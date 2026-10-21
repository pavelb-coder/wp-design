#!/usr/bin/env python3
# Скриншоты 2.1.0 «Пароль при регистрации»: поля пароля в форме (с подсказкой и «Показать пароль»), ошибка пароля,
# смена пароля в личном кабинете. Нужны wpd-n-helper.php в корне WordPress и pip-пакет playwright.
# Запуск: WPD_BASE=http://127.0.0.1:9400 python3 wpd-screenshots-21.py ./screenshots
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
helper("opt=" + urllib.parse.quote(json.dumps({"privacy_url": "https://example.com/privacy",
                                                "intro": "Заполните заявку и придумайте пароль — с ним вы войдёте в личный кабинет."})))
FORM, CAB = pages["form"], pages["cabinet"]
ok = 0

with sync_playwright() as p:
    browser = p.chromium.launch(args=["--disable-dev-shm-usage"])
    ctx = browser.new_context(viewport={"width": 1280, "height": 900}, reduced_motion="reduce")
    page = ctx.new_page()
    page.goto(FORM, timeout=120000)
    page.wait_for_load_state("networkidle")
    assert page.locator("[data-wpd-showpass]").is_visible(), "toggle visible with JS"
    w_pass = page.locator("#wpd-reg-pass").bounding_box()["width"]
    w_name = page.locator("#wpd-reg-name").bounding_box()["width"]
    assert abs(w_pass - w_name) < 2 and page.locator("#wpd-reg-pass").bounding_box()["height"] > 40, f"password styled {w_pass} vs {w_name}"
    time.sleep(3.5)
    page.fill("#wpd-reg-name", "Иванова Мария Сергеевна")
    page.fill("#wpd-reg-email", "maria@n-test.ru")
    page.fill("#wpd-reg-phone", "+7 913 555-12-34")
    page.select_option("#wpd-reg-grade", "10")
    page.fill("#wpd-reg-school", "Лицей № 7")
    page.fill("#wpd-reg-city", "Красноярск")
    page.fill("#wpd-reg-pass", "Mariya-2026")
    page.fill("#wpd-reg-pass2", "Mariya")
    page.locator(".wpd-reg__pass").scroll_into_view_if_needed()
    page.locator(".wpd-page--reg").screenshot(path=os.path.join(SHOTS, "front-registration-password.png"))
    ok += 1

    # Ошибка: пароли не совпадают (серверная проверка; браузерная отключена — повтор короче minlength)
    page.check("input[name=wpd_consent]")
    page.evaluate("() => { document.querySelector('.wpd-reg__form').noValidate = true; }")
    page.click(".wpd-reg__form button[type=submit]")
    page.wait_for_load_state("networkidle")
    assert page.locator("#wpd-reg-pass2-err").inner_text() == "Пароли не совпадают."
    assert page.input_value("#wpd-reg-pass") == "" and page.input_value("#wpd-reg-school") == "Лицей № 7"
    ok += 1
    time.sleep(3.5)  # новая метка времени формы: защита «слишком быстро»
    page.fill("#wpd-reg-pass", "Mariya-2026")
    page.fill("#wpd-reg-pass2", "Mariya-2026")
    page.check("[data-wpd-showpass] input")
    assert page.get_attribute("#wpd-reg-pass", "type") == "text"
    page.check("input[name=wpd_consent]")
    page.click(".wpd-reg__form button[type=submit]")
    page.wait_for_url("**wpd_reg=done**", timeout=120000)
    ok += 1

    # Смена пароля в кабинете
    page.click("text=Сменить пароль")
    page.wait_for_load_state("networkidle")
    page.fill("#wpd-reg-pass-current", "Mariya-2026")
    page.fill("#wpd-reg-pass", "Novyi-2027")
    page.fill("#wpd-reg-pass2", "Novyi-2027")
    page.locator(".wpd-page--reg").screenshot(path=os.path.join(SHOTS, "front-cabinet-password.png"))
    page.click(".wpd-cabinet__pass button[type=submit]")
    page.wait_for_url("**wpd_reg=pass_saved**", timeout=120000)
    assert page.locator(".wpd-reg__alert--success").first.inner_text().strip() == "Пароль изменён."
    ok += 1
    browser.close()

helper("cleanup=1")
print(f"SHOTS_DONE {ok}/4")
