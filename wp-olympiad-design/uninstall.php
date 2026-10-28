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

// Сетевая папка (2.8.0): настройки подключения, индекс файлов, журнал, расписание и кеш файлов для ссылок.
// Скопированные в медиатеку файлы остаются (это обычные вложения), снимаются только служебные отметки.
foreach (['wpd_netfolder', 'wpd_nf_index', 'wpd_nf_log'] as $wpd_option) {
    delete_option($wpd_option);
}
delete_transient('wpd_nf_lock');
wp_clear_scheduled_hook('wpd_nf_sync');
delete_post_meta_by_key('_wpd_nf_src');
delete_post_meta_by_key('_wpd_nf_sig');
$wpd_up    = wp_upload_dir(null, false);
$wpd_cache = trailingslashit($wpd_up['basedir']) . 'wpd-nf-cache';
if (is_dir($wpd_cache)) {
    foreach ((array) scandir($wpd_cache) as $wpd_file) {
        if (is_string($wpd_file) && is_file($wpd_cache . '/' . $wpd_file)) {
            @unlink($wpd_cache . '/' . $wpd_file); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }
    }
    @rmdir($wpd_cache); // phpcs:ignore WordPress.PHP.NoSilencedErrors
}
