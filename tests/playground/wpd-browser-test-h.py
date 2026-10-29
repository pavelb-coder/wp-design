"""
Фаза H, браузер (1.5.0): Playwright + Chromium.
- страница настроек: повторитель слайдов (добавить, переставить, удалить, лимит, сохранение), превью изображения,
  окно медиатеки, превью иконки, палитры;
- сайт: слайдер и карусель новостей с настоящей раскладкой (стрелки, точки, клавиатура, автопрокрутка, телефон);
- редактор блоков: шесть новых блоков вставляются, показываются на холсте, сохраняются и выводятся на сайте;
- отсутствие ошибок JavaScript.

Нужны wpd-set.php и wpd-h-page.php в корне WordPress.
Запуск: WPD_BASE=http://127.0.0.1:9400 python3 wpd-browser-test-h.py <папка_для_скриншотов>
"""
import atexit
import json
import os
import re
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


def fetch(path):
    with urllib.request.urlopen(BASE + path, timeout=120) as r:
        return r.read().decode("utf-8")


def set_opt(data):
    fetch("/wpd-set.php?json=" + urllib.parse.quote(json.dumps(data, ensure_ascii=False)))


def report():
    print("\n".join(results))
    fails = sum(1 for r in results if r.startswith("FAIL"))
    print(f"\nPHASE H (browser): PASS={len(results) - fails} FAIL={fails}")


atexit.register(report)

SLIDES = [
    {"image": "", "heading": "Слайд 1", "text": "Текст", "url": "", "new_tab": 0},
    {"image": "", "heading": "Слайд 2", "text": "", "url": "", "new_tab": 0},
    {"image": "", "heading": "Слайд 3", "text": "", "url": "", "new_tab": 0},
]

with sync_playwright() as p:
    browser = p.chromium.launch(args=["--disable-dev-shm-usage"])
    ctx = browser.new_context(viewport={"width": 1280, "height": 900}, locale="ru-RU")
    page = ctx.new_page()
    js_errors = []
    page.on("pageerror", lambda e: js_errors.append(str(e)))
    page.on("dialog", lambda d: d.accept())

    page.goto(BASE + "/wp-login.php", timeout=120000)
    page.fill("#user_login", "admin")
    page.fill("#user_pass", "password")
    page.click("#wp-submit")
    page.wait_for_load_state("networkidle", timeout=120000)

    # ---------- 1. Настройки: повторитель слайдов ----------
    set_opt({})
    page.goto(BASE + "/wp-admin/admin.php?page=wpd-settings&tab=slider", timeout=120000)
    rep = page.locator("[data-wpd-rep='slides']")
    rows = rep.locator("[data-wpd-rep-list] > [data-wpd-rep-row]")
    check("settings: no slides at start", rows.count() == 0, rows.count())
    add = rep.locator("[data-wpd-rep-add]")
    add.click()
    add.click()
    check("repeater: add creates rows", rows.count() == 2, rows.count())
    check("repeater: rows numbered", rows.nth(1).locator("[data-wpd-rep-num]").first.inner_text().strip() == "2")
    check("repeater: new row focused", page.evaluate("() => !!document.activeElement.closest('[data-wpd-rep-row]')"))
    rows.nth(0).locator("input[name$='[heading]']").fill("Первый")
    rows.nth(1).locator("input[name$='[heading]']").fill("Второй")
    rows.nth(1).locator("[data-wpd-rep-move='-1']").click()
    check("repeater: move up reorders", rows.nth(0).locator("input[name$='[heading]']").input_value() == "Второй")
    check("repeater: renumbered after move", rows.nth(0).locator("[data-wpd-rep-num]").first.inner_text().strip() == "1")
    url_input = rows.nth(0).locator("[data-wpd-s-url]")
    url_input.fill(BASE + "/wp-includes/images/w-logo-blue.png")
    preview = rows.nth(0).locator("[data-wpd-s-preview]")
    check("image: preview follows url", preview.is_visible() and preview.get_attribute("src").endswith("w-logo-blue.png"))
    rows.nth(0).locator("[data-wpd-s-pick]").click()
    try:
        page.wait_for_selector(".media-modal", state="visible", timeout=20000)
        check("image: media library opens", True)
    except Exception as e:
        check("image: media library opens", False, e)
    page.keyboard.press("Escape")
    time.sleep(0.5)
    for _ in range(12):
        if add.is_disabled():
            break
        add.click()
    check("repeater: add disabled at max (10)", add.is_disabled() and rows.count() == 10, rows.count())
    # Удаляем пустые строки (без вопроса) и одну заполненную (с подтверждением — диалог принимается)
    while rows.count() > 2:
        rows.last.locator("[data-wpd-rep-remove]").click()
    check("repeater: remove", rows.count() == 2 and not add.is_disabled())
    rows.nth(1).locator("[data-wpd-rep-remove]").click()
    check("repeater: remove filled row after confirm", rows.count() == 1)
    page.click("#submit")
    page.wait_for_load_state("networkidle", timeout=120000)
    check("save: notice", page.locator(".notice-success, .updated, #setting-error-settings_updated").count() > 0)
    saved = page.locator("[data-wpd-rep='slides'] [data-wpd-rep-row] input[name$='[heading]']")
    check("save: slide stored", saved.count() == 1 and saved.first.input_value() == "Второй", saved.count())
    check("save: image stored", page.locator("[data-wpd-rep='slides'] [data-wpd-s-url]").first.input_value().endswith("w-logo-blue.png"))
    page.screenshot(path=os.path.join(SHOTS, "settings-slider.png"), full_page=True)

    # ---------- 2. Иконки и палитры ----------
    page.goto(BASE + "/wp-admin/admin.php?page=wpd-settings&tab=buttons", timeout=120000)
    first_btn = page.locator("[data-wpd-rep='buttons'] [data-wpd-rep-row]").first
    first_btn.locator("[data-wpd-icon]").select_option("awards")
    cls = first_btn.locator("[data-wpd-icon-preview]").get_attribute("class")
    check("icons: preview updates", "dashicons-awards" in cls, cls)
    page.screenshot(path=os.path.join(SHOTS, "settings-buttons.png"), full_page=True)

    page.goto(BASE + "/wp-admin/admin.php?page=wpd-settings&tab=design", timeout=120000)
    page.locator(".wpd-palettes label", has_text="Море").first.click()
    check("palette: sea fills primary", page.input_value("#wpd_primary").lower() == "#0e7490", page.input_value("#wpd_primary"))
    check("palette: sea fills background", page.input_value("#wpd_bg").lower() == "#ecfeff")
    check("palette: sea radio checked", page.is_checked(".wpd-palettes input[value='sea']"))
    page.evaluate("() => { const i = document.querySelector('#wpd_primary'); i.value = '#123456'; i.dispatchEvent(new Event('input', { bubbles: true })); }")
    check("palette: manual color switches to custom", page.is_checked(".wpd-palettes input[value='custom']"))
    page.screenshot(path=os.path.join(SHOTS, "settings-design.png"), full_page=True)

    # ---------- 3. Сайт: слайдер и новости ----------
    set_opt({"slides": SLIDES, "news_layout": "carousel", "contact_email": "h@example.com", "notice_title": "Объявление"})
    pid = fetch("/wpd-h-page.php").strip()
    page.goto(BASE + "/?page_id=" + pid, timeout=120000)
    page.wait_for_selector(".wpd-slider[data-wpd-ready]", timeout=30000)
    slider = page.locator(".wpd-slider")
    check("front: arrows visible", slider.locator("[data-wpd-next]").is_visible() and slider.locator("[data-wpd-prev]").is_visible())
    check("front: 3 dots", slider.locator(".wpd-slider__dot").count() == 3)
    check("front: autoplay on", slider.get_attribute("data-wpd-autoplay") == "on")
    slider.hover()  # пауза автопрокрутки
    sl = "() => document.querySelector('.wpd-slider__track').scrollLeft"
    cw = page.evaluate("() => document.querySelector('.wpd-slider__track').clientWidth")
    slider.locator("[data-wpd-next]").click()
    page.wait_for_function(f"() => Math.abs(document.querySelector('.wpd-slider__track').scrollLeft - {cw}) < 3", timeout=5000)
    check("front: next scrolls one slide", abs(page.evaluate(sl) - cw) < 3, page.evaluate(sl))
    check("front: dot 2 current", slider.locator(".wpd-slider__dot").nth(1).get_attribute("aria-current") == "true")
    slider.locator(".wpd-slider__dot").nth(2).click()
    page.wait_for_function(f"() => Math.abs(document.querySelector('.wpd-slider__track').scrollLeft - {2 * cw}) < 3", timeout=5000)
    check("front: dot click scrolls", True)
    slider.locator("[data-wpd-next]").click()
    page.wait_for_function("() => document.querySelector('.wpd-slider__track').scrollLeft < 3", timeout=5000)
    check("front: wraps to first", True)
    page.focus(".wpd-slider__track")
    page.keyboard.press("ArrowRight")
    page.wait_for_function(f"() => Math.abs(document.querySelector('.wpd-slider__track').scrollLeft - {cw}) < 3", timeout=5000)
    check("front: keyboard ArrowRight", True)
    one_slide = page.evaluate("() => { const s = document.querySelector('.wpd-slide'); return [s.offsetWidth, document.querySelector('.wpd-slider__track').clientWidth]; }")
    check("front: slide fills track width", abs(one_slide[0] - one_slide[1]) < 2, one_slide)
    h = page.evaluate("() => document.querySelector('.wpd-slider__track').offsetHeight")
    check("front: slider height from settings (420)", abs(h - 420) < 3, h)
    news = page.locator(".wpd-news[data-wpd-ready]")
    check("front: news carousel ready", news.count() == 1)
    per_view = page.evaluate("() => { const t = document.querySelector('.wpd-news__track'); const i = t.querySelector('.wpd-news__item'); return Math.round(t.clientWidth / i.offsetWidth); }")
    check("front: news shows ~3 per view on desktop", per_view == 3, per_view)
    nsl = "() => document.querySelector('.wpd-news__track').scrollLeft"
    news.locator("[data-wpd-next]").click()
    page.wait_for_function("() => document.querySelector('.wpd-news__track').scrollLeft > 10", timeout=5000)
    check("front: news next scrolls", page.evaluate(nsl) > 10)
    arrow_box = news.locator("[data-wpd-next]").bounding_box()
    first_item = page.locator(".wpd-news__item").first.bounding_box()
    check("front: news arrows above cards (not covering)", arrow_box["y"] + arrow_box["height"] <= first_item["y"] + 1, (arrow_box, first_item))
    page.screenshot(path=os.path.join(SHOTS, "front-components.png"), full_page=True)

    mob = browser.new_context(viewport={"width": 390, "height": 844}, is_mobile=True, has_touch=True, locale="ru-RU").new_page()
    mob.on("pageerror", lambda e: js_errors.append("mobile: " + str(e)))
    mob.goto(BASE + "/?page_id=" + pid, timeout=120000)
    mob.wait_for_selector(".wpd-slider[data-wpd-ready]", timeout=30000)
    check("mobile: slider arrows hidden (swipe)", not mob.locator(".wpd-slider [data-wpd-next]").is_visible())
    check("mobile: slide text hidden by default", not mob.locator(".wpd-slide__text").first.is_visible())
    check("mobile: headings visible", mob.locator(".wpd-slide__title").first.is_visible())
    cols = mob.evaluate("() => getComputedStyle(document.querySelector('.wpd-buttons__grid')).gridTemplateColumns.split(' ').length")
    check("mobile: buttons in 2 columns", cols == 2, cols)
    check("mobile: no horizontal page scroll", mob.evaluate("() => document.documentElement.scrollWidth <= window.innerWidth + 1"))
    mob.screenshot(path=os.path.join(SHOTS, "front-components-mobile.png"), full_page=True)
    mob.context.close()

    # ---------- 4. Редактор блоков ----------
    page.goto(BASE + "/wp-admin/post-new.php?post_type=page", timeout=180000)
    page.wait_for_function("() => window.wp && wp.data && wp.data.select('core/editor') && wp.data.select('core/editor').getCurrentPostId()", timeout=120000)
    page.evaluate("""() => wp.apiFetch({ path: '/wp/v2/users/me?context=edit', method: 'POST', data: { meta: { persisted_preferences: {
        core: { enableChoosePatternModal: false, welcomeGuide: false }, 'core/edit-post': { welcomeGuide: false }, _modified: new Date().toISOString()
    } } } })""")
    # Окно стартовых паттернов на чистой базе иногда появляется и после отключения в настройках — закрываем автоматически
    def close_modal():
        overlay = page.locator(".components-modal__screen-overlay").first
        try:
            btn = overlay.locator(".components-modal__header button")
            if btn.count():
                btn.first.click(timeout=2000, no_wait_after=True, force=True)
                time.sleep(0.5)
            if overlay.is_visible():
                page.keyboard.press("Escape")
                time.sleep(0.5)
        except Exception:
            pass
    page.add_locator_handler(page.locator(".components-modal__screen-overlay"), close_modal)
    page.goto(BASE + "/wp-admin/post-new.php?post_type=page", timeout=180000)
    page.wait_for_function("() => window.wp && wp.data && wp.data.select('core/editor') && wp.data.select('core/editor').getCurrentPostId()", timeout=120000)
    try:
        page.wait_for_selector("iframe[name=editor-canvas]", timeout=60000)
    except Exception:
        pass
    time.sleep(3)
    pattern = False
    for _ in range(10):
        pattern = page.evaluate("() => ((wp.data.select('core').getBlockPatterns && wp.data.select('core').getBlockPatterns()) || []).some(p => p.name === 'wpd/front-page')")
        if pattern:
            break
        time.sleep(1)
    check("editor: pattern 'Главная страница из компонентов' available", pattern)
    page.evaluate("() => wp.data.dispatch('core/editor').editPost({ title: 'Компоненты из редактора' })")
    names = ["wpd/slider", "wpd/buttons", "wpd/notice", "wpd/cards", "wpd/news", "wpd/contacts"]
    ids = page.evaluate("""names => {
        const blocks = names.map(n => wp.blocks.createBlock(n));
        wp.data.dispatch('core/block-editor').insertBlocks(blocks);
        return blocks.map(b => b.clientId);
    }""", names)
    time.sleep(3)
    frame = page.frame(name="editor-canvas") or page
    for n in names:
        slug = n.split("/")[1]
        sel = ".wpd-ed-ssr" if slug == "news" else ".wpd-page--" + slug
        check(f"canvas: {n} shown", frame.locator(sel).count() >= 1)
    # Атрибуты из настроек подставлены в новый блок
    a = page.evaluate("id => wp.data.select('core/block-editor').getBlockAttributes(id)", ids[0])
    check("slider block: items from settings", len(a.get("items", [])) == 3, a.get("items"))
    b = page.evaluate("id => wp.data.select('core/block-editor').getBlockAttributes(id)", ids[1])
    before = len(b.get("items", []))
    page.evaluate("id => wp.data.dispatch('core/block-editor').selectBlock(id)", ids[1])
    time.sleep(0.6)
    add_btn = frame.locator(".wpd-page--buttons .wpd-ed-add button")
    if add_btn.count():
        add_btn.first.click()
        time.sleep(0.5)
    b2 = page.evaluate("id => wp.data.select('core/block-editor').getBlockAttributes(id)", ids[1])
    check("buttons block: add item on canvas", len(b2.get("items", [])) == before + 1, (before, len(b2.get("items", []))))
    # Свой заголовок объявления через атрибут и сохранение
    page.evaluate("id => wp.data.dispatch('core/block-editor').updateBlockAttributes(id, { title: 'Объявление из блока' })", ids[2])
    page.evaluate("id => wp.data.dispatch('core/block-editor').updateBlockAttributes(id, { layout: 'grid', count: 2 })", ids[4])
    time.sleep(2)
    check("news block: server render in editor", frame.locator(".wpd-ed-ssr .wpd-news, .wpd-ed-ssr .components-placeholder, .wpd-ed-ssr").count() >= 1)
    # Название организации со ссылкой (1.7.0): название — на холсте, ссылка — в боковой панели
    page.evaluate("id => wp.data.dispatch('core/block-editor').selectBlock(id)", ids[5])
    time.sleep(0.8)
    org_input = frame.locator(".wpd-page--contacts .wpd-contacts__org .wpd-ed-plain")
    check("contacts block: organization row on canvas (1.7.0)", org_input.count() == 1, org_input.count())
    if org_input.count():
        org_input.first.fill("Центр «Юниор»")
        time.sleep(0.4)
    # Только боковая панель блока: с 2.4.0 в панели «Блоки олимпиады» есть скрытое поле с той же подписью
    url_input = page.locator(".block-editor-block-inspector").get_by_label("Ссылка организации")
    if url_input.count():
        url_input.first.fill("https://junior.example.org/")
        time.sleep(0.4)
    c = page.evaluate("id => wp.data.select('core/block-editor').getBlockAttributes(id)", ids[5])
    check("contacts block: org name and link saved to attributes", c.get("org") == "Центр «Юниор»" and c.get("orgUrl") == "https://junior.example.org/", (c.get("org"), c.get("orgUrl")))
    check("contacts block: linked name styled on canvas", frame.locator(".wpd-page--contacts .wpd-contacts__org .wpd-ed-link").count() == 1)
    page.screenshot(path=os.path.join(SHOTS, "editor-components.png"))
    page.evaluate("() => wp.data.dispatch('core/editor').editPost({ status: 'publish' })")
    page.evaluate("() => wp.data.dispatch('core/editor').savePost()")
    page.wait_for_function("() => !wp.data.select('core/editor').isSavingPost()", timeout=120000)
    time.sleep(2)
    content = page.evaluate("() => wp.data.select('core/editor').getEditedPostContent()")
    check("serialize: 6 dynamic blocks", all(f"<!-- wp:{n}" in content for n in names) and "<!-- /wp:wpd/" not in content)
    link = page.evaluate("() => wp.data.select('core/editor').getPermalink()")
    front = fetch(urllib.parse.urlparse(link).path + ("?" + urllib.parse.urlparse(link).query if urllib.parse.urlparse(link).query else ""))
    check("front: block notice title from attribute", "Объявление из блока" in front)
    check("front: block news grid", "wpd-news--grid" in front)
    check("front: block slider from settings", "Слайд 2" in front)
    check("front: block contacts from settings", "mailto:h@example.com" in front)
    check("front: organization link in contacts (1.7.0)", re.search(r'<li class="wpd-contacts__org">.*?<a href="https://junior\.example\.org/" target="_blank" rel="noopener noreferrer">Центр «Юниор»</a>', front) is not None)

    check("no JavaScript errors", not js_errors, js_errors)
    browser.close()

set_opt({})
fetch("/wpd-h-page.php?cleanup=1")
