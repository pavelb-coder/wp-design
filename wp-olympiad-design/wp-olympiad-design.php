<?php
/**
 * Plugin Name:       WP Olympiad Design
 * Plugin URI:        https://github.com/pavelb-coder/wp-design
 * Description:       Оформление страниц в стиле сайта олимпиады: главный блок, таймер до начала и окончания с кнопкой, статистика, документы, FAQ, слайдер, кнопки-ссылки, карточки, объявление, новости и контакты с названием организации. Блоки редактора, панель страницы со своим оформлением, настраиваемые размеры иконок и вид кнопок-ссылок, общие настройки с вкладками, импорт документов из сетевой папки (SMB).
 * Version:           2.9.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            pavelb-coder
 * License:           GPL-2.0-or-later
 * Text Domain:       wp-olympiad-design
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WPD_VERSION', '2.9.0');
define('WPD_DIR', plugin_dir_path(__FILE__));
define('WPD_URL', plugin_dir_url(__FILE__));

// 2.6.0: первым — буфер для запросов REST, чтобы посторонний вывод не ломал сохранение в блочном редакторе
require_once WPD_DIR . 'includes/class-wpd-rest-guard.php';
WPD_Rest_Guard::init();

require_once WPD_DIR . 'includes/class-wpd-settings.php';
require_once WPD_DIR . 'includes/class-wpd-renderer.php';
require_once WPD_DIR . 'includes/class-wpd-page-editor.php';
require_once WPD_DIR . 'includes/class-wpd-blocks.php';
require_once WPD_DIR . 'includes/class-wpd-smb.php';       // 2.8.0: клиент SMB
require_once WPD_DIR . 'includes/class-wpd-netfolder.php'; // 2.8.0: импорт документов из сетевой папки
require_once WPD_DIR . 'includes/class-wpd-uploads.php';   // 2.9.0: SVG и шрифты в медиатеке (только администраторы)
require_once WPD_DIR . 'includes/class-wpd-legacy-cleanup.php'; // 2.2.0: удаление данных бывшей регистрации участников

WPD_Settings::init();
WPD_Renderer::init();
WPD_Page_Editor::init();
WPD_Blocks::init();
WPD_Legacy_Cleanup::init();
WPD_Netfolder::init();
WPD_Uploads::init();
register_activation_hook(__FILE__, ['WPD_Netfolder', 'reschedule']);
register_deactivation_hook(__FILE__, ['WPD_Netfolder', 'deactivate']);
