#!/usr/bin/env python3
"""Real video, in a real browser.

Makes genuine H.264 clips with ffmpeg — with the metadata (the "moov" box) at the END of the
file, as phones write it, at the START ("faststart"), and as a QuickTime .mov — then, in
headless Chromium, checks for each: the duration badge on the gallery page (read straight out
of the file's container), that the lightbox decodes it, that SEEKING works (a real player
issuing HTTP Range requests against the gallery's ?video= endpoint) and that it actually plays.

    FFMPEG=/path/to/ffmpeg python3 tests/tools/browser/video_checks.py      # FFMPEG defaults to `ffmpeg` on PATH
    CHROMIUM=/path/to/chromium ...                                          # if `chromium` isn't on PATH

Needs python3, php (gd), Chromium and ffmpeg (a static build from johnvansickle.com works, no
install needed). Exit status 0 only if every check passes; 2 if ffmpeg or Chromium is missing.
"""
import subprocess, tempfile, os, socket, time, sys, shutil, struct
HERE = os.path.dirname(os.path.abspath(__file__)); sys.path.insert(0, HERE)
from cdp import Browser
FF = os.environ.get("FFMPEG") or shutil.which("ffmpeg")
if not FF or not os.path.exists(FF):
    print("ffmpeg not found. Set FFMPEG=/path/to/ffmpeg (a static build from johnvansickle.com works)."); sys.exit(2)
d = tempfile.mkdtemp(prefix="gallery-video-"); fails = 0; srv = None; b = None
def ok(cond, msg, detail=""):
    global fails; print(("  ok    " if cond else "  FAIL  ") + msg + ("" if cond else f"  [{detail}]")); fails += (not cond)
def make(name, *extra):
    subprocess.run([FF, "-v", "error", "-y", "-f", "lavfi", "-i", "testsrc=size=320x180:rate=25:duration=6.5", "-f", "lavfi", "-i", "sine=frequency=440:duration=6.5",
                    "-c:v", "libx264", "-pix_fmt", "yuv420p", "-c:a", "aac", "-shortest", *extra, f"{d}/{name}"], check=True)
def boxes(path):
    out, data, i = [], open(path, "rb").read(), 0
    while i + 8 <= len(data):
        sz, typ = struct.unpack(">I4s", data[i:i + 8]); h = 8
        if sz == 1: sz = struct.unpack(">Q", data[i + 8:i + 16])[0]; h = 16
        out.append(typ.decode("latin1"))
        if sz == 0 or sz < h: break
        i += sz
    return out
try:
    make("moov_at_end.mp4"); make("faststart.mp4", "-movflags", "+faststart"); make("quicktime.mov", "-f", "mov")
    for n in ("moov_at_end.mp4", "faststart.mp4", "quicktime.mov"): print(f"  {n:18s} boxes {boxes(f'{d}/{n}')}")
    ok(boxes(f"{d}/moov_at_end.mp4").index("moov") > boxes(f"{d}/moov_at_end.mp4").index("mdat"), "(setup) one clip really has its metadata AFTER the video data, like a phone's")
    import re
    src = re.sub(r"define\('ENABLE_VIDEO',\s*gallery_setting\([^)]*\)\)", "define('ENABLE_VIDEO', true)", open(HERE + "/../../../index.php").read(), count=1)
    open(f"{d}/index.php", "w").write(src)
    subprocess.run(["php", "-r", '$i = imagecreatetruecolor(30, 30); imagejpeg($i, $argv[1]);', f"{d}/photo.jpg"], check=True)
    s = socket.socket(); s.bind(("127.0.0.1", 0)); p = s.getsockname()[1]; s.close(); B = f"http://127.0.0.1:{p}"
    srv = subprocess.Popen([os.environ.get("PHP_SERVER", "php"), "-S", f"127.0.0.1:{p}", "-t", d], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL); time.sleep(1.1)
    b = Browser(); b.viewport(1280, 900); b.goto(B + "/", 2.0)
    badges = dict(b.js("[...document.querySelectorAll('.card')].map(c => [(c.querySelector('img') || {}).alt, (c.querySelector('.video-duration') || {}).textContent])"))
    files = b.js("files")
    for name in ("moov_at_end.mp4", "faststart.mp4", "quicktime.mov"):
        ok(badges.get(name) in ("0:06", "0:07"), f"{name}: the duration badge comes from the real file's metadata (6.5s)", str(badges.get(name)))
        b.js(f"openLightbox({files.index(name)})"); b.drain(1.5)
        st = b.js("""(() => { const v = document.getElementById('lightbox-video'); return {shown: getComputedStyle(v).display !== 'none', ready: v.readyState, dur: v.duration, err: v.error ? v.error.code + ':' + v.error.message : null}; })()""")
        ok(st["shown"] and st["err"] is None and st["ready"] >= 1 and abs((st["dur"] or 0) - 6.5) < 0.3, f"{name}: the lightbox loads and decodes it (duration {st['dur']})", str(st))
        r = b.js("""new Promise(res => { const v = document.getElementById('lightbox-video'); v.muted = true; v.onseeked = () => res({seeked: true, t: v.currentTime, err: v.error ? v.error.code : null}); v.currentTime = 4.0; setTimeout(() => res({seeked: false, t: v.currentTime}), 6000); })""")
        ok(r["seeked"] and abs(r["t"] - 4.0) < 0.5 and r.get("err") is None, f"{name}: seeking to 4s works (Range requests, in a real player)", str(r))
        pl = b.js("""new Promise(res => { const v = document.getElementById('lightbox-video'); v.muted = true; const a = v.currentTime; v.play().then(() => setTimeout(() => res({played: v.currentTime > a + 0.3}), 1500)).catch(e => res({played: false, e: String(e)})); })""")
        ok(pl.get("played"), f"{name}: it actually plays (the playback position advances)", str(pl))
        b.js("closeLightbox()")
except Exception as e:
    fails += 1; print("  FAIL  the run crashed:", type(e).__name__, e)
finally:
    if b: b.close()
    if srv: srv.terminate(); srv.wait()
    shutil.rmtree(d, ignore_errors=True)
print("\nALL VIDEO CHECKS PASSED" if not fails else f"\n{fails} VIDEO CHECK(S) FAILED"); sys.exit(1 if fails else 0)
