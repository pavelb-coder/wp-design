<?php
// Фаза L (1.8.0): параметры главного блока и вкладка «Оформление» → «Типографика и вид».
// PHP-часть: поля, санитизация, CSS-переменные и классы, вывод главного блока, шорткод, блок, данные редактора, справка. В конце удаляет опцию.
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
function hero(array $args = []): string {
    return WPD_Renderer::hero_html(WPD_Settings::get(), $args);
}

wp_set_current_user(1);
WPD_Settings::register();
delete_option('wpd_settings');
WPD_Settings::flush_cache();

$look = ['font_heading', 'font_size', 'heading_color', 'content_width', 'block_gap', 'button_shape', 'button_style', 'card_shadow', 'heading_underline', 'card_hover'];
$hero_new = ['hero_button_new_tab', 'hero_button2_text', 'hero_button2_url', 'hero_button2_new_tab', 'hero_image_alt', 'hero_image_position', 'hero_overlay',
    'hero_bg', 'hero_height', 'hero_align', 'hero_title_size'];

/* 1. Поля и вкладки */
check('version >= 1.8.0', version_compare(WPD_VERSION, '1.8.0', '>='));
$map  = WPD_Settings::field_map();
$keys = array_keys($map);
$tabs = WPD_Settings::tabs();
check('design tab: colors, look, behavior (+ code 1.9.0)', array_slice($tabs['design']['sections'], 0, 3) === ['colors', 'look', 'behavior']);
// порядок полей как подпоследовательность (в 1.9.0 между ними добавлены новые поля)
function in_order(array $keys, array $want): bool {
    $prev = -1;
    foreach ($want as $k) {
        $i = array_search($k, $keys, true);
        if ($i === false || $i <= $prev) { return false; }
        $prev = $i;
    }
    return true;
}
$hero_tab = $tabs['hero']['sections'];
check('section look titled', (WPD_Settings::sections()['look'] ?? '') === 'Типографика и вид');
check('10 look fields in section look', count(array_filter($look, function ($k) use ($map) { return ($map[$k]['section'] ?? '') === 'look'; })) === 10);
check('look fields follow font, in order', in_order($keys, array_merge(['font'], $look)) && array_search('font_heading', $keys, true) === array_search('font', $keys, true) + 1);
check('11 new hero fields on hero tab', count(array_filter($hero_new, function ($k) use ($map, $hero_tab) { return in_array($map[$k]['section'] ?? '', $hero_tab, true); })) === 11);
check('hero order: buttons, image, style', in_order($keys, ['hero_button_url', 'hero_button_new_tab', 'hero_button2_text', 'hero_button2_url',
    'hero_button2_new_tab', 'hero_image_url', 'hero_image_alt', 'hero_image_position', 'hero_overlay', 'hero_style', 'hero_bg', 'hero_height', 'hero_align', 'hero_title_size']));
check('232 fields total (2.7.0)', count($map) === 232);
$d = WPD_Settings::get();
check('defaults keep the old look', $d['font_heading'] === '' && $d['font_size'] === 16 && $d['heading_color'] === '' && $d['content_width'] === 1200 && $d['block_gap'] === 40
    && $d['button_shape'] === 'rounded' && $d['button_style'] === 'fill' && $d['card_shadow'] === 'light' && $d['heading_underline'] === 1 && $d['card_hover'] === 1);
check('hero defaults keep the old look', $d['hero_button_new_tab'] === 0 && $d['hero_button2_text'] === '' && $d['hero_image_position'] === 'right' && $d['hero_overlay'] === 55
    && $d['hero_bg'] === '' && $d['hero_height'] === 'normal' && $d['hero_align'] === 'left' && $d['hero_title_size'] === 'normal');

/* 2. Санитизация */
$s = WPD_Settings::sanitize(['_tab' => 'design', 'font_heading' => '', 'font_size' => '30', 'content_width' => '100', 'block_gap' => '500',
    'button_shape' => 'circle', 'button_style' => 'outline', 'card_shadow' => 'mega', 'heading_color' => 'red', 'primary' => '#112233']);
check('font_heading may be empty', $s['font_heading'] === '');
check('numbers clamped (20, 720, 96)', $s['font_size'] === 20 && $s['content_width'] === 720 && $s['block_gap'] === 96, $s['font_size'] . ',' . $s['content_width'] . ',' . $s['block_gap']);
check('selects: unknown -> default, known kept', $s['button_shape'] === 'rounded' && $s['card_shadow'] === 'light' && $s['button_style'] === 'outline');
check('heading_color invalid -> empty', $s['heading_color'] === '');
check('unchecked checkboxes on design tab -> 0', $s['heading_underline'] === 0 && $s['card_hover'] === 0);
$s = WPD_Settings::sanitize(['_tab' => 'design', 'font_heading' => 'Georgia, <script>serif', 'font_size' => '10', 'heading_color' => '#0f766e']);
check('font_heading cleaned', $s['font_heading'] === 'Georgia, scriptserif', $s['font_heading']);
check('font_size min 14', $s['font_size'] === 14);
check('heading_color valid kept', $s['heading_color'] === '#0f766e');
$s = WPD_Settings::sanitize(['_tab' => 'hero', 'hero_button2_url' => 'javascript:alert(1)', 'hero_overlay' => '150', 'hero_bg' => 'blue',
    'hero_image_position' => 'top', 'hero_height' => 'tall', 'hero_align' => 'center', 'hero_title_size' => 'huge', 'hero_button2_new_tab' => '1', 'hero_image_alt' => '<b>Фото</b> зала']);
check('hero: javascript: url dropped', $s['hero_button2_url'] === '');
check('hero: overlay clamped to 90', $s['hero_overlay'] === 90);
check('hero: bg invalid -> empty', $s['hero_bg'] === '');
check('hero: selects validated', $s['hero_image_position'] === 'right' && $s['hero_height'] === 'tall' && $s['hero_align'] === 'center' && $s['hero_title_size'] === 'normal');
check('hero: checkboxes', $s['hero_button2_new_tab'] === 1 && $s['hero_button_new_tab'] === 0);
check('hero: alt tags stripped', $s['hero_image_alt'] === 'Фото зала', $s['hero_image_alt']);
set_opts(['button_shape' => 'pill', 'hero_bg' => '#ffffff']);
$s = WPD_Settings::sanitize(['_tab' => 'stats', 'stats' => '1|a']);
check('other tab save keeps look and hero', $s['button_shape'] === 'pill' && $s['hero_bg'] === '#ffffff');
set_opts([]);

/* 3. CSS-переменные и классы */
check('style_vars default: same 7 variables as before', array_keys(WPD_Settings::style_vars()) === ['--wpd-primary', '--wpd-accent', '--wpd-dark', '--wpd-text', '--wpd-bg', '--wpd-radius', '--wpd-font']);
check('page_classes default: none', WPD_Settings::page_classes() === []);
$w = WPD_Renderer::wrap('<p>x</p>');
check('wrap default unchanged', has($w, '<div class="wpd-page" style="--wpd-primary:#1d4ed8;--wpd-accent:#f97316;--wpd-dark:#0b1220;--wpd-text:#1f2937;--wpd-bg:#f1f5f9;--wpd-radius:16px;--wpd-font:'), substr($w, 0, 200));
set_opts(['font_heading' => 'Georgia, serif', 'font_size' => 18, 'heading_color' => '#0f766e', 'content_width' => 1400, 'block_gap' => 64,
    'button_shape' => 'pill', 'button_style' => 'outline', 'card_shadow' => 'none', 'heading_underline' => 0, 'card_hover' => 0]);
$v = WPD_Settings::style_vars();
check('vars: heading font, size, heading color, width, gap', $v['--wpd-font-heading'] === 'Georgia, serif' && $v['--wpd-size'] === '18px' && $v['--wpd-heading'] === '#0f766e'
    && $v['--wpd-width'] === '1400px' && $v['--wpd-gap'] === '64px');
check('classes: pill, outline, no shadow, no underline, no hover', WPD_Settings::page_classes() === ['wpd-page--btn-pill', 'wpd-page--btn-outline', 'wpd-page--shadow-none', 'wpd-page--no-underline', 'wpd-page--no-hover'],
    implode(' ', WPD_Settings::page_classes()));
$w = WPD_Renderer::wrap('<p>x</p>', 'wpd-page--block');
check('wrap: classes then extra class', has($w, 'class="wpd-page wpd-page--btn-pill wpd-page--btn-outline wpd-page--shadow-none wpd-page--no-underline wpd-page--no-hover wpd-page--block"'), substr($w, 0, 200));
check('wrap: vars in style', has($w, '--wpd-font-heading:Georgia, serif;--wpd-size:18px;--wpd-heading:#0f766e;--wpd-width:1400px;--wpd-gap:64px;'));
set_opts(['button_shape' => 'square', 'card_shadow' => 'strong']);
check('classes: square, strong shadow', WPD_Settings::page_classes() === ['wpd-page--btn-square', 'wpd-page--shadow-strong']);
set_opts(['button_shape' => 'bogus', 'card_shadow' => 'bogus']);
check('classes: stored garbage ignored', WPD_Settings::page_classes() === []);
set_opts(['font_heading' => 'Georgia', 'block_gap' => 64]);
check('shortcode uses vars and classes', has(do_shortcode('[wpd_hero]'), '--wpd-font-heading:Georgia;') && has(do_shortcode('[wpd_hero]'), '--wpd-gap:64px;'));
set_opts([]);

/* 4. Главный блок: вывод */
$h = hero(['image' => '']);
check('hero default: classes unchanged', has($h, '<section class="wpd-hero wpd-hero--dark"><div class="wpd-hero__inner">'), substr($h, 0, 120));
check('hero default: one button in actions, arrow kept', !has($h, 'wpd-btn--ghost'));
$h = hero(['button_text' => 'Положение', 'button_url' => 'https://example.com/p', 'button2_text' => 'Архив', 'button2_url' => 'https://example.com/a']);
check('two buttons in actions', has($h, '<div class="wpd-hero__actions"><a class="wpd-btn" href="https://example.com/p">Положение &rarr;</a><a class="wpd-btn wpd-btn--ghost" href="https://example.com/a">Архив</a></div>'), $h);
$h = hero(['button_text' => 'A', 'button_url' => 'https://e.example/1', 'button_new_tab' => true, 'button2_text' => 'B', 'button2_url' => 'https://e.example/2', 'button2_new_tab' => '1']);
check('new tab for both buttons', substr_count($h, 'target="_blank" rel="noopener noreferrer"') === 2);
check('second button without url not shown', !has(hero(['button2_text' => 'Без ссылки', 'button2_url' => '']), 'Без ссылки'));
check('second button without text not shown', !has(hero(['button2_text' => '', 'button2_url' => 'https://e.example/x']), 'wpd-btn--ghost'));
check('no buttons -> no actions wrapper', !has(hero(['button_text' => '', 'button2_text' => '']), 'wpd-hero__actions'));
$h = hero(['image' => 'https://example.com/i.jpg', 'image_alt' => 'Зал "А"']);
check('image right by default with alt', has($h, 'wpd-hero--img-right') && has($h, '<img src="https://example.com/i.jpg" alt="Зал &quot;А&quot;" loading="lazy">'), $h);
check('image left class', has(hero(['image' => 'https://example.com/i.jpg', 'image_position' => 'left']), 'wpd-hero wpd-hero--dark wpd-hero--img-left'));
$h = hero(['image' => 'https://example.com/i.jpg', 'image_position' => 'background', 'overlay' => 70]);
check('background: no <img>, css var with overlay', !has($h, '<img') && has($h, 'style="--wpd-hero-img:url(&quot;https://example.com/i.jpg&quot;);--wpd-hero-overlay:70%;"'), $h);
$h = hero(['image' => "https://example.com/a'b(c)d.jpg", 'image_position' => 'background', 'overlay' => 150]);
check('background: quotes and brackets encoded', has($h, 'url(&quot;https://example.com/a%27b%28c%29d.jpg&quot;)'), $h);
check('background: overlay clamped', has($h, '--wpd-hero-overlay:90%;'));
check('position without image -> no image class', !has(hero(['image' => '', 'image_position' => 'background']), 'wpd-hero--img-'));
$h = hero(['bg' => '#1d4ed8', 'image' => '']);
check('custom dark bg -> light text', has($h, 'wpd-hero--custom-bg wpd-hero--fg-light') && has($h, '--wpd-hero-bg:#1d4ed8;'));
check('custom light bg -> dark text', has(hero(['bg' => '#fde68a', 'image' => '']), 'wpd-hero--custom-bg wpd-hero--fg-dark'));
check('custom bg invalid ignored', !has(hero(['bg' => 'url(x)', 'image' => '']), 'custom-bg') && !has(hero(['bg' => 'url(x)', 'image' => '']), 'url(x)'));
$h = hero(['height' => 'tall', 'align' => 'center', 'title_size' => 'large', 'image' => '']);
check('height, align, title classes', has($h, 'class="wpd-hero wpd-hero--dark wpd-hero--height-tall wpd-hero--align-center wpd-hero--title-large"'), substr($h, 0, 160));
check('compact and small', has(hero(['height' => 'compact', 'title_size' => 'small', 'image' => '']), 'wpd-hero--height-compact wpd-hero--title-small'));
check('invalid values -> defaults (no classes)', has(hero(['height' => 'x', 'align' => 'y', 'title_size' => 'z', 'image_position' => 'q', 'style' => 'w', 'image' => '']), '<section class="wpd-hero wpd-hero--dark">'));
check('is_light_color', WPD_Renderer::is_light_color('#ffffff') && !WPD_Renderer::is_light_color('#000000') && WPD_Renderer::is_light_color('#fff')
    && !WPD_Renderer::is_light_color('#1d4ed8') && WPD_Renderer::is_light_color('#f97316') && !WPD_Renderer::is_light_color('zzz'));
check('escaping of button text', !has(hero(['button2_text' => '<script>x</script>', 'button2_url' => 'https://e.example/']), '<script>'));

/* 5. Настройки → шорткод, частичные аргументы (панель страницы) */
set_opts(['hero_button2_text' => 'Из настроек', 'hero_button2_url' => 'https://set.example/', 'hero_height' => 'compact', 'hero_image_url' => 'https://set.example/i.jpg',
    'hero_image_position' => 'left', 'hero_image_alt' => 'Описание из настроек']);
$sc = do_shortcode('[wpd_hero]');
check('shortcode: second button and options from settings', has($sc, 'href="https://set.example/">Из настроек</a>') && has($sc, 'wpd-hero--img-left') && has($sc, 'wpd-hero--height-compact') && has($sc, 'alt="Описание из настроек"'), substr($sc, 0, 400));
$h = hero(['title' => 'Только заголовок со страницы']);
check('partial args (page panel) -> rest from settings', has($h, 'Только заголовок со страницы') && has($h, 'Из настроек') && has($h, 'wpd-hero--height-compact'));

/* 6. Блок */
$attrs = WP_Block_Type_Registry::get_instance()->get_registered('wpd/hero')->attributes;
$new = ['buttonNewTab', 'button2Text', 'button2Url', 'button2NewTab', 'imageAlt', 'imagePosition', 'overlay', 'bgColor', 'height', 'textAlign', 'titleSize'];
check('block: 11 new attributes registered', count(array_intersect($new, array_keys($attrs))) === 11);
check('block: no server defaults', count(array_filter($new, function ($k) use ($attrs) { return array_key_exists('default', $attrs[$k]); })) === 0);
check('block: types', $attrs['buttonNewTab']['type'] === 'boolean' && $attrs['overlay']['type'] === 'integer' && $attrs['textAlign']['type'] === 'string');
$r = WPD_Blocks::render_hero([]);
check('block: falls back to settings', has($r, 'Из настроек') && has($r, 'wpd-hero--img-left'));
$r = WPD_Blocks::render_hero(['button2Text' => 'Из блока', 'button2Url' => 'https://block.example/', 'button2NewTab' => true, 'imagePosition' => 'background', 'overlay' => 30,
    'bgColor' => '#fef3c7', 'height' => 'tall', 'textAlign' => 'center', 'titleSize' => 'small', 'imageAlt' => 'не нужен для фона']);
check('block: attributes win', has($r, 'href="https://block.example/" target="_blank" rel="noopener noreferrer">Из блока</a>') && has($r, 'wpd-hero--img-background') && has($r, '--wpd-hero-overlay:30%')
    && has($r, 'wpd-hero--fg-dark') && has($r, 'wpd-hero--height-tall') && has($r, 'wpd-hero--align-center') && has($r, 'wpd-hero--title-small'), substr($r, 0, 500));
check('block: align attribute does not clash with textAlign', has(WPD_Blocks::render_hero(['align' => 'wide', 'textAlign' => 'center']), 'wpd-page--hero alignwide') && has(WPD_Blocks::render_hero(['align' => 'wide', 'textAlign' => 'center']), 'wpd-hero--align-center'));
set_opts(['hero_button_new_tab' => 1, 'hero_button_url' => 'https://set.example/p']);
check('block: false boolean attribute overrides settings', !has(WPD_Blocks::render_hero(['buttonNewTab' => false]), 'target="_blank"') && has(WPD_Blocks::render_hero([]), 'target="_blank"'));
check('block: empty button2Text attribute hides button from settings', !has(WPD_Blocks::render_hero(['button2Text' => '']), 'wpd-btn--ghost'));
set_opts(['button_shape' => 'pill']);
check('block: page classes in wrapper', has(WPD_Blocks::render_hero([]), 'class="wpd-page wpd-page--btn-pill wpd-page--block wpd-page--hero"'));
set_opts([]);

/* 7. Данные редактора и справка */
$inline = implode("\n", (array) wp_scripts()->get_data('wpd-blocks', 'before'));
check('editor data: pageClass, heroOptions, palette', has($inline, '"pageClass":') && has($inline, '"heroOptions":') && has($inline, '"imagePosition":') && has($inline, '"palette":["#'));
check('editor data: vars still passed', has($inline, '"--wpd-primary":"#'));
ob_start();
$_GET = ['page' => 'wpd-settings', 'tab' => 'help'];
WPD_Settings::render_page();
$help = ob_get_clean();
$_GET = [];
check('help: describes look and hero options', has($help, 'Типографика и вид') && has($help, 'фоном с затемнением'));
$css = file_get_contents(WPD_DIR . 'assets/css/wpd.css');
check('css: new rules present', has($css, '.wpd-page--btn-pill .wpd-btn') && has($css, '.wpd-hero--img-background') && has($css, 'var(--wpd-width, 1200px)') && has($css, 'var(--wpd-gap, 40px)') && has($css, '.wpd-page--shadow-none'));

delete_option('wpd_settings');
echo implode("\n", $results), "\n\nTOTAL: ", count($results), ", FAILED: ", $fails, "\n";
