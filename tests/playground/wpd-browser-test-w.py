"""
Фаза W (2.9.0): свои шрифты и логотипы в настоящем браузере (Playwright + Chromium).
Сайт: шрифт из файла (@font-face) действительно загружается и применяется к заголовку главного блока и цифрам блока,
логотип рядом с заголовком и его высота, логотипы партнёров (сетка и лента: прокрутка, копии для бесшовной ленты,
чёрно-белые с цветом при наведении, «уменьшение движения»). Редактор: шрифт на холсте, панель «Шрифты» с подсказками
и предпросмотр, блок «Логотипы партнёров» (раскладка, вид), логотип в блоке «Главный блок», сохранение.
Настройки: добавление строки файла шрифта и подсказки шрифтов.

Запуск: WPD_BASE=http://127.0.0.1:9400 python3 wpd-browser-test-w.py <папка_для_скриншотов>
Нужны помощники wpd-w-helper.php и wpd-set.php и картинка hero19.svg в корне WordPress.
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
    print(f"\nPHASE W (browser): PASS={len(results) - fails} FAIL={fails}")


LOGO = BASE + "/hero19.svg"
FONT = BASE + "/wp-includes/fonts/dashicons.woff2"
OPTS = {
    "font_files": [{"family": "Wpd Test", "url": FONT, "weight": "400", "style": "normal"}],
    "font_hero": '"Wpd Test", sans-serif',
    "hero_title": "Олимпиада W",
    "hero_style": "light",
    "hero_logo": LOGO, "hero_logo_pos": "beside", "hero_logo_alt": "Лого W",
    "partners_title": "Партнёры W",
    "partners": [{"logo": LOGO, "name": "Фонд W", "url": "https://fond.example/", "new_tab": 1, "hidden": 0},
                 {"logo": LOGO, "name": "Вуз W", "url": "", "new_tab": 0, "hidden": 0},
                 {"logo": "", "name": "Только имя W", "url": "", "new_tab": 0, "hidden": 0}],
    "partners_layout": "grid", "partners_columns": "3", "partners_gray": 1,
}

FRONT = """() => {
    const q = (s, r) => (r || document).querySelector(s);
    const title = q('.wpd-hero__title'), logo = q('.wpd-hero__logo-img');
    const tb = title ? title.getBoundingClientRect() : null, lb = logo ? logo.getBoundingClientRect() : null;
    const grid = [...document.querySelectorAll('.wpd-partners')].find(s => s.classList.contains('wpd-partners--grid'));
    const strip = q('.wpd-partners--marquee') || q('.wpd-partners--strip');
    const gl = grid ? [...grid.querySelectorAll('.wpd-partners__item')] : [];
    const track = strip ? q('.wpd-partners__list', strip) : null;
    const num = q('.wpd-page--stats .wpd-stat__num');
    return {
        titleFont: title ? getComputedStyle(title).fontFamily : '',
        logo: lb ? { h: Math.round(lb.height), right: Math.round(lb.right), titleLeft: Math.round(tb.left), mid: Math.abs((lb.top + lb.bottom) / 2 - (tb.top + tb.bottom) / 2) < tb.height } : null,
        gridItems: gl.length,
        gridRows: new Set(gl.map(li => Math.round(li.getBoundingClientRect().top))).size,
        gridFilter: grid && q('.wpd-partners__logo', grid) ? getComputedStyle(q('.wpd-partners__logo', grid)).filter : '',
        stripClass: strip ? strip.className : '',
        stripAnim: track ? getComputedStyle(track).animationName + ' ' + getComputedStyle(track).animationDuration : '',
        clones: strip ? strip.querySelectorAll('[aria-hidden="true"] .wpd-partners__logo, .wpd-partners__item[aria-hidden="true"]').length : 0,
        cloneFocus: strip ? [...strip.querySelectorAll('[aria-hidden="true"] a, a[tabindex="-1"]')].every(a => a.getAttribute('tabindex') === '-1') : false,
        numFont: num ? getComputedStyle(num).fontFamily : '',
    };
}"""

set_opts(OPTS)
info = json.loads(fetch(BASE + "/wpd-w-helper.php?setup=1"))
PID = info["id"]
URL = info["url"]

with sync_playwright() as p:
    browser = p.chromium.launch(args=['--disable-dev-shm-usage'])
    ctx = browser.new_context(viewport={"width": 1440, "height": 1000}, locale="ru-RU", reduced_motion="no-preference")
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
    loaded = page.evaluate("async () => { const f = await document.fonts.load('16px \"Wpd Test\"'); return f.length; }")
    check("front: own font file downloaded via @font-face", loaded >= 1, loaded)
    check("front: font usable", page.evaluate("() => document.fonts.check('16px \"Wpd Test\"')"))
    m = page.evaluate(FRONT)
    check("front: hero title uses own font", m["titleFont"].startswith('"Wpd Test"'), m["titleFont"])
    check("front: hero logo beside title, 56 px", m["logo"] and m["logo"]["h"] == 56 and m["logo"]["right"] <= m["logo"]["titleLeft"] and m["logo"]["mid"], m["logo"])
    check("front: partners grid 3 columns (3 items, 1 row)", m["gridItems"] == 3 and m["gridRows"] == 1, m)
    check("front: grayscale logos", "grayscale(1)" in m["gridFilter"], m["gridFilter"])
    check("front: strip block scrolls", "wpd-partners--marquee" in m["stripClass"] and m["stripAnim"].startswith("wpd-partners-marquee") and m["stripAnim"].endswith("3.6s"), m)
    check("front: strip clones hidden from screen readers and focus", m["clones"] >= 2 and m["cloneFocus"], m)
    check("front: stats block numbers font (block attribute)", m["numFont"].startswith('"Wpd Test"'), m["numFont"])
    page.locator(".wpd-partners--grid .wpd-partners__item").first.scroll_into_view_if_needed()
    page.locator(".wpd-partners--grid .wpd-partners__link").first.hover()
    time.sleep(0.8)
    hov = page.evaluate("() => getComputedStyle(document.querySelector('.wpd-partners--grid .wpd-partners__logo')).filter")
    check("front: color on hover", "grayscale(1)" not in hov, hov)
    a0 = page.locator(".wpd-partners--grid a.wpd-partners__link").first
    check("front: partner link opens in new tab", a0.get_attribute("href") == "https://fond.example/" and a0.get_attribute("target") == "_blank" and "noopener" in (a0.get_attribute("rel") or ""))
    page.screenshot(path=os.path.join(SHOTS, "w-front.png"), full_page=True)

    rctx = browser.new_context(viewport={"width": 1440, "height": 1000}, locale="ru-RU", reduced_motion="reduce")
    rp = rctx.new_page()
    rp.goto(URL, timeout=180000)
    anim = rp.evaluate("() => { const t = document.querySelector('.wpd-partners--marquee .wpd-partners__list'); return t ? getComputedStyle(t).animationName : 'none'; }")
    check("front: reduced motion stops the strip", anim == "none", anim)
    rctx.close()

    mob = browser.new_context(viewport={"width": 390, "height": 800}, locale="ru-RU")
    mp = mob.new_page()
    mp.goto(URL, timeout=180000)
    over = mp.evaluate("() => document.documentElement.scrollWidth - window.innerWidth")
    check("front mobile: no horizontal scroll", over <= 1, over)
    mp.screenshot(path=os.path.join(SHOTS, "w-front-mobile.png"), full_page=True)
    mob.close()

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
    time.sleep(1)
    check("canvas: own font available in editor", cv.evaluate("async () => (await document.fonts.load('16px \"Wpd Test\"')).length") >= 1)
    check("canvas: partners block items (3 incl. hidden)", cv.locator(".wpd-page--partners .wpd-partners__item").count() == 3)
    check("canvas: hidden partner marked", cv.locator(".wpd-page--partners .wpd-partners__item", has_text="Скрыт на сайте").count() == 1)

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

    insp = page.locator(".block-editor-block-inspector")
    pid_ = block_id("wpd/partners")
    select_block(pid_)
    sel = insp.locator("select").first
    check("inspector partners: layout select (strip)", sel.count() == 1 and sel.input_value() == "strip", sel.input_value() if sel.count() else "none")
    sel.select_option("grid")
    time.sleep(0.8)
    check("partners -> grid attribute and canvas", attr(pid_, "layout") == "grid" and cv.locator(".wpd-page--partners .wpd-partners--grid").count() == 1)
    insp.get_by_label("Подписи под логотипами").first.check()
    time.sleep(0.6)
    check("partners: names toggle -> canvas class", cv.locator(".wpd-page--partners .wpd-partners--names").count() == 1 and attr(pid_, "showNames") is True)
    check("partners: add button visible when selected", cv.locator(".wpd-page--partners button", has_text="Добавить партнёра").count() == 1)
    page.screenshot(path=os.path.join(SHOTS, "w-editor-partners.png"))

    sid = block_id("wpd/stats")
    select_block(sid)
    fp = insp.locator(".wpd-ed-fonts")
    check("inspector stats: fonts panel with count", fp.count() == 1 and "Шрифты (2)" in fp.text_content(), fp.text_content() if fp.count() else "none")
    toggle = fp.locator("button.components-panel__body-toggle")
    if toggle.get_attribute("aria-expanded") == "false":
        toggle.click()
        time.sleep(0.5)
    labels = fp.locator("label").all_text_contents()
    check("fonts panel: text, headings, numbers", [l.lower() for l in labels][:3] == ["основной шрифт", "шрифт заголовков", "шрифт цифр"], labels)
    opts = fp.locator("datalist option").evaluate_all("els => els.map(e => e.value)")
    check("fonts panel: datalist suggests own font", '"Wpd Test", sans-serif' in opts and any("Georgia" in o for o in opts), opts[:8])
    inp = fp.locator("input").first
    inp.fill("Courier New, monospace")
    time.sleep(1)
    st = cv.evaluate("() => document.querySelector('.wpd-page--stats').getAttribute('style') || ''").replace(": ", ":")
    check("fonts panel -> canvas var", attr(sid, "fontText") == "Courier New, monospace" and "--wpd-font:Courier New, monospace" in st, st)
    num_font = cv.evaluate("() => getComputedStyle(document.querySelector('.wpd-page--stats .wpd-stat__num')).fontFamily")
    check("canvas: numbers font from block attribute", num_font.startswith('"Wpd Test"'), num_font)
    inp.fill("Arial;} x{")
    time.sleep(0.8)
    st = cv.evaluate("() => document.querySelector('.wpd-page--stats').getAttribute('style') || ''").replace(": ", ":")
    check("canvas: unsafe font characters dropped", "--wpd-font:Arial x" in st and "}" not in st, st)
    inp.fill("Courier New, monospace")
    time.sleep(0.5)

    page.evaluate("() => wp.data.dispatch('core/block-editor').insertBlocks(wp.blocks.createBlock('wpd/hero'), 0)")
    time.sleep(2)
    cv.wait_for_selector(".wpd-page--hero .wpd-hero__logo-img", timeout=30000)
    hb = cv.evaluate("""() => { const h = document.querySelector('.wpd-page--hero .wpd-hero');
        const l = h.querySelector('.wpd-hero__logo-img').getBoundingClientRect(), t = h.querySelector('.wpd-hero__title').getBoundingClientRect();
        return { cls: h.className, beside: l.right <= t.left, src: h.querySelector('.wpd-hero__logo-img').getAttribute('src') }; }""")
    check("canvas hero: logo beside title from settings", "wpd-hero--logo-beside" in hb["cls"] and hb["beside"] and hb["src"].endswith("hero19.svg"), hb)
    hid = block_id("wpd/hero")
    select_block(hid)
    insp.get_by_role("button", name="Логотип", exact=True).first.click()
    time.sleep(0.6)
    pos = insp.locator("select").filter(has=page.locator("option[value='above']")).first
    pos.select_option("above")
    time.sleep(0.8)
    check("hero logo position -> above", attr(hid, "logoPos") == "above" and cv.locator(".wpd-page--hero .wpd-hero--logo-above").count() == 1)
    page.screenshot(path=os.path.join(SHOTS, "w-editor-hero.png"))

    save(page)
    page.goto(URL, timeout=180000)
    fr = page.evaluate("""() => ({
        stats: (document.querySelector('.wpd-page--stats') || {}).getAttribute ? document.querySelector('.wpd-page--stats').getAttribute('style') : '',
        grid: !!document.querySelector('.wpd-page--partners .wpd-partners--grid.wpd-partners--names'),
        hero: !!document.querySelector('.wpd-page--hero .wpd-hero--logo-above .wpd-hero__logo-img'),
    })""")
    check("front after save: block font, partners grid with names, hero logo above", "--wpd-font:Courier New, monospace" in fr["stats"] and fr["grid"] and fr["hero"], fr)

    # ---------- 3. Настройки ----------
    page.goto(f"{BASE}/wp-admin/admin.php?page=wpd-settings&tab=design", timeout=180000)
    rows = "() => document.querySelectorAll('[name^=\"wpd_settings[font_files]\"][name$=\"[family]\"]:not([name*=\"__i__\"])').length"
    n0 = page.evaluate(rows)
    page.get_by_role("button", name="Добавить файл шрифта").first.click()
    time.sleep(0.5)
    check("settings: add font file row", page.evaluate(rows) == n0 + 1, (n0, page.evaluate(rows)))
    dl = page.locator("#wpd-font-list option").evaluate_all("els => els.map(e => e.value)")
    check("settings: datalist with own font", '"Wpd Test", sans-serif' in dl, dl[:6])
    page.screenshot(path=os.path.join(SHOTS, "w-settings-design.png"), full_page=True)
    page.goto(f"{BASE}/wp-admin/admin.php?page=wpd-settings&tab=partners", timeout=180000)
    check("settings partners: 3 rows", page.locator('[name$="[name]"][name^="wpd_settings[partners]"]:not([name*="__i__"])').count() == 3)
    page.screenshot(path=os.path.join(SHOTS, "w-settings-partners.png"), full_page=True)

    check("no JavaScript errors", not js_errors, js_errors[:3])
    browser.close()

fetch(BASE + "/wpd-w-helper.php?cleanup=1")
set_opts({})
report()
