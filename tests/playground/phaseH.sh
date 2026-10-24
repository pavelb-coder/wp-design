#!/bin/bash
# Фаза H (1.5.0): HTTP-проверки компонентов — вкладки настроек с повторителями, настоящее сохранение формы,
# шорткоды на сайте, подключение Dashicons и скриптов. Сохраняет h_components.html для фазы J. В конце сбрасывает опцию.
B=${WPD_BASE:-http://127.0.0.1:9400}
PASS=0; FAIL=0
t() { if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
has() { grep -q -- "$2" "$1" && echo 1 || echo 0; }
hasnot() { grep -q -- "$2" "$1" && echo 0 || echo 1; }
count() { grep -o -- "$2" "$1" | wc -l | tr -d ' '; }
set_opt() { curl -s --max-time 60 "$B/wpd-set.php?json=$(python3 -c 'import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))' "$1")" > /dev/null; }

rm -f cjH.txt
curl -s -c cjH.txt -b cjH.txt -o /dev/null --max-time 60 -d "log=admin&pwd=password&wp-submit=Log+In&redirect_to=/wp-admin/&testcookie=1" "$B/wp-login.php"
get() { curl -s -c cjH.txt -b cjH.txt --max-time 120 "$B$1" -o "$2"; }

set_opt '{}'

# Вкладки настроек
get "/wp-admin/admin.php?page=wpd-settings&tab=slider" h_slider.html
t "slider tab: repeater"                 "$(has h_slider.html 'data-wpd-rep="slides"')"
t "slider tab: row template"             "$(has h_slider.html '<template data-wpd-rep-tpl>')"
t "slider tab: template uses __i__"      "$(has h_slider.html 'wpd_settings\[slides\]\[__i__\]\[heading\]')"
t "slider tab: add button + limit"       "$(has h_slider.html 'data-wpd-rep-add')"
t "slider tab: media picker"             "$(has h_slider.html 'data-wpd-s-pick')"
t "settings: wpd-settings.js enqueued"   "$(has h_slider.html 'assets/js/wpd-settings.js')"
t "settings: media library enqueued"     "$(has h_slider.html 'media-views')"
t "settings: color picker enqueued"      "$(has h_slider.html 'wp-color-picker')"
get "/wp-admin/admin.php?page=wpd-settings&tab=buttons" h_buttons.html
t "buttons tab: 4 demo rows"             "$([ "$(grep -oE 'name="wpd_settings\[buttons\]\[[0-9]+\]\[text\]"' h_buttons.html | sort -u | wc -l | tr -d ' ')" = "4" ] && echo 1 || echo 0)"
t "buttons tab: icon select with preview" "$(has h_buttons.html 'data-wpd-icon')"
get "/wp-admin/admin.php?page=wpd-settings&tab=design" h_design.html
t "design tab: 8 palette options"        "$([ "$(grep -oE 'name="wpd_settings\[palette\]"' h_design.html | wc -l | tr -d ' ')" = "8" ] && echo 1 || echo 0)"
t "design tab: custom palette checked"   "$(grep -qE 'name="wpd_settings\[palette\]" value="custom"[^>]*checked' h_design.html && echo 1 || echo 0)"
get "/wp-admin/admin.php?page=wpd-settings&tab=news" h_news.html
t "news tab: category dropdown"          "$(has h_news.html "name='wpd_settings\[news_category\]'")"
get "/wp-admin/admin.php?page=wpd-settings&tab=notice" h_notice.html
t "notice tab: rich editor for content"  "$(has h_notice.html 'wpd_settings\[notice_content\]')"
get "/wp-admin/index.php" h_dash.html
t "dashboard: no wpd-settings.js"        "$(hasnot h_dash.html 'wpd-settings.js')"

# Настоящее сохранение вкладки «Слайдер» с повторителем
NONCE=$(grep -o 'name="_wpnonce" value="[^"]*"' h_slider.html | head -1 | sed 's/.*value="//;s/"//')
LOC=$(curl -s -c cjH.txt -b cjH.txt -o /dev/null --max-time 120 -w "%{redirect_url}" \
  --data-urlencode "option_page=wpd_settings_group" --data-urlencode "action=update" --data-urlencode "_wpnonce=$NONCE" \
  --data-urlencode "_wp_http_referer=/wp-admin/admin.php?page=wpd-settings&tab=slider" \
  --data-urlencode "wpd_settings[_tab]=slider" \
  --data-urlencode "wpd_settings[slides][0][heading]=Слайд из формы" --data-urlencode "wpd_settings[slides][0][text]=Описание" \
  --data-urlencode "wpd_settings[slides][0][url]=https://example.com/s1" --data-urlencode "wpd_settings[slides][0][new_tab]=1" \
  --data-urlencode "wpd_settings[slides][1][heading]=" --data-urlencode "wpd_settings[slides][1][text]=" \
  --data-urlencode "wpd_settings[slides][2][heading]=Второй <b>слайд</b>" \
  --data-urlencode "wpd_settings[slides][__i__][heading]=Шаблон" \
  --data-urlencode "wpd_settings[slider_height]=480" --data-urlencode "wpd_settings[slider_arrows]=1" --data-urlencode "wpd_settings[slider_dots]=1" \
  "$B/wp-admin/options.php")
t "save slider: redirect back to tab"    "$(echo "$LOC" | grep -q 'tab=slider' && echo 1 || echo 0)"
get "/wp-admin/admin.php?page=wpd-settings&tab=slider" h_slider2.html
t "save slider: first slide stored"      "$(has h_slider2.html 'value="Слайд из формы"')"
t "save slider: empty row dropped, rows renumbered" "$(python3 -c "
import re
s = open('h_slider2.html', encoding='utf-8').read()
rows = sorted(set(re.findall(r'name=\"wpd_settings\[slides\]\[(\d+)\]\[heading\]\" value=\"([^\"]*)\"', s)))
print(1 if rows == [('0', 'Слайд из формы'), ('1', 'Второй слайд')] else 0)")"
t "save slider: tags stripped"           "$(hasnot h_slider2.html 'Второй <b>')"
t "save slider: height stored"           "$(has h_slider2.html 'value="480"')"
t "save slider: autoplay unchecked"      "$(grep -qE 'name="wpd_settings\[slider_autoplay\]" value="1"\s+checked' h_slider2.html && echo 0 || echo 1)"

# Сайт: шорткоды из общих настроек
set_opt '{"slides":[{"image":"","heading":"Слайд 1","text":"Текст 1","url":"https://example.com/1","new_tab":0},{"image":"","heading":"Слайд 2","text":"","url":"","new_tab":0},{"image":"","heading":"Слайд 3","text":"","url":"","new_tab":0}],"notice_title":"Объявление сайта","notice_content":"<p>Важный текст</p>","contact_phone":"+7 391 000-00-00","contact_email":"site@example.com","news_layout":"carousel"}'
ID=$(curl -s --max-time 60 "$B/wpd-h-page.php")
t "helper created page"                  "$([ -n "$ID" ] && [ "$ID" -gt 0 ] 2>/dev/null && echo 1 || echo 0)"
curl -sL --max-time 120 "$B/?page_id=$ID" -o h_components.html
for part in slider buttons cards notice news contacts; do
  t "front: $part rendered"              "$(has h_components.html "class=\"wpd-$part")"
done
t "front: slider markup"                 "$(has h_components.html 'data-wpd-carousel="slider"')"
t "front: 3 slides"                      "$([ "$(count h_components.html 'aria-roledescription="slide"')" = "3" ] && echo 1 || echo 0)"
t "front: news carousel"                 "$(has h_components.html 'data-wpd-carousel="news"')"
t "front: wpd.css linked"                "$(has h_components.html 'assets/css/wpd.css')"
t "front: wpd.js linked"                 "$(has h_components.html 'assets/js/wpd.js')"
t "front: dashicons for buttons/contacts" "$(has h_components.html "dashicons.min.css\|dashicons.css")"
t "front: notice"                        "$(has h_components.html 'Объявление сайта')"
t "front: tel link"                      "$(has h_components.html 'href="tel:+73910000000"')"
PLAIN=$(curl -s --max-time 60 "$B/wpd-h-page.php?plain=1")
curl -sL --max-time 120 "$B/?page_id=$PLAIN" -o h_plain.html
t "plain page: no dashicons for guests"  "$(hasnot h_plain.html 'dashicons')"
t "plain page: no wpd.js"                "$(hasnot h_plain.html 'assets/js/wpd.js')"

# Страница из блоков (как шаблон «Главная страница из компонентов»)
BID=$(curl -s --max-time 60 "$B/wpd-h-page.php?blocks=1")
curl -sL --max-time 120 "$B/?page_id=$BID" -o h_blocks.html
t "blocks page: slider block wrap"       "$(has h_blocks.html 'wpd-page--block wpd-page--slider')"
t "blocks page: contacts block wrap"     "$(has h_blocks.html 'wpd-page--contacts')"
t "blocks page: dashicons"               "$(has h_blocks.html 'dashicons')"

set_opt '{}'
curl -s --max-time 60 "$B/wpd-h-page.php?cleanup=1" > /dev/null
echo
echo "PHASE H: PASS=$PASS FAIL=$FAIL"
[ "$FAIL" = "0" ]
