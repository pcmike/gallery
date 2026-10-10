# Gallery

A single-file, drop-in PHP photo gallery. No dependencies, no build step, no database to set up. Put `index.php` in any folder of photos and it renders a gallery for that folder.

Built for sharing "for sale" listings (Reddit, Discord, etc.) as much as for plain photo galleries — automatic or hand-curated photo grouping, a lightweight Markdown notes box with sold/reserved markers, opt-in view stats, and deep links that actually preview correctly when shared. Version 2 also handles **trips**: nested folders to any depth, video, per-photo captions and locations, an owner-only admin editor, items you can hide from the public link, and a map.

**Everything beyond the basic gallery is opt-in.** With no settings changed, this script writes nothing to your folder, makes no requests to any third party, and doesn't show a single subfolder, video, or photo location. (It does peek into subfolders, read-only and capped at 40 of them, purely to warn you if photos in there are being skipped — see [Folders](#folders).)

**Contents:** [Quick start](#quick-start) · [Recipes](#recipes--start-here) · [What affects what](#what-affects-what) · [Features](#features) · [Troubleshooting](#troubleshooting) · [Configuration](#configuration) · [Directive syntax](#directive-syntax) · [Folders](#folders) · [Video](#video) · [Captions and locations](#captions-and-locations) · [The admin editor](#the-admin-editor) · [Hiding items and private view](#hiding-items-and-private-view) · [Locking down the files themselves](#locking-down-the-files-themselves) · [The trip map](#the-trip-map) · [Sharing and previews](#sharing-and-previews) · [Security notes](#security-notes) · [Keeping runtime data out of git](#keeping-runtime-data-out-of-git) · [Upgrading to 2.0](#upgrading-to-20)

## Quick start

```
your-folder/
  index.php     <- this file
  photo1.jpg
  photo2.png
  forsale.md    <- optional
```

Requirements: **PHP 8.3 or newer** (tested on 8.3.32 and 8.4.26 — the whole suite, the fuzzer, the cross-site-scripting check and the real-browser run on both). 8.3 still receives security fixes (until the end of 2027); older versions don't, so the page says so plainly instead of failing. No required extensions — everything degrades gracefully without them. Optional:
- **Imagick** (with HEIC/HEIF support) or **GD** — enables thumbnail generation/caching, once you turn `ENABLE_THUMBNAIL_CACHE` on (see [Configuration](#configuration)). Imagick specifically is required for HEIC/HEIF photos (iPhone default format) to display at all.
- **pdo_sqlite** — used automatically for view-stat storage if present and `TRACK_VIEWS` is on; falls back to a flock-protected JSON file otherwise. (The SQLite store keys its rows by a hash of each photo's path, never the filename, because that file can be fetched by URL.)
- **EXIF** (`exif_read_data`) — only for reading GPS out of JPEGs when you've turned on photo locations.

**A `.md` file only counts if its first line is exactly `{gallery}`.** Anything else in the folder — a personal notes file, a leftover README — is left completely alone, never parsed or rendered. This is required, not optional; there's no way to turn it off.

```
{gallery}
# My Listing
...
```

Both view tracking and thumbnail caching are **off by default** — this script writes nothing to your folder's disk unless you explicitly turn one on (or use the [admin editor](#the-admin-editor), which saves what you type). See [Configuration](#configuration) for why you'll probably want to turn them on anyway.

## Recipes — start here

Pick what you're doing. Each recipe lists the few settings to change near the top of `index.php`, what to do in the [admin editor](#the-admin-editor) if you need it, and what visitors then see. Everything else in this README is the full reference for those pieces.

| I want to… | Go to |
|---|---|
| Just show a folder of photos | [A plain gallery](#recipe-a-plain-gallery) |
| Post a "for sale" listing on Reddit or Discord | [A for-sale listing](#recipe-a-for-sale-listing) |
| Share a trip — captions, places, video, a map | [A trip album](#recipe-a-trip-album) |
| Show family photos but keep some private | [Family photos, some private](#recipe-family-photos-some-private) |
| Include an old archive that lives on another disk | [An archive on another disk](#recipe-an-archive-on-another-disk) |
| Show iPhone (HEIC) photos | [iPhone photos](#recipe-iphone-heic-photos) |
| See exactly what each setting and admin control does | [What affects what](#what-affects-what) |

You only ever edit the `define('NAME', value);` lines near the top of `index.php` (a plain text editor is fine), and, for the admin, click the gallery's **title** (it's a link that looks like plain text) or visit `yoursite.com/your-folder/?admin` — over **HTTPS** (or `localhost` while testing); on plain HTTP the admin refuses to load, because its password would travel unprotected. Every recipe below was followed step by step against the real script, through the admin's real forms, as part of this project's test suite.

### Recipe: a plain gallery

**Do:** copy `index.php` into the folder with your photos. That's all.

**Visitors see:** every photo in that folder (not in its subfolders), in filename order, with a full-screen viewer (arrow keys, swipe on a phone). A link like `yoursite.com/folder/?photo=name.jpg` opens straight to one photo and previews correctly when pasted into Discord or Reddit.

**What it won't do until you ask:** it writes nothing into your folder, loads nothing from any other website, and `?admin`, `?reveal`, `?map` and `?folder=` all do nothing. If photos exist in subfolders, or videos exist anywhere (they're ignored until `ENABLE_VIDEO` is on), a small collapsed "setup notice" tells you that — it's a count, never a name, and it's the cue to try the [trip album](#recipe-a-trip-album) or [turn video on](#video).

**Worth turning on:** `ENABLE_THUMBNAIL_CACHE` → `true` (the page loads small thumbnails instead of full-size originals; required for iPhone photos). `PHOTO_SORT_ORDER` → `'mtime'` to sort by file date instead of name (only if your upload method keeps dates).

### Recipe: a for-sale listing

For a handful of items, several photos each, shared as one link.

**Settings:**

```php
define('AUTO_GROUP_BY_FILENAME', true);   // photos named alpha_01.jpg, alpha_02.jpg group as "alpha"
define('ENABLE_THUMBNAIL_CACHE', true);   // small thumbnails, big saving for buyers on phones
define('TRACK_VIEWS', true);              // optional: count views
define('SHOW_VIEW_COUNTS_PUBLICLY', false); // optional: ...but keep the numbers to yourself
define('ADMIN_PASSWORD', 'a-long-random-string');  // optional: lets you see those counts
```

**Steps:**
1. Copy [`examples/example-forsale.md`](examples/example-forsale.md) into the folder (rename it `forsale.md`). Its first line, `{gallery}`, is what makes the gallery read it.
2. Name your photos after each item — `alpha_01.jpg`, `alpha_02.jpg`, `bravo_01.jpg` — and write that **same word** in the item's paragraph (`Widget **Alpha** — brand new…`). The word must be the whole filename prefix: a paragraph that says "Widget Model A" will *not* pick up `widgeta_01.jpg`.
3. Mark items with `{sold}`, `{reserved}` and `{color: #5865f2}` anywhere in their paragraph. Delete the "How this maps…" explanation at the bottom of the example.
4. Photos with arbitrary names (`IMG_0234.jpg`)? Don't rely on names: write `{group: Widget Alpha} {photos: IMG_0234.jpg, IMG_0891.jpg}` in the paragraph instead. See [Directive syntax](#directive-syntax).

**Visitors see:** the page title from your `# heading`; each item's text with its photos attached beneath it ("View 2 photos"), a box per multi-photo item, a red SOLD ribbon with greyed-out photos, an orange RESERVED tag. A `?photo=` link or a box's copy-link button (🔗) is what to paste into a post.

**Your numbers:** with `SHOW_VIEW_COUNTS_PUBLICLY` off, no view count appears anywhere public — not on photos, not on boxes, not in the footer total. You see them in the admin: the sitewide total at the top, and "👁 N views" under every photo.

### Recipe: a trip album

Folders per place, captions on photos, where-it-was-taken, a map, a video or two.

**Settings:**

```php
define('AUTO_GROUP_BY_FOLDER', true);       // each subfolder becomes an album
define('ENABLE_THUMBNAIL_CACHE', true);
define('ADMIN_PASSWORD', 'a-long-random-string');   // needed for everything below
define('ENABLE_PHOTO_LOCATION', true);      // lets locations be shown (gate 1 of 3)
define('ENABLE_MAP', true);                 // the map (loads Leaflet + OpenStreetMap — the one third-party feature)
define('ENABLE_VIDEO', true);               // .mov / .mp4
```

**Steps** (folders like `trip/Paris/…`, `trip/Lisbon/…`, with `index.php` in `trip/`):
1. Visit `trip/` — each folder is a cover card, in natural name order (Day 2 before Day 10). Click one to open it; a breadcrumb leads back. Folders can nest as deep as you like.
2. Go to `trip/?admin`, pick a folder from the row of links at the top, and fill in **This folder's own settings**: *Display name*, *Dates* (typed text — never read from the photos), *Description*, and *Position among its sibling folders* (1 = first). **Save folder settings.** These show on its cover card, at the top of its page, in link previews, and in the map legend.
3. Still in that folder, tick **Show photo locations publicly for this folder** — locations are off for every folder until you opt it in, so one trip can share places and another never will. **Ticking it publishes:** it asks you to confirm, then reads the GPS out of every photo in that folder that has some (most phone photos do) and shows those places on the gallery and the map straight away. To keep one photo private, open it below and set *Location visibility* to **Always hide** (or clear its coordinates) and Save. Photos you upload to the folder later are picked up the next time you open that folder in the admin.
4. Under **Photos & video**, give a photo a *Caption* and *Web link*, and correct a location if the GPS is off (*Latitude* and *Longitude* are already filled in) — then **Save**. Visitors get small badges: 💬 caption, 📍 location (opens OpenStreetMap), ℹ️ link. The lightbox shows the caption, never the filename.
5. **Set as cover** on a photo to choose the folder's card image. That same photo is the picture shown when someone pastes the folder's link.
6. The map appears on the front page (and at its own shareable `?map` link) as soon as at least one photo has a *visible* location. Photos close together share one numbered pin (`MAP_CLUSTER_RADIUS_MILES`, default 50); pins are colored per top-level folder.

**Videos** show a ▶ tile and their length; they play in the viewer. They can't have their location read automatically — type it in. **Back up your captions:** *Export the whole tree as JSON* at the bottom of any admin page downloads everything you've typed.

### Recipe: family photos, some private

Everything in one gallery; some photos or folders visible only to people you give a password.

**Settings:**

```php
define('AUTO_GROUP_BY_FOLDER', true);       // only if you use folders
define('ADMIN_PASSWORD', 'a-long-random-string');
define('PRIVATE_VIEW_PASSWORD', 'something-you-can-tell-people');   // lets hiding work
```

**Steps:**
1. In `?admin`, hide a single photo: set its **Visibility** to *Always hide*, **Save**. Hide a whole folder: open it in the admin and press **Hide this entire folder from public view** (it hides everything beneath it too).
2. To show *one* photo inside a hidden folder, set that photo's Visibility to *Always show (even if this folder is hidden)*.
3. Tell your people the `PRIVATE_VIEW_PASSWORD` and have them use the small **Private view** link in the page footer. They type the password once; the browser remembers it for a year, and they're sent back to the normal link — so the link you share never changes and never contains a secret. (Each copy of the gallery keeps its own cookie, so several galleries on one site — even with different viewer passwords — never sign each other's viewers out; viewers unlock each gallery once.)

**Visitors without the password see** a gallery with no trace of what's hidden: not in the grid, the viewer, the photo counts, the map or link previews, and requesting a hidden photo or folder directly just gives "not found". **With the password**, everything is back. A valid admin login counts as having it. The admin's **Hidden items** page lists everything hidden in one place.

**One more step that matters:** hiding controls what the *gallery* serves. The photo files are still ordinary files a web server will hand out to anyone who knows an exact address. To close that, add the server rules in [Locking down the files themselves](#locking-down-the-files-themselves) (both nginx and Apache rules are given, and both were tested against the real servers). The admin reminds you of this on every page while anything is hidden.

### Recipe: an archive on another disk

Old trips on a different drive, shown inside the gallery without copying them.

```php
define('AUTO_GROUP_BY_FOLDER', true);
define('EXTRA_FOLDERS', ['OldTrip2020' => '/mnt/archive/trip-photos-2020']);
```

`OldTrip2020` then appears as an album alongside your real folders, and works exactly like one (captions, hiding, map…) — its saved captions and settings live in a `.gallery-items.php` inside *that* folder on the other disk, so for admin edits PHP needs write access there (viewing needs only read). It exists because the gallery refuses symlinked folders (a link could point anywhere on the server); naming the real path in this file, which only you can edit, is the safe alternative. A real folder with the same name wins.

### Recipe: iPhone (HEIC) photos

```php
define('ENABLE_THUMBNAIL_CACHE', true);
```

That's the whole recipe — it also needs the **Imagick** PHP extension with HEIC support. With the cache **off**, `.heic` files are left out of the gallery entirely (browsers can't show them); with it **on** (and Imagick present) they appear with real JPEG thumbnails and are converted for the full-size viewer. GPS in a HEIC is read for the admin's pre-filled location, the same as for a JPEG.

### What affects what

**Settings in `index.php`** (the first four are the ones people reach for):

| Setting | Default | Turn it on / change it to… | Pairs with |
|---|---|---|---|
| `ENABLE_THUMBNAIL_CACHE` | off | `true` for any real deployment: small thumbnails, and iPhone photos | creates `.gallery-cache/` folders |
| `AUTO_GROUP_BY_FILENAME` | off | `true` if photos are named `item_01.jpg, item_02.jpg` | notes `.md` (mention the prefix word) |
| `AUTO_GROUP_BY_FOLDER` | off | `true` to use subfolders as albums | admin folder settings, `FOLDER_DISPLAY_MODE`, `EXTRA_FOLDERS` |
| `TRACK_VIEWS` | off | `true` to count views | `SHOW_VIEW_COUNTS_PUBLICLY`; the admin shows the counts |
| `SHOW_VIEW_COUNTS_PUBLICLY` | on | `false` to count but show nobody (you see them in the admin) | needs `TRACK_VIEWS` |
| `PHOTO_SORT_ORDER` | `'filename'` | `'mtime'` to sort photos *and* folders by date | |
| `FOLDER_DISPLAY_MODE` | `'overview'` | `'inline'` to show a folder's photos in a box instead of a cover card | needs `AUTO_GROUP_BY_FOLDER` |
| `EXTRA_FOLDERS` | none | add `'Name' => '/real/path'` pairs | needs `AUTO_GROUP_BY_FOLDER` |
| `ENABLE_VIDEO` | off | `true` for `.mov` / `.mp4` | |
| `ADMIN_PASSWORD` | none | a long random string — turns the admin on | everything in the next table |
| `ENABLE_PHOTO_LOCATION` | off | `true` to allow locations to be shown | per-folder switch + per-photo setting in the admin |
| `ENABLE_MAP` | off | `true` for the map (the only feature that contacts another website) | needs `ENABLE_PHOTO_LOCATION` and something with a visible location |
| `MAP_CLUSTER_RADIUS_MILES` | 50 | smaller for a city break, larger for a road trip | needs `ENABLE_MAP` |
| `PRIVATE_VIEW_PASSWORD` | none | a shareable password — turns "Private view" on | hiding items in the admin |
| `NGINX_ACCEL_REDIRECT_PREFIX` | none | your gallery's URL path, e.g. `'/kb'`, once nginx is set up | the nginx rules |

**Controls in the admin** (`?admin`; folder controls appear when you open a subfolder):

| Control | Changes what visitors see | Needs |
|---|---|---|
| Folder → *Display name* | the card label, the page title, the breadcrumb, the map legend, the link preview title | `AUTO_GROUP_BY_FOLDER`. A `{group: Name}` in your notes file overrides it |
| Folder → *Dates*, *Description* | shown under the label on the card and at the top of the folder's page; the description is also the link-preview text (a notes file in that folder takes precedence there) | `AUTO_GROUP_BY_FOLDER` |
| Folder → *Position* | the card order among sibling folders: numbered first, then natural order | a notes-file mention of a folder still places it earlier |
| Photo → **Set as cover** | the folder's card image and its link-preview image | |
| Folder → *Show photo locations publicly* | turning it on stores the GPS of every photo in the folder that has some, so they show 📍 badges and map pins (after a confirmation) | `ENABLE_PHOTO_LOCATION` |
| Photo → *Caption* | 💬 badge; the viewer's caption bar | |
| Photo → *Web link* | ℹ️ badge | |
| Photo → *Latitude / Longitude / Location label* | 📍 badge and map pin (filled in from the photo's GPS when it has any; stored for you when the folder's switch is turned on) | `ENABLE_PHOTO_LOCATION`, the folder's switch, and *Location visibility* not *Always hide* |
| Photo → *Location visibility* | *Inherit folder setting*, *Always show* (a landmark in a folder that otherwise shares no locations) or *Always hide* (a front door in a folder that does) | `ENABLE_PHOTO_LOCATION` |
| Photo → *Visibility* · Folder → **Hide this entire folder** | removes it from everything for anyone without the viewer password | `PRIVATE_VIEW_PASSWORD` to let anyone back in |
| Group names (front page) | renames a filename-based group's box | `AUTO_GROUP_BY_FILENAME`. A `{group: Name}` directive overrides it |
| Notes editor | edit, Save As, Delete your `.md` files; add the missing `{gallery}` marker with one click | |
| **Export as JSON** | downloads your saved captions and settings (one folder, or the whole tree) | |

**Elsewhere** (not settings, but they change the result): a notes `.md` file only counts if its first line is `{gallery}` · filenames with a numeric suffix group together when `AUTO_GROUP_BY_FILENAME` is on · the folder structure *is* the album structure when `AUTO_GROUP_BY_FOLDER` is on · the web-server rules decide whether hidden files can still be fetched by address.

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

## Troubleshooting

If something in a `.md` file doesn't behave as expected — a file typo'd in `{photos:}`, a duplicate `{group:}`/`{photos:}` claim, a `.md` file missing its `{gallery}` marker, a directive that isn't attached to the description it was clearly meant to go with — it's silently excluded or repositioned rather than erroring, but never silently *unexplained*. Look for a small "⚠ N setup notices for the gallery owner" banner (collapsed by default) — at the top of the notes box if one exists, or right below the page header if no `.md` qualified at all. The same list is also in an HTML comment near the top of the page source, if you'd rather grep for it. Neither is alarming to a normal visitor — the banner is addressed explicitly to the gallery owner.

**`?admin` or the Private view keeps showing the password form after the right password** means the browser isn't keeping the login cookie: check that cookies aren't blocked for the site and that you're on HTTPS (the cookie is `Secure` there). A script that logs in with an `Authorization` header on Apache with PHP-FPM or CGI also needs `CGIPassAuth On` (see [Locking down the files themselves](#locking-down-the-files-themselves)); the form doesn't.

**"Nothing was saved: this folder isn't writable by the web server"** (in the admin) means PHP can't write into the folder — the single most common hosting snag. Three optional features write there: the admin's saved captions and settings, view counts (`TRACK_VIEWS`), and the thumbnail cache. Without write permission the gallery itself still works fine; those three just can't save. On most hosts the fix is to give the web server's user write permission on the folder (and on any album folder you edit in the admin, including an [`EXTRA_FOLDERS`](#recipe-an-archive-on-another-disk) one). The admin tells you plainly when a save fails; it never reports "Saved" for something it couldn't write.

This README is the full reference. A deployed gallery's `?about` page (the "gallery" link in the footer) gives visitors a short pitch, the feature list, and a download button, then points back here for anything more detailed.

## Configuration

Everything is a constant near the top of `index.php`:

```php
define('GALLERY_VERSION', '2.0.1');

// Off by default — writes .gallery-stats.php/.sqlite into the folder
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
- If photos exist in subfolders but `AUTO_GROUP_BY_FOLDER` is off, a setup notice says so (a count only, never a name; the search is capped at 40 folders so a gallery dropped into a big existing site never walks all of it).
- Sibling folders order by an admin-set position first, then naturally by name (Day 2 before Day 10, case-insensitive) — or by the folder's own modification time if `PHOTO_SORT_ORDER` is `'mtime'`.
- The header shows **"Last updated"**, derived from the newest file modification time across photos and notes, so it also reflects files you uploaded by FTP.

## Video

Set `ENABLE_VIDEO` and `.mov` / `.mp4` files (an iPhone's two formats) become gallery items alongside photos, eligible for grouping, ordering, captions and everything else a photo is. The file is served exactly as it was recorded: no transcoding, no frame extraction.

**It's off by default, and videos are invisible while it is.** A folder holding only videos (say a `Videos/` folder for a trip) doesn't appear at all, because as far as the gallery is concerned it has nothing in it. So you aren't left guessing, a collapsed "setup notices" banner on the main page says how many `.mov`/`.mp4` files it found and ignored, and that `ENABLE_VIDEO` is the switch (a count only, never a folder or file name; the search is capped at 40 folders).

- Tiles and share previews use one generic placeholder graphic (a play triangle), not a frame from the clip. A shared video link previews as a plain icon and title, not a playable embed.
- The lightbox plays it in the browser's own player; there is no autoplay.
- Seeking works (the gallery honors HTTP `Range` requests), and a **duration badge** is read from the file's container.

Things to know before turning it on:

- **Codec support isn't guaranteed.** iPhone `.mov` files are often HEVC. Apple devices play that natively; a browser or OS without an HEVC decoder may not play it, and there is no conversion fallback.
- **Nothing is compressed.** A multi-minute 4K clip can be hundreds of megabytes, served in full each time.
- **Locations aren't read from video.** Add them by hand in the admin.

## Captions and locations

Each photo or video can have a **caption**, an informational **web link**, and a **location**, all entered in the [admin editor](#the-admin-editor). A caption appears in the lightbox (replacing the filename — the lightbox no longer shows filenames at all; with no caption it shows just "3 / 12") and as a small badge on the grid thumbnail (hover shows it on a desktop; tapping the badge opens a small popover on a phone), with the link and location getting badges of their own along the bottom edge. Captions and links need no setting: entering one *is* the opt-in. Caption text is never scanned for mentions and never changes where a photo sorts.

**Location is gated three times, all closed by default**, because GPS is more sensitive than anything else here:

1. `ENABLE_PHOTO_LOCATION` — off means no badges, no map pins, and no EXIF auto-fill anywhere.
2. A **per-folder** switch in the admin — one trip can opt in without affecting any other.
3. A **per-photo** setting: *inherit the folder* (default), *always show*, or *always hide* — so a landmark can be pinned inside an otherwise-private folder, or a front door hidden inside a public one.

The admin always shows and stores location data for you regardless of these; they control only what visitors see. Coordinates come from EXIF for JPEG and HEIC photos that have GPS data (PNG, GIF, WEBP and BMP generally don't — enter those by hand), and are validated as real numbers before they ever reach a link. **Turning a folder's switch on is what publishes them:** it asks first, then stores the GPS of every photo in that folder, so the places appear right away. To keep a photo out, set its *Location visibility* to *Always hide* or clear its coordinates and Save; a photo you have saved yourself is never overwritten by GPS from the file, and a cleared location stays cleared. Photos added to the folder later are picked up when you next open it in the admin (never on a visitor's request). Turning the switch off hides every location again and keeps what was stored. There is no place-name lookup: that needs a third-party service and a key. Paste coordinates from any map tool.

> **Heads-up: the original files keep their GPS.** The gallery serves your original photo files untouched, so a visitor can save a full-size image and read its embedded GPS coordinates even with every gate above closed. The gates control what the *gallery* shows, not what a saved original contains. If that matters, strip location metadata from the photos before uploading them.

## The admin editor

Set `ADMIN_PASSWORD` (a long random string is the right choice — it's yours alone) and open the admin: **click the gallery's title** at the top of the page (on a folder's page it leads to that folder's admin), or go to `?admin`. The title is deliberately unmarked — no underline, colour or hover change — so visitors have no reason to try it. You get a plain one-field password form (no username, no browser pop-up), and stay signed in for the session. With the password unset the admin doesn't exist: `?admin` returns 404, and the title isn't a link. You get:

- **Tabs** in a sticky bar — **Photos**, **Folder settings**, **Notes**, **Export** — showing one section at a time, with the **folders as a tree** in a sidebar (a collapsed bar on a phone), so even a big gallery stays navigable. If anything is hidden, a red **Hidden** entry appears at the top of the sidebar, above Root (see below)
- every photo and video in the current folder, as a compact card with caption and web link up front and location and visibility fields in a disclosure, and a one-click **Set as cover** for the folder
- the folder's own settings: display name, date range, description, position, whether its photo locations show publicly, and whether the whole folder is hidden
- every `.md` in the folder — including ones missing the `{gallery}` marker, tagged as not active, with a one-click **Add `{gallery}` marker** — edited as **raw text, saved byte-for-byte** (never parsed and re-written). **Save**, **Save As** and **Delete** are separate actions (Save As and Delete ask you to confirm), and the destination is locked to a `.md` name so nothing typed there can reach `index.php`. A reference panel beside the editor explains each directive and inserts its syntax at the cursor (it starts collapsed on a phone)
- **Two tabs, one record:** every item and folder form remembers what it loaded, and a save whose record was changed elsewhere in the meantime (another tab or device) is refused with an explanation instead of silently overwriting. Editing a *different* photo in the same folder never conflicts
- **Export as JSON** for the folder or the whole tree — the only backup of the captions and settings you typed, which a plain save can't undo
- the red **Hidden** entry, when anything is hidden: a plain explanation that hiding isn't a lock, then every hidden folder and photo across the whole tree, each linking straight to where you can change it

How it's locked down: the password is compared with `hash_equals()`; the page **refuses to load over plain HTTP** (except on `localhost`/`127.0.0.1`) and explains why, since the password would otherwise travel in the clear; logging in starts a fresh session (a session id issued earlier can never be a logged-in one), which lasts up to 4 hours and ends when you press **Log out** (top right of the admin) — that also clears the Private view cookie, and changing `ADMIN_PASSWORD` signs every existing session out; a CSRF token protects every save; and a failed-login throttle (5 *wrong* passwords from one address within 15 minutes imposes a 60-second delay before that address's next attempt is even checked; just opening the form doesn't count). If you were signed out and submit a change, it isn't saved and the form tells you why. A script can still send an HTTP `Authorization: Basic` header (any username, the password as the password) instead of using the form. The admin works at a phone's width.

**`ADMIN_PASSWORD` lives in `index.php` itself**, a deliberate choice to keep this a single file. Redact it before pasting or sharing your copy of the script for support.

**The admin's data files are protected by default — and worth a second layer.** Everything the admin writes lives in a `.gallery-items.php` inside each folder (and the stats and login-throttle files are `.gallery-stats.php` and `.gallery-login-attempts.php`). They hold your captions, any GPS coordinates you entered, and the exact filenames of everything you've hidden. A web server serves any file in the folder to anyone who asks for its URL, so these are stored as PHP files that begin with a one-line guard: a direct request runs that line, which answers **404 and stops**, so none of the JSON below it is ever sent — on any host that runs PHP, with no server setting needed. The gallery itself never runs these files; it reads them as plain text and skips the first line. Captions are written with `<` and `>` escaped, so a caption can't smuggle PHP into the file.

The one stats file that can't carry that guard is the optional `.gallery-stats.sqlite` — a binary SQLite database can't start with a PHP line. It holds only counts, keyed by a SHA-256 hash of each photo's path, so a visitor who fetches it learns no filenames (hidden items are counted like any other, and their names still never appear). It's still worth adding the server rule in [Locking down the files themselves](#locking-down-the-files-themselves) if you can: it's a second layer over all of these files, and it keeps even those hashed counts private.

## Hiding items and private view

For the photos or clips that shouldn't be on the public link — people who didn't agree to be, say — but that you still want trusted viewers to see in the same gallery:

1. In the admin, hide an **item**, or an entire **folder** (at any depth). A folder's hide applies to everything beneath it; an individual item can be set back to *public* inside a hidden folder, or *hidden* inside a public one.
2. Set `PRIVATE_VIEW_PASSWORD`. A discreet **Private view** link appears in the footer (`?reveal`), asking for that password in the same kind of one-field form; on success it sets a cookie and returns to the plain gallery link, so you can bookmark the bare URL. It is a **password, not a secret link** — links pasted into Discord or Reddit get crawled by preview bots and can't be revoked without breaking them for everyone. A valid admin login counts as revealed too.

A hidden item is removed from the page outright, not merely styled invisible: it's absent from the grid, notes, the lightbox's list, every count and badge (a count that didn't match what's visible would itself be a tell), the map, and link previews — and `?img=`, `?photo=`, `?track=`, `?folder=` and the video endpoint all refuse it to an unrevealed visitor. A group whose members are all hidden disappears rather than showing "0 photos". The same HTTPS requirement and failed-login throttle as the admin apply, with separate lockout state. Viewers stay unlocked for a year; the cookie is specific to each copy of the gallery (it's named and signed from the gallery's own folder), so several galleries on one site never interfere.

### Locking down the files themselves

Everything above governs requests that go through `index.php`. **A photo is still an ordinary static file** that the web server will serve directly if someone knows or guesses its URL (`yoursite.com/kb/IMG_0412.jpg`) — and if filenames run in sequence, a gap shows where something is missing. Hiding keeps hidden items out of every page, but only a web-server rule stops the direct fetch. The admin shows a persistent warning whenever anything is hidden, saying exactly this.

**nginx** — strongest, but needs shell access to the server. Edit the site's config and add, inside the `server { }` block, two locations scoped to the gallery's path (this example assumes `https://yoursite.com/kb/`; a second gallery needs its own pair). Keep the first one above the second, since nginx takes the first matching regex location:

```nginx
# The gallery's generated data files (captions, GPS, hide flags, stats,
# login throttle, thumbnail cache): never public. The .php ones already
# answer 404 on their own; this is a second layer, and the only cover for
# the binary .gallery-stats.sqlite. 404, not 403, so existence isn't
# confirmed either.
location ~ /\.gallery- {
    return 404;
}

# The photo and video files themselves: reachable only through index.php.
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

Both blocks were tested against nginx 1.26 with php-fpm 8.4: every bare photo, video and data-file URL (including a hidden item, a nested one, and an upper-case extension) returns 404; the gallery, thumbnails, full-size images and video `Range` requests (a real `206`) all work through `index.php`; a hidden item stays unreachable by URL even for a viewer who has entered the private-view password; and `?admin`/`?reveal` Basic Auth passes through php-fpm correctly. They assume your site already has the ordinary pieces a PHP site on nginx has — `index index.php;` and a `location ~ \.php$` that hands scripts to php-fpm.

Finally set `NGINX_ACCEL_REDIRECT_PREFIX` to `'/kb'`, matching the `location` above exactly (`.*` already covers nested folders). The script then answers allowed requests with an `X-Accel-Redirect` header and nginx serves the file itself, which also gives you nginx's own, well-tested `Range` handling for video. Leave it empty and the script serves files itself with PHP — correct everywhere, just without the lockdown.

**Apache** — blunter, and works on FTP-only hosting, since `.htaccess` is an uploadable file. Put this in the gallery's folder as `.htaccess`:

```apache
# The photo and video files themselves: reachable only through index.php.
# (?i) matters: camera files are often IMG_0412.JPG, and without it those
# upper-case names would slip straight past this rule.
<FilesMatch "(?i)\.(jpe?g|png|gif|webp|bmp|heic|heif|mov|mp4|m4v)$">
    Require all denied
</FilesMatch>

# The gallery's generated data files (a second layer; the .php ones also
# answer 404 on their own, and this is the only cover for .gallery-stats.sqlite).
<FilesMatch "^\.gallery-">
    Require all denied
</FilesMatch>
```

This blocks only external requests for the bare files (a **403**, where nginx's `internal` gives a 404); `index.php` still reads them straight from disk, so the gallery works as before. It was tested against Apache 2.4.68 with PHP-FPM: bare photos (a hidden one, nested ones, upper-case `.JPG`, videos) and the data files are refused; the gallery, thumbnails and full-size images work; and a hidden photo stays unreachable by address.

**It depends on `AllowOverride`.** `.htaccess` only counts if the server is set to read it for that folder (`AllowOverride All`, or at least `FileInfo`/`AuthConfig`-level access for these rules). Many hosts allow it; some don't, and then **the rules are silently ignored** — tested: with `AllowOverride None` a hidden photo's bare address answers 200. So always check with `curl -I https://yoursite.com/kb/IMG_0412.jpg` (expect 403), the same way as for nginx. Even then the generated data files stay safe, because they carry their own guard. nginx's `internal` is a hard guarantee; this is only as good as your host's `AllowOverride`.

**Scripts that log in with an `Authorization` header on Apache with PHP-FPM or CGI.** Apache doesn't hand that header to PHP in those modes, so a script sending it just gets the password form back. The form that people use doesn't send it and is unaffected. If you do script it, add one line to the same `.htaccess` (Apache 2.4.13 or newer):

```apache
CGIPassAuth On
```

Tested on Apache 2.4.68 + PHP-FPM: with no `.htaccess` at all the form login works and a Basic header is not seen; with `CGIPassAuth On` the header works too. The form login was also tested on nginx 1.26 + PHP-FPM 8.4 (where the header is passed anyway).

Neither renames your files. That would change files you own, which this project doesn't do.

## The trip map

Set `ENABLE_MAP` (and `ENABLE_PHOTO_LOCATION`, plus a folder or photo that actually has a visible location) and the gallery root gets a **Trip map** above the folders, lined up with them, with its own shareable `?map` page. It plots every visible, located photo from every folder together.

- Photos within `MAP_CLUSTER_RADIUS_MILES` (default 50) of each other share one numbered pin, so a dense city doesn't turn into a wall of markers; clicking a cluster zooms in to its individual pins. A dense city trip and a cross-country drive want very different radii, so it's a setting.
- Pins are colored by top-level folder, with a legend.
- Clicking a pin opens a small popup (thumbnail, label, link) — it doesn't navigate on first click; the link inside does, to that group's page.
- Hidden items are never plotted. `?map` has its own link preview, with a generic map-pin image (a rendered snapshot of the map would need a third-party static-maps service).

**This is the one feature that contacts a third party.** It loads Leaflet from cdnjs and map tiles from OpenStreetMap, which is why it's off by default: with it on, the visitor's browser makes those requests.

## Sharing and previews

Every link you're likely to paste gets a correct Open Graph preview: `?photo=`, `?folder=` (the folder's display name, description or notes, and cover photo) and `?map`. A hidden item or folder is treated as nonexistent for an unrevealed requester — including a link-preview crawler, which has no cookie — so pasting its URL anywhere never generates a preview of it.

`?folder=Europe/France/Paris` works with no server configuration, and it's the canonical link the gallery itself hands out (copy-link buttons, link previews). If you'd rather *type* or share `/kb/Europe/France/Paris/`, nginx can map a directory-style URL onto it. Add this inside the `server { }` block, alongside the rules above:

```nginx
location ~ ^/kb/.+/$ {
    rewrite ^/kb/(.+)/$ /kb/index.php?folder=$1 last;
}
```

It was tested with the setup above: `/kb/Europe/France/Paris/` and a folder with a space in its name (`/kb/Day%201/`) both load; a nonexistent folder gets the gallery's own 404; and the gallery's back-link points at the real root. Three things make it safe, and worth keeping if you change it:

- **It only matches a path ending in `/`**, so it can never catch `index.php` or a photo (a rule matching every path under `/kb/` would shadow your PHP handler and could serve the script's source as text).
- **The folder name is captured inside the `rewrite` itself**, which is what makes nginx escape it. Capturing it in the `location` line and passing the variable along breaks on a name with a space (it returned a 502 in testing).
- Every other link on the page (`?img=`, `?folder=`, `?about`, `#anchors`) keeps working from a pretty URL because the query string rides along; the one that didn't — the "back to the gallery" link — is handled by the script (it names the real root when the request path isn't the script's own).

It relies on your php-fpm setup passing the *original* request URL as `REQUEST_URI` (the standard `fastcgi_param REQUEST_URI $request_uri;` does), which is how the script tells a pretty URL from a direct one. Folder names with spaces, `&`, `%`, `+` and `#` were all tested and resolve correctly.

**Apache** can do the same with one line in the gallery's `.htaccess` (needs `AllowOverride` for `FileInfo`, and `mod_rewrite`):

```apache
RewriteEngine On
RewriteRule ^(.+)/$ index.php?folder=$1 [B,L,QSA]
```

The `B` flag is not optional: it escapes the folder name before it goes into the query string, and without it a name with a space, `&`, `+` or `#` fails (tested: a 403 or 404). It only matches a path ending in `/`, so it can't catch `index.php` or a photo. Tested on Apache 2.4.68 with PHP-FPM, including the back-link.

## Keeping runtime data out of git

If you keep your deployed gallery in git, add this to `.gitignore`: these files are generated, not source — and `.gallery-items.php` can contain GPS coordinates, which shouldn't be committed by accident.

```
.gallery-cache/
.gallery-items.php
.gallery-stats.*
.gallery-login-attempts.php
```

`.gallery-items.php` (the captions, locations and settings you typed) and your hand-written `.md` files are the only things here that can't be regenerated — use the admin's JSON export, and your host's own backups for the notes.

## Upgrading to 2.0

Dropping the new `index.php` over a 1.x copy leaves your gallery looking the same, with three things worth knowing:

- **`?download` now redirects to this repository instead of serving the script.** Anything that fetched the script from `?download` on your gallery should get it from GitHub instead. (Serving it directly would have handed `ADMIN_PASSWORD` to any visitor once the admin existed.)
- **Your view counts carry over.** 1.x kept them in `.gallery-stats.json` (or a SQLite file with readable filenames). On the first request with `TRACK_VIEWS` on, 2.0 imports the per-photo counts and the sitewide total into its new stores, then deletes the old JSON file (or drops the old SQLite table and compacts the file, so the old filenames don't linger in it). If the folder isn't writable the old data is simply left where it was.
- **You may see one new setup notice.** If your folder has subfolders containing photos and `AUTO_GROUP_BY_FOLDER` is off, the collapsed "setup notices" banner now says how many photos in subfolders aren't being shown (a count, never a name). Turn the setting on to show them, or ignore it — it's addressed to you, not your visitors.

Everything else is opt-in. Copy your own settings across by editing the constants in the new file. See [CHANGELOG.md](CHANGELOG.md) for the full list.

## Security notes

What the script does to protect you, and the limits worth knowing.

**What it protects, by default**
- **Everything it writes is private.** The generated data files (captions, locations, hide flags, stats, login throttle) answer 404 if requested by URL, on any host that runs PHP — see [The admin editor](#the-admin-editor). Output is HTML-escaped everywhere, captions are written with `<`/`>` escaped in those files, and a request can only ever *select* among files the script found itself, never name a path (`..`, symlinks, wrong-case spellings and NUL bytes are all refused).
- **Logins:** a password form (no browser pop-up) with the password compared in constant time and a fresh session id on login; the admin and private view refuse to load over plain HTTP (except from `localhost`); a failed-login throttle; CSRF tokens on every admin write; a stale-edit check; and an admin session cookie that is `HttpOnly`, `SameSite=Strict`, uniquely named (it won't collide with another PHP app's `PHPSESSID` on the same domain) and rejects a session id the server never issued.
- **Admin and private-view pages** are sent with `Cache-Control: no-store`, `X-Frame-Options: DENY` and a policy that stops them being framed or their forms being posted elsewhere. Every response carries `X-Content-Type-Options: nosniff`.
- **Hidden items stay out of caches.** A hidden photo or video, even when served to a trusted viewer, is `Cache-Control: private, no-store`, and a page rendered for a viewer is too — so a CDN or shared proxy in front of the gallery can't keep one and hand it to the next visitor. Ordinary photos stay cacheable.
- **The Host header** is used in absolute URLs (link previews) only if it looks like a real host name.

**Limits, stated plainly**
- **A web server hands out any file it can see.** Hiding keeps hidden items out of every page, but the files themselves still answer to their exact address unless you add the [server rules](#locking-down-the-files-themselves).
- **Original photos keep their embedded GPS** (see [Captions and locations](#captions-and-locations)).
- **`ADMIN_PASSWORD` and `PRIVATE_VIEW_PASSWORD` live in `index.php`.** Pick long random ones, and redact them before sharing your copy of the file.
- **Behind a reverse proxy, the script believes what the server tells PHP.** It reads the client address from `REMOTE_ADDR` (used for the login throttle and for allowing plain HTTP only from `localhost`) and treats a request as secure if `HTTPS` is set, the port is 443, or `X-Forwarded-Proto` is `https`. With php-fpm and the standard `fastcgi_param`s this is right. If instead you `proxy_pass` to a PHP web server on the same machine, *every* visitor looks like `127.0.0.1` — the HTTPS requirement then protects nothing and the throttle is shared — so pass the real client address and protocol through, or keep PHP behind FastCGI.
- **Photos are cached by browsers for up to a year** (`immutable`) — that's what keeps a big gallery fast — **and replacing one still works.** Every photo and video address carries a short tag (`&v=…`, a hash of the file's modification time and size). Swap `rack.jpg` for a new picture under the same name and its tag changes, so browsers fetch the new one; photos you didn't touch keep their cached copies. (Link previews pick up the new picture too, since the preview address changes with it.) The tag is a hash, so it doesn't reveal when a file was changed. If you ever replace a file with an identical-size one *and* restore its old timestamp, the tag can't tell — touch the file.
- Images and Markdown are processed from folders **you** control. The script isn't built to accept uploads from strangers, and doesn't.

Found a security problem? Please open a GitHub issue, or contact the author privately if it's something that shouldn't be public yet.

## Project status

This is a personal project, actively developed. See [CHANGELOG.md](CHANGELOG.md) for version history.

## License

MIT — see [LICENSE](LICENSE).

---

created by [github.com/pcmike](https://github.com/pcmike)
