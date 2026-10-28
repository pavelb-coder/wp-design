#!/bin/bash
# Проверка клиента SMB на настоящем Samba. Нужны: smbd с ресурсом, php-cli; по возможности php-smbclient и smbclient.
# Параметры: SMB_HOST, SMB_SHARE, SMB_USER, SMB_PASS, SMB_LOCAL (каталог ресурса на этой машине, для файлов и драйвера mount).
# По умолчанию — ресурс //127.0.0.1/olymp → /tmp/smbshare, пользователь wpdsmb (как в окружении разработки).
L=${SMB_LOCAL:-/tmp/smbshare}
D="$L/Документы олимпиады"
sudo -n rm -rf "$D" 2>/dev/null || rm -rf "$D"
sudo -n mkdir -p "$D/Итоги 2026/Глубже" || mkdir -p "$D/Итоги 2026/Глубже"
w() { printf '%s' "$2" | sudo -n tee "$D/$1" > /dev/null 2>&1 || printf '%s' "$2" > "$D/$1"; }
w "Положение об олимпиаде.pdf" "%PDF-1.4 test"
w "Итоги 2026/Протокол жюри.docx" "DOCX-DATA"
w "Итоги 2026/Глубже/Глубокий.pdf" "deep"
w '~$Временный.docx' "x"
w ".скрытый.pdf" "x"
w "фото.jpg" "img"
w "Скидка 100% #1.pdf" "pct"
head -c 300000 /dev/zero | sudo -n tee "$D/Большой файл.zip" > /dev/null 2>&1 || head -c 300000 /dev/zero > "$D/Большой файл.zip"
sudo -n chmod -R a+rX "$L" 2>/dev/null
php "$(dirname "$0")/test-smb.php"
