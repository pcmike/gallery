# Changelog

All notable changes to this project are documented here. Versions correspond to the `GALLERY_VERSION` constant in `index.php`.

## [2.0.0] - 2026-10-10

A large release, built and tested in phases on one branch and shipped as one version. **Everything new is off by default, behind its own constant** — a deployment that changes nothing but the file behaves like 1.1.1, with the one exception below.

### Breaking changes
- **`?download` no longer serves the script.** It now redirects (302) to the GitHub repository. It used to `readfile()` the running `index.php` to anyone who asked; once `ADMIN_PASSWORD` lives in that same file, that would have handed the password to any visitor, and scrubbing secrets out of the output is a fix that has to be remembered forever. Removing the endpoint closes the whole class at once. An old bookmarked link lands on the repository rather than a dead end. `?about`'s download button now points at GitHub too.

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
- `?img=` and `?video=` validate the one claimed path directly instead of scanning the whole tree first — one tree walk per thumbnail is what made large, deep galleries slow. The check rejects `..`, doubled/leading slashes, backslashes, NULs, folders, the script itself, `.gallery-cache`, symlinked folders, non-media extensions, and any name that isn't an exact-case match for a real file (so a case-insensitive filesystem can't dodge a per-photo hide flag). `?track=` and page loads still scan, since they need the whole group.

### Added — video
- `ENABLE_VIDEO`: `.mov` and `.mp4` are served as gallery items. No transcoding, no frame extraction. Tiles and share previews use one generic placeholder graphic; the lightbox plays the clip in a native `<video>`. Seeking works: real HTTP `Range` support (`206`, `416` past the end, malformed ranges ignored). A **duration badge** is read straight from the container's `mvhd` box.

### Added — captions, location, and the admin editor
- **Per-item captions, a web link, and a location**, one per photo or video. The lightbox shows the caption (or just a position counter) — **it no longer prints the raw filename.** Grid badges (caption, location, link) sit along the bottom edge.
- **`ENABLE_PHOTO_LOCATION`** (off by default) is the first of three gates: also needed are a per-folder switch and a per-photo setting (inherit / always show / always hide), all closed by default. GPS is auto-filled from EXIF for JPEG and HEIC where present; coordinates are validated before they reach any URL.
- **Two admin tabs can't silently overwrite each other:** a save whose record changed since the page loaded is refused with an explanation (per record, so different photos never conflict).
- The caption badge is a tap target that opens a popover on a phone, not just a hover tooltip.
- **`?admin`** (`ADMIN_PASSWORD`, empty = the code path doesn't exist): HTTP Basic Auth compared with `hash_equals()`, refused over plain HTTP except on localhost (with a page explaining why), CSRF-protected saves, a 4-hour session, and a failed-login throttle (5 failures in 15 minutes imposes a 60-second delay). Usable from a phone. It edits every item's caption/link/location, folder settings, every `.md` in the folder (raw text, saved byte-for-byte; **Save**, **Save As** and **Delete** are separate, confirmed actions; a one-click fix for a missing `{gallery}` marker), and exports `.gallery-items.json` for a folder or the whole tree. Stale entries for deleted photos are cleaned up and reported.

### Added — hiding items, and Private view
- A **hide flag** per item and per folder (at any depth) in the admin. A hidden item is removed from the page, counts, lightbox list and every endpoint (`?img=`, `?photo=`, `?track=`, `?folder=`, `?video=`) for an unrevealed visitor — not merely styled invisible. A group whose members are all hidden disappears rather than showing "0 photos". Each item can also be forced *public* inside a hidden folder.
- **`PRIVATE_VIEW_PASSWORD`** and a discreet "Private view" footer link (`?reveal`): a password, deliberately not a secret link, since links pasted into chat get crawled. Same HTTPS requirement and throttle as the admin. A valid admin login counts as revealed too.
- An admin **"Hidden items"** summary across the whole tree in three tiers, and a persistent warning that hiding doesn't stop someone who already knows a file's URL unless the web server is locked down.
- **`NGINX_ACCEL_REDIRECT_PREFIX`** hands matching files to nginx via `X-Accel-Redirect`, so an nginx `internal` rule can make the bare file URLs unreachable. See the README for the nginx and Apache setups.

### Added — trip map
- **`ENABLE_MAP`** (off by default): every visible, located photo on one Leaflet + OpenStreetMap map, at its own shareable **`?map`** URL with a condensed preview on the gallery root. Photos within `MAP_CLUSTER_RADIUS_MILES` (default 50) merge into one numbered pin that zooms in when clicked; pins are colored per top-level folder with a legend; a pin opens a popup (thumbnail, label, link) rather than navigating. Hidden items are never plotted. `?map` has its own Open Graph tags and a generic map-pin preview image. **This is the first feature that makes third-party requests** (Leaflet from cdnjs, tiles from OpenStreetMap), which is why it is opt-in.

### Added — other
- `SHOW_VIEW_COUNTS_PUBLICLY` (default `true`): track views for yourself while hiding the public badges.
- View counts are debounced per browser (30 minutes, per photo), so reloading a photo no longer inflates it. A debounced request still returns the current total.

### Notes
- **The `.gallery-items.json` data files are publicly fetchable unless blocked, and they list the filenames of hidden items.** The README now has a one-block nginx rule and an Apache equivalent. The nginx lockdown, data-file and optional pretty-folder-URL rules were tested against nginx 1.26 + php-fpm 8.4; the Apache rules were not.
- The original photo files are served untouched, so **embedded EXIF GPS in the full-size image is not stripped** — the location gates control what the gallery shows, not what a saved original contains. See the README.
- `tests/run.php` now has 285 checks. It also no longer leaks processes: each run used to orphan its `php -S` servers (~350 MB for ten), which accumulated over a long session and exhausted a small host — and it no longer follows directory symlinks when cleaning up its fixtures.

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
