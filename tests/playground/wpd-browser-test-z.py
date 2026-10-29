"""
Фаза Z (2.12.0): кнопка таймера в настоящем браузере (Playwright + Chromium).
Сайт: вычисленные стили кнопки (контур, цвет текста, скругление «таблетка», высота, иконка слева и её размер, по центру,
подпись под кнопкой, цвет при наведении), кнопка «после окончания» на всю ширину с мягкой заливкой, текстовая ссылка,
кнопка таймера в тёмном главном блоке (белый контур), смена кнопок этапов на лету (до начала → этап → после окончания),
телефон. Редактор: классы холста как на сайте, панели «Кнопки до начала и после окончания», «Кнопка таймера: вид
и цвета», «Кнопка таймера: иконка и поведение», смена вида кнопки, панель таймера главного блока, сохранение.
Настройки и панель «Блоки олимпиады»: поля кнопки.

Запуск: WPD_BASE=http://127.0.0.1:9400 python3 wpd-browser-test-z.py <папка_для_скриншотов>
Нужны помощники wpd-z-helper.php и wpd-set.php в корне WordPress.
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
    time.sleep(2)


def report():
    print("\n".join(results))
    fails = sum(1 for r in results if r.startswith("FAIL"))
    print(f"\nPHASE Z (browser): PASS={len(results) - fails} FAIL={fails}")


# Вычисленные стили кнопок трёх таймеров и главного блока
MEASURE = """() => {
    const box = (sel) => document.querySelector(sel);
    const r = (e) => e ? e.getBoundingClientRect() : null;
    const visLink = (root) => root ? [...root.querySelectorAll('.wpd-countdown__link')].find(a => !a.hidden && getComputedStyle(a).display !== 'none') : null;
    const info = (sel) => {
        const root = box(sel + ' .wpd-countdown');
        const a = visLink(root);
        if (!a) return null;
        const cs = getComputedStyle(a);
        const ic = a.querySelector('.wpd-countdown__icon');
        const note = root.querySelector('.wpd-countdown__note');
        const ra = r(a), rr = r(root), rm = r(root.querySelector('.wpd-countdown__main'));
        const text = [...a.childNodes].find(n => n.nodeType === 3 && n.textContent.trim());
        let textX = null;
        if (text) { const rg = document.createRange(); rg.selectNodeContents(text); textX = rg.getBoundingClientRect().left; }
        return {
            text: a.textContent.trim(), color: cs.color, bg: cs.backgroundColor, border: cs.borderTopWidth + ' ' + cs.borderTopStyle + ' ' + cs.borderTopColor,
            radius: cs.borderTopLeftRadius, h: Math.round(ra.height), w: Math.round(ra.width), deco: cs.textDecorationLine,
            iconSize: ic ? getComputedStyle(ic).fontSize : '', iconX: ic ? r(ic).left : null, textX: textX,
            centerOff: Math.round((ra.left + ra.width / 2) - (rr.left + rr.width / 2)),
            innerW: Math.round(root.clientWidth - parseFloat(getComputedStyle(root).paddingLeft) - parseFloat(getComputedStyle(root).paddingRight)),
            below: ra.top >= rm.bottom - 1,
            note: note && !note.hidden ? { text: note.textContent, top: r(note).top, btnBottom: ra.bottom, centerOff: Math.round((r(note).left + r(note).width / 2) - (rr.left + rr.width / 2)) } : null,
        };
    };
    return { active: info('.z-active'), before: info('.z-before'), done: info('.z-done'), hero: info('.wpd-hero__countdown'),
             scroll: document.documentElement.scrollWidth - window.innerWidth };
}"""

PHASES = """(sel) => [...document.querySelectorAll(sel + ' .wpd-countdown__link')].map(a => (a.getAttribute('data-when') || 'stage') + (a.hidden ? '-' : '+')).join(' ')"""

set_opts({})
info = json.loads(fetch(BASE + "/wpd-z-helper.php?setup=1"))
PID = info["id"]
URL = info["url"]
PANEL_ID = info["pid"]

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
    a = m["active"] or {}
    check("active: outline in own text colour", a.get("color") == "rgb(185, 28, 28)" and a.get("border") == "2px solid rgb(185, 28, 28)" and a.get("bg") in ("rgba(0, 0, 0, 0)", "transparent"), a)
    check("active: pill radius and 52 px height", a.get("radius") == "999px" and a.get("h") == 52, (a.get("radius"), a.get("h")))
    check("active: 22 px icon left of text", a.get("iconSize") == "22px" and a.get("iconX") is not None and a.get("textX") is not None and a["iconX"] < a["textX"], a)
    check("active: centred under timer", a.get("below") is True and abs(a.get("centerOff", 99)) <= 2, (a.get("below"), a.get("centerOff")))
    n = a.get("note") or {}
    check("active: note under button, centred", n.get("text") == "Вход по логину Z" and n.get("top", 0) >= n.get("btnBottom", 1) and abs(n.get("centerOff", 99)) <= 2, n)
    page.hover(".z-active .wpd-countdown__link:not([hidden])")
    time.sleep(0.4)
    hov = page.evaluate("() => getComputedStyle(document.querySelector('.z-active .wpd-countdown__link:not([hidden])')).backgroundColor")
    check("active: hover background", hov == "rgb(253, 230, 138)", hov)
    page.mouse.move(0, 0)
    b = m["before"] or {}
    check("before: text link", b.get("text") == "Регистрация Z" and b.get("deco") == "underline" and b.get("bg") in ("rgba(0, 0, 0, 0)", "transparent") and b.get("h", 99) < 40, b)
    d = m["done"] or {}
    check("done: after button full width", d.get("text", "").startswith("Результаты Z") and abs(d.get("w", 0) - d.get("innerW", -9)) <= 2, (d.get("w"), d.get("innerW")))
    check("done: soft fill of own colour", d.get("bg", "").replace(" ", "") in ("color(srgb0.0862750.6392160.290196/0.14)",) or ("0.14" in d.get("bg", "") and ("22, 163, 74" in d.get("bg", "") or "0.0862" in d.get("bg", ""))), d.get("bg"))
    h = m["hero"] or {}
    check("hero: white outline on dark hero", h.get("text", "").startswith("Герой Z") and h.get("color") == "rgb(255, 255, 255)" and h.get("border", "").startswith("2px solid"), h)
    page.screenshot(path=os.path.join(SHOTS, "z-front.png"), full_page=True)

    # Смена этапов на лету: сдвигаем часы страницы вперёд
    clock = ctx.new_page()
    clock.add_init_script("""(() => { const real = Date.now.bind(Date); window.__wpdShift = 0; Date.now = () => real() + window.__wpdShift; })();""")
    clock.goto(URL, timeout=180000)
    clock.wait_for_load_state("networkidle", timeout=120000)
    check("live: before phase", clock.evaluate(PHASES, ".z-before") == "before+ stage-", clock.evaluate(PHASES, ".z-before"))
    clock.evaluate("() => { window.__wpdShift = 3 * 3600 * 1000; }")
    clock.wait_for_function("() => !document.querySelector('.z-before .wpd-countdown__link--before').hidden === false", timeout=5000)
    time.sleep(0.3)
    ph = clock.evaluate(PHASES, ".z-before")
    check("live: stage button replaces before button", ph == "before- stage+", ph)
    clock.evaluate("() => { window.__wpdShift = 2 * 86400 * 1000; }")
    try:
        clock.wait_for_function("() => document.querySelector('.z-active .wpd-countdown__link--after') && !document.querySelector('.z-active .wpd-countdown__link--after').hidden", timeout=5000)
    except Exception:
        pass
    ph = clock.evaluate(PHASES, ".z-active")
    acts = clock.evaluate("() => [document.querySelector('.z-before .wpd-countdown__actions').hidden, !document.querySelector('.z-active .wpd-countdown__note').hidden]")
    check("live: after end — after button, wrapper hidden without buttons, note stays", ph == "before- stage- after+" and acts == [True, True], (ph, acts))
    clock.close()

    mob = browser.new_context(viewport={"width": 390, "height": 800}, locale="ru-RU")
    mp = mob.new_page()
    mp.goto(URL, timeout=180000)
    mm = mp.evaluate(MEASURE)
    check("mobile: no horizontal scroll", mm["scroll"] <= 1, mm["scroll"])
    check("mobile: buttons under timer", (mm["active"] or {}).get("below") is True and (mm["before"] or {}).get("below") is True)
    mp.screenshot(path=os.path.join(SHOTS, "z-front-mobile.png"), full_page=True)
    mob.close()

    front_cls = page.evaluate("() => document.querySelector('.z-active .wpd-countdown').className")

    # ---------- 2. Редактор ----------
    page.goto(BASE + "/wp-login.php", timeout=120000)
    page.fill("#user_login", "admin")
    page.fill("#user_pass", "password")
    page.click("#wp-submit")
    page.wait_for_load_state("networkidle", timeout=120000)
    page.goto(f"{BASE}/wp-admin/post.php?post={PID}&action=edit", timeout=180000)
    wait_editor(page)
    cv = canvas(page)
    cv.wait_for_selector(".z-active .wpd-countdown", timeout=60000)
    time.sleep(1.5)

    own = lambda cls: sorted(c for c in cls.split() if c.startswith("wpd-cdb"))
    ed_cls = cv.evaluate("() => document.querySelector('.z-active .wpd-countdown').className")
    check("canvas: button classes as on site", own(ed_cls) == own(front_cls) and own(front_cls), (own(ed_cls), own(front_cls)))
    st = cv.evaluate("() => (document.querySelector('.z-active .wpd-countdown').getAttribute('style') || '').replace(/:\\s+/g, ':')")
    check("canvas: button vars", "--wpd-cdb-h:52px" in st and "--wpd-cdb-fg:#b91c1c" in st and "--wpd-cdb-icon:22px" in st, st)
    cb = cv.evaluate("""() => { const a = document.querySelector('.z-active .wpd-countdown__link'); const cs = getComputedStyle(a);
        return { color: cs.color, radius: cs.borderTopLeftRadius, h: Math.round(a.getBoundingClientRect().height), icon: !!a.querySelector('.dashicons-download'),
                 note: (document.querySelector('.z-active .wpd-countdown__note') || {}).textContent || '', hint: (document.querySelector('.z-active .wpd-ed-cd-phases') || {}).textContent || '' }; }""")
    check("canvas: computed button as on site", cb["color"] == "rgb(185, 28, 28)" and cb["radius"] == "999px" and cb["h"] == 52 and cb["icon"], cb)
    check("canvas: note and phase buttons hint", cb["note"] == "Вход по логину Z" and "до начала" in cb["hint"] and "после окончания" in cb["hint"], cb)
    hero_btn = cv.evaluate("""() => { const r = document.querySelector('.wpd-hero__countdown .wpd-countdown'); const a = r && r.querySelector('.wpd-countdown__link');
        return r ? [r.className, a ? a.textContent : ''] : null; }""")
    check("canvas hero: timer button with block look", hero_btn and "wpd-cdb--outline" in hero_btn[0] and "Герой Z" in hero_btn[1], hero_btn)

    def block_id(pred):
        return page.evaluate("(p) => (wp.data.select('core/block-editor').getBlocks().find(b => new Function('b', 'return ' + p)(b)) || {}).clientId", pred)

    def select_block(cid):
        page.evaluate("(id) => wp.data.dispatch('core/block-editor').selectBlock(id)", cid)
        page.evaluate("""() => { try { wp.data.dispatch('core/edit-post').openGeneralSidebar('edit-post/block'); } catch (e) {} }""")
        page.wait_for_selector(".block-editor-block-inspector", timeout=30000)
        time.sleep(0.8)

    def attr(cid, name):
        return page.evaluate("([id, n]) => wp.data.select('core/block-editor').getBlockAttributes(id)[n]", [cid, name])

    insp = page.locator(".block-editor-block-inspector")

    def open_panel(title):
        btn = insp.get_by_role("button", name=title, exact=True).first
        if btn.count() and btn.get_attribute("aria-expanded") == "false":
            btn.click()
            time.sleep(0.6)
        return btn.count() > 0

    aid = block_id("b.name === 'wpd/countdown' && b.attributes.className === 'z-active'")
    select_block(aid)
    check("inspector: phase buttons panel", open_panel("Кнопки до начала и после окончания") and insp.locator(".wpd-ed-look-cdBeforeUrl").count() == 1 and insp.locator(".wpd-ed-look-cdAfterText").count() == 1)
    check("inspector: icon and behaviour panel", open_panel("Кнопка таймера: иконка и поведение") and insp.locator(".wpd-ed-look-cdBtnIcon select").count() == 1
          and insp.locator(".wpd-ed-look-cdBtnNote").count() == 1 and insp.locator(".wpd-ed-look-cdBtnDownload").count() == 1)
    check("inspector: look and colours panel", open_panel("Кнопка таймера: вид и цвета") and insp.locator(".wpd-ed-look-cdBtnStyle select").count() == 1
          and insp.locator(".wpd-ed-look-cdBtnHoverColor").count() == 1 and insp.locator(".wpd-ed-look-cdBtnHeight").count() == 1)
    insp.locator(".wpd-ed-look-cdBtnStyle select").select_option("link")
    time.sleep(0.8)
    ed_cls = cv.evaluate("() => document.querySelector('.z-active .wpd-countdown').className")
    check("style select -> attribute and canvas", attr(aid, "cdBtnStyle") == "link" and "wpd-cdb--link" in ed_cls.split() and "wpd-cdb--outline" not in ed_cls.split(), ed_cls)
    page.screenshot(path=os.path.join(SHOTS, "z-editor.png"))

    hid = block_id("b.name === 'wpd/hero'")
    select_block(hid)
    check("inspector hero: timer button panels", open_panel("Кнопка таймера: этапы") and insp.locator(".wpd-ed-look-cdLinkUrl").count() == 1
          and insp.get_by_role("button", name="Кнопка таймера: вид и цвета", exact=True).count() == 1)
    insp.locator(".wpd-ed-look-cdLinkText input").fill("Герой Z2")
    time.sleep(0.8)
    check("hero: stage button text -> canvas", attr(hid, "cdLinkText") == "Герой Z2" and "Герой Z2" in cv.evaluate("() => document.querySelector('.wpd-hero__countdown .wpd-countdown__link').textContent"))

    save(page)
    page.goto(URL, timeout=180000)
    fr = page.evaluate("() => [document.querySelector('.z-active .wpd-countdown').className, document.querySelector('.wpd-hero__countdown .wpd-countdown__link').textContent]")
    check("front after save", "wpd-cdb--link" in fr[0].split() and "Герой Z2" in fr[1], fr)

    # ---------- 3. Панель страницы и настройки ----------
    page.goto(f"{BASE}/wp-admin/post.php?post={PANEL_ID}&action=edit", timeout=180000)
    wait_editor(page)
    f = page.locator('[name="wpd_page[ov][countdown_btn_style]"]')
    check("page panel: button style field with saved value", f.count() == 1 and f.input_value() == "link", f.input_value() if f.count() else "none")
    page.goto(f"{BASE}/wp-admin/admin.php?page=wpd-settings&tab=features", timeout=180000)
    check("settings: button section fields", page.locator('[name="wpd_settings[countdown_btn_pos]"]').count() == 1 and page.locator('[name="wpd_settings[countdown_after_url]"]').count() == 1
          and page.get_by_text("Кнопка таймера: этапы, вид и поведение").count() >= 1)
    page.screenshot(path=os.path.join(SHOTS, "z-settings.png"), full_page=True)

    check("no JavaScript errors", not js_errors, js_errors[:3])
    browser.close()

fetch(BASE + "/wpd-z-helper.php?cleanup=1")
set_opts({})
report()
