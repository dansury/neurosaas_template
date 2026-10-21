"""Authenticated PWA endpoints in a subdirectory; outbound delivery disabled."""
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

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP_BIN', 'php')
checks = 0
def expect(value, label):
    global checks
    checks += 1
    assert value, label

with tempfile.TemporaryDirectory(prefix='neurosaas-webapp-') as folder:
    root = Path(folder)
    work = root / 'nested/service'
    work.mkdir(parents=True)
    shutil.copytree(ROOT / 'admin', work / 'admin', ignore=shutil.ignore_patterns('data'))
    for name in ['index.php', 'pull.php', 'webapp.php', 'webapp-sw.js']:
        shutil.copy(ROOT / name, work / name)
    config = """<?php return ['install_mode'=>'local',
      'repo'=>'','branch'=>'main','subdir'=>'.','source'=>'branch','pr_number'=>0,
      'purge'=>false,'timezone'=>'UTC','gh_token'=>'','password_hash'=>password_hash('sample-pass',PASSWORD_DEFAULT),
      'admin_email'=>'owner@example.test','admin_email_verified'=>true,'keep_files'=>[],
      'auto_pull'=>false,'auto_interval'=>60];"""
    hashed = subprocess.check_output([PHP, '-r', "echo password_hash('sample-pass', PASSWORD_DEFAULT);"], text=True)
    (work / 'pull-config.php').write_text(config.replace("password_hash('sample-pass',PASSWORD_DEFAULT)", "'"+hashed+"'"), encoding='utf-8')
    with socket.socket() as sock:
        sock.bind(('127.0.0.1',0)); port=sock.getsockname()[1]
    logs = open(root / 'server.log','w+',encoding='utf-8')
    server = subprocess.Popen([PHP,'-d','disable_functions=curl_exec,curl_multi_exec,mail,fsockopen,stream_socket_client','-d','session.save_path='+folder,'-S',f'127.0.0.1:{port}','-t',folder],stdout=logs,stderr=logs)
    client=urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    base=f'http://127.0.0.1:{port}/nested/service/'
    def request(path, fields=None):
        data=urllib.parse.urlencode(fields).encode() if fields is not None else None
        try: response=client.open(base+path,data,timeout=15)
        except urllib.error.HTTPError as error: response=error
        return response.status,response.read().decode('utf-8')
    try:
        for attempt in range(80):
            try: status,body=request('webapp.php'); break
            except OSError: time.sleep(.05)
        manifest=json.loads(body)
        expect(status==200 and manifest['scope']=='/nested/service/','public manifest is subfolder scoped')
        expect('private' not in body and 'endpoint' not in body,'manifest exposes no secrets or subscriptions')
        status,body=request('admin/?webapp=1')
        expect(status==401 and 'data-webapp-controls' not in body,'webapp administration requires authentication')
        login_csrf=re.search(r'name="csrf" value="([a-f0-9]+)"',body).group(1)
        status,body=request('admin/?webapp=1',{'password':'sample-pass','csrf':login_csrf})
        expect(status==200 and 'data-webapp-controls' in body,f'owner login reaches module without saving settings: {status} {body[:300]}')
        token=re.search(r'data-csrf="([a-f0-9]+)"',body).group(1)
        expect('data-webapp-base="/nested/service/"' in body,'admin client uses service root')
        status,body=request('admin/?webapp=1',{'webapp_action':'subscribe','csrf':'wrong'})
        expect(status==403,'subscription mutation requires CSRF')
        status,body=request('admin/?webapp=1',{'webapp_action':'subscribe','csrf':token,'subscription':json.dumps({'endpoint':'http://127.0.0.1/private','keys':{}})})
        expect(status==422,'unsafe endpoints rejected')
        status,body=request('admin/?webapp=1',{'webapp_action':'key','csrf':token})
        result=json.loads(body)
        expect(status==200 and 'publicKey' in result and 'private' not in body,'only public VAPID key returned')
        store=work/'admin/account/data/webapp.sqlite'
        expect(store.exists(),'private module storage created')
        before=store.read_bytes()
        status,body=request('admin/?webapp=1',{'webapp_action':'unknown','csrf':token})
        expect(status==422 and store.read_bytes()==before,'unknown action does not mutate module state')
        status,body=request('webapp-sw.js')
        expect(status==200 and 'neurosaas-webapp-' in body,'root worker available under mount')
        status,body=request('admin/webapp/offline.html')
        expect(status==200 and 'Нет подключения' in body,'public offline fallback available')
        status,body=request('admin/?webapp=1',{'webapp_action':'key','csrf':token})
        expect(json.loads(body)['publicKey']==result['publicKey'],'key survives separate requests')
        print(f'OK {checks} authenticated webapp HTTP checks')
    finally:
        server.terminate();server.wait(timeout=10);logs.close()
