# Установка и обновление

Этот модуль показывает настройки, устанавливает обновления и возвращает предыдущие версии. Пользуйтесь им через `admin/`: открывать файлы этой папки отдельно не нужно. Для первой установки загрузите только корневой `pull.php` на хостинг.

The standalone installer and administration runtime contain the same engine; only the PULL_ROOT initialization differs. Keep both copies synchronized. Local configuration stays in the site root and survives updates.

## Feedback credentials and attachments

`FEEDBACK_GITHUB_TOKEN` is an optional server-only Issues credential. Alternatively the bundle contains `feedback-credential.enc` (AES-256-GCM); the owner imports the separate 32-byte `feedback.key` using the authenticated feedback page. The key and any locally encrypted credential remain under protected `admin/account/data/` and survive upgrades. Never commit the decryption key. `scripts/provision-feedback.php` accepts a replacement token through stdin and writes the ciphertext and separate key into a private directory outside the checkout.

The login button opens GitHub itself, returning to the configured repository issue composer. GitHub cookies cannot authorize API calls from this PHP server; the form therefore uses the support credential or existing operator key. `FEEDBACK_GITHUB_REPO` supplies an explicit destination for local installations. The credential must have Issues write access to that repository.

Screenshots are validated PNG/JPEG/WebP files with random 192-bit bearer URLs, expire after 30 days, and are disclosed before submission. They use no GitHub Contents permission. `FEEDBACK_PUBLIC_BASE_URL` may set the canonical HTTPS panel URL (for example `https://example.com/admin/`) behind a reverse proxy. Keep application data inaccessible directly in the web server. The image endpoint intentionally serves only valid, unexpired bearer URLs; it never serves arbitrary files. Old attachments are pruned on later uploads; total stored media is bounded.

Audio selections come from provider capability metadata and configured credentials. Refresh the GenRouter catalog under `?toolkit=1`; unknown or text-only models cannot receive uploaded audio. Technical contract: [installer](../../specs/pull-php.md).
