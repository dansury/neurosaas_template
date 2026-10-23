# Technical plan

PHP 8.1+ runtime, PHP 7.4+ standalone installer, no Composer or frontend build. Existing inline CSS keeps the single-upload installer independent of project assets.

## Constitution check

Preserve secrets, account verification, public adapter API, reporting consent, local settings and rollback. Verification uses local fixtures and no external AI, mail or issue creation.

## Decisions

1. Add the attribution and clean-starter contract to `CLAUDE.MD`, the constitution and README. Explain the document hierarchy instead of deleting useful module contracts.
2. Use white backgrounds and explicit light color schemes on existing pages. Remove template product labels from the management shell while keeping functional vendor names, the owner's personal consultation links and the original source link in README.
3. Apply identical installer changes to `pull.php` and `admin/deployment/panel.php`; retain their single documented root-path difference. Keep operator-data migrations and public API names.
4. Remove the unused `spec-kit-main.zip` archive: active instructions and constitution are ordinary repository Markdown; no runtime or maintenance command references the archive.
5. Bump the connector patch version for its presentation changes. Add focused checks to the existing layout suite and inspect rendered pages under a dark system preference at desktop and mobile sizes.

## Analysis

FR-004 also maps to `tests/template_attribution.py`, run locally and in CI.
The check reads documentation only: it verifies a visible Markdown link with
the exact original destination after excluding comments and code, and keeps
the specification, constitution, and root agent instructions aligned. It
requires no PHP server, credentials, network access, or paid provider call.

FR-001 maps to existing standalone HTTP deployment fixtures. FR-002 maps to the starter, mirrored installer CSS, connector settings and standalone maintenance CSS. FR-003 maps to archive removal and existing preservation tests. FR-004–006 map to root documentation and the specification index. No public API, schema or authentication change is required. Delivery requires green PHP 8.1/8.3 CI, a merged pull request and final default-branch checks; record their result in the pull request.
