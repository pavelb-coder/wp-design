<?php
// Помощник фазы Z (2.12.0): ?setup=1 — страница /olimpiada-z/ с тремя блоками «Олимпиада: таймер» (этап идёт: свой вид
// кнопки, иконка слева, подпись; до начала: кнопка «до начала», вид «ссылка»; после окончания: кнопка «после окончания»
// на всю ширину) и главным блоком с таймером и своей кнопкой; страница /olimpiada-z-panel/ с панелью «Блоки олимпиады»
// (свои параметры кнопки в группе «Таймер и эффекты»). Даты таймера в общих настройках — для главного блока.
// ?cleanup=1 — удалить обе страницы. Выводит JSON.
require __DIR__ . '/wp-load.php';
header('Content-Type: application/json; charset=utf-8');
wp_set_current_user(1);

$pages = ['olimpiada-z', 'olimpiada-z-panel'];
if (isset($_GET['cleanup'])) {
    foreach ($pages as $slug) {
        $p = get_page_by_path($slug, OBJECT, 'page');
        if ($p) { wp_delete_post($p->ID, true); }
    }
    echo wp_json_encode(['cleaned' => !get_page_by_path('olimpiada-z', OBJECT, 'page') && !get_page_by_path('olimpiada-z-panel', OBJECT, 'page')]);
    exit;
}
foreach ($pages as $slug) {
    $p = get_page_by_path($slug, OBJECT, 'page');
    if ($p) { wp_delete_post($p->ID, true); }
}
$tz  = WPD_Settings::countdown_timezone();
$rel = function (string $r) use ($tz) { return (new DateTime($r, $tz))->format('Y-m-d H:i'); };

$opt = get_option('wpd_settings');
$opt = is_array($opt) ? $opt : [];
$opt = array_merge($opt, ['countdown_enabled' => 1, 'countdown_date' => $rel('-1 hour'), 'countdown_end' => $rel('+1 day'),
    'countdown_link_text' => 'Задания из настроек', 'countdown_link_url' => 'https://example.com/settings-tasks', 'countdown_link_new_tab' => 1]);
update_option('wpd_settings', $opt);
WPD_Settings::flush_cache();

$blocks = implode("\n\n", [
    '<!-- wp:wpd/hero ' . wp_json_encode(['title' => 'Главный Z', 'showCountdown' => true, 'cdLinkText' => 'Герой Z', 'cdLinkUrl' => 'https://example.com/hero-z',
        'cdBtnStyle' => 'outline']) . ' /-->',
    '<!-- wp:wpd/countdown ' . wp_json_encode(['date' => $rel('-1 hour'), 'end' => $rel('+1 day'), 'linkText' => 'Задания Z', 'linkUrl' => 'https://example.com/tasks-z',
        'cdBeforeUrl' => 'https://example.com/reg-z', 'cdAfterUrl' => 'https://example.com/res-z', 'cdBtnStyle' => 'outline', 'cdBtnRadius' => 'pill',
        'cdBtnIcon' => 'download', 'cdBtnIconPos' => 'left', 'cdBtnIconSize' => 22, 'cdBtnNote' => 'Вход по логину Z', 'cdBtnColor' => '#b91c1c',
        'cdBtnHover' => '#fde68a', 'cdBtnHeight' => 52, 'cdBtnPos' => 'center', 'cdBtnTitle' => 'Подсказка Z', 'cdBtnNofollow' => true,
        'className' => 'z-active']) . ' /-->',
    '<!-- wp:wpd/countdown ' . wp_json_encode(['date' => $rel('+2 hours'), 'end' => $rel('+1 day'), 'linkText' => 'Начать Z', 'linkUrl' => 'https://example.com/start-z',
        'cdBeforeText' => 'Регистрация Z', 'cdBeforeUrl' => 'https://example.com/reg-z', 'cdBtnStyle' => 'link', 'cdBtnIcon' => 'none', 'className' => 'z-before']) . ' /-->',
    '<!-- wp:wpd/countdown ' . wp_json_encode(['date' => $rel('-2 days'), 'end' => $rel('-1 day'), 'linkText' => 'Начать Z', 'linkUrl' => 'https://example.com/start-z',
        'cdAfterText' => 'Результаты Z', 'cdAfterUrl' => 'https://example.com/res-z', 'cdBtnStyle' => 'soft', 'cdBtnWidth' => 'full', 'cdBtnBg' => '#16a34a',
        'className' => 'z-done']) . ' /-->',
]);
$id = (int) wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Олимпиада Z', 'post_name' => 'olimpiada-z', 'post_content' => wp_slash($blocks)]);

$pid = (int) wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Олимпиада Z панель', 'post_name' => 'olimpiada-z-panel', 'post_content' => '<p>Z</p>']);
$ov = ['countdown_enabled' => '1', 'countdown_tz' => 'Europe/Moscow', 'countdown_date' => $rel('-1 hour'), 'countdown_end' => $rel('+1 day'), 'countdown_label' => 'До начала',
       'countdown_end_label' => 'До конца Z', 'countdown_done' => 'Всё', 'countdown_link_text' => 'Задания панели', 'countdown_link_url' => 'https://example.com/panel-z',
       'countdown_link_new_tab' => '1', 'countdown_btn_style' => 'link', 'countdown_btn_pos' => 'below', 'countdown_btn_note' => 'Подпись Z', 'countdown_btn_icon' => 'arrow',
       'countdown_btn_icon_pos' => 'right', 'countdown_btn_radius' => 'auto', 'countdown_btn_width' => 'auto'];
update_post_meta($pid, '_wpd_page', WPD_Page_Editor::sanitize(['enabled' => '1', 'order' => ['countdown'], 'show' => ['countdown' => '1'], 'ovg' => ['features' => '1'], 'ov' => $ov], $pid));
update_post_meta($pid, WPD_Renderer::META_KEY, 1);
echo wp_json_encode(['id' => $id, 'url' => get_permalink($id), 'pid' => $pid, 'purl' => get_permalink($pid)]);
