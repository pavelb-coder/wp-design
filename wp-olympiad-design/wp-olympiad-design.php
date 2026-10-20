<?php
/**
 * Plugin Name:       WP Olympiad Design
 * Plugin URI:        https://github.com/pavelb-coder/wp-design
 * Description:       Оформление страниц в стиле сайта олимпиады: главный блок, таймер до начала и окончания с кнопкой, статистика, документы, FAQ, слайдер, кнопки-ссылки, карточки, объявление, новости и контакты. Блоки редактора, панель страницы, общие настройки с вкладками.
 * Version:           1.6.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            pavelb-coder
 * License:           GPL-2.0-or-later
 * Text Domain:       wp-olympiad-design
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WPD_VERSION', '1.6.1');
define('WPD_DIR', plugin_dir_path(__FILE__));
define('WPD_URL', plugin_dir_url(__FILE__));

require_once WPD_DIR . 'includes/class-wpd-settings.php';
require_once WPD_DIR . 'includes/class-wpd-renderer.php';
require_once WPD_DIR . 'includes/class-wpd-page-editor.php';
require_once WPD_DIR . 'includes/class-wpd-blocks.php';

WPD_Settings::init();
WPD_Renderer::init();
WPD_Page_Editor::init();
WPD_Blocks::init();
