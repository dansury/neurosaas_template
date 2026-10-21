# Standalone installer and version management

`pull.php` is a self-contained uploadable deployment entry point. Its engine is
identical to `admin/deployment/panel.php` except PULL_ROOT. A minimal PHP bootstrap
checks the runtime before evaluating PHP 7.4+ engine syntax, so an older handler
shows its version and requirements rather than a parser failure. PHP must be
configured on the host. Zip and cURL (or allow_url_fopen) and a writable target are
required; the advanced toolkit additionally needs PDO SQLite, DOM and mbstring.
Apache 2.4+ is relevant to .htaccess deny rules; nginx needs equivalent rules.

## Setup and authentication

A single-file upload offers GitHub setup. A complete archive also offers local
setup with no repository or token. Neither requires a second installation
password: the pending configuration is tied to the initiating browser session.
Verified administration enrollment supplies its actual password and email-code
flow. Existing installation passwords, accounts and secrets remain valid. A
lost pending session requires removing pull-config.php through hosting controls
and restarting setup. Browser mutations require CSRF; credentials stay server-side.

Token creation precedes fields and requests Contents write for screenshots,
Issues write and Pull requests read. Pasting the user token loads accessible
repositories and then branches over POST; main is selected when available.
GitHub credentials never appear in URLs or JSON responses. The token's user is
the author of submitted issues. Local mode performs no GitHub operation until
connected; a new connection enables the requested automatic-update default.

## Exact versions and rollback

GitHub refs resolve to an exact commit before download and extraction. Deploys
share a nonblocking pull-deploy.lock, validate all ZIP paths/symlinks/size limits,
copy files atomically and preserve local settings/data. Failed resolution or
extraction performs no live-file copy or purge. State holds twenty successful
versions and available associated PR summaries. Only a recorded version may be
restored. Rollback pins automatic updates until an explicit GitHub deployment.
The UI explains restoring the desired files with a new commit/PR into main,
preserving history rather than changing the default branch or force-pushing.

ZIP uploads use the same lock, path validation, atomic copy and preservation
rules. A root index.php/index.html or one enclosing project folder is supported.
The prior manual installation is snapshotted before its first archive update.
Archives are private PHP envelopes, keyed by their content hash and included in
normal history. Archive hashes are not represented as Git commits. Local-mode
archive history works without GitHub access. A visible progress/result surface
accompanies technical output; secrets are not progress messages.

Preserved paths include pull.php/config/state/lock, ai-config.php, .git, .env,
config.php, data, storage, pull-archives, account state and current/legacy module
settings/data/credentials. Operator keep paths are added. Optional purge runs
only after successful extraction/copy. Administration atomically keeps the
preserved standalone installer consistent with its bundled engine.

## Routes

GET opens management; installed browser routes redirect to admin. The exact GET
check/plain pair remains the cron exception. Status/history/watch are read-only;
deploy, archive, rollback, preferences, feedback and account changes require POST
and CSRF. Cron passes a configured password in X-Pull-Password, never in a URL.
New GitHub setups default automatic checks on while the panel is open; legacy
saved off choices remain off. Local mode has no GitHub checks. Foreground checks
stop with the browser; a cron job is needed without an open browser.

## Computer updater

pull.py uses Python 3.9+ stdlib for token-assisted repository/branch selection,
exact-commit ZIP downloads, safe extraction, atomic copies, preserved data,
dependency installation and one-minute foreground polling. --once and --no-deps
bound work; --rollback restores a downloaded commit and pauses polling. pull.cmd
and pull.sh explain where to get Python when absent. Dependencies may require
Node/npm, PHP/Composer or a Python virtual environment according to project files.

## Verification

PHP 8.1/8.3 offline suites cover authentication, CSRF, account migration, provider
payloads, local diagnostics and consent, setup and rollback. Operator tests add
ZIP traversal rejection and GenRouter isolation. Browser acceptance uses a
private disposable PHP installation for desktop/mobile setup, movable feedback,
provider controls, a real ZIP upload and restoration of the prior installation.
No live issues, email or paid inference are sent in tests.
