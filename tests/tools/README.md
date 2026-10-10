# Optional test tools

These sit beside `tests/run.php` (the main suite, which needs only PHP + GD). They are **optional**, need a little more, and are not run by `php tests/run.php`. Both build their own throwaway fixture, use a free port, and clean up after themselves.

| Tool | What it checks | Needs |
|---|---|---|
| [`fuzz/fuzz.py`](fuzz/fuzz.py) | Sends ~2,300 hostile requests (odd paths, arrays, NUL bytes, huge values, wrong methods, ~900 randomized admin POSTs) to every endpoint as an anonymous visitor, a revealed viewer and the admin, with PHP reporting every error. Fails on any warning, notice, fatal, deprecation or 5xx. | `python3`, `php` with gd + imagick |
| [`fuzz/xss.py`](fuzz/xss.py) | A cross-site-scripting canary test. Plants hostile text in every input an owner or visitor controls (admin fields, a notes `.md`, file and folder *names*, query strings, headers, cookies), crawls every page as an anonymous visitor, a revealed viewer and the admin, and fails if any payload comes back *raw* in a context where it could run (context-aware: text inside a `<script>` is inert unless it can close the script). It also checks the payloads really did reach the pages escaped, so a clean result can't be vacuous. | `python3`, `php` with gd + imagick |
| [`browser/browser_checks.py`](browser/browser_checks.py) | Drives the gallery in a **real headless Chromium** (via a tiny stdlib DevTools client, [`browser/cdp.py`](browser/cdp.py) — no Playwright/Puppeteer needed): real clicks and key presses on the lightbox (incl. a video) and caption popover, folder navigation and breadcrumbs, the map with real Leaflet (pins, popup), phone-width pages for horizontal overflow, the admin's collapsible panel, **a real form submission under the page's Content-Security-Policy**, that the admin refuses to be framed, and the contrast of the admin's warning link. Add `--shots DIR` to save screenshots. The map check needs internet access for Leaflet (skipped if offline). | `python3`, `php` with gd + imagick, Chromium (`chromium` on PATH or `CHROMIUM=…`) |
| [`js/run_js_checks.py`](js/run_js_checks.py) | Syntax-checks every inline `<script>`, then runs the real lightbox, caption-popover, view-tracking, map and admin scripts under QuickJS against a small fake DOM and a fake Leaflet, asserting on what they do. It is a safety net for logic errors and typos, **not** a browser (the real-browser tool above covers rendering, CSS and real Leaflet). | `python3`, `php` with gd + imagick, [QuickJS](https://bellard.org/quickjs/) (`qjs`) |

```
python3 tests/tools/fuzz/fuzz.py
python3 tests/tools/fuzz/xss.py
python3 tests/tools/browser/browser_checks.py [--shots DIR]
QJS=/path/to/qjs python3 tests/tools/js/run_js_checks.py     # QJS defaults to `qjs` on PATH
```

All of them exit 0 only when nothing is found. The gallery itself runs under `PHP_SERVER` (default `php`), so you can check another PHP version, e.g. `PHP_SERVER=/path/to/php8.3 python3 tests/tools/fuzz/fuzz.py` (the fixture is still built with the `php` on your PATH, which needs gd + imagick; a static CLI build from <https://dl.static-php.dev/> makes a handy second version). `php tests/run.php` already uses whatever PHP you run it with.

**Getting QuickJS without root** (Debian/Ubuntu): `apt-get download quickjs && dpkg -x quickjs_*.deb ~/quickjs`, then `QJS=~/quickjs/usr/bin/qjs`.

**Web-server rules** (the nginx and Apache snippets in the README) are not automated — they were checked by hand against the real servers, unprivileged and with no install: `apt-get download nginx php8.4-fpm apache2-bin apache2-data apache2 libapr1t64 libaprutil1t64` (or `quickjs` for QuickJS), `dpkg -x` each into a scratch directory, and run them on high ports with `LD_LIBRARY_PATH` pointing at the unpacked libraries (Apache via `mod_proxy_fcgi` to the unpacked php-fpm, with `PHP_INI_SCAN_DIR=/etc/php/8.4/cli/conf.d`). Re-run that after changing a rule in the README.

The shared fixture is [`fuzz/build_fixture.php`](fuzz/build_fixture.php): every feature switched on, folder and file names containing `& < > ' % ;` spaces and non-ASCII, a hidden folder, captions with HTML in them, GPS photos, a video and a HEIC. Run it by hand to get a gallery to poke at:

```
php tests/tools/fuzz/build_fixture.php /tmp/kitchen-sink && php -S localhost:8000 -t /tmp/kitchen-sink
# ?admin password: adm-pw    ?reveal password: viewer-pw
```
(The fuzzer *mutates* its fixture with random admin writes, so it always builds a fresh one — don't point it at yours.)
