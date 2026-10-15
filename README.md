# NeuroSaaS template

A small PHP foundation for AI services: GitHub deployment, feedback and reusable AI connections. Inspired by `dansury/kp-atlant`, without its business-specific dependencies.

## Install

Requires PHP 8.1+, sessions, ZipArchive and outbound HTTPS. cURL is required for AI; deployment and feedback also support PHP HTTPS streams.

1. Upload `pull.php` to your service directory. Open it in a browser.
2. Enter `owner/repository`, branch and a GitHub fine-grained token. The creation button requests **Contents: Read**, **Issues: Read and write**, and **Pull requests: Read** when a preview is selected. Select your repository on GitHub. Issues must be enabled in repository settings.
3. Set a panel password. The panel can install code, send feedback and make paid AI test calls; without a password these actions are accessible to anyone with its URL.
4. Save, open the panel, and press **Install latest version**. For this template choose the repository root (`.`). This installs `lib/llm.php` and the rest of the project.
5. Set up AI connections in the panel. Keys are stored in `ai-config.php`, never returned to the browser. Empty secret fields keep existing keys; separate checkboxes remove them. Files are created with mode 0600 and excluded from git.

For a different project, include `lib/llm.php` in its deployment directory to use the AI controls. When deploying only a repository subdirectory, put the library there too. `pull.php` remains a single-file installer and is always preserved; replace it manually to upgrade the installer itself.

## Updates and rollback

Opening `pull.php` shows the panel without changing project files. Browser actions use CSRF-protected POST requests. The panel polls GitHub while it is open. Automatic update settings persist; restoring a historical version pauses automation until an explicit latest-version install. A filesystem lock prevents concurrent deployments. Preserve uploads, data folders, `.env` and any local service configuration in setup before enabling deletion.

For unattended operation schedule this command in hosting cron, for example once a minute (replace host and path):

```sh
curl --fail -s -H "X-Pull-Password: $PULL_PASSWORD" "https://YOUR_HOST/PATH/pull.php?check=1&plain=1"
```

Keep `PULL_PASSWORD` in the cron environment. The explicit cron endpoint checks for changed commits independently of the browser toggle. `?status=1` returns JSON; `?plain=1` remains the explicit plain-text deploy endpoint for existing integrations. `?history=1` opens rollback history. Protect cron endpoints with the panel password. Do not put passwords into URLs.

`pull.php`, `pull-config.php`, `pull-state.json`, `pull-deploy.lock` and `ai-config.php` are always preserved. Settings can also be edited on the server; deleting `pull-config.php` reopens setup. Set `GITHUB_TOKEN` on the server to override the saved GitHub key. `KRASKI_PULL_ALLOW_IPS` is a compatible optional comma-separated IP allowlist.

## Feedback in your service

The panel includes a feedback form. `pull.php?feedback=1` is its standalone page, suitable for an iframe in an authenticated same-origin service:

```html
<iframe src="pull.php?feedback=1" title="Обратная связь" style="width:100%;height:760px;border:0"></iframe>
```

It uses the panel's password protection. Service users need authorization to that panel; for a public service, implement a separate authenticated, rate-limited endpoint using the service's own users, rather than exposing the deployment password or disabling it. The GitHub token always stays on the server. No page URL, prompts, credentials or customer records are attached automatically. Feedback is limited to one attempt per browser session per minute, with title/body length limits. A permission failure includes instructions to grant Issues write. This is a basic administrator feedback feature, not a public anonymous reporting endpoint.

The panel also offers the requested copyable embedding prompt and a browser-persisted “do not show again” choice.

## Language from visitor IP

The interface defaults to Russian for RU/BY/KZ and English elsewhere. Configure your hosting's GeoIP module to supply `GEOIP_COUNTRY_CODE`, or install PHP GeoIP with its country database. Behind Cloudflare, `PULL_TRUST_CLOUDFLARE=1` enables `CF-IPCountry` **only when the origin is restricted to trusted Cloudflare traffic**. Arbitrary visitor headers are otherwise ignored. Without a country source the default is English. The remembered Русский / English switch always overrides detection. No visitor IP is sent to a third-party geolocation API.

## Use AI from application code

```php
require_once __DIR__ . '/lib/llm.php';
$config = require __DIR__ . '/ai-config.php';
$llm = new LLM($config);
$text = $llm->chatText('You are a helpful assistant.', 'Explain this service.');
$data = $llm->chatJson('Extract structured fields.', 'Name: Anna');
```

Providers: Yandex Foundation Models, GenRouter, OpenRouter. Enable only providers you want, specify a model identifier and choose their fallback order. Disabled/unconfigured providers are skipped; transient 429/5xx errors get one bounded retry. Invalid, empty or truncated responses fall through to the next provider. JSON responses are parsed strictly; incomplete JSON is never silently repaired.

Yandex needs an API key, folder ID and model. Choose native Foundation Models for YandexGPT or the OpenAI-compatible route for supported open models. Short slugs become `gpt://FOLDER/SLUG/latest`; full model URIs are also accepted. Catalogs are fetched on demand; availability depends on your account. If a catalog is unavailable, enter the exact model identifier from your provider console.

OpenRouter is disabled and collapsed initially: **it may not work from Russian hosting**. Each provider has an optional proxy; use a trusted proxy because it handles AI traffic. Test connections from the hosting server with the panel button. A test makes a small paid generation request; normal page views and catalog loading do not generate text.

## Checks

```sh
php -l pull.php
php -l lib/llm.php
php tests/run.php
python3 tests/http_test.py
```

Tests use isolated temporary directories, mocked network transports and fake keys. They do not create real GitHub issues or incur AI charges.

Consultation and improvements: [dansury.github.io](https://dansury.github.io) · [Telegram](https://t.me/dansury.vj).
