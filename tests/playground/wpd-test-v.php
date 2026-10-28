<?php
// Фаза V (2.8.0): импорт документов из сетевой папки. В Playground нет расширения smbclient и proc_open, поэтому
// папка — каталог wp-content/wpd-nf-test (способ «Папка, подключённая к серверу сайта»); драйверы SMB проверяются
// отдельно на настоящем Samba (tests/smb). Здесь: настройки и шифрование пароля, разбор адресов, просмотр папки
// (фильтры, подпапки, размер), импорт в общий список (копии в медиатеке: повторный импорт, изменение файла на месте,
// пропавший и вернувшийся файл, правки пользователя), режим «только ссылки» (отдача файла, кеш, защита), список
// страницы, блок, расписание (страницы и блоки с автообновлением), REST и права, поля строк, вкладка, удаление.
register_shutdown_function(function () { $e = error_get_last(); if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR], true)) { echo "\nSHUTDOWN: {$e['message']} in {$e['file']}:{$e['line']}\n"; } });
ini_set('display_errors', '1');
error_reporting(E_ALL);
require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
header('Content-Type: text/plain; charset=utf-8');

$results = [];
$fails = 0;
function check(string $name, bool $cond, string $detail = ''): void {
    global $results, $fails;
    $results[] = ($cond ? 'PASS ' : 'FAIL ') . $name . ($cond || $detail === '' ? '' : ' :: ' . $detail);
    if (!$cond) { $fails++; }
}
function has(string $h, string $n): bool { return strpos($h, $n) !== false; }
function set_opts(array $values): void {
    global $wp_filter;
    $saved = $wp_filter['sanitize_option_wpd_settings'] ?? null;
    unset($wp_filter['sanitize_option_wpd_settings']);
    update_option('wpd_settings', $values);
    if ($saved !== null) {
        $wp_filter['sanitize_option_wpd_settings'] = $saved;
    }
    WPD_Settings::flush_cache();
}
function nf_set(array $o): void {
    update_option('wpd_netfolder', array_merge(WPD_Netfolder::defaults(), $o));
}
function put(string $rel, string $data, int $mtime = 0): void {
    $p = WP_CONTENT_DIR . '/wpd-nf-test/' . $rel;
    wp_mkdir_p(dirname($p));
    file_put_contents($p, $data);
    touch($p, $mtime ?: time() - 3600);
    clearstatcache();
}
function rrmdir(string $d): void {
    if (!is_dir($d)) { return; }
    foreach (array_diff(scandir($d), ['.', '..']) as $f) {
        $p = $d . '/' . $f;
        is_dir($p) ? rrmdir($p) : unlink($p);
    }
    rmdir($d);
}
function docs(): array { WPD_Settings::flush_cache(); return WPD_Settings::get_global()['docs']; }
function by_src(array $rows, string $src): ?array { foreach ($rows as $r) { if (($r['src'] ?? '') === $src) { return $r; } } return null; }
function nf_atts(): array { return get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_wpd_nf_src']); }
function rest(string $method, string $route, array $params = []) {
    $r = new WP_REST_Request($method, $route);
    foreach ($params as $k => $v) { $r->set_param($k, $v); }
    return rest_do_request($r);
}

$backup = get_option('wpd_settings');
$nf_backup = get_option('wpd_netfolder', null);
wp_set_current_user(1);
$created = [];
$D = WPD_Settings::defaults();
set_opts(array_merge($D, ['hero_title' => 'Заголовок V', 'docs_title' => 'Документы V']));
$ROOT = WP_CONTENT_DIR . '/wpd-nf-test';
rrmdir($ROOT);
delete_option('wpd_nf_index');
delete_option('wpd_nf_log');

/* 1. Классы, версия, подключение */
$pd = get_plugin_data(WP_PLUGIN_DIR . '/wp-olympiad-design/wp-olympiad-design.php', false, false);
check('version 2.8.0 header = constant', WPD_VERSION === $pd['Version'] && version_compare(WPD_VERSION, '2.8.0', '>='), WPD_VERSION . '/' . $pd['Version']);
check('classes loaded', class_exists('WPD_Smb') && class_exists('WPD_Netfolder'));
check('hooks: serve, cron, rest, box', has_action('init', ['WPD_Netfolder', 'maybe_serve']) !== false && has_action('wpd_nf_sync', ['WPD_Netfolder', 'cron']) !== false
    && has_action('rest_api_init', ['WPD_Netfolder', 'register_rest']) !== false && has_action('wpd_settings_after_form', ['WPD_Netfolder', 'render_box']) !== false);
check('drivers list', array_keys(WPD_Netfolder::DRIVERS) === ['auto', 'ext', 'cli', 'mount'], json_encode(WPD_Smb::DRIVERS));

/* 2. Разбор адресов и путей */
check('unc backslashes', WPD_Smb::parse_unc('\\\\srv\\Общая\\Папка 1\\') === ['host' => 'srv', 'share' => 'Общая', 'path' => 'Папка 1']);
check('unc smb:// and //', WPD_Smb::parse_unc('smb://10.0.0.5/olymp/a/b') === ['host' => '10.0.0.5', 'share' => 'olymp', 'path' => 'a/b']
    && WPD_Smb::parse_unc('//сервер/docs') === ['host' => 'сервер', 'share' => 'docs', 'path' => '']);
check('unc rejects', WPD_Smb::parse_unc('\\\\srv') === [] && WPD_Smb::parse_unc('http://x/y') === [] && WPD_Smb::parse_unc('') === [] && WPD_Smb::parse_unc('\\\\s r v\\x') === []);
check('clean_rel drops .. and .', WPD_Smb::clean_rel('../a/./b/../c\\d/') === 'a/b/c/d' || WPD_Smb::clean_rel('../a/./b/../c\\d/') === 'a/c/d', WPD_Smb::clean_rel('../a/./b/../c\\d/'));
check('clean_rel never escapes', strpos(WPD_Smb::clean_rel('../../etc/passwd'), '..') === false);
check('join', WPD_Smb::join('a', '', 'b/c') === 'a/b/c');

/* 3. Настройки: санитизация и пароль */
delete_option('wpd_netfolder');
$s = WPD_Netfolder::sanitize(['enabled' => '1', 'path' => ' \\\\fs\\olymp ', 'user' => 'u', 'pass' => 'Пароль; "кавычки" & <теги>', 'driver' => 'evil',
    'mode' => 'x', 'sub' => '../Итоги/./2026/', 'exts' => '.PDF, docx;; *.zip pdf bad-ext', 'max_mb' => '999', 'gone' => 'x', 'schedule' => 'weekly']);
check('sanitize: enums fall back', $s['driver'] === 'auto' && $s['mode'] === 'copy' && $s['gone'] === 'hide' && $s['schedule'] === '');
check('sanitize: path trimmed, sub cleaned', $s['path'] === '\\\\fs\\olymp' && $s['sub'] === 'Итоги/2026', $s['path'] . '|' . $s['sub']);
check('sanitize: exts', $s['exts'] === 'pdf, docx, zip', $s['exts']);
check('sanitize: max_mb capped', $s['max_mb'] === 200);
check('password encrypted, not plain', $s['pass'] !== '' && strpos($s['pass'], 'Пароль') === false && (strpos($s['pass'], 'enc:') === 0 || strpos($s['pass'], 'b64:') === 0), substr($s['pass'], 0, 4));
check('password decrypts exactly', WPD_Netfolder::decrypt($s['pass']) === 'Пароль; "кавычки" & <теги>');
check('double sanitize keeps one encryption', WPD_Netfolder::decrypt(WPD_Netfolder::sanitize(['pass' => $s['pass']])['pass']) === 'Пароль; "кавычки" & <теги>');
check('openssl used when available', !function_exists('openssl_encrypt') || strpos($s['pass'], 'enc:') === 0);
check('two encryptions differ (random iv)', !function_exists('openssl_encrypt') || WPD_Netfolder::encrypt('x') !== WPD_Netfolder::encrypt('x'));
check('tampered cipher → empty', WPD_Netfolder::decrypt('enc:' . base64_encode(str_repeat('A', 48))) === '' && WPD_Netfolder::decrypt('junk') === '');
update_option('wpd_netfolder', $s);
$s2 = WPD_Netfolder::sanitize(['path' => '\\\\fs\\olymp', 'pass' => '']);
check('empty password keeps saved', $s2['pass'] === $s['pass']);
check('unchecked boxes → 0, empty exts → default', $s2['enabled'] === 0 && $s2['recursive'] === 0 && $s2['exts'] === WPD_Netfolder::defaults()['exts']);
$s3 = WPD_Netfolder::sanitize(['pass' => 'new', 'pass_clear' => '1']);
check('pass_clear clears', $s3['pass'] === '');
check('backslashes kept (options.php already unslashed)', WPD_Netfolder::decrypt(WPD_Netfolder::sanitize(['pass' => 'a\\b"c'])['pass']) === 'a\\b"c'
    && WPD_Netfolder::sanitize(['path' => '\\\\fs\\olymp'])['path'] === '\\\\fs\\olymp');
check('php not trusted by extension', WPD_Netfolder::trust_ext(['ext' => false, 'type' => false], '/x', 'evil.php', null)['ext'] === false
    && WPD_Netfolder::trust_ext(['ext' => false, 'type' => false], '/x', 'a.xlsx', null)['ext'] === 'xlsx');
check('get() fills defaults, drops unknown', array_keys(WPD_Netfolder::get()) === array_keys(WPD_Netfolder::defaults()));

/* 4. Подписи */
check('title_of', WPD_Netfolder::title_of('Протокол_жюри  2026.docx') === 'Протокол жюри 2026' && WPD_Netfolder::title_of('.pdf') === '.pdf'
    && WPD_Netfolder::title_of('Форма <заявки>.docx') === 'Форма «заявки»');
check('human_size', WPD_Netfolder::human_size(980) === '980 Б' && WPD_Netfolder::human_size(1468006) === '1,4 МБ' && WPD_Netfolder::human_size(12 * 1024) === '12 КБ',
    WPD_Netfolder::human_size(1468006));
check('auto_desc with folder', WPD_Netfolder::auto_desc(['ext' => 'docx', 'size' => 2048, 'dir' => 'Итоги']) === 'Итоги · DOCX, 2 КБ');

/* 5. Папка и проверка подключения */
put('Документы/Положение.pdf', '%PDF-1.4 положение');
put('Документы/Итоги/Протокол_жюри.docx', 'DOCX протокол');
put('Документы/~$Положение.docx', 'lock');
put('Документы/.hidden.pdf', 'hidden');
put('Документы/фото.jpg', 'jpg');
put('Документы/Большой.zip', str_repeat('z', 1024 * 1024 + 10));
put('Документы/Расписание.xlsx', 'xlsx');
nf_set(['enabled' => 1, 'driver' => 'mount', 'mount' => $ROOT, 'sub' => 'Документы', 'max_mb' => 1]);
$c = WPD_Netfolder::client();
$t = $c->test('Документы');
check('test: ok via mount', $t['ok'] && $t['driver'] === 'mount' && $t['dirs'] === 1, json_encode($t, JSON_UNESCAPED_UNICODE));
$tb = WPD_Netfolder::client(array_merge(WPD_Netfolder::get(), ['mount' => $ROOT . '/нет']))->test();
check('test: missing folder → message', !$tb['ok'] && $tb['message'] !== '', $tb['message']);
check('mount: no escape with ..', $c->listdir('../..') === null || count((array) $c->listdir('../..')) === count((array) $c->listdir('')));
$auto = WPD_Netfolder::client(array_merge(WPD_Netfolder::get(), ['driver' => 'auto']));
check('auto picks available driver', in_array($auto->driver(), ['ext', 'cli', 'mount'], true), $auto->driver() . ' ' . $auto->error());

/* 6. Просмотр (dry) */
$r = WPD_Netfolder::run('settings', ['dry' => true]);
check('scan ok', is_array($r), is_wp_error($r) ? $r->get_error_message() : '');
$st = [];
foreach ($r['files'] as $f) { $st[$f['rel']] = $f['status']; }
check('scan: filters exts, temp and hidden files, subfolders', $st === ['Документы/Большой.zip' => 'toobig', 'Документы/Положение.pdf' => 'new', 'Документы/Расписание.xlsx' => 'new'] || array_keys($st) == ['Документы/Большой.zip', 'Документы/Положение.pdf', 'Документы/Расписание.xlsx'], json_encode($st, JSON_UNESCAPED_UNICODE));
check('scan: statuses', ($st['Документы/Положение.pdf'] ?? '') === 'new' && ($st['Документы/Большой.zip'] ?? '') === 'toobig');
check('dry: nothing saved', docs() === [] && nf_atts() === [] && get_option('wpd_nf_index', 'none') === 'none');

/* 7. Импорт в общий список: копии в медиатеке */
$r = WPD_Netfolder::import('settings', ['only' => ['Документы/Положение.pdf']]);
check('import only selected', !is_wp_error($r) && $r['report']['added'] === 1 && $r['report']['toobig'] === 1, is_wp_error($r) ? $r->get_error_message() : json_encode($r['report']));
$rows = docs();
$row = by_src($rows, 'Документы/Положение.pdf');
check('row fields', $row && $row['title'] === 'Положение' && $row['type'] === 'PDF' && $row['desc'] === 'PDF, ' . WPD_Netfolder::human_size(strlen('%PDF-1.4 положение')) && $row['nf'] === '' && $row['hidden'] === 0, json_encode($row, JSON_UNESCAPED_UNICODE));
$atts = nf_atts();
check('attachment created with meta', count($atts) === 1 && get_post_meta($atts[0], '_wpd_nf_src', true) === 'Документы/Положение.pdf' && $row['url'] === wp_get_attachment_url($atts[0]));
check('attachment file content', file_get_contents(get_attached_file($atts[0])) === '%PDF-1.4 положение');
check('attachment title from name', get_the_title($atts[0]) === 'Положение');
check('other settings kept', WPD_Settings::get_global()['hero_title'] === 'Заголовок V' && WPD_Settings::get_global()['docs_title'] === 'Документы V');
check('index entry', count(get_option('wpd_nf_index')) === 1 && current(get_option('wpd_nf_index'))['mode'] === 'copy');
check('log written', get_option('wpd_nf_log')[0]['text'] === 'добавлено: 1, слишком большие: 1.' && get_option('wpd_nf_log')[0]['where'] === 'Общий список', json_encode(get_option('wpd_nf_log')[0], JSON_UNESCAPED_UNICODE));
$out = WPD_Renderer::docs_html(WPD_Settings::get(), docs(), 'Документы');
check('front: imported doc rendered', has($out, 'Положение') && has($out, esc_url($row['url'])));

$r = WPD_Netfolder::import('settings');
check('second import: xlsx added, pdf unchanged', $r['report']['added'] === 1 && $r['report']['unchanged'] === 1, json_encode($r['report']));
check('no duplicates', count(docs()) === 2 && count(nf_atts()) === 2);
$r = WPD_Netfolder::import('settings');
check('third import: nothing to do', $r['report']['added'] === 0 && $r['report']['updated'] === 0 && WPD_Netfolder::report_text($r['report']) === 'слишком большие: 1.'
    && WPD_Netfolder::report_text(['added' => 0, 'updated' => 0, 'gone' => 0, 'removed' => 0, 'skipped' => 0, 'toobig' => 0, 'errors' => []]) === 'Изменений нет.');

// Пользователь правит строку — правки сохраняются при обновлении файла
$rows = docs();
foreach ($rows as &$x) { if ($x['src'] === 'Документы/Положение.pdf') { $x['title'] = 'Положение об олимпиаде'; $x['badge'] = 'Новое'; } }
unset($x);
set_opts(array_merge(WPD_Settings::get_global(), ['docs' => $rows]));
$att_before = nf_atts();
sort($att_before);
put('Документы/Положение.pdf', '%PDF-1.4 положение, редакция 2', time());
$r = WPD_Netfolder::run('settings', ['dry' => true]);
$st = [];
foreach ($r['files'] as $f) { $st[$f['rel']] = $f['status']; }
check('changed file detected', $st['Документы/Положение.pdf'] === 'changed' && $st['Документы/Расписание.xlsx'] === 'same');
$r = WPD_Netfolder::import('settings');
$row2 = by_src(docs(), 'Документы/Положение.pdf');
$att_now = nf_atts();
sort($att_now);
check('changed: updated in place', $r['report']['updated'] === 1 && $att_now === $att_before && $row2['url'] === $row['url'], json_encode($r['report']));
check('changed: new content in media', file_get_contents(get_attached_file($att_before[0])) === '%PDF-1.4 положение, редакция 2' || file_get_contents(get_attached_file($att_before[1])) === '%PDF-1.4 положение, редакция 2');
check('changed: user edits kept, desc refreshed', $row2['title'] === 'Положение об олимпиаде' && $row2['badge'] === 'Новое' && $row2['desc'] === 'PDF, ' . WPD_Netfolder::human_size(strlen('%PDF-1.4 положение, редакция 2')), json_encode($row2, JSON_UNESCAPED_UNICODE));

// Файл пропал → строка скрыта; вернулся → снова видна
rename($ROOT . '/Документы/Расписание.xlsx', $ROOT . '/Расписание.xlsx');
$r = WPD_Netfolder::run('settings', ['dry' => true]);
check('gone listed in preview', count($r['gone']) === 1 && $r['gone'][0]['rel'] === 'Документы/Расписание.xlsx');
$r = WPD_Netfolder::import('settings');
$g = by_src(docs(), 'Документы/Расписание.xlsx');
check('gone: hidden + nf', $r['report']['gone'] === 1 && $g['hidden'] === 1 && $g['nf'] === '1');
check('gone: not on site', !has(WPD_Renderer::docs_html(WPD_Settings::get(), docs(), 'Д'), 'Расписание'));
$r = WPD_Netfolder::import('settings');
check('gone: counted once', $r['report']['gone'] === 0);
rename($ROOT . '/Расписание.xlsx', $ROOT . '/Документы/Расписание.xlsx');
$r = WPD_Netfolder::import('settings');
$g = by_src(docs(), 'Документы/Расписание.xlsx');
check('returned: shown again', $g['hidden'] === 0 && $g['nf'] === '' && $r['report']['updated'] === 1);
// Скрытая вручную строка не открывается импортом
$rows = docs();
foreach ($rows as &$x) { if ($x['src'] === 'Документы/Расписание.xlsx') { $x['hidden'] = 1; } }
unset($x);
set_opts(array_merge(WPD_Settings::get_global(), ['docs' => $rows]));
WPD_Netfolder::import('settings');
check('manually hidden stays hidden', by_src(docs(), 'Документы/Расписание.xlsx')['hidden'] === 1);

// Подпапки, новые в начало, удаление пропавших
nf_set(array_merge(WPD_Netfolder::get(), ['recursive' => 1, 'new_first' => 1, 'new_tab' => 1]));
$r = WPD_Netfolder::import('settings');
$rows = docs();
check('recursive: subfolder file added first', $r['report']['added'] === 1 && $rows[0]['src'] === 'Документы/Итоги/Протокол_жюри.docx' && $rows[0]['title'] === 'Протокол жюри'
    && strpos($rows[0]['desc'], 'Итоги · DOCX, ') === 0 && $rows[0]['type'] === 'DOCX' && $rows[0]['new_tab'] === 1, json_encode($rows[0], JSON_UNESCAPED_UNICODE));
nf_set(array_merge(WPD_Netfolder::get(), ['gone' => 'remove']));
unlink($ROOT . '/Документы/Итоги/Протокол_жюри.docx');
$r = WPD_Netfolder::import('settings');
check('gone=remove: row removed', $r['report']['removed'] === 1 && by_src(docs(), 'Документы/Итоги/Протокол_жюри.docx') === null);
// Строки не из папки не трогаются
$rows = docs();
$rows[] = ['title' => 'Своя ссылка', 'url' => 'https://example.org/x.pdf', 'desc' => '', 'badge' => '', 'type' => '', 'color' => '', 'new_tab' => 0, 'download' => 0, 'hidden' => 0, 'from' => '', 'to' => '', 'src' => '', 'nf' => ''];
set_opts(array_merge(WPD_Settings::get_global(), ['docs' => $rows]));
WPD_Netfolder::import('settings');
$own = null;
foreach (docs() as $x) { if ($x['title'] === 'Своя ссылка') { $own = $x; } }
check('manual row kept', $own && $own['url'] === 'https://example.org/x.pdf' && $own['hidden'] === 0);
nf_set(array_merge(WPD_Netfolder::get(), ['gone' => 'hide', 'recursive' => 0, 'new_first' => 0, 'new_tab' => 0]));

/* 8. Ошибки */
nf_set(array_merge(WPD_Netfolder::get(), ['enabled' => 0]));
$e = WPD_Netfolder::import('settings');
check('disabled → error', is_wp_error($e) && $e->get_error_code() === 'wpd_nf_off');
nf_set(array_merge(WPD_Netfolder::get(), ['enabled' => 1]));
$e = WPD_Netfolder::import('settings', ['sub' => 'Нет такой']);
check('missing subfolder → error, logged', is_wp_error($e) && $e->get_error_message() !== '' && get_option('wpd_nf_log')[0]['error'] === 1);
check('unknown target', is_wp_error(WPD_Netfolder::run('nope')));
check('page target needs post', is_wp_error(WPD_Netfolder::run('page', ['post_id' => 0])));

/* 9. Режим «только ссылки» */
put('Ссылки/Приказ.pdf', '%PDF приказ');
put('Ссылки/Форма заявки.docx', 'DOCX форма');
nf_set(array_merge(WPD_Netfolder::get(), ['mode' => 'link']));
$r = WPD_Netfolder::import('items', ['sub' => 'Ссылки', 'items' => []]);
$links = $r['rows'];
check('link: two rows, settings untouched', count($links) === 2 && count(docs()) === 3, count($links) . '/' . count(docs()));
put('Ссылки/Форма <заявки> 100%.pdf', '%PDF форма');
$r2 = WPD_Netfolder::import('items', ['sub' => 'Ссылки', 'items' => $links]);
$r3 = WPD_Netfolder::import('items', ['sub' => 'Ссылки', 'items' => $r2['rows']]);
check('odd file name: added once, stable on reimport', $r2['report']['added'] === 1 && $r3['report']['added'] === 0 && count($r3['rows']) === 3
    && by_src($r3['rows'], 'Ссылки/Форма <заявки> 100%.pdf') !== null && $r3['report']['gone'] === 0, json_encode([$r2['report'], $r3['report']]));
unlink($ROOT . '/Ссылки/Форма <заявки> 100%.pdf');
$lk = by_src($links, 'Ссылки/Приказ.pdf');
check('link url format', (bool) preg_match('#\?wpd_nf=([a-f0-9]{24})$#', $lk['url'], $m) && $m[1] === WPD_Netfolder::key('Ссылки/Приказ.pdf'), $lk['url']);
check('link: no attachments created', count(nf_atts()) === 3, (string) count(nf_atts()));
$key = WPD_Netfolder::key('Ссылки/Приказ.pdf');
$pf = WPD_Netfolder::prepare_file($key);
check('prepare_file: fetched', is_array($pf) && file_get_contents($pf['path']) === '%PDF приказ' && $pf['name'] === 'Приказ.pdf' && $pf['ext'] === 'pdf', is_wp_error($pf) ? $pf->get_error_message() : '');
check('cache in uploads/wpd-nf-cache, protected', strpos($pf['path'], '/wpd-nf-cache/' . $key . '.pdf') !== false && is_file(dirname($pf['path']) . '/.htaccess') && is_file(dirname($pf['path']) . '/index.php'));
put('Ссылки/Приказ.pdf', '%PDF приказ v2', time() + 5);
$pf2 = WPD_Netfolder::prepare_file($key);
check('cache served within ttl (same signature)', file_get_contents($pf2['path']) === '%PDF приказ');
WPD_Netfolder::import('items', ['sub' => 'Ссылки', 'items' => $links]);
$pf3 = WPD_Netfolder::prepare_file($key);
check('changed file: cache dropped on import', file_get_contents($pf3['path']) === '%PDF приказ v2');
add_filter('wpd_nf_cache_ttl', '__return_zero');
put('Ссылки/Приказ.pdf', '%PDF приказ v3', time() + 9);
$pf4 = WPD_Netfolder::prepare_file($key);
check('ttl expired: refetched', file_get_contents($pf4['path']) === '%PDF приказ v3');
rename($ROOT . '/Ссылки', $ROOT . '/Ссылки-off');
$pf5 = WPD_Netfolder::prepare_file($key);
check('folder offline: stale copy served', is_array($pf5) && file_get_contents($pf5['path']) === '%PDF приказ v3');
rename($ROOT . '/Ссылки-off', $ROOT . '/Ссылки');
remove_filter('wpd_nf_cache_ttl', '__return_zero');
$bad = WPD_Netfolder::prepare_file('zz');
check('bad key → 404', is_wp_error($bad) && $bad->get_error_data()['status'] === 404);
check('unknown key → 404', is_wp_error(WPD_Netfolder::prepare_file(str_repeat('a', 24))));
nf_set(array_merge(WPD_Netfolder::get(), ['exts' => 'docx']));
$x = WPD_Netfolder::prepare_file($key);
check('ext no longer allowed → 403', is_wp_error($x) && $x->get_error_data()['status'] === 403);
nf_set(array_merge(WPD_Netfolder::get(), ['exts' => WPD_Netfolder::defaults()['exts'], 'enabled' => 0]));
check('disabled → 404', is_wp_error(WPD_Netfolder::prepare_file($key)));
nf_set(array_merge(WPD_Netfolder::get(), ['enabled' => 1]));
check('copy-mode keys not served', is_wp_error(WPD_Netfolder::prepare_file(WPD_Netfolder::key('Документы/Положение.pdf'))));
check('keys depend on path only', WPD_Netfolder::key('a/b.pdf') === WPD_Netfolder::key('a//b.pdf') && WPD_Netfolder::key('a/b.pdf') !== WPD_Netfolder::key('a/c.pdf'));
check('inline types exclude html/svg', !in_array('html', WPD_Netfolder::INLINE, true) && !in_array('svg', WPD_Netfolder::INLINE, true) && in_array('pdf', WPD_Netfolder::INLINE, true));
// Переход режима: copy → link — строка переделывается
$r = WPD_Netfolder::run('settings', ['dry' => true]);
$st = [];
foreach ($r['files'] as $f) { $st[$f['rel']] = $f['status']; }
check('mode switch marks rows changed', $st['Документы/Положение.pdf'] === 'changed');
update_option('wpd_nf_link_key', $key);

/* 10. Ёмкость списка */
$many = [];
for ($i = 0; $i < 49; $i++) { $many[] = ['title' => 'Д' . $i, 'url' => 'https://example.org/' . $i]; }
$r = WPD_Netfolder::run('items', ['sub' => 'Ссылки', 'items' => $many]);
check('capacity 50: one added, one skipped', count($r['rows']) === 50 && $r['report']['added'] === 1 && $r['report']['skipped'] === 1, json_encode($r['report']));
check('docs repeater max 50', WPD_Settings::field_map()['docs']['max'] === 50);
nf_set(array_merge(WPD_Netfolder::get(), ['mode' => 'copy']));

/* 11. Список страницы */
$pid = (int) wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Страница V', 'post_content' => '<p>V</p>']);
$created[] = $pid;
$r = WPD_Netfolder::import('page', ['post_id' => $pid, 'sub' => 'Ссылки', 'auto' => true]);
$p = WPD_Page_Editor::get($pid);
check('page: custom list saved', !is_wp_error($r) && $p['docs_mode'] === 'custom' && count($p['docs']) === 2 && $p['docs_nf'] === 'Ссылки' && $p['docs_nf_auto'] === 1, json_encode([$p['docs_mode'], count($p['docs']), $p['docs_nf'], $p['docs_nf_auto']]));
check('page: rows keep src', by_src($p['docs'], 'Ссылки/Приказ.pdf') !== null);
check('page: meta flag for cron', has(serialize(get_post_meta($pid, '_wpd_page', true)), 's:12:"docs_nf_auto";i:1;'));
check('page: response html rows', !empty(WPD_Netfolder::rest_import(tap_req(['target' => 'page', 'post_id' => $pid]))['html']));
check('page: second import keeps 2 rows (sub from page)', count(WPD_Page_Editor::get($pid)['docs']) === 2);
check('page defaults include nf keys', WPD_Page_Editor::defaults()['docs_nf'] === '' && WPD_Page_Editor::defaults()['docs_nf_auto'] === 0);
$in = WPD_Page_Editor::meta_to_input(WPD_Page_Editor::get($pid));
check('meta_to_input round trip', $in['docs_nf'] === 'Ссылки' && $in['docs_nf_auto'] === 1 && WPD_Page_Editor::sanitize($in, $pid, false)['docs_nf_auto'] === 1);
check('page sanitize cleans sub', WPD_Page_Editor::sanitize(['docs_nf' => '../x/./y', 'docs_nf_auto' => '1'])['docs_nf'] === 'x/y');
check('page lists: src, nf', in_array('src', WPD_Page_Editor::LISTS['docs'], true) && in_array('nf', WPD_Page_Editor::LISTS['docs'], true));
function tap_req(array $p): WP_REST_Request { $r = new WP_REST_Request('POST', '/wpd/v1/netfolder/import'); foreach ($p as $k => $v) { $r->set_param($k, $v); } return $r; }

/* 12. Блок и расписание */
$bid = (int) wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Блоки V',
    'post_content' => "<!-- wp:paragraph --><p>до</p><!-- /wp:paragraph -->\n<!-- wp:wpd/docs {\"title\":\"Из папки\",\"nfFolder\":\"Ссылки\",\"nfAuto\":true,\"items\":[]} /-->\n"
        . "<!-- wp:group --><div class=\"wp-block-group\"><!-- wp:wpd/docs {\"nfFolder\":\"Документы\",\"nfAuto\":true,\"items\":[]} /--></div><!-- /wp:group -->\n"
        . "<!-- wp:wpd/docs {\"nfFolder\":\"Ссылки\",\"items\":[]} /-->"]);
$created[] = $bid;
$defs = WPD_Blocks::definitions();
check('block attrs nfFolder/nfAuto', ($defs['docs']['attributes']['nfFolder']['type'] ?? '') === 'string' && ($defs['docs']['attributes']['nfAuto']['type'] ?? '') === 'boolean');
nf_set(array_merge(WPD_Netfolder::get(), ['auto_settings' => 1, 'schedule' => 'hourly']));
put('Документы/Новый приказ.pdf', '%PDF новый');
delete_transient('wpd_nf_lock');
$done = WPD_Netfolder::cron();
check('cron: settings, page, blocks', isset($done['settings'], $done['page:' . $pid], $done['blocks:' . $bid]), json_encode($done, JSON_UNESCAPED_UNICODE));
check('cron: settings got new file', by_src(docs(), 'Документы/Новый приказ.pdf') !== null);
$blocks = parse_blocks(get_post($bid)->post_content);
$b0 = $blocks[2]; $grp = $blocks[4]; $b2 = $blocks[6];
check('cron: top block filled', ($b0['blockName'] ?? '') === 'wpd/docs' && count($b0['attrs']['items']) === 2 && by_src($b0['attrs']['items'], 'Ссылки/Приказ.pdf') !== null, json_encode(array_column($blocks, 'blockName')));
check('cron: nested block filled', count($grp['innerBlocks'][0]['attrs']['items'] ?? []) >= 3);
check('cron: block without nfAuto untouched', ($b2['attrs']['items'] ?? null) === []);
check('cron: other content kept', has(get_post($bid)->post_content, '<p>до</p>') && has(get_post($bid)->post_content, '"title":"Из папки"'));
$mod = get_post($bid)->post_modified_gmt;
$rev = count(wp_get_post_revisions($bid));
WPD_Netfolder::cron();
check('cron: unchanged post not rewritten', count(wp_get_post_revisions($bid)) === $rev);
check('cron: lock released', get_transient('wpd_nf_lock') === false);
set_transient('wpd_nf_lock', 1, 60);
check('cron: skipped while locked', WPD_Netfolder::cron() === []);
delete_transient('wpd_nf_lock');
check('cron log entries', count(array_filter(get_option('wpd_nf_log'), function ($e) { return $e['source'] === 'cron'; })) >= 3);

/* 13. Расписание WP-Cron */
wp_clear_scheduled_hook('wpd_nf_sync');
WPD_Netfolder::reschedule();
check('schedule hourly', wp_get_schedule('wpd_nf_sync') === 'hourly');
update_option('wpd_netfolder', array_merge(WPD_Netfolder::get(), ['schedule' => 'daily']));
check('schedule follows option update', wp_get_schedule('wpd_nf_sync') === 'daily');
update_option('wpd_netfolder', array_merge(WPD_Netfolder::get(), ['enabled' => 0]));
check('disabled clears schedule', wp_next_scheduled('wpd_nf_sync') === false);
update_option('wpd_netfolder', array_merge(WPD_Netfolder::get(), ['enabled' => 1, 'schedule' => '']));
check('empty schedule → none', wp_next_scheduled('wpd_nf_sync') === false);
WPD_Netfolder::deactivate();

/* 14. REST и права */
$res = rest('POST', '/wpd/v1/netfolder/scan', ['target' => 'settings']);
check('rest scan as admin', $res->get_status() === 200 && isset($res->get_data()['files'][0]['size']), (string) $res->get_status());
$res = rest('POST', '/wpd/v1/netfolder/test', ['form' => ['driver' => 'mount', 'mount' => $ROOT, 'sub' => 'Ссылки']]);
check('rest test with form values', $res->get_status() === 200 && $res->get_data()['ok'] === true && $res->get_data()['files'] === 2, json_encode($res->get_data(), JSON_UNESCAPED_UNICODE));
$res = rest('POST', '/wpd/v1/netfolder/import', ['target' => 'items', 'post_id' => $bid, 'sub' => 'Ссылки', 'items' => wp_json_encode([])]);
check('rest import items (json string)', $res->get_status() === 200 && count($res->get_data()['rows']) === 2 && $res->get_data()['message'] !== '');
$res = rest('POST', '/wpd/v1/netfolder/import', ['target' => 'settings', 'sub' => 'Нет такой']);
check('rest error → 400 with message', $res->get_status() === 400 && $res->as_error()->get_error_message() !== '');
$ed = wp_insert_user(['user_login' => 'wpd_v_editor', 'user_pass' => wp_generate_password(), 'role' => 'editor', 'user_email' => 'v-ed@example.org']);
$sub_u = wp_insert_user(['user_login' => 'wpd_v_sub', 'user_pass' => wp_generate_password(), 'role' => 'subscriber', 'user_email' => 'v-sub@example.org']);
wp_set_current_user($ed);
check('editor: settings forbidden', rest('POST', '/wpd/v1/netfolder/scan', ['target' => 'settings'])->get_status() === 403);
check('editor: test forbidden', rest('POST', '/wpd/v1/netfolder/test', [])->get_status() === 403);
check('editor: page allowed', rest('POST', '/wpd/v1/netfolder/scan', ['target' => 'page', 'post_id' => $pid])->get_status() === 200);
wp_set_current_user($sub_u);
check('subscriber: page forbidden', rest('POST', '/wpd/v1/netfolder/scan', ['target' => 'page', 'post_id' => $pid])->get_status() === 403);
check('subscriber: items forbidden', rest('POST', '/wpd/v1/netfolder/import', ['target' => 'items'])->get_status() === 403);
wp_set_current_user(0);
check('anonymous forbidden', rest('POST', '/wpd/v1/netfolder/scan', ['target' => 'items'])->get_status() === 401);
wp_set_current_user(1);
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user($ed);
wp_delete_user($sub_u);

/* 15. Поля строк, панель, вкладка, данные редактора */
$f = WPD_Settings::field_map()['docs']['fields'];
check('docs sub-fields src/nf hidden', ($f['src']['type'] ?? '') === 'hidden' && ($f['nf']['type'] ?? '') === 'hidden');
$clean = WPD_Settings::sanitize_rows([['title' => 'A', 'url' => 'https://e.org/a', 'src' => "x/Форма <заявки> 100%.pdf\n", 'nf' => '1'], ['src' => 'only/src.pdf', 'nf' => '1']], 'docs');
check('sanitize_rows: hidden kept as is (no tag stripping), does not make row non-empty', count($clean) === 1 && $clean[0]['src'] === 'x/Форма <заявки> 100%.pdf' && $clean[0]['nf'] === '1', json_encode($clean, JSON_UNESCAPED_UNICODE));
$esc = WPD_Page_Editor::row_html('docs', '0', ['title' => 'T', 'src' => 'a/<b>x</b>.pdf']);
check('src escaped on output', has($esc, 'value="a/&lt;b&gt;x&lt;/b&gt;.pdf"') && !has($esc, '<b>x</b>'));
check('renderer row keys include src', in_array('src', WPD_Renderer::row_keys('docs'), true));
$html = WPD_Page_Editor::row_html('docs', '3', ['title' => 'T', 'url' => 'u', 'src' => 'Ссылки/Приказ.pdf', 'nf' => '1']);
check('page row: hidden inputs + note', has($html, 'name="wpd_page[docs][3][src]" value="Ссылки/Приказ.pdf" data-wpd-field="src"') && has($html, 'name="wpd_page[docs][3][nf]" value="1"')
    && has($html, 'Из сетевой папки: Ссылки/Приказ.pdf — <strong>нет в папке</strong>'));
check('page row without src: no note', !has(WPD_Page_Editor::row_html('docs', '0', ['title' => 'T']), 'Из сетевой папки'));
ob_start();
WPD_Page_Editor::meta_box_html(get_post($pid));
$mb = ob_get_clean();
check('page panel: netfolder box', has($mb, 'data-wpd-nf-page') && has($mb, 'name="wpd_page[docs_nf]" value="Ссылки"') && (bool) preg_match('/name="wpd_page\[docs_nf_auto\]" value="1"\s*checked/', $mb));
nf_set(array_merge(WPD_Netfolder::get(), ['enabled' => 0]));
ob_start();
WPD_Page_Editor::meta_box_html(get_post($pid));
check('page panel: no box when disabled', !has(ob_get_clean(), 'data-wpd-nf-page'));
nf_set(array_merge(WPD_Netfolder::get(), ['enabled' => 1, 'pass' => WPD_Netfolder::encrypt('секрет-V'), 'user' => 'olymp']));
ob_start();
WPD_Netfolder::render_box('docs');
$box = ob_get_clean();
check('tab box: form to options.php with own group', has($box, 'action="options.php"') && (has($box, "name='option_page' value='wpd_netfolder_group'") || has($box, 'name="option_page" value="wpd_netfolder_group"')));
check('tab box: 19 fields (with pass_clear)', count(array_unique(preg_match_all('/name="wpd_netfolder\[(\w+)\]"/', $box, $mm) ? $mm[1] : [])) === 19, (string) count(array_unique($mm[1])));
check('tab box: password never printed', !has($box, 'секрет-V') && !has($box, WPD_Netfolder::get()['pass']) && has($box, '(сохранён)'));
check('tab box: tools and env', has($box, 'data-wpd-nf-test') && has($box, 'data-wpd-nf-scan') && has($box, 'data-wpd-nf-import') && has($box, 'PHP-расширение smbclient'));
check('tab box: log', has($box, 'Журнал импорта') && has($box, 'по расписанию'));
ob_start();
WPD_Netfolder::render_box('faq');
check('tab box only on docs tab', ob_get_clean() === '');
$edd = WPD_Netfolder::editor_data();
check('editor data', $edd['enabled'] === true && $edd['mode'] === 'copy' && array_key_exists('schedule', $edd) && array_key_exists('root', $edd));

/* 16. Ресурсы, справка, удаление */
$dir = WP_PLUGIN_DIR . '/wp-olympiad-design/';
$jsn = file_get_contents($dir . 'assets/js/wpd-netfolder.js');
check('netfolder js', has($jsn, "'/wpd/v1/netfolder/'") && has($jsn, 'data-wpd-nf-page') && has($jsn, 'only'));
$jsb = file_get_contents($dir . 'assets/js/wpd-blocks.js');
check('blocks js: panel and import', has($jsb, 'function NetfolderPanel') && has($jsb, "'/wpd/v1/netfolder/import'") && has($jsb, 'nfAuto'));
check('blocks script depends on api-fetch', in_array('wp-api-fetch', wp_scripts()->registered['wpd-blocks']->deps ?? ['wp-api-fetch'], true));
$un = file_get_contents($dir . 'uninstall.php');
check('uninstall cleans netfolder', has($un, "'wpd_netfolder'") && has($un, "'wpd_nf_index'") && has($un, "wp_clear_scheduled_hook('wpd_nf_sync')") && has($un, "'_wpd_nf_src'") && has($un, 'wpd-nf-cache'));
ob_start();
$m = new ReflectionMethod('WPD_Settings', 'render_help');
$m->setAccessible(true);
$m->invoke(null);
$help = ob_get_clean();
check('help mentions network folder', has($help, 'Сетевая папка'));
$readme = file_get_contents($dir . 'README.md');
check('readme section', has($readme, 'Сетевая папка') && has($readme, 'smbclient'));

/* Уборка */
foreach ($created as $id) { wp_delete_post($id, true); }
foreach (nf_atts() as $id) { wp_delete_attachment($id, true); }
$cache = wp_upload_dir(null, false)['basedir'] . '/wpd-nf-cache';
rrmdir($cache);
rrmdir($ROOT);
delete_option('wpd_nf_index');
delete_option('wpd_nf_log');
delete_option('wpd_nf_link_key');
wp_clear_scheduled_hook('wpd_nf_sync');
if ($nf_backup === null) { delete_option('wpd_netfolder'); } else { update_option('wpd_netfolder', $nf_backup); }
set_opts(is_array($backup) ? $backup : []);
check('cleanup', nf_atts() === [] && !is_dir($ROOT));

echo implode("\n", $results) . "\n";
echo "TOTAL: " . count($results) . ", FAILED: $fails\n";
