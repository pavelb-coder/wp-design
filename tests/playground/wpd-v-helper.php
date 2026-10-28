<?php
// Помощник фазы V (2.8.0): тестовая «сетевая папка» (каталог wp-content/wpd-nf-test, способ mount), режим ссылок,
// страница со своим списком документов, уборка. Ответ — JSON.
require '/wordpress/wp-load.php';
header('Content-Type: application/json; charset=utf-8');
wp_set_current_user(1);
$root = WP_CONTENT_DIR . '/wpd-nf-test';
function v_put(string $rel, string $data): void {
    $p = WP_CONTENT_DIR . '/wpd-nf-test/' . $rel;
    wp_mkdir_p(dirname($p));
    file_put_contents($p, $data);
    touch($p, time() - 600);
}
function v_rm(string $d): void {
    if (!is_dir($d)) { return; }
    foreach (array_diff(scandir($d), ['.', '..']) as $f) { $p = $d . '/' . $f; is_dir($p) ? v_rm($p) : unlink($p); }
    rmdir($d);
}
$out = [];
if (isset($_GET['setup'])) {
    v_rm($root);
    v_put('Документы/Положение об олимпиаде.pdf', '%PDF-1.4 V положение');
    v_put('Документы/Регламент.docx', 'DOCX V регламент');
    v_put('Ссылки/Приказ №1.pdf', '%PDF-1.4 V приказ');
    v_put('Ссылки/Форма <заявки>.docx', 'DOCX V форма');
    v_put('Ссылки/page.html', '<script>alert(1)</script>');
    delete_option('wpd_netfolder');
    delete_option('wpd_nf_index');
    delete_option('wpd_nf_log');
    $out['mount'] = $root;
}
if (isset($_GET['config'])) {
    // Для браузерной фазы: подключение без формы (способ mount, общий список — «Документы», расписание каждый час)
    update_option('wpd_netfolder', array_merge(WPD_Netfolder::defaults(), ['enabled' => 1, 'driver' => 'mount', 'mount' => $root,
        'path' => '\\\\fileserver\\olymp', 'sub' => 'Документы', 'schedule' => 'hourly']));
    $out['ok'] = WPD_Netfolder::enabled();
}
if (isset($_GET['link'])) {
    $o = WPD_Netfolder::get();
    $o['mode'] = 'link';
    $o['exts'] = $o['exts'] . ', html';
    update_option('wpd_netfolder', $o);
    $r = WPD_Netfolder::import('items', ['sub' => 'Ссылки', 'items' => []]);
    $out['urls'] = is_wp_error($r) ? $r->get_error_message() : array_column($r['rows'], 'url', 'src');
}
if (isset($_GET['page'])) {
    $id = (int) wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Страница V', 'post_content' => '<!-- wp:wpd/docs {"nfFolder":"Ссылки"} /-->']);
    update_post_meta($id, '_wpd_enabled', '1');
    WPD_Page_Editor::store($id, WPD_Page_Editor::sanitize(['docs_mode' => 'custom', 'docs_nf' => 'Документы', 'docs' => [['title' => 'Ручной', 'url' => 'https://example.org/r.pdf']]], $id, false));
    update_option('wpd_v_page', $id);
    $out['id'] = $id;
}
if (isset($_GET['state'])) {
    $id = (int) get_option('wpd_v_page');
    $p = $id ? WPD_Page_Editor::get($id) : [];
    $out['settings'] = WPD_Netfolder::get();
    $out['settings']['pass_plain_ok'] = WPD_Netfolder::decrypt((string) $out['settings']['pass']) === 'Пароль-V 1';
    unset($out['settings']['pass']);
    $out['docs'] = WPD_Settings::get_global()['docs'];
    $out['page_docs'] = $p['docs'] ?? [];
    $out['page_nf'] = [$p['docs_nf'] ?? null, $p['docs_nf_auto'] ?? null, $p['docs_mode'] ?? null];
    $out['next'] = wp_next_scheduled('wpd_nf_sync') ? wp_get_schedule('wpd_nf_sync') : '';
}
if (isset($_GET['cleanup'])) {
    $id = (int) get_option('wpd_v_page');
    if ($id) { wp_delete_post($id, true); }
    foreach (get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_wpd_nf_src']) as $a) { wp_delete_attachment($a, true); }
    v_rm($root);
    v_rm(wp_upload_dir(null, false)['basedir'] . '/wpd-nf-cache');
    foreach (['wpd_netfolder', 'wpd_nf_index', 'wpd_nf_log', 'wpd_v_page'] as $k) { delete_option($k); }
    wp_clear_scheduled_hook('wpd_nf_sync');
    $out['cleaned'] = !is_dir($root);
}
echo wp_json_encode($out, JSON_UNESCAPED_UNICODE);
