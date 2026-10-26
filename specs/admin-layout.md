# Administration and template layout

## User contract
The public index.php is a Russian welcome page with a administration link and a top-right direct feedback link. pull.php remains a standalone first installer; after installation its browser routes lead to admin/index.php. Updates, model configuration, SMTP and development requests are available only in administration. Successful installation displays a pulsing link to the site root, respecting reduced-motion preferences.

The welcome page, installer and management shell use a neutral white background and explicit light color scheme regardless of system theme. The shell uses generic service labels and functional navigation, without unrelated product brands. Preserve the owner's personal consultation and development-request links. Keep the original template link in the root README until the developer explicitly requests removal. The installer has no dependency on separately uploaded CSS or build assets; useful Markdown contracts remain in the repository, while unused source archives are excluded.

## Layout
Runtime infrastructure lives in admin/llm_connector, including its bootstrap, host adapter, toolkit, settings, document and mail services and maintenance files. Account handling lives in admin/account. All module specifications are under specs/llm_connector and technical documentation is under specs. Each README begins with a short Russian explanation of purpose and first use. Computer-only maintenance utilities live in Обслуживание_с_компьютера with a Russian README and script comments. No origin history or source manifest is shipped.

## Verified account
New setup asks for no separate installation password and binds pending configuration to the initiating browser session. First administration visit asks for a valid email and matching password of at least 12 characters. Existing installations authenticate with their current deployment password before enrolling. Initial creation sends no email and requires no code. Under the private account lock it atomically stores the email, password hash, admin_account_created=true and admin_email_verified=false in pull-config.php, rejects duplicate creation and removes old enrollment challenges. Session/CSRF rotate and old authentication cookies become invalid; the owner signs in with the new password. Enrollment accepts legacy verified accounts or explicitly created accounts with a valid email and password hash. Email ownership remains unverified until successful email recovery.

Forgot-password starts at login and sends a six-digit cryptographically random code only to the enrolled email, including accounts created without verification. Unknown addresses receive a generic response. Codes expire in 10 minutes, allow five attempts, are session-bound, one-use and stored hashed; delivery has a 60-second cooldown and persistent shared rate limit. Correct confirmation verifies email ownership, changes the password hash and invalidates old sessions. CSRF protects every POST. Challenges and rate limits are locked and private. Delivery failure leaves the existing password usable and explains SMTP recovery; initial creation never depends on delivery. Tests never send real email.

## Installation preservation
Deployment preserves account state and both current and legacy module data, environment, local reporting credential and deny rules. Before new paths are used, a one-time locked migration copies local legacy state without overwriting current files or changing reporting consent. Failure blocks management and deployment instead of accepting partial settings. The installer also preserves its own file, config, AI credentials and deployment history.

## Plan and traceability
1. Move module and specifications; update all imports, fixtures, maintenance utilities and navigation.
2. Add account enrollment and recovery with locked challenges, bounded retries and injectable test transport.
3. Mount management in admin, retain standalone initial deployment and cron, add welcome and success navigation.
4. Run lint and offline PHP/HTTP/security suites on PHP 8.1 and 8.3; inspect rendered markup and reduced-motion CSS.

## Acceptance
Exercise standalone setup, first deploy, admin redirect, password authentication before legacy enrollment, invalid CSRF, enrollment without SMTP and recovery confirmation, wrong/expired/reused code, resend cooldown, reset, cookie invalidation, local-data migration, purge preservation, module settings and root navigation. Existing provider, document, SMTP MIME, diagnostic and self-maintenance contracts remain covered. Issue #15 closes only after these changes merge and verification passes.

## Bundled engine and legacy installer
The standalone root installer contains the same engine as admin/deployment/panel.php; only PULL_ROOT initialization differs. Administration always loads its bundled current engine. Before accepting requests, admin/index.php atomically upgrades an older preserved installer under the deployment lock, keeping all local config and state untouched. The account and module directories deny direct HTTP access. Module data migration uses same-filesystem atomic rename without overwriting existing destinations.

The root cron exception accepts only GET requests with check and plain parameters. Mixed toolkit, feedback or other query parameters and POST requests lead to administration and its enrollment gate. A recovery mail delivery failure preserves the existing account and displays hosting/SMTP recovery instructions.

