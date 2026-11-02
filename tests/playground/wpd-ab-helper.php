<?php
// Помощник фазы AB (2.14.0): ?setup=1 — картинки ab-banner.svg (1600 × 500) и ab-banner-m.svg (600 × 600) и страница /olimpiada-ab/
// с блоками: главный блок с картинкой вместо текста (от края до края, ссылка, картинка для телефона, кнопки и таймер под картинкой),
// главный блок с картинкой с отступами, обычный главный блок по ширине, статистика (ширина текста 400 px, по центру), вопросы
// (по ширине, 300 px; и 300 px по левому краю), кнопки-ссылки (по ширине) и вопросы по умолчанию; страница /olimpiada-ab-panel/ с панелью
// «Блоки олимпиады» (картинка вместо текста у главного блока, ширина и выравнивание вопросов). ?cleanup=1 — удалить всё. Выводит JSON.
require __DIR__ . '/wp-load.php';
header('Content-Type: application/json; charset=utf-8');
wp_set_current_user(1);

$pages = ['olimpiada-ab', 'olimpiada-ab-panel'];
foreach ($pages as $slug) {
    $p = get_page_by_path($slug, OBJECT, 'page');
    if ($p) { wp_delete_post($p->ID, true); }
}
$files = [__DIR__ . '/ab-banner.svg', __DIR__ . '/ab-banner-m.svg'];
if (isset($_GET['cleanup'])) {
    foreach ($files as $f) { if (is_file($f)) { unlink($f); } }
    echo wp_json_encode(['cleaned' => !get_page_by_path('olimpiada-ab', OBJECT, 'page') && !get_page_by_path('olimpiada-ab-panel', OBJECT, 'page') && !is_file($files[0])]);
    exit;
}
$svg = function (int $w, int $h, string $fill, string $text) {
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '">'
        . '<rect width="100%" height="100%" fill="' . $fill . '"/><circle cx="' . ($w - 160) . '" cy="' . intdiv($h, 2) . '" r="' . intdiv($h, 3) . '" fill="#ffffff" opacity="0.25"/>'
        . '<text x="64" y="' . intdiv($h, 2) . '" font-family="Arial, sans-serif" font-size="' . intdiv($h, 7) . '" font-weight="700" fill="#ffffff">' . $text . '</text></svg>';
};
file_put_contents($files[0], $svg(1600, 500, '#1d4ed8', 'Олимпиада 2026 — баннер'));
file_put_contents($files[1], $svg(600, 600, '#b91c1c', 'Телефон'));
$img  = home_url('/ab-banner.svg');
$imgm = home_url('/ab-banner-m.svg');
$tz   = WPD_Settings::countdown_timezone();
$date = (new DateTime('+3 days', $tz))->format('Y-m-d H:i');
$opt = get_option('wpd_settings');
$opt = is_array($opt) ? $opt : [];
$base = $opt;
$opt['countdown_date'] = $date;
$opt['countdown_label'] = 'До старта';
$opt['faq'] = [['q' => 'Когда начало олимпиады и как зарегистрироваться участникам из других городов?', 'a' => str_repeat('Регистрация открыта на сайте олимпиады до начала первого тура, участие бесплатное для всех школьников. ', 4)]];
$opt['faq_title'] = 'Вопросы панели AB';
update_option('wpd_settings', $opt);
WPD_Settings::flush_cache();

$st   = [['num' => '120', 'label' => 'школ', 'icon' => 'welcome-learn-more'], ['num' => '7', 'label' => 'городов', 'icon' => 'groups'], ['num' => '3', 'label' => 'тура', 'icon' => 'awards']];
$long = str_repeat('Олимпиада проводится в два тура: отборочный дистанционный и заключительный очный, задания составляют преподаватели университетов. ', 3);
$faq  = [['q' => 'Как проходит олимпиада?', 'a' => $long], ['q' => 'Сколько туров?', 'a' => 'Два тура: отборочный и заключительный.']];
$b = function (string $name, array $a) { return '<!-- wp:wpd/' . $name . ' ' . wp_json_encode($a) . ' /-->'; };
$blocks = implode("\n\n", [
    $b('hero', ['title' => 'Главный AB баннер', 'lead' => 'Скрытый текст', 'buttonText' => 'Участвовать', 'buttonUrl' => 'https://example.com/go', 'showCountdown' => true,
        'bannerOn' => true, 'bannerUrl' => $img, 'bannerMobileUrl' => $imgm, 'bannerAlt' => 'Афиша олимпиады', 'bannerLink' => 'https://example.com/poster',
        'bannerButtons' => true, 'bannerTimer' => true, 'className' => 'ab-hero']),
    $b('hero', ['title' => 'Главный AB с отступами', 'bannerOn' => true, 'bannerUrl' => $img, 'bannerEdge' => 'padded', 'className' => 'ab-hero-pad']),
    $b('hero', ['title' => 'Обычный главный блок AB', 'lead' => $long, 'textAlign' => 'justify', 'className' => 'ab-hero-text']),
    $b('stats', ['items' => $st, 'blockTextWidth' => 400, 'textAlign' => 'center', 'className' => 'ab-stats']),
    $b('faq', ['items' => $faq, 'title' => 'Вопросы AB', 'blockTextWidth' => 300, 'textAlign' => 'justify', 'className' => 'ab-faq']),
    $b('faq', ['items' => $faq, 'title' => 'Вопросы слева AB', 'blockTextWidth' => 300, 'textAlign' => 'left', 'className' => 'ab-faq-left']),
    $b('buttons', ['title' => 'Кнопки AB', 'textAlign' => 'justify', 'blockTextWidth' => 450, 'className' => 'ab-buttons', 'items' => [
        ['icon' => 'admin-links', 'text' => 'Положение об олимпиаде школьников по математике и информатике для всех классов', 'url' => 'https://example.com/1'],
        ['icon' => 'media-document', 'text' => 'Задания', 'url' => 'https://example.com/2']]]),
    $b('faq', ['items' => $faq, 'title' => 'Вопросы по умолчанию', 'className' => 'ab-faq-plain']),
]);
$id = (int) wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Олимпиада AB', 'post_name' => 'olimpiada-ab', 'post_content' => wp_slash($blocks)]);

$pid = (int) wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Олимпиада AB панель', 'post_name' => 'olimpiada-ab-panel', 'post_content' => '<p>AB</p>']);
update_post_meta($pid, '_wpd_page', WPD_Page_Editor::sanitize(['enabled' => '1', 'order' => ['hero', 'faq'], 'show' => ['hero' => '1', 'faq' => '1'],
    'ovg' => ['hero' => '1', 'faq' => '1'],
    'ov' => ['hero_banner' => '1', 'hero_banner_url' => $img, 'hero_banner_edge' => 'padded', 'hero_banner_alt' => 'Баннер панели', 'hero_show_icons' => '1', 'hero_image_mobile' => '1',
             'faq_text_w' => '640', 'faq_align' => 'justify', 'faq_show_icons' => '1']], $pid));
update_post_meta($pid, WPD_Renderer::META_KEY, 1);

// opts — что помощник добавил в настройки (тесту — чтобы вернуть после смены стиля)
echo wp_json_encode(['id' => $id, 'url' => get_permalink($id), 'pid' => $pid, 'purl' => get_permalink($pid), 'img' => $img, 'imgm' => $imgm,
    'opts' => array_diff_key($opt, $base) + array_intersect_key($opt, ['countdown_date' => 1, 'countdown_label' => 1, 'faq' => 1, 'faq_title' => 1])]);
