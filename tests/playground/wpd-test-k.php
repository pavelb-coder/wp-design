<?php
// Фаза K (1.7.0): название организации со ссылкой в контактах.
// PHP-часть: поля и санитизация, вывод (ссылка, новая вкладка для другого сайта, экранирование), шорткод, блок, панель страницы. В конце удаляет опцию.
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
    // В обход санитизации: иначе массив без _tab считается полной формой
    global $wp_filter;
    $saved = $wp_filter['sanitize_option_wpd_settings'] ?? null;
    unset($wp_filter['sanitize_option_wpd_settings']);
    update_option('wpd_settings', $values);
    if ($saved !== null) {
        $wp_filter['sanitize_option_wpd_settings'] = $saved;
    }
    WPD_Settings::flush_cache();
}
function ct(array $args): string {
    return WPD_Renderer::contacts_html(WPD_Settings::get(), $args);
}

wp_set_current_user(1);
WPD_Settings::register();
delete_option('wpd_settings');
WPD_Settings::flush_cache();

/* 1. Поля */
check('version >= 1.7.0', version_compare(WPD_VERSION, '1.7.0', '>='));
$map  = WPD_Settings::field_map();
$keys = array_keys($map);
check('field contact_org (text) on contacts tab', ($map['contact_org']['type'] ?? '') === 'text' && $map['contact_org']['section'] === 'contacts');
check('field contact_org_url (url) on contacts tab', ($map['contact_org_url']['type'] ?? '') === 'url' && $map['contact_org_url']['section'] === 'contacts');
check('order: title, org, org url, address', array_slice($keys, array_search('contacts_title', $keys, true), 4) === ['contacts_title', 'contact_org', 'contact_org_url', 'contact_address']);
check('defaults empty', WPD_Settings::get()['contact_org'] === '' && WPD_Settings::get()['contact_org_url'] === '');
check('67 fields, contacts tab 10', count($map) === 67 && count(array_filter($map, function ($f) { return $f['section'] === 'contacts'; })) === 10);

/* 2. Санитизация */
$s = WPD_Settings::sanitize(['_tab' => 'contacts', 'contact_org' => '  <b>Центр</b> «Юниор» ', 'contact_org_url' => 'junior.example.org/about']);
check('org: tags stripped, trimmed', $s['contact_org'] === 'Центр «Юниор»', $s['contact_org']);
check('org url: scheme added', $s['contact_org_url'] === 'http://junior.example.org/about', $s['contact_org_url']);
$s = WPD_Settings::sanitize(['_tab' => 'contacts', 'contact_org' => 'X', 'contact_org_url' => 'javascript:alert(1)']);
check('org url: javascript: dropped', $s['contact_org_url'] === '', $s['contact_org_url']);
set_opts(['contact_org' => 'Хранится', 'contact_org_url' => 'https://keep.example.org/']);
$s = WPD_Settings::sanitize(['_tab' => 'stats', 'stats' => '1|a']);
check('other tab save keeps org and link', $s['contact_org'] === 'Хранится' && $s['contact_org_url'] === 'https://keep.example.org/');
set_opts([]);

/* 3. Вывод */
$h = ct(['org' => 'Центр «Юниор»', 'org_url' => 'https://junior.example.org/', 'phone' => '+7 391 000']);
check('org row first in list', has($h, '<ul class="wpd-contacts__list"><li class="wpd-contacts__org">'), $h);
check('org row has building icon', has($h, 'dashicons-building'));
check('external link -> new tab', has($h, '<a href="https://junior.example.org/" target="_blank" rel="noopener noreferrer">Центр «Юниор»</a>'), $h);
$own = home_url('/about/');
$h = ct(['org' => 'Наш сайт', 'org_url' => $own]);
check('own site link -> same tab', has($h, '<a href="' . esc_url($own) . '">Наш сайт</a>'), $h);
$h = ct(['org' => 'Относительная', 'org_url' => '/about/']);
check('relative link -> same tab', has($h, '<a href="/about/">Относительная</a>'), $h);
$host = wp_parse_url(home_url(), PHP_URL_HOST);
check('www. of own host -> same tab', !WPD_Renderer::is_external('https://www.' . $host . '/x') && WPD_Renderer::is_external('https://sub.' . $host . '/x'));
$h = ct(['org' => 'Без ссылки', 'phone' => '1']);
check('no url -> plain text', has($h, '<li class="wpd-contacts__org"><span class="wpd-contacts__icon dashicons dashicons-building" aria-hidden="true"></span><span>Без ссылки</span></li>'), $h);
$h = ct(['org' => 'Опасная', 'org_url' => 'javascript:alert(1)']);
check('javascript: url -> no link', has($h, '<span>Опасная</span>') && !has($h, 'javascript:'), $h);
$h = ct(['org' => '<script>x()</script>Орг', 'org_url' => 'https://e.example/"onmouseover="x']);
check('org name escaped', !has($h, '<script>') && has($h, '&lt;script&gt;'));
check('org url attribute safe', !has($h, '"onmouseover="'), $h);
check('only org -> block shown', has(ct(['org' => 'Только название']), 'wpd-contacts__org'));
check('nothing -> no output', ct([]) === '');
check('no org -> no org row', !has(ct(['phone' => '1']), 'wpd-contacts__org'));

/* 4. Шорткод и настройки */
set_opts(['contact_org' => 'Организация из настроек', 'contact_org_url' => 'https://org.example.com/']);
$sc = do_shortcode('[wpd_contacts]');
check('shortcode: org from settings with link', has($sc, '<a href="https://org.example.com/" target="_blank" rel="noopener noreferrer">Организация из настроек</a>'), $sc);
check('shortcode: dashicons enqueued', wp_style_is('dashicons', 'enqueued'));

/* 5. Блок */
check('block attrs: org, orgUrl registered', isset(WP_Block_Type_Registry::get_instance()->get_registered('wpd/contacts')->attributes['org'], WP_Block_Type_Registry::get_instance()->get_registered('wpd/contacts')->attributes['orgUrl']));
check('block attrs: no server defaults', !array_key_exists('default', WP_Block_Type_Registry::get_instance()->get_registered('wpd/contacts')->attributes['org']));
$r = WPD_Blocks::render_contacts([]);
check('block: falls back to settings', has($r, 'Организация из настроек') && has($r, 'https://org.example.com/'));
$r = WPD_Blocks::render_contacts(['org' => 'Из блока', 'orgUrl' => 'https://block.example.net/']);
check('block: attributes win', has($r, '<a href="https://block.example.net/" target="_blank" rel="noopener noreferrer">Из блока</a>') && !has($r, 'Организация из настроек'), $r);
$r = WPD_Blocks::render_contacts(['org' => 'Без ссылки в блоке', 'orgUrl' => '']);
check('block: empty orgUrl attribute -> no link', has($r, '<span>Без ссылки в блоке</span>'), $r);
$r = WPD_Blocks::render_contacts(['org' => '']);
check('block: empty org attribute -> no org row', !has($r, 'wpd-contacts__org'));
$r = render_block(['blockName' => 'wpd/contacts', 'attrs' => ['org' => 'Через render_block'], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []]);
check('block: render_block', has($r, 'Через render_block') && has($r, 'wpd-page--contacts'));

/* 6. Панель страницы и справка */
set_opts(['contact_org' => 'Только организация']);
$pid = wp_insert_post(['post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'K panel', 'meta_input' => ['_wpd_k_test' => 1]]);
ob_start();
WPD_Page_Editor::meta_box_html(get_post($pid));
$panel = ob_get_clean();
check('panel note: contacts from settings when only org set', !has($panel, 'не заполнены в общих настройках') && has($panel, 'Контакты'));
wp_delete_post($pid, true);
ob_start();
$_GET = ['page' => 'wpd-settings', 'tab' => 'help'];
WPD_Settings::render_page();
$help = ob_get_clean();
$_GET = [];
check('help: contacts shortcode mentions organization', has($help, 'название организации со ссылкой'));

delete_option('wpd_settings');
echo implode("\n", $results), "\n\nTOTAL: ", count($results), ", FAILED: ", $fails, "\n";
