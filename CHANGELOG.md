# Changelog

All notable changes to this project are documented here. Versions correspond to the `GALLERY_VERSION` constant in `index.php`.

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
