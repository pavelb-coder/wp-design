#!/bin/bash
# Фаза AA (2.13.0): HTTP-проверки высоты блоков и иконок — вывод на сайте (классы wpd-bh, wpd-bh--center|bottom, --wpd-bh,
# wpd-noicons, строки без иконки), панель «Блоки олимпиады», CSS, поля на вкладках настроек и их сохранение через options.php,
# поля панели страницы, данные редактора, справка. Помощник — wpd-aa-helper.php.
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
rm -f /tmp/pg/aa_*.html /tmp/pg/aa_*.json /tmp/pg/aa_*.css 2>/dev/null
curl -s --max-time 60 "$B/wpd-set.php?json=%7B%7D" > /dev/null
curl -sL --max-time 120 "$B/wpd-aa-helper.php?setup=1" > aa_setup.json
ID=$(js aa_setup.json 'd["id"]'); URL=$(js aa_setup.json 'd["url"]'); PID=$(js aa_setup.json 'd["pid"]'); PURL=$(js aa_setup.json 'd["purl"]')
t "helper setup" "$( [ -n "$ID" ] && [ -n "$PID" ] && echo 1 || echo 0)"

curl -sL --max-time 120 "$URL" -o aa_page.html
for k in aa-hero aa-stats aa-stats-plain aa-faq aa-faq-plain aa-buttons aa-docs aa-cards aa-cd aa-partners; do part aa_page.html $k > aa_$k.html; done
t "stats: height, centre, no icons" "$(all "$(has aa_aa-stats.html '<section class="wpd-stats wpd-bh wpd-bh--center wpd-noicons" style="--wpd-bh:500px;"')" "$(hasnt aa_aa-stats.html 'wpd-stat__icon')" "$(has aa_aa-stats.html 'городов')")"
t "stats default: markup unchanged" "$(all "$(has aa_aa-stats-plain.html '<section class="wpd-stats" ')" "$(has aa_aa-stats-plain.html 'wpd-stat__icon dashicons dashicons-welcome-learn-more')")"
t "faq: 1000 px bottom, no icons" "$(has aa_aa-faq.html 'class="wpd-faq wpd-bh wpd-bh--bottom wpd-noicons" style="--wpd-bh:1000px;"')"
t "faq default: no new classes" "$(all "$(has aa_aa-faq-plain.html 'class="wpd-faq"')" "$(hasnt aa_aa-faq-plain.html 'wpd-noicons')")"
t "buttons: height + one row without icon" "$(all "$(has aa_aa-buttons.html 'wpd-bh wpd-bh--center')" "$(has aa_aa-buttons.html '--wpd-bh:360px;')" "$(eq "$(grep -o 'wpd-qbtn__icon' aa_aa-buttons.html | wc -l)" 1)" "$(has aa_aa-buttons.html 'class="wpd-qbtn wpd-qbtn--noicon"')")"
t "docs: one row without badge" "$(all "$(eq "$(grep -o 'wpd-doc__icon' aa_aa-docs.html | wc -l)" 1)" "$(has aa_aa-docs.html 'wpd-doc--noicon')")"
t "cards: one card without icon and arrow" "$(all "$(eq "$(grep -o 'wpd-card__icon' aa_aa-cards.html | wc -l)" 1)" "$(eq "$(grep -o 'wpd-card__more' aa_aa-cards.html | wc -l)" 1)")"
t "countdown: height + centre" "$(all "$(has aa_aa-cd.html 'wpd-countdown wpd-bh wpd-bh--center')" "$(has aa_aa-cd.html '--wpd-bh:300px;')" "$(has aa_aa-cd.html 'Задания &rarr;</a>')")"
t "partners: height + bottom" "$(all "$(has aa_aa-partners.html 'wpd-bh wpd-bh--bottom')" "$(has aa_aa-partners.html '--wpd-bh:300px;')")"
t "hero: icons by default" "$(all "$(has aa_aa-hero.html 'dashicons-awards')" "$(has aa_aa-hero.html 'Старт AA &rarr;')")"
t "front: no PHP warnings" "$(nowarn aa_page.html)"
curl -sL --max-time 120 "$PURL" -o aa_panel.html
t "panel page: own height, bottom, no icons" "$(all "$(has aa_panel.html 'class="wpd-stats wpd-bh wpd-bh--bottom wpd-noicons" style="--wpd-bh:640px;"')" "$(hasnt aa_panel.html 'wpd-stat__icon')")"
curl -sL --max-time 60 "$B/wp-content/plugins/wp-olympiad-design/assets/css/wpd.css" -o aa_css.css
t "css served" "$(all "$(has aa_css.css 'min-height: var(--wpd-bh)')" "$(has aa_css.css 'wpd-qbtn--noicon')")"

# Админка
login() { rm -f "$1"; curl -s -c "$1" -b "$1" -o /dev/null --max-time 60 "$B/wp-login.php"
  curl -s -c "$1" -b "$1" -o /dev/null --max-time 60 --data-urlencode "log=$2" --data-urlencode "pwd=$3" -d "wp-submit=Log+In&redirect_to=/wp-admin/&testcookie=1" "$B/wp-login.php"; }
login cjAA.txt admin password
get() { curl -s -c cjAA.txt -b cjAA.txt --max-time 180 "$B$1" -o "$2"; }
get "/wp-admin/admin.php?page=wpd-settings&tab=faq" aa_faq_tab.html
t "faq tab: height, valign, icons fields" "$(all "$(has aa_faq_tab.html 'wpd_settings\[faq_min_h\]')" "$(has aa_faq_tab.html 'wpd_settings\[faq_valign\]')" "$(has aa_faq_tab.html 'wpd_settings\[faq_show_icons\]')" "$(has aa_faq_tab.html 'Минимальная высота блока, px')" "$(has aa_faq_tab.html 'значки раскрытия вопросов')")"
t "faq tab: no PHP warnings" "$(nowarn aa_faq_tab.html)"
get "/wp-admin/admin.php?page=wpd-settings&tab=hero" aa_hero_tab.html
t "hero tab: icons field only" "$(all "$(has aa_hero_tab.html 'wpd_settings\[hero_show_icons\]')" "$(hasnt aa_hero_tab.html 'wpd_settings\[hero_min_h\]')")"
get "/wp-admin/admin.php?page=wpd-settings&tab=slider" aa_slider_tab.html
t "slider tab: icons field, no block height" "$(all "$(has aa_slider_tab.html 'wpd_settings\[slider_show_icons\]')" "$(hasnt aa_slider_tab.html 'wpd_settings\[slider_min_h\]')")"
get "/wp-admin/admin.php?page=wpd-settings&tab=buttons" aa_buttons_tab.html
t "buttons tab: fields in layout section and row flag" "$(all "$(has aa_buttons_tab.html 'wpd_settings\[buttons_min_h\]')" "$(has aa_buttons_tab.html 'Без иконки')")"
get "/wp-admin/admin.php?page=wpd-settings&tab=docs" aa_docs_tab.html
t "docs tab: row flag label" "$(has aa_docs_tab.html 'Без значка')"
get "/wp-admin/admin.php?page=wpd-settings&tab=help" aa_help.html
t "help: 2.13.0 section" "$(has aa_help.html 'Высота блоков и иконки (2.13.0)')"
get "/wp-admin/post.php?post=$PID&action=edit" aa_edit.html
t "page panel: box fields with saved values" "$(all "$(has aa_edit.html 'wpd_page\[ov\]\[stats_min_h\]')" "$(has aa_edit.html 'wpd_page\[ov\]\[stats_show_icons\]')" "$(python3 -c "import re;s=open('aa_edit.html',encoding='utf-8').read();m=re.search(r'<input[^>]*name=\"wpd_page\[ov\]\[stats_min_h\]\"[^>]*>',s);print(1 if m and 'value=\"640\"' in m.group(0) else 0)")")"
t "page panel: no PHP warnings" "$(nowarn aa_edit.html)"
get "/wp-admin/post.php?post=$ID&action=edit" aa_bedit.html
t "editor data: box attributes and group" "$(all "$(has aa_bedit.html '"blockMinHeight"')" "$(has aa_bedit.html '"blockVAlign"')" "$(has aa_bedit.html '"group":"box"')")"
# Сохранение вкладки через options.php: числа ограничиваются, флажок «Показывать иконки» без значения снимается
NONCE=$(python3 -c "import re;s=open('aa_faq_tab.html',encoding='utf-8').read();m=re.search(r'name=\"_wpnonce\" value=\"([^\"]+)\"',s);print(m.group(1) if m else '')")
curl -s -c cjAA.txt -b cjAA.txt --max-time 180 -o /dev/null -e "$B/wp-admin/admin.php?page=wpd-settings&tab=faq" \
  --data-urlencode "option_page=wpd_settings_group" --data-urlencode "action=update" --data-urlencode "_wpnonce=$NONCE" \
  --data-urlencode "wpd_settings[_tab]=faq" --data-urlencode "wpd_settings[faq_min_h]=4000" --data-urlencode "wpd_settings[faq_valign]=nowhere" "$B/wp-admin/options.php"
get "/wp-admin/admin.php?page=wpd-settings&tab=faq" aa_faq_tab2.html
val() { python3 - aa_faq_tab2.html "$1" <<'PY'
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
t "settings save: values checked" "$(all "$(eq "$(val faq_min_h)" 1200)" "$(eq "$(val faq_valign)" '')" "$(eq "$(val faq_show_icons)" off)")"
curl -sL --max-time 120 "$URL" -o aa_page2.html
part aa_page2.html aa-faq-plain > aa_faq_plain2.html
t "front after save: settings apply to plain block" "$(has aa_faq_plain2.html 'class="wpd-faq wpd-bh wpd-noicons" style="--wpd-bh:1200px;"')"

curl -sL --max-time 120 "$B/wpd-aa-helper.php?cleanup=1" > aa_clean.json
curl -s --max-time 60 "$B/wpd-set.php?json=%7B%7D" > /dev/null
t "cleanup" "$(eq "$(js aa_clean.json 'd["cleaned"]')" True)"
echo "PHASE AA: PASS=$PASS FAIL=$FAIL"
