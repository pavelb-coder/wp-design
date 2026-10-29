"""
Фаза Y (2.11.0): прозрачность фонов и размеры текстов в настоящем браузере (Playwright + Chromium).
Сайт: вычисленные размеры заголовков блоков, заголовков элементов, подписей и текстов главного блока; прозрачность
фонов (элементы, подпись, плитки, карточки партнёров, главный блок, фон раздела, фон блока панели) — по альфа-каналу
вычисленного цвета; цвет текста на прозрачном фоне; телефон (заголовки не шире экрана). Редактор: классы и переменные
на холсте совпадают с сайтом, панели «Цвета», «Раскладка и вид», «Фон раздела», «Размеры текстов», изменение
прозрачности и фона раздела, сохранение. Панель «Блоки олимпиады»: поле «Прозрачность фона, %».

Запуск: WPD_BASE=http://127.0.0.1:9400 python3 wpd-browser-test-y.py <папка_для_скриншотов>
Нужны помощники wpd-y-helper.php и wpd-set.php в корне WordPress.
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


def set_opts(d):
    fetch(BASE + "/wpd-set.php?json=" + urllib.parse.quote(json.dumps(d)))


def canvas(page):
    frame = page.frame(name="editor-canvas")
    return frame if frame else page


READY = "() => window.wp && wp.data && wp.data.select('core/editor') && wp.data.select('core/editor').getCurrentPostId()"


def wait_editor(page):
    try:
        page.wait_for_function(READY, timeout=90000)
    except Exception:
        page.reload(timeout=180000)
    page.wait_for_function(READY, timeout=120000)
    page.evaluate("""() => {
        try { wp.data.dispatch('core/preferences').set('core/edit-post', 'welcomeGuide', false); } catch (e) {}
        try { wp.data.dispatch('core/preferences').set('core', 'welcomeGuide', false); } catch (e) {}
        try { wp.data.dispatch('core/preferences').set('core', 'enableChoosePatternModal', false); } catch (e) {}
    }""")
    try:
        page.wait_for_selector("iframe[name=editor-canvas]", timeout=60000)
    except Exception:
        pass
    time.sleep(3)


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
    print(f"\nPHASE Y (browser): PASS={len(results) - fails} FAIL={fails}")


# Альфа-канал вычисленного цвета: rgba(...), rgb(... / a), color(srgb r g b / a); нет альфы — 1
MEASURE = """() => {
    const q = (s) => document.querySelector(s);
    const alpha = (c) => {
        if (!c) return -1;
        let m = /\\/\\s*([\\d.]+)\\s*\\)$/.exec(c); if (m) return Math.round(parseFloat(m[1]) * 100) / 100;
        m = /^rgba\\([^,]+,[^,]+,[^,]+,\\s*([\\d.]+)\\)$/.exec(c); if (m) return Math.round(parseFloat(m[1]) * 100) / 100;
        return c === 'transparent' ? 0 : 1;
    };
    const cs = (s) => { const e = q(s); return e ? getComputedStyle(e) : null; };
    const fs = (s) => { const c = cs(s); return c ? Math.round(parseFloat(c.fontSize) * 10) / 10 : 0; };
    const bga = (s) => { const c = cs(s); return c ? alpha(c.backgroundColor) : -1; };
    const imgAlpha = (s) => { const c = cs(s); if (!c) return ''; return c.backgroundImage; };
    const sec = cs('.wpd-page--stats');
    return {
        statsH2: fs('.wpd-stats .wpd-h2'), statBg: bga('.wpd-stat'),
        secBg: sec ? alpha(sec.backgroundColor) : -1, secPad: sec ? parseFloat(sec.paddingTop) : 0,
        secH2Color: cs('.wpd-stats .wpd-h2') ? cs('.wpd-stats .wpd-h2').color : '',
        faqQ: fs('.wpd-faq__q'),
        heroTitle: fs('.wpd-hero__title'), heroLead: fs('.wpd-hero__lead'), heroBg: bga('.wpd-hero'),
        heroTitleColor: cs('.wpd-hero__title') ? cs('.wpd-hero__title').color : '',
        cardBg: bga('.wpd-card'), cardTitle: fs('.wpd-card__title'),
        qbtnBg: bga('.wpd-qbtn'), qbH2: fs('.wpd-buttons .wpd-h2'), qbDesc: fs('.wpd-qbtn__desc'),
        ptBg: bga('.wpd-partners__link'), ptText: fs('.wpd-partners__text'),
        scroll: document.documentElement.scrollWidth - window.innerWidth,
        vw: window.innerWidth,
    };
}"""

CLASSES = """(sel) => { const e = document.querySelector(sel); return e ? { cls: e.className, style: (e.getAttribute('style') || '').replace(/:\\s+/g, ':') } : null; }"""

set_opts({"bg": "#f1f5f9", "dark": "#0f172a"})
info = json.loads(fetch(BASE + "/wpd-y-helper.php?setup=1"))
PID = info["id"]
URL = info["url"]
PANEL_ID = info["pid"]
PANEL_URL = info["purl"]

with sync_playwright() as p:
    browser = p.chromium.launch(args=['--disable-dev-shm-usage'])
    ctx = browser.new_context(viewport={"width": 1440, "height": 1000}, locale="ru-RU")
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

    # ---------- 1. Сайт ----------
    page.goto(URL, timeout=180000)
    page.wait_for_load_state("networkidle", timeout=120000)
    m = page.evaluate(MEASURE)
    check("front: stats heading 38 px", m["statsH2"] == 38, m["statsH2"])
    check("front: stat cards 50% transparent", m["statBg"] == 0.5, m["statBg"])
    check("front: section background 20% transparent with padding", m["secBg"] == 0.8 and m["secPad"] >= 20, (m["secBg"], m["secPad"]))
    check("front: heading white on dark section", m["secH2Color"] == "rgb(255, 255, 255)", m["secH2Color"])
    check("front: faq questions 22 px", m["faqQ"] == 22, m["faqQ"])
    check("front: hero title 52 px, lead 21 px", m["heroTitle"] == 52 and m["heroLead"] == 21, (m["heroTitle"], m["heroLead"]))
    check("front: hero background 40% transparent", m["heroBg"] == 0.6, m["heroBg"])
    check("front: hero text light on dark transparent background", m["heroTitleColor"] == "rgb(255, 255, 255)", m["heroTitleColor"])
    check("front: card background 60% transparent, title 24 px", m["cardBg"] == 0.4 and m["cardTitle"] == 24, (m["cardBg"], m["cardTitle"]))
    check("front: button tiles 40% transparent, heading 30 px, desc 15 px", m["qbtnBg"] == 0.6 and m["qbH2"] == 30 and m["qbDesc"] == 15, (m["qbtnBg"], m["qbH2"], m["qbDesc"]))
    check("front: partner cards 50% transparent, names 15 px", m["ptBg"] == 0.5 and m["ptText"] == 15, (m["ptBg"], m["ptText"]))
    front = {k: page.evaluate(CLASSES, s) for k, s in {
        "stats": ".wpd-stats", "faq": ".wpd-faq", "hero": ".wpd-hero", "cards": ".wpd-cards", "buttons": ".wpd-buttons", "partners": ".wpd-partners",
        "wrap": ".wpd-page--stats"}.items()}
    page.screenshot(path=os.path.join(SHOTS, "y-front.png"), full_page=True)

    mob = browser.new_context(viewport={"width": 390, "height": 800}, locale="ru-RU")
    mp = mob.new_page()
    mp.goto(URL, timeout=180000)
    mm = mp.evaluate(MEASURE)
    check("front mobile: hero title limited by 11vw", abs(mm["heroTitle"] - round(0.11 * mm["vw"], 1)) <= 0.6, (mm["heroTitle"], mm["vw"]))
    check("front mobile: stats heading min(38, 10vw)", mm["statsH2"] == 38 or abs(mm["statsH2"] - mm["vw"] * 0.1) <= 0.6, mm["statsH2"])
    check("front mobile: no horizontal scroll", mm["scroll"] <= 1, mm["scroll"])
    mp.screenshot(path=os.path.join(SHOTS, "y-front-mobile.png"), full_page=True)
    mob.close()

    page.goto(PANEL_URL, timeout=180000)
    pb = page.evaluate("""() => { const e = document.querySelector('.wpd-stats.wpd-b'); if (!e) return null; const c = getComputedStyle(e);
        return { bg: c.backgroundColor, cls: e.className }; }""")
    check("front panel page: block background 60% transparent, dark text", pb is not None and pb["bg"].replace(" ", "") in ("rgba(11,18,32,0.4)",) and "wpd-b--bg-light" in pb["cls"], pb)

    # ---------- 2. Редактор ----------
    page.goto(BASE + "/wp-login.php", timeout=120000)
    page.fill("#user_login", "admin")
    page.fill("#user_pass", "password")
    page.click("#wp-submit")
    page.wait_for_load_state("networkidle", timeout=120000)
    page.goto(f"{BASE}/wp-admin/post.php?post={PID}&action=edit", timeout=180000)
    wait_editor(page)
    cv = canvas(page)
    cv.wait_for_selector(".wpd-page--partners .wpd-partners__item", timeout=60000)
    time.sleep(1.5)

    def own(cls, prefixes):
        return sorted(c for c in cls.split() if any(c.startswith(x) for x in prefixes))

    pref = {"stats": ["wpd-look"], "faq": ["wpd-look"], "cards": ["wpd-look"], "hero": ["wpd-hero"], "buttons": ["wpd-buttons"], "partners": ["wpd-partners"]}
    sel = {"stats": ".wpd-stats", "faq": ".wpd-faq", "hero": ".wpd-hero", "cards": ".wpd-cards", "buttons": ".wpd-buttons", "partners": ".wpd-partners"}
    for k in ["stats", "faq", "cards", "hero", "buttons", "partners"]:
        ed = cv.evaluate(CLASSES, sel[k])
        fc = own(front[k]["cls"], pref[k]) if front[k] else None
        ec = own(ed["cls"], pref[k]) if ed else None
        check(f"canvas {k}: same classes as site", fc is not None and fc == ec, (fc, ec))
    ed = cv.evaluate(CLASSES, ".wpd-stats")
    check("canvas stats: vars as site", ed and "--wpd-bl-fs-title:38px" in ed["style"] and "--wpd-bl-bg-a:50%" in ed["style"], ed)
    ed = cv.evaluate(CLASSES, ".wpd-hero")
    check("canvas hero: vars as site", ed and "--wpd-hero-a:60%" in ed["style"] and "--wpd-hero-fs-title:52px" in ed["style"] and "--wpd-hero-fs-lead:21px" in ed["style"], ed)
    wrap = cv.evaluate(CLASSES, ".wpd-page--stats")
    check("canvas: section background classes and var", wrap and "wpd-sec" in wrap["cls"].split() and "wpd-sec--dark" in wrap["cls"].split()
          and "--wpd-sec-bg:rgba(11,18,32,0.8)" in wrap["style"], wrap)
    cm = cv.evaluate(MEASURE)
    check("canvas: computed sizes and transparency as site", cm["statsH2"] == 38 and cm["statBg"] == 0.5 and cm["faqQ"] == 22 and cm["cardBg"] == 0.4 and cm["qbtnBg"] == 0.6 and cm["secBg"] == 0.8,
          {k: cm[k] for k in ["statsH2", "statBg", "faqQ", "cardBg", "qbtnBg", "secBg"]})

    def block_id(name):
        return page.evaluate("(n) => (wp.data.select('core/block-editor').getBlocks().find(b => b.name === n) || {}).clientId", name)

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

    def open_panel(title):
        btn = insp.get_by_role("button", name=title, exact=True).first
        if btn.count() and btn.get_attribute("aria-expanded") == "false":
            btn.click()
            time.sleep(0.6)
        return btn.count() > 0

    def set_number(locator, value):
        locator.evaluate("""(el, v) => { const s = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set;
            s.call(el, String(v)); el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); }""", value)
        time.sleep(0.8)

    insp = page.locator(".block-editor-block-inspector")
    sid = block_id("wpd/stats")
    select_block(sid)
    check("inspector stats: colours panel", open_panel("Цвета"))
    tr = insp.locator(".wpd-ed-look-bgTransparency")
    check("inspector stats: transparency control", tr.count() == 1 and "Прозрачность фона карточек, %" in tr.text_content(), tr.text_content() if tr.count() else "none")
    set_number(tr.locator("input[type=number]").first, 80)
    st = cv.evaluate(CLASSES, ".wpd-stats")
    check("transparency 80 -> attribute and canvas", attr(sid, "bgTransparency") == 80 and "--wpd-bl-bg-a:20%" in st["style"], (attr(sid, "bgTransparency"), st))
    check("inspector stats: layout panel has heading size", open_panel("Раскладка и вид") and insp.locator(".wpd-ed-look-headingSize").count() == 1)
    check("inspector stats: section panel", open_panel("Фон раздела") and insp.locator(".wpd-ed-section-tr").count() == 1)
    set_number(insp.locator(".wpd-ed-section-tr input[type=number]").first, 95)
    wrap = cv.evaluate(CLASSES, ".wpd-page--stats")
    check("section transparency 95 -> light text class", attr(sid, "sectionBgTr") == 95 and "wpd-sec--light" in wrap["cls"].split() and "rgba(11,18,32,0.05)" in wrap["style"], wrap)
    page.screenshot(path=os.path.join(SHOTS, "y-editor-stats.png"))

    fid = block_id("wpd/faq")
    select_block(fid)
    open_panel("Раскладка и вид")
    iq = insp.locator(".wpd-ed-look-itemSize")
    check("inspector faq: question size control", iq.count() == 1 and "Размер вопросов, px" in iq.text_content())

    hid = block_id("wpd/hero")
    select_block(hid)
    check("inspector hero: no section panel", insp.get_by_role("button", name="Фон раздела", exact=True).count() == 0)
    check("inspector hero: text sizes panel", open_panel("Размеры текстов") and insp.locator(".wpd-ed-hero-sizes .components-range-control").count() == 4)
    set_number(insp.locator(".wpd-ed-hero-badgeSize input[type=number]").first, 16)
    he = cv.evaluate(CLASSES, ".wpd-hero")
    check("hero badge size -> canvas", attr(hid, "badgeSize") == 16 and "wpd-hero--fs-badge" in he["cls"] and "--wpd-hero-fs-badge:16px" in he["style"], he)
    check("inspector hero: transparency in main panel", insp.locator(".wpd-ed-hero-bgTransparency").count() == 1)

    bid = block_id("wpd/buttons")
    select_block(bid)
    open_panel("Вид и цвета")
    check("inspector buttons: transparency and small size", insp.locator(".wpd-ed-buttons-bgTransparency").count() == 1 and insp.locator(".wpd-ed-buttons-smallSize").count() == 1)
    open_panel("Раскладка")
    check("inspector buttons: heading size", insp.locator(".wpd-ed-buttons-headingSize").count() == 1)
    ptid = block_id("wpd/partners")
    select_block(ptid)
    check("inspector partners: transparency and sizes", insp.locator(".wpd-ed-partners-bgTransparency").count() == 1 and insp.locator(".wpd-ed-partners-headingSize").count() == 1)

    save(page)
    page.goto(URL, timeout=180000)
    fr = page.evaluate("""() => ({ stats: (document.querySelector('.wpd-stats') || {}).getAttribute('style') || '',
        wrap: (document.querySelector('.wpd-page--stats') || {}).className || '', hero: (document.querySelector('.wpd-hero') || {}).getAttribute('style') || '' })""")
    check("front after save: transparency, section and badge size", "--wpd-bl-bg-a:20%" in fr["stats"] and "wpd-sec--light" in fr["wrap"] and "--wpd-hero-fs-badge:16px" in fr["hero"], fr)

    # ---------- 3. Панель «Блоки олимпиады» ----------
    page.goto(f"{BASE}/wp-admin/post.php?post={PANEL_ID}&action=edit", timeout=180000)
    wait_editor(page)
    f = page.locator('[name="wpd_page[bstyle][stats][bg_tr]"]')
    check("page panel: transparency field with saved value", f.count() == 1 and f.input_value() == "60", f.input_value() if f.count() else "none")

    # ---------- 4. Настройки ----------
    page.goto(f"{BASE}/wp-admin/admin.php?page=wpd-settings&tab=faq", timeout=180000)
    check("settings faq: size and transparency fields", page.locator('[name="wpd_settings[faq_item_size]"]').count() == 1 and page.locator('[name="wpd_settings[faq_bg_tr]"]').count() == 1)
    page.screenshot(path=os.path.join(SHOTS, "y-settings-faq.png"), full_page=True)

    check("no JavaScript errors", not js_errors, js_errors[:3])
    browser.close()

fetch(BASE + "/wpd-y-helper.php?cleanup=1")
set_opts({})
report()
