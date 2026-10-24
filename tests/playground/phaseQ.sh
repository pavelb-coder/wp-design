#!/bin/bash
# Фаза Q (2.3.0): HTTP-проверки стиля «Мягкий градиент» — поля на вкладках, настоящее сохранение «Оформления»
# (переключение подставляет палитру и стиль главного блока), страница сайта (классы, фон <body>, иллюстрация, логотип),
# встроенные изображения отдаются сервером, возврат к классическому стилю. Помощник — wpd-q-page.php.
# В конце сбрасывает опцию и удаляет тестовую страницу.
B=${WPD_BASE:-http://127.0.0.1:9400}
PASS=0; FAIL=0
t() { if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
has() { grep -q -- "$2" "$1" && echo 1 || echo 0; }
hasnt() { grep -q -- "$2" "$1" && echo 0 || echo 1; }
set_opt() { curl -s --max-time 60 "$B/wpd-set.php?json=$(python3 -c 'import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))' "$1")" > /dev/null; }
nonce() { grep -o 'name="_wpnonce" value="[^"]*"' "$1" | head -1 | sed 's/.*value="//;s/"//'; }
all1() { for v in "$@"; do [ "$v" = "1" ] || { echo 0; return; }; done; echo 1; }

rm -f cjQ.txt
curl -s -c cjQ.txt -b cjQ.txt -o /dev/null --max-time 60 "$B/wp-login.php"
curl -s -c cjQ.txt -b cjQ.txt -o /dev/null --max-time 60 -d "log=admin&pwd=password&wp-submit=Log+In&redirect_to=/wp-admin/&testcookie=1" "$B/wp-login.php"
get() { curl -s -c cjQ.txt -b cjQ.txt --max-time 120 "$B$1" -o "$2"; }
post() { curl -s -c cjQ.txt -b cjQ.txt -o /dev/null --max-time 120 -w "%{redirect_url}" "$@" "$B/wp-admin/options.php"; }
state() { curl -sL --max-time 60 "$B/wpd-q-page.php?style=$1" > q_state.json; }
jq_() { python3 -c "import json,sys;print(json.load(open('q_state.json'))[sys.argv[1]])" "$1"; }

set_opt '{}'

# Вкладки настроек
get "/wp-admin/admin.php?page=wpd-settings&tab=design" q_design.html
t "design tab: style select with two options" "$(python3 -c "import re;s=open('q_design.html',encoding='utf-8').read();m=re.search(r'<select[^>]*name=\"wpd_settings\[design_style\]\"[^>]*>(.*?)</select>',s,re.S);print(1 if m and 'value=\"classic\"' in m.group(1) and 'value=\"soft\"' in m.group(1) else 0)")"
t "design tab: classic selected by default" "$(grep -qE '<option value="classic"\s*selected' q_design.html && echo 1 || echo 0)"
t "design tab: palette soft offered" "$(has q_design.html 'name="wpd_settings\[palette\]" value="soft"')"
get "/wp-admin/admin.php?page=wpd-settings&tab=hero" q_hero.html
t "hero tab: orb checkbox checked" "$(grep -oE '<input[^>]*name="wpd_settings\[hero_soft_orb\]"[^>]*>' q_hero.html | grep -q checked && echo 1 || echo 0)"
t "hero tab: soft hero style option" "$(has q_hero.html '<option value="soft"')"
get "/wp-admin/admin.php?page=wpd-settings&tab=contacts" q_contacts.html
t "contacts tab: logo select and url" "$(all1 "$(has q_contacts.html 'name="wpd_settings\[contact_logo\]"')" "$(has q_contacts.html 'name="wpd_settings\[contact_logo_url\]"')")"

# Настоящее сохранение «Оформления»: классический → мягкий градиент
N=$(nonce q_design.html)
LOC=$(post --data-urlencode "option_page=wpd_settings_group" --data-urlencode "action=update" --data-urlencode "_wpnonce=$N" \
  --data-urlencode "_wp_http_referer=/wp-admin/admin.php?page=wpd-settings&tab=design" --data-urlencode "wpd_settings[_tab]=design" \
  --data-urlencode "wpd_settings[design_style]=soft" --data-urlencode "wpd_settings[palette]=custom" \
  --data-urlencode "wpd_settings[heading_underline]=1" --data-urlencode "wpd_settings[card_hover]=1" --data-urlencode "wpd_settings[hide_title]=1")
t "design save: redirect back" "$(echo "$LOC" | grep -q 'tab=design' && echo 1 || echo 0)"
get "/wp-admin/admin.php?page=wpd-settings&tab=design" q_design2.html
t "after save: soft selected" "$(grep -qE '<option value="soft"\s*selected' q_design2.html && echo 1 || echo 0)"
t "after save: palette soft checked" "$(grep -oE '<input[^>]*name="wpd_settings\[palette\]" value="soft"[^>]*>' q_design2.html | grep -q checked && echo 1 || echo 0)"
t "after save: primary colour of palette" "$(has q_design2.html 'value="#2235f8"')"
get "/wp-admin/admin.php?page=wpd-settings&tab=hero" q_hero2.html
t "after save: hero style soft" "$(grep -qE '<option value="soft"\s*selected' q_hero2.html && echo 1 || echo 0)"

# Страница сайта
state soft
t "helper: soft state" "$( [ "$(jq_ design_style)$(jq_ palette)$(jq_ hero_style)" = "softsoftsoft" ] && echo 1 || echo 0)"
curl -sL --max-time 120 "$B/olimpiada-q/" -o q_page.html
t "page: soft container" "$(has q_page.html 'class="wpd-page wpd-style-soft"')"
t "page: body class" "$(grep -oE '<body[^>]*>' q_page.html | grep -q 'wpd-soft-page' && echo 1 || echo 0)"
t "page: body background inline" "$(has q_page.html 'body.wpd-soft-page{background-color:#f5f7ff;}')"
t "page: soft hero with orb" "$(has q_page.html 'wpd-hero wpd-hero--soft wpd-hero--orb')"
t "page: timer inside hero" "$(has q_page.html 'wpd-hero__countdown')"
t "page: logo in contacts with org link" "$(has q_page.html '<a class="wpd-contacts__logo-link" href="https://example.com/"')"
t "page: front script loaded" "$(has q_page.html 'wp-olympiad-design/assets/js/wpd.js')"
ORB=$(grep -o 'src="[^"]*cities-orb.webp"' q_page.html | head -1 | sed 's/src="//;s/"$//')
LOGO=$(grep -o 'src="[^"]*kvantorium-logo.webp"' q_page.html | head -1 | sed 's/src="//;s/"$//')
read -r C1 T1 <<< "$(curl -s -o /dev/null --max-time 60 -w '%{http_code} %{content_type}' "$ORB")"
read -r C2 T2 <<< "$(curl -s -o /dev/null --max-time 60 -w '%{http_code} %{content_type}' "$LOGO")"
t "orb image served (200, image/webp)" "$( [ "$C1" = "200" ] && echo "$T1" | grep -q 'image/webp' && echo 1 || echo 0)"
t "logo image served (200, image/webp)" "$( [ "$C2" = "200" ] && echo "$T2" | grep -q 'image/webp' && echo 1 || echo 0)"

# Без иллюстрации
curl -sL --max-time 60 "$B/wpd-q-page.php?style=soft&orb=0" > /dev/null
curl -sL --max-time 120 "$B/olimpiada-q/" -o q_page_noorb.html
t "orb off: soft hero without media" "$(all1 "$(hasnt q_page_noorb.html 'wpd-hero__media')" "$(has q_page_noorb.html 'wpd-hero--soft')")"

# Возврат к классическому стилю
state classic
t "helper: classic state, palette olympiad, hero dark" "$( [ "$(jq_ design_style)$(jq_ palette)$(jq_ hero_style)" = "classicolympiaddark" ] && echo 1 || echo 0)"
curl -sL --max-time 120 "$B/olimpiada-q/" -o q_page_classic.html
t "classic: no soft classes" "$(hasnt q_page_classic.html 'wpd-style-soft')"
t "classic: no body background" "$(hasnt q_page_classic.html 'wpd-soft-page')"
t "classic: dark hero, no orb, no logo" "$(all1 "$(has q_page_classic.html 'wpd-hero wpd-hero--dark')" "$(hasnt q_page_classic.html 'cities-orb')" "$(hasnt q_page_classic.html 'kvantorium-logo')")"

# Уборка: тестовая страница и опция
curl -sL --max-time 60 "$B/wpd-q-page.php?cleanup=1" > q_clean.txt
t "cleanup: page and option removed" "$(has q_clean.txt 'CLEANED')"
echo
echo "TOTAL: $((PASS+FAIL)), FAILED: $FAIL"
