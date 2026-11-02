<?php
// Помощник фазы AA (2.13.0): ?setup=1 — страница /olimpiada-aa/ с блоками: статистика (500 px, по центру, без иконок),
// вопросы (1000 px, снизу, без значков) и вопросы по умолчанию, кнопки-ссылки (360 px, по центру, вторая кнопка без иконки),
// документы (вторая строка без значка), карточки (вторая без иконки и стрелки), таймер (300 px, по центру), логотипы
// партнёров (300 px, снизу), статистика по умолчанию и главный блок с иконкой кнопки; страница /olimpiada-aa-panel/ с панелью
// «Блоки олимпиады» (своя высота и иконки у группы «Статистика»). ?cleanup=1 — удалить обе страницы. Выводит JSON.
require __DIR__ . '/wp-load.php';
header('Content-Type: application/json; charset=utf-8');
wp_set_current_user(1);

$pages = ['olimpiada-aa', 'olimpiada-aa-panel'];
foreach ($pages as $slug) {
    $p = get_page_by_path($slug, OBJECT, 'page');
    if ($p) { wp_delete_post($p->ID, true); }
}
if (isset($_GET['cleanup'])) {
    echo wp_json_encode(['cleaned' => !get_page_by_path('olimpiada-aa', OBJECT, 'page') && !get_page_by_path('olimpiada-aa-panel', OBJECT, 'page')]);
    exit;
}
$tz   = WPD_Settings::countdown_timezone();
$date = (new DateTime('+3 days', $tz))->format('Y-m-d H:i');
$st   = [['num' => '120', 'label' => 'школ', 'icon' => 'welcome-learn-more'], ['num' => '7', 'label' => 'городов', 'icon' => 'groups']];
$faq  = [['q' => 'Когда начало?', 'a' => 'Скоро.'], ['q' => 'Сколько туров?', 'a' => 'Два.']];
$b = function (string $name, array $a) { return '<!-- wp:wpd/' . $name . ' ' . wp_json_encode($a) . ' /-->'; };
$blocks = implode("\n\n", [
    $b('hero', ['title' => 'Главный AA', 'buttonText' => 'Старт AA', 'buttonUrl' => 'https://example.com/start', 'buttonIcon' => 'awards', 'className' => 'aa-hero']),
    $b('stats', ['items' => $st, 'blockMinHeight' => 500, 'blockVAlign' => 'center', 'showIcons' => false, 'className' => 'aa-stats']),
    $b('stats', ['items' => $st, 'className' => 'aa-stats-plain']),
    $b('faq', ['items' => $faq, 'title' => 'Вопросы AA', 'blockMinHeight' => 1000, 'blockVAlign' => 'bottom', 'showIcons' => false, 'className' => 'aa-faq']),
    $b('faq', ['items' => $faq, 'title' => 'Вопросы по умолчанию', 'className' => 'aa-faq-plain']),
    $b('buttons', ['title' => 'Кнопки AA', 'blockMinHeight' => 360, 'blockVAlign' => 'center', 'className' => 'aa-buttons', 'items' => [
        ['icon' => 'admin-links', 'text' => 'С иконкой', 'url' => 'https://example.com/1'],
        ['icon' => 'media-document', 'text' => 'Без иконки', 'url' => 'https://example.com/2', 'noicon' => 1]]]),
    $b('docs', ['title' => 'Документы AA', 'className' => 'aa-docs', 'items' => [
        ['title' => 'Положение', 'url' => 'https://example.com/p.pdf'], ['title' => 'Сайт', 'url' => 'https://example.com/s', 'noicon' => 1]]]),
    $b('cards', ['title' => 'Карточки AA', 'className' => 'aa-cards', 'items' => [
        ['title' => 'К1', 'url' => 'https://example.com/k1', 'icon' => 'star-filled'], ['title' => 'К2', 'url' => 'https://example.com/k2', 'icon' => 'star-filled', 'noicon' => 1]]]),
    $b('countdown', ['date' => $date, 'linkText' => 'Задания', 'linkUrl' => 'https://example.com/t', 'blockMinHeight' => 300, 'blockVAlign' => 'center', 'className' => 'aa-cd']),
    $b('partners', ['title' => 'Партнёры AA', 'blockMinHeight' => 300, 'blockVAlign' => 'bottom', 'className' => 'aa-partners', 'items' => [
        ['name' => 'Партнёр 1', 'logo' => '', 'url' => ''], ['name' => 'Партнёр 2', 'logo' => '', 'url' => '']]]),
]);
$id = (int) wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Олимпиада AA', 'post_name' => 'olimpiada-aa', 'post_content' => wp_slash($blocks)]);

$pid = (int) wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Олимпиада AA панель', 'post_name' => 'olimpiada-aa-panel', 'post_content' => '<p>AA</p>']);
$opt = get_option('wpd_settings');
$opt = is_array($opt) ? $opt : [];
$opt['stats'] = $st;
update_option('wpd_settings', $opt);
WPD_Settings::flush_cache();
update_post_meta($pid, '_wpd_page', WPD_Page_Editor::sanitize(['enabled' => '1', 'order' => ['stats'], 'show' => ['stats' => '1'], 'ovg' => ['stats' => '1'],
    'ov' => ['stats_min_h' => '640', 'stats_valign' => 'bottom', 'stats_show_icons' => '']], $pid));
update_post_meta($pid, WPD_Renderer::META_KEY, 1);

echo wp_json_encode(['id' => $id, 'url' => get_permalink($id), 'pid' => $pid, 'purl' => get_permalink($pid)]);
