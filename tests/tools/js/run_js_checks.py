#!/usr/bin/env python3
"""Check the gallery's inline JavaScript without a browser.

For each page type it (1) extracts every inline <script> and syntax-checks it,
then (2) actually runs the real scripts under QuickJS against a small fake DOM
and a fake Leaflet (see dom.js) and asserts on what they do: lightbox open /
navigate / keyboard / video, no filename ever shown, view-count updates, the
caption popover, copy-link, the map's pins and cluster-click, and the admin's
collapse-on-phone and insert-at-cursor. It cannot see real rendering, real
Leaflet or CSS -- it is a safety net for logic errors and typos, not a browser.

Needs python3, php (gd + imagick, for the fixture) and QuickJS (`qjs`):
    QJS=/path/to/qjs python3 tests/tools/js/run_js_checks.py
(QJS defaults to `qjs` on PATH. On Debian: `apt-get download quickjs`, then
`dpkg -x quickjs_*.deb some-dir` -- no root needed -- and point QJS at
some-dir/usr/bin/qjs.)

Exit status 0 only if every check passes.
"""
import subprocess, time, urllib.request, urllib.error, base64, re, os, sys, tempfile, shutil, socket
HERE = os.path.dirname(os.path.abspath(__file__))
QJS = os.environ.get("QJS") or shutil.which("qjs")
if not QJS or not os.path.exists(QJS):
    print("QuickJS not found. Set QJS=/path/to/qjs (see this file's docstring)."); sys.exit(2)
WORK = tempfile.mkdtemp(prefix="gallery-js-"); SITE = WORK + "/site"; os.makedirs(WORK + "/js")
s = socket.socket(); s.bind(("127.0.0.1", 0)); PORT = s.getsockname()[1]; s.close(); B = f"http://127.0.0.1:{PORT}"
subprocess.run(["php", HERE + "/../fuzz/build_fixture.php", SITE], check=True, stdout=subprocess.DEVNULL)
srv = subprocess.Popen([os.environ.get("PHP_SERVER", "php"), "-S", f"127.0.0.1:{PORT}", "-t", SITE], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
failures = 0
try:
    time.sleep(1.2)
    def get(path, headers=None):
        try: return urllib.request.urlopen(urllib.request.Request(B + path, headers=headers or {}), timeout=10).read().decode("utf-8", "replace")
        except urllib.error.HTTPError as e: return e.read().decode("utf-8", "replace")
    auth = {"Authorization": "Basic " + base64.b64encode(b"admin:adm-pw").decode()}
    pages = {"gallery": ("/", {}), "folder": ("/?folder=Europe", {}), "nested": ("/?folder=Europe%2FFrance%2FParis", {}), "map": ("/?map", {}), "admin": ("/?admin", auth), "admin-folder": ("/?admin&folder=Asia", auth)}
    html = {}; scripts = {}
    for name, (path, h) in pages.items():
        html[name] = get(path, h); open(f"{WORK}/{name}.html", "w", encoding="utf-8").write(html[name])
        scripts[name] = [m.group(1) for m in re.finditer(r"<script(?![^>]*\bsrc=)[^>]*>(.*?)</script>", html[name], re.S)]
        for i, js in enumerate(scripts[name]): open(f"{WORK}/js/{name}-{i}.js", "w", encoding="utf-8").write(js)
    def run(args, label):
        global failures
        r = subprocess.run([QJS, "--std", *args], capture_output=True, text=True, timeout=60, cwd=HERE)
        out = (r.stdout + r.stderr).strip()
        print(f"=== {label}"); print("\n".join("  " + l for l in out.splitlines()[-60:]))
        if r.returncode != 0 or "FAIL" in out: failures += 1
    # 1. syntax of every inline script
    total = bad = 0
    for name in pages:
        for i in range(len(scripts[name])):
            total += 1
            r = subprocess.run([QJS, "--std", HERE + "/syn.js", f"{WORK}/js/{name}-{i}.js"], capture_output=True, text=True)
            if not (r.stdout + r.stderr).strip().startswith("OK"): bad += 1; print(f"SYNTAX ERROR in {name} script #{i}: {(r.stdout + r.stderr).strip()[:300]}")
    print(f"=== syntax: {total} inline scripts checked, {bad} with errors"); failures += bad
    # 2. behaviour
    mapi = next(i for i, js in enumerate(scripts["map"]) if "var clusters =" in js)
    run(["maprun.js", f"{WORK}/map.html", f"{WORK}/js/map-{mapi}.js"], "map script, real data")
    run(["maprun.js", f"{WORK}/map.html", f"{WORK}/js/map-{mapi}.js", HERE + "/clusters.json"], "map script, synthetic cluster (exercises pin-click expansion)")
    gi = max(range(len(scripts["gallery"])), key=lambda i: len(scripts["gallery"][i]))
    run(["galleryrun.js", f"{WORK}/gallery.html", f"{WORK}/js/gallery-{gi}.js"], "gallery script (lightbox, captions, tracking, popover, copy-link)")
    ni = max(range(len(scripts["nested"])), key=lambda i: len(scripts["nested"][i]))
    run(["galleryrun.js", f"{WORK}/nested.html", f"{WORK}/js/nested-{ni}.js"], "gallery script on a nested folder page")
    ai = next(i for i, js in enumerate(scripts["admin"]) if "admConfirmSaveAs" in js)   # (not the first one: the share kit's script can come before it)
    run(["adminrun.js", f"{WORK}/js/admin-{ai}.js"], "admin script (collapse on phone, insert at cursor, Save As confirm)")
finally:
    srv.terminate(); srv.wait(); shutil.rmtree(WORK, ignore_errors=True)
print("\nALL JS CHECKS PASSED" if not failures else f"\n{failures} JS CHECK GROUP(S) FAILED")
sys.exit(1 if failures else 0)
