#!/bin/bash
# Фаза O (2.1.0): HTTP-проверки пароля при регистрации и смены пароля в личном кабинете.
# Гость: поля пароля, ошибки (короткий, несовпадение, без цифр, совпадает с e-mail) без вывода пароля обратно,
# успешная заявка → вход по e-mail и паролю через wp-login, письма без ссылки и без пароля.
# Кабинет: «Сменить пароль», неверный текущий, несовпадение, устаревшая сессия, успешная смена (вход сохраняется,
# старый пароль больше не подходит). Режимы: пароль выключен (ссылка WordPress), без аккаунтов, своя минимальная длина,
# настройки в админке. Использует помощник фазы N; в конце удаляет всё созданное.
B=${WPD_BASE:-http://127.0.0.1:9400}
PASS=0; FAIL=0
t() { if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
has() { grep -q -- "$2" "$1" && echo 1 || echo 0; }
hasnt() { grep -q -- "$2" "$1" && echo 0 || echo 1; }
q() { python3 -c 'import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))' "$1"; }
H() { curl -sL --max-time 60 "$B/wpd-n-helper.php?$1"; }
opt() { H "opt=$(q "$1")" > /dev/null; }
js() { python3 -c "import json,sys;d=json.loads(sys.argv[1]);print(eval(sys.argv[2]))" "$1" "$2"; }
tok() { grep -o 'name="wpd_ts" value="[^"]*"' "$1" | head -1 | sed 's/.*value="//;s/"//'; }
nonce() { grep -o "name=\"$2\" value=\"[^\"]*\"" "$1" | head -1 | sed 's/.*value="//;s/"//'; }
amp() { sed 's/&#038;/\&/g; s/&amp;/\&/g'; }
g() { local jar=$1; shift; curl -s -c "$jar" -b "$jar" --max-time 120 "$@"; }
OUTF=o_post.html
send() { local jar=$1 url=$2; shift 2; curl -s -c "$jar" -b "$jar" --max-time 120 -o "$OUTF" -w "%{redirect_url}" "$@" "$url"; }
# Вход через wp-login: печатает адрес перенаправления (пусто — вход не удался)
login() { local jar=$1; rm -f "$jar"; curl -s -c "$jar" -b "$jar" -o /dev/null "$B/wp-login.php";
  curl -s -c "$jar" -b "$jar" -o /dev/null -w "%{redirect_url}" --max-time 60 --data-urlencode "log=$2" --data-urlencode "pwd=$3" \
    --data-urlencode "wp-submit=Log In" --data-urlencode "redirect_to=$CAB" --data-urlencode "testcookie=1" "$B/wp-login.php"; }

curl -s -o /dev/null "$B/wpd-n-helper.php"; curl -s -o /dev/null "$B/wpd-n-helper.php"
SETUP=$(H "setup=1")
FORM=$(js "$SETUP" "d['form']"); CAB=$(js "$SETUP" "d['cabinet']")
t "setup: pages created" "$([ -n "$FORM" ] && [ -n "$CAB" ] && echo 1 || echo 0)"
rm -f cjO*.txt
BASE=(--data-urlencode "wpd_reg_action=register" --data-urlencode "wpd_hp=" --data-urlencode "wpd_consent=1"
  --data-urlencode "wpd_reg[name]=Соколова Анна" --data-urlencode "wpd_reg[phone]=+7 900 555-44-33"
  --data-urlencode "wpd_reg[grade]=10" --data-urlencode "wpd_reg[school]=Гимназия № 2" --data-urlencode "wpd_reg[city]=Красноярск"
  --data-urlencode "wpd_reg[email]=anna2026@n-test.ru")

# ---------- Форма ----------
g cjO.txt "$FORM" -o o_form.html
t "form: password field"                 "$(has o_form.html 'name="wpd_pass" value="" autocomplete="new-password" minlength="8" maxlength="128" required data-wpd-pass')"
t "form: repeat field"                   "$(has o_form.html 'name="wpd_pass2" value="" autocomplete="new-password"')"
t "form: hint"                           "$(has o_form.html 'Не короче 8 символов, буквы и цифры.')"
t "form: show-password toggle"           "$(has o_form.html 'data-wpd-showpass')"
t "form: wpd.js enqueued"                "$(has o_form.html 'assets/js/wpd.js')"
T=$(tok o_form.html)
sleep 4

# ---------- Ошибки ----------
LOC=$(send cjO.txt "$FORM" "${BASE[@]}" --data-urlencode "wpd_ts=$T" --data-urlencode "wpd_pass=ab1" --data-urlencode "wpd_pass2=ab1")
t "short: no redirect"                   "$([ -z "$LOC" ] && echo 1 || echo 0)"
t "short: message"                       "$(has $OUTF 'Пароль должен быть не короче 8 символов.')"
t "short: other values kept"             "$(has $OUTF 'value="Гимназия № 2"')"
t "short: password not echoed"           "$(hasnt $OUTF 'value="ab1"')"
t "short: field marked invalid"          "$(has $OUTF 'name="wpd_pass" value="" autocomplete="new-password" minlength="8" maxlength="128" required data-wpd-pass aria-invalid="true"')"
LOC=$(send cjO.txt "$FORM" "${BASE[@]}" --data-urlencode "wpd_ts=$T" --data-urlencode "wpd_pass=Sekret2026a" --data-urlencode "wpd_pass2=Sekret2026b")
t "mismatch: rejected"                   "$([ -z "$LOC" ] && [ "$(has $OUTF 'Пароли не совпадают.')" = "1" ] && echo 1 || echo 0)"
t "mismatch: neither value echoed"       "$([ "$(hasnt $OUTF 'Sekret2026a')$(hasnt $OUTF 'Sekret2026b')" = "11" ] && echo 1 || echo 0)"
LOC=$(send cjO.txt "$FORM" "${BASE[@]}" --data-urlencode "wpd_ts=$T" --data-urlencode "wpd_pass=onlyletters" --data-urlencode "wpd_pass2=onlyletters")
t "no digits: rejected"                  "$([ -z "$LOC" ] && [ "$(has $OUTF 'Пароль должен содержать буквы и цифры.')" = "1" ] && echo 1 || echo 0)"
LOC=$(send cjO.txt "$FORM" "${BASE[@]}" --data-urlencode "wpd_ts=$T" --data-urlencode "wpd_pass=Anna2026" --data-urlencode "wpd_pass2=Anna2026")
t "equals e-mail: rejected"              "$([ -z "$LOC" ] && [ "$(has $OUTF 'Пароль не должен совпадать с e-mail.')" = "1" ] && echo 1 || echo 0)"
LOC=$(send cjO.txt "$FORM" "${BASE[@]}" --data-urlencode "wpd_ts=$T")
t "missing: «Придумайте пароль.»"         "$([ -z "$LOC" ] && [ "$(has $OUTF 'Придумайте пароль.')" = "1" ] && echo 1 || echo 0)"
t "errors: nothing created"              "$([ "$(H apps=1)" = "[]" ] && [ "$(H 'user=anna2026@n-test.ru')" = "null" ] && echo 1 || echo 0)"

# ---------- Успешная заявка ----------
H "mails=1&clear=1" > /dev/null
LOC=$(send cjO.txt "$FORM" "${BASE[@]}" --data-urlencode "wpd_ts=$T" --data-urlencode "wpd_pass=Звезда 2026\"<x>" --data-urlencode "wpd_pass2=Звезда 2026\"<x>")
t "valid: redirect to cabinet"           "$(echo "$LOC" | grep -q 'wpd_reg=done' && echo 1 || echo 0)"
U=$(H "user=anna2026@n-test.ru")
t "valid: participant account"           "$(js "$U" "1 if d and d['roles']==['wpd_participant'] and d['login']=='anna2026' else 0")"
t "valid: logged in right away"          "$(grep -q 'wordpress_logged_in' cjO.txt && echo 1 || echo 0)"
MAILS=$(H "mails=1")
t "mails: participant + organizer only"  "$(js "$MAILS" "1 if len(d)==2 else len(d)")"
t "mails: no set-password link"          "$(js "$MAILS" "0 if any('action=rp' in m['message'] for m in d) else 1")"
t "mails: login info in user mail"       "$(js "$MAILS" "1 if any(m['to']=='anna2026@n-test.ru' and 'Для входа используйте e-mail anna2026@n-test.ru (или логин anna2026) и пароль, который вы указали при регистрации.' in m['message'] for m in d) else 0")"
t "mails: password never sent"           "$(js "$MAILS" "0 if any('Звезда' in m['message'] for m in d) else 1")"
URL=$(echo "$LOC" | sed 's/#.*//')
g cjO.txt "$URL" -o o_cab.html
t "cabinet: application shown"           "$(has o_cab.html 'class="wpd-cabinet__number">OL-0001')"
t "cabinet: «Сменить пароль» link"        "$(has o_cab.html 'wpd_pass=1#wpd-cabinet">Сменить пароль</a>')"
# Вход с выбранным паролем (пароль со спецсимволами и пробелом не искажается)
LOC=$(login cjO2.txt "anna2026@n-test.ru" "Звезда 2026\"<x>")
t "login: e-mail + chosen password"      "$(echo "$LOC" | grep -qF "${CAB%%\?*}" && echo 1 || echo 0)"
g cjO2.txt "$CAB" -o o_cab2.html
t "login: cabinet after login"           "$(has o_cab2.html 'class="wpd-cabinet__number">OL-0001')"
LOC=$(login cjO3.txt "anna2026" "Звезда 2026\"<x>")
t "login: login name + chosen password"  "$(echo "$LOC" | grep -qF "${CAB%%\?*}" && echo 1 || echo 0)"
LOC=$(login cjO3.txt "anna2026@n-test.ru" "Звезда 2026")
t "login: wrong password refused"        "$([ -z "$LOC" ] && echo 1 || echo 0)"
# Гость после регистрации без автовхода видит подсказку про пароль
opt '{"autologin":0}'
g cjO4.txt "$FORM" -o o_form4.html; T4=$(tok o_form4.html); sleep 4
LOC=$(send cjO4.txt "$FORM" "${BASE[@]}" --data-urlencode "wpd_reg[email]=boris7@n-test.ru" --data-urlencode "wpd_reg[name]=Борисов Борис" \
  --data-urlencode "wpd_ts=$T4" --data-urlencode "wpd_pass=Boris-pass-77" --data-urlencode "wpd_pass2=Boris-pass-77")
curl -s --max-time 120 "$(echo "$LOC" | sed 's/#.*//')" -o o_cab4.html
t "no autologin: notice about own password" "$(has o_cab4.html 'Войдите с e-mail и паролем, которые вы указали при регистрации.')"
t "no autologin: not logged in"          "$(grep -q 'wordpress_logged_in' cjO4.txt && echo 0 || echo 1)"
t "no autologin: login form shown"       "$(has o_cab4.html 'id="wpd-login"')"
LOC=$(login cjO4.txt "boris7@n-test.ru" "Boris-pass-77")
t "no autologin: login works"            "$(echo "$LOC" | grep -qF "${CAB%%\?*}" && echo 1 || echo 0)"

# ---------- Смена пароля в кабинете ----------
PC="${CAB%%\#*}"; SEP=$([ "${PC#*\?}" != "$PC" ] && echo '&' || echo '?')
g cjO2.txt "${PC}${SEP}wpd_pass=1" -o o_pf.html
t "change: form shown"                   "$(has o_pf.html '<h3 class="wpd-cabinet__subtitle">Смена пароля</h3>')"
t "change: current password field"       "$(has o_pf.html 'name="wpd_pass_current" value="" autocomplete="current-password"')"
t "change: cancel link"                  "$(has o_pf.html '>Отмена</a>')"
PN=$(nonce o_pf.html _wpd_nonce)
t "change: nonce present"                "$([ -n "$PN" ] && echo 1 || echo 0)"
chg() { curl -s -c cjO2.txt -b cjO2.txt --max-time 120 -o "$OUTF" -w "%{redirect_url}" --data-urlencode "wpd_reg_action=password" \
  --data-urlencode "_wpd_nonce=$1" --data-urlencode "wpd_pass_current=$2" --data-urlencode "wpd_pass=$3" --data-urlencode "wpd_pass2=$4" "$PC"; }
LOC=$(chg "$PN" "wrong-pass-1" "NewPass2027" "NewPass2027")
t "change: wrong current refused"        "$([ -z "$LOC" ] && [ "$(has $OUTF 'Неверный текущий пароль.')" = "1" ] && echo 1 || echo 0)"
t "change: form shown again after error" "$([ "$(has $OUTF 'Пароль не изменён — проверьте поля.')$(has $OUTF 'name="wpd_pass_current"')" = "11" ] && echo 1 || echo 0)"
t "change: values not echoed"            "$([ "$(hasnt $OUTF 'wrong-pass-1')$(hasnt $OUTF 'NewPass2027')" = "11" ] && echo 1 || echo 0)"
LOC=$(chg "$PN" "Звезда 2026\"<x>" "NewPass2027" "NewPass2028")
t "change: mismatch refused"             "$([ -z "$LOC" ] && [ "$(has $OUTF 'Пароли не совпадают.')" = "1" ] && echo 1 || echo 0)"
LOC=$(chg "$PN" "Звезда 2026\"<x>" "Звезда 2026\"<x>" "Звезда 2026\"<x>")
t "change: same as current refused"      "$([ -z "$LOC" ] && [ "$(has $OUTF 'Новый пароль совпадает с текущим.')" = "1" ] && echo 1 || echo 0)"
LOC=$(chg "bad0nce000" "Звезда 2026\"<x>" "NewPass2027" "NewPass2027")
t "change: stale session refused"        "$([ -z "$LOC" ] && [ "$(has $OUTF 'Сессия устарела.')" = "1" ] && echo 1 || echo 0)"
H "mails=1&clear=1" > /dev/null
LOC=$(chg "$PN" "Звезда 2026\"<x>" "NewPass2027" "NewPass2027")
t "change: redirect with notice flag"    "$(echo "$LOC" | grep -q 'wpd_reg=pass_saved#wpd-cabinet' && echo 1 || echo 0)"
t "change: wpd_pass removed from URL"    "$(echo "$LOC" | grep -q 'wpd_pass=' && echo 0 || echo 1)"
g cjO2.txt "$(echo "$LOC" | sed 's/#.*//')" -o o_pf2.html
t "change: still logged in + notice"     "$([ "$(has o_pf2.html 'Пароль изменён.')$(has o_pf2.html 'class="wpd-cabinet__number">OL-0001')" = "11" ] && echo 1 || echo 0)"
MAILS=$(H "mails=1")
t "change: WordPress change notice mail" "$(js "$MAILS" "1 if any(m['to']=='anna2026@n-test.ru' for m in d) and not any('NewPass2027' in m['message'] for m in d) else 0")"
LOC=$(login cjO3.txt "anna2026@n-test.ru" "Звезда 2026\"<x>")
t "change: old password no longer works" "$([ -z "$LOC" ] && echo 1 || echo 0)"
LOC=$(login cjO3.txt "anna2026@n-test.ru" "NewPass2027")
t "change: new password works"           "$(echo "$LOC" | grep -qF "${CAB%%\?*}" && echo 1 || echo 0)"
# Гость не может сменить пароль
curl -s --max-time 120 -o "$OUTF" -w "%{http_code}" --data-urlencode "wpd_reg_action=password" --data-urlencode "_wpd_nonce=$PN" \
  --data-urlencode "wpd_pass_current=NewPass2027" --data-urlencode "wpd_pass=Hacked2027x" --data-urlencode "wpd_pass2=Hacked2027x" "$PC" > /dev/null
LOC=$(login cjO3.txt "anna2026@n-test.ru" "Hacked2027x")
t "change: guest POST ignored"           "$([ -z "$LOC" ] && echo 1 || echo 0)"

# ---------- Администратор ----------
rm -f cjOA.txt; curl -s -c cjOA.txt -b cjOA.txt -o /dev/null "$B/wp-login.php"
curl -s -c cjOA.txt -b cjOA.txt -o /dev/null --max-time 60 -d "log=admin&pwd=password&wp-submit=Log+In&redirect_to=/wp-admin/&testcookie=1" "$B/wp-login.php"
a() { curl -s -c cjOA.txt -b cjOA.txt --max-time 120 "$@"; }
a "${PC}${SEP}wpd_pass=1" -o o_acab.html
t "admin: no cabinet password form"      "$([ "$(hasnt o_acab.html 'Смена пароля')$(hasnt o_acab.html 'Сменить пароль')" = "11" ] && echo 1 || echo 0)"
a "$FORM" -o o_aform.html
t "admin: form without password fields"  "$(hasnt o_aform.html 'name="wpd_pass"')"
a "$B/wp-admin/admin.php?page=wpd-registration" -o o_set.html
t "settings: password checkbox on"       "$(grep -q "name=\"wpd_registration\[password\]\" value=\"1\" checked='checked'> Участник задаёт пароль при регистрации" o_set.html && echo 1 || echo 0)"
t "settings: min length field"           "$(has o_set.html 'name="wpd_registration\[password_min\]" value="8" min="6" max="64"')"
SN=$(nonce o_set.html _wpnonce)
a -o /dev/null --data-urlencode "option_page=wpd_registration_group" --data-urlencode "action=update" --data-urlencode "_wpnonce=$SN" \
  --data-urlencode "_wp_http_referer=/wp-admin/admin.php?page=wpd-registration" --data-urlencode "wpd_registration[_tab]=form" \
  --data-urlencode "wpd_registration[enabled]=1" --data-urlencode "wpd_registration[accounts]=1" --data-urlencode "wpd_registration[autologin]=1" \
  --data-urlencode "wpd_registration[password_min]=10" \
  --data-urlencode "wpd_registration[form_page]=$(js "$SETUP" "d['form_id']")" --data-urlencode "wpd_registration[cabinet_page]=$(js "$SETUP" "d['cabinet_id']")" \
  --data-urlencode "wpd_registration[rate_limit]=20" "$B/wp-admin/options.php"
O=$(H optget=1)
t "settings: unchecked password saved as 0" "$(js "$O" "1 if d['opt']['password']==0 and d['opt']['password_min']==10 else 0")"
curl -s --max-time 120 "$FORM" -o o_form5.html
t "password off: no password fields"     "$([ "$(hasnt o_form5.html 'name="wpd_pass"')$(hasnt o_form5.html 'data-wpd-showpass')" = "11" ] && echo 1 || echo 0)"

# ---------- Пароль выключен: ссылка WordPress ----------
H "mails=1&clear=1" > /dev/null; H "rl_clear=1" > /dev/null
g cjO5.txt "$FORM" -o o_form6.html; T6=$(tok o_form6.html); sleep 4
LOC=$(send cjO5.txt "$FORM" "${BASE[@]}" --data-urlencode "wpd_reg[email]=vera@n-test.ru" --data-urlencode "wpd_reg[name]=Вера Л." --data-urlencode "wpd_ts=$T6")
t "password off: registered without password" "$(echo "$LOC" | grep -q 'wpd_reg=done' && echo 1 || echo 0)"
MAILS=$(H "mails=1")
t "password off: set-password link mail" "$(js "$MAILS" "1 if len(d)==3 and any(m['to']=='vera@n-test.ru' and 'action=rp' in m['message'] for m in d) else 0")"
t "password off: login_info about link"  "$(js "$MAILS" "1 if any('Ссылку для установки пароля WordPress пришлёт отдельным письмом. Логин: vera.' in m['message'] for m in d) else 0")"

# ---------- Своя минимальная длина ----------
opt '{"password_min":10}'
H "rl_clear=1" > /dev/null
g cjO6.txt "$FORM" -o o_form7.html; T7=$(tok o_form7.html); sleep 4
t "min 10: hint and attribute"           "$([ "$(has o_form7.html 'Не короче 10 символов')$(has o_form7.html 'minlength="10"')" = "11" ] && echo 1 || echo 0)"
LOC=$(send cjO6.txt "$FORM" "${BASE[@]}" --data-urlencode "wpd_reg[email]=gleb@n-test.ru" --data-urlencode "wpd_ts=$T7" --data-urlencode "wpd_pass=Abcdef123" --data-urlencode "wpd_pass2=Abcdef123")
t "min 10: 9 characters refused"         "$([ -z "$LOC" ] && [ "$(has $OUTF 'не короче 10 символов')" = "1" ] && echo 1 || echo 0)"
LOC=$(send cjO6.txt "$FORM" "${BASE[@]}" --data-urlencode "wpd_reg[email]=gleb@n-test.ru" --data-urlencode "wpd_ts=$T7" --data-urlencode "wpd_pass=Abcdef1234" --data-urlencode "wpd_pass2=Abcdef1234")
t "min 10: 10 characters accepted"       "$(echo "$LOC" | grep -q 'wpd_reg=done' && echo 1 || echo 0)"

# ---------- Без аккаунтов ----------
opt '{"accounts":0}'
H "rl_clear=1" > /dev/null
g cjO7.txt "$FORM" -o o_form8.html; T8=$(tok o_form8.html); sleep 4
t "no accounts: no password fields"      "$(hasnt o_form8.html 'name="wpd_pass"')"
LOC=$(send cjO7.txt "$FORM" "${BASE[@]}" --data-urlencode "wpd_reg[email]=dina@n-test.ru" --data-urlencode "wpd_ts=$T8")
t "no accounts: registered, no user"     "$([ -n "$LOC" ] && [ "$(H 'user=dina@n-test.ru')" = "null" ] && echo 1 || echo 0)"

H cleanup=1 > /dev/null
t "cleanup: participants deleted"        "$([ "$(H 'user=anna2026@n-test.ru')" = "null" ] && [ "$(H apps=1)" = "[]" ] && echo 1 || echo 0)"
[ -z "$KEEP" ] && rm -f cjO*.txt o_*.html
echo
echo "TOTAL: $((PASS+FAIL)), FAILED: $FAIL"
