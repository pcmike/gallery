# Getting started

Install it, first run, and a recipe for each way people use it.

← [Guide index](README.md) · [Project README](../../README.md)

## Quick start

```
your-folder/
  index.php     <- this file
  photo1.jpg
  forsale.md    <- optional notes
```

**PHP 8.3 or newer** (developed on 8.4, the version Debian 13 ships and patches; the test suite also passes on 8.3, the oldest it is written for, and on 8.5); older versions get a plain message instead of a failure. No extension is required. Optional:
- **Imagick** (with HEIC) or **GD**: thumbnails (on by default). Without either, or if the folder can't be written, originals are sent. HEIC (iPhone) photos need Imagick.
- **EXIF**: reading GPS and capture dates from JPEGs.
- **pdo_sqlite**: view-count storage when `TRACK_VIEWS` is on (else a JSON file).
- **ffmpeg** (a program, not a PHP extension): real video thumbnails, hover clips and scrubbing.

**A `.md` file counts only if its first line is exactly `{gallery}`.** Any other file is never read.

## Recipes

Settings are switched in the admin's **Settings**, or in a [settings file](settings.md#the-settings-file). To open the admin the first time, visit `yoursite.com/your-folder/?admin`: it asks for a code from a small file the gallery makes beside `index.php`, then for the password you want ([first run](admin.md#first-run-and-signing-in)); it needs HTTPS (or `localhost`).

| I want to… | Recipe |
|---|---|
| Show a folder of photos | [Plain gallery](#plain-gallery) |
| Post a "for sale" listing | [For-sale listing](#for-sale-listing) |
| Share a trip with captions, places, video, a map | [Trip album](#trip-album) |
| Keep some family photos private | [Private photos](#private-photos) |
| Include an archive from another disk | [Archive elsewhere](#archive-elsewhere) |
| Show iPhone (HEIC) photos | [iPhone photos](#iphone-photos) |

### Plain gallery
Copy `index.php` into the folder. Visitors see every photo in it, and each subfolder as a cover card, by date taken, oldest first, with a full-screen viewer (arrows, swipe). `?photo=name.jpg` opens one photo and previews correctly when pasted into Discord or Reddit. Until you ask, nothing is written but thumbnails. If videos exist while video is off, a collapsed owner-only notice gives a count.

### For-sale listing
Settings: `AUTO_GROUP_BY_FILENAME` on; optionally `TRACK_VIEWS` on (and `SHOW_VIEW_COUNTS_PUBLICLY` off to keep the numbers to yourself).
1. Copy [`examples/example-forsale.md`](../../examples/example-forsale.md) into the folder as `forsale.md`.
2. Name photos after each item (`alpha_01.jpg`, `alpha_02.jpg`) and write that **same word** in the item's paragraph (`Widget **Alpha** — brand new`). It must be the whole filename prefix.
3. Add `{sold}`, `{reserved}` and `{color: #5865f2}` anywhere in a paragraph.
4. Photos named `IMG_0234.jpg`? List them: `{group: Widget Alpha} {photos: IMG_0234.jpg, IMG_0891.jpg}`.

Visitors see each item's text with its photos beneath ("View 2 photos"), a box per multi-photo item, a red SOLD ribbon with greyed photos, an orange RESERVED tag. Paste a `?photo=` link or a box's copy-link button into your post.

### Trip album
Settings: `ENABLE_PHOTO_LOCATION` (maps come with it, and contact a third party from the visitor's browser: OpenStreetMap), `ENABLE_VIDEO`, and an admin password.
1. Folders like `trip/Paris/`, `trip/Lisbon/` appear as cover cards (natural name order; subfolders are on by default). They nest to any depth. *Settings → Folders and photos → Folder style* can instead put every photo on one page, folder by folder (*Flat*), with the folder names, descriptions and notes shown if you want them; a folder can choose for itself in its own settings.
2. In `?admin`, open a folder and fill in *Display name*, *Dates* (it suggests the span of the photos), *Description* and *Position*.
3. Tick **Show photo locations publicly for this folder**. This publishes the GPS of every photo there that has some (a warning says how many); a single photo opts out with its own *Location* switch (press its pencil).
4. Press a photo's pencil to give it a *Caption* and *Information link* and correct a location, and press its star to pick the folder card's picture.
5. The map appears on the front page once a photo has a visible location.

Videos show a play mark and length and play in the viewer. **Back up everything** with *Download ZIP* under *Settings → Files this gallery has made*.

### Private photos
Settings: an admin password; `ENABLE_SHARE_LINKS` for [share links](private-and-sharing.md#share-links), or `PRIVATE_VIEW_PASSWORD` for a password.
1. Make a photo private with its **Private** switch (several at once with **Select**), or a whole folder with its **Private folder** switch (in the folder's settings). A photo can be switched back to public inside a private folder. To go through a folder before anyone sees it, see [Going through a private folder first](private-and-sharing.md#going-through-a-private-folder-first).
2. Let people in. **A share link** is a temporary address (or QR code) for a person: limited by opens and time, optionally to one folder or a few photos, with a log of what they looked at, and you can pause or revoke it. Press **Share** on a private folder, or **Share…** on a photo or a selection. Or, for standing access, give your people the viewer password: they press the **lock** in the icon strip and type it once, and the browser remembers for a year.

Visitors without the password see no trace of what's private. **Then lock the files themselves**: private only controls what the gallery serves ([Locking down the files](server.md#locking-down-the-files)). The admin warns you at the top of its Edited tab until that's done.

### Archive elsewhere
**Settings → Server setup → Folders from elsewhere**: paste a path and press *Add*. The folders inside it appear at the top of the gallery as if they lived there (`/space/trip` holding `Paris` and `StTropez` gives you both). Give the place a name to show it as one folder instead. Photos lying directly in the path aren't shown (a notice counts them). A real folder of the same name wins. Captions and settings are saved inside those folders, so PHP needs write access there. Symlinked folders are refused by design; this is the safe alternative.

### Folders another gallery already shows
A place that an older gallery (another copy of this one) already shows can be added here without moving anything: the photos are not touched, and the files the other gallery made in those folders are read, not replaced.
- **What comes along**, because it is saved inside the folders: names, dates, descriptions, covers, order, captions, private marks on photos and folders, stacks, folder ids. **What does not**: the other gallery's own settings (sort order, videos, locations, tracking, closed gallery: set them again here), the title and description it keeps beside its own `index.php` (a place shown under a name takes its title and description from a settings file inside the place: type them on that folder), its share links and logs, and its view counts (this gallery counts from zero, in the same file, beside the old numbers).
- **Whatever has no private mark is public here** as soon as the place is added, if this gallery is public. To look first, keep this gallery closed (`CLOSE_PUBLIC_GALLERY`) until you have added the place and marked what you want.
- **Both galleries read and write the same files**, so a caption or private mark saved in one shows in the other. An older copy does not know **Not listed** (it sees a Private folder, so a stranger still meets nothing, but its private-view password opens it) or **Gallery of its own** (ignored). If the older gallery makes a Not listed folder public and then private again, it is Not listed here again.
- **Thumbnails.** Each gallery names the thumbnails it makes with its own key, so one gallery's thumbnails look like leftovers to the other. This gallery never clears anything in a place from elsewhere on the server. An older copy does, so while both show the same folder it clears this gallery's thumbnails whenever it shows that folder (they are made again). Once the move is done, take the place out of the older gallery's list.
- **Writing.** Not listed, Gallery of its own, a folder's title and a photo's address are saved inside the folder, so PHP needs write access to it, including to the top folder of a named place. Without it you are told that nothing was saved, and nothing is claimed.
- **Delete…** in Settings (Files this gallery has made) lists the data files in these folders too and says how many are in places from elsewhere; another gallery that uses them loses them with it. Its own settings, links and sign-ins are never on the list.

### iPhone photos
Keep `ENABLE_THUMBNAIL_CACHE` on and have Imagick with HEIC. With the cache off, `.heic` files are left out; with it on they get JPEG thumbnails and are converted for the viewer.
