# Roadmap

Ideas that could be built but have no date. Nothing here is promised; it is kept so the reasoning isn't lost. Shipped work is in the [changelog](../CHANGELOG.md).

## Embedding a share link in another website

Let a link be shown inside a frame on someone else's site (a portfolio, say).

- **Today:** not possible. A link's opening page is sent with `frame-ancestors 'none'`, the link's cookie is `SameSite=Lax` (so it isn't sent from inside another site's frame), and Safari and Firefox limit cookies in frames. The admin and its pages must stay unframeable.
- **Sketch:** a per-link "Allow embedding on: example.com" that lets exactly those sites frame that link's pages; a `SameSite=None; Secure; Partitioned` cookie for those links (HTTPS only; Chrome and Edge, mostly Firefox, Safari unreliable, so Safari visitors would be offered "open in a new tab"); and a plain embedded view (no header, an "Open full size" link).
- **Note:** an open gallery's folder page isn't blocked from framing by the script, and needs no cookie.

## Cards or Flat per share link

Let each share link choose how its folders are shown, instead of always following the gallery's own Cards or Flat setting.

- **Today:** a link shows its folders the way the gallery does. A link limited to some folders and photos already acts as a hybrid, so the most common need is covered.
- **Sketch:** a per-link "Show folders as: Cards / Flat / Same as the gallery" in the link form, with the choice kept on the link and applied to its pages and its preview.
- **Note:** held back for now because the link form is already long and few people would change it. Worth building if people ask for one link to look different from the gallery.


## The backup noticing changed records, not only removed ones

Make the backup's "this part lost a lot" check look inside records, so a bulk edit is caught as well as a bulk delete.

- **Today:** the weekly backup keeps the earlier copy of a list (share links, collections, a folder's captions file) when at least 3 records, and at least a quarter of what the backup held, have gone from the live file. It looks only at which records are still there. A caption emptied or a private mark flipped changes a record without removing it, so it is not caught, and the changed copy replaces the old one.
- **Sketch:** compare the records field by field (for captions, the caption, the location and the private mark), and hold the part when many of them changed the same way, with the same Health row and one-click confirm as for removed records.
- **Note:** held back because it needs a rule for what counts as "many" for each field, and an ordinary bulk edit (Tidy up, a long editing session) would trip it often. Worth revisiting once the removed-records check has been in use for a while and the owner knows how often it speaks up.

## Tidy folder links without the closing slash, and copy-link buttons that give them

Make `https://example.com/p/family/disney/2019` open the folder (today the optional server rule needs the closing slash, `/p/family/disney/2019/`), and let the folder copy-link buttons hand out the tidy address when the server's rule is in place.

- **Today:** a nested folder's tidy address works only with the closing slash, through the optional rule in Settings → Server setup; share addresses are one word at the top (`/p/grandma`); the gallery's own copy-link buttons give the `?folder=` form.
- **Sketch:** (1) an extra optional rule for nested paths with no dot and no closing slash (a path with a slash in it cannot be a share address, so there is no clash), tested on real nginx and Apache; (2) in the gallery, a top-level `/p/family` that no share link has the name of opens the folder (a link cannot take the name of an existing top-level folder anyway), answering a private folder exactly as a missing one; (3) the admin's existing check of the tidy folder address stores its result, the copy buttons use the tidy form only while it holds, and a failed check clears it.
- **Risks worked out:** another application under the same directory could be caught by the new rule (make it skip anything that exists on disk, and document it); a folder name with `&` or `%26` must not be able to add parameters (test it on both servers); a private, Only me or Unlisted folder must still answer exactly like a missing one; a stale "the rule works" result would hand out links that 404 until the next admin visit.
- **Note:** held back by the owner (2026-11-24, "not 100% sure of that one yet"). Nothing is built. If it is built, review it on its own: it changes how addresses reach private folders.

## A nested folder more open than the folder above it

Let a folder inside a private (or Unlisted) folder have a choice of its own that is more open, the nearest explicit choice winning, as the location switch already works.

- **Today:** not possible. A folder made private at any depth hides everything beneath it, and Public is "nothing set", so it cannot override anything. A folder's settings say which folder it follows (see the guide). The cases that want it are covered: a share link that names the subfolder (it works though the folder above is private, and can end, be switched off and be watched), moving the folder out, and marking single photos public inside a private folder.
- **Sketch:** a stored Public (and Unlisted) choice for a folder, and a rule that the nearest folder with a choice decides; an older version would ignore the new value and keep the folder private (fails closed). The buttons greyed out for a following folder would become pressable.
- **Note:** held back on purpose. It makes "is this visible?" depend on the whole chain of folders, a forgotten Public on a subfolder would quietly keep it open, and every list that decides what a visitor sees (the front page, Flat, counts, the map, ZIPs, collections, an Everything link) would need a rule for a visible folder inside a hidden one, and a full crawl test like the one for Unlisted. Worth building only if a real case turns up that a share link or a move does not cover.

## Showing a photo's own information (its metadata) in the viewer

Let a visitor open an "info" panel in the viewer with what the photo file itself says, not only the owner's caption: the camera or phone (make and model), the lens, the camera settings (focal length, aperture, shutter speed, ISO, flash, exposure mode, white balance), when it was taken (with the time zone a phone records), the picture's size, and perhaps the place and the editing software.

- **Today:** the gallery reads only a little from a photo's EXIF: the taken time (to order by date and to suggest a folder's Dates), the GPS position (to offer a map pin) and, as far as I checked, little else. It shows the owner's caption, link and place and nothing else from the file.
- **Sketch:** a small panel in the viewer, per photo, drawn from the photo's EXIF (and a video's container tags), with the owner choosing per folder, or for the whole gallery, which groups are shown (for example "camera and settings" on, "software" off). The position stays under the existing location switches, and the file's own position is never shown unless they allow it. Things that identify a person or a device are never shown: the camera's serial number, the owner or artist name, the copyright text, the unique image id and the maker's notes. Stripped copies the gallery sends (Keep the location in files visitors get) would keep following the same rule.
- **Note:** not built. The owner wants it kept in mind while the taken-time reading is fixed (group 14), so that fix keeps the time zone offset and does not throw EXIF away: ordering uses the clock time the camera wrote, and the reader stays general enough to hand the other tags on later.

## Signed releases for Update now

Make Update now check who made a release, not only that it arrived whole.

- **Today:** Update now downloads `index.php` and its `.sha256` from the same GitHub release, so the checksum proves the file arrived intact, not who made it. Anyone who could publish a release in the project's GitHub repository could publish a matching checksum, and every gallery whose owner presses Update now would run that file. The only roots of trust are the GitHub account and the connection to github.com. The guide says so plainly.
- **Sketch:** sign each release with a key kept off GitHub (an Ed25519 signature, checked with PHP's libsodium); the public key is written into `index.php`, and Update now refuses a release whose `index.php.sig` does not verify. Where libsodium is missing it would send the owner to the release page instead. A release step for the owner, and a key that must never be lost; galleries older than the first signed release can't check that one, only the ones after it.
- **Note:** the owner chose to document the gap now and keep signing as a possibility (audit group 15, C9).

## A real script policy for the pages (Content-Security-Policy)

Give the pages a policy that tells the browser which scripts may run, as a second wall behind escaping.

- **Today:** no page restricts scripts. Output escaping is the only defence, and it holds up (the fuzzing and cross-site-script crawls find nothing). The owner's and the private-view visitor's gallery pages are already unframeable.
- **Sketch:** a nonce on every inline script and a `script-src` that names it (plus the one CDN the map loads from). The many inline `onclick=`, `onfocus=` and `onsubmit=` attributes would first have to become listeners in the scripts, because a strict policy blocks them.
- **Note:** a large rewrite of the markup with a lot to re-test, for a gallery with one owner and a clean crawl, so it was left (audit group 15, E2-2). It would be designed together with the embedding idea above, which needs its own framing rules.

## Colour contrast and underlined links

The accessibility review's list of colour findings, kept for a later look.

- **Today:** links inside notes are told apart by colour only (no underline); the sign-in box's border is faint; about ten small texts (folder counts, a few labels, the accent used as text) sit a little under the 4.5:1 line. The owner prefers the current look and does not want links underlined, so nothing was changed (audit group 15, H-26).
- **Sketch:** if it is ever taken up, try the changes one at a time with before and after screenshots: lighter borders, the link colour wherever the accent is text, a darker red for white-on-red badges. Underlines only if asked for.

## An update feed on your own server, to count installs and versions

Let the owner see how many galleries run which version.

- **Today:** the update check asks GitHub, and the request carries `User-Agent: Drop-in Photo Gallery <version>`. GitHub does not show the owner those requests, so there is no way to count them.
- **Sketch:** `UPDATE_FEED_URL` can already point at a feed on your own server; a feed there that mirrors the GitHub release and keeps its own access log would show each install's address and version. It would need a plain disclosure in Settings and an off switch.


## A held-up change log should not slow every page view
*A maybe, not a plan: it needs another program to hold the log's file.*

- **Today:** when what was typed for a deleted or renamed photo is written in the Changes tab and another program has the log's file locked (a backup tool copying it, say), each page view waits for the file, up to several seconds for each folder that has such data, and then leaves the data where it is. Nothing is lost; the page is slow while the lock lasts.
- **Sketch:** give that one write a short wait (a second or less) and try again at a later view, so a held file costs a page almost nothing.
- **Note:** held back because it only happens while something else keeps the file locked, and the data is never lost.


## A visitor's page should never wait on the visitor log
*A real fix, not a maybe: it needs a design of its own (and no entry may be lost).*

- **Today:** a request that logs waits up to five seconds for the log's file when another process holds it (a stuck PHP process, a network share whose file locks misbehave). Measured with the log held: a stranger's front page and a folder page each took 5.1 seconds, with the default settings. The page still loads; it is late. After the five seconds the entry that could not be written is lost. Health's Check again waits one second and no longer scans the disk first.
- **Sketch:** write a visit with no wait: append one line to a small private spool file (an append with its own lock, microseconds), and let the next request that finds the log free merge the lines into it in order. Nothing is dropped, nothing waits, and a log that stays stuck shows as "N entries waiting" in Health. Needs: the spool file's name and guard (it holds addresses, as the log does), its place in the backup and in "Files this gallery has made", a size limit, and the merge.
- **Not an answer:** skipping the entry when the log is busy (a log that drops entries is not a log), or a shorter wait for visitors (the same loss, sooner).
- **Note:** held back from the third review's round because it changes how every visit is logged and needs its own tests and review.


## Download a copy should say when a database could not be copied clean
*A maybe, not a plan: it needs another program to hold a database to itself.*

- **Today:** Download a copy makes a consistent copy of each view-count database, and of the visitor log, through SQLite. When SQLite can't (the database is damaged, its folder can only be read, or another program holds it to itself), the download quietly packs the file as it is on disk. For a damaged file or a read-only folder that is the right copy to have. For a database another program holds to itself it waits five seconds and the copy can lack the newest counts, which sit in the database's temporary side file (not packed), with no word about it. Nothing in the gallery holds a database like that.
- **Sketch:** when the clean copy fails, say so in the answer (which database, and why), and pack the side files beside the plain file when they are there, so the counts in them are not left out. Refusing the download would be wrong for a damaged database: that is when a copy is most wanted.
- **Note:** held back because it only happens while something outside the gallery keeps a database locked, the visitor log has always behaved this way, and nothing in the live gallery is lost.


## The look at the disks should say when it was cut short
*A maybe, not a plan: it needs a very slow disk or a very large tree.*

- **Today:** the Health look at the disks (the room left on each) stops after 2.5 seconds. The gallery's own disk is always looked at first, but on a slow disk or a very large tree the disks it did not reach get no low-space row, and nothing says the look was cut short. (Before the limit existed nothing was skipped, and the page waited.)
- **Sketch:** when the limit is hit, add one quiet line to the disk rows: "looked at N of M folders; the rest were not looked at", as the look for old capture-date files already does.
- **Note:** held back because it has no test without a slow disk, and the case is rare.

