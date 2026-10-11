# Gallery

A single-file, drop-in PHP photo gallery. No dependencies, no build step, no database to set up. Put `index.php` in any folder of photos and it renders a gallery for that folder.

Built for sharing "for sale" listings (Reddit, Discord, etc.) as much as for plain photo galleries — automatic or hand-curated photo grouping, a lightweight Markdown notes box with sold/reserved markers, opt-in view stats, and deep links that actually preview correctly when shared. Version 2 also handles **trips**: nested folders to any depth, video, per-photo captions and locations, an owner-only admin editor, items you can make private (kept off the public link), and a map.

**Everything beyond the basic gallery is opt-in.** With no settings changed, this script writes only small thumbnails into a `.gallery-cache` folder (nothing else), makes no requests to any third party, and doesn't show a single subfolder, video, or photo location. (It does peek into subfolders, read-only and capped at 40 of them, purely to warn you if photos in there are being skipped — see [Folders](#folders).)

**Contents:** [Quick start](#quick-start) · [Recipes](#recipes--start-here) · [What affects what](#what-affects-what) · [Features](#features) · [Troubleshooting](#troubleshooting) · [Configuration](#configuration) · [Directive syntax](#directive-syntax) · [Folders](#folders) · [Video](#video) · [Captions and locations](#captions-and-locations) · [The admin editor](#the-admin-editor) · [Private items and the private view](#private-items-and-the-private-view) · [Locking down the files themselves](#locking-down-the-files-themselves) · [The maps](#the-maps) · [Sharing and previews](#sharing-and-previews) · [Security notes](#security-notes) · [Keeping runtime data out of git](#keeping-runtime-data-out-of-git) · [Upgrading to 2.0](#upgrading-to-20)

## Quick start

```
your-folder/
  index.php     <- this file
  photo1.jpg
  photo2.png
  forsale.md    <- optional
```

Requirements: **PHP 8.3 or newer** (tested on 8.3.32 and 8.4.26 — the whole suite, the fuzzer, the cross-site-scripting check and the real-browser run on both). 8.3 still receives security fixes (until the end of 2027); older versions don't, so the page says so plainly instead of failing. No required extensions — everything degrades gracefully without them. Optional:
- **Imagick** (with HEIC/HEIF support) or **GD** — enables thumbnail generation/caching, which is on by default (`ENABLE_THUMBNAIL_CACHE`, see [Configuration](#configuration)); without either, or if the folder can't be written, the original photos are served instead. Imagick specifically is required for HEIC/HEIF photos (iPhone default format) to display at all.
- **pdo_sqlite** — used automatically for view-stat storage if present and `TRACK_VIEWS` is on; falls back to a flock-protected JSON file otherwise. (The SQLite store keys its rows by a hash of each photo's path, never the filename, because that file can be fetched by URL.)
- **EXIF** (`exif_read_data`) — only for reading GPS out of JPEGs when you've turned on photo locations.

**A `.md` file only counts if its first line is exactly `{gallery}`.** Anything else in the folder — a personal notes file, a leftover README — is left completely alone, never parsed or rendered. This is required, not optional; there's no way to turn it off.

```
{gallery}
# My Listing
...
```

Thumbnail caching is **on by default** (it writes only small derived images into `.gallery-cache`); view tracking is **off** — nothing else is written to your folder's disk unless you turn it on or use the [admin editor](#the-admin-editor), which saves what you type. See [Configuration](#configuration) for what each setting does.

## Recipes — start here

Pick what you're doing. Each recipe lists the few settings to change — written below as `define('NAME', value);`; put them in a [settings file](#keeping-your-settings-across-updates) so an update doesn't reset them (or change the default in `index.php`'s own `gallery_setting('NAME', default)` line) — what to do in the [admin editor](#the-admin-editor) if you need it, and what visitors then see. Everything else in this README is the full reference for those pieces.

| I want to… | Go to |
|---|---|
| Just show a folder of photos | [A plain gallery](#recipe-a-plain-gallery) |
| Post a "for sale" listing on Reddit or Discord | [A for-sale listing](#recipe-a-for-sale-listing) |
| Share a trip — captions, places, video, a map | [A trip album](#recipe-a-trip-album) |
| Show family photos but keep some private | [Family photos, some private](#recipe-family-photos-some-private) |
| Include an old archive that lives on another disk | [An archive on another disk](#recipe-an-archive-on-another-disk) |
| Show iPhone (HEIC) photos | [iPhone photos](#recipe-iphone-heic-photos) |
| See exactly what each setting and admin control does | [What affects what](#what-affects-what) |

Settings are changed from the admin's **Site settings** screen (or in a [settings file](#keeping-your-settings-across-updates) that survives updates). To get into the admin the first time, **open `yoursite.com/your-folder/?admin` within five minutes of uploading `index.php`** and choose a password ([First-run setup](#first-run-setup)); after that, press the **lock** beside the gallery's title and type the admin password (or visit `?admin`) — over **HTTPS** (or `localhost` while testing); on plain HTTP the admin refuses to load, because its password would travel unprotected. Every recipe below was followed step by step against the real script, through the admin's real forms, as part of this project's test suite.

### Recipe: a plain gallery

**Do:** copy `index.php` into the folder with your photos. That's all.

**Visitors see:** every photo in that folder (not in its subfolders), in filename order, with a full-screen viewer (arrow keys, swipe on a phone). A link like `yoursite.com/folder/?photo=name.jpg` opens straight to one photo and previews correctly when pasted into Discord or Reddit.

**What it won't do until you ask:** it writes nothing into your folder except thumbnails, loads nothing from any other website, and `?admin`, `?reveal`, `?map` and `?folder=` all do nothing. If photos exist in subfolders, or videos exist anywhere (they're ignored until `ENABLE_VIDEO` is on), a small collapsed "setup notice" tells you that — it's a count, never a name, and it's the cue to try the [trip album](#recipe-a-trip-album) or [turn video on](#video).

**Worth knowing:** thumbnails are already on (the page loads small thumbnails instead of full-size originals, and iPhone photos need them). `PHOTO_SORT_ORDER` → `'mtime'` to sort by file date instead of name (only if your upload method keeps dates).

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

**Visitors see:** the page title (the gallery title you set in the admin, else your `# heading`); each item's text with its photos attached beneath it ("View 2 photos"), a box per multi-photo item, a red SOLD ribbon with greyed-out photos, an orange RESERVED tag. A `?photo=` link or a box's copy-link button (🔗) is what to paste into a post.

**Your numbers:** with `SHOW_VIEW_COUNTS_PUBLICLY` off, no view count appears anywhere public — not on photos, not on boxes, not in the visit count beside the gallery icon. You see them in the admin: the visit total on the Photos tab, and each photo's own count when you select it.

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
2. Go to `trip/?admin`, pick a folder from the row of links at the top, and fill in **This folder's own settings**: *Display name*, *Dates* (typed text; the admin suggests the span of the folder's photos, with a one-click "Use these dates" button), *Description*, and *Position among its sibling folders* (1 = first). **Save folder settings.** These show on its cover card, at the top of its page, in link previews, and in the map legend.
3. Still in that folder, tick **Show photo locations publicly for this folder** — locations are off for every folder until you opt it in, so one trip can share places and another never will. **Ticking it publishes:** an orange note beside the switch says so (and how many photos have GPS); it saves at once and reads the GPS out of every photo in that folder that has some (most phone photos do) and shows those places on the gallery and the map straight away. To keep one photo private, open it below and switch off its **Show its location** (or clear its coordinates and Save). Photos you upload to the folder later are picked up the next time you open that folder in the admin.
4. Under **Photos & video**, give a photo a *Caption* and *Web link*, and correct a location if the GPS is off (*Latitude* and *Longitude* are already filled in) — then **Save**. Visitors get small badges: caption-bubble icon, pin icon for location (opens OpenStreetMap), globe link. The lightbox shows the caption, never the filename.
5. **Set as cover** on a photo (the cover is highlighted in the grid) to choose the folder's card image, and pressing that folder's card then opens the folder already scrolled to that photo. That same photo is the picture shown when someone pastes the folder's link.
6. The map appears on the front page (and a shareable `?map` link that opens it) as soon as at least one photo has a *visible* location. Photos close together share one numbered pin (`MAP_CLUSTER_RADIUS_MILES`, default 1 mile); pins are colored per top-level folder.

**Videos** show a play-button tile and their length; they play in the viewer. They can't have their location read automatically — type it in. **Back up your captions:** *Export the whole tree as JSON* at the bottom of any admin page downloads everything you've typed.

### Recipe: family photos, some private

Everything in one gallery; some photos or folders visible only to people you give a password.

**Settings:**

```php
define('AUTO_GROUP_BY_FOLDER', true);       // only if you use folders
define('ADMIN_PASSWORD', 'a-long-random-string');
define('PRIVATE_VIEW_PASSWORD', 'something-you-can-tell-people');   // lets private items work
```

**Steps:**
1. In `?admin`, make a photo private with its **Private** switch (it saves as you flip it). To do several at once, press **Select photos** above the thumbnails, pick them, and press **Make private**. Make a whole folder private: open it in the admin and press **Make this entire folder private** (everything beneath it goes with it).
2. To show *one* photo inside a private folder, switch that photo's **Private** off: it stays public even though its folder is private.
3. Tell your people the `PRIVATE_VIEW_PASSWORD` and have them press the small **lock** beside the gallery's title (it appears once something is private). They type the password once; the browser remembers it for a year, and they're sent back to the normal link — so the link you share never changes and never contains a secret. (Each copy of the gallery keeps its own cookie, so several galleries on one site — even with different viewer passwords — never sign each other's viewers out; viewers unlock each gallery once.)

**Visitors without the password see** a gallery with no trace of what's private: not in the grid, the viewer, the photo counts, the map or link previews, and requesting a private photo or folder directly just gives "not found". **With the password**, everything is back. A valid admin login counts as having it. The admin's **Private** page lists everything private in one place.

**One more step that matters:** making something private controls what the *gallery* serves. The photo files are still ordinary files a web server will hand out to anyone who knows an exact address. To close that, add the server rules in [Locking down the files themselves](#locking-down-the-files-themselves) (both nginx and Apache rules are given, and both were tested against the real servers). The admin reminds you of this on every page while anything is private.

### Recipe: an archive on another disk

Old trips on a different drive, shown inside the gallery without copying them. The simplest way is in the admin: **Site settings → Folders from elsewhere**, paste the path, press *Add*. The folders inside that place then appear **at the top of the gallery**, as if they lived in it: if `/space/trip` holds `Paris` and `StTropez`, the gallery gets `Paris` and `StTropez`, with no folder named `trip` in between. (Photos lying directly in `/space/trip` aren't shown; a setup notice counts them. Put them in a folder.) A real folder of the same name always wins.

```php
define('AUTO_GROUP_BY_FOLDER', true);
define('EXTRA_ROOTS', ['/space/trip']);                              // its folders appear at the top
define('EXTRA_FOLDERS', ['OldTrip2020' => '/mnt/archive/trip-photos-2020']);   // or: this one place, shown as one folder with this name
```

With a **name**, the whole place shows as one album instead: `OldTrip2020` then appears alongside your real folders, and works exactly like one (captions, private items, map…) — its saved captions and settings live in a `.gallery-items.php` inside *that* folder on the other disk, so for admin edits PHP needs write access there (viewing needs only read). It exists because the gallery refuses symlinked folders (a link could point anywhere on the server); naming the real path in this file, which only you can edit, is the safe alternative. A real folder with the same name wins.

### Recipe: iPhone (HEIC) photos

```php
define('ENABLE_THUMBNAIL_CACHE', true);
```

That's the whole recipe — it also needs the **Imagick** PHP extension with HEIC support. With the cache **off**, `.heic` files are left out of the gallery entirely (browsers can't show them); with it **on** (and Imagick present) they appear with real JPEG thumbnails and are converted for the full-size viewer. GPS in a HEIC is read for the admin's pre-filled location, the same as for a JPEG.

### What affects what

**Settings in `index.php`** (the first four are the ones people reach for):

| Setting | Default | Turn it on / change it to… | Pairs with |
|---|---|---|---|
| `ENABLE_THUMBNAIL_CACHE` | **on** | `false` for a gallery that must never write to its folder (then every photo is served full-size and iPhone photos can't be shown) | creates `.gallery-cache/` folders; falls back to the original if it can't write |
| `AUTO_GROUP_BY_FILENAME` | off | `true` if photos are named `item_01.jpg, item_02.jpg` | notes `.md` (mention the prefix word) |
| `AUTO_GROUP_BY_FOLDER` | off | `true` to use subfolders as albums | admin folder settings, `FOLDER_DISPLAY_MODE`, `EXTRA_FOLDERS` |
| `TRACK_VIEWS` | off | `true` to count views | `SHOW_VIEW_COUNTS_PUBLICLY`; the admin shows the counts |
| `SHOW_VIEW_COUNTS_PUBLICLY` | **off** | `true` to show visitors the view counts (you always see them in the admin) | needs `TRACK_VIEWS` |
| `ENABLE_PUBLIC_DOWNLOAD` | **off** | `true` to give every visitor a button in the photo viewer that downloads the original (signed-in visitors always have it) | the download button for visitors who aren't signed in |
| `PHOTO_SORT_ORDER` | `'filename'` | `'mtime'` to sort photos *and* folders by date | |
| `FOLDER_DISPLAY_MODE` | `'overview'` | `'inline'` to show a folder's photos in a box instead of a cover card | needs `AUTO_GROUP_BY_FOLDER` |
| `EXTRA_ROOTS` | none | a list of `'/real/path'` places whose folders appear at the top of the gallery | needs `AUTO_GROUP_BY_FOLDER` |
| `EXTRA_FOLDERS` | none | add `'Name' => '/real/path'` pairs: one place shown as one named folder | needs `AUTO_GROUP_BY_FOLDER` |
| `ENABLE_VIDEO` | off | `true` for `.mov` / `.mp4` | |
| `ADMIN_PASSWORD` | none | choose it in the browser right after upload ([First-run setup](#first-run-setup)), or put a long random string in the settings file — turns the admin on | everything in the next table |
| `ENABLE_PHOTO_LOCATION` | off | `true` to allow locations to be shown | per-folder switch + per-photo setting in the admin |
| `ENABLE_MAP` | off | `true` for the map (the only feature that contacts another website) | needs `ENABLE_PHOTO_LOCATION` and something with a visible location |
| `MAP_CLUSTER_RADIUS_MILES` | 1 | smaller for a city break (fractions are fine, `0.1`), `0` for no grouping at all, larger for a road trip. This is the default; each folder and the front page can set their own | needs `ENABLE_MAP` |
| `PRIVATE_VIEW_PASSWORD` | none | a shareable password — turns "Private view" on | private items in the admin |
| `NGINX_ACCEL_REDIRECT_PREFIX` | none | your gallery's URL path, e.g. `'/kb'`, once nginx is set up | the nginx rules |

**Controls in the admin** (`?admin`; folder controls appear when you open a subfolder):

| Control | Changes what visitors see | Needs |
|---|---|---|
| Gallery (root) → *Gallery title*, *Dates*, *Description*, *Cover* | the page title and browser tab, dates under the title, a description box above the notes, the shared-link title, text and picture. The root is configured the same way a folder is; the title overrides a `# Heading` in a notes file | Always available in the admin |
| Folder → *Display name* | the card label, the page title, the breadcrumb, the map legend, the link preview title | `AUTO_GROUP_BY_FOLDER`. A `{group: Name}` in your notes file overrides it |
| Folder → *Dates*, *Description* | shown under the label on the card and at the top of the folder's page; the description is also the link-preview text (a notes file in that folder takes precedence there) | `AUTO_GROUP_BY_FOLDER` |
| Folder → *Position* | the card order among sibling folders: numbered first, then natural order | a notes-file mention of a folder still places it earlier |
| Photo → **Set as cover** | the folder's card image and its link-preview image | |
| Folder (or gallery) → *Map: show a map on this page, pin grouping* | a map of just that folder's photos on its page, and a shareable `?map&folder=` link that opens it; the front page's map is on unless switched off, a folder's off unless switched on | `ENABLE_MAP`, `ENABLE_PHOTO_LOCATION`, and photos with a visible location |
| Folder → *Show photo locations publicly* | turning it on stores the GPS of every photo in the folder that has some, so they show pin icons and map pins (an orange warning sits beside the switch) | `ENABLE_PHOTO_LOCATION` |
| Photo → *Caption* | caption-bubble icon; the viewer's caption bar | |
| Photo → *Web link* | globe icon, and an icon in the viewer | |
| Photo → *Latitude / Longitude / Location label* | pin icon and map pin (filled in from the photo's GPS when it has any; stored for you when the folder's switch is turned on) | `ENABLE_PHOTO_LOCATION`, the folder's switch, and the photo's *Show its location* not switched off |
| Photo → *Show its location* switch | on shows just this photo's location (a landmark in a folder that otherwise shares none); off keeps it private (a front door in a folder that shows locations), and stays off even if the folder is switched on later | `ENABLE_PHOTO_LOCATION` |
| Photo → *Private* switch · Folder → **Make this entire folder private** | removes it from everything for anyone without the viewer password | `PRIVATE_VIEW_PASSWORD` to let anyone back in |
| Group names (front page) | renames a filename-based group's box | `AUTO_GROUP_BY_FILENAME`. A `{group: Name}` directive overrides it |
| Notes editor | edit, Save As, Delete your `.md` files; add the missing `{gallery}` marker with one click | |
| **Export as JSON** | downloads your saved captions and settings (one folder, or the whole tree) | |

**Elsewhere** (not settings, but they change the result): a notes `.md` file only counts if its first line is `{gallery}` · filenames with a numeric suffix group together when `AUTO_GROUP_BY_FILENAME` is on · the folder structure *is* the album structure when `AUTO_GROUP_BY_FOLDER` is on · the web-server rules decide whether private files can still be fetched by address.

## Features

**Gallery**
- Two ways to group photos, independent of each other:
  - **Auto-grouping** by filename (`dp104_01.jpg`, `dp104_02.jpg` → grouped under "dp104") — off by default, turn on with `AUTO_GROUP_BY_FILENAME` if your photos actually use that naming convention. Only strips a trailing `_01`/`-02` suffix when there's an explicit separator before the digits, so `iphone-15.jpg` groups as "iphone" but `iphone15.jpg` keeps its full name.
  - **Explicit groups** via `{group: Name}` + `{photos: a.jpg, b.jpg}` in a `.md` file — works regardless of filename, for a folder of photos with no naming convention at all. See [Directive syntax](#directive-syntax).
- Full-screen viewer with zoom (a zoom button, the photo itself, or `z`: fit the screen, fill the width of the window and scroll up and down, or actual size, always from the original and never distorted): keyboard arrows and phone swipe (left/right/down), staying inside the folder of the photo you opened; the caption, the photo's web link, and (with the map on) a small map of where it was taken
- **Download the original.** The viewer's caption bar has a download button that saves the photo or video exactly as it sits on the server (a HEIC stays HEIC). Anyone signed in (you, or with the private-view password) always has it; for everyone else it is the Site setting `ENABLE_PUBLIC_DOWNLOAD` (**off** by default). A visitor who isn't signed in is given a photo whose location the gallery hides with the location blanked out. See [Downloads and locations](#downloads-and-locations).
- **Layout is two zones**: every group box (auto-detected or explicit) always renders first, stacked as full-width rows; every standalone single and unboxed `{photos:}` cluster follows below, as one continuous grid. A box is a full-width grid item, so this guarantees the wall of singles below it always starts on a fresh row instead of a box interrupting a partially-filled one.
- **Ordering within each zone**: an item with prose (a classic mention, or a directive whose paragraph has other text) sorts earliest-first, by where that text sits in the `.md` — mirroring the order a reader encounters the descriptions. An item with no prose (a textless directive, or anything never mentioned/claimed) falls back to natural order (`PHOTO_SORT_ORDER`) instead of jumping the queue just because its directive happens to sit early in the file.
- For a textless multi-file group/cluster, its position is anchored on whichever file is listed **first** in its `{photos:}` directive — not its lowest-sorting member — with the rest of its files following immediately after, in the exact order listed. Directives are never silently re-sorted.

**Markdown notes** (optional `.md` file, first line must be `{gallery}`)
- Small, deliberate subset of Markdown (headers, bold/italic/underline/strikethrough, code, links, lists, blockquotes)
- Auto-linked thumbnails: mention an auto-detected group's name in a paragraph and its photos auto-attach, no tagging required
- Inline directives: `{color: value}`, `{sold}`, `{reserved}` (or `{held}`), `{group: Name}`, `{photos: a.jpg, b.jpg, ...}`
- First `# Heading` in the first qualifying `.md` file becomes the page title, unless you've set a **Gallery title** in the admin (see [The admin editor](#the-admin-editor)), which wins

**View stats** — off by default (`TRACK_VIEWS`)
- **Visits**, not page loads: the site total counts **one per visitor per half hour**, however many folders and photos they open, so it tracks the number of people rather than the number of clicks. Reloading, the back button and a shared photo link are all part of the same visit; coming back after more than 30 minutes of silence is a new one. A small first-party cookie remembers when each visitor was last here (it holds nothing but a time).
- **Photo views**: each photo's own count goes up when it's opened in the viewer, once per photo per visitor per half hour, so flipping back and forth over one photo doesn't inflate it. A box adds up its photos. Both update live, with no refresh.
- **Not counted**: you (the admin's own browser, once you've logged in), link-preview and search-engine bots, a browser prefetching a page, and `HEAD` requests from link checkers.
- Auto-detects SQLite vs. JSON-file storage.

**Thumbnail caching** — on by default (`ENABLE_THUMBNAIL_CACHE`)
- On-the-fly thumbnail generation + caching (Imagick or GD), on unless you turn it off
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
- [Private items](#private-items-and-the-private-view): photos, videos and whole folders kept off the public link, with a separate viewer password
- [Maps](#the-maps): a page map (closed until a visitor opens it) and a small map in the photo viewer, both only when you turn them on

## Troubleshooting

If something in a `.md` file doesn't behave as expected — a file typo'd in `{photos:}`, a duplicate `{group:}`/`{photos:}` claim, a `.md` file missing its `{gallery}` marker, a directive that isn't attached to the description it was clearly meant to go with — it's silently excluded or repositioned rather than erroring, but never silently *unexplained*. Look for a small "⚠ N setup notices for the gallery owner" banner (collapsed by default) — at the top of the notes box if one exists, or right below the page header if no `.md` qualified at all. The same list is also in an HTML comment near the top of the page source, if you'd rather grep for it. Neither is alarming to a normal visitor — the banner is addressed explicitly to the gallery owner.

**`?admin` or the Private view keeps showing the password form after the right password** means the browser isn't keeping the login cookie: check that cookies aren't blocked for the site and that you're on HTTPS (the cookie is `Secure` there). A script that logs in with an `Authorization` header on Apache with PHP-FPM or CGI also needs `CGIPassAuth On` (see [Locking down the files themselves](#locking-down-the-files-themselves)); the form doesn't.

**"Nothing was saved: this folder isn't writable by the web server"** (in the admin) means PHP can't write into the folder — the single most common hosting snag. Three features write there: the admin's saved captions and settings, view counts (`TRACK_VIEWS`), and the thumbnail cache. Without write permission the gallery itself still works fine: the first two just can't save, and the cache quietly falls back to serving the original photos. On most hosts the fix is to give the web server's user write permission on the folder (and on any album folder you edit in the admin, including an [`EXTRA_FOLDERS`](#recipe-an-archive-on-another-disk) one). The admin tells you plainly when a save fails; it never reports "Saved" for something it couldn't write.

This README is the full reference. A deployed gallery's `?about` page (the small gallery icon at the right end of the bar above the photos) gives visitors a short pitch, the feature list, and a download button, then points back here for anything more detailed.

## Configuration

Everything is a setting near the top of `index.php`. The `define('NAME', gallery_setting('NAME', default))` lines there are the **defaults**; a [settings file](#keeping-your-settings-across-updates) beside it overrides them and survives updates. The block below shows the defaults (the second argument):

```php
define('GALLERY_VERSION', '2.0.1');

// Off by default — writes .gallery-stats.php/.sqlite into the folder
// once turned on. Strongly recommended for any real deployment: this is
// what powers the live view-count badges.
define('TRACK_VIEWS', false);

// Whether view counts are shown to visitors once TRACK_VIEWS is on. Off by
// default, so turning on tracking never publishes numbers by surprise (you
// always see them in the admin). Set to true to show the live badges.
define('SHOW_VIEW_COUNTS_PUBLICLY', false);

// On by default — small thumbnails go into a .gallery-cache folder.
// Without it every photo is served at full original resolution as its
// own "thumbnail" (real bandwidth cost) and HEIC/HEIF (iPhone) photos
// can't be shown at all. If the folder can't be written, the original
// photo is served instead. Set to false to never write there.
define('ENABLE_THUMBNAIL_CACHE', true);

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
define('EXTRA_ROOTS', []);     // e.g. ['/space/trip']: the folders inside it appear at the top of the gallery
define('EXTRA_FOLDERS', []);

// Off by default. Recognize .mov and .mp4 files as gallery items.
define('ENABLE_VIDEO', false);

// Off by default. Allow photo locations to be shown at all (also needs a
// per-folder switch in ?admin — see "Captions and locations").
define('ENABLE_PHOTO_LOCATION', false);

// Empty = the admin editor does not exist (?admin returns 404).
define('ADMIN_PASSWORD', '');

// Empty = private items are inert (?reveal returns 404). A second, view-only
// password, of any length, that unlocks items you've made private.
define('PRIVATE_VIEW_PASSWORD', '');

// Empty = serve files with PHP. On nginx, set to the gallery's URL path
// (e.g. '/kb') after configuring an `internal` location — see "Locking
// down the files themselves".
define('NGINX_ACCEL_REDIRECT_PREFIX', '');

// Off by default. The maps load Leaflet (from cdnjs) and map tiles
// (from OpenStreetMap) — the only feature that contacts a third party.
define('ENABLE_MAP', false);

// Photos closer together than this (miles) share one numbered map pin.
define('MAP_CLUSTER_RADIUS_MILES', 1);
```


### Keeping your settings across updates

Updating the gallery means replacing `index.php` with a newer copy, and anything you edited into the old one is gone with it. To keep your settings, they live in a **settings file** instead: `.gallery-config.php`, next to `index.php`. It lists only the settings you've changed from the defaults.

**The easy way is the admin:** open **Site settings** (in the left sidebar) and flip the switches. See [Site settings in the admin](#site-settings-in-the-admin) below. It creates and updates the file for you.

You can also write it by hand. It's the same kind of file as the gallery's other data files: a one-line guard (so a direct request answers 404) followed by plain JSON. Nothing in it is ever run as code. A ready-to-edit copy, `gallery-config.example.php`, is in this repository.

```
<?php http_response_code(404); exit; ?>
{
    "ENABLE_VIDEO": true,
    "AUTO_GROUP_BY_FOLDER": true,
    "ADMIN_PASSWORD": "a-long-random-string"   // a note to yourself
}
```

- **It overrides the defaults in `index.php`, and everything it doesn't mention keeps its default.** So a newer `index.php` can improve a default and you get that improvement for everything you haven't pinned.
- **It stays when you update.** Replace `index.php` as often as you like; the file isn't touched. This is tested by swapping in a fresh copy.
- **Hand-editing is forgiving.** Text after `//` on a line is ignored, so you can leave notes (a `//` inside a password or path is just text), and a comma after the last entry is fine. Names are exactly as in the [options table](#what-affects-what) (`ENABLE_VIDEO`, `TRACK_VIEWS`, …); `true` and `false` have no quotes, text does.
- **Mistakes never break the site.** A misspelt name, a value of the wrong kind (`"yes"` for a true/false setting), a value that isn't one of the allowed choices, a file that isn't valid JSON: each is ignored, and the setup-notices banner says which, so you notice. The gallery carries on with the defaults.
- **It is private.** A direct request for it answers 404, and because its name starts with `.gallery-` the [lock-down rules for the data files](#locking-down-the-files-themselves) cover it too. Passwords in it are kept as [hashes](#passwords-are-stored-as-hashes), not as the words you typed. It is still the file that decides who gets in, so keep it out of git (this repository's `.gitignore` lists it, and [so should yours](#keeping-runtime-data-out-of-git)).
- **`GALLERY_VERSION` and the cookie name can't be set from it**, since they belong to the code.

A setting in the file beats the same setting edited into `index.php`.

### Site settings in the admin

The sidebar's **Site settings** shows every setting that is safe to change from a web page, as switches and choices, each with a one-line explanation and its default. **There is no Save button: each change saves the moment you make it**, with a small "Saved." beside it (a browser without JavaScript gets a Save button instead):

- **What is shown:** subfolders, folder style, videos, grouping by filename, photo order.
- **Thumbnails and counting:** thumbnails, counting views, showing counts to visitors.
- **Locations and the map:** allowing photo locations, the map, how close photos must be to share a pin.
- **Passwords:** change the admin password (typed twice, at least 8 characters with a letter and a number; you stay signed in) and set or remove the private-view password, each with its own button beside the fields. Neither password is ever shown back to you.

How it behaves:

- **It only ever writes `.gallery-config.php`.** Your photos, captions, locations, privacy flags, notes, folder settings and view counts are never read or changed by it, and switching a setting *off* only hides things: the data stays and comes back when you switch it on again. (This is tested by comparing every file in the gallery before and after a save, and by switching settings off and on around real captions, GPS data and view counts. The save itself was also run end to end in a real browser and on nginx 1.26 and Apache 2.4 with PHP-FPM, where the page after saving shows the new value immediately.)
- **Only settings that differ from the defaults are stored**, so a better default in a later release still reaches you for everything you haven't changed. Put a setting back to its default and it leaves the file.
- **Warnings are written on the page, not asked in dialogs.** A setting that publishes content or contacts a third party (subfolders, videos, photo locations, the map, an extra folder) has its warning in orange under it, in plain words, before you touch it. Flipping it saves at once. Switching one **off** shows an orange note beside the switch saying what that does and that nothing is deleted: the folders, files, captions and locations stay and come back when you switch it on again. Nothing in Site settings asks you to confirm.
- **Changes are saved one at a time and built on each other**, so quick successive changes can't overwrite one another. The other screens (the sidebar's folder list, photo counts) are refreshed when you leave Site settings after a change, so they never show stale information. A change that can't be saved (an invalid value, an unwritable folder, an expired session, or the file changed elsewhere) puts the switch back and says why next to it.
- **The server-level settings are editable too:** places elsewhere on the server (a path alone adds its folders at the top of the gallery; with a name it is shown as one folder; each path is checked to be a real, readable folder before it is stored) and `NGINX_ACCEL_REDIRECT_PREFIX` (a warning says it needs matching nginx config). A save keeps any other key you added by hand exactly as it was.
- **It won't overwrite what it can't read.** If the settings file isn't valid, saving is refused until you fix or delete it. If the file was changed since the page loaded (another tab, or by hand), the save is refused rather than overwriting that change. The new file is written to a temporary name, read back to check it holds exactly what was intended, and only then moved into place.
- **If the gallery's folder isn't writable** it says so, changes nothing, and shows you the exact text to put in `.gallery-config.php` yourself.
- **Saving rewrites the file**, so any `//` notes you added by hand are not kept.

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
| `{color: #5865f2}` or `{color: gold}` | Custom accent color for that group's gallery box (and a bar of the same colour beside the paragraph's own text; with no photo or folder to colour, the bar is all it does) |
| `{sold}` | Marks the item sold — dimmed/struck-through text, grayscaled photos, red ribbon |
| `{reserved}` / `{held}` | Marks the item reserved — orange tag/ribbon, photos stay full-color |
| `{group: Name}` | Names a group's gallery box, or renames an auto-detected one |
| `{left}` / `{center}` / `{right}` | Aligns that paragraph's text (or a heading's, written at the end of its line). Notes are left-aligned by default |
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

In the default `'overview'` mode, each level shows **one cover card per subfolder** (a highlight photo, the name, and a photo count that includes everything beneath it) plus any photos loose at that level. Clicking a card opens `?folder=Europe/France`, which does the same one level down; a breadcrumb links back through every ancestor. With `'inline'`, a folder's photos just appear in a box on the page you're on, which suits a seller who files each item in its own folder but wants everything visible at once. A page with nothing on it but one to four folders shows **larger cover cards on a wide screen** (two folders are two big cards, not two small tiles); the map buttons above them line up with the first card, and their pictures are squares cut from the original photo (not from a thumbnail), so they stay sharp; phones and narrow windows keep the normal grid and the small picture.

A few details worth knowing:

- **Per-folder settings** — a display name, a short description, a date-range subtitle (typed by you, though the admin offers the span of the folder's photos, from their capture dates or else their file dates, as a suggestion with a one-click button: nothing is shown to visitors unless you save it, because dates can reveal when you were away), a chosen cover photo, and a position among sibling folders — are set in the admin editor. The name, dates and cover photo appear on the cover card; the description appears in a centred box at the top of the folder's own page (with the notes' small Markdown, and `{left}`, `{center}` or `{right}` to align a paragraph) (and in shared-link previews), not on the card, which would crowd it. Folders with no position sort naturally after any numbered ones.
- **A folder can carry its own notes.** A `{gallery}`-marked `.md` inside a subfolder becomes a note box on that folder's page, scoped to that folder's photos.
- **Notes work the same in every folder.** A notes file affects the page it sits on, and its `{photos:}`, `{group:}`, `{color:}`, `{sold}` and `{reserved}` apply to the photos **directly in its own folder**, spelled as in that folder. Write a subfolder's name as a whole word in a paragraph and `{sold}`, `{reserved}` and `{color:}` style that folder's cover card (in `inline` mode its box, with its photos shown under the paragraph); `{group: Name}` there renames it on that page. A top-level `{photos:}` cannot reach a photo inside a folder: a setup notice says so, rather than reporting an "unknown file". Mentioning a photo's name (`eiffel` for `eiffel.jpg`) works in any folder's notes.
- **Everything about a folder lives in that folder.** Stats, the thumbnail cache, and the admin's saved data sit inside the folder they describe, so two cameras' `IMG_0001.jpg` in different folders never collide.
- **Renaming a folder keeps its data; making a new folder and moving photos into it does not.** A real rename (FTP client, file manager, `mv`) carries the folder's data file along. Creating a *new* folder under a different name is, from the gallery's point of view, a different, empty folder.
- **Symlinked folders are refused**, so a link can't make the gallery list files outside itself. To include a folder from elsewhere (an archive on another disk), add it under *Site settings → Folders from elsewhere* (or `EXTRA_ROOTS` / `EXTRA_FOLDERS`) instead.
- If photos exist in subfolders but `AUTO_GROUP_BY_FOLDER` is off, a setup notice says so (a count only, never a name; the search is capped at 40 folders so a gallery dropped into a big existing site never walks all of it).
- Sibling folders order by an admin-set position first, then naturally by name (Day 2 before Day 10, case-insensitive) — or by the folder's own modification time if `PHOTO_SORT_ORDER` is `'mtime'`.
- **The viewer stays in the folder.** Opening a photo and pressing the arrows cycles through that folder's own photos (and counts them), never into a neighbouring folder.
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
3. A **per-photo** switch, *Show its location*: it follows the folder by default; switch it on to show a landmark in a folder that otherwise shares no locations, or off to keep a front door private inside a folder that does (off sticks, even if the folder's switch is turned on later).

The admin always shows and stores location data for you regardless of these; they control only what **visitors who aren't signed in** are shown. **You, and anyone you gave the private-view password, see every saved location** on the gallery and on the maps, whatever the folder and photo switches say (sign out with the open lock to see what the public sees); only `ENABLE_PHOTO_LOCATION` switches the whole feature off for everybody. Coordinates come from EXIF for JPEG and HEIC photos that have GPS data (PNG, GIF, WEBP and BMP generally don't — enter those by hand), and are validated as real numbers before they ever reach a link. **Turning a folder's switch on is what publishes them:** it asks first, then stores the GPS of every photo in that folder, so the places appear right away. To keep a photo out, switch off its *Show its location* (or clear its coordinates and Save); a photo you have saved yourself is never overwritten by GPS from the file, and a cleared location stays cleared. Photos added to the folder later are picked up when you next open it in the admin (never on a visitor's request). Turning the switch off hides every location again and keeps what was stored. There is no place-name lookup: that needs a third-party service and a key. Paste coordinates from any map tool.

> **Heads-up: the original files keep their GPS, and what a visitor is sent is cleaned only where the gallery hides the location.** The full-size picture in the viewer is your original file, so without care a visitor could read its embedded GPS even with every gate above closed. The gallery therefore blanks the location out of what it sends a visitor who isn't signed in, for any photo or video whose location it doesn't show (see [Downloads and locations](#downloads-and-locations)). The files on your server are never changed, and someone signed in (you, or with the private-view password) is sent the file as it is. Not covered: some cameras' private maker data, and GIF/BMP files. If that matters, strip location metadata from the photos before uploading them.

## The admin editor

### First-run setup

A new gallery has no admin password, so `?admin` doesn't exist (it answers 404) — except right after you upload: for **five minutes after `index.php` was last uploaded**, `yoursite.com/your-folder/?admin` shows a one-time "Set up your gallery" page. Type a password twice (at least 8 characters, with a letter and a number), and you're signed in, on Site settings. The password is saved in `.gallery-config.php`, and the page never appears again once a password exists.

- **Do it right away.** Whoever opens that page first in the five minutes sets the password. That's why the window is short, why nothing on the public page mentions it, and why it needs HTTPS (or `localhost`) like the admin itself. If several people try at the same moment, exactly one wins and the rest are shown the login form (tested with six simultaneous attempts on nginx and Apache).
- **Came back later?** Re-uploading `index.php` reopens the five minutes, or create an empty file named `.gallery-setup` next to it (that proves you can write to the folder) and reload; the file is removed once the password is set.
- **Prefer not to use it?** Put `"ADMIN_PASSWORD"` in a [settings file](#keeping-your-settings-across-updates) yourself and the setup page never exists.
- **It only ever adds the password.** Any settings already in `.gallery-config.php` are kept, and if that file is unreadable or invalid the page refuses rather than overwrite it. If the folder isn't writable it shows you the exact file to create by hand.

Choose an admin password ([First-run setup](#first-run-setup), a long random string is the right choice — it's yours alone) and open the admin: press the small **lock** beside the gallery's title and type the password (on a folder's page you land on that folder's admin), or go to `?admin`. The lock is the one sign-in for the whole gallery: the **admin password** opens the admin, the **private-view password** shows the private items, and the box tells them apart by what you type. You get a plain one-field password form (no username, no browser pop-up), and stay signed in for the session. The lock shows whenever there is an admin password, so a visitor can see there is a way in; if that matters to you, know that the form is throttled (five wrong guesses lock that address out for a minute) and the admin is HTTPS-only. With no password set (and the first-run window closed) the admin doesn't exist: `?admin` returns 404, and there is no lock. Signed in as the owner, the **gallery's title becomes the way back to the admin** for wherever you are (on a folder's page it opens that folder's admin); the open lock signs you out. You get:

- **Tabs** in a sticky bar — **Photos**, **Folder settings**, **Notes**, **Export** — showing one section at a time, with the **folders as a tree** in a sidebar (a collapsed bar on a phone), so even a big gallery stays navigable. If anything is private, a red **Private** entry appears at the top of the sidebar, above Root (see below)
- every photo and video in the current folder as a **grid of thumbnails**; select one and the editor panel above the grid opens with its **Private** and **Show its location** switches (they save as you flip them) and its caption, web link and location fields (Prev / Next step through the folder, and a one-click **Set as cover** for the folder). **Select photos** turns on a selection mode for making several photos private, or public again, in one go. Badges on the thumbnails show which have captions, locations or are private
- the folder's own settings: display name, date range, description, position, whether its photo locations show publicly, and whether the whole folder is private. **The gallery itself has the same form** (title, dates, description, and a cover for shared links) on the root's Settings tab, so you never need a notes file just to name it — a notes file is for richer text, lists and `{sold}` items
- every `.md` in the folder — including ones missing the `{gallery}` marker, tagged as not active, with a one-click **Add `{gallery}` marker** — edited as **raw text, saved byte-for-byte** (never parsed and re-written). **Save**, **Save As** and **Delete** are separate actions (Save As and Delete ask you to confirm), and the destination is locked to a `.md` name so nothing typed there can reach `index.php`. A reference panel beside the editor explains each directive and inserts its syntax at the cursor (it starts collapsed on a phone)
- **Two tabs, one record:** every item and folder form remembers what it loaded, and a save whose record was changed elsewhere in the meantime (another tab or device) is refused with an explanation instead of silently overwriting. Editing a *different* photo in the same folder never conflicts
- **Export as JSON** for the folder or the whole tree — the only backup of the captions and settings you typed, which a plain save can't undo
- the red **Private** entry, when anything is private: a plain explanation that private isn't a lock, then every private folder and photo across the whole tree, each linking straight to where you can change it
- **Site settings** (sidebar): the gallery-wide switches and the two passwords, saved in `.gallery-config.php` so they survive updates (see [Site settings in the admin](#site-settings-in-the-admin))

How it's locked down: the password is checked against a stored [hash](#passwords-are-stored-as-hashes); the page **refuses to load over plain HTTP** (except on `localhost`/`127.0.0.1`) and explains why, since the password would otherwise travel in the clear; logging in starts a fresh session (a session id issued earlier can never be a logged-in one), which lasts up to 4 hours and ends when you press **Log out** (top right of the admin) — that also clears the Private view cookie, and changing `ADMIN_PASSWORD` signs every existing session out; a CSRF token protects every save; and a failed-login throttle (5 *wrong* passwords from one address within 15 minutes imposes a 60-second delay before that address's next attempt is even checked; just opening the form doesn't count). If you were signed out and submit a change, it isn't saved and the form tells you why. A script can still send an HTTP `Authorization: Basic` header (any username, the password as the password) instead of using the form. The admin works at a phone's width.

**The admin password is kept in the settings file (`.gallery-config.php`)** as a [hash](#passwords-are-stored-as-hashes) — or in `index.php` itself if you put it there. It is still worth keeping out of what you paste or share for support.

**The admin's data files are protected by default — and worth a second layer.** Everything the admin writes lives in a `.gallery-items.php` inside each folder (and the stats, login-throttle and secret files are `.gallery-stats.php`, `.gallery-login-attempts.php` and `.gallery-secret.php`). They hold your captions, any GPS coordinates you entered, and the exact filenames of everything you've hidden. A web server serves any file in the folder to anyone who asks for its URL, so these are stored as PHP files that begin with a one-line guard: a direct request runs that line, which answers **404 and stops**, so none of the JSON below it is ever sent — on any host that runs PHP, with no server setting needed. The gallery itself never runs these files; it reads them as plain text and skips the first line. Captions are written with `<` and `>` escaped, so a caption can't smuggle PHP into the file.

The one stats file that can't carry that guard is the optional `.gallery-stats.sqlite` — a binary SQLite database can't start with a PHP line. It holds only counts, keyed by a SHA-256 hash of each photo's path, so a visitor who fetches it learns no filenames (hidden items are counted like any other, and their names still never appear). It's still worth adding the server rule in [Locking down the files themselves](#locking-down-the-files-themselves) if you can: it's a second layer over all of these files, and it keeps even those hashed counts private.

### Passwords are stored as hashes

The admin password and the private-view password are kept in `.gallery-config.php` as **bcrypt hashes** (PHP's `password_hash`), not as the words you typed. If someone gets a copy of that file (a backup, a zip, a repository, a server that sends `.php` files as text), they don't get your password, only something they would have to guess at, one try at a time and slowly. A hash is not a password: typing it into the sign-in does nothing.

- **New passwords** (first-run setup, Site settings) are hashed before they are saved.
- **An older file, or one you edited by hand, with a password in plain text still works.** The first successful sign-in through the lock or `?admin` replaces every plain-text password in the file with its hash. Everyone signs in once more afterwards: the cookies depend on the stored value. If the gallery's folder can't be written the plain text stays and keeps working.
- **Writing one yourself:** `php -r 'echo password_hash("the password", PASSWORD_DEFAULT), "\n";'` and paste the result as the value. It is accepted as it is.
- **The sign-in cookies are keyed with a secret of this install**, kept in its own `.gallery-secret.php` (guarded like the other data files, created at the first sign-in). So the hashes in the settings file are not enough to make a valid cookie either. Deleting that file signs everyone out; it is made again at the next sign-in.
- **A forgotten password can't be read back, only replaced:** delete the `ADMIN_PASSWORD` line from `.gallery-config.php`, create an empty file named `.gallery-setup` next to `index.php`, and open `?admin` to [choose a new one](#first-run-setup).
- **What it does not do:** it doesn't protect the photos or captions, and a weak password (short, a dictionary word) can still be guessed from its hash. Pick a long one. The two passwords are checked with a deliberately slow function, a fraction of a second each, once per sign-in.

### Downloads and locations

The photo viewer's download button (`?original=<file>`) sends the original file as a download. Who gets it, and what they are sent:

| Visitor | Download button | The file |
|---|---|---|
| The owner (signed in to the admin) | always | exactly as it is on the server |
| Someone with the private-view password | always | exactly as it is on the server |
| Anyone else | only if `ENABLE_PUBLIC_DOWNLOAD` is on (**off** by default) | a photo or video whose location the gallery **doesn't show** has its location blanked out |

The same rule holds for the picture the viewer itself shows (it is the original file, `?img=…&size=full`) and for a video as it plays, including when a player asks for a part of it: a visitor who isn't signed in never receives the coordinates of a photo or video whose location is hidden. "Hidden" means the gallery wouldn't show that location: the location switch is off for the folder or the site, or you set that photo to hide it. A photo whose location is shown is sent as it is. Private photos and videos stay private: they are still a 404 for anyone who isn't signed in.

**How the location is removed.** The file is not re-encoded, so there is no loss of quality and its size doesn't change: the bytes that hold the location are overwritten in the copy that is sent, and the file on your server is never touched. Blanked: the EXIF GPS block (JPEG, HEIC, PNG, WebP), the GPS and place-name properties in XMP, the city, sub-location, state and country fields in IPTC, and a video's location atom (the one iPhones and Android phones write). Everything else (date taken, camera, caption) stays. The small pictures (thumbnails, share previews) are re-encoded by the gallery and never carry a location.

**What it can't promise.** A camera's private "maker notes" can hold extra data this doesn't read; GIF and BMP files are sent as they are (they have no standard place for a location); and text you wrote yourself, such as a place in a caption or a folder name, is not a location field. A damaged file whose metadata can't be read safely is **not sent as an original at all**: the download answers 422 with the reason, the viewer shows the re-encoded copy instead, and a video isn't played, rather than any of them being sent with the location in it.

**Cost.** Photos that need no cleaning are sent as before (and still by your web server directly if you set `NGINX_ACCEL_REDIRECT_PREFIX`). Only a hidden-location file for a visitor who isn't signed in goes through PHP, and those responses are kept out of shared caches, because the same address gives different bytes to different people.

## Private items and the private view

For the photos or clips that shouldn't be on the public link — people who didn't agree to be, say — but that you still want trusted viewers to see in the same gallery:

1. In the admin, make an **item** private (its **Private** switch, or several at once with **Select photos**), or an entire **folder** (at any depth). A private folder covers everything beneath it; an individual item can be switched back to *public* inside a private folder, or made *private* inside a public one.
2. Set `PRIVATE_VIEW_PASSWORD`. A small **lock** (a line drawing of a closed lock) sits beside the gallery's title whenever there is something to unlock: the admin password exists, or the private-view password exists and something is private. It leads to `?reveal`, a one-field form that accepts either password (the private-view one shows the private items; the admin one opens the admin); on success it sets a cookie and returns to the plain gallery link (or the folder you were on), so you can bookmark the bare URL, and the lock shows **open**. Pressing the open lock locks the private items again in that browser (it forgets the cookie). The owner, signed in to the admin, sees the lock open too, and pressing it signs the owner out as well (the admin's own Log out does the same). It is a **password, not a secret link** — links pasted into Discord or Reddit get crawled by preview bots and can't be revoked without breaking them for everyone. A valid admin login counts as revealed too.

A private item is removed from the page outright, not merely styled invisible: it's absent from the grid, notes, the lightbox's list, every count and badge (a count that didn't match what's visible would itself be a tell), the map, and link previews — and `?img=`, `?photo=`, `?track=`, `?folder=` and the video endpoint all refuse it to an unrevealed visitor. A group whose members are all private disappears rather than showing "0 photos". The same HTTPS requirement and failed-login throttle as the admin apply, with separate lockout state. Viewers stay unlocked for a year; the cookie is specific to each copy of the gallery (it's named and signed from the gallery's own folder), so several galleries on one site never interfere.

### Locking down the files themselves

Everything above governs requests that go through `index.php`. **A photo is still an ordinary static file** that the web server will serve directly if someone knows or guesses its URL (`yoursite.com/kb/IMG_0412.jpg`) — and if filenames run in sequence, a gap shows where something is missing. Making something private keeps it out of every page, but only a web-server rule stops the direct fetch. The admin shows a persistent warning whenever anything is hidden, saying exactly this.

**nginx** — strongest, but needs shell access to the server. Edit the site's config and add, inside the `server { }` block, two locations scoped to the gallery's path (this example assumes `https://yoursite.com/kb/`; a second gallery needs its own pair). Keep the first one above the second, since nginx takes the first matching regex location:

```nginx
# The gallery's generated data files (captions, GPS, privacy flags, stats,
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

Both blocks were tested against nginx 1.26 with php-fpm 8.4: every bare photo, video and data-file URL (including a private item, a nested one, and an upper-case extension) returns 404; the gallery, thumbnails, full-size images and video `Range` requests (a real `206`) all work through `index.php`; a private item stays unreachable by URL even for a viewer who has entered the private-view password; and `?admin`/`?reveal` Basic Auth passes through php-fpm correctly. They assume your site already has the ordinary pieces a PHP site on nginx has — `index index.php;` and a `location ~ \.php$` that hands scripts to php-fpm.

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

## The maps

Set `ENABLE_MAP` (and `ENABLE_PHOTO_LOCATION`, plus a folder or photo that actually has a visible location) and two maps become available. Neither is in the way: **the page map starts closed**, behind a small *Show map* button (its choice is remembered per browser), and **the little map in the photo viewer** shows only for a photo that has a visible location.

- **The page map.** The gallery front page offers a *Show map* button above the folders; pressing it loads the map, which plots every visible, located photo from every folder together, and a *View full map* button beside it opens a large map over the page (click outside it, press Escape or use the cross to close it), and the address bar follows it (`?map`), so copying the address (or the overlay's *Copy link* button) shares the map, and the browser's Back button closes it. Nothing from the map's third parties is requested until a visitor presses it.
- **The viewer map.** In the full-screen viewer a photo with a visible location shows a small map in the top left corner of the photo, about half a mile across. Click it to enlarge it (move, zoom, *Open in OpenStreetMap*); the pin button beside the caption turns it off and on, and the choice is remembered. It is on by default, because it gives the photo real context, but it only exists when `ENABLE_MAP` is on, and the map library is requested only when such a photo is opened.

**Each folder can have its own map too**, with its own settings. In a folder's settings (and the gallery's, on the Settings tab) the **Map** section has:

- **Offer a map on this page.** The gallery front page offers its map unless you switch that off; a folder starts with none, because switching one on makes that folder's page load a third-party map. A folder's map shows only the photos inside it and its subfolders, with their own colours per subfolder, and has its own shareable page at `?map&folder=Paris`. A folder with its map switched off has no map page (404).
- **Pin grouping (miles).** How close photos must be to share a pin on *this* page's map. Leave it blank to use the site-wide `MAP_CLUSTER_RADIUS_MILES` (Site settings); a city break wants a small number (fractions such as `0.1` are fine), a very dense city `0`, which turns grouping off so every photo is its own pin, and a road trip a large one.
- The usual rules still apply on every map: a photo must have a visible location (its folder's location switch on, and not switched off for that photo), and a private photo or folder is never plotted for a visitor (the admin, who sees private things, sees them on the map too).

- **What grouping does.** Photos within `MAP_CLUSTER_RADIUS_MILES` (default 1 mile) of each other share one numbered pin, so a dense stretch doesn't turn into a wall of markers; pressing a numbered pin zooms in (as far as street level) and splits it into its photos' own pins. A dense city trip and a cross-country drive want very different radii, so it's a setting, per page, and **`0` turns grouping off** (every photo its own pin) for places where photos are taken only metres apart.
- Pins are colored by top-level folder, with a legend. A note can override that: a folder or group given `{color: gold}` has gold pins, and `{sold}` / `{reserved}` give red / orange ones.
- Clicking a pin opens a small popup (thumbnail, label, link) — it doesn't navigate on first click; the link inside does, to that group's page.
- Private items are never plotted. A `?map` link has its own link preview, with a generic map-pin image (a rendered snapshot of the map would need a third-party static-maps service).

**This is the one feature that contacts a third party.** It loads Leaflet from cdnjs and map tiles from OpenStreetMap, which is why it's off by default: with it on, a visitor's browser makes those requests once they open a map (the page map's button, or a photo that has a location).

## Sharing and previews

Every link you're likely to paste gets a correct Open Graph preview: `?photo=`, `?folder=` (the folder's display name, description or notes, and cover photo) and `?map`. A private item or folder is treated as nonexistent for an unrevealed requester — including a link-preview crawler, which has no cookie — so pasting its URL anywhere never generates a preview of it.

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

If you keep your deployed gallery in git, add this to `.gitignore`: these files are generated or private, not source — `.gallery-items.php` can contain GPS coordinates, and `.gallery-config.php` holds your passwords, so neither should be committed by accident.

```
.gallery-cache/
.gallery-items.php
.gallery-config.php
.gallery-stats.*
.gallery-login-attempts.php
.gallery-secret.php
```

`.gallery-items.php` (the captions, locations and settings you typed), your settings file `.gallery-config.php` and your hand-written `.md` files are the only things here that can't be regenerated — use the admin's JSON export for the captions, and your host's own backups for the rest.

## Upgrading to 2.0

Dropping the new `index.php` over a 1.x copy leaves your gallery looking the same, with three things worth knowing:

- **`?download` now redirects to this repository instead of serving the script.** Anything that fetched the script from `?download` on your gallery should get it from GitHub instead. (Serving it directly would have handed `ADMIN_PASSWORD` to any visitor once the admin existed.)
- **Your view counts carry over.** 1.x kept them in `.gallery-stats.json` (or a SQLite file with readable filenames). On the first request with `TRACK_VIEWS` on, 2.0 imports the per-photo counts and the sitewide total into its new stores, then deletes the old JSON file (or drops the old SQLite table and compacts the file, so the old filenames don't linger in it). If the folder isn't writable the old data is simply left where it was.
- **You may see one new setup notice.** If your folder has subfolders containing photos and `AUTO_GROUP_BY_FOLDER` is off, the collapsed "setup notices" banner now says how many photos in subfolders aren't being shown (a count, never a name). Turn the setting on to show them, or ignore it — it's addressed to you, not your visitors.

Everything else is opt-in. Copy your own settings across by editing the constants in the new file, or — better, so you never have to again — put them in a [settings file](#keeping-your-settings-across-updates). See [CHANGELOG.md](CHANGELOG.md) for the full list.

## Security notes

What the script does to protect you, and the limits worth knowing.

**What it protects, by default**
- **Everything it writes is private.** The generated data files (captions, locations, privacy flags, stats, login throttle) answer 404 if requested by URL, on any host that runs PHP — see [The admin editor](#the-admin-editor). Output is HTML-escaped everywhere, captions are written with `<`/`>` escaped in those files, and a request can only ever *select* among files the script found itself, never name a path (`..`, symlinks, wrong-case spellings and NUL bytes are all refused).
- **Logins:** a password form (no browser pop-up) with the password compared in constant time and a fresh session id on login; the admin and private view refuse to load over plain HTTP (except from `localhost`); a failed-login throttle; CSRF tokens on every admin write; a stale-edit check; and an admin session cookie that is `HttpOnly`, `SameSite=Strict`, uniquely named (it won't collide with another PHP app's `PHPSESSID` on the same domain) and rejects a session id the server never issued.
- **Admin and private-view pages** are sent with `Cache-Control: no-store`, `X-Frame-Options: DENY` and a policy that stops them being framed or their forms being posted elsewhere. Every response carries `X-Content-Type-Options: nosniff`.
- **Private items stay out of caches.** A private photo or video, even when served to a trusted viewer, is `Cache-Control: private, no-store`, and a page rendered for a viewer is too — so a CDN or shared proxy in front of the gallery can't keep one and hand it to the next visitor. Ordinary photos stay cacheable.
- **The Host header** is used in absolute URLs (link previews) only if it looks like a real host name.

**Limits, stated plainly**
- **A web server hands out any file it can see.** Making something private keeps it out of every page, but the files themselves still answer to their exact address unless you add the [server rules](#locking-down-the-files-themselves).
- **Original photos keep their embedded GPS** (see [Captions and locations](#captions-and-locations)), except in the copy sent to a visitor who isn't signed in when you hide that photo's location ([Downloads and locations](#downloads-and-locations)).
- **`ADMIN_PASSWORD` and `PRIVATE_VIEW_PASSWORD` live in the settings file** (or in `index.php`, if you put them there), [as hashes](#passwords-are-stored-as-hashes). A hash can't be typed in as the password, but a short or common password can still be guessed from one, so pick long ones and keep the file out of backups and repositories you share.
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
