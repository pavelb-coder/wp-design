import json, os, sys, time, urllib.parse, urllib.request, datetime
# Скриншоты 1.9.0: python3 wpd-screenshots-19.py ./shots (нужны hero19.svg, wpd-set.php и wpd-h-page.php в корне WordPress)
from playwright.sync_api import sync_playwright
from PIL import Image
BASE = "http://127.0.0.1:9400"; OUT = sys.argv[1]
def set_opt(d):
    urllib.request.urlopen(BASE + "/wpd-set.php?json=" + urllib.parse.quote(json.dumps(d, ensure_ascii=False)), timeout=60).read()
future = (datetime.datetime.now(datetime.timezone.utc) + datetime.timedelta(hours=3, days=12, minutes=37)).strftime("%Y-%m-%d %H:%M")
opt = {
  "badge": "Олимпиада 2026/27", "hero_title": "Региональная олимпиада школьников", "hero_text": "Задания для 5–11 классов, онлайн-тур и очный финал. Регистрация открыта до 15 ноября.",
  "hero_button_text": "Положение", "hero_button_url": "https://example.com/rules", "hero_button_icon": "media-document",
  "hero_button2_text": "Задания прошлых лет", "hero_button2_url": "https://example.com/archive", "hero_button2_icon": "awards",
  "hero_countdown": 1, "countdown_enabled": 1, "countdown_date": future, "countdown_label": "До начала олимпиады",
  "hero_image_url": BASE + "/hero19.svg", "hero_image_alt": "Иллюстрация", "hero_image_position": "right", "hero_image_style": "shadow",
  "hero_image_fit": "cover", "hero_image_mobile": 1, "hero_style": "gradient", "hero_badge_color": "#fde68a",
  "hero_min_height": 520, "hero_text_width": 600, "hero_valign": "center",
  "heading_weight": "700", "heading_scale": "large", "line_height": "relaxed", "link_color": "#0f766e", "border_width": 2,
  "section_title_align": "center", "alt_sections": 1, "button_shape": "pill",
  "custom_css": ".wpd-page .wpd-hero__title{letter-spacing:-.02em}",
  "google_fonts": "Montserrat:400,700\nPT Serif",
}
with sync_playwright() as p:
    b = p.chromium.launch(args=["--disable-dev-shm-usage"])
    ctx = b.new_context(viewport={"width": 1280, "height": 900}, locale="ru-RU")
    page = ctx.new_page()
    page.route("**/fonts.googleapis.com/**", lambda r: r.abort())
    page.route("**/fonts.gstatic.com/**", lambda r: r.abort())
    page.goto(BASE + "/wp-login.php", timeout=120000)
    page.fill("#user_login", "admin"); page.fill("#user_pass", "password"); page.click("#wp-submit")
    page.wait_for_load_state("networkidle", timeout=120000)
    set_opt(opt)
    page.goto(BASE + "/wp-admin/admin.php?page=wpd-settings&tab=hero", timeout=120000)
    page.screenshot(path=os.path.join(OUT, "settings-hero.png"), full_page=True)
    page.goto(BASE + "/wp-admin/admin.php?page=wpd-settings&tab=design", timeout=120000)
    full = os.path.join(OUT, "_design_full.png")
    page.screenshot(path=full, full_page=True)
    box = page.locator("h2", has_text="Шрифты Google и свой CSS").first.bounding_box()
    im = Image.open(full); top = max(0, int(box["y"]) - 16)
    im.crop((0, top, im.width, im.height)).save(os.path.join(OUT, "settings-code.png")); os.remove(full)
    lb = page.locator("h2", has_text="Типографика").first.bounding_box()
    page.screenshot(path=full, full_page=True)
    im = Image.open(full); im.crop((0, max(0, int(lb["y"]) - 16), im.width, top)).save(os.path.join(OUT, "settings-look.png")); os.remove(full)
    pid = urllib.request.urlopen(BASE + "/wpd-h-page.php?full=1", timeout=60).read().decode().strip()
    ctx2 = b.new_context(viewport={"width": 1280, "height": 900}, locale="ru-RU", reduced_motion="reduce")
    fp = ctx2.new_page()
    fp.route("**/fonts.googleapis.com/**", lambda r: r.abort())
    fp.goto(BASE + "/?page_id=" + pid, timeout=120000); time.sleep(1)
    fp.mouse.wheel(0, 1200); time.sleep(2.5); fp.mouse.wheel(0, -1200); time.sleep(0.5)
    fp.locator(".wpd-page").first.screenshot(path=full)
    im = Image.open(full); im.crop((0, 0, im.width, min(im.height, 1500))).save(os.path.join(OUT, "front-hero-1.9.png")); os.remove(full)
    m = b.new_context(viewport={"width": 390, "height": 844}, locale="ru-RU", device_scale_factor=1, reduced_motion="reduce")
    mp = m.new_page(); mp.goto(BASE + "/?page_id=" + pid, timeout=120000); time.sleep(1.5)
    mp.locator(".wpd-hero").first.screenshot(path=os.path.join(OUT, "front-hero-1.9-mobile.png"))
    urllib.request.urlopen(BASE + "/wpd-h-page.php?cleanup=1", timeout=60).read()
    set_opt({})
    b.close()
print("done")
