# Toolkit parity
The authenticated host may pass navigation markup to `Toolkit::settings($csrf, $navigation)`; the standalone module has no knowledge of host routes and keeps its own layout when no navigation is supplied.
The template includes its LLM connector under admin/llm_connector. Each module owns its runtime files; specifications live in specs/llm_connector. No origin manifest or developer reporting credential is shipped.

| Capability | Implementation | Verification |
| --- | --- | --- |
| Provider/model overrides, newer model and configured fallback chains | ToolkitLLM and model_catalog.php | upstream llm_chain.php |
| Yandex endpoint retry, JSON repair, vision and OCR | ToolkitLLM | upstream llm_json.php, tests/toolkit.php |
| PDF and DOCX extraction | Parser | tests/toolkit.php, upstream code imported verbatim |
| SMTP HTML/plain, attachments and throttling | Mailer | tests/toolkit.php MIME fixtures; network delivery is operator-tested |
| Persistent redacted diagnostic log | DiagLog | tests/toolkit.php |
| Cached live catalogs, built-in fallback and dropdowns | ModelCatalog, mounted setup.php | upstream model_catalog_yandex.php, HTTP tests |
| Page-view update checks, history and rollback | AutoPull via the explicit check endpoint, pull.php | existing HTTP tests, tests/toolkit.php |
| Opt-in sanitized reports, deduplication, capability keys, verified compatible module updates and rollback | Selfheal | upstream smoke (105 checks), update (27 checks) |
| Existing GenRouter/Yandex/OpenRouter instance API | admin/llm_connector/adapter.php | tests/run.php |
| Development requests in the service | index.php, admin/?feedback=1 | HTTP tests |

## Runtime
The template requires PHP 8.1+; the embedded module supports 7.4+ but does not lower the deployer's requirement. Enable curl, pdo_sqlite, zip, dom, mbstring and openssl. pdftotext is optional; absence falls back to OCR. Keep admin/llm_connector/data private; the shipped Apache/LiteSpeed .htaccess denies direct module HTTP access. For Nginx deny the modules directory in the virtual-host configuration; application includes do not require HTTP access to it.

## Use
Include admin/llm_connector/bootstrap.php before output on each ordinary application page. Call NeuroSaaS\\Toolkit::boot() before using ToolkitLLM (text, JSON, overrides, OCR), Parser, Mailer or DiagLog. The original new LLM($config) adapter is unchanged, including GenRouter. The advanced canonical module settings cover Yandex/OpenRouter; GenRouter stays in the original AI panel.

The advanced panel is admin/?toolkit=1, protected by the same password, IP allowlist and CSRF gate as deployment. It has its own consent question: reporting remains ask/local only until explicitly enabled. No email/AI test runs without its button. Model metadata checks and module update checks are read-only. Enable the separate page-view update checkbox for ordinary application pages; panel polling and cron still work.

Enabled legacy Yandex/OpenRouter credentials are migrated into module settings only once, without overwriting existing module settings. ai-config.php is retained unchanged. Secrets never appear in form values. Module data, .env, local reporting token and deployment state survive project updates and purge.

Computer-only module refresh is described in Обслуживание_с_компьютера/README.md.

Run php tests/run.php, php tests/toolkit.php, all admin/llm_connector/tests/*.php, python tests/http_test.py and python tests/toolkit_http.py. Tests do not send real mail, call billed AI or enable reporting.

