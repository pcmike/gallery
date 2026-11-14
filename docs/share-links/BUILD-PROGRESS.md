# Build progress: the 2.3.3-dev overnight pass (started 2026-10-30)

**Resume here after any interruption.** The owner went to bed after saying "go on everything without me". Nothing is committed (owner's CMPR only). Branch `v2.3.3-dev`. The list lives in `FIXLIST.md` ("Queued"). The mockup is https://claude.ai/artifact/1iMDLkD32E6kH13TPynmAi (files in the scratchpad under `mock/project/`; boards: New share link, Links overview, Settings page).

Rules in force: never edit `index.php` while a browser run is going; run suites one at a time (`(ulimit -v 4000000; timeout 550 php tests/run.php)`; no `ulimit -v` for browsers; check `pgrep -c chrom` / `pgrep -c php` are 0 after); a test per item; update CHANGELOG, README, DECISIONS, PHONE-CHECKLIST, FIXLIST together; version stays 2.3.2 in `index.php` until CMPR (bump to 2.3.3 then).

Memory guard (owner, 2026-10-30): the scratchpad `chain.sh` now waits for >= 2500 MB available and no leftover chrom/test processes before every suite (gives up after 10 minutes and says so), runs each suite under `nice`, and sweeps `/tmp/gallery-chromium-*` after each. For single runs by hand: `free -m` first, one suite at a time, run long ones in the background and watch with Monitor.

## Stages (mark [x] when built AND tested)
- [x] **A. Skeleton script** `tests/tools/skeleton/make-skeleton.sh` (read-only; owner will run it on their host with their mapped folders and send the tarball here; tested on a fixture, gallery checksum unchanged). NOT yet done: `README` for it, the fixture builder that rebuilds a tree from a skeleton (`tests/tools/skeleton/build_fixture.py`).
- [x] **B. Downloads model**: built, server-tested, browser-tested.
- [x] **C. Locations wording**: built and tested (form, paragraph, preview line, folder switch, quick popup).
- [x] **D. New/Edit link form**: built and tested (server + browser).
- [x] **E. Links overview**: built and tested.
- [x] **F. QR opens large at once**: built and tested (the rest of the kit unchanged).
- [x] **G. Settings page**: built and tested.
- [x] **H. Files this gallery has made**: built; server tests with decoys + checksums + fail-closed + static code checks; browser checks of the dialogs; `tests/tools/skeleton` round trip passes on a synthetic skeleton. NOT release-safe until run on the owner's real skeleton.
- [x] **I. Tests**: whole chain green on the final code (2026-10-30 ~01:50): server 8.4 1,675 / 8.3 1,671 / 8.5.8 1,671 passed 0 failed; JS ALL PASSED; fuzz 7,411 requests 0 problems; XSS 0 findings; QR ok; nginx+Apache rig 47/47; browser ALL PASSED (798 ok); video ALL PASSED. PHP 8.4.27 is patched; 8.3 and 8.5.8 static builds are the unpatched ones.
- [x] **J. Docs**: CHANGELOG, README, DECISIONS, PHONE-CHECKLIST, FIXLIST (moved to Fixed), skeleton README, tests/tools/README written.
- [x] **K. Morning report**: at the end of this file.

## Decisions taken while the owner slept (list them in the report)
- The gallery-wide *Let visitors download originals* stays on/off (strangers see only public photos, so Everything = on); a folder list for strangers is not built.
- Existing links: stored downloads/zip modes off / all / some keep their names and now mean Off / Everything / Only some folders.

## Morning report (for the owner)
Everything on the list is built on `v2.3.3-dev` (nothing committed, version constant still 2.3.2). Whole chain green (see I above).

**Needs you:** (1) run `tests/tools/skeleton/make-skeleton.sh` on the server that holds the real gallery (give it every mapped folder) and send the tarball; I then run `build_fixture.py` on it. Until that passes, do not use Delete on the real gallery (the list and ZIP are read-only and safe to try). (2) The phone checklist (`PHONE-CHECKLIST.md`, "The new-link form, the list, the QR code and Settings (2.3.3)").

**Decided for you while you slept:** the gallery-wide *Let visitors download originals* stays on/off (no folder list for strangers); (changed 2026-10-30 after the owner objected: Prepare media now appears the moment Thumbnails is switched on, and the ZIP has no size cap: data files are copied to temporary files on disk, not held in memory); Delete leaves `index.php` and `.gallery-setup`; a link set to downloads Off no longer gets public downloads from the gallery-wide switch (said in the changelog).

**Not built:** select-photos for downloads; restoring from the page (unzip by hand); the quick *Share this folder* popup keeps its short form (only its defaults changed); no confirmation before the Clear button.

**2026-10-30 evening, the owner's real skeleton (gallery folder + 5 mapped folders via EXTRA_ROOTS):** `build_fixture.py` first reported PASSED while scanning only the gallery folder (it read `EXTRA_FOLDERS` but not `EXTRA_ROOTS`), so the mapped folders were never tested. Fixed the tool (mounts now built the way `gallery_mounts()` builds them; the restore step now sends a mapped folder's files back to that folder). Re-run: plan 64 files + 2,169 thumbnails in 6 folders (all 64 gallery files the skeleton lists), nothing left alone, delete removed only planned files, every other file byte for byte unchanged, and unzipping the copy restored the tree byte for byte: PASSED. `index.php` was not changed. `gallery-skeleton-*.tar.gz` is now git-ignored.

## Third pass (2026-10-30 evening, owner said "go"): the queue in FIXLIST.md "Queued"
Backup of index.php before this pass: scratchpad `index.before-go3.php`. Order and state (tick as done; every item gets a test):
- [x] S1 small (done, untested as a whole; tests added: `[reveal address]` x2 in run.php, `[name field]` x3 in browser_checks.py, `tests/tools/skeleton/selftest.py` passes 3/3): `/reveal` typed address; name-field row in Settings (placeholders, left text, Remove inline, no default line); skeleton tool uses the real `gallery_mounts()`; PHP chain = 8.4 only (chain.sh no longer runs 8.3/8.5; README wording)
- [x] S2 pin jump (built; standalone check passed 20/20, ported to browser_checks.py as "pin jump"; not yet in a full chain run): ring + centre for both maps (little map stays open, full-screen closes first), "Back to the map" pill (10 s, rules in FIXLIST)
- [x] S3 Settings declutter (built and tested: groups restructured, requires chain + cascade, merged Photo order `pair_PHOTO_ORDER`, "default:" only when differs (data-default + refreshMeta), shorter help, closednote, Prepare media/Tidy/Edited texts shorter, log footnotes folded, rule blurbs fold their detail, row hover, `details.fold` chevron (24 plain details got class `fold`); STILL TO DO: update the old tests that name groups/help text, new tests for each, Folders-group tag): A1 dependent rows hide, A2 "default:" only when different, A3 Photo order + Last first one control, A4 IP source to Server setup, A5 "Visitors and logs" group, A6 Prepare media tools shorter, A7 log footnotes folded, B shorter help texts; U5 row hover; U6 one chevron for every `<summary>`
- [x] S4 Admin UX (built and tested: Health check 'opt' status + optional rows grey + chevron toggle + "Fix this →"; Edited banner folds the rules (`details.lock-fix` inside `#lock-banner`); header has no crumb/tabs, sidebar `.side-group` with label, folder head `#admin-folder-head` (tabs) shown only on folder tabs; phone: side group is one segmented row): U1 Health check (red only for real problems, optional grey, one line when fine), U2 Edited banner folds the rules, U3 sidebar label "The whole gallery" + folder tabs under the folder heading only, U4 phone admin
- [ ] S5 docs (CHANGELOG, README, DECISIONS, PHONE-CHECKLIST, FIXLIST to Fixed), whole chain (8.4 only), memory
- [ ] AFTER the build, owner asked for: (a) walk through the drop-in experience ("someone drops index.php in a folder of photos": make it frictionless), (b) revisit the size of the single file (index.php ~1.3 MB, ~19.7k lines) and whether to act now, (c) revisit the phone checks.
Mockups (artifact 1iMDLkD32E6kH13TPynmAi v39): Folders, Pin, SettingsNew (desktop + phone), Admin (desktop + phone). Folder tabs only appear once a folder is picked (owner did not object).
Rules: never edit index.php while a suite runs; one suite at a time; chain.sh has the memory guard; no `ulimit -v` for browsers; check chrom/php/leftover temp dirs after browser runs.

**State at this point (third pass):** S1-S4 built; server suite on 8.4 was 1,714 passed / 3 failed (test-logic only; fixed, rerunning); browser_checks.py updated for the new header/groups/health/declutter but NOT yet run. Remaining: run browser (+ fix), whole chain, docs (CHANGELOG, README, DECISIONS, PHONE-CHECKLIST, FIXLIST Queued -> Fixed, tools README for skeleton selftest), then the three follow-ups the owner asked for (drop-in walkthrough, file size, phone checks).

**Later the same night:** server suite on 8.4 1,717 / 0 (twice, after the last test fixes); browser 844 ok, 1 test-side failure (the hover rule check, fixed to read the stylesheet because the run leaves touch emulation on). Found and fixed by the browser checks along the way: Log out hugging the title (a later CSS rule overrode `margin-left:auto`), the `#map` landing moved by a scroll margin I had added, switches no longer in one column once "default: …" could disappear (it now keeps its room and is only invisible), nested rows (pin grouping under the map under locations) at the same indent (now `depth-2`). Cleaned up after a crashed browser run left a PHP server behind; browser_checks.py now stops every server it starts, even on a crash.
Still to do: the whole chain on the final code, memory update, then the three follow-ups (drop-in walkthrough: `scratchpad/dropin.py`; file size: `scratchpad/sizecheck.py`; phone checklist: DONE, restructured into Start here / Older checks).

**Final chain started on the finished code** (index.php backup: scratchpad/index.stage5.php). Drop-in findings (see the owner report): fresh install shows a red "1 thing to fix" for the lock check with nothing private, "No subfolders" when subfolders exist but are hidden, a setup notice that tells a first-time owner to edit a constant, a blank 404 for ?admin once the 5-minute window has passed; the Health check's "Fix this" now goes to Settings → Server setup (the Edited tab may not exist yet).

**FINAL CHAIN GREEN (2026-10-31 early morning, 8.4 only):** server 1,717 / 0; JS all passed; fuzz 7,411 requests 0 problems; XSS 0 findings; skeleton self-test 3/3; QR ok; nginx+Apache rig 47 / 0; browser 845 ok (1 skipped); video all passed. After: no chrom/php processes. Not committed; GALLERY_VERSION still 2.3.2. Waiting on the owner: decisions on the drop-in findings (R1-R5 in the report), the phone checks (Start here), the real-gallery trial of Delete (their call), and "cmpr".
Hygiene found: /tmp held ~12,000 leftover test files and ~220 MB of old test folders from killed runs (swept: anything older than two hours).

## Fourth pass (2026-10-31, owner said "go for it"): the 17 queued items in FIXLIST.md
Backup of index.php before this pass: scratchpad `index.before-go4.php`. Stages (tick as done; every item gets a test; one suite at a time; PHP 8.4 only):
- [x] A setup (built + server tests written, not yet run): `.gallery-setup` 15-minute limit, honest banner (bold, centred, blank line), live countdown "N minutes N seconds", "Time's up" state; mentions in Settings/Delete/README follow
- [x] B first run in the admin (built + server tests written, not yet run; browser tests for Show subfolders button TODO): lock check grey until something private (plain words, Fix this button), heads-up rows for every setup notice (hidden subfolders, notes without {gallery}, ...) always visible and not counted, sidebar shows hidden folders greyed, notices rewritten without constants; Show subfolders one-click
- [x] C Settings (code written; tests being updated): controls flush right ("default: x" into the "your setting" tag), modern menu button (no native select) for 3+ choices (Photo order, IP source, visitor downloads), one button look, Expand/Collapse all (only the one that can act), Map switch removed (legacy ENABLE_MAP), Export tab removed (downloads under the ZIP), switches say how much in both states, Folder settings location notes + jump link, Prepare media wording and fold
- [x] D Features page (code written: sidebar tab, panel, ?show= pointer, fresh install lands there via localStorage; tests TODO) (no switches, two sections, Show me pointers, fresh install lands there)
- [ ] E Select mode keeps the Private pill + cover star (CSS done, test to write)
- [ ] F docs (CHANGELOG, README, DECISIONS, PHONE-CHECKLIST, FIXLIST to Fixed, LEGACY-REMOVAL for ENABLE_MAP), whole chain, memory
Rules: never edit index.php while a suite runs; no `ulimit -v` for browsers; kill leftovers by PID; never put a sweep pattern's text in my own command line.

Stage C progress (not yet tested): done in code = C1 chip instead of meta + rowstate takes no room (controls flush right), C2 `ui_menu` (radios inside a styled popover; used for Photo order and every enum with 3+ choices), C5 Expand/Collapse all (Settings + Edited, only the one that can act), C6 Map switch removed (`ENABLE_MAP` = locations; legacy key understood, dropped on save), C9 folder-settings notes (jump link in the label, two location notes, no `ENABLE_PHOTO_LOCATION ... index.php` text, Prepare media sentence only when unread). TODO in C: button unification, Export tab removal, effects texts (both states, counts), Prepare media fold + wording; then tests for all of C.

Stage C/D code complete (button unification, Export tab removed + downloads under the ZIP, effects texts with counts, Prepare media folded, Features page + Show me). Next: run server suite, fix/extend tests, browser suite, docs, chain.

**Fourth pass state (2026-10-31):** all stages built, docs swept (CHANGELOG, README, DECISIONS, PHONE-CHECKLIST, FIXLIST moved to Fixed, LEGACY-REMOVAL, config example). Chain on 8.4: server 1,754/0, JS, fuzz 7,411/0, XSS 0, skeleton 3/3, QR, rig 47/0, video all green. Browser suite found one real bug (a fresh install's Features landing also caught `?folder=` and the Root link, so folder settings were unreachable): fixed so it lands on Features once per tab (`galleryAdminLanded`) and never with `folder=`; plus test-side timing (settle-based waits end early; countdown/smooth-scroll checks use full waits). Remaining: confirm the browser suite green, then tell the owner. Nothing committed; GALLERY_VERSION still 2.3.2.

**2026-10-31 (afternoon): STOPPED at the owner's request, no more test runs until they say go.** Last full green chain was before three late edits to index.php (QR Save image touchscreen guard [server suite ran after it: 1,756/0]; Show me folder links + Share row + pointer fallback [NOT tested]). The browser run in progress was killed by PID and its temp dirs removed. Queued items (FIXLIST.md): Show me on folder-only galleries (half built), Select/Share Show me (half built), blurb alignment (not built), modern folder chooser for visitor downloads (not built). When the owner says go: build the four, then the whole chain once.

## Fifth pass (2026-10-31, owner said "ok" to: build the list, hold the shell, remake the shell mockups from captures of the fixed pages)
Backup of index.php before this pass: scratchpad `index.before-fifth.php`. Items (a test each; one suite at a time; PHP 8.4 only; report at every gate):
- [ ] F1 Show me opens a folder with photos (half built; add browser test, already written in browser_checks.py section "Show me on a folder-only gallery")
- [ ] F2 Features: ONE "Act on many photos at once" row (drop "Share something privately"); texts mention sharing only when Share links is on; Share links row keeps "Turn on →"
- [ ] F3 Show me blurb: a tail aimed at the button, re-aimed on scroll/resize/image load (instant scroll)
- [ ] F4 Select bar: Make private only if a ticked photo is public, Make public only if a ticked photo is privately marked; Share… as today
- [ ] F5 Visitor-download folder chooser in the gallery's own style (no bare checkboxes, no Done button; saves as you tick)
- [ ] F6 Only-child folder: its own blue pill after the dot in the gallery page's path bar
- [ ] F7 QR Save image: share sheet only on a touchscreen (built; server test exists)
- [ ] docs sweep (CHANGELOG, README, DECISIONS, PHONE-CHECKLIST, FIXLIST) + whole chain + tell the owner
HOLD: the shell (decided design is in FIXLIST.md); remake its mockups from captures of the fixed real pages afterwards.
Fifth pass progress: F1-F7 written with tests (server 1,761/1 before the last test-side fix; browser next). Notes: F5 uses the Settings menu look (dl-menu/dl-opt), F6 path bar renders `nav.folder-pills[aria-label="This folder"]` for an only child and the old "no siblings has none" server test now says the opposite, F4 hides Make private/Make public/Folder when they would change nothing.
Fifth pass: code final (server 1,761/0 after the test-side fix; browser 880 ok, 1 test-side failure fixed: the Escape check now focuses the visible button). Whole chain started on the final code; results to follow in summary.txt.
**After the fifth pass (2026-10-31, NOT covered by a full run: owner said no long suites for small changes):** link form sentence case (CSS: `div.sl-grp` only), Shares tab hides the empty "public address" section when closed with no log; each verified by a real render, tests written (`[link form]` server + browser, `[closed]` server updated) but NOT run; the next chain run covers them.
**FIFTH PASS FINISHED AND GREEN (2026-10-31, PHP 8.4):** server 1,762/0, JS, fuzz 7,411/0, XSS 0, skeleton 3/3, QR, rig 47/0, browser 881 ok (1 skipped), video. Nothing committed; GALLERY_VERSION still 2.3.2. Waiting on: owner's checks on test.gal (PHONE-CHECKLIST "The fifth pass"), then the shell (hold: remake mockups from captures of the fixed real pages, framing test first), "cmpr" only on their say-so.

## The shell (started 2026-10-31, after the owner's "keep going"; desktop first, phone later)
Owner's design is in FIXLIST "One shell for the owner" (board v70 is the layout sketch on the REAL pages). Framing spike is done and recorded there.
**Build plan (each piece gets tests; `?admin` itself is NOT changed, the shell is added beside it):**
1. `?admin&shell=1[&folder=X][&tab=T]`: the shell page (inside `run_admin_route`, so the existing sign-in, throttle and CSRF apply, no new door). Header = path crumbs + Folder settings / Notes toggles (no label text) + open-on-its-own icon + Log out; sidebar = whole-gallery links (Features, Edited n, Share n, Settings) + the folder tree; main = ONE iframe.
2. Framed admin page: `?admin&folder=X&inframe=1#tab`: same page without its bar, sidebar and folder head; sent with `X-Frame-Options: SAMEORIGIN` + `frame-ancestors 'self'` ONLY for this flagged, signed-in request (everything else stays DENY / 'none').
3. Framed gallery page: `?folder=X&inframe=1` (owner only): hides its sticky bar, removes the Admin pill and the title link, keeps `inframe=1` on its folder links, tells the shell where it is (postMessage, same origin only).
4. An **Admin** pill on the owner's gallery page (desktop widths only, not in a frame) opens the shell on that folder. The phone keeps today's pages in this first slice.
5. Tests: server (headers, access, the framed pages), browser (the shell loads, navigation in the frame updates path and tree, tools toggle, other-origin frame refused, plain `?admin` still unframeable).
Status: see the checklist below as each piece lands.
- [x] 1 shell page  - [x] 2 framed admin  - [x] 3 framed gallery  - [x] 4 Admin pill  - [x] 5 tests (17 browser checks pass in `scratchpad/shell_only.py`; server tests `[shell]` written, run pending)  - [x] docs
The phone version is built (2026-10-31): the owner's bar at the bottom of the gallery page and of the admin page on a phone (`owner_phone_bar`), tests `[phone bar]` (server and browser). Dropped with the owner: the dirty-settings reload (the shell already loads the gallery fresh) and the classic admin tree's elbows (the classic chrome is going). Still open: check the admin's 800 / 1100 px breakpoints against the frame width at 1000-1400 px windows.

## 2.6.3 — Not listed / coded photo addresses / Gallery of its own (started 2026-11-05)
Plan: docs/share-links/PLAN-not-listed.md. Branch v2.6.3-dev from main (v2.6.2 = rollback anchor). Restore point: backups/34-before-not-listed.tgz.
- [x] 1 Not listed + owner-only filter (committed on v2.6.3-dev; server 2046, browser, JS, skeleton green; backup 35-notlisted-step1.tgz). Notes: stored private+unlisted; owner-only via viewer_may_see_item/viewer_may_download/archive_files_for_viewer/main list filter (009) in 'if (!is_admin_browser())'; link_allows_item 'all' excludes unlisted and link_folder_cover ignores picks above an unlisted folder; folder link redirect hides existence; own form class 'unlisted-form' (NOT instant-form: browser tests select the first instant-form as the location switch; the server test forbids 'instant-form flag-form').
- [x] 2 photo addresses (committed; server 2067, browser, JS, skeleton green; backup 36-notlisted-step2.tgz). Notes: grant is in viewer_may_see_item/viewer_may_download via ?k= + photo_pass_valid(); $photoPass (003) puts the page in single-photo mode (009 filter, 052 folder 404, img_url + passQ JS carry the code); salt = items[name]['addr'] set by action photo_address fresh=1; admin JSON whitelist now has query/fresh; folder flag allowOriginals via toggle_folder_originals; JS part js/080a-gallery-script-photo-address.js; icon 'linkoff'; one browser test ([files made] Show list) failed once from timing and passed on rerun.
- [x] 3 Gallery of its own + navigation (committed; server 2087, browser incl. real Chromium typed-vs-click, JS, skeleton green; backup 37-notlisted-step3.tgz). Notes: nav_top() in 018 (cookie gallery_top, Sec-Fetch-Site, registry key ownGalleries at the gallery root), applied in 052 (crumbs/pills) and 061 (back link); admin action toggle_folder_own_gallery + 'Navigation' subhead; browser test for [settings] Privacy now expects 'Privacy|Navigation'.
- [x] 4 public log Came in at (committed; server 2097, browser incl. real-Chromium arrival logging, JS green; backup 38-notlisted-step4.tgz). Notes: public_log_arrival() (019) -> galleryEntry in the page script (081); 082 sends it once as payload.a (and '?linklog'+passQ for a photo's own address); server validates in run_linklog_route and stores visitor.entry + an 'arrive' event; views in 035 (link_entry_html, column, timeline, CSV only for the public log).
- [x] 5 docs, changelog, README (badge 24k), upgrade note, full chain GREEN on the final build (server 2097, js, fuzz 7427 req/0 problems, xss 0 findings, skel, qr, rig 47, browser, video; backup 39-notlisted-step5-docs.tgz). One browser test ([files made] Show list) raced the daily update look on the one-at-a-time test server: fixed in the test (UPDATE_FEED_URL to a closed port). NOT merged/released: waiting for the owner's cmpr.

# 2.6.5 build (started 2026-11-07, owner said "go")
Restore point: ~/backups/gallery-2.3.3-dev/restore-2.6.5-start-20261107.tar.gz (whole repo incl. .git and the untracked plan files); transcript copy and the render scripts in ~/backups/gallery-2.3.3-dev/context/.
Plans: PLAN-collections.md (sections 9, 14, 15), PLAN-2.6.5-admin.md (incl. pre-build checks). Rule: no commit/push/release except the housekeeping merge, until the owner says cmpr. Back up (tarball) after each step.
Steps (tick as done):
- [x] 1 housekeeping (merge main done, 2.6.5, changelog fixed; baseline suite 2147 passed): merge main (2.6.4) into v2.6.4-dev, renumber 2.6.5, drop the thumbnail-sweep changelog line
- [x] 2 folder settings reorder + wording + Locations section
- [x] 3 link form prompt for a Not listed folder
- [x] 4 Scrub location data
- [x] 5 Prepare media regroup + Earlier views (button, report column) + Tidy leftover thumbnails (tick box)
- [x] 6 Health check row + save message names the real folder
- [x] 7 collections (data, public cards/page, admin actions + UI, tree, tab, select bar, own gallery, links picker/scope: done in code with tests; docs written, browser checks added) (data, page, cards, admin, links, select bar, tab, tree, nav)
- [~] 8 guide, README, changelog, tests written; FINAL full chain running/to run (first chain: all green except 4 browser checks, since fixed)
