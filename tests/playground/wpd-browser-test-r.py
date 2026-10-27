"""
Фаза R (2.4.0): своё оформление страницы в настоящем браузере (Playwright + Chromium).
- боковая колонка «Оформление олимпиады» в блочном редакторе: включение своего оформления, палитра, цвет, стиль,
  связь с полями панели «Блоки олимпиады», отметка «изменено», предпросмотр на холсте, сохранение и вывод на сайте;
- панель: группы «Своё для этой страницы», вид блока (кнопка с кисточкой), свой CSS;
- инструменты: экспорт, копирование с другой страницы, импорт, сброс (с перезагрузкой);
- классический вид панели (группы раскрываются без перезагрузки), отсутствие ошибок JavaScript.

Запуск: WPD_BASE=http://127.0.0.1:9400 python3 wpd-browser-test-r.py <папка_для_скриншотов>
Нужен помощник wpd-r-page.php в корне WordPress.
"""
import json
import os
import sys
import time
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
        page.screenshot(path=os.path.join(SHOTS, "r-editor-timeout.png"))
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
    print(f"\nPHASE R (browser): PASS={len(results) - fails} FAIL={fails}")


info = json.loads(fetch(BASE + "/wpd-r-page.php?reset=1"))
PID = info["id"]
SRC = info["src"]
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

    page.goto(BASE + "/wp-login.php", timeout=120000)
    page.fill("#user_login", "admin")
    page.fill("#user_pass", "password")
    page.click("#wp-submit")
    page.wait_for_load_state("networkidle", timeout=120000)

    # ---------- 1. Боковая колонка ----------
    page.goto(f"{BASE}/wp-admin/post.php?post={PID}&action=edit", timeout=180000)
    wait_editor(page)
    open_document_sidebar(page)
    side = page.locator(".wpd-side")
    check("sidebar: panel rendered", side.count() == 1)
    check("sidebar: enable toggle on (design enabled)", side.get_by_label("Оформление в стиле олимпиады").is_checked())
    own = side.get_by_label("Своё оформление для этой страницы")
    check("sidebar: own design off by default", own.count() == 1 and not own.is_checked())
    check("sidebar: no palettes while off", side.locator(".wpd-side__palette").count() == 0)
    check("panel: page group body hidden", page.locator('[data-wpd-ovg-body="page"]').get_attribute("hidden") is not None)
    page.screenshot(path=os.path.join(SHOTS, "r-sidebar-off.png"))

    own.click()
    time.sleep(0.8)
    check("sidebar -> panel: group checkbox checked", page.locator('input[name="wpd_page[ovg][page]"]').is_checked())
    check("panel: page group body shown", page.locator('[data-wpd-ovg-body="page"]').get_attribute("hidden") is None)
    check("sidebar: 8 palettes", side.locator(".wpd-side__palette").count() == 8, side.locator(".wpd-side__palette").count())
    check("editor marked dirty", page.evaluate("() => wp.data.select('core/editor').isEditedPostDirty()"))

    side.locator(".wpd-side__palette", has_text="Лес").click()
    time.sleep(0.8)
    check("sidebar -> panel: palette radio forest", page.locator('input[name="wpd_page[ov][palette]"][value="forest"]').is_checked())
    check("palette fills panel color", page.input_value("#wpd_pe_ov_primary").lower() == "#15803d", page.input_value("#wpd_pe_ov_primary"))
    check("sidebar palette selected", "is-selected" in (side.locator(".wpd-side__palette", has_text="Лес").get_attribute("class") or ""))

    # Предпросмотр на холсте
    cv = canvas(page)
    try:
        cv.wait_for_function("() => [...document.querySelectorAll('.wpd-page')].some(e => (e.getAttribute('style') || '').includes('#15803d'))", timeout=30000)
        ok = True
    except Exception:
        ok = False
    check("canvas preview: primary color applied", ok)
    check("canvas preview: all wpd-page wrappers updated",
          cv.evaluate("() => { const n = [...document.querySelectorAll('.wpd-page')]; return n.length > 0 && n.every(e => (e.getAttribute('style') || '').includes('--wpd-primary:#15803d')); }"))

    # Цвет вручную — палитра «Свои цвета»
    # React-поле color: значение только через нативный setter (обычное i.value = … обновляет
    # внутренний трекер React, и событие input потом не считается изменением)
    page.evaluate("""() => {
        const i = document.querySelector('.wpd-side input[type=color]');
        const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set;
        setter.call(i, '#aa3300');
        i.dispatchEvent(new Event('input', { bubbles: true }));
    }""")
    time.sleep(0.8)
    check("sidebar color -> panel primary", page.input_value("#wpd_pe_ov_primary").lower() == "#aa3300", page.input_value("#wpd_pe_ov_primary"))
    check("manual color -> custom palette", page.locator('input[name="wpd_page[ov][palette]"][value="custom"]').is_checked())

    # Стиль оформления «Мягкий градиент»: палитра и стиль главного блока
    side.get_by_label("Стиль оформления").select_option("soft")
    time.sleep(0.8)
    check("design soft -> palette soft", page.locator('input[name="wpd_page[ov][palette]"][value="soft"]').is_checked())
    check("design soft -> hero style soft", page.input_value("#wpd_pe_style") == "soft")
    check("sidebar hero style follows", side.get_by_label("Стиль главного блока").input_value() == "soft")
    try:
        cv.wait_for_function("() => [...document.querySelectorAll('.wpd-page')].some(e => e.classList.contains('wpd-style-soft'))", timeout=30000)
        ok = True
    except Exception:
        ok = False
    check("canvas preview: wpd-style-soft class", ok)

    # Радиус ползунком
    rng = side.locator(".components-range-control input[type=number]").first
    if rng.count():
        rng.fill("30")
        rng.press("Tab")
        time.sleep(0.5)
    check("sidebar range -> panel radius", page.input_value("#wpd_pe_ov_radius") == "30", page.input_value("#wpd_pe_ov_radius"))
    page.screenshot(path=os.path.join(SHOTS, "r-sidebar-on.png"))

    # Вид блока «Статистика» и свой CSS
    li = page.locator('.wpd-pe__block[data-key="stats"]')
    li.locator("[data-wpd-bs-toggle]").click()
    time.sleep(0.3)
    check("block style row opens", li.locator("[data-wpd-bs]").is_visible())
    li.locator('input[name="wpd_page[bstyle][stats][mt]"]').fill("64")
    li.locator('select[name="wpd_page[bstyle][stats][width]"]').select_option("narrow")
    check("block marked has-bstyle", "has-bstyle" in (li.get_attribute("class") or ""))
    reveal(page, "#wpd_pe_page_css")
    page.fill("#wpd_pe_page_css", ".wpd-page .wpd-stats { outline: 1px dashed #aa3300; }")
    # Группа «Контакты»: свой телефон
    reveal(page, 'input[name="wpd_page[ovg][contacts]"]')
    page.locator('input[name="wpd_page[ovg][contacts]"]').check()
    page.fill("#wpd_pe_ov_contact_phone", "+7 391 000-00-00")
    li.scroll_into_view_if_needed()
    page.screenshot(path=os.path.join(SHOTS, "r-panel.png"))

    save(page)
    html = fetch(URL)
    check("front: soft style class", "wpd-style-soft" in html)
    check("front: radius 30px", "--wpd-radius:30px" in html)
    check("front: soft palette primary", "--wpd-primary:#2235f8" in html)
    check("front: body soft class", "wpd-soft-page" in html)
    check("front: stats block style", "wpd-b--mt" in html and "--wpd-b-mt:64px" in html and "wpd-b--w-narrow" in html)
    check("front: page css inline", "outline: 1px dashed #aa3300" in html)
    check("front: contacts phone override", "+7 391 000-00-00" in html)
    check("front: hero soft", "wpd-hero--soft" in html)

    # После перезагрузки редактора значения на месте
    page.goto(f"{BASE}/wp-admin/post.php?post={PID}&action=edit", timeout=180000)
    wait_editor(page)
    check("reload: page group on", page.locator('input[name="wpd_page[ovg][page]"]').is_checked())
    check("reload: radius kept", page.input_value("#wpd_pe_ov_radius") == "30")
    check("reload: block style kept", page.input_value('input[name="wpd_page[bstyle][stats][mt]"]') == "64")
    check("reload: canvas uses page vars (server data)",
          canvas(page).evaluate("() => [...document.querySelectorAll('.wpd-page')].some(e => (e.getAttribute('style') || '').includes('--wpd-radius:30px') || (e.getAttribute('style') || '').includes('--wpd-radius: 30px'))"))

    # ---------- 2. Инструменты ----------
    reveal(page, "[data-wpd-export]")
    page.click("[data-wpd-export]")
    page.wait_for_selector("[data-wpd-export-out]:not([hidden])", timeout=30000)
    exported = page.input_value("[data-wpd-export-out]")
    try:
        exp = json.loads(exported)
    except Exception:
        exp = {}
    check("export: JSON with plugin and data", exp.get("plugin") == "wp-olympiad-design" and "ov" in exp.get("data", {}))
    check("export: page group saved", exp.get("data", {}).get("ov", {}).get("page", {}).get("radius") == 30, exp.get("data", {}).get("ov", {}).get("page", {}).get("radius"))
    check("export: copy & download buttons shown", page.locator("[data-wpd-export-copy]").is_visible() and page.locator("[data-wpd-export-download]").is_visible())
    page.screenshot(path=os.path.join(SHOTS, "r-tools.png"))

    # Копирование с источника (перезагрузка)
    page.select_option("[data-wpd-copy-from]", str(SRC))
    with page.expect_navigation(timeout=180000):
        page.click("[data-wpd-copy-page]")
    wait_editor(page)
    check("copy: hero title from source", page.input_value('input[name="wpd_page[hero][title]"]') == "Заголовок источника")
    check("copy: cherry palette", page.locator('input[name="wpd_page[ov][palette]"][value="cherry"]').is_checked())
    check("copy: faq hidden on mobile", page.input_value('select[name="wpd_page[bstyle][faq][hide]"]') == "mobile")
    check("copy: contacts group replaced", not page.locator('input[name="wpd_page[ovg][contacts]"]').is_checked())
    html = fetch(URL)
    check("copy: front cherry primary", "--wpd-primary:#be123c" in html)

    # Импорт ранее экспортированного (перезагрузка)
    reveal(page, "[data-wpd-export]")
    page.fill("[data-wpd-import-in]", exported)
    with page.expect_navigation(timeout=180000):
        page.click("[data-wpd-import]")
    wait_editor(page)
    check("import: radius back", page.input_value("#wpd_pe_ov_radius") == "30")
    check("import: contacts phone back", page.input_value("#wpd_pe_ov_contact_phone") == "+7 391 000-00-00")
    check("import: page css back", "dashed #aa3300" in page.input_value("#wpd_pe_page_css"))

    # Неверный импорт — сообщение без перезагрузки
    reveal(page, "[data-wpd-export]")
    page.fill("[data-wpd-import-in]", "{\"foo\": 1}")
    page.click("[data-wpd-import]")
    page.wait_for_function("() => (document.querySelector('[data-wpd-tools-msg]').textContent || '').length > 3", timeout=30000)
    check("import: invalid JSON message", "is-error" in (page.locator("[data-wpd-tools-msg]").get_attribute("class") or ""), page.locator("[data-wpd-tools-msg]").text_content())

    # Сброс (перезагрузка)
    with page.expect_navigation(timeout=180000):
        page.click("[data-wpd-reset-page]")
    wait_editor(page)
    check("reset: page group off", not page.locator('input[name="wpd_page[ovg][page]"]').is_checked())
    check("reset: design still enabled", page.locator('input[name="wpd_enabled"]').is_checked())
    html = fetch(URL)
    check("reset: front back to global primary", "--wpd-primary:#1d4ed8" in html and "wpd-style-soft" not in html)

    # ---------- 3. Группы панели раскрываются без перезагрузки ----------
    page.goto(f"{BASE}/wp-admin/post.php?post={PID}&action=edit", timeout=180000)
    wait_editor(page)
    pe = page.locator("[data-wpd-pe]")
    if pe.count():
        reveal(page, 'input[name="wpd_page[ovg][hero]"]')
        pe.locator('input[name="wpd_page[ovg][hero]"]').check()
        time.sleep(0.3)
        check("panel: hero group body toggles", pe.locator('[data-wpd-ovg-body="hero"]').get_attribute("hidden") is None)
        check("panel: hero group badge", pe.locator('[data-wpd-ovg-panel="hero"] [data-wpd-ovg-badge]').is_visible())
        pe.locator('input[name="wpd_page[ovg][hero]"]').uncheck()
        time.sleep(0.3)
        check("panel: hero group body hides", pe.locator('[data-wpd-ovg-body="hero"]').get_attribute("hidden") is not None)
    else:
        check("panel on edit screen", False)

    check("no JavaScript errors", not js_errors, js_errors[:5])
    browser.close()

fetch(BASE + "/wpd-r-page.php?cleanup=1")
report()
