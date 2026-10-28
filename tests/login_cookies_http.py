"""Offline duplicate-cookie login/logout regression for existing browsers."""
import hashlib, hmac, http.cookiejar, os, re, shutil, socket, subprocess, tempfile, time, urllib.request, urllib.error, urllib.parse
from pathlib import Path

ROOT=Path(__file__).resolve().parent.parent
PHP=os.environ.get('PHP_BIN','php')
with tempfile.TemporaryDirectory(prefix='login-cookies-') as tmp:
    site=Path(tmp)/'site'
    shutil.copytree(ROOT,site,ignore=shutil.ignore_patterns('.git','.serena','work','data','__pycache__','node_modules'))
    password='cookie-regression-password'
    password_hash=subprocess.check_output([PHP,'-r','echo password_hash("'+password+'", PASSWORD_DEFAULT);'],text=True)
    (site/'pull-config.php').write_text("<?php return ['installation_mode'=>'local','repo'=>'fixture/repo','branch'=>'main','gh_token'=>'','password_hash'=>'"+password_hash+"','admin_email'=>'owner@example.test','admin_email_verified'=>true,'auto_pull'=>false];")
    sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close()
    server=subprocess.Popen([PHP,'-S',f'127.0.0.1:{port}','-t',str(site)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,env=dict(os.environ,SELFHEAL_REPORTING='off',GENLA_DATA_DIR=str(Path(tmp)/'private')))
    jar=http.cookiejar.CookieJar();client=urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(jar));base=f'http://127.0.0.1:{port}'
    def request(path='/admin/?system=1',data=None):
        req=urllib.request.Request(base+path,urllib.parse.urlencode(data).encode() if data else None)
        try:
            with client.open(req,timeout=10) as response:return response.status,response.read().decode()
        except urllib.error.HTTPError as response:return response.code,response.read().decode()
    def cookie(name,value,path):
        jar.set_cookie(http.cookiejar.Cookie(0,name,value,None,False,'127.0.0.1',False,False,path,True,False,None,True,None,None,{'HttpOnly':None},False))
    try:
        for _ in range(100):
            try:status,html=request();break
            except urllib.error.URLError:time.sleep(.05)
        assert status==401
        cookie('pull_panel','stale-admin-session','/admin/');cookie('pull_panel','stale-root-session','/')
        cookie('pull_auth','stale-invalid-token','/admin/')
        status,html=request();csrf=re.search(r'name="csrf" value="([a-f0-9]+)"',html).group(1)
        status,html=request(data={'csrf':csrf,'password':password});assert status==200,(status,html[:200])
        assert request()[0]==200,'Correct password must survive conflicting legacy cookies on next request'
        assert any(c.name.startswith('pull_auth_') and c.path=='/' for c in jar),'Install-scoped auth cookie'
        assert any(c.name.startswith('pull_panel_') and c.path=='/' for c in jar),'Install-scoped session cookie'
        assert 'name="password"' in request('/admin/?logout=1')[1]
        assert request()[0]==401,'Logout must invalidate old and scoped cookies'
        expires=int(time.time())+3600;token=str(expires)+'|'+hmac.new(password_hash.encode(),('pull-auth|'+str(expires)).encode(),hashlib.sha256).hexdigest()
        cookie('pull_auth',token,'/');cookie('pull_auth','invalid-admin-token','/admin/')
        assert request()[0]==200,'A valid legacy token must migrate even after an invalid duplicate'
        assert 'name="password"' in request('/admin/?logout=1')[1]
        assert request()[0]==401,'Logout must also clear migrated legacy path variants'
        print('OK: stale and duplicate cookies, legacy migration and logout')
    finally:server.terminate();server.wait(timeout=10)
