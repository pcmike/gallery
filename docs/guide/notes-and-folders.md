# Notes, folders and the viewer

Markdown notes, folders, video, captions and locations, photo order, the viewer, thumbnails and maps.

← [Guide index](README.md) · [Project README](../../README.md)

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

**On a folder's page the way back and the pills for the folders beside it stay at the top of the screen** (the icon strip sticks just under them), with a down arrow and an up arrow at the right, once you have scrolled, that jump to the bottom and the top of the page. The main page has neither.

With `AUTO_GROUP_BY_FOLDER`, subfolders work to any depth, and each becomes a group labelled with the folder's name (rename it with a `{group:}` directive or the admin's *Display name*; names are never prettified automatically).

- **`overview` mode** shows one cover card per subfolder (a highlight photo, name and a recursive count) plus photos loose at that level; a card opens `?folder=Europe/France`, with a breadcrumb back. **`inline` mode** shows a folder's photos in a box on the current page. A page with only one to four folders shows larger cards on a wide screen.
- **Per-folder settings** in the admin: display name, description, dates (typed by you; the admin only suggests them, because dates can reveal when you were away), cover photo and position among siblings. Folders without a position sort naturally after numbered ones (Day 2 before Day 10).
- **A folder can have its own notes** (a `{gallery}` `.md` inside it, scoped to its photos).
- **Everything about a folder lives in that folder**: stats, thumbnails and saved data, so two cameras' `IMG_0001.jpg` never collide. A real rename (FTP, `mv`) carries the data along; a new folder with photos moved into it starts blank.
- **Symlinked folders are refused** so a link can't expose files outside the gallery; use [Archive elsewhere](getting-started.md#archive-elsewhere).
- A swipeable row of pills under the breadcrumb links to the folders beside the one you're in (in the parent page's order; with more than ten, a window around the current one plus "All"). A folder with no sibling has no such row, so its own name appears as the one blue pill after the way up, and the bar always says where you are.
- The viewer's arrows stay inside the folder you opened, and the header shows "Last updated" from the newest file.

## Video

`ENABLE_VIDEO` makes `.mov` and `.mp4` gallery items (grouping, captions and so on work as for photos). Files are sent exactly as recorded, with seeking and a duration badge. While it is off videos are invisible, and a collapsed notice says how many were ignored.

With **ffmpeg** on the server (nothing needed otherwise; Settings shows whether it was found):
- **A real thumbnail** from each video, taken once and kept with the photo thumbnails, used on the tile, folder cover and link preview. Without ffmpeg the tile shows the browser's own first frame where it can play the video.
- **Hover clip and scrubbing** (`ENABLE_VIDEO_PREVIEWS`). Hovering plays a silent 3-second loop (up to 640 px wide); on a desktop, moving the mouse sideways scrubs through the video, and on a phone a finger drag does, while the tile nearest the middle of the screen plays by itself once the page stops moving (never for reduced-motion or data-saver devices). Frames come from a sprite made once per video (a frame every ~0.5 s from 30 s up, 320 px squares). A click or tap still opens the viewer.
- **How it runs:** a fixed argument list, never through a shell, one at a time with a time limit; a video ffmpeg can't read isn't retried for six hours. Everything is made on first need and kept in `.gallery-cache`.

Video tiles carry a play mark and behave the same in the grid, group strips, covers and the admin. A video's **location** is read from the file (an iPhone's QuickTime item or Android's location atom) when its folder's location switch is on. Cameras that record GPS as a track (GoPro, dashcams, drones) aren't read, so add the place by hand; a visitor's copy of such a file isn't cleaned of that track.

Before turning it on: codec support isn't guaranteed (an iPhone's HEVC `.mov` plays natively on Apple devices, not everywhere), and nothing is compressed, so a long 4K clip is hundreds of megabytes.

## Captions and locations

Each photo or video can have a **caption**, a **web link** and a **location**, entered with the pencil on its tile (or in the viewer); they appear as small badges on the thumbnail (each lights up under the pointer, as the owner's lock and star do) and in the viewer (which never shows filenames). Entering a caption or link is its own opt-in.

**Location has three gates, all closed by default**, because GPS is the most sensitive thing here:
1. `ENABLE_PHOTO_LOCATION`: off means no badges, pins or EXIF fill-in anywhere.
2. A **per-folder** switch. **Turning it on publishes** the GPS of every photo in that folder that has some.
3. A **per-photo** switch, *Location* (in the pencil's editor): follows the folder, on shows a landmark in a folder that shares none, off keeps a front door private (and stays off).

These gates decide what **visitors who aren't signed in** see. You, and anyone with the private-view password, see every saved location. A location you typed in is never overwritten by GPS from its file, and one you cleared stays cleared. There's no place-name lookup (it would need a third-party service): paste coordinates from any map tool.

**When a photo's location is read from its file.** Every photo and video is read **once**, whatever the switches say, at the moment its thumbnails are first made (any visitor's first look at a new photo does that) or when you run **Prepare media** ([below](#thumbnails-and-phones)). What a file says about itself, when it was taken and where, is kept in the thumbnail cache (`.gallery-cache/meta1.php`, a guarded file like the data files, left out of the Export, safe to delete: it is read again). A file that is replaced or edited is read again by itself, because the record notes its modified time and size; a file that couldn't be read is tried again, never remembered as "no location". The location switches (the site-wide one, the folder's and the photo's own) decide only what is **shown**, never what is read, so turning a folder's switch on shows what is already known at once, with nothing to wait for. Nothing is copied into your folder data: a location from the file stands in only for photos where you have set none, and what you typed in, or cleared, always wins. Photos that an older version marked as "checked, nothing found" can't be told from ones you cleared on purpose, so they are left alone until you choose *Read everything again* in Prepare media. A folder's settings say, under the location switch, what the gallery has read from its files. With the thumbnail cache switched off there is nowhere to keep any of this, so file locations are not shown to visitors (the editor still offers a photo's location to you).

> **The original files keep their GPS.** So the gallery blanks a location out of what it sends a visitor who isn't signed in, for any photo or video whose location it doesn't show ([Downloads](private-and-sharing.md#downloads)). Your files on the server are never changed. Not covered: some cameras' maker notes, and GIF/BMP files. If that matters, strip metadata before uploading.

## Photo order

Inside a folder, photos and videos run by **date** (the default, oldest first) or by **name**, either way round. Left alone it is date, except that when **Group photos by filename** is on it is name, which keeps `alpha_01`, `alpha_02` in sequence inside their box; an order you choose is kept either way. A date is when it was taken: read from a JPEG or HEIC photo, or the time a video was recorded (the phone's own note of it, else the video's creation time); a file that doesn't say, such as a PNG or screenshot, uses its file date. Dates are read once and kept in the folder's `.gallery-cache`. On top of that, three things can be **put first**, and they stack: **private items** (owner and private-view only), **videos**, and **photos with a location** (counted only where that visitor can see the location, so the order never gives away a hidden one).

**Put first is one list, and you set its order.** In **Settings** it is three rows: *Private items*, *Videos* and *Located photos*. Each row has a **switch** (is it used at all?) and a **handle**: drag a row by its handle, with a mouse or a finger, to set the order the kinds stack in (the up and down arrow keys move a focused handle). What is switched on always sits above what is off, so the list reads the way it sorts, and a sentence under it says the result: *"Videos, then photos with a location, then the rest."* A change saves as you make it. The default order is private, videos, located, so nothing re-sorts until you move a row (the setting is `SORT_PRIORITY`).

**A folder can have its own.** In a folder's settings (or the gallery's top), *Order of the photos here* has the same list under a **Site's / This folder's own** choice, like the Order and Direction rows above it. It shows the site's list and follows it until you change a switch or drag a row; then the choice moves to *This folder's own* (the section's heading says *custom*, even before you save) and *Site's* puts the site's list back. A list that would sort exactly as the site's does counts as the site's and isn't stored. Every switch off while the site puts something first **is** the folder's own ("put nothing first here"), and its sentence says what the site does. A folder with its own order always opens that section by itself.

- **You set the defaults** in Settings and, per folder, in *Order of the photos here*.
- **Visitors change it for themselves** with the sort button (two arrows) after the map buttons, plus line-drawing toggles for videos, location and private (shown only when they apply; the button is hidden for fewer than two photos). They switch the three kinds on and off; the order they stack in is the folder's, else the site's. The choice is kept in a cookie across folders until *Use this gallery's order*. A link can carry one: `?sort=taken.desc.video` (the order, then any of `desc`, `video`, `located`, `private`).
- The strip of buttons (map, sort, Select, download, lock, about) stays at the top of the screen while the photos scroll under it.
- The viewer's next and previous follow the order. Photos placed with `{photos:}` or a group keep your order.
- Dates found in files are cached in the folder's `.gallery-cache`, so they are read only once.
- **Select photos, and place them by hand (you only).** Press the *Select* button (a tick in a circle, after the sort button) and tap tiles to pick them (a tick shows; a selection stays within one folder). A bar at the bottom counts them and offers *Select all*, *Clear*, *Make private*, *Make public* and *Done*; privacy changes are made on the spot, for the whole selection. Only what would change something is offered: *Make private* when a picked photo is not private on its own, *Make public* when one is (with nothing picked both wait, greyed). In a private folder the two buttons become *Private* (kept private on their own) and *Public*, and a third, *Folder* (back to following the folder), appears when a picked photo has a setting of its own to take back; *Share…* (when share links are on) works on anything picked. Where the photos are laid out one by one it also places them, which the sort can't: the three angles of one church shot on different days belong together. Drag any picked tile beside another photo of the same folder and let go; a line shows where it will land. On a phone, hold a tile for a moment to pick it up (a swipe still scrolls). Everything picked moves together, in order. The result is a **stack**: those photos stay next to each other, in the order you set, wherever the sort puts the one you dropped them beside (its *anchor*), so they hold together under name, date, either direction and the "first" choices, for every visitor. Placed tiles show a coloured strip while selecting. *Release* takes the selected photos out of their stacks, *Reset folder* puts the whole folder back. It's stored in the folder's `.gallery-items.php` (`stacks`), so no other setting touches it; a photo that is later deleted or renamed just drops out. Placing isn't offered when photos are grouped by filename or a notes file lays the page out (the rest of Select still works there), and only photos shown as single tiles can be placed.

## The viewer

Click a photo to open it full screen. **Arrow keys** or the on-screen arrows move through the folder (they stay inside the folder you opened), **Esc** closes, on a phone you **swipe** left and right and swipe down to close. The photo's caption, if it has one, comes with a counter ("3 / 52") and a row of buttons (on a phone the counter is always whole at the left and the close button at the right; the buttons between them are a strip you swipe sideways, with a small arrow at an end that has more): **zoom**, **download** the original (for you, or everyone if you allow it), **copy link**, the photo's **web link** and, where a location is shown, the **map** pin (a small map over the photo's corner; the pin turns blue when it's on).

- **Zoom.** Click the photo (or press **Z**) to fill the width of the window, from the original file, and scroll up and down; again for real pixel size, scrolling both ways; again (or **Esc**) to fit the screen.
- **Over a zoomed photo** the counter and buttons sit on a soft dark pill, and the close button and arrows get the same backing, so they stay readable when the photo runs underneath them. Each control lights its own box when the mouse is over it (so you can tell which one you are on), and the map pin stays blue and *copied* flashes green.
- **Drawn icons.** The close button and the arrows are drawn like the rest of the icons, in identical round boxes, so they are centred exactly.
- **On a phone** the counter and buttons are one row across the **top**, with the close button last, and the photo takes everything below it down to the bottom of the visible area (a tall photo is as tall as the screen allows), on a plain black backdrop. Nothing is left to tap near the bottom edge, where Safari answers a tap by bringing its toolbar back, which costs a second tap, and nothing is laid over the photo. The viewer is sized to what is really visible, so Safari's bars never cover the photo.
- **Download** takes the photo from the browser's own memory (the viewer already holds that very file), so the share sheet can open on the first tap; HEIC photos and videos are fetched.

## Thumbnails and phones

**Sharp tiles.** A tile is a square cut from the middle of the photo, and each photo gets it at two sizes, 560 and 1080 pixels, both kept in `.gallery-cache`. The page tells the browser how wide a tile is, and the browser takes the small one for an ordinary screen and the big one for a phone (a portrait iPhone shows one column, about 350 points wide, which at 3x needs about 1080 pixels). Only tiles near the screen load, so the bigger picture costs bandwidth only for what you scroll past. The pictures are progressive JPEGs. The older 480 pixel thumbnail is still what the small strips and the admin use. With `ENABLE_THUMBNAIL_CACHE` off there is only the original.

**Prepare media.** A photo's tile pictures are otherwise made the first time it is shown, which makes the first visit to a big folder slow, and what each file says about itself (when it was taken, where) is read at that same moment. **Settings → Prepare media** does the whole gallery ahead of time and catches up on anything added since: it says how many files are prepared and has a button that does the rest as fast as the server allows. Three requests work side by side, each on every third file and each asking again as soon as it is answered (about a second each), so a **live percentage**, the count done and a rough time left keep moving, with one reading of each photo for both tile sizes. Videos are read for what they say about themselves too, and, when the server has ffmpeg, their **poster frame, hover clip and scrub strip** are made (one video per request, since each takes seconds; a video ffmpeg can't handle is listed and not tried again for six hours; without ffmpeg, or with the video pictures switched off, the panel says so). Stop whenever you like (it finishes the files in hand, then says so and offers *Carry on*); what's done stays done, and a damaged file is counted and passed over.

**What the gallery knows.** *What the gallery knows about each folder* lists, per folder, its files, how many photos have their thumbnails, how many videos have their pictures, how many files were read and what was found (a location, none, couldn't be read, with the names of those that couldn't, not read yet), how many have a capture date, and how many locations are yours (typed, removed, or an old "checked" mark). **What needs attention** is in the Health check: its Media rows are amber for files that couldn't be read, old "nothing found" marks and videos ffmpeg couldn't handle (each folds open to the file names, linked to each file's editor) and quiet, under *Show all checks*, for files not read yet, videos not made yet, files with no capture date and saved locations that differ from their file; *Open Prepare media* switches to Settings and scrolls to this panel. **Read everything again** reads every file again, in case you want to be sure (optionally with *Also make the thumbnails again*); it asks first, and it also reads the photos with an old "checked, nothing found" mark, so a location in the file may start to show where there was none. What you typed in or removed is never changed.

**Tidy up saved locations** (shown only while there is something to tidy). Older versions copied each photo's location from its file into the folder's saved data (`.gallery-items.php`), and marked the photo "checked". Now the files are read once into the cache, which is the only place those locations belong, so the copies go stale when a photo is replaced. *Tidy up saved locations* (Settings → Prepare media) compares every saved location with what its file says and removes the copies. It first shows what it would do, and only the second button does it. Every folder that changes is copied first to `.gallery-items.before-tidy-<date>.php` (guarded like the data file). It only ever touches a photo's saved `location` and the old "checked" mark: captions, links, private flags, covers, each photo's Location switch and a location you removed on purpose are never touched. *Careful* removes only locations that match the file (to about 10 m) and have no label, and keeps and lists the rest. *Refresh from the files* is for when you never typed a location: it also removes ones that differ, so the file's is used. A location with a label, one for a file that has none or couldn't be read, and one for a file not yet read are always kept. Run Prepare media first, so every file has been read. A line under Prepare media says how many backups it made and has **Delete the backups…** (after a yes; only files named exactly like the backups are removed).

**The viewer on a phone** is described under [The viewer](#the-viewer).

**Opening the gallery with no browser bars.** A web page can't hide an iPhone's address bar and toolbar, but a site added to the Home Screen opens without them. While you are signed in, as the owner or as a viewer with the private-view password (so a family member can add the trip gallery to their Home Screen and follow along), the gallery offers that (**Share → Add to Home Screen**; it needs the manifest at `?manifest` and the icon at `?appicon`, both public and harmless; the icon is the same line drawing as the gallery icon in the icon strip, light on the gallery's dark background, drawn by the script itself, so there is no image file to add). A visitor who isn't signed in doesn't get it. Signing in sets a cookie that lasts a year, so it stays signed in; but an iPhone keeps a Home Screen app's cookies apart from Safari's, so sign in once inside the app (the lock in the icon strip) and it is kept from then on.

## Maps

`ENABLE_PHOTO_LOCATION` (and a photo with a visible location) gives two maps, neither in the way:
- **The page map** starts closed behind a *Show map* button (remembered per browser); it plots every visible located photo, and *View full map* opens a large overlay whose address (`?map`) can be shared; Back closes it. Nothing third-party is requested until someone presses it.
- **A link that opens the small map.** End the gallery's address with `#map` (`https://example.com/trip/#map`, or `…/trip/?folder=Paris#map` for a folder) and the page opens with the small map showing, scrolled into view and marked with the same highlight border a photo gets when a link snaps to it. It is not the full-screen map, so there is nothing to close on a phone, and it doesn't make the map stay open for that visitor afterwards. The open map has a small **link icon** in its top right corner (the mirror of the zoom buttons at the top left) that copies exactly that address. The full-screen map has the same icon in its bottom left corner, copying its own `?map` link.
- **The viewer map** is a small map in the photo's top-left corner (about half a mile across; click to enlarge, *Open in OpenStreetMap*). The pin button beside the caption toggles it and the choice is remembered. It is **on by default on a desktop and off on a phone**, where it covered the photo; there the pin pulses on each located photo until pressed once.

**Each folder can have its own map** (Map section in its settings): it shows only that folder's photos and has a shareable `?map&folder=Paris` page; the front page's is on unless switched off, a folder's off unless switched on (a folder with it off has no map page). *Pin grouping* sets how close photos must be to share a pin for that page, or blank for the site setting. Pressing a numbered pin zooms in on its photos, and groups that would overlap on screen merge until you zoom in, so every pin can be pressed with a finger (a group that zooming can't part opens into one pin per photo). On a phone, one finger on the little map above the photos scrolls the page and says that two fingers move the map; the full-screen map moves with one. Pins are coloured per top-level folder, with a legend; `{color:}`, `{sold}` and `{reserved}` recolour them. Private items are never plotted for visitors.

**A pin's thumbnail finds its photo.** Press the thumbnail in a pin's popup and the page scrolls that photo (or its group) to the middle of the screen and rings it for a moment. The little map above the grid stays open; the full-screen map closes first. A *← Back to the map* pill at the bottom takes you back (it scrolls up to the little map, or opens the full-screen map again as you left it); it goes when you press it, its cross or Escape, when the map is on screen again, when you open a photo, or after about ten seconds.

**The map contacts a third party from the visitor's browser:** it loads Leaflet from cdnjs and tiles from OpenStreetMap, once someone opens a map. (The only request the server itself makes to another site is the optional [country file](admin.md#the-country-file), off until you switch it on.)
