"""Enrollment and recovery against real PHP routes, with mail stubbed locally."""
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

def expect(ok, label):
    global checks
    checks += 1
    assert ok, (label, globals().get('status'), globals().get('html', globals().get('page', ''))[:400])

with tempfile.TemporaryDirectory(prefix="neurosaas-account-http-") as folder:
    work = Path(folder)
    shutil.copytree(ROOT / "admin", work / "admin", ignore=shutil.ignore_patterns("data"))
    for name in ["pull.php", "index.php"]:
        shutil.copy(ROOT / name, work / name)
    gate = work / "admin/account/gate.php"
    source = gate.read_text()
    start = source.index("    private static function deliver(")
    end = source.index("    public static function verified(", start)
    source = source[:start] + """    private static function deliver(string $email, string $code): void {
        if (is_file(dirname(__DIR__, 2) . '/fail-mail')) throw new \\RuntimeException('SMTP delivery failed');
        file_put_contents(dirname(__DIR__, 2) . '/mail.json', json_encode(compact('email', 'code')));
    }

""" + source[end:]
    gate.write_text(source)
    password = "installation-pass"
    hashed = subprocess.check_output([PHP, "-r", "echo password_hash('installation-pass', PASSWORD_DEFAULT);"], text=True)
    config = """<?php return [
        'repo'=>'owner/repo','branch'=>'main','subdir'=>'.','source'=>'branch',
        'pr_number'=>0,'purge'=>false,'timezone'=>'UTC','gh_token'=>'',
        'password_hash'=>'HASH',
        'keep_files'=>[],'auto_pull'=>false,'auto_interval'=>60];""".replace("HASH", hashed)
    (work / "pull-config.php").write_text(config)
    (work / "pull.php").write_text("<?php echo 'legacy installer';")
    # Local state from the previous layout must move before toolkit boot.
    legacy = work / "modules/site_yacloud_openrouter"
    (legacy / "data").mkdir(parents=True)
    (legacy / "data/operator.txt").write_text("LOCAL")
    (legacy / ".env").write_text("MIGRATION_FIXTURE=1")
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        port = sock.getsockname()[1]
    logs = open(work / "server.log", "w+")
    server = subprocess.Popen([
        PHP, "-d", "disable_functions=curl_exec,curl_multi_exec,mail,fsockopen,stream_socket_client",
        "-d", "session.save_path=" + folder, "-S", f"127.0.0.1:{port}", "-t", folder,
    ], stdout=logs, stderr=logs, env=dict(os.environ, SELFHEAL_REPORTING="ask"))
    def browser():
        return urllib.request.build_opener(
            urllib.request.ProxyHandler({}),
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    client = browser()
    base = f"http://127.0.0.1:{port}"
    def request(path="/admin/", data=None, selected=None):
        payload = urllib.parse.urlencode(data).encode() if data is not None else None
        try:
            r = (selected or client).open(base + path, payload, timeout=10)
        except urllib.error.HTTPError as e:
            r = e
        return r.status, r.read().decode()
    def csrf(html):
        return re.search(r'name="csrf" value="([a-f0-9]+)"', html).group(1)
    def send_fields(token, email="owner@example.test", pwd="confirmed-password", action="create"):
        return dict(csrf=token, account_action=action, email=email,
                    account_password=pwd, account_password_confirm=pwd)
    try:
        for _ in range(80):
            try:
                status, html = request()
                break
            except OSError:
                time.sleep(.05)
        else:
            raise RuntimeError("PHP server did not start")
        expect(status == 401 and "Забыли пароль?" in html, "existing owner authenticates before enrollment")
        expect("define('PULL_ROOT'" in (work / "pull.php").read_text() and (work / "pull-config.php").read_text() == config, "legacy installer upgrades without changing configuration")
        token = csrf(html)
        status, _ = request(data=send_fields(token))
        expect(status == 401 and not (work / "mail.json").exists(), "unauthenticated enrollment cannot send or take over")
        status, html = request(data=dict(csrf=token, password=password))
        expect(status == 200 and "Создайте учётную запись" in html, "authenticated owner sees enrollment")
        token = csrf(html)
        standalone = urllib.request.build_opener(
            urllib.request.ProxyHandler({}),
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        status, html = request("/pull.php?check=1&plain=1&toolkit=1", selected=standalone)
        expect(status == 401 and "SMTP host" not in html,
               "standalone mixed route remains authenticated before account creation")
        status, _ = request(data=dict(account_action="create", email="owner@example.test"))
        expect(status == 403 and not (work / "mail.json").exists(), "account CSRF required")
        status, html = request(data=send_fields(token, pwd="short"))
        expect(status == 200 and "от 12 до 512" in html and "не совпадают" not in html
               and not (work / "mail.json").exists(), "weak password has an accurate error and is never sent")
        expect("Создать администратора" in html and 'name="code"' not in html, "initial form requires no email code")
        status, html = request(data=send_fields(token, email="invalid"))
        expect("Введите корректный email" in html, "invalid email rejected")
        fields = send_fields(token)
        fields["account_password_confirm"] = "different-password"
        status, html = request(data=fields)
        expect("Пароли не совпадают" in html and "от 12 до 512" not in html,
               "mismatched passwords have a distinct error")
        (work / "fail-mail").touch()
        status, html = request(data=send_fields(token))
        expect(status == 401 and "Вход в управление" in html and not (work / "mail.json").exists(), "initial account works with unavailable SMTP and requires new login")
        raw = (work / "pull-config.php").read_text()
        expect("'admin_account_created' => true" in raw and "'admin_email_verified' => false" in raw and "owner@example.test" in raw and "confirmed-password" not in raw, "enrollment stores hash without claiming email verification")
        state = work / "admin/account/data/state.php"
        expect("challenge" not in state.read_text(), "enrollment leaves no email challenge")
        status, html = request(data=dict(csrf=csrf(html), password=password))
        expect(status == 401, "previous installation password invalidated")
        status, html = request(data=dict(csrf=csrf(html), password="confirmed-password"))
        expect(status == 200 and "Управление сервисом" in html, "new password grants dashboard")
        saved_config = (work / "pull-config.php").read_text()
        status, html = request(data=send_fields(csrf(html), email="attacker@example.test"))
        expect((work / "pull-config.php").read_text() == saved_config, "created account cannot be overwritten")
        failed_recovery = browser()
        _, page = request("/admin/?account=reset", selected=failed_recovery)
        status, page = request("/admin/?account=reset", send_fields(csrf(page), pwd="replacement-password", action="send"), failed_recovery)
        expect(status == 200 and "Не удалось отправить письмо" in page and (work / "pull-config.php").read_text() == saved_config, "SMTP failure during recovery preserves existing account")
        (work / "fail-mail").unlink()
        status, html = request("/pull.php", selected=standalone)
        expect(status == 401 and "Вход в управление" in html,
               "standalone management has its own authenticated entry")
        status, html = request("/pull.php", dict(csrf=csrf(html), password="confirmed-password"), standalone)
        expect(status == 200 and "Управление сервисом" in html,
               "installed standalone management remains directly accessible")
        status, html = request("/pull.php?feedback=1", selected=standalone)
        expect(status == 200 and "Идеи для доработки" in html,
               "standalone feedback remains directly accessible")
        status, html = request("/index.php")
        expect(status == 200 and 'href="admin/"' in html and "Hello, world!" in html, "public welcome links to admin")
        expect("color-scheme: light" in html and "background: #fff" in html and "NeuroSaaS" not in html, "public welcome uses a neutral white light-theme surface")
        expect((work / "admin/llm_connector/data/operator.txt").read_text() == "LOCAL", "legacy module data migrated")
        expect((work / "admin/llm_connector/.env").read_text() == "MIGRATION_FIXTURE=1", "legacy environment migrated")
        # Let the recovery test send again without waiting; this edits only the disposable fixture.
        subprocess.check_call([PHP, "-r",
            "$p=$argv[1];$s=require $p;$s['last_sent']=0;file_put_contents($p,'<?php return '.var_export($s,true).';');", str(state)])
        recovery = browser()
        _, html = request("/admin/?account=reset", selected=recovery)
        rt = csrf(html)
        (work / "mail.json").unlink(missing_ok=True)
        status, html = request("/admin/?account=reset", send_fields(rt, "unknown@example.test", "replacement-password", action="send"), recovery)
        expect(status == 200 and "Если адрес зарегистрирован" in html and not (work / "mail.json").exists(), "unknown reset email gets generic response without mail")
        subprocess.check_call([PHP, "-r",
            "$p=$argv[1];$s=require $p;$s['last_sent']=0;file_put_contents($p,'<?php return '.var_export($s,true).';');", str(state)])
        status, html = request("/admin/?account=reset", send_fields(rt, pwd="replacement-password", action="send"), recovery)
        reset = json.loads((work / "mail.json").read_text())
        expect(status == 200 and reset["email"] == "owner@example.test", "recovery sends to enrolled email")
        expect(reset["code"] not in html and reset["code"] not in state.read_text(), "recovery code absent from HTML and stored only hashed")
        _, html = request("/admin/?account=reset", send_fields(rt, pwd="replacement-password", action="send"), recovery)
        expect("Подождите минуту" in html, "persistent resend cooldown")
        stranger = browser()
        _, stranger_html = request("/admin/?account=reset", selected=stranger)
        _, stranger_html = request("/admin/?account=reset", dict(csrf=csrf(stranger_html), account_action="verify", code=reset["code"]), stranger)
        expect("Код неверный" in stranger_html, "another browser cannot use recovery code")
        _, html = request("/admin/?account=reset", dict(csrf=rt, account_action="verify", code="invalid"), recovery)
        expect("Код неверный" in html, "wrong recovery code rejected")
        status, html = request("/admin/?account=reset",
            dict(csrf=rt, account_action="verify", code=reset["code"]), recovery)
        expect(status == 401, "reset completed without auto login")
        status, html = request()
        expect(status == 401, "password reset invalidates previously authenticated browser")
        token = csrf(html)
        status, html = request(data=dict(csrf=token, password="replacement-password"))
        expect(status == 200 and "Управление сервисом" in html, "replacement password works")
        # A data file has a PHP envelope and exposes no contents via the PHP server.
        status, body = request("/admin/account/data/state.php")
        expect(status == 200 and body == "", "challenge never disclosed over HTTP")
        logs.flush(); logs.seek(0)
        expect("Fatal error" not in logs.read(), "account routes have no PHP fatal errors")
        print(f"{checks} account HTTP checks passed")
    finally:
        server.terminate()
        server.wait(timeout=5)
        logs.close()

