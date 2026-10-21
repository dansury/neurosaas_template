# Traceable tasks for issue 20

- [x] Token permissions and repository/branch discovery.
- [x] Local setup without a second installation password.
- [x] ZIP upload with preserved state and rollback.
- [x] Standalone pull.py and dependency/polling guidance.
- [x] Top-right draggable feedback with screenshots and voice.
- [x] GenRouter-first provider, vision/audio/catalog and key controls.
- [x] Optional secondary providers and OpenRouter-only proxy.
- [x] Remove embedding tip and slogan; default automatic GitHub updates.
- [x] PR summaries and accurate main-restoration guidance.
- [x] Optional SMTP and provider-specific application-password links.
- [x] Black interface, progress, PHP compatibility and requirement fallback.
- [x] README contact links and original attribution.
- [x] Offline suites and desktop/mobile verification.

Live-account limits: the unauthenticated GenRouter /models endpoint returns
Missing API key. Actual paid text/vision/audio requests were not made; defaults
are determined from the connected account catalog at runtime. Hosting PHP 7.4
is a compatibility target; CI verifies PHP 8.1 and 8.3 below 8.5.
