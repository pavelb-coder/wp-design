#!/bin/bash
# Фаза E (1.3.0): HTTP-проверки страницы с панелью «Блоки олимпиады», страницы из блоков редактора и экрана редактирования.
# Запускать после wpd-test-d.php (читает resD.txt из текущей папки).
B=${WPD_BASE:-http://127.0.0.1:9400}
IDS_JSON=$(grep '^IDS ' resD.txt | sed 's/^IDS //')
PANEL=$(python3 -c 'import json,sys;print(json.loads(sys.argv[1])["panel"])' "$IDS_JSON")
BLOCKS=$(python3 -c 'import json,sys;print(json.loads(sys.argv[1])["blocks"])' "$IDS_JSON")
echo "fixtures: panel=$PANEL blocks=$BLOCKS"
PASS=0; FAIL=0
t() { if [ "$2" = "1" ]; then echo "PASS $1"; PASS=$((PASS+1)); else echo "FAIL $1"; FAIL=$((FAIL+1)); fi; }
has() { grep -q -- "$2" "$1" && echo 1 || echo 0; }
hasnot() { grep -q -- "$2" "$1" && echo 0 || echo 1; }
before() { python3 - "$1" "$2" "$3" <<'PY'
import sys
s=open(sys.argv[1],encoding='utf-8').read(); a=s.find(sys.argv[2]); b=s.find(sys.argv[3])
print(1 if a!=-1 and b!=-1 and a<b else 0)
PY
}

curl -sL --max-time 120 "$B/?page_id=$PANEL" -o e_panel.html
curl -sL --max-time 120 "$B/?page_id=$BLOCKS" -o e_blocks.html

t "panel page: designed wrapper"          "$(has e_panel.html 'class="wpd-page"')"
t "panel page: custom hero title"         "$(has e_panel.html 'Свой заголовок O&#039;Reilly')"
t "panel page: faq rendered before hero"  "$(before e_panel.html 'class="wpd-faq' 'class="wpd-hero')"
t "panel page: countdown hidden"          "$(hasnot e_panel.html 'class="wpd-countdown"')"
t "panel page: custom stats"              "$(has e_panel.html 'wpd-stat__num" data-count="42"')"
t "panel page: no PHP warnings"           "$(grep -qiE '(Warning|Notice|Deprecated|Fatal error)</b>' e_panel.html && echo 0 || echo 1)"

t "blocks page: css in head"              "$(python3 -c "s=open('e_blocks.html',encoding='utf-8').read();h=s[:s.find('</head>')];print(1 if 'wpd.css' in h else 0)")"
t "blocks page: js enqueued"              "$(has e_blocks.html 'wpd.js')"
t "blocks page: hero block wrapper"       "$(has e_blocks.html 'wpd-page wpd-page--block wpd-page--hero')"
t "blocks page: hero custom title"        "$(has e_blocks.html 'wpd-hero__title">Заголовок блока<')"
t "blocks page: paragraph between"        "$(before e_blocks.html 'wpd-page--hero' 'Абзац между блоками')"
t "blocks page: faq block after paragraph" "$(before e_blocks.html 'Абзац между блоками' 'wpd-page--faq')"
t "blocks page: not a designed page"      "$(hasnot e_blocks.html 'wpd-has-design')"
t "blocks page: no PHP warnings"          "$(grep -qiE '(Warning|Notice|Deprecated|Fatal error)</b>' e_blocks.html && echo 0 || echo 1)"

# Экран редактирования: панель, скрипты, данные блоков
rm -f cjE.txt
curl -s -c cjE.txt -b cjE.txt -o /dev/null --max-time 60 -d "log=admin&pwd=password&wp-submit=Log+In&redirect_to=/wp-admin/&testcookie=1" "$B/wp-login.php"
curl -s -c cjE.txt -b cjE.txt --max-time 120 "$B/wp-admin/post.php?post=$PANEL&action=edit" -o e_edit.html
t "edit screen opens"                     "$(has e_edit.html 'post_ID')"
t "edit: panel meta box present"          "$(has e_edit.html 'id="wpd_page_blocks"')"
t "edit: wpd-admin.js enqueued"           "$(has e_edit.html 'wpd-admin.js')"
t "edit: wpd-admin.css enqueued"          "$(has e_edit.html 'wpd-admin.css')"
t "edit: page editor data localized"      "$(has e_edit.html 'wpdPageEditor')"
t "edit: wpd-blocks.js enqueued"          "$(has e_edit.html 'wpd-blocks.js')"
t "edit: blocks data inline"              "$(has e_edit.html 'window.wpdBlocks')"
t "edit: media library enqueued"          "$(has e_edit.html 'media-views')"

# Настройки (1.4.0 — вкладки): 56 простых полей + 3 повторителя, справка о способах редактирования на вкладке «Справка»
: > e_settings.html
for tab in design hero features stats docs faq slider buttons cards notice news contacts help; do
  curl -s -c cjE.txt -b cjE.txt --max-time 60 "$B/wp-admin/admin.php?page=wpd-settings&tab=$tab" >> e_settings.html
done
plain_fields() { grep -oE "name=[\"']wpd_settings\[[a-z0-9_]+\][\"']" "$1" | grep -v '\[_tab\]' | tr "'" '"' | sort -u | wc -l | tr -d ' '; }
t "settings: 113 plain fields (+3 repeaters)" "$([ "$(plain_fields e_settings.html)" = "113" ] && echo 1 || echo 0)"
t "settings: help mentions panel"         "$(has e_settings.html 'Блоки олимпиады')"
t "settings: help mentions editor blocks" "$(has e_settings.html 'Страница олимпиады')"
t "edit: panel links to settings tab"     "$(has e_edit.html 'admin.php?page=wpd-settings&#038;tab=stats')"

# Статика
for f in assets/js/wpd-admin.js assets/js/wpd-blocks.js assets/css/wpd-admin.css assets/css/wpd-editor.css; do
  code=$(curl -s -o /dev/null -w "%{http_code}" --max-time 60 "$B/wp-content/plugins/wp-olympiad-design/$f")
  t "served 200: $f" "$([ "$code" = "200" ] && echo 1 || echo 0)"
done

echo ""
echo "PHASE E: PASS=$PASS FAIL=$FAIL"
