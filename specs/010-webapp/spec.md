# Installable web application and notifications

## User story
The service can be installed on a phone or desktop and receive explicitly enabled
browser notifications. The reusable module ports the generic PWA/Web Push
capabilities of kp-atlant without its branding, sales events or manager database.

## Requirements
- FR-001: Provide a manifest, neutral icons, installation controls and a scoped
  service worker. Work at the domain root and within a hosting subdirectory.
- FR-002: Offer browser-dependent installation guidance and an honest offline
  screen. Never cache authenticated pages, settings, API responses or messages.
- FR-003: Ask for notification permission only after a deliberate button click.
  Support subscribe, unsubscribe, denied/unsupported states and an explicit
  test for the current device in authenticated administration.
- FR-004: Protect every subscription mutation with existing admin authentication
  and CSRF. Do not expose a public notification-sending endpoint.
- FR-005: Preserve private VAPID keys and subscriptions across archive/GitHub
  deployment and rollback. Reject unsafe push endpoints and redirect-based SSRF.
- FR-006: Expose a documented server API for application events. Encrypt payloads
  with RFC 8291 / VAPID RFC 8292 using shared-hosting PHP extensions, without
  Composer or frontend build requirements. Remove expired subscriptions (404/410)
  and distinguish accepted, failed and absent delivery; acceptance is not proof
  of device delivery.
- FR-007: Notification clicks open only same-origin service URLs. Keep the
  application's existing credentials, settings and consent intact.

## Success criteria
PHP fixtures verify VAPID/encryption, endpoint validation, persistence and expired
subscriptions. HTTP fixtures prove authentication and CSRF, including subfolder
mounts. Desktop/mobile browser fixtures cover install, subscribe/unsubscribe,
denied permission, unsupported browsers and safe service-worker behavior using
local browser API fixtures. No real push or permission grant occurs during tests.
