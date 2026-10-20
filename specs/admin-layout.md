# Administration and template layout

## User contract
The public index.php is a Russian welcome page with a top-right administration link. pull.php remains a standalone first installer; after installation its browser routes lead to admin/index.php. Updates, model configuration, SMTP and development requests are available only in administration. Successful installation displays a pulsing link to the site root, respecting reduced-motion preferences.

## Layout
Runtime infrastructure lives in admin/llm_connector, including its bootstrap, host adapter, toolkit, settings, document and mail services and maintenance files. Account handling lives in admin/account. All module specifications are under specs/llm_connector and technical documentation is under specs. Each README begins with a short Russian explanation of purpose and first use. Computer-only maintenance utilities live in Обслуживание_с_компьютера with a Russian README and script comments. No origin history or source manifest is shipped.

## Verified account
The first administration visit asks for email and a password of at least 12 characters. Existing installations must authenticate using their current deployment password before enrolling. A six-digit cryptographically random code confirms the address; codes expire after 10 minutes, allow five attempts, are stored hashed and are never rendered or logged. A 60-second resend cooldown and a shared persistent rate limit constrain delivery. A pending registration does not grant management access. Confirmation atomically stores the email and new password hash in pull-config.php; old cookies become invalid.

Forgot-password starts from the login page. The same six-digit code flow sends only to the previously verified email and uses a generic response for unknown addresses. Correct confirmation changes the password hash and invalidates old sessions. Expired, wrong, reused and exhausted codes are rejected; new codes invalidate previous ones. CSRF protects every POST. Challenges and rate limits are locked and stored privately outside public access. Setup without working mail fails with an actionable explanation, without bypassing verification. Delivery uses configured SMTP when available and otherwise the hosting mail service; no test sends real email.

## Installation preservation
Deployment preserves account state and both current and legacy module data, environment, local reporting credential and deny rules. Before new paths are used, a one-time locked migration copies local legacy state without overwriting current files or changing reporting consent. Failure blocks management and deployment instead of accepting partial settings. The installer also preserves its own file, config, AI credentials and deployment history.

## Plan and traceability
1. Move module and specifications; update all imports, fixtures, maintenance utilities and navigation.
2. Add account enrollment and recovery with locked challenges, bounded retries and injectable test transport.
3. Mount management in admin, retain standalone initial deployment and cron, add welcome and success navigation.
4. Run lint and offline PHP/HTTP/security suites on PHP 8.1 and 8.3; inspect rendered markup and reduced-motion CSS.

## Acceptance
Exercise standalone setup, first deploy, admin redirect, password authentication before legacy enrollment, invalid CSRF, enrollment confirmation, wrong/expired/reused code, resend cooldown, reset, cookie invalidation, local-data migration, purge preservation, module settings and root navigation. Existing provider, document, SMTP MIME, diagnostic and self-maintenance contracts remain covered. Issue #15 closes only after these changes merge and verification passes.

## Bundled engine and legacy installer
The standalone root installer contains the same engine as admin/deployment/panel.php; only PULL_ROOT initialization differs. Administration always loads its bundled current engine. Before accepting requests, admin/index.php atomically upgrades an older preserved installer under the deployment lock, keeping all local config and state untouched. The account and module directories deny direct HTTP access. Module data migration uses same-filesystem atomic rename without overwriting existing destinations.

The root cron exception accepts only GET requests with check and plain parameters. Mixed toolkit, feedback or other query parameters and POST requests lead to administration and its verification gate. A mail delivery failure retains an unverified account and displays hosting/SMTP recovery instructions.
