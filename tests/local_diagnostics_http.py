"""Local archive/account/diagnostic acceptance; only external transports are replaced."""
import contextlib
import html as html_lib
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


fixture = os.environ.get("PREVIEW_FIXTURE")
scope = contextlib.nullcontext(fixture) if fixture else tempfile.TemporaryDirectory(prefix="neurosaas-local-")
with scope as folder:
    work = Path(folder)
    work.mkdir(parents=True, exist_ok=True)
    shutil.copytree(ROOT / "admin", work / "admin", ignore=shutil.ignore_patterns("data"), dirs_exist_ok=True)
    shutil.copy(ROOT / "index.php", work)
    panel = work / "admin/deployment/panel.php"
    source = panel.read_text()
    for name, replacement in {
        "gh_api": """function gh_api(string $path, string $token): array {
    file_put_contents(PULL_ROOT . '/network.json', json_encode(['path' => $path]));
    return [['sha' => str_repeat('a', 40), 'commit' => ['message' => 'Fixture']], ''];
}""",
        "github_create_feedback": """function github_create_feedback(string $repo, string $token, string $title, string $body): array {
    file_put_contents(PULL_ROOT . '/feedback.json', json_encode(compact('repo', 'title', 'body')));
    return [201, ['html_url' => 'https://github.com/' . $repo . '/issues/42']];
}""",
    }.items():
        source, count = re.subn(r"function " + name + r"\([^\n]+\n.*?(?=\nfunction |\Z)",
                                lambda _: replacement + "\n", source, count=1, flags=re.S)
        assert count == 1, name
    panel.write_text(source)
    (work / "pull.php").write_text(source.replace("define('PULL_ROOT', dirname(__DIR__, 2))", "define('PULL_ROOT', __DIR__)"))
    gate = work / "admin/account/gate.php"
    source = gate.read_text()
    start = source.index("    private static function deliver(")
    end = source.index("    public static function verified(", start)
    gate.write_text(source[:start] + """    private static function deliver(string $email, string $code): void {
        file_put_contents(dirname(__DIR__, 2) . '/mail.json', json_encode(compact('email', 'code')));
    }
""" + source[end:])
    (work / "warn.php").write_text("""<?php
require __DIR__ . '/admin/llm_connector/bootstrap.php';
@trigger_error('SUPPRESSED_CUSTOMER_SECRET', E_USER_WARNING);
trigger_error('CUSTOMER_SECRET github_pat_unregistered_secret owner@example.test', E_USER_WARNING);
if (isset($_GET['fatal'])) trigger_error('FATAL_CUSTOMER_SECRET', E_USER_ERROR);
echo 'warning fixture';
""")
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        port = sock.getsockname()[1]
    logs = open(work / "server.log", "w+")
    server = subprocess.Popen([PHP, "-d", "display_errors=0", "-d", "session.save_path=" + str(work),
                               "-S", f"127.0.0.1:{port}", "-t", str(work)],
                              stdout=logs, stderr=logs, env=dict(os.environ, SELFHEAL_REPORTING="ask"))
    base = f"http://127.0.0.1:{port}"

    def browser():
        return urllib.request.build_opener(urllib.request.ProxyHandler({}),
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

    client = browser()

    def request(path="/admin/", data=None, selected=None):
        payload = urllib.parse.urlencode(data).encode() if data is not None else None
        try:
            result = (selected or client).open(base + path, payload, timeout=15)
        except urllib.error.HTTPError as error:
            result = error
        return result.status, result.read().decode()

    def csrf(page):
        return re.search(r'name="csrf" value="([a-f0-9]+)"', page).group(1)

    def config():
        return json.loads(subprocess.check_output([PHP, "-r", "echo json_encode(require $argv[1]);",
                                                  str(work / "pull-config.php")], text=True))

    def snapshot(page):
        ident = re.search(r'name="diagnostic_snapshot" value="([a-f0-9]+)"', page).group(1)
        preview = re.search(r'<textarea readonly rows="10"[^>]*>(.*?)</textarea>', page, re.S).group(1)
        return ident, html_lib.unescape(preview)

    try:
        for _ in range(80):
            try:
                status, page = request("/pull.php")
                break
            except OSError:
                time.sleep(.05)
        else:
            raise RuntimeError("PHP server did not start")
        expect(status == 200 and "Файлы уже загружены" in page, "archive offers local setup through pull redirect")
        standalone = work / "standalone"
        standalone.mkdir()
        shutil.copy(work / "pull.php", standalone / "pull.php")
        standalone_client = browser()
        status, lone = request("/standalone/pull.php?install=local", selected=standalone_client)
        expect(status == 200 and "f_repo" in lone and 'href="?install=local"' not in lone, "single uploaded installer cannot claim full files are present")
        status, _ = request("/standalone/pull.php", dict(csrf=csrf(lone), installation_mode="local", password="local-install-password"), selected=standalone_client)
        expect(status == 422 and not (standalone / "pull-config.php").exists(), "forged standalone local setup is rejected")
        status, page = request("/admin/?install=local")
        expect("f_repo" not in page and "f_gh_token" not in page, "local setup needs no repository or key")
        token = csrf(page)
        status, _ = request(data=dict(installation_mode="local", password="local-install-password"))
        expect(status == 403 and not (work / "pull-config.php").exists(), "local setup requires CSRF")
        status, page = request(data=dict(csrf=token, installation_mode="local", password="local-install-password",
                                        timezone="Europe/Moscow", auto_pull="1"))
        expect(status == 200 and config()["installation_mode"] == "local" and not config()["auto_pull"], "local configuration saved without automatic updates")
        status, page = request()
        expect(status == 401, "installation password protects enrollment")
        status, page = request(data=dict(csrf=csrf(page), password="local-install-password"))
        expect(status == 200 and "Создайте учётную запись" in page, "local mode uses real account enrollment")
        token = csrf(page)
        status, page = request(data=dict(csrf=token, account_action="create", email="owner@example.test", account_password="confirmed-local-password", account_password_confirm="confirmed-local-password"))
        expect(status == 401 and config()["admin_account_created"] and not config()["admin_email_verified"] and not (work / "mail.json").exists(), "local enrollment needs no email and requires login")
        status, page = request(data=dict(csrf=csrf(page), password="confirmed-local-password"))
        expect(status == 200 and "Локальная установка" in page and "f_genrouter_key" in page, "same administration and AI panel are available")
        token = csrf(page)
        for path in ["/admin/?status=1", "/admin/?history=1", "/admin/?watch=1"]:
            status, body = request(path)
            expect(status == 200, "local route " + path)
        for path, data in [
            ("/admin/", dict(csrf=token, action="deploy")),
            ("/admin/", dict(csrf=token, action="deploy", rollback_sha="a" * 40)),
            ("/admin/", dict(csrf=token, action="auto", auto_pull="1", auto_interval="15")),
            ("/pull.php?check=1&plain=1", None),
        ]:
            status, _ = request(path, data)
            expect(status == 409, "local operation blocked " + path + str(data))
        expect(not (work / "network.json").exists(), "no local operation contacted GitHub")
        status, page = request("/admin/?toolkit=1")
        expect(status == 200 and "SMTP" in page, "same advanced mail/model settings")
        request("/warn.php")
        request("/warn.php?fatal=1")
        status, page = request("/admin/?diagnostics=1", selected=browser())
        expect(status == 401, "diagnostic view requires authentication")
        status, page = request("/admin/?feedback=1")
        ident, preview = snapshot(page)
        expect("PHP error" in preview and "warn.php" in preview and '"errno":256' in preview and preview.count("PHP error") == 3, "unsuppressed warnings and fatal captured exactly once with useful locations")
        expect(all(secret not in preview for secret in ["CUSTOMER_SECRET", "FATAL_CUSTOMER_SECRET", "SUPPRESSED_CUSTOMER_SECRET", "owner@example.test", "github_pat_"]), "export excludes customer payloads and credentials")
        expect('name="attach_diagnostics" value="1" checked' not in page, "diagnostics are opt-in")
        token = csrf(page)
        status, _ = request("/admin/?feedback=1", dict(csrf=token, action="feedback", description="Local idea without GitHub"))
        expect(status == 422 and not (work / "feedback.json").exists(), "local feedback explains missing connection")
        before = config()
        status, _ = request(data=dict(action="connect_repository", repo="owner/repo"))
        expect(status == 403 and config() == before, "later connection requires CSRF")
        status, page = request(data=dict(csrf=token, action="connect_repository", repo="owner/repo", branch="main", gh_token="fixture-secret-key"))
        after = config()
        expect(after["installation_mode"] == "github" and after["auto_pull"] and after["password_hash"] == before["password_hash"] and after["admin_email"] == before["admin_email"], "later connection preserves account and enables requested default updates")
        _, page = request("/admin/?feedback=1")
        token = csrf(page)
        ident, preview = snapshot(page)
        status, _ = request("/admin/?feedback=1", dict(csrf=token, action="feedback", description="Idea without diagnostic consent"))
        expect(status == 200 and "Диагностика" not in json.loads((work / "feedback.json").read_text())["body"], "unchecked log is not sent")
        # Isolated fixture: reset only session cooldown, without bypassing product auth/CSRF.
        for session in work.glob("sess_*"):
            session.write_bytes(re.sub(rb"feedback_at\|i:\d+;", b"feedback_at|i:0;", session.read_bytes()))
        _, page = request("/admin/?feedback=1")
        token = csrf(page)
        ident, preview = snapshot(page)
        request("/warn.php")
        status, _ = request("/admin/?feedback=1", dict(csrf=token, action="feedback", description="Idea with diagnostic consent",
            attach_diagnostics="1", diagnostic_snapshot="tampered"))
        expect(status == 422, "unreviewed diagnostic ID rejected")
        status, _ = request("/admin/?feedback=1", dict(csrf=token, action="feedback", description="Idea with diagnostic consent",
            attach_diagnostics="1", diagnostic_snapshot=ident))
        sent = json.loads((work / "feedback.json").read_text())["body"]
        expect(status == 200 and preview in sent and sent.count("PHP error") == preview.count("PHP error"), "sent attachment exactly matches frozen preview despite newer errors")
        expect("fixture-secret-key" not in sent, "GitHub key excluded from diagnostics")
        if fixture:
            subprocess.check_call([PHP, "-r", "$p=$argv[1];$c=require $p;$c['installation_mode']='local';$c['repo']='';$c['gh_token']='';file_put_contents($p,'<?php return '.var_export($c,true).';');", str(work / "pull-config.php")])
        fresh = work / "fresh"
        shutil.copytree(ROOT, fresh, ignore=shutil.ignore_patterns(".git", "data", "work", "__pycache__"))
        fresh_client = browser()
        status, page = request("/fresh/admin/?install=local", selected=fresh_client)
        expect(status == 200 and 'name="password"' not in page, "fresh local setup asks no installation password")
        status, page = request("/fresh/admin/", dict(csrf=csrf(page), installation_mode="local", timezone="Europe/Moscow"), selected=fresh_client)
        expect(status == 200, "passwordless local setup saved")
        status, page = request("/fresh/admin/", selected=fresh_client)
        expect(status == 200 and "Создайте учётную запись" in page, "originating browser reaches account enrollment directly")
        status, page = request("/fresh/admin/", selected=browser())
        expect(status == 403, "another browser cannot claim pending installation")
        _, page = request("/fresh/admin/", selected=fresh_client)
        status, page = request("/fresh/admin/", dict(csrf=csrf(page), account_action="create", email="fresh@example.test", account_password="fresh-owner-password", account_password_confirm="fresh-owner-password"), selected=fresh_client)
        expect(status == 401 and "Вход в управление" in page, "fresh bootstrap creates account without email")
        status, page = request("/fresh/admin/", dict(csrf=csrf(page), password="fresh-owner-password"), selected=fresh_client)
        expect(status == 200 and "Управление сервисом" in page, "fresh owner reaches settings immediately")
        status, page = request("/fresh/admin/", selected=browser())
        expect(status == 401 and "Создайте учётную запись" not in page, "other browsers must authenticate after first creation")
        print(f"{checks} local installation and diagnostics checks passed")
    finally:
        server.terminate()
        server.wait(timeout=10)
        logs.close()

