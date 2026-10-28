<?php
// Фаза U (2.7.0): параметры остальных блоков — «Раскладка и вид» (колонки, отступ, выравнивание, вид, скругление,
// размер текста, фон, акцент, цвет текста) у статистики, документов, FAQ, слайдера, карточек, объявления, новостей,
// контактов и таймера; свои параметры блоков; поля элементов (иконка, цвет, метка, подпись, флажки, скрытие, даты);
// санитизация и перевод старых текстовых списков; разметка рендерера (без параметров — как раньше); атрибуты блоков
// (блок важнее страницы, страница — общих настроек); группы панели страницы и строки списков; данные редактора;
// CSS, JS, справка, версия. В конце удаляет свои записи и возвращает опцию.
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
function blk(string $name, array $attrs = []): string {
    return render_block(['blockName' => 'wpd/' . $name, 'attrs' => $attrs, 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []]);
}
/** Класс и style первого тега с классом $base. */
function root(string $html, string $base): array {
    if (!preg_match('/<(?:section|div) class="(' . preg_quote($base, '/') . '(?: [^"]*)?)"(?: style="([^"]*)")?/', $html, $m)) {
        return ['', ''];
    }
    return [$m[1], html_entity_decode($m[2] ?? '')];
}
function new_page(string $title): int {
    return (int) wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => '<p>U</p>']);
}

$backup = get_option('wpd_settings');
wp_set_current_user(1);
$created = [];
$D = WPD_Settings::defaults();
set_opts($D);
$o = WPD_Settings::get();

/* 1. Поля */
$map = WPD_Settings::field_map();
$blocks = ['stats', 'docs', 'faq', 'cards', 'news', 'notice', 'slider', 'countdown', 'contacts'];
check('LOOK_BLOCKS: nine blocks', array_keys(WPD_Settings::LOOK_BLOCKS) === ['stats', 'docs', 'faq', 'cards', 'news', 'notice', 'slider', 'countdown', 'contacts']);
$missing = [];
foreach (WPD_Settings::LOOK_BLOCKS as $slug => $keys) {
    foreach ($keys as $k) {
        if (!isset($map[$slug . '_' . $k])) {
            $missing[] = $slug . '_' . $k;
        }
    }
}
check('look fields exist for every block', !$missing, implode(',', $missing));
$look_count = array_sum(array_map('count', WPD_Settings::LOOK_BLOCKS));
check('look fields: 77', $look_count === 77, (string) $look_count);
$sections = WPD_Settings::sections();
foreach (['stats_look', 'docs_look', 'cards_look', 'news_look', 'contacts_look'] as $sec) {
    check("section $sec titled «Раскладка и вид»", ($sections[$sec] ?? '') === 'Раскладка и вид');
}
foreach (['faq_look', 'slider_look', 'notice_look'] as $sec) {
    check("section $sec titled «Вид»", ($sections[$sec] ?? '') === 'Вид');
}
check('section countdown_look «Вид таймера»', ($sections['countdown_look'] ?? '') === 'Вид таймера');
$tab_of = [];
foreach (WPD_Settings::tabs() as $id => $t) {
    foreach ($t['sections'] as $s) {
        $tab_of[$s] = $id;
    }
}
check('look sections on their tabs', ($tab_of['stats_look'] ?? '') === 'stats' && ($tab_of['docs_look'] ?? '') === 'docs' && ($tab_of['faq_look'] ?? '') === 'faq'
    && ($tab_of['slider_look'] ?? '') === 'slider' && ($tab_of['cards_look'] ?? '') === 'cards' && ($tab_of['notice_look'] ?? '') === 'notice'
    && ($tab_of['news_look'] ?? '') === 'news' && ($tab_of['contacts_look'] ?? '') === 'contacts' && ($tab_of['countdown_look'] ?? '') === 'features');
check('variant options', array_keys($map['stats_variant']['options']) === ['', 'card', 'outline', 'solid', 'tint', 'plain']);
check('radius options', array_keys($map['faq_radius']['options']) === ['', 'none', 'small', 'medium', 'large'] && WPD_Settings::LOOK_RADIUS === ['none' => 0, 'small' => 6, 'medium' => 12, 'large' => 20]);
$keys = function (string $k) use ($map) { return array_map('strval', array_keys($map[$k]['options'])); };
check('columns: 1–6, contacts 1–4', $keys('cards_columns') === ['', '1', '2', '3', '4', '5', '6'] && $keys('contacts_columns') === ['', '1', '2', '3', '4']);
check('columns mobile 1–3', $keys('news_columns_mobile') === ['', '1', '2', '3']);
check('number bounds', $map['docs_gap']['max'] === 48 && $map['docs_text_size']['max'] === 28 && $map['stats_num_size']['max'] === 96
    && $map['cards_image_height']['max'] === 480 && $map['countdown_value_size']['max'] === 64 && $map['slider_interval']['min'] === 2 && $map['slider_interval']['max'] === 30);
check('colors are color_opt', $map['stats_bg']['type'] === 'color_opt' && $map['notice_accent']['type'] === 'color_opt' && $map['slider_text_color']['type'] === 'color_opt');
check('notice has no bg (own color)', !isset($map['notice_bg']) && isset($map['notice_color']));
check('slider has no variant/columns', !isset($map['slider_variant']) && !isset($map['slider_columns']));
check('faq has no columns', !isset($map['faq_columns']) && isset($map['faq_gap']));
check('look defaults empty', $D['stats_variant'] === '' && $D['docs_columns'] === '' && $D['faq_gap'] === 0 && $D['cards_bg'] === '' && $D['countdown_value_size'] === 0);
check('extra defaults', $D['docs_show_type'] === 1 && $D['news_show_image'] === 1 && $D['news_show_date'] === 1 && $D['news_show_excerpt'] === 1
    && $D['contacts_show_icons'] === 1 && $D['contacts_button_text'] === 'Связаться' && $D['slider_interval'] === 6 && $D['slider_caption_pos'] === 'bottom'
    && $D['news_excerpt_words'] === 20 && $D['faq_single'] === 0 && $D['faq_numbered'] === 0 && $D['docs_new_tab'] === 0);
check('repeaters: stats, docs, faq', $map['stats']['type'] === 'repeater' && $map['docs']['type'] === 'repeater' && $map['faq']['type'] === 'repeater');
check('stats row keys', array_keys($map['stats']['fields']) === ['num', 'label', 'icon', 'color', 'hidden', 'from', 'to']);
// 2.8.0: + служебные src и nf (строки из сетевой папки)
check('docs row keys', array_slice(array_keys($map['docs']['fields']), 0, 11) === ['title', 'url', 'desc', 'badge', 'type', 'color', 'new_tab', 'download', 'hidden', 'from', 'to']);
check('faq row keys', array_keys($map['faq']['fields']) === ['q', 'a', 'open', 'hidden', 'from', 'to']);
check('slides row: badge, button, hidden, dates', !array_diff(['badge', 'button', 'hidden', 'from', 'to'], array_keys($map['slides']['fields'])));
check('cards row: icon, badge, more, hidden, dates', !array_diff(['icon', 'badge', 'more', 'hidden', 'from', 'to'], array_keys($map['cards']['fields'])));
check('look_keys(stats)', WPD_Settings::look_keys('stats') === array_merge(array_map(function ($k) { return 'stats_' . $k; }, WPD_Settings::LOOK_BLOCKS['stats']), ['stats_num_size']),
    implode(',', WPD_Settings::look_keys('stats')));

/* 2. Санитизация */
$s = WPD_Settings::sanitize(['_tab' => 'stats', 'stats_columns' => '9', 'stats_columns_mobile' => '2', 'stats_gap' => '99', 'stats_align' => 'justify',
    'stats_variant' => 'tint', 'stats_radius' => 'huge', 'stats_text_size' => '-4', 'stats_bg' => 'red', 'stats_accent' => '#F97316', 'stats_text_color' => '#fff',
    'stats_title' => '<b>Цифры</b>', 'stats_num_size' => '500',
    'stats' => ['_' => '', ['num' => '300+', 'label' => 'школ', 'icon' => 'groups', 'color' => '#0b1220', 'hidden' => '1', 'from' => '2030-03-01T10:00', 'to' => 'завтра', 'evil' => 'x'],
                ['num' => '', 'label' => ''], ['num' => '5', 'label' => 'x', 'icon' => 'not-an-icon', 'color' => 'blue']]]);
check('sanitize: columns whitelist', $s['stats_columns'] === '' && $s['stats_columns_mobile'] === '2');
check('sanitize: gap clamped', (int) $s['stats_gap'] === 48, (string) $s['stats_gap']);
check('sanitize: align/radius whitelist', $s['stats_align'] === '' && $s['stats_radius'] === '');
check('sanitize: variant kept', $s['stats_variant'] === 'tint');
check('sanitize: text size negative -> 0', (int) $s['stats_text_size'] === 0);
check('sanitize: colors', $s['stats_bg'] === '' && strtolower($s['stats_accent']) === '#f97316' && $s['stats_text_color'] === '#fff');
check('sanitize: title stripped', $s['stats_title'] === 'Цифры', $s['stats_title']);
check('sanitize: num size clamped', (int) $s['stats_num_size'] === 96, (string) $s['stats_num_size']);
check('sanitize: rows — empty removed, extra keys dropped', count($s['stats']) === 2 && array_keys($s['stats'][0]) === ['num', 'label', 'icon', 'color', 'hidden', 'from', 'to']);
check('sanitize: row values', $s['stats'][0]['icon'] === 'groups' && $s['stats'][0]['color'] === '#0b1220' && $s['stats'][0]['hidden'] === 1
    && $s['stats'][0]['from'] === '2030-03-01 10:00' && $s['stats'][0]['to'] === '', json_encode($s['stats'][0], JSON_UNESCAPED_UNICODE));
check('sanitize: unknown icon and bad color dropped', $s['stats'][1]['icon'] === '' && $s['stats'][1]['color'] === '');
$s = WPD_Settings::sanitize(['_tab' => 'docs', 'docs' => ['_' => '', ['title' => 'Положение', 'url' => 'javascript:alert(1)', 'type' => 'DOCX-LONG', 'new_tab' => 'on', 'download' => '1'],
    ['title' => 'Сайт', 'url' => 'https://example.com/', 'desc' => '<i>подпись</i>', 'badge' => 'Новое']]]);
check('sanitize docs: js url emptied, row kept', count($s['docs']) === 2 && $s['docs'][0]['url'] === '');
check('sanitize docs: checkboxes 1', $s['docs'][0]['new_tab'] === 1 && $s['docs'][0]['download'] === 1 && $s['docs'][1]['new_tab'] === 0);
check('sanitize docs: desc stripped', $s['docs'][1]['desc'] === 'подпись' && $s['docs'][1]['badge'] === 'Новое');
$s = WPD_Settings::sanitize(['_tab' => 'faq', 'faq_single' => '1', 'faq' => ['_' => '', ['q' => 'Вопрос', 'a' => '<a href="https://x.ru" onclick="y()">x</a><script>z</script>', 'open' => '1'], ['q' => '', 'a' => '']]]);
check('sanitize faq: rows, answer kses', count($s['faq']) === 1 && has($s['faq'][0]['a'], '<a href="https://x.ru">') && !has($s['faq'][0]['a'], 'onclick') && !has($s['faq'][0]['a'], '<script'));
check('sanitize faq: open, single', $s['faq'][0]['open'] === 1 && $s['faq_single'] === 1 && $s['faq_numbered'] === 0);
$s = WPD_Settings::sanitize(['_tab' => 'slider', 'slider_caption_pos' => 'left', 'slider_interval' => '1']);
check('sanitize slider: caption whitelist -> default, interval min', $s['slider_caption_pos'] === 'bottom' && (int) $s['slider_interval'] === 2, $s['slider_caption_pos'] . '|' . $s['slider_interval']);
$s = WPD_Settings::sanitize(['_tab' => 'notice', 'notice_from' => '2030-05-01T09:00', 'notice_to' => 'bad', 'notice_button_text' => '<b>Читать</b>']);
check('sanitize notice: dates, button text', $s['notice_from'] === '2030-05-01 09:00' && $s['notice_to'] === '' && $s['notice_button_text'] === 'Читать');
// Старые текстовые списки
check('legacy stats text', WPD_Settings::stats_items("300+|школ\n12–18|возраст")[1]['label'] === 'возраст');
check('legacy docs text', WPD_Settings::docs_items("Положение|https://example.com/p.pdf")[0]['url'] === 'https://example.com/p.pdf');
$lf = WPD_Settings::faq_items("Q1\nA1\n\nQ2\nA2");
check('legacy faq text', count($lf) === 2 && $lf[1]['q'] === 'Q2' && $lf[1]['a'] === 'A2');
check('legacy: rows pass through', WPD_Settings::stats_items([['num' => '1', 'label' => 'a']])[0]['num'] === '1');
$s = WPD_Settings::sanitize(['_tab' => 'faq', 'faq' => "Старый вопрос\nСтарый ответ"]);
check('legacy text through sanitize -> rows', ($s['faq'][0]['q'] ?? '') === 'Старый вопрос' && ($s['faq'][0]['a'] ?? '') === 'Старый ответ' && $s['faq'][0]['open'] === 0);

/* 3. block_look() */
$look = function (string $slug, array $v) use ($D) { return WPD_Renderer::block_look(array_merge($D, $v), $slug); };
$l = $look('stats', []);
check('look: nothing set -> bare class, no style', $l === ['class' => 'wpd-look', 'style' => ''] && WPD_Renderer::look_class($l) === '');
$l = $look('stats', ['stats_columns' => '4', 'stats_columns_mobile' => '2', 'stats_gap' => 24, 'stats_align' => 'center', 'stats_variant' => 'outline', 'stats_radius' => 'large',
    'stats_text_size' => 18, 'stats_bg' => '#0b1220', 'stats_accent' => '#f97316', 'stats_text_color' => '#ffffff']);
check('look: all classes', $l['class'] === 'wpd-look wpd-look--cols wpd-look--cols-m wpd-look--gap wpd-look--align-center wpd-look--outline wpd-look--radius wpd-look--fs wpd-look--bg wpd-look--accent wpd-look--color wpd-look--ondark', $l['class']);
check('look: all vars', $l['style'] === '--wpd-bl-cols:4;--wpd-bl-cols-t:2;--wpd-bl-cols-m:2;--wpd-bl-gap:24px;--wpd-radius:20px;--wpd-bl-fs:18px;--wpd-bl-bg:#0b1220;--wpd-primary:#f97316;--wpd-accent:#f97316;--wpd-bl-text:#ffffff;', $l['style']);
check('look: tablet columns = min(2, n)', $look('docs', ['docs_columns' => '1'])['style'] === '--wpd-bl-cols:1;--wpd-bl-cols-t:1;');
check('look: text size at least 11', $look('faq', ['faq_text_size' => 5])['style'] === '--wpd-bl-fs:11px;');
check('look: radius none = 0px', $look('cards', ['cards_radius' => 'none'])['style'] === '--wpd-radius:0px;');
check('look: solid -> ondark', $look('docs', ['docs_variant' => 'solid'])['class'] === 'wpd-look wpd-look--solid wpd-look--ondark');
check('look: other variant -> onlight', $look('docs', ['docs_variant' => 'plain'])['class'] === 'wpd-look wpd-look--plain wpd-look--onlight');
check('look: light bg -> onlight even with solid', $look('docs', ['docs_variant' => 'solid', 'docs_bg' => '#fef3c7'])['class'] === 'wpd-look wpd-look--solid wpd-look--bg wpd-look--onlight');
check('look: invalid values ignored', $look('stats', ['stats_columns' => '9', 'stats_variant' => 'neon', 'stats_bg' => 'red', 'stats_gap' => -3, 'stats_radius' => 'x']) === ['class' => 'wpd-look', 'style' => '']);
check('look: keys of other blocks ignored', $look('faq', ['faq_columns' => '3', 'stats_variant' => 'solid'])['class'] === 'wpd-look');
check('look: gap clamped to max', $look('news', ['news_gap' => 500])['style'] === '--wpd-bl-gap:48px;');

/* 4. Разметка: без параметров — как раньше */
set_opts(array_merge($D, ['stats' => [['num' => '300+', 'label' => 'школ']], 'docs' => [['title' => 'Положение', 'url' => 'https://example.com/p.pdf']],
    'faq' => [['q' => 'Q1', 'a' => 'A1'], ['q' => 'Q2', 'a' => 'A2']], 'cards' => [['title' => 'Карта', 'text' => 'Т', 'image' => '', 'color' => '', 'url' => 'https://example.com/c', 'new_tab' => 0]],
    'slides' => [['image' => '', 'heading' => 'Слайд', 'text' => '', 'url' => '', 'new_tab' => 0]],
    'notice_layout' => 'text', 'notice_title' => 'Объявление', 'notice_url' => 'https://example.com/n', 'contact_phone' => '+7 391 000-00-00', 'contact_email' => 'u@example.com']));
$o = WPD_Settings::get();
$st = WPD_Renderer::stats_html($o);
check('default stats markup', has($st, '<section class="wpd-stats" aria-label="Ключевые цифры"><ul class="wpd-stats__grid"><li class="wpd-stat"><span class="wpd-stat__num" data-count="300" data-suffix="+">300+</span>'), $st);
$dc = WPD_Renderer::docs_html($o);
check('default docs markup', has($dc, '<section class="wpd-docs">') && has($dc, '<li><a class="wpd-doc" href="https://example.com/p.pdf"><span class="wpd-doc__icon" aria-hidden="true">PDF</span><span class="wpd-doc__name">Положение</span></a></li>'), $dc);
$fq = WPD_Renderer::faq_html($o);
check('default faq markup', has($fq, '<section class="wpd-faq"><h2 class="wpd-h2">') && has($fq, '<details class="wpd-faq__item"><summary class="wpd-faq__q">Q1</summary>') && !has($fq, ' open') && !has($fq, 'data-wpd-single'));
$cd = WPD_Renderer::cards_html($o);
check('default cards markup', has($cd, '<section class="wpd-cards">') && has($cd, '<span class="wpd-card__more" aria-hidden="true">&rarr;</span>') && !has($cd, 'wpd-it-badge'));
$sl = WPD_Renderer::slider_html($o);
check('default slider markup', (bool) preg_match('/<section class="wpd-slider(?: wpd-slider--no-mobile-[a-z]+)*" style="--wpd-slider-h:420px"/', $sl) && !has($sl, 'wpd-look') && !has($sl, 'wpd-slide__badge') && !has($sl, 'cap-'), substr($sl, 0, 200));
$nt = WPD_Renderer::notice_html($o);
check('default notice markup', has($nt, '<section class="wpd-notice wpd-notice--text">') && !has($nt, 'wpd-notice__actions'), substr($nt, 0, 200));
$ct = WPD_Renderer::contacts_html($o);
check('default contacts markup', has($ct, '<section class="wpd-contacts">') && has($ct, 'wpd-contacts__icon dashicons') && has($ct, '>Связаться &rarr;</a>'));
$cw = WPD_Renderer::countdown_html(array_merge($o, ['countdown_enabled' => 1, 'countdown_date' => '2035-01-01 10:00']));
check('default countdown markup', has($cw, '<div class="wpd-countdown" data-target='), substr($cw, 0, 120));

/* 5. Разметка: свои параметры блоков и поля элементов */
// Статистика
$st = WPD_Renderer::stats_html(array_merge($o, ['stats_title' => 'Цифры & факты', 'stats_num_size' => 64, 'stats_variant' => 'card']), [
    ['num' => '20', 'label' => 'регионов', 'icon' => 'awards', 'color' => '#0b1220'],
    ['num' => '2', 'label' => 'этапа', 'color' => '#fef3c7'],
    ['num' => 'скрыт', 'label' => 'x', 'hidden' => 1],
]);
[$cls, $sty] = root($st, 'wpd-stats');
check('stats: look class and num var', $cls === 'wpd-stats wpd-look wpd-look--card wpd-look--onlight' && $sty === '--wpd-bl-num:64px;', $cls . '|' . $sty);
check('stats: title h2 before grid', has($st, '<h2 class="wpd-h2">Цифры &amp; факты</h2><ul class="wpd-stats__grid">'));
check('stats: icon before number', has($st, '<span class="wpd-stat__icon dashicons dashicons-awards" aria-hidden="true"></span><span class="wpd-stat__num"'));
check('stats: dark item color', has($st, '<li class="wpd-stat has-color wpd-it--dark" style="--wpd-it-bg:#0b1220;">'));
check('stats: light item color', has($st, '<li class="wpd-stat has-color wpd-it--light" style="--wpd-it-bg:#fef3c7;">'));
check('stats: hidden row not shown', !has($st, 'скрыт') && substr_count($st, '<li class="wpd-stat') === 2);
check('stats: unknown icon ignored', !has(WPD_Renderer::stats_html($o, [['num' => '1', 'label' => 'a', 'icon' => 'evil" onload="x']]), 'onload'));
// Документы
$dc = WPD_Renderer::docs_html($o, [
    ['title' => 'Положение', 'url' => 'https://example.com/p.pdf', 'desc' => 'PDF, 2 МБ', 'badge' => 'Новое', 'type' => 'DOCX-LONG', 'color' => '#15803d', 'download' => 1, 'new_tab' => 1],
    ['title' => 'Сайт', 'url' => 'https://example.com/'],
    ['title' => 'Без ссылки', 'url' => ''],
]);
check('docs: type text cut to 6', has($dc, '<span class="wpd-doc__icon" aria-hidden="true">DOCX-L</span>'));
check('docs: color var and class', has($dc, '<a class="wpd-doc has-icon-color" href="https://example.com/p.pdf" target="_blank" rel="noopener noreferrer" download style="--wpd-it-icon:#15803d;">'), $dc);
check('docs: desc wraps name', has($dc, '<span class="wpd-doc__text"><span class="wpd-doc__name">Положение</span><span class="wpd-doc__desc">PDF, 2 МБ</span></span>'));
check('docs: inline badge', has($dc, '<span class="wpd-it-badge wpd-it-badge--inline">Новое</span></a>'));
check('docs: plain item untouched, no-link skipped', has($dc, '<a class="wpd-doc" href="https://example.com/"><span class="wpd-doc__icon" aria-hidden="true">WEB</span>') && !has($dc, 'Без ссылки'));
$dc = WPD_Renderer::docs_html(array_merge($o, ['docs_show_type' => 0, 'docs_new_tab' => 1]), [['title' => 'A', 'url' => 'https://example.com/a']]);
check('docs: no type icon + notype class', has($dc, '<section class="wpd-docs wpd-docs--notype">') && !has($dc, 'wpd-doc__icon'));
check('docs: all in new tab', has($dc, 'target="_blank"'));
// FAQ
$fq = WPD_Renderer::faq_html(array_merge($o, ['faq_open_first' => 1, 'faq_single' => 1, 'faq_numbered' => 1, 'faq_variant' => 'tint']), [
    ['q' => 'Первый', 'a' => 'a'], ['q' => 'Второй', 'a' => 'b', 'open' => 1], ['q' => 'Скрытый', 'a' => 'c', 'hidden' => 1],
]);
check('faq: numbered + single + look', has($fq, '<section class="wpd-faq wpd-faq--numbered wpd-look wpd-look--tint wpd-look--onlight" data-wpd-single>'), substr($fq, 0, 160));
check('faq: first open, item open', substr_count($fq, '<details class="wpd-faq__item" open>') === 2);
check('faq: hidden question skipped', !has($fq, 'Скрытый'));
// Слайдер
$sl = WPD_Renderer::slider_html(array_merge($o, ['slider_caption_pos' => 'center', 'slider_interval' => 9, 'slider_autoplay' => 1, 'slider_bg' => '#0b1220']), ['items' => [
    ['image' => '', 'heading' => 'Финал', 'text' => '', 'url' => 'https://example.com/f', 'new_tab' => 0, 'badge' => 'Скоро', 'button' => 'Подробнее'],
    ['image' => '', 'heading' => 'Без ссылки', 'text' => '', 'url' => '', 'new_tab' => 0, 'button' => 'Не выводится'],
]]);
check('slider: caption position + look classes', has($sl, '<section class="wpd-slider wpd-slider--cap-center wpd-look wpd-look--bg wpd-look--ondark') && has($sl, '" style="--wpd-slider-h:420px;--wpd-bl-bg:#0b1220"'), substr($sl, 0, 220));
check('slider: interval in ms', has($sl, 'data-autoplay="9000"'));
check('slider: badge and button', has($sl, '<span class="wpd-slide__badge">Скоро</span><h2 class="wpd-slide__title">Финал</h2><span class="wpd-btn wpd-slide__btn">Подробнее &rarr;</span>'));
check('slider: button needs link', !has($sl, 'Не выводится'));
check('slider: bottom caption -> no class', !has(WPD_Renderer::slider_html(array_merge($o, ['slider_caption_pos' => 'bottom'])), 'cap-'));
// Карточки
$cd = WPD_Renderer::cards_html(array_merge($o, ['cards_more_text' => 'Подробнее', 'cards_image_height' => 160]), [
    ['title' => 'Задания', 'text' => '', 'image' => 'https://example.com/i.jpg', 'color' => '', 'url' => 'https://example.com/t', 'new_tab' => 0, 'icon' => 'welcome-learn-more', 'badge' => 'Новое'],
    ['title' => 'Своя ссылка', 'text' => '', 'image' => '', 'color' => '', 'url' => 'https://example.com/s', 'new_tab' => 0, 'more' => 'Читать'],
    ['title' => 'Скрыта', 'text' => '', 'image' => '', 'color' => '', 'url' => '', 'new_tab' => 0, 'hidden' => 1],
]);
[$cls, $sty] = root($cd, 'wpd-cards');
check('cards: image height class and var', $cls === 'wpd-cards wpd-cards--img-h' && $sty === '--wpd-bl-img-h:160px;', $cls . '|' . $sty);
check('cards: icon in title', has($cd, '<span class="wpd-card__title"><span class="wpd-card__icon dashicons dashicons-welcome-learn-more" aria-hidden="true"></span>Задания</span>'));
check('cards: more text default and own', has($cd, '<span class="wpd-card__more wpd-card__more--text">Подробнее &rarr;</span>') && has($cd, '>Читать &rarr;</span>'));
check('cards: badge + class', has($cd, 'wpd-card--badge') && has($cd, '<span class="wpd-it-badge">Новое</span>'));
check('cards: hidden skipped', !has($cd, 'Скрыта'));
// Объявление
$f = function () { return '2030-05-10 12:00'; };
add_filter('wpd_buttons_now', $f);
$nb = array_merge($o, ['notice_button_text' => 'Читать полностью']);
$nt = WPD_Renderer::notice_html($nb);
check('notice: button with link', has($nt, '<p class="wpd-notice__actions"><a class="wpd-btn wpd-notice__btn" href="https://example.com/n">Читать полностью &rarr;</a></p>'));
check('notice: no button without link', !has(WPD_Renderer::notice_html($nb, ['url' => '']), 'wpd-notice__actions'));
check('notice: before from -> nothing', WPD_Renderer::notice_html(array_merge($o, ['notice_from' => '2030-05-11 00:00'])) === '');
check('notice: after to -> nothing', WPD_Renderer::notice_html(array_merge($o, ['notice_to' => '2030-05-10 12:00'])) === '');
check('notice: inside window shown', WPD_Renderer::notice_html(array_merge($o, ['notice_from' => '2030-05-01 00:00', 'notice_to' => '2030-06-01 00:00'])) !== '');
remove_filter('wpd_buttons_now', $f);
[$cls] = root(WPD_Renderer::notice_html($o, ['color' => '#0b1220']), 'wpd-notice');
check('notice: dark own color -> ondark', $cls === 'wpd-notice wpd-notice--text wpd-look wpd-look--ondark', $cls);
[$cls] = root(WPD_Renderer::notice_html(array_merge($o, ['notice_variant' => 'outline']), ['color' => '#0b1220']), 'wpd-notice');
check('notice: own dark color beats variant tone', $cls === 'wpd-notice wpd-notice--text wpd-look wpd-look--outline wpd-look--ondark', $cls);
[$cls] = root(WPD_Renderer::notice_html(array_merge($o, ['notice_variant' => 'solid']), ['color' => '#fef3c7']), 'wpd-notice');
check('notice: own light color -> onlight', $cls === 'wpd-notice wpd-notice--text wpd-look wpd-look--solid wpd-look--onlight', $cls);
[$cls] = root(WPD_Renderer::notice_html($o, ['color' => '#fef3c7']), 'wpd-notice');
check('notice: light own color, no look -> unchanged', $cls === 'wpd-notice wpd-notice--text', $cls);
// Контакты
$ct = WPD_Renderer::contacts_html(array_merge($o, ['contacts_show_icons' => 0, 'contacts_button_text' => 'Написать', 'contacts_columns' => '2']));
check('contacts: no icons class, no icons', has($ct, '<section class="wpd-contacts wpd-contacts--noicons wpd-look wpd-look--cols"') && !has($ct, 'wpd-contacts__icon'));
check('contacts: own button text', has($ct, '>Написать &rarr;</a>'));
check('contacts: empty button text -> no button', !has(WPD_Renderer::contacts_html(array_merge($o, ['contacts_button_text' => ''])), 'wpd-contacts__btn'));
// Таймер
$cw = WPD_Renderer::countdown_html(array_merge($o, ['countdown_enabled' => 1, 'countdown_date' => '2035-01-01 10:00', 'countdown_value_size' => 40, 'countdown_variant' => 'solid']));
[$cls, $sty] = root($cw, 'wpd-countdown');
check('countdown: size class and var', $cls === 'wpd-countdown wpd-look wpd-look--solid wpd-look--ondark wpd-countdown--size' && $sty === '--wpd-bl-val:40px;', $cls . '|' . $sty);
// Новости
$cat = wp_insert_term('Новости U', 'category');
$cat_id = is_array($cat) ? (int) $cat['term_id'] : 0;
$np = wp_insert_post(['post_title' => 'Новость U', 'post_status' => 'publish', 'post_category' => [$cat_id], 'post_content' => 'один два три четыре пять шесть семь восемь девять десять']);
$created[] = $np;
$nw = WPD_Renderer::news_html(array_merge($o, ['news_show_image' => 0, 'news_show_date' => 0, 'news_excerpt_words' => 5, 'news_columns' => '3']), ['category' => $cat_id, 'layout' => 'grid', 'more' => '']);
check('news: noimg class + look', has($nw, '<section class="wpd-news wpd-news--grid wpd-news--noimg wpd-look wpd-look--cols" style="--wpd-bl-cols:3;--wpd-bl-cols-t:2;"'), substr($nw, 0, 200));
check('news: no media, no date', !has($nw, 'wpd-news__media') && !has($nw, 'wpd-news__date'));
check('news: excerpt words', has($nw, '<p class="wpd-news__excerpt">один два три четыре пять…</p>'), $nw);
check('news: no excerpt option', !has(WPD_Renderer::news_html(array_merge($o, ['news_show_excerpt' => 0]), ['category' => $cat_id, 'layout' => 'grid', 'more' => '']), 'wpd-news__excerpt'));

/* 6. Даты показа элементов */
$sched = [['num' => '1', 'label' => 'всегда'], ['num' => '2', 'label' => 'с марта', 'from' => '2030-03-10 10:00'], ['num' => '3', 'label' => 'до марта', 'to' => '2030-03-10 10:00']];
$at = function (string $now) use ($o, $sched) {
    $f = function () use ($now) { return $now; };
    add_filter('wpd_buttons_now', $f);
    preg_match_all('/<span class="wpd-stat__label">([^<]*)</', WPD_Renderer::stats_html($o, $sched), $m);
    remove_filter('wpd_buttons_now', $f);
    return implode(',', $m[1]);
};
check('items dates: before', $at('2030-03-10 09:59') === 'всегда,до марта', $at('2030-03-10 09:59'));
check('items dates: from inclusive, to exclusive', $at('2030-03-10 10:00') === 'всегда,с марта', $at('2030-03-10 10:00'));
check('visible_items unit', count(WPD_Renderer::visible_items([['a' => 1], ['hidden' => 1], 'junk'], $o)) === 1);
check('all hidden -> no section', WPD_Renderer::stats_html($o, [['num' => '1', 'label' => 'a', 'hidden' => 1]]) === '');

/* 7. Блоки: атрибуты важнее страницы, страница — общих */
$reg = WP_Block_Type_Registry::get_instance();
$attr_missing = [];
foreach ($blocks as $slug) {
    $bt = $reg->get_registered('wpd/' . $slug);
    foreach (array_keys(WPD_Blocks::look_attrs($slug)) as $attr) {
        if (!$bt || !isset($bt->attributes[$attr])) {
            $attr_missing[] = "$slug.$attr";
        }
    }
}
check('blocks: look attributes registered', !$attr_missing, implode(',', $attr_missing));
$bt = $reg->get_registered('wpd/docs');
check('blocks: attribute types', $bt->attributes['showType']['type'] === 'boolean' && $bt->attributes['gap']['type'] === 'integer' && $bt->attributes['variant']['type'] === 'string');
check('blocks: server attributes without defaults', !array_key_exists('default', $bt->attributes['variant']) && !array_key_exists('default', $bt->attributes['showType']));
check('look_attrs(faq)', WPD_Blocks::look_attrs('faq') === ['gap' => 'faq_gap', 'textAlign' => 'faq_align', 'variant' => 'faq_variant', 'radius' => 'faq_radius', 'textSize' => 'faq_text_size',
    'bgColor' => 'faq_bg', 'accentColor' => 'faq_accent', 'textColor' => 'faq_text_color', 'openFirst' => 'faq_open_first', 'single' => 'faq_single', 'numbered' => 'faq_numbered']);
$wo = WPD_Blocks::with_look_attrs($o, ['gap' => 0, 'textSize' => 99, 'variant' => 'solid', 'showType' => false, 'newTab' => true, 'bgColor' => '#000', 'unknown' => 'x', 'title' => ['bad']], 'docs');
check('with_look_attrs: unit', (int) $wo['docs_gap'] === (int) $o['docs_gap'] && (int) $wo['docs_text_size'] === 28 && $wo['docs_variant'] === 'solid'
    && $wo['docs_show_type'] === 0 && $wo['docs_new_tab'] === 1 && $wo['docs_bg'] === '#000' && !isset($wo['unknown']));
$wo = WPD_Blocks::with_look_attrs($o, ['from' => '2030-01-01T10:00', 'buttonText' => '<b>Кнопка</b>'], 'notice');
check('with_look_attrs: datetime + text', $wo['notice_from'] === '2030-01-01 10:00' && $wo['notice_button_text'] === 'Кнопка');

set_opts(array_merge(WPD_Settings::get(), ['stats_variant' => 'tint', 'stats_gap' => 20, 'stats_title' => 'Из настроек']));
$b = blk('stats');
[$cls, $sty] = root($b, 'wpd-stats');
check('block stats: settings apply without attributes', $cls === 'wpd-stats wpd-look wpd-look--gap wpd-look--tint wpd-look--onlight' && $sty === '--wpd-bl-gap:20px;' && has($b, '>Из настроек</h2>'), $cls . '|' . $sty);
$b = blk('stats', ['variant' => 'solid', 'gap' => 0, 'numSize' => 50, 'title' => '', 'columns' => '3', 'items' => [['num' => '7', 'label' => 'дней', 'icon' => 'edit']]]);
[$cls, $sty] = root($b, 'wpd-stats');
check('block stats: attributes override', $cls === 'wpd-stats wpd-look wpd-look--cols wpd-look--gap wpd-look--solid wpd-look--ondark' && $sty === '--wpd-bl-cols:3;--wpd-bl-cols-t:2;--wpd-bl-gap:20px;--wpd-bl-num:50px;', $cls . '|' . $sty);
check('block stats: empty title attribute -> none, own items', !has($b, 'wpd-h2') && has($b, 'dashicons-edit') && has($b, '>дней<'));
check('block docs: showType false', has(blk('docs', ['showType' => false, 'items' => [['title' => 'A', 'url' => 'https://example.com/a']]]), 'wpd-docs--notype'));
check('block faq: single/numbered', has(blk('faq', ['single' => true, 'numbered' => true, 'items' => [['q' => 'Q', 'a' => 'A']]]), 'wpd-faq--numbered') && has(blk('faq', ['single' => true, 'items' => [['q' => 'Q', 'a' => 'A']]]), 'data-wpd-single'));
check('block cards: moreText + imageHeight', has(blk('cards', ['moreText' => 'Далее', 'imageHeight' => 999, 'items' => [['title' => 'К', 'url' => 'https://example.com/k']]]), '--wpd-bl-img-h:480px;')
    && has(blk('cards', ['moreText' => 'Далее', 'items' => [['title' => 'К', 'url' => 'https://example.com/k']]]), '>Далее &rarr;</span>'));
check('block slider: captionPos + interval', has(blk('slider', ['captionPos' => 'top', 'interval' => 4, 'autoplay' => true, 'items' => [['heading' => 'S', 'image' => '', 'url' => '']]]), 'wpd-slider--cap-top')
    && has(blk('slider', ['interval' => 4, 'autoplay' => true, 'items' => [['heading' => 'S', 'image' => '', 'url' => '']]]), 'data-autoplay="4000"'));
check('block contacts: showIcons false + buttonText', has(blk('contacts', ['showIcons' => false, 'buttonText' => 'Пишите', 'email' => 'b@example.com']), 'wpd-contacts--noicons')
    && has(blk('contacts', ['buttonText' => 'Пишите', 'email' => 'b@example.com']), '>Пишите &rarr;</a>'));
check('block countdown: valueSize', has(blk('countdown', ['date' => '2035-01-01 10:00', 'valueSize' => 30]), '--wpd-bl-val:30px;'));
check('block news: showDate false', !has(blk('news', ['showDate' => false, 'category' => $cat_id, 'layout' => 'grid']), 'wpd-news__date') && has(blk('news', ['category' => $cat_id, 'layout' => 'grid']), 'wpd-news__date'));
check('block notice: buttonText', has(blk('notice', ['layout' => 'text', 'title' => 'Н', 'url' => 'https://example.com/x', 'buttonText' => 'Узнать']), 'wpd-notice__btn'));
check('block: invalid select attr -> settings', root(blk('stats', ['variant' => 'bogus']), 'wpd-stats')[0] === 'wpd-stats wpd-look wpd-look--gap wpd-look--onlight' || root(blk('stats', ['variant' => 'bogus']), 'wpd-stats')[0] === 'wpd-stats wpd-look wpd-look--gap', root(blk('stats', ['variant' => 'bogus']), 'wpd-stats')[0]);

// Страница
$pid = new_page('Блоки U');
$created[] = $pid;
WPD_Page_Editor::store($pid, WPD_Page_Editor::sanitize(['order' => array_keys(WPD_Page_Editor::blocks()), 'show' => ['content' => 1],
    'ovg' => ['stats' => 1, 'faq' => 1], 'ov' => ['stats_variant' => 'outline', 'stats_gap' => 30, 'stats_title' => 'Для страницы', 'faq_numbered' => '1']], $pid));
WPD_Settings::set_page($pid);
$po = WPD_Settings::get();
check('page: overrides saved', $po['stats_variant'] === 'outline' && (int) $po['stats_gap'] === 30 && $po['stats_title'] === 'Для страницы' && (int) $po['faq_numbered'] === 1,
    $po['stats_variant'] . '|' . $po['stats_gap']);
[$cls, $sty] = root(blk('stats'), 'wpd-stats');
check('page: block without attributes uses page', $cls === 'wpd-stats wpd-look wpd-look--gap wpd-look--outline wpd-look--onlight' && $sty === '--wpd-bl-gap:30px;', $cls . '|' . $sty);
[$cls, $sty] = root(blk('stats', ['variant' => 'plain', 'gap' => 8]), 'wpd-stats');
check('page: block attribute beats page', $cls === 'wpd-stats wpd-look wpd-look--gap wpd-look--plain wpd-look--onlight' && $sty === '--wpd-bl-gap:8px;', $cls . '|' . $sty);
check('page: editor data follows page', WPD_Blocks::look_settings_data()['stats']['variant'] === 'outline' && WPD_Blocks::look_settings_data()['stats']['gap'] === 30
    && WPD_Blocks::look_settings_data()['faq']['numbered'] === true);
WPD_Settings::set_page(0);
check('page: back to global', WPD_Blocks::look_settings_data()['stats']['variant'] === 'tint');

/* 8. Группы и строки панели страницы */
$groups = WPD_Page_Editor::groups();
check('groups: stats, docs, faq added', isset($groups['stats'], $groups['docs'], $groups['faq']));
$gk = WPD_Page_Editor::group_keys('stats');
check('group stats: look keys + title + num size', !array_diff(array_merge(WPD_Settings::look_keys('stats'), ['stats_title']), $gk), implode(',', array_diff(WPD_Settings::look_keys('stats'), $gk)));
check('group docs: look keys + new tab', !array_diff(array_merge(WPD_Settings::look_keys('docs'), ['docs_new_tab']), WPD_Page_Editor::group_keys('docs')));
check('group faq: open first + single', !array_diff(['faq_open_first', 'faq_single', 'faq_numbered', 'faq_variant'], WPD_Page_Editor::group_keys('faq')));
foreach (['slider' => ['slider_interval', 'slider_caption_pos', 'slider_bg'], 'cards' => ['cards_more_text', 'cards_image_height', 'cards_variant'],
          'notice' => ['notice_button_text', 'notice_from', 'notice_to', 'notice_variant'], 'news' => ['news_excerpt_words', 'news_show_image', 'news_columns'],
          'contacts' => ['contacts_button_text', 'contacts_show_icons', 'contacts_variant'], 'features' => ['countdown_value_size', 'countdown_variant']] as $g => $keys) {
    check("group $g: new keys", !array_diff($keys, WPD_Page_Editor::group_keys($g)), implode(',', array_diff($keys, WPD_Page_Editor::group_keys($g))));
}
$all = [];
foreach (array_keys($groups) as $g) {
    $all = array_merge($all, WPD_Page_Editor::group_keys($g));
}
check('groups: no key in two groups', count($all) === count(array_unique($all)), implode(',', array_diff_assoc($all, array_unique($all))));
check('groups: lists stay in their panels', !in_array('stats', $all, true) && !in_array('docs', $all, true) && !in_array('faq', $all, true));
$row = WPD_Page_Editor::row_html('docs', '0', ['title' => 'Т', 'url' => 'https://example.com/', 'badge' => 'Новое', 'new_tab' => 1, 'hidden' => 1, 'from' => '2030-01-01 10:00']);
check('row: «Ещё» details', has($row, '<details class="wpd-pe__more"><summary>Ещё</summary><div class="wpd-pe__more-body">'));
check('row: flagged when hidden or dated', has($row, '<div class="wpd-pe__row is-flagged">'));
check('row: checkbox checked', (bool) preg_match('/name="wpd_page\[docs\]\[0\]\[new_tab\]" value="1"[^>]*checked/', $row) && (bool) preg_match('/name="wpd_page\[docs\]\[0\]\[hidden\]" value="1"[^>]*checked/', $row));
check('row: datetime-local with T', has($row, 'type="datetime-local"') && has($row, 'value="2030-01-01T10:00"'));
check('row: badge input', has($row, 'name="wpd_page[docs][0][badge]"') && has($row, 'value="Новое"'));
$row = WPD_Page_Editor::row_html('stats', '1', ['num' => '1', 'label' => 'a']);
check('row: plain row not flagged, has icon select and color', has($row, '<div class="wpd-pe__row">') && has($row, 'name="wpd_page[stats][1][icon]"') && has($row, 'name="wpd_page[stats][1][color]"'));
$row = WPD_Page_Editor::row_html('faq', '__i__', []);
check('row: template for faq with open', has($row, 'name="wpd_page[faq][__i__][open]"'));
$ps = WPD_Page_Editor::sanitize(['stats_mode' => 'custom', 'stats' => [['num' => '1', 'label' => 'a', 'hidden' => '1', 'from' => '2030-01-01T10:00', 'icon' => 'bogus', 'color' => '#ABC']],
    'docs_mode' => 'custom', 'docs' => [['title' => 'D', 'url' => 'javascript:x', 'download' => 'on']],
    'faq_mode' => 'custom', 'faq' => [['q' => 'Q', 'a' => '<script>x</script>A', 'open' => '1']]]);
check('page sanitize: stats row', $ps['stats'][0]['hidden'] === 1 && $ps['stats'][0]['from'] === '2030-01-01 10:00' && $ps['stats'][0]['icon'] === '' && strtolower($ps['stats'][0]['color']) === '#abc',
    json_encode($ps['stats'][0]));
check('page sanitize: docs row', $ps['docs'][0]['url'] === '' && $ps['docs'][0]['download'] === 1);
check('page sanitize: faq row', $ps['faq'][0]['open'] === 1 && !has($ps['faq'][0]['a'], '<script'));
ob_start();
WPD_Page_Editor::meta_box_html(get_post($pid));
$mb = ob_get_clean();
check('metabox: list templates with «Ещё»', substr_count($mb, '<template data-wpd-tpl=') >= 3 && has($mb, 'wpd-pe__more'));
check('metabox: groups stats/docs/faq', has($mb, 'data-wpd-ovg="stats"') && has($mb, 'data-wpd-ovg="docs"') && has($mb, 'data-wpd-ovg="faq"'));

/* 9. Данные редактора */
$ls = WPD_Blocks::look_settings_data();
check('editor lookSettings: nine blocks', array_keys($ls) === array_keys(WPD_Settings::LOOK_BLOCKS));
check('editor lookSettings: types', $ls['docs']['showType'] === true && $ls['news']['excerptWords'] === 20 && $ls['stats']['gap'] === 20 && $ls['contacts']['buttonText'] === 'Связаться',
    json_encode($ls['docs']));
$lo = WPD_Blocks::look_options_data();
check('editor lookOptions: groups', $lo['stats']['variant']['group'] === 'look' && $lo['stats']['bgColor']['group'] === 'colors' && $lo['stats']['numSize']['group'] === 'extra'
    && $lo['docs']['showType']['group'] === 'extra' && $lo['countdown']['valueSize']['group'] === 'extra');
check('editor lookOptions: select options and bounds', isset($lo['cards']['variant']['options']['tint']) && $lo['cards']['gap']['max'] === 48 && $lo['stats']['bgColor']['label'] === 'Фон карточек');
$defs = WPD_Blocks::definitions();
check('client attributes have settings defaults', ($defs['docs']['attributes']['showType']['default'] ?? null) === true && ($defs['stats']['attributes']['variant']['default'] ?? null) === 'tint');

/* 10. Ресурсы, справка, версия */
$dir = WP_PLUGIN_DIR . '/wp-olympiad-design/';
$css = file_get_contents($dir . 'assets/css/wpd.css');
foreach (['.wpd-look.wpd-look--cols', 'var(--wpd-bl-cols-t)', 'var(--wpd-bl-cols-m)', '.wpd-look.wpd-look--outline', '.wpd-look.wpd-look--ondark', '.wpd-look.wpd-look--color',
          '.wpd-it-badge--inline', '.wpd-stat__icon.dashicons', '[style*="--wpd-bl-num"]', '.wpd-doc__desc', '.wpd-faq--numbered', 'wpd-slider--cap-top', 'wpd-slider--cap-center',
          '.wpd-slide__badge', '.wpd-cards--img-h', '.wpd-card__more--text', '.wpd-notice__actions', '.wpd-contacts--noicons', '.wpd-countdown--size', '#wpd-none'] as $needle) {
    check("css: $needle", has($css, $needle));
}
check('css: look block after soft style', strpos($css, '2.7.0: «Раскладка и вид»') > strpos($css, '.wpd-page.wpd-style-soft .wpd-contacts__box'));
$ecss = file_get_contents($dir . 'assets/css/wpd-editor.css');
check('editor css: off/scheduled/more', has($ecss, '.wpd-ed-off') && has($ecss, '.wpd-ed-scheduled') && has($ecss, '.wpd-ed-more'));
$acss = file_get_contents($dir . 'assets/css/wpd-admin.css');
check('admin css: more + flagged', has($acss, '.wpd-pe__more') && has($acss, 'is-flagged'));
$js = file_get_contents($dir . 'assets/js/wpd-blocks.js');
foreach (['function blockLook(', 'function LookPanels(', 'function ItemMore(', 'function itemFlags(', 'function Badge(', 'function itemColor(', 'wpd-docs--notype',
          'wpd-faq--numbered', 'wpd-slider--cap-', 'wpd-cards--img-h', 'wpd-countdown--size', 'wpd-contacts--noicons', "LookPanels('news'", 'wpd-notice__actions'] as $needle) {
    check("blocks js: $needle", has($js, $needle));
}
$fjs = file_get_contents($dir . 'assets/js/wpd.js');
check('front js: faq single', has($fjs, 'data-wpd-single') && has($fjs, 'function initFaqSingle'));
$ajs = file_get_contents($dir . 'assets/js/wpd-admin.js');
check('admin js: checkbox + datetime rows', has($ajs, "datetime-local") && has($ajs, '.checked'));
ob_start();
WPD_Settings::render_page();
$page = ob_get_clean();
check('help: 2.7.0 section', has($page, 'Параметры остальных блоков (2.7.0)') || has(file_get_contents($dir . 'includes/class-wpd-settings.php'), 'Параметры остальных блоков (2.7.0)'));
check('readme: 2.7.0 section', has(file_get_contents($dir . 'README.md'), '## Параметры остальных блоков (2.7.0)'));
check('version >= 2.7.0', version_compare(WPD_VERSION, '2.7.0', '>='), WPD_VERSION);
$hdr = get_plugin_data($dir . 'wp-olympiad-design.php', false, false);
check('header version = constant', $hdr['Version'] === WPD_VERSION);

/* Уборка */
foreach ($created as $id) {
    wp_delete_post($id, true);
}
if ($cat_id) {
    wp_delete_term($cat_id, 'category');
}
set_opts(is_array($backup) ? $backup : []);
if ($backup === false) {
    delete_option('wpd_settings');
}

echo implode("\n", $results) . "\n";
echo "TOTAL: " . count($results) . ", FAILED: $fails\n";
