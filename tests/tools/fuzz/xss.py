#!/usr/bin/env python3
"""Cross-site-scripting canary test.

Plants hostile text in every input an owner or visitor can control — admin
form fields (caption, link, location label, folder/group names, description,
dates), a notes .md (text, headings, link targets, directive values), file and
folder NAMES, query parameters, and request headers — then crawls every page
(as an anonymous visitor, a revealed viewer and the admin) and fails if any
payload comes back *raw*, i.e. able to break out of its HTML/JS/attribute
context. Escaped output (&lt;svg ...) is fine; only the dangerous raw forms are
flagged. It is a source-level check, not a browser: it asks "could this reach
the page unescaped?", which is the question that matters.

    python3 tests/tools/fuzz/xss.py        # exit 0 only if nothing leaks
Needs python3 and php (gd + imagick for the fixture).
"""
import subprocess, time, urllib.request, urllib.parse, urllib.error, base64, re, os, sys, tempfile, shutil, socket, html
HERE = os.path.dirname(os.path.abspath(__file__))
WORK = tempfile.mkdtemp(prefix="gallery-xss-"); SITE = WORK + "/site"
def free_port():
    s = socket.socket(); s.bind(("127.0.0.1", 0)); p = s.getsockname()[1]; s.close(); return p
PORT = free_port(); B = f"http://127.0.0.1:{PORT}"
subprocess.run(["php", HERE + "/build_fixture.php", SITE], check=True, stdout=subprocess.DEVNULL)

# Each payload carries a unique token so a raw hit is unambiguous, and is built to
# break out of one specific context.
P = {
  "tag":    '"><svg onload=alert(XSSTOK1)>',            # out of an attribute into a tag
  "img":    "'><img src=x onerror=alert(XSSTOK2)>",     # same, single-quoted
  "script": "</script><script>alert(XSSTOK3)</script>", # out of an inline <script>
  "attr":   '" onmouseover="alert(XSSTOK5)',            # attribute breakout without any "<"
  "js":     "';alert(XSSTOK6);'",                        # out of a '...' JS string
}
# Raw (unescaped) forms that must never appear in any response.
DANGEROUS = [re.compile(re.escape(x)) for x in (
  "<svg onload=alert(XSSTOK1)", "<img src=x onerror=alert(XSSTOK2)", "</script><script>alert(XSSTOK3)",
  '" onmouseover="alert(XSSTOK5)', "';alert(XSSTOK6);'")]
JS_URL = re.compile(r'''(?:href|src|action)\s*=\s*["']\s*javascript:''', re.I)

# --- poison the filesystem first: names are attacker-shaped input too (no "/" allowed in a name)
os.makedirs(SITE + "/" + '"><svg onload=alert(XSSTOK1)>', exist_ok=True)
os.makedirs(SITE + "/" + "'><img src=x onerror=alert(XSSTOK2)>", exist_ok=True)
from shutil import copyfile
for d in ('"><svg onload=alert(XSSTOK1)>', "'><img src=x onerror=alert(XSSTOK2)>"):
    copyfile(SITE + "/loose1.jpg", f"{SITE}/{d}/p.jpg")
copyfile(SITE + "/loose1.jpg", SITE + "/" + "<img src=x onerror=alert(XSSTOK2)>.jpg")
copyfile(SITE + "/loose1.jpg", SITE + "/" + '" onmouseover="alert(XSSTOK5).jpg')
copyfile(SITE + "/loose1.jpg", SITE + "/" + "';alert(XSSTOK6);'.jpg")

srv = subprocess.Popen([os.environ.get("PHP_SERVER", "php"), "-d", "display_errors=1", "-d", "error_reporting=-1", "-d", "html_errors=0", "-S", f"127.0.0.1:{PORT}", "-t", SITE], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
time.sleep(1.2)
findings = []; pages = 0; reached = {"script-payload (caption/description/notes)": 0, "attr-payload (display name/dates/notes)": 0, "tag-payload": 0}
class NoRedir(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None
op = urllib.request.build_opener(NoRedir)
def fetch(path, method="GET", data=None, headers=None):
    r = urllib.request.Request(B + path, data=(urllib.parse.urlencode(data).encode() if data is not None else None), headers=headers or {}, method=method)
    try: resp = op.open(r, timeout=10); return resp.status, resp.read().decode("utf-8", "replace"), resp.headers
    except urllib.error.HTTPError as e: return e.code, e.read().decode("utf-8", "replace"), e.headers
SCRIPT_BLOCK = re.compile(r"<script\b[^>]*>.*?</script\s*>", re.S | re.I)
SCRIPT_BREAKOUT = re.compile(re.escape("</script><script>alert(XSSTOK3)"))
def check(label, body, headers=None):
    """Context-aware: inside a <script> element the browser treats everything as
    text, so a '<svg onload=...>' in a JS string is inert and only a raw
    </script> (or an unescaped JS-string quote) could break out; everywhere else
    any of the raw payload forms is dangerous."""
    global pages; pages += 1
    if "&lt;/script&gt;&lt;script&gt;alert(XSSTOK3)" in body: reached["script-payload (caption/description/notes)"] += 1
    if "&quot; onmouseover=&quot;alert(XSSTOK5)" in body: reached["attr-payload (display name/dates/notes)"] += 1
    if "&lt;svg onload=alert(XSSTOK1)&gt;" in body: reached["tag-payload"] += 1
    if SCRIPT_BREAKOUT.search(body):
        m = SCRIPT_BREAKOUT.search(body); findings.append((label, "RAW </script> BREAKOUT: " + body[max(0, m.start() - 60):m.end() + 40].replace("\n", " ")))
    html_only = SCRIPT_BLOCK.sub("", body)
    for rx in DANGEROUS:
        m = rx.search(html_only)
        if m: findings.append((label, "RAW PAYLOAD IN HTML: " + html_only[max(0, m.start() - 60):m.end() + 40].replace("\n", " ")))
    for blk in SCRIPT_BLOCK.findall(body):
        # In a script, the JS-string breakout "';alert(" only matters if the quote that
        # delimits the string is left unescaped — look for the payload's closing form
        # appearing outside a quoted string literal context.
        for m in re.finditer(r"(?<![\\\w])';alert\(XSSTOK6\);'", blk):
            ctx = blk[max(0, m.start() - 40):m.end() + 20]
            if '"' not in blk[max(0, m.start() - 3):m.start()] and "\\'" not in ctx: findings.append((label, "UNESCAPED JS STRING BREAKOUT: " + ctx.replace("\n", " ")))
    m = JS_URL.search(html_only)
    if m: findings.append((label, "javascript: URL in an attribute: " + html_only[max(0, m.start() - 40):m.end() + 60].replace("\n", " ")))
    if headers is not None:
        for k, v in headers.items():
            if re.search(r"XSSTOK", v) or "\n" in v or "\r" in v: findings.append((label, f"payload or CR/LF reflected in header {k}: {v[:100]}"))

# --- sign in (admin) and a revealed viewer
def cookies_from(path, auth):
    r = urllib.request.Request(B + path, headers={"Authorization": "Basic " + base64.b64encode(auth.encode()).decode()})
    try: resp = op.open(r, timeout=10)
    except urllib.error.HTTPError as e: resp = e
    return "; ".join(h.split(";")[0] for h in (resp.headers.get_all("Set-Cookie") or []))
admin_c = cookies_from("/?admin", "admin:adm-pw"); viewer_c = cookies_from("/?reveal", "viewer:viewer-pw")
AH = {"Authorization": "Basic " + base64.b64encode(b"admin:adm-pw").decode(), "Cookie": admin_c}

# --- mini browser: parse a page's POST forms, fill the text-ish fields with payloads, submit
def forms(page):
    out = []
    for m in re.finditer(r'<form\b([^>]*)>(.*?)</form>', page, re.S):
        if 'method="post"' not in m.group(1).lower(): continue
        body = m.group(2); f = {}
        for im in re.finditer(r'<input\b([^>]*)>', body):
            a = dict((k.lower(), html.unescape(v1 or v2 or "")) for k, v1, v2 in re.findall(r'([\w:-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')', im.group(1)))
            if "name" in a and a.get("type", "text") not in ("submit", "button", "file", "checkbox", "radio"): f[a["name"]] = a.get("value", "")
            elif "name" in a and a.get("type") == "checkbox" and "checked" in im.group(1): f[a["name"]] = a.get("value", "on")
        for tm in re.finditer(r'<textarea\b([^>]*)>(.*?)</textarea>', body, re.S):
            n = re.search(r'name="([^"]+)"', tm.group(1))
            if n: f[n.group(1)] = html.unescape(tm.group(2))
        for sm in re.finditer(r'<select\b([^>]*)>(.*?)</select>', body, re.S):
            n = re.search(r'name="([^"]+)"', sm.group(1)); o = re.search(r'<option\b[^>]*value="([^"]*)"', sm.group(2))
            if n and o: f[n.group(1)] = html.unescape(o.group(1))
        out.append(f)
    return out
FILL = {"caption": P["tag"] + P["script"], "web": "https://example.test/?x=" + urllib.parse.quote(P["tag"], safe="") .replace("%22", '"').replace("%3E", ">").replace("%3C", "<").replace("%20", " "),
        "loc_label": P["img"] + P["js"], "display_name": P["tag"] + P["attr"] + P["js"], "description": P["script"] + P["tag"] + P["img"], "dates": P["attr"] + P["tag"]}
NOTES = "{gallery}\n# " + P["tag"] + " " + P["script"] + "\n\nText " + P["img"] + " and **" + P["attr"] + "** with a [link](javascript:alert(XSSTOK4)) and [ok](" + 'https://e.test/"' + P["attr"] + ")" \
        + "\n\n{group: " + P["tag"] + P["script"] + "} {photos: loose1.jpg, " + P["tag"] + ".jpg} {color: " + P["attr"] + "}\n\n- " + P["tag"] + "\n- `" + P["script"] + "`\n\n```\n" + P["script"] + "\n```\n\n> " + P["img"] + "\n"
def paths_for_admin():
    s, pg, _ = fetch("/?admin", headers=AH); return ["/?admin"] + sorted(set("/?admin&folder=" + urllib.parse.quote(urllib.parse.unquote(m), safe="") for m in re.findall(r'href="\?admin&amp;folder=([^"]+)"', pg)))
poisoned = 0
for ap in paths_for_admin():
    s, pg, _ = fetch(ap, headers=AH)
    for f in forms(pg):
        data = dict(f)
        for k in list(data):
            if k in FILL: data[k] = FILL[k]
        if data.get("action") == "save_notes" or data.get("action") == "create_notes": data["content"] = NOTES
        if data.get("action") == "create_notes" or "new_name" in data and data.get("action") != "save_notes": data["new_name"] = "poison.md"
        if data.get("action") in ("delete_notes", "toggle_folder_restriction", "export_items", "export_tree"): continue
        s2, body2, h2 = fetch(ap, method="POST", data=data, headers=AH); poisoned += 1
        check("POST " + ap + " " + str(data.get("action")), body2, h2)
# also create a notes file with payloads in a poisoned folder and at the root
s, pg, _ = fetch("/?admin", headers=AH)
for f in forms(pg):
    if f.get("action") == "save_notes" and f.get("md_file", "").endswith(".md"): fetch("/?admin", "POST", dict(f, content=NOTES), AH)
fetch("/?admin", "POST", {"csrf_token": re.search(r'csrf_token" value="([a-f0-9]+)"', pg).group(1), "action": "save_notes", "md_file": "poison.md", "content": NOTES, "new_name": "poison.md"}, AH)

# --- crawl everything, as three kinds of visitor, with hostile query strings and headers
roots = ["/", "/?map", "/?about", "/?admin", "/?admin&hidden"]
s, pg, _ = fetch("/", headers={"Cookie": viewer_c}); links = set(re.findall(r'href="(\?folder=[^"]+)"', pg))
for _ in range(2):
    for l in list(links):
        s, sub, _ = fetch("/" + html.unescape(l), headers={"Cookie": viewer_c}); links |= set(re.findall(r'href="(\?folder=[^"]+)"', sub))
roots += ["/" + html.unescape(l) for l in links]
roots += ["/?admin&folder=" + urllib.parse.quote(urllib.parse.unquote(m), safe="") for m in re.findall(r'folder=([^"&]+)', fetch("/?admin", headers=AH)[1])]
for k, v in P.items():
    q = urllib.parse.quote(v, safe="")
    roots += [f"/?photo={q}", f"/?folder={q}", f"/?img={q}", f"/?track={q}", f"/?video={q}", f"/?map&x={q}", f"/?about&x={q}", f"/?size={q}&img=loose1.jpg", f"/?admin&folder={q}"]
for who, h in (("anon", {}), ("revealed", {"Cookie": viewer_c}), ("admin", AH)):
    for p in sorted(set(roots)):
        if p == "/?admin" and who != "admin": continue
        hh = dict(h); hh.update({"User-Agent": P["tag"] + P["script"], "Referer": "http://x.test/" + P["tag"], "X-Forwarded-Proto": "https", "X-Forwarded-For": P["tag"], "Accept-Language": P["img"]})
        s, body, hd = fetch(p, headers=hh); check(f"[{who}] GET {p[:70]}", body, hd)
    for host in (P["tag"], P["script"], P["attr"], "evil.test:80/" + P["tag"], "a b"):
        try:
            r = subprocess.run(["curl", "-s", "-m", "8", "-H", f"Host: {host}", "-H", "Cookie: " + h.get("Cookie", ""), B + "/"], capture_output=True, text=True)
            check(f"[{who}] Host: {host[:30]}", r.stdout)
            r = subprocess.run(["curl", "-s", "-m", "8", "-H", f"Host: {host}", B + "/?map"], capture_output=True, text=True); check(f"[{who}] ?map Host: {host[:30]}", r.stdout)
        except Exception: pass
    # hostile cookies the script itself reads
    for ck in ("gallery_viewed=" + urllib.parse.quote(P["tag"]), "PHPSESSID=" + urllib.parse.quote(P["script"]), "gallery_reveal=" + urllib.parse.quote(P["img"])):
        s, body, hd = fetch("/", headers=dict(h, Cookie=(h.get("Cookie", "") + "; " + ck).strip("; "))); check(f"[{who}] cookie {ck[:24]}", body, hd)

srv.terminate(); srv.wait()
print(f"admin forms submitted with payloads: {poisoned}   pages checked: {pages}   findings: {len(findings)}")
print("proof the payloads were stored and came back *escaped* (pages showing each):", reached)
if not all(reached.values()):
    findings.append(("self-check", "a payload never reached any page, so the clean result above proves nothing for it: " + str(reached)))
seen = set()
for lab, why in findings:
    k = (why[:70])
    if k in seen: continue
    seen.add(k); print("FINDING:", lab, "->", why)
shutil.rmtree(WORK, ignore_errors=True)
sys.exit(1 if findings else 0)
