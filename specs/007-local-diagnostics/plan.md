# Technical plan

Use the existing PHP 8.1+ dependency-free installer/runtime pair. Store an explicit local installation mode in pull-config.php; missing mode retains branch/PR behavior. Offer local setup only when all application entry points exist. Gate GitHub operations server-side before network calls; expose later connection as a CSRF-protected administration action that merges only repository settings.

Add admin/llm_connector/diagnostics.php over existing DiagLog/Toolkit, installed before ordinary page output and administration handling. Register warnings and shutdown fatal handling with a recursion guard; keep errors useful via type, relative file and line while excluding arbitrary payloads from automatic PHP capture. Redact exported diagnostic context/keys, known configuration secrets, emails, paths and credential-shaped strings.

Freeze a bounded snapshot in the authenticated session when rendering feedback, label it with an unguessable ID, and require that ID with attach_diagnostics. Escape HTML preview and neutralize Markdown delimiters in issue text. Standalone installer without the module reports diagnostics unavailable.

Constitution check: standalone upload preserved; local secrets and CSRF unchanged; no background external reporting, email or AI tests; neutral Russian UI and source attribution preserved. No new runtime packages.

Verification: PHP lint, existing offline suites, new isolated HTTP/security tests, PHP logger tests and Playwright screenshots. No production configuration or real email/AI/feedback submission.
