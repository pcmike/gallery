# Gallery

A single-file, drop-in PHP photo gallery. No dependencies, no build step, no database to set up. Put `index.php` in any folder of photos and it renders a gallery for that folder.

Built for sharing "for sale" listings (Reddit, Discord, etc.) as much as for plain photo galleries — automatic or hand-curated photo grouping, a lightweight Markdown notes box with sold/reserved markers, opt-in view stats, and deep links that actually preview correctly when shared. Version 2 also handles **trips**: nested folders to any depth, video, per-photo captions and locations, an owner-only admin editor, items you can hide from the public link, and a map.

**Everything beyond the basic gallery is opt-in.** With no settings changed, this script writes nothing to your folder, makes no requests to any third party, and doesn't look at a single subfolder, video, or photo location.

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
- `?about` renders a short in-app overview, linking back here for full docs. (`?download` used to serve the script itself; it now redirects to this repository — see [Upgrading to 2.0](#upgrading-to-20).)

**Trips and larger galleries** (all opt-in — see the sections below)
- [Nested folders](#folders) to any depth, each a cover card that opens its own page, with a breadcrumb and a recursive photo count
- [Video](#video) (`.mov`/`.mp4`) with seeking, a duration badge, and a placeholder tile
- [Per-photo captions, web links, and locations](#captions-and-locations), shown as small badges and in the lightbox
- An owner-only [admin editor](#the-admin-editor) for all of that, plus your notes `.md` files — usable from a phone
- [Hiding items](#hiding-items-and-private-view) from the public link, with a separate viewer password
- A [trip map](#the-trip-map) of everything that has a location

**Troubleshooting**
- If something in a `.md` file doesn't behave as expected — a file typo'd in `{photos:}`, a duplicate `{group:}`/`{photos:}` claim, a `.md` file missing its `{gallery}` marker, a directive that isn't attached to the description it was clearly meant to go with — it's silently excluded or repositioned rather than erroring, but never silently *unexplained*. Look for a small "⚠ N setup notices for the gallery owner" banner (collapsed by default) — at the top of the notes box if one exists, or right below the page header if no `.md` qualified at all. The same list is also in an HTML comment near the top of the page source, if you'd rather grep for it. Neither is alarming to a normal visitor — the banner is addressed explicitly to the gallery owner.

This README is the full reference. A deployed gallery's `?about` page (the "gallery" link in the footer) gives visitors a short pitch, the feature list, and a download button, then points back here for anything more detailed.

## Configuration

Everything is a constant near the top of `index.php`:

```php
define('GALLERY_VERSION', '2.0.0');

// Off by default — writes .gallery-stats.json/.sqlite into the folder
// once turned on. Strongly recommended for any real deployment: this is
// what powers the live view-count badges.
define('TRACK_VIEWS', false);

// Whether view counts are shown publicly once TRACK_VIEWS is on. Set to
// false to keep tracking for yourself while hiding the public badges —
// handy if a low count next to a "for sale" listing would hurt it.
define('SHOW_VIEW_COUNTS_PUBLICLY', true);

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

// Off by default. Scan subfolders (to any depth) and show each as a group.
define('AUTO_GROUP_BY_FOLDER', false);

// 'overview' (default): each folder is a cover card that opens its own
// page. 'inline': folders are just an organizing convenience, and a
// folder's photos show in one box on the page you're already on.
define('FOLDER_DISPLAY_MODE', 'overview');

// Empty by default. Virtual folders pointing at real paths elsewhere on
// the server, e.g. ['OldTrip2020' => '/mnt/archive/trip-photos-2020'].
define('EXTRA_FOLDERS', []);

// Off by default. Recognize .mov and .mp4 files as gallery items.
define('ENABLE_VIDEO', false);

// Off by default. Allow photo locations to be shown at all (also needs a
// per-folder switch in ?admin — see "Captions and locations").
define('ENABLE_PHOTO_LOCATION', false);

// Empty = the admin editor does not exist (?admin returns 404).
define('ADMIN_PASSWORD', '');

// Empty = hiding is inert (?reveal returns 404). A second, view-only
// password that unlocks items you've hidden from the public link.
define('PRIVATE_VIEW_PASSWORD', '');

// Empty = serve files with PHP. On nginx, set to the gallery's URL path
// (e.g. '/kb') after configuring an `internal` location — see "Locking
// down the files themselves".
define('NGINX_ACCEL_REDIRECT_PREFIX', '');

// Off by default. The trip map loads Leaflet (from cdnjs) and map tiles
// (from OpenStreetMap) — the only feature that contacts a third party.
define('ENABLE_MAP', false);

// Photos closer together than this (miles) share one numbered map pin.
define('MAP_CLUSTER_RADIUS_MILES', 50);
```

## Directive syntax

Every directive only ever applies to its own paragraph — never to a paragraph next to it, even one right above it with no other content in between. It's worth knowing precisely what ends a paragraph, since getting this wrong doesn't error, it just silently doesn't attach the way you'd expect:

- **A blank line.** The most common one.
- **A header** (`#`, `##`, `###`), **a blockquote** (`>`), or **a list item** (`-`, `*`, `1.`) — each of these is its own separate thing, never part of a paragraph.
- **A code fence** (` ``` `) — nothing inside a code block is ever paragraph text, directives included.
- **A line starting `(1)`, `(2)`, etc.** — this one's different: it forces a *new* paragraph immediately, without needing a blank line first, which is what lets a numbered listing work with no blank lines between items.

This trips people up in one specific, very natural-to-write way — a description, then a blank line for readability, then a directive on its own line:

```
Take a look at my rack

{group: Rack History} {photos: rack.jpg, rack_older.jpg, rack_oldest.jpg}
```

The blank line makes these two *different* paragraphs, so the directive never sees "Take a look at my rack" as its prose — it's treated as a textless directive (see the table below), which changes both what shows in the notes box and where it sorts. If you meant them to be one item, remove the blank line:

```
Take a look at my rack. {group: Rack History} {photos: rack.jpg, rack_older.jpg, rack_oldest.jpg}
```

If you write it the first way anyway, the on-page notices banner (see [Troubleshooting](#troubleshooting) above) will flag it for you.

Place these directives anywhere in an item's paragraph in a `.md` file:

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

A file explicitly claimed by `{photos:}` always takes priority over filename-based auto-detection, and is removed from that pool entirely. If the same file is referenced by two different `{photos:}` directives, the first one (in file order) wins — the second is silently dropped, but noted in the notices banner described in [Troubleshooting](#troubleshooting) above.

## Folders

Turn on `AUTO_GROUP_BY_FOLDER` and the script reads subfolders, to any depth: `/trip/Europe/France/Paris/` works exactly like `/trip/Paris/`. Each subfolder becomes a group, labelled with the folder's own name (rename it with a `{group: St. Tropez}` directive or the admin's **Display name** field — folder names are never prettified automatically, because `sttropez` can't reliably become "St. Tropez").

In the default `'overview'` mode, each level shows **one cover card per subfolder** (a highlight photo, the name, and a photo count that includes everything beneath it) plus any photos loose at that level. Clicking a card opens `?folder=Europe/France`, which does the same one level down; a breadcrumb links back through every ancestor. With `'inline'`, a folder's photos just appear in a box on the page you're on, which suits a seller who files each item in its own folder but wants everything visible at once.

A few details worth knowing:

- **Per-folder settings** — a display name, a short description, a date-range subtitle (typed by you; it is deliberately *never* read from the photos' EXIF, because that would publish when you were away from home), a chosen cover photo, and a position among sibling folders — are set in the admin editor and shown on the cover card and the folder's own page. Folders with no position sort naturally after any numbered ones.
- **A folder can carry its own notes.** A `{gallery}`-marked `.md` inside a subfolder becomes a note box on that folder's page, scoped to that folder's photos.
- **Everything about a folder lives in that folder.** Stats, the thumbnail cache, and the admin's saved data sit inside the folder they describe, so two cameras' `IMG_0001.jpg` in different folders never collide.
- **Renaming a folder keeps its data; making a new folder and moving photos into it does not.** A real rename (FTP client, file manager, `mv`) carries the folder's data file along. Creating a *new* folder under a different name is, from the gallery's point of view, a different, empty folder.
- **Symlinked folders are refused**, so a link can't make the gallery list files outside itself. To include a folder from elsewhere (an archive on another disk), name it in `EXTRA_FOLDERS` instead.
- If photos exist in subfolders but `AUTO_GROUP_BY_FOLDER` is off, a setup notice says so.
- The header shows **"Last updated"**, derived from the newest file modification time across photos and notes, so it also reflects files you uploaded by FTP.

## Video

Set `ENABLE_VIDEO` and `.mov` / `.mp4` files (an iPhone's two formats) become gallery items alongside photos, eligible for grouping, ordering, captions and everything else a photo is. The file is served exactly as it was recorded: no transcoding, no frame extraction.

- Tiles and share previews use one generic placeholder graphic (a play triangle), not a frame from the clip. A shared video link previews as a plain icon and title, not a playable embed.
- The lightbox plays it in the browser's own player; there is no autoplay.
- Seeking works (the gallery honors HTTP `Range` requests), and a **duration badge** is read from the file's container.

Things to know before turning it on:

- **Codec support isn't guaranteed.** iPhone `.mov` files are often HEVC. Apple devices play that natively; a browser or OS without an HEVC decoder may not play it, and there is no conversion fallback.
- **Nothing is compressed.** A multi-minute 4K clip can be hundreds of megabytes, served in full each time.
- **Locations aren't read from video.** Add them by hand in the admin.

## Captions and locations

Each photo or video can have a **caption**, an informational **web link**, and a **location**, all entered in the [admin editor](#the-admin-editor). A caption appears in the lightbox (replacing the filename — the lightbox no longer shows filenames at all; with no caption it shows just "3 / 12") and as a small badge on the grid thumbnail, with the link and location getting badges of their own along the bottom edge. Captions and links need no setting: entering one *is* the opt-in. Caption text is never scanned for mentions and never changes where a photo sorts.

**Location is gated three times, all closed by default**, because GPS is more sensitive than anything else here:

1. `ENABLE_PHOTO_LOCATION` — off means no badges, no map pins, and no EXIF auto-fill anywhere.
2. A **per-folder** switch in the admin — one trip can opt in without affecting any other.
3. A **per-photo** setting: *inherit the folder* (default), *always show*, or *always hide* — so a landmark can be pinned inside an otherwise-private folder, or a front door hidden inside a public one.

The admin always shows and stores location data for you regardless of these; they control only what visitors see. Coordinates are auto-filled from EXIF for JPEG and HEIC photos that have GPS data (PNG, GIF, WEBP and BMP generally don't — enter those by hand), and are validated as real numbers before they ever reach a link. There is no place-name lookup: that needs a third-party service and a key. Paste coordinates from any map tool.

> **Heads-up: the original files keep their GPS.** The gallery serves your original photo files untouched, so a visitor can save a full-size image and read its embedded GPS coordinates even with every gate above closed. The gates control what the *gallery* shows, not what a saved original contains. If that matters, strip location metadata from the photos before uploading them.

## The admin editor

Set `ADMIN_PASSWORD` (a long random string is the right choice — it's yours alone) and visit `?admin`. With it unset the admin simply doesn't exist: `?admin` returns 404. You get:

- every photo and video in the current folder, with caption, web link and location fields, and a one-click **Set as cover** for the folder
- the folder's own settings: display name, date range, description, position, whether its photo locations show publicly, and whether the whole folder is hidden
- every `.md` in the folder — including ones missing the `{gallery}` marker, tagged as not active, with a one-click **Add `{gallery}` marker** — edited as **raw text, saved byte-for-byte** (never parsed and re-written). **Save**, **Save As** and **Delete** are separate, confirmed actions, the destination is locked to a `.md` name so nothing typed there can reach `index.php`, and a save that touches several files reports each one on its own. A reference panel beside the editor inserts directive syntax at the cursor
- **Export as JSON** for the folder or the whole tree — the only backup of the captions and settings you typed, which a plain save can't undo
- a **Hidden items** summary across the whole tree

How it's locked down: HTTP Basic Auth compared with `hash_equals()`; the page **refuses to load over plain HTTP** (except on `localhost`/`127.0.0.1`) and explains why, since Basic Auth over HTTP is plaintext; a CSRF token protects every save; the session lasts 4 hours; and a failed-login throttle (5 failures from one address within 15 minutes imposes a 60-second delay before that address's next attempt is even checked). There is no reliable cross-browser "log out" — closing the browser is the dependable reset. The admin works at a phone's width.

**`ADMIN_PASSWORD` lives in `index.php` itself**, a deliberate choice to keep this a single file. Redact it before pasting or sharing your copy of the script for support.

Everything the admin writes is as publicly fetchable as a hand-written `.md` already is (the per-folder data is a `.gallery-items.json`) — machine-generated doesn't mean confidential. Don't put anything in a caption you wouldn't put on the page.

## Hiding items and private view

For the photos or clips that shouldn't be on the public link — people who didn't agree to be, say — but that you still want trusted viewers to see in the same gallery:

1. In the admin, hide an **item**, or an entire **folder** (at any depth). A folder's hide applies to everything beneath it; an individual item can be set back to *public* inside a hidden folder, or *hidden* inside a public one.
2. Set `PRIVATE_VIEW_PASSWORD`. A discreet **Private view** link appears in the footer (`?reveal`), prompting for that password; on success it sets a cookie and returns to the plain gallery link, so you can bookmark the bare URL. It is a **password, not a secret link** — links pasted into Discord or Reddit get crawled by preview bots and can't be revoked without breaking them for everyone. A valid admin login counts as revealed too.

A hidden item is removed from the page outright, not merely styled invisible: it's absent from the grid, notes, the lightbox's list, every count and badge (a count that didn't match what's visible would itself be a tell), the map, and link previews — and `?img=`, `?photo=`, `?track=`, `?folder=` and the video endpoint all refuse it to an unrevealed visitor. A group whose members are all hidden disappears rather than showing "0 photos". The same HTTPS requirement and failed-login throttle as the admin apply, with separate lockout state.

### Locking down the files themselves

Everything above governs requests that go through `index.php`. **A photo is still an ordinary static file** that the web server will serve directly if someone knows or guesses its URL (`yoursite.com/kb/IMG_0412.jpg`) — and if filenames run in sequence, a gap shows where something is missing. Hiding keeps hidden items out of every page, but only a web-server rule stops the direct fetch. The admin shows a persistent warning whenever anything is hidden, saying exactly this.

**nginx** — strongest, but needs shell access to the server. Edit the site's config and add, inside the `server { }` block, a location scoped to the gallery's path (this example assumes `https://yoursite.com/kb/`; a second gallery needs its own block):

```nginx
location ~* ^/kb/.*\.(jpe?g|png|gif|webp|bmp|heic|heif|mov|mp4|m4v)$ {
    internal;
}
```

Then check and reload, and confirm the bare URL is now unreachable:

```
sudo nginx -t
sudo systemctl reload nginx
curl -I https://yoursite.com/kb/IMG_0412.jpg     # expect 404
```

Finally set `NGINX_ACCEL_REDIRECT_PREFIX` to `'/kb'`. The script then answers allowed requests with an `X-Accel-Redirect` header and nginx serves the file itself (which also gives you nginx's own, well-tested `Range` handling for video). Leave it empty and the script serves files itself with PHP — correct everywhere, just without the lockdown. The prefix must match the `location` above exactly. `.*` already covers nested folders.

**Apache** — blunter, and works on FTP-only hosting, since `.htaccess` is an uploadable file:

```apache
<FilesMatch "\.(jpe?g|png|gif|webp|bmp|heic|heif|mov|mp4|m4v)$">
    Require all denied
</FilesMatch>
```

This blocks only external requests for the bare file; `index.php` still reads the files straight from disk. It depends on `AllowOverride` being enabled for that directory, which isn't universal, and it can be silently ignored with no symptom — so test it with `curl -I` the same way. nginx's `internal` is a hard guarantee; this is not.

Neither renames your files. That would change files you own, which this project doesn't do.

## The trip map

Set `ENABLE_MAP` (and `ENABLE_PHOTO_LOCATION`, plus a folder or photo that actually has a visible location) and the gallery root gets a **Trip map**, with its own shareable `?map` page. It plots every visible, located photo from every folder together.

- Photos within `MAP_CLUSTER_RADIUS_MILES` (default 50) of each other share one numbered pin, so a dense city doesn't turn into a wall of markers; clicking a cluster zooms in to its individual pins. A dense city trip and a cross-country drive want very different radii, so it's a setting.
- Pins are colored by top-level folder, with a legend.
- Clicking a pin opens a small popup (thumbnail, label, link) — it doesn't navigate on first click; the link inside does, to that group's page.
- Hidden items are never plotted. `?map` has its own link preview, with a generic map-pin image (a rendered snapshot of the map would need a third-party static-maps service).

**This is the one feature that contacts a third party.** It loads Leaflet from cdnjs and map tiles from OpenStreetMap, which is why it's off by default: with it on, the visitor's browser makes those requests.

## Sharing and previews

Every link you're likely to paste gets a correct Open Graph preview: `?photo=`, `?folder=` (the folder's display name, description or notes, and cover photo) and `?map`. A hidden item or folder is treated as nonexistent for an unrevealed requester — including a link-preview crawler, which has no cookie — so pasting its URL anywhere never generates a preview of it.

`?folder=Europe/France/Paris` works with no server configuration. If you'd rather share a path like `/trip/Europe/France/Paris/`, a web-server rewrite that maps the pretty path onto the query string will do it — the script simply reads `$_GET['folder']`, however it got there. No rewrite rule is included or tested here, because the right one depends on your server layout; if you write one, make sure it doesn't also catch `index.php` itself or the real photo files, and check it with `curl` before relying on it.

## Keeping runtime data out of git

If you keep your deployed gallery in git, add this to `.gitignore`: these files are generated, not source — and `.gallery-items.json` can contain GPS coordinates, which shouldn't be committed by accident.

```
.gallery-cache/
.gallery-items.json
.gallery-stats.*
.gallery-login-attempts.json
```

`.gallery-items.json` (the captions, locations and settings you typed) and your hand-written `.md` files are the only things here that can't be regenerated — use the admin's JSON export, and your host's own backups for the notes.

## Upgrading to 2.0

Dropping the new `index.php` over a 1.x copy changes nothing you can see, with **one exception**:

- **`?download` now redirects to this repository instead of serving the script.** Anything that fetched the script from `?download` on your gallery should get it from GitHub instead. (Serving it directly would have handed `ADMIN_PASSWORD` to any visitor once the admin existed.)

Everything else is opt-in. Copy your own settings across by editing the constants in the new file. See [CHANGELOG.md](CHANGELOG.md) for the full list.

## Project status

This is a personal project, actively developed. See [CHANGELOG.md](CHANGELOG.md) for version history.

## License

MIT — see [LICENSE](LICENSE).

---

created by [github.com/pcmike](https://github.com/pcmike)
