<?php
/**
 * Импорт документов из сетевой папки SMB (2.8.0).
 *
 * Подключение (адрес \\сервер\ресурс\путь, пользователь, пароль, домен, способ доступа) задаётся на вкладке «Документы»
 * и хранится в отдельной опции wpd_netfolder; пароль — зашифрованным.
 *
 * Файлы попадают в список документов одним из двух способов:
 *  - copy — копируются в медиатеку WordPress (повторный импорт обновляет тот же файл, ссылка не меняется);
 *  - link — в список попадает ссылка /?wpd_nf=ключ, сайт отдаёт файл из папки (с кешем на час).
 *
 * Куда: общий список документов (вкладка), свой список страницы (панель «Блоки олимпиады») и блок «Олимпиада: документы».
 * Импорт — кнопкой (с предпросмотром) и по расписанию (WP-Cron). Строки из папки помечены путём файла (src): повторный
 * импорт их обновляет, а не дублирует; правки названия, подписи, метки и дат сохраняются. Файл пропал из папки — строка
 * скрывается (nf = 1) или удаляется; вернулся — снова показывается.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPD_Netfolder
{
    const OPTION   = 'wpd_netfolder';
    const GROUP    = 'wpd_netfolder_group';
    const INDEX    = 'wpd_nf_index';
    const LOG      = 'wpd_nf_log';
    const HOOK     = 'wpd_nf_sync';
    const LOCK     = 'wpd_nf_lock';
    const QUERY    = 'wpd_nf';
    const META_SRC = '_wpd_nf_src';
    const META_SIG = '_wpd_nf_sig';
    const CACHE    = 'wpd-nf-cache';

    const MODES     = ['copy' => 'Копировать файлы в медиатеку', 'link' => 'Только ссылки: сайт отдаёт файлы из папки'];
    const SCHEDULES = ['' => 'Выключено', 'hourly' => 'Каждый час', 'twicedaily' => 'Дважды в день', 'daily' => 'Раз в день'];
    const GONE      = ['hide' => 'Скрывать в списке', 'remove' => 'Удалять из списка'];
    const DRIVERS   = ['auto' => 'Автоматически', 'ext' => 'PHP-расширение smbclient', 'cli' => 'Программа smbclient', 'mount' => 'Папка, подключённая к серверу сайта'];

    /** Типы, которые можно показывать в браузере; остальные отдаются на скачивание (защита от HTML/SVG со скриптами). */
    const INLINE = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'txt'];

    /** Срок кеша файла в режиме «только ссылки», секунд (фильтр wpd_nf_cache_ttl). */
    const CACHE_TTL = 3600;

    /* ---------------------------------------------------------------
     * Настройки
     * ------------------------------------------------------------- */

    public static function defaults(): array
    {
        return [
            'enabled'       => 0,
            'path'          => '',
            'user'          => '',
            'domain'        => '',
            'pass'          => '',
            'driver'        => 'auto',
            'mount'         => '',
            'mode'          => 'copy',
            'sub'           => '',
            'recursive'     => 0,
            'exts'          => 'pdf, doc, docx, xls, xlsx, ppt, pptx, odt, ods, odp, rtf, txt, zip',
            'max_mb'        => 20,
            'desc'          => 1,
            'new_tab'       => 0,
            'new_first'     => 0,
            'gone'          => 'hide',
            'schedule'      => '',
            'auto_settings' => 0,
        ];
    }

    public static function get(): array
    {
        $raw = get_option(self::OPTION, []);
        return array_merge(self::defaults(), is_array($raw) ? array_intersect_key($raw, self::defaults()) : []);
    }

    public static function enabled(): bool
    {
        return !empty(self::get()['enabled']);
    }

    /**
     * Санитизация формы подключения. Пустой пароль оставляет сохранённый, флажок pass_clear его удаляет.
     *
     * @param mixed $in
     */
    public static function sanitize($in): array
    {
        // options.php уже снял слеши (wp_unslash) — повторно не снимаем, иначе пропадут «\» в адресе и пароле
        $in  = is_array($in) ? $in : [];
        $old = self::get();
        $d   = self::defaults();
        $str = function (string $k) use ($in): string {
            return isset($in[$k]) && is_scalar($in[$k]) ? trim((string) $in[$k]) : '';
        };

        $out = [
            'enabled'       => empty($in['enabled']) ? 0 : 1,
            'path'          => sanitize_text_field($str('path')),
            'user'          => sanitize_text_field($str('user')),
            'domain'        => sanitize_text_field($str('domain')),
            'pass'          => $old['pass'],
            'driver'        => isset(self::DRIVERS[$str('driver')]) ? $str('driver') : 'auto',
            'mount'         => sanitize_text_field($str('mount')),
            'mode'          => isset(self::MODES[$str('mode')]) ? $str('mode') : 'copy',
            'sub'           => WPD_Smb::clean_rel(sanitize_text_field($str('sub'))),
            'recursive'     => empty($in['recursive']) ? 0 : 1,
            'exts'          => self::clean_exts($str('exts')),
            'max_mb'        => max(1, min(200, (int) $str('max_mb') ?: (int) $d['max_mb'])),
            'desc'          => empty($in['desc']) ? 0 : 1,
            'new_tab'       => empty($in['new_tab']) ? 0 : 1,
            'new_first'     => empty($in['new_first']) ? 0 : 1,
            'gone'          => isset(self::GONE[$str('gone')]) ? $str('gone') : 'hide',
            'schedule'      => isset(self::SCHEDULES[$str('schedule')]) ? $str('schedule') : '',
            'auto_settings' => empty($in['auto_settings']) ? 0 : 1,
        ];
        // Пароль не проходит через sanitize_text_field: в нём могут быть любые символы
        $pass = isset($in['pass']) && is_scalar($in['pass']) ? (string) $in['pass'] : '';
        if (!empty($in['pass_clear'])) {
            $out['pass'] = '';
        } elseif ($pass !== '' && $pass === $old['pass']) {
            $out['pass'] = $old['pass'];
        } elseif ($pass !== '' && preg_match('/^(enc|b64):/', $pass) && self::decrypt($pass) !== '') {
            // Уже зашифрован: при первом сохранении WordPress санитизирует опцию дважды (update_option → add_option)
            $out['pass'] = $pass;
        } elseif ($pass !== '') {
            $out['pass'] = self::encrypt($pass);
        }
        if ($out['exts'] === '') {
            $out['exts'] = $d['exts'];
        }
        if ($out['path'] !== '' && !WPD_Smb::parse_unc($out['path']) && function_exists('add_settings_error')) {
            add_settings_error(self::OPTION, 'wpd_nf_path', 'Адрес сетевой папки не распознан. Пример: \\\\fileserver\\olymp\\Документы.', 'error');
        }
        return $out;
    }

    /** Список расширений: «pdf, docx» — строчные буквы и цифры, без повторов. */
    public static function clean_exts(string $s): string
    {
        $list = [];
        foreach (preg_split('/[\s,;]+/', strtolower($s)) as $e) {
            $e = ltrim($e, '.*');
            if ($e !== '' && preg_match('/^[a-z0-9]{1,8}$/', $e) && !in_array($e, $list, true)) {
                $list[] = $e;
            }
        }
        return implode(', ', $list);
    }

    public static function exts(array $o): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $o['exts']))));
    }

    /* Пароль: AES-256-CBC с ключом из соли WordPress; без OpenSSL — base64 (только чтобы не хранить открытым текстом). */

    private static function crypt_key(): string
    {
        return hash('sha256', wp_salt('auth') . 'wpd-netfolder', true);
    }

    public static function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        if (function_exists('openssl_encrypt') && function_exists('random_bytes')) {
            $iv  = random_bytes(16);
            $enc = openssl_encrypt($plain, 'aes-256-cbc', self::crypt_key(), OPENSSL_RAW_DATA, $iv);
            if ($enc !== false) {
                $mac = hash_hmac('sha256', $iv . $enc, self::crypt_key(), true);
                return 'enc:' . base64_encode($iv . substr($mac, 0, 16) . $enc);
            }
        }
        return 'b64:' . base64_encode($plain);
    }

    public static function decrypt(string $stored): string
    {
        if (strpos($stored, 'b64:') === 0) {
            return (string) base64_decode(substr($stored, 4), true);
        }
        if (strpos($stored, 'enc:') !== 0 || !function_exists('openssl_decrypt')) {
            return '';
        }
        $bin = (string) base64_decode(substr($stored, 4), true);
        if (strlen($bin) < 33) {
            return '';
        }
        $iv  = substr($bin, 0, 16);
        $mac = substr($bin, 16, 16);
        $enc = substr($bin, 32);
        if (!hash_equals(substr(hash_hmac('sha256', $iv . $enc, self::crypt_key(), true), 0, 16), $mac)) {
            return ''; // соли WordPress сменились — пароль нужно ввести заново
        }
        $plain = openssl_decrypt($enc, 'aes-256-cbc', self::crypt_key(), OPENSSL_RAW_DATA, $iv);
        return $plain === false ? '' : $plain;
    }

    /** Клиент SMB с сохранёнными (или переданными) настройками. */
    public static function client(?array $o = null): WPD_Smb
    {
        $o = $o ?? self::get();
        return new WPD_Smb([
            'driver'  => $o['driver'],
            'path'    => $o['path'],
            'user'    => $o['user'],
            'pass'    => self::decrypt((string) $o['pass']),
            'domain'  => $o['domain'],
            'mount'   => $o['mount'],
            'bin'     => (string) apply_filters('wpd_nf_smbclient_bin', ''),
            'timeout' => (int) apply_filters('wpd_nf_timeout', 30),
        ]);
    }

    /* ---------------------------------------------------------------
     * Подключение к WordPress
     * ------------------------------------------------------------- */

    public static function init(): void
    {
        add_action('admin_init', [__CLASS__, 'register']);
        add_action('wpd_settings_after_form', [__CLASS__, 'render_box']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue']);
        add_action('rest_api_init', [__CLASS__, 'register_rest']);
        add_action('init', [__CLASS__, 'maybe_serve'], 1);
        add_action(self::HOOK, [__CLASS__, 'cron']);
        add_action('add_option_' . self::OPTION, [__CLASS__, 'reschedule']);
        add_action('update_option_' . self::OPTION, [__CLASS__, 'reschedule']);
    }

    public static function register(): void
    {
        register_setting(self::GROUP, self::OPTION, [
            'type'              => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitize'],
            'default'           => self::defaults(),
        ]);
    }

    /** Расписание WP-Cron по настройке (вызывается при сохранении настроек и активации плагина). */
    public static function reschedule(): void
    {
        $o    = self::get();
        $want = $o['enabled'] ? (string) $o['schedule'] : '';
        $next = wp_next_scheduled(self::HOOK);
        $have = $next ? (string) wp_get_schedule(self::HOOK) : '';
        if ($have === $want) {
            return;
        }
        wp_clear_scheduled_hook(self::HOOK);
        if ($want !== '') {
            wp_schedule_event(time() + 120, $want, self::HOOK);
        }
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::HOOK);
    }

    /* ---------------------------------------------------------------
     * Импорт
     * ------------------------------------------------------------- */

    /** Ключ файла в индексе и в ссылке /?wpd_nf=… — не угадывается без солей сайта. */
    public static function key(string $rel): string
    {
        return substr(hash_hmac('sha256', WPD_Smb::clean_rel($rel), wp_salt('nonce') . 'wpd-nf'), 0, 24);
    }

    public static function link_url(string $key): string
    {
        return add_query_arg(self::QUERY, $key, home_url('/'));
    }

    private static function index(): array
    {
        $idx = get_option(self::INDEX, []);
        return is_array($idx) ? $idx : [];
    }

    private static function save_index(array $idx): void
    {
        if (get_option(self::INDEX, null) === null) {
            add_option(self::INDEX, $idx, '', 'no');
        } else {
            update_option(self::INDEX, $idx, false);
        }
    }

    /** Название документа из имени файла: без расширения, «_» → пробел. */
    public static function title_of(string $name): string
    {
        $base = (string) preg_replace('/\.[^.\/]{1,8}$/', '', $name);
        // «<» и «>» в названии вырезались бы как теги — заменяем «ёлочками»
        $base = trim((string) preg_replace('/\s+/u', ' ', str_replace(['_', '<', '>'], [' ', '«', '»'], $base)));
        return $base !== '' ? $base : $name;
    }

    /** Размер по-русски: 980 Б, 12 КБ, 1,4 МБ. */
    public static function human_size(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' Б';
        }
        $units = ['КБ', 'МБ', 'ГБ'];
        $v     = $bytes / 1024;
        $i     = 0;
        while ($v >= 1024 && $i < 2) {
            $v /= 1024;
            $i++;
        }
        $num = $v >= 10 ? (string) round($v) : str_replace('.', ',', (string) round($v, 1));
        return $num . ' ' . $units[$i];
    }

    /** Подпись строки из папки: «Итоги 2026 · DOCX, 12 КБ». */
    public static function auto_desc(array $f): string
    {
        $desc = strtoupper((string) $f['ext']) . ', ' . self::human_size((int) $f['size']);
        return ($f['dir'] !== '' ? $f['dir'] . ' · ' : '') . $desc;
    }

    /** Подпись похожа на сделанную импортом — её можно обновлять. */
    private static function is_auto_desc(string $desc): bool
    {
        return $desc === '' || (bool) preg_match('/(^|· )[A-Z0-9]{1,8}, [\d,]+ (Б|КБ|МБ|ГБ)$/u', $desc);
    }

    /**
     * Строки списка для цели импорта.
     *
     * @return array|WP_Error
     */
    private static function target_rows(string $target, array $args)
    {
        switch ($target) {
            case 'settings':
                return WPD_Settings::docs_items(WPD_Settings::get_global()['docs']);
            case 'page':
                $id = (int) ($args['post_id'] ?? 0);
                if (!$id || !get_post($id)) {
                    return new WP_Error('wpd_nf_post', 'Страница не найдена.');
                }
                return WPD_Settings::sanitize_rows(WPD_Page_Editor::get($id)['docs'], 'docs');
            case 'items':
                return WPD_Settings::sanitize_rows(is_array($args['items'] ?? null) ? $args['items'] : [], 'docs');
        }
        return new WP_Error('wpd_nf_target', 'Неизвестная цель импорта.');
    }

    /** Подпапка цели: у общего списка — из настроек, у страницы — из её панели (или переданная), у блока — переданная. */
    private static function target_sub(string $target, array $args, array $o): string
    {
        if (array_key_exists('sub', $args) && $args['sub'] !== null) {
            return WPD_Smb::clean_rel((string) $args['sub']);
        }
        if ($target === 'settings') {
            return (string) $o['sub'];
        }
        if ($target === 'page') {
            return WPD_Smb::clean_rel((string) (WPD_Page_Editor::get((int) ($args['post_id'] ?? 0))['docs_nf'] ?? ''));
        }
        return '';
    }

    /**
     * Просмотр папки и (если не dry) импорт в цель.
     *
     * @param string $target settings | page | items
     * @param array  $args   post_id, sub, only (список rel новых файлов для добавления; null — все), items (для items), dry
     * @return array|WP_Error {files, gone, report, rows, sub, driver}
     */
    public static function run(string $target, array $args = [])
    {
        $o = self::get();
        if (empty($o['enabled'])) {
            return new WP_Error('wpd_nf_off', 'Импорт из сетевой папки выключен на вкладке «Документы».');
        }
        $rows = self::target_rows($target, $args);
        if (is_wp_error($rows)) {
            return $rows;
        }
        $sub    = self::target_sub($target, $args, $o);
        $client = self::client($o);
        $files  = $client->scan($sub, ['depth' => $o['recursive'] ? WPD_Smb::MAX_DEPTH : 0, 'exts' => self::exts($o)]);
        if ($files === null) {
            return new WP_Error('wpd_nf_scan', $client->error() !== '' ? $client->error() : 'Не удалось прочитать папку.');
        }
        $max_bytes = (int) $o['max_mb'] * 1024 * 1024;
        $max_rows  = (int) (WPD_Settings::field_map()['docs']['max'] ?? 50);
        $idx       = self::index();
        $dry       = !empty($args['dry']);
        $only      = isset($args['only']) && is_array($args['only']) ? array_map(function ($r) {
            return WPD_Smb::clean_rel((string) $r);
        }, $args['only']) : null;

        $by = [];
        foreach ($rows as $i => $row) {
            if ((string) ($row['src'] ?? '') !== '') {
                $by[(string) $row['src']] = $i;
            }
        }

        $report = ['added' => 0, 'updated' => 0, 'unchanged' => 0, 'gone' => 0, 'removed' => 0, 'skipped' => 0, 'toobig' => 0, 'errors' => []];
        $list   = [];
        $new    = [];
        $seen   = [];

        foreach ($files as $f) {
            $seen[$f['rel']] = true;
            $key   = self::key($f['rel']);
            $sig   = $f['size'] . '|' . $f['mtime'];
            $entry = $idx[$key] ?? null;
            $in    = isset($by[$f['rel']]);
            if ($f['size'] > $max_bytes) {
                $status = 'toobig';
            } elseif (!$in) {
                $status = 'new';
            } elseif (!$entry || ($entry['sig'] ?? '') !== $sig || ($entry['mode'] ?? '') !== $o['mode']) {
                $status = 'changed';
            } else {
                $status = (string) ($rows[$by[$f['rel']]]['nf'] ?? '') === '1' ? 'changed' : 'same';
            }
            $list[] = $f + ['status' => $status, 'in_list' => $in];
            if ($dry) {
                continue;
            }
            if ($status === 'toobig') {
                $report['toobig']++;
                continue;
            }
            if ($status === 'same') {
                $report['unchanged']++;
                continue;
            }
            if ($status === 'new' && $only !== null && !in_array($f['rel'], $only, true)) {
                continue;
            }
            if ($status === 'new' && count($rows) + count($new) >= $max_rows) {
                $report['skipped']++;
                continue;
            }
            $url = self::materialize($client, $f, $o, $idx, $max_bytes);
            if (is_wp_error($url)) {
                $report['errors'][$f['rel']] = $url->get_error_message();
                continue;
            }
            if ($in) {
                $i    = $by[$f['rel']];
                $row  = $rows[$i];
                $row['url']  = $url;
                $row['type'] = (string) $row['type'] !== '' ? $row['type'] : strtoupper(substr($f['ext'], 0, 6));
                if (!empty($o['desc']) && self::is_auto_desc((string) $row['desc'])) {
                    $row['desc'] = self::auto_desc($f);
                }
                if ((string) ($row['nf'] ?? '') === '1') {
                    $row['nf']     = '';
                    $row['hidden'] = 0;
                }
                $rows[$i] = $row;
                $report['updated']++;
            } else {
                $new[] = [
                    'title'    => self::title_of($f['name']),
                    'url'      => $url,
                    'desc'     => !empty($o['desc']) ? self::auto_desc($f) : '',
                    'badge'    => '',
                    'type'     => strtoupper(substr($f['ext'], 0, 6)),
                    'color'    => '',
                    'new_tab'  => !empty($o['new_tab']) ? 1 : 0,
                    'download' => 0,
                    'hidden'   => 0,
                    'from'     => '',
                    'to'       => '',
                    'src'      => $f['rel'],
                    'nf'       => '',
                ];
                $report['added']++;
            }
        }

        // Строки из этой папки, файлов которых больше нет
        $gone   = [];
        $prefix = $sub === '' ? '' : $sub . '/';
        foreach ($rows as $i => $row) {
            $src = (string) ($row['src'] ?? '');
            if ($src === '' || isset($seen[$src]) || ($prefix !== '' && strpos($src, $prefix) !== 0)) {
                continue;
            }
            if (!$o['recursive'] && strpos(substr($src, strlen($prefix)), '/') !== false) {
                continue; // файл из подпапки, которую сейчас не читали
            }
            $gone[] = ['rel' => $src, 'title' => (string) $row['title'], 'hidden' => (string) ($row['nf'] ?? '') === '1'];
            if ($dry) {
                continue;
            }
            if ($o['gone'] === 'remove') {
                unset($rows[$i]);
                $report['removed']++;
            } elseif ((string) ($row['nf'] ?? '') !== '1') {
                $rows[$i]['nf']     = '1';
                $rows[$i]['hidden'] = 1;
                $report['gone']++;
            }
        }
        $rows = array_values($rows);
        $rows = !empty($o['new_first']) ? array_merge($new, $rows) : array_merge($rows, $new);
        $rows = WPD_Settings::sanitize_rows($rows, 'docs');

        if (!$dry) {
            self::save_index($idx);
        }
        return ['files' => $list, 'gone' => $gone, 'report' => $report, 'rows' => $rows, 'sub' => $sub, 'driver' => $client->driver()];
    }

    /**
     * Ссылка на файл: копия в медиатеке (copy) или ссылка через сайт (link). Обновляет индекс.
     *
     * @return string|WP_Error
     */
    private static function materialize(WPD_Smb $client, array $f, array $o, array &$idx, int $max_bytes)
    {
        $key   = self::key($f['rel']);
        $sig   = $f['size'] . '|' . $f['mtime'];
        $entry = $idx[$key] ?? [];

        if ($o['mode'] === 'link') {
            if (($entry['sig'] ?? '') !== $sig) {
                self::drop_cache($key);
            }
            $idx[$key] = ['rel' => $f['rel'], 'name' => $f['name'], 'ext' => $f['ext'], 'sig' => $sig, 'mode' => 'link', 'att' => (int) ($entry['att'] ?? 0)];
            return self::link_url($key);
        }

        $att = self::find_attachment($f['rel'], (int) ($entry['att'] ?? 0));
        if ($att && get_post_meta($att, self::META_SIG, true) === $sig) {
            $idx[$key] = ['rel' => $f['rel'], 'name' => $f['name'], 'ext' => $f['ext'], 'sig' => $sig, 'mode' => 'copy', 'att' => $att];
            return (string) wp_get_attachment_url($att);
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $tmp = wp_tempnam($f['name']);
        if (!$tmp || !$client->fetch($f['rel'], $tmp, $max_bytes)) {
            if ($tmp && is_file($tmp)) {
                @unlink($tmp); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            }
            return new WP_Error('wpd_nf_fetch', $client->error() !== '' ? $client->error() : 'Файл не скачан.');
        }

        if ($att) {
            // Файл изменился: заменяем содержимое на месте — ссылка остаётся прежней
            $path = get_attached_file($att);
            if (!$path || !@copy($tmp, $path)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
                @unlink($tmp); // phpcs:ignore WordPress.PHP.NoSilencedErrors
                return new WP_Error('wpd_nf_write', 'Не удалось обновить файл в медиатеке.');
            }
            @unlink($tmp); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            $meta = wp_generate_attachment_metadata($att, $path);
            if (is_array($meta)) {
                $meta['filesize'] = (int) filesize($path);
                wp_update_attachment_metadata($att, $meta);
            }
            update_post_meta($att, self::META_SIG, $sig);
            clean_post_cache($att);
        } else {
            $file = ['name' => $f['name'], 'tmp_name' => $tmp, 'error' => 0, 'size' => (int) filesize($tmp)];
            add_filter('wp_check_filetype_and_ext', [__CLASS__, 'trust_ext'], 99, 4);
            $att  = media_handle_sideload($file, 0, self::title_of($f['name']));
            remove_filter('wp_check_filetype_and_ext', [__CLASS__, 'trust_ext'], 99);
            if (is_wp_error($att)) {
                if (is_file($tmp)) {
                    @unlink($tmp); // phpcs:ignore WordPress.PHP.NoSilencedErrors
                }
                return new WP_Error('wpd_nf_media', 'Медиатека не приняла файл: ' . $att->get_error_message());
            }
            update_post_meta($att, self::META_SRC, $f['rel']);
            update_post_meta($att, self::META_SIG, $sig);
        }
        $idx[$key] = ['rel' => $f['rel'], 'name' => $f['name'], 'ext' => $f['ext'], 'sig' => $sig, 'mode' => 'copy', 'att' => (int) $att];
        return (string) wp_get_attachment_url($att);
    }

    /**
     * Файлы из папки принимаются по расширению, если оно разрешено WordPress: проверка содержимого (finfo) отвергает,
     * например, документы Office, сохранённые старыми программами. Неразрешённые типы (php, html…) по-прежнему отклоняются.
     *
     * @param array       $data
     * @param string      $file
     * @param string      $filename
     * @param array|null  $mimes
     */
    public static function trust_ext($data, $file, $filename, $mimes): array
    {
        $data = is_array($data) ? $data : [];
        if (empty($data['ext']) || empty($data['type'])) {
            $ft = wp_check_filetype((string) $filename, is_array($mimes) ? $mimes : null);
            if ($ft['ext'] && $ft['type']) {
                $data['ext']  = $ft['ext'];
                $data['type'] = $ft['type'];
            }
        }
        return $data;
    }

    /** Вложение медиатеки, скопированное из этого файла папки. */
    private static function find_attachment(string $rel, int $hint): int
    {
        if ($hint && get_post_type($hint) === 'attachment' && get_post_meta($hint, self::META_SRC, true) === $rel) {
            return $hint;
        }
        $ids = get_posts([
            'post_type'        => 'attachment',
            'post_status'      => 'inherit',
            'numberposts'      => 1,
            'fields'           => 'ids',
            'meta_key'         => self::META_SRC, // phpcs:ignore WordPress.DB.SlowDBQuery
            'meta_value'       => $rel, // phpcs:ignore WordPress.DB.SlowDBQuery
            'suppress_filters' => true,
        ]);
        return $ids ? (int) $ids[0] : 0;
    }

    /* ---------------------------------------------------------------
     * Сохранение результата в цель
     * ------------------------------------------------------------- */

    /**
     * Импорт с сохранением: settings — общий список, page — список страницы (становится «своим»), items — без сохранения.
     *
     * @return array|WP_Error
     */
    public static function import(string $target, array $args = [])
    {
        $res = self::run($target, $args);
        if (is_wp_error($res)) {
            self::log((string) ($args['source'] ?? 'manual'), self::target_label($target, $args), $res->get_error_message(), true);
            return $res;
        }
        if ($target === 'settings') {
            self::save_settings_docs($res['rows']);
        } elseif ($target === 'page') {
            $id = (int) $args['post_id'];
            $in = WPD_Page_Editor::meta_to_input(WPD_Page_Editor::get($id));
            $in['docs']      = $res['rows'];
            $in['docs_mode'] = 'custom';
            $in['docs_nf']   = $res['sub'];
            if (array_key_exists('auto', $args) && $args['auto'] !== null) {
                $in['docs_nf_auto'] = $args['auto'] ? 1 : 0;
            }
            WPD_Page_Editor::store($id, WPD_Page_Editor::sanitize($in, $id, false));
        }
        self::log((string) ($args['source'] ?? 'manual'), self::target_label($target, $args), self::report_text($res['report']), !empty($res['report']['errors']));
        return $res;
    }

    /** Общий список документов: сохраняется только вкладка «Документы», остальные настройки не меняются. */
    public static function save_settings_docs(array $rows): void
    {
        $global = WPD_Settings::get_global();
        $tab    = WPD_Settings::tabs()['docs']['sections'];
        $in     = ['_tab' => 'docs'];
        foreach (WPD_Settings::field_map() as $key => $field) {
            if (in_array($field['section'], $tab, true)) {
                $in[$key] = $global[$key];
            }
        }
        $in['docs'] = $rows;
        // Через options.php опция санитизируется фильтром register_setting; в WP-Cron и REST его нет — санитизируем сами
        update_option(WPD_Settings::OPTION, has_filter('sanitize_option_' . WPD_Settings::OPTION) ? $in : WPD_Settings::sanitize($in));
    }

    public static function report_text(array $r): string
    {
        $parts = [];
        $add   = function (int $n, string $text) use (&$parts) {
            if ($n > 0) {
                $parts[] = $text . ': ' . $n;
            }
        };
        $add($r['added'], 'добавлено');
        $add($r['updated'], 'обновлено');
        $add($r['gone'], 'скрыто (нет в папке)');
        $add($r['removed'], 'удалено (нет в папке)');
        $add($r['skipped'], 'не поместилось в список');
        $add($r['toobig'], 'слишком большие');
        $add(count($r['errors']), 'ошибок');
        return $parts ? implode(', ', $parts) . '.' : 'Изменений нет.';
    }

    private static function target_label(string $target, array $args): string
    {
        if ($target === 'settings') {
            return 'Общий список';
        }
        $id = (int) ($args['post_id'] ?? 0);
        $t  = $id ? get_the_title($id) : '';
        return ($target === 'page' ? 'Страница' : 'Блок') . ($t !== '' ? ' «' . $t . '»' : ($id ? ' #' . $id : ''));
    }

    /** Журнал последних 20 импортов. */
    public static function log(string $source, string $where, string $text, bool $error = false): void
    {
        $log = get_option(self::LOG, []);
        $log = is_array($log) ? $log : [];
        array_unshift($log, ['time' => time(), 'source' => $source, 'where' => $where, 'text' => $text, 'error' => $error ? 1 : 0]);
        $log = array_slice($log, 0, 20);
        if (get_option(self::LOG, null) === null) {
            add_option(self::LOG, $log, '', 'no');
        } else {
            update_option(self::LOG, $log, false);
        }
    }

    /* ---------------------------------------------------------------
     * Расписание
     * ------------------------------------------------------------- */

    /**
     * Обновление по расписанию: общий список (если включено), страницы с «обновлять по расписанию»
     * и блоки «Олимпиада: документы» с включённым nfAuto.
     *
     * @return array сводка [где => текст]
     */
    public static function cron(): array
    {
        $done = [];
        if (!self::enabled() || get_transient(self::LOCK)) {
            return $done;
        }
        set_transient(self::LOCK, 1, 10 * MINUTE_IN_SECONDS);
        $o = self::get();

        if (!empty($o['auto_settings'])) {
            $r = self::import('settings', ['source' => 'cron']);
            $done['settings'] = is_wp_error($r) ? $r->get_error_message() : self::report_text($r['report']);
        }

        global $wpdb;
        // Страницы: в мета-поле панели хранится docs_nf_auto = 1
        $pages = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s LIMIT 100",
            WPD_Page_Editor::META,
            '%' . $wpdb->esc_like('s:12:"docs_nf_auto";i:1;') . '%'
        ));
        foreach (array_map('intval', $pages) as $id) {
            $r = self::import('page', ['post_id' => $id, 'source' => 'cron']);
            $done['page:' . $id] = is_wp_error($r) ? $r->get_error_message() : self::report_text($r['report']);
        }

        // Блоки «Олимпиада: документы» с автообновлением
        $posts = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type NOT IN ('revision', 'attachment') AND post_status IN ('publish', 'draft', 'private', 'future', 'pending')"
            . ' AND post_content LIKE %s AND post_content LIKE %s LIMIT 100',
            '%' . $wpdb->esc_like('<!-- wp:wpd/docs ') . '%',
            '%' . $wpdb->esc_like('"nfAuto":true') . '%'
        ));
        foreach (array_map('intval', $posts) as $id) {
            $done['blocks:' . $id] = self::sync_blocks($id);
        }

        delete_transient(self::LOCK);
        return $done;
    }

    /** Обновление блоков документов с автообновлением в содержимом записи. */
    public static function sync_blocks(int $post_id): string
    {
        $post = get_post($post_id);
        if (!$post || !function_exists('parse_blocks')) {
            return 'Запись не найдена.';
        }
        $texts   = [];
        $changed = false;
        $walk    = function (array $blocks) use (&$walk, &$texts, &$changed, $post_id): array {
            foreach ($blocks as $i => $b) {
                if (($b['blockName'] ?? '') === 'wpd/docs' && !empty($b['attrs']['nfAuto'])) {
                    $items = isset($b['attrs']['items']) && is_array($b['attrs']['items']) ? $b['attrs']['items'] : [];
                    $r     = self::import('items', ['post_id' => $post_id, 'sub' => (string) ($b['attrs']['nfFolder'] ?? ''), 'items' => $items, 'source' => 'cron']);
                    if (is_wp_error($r)) {
                        $texts[] = $r->get_error_message();
                    } else {
                        $texts[] = self::report_text($r['report']);
                        if ($r['rows'] !== WPD_Settings::sanitize_rows($items, 'docs')) {
                            $blocks[$i]['attrs']['items'] = $r['rows'];
                            $changed = true;
                        }
                    }
                }
                if (!empty($b['innerBlocks'])) {
                    $blocks[$i]['innerBlocks'] = $walk($b['innerBlocks']);
                }
            }
            return $blocks;
        };
        $blocks = $walk(parse_blocks($post->post_content));
        if ($changed) {
            // Без фильтров kses: у WP-Cron нет пользователя с правом unfiltered_html, а содержимое не менялось
            $kses = has_filter('content_save_pre', 'wp_filter_post_kses');
            if ($kses) {
                kses_remove_filters();
            }
            wp_update_post(['ID' => $post_id, 'post_content' => wp_slash(serialize_blocks($blocks))]);
            if ($kses) {
                kses_init_filters();
            }
        }
        return implode(' ', $texts);
    }

    /* ---------------------------------------------------------------
     * Отдача файлов в режиме «только ссылки»
     * ------------------------------------------------------------- */

    private static function cache_dir(): string
    {
        $up  = wp_upload_dir(null, false);
        $dir = trailingslashit($up['basedir']) . self::CACHE;
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
            @file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n"); // phpcs:ignore
            @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n"); // phpcs:ignore
        }
        return $dir;
    }

    private static function cache_file(string $key, string $ext): string
    {
        return self::cache_dir() . '/' . $key . ($ext !== '' ? '.' . $ext : '');
    }

    private static function drop_cache(string $key): void
    {
        foreach ((array) glob(self::cache_dir() . '/' . $key . '*') as $file) {
            if (is_string($file) && is_file($file)) {
                @unlink($file); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            }
        }
    }

    public static function maybe_serve(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification -- публичная ссылка на документ
        if (!isset($_GET[self::QUERY]) || !is_string($_GET[self::QUERY])) {
            return;
        }
        $key = (string) $_GET[self::QUERY]; // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
        $res = self::prepare_file($key);
        if (is_wp_error($res)) {
            status_header((int) ($res->get_error_data()['status'] ?? 404));
            nocache_headers();
            header('Content-Type: text/plain; charset=utf-8');
            echo esc_html($res->get_error_message());
            exit;
        }
        self::send_file($res['path'], $res['name'], $res['ext']);
        exit;
    }

    /**
     * Файл для ссылки: из кеша (не старше часа и с той же подписью) или свежий из папки.
     *
     * @return array|WP_Error {path, name, ext}
     */
    public static function prepare_file(string $key)
    {
        if (!preg_match('/^[a-f0-9]{24}$/', $key)) {
            return new WP_Error('wpd_nf_404', 'Документ не найден.', ['status' => 404]);
        }
        $o     = self::get();
        $idx   = self::index();
        $entry = $idx[$key] ?? null;
        if (empty($o['enabled']) || !$entry || ($entry['mode'] ?? '') !== 'link') {
            return new WP_Error('wpd_nf_404', 'Документ не найден.', ['status' => 404]);
        }
        $ext = strtolower((string) $entry['ext']);
        if (!in_array($ext, self::exts($o), true)) {
            return new WP_Error('wpd_nf_403', 'Этот тип файлов не отдаётся.', ['status' => 403]);
        }
        $file = self::cache_file($key, $ext);
        $ttl  = (int) apply_filters('wpd_nf_cache_ttl', self::CACHE_TTL);
        if (is_file($file) && (time() - (int) filemtime($file)) < $ttl && ($entry['csig'] ?? '') === ($entry['sig'] ?? '')) {
            return ['path' => $file, 'name' => (string) $entry['name'], 'ext' => $ext];
        }
        $client = self::client($o);
        $tmp    = $file . '.part';
        if ($client->fetch((string) $entry['rel'], $tmp, (int) $o['max_mb'] * 1024 * 1024)) {
            @rename($tmp, $file); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            $idx[$key]['csig'] = $entry['sig'] ?? '';
            self::save_index($idx);
            return ['path' => $file, 'name' => (string) $entry['name'], 'ext' => $ext];
        }
        if (is_file($file)) {
            return ['path' => $file, 'name' => (string) $entry['name'], 'ext' => $ext]; // папка недоступна — отдаём прежнюю копию
        }
        return new WP_Error('wpd_nf_502', 'Сетевая папка сейчас недоступна, попробуйте позже.', ['status' => 502]);
    }

    private static function send_file(string $path, string $name, string $ext): void
    {
        $type   = wp_check_filetype($name);
        $mime   = $type['type'] ?: 'application/octet-stream';
        $inline = in_array($ext, self::INLINE, true);
        $ascii  = preg_replace('/[^A-Za-z0-9._ -]/', '_', $name);
        status_header(200);
        header('Content-Type: ' . $mime . ($ext === 'txt' ? '; charset=utf-8' : ''));
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=300');
        header('X-Robots-Tag: noindex');
        if (ob_get_level()) {
            @ob_end_clean(); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }
        readfile($path); // phpcs:ignore WordPress.WP.AlternativeFunctions
    }

    /* ---------------------------------------------------------------
     * REST: проверка, просмотр, импорт
     * ------------------------------------------------------------- */

    public static function register_rest(): void
    {
        register_rest_route('wpd/v1', '/netfolder/test', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'rest_test'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ]);
        foreach (['scan' => ['GET', 'POST'], 'import' => 'POST'] as $route => $methods) {
            register_rest_route('wpd/v1', '/netfolder/' . $route, [
                'methods'             => $methods,
                'callback'            => [__CLASS__, 'rest_' . $route],
                'permission_callback' => [__CLASS__, 'rest_can'],
            ]);
        }
    }

    /** Общий список — администратор; страница и блок — тот, кто может править запись. */
    public static function rest_can(WP_REST_Request $request): bool
    {
        $target = (string) $request->get_param('target');
        if ($target === 'settings') {
            return current_user_can('manage_options');
        }
        $id = (int) $request->get_param('post_id');
        if ($target === 'page') {
            return $id > 0 && current_user_can('edit_post', $id);
        }
        if ($target === 'items') {
            return $id > 0 ? current_user_can('edit_post', $id) : current_user_can('edit_posts');
        }
        return false;
    }

    /** Проверка подключения; поля формы, ещё не сохранённые, тоже учитываются (пустой пароль — сохранённый). */
    public static function rest_test(WP_REST_Request $request): array
    {
        $o    = self::get();
        $form = $request->get_param('form');
        if (is_array($form)) {
            foreach (['path', 'user', 'domain', 'driver', 'mount', 'sub'] as $k) {
                if (isset($form[$k]) && is_scalar($form[$k])) {
                    $o[$k] = sanitize_text_field((string) $form[$k]);
                }
            }
            if (isset($form['pass']) && is_scalar($form['pass']) && (string) $form['pass'] !== '') {
                $o['pass'] = self::encrypt((string) $form['pass']);
            }
        }
        $client  = self::client($o);
        $r       = $client->test((string) $o['sub']);
        $drivers = $client->drivers();
        return $r + ['drivers' => $drivers];
    }

    private static function rest_args(WP_REST_Request $request, bool $dry): array
    {
        $args = ['post_id' => (int) $request->get_param('post_id'), 'dry' => $dry];
        $sub  = $request->get_param('sub');
        if ($sub !== null && is_scalar($sub)) {
            $args['sub'] = (string) $sub;
        }
        $only = $request->get_param('only');
        if (is_array($only)) {
            $args['only'] = array_values(array_filter($only, 'is_scalar'));
        }
        $items = $request->get_param('items');
        if (is_string($items)) {
            $items = json_decode($items, true);
        }
        if (is_array($items)) {
            $args['items'] = $items;
        }
        $auto = $request->get_param('auto');
        if ($auto !== null) {
            $args['auto'] = !empty($auto) && $auto !== 'false';
        }
        return $args;
    }

    /** @return array|WP_Error */
    public static function rest_scan(WP_REST_Request $request)
    {
        $res = self::run((string) $request->get_param('target'), self::rest_args($request, true));
        return is_wp_error($res) ? self::rest_error($res) : self::rest_out($res, (string) $request->get_param('target'), false);
    }

    /** @return array|WP_Error */
    public static function rest_import(WP_REST_Request $request)
    {
        $target = (string) $request->get_param('target');
        $res    = self::import($target, self::rest_args($request, false));
        return is_wp_error($res) ? self::rest_error($res) : self::rest_out($res, $target, true);
    }

    private static function rest_error(WP_Error $e): WP_Error
    {
        return new WP_Error($e->get_error_code(), $e->get_error_message(), ['status' => 400]);
    }

    private static function rest_out(array $res, string $target, bool $done): array
    {
        $out = [
            'files'   => array_map(function ($f) {
                return ['rel' => $f['rel'], 'name' => $f['name'], 'dir' => $f['dir'], 'size' => self::human_size((int) $f['size']),
                        'bytes' => (int) $f['size'], 'mtime' => $f['mtime'] ? wp_date('d.m.Y H:i', (int) $f['mtime']) : '',
                        'status' => $f['status'], 'in_list' => $f['in_list']];
            }, $res['files']),
            'gone'    => $res['gone'],
            'report'  => $res['report'],
            'message' => $done ? self::report_text($res['report']) : '',
            'sub'     => $res['sub'],
            'driver'  => $res['driver'],
        ];
        if ($target === 'items' || $target === 'page') {
            $out['rows'] = $res['rows'];
        }
        if ($target === 'page' && $done) {
            $html = '';
            foreach ($res['rows'] as $i => $row) {
                $html .= WPD_Page_Editor::row_html('docs', (string) $i, $row);
            }
            $out['html'] = $html;
        }
        return $out;
    }

    /* ---------------------------------------------------------------
     * Вкладка «Документы»: подключение, проверка, предпросмотр и импорт
     * ------------------------------------------------------------- */

    public static function enqueue(string $hook): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification
        $tab = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : '';
        if (!isset($_GET['page']) || $_GET['page'] !== WPD_Settings::PAGE || $tab !== 'docs') { // phpcs:ignore WordPress.Security.NonceVerification
            return;
        }
        wp_enqueue_script('wpd-netfolder', WPD_URL . 'assets/js/wpd-netfolder.js', ['wp-api-fetch'], WPD_VERSION, true);
    }

    /** Данные для блочного редактора и панели страницы. */
    public static function editor_data(): array
    {
        $o = self::get();
        return [
            'enabled'  => !empty($o['enabled']),
            'schedule' => (string) (self::SCHEDULES[$o['schedule']] ?? ''),
            'auto'     => (string) $o['schedule'] !== '',
            'mode'     => (string) $o['mode'],
            'root'     => (string) $o['path'] !== '' ? (string) $o['path'] : (string) $o['mount'],
        ];
    }

    public static function render_box(string $tab): void
    {
        if ($tab !== 'docs' || !current_user_can('manage_options')) {
            return;
        }
        $o       = self::get();
        $client  = self::client($o);
        $drivers = $client->drivers();
        $id      = function (string $k): string {
            return 'wpd_nf_' . $k;
        };
        $name    = function (string $k): string {
            return self::OPTION . '[' . $k . ']';
        };
        $text    = function (string $k, string $label, string $desc = '', string $ph = '', string $type = 'text') use ($o, $id, $name): void {
            printf('<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="%6$s" id="%1$s" name="%3$s" value="%4$s" class="regular-text" placeholder="%5$s"%7$s>%8$s</td></tr>',
                esc_attr($id($k)), esc_html($label), esc_attr($name($k)), esc_attr((string) $o[$k]), esc_attr($ph), esc_attr($type),
                $type === 'number' ? ' min="1" max="200" style="width:6em"' : '', $desc !== '' ? '<p class="description">' . $desc . '</p>' : '');
        };
        $check   = function (string $k, string $label, string $desc = '') use ($o, $id, $name): void {
            printf('<tr><th scope="row">%5$s</th><td><label for="%1$s"><input type="checkbox" id="%1$s" name="%2$s" value="1"%3$s> %4$s</label>%6$s</td></tr>',
                esc_attr($id($k)), esc_attr($name($k)), checked(!empty($o[$k]), true, false), esc_html($label), '', $desc !== '' ? '<p class="description">' . $desc . '</p>' : '');
        };
        $select  = function (string $k, string $label, array $options, string $desc = '') use ($o, $id, $name): void {
            $opts = '';
            foreach ($options as $v => $l) {
                $opts .= sprintf('<option value="%s"%s>%s</option>', esc_attr((string) $v), selected((string) $o[$k], (string) $v, false), esc_html($l));
            }
            printf('<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><select id="%1$s" name="%3$s">%4$s</select>%5$s</td></tr>',
                esc_attr($id($k)), esc_html($label), esc_attr($name($k)), $opts, $desc !== '' ? '<p class="description">' . $desc . '</p>' : '');
        };
        $yes = function (bool $v): string {
            return $v ? '<strong style="color:#15803d">есть</strong>' : '<span style="color:#b91c1c">нет</span>';
        };
        ?>
        <div class="wpd-nf" id="wpd-nf" data-wpd-nf>
            <h2>Сетевая папка (SMB)</h2>
            <p class="description" style="max-width:860px">
                Документы можно импортировать из общей папки Windows или файлового сервера (SMB): файлы копируются в медиатеку
                или в список попадают ссылки, по которым сайт отдаёт файлы из папки. Импорт — кнопкой ниже и по расписанию;
                свою подпапку можно задать для страницы (панель «Блоки олимпиады») и для блока «Олимпиада: документы».
                Сервер сайта должен «видеть» файловый сервер по сети (порт 445).
            </p>
            <p class="wpd-nf__env">На сервере сайта: PHP-расширение smbclient — <?php echo $yes($drivers['ext']); // phpcs:ignore ?>,
                программа smbclient — <?php echo $yes(WPD_Smb::cli_binary((string) apply_filters('wpd_nf_smbclient_bin', '')) !== ''); // phpcs:ignore ?>.
                <?php if (!$drivers['ext'] && WPD_Smb::cli_binary() === '') : ?>
                    Без них работает только способ «Папка, подключённая к серверу сайта»: администратор сервера подключает сетевую папку
                    (например, <code>mount -t cifs //fileserver/olymp /mnt/olymp</code>), а здесь указывается путь <code>/mnt/olymp</code>.
                <?php endif; ?>
            </p>
            <form method="post" action="options.php" data-wpd-nf-form>
                <?php settings_fields(self::GROUP); ?>
                <table class="form-table" role="presentation">
                    <?php
                    $check('enabled', 'Включить импорт из сетевой папки');
                    $text('path', 'Адрес папки', 'Например, <code>\\\\fileserver\\olymp\\Документы</code> или <code>smb://192.168.1.10/olymp/Документы</code>.', '\\\\fileserver\\olymp\\Документы');
                    $text('user', 'Пользователь', 'Пусто — гостевой доступ.', '', 'text');
                    printf('<tr><th scope="row"><label for="wpd_nf_pass">Пароль</label></th><td><input type="password" id="wpd_nf_pass" name="%1$s[pass]" value="" class="regular-text" autocomplete="new-password" placeholder="%2$s">'
                        . '%3$s<p class="description">Хранится зашифрованным. Пусто — оставить сохранённый.</p></td></tr>',
                        esc_attr(self::OPTION), $o['pass'] !== '' ? esc_attr('•••••••• (сохранён)') : '',
                        $o['pass'] !== '' ? ' <label><input type="checkbox" name="' . esc_attr(self::OPTION) . '[pass_clear]" value="1"> удалить сохранённый пароль</label>' : '');
                    $text('domain', 'Домен или рабочая группа', 'Например, <code>SCHOOL</code>. Обычно можно оставить пустым.');
                    $select('driver', 'Способ подключения', self::DRIVERS, '«Автоматически» — PHP-расширение, затем программа smbclient, затем подключённая папка.');
                    $text('mount', 'Путь к подключённой папке', 'Только для способа «Папка, подключённая к серверу сайта».', '/mnt/olymp');
                    $select('mode', 'Как добавлять файлы', self::MODES, 'Копии в медиатеке не зависят от доступности папки. Ссылки всегда ведут на актуальный файл, но папка должна быть доступна (сайт хранит копию час).');
                    $text('sub', 'Подпапка для общего списка', 'Относительно адреса папки, например <code>Положения</code>. Пусто — сама папка.');
                    $check('recursive', 'Включать файлы из подпапок', 'Имя подпапки попадает в подпись документа.');
                    $text('exts', 'Типы файлов', 'Расширения через запятую.');
                    $text('max_mb', 'Наибольший размер файла, МБ', '', '', 'number');
                    $check('desc', 'Подпись: тип и размер файла (например, «PDF, 1,4 МБ»)');
                    $check('new_tab', 'Новые документы открывать в новой вкладке');
                    $check('new_first', 'Новые документы — в начало списка');
                    $select('gone', 'Файл удалён из папки', self::GONE, 'Скрытая строка снова показывается, когда файл вернётся.');
                    $select('schedule', 'Обновлять по расписанию', self::SCHEDULES, 'Через WP-Cron: срабатывает при посещениях сайта.');
                    $check('auto_settings', 'Обновлять по расписанию общий список документов', 'Страницы и блоки включают автообновление у себя.');
                    ?>
                </table>
                <?php submit_button('Сохранить подключение', 'secondary'); ?>
            </form>

            <div class="wpd-nf__tools" data-wpd-nf-tools>
                <p>
                    <button type="button" class="button" data-wpd-nf-test>Проверить подключение</button>
                    <button type="button" class="button" data-wpd-nf-scan<?php disabled(empty($o['enabled'])); ?>>Показать файлы для общего списка</button>
                    <span class="spinner" data-wpd-nf-spin></span>
                </p>
                <?php if (empty($o['enabled'])) : ?>
                    <p class="description">Импорт выключен: отметьте «Включить импорт из сетевой папки» и сохраните подключение.</p>
                <?php endif; ?>
                <div data-wpd-nf-result aria-live="polite"></div>
                <div data-wpd-nf-preview hidden>
                    <table class="widefat striped wpd-nf__table">
                        <thead><tr><th class="check-column"><input type="checkbox" data-wpd-nf-all checked aria-label="Все новые"></th>
                            <th>Файл</th><th>Размер</th><th>Изменён</th><th>Состояние</th></tr></thead>
                        <tbody data-wpd-nf-rows></tbody>
                    </table>
                    <p><button type="button" class="button button-primary" data-wpd-nf-import>Импортировать в общий список</button>
                        <span class="description">Новые — только отмеченные; изменённые обновляются, пропавшие из папки — по настройке выше.</span></p>
                </div>
                <?php self::render_log(); ?>
            </div>
        </div>
        <?php
    }

    private static function render_log(): void
    {
        $log = get_option(self::LOG, []);
        if (!is_array($log) || !$log) {
            return;
        }
        echo '<details class="wpd-nf__log"><summary>Журнал импорта</summary><ul>';
        foreach (array_slice($log, 0, 10) as $e) {
            printf('<li%s><time>%s</time> — %s, %s: %s</li>',
                !empty($e['error']) ? ' class="is-error"' : '',
                esc_html(wp_date('d.m.Y H:i', (int) $e['time'])),
                esc_html($e['source'] === 'cron' ? 'по расписанию' : 'вручную'),
                esc_html((string) $e['where']),
                esc_html((string) $e['text']));
        }
        echo '</ul></details>';
    }
}
