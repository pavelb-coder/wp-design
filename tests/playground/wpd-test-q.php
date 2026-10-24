<?php
// Фаза Q (2.3.0): стиль оформления «Мягкий градиент» (по макету Кванториума).
// PHP-часть: поля, палитра и стиль главного блока, переключение стиля при сохранении, классы контейнера,
// главный блок с встроенной иллюстрацией, логотип в контактах, класс и фон <body>, данные редактора, панель страницы,
// встроенные изображения, CSS и JS, справка. В конце удаляет опцию и свои записи.
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
function contacts(): string {
    return WPD_Renderer::contacts_html(WPD_Settings::get());
}
/** Сохранение вкладки так, как это делает форма: значения вкладки + _tab. */
function save_tab(string $tab, array $fields): array {
    $out = WPD_Settings::sanitize(array_merge($fields, ['_tab' => $tab]));
    set_opts($out);
    return $out;
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

$map = WPD_Settings::field_map();
$orb_url  = WPD_URL . 'assets/img/cities-orb.webp';
$logo_url = WPD_URL . 'assets/img/kvantorium-logo.webp';

/* 1. Поля, палитра, стили главного блока */
check('version 2.3.0', WPD_VERSION === '2.3.0' && has(file_get_contents(WPD_DIR . 'wp-olympiad-design.php'), 'Version:           2.3.0'));
check('field design_style: select on design tab, default classic',
    isset($map['design_style']) && $map['design_style']['type'] === 'select' && $map['design_style']['section'] === 'colors' && $map['design_style']['default'] === 'classic');
check('design_style options: classic and soft', array_keys($map['design_style']['options']) === ['classic', 'soft'] && has($map['design_style']['options']['soft'], 'Мягкий градиент'));
check('design_style is the first field of the design tab', array_keys($map)[0] === 'design_style');
check('palette soft: 5 colors of the mockup', (WPD_Settings::palettes()['soft']['colors'] ?? []) === ['#2235f8', '#5041ff', '#22232e', '#22232e', '#f5f7ff']);
check('other palettes kept', count(WPD_Settings::palettes()) === 7 && isset(WPD_Settings::palettes()['olympiad']));
check('hero styles: 4 with soft', WPD_Settings::hero_styles() === ['dark' => 'Тёмный', 'gradient' => 'Градиент основного цвета', 'light' => 'Светлый', 'soft' => 'Мягкий градиент']);
check('hero_style select uses hero_styles()', $map['hero_style']['options'] === WPD_Settings::hero_styles() && $map['hero_style']['default'] === 'dark');
check('renderer HERO_STYLES includes soft', WPD_Renderer::HERO_STYLES === ['dark', 'gradient', 'light', 'soft']);
check('field hero_soft_orb: checkbox in hero image section, on', ($map['hero_soft_orb']['type'] ?? '') === 'checkbox' && $map['hero_soft_orb']['section'] === 'hero_image' && $map['hero_soft_orb']['default'] === 1);
check('field contact_logo: 4 options, default auto', ($map['contact_logo']['type'] ?? '') === 'select' && array_keys($map['contact_logo']['options']) === ['auto', 'builtin', 'custom', 'none'] && $map['contact_logo']['default'] === 'auto');
check('field contact_logo_url: url', ($map['contact_logo_url']['type'] ?? '') === 'url' && $map['contact_logo_url']['section'] === 'contacts');
check('defaults: classic look unchanged', WPD_Settings::get()['design_style'] === 'classic' && WPD_Settings::get()['hero_style'] === 'dark' && WPD_Settings::get()['palette'] === 'custom');

/* 2. Санитизация и переключение стиля */
$s = WPD_Settings::sanitize(['design_style' => 'neon']);
check('sanitize: unknown style -> classic', $s['design_style'] === 'classic');
$s = save_tab('design', ['design_style' => 'soft', 'palette' => 'custom']);
check('switch to soft: palette soft', $s['palette'] === 'soft');
check('switch to soft: colors from palette', $s['primary'] === '#2235f8' && $s['accent'] === '#5041ff' && $s['bg'] === '#f5f7ff');
check('switch to soft: hero style soft (other tab field)', $s['hero_style'] === 'soft');
$s = save_tab('design', ['design_style' => 'soft', 'palette' => 'sea']);
check('save again without switch: chosen palette kept', $s['palette'] === 'sea' && $s['primary'] === '#0e7490');
$s = save_tab('hero', ['hero_style' => 'light']);
check('hero tab: hero style changed separately', $s['hero_style'] === 'light' && $s['design_style'] === 'soft');
$s = save_tab('design', ['design_style' => 'soft', 'palette' => 'sea']);
check('design save without switch keeps hero style', $s['hero_style'] === 'light');
$s = save_tab('stats', ['stats' => "1|один"]);
check('other tab save keeps style and palette', $s['design_style'] === 'soft' && $s['palette'] === 'sea');
$s = save_tab('design', ['design_style' => 'classic', 'palette' => 'sea']);
check('switch to classic: custom palette and hero style kept', $s['design_style'] === 'classic' && $s['palette'] === 'sea' && $s['hero_style'] === 'light');
save_tab('design', ['design_style' => 'soft', 'palette' => 'sea']);
$s = save_tab('design', ['design_style' => 'classic', 'palette' => 'soft']);
check('switch to classic: palette soft -> olympiad with its colors', $s['palette'] === 'olympiad' && $s['primary'] === '#1d4ed8' && $s['accent'] === '#f97316');
check('switch to classic: hero soft -> dark', $s['hero_style'] === 'dark');
set_opts(['design_style' => 'classic']);
$s = WPD_Settings::sanitize(['design_style' => 'soft']);
check('all fields at once (no _tab): switch works', $s['palette'] === 'soft' && $s['hero_style'] === 'soft');
set_opts(['design_style' => 'soft', 'palette' => 'soft', 'hero_style' => 'soft']);
$s = WPD_Settings::sanitize(['design_style' => 'soft', 'palette' => 'soft', 'hero_style' => 'gradient']);
check('all fields, no switch: hero style as sent', $s['hero_style'] === 'gradient');
set_opts([]);
$s = WPD_Settings::sanitize(['hero_style' => 'soft']);
check('sanitize: hero style soft accepted', $s['hero_style'] === 'soft');
set_opts(['design_style' => 'soft', 'hero_style' => 'soft']);
$s = WPD_Settings::sanitize(['hero_style' => 'soft']);
check('all fields without style (-> classic): switch back to dark', $s['design_style'] === 'classic' && $s['hero_style'] === 'dark');
set_opts([]);

/* 3. Классы контейнера */
check('classic: no style class', !preg_grep('/^wpd-style-/', WPD_Settings::page_classes(WPD_Settings::get())));
check('soft: class wpd-style-soft', in_array('wpd-style-soft', WPD_Settings::page_classes(array_merge(WPD_Settings::get(), ['design_style' => 'soft'])), true));
check('broken saved value: no class', !preg_grep('/^wpd-style-/', WPD_Settings::page_classes(array_merge(WPD_Settings::get(), ['design_style' => '"><x']))));
set_opts(['design_style' => 'soft']);
check('wrap: soft class on .wpd-page', has(WPD_Renderer::wrap('<p>x</p>'), 'class="wpd-page wpd-style-soft"'));
check('shortcode [wpd_stats]: soft class', has(do_shortcode('[wpd_stats]'), 'wpd-style-soft'));
set_opts([]);
check('wrap classic: no soft class', !has(WPD_Renderer::wrap('<p>x</p>'), 'wpd-style-soft'));

/* 4. Главный блок «Мягкий градиент» и встроенная иллюстрация */
$h = hero(['style' => 'soft']);
check('soft hero: modifier class', has($h, 'wpd-hero wpd-hero--soft'));
check('soft hero without image: orb', has($h, 'wpd-hero--orb') && has($h, 'wpd-hero__media wpd-hero__media--orb') && has($h, 'src="' . $orb_url . '"'));
check('orb: image on the right', has($h, 'wpd-hero--img-right'));
check('orb: aura and two stars, decorative', substr_count($h, 'aria-hidden="true"') === 3 && has($h, 'wpd-orb__aura') && has($h, 'wpd-orb__star--2'));
check('orb: default alt text', has($h, 'alt="Иллюстрация: Лондон и Красноярск"'));
check('orb: size attributes, not lazy', has($h, 'width="1024" height="1020"') && !has(substr($h, strpos($h, 'wpd-orb__img')), 'loading="lazy"'));
check('orb: own alt kept', has(hero(['style' => 'soft', 'image_alt' => 'Города']), 'alt="Города"'));
check('orb: no frame classes', !has(hero(['style' => 'soft', 'image_style' => 'circle', 'image_fit' => 'contain']), 'wpd-hero--imgstyle'));
check('orb: image on the left', has(hero(['style' => 'soft', 'image_position' => 'left']), 'wpd-hero--img-left'));
$hb = hero(['style' => 'soft', 'image_position' => 'background']);
check('orb: background position -> right, no bg variables', has($hb, 'wpd-hero--img-right') && !has($hb, '--wpd-hero-img') && !has($hb, 'img-background'));
check('orb: hidden on phone when asked', has(hero(['style' => 'soft', 'image_mobile' => '0']), 'wpd-hero--img-hide-mobile'));
$hi = hero(['style' => 'soft', 'image' => 'https://img.example/a.jpg']);
check('soft hero with own image: regular media, no orb', has($hi, '<div class="wpd-hero__media"><img src="https://img.example/a.jpg"') && !has($hi, 'orb'));
check('dark hero: no orb', !has(hero(['style' => 'dark']), 'orb') && !has(hero(['style' => 'dark']), 'wpd-hero__media'));
set_opts(['hero_soft_orb' => 0]);
check('orb switched off: no media', !has(hero(['style' => 'soft']), 'wpd-hero__media') && !has(hero(['style' => 'soft']), 'wpd-hero--img-'));
set_opts(['hero_style' => 'soft']);
check('hero from settings: soft with orb', has(hero(), 'wpd-hero--soft') && has(hero(), 'wpd-hero--orb'));
check('[wpd_hero] in classic design: soft hero works too', has(do_shortcode('[wpd_hero]'), 'wpd-hero--orb') && !has(do_shortcode('[wpd_hero]'), 'wpd-style-soft'));
check('block hero variant soft', has(WPD_Blocks::render_hero(['variant' => 'soft']), 'wpd-hero--soft') && has(WPD_Blocks::render_hero(['variant' => 'soft']), 'wpd-orb__img'));
check('block hero own image: no orb', !has(WPD_Blocks::render_hero(['variant' => 'soft', 'imageUrl' => 'https://img.example/b.jpg']), 'orb'));
check('unknown style -> dark', has(hero(['style' => 'neon']), 'wpd-hero--dark'));
set_opts([]);

/* 5. Логотип в контактах */
$base = ['contact_org' => 'Кванториум Красноярск', 'contact_email' => 'a@example.com'];
set_opts($base);
check('logo auto + classic: none', !has(contacts(), 'wpd-contacts__logo'));
set_opts($base + ['design_style' => 'soft']);
$c = contacts();
check('logo auto + soft: builtin', has($c, '<div class="wpd-contacts__logo"><img class="wpd-contacts__logo-img" src="' . $logo_url . '"'));
check('logo: alt = organisation', has($c, 'alt="Кванториум Красноярск"'));
check('logo: box modifier, logo first in box', has($c, 'wpd-contacts__box wpd-contacts__box--logo"><div class="wpd-contacts__logo">'));
set_opts($base + ['contact_logo' => 'builtin']);
check('logo builtin: shown in classic', has(contacts(), $logo_url));
set_opts($base + ['contact_logo' => 'builtin', 'contact_org_url' => 'https://org.example/']);
check('logo: link of organisation (new tab, external)', has(contacts(), '<a class="wpd-contacts__logo-link" href="https://org.example/" target="_blank" rel="noopener noreferrer"><img'));
set_opts($base + ['contact_logo' => 'custom', 'contact_logo_url' => 'https://cdn.example/logo.svg']);
check('logo custom: own url', has(contacts(), 'src="https://cdn.example/logo.svg"') && !has(contacts(), $logo_url));
set_opts($base + ['contact_logo' => 'custom', 'contact_logo_url' => '']);
check('logo custom without url: none', !has(contacts(), 'wpd-contacts__logo'));
set_opts($base + ['contact_logo' => 'none', 'design_style' => 'soft']);
check('logo none: hidden even in soft', !has(contacts(), 'wpd-contacts__logo'));
set_opts(['contact_logo' => 'builtin', 'contact_email' => 'a@example.com']);
check('logo without organisation: default alt', has(contacts(), 'alt="Кванториум Красноярск"'));
set_opts(['contact_logo' => 'custom', 'contact_logo_url' => 'https://cdn.example/l.png', 'contact_email' => 'a@example.com']);
check('custom logo without organisation: alt «Логотип»', has(contacts(), 'alt="Логотип"'));
set_opts(['contact_logo' => 'builtin']);
check('no contacts data: block not shown even with logo', contacts() === '');
$s = WPD_Settings::sanitize(['_tab' => 'contacts', 'contact_logo' => 'evil', 'contact_logo_url' => 'javascript:alert(1)']);
check('sanitize: bad logo option -> auto, bad url -> empty', $s['contact_logo'] === 'auto' && $s['contact_logo_url'] === '');
set_opts([]);

/* 6. <body> и фон страницы */
$designed = wp_insert_post(['post_title' => 'Q designed', 'post_status' => 'publish', 'post_type' => 'page', 'post_content' => '<p>Q</p>']);
update_post_meta($designed, '_wpd_enabled', 1);
update_post_meta($designed, '_wpd_q_test', 1);
$plain = wp_insert_post(['post_title' => 'Q plain', 'post_status' => 'publish', 'post_type' => 'page', 'post_content' => '[wpd_stats]']);
update_post_meta($plain, '_wpd_q_test', 1);
$body = function (int $id): array {
    $GLOBALS['wp_query'] = new WP_Query(['page_id' => $id]);
    $GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
    return WPD_Renderer::body_class([]);
};
set_opts(['design_style' => 'soft']);
check('body: soft class on designed page', in_array('wpd-soft-page', $body($designed), true));
check('body: no soft class on page with shortcode only', !in_array('wpd-soft-page', $body($plain), true));
set_opts(['design_style' => 'soft', 'hide_title' => 0]);
check('body: soft class without hide_title', $body($designed) === ['wpd-soft-page']);
set_opts([]);
check('body: classic -> no soft class', !in_array('wpd-soft-page', $body($designed), true));
wp_reset_query();

set_opts(['design_style' => 'soft', 'palette' => 'soft', 'bg' => '#f5f7ff']);
reregister();
$after = implode("\n", (array) wp_styles()->get_data('wpd-design', 'after'));
check('inline css: page background for soft', $after === 'body.wpd-soft-page{background-color:#f5f7ff;}');
set_opts(['design_style' => 'soft', 'bg' => '#fafafa', 'custom_css' => '.x{color:red}']);
reregister();
$after = implode("\n", (array) wp_styles()->get_data('wpd-design', 'after'));
check('inline css: background first, then own css', $after === "body.wpd-soft-page{background-color:#fafafa;}\n.x{color:red}");
set_opts(['design_style' => 'soft', 'bg' => 'red;}body{x']);
reregister();
check('inline css: invalid colour not printed', empty(wp_styles()->get_data('wpd-design', 'after')));
set_opts([]);
reregister();
check('inline css: nothing for classic', empty(wp_styles()->get_data('wpd-design', 'after')));

/* 7. Оформленная страница целиком */
set_opts(['design_style' => 'soft', 'hero_style' => 'soft', 'stats' => "1|один", 'contact_email' => 'a@example.com']);
update_post_meta($designed, '_wpd_page', ['order' => ['hero', 'stats', 'contacts'], 'hidden' => []]);
$page = WPD_Renderer::page_html($designed, '<p>Q</p>');
check('page: soft container, soft hero with orb, logo', has($page, 'class="wpd-page wpd-style-soft"') && has($page, 'wpd-hero--orb') && has($page, $logo_url));
set_post_thumbnail($designed, 0);
$att = wp_insert_attachment(['post_title' => 'thumbq', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit', 'guid' => 'https://example.com/q.jpg'], false, $designed);
update_post_meta($att, '_wp_attached_file', '2026/10/q.jpg');
update_post_meta($designed, '_thumbnail_id', $att);
$page = WPD_Renderer::page_html($designed, '<p>Q</p>');
check('page with featured image: own image instead of orb', !has($page, 'wpd-hero--orb') && has($page, 'q.jpg'));
wp_delete_attachment($att, true);
delete_post_meta($designed, '_thumbnail_id');
update_post_meta($designed, '_wpd_page', ['order' => ['hero'], 'hidden' => [], 'hero' => ['style' => 'dark']]);
check('page panel style overrides: dark, no orb', !has(WPD_Renderer::page_html($designed, ''), 'orb'));
set_opts([]);

/* 8. Панель страницы и данные редактора */
$norm = WPD_Page_Editor::normalize(['hero' => ['style' => 'soft']]);
check('panel: hero style soft accepted', $norm['hero']['style'] === 'soft');
ob_start();
WPD_Page_Editor::meta_box_html(get_post($designed));
$mb = ob_get_clean();
check('panel: soft option in style select', (bool) preg_match('/<option value="soft"[^>]*>Мягкий градиент<\/option>/u', $mb));
$inline = implode("\n", (array) wp_scripts()->get_data('wpd-blocks', 'before'));
check('editor data: heroStyles with soft', has($inline, '"heroStyles":' . wp_json_encode(WPD_Settings::hero_styles())) && has($inline, '"soft":' . wp_json_encode('Мягкий градиент')));
check('editor data: softOrb url', has($inline, '"softOrb":"' . str_replace('/', '\/', $orb_url) . '"') || has($inline, '"softOrb":"' . $orb_url . '"'));
$js = file_get_contents(WPD_DIR . 'assets/js/wpd-blocks.js');
check('editor js: orb preview', has($js, "variant === 'soft' && !!data.softOrb") && has($js, 'wpd-hero__media wpd-hero__media--orb') && has($js, 'wpd-ed-orb-replace'));
check('editor css: orb replace button', has(file_get_contents(WPD_DIR . 'assets/css/wpd-editor.css'), '.wpd-page .wpd-ed-orb-replace'));

/* 9. Встроенные изображения */
foreach (['cities-orb.webp' => [80000, 400000], 'kvantorium-logo.webp' => [5000, 120000]] as $file => $range) {
    $path = WPD_DIR . 'assets/img/' . $file;
    $data = is_file($path) ? file_get_contents($path) : '';
    check("image $file: webp", substr($data, 0, 4) === 'RIFF' && substr($data, 8, 4) === 'WEBP');
    check("image $file: size", strlen($data) >= $range[0] && strlen($data) <= $range[1], (string) strlen($data));
}

/* 10. CSS, JS, настройки, справка */
$css = file_get_contents(WPD_DIR . 'assets/css/wpd.css');
check('css: soft hero rules', has($css, '.wpd-page .wpd-hero--soft {') && has($css, '.wpd-page .wpd-hero--soft::before') && has($css, 'text-transform: uppercase') && has($css, '@keyframes wpd-orb-float'));
check('css: soft style rules for all components', has($css, '.wpd-page.wpd-style-soft .wpd-stat {') && has($css, '.wpd-page.wpd-style-soft .wpd-doc {') && has($css, '.wpd-page.wpd-style-soft .wpd-faq__item {')
    && has($css, '.wpd-page.wpd-style-soft .wpd-qbtn {') && has($css, '.wpd-page.wpd-style-soft .wpd-card {') && has($css, '.wpd-page.wpd-style-soft .wpd-contacts__box {')
    && has($css, '.wpd-page.wpd-style-soft .wpd-countdown {') && has($css, '.wpd-page.wpd-style-soft .wpd-notice {') && has($css, '.wpd-page.wpd-style-soft .wpd-news__item {'));
check('css: FAQ in two columns on wide screens', (bool) preg_match('/@media \(min-width: 821px\) \{\s*\.wpd-page\.wpd-style-soft \.wpd-faq \{\s*display: grid;/', $css));
check('css: colours from palette variables', has($css, "color: var(--wpd-primary);\n}\n\n.wpd-page.wpd-style-soft .wpd-stat__label"));
check('css: reduced motion and print for soft', (bool) preg_match('/@media \(prefers-reduced-motion: reduce\) \{\s*\.wpd-page \.wpd-hero--soft::before,/', $css) && has($css, '.wpd-page.wpd-style-soft.wpd-reveal-ready > .wpd-reveal.is-visible'));
check('css: hidden rule still wins over soft display', substr_count($css, '[hidden]') >= 3);
$fjs = file_get_contents(WPD_DIR . 'assets/js/wpd.js');
check('front js: reveal only for soft pages, not in editor, not hero', has($fjs, ".wpd-page.wpd-style-soft:not(.wpd-page--editor)") && has($fjs, "classList.contains('wpd-hero')") && has($fjs, 'if (reduceMotion || !(\'IntersectionObserver\' in window))'));
check('settings js: palette follows style', has(file_get_contents(WPD_DIR . 'assets/js/wpd-settings.js'), "#wpd_design_style"));
ob_start();
$_GET = ['page' => 'wpd-settings', 'tab' => 'design'];
WPD_Settings::render_page();
$design = ob_get_clean();
check('design tab: style select first, soft option', (bool) preg_match('/<select[^>]*id="wpd_design_style"[^>]*name="wpd_settings\[design_style\]"/', $design) && strpos($design, 'wpd_settings[design_style]') < strpos($design, 'wpd_settings[palette]'));
check('design tab: palette «Мягкий градиент»', has($design, 'value="soft"') && has($design, 'Мягкий градиент'));
ob_start();
$_GET = ['page' => 'wpd-settings', 'tab' => 'contacts'];
WPD_Settings::render_page();
$ct = ob_get_clean();
check('contacts tab: logo fields', has($ct, 'name="wpd_settings[contact_logo]"') && has($ct, 'name="wpd_settings[contact_logo_url]"'));
ob_start();
$_GET = ['page' => 'wpd-settings', 'tab' => 'help'];
WPD_Settings::render_page();
$help = ob_get_clean();
$_GET = [];
check('help: soft style described', has($help, 'Стиль оформления</strong> (2.3.0)') && has($help, 'Unbounded:700,900'));

foreach (get_posts(['post_type' => 'page', 'meta_key' => '_wpd_q_test', 'posts_per_page' => -1, 'post_status' => 'any']) as $p) {
    wp_delete_post($p->ID, true);
}
delete_option('wpd_settings');
echo implode("\n", $results), "\n\nTOTAL: ", count($results), ", FAILED: ", $fails, "\n";
