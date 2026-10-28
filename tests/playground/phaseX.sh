#!/bin/bash
# Фаза X (2.10.0): HTTP-проверки удаления сетевой папки — в админке нет ни вкладки/раздела, ни полей, ни скриптов, REST-маршруты
# сетевой папки не существуют, ссылка ?wpd_nf= отдаёт обычную страницу; данные, оставленные 2.8.0, удаляются первым же
# запросом к сайту (файлы медиатеки, строки из папки, атрибуты блока, настройки, расписание), администратор видит итог
# и может скрыть уведомление, на сайте остаются только ручные документы. Помощник — wpd-x-helper.php.
B=${WPD_BASE:-http://127.0.0.1:9400}
PASS=0; FAIL=0
t() { if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
has() { grep -q -- "$2" "$1" && echo 1 || echo 0; }
hasnt() { grep -q -- "$2" "$1" && echo 0 || echo 1; }
eq() { [ "$1" = "$2" ] && echo 1 || echo 0; }
inq() { case "$1" in *"$2"*) echo 1;; *) echo 0;; esac; }
js() { python3 -c "import json,sys;d=json.load(open(sys.argv[1]));exec('print('+sys.argv[2]+')')" "$1" "$2" 2>/dev/null; }
nowarn() { grep -qE '<b>(Warning|Notice|Deprecated|Fatal error)</b>' "$1" && echo 0 || echo 1; }

curl -s --max-time 60 "$B/wpd-set.php?json=%7B%7D" > /dev/null
curl -sL --max-time 120 "$B/wpd-x-helper.php?setup=1" > x_setup.json
ID=$(js x_setup.json 'd["id"]'); URL=$(js x_setup.json 'd["url"]')
t "helper setup" "$( [ -n "$ID" ] && [ -n "$URL" ] && echo 1 || echo 0)"

# Первый же запрос к сайту выполняет очистку
curl -sL --max-time 120 "$B/?p=$ID" -o x_first.html
curl -sL --max-time 60 "$B/wpd-x-helper.php?state=1" > x_state.json
t "cleanup done on first request" "$(eq "$(js x_state.json 'd["done"]["files"], d["done"]["rows"], d["done"]["posts"]')" "1 2 1")"
t "old options and cron removed" "$(eq "$(js x_state.json 'd["options"], d["cron"]')" "0 False")"
t "copied attachment deleted" "$(eq "$(js x_state.json 'd["atts"]')" 0)"
t "settings: manual doc only" "$(eq "$(js x_state.json 'd["docs"]')" "['Положение X']")"
t "block: folder item and nf attrs removed" "$( [ "$(inq "$(js x_state.json 'd["content"]')" 'Ручной X')" = 1 ] && [ "$(inq "$(js x_state.json 'd["content"]')" 'Папка X')" = 0 ] \
  && [ "$(inq "$(js x_state.json 'd["content"]')" 'nfFolder')" = 0 ] && [ "$(inq "$(js x_state.json 'd["content"]')" '"src"')" = 0 ] && echo 1 || echo 0)"
t "notice flag set" "$(eq "$(js x_state.json 'd["notice"]')" 1)"

# Сайт
curl -sL --max-time 120 "$URL" -o x_page.html
t "front: manual doc shown, folder doc gone" "$( [ "$(has x_page.html 'Ручной X')" = 1 ] && [ "$(hasnt x_page.html 'Папка X')" = 1 ] && [ "$(hasnt x_page.html 'wpdx-nf')" = 1 ] && echo 1 || echo 0)"
curl -sL --max-time 120 "$B/?wpd_nf=0123456789abcdef01234567" -o x_nf.html -w "%{http_code} %{content_type}" > x_nf.code
t "?wpd_nf= serves normal page" "$( [ "$(inq "$(cat x_nf.code)" '200 text/html')" = 1 ] && [ "$(has x_nf.html '</html>')" = 1 ] && echo 1 || echo 0)"
curl -s --max-time 60 "$B/wp-json/wpd/v1/netfolder/test" -o x_rest.json
t "REST netfolder/test: no route" "$(eq "$(js x_rest.json 'd["code"]')" rest_no_route)"
curl -s --max-time 60 "$B/wp-json/wpd/v1" -o x_ns.json
t "REST namespace: no netfolder routes" "$( [ -s x_ns.json ] && [ "$(hasnt x_ns.json 'netfolder')" = 1 ] && [ "$(has x_ns.json 'preview')" = 1 ] && echo 1 || echo 0)"

# Админка
login() { rm -f "$1"; curl -s -c "$1" -b "$1" -o /dev/null --max-time 60 "$B/wp-login.php"
  curl -s -c "$1" -b "$1" -o /dev/null --max-time 60 --data-urlencode "log=$2" --data-urlencode "pwd=$3" -d "wp-submit=Log+In&redirect_to=/wp-admin/&testcookie=1" "$B/wp-login.php"; }
login cjX.txt admin password
get() { curl -s -c cjX.txt -b cjX.txt --max-time 180 "$B$1" -o "$2"; }
get "/wp-admin/" x_dash.html
t "notice on dashboard" "$( [ "$(has x_dash.html 'wpd-nf-removed-notice')" = 1 ] && [ "$(has x_dash.html 'удалено 2 строки документов из папки, 1 файл медиатеки, изменено 1 запись или страница')" = 1 ] && echo 1 || echo 0)"
get "/wp-admin/admin.php?page=wpd-settings&tab=docs" x_docs.html
t "docs tab: no network folder" "$( [ "$(hasnt x_docs.html 'Сетевая папка')" = 1 ] && [ "$(hasnt x_docs.html 'wpd-netfolder')" = 1 ] && [ "$(hasnt x_docs.html 'data-wpd-nf')" = 1 ] && echo 1 || echo 0)"
t "docs tab: no src/nf inputs" "$( [ "$(grep -cE 'wpd_settings\[docs\]\[[^]]+\]\[(src|nf)\]' x_docs.html)" = 0 ] && [ "$(has x_docs.html 'wpd_settings\[docs\]\[__i__\]\[title\]')" = 1 ] && echo 1 || echo 0)"
t "docs tab: no PHP warnings" "$(nowarn x_docs.html)"
get "/wp-admin/admin.php?page=wpd-settings&tab=help" x_help.html
t "help: no network folder section" "$( [ "$(hasnt x_help.html 'Сетевая папка')" = 1 ] && [ "$(hasnt x_help.html 'smbclient')" = 1 ] && echo 1 || echo 0)"
get "/wp-admin/post.php?post=$ID&action=edit" x_edit.html
t "edit: no netfolder data or script" "$( [ "$(hasnt x_edit.html 'netfolder')" = 1 ] && [ "$(hasnt x_edit.html 'nfFolder')" = 1 ] && [ "$(hasnt x_edit.html 'docs_nf')" = 1 ] && [ "$(has x_edit.html 'wpd-blocks')" = 1 ] && echo 1 || echo 0)"
t "edit: no PHP warnings" "$(nowarn x_edit.html)"
DIS=$(python3 -c "import re,html;s=open('x_dash.html',encoding='utf-8').read();m=re.search(r'wpd-nf-removed-notice.*?href=\"([^\"]+)\"',s,re.S);print(html.unescape(m.group(1)) if m else '')")
t "dismiss link" "$(inq "$DIS" 'action=wpd_nf_removed_dismiss')"
curl -s -c cjX.txt -b cjX.txt --max-time 60 -o /dev/null -e "$B/wp-admin/" "$DIS"
get "/wp-admin/" x_dash2.html
t "dismiss hides notice" "$( [ "$(hasnt x_dash2.html 'wpd-nf-removed-notice')" = 1 ] && [ "$(eq "$(curl -sL --max-time 60 "$B/wpd-x-helper.php?state=1" | python3 -c 'import json,sys;print(json.load(sys.stdin)["notice"])')" 0)" = 1 ] && echo 1 || echo 0)"
curl -s -c cjX.txt -b cjX.txt --max-time 60 -o x_bad.html -w "%{http_code}" "$B/wp-admin/admin-post.php?action=wpd_nf_removed_dismiss" > x_bad.code
t "dismiss without nonce rejected" "$(eq "$(cat x_bad.code)" 403)"

curl -sL --max-time 120 "$B/wpd-x-helper.php?cleanup=1" > x_clean.json
curl -s --max-time 60 "$B/wpd-set.php?json=%7B%7D" > /dev/null
t "cleanup" "$(eq "$(js x_clean.json 'd["cleaned"]')" True)"
echo "PHASE X: PASS=$PASS FAIL=$FAIL"
