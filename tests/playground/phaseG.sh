#!/bin/bash
# Фаза G (1.4.0): HTTP-проверки пункта меню «Олимпиада» и вкладок настроек, включая настоящее сохранение формы.
B=${WPD_BASE:-http://127.0.0.1:9400}
PASS=0; FAIL=0
t() { if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
has() { grep -q -- "$2" "$1" && echo 1 || echo 0; }
hasnot() { grep -q -- "$2" "$1" && echo 0 || echo 1; }
fields() { grep -oE "name=[\"']wpd_settings\[[a-z0-9_]+\]" "$1" | grep -v '\[_tab\]' | sed "s/'/\"/" | sort -u | wc -l | tr -d ' '; }
set_opt() { curl -s --max-time 60 "$B/wpd-set.php?json=$(python3 -c 'import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))' "$1")" > /dev/null; }

rm -f cjG.txt
curl -s -c cjG.txt -b cjG.txt -o /dev/null --max-time 60 -d "log=admin&pwd=password&wp-submit=Log+In&redirect_to=/wp-admin/&testcookie=1" "$B/wp-login.php"
get() { curl -s -c cjG.txt -b cjG.txt --max-time 120 "$B$1" -o "$2"; }

# Старый адрес перенаправляет
R=$(curl -s -c cjG.txt -b cjG.txt -o /dev/null --max-time 60 -w "%{http_code} %{redirect_url}" "$B/wp-admin/options-general.php?page=wpd-settings")
t "old URL redirects (302)"            "$(echo "$R" | grep -q '^302 .*admin.php?page=wpd-settings$' && echo 1 || echo 0)"

# Меню и вкладки
get "/wp-admin/admin.php?page=wpd-settings" g_design.html
t "menu: top-level item"               "$(has g_design.html 'toplevel_page_wpd-settings')"
t "menu: icon"                         "$(has g_design.html 'dashicons-awards')"
t "menu: not under Settings"           "$(hasnot g_design.html "options-general.php?page=wpd-settings")"
t "page: 14 tabs"                       "$([ "$(grep -oE 'class="nav-tab( nav-tab-active)?"' g_design.html | wc -l)" = "14" ] && echo 1 || echo 0)"
t "page: design tab active by default" "$(python3 -c "import re,sys;s=open('g_design.html',encoding='utf-8').read();print(1 if re.search(r'tab=design\"\s*class=\"nav-tab nav-tab-active\"',s) else 0)")"
t "page: hidden _tab=design"           "$(has g_design.html 'name="wpd_settings\[_tab\]" value="design"')"

declare -A EXPECT=([design]=35 [hero]=45 [features]=43 [stats]=15 [docs]=18 [faq]=16 [slider]=19 [buttons]=19 [cards]=18 [notice]=20 [news]=23 [contacts]=30 [partners]=12 [help]=0)
for tab in design hero features stats docs faq slider buttons cards notice news contacts partners help; do
  get "/wp-admin/admin.php?page=wpd-settings&tab=$tab" "g_$tab.html"
  t "tab $tab: ${EXPECT[$tab]} fields"  "$([ "$(fields g_$tab.html)" = "${EXPECT[$tab]}" ] && echo 1 || echo 0)"
done
t "tab help: no form"                  "$(hasnot g_help.html 'action="options.php"')"
t "tab help: shortcodes"               "$(has g_help.html '\[wpd_countdown\]')"
t "tab help: panel and blocks"         "$([ "$(has g_help.html 'Блоки олимпиады')$(has g_help.html 'Страница олимпиады')" = "11" ] && echo 1 || echo 0)"
t "submenu: stats highlighted"         "$(grep -qE "<li class=\"current\"><a href='admin.php\?page=wpd-settings&(amp;|#038;)?tab=stats'" g_stats.html && echo 1 || echo 0)"
t "submenu: no relative links"         "$(grep -q "href='wpd-settings" g_stats.html && echo 0 || echo 1)"
t "submenu: 13 tab links via admin.php" "$([ "$(grep -oE "href='admin.php\?page=wpd-settings&(amp;|#038;)?tab=[a-z]+'" g_stats.html | wc -l)" = "13" ] && echo 1 || echo 0)"
get "/wp-admin/admin.php?page=wpd-settings&tab=evil" g_evil.html
t "invalid tab -> design"              "$(has g_evil.html 'name="wpd_settings\[_tab\]" value="design"')"

# Настоящее сохранение одной вкладки не сбрасывает другие
set_opt '{"primary":"#0f766e","countdown_enabled":1,"countdown_date":"2030-11-20 10:30","faq_search":1,"stats_animate":1,"hide_title":1,"stats":"1|старое","badge":"Метка"}'
get "/wp-admin/admin.php?page=wpd-settings&tab=stats" g_stats_form.html
NONCE=$(grep -o 'name="_wpnonce" value="[^"]*"' g_stats_form.html | head -1 | sed 's/.*value="//;s/"//')
REF=$(grep -o 'name="_wp_http_referer" value="[^"]*"' g_stats_form.html | head -1 | sed 's/.*value="//;s/"//;s/&amp;/\&/g')
t "form: nonce present"                "$([ -n "$NONCE" ] && echo 1 || echo 0)"
LOC=$(curl -s -c cjG.txt -b cjG.txt -o /dev/null --max-time 120 -w "%{redirect_url}" \
  --data-urlencode "option_page=wpd_settings_group" --data-urlencode "action=update" \
  --data-urlencode "_wpnonce=$NONCE" --data-urlencode "_wp_http_referer=$REF" \
  --data-urlencode "wpd_settings[_tab]=stats" --data-urlencode "wpd_settings[stats]=500+|участников из формы" \
  "$B/wp-admin/options.php")
t "save: redirect back to stats tab"   "$(echo "$LOC" | grep -q 'tab=stats' && echo "$LOC" | grep -q 'settings-updated=true' && echo 1 || echo 0)"
get "/wp-admin/admin.php?page=wpd-settings&tab=stats&settings-updated=true" g_after.html
t "save: notice shown"                 "$(has g_after.html 'settings-error\|notice-success\|updated')"
t "save: stats value saved (rows)"     "$(grep -q 'value="500+"' g_after.html && grep -q 'value="участников из формы"' g_after.html && echo 1 || echo 0)"
get "/wp-admin/admin.php?page=wpd-settings&tab=design" g_design2.html
t "save: color on other tab kept"      "$(has g_design2.html 'value="#0f766e"')"
t "save: hide_title on other tab kept" "$(python3 -c "import re;s=open('g_design2.html',encoding='utf-8').read();print(1 if re.search(r'name=\"wpd_settings\[hide_title\]\" value=\"1\"\s+checked', s) else 0)")"
get "/wp-admin/admin.php?page=wpd-settings&tab=features" g_feat2.html
t "save: checkboxes on other tab kept" "$([ "$(grep -oE 'name="wpd_settings\[(countdown_enabled|faq_search|stats_animate)\]" value="1"\s+checked' g_feat2.html | wc -l)" = "3" ] && echo 1 || echo 0)"
get "/wp-admin/admin.php?page=wpd-settings&tab=hero" g_hero2.html
t "save: hero text on other tab kept"  "$(has g_hero2.html 'value="Метка"')"

# Снять флажок на вкладке «Таймер и эффекты»: меняется только эта вкладка
NONCE=$(grep -o 'name="_wpnonce" value="[^"]*"' g_feat2.html | head -1 | sed 's/.*value="//;s/"//')
curl -s -c cjG.txt -b cjG.txt -o /dev/null --max-time 120 \
  --data-urlencode "option_page=wpd_settings_group" --data-urlencode "action=update" --data-urlencode "_wpnonce=$NONCE" \
  --data-urlencode "_wp_http_referer=/wp-admin/admin.php?page=wpd-settings&tab=features" \
  --data-urlencode "wpd_settings[_tab]=features" --data-urlencode "wpd_settings[faq_search]=1" \
  --data-urlencode "wpd_settings[countdown_date]=2030-11-20T10:30" --data-urlencode "wpd_settings[countdown_label]=До старта" \
  --data-urlencode "wpd_settings[countdown_done]=Прошло" "$B/wp-admin/options.php"
get "/wp-admin/admin.php?page=wpd-settings&tab=features" g_feat3.html
t "uncheck: countdown off"             "$(grep -qE 'name="wpd_settings\[countdown_enabled\]" value="1"\s+checked' g_feat3.html && echo 0 || echo 1)"
t "uncheck: faq search still on"       "$(grep -qE 'name="wpd_settings\[faq_search\]" value="1"\s+checked' g_feat3.html && echo 1 || echo 0)"
get "/wp-admin/admin.php?page=wpd-settings&tab=stats" g_stats3.html
t "uncheck: stats tab untouched"       "$(grep -q 'value="500+"' g_stats3.html && grep -q 'value="участников из формы"' g_stats3.html && echo 1 || echo 0)"

# Ссылка «Настройки» в списке плагинов
get "/wp-admin/plugins.php" g_plugins.html
t "plugins: settings action link"      "$(has g_plugins.html 'admin.php?page=wpd-settings">Настройки')"

set_opt '{}'
echo ""
echo "PHASE G: PASS=$PASS FAIL=$FAIL"
