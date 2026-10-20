<?php
// Фаза D (1.3.0): панель «Блоки олимпиады» (санитизация, сохранение, вывод) и блоки редактора.
register_shutdown_function(function () { $e = error_get_last(); if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR], true)) { echo "\nSHUTDOWN: {$e['message']} in {$e['file']}:{$e['line']}\n"; } });
ini_set('display_errors', '1');
error_reporting(E_ALL);
require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
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

// Рабочие общие настройки
update_option('wpd_settings', [
    'badge' => 'Олимпиада 2026', 'hero_title' => 'Общий заголовок', 'hero_text' => 'Общий текст',
    'hero_button_text' => 'Положение', 'hero_button_url' => 'https://example.com/global',
    'stats' => "12–18|возраст\n300+|участников", 'docs' => "Общий документ|https://example.com/g.pdf",
    'faq' => "Общий вопрос?\nОбщий ответ", 'docs_title' => 'Документы', 'faq_title' => 'Вопросы',
    'hero_style' => 'dark', 'countdown_enabled' => 1, 'countdown_date' => '2030-11-20 10:30',
]);
WPD_Settings::flush_cache();
$o = WPD_Settings::get();

/* 1. Классы и регистрация */
check('class WPD_Page_Editor', class_exists('WPD_Page_Editor'));
check('class WPD_Blocks', class_exists('WPD_Blocks'));
check('version 1.3.0', WPD_VERSION === '1.3.0');

/* 2. Значения по умолчанию */
$d = WPD_Page_Editor::defaults();
check('defaults: order of 6 blocks', $d['order'] === ['hero', 'countdown', 'content', 'stats', 'docs', 'faq']);
check('defaults: nothing hidden', $d['hidden'] === []);
check('defaults: lists use global', $d['stats_mode'] === 'global' && $d['docs_mode'] === 'global' && $d['faq_mode'] === 'global');
check('get() of page without meta = defaults', WPD_Page_Editor::get(999999) === $d);

/* 3. normalize: мусор, дубли, новые блоки */
$n = WPD_Page_Editor::normalize(['order' => ['faq', 'faq', 'bogus', 'hero', ['x']], 'hidden' => ['bogus', 'docs'], 'hero' => 'str', 'stats' => 'str', 'stats_mode' => 'weird']);
check('normalize: order dedup + unknown dropped + missing appended', $n['order'] === ['faq', 'hero', 'countdown', 'content', 'stats', 'docs'], implode(',', $n['order']));
check('normalize: hidden only known', $n['hidden'] === ['docs']);
check('normalize: hero non-array -> defaults', $n['hero'] === $d['hero']);
check('normalize: list non-array -> []', $n['stats'] === []);
check('normalize: bad mode -> global', $n['stats_mode'] === 'global');

/* 4. sanitize */
$s = WPD_Page_Editor::sanitize([
    'order' => ['faq', 'hero', 'content', 'stats', 'docs', 'countdown'],
    'show'  => ['faq' => '1', 'hero' => '1', 'content' => '1', 'stats' => '1'],
    'hero'  => [
        'title' => '<script>alert(1)</script>Заголовок', 'badge' => '<b>Метка</b>', 'lead' => "Строка 1\nСтрока 2<img src=x onerror=1>",
        'button_text' => 'Кнопка', 'button_url' => 'javascript:alert(1)', 'image_id' => 'abc', 'style' => 'neon',
    ],
    'stats_mode' => 'custom',
    'stats' => [['num' => '500+', 'label' => 'школьников'], ['num' => '', 'label' => ''], ['num' => '<i>7</i>', 'label' => 'x', 'evil' => 'y'], 'junk'],
    'docs_mode' => 'custom', 'docs_title' => '<em>Файлы</em>',
    'docs' => [['title' => 'Плохой', 'url' => 'javascript:alert(1)'], ['title' => 'Хороший', 'url' => 'https://example.com/a.pdf']],
    'faq_mode' => 'custom', 'faq_title' => 'FAQ страницы',
    'faq' => [['q' => 'Вопрос?', 'a' => '<strong>да</strong><script>x</script><a href="https://e.com" onclick="x">ссылка</a>'], ['q' => '', 'a' => '']],
]);
check('sanitize: order kept', $s['order'] === ['faq', 'hero', 'content', 'stats', 'docs', 'countdown']);
check('sanitize: unchecked -> hidden', $s['hidden'] === ['docs', 'countdown'], implode(',', $s['hidden']));
check('sanitize: hero title tags stripped', $s['hero']['title'] === 'Заголовок', $s['hero']['title']);
check('sanitize: badge tags stripped', $s['hero']['badge'] === 'Метка');
check('sanitize: lead keeps newline, strips tags', $s['hero']['lead'] === "Строка 1\nСтрока 2", json_encode($s['hero']['lead']));
check('sanitize: javascript button url dropped', $s['hero']['button_url'] === '');
check('sanitize: image_id non-numeric -> 0', $s['hero']['image_id'] === 0);
check('sanitize: bad style -> empty', $s['hero']['style'] === '');
check('sanitize: empty stat row removed, junk skipped', count($s['stats']) === 2, (string) count($s['stats']));
check('sanitize: stat extra keys dropped', array_keys($s['stats'][1]) === ['num', 'label']);
check('sanitize: stat tags stripped', $s['stats'][1]['num'] === '7');
check('sanitize: docs title stripped', $s['docs_title'] === 'Файлы');
check('sanitize: javascript doc url -> empty', $s['docs'][0]['url'] === '');
check('sanitize: good doc url kept', $s['docs'][1]['url'] === 'https://example.com/a.pdf');
check('sanitize: faq answer kses (strong kept)', has($s['faq'][0]['a'], '<strong>да</strong>'));
check('sanitize: faq answer kses (script removed)', !has($s['faq'][0]['a'], '<script'));
check('sanitize: faq answer kses (onclick removed)', !has($s['faq'][0]['a'], 'onclick'));
check('sanitize: empty faq row removed', count($s['faq']) === 1);
check('sanitize: without order -> nothing hidden', WPD_Page_Editor::sanitize(['hero' => ['title' => 'x']])['hidden'] === []);
check('sanitize(garbage) = defaults', WPD_Page_Editor::sanitize('garbage') === $d);

/* 5. Сохранение через save_post */
wp_set_current_user(1);
$pid = wp_insert_post(['post_title' => 'Страница с панелью', 'post_content' => '<p>Текст страницы</p>', 'post_excerpt' => 'Цитата записи', 'post_status' => 'publish', 'post_type' => 'page']);
update_post_meta($pid, '_wpd_enabled', 1);

$form = [
    'order' => ['faq', 'hero', 'content', 'stats', 'docs', 'countdown'],
    'show'  => ['faq' => 1, 'hero' => 1, 'content' => 1, 'stats' => 1, 'docs' => 1],
    'hero'  => ['title' => 'Свой заголовок O\'Reilly', 'badge' => 'Финал', 'lead' => '', 'button_text' => 'Регистрация', 'button_url' => 'https://example.com/reg', 'image_id' => '', 'style' => 'light'],
    'stats_mode' => 'custom', 'stats' => [['num' => '42', 'label' => 'финалиста']],
    'docs_mode' => 'custom', 'docs_title' => 'Документы финала', 'docs' => [],
    'faq_mode' => 'global', 'faq_title' => '', 'faq' => [['q' => 'Не используется', 'a' => 'Скрыто режимом global']],
];
// В настоящем запросе WordPress экранирует $_POST слешами
$_POST = wp_slash(['wpd_page_nonce' => wp_create_nonce('wpd_save_page'), 'wpd_page' => $form]);
do_action('save_post', $pid, get_post($pid), true);
$saved = WPD_Page_Editor::get($pid);
check('save: meta stored', is_array(get_post_meta($pid, '_wpd_page', true)));
check('save: apostrophe preserved (no extra slashes)', $saved['hero']['title'] === "Свой заголовок O'Reilly", $saved['hero']['title']);
check('save: order', $saved['order'][0] === 'faq');
check('save: countdown hidden', $saved['hidden'] === ['countdown']);
check('save: custom stats', $saved['stats'] === [['num' => '42', 'label' => 'финалиста']]);
check('save: faq rows kept but mode global', $saved['faq_mode'] === 'global');

// Бэкслеш в тексте
$_POST = wp_slash(['wpd_page_nonce' => wp_create_nonce('wpd_save_page'), 'wpd_page' => array_replace_recursive($form, ['hero' => ['badge' => 'C:\\path']])]);
do_action('save_post', $pid, get_post($pid), true);
check('save: backslash preserved', WPD_Page_Editor::get($pid)['hero']['badge'] === 'C:\\path', WPD_Page_Editor::get($pid)['hero']['badge']);
$_POST = wp_slash(['wpd_page_nonce' => wp_create_nonce('wpd_save_page'), 'wpd_page' => $form]);
do_action('save_post', $pid, get_post($pid), true);

// Неверный nonce
$_POST = ['wpd_page_nonce' => 'bad', 'wpd_page' => ['order' => ['hero']]];
do_action('save_post', $pid, get_post($pid), true);
check('save: invalid nonce ignored', WPD_Page_Editor::get($pid)['order'][0] === 'faq');

// Без nonce панели (например, сохранение из REST) — ничего не меняется
$_POST = [];
do_action('save_post', $pid, get_post($pid), true);
check('save: no panel nonce -> untouched', WPD_Page_Editor::get($pid)['order'][0] === 'faq');

// Без прав
wp_set_current_user(0);
$_POST = wp_slash(['wpd_page_nonce' => wp_create_nonce('wpd_save_page'), 'wpd_page' => ['order' => ['hero']]]);
do_action('save_post', $pid, get_post($pid), true);
check('save: no capability -> ignored', WPD_Page_Editor::get($pid)['order'][0] === 'faq');
wp_set_current_user(1);

// Ревизия
$rev = wp_save_post_revision($pid);
if ($rev) {
    $_POST = wp_slash(['wpd_page_nonce' => wp_create_nonce('wpd_save_page'), 'wpd_page' => $form]);
    do_action('save_post', $rev, get_post($rev), true);
    check('save: revision gets no meta', get_post_meta($rev, '_wpd_page', true) === '');
} else {
    check('save: revision (skipped)', true);
}

// Значения по умолчанию -> мета удаляется
$pid_def = wp_insert_post(['post_title' => 'По умолчанию', 'post_status' => 'publish', 'post_type' => 'page']);
update_post_meta($pid_def, '_wpd_page', ['order' => ['faq']]);
$_POST = wp_slash(['wpd_page_nonce' => wp_create_nonce('wpd_save_page'), 'wpd_page' => [
    'order' => array_keys(WPD_Page_Editor::blocks()), 'show' => array_fill_keys(array_keys(WPD_Page_Editor::blocks()), 1),
    'hero' => ['title' => '', 'badge' => '', 'lead' => '', 'button_text' => '', 'button_url' => '', 'image_id' => '', 'style' => ''],
    'stats_mode' => 'global', 'docs_mode' => 'global', 'faq_mode' => 'global', 'docs_title' => '', 'faq_title' => '',
]]);
do_action('save_post', $pid_def, get_post($pid_def), true);
check('save: defaults -> meta deleted', get_post_meta($pid_def, '_wpd_page', true) === '');
$_POST = [];

// Старый флажок по-прежнему сохраняется отдельно и не трогает панель
$_POST = ['wpd_nonce' => wp_create_nonce('wpd_save_meta'), 'wpd_enabled' => '1'];
do_action('save_post', $pid, get_post($pid), true);
check('legacy checkbox save keeps panel data', WPD_Page_Editor::get($pid)['order'][0] === 'faq');
$_POST = [];

/* 6. Вывод оформленной страницы */
$html = WPD_Renderer::page_html($pid, '<p>Текст страницы</p>');
$pos = function ($needle) use ($html) { $p = strpos($html, $needle); return $p === false ? -1 : $p; };
check('page: faq before hero (order)', $pos('wpd-faq') > -1 && $pos('wpd-faq') < $pos('wpd-hero'));
check('page: hero before content', $pos('wpd-hero') < $pos('wpd-content'));
check('page: content before stats', $pos('wpd-content') < $pos('wpd-stats'));
check('page: countdown hidden', !has($html, 'wpd-countdown'));
check('page: hero title override (escaped)', has($html, 'Свой заголовок O&#039;Reilly'), substr($html, $pos('wpd-hero__title'), 120));
check('page: badge override', has($html, '<span class="wpd-badge">Финал</span>'));
check('page: button override', has($html, 'href="https://example.com/reg">Регистрация'));
check('page: light style override', has($html, 'wpd-hero--light'));
check('page: empty lead -> excerpt', has($html, 'wpd-hero__lead">Цитата записи'));
check('page: custom stats', has($html, '>42<') && !has($html, '12–18'));
check('page: custom docs empty -> block absent', !has($html, 'wpd-docs'));
check('page: faq global used', has($html, 'Общий вопрос?') && !has($html, 'Не используется'));
check('page: global faq title (empty override)', has($html, '>Вопросы</h2>'));
check('page: single wrapper', substr_count($html, 'class="wpd-page"') === 1);

$html_empty = WPD_Renderer::page_html($pid, '   ');
check('page: empty content -> no content card', !has($html_empty, 'wpd-content'));

// Лид из панели важнее цитаты; заголовок блока документов
$p2 = wp_insert_post(['post_title' => 'Вторая', 'post_content' => 'x', 'post_excerpt' => 'Цитата', 'post_status' => 'publish', 'post_type' => 'page']);
update_post_meta($p2, '_wpd_page', ['hero' => ['lead' => "Свой\nлид"], 'docs_mode' => 'custom', 'docs_title' => 'Мои файлы', 'docs' => [['title' => 'Файл', 'url' => 'https://example.com/f.pdf']]]);
$h2 = WPD_Renderer::page_html($p2, 'x');
check('page2: lead override with <br>', has($h2, 'Свой<br />') && !has($h2, '>Цитата<'));
check('page2: custom docs title', has($h2, '>Мои файлы</h2>'));
check('page2: custom docs item', has($h2, 'https://example.com/f.pdf') && !has($h2, 'g.pdf'));
check('page2: global hero fields kept', has($h2, '>Олимпиада 2026<') && has($h2, 'https://example.com/global'));
check('page2: post title as hero title', has($h2, 'wpd-hero__title">Вторая<'));
check('page2: countdown shown (global on)', has($h2, 'wpd-countdown'));

// Изображение из панели
$att = wp_insert_attachment(['post_title' => 'pe', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit', 'guid' => 'https://example.com/pe.jpg'], false);
update_post_meta($att, '_wp_attached_file', '2026/10/panel-image.jpg');
update_post_meta($p2, '_wpd_page', ['hero' => ['image_id' => $att]]);
check('page2: panel image used', has(WPD_Renderer::page_html($p2, 'x'), 'panel-image.jpg'));

// Все блоки скрыты
update_post_meta($p2, '_wpd_page', ['order' => array_keys(WPD_Page_Editor::blocks()), 'hidden' => array_keys(WPD_Page_Editor::blocks())]);
$h3 = WPD_Renderer::page_html($p2, 'x');
check('page: all hidden -> empty wrapper', !has($h3, 'wpd-hero') && !has($h3, 'wpd-content'));

/* 7. Мета-бокс */
set_current_screen('page');
$GLOBALS['wp_meta_boxes'] = [];
do_action('add_meta_boxes', 'page', get_post($pid));
check('panel registered for page (normal/high)', isset($GLOBALS['wp_meta_boxes']['page']['normal']['high']['wpd_page_blocks']));
check('legacy side box still registered', isset($GLOBALS['wp_meta_boxes']['page']['side']['high']['wpd_design']));
$GLOBALS['wp_meta_boxes'] = [];
do_action('add_meta_boxes', 'post', get_post($pid));
check('panel registered for post', isset($GLOBALS['wp_meta_boxes']['post']['normal']['high']['wpd_page_blocks']));
ob_start(); WPD_Page_Editor::meta_box_html(get_post($pid)); $mb = ob_get_clean();
check('panel: nonce field', has($mb, 'name="wpd_page_nonce"'));
check('panel: 6 order rows', substr_count($mb, 'name="wpd_page[order][]"') === 6);
check('panel: saved order first = faq', strpos($mb, 'value="faq"') < strpos($mb, 'value="hero"'));
check('panel: countdown unchecked', !preg_match('/name="wpd_page\[show\]\[countdown\]" value="1" checked/', $mb));
check('panel: hero title value escaped', has($mb, 'value="Свой заголовок O&#039;Reilly"'));
check('panel: 3 row templates', substr_count($mb, '<template data-wpd-tpl=') === 3);
check('panel: custom stats row', has($mb, 'name="wpd_page[stats][0][num]" value="42"'));
check('panel: design-off notice hidden when enabled', has($mb, 'data-wpd-off hidden'));
ob_start(); WPD_Page_Editor::meta_box_html(get_post($pid_def)); $mb2 = ob_get_clean();
check('panel: design-off notice shown when disabled', has($mb2, 'data-wpd-off>') );
check('panel: global placeholder for badge', has($mb2, 'Пусто — «Олимпиада 2026»'));
$row = WPD_Page_Editor::row_html('docs', '3', ['title' => '"><script>', 'url' => 'https://e.com']);
check('row_html escapes values', !has($row, '<script>') && has($row, '&quot;&gt;&lt;script&gt;'));

/* 8. Блоки редактора */
$reg = WP_Block_Type_Registry::get_instance();
foreach (['hero', 'countdown', 'stats', 'docs', 'faq'] as $b) {
    check("block wpd/$b registered", $reg->is_registered("wpd/$b"));
}
$hero_type = $reg->get_registered('wpd/hero');
$defs = WPD_Blocks::definitions();
check('block hero: editor default title = current settings', $defs['hero']['attributes']['title']['default'] === 'Общий заголовок');
check('block hero: server attrs without default (read from settings at render)', !array_key_exists('default', $hero_type->attributes['title']));
check('block hero: is dynamic', $hero_type->is_dynamic());
check('block stats: editor default items = settings', $defs['stats']['attributes']['items']['default'][1]['num'] === '300+');
check('block editor script registered', wp_script_is('wpd-blocks', 'registered'));
check('block editor style registered', wp_style_is('wpd-editor', 'registered'));
$inline = implode('', (array) wp_scripts()->get_data('wpd-blocks', 'before'));
check('block data passed to editor', has($inline, 'window.wpdBlocks') && has($inline, 'wpd\/hero'));
$cats = apply_filters('block_categories_all', [], null);
check('block category "Олимпиада"', in_array('wpd', array_column($cats, 'slug'), true));
check('block pattern registered', WP_Block_Patterns_Registry::get_instance()->is_registered('wpd/olympiad-page'));

$b = do_blocks('<!-- wp:wpd/hero {"title":"Блок \u003cb\u003eX\u003c/b\u003e","badge":"","variant":"gradient","className":"my-hero bad\"cls"} /-->');
check('render hero: custom title escaped', has($b, 'Блок &lt;b&gt;X&lt;/b&gt;'), $b);
check('render hero: empty badge -> no badge', !has($b, 'wpd-badge'));
check('render hero: variant', has($b, 'wpd-hero--gradient'));
check('render hero: default lead from settings', has($b, 'Общий текст'));
check('render hero: block wrapper class', has($b, 'class="wpd-page wpd-page--block wpd-page--hero my-hero'));
check('render hero: className sanitized', !has($b, 'bad"cls'));
$b = do_blocks('<!-- wp:wpd/hero /-->');
check('render hero: all defaults', has($b, 'Общий заголовок') && has($b, 'Олимпиада 2026'));
// Незаданные поля следуют за настройками даже после их изменения в том же запросе
$tmp = get_option('wpd_settings'); update_option('wpd_settings', array_merge($tmp, ['hero_title' => 'Новый общий']));
check('render hero: follows settings change', has(do_blocks('<!-- wp:wpd/hero /-->'), 'Новый общий'));
check('render hero: changed field keeps own value', has(do_blocks('<!-- wp:wpd/hero {"title":"Своё"} /-->'), '>Своё<'));
update_option('wpd_settings', $tmp);
check('render stats: no items attr -> settings', substr_count(do_blocks('<!-- wp:wpd/stats /-->'), 'class="wpd-stat"') === 2);
check('render docs: no attrs -> settings', has(do_blocks('<!-- wp:wpd/docs /-->'), 'g.pdf'));

check('render hero: align wide class', has(do_blocks('<!-- wp:wpd/hero {"align":"wide"} /-->'), 'wpd-page--hero alignwide'));
check('render hero: bad align ignored', !has(do_blocks('<!-- wp:wpd/hero {"align":"evil"} /-->'), 'alignevil'));
$b = do_blocks('<!-- wp:wpd/countdown {"date":"2031-01-02 03:04","label":"Старт","doneText":"Всё"} /-->');
check('render countdown: target', has($b, 'data-target="2031-01-02T03:04'), $b);
check('render countdown: label', has($b, '>Старт<'));
check('render countdown: done text', has($b, 'data-done="Всё"'));
check('render countdown: empty date -> nothing', do_blocks('<!-- wp:wpd/countdown {"date":""} /-->') === '');
check('render countdown: bad date -> nothing', do_blocks('<!-- wp:wpd/countdown {"date":"<script>"} /-->') === '');

$b = do_blocks('<!-- wp:wpd/stats {"items":[{"num":"77","label":"\u003cscript\u003ex"},{"num":"","label":""},"junk"]} /-->');
check('render stats: item', has($b, '>77<'));
check('render stats: escaped', !has($b, '<script>'));
check('render stats: empty rows skipped', substr_count($b, 'class="wpd-stat"') === 1);
check('render stats: empty list -> nothing', do_blocks('<!-- wp:wpd/stats {"items":[]} /-->') === '');

$b = do_blocks('<!-- wp:wpd/docs {"title":"","items":[{"title":"A","url":"javascript:alert(1)"},{"title":"","url":"https://example.com/z.pdf"}]} /-->');
check('render docs: javascript url dropped', !has($b, 'javascript:'));
check('render docs: empty title -> url as name', has($b, 'wpd-doc__name">https://example.com/z.pdf<'));
check('render docs: empty block title -> settings', has($b, '>Документы</h2>'));

$b = do_blocks('<!-- wp:wpd/faq {"title":"Мои вопросы","items":[{"q":"Q1","a":"\u003cstrong\u003eA\u003c/strong\u003e\u003cbr\u003eB\u003cscript\u003ex\u003c/script\u003e"},{"q":"","a":"no question"}]} /-->');
check('render faq: title', has($b, '>Мои вопросы</h2>'));
check('render faq: answer html kept', has($b, '<strong>A</strong>'));
check('render faq: script removed', !has($b, '<script'));
check('render faq: item without question skipped', substr_count($b, '<details') === 1);

/* 9. Фикстуры для HTTP-проверок */
$block_page = wp_insert_post(['post_title' => 'Страница из блоков', 'post_status' => 'publish', 'post_type' => 'page',
    'post_content' => wp_slash("<!-- wp:wpd/hero {\"title\":\"Заголовок блока\"} /-->\n<!-- wp:paragraph --><p>Абзац между блоками</p><!-- /wp:paragraph -->\n<!-- wp:wpd/faq /-->")]);
$ids = ['panel' => $pid, 'blocks' => $block_page, 'second' => $p2];
update_option('wpd_test_ids_d', $ids);
echo "IDS " . json_encode($ids) . "\n";

echo implode("\n", $results) . "\n";
echo "\nTOTAL: " . count($results) . ", FAILED: $fails\n";
