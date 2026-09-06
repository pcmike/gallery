# Gallery

A single-file, drop-in PHP photo gallery. No dependencies, no build step, no database to set up. Put `index.php` in any folder of photos and it renders a gallery for that folder.

Built for sharing "for sale" listings (Reddit, Discord, etc.) as much as for plain photo galleries — automatic photo grouping, a lightweight Markdown notes box with sold/reserved markers, view stats, and deep links that actually preview correctly when shared.

## Quick start

```
your-folder/
  index.php     <- this file
  photo1.jpg
  photo2.png
  forsale.md    <- optional
```

Requirements: PHP 7.4+ (developed against PHP 8.3). No required extensions — everything degrades gracefully without them. Optional:
- **Imagick** (with HEIC/HEIF support) or **GD** — enables thumbnail generation/caching. Imagick specifically is required for HEIC/HEIF photos (iPhone default format) to display at all.
- **pdo_sqlite** — used automatically for view-stat storage if present; falls back to a flock-protected JSON file otherwise.

The folder needs to be writable by the web server for view stats and the thumbnail cache to work — both degrade silently (not crash) if it isn't.

## Features

**Gallery**
- Auto-groups photos by filename (`dp104_01.jpg`, `dp104_02.jpg` → grouped under "dp104") — only strips a trailing `_01`/`-02` suffix when there's an explicit separator before the digits, so `iphone-15.jpg` groups as "iphone" but `iphone15.jpg` keeps its full name
- Full-screen lightbox: keyboard nav, mobile swipe gestures (left/right/down)
- Gallery order mirrors the order groups are mentioned in a `.md` file, if one exists

**Markdown notes** (optional `.md` file in the folder)
- Small, deliberate subset of Markdown (headers, bold/italic/underline/strikethrough, code, links, lists, blockquotes)
- Auto-linked thumbnails: mention a group's name in a paragraph and its photos auto-attach, no tagging required
- Inline directives: `{color: value}`, `{sold}`, `{reserved}` (or `{held}`)
- First `# Heading` in the first `.md` file becomes the page title

**View stats**
- Per-photo, per-group, and total page-load counts, all updating live with no refresh
- Auto-detects SQLite vs. JSON-file storage; known preview bots excluded from counts
- Toggle everything off with one constant (`TRACK_VIEWS`)

**Deep linking & sharing**
- `?photo=filename.jpg` — opens straight to that photo, with a context-aware Open Graph preview
- `#group-name` / `#item-name` — anchors to a gallery group or a specific `.md` item, each with a one-click copy-link button
- `?download` serves the script itself; `?about` renders an in-app overview + download button, linking back here for full docs

**Images**
- On-the-fly thumbnail generation + caching (Imagick or GD)
- HEIC/HEIF conversion for both grid and lightbox (Imagick only)

This README is the full reference. A deployed gallery's `?about` page (the "gallery" link in the footer) gives visitors a short pitch, the feature list, and a download button, then points back here for anything more detailed.

## Configuration

Everything is a constant near the top of `index.php`:

```php
define('GALLERY_VERSION', '1.0.1');
define('TRACK_VIEWS', true);   // set false to disable all view tracking
```

## Directive syntax

Place these anywhere in an item's paragraph in a `.md` file:

| Directive | Effect |
|---|---|
| `{color: #5865f2}` or `{color: gold}` | Custom accent color for that group's gallery box |
| `{sold}` | Marks the item sold — dimmed/struck-through text, grayscaled photos, red ribbon |
| `{reserved}` / `{held}` | Marks the item reserved — orange tag/ribbon, photos stay full-color |

`{sold}`/`{reserved}` always override a custom `{color:}` on the same item, so the visual signal stays unambiguous.

## Project status

This is a personal project, actively developed. See [CHANGELOG.md](CHANGELOG.md) for version history.

## License

MIT — see [LICENSE](LICENSE).

---

created by [github.com/pcmike](https://github.com/pcmike)
