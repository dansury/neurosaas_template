# Подключение нейросетей, документов и почты

Этот модуль даёт сервису доступ к нейросетям, чтению документов и изображений, отправке писем и журналу ошибок. Откройте «Админка» → «Модели, документы и почта», укажите ключи и сохраните настройки. Открывать файлы этой папки в браузере не нужно. Отчёты об ошибках остаются на вашем сервере, пока вы сами не разрешите отправку.

Runtime files are kept together in this directory. Include bootstrap.php before application output. The instance-based LLM in adapter.php retains GenRouter compatibility; ToolkitLLM provides the extended API. Local data, .env and selfheal/token.local.php survive deployment. Technical contracts and signatures: [module index](../../specs/llm_connector/index.md). Run tests via the repository tests/run_all.sh.
