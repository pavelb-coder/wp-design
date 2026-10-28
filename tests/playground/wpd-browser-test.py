"""
Фаза F (1.3.0): проверка в настоящем браузере (Playwright + Chromium).
- блоки «Олимпиада: …» в редакторе блоков: регистрация, вставка, правка текста на холсте, списки, сохранение, вывод на сайте;
- панель «Блоки олимпиады»: порядок, режим «свои», добавление строк, сохранение из блочного редактора;
- отсутствие ошибок JavaScript.

Запуск: WPD_BASE=http://127.0.0.1:9400 python3 wpd-browser-test.py <папка_для_скриншотов>
"""
import json
import os
import re
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


def canvas(page):
    """Холст редактора: iframe editor-canvas или сама страница."""
    frame = page.frame(name="editor-canvas")
    return frame if frame else page


def wait_editor(page):
    page.wait_for_function("() => window.wp && wp.data && wp.data.select('core/editor') && wp.data.select('core/editor').getCurrentPostId()", timeout=120000)
    # Отключаем приветствие и стартовые паттерны для новых страниц
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
    # Если окно всё же откроется, его закроет обработчик add_locator_handler (см. ниже)


def select_block(page, client_id):
    page.evaluate("id => wp.data.dispatch('core/block-editor').selectBlock(id)", client_id)
    time.sleep(0.6)


def attrs(page, client_id):
    return page.evaluate("id => wp.data.select('core/block-editor').getBlockAttributes(id)", client_id)


def save(page):
    page.evaluate("() => wp.data.dispatch('core/editor').savePost()")
    page.wait_for_function("() => !wp.data.select('core/editor').isSavingPost() && !wp.data.select('core/editor').isAutosavingPost()", timeout=120000)
    # Мета-боксы сохраняются отдельным запросом после сохранения записи
    try:
        page.wait_for_function("() => !wp.data.select('core/edit-post').isSavingMetaBoxes()", timeout=60000)
    except Exception:
        pass
    time.sleep(2)


def fetch(url):
    with urllib.request.urlopen(url, timeout=120) as r:
        return r.read().decode("utf-8")


def report():
    print("\n".join(results))
    fails = sum(1 for r in results if r.startswith("FAIL"))
    print(f"\nPHASE F: PASS={len(results) - fails} FAIL={fails}")


import atexit
atexit.register(report)

with sync_playwright() as p:
    browser = p.chromium.launch(args=['--disable-dev-shm-usage'])
    ctx = browser.new_context(viewport={"width": 1440, "height": 1000}, locale="ru-RU")
    page = ctx.new_page()
    js_errors = []
    page.on("pageerror", lambda e: js_errors.append(str(e)))

    # Стартовое окно «Choose a pattern» может появиться в любой момент — закрываем автоматически
    # Приветственный гид и окно выбора паттерна: пробуем несколько способов закрыть
    def close_modal():
        overlay = page.locator(".components-modal__screen-overlay").first
        candidates = [
            overlay.get_by_role("button", name=re.compile("^(Close|Закрыть)")),
            overlay.locator(".components-modal__header button"),
            overlay.locator("button.components-guide__finish-button"),
            overlay.locator("button[aria-label]"),
        ]
        for cand in candidates:
            try:
                if cand.count():
                    cand.first.click(timeout=2000, no_wait_after=True, force=True)
                    time.sleep(0.5)
                    if not overlay.is_visible():
                        return
            except Exception:
                pass
        try:
            overlay.focus()
        except Exception:
            pass
        page.keyboard.press("Escape")
        time.sleep(0.5)
    page.add_locator_handler(page.locator(".components-modal__screen-overlay"), close_modal)
    page.on("console", lambda m: js_errors.append("console: " + m.text) if m.type == "error" and "wpd" in m.text.lower() else None)

    # Вход
    page.goto(BASE + "/wp-login.php", timeout=120000)
    page.fill("#user_login", "admin")
    page.fill("#user_pass", "password")
    page.click("#wp-submit")
    page.wait_for_load_state("networkidle", timeout=120000)

    # ---------- 1. Блоки в редакторе ----------
    page.goto(BASE + "/wp-admin/post-new.php?post_type=page", timeout=180000)
    wait_editor(page)
    # Отключаем приветствие и стартовые паттерны в профиле пользователя (REST, мета persisted_preferences),
    # затем перезагружаем редактор — окна больше не появятся
    page.evaluate("""() => wp.apiFetch({
        path: '/wp/v2/users/me?context=edit',
        method: 'POST',
        data: { meta: { persisted_preferences: {
            core: { enableChoosePatternModal: false, welcomeGuide: false },
            'core/edit-post': { welcomeGuide: false },
            _modified: new Date().toISOString()
        } } }
    })""")
    page.goto(BASE + "/wp-admin/post-new.php?post_type=page", timeout=180000)
    wait_editor(page)

    types = page.evaluate("() => wp.blocks.getBlockTypes().filter(b => b.name.indexOf('wpd/') === 0).map(b => b.name).sort()")
    check("editor: 11 blocks registered on client", types == sorted(["wpd/hero", "wpd/countdown", "wpd/stats", "wpd/docs", "wpd/faq", "wpd/slider", "wpd/buttons", "wpd/cards", "wpd/notice", "wpd/news", "wpd/contacts"]), types)
    cat = page.evaluate("() => (wp.blocks.getCategories() || []).some(c => c.slug === 'wpd')")
    check("editor: category 'wpd' available", cat)
    pattern = page.evaluate("""() => {
        const s = wp.data.select('core');
        const list = (s.getBlockPatterns && s.getBlockPatterns()) || [];
        return list.some(p => p.name === 'wpd/olympiad-page');
    }""")
    # шаблоны могут подгружаться асинхронно — повторим
    for _ in range(10):
        if pattern:
            break
        time.sleep(1)
        pattern = page.evaluate("() => ((wp.data.select('core').getBlockPatterns && wp.data.select('core').getBlockPatterns()) || []).some(p => p.name === 'wpd/olympiad-page')")
    check("editor: pattern 'Страница олимпиады' available", pattern)

    page.evaluate("() => wp.data.dispatch('core/editor').editPost({ title: 'Страница из браузера' })")
    ids = page.evaluate("""() => {
        const names = ['wpd/hero', 'wpd/countdown', 'wpd/stats', 'wpd/docs', 'wpd/faq'];
        const blocks = names.map(n => wp.blocks.createBlock(n));
        wp.data.dispatch('core/block-editor').insertBlocks(blocks);
        return blocks.map(b => b.clientId);
    }""")
    hero_id, cd_id, stats_id, docs_id, faq_id = ids
    time.sleep(2)
    cv = canvas(page)

    check("canvas: hero rendered", cv.locator(".wpd-page--hero .wpd-hero").count() == 1)
    check("canvas: stats cards rendered", cv.locator(".wpd-page--stats .wpd-stat").count() >= 1, cv.locator(".wpd-page--stats .wpd-stat").count())
    check("canvas: faq rendered", cv.locator(".wpd-page--faq .wpd-faq__item").count() >= 1)
    check("canvas: css variables applied", "--wpd-primary" in (cv.locator(".wpd-page--hero").get_attribute("style") or ""))
    bg = cv.evaluate("() => getComputedStyle(document.querySelector('.wpd-page--hero .wpd-hero')).backgroundImage + '|' + getComputedStyle(document.querySelector('.wpd-page--hero .wpd-hero')).borderRadius")
    check("canvas: wpd.css loaded in editor (hero styled)", "gradient" in bg and bg.split("|")[1] not in ("0px", ""), bg)
    default_title = attrs(page, hero_id).get("title")
    check("hero: default title from settings", bool(default_title), default_title)

    # Правка заголовка прямо на холсте
    select_block(page, hero_id)
    title_box = cv.locator(".wpd-page--hero .wpd-hero__title textarea")
    title_box.click()
    title_box.fill("Заголовок из браузера")
    time.sleep(0.5)
    check("hero: title edited on canvas", attrs(page, hero_id).get("title") == "Заголовок из браузера", attrs(page, hero_id).get("title"))
    # Перевод строки в однострочном поле убирается
    title_box.press("End")
    title_box.press("Enter")
    time.sleep(0.3)
    check("hero: single-line title has no newline", "\n" not in attrs(page, hero_id).get("title", ""))
    title_box.fill("Заголовок из браузера")

    badge = cv.locator(".wpd-page--hero .wpd-badge input")
    badge.fill("Финал 2027")
    time.sleep(0.3)
    check("hero: badge edited", attrs(page, hero_id).get("badge") == "Финал 2027")

    # Стиль в боковой панели
    page.evaluate("() => wp.data.dispatch('core/edit-post').openGeneralSidebar('edit-post/block')")
    time.sleep(0.8)
    style_select = page.locator(".block-editor-block-inspector select").first
    if style_select.count():
        style_select.select_option("light")
        time.sleep(0.5)
    check("hero: style changed in sidebar", attrs(page, hero_id).get("variant") == "light", attrs(page, hero_id).get("variant"))
    check("canvas: light class applied", cv.locator(".wpd-page--hero .wpd-hero--light").count() == 1)

    # 1.8.0: вторая кнопка на холсте; высота, кнопки и изображение в боковой панели
    insp = page.locator(".block-editor-block-inspector")
    b2 = cv.locator(".wpd-page--hero .wpd-btn--ghost input")
    check("hero: second button placeholder on canvas (1.8.0)", b2.count() == 1, b2.count())
    if b2.count():
        b2.first.fill("Задания прошлых лет")
        time.sleep(0.4)
    check("hero: second button text edited on canvas", attrs(page, hero_id).get("button2Text") == "Задания прошлых лет", attrs(page, hero_id).get("button2Text"))
    hsel = insp.get_by_label("Высота", exact=True)
    if hsel.count():
        hsel.first.select_option("tall")
        time.sleep(0.4)
    check("hero: height set in sidebar", attrs(page, hero_id).get("height") == "tall", attrs(page, hero_id).get("height"))
    check("canvas: tall class applied", cv.locator(".wpd-page--hero .wpd-hero--height-tall").count() == 1)
    btn_panel = insp.get_by_role("button", name="Кнопки", exact=True)
    if btn_panel.count():
        btn_panel.first.click()
        time.sleep(0.5)
    u2 = insp.get_by_label("Ссылка второй кнопки", exact=True)
    if u2.count():
        u2.first.fill("https://example.com/archive")
        time.sleep(0.3)
    t2 = insp.get_by_label("Вторая кнопка в новой вкладке", exact=True)
    if t2.count():
        t2.first.check()
        time.sleep(0.3)
    a2 = attrs(page, hero_id)
    check("hero: second button link and new tab in sidebar", a2.get("button2Url") == "https://example.com/archive" and a2.get("button2NewTab") is True, (a2.get("button2Url"), a2.get("button2NewTab")))
    # 1.9.0: иконка кнопки и таймер внутри главного блока
    u1 = insp.get_by_label("Ссылка кнопки", exact=True)
    if u1.count():
        u1.first.fill("https://example.com/rules")
        time.sleep(0.3)
    ic = insp.get_by_label("Иконка кнопки", exact=True)
    check("hero: icon select in «Кнопки» (1.9.0)", ic.count() >= 1 and "Без иконки" in (ic.first.inner_text() if ic.count() else ""))
    if ic.count():
        ic.first.select_option("awards")
        time.sleep(0.4)
    tm = insp.get_by_label("Таймер под кнопками", exact=True)
    if tm.count():
        tm.first.check()
        time.sleep(0.5)
    a3 = attrs(page, hero_id)
    check("hero: icon and timer set in sidebar", a3.get("buttonIcon") == "awards" and a3.get("showCountdown") is True, (a3.get("buttonIcon"), a3.get("showCountdown")))
    check("canvas: icon on button and timer in hero", cv.locator(".wpd-page--hero .wpd-hero .wpd-btn__icon.dashicons-awards").count() == 1 and cv.locator(".wpd-page--hero .wpd-hero__countdown").count() == 1)
    img_panel = insp.get_by_role("button", name="Изображение", exact=True)
    if img_panel.count():
        img_panel.first.click()
        time.sleep(0.5)
    pos = insp.get_by_label("Положение изображения", exact=True)
    if pos.count():
        pos.first.select_option("background")
        time.sleep(0.3)
    page.evaluate("id => wp.data.dispatch('core/block-editor').updateBlockAttributes(id, { imageUrl: 'https://example.com/hero.jpg' })", hero_id)
    time.sleep(0.6)
    check("hero: image position set in sidebar", attrs(page, hero_id).get("imagePosition") == "background", attrs(page, hero_id).get("imagePosition"))
    check("hero: overlay control shown for background image", insp.get_by_label("Затемнение фона, %").count() >= 1)
    hbg = cv.evaluate("() => { const h = document.querySelector('.wpd-page--hero .wpd-hero'); return h.className + '|' + getComputedStyle(h).backgroundImage; }")
    check("canvas: background image with overlay", "wpd-hero--img-background" in hbg and "url(" in hbg and "gradient" in hbg and cv.locator(".wpd-page--hero .wpd-hero__media img").count() == 0, hbg[:200])
    # 1.9.0: панель «Размеры»
    sz_panel = insp.get_by_role("button", name="Размеры", exact=True)
    check("hero: «Размеры» panel (1.9.0)", sz_panel.count() >= 1)
    if sz_panel.count():
        sz_panel.first.click()
        time.sleep(0.5)
    mh = insp.get_by_role("spinbutton", name="Минимальная высота, px")
    if mh.count():
        mh.first.fill("520")
        mh.first.press("Tab")
        time.sleep(0.5)
    check("hero: min height set in sidebar", attrs(page, hero_id).get("minHeight") == 520, attrs(page, hero_id).get("minHeight"))
    mhc = cv.evaluate("() => { const h = document.querySelector('.wpd-page--hero .wpd-hero'); return h.className + '|' + getComputedStyle(h).minHeight; }")
    check("canvas: min height applied", "wpd-hero--minh" in mhc and mhc.split("|")[1].endswith("px") and float(mhc.split("|")[1][:-2]) >= 300, mhc)
    page.screenshot(path=os.path.join(SHOTS, "editor-hero.png"))

    # Выравнивание «широкое» через панель инструментов блока (атрибут align)
    page.evaluate("id => wp.data.dispatch('core/block-editor').updateBlockAttributes(id, { align: 'wide' })", hero_id)
    time.sleep(0.5)
    check("hero: align wide supported", attrs(page, hero_id).get("align") == "wide")

    # Статистика: добавить карточку и заполнить
    select_block(page, stats_id)
    before = len(attrs(page, stats_id).get("items", []))
    cv.locator(".wpd-page--stats .wpd-ed-add button").click()
    time.sleep(0.5)
    items = attrs(page, stats_id).get("items", [])
    check("stats: card added", len(items) == before + 1, len(items))
    last = cv.locator(".wpd-page--stats .wpd-stat").last
    last.locator(".wpd-stat__num textarea").fill("999+")
    last.locator(".wpd-stat__label textarea").fill("карточка из браузера")
    time.sleep(0.4)
    items = attrs(page, stats_id).get("items", [])
    check("stats: new card values", items and items[-1].get("num") == "999+" and items[-1].get("label") == "карточка из браузера", items[-1] if items else None)
    # Переместить последнюю карточку вверх
    last.locator(".wpd-ed-tools button").first.dispatch_event("click")  # тулбар блока может перекрыть кнопку
    time.sleep(0.4)
    items = attrs(page, stats_id).get("items", [])
    check("stats: card moved up", len(items) >= 2 and items[-2]["num"] == "999+", [i["num"] for i in items])
    # Удалить первую карточку
    n_before = len(items)
    cv.locator(".wpd-page--stats .wpd-stat").first.locator(".wpd-ed-tools button").nth(2).dispatch_event("click")
    time.sleep(0.4)
    check("stats: card removed", len(attrs(page, stats_id).get("items", [])) == n_before - 1)
    page.screenshot(path=os.path.join(SHOTS, "editor-stats.png"))

    # Документы: добавить документ с ссылкой
    select_block(page, docs_id)
    cv.locator(".wpd-page--docs .wpd-ed-add button").click()
    time.sleep(0.5)
    doc = cv.locator(".wpd-page--docs .wpd-ed-doc").last
    doc.locator("textarea[aria-label='Название документа']").fill("Регламент финала")  # 2.7.0: ниже ещё подпись документа
    doc.locator("input[type=url].wpd-ed-urlinput").fill("https://example.com/final.pdf")  # 2.7.0: в «Ещё» есть текстовые поля
    time.sleep(0.4)
    ditems = attrs(page, docs_id).get("items", [])
    check("docs: document added", ditems and ditems[-1].get("title") == "Регламент финала" and ditems[-1].get("url") == "https://example.com/final.pdf", ditems[-1] if ditems else None)
    check("docs: PDF icon in editor", doc.locator(".wpd-doc__icon").inner_text().strip() == "PDF")

    # FAQ: добавить вопрос, ответ с форматированием в RichText
    select_block(page, faq_id)
    cv.locator(".wpd-page--faq .wpd-ed-add button").click()
    time.sleep(0.5)
    q = cv.locator(".wpd-page--faq .wpd-faq__item").last
    q.locator(".wpd-faq__q textarea").fill("Вопрос из браузера?")
    ans = q.locator(".wpd-faq__a[contenteditable='true']")
    ans.click()
    page.keyboard.type("Ответ ")
    page.keyboard.press("Control+b")
    page.keyboard.type("жирным")
    page.keyboard.press("Control+b")
    time.sleep(0.5)
    fitems = attrs(page, faq_id).get("items", [])
    check("faq: question added", fitems and fitems[-1].get("q") == "Вопрос из браузера?", fitems[-1] if fitems else None)
    check("faq: rich answer with <strong>", fitems and "<strong>жирным</strong>" in fitems[-1].get("a", ""), fitems[-1].get("a") if fitems else None)

    # Таймер: дата в боковой панели
    select_block(page, cd_id)
    dt = page.locator(".block-editor-block-inspector input[type='datetime-local']")
    check("countdown: start and end date fields (1.6.0)", dt.count() == 2, dt.count())
    if dt.count():
        dt.first.fill("2031-05-15T09:00")
        time.sleep(0.4)
    check("countdown: date set from sidebar", attrs(page, cd_id).get("date") == "2031-05-15 09:00", attrs(page, cd_id).get("date"))
    check("countdown: date shown on canvas", "15.05.2031" in cv.locator(".wpd-page--countdown .wpd-countdown__value").first.inner_text())
    check("countdown: canvas date in Moscow time (1.6.1)", cv.locator(".wpd-page--countdown .wpd-countdown__value").first.inner_text().strip().endswith("09:00 МСК"), cv.locator(".wpd-page--countdown .wpd-countdown__value").first.inner_text())
    check("countdown: timezone hint in sidebar (1.6.1)", page.get_by_text("Часовой пояс: Москва (МСК, UTC+3)", exact=False).count() >= 1)
    # 1.6.0: дата окончания и кнопка на время этапа
    if dt.count() > 1:
        dt.nth(1).fill("2031-05-16T18:00")
        time.sleep(0.4)
    check("countdown: end date set from sidebar", attrs(page, cd_id).get("end") == "2031-05-16 18:00", attrs(page, cd_id).get("end"))
    check("countdown: end timer shown on canvas", "16.05.2031" in cv.locator(".wpd-page--countdown .wpd-ed-countdown-end").inner_text())
    panel_btn = page.locator(".block-editor-block-inspector button", has_text="Кнопка на время этапа")
    if panel_btn.count() and panel_btn.first.get_attribute("aria-expanded") == "false":
        panel_btn.first.click()
        time.sleep(0.3)
    page.locator(".block-editor-block-inspector input[type='url']").first.fill("https://example.com/tasks")
    time.sleep(0.4)
    check("countdown: link url set", attrs(page, cd_id).get("linkUrl") == "https://example.com/tasks", attrs(page, cd_id).get("linkUrl"))
    link_input = cv.locator(".wpd-page--countdown .wpd-countdown__link input")
    check("countdown: button preview on canvas", link_input.count() == 1)
    if link_input.count():
        link_input.fill("К заданиям")
        time.sleep(0.3)
    check("countdown: button text edited on canvas", attrs(page, cd_id).get("linkText") == "К заданиям", attrs(page, cd_id).get("linkText"))

    # Сброс к общим настройкам (на копии: вставим ещё один hero, изменим и сбросим)
    extra = page.evaluate("() => { const b = wp.blocks.createBlock('wpd/hero', { title: 'Временный' }); wp.data.dispatch('core/block-editor').insertBlocks(b); return b.clientId; }")
    select_block(page, extra)
    page.locator(".block-editor-block-inspector button", has_text="Значения по умолчанию").click()
    time.sleep(0.3)
    page.locator(".block-editor-block-inspector button", has_text="Вернуть значения из настроек").click()
    time.sleep(0.4)
    check("reset: block returns to settings defaults", attrs(page, extra).get("title") == default_title, attrs(page, extra).get("title"))
    page.evaluate("id => wp.data.dispatch('core/block-editor').removeBlock(id)", extra)

    # Сериализация: неизменённые поля не сохраняются
    content = page.evaluate("() => wp.data.select('core/editor').getEditedPostContent()")
    hero_comment = re.search(r"<!-- wp:wpd/hero (\{.*?\}) /-->", content)
    hero_json = json.loads(hero_comment.group(1)) if hero_comment else {}
    check("serialize: changed hero fields stored", hero_json.get("title") == "Заголовок из браузера" and hero_json.get("variant") == "light", hero_json)
    check("serialize: untouched hero fields omitted", "lead" not in hero_json and "buttonText" not in hero_json, hero_json)
    check("serialize: blocks are dynamic (self-closing)", "<!-- wp:wpd/faq " in content and "<!-- /wp:wpd/" not in content)

    save(page)
    post_id = page.evaluate("() => wp.data.select('core/editor').getCurrentPostId()")
    page.evaluate("() => wp.data.dispatch('core/editor').editPost({ status: 'publish' })")
    save(page)
    check("editor: post saved without errors", page.evaluate("() => !wp.data.select('core/editor').didPostSaveRequestFail()"))

    front = fetch(f"{BASE}/?page_id={post_id}")
    check("front: hero title from editor", 'wpd-hero__title">Заголовок из браузера<' in front)
    check("front: badge from editor", ">Финал 2027<" in front)
    check("front: light hero", "wpd-hero--light" in front)
    check("front: second button (1.8.0)", 'class="wpd-btn wpd-btn--ghost" href="https://example.com/archive" target="_blank" rel="noopener noreferrer">Задания прошлых лет</a>' in front)
    check("front: background image, tall", '--wpd-hero-img:url(&quot;https://example.com/hero.jpg&quot;)' in front and "wpd-hero--height-tall" in front and "wpd-hero--img-background" in front)
    check("front: hero align wide", "wpd-page--hero alignwide" in front)
    # Дата таймера в общих настройках не задана — таймера внутри блока на сайте нет (подсказка только в редакторе)
    check("front: icon on button, min height (1.9.0)", re.search(r'<a class="wpd-btn" href="https://example.com/rules"><span class="wpd-btn__icon dashicons dashicons-awards" aria-hidden="true"></span>', front) is not None and "--wpd-hero-minh:520px" in front)
    check("front: no hero timer without a date in settings", 'class="wpd-hero__countdown"' not in front)
    check("front: new stat card", ">999+<" in front and "карточка из браузера" in front)
    check("front: new document", "https://example.com/final.pdf" in front and "Регламент финала" in front)
    check("front: faq rich answer", "<strong>жирным</strong>" in front and "Вопрос из браузера?" in front)
    check("front: countdown end and button (1.6.0)", 'data-end="2031-05-16T18:00' in front and 'href="https://example.com/tasks"' in front and "К заданиям" in front)
    check("front: button hidden before start", re.search(r'class="wpd-btn wpd-countdown__link"[^>]*hidden', front) is not None)
    check("front: countdown date (Moscow, +03:00)", 'data-target="2031-05-15T09:00:00+03:00"' in front)
    check("front: css linked", "wpd.css" in front)

    # Повторное открытие: значения на месте
    page.goto(f"{BASE}/wp-admin/post.php?post={post_id}&action=edit", timeout=180000)
    wait_editor(page)
    time.sleep(1.5)
    check("reopen: hero title persisted", canvas(page).locator(".wpd-page--hero .wpd-hero__title textarea").input_value() == "Заголовок из браузера")
    page.screenshot(path=os.path.join(SHOTS, "editor-reopen.png"), full_page=False)

    # ---------- 2. Панель «Блоки олимпиады» ----------
    page.goto(BASE + "/wp-admin/post-new.php?post_type=page", timeout=180000)
    wait_editor(page)
    page.evaluate("() => wp.data.dispatch('core/editor').editPost({ title: 'Страница с панелью из браузера', status: 'publish', content: '<!-- wp:paragraph --><p>Основной текст</p><!-- /wp:paragraph -->' })")
    time.sleep(0.5)
    panel = page.locator("#wpd_page_blocks")
    # В новых версиях область мета-боксов внизу редактора свёрнута — разворачиваем
    if not panel.is_visible():
        toggle = page.locator("button", has_text="Meta Boxes").first
        if not toggle.count():
            toggle = page.locator(".edit-post-meta-boxes-main__presenter button, .edit-post-meta-boxes-main button").first
        if toggle.count():
            # По центру кнопки лежит ручка изменения высоты — нажимаем с клавиатуры
            toggle.focus()
            page.keyboard.press("Enter")
            time.sleep(1.5)
    page.screenshot(path=os.path.join(SHOTS, "panel-area.png"))
    panel.scroll_into_view_if_needed()
    check("panel: visible in block editor", panel.is_visible())
    # Включаем оформление (боковой мета-бокс)
    enable = page.locator("input[name='wpd_enabled']")
    if enable.count():
        enable.check(force=True)
    time.sleep(0.3)
    check("panel: off-notice hides after enabling", page.locator("[data-wpd-off]").is_hidden())

    # Перенести FAQ на самый верх стрелками
    order_js = "() => [...document.querySelectorAll('.wpd-pe__block')].map(li => li.dataset.key)"
    for _ in range(12):
        if page.evaluate(order_js)[0] == "faq":
            break
        page.locator(".wpd-pe__block[data-key='faq'] [data-wpd-move='-1']").click()
    order = page.evaluate(order_js)
    check("panel: arrows reorder blocks", order[0] == "faq", order)
    # Скрыть документы
    page.locator("input[name='wpd_page[show][docs]']").uncheck()
    check("panel: hidden block dimmed", "is-hidden" in (page.locator(".wpd-pe__block[data-key='docs']").get_attribute("class") or ""))

    # Свой заголовок главного блока
    page.fill("input[name='wpd_page[hero][title]']", "Заголовок из панели")
    page.select_option("select[name='wpd_page[hero][style]']", "gradient")

    # Свои карточки статистики: режим custom создаёт пустую строку
    stats_panel = page.locator("details.wpd-pe__panel", has_text="Статистика").first
    stats_panel.locator("summary").click()
    page.locator("input[name='wpd_page[stats_mode]'][value='custom']").check()
    time.sleep(0.3)
    rows = page.locator("[data-wpd-list='stats'] .wpd-pe__row")
    check("panel: custom mode adds first row", rows.count() == 1, rows.count())
    rows.nth(0).locator("[data-wpd-field='num']").fill("77")
    rows.nth(0).locator("[data-wpd-field='label']").fill("строка из панели")
    page.locator("[data-wpd-add='stats']").click()
    rows.nth(1).locator("[data-wpd-field='num']").fill("88")
    rows.nth(1).locator("[data-wpd-field='label']").fill("вторая строка")
    # Вторая строка — вверх
    rows.nth(1).locator("[data-wpd-row-move='-1']").click()
    first_num = page.locator("[data-wpd-list='stats'] .wpd-pe__row").nth(0).locator("[data-wpd-field='num']").input_value()
    check("panel: row moved up", first_num == "88", first_num)

    # FAQ: скопировать из общих настроек
    faq_panel = page.locator("details.wpd-pe__panel", has_text="Частые вопросы").first
    faq_panel.locator("summary").click()
    page.locator("input[name='wpd_page[faq_mode]'][value='custom']").check()
    page.once("dialog", lambda d: d.accept())
    page.locator("[data-wpd-copy='faq']").click()
    time.sleep(0.3)
    faq_rows = page.locator("[data-wpd-list='faq'] .wpd-pe__row").count()
    check("panel: copy from settings fills rows", faq_rows >= 1, faq_rows)
    page.locator("[data-wpd-list='faq'] .wpd-pe__row").first.locator("[data-wpd-field='q']").fill("Вопрос страницы?")
    panel.screenshot(path=os.path.join(SHOTS, "panel.png"))

    save(page)
    pid2 = page.evaluate("() => wp.data.select('core/editor').getCurrentPostId()")
    time.sleep(1)
    front2 = fetch(f"{BASE}/?page_id={pid2}")
    check("panel front: designed", 'class="wpd-page"' in front2)
    check("panel front: custom hero title", 'wpd-hero__title">Заголовок из панели<' in front2)
    check("panel front: gradient", "wpd-hero--gradient" in front2)
    check("panel front: faq first", 0 <= front2.find('class="wpd-faq') < front2.find('class="wpd-hero'))
    check("panel front: docs hidden", 'class="wpd-docs' not in front2)
    check("panel front: custom stats in order", 0 <= front2.find(">88<") < front2.find(">77<"))
    check("panel front: custom faq question", "Вопрос страницы?" in front2)
    check("panel front: post content kept", "Основной текст" in front2)

    # Перезагрузка: панель показывает сохранённое
    page.goto(f"{BASE}/wp-admin/post.php?post={pid2}&action=edit", timeout=180000)
    wait_editor(page)
    order2 = page.evaluate("() => [...document.querySelectorAll('.wpd-pe__block')].map(li => li.dataset.key)")
    check("panel reopen: order persisted", order2[0] == "faq", order2)
    check("panel reopen: hero title persisted", page.locator("input[name='wpd_page[hero][title]']").input_value() == "Заголовок из панели")
    check("panel reopen: 2 stat rows", page.locator("[data-wpd-list='stats'] .wpd-pe__row").count() == 2)

    # Сайт: скриншот оформленной страницы
    page.goto(f"{BASE}/?page_id={pid2}", timeout=120000)
    page.wait_for_load_state("networkidle")
    page.screenshot(path=os.path.join(SHOTS, "front-panel.png"), full_page=True)
    page.goto(f"{BASE}/?page_id={post_id}", timeout=120000)
    page.wait_for_load_state("networkidle")
    page.screenshot(path=os.path.join(SHOTS, "front-blocks.png"), full_page=True)

    check("no JavaScript errors", not js_errors, js_errors[:5])
    browser.close()

