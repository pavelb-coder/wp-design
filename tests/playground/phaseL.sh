#!/bin/bash
# Фаза L (1.8.0): HTTP-проверки новых параметров — раздел «Типографика и вид» на вкладке «Оформление», поля главного блока,
# настоящее сохранение обеих вкладок и результат на сайте. В конце сбрасывает опцию и удаляет тестовые страницы.
B=${WPD_BASE:-http://127.0.0.1:9400}
PASS=0; FAIL=0
t() { if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
has() { grep -q -- "$2" "$1" && echo 1 || echo 0; }
set_opt() { curl -s --max-time 60 "$B/wpd-set.php?json=$(python3 -c 'import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))' "$1")" > /dev/null; }
nonce() { grep -o 'name="_wpnonce" value="[^"]*"' "$1" | head -1 | sed 's/.*value="//;s/"//'; }

rm -f cjL.txt
curl -s -c cjL.txt -b cjL.txt -o /dev/null --max-time 60 -d "log=admin&pwd=password&wp-submit=Log+In&redirect_to=/wp-admin/&testcookie=1" "$B/wp-login.php"
get() { curl -s -c cjL.txt -b cjL.txt --max-time 120 "$B$1" -o "$2"; }

set_opt '{}'
# Вкладка «Оформление»
get "/wp-admin/admin.php?page=wpd-settings&tab=design" l_design.html
t "design tab: section «Типографика и вид»" "$(has l_design.html 'Типографика и вид')"
t "design tab: 10 look fields"           "$(python3 -c "import re;s=open('l_design.html',encoding='utf-8').read();ks=['font_heading','font_size','heading_color','content_width','block_gap','button_shape','button_style','card_shadow','heading_underline','card_hover'];print(1 if all(re.search(r'name=\"wpd_settings\[%s\]\"'%k,s) for k in ks) else 0)")"
t "design tab: look after colors"        "$(python3 -c "s=open('l_design.html',encoding='utf-8').read();a=s.find('[font]');b=s.find('[font_heading]');c=s.find('[hide_title]');print(1 if 0<a<b<c else 0)")"
t "design tab: number limits"            "$(grep -qE 'name="wpd_settings\[content_width\]"[^>]*' l_design.html && grep -oE '<input[^>]*name="wpd_settings\[content_width\]"[^>]*>' l_design.html | grep -q 'min="720"' && grep -oE '<input[^>]*name="wpd_settings\[content_width\]"[^>]*>' l_design.html | grep -q 'max="1600"' && echo 1 || echo 0)"
t "design tab: button shape options"     "$([ "$(has l_design.html 'value="pill"')$(has l_design.html 'value="square"')$(has l_design.html 'value="outline"')" = "111" ] && echo 1 || echo 0)"
t "design tab: optional color picker"    "$(grep -oE '<input[^>]*name="wpd_settings\[heading_color\]"[^>]*>' l_design.html | grep -q 'wpd-color-opt' && echo 1 || echo 0)"

NONCE=$(nonce l_design.html)
LOC=$(curl -s -c cjL.txt -b cjL.txt -o /dev/null --max-time 120 -w "%{redirect_url}" \
  --data-urlencode "option_page=wpd_settings_group" --data-urlencode "action=update" --data-urlencode "_wpnonce=$NONCE" \
  --data-urlencode "_wp_http_referer=/wp-admin/admin.php?page=wpd-settings&tab=design" \
  --data-urlencode "wpd_settings[_tab]=design" --data-urlencode "wpd_settings[palette]=custom" --data-urlencode "wpd_settings[primary]=#1d4ed8" \
  --data-urlencode "wpd_settings[font_heading]=Georgia, serif" --data-urlencode "wpd_settings[font_size]=18" --data-urlencode "wpd_settings[heading_color]=#0f766e" \
  --data-urlencode "wpd_settings[content_width]=1400" --data-urlencode "wpd_settings[block_gap]=64" --data-urlencode "wpd_settings[button_shape]=pill" \
  --data-urlencode "wpd_settings[button_style]=outline" --data-urlencode "wpd_settings[card_shadow]=strong" \
  "$B/wp-admin/options.php")
t "design save: redirect back"           "$(echo "$LOC" | grep -q 'tab=design' && echo 1 || echo 0)"
get "/wp-admin/admin.php?page=wpd-settings&tab=design&settings-updated=true" l_design2.html
t "design save: values stored"           "$([ "$(has l_design2.html 'value="Georgia, serif"')$(has l_design2.html 'value="#0f766e"')$(grep -oE '<input[^>]*name="wpd_settings\[block_gap\]"[^>]*>' l_design2.html | grep -q 'value="64"' && echo 1 || echo 0)" = "111" ] && echo 1 || echo 0)"
t "design save: unchecked boxes stay off" "$(grep -oE '<input[^>]*name="wpd_settings\[card_hover\]"[^>]*>' l_design2.html | grep -q 'checked' && echo 0 || echo 1)"

# Вкладка «Главный блок»
get "/wp-admin/admin.php?page=wpd-settings&tab=hero" l_hero.html
t "hero tab: new fields"                 "$(python3 -c "import re;s=open('l_hero.html',encoding='utf-8').read();ks=['hero_button_new_tab','hero_button2_text','hero_button2_url','hero_button2_new_tab','hero_image_alt','hero_image_position','hero_overlay','hero_bg','hero_height','hero_align','hero_title_size'];print(1 if all(re.search(r'name=\"wpd_settings\[%s\]\"'%k,s) for k in ks) else 0)")"
t "hero tab: position options"           "$(has l_hero.html 'Фоном на весь блок')"
NONCE=$(nonce l_hero.html)
curl -s -c cjL.txt -b cjL.txt -o /dev/null --max-time 120 \
  --data-urlencode "option_page=wpd_settings_group" --data-urlencode "action=update" --data-urlencode "_wpnonce=$NONCE" \
  --data-urlencode "_wp_http_referer=/wp-admin/admin.php?page=wpd-settings&tab=hero" \
  --data-urlencode "wpd_settings[_tab]=hero" --data-urlencode "wpd_settings[badge]=Олимпиада 2026" --data-urlencode "wpd_settings[hero_title]=Главный блок L" \
  --data-urlencode "wpd_settings[hero_button_text]=Положение" --data-urlencode "wpd_settings[hero_button_url]=https://example.com/rules" \
  --data-urlencode "wpd_settings[hero_button2_text]=Задания прошлых лет" --data-urlencode "wpd_settings[hero_button2_url]=https://example.com/archive" \
  --data-urlencode "wpd_settings[hero_button2_new_tab]=1" --data-urlencode "wpd_settings[hero_image_url]=https://example.com/hall.jpg" \
  --data-urlencode "wpd_settings[hero_image_position]=background" --data-urlencode "wpd_settings[hero_overlay]=70" \
  --data-urlencode "wpd_settings[hero_style]=gradient" --data-urlencode "wpd_settings[hero_height]=tall" --data-urlencode "wpd_settings[hero_align]=center" \
  --data-urlencode "wpd_settings[hero_title_size]=large" \
  "$B/wp-admin/options.php"
get "/wp-admin/admin.php?page=wpd-settings&tab=hero&settings-updated=true" l_hero2.html
t "hero save: values stored"             "$([ "$(has l_hero2.html 'value="Задания прошлых лет"')$(grep -oE '<input[^>]*name="wpd_settings\[hero_overlay\]"[^>]*>' l_hero2.html | grep -q 'value="70"' && echo 1 || echo 0)" = "11" ] && echo 1 || echo 0)"
t "hero save: design kept"               "$(get "/wp-admin/admin.php?page=wpd-settings&tab=design" l_design3.html; has l_design3.html 'value="Georgia, serif"')"

# Сайт
ID=$(curl -s --max-time 60 "$B/wpd-h-page.php?hero=1")
curl -sL --max-time 120 "$B/?page_id=$ID" -o l_front.html
t "front: page classes"                  "$(has l_front.html 'class="wpd-page wpd-page--btn-pill wpd-page--btn-outline wpd-page--shadow-strong wpd-page--no-underline wpd-page--no-hover"')"
t "front: css vars"                      "$(has l_front.html '--wpd-font-heading:Georgia, serif;--wpd-size:18px;--wpd-heading:#0f766e;--wpd-width:1400px;--wpd-gap:64px;')"
t "front: hero classes"                  "$(has l_front.html 'class="wpd-hero wpd-hero--gradient wpd-hero--img-background wpd-hero--height-tall wpd-hero--align-center wpd-hero--title-large"')"
t "front: background image + overlay"    "$(has l_front.html 'style="--wpd-hero-img:url(&quot;https://example.com/hall.jpg&quot;);--wpd-hero-overlay:70%;"')"
t "front: no <img> in background mode"   "$(grep -q 'wpd-hero__media' l_front.html && echo 0 || echo 1)"
t "front: two buttons"                   "$(has l_front.html '<div class="wpd-hero__actions"><a class="wpd-btn" href="https://example.com/rules">Положение &rarr;</a><a class="wpd-btn wpd-btn--ghost" href="https://example.com/archive" target="_blank" rel="noopener noreferrer">Задания прошлых лет</a></div>')"
t "front: no PHP warnings"               "$(grep -qE '<b>(Warning|Notice|Deprecated)</b>' l_front.html && echo 0 || echo 1)"
curl -s --max-time 60 "$B/wp-content/plugins/wp-olympiad-design/assets/css/wpd.css" -o l_css.css
t "css: 1.8.0 rules"                     "$([ "$(has l_css.css 'wpd-page--btn-pill')$(has l_css.css 'wpd-hero--img-background')$(has l_css.css 'wpd-hero__actions')$(has l_css.css 'wpd-btn--ghost')$(has l_css.css 'var(--wpd-gap, 40px)')" = "11111" ] && echo 1 || echo 0)"

# Сброс
set_opt '{}'
curl -sL --max-time 120 "$B/?page_id=$ID" -o l_front2.html
t "reset: default look again"            "$([ "$(has l_front2.html '<section class="wpd-hero wpd-hero--dark">')$(grep -q 'wpd-page--btn-' l_front2.html && echo 0 || echo 1)" = "11" ] && echo 1 || echo 0)"
curl -s --max-time 60 "$B/wpd-h-page.php?cleanup=1" > /dev/null
echo
echo "PHASE L: PASS=$PASS FAIL=$FAIL"
[ "$FAIL" = "0" ]
