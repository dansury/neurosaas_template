# auto_pull.php — silent deploy check on every page

> Infrastructural module — no customer TZ behind it; no `[CODE §…]` citations (see `spec.md` §Provenance).
> Canonical source of this code: repository `site_yacloud_openrouter`.

## 1. What it does

Active-development switch. While `AUTOPULL_ENABLED` is on, every page view asks GitHub
for the head of the ref `pull.php` tracks. Same commit — nothing happens and nothing is
printed. New commit — `pull.php` is run over HTTP and the browser is redirected (302) to
the URL it originally asked for, now answered by the deployed code.

No credentials of its own: `repo`, `branch` / `pr_number`, `gh_token` and the `pull.php`
password all come from `pull-config.php` in the site root (`GITHUB_TOKEN` in ENV wins over
`gh_token`, as in `pull.php`).

## 2. API

```php
AutoPull::options(array $cfg, array $extra = []): array   // AUTOPULL_* -> opts, $extra wins
AutoPull::run(array $opts): void                          // page hook; once per request, before output
AutoPull::check(array $opts, bool $force = false): array  // the check itself; $force deploys anyway
AutoPull::status(array $opts): array                      // last remembered outcome, for the admin page
AutoPull::root(array $opts): string                       // directory holding pull.php + pull-config.php
AutoPull::pullConfig(string $root): ?array                // parsed pull-config.php, null when absent
```

`$opts`: `enabled` bool · `root` string · `state_dir` string · `interval` int ·
`pull_url` string · `redirect` bool.

`check()` report: `checked_at` int · `head` sha · `deployed` sha · `changed` bool ·
`deployed_now` bool · `ok` bool · `error` string · `note` string · `output` string
(last 600 chars of the `pull.php` answer). It never throws and never prints.

`status()`: `checked_at`, `head`, `deployed`, `deployed_at`, `error`, `note`,
`cooldown_until`.

## 3. Flow of `run()`

1. Skip: already ran this request · `enabled` off · CLI · request method ≠ GET ·
   `cooldown_until > now` · `interval > 0` and the last check is younger than it.
2. `check()`.
3. Deployed something, `redirect` on, request is a document (not XHR / `Sec-Fetch-Dest` ≠
   document / `Accept` without `text/html`), headers not sent → `Cache-Control: no-store`
   + `302 Location: $_SERVER['REQUEST_URI']`, `exit`.

`check()`:

1. `pull-config.php` → repo/ref/token/password hash. Missing file or empty `repo` → error.
2. Head: branch → `GET /repos/{repo}/commits/{branch}` with `Accept: application/vnd.github.sha`
   (bare sha; a JSON answer is parsed as a fallback); PR → `GET /repos/{repo}/pulls/{n}`,
   `head.sha`. Timeout `HEAD_TIMEOUT_SEC` = 8 s, connect 4 s. `curl` when present, else
   `file_get_contents` + stream context. 401/403/404 are reported in words.
3. Deployed commit: `pull-state.json` (`sha`, `pinned`) next to `pull.php` when the
   installed `pull.php` keeps one, else the `deployed` field of the own state file. A
   pinned rollback stands the automation down (`force` overrides).
4. Unchanged and not `force` → done (`note: актуально`).
5. `flock(LOCK_EX|LOCK_NB)` on `<state file>.lock`; a busy lock means another request is
   already deploying — return without writing state.
6. Deploy: `GET <pull.php>?plain=1`, timeout `DEPLOY_TIMEOUT_SEC` = 300 s. Auth, in order:
   `password_hash` → header `Cookie: pull_auth=<expires>|<hmac_sha256('pull-auth|'+expires,
   key = password_hash)>`, the token `pull.php` itself issues, so the plaintext password is
   never needed; else legacy `secret` → `?token=<secret>`; else nothing. Failure = curl
   error, HTTP ≥ 400, or a `STATUS: FAILED` line in the answer.
7. A host that serves one PHP request at a time is busy with *this* one, so `pull.php`
   never answers. `pull.php` prints as it works, so `DEPLOY_SILENCE_SEC` = 20 s without a
   single byte (curl progress callback) aborts the wait instead of freezing the page for
   the whole timeout — `pull.php` sets `ignore_user_abort(true)` and deploys anyway once a
   worker frees up. The commit is then recorded as deployed with the note «деплой идёт в
   фоне», there is no redirect (the new code is not live yet) and a `COOLDOWN_SEC` pause
   keeps the next page views from starting a second deploy over the running one.
8. State written; an error sets `cooldown_until = now + COOLDOWN_SEC` (120 s).

## 4. State file

`<state_dir>/auto-pull.json`, mode `0600`, plus `auto-pull.json.lock`. Keys: `checked_at`,
`head`, `deployed`, `deployed_at`, `error`, `note`, `output`, `cooldown_until`. Deleting it
loses nothing but the "last check" line in the admin page.

`state_dir` must be outside the directory a deploy overwrites (the app's data directory);
default is `sys_get_temp_dir()`.

## 5. `pull.php` URL

`pull_url` when set, else `<scheme>://<HTTP_HOST><path of root under DOCUMENT_ROOT>/pull.php`
(HTTPS from `HTTPS`, `SERVER_PORT=443` or `X-Forwarded-Proto`). A host that cannot resolve
its own name from PHP needs `AUTOPULL_URL` filled in by hand.

## 6. Settings (`setup.php`, `settings` table, overlaid by `config.php`)

| Key | Default | Meaning |
|---|---|---|
| `AUTOPULL_ENABLED` | `0` | the checkbox; `1` = check on every page view |
| `AUTOPULL_INTERVAL` | `0` | minimum seconds between checks; `0` = every page view |
| `AUTOPULL_URL` | `` | explicit `pull.php` URL; empty = derived |

`setup.php` also has a **Проверить и обновить** button — `check($opts, true)`, independent
of the checkbox.

## 7. Limits

- One GitHub API call per page view at `AUTOPULL_INTERVAL = 0`: 5000 req/h with a token,
  60 req/h without one. Raise the interval if the limit is near.
- The deploy is a second HTTP request to the same host. Where the host serves one PHP
  request at a time, the first page view after a push waits `DEPLOY_SILENCE_SEC` and then
  renders the old code; the deploy finishes in the background and the next view is new.
- Not a cron replacement. Nobody opens a page, nothing is deployed — for an unattended
  server use `pull.php?check=1` from cron.
