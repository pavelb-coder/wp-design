#!/bin/bash
# Фаза Y (2.11.0): HTTP-проверки прозрачности фонов и размеров текстов — поля на вкладках настроек и их сохранение,
# поле «Прозрачность фона» в панели «Блоки олимпиады», вывод на сайте (блоки редактора с размерами, прозрачностью
# и фоном раздела; страница с панелью — фон блока с прозрачностью), данные редактора, CSS, справка. Помощник — wpd-y-helper.php.
B=${WPD_BASE:-http://127.0.0.1:9400}
PASS=0; FAIL=0
t() { if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
has() { grep -q -- "$2" "$1" && echo 1 || echo 0; }
hasnt() { grep -q -- "$2" "$1" && echo 0 || echo 1; }
eq() { [ "$1" = "$2" ] && echo 1 || echo 0; }
js() { python3 -c "import json,sys;d=json.load(open(sys.argv[1]));exec('print('+sys.argv[2]+')')" "$1" "$2" 2>/dev/null; }
nowarn() { grep -qE '<b>(Warning|Notice|Deprecated|Fatal error)</b>' "$1" && echo 0 || echo 1; }
all() { for v in "$@"; do [ "$v" = 1 ] || { echo 0; return; }; done; echo 1; }

curl -s --max-time 60 "$B/wpd-set.php?json=%7B%7D" > /dev/null
curl -sL --max-time 120 "$B/wpd-y-helper.php?setup=1" > y_setup.json
ID=$(js y_setup.json 'd["id"]'); URL=$(js y_setup.json 'd["url"]'); PID=$(js y_setup.json 'd["pid"]'); PURL=$(js y_setup.json 'd["purl"]')
t "helper setup" "$( [ -n "$ID" ] && [ -n "$PID" ] && echo 1 || echo 0)"

# Сайт: блоки редактора
curl -sL --max-time 120 "$URL" -o y_page.html
t "front: hero transparency and sizes" "$(all "$(has y_page.html 'wpd-hero--tr')" "$(has y_page.html '--wpd-hero-a:60%')" "$(has y_page.html '--wpd-hero-fs-title:52px')" "$(has y_page.html '--wpd-hero-fs-lead:21px')")"
t "front: stats heading size and transparency" "$(all "$(has y_page.html 'wpd-look--fs-title')" "$(has y_page.html '--wpd-bl-fs-title:38px')" "$(has y_page.html '--wpd-bl-bg-a:50%')")"
t "front: section background on block wrapper" "$(all "$(has y_page.html 'wpd-sec wpd-sec--dark')" "$(has y_page.html '--wpd-sec-bg:rgba(11,18,32,0.8);')")"
t "front: faq item size" "$(all "$(has y_page.html 'wpd-look--fs-item')" "$(has y_page.html '--wpd-bl-fs-item:22px')")"
t "front: no PHP warnings" "$(nowarn y_page.html)"
curl -sL --max-time 120 "$PURL" -o y_panel.html
t "front: page panel block background with transparency" "$(all "$(has y_panel.html 'wpd-b--bg')" "$(has y_panel.html '--wpd-b-bg:rgba(11,18,32,0.4);')" "$(has y_panel.html 'wpd-b--bg-light')")"
curl -sL --max-time 60 "$B/wp-content/plugins/wp-olympiad-design/assets/css/wpd.css" -o y_css.css
t "css served with new rules" "$(all "$(has y_css.css 'wpd-look--bgtr')" "$(has y_css.css 'wpd-sec--dark')" "$(has y_css.css 'wpd-hero--fs-title')")"

# Админка
login() { rm -f "$1"; curl -s -c "$1" -b "$1" -o /dev/null --max-time 60 "$B/wp-login.php"
  curl -s -c "$1" -b "$1" -o /dev/null --max-time 60 --data-urlencode "log=$2" --data-urlencode "pwd=$3" -d "wp-submit=Log+In&redirect_to=/wp-admin/&testcookie=1" "$B/wp-login.php"; }
login cjY.txt admin password
get() { curl -s -c cjY.txt -b cjY.txt --max-time 180 "$B$1" -o "$2"; }
get "/wp-admin/admin.php?page=wpd-settings&tab=stats" y_stats.html
t "stats tab: size and transparency fields" "$(all "$(has y_stats.html 'wpd_settings\[stats_title_size\]')" "$(has y_stats.html 'wpd_settings\[stats_bg_tr\]')" "$(has y_stats.html 'Прозрачность фона карточек, %')")"
t "stats tab: no PHP warnings" "$(nowarn y_stats.html)"
get "/wp-admin/admin.php?page=wpd-settings&tab=hero" y_hero.html
t "hero tab: new fields" "$(all "$(has y_hero.html 'wpd_settings\[hero_bg_tr\]')" "$(has y_hero.html 'wpd_settings\[hero_title_px\]')" "$(has y_hero.html 'wpd_settings\[hero_badge_size\]')" "$(has y_hero.html 'wpd_settings\[hero_lead_size\]')" "$(has y_hero.html 'wpd_settings\[hero_button_size\]')")"
NONCE=$(python3 -c "import re;s=open('y_stats.html',encoding='utf-8').read();m=re.search(r'name=\"_wpnonce\" value=\"([^\"]+)\"',s);print(m.group(1) if m else '')")
curl -s -c cjY.txt -b cjY.txt --max-time 180 -o /dev/null -e "$B/wp-admin/admin.php?page=wpd-settings&tab=stats" \
  --data-urlencode "option_page=wpd_settings_group" --data-urlencode "action=update" --data-urlencode "_wpnonce=$NONCE" \
  --data-urlencode "wpd_settings[_tab]=stats" --data-urlencode "wpd_settings[stats_title_size]=44" --data-urlencode "wpd_settings[stats_bg_tr]=130" "$B/wp-admin/options.php"
get "/wp-admin/admin.php?page=wpd-settings&tab=stats" y_stats2.html
t "settings save: size kept, transparency clamped" "$(all "$(python3 -c "import re;s=open('y_stats2.html',encoding='utf-8').read();m=re.search(r'name=\"wpd_settings\[stats_title_size\]\"[^>]*value=\"(\d+)\"|value=\"(\d+)\"[^>]*name=\"wpd_settings\[stats_title_size\]\"',s);print(1 if m and (m.group(1) or m.group(2))=='44' else 0)")" "$(python3 -c "import re;s=open('y_stats2.html',encoding='utf-8').read();m=re.search(r'name=\"wpd_settings\[stats_bg_tr\]\"[^>]*value=\"(\d+)\"|value=\"(\d+)\"[^>]*name=\"wpd_settings\[stats_bg_tr\]\"',s);print(1 if m and (m.group(1) or m.group(2))=='100' else 0)")")"
get "/wp-admin/admin.php?page=wpd-settings&tab=help" y_help.html
t "help: 2.11.0 section" "$(has y_help.html 'Прозрачность фонов и размеры текстов (2.11.0)')"
get "/wp-admin/post.php?post=$PID&action=edit" y_edit.html
t "page panel: transparency field with saved value" "$(all "$(has y_edit.html 'wpd_page\[bstyle\]\[stats\]\[bg_tr\]')" "$(python3 -c "import re;s=open('y_edit.html',encoding='utf-8').read();print(1 if re.search(r'name=\"wpd_page\[bstyle\]\[stats\]\[bg_tr\]\" value=\"60\"',s) else 0)")")"
t "page panel: group fields for sizes" "$(all "$(has y_edit.html 'wpd_page\[ov\]\[stats_title_size\]')" "$(has y_edit.html 'wpd_page\[ov\]\[hero_bg_tr\]')")"
t "editor data: sizeSettings and lookOptions" "$(all "$(has y_edit.html '"sizeSettings"')" "$(has y_edit.html '"bgTransparency"')" "$(has y_edit.html '"sectionBg"')" "$(has y_edit.html '"headingSize"')")"
t "edit: no PHP warnings" "$(nowarn y_edit.html)"
# REST: импорт настроек страницы с фоном блока и прозрачностью (та же санитизация, что у панели)
NONCE_R=$(python3 -c "import re;s=open('y_edit.html',encoding='utf-8').read();m=re.search(r'wpApiSettings = \{[^;]*\"nonce\":\"([^\"]+)\"',s) or re.search(r'createNonceMiddleware\( \"([^\"]+)\" \)',s);print(m.group(1) if m else '')")
curl -s -c cjY.txt -b cjY.txt --max-time 120 -H "X-WP-Nonce: $NONCE_R" -H "Content-Type: application/json" \
  -d '{"data":{"order":["stats","faq"],"bstyle":{"stats":{"bg":"#ffffff","bg_tr":"125"},"faq":{"bg":"","bg_tr":"30"}}}}' "$B/wp-json/wpd/v1/page/$PID" -o y_imp.json
t "REST import: transparency clamped, kept only with colour" "$(eq "$(js y_imp.json 'd["data"]["bstyle"]["stats"]["bg_tr"], "faq" in d["data"]["bstyle"]')" "100 False")"
curl -s -c cjY.txt -b cjY.txt --max-time 120 -H "X-WP-Nonce: $NONCE_R" "$B/wp-json/wpd/v1/page/$PID" -o y_exp.json
t "REST export: bg_tr" "$(grep -q '"bg_tr":"100"' y_exp.json && echo 1 || echo 0)"

curl -sL --max-time 120 "$B/wpd-y-helper.php?cleanup=1" > y_clean.json
curl -s --max-time 60 "$B/wpd-set.php?json=%7B%7D" > /dev/null
t "cleanup" "$(eq "$(js y_clean.json 'd["cleaned"]')" True)"
echo "PHASE Y: PASS=$PASS FAIL=$FAIL"
