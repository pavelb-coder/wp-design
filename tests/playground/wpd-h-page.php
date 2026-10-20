<?php
// Тестовый хелпер фазы H (только для песочницы): создаёт страницы с компонентами (и с таймером, ?countdown=1) и удаляет их.
require '/wordpress/wp-load.php';
header('Content-Type: text/plain; charset=utf-8');
if (isset($_GET['cleanup'])) {
    foreach (get_posts(['post_type' => ['page', 'post'], 'meta_key' => '_wpd_h_test', 'posts_per_page' => -1, 'post_status' => 'any']) as $p) {
        wp_delete_post($p->ID, true);
    }
    echo 'OK';
    exit;
}
if (isset($_GET['plain'])) {
    $content = '<p>Обычная страница</p>';
} elseif (isset($_GET['countdown'])) {
    $content = '[wpd_countdown]';
} elseif (isset($_GET['blocks'])) {
    $content = implode("\n\n", ['<!-- wp:wpd/slider {"align":"wide"} /-->', '<!-- wp:wpd/buttons /-->', '<!-- wp:wpd/notice /-->',
        '<!-- wp:wpd/cards /-->', '<!-- wp:wpd/news /-->', '<!-- wp:wpd/contacts /-->']);
} else {
    $content = "[wpd_slider]\n\n[wpd_buttons]\n\n[wpd_cards]\n\n[wpd_notice]\n\n[wpd_news]\n\n[wpd_contacts]";
    // для карусели новостей нужны записи
    for ($i = 1; $i <= 4; $i++) {
        $id = wp_insert_post(['post_title' => "Запись H$i", 'post_status' => 'publish', 'post_content' => 'Текст']);
        update_post_meta($id, '_wpd_h_test', 1);
    }
}
$id = wp_insert_post(['post_title' => 'Компоненты H', 'post_status' => 'publish', 'post_type' => 'page', 'post_content' => $content]);
update_post_meta($id, '_wpd_h_test', 1);
echo $id;
