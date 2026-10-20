<?php
// Замеры: сколько раз читается опция настроек при выводе [wpd_page] и сколько раз WordPress
// обращается к опции за один рендер. Используется для сравнения до и после оптимизации.
ini_set('display_errors', '1');
error_reporting(E_ALL);
require '/wordpress/wp-load.php';
header('Content-Type: text/plain; charset=utf-8');

echo "PHP_VERSION=" . PHP_VERSION . "\n";
// Опция должна существовать: option_{name} не срабатывает для отсутствующей опции.
update_option('wpd_settings', ['primary' => '#0f766e']);

$reads = 0;
add_filter('option_wpd_settings', function ($v) use (&$reads) { $reads++; return $v; });

// Первый рендер (холодный, без кеша).
$reads = 0;
do_shortcode('[wpd_page]');
echo "reads_first_render=$reads\n";

// Второй рендер в том же запросе.
$reads = 0;
do_shortcode('[wpd_page]');
echo "reads_second_render=$reads\n";

// Обычный вывод блока hero + stats + docs + faq + wrap: сколько всего чтений на три шорткода.
$reads = 0;
do_shortcode('[wpd_hero][wpd_stats][wpd_docs][wpd_faq]');
echo "reads_four_shortcodes=$reads\n";
