"""
Фаза AA (2.13.0): высота блоков и иконки в настоящем браузере (Playwright + Chromium).
Сайт: вычисленная высота блоков (статистика 500 px, вопросы 1000 px, кнопки 360 px, таймер 300 px, партнёры 300 px),
положение содержимого (по центру, снизу), блоки по умолчанию — прежней высоты; без иконок — нет значков раскрытия вопросов,
строки без иконки; стиль «Мягкий градиент» — стрелки «↗» убраны только у строк без иконки; телефон — высота не больше экрана,
нет горизонтальной прокрутки. Редактор: классы и переменные холста как на сайте, раздел «Высота и иконки» у блоков
(с 2.14.0 — «Высота, ширина текста и иконки»; в том числе у главного, кнопок-ссылок и партнёров), смена положения и флажка иконок → атрибуты и холст, флажок строки
«без иконки», сохранение. Настройки и панель «Блоки олимпиады»: поля.

Запуск: WPD_BASE=http://127.0.0.1:9400 python3 wpd-browser-test-aa.py <папка_для_скриншотов>
Нужны помощники wpd-aa-helper.php и wpd-set.php в корне WordPress.
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
    print(f"\nPHASE AA (browser): PASS={len(results) - fails} FAIL={fails}")


# Высота корня блока и зазоры содержимого сверху и снизу (внутри отступов)
MEASURE = """() => {
    const one = (wrap, sel) => {
        const root = document.querySelector('.' + wrap + ' ' + sel);
        if (!root) return null;
        const cs = getComputedStyle(root);
        const r = root.getBoundingClientRect();
        const kids = [...root.children].filter(e => getComputedStyle(e).display !== 'none' && e.getBoundingClientRect().height > 0);
        const top = Math.min(...kids.map(e => e.getBoundingClientRect().top));
        const bottom = Math.max(...kids.map(e => e.getBoundingClientRect().bottom));
        return { h: Math.round(r.height), gapTop: Math.round(top - r.top - parseFloat(cs.paddingTop)),
                 gapBottom: Math.round(r.bottom - parseFloat(cs.paddingBottom) - bottom), display: cs.display };
    };
    const after = (sel) => { const e = document.querySelector(sel); if (!e) return null; const s = getComputedStyle(e, '::after'); return s.display === 'none' ? 'none' : s.content; };
    return {
        stats: one('aa-stats', '.wpd-stats'), statsPlain: one('aa-stats-plain', '.wpd-stats'),
        faq: one('aa-faq', '.wpd-faq'), faqPlain: one('aa-faq-plain', '.wpd-faq'),
        buttons: one('aa-buttons', '.wpd-buttons'), cd: one('aa-cd', '.wpd-countdown'), partners: one('aa-partners', '.wpd-partners'),
        faqAfter: after('.aa-faq .wpd-faq__q'), faqPlainAfter: after('.aa-faq-plain .wpd-faq__q'),
        qbtnAfter: [after('.aa-buttons .wpd-qbtn:not(.wpd-qbtn--noicon)'), after('.aa-buttons .wpd-qbtn--noicon')],
        docAfter: [after('.aa-docs .wpd-doc:not(.wpd-doc--noicon)'), after('.aa-docs .wpd-doc--noicon')],
        qbtnIcons: document.querySelectorAll('.aa-buttons .wpd-qbtn__icon').length,
        scroll: document.documentElement.scrollWidth - window.innerWidth, vh: window.innerHeight,
    };
}"""

set_opts({})
info = json.loads(fetch(BASE + "/wpd-aa-helper.php?setup=1"))
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
    s, sp = m["stats"] or {}, m["statsPlain"] or {}
    check("stats: 500 px tall", s.get("h") == 500, s)
    check("stats: content centred", abs(s.get("gapTop", 0) - s.get("gapBottom", 99)) <= 2 and s.get("gapTop", 0) > 20, s)
    check("stats default: natural height", 0 < sp.get("h", 0) < 400 and sp.get("gapTop", 9) <= 1, sp)
    f, fp = m["faq"] or {}, m["faqPlain"] or {}
    check("faq: 1000 px, content at bottom", f.get("h") == 1000 and abs(f.get("gapBottom", 99)) <= 2 and f.get("gapTop", 0) > 300, f)
    check("faq default: natural height", 0 < fp.get("h", 0) < 600, fp)
    check("faq: no disclosure icon; default keeps it", m["faqAfter"] == "none" and m["faqPlainAfter"] not in (None, "none"), (m["faqAfter"], m["faqPlainAfter"]))
    b = m["buttons"] or {}
    check("buttons: 360 px, centred", b.get("h") == 360 and abs(b.get("gapTop", 0) - b.get("gapBottom", 99)) <= 2, b)
    check("buttons: one tile icon", m["qbtnIcons"] == 1, m["qbtnIcons"])
    c = m["cd"] or {}
    check("countdown: 300 px, still flex, centred", c.get("h") == 300 and c.get("display") == "flex" and abs(c.get("gapTop", 0) - c.get("gapBottom", 99)) <= 2, c)
    pt = m["partners"] or {}
    check("partners: 300 px, bottom", pt.get("h") == 300 and abs(pt.get("gapBottom", 99)) <= 2, pt)
    check("desktop: no horizontal scroll", m["scroll"] <= 1, m["scroll"])
    page.screenshot(path=os.path.join(SHOTS, "aa-front.png"), full_page=True)
    front_cls = page.evaluate("() => document.querySelector('.aa-stats .wpd-stats').className")
    front_st = page.evaluate("() => document.querySelector('.aa-stats .wpd-stats').getAttribute('style')")

    # «Мягкий градиент»: стрелки «↗» у плиток и документов
    set_opts({"design_style": "soft"})
    page.goto(URL, timeout=180000)
    page.wait_for_load_state("networkidle", timeout=120000)
    ms = page.evaluate(MEASURE)
    check("soft: tile arrow kept, removed on row without icon", ms["qbtnAfter"][0] not in (None, "none") and ms["qbtnAfter"][1] == "none", ms["qbtnAfter"])
    check("soft: doc arrow kept, removed on row without badge", ms["docAfter"][0] not in (None, "none") and ms["docAfter"][1] == "none", ms["docAfter"])
    check("soft: faq icon still hidden", ms["faqAfter"] == "none", ms["faqAfter"])
    check("soft: heights kept", (ms["stats"] or {}).get("h") == 500 and (ms["buttons"] or {}).get("h") == 360, (ms["stats"], ms["buttons"]))
    page.screenshot(path=os.path.join(SHOTS, "aa-front-soft.png"), full_page=True)
    set_opts({})

    mob = browser.new_context(viewport={"width": 390, "height": 800}, locale="ru-RU")
    mp = mob.new_page()
    mp.goto(URL, timeout=180000)
    mp.wait_for_load_state("networkidle", timeout=120000)
    mm = mp.evaluate(MEASURE)
    check("mobile: 1000 px block capped to screen", (mm["faq"] or {}).get("h", 0) <= 801 and (mm["faq"] or {}).get("h", 0) >= 700, mm["faq"])
    check("mobile: 500 px block kept", (mm["stats"] or {}).get("h", 0) >= 500, mm["stats"])
    check("mobile: no horizontal scroll", mm["scroll"] <= 1, mm["scroll"])
    mp.screenshot(path=os.path.join(SHOTS, "aa-front-mobile.png"), full_page=True)
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
    cv.wait_for_selector(".aa-stats .wpd-stats", timeout=60000)
    time.sleep(1.5)

    own = lambda cls: sorted(x for x in cls.split() if x.startswith("wpd-bh") or x == "wpd-noicons")
    ed_cls = cv.evaluate("() => document.querySelector('.aa-stats .wpd-stats').className")
    ed_st = cv.evaluate("() => (document.querySelector('.aa-stats .wpd-stats').getAttribute('style') || '').replace(/:\\s+/g, ':')")
    check("canvas: stats classes as on site", own(ed_cls) == own(front_cls) and own(front_cls) and "wpd-look" not in ed_cls.split(), (ed_cls, front_cls))
    check("canvas: stats height var", "--wpd-bh:500px" in ed_st and "--wpd-bh:500px" in (front_st or ""), (ed_st, front_st))
    ce = cv.evaluate("""() => ({ statIcons: document.querySelectorAll('.aa-stats .wpd-stat__icon').length,
        plainIcons: document.querySelectorAll('.aa-stats-plain .wpd-stat__icon').length,
        qIcons: document.querySelectorAll('.aa-buttons .wpd-qbtn__icon').length, qNo: document.querySelectorAll('.aa-buttons .wpd-qbtn--noicon').length,
        docIcons: document.querySelectorAll('.aa-docs .wpd-doc__icon').length, cardIcons: document.querySelectorAll('.aa-cards .wpd-card__icon').length,
        cardMore: document.querySelectorAll('.aa-cards .wpd-card__more').length,
        btnCls: document.querySelector('.aa-buttons .wpd-buttons').className, ptCls: document.querySelector('.aa-partners .wpd-partners').className,
        heroIcon: !!document.querySelector('.aa-hero .wpd-btn__icon'), statsH: Math.round(document.querySelector('.aa-stats .wpd-stats').getBoundingClientRect().height) })""")
    check("canvas: icons as on site", ce["statIcons"] == 0 and ce["plainIcons"] == 2 and ce["qIcons"] == 1 and ce["qNo"] == 1 and ce["docIcons"] == 1
          and ce["cardIcons"] == 1 and ce["cardMore"] == 1 and ce["heroIcon"], ce)
    check("canvas: buttons and partners height classes", "wpd-bh--center" in ce["btnCls"].split() and "wpd-bh--bottom" in ce["ptCls"].split(), (ce["btnCls"], ce["ptCls"]))
    check("canvas: stats block 500 px tall", ce["statsH"] >= 500, ce["statsH"])

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

    sid = block_id("b.name === 'wpd/stats' && b.attributes.className === 'aa-stats'")
    select_block(sid)
    check("inspector stats: box panel", open_panel("Высота, ширина текста и иконки") and insp.locator(".wpd-ed-look-blockMinHeight").count() == 1
          and insp.locator(".wpd-ed-look-blockVAlign select").count() == 1 and insp.locator(".wpd-ed-look-showIcons").count() == 1)
    insp.locator(".wpd-ed-look-blockVAlign select").select_option("bottom")
    time.sleep(0.8)
    ed_cls = cv.evaluate("() => document.querySelector('.aa-stats .wpd-stats').className")
    check("valign select -> attribute and canvas", attr(sid, "blockVAlign") == "bottom" and "wpd-bh--bottom" in ed_cls.split() and "wpd-bh--center" not in ed_cls.split(), ed_cls)
    insp.locator(".wpd-ed-look-showIcons input").click()
    time.sleep(0.8)
    check("icons toggle -> attribute and canvas", attr(sid, "showIcons") is True and cv.evaluate("() => document.querySelectorAll('.aa-stats .wpd-stat__icon').length") == 2)
    page.screenshot(path=os.path.join(SHOTS, "aa-editor.png"))

    hid = block_id("b.name === 'wpd/hero'")
    select_block(hid)
    check("inspector hero: box panel with icons toggle only", open_panel("Высота и иконки") and insp.locator(".wpd-ed-look-showIcons").count() == 1
          and insp.locator(".wpd-ed-look-blockMinHeight").count() == 0)
    insp.locator(".wpd-ed-look-showIcons input").click()
    time.sleep(0.8)
    hero_txt = cv.evaluate("() => document.querySelector('.aa-hero .wpd-hero__actions, .aa-hero .wpd-hero__inner').textContent")
    check("hero icons off -> canvas", attr(hid, "showIcons") is False and not cv.evaluate("() => !!document.querySelector('.aa-hero .wpd-btn__icon')") and "→" not in hero_txt, hero_txt[:120])

    bid = block_id("b.name === 'wpd/buttons'")
    select_block(bid)
    check("inspector buttons: box panel", open_panel("Высота, ширина текста и иконки") and insp.locator(".wpd-ed-look-blockMinHeight").count() == 1)
    flags = cv.locator(".aa-buttons .wpd-ed-item--buttons").first.get_by_text("без иконки", exact=True)
    check("buttons item editor: row flag", flags.count() == 1)
    try:
        flags.first.click()
        time.sleep(0.8)
    except Exception:
        pass
    items = attr(bid, "items") or []
    check("row flag -> item attribute and canvas", bool(items) and bool(items[0].get("noicon")) and cv.evaluate("() => document.querySelectorAll('.aa-buttons .wpd-qbtn__icon').length") == 0,
          [i.get("noicon") for i in items])
    pid_ = block_id("b.name === 'wpd/partners'")
    select_block(pid_)
    check("inspector partners: box panel", open_panel("Высота, ширина текста и иконки") and insp.locator(".wpd-ed-look-blockVAlign select").count() == 1 and insp.locator(".wpd-ed-look-showIcons").count() == 0)

    save(page)
    page.goto(URL, timeout=180000)
    fr = page.evaluate("""() => [document.querySelector('.aa-stats .wpd-stats').className, document.querySelectorAll('.aa-stats .wpd-stat__icon').length,
        !!document.querySelector('.aa-hero .dashicons-awards'), document.querySelectorAll('.aa-buttons .wpd-qbtn__icon').length]""")
    check("front after save", "wpd-bh--bottom" in fr[0].split() and "wpd-noicons" not in fr[0].split() and fr[1] == 2 and fr[2] is False and fr[3] == 0, fr)

    # ---------- 3. Панель страницы и настройки ----------
    page.goto(f"{BASE}/wp-admin/post.php?post={PANEL_ID}&action=edit", timeout=180000)
    wait_editor(page)
    f = page.locator('[name="wpd_page[ov][stats_min_h]"]')
    check("page panel: height field with saved value", f.count() == 1 and f.input_value() == "640", f.input_value() if f.count() else "none")
    page.goto(f"{BASE}/wp-admin/admin.php?page=wpd-settings&tab=cards", timeout=180000)
    check("settings: cards fields", page.locator('[name="wpd_settings[cards_min_h]"]').count() == 1 and page.locator('[name="wpd_settings[cards_valign]"]').count() == 1
          and page.locator('[name="wpd_settings[cards_show_icons]"][type="checkbox"]').count() == 1)
    page.screenshot(path=os.path.join(SHOTS, "aa-settings.png"), full_page=True)

    check("no JavaScript errors", not js_errors, js_errors[:3])
    browser.close()

fetch(BASE + "/wpd-aa-helper.php?cleanup=1")
set_opts({})
report()
