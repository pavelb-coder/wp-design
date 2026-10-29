"""
Фаза T (2.6.0): кнопки-ссылки в настоящем браузере (Playwright + Chromium) — вычисленные стили на сайте: вид по умолчанию
не изменился, колонки на компьютере и телефоне, «иконка слева», компактные, заливки, скругление, размер текста, тёмный фон,
цвет при наведении, подпись, метка, картинка, свой цвет кнопки, «Мягкий градиент»; редактор: разделы «Раскладка» и
«Вид и цвета» боковой панели блока меняют холст, поля под плиткой (метка, скрытие, даты показа), сохранение и сайт.

Запуск: WPD_BASE=http://127.0.0.1:9400 python3 wpd-browser-test-t.py <папка_для_скриншотов>
Нужны помощники wpd-t-page.php и wpd-set.php в корне WordPress.
"""
import json
import os
import sys
import time
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


def canvas(page):
    frame = page.frame(name="editor-canvas")
    return frame if frame else page


def wait_editor(page):
    try:
        page.wait_for_function("() => window.wp && wp.data && wp.data.select('core/editor') && wp.data.select('core/editor').getCurrentPostId()", timeout=90000)
    except Exception:
        page.screenshot(path=os.path.join(SHOTS, "t-editor-timeout.png"))
        print("EDITOR TIMEOUT at", page.url)
        page.reload(timeout=180000)
    page.wait_for_function("() => window.wp && wp.data && wp.data.select('core/editor') && wp.data.select('core/editor').getCurrentPostId()", timeout=120000)
    page.evaluate("""() => {
        try { wp.data.dispatch('core/preferences').set('core/edit-post', 'welcomeGuide', false); } catch (e) {}
        try { wp.data.dispatch('core/preferences').set('core', 'welcomeGuide', false); } catch (e) {}
        try { wp.data.dispatch('core/preferences').set('core', 'enableChoosePatternModal', false); } catch (e) {}
    }""")
    try:
        page.wait_for_selector("iframe[name=editor-canvas]", timeout=60000)
    except Exception:
        pass
    page.wait_for_selector("[data-wpd-pe]", state="attached", timeout=60000)
    time.sleep(3)
    open_metaboxes(page)


def open_metaboxes(page):
    """Область мета-боксов внизу блочного редактора (WP 6.7+) может быть свёрнута."""
    btn = page.locator("button", has_text="Meta Boxes")
    if btn.count() == 0:
        btn = page.locator("button", has_text="Мета-блоки")
    if btn.count() and btn.first.get_attribute("aria-expanded") == "false":
        btn.first.dispatch_event("click")
        time.sleep(1.5)


def open_document_sidebar(page):
    page.evaluate("""() => {
        try { wp.data.dispatch('core/edit-post').openGeneralSidebar('edit-post/document'); } catch (e) {}
        try { wp.data.dispatch('core/editor').openGeneralSidebar && wp.data.dispatch('core/editor').openGeneralSidebar('edit-post/document'); } catch (e) {}
    }""")
    time.sleep(1)
    page.wait_for_selector(".wpd-side", timeout=30000)
    # Панель могла быть свёрнута
    toggle = page.locator(".wpd-side > h2 button, .wpd-side .components-panel__body-toggle").first
    if toggle.count() and toggle.get_attribute("aria-expanded") == "false":
        toggle.click()
        time.sleep(0.5)


def reveal(page, selector):
    """Раскрывает свёрнутый раздел <details> панели, в котором находится поле."""
    page.evaluate("(sel) => { const e = document.querySelector(sel); const d = e && e.closest('details'); if (d) d.open = true; }", selector)
    time.sleep(0.2)


def save(page):
    page.evaluate("() => wp.data.dispatch('core/editor').savePost()")
    page.wait_for_function("() => !wp.data.select('core/editor').isSavingPost() && !wp.data.select('core/editor').isAutosavingPost()", timeout=120000)
    try:
        page.wait_for_function("() => !wp.data.select('core/edit-post').isSavingMetaBoxes()", timeout=60000)
    except Exception:
        pass
    time.sleep(2)



def report():
    print("\n".join(results))
    fails = sum(1 for r in results if r.startswith("FAIL"))
    print(f"\nPHASE T (browser): PASS={len(results) - fails} FAIL={fails}")


# Итоги печатаются и при падении сценария (видно, что успело пройти)
import atexit
atexit.register(report)


def set_opts(d):
    fetch(BASE + "/wpd-set.php?json=" + urllib.parse.quote(json.dumps(d)))


STYLE = """() => {
    const cs = (el) => el ? getComputedStyle(el) : null;
    return [...document.querySelectorAll('nav.wpd-buttons')].map(nav => {
        const grid = nav.querySelector('.wpd-buttons__grid');
        const tiles = [...nav.querySelectorAll('.wpd-qbtn')];
        const t = tiles[0];
        const g = cs(grid), s = cs(t);
        return {
            cls: nav.className,
            gridDisplay: g.display,
            cols: g.gridTemplateColumns.split(' ').filter(Boolean).length,
            gap: g.columnGap,
            texts: tiles.map(x => (x.querySelector('.wpd-qbtn__text') || {}).textContent),
            dir: s.flexDirection, minH: s.minHeight, bg: s.backgroundColor, bgImg: s.backgroundImage, color: s.color,
            radius: s.borderTopLeftRadius, border: s.borderTopWidth, fontSize: s.fontSize, align: s.textAlign,
            width: t.getBoundingClientRect().width,
            iconColor: (cs(t.querySelector('.wpd-qbtn__icon')) || {}).color,
            title: (nav.querySelector('.wpd-h2') || {}).textContent || '',
            tiles: tiles.map(x => {
                const st = cs(x), badge = x.querySelector('.wpd-qbtn__badge'), desc = x.querySelector('.wpd-qbtn__desc'), img = x.querySelector('.wpd-qbtn__img');
                return { text: (x.querySelector('.wpd-qbtn__text') || {}).textContent, cls: x.className, bg: st.backgroundColor, color: st.color,
                    iconColor: (cs(x.querySelector('.wpd-qbtn__icon')) || {}).color, opacity: st.opacity,
                    badge: badge ? { pos: cs(badge).position, text: badge.textContent, visible: badge.getBoundingClientRect().width > 0 } : null,
                    descSize: desc ? parseFloat(cs(desc).fontSize) : 0, textSize: parseFloat(cs(x.querySelector('.wpd-qbtn__text')).fontSize),
                    img: img ? img.getBoundingClientRect().width : 0, download: x.hasAttribute('download'),
                    after: getComputedStyle(x, '::after').display };
            }),
        };
    });
}"""


def tile(navs, i, text):
    for t in navs[i]["tiles"]:
        if t["text"] == text:
            return t
    return None


set_opts({})
info = json.loads(fetch(BASE + "/wpd-t-page.php?reset=1"))
PID = info["id"]
URL = info["url"]

with sync_playwright() as p:
    browser = p.chromium.launch(args=['--disable-dev-shm-usage'])
    ctx = browser.new_context(viewport={"width": 1440, "height": 1000}, locale="ru-RU", reduced_motion="reduce")
    page = ctx.new_page()
    js_errors = []
    page.on("pageerror", lambda e: js_errors.append(str(e)))
    page.on("console", lambda m: js_errors.append("console: " + m.text) if m.type == "error" and "wpd" in m.text.lower() else None)
    page.on("dialog", lambda d: d.accept())

    def close_modal(overlay):
        for cand in [overlay.locator("button[aria-label='Close']"), overlay.locator("button[aria-label='Закрыть']"), overlay.locator("button[aria-label]")]:
            try:
                if cand.count():
                    cand.first.click(timeout=2000, no_wait_after=True, force=True)
                    time.sleep(0.5)
                    if not overlay.is_visible():
                        return
            except Exception:
                pass
        page.keyboard.press("Escape")
        time.sleep(0.5)
    page.add_locator_handler(page.locator(".components-modal__screen-overlay"), close_modal)

    def front(opts, w=1440):
        set_opts(opts)
        page.set_viewport_size({"width": w, "height": 1000})
        page.goto(URL, timeout=180000)
        return page.evaluate(STYLE)

    # ---------- 1. Сайт: по умолчанию как раньше ----------
    set_opts({"buttons": [{"icon": "admin-home", "text": "Главная", "url": "https://example.com/"}, {"icon": "media-document", "text": "Положение", "url": "https://example.com/a"}]})
    page.goto(URL, timeout=180000)
    n = page.evaluate(STYLE)
    check("front: two button blocks", len(n) == 2, len(n))
    d = n[0]
    check("default: icon on top, white card, 116px", d["dir"] == "column" and d["minH"] == "116px" and d["bg"] == "rgb(255, 255, 255)", d)
    check("default: gap 12px, auto columns, no title", d["gap"] == "12px" and d["gridDisplay"] == "grid" and d["title"] == "", d)
    check("default: radius from design 16px, primary icon", d["radius"] == "16px" and d["iconColor"] == "rgb(29, 78, 216)", d)
    page.screenshot(path=os.path.join(SHOTS, "t-front-default.png"), full_page=True)

    base = {"buttons": [{"icon": "admin-home", "text": "Главная", "url": "https://example.com/", "desc": "Сайт олимпиады", "badge": "Новое", "color": "#1e3a8a"},
                        {"icon": "media-document", "text": "Положение", "url": "https://example.com/a", "download": 1},
                        {"icon": "clock", "text": "Итоги", "url": "https://example.com/r", "from": "2099-01-01 00:00"},
                        {"icon": "awards", "text": "Призёры", "url": "https://example.com/w"}]}
    # ---------- 2. Колонки ----------
    n = front(dict(base, buttons_columns="3", buttons_columns_mobile="1"))
    check("columns: 3 on desktop", n[0]["cols"] == 3 and n[0]["texts"] == ["Главная", "Положение", "Призёры"], n[0]["cols"])
    n = front(dict(base, buttons_columns="3", buttons_columns_mobile="1"), 390)
    check("columns: 1 on phone", n[0]["cols"] == 1, n[0]["cols"])
    n = front(dict(base, buttons_columns="3"), 390)
    check("columns: phone default 2 when desktop set", n[0]["cols"] == 2, n[0]["cols"])
    page.set_viewport_size({"width": 1440, "height": 1000})

    # ---------- 3. Раскладка и вид ----------
    n = front(dict(base, buttons_layout="row", buttons_title="Полезные ссылки", buttons_gap=24, buttons_height=80))
    d = n[0]
    check("row: icon left, height 80, gap 24", d["dir"] == "row" and d["minH"] == "80px" and d["gap"] == "24px", d)
    check("row: title shown", d["title"] == "Полезные ссылки")
    check("row: badge static inside row", tile(n, 0, "Главная")["badge"]["pos"] == "static", tile(n, 0, "Главная")["badge"])
    page.screenshot(path=os.path.join(SHOTS, "t-front-row.png"), full_page=True)
    n = front(dict(base, buttons_layout="compact", buttons_radius="pill"))
    d = n[0]
    check("compact: flex wrap, tiles by text width", d["gridDisplay"] == "flex" and d["width"] < 400 and d["dir"] == "row", d)
    check("compact: pill radius", d["radius"] == "999px", d["radius"])
    page.screenshot(path=os.path.join(SHOTS, "t-front-compact.png"), full_page=True)
    n = front(dict(base, buttons_variant="solid"))
    t2 = tile(n, 0, "Положение")
    check("solid: primary fill, white text and icon", t2["bg"] == "rgb(29, 78, 216)" and t2["color"] == "rgb(255, 255, 255)" and t2["iconColor"] == "rgb(255, 255, 255)", t2)
    n = front(dict(base, buttons_variant="outline"))
    check("outline: 2px border, transparent", n[0]["border"] == "2px" and tile(n, 0, "Положение")["bg"] == "rgba(0, 0, 0, 0)", n[0])
    n = front(dict(base, buttons_variant="tint"))
    bg = tile(n, 0, "Положение")["bg"]
    check("tint: light tint of primary", bg not in ("rgb(255, 255, 255)", "rgba(0, 0, 0, 0)") and bg != "rgb(29, 78, 216)", bg)
    n = front(dict(base, buttons_text_size=20, buttons_align="left", buttons_icon_color="#15803d"))
    check("text size 20, left aligned, icon color", n[0]["fontSize"] == "20px" and n[0]["align"] == "left" and tile(n, 0, "Положение")["iconColor"] == "rgb(21, 128, 61)", n[0])
    n = front(dict(base, buttons_bg="#0b1220"))
    t2 = tile(n, 0, "Положение")
    check("dark block bg: white text and icons", t2["bg"] == "rgb(11, 18, 32)" and t2["color"] == "rgb(255, 255, 255)" and t2["iconColor"] == "rgb(255, 255, 255)", t2)
    n = front(dict(base, buttons_hover_color="#b91c1c"))
    page.locator("nav.wpd-buttons").first.locator("a.wpd-qbtn", has_text="Положение").hover()
    time.sleep(0.6)
    hc = page.evaluate("() => getComputedStyle([...document.querySelectorAll('nav.wpd-buttons')[0].querySelectorAll('a.wpd-qbtn')].find(a => a.textContent.includes('Положение'))).color")
    check("hover color", hc == "rgb(185, 28, 28)", hc)

    # ---------- 4. Параметры кнопки ----------
    t1 = tile(n, 0, "Главная")
    check("item: own dark color -> white text", t1["bg"] == "rgb(30, 58, 138)" and t1["color"] == "rgb(255, 255, 255)" and "wpd-qbtn--dark" in t1["cls"], t1)
    check("item: badge in corner", t1["badge"] and t1["badge"]["pos"] == "absolute" and t1["badge"]["visible"] and t1["badge"]["text"] == "Новое", t1["badge"])
    check("item: desc smaller than text", 0 < t1["descSize"] < t1["textSize"], t1)
    check("item: download attribute", tile(n, 0, "Положение")["download"] and not t1["download"])
    check("item: future button not on site", tile(n, 0, "Итоги") is None)
    n2 = n[1]
    lg = [t for t in n2["tiles"] if t["text"] == "Логотип"][0]
    check("item: image instead of icon sized like icon (compact 22)", 20 <= lg["img"] <= 24, lg["img"])
    check("block 2: attributes (compact, outline, dark, title)", "wpd-buttons--compact" in n2["cls"] and "wpd-buttons--outline" in n2["cls"] and "wpd-buttons--dark" in n2["cls"] and n2["title"] == "Блок T", n2["cls"])
    check("block 2: visible by dates and hidden", n2["texts"] == ["Обычная", "Файл", "Логотип"], n2["texts"])
    page.screenshot(path=os.path.join(SHOTS, "t-front-items.png"), full_page=True)

    n = front(dict(base, design_style="soft", palette="soft"))
    t1 = tile(n, 0, "Главная"); t2 = tile(n, 0, "Положение")
    check("soft: arrow kept on plain tile, hidden with badge", t2["after"] != "none" and t1["after"] == "none", (t1["after"], t2["after"]))
    check("soft: gradient background kept", "gradient" in n[0]["bgImg"] or "gradient" in page.evaluate("() => getComputedStyle(document.querySelectorAll('nav.wpd-buttons .wpd-qbtn')[1]).backgroundImage"), n[0]["bgImg"])
    page.screenshot(path=os.path.join(SHOTS, "t-front-soft.png"), full_page=True)

    # ---------- 5. Редактор ----------
    set_opts(dict(base, buttons_title="Полезные ссылки"))
    page.goto(BASE + "/wp-login.php", timeout=120000)
    page.fill("#user_login", "admin")
    page.fill("#user_pass", "password")
    page.click("#wp-submit")
    page.wait_for_load_state("networkidle", timeout=120000)
    page.goto(f"{BASE}/wp-admin/post.php?post={PID}&action=edit", timeout=180000)
    wait_editor(page)
    cv = canvas(page)
    cv.wait_for_selector("nav.wpd-buttons .wpd-qbtn", timeout=60000)
    time.sleep(2)
    navs = cv.evaluate(STYLE)
    check("canvas: two blocks", len(navs) == 2, len(navs))
    check("canvas: block 1 settings title", navs[0]["title"] == "Полезные ссылки", navs[0]["title"])
    check("canvas: block 2 same look as site", "wpd-buttons--compact" in navs[1]["cls"] and "wpd-buttons--outline" in navs[1]["cls"] and "wpd-buttons--dark" in navs[1]["cls"] and navs[1]["title"] == "Блок T", navs[1]["cls"])
    tl = navs[1]["tiles"]
    check("canvas: hidden and out-of-date buttons visible, hidden dimmed", len(tl) == 6 and float([t for t in tl if t["text"] == "Скрытая"][0]["opacity"]) < 0.6, [(t["text"], t["opacity"]) for t in tl])
    check("canvas: scheduled marked", "wpd-qbtn--scheduled" in [t for t in tl if t["text"] == "Будущая"][0]["cls"])
    ids = page.evaluate("() => wp.data.select('core/block-editor').getBlocks().filter(b => b.name === 'wpd/buttons').map(b => b.clientId)")

    def select_block(cid):
        page.evaluate("(id) => wp.data.dispatch('core/block-editor').selectBlock(id)", cid)
        page.evaluate("""() => {
            try { wp.data.dispatch('core/edit-post').openGeneralSidebar('edit-post/block'); } catch (e) {}
            try { wp.data.dispatch('core/editor').openGeneralSidebar && wp.data.dispatch('core/editor').openGeneralSidebar('edit-post/block'); } catch (e) {}
        }""")
        page.wait_for_selector(".block-editor-block-inspector", timeout=30000)
        time.sleep(0.8)

    def attr(cid, name):
        return page.evaluate("([id, n]) => wp.data.select('core/block-editor').getBlockAttributes(id)[n]", [cid, name])

    def open_panel(cls):
        pb = page.locator(f".block-editor-block-inspector .{cls}")
        if pb.count() and "is-opened" not in (pb.first.get_attribute("class") or ""):
            pb.first.locator(".components-panel__body-toggle").click()
            time.sleep(0.5)
        return pb

    select_block(ids[0])
    lay = open_panel("wpd-ed-buttons-panel-layout")
    look = open_panel("wpd-ed-buttons-panel-look")
    check("inspector: «Раскладка» and «Вид и цвета» panels", lay.count() == 1 and look.count() == 1)
    insp = page.locator(".block-editor-block-inspector")
    sel_layout = insp.locator(".wpd-ed-buttons-layout select").first
    check("inspector: layout select shows settings value", sel_layout.input_value() == "stack", sel_layout.input_value())
    sel_layout.select_option("row")
    time.sleep(0.8)
    check("inspector layout -> attribute", attr(ids[0], "layout") == "row")
    check("inspector layout -> canvas", "wpd-buttons--row" in cv.evaluate(STYLE)[0]["cls"])
    insp.locator(".wpd-ed-buttons-variant select").first.select_option("solid")
    time.sleep(0.8)
    check("inspector variant -> canvas solid fill", cv.evaluate(STYLE)[0]["tiles"][1]["bg"] == "rgb(29, 78, 216)", cv.evaluate(STYLE)[0]["tiles"][1]["bg"])
    insp.locator(".wpd-ed-buttons-columns select").first.select_option("2")
    time.sleep(0.8)
    check("inspector columns -> canvas 2 columns", attr(ids[0], "columns") == "2" and cv.evaluate(STYLE)[0]["cols"] == 2, cv.evaluate(STYLE)[0]["cols"])
    gap = insp.locator(".wpd-ed-buttons-gap input[type=number]").first
    gap.fill("30")
    time.sleep(0.8)
    check("inspector gap -> canvas 30px", attr(ids[0], "gap") == 30 and cv.evaluate(STYLE)[0]["gap"] == "30px", cv.evaluate(STYLE)[0]["gap"])
    ts = insp.locator(".wpd-ed-buttons-textSize input[type=number]").first
    ts.fill("19")
    time.sleep(0.8)
    check("inspector text size -> canvas", cv.evaluate(STYLE)[0]["fontSize"] == "19px", cv.evaluate(STYLE)[0]["fontSize"])
    check("inspector: four color controls", all(insp.locator(f".wpd-ed-buttons-{a}").count() == 1 for a in ["bgColor", "iconColor", "textColor", "hoverColor"]))
    page.screenshot(path=os.path.join(SHOTS, "t-editor-inspector.png"))

    # Поля под плиткой (блок 2)
    select_block(ids[1])
    cv.wait_for_selector(".wpd-ed-item--buttons", timeout=30000)
    items = cv.locator(".wpd-page--buttons").nth(1).locator(".wpd-ed-item--buttons")
    check("item fields: one editor per button", items.count() == 6, items.count())
    first = items.nth(0)
    first.locator("input.wpd-ed-badge").fill("Важно")
    time.sleep(0.6)
    check("item badge -> attribute and canvas", attr(ids[1], "items")[0]["badge"] == "Важно" and cv.locator(".wpd-page--buttons").nth(1).locator(".wpd-qbtn__badge", has_text="Важно").count() == 1)
    first.locator("label.wpd-ed-check", has_text="скрыть кнопку").locator("input").dispatch_event("click")
    time.sleep(0.6)
    check("item hide -> attribute, dimmed, note", attr(ids[1], "items")[0]["hidden"] == 1
          and "wpd-qbtn--off" in (cv.locator(".wpd-page--buttons").nth(1).locator(".wpd-qbtn").first.get_attribute("class") or "")
          and "Скрыта на сайте" in cv.locator(".wpd-page--buttons").nth(1).locator("li").first.inner_text())
    first.locator("label.wpd-ed-check", has_text="скрыть кнопку").locator("input").dispatch_event("click")
    time.sleep(0.4)
    first.locator("input[aria-label='Показывать до']").fill("2000-06-01T12:30")
    time.sleep(0.6)
    check("item date -> attribute with space", attr(ids[1], "items")[0]["to"] == "2000-06-01 12:30", attr(ids[1], "items")[0].get("to"))
    check("item date -> scheduled note", "до 2000-06-01 12:30" in cv.locator(".wpd-page--buttons").nth(1).locator("li").first.inner_text())
    first.locator("label.wpd-ed-check", has_text="скачивать файл").locator("input").dispatch_event("click")
    time.sleep(0.4)
    check("item download -> attribute", attr(ids[1], "items")[0]["download"] == 1)
    page.screenshot(path=os.path.join(SHOTS, "t-editor-items.png"))

    save(page)
    page.set_viewport_size({"width": 1440, "height": 1000})
    page.goto(URL, timeout=180000)
    n = page.evaluate(STYLE)
    check("after save: block 1 row + solid + 2 cols + gap 30", "wpd-buttons--row" in n[0]["cls"] and "wpd-buttons--solid" in n[0]["cls"] and n[0]["cols"] == 2 and n[0]["gap"] == "30px", n[0]["cls"])
    check("after save: block 2 past 'to' hides first button", n[1]["texts"] == ["Файл", "Логотип"], n[1]["texts"])
    page.screenshot(path=os.path.join(SHOTS, "t-front-after-save.png"), full_page=True)
    page.goto(f"{BASE}/wp-admin/post.php?post={PID}&action=edit", timeout=180000)
    wait_editor(page)
    check("after reload: attributes stored", page.evaluate("() => { const b = wp.data.select('core/block-editor').getBlocks().filter(b => b.name === 'wpd/buttons'); return [b[0].attributes.layout, b[0].attributes.variant, b[0].attributes.gap, b[1].attributes.items[0].badge].join(','); }") == "row,solid,30,Важно")

    # ---------- 6. Вкладка настроек ----------
    page.goto(f"{BASE}/wp-admin/admin.php?page=wpd-settings&tab=buttons", timeout=180000)
    check("settings tab: section headings", page.locator("h2", has_text="Раскладка").count() >= 1 and page.locator("h2", has_text="Вид и цвета").count() >= 1)
    check("settings tab: datetime inputs in rows", page.locator('input[type="datetime-local"][name="wpd_settings[buttons][2][from]"]').input_value() == "2099-01-01T00:00")
    page.screenshot(path=os.path.join(SHOTS, "t-settings-buttons.png"), full_page=True)

    check("no JavaScript errors", not js_errors, js_errors[:3])
    browser.close()

fetch(BASE + "/wpd-t-page.php?cleanup=1")
set_opts({})
