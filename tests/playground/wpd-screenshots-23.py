"""Скриншоты стиля «Мягкий градиент» (2.3.0).

Запуск: WPD_BASE=http://127.0.0.1:9400 python3 wpd-screenshots-23.py <папка>
Перед запуском: curl -sL "$WPD_BASE/wpd-q-page.php?style=soft" (страница /olimpiada-q/).
Анимации отключены (reduced_motion), чтобы кадры были одинаковыми.
"""
import os
import sys

from playwright.sync_api import sync_playwright

BASE = os.environ.get("WPD_BASE", "http://127.0.0.1:9400")
OUT = sys.argv[1] if len(sys.argv) > 1 else "shots"
os.makedirs(OUT, exist_ok=True)

SHOTS = [
    ("soft-desktop.png", {"width": 1280, "height": 900}, True),
    ("soft-desktop-top.png", {"width": 1280, "height": 900}, False),
    ("soft-mobile.png", {"width": 390, "height": 844}, True),
]

with sync_playwright() as p:
    browser = p.chromium.launch(args=["--disable-dev-shm-usage", "--no-sandbox"])
    for name, viewport, full in SHOTS:
        page = browser.new_page(viewport=viewport, reduced_motion="reduce")
        page.goto(BASE + "/olimpiada-q/", wait_until="networkidle")
        page.wait_for_timeout(400)
        page.screenshot(path=os.path.join(OUT, name), full_page=full)
        print("SHOT", name)
        page.close()
    browser.close()
