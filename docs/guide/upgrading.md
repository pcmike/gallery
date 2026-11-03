# Upgrading and backups

What changes at each version, and what to back up.

← [Guide index](README.md) · [Project README](../../README.md)

## Upgrading and backups

**Updating** means replacing `index.php`. Settings (`.gallery-config.php`), captions and everything else live beside it and are untouched; the Health check then shows what it was upgraded from.

**Upgrading to 2.4.2** from 2.4.1: replace `index.php`. Nothing stored changes, and nothing in how it works. Only the license changed: from 2.4.2 it is MIT with the Commons Clause (see [LICENSE](../../LICENSE)); versions up to 2.4.1 stay plain MIT.

**Upgrading to 2.4.1** from 2.4.0: replace `index.php`. Nothing stored changes. The signed-in browsers table now shows **Addresses** and **Device changes** counted from each browser's history file, so existing browsers show the right numbers at once. A browser that was flagged *a different device of the same kind* only because of an iPhone's own networking stays flagged until you press *Looks fine* (new ones are not flagged). The About icon now opens a new tab, and the new-link form's "easy to guess" note only appears when the link really opens something private.

**Upgrading to 2.4.0** from 2.3.2: replace `index.php` and open the admin. Things to know:
- **Private-view guests type the password once** (and so does anyone else who relied on the old fixed cookie): sign-ins are now a token per browser, which you can see and end in *Settings → Signing in and passwords → Browsers signed in*. You stay signed in as the owner (the old stay-signed-in token is swapped for a new one the first time). Both lengths start at **3 days**.
- **New links start at 2 browsers**, not unlimited (`DEFAULT_LINK_USES`; 0 is unlimited). Links you already have are untouched. A link set to *Off* for downloads now gives none, even of public photos.
- **First-run setup asks for a code** in `gallery-setup-code.php` beside `index.php`, instead of using a timer. A gallery that already has a password is not affected.
- **No lock icon on the page.** The admin is at `?admin` (or `…/admin`); on a computer it opens the shell. The gallery-wide map switch (`ENABLE_MAP`) is gone: a map is available wherever photo locations are allowed.
- **New files**, all guarded and all in `.gitignore`: `.gallery-browsers.php`, `.gallery-browsers-log.php`, `.gallery-signin-<id>.php` (one per signed-in browser), `gallery-setup-code.php`, and, only if you switch the country lookup on, `.gallery-geo.php` and `.gallery-geo-state.php`. Add the names to your backups' ignore list if you keep one. *Files this gallery has made* in Settings lists them all.
- **The country lookup is optional and off.** Turning it on downloads one 4 MB file from DB-IP to your server; it is the only request the gallery makes itself to another site.
- **For people who edit the code:** `index.php` is now built from the parts in `src/` by `php build.php`; edit `src/`, never `index.php` (see CONTRIBUTING).

**Upgrading to 2.3.2** adds [an old address that keeps working](private-and-sharing.md#an-address-of-your-own) when a link's address changes (working, paused or killed, 10 ways in per link), a **came in on** line on the Visitors tab, and links in the admin that jump to one setting. Nothing stored changes; a link made before it simply has no old addresses. A new share link now starts on **Just the public gallery**.

**Upgrading to 2.3.1** adds [your own address for a link](private-and-sharing.md#an-address-of-your-own), [closing the public gallery](private-and-sharing.md#closing-the-public-gallery) and `…/admin` for the sign-in. If you use the tidy share address on nginx or Apache, **replace its rule with the newer one** in [The address, and the server rule](private-and-sharing.md#the-address-and-the-server-rule) (the old one keeps working for random addresses only, and the admin's check says it needs updating). It also adds three things to [share links](private-and-sharing.md#share-links): the **Show a preview photo** choice (links made before it have no picture until you switch it on in *Edit*), the **Just the public gallery** scope, and the opt-in [log of the public address](private-and-sharing.md#logging-the-public-address) (`LOG_PUBLIC_VISITS`, off). Nothing stored changes; the public log, once on, writes `.gallery-linklog-ffffffffff.php` and `-items.php` beside `index.php`, which the `.gallery-` server rules and `.gitignore` entries already cover.

**Upgrading to 2.3.0 or later** adds [share links](private-and-sharing.md#share-links), off until you switch on *Allow share links*. Nothing stored changes. Once links are used the gallery writes `.gallery-links.php` (the links) and a `.gallery-linklog-*.php` for each (its log) beside `index.php`, and stamps a hidden id into the `.gallery-items.php` of a folder a link covers; add the two new names to `.gitignore` and your backups (below). On a private photo or a private folder's card, the link button now asks you what you mean, and is gone for everyone else.

**Upgrading to 2.0.8 or later** changes one default: a gallery that has never had its photo order set now runs by date taken, oldest first, so videos sit among the photos they were shot with (before, by file name). A gallery that groups photos by filename keeps name order, and an order you chose yourself is never touched; to keep the old order, pick **Name** in Settings (or `"PHOTO_SORT_ORDER": "filename"`).

**Upgrading to 2.0.9 or later** moves the lock from beside the title into the icon strip (joined to the archive icon), and signing in with it, as the owner too, returns you to the page you were on instead of the admin page; the gallery title still leads to the admin.

**Upgrading to 2.2.0 or later** changes nothing stored: private folders, photos' own privacy and every folder setting are read exactly as before. What is new is how they are shown and changed (the three looks of the lock, *Going through a private folder first*, and folder settings that save by themselves, with no Save button).

**Upgrading to 2.1.0 or later** removes the *Photos* (*Media*) tab from the admin: its job moves to the gallery itself, as a pencil on every tile and in the viewer (caption, link, place, Private, Location, Cover) and the *Select* button, which takes over from *Arrange* (placing photos by drag and drop is part of it). Folder settings is now the admin's first tab, and the Private tab's links open that photo's editor. Nothing stored changes.

**Upgrading to 2.0.11 or later** changes a few things you will notice: the visit total moves from the icon strip into the line under the title; the strip now stays at the top of the screen while you scroll; opening a folder no longer jumps to its cover photo; and you get an **Arrange** button for placing photos by hand (nothing is stored until you use it). A Home Screen app you already added keeps its old icon until you remove it and add it again, and on an iPhone a family member may need to sign in once inside the app (the lock) because the Home Screen app keeps its own cookies.

**Upgrading to 2.0 from 1.x** leaves the gallery looking the same, with three notes: `?download` now redirects to this repository instead of serving the script (serving it would hand `ADMIN_PASSWORD` to any visitor); view counts carry over (1.x's `.gallery-stats.json` is imported on the first request with `TRACK_VIEWS` on, then removed); and you may see a new setup notice counting photos in subfolders when `AUTO_GROUP_BY_FOLDER` is off. See [CHANGELOG.md](../../CHANGELOG.md).

**Keeping runtime data out of git.** If your deployed gallery is in git, add these to `.gitignore` (they hold GPS and passwords):

```
.gallery-cache/
.gallery-items.php
.gallery-config.php
.gallery-stats.*
.gallery-login-attempts.php
.gallery-stay.php
.gallery-secret.php
.gallery-health.php
.gallery-links.php
.gallery-linklog-*.php
```

`.gallery-items.php`, `.gallery-config.php`, `.gallery-links.php`, the `.gallery-linklog-*.php` logs and your `.md` files can't be regenerated: use the admin's JSON export for captions and your host's backups for the rest.
