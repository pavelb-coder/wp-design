#!/bin/bash
# Фаза M (1.9.0): HTTP-проверки — новые поля вкладок «Оформление» и «Главный блок» (подразделы), настоящее сохранение обеих вкладок,
# страница сайта: шрифты Google и свой CSS в <head>, классы и переменные, иконки кнопок, таймер внутри главного блока без повтора.
# В конце сбрасывает опцию и удаляет тестовые страницы.
B=${WPD_BASE:-http://127.0.0.1:9400}
PASS=0; FAIL=0
t() { if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
has() { grep -q -- "$2" "$1" && echo 1 || echo 0; }
set_opt() { curl -s --max-time 60 "$B/wpd-set.php?json=$(python3 -c 'import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))' "$1")" > /dev/null; }
nonce() { grep -o 'name="_wpnonce" value="[^"]*"' "$1" | head -1 | sed 's/.*value="//;s/"//'; }
allf() { python3 -c "import re,sys;s=open(sys.argv[1],encoding='utf-8').read();print(1 if all(re.search(r'name=\"wpd_settings\[%s\]\"'%k,s) for k in sys.argv[2:]) else 0)" "$@"; }
FUTURE=$(python3 -c "import datetime;print((datetime.datetime.now(datetime.timezone.utc)+datetime.timedelta(hours=3,days=5)).strftime('%Y-%m-%dT%H:%M'))")

rm -f cjM.txt
curl -s -c cjM.txt -b cjM.txt -o /dev/null --max-time 60 -d "log=admin&pwd=password&wp-submit=Log+In&redirect_to=/wp-admin/&testcookie=1" "$B/wp-login.php"
get() { curl -s -c cjM.txt -b cjM.txt --max-time 120 "$B$1" -o "$2"; }
post() { curl -s -c cjM.txt -b cjM.txt -o /dev/null --max-time 120 -w "%{redirect_url}" "$@" "$B/wp-admin/options.php"; }

set_opt '{}'
get "/wp-admin/admin.php?page=wpd-settings&tab=design" m_design.html
t "design tab: 10 new fields"            "$(allf m_design.html heading_weight heading_scale line_height link_color button_text_color border_width section_title_align alt_sections google_fonts custom_css)"
t "design tab: section «Шрифты Google и свой CSS» last" "$(python3 -c "s=open('m_design.html',encoding='utf-8').read();a=s.find('[hide_title]');b=s.find('Шрифты Google и свой CSS');c=s.find('[custom_css]');print(1 if 0<a<b<c else 0)")"
t "design tab: weight options"           "$(has m_design.html "Полужирная (600)")"
get "/wp-admin/admin.php?page=wpd-settings&tab=hero" m_hero.html
t "hero tab: 14 new fields"              "$(allf m_hero.html hero_button_icon hero_button2_icon hero_countdown hero_image_fit hero_image_style hero_image_mobile hero_overlay_color hero_badge_color hero_title_color hero_text_color hero_min_height hero_padding hero_valign hero_text_width)"
t "hero tab: 3 subsections in order"     "$(python3 -c "s=open('m_hero.html',encoding='utf-8').read();a=s.find('>Текст и кнопки<');b=s.find('>Изображение<');c=s.find('>Вид главного блока<');print(1 if 0<a<b<c else 0)")"
t "hero tab: image shown on phone by default" "$(grep -oE '<input[^>]*name="wpd_settings\[hero_image_mobile\]"[^>]*>' m_hero.html | grep -q 'checked' && echo 1 || echo 0)"

# Сохранение «Оформления»
N=$(nonce m_design.html)
LOC=$(post --data-urlencode "option_page=wpd_settings_group" --data-urlencode "action=update" --data-urlencode "_wpnonce=$N" \
  --data-urlencode "_wp_http_referer=/wp-admin/admin.php?page=wpd-settings&tab=design" --data-urlencode "wpd_settings[_tab]=design" \
  --data-urlencode "wpd_settings[heading_underline]=1" --data-urlencode "wpd_settings[card_hover]=1" --data-urlencode "wpd_settings[hide_title]=1" \
  --data-urlencode "wpd_settings[heading_weight]=600" --data-urlencode "wpd_settings[heading_scale]=large" --data-urlencode "wpd_settings[line_height]=relaxed" \
  --data-urlencode "wpd_settings[link_color]=#0f766e" --data-urlencode "wpd_settings[button_text_color]=#111827" --data-urlencode "wpd_settings[border_width]=2" \
  --data-urlencode "wpd_settings[section_title_align]=center" --data-urlencode "wpd_settings[alt_sections]=1" \
  --data-urlencode "wpd_settings[google_fonts]=Montserrat:400,700
PT Serif" --data-urlencode "wpd_settings[custom_css]=.wpd-page .wpd-hero__title{letter-spacing:-.02em}</style>" \
  --data-urlencode "wpd_settings[font_heading]=Montserrat, sans-serif")
t "design save: redirect back"           "$(echo "$LOC" | grep -q 'tab=design' && echo 1 || echo 0)"
get "/wp-admin/admin.php?page=wpd-settings&tab=design&settings-updated=true" m_design2.html
t "design save: fonts and css stored (no «<»)" "$([ "$(has m_design2.html 'Montserrat:400,700')$(has m_design2.html 'letter-spacing:-.02em}/style&gt;')" = "11" ] && echo 1 || echo 0)"

# Сохранение «Главного блока»
get "/wp-admin/admin.php?page=wpd-settings&tab=hero" m_hero.html
N=$(nonce m_hero.html)
post --data-urlencode "option_page=wpd_settings_group" --data-urlencode "action=update" --data-urlencode "_wpnonce=$N" \
  --data-urlencode "_wp_http_referer=/wp-admin/admin.php?page=wpd-settings&tab=hero" --data-urlencode "wpd_settings[_tab]=hero" \
  --data-urlencode "wpd_settings[badge]=Олимпиада 2026" --data-urlencode "wpd_settings[hero_title]=Главный блок M" \
  --data-urlencode "wpd_settings[hero_button_text]=Положение" --data-urlencode "wpd_settings[hero_button_url]=https://example.com/rules" \
  --data-urlencode "wpd_settings[hero_button_icon]=media-document" --data-urlencode "wpd_settings[hero_countdown]=1" \
  --data-urlencode "wpd_settings[hero_image_url]=https://example.com/hall.jpg" --data-urlencode "wpd_settings[hero_image_position]=left" \
  --data-urlencode "wpd_settings[hero_image_fit]=contain" --data-urlencode "wpd_settings[hero_image_style]=shadow" \
  --data-urlencode "wpd_settings[hero_overlay]=55" --data-urlencode "wpd_settings[hero_style]=dark" --data-urlencode "wpd_settings[hero_badge_color]=#fde68a" \
  --data-urlencode "wpd_settings[hero_height]=normal" --data-urlencode "wpd_settings[hero_min_height]=560" --data-urlencode "wpd_settings[hero_padding]=24" \
  --data-urlencode "wpd_settings[hero_valign]=top" --data-urlencode "wpd_settings[hero_align]=left" --data-urlencode "wpd_settings[hero_text_width]=640" \
  --data-urlencode "wpd_settings[hero_title_size]=normal" > /dev/null
get "/wp-admin/admin.php?page=wpd-settings&tab=hero&settings-updated=true" m_hero2.html
t "hero save: icon selected"             "$(grep -qE '<option value="media-document"\s+selected' m_hero2.html && echo 1 || echo 0)"
t "hero save: unchecked phone image -> off" "$(grep -oE '<input[^>]*name="wpd_settings\[hero_image_mobile\]"[^>]*>' m_hero2.html | grep -q 'checked' && echo 0 || echo 1)"
t "hero save: design tab kept"           "$(get "/wp-admin/admin.php?page=wpd-settings&tab=design" m_design3.html; has m_design3.html 'Montserrat:400,700')"
# Таймер — настоящим сохранением вкладки «Таймер и эффекты»
get "/wp-admin/admin.php?page=wpd-settings&tab=features" m_feat.html
N=$(nonce m_feat.html)
post --data-urlencode "option_page=wpd_settings_group" --data-urlencode "action=update" --data-urlencode "_wpnonce=$N" \
  --data-urlencode "_wp_http_referer=/wp-admin/admin.php?page=wpd-settings&tab=features" --data-urlencode "wpd_settings[_tab]=features" \
  --data-urlencode "wpd_settings[countdown_enabled]=1" --data-urlencode "wpd_settings[countdown_date]=$FUTURE" \
  --data-urlencode "wpd_settings[countdown_label]=До начала M" > /dev/null

# Сайт: страница [wpd_page]
ID=$(curl -s --max-time 60 "$B/wpd-h-page.php?full=1")
curl -sL --max-time 120 "$B/?page_id=$ID" -o m_front.html
HEAD=$(python3 -c "s=open('m_front.html',encoding='utf-8').read();print(s[:s.find('</head>')])")
echo "$HEAD" > m_head.html
t "front: google fonts link in head"     "$(has m_head.html "id='wpd-google-fonts-css' href='https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&#038;family=PT+Serif&#038;display=swap'")"
t "front: fonts before wpd.css"          "$(python3 -c "s=open('m_head.html',encoding='utf-8').read();a=s.find('wpd-google-fonts-css');b=s.find('wpd-design-css');print(1 if 0<a<b else 0)")"
t "front: custom css inline after wpd.css" "$(python3 -c "s=open('m_head.html',encoding='utf-8').read();a=s.find(\"id='wpd-design-css'\");b=s.find('wpd-design-inline-css');c=s.find('.wpd-page .wpd-hero__title{letter-spacing:-.02em}/style>');print(1 if 0<a<b<c else 0)")"
t "front: page classes"                  "$(has m_front.html 'wpd-page--titles-center wpd-page--alt-sections wpd-page--link-color')"
t "front: look vars"                     "$([ "$(has m_front.html '--wpd-heading-weight:600;')$(has m_front.html '--wpd-h2-scale:1.2;')$(has m_front.html '--wpd-lh:1.8;')$(has m_front.html '--wpd-link:#0f766e;')$(has m_front.html '--wpd-btn-text:#111827;')$(has m_front.html '--wpd-border:2px;')" = "111111" ] && echo 1 || echo 0)"
HC=$(grep -oE '<section class="wpd-hero [^"]*"' m_front.html | head -1)
echo "  hero: $HC"
hc() { echo "$HC" | grep -q -- "$1" && echo 1 || echo 0; }
t "front: hero image classes"            "$([ "$(hc 'wpd-hero--img-left')$(hc 'wpd-hero--fit-contain')$(hc 'wpd-hero--imgstyle-shadow')$(hc 'wpd-hero--img-hide-mobile')" = "1111" ] && echo 1 || echo 0)"
t "front: hero layout classes"           "$([ "$(hc 'wpd-hero--valign-top')$(hc 'wpd-hero--minh')$(hc 'wpd-hero--pad')$(hc 'wpd-hero--text-w')$(hc 'wpd-hero--c-badge')" = "11111" ] && echo 1 || echo 0)"
t "front: no title/text colour classes"  "$([ "$(hc 'c-title')$(hc 'c-text')" = "00" ] && echo 1 || echo 0)"
t "front: hero vars"                     "$([ "$(has m_front.html '--wpd-hero-minh:560px;')$(has m_front.html '--wpd-hero-pad:24px;')$(has m_front.html '--wpd-hero-text-w:640px;')$(has m_front.html '--wpd-hero-badge:#fde68a;')$(has m_front.html '--wpd-hero-badge-fg:#0f172a;')" = "11111" ] && echo 1 || echo 0)"
t "front: button icon"                   "$(has m_front.html '<a class="wpd-btn" href="https://example.com/rules"><span class="wpd-btn__icon dashicons dashicons-media-document" aria-hidden="true"></span>Положение')"
t "front: dashicons loaded"              "$(has m_head.html "id='dashicons-css'")"
t "front: timer inside hero"             "$(python3 -c "
import re;s=open('m_front.html',encoding='utf-8').read();m=re.search(r'<section class=\"wpd-hero .*?</section>',s,re.S);print(1 if m and 'class=\"wpd-hero__countdown\"' in m.group(0) and 'До начала M' in m.group(0) else 0)")"
t "front: timer printed once"            "$([ "$(grep -o 'class="wpd-countdown"' m_front.html | wc -l)" = "1" ] && echo 1 || echo 0)"
t "front: no PHP warnings"               "$(grep -qE '<b>(Warning|Notice|Deprecated)</b>' m_front.html && echo 0 || echo 1)"
curl -s --max-time 60 "$B/wp-content/plugins/wp-olympiad-design/assets/css/wpd.css" -o m_css.css
t "css: 1.9.0 rules"                     "$([ "$(has m_css.css 'wpd-page--alt-sections')$(has m_css.css 'wpd-hero__countdown')$(has m_css.css 'wpd-btn__icon')$(has m_css.css 'wpd-hero--img-hide-mobile')$(has m_css.css 'var(--wpd-lh')$(has m_css.css 'var(--wpd-hero-minh')" = "111111" ] && echo 1 || echo 0)"

# Сброс: без шрифтов и своего CSS ничего лишнего в <head>
set_opt '{}'
curl -sL --max-time 120 "$B/?page_id=$ID" -o m_front2.html
t "reset: no fonts / inline css"         "$([ "$(grep -c 'fonts.googleapis.com' m_front2.html)$(grep -c 'wpd-design-inline-css' m_front2.html)" = "00" ] && echo 1 || echo 0)"
t "reset: timer back outside hero"       "$(grep -q 'wpd-hero__countdown' m_front2.html && echo 0 || echo 1)"
t "reset: default page classes"          "$(grep -qE 'wpd-page--(titles|alt|link)' m_front2.html && echo 0 || echo 1)"
curl -s --max-time 60 "$B/wpd-h-page.php?cleanup=1" > /dev/null
echo
echo "PHASE M: PASS=$PASS FAIL=$FAIL"
[ "$FAIL" = "0" ]
