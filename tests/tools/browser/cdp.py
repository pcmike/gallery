"""A tiny Chrome DevTools Protocol client using only the standard library
(a hand-rolled WebSocket), so the browser checks need nothing but Chromium."""
import sys, socket, base64, os, struct, json, time, subprocess, tempfile, shutil, urllib.request, urllib.parse, atexit, signal, glob

class WS:
    def __init__(self, url):
        u = urllib.parse.urlparse(url); self.s = socket.create_connection((u.hostname, u.port), timeout=30)
        key = base64.b64encode(os.urandom(16)).decode()
        self.s.sendall((f"GET {u.path} HTTP/1.1\r\nHost: {u.hostname}:{u.port}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
                        f"Sec-WebSocket-Key: {key}\r\nSec-WebSocket-Version: 13\r\n\r\n").encode())
        buf = b""
        while b"\r\n\r\n" not in buf: buf += self.s.recv(4096)
        if b" 101 " not in buf.split(b"\r\n")[0]: raise RuntimeError("websocket handshake failed: " + buf[:200].decode("latin1"))
        self.rest = buf.split(b"\r\n\r\n", 1)[1]
    def _read(self, n):
        while len(self.rest) < n:
            chunk = self.s.recv(65536)
            if not chunk: raise EOFError("websocket closed")
            self.rest += chunk
        out, self.rest = self.rest[:n], self.rest[n:]; return out
    def send(self, text):
        data = text.encode(); hdr = bytearray([0x81]); n = len(data)
        if n < 126: hdr.append(0x80 | n)
        elif n < 65536: hdr.append(0x80 | 126); hdr += struct.pack(">H", n)
        else: hdr.append(0x80 | 127); hdr += struct.pack(">Q", n)
        mask = os.urandom(4); hdr += mask
        self.s.sendall(bytes(hdr) + bytes(b ^ mask[i % 4] for i, b in enumerate(data)))
    def recv(self):
        msg = b""
        while True:
            b0, b1 = self._read(2); fin, op = b0 & 0x80, b0 & 0x0F; n = b1 & 0x7F
            if n == 126: n = struct.unpack(">H", self._read(2))[0]
            elif n == 127: n = struct.unpack(">Q", self._read(8))[0]
            payload = self._read(n)
            if op == 0x8: raise EOFError("websocket closed by browser")
            if op == 0x9: continue
            msg += payload
            if fin: return msg.decode("utf-8", "replace")

STRICT = os.environ.get("GALLERY_BROWSER_STRICT") == "1"   # 1: every wait runs for its full length (the slow, literal mode)
WATCHED_TYPES = {"Document", "Stylesheet", "Script", "Image", "Media", "Font", "XHR", "Fetch"}   # not EventSource / WebSocket: they never finish
# Installed in every page: what drain() asks "has this page gone quiet?" (timers up to 3 s and animation frames are counted while pending;
# any DOM change, scroll or resize restarts the 150 ms quiet period; a running finite animation or transition is not quiet).
SETTLE_JS = """(() => {
  if (window.__settle) return;
  const S = {timers: new Set(), frames: new Set(), last: performance.now()}, st = window.setTimeout, ct = window.clearTimeout, raf = window.requestAnimationFrame, caf = window.cancelAnimationFrame;
  const touch = () => { S.last = performance.now(); };
  window.setTimeout = function (f, d, ...a) {
    if (typeof f !== 'function' || !(Number(d || 0) <= 3000)) return st.call(this, f, d, ...a);
    const id = st.call(this, function () { S.timers.delete(id); touch(); return f.apply(this, arguments); }, d, ...a); S.timers.add(id); return id; };
  window.clearTimeout = function (id) { S.timers.delete(id); return ct.call(this, id); };
  window.requestAnimationFrame = function (f) { const id = raf.call(this, function () { S.frames.delete(id); touch(); return f.apply(this, arguments); }); S.frames.add(id); return id; };
  window.cancelAnimationFrame = function (id) { S.frames.delete(id); return caf.call(this, id); };
  new MutationObserver(touch).observe(document, {subtree: true, childList: true, attributes: true, characterData: true});
  addEventListener('scroll', touch, true); addEventListener('resize', touch, true);
  window.__settle = () => document.readyState === 'complete' && S.timers.size === 0 && S.frames.size === 0 && performance.now() - S.last >= 150 &&
    !document.getAnimations().some(x => x.playState === 'running' && x.effect && x.effect.getComputedTiming().iterations !== Infinity);
})()"""

STATS = {} if os.environ.get("GALLERY_BROWSER_STATS") == "1" else None   # 1: print where the waiting went when the run ends

def _print_stats():
    if not STATS: return
    asked = sum(r[1] for r in STATS.values()); spent = sum(r[2] for r in STATS.values())
    print(f"\nwaits: {sum(r[0] for r in STATS.values())} calls, asked for {asked:.0f} s, actually waited {spent:.0f} s")
    for k, r in sorted(STATS.items(), key=lambda kv: -kv[1][2])[:12]: print(f"  {k:60} {r[0]:4} calls  asked {r[1]:6.1f} s  waited {r[2]:6.1f} s")

PROFILE_PREFIX = "gallery-chromium-"
_live = []   # browsers started by this process and not yet closed

def _cleanup_all():
    for b in list(_live):
        b.close()

def _on_signal(signum, frame):
    # `timeout` (SIGTERM) and a closed terminal (SIGHUP) end Python without running `finally` blocks, which used to leave
    # Chromium running and its ~90 MB profile behind (in /tmp, which is RAM on some machines). Raising SystemExit instead
    # lets every `finally` and the atexit hook below run. Ctrl-C already raises KeyboardInterrupt, so it is left alone.
    raise SystemExit(128 + signum)

def _sweep_stale_profiles(min_age=600):
    """Remove profile folders left by runs that were killed hard (SIGKILL, the OOM killer): old enough, and no process still has them open."""
    in_use = ""
    for f in glob.glob("/proc/[0-9]*/cmdline"):
        try: in_use += open(f, "rb").read().replace(b"\0", b" ").decode("utf-8", "replace") + "\n"
        except OSError: pass
    for d in glob.glob(os.path.join(tempfile.gettempdir(), PROFILE_PREFIX + "*")):
        try:
            if time.time() - os.path.getmtime(d) > min_age and d not in in_use: shutil.rmtree(d, ignore_errors=True)
        except OSError: pass

atexit.register(_cleanup_all)
atexit.register(_print_stats)
for _sig in (signal.SIGTERM, signal.SIGHUP):
    try: signal.signal(_sig, _on_signal)
    except (ValueError, OSError): pass   # not the main thread

class Browser:
    proc = None; udd = None
    def __init__(self, chromium=None):
        self.chromium = chromium or os.environ.get("CHROMIUM") or shutil.which("chromium") or shutil.which("chromium-browser") or shutil.which("google-chrome")
        if not self.chromium: raise SystemExit("Chromium not found. Install it (e.g. `sudo apt install chromium`) or set CHROMIUM=/path/to/chromium.")
        _sweep_stale_profiles()
        self.udd = tempfile.mkdtemp(prefix=PROFILE_PREFIX)
        _live.append(self)
        try: self._start()
        except BaseException: self.close(); raise
    def _start(self):
        s = socket.socket(); s.bind(("127.0.0.1", 0)); self.port = s.getsockname()[1]; s.close()
        self.proc = subprocess.Popen([self.chromium, "--headless=new", "--no-sandbox", "--disable-gpu", "--disable-dev-shm-usage", "--no-first-run",
            "--hide-scrollbars", f"--user-data-dir={self.udd}", f"--remote-debugging-port={self.port}", "about:blank"], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, start_new_session=True)   # its own process group, so close() reaches every helper process
        for _ in range(100):
            try:
                tabs = json.load(urllib.request.urlopen(f"http://127.0.0.1:{self.port}/json", timeout=2))
                page = next(t for t in tabs if t["type"] == "page"); break
            except Exception: time.sleep(0.2)
        else: raise SystemExit("Chromium did not start")
        self.ws = WS(page["webSocketDebuggerUrl"]); self.id = 0; self.events = []; self.pending = {}
        for m in ("Page.enable", "Runtime.enable", "Log.enable", "Network.enable"): self.call(m)
        self.call("Page.addScriptToEvaluateOnNewDocument", {"source": SETTLE_JS})
    def call(self, method, params=None, timeout=30):
        self.id += 1; mid = self.id; self.ws.send(json.dumps({"id": mid, "method": method, "params": params or {}})); end = time.time() + timeout
        while time.time() < end:
            m = json.loads(self.ws.recv())
            if m.get("id") == mid:
                if "error" in m: raise RuntimeError(f"{method}: {m['error']}")
                return m.get("result", {})
            if "method" in m: self.events.append(m); self._note(m)
        raise TimeoutError(method)
    def _note(self, m):
        """Keep a count of requests still in flight (pictures, scripts, fetches), for drain()."""
        meth = m.get("method"); p = m.get("params") or {}
        if meth == "Network.requestWillBeSent" and p.get("type") in WATCHED_TYPES: self.pending[p["requestId"]] = time.time()
        elif meth in ("Network.loadingFinished", "Network.loadingFailed"): self.pending.pop(p.get("requestId"), None)
    def drain(self, seconds=0.0):
        t0 = time.time()
        try: self._drain(seconds)
        finally:
            if STATS is not None:
                f = sys._getframe(1); k = f"{os.path.basename(f.f_code.co_filename)}:{f.f_lineno}"; r = STATS.setdefault(k, [0, 0.0, 0.0]); r[0] += 1; r[1] += seconds; r[2] += time.time() - t0
    def _drain(self, seconds=0.0):
        """Collect events for up to `seconds` (without sending anything). Unless GALLERY_BROWSER_STRICT=1, a wait of 0.25-2.9 s ends early
        once the page has gone quiet: nothing in flight on the network, no timer or animation frame pending, no running animation or
        transition, no DOM change or scroll for 150 ms (see SETTLE_JS), checked twice in a row after a short floor. A page that never goes
        quiet (a poll loop) simply uses the whole wait. Longer waits are real-time waits and always run in full."""
        self.ws.s.settimeout(0.2)
        if STRICT or seconds < 0.25 or seconds >= 3:
            end = time.time() + seconds
            while time.time() < end: self._read_event()
            self.ws.s.settimeout(30); return
        start = time.time(); end = start + seconds; quiet = 0
        while time.time() < end:
            self._read_event(0.05)
            if time.time() - start < 0.12: continue
            alive = [t for t in self.pending.values() if time.time() - t < 20]
            if not alive and self._quiet(): quiet += 1
            else: quiet = 0
            if quiet >= 2: break
        self.ws.s.settimeout(30)
    def _read_event(self, wait=None):
        if wait: self.ws.s.settimeout(wait)
        try: m = json.loads(self.ws.recv()); self.events.append(m); self._note(m)
        except (socket.timeout, TimeoutError): pass
    def _quiet(self):
        self.ws.s.settimeout(30)
        try: return self.js("!!(window.__settle && window.__settle())") is True
        except Exception: return False
    def goto(self, url, wait=1.2):
        self.events = []; self.pending = {}; t0 = time.time(); self.call("Page.navigate", {"url": url}); end = t0 + 30
        while time.time() < end:
            seen = {e.get("method") for e in self.events}
            if "Page.loadEventFired" in seen: break
            # a link that only changes the #fragment is a jump inside the same page: no load event ever comes, the browser says so instead
            if "Page.navigatedWithinDocument" in seen and "Page.frameNavigated" not in seen and "Page.frameStoppedLoading" in seen: break
            self.ws.s.settimeout(0.02); self._read_event(); self.ws.s.settimeout(30)
        else: print(f"  note  goto: no load event within 30 s for {url} (carried on)", flush=True)
        if time.time() - t0 > 20 and STATS is not None:
            f = sys._getframe(1); k = f"SLOW goto from {os.path.basename(f.f_code.co_filename)}:{f.f_lineno} " + ",".join(sorted({e.get("method", "?") for e in self.events})); k = k[:200]; r = STATS.setdefault(k, [0, 0.0, 0.0]); r[0] += 1; r[2] += time.time() - t0
        if STATS is not None:
            k = "load " + urllib.parse.urlparse(url).path[-40:] + "?" + urllib.parse.urlparse(url).query[:30]; r = STATS.setdefault(k, [0, 0.0, 0.0]); r[0] += 1; r[2] += time.time() - t0
        self.drain(wait)
    def js(self, expr):
        r = self.call("Runtime.evaluate", {"expression": expr, "returnByValue": True, "awaitPromise": True})
        if "exceptionDetails" in r: raise RuntimeError("page JS error: " + json.dumps(r["exceptionDetails"])[:300])
        return r["result"].get("value")
    def viewport(self, w, h, mobile=False):
        self.call("Emulation.setDeviceMetricsOverride", {"width": w, "height": h, "deviceScaleFactor": 1, "mobile": mobile})
    def key(self, key, code, vk):
        for t in ("keyDown", "keyUp"): self.call("Input.dispatchKeyEvent", {"type": t, "key": key, "code": code, "windowsVirtualKeyCode": vk})
    def swipe(self, x1, y1, x2, y2, steps=6):
        """A real touch drag: touchStart, a few touchMoves, touchEnd (needs touch emulation on)."""
        self.call("Input.dispatchTouchEvent", {"type": "touchStart", "touchPoints": [{"x": x1, "y": y1}]})
        for i in range(1, steps + 1):
            self.call("Input.dispatchTouchEvent", {"type": "touchMove", "touchPoints": [{"x": x1 + (x2 - x1) * i / steps, "y": y1 + (y2 - y1) * i / steps}]})
        self.call("Input.dispatchTouchEvent", {"type": "touchEnd", "touchPoints": []})
        self.drain(0.3)
    def screenshot(self, path):
        open(path, "wb").write(base64.b64decode(self.call("Page.captureScreenshot", {"format": "png"})["data"]))
    def problems(self, ignore=()):
        """Console errors, uncaught exceptions and browser-reported issues (CSP violations, mixed content...) since the last goto."""
        out = []
        for e in self.events:
            m, p = e["method"], e.get("params", {})
            if m == "Runtime.exceptionThrown": out.append("uncaught exception: " + json.dumps(p["exceptionDetails"].get("exception", {}).get("description", p["exceptionDetails"].get("text")))[:200])
            elif m == "Runtime.consoleAPICalled" and p.get("type") in ("error", "assert"): out.append("console.error: " + " ".join(str(a.get("value", a.get("description", ""))) for a in p.get("args", []))[:200])
            elif m == "Log.entryAdded" and p["entry"].get("level") == "error": out.append(f"browser error ({p['entry'].get('source')}): {p['entry'].get('text', '')[:160]} {p['entry'].get('url', '')[:80]}")
        return [x for x in out if not any(i in x for i in ignore)]
    def close(self):
        if self in _live: _live.remove(self)
        if self.proc is not None:
            pg = self.proc.pid
            for sig, patience in ((signal.SIGTERM, 4.0), (signal.SIGKILL, 4.0)):
                try: os.killpg(pg, sig)
                except ProcessLookupError: break
                try: self.proc.wait(timeout=patience)
                except Exception: pass
                end = time.time() + patience   # the helper processes (zygote, network service...) can outlive the main one for a moment and re-create files in the profile
                while time.time() < end:
                    try: os.killpg(pg, 0)
                    except ProcessLookupError: break
                    time.sleep(0.05)
                else: continue
                break
            self.proc = None
        if self.udd: shutil.rmtree(self.udd, ignore_errors=True); self.udd = None
    def __enter__(self): return self
    def __exit__(self, *a): self.close()
