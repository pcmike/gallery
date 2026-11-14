# Plan: the Changes tab (a log of disk activity). FINAL AGREED SHAPE, 2026-11-09. NOTHING OF THIS IS BUILT. Build waits for the owner's "go".

Replaces the phase 3 "changed elsewhere" bars of PLAN-live-refresh.md. The owner's reasoning: the gallery has one admin, so captions, names, marks and
settings changes are things they do themselves and already know. What they may NOT know is what happens on disk without them (Syncthing and other
automation adding, removing or renaming media). Those are not urgent, so: no bars that nag, a number on a tab and a log to read at leisure.

Mockups (all sent to the owner; in scratchpad/proto/ and the backup dir): changes-tab-mockups-4.html (the tab, counter, pill, marks, as planned),
changes-chips-mockups-2.html and changes-cards-working.html (the card text; the working one is clickable). Real-photo crops real-*.jpg embedded.

## 1. The Changes tab
- A sidebar tab right after Visitors (also in the phone Menu and the desktop shell sidebar). Needs SQLite, like Visitors. Stored in the SAME SQLite
  file as the visitor log (new tables; the file then exists on every install with SQLite, not only when Visitors is on). The docs must say plainly:
  "Changes is the log of disk activity".
- Entries, newest first, grouped by day, text and a link (no thumbnails: a thumbnail may not exist yet for brand-new media): media added, removed,
  renamed or replaced, a new folder, a folder that is gone. The word is "media" (photos and videos). Added media gets its real arrival time (inode
  change time) and its names (up to 200 per entry, then "and N more"); removals and renames get the time they were noticed.
- Labels ADDED / REMOVED / RENAMED / FOLDER; an amber edge on unseen entries.
- Entries EXPAND: all names, the arrival time range, the folder link (opens the folder with the new media marked), a link per file while it exists.
  A renamed entry shows the files gone and the new ones side by side. RENAMES ARE NOT PAIRED (decided: pairing by size+date would need size and date
  of every file in the snapshot, about twice the storage, and a baseline built in batches; not worth it for how rarely photos are renamed. It can be
  added later without redoing the design).
- Header: ONE pill "All kinds ▾" (default) opening a multi-select list like the Visitors pills (check marks, counts: Added, Removed, Renamed, Folders;
  Done button; bottom sheet on a phone). A TEXT button "Trim the log…" beside it (NO scissors icon). The search bar (matches folder and file names)
  sits UNDERNEATH.
- Retention like Visitors: setting *Keep change records for (days)*, default 0 (until trimmed); Health warns past 20 MB (measured on the Changes
  entries, not the whole file); Trim in the tab.

## 2. The counter (on the tab, sidebar and phone Menu)
- The number of UNSEEN entries, amber (kind 'warn': it needs a look, not action).
- Opening the tab marks everything seen: the number goes grey (same batch kept), and disappears 24 hours after the tab was opened.
- New entries make it amber again, counting only the new ones. Opened with nothing new: no number.
- Store: when the tab was last opened and how many were unseen then. ONE shared "last opened" time; no per-entry seen flags.

## 3. Where else it shows (NO ribbon: dropped)
- THE PILL (a small notice at the bottom of the gallery page, signed-in owner only):
  - Shows in the folder you are looking at, INCLUDING a folder that only holds folders (the page of the screenshot that started all this); covers
    that folder and everything below it.
  - Names each folder that changed, each a link: "random: 1 new media; Sunrises: 3 renamed."
  - Directly in the current grid, adaptive wording: "2 new media here" / "1 removed here" / "2 added, 1 removed here" / "Media here was renamed".
  - REFRESH appears only when the current view would change (media here, or a folder added/removed at this level). Changes only below this level:
    links plus × (no Refresh). Refresh reloads the page and marks the new media.
  - × dismisses it. Dismissing does not clear anything else.
- THE MARKS: a folder link (from the pill, the tab, or the card text) opens that folder with the new media ringed in amber with a small "New", and the
  page scrolls to the first. They stay until you leave the page or reload it fully; acting on one photo through an overlay does not clear them. The
  address is cleaned (history.replaceState) after load so a reload does not mark again.
- THE CARD TEXT: on a folder card's count line (.folder-cover-count, src/php/055:332-335), owner only, plain amber text in the style of the red
  "(3 private ›)": "(+1 photo ›)", "(+1 folder ›)", "(+2 photos, −1 ›)", more than two kinds "(changed ›)"; a link to the folder with the new media
  marked. NOT a chip. It copies the .fc-priv rules (padding 2px 4px, radius 6px, transition; hover: white text on a tinted background .34; pressed
  .52; keyboard outline) with the colour swapped to amber. The CHEVRON STAYS (on the private count and on the amber one): it is what says "pressable"
  on a phone, where there is no hover. A rename does not change a count, so a card shows nothing for it (the pill and the tab mention it). On narrow
  cards it wraps onto its own line; that is acceptable. It goes when the Changes tab is opened (same moment as the counter turns grey). The pill's ×
  does not clear it. It is drawn when the page loads and updated without a reload when the window comes back (the pill's check gives the per-folder data).
- CONTRIBUTING.md already says (uncommitted edit): count links on a folder card are small coloured text in the count line with a chevron and the
  .fc-priv look and feel; a new count of this kind copies those rules and changes only the colour.

## 4. How a change is noticed (no background process exists)
- Every request already scans the gallery and has `$files` (relative paths of all media) in memory. The check groups it by folder, sorts each folder's
  names, fingerprints them (count + md5 of names) and compares with a small snapshot kept in the log file (one row per folder; the snapshot stores the
  NAMES, owner agreed, about 15 bytes per file). Equal everywhere: stop, write nothing. A folder differs: look at that folder's files (inode change
  times) to say which are new, write an entry, update that folder's row. Only changed folders are rewritten; the check stays fingerprint-first.
- Measured (synthetic, same operations): 700 files 0.2 ms; 10,000 files 4.5 ms; 50,000 files 11 ms; 100,000 files (1,500 folders) 49 ms, 26 MB; 300,000
  files (5,000 folders) 150 ms, 78 MB. Grows with files, not folders.
- Runs on every admin request and when the admin window comes back; on a visitor's request at most once every minute or two (cheap marker). To be
  measured inside the real code before the visitor limit is settled.
- First ever check: a baseline is recorded silently (no flood of "added"). Names only, so it is one cheap pass. The baseline is also retaken silently
  when something that changes what is scanned is altered (places from elsewhere added or removed, the subfolders switch, and so on): keep a "scan
  signature" in the snapshot.

## 5. What COMES with this work
1. Tables in the visitor log file: the entries (time, kind, folder path, count, names), the per-folder snapshot (with names), "tab last opened" + count.
2. The check + the false-alarm guard + the first-run baseline + the scan-signature retake + the visitor-request throttle.
3. The tab (kind pill, Trim text button, search, expandable entries), the counter (amber/grey/gone), the two settings (days, warn MB), the Health row,
   Trim; sidebar, phone Menu and shell wiring.
4. The pill, the marks, the card text (+ live update on window-back).
5. Docs section ("Changes: the log of disk activity"), changelog, the CONTRIBUTING line, tests (the check on fixtures that add, remove and rename files;
   first-run baseline; scan-signature retake; the tab and its filters/search; the counter states; the pill's wording and when Refresh shows; marks;
   card text; retention and the Health warning).

## 6. What GOES with this work (tracked so nothing dead is left behind)
All of this was added by live-refresh PHASE 3 (commit b16d553) and its uncommitted follow-ups, and is NOT needed once the tab exists:
- src/js/046-admin-page-script.js: the whole "Something changed somewhere else" IIFE (reads #live-fp, ask(), hide(), show(), describe(), gallery(),
  sentence(), register('changed'), window.__live.rebase and the window.fetch wrapper on '?admin' POSTs).
- src/php/045-admin-page-2.php: the `<div id="live-fp" ...>` embed.
- src/php/027-gps-and-admin-folder-helpers.php: live_record_fp(); in live_fingerprints() the 'settings' and 'record' keys (and their comparison). KEEP and
  reshape the per-folder 'folders' map, 'at' and the since/'fresh' part: they become the Changes check.
- src/css/044-admin-page-styles.css: `.live-note` and `.live-note a` (the admin top bar).
- Tests: tests/run.php "[changed elsewhere]" checks about settings, record fingerprints and page-embedded fingerprints; the browser block's admin bar
  checks (settings bar, Dismiss, "no bar after the page's own save", the admin bar's folder link). Docs: the "changed elsewhere" paragraph in
  docs/guide/admin.md and the matching CHANGELOG sentences.
- src/js/080b-gallery-script-live-folder.js + `#live-folder` marker (061) + `.live-note-fixed` and `.live-new` CSS (060): REWORK (not delete) into the
  pill and the marks above. (080b must stay BEFORE src/php/081 in the manifest: 081-083 are inside a conditional for share-link viewers.)
- The endpoint ?admin&changed=1 is reshaped around media only (route in 051, reserved word in 002).
- Never built after all: the ribbon (CSS, markup, tests). Nothing to remove.

## 7. What STAYS (live-refresh phases 1, 2 and 4: committed and pushed; the owner called them useful)
window.__live (046), the counts part, the Visitors panel refresh (?admin&visits=1&tab=info), the Shares list refresh (?admin&linkspanel=1), Browsers
signed in (?admin&browserspanel=1), Prepare media's numbers (action media_panel), the shell's visibilitychange refresh, the phone Menu's Visitors number.

## 8. Order of work (commit and push between each; save context as I go; NO TESTS until the owner says; suite chain only per batch / on cmpr)
1. Remove what goes (list in 6). Most of the uncommitted, untested work in the tree is in this list: do this first, it saves testing it.
2. The log: tables, the check, the guards, the baseline.
3. The tab, counter, settings, Health row, Trim.
4. The pill, marks, card text.
5. Docs, changelog, tests, the full chain one suite at a time (server 8.4, JS, browser, health; fuzz/xss and PHP 8.3/8.5 later). Releasing waits for the
   owner's cmpr.
If credit is short: stop after 3 (tab + counter is the useful part); the pill and card text can follow.

## 9. Separate small jobs waiting for the owner's go (not part of the Changes build)
- TAB FLASH (reproduced 2026-11-09, owner's screen recording): on every admin page load, until the script adds `.js-tabs` (046 ~line 755), ALL
  `.tab-panel` are displayed stacked (Features first; in the recording the Features list shows, then the page snaps to Settings). ~45 ms on a local
  server, longer on a real one. Hits Check again (which is `location.reload()`) and F5 alike. Fix: hide the inactive panels from the start (CSS /
  an early inline class), so only the active tab is ever drawn. A verified bug, not a guess.
- CHECK AGAIN refresh in place: today the button is `onclick="location.reload()"` (036 line ~58): the whole admin page reloads because the rows are
  built in PHP (render_admin_health_panel). In place = a small endpoint returning the panel + swapping it + re-running the browser probes (same
  pattern as media_panel / linkspanel). Do the flash fix first and see whether the awkwardness is gone.
- VISITORS EXPORT ICON (fixed in the source, UNCOMMITTED, UNTESTED): the icon was squeezed to 12 px wide by the button's padding; now
  `ui_icon('download')` at 22 px with `.vt-ic{padding:0}` (src/css/044, src/php/035). Needs a CHANGELOG line when committed.

## 10. Open
Nothing on the Changes design. Waiting for the owner's "go" (and a yes/no on the two small jobs in 9).

## History of decisions (2026-11-09, in order; the sections above are the result)
mockup rounds 1-4; ribbon asked for, made rising/see-through/bolder, then DROPPED; pill wording confirmed; entries expandable; names stored; kind filter
became one multi-select pill; search added; Trim became text (scissors dropped); rename pairing considered (measured: stat of 50k files 220 ms,
names-only check 11 ms) and NOT done; pill Refresh only where the view changes; card text as amber plain text like the private count; chevron stays
on both; CONTRIBUTING line added.

## 11. Small items added 2026-11-10 (owner)
- EDITED TAB LIST LIVE REFRESH: DECIDED NOT TO DO (owner, 2026-11-10: nobody waits on the tab for its contents to change; the value on the sidebar updating is enough). Done instead: the sidebar numbers follow an edit made on a gallery page inside the shell (the frame's gallery page now sends the shell a tick after owner actions; 039 shell_frame_script('gallery')).
- DONE 2026-11-10 (uncommitted): the Edited tab's folder summaries now count new addresses ("1 new address").

## 12. GO GIVEN (owner, 2026-11-10) and last decisions
- "May as well go on it all": BUILD the Changes tab now, in the order of section 8, committing and pushing between steps. Credit is not a constraint this month (Max), so run the full chains after each batch.
- TAB ORDER (decided, do it with the Changes build): Features, Collections, Edited, Shares, Visitors, Changes, Settings. Written in THREE places that must all change together: src/php/045-admin-page-2.php (the page's own sidebar), src/php/039-shell-helpers-1.php shell_whole_nav() (shell sidebar) and owner_phone_bar()'s $whole (phone Menu); some tests check the order.
- Collections tab: always shown while Show subfolders is on (DONE 2026-11-10, with an empty state and the New collection box).
- Tabs hidden when: Edited until something is private/set by hand; Shares if share links are off; Visitors unless SQLite works and (share links on, public logging on, or visitors logged); Collections only if Show subfolders is off; Features and Settings never; Changes needs SQLite like Visitors.
- Progress log for the build is kept in docs/share-links/BUILD-PROGRESS.md (untracked) and the memory note project_changes_tab_plan.
