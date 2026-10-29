"""
Фаза U (2.7.0): параметры остальных блоков в настоящем браузере (Playwright + Chromium) — вычисленные стили на сайте:
колонки на компьютере и телефоне, промежутки, вид элементов (заливка, контур, тон), свой цвет элемента, иконки, размер
чисел и значений таймера, нумерация и «только один открытый ответ» FAQ, положение подписи слайдера, контакты без иконок,
«Мягкий градиент»; редактор: панели «Параметры блока», «Раскладка и вид», «Цвета» меняют холст, поля под элементом
(иконка, скрытие, даты, метка, «ответ открыт сразу»), сохранение и сайт; вкладка настроек.

Запуск: WPD_BASE=http://127.0.0.1:9400 python3 wpd-browser-test-u.py <папка_для_скриншотов>
Нужны помощники wpd-u-page.php и wpd-set.php в корне WordPress.
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


def set_opts(d):
    fetch(BASE + "/wpd-set.php?json=" + urllib.parse.quote(json.dumps(d)))


def report():
    print("\n".join(results))
    fails = sum(1 for r in results if r.startswith("FAIL"))
    print(f"\nPHASE U (browser): PASS={len(results) - fails} FAIL={fails}")


atexit.register(report)


def canvas(page):
    frame = page.frame(name="editor-canvas")
    return frame if frame else page


def wait_editor(page):
    try:
        page.wait_for_function("() => window.wp && wp.data && wp.data.select('core/editor') && wp.data.select('core/editor').getCurrentPostId()", timeout=90000)
    except Exception:
        page.screenshot(path=os.path.join(SHOTS, "u-editor-timeout.png"))
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
    time.sleep(3)


def save(page):
    page.evaluate("() => wp.data.dispatch('core/editor').savePost()")
    page.wait_for_function("() => !wp.data.select('core/editor').isSavingPost() && !wp.data.select('core/editor').isAutosavingPost()", timeout=120000)
    try:
        page.wait_for_function("() => !wp.data.select('core/edit-post').isSavingMetaBoxes()", timeout=60000)
    except Exception:
        pass
    time.sleep(2)


# Вычисленные стили блоков на странице (сайт или холст редактора)
STYLE = """() => {
    const cs = (el) => el ? getComputedStyle(el) : null;
    const cols = (el) => el ? cs(el).gridTemplateColumns.split(' ').filter(Boolean).length : 0;
    const one = (sel, i) => document.querySelectorAll(sel)[i || 0] || null;
    const st = one('.wpd-stats'), sg = st && st.querySelector('.wpd-stats__grid');
    const stats = st ? [...st.querySelectorAll('.wpd-stat')].map(x => {
        const ic = x.querySelector('.wpd-stat__icon'), num = x.querySelector('.wpd-stat__num');
        return { label: (x.querySelector('.wpd-stat__label') || {}).textContent, cls: x.className, bg: cs(x).backgroundColor, color: cs(num).color,
            numSize: cs(num).fontSize, border: cs(x).borderTopWidth, opacity: cs(x).opacity,
            icon: ic ? { w: ic.getBoundingClientRect().width, above: ic.getBoundingClientRect().top < num.getBoundingClientRect().top, cls: ic.className } : null };
    }) : [];
    const dc = one('.wpd-docs'), dl = dc && dc.querySelector('.wpd-docs__list');
    const fq = one('.wpd-faq'), fl = fq && fq.querySelector('.wpd-faq__list');
    const fqItems = fq ? [...fq.querySelectorAll('.wpd-faq__item')] : [];
    const cd = one('.wpd-cards'), cg = cd && cd.querySelector('.wpd-cards__grid');
    const sl = one('.wpd-slider'), cap = sl && sl.querySelector('.wpd-slide__caption');
    const nt = one('.wpd-notice');
    const cw = one('.wpd-countdown'), cv = cw && cw.querySelector('.wpd-countdown__value');
    const ct = one('.wpd-contacts'), cl = ct && ct.querySelector('.wpd-contacts__list');
    return {
        stats: { cls: st ? st.className : '', cols: cols(sg), gap: sg ? cs(sg).columnGap : '', items: stats,
                 title: st ? ((st.querySelector('.wpd-h2') || {}).textContent || '') : '' },
        docs: { gap: dl ? cs(dl).rowGap : '', first: dc && dc.querySelector('.wpd-doc') ? (() => { const d = dc.querySelector('.wpd-doc'), b = d.querySelector('.wpd-it-badge');
                  return { bg: cs(d).backgroundColor, shadow: cs(d).boxShadow, badge: b ? b.textContent : '', desc: !!d.querySelector('.wpd-doc__desc') }; })() : null },
        faq: { cls: fq ? fq.className : '', items: fqItems.map(x => ({ open: x.open, before: getComputedStyle(x.querySelector('.wpd-faq__q'), '::before').content,
                  size: cs(x.querySelector('.wpd-faq__q')).fontSize, bg: cs(x).backgroundColor })) },
        cards: { cols: cols(cg), border: cd && cd.querySelector('.wpd-card') ? cs(cd.querySelector('.wpd-card')).borderTopWidth : '',
                 bg: cd && cd.querySelector('.wpd-card') ? cs(cd.querySelector('.wpd-card')).backgroundColor : '' },
        slider: { capJustify: cap ? cs(cap).justifyContent : '', capTop: cap ? cs(cap).top : '', badgeW: sl && sl.querySelector('.wpd-slide__badge') ? sl.querySelector('.wpd-slide__badge').getBoundingClientRect().width : 0, capW: cap ? cap.getBoundingClientRect().width : 0, slideBg: sl && sl.querySelector('.wpd-slide') ? cs(sl.querySelector('.wpd-slide')).backgroundColor + '|' + cs(sl.querySelector('.wpd-slide')).backgroundImage : '' },
        notice: { border: nt ? cs(nt).borderTopWidth : '', btn: nt && nt.querySelector('.wpd-notice__btn') ? nt.querySelector('.wpd-notice__btn').getBoundingClientRect().width : 0 },
        countdown: { size: cv ? cs(cv).fontSize : '', bg: cw ? cs(cw).backgroundColor : '' },
        contacts: { cols: cols(cl), icons: ct ? ct.querySelectorAll('.wpd-contacts__icon').length : -1 },
    };
}"""

set_opts({})
info = json.loads(fetch(BASE + "/wpd-u-page.php?reset=1"))
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

    def front(opts=None, w=1440):
        if opts is not None:
            set_opts(opts)
        page.set_viewport_size({"width": w, "height": 1000})
        page.goto(URL, timeout=180000)
        return page.evaluate(STYLE)

    # ---------- 1. Сайт: атрибуты блоков ----------
    n = front({})
    s = n["stats"]
    check("stats: 3 columns, title", s["cols"] == 3 and s["title"] == "Цифры U", s)
    check("stats: only visible items", [i["label"] for i in s["items"]] == ["школьников", "регионов"], [i["label"] for i in s["items"]])
    i0, i1 = (s["items"] + [None, None])[:2]
    check("stats solid: primary fill, white number", i0 and i0["bg"] == "rgb(29, 78, 216)" and i0["color"] == "rgb(255, 255, 255)", i0)
    check("stats: number size 56px", i0 and i0["numSize"] == "56px", i0 and i0["numSize"])
    check("stats: icon shown above number", i0 and i0["icon"] and i0["icon"]["w"] > 0 and i0["icon"]["above"], i0 and i0["icon"])
    check("stats: own light color beats solid, dark text", i1 and i1["bg"] == "rgb(254, 243, 199)" and i1["color"] != "rgb(255, 255, 255)", i1)
    d = n["docs"]
    check("docs: gap 24px", d["gap"] == "24px", d["gap"])
    check("docs card: white with shadow, badge, desc", d["first"] and d["first"]["bg"] == "rgb(255, 255, 255)" and d["first"]["shadow"] != "none"
          and d["first"]["badge"] == "Новое" and d["first"]["desc"], d["first"])
    f = n["faq"]
    check("faq: numbered (::before counter)", f["items"] and "counter(wpd-faq)" in f["items"][0]["before"], f["items"][:1])
    check("faq: first open, others closed", [x["open"] for x in f["items"][:3]] == [True, False, False], [x["open"] for x in f["items"][:3]])
    check("faq: text size 18px, tint bg", f["items"][0]["size"] == "18px" and f["items"][0]["bg"] not in ("rgb(255, 255, 255)", "rgba(0, 0, 0, 0)"), f["items"][0])
    page.locator(".wpd-faq").first.locator(".wpd-faq__q", has_text="Второй вопрос U").click()
    time.sleep(0.5)
    opened = page.evaluate("() => [...document.querySelectorAll('.wpd-faq')[0].querySelectorAll('.wpd-faq__item')].map(x => x.open)")
    check("faq single: opening second closes first", opened[:3] == [False, True, False], opened)
    c = n["cards"]
    check("cards: 2 columns, outline 2px transparent", c["cols"] == 2 and c["border"] == "2px" and c["bg"] == "rgba(0, 0, 0, 0)", c)
    check("slider: caption vertically centered", n["slider"]["capJustify"] == "center" and n["slider"]["capTop"] == "0px", n["slider"])
    check("slider: badge not stretched", 0 < n["slider"]["badgeW"] < n["slider"]["capW"] / 3, n["slider"])
    check("slider: block bg on slide without image", n["slider"]["slideBg"] == "rgb(11, 18, 32)|none", n["slider"]["slideBg"])
    check("notice: outline border, button", n["notice"]["border"] == "2px" and n["notice"]["btn"] > 0, n["notice"])
    check("countdown: value 40px, white card", n["countdown"]["size"] == "40px" and n["countdown"]["bg"] == "rgb(255, 255, 255)", n["countdown"])
    check("contacts: 2 columns, no icons", n["contacts"]["cols"] == 2 and n["contacts"]["icons"] == 0, n["contacts"])
    page.screenshot(path=os.path.join(SHOTS, "u-front-blocks.png"), full_page=True)

    n = front(None, 390)
    check("phone: stats 1 column (columnsMobile)", n["stats"]["cols"] == 1, n["stats"]["cols"])
    check("phone: cards 1 column by default", n["cards"]["cols"] == 1, n["cards"]["cols"])
    page.screenshot(path=os.path.join(SHOTS, "u-front-phone.png"), full_page=True)

    # ---------- 2. Сайт: общие настройки ----------
    n = front({"stats_gap": 40, "stats_bg": "#0b1220", "faq_numbered": 0, "contacts_show_icons": 1}, 1440)
    s = n["stats"]
    check("settings: gap 40 under block attributes", s["gap"] == "40px", s["gap"])
    check("settings: dark items bg, white numbers", s["items"][0]["bg"] == "rgb(11, 18, 32)" and s["items"][0]["color"] == "rgb(255, 255, 255)", s["items"][0])
    check("settings: block attribute numbered still on", "wpd-faq--numbered" in n["faq"]["cls"])
    n = front({"design_style": "soft", "palette": "soft"})
    s = n["stats"]
    check("soft: solid items keep fill", s["items"][0]["bg"] not in ("rgb(255, 255, 255)", "rgba(0, 0, 0, 0)"), s["items"][0])
    check("soft: outline cards keep 2px border", n["cards"]["border"] == "2px", n["cards"])
    check("soft: countdown value 40px", n["countdown"]["size"] == "40px", n["countdown"]["size"])
    page.screenshot(path=os.path.join(SHOTS, "u-front-soft.png"), full_page=True)

    # ---------- 3. Редактор ----------
    set_opts({})
    page.set_viewport_size({"width": 1440, "height": 1000})
    page.goto(BASE + "/wp-login.php", timeout=120000)
    page.fill("#user_login", "admin")
    page.fill("#user_pass", "password")
    page.click("#wp-submit")
    page.wait_for_load_state("networkidle", timeout=120000)
    page.goto(f"{BASE}/wp-admin/post.php?post={PID}&action=edit", timeout=180000)
    wait_editor(page)
    cv = canvas(page)
    cv.wait_for_selector(".wpd-stats .wpd-stat", timeout=60000)
    time.sleep(2)
    e = cv.evaluate(STYLE)
    check("canvas: stats same look as site", "wpd-look--solid" in e["stats"]["cls"] and e["stats"]["cols"] == 3 and e["stats"]["items"][0]["numSize"] == "56px", e["stats"]["cls"])
    check("canvas: hidden and future stats shown, hidden dimmed", len(e["stats"]["items"]) == 4 and float(e["stats"]["items"][2]["opacity"]) < 0.8, [(i["label"], i["opacity"]) for i in e["stats"]["items"]])
    check("canvas: faq numbered", "wpd-faq--numbered" in e["faq"]["cls"], e["faq"]["cls"])

    def blocks(name):
        return page.evaluate("(n) => wp.data.select('core/block-editor').getBlocks().filter(b => b.name === n).map(b => b.clientId)", name)

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

    insp = page.locator(".block-editor-block-inspector")
    sid = blocks("wpd/stats")[0]
    select_block(sid)
    px = open_panel("wpd-ed-look-panel-extra")
    pl = open_panel("wpd-ed-look-panel-layout")
    pc = open_panel("wpd-ed-look-panel-colors")
    check("inspector: three panels", px.count() == 1 and pl.count() == 1 and pc.count() == 1, (px.count(), pl.count(), pc.count()))
    vs = insp.locator(".wpd-ed-look-variant select").first
    check("inspector: variant shows block value", vs.input_value() == "solid", vs.input_value())
    vs.select_option("outline")
    time.sleep(0.8)
    e = cv.evaluate(STYLE)
    check("inspector variant -> attribute + canvas outline", attr(sid, "variant") == "outline" and "wpd-look--outline" in e["stats"]["cls"] and e["stats"]["items"][0]["border"] == "2px", e["stats"]["cls"])
    insp.locator(".wpd-ed-look-columns select").first.select_option("2")
    time.sleep(0.8)
    check("inspector columns -> canvas 2", cv.evaluate(STYLE)["stats"]["cols"] == 2, cv.evaluate(STYLE)["stats"]["cols"])
    insp.locator(".wpd-ed-look-numSize input[type=number]").first.fill("40")
    time.sleep(0.8)
    check("inspector number size -> canvas 40px", attr(sid, "numSize") == 40 and cv.evaluate(STYLE)["stats"]["items"][0]["numSize"] == "40px", cv.evaluate(STYLE)["stats"]["items"][0]["numSize"])
    check("inspector: colour controls", all(insp.locator(f".wpd-ed-look-{a}").count() == 1 for a in ["bgColor", "accentColor", "textColor"]))
    page.screenshot(path=os.path.join(SHOTS, "u-editor-inspector.png"))

    # Поля под элементом
    cv.wait_for_selector(".wpd-ed-more--stats", timeout=30000)
    more = cv.locator(".wpd-ed-more--stats")
    check("item fields: one per stat", more.count() == 4, more.count())
    more.nth(1).locator("select").first.select_option("edit")
    time.sleep(0.6)
    check("item icon -> attribute and canvas", attr(sid, "items")[1]["icon"] == "edit" and cv.locator(".wpd-stats .wpd-stat").nth(1).locator(".dashicons-edit").count() == 1)
    more.nth(0).locator("label.wpd-ed-check", has_text="скрыть").locator("input").dispatch_event("click")
    time.sleep(0.6)
    check("item hide -> attribute, dimmed, note", attr(sid, "items")[0]["hidden"] in (1, True)
          and "wpd-ed-off" in (cv.locator(".wpd-stats .wpd-stat").first.get_attribute("class") or "")
          and "Скрыта на сайте" in cv.locator(".wpd-stats .wpd-stat").first.inner_text())
    more.nth(3).locator("input[aria-label='Показывать с']").fill("2000-01-01T00:00")
    time.sleep(0.6)
    check("item date -> attribute with space", attr(sid, "items")[3]["from"] == "2000-01-01 00:00", attr(sid, "items")[3].get("from"))

    did = blocks("wpd/docs")[0]
    select_block(did)
    cv.wait_for_selector(".wpd-ed-more--docs", timeout=30000)
    cv.locator(".wpd-ed-more--docs").nth(1).locator("input.wpd-ed-more-badge").fill("Важно")
    time.sleep(0.6)
    check("doc badge -> attribute and canvas", attr(did, "items")[1]["badge"] == "Важно" and cv.locator(".wpd-docs .wpd-it-badge", has_text="Важно").count() == 1)

    fid = blocks("wpd/faq")[0]
    select_block(fid)
    open_panel("wpd-ed-look-panel-extra")
    single = insp.locator(".wpd-ed-look-single input[type=checkbox]").first
    check("faq inspector: single checked from block", single.is_checked())
    insp.locator(".wpd-ed-look-numbered input[type=checkbox]").first.click()
    time.sleep(0.6)
    check("faq numbered off -> attribute + canvas", attr(fid, "numbered") is False and "wpd-faq--numbered" not in cv.evaluate(STYLE)["faq"]["cls"], attr(fid, "numbered"))
    cv.wait_for_selector(".wpd-ed-more--faq", timeout=30000)
    cv.locator(".wpd-ed-more--faq").nth(2).locator("label.wpd-ed-check", has_text="ответ открыт сразу").locator("input").dispatch_event("click")
    time.sleep(0.6)
    check("faq item open -> attribute", attr(fid, "items")[2].get("open") in (1, True), attr(fid, "items")[2])
    page.screenshot(path=os.path.join(SHOTS, "u-editor-items.png"))

    save(page)
    n = front(None, 1440)
    s = n["stats"]
    check("after save: outline + 2 columns + 40px", "wpd-look--outline" in s["cls"] and s["cols"] == 2 and s["items"][0]["numSize"] == "40px", s["cls"])
    check("after save: hidden gone, dated one shown, icon changed", [i["label"] for i in s["items"]] == ["регионов", "будущая"]
          and s["items"][0]["icon"] and "dashicons-edit" in s["items"][0]["icon"]["cls"], [i["label"] for i in s["items"]])
    check("after save: faq not numbered, first and third open", "wpd-faq--numbered" not in n["faq"]["cls"] and [x["open"] for x in n["faq"]["items"][:3]] == [True, False, True], n["faq"])
    page.screenshot(path=os.path.join(SHOTS, "u-front-after-save.png"), full_page=True)

    # ---------- 4. Вкладка настроек ----------
    page.goto(f"{BASE}/wp-admin/admin.php?page=wpd-settings&tab=stats", timeout=180000)
    check("settings tab: «Раскладка и вид»", page.locator("h2", has_text="Раскладка и вид").count() == 1)
    page.locator(".wpd-repeater [data-wpd-add], .wpd-repeater .wpd-add-row, button", has_text="Добавить").first.click()
    time.sleep(0.6)
    check("settings tab: added row has datetime and hidden fields", page.locator('input[type="datetime-local"][name^="wpd_settings[stats]"]').count() >= 2
          and page.locator('input[type="checkbox"][name$="[hidden]"][name^="wpd_settings[stats]"]').count() >= 1)
    page.screenshot(path=os.path.join(SHOTS, "u-settings-stats.png"), full_page=True)

    check("no JavaScript errors", not js_errors, js_errors[:3])
    browser.close()

fetch(BASE + "/wpd-u-page.php?cleanup=1")
set_opts({})
