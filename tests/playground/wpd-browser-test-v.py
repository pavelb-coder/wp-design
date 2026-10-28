"""
Фаза V (2.8.0): сетевая папка в настоящем браузере (Playwright + Chromium) — кнопки вкладки «Документы» («Проверить
подключение», «Показать файлы…» с таблицей и выбором новых, «Импортировать в общий список», перезагрузка со строками
из папки, журнал), раздел «Сетевая папка» блока «Олимпиада: документы» (импорт в атрибут items, холст, автообновление,
сохранение), «Импортировать сейчас» в панели «Блоки олимпиады» (строки списка заменяются, список сохранён).

Запуск: WPD_BASE=http://127.0.0.1:9400 python3 wpd-browser-test-v.py <папка_для_скриншотов>
Нужны помощники wpd-v-helper.php и wpd-set.php в корне WordPress.
"""
import atexit
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


def report():
    print("\n".join(results))
    fails = sum(1 for r in results if r.startswith("FAIL"))
    print(f"\nPHASE V (browser): PASS={len(results) - fails} FAIL={fails}")


atexit.register(report)

fetch(BASE + "/wpd-set.php?json=" + urllib.parse.quote("{}"))
fetch(BASE + "/wpd-v-helper.php?setup=1")
fetch(BASE + "/wpd-v-helper.php?config=1")
PID = json.loads(fetch(BASE + "/wpd-v-helper.php?page=1"))["id"]

with sync_playwright() as p:
    browser = p.chromium.launch(args=["--disable-dev-shm-usage"])
    ctx = browser.new_context(viewport={"width": 1440, "height": 1000}, locale="ru-RU", reduced_motion="reduce")
    page = ctx.new_page()
    js_errors = []
    page.on("pageerror", lambda e: js_errors.append(str(e)))
    page.on("console", lambda m: js_errors.append("console: " + m.text) if m.type == "error" and ("wpd" in m.text.lower() or "netfolder" in m.text.lower()) else None)
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
    page.add_locator_handler(page.locator(".components-modal__screen-overlay"), close_modal)

    page.goto(BASE + "/wp-login.php", timeout=120000)
    page.fill("#user_login", "admin")
    page.fill("#user_pass", "password")
    page.click("#wp-submit")
    page.wait_for_load_state("networkidle", timeout=120000)

    # ---------- 1. Вкладка «Документы» ----------
    page.goto(BASE + "/wp-admin/admin.php?page=wpd-settings&tab=docs", timeout=180000)
    box = page.locator("#wpd-nf")
    box.scroll_into_view_if_needed()
    check("tab: section visible, saved values in form", box.is_visible() and page.locator("#wpd_nf_sub").input_value() == "Документы"
          and page.locator("#wpd_nf_driver").input_value() == "mount")
    result = box.locator("[data-wpd-nf-result]")
    box.locator("[data-wpd-nf-test]").click()
    page.wait_for_selector("#wpd-nf [data-wpd-nf-result] .notice", timeout=60000)
    txt = result.inner_text()
    check("test button: success notice with counts", "Подключение работает" in txt and "подключённая папка" in txt and "файлов — 2" in txt
          and result.locator(".notice-success").count() == 1, txt)
    # Несохранённое поле формы учитывается
    page.fill("#wpd_nf_mount", "/nonexistent/folder")
    box.locator("[data-wpd-nf-test]").click()
    page.wait_for_function("() => document.querySelector('#wpd-nf [data-wpd-nf-result] .notice-error')", timeout=60000)
    check("test button: uses unsaved form value", result.locator(".notice-error").count() == 1, result.inner_text())
    page.reload(timeout=180000)
    box = page.locator("#wpd-nf")
    result = box.locator("[data-wpd-nf-result]")
    box.locator("[data-wpd-nf-scan]").click()
    page.wait_for_selector("#wpd-nf [data-wpd-nf-preview]:not([hidden]) tbody tr", timeout=60000)
    rows = box.locator("[data-wpd-nf-rows] tr")
    texts = [rows.nth(i).inner_text() for i in range(rows.count())]
    check("scan: table with two new files", rows.count() == 2 and all("новый" in t for t in texts), texts)
    check("scan: summary notice", "новых — 2" in result.inner_text(), result.inner_text())
    page.screenshot(path=os.path.join(SHOTS, "v-tab-preview.png"), full_page=True)
    # Снять отметку с «Регламента» и импортировать
    box.locator("input[data-rel='Документы/Регламент.docx']").uncheck()
    box.locator("[data-wpd-nf-import]").click()
    page.wait_for_function("() => /добавлено: 1/.test(document.querySelector('#wpd-nf [data-wpd-nf-result]').textContent)", timeout=120000)
    check("import: only checked file", "добавлено: 1." in result.inner_text(), result.inner_text())
    # Вкладка перезагружается сама; ждём новую страницу целиком (скрипт кнопок подключён в подвале)
    page.wait_for_function("() => document.readyState === 'complete' && document.querySelector('.wpd-rep__src') && window.wp && wp.apiFetch", timeout=120000)
    time.sleep(1)
    notes = page.locator(".wpd-rep__src").all_inner_texts()
    check("after reload: imported row with note", notes == ["Из сетевой папки: Документы/Положение об олимпиаде.pdf"], notes)
    check("after reload: title from file name", page.locator("input[value='Положение об олимпиаде']").count() >= 1)
    log = page.locator(".wpd-nf__log").text_content()  # журнал свёрнут в <details>: inner_text видит только заголовок
    check("after reload: log entry", "вручную, Общий список: добавлено: 1." in log, log[:200])
    page.locator("#wpd-nf [data-wpd-nf-scan]").click()
    page.wait_for_selector("#wpd-nf [data-wpd-nf-preview]:not([hidden]) tbody tr", timeout=60000)
    rows = page.locator("#wpd-nf [data-wpd-nf-rows] tr")
    texts = sorted(rows.nth(i).inner_text() for i in range(rows.count()))
    check("rescan: one in list, one new", len(texts) == 2 and any("уже в списке" in t for t in texts) and any("новый" in t for t in texts), texts)
    check("rescan: checkbox only for new", page.locator("#wpd-nf [data-wpd-nf-rows] input[data-rel]").count() == 1)

    # ---------- 2. Блок «Олимпиада: документы» ----------
    page.goto(f"{BASE}/wp-admin/post.php?post={PID}&action=edit", timeout=180000)
    page.wait_for_function("() => window.wp && wp.data && wp.data.select('core/editor') && wp.data.select('core/editor').getCurrentPostId()", timeout=150000)
    page.evaluate("""() => {
        try { wp.data.dispatch('core/preferences').set('core/edit-post', 'welcomeGuide', false); } catch (e) {}
        try { wp.data.dispatch('core/preferences').set('core', 'enableChoosePatternModal', false); } catch (e) {}
    }""")
    time.sleep(4)
    cid = page.evaluate("() => wp.data.select('core/block-editor').getBlocks().filter(b => b.name === 'wpd/docs').map(b => b.clientId)[0]")
    page.evaluate("(id) => wp.data.dispatch('core/block-editor').selectBlock(id)", cid)
    page.evaluate("""() => {
        try { wp.data.dispatch('core/edit-post').openGeneralSidebar('edit-post/block'); } catch (e) {}
    }""")
    page.wait_for_selector(".block-editor-block-inspector", timeout=30000)
    time.sleep(1)
    insp = page.locator(".block-editor-block-inspector")
    panel = insp.locator(".components-panel__body", has_text="Сетевая папка")
    check("block: panel «Сетевая папка» open (folder set)", panel.count() == 1 and "is-opened" in (panel.first.get_attribute("class") or ""))
    folder = panel.locator("input[type=text]").first
    check("block: folder field shows attribute", folder.input_value() == "Ссылки", folder.input_value())
    panel.get_by_role("button", name="Импортировать сейчас").dispatch_event("click")
    page.wait_for_selector(".wpd-ed-nf-msg", timeout=120000)
    msg = panel.locator(".wpd-ed-nf-msg").inner_text()
    items = page.evaluate("(id) => wp.data.select('core/block-editor').getBlockAttributes(id).items", cid)
    check("block import: message", "добавлено: 2." in msg, msg)
    # Блок без своих элементов начинает с общего списка (в нём строка из «Документов») — она не трогается
    check("block import: items with src", len(items) == 3 and {i.get("src") for i in items} == {"Документы/Положение об олимпиаде.pdf", "Ссылки/Приказ №1.pdf", "Ссылки/Форма <заявки>.docx"}
          and all("wpd_nf=" not in i["url"] for i in items), items)
    cv = page.frame(name="editor-canvas") or page
    cv.wait_for_selector(".wpd-docs .wpd-doc", timeout=30000)
    canvas_text = cv.locator(".wpd-docs").first.inner_text()
    check("block import: canvas shows documents", "Приказ №1" in canvas_text and "Форма <заявки>" in canvas_text, canvas_text[:200])
    check("block: source hint under selected items", cv.locator(".wpd-ed-src").count() == 3, cv.locator(".wpd-ed-src").count())
    panel.locator(".components-toggle-control input").first.dispatch_event("click")
    time.sleep(0.6)
    check("block: auto toggle -> attribute", page.evaluate("(id) => wp.data.select('core/block-editor').getBlockAttributes(id).nfAuto", cid) is True)
    page.screenshot(path=os.path.join(SHOTS, "v-block.png"))
    page.evaluate("() => wp.data.dispatch('core/editor').savePost()")
    page.wait_for_function("() => !wp.data.select('core/editor').isSavingPost() && !wp.data.select('core/editor').isAutosavingPost()", timeout=120000)
    try:
        page.wait_for_function("() => !wp.data.select('core/edit-post').isSavingMetaBoxes()", timeout=60000)
    except Exception:
        pass
    time.sleep(2)
    content = page.evaluate("() => wp.data.select('core/editor').getEditedPostContent()")
    check("saved content: nfAuto and src", '"nfAuto":true' in content and '"src":"Ссылки/Приказ №1.pdf"' in content, content[:300])

    # ---------- 3. Панель «Блоки олимпиады» ----------
    page.reload(timeout=180000)
    page.wait_for_function("() => window.wp && wp.data && wp.data.select('core/editor') && wp.data.select('core/editor').getCurrentPostId()", timeout=150000)
    page.wait_for_selector("[data-wpd-nf-page]", state="attached", timeout=60000)
    time.sleep(2)
    before = page.evaluate("() => document.querySelectorAll('[data-wpd-list=\"docs\"] .wpd-pe__row').length")
    page.evaluate("() => document.querySelector('[data-wpd-nf-page] [data-wpd-nf-auto]').checked = true")
    page.evaluate("() => document.querySelector('[data-wpd-nf-page] [data-wpd-nf-import]').click()")
    page.wait_for_function("() => /сохранён/.test(document.querySelector('[data-wpd-nf-page] [data-wpd-nf-msg]').textContent)", timeout=120000)
    pmsg = page.evaluate("() => document.querySelector('[data-wpd-nf-page] [data-wpd-nf-msg]').textContent")
    after = page.evaluate("() => [...document.querySelectorAll('[data-wpd-list=\"docs\"] .wpd-pe__row')].map(r => [r.querySelector('[data-wpd-field=title]').value, r.querySelector('[data-wpd-field=src]').value])")
    check("panel import: message", "добавлено: 2." in pmsg and "Список страницы уже сохранён" in pmsg, pmsg)
    check("panel import: rows replaced (manual kept + 2 from folder)", before == 1 and len(after) == 3 and after[0] == ["Ручной", ""]
          and {a[1] for a in after[1:]} == {"Документы/Положение об олимпиаде.pdf", "Документы/Регламент.docx"}, after)
    st = json.loads(fetch(BASE + "/wpd-v-helper.php?state=1"))
    check("panel import: saved with auto", st["page_nf"] == ["Документы", 1, "custom"] and len(st["page_docs"]) == 3, st["page_nf"])
    check("no JS errors", not js_errors, js_errors[:3])
    browser.close()

fetch(BASE + "/wpd-v-helper.php?cleanup=1")
fetch(BASE + "/wpd-set.php?json=" + urllib.parse.quote("{}"))
