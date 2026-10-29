<?php
// Фаза Y (2.11.0): прозрачность фонов и размеры текстов блоков. Поля настроек (по одному на каждый фон и размер,
// границы, подписи, группы), санитизация; помощники рендерера (bg_alpha, alpha_color, blend_color, page_bg);
// «Раскладка и вид» (классы, переменные, нижние границы размеров, прозрачный свой цвет — rgba, без своего — класс
// и доля, цвет текста по видимому цвету); главный блок, кнопки-ссылки, логотипы партнёров; вид блока в панели страницы
// (bg_tr) и фон раздела блоков редактора (sectionBg, sectionBgTr); атрибуты блоков (0 — как в настройках), данные
// редактора, CSS, JS, справка, версия. В конце удаляет свои записи и возвращает опцию.
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
/** Класс и style первого тега с классом, начинающимся с $base. */
function root(string $html, string $base): array {
    if (!preg_match('/<(?:section|div|nav) class="(' . preg_quote($base, '/') . '(?: [^"]*)?)"(?: style="([^"]*)")?/', $html, $m)) {
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

/* ---------- 1. Версия и поля настроек ---------- */
check('version >= 2.11.0', version_compare(WPD_VERSION, '2.11.0', '>='));
$new_look = [
    'stats' => ['title_size', 'bg_tr'], 'docs' => ['title_size', 'small_size', 'bg_tr'], 'faq' => ['title_size', 'item_size', 'bg_tr'],
    'cards' => ['title_size', 'item_size', 'small_size', 'bg_tr'], 'news' => ['title_size', 'item_size', 'small_size', 'bg_tr'],
    'notice' => ['item_size', 'small_size', 'bg_tr'], 'slider' => ['item_size', 'small_size', 'bg_tr'], 'countdown' => ['small_size', 'bg_tr'],
    'contacts' => ['title_size', 'item_size', 'small_size', 'bg_tr'],
];
$missing = [];
foreach ($new_look as $slug => $keys) {
    foreach ($keys as $k) {
        if (!isset($map[$slug . '_' . $k]) || $map[$slug . '_' . $k]['section'] !== $slug . '_look' || $map[$slug . '_' . $k]['type'] !== 'number') { $missing[] = $slug . '_' . $k; }
    }
}
check('look: new size and transparency fields in <slug>_look', $missing === [], implode(',', $missing));
$other = ['hero_bg_tr' => ['hero_look', 100], 'hero_title_px' => ['hero_look', 96], 'hero_badge_size' => ['hero_look', 24], 'hero_lead_size' => ['hero_look', 32],
    'hero_button_size' => ['hero_look', 28], 'buttons_title_size' => ['buttons_layout', 72], 'buttons_small_size' => ['buttons_look', 24], 'buttons_bg_tr' => ['buttons_look', 100],
    'partners_title_size' => ['partners_look', 72], 'partners_small_size' => ['partners_look', 24], 'partners_bg_tr' => ['partners_look', 100]];
$bad = [];
foreach ($other as $k => $d) {
    if (!isset($map[$k]) || $map[$k]['section'] !== $d[0] || (int) $map[$k]['max'] !== $d[1] || (int) $map[$k]['min'] !== 0 || (int) $map[$k]['default'] !== 0) { $bad[] = $k; }
}
check('hero/buttons/partners: new fields, sections, ranges, default 0', $bad === [], implode(',', $bad));
$n_new = 0;
foreach ($new_look as $keys) { $n_new += count($keys); }
check('39 new fields, 292 total', $n_new + count($other) === 39 && count($map) === 292, (string) count($map));
check('no bg field for notice, but notice_bg_tr', !isset($map['notice_bg']) && isset($map['notice_bg_tr']));
check('countdown has no title/item size', !isset($map['countdown_title_size']) && !isset($map['countdown_item_size']));
$ranges = true;
foreach ($map as $k => $f) {
    if (preg_match('/_(title_size|item_size|small_size|bg_tr)$/', $k, $m) && strpos($k, 'hero_') !== 0) {
        $want = ['title_size' => 72, 'item_size' => 48, 'small_size' => 24, 'bg_tr' => 100][$m[1]];
        if ((int) $f['max'] !== $want || (int) $f['default'] !== 0) { $ranges = false; }
    }
}
check('ranges: title 72, item 48, small 24, transparency 100', $ranges);
$keys = array_keys($map);
$pos = function ($k) use ($keys) { return array_search($k, $keys, true); };
check('order: bg_tr right after bg', $pos('stats_bg_tr') === $pos('stats_bg') + 1 && $pos('slider_bg_tr') === $pos('slider_bg') + 1
    && $pos('hero_bg_tr') === $pos('hero_bg') + 1 && $pos('buttons_bg_tr') === $pos('buttons_bg') + 1);
check('order: sizes around text size', $pos('faq_title_size') < $pos('faq_item_size') && $pos('faq_item_size') < $pos('faq_text_size')
    && $pos('cards_small_size') === $pos('cards_text_size') + 1 && $pos('buttons_title_size') === $pos('buttons_title') + 1
    && $pos('buttons_small_size') === $pos('buttons_text_size') + 1);
check('order: notice bg_tr before accent', $pos('notice_bg_tr') + 1 === $pos('notice_accent'));
check('labels: per block', $map['faq_item_size']['label'] === 'Размер вопросов, px' && $map['cards_item_size']['label'] === 'Размер заголовков карточек, px'
    && $map['news_small_size']['label'] === 'Размер дат и ссылок, px' && $map['countdown_small_size']['label'] === 'Размер текста кнопки, px'
    && $map['contacts_item_size']['label'] === 'Размер названия организации, px' && $map['stats_title_size']['label'] === 'Размер заголовка блока, px');
check('labels: transparency', $map['stats_bg_tr']['label'] === 'Прозрачность фона карточек, %' && $map['slider_bg_tr']['label'] === 'Прозрачность фона подписи, %'
    && $map['notice_bg_tr']['label'] === 'Прозрачность фона объявления, %' && $map['hero_bg_tr']['label'] === 'Прозрачность фона, %');
check('text_size label kept', $map['faq_text_size']['label'] === 'Размер текста, px' && has($map['faq_text_size']['desc'], 'если для них не задан свой размер'));
check('descs mention 0/100', has($map['docs_bg_tr']['desc'], '100 — полностью прозрачный') && has($map['hero_title_px']['desc'], '11 %'));
check('LOOK_TEXT_RANGE', WPD_Settings::LOOK_TEXT_RANGE === ['title_size' => [16, 72], 'item_size' => [12, 48], 'text_size' => [11, 28], 'small_size' => [10, 24]]);

/* ---------- 2. Санитизация ---------- */
$s = WPD_Settings::sanitize(['_tab' => 'stats', 'stats_bg_tr' => '150', 'stats_title_size' => '-4']);
check('sanitize: transparency clamped to 100, negative -> 0', (int) $s['stats_bg_tr'] === 100 && (int) $s['stats_title_size'] === 0, $s['stats_bg_tr'] . '/' . $s['stats_title_size']);
$s = WPD_Settings::sanitize(['_tab' => 'hero', 'hero_bg_tr' => '35', 'hero_title_px' => '200', 'hero_lead_size' => 'abc']);
check('sanitize: hero numbers', (int) $s['hero_bg_tr'] === 35 && (int) $s['hero_title_px'] === 96 && (int) $s['hero_lead_size'] === 0);
check('sanitize idempotent', WPD_Settings::sanitize_value('hero_title_px', $s['hero_title_px']) === $s['hero_title_px'] && WPD_Settings::sanitize_value('stats_bg_tr', 100) === 100);

/* ---------- 3. Помощники ---------- */
check('bg_alpha', WPD_Renderer::bg_alpha(30) === 0.7 && WPD_Renderer::bg_alpha(0) === 1.0 && WPD_Renderer::bg_alpha(150) === 0.0 && WPD_Renderer::bg_alpha('x') === 1.0);
check('alpha_color', WPD_Renderer::alpha_color('#1d4ed8', 0.4) === 'rgba(29,78,216,0.4)' && WPD_Renderer::alpha_color('#fff', 0.25) === 'rgba(255,255,255,0.25)'
    && WPD_Renderer::alpha_color('#1d4ed8', 1.0) === '#1d4ed8' && WPD_Renderer::alpha_color('#000000', 0.0) === 'rgba(0,0,0,0)' && WPD_Renderer::alpha_color('red', 0.5) === '');
check('blend_color', WPD_Renderer::blend_color('#ffffff', 0.4, '#f1f5f9') === '#f7f9fb' && WPD_Renderer::blend_color('#000000', 0.5, '#ffffff') === '#808080'
    && WPD_Renderer::blend_color('#123456', 1.0, '#ffffff') === '#123456' && WPD_Renderer::blend_color('x', 0.5, '#fff') === '');
check('page_bg', WPD_Renderer::page_bg(['bg' => '#101010']) === '#101010' && WPD_Renderer::page_bg(['bg' => '']) === '#f1f5f9');

/* ---------- 4. block_look ---------- */
$look = function (string $slug, array $v) use ($D) { return WPD_Renderer::block_look(array_merge($D, ['bg' => '#f1f5f9'], $v), $slug); };
check('look: nothing set -> as before', $look('stats', []) === ['class' => 'wpd-look', 'style' => '']);
$l = $look('stats', ['stats_title_size' => 30, 'stats_bg_tr' => 40]);
check('look: title size + transparency without own colour', $l['class'] === 'wpd-look wpd-look--fs-title wpd-look--bgtr wpd-look--onlight' && $l['style'] === '--wpd-bl-fs-title:30px;--wpd-bl-bg-a:60%;', $l['class'] . ' | ' . $l['style']);
$l = $look('faq', ['faq_title_size' => 5, 'faq_item_size' => 60, 'faq_text_size' => 15]);
check('look: lower bound 16, upper 48, order title-item-text', $l['class'] === 'wpd-look wpd-look--fs-title wpd-look--fs-item wpd-look--fs' && $l['style'] === '--wpd-bl-fs-title:16px;--wpd-bl-fs-item:48px;--wpd-bl-fs:15px;', $l['style']);
$l = $look('docs', ['docs_small_size' => 3]);
check('look: small at least 10', $l['class'] === 'wpd-look wpd-look--fs-small' && $l['style'] === '--wpd-bl-fs-small:10px;');
$l = $look('cards', ['cards_item_size' => 5]);
check('look: item at least 12', $l['style'] === '--wpd-bl-fs-item:12px;');
$l = $look('docs', ['docs_bg' => '#0b1220', 'docs_bg_tr' => 50]);
check('look: own colour with transparency -> rgba, no bgtr class', $l['class'] === 'wpd-look wpd-look--bg wpd-look--ondark' && $l['style'] === '--wpd-bl-bg:rgba(11,18,32,0.5);', $l['class'] . ' | ' . $l['style']);
$l = $look('docs', ['docs_bg' => '#0b1220', 'docs_bg_tr' => 90]);
check('look: dark colour 90% transparent on light page -> dark text', $l['class'] === 'wpd-look wpd-look--bg wpd-look--onlight', $l['class']);
$l = $look('docs', ['docs_bg' => '#0b1220', 'docs_bg_tr' => 90, 'bg' => '#111111']);
check('look: same on dark page -> light text', cls_has($l['class'], 'wpd-look--ondark'), $l['class']);
check('look: transparency 0 -> nothing', $look('news', ['news_bg_tr' => 0]) === ['class' => 'wpd-look', 'style' => '']);
check('look: transparency 100 -> 0%', $look('news', ['news_bg_tr' => 100])['style'] === '--wpd-bl-bg-a:0%;');
check('look: cards default (primary) 80% transparent -> dark text', cls_has($look('cards', ['cards_bg_tr' => 80])['class'], 'wpd-look--onlight'));
check('look: solid 20% transparent stays light text', cls_has($look('stats', ['stats_variant' => 'solid', 'stats_bg_tr' => 20])['class'], 'wpd-look--ondark'));
check('look: solid with light accent, 20% -> dark text', cls_has($look('stats', ['stats_variant' => 'solid', 'stats_accent' => '#fde68a', 'stats_bg_tr' => 20])['class'], 'wpd-look--onlight'));
$l = $look('slider', ['slider_bg_tr' => 50]);
check('look: slider caption transparency, no tone class', $l['class'] === 'wpd-look wpd-look--bgtr' && $l['style'] === '--wpd-bl-bg-a:50%;', $l['class']);
check('look: outline keeps variant tone', $look('faq', ['faq_variant' => 'outline', 'faq_bg_tr' => 50])['class'] === 'wpd-look wpd-look--outline wpd-look--bgtr wpd-look--onlight');
$l = $look('notice', ['notice_bg_tr' => 30, 'notice_item_size' => 26, 'notice_small_size' => 12]);
check('look: notice', $l['class'] === 'wpd-look wpd-look--fs-item wpd-look--fs-small wpd-look--bgtr wpd-look--onlight' && has($l['style'], '--wpd-bl-bg-a:70%;'), $l['class']);
$l = $look('notice', ['notice_bg_tr' => 30, 'notice_color' => '#0b1220']);
check('look: notice own dark colour 30% -> light text', cls_has($l['class'], 'wpd-look--ondark'), $l['class']);
check('look: keys of other blocks ignored', $look('countdown', ['countdown_title_size' => 40, 'stats_bg_tr' => 50]) === ['class' => 'wpd-look', 'style' => '']);
check('look: values above max clamped', $look('contacts', ['contacts_title_size' => 500, 'contacts_bg_tr' => 400])['style'] === '--wpd-bl-fs-title:72px;--wpd-bl-bg-a:0%;');

/* ---------- 5. Разметка блоков ---------- */
set_opts(array_merge($D, ['bg' => '#f1f5f9', 'stats_title' => 'Цифры', 'stats' => [['num' => '300+', 'label' => 'школ']],
    'faq' => [['q' => 'Q1', 'a' => 'A1']], 'cards' => [['title' => 'Карта', 'text' => 'Т', 'image' => '', 'color' => '', 'url' => 'https://example.com/c', 'new_tab' => 0]],
    'notice_layout' => 'text', 'notice_title' => 'Объявление', 'notice_color' => '#0b1220', 'notice_url' => 'https://example.com/n',
    'stats_title_size' => 34, 'stats_bg_tr' => 25, 'faq_item_size' => 20, 'cards_small_size' => 11, 'notice_bg_tr' => 85]));
$o = WPD_Settings::get();
[$c, $st] = root(WPD_Renderer::stats_html($o), 'wpd-stats');
check('html: stats root classes and vars', cls_has($c, 'wpd-look--fs-title') && cls_has($c, 'wpd-look--bgtr') && has($st, '--wpd-bl-fs-title:34px') && has($st, '--wpd-bl-bg-a:75%'), $c . ' | ' . $st);
[$c, $st] = root(WPD_Renderer::faq_html($o), 'wpd-faq');
check('html: faq item size', cls_has($c, 'wpd-look--fs-item') && has($st, '--wpd-bl-fs-item:20px'));
[$c, $st] = root(WPD_Renderer::cards_html($o), 'wpd-cards');
check('html: cards small size', cls_has($c, 'wpd-look--fs-small') && has($st, '--wpd-bl-fs-small:11px'));
[$c, $st] = root(WPD_Renderer::notice_html($o), 'wpd-notice');
check('html: notice dark colour 85% transparent -> dark text', cls_has($c, 'wpd-look--bgtr') && cls_has($c, 'wpd-look--onlight') && !cls_has($c, 'wpd-look--ondark') && has($st, '--wpd-notice-bg:#0b1220'), $c);

/* ---------- 6. Главный блок ---------- */
$hero = function (array $v, array $args = []) use ($D) {
    $html = WPD_Renderer::hero_html(array_merge($D, ['bg' => '#f1f5f9', 'dark' => '#0f172a', 'hero_title' => 'Олимпиада', 'hero_badge' => 'Метка', 'hero_button_url' => 'https://example.com/r'], $v), $args);
    return root($html, 'wpd-hero');
};
[$c, $st] = $hero([]);
check('hero: nothing set -> no new classes', !has($c, 'wpd-hero--tr') && !has($c, 'wpd-hero--fs-') && !has($st, '--wpd-hero-a'));
[$c, $st] = $hero(['hero_bg_tr' => 40]);
check('hero: dark style 40% -> tr class, share, light text', cls_has($c, 'wpd-hero--tr') && has($st, '--wpd-hero-a:60%;') && cls_has($c, 'wpd-hero--fg-light'), $c);
[$c, $st] = $hero(['hero_bg_tr' => 80]);
check('hero: dark style 80% on light page -> dark text', cls_has($c, 'wpd-hero--fg-dark') && !cls_has($c, 'wpd-hero--fg-light'), $c);
[$c, $st] = $hero(['hero_style' => 'light', 'hero_bg_tr' => 50]);
check('hero: light style -> dark text', cls_has($c, 'wpd-hero--tr') && cls_has($c, 'wpd-hero--fg-dark'));
[$c, $st] = $hero(['hero_bg' => '#ffffff', 'hero_bg_tr' => 50]);
check('hero: own colour -> rgba', cls_has($c, 'wpd-hero--custom-bg') && cls_has($c, 'wpd-hero--fg-dark') && has($st, '--wpd-hero-bg:rgba(255,255,255,0.5);') && cls_has($c, 'wpd-hero--tr'), $st);
[$c, $st] = $hero(['hero_bg' => '#0b1220', 'hero_bg_tr' => 90]);
check('hero: own dark colour 90% -> dark text, one fg class', cls_has($c, 'wpd-hero--fg-dark') && substr_count($c, 'wpd-hero--fg-') === 1, $c);
[$c, $st] = $hero(['hero_style' => 'soft', 'hero_bg_tr' => 50]);
check('hero: soft style ignores transparency', !cls_has($c, 'wpd-hero--tr'));
[$c, $st] = $hero(['hero_image_url' => home_url('/hero19.svg'), 'hero_image_position' => 'background', 'hero_bg_tr' => 50]);
check('hero: background image ignores transparency', !cls_has($c, 'wpd-hero--tr') && cls_has($c, 'wpd-hero--img-background'));
[$c, $st] = $hero(['hero_title_px' => 10, 'hero_badge_size' => 30, 'hero_lead_size' => 18, 'hero_button_size' => 14]);
check('hero: sizes with bounds', cls_has($c, 'wpd-hero--fs-title') && cls_has($c, 'wpd-hero--fs-badge') && cls_has($c, 'wpd-hero--fs-lead') && cls_has($c, 'wpd-hero--fs-btn')
    && has($st, '--wpd-hero-fs-title:20px;--wpd-hero-fs-badge:24px;--wpd-hero-fs-lead:18px;--wpd-hero-fs-btn:14px;'), $st);
[$c, $st] = $hero([], ['title_px' => 60, 'bg_tr' => 20]);
check('hero: args override settings', has($st, '--wpd-hero-fs-title:60px') && has($st, '--wpd-hero-a:80%'));

/* ---------- 7. Кнопки-ссылки ---------- */
$bl = function (array $v) use ($D) { return WPD_Renderer::buttons_look(array_merge($D, ['bg' => '#f1f5f9'], $v)); };
$b = $bl(['buttons_title_size' => 12, 'buttons_small_size' => 30, 'buttons_bg_tr' => 40]);
check('buttons: classes and vars', $b['class'] === 'wpd-buttons wpd-buttons--fs-title wpd-buttons--fs-sub wpd-buttons--bgtr'
    && has($b['style'], '--wpd-qb-fs-title:16px;--wpd-qb-fs-sub:24px;--wpd-qb-a:60%;'), $b['class'] . ' | ' . $b['style']);
check('buttons: dark bg opaque -> dark', has($bl(['buttons_bg' => '#0b1220'])['class'], 'wpd-buttons--dark'));
$b = $bl(['buttons_bg' => '#0b1220', 'buttons_bg_tr' => 90]);
check('buttons: dark bg 90% transparent -> not dark, hex kept', !has($b['class'], 'wpd-buttons--dark') && has($b['style'], '--wpd-qb-bg:#0b1220'), $b['class']);
check('buttons: nothing new set -> as before', $bl([])['class'] === 'wpd-buttons');

/* ---------- 8. Логотипы партнёров ---------- */
$items = [['logo' => '', 'name' => 'Партнёр', 'url' => '', 'new_tab' => 0, 'hidden' => 0]];
[$c, $st] = root(WPD_Renderer::partners_html(array_merge($D, ['partners' => $items, 'partners_cards' => 1, 'partners_bg_tr' => 30, 'partners_title_size' => 20, 'partners_small_size' => 9])), 'wpd-partners');
check('partners: classes and vars', cls_has($c, 'wpd-partners--bgtr') && cls_has($c, 'wpd-partners--fs-title') && cls_has($c, 'wpd-partners--fs-small')
    && has($st, '--wpd-pt-fs-title:20px;--wpd-pt-fs-small:10px;--wpd-pt-a:70%;'), $c . ' | ' . $st);
[$c, $st] = root(WPD_Renderer::partners_html(array_merge($D, ['partners' => $items, 'partners_cards' => 0, 'partners_bg_tr' => 30])), 'wpd-partners');
check('partners: transparency only with cards', !cls_has($c, 'wpd-partners--bgtr') && !has($st, '--wpd-pt-a'));

/* ---------- 9. Панель страницы: фон блока с прозрачностью ---------- */
$cb = WPD_Page_Editor::clean_bstyle(['stats' => ['bg' => '#0b1220', 'bg_tr' => '150'], 'faq' => ['bg' => '', 'bg_tr' => '40'], 'docs' => ['bg' => '#ffffff', 'bg_tr' => 'abc'],
    'cards' => ['bg' => '#ffffff', 'bg_tr' => '0'], 'hero' => ['bg' => '#000000', 'bg_tr' => '50']]);
check('bstyle: transparency clamped, only with colour', ($cb['stats']['bg_tr'] ?? '') === '100' && !isset($cb['faq']) && ($cb['docs']['bg_tr'] ?? 'x') === ''
    && ($cb['cards']['bg_tr'] ?? 'x') === '' && !isset($cb['hero']), wp_json_encode($cb));
$dec = WPD_Renderer::decorate_block('<section class="wpd-stats"></section>', ['bg' => '#0b1220', 'bg_tr' => '90'], 'stats', array_merge($D, ['bg' => '#f1f5f9']));
check('decorate: rgba and light class by seen colour', has($dec, '--wpd-b-bg:rgba(11,18,32,0.1);') && has($dec, 'wpd-b--bg-light'), $dec);
$dec = WPD_Renderer::decorate_block('<section class="wpd-stats"></section>', ['bg' => '#0b1220', 'bg_tr' => ''], 'stats', $D);
check('decorate: no transparency -> hex and dark', has($dec, '--wpd-b-bg:#0b1220;') && has($dec, 'wpd-b--bg-dark'));
$dec = WPD_Renderer::decorate_block('<section class="wpd-stats"></section>', ['bg' => '#0b1220', 'bg_tr' => '20'], 'stats');
check('decorate: without settings argument', has($dec, 'rgba(11,18,32,0.8)'));
$rm = new ReflectionMethod('WPD_Page_Editor', 'bstyle_html');
$rm->setAccessible(true);
$bh = $rm->invoke(null, 'stats', ['bg' => '#0b1220', 'bg_tr' => '35']);
check('bstyle_html: transparency field', has($bh, 'name="wpd_page[bstyle][stats][bg_tr]"') && has($bh, 'value="35"') && has($bh, 'Прозрачность фона, %') && has($bh, 'max="100"'));
check('bstyle_html: hero has no transparency field', !has($rm->invoke(null, 'hero', []), '[bg_tr]'));
$gk = WPD_Page_Editor::group_keys('stats');
check('page groups include new fields', in_array('stats_title_size', $gk, true) && in_array('stats_bg_tr', $gk, true)
    && in_array('hero_bg_tr', WPD_Page_Editor::group_keys('hero'), true) && in_array('buttons_title_size', WPD_Page_Editor::group_keys('buttons'), true)
    && in_array('partners_bg_tr', WPD_Page_Editor::group_keys('partners'), true) && in_array('countdown_small_size', WPD_Page_Editor::group_keys('features'), true));
check('page groups: descs mention sizes and transparency', has(WPD_Page_Editor::groups()['stats']['desc'], 'прозрачность') && has(WPD_Page_Editor::groups()['hero']['desc'], 'прозрачность фона'));
// Сохранение и вывод страницы
$pid = (int) wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Y bstyle', 'post_content' => '<p>Y</p>']);
$created[] = $pid;
$meta = WPD_Page_Editor::sanitize(['enabled' => '1', 'order' => ['stats', 'faq'], 'show' => ['stats' => '1', 'faq' => '1'], 'bstyle' => ['stats' => ['bg' => '#0b1220', 'bg_tr' => '60']]]);
update_post_meta($pid, '_wpd_page', $meta);
WPD_Settings::flush_cache();
$p = WPD_Page_Editor::get($pid);
check('page meta keeps bg_tr', ($p['bstyle']['stats']['bg_tr'] ?? '') === '60', wp_json_encode($p['bstyle'] ?? null));
$ph = WPD_Renderer::page_html($pid, '<p>Y</p>');
check('page html: block background with transparency', has($ph, '--wpd-b-bg:rgba(11,18,32,0.4);'), substr($ph, 0, 300));
update_post_meta($pid, '_wpd_page', WPD_Page_Editor::sanitize(['enabled' => '1', 'order' => ['stats', 'faq'], 'show' => ['stats' => '1', 'faq' => '1'], 'ovg' => ['stats' => '1'],
    'ov' => ['stats_bg_tr' => '50'], 'bstyle' => ['stats' => ['bg' => '#0b1220'], 'faq' => ['bg' => '']]], $pid));
$ph = WPD_Renderer::page_html($pid, '<p>Y</p>');
check('page html: transparent cards on dark block -> light text', (bool) preg_match('/class="wpd-stats[^"]*wpd-look--ondark[^"]*wpd-b--bg-dark/', $ph), substr($ph, 0, 400));

/* ---------- 10. Блоки редактора ---------- */
$defs = WPD_Blocks::definitions();
$attr_ok = true;
foreach (['stats' => ['headingSize', 'bgTransparency'], 'faq' => ['headingSize', 'itemSize', 'bgTransparency'], 'cards' => ['headingSize', 'itemSize', 'smallSize', 'bgTransparency'],
          'notice' => ['itemSize', 'smallSize', 'bgTransparency'], 'countdown' => ['smallSize', 'bgTransparency']] as $slug => $want) {
    foreach ($want as $a) { if (($defs[$slug]['attributes'][$a]['type'] ?? '') !== 'integer') { $attr_ok = false; } }
}
check('blocks: look attributes', $attr_ok && !isset($defs['countdown']['attributes']['headingSize']));
check('blocks: hero attributes', isset($defs['hero']['attributes']['bgTransparency'], $defs['hero']['attributes']['titlePx'], $defs['hero']['attributes']['badgeSize'],
    $defs['hero']['attributes']['leadSize'], $defs['hero']['attributes']['buttonSize']) && $defs['hero']['attributes']['titlePx']['default'] === 0);
check('blocks: buttons and partners attributes', isset($defs['buttons']['attributes']['headingSize'], $defs['buttons']['attributes']['smallSize'], $defs['buttons']['attributes']['bgTransparency'],
    $defs['partners']['attributes']['headingSize'], $defs['partners']['attributes']['smallSize'], $defs['partners']['attributes']['bgTransparency']));
$sec_ok = true;
foreach ($defs as $slug => $def) {
    $hasS = isset($def['attributes']['sectionBg'], $def['attributes']['sectionBgTr']);
    if ($slug === 'hero' ? $hasS : !$hasS) { $sec_ok = false; }
}
check('blocks: section background on all but hero', $sec_ok && count($defs) === 12);
$lo = WPD_Blocks::look_options_data();
check('editor: transparency in colours, sizes in look', $lo['stats']['bgTransparency']['group'] === 'colors' && $lo['faq']['itemSize']['group'] === 'look'
    && $lo['docs']['smallSize']['max'] === 24 && $lo['docs']['smallSize']['label'] === 'Размер подписей и меток, px');
$ss = WPD_Blocks::size_settings_data(array_merge($D, ['hero_bg_tr' => 30, 'partners_small_size' => 12]));
check('editor: size settings data', $ss['hero']['bgTransparency'] === 30 && $ss['partners']['smallSize'] === 12 && array_keys($ss['hero']) === ['bgTransparency', 'titlePx', 'badgeSize', 'leadSize', 'buttonSize']);

set_opts(array_merge($D, ['bg' => '#f1f5f9', 'stats' => [['num' => '1', 'label' => 'x']], 'faq' => [['q' => 'Q', 'a' => 'A']], 'hero_title' => 'Олимпиада',
    'hero_bg_tr' => 30, 'stats_bg_tr' => 20, 'buttons' => [['text' => 'Кн', 'url' => 'https://example.com/b', 'icon' => 'book']], 'partners' => $items]));
$h = blk('stats', ['headingSize' => 40, 'bgTransparency' => 50]);
[$c, $st] = root($h, 'wpd-stats');
check('block stats: attributes override settings', has($st, '--wpd-bl-fs-title:40px') && has($st, '--wpd-bl-bg-a:50%'), $st);
[$c, $st] = root(blk('stats', ['bgTransparency' => 0]), 'wpd-stats');
check('block stats: 0 -> settings', has($st, '--wpd-bl-bg-a:80%'), $st);
[$c, $st] = root(blk('hero', []), 'wpd-hero');
check('block hero: settings transparency', has($st, '--wpd-hero-a:70%'), $st);
[$c, $st] = root(blk('hero', ['bgTransparency' => 60, 'titlePx' => 50, 'leadSize' => 20]), 'wpd-hero');
check('block hero: attributes', has($st, '--wpd-hero-a:40%') && has($st, '--wpd-hero-fs-title:50px') && has($st, '--wpd-hero-fs-lead:20px'), $st);
$b = blk('buttons', ['headingSize' => 30, 'smallSize' => 12, 'bgTransparency' => 25, 'title' => 'Ссылки']);
check('block buttons: attributes', has($b, 'wpd-buttons--fs-title') && has($b, '--wpd-qb-fs-title:30px') && has($b, '--wpd-qb-fs-sub:12px') && has($b, '--wpd-qb-a:75%'));
$pt = blk('partners', ['headingSize' => 28, 'bgTransparency' => 50, 'cards' => true]);
check('block partners: attributes', has($pt, '--wpd-pt-fs-title:28px') && has($pt, '--wpd-pt-a:50%'));
$w = blk('faq', ['sectionBg' => '#0b1220', 'sectionBgTr' => 20]);
[$c, $st] = root($w, 'wpd-page');
check('block: section background on wrapper', cls_has($c, 'wpd-sec') && cls_has($c, 'wpd-sec--dark') && has($st, '--wpd-sec-bg:rgba(11,18,32,0.8);'), $c . ' | ' . substr($st, -80));
[$c, $st] = root(blk('faq', ['sectionBg' => '#0b1220', 'sectionBgTr' => 95]), 'wpd-page');
check('block: transparent dark section -> light', cls_has($c, 'wpd-sec--light'), $c);
[$c, $st] = root(blk('faq', ['sectionBg' => '#fef3c7']), 'wpd-page');
check('block: opaque section colour -> hex', has($st, '--wpd-sec-bg:#fef3c7;') && cls_has($c, 'wpd-sec--light'));
[$c, $st] = root(blk('faq', ['sectionBg' => 'red', 'sectionBgTr' => 50]), 'wpd-page');
check('block: invalid section colour ignored', !cls_has($c, 'wpd-sec') && !has($st, '--wpd-sec-bg'));
[$c, $st] = root(blk('hero', ['sectionBg' => '#000000']), 'wpd-page');
check('block hero: no section background', !cls_has($c, 'wpd-sec'));
[$c, $st] = root(blk('stats', ['bgTransparency' => 50, 'sectionBg' => '#0b1220']), 'wpd-stats');
check('block: transparent cards on dark section -> light text', cls_has($c, 'wpd-look--ondark'), $c);
[$c, $st] = root(blk('stats', ['bgTransparency' => 50]), 'wpd-stats');
check('block: same without section -> dark text', cls_has($c, 'wpd-look--onlight'), $c);
check('section_under: only with colour, not for hero', !isset(WPD_Blocks::section_under(['bg' => '#ffffff'], [], 'stats')['_under'])
    && WPD_Blocks::section_under(['bg' => '#ffffff'], ['sectionBg' => '#000000', 'sectionBgTr' => 50], 'stats')['_under'] === '#808080'
    && !isset(WPD_Blocks::section_under(['bg' => '#ffffff'], ['sectionBg' => '#000000'], 'hero')['_under']));
check('page_bg: _under wins', WPD_Renderer::page_bg(['bg' => '#ffffff', '_under' => '#101010']) === '#101010');
$sl = WPD_Blocks::section_look(['sectionBg' => '#ffffff', 'sectionBgTr' => 100], 'stats', $D);
check('section_look: 100% -> rgba 0', $sl['style'] === '--wpd-sec-bg:rgba(255,255,255,0);');
check('wrap: extra style appended', has(WPD_Renderer::wrap('<p>x</p>', 'q', $D, '--x:1;'), '--x:1;"'));

/* ---------- 11. CSS, JS, справка ---------- */
$css = (string) file_get_contents($dir . 'assets/css/wpd.css');
check('css: hero transparency and sizes', has($css, '.wpd-hero.wpd-hero--tr:not(.wpd-hero--custom-bg)') && has($css, 'min(var(--wpd-hero-fs-title), 11vw)') && has($css, '.wpd-hero--fs-btn .wpd-hero__actions .wpd-btn'));
check('css: look sizes', has($css, '.wpd-look.wpd-look--fs-item :is(.wpd-faq__q') && has($css, 'var(--wpd-bl-fs-small)') && has($css, ', 10vw)'));
check('css: look transparency', has($css, '.wpd-look.wpd-look--bgtr.wpd-look--solid') && has($css, 'color-mix(in srgb, var(--wpd-bl-base) var(--wpd-bl-bg-a), transparent)')
    && has($css, 'var(--wpd-card, var(--wpd-primary)) var(--wpd-bl-bg-a)') && has($css, 'var(--wpd-notice-bg, #eef2ff) var(--wpd-bl-bg-a)'));
check('css: slider, buttons, partners, section', has($css, '.wpd-slider.wpd-look--bgtr .wpd-slide__caption') && has($css, '.wpd-buttons.wpd-buttons--bgtr .wpd-qbtn')
    && has($css, '.wpd-partners--cards.wpd-partners--bgtr') && has($css, '.wpd-page.wpd-page--block.wpd-sec {') && has($css, '.wpd-page.wpd-page--block.wpd-sec--dark'));
$js = (string) file_get_contents($dir . 'assets/js/wpd-blocks.js');
check('js: helpers mirror renderer', has($js, 'function bgAlpha(') && has($js, 'function alphaColor(') && has($js, 'function blendColor(') && has($js, 'function lookSeenBg(') && has($js, 'function sectionLook('));
check('js: panels', has($js, 'function SectionPanel(') && has($js, "'Размеры текстов'") && has($js, "range('bgTransparency')") && has($js, "ptRange('bgTransparency'")
    && has($js, 'SectionPanel(slug, props.attributes, props.setAttributes)'));
check('js: classes', has($js, "'wpd-look--fs-title'") && has($js, "'wpd-look--bgtr'") && has($js, "'wpd-hero--tr'") && has($js, "'wpd-buttons--bgtr'") && has($js, "'wpd-partners--bgtr'"));
$rh = new ReflectionMethod('WPD_Settings', 'render_help');
$rh->setAccessible(true);
ob_start();
$rh->invoke(null);
$help = (string) ob_get_clean();
check('help: section 2.11.0', has($help, 'Прозрачность фонов и размеры текстов (2.11.0)') && has($help, 'Фон раздела'));
$readme = (string) file_get_contents($dir . 'README.md');
check('README: 2.11.0 section', has($readme, '2.11.0'));

/* ---------- Уборка ---------- */
foreach ($created as $id) { wp_delete_post($id, true); }
if (is_array($backup)) { set_opts($backup); } else { delete_option('wpd_settings'); WPD_Settings::flush_cache(); }
check('cleanup', !get_post($pid));

echo implode("\n", $results) . "\n";
echo "TOTAL: " . count($results) . ", FAILED: $fails\n";
