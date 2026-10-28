# Feedback controls

Created: 2026-10-28. Input: issue #36 and explicit provider fallback controls.

## User story and requirements
An authenticated owner submits a development request with screenshots and dictation, or opens GitHub's own login and issue composer.

- FR-001: Bundle only AES-256-GCM ciphertext for the separate support credential. Import the 32-byte decryption key through authenticated administration; preserve it during updates. No OAuth application is required. GitHub cookies do not authorize PHP API calls.
- FR-002: Offer configured audio models from provider capability metadata, validating the chosen model on the server. Preserve typed text and allow explicit clipboard screenshot insertion.
- FR-003: Open a movable, resizable feedback dialog, retaining its contents when closed and reopened. Fall back to page navigation under restrictive frame policies.
- FR-004: Store bounded screenshots privately and provide random bearer URLs, disclosed before submission and expiring in 30 days, without GitHub Contents permission.
- FR-005: Expose primary and backup provider selectors, preserve saved secrets and skip disabled providers in the host adapter.

## Acceptance
Offline tests verify credential tampering, model validation, transport choice, dialog movement/reopening, clipboard images, issue-only credentials and image expiry at desktop/mobile sizes. No live issues, mail or billed AI calls are made by tests.
