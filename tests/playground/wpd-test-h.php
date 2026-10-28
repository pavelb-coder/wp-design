<?php
// Фаза H (1.5.0): компоненты в духе «Нодес» — слайдер, кнопки-ссылки, карточки, объявление, новости, контакты, палитры.
// PHP-часть: санитизация, вывод, шорткоды, блоки, панель страницы, шаблон главной. В конце возвращает опцию к значениям по умолчанию.
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
    update_option('wpd_settings', $values);
    WPD_Settings::flush_cache();
}

// Санитизация регистрируется на admin_init — во фронтовом скрипте подключаем её сами
WPD_Settings::register();
wp_set_current_user(1);
delete_option('wpd_settings');
WPD_Settings::flush_cache();

check('version >= 1.5.0', version_compare(WPD_VERSION, '1.5.0', '>='));

/* 1. Поля и вкладки */
$map = WPD_Settings::field_map();
check('253 fields', count($map) === 253, (string) count($map));
foreach (['slider', 'buttons', 'cards', 'notice', 'news', 'contacts'] as $tab) {
    check("tab $tab exists", isset(WPD_Settings::tabs()[$tab]));
}
check('repeaters: slides 10, buttons 12, cards 8',
    $map['slides']['max'] === 10 && $map['buttons']['max'] === 12 && $map['cards']['max'] === 8);
check('palette default = custom (existing colors kept)', $map['palette']['default'] === 'custom');
check('46 icons', count(WPD_Settings::icons()) === 46, (string) count(WPD_Settings::icons()));
$d = WPD_Settings::get();
check('defaults: no slides, notice, contacts', $d['slides'] === [] && $d['notice_title'] === '' && $d['contact_phone'] === '');
check('defaults: demo buttons and cards', count($d['buttons']) === 4 && count($d['cards']) === 3);
check('defaults: news 6 carousel', $d['news_count'] === 6 && $d['news_layout'] === 'carousel');

/* 2. Санитизация повторителей */
$rows = WPD_Settings::sanitize_rows([
    ['image' => 'javascript:alert(1)', 'heading' => '<b>Заголовок</b>', 'text' => "Строка 1\nСтрока 2", 'url' => 'https://example.com/a', 'new_tab' => 'on'],
    ['image' => '', 'heading' => '', 'text' => '', 'url' => '', 'new_tab' => '1'],
    'мусор',
    ['heading' => 'Второй', 'new_tab' => '0'],
], 'slides');
check('rows: empty row and garbage dropped', count($rows) === 2, (string) count($rows));
check('rows: javascript: url removed', $rows[0]['image'] === '');
check('rows: tags stripped from heading', $rows[0]['heading'] === 'Заголовок');
check('rows: textarea keeps line breaks', $rows[0]['text'] === "Строка 1\nСтрока 2");
check('rows: checkbox 1/0', $rows[0]['new_tab'] === 1 && $rows[1]['new_tab'] === 0);
check('rows: missing keys filled', $rows[1]['url'] === '' && $rows[1]['image'] === '');
check('rows: capped at max', count(WPD_Settings::sanitize_rows(array_fill(0, 15, ['heading' => 'x']), 'slides')) === 10);
check('rows: non-array -> []', WPD_Settings::sanitize_rows('строка', 'slides') === []);
check('rows: template row __i__ ignored', count(WPD_Settings::sanitize_rows(['__i__' => ['heading' => 'Шаблон'], 0 => ['heading' => 'A']], 'slides')) === 1);
check('rows: unknown key -> []', WPD_Settings::sanitize_rows([['a' => 1]], 'nope') === []);
$b = WPD_Settings::sanitize_rows([['icon' => 'evil"><script>', 'text' => 'Кнопка'], ['icon' => 'awards', 'text' => 'Ок', 'small' => '1']], 'buttons');
check('buttons: unknown icon -> first icon', $b[0]['icon'] === array_key_first(WPD_Settings::icons()));
check('buttons: known icon kept, small flag', $b[1]['icon'] === 'awards' && $b[1]['small'] === 1);
$c = WPD_Settings::sanitize_rows([['title' => 'К', 'color' => 'red'], ['title' => 'Л', 'color' => '#0e7490']], 'cards');
check('cards: invalid color -> empty', $c[0]['color'] === '');
check('cards: hex color kept', $c[1]['color'] === '#0e7490');

/* 3. Санитизация остальных полей */
$cat = wp_insert_term('Новости H', 'category');
$cat_id = is_wp_error($cat) ? (int) get_term_by('name', 'Новости H', 'category')->term_id : (int) $cat['term_id'];
$s = WPD_Settings::sanitize([
    'slider_height' => '5000', 'news_count' => '100', 'news_layout' => 'masonry', 'notice_layout' => 'bad',
    'notice_content' => '<p>Текст <strong>жирный</strong></p><script>alert(1)</script>', 'notice_color' => 'blue',
    'contact_email' => 'не почта', 'news_category' => (string) $cat_id, 'palette' => 'sea', 'primary' => '#000000',
]);
check('slider height clamped to 900', $s['slider_height'] === 900, (string) $s['slider_height']);
check('news count clamped to 24', $s['news_count'] === 24, (string) $s['news_count']);
check('news layout whitelist', $s['news_layout'] === 'carousel');
check('notice layout whitelist', $s['notice_layout'] === 'image_text');
check('notice content: kses keeps strong, drops script', has($s['notice_content'], '<strong>жирный</strong>') && !has($s['notice_content'], 'script'));
check('notice color invalid -> empty', $s['notice_color'] === '');
check('contact email invalid -> empty', $s['contact_email'] === '');
check('news category existing id kept', $s['news_category'] === $cat_id);
check('news category unknown id -> 0', WPD_Settings::sanitize(['news_category' => '999999'])['news_category'] === 0);
check('palette sea applies its colors', $s['primary'] === '#0e7490' && $s['bg'] === '#ecfeff', $s['primary']);
$s2 = WPD_Settings::sanitize(['palette' => 'custom', 'primary' => '#123456']);
check('palette custom keeps own colors', $s2['primary'] === '#123456');
check('palette unknown -> custom', WPD_Settings::sanitize(['palette' => 'neon'])['palette'] === 'custom');
$s3 = WPD_Settings::sanitize(['_tab' => 'slider', 'slides' => [['heading' => 'С вкладки']], 'slider_autoplay' => '1']);
check('tab slider: slides saved, unchecked boxes -> 0', $s3['slides'][0]['heading'] === 'С вкладки' && $s3['slider_arrows'] === 0 && $s3['slider_autoplay'] === 1);
set_opts(['primary' => '#0f766e', 'palette' => 'custom']);
$s4 = WPD_Settings::sanitize(['_tab' => 'slider', 'slides' => []]);
check('tab slider: colors of other tab untouched', $s4['primary'] === '#0f766e');

/* 4. Вывод компонентов */
$o = WPD_Settings::get();
check('slider: no slides -> empty', WPD_Renderer::slider_html($o) === '');
$slides = [
    ['image' => 'https://example.com/1.jpg', 'heading' => 'Первый', 'text' => 'Текст', 'url' => 'https://example.com/go', 'new_tab' => 1],
    ['image' => '', 'heading' => 'Без картинки', 'text' => '', 'url' => '', 'new_tab' => 0],
    ['image' => 'https://example.com/3.jpg', 'heading' => '', 'text' => '', 'url' => '', 'new_tab' => 0],
];
$h = WPD_Renderer::slider_html($o, ['items' => $slides, 'height' => 50, 'autoplay' => 1, 'arrows' => 1, 'dots' => 0, 'mobile' => 0, 'mobile_headings' => 1, 'mobile_texts' => 0]);
check('slider: carousel attrs', has($h, 'data-wpd-carousel="slider"') && has($h, 'data-autoplay="6000"') && has($h, ' data-arrows') && !has($h, 'data-dots'));
check('slider: 3 slides', substr_count($h, 'aria-roledescription="slide"') === 3);
check('slider: height clamped to 200', has($h, '--wpd-slider-h:200px'));
check('slider: slide without image marked', has($h, 'wpd-slide wpd-slide--noimg'));
check('slider: first image eager, others lazy', has($h, '1.jpg" alt="">') && has($h, '3.jpg" alt="" loading="lazy"'));
check('slider: link in new tab with rel', has($h, 'href="https://example.com/go" target="_blank" rel="noopener noreferrer"'));
check('slider: mobile classes', has($h, 'wpd-slider--hide-mobile') && has($h, 'wpd-slider--no-mobile-texts') && !has($h, 'no-mobile-headings'));
check('slider: controls hidden until JS', has($h, 'data-wpd-prev hidden') && has($h, 'data-wpd-dots hidden'));
check('slider: aria roles', has($h, 'aria-roledescription="carousel"') && has($h, 'aria-label="2 из 3"'));
$one = WPD_Renderer::slider_html($o, ['items' => [$slides[0]], 'autoplay' => 0]);
check('slider: one slide -> no controls, no autoplay', !has($one, 'data-wpd-prev') && !has($one, 'data-autoplay'));
check('slider: heading escaped', has(WPD_Renderer::slider_html($o, ['items' => [['heading' => '<i>x</i>']]]), '<h2 class="wpd-slide__title">x</h2>'));

$bh = WPD_Renderer::buttons_html($o, [['icon' => 'awards', 'text' => 'Итоги', 'url' => 'https://example.com/r', 'new_tab' => 0, 'small' => 1], ['icon' => 'book', 'text' => 'Без ссылки', 'url' => '']]);
check('buttons: link with dashicon', has($bh, '<a class="wpd-qbtn wpd-qbtn--small" href="https://example.com/r">') && has($bh, 'dashicons-awards'));
check('buttons: item without url is a span', has($bh, '<span class="wpd-qbtn wpd-qbtn--nolink">'));
check('buttons: rows without text skipped -> empty', WPD_Renderer::buttons_html($o, [['icon' => 'book', 'url' => 'https://x.test']]) === '');
check('buttons: dashicons enqueued', wp_style_is('dashicons', 'enqueued'));

$ch = WPD_Renderer::cards_html($o, [['title' => 'Цвет', 'color' => '#0e7490', 'url' => ''], ['title' => 'Ссылка', 'url' => 'https://example.com/c', 'image' => 'https://example.com/c.jpg']], 'Разделы');
check('cards: title and grid', has($ch, '<h2 class="wpd-h2">Разделы</h2>') && has($ch, 'wpd-cards__grid'));
check('cards: own color via custom property', has($ch, '<li class="wpd-card has-color" style="--wpd-card:#0e7490">'));
check('cards: link with arrow and image', has($ch, 'wpd-card__more') && has($ch, 'src="https://example.com/c.jpg"'));
check('cards: empty title -> no heading', !has(WPD_Renderer::cards_html($o, [['title' => 'A']], ''), 'wpd-h2'));
check('cards: no rows -> empty', WPD_Renderer::cards_html($o, []) === '');

check('notice: empty -> no output', WPD_Renderer::notice_html($o) === '');
$nh = WPD_Renderer::notice_html($o, ['layout' => 'image_text', 'image' => 'https://example.com/n.jpg', 'pre_title' => 'Важно', 'title' => 'Заголовок', 'content' => '<p>Текст</p>', 'url' => 'https://example.com/n', 'color' => '#fef3c7']);
check('notice: image+text layout', has($nh, 'wpd-notice--image-text') && has($nh, 'wpd-notice__media') && has($nh, 'wpd-notice__body'));
check('notice: background color', has($nh, '--wpd-notice-bg:#fef3c7'));
check('notice: title link', has($nh, '<h2 class="wpd-notice__title"><a href="https://example.com/n">Заголовок</a></h2>'));
check('notice: image_text without image falls back to text', has(WPD_Renderer::notice_html($o, ['title' => 'Т']), 'wpd-notice--text'));
check('notice: image_text without text falls back to image', has(WPD_Renderer::notice_html($o, ['image' => 'https://example.com/n.jpg']), 'wpd-notice--image'));
check('notice: image layout without image -> empty', WPD_Renderer::notice_html($o, ['layout' => 'image', 'title' => 'Т']) === '');
check('notice: script stripped', !has(WPD_Renderer::notice_html($o, ['title' => 'Т', 'content' => '<script>x()</script>Ок']), '<script'));

// Новости. 2.7.0: set_opts() сохраняет опцию полной санитизацией, где неотправленный флажок = 0,
// поэтому новые флажки показа (по умолчанию включены) задаём явно
$o = array_merge($o, ['news_show_image' => 1, 'news_show_date' => 1, 'news_show_excerpt' => 1]);
$other = wp_insert_post(['post_title' => 'Чужая запись', 'post_status' => 'publish', 'post_content' => 'вне рубрики']);
$news_ids = [];
for ($i = 1; $i <= 3; $i++) {
    $news_ids[] = wp_insert_post(['post_title' => "Новость H$i", 'post_status' => 'publish', 'post_category' => [$cat_id],
        'post_content' => '<!-- wp:paragraph --><p>Текст новости ' . $i . ' [wpd_news] с шорткодом</p><!-- /wp:paragraph -->',
        'post_date' => date('Y-m-d H:i:s', time() - $i * 3600)]);
}
wp_update_post(['ID' => $news_ids[2], 'post_excerpt' => 'Свой анонс']);
$nw = WPD_Renderer::news_html($o, ['category' => $cat_id, 'count' => 2, 'layout' => 'carousel', 'title' => 'Новости', 'more' => 'Все новости']);
check('news: carousel attrs', has($nw, 'wpd-news--carousel') && has($nw, 'data-wpd-carousel="news" data-arrows'));
check('news: count limit', substr_count($nw, '<article class="wpd-news__item">') === 2);
check('news: newest first', strpos($nw, 'Новость H1') < strpos($nw, 'Новость H2') && !has($nw, 'Новость H3'));
check('news: category filter', !has($nw, 'Чужая запись'));
check('news: more link to category', has($nw, 'href="' . esc_url(get_category_link($cat_id)) . '"') && has($nw, 'Все новости &rarr;'));
check('news: shortcodes stripped from excerpt', !has($nw, '[wpd_news]') && has($nw, 'Текст новости 1'));
check('news: placeholder without thumbnail', has($nw, 'wpd-news__ph'));
check('news: arrows hidden until JS', has($nw, 'data-wpd-next hidden'));
$all = WPD_Renderer::news_html($o, ['category' => $cat_id, 'count' => 5, 'layout' => 'grid', 'more' => '']);
check('news: grid without carousel attrs', has($all, 'wpd-news--grid') && !has($all, 'data-wpd-carousel') && !has($all, 'data-wpd-next'));
check('news: manual excerpt used', has($all, 'Свой анонс'));
check('news: empty more -> no link', !has($all, 'wpd-news__more'));
$f = function ($q) { $q['post__in'] = [0]; return $q; };
add_filter('wpd_news_query', $f);
check('news: filter wpd_news_query applied; no posts -> empty', WPD_Renderer::news_html($o, ['category' => $cat_id]) === '');
remove_filter('wpd_news_query', $f);

check('contacts: empty -> no output', WPD_Renderer::contacts_html($o) === '');
$ct = WPD_Renderer::contacts_html($o, ['title' => 'Контакты', 'address' => 'ул. Ленина, 1', 'city' => 'Красноярск', 'phone' => '+7 (391) 200-00-00',
    'email' => 'olymp@example.com', 'map' => 'https://yandex.ru/maps/x', 'page' => '', 'code' => 'ИНН 1']);
check('contacts: tel link normalized', has($ct, 'href="tel:+73912000000"'));
check('contacts: mailto link', has($ct, 'href="mailto:olymp@example.com"'));
check('contacts: address links to map in new tab', has($ct, 'href="https://yandex.ru/maps/x" target="_blank"') && has($ct, 'ул. Ленина, 1<br>Красноярск'));
check('contacts: button falls back to mailto', has($ct, 'wpd-contacts__btn" href="mailto:olymp@example.com"'));
check('contacts: code row', has($ct, 'wpd-contacts__code'));
check('contacts: page url wins for button', has(WPD_Renderer::contacts_html($o, ['phone' => '1', 'page' => 'https://example.com/contact']), 'wpd-contacts__btn" href="https://example.com/contact"'));

/* 5. Шорткоды из общих настроек */
set_opts(['slides' => $slides, 'notice_title' => 'Объявление H', 'contact_phone' => '8 800 000', 'news_category' => $cat_id]);
foreach (['slider', 'buttons', 'cards', 'notice', 'news', 'contacts'] as $part) {
    check("shortcode wpd_$part registered", shortcode_exists('wpd_' . $part));
    $out = do_shortcode('[wpd_' . $part . ']');
    check("shortcode wpd_$part renders inside .wpd-page", has($out, 'class="wpd-page') && has($out, 'wpd-' . ($part === 'buttons' ? 'buttons' : $part)), substr($out, 0, 120));
}
check('shortcode slider uses settings', has(do_shortcode('[wpd_slider]'), 'Без картинки'));
set_opts(['buttons' => []]);
check('shortcode with empty data -> empty string', do_shortcode('[wpd_buttons]') === '');

/* 6. Блоки: атрибуты важнее настроек, отсутствующий атрибут — из настроек */
set_opts(['notice_title' => 'Из настроек', 'notice_content' => '<p>Текст из настроек</p>', 'cards_title' => 'Разделы из настроек', 'news_category' => $cat_id]);
$reg = WP_Block_Type_Registry::get_instance();
foreach (['slider', 'buttons', 'cards', 'notice', 'news', 'contacts'] as $part) {
    $bt = $reg->get_registered('wpd/' . $part);
    check("block wpd/$part registered (dynamic)", $bt && $bt->is_dynamic());
    $defaults = $bt ? array_filter(array_map(function ($a) { return array_key_exists('default', $a); }, $bt->attributes)) : ['?'];
    check("block wpd/$part: no server defaults", !$defaults, implode(',', array_keys($defaults)));
}
$r = render_block(['blockName' => 'wpd/notice', 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []]);
check('block notice: absent attrs -> settings', has($r, 'Из настроек') && has($r, 'wpd-page--notice'));
$r = render_block(['blockName' => 'wpd/notice', 'attrs' => ['title' => 'Свой', 'preTitle' => ''], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []]);
check('block notice: attr wins', has($r, '>Свой<') && !has($r, 'Из настроек'));
$r = render_block(['blockName' => 'wpd/notice', 'attrs' => ['title' => '', 'content' => '', 'preTitle' => ''], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []]);
check('block notice: empty attrs win -> nothing', !has($r, 'wpd-notice'));
$r = render_block(['blockName' => 'wpd/cards', 'attrs' => ['title' => '', 'items' => [['title' => 'Только блок']]], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []]);
check('block cards: own items, empty title', has($r, 'Только блок') && !has($r, 'Разделы из настроек'));
$r = render_block(['blockName' => 'wpd/slider', 'attrs' => ['items' => $slides, 'autoplay' => false, 'align' => 'full'], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []]);
check('block slider: autoplay off by attr, align class', !has($r, 'data-autoplay') && has($r, 'alignfull'));
$r = render_block(['blockName' => 'wpd/news', 'attrs' => ['count' => 1, 'layout' => 'grid'], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []]);
check('block news: attrs count/layout', substr_count($r, 'wpd-news__item') === 1 && has($r, 'wpd-news--grid'));
$r = render_block(['blockName' => 'wpd/contacts', 'attrs' => ['email' => 'b@example.com'], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []]);
check('block contacts: attr email', has($r, 'mailto:b@example.com'));

/* 7. Шаблон «Главная страница из компонентов» */
$pat = WP_Block_Patterns_Registry::get_instance()->get_registered('wpd/front-page');
check('pattern wpd/front-page registered', (bool) $pat);
if ($pat) {
    foreach (['slider', 'buttons', 'notice', 'cards', 'news', 'contacts'] as $part) {
        check("pattern contains wpd/$part", has($pat['content'], '<!-- wp:wpd/' . $part));
    }
    $parsed = parse_blocks($pat['content']);
    check('pattern parses to blocks', count(array_filter($parsed, function ($b) { return $b['blockName']; })) >= 6);
}

/* 8. Панель страницы: новые компоненты */
set_opts(['slides' => $slides, 'buttons' => [['icon' => 'book', 'text' => 'Кнопка панели', 'url' => '#']], 'contact_email' => 'p@example.com']);
$pid = wp_insert_post(['post_title' => 'Панель H', 'post_status' => 'publish', 'post_type' => 'page', 'post_content' => '<p>Текст</p>']);
update_post_meta($pid, '_wpd_enabled', 1);
$html = WPD_Renderer::page_html($pid, '<p>Текст</p>');
check('panel default: new components hidden', !has($html, 'wpd-slider') && !has($html, 'Кнопка панели') && !has($html, 'wpd-contacts'));
update_post_meta($pid, '_wpd_page', ['order' => array_keys(WPD_Page_Editor::blocks()), 'hidden' => ['countdown']]);
$html = WPD_Renderer::page_html($pid, '<p>Текст</p>');
check('panel shown: slider before hero', has($html, 'wpd-slider') && strpos($html, 'wpd-slider') < strpos($html, 'wpd-hero'));
check('panel shown: buttons and contacts', has($html, 'Кнопка панели') && has($html, 'mailto:p@example.com'));
check('panel shown: contacts last', strrpos($html, 'wpd-contacts') > strrpos($html, 'wpd-faq'));
check('panel: blocks() has 13 entries with titles', count(WPD_Page_Editor::blocks()) === 13 && WPD_Page_Editor::blocks()['news'] !== '');

/* 9. Справка */
ob_start();
$_GET = ['page' => 'wpd-settings', 'tab' => 'help'];
WPD_Settings::render_page();
$help = ob_get_clean();
$_GET = [];
foreach (['[wpd_slider]', '[wpd_buttons]', '[wpd_cards]', '[wpd_notice]', '[wpd_news]', '[wpd_contacts]'] as $sc) {
    check("help lists $sc", has($help, $sc));
}
check('help: front page section', has($help, 'Главная страница из компонентов'));

// Уборка
wp_delete_post($pid, true);
wp_delete_post($other, true);
foreach ($news_ids as $id) { wp_delete_post($id, true); }
wp_delete_term($cat_id, 'category');
delete_option('wpd_settings');

echo implode("\n", $results), "\n\nTOTAL: ", count($results), ", FAILED: ", $fails, "\n";
