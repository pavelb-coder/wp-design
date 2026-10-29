<?php
// Помощник фазы Y (2.11.0): ?setup=1 — страница /olimpiada-y/ с блоками «Олимпиада: …» (размеры текстов, прозрачность фона,
// фон раздела) и страница /olimpiada-y-panel/ с панелью «Блоки олимпиады» (фон блока статистики с прозрачностью);
// ?cleanup=1 — удалить обе страницы. Выводит JSON.
require __DIR__ . '/wp-load.php';
header('Content-Type: application/json; charset=utf-8');
wp_set_current_user(1);

$pages = ['olimpiada-y', 'olimpiada-y-panel'];
if (isset($_GET['cleanup'])) {
    foreach ($pages as $slug) {
        $p = get_page_by_path($slug, OBJECT, 'page');
        if ($p) { wp_delete_post($p->ID, true); }
    }
    echo wp_json_encode(['cleaned' => !get_page_by_path('olimpiada-y', OBJECT, 'page') && !get_page_by_path('olimpiada-y-panel', OBJECT, 'page')]);
    exit;
}
foreach ($pages as $slug) {
    $p = get_page_by_path($slug, OBJECT, 'page');
    if ($p) { wp_delete_post($p->ID, true); }
}
$blocks = implode("\n\n", [
    '<!-- wp:wpd/hero ' . wp_json_encode(['title' => 'Главный Y', 'bgTransparency' => 40, 'titlePx' => 52, 'leadSize' => 21]) . ' /-->',
    '<!-- wp:wpd/stats ' . wp_json_encode(['title' => 'Цифры Y', 'items' => [['num' => '10', 'label' => 'школ']], 'headingSize' => 38, 'bgTransparency' => 50, 'sectionBg' => '#0b1220', 'sectionBgTr' => 20]) . ' /-->',
    '<!-- wp:wpd/faq ' . wp_json_encode(['title' => 'Вопросы Y', 'items' => [['q' => 'Вопрос Y', 'a' => 'Ответ Y']], 'itemSize' => 22]) . ' /-->',
    '<!-- wp:wpd/cards ' . wp_json_encode(['title' => 'Карточки Y', 'items' => [['title' => 'Карта Y', 'text' => 'Текст карты', 'url' => 'https://example.com/c', 'icon' => '', 'color' => '', 'image' => '']],
        'itemSize' => 24, 'bgTransparency' => 60]) . ' /-->',
    '<!-- wp:wpd/buttons ' . wp_json_encode(['title' => 'Ссылки Y', 'items' => [['text' => 'Кнопка Y', 'desc' => 'Подпись Y', 'url' => 'https://example.com/b', 'icon' => 'book']],
        'headingSize' => 30, 'smallSize' => 15, 'bgTransparency' => 40]) . ' /-->',
    '<!-- wp:wpd/partners ' . wp_json_encode(['title' => 'Партнёры Y', 'items' => [['logo' => '', 'name' => 'Партнёр Y', 'url' => '', 'new_tab' => 0, 'hidden' => 0]],
        'cards' => true, 'smallSize' => 15, 'bgTransparency' => 50]) . ' /-->',
]);
$id = (int) wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Олимпиада Y', 'post_name' => 'olimpiada-y', 'post_content' => wp_slash($blocks)]);
// Содержимое общей статистики — блок панели без строк не выводится
$opt = get_option('wpd_settings');
$opt = is_array($opt) ? $opt : [];
$opt['stats'] = [['num' => '5', 'label' => 'школ Y']];
update_option('wpd_settings', $opt);
WPD_Settings::flush_cache();
$pid = (int) wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Олимпиада Y панель', 'post_name' => 'olimpiada-y-panel', 'post_content' => '<p>Y</p>']);
update_post_meta($pid, '_wpd_page', WPD_Page_Editor::sanitize(['enabled' => '1', 'order' => ['stats', 'faq'], 'show' => ['stats' => '1', 'faq' => '1'],
    'bstyle' => ['stats' => ['bg' => '#0b1220', 'bg_tr' => '60']]], $pid));
update_post_meta($pid, WPD_Renderer::META_KEY, 1);
echo wp_json_encode(['id' => $id, 'url' => get_permalink($id), 'pid' => $pid, 'purl' => get_permalink($pid)]);
