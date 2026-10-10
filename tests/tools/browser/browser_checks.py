#!/usr/bin/env python3
"""Drive the gallery in a real (headless) Chromium and check what a person would see and do.

Real clicks and key presses, real layout measurements, real console / CSP errors:
the lightbox (open, arrow keys, Escape, a video), the caption popover (position,
dismissal), folder cover cards and breadcrumbs, the map with real Leaflet (pins,
popup), phone-width pages for horizontal overflow, the admin (its collapsible
panel, a real form submission under the page's Content-Security-Policy, and that
it refuses to be framed). Optionally saves screenshots.

    python3 tests/tools/browser/browser_checks.py [--shots DIR]
    CHROMIUM=/path/to/chromium python3 ...      # if `chromium` isn't on PATH

Needs python3, php (gd + imagick, for the fixture) and Chromium. The map check also
needs internet access for Leaflet (cdnjs); without it that check is skipped, not failed.
Exit status 0 only if every check passes.
"""
import os, sys, time, subprocess, tempfile, shutil, socket, base64, urllib.request
HERE = os.path.dirname(os.path.abspath(__file__)); sys.path.insert(0, HERE)
from cdp import Browser
SHOTS = sys.argv[sys.argv.index("--shots") + 1] if "--shots" in sys.argv else None
if SHOTS: os.makedirs(SHOTS, exist_ok=True)
WORK = tempfile.mkdtemp(prefix="gallery-browser-"); SITE = WORK + "/site"
s = socket.socket(); s.bind(("127.0.0.1", 0)); PORT = s.getsockname()[1]; s.close(); B = f"http://127.0.0.1:{PORT}"
subprocess.run(["php", HERE + "/../fuzz/build_fixture.php", SITE], check=True, stdout=subprocess.DEVNULL)
srv = subprocess.Popen([os.environ.get("PHP_SERVER", "php"), "-S", f"127.0.0.1:{PORT}", "-t", SITE], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
fails = 0; skips = 0
def ok(cond, msg, detail=""):
    global fails
    print(("  ok    " if cond else "  FAIL  ") + msg + ("" if cond else (f"  [{detail}]" if detail else "")))
    if not cond: fails += 1
def shot(b, name):
    if SHOTS: b.screenshot(f"{SHOTS}/{name}.png")
IGN = ("favicon", "net::ERR", "Failed to load resource", "tile.openstreetmap", "DEMUXER", "MEDIA_ELEMENT_ERROR", "Format error", "ERR_BLOCKED")
overflow = "document.documentElement.scrollWidth <= window.innerWidth + 1"
auth = base64.b64encode(b"admin:adm-pw").decode()
b = None
try:
    time.sleep(1.2); b = Browser()
    # ---------------- desktop gallery ----------------
    print("== desktop gallery (1280x900)"); b.viewport(1280, 900)
    b.goto(B + "/", 2.0); shot(b, "01-gallery-desktop")
    ok(not b.problems(IGN), "the page loads with no JavaScript errors or browser-reported issues", "; ".join(b.problems(IGN))[:300])
    ok(b.js(overflow), "no horizontal scrolling at desktop width")
    n = b.js("document.querySelectorAll('.card').length"); ok(n > 3, f"cards are rendered ({n})")
    b.js("[...document.querySelectorAll('.card img')].forEach(i => i.scrollIntoView())"); b.drain(1.5)   # thumbnails are lazy-loaded
    b.js("window.scrollTo(0, 0)")
    broken = b.js("[...document.querySelectorAll('.card img')].filter(i => !(i.complete && i.naturalWidth > 0)).map(i => i.alt)")
    ok(not broken, "every thumbnail actually decodes as an image in the browser", str(broken)[:200])
    b.js("document.querySelector('.card').click()"); b.drain(0.8); shot(b, "02-lightbox")
    ok(b.js("document.getElementById('lightbox').classList.contains('open')"), "clicking a card opens the lightbox")
    ok(b.js("(() => { const i = document.getElementById('lightbox-img'); return i.complete && i.naturalWidth > 0 && getComputedStyle(i).display !== 'none'; })()"), "the lightbox shows a decoded full-size image")
    cap1 = b.js("document.getElementById('lightbox-caption').textContent")
    b.key("ArrowRight", "ArrowRight", 39); b.drain(0.5); cap2 = b.js("document.getElementById('lightbox-caption').textContent")
    ok(cap1 != cap2 and "/" in cap2, "ArrowRight (a real key press) moves to the next item", f"{cap1!r} -> {cap2!r}")
    ok(not any(x in cap2 for x in (".jpg", ".png", ".mp4", ".heic")), "the caption bar never shows a filename")
    b.key("Escape", "Escape", 27); b.drain(0.3); ok(not b.js("document.getElementById('lightbox').classList.contains('open')"), "Escape closes it")
    vi = b.js("files.indexOf('clip.mp4')") if b.js("typeof files !== 'undefined'") else -1
    if vi >= 0:
        b.js(f"openLightbox({vi})"); b.drain(0.6)
        ok(b.js("(() => { const v = document.getElementById('lightbox-video'); return getComputedStyle(v).display !== 'none' && v.src.includes('?video='); })()") and b.js("getComputedStyle(document.getElementById('lightbox-img')).display === 'none'"), "a video opens in the <video> element with the image hidden")
        b.js("closeLightbox()")
    # caption popover
    ok(b.js("document.querySelectorAll('.caption-badge').length") > 0, "a captioned photo has a caption badge")
    r = b.js("""(() => { const badge = document.querySelector('.caption-badge'); const card = badge.closest('.card'); badge.click();
        const pop = card.querySelector('.caption-popover'); if (!pop) return null; const p = pop.getBoundingClientRect(), c = card.getBoundingClientRect();
        return {inside: p.left >= c.left - 1 && p.right <= c.right + 1 && p.top >= c.top - 1 && p.bottom <= c.bottom + 1, w: p.width, h: p.height, shown: getComputedStyle(pop).display !== 'none' && getComputedStyle(pop).visibility !== 'hidden', text: pop.textContent === badge.getAttribute('title'), lightboxOpened: document.getElementById('lightbox').classList.contains('open')}; })()""")
    shot(b, "03-caption-popover")
    ok(r is not None and r["shown"] and r["inside"] and r["w"] > 40 and r["h"] > 10, "tapping the caption badge shows a popover inside its card", str(r))
    ok(r is not None and r["text"] and not r["lightboxOpened"], "...holding the caption text, without also opening the lightbox", str(r))
    b.js("document.body.click()"); ok(b.js("document.querySelector('.caption-popover') === null"), "...and tapping elsewhere dismisses it")
    # folders
    cover = b.js("(() => { const a = document.querySelector('.folder-cover-link'); return a ? a.getAttribute('href') : null; })()")
    ok(cover is not None, "folder cover cards are present"); 
    if cover:
        b.goto(B + "/" + cover, 1.5); shot(b, "04-folder-page")
        ok("?folder=" in b.js("location.href") and not b.problems(IGN), "a cover card opens its folder page without errors", "; ".join(b.problems(IGN))[:200])
        ok(b.js("document.querySelector('.breadcrumb') !== null"), "...which has a breadcrumb back")
    b.goto(B + "/?folder=Europe%2FFrance%2FParis", 1.5)
    ok(b.js("document.querySelectorAll('.breadcrumb a').length") >= 3, "a three-level-deep folder page has a breadcrumb through every ancestor")
    # ---------------- replacing a photo under the same name ----------------
    print("== replacing a photo under the same name (real browser cache)")
    def thumb_rgb():
        b.goto(B + "/", 1.2)
        b.js("[...document.querySelectorAll('.card img')].find(i => i.alt === 'loose2.jpg').scrollIntoView()"); b.drain(1.2)
        return b.js("""(() => { const i = [...document.querySelectorAll('.card img')].find(x => x.alt === 'loose2.jpg'); if (!(i.complete && i.naturalWidth)) return null;
            const c = document.createElement('canvas'); c.width = c.height = 1; const g = c.getContext('2d'); g.drawImage(i, i.naturalWidth / 2 - 4, i.naturalHeight / 2 - 4, 8, 8, 0, 0, 1, 1);
            return [...g.getImageData(0, 0, 1, 1).data].slice(0, 3); })()""")
    before = thumb_rgb()
    subprocess.run(["php", "-r", "$im = imagecreatetruecolor(160, 120); imagefill($im, 0, 0, imagecolorallocate($im, 30, 60, 220)); imagejpeg($im, $argv[1]);", SITE + "/loose2.jpg"], check=True)
    os.utime(SITE + "/loose2.jpg", (time.time() + 30, time.time() + 30))
    after = thumb_rgb()
    ok(before is not None and before[0] > before[2], "before the swap, the thumbnail is the original (reddish) picture", str(before))
    ok(after is not None and after[2] > after[0], "after replacing the file under the same name, a normal page load shows the NEW (bluish) picture, not the browser's cached one", f"{before} -> {after}")
    # ---------------- map (real Leaflet) ----------------
    print("== map (real Leaflet from cdnjs)")
    b.goto(B + "/?map", 4.0); shot(b, "05-map")
    if b.js("typeof L !== 'undefined'"):
        ok(b.js("document.querySelector('.leaflet-container') !== null"), "Leaflet builds the map")
        pins = b.js("document.querySelectorAll('path.leaflet-interactive, .leaflet-marker-icon').length"); ok(pins >= 1, f"pins are drawn ({pins})")
        b.js("document.querySelector('path.leaflet-interactive, .leaflet-marker-icon').dispatchEvent(new MouseEvent('click', {bubbles: true}))"); b.drain(0.6)
        pop = b.js("(() => { const p = document.querySelector('.leaflet-popup-content'); return p ? {link: !!p.querySelector('a[href]'), img: !!p.querySelector('img'), html: p.innerHTML} : null; })()")
        ok(pop is not None and pop["link"] and pop["img"], "clicking a pin opens a popup with a thumbnail and a link", str(pop)[:200])
        ok(pop is not None and "<b>" not in pop["html"].replace("<strong>", "").replace("</strong>", "") and "onerror" not in pop["html"], "...with user text escaped inside it")
        ok(b.js(overflow), "the map page has no horizontal scrolling")
        b.js("history.pushState({}, '', '/')"); b.goto(B + "/", 4.0)
        ok(b.js("document.getElementById('trip-map-preview').classList.contains('leaflet-container')"), "the preview map on the gallery root builds too")
    else:
        skips += 1; print("  skip  Leaflet did not load (no internet access to cdnjs?) — map checks skipped")
    # ---------------- phone width ----------------
    print("== phone width (390x844)"); b.viewport(390, 844, mobile=True)
    for name, path in (("gallery", "/"), ("folder", "/?folder=Europe"), ("nested", "/?folder=Europe%2FFrance%2FParis"), ("about", "/?about")):
        b.goto(B + path, 1.5); shot(b, f"06-mobile-{name}")
        ok(b.js(overflow), f"[{name}] no horizontal scrolling on a phone", f"scrollWidth={b.js('document.documentElement.scrollWidth')}")
    # ---------------- admin ----------------
    print("== admin")
    b.call("Network.setExtraHTTPHeaders", {"headers": {"Authorization": "Basic " + auth}})
    b.goto(B + "/?admin", 1.5); shot(b, "07-admin-mobile")
    ok(b.js(overflow), "[admin] no horizontal scrolling on a phone", f"scrollWidth={b.js('document.documentElement.scrollWidth')}")
    ok(b.js("(() => { const d = document.querySelector('details.admin-reference'); return d ? !d.open : null; })()") is True, "[admin] the directive reference starts collapsed on a phone")
    ok(not b.problems(IGN), "[admin] loads with no errors or CSP violations", "; ".join(b.problems(IGN))[:300])
    b.viewport(1280, 900); b.goto(B + "/?admin", 1.5); shot(b, "08-admin-desktop")
    ok(b.js("(() => { const d = document.querySelector('details.admin-reference'); return d ? d.open : null; })()") is True, "[admin] ...and starts expanded on a desktop")
    b.goto(B + "/?admin&folder=Asia", 1.5)
    b.js("document.querySelector('input[name=display_name]').value = 'Asia (edited in a browser)'")
    b.js("document.querySelector('form input[value=save_folder_identity]').form.querySelector('button[type=submit]').click()"); b.drain(2.0)
    ok("Folder settings saved" in (b.js("(document.querySelector('.flash.ok') || {}).textContent || ''") or ""), "[admin] a real browser form submit works under the page's CSP (\"Folder settings saved.\")", b.js("(document.querySelector('.flash') || {}).textContent || document.title"))
    ok(not [p for p in b.problems(IGN) if "Content Security Policy" in p or "form-action" in p], "[admin] ...with no CSP violation")
    shot(b, "09-admin-after-save")
    ratio = b.js("""(() => { const a = document.querySelector('.flash.error a'); if (!a) return null;
        const parse = s => { const m = s.match(/[\\d.]+/g).map(Number); return [m[0], m[1], m[2], m.length > 3 ? m[3] : 1]; };
        const layers = []; for (let n = a; n; n = n.parentElement) layers.unshift(parse(getComputedStyle(n).backgroundColor));
        let bg = [255, 255, 255]; for (const [r, g, bl, al] of layers) bg = [r * al + bg[0] * (1 - al), g * al + bg[1] * (1 - al), bl * al + bg[2] * (1 - al)];
        const lum = c => { const v = c.map(x => { x /= 255; return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4); }); return 0.2126 * v[0] + 0.7152 * v[1] + 0.0722 * v[2]; };
        const fg = lum(parse(getComputedStyle(a).color).slice(0, 3)), back = lum(bg); return Math.round((Math.max(fg, back) + 0.05) / (Math.min(fg, back) + 0.05) * 100) / 100; })()""")
    ok(ratio is None or ratio >= 3.0, "[admin] the link inside the red warning is readable (contrast >= 3:1)", f"ratio={ratio}")
    # anti-framing: the admin must refuse to be embedded (even by the same site)
    b.goto(B + "/", 1.0); b.events = []
    framed = b.js("""new Promise(res => { const f = document.createElement('iframe'); f.src = '/?admin'; f.onload = () => { let blocked = false; try { blocked = !f.contentDocument || f.contentDocument.body === null || f.contentDocument.title === '' && f.contentDocument.body.innerText.trim() === ''; } catch (e) { blocked = true; } res(blocked); }; document.body.appendChild(f); setTimeout(() => res('timeout'), 5000); })""")
    b.drain(0.5)
    refused = any("X-Frame-Options" in (e.get("params", {}).get("entry", {}).get("text", "") + str(e.get("params", {}))) for e in b.events)
    ok(framed is True or refused, "[admin] the browser refuses to display it inside a frame (X-Frame-Options: DENY)", str(framed))
    b.call("Network.setExtraHTTPHeaders", {"headers": {}})
except Exception as e:
    fails += 1; print("  FAIL  the browser run crashed:", type(e).__name__, e)
finally:
    if b: b.close()
    srv.terminate(); srv.wait(); shutil.rmtree(WORK, ignore_errors=True)
print(f"\n{'ALL BROWSER CHECKS PASSED' if not fails else str(fails) + ' BROWSER CHECK(S) FAILED'}" + (f" ({skips} skipped)" if skips else "") + (f"\nscreenshots: {SHOTS}" if SHOTS else ""))
sys.exit(1 if fails else 0)
