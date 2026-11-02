<?php
// Фаза AB (2.14.0): картинка вместо текста в главном блоке и ширина текста во всех блоках. Поля раздела «Картинка вместо текста»,
// выравнивание «По ширине», «Максимальная ширина текста, px»; санитизация; разметка по умолчанию не меняется; режим картинки
// (невидимый заголовок, <picture>, ссылка, отступы, кнопки и таймер под картинкой); классы wpd-tw и --wpd-tw; атрибуты блоков,
// данные редактора; панель страницы; CSS, JS, справка, README. В конце удаляет свои записи и возвращает опцию.
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
    if ($saved !== null) { $wp_filter['sanitize_option_wpd_settings'] = $saved; }
    WPD_Settings::flush_cache();
}
function blk(string $name, array $attrs = []): string {
    return render_block(['blockName' => 'wpd/' . $name, 'attrs' => $attrs, 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []]);
}
/** Класс и style первого элемента с классом $base. */
function root(string $html, string $base): array {
    if (!preg_match('/<(?:section|div|nav|aside)[^>]* class="(' . preg_quote($base, '/') . '(?: [^"]*)?)"(?: style="([^"]*)")?/', $html, $m)) {
        return ['', ''];
    }
    return [$m[1], html_entity_decode($m[2] ?? '')];
}
function cls_has(string $cls, string $c): bool { return in_array($c, explode(' ', $cls), true); }

$backup = get_option('wpd_settings');
wp_set_current_user(1);
$D   = WPD_Settings::defaults();
$map = WPD_Settings::field_map();
$dir = WPD_DIR;
$created = [];
$R = 'WPD_Renderer';
$IMG = 'https://example.com/banner.jpg';
$MOB = 'https://example.com/banner-mobile.jpg';

/* ---------- 1. Поля ---------- */
$banner_keys = ['hero_banner', 'hero_banner_url', 'hero_banner_mobile', 'hero_banner_alt', 'hero_banner_link', 'hero_banner_new_tab', 'hero_banner_edge',
    'hero_banner_buttons', 'hero_banner_timer'];
$ok = true;
foreach ($banner_keys as $k) { $ok = $ok && isset($map[$k]) && $map[$k]['section'] === 'hero_banner'; }
check('banner: 9 fields in section hero_banner', $ok && count(array_filter($map, function ($f) { return ($f['section'] ?? '') === 'hero_banner'; })) === 9);
check('banner: types', $map['hero_banner']['type'] === 'checkbox' && $map['hero_banner_url']['type'] === 'image' && $map['hero_banner_mobile']['type'] === 'image'
    && $map['hero_banner_alt']['type'] === 'text' && $map['hero_banner_link']['type'] === 'url' && $map['hero_banner_new_tab']['type'] === 'checkbox'
    && $map['hero_banner_edge']['type'] === 'select' && $map['hero_banner_buttons']['type'] === 'checkbox' && $map['hero_banner_timer']['type'] === 'checkbox');
check('banner: defaults off/empty/edge', (int) $D['hero_banner'] === 0 && $D['hero_banner_url'] === '' && $D['hero_banner_mobile'] === '' && $D['hero_banner_alt'] === ''
    && $D['hero_banner_link'] === '' && (int) $D['hero_banner_new_tab'] === 0 && $D['hero_banner_edge'] === 'edge' && (int) $D['hero_banner_buttons'] === 0
    && (int) $D['hero_banner_timer'] === 0);
check('banner: edge options', $map['hero_banner_edge']['options'] === ['edge' => 'От края до края блока', 'padded' => 'С внутренними отступами блока']);
check('banner: labels', $map['hero_banner']['label'] === 'Картинка вместо текста' && $map['hero_banner_mobile']['label'] === 'Картинка для телефона'
    && $map['hero_banner_buttons']['label'] === 'Кнопки под картинкой' && $map['hero_banner_timer']['label'] === 'Таймер под картинкой');
check('banner: timer desc names hero countdown flag', has($map['hero_banner_timer']['desc'], '«Таймер внутри главного блока»') && $map['hero_countdown']['label'] === 'Таймер внутри главного блока');
$sections = (new ReflectionMethod('WPD_Settings', 'sections'));
$sections->setAccessible(true);
$secs = $sections->invoke(null);
check('section title', ($secs['hero_banner'] ?? '') === 'Картинка вместо текста', wp_json_encode(array_keys($secs)));
$tabs = (new ReflectionMethod('WPD_Settings', 'tabs'));
$tabs->setAccessible(true);
$tabs_v = $tabs->invoke(null);
check('hero tab: section after hero_image', ($tabs_v['hero']['sections'] ?? []) === ['hero', 'hero_image', 'hero_banner', 'hero_look', 'hero_logo']);
$tw = array_filter($map, function ($f, $k) { return (bool) preg_match('/_text_w$/', $k); }, ARRAY_FILTER_USE_BOTH);
check('text_w: 11 fields', count($tw) === 11, implode(',', array_keys($tw)));
check('text_w: every block except hero', !isset($map['hero_text_w']) && isset($map['stats_text_w'], $map['docs_text_w'], $map['faq_text_w'], $map['slider_text_w'], $map['buttons_text_w'],
    $map['cards_text_w'], $map['notice_text_w'], $map['news_text_w'], $map['countdown_text_w'], $map['contacts_text_w'], $map['partners_text_w']));
$ok = true;
foreach ($tw as $k => $f) {
    $slug = substr($k, 0, -7);
    $ok = $ok && $f['type'] === 'number' && $f['min'] === 0 && $f['max'] === 1600 && $D[$k] === 0 && $f['section'] === WPD_Settings::box_section($slug)
        && $f['label'] === 'Максимальная ширина текста, px';
}
check('text_w: number 0..1600 default 0 in box section', $ok);
check('text_w: last in BOX_BLOCKS', WPD_Settings::BOX_BLOCKS['stats'] === ['min_h', 'valign', 'show_icons', 'text_w'] && WPD_Settings::BOX_BLOCKS['slider'] === ['show_icons', 'text_w']
    && WPD_Settings::BOX_BLOCKS['hero'] === ['show_icons'] && WPD_Settings::BOX_BLOCKS['partners'] === ['min_h', 'valign', 'text_w']);
check('text_w: bounds constants', WPD_Settings::BOX_MIN_TW === 200 && WPD_Settings::BOX_MAX_TW === 1600);
check('text_w: desc per block', has($map['stats_text_w']['desc'], '«По левому краю» — слева') && has($map['slider_text_w']['desc'], 'слайдов')
    && has($map['notice_text_w']['desc'], 'объявления') && !has($map['partners_text_w']['desc'], '«По левому краю»') && has($map['partners_text_w']['desc'], 'по центру блока'));
check('field_map total 363', count($map) === 363, (string) count($map));
check('plain fields 354', count(array_filter($map, function ($f) { return $f['type'] !== 'repeater'; })) === 354);
$ok = true;
foreach (array_keys(WPD_Settings::LOOK_BLOCKS) as $slug) {
    $ok = $ok && ($map[$slug . '_align']['options']['justify'] ?? '') === 'По ширине' && array_key_last($map[$slug . '_align']['options']) === 'justify';
}
check('look align: justify in all 9 look blocks', $ok);
check('hero_align and buttons_align: justify', ($map['hero_align']['options']['justify'] ?? '') === 'По ширине' && ($map['buttons_align']['options']['justify'] ?? '') === 'По ширине'
    && $D['hero_align'] === 'left');

/* ---------- 2. Санитизация ---------- */
$sv = function ($k, $v) { return WPD_Settings::sanitize_value($k, $v); };
check('sanitize text_w', $sv('stats_text_w', '640') === 640 && $sv('stats_text_w', '9000') === 1600 && $sv('stats_text_w', '-5') === 0 && $sv('stats_text_w', 'x') === 0,
    wp_json_encode([$sv('stats_text_w', '9000'), $sv('stats_text_w', '-5')]));
check('sanitize edge', $sv('hero_banner_edge', 'padded') === 'padded' && $sv('hero_banner_edge', 'evil') !== 'evil');
check('sanitize banner urls', $sv('hero_banner_url', $IMG) === $IMG && $sv('hero_banner_url', 'javascript:alert(1)') === '' && $sv('hero_banner_link', 'javascript:alert(1)') === '');
check('sanitize alt strips tags', $sv('hero_banner_alt', '<b>Олимпиада</b> 2026') === 'Олимпиада 2026');
check('sanitize align justify', $sv('faq_align', 'justify') === 'justify' && $sv('hero_align', 'justify') === 'justify' && $sv('buttons_align', 'justify') === 'justify');

/* ---------- 3. По умолчанию разметка не меняется ---------- */
set_opts($D);
$o = WPD_Settings::get();
$hero0 = $R::hero_html($o);
check('defaults: hero without banner markup', !has($hero0, 'wpd-hero--banner') && !has($hero0, 'wpd-sr') && !has($hero0, 'wpd-hero__below') && has($hero0, '<h1 class="wpd-hero__title">'));
check('banner on without picture: hero exactly as before', $R::hero_html(array_merge($o, ['hero_banner' => 1])) === $hero0);
$heroB = $R::hero_html(array_merge($o, ['hero_banner_url' => $IMG]));
check('picture without flag: hero exactly as before', $heroB === $hero0);
$st_items = [['num' => '10', 'label' => 'школ', 'icon' => 'welcome-learn-more']];
check('defaults: stats root unchanged', root($R::stats_html($o, $st_items), 'wpd-stats')[0] === 'wpd-stats');
check('defaults: box_look empty', $R::box_look($o, 'stats') === ['class' => [], 'vars' => []] && $R::box_look($o, 'partners') === ['class' => [], 'vars' => []]);

/* ---------- 4. Картинка вместо текста ---------- */
$ho = array_merge($o, ['hero_title' => 'Олимпиада «Тест» <2026>', 'hero_text' => 'Подзаголовок', 'badge' => 'Метка', 'hero_button_text' => 'Старт', 'hero_button_url' => 'https://example.com/st',
    'hero_banner' => 1, 'hero_banner_url' => $IMG]);
$x = $R::hero_html($ho);
[$c, $s] = root($x, 'wpd-hero');
check('banner: classes', $c === 'wpd-hero wpd-hero--dark wpd-hero--banner', $c);
check('banner: hidden h1 with escaped title', has($x, '<h1 class="wpd-sr">Олимпиада «Тест» &lt;2026&gt;</h1>'));
check('banner: picture markup', has($x, '<div class="wpd-hero__banner"><img class="wpd-hero__banner-img" src="' . $IMG . '" alt="" decoding="async"></div>'), $x);
check('banner: no text, badge, buttons, inner', !has($x, 'wpd-hero__inner') && !has($x, 'wpd-badge') && !has($x, 'Подзаголовок') && !has($x, 'wpd-hero__actions') && !has($x, 'Старт'));
check('banner: no lazy loading', !has($x, 'loading="lazy"'));
check('banner: ends with section', substr($x, -strlen('</div></section>')) === '</div></section>');
$x = $R::hero_html(array_merge($ho, ['hero_image_url' => 'https://example.com/side.jpg', 'hero_image_position' => 'background']));
check('banner: side/bg image ignored', !has($x, 'side.jpg') && !has($x, 'wpd-hero--img-') && !has($x, '--wpd-hero-img'));
$x = $R::hero_html(array_merge($ho, ['hero_style' => 'soft', 'hero_soft_orb' => 1]));
check('banner: soft orb ignored', !has($x, 'wpd-orb') && !has($x, 'wpd-hero--orb') && has($x, 'wpd-hero--soft wpd-hero--banner'));
$x = $R::hero_html(array_merge($ho, ['hero_logo' => 'https://example.com/logo.png']));
check('banner: logo not output', !has($x, 'logo.png') && !has($x, 'wpd-hero--logo-'));
$x = $R::hero_html(array_merge($ho, ['hero_banner_edge' => 'padded']));
check('banner: padded class', root($x, 'wpd-hero')[0] === 'wpd-hero wpd-hero--dark wpd-hero--banner wpd-hero--banner-padded');
$x = $R::hero_html(array_merge($ho, ['hero_banner_edge' => 'weird']));
check('banner: unknown edge -> edge', !has($x, 'banner-padded'));
$x = $R::hero_html(array_merge($ho, ['hero_banner_mobile' => $MOB, 'hero_banner_alt' => 'Афиша "олимпиады"']));
check('banner: mobile picture', has($x, '<picture><source media="(max-width: 600px)" srcset="' . $MOB . '"><img class="wpd-hero__banner-img" src="' . $IMG . '" alt="Афиша &quot;олимпиады&quot;" decoding="async"></picture>'), $x);
$x = $R::hero_html(array_merge($ho, ['hero_banner_mobile' => $IMG]));
check('banner: same mobile picture -> no <picture>', !has($x, '<picture>'));
$x = $R::hero_html(array_merge($ho, ['hero_banner_mobile' => 'https://example.com/a,b.jpg']));
check('banner: comma in srcset encoded', has($x, 'srcset="https://example.com/a%2Cb.jpg"'), $x);
$x = $R::hero_html(array_merge($ho, ['hero_banner_link' => 'https://example.com/reg', 'hero_banner_new_tab' => 1]));
check('banner: link with new tab and alt from title', has($x, '<a class="wpd-hero__banner-link" href="https://example.com/reg" target="_blank" rel="noopener noreferrer"><img class="wpd-hero__banner-img" src="' . $IMG . '" alt="Олимпиада «Тест» &lt;2026&gt;"'), $x);
$x = $R::hero_html(array_merge($ho, ['hero_banner_link' => 'https://example.com/reg']));
check('banner: link same tab', has($x, '<a class="wpd-hero__banner-link" href="https://example.com/reg"><img') && !has($x, 'target='));
$x = $R::hero_html(array_merge($ho, ['hero_banner_link' => 'javascript:alert(1)']));
check('banner: bad link dropped', !has($x, 'wpd-hero__banner-link') && !has($x, 'javascript'));
$x = $R::hero_html(array_merge($ho, ['hero_title' => '']));
check('banner: no title -> no h1', !has($x, '<h1'));
$x = $R::hero_html(array_merge($ho, ['hero_banner_buttons' => 1, 'hero_button2_text' => 'Ещё', 'hero_button2_url' => 'https://example.com/more', 'hero_button_icon' => 'awards']));
check('banner: buttons under picture', has($x, '</div><div class="wpd-hero__below"><div class="wpd-hero__actions"><a class="wpd-btn" href="https://example.com/st">')
    && has($x, 'Старт &rarr;</a><a class="wpd-btn wpd-btn--ghost" href="https://example.com/more">Ещё</a></div></div></section>') && has($x, 'dashicons-awards'), $x);
$x = $R::hero_html(array_merge($ho, ['hero_banner_buttons' => 1, 'hero_button_url' => '']));
check('banner: buttons flag without buttons -> no below', !has($x, 'wpd-hero__below'));
$cdo = ['countdown_date' => '2030-01-01 10:00', 'countdown_label' => 'До старта'];
$x = $R::hero_html(array_merge($ho, $cdo, ['hero_countdown' => 1, 'hero_banner_timer' => 1]));
check('banner: timer under picture', has($x, '<div class="wpd-hero__below"><div class="wpd-hero__countdown"><div class="wpd-countdown') && !has($x, 'wpd-hero__actions'), $x);
$x = $R::hero_html(array_merge($ho, $cdo, ['hero_countdown' => 1]));
check('banner: hero timer without flag -> hidden', !has($x, 'wpd-countdown') && !has($x, 'wpd-hero__below'));
$x = $R::hero_html(array_merge($ho, $cdo, ['hero_countdown' => 0, 'hero_banner_timer' => 1]));
check('banner: timer flag without hero timer -> hidden', !has($x, 'wpd-countdown'));
$x = $R::hero_html(array_merge($ho, $cdo, ['hero_countdown' => 1, 'hero_banner_timer' => 1, 'hero_banner_buttons' => 1]));
check('banner: buttons then timer', (int) strpos($x, 'wpd-hero__actions') < (int) strpos($x, 'wpd-hero__countdown') && substr_count($x, 'wpd-hero__below') === 1);
$x = $R::hero_html(array_merge($ho, ['hero_bg' => '#123456', 'hero_bg_tr' => 40, 'hero_padding' => 40]));
[$c, $s] = root($x, 'wpd-hero');
check('banner: background vars kept', cls_has($c, 'wpd-hero--custom-bg') && cls_has($c, 'wpd-hero--tr') && cls_has($c, 'wpd-hero--pad') && has($s, '--wpd-hero-pad:40px;'), $c . ' | ' . $s);
$x = $R::hero_html(array_merge($ho, ['hero_banner_alt' => '"><script>alert(1)</script>']));
check('banner: alt escaped', !has($x, '<script>') && has($x, 'alt="&quot;&gt;&lt;script&gt;'));
$x = $R::hero_html(array_merge($o, ['hero_title' => 'Т']), ['banner' => '1', 'banner_url' => $IMG, 'banner_edge' => 'padded']);
check('hero_html args: banner', has($x, 'wpd-hero--banner wpd-hero--banner-padded') && has($x, $IMG));
$x = $R::hero_html(array_merge($ho, ['hero_show_icons' => 0, 'hero_banner_buttons' => 1, 'hero_button_icon' => 'awards']));
check('banner: icons off applies to buttons', has($x, '>Старт</a>') && !has($x, 'dashicons-awards'));

/* ---------- 5. По ширине ---------- */
$bl = $R::block_look(array_merge($o, ['faq_align' => 'justify']), 'faq');
check('block_look: justify class', has($bl['class'], 'wpd-look--align-justify'), wp_json_encode($bl));
$x = $R::faq_html(array_merge($o, ['faq_align' => 'justify']), [['q' => 'В?', 'a' => 'О.']], 'F');
check('faq html: justify', cls_has(root($x, 'wpd-faq')[0], 'wpd-look--align-justify'), root($x, 'wpd-faq')[0]);
$x = $R::buttons_html(array_merge($o, ['buttons_align' => 'justify']), [['icon' => 'admin-links', 'text' => 'Раз', 'url' => 'https://example.com/1']], 'B');
check('buttons html: justify', cls_has(root($x, 'wpd-buttons')[0], 'wpd-buttons--align-justify'), root($x, 'wpd-buttons')[0]);
$x = $R::hero_html(array_merge($o, ['hero_align' => 'justify']));
check('hero html: justify', root($x, 'wpd-hero')[0] === 'wpd-hero wpd-hero--dark wpd-hero--align-justify', root($x, 'wpd-hero')[0]);
$x = $R::hero_html(array_merge($o, ['hero_align' => 'evil']));
check('hero html: bad align ignored', $x === $hero0);
$la = WPD_Blocks::look_attrs('stats');
$alignAttr = array_search('stats_align', $la, true);
$x = blk('stats', ['items' => $st_items, $alignAttr => 'justify']);
check('block stats: justify attr (' . $alignAttr . ')', cls_has(root($x, 'wpd-stats')[0], 'wpd-look--align-justify'), root($x, 'wpd-stats')[0]);
$x = blk('hero', ['title' => 'Г', 'textAlign' => 'justify']);
check('block hero: textAlign justify', cls_has(root($x, 'wpd-hero')[0], 'wpd-hero--align-justify'));

/* ---------- 6. Ширина текста ---------- */
$bl = $R::box_look(array_merge($o, ['stats_text_w' => 640]), 'stats');
check('box_look: text width', $bl === ['class' => ['wpd-tw'], 'vars' => ['--wpd-tw' => '640px']], wp_json_encode($bl));
check('box_look: min 200', $R::box_look(array_merge($o, ['docs_text_w' => 50]), 'docs')['vars']['--wpd-tw'] === '200px');
check('box_look: max 1600', $R::box_look(array_merge($o, ['docs_text_w' => 99999]), 'docs')['vars']['--wpd-tw'] === '1600px');
check('box_look: hero has no text_w', $R::box_look(array_merge($o, ['hero_text_w' => 600]), 'hero')['class'] === []);
$bl = $R::box_look(array_merge($o, ['faq_min_h' => 300, 'faq_valign' => 'center', 'faq_show_icons' => 0, 'faq_text_w' => 700]), 'faq');
check('box_look: with height and icons', $bl['class'] === ['wpd-bh', 'wpd-bh--center', 'wpd-noicons', 'wpd-tw'] && $bl['vars'] === ['--wpd-bh' => '300px', '--wpd-tw' => '700px'], wp_json_encode($bl));
$x = $R::stats_html(array_merge($o, ['stats_text_w' => 720]), $st_items);
[$c, $s] = root($x, 'wpd-stats');
check('stats: root class and var', $c === 'wpd-stats wpd-tw' && $s === '--wpd-tw:720px;', $c . ' | ' . $s);
$all = [
    'docs' => [$R::docs_html(array_merge($o, ['docs_text_w' => 600]), [['title' => 'П', 'url' => 'https://example.com/p.pdf']], 'Д'), 'wpd-docs'],
    'faq' => [$R::faq_html(array_merge($o, ['faq_text_w' => 600]), [['q' => 'В?', 'a' => 'О.']], 'F'), 'wpd-faq'],
    'buttons' => [$R::buttons_html(array_merge($o, ['buttons_text_w' => 600]), [['icon' => 'admin-links', 'text' => 'Раз', 'url' => 'https://example.com/1']], 'B'), 'wpd-buttons'],
    'cards' => [$R::cards_html(array_merge($o, ['cards_text_w' => 600]), [['title' => 'К', 'url' => 'https://example.com/k']], 'C'), 'wpd-cards'],
    'notice' => [$R::notice_html(array_merge($o, ['notice_enabled' => 1, 'notice_title' => 'Внимание', 'notice_text_w' => 600])), 'wpd-notice'],
    'countdown' => [$R::countdown_html(array_merge($o, ['countdown_enabled' => 1, 'countdown_date' => '2030-01-01 10:00', 'countdown_text_w' => 600])), 'wpd-countdown'],
    'contacts' => [$R::contacts_html(array_merge($o, ['contact_email' => 'a@example.com', 'contacts_text_w' => 600])), 'wpd-contacts'],
    'partners' => [blk('partners', ['items' => [['name' => 'П1', 'logo' => '', 'url' => '']], 'blockTextWidth' => 600]), 'wpd-partners'],
    'slider' => [blk('slider', ['items' => [['title' => 'Слайд', 'text' => 'Т', 'image' => 'https://example.com/s.jpg']], 'blockTextWidth' => 600]), 'wpd-slider'],
];
$okw = true; $bad = '';
foreach ($all as $k => $pair) {
    [$c, $s] = root($pair[0], $pair[1]);
    if (!(cls_has($c, 'wpd-tw') && preg_match('/--wpd-tw:600px(;|$)/', $s))) { $okw = false; $bad .= $k . '=' . $c . ' '; }
}
check('text width: all blocks', $okw, $bad);
$news = blk('news', ['blockTextWidth' => 500]);
check('news block: text width (if rendered)', $news === '' || cls_has(root($news, 'wpd-news')[0], 'wpd-tw') || !has($news, 'wpd-news'), root($news, 'wpd-news')[0]);

/* ---------- 7. Блоки ---------- */
$reg = WP_Block_Type_Registry::get_instance();
$ha = $reg->get_registered('wpd/hero')->attributes;
$ok = true;
foreach (['bannerOn' => 'boolean', 'bannerUrl' => 'string', 'bannerMobileUrl' => 'string', 'bannerAlt' => 'string', 'bannerLink' => 'string', 'bannerNewTab' => 'boolean',
          'bannerEdge' => 'string', 'bannerButtons' => 'boolean', 'bannerTimer' => 'boolean'] as $k => $t) {
    $ok = $ok && ($ha[$k]['type'] ?? '') === $t;
}
check('hero block: banner attributes', $ok);
check('hero block: no blockTextWidth', !isset($ha['blockTextWidth']) && isset($ha['textWidth']));
$ok = true;
foreach (['stats', 'docs', 'faq', 'slider', 'buttons', 'cards', 'notice', 'news', 'countdown', 'contacts', 'partners'] as $slug) {
    $ok = $ok && (($reg->get_registered('wpd/' . $slug)->attributes['blockTextWidth']['type'] ?? '') === 'integer') && (WPD_Blocks::box_attrs($slug)['blockTextWidth'] ?? '') === $slug . '_text_w';
}
check('blocks: blockTextWidth registered', $ok);
check('BOX_ATTR_NAMES', WPD_Blocks::BOX_ATTR_NAMES['text_w'] === 'blockTextWidth');
$lo = WPD_Blocks::look_options_data();
check('look options: blockTextWidth box group', ($lo['stats']['blockTextWidth']['group'] ?? '') === 'box' && $lo['stats']['blockTextWidth']['max'] === 1600
    && ($lo['partners']['blockTextWidth']['group'] ?? '') === 'box' && ($lo['buttons']['blockTextWidth']['group'] ?? '') === 'box' && !isset($lo['hero']['blockTextWidth']));
check('look options: align justify', ($lo['stats'][$alignAttr]['options']['justify'] ?? '') === 'По ширине', (string) $alignAttr);
$ls = WPD_Blocks::look_settings_data(array_merge($o, ['stats_text_w' => 480]));
check('look settings: blockTextWidth', ($ls['stats']['blockTextWidth'] ?? null) === 480 && ($ls['partners']['blockTextWidth'] ?? null) === 0, wp_json_encode($ls['stats']));
$hb = ['title' => 'Блок', 'lead' => 'Текст', 'bannerOn' => true, 'bannerUrl' => $IMG];
$x = blk('hero', $hb);
check('block hero: banner', has($x, 'wpd-hero--banner') && has($x, '<h1 class="wpd-sr">Блок</h1>') && has($x, 'src="' . $IMG . '"') && !has($x, 'Текст'));
$x = blk('hero', ['title' => 'Блок', 'bannerOn' => true]);
check('block hero: on without picture -> normal', !has($x, 'wpd-hero--banner') && has($x, '<h1 class="wpd-hero__title">Блок</h1>'));
$x = blk('hero', $hb + ['bannerMobileUrl' => $MOB, 'bannerAlt' => 'Альт', 'bannerLink' => 'https://example.com/l', 'bannerNewTab' => true, 'bannerEdge' => 'padded']);
check('block hero: all banner attrs', has($x, 'srcset="' . $MOB . '"') && has($x, 'alt="Альт"') && has($x, 'href="https://example.com/l" target="_blank"') && has($x, 'wpd-hero--banner-padded'));
$x = blk('hero', $hb + ['buttonText' => 'Б', 'buttonUrl' => 'https://example.com/b', 'bannerButtons' => true]);
check('block hero: buttons under picture', has($x, '<div class="wpd-hero__below"><div class="wpd-hero__actions">'));
set_opts(array_merge($D, ['countdown_date' => '2030-01-01 10:00']));
$x = blk('hero', $hb + ['showCountdown' => true, 'bannerTimer' => true]);
check('block hero: timer under picture', has($x, '<div class="wpd-hero__below"><div class="wpd-hero__countdown">'));
set_opts(array_merge($D, ['hero_banner' => 1, 'hero_banner_url' => $IMG, 'hero_banner_edge' => 'padded']));
$x = blk('hero', ['title' => 'Из настроек']);
check('block hero: settings banner used', has($x, 'wpd-hero--banner wpd-hero--banner-padded') && has($x, '<h1 class="wpd-sr">Из настроек</h1>'));
$x = blk('hero', ['title' => 'Выкл', 'bannerOn' => false]);
check('block hero: attr off overrides settings', !has($x, 'wpd-hero--banner'));
$x = $R::hero_html(WPD_Settings::get());
check('settings: banner in hero_html', has($x, 'wpd-hero--banner-padded'));
set_opts(array_merge($D, ['stats_text_w' => 333]));
$x = blk('stats', ['items' => $st_items]);
check('block stats: settings text width used', has($x, '--wpd-tw:333px;'));
$x = blk('stats', ['items' => $st_items, 'blockTextWidth' => 0]);
check('block stats: 0 -> settings', has($x, '--wpd-tw:333px;'));
$x = blk('stats', ['items' => $st_items, 'blockTextWidth' => 900]);
check('block stats: attr overrides', has($x, '--wpd-tw:900px;'));
set_opts($D);
$x = blk('stats', ['items' => $st_items]);
check('block stats: default none', !has($x, 'wpd-tw'));

/* ---------- 8. Панель страницы ---------- */
$gk = WPD_Page_Editor::group_keys('hero');
check('page groups: banner keys in hero group', count(array_intersect($banner_keys, $gk)) === 9);
check('page groups: text_w keys', in_array('stats_text_w', WPD_Page_Editor::group_keys('stats'), true) && in_array('buttons_text_w', WPD_Page_Editor::group_keys('buttons'), true)
    && in_array('partners_text_w', WPD_Page_Editor::group_keys('partners'), true) && !in_array('hero_text_w', $gk, true));
$pid = (int) wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'AB panel', 'post_content' => '<p>AB</p>']);
$created[] = $pid;
set_opts(array_merge($D, ['faq' => [['q' => 'В?', 'a' => 'О.']], 'faq_title' => 'FAQ AB']));
update_post_meta($pid, '_wpd_page', WPD_Page_Editor::sanitize(['enabled' => '1', 'order' => ['hero', 'faq'], 'show' => ['hero' => '1', 'faq' => '1'], 'ovg' => ['hero' => '1', 'faq' => '1'],
    'ov' => ['hero_banner' => '1', 'hero_banner_url' => $IMG, 'hero_banner_edge' => 'padded', 'hero_banner_link' => 'javascript:x', 'hero_show_icons' => '1',
             'faq_text_w' => '99999', 'faq_align' => 'justify', 'faq_show_icons' => '1']], $pid));
update_post_meta($pid, WPD_Renderer::META_KEY, 1);
WPD_Settings::flush_cache();
$po = WPD_Page_Editor::options($pid);
check('page options: sanitized', (int) $po['hero_banner'] === 1 && $po['hero_banner_url'] === $IMG && $po['hero_banner_edge'] === 'padded' && $po['hero_banner_link'] === ''
    && $po['faq_text_w'] === 1600 && $po['faq_align'] === 'justify', wp_json_encode([$po['hero_banner'], $po['hero_banner_link'], $po['faq_text_w']]));
$ph = WPD_Renderer::page_html($pid, '<p>AB</p>');
check('page html: hero banner', has($ph, 'wpd-hero--banner wpd-hero--banner-padded') && has($ph, '<h1 class="wpd-sr">AB panel</h1>'), substr($ph, 0, 600));
[$c, $s] = root($ph, 'wpd-faq');
check('page html: faq justify + width', cls_has($c, 'wpd-look--align-justify') && cls_has($c, 'wpd-tw') && has($s, '--wpd-tw:1600px;'), $c . ' | ' . $s);

/* ---------- 9. CSS, JS, справка, README ---------- */
$css = (string) file_get_contents($dir . 'assets/css/wpd.css');
check('css: sr-only', has($css, '.wpd-page .wpd-sr {') && has($css, 'clip: rect(0, 0, 0, 0);'));
check('css: justify', has($css, '.wpd-page .wpd-look.wpd-look--align-justify > .wpd-h2,') && has($css, '.wpd-page .wpd-buttons--align-justify .wpd-qbtn,')
    && has($css, '.wpd-page .wpd-hero--align-justify .wpd-hero__text {') && has($css, 'text-align: justify;') && has($css, 'hyphens: auto;'));
check('css: text width', has($css, '.wpd-page .wpd-tw:not(.wpd-slider):not(.wpd-notice) > * {') && has($css, 'max-width: var(--wpd-tw);')
    && has($css, '.wpd-page .wpd-slider.wpd-tw :is(.wpd-slide__title, .wpd-slide__text) {') && has($css, '.wpd-page .wpd-notice.wpd-tw .wpd-notice__body > * {'));
check('css: banner', has($css, '.wpd-page .wpd-hero.wpd-hero--banner {') && has($css, '.wpd-page .wpd-hero.wpd-hero--banner:not(.wpd-hero--banner-padded) {')
    && has($css, '.wpd-page .wpd-hero__banner-img {') && has($css, '.wpd-page .wpd-hero__below {'));
$js = (string) file_get_contents($dir . 'assets/js/wpd-blocks.js');
check('js: banner panel and canvas', has($js, "title: 'Картинка вместо текста'") && has($js, 'function bannerMedia(key, label)') && has($js, "className: 'wpd-hero__banner'")
    && has($js, "className: 'wpd-hero__below'") && has($js, "cls.push('wpd-hero--banner')"));
check('js: banner toggles', has($js, "set({ bannerButtons: !!v })") && has($js, "set({ bannerTimer: !!v })") && has($js, "set({ bannerNewTab: !!v })") && has($js, "set({ bannerEdge: v })"));
check('js: text width in boxLook', has($js, "cls.push('wpd-tw')") && has($js, "st['--wpd-tw']") && has($js, "'Высота, ширина текста и иконки'"));
check('js: banner disables side image', has($js, "var imgUrl = bannerOn ? '' : a.imageUrl;") && has($js, 'var orb = !bannerOn && !imgUrl'));
$rh = new ReflectionMethod('WPD_Settings', 'render_help');
$rh->setAccessible(true);
ob_start();
$rh->invoke(null);
$help = (string) ob_get_clean();
check('help: section 2.14.0', has($help, 'Картинка вместо текста и ширина текста (2.14.0)') && has($help, 'По ширине') && has($help, 'Максимальная ширина текста, px'));
$readme = (string) file_get_contents($dir . 'README.md');
check('README: 2.14.0 section', has($readme, '## Картинка вместо текста и ширина текста (2.14.0)') && has($readme, '`hero_banner_edge`') && has($readme, '`blockTextWidth`'));
check('version 2.14.0', version_compare(WPD_VERSION, '2.14.0', '>='));

/* ---------- Уборка ---------- */
foreach ($created as $id) { wp_delete_post($id, true); }
if (is_array($backup)) { set_opts($backup); } else { delete_option('wpd_settings'); WPD_Settings::flush_cache(); }
check('cleanup', !get_post($pid));

echo implode("\n", $results) . "\n";
echo "TOTAL: " . count($results) . ", FAILED: $fails\n";
