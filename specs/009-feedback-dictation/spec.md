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

## Verification
Offline fixtures cover punctuation, changed facts, empty output, exceptions,
non-Latin identifiers, email and URL preservation. Existing provider, HTTP and
browser suites verify compatibility. Actual connected-account audio/vision
verification remains required for issue #20 and is never inferred from fixtures.
