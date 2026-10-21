<?php
// Удаление данных плагина при деинсталляции из админки.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('wpd_settings');
delete_post_meta_by_key('_wpd_enabled');
delete_post_meta_by_key('_wpd_page');

// Регистрация участников (2.0.0): настройки, счётчик номеров и заявки удаляются.
delete_option('wpd_registration');
delete_option('wpd_reg_counter');
$wpd_apps = get_posts([
    'post_type'        => 'wpd_application',
    'post_status'      => ['publish', 'draft', 'pending', 'private', 'trash', 'future'],
    'numberposts'      => -1,
    'fields'           => 'ids',
    'suppress_filters' => true,
]);
foreach ($wpd_apps as $wpd_id) {
    wp_delete_post((int) $wpd_id, true);
}
// Аккаунты участников не удаляются: им назначается роль «Подписчик», роль «Участник олимпиады» удаляется.
foreach (get_users(['role' => 'wpd_participant', 'fields' => 'ID']) as $wpd_uid) {
    $wpd_user = new WP_User((int) $wpd_uid);
    $wpd_user->set_role('subscriber');
}
delete_metadata('user', 0, 'wpd_own_password', '', true); // 2.1.0: отметка «пароль задан при регистрации»
remove_role('wpd_participant');
