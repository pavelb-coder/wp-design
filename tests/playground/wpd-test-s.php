<?php
// Фаза S (2.5.0): размеры иконок — плитки «Кнопки-ссылки», контакты, кнопки главного блока, значки документов.
// Поля и их место, санитизация, CSS-переменные, вывод шорткодов и блоков (атрибут блока важнее страницы, страница —
// общих настроек), группы панели страницы, данные редактора, мета-бокс, CSS, JS, справка, версия.
// В конце удаляет свои записи и опцию.
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
function save_tab(string $tab, array $fields): array {
    $out = WPD_Settings::sanitize(array_merge($fields, ['_tab' => $tab]));
    set_opts($out);
    return $out;
}
function blk(string $name, array $attrs = []): string {
    return render_block(['blockName' => 'wpd/' . $name, 'attrs' => $attrs, 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []]);
}
/** style="" корневого .wpd-page в разметке. */
function root_style(string $html): string {
    return preg_match('/<div class="wpd-page[^"]*" style="([^"]*)"/', $html, $m) ? html_entity_decode($m[1]) : '';
}
function new_page(string $title, string $content = '<p>Текст</p>'): int {
    $id = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => $content]);
    update_post_meta($id, '_wpd_s_test', 1);
    update_post_meta($id, '_wpd_enabled', 1);
    return (int) $id;
}

wp_set_current_user(1);
WPD_Settings::register();
delete_option('wpd_settings');
WPD_Settings::flush_cache();
WPD_Settings::set_page(0);
$map  = WPD_Settings::field_map();
$keys = array_keys($map);
$sizes = WPD_Settings::ICON_SIZES;

/* 1. Поля */
$expect = [
    'buttons_icon_size'     => ['buttons', 96, '--wpd-icon-qbtn', 16, 44],
    'contacts_icon_size'    => ['contacts', 64, '--wpd-icon-contacts', 12, 24],
    'hero_button_icon_size' => ['hero', 48, '--wpd-icon-btn', 12, 20],
    'docs_icon_size'        => ['docs', 96, '--wpd-icon-doc', 24, 44],
];
foreach ($expect as $key => $e) {
    $f = $map[$key] ?? [];
    check("field $key: number in section {$e[0]}, default 0, 0…{$e[1]}",
        ($f['type'] ?? '') === 'number' && $f['section'] === $e[0] && $f['default'] === 0 && $f['min'] === 0 && $f['max'] === $e[1]);
    check("field $key: description mentions default size", has((string) ($f['desc'] ?? ''), '0 — по умолчанию') && has((string) $f['desc'], $e[4] . ' px'));
    check("ICON_SIZES $key: var, bounds, default", ($sizes[$key]['var'] ?? '') === $e[2] && $sizes[$key]['min'] === $e[3] && $sizes[$key]['max'] === $e[1] && $sizes[$key]['default'] === $e[4]);
}
check('ICON_SIZES: exactly four', array_keys($sizes) === array_keys($expect));
check('120 fields', count($map) === 120, (string) count($map));
check('hero size right after second button icon', array_search('hero_button_icon_size', $keys, true) === array_search('hero_button2_icon', $keys, true) + 1);
check('docs size after docs list', array_search('docs_icon_size', $keys, true) === array_search('docs', $keys, true) + 1);
check('buttons size after buttons list', array_search('buttons_icon_size', $keys, true) === array_search('buttons', $keys, true) + 1);
check('contacts size last on contacts tab', array_search('contacts_icon_size', $keys, true) === array_search('contact_logo_url', $keys, true) + 1);
$defaults = WPD_Settings::defaults();
check('defaults: all 0', $defaults['buttons_icon_size'] === 0 && $defaults['contacts_icon_size'] === 0 && $defaults['hero_button_icon_size'] === 0 && $defaults['docs_icon_size'] === 0);

/* 2. Санитизация */
$s = save_tab('buttons', ['buttons_icon_size' => '30']);
check('sanitize: 30 kept', $s['buttons_icon_size'] === 30);
$s = save_tab('buttons', ['buttons_icon_size' => '500']);
check('sanitize: above max -> max', $s['buttons_icon_size'] === 96);
$s = save_tab('buttons', ['buttons_icon_size' => '-5']);
check('sanitize: negative -> 0', $s['buttons_icon_size'] === 0);
$s = save_tab('buttons', ['buttons_icon_size' => 'abc']);
check('sanitize: text -> 0', $s['buttons_icon_size'] === 0);
save_tab('contacts', ['contacts_icon_size' => '40']);
$s = save_tab('docs', ['docs_icon_size' => '60']);
check('sanitize: other tabs kept', $s['contacts_icon_size'] === 40 && $s['docs_icon_size'] === 60);
$s = save_tab('hero', ['hero_button_icon_size' => '99']);
check('sanitize: hero size max 48', $s['hero_button_icon_size'] === 48);
delete_option('wpd_settings');
WPD_Settings::flush_cache();

/* 3. CSS-переменные */
check('icon_size_css: 0 -> empty', WPD_Settings::icon_size_css('buttons_icon_size', 0) === '');
check('icon_size_css: 30', WPD_Settings::icon_size_css('buttons_icon_size', 30) === '30px');
check('icon_size_css: below min -> min', WPD_Settings::icon_size_css('buttons_icon_size', 5) === '16px' && WPD_Settings::icon_size_css('docs_icon_size', 10) === '24px');
check('icon_size_css: above max -> max', WPD_Settings::icon_size_css('contacts_icon_size', 500) === '64px');
check('icon_size_css: text and unknown key -> empty', WPD_Settings::icon_size_css('buttons_icon_size', 'x') === '' && WPD_Settings::icon_size_css('nope', 30) === '');
$vars = WPD_Settings::style_vars();
check('style_vars: no icon vars by default', !preg_grep('/^--wpd-icon-/', array_keys($vars)));
set_opts(['buttons_icon_size' => 30, 'contacts_icon_size' => 40, 'hero_button_icon_size' => 28, 'docs_icon_size' => 60]);
$vars = WPD_Settings::style_vars();
check('style_vars: four icon vars', ($vars['--wpd-icon-qbtn'] ?? '') === '30px' && ($vars['--wpd-icon-contacts'] ?? '') === '40px'
    && ($vars['--wpd-icon-btn'] ?? '') === '28px' && ($vars['--wpd-icon-doc'] ?? '') === '60px', json_encode($vars));
check('style_attr: contains icon vars', has(WPD_Settings::style_attr(), '--wpd-icon-qbtn:30px;') && has(WPD_Settings::style_attr(), '--wpd-icon-doc:60px;'));
set_opts(['buttons_icon_size' => 3]);
check('style_vars: saved too small value clamped', (WPD_Settings::style_vars()['--wpd-icon-qbtn'] ?? '') === '16px');

/* 4. Шорткоды */
set_opts(['buttons_icon_size' => 30, 'contacts_icon_size' => 40, 'contact_phone' => '+7 000', 'docs' => "Положение|https://example.com/a.pdf", 'docs_icon_size' => 60,
    'hero_button_icon' => 'download', 'hero_button_url' => 'https://example.com/', 'hero_button_icon_size' => 28]);
$sc = do_shortcode('[wpd_buttons]');
check('shortcode buttons: var on container', has(root_style($sc), '--wpd-icon-qbtn:30px'), root_style($sc));
check('shortcode buttons: icons in markup', has($sc, 'wpd-qbtn__icon dashicons'));
check('shortcode contacts: var', has(root_style(do_shortcode('[wpd_contacts]')), '--wpd-icon-contacts:40px'));
check('shortcode docs: var', has(root_style(do_shortcode('[wpd_docs]')), '--wpd-icon-doc:60px'));
$hero = do_shortcode('[wpd_hero]');
check('shortcode hero: var and button icon', has(root_style($hero), '--wpd-icon-btn:28px') && has($hero, 'wpd-btn__icon dashicons dashicons-download'));

/* 5. Блоки: атрибут сильнее настроек */
$b = blk('buttons');
check('block buttons: no attribute -> settings', has(root_style($b), '--wpd-icon-qbtn:30px'), root_style($b));
$b = blk('buttons', ['iconSize' => 64]);
check('block buttons: attribute 64', has(root_style($b), '--wpd-icon-qbtn:64px') && !has(root_style($b), '--wpd-icon-qbtn:30px'));
check('block buttons: attribute 0 -> settings', has(root_style(blk('buttons', ['iconSize' => 0])), '--wpd-icon-qbtn:30px'));
check('block buttons: text attribute -> settings', has(root_style(blk('buttons', ['iconSize' => 'big'])), '--wpd-icon-qbtn:30px'));
check('block buttons: attribute clamped to 96', has(root_style(blk('buttons', ['iconSize' => 400])), '--wpd-icon-qbtn:96px'));
check('block buttons: wrapper classes kept', has(blk('buttons', ['iconSize' => 64]), 'wpd-page--block wpd-page--buttons'));
check('block contacts: attribute 36', has(root_style(blk('contacts', ['iconSize' => 36])), '--wpd-icon-contacts:36px'));
check('block docs: attribute 80', has(root_style(blk('docs', ['iconSize' => 80])), '--wpd-icon-doc:80px'));
check('block hero: buttonIconSize 32', has(root_style(blk('hero', ['buttonIconSize' => 32])), '--wpd-icon-btn:32px'));
check('block buttons: other sizes untouched', has(root_style(blk('buttons', ['iconSize' => 64])), '--wpd-icon-contacts:40px'));
check('with_icon_size: helper', WPD_Blocks::with_icon_size(['buttons_icon_size' => 30], ['iconSize' => 50], 'iconSize', 'buttons_icon_size')['buttons_icon_size'] === 50
    && WPD_Blocks::with_icon_size(['buttons_icon_size' => 30], [], 'iconSize', 'buttons_icon_size')['buttons_icon_size'] === 30
    && WPD_Blocks::with_icon_size(['buttons_icon_size' => 30], ['iconSize' => -4], 'iconSize', 'buttons_icon_size')['buttons_icon_size'] === 30);
$reg = WP_Block_Type_Registry::get_instance();
$ok = true;
foreach (['buttons' => 'iconSize', 'contacts' => 'iconSize', 'docs' => 'iconSize', 'hero' => 'buttonIconSize'] as $slug => $attr) {
    $t = $reg->get_registered('wpd/' . $slug);
    $a = $t ? ($t->attributes[$attr] ?? null) : null;
    $ok = $ok && $a && $a['type'] === 'integer' && !array_key_exists('default', $a);
}
check('block attributes registered as integer without server default', $ok);

/* 6. Данные редактора */
$d = WPD_Blocks::icon_sizes_data();
check('icon_sizes_data: four blocks', array_keys($d) === ['buttons', 'contacts', 'hero', 'docs']);
check('icon_sizes_data: buttons', $d['buttons'] === ['attr' => 'iconSize', 'var' => '--wpd-icon-qbtn', 'min' => 16, 'max' => 96, 'default' => 44, 'setting' => 30], json_encode($d['buttons']));
check('icon_sizes_data: hero attr', $d['hero']['attr'] === 'buttonIconSize' && $d['hero']['setting'] === 28);
$before = implode("\n", (array) wp_scripts()->get_data('wpd-blocks', 'before'));
check('editor data: iconSizes in wpdBlocks', has($before, '"iconSizes"') && has($before, '"--wpd-icon-qbtn"'));
check('editor data: block attribute defaults in client definitions', has($before, '"iconSize":{"type":"integer","default":'));

/* 7. Своё для страницы */
check('panel group buttons has size', in_array('buttons_icon_size', WPD_Page_Editor::group_keys('buttons'), true));
check('panel group contacts has size', in_array('contacts_icon_size', WPD_Page_Editor::group_keys('contacts'), true));
check('panel group hero has size', in_array('hero_button_icon_size', WPD_Page_Editor::group_keys('hero'), true));
check('panel group page has docs size', in_array('docs_icon_size', WPD_Page_Editor::group_keys('page'), true));
check('group descriptions mention icon size', has(WPD_Page_Editor::groups()['buttons']['desc'], 'размер иконок') && has(WPD_Page_Editor::groups()['page']['desc'], 'значков документов'));
$pg = new_page('S page', '<!-- wp:wpd/buttons /--><!-- wp:wpd/buttons {"iconSize":70} /-->');
$form = ['order' => array_keys(WPD_Page_Editor::blocks()), 'show' => ['buttons' => 1, 'contacts' => 1, 'docs' => 1, 'content' => 1],
    'ovg' => ['buttons' => 1, 'contacts' => 1, 'page' => 1], 'ov' => ['buttons_icon_size' => '50', 'contacts_icon_size' => '20', 'docs_icon_size' => '72', 'palette' => 'custom']];
WPD_Page_Editor::store($pg, WPD_Page_Editor::sanitize($form, $pg));
$html = WPD_Renderer::page_html($pg, '');
$st = root_style($html);
check('page: own sizes on page container', has($st, '--wpd-icon-qbtn:50px') && has($st, '--wpd-icon-contacts:20px') && has($st, '--wpd-icon-doc:72px'), $st);
check('page: hero size from global', has($st, '--wpd-icon-btn:28px'));
WPD_Settings::set_page($pg);
check('page context: block without attribute -> page size', has(root_style(blk('buttons')), '--wpd-icon-qbtn:50px'));
check('page context: block attribute beats page', has(root_style(blk('buttons', ['iconSize' => 70])), '--wpd-icon-qbtn:70px'));
$GLOBALS['post'] = get_post($pg);
wp_scripts()->registered['wpd-blocks']->extra['before'] = [];
WPD_Blocks::editor_page_data();
$before = implode("\n", (array) wp_scripts()->get_data('wpd-blocks', 'before'));
check('editor page data: page size for blocks', (bool) preg_match('/"buttons":\{"attr":"iconSize","var":"--wpd-icon-qbtn","min":16,"max":96,"default":44,"setting":50\}/', $before), substr($before, 0, 300));
unset($GLOBALS['post']);
WPD_Settings::set_page(0);
$other = WPD_Renderer::page_html(new_page('S other'), '');
check('other page: global sizes', has(root_style($other), '--wpd-icon-qbtn:30px') || !has($other, 'wpd-qbtn'));

/* 8. Мета-бокс и страница настроек */
ob_start();
WPD_Page_Editor::meta_box_html(get_post($pg));
$mb = ob_get_clean();
check('metabox: buttons size field with value', (bool) preg_match('/<input type="number" id="wpd_pe_ov_buttons_icon_size" name="wpd_page\[ov\]\[buttons_icon_size\]" value="50" min="0" max="96"/', $mb));
check('metabox: contacts, hero and docs size fields', has($mb, 'id="wpd_pe_ov_contacts_icon_size"') && has($mb, 'id="wpd_pe_ov_hero_button_icon_size"') && has($mb, 'id="wpd_pe_ov_docs_icon_size"'));
foreach (['buttons' => 'buttons_icon_size', 'contacts' => 'contacts_icon_size', 'docs' => 'docs_icon_size', 'hero' => 'hero_button_icon_size'] as $tab => $key) {
    ob_start();
    $_GET = ['page' => 'wpd-settings', 'tab' => $tab];
    WPD_Settings::render_page();
    $html = ob_get_clean();
    check("settings tab $tab: $key field", (bool) preg_match('/<input type="number" id="[^"]*' . $key . '" name="wpd_settings\[' . $key . '\]" value="\d+" min="0" max="\d+"/', $html));
}
$_GET = [];

/* 9. CSS и JS */
$css = file_get_contents(WPD_DIR . 'assets/css/wpd.css');
check('css: buttons icon var with 44px fallback', has($css, 'font-size: var(--wpd-icon-qbtn, 44px);') && has($css, 'width: var(--wpd-icon-qbtn, 44px);'));
check('css: soft style fallback 32px', has($css, 'font-size: var(--wpd-icon-qbtn, 32px);'));
check('css: contacts and hero buttons', has($css, 'font-size: var(--wpd-icon-contacts, 24px);') && has($css, 'font-size: var(--wpd-icon-btn, 20px);'));
check('css: docs box and label scale', has($css, 'width: var(--wpd-icon-doc, 44px);') && has($css, 'font-size: calc(var(--wpd-icon-doc, 44px) * 0.27);'));
check('css: no fixed icon sizes left', !preg_match('/(qbtn__icon|contacts__icon|btn__icon|doc__icon)[^{]*\{[^}]*(width|font-size):\s*\d+px/', $css));
$js = file_get_contents(WPD_DIR . 'assets/js/wpd-blocks.js');
check('blocks js: icon size control and vars', has($js, 'function IconSizeControl') && has($js, 'function iconVars') && has($js, "'data-wpd-style'"));
check('blocks js: four blocks use own vars', substr_count($js, '{ className: props.className, attributes: a }') === 4);
check('blocks js: controls in four blocks', substr_count($js, "IconSizeControl('") === 4);
$ajs = file_get_contents(WPD_DIR . 'assets/js/wpd-admin.js');
check('admin js: page preview keeps block value', has($ajs, "lastPreview.style + (el.getAttribute('data-wpd-style') || '')"));

/* 10. Справка, версия */
ob_start();
$_GET = ['page' => 'wpd-settings', 'tab' => 'help'];
WPD_Settings::render_page();
$help = ob_get_clean();
$_GET = [];
check('help: icon sizes (2.5.0)', has($help, 'Размер иконок</strong> (2.5.0)') && has($help, 'Размер блока важнее размера страницы'));
check('version 2.5.0+', version_compare(WPD_VERSION, '2.5.0', '>='));
check('version: header matches constant', has(file_get_contents(WPD_DIR . 'wp-olympiad-design.php'), 'Version:           ' . WPD_VERSION));

/* Уборка */
foreach (get_posts(['post_type' => 'page', 'meta_key' => '_wpd_s_test', 'posts_per_page' => -1, 'post_status' => 'any']) as $p) {
    wp_delete_post($p->ID, true);
}
delete_option('wpd_settings');
echo implode("\n", $results), "\n\nTOTAL: ", count($results), ", FAILED: ", $fails, "\n";
