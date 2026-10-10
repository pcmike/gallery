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
    # ---------------- swipe gestures (real touch events) ----------------
    print("== swipe gestures on a phone (real touch events)")
    b.call("Emulation.setTouchEmulationEnabled", {"enabled": True, "maxTouchPoints": 5})
    b.goto(B + "/", 1.5); b.js("openLightbox(0)"); b.drain(0.6)
    cap = lambda: b.js("document.getElementById('lightbox-caption').textContent")
    isopen = lambda: b.js("document.getElementById('lightbox').classList.contains('open')")
    c0 = cap(); ok(isopen() and c0.startswith("1 / "), "(setup) the lightbox is open on item 1", c0)
    b.swipe(300, 400, 100, 410); c1 = cap(); ok(c1.startswith("2 / "), "swiping left moves to the next item", f"{c0!r} -> {c1!r}")
    b.swipe(100, 400, 300, 395); c2 = cap(); ok(c2.startswith("1 / "), "swiping right moves back", f"{c1!r} -> {c2!r}")
    b.swipe(200, 400, 170, 402); ok(cap() == c2, "a drag shorter than 50px is ignored (no accidental page turn)", cap())
    b.swipe(200, 400, 210, 200); ok(isopen(), "swiping UP does not close it")
    b.swipe(200, 300, 205, 600); ok(not isopen(), "swiping DOWN closes the lightbox")
    shot(b, "10-after-swipe-close")
    b.call("Emulation.setTouchEmulationEnabled", {"enabled": False})
    # ---------------- admin ----------------
    print("== admin")
    # ---------------- logging in the way a person does ----------------
    print("== logging in (a real person: click the title, type the password)")
    b.viewport(1280, 900); b.goto(B + "/", 1.5)
    st = b.js("(() => { const a = document.querySelector('header h1 a.title-admin'); if (!a) return null; const h = getComputedStyle(a.parentElement), c = getComputedStyle(a); const r = a.getBoundingClientRect(); return {deco: c.textDecorationLine, color: c.color === h.color, cursor: c.cursor, x: r.left + r.width / 2, y: r.top + r.height / 2}; })()")
    ok(st is not None, "the gallery title is a link to the admin")
    if st:
        ok(st["deco"] == "none" and st["color"], "...that looks exactly like plain text (no underline, same colour as the heading)", str(st))
        b.call("Input.dispatchMouseEvent", {"type": "mouseMoved", "x": st["x"], "y": st["y"]}); b.drain(0.2)
        st2 = b.js("(() => { const c = getComputedStyle(document.querySelector('header h1 a.title-admin')); return {deco: c.textDecorationLine, cursor: c.cursor}; })()")
        ok(st2["deco"] == "none" and st2["cursor"] in ("auto", "text", "default"), "...and hovering it changes nothing (no underline, no hand cursor)", str(st2))
        b.call("Input.dispatchMouseEvent", {"type": "mousePressed", "x": st["x"], "y": st["y"], "button": "left", "clickCount": 1})
        b.call("Input.dispatchMouseEvent", {"type": "mouseReleased", "x": st["x"], "y": st["y"], "button": "left", "clickCount": 1}); b.drain(1.0)
        ok("?admin" in b.js("location.href"), "clicking the title goes to ?admin", b.js("location.href"))
        ok(b.js("document.querySelector('input[type=password][name=login_password]') !== null") and b.js("document.activeElement && document.activeElement.type") == "password", "...which is a plain password form with the field already focused (no browser pop-up)")
        shot(b, "login-form")
        b.call("Input.insertText", {"text": "wrong-pw"}); b.js("document.querySelector('form').requestSubmit()"); b.drain(1.0)
        ok("isn" in b.js("document.body.innerText") and b.js("document.querySelector('input[name=login_password]') !== null"), "a wrong password shows a message and the form again")
        b.js("document.querySelector('input[name=login_password]').focus()"); b.call("Input.insertText", {"text": "adm-pw"})
        b.js("document.querySelector('form').requestSubmit()"); b.drain(1.5)
        ok(b.js("document.querySelector('h1') && document.querySelector('h1').textContent") == "Gallery Admin", "the right password lands on the admin page", b.js("location.href"))
        ok(not b.problems(IGN), "...with no console or CSP errors", "; ".join(b.problems(IGN))[:300])
        b.goto(B + "/?admin", 1.0)
        ok(b.js("document.querySelector('h1').textContent") == "Gallery Admin", "reloading ?admin stays signed in (the session cookie, no password asked again)")
        b.js("[...document.querySelectorAll('button')].find(x => x.textContent.trim() == 'Log out').click()"); b.drain(1.5)
        ok("?admin" not in b.js("location.href"), "Log out leaves the admin", b.js("location.href"))
        b.goto(B + "/?admin", 1.0)
        ok(b.js("document.querySelector('input[name=login_password]') !== null"), "...and ?admin asks for the password again")

    b.viewport(390, 844, mobile=True)   # back to the phone the admin checks below are about
    b.call("Network.setExtraHTTPHeaders", {"headers": {"Authorization": "Basic " + auth}})
    b.goto(B + "/?admin", 1.5); shot(b, "07-admin-mobile")
    ok(b.js(overflow), "[admin] no horizontal scrolling on a phone", f"scrollWidth={b.js('document.documentElement.scrollWidth')}")
    ok(b.js("(() => { const d = document.querySelector('details.admin-reference'); return d ? !d.open : null; })()") is True, "[admin] the directive reference starts collapsed on a phone")
    ok(not b.problems(IGN), "[admin] loads with no errors or CSP violations", "; ".join(b.problems(IGN))[:300])
    # ---------------- admin tabs (real clicks) ----------------
    print("== admin tabs")
    b.viewport(1280, 900); b.js("try { sessionStorage.clear(); } catch (e) {}"); b.goto(B + "/?admin&folder=Asia", 1.5)
    vis = "[...document.querySelectorAll('.tab-panel')].filter(p => getComputedStyle(p).display !== 'none').map(p => p.dataset.tab)"
    ok(b.js(vis) == ["photos"], "only one tab's content is shown at a time (Photos by default)", str(b.js(vis)))
    tabs = b.js("[...document.querySelectorAll('.admin-tabs a')].map(a => a.dataset.tab)")
    ok(tabs == ["photos", "settings", "notes", "export"], "the tabs are Photos, Folder settings, Notes, Export", str(tabs))
    hb = b.js("(() => { const a = document.querySelector('.side-hidden'); const r = document.querySelector('.admin-folder-nav a').getBoundingClientRect(); if (!a) return null; const x = a.getBoundingClientRect(); return {color: getComputedStyle(a).color, aboveRoot: x.bottom <= r.top + 1, left: x.left < 400}; })()")
    ok(hb is not None and hb["color"].startswith("rgb(255, 123") and hb["aboveRoot"] and hb["left"], "the red Hidden entry sits in the left sidebar, above Root", str(hb))
    row = b.js("""(() => { const q = s => document.querySelector(s).getBoundingClientRect(); const bar = q('.admin-bar'), h1 = q('.admin-bar h1'), crumb = q('.admin-bar .crumb'), tabs = q('.admin-tabs'), out = q('.logout-form');
        return {barH: Math.round(bar.height), sameRow: [h1, crumb, tabs, out].every(r => r.top >= bar.top - 1 && r.bottom <= bar.bottom + 1), order: h1.right <= crumb.left + 1 && crumb.right <= tabs.left + 1 && tabs.right <= out.left + 1, tabsRightSide: tabs.left > bar.width / 2}; })()""")
    ok(row["barH"] < 80 and row["sameRow"] and row["order"] and row["tabsRightSide"], "the header is ONE row: title and folder on the left, tabs right-justified, then Log out", str(row))
    ok(b.js("document.querySelector('.admin-tabs a.active').dataset.tab") == "photos", "the current tab is highlighted")
    b.js("document.querySelector('.admin-tabs a[data-tab=settings]').click()"); b.drain(0.3); shot(b, "09-admin-settings-tab")
    ok(b.js(vis) == ["settings"] and b.js("document.querySelector('.admin-tabs a.active').dataset.tab") == "settings", "clicking a tab shows only that section and highlights the tab", str(b.js(vis)))
    ok(b.js("location.hash") == "#settings", "...and puts it in the address so the view can be linked")
    b.goto(B + "/?admin&folder=Europe", 1.0)
    ok(b.js(vis) == ["settings"], "the chosen tab is remembered when you move to another folder", str(b.js(vis)))
    b.js("document.querySelector('.side-hidden').click()"); b.drain(0.3); shot(b, "10-admin-hidden-tab")
    ok(b.js(vis) == ["hidden"] and b.js("document.querySelector('.hidden-warning') !== null"), "clicking Hidden shows the explanation")
    ok(b.js("document.querySelector('.side-hidden').classList.contains('active') && !document.querySelector('.admin-tabs a.active')"), "...with Hidden highlighted and no folder tab selected (it is not a folder section)")
    link = b.js("(() => { const a = document.querySelector('.hidden-list a[href*=photo-]'); return a ? [a.getAttribute('href'), a.textContent] : null; })()")
    ok(link is not None, "...and lists hidden photos as links", str(link))
    if link:
        b.js("document.querySelector('.hidden-list a[href*=photo-]').click()"); b.drain(1.2)
        landed = b.js("""(() => { const t = document.getElementById(decodeURIComponent(location.hash.slice(1))); if (!t) return null; const ed = document.getElementById(t.dataset.edit); const r = ed.getBoundingClientRect();
            return {tab: document.querySelector('.admin-tabs a.active').dataset.tab, open: ed.classList.contains('open'), inView: r.height > 0 && r.top >= 0 && r.top < innerHeight, name: ed.querySelector('.item-filename').textContent, selected: t.classList.contains('selected')}; })()""")
        ok(landed is not None and landed["tab"] == "photos" and landed["open"] and landed["inView"] and landed["selected"], "clicking a hidden photo opens its folder's Photos tab with that photo's editor open and in view", str(landed) + " " + str(link))
    b.goto(B + "/?admin&folder=Asia", 1.0)
    ok(b.js(vis) != ["hidden"], "moving to another folder doesn't land you back on Hidden", str(b.js(vis)))
    # ---------------- the photo editor: grid of thumbnails, one panel above ----------------
    print("== photo editor")
    b.js("document.querySelector('.admin-tabs a[data-tab=photos]').click()"); b.drain(0.3); shot(b, "12-photos-grid")
    ed_state = "(() => { const o = [...document.querySelectorAll('.item-editor.open')]; return {panel: getComputedStyle(document.getElementById('photo-editor')).display, open: o.length, name: o[0] ? o[0].querySelector('.item-filename').textContent : null, selected: document.querySelectorAll('.thumb.selected').length}; })()"
    st = b.js(ed_state)
    ok(st["panel"] == "none" and st["open"] == 0 and b.js("document.querySelectorAll('.thumb').length") >= 2, "the editor is collapsed until a thumbnail is selected, and the grid shows the thumbnails", str(st))
    names = b.js("[...document.querySelectorAll('.thumb')].map(t => t.querySelector('.thumb-name').textContent)")
    b.js("document.querySelectorAll('.thumb')[0].click()"); b.drain(0.3); shot(b, "13-photo-editor-open")
    st = b.js(ed_state)
    ok(st["panel"] != "none" and st["open"] == 1 and st["selected"] == 1 and st["name"].endswith(names[0]), "selecting a thumbnail opens the editor with that photo's fields", str(st) + str(names))
    b.js("document.querySelectorAll('.thumb')[1].click()"); b.drain(0.2); st = b.js(ed_state)
    ok(st["open"] == 1 and st["name"].endswith(names[1]), "selecting another thumbnail switches the same panel to it (still only one editor open)", str(st))
    b.js("document.querySelector('.item-editor.open [data-nav=\"-1\"]').click()"); b.drain(0.2); st = b.js(ed_state)
    ok(st["name"].endswith(names[0]), "Prev moves to the previous photo", str(st))
    b.js("document.querySelector('.item-editor.open [data-nav=\"1\"]').click()"); b.drain(0.2); st = b.js(ed_state)
    ok(st["name"].endswith(names[1]), "Next moves to the next photo", str(st))
    b.js("document.querySelector('.item-editor.open [data-close]').click()"); b.drain(0.2); st = b.js(ed_state)
    ok(st["panel"] == "none" and st["open"] == 0, "Close collapses the editor again", str(st))
    b.js("document.querySelectorAll('.thumb')[0].click()"); b.drain(0.2)
    b.js("(() => { const i = document.querySelector('.item-editor.open input[name=caption]'); i.value = 'tab test'; i.dispatchEvent(new Event('input', {bubbles: true})); })()")
    ok(b.js("document.querySelectorAll('.thumb')[0].classList.contains('dirty')"), "typing in the editor marks that thumbnail as having unsaved changes")
    b.js("document.querySelector('.item-editor.open form').requestSubmit()"); b.drain(1.5)
    st = b.js(ed_state)
    ok(b.js(vis) == ["photos"] and st["open"] == 1 and st["name"].endswith(names[0]), "after saving, the page comes back on Photos with that same photo's editor open", str(st))
    ok(b.js("document.querySelector('.item-editor.open input[name=caption]').value") == "tab test" and b.js("!!document.querySelectorAll('.thumb')[0].querySelector('.thumb-badges span[title=\"Has a caption\"]')"), "...showing the saved caption, and the thumbnail now carries a caption badge")
    # public breadcrumb pills
    b.goto(B + "/?folder=Asia", 1.0)
    bc = b.js("(() => { const a = document.querySelector('header p.breadcrumb a'); if (!a) return null; const c = getComputedStyle(a); return {radius: c.borderTopLeftRadius, deco: c.textDecorationLine, bg: c.backgroundColor}; })()")
    ok(bc is not None and bc["deco"] == "none" and bc["radius"] not in ("0px", ""), "the public folder page's way back is a styled pill, not a default blue link", str(bc))
    shot(b, "11-folder-breadcrumb")
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
