<?php
// Фаза G (1.4.0, обновлена в 1.5.0 и 1.6.0): пункт меню «Олимпиада» и вкладки настроек — структура, адреса, санитизация по вкладке.
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

check('version >= 1.5.0', version_compare(WPD_VERSION, '1.5.0', '>='));

/* 1. Вкладки */
$tabs = WPD_Settings::tabs();
check('13 tabs', count($tabs) === 13, (string) count($tabs));
check('first tab = design', array_key_first($tabs) === 'design');
check('help tab has no sections', $tabs['help']['sections'] === []);
$covered = [];
foreach ($tabs as $t) { $covered = array_merge($covered, $t['sections']); }
sort($covered);
$sections = array_keys(WPD_Settings::sections());
sort($sections);
check('every section on exactly one tab', $covered === $sections, implode(',', $covered));
$per_tab = [];
foreach ($tabs as $id => $t) {
    $per_tab[$id] = count(array_filter(WPD_Settings::field_map(), function ($f) use ($t) { return in_array($f['section'], $t['sections'], true); }));
}
check('fields per tab (1.6.0)',
    $per_tab === ['design' => 29, 'hero' => 32, 'features' => 12, 'stats' => 1, 'docs' => 2, 'faq' => 2, 'slider' => 8, 'buttons' => 1, 'cards' => 2, 'notice' => 8, 'news' => 5, 'contacts' => 10, 'help' => 0], json_encode($per_tab));
check('help tab is last', array_key_last($tabs) === 'help');

/* 2. Адреса */
check('url() = admin.php?page=wpd-settings', WPD_Settings::url() === admin_url('admin.php?page=wpd-settings'));
check('url(stats) has tab', WPD_Settings::url('stats') === admin_url('admin.php?page=wpd-settings&tab=stats'));
check('url(unknown) without tab', WPD_Settings::url('evil') === admin_url('admin.php?page=wpd-settings'));
$_GET = [];
check('current_tab default = design', WPD_Settings::current_tab() === 'design');
$_GET = ['tab' => 'faq'];
check('current_tab from GET', WPD_Settings::current_tab() === 'faq');
$_GET = ['tab' => '<script>'];
check('current_tab invalid -> design', WPD_Settings::current_tab() === 'design');
$_GET = [];

/* 3. Санитизация по вкладке: поля других вкладок не трогаются */
update_option('wpd_settings', array_merge(WPD_Settings::defaults(), [
    'primary' => '#0f766e', 'countdown_enabled' => 1, 'faq_search' => 1, 'stats_animate' => 1,
    'hide_title' => 1, 'stats' => "1|старое", 'badge' => 'Метка',
]));
WPD_Settings::flush_cache();

$s = WPD_Settings::sanitize(['_tab' => 'stats', 'stats' => "5|новое"]);
check('tab stats: stats updated', $s['stats'] === '5|новое');
check('tab stats: color kept', $s['primary'] === '#0f766e');
check('tab stats: checkboxes of other tabs kept', $s['countdown_enabled'] === 1 && $s['faq_search'] === 1 && $s['hide_title'] === 1);
check('tab stats: hero text kept', $s['badge'] === 'Метка');
check('_tab not stored', !array_key_exists('_tab', $s));
check('all 112 keys returned', count($s) === 112);

$s = WPD_Settings::sanitize(['_tab' => 'features', 'faq_search' => '1', 'countdown_date' => '2031-01-01T10:00']);
check('tab features: unchecked checkbox -> 0', $s['countdown_enabled'] === 0 && $s['stats_animate'] === 0);
check('tab features: checked kept', $s['faq_search'] === 1);
check('tab features: date normalized', $s['countdown_date'] === '2031-01-01 10:00');
check('tab features: hide_title (design tab) kept', $s['hide_title'] === 1);

$s = WPD_Settings::sanitize(['_tab' => 'design', 'primary' => 'javascript:x']);
check('tab design: bad color -> default', $s['primary'] === '#1d4ed8');
check('tab design: hide_title omitted -> 0', $s['hide_title'] === 0);
check('tab design: stats kept', $s['stats'] === '1|старое');

$s = WPD_Settings::sanitize(['_tab' => 'bogus', 'primary' => '#000000']);
check('unknown tab -> full mode (others default)', $s['stats'] === WPD_Settings::field_map()['stats']['default'] && $s['countdown_enabled'] === 0);
$s = WPD_Settings::sanitize(['_tab' => ['x'], 'primary' => '#000000']);
check('array _tab -> full mode', $s['badge'] === WPD_Settings::field_map()['badge']['default']);

// Через update_option (как при сохранении формы), в том числе первый add_option (двойной вызов санитизации).
// Санитизация опции регистрируется на admin_init, поэтому вне админки регистрируем её явно.
WPD_Settings::register();
delete_option('wpd_settings');
WPD_Settings::flush_cache();
update_option('wpd_settings', ['_tab' => 'hero', 'badge' => 'Финал', 'hero_style' => 'light']);
$saved = get_option('wpd_settings');
check('first save through tab: badge stored', ($saved['badge'] ?? '') === 'Финал');
check('first save through tab: others defaults', ($saved['primary'] ?? '') === '#1d4ed8' && count($saved) === 112);
update_option('wpd_settings', ['_tab' => 'docs', 'docs' => 'A|https://example.com/a.pdf', 'docs_title' => 'Файлы']);
$saved = get_option('wpd_settings');
check('second save other tab: hero kept', $saved['badge'] === 'Финал' && $saved['hero_style'] === 'light');
check('second save other tab: docs stored', $saved['docs_title'] === 'Файлы');

/* 4. Меню */
wp_set_current_user(1);
$GLOBALS['menu'] = [];
$GLOBALS['submenu'] = [];
$GLOBALS['_registered_pages'] = [];
WPD_Settings::add_menu();
$top = array_values(array_filter($GLOBALS['menu'], function ($m) { return ($m[2] ?? '') === 'wpd-settings'; }));
check('top-level menu "Олимпиада"', $top && $top[0][0] === 'Олимпиада');
check('menu icon', $top && $top[0][6] === WPD_Settings::MENU_ICON);
$subs = $GLOBALS['submenu']['wpd-settings'] ?? [];
check('13 submenu items', count($subs) === 13, (string) count($subs));
check('first submenu = page itself', ($subs[0][2] ?? '') === 'wpd-settings');
check('submenu for stats tab', in_array('wpd-settings&tab=stats', array_column($subs, 2), true));
check('settings no longer under Settings menu', !isset($GLOBALS['submenu']['options-general.php']) || !in_array('wpd-settings', array_column($GLOBALS['submenu']['options-general.php'], 2), true));
$_GET = ['page' => 'wpd-settings', 'tab' => 'docs'];
check('submenu_file highlights tab', WPD_Settings::submenu_file(null) === 'wpd-settings&tab=docs');
$_GET = ['page' => 'wpd-settings'];
check('submenu_file first tab = page', WPD_Settings::submenu_file(null) === 'wpd-settings');
$_GET = ['page' => 'other'];
check('submenu_file other page untouched', WPD_Settings::submenu_file('x') === 'x');
$_GET = [];

/* 5. Ссылки на настройки из редактора и плагинов */
$links = WPD_Settings::action_links(['deactivate' => '<a>Деактивировать</a>']);
check('plugin action link first', strpos(reset($links), 'admin.php?page=wpd-settings') !== false);
$inline = implode('', (array) wp_scripts()->get_data('wpd-blocks', 'before'));
check('blocks settingsUrl = new page', strpos($inline, 'admin.php?page=wpd-settings') !== false && strpos($inline, 'options-general') === false);

// Рабочие настройки для следующих фаз
update_option('wpd_settings', []);

echo implode("\n", $results) . "\n";
echo "\nTOTAL: " . count($results) . ", FAILED: $fails\n";
