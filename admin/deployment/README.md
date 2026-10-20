# Установка и обновление

Этот модуль показывает настройки, устанавливает обновления и возвращает предыдущие версии. Пользуйтесь им через `admin/`: открывать файлы этой папки отдельно не нужно. Для первой установки загрузите только корневой `pull.php` на хостинг.

The standalone installer and administration runtime contain the same engine; only the PULL_ROOT initialization differs. Keep both copies synchronized. Local configuration stays in the site root and survives updates. Technical contract: [installer](../../specs/pull-php.md).
