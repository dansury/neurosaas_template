# Issue 20 requirement mapping

| Request | Implementation | Verification |
|---|---|---|
| Prefill token rights; place creation above fields | pat_url, repository_fields | Token rights assertion and rendered setup |
| Paste key, choose repository then branch; main default | repository_discovery and shared selector | Offline GitHub discovery browser scenario |
| No additional installation password | Session-bound bootstrap and verified account enrollment | Local enrollment and other-browser rejection |
| Upload ZIP alongside GitHub, with rollback | archive_deploy, private archive_store, shared history | Actual PHP upload and prior-install restoration |
| pull.py beside pull.php | Stdlib exact-commit CLI and dependency installation | Offline preservation, unsafe archive and saved-history checks |
| Explain missing Python | pull.cmd, pull.sh and README download links | Launcher/source inspection; Python CLI compile |
| Remove “Ваш сервис под контролем” | Dashboard title and old embedding UI removed | HTTP and browser assertions |
| Always-visible top-right ideas | Panel dialog and shared starter/toolkit widget | Desktop/mobile dialog open and movement |
| Screenshots, paste and movable handle | Multipart image validation, clipboard DataTransfer, pointer/keyboard handle | Browser controls and source review; no live attachment posted |
| Voice when a model is configured, including GenRouter | Capability-aware model picker and audio chat/transcription routes | Provider payload isolation; live audio remains unverified |
| GenRouter before Yandex; default GenRouter everywhere | Host adapter, canonical config, ENV example, toolkit migration and model group order | PHP defaults, chain/payload and rendered provider order |
| OpenRouter, then Yandex as optional alternatives | Secondary disclosures and ordered settings | Desktop/mobile field assertions |
| Remove proxies except OpenRouter | Field visibility and adapter proxy selection | Rendered field assertions |
| Remove feedback embedding-tip screen | Obsolete tip markup and handlers removed | HTTP/browser assertions |
| New GitHub automatic updates enabled, disable available | Setup/default connection configuration; existing saved values retained | HTTP connection/preference checks |
| Explain versions with latest associated PRs | Commit-associated PR summaries in version history | Resolver/source inspection; failures preserve SHA/label |
| Restore chosen version into main accurately | Restoration prompt asks for a new commit/PR and preserves history | History text inspection |
| User-token author for issues and screenshots | github_create_feedback and feedback_github use the same user token | Offline feedback HTTP tests; no real issue sent |
| Small cache-reset cross beside refresh | catalog-reset labelled button | Markup and style inspection |
| GenRouter text, vision and audio defaults | Only connected-account catalog capabilities select defaults | Qualified GenRouter payload tests; live model IDs require account access |
| GenRouter first in advanced API keys | Whitelisted secret, masked key field and shared adapter sync | Config/markup and host coexistence suites |
| SMTP explanation and host-specific application-password links | Optional mail fields; Yandex/Mail.ru/Gmail help | Rendered/source inspection; no email sent |
| Black accent | Starter, installer, panel and toolkit palettes | Desktop/mobile captures |
| Visible deployment progress/result | Streaming progress and persistent DONE/ERROR result | Actual ZIP-deploy browser scenario |
| PHP below 8.5 in both repositories | Parse-safe bootstrap around PHP 7.4+ engines | PHP 8.3 local suites and PHP 8.1/8.3 CI |
| Minimum hosting fallback and detected versions | Bootstrap plus extension/write checks | Lint, runtime loading and requirements markup |
| README owner links at beginning | Requested Telegram and portfolio links; original attribution retained | Source inspection |

Local verification: PHP lint; tests/run_all.sh; tests/operator_workflows.php;
tests/pull_python.py; tests/browser_operator_workflows.cjs at 1280 and 390 pixels.
Browser fixtures mock GitHub discovery and never send an external issue, email,
screenshot or paid model call. ZIP deployment and rollback use a disposable real
PHP server. Live GenRouter /models replied Missing API key without credentials;
actual available text/vision/audio model IDs are deliberately not invented.
