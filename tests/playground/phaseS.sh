#!/bin/bash
# Фаза S (2.5.0): HTTP-проверки размеров иконок — поля на вкладках настроек и их настоящее сохранение через options.php,
# страница сайта с блоками (атрибут блока, общие настройки), сохранение размера для страницы через мета-бокс так,
# как это делает блочный редактор, данные редактора, предпросмотр REST. Помощник — wpd-s-page.php.
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
# style="" корневого контейнера N-го блока (wpd-page--<slug>), по порядку на странице
bstyle() { python3 - "$1" "$2" "$3" <<'PY'
import re, sys, html
s = open(sys.argv[1], encoding='utf-8').read()
m = re.findall(r'<div class="wpd-page[^"]*wpd-page--' + re.escape(sys.argv[2]) + r'\b[^"]*" style="([^"]*)"', s)
i = int(sys.argv[3])
print(html.unescape(m[i]) if len(m) > i else 'NONE')
PY
}
inq() { case "$1" in *"$2"*) echo 1;; *) echo 0;; esac; }

curl -s --max-time 60 "$B/wpd-set.php?json=%7B%7D" > /dev/null
rm -f cjS.txt
curl -s -c cjS.txt -b cjS.txt -o /dev/null --max-time 60 "$B/wp-login.php"
curl -s -c cjS.txt -b cjS.txt -o /dev/null --max-time 60 -d "log=admin&pwd=password&wp-submit=Log+In&redirect_to=/wp-admin/&testcookie=1" "$B/wp-login.php"
get() { curl -s -c cjS.txt -b cjS.txt --max-time 180 "$B$1" -o "$2"; }

# Поля на вкладках
declare -A TAB=([buttons]=buttons_icon_size [contacts]=contacts_icon_size [docs]=docs_icon_size [hero]=hero_button_icon_size)
declare -A MAX=([buttons]=96 [contacts]=64 [docs]=96 [hero]=48)
for tab in buttons contacts docs hero; do
  get "/wp-admin/admin.php?page=wpd-settings&tab=$tab" "s_tab_$tab.html"
  t "tab $tab: ${TAB[$tab]} field 0…${MAX[$tab]}" "$(grep -qE "name=\"wpd_settings\[${TAB[$tab]}\]\" value=\"0\" min=\"0\" max=\"${MAX[$tab]}\"" "s_tab_$tab.html" && echo 1 || echo 0)"
done
t "tab hero: size field after second icon" "$(python3 -c "
s=open('s_tab_hero.html',encoding='utf-8').read();a=s.find('wpd_settings[hero_button2_icon]');b=s.find('wpd_settings[hero_button_icon_size]');c=s.find('wpd_settings[hero_countdown]')
print(1 if 0<a<b<c else 0)")"

# Настоящее сохранение вкладок (2.7.0: включённые по умолчанию флажки вкладки форма отправляет — передаём их, как браузер)
save() { local tab=$1; shift
  local n; n=$(val "s_tab_$tab.html" _wpnonce)
  curl -s -c cjS.txt -b cjS.txt -o /dev/null --max-time 120 -w "%{redirect_url}" \
    --data-urlencode "option_page=wpd_settings_group" --data-urlencode "action=update" --data-urlencode "_wpnonce=$n" \
    --data-urlencode "_wp_http_referer=/wp-admin/admin.php?page=wpd-settings&tab=$tab" --data-urlencode "wpd_settings[_tab]=$tab" "$@" "$B/wp-admin/options.php"; }
LOC=$(save buttons --data-urlencode "wpd_settings[buttons_icon_size]=36")
t "save buttons: redirect back" "$(inq "$LOC" 'tab=buttons')"
save contacts --data-urlencode "wpd_settings[contacts_icon_size]=30" --data-urlencode "wpd_settings[contacts_show_icons]=1" --data-urlencode "wpd_settings[contact_phone]=+7 391 111-11-11" > /dev/null
save docs --data-urlencode "wpd_settings[docs_icon_size]=500" --data-urlencode "wpd_settings[docs_show_type]=1" --data-urlencode "wpd_settings[docs]=Положение|https://example.com/a.pdf" > /dev/null
save hero --data-urlencode "wpd_settings[hero_button_icon_size]=26" --data-urlencode "wpd_settings[hero_title]=Заголовок S" > /dev/null
for tab in buttons contacts docs hero; do get "/wp-admin/admin.php?page=wpd-settings&tab=$tab" "s_tab2_$tab.html"; done
t "saved: buttons 36 kept after other tabs" "$(has s_tab2_buttons.html 'name="wpd_settings\[buttons_icon_size\]" value="36"')"
t "saved: contacts 30" "$(has s_tab2_contacts.html 'name="wpd_settings\[contacts_icon_size\]" value="30"')"
t "saved: docs 500 -> 96" "$(has s_tab2_docs.html 'name="wpd_settings\[docs_icon_size\]" value="96"')"
t "saved: hero 26" "$(has s_tab2_hero.html 'name="wpd_settings\[hero_button_icon_size\]" value="26"')"

curl -sL --max-time 120 "$B/wpd-s-page.php?reset=1" > s_info.json
ID=$(js s_info.json 'd["id"]'); URL=$(js s_info.json 'd["url"]')
t "helper: page created" "$( [ -n "$ID" ] && [ -n "$URL" ] && echo 1 || echo 0)"

# Страница сайта: атрибут блока и общие настройки
curl -sL --max-time 120 "$URL" -o s_front.html
S0=$(bstyle s_front.html buttons 0); S1=$(bstyle s_front.html buttons 1)
t "front: buttons without size -> settings 36" "$(inq "$S0" '--wpd-icon-qbtn:36px')"
t "front: buttons with size 60" "$( [ "$(inq "$S1" '--wpd-icon-qbtn:60px')" = 1 ] && [ "$(inq "$S1" '--wpd-icon-qbtn:36px')" = 0 ] && echo 1 || echo 0)"
t "front: contacts from settings 30" "$(inq "$(bstyle s_front.html contacts 0)" '--wpd-icon-contacts:30px')"
t "front: docs block 80 beats settings 96" "$(inq "$(bstyle s_front.html docs 0)" '--wpd-icon-doc:80px')"
t "front: hero block 22" "$(inq "$(bstyle s_front.html hero 0)" '--wpd-icon-btn:22px')"
t "front: page container has global sizes" "$(python3 -c "
import re,html;s=open('s_front.html',encoding='utf-8').read();m=re.search(r'<div class=\"wpd-page(?![^\"]*wpd-page--block)[^\"]*\" style=\"([^\"]*)\"',s)
st=html.unescape(m.group(1)) if m else '';print(1 if '--wpd-icon-qbtn:36px' in st and '--wpd-icon-doc:96px' in st else 0)")"
t "front: icons rendered" "$( [ "$(grep -o 'wpd-qbtn__icon' s_front.html | wc -l)" = "4" ] && grep -q 'wpd-doc__icon' s_front.html && grep -q 'wpd-btn__icon' s_front.html && echo 1 || echo 0)"

# Экран редактирования: поля панели и данные редактора
get "/wp-admin/post.php?post=$ID&action=edit" s_edit.html
t "edit: size fields in page panel" "$( [ "$(grep -oE 'id="wpd_pe_ov_(buttons|contacts|hero_button|docs)_icon_size"' s_edit.html | sort -u | wc -l)" = "4" ] && echo 1 || echo 0)"
t "edit: editor data iconSizes with settings" "$(has s_edit.html '"buttons":{"attr":"iconSize","var":"--wpd-icon-qbtn","min":16,"max":96,"default":44,"setting":36}')"
t "edit: client attribute default = setting" "$(has s_edit.html '"iconSize":{"type":"integer","default":36}')"

WPN=$(val s_edit.html _wpnonce); PN=$(val s_edit.html wpd_page_nonce); DN=$(val s_edit.html wpd_nonce)
RN=$(grep -o 'createNonceMiddleware( "[^"]*"' s_edit.html | head -1 | sed 's/.*( "//;s/"$//')
MBURL=$(grep -o 'var _wpMetaBoxUrl = "[^"]*"' s_edit.html | sed 's/.*= "//;s/"$//')
t "edit: nonces found" "$( [ -n "$WPN" ] && [ -n "$PN" ] && [ -n "$DN" ] && [ -n "$RN" ] && [ -n "$MBURL" ] && echo 1 || echo 0)"

# Размер для страницы через мета-бокс
ARGS=()
for k in hero countdown content stats docs faq slider buttons notice cards news contacts; do ARGS+=(--data-urlencode "wpd_page[order][]=$k"); done
CODE=$(curl -s -c cjS.txt -b cjS.txt -o /dev/null --max-time 180 -w "%{http_code}" \
  --data-urlencode "_wpnonce=$WPN" --data-urlencode "_wp_http_referer=/wp-admin/post.php?post=$ID&action=edit" \
  --data-urlencode "user_ID=1" --data-urlencode "action=editpost" --data-urlencode "originalaction=editpost" \
  --data-urlencode "post_type=page" --data-urlencode "original_post_status=publish" --data-urlencode "post_ID=$ID" \
  --data-urlencode "wpd_nonce=$DN" --data-urlencode "wpd_enabled=1" --data-urlencode "wpd_page_nonce=$PN" \
  "${ARGS[@]}" --data-urlencode "wpd_page[show][content]=1" \
  --data-urlencode "wpd_page[ovg][buttons]=1" --data-urlencode "wpd_page[ov][buttons_icon_size]=50" \
  --data-urlencode "wpd_page[ovg][page]=1" --data-urlencode "wpd_page[ov][docs_icon_size]=40" --data-urlencode "wpd_page[ov][palette]=forest" \
  "$MBURL")
t "metabox save: accepted" "$( [ "$CODE" = "302" ] || [ "$CODE" = "200" ] && echo 1 || echo 0)"
get "/wp-admin/post.php?post=$ID&action=edit" s_edit2.html
t "metabox: saved page size shown" "$(has s_edit2.html 'name="wpd_page\[ov\]\[buttons_icon_size\]" value="50"')"
t "edit: editor data uses page size" "$(has s_edit2.html '"setting":50}')"
curl -sL --max-time 120 "$URL" -o s_front2.html
t "front: block without size -> page 50" "$(inq "$(bstyle s_front2.html buttons 0)" '--wpd-icon-qbtn:50px')"
t "front: block size 60 beats page" "$(inq "$(bstyle s_front2.html buttons 1)" '--wpd-icon-qbtn:60px')"
t "front: docs block 80 beats page 40" "$(inq "$(bstyle s_front2.html docs 0)" '--wpd-icon-doc:80px')"
t "front: contacts still global 30" "$(inq "$(bstyle s_front2.html contacts 0)" '--wpd-icon-contacts:30px')"
t "front: page palette applied too" "$(has s_front2.html '#15803d')"

# Предпросмотр REST
rest() { curl -s -c cjS.txt -b cjS.txt --max-time 120 -o s_rest.json -w "%{http_code}" -X POST -H "X-WP-Nonce: $RN" "$@" "$B/?rest_route=/wpd/v1/preview"; }
C=$(rest --data-urlencode "post_id=$ID" --data-urlencode "form=wpd_page[ovg][contacts]=1&wpd_page[ov][contacts_icon_size]=48")
t "rest preview: contacts size in style" "$( [ "$C" = "200" ] && [ "$(js s_rest.json "'--wpd-icon-contacts:48px' in d['style']")" = "True" ] && echo 1 || echo 0)"
t "rest preview: vars map" "$(js s_rest.json "1 if d['vars'].get('--wpd-icon-contacts')=='48px' else 0")"
C=$(rest --data-urlencode "post_id=$ID" --data-urlencode "form=wpd_page[ovg][contacts]=1&wpd_page[ov][contacts_icon_size]=0")
# В группе страницы 0 — размер по умолчанию (24 px), как в общих настройках: переменной нет
t "rest preview: page 0 -> default size, no var" "$(js s_rest.json "1 if '--wpd-icon-contacts' not in d['vars'] and '--wpd-icon-qbtn' in d['vars'] else 0")"

# Уборка
curl -sL --max-time 60 "$B/wpd-s-page.php?cleanup=1" > s_clean.json
t "cleanup" "$(js s_clean.json '1 if d["cleaned"] else 0')"
curl -s --max-time 60 "$B/wpd-set.php?json=%7B%7D" > /dev/null
echo; echo "TOTAL: $((PASS+FAIL)), FAILED: $FAIL"
