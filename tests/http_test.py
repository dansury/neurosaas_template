"""Isolated HTTP acceptance tests. Only external network functions are stubbed."""
import http.cookiejar
import json
import os
from pathlib import Path
import re
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parent.parent
PHP = os.environ.get('PHP_BIN', 'php')
checks = 0

def expect(value, label):
    global checks
    checks += 1
    assert value, label

with tempfile.TemporaryDirectory(prefix='neurosaas-http-') as work:
    work = Path(work)
    source = (ROOT / 'pull.php').read_text()
    def stub(name, code):
        global source
        pattern = r'function ' + name + r'\([^\n]+\n.*?(?=\nfunction |\Z)'
        source, count = re.subn(pattern, lambda _: code + '\n', source, count=1, flags=re.S)
        assert count == 1, name
    stub('gh_api', '''function gh_api(string $path, string $token): array {
        if (is_file(__DIR__ . '/fail-api')) return [null, 'mock failure'];
        return [['sha' => str_repeat('a', 40), 'commit' => ['message' => 'Offline test']], ''];
    }''')
    stub('github_create_feedback', '''function github_create_feedback(string $repo, string $token, string $title, string $body): array {
        file_put_contents(__DIR__ . '/feedback-request.json', json_encode(compact('repo', 'title', 'body')));
        if (is_file(__DIR__ . '/fail-feedback')) return [403, []];
        return [201, ['html_url' => 'https://github.com/' . $repo . '/issues/42']];
    }''')
    stub('download', '''function download(string $url, string $dest, array $extraHeaders = []): array {
        file_put_contents($dest, 'mock archive'); return [12, ''];
    }''')
    source = source.replace("if (defined('PULL_LIBRARY_ONLY')) return;", '''class ZipArchive {
        public function open($path) { return true; }
        public function extractTo($path) {
            mkdir($path . '/repository'); file_put_contents($path . '/repository/index.html', 'deployed'); return true;
        }
        public function close() { return true; }
    }
    if (defined('PULL_LIBRARY_ONLY')) return;''')
    (work / 'pull.php').write_text(source)
    shutil.copytree(ROOT / 'admin' / 'llm_connector', work / 'admin' / 'llm_connector', ignore=shutil.ignore_patterns('data'))
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    logs = open(work / 'server.log', 'w+')
    env = dict(os.environ)
    env.pop('GEOIP_COUNTRY_CODE', None)
    env.pop('PULL_TRUST_CLOUDFLARE', None)
    server = subprocess.Popen([PHP, '-n', '-d', 'session.save_path=' + str(work), '-S', f'127.0.0.1:{port}', '-t', str(work)], env=env, stdout=logs, stderr=logs)
    cookies = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(cookies))
    url = f'http://127.0.0.1:{port}/pull.php'
    def request(query='', data=None, client=opener):
        body = urllib.parse.urlencode(data).encode() if data is not None else None
        try:
            r = client.open(url + query, body, timeout=5)
        except urllib.error.HTTPError as e:
            r = e
        return r.status, r.read().decode()
    def csrf(html):
        return re.search(r'name="csrf" value="([a-f0-9]+)"', html).group(1)
    try:
        for _ in range(80):
            try:
                status, html = request()
                break
            except OSError:
                time.sleep(.05)
        else:
            logs.flush(); logs.seek(0); raise RuntimeError('PHP server did not start: ' + logs.read())
        expect(status == 200 and '<html lang="ru">' in html, 'Russian is the default without geolocation')
        expect('color-scheme:light' in html and 'background:#fff;color:var(--ink)' in html, 'standalone installer renders a white light-theme surface')
        expect('Issues — Read and write' in html and 'issues=write' in html, 'Issues write explained and prefilled')
        expect('terminal' not in html and '$ save_config' not in html, 'friendly setup')
        status, body = request(data={'repo': 'owner/repo'})
        expect(status == 403 and not (work / 'pull-config.php').exists(), 'setup CSRF protected')
        _, html = request()
        setup = dict(csrf=csrf(html), repo='owner/repo', branch='main', subdir='.', timezone='UTC', gh_token='FAKE_KEY', password='sample-pass', auto_pull='1', auto_interval='60')
        status, html = request(data=setup)
        expect(status == 200 and 'Подключение настроено' in html, 'first-run setup saves')
        expect((work / 'pull-config.php').stat().st_mode & 0o777 == 0o600, 'config private permissions')
        expect('sample-pass' not in (work / 'pull-config.php').read_text(), 'password hash only')
        status, html = request()
        expect(status == 401 and 'Вход в управление' in html, 'password gate')
        status, html = request(data={'csrf': csrf(html), 'password': 'sample-pass'})
        expect(status == 200 and 'Ваш сервис под контролем' in html, 'sign in to dashboard')
        expect(not (work / 'pull-state.json').exists() and not (work / 'pull-deploy.lock').exists(), 'GET and login never deploy')
        expect('FAKE_KEY' not in html and 'sample-pass' not in html, 'secrets never returned')
        expect('<details open><summary>Genrouter' in html and '<details><summary>Openrouter' in html, 'GenRouter starts open and OpenRouter stays collapsed')
        expect('https://genrouter.ru/?ref=d97a170843' in html and 'интерактивная подписка' in html, 'GenRouter onboarding and API boundary shown')
        expect('Идеи для доработки' in html and 'owner/repo/issues' in html, 'feedback is renamed and links the repository')
        expect('может не работать на российских хостингах' in html, 'provider hosting warning remains visible')
        expect('Ваш сервис' in html and 'NeuroSaaS</title>' not in html and 'https://dansury.github.io' in html and 'https://t.me/dansury.vj' in html, 'neutral panel preserves owner consultation links')
        expect('Embed pull.php feedback form directly into the interface of the service' in html and 'Больше не показывать' in html, 'embedding prompt and dismissal')
        token = csrf(html)
        status, state = request('?status=1')
        expect(status == 200 and json.loads(state)['changed'], 'status detects new version')
        status, _ = request(data={'csrf': 'bad', 'action': 'auto', 'auto_interval': '30'})
        expect(status == 403, 'settings CSRF protected')
        status, html = request(data={'csrf': token, 'action': 'auto', 'auto_interval': '30'})
        expect(status == 200 and "'auto_pull' => false" in (work / 'pull-config.php').read_text(), 'automatic preference persisted')
        status, _ = request('?plain=1', {'csrf': token, 'action': 'deploy', 'automatic': '1'})
        expect(status == 409 and not (work / 'pull-state.json').exists(), 'disabled automatic request blocked')
        status, _ = request('?feedback=1', {'csrf': token, 'action': 'feedback', 'title': 'a', 'description': 'short'})
        expect(status == 422 and not (work / 'feedback-request.json').exists(), 'feedback validation')
        status, html = request('?feedback=1', {'csrf': token, 'action': 'feedback', 'title': '', 'description': 'Expected screen does not load.'})
        expect(status == 200 and 'Спасибо!' in html and '/issues/42' in html and 'Первая идея уже в репозитории!' in html and 'resolve https://github.com/owner/repo/issues' in html, 'optional-title feedback success and first-idea guidance')
        report = json.loads((work / 'feedback-request.json').read_text())
        expect(report['repo'] == 'owner/repo' and report['title'].startswith('Идея для доработки') and 'FAKE_KEY' not in str(report), 'feedback uses configured repo, default title and no secret in report')
        status, _ = request('?feedback=1', {'csrf': token, 'action': 'feedback', 'title': 'Retry', 'description': 'Expected screen does not load.'})
        expect(status == 429, 'feedback cooldown')
        status, html = request('?lang=en')
        expect(status == 200 and '<html lang="en">' in html, 'manual language switch')
        status, html = request()
        expect('<html lang="en">' in html, 'language remembered')
        (work / 'fail-api').touch()
        status, html = request('?check=1&plain=1')
        expect(status == 502 and 'FAILED' in html and not (work / 'pull-state.json').exists(), 'failed cron check does not deploy')
        (work / 'fail-api').unlink()
        status, _ = request('?history=1')
        expect(status == 200, 'history page')
        data = {'csrf': token, 'action': 'ai', 'order': 'yandex, genrouter, openrouter', 'yandex_enabled': '1', 'yandex_key': 'FAKE_AI_KEY', 'yandex_folder': 'folder', 'yandex_model': 'yandexgpt', 'yandex_route': 'native', 'genrouter_model': '', 'openrouter_model': ''}
        status, html = request(data=data)
        expect(status == 200 and (work / 'ai-config.php').exists() and 'FAKE_AI_KEY' not in html, 'AI config saved without echoing keys')
        data['yandex_key'] = ''
        status, _ = request(data=data)
        expect(status == 200 and 'FAKE_AI_KEY' in (work / 'ai-config.php').read_text(), 'blank secret preserves key')
        data['yandex_clear_key'] = '1'
        status, _ = request(data=data)
        expect(status == 200 and 'FAKE_AI_KEY' not in (work / 'ai-config.php').read_text(), 'explicit clear removes key')
        data['order'] = 'unknown'
        status, _ = request(data=data)
        expect(status == 422, 'unknown provider order rejected')
        status, _ = request('?rollback=' + 'b' * 40)
        expect(status == 200 and not (work / 'pull-state.json').exists(), 'GET rollback never deploys')
        # Authentication required for feedback, AI and status from a fresh browser.
        fresh = urllib.request.build_opener(urllib.request.ProxyHandler({}))
        status, _ = request('?feedback=1', client=fresh)
        expect(status == 401, 'feedback auth enforced')
        status, _ = request('?status=1', client=fresh)
        expect(status == 401, 'status auth enforced')
        # Check GitHub failures from another signed-in browser/session.
        second = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        _, html = request(client=second)
        _, html = request(data={'csrf': csrf(html), 'password': 'sample-pass'}, client=second)
        (work / 'fail-feedback').touch()
        status, html = request('?feedback=1', {'csrf': csrf(html), 'action': 'feedback', 'title': 'Broken feature', 'description': 'The expected screen does not load.'}, client=second)
        expect(status == 502 and 'Issues: Read and write' in html and 'personal-access-tokens/new' in html and 'FAKE_KEY' not in html, 'GitHub permission failure actionable')
        # Exercise deployment/history/pinning end to end with only the archive boundary mocked.
        status, result = request('?plain=1', {'csrf': token, 'action': 'deploy'})
        expect(status == 200 and 'STATUS:   DONE' in result and (work / 'index.html').read_text() == 'deployed', 'manual deploy installs files')
        deployed = json.loads((work / 'pull-state.json').read_text())
        expect(deployed['sha'] == 'a' * 40 and not deployed['pinned'], 'deploy state recorded')
        status, result = request('?check=1&plain=1')
        expect(status == 200 and 'UP-TO-DATE' in result, 'unchanged cron skips deployment')
        old = {key: value for key, value in deployed.items() if key != 'history'}
        old['sha'] = 'b' * 40
        deployed['history'].append(old)
        (work / 'pull-state.json').write_text(json.dumps(deployed))
        status, result = request('?plain=1', {'csrf': token, 'action': 'deploy', 'rollback': 'b' * 40})
        pinned = json.loads((work / 'pull-state.json').read_text())
        expect(status == 200 and pinned['pinned'] and pinned['sha'] == 'b' * 40, 'rollback pins recorded historical version')
        status, result = request('?check=1&plain=1')
        expect(status == 200 and 'PINNED' in result and json.loads((work / 'pull-state.json').read_text())['sha'] == 'b' * 40, 'cron respects rollback pin')
        status, result = request('?plain=1', {'csrf': token, 'action': 'deploy'})
        expect(status == 200 and not json.loads((work / 'pull-state.json').read_text())['pinned'], 'explicit latest deployment resumes updates')
        status, result = request('?plain=1', {'csrf': token, 'action': 'deploy', 'rollback': 'c' * 40})
        expect(status == 502 and 'FAILED' in result and json.loads((work / 'pull-state.json').read_text())['sha'] == 'a' * 40, 'unknown rollback rejected without changing files')
        status, html = request(data={'csrf': token, 'action': 'password', 'new_password': 'changed-pass', 'new_password_confirm': 'changed-pass'})
        expect(status == 200 and 'sample-pass' not in (work / 'pull-config.php').read_text(), 'panel password can be replaced without storing it in clear text')
        third = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        _, html = request(client=third)
        status, html = request(data={'csrf': csrf(html), 'password': 'changed-pass'}, client=third)
        expect(status == 200 and 'Ваш сервис под контролем' in html, 'the replacement password grants a fresh login')
        logs.flush(); logs.seek(0)
        expect('Fatal error' not in logs.read(), 'no PHP fatal errors')
        print(f'{checks} HTTP checks passed')
    finally:
        server.terminate()
        server.wait(timeout=5)
        logs.close()
