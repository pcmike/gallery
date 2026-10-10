# Changelog

All notable changes to this project are documented here. Versions correspond to the `GALLERY_VERSION` constant in `index.php`.

## [2.0.1] - 2026-10-10

### Added
- A setup notice when video files exist but `ENABLE_VIDEO` is off. A trip's `Videos/` folder used to vanish without a word: with video off the gallery sees nothing in it, so the folder never appeared. The collapsed "setup notices" banner now says how many `.mov`/`.mp4` files it found and ignored, and that `ENABLE_VIDEO` is the switch. It's a count only, never a folder or file name (a folder of nothing but ignored videos isn't one the owner has published, and the banner is visible to every visitor). The search covers the gallery root, its subfolders and `EXTRA_FOLDERS`, and is capped at 40 folders so a gallery dropped into a big existing site never walks all of it. Nothing changes when `ENABLE_VIDEO` is on, and nothing is written to disk either way.

### Changed
- **`?admin` and the Private view now use a password form instead of the browser's Basic-Auth pop-up.** One field, no username, no "which user name?" dialog. Logging in starts a fresh session (the admin stays signed in for the session, up to 4 hours) and the admin has a **Log out** button, which also clears the Private view cookie. A change posted after the session expired is refused with a clear message instead of silently lost. Changing `ADMIN_PASSWORD` signs every session out. The failed-login throttle is unchanged (just opening the form never counts). A script may still send an `Authorization: Basic` header; the form needs no server setting, so Apache with PHP-FPM no longer needs `CGIPassAuth On` for people to log in (only for such scripts). Tested in a real browser and on nginx + PHP-FPM and Apache + PHP-FPM.
- **The gallery's title is now the way into the admin.** It links to `?admin` (on a folder's page, to that folder's admin) but is deliberately unmarked: no underline, no colour, no hover or cursor change. Only present when `ADMIN_PASSWORD` is set. The login page is `noindex`.
- **A notes file with nothing to show no longer draws an empty box.** A `.md` holding only a `# Title` (which becomes the page title) or only directives used to leave a box captioned with its filename; it now renders nothing. The file still takes effect.
- **Turning on a folder's locations now actually publishes them.** It used to store nothing: each photo's GPS was only pre-filled in the editor and a location appeared only after you clicked Save on that photo, so an owner who ticked the switch, saw coordinates in the admin and expected a map got nothing, with no hint why. Now ticking it asks for confirmation (with the number of photos affected), reads the GPS of every photo in the folder that has some, stores it, and says how many now show a location. Opting a photo out is the per-photo setting that was already there (*Always hide*) or clearing its coordinates; a photo you saved yourself is never overwritten by GPS from its file, and a cleared location stays cleared. Photos added later are picked up the next time you open that folder in the admin, never on a visitor's request. While a folder's switch is off, the admin says which photos have GPS in the file, that it stays private, and what turning the switch on will do. Turning the switch off hides everything again and keeps the stored coordinates.
- **The trip map moved above the folders and now lines up with them.** It sat below the photo grid and ran edge to edge across the window, unlike everything around it. It now shares the grid's column and side padding (same left and right edges as the folder tiles, at every window width), and is 300 px tall.
- With nothing to show at all (for example a gallery holding only a `Videos/` folder while `ENABLE_VIDEO` is off), the setup-notices banner now starts **open** instead of collapsed, so the explanation sits right above "No photos found". It stays collapsed whenever there are photos to look at.

## [2.0.0] - 2026-10-10

A large release, built and tested in phases on one branch and shipped as one version. **Everything new is off by default, behind its own constant** — a deployment that just swaps in the new file behaves like 1.1.1, apart from the one breaking change below and the two upgrade notes under it.

### Breaking changes
- **PHP 8.3 or newer is now required** (1.x stated 7.4). PHP 7.4 has been unsupported since 2022 and was never tested against this release; 8.1 and 8.2 are out of (or about to leave) security support. The minimum is 8.3, the oldest version still receiving security fixes (until the end of 2027), and it is a tested claim: the full test suite, the fuzzer, the cross-site-scripting check and the real-browser run all pass on PHP 8.3.32 as well as 8.4.26. On an older PHP the page stops with a one-line message saying so.
- **`?download` no longer serves the script.** It now redirects (302) to the GitHub repository. It used to `readfile()` the running `index.php` to anyone who asked; once `ADMIN_PASSWORD` lives in that same file, that would have handed the password to any visitor, and scrubbing secrets out of the output is a fix that has to be remembered forever. Removing the endpoint closes the whole class at once. An old bookmarked link lands on the repository rather than a dead end. `?about`'s download button now points at GitHub too.

### Upgrading from 1.x
- **Your view counts carry over automatically.** 1.x kept them in `.gallery-stats.json` (or a SQLite file with readable filenames). On the first request with `TRACK_VIEWS` on, 2.0 imports the per-photo counts and the sitewide total into its new stores, then removes the old JSON file (or drops the old SQLite table and compacts the file, so the old filenames don't linger in it).
- **You may see one new setup notice** if your folder has subfolders containing photos while `AUTO_GROUP_BY_FOLDER` is off: the collapsed "setup notices" banner says how many photos in subfolders aren't shown (a count, never a name).

### Added — folders
- **Nested folders, to any depth** (`AUTO_GROUP_BY_FOLDER`, off by default). A root like `/trip` with `Europe/France/Paris` underneath shows each subfolder as its own group. With `FOLDER_DISPLAY_MODE = 'overview'` (the default) each level shows one **cover card per subfolder** — a highlight photo, a label and a *recursive* photo count — plus any photos loose at that level, and clicking a card opens `?folder=Europe/France` and repeats. `'inline'` instead shows every folder's photos in one box, for people who use folders only to stay organized. A breadcrumb links through every ancestor.
- `?folder=` is validated against the script's own file list, never trusted: `../`, leading/trailing slashes, a file path, an empty value, a nonexistent folder, and a hidden folder all return 404. Symlinked subfolders are refused outright.
- `EXTRA_FOLDERS`: an explicit, admin-written map of virtual folder names to real paths (for archiving an old trip on another disk) — declared in code you control, so no symlink is involved.
- Per-folder **display name, description, a typed date-range subtitle, a chosen cover photo, and a position among sibling folders**, all editable in `?admin`. Stored inside the folder they describe, so they survive a real rename. The date range is deliberately never derived from EXIF: that would publish when you were away from home.
- A folder may carry its own `{gallery}`-marked `.md`, becoming a note box on that folder's page, scoped to that folder's photos only.
- A `.md` mention of a folder links to its `?folder=` page in overview mode.
- Stats, the thumbnail cache and per-item data are **colocated in each folder**, so two cameras' `IMG_0001.jpg` in different folders never collide, and a real folder rename carries its data along.
- A **"Last updated"** date in the header, derived from the newest file modification time across photos and notes — not a separately maintained marker, so it also reflects files uploaded by FTP.
- A setup notice when photos exist in subfolders but `AUTO_GROUP_BY_FOLDER` is off, so a first-time owner isn't left with a silently sparse gallery (a count only; the search is capped at 40 folders).
- Sibling folders order by an admin-set position, then naturally by name (Day 2 before Day 10), or by folder mtime under `PHOTO_SORT_ORDER = 'mtime'`.
- `?img=` and `?video=` validate the one claimed path directly instead of scanning the whole tree first — one tree walk per thumbnail is what made large, deep galleries slow (measured on 3,000 photos in 200 folders with PHP's built-in server: about 35 ms per `?img=` request before, about 9 ms after). The check rejects `..`, doubled/leading slashes, backslashes, NULs, folders, the script itself, `.gallery-cache`, symlinked folders, non-media extensions, and any name that isn't an exact-case match for a real file (so a case-insensitive filesystem can't dodge a per-photo hide flag). `?track=` and page loads still scan, since they need the whole group.

### Added — video
- `ENABLE_VIDEO`: `.mov` and `.mp4` are served as gallery items. No transcoding, no frame extraction. Tiles and share previews use one generic placeholder graphic; the lightbox plays the clip in a native `<video>`. Seeking works: real HTTP `Range` support (`206`, `416` past the end, malformed ranges ignored). A **duration badge** is read straight from the container's `mvhd` box.

### Added — captions, location, and the admin editor
- **Per-item captions, a web link, and a location**, one per photo or video. The lightbox shows the caption (or just a position counter) — **it no longer prints the raw filename.** Grid badges (caption, location, link) sit along the bottom edge.
- **`ENABLE_PHOTO_LOCATION`** (off by default) is the first of three gates: also needed are a per-folder switch and a per-photo setting (inherit / always show / always hide), all closed by default. GPS is auto-filled from EXIF for JPEG and HEIC where present; coordinates are validated before they reach any URL.
- **Two admin tabs can't silently overwrite each other:** a save whose record changed since the page loaded is refused with an explanation (per record, so different photos never conflict).
- The caption badge is a tap target that opens a popover on a phone, not just a hover tooltip.
- **`?admin`** (`ADMIN_PASSWORD`, empty = the code path doesn't exist): HTTP Basic Auth compared with `hash_equals()`, refused over plain HTTP except on localhost (with a page explaining why), CSRF-protected saves, a 4-hour session, and a failed-login throttle (5 wrong passwords from one address in 15 minutes imposes a 60-second delay; a request with no credentials — the normal first step of any login — doesn't count). Usable from a phone. It edits every item's caption/link/location, folder settings, every `.md` in the folder (raw text, saved byte-for-byte; **Save**, **Save As** and **Delete** are separate actions, and Save As and Delete ask for confirmation; a one-click fix for a missing `{gallery}` marker), and exports each folder's saved data (`.gallery-items.php`) as plain JSON, for one folder or the whole tree. Stale entries for deleted photos are cleaned up and reported.

### Added — hiding items, and Private view
- A **hide flag** per item and per folder (at any depth) in the admin. A hidden item is removed from the page, counts, lightbox list and every endpoint (`?img=`, `?photo=`, `?track=`, `?folder=`, `?video=`) for an unrevealed visitor — not merely styled invisible. A group whose members are all hidden disappears rather than showing "0 photos". Each item can also be forced *public* inside a hidden folder.
- **`PRIVATE_VIEW_PASSWORD`** and a discreet "Private view" footer link (`?reveal`): a password, deliberately not a secret link, since links pasted into chat get crawled. Same HTTPS requirement and throttle as the admin. A valid admin login counts as revealed too.
- An admin **"Hidden items"** summary across the whole tree in three tiers, and a persistent warning that hiding doesn't stop someone who already knows a file's URL unless the web server is locked down.
- **`NGINX_ACCEL_REDIRECT_PREFIX`** hands matching files to nginx via `X-Accel-Redirect`, so an nginx `internal` rule can make the bare file URLs unreachable. See the README for the nginx and Apache setups.

### Added — trip map
- **`ENABLE_MAP`** (off by default): every visible, located photo on one Leaflet + OpenStreetMap map, at its own shareable **`?map`** URL with a condensed preview on the gallery root. Photos within `MAP_CLUSTER_RADIUS_MILES` (default 50) merge into one numbered pin that zooms in when clicked; pins are colored per top-level folder with a legend; a pin opens a popup (thumbnail, label, link) rather than navigating. Hidden items are never plotted. `?map` has its own Open Graph tags and a generic map-pin preview image. **This is the first feature that makes third-party requests** (Leaflet from cdnjs, tiles from OpenStreetMap), which is why it is opt-in.

### Added — walkthroughs
- **README: "Recipes — start here"** with a decision table and one tested walkthrough per use case (plain gallery, for-sale listing, trip album, family photos with private items, an archive on another disk, iPhone photos), plus a **"What affects what"** pair of tables mapping every setting in `index.php` and every admin control to its effect on visitors. Each recipe was followed step by step against the real script, through the admin's real forms, as part of the test suite.
- The admin now shows, when `TRACK_VIEWS` is on, the sitewide page-load total and "👁 N views" under every photo — so `SHOW_VIEW_COUNTS_PUBLICLY = false` really means "count them, show only me".

### Added — other
- `SHOW_VIEW_COUNTS_PUBLICLY` (default `true`): track views for yourself while hiding the public badges.
- View counts are debounced per browser (30 minutes, per photo), so reloading a photo no longer inflates it. A debounced request still returns the current total.
- The SQLite view-stats store keys its rows by a SHA-256 of each photo's path rather than the filename, because that binary file can be fetched by URL and can't carry the PHP guard the JSON files do. Hidden items are counted like any other; no filename appears in the file.

### Security
- **Apache rules are now tested on a real Apache** (2.4.68 + PHP-FPM) and Apache pretty folder URLs are documented (`RewriteRule … [B,L,QSA]`; the `B` flag is needed for names with spaces or `&+#`). Along the way:
- **Apache: the documented `.htaccess` rule missed upper-case extensions** (`IMG_0412.JPG`, `.Jpeg`) because `FilesMatch` is case-sensitive, so those photos — hidden ones included — stayed reachable by address even with the rule installed. Fixed with `(?i)`. Found by running the rules on a real Apache (2.4.68 + PHP-FPM), which also confirmed that `AllowOverride None` silently disables them, and that the admin and Private view login needs `CGIPassAuth On` on Apache with PHP-FPM/CGI (documented).
- **Hidden photos and videos are never cacheable** (`Cache-Control: private, no-store`), as are pages rendered for a revealed viewer (with `Vary: Cookie`) — previously every photo, hidden or not, was `public, immutable`, so a CDN or proxy could have kept a hidden photo and served it to anyone.
- Admin and private-view responses send `Cache-Control: no-store`, `X-Frame-Options: DENY` and a CSP (`frame-ancestors 'none'; form-action 'self'; base-uri 'none'; object-src 'none'`); every response sends `X-Content-Type-Options: nosniff`.
- The admin session has its own cookie name (it no longer shares `PHPSESSID` with a host site) and rejects session ids the server didn't issue (`session.use_strict_mode`).
- A malformed `Host` header is no longer echoed into absolute URLs.
- Verified by a cross-site-scripting canary test (payloads planted in every admin field, notes, file and folder names, query strings and headers; no raw payload came back on ~290 pages) and by PHPStan static analysis (no real findings). The `README`'s new "Security notes" lists what is and isn't protected.

### Fixed
- **Replacing a photo under the same name now shows the new picture.** Photos and videos are cached by browsers for a year, and their address never changed, so anyone who had seen `rack.jpg` kept seeing the old one after you swapped it (this dates from 1.x). Image and video addresses now carry a tag (`&v=…`) hashed from the file's modification time and size, so a replaced file gets a new address while untouched photos keep theirs; link-preview images pick it up too. Checked in a real browser: without the tag the swapped photo kept showing the old picture; with it the new one appears on a normal page load.
- The link inside the admin's red "Hidden items exist" warning was dark blue on dark red (contrast about 1.8:1, nearly unreadable); it now inherits the box's colour.
- **A pasted `?folder=` link now previews with the cover photo chosen in the admin** (it used the folder's first file). One shared function picks the cover for both the card and the preview.
- **Two galleries on one domain no longer sign each other's viewers out.** The "Private view" cookie used one fixed name for the whole domain; it is now named and signed from each copy's own folder, so every gallery keeps its own (and one gallery's cookie is no longer valid in another that merely shares the password).
- **The sitewide "N views" total no longer shows in the public footer when `SHOW_VIEW_COUNTS_PUBLICLY` is off.**
- **The admin no longer says "Saved" when it couldn't write** (a folder the web server can't write to — very common on shared hosting). It says "Nothing was saved" and what to change.
- `examples/example-forsale.md` did what its text promised only by accident: it claimed the filename prefix `widgeta` matches "Widget Model A", which it doesn't, so none of its items got photos. Rewritten (Alpha/Bravo/Charlie), and the claim corrected.
- Directive examples written inside `` `inline code` `` in a notes file were parsed as real directives (so documenting the syntax raised "unknown file" notices on the public page, including the shipped example's own explanation). Braces inside inline code are now inert and shown literally; real directives in the same paragraph still work.
- `?img=...&size[]=x` (an array where a size is expected) was a fatal error; it now falls back to the default size. This one dates from 1.x.

### Notes
- **The generated data files are guarded PHP files, not plain JSON.** `.gallery-items.php`, `.gallery-stats.php` and `.gallery-login-attempts.php` begin with a one-line `<?php http_response_code(404); exit; ?>`, so asking a web server for one by URL returns 404 and nothing else — captions, GPS and the filenames of hidden items are never sent, on any host that runs PHP, with no server rule. They're read as plain text (never executed), and are written with `<`/`>` escaped so a caption can't inject PHP. The one file that can't carry the guard is the optional binary `.gallery-stats.sqlite`, which is why it holds only hashed keys and counts; the README's nginx/Apache rule blocking `.gallery-*` is worth adding as a second layer over all of these. The nginx rules were tested against nginx 1.26 + php-fpm 8.4; the Apache rules were not.
- The original photo files are served untouched, so **embedded EXIF GPS in the full-size image is not stripped** — the location gates control what the gallery shows, not what a saved original contains. See the README.
- `tests/run.php` now has 463 checks, including recipe walkthroughs that drive the admin through its own forms the way a browser submits them, an upgrade from the *real* 1.1.1 script (not an imitation of its data), and iPhone-style big-endian GPS, including a real 1.x-to-2.0 stats upgrade in both storage flavours. The project was also fuzzed with ~2,300 hostile requests across every endpoint (anonymous, revealed and admin, PHP with all errors on): it found and fixed the fatal above, a fatal on a NUL byte in a notes filename, and PHP warnings from array-valued admin form fields. The inline JavaScript was syntax-checked and its logic executed against a fake DOM and Leaflet, and the whole gallery was driven in a real headless Chromium (lightbox and keys, caption popover, folder navigation, the map with real Leaflet and a clickable pin, phone-width layouts, the admin including a real form submit under its Content-Security-Policy, and that the admin cannot be framed). The suite also no longer leaks processes: each run used to orphan its `php -S` servers (~350 MB for ten), which accumulated over a long session and exhausted a small host — and it no longer follows directory symlinks when cleaning up its fixtures.
- Also verified with real tools rather than assumed: swipe gestures with real touch events (left/right/ignored-if-short/up-doesn't-close/down-closes); real H.264 clips with the metadata at the end of the file (as phones write it), at the start, and as a QuickTime `.mov` — duration badge, decoding, **seeking** and playback in Chromium; 400 simultaneous view pings and 30 simultaneous admin saves on php-fpm, counted exactly on both stats backends; the whole suite, the fuzzer, the cross-site-scripting check and the browser run on PHP 8.3 and 8.4; the nginx and Apache rules on the real servers.

## [1.1.1] - 2026-09-07

### Fixed
- A gallery group box now has a one-click copy-link button, same as a `.md` item — the README always claimed both got one, but only `.md` items actually did. A group box had a valid `#group-<slug>` anchor id, just no visible button or click handler to copy it. `render_item_permalink()` generalized into `render_permalink_button()` (the `.item-permalink` class renamed to `.permalink-copy`, same underlying mechanism reused for both). Also added the same `:target` flash-highlight `.md` items already get, so following a shared group link actually shows you which box you landed on.

## [1.1.0] - 2026-09-07

### Breaking changes
- **A `.md` file is only read if its first line is exactly `{gallery}`.** Any existing `forsale.md` (or similar) needs that line added, or it will silently stop being parsed. This is a hard requirement, not a toggle — added so this script can be dropped into a folder that already has its own unrelated `.md` files (notes, a README) without swallowing them.
- **`TRACK_VIEWS` now defaults to `false`** (was `true`). Set it to `true` to get view-count badges/stats back.
- **`ENABLE_THUMBNAIL_CACHE` (new) defaults to `false`.** Without it, every photo is served at full original resolution as its own "thumbnail," and HEIC/HEIF photos are excluded from the gallery entirely — even with Imagick installed. Set it to `true` for real deployments.
- **`AUTO_GROUP_BY_FILENAME` (new) defaults to `false`** (auto-grouping by a `_01`/`-02` filename suffix was previously always on). Set it to `true` if your photos use that multi-angle naming convention.

Reasoning for all three defaults: this script writes nothing to a folder's disk, and assumes nothing about a folder's filename conventions, unless explicitly told to.

### Added
- Explicit photo grouping in a `.md` file via `{group: Name}` and `{photos: a.jpg, b.jpg, ...}` directives — works regardless of filename, for a folder of photos with no naming convention at all. See the README for the full behavior matrix (boxed vs. unboxed, with/without text, permalinks, positional clustering).
- `{photos:}` used without `{group:}` repositions the listed files adjacent to each other in the gallery, without boxing them or creating an aggregate view count. With prose, they're positioned early (by where that text sits in the `.md`); textless, they fall back to natural order, anchored on the first-listed file.
- `{group:}` used alone (no `{photos:}` in the same paragraph) renames an already auto-detected group's display label.
- `PHOTO_SORT_ORDER` constant: `'filename'` (default, natural sort) or `'mtime'` (file modification time, oldest first).
- A "⚠ N setup notices for the gallery owner" banner, collapsed by default, listing anything that didn't resolve cleanly: a `.md` file missing its `{gallery}` marker (reported as a count only, deliberately never by filename — see Fixed below), an unknown filename referenced in `{photos:}`, a duplicate `{group:}`/`{photos:}` claim, or a directive that isn't attached to the description it was probably meant to go with (see the next bullet). Shown at the top of the notes box if one exists, or right below the page header if no `.md` qualified at all — addressed explicitly to the gallery owner, so it's not alarming to a normal visitor. The same list is also still in an HTML comment near the top of the page source.
- A directive-only paragraph (no prose of its own) immediately following a plain, unclaimed description — separated only by a blank line — is flagged in that notice banner, since it's easy to write by accident and silently changes both what shows in the notes box and where the item sorts. Not flagged when the preceding paragraph already has its own directive, or when a header/list/blockquote sits in between.

### Fixed
- Two unrelated files sharing a base name but differing only in extension (e.g. `topology.jpg` and `topology.png`) could silently merge into a box, regardless of `AUTO_GROUP_BY_FILENAME` — a coincidence, not an intentional grouping signal, since neither file needed any suffix stripped to arrive at that shared name. A real multi-angle group (`dp104_01.jpg`/`dp104_02.jpg` → `dp104`) is unaffected, since stripping is what makes those converge. (An initial fix keyed every file by its full filename including extension, which inadvertently broke classic mention-based auto-linking — a paragraph mentioning "zebra" could no longer match `zebra.jpg`. Fixed properly in the same release.)
- The gallery grid no longer splits into separate fragments around a group box. Previously, standalone single photos were batched into their own `<div class="grid">` blocks that broke every time a box appeared between them, so a short run of singles right before a box rendered as a sparse, incomplete-looking row. The whole gallery is now one continuous grid; a box is simply a grid item spanning the full row width.
- Layout is now two zones: every box (auto-detected or explicit) always renders before every single and unboxed cluster, regardless of where its directive/mention sits in the `.md`. Even the previous fix above couldn't fully prevent a box from landing mid-row and leaving a gap when it interrupted an uneven number of singles — putting every box first eliminates that possibility structurally, since a full-width row always ends cleanly.
- Mention-matching now searches prose only. Previously it scanned raw `.md` content directly, so a filename referenced inside a *different* paragraph's `{photos: a.jpg, b.jpg}` list could falsely count as a mention of its own name if that name happened to appear with valid word boundaries inside the directive text — most obvious with short filenames, but not limited to them.
- A mentioned standalone single's "View 1 photo ↓" note-box link now actually works. It always pointed at `#group-<slug>`, but only a boxed group's container ever carried that id — a plain single card never did, so the link was dead for any mention that didn't turn out to be a real multi-photo group. Every card gets the anchor now.
- The missing-`{gallery}`-marker notice no longer names the rejected `.md` file. Every other notice only ever references a filename the folder owner themselves typed into their own gallery `.md`, already meant to be public — but a `.md` missing the marker is, by definition, one they *didn't* choose to expose. Since a `.md` is served as a plain static file with no access control tied to the marker, naming it handed a visitor the literal URL to go read its contents directly. Now reported as a count only ("N .md files were found but ignored...").

## [1.0.5] - 2026-09-06

### Fixed
- Main gallery footer: the "gallery" link and the "· N views" text now share one consistent color and font-size, instead of the link being dimmed while the count text stayed full-brightness

## [1.0.4] - 2026-09-06

### Changed
- `?about` page: removed the "See it in action" link, which duplicated the top-bar "Back to gallery" link (both pointed to the same place)

## [1.0.3] - 2026-09-06

### Fixed
- `?about` page: removed the download button's own bottom margin, which was stacking with the following section header's top margin and doubling the gap before the first divider line compared to every other section on the page

## [1.0.2] - 2026-09-06

### Fixed
- `?about` page: the divider line before every section header was missing above the first one ("What it does"), leaving an unexplained gap below the download button
- Removed the `?about` page's closing footer line (name/version/attribution) — redundant with the "Full documentation & source" link directly above it

## [1.0.1] - 2026-09-06

### Fixed
- Thumbnail cache (`.gallery-cache`) is now garbage-collected on normal page loads — entries for photos that have been renamed, deleted, or re-edited no longer accumulate indefinitely
- Group-name matching (auto-linked thumbnails, mention-order detection) now correctly handles group keys that start or end with punctuation, e.g. `+model`, instead of relying on `\b` word boundaries
- Open Graph description text could, in rare cases, silently drop everything between a stray `{` inside a code block and an unrelated `}` later in the note; code content is now stripped before `{directive}` parsing

### Changed
- `?about` is now a short in-app pitch (feature list + download button) linking to the GitHub repo for full documentation, instead of duplicating the README

## [1.0.0] - 2026-09-06

Initial release.

### Added
- Drop-in single-file gallery: scans its own folder for jpg/jpeg/png/gif/webp/bmp (and heic/heif when Imagick is available)
- Automatic photo grouping by filename suffix (`name_01`, `name-02`, etc.)
- Full-screen lightbox with keyboard navigation and mobile swipe gestures (left/right/down)
- Optional `.md` notes box with a deliberately small Markdown subset
- Auto-linked thumbnails: a paragraph mentioning a group's name automatically gets a thumbnail preview, view count, and jump link
- Inline directives: `{color: value}`, `{sold}`, `{reserved}` / `{held}` — status directives override a custom color
- Gallery display order mirrors the order groups are first mentioned in the `.md`, falling back to filename order with no `.md`
- Page title derived from the first `.md` file's leading `# Heading`
- View stats: per-photo, per-group, and total page-load counts, updating live via a background ping; SQLite auto-detected with a JSON-file fallback; known preview bots excluded from counts; toggleable via `TRACK_VIEWS`
- Thumbnail generation and caching (Imagick or GD), with HEIC/HEIF conversion for both grid and lightbox
- Deep linking: `?photo=filename` (with a context-aware Open Graph preview), `#group-name`, and `#item-name` anchors, each with one-click copy-to-clipboard permalinks
- Open Graph / Twitter Card meta tags for link previews
- `?about` page with full in-app documentation and a download link; `?download` serves the script itself
- `GALLERY_VERSION` constant for version tracking
