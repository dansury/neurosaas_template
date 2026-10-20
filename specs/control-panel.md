# Control panel and AI connections — issue #1

## Scope
A single-file, uploadable pull.php remains the installation entry point. Keep branch/PR tracking, exact-commit downloads, preserved files, history and rollback. Replace the terminal interface with an accessible bilingual control panel. GET opens the panel; deployments and rollback from the UI require POST and CSRF. Existing explicit cron check/plain endpoints remain available. Serialize deploys with a filesystem lock.

## Feedback
Authenticated panel and standalone ?feedback=1 form submit title and description to the configured repository through GitHub Issues. Token remains on the server. Require CSRF, length validation and a session cooldown; show actionable permission errors. Token creation links request Contents read, Issues write and optional Pull requests read. Do not include credentials or automatic page URLs in reports.

Show the requested English embedding prompt with copy and persistent browser dismissal. Keep functional development-request navigation and the owner's consultation links to https://dansury.github.io and https://t.me/dansury.vj.

## Neutral presentation

The management shell uses generic service labels, a white background and explicit light color scheme. Functional feedback navigation and the owner's personal consultation links stay available. Provider names identify their configured integrations. Language selection follows the Russian-first onboarding contract in `pull-panel-russian-onboarding.md`.

## Language

Default to Russian on first use and remember the explicit language switch. Follow `pull-panel-russian-onboarding.md` for the current onboarding contract. Do not send visitor IPs to external geolocation services.

## Automatic updates
Save enable/disable and interval in config. While the panel is open, poll status and deploy changed commits. Rollback pauses automatic updates until an explicit deployment. Offer a cron command for operation with the browser closed. Preserve configs, state and locks during deployment. Failed checks do not trigger deployments.

## AI connections
Reusable dependency-free admin/llm_connector/adapter.php provides the host-facing provider API without application database, logging, mail or commercial-workflow dependencies. Support Yandex Foundation Models (native and OpenAI-compatible routes), GenRouter and OpenRouter, ordered fallback, bounded transient retries, text and strict JSON responses, model catalogs, connection tests and per-provider proxy selection. The native YandexGPT route is separate from the OpenAI-compatible route for open models. The latter has a configurable base URL and defaults to `https://ai.api.cloud.yandex.net/v1`; its models use a URI such as `gpt://<folder_ID>/qwen3-235b-a22b-fp8/latest`. UI saves secrets server-side, never echoes them; blank secret fields preserve saved keys. OpenRouter is collapsed and disabled initially, with the requested Russian-hosting warning. No live test or billing call without pressing its test button. Local configs are ignored by git and preserved by deployment.

## Advanced infrastructure and development requests
The optional canonical module is mounted at `?toolkit=1` after the normal password, IP and CSRF gates. A password is mandatory for this route even if the deployment panel was configured without one. It adds the complete infrastructure defined in `005-toolkit-parity/spec.md`; the original instance LLM remains backward compatible. Feedback actions are labelled `Заказать доработку` / `Request a change` and retain the configured repository, validation and server-side token behavior.

## Acceptance
PHP lint; isolated HTTP tests of setup, authentication, CSRF, locale, feedback validation and mocked GitHub requests; mocked AI route/payload/fallback/JSON tests; syntax check UI JavaScript. No real issues or paid AI calls during automated tests.
