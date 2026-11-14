# What the owner needs to check (built overnight 2026-11-10 -> 11-11; nothing merged, tagged or released: no "cmpr" was given)

Kept up to date as the work lands. Final version is in the report I leave at the end of the run.

## Before anything: copy index.php to /p  (commit 10cb498, pushed)
(branch v2.6.5-dev; I never deploy)

## Things to look at on the real gallery
1. Settings -> Health check: new layout (numbers strip, "N things to fix · N suggestions", Details button).
2. The two web-server rows ("visitor log" and "backup" can't be fetched by their address): green on your nginx? If red, the .gallery- rule is not covering them.
3. Backup: first visit after copying makes one by itself; Health -> Database and backup -> "Backup: made ..."; press Back up now; Download (admin password) and open the ZIP (README, MANIFEST, your settings/links/captions inside; no photos).
4. In about a week: did it refresh by itself ("the weekly one")?
5. IMG_8919.HEIC in the viewer: in Safari you should now get the HEIC itself (full size, true colours); in Chrome/Tesla the converted JPEG, now more colourful (profile kept) and up to 4096 px. Compare with Immich.
6. Thumbnails are made again once (the cache names changed so profile-less pictures aren't reused): first visit to each folder is slower; Prepare media shows "not prepared" until they are made.
7. Left behind -> leftover thumbnails: if the row shows, Set aside again once (old note format is ignored by design).
8. Safari HEIC original: orientation (rotated phone photos) and, as a visitor, the location blanked. I can't run Safari here: server side tested (bytes), not visually.

## Not tested on real data (only on copies)
Restore the log / Set aside and start a new log: tested on damaged copies, never on your real log.

## Decisions / opinions wanted
- HEIC "full" cap raised 2400 -> 4096 px (JPEG for non-Safari): ok?
- Heads-up rows (amber ring !) show without opening Details: ok?
- Offer still open: one-page soak-test checklist.

## Added 2026-11-11
- Health strip: "Disk free" is now the lowest across every disk your folders are on (mapped ones too, read-only too). With more than one disk the chip has a small arrow: tap it for the list. Check it on your real gallery with the NAS / mapped folders, on desktop and phone.
- Lockouts row: just the heading (with a short breakdown only when more than one kind) and the Visitors link.
- PHP memory row: on your server (Imagick present) the "memory limit is low (64M)" suggestion should be gone. The gallery now asks for 256M itself before GD resizes; the row appears only with no Imagick, GD resizing and a refusing server.
- "How to fix" folds: look at them on your real gallery (Settings → Health check → Details). They appear on rows that need a server change only. Check the nginx/Apache snippet on the visitor-log and backup rows if either is red, and that Copy works on the phone.
- A bug fixed on the way: the backup reader logged a warning on every backup (harmless, wrong array key).

## Added 2026-11-11 (quick-pass items)
- Health strip: Links is green for in use / red for the rest, Folders includes collections, Backup reads like "17h ago", Files this gallery has made has no subtitle.
- Changes tab: the All kinds list now matches the Visitors tab's lists (desktop and phone).

## Web server rules (one section instead of seven blocks)
- Settings → Server setup → **Web server rules**: one line of chips (private photos, tidy folder links, tidy share addresses, nginx redirect) and one fold each for nginx and Apache. Your server (nginx) should be first, tagged "looks like yours".
- The nginx block on /p: compare it with what you have in the site now; the "needed" and "optional" parts are marked, and the whole block sits between the begin and end lines. The Apache block has the rewrites inside an `<IfModule mod_rewrite.c>`.
- The redirect setting no longer has "Show the rule"; it points to the block. The Edited tab's warning, the Health check's rows and the Features list "Open the rules →" all go to the section.
- On a phone: the chips stack, the fold title and the "looks like yours" tag, and the Copy button sits above the code.

## Share link: folders left out of a folder
- New link → Only some folders → Add folder: tick Eurotrip; its folders tick too. Untick Rome: Eurotrip shows a dash, and the chip says "without Rome". Done, save, open the link in a private window: Rome is not there, the rest is.
- Edit the link: the picker shows the same ticks. Press the dashed Eurotrip: everything ticks again.
- A Not listed folder inside Eurotrip is not ticked for you (tick it yourself; it asks first).
- Downloads / ZIP "Only some folders": the folders inside a ticked one show ticked and fixed.
- Going back to an older gallery version would show a left-out folder again (noted in the changelog).
