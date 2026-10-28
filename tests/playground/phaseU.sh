#!/bin/bash
# Фаза U (2.7.0): HTTP-проверки — разделы «Раскладка и вид» / «Вид» и свои параметры на вкладках остальных блоков,
# новые поля строк списков (статистика, документы, FAQ), настоящее сохранение вкладок через options.php, страница сайта
# с блоками «Олимпиада: …» (атрибуты блока + общие настройки, скрытые и будущие элементы), данные редактора, группы
# панели страницы и их сохранение через мета-бокс (блок важнее страницы), ресурсы. Помощник — wpd-u-page.php.
B=${WPD_BASE:-http://127.0.0.1:9400}
PASS=0; FAIL=0
t() { if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
has() { grep -q -- "$2" "$1" && echo 1 || echo 0; }
hasnt() { grep -q -- "$2" "$1" && echo 0 || echo 1; }
val() { python3 - "$1" "$2" <<'PY'
import re, sys
s = open(sys.argv[1], encoding='utf-8').read()
m = re.search(r'name="' + re.escape(sys.argv[2]) + r'" value="([^"]*)"', s)
print(m.group(1) if m else '')
PY
}
js() { python3 -c "import json,sys;d=json.load(open(sys.argv[1]));exec('print('+sys.argv[2]+')')" "$1" "$2" 2>/dev/null; }
# Корень N-го блока с классом BASE: "классы|style"
root() { python3 - "$1" "$2" "$3" <<'PY'
import re, sys, html
s = open(sys.argv[1], encoding='utf-8').read()
base = re.escape(sys.argv[2]); i = int(sys.argv[3])
r = re.findall(r'<(?:section|div) class="(' + base + r'(?: [^"]*)?)"(?: style="([^"]*)")?', s)
print('NONE' if len(r) <= i else r[i][0] + '|' + html.unescape(r[i][1]))
PY
}
# Число уникальных полей wpd_settings[...] по регулярному выражению
nf() { grep -oE "name=\"wpd_settings\\[($2)\\]\"" "$1" | sort -u | wc -l | tr -d ' '; }
sel() { python3 - "$1" "$2" <<'PY'
import re, sys
s = open(sys.argv[1], encoding='utf-8').read()
m = re.search(r'name="' + re.escape(sys.argv[2]) + r'"[^>]*>(.*?)</select>', s, re.S)
v = re.search(r'<option value="([^"]*)"\s*selected', m.group(1)) if m else None
print(v.group(1) if v else '')
PY
}
edata() { python3 - "$1" "$2" <<'PY'
import json, sys
s = open(sys.argv[1], encoding='utf-8').read()
i = s.rfind('"' + sys.argv[2] + '":{')
if i < 0:
    print('{}'); sys.exit()
obj, _ = json.JSONDecoder().raw_decode(s[i + len(sys.argv[2]) + 3:])
print(json.dumps(obj, ensure_ascii=False, separators=(',', ':')))
PY
}
inq() { case "$1" in *"$2"*) echo 1;; *) echo 0;; esac; }
eq() { [ "$1" = "$2" ] && echo 1 || echo 0; }

curl -s --max-time 60 "$B/wpd-set.php?json=%7B%7D" > /dev/null
rm -f cjU.txt
curl -s -c cjU.txt -b cjU.txt -o /dev/null --max-time 60 "$B/wp-login.php"
curl -s -c cjU.txt -b cjU.txt -o /dev/null --max-time 60 -d "log=admin&pwd=password&wp-submit=Log+In&redirect_to=/wp-admin/&testcookie=1" "$B/wp-login.php"
get() { curl -s -c cjU.txt -b cjU.txt --max-time 180 "$B$1" -o "$2"; }

# Вкладки настроек
L='columns|columns_mobile|gap|align|variant|radius|text_size|bg|accent|text_color'
for tab in stats docs faq slider cards notice news contacts features; do get "/wp-admin/admin.php?page=wpd-settings&tab=$tab" "u_tab_$tab.html"; done
t "tab stats: 12 look + own fields" "$(eq "$(nf u_tab_stats.html "stats_($L|num_size|title)")" 12)"
t "tab docs: 12" "$(eq "$(nf u_tab_docs.html "docs_($L|new_tab|show_type)")" 12)"
t "tab faq: 11" "$(eq "$(nf u_tab_faq.html "faq_(gap|align|variant|radius|text_size|bg|accent|text_color|open_first|single|numbered)")" 11)"
t "tab slider: 8" "$(eq "$(nf u_tab_slider.html "slider_(align|radius|text_size|bg|accent|text_color|interval|caption_pos)")" 8)"
t "tab cards: 12" "$(eq "$(nf u_tab_cards.html "cards_($L|more_text|image_height)")" 12)"
t "tab notice: 9" "$(eq "$(nf u_tab_notice.html "notice_(align|variant|radius|text_size|accent|text_color|button_text|from|to)")" 9)"
t "tab news: 14" "$(eq "$(nf u_tab_news.html "news_($L|excerpt_words|show_image|show_date|show_excerpt)")" 14)"
t "tab contacts: 12" "$(eq "$(nf u_tab_contacts.html "contacts_($L|button_text|show_icons)")" 12)"
t "tab features: countdown look 8" "$(eq "$(nf u_tab_features.html "countdown_(align|variant|radius|text_size|bg|accent|text_color|value_size)")" 8)"
t "tab stats: section «Раскладка и вид»" "$(python3 -c "
import re;s=open('u_tab_stats.html',encoding='utf-8').read();print(1 if re.search(r'<h2>Статистика</h2>.*<h2>Раскладка и вид</h2>',s,re.S) else 0)")"
t "tab faq: section «Вид»" "$(has u_tab_faq.html '<h2>Вид</h2>')"
t "tab stats: row template sub-fields" "$(eq "$(grep -oE 'wpd_settings\[stats\]\[__i__\]\[(icon|color|hidden|from|to)\]' u_tab_stats.html | sort -u | wc -l | tr -d ' ')" 5)"
t "tab docs: row template sub-fields" "$(eq "$(grep -oE 'wpd_settings\[docs\]\[__i__\]\[(desc|badge|type|color|new_tab|download|hidden|from|to)\]' u_tab_docs.html | sort -u | wc -l | tr -d ' ')" 9)"
t "tab faq: row template sub-fields" "$(eq "$(grep -oE 'wpd_settings\[faq\]\[__i__\]\[(open|hidden|from|to)\]' u_tab_faq.html | sort -u | wc -l | tr -d ' ')" 4)"
t "tab docs: show type checked by default" "$(grep -qE 'name="wpd_settings\[docs_show_type\]" value="1"\s+checked' u_tab_docs.html && echo 1 || echo 0)"
t "tabs: no PHP warnings" "$(cat u_tab_*.html | grep -qE '<b>(Warning|Notice|Deprecated|Fatal error)</b>' && echo 0 || echo 1)"

# Сохранение вкладки «Статистика»
N=$(val u_tab_stats.html _wpnonce)
save() { local tab=$1 n=$2; shift 2
  curl -s -c cjU.txt -b cjU.txt -o /dev/null --max-time 120 -w "%{redirect_url}" \
    --data-urlencode "option_page=wpd_settings_group" --data-urlencode "action=update" --data-urlencode "_wpnonce=$n" \
    --data-urlencode "_wp_http_referer=/wp-admin/admin.php?page=wpd-settings&tab=$tab" --data-urlencode "wpd_settings[_tab]=$tab" "$@" "$B/wp-admin/options.php"; }
LOC=$(save stats "$N" --data-urlencode "wpd_settings[stats_title]=Ключевые цифры" --data-urlencode "wpd_settings[stats_columns]=4" \
  --data-urlencode "wpd_settings[stats_columns_mobile]=2" --data-urlencode "wpd_settings[stats_gap]=12" --data-urlencode "wpd_settings[stats_variant]=outline" \
  --data-urlencode "wpd_settings[stats_radius]=small" --data-urlencode "wpd_settings[stats_bg]=#0b1220" --data-urlencode "wpd_settings[stats_num_size]=48" \
  --data-urlencode "wpd_settings[stats][_]=" \
  --data-urlencode "wpd_settings[stats][0][num]=100" --data-urlencode "wpd_settings[stats][0][label]=первая" --data-urlencode "wpd_settings[stats][0][icon]=awards" \
  --data-urlencode "wpd_settings[stats][0][color]=#1e3a8a" \
  --data-urlencode "wpd_settings[stats][1][num]=2" --data-urlencode "wpd_settings[stats][1][label]=скрытая" --data-urlencode "wpd_settings[stats][1][hidden]=1" \
  --data-urlencode "wpd_settings[stats][2][num]=3" --data-urlencode "wpd_settings[stats][2][label]=будущая" --data-urlencode "wpd_settings[stats][2][from]=2099-01-01T10:00")
t "save stats: redirect back" "$(inq "$LOC" 'tab=stats')"
get "/wp-admin/admin.php?page=wpd-settings&tab=stats" u_tab_stats2.html
t "saved: title, gap, num size" "$( [ "$(has u_tab_stats2.html 'name="wpd_settings\[stats_title\]" value="Ключевые цифры"')" = 1 ] && [ "$(has u_tab_stats2.html 'name="wpd_settings\[stats_gap\]" value="12"')" = 1 ] && [ "$(has u_tab_stats2.html 'name="wpd_settings\[stats_num_size\]" value="48"')" = 1 ] && echo 1 || echo 0)"
t "saved: selects" "$(eq "$(sel u_tab_stats2.html 'wpd_settings[stats_columns]'),$(sel u_tab_stats2.html 'wpd_settings[stats_columns_mobile]'),$(sel u_tab_stats2.html 'wpd_settings[stats_variant]'),$(sel u_tab_stats2.html 'wpd_settings[stats_radius]')" "4,2,outline,small")"
t "saved: bg color" "$(has u_tab_stats2.html 'name="wpd_settings\[stats_bg\]" value="#0b1220"')"
t "saved: row icon and color" "$( [ "$(sel u_tab_stats2.html 'wpd_settings[stats][0][icon]')" = awards ] && [ "$(has u_tab_stats2.html 'name="wpd_settings\[stats\]\[0\]\[color\]" value="#1e3a8a"')" = 1 ] && echo 1 || echo 0)"
t "saved: row hidden checked" "$(grep -qE 'name="wpd_settings\[stats\]\[1\]\[hidden\]" value="1"\s+checked' u_tab_stats2.html && echo 1 || echo 0)"
t "saved: row date back with T" "$(has u_tab_stats2.html 'name="wpd_settings\[stats\]\[2\]\[from\]" value="2099-01-01T10:00"')"

# Сохранение вкладки «Частые вопросы» (флажки) и «Документы» (без значка типа)
N=$(val u_tab_faq.html _wpnonce)
save faq "$N" --data-urlencode "wpd_settings[faq_title]=Вопросы U" --data-urlencode "wpd_settings[faq_single]=1" --data-urlencode "wpd_settings[faq_numbered]=1" \
  --data-urlencode "wpd_settings[faq][_]=" --data-urlencode "wpd_settings[faq][0][q]=Как?" --data-urlencode "wpd_settings[faq][0][a]=Так" \
  --data-urlencode "wpd_settings[faq][0][open]=1" > /dev/null
get "/wp-admin/admin.php?page=wpd-settings&tab=faq" u_tab_faq2.html
t "saved faq: single, numbered checked; open first not" "$( grep -qE 'name="wpd_settings\[faq_single\]" value="1"\s+checked' u_tab_faq2.html && grep -qE 'name="wpd_settings\[faq_numbered\]" value="1"\s+checked' u_tab_faq2.html && ! grep -qE 'name="wpd_settings\[faq_open_first\]" value="1"\s+checked' u_tab_faq2.html && echo 1 || echo 0)"
t "saved faq: row open checked" "$(grep -qE 'name="wpd_settings\[faq\]\[0\]\[open\]" value="1"\s+checked' u_tab_faq2.html && echo 1 || echo 0)"
get "/wp-admin/admin.php?page=wpd-settings&tab=stats" u_tab_stats3.html
t "other tab kept after faq save" "$(has u_tab_stats3.html 'name="wpd_settings\[stats_gap\]" value="12"')"

# Страница сайта
curl -sL --max-time 120 "$B/wpd-u-page.php?reset=1" > u_info.json
ID=$(js u_info.json 'd["id"]'); URL=$(js u_info.json 'd["url"]')
t "helper: page created" "$( [ -n "$ID" ] && [ -n "$URL" ] && echo 1 || echo 0)"
curl -sL --max-time 120 "$URL" -o u_front.html
t "front: stats — attributes + settings" "$(eq "$(root u_front.html wpd-stats 0)" 'wpd-stats wpd-look wpd-look--cols wpd-look--cols-m wpd-look--gap wpd-look--solid wpd-look--radius wpd-look--bg wpd-look--ondark|--wpd-bl-cols:3;--wpd-bl-cols-t:2;--wpd-bl-cols-m:1;--wpd-bl-gap:12px;--wpd-radius:6px;--wpd-bl-bg:#0b1220;--wpd-bl-num:56px;')"
t "front: stats title from block, icon, light item" "$( [ "$(has u_front.html '<h2 class="wpd-h2">Цифры U</h2>')" = 1 ] && [ "$(has u_front.html 'wpd-stat__icon dashicons dashicons-awards')" = 1 ] && [ "$(has u_front.html 'class="wpd-stat has-color wpd-it--light" style="--wpd-it-bg:#fef3c7;"')" = 1 ] && echo 1 || echo 0)"
t "front: hidden and future stats not shown" "$( [ "$(hasnt u_front.html '>скрытая<')" = 1 ] && [ "$(hasnt u_front.html '>будущая<')" = 1 ] && [ "$(has u_front.html '>школьников<')" = 1 ] && echo 1 || echo 0)"
t "front: docs look" "$(eq "$(root u_front.html wpd-docs 0)" 'wpd-docs wpd-look wpd-look--gap wpd-look--card wpd-look--onlight|--wpd-bl-gap:24px;')"
t "front: doc desc, badge, color, download" "$(python3 -c "
s=open('u_front.html',encoding='utf-8').read()
print(1 if 'class=\"wpd-doc has-icon-color\" href=\"https://example.com/u.pdf\" download style=\"--wpd-it-icon:#15803d;\"' in s and '<span class=\"wpd-doc__desc\">PDF, 1 МБ</span>' in s and '<span class=\"wpd-it-badge wpd-it-badge--inline\">Новое</span>' in s else 0)")"
t "front: doc own type, new tab" "$( [ "$(has u_front.html '>САЙТ</span>')" = 1 ] && grep -qE 'href="https://example.com/" target="_blank"' u_front.html && echo 1 || echo 0)"
t "front: faq block" "$(eq "$(root u_front.html wpd-faq 0)" 'wpd-faq wpd-faq--numbered wpd-look wpd-look--tint wpd-look--fs wpd-look--onlight|--wpd-bl-fs:18px;')"
t "front: faq single + first open" "$( [ "$(grep -o 'data-wpd-single' u_front.html | wc -l)" -ge 1 ] && grep -q '<details class="wpd-faq__item" open><summary class="wpd-faq__q">Первый вопрос U</summary>' u_front.html && echo 1 || echo 0)"
t "front: faq without attributes uses settings" "$(eq "$(root u_front.html wpd-faq 1)" 'wpd-faq wpd-faq--numbered|')"
t "front: cards" "$(eq "$(root u_front.html wpd-cards 0)" 'wpd-cards wpd-cards--img-h wpd-look wpd-look--cols wpd-look--outline wpd-look--onlight|--wpd-bl-cols:2;--wpd-bl-cols-t:2;--wpd-bl-img-h:140px;')"
t "front: card icon, badge, more texts" "$( [ "$(has u_front.html 'wpd-card__icon dashicons dashicons-edit')" = 1 ] && [ "$(has u_front.html '<span class="wpd-it-badge">Скоро</span>')" = 1 ] && [ "$(has u_front.html '>Подробнее &rarr;</span>')" = 1 ] && [ "$(has u_front.html '>Смотреть &rarr;</span>')" = 1 ] && echo 1 || echo 0)"
t "front: slider caption + look" "$(inq "$(root u_front.html wpd-slider 0)" 'wpd-slider wpd-slider--cap-center wpd-look wpd-look--bg wpd-look--ondark')"
t "front: slide badge + button" "$( [ "$(has u_front.html '<span class="wpd-slide__badge">Финал</span>')" = 1 ] && [ "$(has u_front.html 'wpd-btn wpd-slide__btn">Узнать &rarr;</span>')" = 1 ] && echo 1 || echo 0)"
t "front: notice" "$(eq "$(root u_front.html wpd-notice 0)" 'wpd-notice wpd-notice--text wpd-look wpd-look--outline wpd-look--onlight|')"
t "front: notice button" "$(has u_front.html '<p class="wpd-notice__actions"><a class="wpd-btn wpd-notice__btn" href="https://example.com/n">Читать &rarr;</a></p>')"
t "front: countdown" "$(eq "$(root u_front.html wpd-countdown 0)" 'wpd-countdown wpd-look wpd-look--card wpd-look--onlight wpd-countdown--size|--wpd-bl-val:40px;')"
t "front: contacts" "$(eq "$(root u_front.html wpd-contacts 0)" 'wpd-contacts wpd-contacts--noicons wpd-look wpd-look--cols wpd-look--tint wpd-look--onlight|--wpd-bl-cols:2;--wpd-bl-cols-t:2;')"
t "front: contacts button, no icons" "$( [ "$(has u_front.html '>Написать &rarr;</a>')" = 1 ] && [ "$(hasnt u_front.html 'wpd-contacts__icon')" = 1 ] && echo 1 || echo 0)"
t "front: news without images" "$( [ "$(inq "$(root u_front.html wpd-news 0)" 'wpd-news wpd-news--grid wpd-news--noimg')" = 1 ] && [ "$(hasnt u_front.html 'wpd-news__media')" = 1 ] && echo 1 || echo 0)"
t "front: no PHP warnings" "$(grep -qE '<b>(Warning|Notice|Deprecated|Fatal error)</b>' u_front.html && echo 0 || echo 1)"

# Экран редактирования
get "/wp-admin/post.php?post=$ID&action=edit" u_edit.html
LS=$(edata u_edit.html lookSettings)
t "edit: lookSettings from settings" "$( [ "$(inq "$LS" '"stats":{"columns":"4","columnsMobile":"2","gap":12,"textAlign":"","variant":"outline","radius":"small"')" = 1 ] && [ "$(inq "$LS" '"title":"Ключевые цифры","numSize":48}')" = 1 ] && [ "$(inq "$LS" '"single":true,"numbered":true')" = 1 ] && echo 1 || echo 0)"
t "edit: lookOptions" "$(inq "$(edata u_edit.html lookOptions)" '"variant":{"label":"Вид карточек","type":"select","group":"look"')"
t "edit: client defaults = settings" "$(has u_edit.html '"numSize":{"type":"integer","default":48}')"
t "edit: icons for item select" "$(has u_edit.html '"icons":{"media-document":')"
t "edit: page groups stats/docs/faq" "$( [ "$(grep -oE 'data-wpd-ovg="(stats|docs|faq)"' u_edit.html | sort -u | wc -l | tr -d ' ')" = 3 ] && echo 1 || echo 0)"
t "edit: page panel look fields" "$(eq "$(grep -oE 'id="wpd_pe_ov_stats_('"$L"'|num_size|title)"' u_edit.html | sort -u | wc -l | tr -d ' ')" 12)"
t "edit: list rows with «Ещё»" "$( [ "$(grep -o '<details class="wpd-pe__more"><summary>Ещё' u_edit.html | wc -l)" -ge 3 ] && echo 1 || echo 0)"
WPN=$(val u_edit.html _wpnonce); PN=$(val u_edit.html wpd_page_nonce); DN=$(val u_edit.html wpd_nonce)
MBURL=$(grep -o 'var _wpMetaBoxUrl = "[^"]*"' u_edit.html | sed 's/.*= "//;s/"$//')
t "edit: nonces found" "$( [ -n "$WPN" ] && [ -n "$PN" ] && [ -n "$DN" ] && [ -n "$MBURL" ] && echo 1 || echo 0)"

# Группа «Статистика» и свой список FAQ страницы через мета-бокс
ARGS=()
for k in hero countdown content stats docs faq slider buttons notice cards news contacts; do ARGS+=(--data-urlencode "wpd_page[order][]=$k"); done
CODE=$(curl -s -c cjU.txt -b cjU.txt -o /dev/null --max-time 180 -w "%{http_code}" \
  --data-urlencode "_wpnonce=$WPN" --data-urlencode "_wp_http_referer=/wp-admin/post.php?post=$ID&action=edit" \
  --data-urlencode "user_ID=1" --data-urlencode "action=editpost" --data-urlencode "originalaction=editpost" \
  --data-urlencode "post_type=page" --data-urlencode "original_post_status=publish" --data-urlencode "post_ID=$ID" \
  --data-urlencode "wpd_nonce=$DN" --data-urlencode "wpd_enabled=1" --data-urlencode "wpd_page_nonce=$PN" \
  "${ARGS[@]}" --data-urlencode "wpd_page[show][content]=1" \
  --data-urlencode "wpd_page[ovg][stats]=1" --data-urlencode "wpd_page[ov][stats_variant]=plain" --data-urlencode "wpd_page[ov][stats_gap]=30" \
  --data-urlencode "wpd_page[ov][stats_title]=Для страницы" --data-urlencode "wpd_page[ov][stats_bg]=" \
  --data-urlencode "wpd_page[faq_mode]=custom" --data-urlencode "wpd_page[faq_title]=" \
  --data-urlencode "wpd_page[faq][0][q]=Вопрос страницы" --data-urlencode "wpd_page[faq][0][a]=Ответ" --data-urlencode "wpd_page[faq][0][open]=1" \
  --data-urlencode "wpd_page[faq][0][to]=2099-01-01T00:00" \
  "$MBURL")
t "metabox save: accepted" "$( [ "$CODE" = "302" ] || [ "$CODE" = "200" ] && echo 1 || echo 0)"
get "/wp-admin/post.php?post=$ID&action=edit" u_edit2.html
t "metabox: page values shown" "$( [ "$(has u_edit2.html 'name="wpd_page\[ov\]\[stats_title\]" value="Для страницы"')" = 1 ] && [ "$(sel u_edit2.html 'wpd_page[ov][stats_variant]')" = plain ] && echo 1 || echo 0)"
t "metabox: faq row open checked, date with T, flagged" "$( grep -qE 'name="wpd_page\[faq\]\[0\]\[open\]" value="1"[^>]*checked' u_edit2.html && grep -q 'name="wpd_page\[faq\]\[0\]\[to\]" value="2099-01-01T00:00"' u_edit2.html && grep -q 'wpd-pe__row is-flagged' u_edit2.html && echo 1 || echo 0)"
t "edit: editor data follows page" "$(inq "$(edata u_edit2.html lookSettings)" '"gap":30,"textAlign":"","variant":"plain"')"
curl -sL --max-time 120 "$URL" -o u_front2.html
t "front: block attribute beats page, page beats settings" "$(eq "$(root u_front2.html wpd-stats 0)" 'wpd-stats wpd-look wpd-look--cols wpd-look--cols-m wpd-look--gap wpd-look--solid wpd-look--radius wpd-look--ondark|--wpd-bl-cols:3;--wpd-bl-cols-t:2;--wpd-bl-cols-m:1;--wpd-bl-gap:30px;--wpd-radius:6px;--wpd-bl-num:56px;')"
t "front: block title beats page title" "$(has u_front2.html '<h2 class="wpd-h2">Цифры U</h2>')"

# Ресурсы
CSS=$(grep -oE "[^'\"]*wp-olympiad-design/assets/css/wpd\.css[^'\"]*" u_front.html | head -1 | sed 's/&#038;/\&/g;s/&amp;/\&/g')
curl -s --max-time 60 "$CSS" -o u_wpd.css
t "css served with 2.7.0 rules" "$( [ "$(has u_wpd.css '2.7.0: «Раскладка и вид»')" = 1 ] && [ "$(has u_wpd.css 'wpd-look--ondark')" = 1 ] && echo 1 || echo 0)"
JSU=$(grep -oE "[^'\"]*wp-olympiad-design/assets/js/wpd\.js[^'\"]*" u_front.html | head -1 | sed 's/&#038;/\&/g;s/&amp;/\&/g')
curl -s --max-time 60 "$JSU" -o u_wpd.js
t "front js served with faq single" "$(has u_wpd.js 'initFaqSingle')"

# Уборка
curl -sL --max-time 60 "$B/wpd-u-page.php?cleanup=1" > u_clean.json
t "cleanup" "$(js u_clean.json '1 if d["cleaned"] else 0')"
curl -s --max-time 60 "$B/wpd-set.php?json=%7B%7D" > /dev/null
echo; echo "TOTAL: $((PASS+FAIL)), FAILED: $FAIL"
