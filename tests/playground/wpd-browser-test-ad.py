"""
Фаза AD (2.15.1): страница не остаётся голым текстом, если файл wpd.css не дошёл до браузера.
В Chromium запрос wpd.css перехватывается и обрывается (как при сломанном мобильном кеше или
минификации): на телефоне 390 px главный блок всё равно тёмный, кнопка акцентная и не ниже 48 px,
статистика в две колонки, нет горизонтальной прокрутки; мета viewport на месте. Затем та же
страница без блокировки — внешний вид прежний, ошибок JS нет.

Запуск: WPD_BASE=http://127.0.0.1:9400 python3 wpd-browser-test-ad.py <папка_для_скриншотов>
Нужны помощники wpd-ac-helper.php и wpd-set.php в корне WordPress.
"""
import json
import os
import sys
import urllib.parse
import urllib.request

from playwright.sync_api import sync_playwright

BASE = os.environ.get("WPD_BASE", "http://127.0.0.1:9400")
SHOTS = sys.argv[1] if len(sys.argv) > 1 else "."
os.makedirs(SHOTS, exist_ok=True)
results = []


def check(name, cond, detail=""):
    results.append(("PASS " if cond else "FAIL ") + name + ("" if cond or not detail else " :: " + str(detail)[:300]))


def fetch(url):
    with urllib.request.urlopen(url, timeout=180) as r:
        return r.read().decode("utf-8")


def set_opts(d):
    fetch(BASE + "/wpd-set.php?json=" + urllib.parse.quote(json.dumps(d)))


setup = json.loads(fetch(BASE + "/wpd-ac-helper.php?setup=1"))
URL = setup["url"]
check("helper setup", bool(setup.get("id") and URL), setup)

BG = "(sel) => { const e = document.querySelector(sel); return e ? getComputedStyle(e).backgroundColor : ''; }"
with sync_playwright() as pw:
    browser = pw.chromium.launch(args=["--disable-dev-shm-usage"])
    ctx = browser.new_context(viewport={"width": 390, "height": 844}, locale="ru-RU",
                              has_touch=True, is_mobile=True)
    page = ctx.new_page()
    errors = []
    page.on("pageerror", lambda e: errors.append(str(e)))
    # Файл стилей «не дошёл» до мобильного браузера
    page.route("**/assets/css/wpd.css*", lambda r: r.abort())
    page.goto(URL, wait_until="networkidle", timeout=180000)
    page.wait_for_timeout(600)
    hero_bg = page.evaluate(BG, ".wpd-hero")
    check("blocked css: hero is dark, not naked text", hero_bg == "rgb(11, 18, 32)", hero_bg)
    btn_bg = page.evaluate(BG, ".wpd-hero .wpd-btn")
    check("blocked css: button is accent", btn_bg == "rgb(249, 115, 22)", btn_bg)
    btn_h = page.evaluate("() => { const e = document.querySelector('.wpd-hero .wpd-btn'); "
                          "return e ? e.getBoundingClientRect().height : -1; }")
    check("blocked css: button height >= 48", btn_h >= 48, btn_h)
    cols = page.evaluate("() => { const e = document.querySelector('.wpd-stats__grid'); "
                         "return e ? getComputedStyle(e).gridTemplateColumns.split(' ').filter(Boolean).length : -1; }")
    check("blocked css: stats 2 columns on phone", cols == 2, cols)
    title_color = page.evaluate("() => { const e = document.querySelector('.wpd-hero__title'); "
                                "return e ? getComputedStyle(e).color : ''; }")
    check("blocked css: hero title is white", title_color == "rgb(255, 255, 255)", title_color)
    sw = page.evaluate("() => Math.max(document.documentElement.scrollWidth, document.body.scrollWidth)")
    check("blocked css: no horizontal scroll", sw <= 391, sw)
    meta = page.evaluate("() => document.querySelectorAll('meta[name=\"viewport\"]').length")
    check("viewport meta present", meta >= 1, meta)
    page.screenshot(path=os.path.join(SHOTS, "ad-blocked-css-mobile.png"), full_page=True)

    page.unroute("**/assets/css/wpd.css*")
    page.goto(URL, wait_until="networkidle", timeout=180000)
    page.wait_for_timeout(600)
    check("normal: hero is dark", page.evaluate(BG, ".wpd-hero") == "rgb(11, 18, 32)",
          page.evaluate(BG, ".wpd-hero"))
    check("no JS errors", not errors, "; ".join(errors[:3]))
    browser.close()

fetch(BASE + "/wpd-ac-helper.php?cleanup=1")
set_opts(setup.get("opts") or {})

fails = [r for r in results if r.startswith("FAIL")]
for r in results:
    print(r)
print(f"AD BROWSER: {len(results) - len(fails)}/{len(results)} passed")
sys.exit(1 if fails else 0)
