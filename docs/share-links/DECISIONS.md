# Share links: decision log

Why each choice was made, in the words of the conversation that produced it (2026-10-19). The plan that follows from these is in [PLAN.md](PLAN.md); the mockups are in [mockups/](mockups/). Anyone (including a future session of the assistant) picking this up should read this file first, then the plan.

**Who decided what.** "Owner" is the gallery's owner (the person who asked for the feature). "Assistant" proposed; the owner approved, changed or rejected. Where the owner changed course, the note says so.

## The idea

The owner currently gives people a *private-view password*. They want to stop handing out a password and instead hand out **temporary links**: `https://example.com/trip/<random>` that automatically sign the person in as a "private viewer", usable only N times and/or until a time. The password option stays in the project for admins who prefer it; **nothing in the share-link screens should mention the password**.

## Where it lives

- **A new "Share links" tab in the admin**, next to Private, not a Site-settings section. *Why:* Site settings holds on/off options saved to `.gallery-config.php`; links are records that change constantly (use counts, pause, revoke, logs).
- **Site settings gets only**: *Allow share links* (off by default, like every opt-in), the short-address check, the heads-up default (off), where visitors' IP addresses come from, and a Cloudflare-caching note.
- Storage: `.gallery-links.php` beside `index.php` (the same guarded-PHP-JSON pattern as `.gallery-items.php`), plus one log file per link. They are matched by the existing `/\.gallery-` web-server rule and need adding to the README's backup list.

## The link and the opening page

- **Address `/trip/<16 letters and digits>`** (the owner's gallery is at `/trip/`). It needs a web-server rewrite, exactly like the existing tidy folder-link rule; the rule shown in the admin is **written for the user's own install** (derived the same way as the other rules), with an nginx and an Apache version, and a live probe. Without the rule, `?link=<token>` works with no server setup.
- **Token is stored readable**, not hashed, so the owner can copy a link again later (they re-send links by message). The file is guarded and not served.
- **"Open the gallery" is a button; pressing it is the "use".** Chat apps fetch links to build previews; counting the page fetch would burn a one-time link before the person saw it.
- **A "use" is a new browser**, not a page view. Once someone is in, they can keep looking until the link ends. *Added after the owner pointed out in-app browsers:* a link tapped inside Instagram/Facebook opens in that app's browser and the person then taps "Open in Safari"; a second open from the **same IP within ~15 minutes is not counted**, so a *Once* link survives that hand-off.
- **Invalid / expired / used-up / revoked / paused / mistyped links all show the same page.** Owner: it should have **no "Go to the gallery" button and no "public photos are still there" line**, no "it may have ended or been used up", and no domain footer. The point is for the person to tell whoever sent it, not to go poking around. Text: "This link isn't valid" / "Please let the person who sent it to you know."
- **The opening page has no domain footer.**
- **Optional personal note** per link, shown on the opening page ("Hi Carol, here are the Rome photos…").
- **Title on the opening page and in message previews is configurable per link:** the gallery's title (default), a neutral "A shared gallery", or custom text. The icon is always the gallery's. *Owner:* fine for now, but make it configurable "in case there comes a time when it's not ok for a given share link". Message previews never show a photo or a folder name.
- Opening page headers: `Cache-Control: no-store`, `X-Robots-Tag: noindex`, `Referrer-Policy: no-referrer`.
- After pressing the button the browser is redirected to a clean address (the token is not left in the address bar or history).

## Access, status and lifetime

- **The cookie is checked against the link's record on every request.** Revoking, pausing or expiring cuts off people already in. It is bound to the token, so *Replace address* also signs everyone out.
- **Link holders are never admins.** No pencils, locks, Private tab or sign-in lock in the icon strip. *Admin implies private view, never the reverse.*
- If the owner (signed in as admin) opens a link, the admin session wins and nothing is counted or logged.
- **Statuses:** Active, Paused (reversible, "Resume"), Used up, Expired (**"Extend…"** is offered), Revoked (final; stays listed with its log).
- **Starts-on date**: the owner said it isn't super useful but fine to add, default *Immediately*.
- **Opens**: Once / 5 / 10 / Unlimited / other. **Ends**: 1 day / 1 week / 30 days / a picked date / never. Times are entered and shown in the owner's browser time zone.
- **Heads-up header** (the visitor sees a thin line at the top): *can be turned on/off, including per link* because the owner isn't sure they like it. Site default off; per link: use the site setting / On / Off. **Wording (owner's):** "The link being used to access this media expires on <date>." Amber in the last day; dismissable; not shown when the link has no end date.
- **Replace address**: new token, same name, settings and log; the old address stops working at once and, if pressed, is logged as a blocked try.
- **Preview as this link** (opens in a new tab): shows exactly what the link shows; counts nothing and logs nothing. Needed to check a folder-limited link before sending it. **How it really works (changed while building it):** the first plan said the new tab would have "its own sign-in" so the owner stayed signed in as themselves in the first tab, but cookies are shared by every tab of a browser, so that can't be done. A signed preview cookie (4 hours, or until *Exit preview*) therefore makes the whole browser see the link's view on gallery and media pages (never on `?admin`, and a picture an admin page asks for is still the owner's, so the admin's thumbnails keep working). Every gallery page in that browser shows the purple strip with *Exit preview*, and the Share links tab shows a note with *End preview*, so it can't be mistaken for the owner's view. Rejected: asking the owner to use a private window instead (more steps, and no strip or list of what's included).
- **A new-activity badge** on the Share links tab/row (no email; the gallery has no mail).
- **Not included:** emailing the owner on first open.

## What a link can see

Three scopes, all in the first version (owner: "option for everything and specific folders… in the first version"; photo scope added later in the conversation):
1. **Everything private** (including things added later).
2. **Only some folders** (each includes everything inside it). The link holder is never told other private folders exist: no counts, no lock icons, no hints.
3. **Only some photos** (from the viewer's link button, or Select mode's *Share…*). They see only those; closing the viewer shows the folder with only the shared photos.

**Folders are remembered by a hidden id stamped into the folder's own `.gallery-items.php`** (the owner chose this over path-matching: "you don't rename something to hide it normally"). The id travels with a rename or move; a deleted folder is "missing"; a **same-name newcomer is not covered**; a copied folder (duplicate id) is ambiguous so it fails closed with a warning; if the folder isn't writable, creating a link for it is refused (no silent fallback to paths). Each link remembers the folder's last path and checks that first, rescanning only if the id has moved. Photos are remembered as folder id + filename; a renamed/deleted photo is "missing" too. A missing item fails closed and the link row shows "Folder missing" with *Pick it again…* / *Remove it from this link*.

## What a link can do

- **Download originals** and **ZIP of a folder**, each: Off / everywhere they can see / only in chosen folders (when the scope is some folders the wording is "In all chosen folders / Only in some of them"). Public photos follow the gallery's own download setting.
- **Photo locations.** The assistant first said visitors "never" get locations; the owner corrected it: *visitors do get locations when "show locations publicly" is on for the folder.* The real model: a link holder by default sees **only the locations visitors already see** ("as the public sees them"); the alternative is "all saved locations". Hidden locations are **scrubbed from originals and from the ZIP** for link holders (the ZIP today ships files "exactly as on the server, locations included", so it needs the same scrubbing single downloads already have).

## The link button on private media

- The viewer's copy-link / permalink button on a **private** photo (and on a private folder's card):
  - **for the owner**: opens a small popup, "This photo is private", with **one row of two buttons**: *Share just this photo…* and *Copy link* (the owner asked for the buttons on a single line);
  - **for everyone else** (link holders, private-view visitors): **the button is hidden**, because a plain link to a private item is useless to anyone without access.
- Public items keep the button for everyone.
- Why a private link is useless: `?photo=<private>` for a viewer without access just loads the plain gallery with no photo and no preview picture, and `?img=` answers 404; the copied address never contains the share token.
- A photo that follows a private folder counts as private; a photo explicitly made public inside one counts as public.

## Sharing aids

- **Share this folder…** button on a private folder's page and on the Private tab's rows: opens a short form already set to that folder (name, folders, opens, ends, *More options…*). *Why:* otherwise the owner has to go to the tab and find the folder in a list.
- **Share…** in Select mode for a few photos.
- **Copy**, **Share…** (the phone's own share sheet) and a **QR code** with the **gallery's own icon in the middle** (error correction H so the icon is safe). The QR has a **large preview** (a white full-screen version for showing someone across a table), **Copy image** and **Save image**.
- **Export log as CSV** (visitors / what they looked at / everything in order), per link and per IP.

## The log

The owner wants to see "what folders and exactly which media any given IP looks at", across all links, and never to lose a log when a link ends: **"Revoked/expired != deleted. Logs are deleted only when the link is deleted."** The delete confirmation counts what will be lost.

- **Four levels, because thumbnails are sharp enough to count as seeing a photo** (owner: "if we only show the media an IP looked at, defined as opening it in the lightbox, we're not being truthful"):
  **Seen** (on their screen, even as a thumbnail) · **Opened** (shown large in the viewer; **Played** for a video) · **Saved** (the original downloaded).
- **"Seen" is reported by the visitor's browser** (IntersectionObserver, sent in small batches), not counted from image requests: browsers keep public images for a year, and a CDN may keep copies, so image requests don't say what was on screen. The server also notes deliveries of *private* items as a backup so an ad-blocker can't hide the sensitive ones.
- **Cloudflare was checked on the live site** (2026-10-19): pages and `?img=` images return `cf-cache-status: DYNAMIC`; nothing is cached at the edge today (Caching Level Standard, Browser Cache TTL 4 hours; the owner believed there was a Cache Everything rule, but none matches these URLs).
- Per link, per IP: browser, country, opens, and per item first/last seen and counts. A timeline of the **latest 300 events per link**; older events fold into the counts so nothing is lost. Latest **200 visitors per link** ("+N earlier").
- Views: **the IP's page** (Folders / Media / Timeline tabs; filenames are shown because only the owner sees it), an **all-IPs list across links** ("an address on two links is worth a look"), and **blocked tries** with IP (a forwarded link being tried by someone else).
- **An IP is not a person**; the screens say so.
- Link holders' views are kept out of the public view counts.

### IP addresses behind Cloudflare
`REMOTE_ADDR` is Cloudflare's address. A setting "Where visitors' IP addresses come from" (Directly / Cloudflare / another proxy). With Cloudflare, `CF-Connecting-IP` is trusted **only when `REMOTE_ADDR` is in Cloudflare's published ranges** (built in, editable). This also fixes the existing sign-in throttle, which currently sees only Cloudflare addresses.

## Things the assistant raised and the owner accepted without change
"Preview as", "Replace address", "Share this folder…", CSV export, message previews that don't leak, in-app-browser re-open grace, owner opening own link, view counts excluded, "folder missing" handling, no owner tools for link holders, batching the log writes, times in the owner's zone, backups/docs/tests housekeeping.

## Out of scope (for now)
Email/push notifications; a per-photo "what did they look at" *history beyond the 300-event timeline*; hashing tokens.

## Risks to keep in front of us
- **Visibility is checked in many places** (≈21 `is_revealed()` and 13 `file_is_hidden()` call sites). A folder- or photo-limited viewer must be right in every one: thumbnails, originals, video, map, search, counts, ZIP, sorting. Plan: a viewer-scope abstraction used by all of them, a leak test per route, and the fuzz suite run as that viewer.
- **"Once" must really be once** under simultaneous requests (lock the count).
- **Real iPhone/Safari behaviour** (Share sheet, copying the QR image, the full-screen QR, in-app browser hand-off) cannot be tested here; the owner will check on a real phone against their working trip gallery.
