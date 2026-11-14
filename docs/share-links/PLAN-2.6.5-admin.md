# 2.6.5 admin changes other than collections (decided with the owner on renders, 2026-11-07; NOT BUILT)
Untracked on purpose (like the other PLAN files). Render script: scratchpad `shots/mock2.py` (modes all|h|i|g|j) and `mock.py`.

## Settings > Videos and thumbnails > Prepare media (regrouped)
1. Status line + "Prepare the rest" / Stop + progress bar (unchanged); "What the gallery knows about each folder" report.
2. **Moved in from another gallery** (shown only when something is found):
   - **Earlier views**: "4,210 earlier views found for 38 folders, counted under their old addresses." Button **Add earlier views to the counts**. Copies old per-photo counts into THIS gallery's own rows (per-folder `.gallery-stats.sqlite`, table photo_views_h; candidate keys = the photo's path as the old gallery saw it, shorter suffixes of the new path; looked up only in the photo's own folder store, so no cross-folder misattribution). Old rows never changed. Once per folder (record it so a rerun cannot double-count); cannot be undone; afterwards "Added 4,210 views to 38 folders." Not carried: the front-page visit counter (lives beside the old gallery's index.php). NO per-folder Views section or toggle (owner: a toggle makes no sense).
   - **Tidy leftover thumbnails**: count + MB + per-folder list first; only exact gallery-made thumbnail/poster names; never meta1.php/taken3.json/other files; the Remove button stays disabled until the tick box "No other gallery still shows these folders (the old one is retired)" is ticked (not stored; asked each time).
3. **Tidy up**: Tidy up saved locations (only while copies/refreshed/marks/unread > 0) and the backups line + "Delete the backups…" (only while backup files exist).
4. Read everything again (last).
- The report gets an **Earlier views** column ("412 found" amber, "412 added" after the button, "—" none), shown only while some folder has earlier views. Shortened headings to fit: "In the files" (was Locations in the files), "Dates" (was Capture dates), "Saved" (was Saved in the folder's data).
- Never touched by the new gallery: the old install's own files (index.php, config, links, passwords). The guide tells the owner to remove that folder by hand once the old gallery is retired.

## Settings > Sharing and access
- New **Scrub location data** switch under "Let visitors download originals", default ON, no per-folder override; HIDDEN (not greyed) while downloads are Off (the page's existing data-requires pattern). Help: photos visitors download have their GPS taken out first, from the viewer and from a Not listed photo's address; you, the private-view password and share links set to show all locations always get the untouched file.

## Health check
- A row when a mapped place's own folder is not writable (not rendered yet; one line in the existing list).

## Folder settings
- See PLAN-collections.md section 14 (reorder, Locations section, wording).

- Scrub upgrade effect (owner OK'd 2026-11-07, mention in the changelog): a gallery that already allows public downloads and shows locations starts stripping GPS from those downloads on upgrade.

## Pre-build checks, settled 2026-11-07
1. **Collection address**: `?folder=<parent>/<name>` stays the address (the tidy server rule is unchanged). The collection is recognised at the one point where an unknown folder would answer 404 (052-view-stats.php ~455, `empty($currentFolderFiles)`), and is rendered by its own code; `$currentFolderPath` (about 50 uses) is never given a virtual path. `?archive` and the other folder routes answer 404 for a collection address.
2. **Scrub**: the one hook is `visitor_scrub_plan` (005), used by every original sent to a visitor (full-size view `?img&size=full`, `?original`/download, video playback, a Not listed photo's address). Types served: jpg jpeg png gif webp bmp heic heif mov mp4; all are patched except gif/bmp, which carry no location; thumbnails, tiles and video clips are re-made with metadata stripped. New rule with the switch ON: scrub for every visitor except those for whom `viewer_sees_all_locations()` (revealed, link with all locations). Cost: a GPS-bearing original is then streamed by PHP (patched) instead of handed to nginx (X-Accel); a photo with no GPS still goes the fast way.
3. **Renaming a mapped place's label (Eurotrip)**: survives: share links, collection members, Not listed photo codes (all by folder hidden id), captions/private marks/titles (in the folder's own items file). Changes: every folder and photo address (`?folder=Eurotrip/...`, `?photo=Eurotrip/...`), view counts (keyed by the path as seen; the old rows stay but "Add earlier views" cannot find them because the old label is unknown), thumbnails (named from the path; rebuilt, the old ones left in the mapped place until Tidy leftover thumbnails). So: pick the label once, before sharing.
4. **Unwritable mapped place**: saves already say "Nothing was saved: this folder isn't writable by the web server. Give the web server's user write permission on it (...), then try again." (`data_write_failure_text`, 010). Small improvement for 2.6.5: name the real path for a mapped place. The Health check only tests the top folder and folders that hold media, so a mapped place with only subfolders is never tested: add the Health row.
