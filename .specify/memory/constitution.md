# NeuroSaaS Template Constitution

## I. Safe, reversible delivery

`pull.php` remains a self-contained installation entry point. A deployment identifies the source revision, preserves operator-owned files and secrets, serializes concurrent updates, reports an actionable failure, and leaves a usable rollback path. A deployment or module refresh must never silently replace local configuration, data, uploaded assets, or reporting consent.

## II. Local secrets and least privilege

Credentials are stored only on the operator's server, never echoed into HTML, logs, Git, generated issues, or URLs. Every key helper names the exact repository scope and minimum permission it needs. Authentication, CSRF validation, password handling, and secret-file permissions are mandatory at every mutation boundary. A disabled integration or provider retains no active execution path.

## III. One configuration, one understandable behavior

The same concept has one representation and one effect across setup, the dashboard, and embedded service UI. Controls show a meaningful default, explain where a value comes from, and link to an official credential source when applicable. Provider settings, model catalogues, fallback order, and error messages must agree with the runtime actually used; no placeholder control may claim a capability it does not perform.

## IV. Operator-first Russian interface

The default interface is concise Russian, with an explicit remembered language switch. Important actions expose their consequence, status, and recovery path. Feedback and development-request flows protect customer data, clearly disclose their GitHub destination, and guide a first successful submission to the next useful step. Layouts remain usable on desktop and mobile without hiding essential actions.

## V. Bounded AI and toolkit integration

The instance-based `admin/llm_connector/adapter.php` API stays backward compatible while the optional toolkit remains isolated behind its authenticated route. Providers are configured and tested only on the operator's explicit action; automated verification never sends mail, calls paid AI, or creates GitHub issues. Canonical toolkit updates retain local settings and reporting remains opt-in.

## VI. Evidence before completion

Specifications describe intended behavior before non-trivial implementation. Tests cover the changed contract with offline fixtures, including security and failure paths. Work lands through a reviewable branch and pull request; completion requires merged code, a recheck of the applicable behavior, and closure of the resolved issue.


## VII. Clean foundation and source attribution
Fresh services start with a neutral white interface and no unrelated product branding, business logic or unused source archives. The standalone upload-and-install path must not acquire a build or package-manager prerequisite. Focused modules expose documented public APIs; their contracts and agent instructions stay synchronized with code. Preserve the original `https://github.com/dansury/neurosaas_template/` link in the root README until the developer explicitly requests its removal, including after renaming or adapting a service. Protect existing operator data and configuration when cleaning the template.

