<?php http_response_code(404); exit; ?>
// Example settings file for the Drop-in Photo Gallery.
//
// To use it: copy this file next to index.php and name the copy
//   .gallery-config.php
// (with the leading dot), then remove the // from the lines you want to use.
// The file is plain JSON after the first line. A comma after the last entry
// is fine, and lines starting with // are ignored.
//
// Anything you leave out keeps its default from index.php. This file survives
// replacing index.php with a newer version, so your settings do too. The
// gallery's admin ("Settings") can also edit it; when it saves, it keeps
// only the settings that differ from the defaults and drops these comments.
//
// Only the names below are settings. A name that isn't (a typo), a value of
// the wrong kind, or a file that isn't valid JSON is ignored and reported in
// the gallery's setup notices; it never breaks the site. Keep it out of git:
// it holds your passwords (the gallery stores them as hashes once you sign in).
{
    // --- What gets shown ---------------------------------------------------
    // "AUTO_GROUP_BY_FOLDER": true,        // default false: show subfolders, each as a group (README: Folders)
    // "FOLDER_DISPLAY_MODE": "overview",   // "overview" (one cover card per folder) or "inline"
    // "ENABLE_VIDEO": true,                // default false: show .mov / .mp4 files
    // "AUTO_GROUP_BY_FILENAME": true,      // default false: group photos named alpha_01.jpg, alpha_02.jpg
    // "PHOTO_SORT_ORDER": "taken",         // "taken" (when it was taken, else the file's date; the default) or "filename" (the default if photos are grouped by filename)
    // "PHOTO_SORT_DESC": true,             // default false: last first (Z to A, newest first)
    // "SORT_VIDEOS_FIRST": true,           // default false: videos at the front of each folder
    // "SORT_LOCATED_FIRST": true,          // default false: photos whose location is shown come first
    // "SORT_PRIVATE_FIRST": true,          // default false: private items first (owner and private-view only)
    // "SORT_PRIORITY": "private,video,located",   // the order those three "first" choices stack in (the admin shows them as a list you drag)
    // "SHOW_SORT_BUTTON": true,            // default true: the visitors' sort button

    // --- Thumbnails and counts ---------------------------------------------
    // "ENABLE_THUMBNAIL_CACHE": true,      // default true: small thumbnails in .gallery-cache (false = never write)
    // "TRACK_VIEWS": true,                 // default false: count views (writes a stats file)
    // "SHOW_VIEW_COUNTS_PUBLICLY": true,   // default false: show the counts to visitors
    // "ENABLE_VIDEO_THUMBNAILS": false,    // default true: a real picture for each video (needs ffmpeg on the server; does nothing without it)
    // "ENABLE_VIDEO_PREVIEWS": false,      // default true: hovering over a video plays a short silent clip (needs ffmpeg)
    // "FFMPEG_PATH": "/usr/local/bin/ffmpeg",   // only if ffmpeg is somewhere the gallery doesn't look
    // "PUBLIC_DOWNLOAD_MODE": "all",       // default "off": a download-original button for visitors without a share link: "all" (every public photo) or "some" (only the folders in PUBLIC_DOWNLOAD_FOLDERS). Signed-in people always have it; visitors never get a ZIP
    // "PUBLIC_DOWNLOAD_FOLDERS": ["Paris"], // the folders "some" allows (each includes what is inside it)
    // (the older "ENABLE_PUBLIC_DOWNLOAD": true still reads as "all" until PUBLIC_DOWNLOAD_MODE is set)

    // --- Locations and the map ---------------------------------------------
    // "ENABLE_PHOTO_LOCATION": true,       // default false: allow photo locations to be shown (per-folder switch in the admin); maps come with it (they load Leaflet and OpenStreetMap tiles)
    // "MAP_CLUSTER_RADIUS_MILES": 1,       // how close photos must be to share one pin

    // --- Passwords ---------------------------------------------------------
    // "ADMIN_PASSWORD": "a-long-random-string",   // empty = no password yet (first-run setup asks for the code in gallery-setup-code.php, which opening ?admin makes beside index.php).
    //   Plain text is fine here: the first sign-in replaces it with a hash. To write a hash yourself:
    //   php -r 'echo password_hash("the password", PASSWORD_DEFAULT), "\n";'
    // "PRIVATE_VIEW_PASSWORD": "something-to-share",   // empty = no private view
    // "ADMIN_STAY_SIGNED_IN_DAYS": 3,      // how many days the admin stays signed in after the last visit (a Home Screen app forgets an ordinary sign-in when it is closed); 0: until the browser closes
    // "PRIVATE_VIEW_SIGNED_IN_DAYS": 3,    // how many days a guest stays in private view after the last visit; 0: until the browser closes (Settings lists every signed-in browser and can end any one)
    // "BROWSER_HISTORY_DAYS": 90,          // how long each signed-in browser's address history is kept (full IP addresses, no size cap; the Health check shows the total)
    // "BROWSER_ENDED_DAYS": 30,            // how long a signed-out browser stays under "Recently signed out" (0: not kept)
    // "BROWSER_NETWORKS_ALARM": 20,        // flag a browser used from more than this many networks (IPv4 /24, IPv6 /64) in 24 hours
    // "BROWSER_END_ON_DEVICE": false,      // sign a browser out by itself when it is used from a different device of the same kind
    // "BROWSER_END_ON_FAMILY": false,      // ... from a different kind of device (a phone one day, a Windows computer the next)
    // "BROWSER_END_ON_COUNTRY": false,     // ... from a new country (needs COUNTRY_LOOKUP, or a Cloudflare/CloudFront/Vercel country header)
    // "BROWSER_END_ON_NETWORKS": false,    // ... when it passes the networks limit
    // "COUNTRY_LOOKUP": false,             // download DB-IP's free country file (about 4 MB, CC BY 4.0) and look addresses up on this server; the only request the gallery makes by itself to another site (Settings turns it on in two steps)
    // "COUNTRY_DB_URL": "",                // another source for that file: https, or http only to 127.0.0.1; {ym} stands for the year and month
    // "DEFAULT_LINK_USES": 2,              // how many browsers a new share link lets in (a link for one person: a phone and a computer); 0: unlimited, for a group

    // --- Share links (README: Share links) ---------------------------------
    // "ENABLE_SHARE_LINKS": true,          // default false: temporary links that let someone see private photos (made in the admin's Shares tab)
    // "SHARE_HEADS_UP": true,              // default false: a thin line at the top for people using a link, saying when it ends (each link can override)
    // "LOG_PUBLIC_VISITS": true,           // default false: also log who opens the public address (their IP address, browser and what they looked at), in the Shares tab; needs ENABLE_SHARE_LINKS
    // "LOG_PUBLIC_DAYS": 30,               // how many days that log is kept (0: until you trim it)
    // "CLOSE_PUBLIC_GALLERY": true,        // default false: only you, private-view sign-ins and people using a share link see anything; everyone else gets the page of a link that is not valid (needs ENABLE_SHARE_LINKS and ADMIN_PASSWORD; sign in at .../admin)
    // "TRUSTED_PROXY": "cloudflare",       // where visitors' addresses come from, for link logs and the sign-in throttle: "direct" (the default), "cloudflare" or "proxy"
    // "TRUSTED_PROXY_RANGES": ["203.0.113.0/24"],   // with "proxy" (or to add to Cloudflare's): the addresses whose X-Forwarded-For header is believed

    // --- Server setup (advanced) ----------------------------------------
    // "EXTRA_ROOTS": ["/space/trip"],         // the folders inside each place appear at the top of the gallery (editable in the admin)
    // "EXTRA_FOLDERS": { "OldTrip2020": "/mnt/archive/trip-2020" },   // or one place shown as one folder with this name
    // "NGINX_ACCEL_REDIRECT_PREFIX": "/kb",   // only once the matching nginx config is in place (README)
}
