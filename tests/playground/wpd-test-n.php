<?php
// Фаза N (2.0.0): регистрация участников — PHP-часть.
// Настройки и конструктор полей, сроки и лимит мест, проверка значений, защита от спама, заявки и номера, статусы и письма,
// CSV, персональные данные, вывод формы и личного кабинета, роль участника и права, админка «Участники», блоки.
// В конце удаляет свои заявки, пользователей и опции.
register_shutdown_function(function () { $e = error_get_last(); if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR], true)) { echo "\nSHUTDOWN: {$e['message']} in {$e['file']}:{$e['line']}\n"; } });
ini_set('display_errors', '1');
error_reporting(E_ALL);
require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once ABSPATH . 'wp-admin/includes/post.php';
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
function msk(string $rel): string {
    return (new DateTime($rel, new DateTimeZone('Europe/Moscow')))->format('Y-m-d H:i');
}
$mails = [];
add_filter('pre_wp_mail', function ($r, $atts) use (&$mails) {
    $mails[] = ['to' => is_array($atts['to']) ? implode(',', $atts['to']) : (string) $atts['to'], 'subject' => (string) $atts['subject'],
                'message' => (string) $atts['message'], 'headers' => $atts['headers']];
    return true;
}, 10, 2);
function cleanup_n(): void {
    foreach (get_posts(['post_type' => 'wpd_application', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'suppress_filters' => true]) as $id) {
        wp_delete_post($id, true);
    }
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach (get_users(['search' => '*@n-test.ru', 'search_columns' => ['user_email'], 'fields' => 'ID']) as $uid) {
        wp_delete_user((int) $uid);
    }
    delete_option('wpd_registration');
    delete_option('wpd_reg_counter');
    WPD_Registration::flush_cache();
}
cleanup_n();
delete_option('wpd_settings');
WPD_Settings::flush_cache();
wp_set_current_user(0);

/* ---------- Настройки ---------- */
$map = WPD_Registration::field_map();
check('settings: 31 fields', count($map) === 31, (string) count($map));
check('settings: 3 tabs', array_keys(WPD_Registration::tabs()) === ['form', 'fields', 'mail']);
$secs = [];
foreach (WPD_Registration::tabs() as $t) { $secs = array_merge($secs, $t['sections']); }
check('settings: every section on a tab', !array_diff(array_unique(array_column($map, 'section')), $secs));
$d = WPD_Registration::defaults();
check('defaults: open, always, unlimited, accounts on', $d['enabled'] === 1 && $d['period'] === 'always' && $d['limit'] === 0 && $d['accounts'] === 1 && $d['autologin'] === 1 && $d['allow_edit'] === 1);
check('defaults: 4 extra fields', array_column($d['fields'], 'id') === ['phone', 'grade', 'school', 'city']);
check('defaults: grade 5..11 select', $d['fields'][1]['type'] === 'select' && WPD_Registration::option_lines($d['fields'][1]['options']) === ['5', '6', '7', '8', '9', '10', '11']);
check('defaults: rate limit 20, prefix OL-', $d['rate_limit'] === 20 && $d['number_prefix'] === 'OL-');
check('defaults: mail templates with placeholders', has($d['mail_user_body'], '{number}') && has($d['mail_user_body'], '{fields}') && has($d['mail_status_subject'], '{status}'));
check('form fields: name and email first', array_column(WPD_Registration::form_fields(), 'id') === ['name', 'email', 'phone', 'grade', 'school', 'city']);

/* ---------- Конструктор полей ---------- */
$f = WPD_Registration::sanitize_fields([
    '_' => '',
    '__i__' => ['label' => 'Шаблон'],
    ['id' => 'phone', 'label' => ' Телефон <b>x</b> ', 'type' => 'tel', 'required' => '1', 'in_list' => '1'],
    ['id' => '', 'label' => 'Предмет', 'type' => 'radio', 'options' => "Математика\n\nФизика\nМатематика\n<i>Химия</i>"],
    ['id' => 'phone', 'label' => 'Дубль id', 'type' => 'text'],
    ['id' => 'email', 'label' => 'Системный id', 'type' => 'text'],
    ['id' => 'wpd_x', 'label' => 'Служебный id', 'type' => 'text'],
    ['label' => 'Список без вариантов', 'type' => 'select', 'options' => "  \n "],
    ['label' => 'Неизвестный тип', 'type' => 'file'],
    ['label' => '', 'type' => 'text'],
    'мусор',
]);
check('builder: empty label, template, junk dropped', count($f) === 7, (string) count($f));
check('builder: label cleaned', $f[0]['label'] === 'Телефон x' && $f[0]['required'] === 1 && $f[0]['in_list'] === 1, $f[0]['label']);
check('builder: options cleaned and deduped', $f[1]['type'] === 'radio' && $f[1]['options'] === "Математика\nФизика\nХимия", $f[1]['options']);
check('builder: generated id', (bool) preg_match('/^f_[a-f0-9]{6}$/', $f[1]['id']), $f[1]['id']);
$ids = array_column($f, 'id');
check('builder: ids unique, no system or wpd ids', count(array_unique($ids)) === count($ids) && !in_array('email', $ids, true) && !in_array('wpd_x', $ids, true), implode(',', $ids));
check('builder: list without options becomes text', $f[5]['type'] === 'text');
check('builder: unknown type becomes text', $f[6]['type'] === 'text');
check('builder: stable id kept', $f[0]['id'] === 'phone');
$many = [];
for ($i = 0; $i < 40; $i++) { $many[] = ['label' => "Поле $i"]; }
check('builder: max 30 fields', count(WPD_Registration::sanitize_fields($many)) === 30);
check('builder: not array -> []', WPD_Registration::sanitize_fields('x') === []);
$ol = WPD_Registration::option_lines(implode("\n", range(1, 70)));
check('options: max 50', count($ol) === 50);

/* ---------- Санитизация ---------- */
$s = WPD_Registration::sanitize(['_tab' => 'form', 'period' => 'evil', 'limit' => '-5', 'rate_limit' => '9999', 'start' => '2030-01-02T10:00',
    'end' => 'вчера', 'form_page' => '999999', 'privacy_url' => 'javascript:alert(1)', 'title' => '<script>x</script>Регистрация', 'fields' => [['label' => 'Не с этой вкладки']]]);
check('sanitize: unknown period -> always', $s['period'] === 'always');
check('sanitize: number clamped', $s['limit'] === 0 && $s['rate_limit'] === 500);
check('sanitize: datetime normalized / invalid empty', $s['start'] === '2030-01-02 10:00' && $s['end'] === '');
check('sanitize: missing page -> 0', $s['form_page'] === 0);
check('sanitize: javascript url dropped', $s['privacy_url'] === '');
check('sanitize: tags stripped', $s['title'] === 'Регистрация', $s['title']);
check('sanitize: unchecked checkboxes -> 0', $s['enabled'] === 0 && $s['accounts'] === 0);
check('sanitize: other tab fields kept', array_column($s['fields'], 'id') === ['phone', 'grade', 'school', 'city']);
$s2 = WPD_Registration::sanitize(['_tab' => 'fields', 'fields' => ['_' => '']]);
check('sanitize: empty builder -> no extra fields', $s2['fields'] === [] && $s2['enabled'] === 1);
$pg = wp_insert_post(['post_title' => 'Кабинет N', 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '[wpd_cabinet]']);
$pgf = wp_insert_post(['post_title' => 'Форма N', 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '[wpd_registration]']);
$s3 = WPD_Registration::sanitize(['_tab' => 'form', 'cabinet_page' => (string) $pg, 'period' => 'dates', 'start' => '2030-01-02T10:00', 'end' => '2030-01-01T10:00']);
check('sanitize: page kept', $s3['cabinet_page'] === $pg);
check('sanitize: end before start warning', in_array('wpd_reg_dates', array_column(get_settings_errors('wpd_registration'), 'code'), true));

/* ---------- Состояние приёма ---------- */
set_reg([]);
check('status: open by default', WPD_Registration::status()['open'] === true);
set_reg(['enabled' => 0]);
check('status: disabled', WPD_Registration::status()['reason'] === 'disabled');
set_reg(['period' => 'dates', 'start' => msk('+2 days'), 'end' => msk('+5 days')]);
check('status: not started', WPD_Registration::status()['reason'] === 'not_started');
set_reg(['period' => 'dates', 'start' => msk('-5 days'), 'end' => msk('-1 minute')]);
check('status: ended', WPD_Registration::status()['reason'] === 'ended');
set_reg(['period' => 'dates', 'start' => msk('-1 day'), 'end' => msk('+1 day')]);
check('status: within dates', WPD_Registration::status()['open'] === true);
set_reg(['period' => 'always', 'start' => msk('+2 days')]);
check('status: dates ignored when always', WPD_Registration::status()['open'] === true);
update_option('wpd_settings', ['countdown_date' => msk('-1 hour')]);
WPD_Settings::flush_cache();
set_reg(['period' => 'countdown']);
check('status: countdown start passed -> ended', WPD_Registration::status()['reason'] === 'ended');
update_option('wpd_settings', ['countdown_date' => msk('+3 days')]);
WPD_Settings::flush_cache();
check('status: before countdown start -> open', WPD_Registration::status()['open'] === true);
delete_option('wpd_settings');
WPD_Settings::flush_cache();

/* ---------- Проверка значений ---------- */
set_reg([]);
$fields = WPD_Registration::form_fields();
[$v, $e] = WPD_Registration::validate([], $fields);
check('validate: all required errors', array_keys($e) === ['name', 'email', 'phone', 'grade', 'school', 'city'], implode(',', array_keys($e)));
check('validate: select required message', $e['grade'] === 'Выберите вариант.');
[$v, $e] = WPD_Registration::validate(['name' => '  Иванов <b>Иван</b> ', 'email' => ' Ivan@N-Test.RU ', 'phone' => '+7 (900) 123-45-67',
    'grade' => '9', 'school' => 'Лицей № 1', 'city' => 'Красноярск', 'extra' => 'лишнее'], $fields);
check('validate: valid input no errors', $e === [], json_encode($e, JSON_UNESCAPED_UNICODE));
check('validate: cleaned values', $v['name'] === 'Иванов Иван' && $v['email'] === 'ivan@n-test.ru' && $v['grade'] === '9', json_encode($v, JSON_UNESCAPED_UNICODE));
check('validate: unknown keys ignored', !isset($v['extra']));
[$v, $e] = WPD_Registration::validate(['name' => '12345', 'email' => 'не почта', 'phone' => '12-34', 'grade' => '12', 'school' => 'x', 'city' => 'y'], $fields);
check('validate: name needs letters', isset($e['name']));
check('validate: bad email', $e['email'] === 'Укажите корректный e-mail, например name@example.ru.');
check('validate: bad email kept for correction', $v['email'] === 'не почта');
[$v2, $e2] = WPD_Registration::validate(['email' => 'ivanov@mail'], [['id' => 'email', 'label' => 'E', 'type' => 'email', 'required' => 1, 'options' => '', 'placeholder' => '']]);
check('validate: email without dot in domain kept', isset($e2['email']) && $v2['email'] === 'ivanov@mail');
check('validate: short phone', isset($e['phone']));
check('validate: option not in list', $e['grade'] === 'Выберите вариант из списка.' && $v['grade'] === '');
[, $e] = WPD_Registration::validate(['phone' => '+7 900 123 45 67 доб'], [['id' => 'phone', 'label' => 'T', 'type' => 'tel', 'required' => 0, 'options' => '', 'placeholder' => '']]);
check('validate: letters in phone', isset($e['phone']));
$types = [
    ['id' => 'n', 'label' => 'N', 'type' => 'number', 'required' => 0, 'options' => '', 'placeholder' => ''],
    ['id' => 'd', 'label' => 'D', 'type' => 'date', 'required' => 0, 'options' => '', 'placeholder' => ''],
    ['id' => 'c', 'label' => 'C', 'type' => 'checkbox', 'required' => 1, 'options' => '', 'placeholder' => ''],
    ['id' => 'r', 'label' => 'R', 'type' => 'radio', 'required' => 0, 'options' => "а\nб", 'placeholder' => ''],
    ['id' => 't', 'label' => 'T', 'type' => 'textarea', 'required' => 0, 'options' => '', 'placeholder' => ''],
    ['id' => 's', 'label' => 'S', 'type' => 'text', 'required' => 0, 'options' => '', 'placeholder' => ''],
];
[$v, $e] = WPD_Registration::validate(['n' => '12,5', 'd' => '2010-02-30', 'r' => 'б', 't' => "строка 1\nстрока 2", 's' => str_repeat('я', 300)], $types);
check('validate: decimal comma number', $v['n'] === '12.5' && !isset($e['n']));
check('validate: impossible date', isset($e['d']));
check('validate: required checkbox', $e['c'] === 'Отметьте этот пункт.' && $v['c'] === '');
check('validate: radio option', $v['r'] === 'б' && !isset($e['r']));
check('validate: textarea keeps newlines', $v['t'] === "строка 1\nстрока 2");
check('validate: text max 200', mb_strlen($v['s']) === 200);
[$v, $e] = WPD_Registration::validate(['n' => 'abc', 'd' => '2010-02-28', 'c' => '1'], $types);
check('validate: non-numeric', $e['n'] === 'Укажите число.' && $v['d'] === '2010-02-28' && $v['c'] === '1' && !isset($e['d']));

/* ---------- Защита от спама ---------- */
check('token: fresh rejected (too fast)', !WPD_Registration::check_token(WPD_Registration::form_token()));
check('token: 10 s old accepted', WPD_Registration::check_token(WPD_Registration::form_token(time() - 10)));
check('token: 8 days old rejected', !WPD_Registration::check_token(WPD_Registration::form_token(time() - 8 * DAY_IN_SECONDS)));
$tok = WPD_Registration::form_token(time() - 10);
check('token: forged signature rejected', !WPD_Registration::check_token(substr($tok, 0, -1) . (substr($tok, -1) === 'a' ? 'b' : 'a')));
check('token: garbage rejected', !WPD_Registration::check_token('') && !WPD_Registration::check_token('abc.def'));
add_filter('wpd_reg_min_seconds', '__return_zero');
check('token: min seconds filter', WPD_Registration::check_token(WPD_Registration::form_token()));
remove_filter('wpd_reg_min_seconds', '__return_zero');
set_reg(['rate_limit' => 2]);
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
$rk = new ReflectionMethod('WPD_Registration', 'rate_hit');
$rk->setAccessible(true);
check('rate: not limited at start', !WPD_Registration::rate_limited());
$rk->invoke(null);
$rk->invoke(null);
check('rate: limited after 2', WPD_Registration::rate_limited());
$_SERVER['REMOTE_ADDR'] = '203.0.113.8';
check('rate: other address not limited', !WPD_Registration::rate_limited());
set_reg(['rate_limit' => 0]);
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
check('rate: 0 = unlimited', !WPD_Registration::rate_limited());
$tkeys = $GLOBALS['wpdb']->get_col("SELECT option_name FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE '_transient_wpd_reg_rl_%'");
check('rate: ip not stored in clear', $tkeys && !array_filter($tkeys, function ($k) { return has($k, '203.0.113'); }));
foreach ($tkeys as $k) { delete_option($k); delete_option(str_replace('_transient_', '_transient_timeout_', $k)); }

/* ---------- Заявки, номера, поиск ---------- */
set_reg([]);
$vals = ['name' => 'Петрова Анна', 'email' => 'anna@n-test.ru', 'phone' => '+79001112233', 'grade' => '10', 'school' => 'Гимназия 2', 'city' => 'Ачинск'];
$a1 = WPD_Registration::create_application($vals, 0);
$app1 = get_post($a1);
check('app: created', $a1 > 0 && $app1->post_type === 'wpd_application' && $app1->post_status === 'publish');
check('app: number OL-0001', get_post_meta($a1, '_wpd_number', true) === 'OL-0001', (string) get_post_meta($a1, '_wpd_number', true));
check('app: status new', WPD_Registration::app_status($app1) === 'new');
check('app: title = name', $app1->post_title === 'Петрова Анна');
check('app: extra fields meta without name/email', get_post_meta($a1, '_wpd_fields', true) === ['phone' => '+79001112233', 'grade' => '10', 'school' => 'Гимназия 2', 'city' => 'Ачинск']);
check('app: values merged', WPD_Registration::app_values($app1)['email'] === 'anna@n-test.ru' && WPD_Registration::app_values($app1)['name'] === 'Петрова Анна');
check('app: searchable excerpt', has($app1->post_excerpt, 'OL-0001') && has($app1->post_excerpt, 'Гимназия 2'));
check('app: find by email case-insensitive', WPD_Registration::find_by_email(' ANNA@n-test.ru ') && WPD_Registration::find_by_email('ANNA@n-test.ru')->ID === $a1);
check('app: find by unknown email', WPD_Registration::find_by_email('nobody@n-test.ru') === null && WPD_Registration::find_by_email('') === null);
$a2 = WPD_Registration::create_application(['name' => 'Сидоров Олег', 'email' => 'oleg@n-test.ru', 'grade' => '7'], 0);
check('app: sequential number', get_post_meta($a2, '_wpd_number', true) === 'OL-0002');
set_reg(['number_prefix' => '2026/']);
$a3 = WPD_Registration::create_application(['name' => 'Ким Ли', 'email' => 'kim@n-test.ru'], 0);
check('app: custom prefix', get_post_meta($a3, '_wpd_number', true) === '2026/0003');
set_reg([]);
// Одновременная заявка: другой запрос увеличивает счётчик между чтением и записью — номер не должен повториться
$before = (int) get_option('wpd_reg_counter');
$injected = 0;
$inj = function ($q) use (&$injected) {
    global $wpdb;
    if ($injected === 0 && strpos($q, "UPDATE {$wpdb->options} SET option_value") === 0 && strpos($q, 'wpd_reg_counter') !== false) {
        $injected = 1;
        $wpdb->query("UPDATE {$wpdb->options} SET option_value = option_value + 5 WHERE option_name = 'wpd_reg_counter'");
    }
    return $q;
};
add_filter('query', $inj);
$num = WPD_Registration::next_number();
remove_filter('query', $inj);
check('number: concurrent bump -> retried, no duplicate', $injected === 1 && $num === 'OL-' . str_pad((string) ($before + 6), 4, '0', STR_PAD_LEFT), $num . ' before=' . $before);
check('number: counter stored', (int) get_option('wpd_reg_counter') === $before + 6);
update_option('wpd_reg_counter', $before, false);
delete_option('wpd_reg_counter');
check('number: missing counter starts at 1', WPD_Registration::next_number() === 'OL-0001');
update_option('wpd_reg_counter', $before, false);
check('count: 3 applications', WPD_Registration::count_applications() === 3 && WPD_Registration::count_by_status('new') === 3);
check('user application: none for 0', WPD_Registration::user_application(0) === null);

/* ---------- Статусы и письма ---------- */
$mails = [];
check('status: change to approved', WPD_Registration::set_status($a1, 'approved') === true && WPD_Registration::app_status(get_post($a1)) === 'approved');
check('status: mail to participant', count($mails) === 1 && $mails[0]['to'] === 'anna@n-test.ru' && $mails[0]['subject'] === 'Заявка OL-0001: Подтверждена', json_encode($mails, JSON_UNESCAPED_UNICODE));
check('status: mail body vars', has($mails[0]['message'], 'Здравствуйте, Петрова Анна!') && has($mails[0]['message'], 'изменён: Подтверждена') && !has($mails[0]['message'], '{'));
check('status: same status -> false, no mail', WPD_Registration::set_status($a1, 'approved') === false && count($mails) === 1);
check('status: unknown status -> false', WPD_Registration::set_status($a1, 'evil') === false);
check('status: notify=false no mail', WPD_Registration::set_status($a2, 'rejected', false) === true && count($mails) === 1);
set_reg(['mail_status' => 0]);
WPD_Registration::set_status($a2, 'new');
check('status: mail_status off', count($mails) === 1);
set_reg([]);
check('status: not an application -> false', WPD_Registration::set_status($pg, 'approved') === false);
$fired = [];
add_action('wpd_reg_status_changed', function ($id, $st, $old) use (&$fired) { $fired = [$id, $st, $old]; }, 10, 3);
WPD_Registration::set_status($a2, 'approved');
check('status: action fired', $fired === [$a2, 'approved', 'new']);
check('count: taken excludes rejected', WPD_Registration::set_status($a3, 'rejected', false) && WPD_Registration::count_applications(true) === 2 && WPD_Registration::count_by_status('rejected') === 1);

$mails = [];
check('mail: user mail sent', WPD_Registration::send_mail(get_post($a1), 'user') && $mails[0]['subject'] === 'Заявка OL-0001 принята');
check('mail: fields list in body', has($mails[0]['message'], 'ФИО участника: Петрова Анна') && has($mails[0]['message'], 'Класс: 10') && has($mails[0]['message'], 'Регион, город: Ачинск'));
check('mail: cabinet falls back to home', has($mails[0]['message'], 'Личный кабинет: ' . home_url('/')));
WPD_Registration::send_mail(get_post($a1), 'admin');
check('mail: admin to site admin email', $mails[1]['to'] === get_option('admin_email') && $mails[1]['subject'] === 'Новая заявка OL-0001: Петрова Анна');
check('mail: admin reply-to and edit link', in_array('Reply-To: anna@n-test.ru', (array) $mails[1]['headers'], true) && has($mails[1]['message'], 'post.php?post=' . $a1 . '&action=edit'));
set_reg(['admin_email' => 'org@n-test.ru', 'cabinet_page' => $pg, 'mail_user_subject' => "Тема\r\nBcc: evil@x.ru {number}"]);
WPD_Registration::send_mail(get_post($a1), 'admin');
WPD_Registration::send_mail(get_post($a1), 'user');
check('mail: custom admin email', $mails[2]['to'] === 'org@n-test.ru');
check('mail: subject without newlines (no header injection)', $mails[3]['subject'] === 'Тема Bcc: evil@x.ru OL-0001', $mails[3]['subject']);
check('mail: cabinet page link', has($mails[3]['message'], get_permalink($pg)));
set_reg([]);
$noemail = WPD_Registration::create_application(['name' => 'Без почты', 'email' => ''], 0);
check('mail: no address -> false', WPD_Registration::send_mail(get_post($noemail), 'user') === false);
wp_delete_post($noemail, true);

/* ---------- Изменение заявки ---------- */
WPD_Registration::update_application($a1, ['name' => 'Петрова Анна Сергеевна', 'email' => 'hack@n-test.ru', 'phone' => '+79001112233', 'grade' => '11', 'school' => 'Гимназия 2', 'city' => 'Ачинск']);
$app1 = get_post($a1);
check('update: title and fields', $app1->post_title === 'Петрова Анна Сергеевна' && WPD_Registration::app_values($app1)['grade'] === '11');
check('update: email unchanged by default', get_post_meta($a1, '_wpd_email', true) === 'anna@n-test.ru');
WPD_Registration::update_application($a1, WPD_Registration::app_values($app1) + ['email' => 'x'] , false);
WPD_Registration::update_application($a1, array_merge(WPD_Registration::app_values($app1), ['email' => 'Anna2@n-test.ru']), true);
check('update: email changed by admin', get_post_meta($a1, '_wpd_email', true) === 'anna2@n-test.ru');
check('update: number kept in excerpt', has(get_post($a1)->post_excerpt, 'OL-0001'));

/* ---------- CSV ---------- */
$rows = WPD_Participants::csv_rows();
check('csv: header', $rows[0] === ['№', 'Подана', 'Статус', 'ФИО участника', 'E-mail', 'Телефон', 'Класс', 'Школа', 'Регион, город', 'Логин'], json_encode($rows[0], JSON_UNESCAPED_UNICODE));
check('csv: 3 rows, oldest first', count($rows) === 4 && $rows[1][0] === 'OL-0001' && $rows[3][0] === '2026/0003');
check('csv: status label', $rows[1][2] === 'Подтверждена');
check('csv: filtered by status', count(WPD_Participants::csv_rows('rejected')) === 2);
check('csv: formula guard', WPD_Participants::csv_cell('=HYPERLINK("x")') === "'=HYPERLINK(\"x\")" && WPD_Participants::csv_cell('+7900') === "'+7900" && WPD_Participants::csv_cell('Анна') === 'Анна');
check('csv: phone with + guarded', $rows[1][5] === "'+79001112233");

/* ---------- Персональные данные ---------- */
$ex = WPD_Registration::privacy_export('oleg@n-test.ru');
check('privacy: export found', $ex['done'] === true && count($ex['data']) === 1 && $ex['data'][0]['group_id'] === 'wpd-applications');
$exv = array_column($ex['data'][0]['data'], 'value', 'name');
check('privacy: export values', $exv['Номер заявки'] === 'OL-0002' && $exv['Класс'] === '7' && $exv['ФИО участника'] === 'Сидоров Олег');
check('privacy: export unknown email empty', WPD_Registration::privacy_export('none@n-test.ru')['data'] === []);
$er = WPD_Registration::privacy_erase('oleg@n-test.ru');
check('privacy: erase', $er['items_removed'] === true && get_post($a2) === null && WPD_Registration::find_by_email('oleg@n-test.ru') === null);
check('privacy: hooks registered', isset(apply_filters('wp_privacy_personal_data_exporters', [])['wpd-registration']) && isset(apply_filters('wp_privacy_personal_data_erasers', [])['wpd-registration']));

/* ---------- Вывод формы ---------- */
set_reg(['cabinet_page' => $pg, 'form_page' => $pgf, 'privacy_url' => 'https://example.com/privacy']);
$h = WPD_Registration::render_form();
check('form: wrapped in wpd-page', has($h, '<div class="wpd-page wpd-page--reg"') && has($h, '<section class="wpd-reg" id="wpd-reg">'));
check('form: title and intro', has($h, '<h2 class="wpd-h2">Регистрация участников</h2>') && has($h, 'class="wpd-reg__intro"'));
check('form: post to same page', has($h, '<form class="wpd-reg__form" method="post"') && has($h, '<input type="hidden" name="wpd_reg_action" value="register">'));
check('form: time token', (bool) preg_match('/name="wpd_ts" value="\d+\.[a-f0-9]{16}"/', $h));
check('form: honeypot hidden from AT', has($h, '<div class="wpd-reg__hp" aria-hidden="true">') && has($h, 'name="wpd_hp" value="" tabindex="-1" autocomplete="off"'));
check('form: 6 fields', substr_count($h, 'name="wpd_reg[') === 6);
check('form: name autocomplete and required', (bool) preg_match('/<input type="text" id="wpd-reg-name" name="wpd_reg\[name\]" value="" maxlength="200" autocomplete="name" placeholder="[^"]+" required>/u', $h));
check('form: email type', has($h, 'type="email" id="wpd-reg-email"'));
check('form: tel with autocomplete', has($h, 'type="tel" id="wpd-reg-phone" name="wpd_reg[phone]" value="" autocomplete="tel"'));
check('form: select with empty option', has($h, '<select id="wpd-reg-grade" name="wpd_reg[grade]" required><option value="">— выберите —</option><option value="5">5</option>'));
check('form: required stars', substr_count($h, '<span class="wpd-reg__req" aria-hidden="true">*</span>') === 8);
check('form: consent with privacy link', has($h, 'name="wpd_consent" value="1" required') && has($h, '152-ФЗ') && has($h, 'href="https://example.com/privacy" target="_blank" rel="noopener"'));
check('form: submit button', has($h, '<button type="submit" class="wpd-btn">Отправить заявку</button>'));
check('form: login link for guests', has($h, 'class="wpd-reg__login"') && has($h, 'wp-login.php?redirect_to=' . rawurlencode(get_permalink($pg))));
check('form: no seats line without limit', !has($h, 'wpd-reg__left'));
$h = WPD_Registration::render_form(['title' => 'Своя', 'intro' => '', 'button' => 'Готово']);
check('form: args override texts', has($h, '>Своя</h2>') && !has($h, 'wpd-reg__intro') && has($h, '>Готово</button>'));
$h = WPD_Registration::render_form(['title' => '']);
check('form: empty title hides heading', !has($h, '<h2'));
set_reg(['limit' => 5, 'cabinet_page' => $pg]);
check('form: seats left', has(WPD_Registration::render_form(), 'Осталось мест: <strong>4</strong>'));
set_reg(['limit' => 5, 'show_left' => 0]);
check('form: seats hidden', !has(WPD_Registration::render_form(), 'Осталось мест'));
set_reg(['limit' => 1]);
$h = WPD_Registration::render_form();
check('form: full message', has($h, 'wpd-reg__alert--closed') && has($h, 'Все места заняты') && !has($h, '<form'));
set_reg(['enabled' => 0, 'closed' => 'Закрыто <b>x</b>']);
check('form: closed message escaped', has(WPD_Registration::render_form(), 'Закрыто &lt;b&gt;x&lt;/b&gt;'));
set_reg(['period' => 'dates', 'start' => '2030-03-01 09:00']);
$h = WPD_Registration::render_form();
check('form: not started with date', has($h, 'Приём заявок начнётся 1 марта 2030, 09:00 МСК.'), strip_tags($h));
set_reg(['fields' => [['id' => 'subj', 'label' => 'Предмет', 'type' => 'radio', 'required' => 1, 'options' => "Математика\nФизика", 'placeholder' => '', 'in_list' => 0],
                      ['id' => 'agree', 'label' => 'Буду очно', 'type' => 'checkbox', 'required' => 0, 'options' => '', 'placeholder' => '', 'in_list' => 0],
                      ['id' => 'about', 'label' => 'О себе', 'type' => 'textarea', 'required' => 0, 'options' => '', 'placeholder' => 'Коротко', 'in_list' => 0],
                      ['id' => 'bd', 'label' => 'Дата рождения', 'type' => 'date', 'required' => 0, 'options' => '', 'placeholder' => '', 'in_list' => 0]]]);
$h = WPD_Registration::render_form();
check('form: radio fieldset with legend', has($h, '<fieldset><legend>Предмет<span class="wpd-reg__req" aria-hidden="true">*</span></legend>') && has($h, 'type="radio" name="wpd_reg[subj]" value="Математика" required>'));
check('form: radio required only once', substr_count($h, 'type="radio" name="wpd_reg[subj]" value="Физика">') === 1);
check('form: checkbox field', has($h, '<input type="checkbox" id="wpd-reg-agree" name="wpd_reg[agree]" value="1">'));
check('form: textarea field', has($h, '<textarea id="wpd-reg-about" name="wpd_reg[about]" rows="4" maxlength="2000" placeholder="Коротко"></textarea>'));
check('form: date field', has($h, 'type="date" id="wpd-reg-bd"'));
check('form: wide fields', substr_count($h, 'wpd-reg__field wpd-reg__field--wide') === 4);
$fh = WPD_Registration::field_html(['id' => 'x', 'label' => 'X <i>', 'type' => 'text', 'required' => 1, 'options' => '', 'placeholder' => ''], '"><script>', 'Ошибка <b>');
check('field: value and label escaped', has($fh, 'value="&quot;&gt;&lt;script&gt;"') && has($fh, 'X &lt;i&gt;'));
check('field: error linked by aria', has($fh, 'aria-invalid="true" aria-describedby="wpd-reg-x-err"') && has($fh, '<p class="wpd-reg__error" id="wpd-reg-x-err">Ошибка &lt;b&gt;</p>') && has($fh, 'wpd-reg__field--error'));
$fh = WPD_Registration::field_html(['id' => 'email', 'label' => 'E-mail', 'type' => 'email', 'required' => 1, 'options' => '', 'placeholder' => ''], 'a@b.ru', '', true);
check('field: locked email readonly', has($fh, ' readonly') && has($fh, 'E-mail вашего аккаунта.'));
check('field: selected option kept', has(WPD_Registration::field_html(['id' => 'g', 'label' => 'G', 'type' => 'select', 'required' => 0, 'options' => "1\n2", 'placeholder' => ''], '2', ''), '<option value="2" selected=\'selected\'>2</option>'));

// Успех по одноразовой ссылке
set_reg(['cabinet_page' => $pg]);
set_transient('wpd_reg_done_tok123', $a1, 60);
$_GET = ['wpd_reg' => 'done', 't' => 'tok123'];
$h = WPD_Registration::render_form();
check('form: success message after submit', has($h, 'wpd-reg__alert--success') && has($h, 'Заявка OL-0001 принята') && has($h, 'anna2@n-test.ru') && !has($h, '<form'));
check('form: success links cabinet', has($h, 'href="' . get_permalink($pg) . '">Личный кабинет</a>'));
$_GET = ['wpd_reg' => 'done', 't' => 'wrong'];
check('form: wrong token -> form', has(WPD_Registration::render_form(), '<form'));
$_GET = [];

/* ---------- Роль, аккаунт, кабинет ---------- */
$uid = wp_insert_user(['user_login' => 'nuser', 'user_email' => 'nuser@n-test.ru', 'user_pass' => wp_generate_password(), 'display_name' => 'Орлов Пётр', 'role' => 'wpd_participant']);
$a4 = WPD_Registration::create_application(['name' => 'Орлов Пётр', 'email' => 'nuser@n-test.ru', 'phone' => '+79990001122', 'grade' => '8', 'school' => 'СОШ 5', 'city' => 'Канск'], $uid);
check('role: participant has read only', get_role('wpd_participant') && get_role('wpd_participant')->capabilities === ['read' => true]);
check('role: is_participant', WPD_Registration::is_participant(get_userdata($uid)) && !WPD_Registration::is_participant(get_userdata(1)));
check('user application found', WPD_Registration::user_application($uid) && WPD_Registration::user_application($uid)->ID === $a4);
wp_set_current_user($uid);
check('participant: no admin bar', WPD_Registration::show_admin_bar(true) === false);
check('participant: login redirect to cabinet', WPD_Registration::login_redirect(admin_url(), '', get_userdata($uid)) === get_permalink($pg));
check('participant: explicit redirect kept', WPD_Registration::login_redirect('https://x.test/page', 'https://x.test/page', get_userdata($uid)) === 'https://x.test/page');
check('participant: cannot edit applications', !current_user_can('edit_post', $a4) && !current_user_can('edit_posts'));
$h = WPD_Registration::render_form();
check('form: already registered', has($h, 'Вы уже подали заявку OL-') && has($h, 'Статус: Новая') && has($h, 'Перейти в личный кабинет') && !has($h, '<form'));
$c = WPD_Registration::render_cabinet();
check('cabinet: card with number and status', has($c, '<section class="wpd-cabinet" id="wpd-cabinet">') && has($c, 'class="wpd-cabinet__number">' . get_post_meta($a4, '_wpd_number', true)) && has($c, '<span class="wpd-status wpd-status--new">Новая</span>'));
check('cabinet: submitted date in russian', (bool) preg_match('/Подана \d{1,2} (января|февраля|марта|апреля|мая|июня|июля|августа|сентября|октября|ноября|декабря) \d{4}, \d\d:\d\d/u', $c));
check('cabinet: data list', has($c, '<dt>Школа</dt><dd>СОШ 5</dd>') && has($c, '<dt>E-mail</dt><dd>nuser@n-test.ru</dd>'));
check('cabinet: edit link', has($c, 'wpd_edit=1') && has($c, 'Изменить заявку'));
check('cabinet: logout link', has($c, 'Орлов Пётр · <a href=') && has($c, 'action=logout'));
check('can_edit: new + open', WPD_Registration::can_edit(get_post($a4)));
$_GET = ['wpd_edit' => '1'];
$c = WPD_Registration::render_cabinet();
check('cabinet: edit form with nonce', has($c, '<input type="hidden" name="wpd_reg_action" value="edit">') && has($c, 'name="_wpd_nonce"') && has($c, 'value="СОШ 5"'));
check('cabinet: email locked in edit', (bool) preg_match('/id="wpd-reg-email"[^>]*readonly/', $c));
check('cabinet: cancel link', has($c, 'wpd-btn--ghost') && has($c, '>Отмена</a>'));
$_GET = ['wpd_reg' => 'saved'];
check('cabinet: saved notice', has(WPD_Registration::render_cabinet(), 'Изменения сохранены.'));
$_GET = [];
set_reg(['cabinet_page' => $pg, 'limit' => 1]);
check('can_edit: allowed when only seats are full', WPD_Registration::can_edit(get_post($a4)));
set_reg(['cabinet_page' => $pg, 'allow_edit' => 0]);
check('can_edit: off by setting', !WPD_Registration::can_edit(get_post($a4)) && !has(WPD_Registration::render_cabinet(), 'Изменить заявку'));
set_reg(['cabinet_page' => $pg, 'enabled' => 0]);
check('can_edit: closed registration', !WPD_Registration::can_edit(get_post($a4)));
set_reg(['cabinet_page' => $pg]);
WPD_Registration::set_status($a4, 'approved', false);
$c = WPD_Registration::render_cabinet();
check('cabinet: approved message, no edit', has($c, 'wpd-status--approved') && has($c, 'Заявка подтверждена') && !has($c, 'Изменить заявку'));
WPD_Registration::set_status($a4, 'rejected', false);
check('cabinet: rejected message', has(WPD_Registration::render_cabinet(), 'Заявка отклонена'));
wp_set_current_user(1);
$c = WPD_Registration::render_cabinet();
check('cabinet: user without application', has($c, 'Заявки пока нет.'));
wp_set_current_user(0);
$c = WPD_Registration::render_cabinet();
check('cabinet: login form for guests', has($c, 'id="wpd-login"') && has($c, 'E-mail или логин') && has($c, 'value="' . get_permalink($pg) . '"'));
check('cabinet: lost password link', has($c, 'action=lostpassword') && has($c, 'Забыли пароль'));
set_reg(['cabinet_page' => $pg, 'form_page' => $pgf]);
check('cabinet: link to form', has(WPD_Registration::render_cabinet(), 'href="' . get_permalink($pgf) . '">Подать заявку</a>'));
set_transient('wpd_reg_done_tok456', $a1, 60);
$_GET = ['wpd_reg' => 'done', 't' => 'tok456'];
check('cabinet: success notice for guest with password hint', has(WPD_Registration::render_cabinet(), 'Ссылка для установки пароля отправлена на anna2@n-test.ru.'));
$_GET = [];
check('cabinet: custom title', has(WPD_Registration::render_cabinet(['title' => 'Мой кабинет']), '<h2 class="wpd-h2">Мой кабинет</h2>'));

/* ---------- Шорткоды, блоки, стили ---------- */
check('shortcodes registered', shortcode_exists('wpd_registration') && shortcode_exists('wpd_cabinet'));
check('shortcode attrs', has(do_shortcode('[wpd_registration title="Заявка на тур"]'), '>Заявка на тур</h2>'));
$reg = WP_Block_Type_Registry::get_instance();
$b = $reg->get_registered('wpd/registration');
check('block: registration registered', $b && array_keys($b->attributes) === ['title', 'intro', 'button', 'lock', 'metadata', 'align'] || ($b && !array_diff(['title', 'intro', 'button'], array_keys($b->attributes))), $b ? implode(',', array_keys($b->attributes)) : '');
check('block: no server defaults', $b && !isset($b->attributes['title']['default']));
check('block: cabinet registered', (bool) $reg->get_registered('wpd/cabinet'));
check('block: editor script', $b && in_array('wpd-reg-blocks', (array) ($b->editor_script_handles ?? [$b->editor_script]), true));
check('block: render empty attr -> settings', has(WPD_Registration::render_registration_block(['title' => '']), '>Регистрация участников</h2>'));
check('block: align wide', has(WPD_Registration::render_registration_block(['align' => 'wide']), '<div class="alignwide wpd-page wpd-page--reg"'));
check('block: cabinet title attr', has(WPD_Registration::render_cabinet_block(['title' => 'Кабинет участника']), '>Кабинет участника</h2>'));
$inline = implode('', (array) wp_scripts()->get_data('wpd-reg-blocks', 'before'));
check('editor data: blocks and defaults', has($inline, 'window.wpdReg') && has($inline, '"wpd\/registration"') && has($inline, '"defaults"') && has($inline, 'wpd-registration'));
global $wp_query;
$wp_query = new WP_Query(['page_id' => $pgf]);
$wp_query->is_singular = true;
$wp_query->queried_object = get_post($pgf);
check('assets: needed on form page', WPD_Registration::needs_assets(false) === true);
$wp_query->queried_object = get_post($pg);
check('assets: needed on cabinet page', WPD_Registration::needs_assets(false) === true);
$plain = wp_insert_post(['post_title' => 'Обычная N', 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '<!-- wp:wpd/cabinet /-->']);
$wp_query->queried_object = get_post($plain);
check('assets: block detected', WPD_Registration::needs_assets(false) === true);
wp_update_post(['ID' => $plain, 'post_content' => 'текст']);
$wp_query->queried_object = get_post($plain);
check('assets: not needed elsewhere', WPD_Registration::needs_assets(false) === false);
$wp_query = new WP_Query();

/* ---------- Админка ---------- */
check('cpt: not public, ui under Олимпиада', !get_post_type_object('wpd_application')->public && get_post_type_object('wpd_application')->show_in_menu === 'wpd-settings');
check('cpt: create disabled', get_post_type_object('wpd_application')->cap->create_posts === 'do_not_allow');
$editor = wp_insert_user(['user_login' => 'neditor', 'user_email' => 'neditor@n-test.ru', 'user_pass' => wp_generate_password(), 'role' => 'editor']);
check('cpt: editor cannot edit applications', !user_can($editor, 'edit_post', $a1) && !user_can($editor, 'delete_post', $a1));
check('cpt: admin can edit and delete', user_can(1, 'edit_post', $a1) && user_can(1, 'delete_post', $a1));
check('cpt: not queryable on site', get_post_type_object('wpd_application')->publicly_queryable === false && get_post_type_object('wpd_application')->exclude_from_search === true);
$cols = WPD_Participants::columns(['cb' => '<input type="checkbox">', 'title' => 'Заголовок', 'date' => 'Дата']);
check('admin: columns', array_keys($cols) === ['cb', 'wpd_number', 'title', 'wpd_email', 'wpd_f_grade', 'wpd_f_school', 'wpd_status', 'wpd_account', 'date'], implode(',', array_keys($cols)));
ob_start(); WPD_Participants::column('wpd_status', $a1); $out = ob_get_clean();
check('admin: status column', $out === '<span class="wpd-status wpd-status--approved">Подтверждена</span>', $out);
ob_start(); WPD_Participants::column('wpd_f_grade', $a1); $out = ob_get_clean();
check('admin: field column', $out === '11');
wp_set_current_user(1);
ob_start(); WPD_Participants::column('wpd_account', $a4); $out = ob_get_clean();
check('admin: account column links user', has($out, 'user-edit.php?user_id=' . $uid) && has($out, '>nuser</a>'));
ob_start(); WPD_Participants::column('wpd_email', $a1); $out = ob_get_clean();
check('admin: email mailto', $out === '<a href="mailto:anna2@n-test.ru">anna2@n-test.ru</a>');
$views = WPD_Participants::views(['all' => 'x', 'publish' => 'y', 'trash' => '<a>Корзина</a>']);
check('admin: views by status', array_keys($views) === ['all', 'wpd_new', 'wpd_approved', 'wpd_rejected', 'trash'] && has($views['wpd_rejected'], '(2)') && has($views['wpd_approved'], '(1)') && has($views['wpd_new'], '(0)'), implode(',', array_keys($views)) . ' ' . strip_tags(implode(' | ', $views)));
$ba = WPD_Participants::bulk_actions(['edit' => 'Изменить', 'trash' => 'В корзину']);
check('admin: bulk actions', array_keys($ba) === ['wpd_approved', 'wpd_rejected', 'wpd_new', 'trash']);
$mails = [];
wp_set_current_user($editor);
$r = WPD_Participants::handle_bulk('edit.php?post_type=wpd_application', 'wpd_new', [$a1, $a4, $a3]);
check('admin: bulk ignored for non-admin', $r === 'edit.php?post_type=wpd_application' && WPD_Registration::count_by_status('new') === 0);
wp_set_current_user(1);
$r = WPD_Participants::handle_bulk('edit.php?post_type=wpd_application&wpd_changed=5', 'wpd_new', [$a1, $a4, $a3]);
check('admin: bulk result count', has($r, 'wpd_changed=3') && !has($r, 'wpd_changed=5') && WPD_Registration::count_by_status('new') === 3);
check('admin: bulk sends status mails', count($mails) === 3);
check('admin: other bulk action untouched', WPD_Participants::handle_bulk('x', 'trash', [$a1]) === 'x');
$ra = WPD_Participants::row_actions(['edit' => 'e', 'inline hide-if-no-js' => 'q', 'trash' => 't'], get_post($a1));
check('admin: row actions', array_keys($ra) === ['wpd_approved', 'wpd_rejected', 'edit', 'trash'] && has($ra['wpd_approved'], 'action=wpd_reg_status') && has($ra['wpd_approved'], '_wpnonce='));
check('admin: row actions other types untouched', WPD_Participants::row_actions(['edit' => 'e'], get_post($pg)) === ['edit' => 'e']);
$GLOBALS['submenu'] = ['wpd-settings' => [['Оформление', 'manage_options', 'wpd-settings'], ['Участники', 'manage_options', 'edit.php?post_type=wpd_application']]];
WPD_Participants::menu_badge();
// Карточка заявки: сохранение из админки, поля с ошибкой остаются прежними
$_POST = ['wpd_app_nonce' => wp_create_nonce('wpd_app_save'), 'wpd_reg' => ['email' => 'плохой@адрес', 'phone' => '+7 900 111-22-33', 'grade' => '99', 'school' => 'Новая школа', 'city' => ''],
          'wpd_app_status' => 'approved'];
$mails = [];
WPD_Participants::save($a1, get_post($a1));
$sv = WPD_Registration::app_values(get_post($a1));
check('card save: valid fields updated', $sv['school'] === 'Новая школа' && $sv['phone'] === '+7 900 111-22-33' && $sv['city'] === '');
check('card save: invalid email and option keep old values', $sv['email'] === 'anna2@n-test.ru' && $sv['grade'] === '11', json_encode($sv, JSON_UNESCAPED_UNICODE));
check('card save: status without notify -> no mail', WPD_Registration::app_status(get_post($a1)) === 'approved' && $mails === []);
$_POST['wpd_reg']['email'] = 'anna3@n-test.ru';
$_POST['wpd_app_status'] = 'new';
$_POST['wpd_app_notify'] = '1';
WPD_Participants::save($a1, get_post($a1));
check('card save: email changed by admin, notify mail', get_post_meta($a1, '_wpd_email', true) === 'anna3@n-test.ru' && count($mails) === 1 && $mails[0]['to'] === 'anna3@n-test.ru');
$_POST['wpd_app_nonce'] = 'bad';
$_POST['wpd_reg']['school'] = 'Взлом';
WPD_Participants::save($a1, get_post($a1));
check('card save: bad nonce ignored', WPD_Registration::app_values(get_post($a1))['school'] === 'Новая школа');
$_POST = [];
check('admin: menu badge with new count', $GLOBALS['submenu']['wpd-settings'][1][0] === 'Участники <span class="awaiting-mod count-3"><span class="pending-count">3</span></span>', $GLOBALS['submenu']['wpd-settings'][1][0]);
$GLOBALS['submenu'] = [];
WPD_Registration::add_menu();
check('admin: registration submenu', ($GLOBALS['submenu']['wpd-settings'][0][2] ?? '') === 'wpd-registration');
ob_start();
$_GET = ['page' => 'wpd-registration', 'tab' => 'fields'];
WPD_Registration::render_page();
$sp = ob_get_clean();
$_GET = [];
check('settings page: tabs and howto', preg_match('/class="nav-tab nav-tab-active"\s+aria-current="page">Поля формы<\/a>/u', $sp) && has($sp, '[wpd_registration]') && has($sp, 'edit.php?post_type=wpd_application'));
check('settings page: state line', has($sp, 'Сейчас приём заявок открыт.') && has($sp, 'Заявок: 3.'));
check('settings page: builder rows', substr_count($sp, 'data-wpd-rep-row') === 5 && has($sp, 'name="wpd_registration[fields][0][id]" value="phone"') && has($sp, 'data-wpd-rep-tpl'));
check('settings page: builder type select', has($sp, '<option value="radio">Выбор одного варианта</option>') && has($sp, 'name="wpd_registration[fields][_]"'));
check('settings page: options hidden for non-list types', (bool) preg_match('/for="wpd_reg_f_0_options" data-wpd-reg-options hidden>/', $sp) && (bool) preg_match('/for="wpd_reg_f_1_options" data-wpd-reg-options>/', $sp) && has($sp, 'data-wpd-reg-type'));
check('settings page: hidden tab field', has($sp, 'name="wpd_registration[_tab]" value="fields"'));
ob_start();
$_GET = ['page' => 'wpd-registration'];
WPD_Registration::render_page();
$sp = ob_get_clean();
$_GET = [];
check('settings page: form tab fields', has($sp, 'name="wpd_registration[period]"') && has($sp, 'type="datetime-local" id="wpd_reg_start"') && has($sp, "<select name='wpd_registration[cabinet_page]' id='wpd_reg_cabinet_page'>") && has($sp, 'Кабинет N'));
check('settings page: rate limit', has($sp, 'name="wpd_registration[rate_limit]" value="20"'));
wp_set_current_user($editor);
ob_start(); WPD_Registration::render_page(); $sp = ob_get_clean();
check('settings page: hidden for non-admin', $sp === '');
wp_set_current_user(0);
$help = (function () { ob_start(); $m = new ReflectionMethod('WPD_Settings', 'render_help'); $m->setAccessible(true); $m->invoke(null); return ob_get_clean(); })();
check('help: registration section', has($help, '<h2>Регистрация участников</h2>') && has($help, '[wpd_cabinet]'));
check('human date: russian months', WPD_Registration::human_date(1893456000, new DateTimeZone('Europe/Moscow')) === '1 января 2030, 03:00' && WPD_Registration::human_date(1909000000, new DateTimeZone('UTC')) === '29 июня 2030, 21:46', WPD_Registration::human_date(1909000000, new DateTimeZone('UTC')));
check('version 2.0.0', WPD_VERSION === '2.0.0');

/* ---------- CSS ---------- */
$css = file_get_contents(WPD_DIR . 'assets/css/wpd.css');
check('css: registration rules', has($css, '.wpd-page .wpd-reg__grid') && has($css, '.wpd-page .wpd-reg__hp') && has($css, '.wpd-page .wpd-status--approved') && has($css, '.wpd-page .wpd-cabinet__data'));
check('css: honeypot off-screen, not display:none', (bool) preg_match('/\.wpd-reg__hp \{[^}]*left: -10000px/', $css));
check('css: error border beats base field rule', (bool) preg_match('/\.wpd-page \.wpd-reg \.wpd-reg__field--error input:not\(\[type="radio"\]\)[^{]*\{\s*border-color: #dc2626;/', $css) && strrpos($css, '.wpd-reg__field--error input') > strpos($css, '.wpd-page .wpd-reg input[type="text"]'));
check('css: one column on phones', (bool) preg_match('/@media \(max-width: 820px\) \{[^@]*\.wpd-reg__grid,[^{]*\{\s*grid-template-columns: 1fr;/', $css));

wp_delete_post($pg, true);
wp_delete_post($pgf, true);
wp_delete_post($plain, true);
cleanup_n();
echo implode("\n", $results), "\n\nTOTAL: ", count($results), ", FAILED: ", $fails, "\n";
