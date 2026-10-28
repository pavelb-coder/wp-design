<?php
// Фаза X (2.10.0): импорт документов из сетевой папки (SMB, 2.8.0) убран. Кода сетевой папки нет (классы, файлы, хуки,
// REST, отдача файлов, поля строк и блоков, данные редактора, разделы панели и вкладки, справка), а одноразовая очистка
// удаляет всё импортированное: скопированные файлы медиатеки (с файлами на диске), строки из папки в общем списке,
// в своих списках страниц (и в группе «Документы» своего оформления), в блоках «Олимпиада: документы» на любом уровне
// вложенности, служебные поля, настройки, индекс, журнал, расписание, блокировку и кеш — порциями, без ревизий и смены
// даты, с итогом и уведомлением администратору. Повторный запуск ничего не меняет; на чистом сайте уведомления нет.
register_shutdown_function(function () { $e = error_get_last(); if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR], true)) { echo "\nSHUTDOWN: {$e['message']} in {$e['file']}:{$e['line']}\n"; } });
ini_set('display_errors', '1');
error_reporting(E_ALL);
require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
header('Content-Type: text/plain; charset=utf-8');

$results = [];
$fails = 0;
function check(string $name, bool $cond, string $detail = ''): void {
    global $results, $fails;
    $results[] = ($cond ? 'PASS ' : 'FAIL ') . $name . ($cond || $detail === '' ? '' : ' :: ' . $detail);
    if (!$cond) { $fails++; }
}
function has(string $h, string $n): bool { return strpos($h, $n) !== false; }
function set_raw(array $values): void {
    global $wp_filter;
    $saved = $wp_filter['sanitize_option_wpd_settings'] ?? null;
    unset($wp_filter['sanitize_option_wpd_settings']);
    update_option('wpd_settings', $values);
    if ($saved !== null) { $wp_filter['sanitize_option_wpd_settings'] = $saved; }
    WPD_Settings::flush_cache();
}
function reset_cleanup(): void {
    delete_option(WPD_Netfolder_Cleanup::DONE_OPTION);
    delete_option(WPD_Netfolder_Cleanup::STATE_OPTION);
    delete_option(WPD_Netfolder_Cleanup::NOTICE);
    delete_transient(WPD_Netfolder_Cleanup::LOCK);
}
function att_with_file(string $name, bool $nf): int {
    $up = wp_upload_bits($name, null, '%PDF-1.4 ' . $name);
    $id = (int) wp_insert_attachment(['post_title' => $name, 'post_mime_type' => 'application/pdf', 'post_status' => 'inherit'], $up['file']);
    if ($nf) {
        update_post_meta($id, '_wpd_nf_src', 'Итоги/' . $name);
        update_post_meta($id, '_wpd_nf_sig', '10|20');
    }
    return $id;
}

$backup = get_option('wpd_settings', null);
wp_set_current_user(1);
$dir = WPD_DIR;
$reg = rest_get_server()->get_routes();

/* ---------- 1. Кода сетевой папки нет ---------- */
check('version >= 2.10.0', version_compare(WPD_VERSION, '2.10.0', '>='));
check('no classes WPD_Netfolder, WPD_Smb', !class_exists('WPD_Netfolder', false) && !class_exists('WPD_Smb', false));
check('files removed', !file_exists($dir . 'includes/class-wpd-netfolder.php') && !file_exists($dir . 'includes/class-wpd-smb.php') && !file_exists($dir . 'assets/js/wpd-netfolder.js'));
check('cleanup class loaded', class_exists('WPD_Netfolder_Cleanup', false));
check('no REST routes wpd/v1/netfolder', !array_filter(array_keys($reg), function ($r) { return strpos($r, '/wpd/v1/netfolder') === 0; }));
check('no cron handler', !has_action('wpd_nf_sync'));
check('no settings box hook', !has_action('wpd_settings_after_form'));
check('cleanup hooks', has_action('init', ['WPD_Netfolder_Cleanup', 'maybe_run']) === 20 && has_action('admin_notices', ['WPD_Netfolder_Cleanup', 'notice']) !== false
    && has_action('admin_post_wpd_nf_removed_dismiss', ['WPD_Netfolder_Cleanup', 'dismiss']) !== false);
$map = WPD_Settings::field_map();
check('docs row: no src, nf', array_keys($map['docs']['fields']) === ['title', 'url', 'desc', 'badge', 'type', 'color', 'new_tab', 'download', 'hidden', 'from', 'to']);
check('no hidden field type left', !array_filter($map, function ($f) { return $f['type'] === 'repeater' && array_filter($f['fields'], function ($s) { return $s['type'] === 'hidden'; }); }));
$rows = WPD_Settings::sanitize_rows([['title' => 'A', 'url' => 'https://ex.com/a.pdf', 'src' => 'a.pdf', 'nf' => '1']], 'docs');
check('sanitize drops src, nf', count($rows) === 1 && !isset($rows[0]['src']) && !isset($rows[0]['nf']) && $rows[0]['title'] === 'A');
check('page editor: no docs_nf in defaults', !array_key_exists('docs_nf', WPD_Page_Editor::defaults()) && !array_key_exists('docs_nf_auto', WPD_Page_Editor::defaults()));
check('page editor: docs keys', WPD_Page_Editor::LISTS['docs'] === ['title', 'url', 'desc', 'badge', 'type', 'color', 'new_tab', 'download', 'hidden', 'from', 'to']);
$pe = WPD_Page_Editor::sanitize(['docs_mode' => 'custom', 'docs' => [['title' => 'B', 'url' => 'https://ex.com/b.pdf', 'src' => 'b.pdf']], 'docs_nf' => 'Итоги', 'docs_nf_auto' => '1'], 0, false);
check('page sanitize ignores docs_nf and src', !array_key_exists('docs_nf', $pe) && !array_key_exists('docs_nf_auto', $pe) && !isset($pe['docs'][0]['src']) && ($pe['docs'][0]['title'] ?? '') === 'B');
$defs = WPD_Blocks::definitions();
check('docs block: no nfFolder, nfAuto', !isset($defs['docs']['attributes']['nfFolder']) && !isset($defs['docs']['attributes']['nfAuto']));
$js = file_get_contents($dir . 'assets/js/wpd-blocks.js');
check('blocks js: no netfolder panel', !has($js, 'NetfolderPanel') && !has($js, 'netfolder') && !has($js, 'nfFolder') && !has($js, 'it.src'));
$all = '';
foreach (['includes/class-wpd-settings.php', 'includes/class-wpd-page-editor.php', 'includes/class-wpd-blocks.php', 'includes/class-wpd-renderer.php',
          'assets/js/wpd-admin.js', 'assets/js/wpd-settings.js', 'assets/js/wpd-sidebar.js', 'assets/css/wpd-settings.css', 'assets/css/wpd-editor.css', 'assets/css/wpd-admin.css', 'wp-olympiad-design.php'] as $f) {
    $all .= file_get_contents($dir . $f);
}
check('no netfolder code in other files', !has($all, 'WPD_Netfolder::') && !has($all, 'WPD_Smb') && !has($all, 'wpd-netfolder.js') && !has($all, "'wpd-netfolder'") && !has($all, 'wpd-nf') && !has($all, 'docs_nf') && !has($all, 'SMB'));
$rh = new ReflectionMethod('WPD_Settings', 'render_help');
$rh->setAccessible(true);
ob_start();
$rh->invoke(null);
$help = ob_get_clean();
check('help: no network folder section', !has($help, 'Сетевая папка') && !has($help, 'smbclient'));
$readme = file_get_contents($dir . 'README.md');
check('readme: removal section, no setup docs', has($readme, 'Сетевая папка убрана (2.10.0)') && !has($readme, 'pecl install smbclient') && !has($readme, 'wpd/v1/netfolder/scan'));
$un = file_get_contents($dir . 'uninstall.php');
check('uninstall runs full cleanup', has($un, 'WPD_Netfolder_Cleanup::run(0)'));
check('deactivation clears former schedule', has(file_get_contents($dir . 'wp-olympiad-design.php'), 'wp_clear_scheduled_hook(WPD_Netfolder_Cleanup::HOOK)'));

/* ---------- 2. Чистый сайт: очистка проходит без уведомления ---------- */
reset_cleanup();
check('fresh: run(0) done', WPD_Netfolder_Cleanup::run(0) === true);
check('fresh: done mark with zero report', is_array(get_option(WPD_Netfolder_Cleanup::DONE_OPTION)) && get_option(WPD_Netfolder_Cleanup::DONE_OPTION)['files'] === 0 && get_option(WPD_Netfolder_Cleanup::DONE_OPTION)['rows'] === 0);
check('fresh: no notice', !get_option(WPD_Netfolder_Cleanup::NOTICE));

/* ---------- 3. Помощники очистки ---------- */
$n = 0;
$r = WPD_Netfolder_Cleanup::clean_rows([['title' => 'M', 'url' => 'u', 'src' => '', 'nf' => ''], ['title' => 'F', 'src' => 'a.pdf'], ['title' => 'S', 'src' => '  '], 'junk', ['title' => 'N']], $n);
check('clean_rows: drops src rows, strips keys', $n === 1 && $r === [['title' => 'M', 'url' => 'u'], ['title' => 'S'], 'junk', ['title' => 'N']], wp_json_encode($r));
check('clean_rows: non-array as is', WPD_Netfolder_Cleanup::clean_rows('x') === 'x');
check('clean_content: null without docs block', WPD_Netfolder_Cleanup::clean_content('<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->') === null);
check('clean_content: null when nothing to clean', WPD_Netfolder_Cleanup::clean_content('<!-- wp:wpd/docs {"title":"Д","items":[{"title":"M","url":"https://ex.com/m.pdf"}]} /-->') === null);
$n = 0;
$m = WPD_Netfolder_Cleanup::clean_page_meta(['docs' => [['title' => 'A', 'src' => 'a'], ['title' => 'B']], 'docs_nf' => 'x', 'docs_nf_auto' => 1, 'ov' => ['docs' => ['docs' => [['title' => 'C', 'src' => 'c']], 'docs_title' => 'T']]], $n);
check('clean_page_meta', $n === 2 && $m === ['docs' => [['title' => 'B']], 'ov' => ['docs' => ['docs' => [], 'docs_title' => 'T']]], wp_json_encode($m));
$ref = new ReflectionMethod('WPD_Netfolder_Cleanup', 'plural');
$ref->setAccessible(true);
check('plural forms', $ref->invoke(null, 1, 'файл', 'файла', 'файлов') === '1 файл' && $ref->invoke(null, 3, 'файл', 'файла', 'файлов') === '3 файла'
    && $ref->invoke(null, 11, 'файл', 'файла', 'файлов') === '11 файлов' && $ref->invoke(null, 22, 'файл', 'файла', 'файлов') === '22 файла' && $ref->invoke(null, 25, 'файл', 'файла', 'файлов') === '25 файлов');
check('report_text', WPD_Netfolder_Cleanup::report_text(['rows' => 5, 'files' => 3, 'posts' => 1, 'pages' => 1])
    === 'Импорт документов из сетевой папки убран из плагина. Настройки подключения, пароль, журнал и расписание удалены; удалено 5 строк документов из папки, 3 файла медиатеки, изменено 2 записи или страницы.',
    WPD_Netfolder_Cleanup::report_text(['rows' => 5, 'files' => 3, 'posts' => 1, 'pages' => 1]));
check('report_text: nothing imported', WPD_Netfolder_Cleanup::report_text([]) === 'Импорт документов из сетевой папки убран из плагина. Настройки подключения, пароль, журнал и расписание удалены.');

/* ---------- 4. Данные бывшей сетевой папки ---------- */
update_option('wpd_netfolder', ['enabled' => 1, 'url' => '\\\\srv\\olymp', 'pass' => 'enc:xxx', 'schedule' => 'hourly']);
update_option('wpd_nf_index', ['k' => ['rel' => 'a.pdf', 'att' => 1]], false);
update_option('wpd_nf_log', [['time' => time(), 'text' => 'Импорт']], false);
set_transient('wpd_nf_lock', 1, 600);
wp_schedule_event(time() + 3600, 'hourly', 'wpd_nf_sync');
$cache = wp_upload_dir(null, false)['basedir'] . '/wpd-nf-cache';
wp_mkdir_p($cache);
file_put_contents($cache . '/0123456789abcdef01234567.pdf', 'x');
file_put_contents($cache . '/.htaccess', 'Deny from all');

$nf_atts = [att_with_file('x-one.pdf', true), att_with_file('x-two.pdf', true), att_with_file('x-three.pdf', true)];
$nf_files = array_map('get_attached_file', $nf_atts);
$own_att = att_with_file('x-own.pdf', false);
check('pre: attachments and files', count(array_filter($nf_files, 'is_file')) === 3 && is_file((string) get_attached_file($own_att)));
$u1 = wp_get_attachment_url($nf_atts[0]);

$manual = ['title' => 'Положение', 'url' => 'https://ex.com/pol.pdf', 'desc' => '', 'badge' => '', 'type' => '', 'color' => '', 'new_tab' => 0, 'download' => 0, 'hidden' => 0, 'from' => '', 'to' => ''];
set_raw(['docs_title' => 'Документы X', 'docs' => [
    $manual + ['src' => '', 'nf' => ''],
    ['title' => 'Итоги (копия)', 'url' => $u1, 'src' => 'Итоги/x-one.pdf', 'nf' => ''],
    ['title' => 'Итоги (ссылка)', 'url' => home_url('/?wpd_nf=0123456789abcdef01234567'), 'src' => 'Итоги/x-link.pdf', 'nf' => '1', 'hidden' => 1],
]]);

$page = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'X panel', 'post_content' => 'Текст X']);
update_post_meta($page, '_wpd_enabled', 1);
$meta = WPD_Page_Editor::defaults();
$meta['docs_mode'] = 'custom';
$meta['docs'] = [['title' => 'Своё X', 'url' => 'https://ex.com/own.pdf'], ['title' => 'Из папки X', 'url' => 'https://ex.com/nf.pdf', 'src' => 'X/nf.pdf', 'nf' => '']];
$meta['docs_nf'] = 'X';
$meta['docs_nf_auto'] = 1;
$meta['ov'] = ['docs' => ['docs' => [['title' => 'Ov из папки', 'url' => 'https://ex.com/ov.pdf', 'src' => 'X/ov.pdf']], 'docs_title' => 'Свой заголовок']];
update_post_meta($page, '_wpd_page', $meta);
$page_plain = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'X plain panel', 'post_content' => 'y']);
$plain_meta = WPD_Page_Editor::defaults();
update_post_meta($page_plain, '_wpd_page', $plain_meta);

$b = function (array $attrs): string { return '<!-- wp:wpd/docs ' . wp_json_encode($attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ' /-->'; };
$content = "<!-- wp:paragraph -->\n<p>До</p>\n<!-- /wp:paragraph -->\n\n"
    . $b(['title' => 'Блок X', 'nfFolder' => 'Итоги', 'nfAuto' => true, 'items' => [['title' => 'Ручной', 'url' => 'https://ex.com/r.pdf', 'src' => '', 'nf' => ''], ['title' => 'Папка', 'url' => 'https://ex.com/p.pdf', 'src' => 'Итоги/p.pdf', 'nf' => '']]])
    . "\n\n<!-- wp:group -->\n<div class=\"wp-block-group\">" . $b(['title' => 'Вложенный X', 'items' => [['title' => 'Глубоко', 'url' => 'https://ex.com/d.pdf', 'src' => 'd.pdf']]]) . "</div>\n<!-- /wp:group -->";
$post = wp_insert_post(['post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'X blocks', 'post_content' => wp_slash($content)]);
$clean_content = $b(['title' => 'Чистый X', 'items' => [['title' => 'Только ручной', 'url' => 'https://ex.com/o.pdf']]]);
$post_clean = wp_insert_post(['post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'X clean', 'post_content' => wp_slash($clean_content)]);
global $wpdb;
$wpdb->update($wpdb->posts, ['post_modified' => '2020-01-02 03:04:05', 'post_modified_gmt' => '2020-01-02 03:04:05'], ['ID' => $post]);
clean_post_cache($post);
$revs_before = count(wp_get_post_revisions($post));
check('pre: data seeded', get_option('wpd_netfolder') && wp_next_scheduled('wpd_nf_sync') && is_dir($cache) && has(get_post_field('post_content', $post), 'nfFolder'));

/* ---------- 5. Очистка порциями ---------- */
reset_cleanup();
$first = WPD_Netfolder_Cleanup::run(2);
check('batch 1: not done yet', $first === false && !WPD_Netfolder_Cleanup::is_done());
check('batch 1: two attachments deleted, settings untouched', count(array_filter($nf_atts, function ($id) { return get_post($id) !== null; })) === 1
    && count(get_option('wpd_settings')['docs']) === 3 && is_array(get_option(WPD_Netfolder_Cleanup::STATE_OPTION)));
$runs = 1;
while (!WPD_Netfolder_Cleanup::run(2) && $runs < 50) { $runs++; }
check('finished in several batches', $runs >= 2 && $runs < 50 && WPD_Netfolder_Cleanup::is_done(), (string) $runs);
wp_cache_flush();
WPD_Settings::flush_cache();

check('copied attachments deleted', !array_filter($nf_atts, function ($id) { return get_post($id) !== null; }));
check('copied files removed from disk', !array_filter($nf_files, 'is_file'));
check('own attachment kept', get_post($own_att) !== null && is_file((string) get_attached_file($own_att)));
$docs = get_option('wpd_settings')['docs'];
check('settings: only manual row, without service keys', $docs === [$manual], wp_json_encode($docs, JSON_UNESCAPED_UNICODE));
check('settings: other values kept', get_option('wpd_settings')['docs_title'] === 'Документы X');
$pm = get_post_meta($page, '_wpd_page', true);
check('page: folder rows removed', $pm['docs'] === [['title' => 'Своё X', 'url' => 'https://ex.com/own.pdf']] && $pm['ov']['docs']['docs'] === [] && $pm['ov']['docs']['docs_title'] === 'Свой заголовок', wp_json_encode($pm['docs']));
check('page: docs_nf fields removed', !array_key_exists('docs_nf', $pm) && !array_key_exists('docs_nf_auto', $pm) && $pm['docs_mode'] === 'custom');
check('page without folder data untouched', get_post_meta($page_plain, '_wpd_page', true) === $plain_meta);
$pc = get_post_field('post_content', $post);
$blocks = parse_blocks($pc);
$top = array_values(array_filter($blocks, function ($bl) { return $bl['blockName'] === 'wpd/docs'; }));
check('block: folder item removed, manual kept, attrs dropped', count($top) === 1 && $top[0]['attrs'] === ['title' => 'Блок X', 'items' => [['title' => 'Ручной', 'url' => 'https://ex.com/r.pdf']]], wp_json_encode($top[0]['attrs'] ?? null, JSON_UNESCAPED_UNICODE));
$group = array_values(array_filter($blocks, function ($bl) { return $bl['blockName'] === 'core/group'; }));
check('nested block cleaned', $group && $group[0]['innerBlocks'][0]['attrs'] === ['title' => 'Вложенный X', 'items' => []]);
check('other blocks kept', has($pc, "<!-- wp:paragraph -->\n<p>До</p>\n<!-- /wp:paragraph -->") && has($pc, '<div class="wp-block-group">'));
check('no revision, modified date kept', count(wp_get_post_revisions($post)) === $revs_before && get_post_field('post_modified', $post) === '2020-01-02 03:04:05');
check('clean post untouched', get_post_field('post_content', $post_clean) === $clean_content);
check('options deleted', get_option('wpd_netfolder', 'GONE') === 'GONE' && get_option('wpd_nf_index', 'GONE') === 'GONE' && get_option('wpd_nf_log', 'GONE') === 'GONE');
check('lock, schedule, cache removed', get_transient('wpd_nf_lock') === false && wp_next_scheduled('wpd_nf_sync') === false && !is_dir($cache));
check('state option removed', get_option(WPD_Netfolder_Cleanup::STATE_OPTION, 'GONE') === 'GONE');
$rep = get_option(WPD_Netfolder_Cleanup::DONE_OPTION);
check('report counts', $rep['version'] === WPD_VERSION && $rep['files'] === 3 && $rep['rows'] === 6 && $rep['posts'] === 1 && $rep['pages'] === 1, wp_json_encode($rep));
check('notice flag set', (int) get_option(WPD_Netfolder_Cleanup::NOTICE) === 1);

/* ---------- 6. Вывод сайта после очистки ---------- */
$html = WPD_Renderer::docs_html(WPD_Settings::get(), WPD_Settings::docs_items(WPD_Settings::get()['docs']), 'Документы X');
check('front: manual doc shown, folder docs gone', has($html, 'Положение') && !has($html, 'Итоги (копия)') && !has($html, 'wpd_nf'));
check('front: block renders manual item', has(do_blocks(get_post_field('post_content', $post)), 'Ручной') && !has(do_blocks(get_post_field('post_content', $post)), 'Папка'));

/* ---------- 7. Уведомление ---------- */
ob_start();
WPD_Netfolder_Cleanup::notice();
$nt = ob_get_clean();
check('notice for admin', has($nt, 'wpd-nf-removed-notice') && has($nt, 'WP Olympiad Design ' . WPD_VERSION) && has($nt, 'удалено 6 строк документов из папки, 3 файла медиатеки, изменено 2 записи или страницы')
    && has($nt, 'action=wpd_nf_removed_dismiss') && has($nt, '_wpnonce='), $nt);
$ed = wp_insert_user(['user_login' => 'wpdx_editor', 'user_pass' => wp_generate_password(), 'role' => 'editor', 'user_email' => 'wpdx@example.com']);
wp_set_current_user($ed);
ob_start();
WPD_Netfolder_Cleanup::notice();
check('no notice for editor', ob_get_clean() === '');
wp_set_current_user(1);
delete_option(WPD_Netfolder_Cleanup::NOTICE);
ob_start();
WPD_Netfolder_Cleanup::notice();
check('no notice after dismiss', ob_get_clean() === '');

/* ---------- 8. Повторно и при занятой блокировке ---------- */
$before = [get_option('wpd_settings'), get_post_meta($page, '_wpd_page', true), get_post_field('post_content', $post)];
WPD_Netfolder_Cleanup::maybe_run();
check('done: maybe_run does nothing', get_option(WPD_Netfolder_Cleanup::DONE_OPTION) === $rep && !get_option(WPD_Netfolder_Cleanup::NOTICE));
reset_cleanup();
check('rerun: done at once', WPD_Netfolder_Cleanup::run(0) === true);
wp_cache_flush();
check('rerun: nothing changed, no notice', [get_option('wpd_settings'), get_post_meta($page, '_wpd_page', true), get_post_field('post_content', $post)] === $before
    && get_option(WPD_Netfolder_Cleanup::DONE_OPTION)['rows'] === 0 && !get_option(WPD_Netfolder_Cleanup::NOTICE));
reset_cleanup();
set_transient(WPD_Netfolder_Cleanup::LOCK, 1, 60);
update_option('wpd_nf_log', ['x'], false);
WPD_Netfolder_Cleanup::maybe_run();
check('locked: skipped', get_option('wpd_nf_log', 'GONE') !== 'GONE' && !WPD_Netfolder_Cleanup::is_done());
delete_transient(WPD_Netfolder_Cleanup::LOCK);
WPD_Netfolder_Cleanup::maybe_run();
check('unlocked: one batch finishes small site', WPD_Netfolder_Cleanup::is_done() && get_option('wpd_nf_log', 'GONE') === 'GONE' && (int) get_option(WPD_Netfolder_Cleanup::NOTICE) === 1);

/* ---------- Уборка ---------- */
foreach ([$page, $page_plain, $post, $post_clean] as $id) { wp_delete_post($id, true); }
wp_delete_attachment($own_att, true);
wp_delete_user($ed);
delete_option(WPD_Netfolder_Cleanup::NOTICE);
set_raw(is_array($backup) ? $backup : []);
check('cleanup', !get_post($page) && !get_user_by('login', 'wpdx_editor'));

echo implode("\n", $results) . "\n";
echo "TOTAL: " . count($results) . ", FAILED: $fails\n";
