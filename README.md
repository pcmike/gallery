# Gallery

A single-file, drop-in PHP photo gallery. No dependencies, no build step, no database to set up. Put `index.php` in any folder of photos and it renders a gallery for that folder.

Built for sharing "for sale" listings (Reddit, Discord, etc.) as much as for plain photo galleries — automatic or hand-curated photo grouping, a lightweight Markdown notes box with sold/reserved markers, opt-in view stats, and deep links that actually preview correctly when shared.

## Quick start

```
your-folder/
  index.php     <- this file
  photo1.jpg
  photo2.png
  forsale.md    <- optional
```

Requirements: PHP 7.4+ (developed against PHP 8.3/8.4). No required extensions — everything degrades gracefully without them. Optional:
- **Imagick** (with HEIC/HEIF support) or **GD** — enables thumbnail generation/caching, once you turn `ENABLE_THUMBNAIL_CACHE` on (see [Configuration](#configuration)). Imagick specifically is required for HEIC/HEIF photos (iPhone default format) to display at all.
- **pdo_sqlite** — used automatically for view-stat storage if present and `TRACK_VIEWS` is on; falls back to a flock-protected JSON file otherwise.

**A `.md` file only counts if its first line is exactly `{gallery}`.** Anything else in the folder — a personal notes file, a leftover README — is left completely alone, never parsed or rendered. This is required, not optional; there's no way to turn it off.

```
{gallery}
# My Listing
...
```

Both view tracking and thumbnail caching are **off by default** — this script writes nothing to your folder's disk unless you explicitly turn one on. See [Configuration](#configuration) for why you'll probably want to turn them on anyway.

## Features

**Gallery**
- Two ways to group photos, independent of each other:
  - **Auto-grouping** by filename (`dp104_01.jpg`, `dp104_02.jpg` → grouped under "dp104") — off by default, turn on with `AUTO_GROUP_BY_FILENAME` if your photos actually use that naming convention. Only strips a trailing `_01`/`-02` suffix when there's an explicit separator before the digits, so `iphone-15.jpg` groups as "iphone" but `iphone15.jpg` keeps its full name.
  - **Explicit groups** via `{group: Name}` + `{photos: a.jpg, b.jpg}` in a `.md` file — works regardless of filename, for a folder of photos with no naming convention at all. See [Directive syntax](#directive-syntax).
- Full-screen lightbox: keyboard nav, mobile swipe gestures (left/right/down)
- **Layout is two zones**: every group box (auto-detected or explicit) always renders first, stacked as full-width rows; every standalone single and unboxed `{photos:}` cluster follows below, as one continuous grid. A box is a full-width grid item, so this guarantees the wall of singles below it always starts on a fresh row instead of a box interrupting a partially-filled one.
- **Ordering within each zone**: an item with prose (a classic mention, or a directive whose paragraph has other text) sorts earliest-first, by where that text sits in the `.md` — mirroring the order a reader encounters the descriptions. An item with no prose (a textless directive, or anything never mentioned/claimed) falls back to natural order (`PHOTO_SORT_ORDER`) instead of jumping the queue just because its directive happens to sit early in the file.
- For a textless multi-file group/cluster, its position is anchored on whichever file is listed **first** in its `{photos:}` directive — not its lowest-sorting member — with the rest of its files following immediately after, in the exact order listed. Directives are never silently re-sorted.

**Markdown notes** (optional `.md` file, first line must be `{gallery}`)
- Small, deliberate subset of Markdown (headers, bold/italic/underline/strikethrough, code, links, lists, blockquotes)
- Auto-linked thumbnails: mention an auto-detected group's name in a paragraph and its photos auto-attach, no tagging required
- Inline directives: `{color: value}`, `{sold}`, `{reserved}` (or `{held}`), `{group: Name}`, `{photos: a.jpg, b.jpg, ...}`
- First `# Heading` in the first qualifying `.md` file becomes the page title

**View stats** — off by default (`TRACK_VIEWS`)
- Per-photo, per-group, and total page-load counts, all updating live with no refresh, once turned on
- Auto-detects SQLite vs. JSON-file storage; known preview bots excluded from counts

**Thumbnail caching** — off by default (`ENABLE_THUMBNAIL_CACHE`)
- On-the-fly thumbnail generation + caching (Imagick or GD), once turned on
- HEIC/HEIF conversion for both grid and lightbox (Imagick only) — requires this to be on; HEIC files are excluded from the gallery entirely otherwise, even with Imagick installed

**Deep linking & sharing**
- `?photo=filename.jpg` — opens straight to that photo, with a context-aware Open Graph preview
- `#group-name` / `#item-name` — anchors to a gallery group or a specific `.md` item, each with a one-click copy-link button
- `?download` serves the script itself; `?about` renders an in-app overview + download button, linking back here for full docs

**Troubleshooting**
- If something in a `.md` file doesn't behave as expected — a file typo'd in `{photos:}`, a duplicate `{group:}`/`{photos:}` claim, a `.md` file missing its `{gallery}` marker — it's silently excluded rather than erroring, but never silently *unexplained*: view the page source and look for an HTML comment near the top listing anything that didn't resolve cleanly. Never shown to a normal visitor.

This README is the full reference. A deployed gallery's `?about` page (the "gallery" link in the footer) gives visitors a short pitch, the feature list, and a download button, then points back here for anything more detailed.

## Configuration

Everything is a constant near the top of `index.php`:

```php
define('GALLERY_VERSION', '1.1.0');

// Off by default — writes .gallery-stats.json/.sqlite into the folder
// once turned on. Strongly recommended for any real deployment: this is
// what powers the live view-count badges.
define('TRACK_VIEWS', false);

// Off by default — writes into a .gallery-cache folder once turned on.
// Strongly recommended: without it, every photo is served at full
// original resolution as its own "thumbnail" (real bandwidth cost), and
// HEIC/HEIF (iPhone) photos are excluded from the gallery entirely,
// even with Imagick installed.
define('ENABLE_THUMBNAIL_CACHE', false);

// Off by default. Turn on if your photos use a multi-angle naming
// convention (e.g. dp104_01.jpg, dp104_02.jpg) — leaving it on for an
// arbitrary folder of camera photos can falsely merge unrelated shots
// that just happen to share a numeric filename pattern (e.g.
// IMG_0234.jpg / IMG_0235.jpg from two different, unrelated photos).
define('AUTO_GROUP_BY_FILENAME', false);

// 'filename' (default, natural sort) or 'mtime' (file modification
// time, oldest first). 'filename' is the safer default for a script
// meant to be copied/uploaded anywhere — many deployment methods (FTP,
// zip/extract, a fresh git clone, cloud sync) reset a file's
// modification time to "when it was placed here," not when the photo
// was actually taken, which can make 'mtime' order arbitrary. Only
// switch if you've verified your deployment method preserves timestamps.
define('PHOTO_SORT_ORDER', 'filename');
```

## Directive syntax

Place these anywhere in an item's paragraph in a `.md` file:

| Directive | Effect |
|---|---|
| `{color: #5865f2}` or `{color: gold}` | Custom accent color for that group's gallery box |
| `{sold}` | Marks the item sold — dimmed/struck-through text, grayscaled photos, red ribbon |
| `{reserved}` / `{held}` | Marks the item reserved — orange tag/ribbon, photos stay full-color |
| `{group: Name}` | Names a group's gallery box, or renames an auto-detected one |
| `{photos: a.jpg, b.jpg, ...}` | Explicitly assigns specific files to this paragraph, regardless of filename |

`{sold}`/`{reserved}` always override a custom `{color:}` on the same item, so the visual signal stays unambiguous.

`{group:}` and `{photos:}` are two independent controls, not one combined directive:

| Directive(s) in paragraph | Gallery box? | Aggregate view count? | Positional clustering? | Notes-box output |
|---|---|---|---|---|
| `{group:}` + `{photos:}`, no other text | Yes, labeled | Yes | Yes — natural order, anchored on the first-listed file | Nothing — the box's own anchor is the only link |
| `{group:}` + `{photos:}`, with text | Yes, labeled | Yes | Yes — early, by where the text sits in the `.md` | Text + thumbnails + permalink |
| `{photos:}` alone, no text | No | No | Yes — natural order, anchored on the first-listed file | Nothing |
| `{photos:}` alone, with text | No | No | Yes — early, by where the text sits in the `.md` | Text + thumbnails + permalink (anchored on the first listed file) |
| `{group:}` alone, no `{photos:}` | Only if the text also matches an auto-detected group | Inherited from that group | N/A | Renames that group's label |

A file explicitly claimed by `{photos:}` always takes priority over filename-based auto-detection, and is removed from that pool entirely. If the same file is referenced by two different `{photos:}` directives, the first one (in file order) wins — the second is silently dropped, but noted in the troubleshooting HTML comment described above.

## Project status

This is a personal project, actively developed. See [CHANGELOG.md](CHANGELOG.md) for version history.

## License

MIT — see [LICENSE](LICENSE).

---

created by [github.com/pcmike](https://github.com/pcmike)
