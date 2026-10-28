<?php
// Удаление данных плагина при деинсталляции из админки.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('wpd_settings');
delete_post_meta_by_key('_wpd_enabled');
delete_post_meta_by_key('_wpd_page');
delete_post_meta_by_key('_wpd_rev'); // отметка изменений панели в блочном редакторе (2.4.0)
delete_option('wpd_rest_stray'); // перехваченный посторонний вывод в ответах REST (2.6.0)

// Регистрация участников (2.0.0–2.1.0, убрана в 2.2.0): если плагин удаляют до завершения очистки,
// оставшиеся заявки, аккаунты участников, роль, настройки и временные данные удаляются здесь целиком.
require_once __DIR__ . '/includes/class-wpd-legacy-cleanup.php';
WPD_Legacy_Cleanup::run(0);
delete_option(WPD_Legacy_Cleanup::DONE_OPTION);
delete_option(WPD_Legacy_Cleanup::PAGES_OPTION);
delete_transient(WPD_Legacy_Cleanup::LOCK);
