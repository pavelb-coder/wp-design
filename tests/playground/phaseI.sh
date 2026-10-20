#!/bin/bash
# Фаза I (1.6.0): HTTP-проверки таймера окончания и кнопки — поля вкладки «Таймер и эффекты», настоящее сохранение
# (включая предупреждение о датах), страница сайта во время этапа. Сохраняет i_countdown.html для фазы J. В конце сбрасывает опцию.
B=${WPD_BASE:-http://127.0.0.1:9400}
PASS=0; FAIL=0
t() { if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
has() { grep -q -- "$2" "$1" && echo 1 || echo 0; }
hasnot() { grep -q -- "$2" "$1" && echo 0 || echo 1; }
set_opt() { curl -s --max-time 60 "$B/wpd-set.php?json=$(python3 -c 'import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))' "$1")" > /dev/null; }
rel() { python3 -c "import datetime,sys;print((datetime.datetime.now(datetime.timezone.utc)+datetime.timedelta(hours=float(sys.argv[1]))).strftime('%Y-%m-%d %H:%M'))" "$1"; }

rm -f cjI.txt
curl -s -c cjI.txt -b cjI.txt -o /dev/null --max-time 60 -d "log=admin&pwd=password&wp-submit=Log+In&redirect_to=/wp-admin/&testcookie=1" "$B/wp-login.php"
get() { curl -s -c cjI.txt -b cjI.txt --max-time 120 "$B$1" -o "$2"; }

set_opt '{}'
get "/wp-admin/admin.php?page=wpd-settings&tab=features" i_feat.html
t "features tab: end date field"         "$(has i_feat.html 'name="wpd_settings\[countdown_end\]"')"
t "features tab: end field is datetime"  "$(grep -qE 'type="datetime-local"[^>]*name="wpd_settings\[countdown_end\]"|name="wpd_settings\[countdown_end\]"[^>]*type="datetime-local"' i_feat.html && echo 1 || echo 0)"
t "features tab: end label"              "$(has i_feat.html 'name="wpd_settings\[countdown_end_label\]"')"
t "features tab: link url (type url)"    "$(grep -qE 'type="url"[^>]*name="wpd_settings\[countdown_link_url\]"|name="wpd_settings\[countdown_link_url\]"[^>]*type="url"' i_feat.html && echo 1 || echo 0)"
t "features tab: link text"              "$(has i_feat.html 'name="wpd_settings\[countdown_link_text\]"')"
t "features tab: new tab checked by default" "$(grep -qE 'name="wpd_settings\[countdown_link_new_tab\]" value="1"\s+checked' i_feat.html && echo 1 || echo 0)"
t "features tab: labels renamed"         "$([ "$(has i_feat.html 'Дата и время начала')$(has i_feat.html 'Дата и время окончания')" = "11" ] && echo 1 || echo 0)"

# Сохранение с окончанием раньше начала — предупреждение, значения сохраняются
NONCE=$(grep -o 'name="_wpnonce" value="[^"]*"' i_feat.html | head -1 | sed 's/.*value="//;s/"//')
post_feat() {
  curl -s -c cjI.txt -b cjI.txt -o /dev/null --max-time 120 -w "%{redirect_url}" \
    --data-urlencode "option_page=wpd_settings_group" --data-urlencode "action=update" --data-urlencode "_wpnonce=$NONCE" \
    --data-urlencode "_wp_http_referer=/wp-admin/admin.php?page=wpd-settings&tab=features" \
    --data-urlencode "wpd_settings[_tab]=features" --data-urlencode "wpd_settings[countdown_enabled]=1" \
    --data-urlencode "wpd_settings[countdown_date]=$1" --data-urlencode "wpd_settings[countdown_end]=$2" \
    --data-urlencode "wpd_settings[countdown_label]=До старта" --data-urlencode "wpd_settings[countdown_end_label]=До финиша" \
    --data-urlencode "wpd_settings[countdown_done]=Тур завершён" --data-urlencode "wpd_settings[countdown_link_text]=Открыть задания" \
    --data-urlencode "wpd_settings[countdown_link_url]=https://example.com/tasks" --data-urlencode "wpd_settings[countdown_link_new_tab]=1" \
    "$B/wp-admin/options.php"
}
LOC=$(post_feat "2031-03-10T10:00" "2031-03-09T10:00")
t "save: redirect back to features"      "$(echo "$LOC" | grep -q 'tab=features' && echo 1 || echo 0)"
get "/wp-admin/admin.php?page=wpd-settings&tab=features&settings-updated=true" i_feat2.html
t "save: warning about end before start" "$(has i_feat2.html 'Дата окончания таймера не позже даты начала')"
t "save: warning shown once"             "$([ "$(grep -o 'Дата окончания таймера не позже даты начала' i_feat2.html | wc -l | tr -d ' ')" = "1" ] && echo 1 || echo 0)"
t "save: end value stored"               "$(has i_feat2.html 'value="2031-03-09T10:00"')"
t "save: link stored"                    "$(has i_feat2.html 'value="https://example.com/tasks"')"

# Нормальные даты: этап идёт сейчас (время UTC → часовой пояс сайта в песочнице UTC)
START=$(rel -1); END=$(rel 2)
LOC=$(post_feat "${START/ /T}" "${END/ /T}")
get "/wp-admin/admin.php?page=wpd-settings&tab=features&settings-updated=true" i_feat3.html
t "save: no warning for valid dates"     "$(hasnot i_feat3.html 'Дата окончания таймера не позже даты начала')"

ID=$(curl -s --max-time 60 "$B/wpd-h-page.php?countdown=1")
curl -sL --max-time 120 "$B/?page_id=$ID" -o i_countdown.html
t "front: countdown active"              "$(has i_countdown.html 'data-phase="active"')"
t "front: end label shown"               "$(has i_countdown.html '<p class="wpd-countdown__label">До финиша</p>')"
t "front: button visible during stage"   "$(grep -qE 'class="wpd-btn wpd-countdown__link" href="https://example.com/tasks" target="_blank" rel="noopener noreferrer">Открыть задания' i_countdown.html && echo 1 || echo 0)"
t "front: data for script"               "$([ "$(has i_countdown.html 'data-end="')$(has i_countdown.html 'data-end-label="До финиша"')$(has i_countdown.html 'data-label="До старта"')" = "111" ] && echo 1 || echo 0)"
t "front: wpd.js linked"                 "$(has i_countdown.html 'assets/js/wpd.js')"

# Этап закончился — кнопка скрыта, текст «прошло»
set_opt "{\"countdown_enabled\":1,\"countdown_date\":\"$(rel -5)\",\"countdown_end\":\"$(rel -1)\",\"countdown_done\":\"Тур завершён\",\"countdown_link_url\":\"https://example.com/tasks\",\"countdown_link_text\":\"Открыть задания\"}"
curl -sL --max-time 120 "$B/?page_id=$ID" -o i_countdown_done.html
t "front after end: done text"           "$(has i_countdown_done.html '<p class="wpd-countdown__value">Тур завершён</p>')"
t "front after end: button hidden"       "$(grep -qE 'wpd-countdown__link[^>]*hidden' i_countdown_done.html && echo 1 || echo 0)"

set_opt '{}'
curl -s --max-time 60 "$B/wpd-h-page.php?cleanup=1" > /dev/null
echo
echo "PHASE I: PASS=$PASS FAIL=$FAIL"
[ "$FAIL" = "0" ]
