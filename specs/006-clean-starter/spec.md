# Feature Specification: clean, documented starter

Created: 2026-10-21

## User scenarios and acceptance

### US1 — Start a new service (P1)
A developer creates a repository from this template, uploads only `pull.php`, and follows the Russian instructions to install and configure their service.

- FR-001: The installer remains self-contained; no local build, package manager or additional uploaded asset is required. Hosting and email prerequisites must be explicit.
- FR-002: The welcome page and management screens use a neutral white interface, including diagnostics, errors and standalone maintenance screens, regardless of system theme. Provider names remain where they identify integrations.
- FR-003: Unused source archives and unrelated product branding must not be shipped. Compatibility that protects existing operator data remains supported; new services must not acquire old product workflows.

### US2 — Develop with an AI agent (P1)
A developer or AI agent can identify the instructions, module contracts and public entry points without reading every document.

- FR-004: Root `README.md` contains a visible Markdown link to `https://github.com/dansury/neurosaas_template/`. Agents preserve that original URL when renaming or adapting the service, until the developer explicitly requests its removal. A link to the derived repository or template-generation page is not a replacement.
- FR-005: Root instructions establish neutral UI, standalone installation, cohesive modules, meaningful API documentation and synchronized specifications as ongoing development requirements.
- FR-006: The README explains the purposes of `CLAUDE.MD`, `AGENTS.md`, module READMEs, specifications and tests, and gives a short reading order. Documents describe actual behavior and are maintained with code.

## Original template attribution acceptance (FR-004)

1. Given a README rewrite, project rename, or rebranding, when the change is
   delivered, the rendered root README still credits the original template
   with a Markdown link to `https://github.com/dansury/neurosaas_template/`.
2. Given only a `Use this template` link ending in `/generate`, a plain-text
   URL, an HTML comment, or a code example, attribution is not satisfied;
   the source must be a visible Markdown link in ordinary README prose.
3. Given routine documentation changes without an explicit request to remove
   attribution, repository checks reject removal of the original link.

## Edge cases

- A hosting account without working mail needs actionable SMTP or hosting-mail setup; email verification is never bypassed.
- An existing installation retains configuration, reporting consent, module data and rollback support.
- Explicit future developer instructions may change product design or remove attribution; ordinary rebranding does not imply permission to remove the original link.

## Success criteria

- SC-001: A fixture that initially contains only `pull.php` completes setup and first deployment through the existing offline HTTP suite.
- SC-002: Rendered welcome, installer, login and diagnostic screens stay light at desktop and mobile sizes with a dark system preference.
- SC-003: All existing offline PHP and HTTP suites pass, including installer/runtime equivalence and preservation checks.
- SC-004: Repository documentation contains the original link and a clear map of development instructions.

## Out of scope

Replacing working integrations, adding application-specific business logic, sending email, calling paid AI or removing operator-data migration.
