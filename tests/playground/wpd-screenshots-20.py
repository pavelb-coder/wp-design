#!/usr/bin/env python3
# Скриншоты 2.0.0 «Регистрация участников»: форма, ошибки, личный кабинет, список заявок, карточка, конструктор полей, телефон.
# Нужны wpd-n-helper.php в корне WordPress и pip-пакет playwright. Запуск: WPD_BASE=http://127.0.0.1:9400 python3 wpd-screenshots-20.py ./screenshots
import json, os, sys, time, urllib.parse, urllib.request
from playwright.sync_api import sync_playwright

BASE = os.environ.get("WPD_BASE", "http://127.0.0.1:9400")
SHOTS = sys.argv[1] if len(sys.argv) > 1 else "screenshots"
os.makedirs(SHOTS, exist_ok=True)


def helper(q):
    with urllib.request.urlopen(f"{BASE}/wpd-n-helper.php?{q}", timeout=120) as r:
        return json.loads(r.read().decode("utf-8") or "null")


helper("cleanup=1")
pages = helper("setup=1")
helper("opt=" + urllib.parse.quote(json.dumps({"limit": 150, "privacy_url": "https://example.com/privacy",
                                                "intro": "Заполните заявку — после отправки откроется личный кабинет, ссылка для входа придёт на e-mail."})))
FORM, CAB = pages["form"], pages["cabinet"]

with sync_playwright() as p:
    browser = p.chromium.launch(args=["--disable-dev-shm-usage"])
    ctx = browser.new_context(viewport={"width": 1280, "height": 900}, reduced_motion="reduce")
    page = ctx.new_page()

    # Гость: форма
    page.goto(FORM, timeout=120000)
    page.wait_for_load_state("networkidle")
    page.locator(".wpd-page--reg").screenshot(path=os.path.join(SHOTS, "front-registration.png"))

    # Ошибки: отключаем проверку браузера, чтобы показать серверные сообщения
    time.sleep(3.5)
    page.fill("#wpd-reg-name", "Иванов Иван")
    page.fill("#wpd-reg-email", "ivanov@mail")
    page.fill("#wpd-reg-phone", "12-34")
    page.evaluate("() => { document.querySelector('.wpd-reg__form').noValidate = true; }")
    page.click(".wpd-reg__form button[type=submit]")
    page.wait_for_load_state("networkidle")
    page.locator(".wpd-page--reg").screenshot(path=os.path.join(SHOTS, "front-registration-errors.png"))

    # Правильная заявка → кабинет
    page.goto(FORM, timeout=120000)
    time.sleep(3.5)
    page.fill("#wpd-reg-name", "Иванова Мария Сергеевна")
    page.fill("#wpd-reg-email", "maria@n-test.ru")
    page.fill("#wpd-reg-phone", "+7 913 555-12-34")
    page.select_option("#wpd-reg-grade", "10")
    page.fill("#wpd-reg-school", "Лицей № 7")
    page.fill("#wpd-reg-city", "Красноярск")
    page.check("input[name=wpd_consent]")
    page.click(".wpd-reg__form button[type=submit]")
    page.wait_for_url("**wpd_reg=done**", timeout=120000)
    page.wait_for_load_state("networkidle")
    page.locator(".wpd-page--reg").screenshot(path=os.path.join(SHOTS, "front-cabinet.png"))

    # Телефон: форма
    mob = browser.new_context(viewport={"width": 390, "height": 844}, device_scale_factor=2, reduced_motion="reduce")
    mp = mob.new_page()
    mp.goto(FORM, timeout=120000)
    mp.wait_for_load_state("networkidle")
    mp.locator(".wpd-page--reg").screenshot(path=os.path.join(SHOTS, "front-registration-mobile.png"))
    mob.close()

    # Ещё несколько заявок для списка
    for i, (name, mail, st) in enumerate([("Петров Алексей", "petrov@n-test.ru", "approved"), ("Сидорова Анна", "sidorova@n-test.ru", "new"),
                                          ("Ким Даниил", "kim@n-test.ru", "rejected")]):
        g = browser.new_context(viewport={"width": 1280, "height": 900})
        gp = g.new_page()
        gp.goto(FORM, timeout=120000)
        time.sleep(3.5)
        gp.fill("#wpd-reg-name", name)
        gp.fill("#wpd-reg-email", mail)
        gp.fill("#wpd-reg-phone", f"+7 900 000-00-0{i}")
        gp.select_option("#wpd-reg-grade", str(9 + i % 3))
        gp.fill("#wpd-reg-school", ["Гимназия № 2", "СОШ № 10", "Лицей № 1"][i])
        gp.fill("#wpd-reg-city", ["Ачинск", "Канск", "Норильск"][i])
        gp.check("input[name=wpd_consent]")
        gp.click(".wpd-reg__form button[type=submit]")
        gp.wait_for_url("**wpd_reg=done**", timeout=120000)
        g.close()
        helper("rl_clear=1")

    # Администратор
    admin = browser.new_context(viewport={"width": 1400, "height": 900}, reduced_motion="reduce")
    ap = admin.new_page()
    ap.goto(f"{BASE}/wp-login.php", timeout=120000)
    ap.fill("#user_login", "admin")
    ap.fill("#user_pass", "password")
    ap.click("#wp-submit")
    ap.wait_for_load_state("networkidle")
    apps = helper("apps=1")
    for a in apps:
        if a["email"] == "petrov@n-test.ru":
            ap.goto(f"{BASE}/wp-admin/edit.php?post_type=wpd_application", timeout=120000)
            href = ap.locator(f"#post-{a['id']} .wpd_approved a").get_attribute("href")
            ap.goto(href, timeout=120000)
        if a["email"] == "kim@n-test.ru":
            ap.goto(f"{BASE}/wp-admin/edit.php?post_type=wpd_application", timeout=120000)
            href = ap.locator(f"#post-{a['id']} .wpd_rejected a").get_attribute("href")
            ap.goto(href, timeout=120000)
    ap.goto(f"{BASE}/wp-admin/edit.php?post_type=wpd_application", timeout=120000)
    ap.wait_for_load_state("networkidle")
    ap.locator("#post-" + str(apps[0]["id"])).hover()
    ap.screenshot(path=os.path.join(SHOTS, "admin-participants.png"), clip={"x": 0, "y": 0, "width": 1400, "height": 560})
    ap.goto(f"{BASE}/wp-admin/post.php?post={apps[0]['id']}&action=edit", timeout=120000)
    ap.wait_for_load_state("networkidle")
    ap.screenshot(path=os.path.join(SHOTS, "admin-application.png"), clip={"x": 0, "y": 0, "width": 1400, "height": 760})
    ap.goto(f"{BASE}/wp-admin/admin.php?page=wpd-registration&tab=fields", timeout=120000)
    ap.wait_for_load_state("networkidle")
    ap.locator(".wpd-reg-settings").screenshot(path=os.path.join(SHOTS, "settings-registration-fields.png"))
    ap.goto(f"{BASE}/wp-admin/admin.php?page=wpd-registration", timeout=120000)
    ap.wait_for_load_state("networkidle")
    ap.locator(".wpd-reg-settings").screenshot(path=os.path.join(SHOTS, "settings-registration.png"))
    admin.close()
    browser.close()

helper("cleanup=1")
print("SHOTS DONE")
