<?php
// Помощник фазы N (2.0.0) для HTTP-проверок регистрации. Выводит JSON.
//  ?setup=1             — mu-плагин, который перехватывает письма (pre_wp_mail → опция wpd_test_mails), страницы формы и кабинета, сброс настроек
//  ?opt=<json>          — записать настройки регистрации (страницы из setup подставляются сами)
//  ?mails=1[&clear=1]   — перехваченные письма (и очистка)
//  ?apps=1              — заявки: id, номер, статус, e-mail, пользователь, поля
//  ?user=<email>        — пользователь: id, логин, роли
//  ?setpass=<email>     — задать пароль «Passw0rd-N» (для проверки входа участника)
//  ?optget=1           — сохранённая опция регистрации и e-mail администратора
//  ?rl_clear=1          — сбросить лимит заявок с адреса
//  ?cleanup=1           — удалить всё созданное фазой
require '/wordpress/wp-load.php';
header('Content-Type: application/json; charset=utf-8');

$mu_dir = WP_CONTENT_DIR . '/mu-plugins';
$mu = $mu_dir . '/wpd-test-mail.php';
$out = [];

function n_pages(): array { return (array) get_option('wpd_test_n_pages', []); }
function n_rl_clear(): void {
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_wpd\\_reg\\_rl\\_%' OR option_name LIKE '\\_transient\\_timeout\\_wpd\\_reg\\_rl\\_%'");
    wp_cache_flush();
}
function n_set(array $opt): void {
    $p = n_pages();
    $opt += ['form_page' => (int) ($p['form'] ?? 0), 'cabinet_page' => (int) ($p['cabinet'] ?? 0)];
    global $wp_filter;
    $saved = $wp_filter['sanitize_option_wpd_registration'] ?? null;
    unset($wp_filter['sanitize_option_wpd_registration']);
    update_option('wpd_registration', $opt);
    if ($saved !== null) { $wp_filter['sanitize_option_wpd_registration'] = $saved; }
    WPD_Registration::flush_cache();
}

if (isset($_GET['setup'])) {
    if (!is_dir($mu_dir)) { mkdir($mu_dir, 0777, true); }
    file_put_contents($mu, "<?php\n// Тест фазы N: письма не отправляются, а записываются в опцию wpd_test_mails.\n"
        . "add_filter('pre_wp_mail', function (\$r, \$a) {\n"
        . "    \$log = (array) get_option('wpd_test_mails', []);\n"
        . "    \$log[] = ['to' => is_array(\$a['to']) ? implode(',', \$a['to']) : (string) \$a['to'], 'subject' => (string) \$a['subject'], 'message' => (string) \$a['message'], 'headers' => \$a['headers']];\n"
        . "    update_option('wpd_test_mails', \$log, false);\n"
        . "    return true;\n"
        . "}, 10, 2);\n");
    $p = n_pages();
    if (empty($p['form']) || !get_post($p['form'])) {
        $p['form'] = wp_insert_post(['post_title' => 'Регистрация N', 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '[wpd_registration]']);
    }
    if (empty($p['cabinet']) || !get_post($p['cabinet'])) {
        $p['cabinet'] = wp_insert_post(['post_title' => 'Кабинет N', 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '<!-- wp:wpd/cabinet /-->']);
    }
    update_option('wpd_test_n_pages', $p, false);
    update_option('wpd_test_mails', [], false);
    n_set([]);
    n_rl_clear();
    $out = ['form' => get_permalink($p['form']), 'cabinet' => get_permalink($p['cabinet']), 'form_id' => $p['form'], 'cabinet_id' => $p['cabinet']];
} elseif (isset($_GET['opt'])) {
    $opt = json_decode(wp_unslash($_GET['opt']), true);
    n_set(is_array($opt) ? $opt : []);
    $out = ['ok' => true, 'status' => WPD_Registration::status()];
} elseif (isset($_GET['mails'])) {
    $out = (array) get_option('wpd_test_mails', []);
    if (!empty($_GET['clear'])) { update_option('wpd_test_mails', [], false); }
} elseif (isset($_GET['apps'])) {
    foreach (get_posts(['post_type' => 'wpd_application', 'post_status' => 'any', 'numberposts' => -1, 'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => true]) as $a) {
        $out[] = ['id' => $a->ID, 'number' => get_post_meta($a->ID, '_wpd_number', true), 'status' => WPD_Registration::app_status($a),
                  'email' => get_post_meta($a->ID, '_wpd_email', true), 'user' => (int) get_post_meta($a->ID, '_wpd_user', true),
                  'title' => $a->post_title, 'values' => WPD_Registration::app_values($a)];
    }
} elseif (isset($_GET['user'])) {
    $u = get_user_by('email', wp_unslash($_GET['user']));
    $out = $u ? ['id' => $u->ID, 'login' => $u->user_login, 'roles' => array_values($u->roles), 'name' => $u->display_name] : null;
} elseif (isset($_GET['setpass'])) {
    $u = get_user_by('email', wp_unslash($_GET['setpass']));
    if ($u) { wp_set_password('Passw0rd-N', $u->ID); }
    $out = $u ? ['login' => $u->user_login] : null;
} elseif (isset($_GET['optget'])) {
    $out = ['opt' => get_option('wpd_registration'), 'admin_email' => get_option('admin_email')];
} elseif (isset($_GET['rl_clear'])) {
    n_rl_clear();
    $out = ['ok' => true];
} elseif (isset($_GET['cleanup'])) {
    foreach (get_posts(['post_type' => 'wpd_application', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'suppress_filters' => true]) as $id) {
        wp_delete_post($id, true);
    }
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach (get_users(['search' => '*@n-test.ru', 'search_columns' => ['user_email'], 'fields' => 'ID']) as $uid) {
        wp_delete_user((int) $uid);
    }
    foreach (n_pages() as $id) { wp_delete_post((int) $id, true); }
    foreach (['wpd_test_n_pages', 'wpd_test_mails', 'wpd_registration', 'wpd_reg_counter'] as $o) { delete_option($o); }
    n_rl_clear();
    if (file_exists($mu)) { unlink($mu); }
    $out = ['ok' => true];
}
echo wp_json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
