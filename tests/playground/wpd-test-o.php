<?php
// Фаза O (2.1.0): пароль при регистрации и смена пароля в личном кабинете — PHP-часть.
// Настройки, проверка пароля, поля формы (значение не выводится обратно), аккаунт с паролем из формы, подстановки писем,
// сообщения кабинета, форма смены пароля, страница настроек, справка, JS и CSS. В конце удаляет свои данные.
register_shutdown_function(function () { $e = error_get_last(); if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR], true)) { echo "\nSHUTDOWN: {$e['message']} in {$e['file']}:{$e['line']}\n"; } });
ini_set('display_errors', '1');
error_reporting(E_ALL);
require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
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
function set_reg(array $values): void {
    global $wp_filter;
    $saved = $wp_filter['sanitize_option_wpd_registration'] ?? null;
    unset($wp_filter['sanitize_option_wpd_registration']);
    update_option('wpd_registration', $values);
    if ($saved !== null) { $wp_filter['sanitize_option_wpd_registration'] = $saved; }
    WPD_Registration::flush_cache();
}
function set_state(?array $st): void {
    $p = new ReflectionProperty('WPD_Registration', 'state');
    $p->setAccessible(true);
    $p->setValue(null, $st);
}
function cleanup_o(): void {
    foreach (get_posts(['post_type' => 'wpd_application', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'suppress_filters' => true]) as $id) {
        wp_delete_post($id, true);
    }
    foreach (get_users(['search' => '*@o-test.ru', 'search_columns' => ['user_email'], 'fields' => 'ID']) as $uid) {
        wp_delete_user((int) $uid);
    }
    delete_option('wpd_registration');
    delete_option('wpd_reg_counter');
    WPD_Registration::flush_cache();
}
$mails = [];
add_filter('pre_wp_mail', function ($r, $atts) use (&$mails) {
    $mails[] = ['to' => (string) (is_array($atts['to']) ? implode(',', $atts['to']) : $atts['to']), 'subject' => (string) $atts['subject'], 'message' => (string) $atts['message']];
    return true;
}, 10, 2);
cleanup_o();
wp_set_current_user(0);
set_state(null);

/* ---------- Настройки ---------- */
$map = WPD_Registration::field_map();
check('version 2.1.0', WPD_VERSION === '2.1.0');
check('setting: password checkbox on by default', ($map['password']['type'] ?? '') === 'checkbox' && $map['password']['default'] === 1 && $map['password']['section'] === 'reg_main');
check('setting: password_min 8 (6..64)', ($map['password_min']['default'] ?? 0) === 8 && $map['password_min']['min'] === 6 && $map['password_min']['max'] === 64);
$keys = array_keys($map);
check('setting: placed right after accounts', array_search('password', $keys, true) === array_search('accounts', $keys, true) + 1 && array_search('password_min', $keys, true) === array_search('accounts', $keys, true) + 2);
check('setting: accounts description without set-password link', !has($map['accounts']['desc'], 'ссылкой для установки пароля') && has($map['accounts']['desc'], 'по e-mail (или логину) и паролю'));
$s = WPD_Registration::sanitize(['_tab' => 'form', 'password_min' => '3', 'accounts' => '1']);
check('sanitize: password unchecked -> 0, min clamped to 6', $s['password'] === 0 && $s['password_min'] === 6);
$s = WPD_Registration::sanitize(['_tab' => 'form', 'password' => '1', 'password_min' => '500']);
check('sanitize: password on, min clamped to 64', $s['password'] === 1 && $s['password_min'] === 64);
check('mail vars documented', has($map['mail_user_body']['desc'], '{login}') && has($map['mail_user_body']['desc'], '{login_info}'));
check('default user mail has {login_info}', has($map['mail_user_body']['default'], "Личный кабинет: {cabinet}\n{login_info}"));

/* ---------- Проверка пароля ---------- */
$pe = function ($p, $e = 'ivan@o-test.ru', $m = 8) { return WPD_Registration::password_error($p, $e, $m); };
check('password: empty', $pe('') === 'Придумайте пароль.');
check('password: too short (8)', $pe('abc1234') === 'Пароль должен быть не короче 8 символов.');
check('password: too short (6) plural', $pe('ab12', '', 6) === 'Пароль должен быть не короче 6 символов.');
check('password: exactly min ok', $pe('abcd1234') === '');
check('password: letters only', $pe('abcdefghij') === 'Пароль должен содержать буквы и цифры.');
check('password: digits only', $pe('1234567890') === 'Пароль должен содержать буквы и цифры.');
check('password: cyrillic letters ok', $pe('олимпиада2026') === '');
check('password: spaces allowed', $pe('мой пароль 7') === '');
check('password: backslash refused', $pe('abcd\\1234') === 'Пароль не может содержать символ «\\».');
check('password: max 128', $pe(str_repeat('a1', 65)) === 'Пароль должен быть не длиннее 128 символов.' && $pe(str_repeat('a1', 64)) === '');
check('password: equals email local part', $pe('Ivan2026', 'ivan2026@o-test.ru') === 'Пароль не должен совпадать с e-mail.');
check('password: equals email', $pe('ivan2026@o-test.ru', 'ivan2026@o-test.ru') === 'Пароль не должен совпадать с e-mail.');
check('password: min from settings', (function () { set_reg(['password_min' => 10]); $r = WPD_Registration::password_error('abcd12345'); set_reg([]); return $r === 'Пароль должен быть не короче 10 символов.'; })());

/* ---------- Когда пароль задаётся в форме ---------- */
set_reg([]);
check('wants_password: guest, defaults', WPD_Registration::wants_password() === true);
check('wants_password: accounts off', WPD_Registration::wants_password(['accounts' => 0, 'password' => 1]) === false);
check('wants_password: password off', WPD_Registration::wants_password(['accounts' => 1, 'password' => 0]) === false);
$adm = 1;
wp_set_current_user($adm);
check('wants_password: logged in user', WPD_Registration::wants_password() === false);
wp_set_current_user(0);

/* ---------- Поля формы ---------- */
$h = WPD_Registration::render_form();
check('form: password field', has($h, '<input type="password" id="wpd-reg-pass" name="wpd_pass" value="" autocomplete="new-password" minlength="8" maxlength="128" required data-wpd-pass aria-describedby="wpd-reg-pass-hint">'), $h);
check('form: repeat field', has($h, '<input type="password" id="wpd-reg-pass2" name="wpd_pass2" value="" autocomplete="new-password" minlength="8" maxlength="128" required data-wpd-pass>'));
check('form: labels', has($h, '<label for="wpd-reg-pass">Пароль<span class="wpd-reg__req" aria-hidden="true">*</span></label>') && has($h, '<label for="wpd-reg-pass2">Повторите пароль<span'));
check('form: hint', has($h, '<p class="wpd-reg__hint" id="wpd-reg-pass-hint">Не короче 8 символов, буквы и цифры. С e-mail и этим паролем вы войдёте в личный кабинет.</p>'));
check('form: own grid block', has($h, '<div class="wpd-reg__grid wpd-reg__pass">'));
check('form: show password toggle hidden without JS', has($h, '<label class="wpd-reg__check wpd-reg__showpass" hidden data-wpd-showpass><input type="checkbox"> <span>Показать пароль</span></label>'));
$pos = [strpos($h, 'name="wpd_reg[city]"'), strpos($h, 'name="wpd_pass"'), strpos($h, 'name="wpd_pass2"'), strpos($h, 'name="wpd_consent"')];
check('form: order fields -> password -> repeat -> consent', $pos[0] < $pos[1] && $pos[1] < $pos[2] && $pos[2] < $pos[3], implode(',', $pos));
check('form: 6 data fields unchanged', substr_count($h, 'name="wpd_reg[') === 6);
set_reg(['password_min' => 6]);
$h = WPD_Registration::render_form();
check('form: min 6 in hint and attribute', has($h, 'Не короче 6 символов') && has($h, 'minlength="6"'));
set_reg(['password_min' => 21]);
check('form: plural 21 символа', has(WPD_Registration::render_form(), 'Не короче 21 символа,'));
set_reg(['password' => 0]);
$h = WPD_Registration::render_form();
check('form: no password fields when off', !has($h, 'wpd_pass') && !has($h, 'data-wpd-showpass'));
set_reg(['accounts' => 0]);
check('form: no password fields without accounts', !has(WPD_Registration::render_form(), 'wpd_pass'));
set_reg([]);
wp_set_current_user($adm);
check('form: no password fields for logged-in user', !has(WPD_Registration::render_form(), 'wpd_pass'));
wp_set_current_user(0);
// Ошибки пароля
set_state(['form' => 'register', 'values' => ['name' => 'Иванов Иван', 'email' => 'ivan@o-test.ru'], 'errors' => ['_pass' => 'Пароль должен содержать буквы и цифры.'], 'notice' => '']);
$h = WPD_Registration::render_form();
check('form error: summary counts password', has($h, 'Проверьте форму: 1 поле заполнено неверно.'));
check('form error: password field marked', has($h, 'name="wpd_pass" value="" autocomplete="new-password" minlength="8" maxlength="128" required data-wpd-pass aria-invalid="true" aria-describedby="wpd-reg-pass-hint wpd-reg-pass-err">'));
check('form error: message', has($h, '<p class="wpd-reg__error" id="wpd-reg-pass-err">Пароль должен содержать буквы и цифры.</p>') && has($h, 'wpd-reg__field wpd-reg__field--error"><label for="wpd-reg-pass">'));
check('form error: other values kept', has($h, 'value="Иванов Иван"') && has($h, 'value="ivan@o-test.ru"'));
set_state(['form' => 'register', 'values' => [], 'errors' => ['_pass2' => 'Пароли не совпадают.'], 'notice' => '']);
$h = WPD_Registration::render_form();
check('form error: mismatch on repeat field', has($h, 'name="wpd_pass2" value="" autocomplete="new-password" minlength="8" maxlength="128" required data-wpd-pass aria-invalid="true" aria-describedby="wpd-reg-pass2-err">') && has($h, 'Пароли не совпадают.'));
set_state(null);
$_POST = ['wpd_pass' => 'Secret123xyz', 'wpd_pass2' => 'Secret123xyz'];
check('form: posted password never echoed', !has(WPD_Registration::render_form(), 'Secret123xyz'));
$_POST = [];

/* ---------- Аккаунт ---------- */
$cu = new ReflectionMethod('WPD_Registration', 'create_user');
$cu->setAccessible(true);
$u1 = $cu->invoke(null, ['name' => 'Орлова Ольга', 'email' => 'olga@o-test.ru'], 'Olga2026pass');
check('account: created with own password', is_int($u1) && wp_check_password('Olga2026pass', get_userdata($u1)->user_pass, $u1));
check('account: own password flag', (int) get_user_meta($u1, 'wpd_own_password', true) === 1);
check('account: role and login', get_userdata($u1)->roles === ['wpd_participant'] && get_userdata($u1)->user_login === 'olga');
$u2 = $cu->invoke(null, ['name' => 'Ли Ким', 'email' => 'kim@o-test.ru']);
check('account: random password without own', is_int($u2) && !wp_check_password('', get_userdata($u2)->user_pass, $u2) && (int) get_user_meta($u2, 'wpd_own_password', true) === 0);
$sig = wp_authenticate('olga@o-test.ru', 'Olga2026pass');
check('account: login by email and password', $sig instanceof WP_User && $sig->ID === $u1);
check('account: login by login and password', wp_authenticate('olga', 'Olga2026pass') instanceof WP_User);
check('account: wrong password refused', is_wp_error(wp_authenticate('olga@o-test.ru', 'wrong1234')));

/* ---------- Письма ---------- */
set_reg([]);
$a1 = WPD_Registration::create_application(['name' => 'Орлова Ольга', 'email' => 'olga@o-test.ru'], $u1);
$a2 = WPD_Registration::create_application(['name' => 'Ли Ким', 'email' => 'kim@o-test.ru'], $u2);
$a3 = WPD_Registration::create_application(['name' => 'Без аккаунта', 'email' => 'guest@o-test.ru'], 0);
$v1 = WPD_Registration::mail_vars(get_post($a1));
check('mail vars: login', $v1['{login}'] === 'olga');
check('mail vars: login_info own password', $v1['{login_info}'] === 'Для входа используйте e-mail olga@o-test.ru (или логин olga) и пароль, который вы указали при регистрации.');
$v2 = WPD_Registration::mail_vars(get_post($a2));
check('mail vars: login_info set-password link', $v2['{login_info}'] === 'Ссылку для установки пароля WordPress пришлёт отдельным письмом. Логин: kim.');
$v3 = WPD_Registration::mail_vars(get_post($a3));
check('mail vars: no account -> empty', $v3['{login}'] === '' && $v3['{login_info}'] === '');
$mails = [];
WPD_Registration::send_mail(get_post($a1), 'user');
WPD_Registration::send_mail(get_post($a3), 'user');
check('mail: user mail tells how to log in', has($mails[0]['message'], 'Личный кабинет: ') && has($mails[0]['message'], 'пароль, который вы указали при регистрации'));
check('mail: password never in mail', !has($mails[0]['message'], 'Olga2026pass'));
check('mail: empty login_info leaves no hole', !has($mails[1]['message'], "\n\n\n") && !has($mails[1]['message'], '{login_info}'), json_encode($mails[1]['message'], JSON_UNESCAPED_UNICODE));
check('mail: body trimmed', $mails[1]['message'] === trim($mails[1]['message']));

/* ---------- Кабинет ---------- */
$pg = wp_insert_post(['post_title' => 'Кабинет O', 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '[wpd_cabinet]']);
set_reg(['cabinet_page' => $pg]);
set_transient('wpd_reg_done_otok1', $a1, 60);
set_transient('wpd_reg_done_otok2', $a2, 60);
$_GET = ['wpd_reg' => 'done', 't' => 'otok1'];
check('cabinet guest after registration: own password hint', has(WPD_Registration::render_cabinet(), 'Войдите с e-mail и паролем, которые вы указали при регистрации.'));
$_GET = ['wpd_reg' => 'done', 't' => 'otok2'];
check('cabinet guest after registration: link hint without own password', has(WPD_Registration::render_cabinet(), 'Ссылка для установки пароля отправлена на kim@o-test.ru.'));
$_GET = [];
wp_set_current_user($u1);
$c = WPD_Registration::render_cabinet();
check('cabinet: «Сменить пароль» link', (bool) preg_match('/Орлова Ольга · <a href="[^"]*wpd_pass=1#wpd-cabinet">Сменить пароль<\/a> · <a href="[^"]*action=logout/u', $c));
$_GET = ['wpd_pass' => '1'];
$c = WPD_Registration::render_cabinet();
check('cabinet pass form: title and action', has($c, '<h3 class="wpd-cabinet__subtitle">Смена пароля</h3>') && has($c, '<input type="hidden" name="wpd_reg_action" value="password">'));
check('cabinet pass form: nonce', (bool) preg_match('/name="_wpd_nonce" value="[a-f0-9]{10}"/', $c));
check('cabinet pass form: current password', has($c, 'name="wpd_pass_current" value="" autocomplete="current-password" maxlength="128" required data-wpd-pass>'));
check('cabinet pass form: new + repeat', has($c, 'id="wpd-reg-pass" name="wpd_pass"') && has($c, 'id="wpd-reg-pass2" name="wpd_pass2"') && has($c, '>Новый пароль<') && has($c, '>Повторите новый пароль<'));
check('cabinet pass form: toggle and cancel', has($c, 'data-wpd-showpass') && has($c, '>Отмена</a>') && has($c, '>Сменить пароль</button>'));
check('cabinet pass form: posts to cabinet without wpd_pass', (bool) preg_match('/<form class="wpd-reg__form wpd-cabinet__pass" method="post" action="[^"]*#wpd-cabinet">/', $c) && !preg_match('/<form[^>]*wpd_pass=1/', $c));
check('cabinet pass form: application card hidden', !has($c, 'wpd-cabinet__number'));
$_GET = [];
set_state(['form' => 'password', 'values' => [], 'errors' => ['_cur' => 'Неверный текущий пароль.'], 'notice' => '']);
$c = WPD_Registration::render_cabinet();
check('cabinet pass errors: form shown again with message', has($c, 'Пароль не изменён — проверьте поля.') && has($c, '<p class="wpd-reg__error" id="wpd-reg-pass-current-err">Неверный текущий пароль.</p>'));
set_state(null);
$_GET = ['wpd_reg' => 'pass_saved'];
$c = WPD_Registration::render_cabinet();
check('cabinet: password changed notice', has($c, 'Пароль изменён.') && has($c, 'wpd-cabinet__number'));
$_GET = [];
wp_set_current_user($adm);
$_GET = ['wpd_pass' => '1'];
$c = WPD_Registration::render_cabinet();
check('cabinet: admin gets no password form (has profile)', !has($c, 'Смена пароля') && !has($c, 'Сменить пароль'));
$_GET = [];
wp_set_current_user(0);
// Неучастник не может сменить пароль через кабинет
wp_set_current_user($adm);
$pp = new ReflectionMethod('WPD_Registration', 'process_password');
$_POST = ['_wpd_nonce' => wp_create_nonce('wpd_reg_pass'), 'wpd_pass_current' => 'password', 'wpd_pass' => 'NewPass123', 'wpd_pass2' => 'NewPass123'];
$before = get_userdata($adm)->user_pass;
$pp->invoke(null);
check('process_password: ignored for admin', get_userdata($adm)->user_pass === $before);
wp_set_current_user($u1);
$_POST = ['_wpd_nonce' => 'bad', 'wpd_pass_current' => 'Olga2026pass', 'wpd_pass' => 'NewPass123', 'wpd_pass2' => 'NewPass123'];
$pp->invoke(null);
$sp = new ReflectionProperty('WPD_Registration', 'state');
$sp->setAccessible(true);
check('process_password: bad nonce', ($sp->getValue()['errors']['_form'] ?? '') === 'Сессия устарела. Обновите страницу и попробуйте ещё раз.');
$_POST = ['_wpd_nonce' => wp_create_nonce('wpd_reg_pass'), 'wpd_pass_current' => 'wrong', 'wpd_pass' => 'short', 'wpd_pass2' => 'short'];
$pp->invoke(null);
$e = $sp->getValue()['errors'];
check('process_password: wrong current + weak new', ($e['_cur'] ?? '') === 'Неверный текущий пароль.' && ($e['_pass'] ?? '') === 'Пароль должен быть не короче 8 символов.');
$_POST = ['_wpd_nonce' => wp_create_nonce('wpd_reg_pass'), 'wpd_pass_current' => 'Olga2026pass', 'wpd_pass' => 'NewPass123', 'wpd_pass2' => 'NewPass124'];
$pp->invoke(null);
check('process_password: mismatch', ($sp->getValue()['errors']['_pass2'] ?? '') === 'Пароли не совпадают.');
$_POST = ['_wpd_nonce' => wp_create_nonce('wpd_reg_pass'), 'wpd_pass_current' => 'Olga2026pass', 'wpd_pass' => 'Olga2026pass', 'wpd_pass2' => 'Olga2026pass'];
$pp->invoke(null);
check('process_password: same as current', ($sp->getValue()['errors']['_pass'] ?? '') === 'Новый пароль совпадает с текущим.');
check('process_password: password unchanged after errors', wp_check_password('Olga2026pass', get_userdata($u1)->user_pass, $u1));
$_POST = [];
set_state(null);
wp_set_current_user(0);

/* ---------- Страница настроек, справка ---------- */
wp_set_current_user($adm);
ob_start();
$_GET = ['page' => 'wpd-registration'];
WPD_Registration::render_page();
$sp_html = ob_get_clean();
$_GET = [];
check('settings page: password checkbox checked', (bool) preg_match('/<input type="checkbox" name="wpd_registration\[password\]" value="1" checked=\'checked\'> Участник задаёт пароль при регистрации/u', $sp_html));
check('settings page: min length field', has($sp_html, '<input type="number" id="wpd_reg_password_min" name="wpd_registration[password_min]" value="8" min="6" max="64" class="small-text">'));
$help = (function () { ob_start(); $m = new ReflectionMethod('WPD_Settings', 'render_help'); $m->setAccessible(true); $m->invoke(null); return ob_get_clean(); })();
check('help: password paragraph', has($help, 'С 2.1.0 участник сам задаёт пароль') && has($help, '«Сменить пароль»'));
wp_set_current_user(0);

/* ---------- JS и CSS ---------- */
$js = file_get_contents(WPD_DIR . 'assets/js/wpd.js');
check('js: show password init', has($js, 'function initShowPassword(toggle)') && has($js, "querySelectorAll('[data-wpd-showpass]')") && has($js, "input[data-wpd-pass]"));
check('js: fields hidden again before submit', has($js, "form.addEventListener('submit'"));
$css = file_get_contents(WPD_DIR . 'assets/css/wpd.css');
check('css: hidden toggle really hidden (flex override)', (bool) preg_match('/\.wpd-page \.wpd-reg__check\[hidden\] \{\s*display: none;/', $css));
check('css: password inputs styled like other fields in form', (bool) preg_match('/\.wpd-page \.wpd-reg input\[type="password"\],[^{]*\{\s*box-sizing: border-box;\s*width: 100%;/', $css));
check('css: password block and subtitle', has($css, '.wpd-page .wpd-reg__pass {') && has($css, '.wpd-page .wpd-cabinet__subtitle {'));
$un = file_get_contents(WPD_DIR . 'uninstall.php');
check('uninstall: own password flag removed', has($un, "delete_metadata('user', 0, 'wpd_own_password', '', true);"));

wp_delete_post($pg, true);
cleanup_o();
echo implode("\n", $results), "\n\nTOTAL: ", count($results), ", FAILED: ", $fails, "\n";
