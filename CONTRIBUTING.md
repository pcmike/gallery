# Contributing

This started as a personal tool, so there's no formal process yet — just a few notes if you're poking around the code or opening a PR.

## Local testing

No build step. Drop `index.php` into a folder with a few test images (and optionally a `.md` file, first line `{gallery}` — see the README) and run PHP's built-in server:

```
php -S localhost:8000 -t /path/to/test-folder
```

Then visit `http://localhost:8000/`. Useful endpoints while testing:
- `/?about` — short in-app pitch/feature overview, links to the README for full docs
- `/?download` — redirects to the GitHub repo (it used to serve the script itself; see CHANGELOG)
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

It runs against eight fixtures: **defaults** (an unmodified `index.php` copy, so `TRACK_VIEWS`/`ENABLE_THUMBNAIL_CACHE`/`AUTO_GROUP_BY_FILENAME` are all genuinely off), **optedin** (those three explicitly turned on), **hiddencounts** (`TRACK_VIEWS` on, `SHOW_VIEW_COUNTS_PUBLICLY` off), **nested** (`AUTO_GROUP_BY_FOLDER` on alongside `AUTO_GROUP_BY_FILENAME`/`ENABLE_THUMBNAIL_CACHE`/`TRACK_VIEWS`, plus an `EXTRA_FOLDERS` virtual mount and a symlink planted specifically to confirm it's refused), **video** (`ENABLE_VIDEO` on, with a real synthetic `.mp4` and a deliberately-broken `.mov`), **admin** (`ADMIN_PASSWORD` set, `AUTO_GROUP_BY_FOLDER`/`ENABLE_PHOTO_LOCATION` on), **mtimesort** (`PHOTO_SORT_ORDER` set to `mtime`), and **nonotesbox** (every `.md` rejected, so there's no notes box at all — exercises the notices banner's other placement). It covers:
- the `{gallery}` marker requirement (a `.md` file missing it is ignored and noted; one with it is parsed)
- explicit `{group:}`/`{photos:}` directives across the full behavior matrix documented in the README — boxed vs. unboxed, with/without text, permalinks, positional clustering of unboxed files, `{group:}`-alone renaming an auto-detected group, and duplicate-claim rejection (including that a losing duplicate claim doesn't also render, and that a *textless* explicit group's `{sold}`/`{color:}` still apply — both were real regressions caught while building this)
- the two-zone layout (every box renders before every single/cluster, even a textless box defined at the very end of the `.md`) and the prose-vs-natural-order rule within each zone, including that a textless multi-file group/cluster anchors on its first-*listed* file (not its lowest-sorting one) with the rest following in the exact order listed
- mention-matching searches prose only — a filename referenced inside a *different* paragraph's `{photos:}` list must not falsely count as a mention of its own name (a real regression caught while building the two-zone ordering: short keys made it obvious, but the same false-positive was possible with realistic names too)
- a mentioned standalone single gets a working "View 1 photo" jump-link target, not just a boxed group (a pre-existing gap, unrelated to this batch's other changes, found and fixed alongside it)
- a directive-only paragraph right after a plain, unclaimed description is flagged as a likely-accidental disconnect (but not when the preceding paragraph already has its own directive, or when a header/list/blockquote sits in between) — and that every setup notice is surfaced in an on-page banner, not just the HTML-comment fallback, in both of its placements (inside the notes box when one exists, right below the header when it doesn't)
- filename auto-grouping (including the `iphone15.jpg` vs `iphone-15.jpg` suffix-stripping distinction) when `AUTO_GROUP_BY_FILENAME` is on, and that it's off by default
- Markdown directives (`{color:}`, `{sold}`, `{reserved}`, `{sold}`+`{reserved}` precedence) and auto-linked thumbnails, including group keys with leading punctuation (e.g. `+model`)
- the OG description's code-fence handling
- `?about`, `?download` (redirects to GitHub rather than serving the file), `?img` (including path-traversal rejection, and that caching is off by default — full-size originals served, no `.gallery-cache` created), and `?track` (bot exclusion, path-traversal rejection, that it's off by default, the per-photo view-count debounce cookie, and that `SHOW_VIEW_COUNTS_PUBLICLY=false` keeps tracking working while hiding the public badges)
- thumbnail cache creation and garbage collection when caching is on
- `PHOTO_SORT_ORDER=mtime` actually reordering by file modification time
- `AUTO_GROUP_BY_FOLDER`: recursive subfolder scanning with symlink refusal and `EXTRA_FOLDERS` virtual mounts, cover cards for `FOLDER_DISPLAY_MODE='overview'` (including the recursive photo-count badge), folder membership beating filename auto-grouping, per-folder colocation of stats/thumbnail-cache files (never centralized at the root), and the `?folder=` drill-down itself — scoped file list, its own page title/`<h1>`/photo-count/Open Graph tags (not the root's), a breadcrumb back-link, no cover cards of its own, and a 404 for an invalid/unknown/non-folder value
- the "Last updated" date shown in the header on every page (root and `?folder=` alike), derived from the newest file mtime across photos and `.md` files — not a separately-maintained marker
- `ENABLE_VIDEO`: `.mov`/`.mp4` recognition (and that it's invisible and unservable when off, even for a file that genuinely exists on disk), the generic placeholder graphic served through the normal `?img=` thumbnail URL, the duration badge read from the container's `mvhd` box (and graceful degradation to no badge at all when a file doesn't parse as one), a folder's cover card preferring an actual photo over a video for its highlight image, and the `?video=` Range-request endpoint itself — a full request, a ranged request, a suffix range, a request entirely past end-of-file (416), and a malformed `Range` header (ignored, falls back to a full response)
- a subfolder's own optional `{gallery}`-marked `.md` (see `AUTO_GROUP_BY_FOLDER`): renders as its own note box on that folder's `?folder=` page, scoped so it can only ever mention/claim that folder's own files, with its mention/`{color:}`/`{sold:}`/`{reserved:}` anchors correctly lined up with the actual rendered card (not a sub-clustering `{group:}`/`{photos:}` directive, which isn't supported inside a subfolder's own notes yet); an unmarked `.md` there is rejected the same generic, filename-never-revealed way the root's is
- `?admin` (inert/404 when `ADMIN_PASSWORD` is unset): HTTP Basic Auth success/failure, the CSRF token (a save without a valid one is rejected and has no effect), the failed-login throttle (5 failures locks out the 6th attempt — even with the correct password — until the lockout window passes), per-item caption/web-link/location saves (including input validation and all three location-visibility gates), a `{group:}`-controlled group's display name being rejected and shown disabled, a folder's own display name and chosen cover photo, the `{gallery}`-marker one-click fix, raw notes save/save-as/delete (byte-for-byte, extension enforced), the JSON export, and stale `.gallery-items.json` entry cleanup (reported in the notices, and that a surviving file's entry is left alone)

It does **not** cover things that need a real browser (lightbox JS, swipe gestures, live-updating badges), nor `?admin`'s HTTPS requirement when the request is genuinely non-local (the black-box test harness only ever connects over loopback, so `REMOTE_ADDR` is always recognized as local — the refusal page is covered by code review, not an automated request) — those stay manual testing.

Run both `php -l index.php` and `php tests/run.php` before committing, and add a case to `tests/run.php` for any bug fix so it can't silently regress.

## Code style / constraints

- **Single file, no dependencies.** The whole point is drop-in simplicity — avoid introducing a required external library or build step.
- **Graceful degradation.** Every optional feature (Imagick/GD, pdo_sqlite, a writable folder) should fail silently into a working — if reduced — experience, never a broken page.
- Keep the Markdown subset intentionally small. This isn't meant to become a full Markdown implementation.
- Bump `GALLERY_VERSION` and add a `CHANGELOG.md` entry for any user-facing change.

## Reporting issues / suggesting features

Open a GitHub issue with what you were trying to do, what happened, and your PHP version + which optional extensions (Imagick/GD/pdo_sqlite) are installed, since a lot of behavior here depends on what's available on the host.
