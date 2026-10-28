<?php
// Помощник фазы X (2.10.0): ?setup=1 — данные бывшей сетевой папки, как их оставил 2.8.0: настройки подключения, индекс,
// журнал, задание WP-Cron, вложение wpdx-nf.pdf, скопированное из папки, строка из папки в общем списке документов и
// страница /olimpiada-x/ с блоком «Олимпиада: документы» (строка из папки и ручная, атрибуты nfFolder/nfAuto);
// отметки очистки сбрасываются — следующий запрос к сайту выполнит её. ?state=1 — состояние очистки,
// ?cleanup=1 — удалить страницу, вложения wpdx-* и отметки. Выводит JSON.
require __DIR__ . '/wp-load.php';
header('Content-Type: application/json; charset=utf-8');
wp_set_current_user(1);

$page = get_page_by_path('olimpiada-x', OBJECT, 'page');
$atts = function () {
    return array_values(array_filter(get_posts(['post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids']),
        function ($id) { return strpos(basename((string) get_attached_file($id)), 'wpdx-') === 0; }));
};
if (isset($_GET['state'])) {
    $o = get_option('wpd_settings');
    echo wp_json_encode([
        'done'    => get_option(WPD_Netfolder_Cleanup::DONE_OPTION),
        'notice'  => (int) get_option(WPD_Netfolder_Cleanup::NOTICE),
        'options' => count(array_filter(['wpd_netfolder', 'wpd_nf_index', 'wpd_nf_log'], function ($n) { return get_option($n, null) !== null; })),
        'cron'    => (bool) wp_next_scheduled('wpd_nf_sync'),
        'atts'    => count($atts()),
        'docs'    => array_column(is_array($o['docs'] ?? null) ? $o['docs'] : [], 'title'),
        'content' => $page ? get_post_field('post_content', $page->ID) : '',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
if (isset($_GET['cleanup'])) {
    if ($page) { wp_delete_post($page->ID, true); }
    foreach ($atts() as $id) { wp_delete_attachment($id, true); }
    delete_option(WPD_Netfolder_Cleanup::NOTICE);
    echo wp_json_encode(['cleaned' => !get_page_by_path('olimpiada-x', OBJECT, 'page') && !$atts()]);
    exit;
}
if ($page) { wp_delete_post($page->ID, true); }
foreach ($atts() as $id) { wp_delete_attachment($id, true); }

update_option('wpd_netfolder', ['enabled' => 1, 'url' => '\\\\srv\\olymp', 'pass' => 'enc:x']);
update_option('wpd_nf_index', ['k' => ['rel' => 'a.pdf']], false);
update_option('wpd_nf_log', [['time' => time(), 'text' => 'Импорт']], false);
wp_clear_scheduled_hook('wpd_nf_sync');
wp_schedule_event(time() + 3600, 'hourly', 'wpd_nf_sync');
$up  = wp_upload_bits('wpdx-nf.pdf', null, '%PDF-1.4 X');
$att = (int) wp_insert_attachment(['post_title' => 'wpdx-nf', 'post_mime_type' => 'application/pdf', 'post_status' => 'inherit'], $up['file']);
update_post_meta($att, '_wpd_nf_src', 'Итоги/wpdx-nf.pdf');

$o = get_option('wpd_settings');
$o = is_array($o) ? $o : [];
$o['docs'] = [
    ['title' => 'Положение X', 'url' => 'https://ex.com/pol-x.pdf', 'src' => '', 'nf' => ''],
    ['title' => 'Итоги из папки X', 'url' => wp_get_attachment_url($att), 'src' => 'Итоги/wpdx-nf.pdf', 'nf' => ''],
];
update_option('wpd_settings', $o);

$attrs = ['title' => 'Документы X', 'nfFolder' => 'Итоги', 'nfAuto' => true, 'items' => [
    ['title' => 'Ручной X', 'url' => 'https://ex.com/manual-x.pdf', 'src' => '', 'nf' => ''],
    ['title' => 'Папка X', 'url' => 'https://ex.com/folder-x.pdf', 'src' => 'Итоги/folder-x.pdf', 'nf' => ''],
]];
$content = "<!-- wp:paragraph -->\n<p>Текст X</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:wpd/docs " . wp_json_encode($attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ' /-->';
$id = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'olimpiada-x', 'post_title' => 'Олимпиада X', 'post_content' => wp_slash($content)]);

delete_option(WPD_Netfolder_Cleanup::DONE_OPTION);
delete_option(WPD_Netfolder_Cleanup::STATE_OPTION);
delete_option(WPD_Netfolder_Cleanup::NOTICE);
delete_transient(WPD_Netfolder_Cleanup::LOCK);
echo wp_json_encode(['id' => $id, 'url' => get_permalink($id), 'att' => $att]);
