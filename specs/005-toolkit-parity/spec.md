# Feature Specification: complete reusable AI infrastructure
Created: 2026-10-16
Input: "Приведи в соответствие! ... В самом ... neurosaas-template и ... kp-atlant замени ... на ... заказать доработку"

## User stories
### US1 — Reuse the complete infrastructure (P1)
A service owner can connect models, extract text from PDFs/DOCX and images, send mail and inspect sanitized diagnostics without rebuilding those modules.
Given a fresh template installation, when the owner opens advanced settings, then model catalogs, fallback/vision/OCR, SMTP, diagnostics and module maintenance are available.
### US2 — Keep an installation current (P1)
Updates preserve local settings, credentials and module data. The owner can enable checks on normal application pages and safely roll back compatible module updates.
### US3 — Order an improvement (P1)
The owner can order a development change from the interface. The request goes to the configured repository, respecting existing permissions and review rules.

## Requirements
- FR-001: Ship all capabilities identified in issue #5 from the canonical toolkit, including model/version/endpoint fallbacks and cached catalogs.
- FR-002: Retain the template's Yandex/GenRouter/OpenRouter adapter and its existing API.
- FR-003: Reuse authenticated access and reject embedded settings POSTs without a valid CSRF token.
- FR-004: Preserve module data, local token, .env and deployment configuration during whole-project deploy/purge.
- FR-005: Reporting defaults to undecided/local only and is explicitly enabled by the operator. Do not distribute the canonical reporting credential in the template.
- FR-006: Provide a reusable bootstrap hook for updates on application page views and a safe feedback link.
- FR-007: Label the feedback action Заказать доработку / Request a change.
- FR-008: Document runtime requirements and reproducible offline verification.
## Edge cases
Host and toolkit both declare LLM; secrets may already exist in ai-config.php; module settings may be newer than migration; direct module setup requests must not inherit host authentication; update/purge must retain nested operator data.
## Success criteria
SC-001: All parity rows have implementation and an offline check.
SC-002: Original template unit/HTTP tests and canonical suites pass on Linux.
SC-003: A fresh installer, authenticated advanced-settings page and development request form work without live external transactions.
## Out of scope
Changing commercial workflows or automatically sending customer messages. Lowering the template's PHP requirement below 8.1 is separate from feature parity.

