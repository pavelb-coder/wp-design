#!/bin/bash
# Фаза K (1.7.0): HTTP-проверки названия организации в контактах — поля вкладки «Контакты», настоящее сохранение формы,
# страница сайта со ссылкой. В конце сбрасывает опцию и удаляет тестовые страницы.
B=${WPD_BASE:-http://127.0.0.1:9400}
PASS=0; FAIL=0
t() { if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
has() { grep -q -- "$2" "$1" && echo 1 || echo 0; }
set_opt() { curl -s --max-time 60 "$B/wpd-set.php?json=$(python3 -c 'import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))' "$1")" > /dev/null; }

rm -f cjK.txt
curl -s -c cjK.txt -b cjK.txt -o /dev/null --max-time 60 -d "log=admin&pwd=password&wp-submit=Log+In&redirect_to=/wp-admin/&testcookie=1" "$B/wp-login.php"
get() { curl -s -c cjK.txt -b cjK.txt --max-time 120 "$B$1" -o "$2"; }

set_opt '{}'
get "/wp-admin/admin.php?page=wpd-settings&tab=contacts" k_tab.html
t "contacts tab: org name field"          "$(has k_tab.html 'name="wpd_settings\[contact_org\]"')"
t "contacts tab: org link field (url)"    "$(grep -qE 'type="url"[^>]*name="wpd_settings\[contact_org_url\]"' k_tab.html && echo 1 || echo 0)"
t "contacts tab: labels"                  "$([ "$(has k_tab.html 'Название организации')$(has k_tab.html 'Ссылка организации')" = "11" ] && echo 1 || echo 0)"
t "contacts tab: org before address"      "$(python3 -c "import sys;s=open('k_tab.html',encoding='utf-8').read();a=s.find('[contact_org]');b=s.find('[contact_address]');print(1 if 0<a<b else 0)")"

NONCE=$(grep -o 'name="_wpnonce" value="[^"]*"' k_tab.html | head -1 | sed 's/.*value="//;s/"//')
LOC=$(curl -s -c cjK.txt -b cjK.txt -o /dev/null --max-time 120 -w "%{redirect_url}" \
  --data-urlencode "option_page=wpd_settings_group" --data-urlencode "action=update" --data-urlencode "_wpnonce=$NONCE" \
  --data-urlencode "_wp_http_referer=/wp-admin/admin.php?page=wpd-settings&tab=contacts" \
  --data-urlencode "wpd_settings[_tab]=contacts" --data-urlencode "wpd_settings[contacts_title]=Контакты" \
  --data-urlencode "wpd_settings[contact_org]=Краевой центр «Юниор»" --data-urlencode "wpd_settings[contact_org_url]=https://junior.example.org/" \
  --data-urlencode "wpd_settings[contact_phone]=+7 (391) 200-00-00" --data-urlencode "wpd_settings[contact_email]=k@example.com" \
  "$B/wp-admin/options.php")
t "save: redirect back to contacts"       "$(echo "$LOC" | grep -q 'tab=contacts' && echo 1 || echo 0)"
get "/wp-admin/admin.php?page=wpd-settings&tab=contacts&settings-updated=true" k_tab2.html
t "save: org name stored"                 "$(has k_tab2.html 'value="Краевой центр «Юниор»"')"
t "save: org link stored"                 "$(has k_tab2.html 'value="https://junior.example.org/"')"

ID=$(curl -s --max-time 60 "$B/wpd-h-page.php")
curl -sL --max-time 120 "$B/?page_id=$ID" -o k_front.html
t "front: org row first in contacts"      "$(has k_front.html '<ul class="wpd-contacts__list"><li class="wpd-contacts__org">')"
t "front: org link in new tab"            "$(has k_front.html '<a href="https://junior.example.org/" target="_blank" rel="noopener noreferrer">Краевой центр «Юниор»</a>')"
t "front: org styles in CSS"              "$(curl -s --max-time 60 "$B/wp-content/plugins/wp-olympiad-design/assets/css/wpd.css" | grep -q 'wpd-contacts__org' && echo 1 || echo 0)"
t "front: no PHP warnings"                "$(grep -qE '<b>(Warning|Notice|Deprecated)</b>' k_front.html && echo 0 || echo 1)"

set_opt '{}'
curl -s --max-time 60 "$B/wpd-h-page.php?cleanup=1" > /dev/null
echo
echo "PHASE K: PASS=$PASS FAIL=$FAIL"
[ "$FAIL" = "0" ]
