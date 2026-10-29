<?php
/**
 * Plugin Name:       WP Olympiad Design
 * Plugin URI:        https://github.com/pavelb-coder/wp-design
 * Description:       Оформление страниц в стиле сайта олимпиады: главный блок, таймер до начала и окончания с кнопкой, статистика, документы, FAQ, слайдер, кнопки-ссылки, карточки, объявление, новости, контакты и логотипы партнёров. Свои шрифты и логотипы, блоки редактора, панель страницы со своим оформлением, настраиваемые размеры иконок и вид кнопок-ссылок, общие настройки с вкладками.
 * Version:           2.12.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            pavelb-coder
 * License:           GPL-2.0-or-later
 * Text Domain:       wp-olympiad-design
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WPD_VERSION', '2.12.0');
define('WPD_DIR', plugin_dir_path(__FILE__));
define('WPD_URL', plugin_dir_url(__FILE__));

// 2.6.0: первым — буфер для запросов REST, чтобы посторонний вывод не ломал сохранение в блочном редакторе
require_once WPD_DIR . 'includes/class-wpd-rest-guard.php';
WPD_Rest_Guard::init();

require_once WPD_DIR . 'includes/class-wpd-settings.php';
require_once WPD_DIR . 'includes/class-wpd-renderer.php';
require_once WPD_DIR . 'includes/class-wpd-page-editor.php';
require_once WPD_DIR . 'includes/class-wpd-blocks.php';
require_once WPD_DIR . 'includes/class-wpd-uploads.php';   // 2.9.0: SVG и шрифты в медиатеке (только администраторы)
require_once WPD_DIR . 'includes/class-wpd-legacy-cleanup.php'; // 2.2.0: удаление данных бывшей регистрации участников
require_once WPD_DIR . 'includes/class-wpd-netfolder-cleanup.php'; // 2.10.0: удаление данных бывшей сетевой папки

WPD_Settings::init();
WPD_Renderer::init();
WPD_Page_Editor::init();
WPD_Blocks::init();
WPD_Legacy_Cleanup::init();
WPD_Netfolder_Cleanup::init();
WPD_Uploads::init();
// 2.10.0: при отключении плагина задание бывшей сетевой папки не должно остаться в WP-Cron
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook(WPD_Netfolder_Cleanup::HOOK);
});
