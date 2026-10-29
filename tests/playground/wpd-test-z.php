<?php
// Фаза Z (2.12.0): кнопка таймера. Поля раздела «Кнопка таймера» (кнопки до начала и после окончания, иконка, поведение,
// вид и цвета), санитизация; разметка по умолчанию не меняется; кнопки этапов и их видимость, обёртка и подпись,
// иконка и её положение, download, nofollow, подсказка; классы и CSS-переменные вида; атрибуты блоков таймера и главного
// блока, данные редактора; панель страницы; CSS, JS, справка, README. В конце удаляет свои записи и возвращает опцию.
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
/** Класс и style корня таймера. */
function cd_root(string $html): array {
    if (!preg_match('/<div class="(wpd-countdown(?: [^"]*)?)"(?: style="([^"]*)")?/', $html, $m)) {
        return ['', ''];
    }
    return [$m[1], html_entity_decode($m[2] ?? '')];
}
function cls_has(string $cls, string $c): bool { return in_array($c, explode(' ', $cls), true); }
/** Теги <a> кнопок таймера. */
function links(string $html): array {
    preg_match_all('/<a class="wpd-btn wpd-countdown__link[^>]*>.*?<\/a>/s', $html, $m);
    return $m[0];
}
function link_of(string $html, string $when): string {
    foreach (links($html) as $l) {
        $w = preg_match('/data-when="(\w+)"/', $l, $m) ? $m[1] : 'stage';
        if ($w === $when) { return $l; }
    }
    return '';
}
function visible(string $tag): bool { return $tag !== '' && !preg_match('/^<a [^>]*\shidden>/', $tag); }

$backup = get_option('wpd_settings');
wp_set_current_user(1);
$D   = WPD_Settings::defaults();
$map = WPD_Settings::field_map();
$dir = WPD_DIR;
$created = [];
$tz  = WPD_Settings::countdown_timezone($D);
$rel = function (string $r) use ($tz) { return (new DateTime($r, $tz))->format('Y-m-d H:i'); };

/* ---------- 1. Версия и поля ---------- */
check('version >= 2.12.0', version_compare(WPD_VERSION, '2.12.0', '>='));
$new = ['countdown_before_text' => 'text', 'countdown_before_url' => 'url', 'countdown_after_text' => 'text', 'countdown_after_url' => 'url',
        'countdown_btn_icon' => 'select', 'countdown_btn_icon_pos' => 'select', 'countdown_btn_icon_size' => 'number', 'countdown_btn_download' => 'checkbox',
        'countdown_btn_nofollow' => 'checkbox', 'countdown_btn_title' => 'text', 'countdown_btn_note' => 'text', 'countdown_btn_style' => 'select',
        'countdown_btn_radius' => 'select', 'countdown_btn_width' => 'select', 'countdown_btn_pos' => 'select', 'countdown_btn_height' => 'number',
        'countdown_btn_bg' => 'color_opt', 'countdown_btn_color' => 'color_opt', 'countdown_btn_border' => 'color_opt', 'countdown_btn_hover' => 'color_opt',
        'countdown_btn_hover_color' => 'color_opt'];
$bad = [];
foreach ($new as $k => $type) {
    if (!isset($map[$k]) || $map[$k]['type'] !== $type || $map[$k]['section'] !== 'countdown_button') { $bad[] = $k; }
}
check('fields: 21 new in section countdown_button', count($new) === 21 && !$bad, implode(',', $bad));
check('fields: 313 in total', count($map) === 313, (string) count($map));
check('fields: section label', (WPD_Settings::sections()['countdown_button'] ?? '') === 'Кнопка таймера: этапы, вид и поведение');
check('tab features: sections', WPD_Settings::tabs()['features']['sections'] === ['features', 'countdown_button', 'countdown_look']);
check('defaults', $D['countdown_before_text'] === 'Регистрация' && $D['countdown_after_text'] === 'Результаты' && $D['countdown_before_url'] === ''
    && $D['countdown_btn_icon'] === 'arrow' && $D['countdown_btn_style'] === 'auto' && $D['countdown_btn_radius'] === 'auto' && $D['countdown_btn_width'] === 'auto'
    && $D['countdown_btn_pos'] === 'right' && $D['countdown_btn_icon_pos'] === 'right' && $D['countdown_btn_height'] === 0 && $D['countdown_btn_download'] === 0
    && $D['countdown_btn_bg'] === '');
check('options', array_keys($map['countdown_btn_style']['options']) === ['auto', 'soft', 'outline', 'link']
    && array_keys($map['countdown_btn_radius']['options']) === ['auto', 'none', 'small', 'large', 'pill']
    && array_keys($map['countdown_btn_pos']['options']) === ['right', 'below', 'center'] && array_keys($map['countdown_btn_width']['options']) === ['auto', 'full']
    && array_slice(array_keys($map['countdown_btn_icon']['options']), 0, 2) === ['arrow', 'none'] && isset($map['countdown_btn_icon']['options']['download']));
check('limits', $map['countdown_btn_icon_size']['max'] === 48 && $map['countdown_btn_height']['max'] === 80);

/* ---------- 2. Санитизация ---------- */
$sv = function ($k, $v) { return WPD_Settings::sanitize_value($k, $v); };
check('sanitize: select invalid -> default', $sv('countdown_btn_style', 'neon') === 'auto' && $sv('countdown_btn_icon', 'bogus') === 'arrow' && $sv('countdown_btn_style', 'outline') === 'outline');
check('sanitize: numbers clamped', $sv('countdown_btn_height', 500) === 80 && $sv('countdown_btn_icon_size', -3) === 0 && $sv('countdown_btn_icon_size', 30) === 30);
check('sanitize: colours', $sv('countdown_btn_bg', 'red') === '' && $sv('countdown_btn_bg', '#AbCdEf') === '#AbCdEf');
check('sanitize: urls', $sv('countdown_before_url', 'javascript:alert(1)') === '' && $sv('countdown_after_url', ' https://example.com/r ') === 'https://example.com/r');
check('sanitize: texts', $sv('countdown_btn_note', '<b>Вход</b> по логину') === 'Вход по логину' && (int) $sv('countdown_btn_download', '1') === 1);
$idem = true;
foreach (['countdown_btn_style' => 'link', 'countdown_btn_height' => 44, 'countdown_btn_bg' => '#112233', 'countdown_before_url' => 'https://e.com/a?b=1', 'countdown_btn_note' => 'Т & т'] as $k => $v) {
    $once = $sv($k, $v);
    if ($sv($k, $once) !== $once) { $idem = false; }
}
check('sanitize: idempotent', $idem);

/* ---------- 3. Разметка по умолчанию не меняется ---------- */
$o = array_merge($D, ['countdown_link_url' => 'https://example.com/tasks', 'countdown_link_text' => 'Открыть задания']);
$args_active = ['enabled' => true, 'date' => $rel('-1 hour'), 'end' => $rel('+1 day')];
$h = WPD_Renderer::countdown_html($o, $args_active);
check('default: same link markup as 1.6.0', has($h, '<a class="wpd-btn wpd-countdown__link" href="https://example.com/tasks" target="_blank" rel="noopener noreferrer">Открыть задания &rarr;</a></div>'), $h);
[$c, $st] = cd_root($h);
check('default: no button classes or vars', $c === 'wpd-countdown' && $st === '' && !has($h, 'wpd-countdown__actions') && !has($h, 'data-when'), $c);
check('default: before/after need urls', count(links($h)) === 1);

/* ---------- 4. Кнопки этапов ---------- */
$o2 = array_merge($o, ['countdown_before_url' => 'https://example.com/reg', 'countdown_after_url' => 'https://example.com/res']);
$hb = WPD_Renderer::countdown_html($o2, ['enabled' => true, 'date' => $rel('+1 hour'), 'end' => $rel('+1 day')]);
check('before: three buttons in wrapper', count(links($hb)) === 3 && has($hb, '<div class="wpd-countdown__actions">'), $hb);
check('before: only before button visible', visible(link_of($hb, 'before')) && !visible(link_of($hb, 'stage')) && !visible(link_of($hb, 'after')));
check('before: button markup', has(link_of($hb, 'before'), 'class="wpd-btn wpd-countdown__link wpd-countdown__link--before" data-when="before" href="https://example.com/reg"')
    && has(link_of($hb, 'before'), '>Регистрация &rarr;</a>'));
$ha = WPD_Renderer::countdown_html($o2, $args_active);
check('active: only stage button visible', !visible(link_of($ha, 'before')) && visible(link_of($ha, 'stage')) && !visible(link_of($ha, 'after')));
$hd = WPD_Renderer::countdown_html($o2, ['enabled' => true, 'date' => $rel('-2 days'), 'end' => $rel('-1 day')]);
check('done: only after button visible', !visible(link_of($hd, 'before')) && !visible(link_of($hd, 'stage')) && visible(link_of($hd, 'after'))
    && has(link_of($hd, 'after'), 'wpd-countdown__link--after" data-when="after" href="https://example.com/res"') && has($hd, '>Результаты &rarr;</a>'));
$hn = WPD_Renderer::countdown_html($o2, ['enabled' => true, 'date' => $rel('-1 hour')]);
check('no end date: no after button', link_of($hn, 'after') === '' && visible(link_of($hn, 'stage')));
$hx = WPD_Renderer::countdown_html(array_merge($o2, ['countdown_before_text' => '', 'countdown_after_url' => 'javascript:alert(1)']), $args_active);
check('empty text or bad url: no button', link_of($hx, 'before') === '' && link_of($hx, 'after') === '' && count(links($hx)) === 1 && !has($hx, 'wpd-countdown__actions'));
$hw = WPD_Renderer::countdown_html(array_merge($o2, ['countdown_before_url' => '']), ['enabled' => true, 'date' => $rel('+1 hour'), 'end' => $rel('+1 day')]);
check('nothing visible: wrapper hidden', has($hw, '<div class="wpd-countdown__actions" hidden>'), $hw);
$hs = WPD_Renderer::countdown_html(array_merge($o, ['countdown_link_url' => '', 'countdown_before_url' => 'https://example.com/reg']), ['enabled' => true, 'date' => $rel('+1 hour')]);
check('before button alone: no wrapper, visible', count(links($hs)) === 1 && visible(link_of($hs, 'before')) && !has($hs, 'wpd-countdown__actions'));

/* ---------- 5. Подпись ---------- */
$o3 = array_merge($o, ['countdown_btn_note' => 'Вход по логину из письма']);
$h = WPD_Renderer::countdown_html($o3, $args_active);
check('note: wrapper with note after button', (bool) preg_match('/<div class="wpd-countdown__actions"><a [^>]+>[^<]+<\/a><p class="wpd-countdown__note">Вход по логину из письма<\/p><\/div>/', $h), $h);
$h = WPD_Renderer::countdown_html($o3, ['enabled' => true, 'date' => $rel('+1 hour')]);
check('note: hidden with the button', has($h, '<div class="wpd-countdown__actions" hidden>') && has($h, '<p class="wpd-countdown__note" hidden>'));
$h = WPD_Renderer::countdown_html(array_merge($o3, ['countdown_link_url' => '']), $args_active);
check('note: no button -> no note', !has($h, 'wpd-countdown__note'));
$h = WPD_Renderer::countdown_html(array_merge($o, ['countdown_btn_note' => '<script>x</script>Подпись']), $args_active);
check('note: escaped', has($h, '>Подпись</p>') && !has($h, '<script'));

/* ---------- 6. Иконка и поведение ---------- */
$L = function (array $over) use ($o, $args_active) { return link_of(WPD_Renderer::countdown_html(array_merge($o, $over), $args_active), 'stage'); };
$R = function (array $over) use ($o, $args_active) { return cd_root(WPD_Renderer::countdown_html(array_merge($o, $over), $args_active)); };
check('icon none: no arrow', has($L(['countdown_btn_icon' => 'none']), '>Открыть задания</a>'));
check('icon right', has($L(['countdown_btn_icon' => 'download']), '>Открыть задания<span class="wpd-countdown__icon dashicons dashicons-download" aria-hidden="true"></span></a>'));
check('icon left', has($L(['countdown_btn_icon' => 'download', 'countdown_btn_icon_pos' => 'left']), '"><span class="wpd-countdown__icon dashicons dashicons-download" aria-hidden="true"></span>Открыть задания</a>')
    && cls_has($R(['countdown_btn_icon' => 'download', 'countdown_btn_icon_pos' => 'left'])[0], 'wpd-cdb--icon-left'));
check('arrow ignores left position', has($L(['countdown_btn_icon_pos' => 'left']), 'Открыть задания &rarr;</a>') && !has($R(['countdown_btn_icon_pos' => 'left'])[0], 'icon-left'));
check('icon: dashicons enqueued', wp_style_is('dashicons', 'enqueued'));
[$c, $st] = $R(['countdown_btn_icon' => 'download', 'countdown_btn_icon_size' => 5]);
check('icon size clamped to 10', cls_has($c, 'wpd-cdb--icon') && has($st, '--wpd-cdb-icon:10px;'), $st);
check('icon size max 48, none -> no size', has($R(['countdown_btn_icon_size' => 99])[1], '--wpd-cdb-icon:48px;') && !has($R(['countdown_btn_icon' => 'none', 'countdown_btn_icon_size' => 20])[1], 'cdb-icon'));
check('download and title', has($L(['countdown_btn_download' => 1, 'countdown_btn_title' => 'Скачать "задания"']), 'rel="noopener noreferrer" download title="Скачать &quot;задания&quot;">'));
check('nofollow with new tab', has($L(['countdown_btn_nofollow' => 1]), 'rel="noopener noreferrer nofollow"'));
$l = $L(['countdown_btn_nofollow' => 1, 'countdown_link_new_tab' => 0]);
check('nofollow without new tab', has($l, 'href="https://example.com/tasks" rel="nofollow">') && !has($l, ' target='), $l);
$hb2 = WPD_Renderer::countdown_html(array_merge($o2, ['countdown_link_new_tab' => 0, 'countdown_btn_icon' => 'none']), ['enabled' => true, 'date' => $rel('+1 hour'), 'end' => $rel('+1 day')]);
check('behaviour applies to all phase buttons', !has($hb2, ' target="_blank"') && has(link_of($hb2, 'before'), '>Регистрация</a>') && has(link_of($hb2, 'after'), '>Результаты</a>'));

/* ---------- 7. Вид и цвета ---------- */
foreach (['soft', 'outline', 'link'] as $v) {
    check("style $v: class", cls_has($R(['countdown_btn_style' => $v])[0], 'wpd-cdb--' . $v) && cls_has($R(['countdown_btn_style' => $v])[0], 'wpd-cdb'));
}
check('style: invalid -> nothing', $R(['countdown_btn_style' => 'neon'])[0] === 'wpd-countdown');
check('radius', cls_has($R(['countdown_btn_radius' => 'pill'])[0], 'wpd-cdb--r-pill') && cls_has($R(['countdown_btn_radius' => 'none'])[0], 'wpd-cdb--r-none'));
check('width and position', cls_has($R(['countdown_btn_width' => 'full'])[0], 'wpd-cdb--full') && cls_has($R(['countdown_btn_pos' => 'below'])[0], 'wpd-cdb--below')
    && cls_has($R(['countdown_btn_pos' => 'center'])[0], 'wpd-cdb--center'));
check('height clamped', has($R(['countdown_btn_height' => 20])[1], '--wpd-cdb-h:32px;') && has($R(['countdown_btn_height' => 120])[1], '--wpd-cdb-h:80px;') && cls_has($R(['countdown_btn_height' => 50])[0], 'wpd-cdb--h'));
[$c, $st] = $R(['countdown_btn_bg' => '#111111', 'countdown_btn_color' => '#222222', 'countdown_btn_border' => '#333333', 'countdown_btn_hover' => '#444444', 'countdown_btn_hover_color' => '#555555']);
check('colours: classes and vars', cls_has($c, 'wpd-cdb--bg') && cls_has($c, 'wpd-cdb--fg') && cls_has($c, 'wpd-cdb--bd') && cls_has($c, 'wpd-cdb--hbg') && cls_has($c, 'wpd-cdb--hfg')
    && $st === '--wpd-cdb-bg:#111111;--wpd-cdb-fg:#222222;--wpd-cdb-bd:#333333;--wpd-cdb-hbg:#444444;--wpd-cdb-hfg:#555555;', $st);
check('colours: invalid ignored', $R(['countdown_btn_bg' => 'red', 'countdown_btn_color' => 'url(x)'])[0] === 'wpd-countdown');
[$c, $st] = $R(['countdown_btn_style' => 'outline', 'countdown_value_size' => 30]);
check('button vars after look vars', has($c, 'wpd-countdown--size wpd-cdb wpd-cdb--outline') && has($st, '--wpd-bl-val:30px;'), $c);
check('shortcode uses settings', (function () use ($o) {
    set_opts(array_merge($o, ['countdown_enabled' => 1, 'countdown_date' => (new DateTime('-1 hour', WPD_Settings::countdown_timezone()))->format('Y-m-d H:i'), 'countdown_btn_style' => 'link']));
    $h = do_shortcode('[wpd_countdown]');
    return has($h, 'wpd-cdb--link') && has($h, 'wpd-countdown__link');
})());

/* ---------- 8. Блоки ---------- */
$defs = WPD_Blocks::definitions();
$cdA = $defs['countdown']['attributes'];
$heA = $defs['hero']['attributes'];
$miss = [];
foreach (WPD_Blocks::CD_BTN_ATTRS as $attr => $key) {
    if (!isset($cdA[$attr], $heA[$attr])) { $miss[] = $attr; }
}
check('blocks: 21 button attributes on countdown and hero', count(WPD_Blocks::CD_BTN_ATTRS) === 21 && !$miss, implode(',', $miss));
check('blocks: attribute types', $cdA['cdBtnHeight']['type'] === 'integer' && $cdA['cdBtnDownload']['type'] === 'boolean' && $cdA['cdBtnStyle']['type'] === 'string'
    && $heA['cdLinkNewTab']['type'] === 'boolean' && isset($heA['cdLinkText'], $heA['cdLinkUrl']) && !isset($cdA['cdLinkText']));
check('blocks: defaults are settings', $cdA['cdBtnStyle']['default'] === 'link' && $cdA['cdBeforeText']['default'] === 'Регистрация' && $heA['cdLinkUrl']['default'] === 'https://example.com/tasks');
check('look_attrs(countdown) includes button', array_slice(WPD_Blocks::look_attrs('countdown'), -21, 21, true) === WPD_Blocks::CD_BTN_ATTRS);
check('hero_countdown_attrs', array_keys(WPD_Blocks::hero_countdown_attrs()) === array_merge(['cdLinkText', 'cdLinkUrl', 'cdLinkNewTab'], array_keys(WPD_Blocks::CD_BTN_ATTRS)));
check('groups', WPD_Blocks::countdown_button_group('countdown_before_url') === 'btn_phases' && WPD_Blocks::countdown_button_group('countdown_link_text') === 'btn_phases'
    && WPD_Blocks::countdown_button_group('countdown_btn_hover_color') === 'btn_look' && WPD_Blocks::countdown_button_group('countdown_btn_pos') === 'btn_look'
    && WPD_Blocks::countdown_button_group('countdown_btn_note') === 'btn_more' && WPD_Blocks::countdown_button_group('countdown_btn_icon_size') === 'btn_more');
$lo = WPD_Blocks::look_options_data();
check('editor options: countdown groups', $lo['countdown']['cdBtnStyle']['group'] === 'btn_look' && $lo['countdown']['cdBeforeUrl']['group'] === 'btn_phases'
    && $lo['countdown']['cdBtnIcon']['group'] === 'btn_more' && $lo['countdown']['valueSize']['group'] === 'extra' && $lo['countdown']['bgColor']['group'] === 'colors'
    && $lo['countdown']['cdBtnHeight']['max'] === 80 && isset($lo['countdown']['cdBtnIcon']['options']['arrow']));
check('editor options: hero timer', array_keys($lo['heroCountdown']) === array_keys(WPD_Blocks::hero_countdown_attrs()) && $lo['heroCountdown']['cdLinkUrl']['group'] === 'btn_phases');
$ls = WPD_Blocks::look_settings_data(array_merge($D, ['countdown_btn_style' => 'soft', 'countdown_btn_height' => 44, 'countdown_link_text' => 'К заданиям']));
check('editor settings data', $ls['countdown']['cdBtnStyle'] === 'soft' && $ls['countdown']['cdBtnHeight'] === 44 && $ls['heroCountdown']['cdLinkText'] === 'К заданиям'
    && $ls['heroCountdown']['cdBtnDownload'] === false);

$date = $rel('-1 hour');
$end  = $rel('+1 day');
set_opts(array_merge($o, ['countdown_enabled' => 1, 'countdown_date' => $date, 'countdown_end' => $end, 'countdown_btn_style' => 'outline', 'countdown_btn_note' => 'Из настроек']));
$b = blk('countdown', ['date' => $date, 'end' => $end, 'linkUrl' => 'https://example.com/t']);
[$c] = cd_root($b);
check('block countdown: settings apply', cls_has($c, 'wpd-cdb--outline') && has($b, 'Из настроек'));
$b = blk('countdown', ['date' => $date, 'end' => $end, 'linkUrl' => 'https://example.com/t', 'cdBtnStyle' => 'soft', 'cdBtnHeight' => 0, 'cdBtnNote' => '', 'cdBtnIcon' => 'book',
    'cdBtnIconPos' => 'left', 'cdBtnDownload' => true, 'cdAfterUrl' => 'https://example.com/res', 'cdAfterText' => 'Итоги', 'cdBtnBg' => '#abcdef']);
[$c, $st] = cd_root($b);
check('block countdown: attributes override', cls_has($c, 'wpd-cdb--soft') && !cls_has($c, 'wpd-cdb--outline') && !has($b, 'Из настроек') && has($b, 'dashicons-book')
    && cls_has($c, 'wpd-cdb--icon-left') && has($b, ' download') && has($st, '--wpd-cdb-bg:#abcdef;'), $c);
check('block countdown: phase button from attributes', has(link_of($b, 'after'), 'href="https://example.com/res"') && has(link_of($b, 'after'), 'Итоги') && !visible(link_of($b, 'after')));
check('block countdown: 0 -> settings, invalid style -> default', !cls_has(cd_root(blk('countdown', ['date' => $date, 'linkUrl' => 'https://e.com/t', 'cdBtnStyle' => 'bogus']))[0], 'wpd-cdb--outline')
    && has(cd_root(blk('countdown', ['date' => $date, 'linkUrl' => 'https://e.com/t', 'cdBtnHeight' => 0]))[0], 'wpd-cdb--outline'));
check('block countdown: bad url attr -> no button', count(links(blk('countdown', ['date' => $rel('+1 hour'), 'linkUrl' => '', 'cdBeforeUrl' => 'javascript:alert(1)']))) === 0);
$hh = blk('hero', ['showCountdown' => true]);
check('block hero: timer button from settings', has($hh, 'wpd-hero__countdown') && cls_has(cd_root($hh)[0], 'wpd-cdb--outline') && has($hh, 'href="https://example.com/tasks"'), substr($hh, 0, 200));
$hh = blk('hero', ['showCountdown' => true, 'cdLinkUrl' => 'https://example.com/hero', 'cdLinkText' => 'В бой', 'cdLinkNewTab' => false, 'cdBtnStyle' => 'link', 'cdBtnPos' => 'center']);
[$c] = cd_root($hh);
check('block hero: attributes override timer button', cls_has($c, 'wpd-cdb--link') && cls_has($c, 'wpd-cdb--center') && has($hh, 'href="https://example.com/hero">В бой &rarr;</a>'), $c);
check('block hero: off -> no timer', !has(blk('hero', ['showCountdown' => false, 'cdBtnStyle' => 'link']), 'wpd-countdown'));

/* ---------- 9. Панель страницы ---------- */
check('page group features includes button section', WPD_Page_Editor::groups()['features']['sections'] === ['features', 'countdown_button', 'countdown_look']
    && in_array('countdown_btn_style', WPD_Page_Editor::group_keys('features'), true) && in_array('countdown_before_url', WPD_Page_Editor::group_keys('features'), true)
    && has(WPD_Page_Editor::groups()['features']['desc'], 'кнопки до начала и после окончания'));
$pid = (int) wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Z panel', 'post_content' => '<p>Z</p>']);
$created[] = $pid;
$ov = ['countdown_enabled' => '1', 'countdown_tz' => 'Europe/Moscow', 'countdown_date' => $date, 'countdown_end' => $end, 'countdown_label' => 'До', 'countdown_end_label' => 'До конца',
       'countdown_done' => 'Всё', 'countdown_link_text' => 'Задания стр', 'countdown_link_url' => 'https://example.com/page', 'countdown_link_new_tab' => '1',
       'countdown_btn_style' => 'link', 'countdown_btn_radius' => 'pill', 'countdown_btn_note' => 'Подпись страницы', 'countdown_btn_color' => '#ff0000',
       'countdown_after_url' => 'https://example.com/page-res', 'countdown_btn_height' => '999', 'countdown_btn_icon' => 'nonsense'];
update_post_meta($pid, '_wpd_page', WPD_Page_Editor::sanitize(['enabled' => '1', 'order' => ['countdown'], 'show' => ['countdown' => '1'], 'ovg' => ['features' => '1'], 'ov' => $ov], $pid));
WPD_Settings::flush_cache();
$po = WPD_Page_Editor::options($pid);
check('page options: sanitized', $po['countdown_btn_style'] === 'link' && $po['countdown_btn_height'] === 80 && $po['countdown_btn_icon'] === 'arrow' && $po['countdown_btn_color'] === '#ff0000', wp_json_encode([$po['countdown_btn_style'], $po['countdown_btn_height'], $po['countdown_btn_icon']]));
$ph = WPD_Renderer::page_html($pid, '<p>Z</p>');
[$c, $st] = cd_root($ph);
check('page html: own button look', cls_has($c, 'wpd-cdb--link') && cls_has($c, 'wpd-cdb--r-pill') && has($st, '--wpd-cdb-fg:#ff0000;') && has($ph, 'Подпись страницы')
    && has($ph, 'href="https://example.com/page"') && has(link_of($ph, 'after'), 'page-res'), $c);

/* ---------- 10. CSS, JS, справка, README ---------- */
$css = (string) file_get_contents($dir . 'assets/css/wpd.css');
check('css: actions and note', has($css, '.wpd-page .wpd-countdown__actions {') && has($css, '.wpd-page .wpd-countdown__note[hidden]') && has($css, '.wpd-countdown__icon.dashicons'));
check('css: position, width, height, radius', has($css, '.wpd-countdown.wpd-cdb.wpd-cdb--center') && has($css, '.wpd-cdb--full > :is(.wpd-countdown__link, .wpd-countdown__actions)')
    && has($css, 'min-height: var(--wpd-cdb-h)') && has($css, '.wpd-cdb--r-pill .wpd-countdown__link.wpd-btn { border-radius: 999px; }'));
check('css: styles and colours', has($css, '.wpd-cdb.wpd-cdb--outline .wpd-countdown__link.wpd-btn') && has($css, '.wpd-cdb.wpd-cdb--link .wpd-countdown__link.wpd-btn')
    && has($css, 'var(--wpd-cdb-bg, var(--wpd-primary)) 14%') && has($css, 'background: var(--wpd-cdb-hbg)') && has($css, 'color: var(--wpd-cdb-hfg)'));
$fjs = (string) file_get_contents($dir . 'assets/js/wpd.js');
check('wpd.js: phase buttons', has($fjs, "querySelectorAll('.wpd-countdown__link')") && has($fjs, "getAttribute('data-when') || 'stage'") && has($fjs, 'actions.hidden = !any') && has($fjs, 'note.hidden = !any'));
$js = (string) file_get_contents($dir . 'assets/js/wpd-blocks.js');
check('wpd-blocks.js: helpers and panels', has($js, 'function cdButtonLook(slug, a)') && has($js, 'function cdButtonEl(') && has($js, "LookPanels('heroCountdown', a, set, true)")
    && has($js, "'Кнопки до начала и после окончания'") && has($js, "'Кнопка таймера: вид и цвета'") && has($js, "'Кнопка таймера: иконка и поведение'"));
check('wpd-blocks.js: classes mirror renderer', has($js, "'wpd-cdb--' + v") && has($js, "'wpd-cdb--r-' + v") && has($js, "'wpd-cdb--icon-left'") && has($js, "'--wpd-cdb-h'"));
$rh = new ReflectionMethod('WPD_Settings', 'render_help');
$rh->setAccessible(true);
ob_start();
$rh->invoke(null);
$help = (string) ob_get_clean();
check('help: section 2.12.0', has($help, 'Кнопка таймера (2.12.0)') && has($help, 'после окончания') && has($help, 'nofollow'));
$readme = (string) file_get_contents($dir . 'README.md');
check('README: 2.12.0 section', has($readme, 'Кнопка таймера (2.12.0)'));

/* ---------- Уборка ---------- */
foreach ($created as $id) { wp_delete_post($id, true); }
if (is_array($backup)) { set_opts($backup); } else { delete_option('wpd_settings'); WPD_Settings::flush_cache(); }
check('cleanup', !get_post($pid));

echo implode("\n", $results) . "\n";
echo "TOTAL: " . count($results) . ", FAILED: $fails\n";
