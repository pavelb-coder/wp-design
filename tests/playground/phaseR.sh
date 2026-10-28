#!/bin/bash
# Фаза R (2.4.0): HTTP-проверки своего оформления страницы — экран редактирования (боковая колонка, панель, инструменты),
# настоящее сохранение мета-бокса так, как это делает блочный редактор (meta-box-loader), страница сайта (стиль, палитра,
# вид блоков, свой CSS, контакты), REST с cookie и X-WP-Nonce (экспорт, предпросмотр, копирование, импорт, сброс, права).
# Помощник — wpd-r-page.php. В конце удаляет тестовые страницы.
B=${WPD_BASE:-http://127.0.0.1:9400}
PASS=0; FAIL=0
t() { if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
has() { grep -q -- "$2" "$1" && echo 1 || echo 0; }
hasnt() { grep -q -- "$2" "$1" && echo 0 || echo 1; }
all1() { for v in "$@"; do [ "$v" = "1" ] || { echo 0; return; }; done; echo 1; }
val() { python3 - "$1" "$2" <<'PY'
import re, sys
s = open(sys.argv[1], encoding='utf-8').read()
m = re.search(r'name="' + re.escape(sys.argv[2]) + r'" value="([^"]*)"', s)
print(m.group(1) if m else '')
PY
}
js() { python3 -c "import json,sys;d=json.load(open(sys.argv[1]));exec('print('+sys.argv[2]+')')" "$1" "$2" 2>/dev/null; }

rm -f cjR.txt
curl -s -c cjR.txt -b cjR.txt -o /dev/null --max-time 60 "$B/wp-login.php"
curl -s -c cjR.txt -b cjR.txt -o /dev/null --max-time 60 -d "log=admin&pwd=password&wp-submit=Log+In&redirect_to=/wp-admin/&testcookie=1" "$B/wp-login.php"
get() { curl -s -c cjR.txt -b cjR.txt --max-time 180 "$B$1" -o "$2"; }

curl -sL --max-time 120 "$B/wpd-r-page.php?reset=1" > r_info.json
ID=$(js r_info.json 'd["id"]'); SRC=$(js r_info.json 'd["src"]')
t "helper: pages created" "$( [ -n "$ID" ] && [ -n "$SRC" ] && echo 1 || echo 0)"

# Экран редактирования
get "/wp-admin/post.php?post=$ID&action=edit" r_edit.html
t "edit: sidebar script loaded" "$(has r_edit.html 'wp-olympiad-design/assets/js/wpd-sidebar.js')"
t "edit: admin script loaded" "$(has r_edit.html 'wp-olympiad-design/assets/js/wpd-admin.js')"
t "edit: thirteen override groups" "$( [ "$(grep -o 'data-wpd-ovg="' r_edit.html | wc -l)" = "13" ] && echo 1 || echo 0)"
t "edit: block style toggles (13)" "$( [ "$(grep -o 'data-wpd-bs-toggle' r_edit.html | wc -l)" = "13" ] && echo 1 || echo 0)"
t "edit: own CSS and tools" "$(all1 "$(has r_edit.html 'id="wpd_pe_page_css"')" "$(has r_edit.html 'data-wpd-tools')")"
t "edit: copy source offered" "$(has r_edit.html "<option value=\"$SRC\">")"
t "edit: page palette with id base" "$(has r_edit.html 'data-id-base="wpd_pe_ov_"')"

WPN=$(val r_edit.html _wpnonce); PN=$(val r_edit.html wpd_page_nonce); DN=$(val r_edit.html wpd_nonce)
RN=$(grep -o 'createNonceMiddleware( "[^"]*"' r_edit.html | head -1 | sed 's/.*( "//;s/"$//')
MBURL=$(grep -o 'var _wpMetaBoxUrl = "[^"]*"' r_edit.html | sed 's/.*= "//;s/"$//')
t "edit: nonces found" "$( [ -n "$WPN" ] && [ -n "$PN" ] && [ -n "$DN" ] && [ -n "$RN" ] && [ -n "$MBURL" ] && echo 1 || echo 0)"

# Сохранение мета-боксов так, как это делает блочный редактор
ORDER=(hero countdown content stats docs faq slider buttons notice cards news contacts)
ARGS=()
for k in "${ORDER[@]}"; do ARGS+=(--data-urlencode "wpd_page[order][]=$k"); done
for k in hero content stats faq contacts; do ARGS+=(--data-urlencode "wpd_page[show][$k]=1"); done
CODE=$(curl -s -c cjR.txt -b cjR.txt -o r_save.html --max-time 180 -w "%{http_code}" \
  --data-urlencode "_wpnonce=$WPN" --data-urlencode "_wp_http_referer=/wp-admin/post.php?post=$ID&action=edit" \
  --data-urlencode "user_ID=1" --data-urlencode "action=editpost" --data-urlencode "originalaction=editpost" \
  --data-urlencode "post_type=page" --data-urlencode "original_post_status=publish" --data-urlencode "post_ID=$ID" \
  --data-urlencode "wpd_nonce=$DN" --data-urlencode "wpd_enabled=1" --data-urlencode "wpd_page_nonce=$PN" \
  "${ARGS[@]}" \
  --data-urlencode "wpd_page[hero][title]=Заголовок фазы R" \
  --data-urlencode "wpd_page[ovg][page]=1" --data-urlencode "wpd_page[ovg][contacts]=1" \
  --data-urlencode "wpd_page[ov][design_style]=soft" --data-urlencode "wpd_page[ov][palette]=custom" \
  --data-urlencode "wpd_page[ov][radius]=24" --data-urlencode "wpd_page[ov][heading_underline]=1" \
  --data-urlencode "wpd_page[ov][contact_phone]=+7 391 000-11-22" --data-urlencode "wpd_page[ov][contact_email]=r@example.com" \
  --data-urlencode "wpd_page[bstyle][stats][bg]=#112233" --data-urlencode "wpd_page[bstyle][stats][mt]=48" \
  --data-urlencode "wpd_page[bstyle][stats][width]=narrow" --data-urlencode "wpd_page[bstyle][contacts][hide]=mobile" \
  --data-urlencode "wpd_page[page_css]=.wpd-page .wpd-stats { outline: 2px dashed #aa3300; }</style><script>x</script>" \
  "$MBURL")
t "save: meta-box request accepted" "$( [ "$CODE" = "200" ] || [ "$CODE" = "302" ] && echo 1 || echo 0)"
get "/wp-admin/post.php?post=$ID&action=edit" r_edit2.html
t "after save: page group checked" "$(grep -oE '<input[^>]*name="wpd_page\[ovg\]\[page\]"[^>]*>' r_edit2.html | grep -q checked && echo 1 || echo 0)"
t "after save: style soft, palette soft (switch)" "$(all1 "$(grep -qE '<option value="soft"\s*selected' r_edit2.html && echo 1 || echo 0)" "$(grep -oE '<input[^>]*name="wpd_page\[ov\]\[palette\]" value="soft"[^>]*>' r_edit2.html | grep -q checked && echo 1 || echo 0)")"
t "after save: block style kept" "$(grep -oE '<input[^>]*name="wpd_page\[bstyle\]\[stats\]\[mt\]"[^>]*>' r_edit2.html | grep -q 'value="48"' && echo 1 || echo 0)"
t "after save: design still enabled" "$(grep -oE '<input type="checkbox" name="wpd_enabled"[^>]*>' r_edit2.html | grep -q checked && echo 1 || echo 0)"

# Страница сайта
curl -sL --max-time 180 "$B/olimpiada-r/" -o r_page.html
t "page: soft container and page primary" "$(all1 "$(has r_page.html 'wpd-style-soft')" "$(has r_page.html '--wpd-primary:#2235f8')")"
t "page: radius from page" "$(has r_page.html '--wpd-radius:24px')"
t "page: soft hero" "$(has r_page.html 'wpd-hero wpd-hero--soft')"
t "page: hero title from panel" "$(has r_page.html 'Заголовок фазы R')"
t "page: body class for soft page" "$(grep -oE '<body[^>]*>' r_page.html | grep -q 'wpd-soft-page' && echo 1 || echo 0)"
t "page: stats decorated" "$(grep -oE '<section[^>]*wpd-stats[^>]*>' r_page.html | grep -q 'wpd-b wpd-b--bg wpd-b--bg-dark wpd-b--mt wpd-b--w-narrow' && echo 1 || echo 0)"
t "page: stats vars" "$(has r_page.html '--wpd-b-bg:#112233;--wpd-b-mt:48px;')"
t "page: contacts hidden on phone, page phone" "$(all1 "$(has r_page.html 'wpd-b--hide-mobile')" "$(has r_page.html '+7 391 000-11-22')")"
t "page: own CSS inline" "$(has r_page.html '.wpd-page .wpd-stats { outline: 2px dashed #aa3300; }')"
t "page: own CSS cannot close style" "$(hasnt r_page.html '<script>x</script>')"
t "page: blocks in content use page settings" "$(grep -c '+7 391 000-11-22' r_page.html | awk '{print ($1>=1)?1:0}')"

curl -sL --max-time 180 "$B/olimpiada-r-src/" -o r_src.html
t "source page: own cherry palette" "$(all1 "$(has r_src.html '--wpd-primary:#be123c')" "$(hasnt r_src.html 'wpd-style-soft')")"
t "source page: no styles of other page" "$(all1 "$(hasnt r_src.html 'outline: 2px dashed')" "$(hasnt r_src.html '+7 391 000-11-22')")"

# REST с cookie и нонсом
rest() { local m=$1 path=$2; shift 2; curl -s -c cjR.txt -b cjR.txt --max-time 120 -o r_rest.json -w "%{http_code}" -X "$m" -H "X-WP-Nonce: $RN" "$@" "$B/?rest_route=$path"; }
C=$(rest GET "/wpd/v1/page/$ID")
t "rest export: 200" "$( [ "$C" = "200" ] && echo 1 || echo 0)"
t "rest export: plugin, version, enabled, ov" "$( [ "$(js r_rest.json 'd["plugin"]=="wp-olympiad-design" and d["version"]>="2.4.0" and d["enabled"] and "page" in d["data"]["ov"]')" = "True" ] && echo 1 || echo 0)"
cp r_rest.json r_export.json
C=$(rest POST "/wpd/v1/preview" --data-urlencode "post_id=$ID" --data-urlencode "form=wpd_page[ovg][page]=1&wpd_page[ov][palette]=forest&wpd_page[ov][design_style]=soft")
t "rest preview: 200 with forest primary" "$( [ "$C" = "200" ] && grep -q '#15803d' r_rest.json && echo 1 || echo 0)"
C=$(rest POST "/wpd/v1/page/$ID" --data-urlencode "from=$SRC")
t "rest copy from source: 200" "$( [ "$C" = "200" ] && echo 1 || echo 0)"
curl -sL --max-time 180 "$B/olimpiada-r/" -o r_page2.html
t "after copy: cherry palette and source hero title" "$(all1 "$(has r_page2.html '--wpd-primary:#be123c')" "$(has r_page2.html 'Заголовок источника')" "$(hasnt r_page2.html 'wpd-style-soft')")"
C=$(rest POST "/wpd/v1/page/$ID" -H "Content-Type: application/json" --data-binary '{"data":"{oops"}')
t "rest import invalid JSON: 400" "$( [ "$C" = "400" ] && grep -q 'wpd_json' r_rest.json && echo 1 || echo 0)"
python3 -c "import json;print(json.dumps({'data':json.dumps(json.load(open('r_export.json')))}))" > r_import.json
C=$(rest POST "/wpd/v1/page/$ID" -H "Content-Type: application/json" --data-binary @r_import.json)
curl -sL --max-time 180 "$B/olimpiada-r/" -o r_page3.html
t "rest import export JSON: back to soft page" "$( [ "$C" = "200" ] && [ "$(has r_page3.html 'wpd-style-soft')" = "1" ] && [ "$(has r_page3.html '+7 391 000-11-22')" = "1" ] && echo 1 || echo 0)"
C=$(rest DELETE "/wpd/v1/page/$ID")
curl -sL --max-time 180 "$B/olimpiada-r/" -o r_page4.html
t "rest reset: global look, design enabled" "$( [ "$C" = "200" ] && [ "$(hasnt r_page4.html 'wpd-style-soft')" = "1" ] && [ "$(hasnt r_page4.html 'wpd-b--')" = "1" ] && [ "$(has r_page4.html 'class="wpd-page')" = "1" ] && echo 1 || echo 0)"

# Права
C=$(curl -s --max-time 60 -o /dev/null -w "%{http_code}" "$B/?rest_route=/wpd/v1/page/$ID")
t "rest anonymous: 401" "$( [ "$C" = "401" ] && echo 1 || echo 0)"
C=$(curl -s -b cjR.txt --max-time 60 -o /dev/null -w "%{http_code}" -X DELETE "$B/?rest_route=/wpd/v1/page/$SRC")
t "rest cookie without nonce: 401/403" "$( [ "$C" = "401" ] || [ "$C" = "403" ] && echo 1 || echo 0)"
curl -sL --max-time 180 "$B/olimpiada-r-src/" -o r_src2.html
t "source page untouched" "$(has r_src2.html '--wpd-primary:#be123c')"

# Уборка
curl -sL --max-time 60 "$B/wpd-r-page.php?cleanup=1" > r_clean.txt
t "cleanup: pages removed" "$(has r_clean.txt '"cleaned":true')"
echo
echo "TOTAL: $((PASS+FAIL)), FAILED: $FAIL"
