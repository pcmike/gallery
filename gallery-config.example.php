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
    // "ENABLE_PUBLIC_DOWNLOAD": true,      // default false: a download-original button for every visitor (signed-in ones always have it)

    // --- Locations and the map ---------------------------------------------
    // "ENABLE_PHOTO_LOCATION": true,       // default false: allow photo locations to be shown (per-folder switch in the admin)
    // "ENABLE_MAP": true,                  // default false: the maps (load Leaflet and OpenStreetMap tiles)
    // "MAP_CLUSTER_RADIUS_MILES": 1,       // how close photos must be to share one pin

    // --- Passwords ---------------------------------------------------------
    // "ADMIN_PASSWORD": "a-long-random-string",   // empty = no password yet (first-run setup is open for 5 minutes after upload).
    //   Plain text is fine here: the first sign-in replaces it with a hash. To write a hash yourself:
    //   php -r 'echo password_hash("the password", PASSWORD_DEFAULT), "\n";'
    // "PRIVATE_VIEW_PASSWORD": "something-to-share",   // empty = no private view

    // --- Share links (README: Share links) ---------------------------------
    // "ENABLE_SHARE_LINKS": true,          // default false: temporary links that let someone see private photos (made in the admin's Share tab)
    // "SHARE_HEADS_UP": true,              // default false: a thin line at the top for people using a link, saying when it ends (each link can override)
    // "TRUSTED_PROXY": "cloudflare",       // where visitors' addresses come from, for link logs and the sign-in throttle: "direct" (the default), "cloudflare" or "proxy"
    // "TRUSTED_PROXY_RANGES": ["203.0.113.0/24"],   // with "proxy" (or to add to Cloudflare's): the addresses whose X-Forwarded-For header is believed

    // --- Server setup (advanced) ----------------------------------------
    // "EXTRA_ROOTS": ["/space/trip"],         // the folders inside each place appear at the top of the gallery (editable in the admin)
    // "EXTRA_FOLDERS": { "OldTrip2020": "/mnt/archive/trip-2020" },   // or one place shown as one folder with this name
    // "NGINX_ACCEL_REDIRECT_PREFIX": "/kb",   // only once the matching nginx config is in place (README)
}
