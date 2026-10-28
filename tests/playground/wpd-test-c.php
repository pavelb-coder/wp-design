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
update_option('wpd_rest_stray', ['time' => time(), 'route' => '', 'text' => 'x', 'count' => 1], false); // 2.6.0
check('pre: option exists', get_option('wpd_settings') !== false);
check('pre: meta exists', (int) get_post_meta($pid, '_wpd_enabled', true) === 1);
// Данные бывшей регистрации (2.0.0–2.1.0), если плагин удаляют до завершения очистки 2.2.0
add_role('wpd_participant', 'Участник олимпиады', ['read' => true]);
update_option('wpd_registration', ['title' => 'X']);
update_option('wpd_reg_counter', 7);
set_transient('wpd_reg_rl_c0ffee', 2, HOUR_IN_SECONDS);
delete_option('wpd_reg_removed');
$app = wp_insert_post(['post_title' => 'Заявка', 'post_status' => 'publish', 'post_type' => 'wpd_application']);
update_post_meta($app, '_wpd_number', 'OL-0007');
$puid = wp_insert_user(['user_login' => 'c_participant', 'user_email' => 'c_participant@c-test.ru', 'user_pass' => wp_generate_password(), 'role' => 'wpd_participant', 'meta_input' => ['wpd_own_password' => 1]]);
check('pre: legacy application and participant exist', $app > 0 && !is_wp_error($puid) && get_role('wpd_participant') !== null);

define('WP_UNINSTALL_PLUGIN', true);
global $wpdb;
// Бывшая сетевая папка (2.8.0, убрана в 2.10.0): если очистка не закончилась, uninstall удаляет всё — настройки, индекс,
// журнал, расписание, скопированные файлы медиатеки, кеш файлов для ссылок — и отметки самой очистки
update_option('wpd_netfolder', ['enabled' => 1, 'schedule' => 'hourly']);
update_option('wpd_nf_index', ['x' => ['rel' => 'a.pdf']], false);
update_option('wpd_nf_log', [['time' => time(), 'text' => 'x']], false);
wp_schedule_event(time() + 3600, 'hourly', 'wpd_nf_sync');
$nf_att = (int) wp_insert_attachment(['post_title' => 'nf', 'post_mime_type' => 'application/pdf', 'post_status' => 'inherit'], false);
update_post_meta($nf_att, '_wpd_nf_src', 'a.pdf');
update_post_meta($nf_att, '_wpd_nf_sig', '1|2');
$nf_cache = wp_upload_dir(null, false)['basedir'] . '/wpd-nf-cache';
wp_mkdir_p($nf_cache);
file_put_contents($nf_cache . '/0123456789abcdef01234567.pdf', 'x');
file_put_contents($nf_cache . '/.htaccess', 'Deny from all');
update_option('wpd_nf_removed_state', ['posts' => 0]);
update_option('wpd_nf_removed_notice', 1);
include WP_PLUGIN_DIR . '/wp-olympiad-design/uninstall.php';
wp_cache_flush();
check('uninstall: option deleted', get_option('wpd_settings', 'GONE') === 'GONE');
check('uninstall: former netfolder options deleted', get_option('wpd_netfolder', 'GONE') === 'GONE' && get_option('wpd_nf_index', 'GONE') === 'GONE' && get_option('wpd_nf_log', 'GONE') === 'GONE');
check('uninstall: netfolder schedule cleared', wp_next_scheduled('wpd_nf_sync') === false);
check('uninstall: copied attachment deleted (2.10.0)', get_post($nf_att) === null);
check('uninstall: cleanup marks deleted (2.10.0)', get_option('wpd_nf_removed', 'GONE') === 'GONE' && get_option('wpd_nf_removed_state', 'GONE') === 'GONE' && get_option('wpd_nf_removed_notice', 'GONE') === 'GONE');
check('uninstall: link cache removed', !is_dir($nf_cache));
check('uninstall: meta deleted', get_post_meta($pid, '_wpd_enabled', true) === '');
check('uninstall: REST guard option deleted (2.6.0)', get_option('wpd_rest_stray', 'GONE') === 'GONE');
check('uninstall: page panel meta deleted', get_post_meta($pid, '_wpd_page', true) === '');
check('uninstall: legacy registration options deleted', get_option('wpd_registration', 'GONE') === 'GONE' && get_option('wpd_reg_counter', 'GONE') === 'GONE' && get_transient('wpd_reg_rl_c0ffee') === false);
check('uninstall: legacy applications and meta deleted', get_post($app) === null && (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_wpd_number'") === 0);
check('uninstall: legacy participant account deleted', get_userdata($puid) === false);
check('uninstall: legacy role removed', get_role('wpd_participant') === null);
check('uninstall: cleanup options removed', get_option('wpd_reg_removed', 'GONE') === 'GONE' && get_option('wpd_reg_removed_pages', 'GONE') === 'GONE');

echo implode("\n", $results) . "\n";
echo "\nTOTAL: " . count($results) . ", FAILED: $fails\n";
