# Тесты плагина WP Olympiad Design

Тесты гоняются в [WordPress Playground](https://wordpress.github.io/wordpress-playground/)
(CLI `@wp-playground/cli`), без внешней БД и без установки PHP.

## Что проверяется

| Фаза | Файл | Что проверяет | Итог |
|---|---|---|---|
| A | `wpd-test-a.php` | синтаксис, регистрация шорткодов, кеш настроек, дефолты, санитизация (XSS, URL, цвета, радиус), парсеры, экранирование вывода, мета-бокс и `save_post` (nonce, права, ревизии) | 73 из 73 |
| B | `phaseB.sh` | HTTP: оформленная страница, обычная страница, миниатюра, `hide_title`, CSS (только там, где нужен), страница настроек (19 полей, 6 разделов) | 42 из 42 |
| C | `wpd-test-c.php` | деинсталляция (`uninstall.php`) | 5 из 5 |
| M | `wpd-measure.php` | замер: сколько раз читается опция настроек при рендере (см. ниже) | — |

Проверено на PHP 7.4.33 (blueprint с `preferredVersions.php = 7.4`) и на версии по умолчанию PHP 8.5.10. Версия подтверждается скриптом `PHP_VERSION`, а не заголовком `X-Powered-By`.

## Замеры оптимизации 1.1.0

| Показатель | 1.0.0 | 1.1.0 |
|---|---|---|
| Чтений `wpd_settings` при первом рендере `[wpd_page]` | 2 | 1 |
| Чтений при повторном рендере в том же запросе | 2 | 0 (кеш) |
| Чтений на четыре шорткода подряд | 6 | 0 (кеш) |
| Ссылка на `wpd.css` на обычной странице | 1 | 0 |
| Ссылка на `wpd.css` на оформленной странице и странице с шорткодом | 1 | 1 |

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
