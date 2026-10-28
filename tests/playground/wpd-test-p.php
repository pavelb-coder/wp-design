<?php
// Фаза P (2.2.0): регистрация участников и личный кабинет убраны. Проверяет, что от них ничего не осталось в коде,
// и что одноразовая очистка удаляет данные 2.0.0–2.1.0: заявки, аккаунты участников (записи переходят администратору,
// аккаунты с другими ролями остаются без роли участника), роль, настройки, счётчик, временные данные, мета-поля;
// порции, блокировка, отметка о завершении, список страниц с оставшимися шорткодами и блоками, уведомление.
register_shutdown_function(function () { $e = error_get_last(); if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR], true)) { echo "\nSHUTDOWN: {$e['message']} in {$e['file']}:{$e['line']}\n"; } });
ini_set('display_errors', '1');
error_reporting(E_ALL);
require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
header('Content-Type: text/plain; charset=utf-8');

$results = [];
$fails = 0;
function check(string $name, bool $cond, string $detail = ''): void {
    global $results, $fails;
    $results[] = ($cond ? 'PASS ' : 'FAIL ') . $name . ($cond || $detail === '' ? '' : ' :: ' . $detail);
    if (!$cond) { $fails++; }
}
function has(string $h, string $n): bool { return strpos($h, $n) !== false; }
global $wpdb;
function count_apps(): int { global $wpdb; return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'wpd_application'"); }
function count_trans(): int { global $wpdb; return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_wpd\\_reg\\_%'"); }

$admin = 1;
wp_set_current_user(0);

/* ---------- В плагине больше нет регистрации ---------- */
check('version 2.2.0 or later', version_compare(WPD_VERSION, '2.2.0', '>='));
check('no registration classes', !class_exists('WPD_Registration', false) && !class_exists('WPD_Participants', false));
check('registration files removed', !file_exists(WPD_DIR . 'includes/class-wpd-registration.php') && !file_exists(WPD_DIR . 'includes/class-wpd-participants.php') && !file_exists(WPD_DIR . 'assets/js/wpd-reg-blocks.js'));
check('no shortcodes', !shortcode_exists('wpd_registration') && !shortcode_exists('wpd_cabinet'));
$reg = WP_Block_Type_Registry::get_instance();
check('no blocks wpd/registration, wpd/cabinet', !$reg->is_registered('wpd/registration') && !$reg->is_registered('wpd/cabinet'));
$wpd_blocks = array_filter(array_keys($reg->get_all_registered()), function ($n) { return strpos($n, 'wpd/') === 0; });
check('12 wpd blocks (2.9.0: + partners)', count($wpd_blocks) === 12, implode(',', $wpd_blocks));
check('no application post type', !post_type_exists('wpd_application'));
check('participant role not re-created', get_role('wpd_participant') === null);
check('no registration hooks', !has_action('admin_post_wpd_reg_export') && !has_action('admin_post_wpd_reg_status') && !has_filter('show_admin_bar', ['WPD_Registration', 'admin_bar']));
check('no profile redirect for subscribers', !has_action('load-profile.php'));
$css = file_get_contents(WPD_DIR . 'assets/css/wpd.css');
check('css: registration styles removed', !has($css, '.wpd-reg') && !has($css, '.wpd-cabinet') && !has($css, '.wpd-status'));
check('settings css: registration page styles removed', !has(file_get_contents(WPD_DIR . 'assets/css/wpd-settings.css'), 'wpd-reg'));
$js = file_get_contents(WPD_DIR . 'assets/js/wpd.js');
check('js: show-password code removed', !has($js, 'data-wpd-showpass') && !has($js, 'initShowPassword'));
$help = (function () { ob_start(); $m = new ReflectionMethod('WPD_Settings', 'render_help'); $m->setAccessible(true); $m->invoke(null); return ob_get_clean(); })();
check('help: no registration section', !has($help, 'Регистрация участников') && !has($help, 'wpd_registration'));
$main = file_get_contents(WPD_DIR . 'wp-olympiad-design.php');
check('plugin header: no registration in description', !has($main, 'регистрация участников') && has($main, 'Version:           ' . WPD_VERSION));
$readme = file_get_contents(WPD_DIR . 'README.md');
check('readme: 2.2.0 note', has($readme, '## Регистрация участников убрана (2.2.0)'));
check('cleanup already done on this site', WPD_Legacy_Cleanup::is_done() && in_array(get_option(WPD_Legacy_Cleanup::DONE_OPTION), ['2.2.0', WPD_VERSION], true));
$p = get_post(wp_insert_post(['post_title' => 'P raw', 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '[wpd_registration]']));
check('old shortcode left as plain text', trim(apply_filters('the_content', $p->post_content)) === '<p>[wpd_registration]</p>');
wp_delete_post($p->ID, true);

/* ---------- Данные 2.1.0 ---------- */
add_role('wpd_participant', 'Участник олимпиады', ['read' => true]);
update_option('wpd_registration', ['title' => 'Регистрация', 'form_page' => 5]);
update_option('wpd_reg_counter', 42, false);
set_transient('wpd_reg_rl_abcdef0123456789abcd', 3, HOUR_IN_SECONDS);
set_transient('wpd_reg_done_tok123', 77, HOUR_IN_SECONDS);
set_transient('wpd_other_keep', 1, HOUR_IN_SECONDS);
update_option('wpd_settings_keep_probe', 1, false);
$apps = [];
foreach (['publish', 'publish', 'private', 'draft', 'trash'] as $i => $st) {
    $id = wp_insert_post(['post_title' => 'Заявка ' . $i, 'post_type' => 'wpd_application', 'post_status' => $st]);
    update_post_meta($id, '_wpd_number', 'OL-000' . $i);
    update_post_meta($id, '_wpd_fields', ['grade' => '9']);
    $apps[] = $id;
}
$users = [];
for ($i = 0; $i < 5; $i++) {
    $users[] = wp_insert_user(['user_login' => 'p_part' . $i, 'user_email' => "p_part$i@p-test.ru", 'user_pass' => wp_generate_password(), 'role' => 'wpd_participant', 'meta_input' => ['wpd_own_password' => 1]]);
}
$editor = wp_insert_user(['user_login' => 'p_editor', 'user_email' => 'p_editor@p-test.ru', 'user_pass' => wp_generate_password(), 'role' => 'wpd_participant']);
(new WP_User($editor))->add_role('editor');
$multi = wp_insert_user(['user_login' => 'p_multi', 'user_email' => 'p_multi@p-test.ru', 'user_pass' => wp_generate_password(), 'role' => 'wpd_participant']);
(new WP_User($multi))->add_role('subscriber');
$author_part = wp_insert_user(['user_login' => 'p_withpost', 'user_email' => 'p_withpost@p-test.ru', 'user_pass' => wp_generate_password(), 'role' => 'wpd_participant']);
$their_post = wp_insert_post(['post_title' => 'Запись участника', 'post_type' => 'post', 'post_status' => 'draft', 'post_author' => $author_part]);
$plain = wp_insert_user(['user_login' => 'p_plain', 'user_email' => 'p_plain@p-test.ru', 'user_pass' => wp_generate_password(), 'role' => 'subscriber', 'meta_input' => ['wpd_own_password' => 1]]);
update_user_meta($admin, 'manageedit-wpd_applicationcolumnshidden', ['wpd_email']);
update_user_meta($admin, 'edit_wpd_application_per_page', 50);
$pg_sc = wp_insert_post(['post_title' => 'P форма', 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => "<p>Текст</p>\n[wpd_registration title=\"Заявка\"]"]);
$pg_bl = wp_insert_post(['post_title' => 'P кабинет', 'post_type' => 'page', 'post_status' => 'draft', 'post_content' => '<!-- wp:wpd/cabinet {"title":"Кабинет"} /-->']);
$pg_cab = wp_insert_post(['post_title' => 'P шорткод кабинета', 'post_type' => 'post', 'post_status' => 'private', 'post_content' => '[wpd_cabinet]']);
$pg_trash = wp_insert_post(['post_title' => 'P в корзине', 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '<!-- wp:wpd/registration /-->']);
wp_trash_post($pg_trash);
$pg_clean = wp_insert_post(['post_title' => 'P обычная', 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '<!-- wp:wpd/hero /--> регистрация']);
delete_option(WPD_Legacy_Cleanup::DONE_OPTION);
wp_cache_flush();
check('seed: legacy data present', count_apps() === 5 && count(get_users(['role' => 'wpd_participant', 'fields' => 'ID'])) === 8 && count_trans() === 2);

/* ---------- Порции ---------- */
$runs = 0;
$first = WPD_Legacy_Cleanup::run(2);
$runs++;
check('batch 1: not finished', $first === false);
check('batch 1: only 2 applications deleted', count_apps() === 3, (string) count_apps());
check('batch 1: settings still there until the end', get_option('wpd_registration') !== false && get_role('wpd_participant') !== null);
$done = false;
while (!$done && $runs < 20) {
    $done = WPD_Legacy_Cleanup::run(2);
    $runs++;
}
check('batches: finished', $done === true && $runs >= 4 && $runs < 20, (string) $runs);
wp_cache_flush();
check('applications deleted (all statuses, incl. trash)', count_apps() === 0);
check('application meta deleted', (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id IN (" . implode(',', array_fill(0, count($apps), '%d')) . ')', $apps)) === 0);
check('participants deleted', !array_filter(array_map('get_userdata', $users)));
$ed = get_userdata($editor);
check('participant + editor: account kept, role removed', $ed && $ed->roles === ['editor'], $ed ? implode(',', $ed->roles) : 'deleted');
$mu = get_userdata($multi);
check('participant + subscriber: account kept as subscriber', $mu && array_values($mu->roles) === ['subscriber'], $mu ? implode(',', $mu->roles) : 'deleted');
check('participant with a post: deleted, post reassigned to admin', get_userdata($author_part) === false && (int) get_post($their_post)->post_author === $admin);
check('other users untouched', get_userdata($plain) && get_userdata($plain)->roles === ['subscriber'] && get_userdata($admin)->roles === ['administrator']);
check('role removed', get_role('wpd_participant') === null && !wp_roles()->is_role('wpd_participant'));
check('options removed', get_option('wpd_registration', 'GONE') === 'GONE' && get_option('wpd_reg_counter', 'GONE') === 'GONE');
check('registration transients removed, others kept', count_trans() === 0 && get_transient('wpd_other_keep') == 1);
check('user meta removed (incl. users kept)', (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key IN ('wpd_own_password','manageedit-wpd_applicationcolumnshidden','edit_wpd_application_per_page')") === 0);
check('plugin settings untouched', get_option('wpd_settings_keep_probe') == 1);
$pages = (array) get_option(WPD_Legacy_Cleanup::PAGES_OPTION, []);
sort($pages);
$want = [$pg_sc, $pg_bl, $pg_cab];
sort($want);
check('pages with leftover shortcodes/blocks listed (not trash, not ordinary)', $pages === $want, implode(',', $pages));
check('pages themselves not modified', has(get_post($pg_sc)->post_content, '[wpd_registration') && get_post_status($pg_bl) === 'draft');

/* ---------- maybe_run: блокировка и отметка ---------- */
$a2 = wp_insert_post(['post_title' => 'Заявка поздняя', 'post_type' => 'wpd_application', 'post_status' => 'publish']);
delete_option(WPD_Legacy_Cleanup::DONE_OPTION);
set_transient(WPD_Legacy_Cleanup::LOCK, 1, 60);
WPD_Legacy_Cleanup::maybe_run();
check('maybe_run: waits while locked', get_post($a2) !== null && !WPD_Legacy_Cleanup::is_done());
delete_transient(WPD_Legacy_Cleanup::LOCK);
WPD_Legacy_Cleanup::maybe_run();
check('lock not matched by transient cleanup', strpos(WPD_Legacy_Cleanup::LOCK, 'wpd_reg_') !== 0);
check('maybe_run: runs and marks done', get_post($a2) === null && get_option(WPD_Legacy_Cleanup::DONE_OPTION) === WPD_VERSION && get_transient(WPD_Legacy_Cleanup::LOCK) === false);
$a3 = wp_insert_post(['post_title' => 'Заявка после', 'post_type' => 'wpd_application', 'post_status' => 'publish']);
WPD_Legacy_Cleanup::maybe_run();
check('maybe_run: no-op once done', get_post($a3) !== null);
wp_delete_post($a3, true);
check('done option autoloaded (no extra query per request)', !in_array($wpdb->get_var("SELECT autoload FROM {$wpdb->options} WHERE option_name = 'wpd_reg_removed'"), ['no', 'off'], true));

/* ---------- Уведомление ---------- */
$notice = function () { ob_start(); WPD_Legacy_Cleanup::notice(); return ob_get_clean(); };
wp_set_current_user($plain);
check('notice: hidden from non-admins', $notice() === '');
wp_set_current_user($admin);
$n = $notice();
check('notice: warning with all three pages', has($n, 'notice notice-warning') && has($n, '>P форма</a>') && has($n, '>P кабинет</a>') && has($n, '>P шорткод кабинета</a>'), $n);
check('notice: edit links', has($n, 'post.php?post=' . $pg_sc . '&#038;action=edit">P форма</a>'));
check('notice: dismiss link with nonce', (bool) preg_match('/admin-post\.php\?action=wpd_reg_pages_dismiss&#038;_wpnonce=[a-f0-9]{10}/', $n));
check('notice: text', has($n, 'регистрация участников и личный кабинет убраны из плагина, заявки и аккаунты участников удалены.'));
wp_update_post(['ID' => $pg_sc, 'post_content' => '<p>Текст</p>']);
$n = $notice();
check('notice: page fixed -> dropped from list', !has($n, '>P форма</a>') && has($n, '>P кабинет</a>'));
check('notice: option updated', count((array) get_option(WPD_Legacy_Cleanup::PAGES_OPTION)) === 2);
wp_trash_post($pg_bl);
wp_delete_post($pg_cab, true);
check('notice: nothing left -> no notice, option removed', $notice() === '' && get_option(WPD_Legacy_Cleanup::PAGES_OPTION, 'GONE') === 'GONE');
$pg_none = WPD_Legacy_Cleanup::find_pages();
check('find_pages: none -> option not created', $pg_none === [] && get_option(WPD_Legacy_Cleanup::PAGES_OPTION, 'GONE') === 'GONE');
check('fresh site: run() is quick no-op', WPD_Legacy_Cleanup::run() === true);
wp_set_current_user(0);

/* ---------- Удаление плагина до завершения очистки ---------- */
$un = file_get_contents(WPD_DIR . 'uninstall.php');
check('uninstall: runs full cleanup and removes own options', has($un, 'WPD_Legacy_Cleanup::run(0);') && has($un, 'delete_option(WPD_Legacy_Cleanup::DONE_OPTION);'));

// Уборка
foreach ([$pg_sc, $pg_bl, $pg_trash, $pg_clean, $their_post] as $id) { wp_delete_post($id, true); }
foreach ([$editor, $multi, $plain] as $id) { wp_delete_user($id); }
delete_option('wpd_settings_keep_probe');
delete_transient('wpd_other_keep');
update_option(WPD_Legacy_Cleanup::DONE_OPTION, '2.2.0');
echo implode("\n", $results), "\n\nTOTAL: ", count($results), ", FAILED: ", $fails, "\n";
