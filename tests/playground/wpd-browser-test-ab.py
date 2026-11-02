"""
Фаза AB (2.14.0): картинка вместо текста и ширина текста в настоящем браузере (Playwright + Chromium).
Сайт: картинка главного блока от края до края (ширина картинки = ширине блока, высота — по пропорциям 1600 × 500),
с отступами (картинка уже блока), ссылка с картинки, кнопки и таймер под картинкой, невидимый заголовок; телефон — картинка
для телефона (квадратная), нет горизонтальной прокрутки; «По ширине» — вычисленный text-align: justify у главного блока,
вопросов и плиток кнопок; «Максимальная ширина текста» — колонка не шире заданной, по центру или слева. Редактор: холст как
на сайте, раздел «Картинка вместо текста» главного блока (флажки, ширина картинки → атрибуты и холст), раздел «Высота,
ширина текста и иконки» → атрибут и переменная холста, сохранение. Настройки и панель «Блоки олимпиады»: поля.

Запуск: WPD_BASE=http://127.0.0.1:9400 python3 wpd-browser-test-ab.py <папка_для_скриншотов>
Нужны помощники wpd-ab-helper.php и wpd-set.php в корне WordPress.
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
    print(f"\nPHASE AB (browser): PASS={len(results) - fails} FAIL={fails}")


# Размеры картинок главного блока, выравнивание и ширина колонок
MEASURE = """() => {
    const rect = (e) => { if (!e) return null; const r = e.getBoundingClientRect(); return { l: Math.round(r.left), r: Math.round(r.right), w: Math.round(r.width), h: Math.round(r.height), t: Math.round(r.top + scrollY) }; };
    const q = (s) => document.querySelector(s);
    const hero = (cls) => {
        const sec = q('.' + cls + ' .wpd-hero'), img = q('.' + cls + ' .wpd-hero__banner-img');
        if (!sec) return null;
        const cs = getComputedStyle(sec);
        return { sec: rect(sec), img: rect(img), src: img ? img.currentSrc : '', loaded: img ? img.complete && img.naturalWidth > 0 : false,
                 below: rect(q('.' + cls + ' .wpd-hero__below')), padL: parseFloat(cs.paddingLeft), display: cs.display,
                 h1: rect(q('.' + cls + ' h1.wpd-sr')), link: !!(img && img.closest('a.wpd-hero__banner-link')),
                 btn: rect(q('.' + cls + ' .wpd-hero__below .wpd-btn')), timer: rect(q('.' + cls + ' .wpd-hero__below .wpd-countdown')) };
    };
    const col = (cls, sel) => {
        const root = q('.' + cls + ' ' + sel);
        if (!root) return null;
        const cs = getComputedStyle(root);
        const inner = { l: root.getBoundingClientRect().left + parseFloat(cs.paddingLeft), r: root.getBoundingClientRect().right - parseFloat(cs.paddingRight) };
        const kids = [...root.children].map(rect);
        return { root: rect(root), innerL: Math.round(inner.l), innerR: Math.round(inner.r), kids: kids,
                 maxW: Math.max(...kids.map(k => k.w)), gapL: Math.round(Math.min(...kids.map(k => k.l)) - inner.l), gapR: Math.round(inner.r - Math.max(...kids.map(k => k.r))) };
    };
    const ta = (s) => { const e = q(s); return e ? getComputedStyle(e).textAlign : null; };
    return {
        hero: hero('ab-hero'), pad: hero('ab-hero-pad'),
        leadAlign: ta('.ab-hero-text .wpd-hero__lead'), titleAlign: ta('.ab-hero-text .wpd-hero__title'),
        stats: col('ab-stats', '.wpd-stats'), faq: col('ab-faq', '.wpd-faq'), faqLeft: col('ab-faq-left', '.wpd-faq'), faqPlain: col('ab-faq-plain', '.wpd-faq'),
        buttons: col('ab-buttons', '.wpd-buttons'),
        faqAlign: ta('.ab-faq .wpd-faq__item'), faqPlainAlign: ta('.ab-faq-plain .wpd-faq__item'), btnAlign: ta('.ab-buttons .wpd-qbtn'),
        hyph: (() => { const e = q('.ab-faq .wpd-faq__item'); return e ? getComputedStyle(e).hyphens : null; })(),
        scroll: document.documentElement.scrollWidth - window.innerWidth,
    };
}"""

set_opts({})
info = json.loads(fetch(BASE + "/wpd-ab-helper.php?setup=1"))
PID = info["id"]
URL = info["url"]
PANEL_ID = info["pid"]
IMG = info["img"]
IMGM = info["imgm"]
OPTS = info["opts"]

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
    h = m["hero"] or {}
    sec, img = h.get("sec") or {}, h.get("img") or {}
    check("banner: picture loaded (desktop source)", h.get("loaded") and h.get("src") == IMG, h.get("src"))
    check("banner edge: picture as wide as block", img.get("w") and abs(img["w"] - sec.get("w", 0)) <= 1 and abs(img["l"] - sec.get("l", 0)) <= 1, (img, sec))
    check("banner edge: height by proportions 1600x500", img.get("w") and abs(img["h"] - img["w"] * 500 / 1600) <= 2, img)
    check("banner: block height = picture + below", sec.get("h") and abs(sec["h"] - img.get("h", 0) - (h.get("below") or {}).get("h", 0)) <= 2 and h.get("display") == "block", (sec, h.get("below")))
    check("banner: link around picture", h.get("link") is True)
    check("banner: hidden h1 (1px)", (h.get("h1") or {}).get("w", 99) <= 1)
    check("banner: button and timer below picture", (h.get("btn") or {}).get("t", 0) >= img.get("t", 0) + img.get("h", 0) and (h.get("timer") or {}).get("h", 0) > 20, (h.get("btn"), h.get("timer")))
    pd = m["pad"] or {}
    psec, pimg = pd.get("sec") or {}, pd.get("img") or {}
    check("banner padded: picture inside block padding", pimg.get("w") and pimg["w"] < psec.get("w", 0) - 40 and abs((pimg["l"] - psec["l"]) - pd.get("padL", 0)) <= 1, (pimg, psec, pd.get("padL")))
    check("banner padded: proportions kept", pimg.get("w") and abs(pimg["h"] - pimg["w"] * 500 / 1600) <= 2, pimg)
    check("hero justify: lead and title justified", m["leadAlign"] == "justify" and m["titleAlign"] == "justify", (m["leadAlign"], m["titleAlign"]))
    st = m["stats"] or {}
    check("stats: column <= 400 px, centred", 0 < st.get("maxW", 0) <= 400 and abs(st.get("gapL", 0) - st.get("gapR", 99)) <= 2 and st.get("gapL", 0) > 60, st)
    fq = m["faq"] or {}
    check("faq: column <= 300 px, centred", 0 < fq.get("maxW", 0) <= 300 and abs(fq.get("gapL", 0) - fq.get("gapR", 99)) <= 2, fq)
    fl = m["faqLeft"] or {}
    check("faq left: column at left edge", 0 < fl.get("maxW", 0) <= 300 and abs(fl.get("gapL", 99)) <= 1 and fl.get("gapR", 0) > 100, fl)
    fp = m["faqPlain"] or {}
    check("faq default: full width", fp.get("maxW", 0) >= (fp.get("root") or {}).get("w", 9999) - 2 and fp.get("maxW", 0) > 300 and abs(fp.get("gapL", 99)) <= 1, fp)
    check("faq: justified with hyphens; default not", m["faqAlign"] == "justify" and m["hyph"] == "auto" and m["faqPlainAlign"] != "justify", (m["faqAlign"], m["hyph"], m["faqPlainAlign"]))
    bt = m["buttons"] or {}
    check("buttons: justified tiles, column <= 450 px", m["btnAlign"] == "justify" and 0 < bt.get("maxW", 0) <= 450, (m["btnAlign"], bt.get("maxW")))
    check("desktop: no horizontal scroll", m["scroll"] <= 1, m["scroll"])
    page.screenshot(path=os.path.join(SHOTS, "ab-front.png"), full_page=True)
    front_cls = page.evaluate("() => document.querySelector('.ab-hero .wpd-hero').className")
    front_tw = page.evaluate("() => document.querySelector('.ab-stats .wpd-stats').getAttribute('style')")

    # «Мягкий градиент»: картинка от края до края и в этом стиле
    set_opts(dict(OPTS, design_style="soft"))
    page.goto(URL, timeout=180000)
    page.wait_for_load_state("networkidle", timeout=120000)
    ms = page.evaluate(MEASURE)
    sh = ms["hero"] or {}
    check("soft: banner edge to edge", (sh.get("img") or {}).get("w") and abs(sh["img"]["w"] - (sh.get("sec") or {}).get("w", 0)) <= 1, (sh.get("img"), sh.get("sec")))
    check("soft: column widths kept", 0 < (ms["stats"] or {}).get("maxW", 0) <= 400 and (ms["faq"] or {}).get("maxW", 0) <= 300)
    page.screenshot(path=os.path.join(SHOTS, "ab-front-soft.png"), full_page=True)
    set_opts(OPTS)

    mob = browser.new_context(viewport={"width": 390, "height": 800}, locale="ru-RU")
    mp = mob.new_page()
    mp.goto(URL, timeout=180000)
    mp.wait_for_load_state("networkidle", timeout=120000)
    mm = mp.evaluate(MEASURE)
    mh = mm["hero"] or {}
    mimg = mh.get("img") or {}
    check("mobile: phone picture used", mh.get("src") == IMGM and mh.get("loaded"), mh.get("src"))
    check("mobile: square phone picture, full block width", mimg.get("w") and abs(mimg["h"] - mimg["w"]) <= 2 and abs(mimg["w"] - (mh.get("sec") or {}).get("w", 0)) <= 1, (mimg, mh.get("sec")))
    check("mobile: button and timer below picture", (mh.get("btn") or {}).get("h", 0) > 20 and (mh.get("timer") or {}).get("h", 0) > 20, (mh.get("btn"), mh.get("timer")))
    check("mobile: padded banner keeps desktop picture", (mm["pad"] or {}).get("src") == IMG)
    check("mobile: columns fit screen", (mm["stats"] or {}).get("maxW", 999) <= 390 and (mm["faq"] or {}).get("maxW", 999) <= 390)
    check("mobile: no horizontal scroll", mm["scroll"] <= 1, mm["scroll"])
    mp.screenshot(path=os.path.join(SHOTS, "ab-front-mobile.png"), full_page=True)
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
    cv.wait_for_selector(".ab-hero .wpd-hero", timeout=60000)
    time.sleep(1.5)

    own = lambda cls: sorted(x for x in cls.split() if x.startswith("wpd-hero"))
    ce = cv.evaluate("""() => ({ cls: document.querySelector('.ab-hero .wpd-hero').className,
        img: (document.querySelector('.ab-hero .wpd-hero__banner-img') || {}).src || '',
        below: !!document.querySelector('.ab-hero .wpd-hero__below .wpd-hero__actions'),
        timer: !!document.querySelector('.ab-hero .wpd-hero__below .wpd-hero__countdown'),
        text: !!document.querySelector('.ab-hero .wpd-hero__inner'),
        padCls: document.querySelector('.ab-hero-pad .wpd-hero').className,
        textCls: document.querySelector('.ab-hero-text .wpd-hero').className,
        statsSt: (document.querySelector('.ab-stats .wpd-stats').getAttribute('style') || '').replace(/:\\s+/g, ':'),
        statsCls: document.querySelector('.ab-stats .wpd-stats').className,
        faqCls: document.querySelector('.ab-faq .wpd-faq').className })""")
    check("canvas: hero classes as on site", own(ce["cls"]) == own(front_cls) and "wpd-hero--banner" in ce["cls"].split(), (ce["cls"], front_cls))
    check("canvas: banner picture, buttons and timer below, no text", ce["img"] == IMG and ce["below"] and ce["timer"] and not ce["text"], ce)
    check("canvas: padded and justify classes", "wpd-hero--banner-padded" in ce["padCls"].split() and "wpd-hero--align-justify" in ce["textCls"].split(), (ce["padCls"], ce["textCls"]))
    check("canvas: text width class and var as on site", "wpd-tw" in ce["statsCls"].split() and "--wpd-tw:400px" in ce["statsSt"] and "--wpd-tw:400px" in (front_tw or ""), (ce["statsCls"], ce["statsSt"]))
    check("canvas: faq justify + width", "wpd-look--align-justify" in ce["faqCls"].split() and "wpd-tw" in ce["faqCls"].split(), ce["faqCls"])

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

    hid = block_id("b.name === 'wpd/hero' && b.attributes.className === 'ab-hero'")
    select_block(hid)
    bp = insp.locator(".wpd-ed-hero-banner")
    check("inspector hero: banner panel with controls", open_panel("Картинка вместо текста") and bp.get_by_label("Ссылка с картинки").count() == 1
          and bp.get_by_label("Ширина картинки").count() == 1 and bp.get_by_label("Кнопки под картинкой").count() == 1
          and bp.get_by_label("Таймер под картинкой").count() == 1 and bp.locator(".wpd-ed-banner-bannerUrl img").count() == 1
          and bp.locator(".wpd-ed-banner-bannerMobileUrl img").count() == 1)
    bp.get_by_label("Кнопки под картинкой").click()
    time.sleep(0.8)
    check("buttons toggle -> attribute and canvas", attr(hid, "bannerButtons") is False
          and not cv.evaluate("() => !!document.querySelector('.ab-hero .wpd-hero__below .wpd-hero__actions')")
          and cv.evaluate("() => !!document.querySelector('.ab-hero .wpd-hero__below .wpd-hero__countdown')"))
    bp.get_by_label("Ширина картинки").select_option("padded")
    time.sleep(0.8)
    check("edge select -> attribute and canvas", attr(hid, "bannerEdge") == "padded"
          and "wpd-hero--banner-padded" in cv.evaluate("() => document.querySelector('.ab-hero .wpd-hero').className").split())
    page.screenshot(path=os.path.join(SHOTS, "ab-editor-hero.png"))
    bp.locator(".components-toggle-control").filter(has_text="Картинка вместо текста").locator("label").first.click()
    time.sleep(0.8)
    check("banner toggle off -> normal hero on canvas", attr(hid, "bannerOn") is False and cv.evaluate("() => !!document.querySelector('.ab-hero .wpd-hero__inner')")
          and not cv.evaluate("() => !!document.querySelector('.ab-hero .wpd-hero__banner')"))
    bp.locator(".components-toggle-control").filter(has_text="Картинка вместо текста").locator("label").first.click()
    time.sleep(0.8)
    check("banner toggle on again", attr(hid, "bannerOn") is True and cv.evaluate("() => !!document.querySelector('.ab-hero .wpd-hero__banner')"))

    sid = block_id("b.name === 'wpd/stats'")
    select_block(sid)
    check("inspector stats: text width in box panel", open_panel("Высота, ширина текста и иконки") and insp.locator(".wpd-ed-look-blockTextWidth").count() == 1)
    num = insp.locator(".wpd-ed-look-blockTextWidth input[type=number]")
    try:
        num.fill("800")
        num.press("Tab")
    except Exception:
        pass
    time.sleep(0.8)
    st2 = cv.evaluate("() => (document.querySelector('.ab-stats .wpd-stats').getAttribute('style') || '').replace(/:\\s+/g, ':')")
    check("text width -> attribute and canvas var", attr(sid, "blockTextWidth") == 800 and "--wpd-tw:800px" in st2, (attr(sid, "blockTextWidth"), st2))
    sel = insp.locator(".wpd-ed-look-textAlign select")
    check("stats align: justify option", open_panel("Раскладка и вид") and sel.count() == 1 and sel.locator("option[value=justify]").count() == 1)
    sel.select_option("justify")
    time.sleep(0.8)
    check("align justify -> canvas", "wpd-look--align-justify" in cv.evaluate("() => document.querySelector('.ab-stats .wpd-stats').className").split())
    page.screenshot(path=os.path.join(SHOTS, "ab-editor-stats.png"))

    save(page)
    page.goto(URL, timeout=180000)
    page.wait_for_load_state("networkidle", timeout=120000)
    fr = page.evaluate("""() => [document.querySelector('.ab-hero .wpd-hero').className, !!document.querySelector('.ab-hero .wpd-hero__below .wpd-hero__actions'),
        document.querySelector('.ab-stats .wpd-stats').getAttribute('style'), document.querySelector('.ab-stats .wpd-stats').className]""")
    check("front after save", "wpd-hero--banner-padded" in fr[0].split() and fr[1] is False and "--wpd-tw:800px" in (fr[2] or "") and "wpd-look--align-justify" in fr[3].split(), fr)

    # ---------- 3. Панель страницы и настройки ----------
    page.goto(f"{BASE}/wp-admin/post.php?post={PANEL_ID}&action=edit", timeout=180000)
    wait_editor(page)
    f = page.locator('[name="wpd_page[ov][hero_banner_url]"]')
    g = page.locator('[name="wpd_page[ov][faq_text_w]"]')
    check("page panel: banner and width fields with saved values", f.count() == 1 and f.input_value() == IMG and g.count() == 1 and g.input_value() == "640",
          (f.input_value() if f.count() else "none", g.input_value() if g.count() else "none"))
    page.goto(f"{BASE}/wp-admin/admin.php?page=wpd-settings&tab=hero", timeout=180000)
    check("settings: hero banner fields", page.locator('[name="wpd_settings[hero_banner]"][type="checkbox"]').count() == 1
          and page.locator('[name="wpd_settings[hero_banner_url]"]').count() == 1 and page.locator('[name="wpd_settings[hero_banner_edge]"]').count() == 1
          and page.locator('select[name="wpd_settings[hero_align]"] option[value="justify"]').count() == 1)
    page.locator('[name="wpd_settings[hero_banner]"]').scroll_into_view_if_needed()
    page.screenshot(path=os.path.join(SHOTS, "ab-settings.png"))

    check("no JavaScript errors", not js_errors, js_errors[:3])
    browser.close()

fetch(BASE + "/wpd-ab-helper.php?cleanup=1")
set_opts({})
report()
