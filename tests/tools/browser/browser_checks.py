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
import json, os, sys, time, subprocess, tempfile, shutil, socket, base64, urllib.request
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
        b.js("history.pushState({}, '', '/')"); b.js("try { localStorage.clear(); } catch (e) {}"); b.goto(B + "/", 2.0)
        ok(b.js("document.getElementById('map-preview-body').hidden") and b.js("typeof L === 'undefined'") and b.js("document.getElementById('map-toggle').getAttribute('aria-expanded')") == "false", "[overview map] it starts closed: a 'Show trip map' button, no map, and Leaflet not even downloaded until asked")
        b.js("document.getElementById('map-toggle').click()"); b.drain(4.0); shot(b, "05a-overview-map-open")
        ok(b.js("document.getElementById('trip-map-preview').classList.contains('leaflet-container')") and not b.js("document.getElementById('map-preview-body').hidden") and "Hide" in b.js("document.querySelector('.map-toggle-label').textContent"), "[overview map] pressing the button loads Leaflet and builds the map, and the button now says Hide")
        b.goto(B + "/", 4.0)
        ok(b.js("document.getElementById('trip-map-preview').classList.contains('leaflet-container')"), "[overview map] the choice is remembered in the browser: the map is open again on the next page")
        b.js("document.getElementById('map-toggle').click()"); b.drain(0.4)
        ok(b.js("document.getElementById('map-preview-body').hidden"), "[overview map] pressing it again hides the map")
        b.js("document.getElementById('map-toggle').click()"); b.drain(1.0)
        # the map must never land on top of the viewer
        b.js("document.getElementById('map-preview').scrollIntoView()"); b.drain(0.3)
        pt = b.js("(() => { const r = document.getElementById('trip-map-preview').getBoundingClientRect(); return [Math.round(r.left + r.width / 2), Math.round(r.top + r.height / 2)]; })()")
        b.js("openLightbox(0)"); b.drain(0.6)
        top = b.js(f"(() => {{ const e = document.elementFromPoint({pt[0]}, {pt[1]}); return e ? !!e.closest('#lightbox') : null; }})()")
        ok(top is True, "[overview map] with the viewer open, nothing of the overview map shows through or sits on top of it", str(top))
        b.js("closeLightbox()")
        # the little map in the viewer
        b.js("try { localStorage.clear(); } catch (e) {}"); b.goto(B + "/", 1.5)
        li = b.js("files.indexOf('loose1.jpg')")
        b.js(f"openLightbox({li})"); b.drain(4.0); shot(b, "05c-lightbox-map")
        mini = b.js("(() => { const m = document.getElementById('lightbox-map'), i = document.getElementById('lightbox-img'); const r = m.getBoundingClientRect(), ir = i.getBoundingClientRect(); return {hidden: m.hidden, leaflet: m.classList.contains('leaflet-container'), w: Math.round(r.width), h: Math.round(r.height), dx: Math.round(r.left - ir.left), dy: Math.round(r.top - ir.top), tiles: m.querySelectorAll('.leaflet-tile').length}; })()")
        ok(mini["leaflet"] and not mini["hidden"] and 100 < mini["w"] < 260 and 0 <= mini["dx"] <= 20 and 0 <= mini["dy"] <= 20, "[viewer map] a photo with a public location shows a small map in the top left corner of the photo, on by default", str(mini))
        ok(b.js("(() => { const m = document.getElementById('lightbox-map').getBoundingClientRect(); const e = document.elementFromPoint(m.left + 40, m.top + 40); return !!e.closest('#lightbox-map'); })()"), "[viewer map] it is on top of the photo, where it can be clicked")
        ok(b.js("document.getElementById('lightbox-web').hidden") is False and b.js("document.getElementById('lightbox-web').getAttribute('href')") == "https://example.com/?a=1&b=2" and b.js("document.getElementById('lightbox-web').getBoundingClientRect().left") > b.js("document.getElementById('lightbox-copy-btn').getBoundingClientRect().left"), "[viewer] the photo's web link sits beside the permalink icon in the caption bar")
        b.js("document.getElementById('lightbox-map').click()"); b.drain(1.2); shot(b, "05d-lightbox-map-big")
        big = b.js("(() => { const m = document.getElementById('lightbox-map'); const r = m.getBoundingClientRect(); return {cls: m.classList.contains('expanded'), w: Math.round(r.width), h: Math.round(r.height), close: getComputedStyle(m.querySelector('.lbmap-close')).display !== 'none', osm: m.querySelector('.lbmap-osm').href}; })()")
        ok(big["cls"] and big["w"] > 500 and big["close"] and "openstreetmap.org" in big["osm"], "[viewer map] clicking it enlarges it, with a close button and an Open in OpenStreetMap link", str(big))
        b.js("document.querySelector('.lbmap-close').click()"); b.drain(0.5)
        ok(not b.js("document.getElementById('lightbox-map').classList.contains('expanded')") and b.js("document.getElementById('lightbox').classList.contains('open')"), "[viewer map] the close button shrinks it again and leaves the viewer open")
        b.js("document.getElementById('lightbox-map').click()"); b.drain(0.5); b.key("Escape", "Escape", 27); b.drain(0.4)
        ok(not b.js("document.getElementById('lightbox-map').classList.contains('expanded')") and b.js("document.getElementById('lightbox').classList.contains('open')"), "[viewer map] Escape closes the big map first, not the viewer")
        b.js("document.getElementById('lightbox-map-toggle').click()"); b.drain(0.4)
        ok(b.js("document.getElementById('lightbox-map').hidden") and b.js("localStorage.getItem('galleryLightboxMap')") == "0", "[viewer map] the pin button in the caption bar turns the little map off, and remembers it")
        b.js("document.getElementById('lightbox-map-toggle').click()"); b.drain(1.5)
        ok(not b.js("document.getElementById('lightbox-map').hidden"), "[viewer map] ...and on again")
        nl = b.js("locations.findIndex(l => l === null)")
        b.js(f"openLightbox({nl})"); b.drain(0.6)
        ok(b.js("document.getElementById('lightbox-map').hidden") and b.js("document.getElementById('lightbox-map-toggle').hidden"), "[viewer map] a photo with no public location shows neither the map nor its button")
        b.js("closeLightbox()")
        b.js("try { localStorage.setItem('galleryMapOpen', '1'); } catch (e) {}"); b.goto(B + "/?folder=Asia", 4.0); shot(b, "05b-folder-map")
        pins = b.js("document.querySelectorAll('.map-preview path.leaflet-interactive, .map-preview .leaflet-marker-icon').length")
        ok(b.js("document.getElementById('trip-map-preview').classList.contains('leaflet-container')") and pins == 1, "a folder with its map switched on shows its own map, here with just the one photo that is in it", str(pins))
        b.goto(B + "/?map&folder=Asia", 4.0)
        ok(b.js("document.querySelector('.leaflet-container') !== null") and "Asia" in b.js("document.querySelector('h1').textContent") and b.js("document.querySelector('a.back').getAttribute('href')") == "?folder=Asia", "...and the folder's own map page builds, titled for the folder, with a way back to it")
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
    b.goto(B + "/", 1.5); b.js("openLightbox(files.findIndex(f => !f.includes('/')))"); b.drain(0.6)
    cap = lambda: b.js("document.getElementById('lightbox-caption').textContent")
    isopen = lambda: b.js("document.getElementById('lightbox').classList.contains('open')")
    c0 = cap(); ok(isopen() and c0.startswith("1 / "), "(setup) the lightbox is open on item 1", c0)
    b.swipe(300, 400, 100, 410); c1 = cap(); ok(c1.startswith("2 / "), "swiping left moves to the next item", f"{c0!r} -> {c1!r}")
    b.swipe(100, 400, 300, 395); c2 = cap(); ok(c2.startswith("1 / "), "swiping right moves back", f"{c1!r} -> {c2!r}")
    b.swipe(200, 400, 170, 402); ok(cap() == c2, "a drag shorter than 50px is ignored (no accidental page turn)", cap())
    b.swipe(200, 400, 210, 200); ok(isopen(), "swiping UP does not close it")
    b.swipe(200, 300, 205, 600); ok(not isopen(), "swiping DOWN closes the lightbox")
    shot(b, "10-after-swipe-close")
    # the arrows never cross a folder boundary
    b.goto(B + "/", 1.5)
    seen = b.js("""(() => { const dir = f => f.includes('/') ? f.slice(0, f.lastIndexOf('/')) : '';
        const sizes = {}; files.forEach(f => sizes[dir(f)] = (sizes[dir(f)] || 0) + 1);
        const start = files.findIndex(f => sizes[dir(f)] > 1 && dir(f) !== ''); if (start < 0) return null;
        openLightbox(start); const d = dir(files[start]), visited = [];
        for (let i = 0; i < sizes[d]; i++) { visited.push(dir(files[current])); navigate(1); }
        return {d, visited, back: files[current] === files[start], n: sizes[d]}; })()""")
    ok(seen is not None and all(v == seen["d"] for v in seen["visited"]) and seen["back"], "[viewer] the arrows stay inside the photo's own folder and wrap around within it", str(seen))
    ok(b.js("document.getElementById('lightbox-caption').textContent").endswith("/ " + str(seen["n"])) if seen else True, "[viewer] ...and the counter counts that folder's photos only", b.js("document.getElementById('lightbox-caption').textContent"))
    b.js("closeLightbox()")
    b.call("Emulation.setTouchEmulationEnabled", {"enabled": False})
    # ---------------- admin ----------------
    print("== admin")
    # ---------------- logging in the way a person does ----------------
    print("== a browser re-sending an old saved password")
    b.call("Network.setExtraHTTPHeaders", {"headers": {"Authorization": "Basic " + base64.b64encode(b"admin:a-password-saved-long-ago").decode()}})
    b.viewport(1280, 900); b.goto(B + "/?admin", 1.2)
    challenged = any("www-authenticate" in {k.lower() for k in e.get("params", {}).get("response", {}).get("headers", {})} for e in b.events if e.get("method") == "Network.responseReceived")
    ok(not challenged, "a stale saved password gets no WWW-Authenticate challenge, so the browser has nothing to open its own login dialog with")
    ok(b.js("document.querySelector('input[name=login_password]') !== null") and "saved password" in b.js("document.body.innerText"), "...it gets the ordinary password form, which says the saved password doesn't match", b.js("document.body.innerText")[:150])
    b.goto(B + "/?reveal", 1.0)
    ok(b.js("document.querySelector('input[name=login_password]') !== null") and not any("www-authenticate" in {k.lower() for k in e.get("params", {}).get("response", {}).get("headers", {})} for e in b.events if e.get("method") == "Network.responseReceived"), "the private view does the same")
    b.call("Network.setExtraHTTPHeaders", {"headers": {}})
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
    # ---------------- the folder tree in the sidebar ----------------
    print("== folder tree")
    b.viewport(1280, 900); b.goto(B + "/?admin&folder=Europe%2FFrance%2FParis", 1.0)
    cur = b.js("(() => { const a = document.querySelector('.admin-folder-nav a.current'); return a ? {w: getComputedStyle(a).fontWeight, name: a.querySelector('.fname').textContent} : null; })()")
    ok(cur is not None and cur["name"] == "Paris" and int(cur["w"]) >= 600, "the chosen folder in the sidebar is bold (like the active tab), and is the one being edited", str(cur))
    counts = b.js("[...document.querySelectorAll('.admin-folder-nav a')].map(a => [a.querySelector('.fname').textContent, a.querySelector('.fcount').textContent])")
    ok(counts and all(c[1].isdigit() for c in counts) and dict(counts).get("Paris") == "3", "every folder shows how many photos/videos are directly in it", str(counts)[:200])
    ttl = b.js("[...document.querySelectorAll('.admin-folder-nav a')].find(a => a.querySelector('.fname').textContent == 'Europe').title")
    ok("including subfolders" in ttl, "...and hovering a parent says how many there are including its subfolders", ttl)
    xs = b.js("(() => { const t = n => { const a = [...document.querySelectorAll('.admin-folder-nav a')].find(a => a.querySelector('.fname').textContent == n); return Math.round(a.querySelector('.fname').getBoundingClientRect().left); }; return {europe: t('Europe'), france: t('France'), paris: t('Paris'), germany: t('Germany'), root: t('Root')}; })()")
    ok(xs["europe"] == xs["root"] and xs["france"] > xs["europe"] and xs["paris"] > xs["france"] and xs["germany"] == xs["france"], "nested folders step in one level at a time (Europe, then France and Germany inside it, then Paris inside France)", str(xs))
    guide = b.js("(() => { const u = document.querySelector('.admin-folder-nav li > ul'); const c = getComputedStyle(u); return {w: c.borderLeftWidth, shown: c.display}; })()")
    ok(guide["w"] == "1px" and guide["shown"] != "none", "...with a guide line down the left of each group of children", str(guide))
    ok(b.js("document.querySelector('.admin-folder-nav li.has-children:not(.collapsed) a.current') !== null") and b.js("[...document.querySelectorAll('.admin-folder-nav li.has-children')].filter(li => li.querySelector('a.current')).every(li => !li.classList.contains('collapsed'))"), "the folders on the way to the chosen one are open")
    b.goto(B + "/?admin&folder=Asia", 1.0)
    state = b.js("(() => { const li = [...document.querySelectorAll('.admin-folder-nav li.has-children')].find(l => l.querySelector(':scope > a .fname').textContent == 'Europe'); return {folded: li.classList.contains('collapsed'), hidden: getComputedStyle(li.querySelector(':scope > ul')).display === 'none'}; })()")
    ok(state["folded"] and state["hidden"], "a branch that isn't on the path to the chosen folder starts folded away", str(state))
    b.js("[...document.querySelectorAll('.admin-folder-nav li.has-children')].find(l => l.querySelector(':scope > a .fname').textContent == 'Europe').querySelector(':scope > .ftoggle').click()"); b.drain(0.2)
    ok(b.js("getComputedStyle([...document.querySelectorAll('.admin-folder-nav li.has-children')].find(l => l.querySelector(':scope > a .fname').textContent == 'Europe').querySelector(':scope > ul')).display") != "none", "...and its chevron opens it")
    texts = b.js("(() => { const t = el => { const r = el.getBoundingClientRect(), c = getComputedStyle(el); return Math.round(r.left + parseFloat(c.borderLeftWidth) + parseFloat(c.paddingLeft)); }; const q = s => document.querySelector(s); return [t(q('.side-hidden')), t(q('.side-link')), t(q('.admin-folders > summary')), t(q('.admin-folder-nav a'))]; })()")
    ok(max(texts) - min(texts) <= 1, "the Hidden entry, Site settings, the FOLDERS label and the folder names all start in the same column", str(texts))
    sc = b.js("getComputedStyle(document.querySelector('.side-link')).color")
    ok(sc != b.js("getComputedStyle(document.querySelector('.admin-folder-nav a')).color") and sc.startswith("rgb(94, 234, 212)"), "Site settings is its own colour (teal), distinct from the folders and from Hidden's red", sc)

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
    geo = "(() => { const e = document.querySelector('.item-editor.open'), c = getComputedStyle(e), h = e.querySelector('.editor-head').getBoundingClientRect(), p = document.getElementById('photo-editor').getBoundingClientRect(); return {border: c.borderTopWidth, pad: c.paddingTop, margin: c.marginTop, headOffset: Math.round(h.top - p.top)}; })()"
    g2 = b.js(geo)
    b.js("document.querySelectorAll('.thumb')[0].click()"); b.drain(0.2); g1 = b.js(geo)
    ok(g1 == g2 and g1["border"] == "0px" and g1["pad"] == "0px", "the editor looks exactly the same for the second photo as for the first: no extra bar or padding above it", f"first={g1} second={g2}")
    b.js("document.querySelectorAll('.thumb')[1].click()"); b.drain(0.2)
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
    # ---------------- the notes reference ----------------
    b.js("document.querySelector('.admin-tabs a[data-tab=notes]').click()"); b.drain(0.4); shot(b, "17-notes-tab")
    geo = b.js("(() => { const r = document.querySelector('.notes-reference'), m = document.querySelector('.notes-main'); const a = r.getBoundingClientRect(), c = m.getBoundingClientRect(); return {shown: getComputedStyle(r).display !== 'none' && a.height > 200, beside: a.left >= c.right - 2, sticky: getComputedStyle(r).position, headings: [...r.querySelectorAll('h3')].map(h => h.textContent.trim()).length, folded: r.querySelectorAll('details').length}; })()")
    ok(geo["shown"] and geo["beside"] and geo["sticky"] == "sticky" and geo["headings"] >= 6 and geo["folded"] == 0, "the full notes reference sits beside the editors on a desktop, stays in view while scrolling, and nothing in it is folded away", str(geo))
    b.js("document.querySelector('textarea.adm-textarea').focus()")
    b.js("document.querySelector('.notes-reference .admin-insert-btn[data-snippet=\"{sold}\"]').click()"); b.drain(0.2)
    ok("{sold}" in b.js("document.querySelector('textarea.adm-textarea').value") or "{sold}" in b.js("[...document.querySelectorAll('textarea.adm-textarea')].map(t => t.value).join('|')"), "an Insert button in the reference puts its directive into the note you were typing in")
    b.viewport(390, 844, mobile=True); b.goto(B + "/?admin#notes", 1.2)
    phone = b.js("(() => { const r = document.querySelector('.notes-reference'), m = document.querySelector('.notes-main'); return {below: r.getBoundingClientRect().top >= m.getBoundingClientRect().bottom - 2, shown: getComputedStyle(r).display !== 'none', folded: r.querySelectorAll('details').length, overflow: document.documentElement.scrollWidth > innerWidth}; })()")
    ok(phone["shown"] and phone["below"] and phone["folded"] == 0 and not phone["overflow"], "on a phone the reference is still shown in full, below the editors, without sideways scrolling", str(phone))
    b.viewport(1280, 900); b.goto(B + "/?admin", 1.5)
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
    # ---------------- Site settings (a real person, real clicks) ----------------
    print("== site settings")
    SITE2 = WORK + "/site2"; os.makedirs(SITE2 + "/Sub")
    shutil.copy(HERE + "/../../../index.php", SITE2 + "/index.php")
    subprocess.run(["php", "-r", "$i=imagecreatetruecolor(80,60); imagejpeg($i, $argv[1]); imagejpeg($i, $argv[2]);", SITE2 + "/p.jpg", SITE2 + "/Sub/q.jpg"], check=True)
    open(SITE2 + "/clip.mp4", "wb").write(b"x")
    open(SITE2 + "/Sub/.gallery-items.php", "w").write("<?php http_response_code(404); exit; ?>\n" + '{"items":{"q.jpg":{"restriction":"private","caption":"a hidden one"}},"groups":[]}')
    EXTRA = WORK + "/extra-src"; os.makedirs(EXTRA)
    subprocess.run(["php", "-r", "$i=imagecreatetruecolor(80,60); imagejpeg($i, $argv[1]);", EXTRA + "/archive1.jpg"], check=True)
    open(SITE2 + "/.gallery-items.php", "w").write("<?php http_response_code(404); exit; ?>\n" + '{"items":{"p.jpg":{"caption":"Keep this caption"}},"groups":[],"displayName":"Keep this title"}')
    cfgfile = SITE2 + "/.gallery-config.php"
    guard = "<?php http_response_code(404); exit; ?>\n"
    open(cfgfile, "w").write(guard + '{"ADMIN_PASSWORD": "site-admin-pw-1"}\n')
    s2 = socket.socket(); s2.bind(("127.0.0.1", 0)); PORT2 = s2.getsockname()[1]; s2.close(); B2 = f"http://127.0.0.1:{PORT2}"
    srv2 = subprocess.Popen([os.environ.get("PHP_SERVER", "php"), "-S", f"127.0.0.1:{PORT2}", "-t", SITE2], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    time.sleep(1.0)
    b.viewport(1280, 900); b.goto(B2 + "/?admin", 1.0)
    b.js("document.querySelector('input[name=login_password]').focus()"); b.call("Input.insertText", {"text": "site-admin-pw-1"}); b.js("document.querySelector('form').requestSubmit()"); b.drain(1.5)
    b.js("document.querySelector('.side-link').click()"); b.drain(0.4); shot(b, "14-site-settings")
    vis2 = "[...document.querySelectorAll('.tab-panel')].filter(p => getComputedStyle(p).display !== 'none').map(p => p.dataset.tab)"
    cfg = lambda: open(cfgfile).read()
    ok(b.js(vis2) == ["site"] and b.js("document.querySelector('.side-link').classList.contains('active')"), "clicking Site settings in the sidebar shows the settings (and nothing else)", str(b.js(vis2)))
    ok(b.js("document.querySelectorAll('.site-row .switch input').length") >= 7 and not b.problems(IGN), "...as a list of switches, with no console or CSP errors", "; ".join(b.problems(IGN))[:300])
    ok(b.js("(() => { const n = document.querySelector('.site-settings-form .nojs-only'); return !n || getComputedStyle(n).display === 'none'; })()"), "there is no Save button to scroll to: it only exists for browsers without JavaScript")
    before_items = open(SITE2 + "/.gallery-items.php").read()
    b.js("window.__stay = 'same page'; window.__confirms = []; window.__answer = true; window.confirm = m => { window.__confirms.push(m); return window.__answer; };")
    tog = lambda name: b.js(f"document.querySelector('input[name=set_{name}][type=checkbox]').closest('.switch').querySelector('.slider').click()")
    chk = lambda name: b.js(f"document.querySelector('input[name=set_{name}][type=checkbox]').checked")
    state = lambda name: b.js(f"document.querySelector('input[name=set_{name}][type=checkbox]').closest('.ctl').querySelector('.rowstate').textContent")
    # a harmless switch saves by itself
    tog("TRACK_VIEWS"); b.drain(1.0)
    ok('"TRACK_VIEWS": true' in cfg() and state("TRACK_VIEWS") == "Saved." and b.js("window.__confirms.length") == 0, "flipping a harmless switch saves it on the spot, with no Save button and no question", f"{state('TRACK_VIEWS')!r} {cfg()!r}")
    ok(b.js("window.__stay") == "same page", "...without reloading the page")
    lefts = b.js("[...document.querySelectorAll('.site-row .switch')].map(e => Math.round(e.getBoundingClientRect().left))")
    ok(max(lefts) - min(lefts) <= 2, "...and the status message doesn't push that switch out of line with the others", str(lefts))
    # something that publishes content has its warning in orange text on the row, never in a dialog
    ok(b.js("(() => { const r = document.querySelector('input[name=set_ENABLE_VIDEO][type=checkbox]').closest('.site-row'); const w = r.querySelector('.risk'); return !!w && getComputedStyle(w).display !== 'none' && getComputedStyle(w).color.startsWith('rgb(245, 166, 35)') && w.textContent.includes('becomes visible to visitors'); })()"), "turning on videos is explained by orange text already on its row, naming what it publishes")
    tog("ENABLE_VIDEO"); b.drain(1.0)
    ok(chk("ENABLE_VIDEO") is True and '"ENABLE_VIDEO": true' in cfg() and '"TRACK_VIEWS": true' in cfg() and b.js("window.__confirms.length") == 0, "flipping it saves at once with no question, and the earlier change is still there (each save builds on the last)")
    ok(b.js("document.querySelector('input[name=set_ENABLE_VIDEO][type=checkbox]').closest('.site-row').querySelector('.offnote').hidden"), "the 'what turning it off does' note stays out of the way while it is on")
    # turning something off shows what that does, beside the switch, instead of asking
    tog("ENABLE_VIDEO"); b.drain(1.0)
    ok(chk("ENABLE_VIDEO") is False and '"ENABLE_VIDEO"' not in cfg() and b.js("window.__confirms.length") == 0, "turning videos off saves at once too, and the setting leaves the file (back to its default)")
    ok(b.js("(() => { const n = document.querySelector('input[name=set_ENABLE_VIDEO][type=checkbox]').closest('.site-row').querySelector('.offnote'); return !n.hidden && getComputedStyle(n).color.startsWith('rgb(245, 166, 35)') && n.textContent.includes('Nothing is deleted'); })()"), "...and an orange note appears beside it saying nothing is deleted")
    tog("ENABLE_VIDEO"); b.drain(1.0)
    ok(b.js("document.querySelector('input[name=set_ENABLE_VIDEO][type=checkbox]').closest('.site-row').querySelector('.offnote').hidden"), "...and goes away when it is switched back on")
    tog("ENABLE_VIDEO"); b.drain(1.0)
    # choices and numbers save on change
    b.js("(() => { const s = document.querySelector('select[name=set_PHOTO_SORT_ORDER]'); s.value = 'mtime'; s.dispatchEvent(new Event('change', {bubbles: true})); })()"); b.drain(1.0)
    b.js("(() => { const n = document.querySelector('input[name=set_MAP_CLUSTER_RADIUS_MILES]'); n.value = '25'; n.dispatchEvent(new Event('change', {bubbles: true})); })()"); b.drain(1.0)
    ok('"PHOTO_SORT_ORDER": "mtime"' in cfg() and '"MAP_CLUSTER_RADIUS_MILES": 25' in cfg(), "a choice and a number save as soon as they change")
    b.js("(() => { const n = document.querySelector('input[name=set_MAP_CLUSTER_RADIUS_MILES]'); n.value = '-5'; n.dispatchEvent(new Event('change', {bubbles: true})); })()"); b.drain(1.0)
    ok(b.js("document.querySelector('input[name=set_MAP_CLUSTER_RADIUS_MILES]').value") == "25" and '"MAP_CLUSTER_RADIUS_MILES": 25' in cfg(), "an invalid value is refused with the reason and the field goes back to what is saved")
    # a password has its own button
    b.js("document.querySelector('input[name=pw_private]').focus()"); b.call("Input.insertText", {"text": "viewer-pass"}); b.js("document.querySelector('input[name=pw_private2]').focus()"); b.call("Input.insertText", {"text": "viewer-pass"})
    b.js("document.querySelector('.pw-btn[data-pw=private]').click()"); b.drain(1.0)
    ok('"PRIVATE_VIEW_PASSWORD": "viewer-pass"' in cfg() and b.js("document.querySelector('input[name=pw_private]').value") == "", "setting the private-view password saves from the button beside it, and the boxes are cleared")
    b.js("document.querySelector('.pw-btn[data-pw=admin]').click()"); b.drain(0.6)
    ok("Type the new password first" in b.js("document.querySelector('.pw-btn[data-pw=admin]').parentNode.querySelector('.rowstate').textContent"), "pressing a password button with nothing typed says so")
    # enabling folders changes what the rest of the admin shows: it refreshes when you leave
    tog("AUTO_GROUP_BY_FOLDER"); b.drain(1.0)
    ok(chk("AUTO_GROUP_BY_FOLDER") is True and '"AUTO_GROUP_BY_FOLDER": true' in cfg() and b.js("window.__confirms.length") == 0 and "visible to visitors" in b.js("document.querySelector('input[name=set_AUTO_GROUP_BY_FOLDER][type=checkbox]').closest('.site-row').querySelector('.risk').textContent"), "turning subfolders on saves at once, with the orange note on its row saying what it publishes and no question")
    ok(b.js("!document.querySelector('.sitesync').hidden"), "after a change the panel says the other screens refresh when you leave it")
    b.drain(0.8)
    ok(b.js("document.querySelector('.admin-folder-nav a[href*=Sub]') !== null") and b.js("window.__stay") == "same page" and b.js(vis2) == ["site"], "the folder list on the left updates the moment subfolders are turned on, without leaving this screen or reloading the page", str(b.js(vis2)))
    # folders from elsewhere on the server: add and remove from the screen
    b.js("document.querySelector('.extra-name').value = 'OldTrip'; document.querySelector('.extra-path').value = %s;" % repr(EXTRA).replace("'", '"'))
    b.js("window.__confirms.length = 0"); b.js("document.querySelector('.extra-add-btn').click()"); b.drain(1.2)
    ok(b.js("window.__confirms.length") == 0 and "photo and video in that folder" in b.js("document.querySelector('.extra-folders').closest('.site-row').querySelector('.risk').textContent"), "adding a folder from elsewhere asks nothing: the orange note on the row already says it shows everything in it")
    ok("OldTrip" in b.js("document.querySelector('.extra-list').textContent") and EXTRA in b.js("document.querySelector('.extra-list').textContent") and '"EXTRA_FOLDERS"' in cfg(), "...then lists it on the screen and saves it", cfg()[:200])
    ok(b.js("document.querySelector('.admin-folder-nav a[href*=OldTrip]') !== null"), "...and the sidebar shows it straight away")
    b.js("document.querySelector('.extra-name').value = 'Bad'; document.querySelector('.extra-path').value = '/no/such/folder'; document.querySelector('.extra-add-btn').click()"); b.drain(1.0)
    ok("isn't a folder this server can read" in b.js("document.body.innerText") and "Bad" not in b.js("document.querySelector('.extra-list').textContent"), "a path that doesn't exist is refused with the reason, and isn't added")
    b.js("window.__confirms.length = 0"); b.js("document.querySelector('.extra-remove').click()"); b.drain(1.2)
    ok(b.js("window.__confirms.length") == 0 and "Nothing was deleted" in b.js("document.querySelector('.extra-folders').closest('.site-row').innerText") and '"EXTRA_FOLDERS"' not in cfg() and b.js("document.querySelector('.admin-folder-nav a[href*=OldTrip]') === null"), "removing it asks nothing, says nothing was deleted, takes it out of the file, and the sidebar drops it at once")
    # the nginx prefix
    b.js("window.__confirms.length = 0; (() => { const i = document.querySelector('input[name=set_NGINX_ACCEL_REDIRECT_PREFIX]'); i.value = 'kb'; i.dispatchEvent(new Event('change', {bubbles: true})); })()"); b.drain(1.0)
    ok('NGINX_ACCEL_REDIRECT_PREFIX' not in cfg() and b.js("document.querySelector('input[name=set_NGINX_ACCEL_REDIRECT_PREFIX]').value") == "", "an nginx prefix that isn't a plain path (no leading slash) is refused and the field clears back")
    b.js("window.__confirms.length = 0; (() => { const i = document.querySelector('input[name=set_NGINX_ACCEL_REDIRECT_PREFIX]'); i.value = '/kb'; i.dispatchEvent(new Event('change', {bubbles: true})); })()"); b.drain(1.0)
    ok(b.js("window.__confirms.length") == 0 and '"NGINX_ACCEL_REDIRECT_PREFIX": "/kb"' in cfg() and "every photo and video will stop loading" in b.js("document.querySelector('input[name=set_NGINX_ACCEL_REDIRECT_PREFIX]').closest('.site-row').querySelector('.risk').textContent"), "setting it saves at once; the orange note on its row already warns that photos and videos stop loading without the matching nginx setup")
    b.js("(() => { const i = document.querySelector('input[name=set_NGINX_ACCEL_REDIRECT_PREFIX]'); i.value = ''; i.dispatchEvent(new Event('change', {bubbles: true})); })()"); b.drain(1.0)
    ok('NGINX_ACCEL_REDIRECT_PREFIX' not in cfg(), "clearing it needs no question and takes it out of the file")
    b.js("document.querySelector('.admin-tabs a[data-tab=photos]').click()"); b.drain(1.8)
    ok(b.js("window.__stay") is None and b.js("document.querySelector('.admin-folder-nav a[href*=Sub]') !== null") and b.js(vis2) == ["photos"], "leaving Site settings after a change reloads, landing on the tab clicked, and the sidebar now lists the new folder", str(b.js(vis2)))
    # removing the private password: orange text on the row, no dialog
    b.js("document.querySelector('.admin-tabs a[data-tab=site]') ? document.querySelector('.admin-tabs a[data-tab=site]').click() : document.querySelector('.side-link').click()"); b.drain(0.5)
    b.js("window.__confirms = []; window.confirm = m => { window.__confirms.push(m); return true; };")
    ok(b.js("(() => { const w = document.querySelector('.pw-btn[data-pw=private-remove]').closest('.site-row').querySelector('.warn-note'); return !!w && getComputedStyle(w).color.startsWith('rgb(245, 166, 35)') && w.textContent.includes('nothing is deleted'); })()"), "the private-view row carries an orange note about what removing the password does")
    b.js("document.querySelector('.pw-btn[data-pw=private-remove]').click()"); b.drain(1.0)
    ok(b.js("window.__confirms.length") == 0 and 'PRIVATE_VIEW_PASSWORD' not in cfg(), "removing the private-view password saves at once with no question")
    # a hidden photo's thumbnail must load in the admin, even with no private-view password
    b.goto(B2 + "/?admin&folder=Sub#photos", 1.8)
    th = b.js("[...document.querySelectorAll('.thumb img')].map(i => [i.closest('.thumb').title, i.complete && i.naturalWidth > 0])")
    ok(len(th) >= 1 and any("hidden one" in t[0] for t in th) and all(t[1] for t in th), "the admin's thumbnails all load, including a hidden photo's (they were broken when no private-view password was set)", str(th))
    # the map and location switches are instant too
    b.goto(B2 + "/?admin#site", 1.2)
    tog("ENABLE_MAP"); b.drain(1.0); tog("ENABLE_PHOTO_LOCATION"); b.drain(1.0)
    b.goto(B2 + "/?admin&folder=Sub#photos", 1.5)
    ok(b.js("document.querySelectorAll('.map-form').length") == 1 and b.js("(() => { const n = document.querySelector('.map-form .nojs-only'); return getComputedStyle(n).display === 'none'; })()"), "[map] a folder's Map section is a switch and a number with no visible Save button")
    ok(b.js("(() => { const w = document.querySelector('.map-form .warn-note'); return getComputedStyle(w).color.startsWith('rgb(245, 166, 35)') && w.textContent.includes('OpenStreetMap'); })()"), "...with the orange note about the third-party map sites sitting right under the switch")
    b.js("window.__stay = 'same page'; window.__confirms = []; window.confirm = m => { window.__confirms.push(m); return true; };")
    mst = lambda sel: b.js(f"document.querySelector('{sel}').closest('.instant-row, .field').querySelector('.rowstate').textContent")
    b.js("document.querySelector('.map-form .switch .slider').click()"); b.drain(1.0)
    items_sub = lambda: json.loads(open(SITE2 + "/Sub/.gallery-items.php").read().split("?>\n", 1)[1])
    ok(items_sub().get("mapEnabled") is True and mst(".map-form input[name=map_enabled][type=checkbox]").startswith("Saved") and b.js("window.__stay") == "same page" and b.js("window.__confirms.length") == 0, "[map] flipping the switch saves in place: 'Saved', no reload, no dialog", mst(".map-form input[name=map_enabled][type=checkbox]"))
    b.js("(() => { const n = document.querySelector('.map-form input[name=map_radius]'); n.value = '15'; n.dispatchEvent(new Event('change', {bubbles: true})); })()"); b.drain(1.0)
    ok(items_sub().get("mapRadius") == 15 and items_sub().get("mapEnabled") is True, "[map] the pin grouping saves on change and leaves the switch alone")
    b.js("(() => { const n = document.querySelector('.map-form input[name=map_radius]'); n.value = '-3'; n.dispatchEvent(new Event('change', {bubbles: true})); })()"); b.drain(1.0)
    ok(b.js("document.querySelector('.map-form input[name=map_radius]').value") == "15" and "from 1 to 10000" in mst(".map-form input[name=map_radius]") and items_sub().get("mapRadius") == 15, "[map] an invalid pin grouping goes back to what was saved, with the reason beside it")
    b.js("document.querySelector('.map-form .switch .slider').click()"); b.drain(1.0)
    ok(items_sub().get("mapEnabled") is False and items_sub().get("mapRadius") == 15, "[map] switching it off keeps the radius")
    ok(b.js("document.querySelectorAll('.instant-form:not(.map-form)').length") >= 1 and b.js("(() => { const w = document.querySelector('.instant-form:not(.map-form) .warn-note'); return !!w && getComputedStyle(w).color.startsWith('rgb(245, 166, 35)'); })()"), "[location] the folder's location switch has its own orange warning beside it")
    b.js("document.querySelector('.instant-form:not(.map-form) .switch .slider').click()"); b.drain(1.0)
    ok(items_sub().get("locationEnabled") is True and b.js("window.__confirms.length") == 0 and b.js("window.__stay") == "same page", "[location] flipping it saves in place, no dialog")
    ok(not b.problems(IGN), "[map] none of that raised console or CSP errors", "; ".join(b.problems(IGN))[:300])
    cfg_now = cfg()
    ok(cfg_now.startswith(guard) and '"ADMIN_PASSWORD": "site-admin-pw-1"' in cfg_now, "the settings file is still the 404 guard plus JSON, and the admin password in it is untouched")
    ok(open(SITE2 + "/.gallery-items.php").read() == before_items, "none of it touched the captions and titles stored for the photos")
    b.viewport(390, 844, mobile=True); b.goto(B2 + "/?admin#site", 1.2); shot(b, "15-site-settings-phone")
    ok(b.js(overflow), "[site settings] no horizontal scrolling on a phone", f"scrollWidth={b.js('document.documentElement.scrollWidth')}")
    srv2.terminate(); srv2.wait()
    # ---------------- first-run setup (a gallery that was just uploaded) ----------------
    print("== first-run setup")
    SITE3 = WORK + "/site3"; os.makedirs(SITE3)
    shutil.copy(HERE + "/../../../index.php", SITE3 + "/index.php")          # just copied: inside the five-minute window
    subprocess.run(["php", "-r", "$i=imagecreatetruecolor(80,60); imagejpeg($i, $argv[1]);", SITE3 + "/p.jpg"], check=True)
    s3 = socket.socket(); s3.bind(("127.0.0.1", 0)); PORT3 = s3.getsockname()[1]; s3.close(); B3 = f"http://127.0.0.1:{PORT3}"
    srv3 = subprocess.Popen([os.environ.get("PHP_SERVER", "php"), "-S", f"127.0.0.1:{PORT3}", "-t", SITE3], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    time.sleep(1.0)
    b.viewport(1280, 900); b.goto(B3 + "/", 1.0)
    ok(b.js("document.querySelector('a[href^=\"?admin\"]') === null") and "Set up your gallery" not in b.js("document.body.innerText"), "a just-uploaded gallery's public page gives no hint that setup is open")
    b.goto(B3 + "/?admin", 1.0); shot(b, "16-first-run-setup")
    ok("Set up your gallery" in b.js("document.body.innerText") and b.js("document.activeElement && document.activeElement.name") == "setup_password", "opening ?admin right after upload shows the set-up page with the password field ready", b.js("document.body.innerText")[:120])
    b.js("document.querySelector('#setup_password').focus()"); b.call("Input.insertText", {"text": "my first admin password"})
    b.js("document.querySelector('#setup_password2').focus()"); b.call("Input.insertText", {"text": "my first admin password"})
    b.js("document.querySelector('form').requestSubmit()"); b.drain(1.8)
    ok(b.js("document.querySelector('h1') && document.querySelector('h1').textContent") == "Gallery Admin" and "admin password is set" in b.js("document.body.innerText"), "choosing a password lands in the admin, already signed in, with a message saying so", b.js("location.href"))
    ok(b.js("[...document.querySelectorAll('.tab-panel')].filter(p => getComputedStyle(p).display !== 'none').map(p => p.dataset.tab)") == ["site"], "...on Site settings, where the rest of the gallery-wide settings are")
    guard = "<?php http_response_code(404); exit; ?>\n"
    cfg3 = open(SITE3 + "/.gallery-config.php").read()
    ok(cfg3.startswith(guard) and '"ADMIN_PASSWORD": "my first admin password"' in cfg3, "the password is in the settings file (the 404 guard plus JSON)", cfg3.replace("\n", " ")[:200])
    b.goto(B3 + "/?admin", 1.0)
    ok(b.js("document.querySelector('h1').textContent") == "Gallery Admin", "reloading stays signed in")
    b.js("[...document.querySelectorAll('button')].find(x => x.textContent.trim() == 'Log out').click()"); b.drain(1.5)
    b.goto(B3 + "/?admin", 1.0)
    ok(b.js("document.querySelector('input[name=login_password]') !== null") and b.js("document.querySelector('input[name=setup_password]') === null"), "once logged out, ?admin is the login form and setup never comes back, though the file is still brand new")
    srv3.terminate(); srv3.wait()
    b.viewport(1280, 900)
except Exception as e:
    fails += 1; print("  FAIL  the browser run crashed:", type(e).__name__, e)
finally:
    if b: b.close()
    srv.terminate(); srv.wait(); shutil.rmtree(WORK, ignore_errors=True)
print(f"\n{'ALL BROWSER CHECKS PASSED' if not fails else str(fails) + ' BROWSER CHECK(S) FAILED'}" + (f" ({skips} skipped)" if skips else "") + (f"\nscreenshots: {SHOTS}" if SHOTS else ""))
sys.exit(1 if fails else 0)
