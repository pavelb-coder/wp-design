<?php
// Помощник фазы R (2.4.0): страница /olimpiada-r/ с включённым оформлением и блоками «Олимпиада: …» в тексте.
// ?reset=1 — пересоздать страницу без настроек панели, ?cleanup=1 — удалить страницу и страницу-источник.
// Выводит JSON: id, адрес, id страницы-источника для копирования.
require __DIR__ . '/wp-load.php';
header('Content-Type: application/json; charset=utf-8');
wp_set_current_user(1);

$find = function (string $slug) {
    return get_page_by_path($slug, OBJECT, 'page');
};

if (isset($_GET['cleanup'])) {
    foreach (['olimpiada-r', 'olimpiada-r-src'] as $slug) {
        $old = $find($slug);
        if ($old) {
            wp_delete_post($old->ID, true);
        }
    }
    echo wp_json_encode(['cleaned' => !$find('olimpiada-r') && !$find('olimpiada-r-src')]);
    exit;
}

$page = $find('olimpiada-r');
if ($page && isset($_GET['reset'])) {
    wp_delete_post($page->ID, true);
    $page = null;
}
if (!$page) {
    $id = wp_insert_post([
        'post_type'    => 'page',
        'post_status'  => 'publish',
        'post_title'   => 'Олимпиада — фаза R',
        'post_name'    => 'olimpiada-r',
        'post_content' => "<!-- wp:paragraph -->\n<p>Текст страницы фазы R.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:wpd/stats /-->\n\n<!-- wp:wpd/contacts /-->",
    ]);
    update_post_meta($id, '_wpd_enabled', 1);
} else {
    $id = $page->ID;
}

// Страница-источник для «Скопировать с другой страницы»: своя палитра «Вишня» и скрытый на телефоне FAQ
$src = $find('olimpiada-r-src');
if (!$src) {
    $src_id = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Источник R', 'post_name' => 'olimpiada-r-src', 'post_content' => '<p>Источник</p>']);
    update_post_meta($src_id, '_wpd_enabled', 1);
    $in = WPD_Page_Editor::meta_to_input([]);
    $in['ovg'] = ['page' => 1];
    foreach (WPD_Page_Editor::group_keys('page') as $key) {
        $in['ov'][$key] = WPD_Settings::get_global()[$key];
    }
    $in['ov']['palette'] = 'cherry';
    $in['bstyle'] = ['faq' => ['hide' => 'mobile']];
    $in['hero']['title'] = 'Заголовок источника';
    WPD_Page_Editor::store($src_id, WPD_Page_Editor::sanitize($in, $src_id, false));
} else {
    $src_id = $src->ID;
}

echo wp_json_encode(['id' => $id, 'url' => get_permalink($id), 'src' => $src_id]);
