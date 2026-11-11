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
- **pdo_sqlite**: view-count storage when `TRACK_VIEWS` is on (else a JSON file). **The visitor log needs it** (and a folder the web server can write to): without it nothing is logged and the Visitors tab is hidden; the Health check says so.
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
**Settings → Server setup → Folders from elsewhere**: paste a path and press *Add*. The folders inside it appear at the top of the gallery as if they lived there (`/space/trip` holding `Paris` and `StTropez` gives you both). Give the place a name to show it as one folder instead. Photos lying directly in the path aren't shown (a notice counts them). A real folder of the same name wins. Captions and settings are saved inside those folders, so PHP needs write access there. Symlinked folders are refused by design; this is the safe alternative. Each entry has **Edit** (its name and path in one step) and **Remove**. Renaming a place changes its folders' addresses (`?folder=Eurotrip/…`, with the old name) but not its share links, collections or Not listed addresses, and **its view counts follow it**: the gallery remembers the names a place has had and adds up the views under all of them, so renaming it back loses nothing. Give a place the name you will keep: if you plan to move it later into a folder of the same name inside a place you add without a name (`/space/photos/eurotrip2026`), name it that (`eurotrip2026`) now and its addresses and counts stay the same after the move.

### Folders another gallery already shows (moving a gallery in)
A place that an older gallery (another copy of this one) already shows can be added here without moving anything: the photos are not touched, and the files the other gallery made in those folders are read, not replaced. Give the place a name to show it as one folder (say `/space/trip` as "Eurotrip"); pick that name once, before you share anything, because every folder and photo address under it contains it.

**A good order:**
1. **Update the old gallery first** to a version that leaves other galleries' thumbnails alone (2.6.4 or later), if it is going to run beside this one for a while.
2. **Set this gallery up before adding the place:** turn on what you used there (*Videos*, *Share links*, *Count visits*), and keep it closed (`CLOSE_PUBLIC_GALLERY`) until you have checked it.
3. **Add the place** under *Settings → Server setup → Folders from elsewhere*, and look through the gallery as the owner: **check every private mark**, because whatever has none is public here as soon as this gallery is.
4. **Open it** (switch the closed gallery off), then carry what is worth carrying over in *Settings → Count views*: **Add earlier views to the counts** (one press, once per folder; the Health check has an amber row until you do or set it aside). Once the old gallery is retired, **Tidy leftover thumbnails** removes what it left behind.
5. **Retire the old gallery by hand**: nothing in this gallery deletes another install's files. Its folder (`index.php`, `.gallery-config.php`, `.gallery-links.php` and the link logs, the sign-in files, the install secret) can go when you are sure; first take a **Files ZIP** of it if you want the history of who opened which share link, which is kept only there. Its share links stop working. Its front-page visit counter is not carried.

- **What comes along**, because it is saved inside the folders: names, dates, descriptions, covers, order, captions, private marks on photos and folders, stacks, folder ids. **What does not**: the other gallery's own settings (sort order, videos, locations, tracking, closed gallery: set them again here), the title and description it keeps beside its own `index.php` (a place shown under a name takes its title and description from a settings file inside the place: type them on that folder), its share links and logs, and its view counts (this gallery counts from zero in the same file, beside the old numbers, until you add the earlier views).
- **Whatever has no private mark is public here** as soon as the place is added, if this gallery is public. To look first, keep this gallery closed until you have added the place and marked what you want.
- **Both galleries read and write the same files**, so a caption or private mark saved in one shows in the other. An older copy does not know **Not listed** (it sees a Private folder, so a stranger still meets nothing, but its private-view password opens it), **Gallery of its own** (ignored) or collections. If the older gallery makes a Not listed folder public and then private again, it is Not listed here again.
- **Thumbnails.** Each gallery names the thumbnails it makes with its own key, so one gallery's thumbnails look like leftovers to the other. This gallery never clears anything in a place from elsewhere on the server (so the old gallery's pile up there until *Tidy leftover thumbnails*). An older copy does, so while both show the same folder it clears this gallery's thumbnails whenever it shows that folder (they are made again). Once the move is done, take the place out of the older gallery's list. If you instead **move the folders for real into this gallery's own folder**, their old thumbnails (and any other file in `.gallery-cache` that is not this gallery's own) are removed on the next visit, and *Add earlier views* brings the counts across because the path changed.
- **Writing.** Not listed, Gallery of its own, collections, a folder's title and a photo's link are saved inside the folder, so PHP needs write access to it, including to the top folder of a named place; a save that cannot be written says which folder is not writable, and the Health check looks at the place itself.
- **Delete…** in Settings (Files this gallery has made) lists the data files in these folders too and says how many are in places from elsewhere; another gallery that uses them loses them with it. Its own settings, links and sign-ins are never on the list.

### iPhone photos
Keep `ENABLE_THUMBNAIL_CACHE` on and have Imagick with HEIC. With the cache off, `.heic` files are left out; with it on they get JPEG thumbnails and are converted for the viewer.

**Colours.** An iPhone photo carries a wide-gamut colour profile (Display P3). Every picture the gallery makes from a photo (thumbnails, tiles, covers, the converted copy for the viewer) keeps a small profile like that, so a photo is as vivid on a wide screen as it is on the phone; the rest of what the photo says about itself (where it was taken, the camera) is still removed. A profile too big to keep (some cameras' run to megabytes) is converted to sRGB and dropped. Pictures made before 2.6.5 had their profile removed and look a little flat on a wide-gamut photo; they are made again, once, as they are asked for.

**Safari shows HEIC itself.** In the viewer, a browser that can show HEIC (Safari on iPhone, iPad and Mac) is sent the photo itself, at its own size and with its own colours, under the same rules as any original (a visitor who may not see where it was taken gets it with the location blanked out). Every other browser (Chrome, Firefox, Edge) gets the converted JPEG, now up to 4096 pixels on the long side (it was 2400). The grid always uses the converted thumbnails.
