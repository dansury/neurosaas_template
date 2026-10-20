# GITHUB ISSUES
When a github issue in my repo is resolved and PR is merged, close the issue. 

# PR MERGE
When finished working in github, merge PRs.

# Service integration
Every generated service interface must include admin/llm_connector/bootstrap.php before output and a visible «Заказать доработку» link to admin/?feedback=1. Use the authenticated admin/?toolkit=1 settings route for the canonical infrastructure; keep the original LLM adapter for GenRouter compatibility. Module maintenance must preserve local settings and reporting consent. Read specs/toolkit-parity.md before changing integration.

# SPEC-DRIVEN DEVELOPMENT

1.0 Use GitHub Spec Kit (https://github.com/github/spec-kit): constitution, specification, technical plan, traceable tasks, analysis, implementation and verification. Follow the installed Spec Kit skill when available. Read specs/index.md, then only the relevant module specification before source code. Keep specs and tests in the repository.
1.1 Lookup file -> spec mapping in specs/llm_connector/index.md. It is a thin navigation index — read once, then open ONE specs/llm_connector/<module>.md for the relevant area. Never read the whole /spec/ folder.

1.2 For reference details (signatures, DB schemas, algorithms, flows, SEO contract, email flows, referrals, QR cards): open exactly one specs/llm_connector/<module>.md that matches the module you are editing. If the detail is missing there, read the source file — do NOT pull another specs/llm_connector/<module>.md unless needed.

1.3 Spec-driven workflow — for any new feature or non-trivial change:
Read the relevant specs/llm_connector/<module>.md.
Update that spec to describe the planned change (signatures, tables, flows, configs) — before writing any code.
Implement code to match the updated spec.
Specs describe actual functionality only — never changelogs or version history. For pure bug fixes that require no design decisions, step 2 may be skipped; update the spec after the fix if its content was inaccurate.

1.4
All spec and code in English, interface - in the user's language.

## 2 How to work with TK
- **Read the TOR in its entirety, every paragraph.** An item mentioned in passing,
  — the same requirement as highlighted in bold. The missed point has to be
  correcting it with a second pass is more expensive than reading it carefully right away.
- **Check the TOR before submitting**: go through the list of requirements and for each
  name exactly where it was completed. It hasn’t been completed — say so directly, don’t keep quiet.
- **Check the interface requirement visually in the markup**, not just in the code: “the field is hidden” means it’s not visible on the screen, and `hidden` in HTML can still be overridden by any `display` in CSS.

## 3 Assess the complexity of the task.
If you don’t have time to complete some tasks during this pass, write them down in TODO.md.
Before reading the spec, check TODO.md and complete those tasks first.
After passing the feature test, remove the completed tasks from TODO.md; leave the uncompleted ones.
