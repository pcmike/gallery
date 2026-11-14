# Plan: Not listed folders, coded photo addresses, "Gallery of its own" (ships as 2.6.3)

Agreed with the owner on 2026-11-05. NOT BUILT YET. Kept untracked (like BUILD-PROGRESS.md) so a git rollback never removes it.
If a build is rolled back and the plan needs tweaks, edit this file, then try again. Rollback anchors are at the bottom.

## 1. What it is, in the owner's words
- **Not listed** is the owner's private drawer: a folder that stands in for a bare `photos/random/` containing a blank `index.html`. The owner sees everything in it and shares single photos from it. Nobody else is meant to see the full contents. It also lets the owner change a photo's link without touching anything on disk.
- **Gallery of its own** lets each album (Sunrises, Sunsets, Eurotrip) stand alone: someone who arrives inside it can go up only as far as that folder.
- Share links keep working exactly as they do now.

## 2. The example gallery
```
/photos                      (nothing switched on)
|-- sunrises                 Gallery of its own
|-- sunsets                  Gallery of its own
|-- random                   Not listed
`-- eurotrip                 Gallery of its own
    |-- italy
    |   `-- florence
    `-- france
        `-- paris
```

| They go to | They get |
|---|---|
| `/photos` | Sunrises, Sunsets, Eurotrip. No Random. Normal navigation. |
| `/photos/random` | The same "not found" response a missing folder gets (status and body identical). The owner sees it with a **Not listed** pill. Private-view password holders do NOT see it. |
| `/photos/sunrises` | Its photos. Arrived directly: nothing above it. |
| `/photos/eurotrip` | Its contents, including Italy and France. Nothing above it. |
| `/photos/eurotrip/italy` | `<- Eurotrip`, France beside Italy. Nothing above Eurotrip. |
| `/photos/eurotrip/france/paris` | `<- Eurotrip > France`, folders beside Paris. Nothing above Eurotrip. |
| `/photos/?photo=random/IMG_1234.jpg&k=CODE` | That one photo, alone: no grid, no neighbours, no navigation. Wrong or missing code: "not found". |
| `/photos/random/IMG_1234.jpg` (the file) | 403 when the server rule is in place (Health check warns when it is not). |

Someone who came in through `/photos` and then opened Eurotrip keeps the way back up. Typing or pasting a new address resets that. `/photos` can always be typed. This navigation is tidiness, not security.

## 3. New choices (no new global settings)
On a folder:
1. **Visibility: Public / Not listed / Private.** Private is unchanged.
2. **Gallery of its own** (a box). The nearest marked folder above (or at) where someone is is the top. None marked = nothing changes (an upgraded site behaves exactly as before).
3. **Allow downloading originals** (only on a Not listed folder, off). It decides whether someone opening a photo by its address can download the original. Location uses the folder's existing location switch (off by default). No folder ZIP exists for a Not listed folder.

On a photo in a Not listed folder (owner only): **current link** (copies the address) and **new link** (asks first, then stores a fresh random string on that photo only; the old address stops). Thumbnail overlay along the bottom edge: edit, new link (crossed-out link icon), current link. Viewer toolbar: the crossed-out link sits just before the existing link icon. Mockups: `~/backups/gallery-2.3.3-dev/context/not-listed-mockups/` (the script `mock.py` rebuilds them).

## 4. Storage and codes
- A Not listed folder is stored as **Private plus an `unlisted` flag** in the folder's own `.gallery-items.php`. Old versions (the owner copies old releases over new ones) see Private, so strangers still cannot see it (fail closed). The flag reuses `file_is_hidden()` and the ~32 `=== 'private'` checks (lists, counts, ZIPs, previews, thumbnails, images, downloads, caching).
- Photo code = HMAC(install secret, folder hidden id + file name [+ the photo's stored random string once refreshed]). Nothing is stored until **New link** is used. Renaming or moving the folder keeps codes; renaming the photo ends its address. Compare with `hash_equals`; wrong and missing code answer identically.
- Item-level "public" inside a Not listed folder is ignored (the folder stays unlisted).
- "Gallery of its own" is a flag in the folder's own file; moves with the folder. Old versions ignore it (harmless).

## 5. Share links
Unchanged: scopes, Show only, custom addresses, limits, logs, quick Share. A link that names a Not listed folder opens it as the link says. A "just the public gallery" link does not list it. **An "Everything" link excludes Not listed folders** (to share one, pick it under Only some folders). Download choices on a link still override the gallery setting, both ways. People on a link are not affected by "Gallery of its own".

## 6. The public log
Log visitors to the public address (existing setting): each visit gains **Came in at** (the arrival address) and an "Arrived at ..." timeline line; opens of Not listed photos are logged and tagged **Not listed**. Visitors are still an IP address plus a browser, not a cookie. Private items are never in this log (nobody who can see them is logged there).

## 7. Navigation rule (Gallery of its own)
- Server-side: the HTML itself leaves out the way up and sibling folders (names never reach a boxed-in visitor's page source).
- A short session cookie remembers the top. A fresh arrival (pasted or typed address, chat link, bookmark, new tab: `Sec-Fetch-Site` not `same-origin`) resets it; a click inside the gallery keeps it; a missing header keeps the stored one (fail toward showing navigation).
- Pages that vary with the cookie send `Cache-Control: private`. Admin, private-view and link visitors are unaffected. Admin shell (`body.inframe`) untouched. Also check: manifest start address, Flat view, `?photo=` deep links, sign-in/out return addresses.

## 8. Owner-only filter (the careful part)
`is_revealed()` is true for the owner AND for private-view password holders (27 call sites; about 8 decide what is shown). Not listed must hide from private-view holders too, so each of these needs "not the owner and the folder is Not listed -> treat as hidden":
- `src/php/009` ~423 (the main list filter), `src/php/020` ~131, ~156, ~174 (may see / may download), ~376 (folder names), `src/php/005` ~157 (ZIP list), `src/php/026` ~49 (media list), `src/php/061` ~267, ~340, ~497, ~588 (page body sort, download controls, file lists). The other `is_revealed()` uses are logging/UI.
- Safety net: a test folder with a sentinel filename (`NOTLISTED-SENTINEL.jpg`) crawled four ways (stranger, private-view, share link, owner) across every page and route (`img`, `original`, `download`, `archive`, `strip`, `preview`, `track`, `mapicon`, plus lists, counts, Flat, covers, last-updated dates, previews). It fails if the sentinel shows up anywhere but for the owner (or the link that names it).

## 9. Gotchas to handle
- Photo page: `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex, nofollow` (public pages set neither today; link pages do, see `020-folder-ids.php` ~321); image requests and the link preview (Open Graph) carry the code.
- The 403-for-bare-photo rule cannot be tested on `php -S`; the Health check warns loudly and the Not listed switch says so.
- Thumbnails of private/hidden items are not cached, so a large Not listed folder regenerates thumbnails for the owner each visit (same as Private today).
- Old-version downgrade: strangers still blocked; private-view holders could see a Not listed folder on an older version.

## 10. What stays the same
Share links (all of it), Private folders and photos, the private-view password, Close the public gallery, `?folder=` and `?photo=` for public things, the web-server rules, files on disk, the update check / Update now.

## 11. Build order (each step ends green, committed and backed up)
1. Not listed: flag, owner-only filter at the ~8 sites, not-found page, folder control and pills, Everything exclusion, Health check row, sentinel crawl.
2. Photo addresses: code, single-photo page and route, overlay and viewer icons, New link, downloads switch, Referrer/noindex.
3. Gallery of its own and the entry navigation.
4. Public log: Came in at, Not listed tag.
5. Docs (guide, changelog, upgrade note, README), version 2.6.3, full chain.

## 12. Rollback anchors
- Release **v2.6.2** (main `764e4b2`) is the last good state; `main` is untouched until the owner says "cmpr".
- Work happens on `v2.6.3-dev`, one commit per step. A bad step: `git reset --hard <last good commit>`; a bad build: delete the branch and restart from `main`.
- Restore-point tarball before work: `~/backups/gallery-2.3.3-dev/34-before-not-listed.tgz`; one more per step (35, 36, ...).
- This plan, the mockups and the session notes are outside git (this untracked file, `~/backups/gallery-2.3.3-dev/context/`, and the memory note `project_plan_standalone_folders.md`).

## 13. Not included (parked)
Folder-wide "new addresses" button; per-link Cards/Flat (see `docs/ROADMAP.md`); a short `/photos/CODE` address form; a "treat this folder as an album" switch deeper than "Gallery of its own".
