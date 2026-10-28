<?php
// Фаза W (2.9.0): сторонние шрифты и логотипы. Настройки (секции, вкладки, поля, очистка названий шрифтов, строк файлов
// шрифтов и ссылок сервисов), @font-face и таблицы стилей (общие, страницы, редактор), подсказки шрифтов, шрифты элементов,
// атрибуты шрифтов у всех блоков, логотип главного блока (над/рядом, вариант для тёмного фона), логотип контактов,
// компонент «Логотипы партнёров» (шорткод, блок, панель страницы), загрузка SVG и шрифтов (права, очистка SVG,
// сигнатуры шрифтов, размеры SVG в медиатеке), справка и README.
register_shutdown_function(function () { $e = error_get_last(); if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR], true)) { echo "\nSHUTDOWN: {$e['message']} in {$e['file']}:{$e['line']}\n"; } });
ini_set('display_errors', '1');
error_reporting(E_ALL);
require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
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
function clean(array $in): array { return WPD_Settings::sanitize($in); }
function tmpfile_with(string $name, string $data): string {
    $p = get_temp_dir() . 'wpdw-' . wp_generate_password(6, false) . '-' . $name;
    file_put_contents($p, $data);
    return $p;
}
function sideload(string $name, string $data) {
    $tmp = tmpfile_with($name, $data);
    $file = ['name' => $name, 'tmp_name' => $tmp, 'size' => strlen($data), 'error' => 0, 'type' => ''];
    $r = wp_handle_sideload($file, ['test_form' => false]);
    if (is_file($tmp)) { @unlink($tmp); }
    return $r;
}

$backup = get_option('wpd_settings', null);
set_opts([]);
wp_set_current_user(1);
$dir = WPD_DIR;
$map = WPD_Settings::field_map();
$created = [];
$files = [];

/* ---------- Настройки ---------- */
check('version 2.9.0', version_compare(WPD_VERSION, '2.9.0', '>='));
$sections = WPD_Settings::sections();
check('sections: fonts, hero_logo, partners', isset($sections['fonts'], $sections['hero_logo'], $sections['partners'], $sections['partners_look']));
$tabs = WPD_Settings::tabs();
check('design tab has fonts section', in_array('fonts', $tabs['design']['sections'], true));
check('hero tab has logo section', in_array('hero_logo', $tabs['hero']['sections'], true));
check('partners tab before help', isset($tabs['partners']) && array_search('partners', array_keys($tabs), true) === array_search('help', array_keys($tabs), true) - 1);
$new = ['font_files', 'font_links', 'font_hero', 'font_button', 'font_numbers', 'hero_logo', 'hero_logo_dark', 'hero_logo_pos', 'hero_logo_height',
        'hero_logo_alt', 'hero_logo_url', 'contact_logo_height', 'partners_title', 'partners', 'partners_layout', 'partners_columns',
        'partners_speed', 'partners_height', 'partners_gray', 'partners_cards', 'partners_names'];
check('21 new fields', count(array_intersect($new, array_keys($map))) === 21);
check('font_files repeater: select and file sub-fields', $map['font_files']['fields']['weight']['type'] === 'select' && $map['font_files']['fields']['url']['type'] === 'file');
check('contact_logo_url is image field', $map['contact_logo_url']['type'] === 'image');
$d = WPD_Settings::defaults();
check('defaults', $d['font_files'] === [] && $d['font_links'] === [] && $d['font_hero'] === '' && $d['hero_logo_pos'] === 'above'
    && (int) $d['hero_logo_height'] === 56 && $d['partners_layout'] === 'grid' && (int) $d['partners_gray'] === 1 && $d['partners_title'] === 'Партнёры');

// Названия шрифтов
check('font: cyrillic kept', WPD_Settings::sanitize_value('font', '"Мой Шрифт", sans-serif') === '"Мой Шрифт", sans-serif');
check('font: css injection stripped', WPD_Settings::sanitize_value('font_hero', 'Arial;} body{color:red') === 'Arial bodycolorred');
check('font: ascii unchanged', WPD_Settings::sanitize_value('font', 'Montserrat, "Segoe UI", sans-serif') === 'Montserrat, "Segoe UI", sans-serif');
check('font: empty allowed for element fonts', WPD_Settings::sanitize_value('font_button', '') === '');
check('font: empty main font -> default', WPD_Settings::sanitize_value('font', ' ') === $map['font']['default']);
check('font_family_name', WPD_Settings::font_family_name(' Golos  "Text";} ') === 'Golos Text');

// Строки файлов шрифтов
$rows = WPD_Settings::sanitize_rows([
    ['family' => 'Golos Text', 'url' => 'https://ex.com/f/golos-400.woff2', 'weight' => '400', 'style' => 'normal'],
    ['family' => 'Golos Text', 'url' => 'https://ex.com/f/golos-700.woff2', 'weight' => '950', 'style' => 'oblique'],
    ['family' => '', 'url' => '', 'weight' => '700', 'style' => 'italic'],
    ['family' => 'Var', 'url' => 'javascript:alert(1)', 'weight' => '100 900', 'style' => 'italic'],
    '__i__' => ['family' => 'tpl', 'url' => 'https://ex.com/x.woff2'],
], 'font_files');
check('font rows: empty and template dropped', count($rows) === 3);
check('font rows: invalid select -> default', $rows[1]['weight'] === '400' && $rows[1]['style'] === 'normal');
check('font rows: variable weight kept', $rows[2]['weight'] === '100 900' && $rows[2]['style'] === 'italic');
check('font rows: javascript url cleared', $rows[2]['url'] === '');
$many = [];
for ($i = 0; $i < 30; $i++) { $many[] = ['family' => 'F' . $i, 'url' => 'https://ex.com/' . $i . '.woff']; }
check('font rows: max 24', count(WPD_Settings::sanitize_rows($many, 'font_files')) === 24);
$links = WPD_Settings::sanitize_rows([
    ['url' => 'https://use.typekit.net/abc1234.css', 'families' => 'proxima-nova, "museo-sans"'],
    ['url' => 'http://insecure.example/fonts.css', 'families' => ''],
    ['url' => 'http://insecure.example/fonts.css', 'families' => 'Old'],
], 'font_links');
check('font links: http rejected', count($links) === 2 && $links[0]['url'] === 'https://use.typekit.net/abc1234.css' && $links[1]['url'] === '' && $links[1]['families'] === 'Old');

// @font-face
$o = array_merge($d, ['font_files' => [
    ['family' => 'Golos Text', 'url' => 'https://ex.com/f/golos-400.woff2', 'weight' => '400', 'style' => 'normal'],
    ['family' => 'Golos Text', 'url' => 'https://ex.com/f/golos-700-italic.ttf?ver=2', 'weight' => '700', 'style' => 'italic'],
    ['family' => 'Var";}body{x', 'url' => 'https://ex.com/f/v(1)\'x.otf', 'weight' => '100 900', 'style' => 'normal'],
    ['family' => 'Bad', 'url' => 'https://ex.com/f/bad.exe', 'weight' => '400', 'style' => 'normal'],
    ['family' => '', 'url' => 'https://ex.com/f/noname.woff', 'weight' => '400', 'style' => 'normal'],
    ['family' => 'Кириллица', 'url' => 'https://ex.com/f/k.woff', 'weight' => '500', 'style' => 'normal'],
]]);
$css = WPD_Settings::font_face_css($o);
check('font-face: 4 rules', substr_count($css, '@font-face') === 4, $css);
check('font-face: woff2 format and swap', has($css, '@font-face{font-family:"Golos Text";src:url("https://ex.com/f/golos-400.woff2") format("woff2");font-weight:400;font-style:normal;font-display:swap;}'));
check('font-face: ttf with query -> truetype, italic', has($css, 'golos-700-italic.ttf?ver=2") format("truetype");font-weight:700;font-style:italic;'));
check('font-face: family and url escaped', has($css, 'font-family:"Varbodyx"') && has($css, 'v%281%29%27x.otf") format("opentype");font-weight:100 900;') && !has($css, '";}body'));
check('font-face: wrong extension and no name skipped', !has($css, 'bad.exe') && !has($css, 'noname.woff'));
check('font-face: cyrillic family', has($css, 'font-family:"Кириллица";src:url("https://ex.com/f/k.woff") format("woff");font-weight:500'));
check('font-face: empty without files', WPD_Settings::font_face_css($d) === '');
$o['font_links'] = [['url' => 'https://a.example/1.css', 'families' => 'Alpha, "Beta Sans"'], ['url' => 'https://a.example/1.css', 'families' => ''],
                    ['url' => 'http://b.example/2.css', 'families' => ''], ['url' => 'https://api.fontshare.com/v2/css?f[]=satoshi@400&display=swap', 'families' => 'Satoshi']];
check('font links: https, unique', WPD_Settings::font_link_urls($o) === ['https://a.example/1.css', 'https://api.fontshare.com/v2/css?f%5B%5D=satoshi@400&display=swap']);
$o['google_fonts'] = "Montserrat:400,700\nPT Serif";
$sug = WPD_Settings::font_suggestions($o);
check('suggestions: files first, quoted names', $sug[0] === '"Golos Text", sans-serif' && in_array('Кириллица, sans-serif', $sug, true));
check('suggestions: services and google', in_array('Alpha, sans-serif', $sug, true) && in_array('"Beta Sans", sans-serif', $sug, true)
    && in_array('Satoshi, sans-serif', $sug, true) && in_array('Montserrat, sans-serif', $sug, true) && in_array('"PT Serif", sans-serif', $sug, true));
check('suggestions: system stacks, unique', in_array('Georgia, "Times New Roman", serif', $sug, true) && count($sug) === count(array_unique($sug)));
$dl = WPD_Settings::font_datalist($o);
check('datalist markup', has($dl, '<datalist id="wpd-font-list">') && has($dl, '<option value="&quot;Golos Text&quot;, sans-serif"></option>'));

// Шрифты элементов
$o2 = array_merge($d, ['font_hero' => '"Golos Text", sans-serif', 'font_button' => 'Arial', 'font_numbers' => '']);
$vars = WPD_Settings::style_vars($o2);
$cls = WPD_Settings::page_classes($o2);
check('element font vars', ($vars['--wpd-font-hero'] ?? '') === '"Golos Text", sans-serif' && ($vars['--wpd-font-btn'] ?? '') === 'Arial' && !isset($vars['--wpd-font-num']));
check('element font classes', in_array('wpd-ff-hero', $cls, true) && in_array('wpd-ff-btn', $cls, true) && !in_array('wpd-ff-num', $cls, true));
check('no element fonts by default', !array_intersect(['wpd-ff-hero', 'wpd-ff-btn', 'wpd-ff-num'], WPD_Settings::page_classes($d)) && !isset(WPD_Settings::style_vars($d)['--wpd-font-hero']));
$cssfile = file_get_contents($dir . 'assets/css/wpd.css');
check('css: element font rules', has($cssfile, '.wpd-page.wpd-ff-hero .wpd-hero .wpd-hero__title') && has($cssfile, '.wpd-page.wpd-ff-btn :is(.wpd-btn') && has($cssfile, '.wpd-page.wpd-ff-num :is(.wpd-stat__num, .wpd-countdown__value)'));

// Сохранение через sanitize()
$s = clean(['_tab' => 'design', 'font_files' => ['_' => '', 0 => ['family' => 'Golos Text', 'url' => 'https://ex.com/g.woff2', 'weight' => '700', 'style' => 'italic']],
            'font_links' => ['_' => '', 0 => ['url' => 'https://use.typekit.net/k.css', 'families' => 'proxima-nova']],
            'font_hero' => 'Golos Text', 'font_button' => '', 'font_numbers' => 'Мой цифровой']);
check('sanitize: fonts saved', count($s['font_files']) === 1 && $s['font_files'][0]['weight'] === '700' && $s['font_links'][0]['families'] === 'proxima-nova'
    && $s['font_hero'] === 'Golos Text' && $s['font_numbers'] === 'Мой цифровой');
$s2 = clean(['_tab' => 'partners', 'partners' => ['_' => '', 0 => ['logo' => 'https://ex.com/l.svg', 'name' => 'Фонд', 'url' => 'https://fond.example', 'new_tab' => '1'],
             1 => ['logo' => '', 'name' => '', 'url' => 'https://x.example']], 'partners_layout' => 'zigzag', 'partners_height' => '999', 'partners_speed' => 'fast']);
check('sanitize: partners', count($s2['partners']) === 2 && $s2['partners'][0]['new_tab'] === 1 && $s2['partners_layout'] === 'grid'
    && (int) $s2['partners_height'] === 200 && $s2['partners_speed'] === 'fast');

/* ---------- Подключение шрифтов ---------- */
set_opts(['font_files' => [['family' => 'Golos Text', 'url' => 'https://ex.com/g.woff2', 'weight' => '400', 'style' => 'normal']],
          'font_links' => [['url' => 'https://use.typekit.net/k.css', 'families' => 'proxima-nova']], 'google_fonts' => 'Montserrat']);
wp_deregister_style('wpd-design');
wp_deregister_style('wpd-google-fonts');
wp_deregister_style('wpd-font-link-0');
WPD_Renderer::register_assets();
$st = wp_styles()->registered['wpd-design'] ?? null;
check('assets: font link registered as dependency', $st && in_array('wpd-font-link-0', $st->deps, true) && in_array('wpd-google-fonts', $st->deps, true)
    && wp_styles()->registered['wpd-font-link-0']->src === 'https://use.typekit.net/k.css');
$inline = implode("\n", (array) ($st->extra['after'] ?? []));
check('assets: @font-face inline', has($inline, '@font-face{font-family:"Golos Text";src:url("https://ex.com/g.woff2")'));

// Шрифты страницы
$pid = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'W fonts', 'post_content' => 'x']);
$created[] = $pid;
update_post_meta($pid, '_wpd_enabled', 1);
$g = WPD_Settings::get_global();
$pov = [];
foreach (WPD_Page_Editor::group_keys('page') as $k) { $pov[$k] = $g[$k]; }
check('page group has font fields', count(array_intersect(['font_files', 'font_links', 'font_hero', 'font_button', 'font_numbers'], WPD_Page_Editor::group_keys('page'))) === 5);
$pov['font_files'] = [['family' => 'Page Font', 'url' => 'https://ex.com/p.woff', 'weight' => '400', 'style' => 'normal']];
$pov['font_links'] = [['url' => 'https://use.typekit.net/k.css', 'families' => ''], ['url' => 'https://fonts.bunny.net/css?family=inter:400', 'families' => 'Inter']];
$pov['font_hero'] = '"Page Font", serif';
$saved = WPD_Page_Editor::sanitize(['ov' => $pov, 'ovg' => ['page' => 1]], $pid, false);
update_post_meta($pid, '_wpd_page', $saved);
$po = WPD_Page_Editor::options($pid);
check('page options: own fonts', $po['font_hero'] === '"Page Font", serif' && $po['font_files'][0]['family'] === 'Page Font');
$enq = [];
$faces = WPD_Renderer::page_font_assets($po, function ($h, $src) use (&$enq) { $enq[$h] = $src; });
check('page fonts: only new links enqueued', $enq === ['wpd-font-link-page-1' => 'https://fonts.bunny.net/css?family=inter:400'], wp_json_encode($enq));
check('page fonts: own @font-face', has($faces, 'font-family:"Page Font"') && !has($faces, 'Golos'));
check('page fonts: same as global -> nothing', WPD_Renderer::page_font_assets(WPD_Settings::get_global(), function () { throw new Exception('no'); }) === '');
$GLOBALS['post'] = get_post($pid);
setup_postdata($GLOBALS['post']);
WPD_Settings::set_page($pid);
$html = WPD_Renderer::page_html($pid, '');
WPD_Settings::set_page(0);
check('page render: element font class and var', has($html, 'wpd-ff-hero') && has($html, '--wpd-font-hero:&quot;Page Font&quot;, serif'));

/* ---------- Логотип главного блока ---------- */
$base = WPD_Settings::get_global();
$h = function (array $over, array $args = []) use ($base) { return WPD_Renderer::hero_html(array_merge($base, $over), $args); };
$plain = $h([]);
check('hero: no logo by default', !has($plain, 'wpd-hero__logo') && !has($plain, 'wpd-hero--logo-'));
$L = 'https://ex.com/logo.svg'; $D = 'https://ex.com/logo-white.svg';
$x = $h(['hero_logo' => $L, 'hero_logo_dark' => $D, 'hero_style' => 'light']);
check('hero: light style -> main logo above title', has($x, 'wpd-hero--logo-above') && has($x, 'src="' . $L . '"') && !has($x, $D)
    && strpos($x, 'wpd-hero__logo') < strpos($x, 'wpd-hero__title'));
$x = $h(['hero_logo' => $L, 'hero_logo_dark' => $D, 'hero_style' => 'dark']);
check('hero: dark style -> dark variant', has($x, 'src="' . $D . '"') && has($x, 'wpd-hero__logo--dark'));
$x = $h(['hero_logo' => $L, 'hero_logo_dark' => $D, 'hero_style' => 'gradient']);
check('hero: gradient -> dark variant', has($x, 'src="' . $D . '"'));
$x = $h(['hero_logo' => $L, 'hero_logo_dark' => $D, 'hero_style' => 'dark', 'hero_bg' => '#fde68a']);
check('hero: light custom bg -> main logo', has($x, 'src="' . $L . '"'));
$x = $h(['hero_logo' => $L, 'hero_logo_dark' => $D, 'hero_style' => 'light', 'hero_bg' => '#111827']);
check('hero: dark custom bg -> dark variant', has($x, 'src="' . $D . '"'));
$x = $h(['hero_logo' => $L, 'hero_logo_dark' => $D, 'hero_style' => 'light', 'hero_image_url' => 'https://ex.com/bg.jpg', 'hero_image_position' => 'background', 'hero_overlay' => 50]);
check('hero: photo with dark overlay -> dark variant', has($x, 'src="' . $D . '"'));
$x = $h(['hero_logo' => $L, 'hero_logo_dark' => $D, 'hero_style' => 'light', 'hero_image_url' => 'https://ex.com/bg.jpg', 'hero_image_position' => 'background', 'hero_overlay' => 50, 'hero_overlay_color' => '#ffffff']);
check('hero: photo with white overlay -> main logo', has($x, 'src="' . $L . '"'));
$x = $h(['hero_logo' => '', 'hero_logo_dark' => $D, 'hero_style' => 'light']);
check('hero: only dark variant -> used anyway', has($x, 'src="' . $D . '"'));
$x = $h(['hero_logo' => $L, 'hero_logo_pos' => 'beside', 'hero_logo_height' => 80, 'hero_logo_alt' => 'Кванториум', 'hero_logo_url' => 'https://kvant.example/']);
check('hero: beside -> heading wrapper', has($x, 'wpd-hero--logo-beside') && preg_match('#<div class="wpd-hero__heading"><div class="wpd-hero__logo">.*?</div><h1 class="wpd-hero__title">#s', $x) === 1);
check('hero: height var, alt, external link', has($x, '--wpd-hero-logo-h:80px') && has($x, 'alt="Кванториум"') && has($x, 'href="https://kvant.example/" target="_blank" rel="noopener noreferrer"'));
$x = $h(['hero_logo' => $L, 'hero_logo_url' => 'https://kvant.example/']);
check('hero: link without alt has aria-label', has($x, 'aria-label="Сайт организации"') && has($x, 'alt=""'));
$x = $h(['hero_logo' => $L, 'hero_logo_height' => 5000]);
check('hero: height clamped', has($x, '--wpd-hero-logo-h:240px'));
$x = $h(['hero_logo' => 'javascript:alert(1)']);
check('hero: unsafe logo url ignored', !has($x, 'wpd-hero__logo') && !has($x, 'javascript'));
check('hero: logo css', has($cssfile, '.wpd-page .wpd-hero__logo-img') && has($cssfile, 'height: var(--wpd-hero-logo-h, 56px)') && has($cssfile, '.wpd-page .wpd-hero__heading'));

/* ---------- Логотип контактов ---------- */
$c = array_merge($base, ['contact_org' => 'Орг', 'contact_logo' => 'custom', 'contact_logo_url' => 'https://ex.com/c.svg', 'contact_logo_height' => 120]);
$x = WPD_Renderer::contacts_html($c);
check('contacts: custom logo with height', has($x, 'src="https://ex.com/c.svg"') && has($x, 'style="--wpd-contacts-logo-h:120px"'));
$x = WPD_Renderer::contacts_html(array_merge($c, ['contact_logo_height' => 0]));
check('contacts: height 0 -> default', has($x, '<div class="wpd-contacts__logo"><img'));
$x = WPD_Renderer::contacts_html(array_merge($c, ['contact_logo' => 'none']));
check('contacts: none', !has($x, 'wpd-contacts__logo'));
$x = WPD_Renderer::contacts_html(array_merge($c, ['contact_logo' => 'builtin']));
check('contacts: builtin', has($x, 'kvantorium-logo.webp'));

/* ---------- Логотипы партнёров ---------- */
$P = [
    ['logo' => 'https://ex.com/a.svg', 'name' => 'Альфа', 'url' => 'https://alpha.example/', 'new_tab' => 0, 'hidden' => 0],
    ['logo' => 'https://ex.com/b.png', 'name' => 'Бета', 'url' => '', 'new_tab' => 0, 'hidden' => 0],
    ['logo' => '', 'name' => 'Гамма текстом', 'url' => home_url('/gamma/'), 'new_tab' => 0, 'hidden' => 0],
    ['logo' => 'https://ex.com/h.png', 'name' => 'Скрытый', 'url' => '', 'new_tab' => 0, 'hidden' => 1],
    ['logo' => 'https://ex.com/j.png', 'name' => 'Опасный', 'url' => 'javascript:alert(1)', 'new_tab' => 0, 'hidden' => 0],
];
$po2 = array_merge($base, ['partners' => $P]);
check('partners: empty -> nothing', WPD_Renderer::partners_html($base) === '' && WPD_Renderer::partners_html(array_merge($base, ['partners' => [['logo' => '', 'name' => '', 'hidden' => 0]]])) === '');
$x = WPD_Renderer::partners_html($po2);
check('partners: grid defaults', has($x, 'class="wpd-partners wpd-partners--grid wpd-partners--gray wpd-partners--cards"') && has($x, 'style="--wpd-pt-h:64px;"')
    && has($x, '<h2 class="wpd-h2">Партнёры</h2>'));
check('partners: 4 visible items, hidden skipped', substr_count($x, '<li class="wpd-partners__item"') === 4 && !has($x, 'Скрытый'));
check('partners: external link new tab, alt from name', has($x, 'href="https://alpha.example/" target="_blank"') && has($x, 'alt="Альфа"') && has($x, 'loading="lazy"'));
check('partners: own link same tab', has($x, 'href="' . esc_url(home_url('/gamma/')) . '">') && has($x, '<span class="wpd-partners__text">Гамма текстом</span>'));
check('partners: no link -> span, javascript dropped', has($x, '<span class="wpd-partners__link"><img class="wpd-partners__logo" src="https://ex.com/b.png"') && !has($x, 'javascript'));
check('partners: names hidden by default', !has($x, 'wpd-partners__name'));
$x = WPD_Renderer::partners_html($po2, ['layout' => 'grid', 'columns' => '5', 'gray' => 0, 'cards' => 0, 'names' => 1, 'height' => 90, 'title' => '']);
check('partners: args override', has($x, 'wpd-partners--cols') && has($x, '--wpd-pt-cols:5;') && has($x, '--wpd-pt-h:90px;') && !has($x, 'wpd-partners--gray')
    && !has($x, 'wpd-partners--cards') && has($x, '<span class="wpd-partners__name">Альфа</span>') && !has($x, '<h2'));
$x = WPD_Renderer::partners_html($po2, ['layout' => 'strip', 'speed' => 'slow']);
check('partners: strip marquee duplicates items', has($x, 'wpd-partners--strip') && has($x, 'wpd-partners--marquee') && substr_count($x, '<li class="wpd-partners__item"') === 8
    && substr_count($x, 'aria-hidden="true"') === 4 && has($x, '--wpd-pt-dur:18s;'));
check('partners: clones not focusable, no alt', has($x, 'tabindex="-1"') && substr_count($x, 'alt="Альфа"') === 1);
$x = WPD_Renderer::partners_html($po2, ['layout' => 'strip', 'speed' => 'none']);
check('partners: strip without scroll', !has($x, 'marquee') && substr_count($x, '<li') === 4);
$x = WPD_Renderer::partners_html(array_merge($base, ['partners' => [$P[0]]]), ['layout' => 'strip']);
check('partners: single logo no marquee', !has($x, 'marquee'));
$x = WPD_Renderer::partners_html($po2, ['layout' => 'grid', 'columns' => '9', 'height' => 3]);
check('partners: bad columns ignored, height min', !has($x, 'wpd-partners--cols') && has($x, '--wpd-pt-h:24px;'));
check('partners: css', has($cssfile, '@keyframes wpd-partners-marquee') && has($cssfile, 'prefers-reduced-motion: reduce') && has($cssfile, 'animation-play-state: paused')
    && has($cssfile, '.wpd-page .wpd-partners--gray .wpd-partners__item:is(:hover, :focus-within) .wpd-partners__logo'));
set_opts(['partners' => $P]);
check('shortcode registered', shortcode_exists('wpd_partners') && in_array('wpd_partners', WPD_Renderer::SHORTCODES, true));
$x = do_shortcode('[wpd_partners]');
check('shortcode output', has($x, 'class="wpd-page') && has($x, 'wpd-partners--grid') && has($x, 'Альфа'));
check('part_html partners', has(WPD_Renderer::part_html('partners', WPD_Settings::get()), 'wpd-partners'));

// Панель страницы
$blocks = WPD_Page_Editor::blocks();
check('panel: partners block after contacts', count($blocks) === 13 && array_key_last($blocks) === 'partners');
check('panel: partners opt-in', in_array('partners', WPD_Page_Editor::OPT_IN, true) && in_array('partners', WPD_Page_Editor::defaults()['hidden'], true));
check('panel: partners group', isset(WPD_Page_Editor::groups()['partners']) && in_array('partners', WPD_Page_Editor::group_keys('partners'), true)
    && in_array('partners_layout', WPD_Page_Editor::group_keys('partners'), true));
check('panel: hero group has logo fields', count(array_intersect(['hero_logo', 'hero_logo_dark', 'hero_logo_pos', 'hero_logo_height', 'hero_logo_alt', 'hero_logo_url'], WPD_Page_Editor::group_keys('hero'))) === 6);
$pid2 = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'W partners', 'post_content' => 'Текст']);
$created[] = $pid2;
update_post_meta($pid2, '_wpd_enabled', 1);
$order = array_keys($blocks);
$sv = WPD_Page_Editor::sanitize(['order' => $order, 'show' => ['hero' => 1, 'content' => 1, 'partners' => 1]], $pid2, false);
check('panel: partners visible after save', !in_array('partners', $sv['hidden'], true) && in_array('partners', $sv['order'], true));
update_post_meta($pid2, '_wpd_page', $sv);
$GLOBALS['post'] = get_post($pid2);
setup_postdata($GLOBALS['post']);
WPD_Settings::set_page($pid2);
$html = WPD_Renderer::page_html($pid2, 'Текст');
WPD_Settings::set_page(0);
check('panel: page renders partners', has($html, 'wpd-partners') && has($html, 'Альфа'));
update_post_meta($pid2, '_wpd_page', array_merge($sv, ['order' => array_values(array_diff($order, ['partners'])), 'hidden' => []]));
$old = WPD_Page_Editor::get($pid2);
check('panel: old saved order -> partners hidden, at end', in_array('partners', $old['hidden'], true) && end($old['order']) === 'partners');

/* ---------- Блоки ---------- */
$defs = WPD_Blocks::definitions();
check('blocks: 12 definitions with partners', count($defs) === 12 && isset($defs['partners']));
check('block registered', WP_Block_Type_Registry::get_instance()->is_registered('wpd/partners'));
$all_font = true;
foreach ($defs as $slug => $def) {
    if (!isset($def['attributes']['fontText'], $def['attributes']['fontHeading']) || $def['attributes']['fontText']['default'] !== '') { $all_font = false; }
}
check('blocks: all have fontText/fontHeading', $all_font);
check('blocks: element font attrs where relevant', isset($defs['hero']['attributes']['fontButtons'], $defs['hero']['attributes']['fontNumbers'], $defs['stats']['attributes']['fontNumbers'],
    $defs['buttons']['attributes']['fontButtons']) && !isset($defs['docs']['attributes']['fontButtons']) && !isset($defs['faq']['attributes']['fontNumbers']));
check('blocks: hero logo attrs', isset($defs['hero']['attributes']['logoUrl'], $defs['hero']['attributes']['logoDarkUrl'], $defs['hero']['attributes']['logoPos'],
    $defs['hero']['attributes']['logoHeight'], $defs['hero']['attributes']['logoAlt'], $defs['hero']['attributes']['logoLink']));
check('blocks: contacts logo attrs', isset($defs['contacts']['attributes']['logo'], $defs['contacts']['attributes']['logoUrl'], $defs['contacts']['attributes']['logoHeight']));
check('blocks: partners attrs default from settings', count($defs['partners']['attributes']['items']['default']) === 5 && $defs['partners']['attributes']['layout']['default'] === 'grid');
$r = render_block(['blockName' => 'wpd/stats', 'attrs' => ['fontText' => '"Golos Text", sans-serif', 'fontNumbers' => 'Мой цифровой;}x{'], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []]);
check('block fonts: vars on root', has($r, '--wpd-font:&quot;Golos Text&quot;, sans-serif') && has($r, '--wpd-font-num:Мой цифровойx') && has($r, 'wpd-ff-num'));
$r = render_block(['blockName' => 'wpd/hero', 'attrs' => ['fontHeading' => 'Unbounded'], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []]);
check('block fonts: hero heading -> hero title font too', has($r, '--wpd-font-heading:Unbounded') && has($r, '--wpd-font-hero:Unbounded') && has($r, 'wpd-ff-hero'));
$r = render_block(['blockName' => 'wpd/docs', 'attrs' => ['fontText' => '', 'fontButtons' => 'Ignored'], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []]);
check('block fonts: empty and unknown ignored', !has($r, 'Ignored') && !has($r, 'wpd-ff-btn'));
$r = render_block(['blockName' => 'wpd/hero', 'attrs' => ['logoUrl' => $L, 'logoDarkUrl' => $D, 'variant' => 'dark', 'logoPos' => 'beside', 'logoHeight' => 40, 'logoAlt' => 'Лого'], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []]);
check('block hero: logo attrs', has($r, 'src="' . $D . '"') && has($r, 'wpd-hero--logo-beside') && has($r, '--wpd-hero-logo-h:40px') && has($r, 'alt="Лого"'));
$r = render_block(['blockName' => 'wpd/contacts', 'attrs' => ['org' => 'Орг', 'logo' => 'custom', 'logoUrl' => 'https://ex.com/z.svg', 'logoHeight' => 64], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []]);
check('block contacts: logo attrs', has($r, 'src="https://ex.com/z.svg"') && has($r, '--wpd-contacts-logo-h:64px'));
$r = render_block(['blockName' => 'wpd/contacts', 'attrs' => ['org' => 'Орг', 'logo' => 'evil'], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []]);
check('block contacts: bad mode -> auto', !has($r, 'wpd-contacts__logo'));
$r = render_block(['blockName' => 'wpd/partners', 'attrs' => ['title' => 'Друзья', 'layout' => 'strip', 'speed' => 'fast', 'grayscale' => false, 'showNames' => true,
    'items' => [['logo' => 'https://ex.com/q.png', 'name' => 'Ку'], ['logo' => 'https://ex.com/w.png', 'name' => 'Вэ', 'url' => 'https://w.example', 'new_tab' => 1]]], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []]);
check('block partners: render', has($r, 'wpd-page--partners') && has($r, '>Друзья</h2>') && has($r, 'wpd-partners--marquee') && has($r, '--wpd-pt-dur:3.6s;')
    && !has($r, 'wpd-partners--gray') && has($r, 'wpd-partners__name'));
$r = render_block(['blockName' => 'wpd/partners', 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []]);
check('block partners: no attrs -> settings', has($r, 'Альфа') && has($r, 'wpd-partners--grid'));
$r = render_block(['blockName' => 'wpd/partners', 'attrs' => ['items' => []], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []]);
check('block partners: empty items -> nothing', trim($r) === '');
$fa = WPD_Blocks::font_attrs_data();
check('editor data: font attrs', $fa['hero']['fontHeading']['set'] === [['--wpd-font-heading', ''], ['--wpd-font-hero', 'wpd-ff-hero']] && isset($fa['partners']['fontText']));
$jsb = file_get_contents($dir . 'assets/js/wpd-blocks.js');
check('blocks js: font panel, partners edit, logo panels', has($jsb, 'function FontPanel') && has($jsb, 'function PartnersEdit') && has($jsb, 'partners: PartnersEdit')
    && has($jsb, "title: 'Логотип'") && has($jsb, 'withFonts(slug, editors[slug])'));
$jss = file_get_contents($dir . 'assets/js/wpd-sidebar.js');
check('sidebar js: element fonts', has($jss, "o('font_hero')") && has($jss, "o('font_button')") && has($jss, "o('font_numbers')") && has($jss, "list: 'wpd-font-list'"));

/* ---------- Поля настроек ---------- */
ob_start();
WPD_Settings::render_field(['key' => 'font_hero', 'value' => 'X']);
WPD_Settings::render_field(['key' => 'font_button', 'value' => '']);
$f = ob_get_clean();
check('font field: list attribute, datalist once', substr_count($f, 'list="wpd-font-list"') === 2 && substr_count($f, '<datalist id="wpd-font-list">') <= 1);
ob_start();
WPD_Settings::render_field(['key' => 'font_files', 'value' => [['family' => 'Golos', 'url' => 'https://ex.com/g.woff2', 'weight' => '700', 'style' => 'italic']]]);
$f = ob_get_clean();
check('font files field: file picker and selects', has($f, 'data-wpd-s-kind="file"') && has($f, '<option value="700" selected') && has($f, '<option value="italic" selected')
    && has($f, 'name="wpd_settings[font_files][__i__][weight]"'));
$jset = file_get_contents($dir . 'assets/js/wpd-settings.js');
check('settings js: font file picker', has($jset, "data-wpd-s-kind') === 'file'"));

/* ---------- Загрузка SVG и шрифтов ---------- */
check('dom available', class_exists('DOMDocument'));
$m = WPD_Uploads::upload_mimes([], null);
check('mimes: admin gets svg and fonts', ($m['svg'] ?? '') === 'image/svg+xml' && isset($m['woff2'], $m['woff'], $m['ttf'], $m['otf']));
check('mimes: in get_allowed_mime_types', isset(get_allowed_mime_types()['svg']));
if ($old = get_user_by('login', 'wpdw_editor')) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user($old->ID); }
$ed = wp_insert_user(['user_login' => 'wpdw_editor', 'user_pass' => wp_generate_password(), 'role' => 'editor', 'user_email' => 'wpdw@example.com']);
$m = WPD_Uploads::upload_mimes([], $ed);
check('mimes: editor gets nothing', !isset($m['svg']) && !isset($m['woff2']));
wp_set_current_user($ed);
check('mimes: current editor', !isset(get_allowed_mime_types()['svg']));
$r = sideload('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>');
check('editor: svg upload rejected', !empty($r['error']), wp_json_encode($r));
wp_set_current_user(1);

$S = WPD_Uploads::sanitize_svg('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 20"><script>alert(1)</script><rect width="5" height="5" onclick="alert(1)" onload="x()" fill="red"/></svg>');
check('svg: script and handlers removed', $S !== null && !has($S, 'script') && !has($S, 'onclick') && !has($S, 'onload') && has($S, '<rect width="5" height="5" fill="red"/>'));
check('svg: size from viewBox', has($S, 'width="10" height="20"'));
$S = WPD_Uploads::sanitize_svg('<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><a href="javascript:alert(1)"><circle r="3"/></a><use xlink:href="#a"/><use xlink:href="https://evil.example/x.svg#a"/><image href="java&#x09;script:alert(1)"/><image href="data:image/png;base64,AAAA"/></svg>');
check('svg: links', $S !== null && !has($S, 'javascript') && !has($S, '<a') && has($S, 'xlink:href="#a"') && !has($S, 'evil.example') && has($S, 'href="data:image/png;base64,AAAA"'));
$S = WPD_Uploads::sanitize_svg('<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><iframe xmlns="http://www.w3.org/1999/xhtml" src="x"/></foreignObject><set attributeName="href" to="javascript:alert(1)"/><animate attributeName="onclick" values="x"/><animate attributeName="opacity" values="0;1" dur="1s"/><style>@import url(http://x);</style><style>.a{fill:red}</style><path style="fill:url(#g)" d="M0 0"/><path style="background:url(http://x)" d="M1 1"/></svg>');
check('svg: foreignObject, href animation, bad styles removed', $S !== null && !has($S, 'foreignObject') && !has($S, 'iframe') && !has($S, '<set') && !has($S, 'onclick')
    && has($S, 'attributeName="opacity"') && !has($S, '@import') && has($S, '.a{fill:red}') && has($S, 'style="fill:url(#g)"') && !has($S, 'http://x'));
check('svg: entities rejected', WPD_Uploads::sanitize_svg('<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg"><text>&x;</text></svg>') === null);
check('svg: billion laughs rejected', WPD_Uploads::sanitize_svg('<!DOCTYPE svg [<!ENTITY a "aaaa"><!ENTITY b "&a;&a;&a;">]><svg xmlns="http://www.w3.org/2000/svg"><text>&b;</text></svg>') === null);
$S = WPD_Uploads::sanitize_svg('<?xml version="1.0"?><!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd"><svg xmlns="http://www.w3.org/2000/svg" xmlns:inkscape="http://www.inkscape.org/namespaces/inkscape" inkscape:version="1" width="40px" height="20"><!-- c --><?php echo 1; ?><g inkscape:label="L"><path d="M0 0h4"/></g><inkscape:extra/></svg>');
check('svg: plain doctype, comments, PI, editor attrs removed', $S !== null && !has($S, 'DOCTYPE') && !has($S, '<!--') && !has($S, '<?php') && !has($S, 'inkscape:version')
    && !has($S, 'inkscape:label') && !has($S, 'inkscape:extra') && has($S, '<path d="M0 0h4"/>') && has($S, 'width="40px"'));
check('svg: broken, html, empty, gzip rejected', WPD_Uploads::sanitize_svg('<svg><g></svg>') === null && WPD_Uploads::sanitize_svg('<html><body>x</body></html>') === null
    && WPD_Uploads::sanitize_svg('') === null && WPD_Uploads::sanitize_svg("\x1f\x8b\x08\x00abc") === null);
check('svg: too big rejected', WPD_Uploads::sanitize_svg('<svg xmlns="http://www.w3.org/2000/svg"><desc>' . str_repeat('a', 2100000) . '</desc></svg>') === null);
$S = WPD_Uploads::sanitize_svg('<svg viewBox="0 0 8 4"><SCRIPT>alert(1)</SCRIPT><path d="M0 0"/></svg>');
check('svg: no namespace -> added; uppercase script removed', $S !== null && has($S, 'xmlns="http://www.w3.org/2000/svg"') && !has(strtolower($S), 'script'));

// Настоящая загрузка
$r = sideload('w-logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 40" onload="alert(1)"><script>alert(2)</script><rect width="120" height="40" fill="#06c"/></svg>');
check('upload svg: accepted as image/svg+xml', empty($r['error']) && ($r['type'] ?? '') === 'image/svg+xml', wp_json_encode($r));
$stored = !empty($r['file']) ? (string) file_get_contents($r['file']) : '';
check('upload svg: stored file sanitized', has($stored, '<rect') && !has($stored, 'script') && !has($stored, 'onload'));
if (!empty($r['file'])) { $files[] = $r['file']; }
$r = sideload('w-evil.svg', '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg"><text>&x;</text></svg>');
check('upload svg: entity file rejected', !empty($r['error']) && has((string) $r['error'], 'SVG не прошёл проверку'));
$woff2 = (string) file_get_contents(ABSPATH . 'wp-includes/fonts/dashicons.woff2');
$r = sideload('w-font.woff2', $woff2);
check('upload woff2: accepted', empty($r['error']) && ($r['type'] ?? '') === 'font/woff2' && substr((string) ($r['url'] ?? ''), -6) === '.woff2', wp_json_encode($r));
if (!empty($r['file'])) { $files[] = $r['file']; }
$r = sideload('w-fake.woff2', '<?php echo "x"; ?>');
check('upload fake font: rejected', !empty($r['error']));
$r = sideload('w-font.ttf', (string) file_get_contents(ABSPATH . 'wp-includes/fonts/dashicons.ttf'));
check('upload ttf: accepted', empty($r['error']) && ($r['type'] ?? '') === 'font/ttf', wp_json_encode($r));
if (!empty($r['file'])) { $files[] = $r['file']; }
check('font signature helper', WPD_Uploads::font_signature_ok(ABSPATH . 'wp-includes/fonts/dashicons.woff2', 'woff2') && !WPD_Uploads::font_signature_ok(ABSPATH . 'wp-includes/fonts/dashicons.woff2', 'otf'));

// Вложение SVG: размеры и превью
$tmp = tmpfile_with('w-att.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 300 100"><rect width="300" height="100"/></svg>');
$att = media_handle_sideload(['name' => 'w-att.svg', 'tmp_name' => $tmp], 0);
check('attachment svg created', is_int($att) && $att > 0, is_wp_error($att) ? $att->get_error_message() : '');
if (is_int($att)) {
    $meta = wp_get_attachment_metadata($att);
    check('attachment svg: width/height from viewBox', (int) ($meta['width'] ?? 0) === 300 && (int) ($meta['height'] ?? 0) === 100, wp_json_encode($meta));
    $js = wp_prepare_attachment_for_js($att);
    check('attachment svg: preview size for media modal', isset($js['sizes']['full']['url']) && $js['sizes']['full']['width'] === 300);
    wp_delete_attachment($att, true);
}
list($sw, $sh) = WPD_Uploads::svg_size(tmpfile_with('s.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="64px" height="32" viewBox="0 0 1 1"/>'));
check('svg_size: attributes win over viewBox', $sw === 64 && $sh === 32);

/* ---------- Справка и README ---------- */
ob_start();
$mm = new ReflectionMethod('WPD_Settings', 'render_help');
$mm->setAccessible(true);
$mm->invoke(null);
$help = ob_get_clean();
check('help: shortcode and fonts section', has($help, '[wpd_partners]') && has($help, 'Свои шрифты и логотипы') && has($help, 'только администраторы'));
$readme = file_get_contents($dir . 'README.md');
check('readme: fonts, logos, partners', has($readme, 'Свои шрифты') && has($readme, '[wpd_partners]') && has($readme, 'SVG'));

/* ---------- Уборка ---------- */
foreach ($created as $id) { wp_delete_post($id, true); }
foreach ($files as $f) { if (is_file($f)) { unlink($f); } }
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user($ed);
set_opts(is_array($backup) ? $backup : []);
check('cleanup', !get_user_by('login', 'wpdw_editor'));

echo implode("\n", $results) . "\n";
echo "TOTAL: " . count($results) . ", FAILED: $fails\n";
