# Gallery

A single-file, drop-in PHP photo gallery. No dependencies, no build step, no database to set up: put `index.php` in a folder of photos and it shows a gallery for that folder.

Made for sharing "for sale" listings (Reddit, Discord…) as much as for plain galleries, with grouping by filename or by hand, a small Markdown notes box with sold/reserved markers, opt-in view stats and links that preview properly when shared. Version 2 also does **trips**: nested folders, video, captions and locations, an owner-only admin, private items, maps and sorting, sharp thumbnails and a full-screen viewer built for phones as much as desktops.

**Everything beyond the basic gallery is opt-in.** With nothing changed it writes only small thumbnails to a `.gallery-cache` folder, contacts no third party, and shows no subfolder, video or photo location. (It peeks into subfolders, read-only and capped at 40, only to warn you if photos there are being skipped.)

**Contents:** [Quick start](#quick-start) · [Recipes](#recipes) · [Settings](#settings) · [Notes and directives](#notes-and-directives) · [Folders](#folders) · [Video](#video) · [Captions and locations](#captions-and-locations) · [Photo order](#photo-order) · [The viewer](#the-viewer) · [Thumbnails and phones](#thumbnails-and-phones) · [The admin](#the-admin) · [Private items](#private-items) · [Downloads](#downloads) · [Locking down the files](#locking-down-the-files) · [Maps](#maps) · [Sharing](#sharing-and-previews) · [Troubleshooting](#troubleshooting) · [Security](#security-notes) · [Upgrading](#upgrading-and-backups)

## Quick start

```
your-folder/
  index.php     <- this file
  photo1.jpg
  forsale.md    <- optional notes
```

**PHP 8.3 or newer** (tested on 8.3 and 8.4); older versions get a plain message instead of a failure. No extension is required. Optional:
- **Imagick** (with HEIC) or **GD**: thumbnails (on by default). Without either, or if the folder can't be written, originals are sent. HEIC (iPhone) photos need Imagick.
- **EXIF**: reading GPS and capture dates from JPEGs.
- **pdo_sqlite**: view-count storage when `TRACK_VIEWS` is on (else a JSON file).
- **ffmpeg** (a program, not a PHP extension): real video thumbnails, hover clips and scrubbing.

**A `.md` file counts only if its first line is exactly `{gallery}`.** Any other file is never read.

## Recipes

Settings are switched in the admin's **Site settings**, or in a [settings file](#the-settings-file). To open the admin the first time, visit `yoursite.com/your-folder/?admin` **within five minutes of uploading** `index.php` and choose a password ([first run](#first-run-and-signing-in)); it needs HTTPS (or `localhost`).

| I want to… | Recipe |
|---|---|
| Show a folder of photos | [Plain gallery](#plain-gallery) |
| Post a "for sale" listing | [For-sale listing](#for-sale-listing) |
| Share a trip with captions, places, video, a map | [Trip album](#trip-album) |
| Keep some family photos private | [Private photos](#private-photos) |
| Include an archive from another disk | [Archive elsewhere](#archive-elsewhere) |
| Show iPhone (HEIC) photos | [iPhone photos](#iphone-photos) |

### Plain gallery
Copy `index.php` into the folder. Visitors see every photo in it (not its subfolders) in filename order, with a full-screen viewer (arrows, swipe). `?photo=name.jpg` opens one photo and previews correctly when pasted into Discord or Reddit. Until you ask, nothing is written but thumbnails. If subfolders hold photos, or videos exist while video is off, a collapsed owner-only notice gives a count.

### For-sale listing
Settings: `AUTO_GROUP_BY_FILENAME` on; optionally `TRACK_VIEWS` on (and `SHOW_VIEW_COUNTS_PUBLICLY` off to keep the numbers to yourself).
1. Copy [`examples/example-forsale.md`](examples/example-forsale.md) into the folder as `forsale.md`.
2. Name photos after each item (`alpha_01.jpg`, `alpha_02.jpg`) and write that **same word** in the item's paragraph (`Widget **Alpha** — brand new`). It must be the whole filename prefix.
3. Add `{sold}`, `{reserved}` and `{color: #5865f2}` anywhere in a paragraph.
4. Photos named `IMG_0234.jpg`? List them: `{group: Widget Alpha} {photos: IMG_0234.jpg, IMG_0891.jpg}`.

Visitors see each item's text with its photos beneath ("View 2 photos"), a box per multi-photo item, a red SOLD ribbon with greyed photos, an orange RESERVED tag. Paste a `?photo=` link or a box's copy-link button into your post.

### Trip album
Settings: `AUTO_GROUP_BY_FOLDER`, `ENABLE_PHOTO_LOCATION`, `ENABLE_MAP` (the one feature that contacts a third party), `ENABLE_VIDEO`, and an admin password.
1. Folders like `trip/Paris/`, `trip/Lisbon/` appear as cover cards (natural name order). They nest to any depth.
2. In `?admin`, open a folder and fill in *Display name*, *Dates* (it suggests the span of the photos), *Description* and *Position*.
3. Tick **Show photo locations publicly for this folder**. This publishes the GPS of every photo there that has some (a warning says how many); a single photo opts out with its own *Show its location* switch.
4. Give photos a *Caption* and *Web link*, correct a location, and **Set as cover** to pick the folder card's picture (opening the card then scrolls to it).
5. The map appears on the front page once a photo has a visible location.

Videos show a play mark and length and play in the viewer. **Back up your captions** with *Export as JSON* in the admin.

### Private photos
Settings: an admin password and `PRIVATE_VIEW_PASSWORD`.
1. Make a photo private with its **Private** switch (several at once with **Select**), or a whole folder with **Make this entire folder private**. A photo can be switched back to public inside a private folder.
2. Give the viewer password to your people. They press the **lock** beside the gallery's title and type it once; the browser remembers for a year and the link you share never changes.

Visitors without the password see no trace of what's private. **Then lock the files themselves**: private only controls what the gallery serves ([Locking down the files](#locking-down-the-files)). The admin warns you on its Private tab until that's done.

### Archive elsewhere
**Site settings → Server setup → Folders from elsewhere**: paste a path and press *Add*. The folders inside it appear at the top of the gallery as if they lived there (`/space/trip` holding `Paris` and `StTropez` gives you both). Give the place a name to show it as one folder instead. Photos lying directly in the path aren't shown (a notice counts them). A real folder of the same name wins. Captions and settings are saved inside those folders, so PHP needs write access there. Symlinked folders are refused by design; this is the safe alternative.

### iPhone photos
Keep `ENABLE_THUMBNAIL_CACHE` on and have Imagick with HEIC. With the cache off, `.heic` files are left out; with it on they get JPEG thumbnails and are converted for the viewer.

## Settings

Changed in **Site settings** (no Save button: each change saves as you make it) or in a [settings file](#the-settings-file). Anything left alone keeps its default.

| Setting | Default | What it does |
|---|---|---|
| `AUTO_GROUP_BY_FOLDER` | off | Subfolders become albums, to any depth |
| `FOLDER_DISPLAY_MODE` | `overview` | `overview`: cover cards; `inline`: a folder's photos in a box |
| `AUTO_GROUP_BY_FILENAME` | off | `alpha_01.jpg`, `alpha_02.jpg` group as "alpha" |
| `ENABLE_VIDEO` | off | Show `.mov` / `.mp4` |
| `ENABLE_VIDEO_THUMBNAILS` | on | Real video pictures (needs ffmpeg) |
| `ENABLE_VIDEO_PREVIEWS` | on | Hover clips and scrubbing (needs ffmpeg) |
| `FFMPEG_PATH` | none | Full path, if ffmpeg is somewhere unusual (file only) |
| `ENABLE_THUMBNAIL_CACHE` | on | Thumbnails in `.gallery-cache`; off = originals, no HEIC |
| `PHOTO_SORT_ORDER` | `filename` | `filename` or `taken` (date taken, else the file's date; the older `mtime` still works) |
| `PHOTO_SORT_DESC` | off | Last first (Z to A, newest first) |
| `SORT_VIDEOS_FIRST` / `SORT_LOCATED_FIRST` / `SORT_PRIVATE_FIRST` | off | What goes first, whatever the order; they stack |
| `SORT_PRIORITY` | `private,video,located` | The order those three stack in (the admin shows them as a list you drag) |
| `SHOW_SORT_BUTTON` | on | The visitors' sort button (always left out of a listing laid out by a notes file with no folders) |
| `TRACK_VIEWS` | off | Count visits and photo views |
| `SHOW_VIEW_COUNTS_PUBLICLY` | off | Show visitors the counts (you always see them) |
| `ENABLE_PUBLIC_DOWNLOAD` | off | A download-original button for every visitor |
| `ENABLE_PHOTO_LOCATION` | off | Allow locations to show (also needs a per-folder switch) |
| `ENABLE_MAP` | off | The maps (loads Leaflet and OpenStreetMap tiles) |
| `MAP_CLUSTER_RADIUS_MILES` | 1 | Photos closer than this share a pin; 0 = no grouping |
| `EXTRA_ROOTS` / `EXTRA_FOLDERS` | none | Places elsewhere on the server (see [Archive elsewhere](#archive-elsewhere)) |
| `ADMIN_PASSWORD` | none | Turns the admin on |
| `PRIVATE_VIEW_PASSWORD` | none | Turns private view on |
| `NGINX_ACCEL_REDIRECT_PREFIX` | none | The gallery's URL path (e.g. `/kb`), once nginx has the matching rules |

### The settings file
Replacing `index.php` with a newer version would reset anything you edited into it, so settings live in **`.gallery-config.php`** beside it, listing only what you changed. Site settings creates and updates it for you. By hand it is a one-line guard followed by JSON ([`gallery-config.example.php`](gallery-config.example.php) is a copy to edit):

```
<?php http_response_code(404); exit; ?>
{
    "ENABLE_VIDEO": true,
    "AUTO_GROUP_BY_FOLDER": true   // notes after // are ignored; a trailing comma is fine
}
```

- It overrides `index.php`'s defaults and survives updates. A better default in a newer release still reaches everything you haven't set.
- **Mistakes never break the site:** a misspelt name, a value of the wrong kind or invalid JSON is ignored and named in the owner's setup notices.
- It is private: a direct request answers 404 and its `.gallery-` name is covered by the [server rules](#locking-down-the-files). Passwords in it are [hashes](#passwords). Keep it [out of git](#upgrading-and-backups).

### Site settings in the admin
Groups (**What is shown**, **Thumbnails and counting**, **Locations and the map**, **Server setup**, **Passwords**) fold open and shut. A setting that publishes something or contacts a third party shows an orange warning before you touch it; switching one off only hides things and never deletes data. Saving writes only `.gallery-config.php`, never your photos, captions, notes or counts. A change that can't be saved (invalid value, unwritable folder, expired session, file changed elsewhere) puts the switch back and says why. If the folder isn't writable it shows the exact text to create the file by hand. Saving drops any `//` notes you added.

## Notes and directives

A notes file is a short box of text above the photos. In it, directives in `{braces}` attach photos, mark items sold or colour a box. The first `# Heading` becomes the page title unless you've set a **Gallery title** in the admin. Markdown is a small subset: headers, bold, italic, underline, strikethrough, code, links, lists, quotes. The admin's Notes tab has a reference panel beside the editor.

| Directive | Effect |
|---|---|
| `{color: #5865f2}` / `{color: gold}` | Accent colour for the item's box, plus a bar beside its text |
| `{sold}` | Dimmed text, grey photos, red ribbon |
| `{reserved}` / `{held}` | Orange tag; photos stay in colour |
| `{group: Name}` | Names a group's box, or renames an automatic one |
| `{photos: a.jpg, b.jpg}` | Attaches exactly these files to the paragraph, whatever they're called |
| `{left}` `{center}` `{right}` | Aligns that paragraph (or a heading, at the end of its line) |

`{sold}` and `{reserved}` override a custom `{color:}`; sold wins over reserved. Mentioning a group's name in a paragraph also attaches its photos; a listed photo always beats automatic matching, and one file belongs to one paragraph (the first claim wins).

**A directive only ever affects its own paragraph.** A paragraph ends at a blank line, a header, a quote, a list item, a code fence, or a line starting `(1)`, `(2)`… This is the usual trap:

```
Take a look at my rack

{group: Rack} {photos: rack.jpg, rack2.jpg}
```

The blank line makes two paragraphs, so the directive has no text of its own. Remove the blank line to make one item (the setup notices flag the first form).

| In the paragraph | Box? | Combined count? | Notes-box output | Position |
|---|---|---|---|---|
| `{group:}` + `{photos:}`, no text | yes, labelled | yes | nothing (the box is the link) | natural order, by the first listed file |
| `{group:}` + `{photos:}`, with text | yes, labelled | yes | text, thumbnails, permalink | early, by where the text sits |
| `{photos:}` alone, no text | no | no | nothing | natural order, by the first listed file |
| `{photos:}` alone, with text | no | no | text, thumbnails, permalink | early, by where the text sits |
| `{group:}` alone | only if the text matches an automatic group | inherited | renames that group | n/a |

**Layout** has two zones: group boxes first, as full-width rows, then every single photo and unboxed `{photos:}` cluster as one grid, so singles always start on a fresh row. Within a zone, items with text sort by where their text sits, and the rest by [photo order](#photo-order). Listed files are never re-sorted.

**Notes work the same in every folder:** a notes file affects the page it sits on, and its `{photos:}` reach only photos directly in its own folder. Write a subfolder's name as a whole word and `{sold}`, `{reserved}`, `{color:}` style that folder's card; `{group: Name}` renames it on that page. Mentioning a photo's name (`eiffel` for `eiffel.jpg`) works in any folder's notes.

## Folders

With `AUTO_GROUP_BY_FOLDER`, subfolders work to any depth, and each becomes a group labelled with the folder's name (rename it with a `{group:}` directive or the admin's *Display name*; names are never prettified automatically).

- **`overview` mode** shows one cover card per subfolder (a highlight photo, name and a recursive count) plus photos loose at that level; a card opens `?folder=Europe/France`, with a breadcrumb back. **`inline` mode** shows a folder's photos in a box on the current page. A page with only one to four folders shows larger cards on a wide screen.
- **Per-folder settings** in the admin: display name, description, dates (typed by you; the admin only suggests them, because dates can reveal when you were away), cover photo and position among siblings. Folders without a position sort naturally after numbered ones (Day 2 before Day 10).
- **A folder can have its own notes** (a `{gallery}` `.md` inside it, scoped to its photos).
- **Everything about a folder lives in that folder**: stats, thumbnails and saved data, so two cameras' `IMG_0001.jpg` never collide. A real rename (FTP, `mv`) carries the data along; a new folder with photos moved into it starts blank.
- **Symlinked folders are refused** so a link can't expose files outside the gallery; use [Archive elsewhere](#archive-elsewhere).
- A swipeable row of pills under the breadcrumb links to the folders beside the one you're in (in the parent page's order; with more than ten, a window around the current one plus "All").
- The viewer's arrows stay inside the folder you opened, and the header shows "Last updated" from the newest file.

## Video

`ENABLE_VIDEO` makes `.mov` and `.mp4` gallery items (grouping, captions and so on work as for photos). Files are sent exactly as recorded, with seeking and a duration badge. While it is off videos are invisible, and a collapsed notice says how many were ignored.

With **ffmpeg** on the server (nothing needed otherwise; Site settings shows whether it was found):
- **A real thumbnail** from each video, taken once and kept with the photo thumbnails, used on the tile, folder cover and link preview. Without ffmpeg the tile shows the browser's own first frame where it can play the video.
- **Hover clip and scrubbing** (`ENABLE_VIDEO_PREVIEWS`). Hovering plays a silent 3-second loop (up to 640 px wide); on a desktop, moving the mouse sideways scrubs through the video, and on a phone a finger drag does, while the tile nearest the middle of the screen plays by itself once the page stops moving (never for reduced-motion or data-saver devices). Frames come from a sprite made once per video (a frame every ~0.5 s from 30 s up, 320 px squares). A click or tap still opens the viewer.
- **How it runs:** a fixed argument list, never through a shell, one at a time with a time limit; a video ffmpeg can't read isn't retried for six hours. Everything is made on first need and kept in `.gallery-cache`.

Video tiles carry a play mark and behave the same in the grid, group strips, covers and the admin. A video's **location** is read from the file (an iPhone's QuickTime item or Android's location atom) when its folder's location switch is on. Cameras that record GPS as a track (GoPro, dashcams, drones) aren't read, so add the place by hand; a visitor's copy of such a file isn't cleaned of that track.

Before turning it on: codec support isn't guaranteed (an iPhone's HEVC `.mov` plays natively on Apple devices, not everywhere), and nothing is compressed, so a long 4K clip is hundreds of megabytes.

## Captions and locations

Each photo or video can have a **caption**, a **web link** and a **location**, entered in the admin; they appear as small badges on the thumbnail and in the viewer (which never shows filenames). Entering a caption or link is its own opt-in.

**Location has three gates, all closed by default**, because GPS is the most sensitive thing here:
1. `ENABLE_PHOTO_LOCATION`: off means no badges, pins or EXIF fill-in anywhere.
2. A **per-folder** switch. **Turning it on publishes** the GPS of every photo in that folder that has some.
3. A **per-photo** switch, *Show its location*: follows the folder, on shows a landmark in a folder that shares none, off keeps a front door private (and stays off).

These gates decide what **visitors who aren't signed in** see. You, and anyone with the private-view password, see every saved location. A photo you saved yourself is never overwritten by GPS from its file, and a cleared location stays cleared. Photos added later are picked up the next time you open the folder in the admin, never on a visitor's request. There's no place-name lookup (it would need a third-party service): paste coordinates from any map tool.

> **The original files keep their GPS.** So the gallery blanks a location out of what it sends a visitor who isn't signed in, for any photo or video whose location it doesn't show ([Downloads](#downloads)). Your files on the server are never changed. Not covered: some cameras' maker notes, and GIF/BMP files. If that matters, strip metadata before uploading.

## Photo order

Inside a folder, photos run by **name** or by **date** (when it was taken: read from a JPEG or HEIC photo, or the time a video was recorded (the phone's own note of it, else the video's creation time); a file that doesn't say, such as a PNG or screenshot, uses its file date), either way round. On top of that, three things can be **put first**, and they stack: **private items** (owner and private-view only), **videos**, and **photos with a location** (counted only where that visitor can see the location, so the order never gives away a hidden one).

**Put first is one list, and you set its order.** In **Site settings** it is three rows: *Private items*, *Videos* and *Located photos*. Each row has a **switch** (is it used at all?) and a **handle**: drag a row by its handle, with a mouse or a finger, to set the order the kinds stack in (the up and down arrow keys move a focused handle). What is switched on always sits above what is off, so the list reads the way it sorts, and a sentence under it says the result: *"Videos, then photos with a location, then the rest."* A change saves as you make it. The default order is private, videos, located, so nothing re-sorts until you move a row (the setting is `SORT_PRIORITY`).

**A folder can have its own.** In a folder's settings (or the gallery's top), *Order of the photos here* has the same list under a **Site's / This folder's own** choice, like the Order and Direction rows above it. It shows the site's list and follows it until you change a switch or drag a row; then the choice moves to *This folder's own* (the section's heading says *custom*, even before you save) and *Site's* puts the site's list back. A list that would sort exactly as the site's does counts as the site's and isn't stored. Every switch off while the site puts something first **is** the folder's own ("put nothing first here"), and its sentence says what the site does. A folder with its own order always opens that section by itself.

- **You set the defaults** in Site settings and, per folder, in *Order of the photos here*.
- **Visitors change it for themselves** with the sort button (two arrows) after the map buttons, plus line-drawing toggles for videos, location and private (shown only when they apply; the button is hidden for fewer than two photos). They switch the three kinds on and off; the order they stack in is the folder's, else the site's. The choice is kept in a cookie across folders until *Use this gallery's order*. A link can carry one: `?sort=taken.desc.video` (the order, then any of `desc`, `video`, `located`, `private`).
- The viewer's next and previous follow the order. Photos placed with `{photos:}` or a group keep your order.
- Dates found in files are cached in the folder's `.gallery-cache`, so they are read only once.

## The viewer

Click a photo to open it full screen. **Arrow keys** or the on-screen arrows move through the folder (they stay inside the folder you opened), **Esc** closes, on a phone you **swipe** left and right and swipe down to close. The photo's caption, if it has one, comes with a counter ("3 / 52") and a row of buttons: **zoom**, **download** the original (for you, or everyone if you allow it), **copy link**, the photo's **web link** and, where a location is shown, the **map** pin (a small map over the photo's corner; the pin turns blue when it's on).

- **Zoom.** Click the photo (or press **Z**) to fill the width of the window, from the original file, and scroll up and down; again for real pixel size, scrolling both ways; again (or **Esc**) to fit the screen.
- **Over a zoomed photo** the counter and buttons sit on a soft dark pill, and the close button and arrows get the same backing, so they stay readable when the photo runs underneath them. Each control lights its own box when the mouse is over it (so you can tell which one you are on), and the map pin stays blue and *copied* flashes green.
- **Drawn icons.** The close button and the arrows are drawn like the rest of the icons, in identical round boxes, so they are centred exactly.
- **On a phone** the counter and buttons are one row across the **top**, with the close button last, and the photo takes everything below it down to the bottom of the visible area (a tall photo is as tall as the screen allows), on a plain black backdrop. Nothing is left to tap near the bottom edge, where Safari answers a tap by bringing its toolbar back, which costs a second tap, and nothing is laid over the photo. The viewer is sized to what is really visible, so Safari's bars never cover the photo.
- **Download** takes the photo from the browser's own memory (the viewer already holds that very file), so the share sheet can open on the first tap; HEIC photos and videos are fetched.

## Thumbnails and phones

**Sharp tiles.** A tile is a square cut from the middle of the photo, and each photo gets it at two sizes, 560 and 1080 pixels, both kept in `.gallery-cache`. The page tells the browser how wide a tile is, and the browser takes the small one for an ordinary screen and the big one for a phone (a portrait iPhone shows one column, about 350 points wide, which at 3x needs about 1080 pixels). Only tiles near the screen load, so the bigger picture costs bandwidth only for what you scroll past. The pictures are progressive JPEGs. The older 480 pixel thumbnail is still what the small strips and the admin use. With `ENABLE_THUMBNAIL_CACHE` off there is only the original.

**Prepare thumbnails.** A photo's tile pictures are otherwise made the first time it is shown, which makes the first visit to a big folder slow. **Site settings → Thumbnails** says how many photos are ready and has a button that makes the rest, a few seconds' worth at a time with a progress bar, one reading of each photo for both sizes. Stop whenever you like; what's done stays done, and a damaged file is counted and passed over. (Videos are left to the first time they are shown.)

**The viewer on a phone** is described under [The viewer](#the-viewer).

**Opening the gallery with no browser bars.** A web page can't hide an iPhone's address bar and toolbar, but a site added to the Home Screen opens without them. While you are signed in as the owner the gallery offers that (**Share → Add to Home Screen**; it needs the manifest at `?manifest` and the icon at `?appicon`, both public and harmless). Other visitors' pages don't carry it yet. You may need to sign in once inside the app.

## The admin

### First run and signing in
A new gallery has no admin password, so `?admin` answers 404, except for **five minutes after `index.php` was last uploaded**: it then shows a one-time "Set up your gallery" page. Choose a password (8+ characters with a letter and a number) and you're signed in. Whoever opens that page first sets the password, hence the short window and the HTTPS requirement; if several try at once exactly one wins. To reopen it, re-upload `index.php` or create an empty `.gallery-setup` file beside it. To skip it, put `ADMIN_PASSWORD` in the settings file.

After that, press the **lock** beside the gallery's title (or visit `?admin`) and type the password. The lock is the one sign-in: the admin password opens the admin, the private-view password shows private items. Signed in as the owner, the gallery's title becomes the way back to the admin (on a folder's page, that folder's admin) and the open lock signs you out.

The sign-in box is deliberately **not a password field**, so browsers and password managers don't offer to fill or save it: a text box drawn as dots, named `access_code`, read-only until tapped, on a bare page with a large lock drawing (which goes back) and no heading, button or word "password". Return sends it. The admin refuses plain HTTP (except on `localhost`), uses a fresh session on login (up to 4 hours; Log out also clears private view), protects every save with a CSRF token and throttles guesses (5 wrong from one address in 15 minutes means a 60-second delay). Scripts may send an `Authorization: Basic` header instead.

### What the admin has
- **A lock on every tile** of the gallery itself, for you only (never visitors or private-view viewers): red and labelled *Private* when the photo is private (including by its folder), a faint open lock when it's public (on a desktop it appears on hover). Press it and the photo flips between private and public, with a short message; it doesn't open the viewer. If the admin session has ended it says so. It uses the same save as the admin's switch, so nothing else is touched.
- **Photos** (called **Media** once video is on): a thumbnail grid. Select one for its *Private* and *Show its location* switches (they save as you flip them), *Caption*, *Web link*, location fields and **Set as cover**. **Select photos** makes several private or public at once.
- **Settings** (*Folder settings* in a folder): the title or display name, dates, description, the order of the photos (including its own *Put first* list), location and privacy switches and the map section. The gallery's top has the same form, so a notes file isn't needed just to name it.
- **Notes**: every `.md` in the folder, edited as raw text and saved byte-for-byte; *Save*, *Save As* and *Delete* are separate (the latter two ask first) and locked to `.md` names. A file missing `{gallery}` is shown as not active with a one-click fix.
- **Export**: the folder or whole tree as JSON, the only backup of what you typed.
- **Private** (red, only while something is private), **Site settings**, and a folder tree in the sidebar (a collapsed bar on a phone). While Site settings or Private is showing, the folder you came from is only softly tinted, so the one lit item is the one you are looking at.
- Two tabs can't clobber each other: a save whose record changed elsewhere is refused with an explanation. The admin works at phone width.

### Health check
**Site settings** opens with a **Health check**: the running version and what it was upgraded from, PHP and its extensions, the settings file and passwords, folders from elsewhere, whether folders are writable, whether every folder's data file still reads (a damaged one is flagged before a save could overwrite it), ffmpeg, and, tested by your browser, the server rules ([below](#locking-down-the-files)). It only looks. When all is well it folds to one green line; otherwise it shows only what needs attention (red = problem, amber = worth a look). Open it after an upgrade, a move or replacing folders.

### Passwords
Both passwords are stored in `.gallery-config.php` as **bcrypt hashes**, so a copy of that file doesn't give them away (a hash typed into the sign-in does nothing). New passwords are hashed on saving; a plain-text one (older file, or edited by hand) works and is replaced by its hash at the next sign-in, which signs everyone out once. Sign-in cookies are keyed with a per-install secret in `.gallery-secret.php` (deleting it signs everyone out). Pick long passwords: a short one can still be guessed from its hash. A forgotten password can't be read back: delete the `ADMIN_PASSWORD` line, create `.gallery-setup`, and open `?admin`. To write a hash yourself: `php -r 'echo password_hash("the password", PASSWORD_DEFAULT), "\n";'`.

### What a folder keeps
Each folder holds its own data: `.gallery-items.php` (captions, saved locations, cover, name, dates and the **private flags** of the folder and its photos), `.gallery-stats.*` (view counts) and `.gallery-cache`. Settings, passwords, extra-folder paths, the sign-in key and notes live beside `index.php`. So **wiping a folder and putting a new one in its place keeps every setting working, but the new folder starts blank, and a folder that was private is public again until you mark it**. Keep its `.gallery-items.php` (or the Export download) before replacing a folder. If the new folder is owned by another user the gallery can still show it, but nothing can be saved there and thumbnails aren't cached; the Health check lists such folders.

### Data files are guarded
Generated files (`.gallery-items.php`, `.gallery-stats.php`, `.gallery-login-attempts.php`, `.gallery-secret.php`, `.gallery-health.php`) hold captions, GPS and the exact names of hidden items. They are stored as PHP files starting with a guard line, so a direct request runs it, answers **404** and stops, with no server setting needed. The gallery reads them as plain text and skips the guard line; text is written with `<` and `>` escaped. The one exception is the optional `.gallery-stats.sqlite`, which holds only counts keyed by a hash of each photo's path; the [server rules](#locking-down-the-files) cover it.

## Private items

For photos, videos or folders that shouldn't be on the public link but that trusted people may see in the same gallery.

1. In the admin make an **item** private, or a whole **folder** (covering everything beneath it). An item can be switched back to *public* inside a private folder, or *private* inside a public one.
2. Set `PRIVATE_VIEW_PASSWORD`. The **lock** beside the title (shown whenever there is an admin password, or a private-view password and something private) leads to `?reveal`, a one-field form accepting either password; the private-view one sets a cookie for a year and returns you to the plain link. Pressing the open lock locks private items again in that browser. It is a **password, not a secret link**: links pasted into chats get crawled and can't be revoked.

A private item is removed outright for anyone without the password: absent from the grid, notes, viewer, counts, badges, map and link previews, and `?img=`, `?photo=`, `?folder=` and the video endpoint all answer "not found". A group whose members are all private disappears. The same HTTPS requirement and throttle as the admin apply. The cookie is specific to each copy of the gallery, so several galleries on one site never interfere.

## Downloads

**Original.** The viewer's caption bar has a download button that saves the file exactly as it sits on the server (a HEIC stays HEIC). On a phone it opens the share sheet, so **Save Image** / **Save Video** puts it straight into Photos (anywhere that isn't possible it is a normal download).

| Visitor | Button | File |
|---|---|---|
| The owner | always | as it is on the server |
| Private-view password | always | as it is on the server |
| Anyone else | only with `ENABLE_PUBLIC_DOWNLOAD` (off) | location blanked if the gallery hides it |

The same rule covers the full-size picture in the viewer and a video as it plays (including ranged requests): a visitor who isn't signed in never receives the coordinates of a photo or video whose location is hidden. Private items stay a 404.

**How the location is removed.** Nothing is re-encoded and the size doesn't change: the bytes holding the location are overwritten in the copy sent, and your file is never touched. Blanked: EXIF GPS (JPEG, HEIC, PNG, WebP), GPS and place names in XMP, city/sub-location/state/country in IPTC, and a video's location atom. Date, camera and caption stay. Thumbnails and share previews are re-encoded and never carry a location. **Not covered:** maker notes, GIF and BMP, and text you wrote yourself. A damaged file whose metadata can't be read safely isn't sent as an original at all (422; the viewer shows the re-encoded copy; a video isn't played). Only hidden-location files for signed-out visitors go through PHP, and those responses stay out of shared caches.

**Everything as one ZIP.** Signed in as the owner or with the private-view password, an **archive** button beside the gallery icon saves every original (or, on a folder's page, that folder and below) as one ZIP. It says how many files and how big and **always asks first**. It's a 404 for anyone else. Files are exactly as on the server, locations included.
- It is streamed as it's built (1 MB at a time), so nothing is stored and memory stays flat; files are stored, not compressed, so the size is known up front and a cut-off download shows as incomplete. Over 4 GB or 65,535 files it uses ZIP64.
- Each download holds a PHP worker for as long as it takes, so **only two run at once** (a third gets a 503), there is no resume, and more than 100,000 files is refused. Your host must allow a request that long (shared hosts, proxies and `request_terminate_timeout` may cut it off; with nginx the script sends `X-Accel-Buffering: no`).

## Locking down the files

Everything above governs requests that go through `index.php`. **A photo is still an ordinary static file** that the web server serves to anyone who knows its address (`yoursite.com/kb/IMG_0412.jpg`), and sequential filenames show where something is missing. Only a server rule stops that. The admin's Private tab has these rules written for your gallery's address, with Copy buttons.

**nginx** (strongest; needs shell access). Add inside the `server { }` block, first location above the second, for a gallery at `/kb/`:

```nginx
# Generated data files: never public (a second layer; the .php ones also answer 404).
location ~ /\.gallery- {
    return 404;
}

# The photo and video files themselves: reachable only through index.php.
location ~* ^/kb/.*\.(jpe?g|png|gif|webp|bmp|heic|heif|mov|mp4|m4v)$ {
    internal;
}
```

Then `sudo nginx -t && sudo systemctl reload nginx` and check: `curl -I https://yoursite.com/kb/IMG_0412.jpg` should answer 404. The rules assume the usual `index index.php;` and a `location ~ \.php$` for php-fpm. Finally set **`NGINX_ACCEL_REDIRECT_PREFIX`** to `/kb`, matching the location: the gallery then answers with `X-Accel-Redirect` and nginx sends the file itself, which is faster and gives videos nginx's own Range handling. Leave it empty and PHP sends files (correct everywhere, just slower).

nginx can only send files under the gallery's own folder, so photos from [Archive elsewhere](#archive-elsewhere) are always sent by the gallery, with the setting on or off (they have no address of their own on the web server, so there is nothing to lock for them).

**Apache** (blunter; works on FTP-only hosting). Save as `.htaccess` in the gallery's folder:

```apache
# The photo and video files themselves. (?i): camera files are often IMG_0412.JPG.
<FilesMatch "(?i)\.(jpe?g|png|gif|webp|bmp|heic|heif|mov|mp4|m4v)$">
    Require all denied
</FilesMatch>

# Generated data files (a second layer).
<FilesMatch "^\.gallery-">
    Require all denied
</FilesMatch>
```

A bare photo address then answers 403 while the gallery works as before. **It depends on `AllowOverride`**: if the server doesn't read `.htaccess` there, the rules are silently ignored, so check with `curl -I` (expect 403). The data files stay safe regardless (their own guard). A script that logs in with an `Authorization` header on PHP-FPM or CGI also needs `CGIPassAuth On` (Apache 2.4.13+); the sign-in form needs nothing.

**The admin checks for you.** Each time it opens, your browser (not the server) asks, without signing in, for one photo and one cached thumbnail by their plain addresses (HEAD requests, nothing downloaded) and for a folder by its tidy address. If both pictures are refused (403, 404, 410), the red "Private isn't locked" warning turns into a green note and the rules are tucked away (*Show the rules* brings them back); if one is handed out, the warning stays and names it. If the tidy address opens the folder, Site settings says tidy links work; otherwise it says they're not set up (optional). If `internal` is in place but the redirect setting is empty it says so, with a button to set it; if it's set but doesn't work it says that in red. Nothing is hidden when a check can't be made. Photos from elsewhere on the server can't be tested this way, and the checks say "not needed". Each check covers only the address your browser uses. Nothing renames your files.

## Maps

`ENABLE_MAP` with `ENABLE_PHOTO_LOCATION` (and a photo with a visible location) gives two maps, neither in the way:
- **The page map** starts closed behind a *Show map* button (remembered per browser); it plots every visible located photo, and *View full map* opens a large overlay whose address (`?map`) can be shared; Back closes it. Nothing third-party is requested until someone presses it.
- **The viewer map** is a small map in the photo's top-left corner (about half a mile across; click to enlarge, *Open in OpenStreetMap*). The pin button beside the caption toggles it and the choice is remembered. It is **on by default on a desktop and off on a phone**, where it covered the photo; there the pin pulses on each located photo until pressed once.

**Each folder can have its own map** (Map section in its settings): it shows only that folder's photos and has a shareable `?map&folder=Paris` page; the front page's is on unless switched off, a folder's off unless switched on (a folder with it off has no map page). *Pin grouping* sets how close photos must be to share a pin for that page, or blank for the site setting. Pressing a numbered pin zooms in to its photos. Pins are coloured per top-level folder, with a legend; `{color:}`, `{sold}` and `{reserved}` recolour them. Private items are never plotted for visitors.

**This is the one feature that contacts a third party:** it loads Leaflet from cdnjs and tiles from OpenStreetMap, once someone opens a map.

## Sharing and previews

Every link you'd paste gets a correct Open Graph preview: `?photo=`, `?folder=` (display name, description or notes, and cover photo) and `?map`. The gallery's own link shows the cover you set for it, else the cover of one of its folders (the cover you chose for that folder, else its first photo; never a video or a private folder, and the same all day); a gallery without folders uses one of its photos. Private items are nonexistent to a crawler, so pasting one never previews it. A shared video previews as picture and title, not a playable embed.

`?folder=Europe/France/Paris` needs no server setup and is what the gallery itself shares. To also type `/kb/Europe/France/Paris/`, map it onto the script. The admin's Site settings checks whether this works.

```nginx
location ~ ^/kb/.+/$ {
    rewrite ^/kb/(.+)/$ /kb/index.php?folder=$1 last;
}
```

It matches only paths ending in `/` (so it can't catch `index.php` or a photo), captures the name inside the `rewrite` (which makes nginx escape spaces), and needs php-fpm to pass the original `REQUEST_URI` (the standard `fastcgi_param` does). On Apache, in `.htaccess` (needs `mod_rewrite` and `AllowOverride FileInfo`):

```apache
RewriteEngine On
RewriteRule ^(.+)/$ index.php?folder=$1 [B,L,QSA]
```

Keep the `B` flag: it escapes spaces, `&`, `+` and `#`.

## Troubleshooting

- **Something in a notes file didn't work** (a misspelt file in `{photos:}`, a duplicate claim, a missing `{gallery}`, a directive cut off by a blank line): it is excluded rather than erroring, and a collapsed "⚠ N setup notices for the gallery owner" banner says what (also as a comment in the page source). Once an admin password exists only the signed-in owner gets it.
- **A folder from elsewhere shows nothing:** the setup notices say when its path is missing or unreadable, and the Health check names it.
- **The password form keeps appearing after the right password:** the browser isn't keeping the login cookie. Check cookies aren't blocked and you're on HTTPS (the cookie is `Secure`).
- **"Nothing was saved: this folder isn't writable"** means PHP can't write there, the most common hosting snag. Three things write to folders: saved captions and settings, view counts and the thumbnail cache. The gallery still works without them (the cache falls back to originals). Give the web server's user write permission on the folder, including any [extra folder](#archive-elsewhere) you edit. It never says "Saved" for something it couldn't write.
- **Not sure what's wrong with an install:** open the [Health check](#health-check).

## Security notes

**What it does**
- Everything it writes is private (guarded data files, [above](#data-files-are-guarded)). Output is HTML-escaped everywhere, and a request can only *select* among files the script found itself, never name a path (`..`, symlinks, wrong-case spellings and NUL bytes are refused).
- **Logins:** a form with a constant-time compare and a fresh session id; HTTPS-only admin and private view (except `localhost`); a throttle; CSRF tokens on every write; a stale-edit check; an `HttpOnly`, `SameSite=Strict`, uniquely named session cookie.
- Admin and private-view pages are `no-store`, not frameable, and can't post forms elsewhere; every response carries `nosniff`.
- Private items stay out of shared caches (`private, no-store`); ordinary photos stay cacheable, and a replaced photo gets a new `&v=` tag so browsers fetch it.
- The Host header is used in absolute URLs only if it looks like a real host name.

**Limits**
- A web server hands out any file it can see: private needs the [server rules](#locking-down-the-files).
- Original photos keep their GPS except in copies sent to signed-out visitors where you hide the location.
- A short password can be guessed from its hash.
- **Behind a reverse proxy the script believes what the server tells PHP** (`REMOTE_ADDR`, `HTTPS`, port 443, `X-Forwarded-Proto`). With php-fpm that is right. With `proxy_pass` to PHP on the same machine every visitor looks like `127.0.0.1`, so the HTTPS requirement protects nothing and the throttle is shared: pass the real address and protocol, or keep PHP behind FastCGI.
- Images and Markdown are processed from folders **you** control; it isn't built to accept uploads from strangers.

Found a security problem? Open a GitHub issue, or contact the author privately if it shouldn't be public yet.

## Upgrading and backups

**Updating** means replacing `index.php`. Settings (`.gallery-config.php`), captions and everything else live beside it and are untouched; the Health check then shows what it was upgraded from.

**Upgrading to 2.0 from 1.x** leaves the gallery looking the same, with three notes: `?download` now redirects to this repository instead of serving the script (serving it would hand `ADMIN_PASSWORD` to any visitor); view counts carry over (1.x's `.gallery-stats.json` is imported on the first request with `TRACK_VIEWS` on, then removed); and you may see a new setup notice counting photos in subfolders when `AUTO_GROUP_BY_FOLDER` is off. See [CHANGELOG.md](CHANGELOG.md).

**Keeping runtime data out of git.** If your deployed gallery is in git, add these to `.gitignore` (they hold GPS and passwords):

```
.gallery-cache/
.gallery-items.php
.gallery-config.php
.gallery-stats.*
.gallery-login-attempts.php
.gallery-secret.php
.gallery-health.php
```

`.gallery-items.php`, `.gallery-config.php` and your `.md` files can't be regenerated: use the admin's JSON export for captions and your host's backups for the rest.

## Project status

A personal project, actively developed. See [CHANGELOG.md](CHANGELOG.md) for history.

## License

MIT, see [LICENSE](LICENSE).

---

created by [github.com/pcmike](https://github.com/pcmike)
