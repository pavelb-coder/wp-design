<?php
// Тестовый хелпер (только для песочницы): меняет опцию плагина по GET ?json=...
require '/wordpress/wp-load.php';
$data = json_decode(stripslashes($_GET['json'] ?? '{}'), true);
update_option('wpd_settings', $data ?: []);
echo 'OK ' . json_encode(get_option('wpd_settings'), JSON_UNESCAPED_UNICODE);
