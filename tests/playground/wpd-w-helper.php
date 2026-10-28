<?php
// Помощник фазы W (2.9.0): ?setup=1 — страница /olimpiada-w/ (оформление включено, видимы главный блок, текст и логотипы
// партнёров) с блоками «Олимпиада: логотипы партнёров» (свои логотипы, лента) и «Олимпиада: статистика» (шрифты блока),
// пользователь-редактор wpdw_ed / Wpdw-pass-123 и общие настройки с логотипом главного блока и партнёрами.
// ?cleanup=1 — удалить страницу, редактора и загруженные тестом файлы (вложения с именем wpdw-*).
// Выводит JSON: id, url.
require __DIR__ . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
header('Content-Type: application/json; charset=utf-8');
wp_set_current_user(1);

$page = get_page_by_path('olimpiada-w', OBJECT, 'page');
$ed   = get_user_by('login', 'wpdw_ed');
if (isset($_GET['cleanup'])) {
    if ($page) { wp_delete_post($page->ID, true); }
    if ($ed) { wp_delete_user($ed->ID); }
    foreach (get_posts(['post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids']) as $id) {
        if (strpos(basename((string) get_attached_file($id)), 'wpdw-') === 0) { wp_delete_attachment($id, true); }
    }
    echo wp_json_encode(['cleaned' => !get_page_by_path('olimpiada-w', OBJECT, 'page') && !get_user_by('login', 'wpdw_ed')]);
    exit;
}
if ($page) { wp_delete_post($page->ID, true); }
if (!$ed) {
    wp_insert_user(['user_login' => 'wpdw_ed', 'user_pass' => 'Wpdw-pass-123', 'role' => 'editor', 'user_email' => 'wpdw_ed@example.com']);
}
$b = function (string $name, array $attrs = []): string {
    return '<!-- wp:wpd/' . $name . ($attrs ? ' ' . wp_json_encode($attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '') . " /-->\n\n";
};
$content = "<p>Текст W</p>\n\n"
    . $b('partners', ['title' => 'Лента W', 'layout' => 'strip', 'speed' => 'fast', 'items' => [
        ['logo' => home_url('/hero19.svg'), 'name' => 'Альфа W', 'url' => 'https://alpha.example/'],
        ['logo' => home_url('/hero19.svg'), 'name' => 'Бета W', 'url' => ''],
        ['logo' => home_url('/hero19.svg'), 'name' => 'Скрытый W', 'hidden' => 1],
    ]])
    . $b('stats', ['title' => 'Цифры W', 'fontText' => 'Georgia, serif', 'fontNumbers' => '"Wpd Test", monospace', 'items' => [['num' => '42', 'label' => 'ответ']]]);
$id = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'olimpiada-w', 'post_title' => 'Олимпиада W', 'post_content' => wp_slash($content)]);
update_post_meta($id, '_wpd_enabled', 1);
$order = array_keys(WPD_Page_Editor::blocks());
update_post_meta($id, '_wpd_page', WPD_Page_Editor::sanitize(['order' => $order, 'show' => ['hero' => 1, 'content' => 1, 'partners' => 1]], $id, false));
echo wp_json_encode(['id' => $id, 'url' => get_permalink($id)]);
