#!/bin/bash
# Фаза V (2.8.0): HTTP-проверки сетевой папки — раздел на вкладке «Документы», сохранение подключения через options.php
# (пароль шифруется и не выводится, расписание), REST с cookie-авторизацией (проверка с несохранёнными полями, просмотр,
# импорт), строки из папки на вкладке и их сохранение основной формой, отдача файлов по ссылке (заголовки, кеш, защита),
# панель страницы, данные блочного редактора. Помощник — wpd-v-helper.php.
B=${WPD_BASE:-http://127.0.0.1:9400}
PASS=0; FAIL=0
t() { if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
has() { grep -q -- "$2" "$1" && echo 1 || echo 0; }
hasnt() { grep -q -- "$2" "$1" && echo 0 || echo 1; }
js() { python3 -c "import json,sys;d=json.load(open(sys.argv[1]));exec('print('+sys.argv[2]+')')" "$1" "$2" 2>/dev/null; }
eq() { [ "$1" = "$2" ] && echo 1 || echo 0; }
inq() { case "$1" in *"$2"*) echo 1;; *) echo 0;; esac; }
# Значение скрытого поля внутри формы с маркером
fval() { python3 - "$1" "$2" "$3" <<'PY'
import re, sys
s = open(sys.argv[1], encoding='utf-8').read()
i = s.find(sys.argv[2])
s = s[i:] if i >= 0 else ''
m = re.search(r"name=['\"]" + re.escape(sys.argv[3]) + r"['\"] value=['\"]([^'\"]*)['\"]", s)
print(m.group(1) if m else '')
PY
}

curl -s --max-time 60 "$B/wpd-set.php?json=%7B%7D" > /dev/null
curl -sL --max-time 120 "$B/wpd-v-helper.php?setup=1" > v_setup.json
MOUNT=$(js v_setup.json 'd["mount"]')
t "helper setup" "$( [ -n "$MOUNT" ] && echo 1 || echo 0)"
rm -f cjV.txt
curl -s -c cjV.txt -b cjV.txt -o /dev/null --max-time 60 "$B/wp-login.php"
curl -s -c cjV.txt -b cjV.txt -o /dev/null --max-time 60 -d "log=admin&pwd=password&wp-submit=Log+In&redirect_to=/wp-admin/&testcookie=1" "$B/wp-login.php"
get() { curl -s -c cjV.txt -b cjV.txt --max-time 180 "$B$1" -o "$2"; }

# Раздел на вкладке
get "/wp-admin/admin.php?page=wpd-settings&tab=docs" v_tab.html
t "tab: netfolder section after main form" "$(python3 -c "
s=open('v_tab.html',encoding='utf-8').read();a=s.find('name=\"wpd_settings[_tab]\"');b=s.find('<h2>Сетевая папка (SMB)</h2>');print(1 if 0<a<b else 0)")"
t "tab: own form group" "$(has v_tab.html "value='wpd_netfolder_group'")"
t "tab: fields" "$(eq "$(grep -oE 'name="wpd_netfolder\[[a-z_]+\]"' v_tab.html | sort -u | wc -l | tr -d ' ')" 18)"
t "tab: script enqueued" "$(has v_tab.html 'assets/js/wpd-netfolder.js')"
t "tab: api-fetch for buttons" "$(has v_tab.html 'createNonceMiddleware')"
t "tab: env line" "$(has v_tab.html 'На сервере сайта: PHP-расширение smbclient')"
t "tab: docs row template has src/nf" "$(eq "$(grep -oE 'wpd_settings\[docs\]\[__i__\]\[(src|nf)\]' v_tab.html | sort -u | wc -l | tr -d ' ')" 2)"
get "/wp-admin/admin.php?page=wpd-settings&tab=faq" v_tab_faq.html
t "other tabs: no section, no script" "$( [ "$(hasnt v_tab_faq.html 'Сетевая папка (SMB)')" = 1 ] && [ "$(hasnt v_tab_faq.html 'wpd-netfolder.js')" = 1 ] && echo 1 || echo 0)"

# Сохранение подключения
N=$(fval v_tab.html 'data-wpd-nf-form' _wpnonce)
LOC=$(curl -s -c cjV.txt -b cjV.txt -o /dev/null --max-time 120 -w "%{redirect_url}" \
  --data-urlencode "option_page=wpd_netfolder_group" --data-urlencode "action=update" --data-urlencode "_wpnonce=$N" \
  --data-urlencode "_wp_http_referer=/wp-admin/admin.php?page=wpd-settings&tab=docs" \
  --data-urlencode "wpd_netfolder[enabled]=1" --data-urlencode "wpd_netfolder[path]=\\\\fileserver\\olymp" \
  --data-urlencode "wpd_netfolder[user]=olymp" --data-urlencode "wpd_netfolder[pass]=Пароль-V 1" --data-urlencode "wpd_netfolder[driver]=mount" \
  --data-urlencode "wpd_netfolder[mount]=$MOUNT" --data-urlencode "wpd_netfolder[mode]=copy" --data-urlencode "wpd_netfolder[sub]=Документы" \
  --data-urlencode "wpd_netfolder[exts]=pdf, docx" --data-urlencode "wpd_netfolder[max_mb]=5" --data-urlencode "wpd_netfolder[desc]=1" \
  --data-urlencode "wpd_netfolder[gone]=hide" --data-urlencode "wpd_netfolder[schedule]=twicedaily" "$B/wp-admin/options.php")
t "save: redirect back to docs tab" "$( [ "$(inq "$LOC" 'tab=docs')" = 1 ] && [ "$(inq "$LOC" 'settings-updated=true')" = 1 ] && echo 1 || echo 0)"
curl -sL --max-time 60 "$B/wpd-v-helper.php?state=1" > v_state1.json
t "save: values stored" "$(js v_state1.json '1 if d["settings"]["enabled"]==1 and d["settings"]["driver"]=="mount" and d["settings"]["sub"]=="Документы" and d["settings"]["max_mb"]==5 else 0')"
t "save: password encrypted and correct" "$(js v_state1.json '1 if d["settings"]["pass_plain_ok"] else 0')"
t "save: schedule set" "$(js v_state1.json '1 if d["next"]=="twicedaily" else 0')"
get "$(echo "$LOC" | sed "s#^https\?://[^/]*##")" v_tab2.html
t "tab: password not printed" "$( [ "$(hasnt v_tab2.html 'Пароль-V')" = 1 ] && [ "$(has v_tab2.html '(сохранён)')" = 1 ] && [ "$(has v_tab2.html 'name="wpd_netfolder\[pass_clear\]"')" = 1 ] && echo 1 || echo 0)"
t "tab: saved values shown" "$( [ "$(has v_tab2.html 'name="wpd_netfolder\[sub\]" value="Документы"')" = 1 ] && grep -qE 'name="wpd_netfolder\[enabled\]" value="1"\s*checked' v_tab2.html && echo 1 || echo 0)"
t "tab: settings saved notice" "$(has v_tab2.html 'settings_updated')"

# REST
NONCE=$(grep -oE 'createNonceMiddleware\( "[a-f0-9]+" \)' v_tab2.html | grep -oE '[a-f0-9]{10}' | head -1)
t "rest nonce found" "$( [ -n "$NONCE" ] && echo 1 || echo 0)"
rest() { curl -s -c cjV.txt -b cjV.txt --max-time 180 -H "X-WP-Nonce: $NONCE" -H "Content-Type: application/json" -X POST -d "$2" -o "$3" -w "%{http_code}" "$B/?rest_route=/wpd/v1/netfolder/$1"; }
C=$(rest test "{\"form\":{\"driver\":\"mount\",\"mount\":\"$MOUNT\",\"sub\":\"Ссылки\"}}" v_rt.json)
t "rest test: unsaved form values" "$( [ "$C" = 200 ] && [ "$(js v_rt.json '1 if d["ok"] and d["files"]==3 and d["driver"]=="mount" else 0')" = 1 ] && echo 1 || echo 0)"
C=$(rest test "{\"form\":{\"driver\":\"mount\",\"mount\":\"$MOUNT/нет\"}}" v_rt2.json)
t "rest test: failure message" "$(js v_rt2.json '1 if not d["ok"] and len(d["message"])>5 else 0')"
C=$(rest scan '{"target":"settings"}' v_scan.json)
t "rest scan" "$( [ "$C" = 200 ] && [ "$(js v_scan.json '1 if [f["status"] for f in d["files"]]==["new","new"] and d["files"][0]["size"].endswith("Б") else 0')" = 1 ] && echo 1 || echo 0)"
C=$(rest import '{"target":"settings","only":["Документы/Регламент.docx"]}' v_imp.json)
t "rest import only one" "$( [ "$C" = 200 ] && [ "$(js v_imp.json '1 if d["report"]["added"]==1 and d["message"]=="добавлено: 1." else 0')" = 1 ] && echo 1 || echo 0)"
C=$(rest import '{"target":"settings"}' v_imp2.json)
t "rest import rest" "$(js v_imp2.json '1 if d["report"]["added"]==1 and d["report"]["unchanged"]==1 else 0')"
C=$(curl -s -o /dev/null -w "%{http_code}" -X POST -H "Content-Type: application/json" -d '{"target":"settings"}' "$B/?rest_route=/wpd/v1/netfolder/import")
t "rest: anonymous 401" "$(eq "$C" 401)"
C=$(curl -s -c cjV.txt -b cjV.txt -o /dev/null -w "%{http_code}" -X POST -H "Content-Type: application/json" -d '{"target":"settings"}' "$B/?rest_route=/wpd/v1/netfolder/import")
t "rest: cookie without nonce rejected" "$( [ "$C" = 401 ] || [ "$C" = 403 ] && echo 1 || echo 0)"

# Строки на вкладке и сохранение основной формой
get "/wp-admin/admin.php?page=wpd-settings&tab=docs" v_tab3.html
t "tab: imported rows with note" "$( [ "$(grep -o 'Из сетевой папки: Документы/' v_tab3.html | wc -l | tr -d ' ')" = 2 ] && echo 1 || echo 0)"
t "tab: hidden src inputs" "$(has v_tab3.html 'name="wpd_settings\[docs\]\[0\]\[src\]" value="Документы/Регламент.docx"')"
t "tab: title from file name" "$(has v_tab3.html 'value="Положение об олимпиаде"')"
MN=$(fval v_tab3.html "value='wpd_settings_group'" _wpnonce)
curl -s -c cjV.txt -b cjV.txt -o /dev/null --max-time 120 \
  --data-urlencode "option_page=wpd_settings_group" --data-urlencode "action=update" --data-urlencode "_wpnonce=$MN" \
  --data-urlencode "_wp_http_referer=/wp-admin/admin.php?page=wpd-settings&tab=docs" --data-urlencode "wpd_settings[_tab]=docs" \
  --data-urlencode "wpd_settings[docs_title]=Документы V" --data-urlencode "wpd_settings[docs_show_type]=1" --data-urlencode "wpd_settings[docs][_]=" \
  --data-urlencode "wpd_settings[docs][0][title]=Регламент (правка)" --data-urlencode "wpd_settings[docs][0][url]=https://example.org/reg.docx" \
  --data-urlencode "wpd_settings[docs][0][src]=Документы/Регламент.docx" --data-urlencode "wpd_settings[docs][0][nf]=" \
  --data-urlencode "wpd_settings[docs][1][title]=Ручная" --data-urlencode "wpd_settings[docs][1][url]=https://example.org/m.pdf" \
  "$B/wp-admin/options.php"
curl -sL --max-time 60 "$B/wpd-v-helper.php?state=1" > v_state2.json
t "main form keeps src" "$(js v_state2.json '1 if d["docs"][0]["src"]=="Документы/Регламент.docx" and d["docs"][0]["title"]=="Регламент (правка)" and d["docs"][1]["src"]=="" else 0')"
C=$(rest import '{"target":"settings"}' v_imp3.json)
curl -sL --max-time 60 "$B/wpd-v-helper.php?state=1" > v_state3.json
t "reimport after edit: edits kept, deleted row comes back" "$(js v_state3.json '1 if d["docs"][0]["title"]=="Регламент (правка)" and d["docs"][0]["url"]=="https://example.org/reg.docx" and len(d["docs"])==3 and d["docs"][2]["src"]=="Документы/Положение об олимпиаде.pdf" else 0')"

# Ссылки на файлы
curl -sL --max-time 120 "$B/wpd-v-helper.php?link=1" > v_link.json
U1=$(js v_link.json 'd["urls"]["Ссылки/Приказ №1.pdf"]')
U2=$(js v_link.json 'd["urls"].get("Ссылки/Форма <заявки>.docx","")')
U3=$(js v_link.json 'd["urls"]["Ссылки/page.html"]')
t "link urls" "$( [ "$(inq "$U1" '?wpd_nf=')" = 1 ] && [ -n "$U2" ] && [ -n "$U3" ] && echo 1 || echo 0)"
U1L=$(echo "$U1" | sed "s#^https\?://[^/]*#$B#")
U2L=$(echo "$U2" | sed "s#^https\?://[^/]*#$B#")
U3L=$(echo "$U3" | sed "s#^https\?://[^/]*#$B#")
curl -s --max-time 120 -D v_h1.txt -o v_f1.bin "$U1L"
t "link pdf: 200 + body" "$( grep -q '^HTTP/[0-9.]* 200' v_h1.txt && [ "$(cat v_f1.bin)" = "%PDF-1.4 V приказ" ] && echo 1 || echo 0)"
t "link pdf: type and inline utf-8 name" "$( grep -qi '^content-type: application/pdf' v_h1.txt && grep -qi "^content-disposition: inline; filename=\"[^\"]*\"; filename\*=UTF-8''%D0%9F%D1%80%D0%B8%D0%BA%D0%B0%D0%B7" v_h1.txt && echo 1 || echo 0)"
t "link pdf: nosniff, noindex" "$( grep -qi '^x-content-type-options: nosniff' v_h1.txt && grep -qi '^x-robots-tag: noindex' v_h1.txt && echo 1 || echo 0)"
curl -s --max-time 120 -D v_h2.txt -o v_f2.bin "$U2L"
t "link docx: attachment" "$( grep -qi '^content-disposition: attachment' v_h2.txt && [ "$(cat v_f2.bin)" = "DOCX V форма" ] && echo 1 || echo 0)"
curl -s --max-time 120 -D v_h3.txt -o v_f3.bin "$U3L"
t "link html: forced download, not text/html inline" "$( grep -qi '^content-disposition: attachment' v_h3.txt && echo 1 || echo 0)"
curl -s --max-time 60 -D v_h4.txt -o v_f4.bin "$B/?wpd_nf=000000000000000000000000"
t "unknown key: 404 text" "$( grep -q '^HTTP/[0-9.]* 404' v_h4.txt && grep -q 'Документ не найден' v_f4.bin && echo 1 || echo 0)"
curl -s --max-time 60 -D v_h5.txt -o /dev/null "$B/?wpd_nf=../../wp-config"
t "bad key: 404" "$(grep -q '^HTTP/[0-9.]* 404' v_h5.txt && echo 1 || echo 0)"

# Панель страницы и блочный редактор
curl -sL --max-time 120 "$B/wpd-v-helper.php?page=1" > v_page.json
ID=$(js v_page.json 'd["id"]')
get "/wp-admin/post.php?post=$ID&action=edit" v_edit.html
t "page panel: netfolder box" "$( [ "$(has v_edit.html 'data-wpd-nf-page')" = 1 ] && [ "$(has v_edit.html 'name="wpd_page\[docs_nf\]" value="Документы"')" = 1 ] && echo 1 || echo 0)"
t "page panel: script" "$(has v_edit.html 'assets/js/wpd-netfolder.js')"
t "editor data: netfolder" "$(grep -q '"netfolder":{"enabled":true' v_edit.html && echo 1 || echo 0)"
t "editor: block attrs nfFolder/nfAuto" "$( grep -q '"nfFolder":{"type":"string"' v_edit.html && grep -q '"nfAuto":{"type":"boolean"' v_edit.html && echo 1 || echo 0)"
C=$(rest import "{\"target\":\"page\",\"post_id\":$ID,\"auto\":1}" v_pimp.json)
t "rest page import: html rows" "$( [ "$C" = 200 ] && [ "$(js v_pimp.json '1 if "data-wpd-field=\"src\"" in d["html"] and len(d["rows"])==3 else 0')" = 1 ] && echo 1 || echo 0)"
curl -sL --max-time 60 "$B/wpd-v-helper.php?state=1" > v_state4.json
t "page saved: custom, sub, auto, manual row kept" "$(js v_state4.json '1 if d["page_nf"]==["Документы",1,"custom"] and d["page_docs"][0]["title"]=="Ручной" and len(d["page_docs"])==3 else 0')"
get "/wp-admin/post.php?post=$ID&action=edit" v_edit2.html
t "page panel: auto checked, rows with note" "$( grep -qE 'name="wpd_page\[docs_nf_auto\]" value="1"\s*checked' v_edit2.html && [ "$(grep -o 'Из сетевой папки: Документы/' v_edit2.html | wc -l | tr -d ' ')" = 2 ] && echo 1 || echo 0)"
curl -sL --max-time 120 "$(js v_page.json '1' >/dev/null; echo "$B/?page_id=$ID")" -o v_front.html
t "front: page docs list shows imported" "$(has v_front.html 'Положение об олимпиаде')"
t "no PHP warnings" "$(cat v_tab*.html v_edit*.html v_front.html | grep -qE '<b>(Warning|Notice|Deprecated|Fatal error)</b>' && echo 0 || echo 1)"

# Уборка
curl -sL --max-time 60 "$B/wpd-v-helper.php?cleanup=1" > v_clean.json
t "cleanup" "$(js v_clean.json '1 if d["cleaned"] else 0')"
curl -s --max-time 60 "$B/wpd-set.php?json=%7B%7D" > /dev/null
echo; echo "TOTAL: $((PASS+FAIL)), FAILED: $FAIL"
