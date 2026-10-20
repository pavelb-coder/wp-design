<?php
// Фаза I (1.6.0): таймер до начала и до окончания, кнопка-ссылка на время этапа.
// PHP-часть: поля и санитизация, предупреждение о датах, этапы на сервере, кнопка, блок, шорткод, панель. В конце удаляет опцию.
register_shutdown_function(function () { $e = error_get_last(); if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR], true)) { echo "\nSHUTDOWN: {$e['message']} in {$e['file']}:{$e['line']}\n"; } });
ini_set('display_errors', '1');
error_reporting(E_ALL);
require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
header('Content-Type: text/plain; charset=utf-8');

$results = [];
$fails = 0;
function check(string $name, bool $cond, string $detail = ''): void {
    global $results, $fails;
    $results[] = ($cond ? 'PASS ' : 'FAIL ') . $name . ($cond || $detail === '' ? '' : ' :: ' . $detail);
    if (!$cond) { $fails++; }
}
function has(string $h, string $n): bool { return strpos($h, $n) !== false; }
function set_opts(array $values): void {
    // В обход санитизации: иначе массив без _tab считается полной формой и неотмеченные чекбоксы становятся 0
    global $wp_filter;
    $saved = $wp_filter['sanitize_option_wpd_settings'] ?? null;
    unset($wp_filter['sanitize_option_wpd_settings']);
    update_option('wpd_settings', $values);
    if ($saved !== null) {
        $wp_filter['sanitize_option_wpd_settings'] = $saved;
    }
    WPD_Settings::flush_cache();
}
/** Дата в формате настроек относительно текущего момента (в часовом поясе сайта). */
function rel(string $modify): string {
    return (new DateTime('now', wp_timezone()))->modify($modify)->format('Y-m-d H:i');
}
function cd(array $args): string {
    return WPD_Renderer::countdown_html(WPD_Settings::get(), $args + ['enabled' => true]);
}

WPD_Settings::register();
wp_set_current_user(1);
delete_option('wpd_settings');
WPD_Settings::flush_cache();

check('version 1.6.0', WPD_VERSION === '1.6.0');

/* 1. Поля */
$map = WPD_Settings::field_map();
foreach (['countdown_end' => 'datetime', 'countdown_end_label' => 'text', 'countdown_link_text' => 'text', 'countdown_link_url' => 'url', 'countdown_link_new_tab' => 'checkbox'] as $key => $type) {
    check("field $key ($type) on features tab", isset($map[$key]) && $map[$key]['type'] === $type && $map[$key]['section'] === 'features');
}
$d = WPD_Settings::get();
check('defaults: no end, no link url, new tab on', $d['countdown_end'] === '' && $d['countdown_link_url'] === '' && $d['countdown_link_new_tab'] === 1);
check('defaults: end label and link text', $d['countdown_end_label'] === 'До окончания олимпиады' && $d['countdown_link_text'] === 'Перейти к заданиям');
$keys = array_keys($map);
check('fields order: end after start label, link after done',
    array_search('countdown_end', $keys, true) === array_search('countdown_label', $keys, true) + 1
    && array_search('countdown_link_text', $keys, true) === array_search('countdown_done', $keys, true) + 1);

/* 2. Санитизация */
$s = WPD_Settings::sanitize(['_tab' => 'features', 'countdown_enabled' => '1', 'countdown_date' => '2031-01-10T09:00', 'countdown_end' => '2031-01-12T18:30',
    'countdown_end_label' => '<b>До</b> конца', 'countdown_link_text' => '<i>Задания</i>', 'countdown_link_url' => 'javascript:alert(1)']);
check('end normalized', $s['countdown_end'] === '2031-01-12 18:30', $s['countdown_end']);
check('end label tags stripped', $s['countdown_end_label'] === 'До конца');
check('link text tags stripped', $s['countdown_link_text'] === 'Задания');
check('javascript: link dropped', $s['countdown_link_url'] === '');
check('unchecked new tab -> 0 on tab save', $s['countdown_link_new_tab'] === 0);
check('invalid end -> empty', WPD_Settings::sanitize(['countdown_end' => '2031-13-45T99:99'])['countdown_end'] === '');
check('valid dates: no warning', !in_array('wpd_countdown_end', array_column(get_settings_errors('wpd_settings'), 'code'), true));
$GLOBALS['wp_settings_errors'] = [];
WPD_Settings::sanitize(['_tab' => 'features', 'countdown_date' => '2031-01-10T09:00', 'countdown_end' => '2031-01-10T09:00']);
WPD_Settings::sanitize(['_tab' => 'features', 'countdown_date' => '2031-01-10T09:00', 'countdown_end' => '2031-01-09T09:00']);
$codes = array_column(get_settings_errors('wpd_settings'), 'code');
check('end not after start -> one warning (no duplicates)', count(array_keys($codes, 'wpd_countdown_end', true)) === 1, implode(',', $codes));
$GLOBALS['wp_settings_errors'] = [];
WPD_Settings::sanitize(['_tab' => 'stats', 'stats' => '1|a']);
check('other tab save: no countdown warning', !get_settings_errors('wpd_settings'));
set_opts(['countdown_link_url' => 'https://example.com/keep', 'countdown_end' => '2031-02-02 10:00']);
$s = WPD_Settings::sanitize(['_tab' => 'stats', 'stats' => '1|a']);
check('other tab save keeps end and link', $s['countdown_link_url'] === 'https://example.com/keep' && $s['countdown_end'] === '2031-02-02 10:00');
set_opts([]);

/* 3. Этапы на сервере */
$base = ['label' => 'До начала', 'end_label' => 'До окончания', 'done' => 'Завершено', 'link_text' => 'К заданиям', 'link_url' => 'https://example.com/tasks'];

$h = cd($base + ['date' => rel('+2 days'), 'end' => rel('+3 days')]);
check('before: phase', has($h, 'data-phase="before"'), $h);
check('before: start label shown', has($h, '<p class="wpd-countdown__label">До начала</p>'));
check('before: start date as value', has($h, wp_date(get_option('date_format'), strtotime('+2 days'))));
check('before: link present but hidden', has($h, 'class="wpd-btn wpd-countdown__link" href="https://example.com/tasks" target="_blank" rel="noopener noreferrer" hidden>'), $h);
check('before: data attrs for script', has($h, 'data-end="') && has($h, 'data-end-label="До окончания"') && has($h, 'data-label="До начала"') && has($h, 'data-done="Завершено"'));
check('before: class unchanged', has($h, '<div class="wpd-countdown" '));

$h = cd($base + ['date' => rel('-1 hour'), 'end' => rel('+1 day')]);
check('active: phase', has($h, 'data-phase="active"'), $h);
check('active: end label shown', has($h, '<p class="wpd-countdown__label">До окончания</p>'));
check('active: end date as value', has($h, wp_date(get_option('date_format'), strtotime('+1 day'))));
check('active: link visible', has($h, 'wpd-countdown__link') && !preg_match('/wpd-countdown__link[^>]*hidden/', $h));
check('active: link text with arrow', has($h, '>К заданиям &rarr;</a>'));

$h = cd($base + ['date' => rel('-2 days'), 'end' => rel('-1 day')]);
check('done: phase', has($h, 'data-phase="done"'));
check('done: label hidden', has($h, '<p class="wpd-countdown__label" hidden>'));
check('done: done text as value', has($h, '<p class="wpd-countdown__value">Завершено</p>'));
check('done: link hidden', (bool) preg_match('/wpd-countdown__link[^>]*hidden/', $h));

$h = cd($base + ['date' => rel('-1 hour'), 'end' => '']);
check('no end, after start: done text', has($h, 'data-phase="done"') && has($h, '>Завершено<'));
check('no end, after start: link visible (open-ended stage)', !preg_match('/wpd-countdown__link[^>]*hidden/', $h));
check('no end: no data-end attrs', !has($h, 'data-end'));

$h = cd($base + ['date' => rel('+1 day'), 'end' => rel('+1 day')]);
check('end equal to start -> ignored', !has($h, 'data-end') && has($h, 'data-phase="before"'));
$h = cd($base + ['date' => rel('+2 days'), 'end' => rel('+1 day')]);
check('end before start -> ignored', !has($h, 'data-end'));

check('no link url -> no button', !has(cd(['link_url' => ''] + $base + ['date' => rel('-1 hour'), 'end' => rel('+1 day')]), 'wpd-countdown__link'));
check('empty link text -> no button', !has(cd(['link_text' => ''] + $base + ['date' => rel('-1 hour'), 'end' => rel('+1 day')]), 'wpd-countdown__link'));
check('javascript: link -> no button', !has(cd(['link_url' => 'javascript:alert(1)'] + $base + ['date' => rel('-1 hour'), 'end' => rel('+1 day')]), 'wpd-countdown__link'));
$h = cd(['link_new_tab' => false] + $base + ['date' => rel('-1 hour'), 'end' => rel('+1 day')]);
check('new tab off -> no target', has($h, 'wpd-countdown__link') && !has($h, 'target="_blank"'));
$h = cd(['link_text' => '<script>x()</script>', 'end_label' => '<img src=x>'] + $base + ['date' => rel('-1 hour'), 'end' => rel('+1 day')]);
check('link text and end label escaped', !has($h, '<script>') && !has($h, '<img'));
check('empty start label: label hidden before start', has(cd(['label' => ''] + $base + ['date' => rel('+1 day')]), '<p class="wpd-countdown__label" hidden>'));
check('disabled or no date -> empty', WPD_Renderer::countdown_html(WPD_Settings::get(), ['enabled' => false, 'date' => rel('+1 day')]) === '' && cd(['date' => '']) === '');

/* 4. Шорткод и общие настройки */
set_opts(['countdown_enabled' => 1, 'countdown_date' => rel('-1 hour'), 'countdown_end' => rel('+2 hours'), 'countdown_end_label' => 'Идёт тур',
    'countdown_link_text' => 'На платформу', 'countdown_link_url' => 'https://example.com/platform', 'countdown_link_new_tab' => 0]);
$sc = do_shortcode('[wpd_countdown]');
check('shortcode: active phase from settings', has($sc, 'data-phase="active"') && has($sc, '>Идёт тур<'), $sc);
check('shortcode: button from settings, same tab', has($sc, 'href="https://example.com/platform">На платформу') && !has($sc, 'target="_blank"'));
check('[wpd_page] includes countdown with button', has(do_shortcode('[wpd_page]'), 'wpd-countdown__link'));

/* 5. Блок */
$bt = WP_Block_Type_Registry::get_instance()->get_registered('wpd/countdown');
foreach (['end', 'endLabel', 'linkText', 'linkUrl', 'linkNewTab'] as $attr) {
    check("block attr $attr registered without server default", isset($bt->attributes[$attr]) && !array_key_exists('default', $bt->attributes[$attr]));
}
$r = render_block(['blockName' => 'wpd/countdown', 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []]);
check('block: absent attrs -> settings (end, button)', has($r, 'data-phase="active"') && has($r, 'https://example.com/platform'), $r);
$r = render_block(['blockName' => 'wpd/countdown', 'attrs' => ['date' => rel('+1 day'), 'end' => rel('+2 days'), 'endLabel' => 'Своё окончание', 'linkUrl' => 'https://example.com/own', 'linkText' => 'Свой текст', 'linkNewTab' => true],
    'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []]);
check('block: own end label and link', has($r, 'data-end-label="Своё окончание"') && has($r, 'href="https://example.com/own" target="_blank"') && has($r, 'Свой текст'));
check('block: before start -> button hidden', (bool) preg_match('/wpd-countdown__link[^>]*hidden/', $r));
$r = render_block(['blockName' => 'wpd/countdown', 'attrs' => ['linkUrl' => ''], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []]);
check('block: empty linkUrl attr wins -> no button', !has($r, 'wpd-countdown__link') && has($r, 'wpd-countdown'));
$r = render_block(['blockName' => 'wpd/countdown', 'attrs' => ['end' => ''], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []]);
check('block: empty end attr wins -> single timer', !has($r, 'data-end'));

/* 6. Панель страницы */
$pid = wp_insert_post(['post_title' => 'Таймер I', 'post_status' => 'publish', 'post_type' => 'page', 'post_content' => '<p>Текст</p>']);
update_post_meta($pid, '_wpd_enabled', 1);
$html = WPD_Renderer::page_html($pid, '<p>Текст</p>');
check('designed page: countdown with button', has($html, 'wpd-countdown__link') && has($html, 'data-phase="active"'));
ob_start();
WPD_Page_Editor::meta_box_html(get_post($pid));
$mb = ob_get_clean();
check('panel note: start and end from settings', has($mb, 'начало и окончание из общих настроек'));
wp_delete_post($pid, true);

/* 7. Справка */
ob_start();
$_GET = ['page' => 'wpd-settings', 'tab' => 'help'];
WPD_Settings::render_page();
$help = ob_get_clean();
$_GET = [];
check('help mentions end timer and button', has($help, 'до окончания') && has($help, 'кнопка на время этапа'));

delete_option('wpd_settings');
echo implode("\n", $results), "\n\nTOTAL: ", count($results), ", FAILED: ", $fails, "\n";
