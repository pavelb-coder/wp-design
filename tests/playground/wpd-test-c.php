<?php
// Фаза C: деинсталляция и проверка источника уведомления deprecated.
ini_set('display_errors', '1');
error_reporting(E_ALL);
require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
header('Content-Type: text/plain; charset=utf-8');

$results = [];
$fails = 0;
function check($n, $c, $d = '') {
    global $results, $fails;
    $results[] = ($c ? 'PASS ' : 'FAIL ') . $n . ($c || !$d ? '' : " :: $d");
    if (!$c) { $fails++; }
}

// Уведомление о deprecated: проверяем, что оно не из кода плагина.
error_clear_last();
apply_filters('the_content', '<p>plain</p>');
$e = error_get_last();
check('core the_content: no deprecation from plugin code', !$e || strpos($e['file'], 'olympiad') === false, $e ? $e['file'] : '');
echo 'core the_content last error: ' . ($e ? $e['message'] . ' @ ' . basename($e['file']) : 'none') . "\n";

// Деинсталляция
update_option('wpd_settings', ['primary' => '#123456']);
$pid = wp_insert_post(['post_title' => 'Для удаления', 'post_status' => 'publish', 'post_type' => 'page']);
update_post_meta($pid, '_wpd_enabled', 1);
update_post_meta($pid, '_wpd_page', ['order' => ['faq']]);
check('pre: option exists', get_option('wpd_settings') !== false);
check('pre: meta exists', (int) get_post_meta($pid, '_wpd_enabled', true) === 1);

define('WP_UNINSTALL_PLUGIN', true);
include WP_PLUGIN_DIR . '/wp-olympiad-design/uninstall.php';
wp_cache_flush();
check('uninstall: option deleted', get_option('wpd_settings', 'GONE') === 'GONE');
check('uninstall: meta deleted', get_post_meta($pid, '_wpd_enabled', true) === '');
check('uninstall: page panel meta deleted', get_post_meta($pid, '_wpd_page', true) === '');

echo implode("\n", $results) . "\n";
echo "\nTOTAL: " . count($results) . ", FAILED: $fails\n";
