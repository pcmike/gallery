# Contributing

This started as a personal tool, so there's no formal process yet — just a few notes if you're poking around the code or opening a PR.

## Local testing

No build step. Drop `index.php` into a folder with a few test images (and optionally a `.md` file, first line `{gallery}` — see the README) and run PHP's built-in server:

```
php -S localhost:8000 -t /path/to/test-folder
```

Then visit `http://localhost:8000/`. Useful endpoints while testing:
- `/?about` — short in-app pitch, links to the README
- `/?download` — redirects to the GitHub repo (it used to serve the script itself; see CHANGELOG)
- `/?img=somefile.jpg&size=thumb` — the resize/cache endpoint directly (set `ENABLE_THUMBNAIL_CACHE` to `true` to actually exercise caching/resizing)
- `/?track=somefile.jpg` — the view-tracking ping, returns JSON (set `TRACK_VIEWS` to `true` to see it actually track)
- `/?folder=Europe/France` — a folder's own page (set `AUTO_GROUP_BY_FOLDER` to `true`; works at any depth)
- `/?map` — the gallery page with the map overlay open (needs `ENABLE_MAP` and `ENABLE_PHOTO_LOCATION`, plus photos with a visible location); `/?map&folder=Europe` does the same for a folder
- `/?admin` and `/?reveal` — a password form (set `ADMIN_PASSWORD` / `PRIVATE_VIEW_PASSWORD` in your test copy; both are allowed over plain HTTP on `localhost`)

Remember most of these are off by default — if you're testing view stats or thumbnail caching specifically, flip the relevant constant near the top of your test copy of `index.php` first.

Always run `php -l index.php` before committing — it catches syntax errors instantly.

## Automated tests

`tests/run.php` is a black-box test suite: it builds throwaway fixture folders (synthetic JPEGs + a `.md` file), runs PHP's built-in server against them, and exercises the gallery through real HTTP requests — the same way this project has always been tested by hand. It's a single script, not a framework, and it isn't part of what gets deployed (`tests/` never ships alongside `index.php`).

```
php tests/run.php
```

Requires the GD extension (to synthesize fixture images) — that's it, no other dependencies. It cleans up its own fixture folders and server processes on exit, including on failure. If you interrupt it mid-run (Ctrl-C), check for and kill any stray `php -S` process it may have left running. A normal run leaves none behind — it starts each server directly (no shell wrapper) so the shutdown handler really terminates it; an earlier version orphaned about ten per run, which adds up on a small machine. On a constrained host it's worth running it as `(ulimit -v 4000000; timeout 300 php tests/run.php)` and confirming afterward that no `php -S` is still running.

It covers, through real HTTP requests against many fixtures (unmodified defaults, opted-in settings, folders, video, maps, admin, private items, downloads, the health check and more): the `{gallery}` marker rule, the directive behaviour matrix in the README, layout and ordering, filename and folder grouping, thumbnails and their cleanup, view stats, path validation, the guarded data files, sign-in and throttling, private items and what a visitor can reach, location gating and scrubbing, the streamed ZIP, photo order, the server-rule and health checks, and every recipe in the README followed as a person would. Each past bug has a case so it can't silently regress.

The black-box suite does **not** cover things that need a real browser (lightbox JS, swipe gestures, live-updating badges, the map overlay): those are exercised by `tests/tools/` (real Chromium, QuickJS, XSS and fuzz runs; see its README), which are run before a release. It also does not cover `?admin`/`?reveal`'s HTTPS requirement when the request is genuinely non-local (the black-box test harness only ever connects over loopback, so `REMOTE_ADDR` is always recognized as local — the refusal page is covered by code review, not an automated request), nor the nginx `X-Accel-Redirect` handoff itself (`NGINX_ACCEL_REDIRECT_PREFIX` needs a real nginx `internal` location configured outside this script to actually verify against) — those stay manual testing.

Run both `php -l index.php` and `php tests/run.php` before committing, and add a case to `tests/run.php` for any bug fix so it can't silently regress.

## PHP versions

The minimum is **PHP 8.3**, and it's a tested claim: the whole suite and every tool in `tests/tools/` pass on 8.3 and on 8.4 (the version this is developed on). Before raising or lowering the minimum, run them on the new floor. A throwaway second PHP needs no install — grab a static CLI build from <https://dl.static-php.dev/static-php-cli/common/> and run `php83 tests/run.php` and, for the tools, `PHP_SERVER=/path/to/php83 python3 tests/tools/fuzz/fuzz.py` (see [`tests/tools/README.md`](tests/tools/README.md)). Such a build has GD and SQLite but no Imagick, so the HEIC tests skip and thumbnails use the GD path — which is useful coverage of its own.

## Optional tools: fuzzing and JavaScript checks

Five extra checks live in [`tests/tools/`](tests/tools/README.md), outside the main suite because they need a bit more (python3, plus Chromium for the browser one and QuickJS for the JavaScript one): a **fuzzer** that sends thousands of hostile requests at every endpoint with PHP reporting every error, a **cross-site-scripting canary** (`xss.py`) that plants payloads in every input and fails if any comes back unescaped in a dangerous context, a **real-browser run** (headless Chromium: clicks, key presses, layout, the map, a real admin form under its CSP, screenshots), a **JavaScript runner** that executes the page scripts against a fake DOM and a fake Leaflet, and a small **reference QR encoder and decoder** that the browser run uses to check the gallery's own QR code. Both are worth running after touching request handling, the admin, or any inline script — the fuzzer is what found the `?size[]=` fatal error and the admin NUL-byte crash that the main suite hadn't thought to try.

```
python3 tests/tools/fuzz/fuzz.py
python3 tests/tools/fuzz/xss.py
python3 tests/tools/qr/qr_reference.py
python3 tests/tools/browser/browser_checks.py --shots /tmp/gallery-shots
QJS=/path/to/qjs python3 tests/tools/js/run_js_checks.py
```

## Code style / constraints

- **Single file, no dependencies.** The whole point is drop-in simplicity — avoid introducing a required external library or build step.
- **Graceful degradation.** Every optional feature (Imagick/GD, pdo_sqlite, a writable folder) should fail silently into a working — if reduced — experience, never a broken page.
- Keep the Markdown subset intentionally small. This isn't meant to become a full Markdown implementation.
- Bump `GALLERY_VERSION` and add a `CHANGELOG.md` entry for any user-facing change.

## Reporting issues / suggesting features

Open a GitHub issue with what you were trying to do, what happened, and your PHP version + which optional extensions (Imagick/GD/pdo_sqlite) are installed, since a lot of behavior here depends on what's available on the host.
