<?php
// Фаза AA (2.13.0): высота блоков и иконки. Поля «Минимальная высота блока», «Содержимое по вертикали», «Показывать иконки»
// и флажки строк «Без иконки» / «Без значка»; санитизация; разметка по умолчанию не меняется; классы wpd-bh, wpd-bh--center|bottom,
// переменная --wpd-bh и класс wpd-noicons; иконки и стрелки каждого блока; атрибуты блоков и данные редактора; панель страницы;
// CSS, JS, справка, README. В конце удаляет свои записи и возвращает опцию.
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
function no_new(string $cls): bool { return !preg_match('/\bwpd-(bh|noicons)\b/', $cls); }

$backup = get_option('wpd_settings');
wp_set_current_user(1);
$D   = WPD_Settings::defaults();
$map = WPD_Settings::field_map();
$dir = WPD_DIR;
$created = [];
$R = 'WPD_Renderer';

/* ---------- 1. Поля ---------- */
$box = array_filter($map, function ($f, $k) { return (bool) preg_match('/_(min_h|valign|show_icons)$/', $k) && !in_array($k, ['contacts_show_icons', 'hero_valign'], true); }, ARRAY_FILTER_USE_BOTH);
check('box fields: 30', count($box) === 30, (string) count($box));
check('field_map total 343', count($map) === 343, (string) count($map));
check('plain fields 334', count(array_filter($map, function ($f) { return $f['type'] !== 'repeater'; })) === 334);
$ok = true;
foreach (WPD_Settings::BOX_BLOCKS as $slug => $keys) {
    foreach ($keys as $k) {
        $f = $map[$slug . '_' . $k] ?? null;
        $ok = $ok && $f && $f['section'] === WPD_Settings::box_section($slug);
    }
}
check('box fields exist in their sections', $ok);
check('sections: hero_look, buttons_layout, partners_look, stats_look', WPD_Settings::box_section('hero') === 'hero_look' && WPD_Settings::box_section('buttons') === 'buttons_layout'
    && WPD_Settings::box_section('partners') === 'partners_look' && WPD_Settings::box_section('stats') === 'stats_look');
check('slider and hero: icons only (own heights exist)', WPD_Settings::BOX_BLOCKS['slider'] === ['show_icons'] && WPD_Settings::BOX_BLOCKS['hero'] === ['show_icons']
    && !isset($map['slider_min_h']) && !isset($map['hero_min_h']));
check('contacts, partners: no show_icons field', !isset($map['partners_show_icons']) && WPD_Settings::BOX_BLOCKS['contacts'] === ['min_h', 'valign']);
check('min_h: number 0..1200 default 0', $map['stats_min_h']['type'] === 'number' && $map['stats_min_h']['min'] === 0 && $map['stats_min_h']['max'] === 1200 && $D['stats_min_h'] === 0);
check('valign: select default top', $map['faq_valign']['type'] === 'select' && array_keys($map['faq_valign']['options']) === ['', 'center', 'bottom'] && $D['faq_valign'] === '');
check('show_icons: checkbox default on', $map['cards_show_icons']['type'] === 'checkbox' && (int) $D['cards_show_icons'] === 1 && (int) $D['hero_show_icons'] === 1);
check('show_icons desc names icons and row flag', has($map['docs_show_icons']['desc'], 'PDF') && has($map['docs_show_icons']['desc'], '«Без значка»')
    && has($map['buttons_show_icons']['desc'], '«Без иконки»') && !has($map['faq_show_icons']['desc'], '«Без'));
check('row noicon: docs, buttons, cards (last field)', ($map['docs']['fields']['noicon']['type'] ?? '') === 'checkbox' && array_key_last($map['docs']['fields']) === 'noicon'
    && array_key_last($map['buttons']['fields']) === 'noicon' && array_key_last($map['cards']['fields']) === 'noicon'
    && $map['cards']['fields']['noicon']['label'] === 'Без иконки и стрелки' && $map['docs']['fields']['noicon']['label'] === 'Без значка');
check('no row noicon elsewhere', !isset($map['stats']['fields']['noicon']) && !isset($map['faq']['fields']['noicon']));

/* ---------- 2. Санитизация ---------- */
$sv = function ($k, $v) { return WPD_Settings::sanitize_value($k, $v); };
check('sanitize min_h', $sv('stats_min_h', '450') === 450 && $sv('stats_min_h', '5000') === 1200 && $sv('stats_min_h', '-3') === 0 && $sv('stats_min_h', 'abc') === 0,
    wp_json_encode([$sv('stats_min_h', '5000'), $sv('stats_min_h', '-3')]));
check('sanitize valign', $sv('news_valign', 'bottom') === 'bottom' && $sv('news_valign', 'evil') === '');
check('sanitize show_icons', in_array($sv('faq_show_icons', '1'), [1, '1'], true) && in_array($sv('faq_show_icons', ''), [0, '0', ''], true));
$rows = WPD_Settings::sanitize_rows([['title' => 'A', 'url' => 'https://example.com/a.pdf', 'noicon' => '1'], ['title' => 'B', 'url' => 'https://example.com/b']], 'docs');
check('sanitize repeater keeps noicon', !empty($rows[0]['noicon']) && empty($rows[1]['noicon']), wp_json_encode($rows));

/* ---------- 3. По умолчанию разметка не меняется ---------- */
set_opts($D);
$o = WPD_Settings::get();
$st_items = [['num' => '10', 'label' => 'школ', 'icon' => 'welcome-learn-more']];
$doc_items = [['title' => 'Положение', 'url' => 'https://example.com/p.pdf'], ['title' => 'Сайт', 'url' => 'https://example.com/s', 'noicon' => '1']];
$btn_items = [['icon' => 'admin-links', 'text' => 'Раз', 'url' => 'https://example.com/1'], ['icon' => 'media-document', 'text' => 'Два', 'url' => 'https://example.com/2', 'noicon' => '1']];
$card_items = [['title' => 'К1', 'url' => 'https://example.com/k1', 'icon' => 'star-filled'], ['title' => 'К2', 'url' => 'https://example.com/k2', 'icon' => 'star-filled', 'noicon' => '1'],
    ['title' => 'К3', 'url' => 'https://example.com/k3', 'more' => 'Подробнее']];
$faq_items = [['q' => 'Вопрос?', 'a' => 'Ответ.']];
$h = [
    'stats' => $R::stats_html($o, $st_items),
    'docs' => $R::docs_html($o, [$doc_items[0]], 'Документы'),
    'faq' => $R::faq_html($o, $faq_items, 'FAQ'),
    'buttons' => $R::buttons_html($o, [$btn_items[0]], 'Кнопки'),
    'cards' => $R::cards_html($o, [$card_items[0], $card_items[2]], 'Карточки'),
];
$okd = true;
foreach (['stats' => 'wpd-stats', 'docs' => 'wpd-docs', 'faq' => 'wpd-faq', 'buttons' => 'wpd-buttons', 'cards' => 'wpd-cards'] as $k => $base) {
    [$c, $s] = root($h[$k], $base);
    $okd = $okd && $c !== '' && no_new($c) && !has($s, '--wpd-bh') && !has($h[$k], 'noicon');
}
check('defaults: no new classes or vars', $okd);
check('defaults: icons present', has($h['stats'], 'wpd-stat__icon') && has($h['stats'], 'dashicons-welcome-learn-more') && has($h['docs'], 'wpd-doc__icon') && has($h['buttons'], 'wpd-qbtn__icon')
    && has($h['cards'], 'wpd-card__icon') && has($h['cards'], 'Подробнее &rarr;') && has($h['cards'], '<span class="wpd-card__more" aria-hidden="true">&rarr;</span>'));
check('defaults: root class exactly as before', root($h['stats'], 'wpd-stats')[0] === 'wpd-stats' && root($h['faq'], 'wpd-faq')[0] === 'wpd-faq' && root($h['buttons'], 'wpd-buttons')[0] === 'wpd-buttons');
$cd = $R::countdown_html(array_merge($o, ['countdown_enabled' => 1, 'countdown_date' => '2030-01-01 10:00', 'countdown_link_text' => 'Go', 'countdown_link_url' => 'https://example.com/go']));
check('defaults: countdown root and arrow', no_new(root($cd, 'wpd-countdown')[0]) && has($cd, 'Go &rarr;</a>'));

/* ---------- 4. Высота блока ---------- */
check('box_look: none by default', $R::box_look($o, 'stats') === ['class' => [], 'vars' => []]);
$bl = $R::box_look(array_merge($o, ['stats_min_h' => 400, 'stats_valign' => 'center']), 'stats');
check('box_look: height + center', $bl['class'] === ['wpd-bh', 'wpd-bh--center'] && $bl['vars'] === ['--wpd-bh' => '400px'], wp_json_encode($bl));
check('box_look: valign without height ignored', $R::box_look(array_merge($o, ['stats_valign' => 'bottom']), 'stats')['class'] === []);
check('box_look: clamp 1200', $R::box_look(array_merge($o, ['docs_min_h' => 5000]), 'docs')['vars']['--wpd-bh'] === '1200px');
check('box_look: bad valign ignored', $R::box_look(array_merge($o, ['docs_min_h' => 300, 'docs_valign' => 'middle']), 'docs')['class'] === ['wpd-bh']);
check('box_look: slider has no height', $R::box_look(array_merge($o, ['slider_min_h' => 300]), 'slider')['class'] === []);
$x = $R::stats_html(array_merge($o, ['stats_min_h' => 420, 'stats_valign' => 'bottom']), $st_items);
[$c, $s] = root($x, 'wpd-stats');
check('stats: height classes without wpd-look', $c === 'wpd-stats wpd-bh wpd-bh--bottom' && has($s, '--wpd-bh:420px;'), $c . ' | ' . $s);
$x = $R::stats_html(array_merge($o, ['stats_min_h' => 420, 'stats_gap' => 30]), $st_items);
[$c, $s] = root($x, 'wpd-stats');
check('stats: with look params keeps wpd-look', cls_has($c, 'wpd-look') && cls_has($c, 'wpd-look--gap') && cls_has($c, 'wpd-bh') && has($s, '--wpd-bl-gap:30px;') && has($s, '--wpd-bh:420px;'), $c);
$okh = true; $bad = '';
foreach (['docs' => [$R::docs_html(array_merge($o, ['docs_min_h' => 300, 'docs_valign' => 'center']), $doc_items, 'Д'), 'wpd-docs'],
          'faq' => [$R::faq_html(array_merge($o, ['faq_min_h' => 300, 'faq_valign' => 'center']), $faq_items, 'F'), 'wpd-faq'],
          'buttons' => [$R::buttons_html(array_merge($o, ['buttons_min_h' => 300, 'buttons_valign' => 'center']), $btn_items, 'B'), 'wpd-buttons'],
          'cards' => [$R::cards_html(array_merge($o, ['cards_min_h' => 300, 'cards_valign' => 'center']), $card_items, 'C'), 'wpd-cards'],
          'notice' => [$R::notice_html(array_merge($o, ['notice_enabled' => 1, 'notice_title' => 'Внимание', 'notice_min_h' => 300, 'notice_valign' => 'center'])), 'wpd-notice'],
          'countdown' => [$R::countdown_html(array_merge($o, ['countdown_enabled' => 1, 'countdown_date' => '2030-01-01 10:00', 'countdown_min_h' => 300, 'countdown_valign' => 'center'])), 'wpd-countdown'],
          'contacts' => [$R::contacts_html(array_merge($o, ['contact_email' => 'a@example.com', 'contacts_min_h' => 300, 'contacts_valign' => 'center'])), 'wpd-contacts'],
         ] as $k => $pair) {
    [$c, $s] = root($pair[0], $pair[1]);
    if (!(cls_has($c, 'wpd-bh') && cls_has($c, 'wpd-bh--center') && has($s, '--wpd-bh:300px;'))) { $okh = false; $bad .= $k . '=' . $c . ' '; }
}
check('height on docs, faq, buttons, cards, notice, countdown, contacts', $okh, $bad);
$ph = $R::partners_html(array_merge($o, ['partners_items' => [['name' => 'П1', 'logo' => '', 'url' => '']], 'partners_min_h' => 260, 'partners_valign' => 'bottom']),
    ['items' => [['name' => 'П1', 'logo' => '', 'url' => '', 'new_tab' => '', 'hidden' => '', 'from' => '', 'to' => '']]]);
[$c, $s] = root($ph, 'wpd-partners');
check('partners: height', (cls_has($c, 'wpd-bh') && cls_has($c, 'wpd-bh--bottom') && has($s, '--wpd-bh:260px;')), $c . ' | ' . $s);
$hero_cd = $R::hero_html(array_merge($o, ['hero_title' => 'Герой', 'countdown_enabled' => 1, 'countdown_date' => '2030-01-01 10:00', 'countdown_min_h' => 500, 'countdown_valign' => 'center']), ['countdown' => true]);
check('hero timer ignores block height', has($hero_cd, 'wpd-countdown') ? !has($hero_cd, 'wpd-bh') : true);

/* ---------- 5. Иконки блока ---------- */
$x = $R::stats_html(array_merge($o, ['stats_show_icons' => 0]), $st_items);
check('stats: icons off', !has($x, 'wpd-stat__icon') && cls_has(root($x, 'wpd-stats')[0], 'wpd-noicons') && has($x, 'школ'));
$x = $R::docs_html(array_merge($o, ['docs_show_icons' => 0]), $doc_items, 'Д');
check('docs: icons off -> notype + noicons', !has($x, 'wpd-doc__icon') && cls_has(root($x, 'wpd-docs')[0], 'wpd-docs--notype') && cls_has(root($x, 'wpd-docs')[0], 'wpd-noicons'));
$x = $R::docs_html($o, $doc_items, 'Д');
check('docs: row noicon', substr_count($x, 'wpd-doc__icon') === 1 && substr_count($x, 'wpd-doc--noicon') === 1 && !cls_has(root($x, 'wpd-docs')[0], 'wpd-noicons'));
$x = $R::docs_html(array_merge($o, ['docs_show_type' => 0]), $doc_items, 'Д');
check('docs: show_type off -> no row noicon class', !has($x, 'wpd-doc--noicon') && !has($x, 'wpd-doc__icon'));
$x = $R::faq_html(array_merge($o, ['faq_show_icons' => 0]), $faq_items, 'F');
check('faq: icons off -> noicons class', cls_has(root($x, 'wpd-faq')[0], 'wpd-noicons'));
$x = $R::buttons_html($o, $btn_items, 'B');
check('buttons: row noicon', substr_count($x, 'wpd-qbtn__icon') === 1 && substr_count($x, 'wpd-qbtn--noicon') === 1 && has($x, 'Два'));
$x = $R::buttons_html(array_merge($o, ['buttons_show_icons' => 0]), $btn_items, 'B');
check('buttons: icons off', !has($x, 'wpd-qbtn__icon') && substr_count($x, 'wpd-qbtn--noicon') === 2 && cls_has(root($x, 'wpd-buttons')[0], 'wpd-noicons'));
$x = $R::buttons_html(array_merge($o, ['buttons_show_icons' => 0]), [['icon' => '', 'image' => 'https://example.com/i.png', 'text' => 'Img', 'url' => 'https://example.com/']], 'B');
check('buttons: icons off hides image too', !has($x, 'wpd-qbtn__img'));
$x = $R::cards_html($o, $card_items, 'C');
check('cards: row noicon hides icon and arrow', substr_count($x, 'wpd-card__icon') === 1 && substr_count($x, '<span class="wpd-card__more" aria-hidden="true">&rarr;</span>') === 1 && has($x, 'Подробнее &rarr;'));
$x = $R::cards_html(array_merge($o, ['cards_show_icons' => 0]), $card_items, 'C');
check('cards: icons off', !has($x, 'wpd-card__icon') && !has($x, '&rarr;') && has($x, 'Подробнее</span>') && has($x, 'К2'));
$n_on = $R::notice_html(array_merge($o, ['notice_enabled' => 1, 'notice_title' => 'Т', 'notice_button_text' => 'Читать', 'notice_url' => 'https://example.com/n']));
$n_off = $R::notice_html(array_merge($o, ['notice_enabled' => 1, 'notice_title' => 'Т', 'notice_button_text' => 'Читать', 'notice_url' => 'https://example.com/n', 'notice_show_icons' => 0]));
check('notice: arrow on/off', has($n_on, 'Читать &rarr;</a>') && has($n_off, 'Читать</a>') && !has($n_off, '&rarr;'), substr(strip_tags($n_on), 0, 80));
$cd_off = $R::countdown_html(array_merge($o, ['countdown_enabled' => 1, 'countdown_date' => '2030-01-01 10:00', 'countdown_link_text' => 'Go', 'countdown_link_url' => 'https://example.com/go',
    'countdown_btn_icon' => 'download', 'countdown_show_icons' => 0]));
check('countdown: icons off -> no icon, no arrow', has($cd_off, '>Go</a>') && !has($cd_off, 'wpd-countdown__icon') && !has($cd_off, '&rarr;'));
$sl = $R::slider_html(array_merge($o, ['slider_show_icons' => 0, 'slides' => [['image' => 'https://example.com/s.jpg', 'heading' => 'Слайд', 'text' => '', 'url' => 'https://example.com/s', 'button' => 'Смотреть']]]));
$sl_on = $R::slider_html(array_merge($o, ['slides' => [['image' => 'https://example.com/s.jpg', 'heading' => 'Слайд', 'text' => '', 'url' => 'https://example.com/s', 'button' => 'Смотреть']]]));
check('slider: button arrow on/off', has($sl_on, 'Смотреть &rarr;') && has($sl, 'Смотреть</span>') && !has($sl, 'Смотреть &rarr;') && cls_has(root($sl, 'wpd-slider')[0], 'wpd-noicons'),
    substr(strip_tags($sl_on), 0, 120));
$c_on = $R::contacts_html(array_merge($o, ['contact_email' => 'a@example.com', 'contacts_button_text' => 'Написать']));
$c_off = $R::contacts_html(array_merge($o, ['contact_email' => 'a@example.com', 'contacts_button_text' => 'Написать', 'contacts_show_icons' => 0]));
check('contacts: button arrow follows show_icons', has($c_on, 'Написать &rarr;') && has($c_off, 'Написать</a>'), substr(strip_tags($c_on), 0, 100));
$hero_on = $R::hero_html(array_merge($o, ['hero_title' => 'Г', 'hero_button_text' => 'Старт', 'hero_button_url' => 'https://example.com/st', 'hero_button_icon' => 'awards']));
$hero_off = $R::hero_html(array_merge($o, ['hero_title' => 'Г', 'hero_button_text' => 'Старт', 'hero_button_url' => 'https://example.com/st', 'hero_button_icon' => 'awards', 'hero_show_icons' => 0]));
check('hero: icon and arrow on', has($hero_on, 'dashicons-awards') && has($hero_on, 'Старт &rarr;'));
check('hero: icons off', !has($hero_off, 'dashicons-awards') && !has($hero_off, 'Старт &rarr;') && has($hero_off, 'Старт</a>'));
$hcd = $R::hero_html(array_merge($o, ['hero_title' => 'Г', 'hero_show_icons' => 0, 'countdown_enabled' => 1, 'countdown_date' => '2030-01-01 10:00',
    'countdown_link_text' => 'Go', 'countdown_link_url' => 'https://example.com/go']), ['countdown' => true]);
check('hero: icons off reach hero timer button', !has($hcd, 'wpd-countdown__link') || (has($hcd, '>Go</a>') && !has($hcd, 'Go &rarr;')));
check('show_icons helper: missing key means on', $R::show_icons([], 'stats') && !$R::show_icons(['stats_show_icons' => 0], 'stats') && !$R::show_icons(['contacts_show_icons' => ''], 'contacts'));

/* ---------- 6. Блоки редактора ---------- */
$reg = WP_Block_Type_Registry::get_instance();
$okb = true; $miss = '';
foreach (WPD_Settings::BOX_BLOCKS as $slug => $keys) {
    $at = $reg->get_registered('wpd/' . $slug)->attributes ?? [];
    foreach ($keys as $k) {
        $name = WPD_Blocks::BOX_ATTR_NAMES[$k];
        if ($slug === 'contacts' && $k === 'show_icons') { continue; }
        if (!isset($at[$name])) { $okb = false; $miss .= $slug . '.' . $name . ' '; }
    }
}
check('blocks: box attributes registered', $okb, $miss);
check('blocks: attr types', ($reg->get_registered('wpd/stats')->attributes['blockMinHeight']['type'] ?? '') === 'integer'
    && ($reg->get_registered('wpd/stats')->attributes['blockVAlign']['type'] ?? '') === 'string'
    && ($reg->get_registered('wpd/hero')->attributes['showIcons']['type'] ?? '') === 'boolean');
check('blocks: box_attrs map', WPD_Blocks::box_attrs('faq') === ['blockMinHeight' => 'faq_min_h', 'blockVAlign' => 'faq_valign', 'showIcons' => 'faq_show_icons']
    && WPD_Blocks::box_attrs('hero') === ['showIcons' => 'hero_show_icons'] && WPD_Blocks::box_only_slugs() === ['hero', 'buttons', 'partners']);
$lo = WPD_Blocks::look_options_data();
check('editor data: box group', ($lo['stats']['blockMinHeight']['group'] ?? '') === 'box' && ($lo['stats']['blockMinHeight']['max'] ?? 0) === 1200
    && ($lo['faq']['blockVAlign']['options']['center'] ?? '') === 'По центру' && ($lo['hero']['showIcons']['group'] ?? '') === 'box'
    && ($lo['buttons']['blockMinHeight']['group'] ?? '') === 'box' && ($lo['partners']['blockVAlign']['group'] ?? '') === 'box' && !isset($lo['partners']['showIcons'])
    && ($lo['contacts']['showIcons']['group'] ?? '') === 'extra');
$ls = WPD_Blocks::look_settings_data(array_merge($D, ['stats_min_h' => 350, 'hero_show_icons' => 0]));
check('editor data: settings values', $ls['stats']['blockMinHeight'] === 350 && $ls['hero']['showIcons'] === false && $ls['buttons']['blockVAlign'] === '' && $ls['partners']['blockMinHeight'] === 0,
    wp_json_encode([$ls['stats']['blockMinHeight'] ?? null, $ls['hero'] ?? null]));
$x = blk('stats', ['items' => $st_items, 'blockMinHeight' => 480, 'blockVAlign' => 'center', 'showIcons' => false]);
[$c, $s] = root($x, 'wpd-stats');
check('block stats: attrs apply', cls_has($c, 'wpd-bh') && cls_has($c, 'wpd-bh--center') && cls_has($c, 'wpd-noicons') && has($s, '--wpd-bh:480px;') && !has($x, 'wpd-stat__icon'), $c);
$x = blk('docs', ['items' => $doc_items]);
check('block docs: row noicon', substr_count($x, 'wpd-doc__icon') === 1 && has($x, 'wpd-doc--noicon'));
$x = blk('buttons', ['items' => $btn_items, 'blockMinHeight' => 320, 'blockVAlign' => 'bottom']);
[$c, $s] = root($x, 'wpd-buttons');
check('block buttons: height + row noicon', cls_has($c, 'wpd-bh--bottom') && has($s, '--wpd-bh:320px;') && substr_count($x, 'wpd-qbtn__icon') === 1, $c);
$x = blk('buttons', ['items' => $btn_items, 'showIcons' => false]);
check('block buttons: icons off', !has($x, 'wpd-qbtn__icon') && cls_has(root($x, 'wpd-buttons')[0], 'wpd-noicons'));
$x = blk('cards', ['items' => $card_items, 'showIcons' => false]);
check('block cards: icons off', !has($x, 'wpd-card__icon') && !has($x, '&rarr;'));
$x = blk('hero', ['title' => 'Г', 'buttonText' => 'Старт', 'buttonUrl' => 'https://example.com/st', 'buttonIcon' => 'awards', 'showIcons' => false]);
check('block hero: icons off', has($x, 'Старт') && !has($x, 'dashicons-awards') && !has($x, 'Старт &rarr;'));
$x = blk('hero', ['title' => 'Г', 'buttonText' => 'Старт', 'buttonUrl' => 'https://example.com/st', 'buttonIcon' => 'awards']);
check('block hero: default icons on', has($x, 'dashicons-awards') && has($x, 'Старт &rarr;'));
$x = blk('partners', ['items' => [['name' => 'П1', 'logo' => '', 'url' => '']], 'blockMinHeight' => 250]);
check('block partners: height', (cls_has(root($x, 'wpd-partners')[0], 'wpd-bh') && has($x, '--wpd-bh:250px;')), root($x, 'wpd-partners')[0]);
$x = blk('countdown', ['date' => '2030-01-01 10:00', 'linkText' => 'Go', 'linkUrl' => 'https://example.com/go', 'showIcons' => false, 'blockMinHeight' => 200]);
check('block countdown: icons off + height', has($x, '>Go</a>') && cls_has(root($x, 'wpd-countdown')[0], 'wpd-bh'));
$x = blk('stats', ['items' => $st_items, 'blockMinHeight' => 0]);
check('block stats: 0 -> settings', no_new(root($x, 'wpd-stats')[0]));
set_opts(array_merge($D, ['stats_min_h' => 333]));
$x = blk('stats', ['items' => $st_items]);
check('block stats: settings height used', has($x, '--wpd-bh:333px;'));
set_opts($D);

/* ---------- 7. Панель страницы ---------- */
check('page groups: box keys overridable', in_array('stats_min_h', WPD_Page_Editor::group_keys('stats'), true) && in_array('hero_show_icons', WPD_Page_Editor::group_keys('hero'), true)
    && in_array('buttons_valign', WPD_Page_Editor::group_keys('buttons'), true) && in_array('partners_min_h', WPD_Page_Editor::group_keys('partners'), true));
$pid = (int) wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'AA panel', 'post_content' => '<p>AA</p>']);
$created[] = $pid;
set_opts(array_merge($D, ['faq' => $faq_items, 'faq_title' => 'FAQ AA']));
update_post_meta($pid, '_wpd_page', WPD_Page_Editor::sanitize(['enabled' => '1', 'order' => ['faq'], 'show' => ['faq' => '1'], 'ovg' => ['faq' => '1'],
    'ov' => ['faq_min_h' => '9999', 'faq_valign' => 'center', 'faq_show_icons' => '']], $pid));
WPD_Settings::flush_cache();
$po = WPD_Page_Editor::options($pid);
check('page options: sanitized', $po['faq_min_h'] === 1200 && $po['faq_valign'] === 'center' && empty($po['faq_show_icons']), wp_json_encode([$po['faq_min_h'], $po['faq_valign'], $po['faq_show_icons']]));
$ph = WPD_Renderer::page_html($pid, '<p>AA</p>');
[$c, $s] = root($ph, 'wpd-faq');
check('page html: faq own height and icons', cls_has($c, 'wpd-bh--center') && cls_has($c, 'wpd-noicons') && has($s, '--wpd-bh:1200px;'), $c);

/* ---------- 8. CSS, JS, справка, README ---------- */
$css = (string) file_get_contents($dir . 'assets/css/wpd.css');
check('css: height rules', has($css, '.wpd-page .wpd-bh {') && has($css, 'min-height: var(--wpd-bh);') && has($css, 'min-height: min(var(--wpd-bh), 100vh);')
    && has($css, 'align-content: end;') && has($css, 'grid-template-columns: minmax(0, 1fr);'));
check('css: noicons hides drawn icons', has($css, '.wpd-page .wpd-noicons :is(.wpd-faq__q, #wpd-none)::after') && has($css, '.wpd-page :is(a.wpd-qbtn--noicon, #wpd-none)::after')
    && has($css, '.wpd-page :is(.wpd-doc--noicon, #wpd-none)::after'));
$js = (string) file_get_contents($dir . 'assets/js/wpd-blocks.js');
check('wpd-blocks.js: helpers', has($js, 'function boxLook(slug, a)') && has($js, 'function showIcons(slug, a)') && has($js, "'Высота и иконки'") && has($js, "only === 'box'"));
check('wpd-blocks.js: panels for hero, buttons, partners', has($js, "LookPanels('hero', a, set, 'box')") && has($js, "LookPanels('buttons', a, set, 'box')") && has($js, "LookPanels('partners', a, set, 'box')"));
check('wpd-blocks.js: row flags', has($js, "noiconLabel: 'без значка'") && has($js, "noiconLabel: 'без иконки и стрелки'") && has($js, "label: 'без иконки', onChange: function (v) { patch({ noicon: v }); }"));
$rh = new ReflectionMethod('WPD_Settings', 'render_help');
$rh->setAccessible(true);
ob_start();
$rh->invoke(null);
$help = (string) ob_get_clean();
check('help: section 2.13.0', has($help, 'Высота блоков и иконки (2.13.0)') && has($help, 'Минимальная высота блока') && has($help, 'Без иконки'));
$readme = (string) file_get_contents($dir . 'README.md');
check('README: 2.13.0 section', has($readme, 'Высота блоков и иконки (2.13.0)'));

/* ---------- Уборка ---------- */
foreach ($created as $id) { wp_delete_post($id, true); }
if (is_array($backup)) { set_opts($backup); } else { delete_option('wpd_settings'); WPD_Settings::flush_cache(); }
check('cleanup', !get_post($pid));

echo implode("\n", $results) . "\n";
echo "TOTAL: " . count($results) . ", FAILED: $fails\n";
