# Local installation and feedback diagnostics

Created: 2026-10-21. Status: verified. Input: full-file hosting installation must duplicate pull.php; resolve #18 using kp-atlant error logging and feedback attachments.

## User stories and requirements

- US1 / FR-001: After uploading the full archive, the owner can choose local installation without a GitHub repository or key. The same authenticated administration, verified email, AI, mail, toolkit and password controls are available. A standalone pull.php upload cannot claim the application is already installed.
- US1 / FR-002: Local mode performs no GitHub checks, downloads, deployment or automatic update. Status and history explain the mode. The owner can connect a repository later from administration without replacing account, secrets, data or reporting consent.
- US2 / FR-003: PHP warnings and fatal errors are recorded locally without recursion or breaking the original error handler. Diagnostics contain bounded, redacted technical information; secrets, customer payloads, request URLs and email codes are excluded.
- US2 / FR-004: The authenticated development-request form offers an unchecked attachment option and an escaped, readonly preview of the exact bounded diagnostic snapshot. Only explicitly selected diagnostics are included as a text section in the GitHub issue. CSRF, authentication, validation and cooldown apply. No extra GitHub Contents write permission is required.
- US2 / FR-005: A local installation can inspect/copy diagnostics and compose a request, but sending requires a connected repository and Issues-write key. No automatic reporting is enabled.
- US3 / FR-006: README explains both installation paths, hosting/mail requirements and network boundaries. Capture actual initial screens, installation, account, administration, AI, toolkit, diagnostics and feedback at desktop/mobile sizes.

## Acceptance

SC-001: Fresh complete archive starts locally with GitHub network stubs that fail if called; the real verified-account flow completes with a mocked mail transport.
SC-002: Status, cron, deployment, rollback and auto actions cannot contact GitHub in local mode; connecting later preserves local settings.
SC-003: Tests trigger warnings, suppressed warnings and a fatal error; logs are bounded and redact configured/unregistered credentials. Feedback without consent excludes diagnostics; selected diagnostics match the preview, including when new events arrive.
SC-004: Existing PHP/HTTP suites pass; installer matches bundled engine except its root definition. Rendered captures are from the running PHP application and identify test-only mail transport.

## Scope and provenance

Port the behavior of kp-atlant lib/logger.php (PHP warning/fatal capture, non-recursive logging) and lib/support.php (attach_log opt-in). Reuse the bundled DiagLog rather than copying kp-atlant database, customer workflow, branding or notification system.

Sources: https://github.com/dansury/kp-atlant/blob/main/lib/logger.php and https://github.com/dansury/kp-atlant/blob/main/lib/support.php. Explicit consent remains local-only by default.
