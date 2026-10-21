#!/usr/bin/env python3
"""Local updater. Python 3.9+: https://www.python.org/downloads/ (Windows: add to PATH).
Run: python pull.py; Ctrl+C stops one-minute foreground checks. No background service.
Tokens stay in a private local config; existing .env, data, storage and PHP settings survive.
"""
import argparse
import getpass
import hashlib
import io
import json
import os
from pathlib import Path, PurePosixPath
import shutil
import stat
import subprocess
import sys
import tempfile
import time
import urllib.parse
import urllib.request
import zipfile

KEEP = {'.git', '.env', 'data', 'storage', 'config.php', 'pull.py', 'pull.php',
        'pull-config.php', 'pull-state.json', 'ai-config.php', '.pull-local.json',
        '.pull-local-history', 'pull-archives', 'admin/account/data',
        'admin/llm_connector/data', 'admin/llm_connector/.env',
        'admin/llm_connector/selfheal/token.local.php'}
TOKEN_URL = 'https://github.com/settings/personal-access-tokens/new?' + urllib.parse.urlencode(
    {'name': 'Local updater', 'contents': 'read', 'issues': 'write', 'pull_requests': 'read'})

class SafeRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, url):
        redirected = super().redirect_request(req, fp, code, msg, headers, url)
        if redirected and urllib.parse.urlparse(url).hostname != urllib.parse.urlparse(req.full_url).hostname:
            redirected.remove_header('Authorization')
        return redirected

def request(path, token, raw=False):
    req = urllib.request.Request('https://api.github.com' + path,
        headers={'Authorization': 'Bearer ' + token, 'User-Agent': 'pull.py', 'Accept': 'application/vnd.github+json'})
    with urllib.request.build_opener(SafeRedirect()).open(req, timeout=60) as reply:
        data = reply.read(100 * 1024 * 1024 + 1)
    if len(data) > 100 * 1024 * 1024:
        raise RuntimeError('Archive exceeds 100 MB')
    return data if raw else json.loads(data)

def select(label, rows, name):
    if not rows:
        raise RuntimeError('No accessible ' + label)
    for index, row in enumerate(rows, 1): print(f'{index}. {row[name]}')
    answer = input(label + ' [1]: ').strip() or '1'
    return rows[int(answer) - 1][name]

def protected(path):
    return any(path == keep or path.startswith(keep + '/') for keep in KEEP)

def extract(data, destination):
    with zipfile.ZipFile(io.BytesIO(data)) as archive:
        total = 0
        for item in archive.infolist():
            path = PurePosixPath(item.filename.replace('\\', '/'))
            total += item.file_size
            if path.is_absolute() or '..' in path.parts or ':' in path.parts[0] or stat.S_ISLNK(item.external_attr >> 16):
                raise RuntimeError('Unsafe archive member')
            if total > 500 * 1024 * 1024 or item.file_size > 100 * 1024 * 1024:
                raise RuntimeError('Expanded archive is too large')
        archive.extractall(destination)
    children = list(destination.iterdir())
    return children[0] if len(children) == 1 and children[0].is_dir() else destination

def dependencies(root):
    if (root / 'requirements.txt').is_file():
        venv = root / '.venv'
        if not venv.exists(): subprocess.run([sys.executable, '-m', 'venv', str(venv)], check=True)
        python = venv / ('Scripts/python.exe' if os.name == 'nt' else 'bin/python')
        subprocess.run([str(python), '-m', 'pip', 'install', '-r', str(root / 'requirements.txt')], check=True)
    if (root / 'package.json').is_file():
        npm = shutil.which('npm.cmd' if os.name == 'nt' else 'npm')
        if not npm: raise RuntimeError('Install Node.js: https://nodejs.org/')
        subprocess.run([npm, 'ci' if (root / 'package-lock.json').exists() else 'install'], cwd=root, check=True)
    if (root / 'composer.json').is_file():
        composer = shutil.which('composer')
        if not composer: raise RuntimeError('Install PHP and Composer: https://www.php.net/downloads.php / https://getcomposer.org/download/')
        subprocess.run([composer, 'install', '--no-interaction'], cwd=root, check=True)

def install(data, root, version, setup_dependencies=True):
    with tempfile.TemporaryDirectory(prefix='pull-local-') as folder:
        source = extract(data, Path(folder))
        for file in source.rglob('*'):
            rel = file.relative_to(source).as_posix()
            if protected(rel) or not file.is_file(): continue
            target = root / rel
            if target.is_symlink() or any(p.is_symlink() for p in target.parents if p != root.parent):
                raise RuntimeError('Destination symlink rejected: ' + rel)
            target.parent.mkdir(parents=True, exist_ok=True)
            temp = target.with_name(target.name + '.pull-tmp')
            shutil.copyfile(file, temp); os.replace(temp, target)
    history = root / '.pull-local-history'; history.mkdir(mode=0o700, exist_ok=True)
    (history / (version + '.zip')).write_bytes(data)
    if setup_dependencies: dependencies(root)

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--once', action='store_true')
    parser.add_argument('--no-deps', action='store_true', help='Skip dependency installation')
    parser.add_argument('--rollback', help='Restore a previously downloaded commit and stop')
    args = parser.parse_args()
    root = Path(__file__).resolve().parent; path = root / '.pull-local.json'
    config = json.loads(path.read_text()) if path.exists() else {}
    if args.rollback:
        if len(args.rollback) != 40 or any(c not in '0123456789abcdef' for c in args.rollback): raise RuntimeError('Use the full commit SHA from history')
        install((root / '.pull-local-history' / (args.rollback + '.zip')).read_bytes(), root, args.rollback, not args.no_deps)
        config['sha'] = args.rollback; config['paused'] = True
        path.write_text(json.dumps(config)); path.chmod(0o600)
        print('Restored. Polling paused; merge a restoration commit into main, then remove paused from .pull-local.json.'); return
    if not config:
        print('Create a GitHub user token: ' + TOKEN_URL)
        token = os.getenv('GITHUB_TOKEN') or getpass.getpass('GitHub token (hidden): ')
        repos=[]
        for page in range(1,11):
            batch=request(f'/user/repos?per_page=100&sort=updated&page={page}',token); repos.extend(batch)
            if len(batch)<100: break
        repo=select('Repository',repos,'full_name')
        branches=[]
        for page in range(1,11):
            batch=request('/repos/'+repo+f'/branches?per_page=100&page={page}',token);branches.extend(batch)
            if len(batch)<100:break
        branches.sort(key=lambda row: (row['name'] != 'main', row['name']))
        config={'token':token,'repo':repo,'branch':select('Branch',branches,'name'),'sha':''}
        path.write_text(json.dumps(config)); path.chmod(0o600)
    if config.get('paused'): raise RuntimeError('Rollback is pinned. Restore the desired version into main before resuming updates.')
    try:
        while True:
            try:
                branch=urllib.parse.quote(config['branch'],safe='')
                sha=request('/repos/'+config['repo']+'/commits/'+branch,config['token'])['sha']
                if sha != config.get('sha'):
                    print('Downloading commit ' + sha)
                    data=request('/repos/'+config['repo']+'/zipball/'+sha,config['token'],True)
                    install(data,root,sha,not args.no_deps)
                    config['sha']=sha;path.write_text(json.dumps(config));path.chmod(0o600)
                    print('Updated to ' + sha)
                else: print('Up to date: ' + sha[:12])
            except Exception as error:
                print(type(error).__name__ + ': update failed; previous settings and history retained.', file=sys.stderr)
                if args.once: raise
            if args.once: break
            time.sleep(60)
    except KeyboardInterrupt: print('\nUpdate checks stopped.')

if __name__ == '__main__': main()
