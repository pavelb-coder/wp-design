#!/bin/bash
# Фаза AB (2.14.0): HTTP-проверки картинки вместо текста и ширины текста — вывод на сайте (wpd-hero--banner, невидимый h1,
# <picture>, ссылка, кнопки и таймер под картинкой, wpd-hero--banner-padded; wpd-look--align-justify, wpd-buttons--align-justify,
# wpd-hero--align-justify; wpd-tw и --wpd-tw), панель «Блоки олимпиады», CSS, поля на вкладках настроек и их сохранение через
# options.php, поля панели страницы, данные редактора, справка. Помощник — wpd-ab-helper.php.
B=${WPD_BASE:-http://127.0.0.1:9400}
PASS=0; FAIL=0
t() { if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
has() { grep -q -- "$2" "$1" && echo 1 || echo 0; }
hasnt() { grep -q -- "$2" "$1" && echo 0 || echo 1; }
eq() { [ "$1" = "$2" ] && echo 1 || echo 0; }
js() { python3 -c "import json,sys;d=json.load(open(sys.argv[1]));exec('print('+sys.argv[2]+')')" "$1" "$2" 2>/dev/null; }
nowarn() { grep -qE '<b>(Warning|Notice|Deprecated|Fatal error)</b>' "$1" && echo 0 || echo 1; }
all() { for v in "$@"; do [ "$v" = 1 ] || { echo 0; return; }; done; echo 1; }
# Блок по классу обёртки: разметка до следующего блока
part() { python3 - "$1" "$2" <<'PY'
import sys
s = open(sys.argv[1], encoding='utf-8').read()
i = s.find(' ' + sys.argv[2] + '"')
if i < 0:
    i = s.find(' ' + sys.argv[2] + ' ')
j = s.find('<div class="wpd-page wpd-page--block', i + 10)
print(s[i:j if j > 0 else len(s)] if i >= 0 else '')
PY
}
rm -f /tmp/pg/ab_*.html /tmp/pg/ab_*.json /tmp/pg/ab_*.css 2>/dev/null
curl -s --max-time 60 "$B/wpd-set.php?json=%7B%7D" > /dev/null
curl -sL --max-time 120 "$B/wpd-ab-helper.php?setup=1" > ab_setup.json
ID=$(js ab_setup.json 'd["id"]'); URL=$(js ab_setup.json 'd["url"]'); PID=$(js ab_setup.json 'd["pid"]'); PURL=$(js ab_setup.json 'd["purl"]')
IMG=$(js ab_setup.json 'd["img"]'); IMGM=$(js ab_setup.json 'd["imgm"]')
t "helper setup" "$( [ -n "$ID" ] && [ -n "$PID" ] && [ -n "$IMG" ] && echo 1 || echo 0)"
t "banner svg served" "$(eq "$(curl -s -o /dev/null -w '%{http_code}' --max-time 60 "$IMG")" 200)"

curl -sL --max-time 120 "$URL" -o ab_page.html
for k in ab-hero ab-hero-pad ab-hero-text ab-stats ab-faq ab-faq-left ab-buttons ab-faq-plain; do part ab_page.html $k > ab_$k.html; done
t "hero banner: classes, hidden h1" "$(all "$(has ab_ab-hero.html '<section class="wpd-hero wpd-hero--dark wpd-hero--banner"><h1 class="wpd-sr">Главный AB баннер</h1>')" "$(hasnt ab_ab-hero.html 'Скрытый текст')" "$(hasnt ab_ab-hero.html 'wpd-hero__inner')")"
t "hero banner: link, picture, alt" "$(has ab_ab-hero.html "<a class=\"wpd-hero__banner-link\" href=\"https://example.com/poster\"><picture><source media=\"(max-width: 600px)\" srcset=\"$IMGM\"><img class=\"wpd-hero__banner-img\" src=\"$IMG\" alt=\"Афиша олимпиады\" decoding=\"async\"></picture></a>")"
t "hero banner: buttons and timer below" "$(all "$(has ab_ab-hero.html '<div class="wpd-hero__below"><div class="wpd-hero__actions"><a class="wpd-btn" href="https://example.com/go">Участвовать')" "$(has ab_ab-hero.html '<div class="wpd-hero__countdown"><div class="wpd-countdown"')")"
t "hero padded: class, no below" "$(all "$(has ab_ab-hero-pad.html 'class="wpd-hero wpd-hero--dark wpd-hero--banner wpd-hero--banner-padded"')" "$(hasnt ab_ab-hero-pad.html 'wpd-hero__below')" "$(hasnt ab_ab-hero-pad.html '<picture>')")"
t "hero text: justify" "$(all "$(has ab_ab-hero-text.html 'class="wpd-hero wpd-hero--dark wpd-hero--align-justify"')" "$(has ab_ab-hero-text.html '<h1 class="wpd-hero__title">Обычный главный блок AB</h1>')")"
t "stats: text width + centre" "$(has ab_ab-stats.html '<section class="wpd-stats wpd-look wpd-look--align-center wpd-tw" style="--wpd-tw:400px;"')"
t "faq: justify + width" "$(has ab_ab-faq.html 'class="wpd-faq wpd-look wpd-look--align-justify wpd-tw" style="--wpd-tw:300px;"')"
t "faq left: width" "$(has ab_ab-faq-left.html 'class="wpd-faq wpd-look wpd-look--align-left wpd-tw" style="--wpd-tw:300px;"')"
t "buttons: justify + width" "$(all "$(has ab_ab-buttons.html 'wpd-buttons--align-justify')" "$(has ab_ab-buttons.html 'wpd-tw')" "$(has ab_ab-buttons.html '--wpd-tw:450px;')")"
t "faq default: unchanged" "$(all "$(has ab_ab-faq-plain.html '<section class="wpd-faq">')" "$(hasnt ab_ab-faq-plain.html 'wpd-tw')")"
t "front: no PHP warnings" "$(nowarn ab_page.html)"
curl -sL --max-time 120 "$PURL" -o ab_panel.html
t "panel page: hero banner padded" "$(all "$(has ab_panel.html 'class="wpd-hero wpd-hero--dark wpd-hero--banner wpd-hero--banner-padded"')" "$(has ab_panel.html "src=\"$IMG\" alt=\"Баннер панели\"")" "$(has ab_panel.html '<h1 class="wpd-sr">Олимпиада AB панель</h1>')")"
t "panel page: faq justify + width" "$(has ab_panel.html 'class="wpd-faq wpd-look wpd-look--align-justify wpd-tw" style="--wpd-tw:640px;"')"
curl -sL --max-time 60 "$B/wp-content/plugins/wp-olympiad-design/assets/css/wpd.css" -o ab_css.css
t "css served" "$(all "$(has ab_css.css 'max-width: var(--wpd-tw)')" "$(has ab_css.css 'wpd-hero__banner-img')" "$(has ab_css.css 'text-align: justify')")"

# Админка
login() { rm -f "$1"; curl -s -c "$1" -b "$1" -o /dev/null --max-time 60 "$B/wp-login.php"
  curl -s -c "$1" -b "$1" -o /dev/null --max-time 60 --data-urlencode "log=$2" --data-urlencode "pwd=$3" -d "wp-submit=Log+In&redirect_to=/wp-admin/&testcookie=1" "$B/wp-login.php"; }
login cjAB.txt admin password
get() { curl -s -c cjAB.txt -b cjAB.txt --max-time 180 "$B$1" -o "$2"; }
get "/wp-admin/admin.php?page=wpd-settings&tab=hero" ab_hero_tab.html
t "hero tab: banner section and fields" "$(all "$(has ab_hero_tab.html 'Картинка вместо текста')" "$(has ab_hero_tab.html 'wpd_settings\[hero_banner\]')" "$(has ab_hero_tab.html 'wpd_settings\[hero_banner_url\]')" "$(has ab_hero_tab.html 'wpd_settings\[hero_banner_mobile\]')" "$(has ab_hero_tab.html 'wpd_settings\[hero_banner_edge\]')" "$(has ab_hero_tab.html 'wpd_settings\[hero_banner_timer\]')" "$(has ab_hero_tab.html 'От края до края блока')")"
t "hero tab: justify option, no hero_text_w" "$(all "$(has ab_hero_tab.html '<option value="justify"')" "$(hasnt ab_hero_tab.html 'wpd_settings\[hero_text_w\]')")"
t "hero tab: no PHP warnings" "$(nowarn ab_hero_tab.html)"
get "/wp-admin/admin.php?page=wpd-settings&tab=faq" ab_faq_tab.html
t "faq tab: text width field, justify" "$(all "$(has ab_faq_tab.html 'wpd_settings\[faq_text_w\]')" "$(has ab_faq_tab.html 'Максимальная ширина текста, px')" "$(has ab_faq_tab.html 'По ширине')")"
get "/wp-admin/admin.php?page=wpd-settings&tab=slider" ab_slider_tab.html
t "slider tab: text width (slides)" "$(all "$(has ab_slider_tab.html 'wpd_settings\[slider_text_w\]')" "$(has ab_slider_tab.html 'слайдов')")"
get "/wp-admin/admin.php?page=wpd-settings&tab=help" ab_help.html
t "help: 2.14.0 section" "$(has ab_help.html 'Картинка вместо текста и ширина текста (2.14.0)')"
get "/wp-admin/post.php?post=$PID&action=edit" ab_edit.html
t "page panel: banner fields with saved values" "$(all "$(has ab_edit.html 'wpd_page\[ov\]\[hero_banner_url\]')" "$(has ab_edit.html 'wpd_page\[ov\]\[hero_banner_edge\]')" "$(has ab_edit.html 'wpd_page\[ov\]\[faq_text_w\]')" "$(python3 -c "import re;s=open('ab_edit.html',encoding='utf-8').read();m=re.search(r'<input[^>]*name=\"wpd_page\[ov\]\[faq_text_w\]\"[^>]*>',s);print(1 if m and 'value=\"640\"' in m.group(0) else 0)")")"
t "page panel: no PHP warnings" "$(nowarn ab_edit.html)"
get "/wp-admin/post.php?post=$ID&action=edit" ab_bedit.html
t "editor data: attributes and options" "$(all "$(has ab_bedit.html '"blockTextWidth"')" "$(has ab_bedit.html '"bannerOn"')" "$(has ab_bedit.html '"bannerEdge"')" "$(has ab_bedit.html '"justify"')")"
# Сохранение вкладки через options.php: ширина ограничивается, флажок «Картинка вместо текста» сохраняется
NONCE=$(python3 -c "import re;s=open('ab_hero_tab.html',encoding='utf-8').read();m=re.search(r'name=\"_wpnonce\" value=\"([^\"]+)\"',s);print(m.group(1) if m else '')")
curl -s -c cjAB.txt -b cjAB.txt --max-time 180 -o /dev/null -e "$B/wp-admin/admin.php?page=wpd-settings&tab=hero" \
  --data-urlencode "option_page=wpd_settings_group" --data-urlencode "action=update" --data-urlencode "_wpnonce=$NONCE" \
  --data-urlencode "wpd_settings[_tab]=hero" --data-urlencode "wpd_settings[hero_title]=Олимпиада AB" --data-urlencode "wpd_settings[hero_show_icons]=1" \
  --data-urlencode "wpd_settings[hero_image_mobile]=1" --data-urlencode "wpd_settings[hero_banner]=1" --data-urlencode "wpd_settings[hero_banner_url]=$IMG" \
  --data-urlencode "wpd_settings[hero_banner_edge]=sideways" --data-urlencode "wpd_settings[hero_banner_link]=javascript:alert(1)" \
  --data-urlencode "wpd_settings[hero_align]=justify" "$B/wp-admin/options.php"
get "/wp-admin/admin.php?page=wpd-settings&tab=hero" ab_hero_tab2.html
val() { python3 - "$1" "$2" <<'PY'
import re, sys
s = open(sys.argv[1], encoding='utf-8').read()
k = re.escape('wpd_settings[' + sys.argv[2] + ']')
m = re.search(r'<select[^>]*name="' + k + r'".*?</select>', s, re.S)
if m:
    o = re.search(r'<option value="([^"]*)"[^>]*selected', m.group(0)); print(o.group(1) if o else '')
else:
    m = re.search(r'<input[^>]*name="' + k + r'"[^>]*>', s)
    if m and 'type="checkbox"' in m.group(0):
        print('on' if 'checked' in m.group(0) else 'off')
    else:
        v = re.search(r'value="([^"]*)"', m.group(0)) if m else None; print(v.group(1) if v else '')
PY
}
t "settings save: hero values checked" "$(all "$(eq "$(val ab_hero_tab2.html hero_banner)" on)" "$(eq "$(val ab_hero_tab2.html hero_banner_url)" "$IMG")" "$(eq "$(val ab_hero_tab2.html hero_banner_edge)" edge)" "$(eq "$(val ab_hero_tab2.html hero_banner_link)" '')" "$(eq "$(val ab_hero_tab2.html hero_banner_timer)" off)" "$(eq "$(val ab_hero_tab2.html hero_align)" justify)")"
NONCE=$(python3 -c "import re;s=open('ab_faq_tab.html',encoding='utf-8').read();m=re.search(r'name=\"_wpnonce\" value=\"([^\"]+)\"',s);print(m.group(1) if m else '')")
curl -s -c cjAB.txt -b cjAB.txt --max-time 180 -o /dev/null -e "$B/wp-admin/admin.php?page=wpd-settings&tab=faq" \
  --data-urlencode "option_page=wpd_settings_group" --data-urlencode "action=update" --data-urlencode "_wpnonce=$NONCE" \
  --data-urlencode "wpd_settings[_tab]=faq" --data-urlencode "wpd_settings[faq_show_icons]=1" --data-urlencode "wpd_settings[faq_text_w]=7000" --data-urlencode "wpd_settings[faq_align]=justify" "$B/wp-admin/options.php"
get "/wp-admin/admin.php?page=wpd-settings&tab=faq" ab_faq_tab2.html
t "settings save: faq width clamped" "$(all "$(eq "$(val ab_faq_tab2.html faq_text_w)" 1600)" "$(eq "$(val ab_faq_tab2.html faq_align)" justify)")"
curl -sL --max-time 120 "$URL" -o ab_page2.html
part ab_page2.html ab-faq-plain > ab_faq_plain2.html
t "front after save: settings apply to plain block" "$(has ab_faq_plain2.html 'class="wpd-faq wpd-look wpd-look--align-justify wpd-tw" style="--wpd-tw:1600px;"')"
cat > /tmp/pg/wordpress/ab_sc.php <<'PHP'
<?php require __DIR__ . '/wp-load.php'; header('Content-Type: text/html; charset=utf-8'); echo do_shortcode('[wpd_hero]');
PHP
curl -sL --max-time 120 "$B/ab_sc.php" -o ab_sc.html
t "shortcode hero: settings banner" "$(all "$(has ab_sc.html 'wpd-hero--banner')" "$(has ab_sc.html "src=\"$IMG\"")" "$(has ab_sc.html '<h1 class="wpd-sr">Олимпиада AB</h1>')")"
rm -f /tmp/pg/wordpress/ab_sc.php

curl -sL --max-time 120 "$B/wpd-ab-helper.php?cleanup=1" > ab_clean.json
curl -s --max-time 60 "$B/wpd-set.php?json=%7B%7D" > /dev/null
t "cleanup" "$(eq "$(js ab_clean.json 'd["cleaned"]')" True)"
echo "PHASE AB: PASS=$PASS FAIL=$FAIL"
