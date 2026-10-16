"""Authenticated embedded settings checks. All outbound transports are disabled."""
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
PHP = os.environ.get("PHP_BIN", "php")
checks = 0
def expect(value, label):
    global checks
    checks += 1
    assert value, label

with tempfile.TemporaryDirectory(prefix="neurosaas-toolkit-http-") as folder:
    work = Path(folder)
    shutil.copy(ROOT / "pull.php", work)
    shutil.copy(ROOT / "example.php", work)
    shutil.copytree(ROOT / "lib", work / "lib")
    shutil.copytree(ROOT / "modules", work / "modules", ignore=shutil.ignore_patterns("data"))
    config = """<?php return [
        'repo'=>'owner/repo','branch'=>'main','subdir'=>'.','source'=>'branch',
        'pr_number'=>0,'purge'=>false,'timezone'=>'UTC','gh_token'=>'',
        'password_hash'=>password_hash('sample-pass', PASSWORD_DEFAULT),
        'keep_files'=>[],'auto_pull'=>false,'auto_interval'=>60];"""
    hashed = subprocess.check_output([PHP, "-r", "echo password_hash('sample-pass', PASSWORD_DEFAULT);"], text=True)
    config = config.replace("password_hash('sample-pass', PASSWORD_DEFAULT)", "'" + hashed + "'")
    (work / "pull-config.php").write_text(config, encoding="utf-8")
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        port = sock.getsockname()[1]
    logs = open(work / "server.log", "w+", encoding="utf-8")
    server = subprocess.Popen([
        PHP, "-d", "disable_functions=curl_exec,curl_multi_exec,mail,fsockopen,stream_socket_client",
        "-d", "session.save_path=" + folder, "-S", f"127.0.0.1:{port}", "-t", folder
    ], stdout=logs, stderr=logs, env=dict(os.environ, GEOIP_COUNTRY_CODE="RU", SELFHEAL_REPORTING="ask"))
    url = f"http://127.0.0.1:{port}/pull.php"
    client = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    def request(query="", data=None, selected=None):
        data = urllib.parse.urlencode(data).encode() if data is not None else None
        try:
            result = (selected or client).open(url + query, data, timeout=10)
        except urllib.error.HTTPError as error:
            result = error
        return result.status, result.read().decode()
    def csrf(html):
        return re.search(r'name="csrf" value="([a-f0-9]+)"', html).group(1)
    try:
        for _ in range(80):
            try:
                status, html = request("?toolkit=1")
                break
            except OSError:
                time.sleep(.05)
        else:
            raise RuntimeError("HTTP server failed")
        expect(status == 401, "advanced settings require host authentication")
        token = csrf(html)
        status, html = request("?toolkit=1", {"csrf": token, "password": "sample-pass"})
        expect(status == 200 and "SMTP" in html and "Сохранено:" not in html, "deep-link login redirects to GET without saving module settings")
        token = csrf(html)
        status, html = request("?toolkit=1")
        expect(status == 200 and "SMTP" in html and "каталог" in html.lower(), f"canonical settings render: {status} {html[:160]}")
        expect("setup.php#" not in html and "?toolkit=1#smtp" in html, "all module-local actions stay on authenticated mount")
        forms = re.findall(r"<form\b.*?</form>", html, re.S)
        expect(forms and all('name="csrf"' in form for form in forms), "every module form has host CSRF")
        expect("отправ" in html.lower() and "nonce" in html.lower(), "consent and signed maintenance controls render")
        status, _ = request("?toolkit=1", {"action": "save", "SMTP_FROM_NAME": "Rejected"})
        expect(status == 403, "advanced POST without CSRF rejected")
        status, _ = request("?toolkit=1", {"csrf": "bad", "action": "save", "SMTP_FROM_NAME": "Rejected"})
        expect(status == 403, "advanced POST with invalid CSRF rejected")
        status, html = request("?toolkit=1", {"csrf": token, "action": "save", "SMTP_FROM_NAME": "Fixture sender", "SMTP_PASS": "FAKE_SMTP_SECRET"})
        expect(status == 200 and "FAKE_SMTP_SECRET" not in html, "settings save masks secrets")
        status, html = request("?toolkit=1")
        expect(status == 200 and "Fixture sender" in html and "FAKE_SMTP_SECRET" not in html, "settings persist safely")
        fresh = urllib.request.build_opener(urllib.request.ProxyHandler({}))
        status, _ = request("?toolkit=1", selected=fresh)
        expect(status == 401, "fresh session cannot access the mounted module")
        direct = client.open(f"http://127.0.0.1:{port}/modules/site_yacloud_openrouter/setup.php").read().decode()
        expect("ВХОД В НАСТРОЙКИ" in direct, "host auth is never persisted into standalone module session")
        status, html = request("?feedback=1")
        expect(status == 200 and "Заказать доработку" in html, "development request form wording")
        logs.flush(); logs.seek(0)
        log = logs.read()
        expect("Fatal error" not in log, "HTTP integration has no PHP fatal errors")
        print(f"{checks} toolkit HTTP checks passed")
    finally:
        server.terminate()
        server.wait(timeout=5)
        logs.close()

