# Feedback dictation

## User story
An operator dictates feedback and receives readable Russian text without losing
facts or existing text. Only existing feedback functionality is upgraded from
kp-atlant; sales, catalog and document workflows are outside this template.

## Requirements
- FR-001: Format recognized speech with punctuation and grammatical agreement
  using the configured text-model chain at temperature zero.
- FR-002: Preserve numbers, identifiers containing digits, email addresses and
  URLs. If formatting fails or changes protected facts, retain the transcript
  and show a warning; never discard recognized speech.
- FR-003: Report the selected audio provider, model and HTTP status on failure,
  without disclosing keys or raw provider responses.
- FR-004: Enable dictation only when the selected audio provider has a key.
- FR-005: Keep submission explicit, preserve the existing feedback field and
  send audio only following the operator's recording action.

## Shared feedback contract

Authenticated feedback offers configured audio models from provider capability metadata. The server validates the selected model before sending audio. The form opens in a movable, resizable dialog with normal-page fallback when framing is denied. Clipboard screenshots use random server URLs disclosed to the operator, expire in 30 days, and need no GitHub Contents permission.

The login button opens GitHub's own login and repository issue composer; browser login alone does not authorize server API calls. Direct form submissions use the encrypted issue credential. Only AES-256-GCM ciphertext is bundled; the owner imports the separate 32-byte feedback.key through authenticated administration. Existing deployment keys remain supported. Provider fallback order is chosen with explicit primary/reserve selectors; disabled providers are skipped and saved keys preserved.

## Verification
Offline fixtures cover punctuation, changed facts, empty output, exceptions,
non-Latin identifiers, email and URL preservation. Existing provider, HTTP and
browser suites verify compatibility. Actual connected-account audio/vision
verification remains required for issue #20 and is never inferred from fixtures.
