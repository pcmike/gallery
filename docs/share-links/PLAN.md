# Share links: build plan

Branch `v2.3.0-dev` (from `main` at 2.2.0, `9b23f49`). **Not released and not pushed until the owner says so.** Reasons for every choice: [DECISIONS.md](DECISIONS.md). Pictures of every screen: [mockups/png/](mockups/png/) (and the generators in [mockups/](mockups/)).

Working rules for this branch (from the repo and the owner): single file, no dependencies, graceful degradation; every user-facing change bumps `GALLERY_VERSION` + CHANGELOG + README + `?about` together; `php -l` and the whole suite before each commit; a test per behaviour; commit per milestone; PHP ≥ 8.3 (tested on 8.3 and 8.4). Don't start the long browser suite casually (about 17 min for the whole chain).

## How the code is organised today (what we build on)

- The script runs top to bottom. `?img=`, `?video=`, `?original=`, `?strip=`, `?preview=` answer **before** the tree is scanned (`valid_media_path`, then `serve_*` with their own hide check). `?reveal` and `?admin` are routed early too.
- After the scan, `$allFiles` is the complete list and **`$files`/`$groups` are filtered for anyone who isn't "revealed"** (about line 3120): every later count, folder card, lightbox list and allowlist agrees with what is rendered. This is the single most important choke point.
- `is_revealed()` = the admin cookie, or the private-view-password cookie. It is used in ~21 places; `file_is_hidden()` in ~13 (see "Call-site checklist").
- Data files are guarded PHP-JSON (`json_file_read` / `json_file_update`, `flock`), colocated per folder (`.gallery-items.php`) or beside `index.php` (`.gallery-login-attempts.php`). Admin changes are POSTs handled in `handle_admin_post`; in-page toggles send `X-Requested-With: gallery-fetch` and get JSON; the Private tab re-fetches the admin page and swaps its panel.
- Settings: `gallery_setting()` constants, `gallery_setting_definitions()`, saved by `site_settings_save()` to `.gallery-config.php`. Server-rule snippets are built per install by `render_server_rules_help()` (`$loc`, `$base`).

## Design in one page

**Viewer levels.** `owner` (admin cookie) > `full` (private-view password) > `link` (a valid share-link cookie, scoped) > `public`. `is_revealed()` keeps meaning *owner or full*. A new `link_viewer()` returns the live link record (or null) for the request; it reads the links file **only when the link cookie is present**, so public traffic costs nothing.

**Link cookie.** `gallery_link_<10 hex of sha256(__DIR__)>` = `<id>.<hmac_sha256('link|id|token|__DIR__', install_secret)>`. Bound to the token, so *Replace address* signs everyone out. Re-checked against the record every request (status, starts, ends, max opens). An admin browser is never a link viewer.

**Scope checks without a folder index.** Folders carry a hidden id (`folderId` in the folder's own `.gallery-items.php`, stamped when a link first covers it). Authorization asks: for the item's containing folder and each ancestor, read its `folderId` (cached per request); is any in the link's scope? Photo scope: (containing folder id, basename) in the set. No path matching, so renames/moves keep working and a same-name newcomer has no id. A link also keeps `folderIndex` (id → last known path) **for display only** (the "Folder missing" warning, the Opens-in menu).

**The filter.** At the choke point, `!is_revealed()` becomes: keep an item if it is not hidden, or the viewer is owner/full, or the link allows it. Everything downstream follows. `?img`/`?video`/`?original`/`?strip`/`?preview`/`?photo` get the same predicate (`viewer_may_see_item`).

**Capabilities are separate functions**, not one boolean: `viewer_may_download($dir,$file)`, `viewer_may_zip($folder)`, `viewer_sees_all_locations()`, `viewer_is_link()`. Owner/full keep today's behaviour exactly.

**Redeem.** `?link=<token>` (or the pretty `/trip/<token>` rewritten to it): GET shows the opening page (no-store, noindex, no-referrer; title/note per link). POST (`go`) re-validates, counts a use unless this browser already holds a valid cookie for the link or the same IP opened it in the last ~15 minutes, sets the cookie, redirects to the clean "opens in" address. Bad or inactive tokens show the one invalid page; wrong tokens are throttled (`login_throttle`, realm `link`); inactive-but-real tokens are logged as blocked tries.

**Storage.** `.gallery-links.php` (guarded JSON): `links` (id → record), `folderIndex`, `seenAt` (for the new-activity badge). Record: `id, label, token, status (active|paused|revoked), created, startsAt, endsAt, maxOpens, opens, note, titleMode (gallery|neutral|custom), titleText, scope {type: all|folders|photos, folders:[fid], photos:[{fid,name}]}, openIn, downloads {mode: off|all|some, folders:[fid]}, zip {…same…}, locations (public|all), headsUp (default|on|off), lastOpened, revokedAt`. Logs: one `.gallery-linklog-<id>.php` per link (visitors, per-IP per-item tallies, latest 300 events, blocked tries). Deleting a link deletes its log; nothing else does.

**Logging.** Browser reports (`?linklog`, POST, small batches): *seen* (IntersectionObserver), *opened*, *played*, *saved*; server notes deliveries of private items too. Client IP via `client_ip()` (trusted-proxy aware; Cloudflare ranges built in).

## Milestones

Each ends with: `php -l`, the full `tests/run.php` on PHP 8.4 and 8.3, a commit on `v2.3.0-dev`, and this checklist updated.

### M1 · Foundations, redeem, the tab's skeleton: DONE (commit "M1")
- [x] `client_ip()` + `TRUSTED_PROXY` (direct / cloudflare / proxy, `TRUSTED_PROXY_RANGES`) and used by the login throttle.
- [x] `ENABLE_SHARE_LINKS` (off), `SHARE_HEADS_UP` (off), `TRUSTED_PROXY` in Site settings (new "Share links" group, shows the address the gallery sees).
- [x] Link store (`.gallery-links.php`), 16-character base-62 token, constant-time lookup, `link_state()` (active, paused, revoked, scheduled, expired, used_up), counters under the file lock.
- [x] Cookie bound to the token, `link_viewer()`, `viewer_may_see_item` / `viewer_may_download` / `viewer_sees_all_locations`, link holders kept out of shared caches.
- [x] Redeem route (opening page, the one invalid page, press-to-count with the 15-minute same-address allowance, throttle, owner's browser skips everything); rule generator (nginx + Apache, written per install) and the live probe; `?link=` fallback. *Blocked-try logging is a stub until M3.*
- [x] Admin Share links tab: list (Current / Ended), New link and Edit (scope "everything private" only for now), Pause/Resume, Extend, Replace address, Revoke, Delete (modals), phone width, address check banner.
- [x] The call sites (see the table): `?photo=`, the scrub plan, originals, strip/preview, `serve_image`, `serve_video`, the page filter, cache headers, location trust, the sign-in lock. In the viewer: download and copy-link buttons are per file for link holders. *Not yet:* ZIP for links (M2), private-folder cards' link icon (M2), heads-up header (M2).
- [x] Tests: `[share links]` in `tests/run.php` (33 checks: opening page, counting, grace, statuses and cut-off, identical invalid page, replace/extend/edit/revoke/delete, throttle, owner, proxies and addresses, per-install rules and probe). Server suite 1,176 on PHP 8.4, 1,172 on 8.3.

### M2 · Scopes and capabilities (the risky part): DONE (commit "M2")
- [x] `folderId` stamped in the folder's own `.gallery-items.php` (refused when the folder can't be written), ancestor-id walk (`link_item_under_folder_ids`), photo scope (folder id + name), `viewer_may_see_item` for all three scopes.
- [x] Ids carried by more than one folder (a copied folder) are ambiguous and match nothing; found by the first page a link holder loads or the admin tab, kept in the links file so the early routes agree.
- [x] Applied at the page filter and in every route (`?img` all sizes, `?video`, `?strip`, `?preview`, `?original`, `?archive`, `?photo`, folder pages, counts, pills, covers).
- [x] Capabilities: downloads and ZIP (off / everywhere / only some folders), ZIP streams with hidden locations scrubbed (same size) and leaves out a file that can't be scrubbed; locations (public / all) also for single originals.
- [x] "Folder missing": tab shows a pill, a notice, and *Take it out of this link*; renamed folders keep working, a same-name newcomer is not covered, a copy makes the id ambiguous.
- [x] Link holders: no sign-in lock, no owner tools, per-file download and copy-link buttons, the heads-up line (amber in the last day, dismissable), kept out of the view counts, the private folder card's link icon hidden for non-owners.
- [x] Form: scope (everything / some folders, with a folder picker and chips), opens in, downloads and ZIP (+ folder lists), all worded for the scope. Photo scope works in the backend; its pickers arrive with M4.
- [x] Leak matrix in `tests/run.php` (5 viewers x every file, every size, video, `?photo=`, folder pages, names on the page) + fuzz identities (link-folders / link-photos / link-all, 5,015 requests, 0 problems) + XSS canary (labels, notes, titles, odd folder and file names; 557 pages, 0 findings).
- Server suite 1,211 on PHP 8.4, 1,207 on 8.3.

### M3 · The log: DONE (commit "M3")
- [x] Per-link log store (`.gallery-linklog-<id>.php`, plus `-items` for exact per-item tallies and `-q` for the server's cheap delivery notes), visitor/IP records (an address together with a browser), caps (latest 300 timeline lines, latest 200 visitors folded into a "+ N earlier" count), blocked tries with the reason (paused, replaced, ...).
- [x] `?linklog` beacon + the page script (seen via IntersectionObserver / opened / played / saved) + server notes for private pictures and originals delivered; reports from the public or the owner are dropped.
- [x] Activity views: Visitors / Folders / Photos on a link; the address page (Folders / Media / Timeline, filterable by link and folder); the all-IP-addresses list; the new-activity badge (row text and sidebar count, cleared by `link_seen`).
- [x] CSV export (visitors / what they looked at / everything in order; one address alone; a cell starting `=`, `+`, `-` or `@` can't run as a formula), owner only.
- [x] Logs are kept when a link is revoked or expires; only deleting the link deletes them. Link holders stay out of the gallery's view counts.
- [x] Tests: 28 `[share log]` checks in `tests/run.php` (including the caps); a `share links` section in `tests/tools/browser/browser_checks.py` (opening page and mistyped link on a phone, a Rome-only holder's page and the absence of owner tools, the heads-up line, the whole tab flow: rows, Visitors / Folders / Photos, address page and timeline, back, IP addresses view, "nothing new" after looking, phone width: 20 checks). Server suite 1,239 on PHP 8.4, 1,235 on 8.3; fuzz 5,268 requests, 0 problems; XSS 592 pages, 0 findings.
- Test-harness notes: the hidden IP-addresses view owns the first `.sl-act` on the page, so browser checks must scope to `.sl-row[open] .sl-act`. A press from an address that opened the link in the last 15 minutes is not a new opening (clear `lastOpen` in a test to get one). Don't run the browser tools under `ulimit -v`: Chromium won't start.
- Cosmetic, for M4/M5: on a phone the "2 not ended · 0 ended" line wraps into four short lines beside the Links / IP addresses switch.

### M4 · Sharing UI: DONE (commit "M4")
- [x] **The share kit** (`render_share_kit()`, written out on the owner's gallery pages and the admin page, only while share links are on): a QR code drawn in the browser (byte mode, error correction H, versions 1-20, the gallery's own icon in the middle with a white margin), Copy / Share… (the phone's share sheet, only where the browser has one) / QR code under each link's address in the tab, **Show large** (full-screen white, to hold up to a camera; Escape or Done closes it), **Copy image**, **Save image** (a phone's share sheet offers "Save Image"). Icons `share`, `qr`, `copy` added to the sprite.
- [x] The encoder is verified against an independent one: `tests/tools/qr/qr_reference.py` (encoder and a decoder that unmasks, un-interleaves and checks every Reed-Solomon block; self-tests of the published format and version bit patterns and of the block tables' totals) is identical to the old `qrmini.py` (whose codes a phone scanned) for versions 1-6, and the browser check compares the page's picture with it module for module and reads it back.
- [x] **The short form** (`GalleryShare.quick`): "Share <folder>", "Share just this photo", "Share these N photos"; name, opens (Once / 5 / 10 / Unlimited), stops working (1 day / 1 week / 30 days / Never), the fine print and **More options…** (hands over to the full form on the Share links tab, filled in); after Create link it shows the address with Copy / Share… / QR code. Posts the same `link_create` the tab does; the response now carries both address forms (`urls.long`, `urls.short`; the tidy one is used when the server rule works).
- [x] Entry points: a **Share button in the icon strip of a private folder's page**; a private folder card's link icon asks what to share for the owner (a public folder's keeps the plain copy link); **Share…** on each private folder row of the Private tab; **Share…** in Select mode; the viewer's link button on a **private** photo asks "This photo is private": *Share just this photo…* / *Copy link* (a public photo copies straight away, as before; everyone else never had the button on private items).
- [x] **Preview as this link**: a button on each (not revoked) link; opens the gallery where the link opens, in a new tab, with a signed cookie (4 hours, or Exit preview) that makes the browser a holder of that link on gallery and media pages: the same checks, no owner tools, nothing counted or logged (the log writers and the queue refuse). `?admin` keeps the owner's sign-in, and a thumbnail asked for by an admin page (Referer has `admin`) is still the owner's, so the tabs' thumbnails keep working during a preview. A purple strip says whose view it is, with **What's included** (a list of what the link shows and what it doesn't) and **Exit preview** (`?exitpreview`, back to the tab). A revoked link's preview stops; a paused one can be previewed and the strip says nobody sees anything now.
- [x] **Photo scope in the full form**: "Only some photos" is always offered; thumbnails with × and **+ Add photos** (a picker: choose a folder, tick its photos, what is ticked stays ticked across folders; `?admin&linkphotos=` lists one folder's photos), "Opens in: the first photo".
- [x] Tests: 19 new server checks in `tests/run.php` (the kit and every entry point appear for the owner only, both address forms, the picker's list, and the preview contract: start needs the owner and the token, the link's own view, pictures in and out of scope, the admin pages and the Referer rule, nothing counted or logged, a changed cookie, Exit, paused and revoked; share links off = none of it), mutation-tested (the Referer rule, the log guards and the owner short-circuit each make exactly their check fail); 51 new browser checks (QR against the reference, large view, the short form from a folder / photo / selection / Private tab, More options…, the viewer's question, the picker, preview with its list and Exit, phone widths); fuzz identities for previews and the new routes (7,411 requests, 0 problems); XSS canary with previews (816 pages, 0 findings); the JS-check tool picks the admin script by content.
- Not done here (needs a real phone): the iPhone share sheet, copying the QR as a picture, the full-screen QR held up to a camera, Save Image from the share sheet.
- Cosmetic, still open: on a phone the Share links tab's "2 not ended · 0 ended" line wraps into four short lines beside the Links / IP addresses switch.

### M5 · Finish
- [ ] README (Share links section, backup list, nginx/Apache snippet, Private items now leads with links), `?about`, CHANGELOG, `GALLERY_VERSION` 2.3.0, `gallery-config.example.php`.
- [ ] Browser tests: the opening page, scoped holder and tab are done (M3); still to add: the share/QR UI, preview as a link, Share this folder, the photo popup. XSS and fuzz runs, JS checks.
- [ ] Full chain on 8.4 and 8.3; a real-phone checklist for the owner.

## Call-site checklist (every place visibility is decided)

Update the line numbers as code moves. Each row must end in a deliberate choice and a test.

| Where (2.2.0) | Today | With share links |
|---|---|---|
| `?photo=` (≈958) | hidden → ignored unless revealed | `viewer_may_see_item` |
| `send_original_for_visitor` / `visitor_scrub_plan` (≈1484) | revealed → no scrub | scrub unless owner/full or link with `locations=all` |
| `run_archive_route` (≈1634) | revealed only | owner/full, or link with zip capability for that folder; scrub locations for links |
| `serve_original_download` (≈1686) | revealed or `ENABLE_PUBLIC_DOWNLOAD` | add link download capability (per item) |
| `serve_video_strip` / `serve_video_preview` (≈1887, 1917) | hidden && !revealed → 404 | `viewer_may_see_item` |
| `serve_image` (≈2492) / `serve_video` (≈2626) | same | same |
| `$hasPrivateItems` (≈3105) | the sign-in lock shows if anything is private | link holders never see the lock |
| the filter (≈3120) | `!is_revealed()` | `!owner/full` → keep not-hidden or link-allowed |
| `send_viewer_aware_cache_headers` (≈3340) | revealed → private,no-store | also link holders |
| `photo_location_visible` (≈4415) | trusted if revealed | trusted if owner/full or link `locations=all` |
| `media_sort_apply` (≈4819) | private-first only for revealed | owner/full only (link holders never see private markers) |
| archive button (≈9395) | counts for revealed | per capability |
| `home_screen_app_tags` (≈10736) | skipped when revealed | check intent |
| icon-strip lock (≈12016) | owner or revealed | owner or full, never a link holder |
| sort button, download button, archive button, copy-link buttons (≈12297, 12447 and the permalink/viewer code) | various | per capability; hide the link button on private items for non-owners |
| `?track` (≈9223) / visit counters | skips admin | skips link holders too |

## Test plan (summary)

- **Server suite** `[share links]`: redeem GET/POST, counting (browser, IP grace, simultaneous), statuses and boundaries (starts/ends, pause, extend, revoke, replace), invalid page identical for all failures, throttle, cookie binding, headers, pretty-path rule text per install, trusted-proxy (including a spoofed header), settings off ⇒ links dead, logs survive revoke/expire and die only with delete.
- **Leak matrix**: for scope ∈ {all, folders, photos} × route ∈ {page, `?folder=`, `?photo=`, `?img` all sizes, `?video`, `?strip`, `?preview`, `?original`, `?archive`, `?map`, sort, counts, breadcrumbs/pills, og tags} assert exactly what is allowed and nothing more; a same-name new folder is not covered; a copied folder id fails closed; renamed folder keeps working.
- **Fuzz**: a link-holder identity added to `fuzz.py`; **XSS**: labels, notes and titles are planted and must be escaped everywhere (opening page, tab, logs, CSV).
- **Browser**: opening page on a phone width, scoped viewer, no owner tools, link button hidden on private items, tab flows, QR present and scannable shape, heads-up.
- **Manual (owner, real iPhone)**: Share sheet, copy QR image, full-screen QR, in-app-browser hand-off, the opening page in Messages' preview.

## Resuming this work later

1. Read [DECISIONS.md](DECISIONS.md), then this file; tick the boxes above as you go.
2. `git switch v2.3.0-dev`; the last commit message names the milestone it ended.
3. The assistant's memory file `project_share_links_design.md` holds the same summary.
4. Release only on the owner's say-so (`cmpr`): docs sweep, version/CHANGELOG/README/`?about`, all suites, then merge to `main`, tag `v2.3.0`, push, release assets (`index.php`, `index.php.sha256`, `example-forsale.md`).
