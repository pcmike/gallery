#!/usr/bin/env python3
"""The Health check's list, in a real browser: the "N optional" / Details button, and what the buttons inside the list do.

    python3 -I tests/tools/browser/health_checks.py

Makes a gallery with older visitor logs left behind (what versions before 2.6.5 kept), then, in Chromium (in the admin's frame, as
the desktop shows it, and on the plain admin page): the button opens and closes the list again and again; "Remove them" takes the old
logs away, the page reloads and the list is STILL OPEN, with the row gone. Exit status 0 only if every check passes.
"""
import os, sys, json, time, socket, shutil, subprocess, tempfile
HERE = os.path.dirname(os.path.abspath(__file__)); sys.path.insert(0, HERE)
from cdp import Browser
fails = 0
def ok(cond, msg, detail=""):
    global fails; print(("  ok    " if cond else "  FAIL  ") + msg + ("" if cond else f"  [{detail}]")); fails += (not cond)
G = "<?php http_response_code(404); exit; ?>\n"
W = tempfile.mkdtemp(prefix="gallery-health-"); S = W + "/photos"; srv = None
PW = subprocess.run(["php", "-r", "echo password_hash($argv[1], PASSWORD_BCRYPT);", "hc-pw-1234"], capture_output=True, text=True).stdout   # (once: a new hash would sign the browser out)
def site():
    shutil.rmtree(S, ignore_errors=True); os.makedirs(S + "/Rome")
    subprocess.run(["php", "-r", '$i=imagecreatetruecolor(300,200); imagejpeg($i,$argv[1]);', S + "/Rome/a.jpg"], check=True)
    shutil.copy(HERE + "/../../../index.php", S + "/index.php"); os.utime(S + "/index.php", (time.time() - 3600,) * 2)
    open(S + "/.gallery-config.php", "w").write(G + json.dumps({"ADMIN_PASSWORD": PW, "AUTO_GROUP_BY_FOLDER": True, "ENABLE_SHARE_LINKS": True}) + "\n")
    for n in ("0123456789.php", "0123456789-items.php", "0123456789-q.php"): open(S + "/.gallery-linklog-" + n, "w").write(G + "{}")
    rec = {"links": {"ffffffffff": {"id": "ffffffffff", "label": "Public address", "token": "", "kind": "public", "status": "active", "scope": {"type": "public", "folders": [], "photos": []}, "created": int(time.time())}}}
    open(S + "/.gallery-links.php", "w").write(G + json.dumps(rec) + "\n")
try:
    site()
    s = socket.socket(); s.bind(("127.0.0.1", 0)); P = s.getsockname()[1]; s.close(); B = f"http://127.0.0.1:{P}"
    srv = subprocess.Popen(["php", "-S", f"127.0.0.1:{P}", "-t", W], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL); time.sleep(1.0)
    with Browser() as b:
        b.viewport(1280, 900)
        b.goto(B + "/photos/?admin", 1.5)
        b.js("document.querySelector('input[name=access_code]').focus()"); b.call("Input.insertText", {"text": "hc-pw-1234"}); b.js("document.querySelector('form').requestSubmit()"); b.drain(2.5)
        for mode in ("shell", "plain"):
            site()   # (fresh old logs for each way of looking)
            b.goto(B + "/photos/?admin&shell=1&tab=site" if mode == "shell" else B + "/photos/?admin&classic=1#site", 2.5)
            D = "document.querySelector('iframe').contentDocument" if mode == "shell" else "document"
            q = lambda e: b.js(e.replace("DOC", D))
            ok(q("!!DOC.querySelector('.health-toggle') && DOC.querySelector('.health-toggle').checkVisibility()"), f"[health:{mode}] the button that opens the list is there and visible")
            q("DOC.querySelector('.health-toggle').click()"); b.drain(0.4)
            ok(q("DOC.getElementById('health-panel').classList.contains('show-all') && !!DOC.querySelector('[data-oldlogs-remove]') && DOC.querySelector('[data-oldlogs-remove]').checkVisibility()"), f"[health:{mode}] it opens the list, and the older visitor logs row with its Remove button is in it")
            ok("3 files" in q("DOC.querySelector('[data-oldlogs-remove]').closest('.health-row').innerText"), f"[health:{mode}] the row says how many files are left behind", q("DOC.querySelector('[data-oldlogs-remove]').closest('.health-row').innerText")[:120])
            q("DOC.querySelector('[data-oldlogs-remove]').click()")
            # the page reloads; look at once, and then as the checks finish
            seen_hidden = False; end = time.time() + 12; state = None
            while time.time() < end:
                b.drain(0.1)
                try:
                    state = q("(() => { const p = DOC.getElementById('health-panel'), t = DOC.querySelector('.health-toggle'); return p && t ? {vis: t.checkVisibility(), open: p.classList.contains('show-all'), aria: t.getAttribute('aria-expanded'), row: !!DOC.querySelector('[data-oldlogs-remove]')} : null; })()")
                except Exception: state = None
                if state and not state["row"] and state["open"] and not any(f.startswith(".gallery-linklog") for f in os.listdir(S)): break
            ok(state is not None and state["open"] and state["aria"] == "true" and not state["row"], f"[health:{mode}] ...the list is still open, and the row is gone", str(state))
            left = sorted(f for f in os.listdir(S) if "linklog" in f); links = json.loads(open(S + "/.gallery-links.php").read().split("\n", 1)[1])["links"]
            ok(left == [] and "ffffffffff" not in links, f"[health:{mode}] the three files and the public address's stand-in record are gone", str(left))
            for i in range(3):
                q("DOC.querySelector('.health-toggle').click()"); b.drain(0.5)
                want = (i % 2 == 0)   # open at the start, so the first press closes it
                ok(q("DOC.getElementById('health-panel').classList.contains('show-all')") != want, f"[health:{mode}] press {i + 1}: the button {'closes' if want else 'opens'} the list")
            # a reload starts the list closed again (it only starts open straight after Remove / Set aside)
            q("DOC.getElementById('health-panel').classList.contains('show-all') || DOC.querySelector('.health-toggle').click()"); b.drain(0.5)
            ok(q("DOC.getElementById('health-panel').classList.contains('show-all')"), f"[health:{mode}] (the list is open before the reload)")
            b.js("location.reload()"); b.drain(2.5)
            if mode == "shell": b.goto(B + "/photos/?admin&shell=1&tab=site", 2.5)
            ok(not q("DOC.getElementById('health-panel').classList.contains('show-all')") and q("DOC.querySelector('.health-toggle').getAttribute('aria-expanded')") == "false", f"[health:{mode}] after an ordinary reload the list starts closed")
finally:
    if srv: srv.terminate()
    shutil.rmtree(W, ignore_errors=True)
print("\nALL HEALTH CHECKS PASSED" if not fails else f"\n{fails} HEALTH CHECK(S) FAILED"); sys.exit(1 if fails else 0)
