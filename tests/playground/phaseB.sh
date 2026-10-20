#!/bin/bash
# Фаза B: проверки фронтенда и админки по HTTP
B=${WPD_BASE:-http://127.0.0.1:9400}
IDS_JSON=$(grep '^IDS ' resA.txt | sed 's/^IDS //')
D=$(python3 -c 'import json,sys;print(json.loads(sys.argv[1])["designed"])' "$IDS_JSON")
P=$(python3 -c 'import json,sys;print(json.loads(sys.argv[1])["plain"])' "$IDS_JSON")
F=$(python3 -c 'import json,sys;print(json.loads(sys.argv[1])["featured"])' "$IDS_JSON")
echo "fixtures: designed=$D plain=$P featured=$F"
PASS=0; FAIL=0
t() { local name="$1" cond="$2"; if [ "$cond" = "1" ]; then echo "PASS $name"; PASS=$((PASS+1)); else echo "FAIL $name"; FAIL=$((FAIL+1)); fi; }
has() { grep -q -- "$2" "$1" && echo 1 || echo 0; }
hasnot() { grep -q -- "$2" "$1" && echo 0 || echo 1; }

curl -sL --max-time 120 "$B/?p=$D" -o f_designed.html
curl -sL --max-time 120 "$B/?p=$P" -o f_plain.html
curl -sL --max-time 120 "$B/?p=$F" -o f_featured.html
curl -sL --max-time 120 "$B/?p=5" -o f_shortcode.html
curl -sL --max-time 120 "$B/?p=4" -o f_blueprint.html

t "designed: wrapper present"        "$(has f_designed.html 'class="wpd-page"')"
t "designed: body class wpd-has-design" "$(has f_designed.html 'wpd-has-design')"
t "designed: hero h1 = post title"  "$(has f_designed.html '<h1 class="wpd-hero__title">Оформленная</h1>')"
t "designed: excerpt as lead"       "$(has f_designed.html 'wpd-hero__lead">Лид текст')"
t "designed: content in card"       "$(has f_designed.html 'class="wpd-content"')"
t "designed: body text rendered"    "$(has f_designed.html 'Контент')"
t "designed: stats shown"           "$(has f_designed.html 'wpd-stat__num">12–18')"
t "designed: faq shown"             "$(has f_designed.html 'wpd-faq__q">Q?')"
t "designed: docs from settings shown" "$(has f_designed.html 'wpd-docs')"

t "plain: no design wrapper"        "$(hasnot f_plain.html 'wpd-page')"
t "plain: no body class"            "$(hasnot f_plain.html 'wpd-has-design')"
t "plain: no wpd css link"          "$(hasnot f_plain.html 'wpd.css')"
t "designed: wpd css link"          "$(has f_designed.html 'wpd.css')"
t "shortcode page: wpd css link"    "$(has f_shortcode.html 'wpd.css')"
t "plain: content intact"           "$(has f_plain.html 'Обычный')"

t "featured: thumbnail used in hero" "$(has f_featured.html 'wpd-hero__media')"
t "featured: thumbnail URL"          "$(has f_featured.html 'thumb.jpg')"
t "featured: no excerpt -> no lead"  "$(hasnot f_featured.html 'wpd-hero__lead')"

t "shortcode page: wpd_page output"  "$(has f_shortcode.html 'wpd-hero__title')"
t "shortcode page: no body class"    "$(hasnot f_shortcode.html 'wpd-has-design')"

t "blueprint page 4: designed"       "$(has f_blueprint.html 'wpd-has-design')"
t "blueprint page 4: excerpt lead"   "$(has f_blueprint.html 'Вступление записи')"

# CSS подключён и доступен
CSS_URL=$(grep -oE "https?://[^\"' ]+wpd[.]css[^\"' ]*" f_designed.html | head -1)
t "css enqueued in head"             "$([ -n "$CSS_URL" ] && echo 1 || echo 0)"
if [ -n "$CSS_URL" ]; then
  curl -s --max-time 60 "$CSS_URL" -o wpd.css -w "%{http_code} %{content_type}\n" | tee css_status.txt
  t "css served 200"                  "$(grep -q '^200' css_status.txt && echo 1 || echo 0)"
  t "css contains .wpd-page rules"    "$(has wpd.css '.wpd-page .wpd-hero')"
fi

# hide_title = 0 -> класс не выводится
curl -s --max-time 60 "$B/wpd-set.php?json=$(python3 -c 'import urllib.parse,json;print(urllib.parse.quote(json.dumps({"hide_title":0,"docs":"Положение|https://example.com/p.pdf","stats":"12–18|возраст","faq":"Q?\nA"})))')" > set1.txt
curl -sL --max-time 120 "$B/?p=$D" -o f_designed_nohide.html
t "hide_title off: no body class"    "$(hasnot f_designed_nohide.html 'wpd-has-design')"
t "hide_title off: design still on"  "$(has f_designed_nohide.html 'class="wpd-page"')"

# Док-блок и кастомные цвета уходят в CSS-переменные
curl -s --max-time 60 "$B/wpd-set.php?json=$(python3 -c 'import urllib.parse,json;print(urllib.parse.quote(json.dumps({"primary":"#0f766e","accent":"#f59e0b","docs":"Положение|https://example.com/p.pdf\nБез|javascript:alert(1)","stats":"","faq":"","hide_title":1})))')" > set2.txt
curl -sL --max-time 120 "$B/?p=$D" -o f_designed_custom.html
t "custom primary in vars"           "$(has f_designed_custom.html '--wpd-primary:#0f766e')"
t "custom accent in vars"            "$(has f_designed_custom.html '--wpd-accent:#f59e0b')"
t "docs: valid link shown"           "$(has f_designed_custom.html 'https://example.com/p.pdf')"
t "docs: javascript link absent"     "$(hasnot f_designed_custom.html 'javascript:')"
t "empty stats hidden on page"       "$(hasnot f_designed_custom.html 'wpd-stats')"
t "empty faq hidden on page"         "$(hasnot f_designed_custom.html 'wpd-faq')"

# Страница настроек в админке: 19 полей и кнопка сохранения
rm -f cj.txt
curl -s -c cj.txt -b cj.txt -o /dev/null --max-time 60 -d "log=admin&pwd=password&wp-submit=Log+In&redirect_to=/wp-admin/&testcookie=1" $B/wp-login.php
curl -s -c cj.txt -b cj.txt --max-time 60 "$B/wp-admin/options-general.php?page=wpd-settings" -o settings.html
t "admin settings page opens"        "$(has settings.html 'Дизайн олимпиады')"
t "19 settings fields rendered"      "$([ "$(grep -o 'name="wpd_settings\[' settings.html | wc -l)" = "19" ] && echo 1 || echo 0)"
for sec in 'Цвета и шрифт' 'Главный блок' 'Статистика' 'Документы' 'Частые вопросы' 'Поведение'; do t "section: $sec" "$(has settings.html "$sec")"; done
t "shortcode help on page"           "$(has settings.html '\[wpd_page\]')"

echo ""
echo "PHASE B: PASS=$PASS FAIL=$FAIL"
