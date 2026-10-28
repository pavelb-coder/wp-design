<?php
// Помощник фазы T (2.6.0): страница /olimpiada-t/ с включённым оформлением и двумя блоками «Олимпиада: кнопки-ссылки» —
// без атрибутов (общие настройки или настройки страницы) и со своими раскладкой, видом, цветами и кнопками
// (обычная, скрытая, будущая, прошедшая, с окном показа, подписью, меткой, картинкой, цветом и скачиванием).
// ?reset=1 — пересоздать страницу без настроек панели, ?cleanup=1 — удалить страницу и mu-plugin.
// ?junk=1 — поставить mu-plugin, который при заголовке X-WPD-Junk печатает предупреждение PHP на init (как тема с
// display_errors), а при X-WPD-NoGuard отключает защиту (WPD_REST_GUARD = false) для контрольного запроса; ?junk=0 — убрать.
require __DIR__ . '/wp-load.php';
header('Content-Type: application/json; charset=utf-8');
wp_set_current_user(1);

$mu = WP_CONTENT_DIR . '/mu-plugins/wpd-test-junk.php';
if (isset($_GET['junk'])) {
    if ($_GET['junk'] === '1') {
        wp_mkdir_p(dirname($mu));
        file_put_contents($mu, "<?php\n// Тестовый посторонний вывод фазы T\n"
            . "if (!empty(\$_SERVER['HTTP_X_WPD_NOGUARD'])) { define('WPD_REST_GUARD', false); }\n"
            . "if (!empty(\$_SERVER['HTTP_X_WPD_JUNK'])) {\n"
            . "    @ini_set('display_errors', '1');\n"
            . "    add_action('init', function () { echo \"<br />\\n<b>Warning</b>:  T-junk: Undefined variable \\\$t in <b>/wp-content/themes/t/functions.php</b> on line <b>3</b><br />\\n\"; });\n"
            . "}\n");
    } elseif (file_exists($mu)) {
        unlink($mu);
    }
    echo wp_json_encode(['junk' => file_exists($mu)]);
    exit;
}

$page = get_page_by_path('olimpiada-t', OBJECT, 'page');
if (isset($_GET['cleanup']) || ($page && isset($_GET['reset']))) {
    if ($page) {
        wp_delete_post($page->ID, true);
    }
    $page = null;
    if (isset($_GET['cleanup'])) {
        if (file_exists($mu)) {
            unlink($mu);
        }
        delete_option('wpd_rest_stray');
        echo wp_json_encode(['cleaned' => !get_page_by_path('olimpiada-t', OBJECT, 'page') && !file_exists($mu)]);
        exit;
    }
}
if (!$page) {
    $items = [
        ['text' => 'Обычная', 'url' => 'https://example.com/1', 'icon' => 'admin-home'],
        ['text' => 'Скрытая', 'url' => 'https://example.com/2', 'icon' => 'hidden', 'hidden' => 1],
        ['text' => 'Будущая', 'url' => 'https://example.com/3', 'icon' => 'clock', 'from' => '2099-01-01 00:00'],
        ['text' => 'Прошедшая', 'url' => 'https://example.com/4', 'icon' => 'clock', 'to' => '2000-01-01 00:00'],
        ['text' => 'Файл', 'url' => 'https://example.com/f.pdf', 'icon' => 'download', 'from' => '2000-01-01 00:00', 'to' => '2099-01-01 00:00',
         'desc' => 'PDF, 2 МБ', 'badge' => 'Новое', 'download' => 1, 'color' => '#fef3c7', 'icon_color' => '#b45309'],
        ['text' => 'Логотип', 'url' => 'https://example.com/6', 'image' => content_url('plugins/wp-olympiad-design/assets/img/kvantorium-logo.webp')],
    ];
    $attrs = ['items' => $items, 'layout' => 'compact', 'variant' => 'outline', 'columns' => '3', 'title' => 'Блок T', 'radius' => 'pill',
              'bgColor' => '#0b1220', 'textSize' => 16];
    $content = "<!-- wp:wpd/buttons /-->\n\n<!-- wp:wpd/buttons " . wp_json_encode($attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . " /-->";
    $id = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Олимпиада — фаза T', 'post_name' => 'olimpiada-t',
        'post_content' => wp_slash($content)]);
    update_post_meta($id, '_wpd_enabled', 1);
    WPD_Page_Editor::store($id, WPD_Page_Editor::sanitize(['order' => array_keys(WPD_Page_Editor::blocks()), 'show' => ['content' => 1]], $id));
} else {
    $id = $page->ID;
}
echo wp_json_encode(['id' => $id, 'url' => get_permalink($id)]);
