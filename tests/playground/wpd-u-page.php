<?php
// Помощник фазы U (2.7.0): страница /olimpiada-u/ с включённым оформлением и блоками «Олимпиада: …» со своими параметрами
// «Раскладки и вида» и полями элементов: статистика, документы, FAQ (со своими параметрами и без атрибутов), карточки,
// слайдер, объявление, таймер, контакты, новости. Скрытые и будущие элементы на сайте не выводятся.
// ?reset=1 — пересоздать страницу без настроек панели, ?cleanup=1 — удалить. Выводит JSON: id, адрес.
require __DIR__ . '/wp-load.php';
header('Content-Type: application/json; charset=utf-8');
wp_set_current_user(1);

$page = get_page_by_path('olimpiada-u', OBJECT, 'page');
if (isset($_GET['cleanup']) || ($page && isset($_GET['reset']))) {
    if ($page) {
        wp_delete_post($page->ID, true);
    }
    $page = null;
    if (isset($_GET['cleanup'])) {
        echo wp_json_encode(['cleaned' => !get_page_by_path('olimpiada-u', OBJECT, 'page')]);
        exit;
    }
}
if (!$page) {
    $b = function (string $name, array $attrs = []): string {
        return '<!-- wp:wpd/' . $name . ($attrs ? ' ' . wp_json_encode($attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '') . " /-->\n\n";
    };
    $content = $b('stats', ['title' => 'Цифры U', 'variant' => 'solid', 'columns' => '3', 'columnsMobile' => '1', 'numSize' => 56, 'items' => [
            ['num' => '300+', 'label' => 'школьников', 'icon' => 'awards'],
            ['num' => '20', 'label' => 'регионов', 'color' => '#fef3c7'],
            ['num' => '7', 'label' => 'скрытая', 'hidden' => 1],
            ['num' => '9', 'label' => 'будущая', 'from' => '2099-01-01 00:00'],
        ]])
        . $b('docs', ['variant' => 'card', 'gap' => 24, 'items' => [
            ['title' => 'Положение U', 'url' => 'https://example.com/u.pdf', 'desc' => 'PDF, 1 МБ', 'badge' => 'Новое', 'color' => '#15803d', 'download' => true],
            ['title' => 'Сайт U', 'url' => 'https://example.com/', 'type' => 'САЙТ', 'new_tab' => true],
        ]])
        . $b('faq', ['single' => true, 'numbered' => true, 'openFirst' => true, 'variant' => 'tint', 'textSize' => 18, 'items' => [
            ['q' => 'Первый вопрос U', 'a' => 'Ответ 1'], ['q' => 'Второй вопрос U', 'a' => 'Ответ 2'], ['q' => 'Третий вопрос U', 'a' => 'Ответ 3'],
        ]])
        . $b('cards', ['moreText' => 'Подробнее', 'imageHeight' => 140, 'columns' => '2', 'variant' => 'outline', 'items' => [
            ['title' => 'Задания U', 'text' => 'Текст', 'url' => 'https://example.com/t', 'icon' => 'edit', 'badge' => 'Скоро'],
            ['title' => 'Итоги U', 'text' => 'Текст', 'url' => 'https://example.com/r', 'more' => 'Смотреть'],
        ]])
        . $b('slider', ['captionPos' => 'center', 'bgColor' => '#0b1220', 'autoplay' => false, 'items' => [
            ['heading' => 'Слайд U', 'text' => 'Описание', 'image' => '', 'url' => 'https://example.com/s', 'badge' => 'Финал', 'button' => 'Узнать'],
        ]])
        . $b('notice', ['layout' => 'text', 'title' => 'Объявление U', 'content' => 'Текст объявления', 'url' => 'https://example.com/n', 'buttonText' => 'Читать', 'variant' => 'outline'])
        . $b('countdown', ['date' => '2099-01-01 10:00', 'valueSize' => 40, 'variant' => 'card'])
        . $b('contacts', ['showIcons' => false, 'buttonText' => 'Написать', 'email' => 'u@example.com', 'phone' => '+7 391 000-00-00', 'variant' => 'tint', 'columns' => '2'])
        . $b('news', ['showImage' => false, 'layout' => 'grid', 'count' => 3])
        . $b('faq', ['items' => [['q' => 'Вопрос без атрибутов', 'a' => 'Ответ']]]);
    $id = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Олимпиада — фаза U', 'post_name' => 'olimpiada-u',
        'post_content' => wp_slash($content)]);
    update_post_meta($id, '_wpd_enabled', 1);
    WPD_Page_Editor::store($id, WPD_Page_Editor::sanitize(['order' => array_keys(WPD_Page_Editor::blocks()), 'show' => ['content' => 1]], $id));
    if (!get_posts(['post_type' => 'post', 'numberposts' => 1])) {
        wp_insert_post(['post_title' => 'Новость U', 'post_status' => 'publish', 'post_content' => 'Текст новости U']);
    }
} else {
    $id = $page->ID;
}
echo wp_json_encode(['id' => $id, 'url' => get_permalink($id)]);
