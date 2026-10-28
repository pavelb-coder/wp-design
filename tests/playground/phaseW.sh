#!/bin/bash
# Фаза W (2.9.0): HTTP-проверки шрифтов и логотипов — разделы и поля на вкладках «Оформление», «Главный блок», «Партнёры»,
# настоящее сохранение через options.php (файлы шрифтов, ссылки сервисов, шрифты элементов, логотип, партнёры), страница
# сайта (таблицы стилей, @font-face, классы шрифтов, логотип рядом с заголовком, партнёры из панели и блок-лента, шрифты
# блока), загрузка через async-upload.php: SVG администратора очищается, SVG с сущностями и поддельный шрифт отклоняются,
# WOFF2 принимается, редактору SVG запрещён; экран редактирования (данные блоков, панель страницы). Помощник — wpd-w-helper.php.
B=${WPD_BASE:-http://127.0.0.1:9400}
PASS=0; FAIL=0
t() { if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
has() { grep -q -- "$2" "$1" && echo 1 || echo 0; }
hasnt() { grep -q -- "$2" "$1" && echo 0 || echo 1; }
eq() { [ "$1" = "$2" ] && echo 1 || echo 0; }
inq() { case "$1" in *"$2"*) echo 1;; *) echo 0;; esac; }
js() { python3 -c "import json,sys;d=json.load(open(sys.argv[1]));exec('print('+sys.argv[2]+')')" "$1" "$2" 2>/dev/null; }
val() { python3 - "$1" "$2" <<'PY'
import re, sys
s = open(sys.argv[1], encoding='utf-8').read()
m = re.search(r'name="' + re.escape(sys.argv[2]) + r'" value="([^"]*)"', s)
print(m.group(1) if m else '')
PY
}
edata() { python3 - "$1" "$2" <<'PY'
import json, sys
s = open(sys.argv[1], encoding='utf-8').read()
i = s.rfind('"' + sys.argv[2] + '":')
if i < 0:
    print('{}'); sys.exit()
obj, _ = json.JSONDecoder().raw_decode(s[i + len(sys.argv[2]) + 3:])
print(json.dumps(obj, ensure_ascii=False, separators=(',', ':')))
PY
}
fields() { grep -oE "name=[\"']wpd_settings\[[a-z0-9_]+\]" "$1" | grep -v '\[_tab\]' | sort -u | wc -l | tr -d ' '; }

curl -s --max-time 60 "$B/wpd-set.php?json=%7B%7D" > /dev/null
curl -sL --max-time 120 "$B/wpd-w-helper.php?setup=1" > w_setup.json
ID=$(js w_setup.json 'd["id"]'); URL=$(js w_setup.json 'd["url"]')
t "helper setup" "$( [ -n "$ID" ] && [ -n "$URL" ] && echo 1 || echo 0)"
login() { rm -f "$1"; curl -s -c "$1" -b "$1" -o /dev/null --max-time 60 "$B/wp-login.php"
  curl -s -c "$1" -b "$1" -o /dev/null --max-time 60 --data-urlencode "log=$2" --data-urlencode "pwd=$3" -d "wp-submit=Log+In&redirect_to=/wp-admin/&testcookie=1" "$B/wp-login.php"; }
login cjW.txt admin password
get() { curl -s -c cjW.txt -b cjW.txt --max-time 180 "$B$1" -o "$2"; }
nowarn() { grep -qE '<b>(Warning|Notice|Deprecated|Fatal error)</b>' "$1" && echo 0 || echo 1; }

# Вкладки настроек
get "/wp-admin/admin.php?page=wpd-settings&tab=design" w_design.html
t "design: fonts section" "$(has w_design.html '<h2>Свои шрифты</h2>')"
t "design: 35 fields" "$(eq "$(fields w_design.html)" 35)"
t "design: font row template (4 sub-fields)" "$(eq "$(grep -oE 'wpd_settings\[font_files\]\[__i__\]\[(family|url|weight|style)\]' w_design.html | sort -u | wc -l | tr -d ' ')" 4)"
t "design: link row template (2 sub-fields)" "$(eq "$(grep -oE 'wpd_settings\[font_links\]\[__i__\]\[(url|families)\]' w_design.html | sort -u | wc -l | tr -d ' ')" 2)"
t "design: font inputs use datalist" "$(eq "$(grep -oE 'name="wpd_settings\[(font|font_heading|font_hero|font_button|font_numbers)\]"[^>]*list="wpd-font-list"' w_design.html | wc -l | tr -d ' ')" 5)"
t "design: one datalist" "$(eq "$(grep -o '<datalist id="wpd-font-list">' w_design.html | wc -l | tr -d ' ')" 1)"
t "design: file picker" "$(has w_design.html 'data-wpd-s-kind="file"')"
t "design: no PHP warnings" "$(nowarn w_design.html)"
get "/wp-admin/admin.php?page=wpd-settings&tab=hero" w_hero.html
t "hero: logo section with 6 fields" "$( [ "$(has w_hero.html '<h2>Логотип</h2>')" = 1 ] && [ "$(grep -oE 'name="wpd_settings\[hero_logo(_dark|_pos|_height|_alt|_url)?\]"' w_hero.html | sort -u | wc -l | tr -d ' ')" = 6 ] && echo 1 || echo 0)"
get "/wp-admin/admin.php?page=wpd-settings&tab=partners" w_partners.html
t "partners: tab active, 9 fields" "$( [ "$(python3 -c "import re;s=open('w_partners.html',encoding='utf-8').read();print(1 if re.search(r'tab=partners\"\s*class=\"nav-tab nav-tab-active\"',s) else 0)")" = 1 ] && [ "$(fields w_partners.html)" = 9 ] && echo 1 || echo 0)"
t "partners: row template (5 sub-fields)" "$(eq "$(grep -oE 'wpd_settings\[partners\]\[__i__\]\[(logo|name|url|new_tab|hidden)\]' w_partners.html | sort -u | wc -l | tr -d ' ')" 5)"
get "/wp-admin/admin.php?page=wpd-settings&tab=contacts" w_contacts.html
t "contacts: logo picker and height" "$( [ "$(grep -qE 'data-wpd-s-media>[^<]*<img[^>]*>[^<]*<input type="url" id="wpd_contact_logo_url"' w_contacts.html && echo 1 || echo 0)" = 1 ] && [ "$(has w_contacts.html 'name="wpd_settings\[contact_logo_height\]"')" = 1 ] && echo 1 || echo 0)"

# Сохранение через options.php
save() { local tab=$1; shift
  local f="w_$tab.html"; local N; N=$(val "$f" _wpnonce)
  curl -s -c cjW.txt -b cjW.txt -o /dev/null --max-time 120 -w "%{redirect_url}" \
    --data-urlencode "option_page=wpd_settings_group" --data-urlencode "action=update" --data-urlencode "_wpnonce=$N" \
    --data-urlencode "_wp_http_referer=/wp-admin/admin.php?page=wpd-settings&tab=$tab" --data-urlencode "wpd_settings[_tab]=$tab" "$@" "$B/wp-admin/options.php"; }
FONT_URL="$B/wp-includes/fonts/dashicons.woff2"
LOC=$(save design --data-urlencode "wpd_settings[font_files][_]=" \
  --data-urlencode "wpd_settings[font_files][0][family]=Wpd Test" --data-urlencode "wpd_settings[font_files][0][url]=$FONT_URL" \
  --data-urlencode "wpd_settings[font_files][0][weight]=400" --data-urlencode "wpd_settings[font_files][0][style]=normal" \
  --data-urlencode "wpd_settings[font_files][1][family]=Wpd Test" --data-urlencode "wpd_settings[font_files][1][url]=$B/wp-includes/fonts/dashicons.ttf" \
  --data-urlencode "wpd_settings[font_files][1][weight]=700" --data-urlencode "wpd_settings[font_files][1][style]=italic" \
  --data-urlencode "wpd_settings[font_links][_]=" --data-urlencode "wpd_settings[font_links][0][url]=https://fonts.bunny.net/css?family=inter:400" \
  --data-urlencode "wpd_settings[font_links][0][families]=Inter" --data-urlencode "wpd_settings[font_links][1][url]=http://insecure.example/x.css" \
  --data-urlencode "wpd_settings[font_links][1][families]=" \
  --data-urlencode "wpd_settings[font]=Arial, sans-serif" --data-urlencode "wpd_settings[font_hero]=\"Wpd Test\", sans-serif" \
  --data-urlencode "wpd_settings[font_button]=Georgia, serif" --data-urlencode "wpd_settings[font_numbers]=")
t "save design: redirect back" "$(inq "$LOC" 'tab=design')"
get "/wp-admin/admin.php?page=wpd-settings&tab=design" w_design2.html
t "saved: font rows" "$( [ "$(has w_design2.html 'name="wpd_settings\[font_files\]\[1\]\[family\]" value="Wpd Test"')" = 1 ] && [ "$(grep -qE '<option value="italic" selected' w_design2.html && echo 1 || echo 0)" = 1 ] && echo 1 || echo 0)"
t "saved: http link dropped" "$( [ "$(has w_design2.html 'fonts.bunny.net')" = 1 ] && [ "$(hasnt w_design2.html 'insecure.example')" = 1 ] && echo 1 || echo 0)"
t "saved: datalist suggests own font" "$(has w_design2.html '<option value="&quot;Wpd Test&quot;, sans-serif">')"
LOC=$(save hero --data-urlencode "wpd_settings[hero_title]=Олимпиада W" --data-urlencode "wpd_settings[hero_logo]=$B/hero19.svg" \
  --data-urlencode "wpd_settings[hero_logo_pos]=beside" --data-urlencode "wpd_settings[hero_logo_height]=72" --data-urlencode "wpd_settings[hero_logo_alt]=Лого W" \
  --data-urlencode "wpd_settings[hero_style]=light")
t "save hero: redirect back" "$(inq "$LOC" 'tab=hero')"
LOC=$(save partners --data-urlencode "wpd_settings[partners_title]=Наши партнёры" --data-urlencode "wpd_settings[partners][_]=" \
  --data-urlencode "wpd_settings[partners][0][logo]=$B/hero19.svg" --data-urlencode "wpd_settings[partners][0][name]=Фонд W" \
  --data-urlencode "wpd_settings[partners][0][url]=https://fond.example/" --data-urlencode "wpd_settings[partners][1][name]=Только имя W" \
  --data-urlencode "wpd_settings[partners_layout]=grid" --data-urlencode "wpd_settings[partners_columns]=4" --data-urlencode "wpd_settings[partners_height]=80" \
  --data-urlencode "wpd_settings[partners_gray]=1" --data-urlencode "wpd_settings[partners_names]=1")
t "save partners: redirect back" "$(inq "$LOC" 'tab=partners')"
get "/wp-admin/admin.php?page=wpd-settings&tab=partners" w_partners2.html
t "saved: partners rows, cards off (unposted checkbox)" "$( [ "$(has w_partners2.html 'value="Фонд W"')" = 1 ] && [ "$(grep -qE 'name="wpd_settings\[partners_cards\]" value="1" *checked' w_partners2.html && echo 0 || echo 1)" = 1 ] && echo 1 || echo 0)"

# Страница сайта
curl -sL --max-time 180 "$URL" -o w_front.html
t "front: service stylesheet" "$(grep -qE "<link rel='stylesheet' id='wpd-font-link-0-css' href='https://fonts.bunny.net/css\?family=inter(:|%3A)400" w_front.html && echo 1 || echo 0)"
t "front: @font-face for both files" "$( [ "$(grep -o '@font-face{font-family:"Wpd Test"' w_front.html | wc -l | tr -d ' ')" = 2 ] && [ "$(has w_front.html 'dashicons.ttf") format("truetype");font-weight:700;font-style:italic;font-display:swap;')" = 1 ] && echo 1 || echo 0)"
t "front: element font classes and vars" "$( [ "$(grep -qE 'class="wpd-page [^"]*wpd-ff-hero wpd-ff-btn' w_front.html && echo 1 || echo 0)" = 1 ] && [ "$(has w_front.html '--wpd-font-hero:&quot;Wpd Test&quot;, sans-serif;')" = 1 ] && [ "$(has w_front.html 'wpd-ff-num')" = 1 ] && echo 1 || echo 0)"
t "front: hero logo beside title" "$(grep -qE '<div class="wpd-hero__heading"><div class="wpd-hero__logo"><img class="wpd-hero__logo-img" src="[^"]*hero19.svg" alt="Лого W"></div><h1 class="wpd-hero__title">Олимпиада W</h1>' w_front.html && echo 1 || echo 0)"
t "front: hero logo height" "$(has w_front.html '--wpd-hero-logo-h:72px')"
t "front: partners from settings (grid)" "$( [ "$(grep -qE 'class="wpd-partners wpd-partners--grid wpd-partners--gray wpd-partners--names wpd-partners--cols" style="--wpd-pt-h:80px;--wpd-pt-cols:4;"' w_front.html && echo 1 || echo 0)" = 1 ] && [ "$(has w_front.html '<span class="wpd-partners__text">Только имя W</span>')" = 1 ] && echo 1 || echo 0)"
t "front: partners block strip with clones" "$( [ "$(has w_front.html '>Лента W</h2>')" = 1 ] && [ "$(has w_front.html 'wpd-partners--marquee')" = 1 ] && [ "$(grep -o 'alt="Альфа W"' w_front.html | wc -l | tr -d ' ')" = 1 ] && [ "$(hasnt w_front.html 'Скрытый W')" = 1 ] && echo 1 || echo 0)"
t "front: stats block fonts" "$( [ "$(has w_front.html '--wpd-font:Georgia, serif;')" = 1 ] && [ "$(has w_front.html '--wpd-font-num:&quot;Wpd Test&quot;, monospace')" = 1 ] && echo 1 || echo 0)"
t "front: no PHP warnings" "$(nowarn w_front.html)"

# Загрузка в медиатеку
mk() { printf '%s' "$2" > "/tmp/pg/$1"; }
mk wpdw-logo.svg '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 40" onload="alert(1)"><script>alert(2)</script><rect width="120" height="40" fill="#06c"/><a href="javascript:alert(3)"><text>x</text></a></svg>'
mk wpdw-evil.svg '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg"><text>&x;</text></svg>'
mk wpdw-fake.woff2 '<?php echo 1; ?>'
cp /tmp/pg/wordpress/wp-includes/fonts/dashicons.woff2 /tmp/pg/wpdw-font.woff2
upload() { # cookie file, local file
  local nonce; nonce=$(curl -s -c "$1" -b "$1" --max-time 120 "$B/wp-admin/media-new.php" | grep -o '"_wpnonce":"[a-z0-9]*"' | head -1 | sed 's/.*:"//;s/"$//')
  curl -s -c "$1" -b "$1" --max-time 180 -F "async-upload=@/tmp/pg/$2" -F "name=$2" -F "action=upload-attachment" -F "_wpnonce=$nonce" "$B/wp-admin/async-upload.php"; }
upload cjW.txt wpdw-logo.svg > w_up1.json
t "upload svg: success, svg mime" "$( [ "$(js w_up1.json 'd["success"]')" = True ] && [ "$(js w_up1.json 'd["data"]["mime"]')" = image/svg+xml ] && echo 1 || echo 0)"
SVGURL=$(js w_up1.json 'd["data"]["url"]')
curl -s --max-time 60 "$SVGURL" -o w_up1.svg
t "upload svg: served file sanitized" "$( [ "$(has w_up1.svg '<rect')" = 1 ] && [ "$(hasnt w_up1.svg 'script')" = 1 ] && [ "$(hasnt w_up1.svg 'onload')" = 1 ] && [ "$(hasnt w_up1.svg 'javascript')" = 1 ] && echo 1 || echo 0)"
t "upload svg: dimensions in library" "$( [ "$(js w_up1.json 'd["data"]["width"]')" = 120 ] && [ "$(js w_up1.json 'd["data"]["sizes"]["full"]["height"]')" = 40 ] && echo 1 || echo 0)"
upload cjW.txt wpdw-evil.svg > w_up2.json
t "upload svg with entities: rejected" "$( [ "$(js w_up2.json 'd["success"]')" = False ] && [ "$(inq "$(cat w_up2.json)" 'SVG')" = 1 ] && echo 1 || echo 0)"
upload cjW.txt wpdw-font.woff2 > w_up3.json
t "upload woff2: accepted" "$( [ "$(js w_up3.json 'd["success"]')" = True ] && [ "$(js w_up3.json 'd["data"]["mime"]')" = font/woff2 ] && echo 1 || echo 0)"
upload cjW.txt wpdw-fake.woff2 > w_up4.json
t "upload fake woff2: rejected" "$(eq "$(js w_up4.json 'd["success"]')" False)"
login cjW2.txt wpdw_ed Wpdw-pass-123
upload cjW2.txt wpdw-logo.svg > w_up5.json
t "editor: svg rejected" "$(eq "$(js w_up5.json 'd["success"]')" False)"
upload cjW2.txt wpdw-font.woff2 > w_up6.json
t "editor: font rejected" "$(eq "$(js w_up6.json 'd["success"]')" False)"
rm -f /tmp/pg/wpdw-*

# Экран редактирования
get "/wp-admin/post.php?post=$ID&action=edit" w_edit.html
t "edit: partners block data" "$(inq "$(edata w_edit.html 'wpd\/partners')" '{"title":"Олимпиада: логотипы партнёров"')"
t "edit: font attrs and list" "$( [ "$(inq "$(edata w_edit.html fontAttrs)" '"hero":{"fontText":{"label":"Основной шрифт"')" = 1 ] && [ "$(inq "$(edata w_edit.html fontList)" '"\"Wpd Test\", sans-serif"')" = 1 ] && echo 1 || echo 0)"
t "edit: panel 13 order rows" "$(eq "$(grep -o 'name="wpd_page\[order\]\[\]"' w_edit.html | wc -l | tr -d ' ')" 13)"
t "edit: panel partners group and hero logo" "$( [ "$(has w_edit.html 'id="wpd_pe_ov_partners_layout"')" = 1 ] && [ "$(has w_edit.html 'id="wpd_pe_ov_hero_logo_dark"')" = 1 ] && [ "$(has w_edit.html 'id="wpd_pe_ov_font_hero"')" = 1 ] && echo 1 || echo 0)"
t "edit: panel datalist" "$(eq "$(grep -o '<datalist id="wpd-font-list">' w_edit.html | wc -l | tr -d ' ')" 1)"
t "edit: no PHP warnings" "$(nowarn w_edit.html)"

curl -sL --max-time 120 "$B/wpd-w-helper.php?cleanup=1" > w_clean.json
curl -s --max-time 60 "$B/wpd-set.php?json=%7B%7D" > /dev/null
t "cleanup" "$(eq "$(js w_clean.json 'd["cleaned"]')" True)"
echo "PHASE W: PASS=$PASS FAIL=$FAIL"
