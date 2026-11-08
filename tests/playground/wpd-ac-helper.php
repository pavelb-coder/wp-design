<?php
// Помощник фазы AC (2.15.0): ?setup=1 — страница /olimpiada-ac/ со всеми компонентами шорткодами
// ([wpd_hero] … [wpd_partners]), 5 вопросов FAQ с длинной ссылкой (поиск от 4 вопросов), таймер,
// 4 записи новостей; ?cleanup=1 — удалить страницу, записи и вернуть настройки. Выводит JSON.
require __DIR__ . '/wp-load.php';
header('Content-Type: application/json; charset=utf-8');
wp_set_current_user(1);

$p = get_page_by_path('olimpiada-ac', OBJECT, 'page');
if ($p) { wp_delete_post($p->ID, true); }
foreach (get_posts(['post_type' => 'post', 'meta_key' => '_wpd_ac_test', 'posts_per_page' => -1, 'post_status' => 'any']) as $post) {
    wp_delete_post($post->ID, true);
}
if (isset($_GET['cleanup'])) {
    echo wp_json_encode(['cleaned' => !get_page_by_path('olimpiada-ac', OBJECT, 'page')]);
    exit;
}
$tz   = WPD_Settings::countdown_timezone();
$date = (new DateTime('+3 days', $tz))->format('Y-m-d H:i');
$opt = get_option('wpd_settings');
$opt = is_array($opt) ? $opt : [];
$base = $opt;
$opt['countdown_date'] = $date;
$opt['countdown_label'] = 'До начала олимпиады';
$opt['countdown_enabled'] = 1;
$opt['hero_button_url'] = 'https://example.com/go';
$opt['hero_button2_text'] = 'Задания прошлых лет';
$opt['hero_button2_url'] = 'https://example.com/tasks';
$opt['docs'] = [['title' => 'Положение об олимпиаде', 'url' => 'https://example.com/polozhenie.pdf'],
    ['title' => 'Задания прошлых лет и критерии оценивания работ участников', 'url' => 'https://example.com/zadaniya']];
$opt['notice_layout'] = 'text';
$opt['notice_title'] = 'Объявление AC';
$opt['notice_content'] = 'Регистрация открыта, подробности — в положении об олимпиаде.';
$opt['contact_address'] = 'г. Красноярск, ул. Примерная, д. 1';
$opt['contact_phone'] = '+7 (391) 000-00-00';
$opt['contact_email'] = 'ac@example.com';
$opt['partners'] = [['name' => 'Партнёр один'], ['name' => 'Партнёр два'], ['name' => 'Партнёр три'], ['name' => 'Партнёр четыре']];
$faq = [];
for ($i = 1; $i <= 5; $i++) {
    $faq[] = ['q' => "Вопрос номер $i о регистрации и проведении олимпиады?",
        'a' => 'Ответ со ссылкой https://example.com/very/long/path/to/registration/page?param=abcdefghijklmnopqrstuvwxyz — регистрация открыта до начала тура.'];
}
$opt['faq'] = $faq;
$opt['faq_title'] = 'Вопросы AC';
update_option('wpd_settings', $opt);
WPD_Settings::flush_cache();

for ($i = 1; $i <= 4; $i++) {
    $nid = wp_insert_post(['post_title' => "Запись AC$i", 'post_status' => 'publish', 'post_content' => 'Текст новости олимпиады.']);
    update_post_meta($nid, '_wpd_ac_test', 1);
}
$parts = ['wpd_hero', 'wpd_stats', 'wpd_docs', 'wpd_faq', 'wpd_countdown', 'wpd_slider',
    'wpd_buttons', 'wpd_cards', 'wpd_notice', 'wpd_news', 'wpd_contacts', 'wpd_partners'];
$content = implode("\n\n", array_map(function ($s) { return "[$s]"; }, $parts));
$id = (int) wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Олимпиада AC',
    'post_name' => 'olimpiada-ac', 'post_content' => $content]);

echo wp_json_encode(['id' => $id, 'url' => get_permalink($id),
    'opts' => array_diff_key($opt, $base) + array_intersect_key($opt, ['countdown_date' => 1, 'countdown_label' => 1, 'faq' => 1, 'faq_title' => 1, 'countdown_enabled' => 1, 'docs' => 1, 'notice_layout' => 1, 'notice_title' => 1, 'notice_content' => 1, 'contact_address' => 1, 'contact_phone' => 1, 'contact_email' => 1, 'partners' => 1, 'hero_button_url' => 1, 'hero_button2_text' => 1, 'hero_button2_url' => 1])]);
