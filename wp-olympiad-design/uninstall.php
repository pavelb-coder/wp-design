<?php
// Удаление данных плагина при деинсталляции из админки.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('wpd_settings');
delete_post_meta_by_key('_wpd_enabled');
delete_post_meta_by_key('_wpd_page');
