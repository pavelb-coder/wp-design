<?php
// Фаза M (1.9.0): ещё параметры главного блока и вкладки «Оформление».
// PHP-часть: поля и подразделы, санитизация (включая шрифты Google и свой CSS), переменные и классы, подключение шрифтов и CSS,
// вывод главного блока (иконки, таймер внутри, изображение, цвета, размеры), страница и [wpd_page] без двойного таймера, блок, данные редактора, справка.
// В конце удаляет опцию и свои записи.
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
function hero(array $args = []): string {
    return WPD_Renderer::hero_html(WPD_Settings::get(), $args);
}
function msk(string $rel): string {
    return (new DateTime($rel, new DateTimeZone('Europe/Moscow')))->format('Y-m-d H:i');
}
function reregister(): void {
    wp_deregister_style('wpd-design');
    wp_deregister_style('wpd-google-fonts');
    wp_deregister_script('wpd-design');
    WPD_Renderer::register_assets();
}

wp_set_current_user(1);
WPD_Settings::register();
delete_option('wpd_settings');
WPD_Settings::flush_cache();

$map  = WPD_Settings::field_map();
$keys = array_keys($map);
$tabs = WPD_Settings::tabs();
$secs = WPD_Settings::sections();
$look_new = ['heading_weight', 'heading_scale', 'line_height', 'link_color', 'button_text_color', 'border_width', 'section_title_align', 'alt_sections'];
$hero_new = ['hero_button_icon', 'hero_button2_icon', 'hero_countdown', 'hero_image_fit', 'hero_image_style', 'hero_image_mobile', 'hero_overlay_color',
    'hero_badge_color', 'hero_title_color', 'hero_text_color', 'hero_min_height', 'hero_padding', 'hero_valign', 'hero_text_width'];
$field_tab = function (string $k) use ($map, $tabs): string {
    foreach ($tabs as $id => $t) { if (in_array($map[$k]['section'], $t['sections'], true)) { return $id; } }
    return '';
};
$count_tab = function (string $tab) use ($keys, $field_tab): int { return count(array_filter($keys, function ($k) use ($field_tab, $tab) { return $field_tab($k) === $tab; })); };

/* 1. Поля, подразделы, значения по умолчанию */
check('version 1.9.0', version_compare(WPD_VERSION, '1.9.0', '>='));
check('112 fields', count($map) === 112, (string) count($map));
check('design tab: colors, look, behavior, code', $tabs['design']['sections'] === ['colors', 'look', 'behavior', 'code']);
check('hero tab: 3 subsections', $tabs['hero']['sections'] === ['hero', 'hero_image', 'hero_look']);
check('subsection titles', $secs['code'] === 'Шрифты Google и свой CSS' && $secs['hero'] === 'Текст и кнопки' && $secs['hero_image'] === 'Изображение' && $secs['hero_look'] === 'Вид главного блока');
check('design 29 fields, hero 32', $count_tab('design') === 29 && $count_tab('hero') === 32, $count_tab('design') . '/' . $count_tab('hero'));
check('8 new look fields in look', count(array_filter($look_new, function ($k) use ($map) { return $map[$k]['section'] === 'look'; })) === 8);
check('fonts and css in code section', $map['google_fonts']['section'] === 'code' && $map['custom_css']['section'] === 'code' && $map['google_fonts']['type'] === 'fonts' && $map['custom_css']['type'] === 'css');
check('14 new hero fields on hero tab', count(array_filter($hero_new, function ($k) use ($field_tab) { return $field_tab($k) === 'hero'; })) === 14);
check('buttons and timer in «Текст и кнопки»', $map['hero_button_icon']['section'] === 'hero' && $map['hero_button2_icon']['section'] === 'hero' && $map['hero_countdown']['section'] === 'hero');
check('image fields in «Изображение»', count(array_filter(['hero_image_url', 'hero_image_alt', 'hero_image_position', 'hero_image_fit', 'hero_image_style', 'hero_image_mobile', 'hero_overlay', 'hero_overlay_color'],
    function ($k) use ($map) { return $map[$k]['section'] === 'hero_image'; })) === 8);
check('look fields in «Вид главного блока»', count(array_filter(['hero_style', 'hero_bg', 'hero_badge_color', 'hero_title_color', 'hero_text_color', 'hero_height', 'hero_min_height', 'hero_padding', 'hero_valign', 'hero_align', 'hero_text_width', 'hero_title_size'],
    function ($k) use ($map) { return $map[$k]['section'] === 'hero_look'; })) === 12);
check('icon select: none + 46 icons', $map['hero_button_icon']['type'] === 'select' && array_keys($map['hero_button_icon']['options'])[0] === '' && count($map['hero_button_icon']['options']) === count(WPD_Settings::icons()) + 1);
$d = WPD_Settings::get();
check('defaults: design looks as 1.8.0', $d['heading_weight'] === '800' && $d['heading_scale'] === 'normal' && $d['line_height'] === 'normal' && $d['link_color'] === '' && $d['button_text_color'] === ''
    && $d['border_width'] === 1 && $d['section_title_align'] === 'left' && $d['alt_sections'] === 0 && $d['google_fonts'] === '' && $d['custom_css'] === '');
check('defaults: hero looks as 1.8.0', $d['hero_button_icon'] === '' && $d['hero_countdown'] === 0 && $d['hero_image_fit'] === 'cover' && $d['hero_image_style'] === 'rounded' && $d['hero_image_mobile'] === 1
    && $d['hero_overlay_color'] === '' && $d['hero_min_height'] === 0 && $d['hero_padding'] === 0 && $d['hero_valign'] === 'center' && $d['hero_text_width'] === 0);

/* 2. Санитизация */
$s = WPD_Settings::sanitize(['_tab' => 'design', 'heading_weight' => '900', 'heading_scale' => 'huge', 'line_height' => 'relaxed', 'link_color' => 'blue', 'button_text_color' => '#111827',
    'border_width' => '9', 'section_title_align' => 'center', 'alt_sections' => '1']);
check('weight 900 -> 800 (not in list)', $s['heading_weight'] === '800');
check('scale invalid -> normal, line height kept', $s['heading_scale'] === 'normal' && $s['line_height'] === 'relaxed');
check('link color invalid -> empty, button text color kept', $s['link_color'] === '' && $s['button_text_color'] === '#111827');
check('border clamped to 4', $s['border_width'] === 4);
check('title align, alt sections', $s['section_title_align'] === 'center' && $s['alt_sections'] === 1);
$s = WPD_Settings::sanitize(['_tab' => 'design', 'heading_weight' => '400', 'border_width' => '-3']);
check('weight 400 kept, border min 0', $s['heading_weight'] === '400' && $s['border_width'] === 0);
check('unchecked alt sections -> 0', $s['alt_sections'] === 0);
$s = WPD_Settings::sanitize(['_tab' => 'design', 'google_fonts' => "Montserrat:400,700,700,950\r\nPT  Serif\n<script>alert(1)</script>\nOpen Sans: 300;600\nmontserrat:300\nA\nB\nC\nD"]);
check('fonts: cleaned, deduped, max 5', $s['google_fonts'] === "Montserrat:400,700\nPT Serif\nOpen Sans:300,600\nA\nB", json_encode($s['google_fonts']));
check('fonts: url', WPD_Settings::google_fonts_url($s) === 'https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&family=PT+Serif&family=Open+Sans:wght@300;600&family=A&family=B&display=swap', WPD_Settings::google_fonts_url($s));
check('fonts: empty -> no url', WPD_Settings::google_fonts_url(['google_fonts' => "\n<b>\n"]) === '');
$s = WPD_Settings::sanitize(['_tab' => 'design', 'custom_css' => "  .wpd-page .wpd-hero{width:50%;content:\"a\"}</style><script>x</script>  "]);
check('css: «<» removed, % and quotes kept', $s['custom_css'] === '.wpd-page .wpd-hero{width:50%;content:"a"}/style>script>x/script>', $s['custom_css']);
$s = WPD_Settings::sanitize(['_tab' => 'design', 'custom_css' => str_repeat('a', 25000)]);
check('css: length limit 20000', strlen($s['custom_css']) === 20000);
$s = WPD_Settings::sanitize(['_tab' => 'hero', 'hero_button_icon' => 'awards', 'hero_button2_icon' => 'evil', 'hero_countdown' => '1', 'hero_image_fit' => 'stretch', 'hero_image_style' => 'circle',
    'hero_overlay_color' => '#1e3a8a', 'hero_badge_color' => 'x', 'hero_title_color' => '#ffffff', 'hero_min_height' => '5000', 'hero_padding' => '500', 'hero_valign' => 'bottom', 'hero_text_width' => '9999']);
check('hero: icons validated', $s['hero_button_icon'] === 'awards' && $s['hero_button2_icon'] === '');
check('hero: countdown checkbox, unchecked mobile -> 0', $s['hero_countdown'] === 1 && $s['hero_image_mobile'] === 0);
check('hero: fit invalid -> cover, style kept', $s['hero_image_fit'] === 'cover' && $s['hero_image_style'] === 'circle');
check('hero: colours', $s['hero_overlay_color'] === '#1e3a8a' && $s['hero_badge_color'] === '' && $s['hero_title_color'] === '#ffffff');
check('hero: numbers clamped', $s['hero_min_height'] === 900 && $s['hero_padding'] === 120 && $s['hero_text_width'] === 1200);
check('hero: valign', $s['hero_valign'] === 'bottom');
set_opts(['google_fonts' => 'Inter', 'custom_css' => '.x{}', 'hero_button_icon' => 'flag']);
$s = WPD_Settings::sanitize(['_tab' => 'stats', 'stats' => '1|a']);
check('other tab keeps fonts, css, hero icon', $s['google_fonts'] === 'Inter' && $s['custom_css'] === '.x{}' && $s['hero_button_icon'] === 'flag');
set_opts([]);

/* 3. Переменные и классы */
check('style_vars default: still 7', count(WPD_Settings::style_vars()) === 7);
check('page_classes default: none', WPD_Settings::page_classes() === []);
set_opts(['heading_weight' => '600', 'heading_scale' => 'large', 'line_height' => 'compact', 'link_color' => '#0f766e', 'button_text_color' => '#111827', 'border_width' => 2,
    'section_title_align' => 'center', 'alt_sections' => 1]);
$v = WPD_Settings::style_vars();
check('vars: weight, scale, line height', ($v['--wpd-heading-weight'] ?? '') === '600' && ($v['--wpd-h2-scale'] ?? '') === '1.2' && ($v['--wpd-lh'] ?? '') === '1.45', json_encode($v));
check('vars: link, button text, border', ($v['--wpd-link'] ?? '') === '#0f766e' && ($v['--wpd-btn-text'] ?? '') === '#111827' && ($v['--wpd-border'] ?? '') === '2px');
check('classes: titles center, alt sections, link colour', WPD_Settings::page_classes() === ['wpd-page--titles-center', 'wpd-page--alt-sections', 'wpd-page--link-color'], implode(' ', WPD_Settings::page_classes()));
check('wrap: vars in style attr', has(WPD_Renderer::wrap('x'), '--wpd-heading-weight:600;--wpd-h2-scale:1.2;--wpd-lh:1.45;--wpd-link:#0f766e;--wpd-btn-text:#111827;--wpd-border:2px;'));
set_opts(['heading_scale' => 'small', 'line_height' => 'relaxed', 'border_width' => 0]);
$v = WPD_Settings::style_vars();
check('vars: small scale, relaxed, border 0', $v['--wpd-h2-scale'] === '0.85' && $v['--wpd-lh'] === '1.8' && $v['--wpd-border'] === '0px');
set_opts(['heading_weight' => '900', 'link_color' => 'javascript:x', 'section_title_align' => 'x', 'border_width' => 1]);
check('stored garbage: no vars, no classes', count(WPD_Settings::style_vars()) === 7 && WPD_Settings::page_classes() === []);
set_opts([]);

/* 4. Шрифты Google и свой CSS: подключение */
reregister();
check('no fonts: wpd-design without deps', wp_styles()->registered['wpd-design']->deps === [] && !isset(wp_styles()->registered['wpd-google-fonts']));
check('no css: no inline style', empty(wp_styles()->get_data('wpd-design', 'after')));
set_opts(['google_fonts' => "Montserrat:400,800\nPT Serif", 'custom_css' => '.wpd-page .wpd-h2{letter-spacing:-.02em}']);
reregister();
$fonts = wp_styles()->registered['wpd-google-fonts'] ?? null;
check('fonts: style registered', $fonts && $fonts->src === 'https://fonts.googleapis.com/css2?family=Montserrat:wght@400;800&family=PT+Serif&display=swap' && $fonts->ver === null);
check('fonts: dependency of wpd-design', wp_styles()->registered['wpd-design']->deps === ['wpd-google-fonts']);
check('css: inline after wpd.css', implode('', (array) wp_styles()->get_data('wpd-design', 'after')) === '.wpd-page .wpd-h2{letter-spacing:-.02em}');
check('editor style depends on wpd-design', in_array('wpd-design', wp_styles()->registered['wpd-editor']->deps ?? [], true));
set_opts([]);
reregister();

/* 5. Главный блок */
$btn = ['button_text' => 'Положение', 'button_url' => 'https://e.example/p', 'button2_text' => 'Архив', 'button2_url' => 'https://e.example/a'];
wp_dequeue_style('dashicons');
$h = hero($btn + ['button_icon' => 'media-document', 'button2_icon' => 'download']);
check('icons: before text, arrow kept', has($h, '<a class="wpd-btn" href="https://e.example/p"><span class="wpd-btn__icon dashicons dashicons-media-document" aria-hidden="true"></span>Положение &rarr;</a>'), $h);
check('icons: second button', has($h, '<a class="wpd-btn wpd-btn--ghost" href="https://e.example/a"><span class="wpd-btn__icon dashicons dashicons-download" aria-hidden="true"></span>Архив</a>'));
check('icons: dashicons enqueued', wp_style_is('dashicons', 'enqueued'));
check('icons: unknown icon ignored', !has(hero($btn + ['button_icon' => 'x" onmouseover="a']), 'wpd-btn__icon') && !has(hero($btn + ['button_icon' => 'x" onmouseover="a']), 'onmouseover'));
check('icons: none by default', !has(hero($btn), 'wpd-btn__icon'));

set_opts(['countdown_date' => msk('+3 days'), 'countdown_label' => 'До начала', 'countdown_enabled' => 0]);
$h = hero(['countdown' => true, 'image' => '']);
check('timer inside: after text, before end of text column', has($h, '<div class="wpd-hero__countdown"><div class="wpd-countdown" data-target=') && strpos($h, 'wpd-hero__countdown') < strpos($h, '</div></div></section>'), $h);
check('timer inside: works even if separate timer is off', has($h, 'До начала'));
check('timer inside: off by default', !has(hero(['image' => '']), 'wpd-hero__countdown'));
set_opts(['countdown_date' => '']);
check('timer inside: no date -> nothing', !has(hero(['countdown' => true]), 'wpd-hero__countdown'));

set_opts(['countdown_date' => msk('+3 days'), 'countdown_enabled' => 1, 'hero_countdown' => 1]);
$p = do_shortcode('[wpd_page]');
check('[wpd_page]: one timer, inside hero', substr_count($p, 'class="wpd-countdown"') === 1 && has($p, 'wpd-hero__countdown'));
$sc = do_shortcode('[wpd_hero]');
check('[wpd_hero]: timer inside from settings', has($sc, 'wpd-hero__countdown'));
$pid = wp_insert_post(['post_title' => 'M page', 'post_status' => 'publish', 'post_type' => 'page', 'post_content' => 'Текст']);
update_post_meta($pid, '_wpd_m_test', 1);
update_post_meta($pid, '_wpd_enabled', 1);
$pg = WPD_Renderer::page_html($pid, 'Текст');
check('page: one timer, inside hero', substr_count($pg, 'class="wpd-countdown"') === 1 && has($pg, 'wpd-hero__countdown'));
update_post_meta($pid, '_wpd_page', ['hidden' => ['hero']]);
$pg = WPD_Renderer::page_html($pid, 'Текст');
check('page: hero hidden -> separate timer shown', substr_count($pg, 'class="wpd-countdown"') === 1 && !has($pg, 'wpd-hero__countdown'), substr_count($pg, 'class="wpd-countdown"') . '');
set_opts(['countdown_date' => msk('+3 days'), 'countdown_enabled' => 1]);
delete_post_meta($pid, '_wpd_page');
check('page: setting off -> timer under hero as before', substr_count(WPD_Renderer::page_html($pid, 'Текст'), 'class="wpd-countdown"') === 1 && !has(WPD_Renderer::page_html($pid, 'Текст'), 'wpd-hero__countdown'));
set_opts([]);

$img = ['image' => 'https://example.com/i.jpg'];
check('fit contain: class for side image', has(hero($img + ['image_fit' => 'contain']), 'wpd-hero--img-right wpd-hero--fit-contain'));
check('fit: not for background', !has(hero($img + ['image_fit' => 'contain', 'image_position' => 'background']), 'fit-contain'));
check('fit: invalid -> cover (no class)', !has(hero($img + ['image_fit' => 'stretch']), 'wpd-hero--fit-'));
check('image style classes', has(hero($img + ['image_style' => 'shadow']), 'wpd-hero--imgstyle-shadow') && has(hero($img + ['image_style' => 'circle']), 'wpd-hero--imgstyle-circle')
    && has(hero($img + ['image_style' => 'square']), 'wpd-hero--imgstyle-square') && !has(hero($img + ['image_style' => 'rounded']), 'imgstyle') && !has(hero($img + ['image_style' => 'oval']), 'imgstyle'));
check('image style: not without image', !has(hero(['image' => '', 'image_style' => 'circle']), 'imgstyle'));
check('hide on mobile: false or "0"', has(hero($img + ['image_mobile' => false]), 'wpd-hero--img-hide-mobile') && has(hero($img + ['image_mobile' => '0']), 'wpd-hero--img-hide-mobile'));
check('hide on mobile: background too', has(hero($img + ['image_mobile' => 0, 'image_position' => 'background']), 'wpd-hero--img-background wpd-hero--img-hide-mobile'));
check('hide on mobile: default shown, no image -> no class', !has(hero($img), 'hide-mobile') && !has(hero(['image' => '', 'image_mobile' => false]), 'hide-mobile'));
$h = hero($img + ['image_position' => 'background', 'overlay' => 40, 'overlay_color' => '#1e3a8a']);
check('overlay colour var for background', has($h, '--wpd-hero-overlay:40%;--wpd-hero-overlay-color:#1e3a8a;'), $h);
check('overlay colour: not for side image, invalid ignored', !has(hero($img + ['overlay_color' => '#1e3a8a']), 'overlay-color') && !has(hero($img + ['image_position' => 'background', 'overlay_color' => 'red']), 'overlay-color'));
check('valign classes', has(hero(['image' => '', 'valign' => 'top']), 'wpd-hero--valign-top') && has(hero(['image' => '', 'valign' => 'bottom']), 'wpd-hero--valign-bottom')
    && !has(hero(['image' => '', 'valign' => 'center']), 'valign') && !has(hero(['image' => '', 'valign' => 'middle']), 'valign'));
$h = hero(['image' => '', 'badge_color' => '#fde68a', 'title_color' => '#facc15', 'text_color' => '#e2e8f0']);
check('colours: classes', has($h, 'wpd-hero--c-badge wpd-hero--c-title wpd-hero--c-text'), substr($h, 0, 250));
check('colours: vars, badge text auto (light bg -> dark text)', has($h, '--wpd-hero-badge:#fde68a;--wpd-hero-badge-fg:#0f172a;--wpd-hero-title:#facc15;--wpd-hero-text:#e2e8f0;'));
check('badge: dark bg -> white text', has(hero(['image' => '', 'badge_color' => '#1d4ed8']), '--wpd-hero-badge-fg:#fff;'));
check('colours: invalid ignored', !has(hero(['image' => '', 'badge_color' => 'red', 'title_color' => 'url(x)', 'text_color' => '#12']), 'wpd-hero--c-'));
$h = hero(['image' => '', 'min_height' => 640, 'padding' => 72, 'text_width' => 600]);
check('sizes: classes and vars', has($h, 'wpd-hero--minh wpd-hero--pad wpd-hero--text-w') && has($h, '--wpd-hero-minh:640px;--wpd-hero-pad:72px;--wpd-hero-text-w:600px;'), substr($h, 0, 300));
check('sizes: clamped', has(hero(['image' => '', 'min_height' => 5000, 'padding' => 999, 'text_width' => 99999]), '--wpd-hero-minh:900px;--wpd-hero-pad:120px;--wpd-hero-text-w:1200px;'));
check('sizes: 0 and negative -> none', !has(hero(['image' => '', 'min_height' => 0, 'padding' => -5, 'text_width' => '']), 'wpd-hero--minh') && !has(hero(['image' => '', 'padding' => -5]), '--wpd-hero-pad'));
check('defaults: markup as 1.8.0', has(hero(['image' => '']), '<section class="wpd-hero wpd-hero--dark"><div class="wpd-hero__inner">'));
set_opts(['hero_badge_color' => '#000000', 'hero_valign' => 'top', 'hero_min_height' => 500, 'hero_button_icon' => 'awards', 'hero_button_url' => 'https://set.example/']);
$sc = do_shortcode('[wpd_hero]');
check('shortcode: new options from settings', has($sc, 'wpd-hero--valign-top') && has($sc, 'wpd-hero--c-badge') && has($sc, '--wpd-hero-minh:500px;') && has($sc, 'dashicons-awards'));
check('partial args (page panel) -> new options from settings', has(hero(['title' => 'Со страницы']), 'wpd-hero--valign-top') && has(hero(['title' => 'Со страницы']), 'dashicons-awards'));

/* 6. Блок */
$attrs = WP_Block_Type_Registry::get_instance()->get_registered('wpd/hero')->attributes;
$new = ['buttonIcon', 'button2Icon', 'showCountdown', 'imageFit', 'imageStyle', 'imageMobile', 'overlayColor', 'badgeColor', 'titleColor', 'textColor', 'minHeight', 'padding', 'verticalAlign', 'textWidth'];
check('block: 14 new attributes', count(array_intersect($new, array_keys($attrs))) === 14);
check('block: no server defaults', count(array_filter($new, function ($k) use ($attrs) { return array_key_exists('default', $attrs[$k]); })) === 0);
check('block: types', $attrs['showCountdown']['type'] === 'boolean' && $attrs['imageMobile']['type'] === 'boolean' && $attrs['minHeight']['type'] === 'integer' && $attrs['padding']['type'] === 'integer' && $attrs['textWidth']['type'] === 'integer');
$r = WPD_Blocks::render_hero([]);
check('block: falls back to settings', has($r, 'wpd-hero--valign-top') && has($r, 'dashicons-awards') && has($r, '--wpd-hero-minh:500px;'));
$r = WPD_Blocks::render_hero(['buttonIcon' => 'flag', 'verticalAlign' => 'bottom', 'minHeight' => 300, 'padding' => 40, 'textWidth' => 700, 'badgeColor' => '#ffffff', 'titleColor' => '#111111', 'textColor' => '#222222',
    'imageUrl' => 'https://b.example/i.jpg', 'imageFit' => 'contain', 'imageStyle' => 'shadow', 'imageMobile' => false]);
check('block: attributes win', has($r, 'dashicons-flag') && has($r, 'wpd-hero--valign-bottom') && has($r, '--wpd-hero-minh:300px;--wpd-hero-pad:40px;--wpd-hero-text-w:700px;')
    && has($r, '--wpd-hero-badge:#ffffff;--wpd-hero-badge-fg:#0f172a;--wpd-hero-title:#111111;--wpd-hero-text:#222222;'), substr($r, 0, 600));
check('block: image options', has($r, 'wpd-hero--fit-contain wpd-hero--imgstyle-shadow wpd-hero--img-hide-mobile'));
check('block: empty icon attribute removes settings icon', !has(WPD_Blocks::render_hero(['buttonIcon' => '']), 'wpd-btn__icon'));
check('block: zero sizes override settings', !has(WPD_Blocks::render_hero(['minHeight' => 0]), 'wpd-hero--minh'));
set_opts(['countdown_date' => msk('+3 days'), 'hero_countdown' => 1, 'hero_image_mobile' => 0, 'hero_image_url' => 'https://set.example/i.jpg']);
check('block: showCountdown false overrides settings', !has(WPD_Blocks::render_hero(['showCountdown' => false]), 'wpd-hero__countdown') && has(WPD_Blocks::render_hero([]), 'wpd-hero__countdown'));
check('block: imageMobile true overrides settings', !has(WPD_Blocks::render_hero(['imageMobile' => true]), 'hide-mobile') && has(WPD_Blocks::render_hero([]), 'hide-mobile'));
set_opts([]);

/* 7. Данные редактора, страница настроек, справка, CSS */
$inline = implode("\n", (array) wp_scripts()->get_data('wpd-blocks', 'before'));
check('editor data: imageFit, imageStyle, verticalAlign options', has($inline, '"imageFit":{"cover"') && has($inline, '"imageStyle":{"rounded"') && has($inline, '"verticalAlign":{"top"'));
check('editor data: icons', has($inline, '"icons":{"media-document"'));
ob_start();
$_GET = ['page' => 'wpd-settings', 'tab' => 'design'];
WPD_Settings::render_page();
$design = ob_get_clean();
check('settings design: fonts and css textareas', has($design, '<textarea id="wpd_google_fonts" name="wpd_settings[google_fonts]" rows="3" class="large-text code" spellcheck="false">')
    && has($design, '<textarea id="wpd_custom_css" name="wpd_settings[custom_css]" rows="10"'));
check('settings design: section titles', has($design, 'Шрифты Google и свой CSS') && has($design, 'Типографика и вид'));
ob_start();
$_GET = ['page' => 'wpd-settings', 'tab' => 'hero'];
WPD_Settings::render_page();
$herop = ob_get_clean();
$a1 = strpos($herop, '>Текст и кнопки<'); $a2 = strpos($herop, '>Изображение<'); $a3 = strpos($herop, '>Вид главного блока<');
check('settings hero: 3 subsection headings in order', $a1 !== false && $a1 < $a2 && $a2 < $a3);
check('settings hero: icon select with «Без иконки»', has($herop, 'name="wpd_settings[hero_button_icon]"') && preg_match('/<option value=""\s+selected=.selected.>Без иконки<\/option>/', $herop) === 1);
ob_start();
$_GET = ['page' => 'wpd-settings', 'tab' => 'help'];
WPD_Settings::render_page();
$help = ob_get_clean();
$_GET = [];
check('help: 1.9.0 options', has($help, 'Шрифты Google и свой CSS') && has($help, 'таймер под кнопками') && has($help, 'чередующийся фон разделов'));
$css = file_get_contents(WPD_DIR . 'assets/css/wpd.css');
check('css: new rules', has($css, '.wpd-page--titles-center .wpd-h2') && has($css, '.wpd-page--alt-sections >') && has($css, '.wpd-hero--imgstyle-circle') && has($css, '.wpd-hero__countdown')
    && has($css, 'var(--wpd-heading-weight, 800)') && has($css, 'var(--wpd-btn-text, #fff)') && has($css, 'line-height: var(--wpd-lh, 1.6)') && has($css, 'var(--wpd-border, 1px) solid')
    && has($css, 'var(--wpd-hero-overlay-color,') && has($css, '.wpd-hero--img-hide-mobile .wpd-hero__media'));

foreach (get_posts(['post_type' => 'page', 'meta_key' => '_wpd_m_test', 'posts_per_page' => -1, 'post_status' => 'any']) as $p) {
    wp_delete_post($p->ID, true);
}
delete_option('wpd_settings');
echo implode("\n", $results), "\n\nTOTAL: ", count($results), ", FAILED: ", $fails, "\n";
