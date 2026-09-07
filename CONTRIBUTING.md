# Contributing

This started as a personal tool, so there's no formal process yet — just a few notes if you're poking around the code or opening a PR.

## Local testing

No build step. Drop `index.php` into a folder with a few test images (and optionally a `.md` file, first line `{gallery}` — see the README) and run PHP's built-in server:

```
php -S localhost:8000 -t /path/to/test-folder
```

Then visit `http://localhost:8000/`. Useful endpoints while testing:
- `/?about` — short in-app pitch/feature overview, links to the README for full docs
- `/?download` — downloads the script itself
- `/?img=somefile.jpg&size=thumb` — the resize/cache endpoint directly (set `ENABLE_THUMBNAIL_CACHE` to `true` to actually exercise caching/resizing)
- `/?track=somefile.jpg` — the view-tracking ping, returns JSON (set `TRACK_VIEWS` to `true` to see it actually track)

Remember both of those are off by default — if you're testing view stats or thumbnail caching specifically, flip the relevant constant near the top of your test copy of `index.php` first.

Always run `php -l index.php` before committing — it catches syntax errors instantly.

## Automated tests

`tests/run.php` is a black-box test suite: it builds throwaway fixture folders (synthetic JPEGs + a `.md` file), runs PHP's built-in server against them, and exercises the gallery through real HTTP requests — the same way this project has always been tested by hand. It's a single script, not a framework, and it isn't part of what gets deployed (`tests/` never ships alongside `index.php`).

```
php tests/run.php
```

Requires the GD extension (to synthesize fixture images) — that's it, no other dependencies. It cleans up its own fixture folders and server processes on exit, including on failure. If you interrupt it mid-run (Ctrl-C), check for and kill any stray `php -S` process it may have left running.

It runs against three fixtures: **defaults** (an unmodified `index.php` copy, so `TRACK_VIEWS`/`ENABLE_THUMBNAIL_CACHE`/`AUTO_GROUP_BY_FILENAME` are all genuinely off), **optedin** (those three explicitly turned on), and **mtimesort** (`PHOTO_SORT_ORDER` set to `mtime`). It covers:
- the `{gallery}` marker requirement (a `.md` file missing it is ignored and noted; one with it is parsed)
- explicit `{group:}`/`{photos:}` directives across the full behavior matrix documented in the README — boxed vs. unboxed, with/without text, permalinks, positional clustering of unboxed files, `{group:}`-alone renaming an auto-detected group, and duplicate-claim rejection (including that a losing duplicate claim doesn't also render, and that a *textless* explicit group's `{sold}`/`{color:}` still apply — both were real regressions caught while building this)
- filename auto-grouping (including the `iphone15.jpg` vs `iphone-15.jpg` suffix-stripping distinction) when `AUTO_GROUP_BY_FILENAME` is on, and that it's off by default
- Markdown directives (`{color:}`, `{sold}`, `{reserved}`, `{sold}`+`{reserved}` precedence) and auto-linked thumbnails, including group keys with leading punctuation (e.g. `+model`)
- the OG description's code-fence handling
- `?about`, `?download`, `?img` (including path-traversal rejection, and that caching is off by default — full-size originals served, no `.gallery-cache` created), and `?track` (bot exclusion, path-traversal rejection, and that it's off by default)
- thumbnail cache creation and garbage collection when caching is on
- `PHOTO_SORT_ORDER=mtime` actually reordering by file modification time

It does **not** cover things that need a real browser (lightbox JS, swipe gestures, live-updating badges) — that's still manual testing.

Run both `php -l index.php` and `php tests/run.php` before committing, and add a case to `tests/run.php` for any bug fix so it can't silently regress.

## Code style / constraints

- **Single file, no dependencies.** The whole point is drop-in simplicity — avoid introducing a required external library or build step.
- **Graceful degradation.** Every optional feature (Imagick/GD, pdo_sqlite, a writable folder) should fail silently into a working — if reduced — experience, never a broken page.
- Keep the Markdown subset intentionally small. This isn't meant to become a full Markdown implementation.
- Bump `GALLERY_VERSION` and add a `CHANGELOG.md` entry for any user-facing change.

## Reporting issues / suggesting features

Open a GitHub issue with what you were trying to do, what happened, and your PHP version + which optional extensions (Imagick/GD/pdo_sqlite) are installed, since a lot of behavior here depends on what's available on the host.
