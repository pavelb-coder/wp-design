#!/bin/bash
# Фаза AC (2.15.0): HTTP-проверки мобильной адаптации — слой 2.15.0 в wpd.css (точки 820/600/480/360,
# ландшафт, сенсорные устройства, safe-area), страница сайта без предупреждений PHP, раздел справки
# 2.15.0, версия плагина 2.15.0. Помощник — wpd-ac-helper.php.
B=${WPD_BASE:-http://127.0.0.1:9400}
PASS=0; FAIL=0
t() { if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
has() { grep -q -- "$2" "$1" && echo 1 || echo 0; }
eq() { [ "$1" = "$2" ] && echo 1 || echo 0; }
js() { python3 -c "import json,sys;d=json.load(open(sys.argv[1]));exec('print('+sys.argv[2]+')')" "$1" "$2" 2>/dev/null; }
nowarn() { grep -qE '<b>(Warning|Notice|Deprecated|Fatal error)</b>' "$1" && echo 0 || echo 1; }
all() { for v in "$@"; do [ "$v" = 1 ] || { echo 0; return; }; done; echo 1; }
rm -f /tmp/pg/ac_*.html /tmp/pg/ac_*.json /tmp/pg/ac_*.css 2>/dev/null
curl -s --max-time 60 "$B/wpd-set.php?json=%7B%7D" > /dev/null
curl -sL --max-time 120 "$B/wpd-ac-helper.php?setup=1" > ac_setup.json
ID=$(js ac_setup.json 'd["id"]'); URL=$(js ac_setup.json 'd["url"]')
t "helper setup" "$( [ -n "$ID" ] && [ -n "$URL" ] && echo 1 || echo 0)"

curl -sL --max-time 120 "$URL" -o ac_page.html
t "front: all components" "$(all "$(has ac_page.html 'wpd-hero')" "$(has ac_page.html 'wpd-stats__grid')" "$(has ac_page.html 'wpd-docs__list')" "$(has ac_page.html 'wpd-faq__search')" "$(has ac_page.html 'wpd-countdown')" "$(has ac_page.html 'wpd-notice')" "$(has ac_page.html 'wpd-contacts')" "$(has ac_page.html 'wpd-slider')" "$(has ac_page.html 'wpd-notice')" "$(has ac_page.html 'wpd-contacts')" "$(has ac_page.html 'wpd-partners__list')")"
t "front: no PHP warnings" "$(nowarn ac_page.html)"

curl -sL --max-time 60 "$B/wp-content/plugins/wp-olympiad-design/assets/css/wpd.css" -o ac_css.css
t "css: 2.15.0 layer" "$(has ac_css.css '2.15.0. Мобильная адаптация')"
t "css: breakpoints" "$(all "$(has ac_css.css '@media (max-width: 820px)')" "$(has ac_css.css '@media (max-width: 600px)')" "$(has ac_css.css '@media (max-width: 480px)')" "$(has ac_css.css '@media (max-width: 360px)')" "$(has ac_css.css '@media (max-height: 500px) and (orientation: landscape)')")"
t "css: touch and safe-area" "$(all "$(has ac_css.css '@media (hover: none)')" "$(has ac_css.css '@media (pointer: coarse)')" "$(has ac_css.css 'safe-area-inset-left')" "$(has ac_css.css 'min-height: 48px')" "$(has ac_css.css 'font-size: 16px')")"
t "css: no page-wide overflow" "$(all "$(has ac_css.css 'overflow-wrap: anywhere')" "$(has ac_css.css 'text-size-adjust: 100%')")"

# Админка: версия и справка
login() { rm -f "$1"; curl -s -c "$1" -b "$1" -o /dev/null --max-time 60 "$B/wp-login.php"
  curl -s -c "$1" -b "$1" -o /dev/null --max-time 60 --data-urlencode "log=$2" --data-urlencode "pwd=$3" -d "wp-submit=Log+In&redirect_to=/wp-admin/&testcookie=1" "$B/wp-login.php"; }
login cjAC.txt admin password
curl -s -c cjAC.txt -b cjAC.txt --max-time 180 "$B/wp-admin/admin.php?page=wpd-settings&tab=help" -o ac_help.html
t "help: 2.15.0 section" "$(has ac_help.html 'Мобильная адаптация (2.15.0)')"
t "help: no PHP warnings" "$(nowarn ac_help.html)"
curl -s -c cjAC.txt -b cjAC.txt --max-time 180 "$B/wp-admin/plugins.php" -o ac_plugins.html
t "plugins: version 2.15.0" "$(has ac_plugins.html '2.15.0')"

curl -sL --max-time 120 "$B/wpd-ac-helper.php?cleanup=1" > /dev/null
OPTS=$(python3 -c "import json;print(json.dumps(json.load(open('ac_setup.json')).get('opts') or {}))")
curl -s --max-time 60 "$B/wpd-set.php?json=$(python3 -c "import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))" "$OPTS")" > /dev/null
echo "AC: $PASS passed, $FAIL failed"
[ "$FAIL" = 0 ]
