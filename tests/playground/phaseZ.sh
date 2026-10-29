#!/bin/bash
# Фаза Z (2.12.0): HTTP-проверки кнопки таймера — вывод на сайте (кнопки этапов, вид, иконка, подпись, атрибуты ссылки,
# таймер главного блока, панель «Блоки олимпиады»), CSS и скрипт сайта, раздел «Кнопка таймера» на вкладке настроек
# и его сохранение через options.php, поля панели страницы, данные редактора, справка. Помощник — wpd-z-helper.php.
B=${WPD_BASE:-http://127.0.0.1:9400}
PASS=0; FAIL=0
t() { if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
has() { grep -q -- "$2" "$1" && echo 1 || echo 0; }
hasnt() { grep -q -- "$2" "$1" && echo 0 || echo 1; }
eq() { [ "$1" = "$2" ] && echo 1 || echo 0; }
js() { python3 -c "import json,sys;d=json.load(open(sys.argv[1]));exec('print('+sys.argv[2]+')')" "$1" "$2" 2>/dev/null; }
nowarn() { grep -qE '<b>(Warning|Notice|Deprecated|Fatal error)</b>' "$1" && echo 0 || echo 1; }
all() { for v in "$@"; do [ "$v" = 1 ] || { echo 0; return; }; done; echo 1; }
# Блок таймера по классу обёртки: python печатает его разметку до следующего блока
part() { python3 - "$1" "$2" <<'PY'
import re, sys
s = open(sys.argv[1], encoding='utf-8').read()
i = s.find('wpd-page--countdown ' + sys.argv[2])
j = s.find('<div class="wpd-page wpd-page--block', i + 10)
print(s[i:j if j > 0 else len(s)] if i >= 0 else '')
PY
}
vis() { python3 - "$1" "$2" <<'PY'
import re, sys
s = open(sys.argv[1], encoding='utf-8').read()
out = []
for a in re.findall(r'<a class="wpd-btn wpd-countdown__link[^>]*>', s):
    m = re.search(r'data-when="(\w+)"', a)
    out.append((m.group(1) if m else 'stage') + ('-' if re.search(r'\shidden>$', a) else '+'))
print(' '.join(out))
PY
}

curl -s --max-time 60 "$B/wpd-set.php?json=%7B%7D" > /dev/null
curl -sL --max-time 120 "$B/wpd-z-helper.php?setup=1" > z_setup.json
ID=$(js z_setup.json 'd["id"]'); URL=$(js z_setup.json 'd["url"]'); PID=$(js z_setup.json 'd["pid"]'); PURL=$(js z_setup.json 'd["purl"]')
t "helper setup" "$( [ -n "$ID" ] && [ -n "$PID" ] && echo 1 || echo 0)"

# Сайт: блоки таймера
curl -sL --max-time 120 "$URL" -o z_page.html
part z_page.html z-active > z_active.html
part z_page.html z-before > z_before.html
part z_page.html z-done > z_done.html
t "front active: button look classes" "$(has z_active.html 'class="wpd-countdown wpd-cdb wpd-cdb--outline wpd-cdb--r-pill wpd-cdb--center wpd-cdb--h wpd-cdb--fg wpd-cdb--hbg wpd-cdb--icon wpd-cdb--icon-left"')"
t "front active: button vars" "$(has z_active.html 'style="--wpd-cdb-h:52px;--wpd-cdb-fg:#b91c1c;--wpd-cdb-hbg:#fde68a;--wpd-cdb-icon:22px;"')"
t "front active: only stage button visible" "$(eq "$(vis z_active.html)" 'before- stage+ after-')"
t "front active: icon left, nofollow, title" "$(all "$(has z_active.html 'rel="noopener noreferrer nofollow" title="Подсказка Z"><span class="wpd-countdown__icon dashicons dashicons-download" aria-hidden="true"></span>Задания Z</a>')" "$(has z_page.html 'dashicons-css')")"
t "front active: note in wrapper" "$(has z_active.html '<p class="wpd-countdown__note">Вход по логину Z</p></div>')"
t "front before: before button visible, link style" "$(all "$(eq "$(vis z_before.html)" 'before+ stage-')" "$(has z_before.html 'wpd-cdb wpd-cdb--link"')" "$(has z_before.html '>Регистрация Z</a>')")"
t "front done: after button visible, full width soft" "$(all "$(eq "$(vis z_done.html)" 'stage- after+')" "$(has z_done.html 'wpd-cdb--soft wpd-cdb--full wpd-cdb--bg')" "$(has z_done.html '>Результаты Z &rarr;</a>')")"
t "front hero: timer button from block" "$(all "$(has z_page.html 'wpd-hero__countdown')" "$(has z_page.html 'href="https://example.com/hero-z" target="_blank" rel="noopener noreferrer">Герой Z &rarr;</a>')")"
t "front: no PHP warnings" "$(nowarn z_page.html)"
curl -sL --max-time 120 "$PURL" -o z_panel.html
t "front panel page: own button look" "$(all "$(has z_panel.html 'wpd-cdb wpd-cdb--link wpd-cdb--below')" "$(has z_panel.html 'Подпись Z')" "$(has z_panel.html 'href="https://example.com/panel-z"')")"
curl -sL --max-time 60 "$B/wp-content/plugins/wp-olympiad-design/assets/css/wpd.css" -o z_css.css
curl -sL --max-time 60 "$B/wp-content/plugins/wp-olympiad-design/assets/js/wpd.js" -o z_js.js
t "css and script served" "$(all "$(has z_css.css 'wpd-cdb--outline')" "$(has z_css.css 'wpd-countdown__actions')" "$(has z_js.js "data-when")")"

# Админка
login() { rm -f "$1"; curl -s -c "$1" -b "$1" -o /dev/null --max-time 60 "$B/wp-login.php"
  curl -s -c "$1" -b "$1" -o /dev/null --max-time 60 --data-urlencode "log=$2" --data-urlencode "pwd=$3" -d "wp-submit=Log+In&redirect_to=/wp-admin/&testcookie=1" "$B/wp-login.php"; }
login cjZ.txt admin password
get() { curl -s -c cjZ.txt -b cjZ.txt --max-time 180 "$B$1" -o "$2"; }
get "/wp-admin/admin.php?page=wpd-settings&tab=features" z_features.html
t "features tab: button section and fields" "$(all "$(has z_features.html 'Кнопка таймера: этапы, вид и поведение')" "$(has z_features.html 'wpd_settings\[countdown_before_url\]')" "$(has z_features.html 'wpd_settings\[countdown_btn_style\]')" "$(has z_features.html 'wpd_settings\[countdown_btn_hover_color\]')" "$(has z_features.html 'wpd_settings\[countdown_btn_note\]')")"
t "features tab: icon select with arrow" "$(all "$(has z_features.html '<option value="arrow"')" "$(has z_features.html 'Стрелка →')")"
t "features tab: no PHP warnings" "$(nowarn z_features.html)"
get "/wp-admin/admin.php?page=wpd-settings&tab=help" z_help.html
t "help: 2.12.0 section" "$(has z_help.html 'Кнопка таймера (2.12.0)')"
get "/wp-admin/post.php?post=$PID&action=edit" z_edit.html
t "page panel: button fields with saved values" "$(all "$(has z_edit.html 'wpd_page\[ov\]\[countdown_btn_style\]')" "$(has z_edit.html 'wpd_page\[ov\]\[countdown_after_url\]')" "$(python3 -c "import re;s=open('z_edit.html',encoding='utf-8').read();m=re.search(r'<select[^>]*name=\"wpd_page\[ov\]\[countdown_btn_style\]\".*?</select>',s,re.S);print(1 if m and re.search(r'value=\"link\"[^>]*selected',m.group(0)) else 0)")")"
t "page panel: no PHP warnings" "$(nowarn z_edit.html)"
get "/wp-admin/post.php?post=$ID&action=edit" z_bedit.html
t "editor data: button options and hero timer" "$(all "$(has z_bedit.html '"cdBtnStyle"')" "$(has z_bedit.html '"heroCountdown"')" "$(has z_bedit.html '"btn_look"')" "$(has z_bedit.html '"cdLinkUrl"')")"
# Сохранение раздела через options.php: значения проверяются
NONCE=$(python3 -c "import re;s=open('z_features.html',encoding='utf-8').read();m=re.search(r'name=\"_wpnonce\" value=\"([^\"]+)\"',s);print(m.group(1) if m else '')")
curl -s -c cjZ.txt -b cjZ.txt --max-time 180 -o /dev/null -e "$B/wp-admin/admin.php?page=wpd-settings&tab=features" \
  --data-urlencode "option_page=wpd_settings_group" --data-urlencode "action=update" --data-urlencode "_wpnonce=$NONCE" \
  --data-urlencode "wpd_settings[_tab]=features" --data-urlencode "wpd_settings[countdown_btn_style]=outline" --data-urlencode "wpd_settings[countdown_btn_height]=200" \
  --data-urlencode "wpd_settings[countdown_before_url]=javascript:alert(1)" --data-urlencode "wpd_settings[countdown_btn_bg]=#123456" \
  --data-urlencode "wpd_settings[countdown_btn_icon]=evil" --data-urlencode "wpd_settings[countdown_btn_note]=<b>Подпись</b>" "$B/wp-admin/options.php"
get "/wp-admin/admin.php?page=wpd-settings&tab=features" z_features2.html
val() { python3 - z_features2.html "$1" <<'PY'
import re, sys
s = open(sys.argv[1], encoding='utf-8').read()
k = re.escape('wpd_settings[' + sys.argv[2] + ']')
m = re.search(r'<select[^>]*name="' + k + r'".*?</select>', s, re.S)
if m:
    o = re.search(r'<option value="([^"]*)"[^>]*selected', m.group(0)); print(o.group(1) if o else '')
else:
    m = re.search(r'<input[^>]*name="' + k + r'"[^>]*>', s); v = re.search(r'value="([^"]*)"', m.group(0)) if m else None; print(v.group(1) if v else '')
PY
}
t "settings save: values checked" "$(all "$(eq "$(val countdown_btn_style)" outline)" "$(eq "$(val countdown_btn_height)" 80)" "$(eq "$(val countdown_before_url)" '')" "$(eq "$(val countdown_btn_bg)" '#123456')" "$(eq "$(val countdown_btn_icon)" arrow)" "$(eq "$(val countdown_btn_note)" 'Подпись')")"

curl -sL --max-time 120 "$B/wpd-z-helper.php?cleanup=1" > z_clean.json
curl -s --max-time 60 "$B/wpd-set.php?json=%7B%7D" > /dev/null
t "cleanup" "$(eq "$(js z_clean.json 'd["cleaned"]')" True)"
echo "PHASE Z: PASS=$PASS FAIL=$FAIL"
