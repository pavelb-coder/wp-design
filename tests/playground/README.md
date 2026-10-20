# Тесты плагина WP Olympiad Design

Тесты гоняются в [WordPress Playground](https://wordpress.github.io/wordpress-playground/)
(CLI `@wp-playground/cli`), без внешней БД и без установки PHP.

## Что проверяется

| Фаза | Файл | Что проверяет | Итог |
|---|---|---|---|
| A | `wpd-test-a.php` | синтаксис, регистрация шорткодов, дефолты, санитизация (XSS, URL, цвета, радиус), парсеры, экранирование вывода, мета-бокс и `save_post` (nonce, права, ревизии) | 68 из 68 |
| B | `phaseB.sh` | HTTP: оформленная страница, обычная страница, миниатюра, `hide_title`, CSS, страница настроек (19 полей, 6 разделов) | 39 из 39 |
| C | `wpd-test-c.php` | деинсталляция (`uninstall.php`) | 5 из 5 |

Проверено на PHP 7.4.33 и 8.5.10 (версии, которые реально отдал Playground).

## Запуск

1. Скопируйте `wp-olympiad-design` в папку, которую монтирует Playground (`/wordpress/wp-content/plugins/`).
2. Поднимите сервер WordPress:
   ```bash
   npx @wp-playground/cli@3.1.59 server --auto-mount=<папка_с_WordPress> \
     --blueprint=tests/playground/blueprint.json --blueprint-may-read-adjacent-files --port=9400
   ```
   `blueprint.json` задаёт `preferredVersions.php` (например, `7.4`) и активирует плагин.
3. Скопируйте `wpd-test-a.php`, `wpd-test-c.php`, `wpd-set.php` в корень WordPress и выполните:
   ```bash
   curl -sL http://127.0.0.1:9400/wpd-test-a.php > resA.txt   # фаза A, выводит строку IDS
   WPD_BASE=http://127.0.0.1:9400 bash phaseB.sh               # фаза B, читает resA.txt из текущей папки
   curl -sL http://127.0.0.1:9400/wpd-test-c.php               # фаза C, последней: удаляет данные плагина
   ```

Важно: Playground хранит базу SQLite в `<папка_с_WordPress>/wp-content/database/`.
Чтобы начать с чистой базы, остановите сервер и удалите файлы `.ht.*` в этой папке.
Фаза C удаляет настройки и мета-поля плагина, поэтому запускайте её последней.

`wpd-set.php` — служебный хелпер: меняет опцию плагина по GET-параметру `json`. Только для песочницы, в продакшен не копируйте.
