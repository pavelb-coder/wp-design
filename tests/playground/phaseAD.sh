#!/bin/bash
# Фаза AD (2.15.1): HTTP-проверки страховки от «голого текста» — критические стили встроены в страницу
# (:where(.wpd-page) в wpd-design-inline-css после wpd.css), мета viewport на странице с оформлением,
# версия плагина 2.15.1, раздел в справке. Помощник — wpd-ac-helper.php.
B=${WPD_BASE:-http://127.0.0.1:9400}
PASS=0; FAIL=0
t() { if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
has() { grep -q -- "$2" "$1" && echo 1 || echo 0; }
js() { python3 -c "import json,sys;d=json.load(open(sys.argv[1]));exec('print('+sys.argv[2]+')')" "$1" "$2" 2>/dev/null; }
nowarn() { grep -qE '<b>(Warning|Notice|Deprecated|Fatal error)</b>' "$1" && echo 0 || echo 1; }
all() { for v in "$@"; do [ "$v" = 1 ] || { echo 0; return; }; done; echo 1; }
rm -f /tmp/pg/ad_*.html /tmp/pg/ad_*.json 2>/dev/null
curl -s --max-time 60 "$B/wpd-set.php?json=%7B%7D" > /dev/null
curl -sL --max-time 120 "$B/wpd-ac-helper.php?setup=1" > ad_setup.json
URL=$(js ad_setup.json 'd["url"]')
t "helper setup" "$( [ -n "$URL" ] && echo 1 || echo 0)"

curl -sL --max-time 120 "$URL" -o ad_page.html
t "front: critical inline after wpd.css" "$(python3 -c "s=open('ad_page.html',encoding='utf-8').read();a=s.find(\"id='wpd-design-css'\");b=s.find('wpd-design-inline-css');c=s.find(':where(.wpd-page)');print(1 if 0<a<b<c else 0)")"
t "front: critical covers blocks" "$(all "$(has ad_page.html ':where(.wpd-page) .wpd-hero{')" "$(has ad_page.html ':where(.wpd-page) .wpd-btn{')" "$(has ad_page.html ':where(.wpd-page) .wpd-stats__grid')" "$(has ad_page.html '@media (max-width:600px)')")"
t "front: viewport meta" "$(has ad_page.html '<meta name="viewport" content="width=device-width, initial-scale=1">')"
t "front: no PHP warnings" "$(nowarn ad_page.html)"

login() { rm -f "$1"; curl -s -c "$1" -b "$1" -o /dev/null --max-time 60 "$B/wp-login.php"
  curl -s -c "$1" -b "$1" -o /dev/null --max-time 60 --data-urlencode "log=$2" --data-urlencode "pwd=$3" -d "wp-submit=Log+In&redirect_to=/wp-admin/&testcookie=1" "$B/wp-login.php"; }
login cjAD.txt admin password
curl -s -c cjAD.txt -b cjAD.txt --max-time 180 "$B/wp-admin/admin.php?page=wpd-settings&tab=help" -o ad_help.html
t "help: 2.15.1 in mobile section" "$(has ad_help.html '2.15.1: если на телефоне')"
curl -s -c cjAD.txt -b cjAD.txt --max-time 180 "$B/wp-admin/plugins.php" -o ad_plugins.html
t "plugins: version 2.15.1" "$(has ad_plugins.html '2.15.1')"

curl -sL --max-time 120 "$B/wpd-ac-helper.php?cleanup=1" > /dev/null
OPTS=$(python3 -c "import json;print(json.dumps(json.load(open('ad_setup.json')).get('opts') or {}))")
curl -s --max-time 60 "$B/wpd-set.php?json=$(python3 -c "import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))" "$OPTS")" > /dev/null
echo "AD: $PASS passed, $FAIL failed"
[ "$FAIL" = 0 ]
