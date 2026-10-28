<?php
// Проверка клиента SMB (2.8.0) на настоящем сервере Samba: драйверы ext (PHP-расширение smbclient), cli (программа smbclient)
// и mount (локальный каталог ресурса). Запуск — run.sh (создаёт файлы в ресурсе и передаёт параметры через окружение).
define('WPD_SMB_STANDALONE', 1);
require __DIR__ . '/../../wp-olympiad-design/includes/class-wpd-smb.php';

$host  = getenv('SMB_HOST') ?: '127.0.0.1';
$share = getenv('SMB_SHARE') ?: 'olymp';
$user  = getenv('SMB_USER') ?: 'wpdsmb';
$pass  = getenv('SMB_PASS') ?: 'Пароль-123';
$local = getenv('SMB_LOCAL') ?: '/tmp/smbshare';
$dir   = 'Документы олимпиады';

$pass_n = 0; $fail_n = 0;
function t(string $name, bool $ok, string $detail = ''): void {
    global $pass_n, $fail_n;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : ' :: ' . $detail) . "\n";
    $ok ? $pass_n++ : $fail_n++;
}

t('parse_unc', WPD_Smb::parse_unc("\\\\$host\\$share\\$dir") === ['host' => $host, 'share' => $share, 'path' => $dir]);
t('parse_ls: padded cyrillic names', count(WPD_Smb::parse_ls("  .                                   D        0  Mon Oct 27 10:00:00 2026\n  Отчёт 1.pdf                         A     1234  Tue Oct 28 09:15:07 2026\n  Папка                               D        0  Tue Oct 28 09:15:07 2026\n")) === 2);

$drivers = ['mount'];
if (WPD_Smb::ext_available()) { $drivers[] = 'ext'; } else { echo "SKIP ext: PHP-расширение smbclient не загружено\n"; }
if (WPD_Smb::proc_available() && WPD_Smb::cli_binary() !== '') { $drivers[] = 'cli'; } else { echo "SKIP cli: нет программы smbclient или proc_open\n"; }

foreach ($drivers as $d) {
    $cfg = ['driver' => $d, 'path' => "\\\\$host\\$share\\$dir", 'user' => $user, 'pass' => $pass, 'mount' => $local . '/' . $dir, 'timeout' => 20];
    $c = new WPD_Smb($cfg);
    $r = $c->test();
    t("$d: test ok", $r['ok'] && $r['driver'] === $d && $r['dirs'] === 1 && $r['files'] === 6, json_encode($r, JSON_UNESCAPED_UNICODE));
    $ls = $c->listdir('');
    $names = array_column((array) $ls, 'name');
    sort($names);
    t("$d: listdir names (cyrillic, spaces, %, #)", in_array('Положение об олимпиаде.pdf', $names, true) && in_array('Скидка 100% #1.pdf', $names, true) && in_array('Итоги 2026', $names, true), implode(' | ', $names));
    $big = null;
    foreach ((array) $ls as $f) { if ($f['name'] === 'Большой файл.zip') { $big = $f; } }
    t("$d: size and mtime", $big && $big['size'] === 300000 && $big['mtime'] > 1700000000 && !$big['dir']);
    $s = $c->scan('', ['depth' => 0, 'exts' => ['pdf', 'docx', 'zip']]);
    $rels = array_column((array) $s, 'rel');
    t("$d: scan flat skips temp/hidden/other ext", $rels === ['Большой файл.zip', 'Положение об олимпиаде.pdf', 'Скидка 100% #1.pdf'], implode(' | ', $rels));
    $s = $c->scan('', ['depth' => 5, 'exts' => ['pdf', 'docx']]);
    $rels = array_column((array) $s, 'rel');
    t("$d: scan recursive", in_array('Итоги 2026/Протокол жюри.docx', $rels, true) && in_array('Итоги 2026/Глубже/Глубокий.pdf', $rels, true), implode(' | ', $rels));
    $s = $c->scan('Итоги 2026', ['depth' => 0, 'exts' => ['docx']]);
    t("$d: scan subfolder", array_column((array) $s, 'rel') === ['Итоги 2026/Протокол жюри.docx'] && $s[0]['dir'] === '', json_encode($s, JSON_UNESCAPED_UNICODE));
    $tmp = tempnam(sys_get_temp_dir(), 'wpd');
    t("$d: fetch", $c->fetch('Итоги 2026/Протокол жюри.docx', $tmp) && file_get_contents($tmp) === 'DOCX-DATA');
    t("$d: fetch % and # in name", $c->fetch('Скидка 100% #1.pdf', $tmp) && file_get_contents($tmp) === 'pct');
    @unlink($tmp);
    t("$d: fetch over limit refused, nothing left", !$c->fetch('Большой файл.zip', $tmp, 100000) && !file_exists($tmp) && $c->error() !== '');
    t("$d: fetch big ok", $c->fetch('Большой файл.zip', $tmp) && filesize($tmp) === 300000);
    @unlink($tmp);
    t("$d: fetch missing", !$c->fetch('нет.pdf', $tmp) && $c->error() !== '');
    t("$d: .. cannot escape", $c->listdir('../../..') === null || array_column((array) $c->listdir('../../..'), 'name') == array_column((array) $c->listdir(''), 'name'));
    if ($d !== 'mount') {
        $bad = new WPD_Smb(['pass' => 'wrong'] + $cfg);
        $r = $bad->test();
        t("$d: wrong password message", !$r['ok'] && $r['message'] !== '', $r['message']);
        $bad = new WPD_Smb(['path' => "\\\\$host\\nope"] + $cfg);
        t("$d: unknown share message", !$bad->test()['ok']);
        $bad = new WPD_Smb(['path' => "\\\\nohost.invalid\\$share"] + $cfg);
        $t0 = microtime(true);
        $r = $bad->test();
        t("$d: unknown host fails fast", !$r['ok'] && microtime(true) - $t0 < 25, $r['message']);
        $bad = new WPD_Smb(['path' => "\\\\$host\\$share\\$dir\\Положение об олимпиаде.pdf"] + $cfg);
        t("$d: file as folder", !$bad->test()['ok']);
    }
}
$a = new WPD_Smb(['driver' => 'auto', 'path' => "\\\\$host\\$share", 'user' => $user, 'pass' => $pass]);
t('auto prefers ext, then cli', $a->driver() === (WPD_Smb::ext_available() ? 'ext' : (WPD_Smb::cli_binary() !== '' ? 'cli' : '')));
echo "\nTOTAL: " . ($pass_n + $fail_n) . ", FAILED: $fail_n\n";
