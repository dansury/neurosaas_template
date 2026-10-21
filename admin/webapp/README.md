# Web application module

This optional, reusable module ports installable PWA and RFC 8291/8292 Web Push
from kp-atlant. It contains no sales workflows, branding or manager records.
The welcome page offers installation; administration links to
`admin/?webapp=1` for explicit per-device notification consent and a test.

Requires HTTPS (localhost is supported for development), PHP 7.4+, OpenSSL with
ECDH, cURL and PDO SQLite. No Composer, Node.js or build step is required.
iOS notifications require an installed Home Screen application (iOS 16.4+).
Browsers and operating systems determine installation and delivery support.

## Integrating a generated service

```php
require_once __DIR__ . '/admin/webapp/WebApp.php';
// Inside <head>; pass '/service/' explicitly if your router has nested URLs.
\NeuroSaaS\WebApp::head();
// Optional installation control in your page body.
\NeuroSaaS\WebApp::installButton();
// From trusted server code, after the application's own event/commit succeeds:
$result = \NeuroSaaS\WebApp::notify('Готово', 'Задача обработана', './');
```

`notify` returns accepted/expired/failed counts and targets subscribed devices of
the current single administrator account. An optional fourth endpoint argument
limits delivery to one stored device. There is no public broadcast HTTP API.
Adding multi-user delivery requires integrating the host application's user
authorization and ownership model, not sharing these owner subscriptions.
`setName(string)` changes the manifest name; the default is neutral.

Keys and subscriptions live in `admin/account/data/webapp.sqlite`, already in the
installer's mandatory preservation list. Keep that directory private (Apache's
deny rule is created automatically; nginx must deny `/admin/account/data/`).
Back it up with other account data; rollback never replaces the signing keys.
Only trusted browser push-service HTTPS endpoints are accepted; redirects are
disabled. Subscription URLs and keys are not written to logs or public manifests.

The service worker caches only the public offline message, never user pages,
settings, messages, API results or code. Notifications and click URLs are plain
text/same-origin. Push-service acceptance is not device-delivery confirmation;
browser permission, OS settings and provider availability still apply.
