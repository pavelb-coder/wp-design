<?php
// Фаза T (2.6.0): параметры кнопок-ссылок — поля блока (раскладка, вид и цвета) и кнопок (подпись, метка, картинка,
// цвета, скачивание, скрытие, даты показа), санитизация, разметка рендерера, атрибуты блока (блок важнее страницы,
// страница — общих настроек), группа панели страницы, данные редактора; защита ответов REST от постороннего вывода
// (WPD_Rest_Guard): распознавание запросов REST, буфер, очистка, запоминание, уведомление; CSS, JS, справка, версия.
// В конце удаляет свои записи и опции.
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
/** Открывающий тег nav.wpd-buttons: [классы, style]. */
function nav(string $html): array {
    if (!preg_match('/<nav class="([^"]*)"(?: style="([^"]*)")? aria-label="([^"]*)">/', $html, $m)) {
        return ['', '', ''];
    }
    return [$m[1], html_entity_decode($m[2] ?? ''), $m[3]];
}
function new_page(string $title, string $content = '<p>Текст</p>'): int {
    $id = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => $content]);
    update_post_meta($id, '_wpd_t_test', 1);
    update_post_meta($id, '_wpd_enabled', 1);
    return (int) $id;
}

wp_set_current_user(1);
WPD_Settings::register();
delete_option('wpd_settings');
delete_option(WPD_Rest_Guard::OPTION);
WPD_Settings::flush_cache();
WPD_Settings::set_page(0);
$map  = WPD_Settings::field_map();
$keys = array_keys($map);

/* 1. Поля */
$layout_keys = ['buttons_title', 'buttons_columns', 'buttons_columns_mobile', 'buttons_gap', 'buttons_height', 'buttons_align'];
$look_keys   = ['buttons_layout', 'buttons_variant', 'buttons_radius', 'buttons_text_size', 'buttons_bg', 'buttons_icon_color', 'buttons_text_color', 'buttons_hover_color'];
check('fields: 313 in total', count($map) === 313, (string) count($map));
check('fields: 304 plain + 9 repeaters', count(array_filter($map, function ($f) { return $f['type'] !== 'repeater'; })) === 304);
check('fields: layout section fields', count(array_filter($layout_keys, function ($k) use ($map) { return isset($map[$k]) && $map[$k]['section'] === 'buttons_layout'; })) === 6);
check('fields: look section fields', count(array_filter($look_keys, function ($k) use ($map) { return isset($map[$k]) && $map[$k]['section'] === 'buttons_look'; })) === 8);
$pos = array_flip($keys);
check('fields: new fields right after icon size', $pos['buttons_title'] === $pos['buttons_icon_size'] + 1 && $pos['buttons_hover_color'] === $pos['buttons_icon_size'] + 17);
check('fields: defaults keep old look', $map['buttons_layout']['default'] === 'stack' && $map['buttons_variant']['default'] === 'card'
    && $map['buttons_columns']['default'] === '' && $map['buttons_radius']['default'] === '' && $map['buttons_gap']['default'] === 0
    && $map['buttons_text_size']['default'] === 0 && $map['buttons_bg']['default'] === '' && $map['buttons_title']['default'] === '');
check('fields: layout options', array_keys($map['buttons_layout']['options']) === ['stack', 'row', 'compact']);
check('fields: variant options', array_keys($map['buttons_variant']['options']) === ['card', 'outline', 'solid', 'tint']);
check('fields: radius options', array_keys($map['buttons_radius']['options']) === ['', 'none', 'small', 'medium', 'large', 'pill']);
check('fields: columns options', array_map('strval', array_keys($map['buttons_columns']['options'])) === ['', '2', '3', '4', '5', '6']);
check('fields: mobile columns options', array_map('strval', array_keys($map['buttons_columns_mobile']['options'])) === ['', '1', '2', '3']);
check('fields: align options', array_keys($map['buttons_align']['options']) === ['', 'center', 'left']);
check('fields: number bounds', $map['buttons_gap']['max'] === 48 && $map['buttons_height']['max'] === 320 && $map['buttons_text_size']['max'] === 28);
check('fields: colors are optional colors', $map['buttons_bg']['type'] === 'color_opt' && $map['buttons_hover_color']['type'] === 'color_opt');
$sub = array_keys($map['buttons']['fields']);
check('repeater: new sub-fields', $sub === ['icon', 'text', 'url', 'new_tab', 'small', 'desc', 'badge', 'image', 'color', 'icon_color', 'download', 'hidden', 'from', 'to'], implode(',', $sub));
check('repeater: dates are datetime', $map['buttons']['fields']['from']['type'] === 'datetime' && $map['buttons']['fields']['to']['type'] === 'datetime');
$sections = WPD_Settings::sections();
check('sections: layout and look', ($sections['buttons_layout'] ?? '') === 'Раскладка' && ($sections['buttons_look'] ?? '') === 'Вид и цвета');
check('tabs: buttons tab has three sections', WPD_Settings::tabs()['buttons']['sections'] === ['buttons', 'buttons_layout', 'buttons_look']);

/* 2. Санитизация */
$out = save_tab('buttons', [
    'buttons_title' => ' <b>Полезные</b> ссылки ', 'buttons_columns' => '4', 'buttons_columns_mobile' => '9', 'buttons_gap' => '100',
    'buttons_height' => '-5', 'buttons_align' => 'right', 'buttons_layout' => 'row', 'buttons_variant' => 'neon', 'buttons_radius' => 'pill',
    'buttons_text_size' => '18', 'buttons_bg' => '#0F172A', 'buttons_icon_color' => 'red', 'buttons_text_color' => '#fff', 'buttons_hover_color' => '',
    'buttons' => [
        ['icon' => 'admin-home', 'text' => 'Положение', 'url' => 'https://example.com/a.pdf', 'desc' => ' Подпись <i>x</i> ', 'badge' => 'Новое',
         'image' => 'javascript:alert(1)', 'color' => '#112233', 'icon_color' => 'blue', 'download' => '1', 'hidden' => '', 'from' => '2030-01-02T09:30', 'to' => 'когда-нибудь'],
        ['icon' => 'admin-home', 'text' => '', 'url' => '', 'from' => '2030-01-01T00:00', 'to' => '2030-02-01T00:00'],
        ['text' => 'Скрытая', 'hidden' => '1', 'image' => 'https://example.com/i.png'],
    ],
]);
check('sanitize: title stripped and trimmed', $out['buttons_title'] === 'Полезные ссылки', $out['buttons_title']);
check('sanitize: columns 4 kept, mobile 9 -> auto', $out['buttons_columns'] === '4' && $out['buttons_columns_mobile'] === '');
check('sanitize: gap clamped to 48, height to 0', (int) $out['buttons_gap'] === 48 && (int) $out['buttons_height'] === 0);
check('sanitize: invalid selects -> defaults', $out['buttons_align'] === '' && $out['buttons_variant'] === 'card');
check('sanitize: valid selects kept', $out['buttons_layout'] === 'row' && $out['buttons_radius'] === 'pill' && (int) $out['buttons_text_size'] === 18);
check('sanitize: colors', strtolower($out['buttons_bg']) === '#0f172a' && $out['buttons_icon_color'] === '' && $out['buttons_text_color'] === '#fff' && $out['buttons_hover_color'] === '');
check('sanitize: row with only dates dropped', count($out['buttons']) === 2, (string) count($out['buttons']));
$r0 = $out['buttons'][0];
check('sanitize: desc and badge', $r0['desc'] === 'Подпись x' && $r0['badge'] === 'Новое', $r0['desc']);
check('sanitize: bad image url dropped', $r0['image'] === '');
check('sanitize: item colors', $r0['color'] === '#112233' && $r0['icon_color'] === '');
check('sanitize: flags', (int) $r0['download'] === 1 && (int) $r0['hidden'] === 0 && (int) $out['buttons'][1]['hidden'] === 1);
check('sanitize: datetime normalized, junk dropped', $r0['from'] === '2030-01-02 09:30' && $r0['to'] === '', $r0['from'] . '|' . $r0['to']);
check('sanitize: image url kept', $out['buttons'][1]['image'] === 'https://example.com/i.png');
$rows = WPD_Settings::sanitize_rows([['text' => 'A']], 'buttons');
check('sanitize_rows: old rows get new keys with empty values', $rows[0]['desc'] === '' && $rows[0]['from'] === '' && (int) $rows[0]['download'] === 0 && $rows[0]['color'] === '');
$o2 = save_tab('hero', ['hero_title' => 'Другая вкладка']);
check('sanitize: other tab keeps button options', $o2['buttons_layout'] === 'row' && $o2['buttons_title'] === 'Полезные ссылки' && count($o2['buttons']) === 2);

/* 3. Разметка: по умолчанию как раньше */
delete_option('wpd_settings');
WPD_Settings::flush_cache();
$o = WPD_Settings::get();
$items = [['text' => 'Положение', 'url' => 'https://example.com/a', 'icon' => 'media-document'], ['text' => 'Чат', 'url' => '', 'icon' => 'format-chat']];
$h = WPD_Renderer::buttons_html($o, $items);
check('render default: plain nav without modifiers', has($h, '<nav class="wpd-buttons" aria-label="Быстрые ссылки"><ul class="wpd-buttons__grid">'), substr($h, 0, 160));
check('render default: no title', !has($h, 'wpd-h2'));
check('render default: text in body', has($h, '<span class="wpd-qbtn__body"><span class="wpd-qbtn__text">Положение</span></span>'));
check('render default: icon kept', has($h, 'dashicons-media-document') && has($h, 'dashicons-format-chat'));
check('render default: no-link item as span', has($h, '<span class="wpd-qbtn wpd-qbtn--nolink">'));
check('render default: no badge, desc, download', !has($h, '__badge') && !has($h, '__desc') && !has($h, ' download'));
check('render: empty list -> nothing', WPD_Renderer::buttons_html($o, []) === '');

/* 4. Разметка: параметры блока */
$look = function (array $over) use ($o) { return WPD_Renderer::buttons_look(array_merge($o, $over)); };
$l = $look(['buttons_layout' => 'row', 'buttons_variant' => 'outline']);
check('look: row + outline classes', $l['class'] === 'wpd-buttons wpd-buttons--row wpd-buttons--outline' && $l['style'] === '', $l['class']);
check('look: compact + solid + tint', $look(['buttons_layout' => 'compact'])['class'] === 'wpd-buttons wpd-buttons--compact'
    && $look(['buttons_variant' => 'solid'])['class'] === 'wpd-buttons wpd-buttons--solid' && $look(['buttons_variant' => 'tint'])['class'] === 'wpd-buttons wpd-buttons--tint');
$l = $look(['buttons_columns' => '3', 'buttons_columns_mobile' => '1']);
check('look: columns class and vars', has($l['class'], 'wpd-buttons--cols wpd-buttons--cols-m') && $l['style'] === '--wpd-qb-cols:3;--wpd-qb-cols-m:1;', $l['style']);
check('look: align', has($look(['buttons_align' => 'center'])['class'], 'wpd-buttons--align-center') && has($look(['buttons_align' => 'left'])['class'], 'wpd-buttons--align-left'));
$radii = [];
foreach (['none', 'small', 'medium', 'large', 'pill'] as $r) { $radii[] = $look(['buttons_radius' => $r])['style']; }
check('look: radius map', $radii === ['--wpd-qb-radius:0px;', '--wpd-qb-radius:6px;', '--wpd-qb-radius:12px;', '--wpd-qb-radius:20px;', '--wpd-qb-radius:999px;'], implode(' ', $radii));
check('look: gap and height', $look(['buttons_gap' => 20, 'buttons_height' => 90])['style'] === '--wpd-qb-gap:20px;--wpd-qb-h:90px;');
check('look: gap over max clamped', $look(['buttons_gap' => 500])['style'] === '--wpd-qb-gap:48px;');
$l = $look(['buttons_text_size' => 20]);
check('look: text size + small 85%', has($l['class'], 'wpd-buttons--fs') && $l['style'] === '--wpd-qb-fs:20px;--wpd-qb-fs-small:17px;', $l['style']);
check('look: text size at least 11', $look(['buttons_text_size' => 5])['style'] === '--wpd-qb-fs:11px;--wpd-qb-fs-small:11px;');
$l = $look(['buttons_bg' => '#0b1220', 'buttons_icon_color' => '#f97316', 'buttons_text_color' => '#ffffff', 'buttons_hover_color' => '#fde68a']);
check('look: dark bg class and color vars', has($l['class'], 'wpd-buttons--dark') && $l['style'] === '--wpd-qb-bg:#0b1220;--wpd-qb-icon:#f97316;--wpd-qb-text:#ffffff;--wpd-qb-hover:#fde68a;', $l['style']);
check('look: light bg not dark', !has($look(['buttons_bg' => '#fef3c7'])['class'], '--dark'));
check('look: invalid values ignored', $look(['buttons_layout' => 'grid', 'buttons_columns' => '9', 'buttons_bg' => 'red', 'buttons_radius' => 'huge'])['class'] === 'wpd-buttons'
    && $look(['buttons_bg' => 'red'])['style'] === '');
check('is_dark', WPD_Renderer::is_dark('#000') && WPD_Renderer::is_dark('#1d4ed8') && !WPD_Renderer::is_dark('#ffffff') && !WPD_Renderer::is_dark('#fde68a') && !WPD_Renderer::is_dark('bad'));
$h = WPD_Renderer::buttons_html(array_merge($o, ['buttons_layout' => 'compact', 'buttons_radius' => 'small', 'buttons_title' => 'Ссылки & документы']), $items);
[$cls, $st, $aria] = nav($h);
check('render: nav class and style from look', $cls === 'wpd-buttons wpd-buttons--compact' && $st === '--wpd-qb-radius:6px;', $cls . '|' . $st);
check('render: title as h2 and aria-label', has($h, '<h2 class="wpd-h2">Ссылки &amp; документы</h2><ul') && $aria === 'Ссылки &amp; документы', $aria);
check('render: explicit empty title beats settings', !has(WPD_Renderer::buttons_html(array_merge($o, ['buttons_title' => 'X']), $items, ''), 'wpd-h2'));

/* 5. Разметка: параметры кнопки */
$h = WPD_Renderer::buttons_html($o, [
    ['text' => 'Файл', 'url' => 'https://example.com/f.pdf', 'icon' => 'download', 'desc' => 'PDF, 2 МБ', 'badge' => 'Новое', 'download' => 1, 'new_tab' => 1],
    ['text' => 'Фото', 'url' => 'https://example.com/p', 'image' => 'https://example.com/i.png', 'color' => '#0b1220', 'icon_color' => '#f97316'],
    ['text' => 'Светлая', 'url' => '', 'color' => '#fef3c7'],
]);
check('item: desc in body', has($h, '<span class="wpd-qbtn__text">Файл</span><span class="wpd-qbtn__desc">PDF, 2 МБ</span></span>'));
check('item: badge after body', has($h, '</span><span class="wpd-qbtn__badge">Новое</span></a>'));
check('item: badge class', has($h, '<a class="wpd-qbtn wpd-qbtn--badge"'));
check('item: download attribute with new tab', (bool) preg_match('/href="https:\/\/example\.com\/f\.pdf"[^>]*target="_blank"[^>]*download>/', $h));
check('item: image replaces icon', has($h, '<img class="wpd-qbtn__img" src="https://example.com/i.png" alt="" loading="lazy">') && substr_count($h, 'wpd-qbtn__icon') === 2);
check('item: dark color classes and vars', has($h, 'class="wpd-qbtn has-color wpd-qbtn--dark has-icon-color wpd-qbtn--img" style="--wpd-qb-bg:#0b1220;--wpd-qb-icon:#f97316;"'));
check('item: light color', has($h, '<span class="wpd-qbtn wpd-qbtn--nolink has-color wpd-qbtn--light" style="--wpd-qb-bg:#fef3c7;">'));
check('item: no download on other links', substr_count($h, ' download') === 1);
$il = WPD_Renderer::button_item_look(['color' => 'red', 'icon_color' => 'x']);
check('item look: invalid colors ignored', $il['class'] === '' && $il['style'] === '');

/* 6. Скрытие и даты показа */
$sched = [
    ['text' => 'Всегда', 'url' => 'https://example.com/1'],
    ['text' => 'Скрыта', 'url' => 'https://example.com/2', 'hidden' => 1],
    ['text' => 'С 10 марта', 'url' => 'https://example.com/3', 'from' => '2030-03-10 10:00'],
    ['text' => 'До 10 марта', 'url' => 'https://example.com/4', 'to' => '2030-03-10 10:00'],
    ['text' => 'Окно', 'url' => 'https://example.com/5', 'from' => '2030-03-01 00:00', 'to' => '2030-03-20 00:00'],
];
$at = function (string $now) use ($o, $sched) {
    $f = function () use ($now) { return $now; };
    add_filter('wpd_buttons_now', $f);
    $h = WPD_Renderer::buttons_html($o, $sched);
    remove_filter('wpd_buttons_now', $f);
    preg_match_all('/<span class="wpd-qbtn__text">([^<]*)</', $h, $m);
    return implode(',', $m[1]);
};
check('dates: before window', $at('2030-02-01 12:00') === 'Всегда,До 10 марта', $at('2030-02-01 12:00'));
check('dates: inside window, before from', $at('2030-03-10 09:59') === 'Всегда,До 10 марта,Окно', $at('2030-03-10 09:59'));
check('dates: from inclusive, to exclusive', $at('2030-03-10 10:00') === 'Всегда,С 10 марта,Окно', $at('2030-03-10 10:00'));
check('dates: after window', $at('2030-04-01 00:00') === 'Всегда,С 10 марта', $at('2030-04-01 00:00'));
check('dates: hidden never on site', !has($at('2030-03-05 00:00'), 'Скрыта'));
check('visible(): unit', WPD_Renderer::button_visible(['text' => 'a'], '2030-01-01 00:00') && !WPD_Renderer::button_visible(['hidden' => 1], '2030-01-01 00:00')
    && !WPD_Renderer::button_visible(['to' => '2029-12-31 23:59'], '2030-01-01 00:00') && WPD_Renderer::button_visible(['from' => '2029-12-31 23:59'], '2030-01-01 00:00'));
$f = function () { return '2030-02-01 12:00'; };
add_filter('wpd_buttons_now', $f);
$ed = WPD_Renderer::buttons_html($o, $sched, null, true);
check('editor mode: all items, off ones dimmed', substr_count($ed, '<li>') === 5 && substr_count($ed, 'wpd-qbtn--off') === 3);
check('all hidden -> nothing on site', WPD_Renderer::buttons_html($o, [['text' => 'x', 'url' => '', 'hidden' => 1]]) === '');
remove_filter('wpd_buttons_now', $f);
$tz = WPD_Settings::countdown_timezone($o);
$now = WPD_Renderer::buttons_now($o);
check('now: timer timezone, minute precision', (bool) preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d$/', $now) && $now === (new DateTime('now', $tz))->format('Y-m-d H:i'), $now);

/* 7. Блок: атрибуты важнее страницы, страница — общих */
set_opts(array_merge(WPD_Settings::defaults(), ['buttons_layout' => 'row', 'buttons_bg' => '#fef3c7', 'buttons_title' => 'Из настроек', 'buttons_gap' => 20,
    'buttons' => [['icon' => 'admin-home', 'text' => 'Общая', 'url' => 'https://example.com/g', 'new_tab' => 0, 'small' => 0]]]));
$b = blk('buttons');
[$cls, $st] = nav($b);
check('block: settings apply without attributes', $cls === 'wpd-buttons wpd-buttons--row' && $st === '--wpd-qb-gap:20px;--wpd-qb-bg:#fef3c7;' && has($b, '>Из настроек</h2>') && has($b, '>Общая<'), $cls . '|' . $st);
$b = blk('buttons', ['layout' => 'compact', 'variant' => 'tint', 'columns' => '3', 'columnsMobile' => '1', 'textAlign' => 'center', 'radius' => 'large',
    'gap' => 0, 'height' => 80, 'textSize' => 15, 'bgColor' => '', 'iconColor' => '#15803d', 'title' => 'Свой заголовок', 'items' => [['text' => 'Своя', 'url' => 'https://example.com/s', 'badge' => '1']]]);
[$cls, $st] = nav($b);
check('block: attributes override classes', $cls === 'wpd-buttons wpd-buttons--compact wpd-buttons--tint wpd-buttons--cols wpd-buttons--cols-m wpd-buttons--align-center wpd-buttons--fs', $cls);
check('block: gap 0 -> settings, empty bg -> none', $st === '--wpd-qb-cols:3;--wpd-qb-cols-m:1;--wpd-qb-radius:20px;--wpd-qb-gap:20px;--wpd-qb-h:80px;--wpd-qb-fs:15px;--wpd-qb-fs-small:13px;--wpd-qb-icon:#15803d;', $st);
check('block: own title and items', has($b, '>Свой заголовок</h2>') && has($b, '>Своя<') && !has($b, '>Общая<') && has($b, 'wpd-qbtn__badge'));
check('block: empty title attribute -> no title', !has(blk('buttons', ['title' => '']), 'wpd-h2'));
check('block: title attribute sanitized', has(blk('buttons', ['title' => '<script>x</script>Т']), '<h2 class="wpd-h2">Т</h2>') || has(blk('buttons', ['title' => '<script>x</script>Т']), '<h2 class="wpd-h2">xТ</h2>'));
check('block: number over max clamped', has(nav(blk('buttons', ['height' => 9999]))[1], '--wpd-qb-h:320px;'));
check('block: invalid select attr -> default', nav(blk('buttons', ['layout' => 'bogus']))[0] === 'wpd-buttons');
check('block: icon size still works', has(blk('buttons', ['iconSize' => 60]), '--wpd-icon-qbtn:60px'));
$wo = WPD_Blocks::with_buttons_attrs(WPD_Settings::get(), ['gap' => '-3', 'columns' => '4', 'bgColor' => '#000', 'unknown' => 'x', 'title' => ['bad']]);
check('with_buttons_attrs: unit', (int) $wo['buttons_gap'] === 20 && $wo['buttons_columns'] === '4' && $wo['buttons_bg'] === '#000' && $wo['buttons_title'] === 'Из настроек' && !isset($wo['unknown']));

$pid = new_page('Кнопки T');
$ov = ['buttons_layout' => 'compact', 'buttons_variant' => 'solid', 'buttons_title' => 'Для страницы', 'buttons_gap' => 30];
WPD_Page_Editor::store($pid, WPD_Page_Editor::sanitize(['order' => array_keys(WPD_Page_Editor::blocks()), 'show' => ['content' => 1],
    'ovg' => ['buttons' => 1], 'ov' => $ov], $pid));
WPD_Settings::set_page($pid);
$po = WPD_Settings::get();
check('page: group overrides saved', $po['buttons_layout'] === 'compact' && $po['buttons_variant'] === 'solid' && $po['buttons_title'] === 'Для страницы' && (int) $po['buttons_gap'] === 30,
    $po['buttons_layout'] . '|' . $po['buttons_variant']);
check('page: other group fields from settings', $po['buttons_bg'] === '' || $po['buttons_bg'] === '#fef3c7');
[$cls, $st] = nav(blk('buttons'));
check('page: block without attributes uses page', $cls === 'wpd-buttons wpd-buttons--compact wpd-buttons--solid' && has($st, '--wpd-qb-gap:30px;'), $cls . '|' . $st);
[$cls, $st] = nav(blk('buttons', ['layout' => 'row', 'gap' => 8]));
check('page: block attribute beats page', $cls === 'wpd-buttons wpd-buttons--row wpd-buttons--solid' && has($st, '--wpd-qb-gap:8px;'), $cls . '|' . $st);
check('page: editor data follows page', WPD_Blocks::buttons_settings_data()['layout'] === 'compact' && WPD_Blocks::buttons_settings_data()['gap'] === 30);
WPD_Settings::set_page(0);
check('page: back to global', WPD_Blocks::buttons_settings_data()['layout'] === 'row');
$gk = WPD_Page_Editor::group_keys('buttons');
check('page group: has all new keys', !array_diff(array_merge($layout_keys, $look_keys, ['buttons', 'buttons_icon_size']), $gk), implode(',', array_diff(array_merge($layout_keys, $look_keys), $gk)));
check('page group: description mentions look', has(WPD_Page_Editor::groups()['buttons']['desc'], 'колонки'));

/* 8. Данные редактора */
$sd = WPD_Blocks::buttons_settings_data();
check('editor data: settings map', array_keys($sd) === array_keys(WPD_Blocks::BUTTONS_ATTRS) && $sd['title'] === 'Из настроек' && $sd['gap'] === 20 && $sd['bgColor'] === '#fef3c7');
$od = WPD_Blocks::buttons_options_data();
check('editor data: select options and bounds', isset($od['layout']['options']['compact']) && $od['gap']['max'] === 48 && $od['textSize']['max'] === 28 && $od['bgColor']['label'] === 'Фон плиток');
$reg = WP_Block_Type_Registry::get_instance()->get_registered('wpd/buttons');
$attrs = $reg ? $reg->attributes : [];
check('block type: new attributes registered', $reg && !array_diff(array_keys(WPD_Blocks::BUTTONS_ATTRS), array_keys($attrs)));
check('block type: no server defaults for new attributes', $reg && !array_filter(array_keys(WPD_Blocks::BUTTONS_ATTRS), function ($k) use ($attrs) { return array_key_exists('default', $attrs[$k]); }));
check('block type: types', $reg && $attrs['gap']['type'] === 'integer' && $attrs['layout']['type'] === 'string' && $attrs['bgColor']['type'] === 'string');
$defs = WPD_Blocks::definitions()['buttons']['attributes'];
check('block definitions: client defaults = settings', $defs['layout'] === ['type' => 'string', 'default' => 'row'] && $defs['gap'] === ['type' => 'integer', 'default' => 20]
    && $defs['bgColor']['default'] === '#fef3c7' && $defs['title']['default'] === 'Из настроек');
$src = file_get_contents(WPD_DIR . 'includes/class-wpd-blocks.php');
check('editor script data: buttonsOptions and buttonsSettings', substr_count($src, "'buttonsSettings' => self::buttons_settings_data(\$o)") === 2 && has($src, "'buttonsOptions'  => self::buttons_options_data()"));

/* 9. Вкладка настроек и мета-бокс */
ob_start();
$_GET = ['page' => 'wpd-settings', 'tab' => 'buttons'];
WPD_Settings::render_page();
$tab = ob_get_clean();
$_GET = [];
check('tab: section headings in order', (bool) preg_match('/<h2>Кнопки-ссылки<\/h2>.*<h2>Раскладка<\/h2>.*<h2>Вид и цвета<\/h2>/s', $tab));
foreach (array_merge($layout_keys, $look_keys) as $k) {
    if (!has($tab, 'name="wpd_settings[' . $k . ']"')) { check("tab: field $k", false); }
}
check('tab: all 14 fields present', count(array_filter(array_merge($layout_keys, $look_keys), function ($k) use ($tab) { return has($tab, 'name="wpd_settings[' . $k . ']"'); })) === 14);
check('tab: datetime inputs in repeater', (bool) preg_match('/<input type="datetime-local"[^>]*name="wpd_settings\[buttons\]\[\d+\]\[from\]"/', $tab) || (bool) preg_match('/type="datetime-local"[^>]*name="wpd_settings\[buttons\]\[__i__\]\[to\]"/', $tab));
check('tab: repeater has new sub-fields', has($tab, '[desc]') && has($tab, '[badge]') && has($tab, '[icon_color]') && has($tab, '[download]') && has($tab, '[hidden]'));
set_opts(array_merge(WPD_Settings::defaults(), ['buttons' => [['text' => 'Д', 'url' => '', 'from' => '2030-01-02 09:30']]]));
ob_start();
$_GET = ['page' => 'wpd-settings', 'tab' => 'buttons'];
WPD_Settings::render_page();
$tab = ob_get_clean();
$_GET = [];
check('tab: stored date shown with T', has($tab, 'value="2030-01-02T09:30"'));
ob_start();
WPD_Page_Editor::meta_box_html(get_post($pid));
$mb = ob_get_clean();
check('metabox: page group has new fields', has($mb, 'id="wpd_pe_ov_buttons_layout"') && has($mb, 'id="wpd_pe_ov_buttons_bg"') && has($mb, 'id="wpd_pe_ov_buttons_columns_mobile"'));
check('metabox: saved page value shown', (bool) preg_match('/name="wpd_page\[ov\]\[buttons_title\]"[^>]*value="Для страницы"|value="Для страницы"[^>]*name="wpd_page\[ov\]\[buttons_title\]"/', $mb));

/* 10. Защита ответов REST */
$srv = $_SERVER['REQUEST_URI'] ?? null;
$get = $_GET;
$_GET = [];
$_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/pages/5';
check('guard: /wp-json/ is REST', WPD_Rest_Guard::is_rest_request());
$_SERVER['REQUEST_URI'] = '/sub/wp-json/wpd/v1/preview?x=1';
check('guard: subdirectory /wp-json/', WPD_Rest_Guard::is_rest_request());
$_SERVER['REQUEST_URI'] = '/wp-jsonx/page';
check('guard: similar path is not REST', !WPD_Rest_Guard::is_rest_request());
$_SERVER['REQUEST_URI'] = '/sample-page/';
check('guard: page is not REST', !WPD_Rest_Guard::is_rest_request());
$_GET = ['rest_route' => '/wp/v2/pages'];
check('guard: ?rest_route=', WPD_Rest_Guard::is_rest_request());
$_GET = $get;
if ($srv === null) { unset($_SERVER['REQUEST_URI']); } else { $_SERVER['REQUEST_URI'] = $srv; }
check('guard: enabled by default', WPD_Rest_Guard::enabled());
check('guard: not active outside REST', !WPD_Rest_Guard::is_active());
check('guard: clean() without buffer passes through', WPD_Rest_Guard::clean('served-x') === 'served-x');

delete_option(WPD_Rest_Guard::OPTION);
$display = ini_get('display_errors');
$level = ob_get_level();
WPD_Rest_Guard::start();
check('guard: start() opens buffer and filter', WPD_Rest_Guard::is_active() && ob_get_level() === $level + 1 && has_filter('rest_pre_serve_request', ['WPD_Rest_Guard', 'clean']) === -1000);
WPD_Rest_Guard::start();
check('guard: start() twice -> one buffer', ob_get_level() === $level + 1);
echo "<br />\n<b>Warning</b>:  Undefined variable \$x in <b>/wp-content/themes/t/functions.php</b> on line <b>7</b><br />\n";
ob_start(); // незакрытый чужой буфер внутри нашего
echo 'inner junk';
$req = new WP_REST_Request('POST', '/wp/v2/pages/5');
$ret = WPD_Rest_Guard::clean(false, null, $req, null);
check('guard: clean() returns served unchanged', $ret === false);
check('guard: buffers closed back to start level', ob_get_level() === $level, ob_get_level() . ' vs ' . $level);
check('guard: not active after clean', !WPD_Rest_Guard::is_active());
check('guard: display_errors off after clean', in_array(strtolower((string) ini_get('display_errors')), ['0', '', 'off'], true), (string) ini_get('display_errors'));
@ini_set('display_errors', (string) $display);
$st = get_option(WPD_Rest_Guard::OPTION);
check('guard: stray output remembered', is_array($st) && has($st['text'], 'Undefined variable') && has($st['text'], 'inner junk'));
check('guard: order kept (outer before inner)', is_array($st) && strpos($st['text'], 'Warning') < strpos($st['text'], 'inner junk'));
check('guard: route and count', is_array($st) && $st['route'] === 'POST /wp/v2/pages/5' && (int) $st['count'] === 1 && abs(time() - (int) $st['time']) < 30);
check('guard: option not autoloaded', (function () { global $wpdb; $v = $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", WPD_Rest_Guard::OPTION)); return in_array($v, ['no', 'off', 'auto-off'], true); })());
WPD_Rest_Guard::remember((string) $st['text']);
check('guard: same text within 5 min not rewritten', (int) get_option(WPD_Rest_Guard::OPTION)['count'] === 1);
$old = get_option(WPD_Rest_Guard::OPTION);
$old['time'] = time() - 600;
update_option(WPD_Rest_Guard::OPTION, $old, false);
WPD_Rest_Guard::remember((string) $st['text']);
check('guard: same text later -> count 2', (int) get_option(WPD_Rest_Guard::OPTION)['count'] === 2);
WPD_Rest_Guard::remember(str_repeat('я', 5000));
$long = get_option(WPD_Rest_Guard::OPTION);
check('guard: long text cut to MAX_LEN chars', mb_strlen($long['text'], 'UTF-8') === WPD_Rest_Guard::MAX_LEN && (int) $long['count'] === 1 && $long['route'] === '');
WPD_Rest_Guard::start();
echo "   \n\t";
WPD_Rest_Guard::clean(true);
@ini_set('display_errors', (string) $display);
check('guard: whitespace-only output not remembered', get_option(WPD_Rest_Guard::OPTION)['text'] === $long['text']);
$rd = WPD_Rest_Guard::readable("<br />\n<b>Warning</b>:  x &amp; y in <b>/a.php</b><br />\n\n\n\nend");
check('guard: readable() strips tags and entities', $rd === "Warning:  x & y in /a.php\n\nend", json_encode($rd));

WPD_Rest_Guard::remember("<b>Notice</b>: test <script>alert(1)</script>", new WP_REST_Request('PUT', '/wp/v2/pages/9'));
ob_start();
WPD_Rest_Guard::notice();
$n = ob_get_clean();
check('notice: shown to admin', has($n, 'wpd-rest-stray-notice') && has($n, 'Ответ не является допустимым ответом JSON'));
check('notice: route and readable text', has($n, '<code>PUT /wp/v2/pages/9</code>') && has($n, '>Notice: test</pre>') && !has($n, 'alert') && !has($n, '<script>'));
check('notice: dismiss link with nonce', (bool) preg_match('/admin-post\.php\?action=wpd_rest_stray_dismiss&(amp;|#038;)_wpnonce=[0-9a-f]+/', $n));
$sub_id = wp_insert_user(['user_login' => 'wpd_t_sub', 'user_pass' => wp_generate_password(), 'role' => 'subscriber', 'user_email' => 't-sub@example.com']);
wp_set_current_user($sub_id);
ob_start();
WPD_Rest_Guard::notice();
check('notice: hidden from subscriber', ob_get_clean() === '');
wp_set_current_user(1);
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user($sub_id);
delete_option(WPD_Rest_Guard::OPTION);
ob_start();
WPD_Rest_Guard::notice();
check('notice: nothing without stored output', ob_get_clean() === '');
check('guard: hooks registered', has_action('admin_notices', ['WPD_Rest_Guard', 'notice']) !== false && has_action('admin_post_wpd_rest_stray_dismiss', ['WPD_Rest_Guard', 'dismiss']) !== false);
$main = file_get_contents(WPD_DIR . 'wp-olympiad-design.php');
check('guard: loaded first in main file', strpos($main, "class-wpd-rest-guard.php") !== false && strpos($main, "class-wpd-rest-guard.php") < strpos($main, "class-wpd-settings.php"));
check('uninstall: deletes stray option', has(file_get_contents(WPD_DIR . 'uninstall.php'), "delete_option('wpd_rest_stray')"));

/* 11. CSS и JS */
$css = file_get_contents(WPD_DIR . 'assets/css/wpd.css');
check('css: base uses vars with old fallbacks', has($css, 'min-height: var(--wpd-qb-h, 116px);') && has($css, 'background: var(--wpd-qb-bg, #fff);')
    && has($css, 'gap: var(--wpd-qb-gap, 12px);') && has($css, 'color: var(--wpd-qb-icon, var(--wpd-primary));') && has($css, 'font-size: var(--wpd-qb-fs-small, 13px);'));
check('css: soft style keeps gradient as fallback', has($css, 'background: var(--wpd-qb-bg, linear-gradient(135deg, #fff 0%, #fdfcff 70%, #e8e2ff 100%));') && has($css, 'color: var(--wpd-qb-text, #7f82a0);'));
foreach (['--row', '--compact', '--outline', '--solid', '--tint', '--cols', '--cols-m', '--align-center', '--align-left', '--dark', '--fs'] as $mod) {
    if (!has($css, '.wpd-buttons' . $mod . ' ')) { check("css: modifier $mod", false); }
}
check('css: all block modifiers styled', count(array_filter(['--row', '--compact', '--outline', '--solid', '--tint', '--cols', '--cols-m', '--align-center', '--align-left', '--dark', '--fs'], function ($m) use ($css) { return has($css, '.wpd-buttons' . $m . ' '); })) === 11);
check('css: item parts styled', has($css, '.wpd-qbtn__badge {') && has($css, '.wpd-qbtn__desc {') && has($css, '.wpd-qbtn__img {') && has($css, '.wpd-qbtn__body {') && has($css, '.wpd-qbtn--dark') && has($css, '.wpd-qbtn--light'));
check('css: mobile columns in media query', (bool) preg_match('/@media \(max-width: 600px\) \{[^@]*wpd-buttons--cols-m \.wpd-buttons__grid \{\s*grid-template-columns: repeat\(var\(--wpd-qb-cols-m\)/s', $css));
check('css: soft arrow hidden for badge', has($css, '.wpd-page.wpd-style-soft a.wpd-qbtn--badge::after'));
check('css: no fixed icon sizes left', !preg_match('/(qbtn__icon|contacts__icon|btn__icon|doc__icon)[^{]*\{[^}]*(width|font-size):\s*\d+px/', $css));
$ecss = file_get_contents(WPD_DIR . 'assets/css/wpd-editor.css');
check('editor css: off and scheduled', has($ecss, '.wpd-qbtn--off') && has($ecss, '.wpd-qbtn--scheduled') && has($ecss, '.wpd-ed-state'));
$js = file_get_contents(WPD_DIR . 'assets/js/wpd-blocks.js');
check('blocks js: look helpers mirror PHP', has($js, 'function buttonsLook(a)') && has($js, 'function buttonItemLook(it)') && has($js, 'function buttonsValue(a, attr)') && has($js, 'function isDark(hexv)'));
check('blocks js: same radius map as PHP', has($js, "{ none: '0px', small: '6px', medium: '12px', large: '20px', pill: '999px' }"));
check('blocks js: inspector panels', has($js, "title: 'Раскладка', initialOpen: false, className: 'wpd-ed-buttons-panel-layout'") && has($js, "title: 'Вид и цвета', initialOpen: false, className: 'wpd-ed-buttons-panel-look'"));
check('blocks js: item fields', has($js, "'скачивать файл'") && has($js, "'скрыть кнопку'") && has($js, "type: 'datetime-local'") && has($js, 'wpd-ed-badge'));
check('blocks js: new item has all keys', has($js, "desc: '', badge: '', image: '', color: '', icon_color: '', download: 0, hidden: 0, from: '', to: ''"));

/* 12. Справка, версия */
ob_start();
$_GET = ['page' => 'wpd-settings', 'tab' => 'help'];
WPD_Settings::render_page();
$help = ob_get_clean();
$_GET = [];
check('help: buttons section', has($help, 'Кнопки-ссылки (2.6.0)') && has($help, 'даты показа') && has($help, 'Значения блока важнее значений страницы'));
check('help: JSON guard section', has($help, 'Ответ не является допустимым ответом JSON') && has($help, 'WPD_REST_GUARD'));
check('readme: buttons and guard', has(file_get_contents(WPD_DIR . 'README.md'), 'Кнопки-ссылки: раскладка, вид и параметры кнопок (2.6.0)') && has(file_get_contents(WPD_DIR . 'README.md'), 'защита ответов REST'));
check('version 2.6.0+', version_compare(WPD_VERSION, '2.6.0', '>='));
check('version: header matches constant', has($main, 'Version:           ' . WPD_VERSION));

/* Уборка */
foreach (get_posts(['post_type' => 'page', 'meta_key' => '_wpd_t_test', 'posts_per_page' => -1, 'post_status' => 'any']) as $p) {
    wp_delete_post($p->ID, true);
}
delete_option('wpd_settings');
delete_option(WPD_Rest_Guard::OPTION);
echo implode("\n", $results), "\n\nTOTAL: ", count($results), ", FAILED: ", $fails, "\n";
