"""
Фаза S (2.5.0): размеры иконок в настоящем браузере (Playwright + Chromium) — вычисленные размеры иконок на сайте
(по умолчанию, из общих настроек, из атрибутов блоков, «Мягкий градиент»), поле «Размер иконок» в боковой панели блоков
и предпросмотр на холсте, размер для страницы из панели «Блоки олимпиады» вместе с размером блока, сохранение.

Запуск: WPD_BASE=http://127.0.0.1:9400 python3 wpd-browser-test-s.py <папка_для_скриншотов>
Нужны помощники wpd-s-page.php и wpd-set.php в корне WordPress.
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
        page.screenshot(path=os.path.join(SHOTS, "s-editor-timeout.png"))
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
    print(f"\nPHASE S (browser): PASS={len(results) - fails} FAIL={fails}")


def set_opts(d):
    fetch(BASE + "/wpd-set.php?json=" + urllib.parse.quote(json.dumps(d)))


MEASURE = """() => {
    const px = (el, prop) => el ? parseFloat(getComputedStyle(el)[prop]) : -1;
    const q = (sel) => [...document.querySelectorAll(sel)];
    return {
        qbtn: q('.wpd-page--buttons').map(w => px(w.querySelector('.wpd-qbtn__icon'), 'width')),
        qbtnFont: q('.wpd-page--buttons').map(w => px(w.querySelector('.wpd-qbtn__icon'), 'fontSize')),
        doc: px(document.querySelector('.wpd-doc__icon'), 'width'),
        docFont: px(document.querySelector('.wpd-doc__icon'), 'fontSize'),
        contacts: px(document.querySelector('.wpd-contacts__icon'), 'fontSize'),
        hero: px(document.querySelector('.wpd-btn__icon'), 'fontSize'),
    };
}"""


def near(a, b, eps=0.6):
    return abs(a - b) <= eps


set_opts({})
info = json.loads(fetch(BASE + "/wpd-s-page.php?reset=1"))
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

    # ---------- 1. Сайт: размеры по умолчанию и из атрибутов блоков ----------
    page.goto(URL, timeout=180000)
    m = page.evaluate(MEASURE)
    check("front default: buttons 44, block attribute 60", len(m["qbtn"]) == 2 and near(m["qbtn"][0], 44) and near(m["qbtn"][1], 60), m)
    check("front: tile icon glyph follows box", near(m["qbtnFont"][1], 60), m)
    check("front: docs badge 80 with label 80*0.27", near(m["doc"], 80) and near(m["docFont"], 21.6), m)
    check("front default: contacts 24", near(m["contacts"], 24), m)
    check("front: hero button icon 22 (attribute)", near(m["hero"], 22), m)
    page.screenshot(path=os.path.join(SHOTS, "s-front-default.png"), full_page=True)

    set_opts({"buttons_icon_size": 36, "contacts_icon_size": 30, "docs_icon_size": 30})
    page.goto(URL, timeout=180000)
    m = page.evaluate(MEASURE)
    check("front settings: buttons 36, attribute 60 stays", near(m["qbtn"][0], 36) and near(m["qbtn"][1], 60), m)
    check("front settings: contacts 30", near(m["contacts"], 30), m)
    check("front settings: docs attribute 80 beats settings 30", near(m["doc"], 80), m)

    set_opts({"design_style": "soft", "palette": "soft", "hero_style": "soft"})
    page.goto(URL, timeout=180000)
    m = page.evaluate(MEASURE)
    check("front soft style: default tile icon 32", near(m["qbtn"][0], 32) and near(m["qbtn"][1], 60), m)
    page.screenshot(path=os.path.join(SHOTS, "s-front-soft.png"), full_page=True)
    set_opts({"buttons_icon_size": 36, "contacts_icon_size": 30})

    page.goto(BASE + "/wp-login.php", timeout=120000)
    page.fill("#user_login", "admin")
    page.fill("#user_pass", "password")
    page.click("#wp-submit")
    page.wait_for_load_state("networkidle", timeout=120000)

    # ---------- 2. Редактор: холст и поле в боковой панели блока ----------
    page.goto(f"{BASE}/wp-admin/post.php?post={PID}&action=edit", timeout=180000)
    wait_editor(page)
    cv = canvas(page)
    cv.wait_for_selector(".wpd-page--buttons .wpd-qbtn__icon", timeout=60000)
    time.sleep(2)
    m = cv.evaluate(MEASURE)
    check("canvas: buttons 36 from settings, 60 from attribute", len(m["qbtn"]) == 2 and near(m["qbtn"][0], 36) and near(m["qbtn"][1], 60), m)
    check("canvas: docs 80, contacts 30, hero 22", near(m["doc"], 80) and near(m["contacts"], 30) and near(m["hero"], 22), m)

    ids = page.evaluate("() => wp.data.select('core/block-editor').getBlocks().filter(b => b.name === 'wpd/buttons').map(b => b.clientId)")
    check("editor: two buttons blocks", len(ids) == 2, ids)

    def select_block(cid):
        page.evaluate("(id) => wp.data.dispatch('core/block-editor').selectBlock(id)", cid)
        page.evaluate("""() => {
            try { wp.data.dispatch('core/edit-post').openGeneralSidebar('edit-post/block'); } catch (e) {}
            try { wp.data.dispatch('core/editor').openGeneralSidebar && wp.data.dispatch('core/editor').openGeneralSidebar('edit-post/block'); } catch (e) {}
        }""")
        page.wait_for_selector(".block-editor-block-inspector", timeout=30000)
        time.sleep(0.8)

    def size_input():
        return page.locator(".block-editor-block-inspector .wpd-ed-icon-size input[type=number]").first

    def attr(cid, name):
        return page.evaluate("([id, n]) => wp.data.select('core/block-editor').getBlockAttributes(id)[n]", [cid, name])

    select_block(ids[0])
    inp = size_input()
    check("inspector: size control for buttons", inp.count() == 1)
    help_txt = page.locator(".block-editor-block-inspector .wpd-ed-icon-size").inner_text()
    check("inspector: help shows current setting and bounds", "как в настройках (36 px)" in help_txt and "От 16 до 96" in help_txt, help_txt)
    inp.fill("70")
    time.sleep(1)
    check("inspector -> attribute 70", attr(ids[0], "iconSize") == 70, attr(ids[0], "iconSize"))
    m = cv.evaluate(MEASURE)
    check("canvas: block preview 70, other block 60", near(m["qbtn"][0], 70) and near(m["qbtn"][1], 60), m)
    page.screenshot(path=os.path.join(SHOTS, "s-editor-block-70.png"))
    inp.fill("0")
    time.sleep(1)
    m = cv.evaluate(MEASURE)
    check("inspector 0 -> back to settings 36", near(m["qbtn"][0], 36), m)

    for name, label in [("wpd/docs", "Размер значков, px"), ("wpd/contacts", "Размер иконок, px"), ("wpd/hero", "Размер иконок на кнопках, px")]:
        cid = page.evaluate("(n) => (wp.data.select('core/block-editor').getBlocks().find(b => b.name === n) || {}).clientId", name)
        select_block(cid)
        box = page.locator(".block-editor-block-inspector .wpd-ed-icon-size")
        if box.count() == 0:
            # Поле главного блока — в свёрнутой панели «Кнопки»
            page.locator(".block-editor-block-inspector").get_by_role("button", name="Кнопки", exact=True).first.click()
            time.sleep(0.6)
        check(f"inspector: {name} control «{label}»", box.count() == 1 and label.lower() in box.text_content().lower(), box.text_content() if box.count() else "none")
    cid = page.evaluate("() => wp.data.select('core/block-editor').getBlocks().find(b => b.name === 'wpd/contacts').clientId")
    select_block(cid)
    size_input().fill("44")
    time.sleep(1)
    check("canvas: contacts block 44", near(cv.evaluate(MEASURE)["contacts"], 44))

    # ---------- 3. Своё для страницы: предпросмотр с учётом размера блока ----------
    open_metaboxes(page)
    reveal(page, 'input[name="wpd_page[ovg][buttons]"]')
    grp = page.locator('input[name="wpd_page[ovg][buttons]"]')
    grp.dispatch_event("click")
    time.sleep(0.8)
    check("panel: buttons group on", grp.is_checked())
    reveal(page, "#wpd_pe_ov_buttons_icon_size")
    page.fill("#wpd_pe_ov_buttons_icon_size", "50")
    page.locator("#wpd_pe_ov_buttons_icon_size").dispatch_event("change")
    try:
        cv.wait_for_function("() => (document.querySelector('.wpd-page--buttons').getAttribute('style') || '').includes('--wpd-icon-qbtn:50px')", timeout=30000)
        ok = True
    except Exception:
        ok = False
    check("page preview reaches canvas", ok)
    time.sleep(1)
    m = cv.evaluate(MEASURE)
    check("canvas page preview: block without size 50, block 60 kept", near(m["qbtn"][0], 50) and near(m["qbtn"][1], 60), m)
    check("canvas page preview: contacts block 44 kept", near(m["contacts"], 44), m)
    select_block(ids[0])
    size_input().fill("64")
    time.sleep(1.5)
    m = cv.evaluate(MEASURE)
    check("canvas: block size beats page preview (64)", near(m["qbtn"][0], 64), m)
    page.screenshot(path=os.path.join(SHOTS, "s-editor-page-preview.png"))

    save(page)
    page.goto(URL, timeout=180000)
    m = page.evaluate(MEASURE)
    check("front after save: 64 and 60", near(m["qbtn"][0], 64) and near(m["qbtn"][1], 60), m)
    check("front after save: contacts 44", near(m["contacts"], 44), m)
    page.goto(f"{BASE}/wp-admin/post.php?post={PID}&action=edit", timeout=180000)
    wait_editor(page)
    check("after save: page size in panel", page.input_value("#wpd_pe_ov_buttons_icon_size") == "50")
    check("after save: attribute stored", page.evaluate("() => wp.data.select('core/block-editor').getBlocks().filter(b => b.name === 'wpd/buttons').map(b => b.attributes.iconSize).join(',')") == "64,60")
    m = canvas(page).evaluate(MEASURE)
    check("canvas after reload: 64 and 60", near(m["qbtn"][0], 64) and near(m["qbtn"][1], 60), m)

    # ---------- 4. Вкладка настроек ----------
    page.goto(f"{BASE}/wp-admin/admin.php?page=wpd-settings&tab=buttons", timeout=180000)
    f = page.locator('input[name="wpd_settings[buttons_icon_size]"]')
    check("settings tab: field shows 36", f.count() == 1 and f.input_value() == "36")
    page.screenshot(path=os.path.join(SHOTS, "s-settings-buttons.png"))

    check("no JavaScript errors", not js_errors, js_errors[:3])
    browser.close()

fetch(BASE + "/wpd-s-page.php?cleanup=1")
set_opts({})
report()
