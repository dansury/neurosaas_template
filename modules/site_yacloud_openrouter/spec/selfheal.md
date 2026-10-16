# selfheal — self-maintaining module layer

Spec of `selfheal/`, `selfheal_install.php`, `selfheal_seal.php`,
`selfheal_admin.php`, `module.json`. Infrastructural code, no customer TZ
behind it — requirements come from one integration need: **the developer must
be able to keep this module working without any contact with the people who
installed it, and must not be able to break their projects while doing so.**

Operator-facing instructions for humans and assistants: `AGENTS.md`.

---

## 1. Public façade — `SelfHeal`

`Selfheal\SelfHeal` is the only class host code may touch. Every method is
listed in `Contract::PUBLIC_API`; the smoke test asserts each one exists, so
removing one fails the suite.

| Method | Returns | Notes |
|---|---|---|
| `boot(array $opts = [])` | `bool` | idempotent; second call is a no-op returning `true` |
| `booted()` | `bool` | |
| `version()` / `apiVersion()` / `module()` | `string` | from `module.json` / `Contract` |
| `fits(string)` / `supports(string)` | `bool` | `'1.x'`, `'^1.0'`, `'>=1.2'`, exact |
| `capture(Throwable, array)` | `string` | 16-hex fingerprint, usable as a support ref |
| `note(string, array)` | `string` | non-exception finding, level `warn` |
| `signError(string, ?string)` | `string` | §7.3 — mandatory signature |
| `userMessage(Throwable, string, array)` | `string` | `capture()` + `signError()` |
| `flush(int)` | `array` | `sent`, `failed`, `skipped`, `errors` |
| `reporting()` / `setReporting(bool, string)` | `string` / `void` | §7 |
| `issueKey(array, int)` / `grants(string, string)` | `string` / `bool` | §5 |
| `updateStatus(bool)` / `applyUpdate(string, bool)` / `rollback(string, ?string)` | `array` | §10 |
| `adminNotice(array)` / `handleAdminPost(array)` | `string` / `?array` | §11 |
| `diagnostics()` / `recentReports(int)` | `array` | §1.2 |

### 1.1 Failure policy

No public method throws. Every entry point is wrapped in
`try/catch (Throwable)` and degrades to "the feature is off": a missing state
file, an unwritable directory or a dead network reduce functionality and never
propagate. Methods called before `boot()` boot lazily with `capture => false`,
so reading state never installs handlers as a side effect.

`boot()` options:

| Key | Default | Effect |
|---|---|---|
| `app` | `''` | host name, sent in reports |
| `db` | `data/selfheal.db` | our own state file (§3) |
| `capture` | `true` | install the chained handlers (§9) |
| `exceptions` | `false` | also install an exception handler (§9.2) |
| `scope` | `'module'` | `'all'` reports the host's errors too |
| `trace_scope` | `'module'` | `'all'` sends host frames too |
| `secrets` | `[]` | extra strings masked in everything outgoing |
| `flush_per_request` | `2` | reports pushed per request |
| `queue_keep` | `300` | queue rows retained |
| `work_dir` | `data/selfheal` | staging + backups (§10) |
| `sign_suffix` | `''` | replaces the signature text (§7.3) |

### 1.2 `diagnostics()`

`booted`, `module`, `version`, `api_version`, `repo`, `channel`, `php`,
`state_db` (path-scrubbed), `state_ok`, `install_id`, `reporting`,
`reporting_env`, `decided_at`, `share_host`, `credential`
(`env|local|sealed|none`), `credential_fp`, `relay`, `can_send`, `capture`,
`captured`, `queued`, `sent`, `failing`, `last_flush_at`, `scope`, `features`.
Never contains a credential — only its 8-hex fingerprint.

---

## 2. Contract and compatibility — `Contract`

`API_VERSION` is semver over the **public surface only**:

| Change | Bump | Auto-installs at users? |
|---|---|---|
| internals, fixes, new files | patch | yes |
| new public method / feature / capability | minor | yes |
| a public method changes or disappears | **major** | **no** — `autoUpdatable()` returns false, the admin is asked instead |

`Contract::manifest()` reads `module.json` once, falling back to safe defaults
when it is absent or unreadable. `repo()` and `channel()` validate their value
against a pattern, so a corrupted manifest cannot redirect traffic to an
arbitrary host.

`fits()` is the host-side gate: `'1.x'` prefix match, `'^1.2'` same major and
at least that version, `'>=1.2'`, or an exact match.

---

## 3. State — `State`

Its **own** SQLite file, separate from the host's database and from the
module's `app.db`: a maintenance layer must not be able to lock or migrate
someone else's data. `WAL`, `busy_timeout = 2000`. Every method swallows
failure; `ready()` reports whether the file opened.

| Table | Content |
|---|---|
| `sh_kv` | `install_secret`, `install_id`, `reporting`, `share_host`, `remote_check`, `installed_files`, `pinned_version`, `notice_dismissed`, `applied_sha`, `last_update_note`, … |
| `sh_queue` | one row per recorded report: `fp`, `level`, `ts`, `title`, `payload` (JSON), `tries`, `next_try`, `sent_at`, `issue`, `note` |
| `sh_issues` | `fp` → `issue`, with `seen`, `first`, `last`, `bumped` |

`markFailed()` backs off `2^tries` minutes, capped at 6 hours.
`trimQueue()` keeps the newest `queue_keep` rows.

---

## 4. Sealed credential — `Vault`

### 4.1 What sealing does and does not do

Does: keeps the token out of the browser (all calls are server-side), keeps it
from matching `ghp_` / `github_pat_` so secret scanners and GitHub push
protection do not revoke it when the module lands in a public repository, and
keeps it out of casual reading and `grep`.

Does not: withstand someone who owns the machine. Inherent to shipping a
credential inside distributed code.

Therefore the shipped token **must** be a fine-grained PAT limited to the one
canonical repository whose only **write** permission is *Issues: Read and
write*. Read-only *Contents / Metadata* is permitted and is what the updater
uses (`module.json` + the release zipball); on a public repository it grants
nothing an anonymous request could not do. Forbidden: Contents write,
workflows, packages, secrets, actions, administration, org access, a second
repository, and classic PATs (which cannot be scoped to one repository at all).
The blast radius of a leak is then noise in one issue tracker, fixed by revoking
one token — such a token cannot write code, releases or any other repository.

Note for anyone validating a token from inside a sandboxed agent environment:
outbound GitHub traffic there may be re-authenticated by a proxy, in which case
an API probe reflects the session's permissions rather than the token's. Verify
a sealed token on the real server (`php selfheal_seal.php --check` plus one real
flush from the admin panel), not from such a sandbox.

### 4.2 Format

`sh1:<b64url(iv|tag|ct)>` — AES-256-GCM. `sh0:<b64url(nonce|tag|ct)>` — the
openssl-free fallback: HMAC-SHA256 keystream, XOR, HMAC tag verified with
`hash_equals` before decrypting. Key = `HMAC-SHA256("selfheal/v1/<module>",
pepper)`, pepper assembled at runtime from rot13 / reversed / base64 / chr
fragments so neither key nor token is a literal in the tree. Tampering, an
unknown prefix and non-base64 input all yield `''`.

Sealing happens in `selfheal_seal.php` (CLI only; returns 404 over HTTP). Input
comes from STDIN or `SELFHEAL_SEAL_INPUT`, never from an argument. The blob is
verified to round-trip before the file is written; the plaintext is never
stored, logged or echoed. A `ghp_` classic PAT triggers a loud warning.

### 4.3 Resolution order and the two files

`SELFHEAL_GH_TOKEN` → `selfheal/token.local.php` → `selfheal/token.php`.

| File | Owner | On update |
|---|---|---|
| `token.php` | module developer | **replaced** — this is how the credential rotates across the installed base |
| `token.local.php` | install operator | **preserved** (in `module.json → preserve`) and takes priority |

Listing the shipped file in `preserve` would make a leaked token
unrevokable; the smoke test asserts it is not listed and that the local one is.

Tokenless operation: `SELFHEAL_RELAY_URL` (HTTPS) receives
`{module, version, fp, title, report}` instead, or nothing is configured and
reports simply accumulate locally.

---

## 5. Capability keys — `Keys`

Format `sk1.<b64url(json)>.<b64url(hmac)>`, HMAC-SHA256 over the payload with
a per-install secret generated on first use. Claims: `c` (capabilities), `i`
(issued), `e` (absolute expiry, `0` = none), `m` (module), `a` (API version).

Rules: an unknown capability is rejected **at issue time** (returns `''`), so a
typo fails where it is written; a key from another install fails `verify`
(different secret); `ttl !== 0` is an absolute deadline, so a negative TTL
yields an already-expired key rather than silently meaning "forever".

Capabilities: `errors.report`, `errors.read`, `updates.read`, `updates.apply`,
`admin`. `applyUpdate()` and `rollback()` require `updates.apply`;
`adminNonce()` mints a 30-minute `admin` key used as the admin form nonce, so
our admin actions need no session and no host CSRF plumbing.

---

## 6. Reporting — `Reporter`, `Http`

### 6.1 Recording

`record()` fingerprints, applies volume control, writes one queue row and
schedules a flush. Local only; no network on this path.

Fingerprint = `sha256(module | api-major | normalized message | basename:line)`
truncated to 16 hex. Normalization lowercases, replaces hex runs ≥ 8 with `#`,
digit runs with `N`, and quoted strings with `S`, so "attempt 3, id 9f3a…" and
"attempt 7, id 771c…" collapse to one entry while a different throw site stays
distinct.

Volume control: `MAX_PER_FP_PER_DAY = 3` recordings of one fingerprint per day,
and `MAX_NEW_PER_HOUR = 6` recordings per hour for fingerprints that have no
issue yet — a fingerprint already filed is exempt from the hourly budget, so a
known recurring failure cannot crowd a genuinely new one out. A loop therefore
costs a bounded number of rows and at most one issue.

### 6.2 Sending

`scheduleFlush()` registers a shutdown function that calls
`fastcgi_finish_request()` / `litespeed_finish_request()` **first**, so the
page is already delivered, then flushes under `SEND_BUDGET_SEC = 6` seconds
total and `flush_per_request` rows. `ignore_user_abort(true)` keeps it alive.
Nothing is scheduled when consent is not `on` or no credential exists.

Delivery: relay when configured; otherwise a known fingerprint gets a comment
on its issue (at most once per `COMMENT_COOLDOWN = 6h`), an unknown one is
searched for in the repo (`in:body "fp:<fp>"`) before a new issue is created
with labels `auto-report` and `v<version>`. A `404`/`410` on the comment falls
through to opening a fresh issue; a `422` on creation retries without labels so
a missing label never costs the report.

`Http` allows HTTPS only, never follows a redirect off HTTPS, caps every call
at `MAX_TIMEOUT = 20`s, and returns errors instead of throwing.

### 6.3 Report content

`message`, `where` (`<module>/file.php:line`), `level`, `type`, `env`
(`module`, `api`, `php`, `os`, `sapi`, `install`, `app`, and `host` only when
`shareHost()`), scrubbed `context`, module-only `trace`, and the `fp:` marker
used for deduplication. `install` is a random 12-hex id stored locally — it
distinguishes "one site failing repeatedly" from "every site failing once"
without identifying anyone.

---

## 7. Consent — `Consent`

### 7.1 States

| State | Recorded locally | Sent | Set by |
|---|---|---|---|
| `ask` (default) | yes | **no** | initial state |
| `on` | yes | yes | operator |
| `off` | **no** | no | operator |

Reporting can never switch itself on. `SELFHEAL_REPORTING=on|off|ask` pins the
answer for a fleet, wins over the stored value and hides the question.

### 7.2 Asking

`selfheal_install.php` asks on the terminal and refuses to infer an answer from
silence; the admin block (§11) shows the question while it is open;
`AGENTS.md` §1 requires the assistant performing the installation to ask in the
operator's own words and records how. `shareHost()` is a separate, narrower
switch (default on) for the one identifying field.

### 7.3 Signature on user-facing errors

Every error text the module shows an end user goes through `signError()`, which
states what happens to that error — including when sending is off, so the
notice is never quietly misleading:

| State | Text |
|---|---|
| `on` | recorded **and sent** to the developer, who maintains the module automatically; can be switched off in the admin panel |
| `off` | recorded on this server only; sending is switched off |
| `ask` | recorded on this server; sending not permitted yet, decided in the admin panel |

The reference code (the fingerprint) is appended when known.
`boot(['sign_suffix' => …])` replaces the wording; there is no option to remove
the signature — it is the condition automatic maintenance runs on.

---

## 8. Scrubbing — `Scrub`

Applied to every title, body, context value and trace line before it leaves the
server: registered secrets (`boot(['secrets' => …])`, plus our own token),
credential-shaped strings (`ghp_`/`github_pat_`/`sk-`/`AQ…`/JWT/`Bearer`/
`key=…`), e-mail and card-like digit runs, then paths — module root →
`<module>`, its parent → `<host>`, `DOCUMENT_ROOT` → `<docroot>`,
`/home/<user>` → `<home>`. Context is scrubbed recursively to depth 4 with
non-string scalars preserved; traces keep module frames and collapse runs of
host frames into one placeholder line.

---

## 9. Error capture — `Guard`

### 9.1 Chained error handler

`set_error_handler` keeps the previous handler and, after recording, returns
`call_user_func($previous, …)` — or `false` when there was none, which means
PHP handles the error exactly as before. Nothing is swallowed;
`error_reporting`, `display_errors` and `log_errors` are not touched.

Reportable: `E_ERROR|E_PARSE|E_CORE_ERROR|E_COMPILE_ERROR|E_USER_ERROR|
E_RECOVERABLE_ERROR|E_WARNING|E_USER_WARNING`. Notices and deprecations are
noise and are ignored.

### 9.2 No exception handler by default

An uncaught exception already reaches the shutdown function through
`error_get_last()`, so installing an exception handler would add nothing and
could break a host that has one. `['exceptions' => true]` opts in; even then
the previous handler is called, and with none the default `error_log()`
behaviour is reproduced.

### 9.3 Shutdown

Read-only: inspects `error_get_last()`, reports a fatal from a module file,
never prints, exits or sends headers.

### 9.4 Scope

Default `'module'`: only files under `SELFHEAL_MODULE_ROOT`. The host's bugs
belong to the host, and flooding the developer's tracker with them would make
the mechanism useless. `'all'` is opt-in.

---

## 10. Updates — `Updater`

### 10.1 Check

`https://raw.githubusercontent.com/<repo>/<channel>/module.json` for the
version, then `/repos/<repo>/commits/<channel>` for the exact sha, cached in
`sh_kv` for `CHECK_TTL = 6h`. Unauthenticated (the reporting token has no
contents permission, by design). Failure is recorded as `error` and the cached
answer stands; `status()` never blocks a page.

`status()` computes `blockers`: a major API jump, a PHP requirement the server
does not meet, an unwritable module directory, or a version at or below
`pinned_version`. `update` is true only when a newer version exists **and**
there are no blockers.

### 10.2 Apply

Under an exclusive `flock` on `<work_dir>/update.lock`, with
`ignore_user_abort(true)`:

1. refuse on blockers (unless `force`) or when no sha is known;
2. download `/repos/<repo>/zipball/<sha>`; reject an archive under 1 KiB;
3. extract to `<work_dir>/stage/<sha>` (outside the module), unwrapping
   GitHub's single top-level directory;
4. **verify** (§10.4);
5. copy the whole current module to `<work_dir>/backup/<version>-<stamp>`;
   failure here aborts the update;
6. copy the release over the module, skipping `preserve` paths;
7. any failure in 6 → restore the backup and report the restored version;
8. remove files the previous release owned that this one dropped
   (`installed_files`; the first update deletes nothing);
9. record `previous_version`, `applied_sha`, `applied_at`; clear
   `pinned_version` and `notice_dismissed`; drop staging; keep 3 backups.

### 10.3 Rollback

Takes a pre-rollback safety copy, restores the chosen (default newest) backup,
and sets `pinned_version` to the version rolled back **from**, so the updater
does not immediately reinstall it. `unpin()` clears it. With no backups,
rollback refuses.

### 10.4 Verification

`module.json` present and parseable; its `version` equal to what `check()`
announced; `api_version` same major; at least 8 files; **every** `.php` file
parsed with `token_get_all($src, TOKEN_PARSE)` (throws `ParseError` on bad
syntax — no shell needed, works on locked-down shared hosting); a non-empty
`selfheal/bootstrap.php`. Any failure leaves the install untouched.

### 10.5 Preserved paths

`module.json → preserve`, always including `data` and
`selfheal/token.local.php`. `isPreserved()` matches a path or a directory
prefix, so `database.php` is not mistaken for `data`. `rmTree()` refuses to
delete anything outside `work_dir`.

---

## 11. Admin surface — `Admin`

### 11.1 Embedded block

`handleAdminPost($_POST)` before any output, `adminNotice()` where the block
should appear. `handleAdminPost()` returns `null` for a POST that is not ours,
so a host's own form handling is unaffected. Actions: `reporting_on`,
`reporting_off`, `share_host_on`, `share_host_off`, `flush`, `forget_reports`,
`check`, `update`, `rollback`, `unpin`, `dismiss` — each requires a valid
`admin` nonce (§5), and an invalid one changes nothing.

`notice()` renders, in order: the consent question while it is open, the "new
version" notice with «Обновить» / «Откатить» / «Скрыть», then a status table,
the action buttons and the last 10 local reports. Scoped `sh-…` class names and
inline styles only; `''` when there is nothing to say. Credentials never appear
— only `credential_fp`.

### 11.2 Standalone page

`selfheal_admin.php` — same block behind `SELFHEAL_ADMIN_PASSWORD` /
`ADMIN_PASSWORD` (from `config.php` when this module is used whole). With no
password set it answers 503 and renders nothing: an unprotected update button
on a public URL would be a hole.

### 11.3 Host integration in this repository

`setup.php` requires `selfheal/bootstrap.php`, boots with the four known
secrets registered, calls `handleAdminPost()` before rendering and echoes
`adminNotice()` above the settings form.

---

## 12. Installer — `selfheal_install.php`

CLI or page. Reports the environment (PHP version, required and optional
extensions, state file, module-directory writability, credential presence),
asks the consent question (§7.2) unless `--yes` / `--no` / `--status`, and
prints the exact host snippet. It never writes to the host project, never
touches the host database and never enables anything unasked.

---

## 13. Tests

### 13.1 `tests/selfheal_smoke.php`

105 assertions, no network: isolation (no new globals, `error_reporting` and
`display_errors` untouched, no global functions, own state file, the host's
error handler still called and still authoritative, the autoloader ignoring
foreign classes), the full `PUBLIC_API` surface, `fits()` / `autoUpdatable()`,
capability keys (forgery, expiry, foreign install, unknown capability, missing
right on update and rollback), sealing (round-trip, no plaintext, no scanner
match, tampering, the two-file split, and a tree-wide scan for plaintext
tokens), scrubbing, consent states and the ENV pin, recording with dedup and
storm limiting, the signature in all three consent states, admin POST handling
with and without a valid nonce, markup balance of the rendered block, and a
pass proving every public method is safe on a broken state file.

### 13.2 `tests/selfheal_update.php`

27 assertions against synthetic releases in a temp directory: a good release
accepted; a substituted version, a syntax error (naming the file), a missing
entry point, a major API jump and a truncated archive all rejected; preserved
paths correct and excluded from the file list; operator data, local token and
`pull-config.php` intact across both an update and a rollback; backups listed
with their version; `rmTree()` refusing to leave `work_dir`.

`apply()`, `rollback()` and `restore()` write to `SELFHEAL_MODULE_ROOT` by
design and are therefore **blocked** from reflection in this file, with every
argument checked against the live module path. An early draft called
`restore()` and overwrote the working checkout with a synthetic release; the
guard exists so that cannot recur.
