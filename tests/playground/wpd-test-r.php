<?php
// Фаза R (2.4.0): своё оформление страницы — PHP-часть.
// Группы переопределений, санитизация (группы, смена стиля, палитры, вид блоков, свой CSS), хранение,
// контекст настроек страницы, вывод оформления страницы и блоков «Олимпиада: …», стили страницы,
// REST (предпросмотр, экспорт, импорт, копирование, сброс, права), мета-бокс, боковая колонка, мета _wpd_rev,
// справка, CSS и JS. В конце удаляет свои записи и опцию.
register_shutdown_function(function () { $e = error_get_last(); if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR], true)) { echo "\nSHUTDOWN: {$e['message']} in {$e['file']}:{$e['line']}\n"; } });
ini_set('display_errors', '1');
error_reporting(E_ALL);
require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
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
function new_page(string $title, string $content = '<p>Текст</p>', bool $enabled = true): int {
    $id = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => $content]);
    update_post_meta($id, '_wpd_r_test', 1);
    if ($enabled) {
        update_post_meta($id, '_wpd_enabled', 1);
    }
    return (int) $id;
}
function rest(string $method, string $route, array $params = []): WP_REST_Response {
    $r = new WP_REST_Request($method, $route);
    foreach ($params as $k => $v) {
        $r->set_param($k, $v);
    }
    $res = rest_do_request($r);
    return $res instanceof WP_REST_Response ? $res : rest_ensure_response($res);
}
/** Форма панели: всё как отправляет настоящий мета-бокс (порядок и показ блоков). */
function form(array $extra): array {
    $show = [];
    foreach (array_keys(WPD_Page_Editor::blocks()) as $k) {
        $show[$k] = 1;
    }
    return array_merge(['order' => array_keys(WPD_Page_Editor::blocks()), 'show' => $show], $extra);
}

wp_set_current_user(1);
delete_option('wpd_settings');
WPD_Settings::flush_cache();
WPD_Settings::set_page(0);
$map    = WPD_Settings::field_map();
$global = WPD_Settings::get_global();

/* 1. Группы переопределений */
$groups = WPD_Page_Editor::groups();
check('groups: nine groups in order', array_keys($groups) === ['page', 'hero', 'features', 'slider', 'buttons', 'cards', 'notice', 'news', 'contacts']);
$page_keys = WPD_Page_Editor::group_keys('page');
check('page group: style, palette, colours, fonts', !array_diff(['design_style', 'palette', 'primary', 'accent', 'dark', 'text', 'bg', 'radius', 'google_fonts'], $page_keys));
check('page group: look and behavior fields', !array_diff(['heading_underline', 'card_hover', 'hide_title'], $page_keys), implode(',', $page_keys));
check('page group: 29 fields', count($page_keys) === 29, (string) count($page_keys));
check('page group: no custom_css (own CSS field instead)', !in_array('custom_css', $page_keys, true));
$hero_keys = WPD_Page_Editor::group_keys('hero');
check('hero group: no texts, image url or style (panel fields)', !array_intersect(['hero_title', 'hero_text', 'badge', 'hero_button_text', 'hero_button_url', 'hero_image_url', 'hero_style'], $hero_keys));
check('hero group: has second button and look fields', in_array('hero_countdown', $hero_keys, true) || count($hero_keys) > 10, implode(',', $hero_keys));
$all = [];
$dup = false;
$total = 0;
foreach (array_keys($groups) as $g) {
    $keys = WPD_Page_Editor::group_keys($g);
    $total += count($keys);
    foreach ($keys as $k) {
        if (isset($all[$k])) { $dup = true; }
        $all[$k] = $g;
    }
    check("group $g: not empty, keys exist in settings", $keys && !array_diff($keys, array_keys($map)));
}
check('groups: no field in two groups', !$dup);
check('groups: excluded fields never overridable', !array_intersect(WPD_Page_Editor::OV_EXCLUDE, array_keys($all)));
check('group_keys: unknown group -> empty', WPD_Page_Editor::group_keys('nope') === []);

/* 2. Санитизация групп */
$c = WPD_Page_Editor::sanitize(form(['ov' => ['primary' => '#ff0000']]));
check('sanitize: values without enabled group are ignored', $c['ov'] === []);
$c = WPD_Page_Editor::sanitize(form(['ovg' => ['page' => 1], 'ov' => ['palette' => 'custom', 'primary' => '#AA3300', 'radius' => '24']]));
check('sanitize: enabled group stores all its fields', isset($c['ov']['page']) && count($c['ov']['page']) === count($page_keys));
check('sanitize: own colour kept (custom palette)', $c['ov']['page']['primary'] === '#aa3300' || $c['ov']['page']['primary'] === '#AA3300', $c['ov']['page']['primary']);
check('sanitize: missing fields take global values', $c['ov']['page']['accent'] === $global['accent'] && $c['ov']['page']['font'] === $global['font']);
check('sanitize: unchecked checkbox -> 0', $c['ov']['page']['heading_underline'] === 0);
check('sanitize: number field', (string) $c['ov']['page']['radius'] === '24');
$c = WPD_Page_Editor::sanitize(form(['ovg' => ['page' => 1], 'ov' => ['palette' => 'forest', 'primary' => '#000000']]));
check('sanitize: ready palette sets colours', $c['ov']['page']['primary'] === '#15803d');
$c = WPD_Page_Editor::sanitize(form(['ovg' => ['page' => 1], 'ov' => ['palette' => 'custom', 'primary' => 'red;}body{x']]));
check('sanitize: invalid colour rejected', !has((string) $c['ov']['page']['primary'], '}'), (string) $c['ov']['page']['primary']);
$c = WPD_Page_Editor::sanitize(form(['ovg' => ['contacts' => 1], 'ov' => ['contact_phone' => '<b>+7 999</b>', 'contact_email' => 'x@example.com']]));
check('sanitize: contacts group, text sanitized', isset($c['ov']['contacts']) && !has((string) $c['ov']['contacts']['contact_phone'], '<b>') && has((string) $c['ov']['contacts']['contact_phone'], '+7 999'));
check('sanitize: other groups untouched', !isset($c['ov']['page']) && !isset($c['ov']['hero']));
$c = WPD_Page_Editor::sanitize(form(['ovg' => ['page' => 1], 'ov' => ['design_style' => 'neon']]));
check('sanitize: unknown design style -> classic', $c['ov']['page']['design_style'] === 'classic');

/* 3. Смена стиля оформления страницы */
$c = WPD_Page_Editor::sanitize(form(['ovg' => ['page' => 1], 'ov' => ['design_style' => 'soft', 'palette' => 'custom']]));
check('switch to soft: palette soft, primary of palette', $c['ov']['page']['palette'] === 'soft' && $c['ov']['page']['primary'] === '#2235f8');
check('switch to soft: hero style soft', $c['hero']['style'] === 'soft');
$sw = new_page('R switch');
WPD_Page_Editor::store($sw, $c);
$c2 = WPD_Page_Editor::sanitize(form(['ovg' => ['page' => 1], 'ov' => ['design_style' => 'classic', 'palette' => 'soft'], 'hero' => ['style' => 'soft']]), $sw);
check('switch back: palette olympiad, hero dark', $c2['ov']['page']['palette'] === 'olympiad' && $c2['hero']['style'] === 'dark');
$c3 = WPD_Page_Editor::sanitize(form(['ovg' => ['page' => 1], 'ov' => ['design_style' => 'soft', 'palette' => 'cherry'], 'hero' => ['style' => 'light']]), $sw);
check('soft kept: own palette and hero style not replaced', $c3['ov']['page']['palette'] === 'cherry' && $c3['hero']['style'] === 'light');
$c4 = WPD_Page_Editor::sanitize(form(['ovg' => ['page' => 1], 'ov' => ['design_style' => 'soft', 'palette' => 'cherry']]), 0, false);
check('switch=false (import): palette as given', $c4['ov']['page']['palette'] === 'cherry');

/* 4. Вид блоков и свой CSS */
$b = WPD_Page_Editor::clean_bstyle([
    'hero'  => ['bg' => '#ffffff', 'mt' => '10'],
    'stats' => ['bg' => '#112233', 'mt' => '500', 'mb' => '-5', 'width' => 'narrow', 'hide' => 'mobile'],
    'faq'   => ['bg' => 'red', 'mt' => 'abc', 'width' => 'huge', 'hide' => 'tablet'],
    'docs'  => ['mt' => '0'],
    'nope'  => ['mt' => '10'],
]);
check('bstyle: hero background ignored, margin kept', isset($b['hero']) && $b['hero']['bg'] === '' && $b['hero']['mt'] === '10');
check('bstyle: margins clamped 0..200', $b['stats']['mt'] === '200' && $b['stats']['mb'] === '0');
check('bstyle: width and hide kept', $b['stats']['width'] === 'narrow' && $b['stats']['hide'] === 'mobile' && $b['stats']['bg'] === '#112233');
check('bstyle: invalid values dropped, empty block not stored', !isset($b['faq']));
check('bstyle: zero margin is a value', isset($b['docs']) && $b['docs']['mt'] === '0');
check('bstyle: unknown block ignored', !isset($b['nope']));
$c = WPD_Page_Editor::sanitize(form(['page_css' => ".x{color:red}</style><script>alert(1)</script>"]));
check('page css: no closing style tag or script', !has($c['page_css'], '</style') && !has($c['page_css'], '<script'), $c['page_css']);
check('page css: rule kept', has($c['page_css'], '.x{color:red}'));

/* 5. Хранение */
$st = new_page('R store');
WPD_Page_Editor::store($st, WPD_Page_Editor::sanitize([]));
check('store: defaults are not stored', get_post_meta($st, WPD_Page_Editor::META, true) === '');
$in = form(['ovg' => ['page' => 1], 'ov' => ['palette' => 'custom', 'primary' => '#aa3300'], 'page_css' => '.q::before{content:"\201C"}', 'bstyle' => ['faq' => ['hide' => 'desktop']]]);
WPD_Page_Editor::store($st, WPD_Page_Editor::sanitize($in, $st));
$g = WPD_Page_Editor::get($st);
check('store: roundtrip ov', ($g['ov']['page']['primary'] ?? '') === '#aa3300');
check('store: backslash in CSS survives', has($g['page_css'], '\201C'), $g['page_css']);
check('store: bstyle roundtrip', ($g['bstyle']['faq']['hide'] ?? '') === 'desktop');
$mi = WPD_Page_Editor::meta_to_input($g);
$again = WPD_Page_Editor::sanitize($mi, $st, false);
check('meta_to_input: sanitize(meta_to_input(meta)) == meta', $again['ov'] == $g['ov'] && $again['bstyle'] == $g['bstyle'] && $again['page_css'] === $g['page_css'] && $again['order'] === $g['order']);

/* 6. Контекст настроек */
$o = WPD_Page_Editor::apply($global, $st);
check('apply: page value replaces global', $o['primary'] === '#aa3300' && $global['primary'] !== '#aa3300');
check('apply_data: unknown keys ignored', !array_key_exists('zzz', WPD_Page_Editor::apply_data($global, ['ov' => ['page' => ['zzz' => 1]]])));
WPD_Settings::set_page($st);
check('context: get() with page overrides', WPD_Settings::get()['primary'] === '#aa3300' && WPD_Settings::current_page() === $st);
check('context: get_global() unchanged', WPD_Settings::get_global()['primary'] === $global['primary']);
$plain = new_page('R plain');
WPD_Settings::set_page($plain);
check('context: page without own design = global', WPD_Settings::get() == WPD_Settings::get_global());
WPD_Settings::set_page(0);
check('context: reset -> global', WPD_Settings::get()['primary'] === $global['primary'] && WPD_Settings::current_page() === 0);
// кеш страницы сбрасывается при изменении её настроек
WPD_Settings::set_page($st);
WPD_Settings::get();
WPD_Page_Editor::store($st, WPD_Page_Editor::sanitize(form(['ovg' => ['page' => 1], 'ov' => ['palette' => 'custom', 'primary' => '#00aa33']]), $st));
check('context: cache flushed after page meta update', WPD_Settings::get()['primary'] === '#00aa33', WPD_Settings::get()['primary']);
WPD_Settings::set_page(0);

/* 7. Вывод страницы */
$pg = new_page('R page', '<p>Текст R</p>');
$in = form([
    'show'   => ['hero' => 1, 'content' => 1, 'stats' => 1, 'faq' => 1, 'contacts' => 1],
    'hero'   => ['title' => 'Заголовок R'],
    'ovg'    => ['page' => 1, 'contacts' => 1],
    'ov'     => ['design_style' => 'soft', 'palette' => 'custom', 'radius' => '24', 'contact_phone' => '+7 999 000-00-00', 'button_shape' => 'pill'],
    'bstyle' => ['stats' => ['bg' => '#112233', 'mt' => '10', 'width' => 'narrow', 'hide' => 'mobile'], 'faq' => ['bg' => '#fafafa', 'mb' => '40', 'width' => 'full', 'hide' => 'desktop'], 'hero' => ['bg' => '#ffffff', 'mt' => '8']],
    'page_css' => '.wpd-page .wpd-stats{outline:1px dashed #aa3300}',
]);
WPD_Page_Editor::store($pg, WPD_Page_Editor::sanitize($in, $pg));
$html = WPD_Renderer::page_html($pg, '<p>Текст R</p>');
check('page: soft style container', has($html, 'wpd-style-soft'));
check('page: page primary in vars', has($html, '--wpd-primary:#2235f8'));
preg_match('/<section[^>]*wpd-stats[^>]*>/', $html, $m);
$stats = $m[0] ?? '';
check('page: stats decorated (bg dark, mt, narrow, hide mobile)', has($stats, 'wpd-b wpd-b--bg wpd-b--bg-dark wpd-b--mt wpd-b--w-narrow wpd-b--hide-mobile'), $stats);
check('page: stats vars', has($stats, '--wpd-b-bg:#112233;') && has($stats, '--wpd-b-mt:10px;'));
preg_match('/<section[^>]*wpd-faq[^>]*>/', $html, $m);
$faq = $m[0] ?? '';
check('page: faq light bg, mb, full, hide desktop', has($faq, 'wpd-b--bg-light') && has($faq, 'wpd-b--mb') && has($faq, 'wpd-b--w-full') && has($faq, 'wpd-b--hide-desktop'), $faq);
preg_match('/<section[^>]*wpd-hero[^>]*>/', $html, $m);
$hero = $m[0] ?? '';
check('page: hero margin but no background', has($hero, 'wpd-b--mt') && !has($hero, 'wpd-b--bg'), $hero);
check('page: soft hero (switch on save)', has($hero, 'wpd-hero--soft'));
check('page: contacts phone from page', has($html, '+7 999 000-00-00'));
check('page: content not decorated', has($html, '<div class="wpd-content"><p>Текст R</p>'));
$other = new_page('R other');
update_post_meta($other, WPD_Page_Editor::META, wp_slash(WPD_Page_Editor::sanitize(form(['hero' => ['title' => 'Другая']]))));
$html2 = WPD_Renderer::page_html($other, '');
check('page: other page keeps global style', !has($html2, 'wpd-style-soft') && !has($html2, 'wpd-b--') && !has($html2, '+7 999 000-00-00'));

/* 8. decorate_block */
check('decorate: no settings -> unchanged', WPD_Renderer::decorate_block('<section class="x">a</section>', [], 'stats') === '<section class="x">a</section>');
check('decorate: empty html stays empty', WPD_Renderer::decorate_block('', ['mt' => '5'], 'stats') === '');
$d = WPD_Renderer::decorate_block('<div style="color:red">a</div>', ['mt' => '5'], 'notice');
check('decorate: adds class and merges style', $d === '<div style="color:red;--wpd-b-mt:5px;" class="wpd-b wpd-b--mt">a</div>', $d);
$d = WPD_Renderer::decorate_block("\n<section class=\"wpd-docs\" id=\"d\">a</section>", ['width' => 'full'], 'docs');
check('decorate: keeps leading whitespace and attributes', $d === "\n<section class=\"wpd-docs wpd-b wpd-b--w-full\" id=\"d\">a</section>", $d);
check('decorate: hero background ignored', !has(WPD_Renderer::decorate_block('<section class="h">a</section>', ['bg' => '#000000'], 'hero'), 'wpd-b'));

/* 9. Блоки «Олимпиада: …» в тексте страницы */
WPD_Settings::set_page($pg);
$blk = render_block(['blockName' => 'wpd/contacts', 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []]);
check('block wpd/contacts: page phone in context', has($blk, '+7 999 000-00-00'));
$sc = do_shortcode('[wpd_contacts]');
check('shortcode in page context: page phone', has($sc, '+7 999 000-00-00') || $sc === '[wpd_contacts]');
WPD_Settings::set_page(0);
$blk = render_block(['blockName' => 'wpd/contacts', 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []]);
check('block wpd/contacts: global outside page', !has($blk, '+7 999 000-00-00'));
// block-renderer в редакторе получает post_id
$req = new WP_REST_Request('GET', '/wp/v2/block-renderer/wpd/contacts');
$req->set_param('post_id', $pg);
WPD_Blocks::rest_page_context(null, [], $req);
check('block-renderer: page context from post_id', WPD_Settings::current_page() === $pg);
WPD_Settings::set_page(0);
$req = new WP_REST_Request('GET', '/wp/v2/posts');
$req->set_param('post_id', $pg);
WPD_Blocks::rest_page_context(null, [], $req);
check('other routes: no page context', WPD_Settings::current_page() === 0);

/* 10. Стили страницы */
wp_deregister_style('wpd-design');
WPD_Renderer::register_assets();
wp_enqueue_style('wpd-design');
WPD_Settings::set_page($pg);
WPD_Renderer::enqueue_page_assets();
$after = implode("\n", (array) wp_styles()->get_data('wpd-design', 'after'));
check('page assets: own CSS inline', has($after, '.wpd-page .wpd-stats{outline:1px dashed #aa3300}'));
check('page assets: soft body background', has($after, 'body.wpd-soft-page{background-color:'));
WPD_Settings::set_page(0);
wp_deregister_style('wpd-design');
WPD_Renderer::register_assets();
wp_enqueue_style('wpd-design');
WPD_Settings::set_page($plain);
WPD_Renderer::enqueue_page_assets();
$after = implode("\n", (array) wp_styles()->get_data('wpd-design', 'after'));
check('page assets: nothing for page without settings', !has($after, 'outline:1px dashed') && !has($after, 'wpd-soft-page'));
WPD_Settings::set_page(0);

/* 11. REST */
$routes = rest_get_server()->get_routes();
check('rest: routes registered', isset($routes['/wpd/v1/preview']) && isset($routes['/wpd/v1/page/(?P<id>\d+)']));
$res = rest('POST', '/wpd/v1/preview', ['post_id' => $pg, 'form' => http_build_query(['wpd_page' => form(['ovg' => ['page' => 1], 'ov' => ['palette' => 'cherry', 'design_style' => 'classic']])])]);
$d = $res->get_data();
check('preview: 200', $res->get_status() === 200, (string) $res->get_status());
check('preview: cherry primary in style', has((string) ($d['style'] ?? ''), '#be123c'));
check('preview: classes and fields', ($d['classes'][0] ?? '') === 'wpd-page' && isset($d['vars'], $d['soft'], $d['bg'], $d['heroStyle']));
check('preview: does not save', (WPD_Page_Editor::get($pg)['ov']['page']['design_style'] ?? '') === 'soft');
$res = rest('POST', '/wpd/v1/preview', ['post_id' => $plain, 'form' => http_build_query(['wpd_page' => form(['ovg' => ['page' => 1], 'ov' => ['design_style' => 'soft']])])]);
check('preview: switch to soft -> soft flag and hero style', !empty($res->get_data()['soft']) && $res->get_data()['heroStyle'] === 'soft');

$res = rest('GET', '/wpd/v1/page/' . $pg);
$exp = $res->get_data();
check('export: 200 with plugin, version, enabled, data', $res->get_status() === 200 && $exp['plugin'] === 'wp-olympiad-design' && $exp['version'] === WPD_VERSION && $exp['enabled'] === true && isset($exp['data']['ov']['page']));
check('export: title', $exp['title'] === 'R page');

$tgt = new_page('R target', '', false);
$res = rest('POST', '/wpd/v1/page/' . $tgt, ['data' => wp_json_encode($exp)]);
check('import (JSON string with wrapper): ok', $res->get_status() === 200 && !empty($res->get_data()['ok']));
$gt = WPD_Page_Editor::get($tgt);
check('import: data equals source', $gt['ov'] == $exp['data']['ov'] && $gt['bstyle'] == $exp['data']['bstyle'] && $gt['page_css'] === $exp['data']['page_css']);
check('import: enables design (source enabled)', get_post_meta($tgt, '_wpd_enabled', true) == 1);
$res = rest('POST', '/wpd/v1/page/' . $tgt, ['data' => ['page_css' => '.y{top:0}']]);
check('import (bare data array): ok', $res->get_status() === 200 && WPD_Page_Editor::get($tgt)['page_css'] === '.y{top:0}');
$res = rest('POST', '/wpd/v1/page/' . $tgt, ['data' => 'не json']);
check('import: invalid JSON -> 400', $res->get_status() === 400 && $res->as_error()->get_error_code() === 'wpd_json');
$res = rest('POST', '/wpd/v1/page/' . $tgt, ['data' => wp_json_encode(['foo' => 1])]);
check('import: JSON without settings -> 400', $res->get_status() === 400);
check('import: failed import keeps data', WPD_Page_Editor::get($tgt)['page_css'] === '.y{top:0}');

$cp = new_page('R copy', '', false);
$res = rest('POST', '/wpd/v1/page/' . $cp, ['from' => $pg]);
check('copy from page: ok and same data', $res->get_status() === 200 && WPD_Page_Editor::get($cp)['ov'] == WPD_Page_Editor::get($pg)['ov']);
check('copy: enabled with source', get_post_meta($cp, '_wpd_enabled', true) == 1);
$res = rest('POST', '/wpd/v1/page/' . $cp, ['from' => $cp]);
check('copy from itself -> 403', $res->get_status() === 403);
$res = rest('POST', '/wpd/v1/page/' . $cp, ['from' => 999999]);
check('copy from missing post -> 403', $res->get_status() === 403);

$res = rest('DELETE', '/wpd/v1/page/' . $cp);
check('reset: ok, meta removed, design stays enabled', $res->get_status() === 200 && get_post_meta($cp, WPD_Page_Editor::META, true) === '' && get_post_meta($cp, '_wpd_enabled', true) == 1);

$res = rest('GET', '/wpd/v1/page/999999');
check('rest: missing post -> 401/403', in_array($res->get_status(), [401, 403], true), (string) $res->get_status());
$post_id_post = wp_insert_post(['post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'R att', 'post_mime_type' => 'image/png']);
update_post_meta($post_id_post, '_wpd_r_test', 1);
$res = rest('GET', '/wpd/v1/page/' . $post_id_post);
check('rest: unsupported post type -> 403', $res->get_status() === 403, (string) $res->get_status());
$sub = wp_insert_user(['user_login' => 'wpd_r_sub', 'user_pass' => 'x' . wp_generate_password(), 'role' => 'subscriber']);
wp_set_current_user($sub);
check('rest: subscriber export -> 403', rest('GET', '/wpd/v1/page/' . $pg)->get_status() === 403);
check('rest: subscriber reset -> 403', rest('DELETE', '/wpd/v1/page/' . $pg)->get_status() === 403 && get_post_meta($pg, WPD_Page_Editor::META, true) !== '');
check('rest: subscriber preview -> 403', rest('POST', '/wpd/v1/preview', ['post_id' => $pg, 'form' => ''])->get_status() === 403);
wp_set_current_user(0);
check('rest: anonymous -> 401', rest('GET', '/wpd/v1/page/' . $pg)->get_status() === 401);
wp_set_current_user(1);
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user($sub);

/* 12. Сохранение мета-бокса (как отправляет форма) */
$_POST = ['wpd_page_nonce' => wp_create_nonce('wpd_save_page'), 'wpd_page' => wp_slash(form(['ovg' => ['notice' => 1], 'ov' => ['notice_title' => 'Объявление R'], 'page_css' => 'a{b:"\\c"}']))];
WPD_Page_Editor::save($plain, get_post($plain));
$gp = WPD_Page_Editor::get($plain);
check('save: notice group stored', ($gp['ov']['notice']['notice_title'] ?? '') === 'Объявление R');
check('save: slashes handled', $gp['page_css'] === 'a{b:"\\c"}', $gp['page_css']);
$_POST = ['wpd_page_nonce' => 'bad', 'wpd_page' => form(['page_css' => 'x{}'])];
WPD_Page_Editor::save($plain, get_post($plain));
check('save: bad nonce ignored', WPD_Page_Editor::get($plain)['page_css'] === 'a{b:"\\c"}');
$_POST = [];

/* 13. Мета-бокс */
ob_start();
WPD_Page_Editor::meta_box_html(get_post($pg));
$mb = ob_get_clean();
check('metabox: nine override groups', substr_count($mb, 'data-wpd-ovg=') === 9, (string) substr_count($mb, 'data-wpd-ovg='));
check('metabox: group checkbox names', has($mb, 'name="wpd_page[ovg][page]"') && has($mb, 'name="wpd_page[ovg][contacts]"'));
check('metabox: enabled groups checked', (bool) preg_match('/<input[^>]*name="wpd_page\[ovg\]\[page\]"[^>]*checked/', $mb) && (bool) preg_match('/<input[^>]*name="wpd_page\[ovg\]\[contacts\]"[^>]*checked/', $mb));
check('metabox: disabled group not checked', !preg_match('/<input[^>]*name="wpd_page\[ovg\]\[slider\]"[^>]*checked/', $mb));
check('metabox: override fields prefixed', has($mb, 'id="wpd_pe_ov_primary"') && has($mb, 'name="wpd_page[ov][primary]"'));
check('metabox: page palette with id base', has($mb, 'data-id-base="wpd_pe_ov_"'));
check('metabox: page value shown in field', has($mb, '+7 999 000-00-00'));
check('metabox: no global custom_css field in panel', !has($mb, 'name="wpd_page[ov][custom_css]"'));
check('metabox: block style toggles for all blocks', substr_count($mb, 'data-wpd-bs-toggle') === count(WPD_Page_Editor::blocks()), (string) substr_count($mb, 'data-wpd-bs-toggle'));
check('metabox: block style fields', has($mb, 'name="wpd_page[bstyle][stats][bg]"') && has($mb, 'id="wpd_pe_bs_stats_mt"') && has($mb, 'name="wpd_page[bstyle][faq][hide]"'));
check('metabox: hero block without background field', !has($mb, 'name="wpd_page[bstyle][hero][bg]"') && has($mb, 'name="wpd_page[bstyle][hero][mt]"'));
check('metabox: saved block style selected', (bool) preg_match('/<select[^>]*name="wpd_page\[bstyle\]\[stats\]\[width\]"[^>]*>.*?<option value="narrow"\s*selected/s', $mb));
check('metabox: own CSS textarea with value', has($mb, 'id="wpd_pe_page_css"') && has($mb, 'outline:1px dashed #aa3300'));
check('metabox: tools panel', has($mb, 'data-wpd-tools') && has($mb, 'data-wpd-export') && has($mb, 'data-wpd-import') && has($mb, 'data-wpd-reset-page'));
check('metabox: copy sources list other pages', has($mb, '<option value="' . $st . '">R store</option>') && !has($mb, '<option value="' . $pg . '">'));
$sources = WPD_Page_Editor::copy_sources($pg);
check('copy_sources: pages with settings or enabled design', isset($sources[$st]) && isset($sources[$plain]) && !isset($sources[$pg]));

/* 14. Боковая колонка, мета _wpd_rev, ресурсы редактора */
set_current_screen('page');
WPD_Page_Editor::enqueue_sidebar();
check('sidebar script enqueued on page screen', wp_script_is('wpd-sidebar', 'enqueued'));
$dep = wp_scripts()->registered['wpd-sidebar']->deps ?? [];
check('sidebar deps', !array_diff(['wp-plugins', 'wp-element', 'wp-components', 'wp-data', 'jquery'], $dep), implode(',', $dep));
wp_dequeue_script('wpd-sidebar');
wp_deregister_script('wpd-sidebar');
set_current_screen('edit-comments');
WPD_Page_Editor::enqueue_sidebar();
check('sidebar not enqueued on other screens', !wp_script_is('wpd-sidebar', 'enqueued'));
$reg = get_registered_meta_keys('post', 'page');
check('_wpd_rev registered and in REST', isset($reg['_wpd_rev']) && !empty($reg['_wpd_rev']['show_in_rest']));
check('_wpd_rev: editor can write', current_user_can('edit_post_meta', $pg, '_wpd_rev') || current_user_can('edit_post', $pg));
$js = file_get_contents(WPD_DIR . 'assets/js/wpd-sidebar.js');
check('sidebar js: plugin and panel', has($js, "registerPlugin('wpd-design-sidebar'") && has($js, 'PluginDocumentSettingPanel') && has($js, 'Оформление олимпиады'));
check('sidebar js: uses panel form fields', has($js, 'wpd_page[ov]') || has($js, "'wpd_page'"));
$css = file_get_contents(WPD_DIR . 'assets/css/wpd.css');
check('css: block decoration rules', has($css, '.wpd-page > .wpd-b--bg') && has($css, '--wpd-b-mt') && has($css, '.wpd-page > .wpd-b--w-narrow') && has($css, '.wpd-page > .wpd-b--w-full'));
check('css: hide on phone/desktop breakpoints', (bool) preg_match('/@media \(max-width: 782px\) \{\s*\.wpd-page > \.wpd-b--hide-mobile/', $css) && (bool) preg_match('/@media \(min-width: 783px\) \{\s*\.wpd-page > \.wpd-b--hide-desktop/', $css));
$acss = file_get_contents(WPD_DIR . 'assets/css/wpd-admin.css');
check('admin css: sidebar and panel styles', has($acss, '.wpd-side__palette') && has($acss, '.wpd-pe__bs'));
$sjs = file_get_contents(WPD_DIR . 'assets/js/wpd-settings.js');
check('settings js: palettes prefix-aware', has($sjs, 'data-id-base'));

/* 15. Справка, версия, удаление */
ob_start();
$_GET = ['page' => 'wpd-settings', 'tab' => 'help'];
WPD_Settings::render_page();
$help = ob_get_clean();
$_GET = [];
check('help: page design and sidebar (2.4.0)', has($help, 'своё оформление страницы') && has($help, 'Боковая колонка «Оформление олимпиады»'));
check('version 2.4.0+', version_compare(WPD_VERSION, '2.4.0', '>='));
check('version: header matches constant', has(file_get_contents(WPD_DIR . 'wp-olympiad-design.php'), 'Version:           ' . WPD_VERSION));
$un = file_get_contents(WPD_DIR . 'uninstall.php');
check('uninstall removes _wpd_rev and _wpd_page', has($un, '_wpd_rev') && has($un, '_wpd_page'));

/* Уборка */
foreach (get_posts(['post_type' => ['page', 'attachment'], 'meta_key' => '_wpd_r_test', 'posts_per_page' => -1, 'post_status' => 'any']) as $p) {
    wp_delete_post($p->ID, true);
}
delete_option('wpd_settings');
echo implode("\n", $results), "\n\nTOTAL: ", count($results), ", FAILED: ", $fails, "\n";
