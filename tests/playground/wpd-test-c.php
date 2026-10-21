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
// Регистрация (2.0.0): настройки, счётчик, заявки, роль участника
update_option('wpd_registration', ['title' => 'X']);
update_option('wpd_reg_counter', 7);
$app = wp_insert_post(['post_title' => 'Заявка', 'post_status' => 'publish', 'post_type' => 'wpd_application']);
update_post_meta($app, '_wpd_number', 'OL-0007');
$puid = wp_insert_user(['user_login' => 'c_participant', 'user_email' => 'c_participant@c-test.ru', 'user_pass' => wp_generate_password(), 'role' => 'wpd_participant']);
check('pre: application and participant exist', $app > 0 && !is_wp_error($puid) && get_role('wpd_participant') !== null);

define('WP_UNINSTALL_PLUGIN', true);
global $wpdb;
include WP_PLUGIN_DIR . '/wp-olympiad-design/uninstall.php';
wp_cache_flush();
check('uninstall: option deleted', get_option('wpd_settings', 'GONE') === 'GONE');
check('uninstall: meta deleted', get_post_meta($pid, '_wpd_enabled', true) === '');
check('uninstall: page panel meta deleted', get_post_meta($pid, '_wpd_page', true) === '');
check('uninstall: registration options deleted', get_option('wpd_registration', 'GONE') === 'GONE' && get_option('wpd_reg_counter', 'GONE') === 'GONE');
check('uninstall: applications deleted', get_post($app) === null && (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'wpd_application'") === 0);
check('uninstall: application meta deleted', (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_wpd_number'") === 0);
$pu = get_userdata($puid);
check('uninstall: participant kept as subscriber', $pu && in_array('subscriber', $pu->roles, true) && !in_array('wpd_participant', $pu->roles, true), $pu ? implode(',', $pu->roles) : 'no user');
check('uninstall: role removed', get_role('wpd_participant') === null);
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user($puid);
wp_delete_post($pid, true);

echo implode("\n", $results) . "\n";
echo "\nTOTAL: " . count($results) . ", FAILED: $fails\n";
