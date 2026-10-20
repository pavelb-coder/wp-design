<?php
// Фаза A: юнит-проверки логики, санитизации, рендера и мета-бокса внутри WordPress.
register_shutdown_function(function(){ $e = error_get_last(); if ($e) { echo "\nSHUTDOWN: {$e['message']} in {$e['file']}:{$e['line']}\n"; } });
ini_set('display_errors','1'); error_reporting(E_ALL);
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

// 0. Синтаксис всех PHP-файлов
$plugin_dir = WP_PLUGIN_DIR . '/wp-olympiad-design';
$files = array_merge(
    glob($plugin_dir . '/*.php') ?: [],
    glob($plugin_dir . '/includes/*.php') ?: [],
    glob($plugin_dir . '/*.php') ?: []
);
foreach (array_unique($files) as $f) {
    try {
        token_get_all(file_get_contents($f), TOKEN_PARSE);
        check('syntax ' . basename($f), true);
    } catch (ParseError $e) {
        check('syntax ' . basename($f), false, $e->getMessage());
    }
}

// 1. Активация и регистрация
check('plugin active', is_plugin_active('wp-olympiad-design/wp-olympiad-design.php'));
foreach (['wpd_page', 'wpd_hero', 'wpd_stats', 'wpd_docs', 'wpd_faq'] as $sc) {
    check("shortcode $sc registered", shortcode_exists($sc));
}
check('css file exists', file_exists($plugin_dir . '/assets/css/wpd.css'));

// 1b. Кеш настроек: сброс при изменении опции в том же запросе
WPD_Settings::flush_cache();
update_option('wpd_settings', ['primary' => '#111111']);
check('cache: reads new value after update', WPD_Settings::get()['primary'] === '#111111');
update_option('wpd_settings', ['primary' => '#222222']);
check('cache: reflects second update in same request', WPD_Settings::get()['primary'] === '#222222');
delete_option('wpd_settings');
check('cache: reset after delete', WPD_Settings::get()['primary'] === '#1d4ed8');
// Опция должна существовать: фильтр option_* не применяется к отсутствующей опции.
update_option('wpd_settings', ['primary' => '#333333']);
WPD_Settings::flush_cache();
$reads = 0;
add_filter('option_wpd_settings', function ($v) use (&$reads) { $reads++; return $v; });
WPD_Settings::get(); WPD_Settings::get(); WPD_Settings::get();
check('cache: option read once for 3 get() calls', $reads === 1, "reads=$reads");
remove_all_filters('option_wpd_settings');

// body_class вне одиночной записи не меняется
check('body_class untouched outside singular', WPD_Renderer::body_class(['x']) === ['x']);

// 2. Дефолты и количество полей
delete_option('wpd_settings');
$defaults = WPD_Settings::defaults();
check('defaults count = 64', count($defaults) === 64, (string) count($defaults));
check('get() returns defaults when empty', WPD_Settings::get()['primary'] === '#1d4ed8');

$out = do_shortcode('[wpd_page]');
check('[wpd_page] has hero', strpos($out, 'wpd-hero') !== false);
check('[wpd_page] has stats', strpos($out, 'wpd-stats') !== false);
check('[wpd_page] has faq', strpos($out, 'wpd-faq') !== false);
check('[wpd_page] no docs when docs empty', strpos($out, 'wpd-docs') === false);
check('default stats = 4 cards', substr_count($out, 'class="wpd-stat"') === 4, (string) substr_count($out, 'class="wpd-stat"'));
check('default faq = 2 items', substr_count($out, '<details') === 2, (string) substr_count($out, '<details'));
check('wrapper has css vars', strpos($out, '--wpd-primary:#1d4ed8') !== false);
check('[wpd_stats] wrapped', strpos(do_shortcode('[wpd_stats]'), 'wpd-page') !== false);
check('[wpd_docs] empty when no docs', do_shortcode('[wpd_docs]') === '');

// 3. Санитизация: безопасность
$bad = WPD_Settings::sanitize([
    'primary'        => 'red;background:url(x)',
    'accent'         => '#ABCDEF',
    'radius'         => '99',
    'font'           => 'x;}body{display:none}',
    'hero_title'     => '<img src=x onerror=alert(1)>Заголовок',
    'hero_button_url'=> 'javascript:alert(1)',
    'hero_image_url' => 'https://example.com/i.jpg',
    'faq'            => "Вопрос<script>alert(1)</script>\n<a href=\"https://vk.com/x\" onclick=\"a()\">VK</a> <b>x</b>\n\nВопрос2\nОтвет2",
    'docs'           => "<script>x</script>|https://example.com/a.pdf\nБез|javascript:alert(2)",
    'hide_title'     => 'on',
]);
check('invalid color -> default', $bad['primary'] === '#1d4ed8', $bad['primary']);
check('valid color kept', $bad['accent'] === '#ABCDEF');
check('radius clamped to 40', $bad['radius'] === 40, (string) $bad['radius']);
check('font has no CSS-breaking chars', strpos($bad['font'], '{') === false && strpos($bad['font'], ';') === false && strpos($bad['font'], '}') === false, $bad['font']);
check('hero_title tags stripped', strpos($bad['hero_title'], '<img') === false);
check('javascript: url rejected', $bad['hero_button_url'] === '', $bad['hero_button_url']);
check('https image url kept', $bad['hero_image_url'] === 'https://example.com/i.jpg');
check('faq strips script tag', stripos($bad['faq'], '<script') === false);
check('faq strips onclick attr', stripos($bad['faq'], 'onclick') === false);
check('faq keeps <a href>', strpos($bad['faq'], '<a href="https://vk.com/x">') !== false, $bad['faq']);
check('faq keeps <b> removed (not allowed)', strpos($bad['faq'], '<b>') === false);
check('hide_title checkbox on -> 1', $bad['hide_title'] === 1);

$off = WPD_Settings::sanitize(['primary' => '#000000']);
check('missing checkbox -> 0', $off['hide_title'] === 0);
check('missing text field -> default', $off['hero_title'] === WPD_Settings::field_map()['hero_title']['default']);
check('sanitize([]) returns all keys', count(WPD_Settings::sanitize([])) === 64);
check('sanitize(non-array) safe', count(WPD_Settings::sanitize('garbage')) === 64);

// 4. Парсеры
$pairs = WPD_Settings::parse_pairs("a|b\r\nc|d\r\n\r\n  e  \n");
check('parse_pairs CRLF + blanks', count($pairs) === 3, (string) count($pairs));
check('parse_pairs no pipe -> empty right', $pairs[2]['right'] === '');
$pipe = WPD_Settings::parse_pairs("Name|https://x.com/?a=1|b");
check('parse_pairs keeps extra pipes', $pipe[0]['right'] === 'https://x.com/?a=1|b', $pipe[0]['right']);
check('parse_pairs empty string', WPD_Settings::parse_pairs('') === []);

$faq = WPD_Settings::parse_faq("Q1\r\nA1 строка\r\nвтрая\r\n\r\n\r\nQ2\nA2\n\n");
check('parse_faq CRLF + multi blank', count($faq) === 2, (string) count($faq));
check('parse_faq multiline answer', $faq[0]['a'] === "A1 строка\r\nвтрая" || $faq[0]['a'] === "A1 строка\nвтрая", json_encode($faq[0]));
check('parse_faq empty', WPD_Settings::parse_faq('') === []);
check('parse_faq question-only block', count(WPD_Settings::parse_faq("Только вопрос")) === 1);

// 5. Рендер с экранированием
update_option('wpd_settings', [
    'hero_title' => '<script>evil()</script>Главный',
    'hero_text'  => '<img src=x onerror=1>Текст',
    'hero_button_text' => 'Жми',
    'hero_button_url'  => 'https://example.com/go',
    'stats' => '',
    'faq' => '',
    'docs' => "<script>x</script>|https://example.com/a.pdf\nWEB|https://example.com/page\nBad|javascript:alert(3)",
    'hide_title' => 1,
]);
$hero = do_shortcode('[wpd_hero]');
check('hero title escaped', strpos($hero, '<script>evil') === false && strpos($hero, '&lt;script&gt;evil') !== false);
check('hero text escaped', strpos($hero, '<img') === false);
check('hero button rendered', strpos($hero, 'href="https://example.com/go"') !== false);
check('empty stats -> no section', strpos(do_shortcode('[wpd_page]'), 'wpd-stats') === false);
check('empty faq -> no section', strpos(do_shortcode('[wpd_page]'), 'wpd-faq') === false);
$docs = do_shortcode('[wpd_docs]');
check('docs: pdf icon', strpos($docs, '>PDF</span>') !== false);
check('docs: web icon', strpos($docs, '>WEB</span>') !== false);
check('docs: script label escaped', strpos($docs, '<script>x') === false);
check('docs: javascript link dropped', strpos($docs, 'javascript:') === false);
check('docs: 2 links', substr_count($docs, 'class="wpd-doc"') === 2, (string) substr_count($docs, 'class="wpd-doc"'));

// Пустой badge и без кнопки
update_option('wpd_settings', ['badge' => '', 'hero_button_url' => '', 'hero_button_text' => 'X']);
$hero2 = do_shortcode('[wpd_hero]');
check('empty badge hidden', strpos($hero2, 'wpd-badge') === false);
check('button hidden without url', strpos($hero2, 'wpd-btn') === false);
check('no img without image', strpos($hero2, '<img') === false);

// 5b. Версия 1.2.0: дата таймера, варианты блока, таймер, поиск FAQ, анимация статистики
check('shortcode wpd_countdown registered', shortcode_exists('wpd_countdown'));
check('normalize_datetime: T-format', WPD_Settings::normalize_datetime('2030-11-20T10:30') === '2030-11-20 10:30');
check('normalize_datetime: space-format', WPD_Settings::normalize_datetime('2030-11-20 10:30') === '2030-11-20 10:30');
check('normalize_datetime: invalid month -> empty', WPD_Settings::normalize_datetime('2030-13-20T10:30') === '');
check('normalize_datetime: Feb 30 -> empty', WPD_Settings::normalize_datetime('2030-02-30T10:00') === '');
check('normalize_datetime: bad hour -> empty', WPD_Settings::normalize_datetime('2030-11-20T25:00') === '');
check('normalize_datetime: garbage -> empty', WPD_Settings::normalize_datetime('<script>') === '');
check('normalize_datetime: empty -> empty', WPD_Settings::normalize_datetime('') === '');

$feat = WPD_Settings::sanitize([
    'hero_style' => 'neon', 'countdown_date' => '2030-11-20T10:30', 'countdown_label' => '<b>До</b> старта',
    'countdown_enabled' => 'on', 'faq_search' => '', 'stats_animate' => '1',
]);
check('hero_style invalid -> dark', $feat['hero_style'] === 'dark', $feat['hero_style']);
check('countdown_date normalized', $feat['countdown_date'] === '2030-11-20 10:30', $feat['countdown_date']);
check('countdown_enabled on -> 1', $feat['countdown_enabled'] === 1);
check('faq_search unchecked -> 0', $feat['faq_search'] === 0);
check('stats_animate on -> 1', $feat['stats_animate'] === 1);
check('countdown_label tags stripped', strpos($feat['countdown_label'], '<b>') === false);

// Таймер: выключен / включён / без даты
update_option('wpd_settings', ['countdown_enabled' => 0, 'countdown_date' => '2030-11-20 10:30']);
check('countdown off -> empty', do_shortcode('[wpd_countdown]') === '');
update_option('wpd_settings', ['countdown_enabled' => 1, 'countdown_date' => '']);
check('countdown without date -> empty', do_shortcode('[wpd_countdown]') === '');
update_option('wpd_settings', [
    'countdown_enabled' => 1, 'countdown_date' => '2030-11-20 10:30',
    'countdown_label' => '<img src=x onerror=1>До старта', 'countdown_done' => 'Уже прошло',
]);
$cd = do_shortcode('[wpd_countdown]');
check('countdown renders target', strpos($cd, 'data-target="2030-11-20T10:30:00') !== false, $cd);
check('countdown done text attr', strpos($cd, 'data-done="Уже прошло"') !== false);
check('countdown label escaped', strpos($cd, '<img') === false);
check('countdown value has date (no-JS fallback)', strpos($cd, 'wpd-countdown__value') !== false);
check('countdown wrapped in .wpd-page', strpos($cd, 'class="wpd-page"') !== false);

// Вариант главного блока (проверяем, что хук сброса кеша работает в том же запросе)
update_option('wpd_settings', ['hero_style' => 'light']);
check('hero light class', strpos(do_shortcode('[wpd_hero]'), 'wpd-hero--light') !== false);
update_option('wpd_settings', ['hero_style' => 'gradient']);
check('hero gradient class', strpos(do_shortcode('[wpd_hero]'), 'wpd-hero--gradient') !== false);
update_option('wpd_settings', ['hero_style' => 'bogus']);
check('hero invalid style falls back to dark', strpos(do_shortcode('[wpd_hero]'), 'wpd-hero--dark') !== false);

// Поиск по FAQ: от 4 вопросов и при включённой опции
$faq4 = "Q1\nA1\n\nQ2\nA2\n\nQ3\nA3\n\nQ4\nA4";
update_option('wpd_settings', ['faq' => $faq4, 'faq_search' => 1]);
$f4 = do_shortcode('[wpd_faq]');
check('faq search shown for 4 items', strpos($f4, 'data-wpd-search') !== false && strpos($f4, 'class="wpd-faq__search"') !== false);
check('faq search input hidden by default', strpos($f4, 'wpd-faq__search" placeholder="Поиск по вопросам" aria-label="Поиск по вопросам" hidden') !== false);
update_option('wpd_settings', ['faq' => "Q1\nA1\n\nQ2\nA2\n\nQ3\nA3", 'faq_search' => 1]);
check('faq search hidden for 3 items', strpos(do_shortcode('[wpd_faq]'), 'wpd-faq__search') === false);
update_option('wpd_settings', ['faq' => $faq4, 'faq_search' => 0]);
check('faq search off -> none', strpos(do_shortcode('[wpd_faq]'), 'wpd-faq__search') === false);

// Анимация статистики: только числа вида 300, 300+, 50%
update_option('wpd_settings', ['stats' => "300+|участников\n12–18|возраст\n50%|доля\nabc|текст", 'stats_animate' => 1]);
$st = do_shortcode('[wpd_stats]');
check('stats animate: 300+ -> data-count 300 suffix +', strpos($st, 'data-count="300" data-suffix="+"') !== false, $st);
check('stats animate: 50% -> data-count 50 suffix %', strpos($st, 'data-count="50" data-suffix="%"') !== false);
check('stats animate: range 12–18 not animated', substr_count($st, 'data-count=') === 2, (string) substr_count($st, 'data-count='));
check('stats animate: text value kept', strpos($st, '>300+<') !== false);
update_option('wpd_settings', ['stats_animate' => 0]);
check('stats animate off -> no data-count', strpos(do_shortcode('[wpd_stats]'), 'data-count') === false);

// Возвращаем настройки, с которыми работают фазы B и C
update_option('wpd_settings', []);

// 6. Мета-бокс: сохранение через save_post
wp_set_current_user(1);
$page_id = wp_insert_post(['post_title' => 'Оформленная', 'post_content' => '<p>Контент</p>', 'post_excerpt' => 'Лид <b>текст</b>', 'post_status' => 'publish', 'post_type' => 'page']);
$page_plain = wp_insert_post(['post_title' => 'Обычная', 'post_content' => '<p>Обычный</p>', 'post_status' => 'publish', 'post_type' => 'page']);

// Включаем
$_POST = ['wpd_nonce' => wp_create_nonce('wpd_save_meta'), 'wpd_enabled' => '1'];
do_action('save_post', $page_id, get_post($page_id), true);
check('meta saved = 1 with valid nonce', (int) get_post_meta($page_id, '_wpd_enabled', true) === 1);

// Выключаем (галочка снята — поля нет)
$_POST = ['wpd_nonce' => wp_create_nonce('wpd_save_meta')];
do_action('save_post', $page_id, get_post($page_id), true);
check('meta saved = 0 when unchecked', (int) get_post_meta($page_id, '_wpd_enabled', true) === 0);

// Неверный nonce — ничего не меняем
update_post_meta($page_id, '_wpd_enabled', 1);
$_POST = ['wpd_nonce' => 'bad', 'wpd_enabled' => ''];
do_action('save_post', $page_id, get_post($page_id), true);
check('invalid nonce ignored', (int) get_post_meta($page_id, '_wpd_enabled', true) === 1);

// Без прав — ничего не меняем
wp_set_current_user(0);
$_POST = ['wpd_nonce' => wp_create_nonce('wpd_save_meta'), 'wpd_enabled' => ''];
do_action('save_post', $page_id, get_post($page_id), true);
check('no capability -> ignored', (int) get_post_meta($page_id, '_wpd_enabled', true) === 1);
wp_set_current_user(1);

// Ревизия не получает мету
$rev_id = wp_save_post_revision($page_id);
$_POST = ['wpd_nonce' => wp_create_nonce('wpd_save_meta'), 'wpd_enabled' => '1'];
if ($rev_id) {
    do_action('save_post', $rev_id, get_post($rev_id), true);
    check('revision gets no meta', get_post_meta($rev_id, '_wpd_enabled', true) === '');
} else {
    check('revision (skipped: no revision created)', true);
}
$_POST = [];

// Мета-бокс зарегистрирован для page и post
set_current_screen('page');
$GLOBALS['wp_meta_boxes'] = [];
do_action('add_meta_boxes', 'page', get_post($page_id));
check('meta box registered for page', isset($GLOBALS['wp_meta_boxes']['page']['side']['high']['wpd_design']));
ob_start(); WPD_Renderer::meta_box_html(get_post($page_id)); $mb = ob_get_clean();
check('meta box renders checkbox', strpos($mb, 'name="wpd_enabled"') !== false && strpos($mb, 'checked') !== false);
check('plain page not designed', !(bool) get_post_meta($page_plain, '_wpd_enabled', true));

// 7. Поведение the_content вне главного цикла не меняется
$plain = apply_filters('the_content', '<p>Тест</p>');
check('the_content untouched outside singular loop', strpos($plain, 'wpd-page') === false);

// Фикстуры для фазы B (curl)
$thumb_id = wp_insert_attachment(['post_title' => 'thumb', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit', 'guid' => 'https://example.com/thumb.jpg'], false);
$feat_id = wp_insert_post(['post_title' => 'С картинкой', 'post_content' => '<p>Текст с картинкой</p>', 'post_status' => 'publish', 'post_type' => 'page']);
update_post_meta($feat_id, '_wpd_enabled', 1);
if ($thumb_id) {
    update_post_meta($thumb_id, '_wp_attached_file', '2026/10/thumb.jpg');
    update_post_meta($feat_id, '_thumbnail_id', $thumb_id);
}


// Возвращаем рабочие настройки для фронта
update_option('wpd_settings', ['docs' => "Положение|https://example.com/p.pdf", 'hide_title' => 1, 'stats' => "12–18|возраст\n300+|участников", 'faq' => "Q?\nA"]);

$ids = ['designed' => $page_id, 'plain' => $page_plain, 'featured' => $feat_id];
update_option('wpd_test_ids', $ids);
echo "IDS " . json_encode($ids) . "\n";

echo implode("\n", $results) . "\n";
echo "\nTOTAL: " . count($results) . ", FAILED: $fails\n";
