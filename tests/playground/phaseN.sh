#!/bin/bash
# Фаза N (2.0.0): HTTP-проверки регистрации участников — настоящие запросы гостя, участника и администратора.
# Форма: метка времени, ловушка, согласие, ошибки с сохранением значений, повторный e-mail; успешная заявка → аккаунт, вход, кабинет, письма.
# Участник: без консоли и панели, правка заявки, вход через wp-login. Администратор: список, счётчик, смена статуса, карточка, CSV,
# настройки (конструктор полей). Лимит мест, режим без аккаунтов, лимит с адреса, сроки. В конце удаляет всё созданное.
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

curl -s -o /dev/null "$B/wpd-n-helper.php" ; curl -s -o /dev/null "$B/wpd-n-helper.php"
SETUP=$(H "setup=1")
FORM=$(js "$SETUP" "d['form']"); CAB=$(js "$SETUP" "d['cabinet']")
t "setup: pages created" "$([ -n "$FORM" ] && [ -n "$CAB" ] && echo 1 || echo 0)"
rm -f cjG.txt cjP.txt cjA.txt cjG2.txt cjG3.txt
g() { local jar=$1; shift; curl -s -c "$jar" -b "$jar" --max-time 120 "$@"; }
anon() { curl -s --max-time 120 "$1" -o "$2"; }
# POST формы: печатает адрес перенаправления (пусто — страница показана снова), тело — в $OUTF
OUTF=n_post.html
send() { local jar=$1 url=$2; shift 2; curl -s -c "$jar" -b "$jar" --max-time 120 -o "$OUTF" -w "%{redirect_url}" "$@" "$url"; }
VALID=(--data-urlencode "wpd_reg_action=register" --data-urlencode "wpd_hp=" --data-urlencode "wpd_consent=1"
  --data-urlencode "wpd_reg[name]=Иванов Иван Иванович" --data-urlencode "wpd_reg[phone]=+7 900 123-45-67"
  --data-urlencode "wpd_reg[grade]=9" --data-urlencode "wpd_reg[school]=Лицей № 1" --data-urlencode "wpd_reg[city]=Красноярск"
  --data-urlencode "wpd_pass=Olymp2026pass" --data-urlencode "wpd_pass2=Olymp2026pass")

# ---------- Гость открывает форму ----------
g cjG.txt "$FORM" -o n_form.html
t "form: rendered on page"               "$(has n_form.html '<section class="wpd-reg" id="wpd-reg">')"
t "form: styles enqueued on form page"   "$(has n_form.html 'wpd/assets/css/wpd.css\|wp-olympiad-design/assets/css/wpd.css')"
t "form: 6 fields + consent"             "$([ "$(grep -o 'name="wpd_reg\[' n_form.html | wc -l)" = "6" ] && [ "$(has n_form.html 'name="wpd_consent"')" = "1" ] && echo 1 || echo 0)"
t "form: token and honeypot"             "$([ -n "$(tok n_form.html)" ] && [ "$(has n_form.html 'name="wpd_hp"')" = "1" ] && echo 1 || echo 0)"
T1=$(tok n_form.html)

# Слишком быстро — свежая метка
g cjG.txt "$FORM" -o n_form2.html
LOC=$(send cjG.txt "$FORM" "${VALID[@]}" --data-urlencode "wpd_reg[email]=ivan@n-test.ru" --data-urlencode "wpd_ts=$(tok n_form2.html)")
t "too fast: no redirect"                "$([ -z "$LOC" ] && echo 1 || echo 0)"
t "too fast: form error"                 "$(has $OUTF 'форма заполнена слишком быстро')"
t "too fast: values kept"                "$(has $OUTF 'value="Лицей № 1"')"
sleep 4
# Ловушка
LOC=$(send cjG.txt "$FORM" "${VALID[@]}" --data-urlencode "wpd_reg[email]=ivan@n-test.ru" --data-urlencode "wpd_ts=$T1" --data-urlencode "wpd_hp=http://spam")
t "honeypot: rejected"                   "$([ -z "$LOC" ] && [ "$(has $OUTF 'форма заполнена слишком быстро')" = "1" ] && echo 1 || echo 0)"
# Поддельная метка
LOC=$(send cjG.txt "$FORM" "${VALID[@]}" --data-urlencode "wpd_reg[email]=ivan@n-test.ru" --data-urlencode "wpd_ts=1000.abcdefabcdefabcd")
t "forged token: rejected"               "$([ -z "$LOC" ] && [ "$(has $OUTF 'форма заполнена слишком быстро')" = "1" ] && echo 1 || echo 0)"
# Без согласия и с ошибками
LOC=$(curl -s -c cjG.txt -b cjG.txt -o $OUTF -w "%{redirect_url}" --data-urlencode "wpd_reg_action=register" --data-urlencode "wpd_ts=$T1" \
  --data-urlencode "wpd_reg[name]=Петров Пётр" --data-urlencode "wpd_reg[email]=не почта" --data-urlencode "wpd_reg[grade]=42" "$FORM")
t "errors: no redirect"                  "$([ -z "$LOC" ] && echo 1 || echo 0)"
t "errors: summary alert"                "$(has $OUTF 'Проверьте форму: [0-9]* пол[а-я]* заполнен[а-я]* неверно.')"
t "errors: email message"                "$(has $OUTF 'Укажите корректный e-mail')"
t "errors: option not in list"           "$(has $OUTF 'Выберите вариант из списка')"
t "errors: required phone"               "$(has $OUTF 'id="wpd-reg-phone-err"')"
t "errors: consent message"              "$(has $OUTF 'Нужно согласие на обработку персональных данных')"
t "errors: aria-invalid"                 "$(has $OUTF 'aria-invalid="true"')"
t "errors: name kept"                    "$(has $OUTF 'value="Петров Пётр"')"
t "errors: invalid email kept to fix"    "$(has $OUTF 'value="не почта"')"
t "errors: no application created"       "$([ "$(H apps=1)" = "[]" ] && echo 1 || echo 0)"
# E-mail существующего пользователя (администратора)
ADMIN_MAIL=$(js "$(H optget=1)" "d['admin_email']")
LOC=$(send cjG.txt "$FORM" "${VALID[@]}" --data-urlencode "wpd_reg[email]=$ADMIN_MAIL" --data-urlencode "wpd_ts=$T1")
t "existing wp user email: asks to log in" "$([ -z "$LOC" ] && [ "$(has $OUTF 'уже есть пользователь с этим e-mail')" = "1" ] && echo 1 || echo 0)"

# ---------- Успешная заявка ----------
H "mails=1&clear=1" > /dev/null
LOC=$(send cjG.txt "$FORM" "${VALID[@]}" --data-urlencode "wpd_reg[email]=Ivan@N-Test.ru" --data-urlencode "wpd_ts=$T1")
t "valid: redirect to cabinet with token" "$(echo "$LOC" | grep -q "wpd_reg=done&t=[A-Za-z0-9]\{20\}#wpd-cabinet" && echo "$LOC" | grep -qF "${CAB%%\?*}" && echo 1 || echo 0)"
APPS=$(H apps=1)
t "valid: one application OL-0001"       "$(js "$APPS" "1 if len(d)==1 and d[0]['number']=='OL-0001' and d[0]['status']=='new' else 0")"
t "valid: email lowercased"              "$(js "$APPS" "1 if d[0]['email']=='ivan@n-test.ru' else 0")"
t "valid: values stored"                 "$(js "$APPS" "1 if d[0]['values']['school']=='Лицей № 1' and d[0]['values']['grade']=='9' and d[0]['title']=='Иванов Иван Иванович' else 0")"
U=$(H "user=ivan@n-test.ru")
t "valid: account with role participant" "$(js "$U" "1 if d and d['roles']==['wpd_participant'] and d['login']=='ivan' and d['name']=='Иванов Иван Иванович' else 0")"
t "valid: application linked to user"    "$([ "$(js "$APPS" "d[0]['user']")" = "$(js "$U" "d['id']")" ] && echo 1 || echo 0)"
t "valid: logged in (auth cookie)"       "$(grep -q 'wordpress_logged_in' cjG.txt && echo 1 || echo 0)"
MAILS=$(H "mails=1")
t "mails: 2 sent (own password — no set-password mail, 2.1.0)" "$(js "$MAILS" "1 if len(d)==2 else len(d)")"
t "mails: participant confirmation"      "$(js "$MAILS" "1 if any(m['to']=='ivan@n-test.ru' and m['subject']=='Заявка OL-0001 принята' and 'Лицей № 1' in m['message'] for m in d) else 0")"
t "mails: organizer notice"              "$(js "$MAILS" "1 if any(m['to']=='$ADMIN_MAIL' and m['subject'].startswith('Новая заявка OL-0001') for m in d) else 0")"
t "mails: no WordPress set-password mail" "$(js "$MAILS" "0 if any('action=rp' in m['message'] for m in d) else 1")"
URL=$(echo "$LOC" | sed 's/#.*//')
g cjG.txt "$URL" -o n_cab.html
t "cabinet: success notice"              "$(has n_cab.html 'Заявка OL-0001 принята')"
t "cabinet: number and status"           "$([ "$(has n_cab.html 'class="wpd-cabinet__number">OL-0001')$(has n_cab.html 'wpd-status--new">Новая')" = "11" ] && echo 1 || echo 0)"
t "cabinet: fields listed"               "$(has n_cab.html '<dt>Класс</dt><dd>9</dd>')"
t "cabinet: edit link"                   "$(has n_cab.html 'wpd_edit=1')"
t "participant: no admin bar"            "$(hasnt n_cab.html 'id="wpadminbar"')"
g cjG.txt "$FORM" -o n_form3.html
t "form: already submitted for participant" "$([ "$(has n_form3.html 'Вы уже подали заявку OL-0001')" = "1" ] && [ "$(hasnt n_form3.html '<form class="wpd-reg__form"')" = "1" ] && echo 1 || echo 0)"
LOC=$(curl -s -b cjG.txt -o /dev/null -w "%{redirect_url}" --max-time 60 "$B/wp-admin/")
t "participant: /wp-admin -> cabinet"    "$(echo "$LOC" | grep -qF "${CAB%%\?*}" && echo 1 || echo 0)"
LOC=$(curl -s -b cjG.txt -o /dev/null -w "%{redirect_url}" --max-time 60 "$B/wp-admin/profile.php")
t "participant: profile -> cabinet"      "$(echo "$LOC" | grep -qF "${CAB%%\?*}" && echo 1 || echo 0)"
CODE=$(curl -s -b cjG.txt -o /dev/null -w "%{http_code}" --max-time 60 -d "action=heartbeat" "$B/wp-admin/admin-ajax.php")
t "participant: admin-ajax not redirected" "$([ "$CODE" != "302" ] && echo 1 || echo 0)"

# ---------- Правка заявки участником ----------
EDIT_URL="$CAB$( [[ "$CAB" == *\?* ]] && echo '&' || echo '?')wpd_edit=1"
g cjG.txt "$EDIT_URL" -o n_edit.html
t "edit: form with current values"       "$([ "$(has n_edit.html 'value="Лицей № 1"')$(has n_edit.html 'name="wpd_reg_action" value="edit"')" = "11" ] && echo 1 || echo 0)"
t "edit: email readonly"                 "$(grep -oE '<input[^>]*id="wpd-reg-email"[^>]*>' n_edit.html | grep -q readonly && echo 1 || echo 0)"
EN=$(nonce n_edit.html _wpd_nonce)
EDIT=(--data-urlencode "wpd_reg_action=edit" --data-urlencode "_wpd_nonce=$EN" --data-urlencode "wpd_reg[name]=Иванов Иван" \
  --data-urlencode "wpd_reg[email]=evil@n-test.ru" --data-urlencode "wpd_reg[phone]=+7 900 123-45-67" --data-urlencode "wpd_reg[grade]=10" \
  --data-urlencode "wpd_reg[city]=Красноярск")
LOC=$(send cjG.txt "$EDIT_URL" "${EDIT[@]}" --data-urlencode "wpd_reg[school]=")
t "edit: required error, no redirect"    "$([ -z "$LOC" ] && [ "$(has $OUTF 'id="wpd-reg-school-err"')" = "1" ] && echo 1 || echo 0)"
t "edit: still edit form after error"    "$(has $OUTF 'name="wpd_reg_action" value="edit"')"
LOC=$(send cjG.txt "$EDIT_URL" "${EDIT[@]}" --data-urlencode "wpd_reg[school]=Гимназия 3" --data-urlencode "_wpd_nonce=bad")
t "edit: bad nonce rejected"             "$([ -z "$LOC" ] && [ "$(has $OUTF 'Сессия устарела')" = "1" ] && echo 1 || echo 0)"
LOC=$(send cjG.txt "$EDIT_URL" "${EDIT[@]}" --data-urlencode "wpd_reg[school]=Гимназия 3")
t "edit: saved -> redirect"              "$(echo "$LOC" | grep -q 'wpd_reg=saved#wpd-cabinet' && echo "$LOC" | grep -qv 'wpd_edit' && echo 1 || echo 0)"
APPS=$(H apps=1)
t "edit: values updated, email kept"     "$(js "$APPS" "1 if d[0]['values']['school']=='Гимназия 3' and d[0]['values']['grade']=='10' and d[0]['email']=='ivan@n-test.ru' and d[0]['title']=='Иванов Иван' else 0")"
t "edit: display name updated"           "$(js "$(H 'user=ivan@n-test.ru')" "1 if d['name']=='Иванов Иван' else 0")"
g cjG.txt "$(echo "$LOC" | sed 's/#.*//')" -o n_cab2.html
t "edit: saved notice and new value"     "$([ "$(has n_cab2.html 'Изменения сохранены')$(has n_cab2.html '<dd>Гимназия 3</dd>')" = "11" ] && echo 1 || echo 0)"

# ---------- Повторный e-mail ----------
g cjG2.txt "$FORM" -o n_g2.html; T2=$(tok n_g2.html); sleep 4
LOC=$(send cjG2.txt "$FORM" "${VALID[@]}" --data-urlencode "wpd_reg[email]=ivan@n-test.ru" --data-urlencode "wpd_ts=$T2")
t "duplicate email: rejected"            "$([ -z "$LOC" ] && [ "$(has $OUTF 'Заявка с этим e-mail уже подана')" = "1" ] && echo 1 || echo 0)"

# ---------- Вход участника ----------
H "setpass=ivan@n-test.ru" > /dev/null
curl -s -c cjP.txt -b cjP.txt -o /dev/null "$B/wp-login.php"
LOC=$(curl -s -c cjP.txt -b cjP.txt -o /dev/null -w "%{redirect_url}" --max-time 60 -d "log=ivan&pwd=Passw0rd-N&wp-submit=Log+In&redirect_to=$(q "$B/wp-admin/")&testcookie=1" "$B/wp-login.php")
t "participant login -> cabinet"         "$(echo "$LOC" | grep -qF "${CAB%%\?*}" && echo 1 || echo 0)"
g cjG3.txt "$CAB" -o n_cab_guest.html
curl -s -c cjG3.txt -b cjG3.txt -o /dev/null "$B/wp-login.php"
t "cabinet guest: login form"            "$([ "$(has n_cab_guest.html 'id="wpd-login"')$(has n_cab_guest.html 'E-mail или логин')" = "11" ] && echo 1 || echo 0)"
LOC=$(curl -s -c cjG3.txt -b cjG3.txt -o /dev/null -w "%{redirect_url}" --max-time 60 -d "log=ivan%40n-test.ru&pwd=Passw0rd-N&wp-submit=Log+In&redirect_to=$(q "$CAB")&testcookie=1" "$B/wp-login.php")
t "cabinet login form: back to cabinet"  "$([ "$LOC" = "$CAB" ] && echo 1 || echo 0)"

# ---------- Администратор ----------
curl -s -c cjA.txt -b cjA.txt -o /dev/null --max-time 60 -d "log=admin&pwd=password&wp-submit=Log+In&redirect_to=/wp-admin/&testcookie=1" "$B/wp-login.php"
a() { curl -s -c cjA.txt -b cjA.txt --max-time 120 "$@"; }
a "$B/wp-admin/edit.php?post_type=wpd_application" -o n_list.html
t "admin list: application row"          "$([ "$(has n_list.html '<strong>OL-0001</strong>')$(has n_list.html 'mailto:ivan@n-test.ru')" = "11" ] && echo 1 || echo 0)"
t "admin list: columns"                  "$([ "$(has n_list.html "id='wpd_number'")$(has n_list.html "id='wpd_status'")$(has n_list.html "id='wpd_account'")$(has n_list.html "id='wpd_f_grade'")" = "1111" ] && echo 1 || echo 0)"
t "admin list: status views"             "$([ "$(has n_list.html 'wpd_status=new')$(has n_list.html 'wpd_status=approved')$(has n_list.html 'wpd_status=rejected')" = "111" ] && echo 1 || echo 0)"
t "admin list: menu badge 1"             "$(has n_list.html 'Участники <span class="awaiting-mod count-1"><span class="pending-count">1</span>')"
t "admin list: registration submenu"     "$(has n_list.html 'admin.php?page=wpd-registration')"
t "admin list: no «Add new»"             "$(hasnt n_list.html 'post-new.php?post_type=wpd_application')"
t "admin list: export button"            "$(has n_list.html 'admin-post.php?action=wpd_reg_export')"
t "admin list: bulk actions"             "$([ "$(has n_list.html '<option value="wpd_approved">')$(has n_list.html '<option value="wpd_rejected">')" = "11" ] && echo 1 || echo 0)"
a "$B/wp-admin/edit.php?post_type=wpd_application&s=$(q 'Гимназия 3')" -o n_search.html
t "admin search by school"               "$(has n_search.html '<strong>OL-0001</strong>')"
a "$B/wp-admin/edit.php?post_type=wpd_application&s=nomatchxyz" -o n_search2.html
t "admin search no match"                "$(hasnt n_search2.html '<strong>OL-0001</strong>')"
CODE=$(a -o /dev/null -w "%{http_code}" "$B/wp-admin/post-new.php?post_type=wpd_application")
t "admin: cannot create application manually" "$([ "$CODE" = "403" ] && echo 1 || echo 0)"

# Быстрое подтверждение
APPROVE=$(grep -o 'href="[^"]*action=wpd_reg_status[^"]*status=approved[^"]*"' n_list.html | head -1 | sed 's/href="//;s/"$//' | amp)
H "mails=1&clear=1" > /dev/null
LOC=$(a -o /dev/null -w "%{redirect_url}" -e "$B/wp-admin/edit.php?post_type=wpd_application" "$APPROVE")
t "quick approve: redirect with notice"  "$(echo "$LOC" | grep -q 'wpd_changed=1' && echo 1 || echo 0)"
t "quick approve: status approved"       "$(js "$(H apps=1)" "1 if d[0]['status']=='approved' else 0")"
t "quick approve: status mail"           "$(js "$(H mails=1)" "1 if len(d)==1 and d[0]['subject']=='Заявка OL-0001: Подтверждена' and d[0]['to']=='ivan@n-test.ru' else 0")"
a "$LOC" -o n_list2.html
t "notice text"                          "$(has n_list2.html 'Статус изменён: 1 заявка.')"
t "badge gone with no new"               "$(hasnt n_list2.html 'Участники <span class="awaiting-mod')"
CODE=$(a -o /dev/null -w "%{http_code}" "$(echo "$APPROVE" | sed 's/_wpnonce=[^&]*/_wpnonce=bad/')")
t "quick status: bad nonce rejected"     "$([ "$CODE" = "403" ] && echo 1 || echo 0)"
CODE=$(curl -s -b cjP.txt -o /dev/null -w "%{http_code}" "$APPROVE")
t "quick status: participant can't"      "$([ "$CODE" != "302" ] || [ "$(js "$(H apps=1)" "d[0]['status']")" = "approved" ] && echo 1 || echo 0)"
g cjP.txt "$CAB" -o n_cab3.html
t "cabinet: approved, no edit link"      "$([ "$(has n_cab3.html 'wpd-status--approved">Подтверждена')$(hasnt n_cab3.html 'wpd_edit=1')" = "11" ] && echo 1 || echo 0)"
g cjP.txt "$EDIT_URL" -o n_edit2.html
t "cabinet: edit url ignored when approved" "$(hasnt n_edit2.html 'name="wpd_reg_action" value="edit"')"

# Вид по статусу
a "$B/wp-admin/edit.php?post_type=wpd_application&wpd_status=approved" -o n_v1.html
a "$B/wp-admin/edit.php?post_type=wpd_application&wpd_status=new" -o n_v2.html
t "view approved lists it, new doesn't"  "$([ "$(has n_v1.html '<strong>OL-0001</strong>')$(hasnt n_v2.html '<strong>OL-0001</strong>')" = "11" ] && echo 1 || echo 0)"
t "view: export button with status"      "$(has n_v1.html 'Экспорт в CSV (Подтверждена)')"

# CSV
EXPORT=$(grep -o 'href="[^"]*action=wpd_reg_export[^"]*"' n_list.html | head -1 | sed 's/href="//;s/"$//' | amp)
a -D n_csv_h.txt -o n_csv.csv "$EXPORT"
t "csv: content type and filename"       "$(grep -qi 'content-type: text/csv' n_csv_h.txt && grep -qi 'filename="participants-' n_csv_h.txt && echo 1 || echo 0)"
t "csv: BOM"                             "$([ "$(head -c 3 n_csv.csv | od -An -tx1 | tr -d ' \n')" = "efbbbf" ] && echo 1 || echo 0)"
t "csv: header with ;"                   "$(sed -n 1p n_csv.csv | grep -q '№;Подана;Статус;"ФИО участника";E-mail;Телефон;Класс;Школа;"Регион, город";Логин' && echo 1 || echo 0)"
t "csv: row with guarded phone"          "$(sed -n 2p n_csv.csv | grep -q "^OL-0001;.*;Подтверждена;\"Иванов Иван\";ivan@n-test.ru;\"'+7 900 123-45-67\";10;\"Гимназия 3\";Красноярск;ivan" && echo 1 || echo 0)"
CODE=$(a -o /dev/null -w "%{http_code}" "$B/wp-admin/admin-post.php?action=wpd_reg_export")
t "csv: no nonce -> rejected"            "$([ "$CODE" = "403" ] && echo 1 || echo 0)"
CODE=$(curl -s -b cjP.txt -o n_csv_p.txt -w "%{http_code}" "$EXPORT")
t "csv: participant gets nothing"        "$(grep -q 'OL-0001' n_csv_p.txt && echo 0 || echo 1)"

# Карточка заявки
ID=$(js "$(H apps=1)" "d[0]['id']")
a "$B/wp-admin/post.php?post=$ID&action=edit" -o n_card.html
t "card: data and status boxes"          "$([ "$(has n_card.html 'id="wpd_app_data"')$(has n_card.html 'id="wpd_app_status"')" = "11" ] && echo 1 || echo 0)"
t "card: field values"                   "$(has n_card.html 'value="Гимназия 3"')"
t "card: classic edit screen"            "$([ "$(has n_card.html 'id="post-body"')$(hasnt n_card.html 'block-editor-page')" = "11" ] && echo 1 || echo 0)"
WN=$(nonce n_card.html _wpnonce); AN=$(nonce n_card.html wpd_app_nonce)
H "mails=1&clear=1" > /dev/null
LOC=$(a -o /dev/null -w "%{redirect_url}" --data-urlencode "_wpnonce=$WN" --data-urlencode "wpd_app_nonce=$AN" --data-urlencode "action=editpost" \
  --data-urlencode "originalaction=editpost" --data-urlencode "post_ID=$ID" --data-urlencode "post_type=wpd_application" --data-urlencode "post_author=1" \
  --data-urlencode "post_title=Иванов Иван Петрович" --data-urlencode "wpd_reg[email]=ivan.new@n-test.ru" --data-urlencode "wpd_reg[phone]=+7 900 123-45-67" \
  --data-urlencode "wpd_reg[grade]=11" --data-urlencode "wpd_reg[school]=Гимназия 3" --data-urlencode "wpd_reg[city]=" \
  --data-urlencode "wpd_app_status=rejected" --data-urlencode "post_status=publish" --data-urlencode "save=Обновить" "$B/wp-admin/post.php")
APPS=$(H apps=1)
t "card save: redirect"                  "$(echo "$LOC" | grep -q "post=$ID" && echo 1 || echo 0)"
t "card save: values, title, email"      "$(js "$APPS" "1 if d[0]['values']['grade']=='11' and d[0]['values']['city']=='' and d[0]['title']=='Иванов Иван Петрович' and d[0]['email']=='ivan.new@n-test.ru' else 0")"
t "card save: status without mail"       "$(js "$APPS" "1 if d[0]['status']=='rejected' else 0")$(js "$(H mails=1)" "'' if len(d)==0 else 'X'")"
a "$(echo "$LOC" | amp)" -o n_card2.html
t "card: custom updated message"         "$(has n_card2.html 'Заявка сохранена.')"

# ---------- Настройки: конструктор полей ----------
a "$B/wp-admin/admin.php?page=wpd-registration&tab=fields" -o n_set.html
t "settings: builder with 4 rows"        "$([ "$(grep -o 'name="wpd_registration\[fields\]\[[0-9]*\]\[label\]"' n_set.html | wc -l)" = "4" ] && echo 1 || echo 0)"
t "settings: system fields note"         "$(has n_set.html 'class="wpd-reg-system"')"
SN=$(nonce n_set.html _wpnonce)
LOC=$(a -o /dev/null -w "%{redirect_url}" --data-urlencode "option_page=wpd_registration_group" --data-urlencode "action=update" --data-urlencode "_wpnonce=$SN" \
  --data-urlencode "_wp_http_referer=/wp-admin/admin.php?page=wpd-registration&tab=fields" --data-urlencode "wpd_registration[_tab]=fields" \
  --data-urlencode "wpd_registration[fields][_]=" \
  --data-urlencode "wpd_registration[fields][0][id]=grade" --data-urlencode "wpd_registration[fields][0][label]=Класс" --data-urlencode "wpd_registration[fields][0][type]=select" \
  --data-urlencode "wpd_registration[fields][0][options]=9
10
11" --data-urlencode "wpd_registration[fields][0][required]=1" --data-urlencode "wpd_registration[fields][0][in_list]=1" \
  --data-urlencode "wpd_registration[fields][1][id]=" --data-urlencode "wpd_registration[fields][1][label]=Предмет" --data-urlencode "wpd_registration[fields][1][type]=radio" \
  --data-urlencode "wpd_registration[fields][1][options]=Математика
Информатика" --data-urlencode "wpd_registration[fields][1][required]=1" \
  --data-urlencode "wpd_registration[fields][2][id]=" --data-urlencode "wpd_registration[fields][2][label]=Нужно общежитие" --data-urlencode "wpd_registration[fields][2][type]=checkbox" \
  --data-urlencode "wpd_registration[fields][__i__][label]=Шаблон" "$B/wp-admin/options.php")
t "settings save: redirect back"         "$(echo "$LOC" | grep -q 'page=wpd-registration' && echo "$LOC" | grep -q 'tab=fields' && echo 1 || echo 0)"
a "$B/wp-admin/admin.php?page=wpd-registration&tab=fields&settings-updated=true" -o n_set2.html
t "settings save: 3 rows in new order"   "$(python3 -c "import re;s=open('n_set2.html',encoding='utf-8').read();l=re.findall(r'name=\"wpd_registration\[fields\]\[\d+\]\[label\]\" value=\"([^\"]*)\"',s);print(1 if l==['Класс','Предмет','Нужно общежитие'] else l)")"
t "settings save: notice"                "$(grep -q "id='setting-error-settings_updated'" n_set2.html && echo 1 || echo 0)"
t "settings save: other tabs kept"       "$(js "$(H optget=1)" "1 if d['opt']['enabled']==1 and d['opt']['form_page']>0 and d['opt']['number_prefix']=='OL-' else d['opt']")"
anon "$FORM" n_form4.html
t "form: new fields order"               "$(python3 -c "import re;s=open('n_form4.html',encoding='utf-8').read();l=re.findall(r'name=\"wpd_reg\[([a-z0-9_]+)\]',s);print(1 if l[:3]==['name','email','grade'] and len(l)>=5 and l[3].startswith('f_') else l)")"
t "form: radio and checkbox"             "$([ "$(has n_form4.html 'value="Информатика"')$(has n_form4.html '>Нужно общежитие</span>')" = "11" ] && echo 1 || echo 0)"
t "form: removed field gone"             "$(hasnt n_form4.html 'wpd_reg[school]')"
a "$B/wp-admin/admin.php?page=wpd-registration" -o n_set3.html
SN=$(nonce n_set3.html _wpnonce)
a -o /dev/null --data-urlencode "option_page=wpd_registration_group" --data-urlencode "action=update" --data-urlencode "_wpnonce=$SN" \
  --data-urlencode "_wp_http_referer=/wp-admin/admin.php?page=wpd-registration" --data-urlencode "wpd_registration[_tab]=form" \
  --data-urlencode "wpd_registration[enabled]=1" --data-urlencode "wpd_registration[period]=dates" --data-urlencode "wpd_registration[start]=2030-03-01T09:00" \
  --data-urlencode "wpd_registration[end]=2030-04-01T09:00" --data-urlencode "wpd_registration[title]=Заявка на олимпиаду" \
  --data-urlencode "wpd_registration[form_page]=$(js "$SETUP" "d['form_id']")" --data-urlencode "wpd_registration[cabinet_page]=$(js "$SETUP" "d['cabinet_id']")" \
  --data-urlencode "wpd_registration[number_prefix]=T-" --data-urlencode "wpd_registration[rate_limit]=20" "$B/wp-admin/options.php"
a "$B/wp-admin/admin.php?page=wpd-registration&settings-updated=true" -o n_set4.html
t "settings form tab: dates saved"       "$(has n_set4.html 'value="2030-03-01T09:00"')"
t "settings form tab: state closed"      "$(has n_set4.html 'Сейчас приём заявок закрыт: ещё не начался.')"
a "$B/wp-admin/admin.php?page=wpd-registration&tab=fields" -o n_set5.html
t "settings form tab: builder untouched" "$(grep -q 'value="Нужно общежитие"' n_set5.html && echo 1 || echo 0)"
anon "$FORM" n_form5.html
t "form: not started message (MSK)"      "$(has n_form5.html 'Приём заявок начнётся 1 марта 2030, 09:00 МСК.')"
t "form: saved title"                    "$(has n_form5.html '>Заявка на олимпиаду</h2>')"

# ---------- Сроки ----------
PAST1=$(python3 -c "import datetime;print((datetime.datetime.now(datetime.timezone.utc)+datetime.timedelta(hours=3,days=-3)).strftime('%Y-%m-%d %H:%M'))")
PAST2=$(python3 -c "import datetime;print((datetime.datetime.now(datetime.timezone.utc)+datetime.timedelta(hours=3,minutes=-5)).strftime('%Y-%m-%d %H:%M'))")
opt "{\"period\":\"dates\",\"start\":\"$PAST1\",\"end\":\"$PAST2\"}"
anon "$FORM" n_form6.html
t "dates: ended -> closed"               "$([ "$(has n_form6.html 'Приём заявок закрыт.')$(hasnt n_form6.html '<form class="wpd-reg__form"')" = "11" ] && echo 1 || echo 0)"
LOC=$(send cjG2.txt "$FORM" "${VALID[@]}" --data-urlencode "wpd_reg[email]=late@n-test.ru" --data-urlencode "wpd_ts=$T2")
t "dates: POST after end refused"        "$([ -z "$LOC" ] && [ "$(js "$(H apps=1)" "len(d)")" = "1" ] && echo 1 || echo 0)"

# ---------- Лимит мест ----------
opt '{"limit":2}'
g cjG2.txt "$FORM" -o n_g2.html; T2=$(tok n_g2.html)
t "limit: seats left shown"              "$(has n_g2.html 'Осталось мест: <strong>2</strong>')"
a -o /dev/null "$(echo "$APPROVE" | sed 's/status=approved/status=new/')" -e "$B/wp-admin/edit.php?post_type=wpd_application"
g cjG2.txt "$FORM" -o n_g2.html
t "limit: rejected app doesn't take a seat, new does" "$(has n_g2.html 'Осталось мест: <strong>1</strong>')"
sleep 4
LOC=$(send cjG2.txt "$FORM" "${VALID[@]}" --data-urlencode "wpd_reg[email]=second@n-test.ru" --data-urlencode "wpd_ts=$T2")
t "limit: second application accepted"   "$(echo "$LOC" | grep -q 'wpd_reg=done' && echo 1 || echo 0)"
t "limit: number OL-0002"                "$(js "$(H apps=1)" "1 if d[-1]['number']=='OL-0002' and d[-1]['user']>0 else 0")"
t "limit: login by email-based unique login" "$(js "$(H 'user=second@n-test.ru')" "1 if d['login']=='second' else d")"
anon "$FORM" n_form7.html
t "limit: full message"                  "$([ "$(has n_form7.html 'Все места заняты')$(hasnt n_form7.html '<form class="wpd-reg__form"')" = "11" ] && echo 1 || echo 0)"
a -o /dev/null "$(echo "$APPROVE" | sed 's/status=approved/status=rejected/')" -e "$B/wp-admin/edit.php?post_type=wpd_application"
anon "$FORM" n_form8.html
t "limit: reopened after reject"         "$(has n_form8.html '<form class="wpd-reg__form"')"

# ---------- Без аккаунтов и лимит с адреса ----------
opt '{"accounts":0,"rate_limit":1,"mail_admin":0}'
H rl_clear=1 > /dev/null
H "mails=1&clear=1" > /dev/null
rm -f cjG4.txt
g cjG4.txt "$FORM" -o n_g4.html; T4=$(tok n_g4.html)
t "no accounts: no login link"           "$(hasnt n_g4.html 'class="wpd-reg__login"')"
sleep 4
LOC=$(send cjG4.txt "$FORM" "${VALID[@]}" --data-urlencode "wpd_reg[email]=$ADMIN_MAIL" --data-urlencode "wpd_ts=$T4")
t "no accounts: redirect to form page"   "$(echo "$LOC" | grep -q 'wpd_reg=done&t=.*#wpd-reg' && echo "$LOC" | grep -qF "${FORM%%\?*}" && echo 1 || echo 0)"
t "no accounts: not logged in"           "$(grep -q 'wordpress_logged_in' cjG4.txt && echo 0 || echo 1)"
t "no accounts: app without user"        "$(js "$(H apps=1)" "1 if d[-1]['user']==0 and d[-1]['email']=='$ADMIN_MAIL' else 0")"
t "no accounts: only participant mail"   "$(js "$(H mails=1)" "1 if len(d)==1 and d[0]['to']=='$ADMIN_MAIL' else [m['subject'] for m in d]")"
g cjG4.txt "$(echo "$LOC" | sed 's/#.*//')" -o n_g4b.html
t "no accounts: success on form page"    "$([ "$(has n_g4b.html 'wpd-reg__alert--success')$(has n_g4b.html 'OL-0003')" = "11" ] && echo 1 || echo 0)"
g cjG4.txt "$FORM" -o n_g4c.html; T4=$(tok n_g4c.html); sleep 4
LOC=$(send cjG4.txt "$FORM" "${VALID[@]}" --data-urlencode "wpd_reg[email]=third@n-test.ru" --data-urlencode "wpd_ts=$T4")
t "rate limit: second from same address blocked" "$([ -z "$LOC" ] && [ "$(has $OUTF 'Слишком много заявок с вашего адреса')" = "1" ] && echo 1 || echo 0)"
t "rate limit: values kept"              "$(has $OUTF 'value="Иванов Иван Иванович"')"
H rl_clear=1 > /dev/null
LOC=$(send cjG4.txt "$FORM" "${VALID[@]}" --data-urlencode "wpd_reg[email]=third@n-test.ru" --data-urlencode "wpd_ts=$T4")
t "rate limit: allowed after reset"      "$(echo "$LOC" | grep -q 'wpd_reg=done' && echo 1 || echo 0)"

# ---------- Закрыто вручную ----------
opt '{"enabled":0,"closed":"Регистрация завершена, ждём на олимпиаде!"}'
anon "$FORM" n_form9.html
t "disabled: custom closed text"         "$(has n_form9.html 'Регистрация завершена, ждём на олимпиаде!')"
g cjP.txt "$CAB" -o n_cab4.html
t "disabled: cabinet still works"        "$(has n_cab4.html 'class="wpd-cabinet__number">OL-0001')"

# ---------- Персональные данные (инструменты WordPress) ----------
a "$B/wp-admin/export-personal-data.php" -o n_priv.html
t "privacy tools page available"         "$(grep -q 'export-personal-data\|Export Personal Data\|Экспорт персональных данных' n_priv.html && echo 1 || echo 0)"

H cleanup=1 > /dev/null
t "cleanup: no applications left"        "$([ "$(H apps=1)" = "[]" ] && echo 1 || echo 0)"
t "cleanup: participants deleted"        "$([ "$(H 'user=ivan@n-test.ru')" = "null" ] && echo 1 || echo 0)"
[ -z "$KEEP" ] && rm -f cjG*.txt cjP.txt cjA.txt n_*.html n_*.txt n_*.csv
echo
echo "TOTAL: $((PASS+FAIL)), FAILED: $FAIL"
