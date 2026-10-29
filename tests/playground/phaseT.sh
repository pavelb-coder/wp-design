#!/bin/bash
# Фаза T (2.6.0): HTTP-проверки — параметры кнопок-ссылок на вкладке настроек и их настоящее сохранение через options.php
# (с кнопками, датами показа и цветами), страница сайта с двумя блоками (общие настройки и атрибуты блока, скрытые и
# показываемые по датам кнопки), сохранение группы страницы через мета-бокс, данные редактора; защита ответов REST:
# мусорный вывод на init (mu-plugin помощника) не портит JSON ответов чтения и сохранения страницы, контрольный запрос
# без защиты (X-WPD-NoGuard) портит, перехваченный текст виден администратору в уведомлении и скрывается ссылкой.
# Помощник — wpd-t-page.php.
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
isjson() { python3 -c "import json,sys;json.load(open(sys.argv[1]));print(1)" "$1" 2>/dev/null || echo 0; }
# N-й блок кнопок на странице: "классы|style|тексты кнопок через запятую"
nav() { python3 - "$1" "$2" <<'PY'
import re, sys, html
s = open(sys.argv[1], encoding='utf-8').read()
navs = re.findall(r'<nav class="(wpd-buttons[^"]*)"(?: style="([^"]*)")? aria-label="[^"]*">(.*?)</nav>', s, re.S)
i = int(sys.argv[2])
if len(navs) <= i:
    print('NONE')
else:
    c, st, body = navs[i]
    print(c + '|' + html.unescape(st) + '|' + ','.join(re.findall(r'<span class="wpd-qbtn__text">([^<]*)<', body)))
PY
}
# Последний объект "ключ":{…} из инлайн-данных редактора (кириллица экранирована) — как JSON
edata() { python3 - "$1" "$2" <<'PY'
import json, re, sys
s = open(sys.argv[1], encoding='utf-8').read()
# последний: данные страницы (editor_page_data) добавляются после общих и заменяют их через Object.assign
i = s.rfind('"' + sys.argv[2] + '":{')
if i < 0:
    print('{}'); sys.exit()
dec = json.JSONDecoder()
obj, _ = dec.raw_decode(s[i + len(sys.argv[2]) + 3:])
print(json.dumps(obj, ensure_ascii=False, separators=(',', ':')))
PY
}
inq() { case "$1" in *"$2"*) echo 1;; *) echo 0;; esac; }

curl -s --max-time 60 "$B/wpd-set.php?json=%7B%7D" > /dev/null
rm -f cjT.txt
curl -s -c cjT.txt -b cjT.txt -o /dev/null --max-time 60 "$B/wp-login.php"
curl -s -c cjT.txt -b cjT.txt -o /dev/null --max-time 60 -d "log=admin&pwd=password&wp-submit=Log+In&redirect_to=/wp-admin/&testcookie=1" "$B/wp-login.php"
get() { curl -s -c cjT.txt -b cjT.txt --max-time 180 "$B$1" -o "$2"; }

# Вкладка настроек
get "/wp-admin/admin.php?page=wpd-settings&tab=buttons" t_tab.html
t "tab: three section headings" "$(python3 -c "
import re;s=open('t_tab.html',encoding='utf-8').read();print(1 if re.search(r'<h2>Кнопки-ссылки</h2>.*<h2>Раскладка</h2>.*<h2>Вид и цвета</h2>',s,re.S) else 0)")"
t "tab: 14 new fields" "$( [ "$(grep -oE 'name="wpd_settings\[buttons_(title|columns|columns_mobile|gap|height|align|layout|variant|radius|text_size|bg|icon_color|text_color|hover_color)\]"' t_tab.html | sort -u | wc -l)" = "14" ] && echo 1 || echo 0)"
t "tab: repeater template has new sub-fields" "$( [ "$(grep -oE 'wpd_settings\[buttons\]\[__i__\]\[(desc|badge|image|color|icon_color|download|hidden|from|to)\]' t_tab.html | sort -u | wc -l)" = "9" ] && echo 1 || echo 0)"
t "tab: date inputs datetime-local" "$(grep -qE 'type="datetime-local"[^>]*name="wpd_settings\[buttons\]\[__i__\]\[from\]"' t_tab.html && echo 1 || echo 0)"
t "tab: no PHP warnings" "$(grep -qE '<b>(Warning|Notice|Deprecated|Fatal error)</b>' t_tab.html && echo 0 || echo 1)"

N=$(val t_tab.html _wpnonce)
LOC=$(curl -s -c cjT.txt -b cjT.txt -o /dev/null --max-time 120 -w "%{redirect_url}" \
  --data-urlencode "option_page=wpd_settings_group" --data-urlencode "action=update" --data-urlencode "_wpnonce=$N" \
  --data-urlencode "_wp_http_referer=/wp-admin/admin.php?page=wpd-settings&tab=buttons" --data-urlencode "wpd_settings[_tab]=buttons" \
  --data-urlencode "wpd_settings[buttons_title]=Полезные ссылки" --data-urlencode "wpd_settings[buttons_columns]=4" \
  --data-urlencode "wpd_settings[buttons_columns_mobile]=1" --data-urlencode "wpd_settings[buttons_gap]=20" \
  --data-urlencode "wpd_settings[buttons_height]=90" --data-urlencode "wpd_settings[buttons_align]=left" \
  --data-urlencode "wpd_settings[buttons_layout]=row" --data-urlencode "wpd_settings[buttons_variant]=tint" \
  --data-urlencode "wpd_settings[buttons_radius]=small" --data-urlencode "wpd_settings[buttons_text_size]=18" \
  --data-urlencode "wpd_settings[buttons_bg]=" --data-urlencode "wpd_settings[buttons_icon_color]=#15803d" \
  --data-urlencode "wpd_settings[buttons_text_color]=" --data-urlencode "wpd_settings[buttons_hover_color]=#b91c1c" \
  --data-urlencode "wpd_settings[buttons][0][icon]=admin-home" --data-urlencode "wpd_settings[buttons][0][text]=Главная" \
  --data-urlencode "wpd_settings[buttons][0][url]=https://example.com/" --data-urlencode "wpd_settings[buttons][0][desc]=Сайт олимпиады" \
  --data-urlencode "wpd_settings[buttons][0][badge]=Новое" --data-urlencode "wpd_settings[buttons][0][color]=#1e3a8a" \
  --data-urlencode "wpd_settings[buttons][1][icon]=download" --data-urlencode "wpd_settings[buttons][1][text]=Положение" \
  --data-urlencode "wpd_settings[buttons][1][url]=https://example.com/a.pdf" --data-urlencode "wpd_settings[buttons][1][download]=1" \
  --data-urlencode "wpd_settings[buttons][1][from]=2000-01-01T00:00" --data-urlencode "wpd_settings[buttons][1][to]=2099-12-31T23:59" \
  --data-urlencode "wpd_settings[buttons][2][icon]=clock" --data-urlencode "wpd_settings[buttons][2][text]=Итоги" \
  --data-urlencode "wpd_settings[buttons][2][url]=https://example.com/r" --data-urlencode "wpd_settings[buttons][2][from]=2099-01-01T10:00" \
  --data-urlencode "wpd_settings[buttons][3][icon]=hidden" --data-urlencode "wpd_settings[buttons][3][text]=Черновик" \
  --data-urlencode "wpd_settings[buttons][3][url]=" --data-urlencode "wpd_settings[buttons][3][hidden]=1" \
  "$B/wp-admin/options.php")
t "save tab: redirect back" "$(inq "$LOC" 'tab=buttons')"
get "/wp-admin/admin.php?page=wpd-settings&tab=buttons" t_tab2.html
t "saved: title" "$(has t_tab2.html 'name="wpd_settings\[buttons_title\]" value="Полезные ссылки"')"
t "saved: gap and height" "$( [ "$(has t_tab2.html 'name="wpd_settings\[buttons_gap\]" value="20"')" = 1 ] && [ "$(has t_tab2.html 'name="wpd_settings\[buttons_height\]" value="90"')" = 1 ] && echo 1 || echo 0)"
t "saved: selects" "$(python3 -c "
import re;s=open('t_tab2.html',encoding='utf-8').read()
def sel(k):
    m=re.search(r'name=\"wpd_settings\['+k+r'\]\"[^>]*>(.*?)</select>',s,re.S)
    v=re.search(r'<option value=\"([^\"]*)\"\s*selected',m.group(1)) if m else None
    return v.group(1) if v else None
r=[sel(k) for k in ['buttons_columns','buttons_columns_mobile','buttons_align','buttons_layout','buttons_variant','buttons_radius']]
print(1 if r==['4','1','left','row','tint','small'] else r)")"
t "saved: colors" "$( [ "$(has t_tab2.html 'name="wpd_settings\[buttons_icon_color\]" value="#15803d"')" = 1 ] && [ "$(has t_tab2.html 'name="wpd_settings\[buttons_hover_color\]" value="#b91c1c"')" = 1 ] && echo 1 || echo 0)"
t "saved: button desc, badge, color" "$( [ "$(has t_tab2.html 'name="wpd_settings\[buttons\]\[0\]\[desc\]" value="Сайт олимпиады"')" = 1 ] && [ "$(has t_tab2.html 'name="wpd_settings\[buttons\]\[0\]\[badge\]" value="Новое"')" = 1 ] && echo 1 || echo 0)"
t "saved: dates shown back with T" "$(grep -qE 'name="wpd_settings\[buttons\]\[1\]\[to\]" value="2099-12-31T23:59"' t_tab2.html && echo 1 || echo 0)"
t "saved: four buttons kept" "$(grep -q 'name="wpd_settings\[buttons\]\[3\]\[text\]" value="Черновик"' t_tab2.html && echo 1 || echo 0)"

# Страница сайта
curl -sL --max-time 120 "$B/wpd-t-page.php?reset=1" > t_info.json
ID=$(js t_info.json 'd["id"]'); URL=$(js t_info.json 'd["url"]')
t "helper: page created" "$( [ -n "$ID" ] && [ -n "$URL" ] && echo 1 || echo 0)"
curl -sL --max-time 120 "$URL" -o t_front.html
N0=$(nav t_front.html 0); N1=$(nav t_front.html 1)
t "front: block 1 from settings — classes" "$(inq "$N0" 'wpd-buttons wpd-buttons--row wpd-buttons--tint wpd-buttons--cols wpd-buttons--cols-m wpd-buttons--align-left wpd-buttons--fs|')"
t "front: block 1 vars" "$(inq "$N0" '|--wpd-qb-cols:4;--wpd-qb-cols-m:1;--wpd-qb-radius:6px;--wpd-qb-gap:20px;--wpd-qb-h:90px;--wpd-qb-fs:18px;--wpd-qb-fs-small:15px;--wpd-qb-icon:#15803d;--wpd-qb-hover:#b91c1c;|')"
t "front: block 1 only visible buttons" "$(inq "$N0" '|Главная,Положение')" 
t "front: settings title" "$(grep -q '<h2 class="wpd-h2">Полезные ссылки</h2><ul class="wpd-buttons__grid">' t_front.html && echo 1 || echo 0)"
t "front: future and hidden not on site" "$( [ "$(hasnt t_front.html '>Итоги<')" = 1 ] && [ "$(hasnt t_front.html '>Черновик<')" = 1 ] && echo 1 || echo 0)"
t "front: desc, badge and dark tile" "$(python3 -c "
s=open('t_front.html',encoding='utf-8').read()
print(1 if 'class=\"wpd-qbtn has-color wpd-qbtn--dark wpd-qbtn--badge\" style=\"--wpd-qb-bg:#1e3a8a;\"' in s and '<span class=\"wpd-qbtn__desc\">Сайт олимпиады</span>' in s and '<span class=\"wpd-qbtn__badge\">Новое</span>' in s else 0)")"
t "front: download link" "$(grep -qE 'href="https://example.com/a.pdf"[^>]*download>' t_front.html && echo 1 || echo 0)"
t "front: block 2 attributes" "$(inq "$N1" 'wpd-buttons wpd-buttons--compact wpd-buttons--outline wpd-buttons--cols wpd-buttons--cols-m wpd-buttons--align-left wpd-buttons--fs wpd-buttons--dark|')"
t "front: block 2 vars (attr + settings)" "$(inq "$N1" '|--wpd-qb-cols:3;--wpd-qb-cols-m:1;--wpd-qb-radius:999px;--wpd-qb-gap:20px;--wpd-qb-h:90px;--wpd-qb-fs:16px;--wpd-qb-fs-small:14px;--wpd-qb-bg:#0b1220;--wpd-qb-icon:#15803d;--wpd-qb-hover:#b91c1c;|')"
t "front: block 2 visible by dates" "$(inq "$N1" '|Обычная,Файл,Логотип')"
t "front: block 2 title" "$(grep -q '<h2 class="wpd-h2">Блок T</h2>' t_front.html && echo 1 || echo 0)"
t "front: image instead of icon" "$(grep -qE '<img [^>]*class="wpd-qbtn__img" src="[^"]*kvantorium-logo.webp"' t_front.html && echo 1 || echo 0)"
t "front: light tile with icon color" "$(grep -q 'class="wpd-qbtn has-color wpd-qbtn--light has-icon-color wpd-qbtn--badge" style="--wpd-qb-bg:#fef3c7;--wpd-qb-icon:#b45309;"' t_front.html && echo 1 || echo 0)"
t "front: no PHP warnings" "$(grep -qE '<b>(Warning|Notice|Deprecated|Fatal error)</b>' t_front.html && echo 0 || echo 1)"

# Экран редактирования
get "/wp-admin/post.php?post=$ID&action=edit" t_edit.html
t "edit: buttonsSettings from settings" "$( [ "$(edata t_edit.html buttonsSettings)" = '{"title":"Полезные ссылки","columns":"4","columnsMobile":"1","gap":20,"height":90,"textAlign":"left","layout":"row","variant":"tint","radius":"small","textSize":18,"bgColor":"","headingSize":0,"smallSize":0,"bgTransparency":0,"iconColor":"#15803d","textColor":"","hoverColor":"#b91c1c"}' ] && echo 1 || echo 0)"
t "edit: buttonsOptions" "$(inq "$(edata t_edit.html buttonsOptions)" '"layout":{"label":"Вид плиток","options":{"stack":"Иконка сверху"')"
t "edit: client defaults = settings" "$( [ "$(has t_edit.html '"layout":{"type":"string","default":"row"}')" = 1 ] && [ "$(has t_edit.html '"gap":{"type":"integer","default":20}')" = 1 ] && echo 1 || echo 0)"
t "edit: page panel fields" "$( [ "$(grep -oE 'id="wpd_pe_ov_buttons_(title|columns|columns_mobile|gap|height|align|layout|variant|radius|text_size|bg|icon_color|text_color|hover_color)"' t_edit.html | sort -u | wc -l)" = "14" ] && echo 1 || echo 0)"
WPN=$(val t_edit.html _wpnonce); PN=$(val t_edit.html wpd_page_nonce); DN=$(val t_edit.html wpd_nonce)
RN=$(grep -o 'createNonceMiddleware( "[^"]*"' t_edit.html | head -1 | sed 's/.*( "//;s/"$//')
MBURL=$(grep -o 'var _wpMetaBoxUrl = "[^"]*"' t_edit.html | sed 's/.*= "//;s/"$//')
t "edit: nonces found" "$( [ -n "$WPN" ] && [ -n "$PN" ] && [ -n "$DN" ] && [ -n "$RN" ] && [ -n "$MBURL" ] && echo 1 || echo 0)"

# Группа страницы через мета-бокс
ARGS=()
for k in hero countdown content stats docs faq slider buttons notice cards news contacts; do ARGS+=(--data-urlencode "wpd_page[order][]=$k"); done
CODE=$(curl -s -c cjT.txt -b cjT.txt -o /dev/null --max-time 180 -w "%{http_code}" \
  --data-urlencode "_wpnonce=$WPN" --data-urlencode "_wp_http_referer=/wp-admin/post.php?post=$ID&action=edit" \
  --data-urlencode "user_ID=1" --data-urlencode "action=editpost" --data-urlencode "originalaction=editpost" \
  --data-urlencode "post_type=page" --data-urlencode "original_post_status=publish" --data-urlencode "post_ID=$ID" \
  --data-urlencode "wpd_nonce=$DN" --data-urlencode "wpd_enabled=1" --data-urlencode "wpd_page_nonce=$PN" \
  "${ARGS[@]}" --data-urlencode "wpd_page[show][content]=1" \
  --data-urlencode "wpd_page[ovg][buttons]=1" --data-urlencode "wpd_page[ov][buttons_layout]=stack" --data-urlencode "wpd_page[ov][buttons_variant]=solid" \
  --data-urlencode "wpd_page[ov][buttons_title]=Для страницы" --data-urlencode "wpd_page[ov][buttons_columns]=2" --data-urlencode "wpd_page[ov][buttons_gap]=30" \
  --data-urlencode "wpd_page[ov][buttons_icon_color]=" --data-urlencode "wpd_page[ov][buttons_hover_color]=" \
  --data-urlencode "wpd_page[ov][buttons][0][text]=Своя для страницы" --data-urlencode "wpd_page[ov][buttons][0][url]=https://example.com/p" \
  --data-urlencode "wpd_page[ov][buttons][0][icon]=star-filled" --data-urlencode "wpd_page[ov][buttons][0][to]=2099-01-01T00:00" \
  "$MBURL")
t "metabox save: accepted" "$( [ "$CODE" = "302" ] || [ "$CODE" = "200" ] && echo 1 || echo 0)"
get "/wp-admin/post.php?post=$ID&action=edit" t_edit2.html
t "metabox: page title shown" "$(has t_edit2.html 'name="wpd_page\[ov\]\[buttons_title\]" value="Для страницы"')"
t "metabox: page date shown" "$(has t_edit2.html 'name="wpd_page\[ov\]\[buttons\]\[0\]\[to\]" value="2099-01-01T00:00"')"
t "edit: editor data follows page" "$(inq "$(edata t_edit2.html buttonsSettings)" '{"title":"Для страницы","columns":"2"')"
curl -sL --max-time 120 "$URL" -o t_front2.html
N0=$(nav t_front2.html 0); N1=$(nav t_front2.html 1)
t "front: block 1 uses page group" "$( [ "$(inq "$N0" 'wpd-buttons wpd-buttons--solid wpd-buttons--cols wpd-buttons--cols-m wpd-buttons--align-left wpd-buttons--fs|')" = 1 ] && [ "$(inq "$N0" '--wpd-qb-gap:30px;')" = 1 ] && [ "$(inq "$N0" '--wpd-qb-icon')" = 0 ] && echo 1 || echo 0)"
t "front: page buttons list" "$(inq "$N0" '|Своя для страницы')"
t "front: page title" "$(grep -q '<h2 class="wpd-h2">Для страницы</h2>' t_front2.html && echo 1 || echo 0)"
t "front: block 2 attributes beat page" "$( [ "$(inq "$N1" 'wpd-buttons--compact wpd-buttons--outline')" = 1 ] && [ "$(inq "$N1" '--wpd-qb-cols:3;')" = 1 ] && [ "$(inq "$N1" '--wpd-qb-gap:30px;')" = 1 ] && echo 1 || echo 0)"

# Защита ответов REST
curl -sL --max-time 60 "$B/wpd-t-page.php?junk=1" > t_junk.json
t "guard: junk mu-plugin installed" "$(js t_junk.json '1 if d["junk"] else 0')"
curl -s --max-time 60 "$B/wpd-set.php?json=%7B%7D" > /dev/null
curl -s --max-time 120 -H "X-WPD-Junk: 1" -H "X-WPD-NoGuard: 1" "$B/?rest_route=/wp/v2/pages/$ID" -o t_rest_noguard.txt
t "guard control: without guard JSON broken" "$( [ "$(isjson t_rest_noguard.txt)" = 0 ] && grep -q 'T-junk' t_rest_noguard.txt && echo 1 || echo 0)"
curl -s --max-time 120 -H "X-WPD-Junk: 1" "$B/?rest_route=/wp/v2/pages/$ID" -o t_rest_get.txt
t "guard: GET page via ?rest_route clean JSON" "$( [ "$(isjson t_rest_get.txt)" = 1 ] && [ "$(js t_rest_get.txt "d['id']")" = "$ID" ] && echo 1 || echo 0)"
curl -s --max-time 120 -H "X-WPD-Junk: 1" "$B/wp-json/wp/v2/pages/$ID" -o t_rest_get2.txt
# Без красивых постоянных ссылок /wp-json/ может не разбираться — тогда достаточно, чтобы мусора не было
t "guard: GET via /wp-json/ has no junk" "$(hasnt t_rest_get2.txt 'T-junk')"
C=$(curl -s -c cjT.txt -b cjT.txt --max-time 180 -o t_rest_save.txt -w "%{http_code}" -X POST -H "X-WPD-Junk: 1" -H "X-WP-Nonce: $RN" \
  -H "Content-Type: application/json" --data '{"title":"Олимпиада — фаза T (сохранено)"}' "$B/?rest_route=/wp/v2/pages/$ID&_locale=user")
t "guard: block editor save returns clean JSON" "$( [ "$C" = "200" ] && [ "$(isjson t_rest_save.txt)" = 1 ] && [ "$(js t_rest_save.txt "d['title']['raw']")" = "Олимпиада — фаза T (сохранено)" ] && echo 1 || echo 0)"
C=$(curl -s -c cjT.txt -b cjT.txt --max-time 120 -o t_rest_prev.txt -w "%{http_code}" -X POST -H "X-WPD-Junk: 1" -H "X-WP-Nonce: $RN" \
  --data-urlencode "post_id=$ID" --data-urlencode "form=wpd_page[ovg][page]=1&wpd_page[ov][palette]=forest" "$B/?rest_route=/wpd/v1/preview")
t "guard: plugin preview route clean JSON" "$( [ "$C" = "200" ] && [ "$(isjson t_rest_prev.txt)" = 1 ] && echo 1 || echo 0)"
get "/wp-admin/index.php" t_dash.html
t "guard notice: shown in admin" "$( [ "$(has t_dash.html 'wpd-rest-stray-notice')" = 1 ] && [ "$(has t_dash.html 'T-junk: Undefined variable')" = 1 ] && echo 1 || echo 0)"
t "guard notice: route shown" "$(grep -qE '<code>(GET|POST) /w(p/v2/pages/[0-9]+|pd/v1/preview)</code>' t_dash.html && echo 1 || echo 0)"
t "guard notice: tags stripped" "$(python3 -c "
import re;s=open('t_dash.html',encoding='utf-8').read();m=re.search(r'<pre[^>]*>(.*?)</pre>',s,re.S);print(1 if m and 'Warning:' in m.group(1) and '&lt;b&gt;' not in m.group(1) and '<b>' not in m.group(1) else 0)")"
DIS=$(grep -oE 'href="[^"]*admin-post\.php\?action=wpd_rest_stray_dismiss[^"]*"' t_dash.html | head -1 | sed 's/^href="//;s/"$//;s/&#038;/\&/g;s/&amp;/\&/g')
C=$(curl -s -c cjT.txt -b cjT.txt --max-time 120 -o /dev/null -w "%{http_code}" -e "$B/wp-admin/index.php" "$DIS")
get "/wp-admin/index.php" t_dash2.html
t "guard notice: dismiss works" "$( [ -n "$DIS" ] && [ "$C" = "302" ] && [ "$(hasnt t_dash2.html 'wpd-rest-stray-notice')" = 1 ] && echo 1 || echo 0)"
C=$(curl -s -c cjT.txt -b cjT.txt --max-time 120 -o /dev/null -w "%{http_code}" "$B/wp-admin/admin-post.php?action=wpd_rest_stray_dismiss&_wpnonce=bad")
t "guard notice: bad nonce rejected" "$( [ "$C" = "403" ] || [ "$C" = "200" ] && echo 1 || echo 0)"
curl -s --max-time 120 "$B/?rest_route=/wp/v2/pages/$ID" -o t_rest_plain.txt
get "/wp-admin/index.php" t_dash3.html
t "guard: clean requests leave no notice" "$( [ "$(isjson t_rest_plain.txt)" = 1 ] && [ "$(hasnt t_dash3.html 'wpd-rest-stray-notice')" = 1 ] && echo 1 || echo 0)"
curl -s --max-time 120 -H "X-WPD-Junk: 1" "$URL" -o t_front_junk.html
t "guard: normal pages not buffered (junk visible)" "$(has t_front_junk.html 'T-junk')"

# Уборка
curl -sL --max-time 60 "$B/wpd-t-page.php?cleanup=1" > t_clean.json
t "cleanup" "$(js t_clean.json '1 if d["cleaned"] else 0')"
curl -s --max-time 60 "$B/wpd-set.php?json=%7B%7D" > /dev/null
echo; echo "TOTAL: $((PASS+FAIL)), FAILED: $FAIL"
