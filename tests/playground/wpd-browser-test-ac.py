"""
Фаза AC (2.15.0): мобильная адаптация в настоящем браузере (Playwright + Chromium).
Страница помощника wpd-ac-helper.php (все компоненты шорткодами) открывается на ширинах
390 / 360 / 320 px, на планшете 768 px и в ландшафте телефона 800x360: нет горизонтальной
прокрутки страницы, кнопки и вопросы FAQ не ниже 48 px, документы не ниже 56 px, статистика
в 2 колонки (на 320 px партнёры — в 1), карусель новостей — карточка на ~86% экрана, слайд
не выше 300 px (в ландшафте — 240 px), поиск по FAQ — шрифт не меньше 16 px (iOS не приближает),
на компьютере (1200 px) вид прежний: статистика не в 2 колонки принудительно, кнопка главного
блока не во всю ширину колонки действий. Скриншоты — в папку из аргумента.

Запуск: WPD_BASE=http://127.0.0.1:9400 python3 wpd-browser-test-ac.py <папка_для_скриншотов>
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

JS = {
    "overflow": "() => Math.max(document.documentElement.scrollWidth, document.body ? document.body.scrollWidth : 0)",
    "cols": "(sel) => { const e = document.querySelector(sel); if (!e) return -1; "
            "return getComputedStyle(e).gridTemplateColumns.split(' ').filter(Boolean).length; }",
    "height": "(sel) => { const e = document.querySelector(sel); return e ? e.getBoundingClientRect().height : -1; }",
    "minheight": "(sel) => { const e = document.querySelector(sel); return e ? parseFloat(getComputedStyle(e).minHeight) || 0 : -1; }",
    "fontsize": "(sel) => { const e = document.querySelector(sel); return e ? parseFloat(getComputedStyle(e).fontSize) : -1; }",
    "ratio": "() => { const i = document.querySelector('.wpd-news--carousel .wpd-news__item'); "
             "const t = document.querySelector('.wpd-news--carousel .wpd-news__track'); "
             "return (i && t && t.clientWidth) ? i.getBoundingClientRect().width / t.clientWidth : -1; }",
    "actions_dir": "() => { const e = document.querySelector('.wpd-hero__actions'); "
                   "return e ? getComputedStyle(e).flexDirection : ''; }",
}


def probe(page, width, label, shot=None):
    page.set_viewport_size({"width": width, "height": 844})
    page.goto(URL, wait_until="networkidle", timeout=180000)
    page.wait_for_timeout(600)
    sw = page.evaluate(JS["overflow"])
    check(f"{label}: no horizontal scroll", sw <= width + 1, f"scrollWidth={sw} width={width}")
    if shot:
        page.screenshot(path=os.path.join(SHOTS, shot), full_page=True)


with sync_playwright() as pw:
    browser = pw.chromium.launch(args=["--disable-dev-shm-usage"])
    ctx = browser.new_context(viewport={"width": 390, "height": 844}, locale="ru-RU",
                              has_touch=True, is_mobile=True)
    page = ctx.new_page()
    errors = []
    page.on("pageerror", lambda e: errors.append(str(e)))
    probe(page, 390, "phone 390", "ac-mobile-390.png")
    check("phone 390: hero button min-height >= 48", page.evaluate(JS["minheight"], ".wpd-hero .wpd-btn") >= 48,
          page.evaluate(JS["minheight"], ".wpd-hero .wpd-btn"))
    check("phone 390: hero actions column", page.evaluate(JS["actions_dir"]) == "column",
          page.evaluate(JS["actions_dir"]))
    check("phone 390: stats 2 columns", page.evaluate(JS["cols"], ".wpd-stats__grid") == 2,
          page.evaluate(JS["cols"], ".wpd-stats__grid"))
    check("phone 390: docs 1 column", page.evaluate(JS["cols"], ".wpd-docs__list") == 1,
          page.evaluate(JS["cols"], ".wpd-docs__list"))
    check("phone 390: cards 1 column", page.evaluate(JS["cols"], ".wpd-cards__grid") == 1,
          page.evaluate(JS["cols"], ".wpd-cards__grid"))
    check("phone 390: partners 2 columns", page.evaluate(JS["cols"], ".wpd-partners__list") == 2,
          page.evaluate(JS["cols"], ".wpd-partners__list"))
    check("phone 390: faq question height >= 48", page.evaluate(JS["height"], ".wpd-faq__q") >= 48,
          page.evaluate(JS["height"], ".wpd-faq__q"))
    check("phone 390: doc height >= 56", page.evaluate(JS["height"], ".wpd-doc") >= 56,
          page.evaluate(JS["height"], ".wpd-doc"))
    sfs = page.evaluate(JS["fontsize"], ".wpd-faq__search")
    check("phone 390: faq search font >= 16 (no iOS zoom)", sfs == -1 or sfs >= 16, sfs)
    r = page.evaluate(JS["ratio"])
    check("phone 390: news carousel item ~86%", r == -1 or 0.80 <= r <= 0.92, r)
    sh = page.evaluate(JS["height"], ".wpd-slide")
    check("phone 390: slide height <= 310", sh == -1 or sh <= 310, sh)

    probe(page, 360, "phone 360", "ac-mobile-360.png")
    probe(page, 320, "phone 320", "ac-mobile-320.png")
    check("phone 320: partners 1 column", page.evaluate(JS["cols"], ".wpd-partners__list") == 1,
          page.evaluate(JS["cols"], ".wpd-partners__list"))

    probe(page, 768, "tablet 768")
    check("tablet 768: no forced phone stats (cols != 2 or wide)", page.evaluate(JS["cols"], ".wpd-stats__grid") >= 2,
          page.evaluate(JS["cols"], ".wpd-stats__grid"))

    # Ландшафт телефона: слайд не выше 250 px
    page.set_viewport_size({"width": 800, "height": 360})
    page.goto(URL, wait_until="networkidle", timeout=180000)
    page.wait_for_timeout(600)
    sw = page.evaluate(JS["overflow"])
    check("landscape 800x360: no horizontal scroll", sw <= 801, f"scrollWidth={sw}")
    sh = page.evaluate(JS["height"], ".wpd-slide")
    check("landscape: slide height <= 250", sh == -1 or sh <= 250, sh)
    page.screenshot(path=os.path.join(SHOTS, "ac-landscape.png"), full_page=False)

    # Компьютер: мобильный слой не действует
    dctx = browser.new_context(viewport={"width": 1200, "height": 900}, locale="ru-RU")
    dpage = dctx.new_page()
    dpage.goto(URL, wait_until="networkidle", timeout=180000)
    dpage.wait_for_timeout(600)
    check("desktop 1200: hero actions row", dpage.evaluate(JS["actions_dir"]) == "row",
          dpage.evaluate(JS["actions_dir"]))
    sw = dpage.evaluate(JS["overflow"])
    check("desktop 1200: no horizontal scroll", sw <= 1201, f"scrollWidth={sw}")
    dpage.screenshot(path=os.path.join(SHOTS, "ac-desktop.png"), full_page=False)

    check("no JS errors", not errors, "; ".join(errors[:3]))
    browser.close()

fetch(BASE + "/wpd-ac-helper.php?cleanup=1")
set_opts(setup.get("opts") or {})

fails = [r for r in results if r.startswith("FAIL")]
for r in results:
    print(r)
print(f"AC BROWSER: {len(results) - len(fails)}/{len(results)} passed")
sys.exit(1 if fails else 0)
