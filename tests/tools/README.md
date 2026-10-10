# Optional test tools

These sit beside `tests/run.php` (the main suite, which needs only PHP + GD). They are **optional**, need a little more, and are not run by `php tests/run.php`. Both build their own throwaway fixture, use a free port, and clean up after themselves.

| Tool | What it checks | Needs |
|---|---|---|
| [`fuzz/fuzz.py`](fuzz/fuzz.py) | Sends ~2,300 hostile requests (odd paths, arrays, NUL bytes, huge values, wrong methods, ~900 randomized admin POSTs) to every endpoint as an anonymous visitor, a revealed viewer and the admin, with PHP reporting every error. Fails on any warning, notice, fatal, deprecation or 5xx. | `python3`, `php` with gd + imagick |
| [`fuzz/xss.py`](fuzz/xss.py) | A cross-site-scripting canary test. Plants hostile text in every input an owner or visitor controls (admin fields, a notes `.md`, file and folder *names*, query strings, headers, cookies), crawls every page as an anonymous visitor, a revealed viewer and the admin, and fails if any payload comes back *raw* in a context where it could run (context-aware: text inside a `<script>` is inert unless it can close the script). It also checks the payloads really did reach the pages escaped, so a clean result can't be vacuous. | `python3`, `php` with gd + imagick |
| [`js/run_js_checks.py`](js/run_js_checks.py) | Syntax-checks every inline `<script>`, then runs the real lightbox, caption-popover, view-tracking, map and admin scripts under QuickJS against a small fake DOM and a fake Leaflet, asserting on what they do. It is a safety net for logic errors and typos, **not** a browser: real Leaflet, rendering and CSS are not exercised. | `python3`, `php` with gd + imagick, [QuickJS](https://bellard.org/quickjs/) (`qjs`) |

```
python3 tests/tools/fuzz/fuzz.py
python3 tests/tools/fuzz/xss.py
QJS=/path/to/qjs python3 tests/tools/js/run_js_checks.py     # QJS defaults to `qjs` on PATH
```

Both exit 0 only when nothing is found.

**Getting QuickJS without root** (Debian/Ubuntu): `apt-get download quickjs && dpkg -x quickjs_*.deb ~/quickjs`, then `QJS=~/quickjs/usr/bin/qjs`.

The shared fixture is [`fuzz/build_fixture.php`](fuzz/build_fixture.php): every feature switched on, folder and file names containing `& < > ' % ;` spaces and non-ASCII, a hidden folder, captions with HTML in them, GPS photos, a video and a HEIC. Run it by hand to get a gallery to poke at:

```
php tests/tools/fuzz/build_fixture.php /tmp/kitchen-sink && php -S localhost:8000 -t /tmp/kitchen-sink
# ?admin password: adm-pw    ?reveal password: viewer-pw
```
(The fuzzer *mutates* its fixture with random admin writes, so it always builds a fresh one — don't point it at yours.)
