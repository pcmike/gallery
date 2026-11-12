# Settings

Every setting, its default, and where to change it.

← [Guide index](README.md) · [Project README](../../README.md)

## Settings

Changed in **Settings** (no Save button: each change saves as you make it) or in a [settings file](#the-settings-file). Anything left alone keeps its default.

| Setting | Default | What it does |
|---|---|---|
| `AUTO_GROUP_BY_FOLDER` | on | Subfolders become albums, to any depth (on by default since 2.5.0) |
| `FOLDER_DISPLAY_MODE` | `overview` | How a page shows its folders: `overview` (cover cards) or `flat` (all their photos on the page, folder by folder). The older `cards` and `inline` (Flat with folder names) still work |
| `FOLDER_FLAT_NAMES` | off | Flat only: a box for each folder directly under the page and a divider for the folders inside it, with each folder's description and dates, and a pill for each folder in the row at the top to jump to it |
| `FOLDER_FLAT_NOTES` | off | Flat only, with folder names on: each folder's notes text under its name |
| `AUTO_GROUP_BY_FILENAME` | off | `alpha_01.jpg`, `alpha_02.jpg` group as "alpha" |
| `ENABLE_VIDEO` | off | Show `.mov` / `.mp4` |
| `ENABLE_VIDEO_THUMBNAILS` | on | Real video pictures (needs ffmpeg) |
| `ENABLE_VIDEO_PREVIEWS` | on | Hover clips and scrubbing (needs ffmpeg) |
| `FFMPEG_PATH` | none | Full path, if ffmpeg is somewhere unusual (file only) |
| `ENABLE_THUMBNAIL_CACHE` | on | Thumbnails, and what the files say about themselves (capture date, location), in `.gallery-cache`; off = originals, no HEIC, and nothing is read ahead of time |
| `PHOTO_SORT_ORDER` | `taken`, or `filename` when `AUTO_GROUP_BY_FILENAME` is on | `taken` (date taken, else the file's date; oldest first) or `filename` (the older `mtime` still works) |
| `PHOTO_SORT_DESC` | off | Last first (Z to A, newest first) |
| `SORT_VIDEOS_FIRST` / `SORT_LOCATED_FIRST` / `SORT_PRIVATE_FIRST` | off | What goes first, whatever the order; they stack |
| `SORT_PRIORITY` | `private,video,located` | The order those three stack in (the admin shows them as a list you drag) |
| `SHOW_SORT_BUTTON` | on | The visitors' sort button (left out of a listing where a notes file places the photos, with no folders: a `{photos:}` or `{group:}` directive, or a paragraph that names one; a notes file that is only words keeps it) |
| `TRACK_VIEWS` | off | Count visits and photo views |
| `SHOW_VIEW_COUNTS_PUBLICLY` | off | Show visitors the counts (you, signed in as the owner, always see them: a count on every tile, a zero too, and the totals) |
| `PUBLIC_DOWNLOAD_MODE` / `PUBLIC_DOWNLOAD_FOLDERS` / `PUBLIC_DOWNLOAD_ROOT` | `off` | Kept by the admin, not a row in Settings: each folder's own **Visitors can download originals** switch (in its folder settings, right after *Gallery of its own*) and the main page's **Visitors can download the photos on this page**. A folder's switch covers only the photos directly in that folder, never the folders inside it (while it is on, the line under it says how many of the public folders below it, at any depth, allow downloads and names those that do, as links to their settings; **Allow downloads in all** and **Turn off in all** switch them once, with no link afterwards). A download button for visitors who arrive without a share link; public photos only, never a ZIP. Switches are hidden while the gallery is closed. An older `all` (Everything) or the old on/off line is read as off until a switch is used |
| `SCRUB_LOCATION_DATA` | on | The location (GPS) is taken out of every original a visitor is sent: the viewer's full-size picture, a download, a video that plays and a Not listed photo's own link, whether the gallery shows the location on the page or not. You, the private-view password and share links set to show all locations always get the file as it is. With it off, only a location the gallery hides is taken out | A folder can opt out for its own photos with **Keep the location in files visitors get** (in its Locations section, under the location switch); a location the gallery hides is still taken out
| `ENABLE_PHOTO_LOCATION` | off | Allow locations to show (also needs a per-folder switch) |
| `ENABLE_MAP` | (gone) | Removed in 2.4.0: a map is available wherever `ENABLE_PHOTO_LOCATION` is on (it loads Leaflet and OpenStreetMap tiles); each folder's own Map switch controls it. An old line in the settings file is ignored and dropped by the next save |
| `MAP_CLUSTER_RADIUS_MILES` | 1 | No row in Settings since 2.6.5: each folder's own settings have the pin grouping (photos closer than this share a pin; 0 = no grouping), and 1 mile is the built-in default. A line already in the settings file is still read |
| `EXTRA_ROOTS` / `EXTRA_FOLDERS` | none | Places elsewhere on the server (see [Archive elsewhere](getting-started.md#archive-elsewhere)) |
| `ADMIN_PASSWORD` | none | Turns the admin on |
| `PRIVATE_VIEW_PASSWORD` | none | Turns private view on |
| `ENABLE_SHARE_LINKS` | off | Turns on [share links](private-and-sharing.md#share-links) |
| `DEFAULT_LINK_USES` | 2 | How many browsers a **new** [share link](private-and-sharing.md#share-links) lets in (0 = unlimited): the number the new-link form and the quick window start on. A limit turns the next new browser away and shows it as a blocked try; browsers already in keep working |
| `SHARE_HEADS_UP` | off | A thin line at the top for people using a link: when it ends (each link can say otherwise) |
| `LOG_PUBLIC_VISITS` | off | [Log who opens the public address](private-and-sharing.md#logging-the-public-address) in the [Visitors tab](private-and-sharing.md#the-visitors-tab-the-log) (needs SQLite; no longer needs share links) |
| `LOG_KEEP_DAYS` | 0 | How many days [visitor records](private-and-sharing.md#the-visitors-tab-the-log) are kept, for the public address and every share link (0 = until you trim the log) |
| `LOG_WARN_MB` | 100 | The Health check warns, with the size and the oldest record, when the visitor records take more than this many megabytes (0 = no warning) |
| `CHANGES_KEEP_DAYS` | 0 | How many days the entries of the [Changes tab](admin.md) (the log of disk activity) are kept (0 = until you trim the log) |
| `CHANGES_WARN_MB` | 20 | The Health check warns when the Changes tab's entries take more than this many megabytes (0 = no warning) |
| `ADMIN_STAY_SIGNED_IN_DAYS` / `PRIVATE_VIEW_SIGNED_IN_DAYS` | 3 / 3 | [How long the owner, and a guest in private view, stay signed in](admin.md#staying-signed-in-and-which-browsers-are-signed-in) after the last visit (0: until the browser closes) |
| `BROWSER_HISTORY_DAYS` / `BROWSER_ENDED_DAYS` | 90 / 30 | How long [a browser's address history](admin.md#address-history-and-alarms) is kept, and how long a signed-out browser stays under *Recently signed out* |
| `BROWSER_NETWORKS_ALARM` | 20 | Flag a browser used from more than this many networks in 24 hours |
| `BROWSER_END_ON_DEVICE` / `_FAMILY` / `_COUNTRY` / `_NETWORKS` | off | Sign a browser out by itself when the matching flag is raised |
| `COUNTRY_LOOKUP` | off | The [country file](admin.md#the-country-file) (DB-IP, downloaded to your server about monthly); `COUNTRY_DB_URL` names another source |
| `CHECK_FOR_UPDATES` | on | Once a day the [Health check](admin.md#new-versions-and-update-now) asks GitHub for the newest version and offers *View release* and *Update now* when it is newer; `UPDATE_FEED_URL` names another source |
| `CLOSE_PUBLIC_GALLERY` | off | [Close the public gallery](private-and-sharing.md#closing-the-public-gallery): only you, private-view sign-ins and people using a share link see anything (needs share links on and an admin password) |
| `TRUSTED_PROXY` / `TRUSTED_PROXY_RANGES` | `direct` / none | Where visitors' addresses come from, for share-link logs and the sign-in throttle: `direct` (the connection), `cloudflare`, or `proxy` (the `X-Forwarded-For` header, believed only from the ranges you list) |
| `NGINX_ACCEL_REDIRECT_PREFIX` | none | The gallery's URL path (e.g. `/kb`), once nginx has the matching location (it is in the nginx block under **Web server rules**, [The server](server.md#locking-down-the-files)) |

### The settings file
Replacing `index.php` with a newer version would reset anything you edited into it, so settings live in **`.gallery-config.php`** beside it, listing only what you changed. Settings creates and updates it for you. By hand it is a one-line guard followed by JSON ([`gallery-config.example.php`](../../gallery-config.example.php) is a copy to edit):

```
<?php http_response_code(404); exit; ?>
{
    "ENABLE_VIDEO": true,
    "FOLDER_DISPLAY_MODE": "flat"   // notes after // are ignored; a trailing comma is fine
}
```

- It overrides `index.php`'s defaults and survives updates. A better default in a newer release still reaches everything you haven't set.
- **Mistakes never break the site:** a misspelt name, a value of the wrong kind or invalid JSON is ignored and named in the owner's setup notices.
- It is private: a direct request answers 404 and its `.gallery-` name is covered by the [server rules](server.md#locking-down-the-files). Passwords in it are [hashes](admin.md#passwords). Keep it [out of git](upgrading.md#upgrading-and-backups).

### Settings in the admin
**Search.** The box above the groups finds a setting by a few words: its name, a word from its help, one of its choices, or its name in the settings file (`TRACK_VIEWS`). Only the settings that have every word stay, with their groups open; Escape or the cross clears it and the groups go back as they were. A setting that only shows while another one is on is counted ("1 more matches but only shows while another setting is on"), not listed.

Groups (**Folders and photos**, **Videos and thumbnails**, **Visitors and logs**, **Location data**, **Sharing and access**, **Signing in and passwords**, **Server setup**, and **Files this gallery has made**) fold open and shut, and all start closed. A row that only matters while another switch is on (the map under *Allow photo locations*, the pin grouping under the map, the visitor view counts under *Count views*, video thumbnails under *Show videos* and so on) is hidden until that switch is on, and "default: …" shows under a row only while its value differs from the default. Photo order and its direction are one control of four choices; three or more choices are a **menu button** (a button showing the choice that opens a dark list with a tick), two short ones are pills. *Expand all / Collapse all* sit at the right of the *Settings* heading (only the one that can do something). The gallery-wide *Map* switch is gone: a map is available wherever locations are allowed, and each folder has its own *Map* switch. A setting that publishes something or contacts a third party shows an orange warning before you touch it; switching one off only hides things and never deletes data. Saving writes only `.gallery-config.php`, never your photos, captions, notes or counts. A change that can't be saved (invalid value, unwritable folder, expired session, file changed elsewhere) puts the switch back and says why. If the folder isn't writable it shows the exact text to create the file by hand. Saving drops any `//` notes you added.
