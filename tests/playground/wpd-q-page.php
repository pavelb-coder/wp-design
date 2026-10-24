<?php
// Тестовый хелпер фазы Q (только для песочницы): настройки и оформленная страница с содержимым макета
// «Мягкий градиент». ?style=soft|classic — стиль оформления (сохраняется через WPD_Settings::sanitize, как формой),
// ?orb=0 — без встроенной иллюстрации, ?cleanup=1 — удалить страницу и опцию. Выводит JSON: id и адрес страницы, итоговые палитру и стиль главного блока.
require '/wordpress/wp-load.php';
wp_set_current_user(1);

if (isset($_GET['cleanup'])) {
    $old = get_page_by_path('olimpiada-q', OBJECT, 'page');
    if ($old) {
        wp_delete_post($old->ID, true);
    }
    delete_option('wpd_settings');
    echo get_page_by_path('olimpiada-q', OBJECT, 'page') || get_option('wpd_settings') !== false ? 'LEFT' : 'CLEANED', "\n";
    exit;
}

$style = ($_GET['style'] ?? 'soft') === 'classic' ? 'classic' : 'soft';
$o = WPD_Settings::get();
$input = array_merge($o, [
    '_tab'            => 'design',
    'design_style'    => $style,
]);
// Вкладка «Оформление» — переключение стиля меняет палитру и стиль главного блока
update_option('wpd_settings', WPD_Settings::sanitize($input));

// Содержимое (все поля сразу, без _tab — стиль не меняется)
$o = WPD_Settings::get();
$future = wp_date('Y-m-d H:i', time() + 40 * DAY_IN_SECONDS, new DateTimeZone('Europe/Moscow'));
$data = array_merge($o, [
    'badge'             => '',
    'hero_title'        => 'Всероссийская олимпиада по техническому английскому языку',
    'hero_text'         => '',
    'hero_button_text'  => '',
    'hero_button_url'   => '',
    'hero_image_url'    => '',
    'hero_countdown'    => 1,
    'hero_soft_orb'     => isset($_GET['orb']) && $_GET['orb'] === '0' ? 0 : 1,
    'countdown_enabled' => 1,
    'countdown_date'    => $future,
    'countdown_label'   => 'До начала олимпиады',
    'stats'             => "12-18|возраст участников\n300+|школьников в прошлом году\n20|регионов России\n2|этапа: онлайн и очный финал в Красноярске",
    'stats_animate'     => 0,
    'docs_title'        => 'Документы',
    'docs'              => "Положение об олимпиаде|https://example.com/polozhenie.pdf\nРегламент проведения|https://example.com/reglament\nРезультаты прошлого года|https://example.com/results.pdf",
    'faq_title'         => 'Частые вопросы',
    'faq'               => "Кто может участвовать?\nШкольники от 12 до 18 лет из любого региона России.\n\nСколько стоит участие?\nУчастие бесплатное.\n\nКак проходит олимпиада?\nСначала онлайн-этап, затем очный финал в Красноярске.\n\nГде узнать результаты?\nВ разделе «Документы» после проверки работ.",
    'cards_title'       => '',
    'cards'             => [
        ['title' => 'Перечень Минпросвещения', 'text' => 'Включена в Перечень мероприятий, рекомендованных Министерством просвещения РФ', 'image' => '', 'url' => '', 'new_tab' => 0, 'color' => ''],
        ['title' => 'Положение', 'text' => '', 'image' => '', 'url' => 'https://example.com/polozhenie.pdf', 'new_tab' => 0, 'color' => ''],
        ['title' => 'Принять участие', 'text' => '', 'image' => '', 'url' => 'https://example.com/reg', 'new_tab' => 0, 'color' => ''],
    ],
    'buttons'           => [
        ['text' => 'Об олимпиаде', 'url' => '#about', 'icon' => 'info-outline', 'new_tab' => 0, 'small' => 0],
        ['text' => 'Документы', 'url' => '#docs', 'icon' => 'media-document', 'new_tab' => 0, 'small' => 0],
        ['text' => 'Результаты', 'url' => 'https://example.com/results.pdf', 'icon' => 'awards', 'new_tab' => 0, 'small' => 0],
        ['text' => 'Задания прошлых лет', 'url' => '', 'icon' => 'book', 'new_tab' => 0, 'small' => 0],
    ],
    'contacts_title'    => 'Контакты',
    'contact_org'       => 'Кванториум Красноярск',
    'contact_org_url'   => 'https://example.com/',
    'contact_address'   => 'ул. Примерная, 1',
    'contact_city'      => '660000, Красноярск',
    'contact_phone'     => '+7 (391) 000-00-00',
    'contact_email'     => 'info@example.com',
    'contact_logo'      => 'auto',
]);
unset($data['_tab']);
update_option('wpd_settings', WPD_Settings::sanitize($data));

$title = 'Олимпиада — фаза Q';
$page  = get_page_by_path('olimpiada-q', OBJECT, 'page');
$id    = $page ? (int) $page->ID : (int) wp_insert_post([
    'post_title'   => $title,
    'post_name'    => 'olimpiada-q',
    'post_status'  => 'publish',
    'post_type'    => 'page',
    'post_content' => '<p>Олимпиада проверяет, как школьники читают технические тексты, понимают инструкции и описывают устройства на английском языке.</p>',
]);
update_post_meta($id, '_wpd_enabled', 1);
update_post_meta($id, '_wpd_page', [
    'order'  => ['hero', 'countdown', 'stats', 'cards', 'content', 'buttons', 'docs', 'faq', 'contacts', 'slider', 'notice', 'news'],
    'hidden' => ['slider', 'notice', 'news'],
]);

$o = WPD_Settings::get();
echo json_encode([
    'id'           => $id,
    'url'          => get_permalink($id),
    'design_style' => $o['design_style'],
    'palette'      => $o['palette'],
    'hero_style'   => $o['hero_style'],
    'primary'      => $o['primary'],
    'accent'       => $o['accent'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
