<?php
// Помощник фазы S (2.5.0): страница /olimpiada-s/ с включённым оформлением и блоками «Олимпиада: …» с иконками —
// кнопки-ссылки без размера и с размером 60, контакты, документы с размером 80, главный блок с размером 22.
// ?reset=1 — пересоздать страницу без настроек панели, ?cleanup=1 — удалить. Выводит JSON: id, адрес.
require __DIR__ . '/wp-load.php';
header('Content-Type: application/json; charset=utf-8');
wp_set_current_user(1);

$page = get_page_by_path('olimpiada-s', OBJECT, 'page');
if (isset($_GET['cleanup']) || ($page && isset($_GET['reset']))) {
    if ($page) {
        wp_delete_post($page->ID, true);
    }
    $page = null;
    if (isset($_GET['cleanup'])) {
        echo wp_json_encode(['cleaned' => !get_page_by_path('olimpiada-s', OBJECT, 'page')]);
        exit;
    }
}
if (!$page) {
    $items = '[{"text":"Положение","url":"https://example.com/a","icon":"media-document"},{"text":"Чат","url":"https://example.com/b","icon":"format-chat"}]';
    $docs = '[{"title":"Положение","url":"https://example.com/a.pdf"},{"title":"Сайт","url":"https://example.com/"}]';
    $content = "<!-- wp:wpd/hero {\"buttonIcon\":\"download\",\"buttonUrl\":\"https://example.com/\",\"buttonIconSize\":22} /-->\n\n"
        . "<!-- wp:wpd/buttons {\"items\":$items} /-->\n\n"
        . "<!-- wp:wpd/buttons {\"items\":$items,\"iconSize\":60} /-->\n\n"
        . "<!-- wp:wpd/docs {\"items\":$docs,\"iconSize\":80} /-->\n\n"
        . "<!-- wp:wpd/contacts {\"phone\":\"+7 391 000-00-00\",\"email\":\"s@example.com\"} /-->";
    $id = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Олимпиада — фаза S', 'post_name' => 'olimpiada-s',
        'post_content' => wp_slash($content)]);
    update_post_meta($id, '_wpd_enabled', 1);
    $order = array_keys(WPD_Page_Editor::blocks());
    // Только содержимое страницы: блоки панели скрыты, чтобы на странице были лишь блоки из текста
    WPD_Page_Editor::store($id, WPD_Page_Editor::sanitize(['order' => $order, 'show' => ['content' => 1]], $id));
} else {
    $id = $page->ID;
}
echo wp_json_encode(['id' => $id, 'url' => get_permalink($id)]);
