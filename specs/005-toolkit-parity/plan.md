# Plan
PHP 8.1+, no Composer. Import an exact public canonical module revision with a reproducible sync script; exclude developer credentials. Canonical version 1.0.1 exposes ToolkitLLM plus a conditional legacy alias, allowing the template instance LLM to coexist.
Add admin/llm_connector/toolkit.php for initialization, one-time migration of enabled Yandex/OpenRouter configuration, diagnostic redaction and optional per-page update hooks. Keep GenRouter and the original LLM API intact. Mount canonical setup through authenticated pull.php?toolkit=1; preserve the host CSRF gate and inject it into every embedded form, rewriting module-local form actions back to the mounted route. Do not persist a bypass flag in the shared session.
Preserve module data and operator files in the deployer, including nested paths. Add admin/llm_connector/bootstrap.php and a compact example application showing Request a change and an update hook.
Constitution check: source stays canonical and auto-updateable, no private fork; reporting is opt-in and no bundled reporting credential is copied. No production AI/mail calls in tests.

