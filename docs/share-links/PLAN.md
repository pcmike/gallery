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

### M3 · The log
- [ ] Per-link log store, visitor/IP records, caps (300 events, 200 visitors), blocked tries.
- [ ] `?linklog` beacon + the page script (seen/opened/played/saved) + server notes for private deliveries.
- [ ] Activity views: Visitors / Folders / Photos on a link; the IP page (Folders / Media / Timeline); the all-IPs list; the new-activity badge.
- [ ] CSV export.

### M4 · Sharing UI
- [ ] Copy / Share… / QR (client-side encoder, icon in the centre, large view, copy image, save image).
- [ ] Preview as this link (own sign-in, counts and logs nothing).
- [ ] Share this folder…, the photo popup (owner) on the viewer's link button, Select-mode *Share…*, the "Only some photos" scope.

### M5 · Finish
- [ ] README (Share links section, backup list, nginx/Apache snippet, Private items now leads with links), `?about`, CHANGELOG, `GALLERY_VERSION` 2.3.0, `gallery-config.example.php`.
- [ ] Browser tests (opening page, viewer for a scoped link, tab, QR), XSS and fuzz runs, JS checks.
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
