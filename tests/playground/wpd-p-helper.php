<?php
// Помощник фазы P (2.2.0). Выводит JSON. Сам очистку не запускает: до загрузки WordPress подменяет опцию
// «очистка завершена», поэтому порции выполняют только настоящие запросы сайта из phaseP.sh.
//  ?seed=<N>   — данные 2.1.0: роль участника, N заявок, 3 участника (пароль P-pass-2021), настройки, счётчик,
//                временные данные, страница «P2 форма» со старым шорткодом; отметка о завершении очистки снимается
//  ?state=1    — сколько осталось: заявки, участники, роль, настройки, отметка, список страниц
//  ?cleanup=1  — удалить страницу фазы и вернуть отметку
$GLOBALS['wp_filter']['pre_option_wpd_reg_removed'][10][] = ['function' => function () { return '2.2.0'; }, 'accepted_args' => 1];
require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
header('Content-Type: application/json; charset=utf-8');
global $wpdb;
remove_all_filters('pre_option_wpd_reg_removed');
$out = [];

if (isset($_GET['seed'])) {
    add_role('wpd_participant', 'Участник олимпиады', ['read' => true]);
    update_option('wpd_registration', ['title' => 'Регистрация']);
    update_option('wpd_reg_counter', (int) $_GET['seed'], false);
    set_transient('wpd_reg_done_ptoken', 1, HOUR_IN_SECONDS);
    wp_defer_term_counting(true);
    wp_suspend_cache_invalidation(true);
    for ($i = 0; $i < (int) $_GET['seed']; $i++) {
        $id = wp_insert_post(['post_title' => 'Заявка ' . $i, 'post_type' => 'wpd_application', 'post_status' => 'publish']);
        add_post_meta($id, '_wpd_number', sprintf('OL-%04d', $i + 1));
    }
    wp_suspend_cache_invalidation(false);
    wp_defer_term_counting(false);
    for ($i = 0; $i < 3; $i++) {
        wp_insert_user(['user_login' => 'p2_part' . $i, 'user_email' => "p2_part$i@p-test.ru", 'user_pass' => 'P-pass-2021', 'role' => 'wpd_participant']);
    }
    $pg = wp_insert_post(['post_title' => 'P2 форма', 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => "<p>Подайте заявку:</p>\n[wpd_registration]"]);
    update_option('wpd_test_p_page', $pg, false);
    delete_option('wpd_reg_removed');
    delete_option('wpd_reg_removed_pages');
    wp_cache_flush();
    $out = ['page' => get_permalink($pg), 'page_id' => $pg];
} elseif (isset($_GET['state'])) {
    wp_cache_flush();
    $out = [
        'apps'  => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'wpd_application'"),
        'users' => count(get_users(['role' => 'wpd_participant', 'fields' => 'ID'])) + count(array_filter([get_user_by('login', 'p2_part0'), get_user_by('login', 'p2_part1'), get_user_by('login', 'p2_part2')])),
        'role'  => get_role('wpd_participant') !== null,
        'opt'   => get_option('wpd_registration', false) !== false || get_option('wpd_reg_counter', false) !== false,
        'trans' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_wpd\\_reg\\_%'"),
        'done'  => (string) $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'wpd_reg_removed'"),
        'pages' => array_map('intval', (array) maybe_unserialize($wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'wpd_reg_removed_pages'"))),
    ];
} elseif (isset($_GET['cleanup'])) {
    $pg = (int) get_option('wpd_test_p_page');
    if ($pg) { wp_delete_post($pg, true); }
    delete_option('wpd_test_p_page');
    delete_option('wpd_reg_removed_pages');
    update_option('wpd_reg_removed', '2.2.0');
    $out = ['ok' => true];
}
echo wp_json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
