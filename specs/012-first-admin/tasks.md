# Tasks

- [x] T001 (FR-001–004) Update `admin/account/gate.php` enrollment and recovery.
- [x] T002 (FR-005) Update `admin/llm_connector/feedback_widget.php`, `admin/deployment/panel.php` and `pull.php` feedback navigation.
- [x] T003 (FR-006) Synchronize `README.md`, `admin/account/README.md`, `specs/admin-layout.md`, `specs/008-operator-workflows/spec.md` and installer copy.
- [x] T004 (FR-001–006) Update `tests/account_http.py`, `tests/local_diagnostics_http.py`, `tests/browser_operator_workflows.cjs`; run supported offline checks and CI.

Validation: 33 account HTTP checks, 37 local-install/diagnostic checks, 25 account/layout checks, desktop/mobile feedback navigation with frame denial, archive deployment and rollback. PHP suites pass on Linux CI for 8.1 and 8.3; Unix file-permission assertions are verified there. Final PR checks must pass before merge.
