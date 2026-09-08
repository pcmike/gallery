# Changelog

All notable changes to this project are documented here. Versions correspond to the `GALLERY_VERSION` constant in `index.php`.

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
