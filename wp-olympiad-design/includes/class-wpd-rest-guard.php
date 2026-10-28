<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Защита ответов REST API от постороннего вывода (2.6.0).
 *
 * Блочный редактор сохраняет записи через REST API и ждёт в ответе чистый JSON. Если до ответа что-то напечатано —
 * предупреждение PHP темы или другого плагина при включённом display_errors, BOM, пробелы после «?>», отладочный
 * echo, — редактор пишет «Ошибка обновления. Ответ не является допустимым ответом JSON» на любой странице.
 *
 * Для запросов REST плагин включает буфер вывода сразу при загрузке, а перед отправкой ответа
 * (фильтр rest_pre_serve_request) выбрасывает накопленный посторонний вывод и отключает показ ошибок до конца
 * запроса. Перехваченный текст сохраняется (опция wpd_rest_stray) и показывается администратору в уведомлении,
 * чтобы источник можно было найти и исправить.
 *
 * Отключить: define('WPD_REST_GUARD', false); в wp-config.php.
 */
final class WPD_Rest_Guard
{
    const OPTION  = 'wpd_rest_stray';
    const ACTION  = 'wpd_rest_stray_dismiss';
    const MAX_LEN = 4000;

    /** Уровень нашего буфера (0 — буфер не включён). */
    private static $level = 0;

    public static function init(): void
    {
        add_action('admin_notices', [__CLASS__, 'notice']);
        add_action('admin_post_' . self::ACTION, [__CLASS__, 'dismiss']);
        if (self::enabled() && self::is_rest_request()) {
            self::start();
        }
    }

    public static function enabled(): bool
    {
        return !defined('WPD_REST_GUARD') || WPD_REST_GUARD;
    }

    /**
     * Запрос к REST API. Константа REST_REQUEST появляется позже (при разборе запроса), поэтому при загрузке
     * плагина смотрим на адрес: /wp-json/… или ?rest_route=….
     */
    public static function is_rest_request(): bool
    {
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return true;
        }
        if (isset($_GET['rest_route'])) { // phpcs:ignore WordPress.Security.NonceVerification
            return true;
        }
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        if ($uri === '') {
            return false;
        }
        $path   = (string) parse_url($uri, PHP_URL_PATH);
        $prefix = function_exists('rest_get_url_prefix') ? rest_get_url_prefix() : 'wp-json';
        return $prefix !== '' && strpos($path . '/', '/' . trim($prefix, '/') . '/') !== false;
    }

    /** Включает буфер и фильтр, который очищает его перед ответом. */
    public static function start(): void
    {
        if (self::$level) {
            return;
        }
        ob_start();
        self::$level = ob_get_level();
        add_filter('rest_pre_serve_request', [__CLASS__, 'clean'], -1000, 4);
    }

    public static function is_active(): bool
    {
        return self::$level > 0;
    }

    /**
     * Перед отправкой ответа: выбрасывает всё, что напечатано в нашем буфере (и в незакрытых буферах внутри него),
     * а также в стандартном буфере PHP (output_buffering в php.ini) — туда попадает вывод, сделанный до загрузки
     * плагина. Возвращает $served без изменений, ответ отправляет сам WordPress.
     *
     * @param mixed $served
     * @param mixed $result
     * @param mixed $request
     * @param mixed $server
     * @return mixed
     */
    public static function clean($served, $result = null, $request = null, $server = null)
    {
        if (!self::$level) {
            return $served;
        }
        $stray = '';
        while (ob_get_level() >= self::$level && ob_get_level() > 0) {
            $stray = (string) ob_get_clean() . $stray;
        }
        self::$level = 0;
        if (ob_get_level() === 1) {
            $status = ob_get_status();
            if (($status['name'] ?? '') === 'default output handler') {
                $stray = (string) ob_get_contents() . $stray;
                ob_clean();
            }
        }
        // Предупреждения после ответа (при завершении запроса) тоже испортили бы JSON
        @ini_set('display_errors', '0'); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.PHP.IniSet
        if (trim($stray) !== '') {
            self::remember($stray, $request);
        }
        return $served;
    }

    /** Запоминает перехваченный вывод для уведомления (не чаще раза в 5 минут для того же текста). */
    public static function remember(string $stray, $request = null): void
    {
        $text = function_exists('mb_substr') ? mb_substr($stray, 0, self::MAX_LEN, 'UTF-8') : substr($stray, 0, self::MAX_LEN);
        if (function_exists('wp_check_invalid_utf8')) {
            $text = wp_check_invalid_utf8($text, true);
        }
        $route = '';
        if ($request instanceof WP_REST_Request) {
            $route = $request->get_method() . ' ' . $request->get_route();
        }
        $old = get_option(self::OPTION);
        $old = is_array($old) ? $old : [];
        $same = ($old['text'] ?? null) === $text;
        if ($same && time() - (int) ($old['time'] ?? 0) < 300) {
            return;
        }
        update_option(self::OPTION, [
            'time'  => time(),
            'route' => $route,
            'text'  => $text,
            'count' => $same ? (int) ($old['count'] ?? 0) + 1 : 1,
        ], false);
    }

    /** Текст для показа: без HTML-разметки сообщений PHP, с обычными переводами строк. */
    public static function readable(string $text): string
    {
        $text = preg_replace('~<br\s*/?>~i', "\n", $text);
        $text = wp_strip_all_tags((string) $text, false);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace("~\n{3,}~", "\n\n", str_replace("\r", '', $text));
        return trim((string) $text);
    }

    public static function notice(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $d = get_option(self::OPTION);
        if (!is_array($d) || empty($d['text'])) {
            return;
        }
        $url  = wp_nonce_url(admin_url('admin-post.php?action=' . self::ACTION), self::ACTION);
        $when = function_exists('wp_date') ? wp_date('d.m.Y H:i', (int) $d['time']) : gmdate('d.m.Y H:i', (int) $d['time']);
        echo '<div class="notice notice-warning wpd-rest-stray-notice"><p><strong>WP Olympiad Design:</strong> '
            . 'в ответ REST API попал посторонний вывод. Из-за такого вывода блочный редактор пишет '
            . '«Ответ не является допустимым ответом JSON». Плагин убрал его, и сохранение работает, но источник лучше исправить: '
            . 'обычно это предупреждение PHP темы или другого плагина при включённом показе ошибок (display_errors), '
            . 'пробелы или BOM в начале PHP-файла.</p>'
            . '<p>Последний раз: ' . esc_html($when)
            . ($d['route'] !== '' ? ', запрос <code>' . esc_html($d['route']) . '</code>' : '')
            . ((int) $d['count'] > 1 ? ', повторялось ' . (int) $d['count'] . ' раз' : '') . '. Перехвачено:</p>'
            . '<pre style="white-space:pre-wrap;max-height:220px;overflow:auto;background:#f6f7f7;padding:8px">'
            . esc_html(self::readable((string) $d['text'])) . '</pre>'
            . '<p><a class="button" href="' . esc_url($url) . '">Скрыть до следующего раза</a></p></div>';
    }

    public static function dismiss(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав.', 403);
        }
        check_admin_referer(self::ACTION);
        delete_option(self::OPTION);
        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }
}
