"""A tiny Chrome DevTools Protocol client using only the standard library
(a hand-rolled WebSocket), so the browser checks need nothing but Chromium."""
import socket, base64, os, struct, json, time, subprocess, tempfile, shutil, urllib.request, urllib.parse

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

class Browser:
    def __init__(self, chromium=None):
        self.chromium = chromium or os.environ.get("CHROMIUM") or shutil.which("chromium") or shutil.which("chromium-browser") or shutil.which("google-chrome")
        if not self.chromium: raise SystemExit("Chromium not found. Install it (e.g. `sudo apt install chromium`) or set CHROMIUM=/path/to/chromium.")
        self.udd = tempfile.mkdtemp(prefix="gallery-chromium-")
        s = socket.socket(); s.bind(("127.0.0.1", 0)); self.port = s.getsockname()[1]; s.close()
        self.proc = subprocess.Popen([self.chromium, "--headless=new", "--no-sandbox", "--disable-gpu", "--disable-dev-shm-usage", "--no-first-run",
            "--hide-scrollbars", f"--user-data-dir={self.udd}", f"--remote-debugging-port={self.port}", "about:blank"], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        for _ in range(100):
            try:
                tabs = json.load(urllib.request.urlopen(f"http://127.0.0.1:{self.port}/json", timeout=2))
                page = next(t for t in tabs if t["type"] == "page"); break
            except Exception: time.sleep(0.2)
        else: raise SystemExit("Chromium did not start")
        self.ws = WS(page["webSocketDebuggerUrl"]); self.id = 0; self.events = []
        for m in ("Page.enable", "Runtime.enable", "Log.enable", "Network.enable"): self.call(m)
    def call(self, method, params=None, timeout=30):
        self.id += 1; mid = self.id; self.ws.send(json.dumps({"id": mid, "method": method, "params": params or {}})); end = time.time() + timeout
        while time.time() < end:
            m = json.loads(self.ws.recv())
            if m.get("id") == mid:
                if "error" in m: raise RuntimeError(f"{method}: {m['error']}")
                return m.get("result", {})
            if "method" in m: self.events.append(m)
        raise TimeoutError(method)
    def drain(self, seconds=0.0):
        """Collect events for a moment (without sending anything)."""
        self.ws.s.settimeout(0.2); end = time.time() + seconds
        while time.time() < end:
            try: m = json.loads(self.ws.recv()); self.events.append(m)
            except (socket.timeout, TimeoutError): pass
        self.ws.s.settimeout(30)
    def goto(self, url, wait=1.2):
        self.events = []; self.call("Page.navigate", {"url": url}); end = time.time() + 30
        while time.time() < end:
            if any(e["method"] == "Page.loadEventFired" for e in self.events): break
            self.drain(0.2)
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
        try: self.proc.terminate(); self.proc.wait(timeout=5)
        except Exception: self.proc.kill()
        shutil.rmtree(self.udd, ignore_errors=True)
