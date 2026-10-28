# Feedback usability

Sources: GENLA issues #12–14, 2026-10-28; shared feedback/provider requirements also apply to NeuroSaaS Template.

## Stories and requirements
- FR-001: An existing browser with stale or duplicate legacy cookies can sign in using the correct password. Installation-scoped session/auth cookies preserve authentication, CSRF and logout boundaries.
- FR-002: An administrator opens a compact, movable feedback dialog in the current page, without an iframe or a second page header. Screenshots have individual remove buttons, including pasted images. Submission remains explicit and authenticated.
- FR-003: Voice opens provider connection settings in a new tab when no audio provider is configured. After connection, a small expandable link exposes eligible voice models; microphone use stays explicit.
- FR-004: Confirmed submission uses neutral success wording without falsely calling a later idea the first. Generated issue bodies contain no service-feedback footer. GENLA omits GitHub login; the template retains it.
- FR-005: API keys precede model controls on the connection page. Yandex Folder ID is shown only when a key is saved or entered, including immediate input changes and explicit key removal.

## Acceptance
Offline HTTP/browser fixtures cover stale cookies, login/logout, CSRF, screenshots, empty/configured providers, repeated feedback success, and mobile/desktop dialog layout. No production, delivery, paid inference or real issue submission is part of testing.
