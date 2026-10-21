#!/bin/bash
# Фаза P (2.2.0): HTTP-проверки после удаления регистрации участников и личного кабинета.
# Меню и адреса бывших разделов, старый шорткод на странице; данные 2.1.0 (450 заявок, участники, роль, настройки)
# удаляются порциями по 200 настоящими запросами сайта; бывший участник не входит; уведомление о страницах
# со старыми шорткодами и его скрытие. Помощник — wpd-p-helper.php. В конце удаляет созданное.
B=${WPD_BASE:-http://127.0.0.1:9400}
PASS=0; FAIL=0
t() { if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
has() { grep -q -- "$2" "$1" && echo 1 || echo 0; }
hasnt() { grep -q -- "$2" "$1" && echo 0 || echo 1; }
q() { python3 -c 'import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))' "$1"; }
H() { curl -sL --max-time 300 "$B/wpd-p-helper.php?$1"; }
js() { python3 -c "import json,sys;d=json.loads(sys.argv[1]);print(eval(sys.argv[2]))" "$1" "$2"; }
amp() { sed 's/&#038;/\&/g; s/&amp;/\&/g'; }
rm -f cjPA.txt cjPU.txt
curl -s -o /dev/null "$B/wpd-p-helper.php"; curl -s -o /dev/null "$B/wpd-p-helper.php"

# ---------- Администратор: разделов больше нет ----------
curl -s -c cjPA.txt -b cjPA.txt -o /dev/null "$B/wp-login.php"
curl -s -c cjPA.txt -b cjPA.txt -o /dev/null --max-time 60 -d "log=admin&pwd=password&wp-submit=Log+In&redirect_to=/wp-admin/&testcookie=1" "$B/wp-login.php"
a() { curl -s -c cjPA.txt -b cjPA.txt --max-time 180 "$@"; }
a "$B/wp-admin/admin.php?page=wpd-settings" -o p_set.html
t "menu: settings page works"            "$(has p_set.html 'wpd-settings')"
t "menu: no «Регистрация»"                "$(hasnt p_set.html 'page=wpd-registration')"
t "menu: no «Участники»"                  "$(hasnt p_set.html 'post_type=wpd_application')"
t "help: no registration section"        "$(a "$B/wp-admin/admin.php?page=wpd-settings&tab=help" -o p_help.html; hasnt p_help.html 'Регистрация участников')"
CODE=$(a -o p_reg.html -w "%{http_code}" "$B/wp-admin/admin.php?page=wpd-registration")
t "old settings page: not available"     "$([ "$CODE" = "403" ] || [ "$(has p_reg.html 'not allowed')" = "1" ] && echo 1 || echo 0)"
a -o p_apps.html "$B/wp-admin/edit.php?post_type=wpd_application"
t "old applications list: invalid type"  "$(grep -q 'Invalid post type\|Неверный тип записи' p_apps.html && echo 1 || echo 0)"
CODE=$(a -o /dev/null -w "%{http_code}" "$B/wp-admin/admin-post.php?action=wpd_reg_export")
t "old CSV export: no handler"           "$([ "$CODE" != "200" ] || [ "$(a -s "$B/wp-admin/admin-post.php?action=wpd_reg_export" | head -c 20 | grep -c 'Номер')" = "0" ] && echo 1 || echo 0)"
a "$B/wp-admin/post-new.php?post_type=page" -o p_new.html
t "editor: registration block script gone" "$(hasnt p_new.html 'wpd-reg-blocks')"

# ---------- Данные 2.1.0 и очистка порциями ----------
SEED=$(H "seed=450")
PAGE=$(js "$SEED" "d['page']"); PID=$(js "$SEED" "d['page_id']")
S=$(H "state=1")
t "seed: 450 applications, 3 participants" "$(js "$S" "1 if d['apps']==450 and d['users']>=3 and d['role'] and d['opt'] and d['done']=='' else 0")"
curl -s --max-time 300 -o p_front1.html "$B/"
S=$(H "state=1")
t "request 1: batches of 200, not finished" "$(js "$S" "1 if d['apps'] in (250, 50) and d['done']=='' and d['role'] and d['opt'] else str(d)")"
N=1
while [ "$(js "$(H state=1)" "d['done']")" != "2.2.0" ] && [ $N -lt 6 ]; do curl -s --max-time 300 -o /dev/null "$B/"; N=$((N+1)); done
S=$(H "state=1")
t "finished within 3 site requests"      "$([ $N -le 3 ] && echo 1 || echo 0)"
t "everything removed"                   "$(js "$S" "1 if d['apps']==0 and d['users']==0 and not d['role'] and not d['opt'] and d['trans']==0 else str(d)")"
t "marked done (2.2.0)"       "$(js "$S" "1 if d['done']=='2.2.0' else str(d)")"
t "pages with old shortcode recorded"    "$(js "$S" "1 if d['pages']==[$PID] else str(d)")"
t "site pages still work during cleanup" "$(grep -qi '<html' p_front1.html && echo 1 || echo 0)"
rm -f cjPU.txt; curl -s -c cjPU.txt -b cjPU.txt -o /dev/null "$B/wp-login.php"
LOC=$(curl -s -c cjPU.txt -b cjPU.txt -o /dev/null -w "%{redirect_url}" --max-time 60 -d "log=p2_part0&pwd=P-pass-2021&wp-submit=Log+In&testcookie=1" "$B/wp-login.php")
t "former participant can't log in"      "$([ -z "$LOC" ] && echo 1 || echo 0)"

# ---------- Старый шорткод на странице ----------
curl -s --max-time 120 "$PAGE" -o p_page.html
t "page: old shortcode shown as text"    "$(has p_page.html '\[wpd_registration\]')"
t "page: no form markup"                 "$([ "$(hasnt p_page.html 'wpd-reg')$(hasnt p_page.html 'name="wpd_reg_action"')" = "11" ] && echo 1 || echo 0)"

# ---------- Уведомление администратору ----------
a "$B/wp-admin/index.php" -o p_dash.html
t "notice: shown on dashboard"           "$(has p_dash.html 'wpd-reg-removed-notice')"
t "notice: lists the page with edit link" "$(grep -q "post.php?post=$PID&#038;action=edit\">P2 форма</a>" p_dash.html && echo 1 || echo 0)"
DIS=$(grep -o 'admin-post.php?action=wpd_reg_pages_dismiss&#038;_wpnonce=[a-f0-9]*' p_dash.html | head -1 | amp)
t "notice: dismiss link"                 "$([ -n "$DIS" ] && echo 1 || echo 0)"
CODE=$(a -o /dev/null -w "%{http_code}" "$B/wp-admin/admin-post.php?action=wpd_reg_pages_dismiss&_wpnonce=bad")
t "dismiss: bad nonce refused"           "$([ "$CODE" != "302" ] && echo 1 || echo 0)"
CODE=$(curl -s -o /dev/null -w "%{http_code}" "$B/wp-admin/admin-post.php?action=wpd_reg_pages_dismiss&_wpnonce=x")
t "dismiss: guest gets no redirect"      "$([ "$CODE" != "302" ] && echo 1 || echo 0)"
LOC=$(a -o /dev/null -w "%{redirect_url}" -e "$B/wp-admin/index.php" "$B/wp-admin/$DIS")
t "dismiss: redirect back"               "$(echo "$LOC" | grep -q 'wp-admin/index.php' && echo 1 || echo 0)"
a "$B/wp-admin/index.php" -o p_dash2.html
t "dismiss: notice gone"                 "$(hasnt p_dash2.html 'wpd-reg-removed-notice')"
S=$(H "state=1")
t "dismiss: list cleared"                "$(js "$S" "1 if d['pages']==[] else str(d)")"

H cleanup=1 > /dev/null
[ -z "$KEEP" ] && rm -f cjPA.txt cjPU.txt p_*.html
echo
echo "TOTAL: $((PASS+FAIL)), FAILED: $FAIL"
