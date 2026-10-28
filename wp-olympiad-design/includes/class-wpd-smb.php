<?php
/**
 * Клиент сетевой папки SMB (2.8.0) — без зависимостей от WordPress.
 *
 * Три способа доступа («драйвера»):
 *  - ext   — PHP-расширение smbclient (libsmbclient-php): функции smbclient_*;
 *  - cli   — программа smbclient из пакета Samba, запускается через proc_open (без оболочки);
 *  - mount — папка, уже подключённая (смонтированная) к серверу сайта администратором, например /mnt/olymp.
 * auto выбирает первый доступный: ext → cli → mount.
 *
 * Пути внутри папки («rel») — через «/», относительно корня подключения (\\сервер\ресурс\путь).
 */

if (!defined('ABSPATH') && !defined('WPD_SMB_STANDALONE')) {
    exit;
}

class WPD_Smb
{
    const DRIVERS = ['auto', 'ext', 'cli', 'mount'];

    /** Сколько файлов и уровней вложенности читается за один просмотр папки. */
    const MAX_FILES = 500;
    const MAX_DEPTH = 5;

    /** @var array */
    private $cfg;
    /** @var string */
    private $error = '';
    /** @var string|null */
    private $driver = null;
    /** @var resource|null состояние libsmbclient */
    private $state = null;

    /**
     * @param array $cfg driver, path (UNC: \\host\share\dir или smb://host/share/dir), user, pass, domain, mount, bin, timeout
     */
    public function __construct(array $cfg)
    {
        $this->cfg = array_merge([
            'driver'  => 'auto',
            'path'    => '',
            'user'    => '',
            'pass'    => '',
            'domain'  => '',
            'mount'   => '',
            'bin'     => '',
            'timeout' => 30,
        ], $cfg);
    }

    public function __destruct()
    {
        if ($this->state && function_exists('smbclient_state_free')) {
            @smbclient_state_free($this->state); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }
    }

    public function error(): string
    {
        return $this->error;
    }

    /* ---------------------------------------------------------------
     * Разбор и проверка путей
     * ------------------------------------------------------------- */

    /**
     * Адрес папки: \\host\share\dir, //host/share/dir или smb://host/share/dir.
     *
     * @return array{host:string,share:string,path:string}|array{} пустой массив — адрес не распознан
     */
    public static function parse_unc(string $s): array
    {
        $s = trim($s);
        $s = (string) preg_replace('#^smb:#i', '', $s);
        $s = str_replace('\\', '/', $s);
        if (strpos($s, '//') !== 0) {
            return [];
        }
        $parts = array_values(array_filter(explode('/', substr($s, 2)), function ($p) {
            return $p !== '';
        }));
        if (count($parts) < 2) {
            return [];
        }
        $host = $parts[0];
        // Имя или IP-адрес сервера (IPv6 — в квадратных скобках), без пользователя и порта в адресе
        if (!preg_match('/^(\[[0-9a-f:.]+\]|[\p{L}\p{N}][\p{L}\p{N}._-]*)$/iu', $host)) {
            return [];
        }
        $share = $parts[1];
        if (preg_match('/[\\\\\/:*?"<>|\x00-\x1f]/', $share)) {
            return [];
        }
        return ['host' => $host, 'share' => $share, 'path' => self::clean_rel(implode('/', array_slice($parts, 2)))];
    }

    /**
     * Относительный путь: «/» вместо «\», без «.», «..», пустых частей и управляющих символов.
     */
    public static function clean_rel(string $s): string
    {
        $s   = str_replace('\\', '/', $s);
        $out = [];
        foreach (explode('/', $s) as $part) {
            $part = trim((string) preg_replace('/[\x00-\x1f\x7f]/', '', $part));
            if ($part === '' || $part === '.' || $part === '..') {
                continue;
            }
            $out[] = $part;
        }
        return implode('/', $out);
    }

    /** Соединение частей пути. */
    public static function join(string ...$parts): string
    {
        return self::clean_rel(implode('/', $parts));
    }

    /* ---------------------------------------------------------------
     * Доступность драйверов
     * ------------------------------------------------------------- */

    public static function ext_available(): bool
    {
        return function_exists('smbclient_state_new') && function_exists('smbclient_opendir');
    }

    public static function proc_available(): bool
    {
        if (!function_exists('proc_open') || !function_exists('proc_close')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return !in_array('proc_open', $disabled, true);
    }

    /** Путь к программе smbclient или пустая строка. */
    public static function cli_binary(string $hint = ''): string
    {
        if (!self::proc_available()) {
            return '';
        }
        $candidates = array_filter([$hint, '/usr/bin/smbclient', '/usr/local/bin/smbclient', '/opt/homebrew/bin/smbclient', '/bin/smbclient']);
        foreach ($candidates as $path) {
            if (@is_file($path) && @is_executable($path)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- open_basedir
                return $path;
            }
        }
        return '';
    }

    /**
     * Какие драйверы можно использовать на этом сервере.
     *
     * @return array<string,bool>
     */
    public function drivers(): array
    {
        return [
            'ext'   => self::ext_available(),
            'cli'   => self::cli_binary((string) $this->cfg['bin']) !== '',
            'mount' => trim((string) $this->cfg['mount']) !== '',
        ];
    }

    /**
     * Выбранный драйвер: явно заданный (если доступен) или первый доступный. Пусто — нет подходящего (см. error()).
     */
    public function driver(): string
    {
        if ($this->driver !== null) {
            return $this->driver;
        }
        $want  = in_array($this->cfg['driver'], self::DRIVERS, true) ? $this->cfg['driver'] : 'auto';
        $have  = $this->drivers();
        $smbok = $this->unc() !== [];

        if ($want === 'auto') {
            foreach (['ext', 'cli'] as $d) {
                if ($have[$d] && $smbok) {
                    return $this->driver = $d;
                }
            }
            if ($have['mount']) {
                return $this->driver = 'mount';
            }
            $this->error = !$smbok && !$have['mount']
                ? 'Не указан адрес сетевой папки (\\\\сервер\\папка) или путь к подключённой папке на сервере.'
                : 'На сервере сайта нет способа подключиться к SMB: нет ни PHP-расширения smbclient, ни программы smbclient '
                    . '(или запрещён proc_open). Попросите администратора сервера установить одно из них или подключить сетевую папку '
                    . 'к серверу и укажите путь к ней.';
            return $this->driver = '';
        }
        if (!$have[$want]) {
            $this->error = [
                'ext'   => 'PHP-расширение smbclient не установлено на сервере сайта.',
                'cli'   => 'Программа smbclient не найдена на сервере сайта или запрещён запуск программ (proc_open).',
                'mount' => 'Не указан путь к подключённой папке на сервере.',
            ][$want];
            return $this->driver = '';
        }
        if ($want !== 'mount' && !$smbok) {
            $this->error = 'Адрес сетевой папки не распознан. Пример: \\\\fileserver\\olymp\\Документы.';
            return $this->driver = '';
        }
        return $this->driver = $want;
    }

    /** @return array{host:string,share:string,path:string}|array{} */
    private function unc(): array
    {
        return self::parse_unc((string) $this->cfg['path']);
    }

    /* ---------------------------------------------------------------
     * Публичные операции
     * ------------------------------------------------------------- */

    /**
     * Проверка подключения: чтение корня (или подпапки).
     *
     * @return array{ok:bool,driver:string,message:string,dirs:int,files:int}
     */
    public function test(string $sub = ''): array
    {
        $driver = $this->driver();
        if ($driver === '') {
            return ['ok' => false, 'driver' => '', 'message' => $this->error, 'dirs' => 0, 'files' => 0];
        }
        $list = $this->listdir(self::clean_rel($sub));
        if ($list === null) {
            return ['ok' => false, 'driver' => $driver, 'message' => $this->error, 'dirs' => 0, 'files' => 0];
        }
        $dirs = count(array_filter($list, function ($e) {
            return $e['dir'];
        }));
        return ['ok' => true, 'driver' => $driver, 'message' => 'Подключение работает.', 'dirs' => $dirs, 'files' => count($list) - $dirs];
    }

    /**
     * Содержимое одной папки: [['name' => ..., 'dir' => bool, 'size' => int, 'mtime' => int], ...]. null — ошибка.
     */
    public function listdir(string $rel): ?array
    {
        $rel = self::clean_rel($rel);
        switch ($this->driver()) {
            case 'ext':
                $list = $this->ext_list($rel);
                break;
            case 'cli':
                $list = $this->cli_list($rel);
                break;
            case 'mount':
                $list = $this->mount_list($rel);
                break;
            default:
                return null;
        }
        if ($list === null) {
            return null;
        }
        $list = array_values(array_filter($list, function ($e) {
            return $e['name'] !== '.' && $e['name'] !== '..';
        }));
        usort($list, function ($a, $b) {
            return strnatcasecmp($a['name'], $b['name']);
        });
        return $list;
    }

    /**
     * Файлы папки (с подпапками до $opts['depth'] уровней).
     *
     * @param array $opts depth (0 — только сама папка), exts (['pdf', ...], пусто — все), max (не больше MAX_FILES)
     * @return array|null [['rel' => 'Итоги/a.pdf', 'name' => 'a.pdf', 'dir' => 'Итоги', 'size' => int, 'mtime' => int, 'ext' => 'pdf'], ...]
     */
    public function scan(string $sub, array $opts = []): ?array
    {
        $depth = max(0, min(self::MAX_DEPTH, (int) ($opts['depth'] ?? 0)));
        $exts  = array_map('strtolower', (array) ($opts['exts'] ?? []));
        $max   = max(1, min(self::MAX_FILES, (int) ($opts['max'] ?? self::MAX_FILES)));
        $root  = self::clean_rel($sub);
        $out   = [];
        $queue = [[$root, 0]];

        while ($queue && count($out) < $max) {
            [$dir, $level] = array_shift($queue);
            $list = $this->listdir($dir);
            if ($list === null) {
                if ($dir === $root) {
                    return null;
                }
                continue; // недоступная подпапка не мешает остальным
            }
            foreach ($list as $e) {
                $name = $e['name'];
                // Скрытые и временные файлы Office (~$Документ.docx) не импортируются
                if ($name === '' || $name[0] === '.' || strpos($name, '~$') === 0 || strtolower($name) === 'thumbs.db' || strtolower($name) === 'desktop.ini') {
                    continue;
                }
                $rel = self::join($dir, $name);
                if ($e['dir']) {
                    if ($level < $depth) {
                        $queue[] = [$rel, $level + 1];
                    }
                    continue;
                }
                $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
                if ($exts && !in_array($ext, $exts, true)) {
                    continue;
                }
                $parent = self::clean_rel(substr($rel, strlen($root)));
                $parent = (string) (strpos($parent, '/') !== false ? substr($parent, 0, (int) strrpos($parent, '/')) : '');
                $out[] = ['rel' => $rel, 'name' => $name, 'dir' => $parent, 'size' => (int) $e['size'], 'mtime' => (int) $e['mtime'], 'ext' => $ext];
                if (count($out) >= $max) {
                    break;
                }
            }
        }
        usort($out, function ($a, $b) {
            return strnatcasecmp($a['rel'], $b['rel']);
        });
        return $out;
    }

    /**
     * Скачивание файла в локальный $dest. $max_bytes > 0 — не больше этого размера.
     */
    public function fetch(string $rel, string $dest, int $max_bytes = 0): bool
    {
        $rel = self::clean_rel($rel);
        if ($rel === '') {
            $this->error = 'Не указан файл.';
            return false;
        }
        switch ($this->driver()) {
            case 'ext':
                $ok = $this->ext_fetch($rel, $dest, $max_bytes);
                break;
            case 'cli':
                $ok = $this->cli_fetch($rel, $dest, $max_bytes);
                break;
            case 'mount':
                $ok = $this->mount_fetch($rel, $dest, $max_bytes);
                break;
            default:
                return false;
        }
        if (!$ok && is_file($dest)) {
            @unlink($dest); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }
        return $ok;
    }

    /* ---------------------------------------------------------------
     * Драйвер ext: libsmbclient-php
     * ------------------------------------------------------------- */

    private function ext_state()
    {
        if ($this->state) {
            return $this->state;
        }
        $st = smbclient_state_new();
        if (!$st) {
            $this->error = 'Не удалось запустить libsmbclient.';
            return null;
        }
        if (defined('SMBCLIENT_OPT_TIMEOUT') && function_exists('smbclient_option_set')) {
            @smbclient_option_set($st, SMBCLIENT_OPT_TIMEOUT, max(5, (int) $this->cfg['timeout']) * 1000); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }
        $domain = (string) $this->cfg['domain'] !== '' ? (string) $this->cfg['domain'] : null;
        $user   = (string) $this->cfg['user'] !== '' ? (string) $this->cfg['user'] : 'guest';
        $pass   = (string) $this->cfg['pass'];
        if (!@smbclient_state_init($st, $domain, $user, $pass)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
            $this->error = self::errno_message((int) smbclient_state_errno($st));
            smbclient_state_free($st);
            return null;
        }
        return $this->state = $st;
    }

    /** URL libsmbclient: smb://host/share/путь, части пути закодированы. */
    private function ext_url(string $rel): string
    {
        $u    = $this->unc();
        $path = self::join($u['path'], $rel);
        $enc  = $path === '' ? '' : '/' . implode('/', array_map('rawurlencode', explode('/', $path)));
        return 'smb://' . $u['host'] . '/' . rawurlencode($u['share']) . $enc;
    }

    private function ext_list(string $rel): ?array
    {
        $st = $this->ext_state();
        if (!$st) {
            return null;
        }
        $url = $this->ext_url($rel);
        $dh  = @smbclient_opendir($st, $url); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        if (!$dh) {
            $this->error = self::errno_message((int) smbclient_state_errno($st));
            return null;
        }
        $out = [];
        while (($e = @smbclient_readdir($st, $dh)) !== false) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
            if (!is_array($e) || !isset($e['name'])) {
                continue;
            }
            $type = (string) ($e['type'] ?? '');
            if ($type !== 'file' && $type !== 'directory') {
                continue; // ссылки, принтеры, общие ресурсы и т. п.
            }
            $out[] = ['name' => (string) $e['name'], 'dir' => $type === 'directory', 'size' => 0, 'mtime' => 0];
        }
        @smbclient_closedir($st, $dh); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        foreach ($out as $i => $e) {
            if ($e['dir'] || $e['name'] === '.' || $e['name'] === '..') {
                continue;
            }
            $stat = @smbclient_stat($st, $url . '/' . rawurlencode($e['name'])); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            if (is_array($stat)) {
                $out[$i]['size']  = (int) ($stat['size'] ?? 0);
                $out[$i]['mtime'] = (int) ($stat['mtime'] ?? 0);
            }
        }
        return $out;
    }

    private function ext_fetch(string $rel, string $dest, int $max_bytes): bool
    {
        $st = $this->ext_state();
        if (!$st) {
            return false;
        }
        $fh = @smbclient_open($st, $this->ext_url($rel), 'r'); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        if (!$fh) {
            $this->error = self::errno_message((int) smbclient_state_errno($st));
            return false;
        }
        $out = @fopen($dest, 'wb'); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
        if (!$out) {
            @smbclient_close($st, $fh); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            $this->error = 'Не удалось создать временный файл на сервере сайта.';
            return false;
        }
        $total = 0;
        $ok    = true;
        while (true) {
            $chunk = @smbclient_read($st, $fh, 65536); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            if ($chunk === false) {
                $this->error = self::errno_message((int) smbclient_state_errno($st));
                $ok = false;
                break;
            }
            if ($chunk === '') {
                break;
            }
            $total += strlen($chunk);
            if ($max_bytes > 0 && $total > $max_bytes) {
                $this->error = 'Файл больше допустимого размера.';
                $ok = false;
                break;
            }
            fwrite($out, $chunk); // phpcs:ignore WordPress.WP.AlternativeFunctions
        }
        fclose($out); // phpcs:ignore WordPress.WP.AlternativeFunctions
        @smbclient_close($st, $fh); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        return $ok;
    }

    /** Текст ошибки libsmbclient по коду errno. */
    public static function errno_message(int $errno): string
    {
        switch ($errno) {
            case 1:   // EPERM
            case 13:  // EACCES
                return 'Нет доступа: проверьте имя пользователя, пароль, домен и права на папку.';
            case 2:   // ENOENT
                return 'Папка или файл не найдены: проверьте имя ресурса и путь.';
            case 20:  // ENOTDIR
                return 'Указанный путь — не папка.';
            case 110: // ETIMEDOUT
                return 'Сервер не ответил вовремя (тайм-аут).';
            case 111: // ECONNREFUSED
                return 'Сервер отказал в подключении: на нём не запущен SMB или порт 445 закрыт.';
            case 112: // EHOSTDOWN
            case 113: // EHOSTUNREACH
            case 101: // ENETUNREACH
                return 'Сервер недоступен из сети сервера сайта.';
            case 22:  // EINVAL
                return 'Неверный адрес папки или имя сервера не найдено.';
            case 19:  // ENODEV
            case 6:   // ENXIO
                return 'Общий ресурс не найден на сервере.';
            default:
                return 'Ошибка SMB (код ' . $errno . ').';
        }
    }

    /* ---------------------------------------------------------------
     * Драйвер cli: программа smbclient
     * ------------------------------------------------------------- */

    /**
     * Запуск smbclient без оболочки. Пароль передаётся через временный файл с правами 600, а не в командной строке.
     *
     * @return array{code:int,out:string,err:string}|null
     */
    private function cli_run(string $dir, string $cmd): ?array
    {
        $bin = self::cli_binary((string) $this->cfg['bin']);
        $u   = $this->unc();
        if ($bin === '' || !$u) {
            $this->error = 'Программа smbclient недоступна.';
            return null;
        }
        $auth = tempnam(sys_get_temp_dir(), 'wpdsmb');
        if ($auth === false) {
            $this->error = 'Не удалось создать временный файл на сервере сайта.';
            return null;
        }
        @chmod($auth, 0600); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        $lines = 'username = ' . ((string) $this->cfg['user'] !== '' ? $this->cfg['user'] : 'guest') . "\n"
            . 'password = ' . $this->cfg['pass'] . "\n"
            . ((string) $this->cfg['domain'] !== '' ? 'domain = ' . $this->cfg['domain'] . "\n" : '');
        file_put_contents($auth, $lines); // phpcs:ignore WordPress.WP.AlternativeFunctions

        $args = [$bin, '//' . $u['host'] . '/' . $u['share'], '-A', $auth, '-t', (string) max(5, (int) $this->cfg['timeout'])];
        $path = self::join($u['path'], $dir);
        if ($path !== '') {
            $args[] = '-D';
            $args[] = $path;
        }
        $args[] = '-c';
        $args[] = $cmd;

        $env  = ['LC_ALL' => 'C.UTF-8', 'LANG' => 'C.UTF-8', 'PATH' => '/usr/bin:/bin:/usr/local/bin', 'HOME' => sys_get_temp_dir()];
        $proc = @proc_open($args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        if (!is_resource($proc)) {
            @unlink($auth); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            $this->error = 'Не удалось запустить smbclient.';
            return null;
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out      = '';
        $err      = '';
        $deadline = microtime(true) + max(5, (int) $this->cfg['timeout']) + 30;
        while (true) {
            $read   = [$pipes[1], $pipes[2]];
            $write  = null;
            $except = null;
            if (@stream_select($read, $write, $except, 1) === false) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
                break;
            }
            foreach ($read as $r) {
                $chunk = (string) fread($r, 65536); // phpcs:ignore WordPress.WP.AlternativeFunctions
                if ($r === $pipes[1]) {
                    $out .= $chunk;
                } else {
                    $err .= $chunk;
                }
            }
            if (feof($pipes[1]) && feof($pipes[2])) {
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($proc);
                $err .= "\nNT_STATUS_IO_TIMEOUT";
                break;
            }
        }
        fclose($pipes[1]); // phpcs:ignore WordPress.WP.AlternativeFunctions
        fclose($pipes[2]); // phpcs:ignore WordPress.WP.AlternativeFunctions
        $code = proc_close($proc);
        @unlink($auth); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        return ['code' => (int) $code, 'out' => $out, 'err' => $err];
    }

    /** Имя, которое безопасно передать в команде smbclient (в кавычках): без кавычек и «;». */
    private static function cli_safe(string $name): bool
    {
        return strpbrk($name, "\";\r\n") === false;
    }

    private function cli_list(string $rel): ?array
    {
        $r = $this->cli_run($rel, 'ls');
        if ($r === null) {
            return null;
        }
        $status = self::nt_status($r['out'] . "\n" . $r['err']);
        if ($status !== '') {
            $this->error = self::nt_message($status);
            return null;
        }
        if ($r['code'] !== 0 && trim($r['out']) === '') {
            $this->error = 'smbclient завершился с ошибкой: ' . trim(substr($r['err'], 0, 300));
            return null;
        }
        return self::parse_ls($r['out']);
    }

    private function cli_fetch(string $rel, string $dest, int $max_bytes): bool
    {
        $dir  = strpos($rel, '/') !== false ? substr($rel, 0, (int) strrpos($rel, '/')) : '';
        $name = basename(str_replace('\\', '/', $rel));
        if (!self::cli_safe($name) || !self::cli_safe($dest)) {
            $this->error = 'Имя файла содержит кавычки или «;» — через программу smbclient его не скачать. Переименуйте файл.';
            return false;
        }
        $r = $this->cli_run($dir, 'get "' . $name . '" "' . $dest . '"');
        if ($r === null) {
            return false;
        }
        $status = self::nt_status($r['out'] . "\n" . $r['err']);
        if ($status !== '' || !is_file($dest)) {
            $this->error = $status !== '' ? self::nt_message($status) : 'Файл не скачан.';
            return false;
        }
        if ($max_bytes > 0 && filesize($dest) > $max_bytes) {
            $this->error = 'Файл больше допустимого размера.';
            return false;
        }
        return true;
    }

    /**
     * Разбор вывода «ls» программы smbclient:
     * «  Положение.pdf                     A    12345  Wed Oct 28 05:43:26 2026».
     */
    public static function parse_ls(string $out): array
    {
        $list = [];
        foreach (preg_split('/\r?\n/', $out) as $line) {
            if (!preg_match('/^  (.*?)\s+([DAHSRNIVXCLO]*)\s+(\d+)\s+(\w{3} \w{3} [ \d]?\d \d\d:\d\d:\d\d \d{4})\s*$/u', $line, $m)) {
                continue;
            }
            $name = rtrim($m[1]);
            if ($name === '.' || $name === '..' || $name === '') {
                continue;
            }
            $time   = strtotime(preg_replace('/\s+/', ' ', $m[4]));
            $list[] = ['name' => $name, 'dir' => strpos($m[2], 'D') !== false, 'size' => (int) $m[3], 'mtime' => $time ? (int) $time : 0];
        }
        return $list;
    }

    /** Первый код NT_STATUS_… ошибки в выводе (кроме OK). */
    public static function nt_status(string $text): string
    {
        if (preg_match_all('/NT_STATUS_[A-Z0-9_]+/', $text, $m)) {
            foreach ($m[0] as $code) {
                if ($code !== 'NT_STATUS_OK' && $code !== 'NT_STATUS_NO_MORE_FILES') {
                    return $code;
                }
            }
        }
        return '';
    }

    public static function nt_message(string $code): string
    {
        $map = [
            'NT_STATUS_LOGON_FAILURE'           => 'Неверное имя пользователя или пароль.',
            'NT_STATUS_ACCESS_DENIED'           => 'Нет доступа к папке: проверьте права пользователя.',
            'NT_STATUS_ACCOUNT_DISABLED'        => 'Учётная запись отключена.',
            'NT_STATUS_ACCOUNT_LOCKED_OUT'      => 'Учётная запись заблокирована.',
            'NT_STATUS_PASSWORD_EXPIRED'        => 'Срок действия пароля истёк.',
            'NT_STATUS_BAD_NETWORK_NAME'        => 'Общий ресурс не найден на сервере: проверьте имя после имени сервера.',
            'NT_STATUS_OBJECT_NAME_NOT_FOUND'   => 'Папка или файл не найдены: проверьте путь.',
            'NT_STATUS_OBJECT_PATH_NOT_FOUND'   => 'Папка не найдена: проверьте путь.',
            'NT_STATUS_NOT_A_DIRECTORY'         => 'Указанный путь — не папка.',
            'NT_STATUS_CONNECTION_REFUSED'      => 'Сервер отказал в подключении: на нём не запущен SMB или порт 445 закрыт.',
            'NT_STATUS_HOST_UNREACHABLE'        => 'Сервер недоступен из сети сервера сайта.',
            'NT_STATUS_NETWORK_UNREACHABLE'     => 'Сеть сервера недоступна.',
            'NT_STATUS_IO_TIMEOUT'              => 'Сервер не ответил вовремя (тайм-аут).',
            'NT_STATUS_UNSUCCESSFUL'            => 'Не удалось подключиться: имя сервера не найдено или он недоступен.',
            'NT_STATUS_INVALID_PARAMETER'       => 'Неверный адрес папки.',
            'NT_STATUS_RESOURCE_NAME_NOT_FOUND' => 'Имя сервера не найдено.',
        ];
        return $map[$code] ?? 'Ошибка SMB: ' . $code . '.';
    }

    /* ---------------------------------------------------------------
     * Драйвер mount: подключённая к серверу папка
     * ------------------------------------------------------------- */

    /** Абсолютный путь внутри подключённой папки или null, если выходит за её пределы. */
    private function mount_path(string $rel): ?string
    {
        $base = realpath(rtrim((string) $this->cfg['mount'], '/\\'));
        if ($base === false || !is_dir($base)) {
            $this->error = 'Подключённая папка не найдена на сервере сайта: ' . $this->cfg['mount'];
            return null;
        }
        $path = $base . ($rel !== '' ? DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel) : '');
        $real = realpath($path);
        if ($real === false) {
            $this->error = 'Папка или файл не найдены: проверьте путь.';
            return null;
        }
        if ($real !== $base && strpos($real, rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) !== 0) {
            $this->error = 'Путь выходит за пределы подключённой папки.';
            return null;
        }
        return $real;
    }

    private function mount_list(string $rel): ?array
    {
        $dir = $this->mount_path($rel);
        if ($dir === null) {
            return null;
        }
        if (!is_dir($dir)) {
            $this->error = 'Указанный путь — не папка.';
            return null;
        }
        $names = @scandir($dir); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        if ($names === false) {
            $this->error = 'Нет доступа к папке на сервере сайта.';
            return null;
        }
        $out = [];
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $full = $dir . DIRECTORY_SEPARATOR . $name;
            if (is_link($full)) {
                continue;
            }
            $is_dir = is_dir($full);
            $out[]  = ['name' => $name, 'dir' => $is_dir, 'size' => $is_dir ? 0 : (int) @filesize($full), 'mtime' => (int) @filemtime($full)]; // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }
        return $out;
    }

    private function mount_fetch(string $rel, string $dest, int $max_bytes): bool
    {
        $file = $this->mount_path($rel);
        if ($file === null) {
            return false;
        }
        if (!is_file($file)) {
            $this->error = 'Это не файл.';
            return false;
        }
        if ($max_bytes > 0 && filesize($file) > $max_bytes) {
            $this->error = 'Файл больше допустимого размера.';
            return false;
        }
        if (!@copy($file, $dest)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
            $this->error = 'Не удалось скопировать файл.';
            return false;
        }
        return true;
    }
}
