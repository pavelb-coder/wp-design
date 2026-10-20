<?php
/**
 * Plugin Name:       WP Olympiad Design
 * Plugin URI:        https://github.com/pavelb-coder/wp-design
 * Description:       Оформление страниц в стиле сайта олимпиады: главный блок, статистика, документы и FAQ-аккордеон. Блоки редактора, своё содержимое и порядок блоков для каждой страницы, общие настройки без правки кода.
 * Version:           1.3.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            pavelb-coder
 * License:           GPL-2.0-or-later
 * Text Domain:       wp-olympiad-design
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WPD_VERSION', '1.3.0');
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
