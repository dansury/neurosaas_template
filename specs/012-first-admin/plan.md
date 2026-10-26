# Technical plan

PHP 8.1+, existing filesystem configuration and locked private account state; no new runtime dependencies.

## Constitution check
Keep standalone pull.php synchronized with the bundled deployment engine, preserve configuration, legacy verified accounts, owner authentication and private state. Tests use disposable fixtures with mail stubbed and no external requests. All principles pass.

## Decisions
- Store `admin_account_created=true` separately from `admin_email_verified=false`. `AdminAccount::enrolled()` accepts valid legacy verified accounts or the explicit creation marker with a valid address and password hash. `verified()` continues to describe actual email verification.
- Commit initial email/password under the existing account transaction lock; refuse duplicate creation and discard old enrollment challenges. Rotate session/CSRF and require login with the new password.
- Limit sending/consuming challenges to recovery. Recovery accepts enrolled accounts, proves email ownership on successful confirmation, and retains rate limits, expiry and session binding.
- Use normal feedback links instead of frames. Hosting X-Frame-Options/CSP policies cannot be reliably changed by the application, so direct navigation is portable.
- Update both installer engines, account and installation documentation; exercise HTTP fixtures and browser checks in CI on supported PHP versions.
- Normalize the final administration cookie path after Windows dirname processing so root and nested installations retain their owner session. Session fixture edits preserve serialized bytes across operating systems.

## Analysis
Every task maps to FR-001–006. Initial enrollment no longer implies verified email; recovery retains proof of mailbox control. Legacy setup credentials still precede enrollment. No schema migration or local settings overwrite is required.
