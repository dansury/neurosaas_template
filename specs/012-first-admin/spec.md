# Feature Specification: First administrator without mail

**Created**: 2026-10-26
**Status**: Implemented
**Input**: «первого пользователя можно создавать без подтверждения по почте будет, потому что часто smtp недоступен изначально»; attached feedback frame connection error.

## User Scenarios & Testing

### US1 — Complete initial setup without mail (P1)
The installation owner supplies an email and a strong matching password and can sign in immediately, including when SMTP and hosting mail are unavailable.

Acceptance: the initiating installation browser (or an authenticated legacy owner) creates the first account without a mail attempt or code form. Invalid CSRF, invalid email and weak/mismatched passwords fail. Another browser cannot claim a pending installation or replace an existing account.

### US2 — Recover access by email (P1)
Recovery sends a short-lived code to the stored account address. Correct confirmation changes the password and verifies ownership of that address. Unknown addresses, wrong, expired, reused and cross-session codes grant no access. Delivery failure leaves the existing password usable.

### US3 — Open development requests on restrictive hosting (P2)
Development request links open the authenticated feedback page directly, so hosting policies that prohibit framing do not leave an unusable dialog.

## Requirements
- FR-001 (US1): Initial creation MUST require no SMTP, hosting mail or email code.
- FR-002 (US1): Creation MUST retain installation-session, existing-owner authentication, CSRF, validation, locking and password-hash protections.
- FR-003 (US1, US2): Creation MUST distinguish account enrollment from email ownership verification and preserve existing verified accounts.
- FR-004 (US2): Recovery MUST require the existing bounded email challenge, including for accounts created without verification.
- FR-005 (US3): Public and administration feedback links MUST navigate to the feedback route without an iframe.
- FR-006 (US1): Installer instructions and account UI MUST explain immediate creation and later SMTP configuration.

## Success Criteria
Offline HTTP tests demonstrate creation with a failing mail stub, protected recovery, legacy authentication and fresh-install browser ownership. Browser tests demonstrate feedback navigation at desktop and mobile widths with framing denied.

## Scope
One administrator per installation; no general multi-user registration or live SMTP/production calls.
