# Health check as a dashboard (2.6.5 plan, written 2026-11-10; NOT built, waits for the owner's go)

Owner's brief: the Health check is a true health check and a dashboard for everything the gallery is and does, exhaustive, so there are no surprises later.
Actionable rows prompt the user, in context, with the fix next to the problem. Warnings, suggestions and plain information are told apart. One page gives the full picture.
Database: automatic weekly backup, ONE rotating copy; "Back up now" replaces it; the log is checked for damage before every backup (automatic or by hand).

## The tiers (what the count in the summary means)

| Tier | Mark | Counted | Meaning | Example |
|---|---|---|---|---|
| Fix | red / amber triangle | headline: "N things to fix" | something is broken or not shown right NOW | settings file unreadable, log database damaged |
| Suggestion | grey hollow circle | "N suggestions" beside the headline | works, but there is a worthwhile action | log over the size you set, leftover thumbnails |
| Heads-up | amber "!" in a ring | not counted | nothing to do, but worth a look | 6 lockouts in 7 days, closed gallery, a link ends in 3 days |
| Info | plain dot | not counted | a fact | 641 photos and 24 videos |
| Good | green check | not counted | checked, fine | PHP 8.5.12 |

The fold button says "Details" (no count). Every Fix and Suggestion row carries its own button or link right in the row (not only a link to a tab).

## What exists today (from the code, 035/036 + JS probes)

This install: version record, new versions, HEIC without Imagick, resize libraries, EXIF, visitor log (SQLite + size), change log (size).
Settings and passwords: settings file state/problems, admin + private-view password hashed, sign-in key, lockouts (7 days).
Folders and files: extra folders exist/readable, folders writable, data files read fine, photo/video totals, scan stopped early.
Signed-in browsers: address history size, country file edition. Videos: ffmpeg. Media: undated/unreadable/unread/differs/legacy/video failed, prepared or not.
Left behind: earlier views, leftover thumbnails, loose photos, copied folder ids, old visitor logs, tidy-up backups.
Web server (browser probes): private photos fetchable by address, tidy folder links, nginx redirect, tidy share addresses, visitor log not fetchable.

## Gaps found (nothing checks these today) and the new rows

### Database (visitor log + change log + lockouts; one SQLite file)
- Integrity: `PRAGMA quick_check` (3 ms on a 1.3 MB log; tested: damaged middle pages are INVISIBLE to today's check, a destroyed header is reported as a permissions problem). FIX when damaged.
- Honest wording: "not a database / damaged" is not "can't be written". Says what is lost (history only: links, settings, captions are in other files).
- Schema version, side files (-wal size), file not readable by others (it holds IP addresses), size vs the limit (exists), oldest record, retention (info).
- Who depends on it, in the row's text: Visitors tab, share-link records, lockout history, Changes tab. (Share links themselves keep working without it: tested.)
- Changes watcher: when the disk was last looked at (info; a stale answer means nothing has been visiting).
- Backup status row (below). Actions on a damaged log: **Restore from the backup (made <date>)** when a good one exists; **Set it aside and start a new log** (renamed `.damaged-<date>`, never deleted; asks first).
- Per-folder view-count databases (`.gallery-stats.sqlite`, 9 here): checked the same way; FIX if one is damaged (those counts are lost; falls back to nothing for that folder). Heavy: cached.

### Backup of the database (owner's rules)
- One copy only, beside the log: `.gallery-visits-<same random>.backup.sqlite` (keeps the random part and the `.gallery-` prefix so the server rules and the "can't be fetched" probe cover it).
- Made with `VACUUM INTO` a temp name, then: check the SOURCE (quick_check) before, check the COPY after, only then rename over the old backup. A failed check at either step leaves the previous backup untouched and Health says so (FIX: "Backup skipped: the log is damaged; last good backup <date>").
- Weekly, automatic: no cron here, so it runs on a request when the last one is 7+ days old (meta key, claimed inside the write transaction so two requests don't both do it; done after the response is sent where `fastcgi_finish_request` exists). On a quiet site it may be late; the row shows the real date.
- "Back up now" button in the row: same checks; the new one becomes THE backup.
- Needs free space about the size of the log: checked first (Suggestion row if short).
- It holds IP addresses: guarded like the log; listed in "Files this gallery has made" (own kind, deletable); docs say so.
- Restore: verifies the backup first, sets the live file aside (never destroys), copies the backup into place, asks first.

### Other stores nothing checks
- Share links file (`.gallery-links.php`): readable, parses, count by state (active / paused / ended), ending within 7 days (Heads-up). FIX if unreadable ("every share link stops working").
- Collections file, secret file (widen "Sign-in key present" to say what depends on it: Not listed photo addresses, signed-in browsers).
- Folder data files `.gallery-items.php` already checked; the captions/flags in them are irreplaceable and have NO backup except Tidy-up's. (Question 1 below.)
- Lockouts file, login-attempts file, browsers file: readable (info if fine).
- Disk: free space where the gallery lives; what the gallery's own files take (thumbnails, logs, backups) as info. Suggestion when free space is low.

### Environment
- HTTPS: is the admin reached over HTTPS (sign-in code over plain HTTP) -> Suggestion when not local.
- Real visitor address: the proxy hint (`client_ip_proxy_hint`) exists in code but is not a Health row -> Suggestion with the Settings link.
- curl / allow_url_fopen: needed for the update check and the country file download; Suggestion when those are on and neither exists.
- mbstring (falls back), gzdecode (country file), proc_open (ffmpeg), memory_limit (big photos), max_execution_time (Prepare media, ZIPs): info/suggestion each.
- "Update now" possible (index.php writable)? Previous version kept (can go back)? Info rows.

### State worth knowing (Heads-up / Info)
- Closed gallery (public sees nothing), public downloads mode, public log on, Not listed folder count, private count, collections count.
- Web probes widened: the per-folder stats files and the backup copy cannot be fetched by address (they are raw SQLite files).

## Cost / behaviour
- Cheap checks (file reads, counts) on every open as now. Heavy ones (quick_check on the log and on each folder's stats file, disk walk) cached with a time and re-run by "Check again" or after an hour.
- The page's intro line changes: Health still only looks, except where a row has a button; the weekly backup is the one thing that runs by itself.
- Header strip of numbers (optional): photos/videos, folders, active links, visitors, log size, free disk. Question 3.

## Considered, not proposed (say if you want them)
Server clock/time zone, security headers, PHP end-of-life warning, OPcache state, per-photo checks.

## Tests (to add)
Damaged-middle and destroyed-header fixtures (row says so, right wording, right buttons); backup refused from a damaged log and previous backup kept; copy check failing keeps the old; weekly trigger by faking the meta time, only once under concurrent requests; Back up now replaces; restore (verified, live file set aside); start-new (renamed); backup in the Files list, deletable, not fetchable by address; tier counts (Fix/Suggestion) and the Details button; every new row has a fixture that makes it appear and one that makes it go.

## Open questions for the owner
1. Back up the small, irreplaceable files too (links, collections, settings, secret, and the captions/flags `.gallery-items.php`)? Same weekly, one-rotating rule, into a single archive beside the database backup.
2. Per-folder view-count databases: include in that backup, or only check them?
3. A header strip of key numbers at the top of the dashboard?
4. Heavy checks cached up to an hour, or run on every open?
