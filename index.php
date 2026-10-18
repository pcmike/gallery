<?php
/**
 * DROP-IN PHOTO GALLERY
 * Single PHP file, no dependencies, no build step. Drop into any folder
 * of photos and it renders a gallery for that folder.
 *
 * Full documentation (grouping, nested folders, video, captions and
 * locations, the admin editor, hiding items, the maps, Markdown
 * notes, {color}/{sold}/{reserved}/{group}/{photos} directives, view
 * stats, deep linking, image caching, etc.) lives in the repo's README: github.com/pcmike/gallery. The
 * "?about" page this script serves itself (open any deployed gallery and
 * press the small gallery icon above the photos, or visit "?about" directly) is a short
 * in-app pitch that links to the GitHub repo, including a "Get it on
 * GitHub" button — ?download used to serve this file's own bytes
 * directly, but no longer exists; see the ?download handler below.
 *
 * Quick reference for anyone editing this file directly:
 *   - GALLERY_VERSION / TRACK_VIEWS / SHOW_VIEW_COUNTS_PUBLICLY /
 *     ENABLE_THUMBNAIL_CACHE / AUTO_GROUP_BY_FILENAME / PHOTO_SORT_ORDER /
 *     SORT_VIDEOS_FIRST / SORT_LOCATED_FIRST / SORT_PRIVATE_FIRST /
 *     SORT_PRIORITY / AUTO_GROUP_BY_FOLDER / FOLDER_DISPLAY_MODE / EXTRA_FOLDERS /
 *     ENABLE_VIDEO / ADMIN_PASSWORD / ENABLE_PHOTO_LOCATION /
 *     PRIVATE_VIEW_PASSWORD / NGINX_ACCEL_REDIRECT_PREFIX / ENABLE_MAP /
 *     MAP_CLUSTER_RADIUS_MILES constants are just below, and are only the
 *     DEFAULTS: a .gallery-config.php beside this file overrides them and
 *     survives replacing this file with a newer version (see
 *     gallery_setting() below and the README). TRACK_VIEWS,
 *     AUTO_GROUP_BY_FOLDER, ENABLE_VIDEO, ENABLE_PHOTO_LOCATION and
 *     ENABLE_MAP are off by default — this script writes nothing to disk
 *     (apart from thumbnails in .gallery-cache and what the admin saves),
 *     makes no outbound/third-party requests, and doesn't show a single
 *     subfolder, video file, or photo location unless you turn one on.
 *     (With AUTO_GROUP_BY_FOLDER off it still peeks into subfolders, read-
 *     only and capped, solely to warn that photos there are being skipped.)
 *     ADMIN_PASSWORD and PRIVATE_VIEW_PASSWORD are empty by default,
 *     which makes ?admin (apart from the first-run setup page) and ?reveal completely inert rather than merely
 *     unauthenticated. NGINX_ACCEL_REDIRECT_PREFIX is empty by default,
 *     which keeps every image/video served by plain PHP, same as always.
 *   - A .md file is only read if its first line is exactly {gallery}.
 *   - Photo grouping: auto by filename (minus a trailing "_01"/"-02"
 *     suffix, if AUTO_GROUP_BY_FILENAME is on), auto by subfolder (if
 *     AUTO_GROUP_BY_FOLDER is on — folder membership wins if both are
 *     on), or explicit via {group: Name} + {photos: a.jpg, b.jpg} in a
 *     .md file, which always wins over either auto-detection.
 *   - .md directives: {color: value}, {sold}, {reserved} (or {held}),
 *     {group: Name}, {photos: a.jpg, b.jpg, ...}.
 *   - See each function's own docblock below for implementation details.
 */

// Needs PHP 8.3+. Say so plainly rather than failing with a cryptic error
// halfway through the page (this check itself only uses syntax every PHP
// version since 7.0 can parse).
if (PHP_VERSION_ID < 80300) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'This gallery needs PHP 8.3 or newer; this server is running PHP ' . PHP_VERSION . '.';
    exit;
}

// Never let a browser second-guess a declared content type: a photo is an
// image and a page is a page, whatever bytes a file happens to contain.
header('X-Content-Type-Options: nosniff');

// Version shown on the "?about" page and stamped in the page source.
define('GALLERY_VERSION', '2.2.0');

/**
 * The optional settings file. Everything below is configured by the
 * gallery_setting() lines in this file, and the values in them are the
 * defaults. If a file named .gallery-config.php sits next to this script,
 * the values it holds override them — so replacing this file with a newer
 * version keeps your settings, instead of resetting them to the defaults.
 * Like the gallery's other data files it is a one-line PHP guard (so a
 * direct request answers 404) followed by plain JSON, never executed or
 * compiled, so it can't be a way to run code and a stale cache can't make a
 * saved setting appear not to have saved:
 *
 *   <?php http_response_code(404); exit; ?>
 *   {"ENABLE_VIDEO": true, "ADMIN_PASSWORD": "..."}
 *
 * Lines (or the end of a line) starting with // are ignored, for notes to
 * yourself, and a comma after the last entry is forgiven. Only the
 * settings named in this file are honored, each must be the same kind of
 * value as its default (true/false, text, a number, a list), and anything
 * else — a typo'd name, a wrong kind of value, a file that can't be read or
 * isn't valid JSON — is ignored with a note in the setup-notices banner,
 * never a broken site. Without the file nothing changes at all.
 */
/** Where the settings file lives, and what loading it found. Loaded once,
 *  the first time anything asks for a setting (the define() lines below). */
function gallery_config_path() {
    return __DIR__ . '/.gallery-config.php';
}

/** Removes // comments (whole-line or after a value) and a comma left after
 *  the last entry, from hand-edited settings text. It tracks whether it is
 *  inside a JSON string, so a "//" or a "," in a password or a path is never
 *  mistaken for either. */
function gallery_config_clean($raw) {
    $n = strlen($raw);
    // 1. comments
    $out = ''; $inStr = false;
    for ($i = 0; $i < $n; $i++) {
        $c = $raw[$i];
        if ($inStr) {
            $out .= $c;
            if ($c === '\\' && $i + 1 < $n) $out .= $raw[++$i];
            elseif ($c === '"') $inStr = false;
            continue;
        }
        if ($c === '"') { $inStr = true; $out .= $c; continue; }
        if ($c === '/' && $i + 1 < $n && $raw[$i + 1] === '/') {
            while ($i < $n && $raw[$i] !== "\n") $i++;
            $out .= "\n";
            continue;
        }
        $out .= $c;
    }
    // 2. a comma directly before a closing } or ]
    $raw = $out; $n = strlen($raw); $out = ''; $inStr = false;
    for ($i = 0; $i < $n; $i++) {
        $c = $raw[$i];
        if ($inStr) {
            $out .= $c;
            if ($c === '\\' && $i + 1 < $n) $out .= $raw[++$i];
            elseif ($c === '"') $inStr = false;
            continue;
        }
        if ($c === '"') { $inStr = true; $out .= $c; continue; }
        if ($c === ',') {
            $j = $i + 1;
            while ($j < $n && ctype_space($raw[$j])) $j++;
            if ($j < $n && ($raw[$j] === '}' || $raw[$j] === ']')) continue;
        }
        $out .= $c;
    }
    return $out;
}

/** Reads the settings file's text into settings. Returns [status, settings]
 *  where status is 'ok', 'broken' (not valid JSON) or 'invalid' (valid, but
 *  not an object of settings). */
function gallery_config_parse($raw) {
    $raw = gallery_config_clean(data_file_strip_guard($raw));
    if (trim($raw) === '') return ['ok', []];
    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) return ['broken', []];
    if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) return ['invalid', []];
    return ['ok', $decoded];
}

function gallery_config_load() {
    static $state = null;
    if ($state !== null) return $state;
    $state = ['status' => 'absent', 'cfg' => []];
    $path = gallery_config_path();
    if (!is_file($path)) return $state;
    $raw = is_readable($path) ? @file_get_contents($path) : false;
    if ($raw === false) {
        $GLOBALS['__gallery_config_problems'][] = ".gallery-config.php exists but can't be read by the web server — using the settings in index.php.";
        $state['status'] = 'unreadable';
        return $state;
    }
    [$status, $cfg] = gallery_config_parse($raw);
    if ($status === 'ok') {
        $state = ['status' => 'ok', 'cfg' => $cfg];
    } else {
        $GLOBALS['__gallery_config_problems'][] = $status === 'broken'
            ? ".gallery-config.php couldn't be read (it isn't valid JSON — a stray comma or a missing quote?) — using the settings in index.php."
            : ".gallery-config.php must hold an object of settings, like {\"NAME\": value} — using the settings in index.php.";
        $state['status'] = $status;
    }
    return $state;
}

function gallery_setting($name, $default, $allowed = null) {
    static $asked = [];
    $cfg = gallery_config_load()['cfg'];
    if ($name === null) {
        // End of the settings block: whatever the file names that nothing asked for is a typo.
        foreach (array_keys($cfg) as $k) {
            if (!isset($asked[$k])) $GLOBALS['__gallery_config_problems'][] = ".gallery-config.php: \"" . (is_string($k) ? $k : '?') . "\" isn't a setting (a typo?) — ignored.";
        }
        return null;
    }
    $asked[$name] = true;
    $GLOBALS['__gallery_defaults'][$name] = $default;   // what the admin's Site settings shows as "default"
    if (!array_key_exists($name, $cfg)) return $default;
    $v = $cfg[$name];
    $ok = is_bool($default) ? is_bool($v)
        : (is_string($default) ? is_string($v)
        : (is_array($default) ? is_array($v)
        : (is_int($default) ? (is_int($v) || is_float($v)) : false)));
    if ($ok && $allowed !== null && !in_array($v, $allowed, true)) $ok = false;
    if (!$ok) {
        $want = $allowed !== null ? 'one of ' . implode(', ', array_map(function ($a) { return "'" . $a . "'"; }, $allowed))
            : (is_bool($default) ? 'true or false' : (is_string($default) ? 'text' : (is_array($default) ? 'a list' : 'a number')));
        $GLOBALS['__gallery_config_problems'][] = ".gallery-config.php: \"" . $name . "\" should be " . $want . " — ignored.";
        return $default;
    }
    $GLOBALS['__gallery_config_applied'][] = $name;
    return $v;
}

/** The folders that really get mounted: every named EXTRA_FOLDERS entry, then each folder found inside every
 *  EXTRA_ROOTS path under its own name (the first to claim a name keeps it). Never follows a link. */
function gallery_mounts() {
    static $mounts = null;
    if ($mounts !== null) return $mounts;
    $named = EXTRA_FOLDERS;
    $roots = EXTRA_ROOTS;
    $mounts = [];
    foreach ((array) $named as $name => $path) {
        if (is_string($name) && is_string($path)) $mounts[$name] = $path;
    }
    foreach ((array) $roots as $root) {
        if (!is_string($root) || !is_dir($root) || is_link($root)) continue;
        foreach ((@scandir($root) ?: []) as $entry) {
            if ($entry[0] === '.' || isset($mounts[$entry])) continue;
            $path = rtrim($root, '/') . '/' . $entry;
            if (is_dir($path) && !is_link($path)) $mounts[$entry] = $path;
        }
    }
    return $mounts;
}

/** How many photos and videos lie directly in the EXTRA_ROOTS paths: those are not shown (only their folders are). */
function count_loose_media_in_roots() {
    global $mediaExtensions;
    $n = 0;
    foreach (EXTRA_ROOTS as $root) {
        if (!is_string($root) || !is_dir($root) || is_link($root)) continue;
        foreach ((@scandir($root) ?: []) as $entry) {
            if ($entry[0] !== '.' && is_file($root . '/' . $entry) && in_array(strtolower(pathinfo($entry, PATHINFO_EXTENSION)), $mediaExtensions, true)) $n++;
        }
    }
    return $n;
}

// Turn photo/group/page view tracking on or off. Off by default — this
// script writes nothing to the folder's disk unless you turn something
// on. Turning this on writes .gallery-stats.php or .gallery-stats.sqlite
// into the folder, and is what enables the live view-count badges. See
// the README for details.
define('TRACK_VIEWS', gallery_setting('TRACK_VIEWS', false));

// Whether view counts are shown publicly once TRACK_VIEWS is on. Off by
// default: turning on tracking shouldn't also start publishing numbers
// without you meaning it (counts still show to you in ?admin). Set to
// true to show the live badges and the footer total to visitors. Has no
// effect unless TRACK_VIEWS is also on.
define('SHOW_VIEW_COUNTS_PUBLICLY', gallery_setting('SHOW_VIEW_COUNTS_PUBLICLY', false));

// Whether a visitor who isn't signed in is offered a button to download the original of a photo. Off by default: it
// hands out every picture at full resolution. Someone signed in (the owner, or with the private-view password) always
// has the button. Where the gallery doesn't show a photo's location, a visitor who isn't signed in is given the file
// with the location blanked out (see location_scrub_plan).
define('ENABLE_PUBLIC_DOWNLOAD', gallery_setting('ENABLE_PUBLIC_DOWNLOAD', false));

// Thumbnail generation/caching. On by default: without it every photo is
// served at full original resolution as its own "thumbnail" (real
// bandwidth cost — a page of phone photos can be hundreds of megabytes),
// and HEIC/HEIF (iPhone) photos can't be shown at all, since converting
// them needs somewhere to keep the result. It only ever writes small
// derived images into a .gallery-cache folder beside this file, exposes
// nothing that isn't already in the gallery, and if that folder can't be
// written (or GD/Imagick is missing) the original photo is served as
// before. Set to false for a gallery that must never write to its folder.
// See the README for details.
define('ENABLE_THUMBNAIL_CACHE', gallery_setting('ENABLE_THUMBNAIL_CACHE', true));
// The two pictures a tile can show (size=tile and size=tilehd), both square cuts from the middle of the photo, which is all
// a tile ever shows of it: [side in pixels, JPEG quality]. A tile is 180 to 250 points wide (a phone in portrait with one
// column: about 350), so 560 is right for an ordinary screen and 1080 for a 3x phone.
const TILE_SIZES = ['tile' => [560, 82], 'tilehd' => [1080, 80]];

// Auto-group photos by filename (stripping a trailing "_01"/"-02" style
// suffix, e.g. "dp104_01.jpg" + "dp104_02.jpg" -> "dp104"). Off by
// default. Turn this on if your photos actually use that multi-angle
// naming convention (e.g. a "for sale" listing shot from several
// angles) — leaving it on for an arbitrary folder of camera photos can
// falsely merge unrelated shots that just happen to share a numeric
// filename pattern (e.g. IMG_0234.jpg / IMG_0235.jpg from two different,
// unrelated photos). See the README for details, and for how to define
// groups explicitly via {group:}/{photos:} directives in a .md file
// instead, regardless of filename.
define('AUTO_GROUP_BY_FILENAME', gallery_setting('AUTO_GROUP_BY_FILENAME', false));

// How photos are ordered inside a folder before any .md-driven repositioning: 'taken' (when each photo or video was
// taken, read from the file itself, else its modified date; oldest first), 'filename' (natural sort by name) or 'mtime'
// (file modification time, oldest first). Left unset, it is 'taken', so a video sits among the photos it was shot with,
// except when AUTO_GROUP_BY_FILENAME is on: then it is 'filename', which keeps the files of a group (alpha_01, alpha_02…)
// in sequence. Dates are read from the files once and kept in the folder's cache. A folder can have its own order, and
// visitors can change it for themselves with the sort button.
define('PHOTO_SORT_ORDER', gallery_setting('PHOTO_SORT_ORDER', AUTO_GROUP_BY_FILENAME ? 'filename' : 'taken', ['filename', 'mtime', 'taken']));
// Which way round the photos inside a folder run, and what comes first whatever the order: see media_sort_apply().
define('PHOTO_SORT_DESC', gallery_setting('PHOTO_SORT_DESC', false));
define('SORT_VIDEOS_FIRST', gallery_setting('SORT_VIDEOS_FIRST', false));
define('SORT_LOCATED_FIRST', gallery_setting('SORT_LOCATED_FIRST', false));
define('SORT_PRIVATE_FIRST', gallery_setting('SORT_PRIVATE_FIRST', false));
define('SORT_PRIORITY', gallery_setting('SORT_PRIORITY', 'private,video,located'));   // the order the "first" choices stack in
// Whether visitors get the sort button (it is also left out of a page that a notes file lays out, see the page template).
define('SHOW_SORT_BUTTON', gallery_setting('SHOW_SORT_BUTTON', true));

// Scan subfolders recursively (to any depth) and group photos by which
// one they're in. Off by default — a flat folder behaves exactly as it
// always has. Turn this on for a trip folder like /trip/Paris,
// /trip/StTropez, etc. See the README for FOLDER_DISPLAY_MODE and
// EXTRA_FOLDERS, which only matter once this is on.
define('AUTO_GROUP_BY_FOLDER', gallery_setting('AUTO_GROUP_BY_FOLDER', false));

// How a folder-group renders once AUTO_GROUP_BY_FOLDER is on: 'overview'
// (default) shows one cover card per subfolder — a highlight photo, a
// label, a recursive photo count — and clicking it drills into that
// folder's own page; 'inline' flattens every subfolder's photos
// (including anything in deeper sub-subfolders) into one ordinary full
// grid box, the same shape a filename-based group already renders as.
// 'overview' fits a trip album meant to be browsed one place at a time;
// 'inline' fits a handful of items organized into folders purely for the
// owner's own convenience, where everything should stay visible at once
// (this script's other real use case — a seller with a few items, each
// in its own folder). Only read when AUTO_GROUP_BY_FOLDER is on.
define('FOLDER_DISPLAY_MODE', gallery_setting('FOLDER_DISPLAY_MODE', 'overview', ['overview', 'inline']));

// Map a virtual top-level folder name to a real path elsewhere on the
// server, e.g. ['OldTrip2020' => '/mnt/archive-disk/trip-photos-2020'].
// Scanned exactly like a real subfolder once AUTO_GROUP_BY_FOLDER is on.
// This exists specifically so a real, legitimate need — archiving an old
// trip from a second disk — doesn't require a symlink: the scan refuses
// to follow symlinks outright (a link pointing outside the gallery root
// could otherwise make the scan's own trusted output enumerate arbitrary
// filesystem paths as "valid"), so this is the safe way to get the same
// result. The trust question becomes "did the owner write this exact
// path into the one file they control," the same category of trust as
// ADMIN_PASSWORD — not something inferred from the filesystem. Empty by
// default.
// EXTRA_ROOTS is the simpler way to use a place elsewhere on the server: a list of paths whose *folders* appear at
// the top of the gallery as if they sat in it (['/space/trip'] puts /space/trip/Paris and /space/trip/StTropez at the
// top, named Paris and StTropez). EXTRA_FOLDERS above is the "show this one place as a folder with this name" form.
// A real folder of the same name always wins, then EXTRA_FOLDERS, then the first path listed here. Photos lying
// directly in such a path aren't shown (put them in a folder); a setup notice says how many.
define('EXTRA_ROOTS', gallery_setting('EXTRA_ROOTS', []));
define('EXTRA_FOLDERS', gallery_setting('EXTRA_FOLDERS', []));

// Recognize .mov and .mp4 (iPhone's two video formats) as media items,
// eligible for everything a photo already is — filename auto-grouping,
// explicit {photos:}, folder-based grouping, ordering. Off by default:
// someone's folder might already have an unrelated .mov screen recording
// sitting in it, and turning this on is what decides the gallery should
// start treating video files as content. No transcoding, no re-encoding,
// no extracted frames — a video is served exactly as the camera produced
// it (with HTTP Range support for seeking), and shown in the grid behind
// a generic placeholder graphic rather than an actual frame, since that
// would mean adding a video-processing dependency this project doesn't
// carry. See the README for the real limitations this implies: codec
// compatibility isn't guaranteed (HEVC-encoded .mov plays natively on
// Apple platforms only), there's real bandwidth cost serving uncompressed
// clips in full, and EXIF-style location auto-fill doesn't carry over
// from an iPhone video the way it does from a photo.
define('ENABLE_VIDEO', gallery_setting('ENABLE_VIDEO', false));

// A real picture for each video (in the grid, on a folder's cover and in shared-link previews) instead of the generic
// placeholder: one frame is taken from the video with ffmpeg, when this server has it, once per video, and kept with the
// photo thumbnails. Does nothing if ffmpeg isn't there (and then the browser's own first frame is shown where it can
// play the video). Needs ENABLE_VIDEO and the thumbnail cache.
define('ENABLE_VIDEO_THUMBNAILS', gallery_setting('ENABLE_VIDEO_THUMBNAILS', true));
// Where ffmpeg is, if it isn't found by itself (it is looked for on the usual paths). A full path, in the settings file.
define('FFMPEG_PATH', gallery_setting('FFMPEG_PATH', ''));
// A short silent looping clip of each video (about 3 seconds, small), played when a visitor hovers over its tile. Made with
// ffmpeg once per video and kept with the thumbnails; does nothing without ffmpeg. Needs ENABLE_VIDEO and the thumbnail cache.
define('ENABLE_VIDEO_PREVIEWS', gallery_setting('ENABLE_VIDEO_PREVIEWS', true));

// Password for the admin editor, reached via ?admin — captions, per-item
// location/web link, display names, and raw .md editing. Empty by
// default, and that's not merely "unauthenticated": with no password
// set, ?admin does not exist (404) — the whole admin code path doesn't
// run at all — with one exception: for five minutes after this file is
// uploaded (or while an empty .gallery-setup file sits beside it) ?admin
// offers a one-time page to choose the password, and the choice is saved in
// the settings file. Set this to a long random token, not a
// memorable phrase — this is the one credential in this script that, if
// guessed, grants write access, so it deserves the strongest value you'd
// put in any constant here. See the README for the HTTPS requirement and
// the failed-login throttle this password is protected by.
define('ADMIN_PASSWORD', gallery_setting('ADMIN_PASSWORD', ''));

// Whether a photo's location (latitude/longitude, auto-filled from EXIF
// where possible, always editable by hand in the admin tool) is ever
// shown to a visitor at all. False by default — GPS coordinates are more
// sensitive than anything else this script handles, so this is the most
// deserving of an off default of any constant here. Even once this is
// on, nothing is shown until a folder's own location setting is also
// turned on in the admin tool (deployment data, not a code constant),
// and a specific photo's own per-item setting (inherit the folder
// default, always show, or always hide — three states, not a checkbox)
// doesn't override it to "hide." The admin tool itself can always see
// and edit location data regardless of this constant — it only gates
// what a visitor is ever shown. (A visitor who is signed in, the owner or
// someone with the private-view password, sees every photo's saved
// location while this is on, whatever the folder and photo switches say:
// those switches decide what visitors who aren't signed in are shown.) Real coverage is narrower than
// it sounds: EXIF GPS auto-fill works for JPEG (via exif_read_data) and,
// where Imagick can read HEIC (the same condition HEIC display already
// has), for HEIC/HEIF — PNG, GIF, WEBP, and BMP generally carry no GPS
// block, so those need the coordinates typed in by hand.
define('ENABLE_PHOTO_LOCATION', gallery_setting('ENABLE_PHOTO_LOCATION', false));

// A second, lower-privilege password that unlocks items flagged hidden
// from public view in the admin tool — scoped to viewing only, never
// editing. Empty/off by default, same pattern as ADMIN_PASSWORD. The
// bare gallery link stays completely public and frictionless regardless
// — this only matters once ?reveal is visited (a small "Private view"
// link in the footer, shown only when this is set). Deliberately a
// password, not a secret link: a link pasted into Discord/Reddit gets
// crawled automatically by that platform's own link-preview bot, and a
// secret embedded in a URL can't be revoked short of breaking the link
// for everyone who had it. This password is the weaker of the two this
// script has — it has to be spoken or texted to someone else and
// actually remembered, so expect it to be shorter and more guessable
// than ADMIN_PASSWORD — which is exactly why it gets the same
// failed-attempt throttle, not an exemption from it.
define('PRIVATE_VIEW_PASSWORD', gallery_setting('PRIVATE_VIEW_PASSWORD', ''));

// Hands matching image/video requests to nginx's own static file serving
// instead of reading them with PHP, via the X-Accel-Redirect header —
// once nginx is configured with an `internal` location for this
// gallery's path (see the README), this is the only way a hidden item's
// file is ever actually unreachable at its bare URL, not just
// unreferenced. Empty by default: that's what makes it safe to ship —
// left empty, nothing changes, and every image/video is still served by
// plain PHP readfile()/streaming exactly as before. Set to the gallery's
// own URL path (e.g. '/kb', no trailing slash) only once the matching
// nginx config is actually in place; see the README for the full setup
// and why Apache's equivalent (a plain deny rule in .htaccess) can't use
// this same mechanism.
define('NGINX_ACCEL_REDIRECT_PREFIX', gallery_setting('NGINX_ACCEL_REDIRECT_PREFIX', ''));

// The name of the cookie that remembers a browser unlocked private view
// (see PRIVATE_VIEW_PASSWORD and is_revealed() below). Derived from this
// copy's own folder, so several galleries on one domain — each with its own
// viewer password — each get their own cookie and never sign each other's
// viewers out (a cookie covers the whole domain, so a single shared name
// would have them overwrite one another). Nothing to configure. A plain
// define() here rather than where it's first used, because define()
// executes in file order (unlike a function declaration) and this has to
// run before anything below ever calls is_revealed().
define('REVEAL_COOKIE_NAME', 'gallery_reveal_' . substr(hash('sha256', __DIR__), 0, 10));

// Plots every visible, located photo on one overview map — Leaflet plus
// OpenStreetMap tiles, the only realistic option with no API key. Off by
// default: this is the *first* feature in this script to break the
// "one file, nothing else happens" promise, since it loads an external
// script and image tiles from a third party at page-load time. Has no
// effect at all unless ENABLE_PHOTO_LOCATION is also on and at least one
// photo actually has a visible location — see ENABLE_PHOTO_LOCATION's
// own doc comment for that gating. Shared as ?map
// URL, with a smaller preview embedded on the gallery root.
define('ENABLE_MAP', gallery_setting('ENABLE_MAP', false));

// How close together (in miles) two photos' locations have to be before
// the map merges them into a single cluster pin instead of showing each
// individually. 1 is a neighbourhood: photos of one sight share a pin and a
// trip across a city doesn't collapse into one. A dense city trip and a
// cross-country road trip want very different values, so this is
// deliberately tunable per deployment (and per folder) rather than a fixed
// constant buried in code. 0 turns grouping off.
define('MAP_CLUSTER_RADIUS_MILES', gallery_setting('MAP_CLUSTER_RADIUS_MILES', 1));
gallery_setting(null, null);   // done asking: report any name in the settings file that isn't a setting

$dir = __DIR__;
$self = basename(__FILE__);

// Imagick is what makes HEIC/HEIF support and quality resizing possible;
// GD is used as a fallback for resizing (but can't decode HEIC).
$imagickAvailable = class_exists('Imagick');
$gdAvailable = extension_loaded('gd');

// HEIC/HEIF requires Imagick to convert, and that conversion has nowhere
// to go without the thumbnail cache enabled (see ENABLE_THUMBNAIL_CACHE
// above) — so without both, those files are excluded rather than shown
// broken.
$imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
if ($imagickAvailable && ENABLE_THUMBNAIL_CACHE) {
    $imageExtensions[] = 'heic';
    $imageExtensions[] = 'heif';
}

/** What to call the gallery's contents in labels: "media" once videos are switched on, "photos" until then. */
function media_word() {
    return ENABLE_VIDEO ? 'media' : 'photos';
}

/** Whether a filename's extension is one of the two video formats this
 *  script recognizes when ENABLE_VIDEO is on — used everywhere a video
 *  item needs different handling than a photo (serving, the grid
 *  placeholder, cover-card selection), regardless of whether video
 *  recognition is actually turned on; it's purely an extension check. */
function is_video_file($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, ['mov', 'mp4'], true);
}

// $mediaExtensions is what the scan below actually filters by — photo
// extensions, plus .mov/.mp4 once ENABLE_VIDEO is on. Kept separate from
// $imageExtensions itself, which stays photo-only: it's still used
// downstream (image_cache_path, generate_resized_jpeg) for logic that
// only ever makes sense for an actual photo.
$mediaExtensions = $imageExtensions;
if (ENABLE_VIDEO) {
    $mediaExtensions[] = 'mov';
    $mediaExtensions[] = 'mp4';
}

/**
 * Recursively scans a real directory into a tree: ['files' => [bare
 * filenames loose directly here, already sorted per PHOTO_SORT_ORDER],
 * 'subdirs' => [folderName => nested node, ...]]. Only ever called when
 * AUTO_GROUP_BY_FOLDER is on — a flat folder keeps using the untouched
 * scan further below, so there is zero behavior change for the common
 * case regardless of anything in this function.
 *
 * Two things this has to get right every time it steps into a subfolder:
 * it must never follow a symlink (a link pointing outside the gallery
 * root would otherwise let the scan's own trusted output enumerate
 * arbitrary filesystem paths as "valid," which every downstream
 * allowlist check would then trust) — checked both directly (is_link)
 * and by confirming the resolved real path actually sits under its own
 * parent's real path, so a less obvious escape doesn't slip through
 * either. And it must exclude .gallery-cache — the one *directory* among
 * this script's own infrastructure files — from being scanned as if it
 * were a photo subfolder.
 */
function scan_folder_tree($realDir, $mediaExtensions, $self, $isRoot = true) {
    $node = ['files' => [], 'subdirs' => []];
    $entries = @scandir($realDir);
    if ($entries === false) return $node;
    $parentReal = realpath($realDir);
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        if ($isRoot && $entry === $self) continue;
        $path = $realDir . '/' . $entry;
        if (is_file($path)) {
            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if (in_array($ext, $mediaExtensions, true)) {
                $node['files'][] = $entry;
            }
            continue;
        }
        if (!is_dir($path) || $entry === '.gallery-cache' || is_link($path)) {
            continue;
        }
        $childReal = realpath($path);
        if ($childReal === false || $parentReal === false
            || strpos($childReal, $parentReal . DIRECTORY_SEPARATOR) !== 0) {
            continue;
        }
        $node['subdirs'][$entry] = scan_folder_tree($path, $mediaExtensions, $self, false);
    }
    if (PHOTO_SORT_ORDER === 'mtime') {
        usort($node['files'], function ($a, $b) use ($realDir) {
            return (@filemtime("$realDir/$a") ?: 0) <=> (@filemtime("$realDir/$b") ?: 0);
        });
    } else {
        usort($node['files'], function ($a, $b) {
            return strnatcasecmp($a, $b);
        });
    }
    return $node;
}

/**
 * For when AUTO_GROUP_BY_FOLDER is off: a bounded look for photos/videos
 * sitting in subfolders, so a first-time owner who organized a trip into
 * subfolders sees *why* the gallery looks empty instead of nothing at
 * all. Bounded on purpose — this script is often dropped into a folder
 * that already has unrelated, possibly huge subtrees (a whole web root),
 * and it must never walk all of that on every request just to print a
 * hint. Visits at most $maxDirs directories, never follows symlinks, and
 * skips dot-directories (.git, .gallery-cache). Returns [count found,
 * whether the search was cut short].
 */
function count_media_in_subfolders($realDir, $mediaExtensions, $maxDirs = 40) {
    $count = 0;
    $visited = 0;
    $truncated = false;
    $queue = [];
    foreach ((@scandir($realDir) ?: []) as $entry) {
        if ($entry[0] === '.') continue;
        $path = $realDir . '/' . $entry;
        if (is_dir($path) && !is_link($path)) $queue[] = $path;
    }
    while ($queue) {
        if ($visited++ >= $maxDirs) {
            $truncated = true;
            break;
        }
        $current = array_shift($queue);
        foreach ((@scandir($current) ?: []) as $entry) {
            if ($entry[0] === '.') continue;
            $path = $current . '/' . $entry;
            if (is_link($path)) continue;
            if (is_dir($path)) {
                $queue[] = $path;
            } elseif (in_array(strtolower(pathinfo($entry, PATHINFO_EXTENSION)), $mediaExtensions, true)) {
                $count++;
            }
        }
    }
    return [$count, $truncated];
}

/**
 * For when ENABLE_VIDEO is off: a bounded count of .mov/.mp4 files
 * sitting anywhere under the gallery root (and any EXTRA_FOLDERS), so an
 * owner who makes a "Videos/" folder and sees nothing is told why instead
 * of left guessing. Same constraints as count_media_in_subfolders():
 * never follows a linked directory, skips dot-directories, and visits at
 * most $maxDirs directories so a drop into a big web root stays cheap.
 * Returns [count found, whether the search was cut short]. A count only,
 * never names — a folder holding nothing but ignored videos isn't one the
 * owner has published, and this banner is visible to every visitor.
 */
function count_ignored_videos($realDir, $maxDirs = 40) {
    $count = 0;
    $visited = 0;
    $truncated = false;
    $queue = [$realDir];
    foreach (gallery_mounts() as $extraPath) {
        if (is_string($extraPath) && is_dir($extraPath) && !is_link($extraPath)) $queue[] = $extraPath;
    }
    while ($queue) {
        if ($visited++ >= $maxDirs) {
            $truncated = true;
            break;
        }
        $current = array_shift($queue);
        foreach ((@scandir($current) ?: []) as $entry) {
            if ($entry[0] === '.') continue;
            $path = $current . '/' . $entry;
            if (is_dir($path)) {
                if (!is_link($path)) $queue[] = $path;
            } elseif (is_video_file($entry) && is_file($path)) {
                $count++;
            }
        }
    }
    return [$count, $truncated];
}

/**
 * Merges EXTRA_FOLDERS into the root node as additional virtual
 * subfolders. These point at real paths outside the gallery root on
 * purpose — the admin wrote them into a constant they control, the same
 * category of trust as ADMIN_PASSWORD — so the symlink/containment
 * checks above (which exist to stop the scan's *own* directory listing
 * from wandering outside the root) don't apply to them. A real folder of
 * the same name already found during the normal scan always wins.
 */
function merge_extra_folders($rootNode, $mediaExtensions, $self) {
    foreach (gallery_mounts() as $virtualName => $realPath) {
        if (!is_string($virtualName) || $virtualName === '' || strpbrk($virtualName, "/\\") !== false) continue;
        if (isset($rootNode['subdirs'][$virtualName])) continue;
        if (!is_dir($realPath) || is_link($realPath)) continue;
        $rootNode['subdirs'][$virtualName] = scan_folder_tree($realPath, $mediaExtensions, $self, false);
    }
    return $rootNode;
}

/** Every file in this tree as relative paths from the tree's own root
 *  (e.g. "Paris/eiffel.jpg"), folders before nothing in particular order
 *  — each node's own files are already sorted; subfolders are visited in
 *  scandir order, which is fine since real cross-folder ordering is a
 *  display-time concern (see folder-group rendering), not this one. */
function flatten_folder_tree($node, $prefix = '') {
    $out = [];
    foreach ($node['files'] as $f) {
        $out[] = $prefix === '' ? $f : $prefix . '/' . $f;
    }
    foreach ($node['subdirs'] as $name => $sub) {
        $subPrefix = $prefix === '' ? $name : $prefix . '/' . $name;
        foreach (flatten_folder_tree($sub, $subPrefix) as $rel) {
            $out[] = $rel;
        }
    }
    return $out;
}

/** Looks up the node for a relative folder path ('' or null = root), or
 *  null if no such folder exists in the tree. */
function find_folder_node($node, $relPath) {
    if ($relPath === '' || $relPath === null) return $node;
    $current = $node;
    foreach (explode('/', trim($relPath, '/')) as $seg) {
        if ($seg === '' || !isset($current['subdirs'][$seg])) return null;
        $current = $current['subdirs'][$seg];
    }
    return $current;
}

/** Total file count across a node's entire subtree (itself + every descendant). */
function count_folder_tree_files($node) {
    $count = count($node['files']);
    foreach ($node['subdirs'] as $sub) {
        $count += count_folder_tree_files($sub);
    }
    return $count;
}

/**
 * Validates ONE claimed media path directly, without building the whole
 * file list first. ?img= and ?video= fire once per thumbnail — a grid of
 * 300 photos is 300 requests — and "scan the entire tree, then check
 * membership" costs the same on a ten-photo folder as it would, per
 * request, on thousands of photos in deep folders. This answers the same
 * question the scan's own allowlist answers, with the same rules, by
 * resolving only the path in question:
 *   - no empty, ".", ".." or NUL/backslash anywhere; no absolute path
 *   - a recognized media extension (so ENABLE_VIDEO off means no video)
 *   - never the script itself, never inside .gallery-cache
 *   - every folder along the way is a real directory, not a symlink; the
 *     first segment may instead be an EXTRA_FOLDERS virtual mount (an
 *     admin-written constant, trusted the same way — but a real folder
 *     of the same name wins, exactly as in the scan)
 *   - the final name must appear, character for character, in its
 *     folder's own listing — not merely resolve. On a case-insensitive
 *     filesystem "EIFFEL.JPG" would otherwise reach eiffel.jpg while
 *     missing the per-photo hide flag keyed on the exact name.
 *   - the folder's real path stays under its root
 * It never decides anything about *visibility*: callers still apply
 * file_is_hidden() to whatever this accepts.
 */
function valid_media_path($dir, $rel, $mediaExtensions, $self) {
    if (!is_string($rel) || $rel === '' || strlen($rel) > 2048) return false;
    if (strpbrk($rel, "\0\\") !== false) return false;
    $segments = explode('/', $rel);
    foreach ($segments as $seg) {
        if ($seg === '' || $seg === '.' || $seg === '..') return false;
    }
    $file = array_pop($segments);
    if (!empty($segments) && !AUTO_GROUP_BY_FOLDER) return false;
    if (!in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), $mediaExtensions, true)) return false;
    if (empty($segments) && $file === $self) return false;

    $current = $dir;
    $root = realpath($dir);
    foreach ($segments as $i => $seg) {
        if (strcasecmp($seg, '.gallery-cache') === 0) return false;
        $next = $current . '/' . $seg;
        if ($i === 0 && !(is_dir($next) && !is_link($next))) {
            $mount = gallery_mounts()[$seg] ?? null;
            if (!is_string($mount) || strpbrk($seg, "/\\") !== false || !is_dir($mount) || is_link($mount)) return false;
            $next = $mount;
            $root = realpath($mount);
        } elseif ($i > 0 && (!is_dir($next) || is_link($next))) {
            return false;
        } elseif ($i === 0 && !is_dir($next)) {
            return false;
        }
        $current = $next;
    }
    $realCurrent = realpath($current);
    if ($root === false || $realCurrent === false) return false;
    if ($realCurrent !== $root && strpos($realCurrent . '/', $root . '/') !== 0) return false;
    $listing = @scandir($current);
    if ($listing === false || !in_array($file, $listing, true)) return false;
    return is_file($current . '/' . $file);
}

/**
 * Resolves a relative path (e.g. "Paris/eiffel.jpg", or
 * "OldTrip2020/x.jpg" for an EXTRA_FOLDERS virtual mount) to its real
 * filesystem path. Purely a path-construction helper — this never
 * decides whether a path is *valid*; every caller still has to check the
 * requested relative path against the real, pre-built allowlist first.
 */
function resolve_real_path($dir, $relPath) {
    $slash = strpos($relPath, '/');
    $first = $slash === false ? $relPath : substr($relPath, 0, $slash);
    $mounts = gallery_mounts();
    if (isset($mounts[$first]) && is_dir($mounts[$first])) {
        $rest = $slash === false ? '' : substr($relPath, $slash + 1);
        return rtrim($mounts[$first], '/') . ($rest === '' ? '' : '/' . $rest);
    }
    return $dir . '/' . $relPath;
}

/** The real directory containing a given relative file path — where that
 *  file's colocated .gallery-stats.* / .gallery-cache actually live. */
function containing_folder_real_path($dir, $relPath) {
    $slash = strrpos($relPath, '/');
    if ($slash === false) return $dir;
    return resolve_real_path($dir, substr($relPath, 0, $slash));
}

// ?original=<file> hands a signed-in visitor (the owner, or someone with the private-view password) the original file
// itself, as a download. Everyone else gets 404, as for any other thing that isn't there.
if (isset($_GET['original'])) {
    $claimed = $_GET['original'];
    $claimedList = valid_media_path($dir, $claimed, $mediaExtensions, $self) ? [$claimed] : [];
    serve_original_download($dir, $claimed, $claimedList, $claimedList ? items_get_all_for_files($dir, $claimedList) : []);
    exit;
}

// ?strip=<video>: the grid of frames a finger scrubs through on a video's tile.
if (isset($_GET['strip'])) {
    $claimed = $_GET['strip'];
    $claimedList = valid_media_path($dir, $claimed, $mediaExtensions, $self) ? [$claimed] : [];
    serve_video_strip($dir, $claimed, $claimedList, $claimedList ? items_get_all_for_files($dir, $claimedList) : []);
    exit;
}

// ?preview=<video>: the short hover clip of a video.
if (isset($_GET['preview'])) {
    $claimed = $_GET['preview'];
    $claimedList = valid_media_path($dir, $claimed, $mediaExtensions, $self) ? [$claimed] : [];
    serve_video_preview($dir, $claimed, $claimedList, $claimedList ? items_get_all_for_files($dir, $claimedList) : []);
    exit;
}

// ?img= and ?video= answer here, before the tree is scanned: one
// direct path validation (see valid_media_path) instead of walking every
// folder for every thumbnail. serve_image()/serve_video() still run
// their own allowlist check and the hide check on whatever is passed in.
if (isset($_GET['img']) || isset($_GET['video'])) {
    $claimedIsImg = isset($_GET['img']);
    $claimed = $claimedIsImg ? $_GET['img'] : $_GET['video'];
    $claimedList = valid_media_path($dir, $claimed, $mediaExtensions, $self) ? [$claimed] : [];
    $claimedItems = $claimedList ? items_get_all_for_files($dir, $claimedList) : [];
    if ($claimedIsImg) {
        serve_image($dir, $claimed, $_GET['size'] ?? 'thumb', $claimedList, $claimedItems);
    } else {
        serve_video($dir, $claimed, $claimedList, $claimedItems);
    }
    exit;
}

if (AUTO_GROUP_BY_FOLDER) {
    $folderTree = merge_extra_folders(
        scan_folder_tree($dir, $mediaExtensions, $self),
        $mediaExtensions,
        $self
    );
    $files = flatten_folder_tree($folderTree);
} else {
    $folderTree = null;
    [$subfolderMediaCount, $subfolderMediaTruncated] = count_media_in_subfolders($dir, $mediaExtensions);
    $files = array_filter(scandir($dir), function ($file) use ($dir, $mediaExtensions, $self) {
        if ($file === $self || $file === '.' || $file === '..') return false;
        if (!is_file("$dir/$file")) return false;
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        return in_array($ext, $mediaExtensions);
    });

    // Base ordering, before any .md-driven repositioning — see
    // PHOTO_SORT_ORDER above.
    if (PHOTO_SORT_ORDER === 'mtime') {
        usort($files, function ($a, $b) use ($dir) {
            return (@filemtime("$dir/$a") ?: 0) <=> (@filemtime("$dir/$b") ?: 0);
        });
    } else {
        // Natural sort by filename, so groups like dp104_01, dp104_02,
        // dp104_03 stay together and in order, followed by neo98_01, etc.
        usort($files, function ($a, $b) {
            return strnatcasecmp($a, $b);
        });
    }
    $files = array_values($files);
}

// The photos inside each folder in the order chosen for it (see media_sort_apply).
$files = array_values(media_sort_apply($files, $dir));

// Snapshot of each file's position in this base sort, before any .md-
// driven repositioning happens — used as the "natural order" anchor for
// a group/cluster that has no prose to derive an early position from
// (see GROUPING and compute_zone_order() below).
$naturalRank = [];
foreach ($files as $i => $f) {
    $naturalRank[$f] = $i;
}

// Duration badge (see ENABLE_VIDEO's doc comment) — read once per video
// file here, alongside every other per-file precomputation, rather than
// re-parsed on every render. Missing/unparseable just omits the badge
// for that one file; it never blocks the rest of the page.
$videoDurations = [];
if (ENABLE_VIDEO) {
    foreach ($files as $f) {
        if (is_video_file($f)) {
            $duration = video_duration_seconds(resolve_real_path($dir, $f));
            if ($duration !== null) {
                $videoDurations[$f] = $duration;
            }
        }
    }
}

// Per-file caption/location/web data, set via the admin tool (?admin) —
// see the ".gallery-items.php" section below for the storage shape.
// Read unconditionally (not gated behind ADMIN_PASSWORD): a gallery that
// never touches the admin tool just gets an empty result per folder,
// cheaply, the same way an untouched .gallery-stats.php costs nothing.
$itemsData = items_get_all_for_files($dir, $files);

/**
 * Derive a group key from a filename by stripping a trailing "_01" /
 * "-02" style numeric suffix. Only strips when there's an explicit
 * separator before the digits, so a standalone file like "iphone15.jpg"
 * keeps its full name rather than losing the "15". A no-op entirely
 * when AUTO_GROUP_BY_FILENAME is off — the key is then just the
 * extension-stripped filename, same as always, which is what lets
 * prose mention an item by name (e.g. "the zebra print blanket") and
 * auto-link a standalone "zebra.jpg". This can still coincidentally
 * collide two unrelated files that share a base name but differ only
 * in extension (e.g. "topology.jpg" and "topology.png") — see the
 * disambiguation pass right after $groups is built, further down.
 */
function group_key($filename) {
    $base = pathinfo($filename, PATHINFO_FILENAME);
    if (!AUTO_GROUP_BY_FILENAME) {
        return $base;
    }
    $key = preg_replace('/[_\-]\d+$/', '', $base);
    return $key === '' ? $base : $key;
}

/**
 * Builds a whole-word, case-insensitive match pattern for a group key
 * appearing in free text. Uses explicit alnum/underscore lookarounds
 * instead of \b — \b only checks for a \w/\W transition, so it doesn't
 * treat a key starting or ending in punctuation (e.g. "+model") as a
 * distinct token and can match inside an unrelated word.
 */
function group_mention_pattern($key) {
    return '/(?<![\p{L}\p{N}_])' . preg_quote($key, '/') . '(?![\p{L}\p{N}_])/iu';
}

function slugify($text) {
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-');
}

// $groups is built later (see "GROUPING" below), after .md files are
// discovered and their {group:}/{photos:} directives are extracted —
// both the ?track endpoint and the gallery renderer need the final,
// merged version, not just the filename-based auto-detection.

// ?photo=<filename-or-relative-path> deep-links straight to that photo in
// the lightbox on page load. Only ever a value that's an exact match
// against $files, the real list this script's own scan already trusts —
// never basename()'d first, since that would strip a legitimate
// subfolder prefix like "Paris/" right back off. The allowlist's exact-
// match is what actually provides the safety here, not basename(); a
// traversal attempt never matches anything the scan itself produced,
// with or without that extra step. See serve_image() below for the same
// reasoning applied to ?img=.
$requestedPhoto = null;
if (isset($_GET['photo'])) {
    $candidate = $_GET['photo'];
    if (in_array($candidate, $files, true) && (!file_is_hidden($dir, $candidate, $itemsData) || is_revealed())) {
        $requestedPhoto = $candidate;
    }
}

// ?download used to serve this file's own bytes directly. Redirects to
// the GitHub repo instead, so anyone with an old bookmarked or shared
// link still lands somewhere useful rather than a dead end. Removed
// outright, not scrubbed, specifically so this script can never hand out
// a copy of itself — including whatever secrets later constants define —
// to a visitor who asks. A live snapshot was also just a worse copy than
// GitHub's anyway: unversioned, possibly stale or hand-edited.
if (isset($_GET['download'])) {
    header('Location: https://github.com/pcmike/gallery', true, 302);
    exit;
}

// ?about renders a full documentation page (with the download link for
// this script at the top) instead of the gallery — this is what the
// "gallery" link in the footer points to.
if (isset($_GET['about'])) {
    render_about_page();
    exit;
}

// ?mapicon — the generic share-preview image for ?map (see
// render_map_icon_image()). Inert unless ENABLE_MAP is on, like ?map.
if (ENABLE_MAP && isset($_GET['mapicon'])) {
    render_map_icon_image();
    exit;
}

// ?appicon and ?manifest — what "Add to Home Screen" needs for the gallery to open as an app with no browser bars (the
// page only points at them for the signed-in owner for now; see home_screen_app_tags()). Nothing private in either.
if (isset($_GET['appicon'])) {
    render_app_icon_image(isset($_GET['size']) && $_GET['size'] === 'large' ? 512 : 180);
    exit;
}
if (isset($_GET['manifest'])) {
    $name = trim(preg_replace('/[\x00-\x1f]+/', ' ', (string) ($_GET['name'] ?? '')));
    $name = $name === '' ? 'Gallery' : (function_exists('mb_substr') ? mb_substr($name, 0, 40) : substr($name, 0, 40));
    $start = gallery_root_href();
    if ($start === './') {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $start = is_string($path) ? preg_replace('#[^/]*$#', '', $path) : '/';
    }
    header('Content-Type: application/manifest+json; charset=UTF-8');
    header('Cache-Control: no-cache');
    echo json_encode([
        'name' => $name, 'short_name' => $name, 'start_url' => $start, 'scope' => $start, 'display' => 'standalone',
        'background_color' => '#0f0f10', 'theme_color' => '#0f0f10',
        'icons' => [
            ['src' => $start . '?appicon&v=2', 'sizes' => '180x180', 'type' => 'image/png'],
            ['src' => $start . '?appicon&size=large&v=2', 'sizes' => '512x512', 'type' => 'image/png'],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

// ?reveal — the lock in the icon strip (the one sign-in). See run_reveal_route()
// for the full HTTPS/throttle/auth flow; checked early, like ?about/
// ?download, since it's a distinct mode of this one file, not something
// that needs $groups or any of the scan below.
if (isset($_GET['reveal'])) {
    run_reveal_route($dir);
    exit;
}

/* -----------------------------------------------------------------------
 * Image serving: resized/cached thumbnails, HEIC conversion.
 * ----------------------------------------------------------------------- */

function guess_mime($ext) {
    $map = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp',
        'heic' => 'image/heic', 'heif' => 'image/heif',
        'mp4' => 'video/mp4', 'mov' => 'video/quicktime',
    ];
    return $map[$ext] ?? 'application/octet-stream';
}

/**
 * Serves an original, untransformed file — hands it to nginx's own
 * static serving via X-Accel-Redirect once NGINX_ACCEL_REDIRECT_PREFIX
 * is configured to match a real `internal` location block for this
 * gallery's own path (see the README), instead of reading it with PHP.
 * $relativePath is the file's path relative to the gallery root, used to
 * build the URL path nginx matches against its own location blocks —
 * not a filesystem path, and it has to line up exactly with that config.
 * Falls back to plain readfile() when the prefix is unset, which is also
 * what makes leaving it empty safe to ship as the default: zero nginx
 * config required, works correctly on any host, same as always.
 */
/** True when a file lies where the web server itself would find it at the address the gallery shows it under: inside the
 *  gallery's own folder, at the same relative path. Photos and videos brought in from elsewhere on the server
 *  (EXTRA_FOLDERS, EXTRA_ROOTS) do not, so nginx cannot send them: the gallery sends those itself. */
function file_is_under_web_root($srcPath, $relativePath) {
    $real = @realpath($srcPath);
    $expected = @realpath(rtrim($GLOBALS['dir'], '/') . '/' . $relativePath);
    return $real !== false && $expected !== false && $real === $expected;
}

function serve_original_file($srcPath, $relativePath, $mimeType) {
    header('Content-Type: ' . $mimeType);
    if (NGINX_ACCEL_REDIRECT_PREFIX !== '' && file_is_under_web_root($srcPath, $relativePath)) {
        // Each path segment encoded separately — the slashes themselves
        // are the URL structure nginx's location block matches against,
        // not something to escape away.
        $urlPath = implode('/', array_map('rawurlencode', explode('/', $relativePath)));
        header('X-Accel-Redirect: ' . rtrim(NGINX_ACCEL_REDIRECT_PREFIX, '/') . '/' . $urlPath);
        return;
    }
    readfile($srcPath);
}

/* -----------------------------------------------------------------------
 * A copy of an original without its location. A photo whose location the
 * gallery does not show is downloaded with the coordinates blanked out
 * of the file, never re-encoded: location_scrub_plan() finds where the
 * location sits (EXIF's GPS block, the XMP and IPTC place fields, a
 * video's location atom) and returns same-length replacements, so the
 * picture is byte-for-byte what it was and the file keeps its size.
 * Null means the file couldn't be read safely, and then nothing is sent.
 * ----------------------------------------------------------------------- */

/** $len bytes at $off, or null if they aren't there (or the block is absurdly large for metadata). */
function scrub_read($fh, $off, $len) {
    if ($len < 0 || $off < 0 || $len > 33554432) return null;
    if ($len === 0) return '';
    if (fseek($fh, $off) !== 0) return null;
    $b = fread($fh, $len);
    return is_string($b) && strlen($b) === $len ? $b : null;
}

/** The GPS block of a TIFF (the shape EXIF uses), zeroed: [[fileOffset, bytes], ...], or null if it doesn't parse.
 *  $base is where the TIFF starts in the file. */
function scrub_tiff_gps($tiff, $base) {
    $n = strlen($tiff);
    if ($n < 8) return null;
    $le = substr($tiff, 0, 2) === 'II';
    if (!$le && substr($tiff, 0, 2) !== 'MM') return null;
    $u16 = function ($o) use ($tiff, $n, $le) { return $o >= 0 && $o + 2 <= $n ? unpack($le ? 'v' : 'n', $tiff, $o)[1] : null; };
    $u32 = function ($o) use ($tiff, $n, $le) { return $o >= 0 && $o + 4 <= $n ? unpack($le ? 'V' : 'N', $tiff, $o)[1] : null; };
    if ($u16(2) !== 42) return null;
    $sizes = [1 => 1, 2 => 1, 3 => 2, 4 => 4, 5 => 8, 6 => 1, 7 => 1, 8 => 2, 9 => 4, 10 => 8, 11 => 4, 12 => 8, 13 => 4, 16 => 8, 17 => 4, 18 => 4];
    $patches = [];
    $zero = function ($off, $len) use (&$patches, $base, $n) {
        if ($off < 0 || $len < 0 || $off + $len > $n) return false;
        if ($len > 0) $patches[] = [$base + $off, str_repeat("\0", $len)];
        return true;
    };
    $ifd = $u32(4);
    for ($guard = 0; $ifd !== null && $ifd !== 0 && $guard < 4; $guard++) {
        $count = $u16($ifd);
        if ($count === null || $count > 1000) return null;
        for ($i = 0; $i < $count; $i++) {
            $e = $ifd + 2 + 12 * $i;
            $tag = $u16($e);
            if ($tag === null) return null;
            if ($tag !== 0x8825) continue;
            $gps = $u32($e + 8);
            $g = $gps === null ? null : $u16($gps);
            if ($g === null || $g > 256) return null;
            for ($j = 0; $j < $g; $j++) {
                $ge = $gps + 2 + 12 * $j;
                $type = $u16($ge + 2); $cnt = $u32($ge + 4);
                if ($type === null || $cnt === null) return null;
                $size = ($sizes[$type] ?? 1) * $cnt;
                if ($size > 4) { $at = $u32($ge + 8); if ($at === null || !$zero($at, $size)) return null; }
            }
            if (!$zero($gps, 2 + 12 * $g + 4)) return null;
        }
        $ifd = $u32($ifd + 2 + 12 * $count);
    }
    return $patches;
}

/** XMP text: the values of GPS and place-name properties, replaced by spaces of the same length. */
function scrub_xmp_patches($xml, $base) {
    $patches = [];
    $names = 'exif:GPS[A-Za-z]+|photoshop:(?:City|State|Country)|Iptc4xmpCore:(?:Location|CountryCode)';
    foreach (['/\b(?:' . $names . ')\s*=\s*(["\'])(.*?)\1/s' => 2, '/<(' . $names . ')(?:\s[^>]*)?>(.*?)<\/\1\s*>/s' => 2] as $re => $group) {
        if (!preg_match_all($re, $xml, $m, PREG_OFFSET_CAPTURE)) continue;
        foreach ($m[$group] as [$text, $off]) {
            if ($text !== '') $patches[] = [$base + $off, str_repeat(' ', strlen($text))];
        }
    }
    return $patches;
}

/** IPTC (inside a Photoshop APP13 block): the city, sub-location, state and country fields, blanked. */
function scrub_iptc_patches($d, $base) {
    $patches = [];
    $n = strlen($d);
    $p = 0;
    while ($p + 12 <= $n && substr($d, $p, 4) === '8BIM') {
        $id = unpack('n', $d, $p + 4)[1];
        $nameLen = ord($d[$p + 6]);
        $q = $p + 7 + $nameLen; if (($nameLen + 1) % 2) $q++;
        if ($q + 4 > $n) break;
        $len = unpack('N', $d, $q)[1];
        $dataAt = $q + 4;
        if ($id === 0x0404) {
            $r = $dataAt; $end = min($n, $dataAt + $len);
            while ($r + 5 <= $end && $d[$r] === "\x1C") {
                $rec = ord($d[$r + 1]); $tag = ord($d[$r + 2]); $l = unpack('n', $d, $r + 3)[1];
                if ($l & 0x8000 || $r + 5 + $l > $end) break;
                if ($rec === 2 && in_array($tag, [90, 92, 95, 100, 101], true) && $l > 0) $patches[] = [$base + $r + 5, str_repeat(' ', $l)];
                $r += 5 + $l;
            }
        }
        $p = $dataAt + $len + ($len % 2);
    }
    return $patches;
}

function scrub_plan_jpeg($fh, $size) {
    if (scrub_read($fh, 0, 2) !== "\xFF\xD8") return null;
    $patches = [];
    $pos = 2;
    while (true) {
        if ($pos + 2 > $size) return null;
        $h = scrub_read($fh, $pos, 2);
        if ($h === null || $h[0] !== "\xFF") return null;
        $m = ord($h[1]);
        if ($m === 0xFF) { $pos++; continue; }
        if ($m === 0xD8 || $m === 0x01 || ($m >= 0xD0 && $m <= 0xD7)) { $pos += 2; continue; }
        if ($m === 0xDA || $m === 0xD9) break;   // the picture itself starts: every piece of metadata is before this
        $lenBytes = scrub_read($fh, $pos + 2, 2);
        if ($lenBytes === null) return null;
        $len = unpack('n', $lenBytes)[1];
        if ($len < 2) return null;
        $dataOff = $pos + 4; $dataLen = $len - 2;
        if ($m === 0xE1 || $m === 0xED) {
            $data = scrub_read($fh, $dataOff, $dataLen);
            if ($data === null) return null;
            if ($m === 0xE1 && strncmp($data, "Exif\0\0", 6) === 0) {
                $t = scrub_tiff_gps(substr($data, 6), $dataOff + 6);
                if ($t === null) return null;
                $patches = array_merge($patches, $t);
            } elseif ($m === 0xE1 && strncmp($data, 'http://ns.adobe.com/', 20) === 0) {
                $patches = array_merge($patches, scrub_xmp_patches($data, $dataOff));
            } elseif ($m === 0xED && strncmp($data, "Photoshop 3.0\0", 14) === 0) {
                $patches = array_merge($patches, scrub_iptc_patches(substr($data, 14), $dataOff + 14));
            }
        }
        $pos += 2 + $len;
    }
    return $patches;
}

function scrub_plan_png($fh, $size) {
    if (scrub_read($fh, 0, 8) !== "\x89PNG\r\n\x1a\n") return null;
    $patches = [];
    $pos = 8;
    while ($pos + 12 <= $size) {
        $h = scrub_read($fh, $pos, 8);
        if ($h === null) return null;
        $len = unpack('N', $h)[1]; $type = substr($h, 4, 4);
        if ($pos + 12 + $len > $size) return null;
        if ($type === 'eXIf' || $type === 'iTXt') {
            $data = scrub_read($fh, $pos + 8, $len);
            if ($data === null) return null;
            $local = null;
            if ($type === 'eXIf') $local = scrub_tiff_gps($data, 0);
            elseif (strncmp($data, 'XML:com.adobe.xmp', 17) === 0) $local = scrub_xmp_patches($data, 0);
            else $local = [];
            if ($local === null) return null;
            if ($local) {
                foreach ($local as [$o, $b]) $data = substr_replace($data, $b, $o, strlen($b));
                $patches[] = [$pos + 8, $data];
                $patches[] = [$pos + 8 + $len, pack('N', crc32($type . $data))];   // the chunk's checksum
            }
        }
        if ($type === 'IEND') break;
        $pos += 12 + $len;
    }
    return $patches;
}

function scrub_plan_webp($fh, $size) {
    $h = scrub_read($fh, 0, 12);
    if ($h === null || substr($h, 0, 4) !== 'RIFF' || substr($h, 8, 4) !== 'WEBP') return null;
    $patches = [];
    $pos = 12;
    while ($pos + 8 <= $size) {
        $c = scrub_read($fh, $pos, 8);
        if ($c === null) return null;
        $type = substr($c, 0, 4); $len = unpack('V', $c, 4)[1];
        if ($pos + 8 + $len > $size) return null;
        if ($type === 'EXIF' || $type === 'XMP ') {
            $data = scrub_read($fh, $pos + 8, $len);
            if ($data === null) return null;
            if ($type === 'EXIF') {
                $skip = strncmp($data, "Exif\0\0", 6) === 0 ? 6 : 0;
                $t = scrub_tiff_gps(substr($data, $skip), $pos + 8 + $skip);
                if ($t === null) return null;
                $patches = array_merge($patches, $t);
            } else {
                $patches = array_merge($patches, scrub_xmp_patches($data, $pos + 8));
            }
        }
        $pos += 8 + $len + ($len % 2);
    }
    return $patches;
}

/** The boxes (size, type) laid end to end between $from and $to of an MP4/MOV/HEIC file. */
function scrub_boxes($fh, $from, $to) {
    $out = [];
    $pos = $from;
    while ($pos + 8 <= $to && count($out) < 4096) {
        $h = scrub_read($fh, $pos, 8);
        if ($h === null) return null;
        $len = unpack('N', $h)[1]; $type = substr($h, 4, 4); $head = 8;
        if ($len === 1) {
            $big = scrub_read($fh, $pos + 8, 8);
            if ($big === null) return null;
            $len = unpack('J', $big)[1]; $head = 16;
        } elseif ($len === 0) {
            $len = $to - $pos;
        }
        if ($len < $head || $pos + $len > $to) return null;
        $out[] = ['at' => $pos, 'type' => $type, 'data' => $pos + $head, 'end' => $pos + $len];
        $pos += $len;
    }
    return $out;
}

function scrub_plan_mp4($fh, $size) {
    $patches = [];
    $walk = function ($from, $to, $depth) use (&$walk, &$patches, $fh) {
        $boxes = scrub_boxes($fh, $from, $to);
        if ($boxes === null) return false;
        foreach ($boxes as $b) {
            $type = $b['type'];
            if ($type === "\xA9xyz" || $type === 'loci') {
                if ($b['end'] > $b['data']) $patches[] = [$b['data'], str_repeat("\0", $b['end'] - $b['data'])];
            } elseif (in_array($type, ['moov', 'trak', 'udta'], true) && $depth < 6) {
                if (!$walk($b['data'], $b['end'], $depth + 1)) return false;
            } elseif ($type === 'meta' && $depth < 6) {
                // ISO's meta box has a 4-byte version first; QuickTime's doesn't.
                $probe = scrub_read($fh, $b['data'] + 4, 4);
                $inner = $probe === 'hdlr' ? $b['data'] : $b['data'] + 4;
                $kids = scrub_boxes($fh, $inner, $b['end']);
                if ($kids === null) return false;
                $locationKeys = [];
                foreach ($kids as $k) {
                    if ($k['type'] !== 'keys') continue;
                    $head = scrub_read($fh, $k['data'], 8);
                    if ($head === null) return false;
                    $entries = unpack('N', $head, 4)[1];
                    $at = $k['data'] + 8;
                    for ($i = 1; $i <= $entries && $i < 512; $i++) {
                        $eh = scrub_read($fh, $at, 8);
                        if ($eh === null) return false;
                        $elen = unpack('N', $eh)[1];
                        if ($elen < 8 || $at + $elen > $k['end']) return false;
                        $name = scrub_read($fh, $at + 8, $elen - 8);
                        if ($name !== null && stripos($name, 'location') !== false) $locationKeys[$i] = true;
                        $at += $elen;
                    }
                }
                foreach ($kids as $k) {
                    if ($k['type'] !== 'ilst' || !$locationKeys) continue;
                    $items = scrub_boxes($fh, $k['data'], $k['end']);
                    if ($items === null) return false;
                    foreach ($items as $item) {
                        if (!isset($locationKeys[unpack('N', $item['type'])[1]])) continue;
                        $datas = scrub_boxes($fh, $item['data'], $item['end']);
                        if ($datas === null) return false;
                        foreach ($datas as $d) {
                            if ($d['type'] === 'data' && $d['end'] - $d['data'] > 8) $patches[] = [$d['data'] + 8, str_repeat(' ', $d['end'] - $d['data'] - 8)];
                        }
                    }
                }
            }
        }
        return true;
    };
    return $walk(0, $size, 0) ? $patches : null;
}

function scrub_plan_heif($fh, $size) {
    $top = scrub_boxes($fh, 0, $size);
    if ($top === null) return null;
    $meta = null;
    foreach ($top as $b) if ($b['type'] === 'meta') $meta = $b;
    if ($meta === null) return [];
    $kids = scrub_boxes($fh, $meta['data'] + 4, $meta['end']);
    if ($kids === null) return null;
    $items = [];   // item id => 'exif' | 'xmp'
    foreach ($kids as $k) {
        if ($k['type'] !== 'iinf') continue;
        $ver = scrub_read($fh, $k['data'], 1);
        $skip = $ver === "\0" ? 6 : 8;
        $infes = scrub_boxes($fh, $k['data'] + $skip, $k['end']);
        if ($infes === null) return null;
        foreach ($infes as $inf) {
            if ($inf['type'] !== 'infe') continue;
            $body = scrub_read($fh, $inf['data'], min(256, $inf['end'] - $inf['data']));
            if ($body === null || strlen($body) < 12) continue;
            $v = ord($body[0]);
            if ($v < 2) continue;
            $id = $v === 2 ? unpack('n', $body, 4)[1] : unpack('N', $body, 4)[1];
            $o = $v === 2 ? 8 : 10;
            $itemType = substr($body, $o, 4);
            if ($itemType === 'Exif') $items[$id] = 'exif';
            elseif ($itemType === 'mime' && stripos(substr($body, $o + 4), 'rdf+xml') !== false) $items[$id] = 'xmp';
        }
    }
    if (!$items) return [];
    $patches = [];
    foreach ($kids as $k) {
        if ($k['type'] !== 'iloc') continue;
        $h = scrub_read($fh, $k['data'], 8);
        if ($h === null) return null;
        $ver = ord($h[0]);
        $offSize = ord($h[4]) >> 4; $lenSize = ord($h[4]) & 15; $baseSize = ord($h[5]) >> 4; $idxSize = $ver > 0 ? ord($h[5]) & 15 : 0;
        $count = $ver < 2 ? unpack('n', $h, 6)[1] : unpack('N', scrub_read($fh, $k['data'] + 6, 4))[1];
        $at = $k['data'] + ($ver < 2 ? 8 : 10);
        $num = function ($len) use ($fh, &$at) {
            if ($len === 0) return 0;
            $b = scrub_read($fh, $at, $len); $at += $len;
            if ($b === null) return null;
            $v = 0; for ($i = 0; $i < $len; $i++) $v = ($v << 8) | ord($b[$i]);
            return $v;
        };
        for ($i = 0; $i < $count && $i < 4096; $i++) {
            $id = $num($ver < 2 ? 2 : 4);
            $method = 0;
            if ($ver > 0) { $m = $num(2); if ($m === null) return null; $method = $m & 15; }
            $num(2);
            $base = $num($baseSize); $ext = $num(2);
            if ($id === null || $base === null || $ext === null || $ext > 64) return null;
            $extents = [];
            for ($e = 0; $e < $ext; $e++) {
                if ($idxSize) $num($idxSize);
                $eo = $num($offSize); $el = $num($lenSize);
                if ($eo === null || $el === null) return null;
                $extents[] = [$base + $eo, $el];
            }
            if (!isset($items[$id])) continue;
            if ($method !== 0 || count($extents) !== 1) return null;   // not where it can be safely reached
            [$eo, $el] = $extents[0];
            $data = scrub_read($fh, $eo, $el);
            if ($data === null) return null;
            if ($items[$id] === 'exif') {
                if (strlen($data) < 12) return null;
                $skip = 4 + unpack('N', $data)[1];
                $t = scrub_tiff_gps(substr($data, $skip), $eo + $skip);
                if ($t === null) return null;
                $patches = array_merge($patches, $t);
            } else {
                $patches = array_merge($patches, scrub_xmp_patches($data, $eo));
            }
        }
    }
    return $patches;
}

/** What to overwrite in this file to take its location out: [[fileOffset, bytes], ...] sorted, not overlapping, each
 *  replacing as many bytes as it holds; [] when there is nothing to remove; null when the file can't be read
 *  safely (a damaged or unusual one), in which case it must not be handed out. */
function location_scrub_plan($path) {
    $size = @filesize($path);
    $fh = $size === false ? false : @fopen($path, 'rb');
    if (!$fh) return null;
    try {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === 'jpg' || $ext === 'jpeg') $plan = scrub_plan_jpeg($fh, $size);
        elseif ($ext === 'png') $plan = scrub_plan_png($fh, $size);
        elseif ($ext === 'webp') $plan = scrub_plan_webp($fh, $size);
        elseif ($ext === 'heic' || $ext === 'heif') $plan = scrub_plan_heif($fh, $size);
        elseif ($ext === 'mp4' || $ext === 'mov') $plan = scrub_plan_mp4($fh, $size);
        else $plan = [];   // gif, bmp: nothing in them that says where
    } catch (Throwable $e) {
        $plan = null;
    } finally {
        fclose($fh);
    }
    if ($plan === null) return null;
    usort($plan, function ($a, $b) { return $a[0] <=> $b[0]; });
    $merged = [];
    foreach ($plan as [$o, $b]) {
        $last = count($merged) - 1;
        if ($last >= 0 && $o <= $merged[$last][0] + strlen($merged[$last][1])) {
            $rel = $o - $merged[$last][0];
            $merged[$last][1] = substr($merged[$last][1], 0, $rel) . $b . substr($merged[$last][1], $rel + strlen($b));
        } else {
            $merged[] = [$o, $b];
        }
    }
    return $merged;
}

/** $chunk, which starts at byte $pos of the file, with whatever part of $patches falls inside it laid over it. */
function apply_patches_to_chunk($chunk, $pos, $patches) {
    $end = $pos + strlen($chunk);
    foreach ($patches as [$o, $b]) {
        if ($o >= $end || $o + strlen($b) <= $pos) continue;
        $from = max($o, $pos); $to = min($o + strlen($b), $end);
        $chunk = substr_replace($chunk, substr($b, $from - $o, $to - $from), $from - $pos, $to - $from);
    }
    return $chunk;
}

/** Sends $fh's bytes (from its current start, $size of them) to the visitor with $patches laid over them. */
function stream_with_patches($fh, $size, $patches, $emit) {
    $pos = 0;
    while ($pos < $size) {
        $chunk = fread($fh, min(1048576, $size - $pos));
        if ($chunk === false || $chunk === '') break;
        $emit(apply_patches_to_chunk($chunk, $pos, $patches));
        $pos += strlen($chunk);
    }
}

/** What to overwrite in the original at $srcPath before it is given to this visitor: [] for nothing (a signed-in
 *  visitor, the owner or someone with the private-view password, gets the file exactly as it is, and so does anyone for
 *  a photo whose location the gallery shows), a plan for a visitor who isn't signed in and a photo whose location is
 *  hidden, or null when that can't be done safely, in which case the file must not be given out. */
function visitor_scrub_plan($srcPath, $filename, $itemsData) {
    if (is_revealed() || photo_location_visible($itemsData[$filename] ?? [])) return [];
    return location_scrub_plan($srcPath);
}

/** Sends the original file to this visitor (see visitor_scrub_plan). False, with nothing sent, when it may not be.
 *  $cacheControl is sent with a file that is the same for everyone; a file made differently for different visitors is
 *  kept out of shared caches. */
function send_original_for_visitor($srcPath, $filename, $itemsData, $cacheControl = null) {
    $plan = visitor_scrub_plan($srcPath, $filename, $itemsData);
    if ($plan === null) return false;
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if ($plan === []) {
        if ($cacheControl !== null) header('Cache-Control: ' . $cacheControl);
        serve_original_file($srcPath, $filename, guess_mime($ext));
        return true;
    }
    $size = @filesize($srcPath);
    $fh = $size === false ? false : @fopen($srcPath, 'rb');
    if (!$fh) return false;
    header('Content-Type: ' . guess_mime($ext));
    header('Content-Length: ' . $size);
    header('Cache-Control: private, max-age=86400');
    header('Vary: Cookie');
    stream_with_patches($fh, $size, $plan, function ($chunk) { echo $chunk; flush(); });
    fclose($fh);
    return true;
}

/* -----------------------------------------------------------------------
 * ?archive: every original in the gallery (or in one folder and below) as
 * one ZIP, for the owner and for visitors with the private-view password.
 * It is written to the visitor's connection as it is built, so nothing is
 * stored on the server however big the gallery is: each file is read once,
 * in small pieces, and sent. Files are stored, not compressed (photos and
 * videos are already compressed, so it would only cost time), each with a
 * "data descriptor" after it carrying its checksum and size, which is what
 * lets the header go out before the file has been read. The total size is
 * known in advance (nothing is compressed), so the download has a real
 * progress bar and an interrupted one is noticed as incomplete. Over 4 GB or
 * 65,535 files it switches to the ZIP64 variant, which every current unzip
 * tool reads. Only a couple of archives are made at the same time.
 * ----------------------------------------------------------------------- */

/** The files an archive of $files would hold: [{rel, path, size, mtime}], skipping anything unreadable. */
function archive_entries($dir, $files) {
    $out = [];
    foreach ($files as $rel) {
        $path = resolve_real_path($dir, $rel);
        $st = is_file($path) && is_readable($path) ? @stat($path) : false;
        if ($st) $out[] = ['rel' => $rel, 'path' => $path, 'size' => (int) $st['size'], 'mtime' => (int) $st['mtime']];
    }
    return $out;
}

/** "1.4 GB" */
function human_bytes($n) {
    if ($n < 1024) return $n . ' bytes';
    foreach (['KB', 'MB', 'GB', 'TB'] as $i => $unit) {
        $n /= 1024;
        if ($n < 1024 || $unit === 'TB') return ($n >= 100 ? number_format($n, 0) : number_format($n, 1)) . ' ' . $unit;
    }
}

/** [dosTime, dosDate] for a ZIP entry. */
function archive_dos_time($ts) {
    $d = getdate(max(315532800, $ts));
    return [($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2), (($d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday']];
}

/** A path as a ZIP entry name: UTF-8, forward slashes, nothing that could climb out of the folder it is unpacked into. */
function archive_entry_name($name) {
    if (!preg_match('//u', $name)) $name = mb_convert_encoding($name, 'UTF-8', 'ISO-8859-1');
    $name = preg_replace('/[\x00-\x1F\x7F\\\\]/', '_', $name);
    $parts = array_filter(explode('/', $name), function ($p) { return $p !== '' && $p !== '.' && $p !== '..'; });
    return implode('/', $parts);
}

/** The exact size of the ZIP that archive_stream() will send. */
function archive_total_size($sizes, $names, $zip64) {
    $total = 0;
    foreach ($sizes as $i => $size) {
        $n = strlen($names[$i]);
        $total += 30 + $n + ($zip64 ? 20 : 0) + $size + ($zip64 ? 24 : 16) + 46 + $n + ($zip64 ? 28 : 0);
    }
    return $total + 22 + ($zip64 ? 56 + 20 : 0);
}

/** Sends the ZIP of $entries ([{name, path, size, mtime}]) to the visitor. Returns false (having sent nothing) if the
 *  total size is beyond what a ZIP can describe. */
function archive_stream($entries, $zip64) {
    $offset = 0;
    $central = '';
    $emit = function ($bytes) use (&$offset) {
        echo $bytes;
        $offset += strlen($bytes);
    };
    foreach ($entries as $e) {
        if (connection_aborted()) return;
        $name = $e['name']; $size = $e['size'];
        [$time, $date] = archive_dos_time($e['mtime']);
        $at = $offset;
        $emit(pack('VvvvvvVVVvv', 0x04034b50, $zip64 ? 45 : 20, 0x0808, 0, $time, $date, 0, $zip64 ? 0xFFFFFFFF : 0, $zip64 ? 0xFFFFFFFF : 0, strlen($name), $zip64 ? 20 : 0)
            . $name . ($zip64 ? pack('vvPP', 1, 16, 0, 0) : ''));
        $fh = @fopen($e['path'], 'rb');
        if (!$fh) return;   // the connection ends short of its length, so the download shows as failed, never as a good file
        $ctx = hash_init('crc32b');
        for ($left = $size; $left > 0;) {
            $chunk = fread($fh, min(1048576, $left));
            if ($chunk === false || $chunk === '') { fclose($fh); return; }
            hash_update($ctx, $chunk);
            $emit($chunk);
            $left -= strlen($chunk);
            if (connection_aborted()) { fclose($fh); return; }
            flush();
        }
        fclose($fh);
        $crc = hexdec(hash_final($ctx));
        $emit(pack('VV', 0x08074b50, $crc) . ($zip64 ? pack('PP', $size, $size) : pack('VV', $size, $size)));
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 0x0300 | ($zip64 ? 45 : 20), $zip64 ? 45 : 20, 0x0808, 0, $time, $date, $crc,
                $zip64 ? 0xFFFFFFFF : $size, $zip64 ? 0xFFFFFFFF : $size, strlen($name), $zip64 ? 28 : 0, 0, 0, 0, 0x81A40000, $zip64 ? 0xFFFFFFFF : $at)
            . $name . ($zip64 ? pack('vvPPP', 1, 24, $size, $size, $at) : '');
    }
    $n = count($entries);
    $cdAt = $offset;
    $emit($central);
    if ($zip64) {
        $emit(pack('VPvvVVPPPP', 0x06064b50, 44, 45, 45, 0, 0, $n, $n, strlen($central), $cdAt));
        $emit(pack('VVPV', 0x07064b50, 0, $cdAt + strlen($central), 1));
        $emit(pack('VvvvvVVv', 0x06054b50, 0, 0, 0xFFFF, 0xFFFF, 0xFFFFFFFF, 0xFFFFFFFF, 0));
    } else {
        $emit(pack('VvvvvVVv', 0x06054b50, 0, 0, $n, $n, strlen($central), $cdAt, 0));
    }
    flush();
}

/** One of a couple of places to make an archive at once, or null if they are all taken. The lock goes when the request ends. */
function archive_take_slot() {
    $base = sys_get_temp_dir() . '/gallery-archive-' . substr(hash('sha256', __DIR__), 0, 10) . '-';
    for ($i = 1; $i <= 2; $i++) {
        $fh = @fopen($base . $i . '.lock', 'c');
        if ($fh && flock($fh, LOCK_EX | LOCK_NB)) return $fh;
        if ($fh) fclose($fh);
    }
    return null;
}

/** ?archive[&folder=...]: the originals of this gallery or folder as one ZIP. $files is every file the visitor may see;
 *  $folder is the folder asked for (null for the whole gallery). Signed-in visitors only, files exactly as they are. */
function run_archive_route($dir, $files, $folder, $rootTitle, $folderName) {
    send_sensitive_page_headers();
    if (!is_revealed()) {
        http_response_code(404);
        return;
    }
    $entries = archive_entries($dir, $files);
    if (!$entries) {
        http_response_code(404);
        return;
    }
    if (count($entries) > 100000) {
        http_response_code(413);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'That is more files than one archive can hold. Download a folder at a time.';
        return;
    }
    $root = archive_entry_name($folder === null ? $rootTitle : $folderName);
    if ($root === '') $root = 'gallery';
    $prefix = $folder === null ? '' : $folder . '/';
    foreach ($entries as $i => $e) {
        $entries[$i]['name'] = $root . '/' . archive_entry_name($prefix !== '' && strncmp($e['rel'], $prefix, strlen($prefix)) === 0 ? substr($e['rel'], strlen($prefix)) : $e['rel']);
    }
    $sizes = array_column($entries, 'size'); $names = array_column($entries, 'name');
    $zip64 = count($entries) >= 0xFFFF || max($sizes) >= 0xFFFFFFFF || archive_total_size($sizes, $names, false) >= 0xFFFFFFF0;
    $total = archive_total_size($sizes, $names, $zip64);
    $slot = archive_take_slot();
    if ($slot === null) {
        http_response_code(503);
        header('Retry-After: 30');
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'An archive is already being made for someone else. Try again in a minute.';
        return;
    }
    $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $root); $ascii = trim($ascii, '_') !== '' ? $ascii : 'gallery';
    @set_time_limit(0);
    ignore_user_abort(false);
    @ini_set('zlib.output_compression', '0');
    if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');
    while (ob_get_level() > 0) @ob_end_clean();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $ascii . '.zip"; filename*=UTF-8\'\'' . rawurlencode($root . '.zip'));
    header('Content-Length: ' . $total);
    header('X-Content-Type-Options: nosniff');
    header('X-Accel-Buffering: no');
    header('Cache-Control: no-store');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') return;
    archive_stream($entries, $zip64);
}

/** ?original=: the original file as a download. Offered to anyone signed in, and to everyone when ENABLE_PUBLIC_DOWNLOAD is
 *  on; a visitor who isn't signed in gets a photo whose location the gallery hides with the location blanked out. */
function serve_original_download($dir, $filename, $validFiles, $itemsData = []) {
    send_sensitive_page_headers();
    if (!is_string($filename) || !in_array($filename, $validFiles, true) || (!is_revealed() && !ENABLE_PUBLIC_DOWNLOAD)
        || (file_is_hidden($dir, $filename, $itemsData) && !is_revealed())) {
        http_response_code(404);
        return;
    }
    $srcPath = resolve_real_path($dir, $filename);
    $size = @filesize($srcPath);
    if (!is_file($srcPath) || $size === false) {
        http_response_code(404);
        return;
    }
    $name = basename($filename);
    $ascii = preg_replace('/[^A-Za-z0-9._ -]/', '_', $name);
    // Checked before anything is sent: a file whose location can't be removed safely is not handed out.
    if (visitor_scrub_plan($srcPath, $filename, $itemsData) === null) {
        http_response_code(422);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "This file's location can't be removed safely, so it isn't offered as a download.";
        return;
    }
    header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
    header('X-Content-Type-Options: nosniff');
    if (!send_original_for_visitor($srcPath, $filename, $itemsData)) http_response_code(404);
}

/* -----------------------------------------------------------------------
 * A picture for a video. ffmpeg, when this server has it, takes one frame
 * (about a third of a second in, or the first frame of a very short clip),
 * once per video; that frame is kept in the thumbnail cache and everything
 * else (the grid thumbnail, the folder cover, the share preview) is cut from
 * it exactly as for a photo. ffmpeg is started with a fixed list of
 * arguments (never through a shell), with a time limit, one at a time, and
 * what it writes carries no metadata. A video it can't read, or no ffmpeg,
 * gives the generic placeholder as before.
 * ----------------------------------------------------------------------- */

/** Where ffmpeg is on this server, or '' if it can't be used: the setting is off, the functions to start a program are
 *  not available, or no ffmpeg answers. Looked up once a day and remembered outside the web folder. */
function ffmpeg_binary() {
    static $found = null;
    if ($found !== null) return $found;
    $found = '';
    if (!function_exists('proc_open')) return $found;
    $remember = sys_get_temp_dir() . '/gallery-ffmpeg-' . substr(hash('sha256', __DIR__ . '|' . FFMPEG_PATH), 0, 10) . '.txt';
    if (is_file($remember) && time() - (int) @filemtime($remember) < 86400) {
        return $found = trim((string) @file_get_contents($remember));
    }
    $candidates = FFMPEG_PATH !== '' ? [FFMPEG_PATH] : ['ffmpeg', '/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/opt/homebrew/bin/ffmpeg', '/snap/bin/ffmpeg'];
    foreach ($candidates as $c) {
        if ($c !== 'ffmpeg' && !is_executable($c)) continue;
        if (run_quietly([$c, '-version'], 5) === 0) { $found = $c; break; }
    }
    @file_put_contents($remember, $found, LOCK_EX);
    return $found;
}

/** Runs a program (the arguments as a list, no shell), with a time limit; its exit code, or null if it couldn't be run
 *  or was stopped for taking too long. Whatever it prints is thrown away. */
function run_quietly($args, $seconds) {
    $proc = @proc_open($args, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (!is_resource($proc)) return null;
    $deadline = microtime(true) + $seconds;
    while (true) {
        $st = proc_get_status($proc);
        if (!$st['running']) { $code = $st['exitcode']; proc_close($proc); return $code; }
        if (microtime(true) > $deadline) { proc_terminate($proc, 9); proc_close($proc); return null; }
        usleep(20000);
    }
}

/** Takes the one ffmpeg place (only one runs at a time), waiting up to $seconds for whoever has it to finish, so a request
 *  that arrives during another's work is served in its turn rather than refused. Null if it never came free. */
function ffmpeg_take_turn($seconds) {
    $lock = @fopen(sys_get_temp_dir() . '/gallery-ffmpeg-' . substr(hash('sha256', __DIR__), 0, 10) . '.lock', 'c');
    if (!$lock) return null;
    $deadline = microtime(true) + $seconds;
    while (!flock($lock, LOCK_EX | LOCK_NB)) {
        if (microtime(true) > $deadline) { fclose($lock); return null; }
        usleep(100000);
    }
    return $lock;
}

/** The path of the frame taken from this video (a JPEG up to 1600 pixels), making it first if need be; null if it can't
 *  be had now (no ffmpeg, a video it can't read, someone else's frame is being taken, a cache that can't be written). */
function video_poster_path($srcPath, $cacheDir, $filename) {
    if (!ENABLE_VIDEO_THUMBNAILS || !ENABLE_THUMBNAIL_CACHE) return null;
    $poster = image_cache_path($cacheDir, $filename, 'poster', $srcPath);
    if ($poster === null) return null;
    if (is_file($poster) && filesize($poster) > 0) return $poster;
    $failed = $poster . '.failed';
    if (is_file($failed) && time() - (int) @filemtime($failed) < 21600) return null;   // it didn't work lately; not again for a few hours
    $bin = ffmpeg_binary();
    if ($bin === '') return null;
    $lock = ffmpeg_take_turn(20);   // one at a time, in turn
    if (!$lock) return null;
    if (is_file($poster) && filesize($poster) > 0) { flock($lock, LOCK_UN); fclose($lock); return $poster; }   // made by whoever had the turn before
    try {
        $duration = video_duration_seconds($srcPath);
        $tmp = $poster . '.tmp' . bin2hex(random_bytes(3));
        foreach ([$duration !== null ? max(0, min(1.0, $duration / 3)) : 0, 0] as $at) {
            $code = run_quietly([$bin, '-v', 'error', '-nostdin', '-y', '-ss', sprintf('%.2f', $at), '-i', $srcPath, '-map_metadata', '-1', '-frames:v', '1',
                '-vf', "scale='min(1600,iw)':'min(1600,ih)':force_original_aspect_ratio=decrease", '-q:v', '3', '-f', 'image2', $tmp], 25);
            if ($code === 0 && is_file($tmp) && filesize($tmp) > 0) { @chmod($tmp, 0644); if (@rename($tmp, $poster)) return $poster; }
            @unlink($tmp);
        }
        @touch($failed);
        return null;
    } finally {
        flock($lock, LOCK_UN); fclose($lock);
    }
}

/** True when a picture couldn't be taken from this video lately (ffmpeg is there but couldn't read it). */
function video_poster_failed($file) {
    $srcPath = resolve_real_path($GLOBALS['dir'], $file);
    $poster = image_cache_path(containing_folder_real_path($GLOBALS['dir'], $file) . '/.gallery-cache', $file, 'poster', $srcPath);
    return $poster !== null && !is_file($poster) && is_file($poster . '.failed');
}

/** The short clip for a video (a small silent H.264 .mp4), making it first if need be; null if it can't be had now. Same
 *  care as video_poster_path(): fixed arguments, no shell, a time limit, one ffmpeg at a time, and not retried for hours
 *  after a failure. */
function video_preview_path($srcPath, $cacheDir, $filename) {
    if (!ENABLE_VIDEO_PREVIEWS || !ENABLE_THUMBNAIL_CACHE) return null;
    $base = image_cache_path($cacheDir, $filename, 'preview2', $srcPath);
    if ($base === null) return null;
    $clip = substr($base, 0, -4) . '.mp4';
    if (is_file($clip) && filesize($clip) > 0) return $clip;
    $failed = $clip . '.failed';
    if (is_file($failed) && time() - (int) @filemtime($failed) < 21600) return null;
    $bin = ffmpeg_binary();
    if ($bin === '') return null;
 
    $lock = ffmpeg_take_turn(15);
    if (!$lock) return null;
    if (is_file($clip) && filesize($clip) > 0) { flock($lock, LOCK_UN); fclose($lock); return $clip; }
    try {
        $duration = video_duration_seconds($srcPath);
        $start = $duration !== null && $duration > 3.5 ? min($duration / 3, $duration - 3) : 0;
        $tmp = $clip . '.tmp' . bin2hex(random_bytes(3));
        $code = run_quietly([$bin, '-v', 'error', '-nostdin', '-y', '-ss', sprintf('%.2f', $start), '-t', '3', '-i', $srcPath, '-an', '-map_metadata', '-1',
            '-vf', 'scale=w=min(640\\,iw):h=-2,fps=15', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '27', '-pix_fmt', 'yuv420p', '-movflags', '+faststart', '-f', 'mp4', $tmp], 45);
        if ($code === 0 && is_file($tmp) && filesize($tmp) > 0) { @chmod($tmp, 0644); if (@rename($tmp, $clip)) return $clip; }
        @unlink($tmp);
        @touch($failed);
        return null;
    } finally {
        flock($lock, LOCK_UN); fclose($lock);
    }
}

/** Where this video's clip is (or would be) kept, or null if the cache can't be used. */
function video_preview_file($file) {
    $base = image_cache_path(containing_folder_real_path($GLOBALS['dir'], $file) . '/.gallery-cache', $file, 'preview2', resolve_real_path($GLOBALS['dir'], $file));
    return $base === null ? null : substr($base, 0, -4) . '.mp4';
}

/** The grid of frames a video's scrub sprite holds: ten columns and one row for every five seconds (1 to 6 rows), so a frame
 *  about every half second for a video of half a minute or more, spread over its whole length. [columns, rows]. */
function video_strip_layout($duration) {
    return [10, (int) max(1, min(6, ceil($duration / 5)))];
}

/** The sprite of frames for a video (one JPEG, small square frames in a grid, spread over the whole video), which a finger
 *  dragged across a video's tile scrubs through. Made once with ffmpeg, like the clip; null if it can't be had. */
function video_strip_path($srcPath, $cacheDir, $filename) {
    if (!ENABLE_VIDEO_PREVIEWS || !ENABLE_THUMBNAIL_CACHE) return null;
    $strip = image_cache_path($cacheDir, $filename, 'strip2', $srcPath);
    if ($strip === null) return null;
    if (is_file($strip) && filesize($strip) > 0) return $strip;
    $failed = $strip . '.failed';
    if (is_file($failed) && time() - (int) @filemtime($failed) < 21600) return null;
    $duration = video_duration_seconds($srcPath);
    if ($duration === null || $duration < 1) return null;
    $bin = ffmpeg_binary();
    if ($bin === '') return null;
    $lock = ffmpeg_take_turn(30);
    if (!$lock) return null;
    if (is_file($strip) && filesize($strip) > 0) { flock($lock, LOCK_UN); fclose($lock); return $strip; }
    try {
        $tmp = $strip . '.tmp' . bin2hex(random_bytes(3));
        [$cols, $rows] = video_strip_layout($duration);
        $args = [$bin, '-v', 'error', '-nostdin', '-y'];
        if ($duration > 20) array_push($args, '-skip_frame', 'nokey');   // a long video: only its key frames are read, which is much quicker
        array_push($args, '-i', $srcPath, '-an', '-map_metadata', '-1', '-vf',
            sprintf('fps=%.5F,crop=min(iw\\,ih):min(iw\\,ih),scale=320:320,tile=%dx%d', $cols * $rows / $duration * 1.05, $cols, $rows),
            '-frames:v', '1', '-q:v', '4', '-f', 'image2', $tmp);
        $code = run_quietly($args, 90);
        if ($code === 0 && is_file($tmp) && filesize($tmp) > 0) { @chmod($tmp, 0644); if (@rename($tmp, $strip)) return $strip; }
        @unlink($tmp);
        @touch($failed);
        return null;
    } finally {
        flock($lock, LOCK_UN); fclose($lock);
    }
}

/** ?strip=<video>: that sprite, for anyone who may see the video (same gates as the video itself); &warm only makes it. */
function serve_video_strip($dir, $filename, $validFiles, $itemsData = []) {
    if (!is_string($filename) || !in_array($filename, $validFiles, true) || !is_video_file($filename)
        || (file_is_hidden($dir, $filename, $itemsData) && !is_revealed())) {
        http_response_code(404);
        return;
    }
    $strip = video_strip_path(resolve_real_path($dir, $filename), containing_folder_real_path($dir, $filename) . '/.gallery-cache', $filename);
    if (isset($_GET['warm'])) {
        http_response_code($strip === null ? 404 : 204);
        header('Cache-Control: no-store');
        return;
    }
    if ($strip === null) {
        http_response_code(404);
        return;
    }
    header('Content-Type: image/jpeg');
    header('Cache-Control: ' . file_cache_control($dir, $filename, $itemsData));
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . filesize($strip));
    readfile($strip);
}

/** True when a hover over a video tile can play a made clip. */
function video_previews_expected() {
    return ENABLE_VIDEO_PREVIEWS && ENABLE_THUMBNAIL_CACHE && ffmpeg_binary() !== '';
}

/** ?preview=<video>: the short clip, for anyone who may see the video (the same gates as the video itself), with Range
 *  support (Safari plays nothing without it). Nothing is sent that wasn't made by ffmpeg from the video: no metadata. */
function serve_video_preview($dir, $filename, $validFiles, $itemsData = []) {
    if (!is_string($filename) || !in_array($filename, $validFiles, true) || !is_video_file($filename)
        || (file_is_hidden($dir, $filename, $itemsData) && !is_revealed())) {
        http_response_code(404);
        return;
    }
    $srcPath = resolve_real_path($dir, $filename);
    $clip = video_preview_path($srcPath, containing_folder_real_path($dir, $filename) . '/.gallery-cache', $filename);
    if (isset($_GET['warm'])) {
        // Only make it (the page asks quietly for the clips of the tiles in view, one at a time): no body to download.
        http_response_code($clip === null ? 404 : 204);
        header('Cache-Control: no-store');
        return;
    }
    $size = $clip === null ? false : @filesize($clip);
    if ($size === false || $size < 1) {
        http_response_code(404);
        return;
    }
    $start = 0; $end = $size - 1;
    if (preg_match('/^bytes=(\d*)-(\d*)$/', trim($_SERVER['HTTP_RANGE'] ?? ''), $m) && ($m[1] !== '' || $m[2] !== '')) {
        if ($m[1] === '') { $start = max(0, $size - (int) $m[2]); }
        else { $start = (int) $m[1]; $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1); }
        if ($start >= $size || $start > $end) { http_response_code(416); header('Content-Range: bytes */' . $size); return; }
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }
    header('Content-Type: video/mp4');
    header('Accept-Ranges: bytes');
    header('Cache-Control: ' . file_cache_control($dir, $filename, $itemsData));
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . ($end - $start + 1));
    $fh = @fopen($clip, 'rb');
    if (!$fh) return;
    fseek($fh, $start);
    for ($left = $end - $start + 1; $left > 0 && !feof($fh);) {
        $chunk = fread($fh, min(65536, $left));
        if ($chunk === false || $chunk === '') break;
        echo $chunk;
        $left -= strlen($chunk);
    }
    fclose($fh);
}

/** True when this server can make a real picture of a video, so the page can leave the browser's own first frame out. */
function video_pictures_expected() {
    return ENABLE_VIDEO_THUMBNAILS && ENABLE_THUMBNAIL_CACHE && ffmpeg_binary() !== '';
}

/**
 * Renders a generic "this is a video" placeholder graphic — a dark tile
 * with a plain play triangle — wherever a video's "thumbnail" or share-
 * preview image is requested. No frame extraction: that would mean
 * adding a video-processing dependency (ffmpeg or similar) this project
 * has never carried and isn't worth adding just for this. Same role for
 * every video regardless of filename, so nothing to cache — generating
 * one is cheap enough to just do on every request.
 */
function render_video_placeholder_image($maxDim) {
    $w = max(1, (int) $maxDim);
    $h = max(1, (int) round($w * 0.75));
    $img = imagecreatetruecolor($w, $h);
    imagefilledrectangle($img, 0, 0, $w, $h, imagecolorallocate($img, 28, 28, 30));
    $triangleColor = imagecolorallocate($img, 200, 200, 205);
    $size = min($w, $h) * 0.22;
    $cx = $w / 2;
    $cy = $h / 2;
    $points = [
        $cx - $size * 0.55, $cy - $size,
        $cx - $size * 0.55, $cy + $size,
        $cx + $size * 0.85, $cy,
    ];
    imagefilledpolygon($img, $points, $triangleColor);
    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=31536000, immutable');
    imagejpeg($img, null, 82);
    imagedestroy($img);
}

/**
 * The ?map page's share-preview image: a dark tile with a plain map
 * pin. Not a rendered snapshot of the actual map — that needs a
 * third-party static-maps API, the same dependency objection that ruled
 * out geocoding — so, same honesty standard as the video placeholder, a
 * generic icon that says "this link is a map" and nothing more. 1200x630
 * is the usual large-link-preview size. Identical for every gallery, so
 * nothing to cache or key; cheap enough to draw per request.
 */
function render_map_icon_image() {
    $w = 1200;
    $h = 630;
    $img = imagecreatetruecolor($w, $h);
    $bg = imagecolorallocate($img, 28, 28, 30);
    imagefilledrectangle($img, 0, 0, $w, $h, $bg);
    $pin = imagecolorallocate($img, 224, 138, 60);
    $r = (int) round($h * 0.19);
    $cx = (int) ($w / 2);
    $cy = (int) round($h * 0.42);
    imagefilledellipse($img, $cx, $cy, $r * 2, $r * 2, $pin);
    // Tangent-ish taper from the circle's lower flanks down to the point.
    $tipY = (int) round($h * 0.82);
    $dx = (int) round($r * 0.86);
    $dy = (int) round($r * 0.5);
    $points = [$cx - $dx, $cy + $dy, $cx + $dx, $cy + $dy, $cx, $tipY];
    imagefilledpolygon($img, $points, $pin);
    imagefilledellipse($img, $cx, $cy, (int) round($r * 0.8), (int) round($r * 0.8), $bg);
    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=31536000, immutable');
    imagejpeg($img, null, 85);
    imagedestroy($img);
}

/** The home-screen icon: a dark square with a small mountain-and-sun picture, as a PNG of $px pixels. */
function render_app_icon_image($px) {
    // The same line drawing as the gallery icon in the icon strip (a rounded frame, a sun, a hill line; ui_icon_paths()'s 'gallery'),
    // drawn here in PHP so no image file is needed. Drawn four times larger and shrunk, which smooths the edges.
    $k = 4; $big = $px * $k;
    $img = imagecreatetruecolor($big, $big);
    $bg = imagecolorallocate($img, 15, 15, 16);
    $ink = imagecolorallocate($img, 234, 234, 234);
    imagefilledrectangle($img, 0, 0, $big, $big, $bg);
    $u = $big * 0.58 / 18;                       // one unit of the 24-unit drawing; the frame (18 wide) fills 58% of the icon
    $X = function ($x) use ($big, $u) { return $big / 2 + ($x - 12) * $u; };
    $Y = function ($y) use ($big, $u) { return $big / 2 + ($y - 12) * $u; };
    $w = 1.8 * $u;                               // the icon's stroke width
    $rrect = function ($x1, $y1, $x2, $y2, $r, $col) use ($img) {
        imagefilledrectangle($img, (int) round($x1 + $r), (int) round($y1), (int) round($x2 - $r), (int) round($y2), $col);
        imagefilledrectangle($img, (int) round($x1), (int) round($y1 + $r), (int) round($x2), (int) round($y2 - $r), $col);
        foreach ([[$x1 + $r, $y1 + $r], [$x2 - $r, $y1 + $r], [$x1 + $r, $y2 - $r], [$x2 - $r, $y2 - $r]] as [$cx, $cy]) {
            imagefilledellipse($img, (int) round($cx), (int) round($cy), (int) round($r * 2), (int) round($r * 2), $col);
        }
    };
    // the frame: x 3..21, y 4..20, corner radius 2, a stroke centred on that outline
    $rrect($X(3) - $w / 2, $Y(4) - $w / 2, $X(21) + $w / 2, $Y(20) + $w / 2, 2 * $u + $w / 2, $ink);
    $rrect($X(3) + $w / 2, $Y(4) + $w / 2, $X(21) - $w / 2, $Y(20) - $w / 2, max(1, 2 * $u - $w / 2), $bg);
    // the sun: a circle of radius 1.7 at (9,10)
    imagefilledellipse($img, (int) round($X(9)), (int) round($Y(10)), (int) round(($u * 1.7 + $w / 2) * 2), (int) round(($u * 1.7 + $w / 2) * 2), $ink);
    imagefilledellipse($img, (int) round($X(9)), (int) round($Y(10)), (int) round(($u * 1.7 - $w / 2) * 2), (int) round(($u * 1.7 - $w / 2) * 2), $bg);
    // the hill line: (7,20) up to (16,11) down to (21,16), round ends and corner
    foreach ([[[7, 20], [16, 11]], [[16, 11], [21, 16]]] as [[$ax, $ay], [$bx, $by]]) {   // each segment as a filled strip of the stroke's width
        $dx = $X($bx) - $X($ax); $dy = $Y($by) - $Y($ay); $len = sqrt($dx * $dx + $dy * $dy);
        $nx = -$dy / $len * $w / 2; $ny = $dx / $len * $w / 2;
        imagefilledpolygon($img, [(int) round($X($ax) + $nx), (int) round($Y($ay) + $ny), (int) round($X($bx) + $nx), (int) round($Y($by) + $ny), (int) round($X($bx) - $nx), (int) round($Y($by) - $ny), (int) round($X($ax) - $nx), (int) round($Y($ay) - $ny)], $ink);
    }
    foreach ([[7, 20], [16, 11], [21, 16]] as [$x, $y]) imagefilledellipse($img, (int) round($X($x)), (int) round($Y($y)), (int) round($w), (int) round($w), $ink);
    $out = imagecreatetruecolor($px, $px);
    imagecopyresampled($out, $img, 0, 0, 0, 0, $px, $px, $big, $big);
    imagedestroy($img);
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=31536000, immutable');   // the address carries a version (v=), so a new drawing is a new address
    imagepng($out);
    imagedestroy($out);
}

/**
 * Reads a clip's duration (in seconds) straight out of its MP4/MOV
 * container's mvhd box — no transcoding, no external dependency, just
 * walking a handful of well-documented box headers. iPhone video
 * commonly places the moov atom at the *end* of the file (mdat written
 * first, moov finalized after recording stops), so this walks top-level
 * boxes by their declared size rather than assuming moov is near the
 * start — each step is a seek + an 8-or-16-byte read, so a large
 * preceding mdat costs nothing extra to skip over. Returns null on
 * anything that doesn't parse as a well-formed enough container — a
 * missing duration badge is a fine degradation, not worth failing the
 * whole page over.
 */
function video_duration_seconds($path) {
    $fh = @fopen($path, 'rb');
    if ($fh === false) return null;
    $fileSize = @filesize($path);
    if ($fileSize === false || $fileSize < 8) {
        fclose($fh);
        return null;
    }

    $maxBoxes = 10000; // sanity guard against a corrupt/adversarial file forcing an infinite loop
    $findBox = function ($startOffset, $rangeEnd, $wantType) use ($fh, &$maxBoxes) {
        $pos = $startOffset;
        $count = 0;
        while ($pos < $rangeEnd && $count++ < $maxBoxes) {
            if (fseek($fh, $pos) !== 0) return null;
            $header = fread($fh, 8);
            if ($header === false || strlen($header) < 8) return null;
            $size = unpack('N', substr($header, 0, 4))[1];
            $type = substr($header, 4, 4);
            $headerLen = 8;
            if ($size === 1) {
                $ext = fread($fh, 8);
                if ($ext === false || strlen($ext) < 8) return null;
                $size = unpack('J', $ext)[1];
                $headerLen = 16;
            }
            if ($size !== 0 && $size < $headerLen) return null; // corrupt
            if ($type === $wantType) {
                return ['offset' => $pos + $headerLen, 'size' => $size === 0 ? ($rangeEnd - $pos - $headerLen) : $size - $headerLen];
            }
            if ($size === 0) return null; // box extends to EOF and it's not the one we want
            $pos += $size;
        }
        return null;
    };

    $moov = $findBox(0, $fileSize, 'moov');
    if ($moov === null) {
        fclose($fh);
        return null;
    }
    $mvhd = $findBox($moov['offset'], $moov['offset'] + $moov['size'], 'mvhd');
    if ($mvhd === null) {
        fclose($fh);
        return null;
    }

    fseek($fh, $mvhd['offset']);
    $body = fread($fh, min($mvhd['size'], 32));
    fclose($fh);
    if ($body === false || strlen($body) < 1) return null;

    $version = ord($body[0]);
    if ($version === 1) {
        if (strlen($body) < 32) return null;
        $timescale = unpack('N', substr($body, 20, 4))[1];
        $duration = unpack('J', substr($body, 24, 8))[1];
    } else {
        if (strlen($body) < 20) return null;
        $timescale = unpack('N', substr($body, 12, 4))[1];
        $duration = unpack('N', substr($body, 16, 4))[1];
    }
    if ($timescale <= 0) return null;
    return $duration / $timescale;
}

/** Formats a duration in seconds as a plain "M:SS" badge (no leading
 *  hours unit — these are short trip clips, not feature-length video). */
function format_duration($seconds) {
    $seconds = (int) round($seconds);
    if ($seconds < 0) $seconds = 0;
    $m = intdiv($seconds, 60);
    $s = $seconds % 60;
    return $m . ':' . str_pad((string) $s, 2, '0', STR_PAD_LEFT);
}

function image_cache_path($cacheDir, $filename, $variant, $srcPath) {
    if (!ENABLE_THUMBNAIL_CACHE) return null;
    if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0775, true)) return null;
    if (!is_writable($cacheDir)) return null;
    $mtime = @filemtime($srcPath);
    $hash = md5($filename . '|' . $variant . '|' . $mtime);
    return $cacheDir . '/' . $hash . '.jpg';
}

/**
 * Deletes cached thumbnails that no longer correspond to any current
 * photo. A cache entry is keyed by filename+variant+mtime (see
 * image_cache_path), so a renamed/deleted/edited photo just orphans its
 * old cache file rather than overwriting it — this sweeps those away,
 * so a folder whose photos get swapped out over time doesn't
 * accumulate stale thumbnails forever. Only runs on normal gallery page
 * loads (never on ?img/?track), so it doesn't add overhead to the
 * requests that actually serve images.
 *
 * $files are relative paths, and each one's cache lives colocated in its
 * *own* containing folder (see containing_folder_real_path()) rather
 * than one centralized cache at the root — so this groups by containing
 * folder first and GCs each one independently. A flat gallery has
 * exactly one such group (the root itself), so this is a no-op change
 * in shape for that case, not just in effect.
 */
function gc_image_cache($dir, $files, $imagickAvailable) {
    if (!ENABLE_THUMBNAIL_CACHE) return;
    $byFolder = [];
    foreach ($files as $file) {
        $byFolder[containing_folder_real_path($dir, $file)][] = $file;
    }
    foreach ($byFolder as $folderPath => $folderFiles) {
        $cacheDir = $folderPath . '/.gallery-cache';
        if (!is_dir($cacheDir)) continue;
        $valid = [];
        foreach ($folderFiles as $file) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $isHeic = in_array($ext, ['heic', 'heif'], true) && $imagickAvailable;
            $variants = $isHeic ? ['thumb', 'tile', 'tilehd', 'share', 'full', 'cover', 'coverlg'] : ['thumb', 'tile', 'tilehd', 'share', 'cover', 'coverlg'];
            if (is_video_file($file)) { $variants[] = 'poster'; $variants[] = 'strip2'; }   // the frame taken from a video, and its sprite of frames
            $mtime = @filemtime(resolve_real_path($dir, $file));
            $valid['taken3.json'] = true;   // the capture dates found for sorting (see media_taken_times)
            if (is_video_file($file)) $valid[md5($file . '|preview2|' . $mtime) . '.mp4'] = true;   // and its short clip (see video_preview_path)
            foreach ($variants as $variant) {
                $valid[md5($file . '|' . $variant . '|' . $mtime) . '.jpg'] = true;
            }
        }
        foreach (scandir($cacheDir) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            // A video's "didn't work lately" note and a picture still being written belong to a current video.
            $base = preg_replace('/\.(failed|tmp[0-9a-f]*)$/', '', $entry);
            if ($base !== $entry && isset($valid[$base]) && ($base === $entry || time() - (int) @filemtime($cacheDir . '/' . $entry) < 21600)) continue;
            if (!isset($valid[$entry])) {
                @unlink($cacheDir . '/' . $entry);
            }
        }
    }
}

/** Resizes (and, for HEIC, converts) an image to JPEG using Imagick if
 *  available, otherwise GD. Returns true on success. The long side ends up at most $maxDim (a picture is never enlarged).
 *  The JPEG is progressive: it paints coarse-to-fine while it loads. */
function generate_resized_jpeg($srcPath, $destPath, $maxDim, $quality = 82) {
    if (class_exists('Imagick')) {
        try {
            $img = new Imagick();
            $img->readImage($srcPath);
            if (method_exists($img, 'autoOrient')) {
                $img->autoOrient();
            }
            $img->setImageFormat('jpeg');
            $img->setImageCompressionQuality($quality);
            $w = $img->getImageWidth();
            $h = $img->getImageHeight();
            if (max($w, $h) > $maxDim) {
                $img->thumbnailImage($maxDim, $maxDim, true);
                if (max($w, $h) > $maxDim * 2 && method_exists($img, 'unsharpMaskImage')) $img->unsharpMaskImage(0, 0.5, 0.5, 0.02);   // a picture shrunk a lot comes out a touch soft
            }
            if (defined('Imagick::INTERLACE_PLANE')) $img->setInterlaceScheme(Imagick::INTERLACE_PLANE);
            $img->stripImage();
            $ok = $img->writeImage($destPath);
            $img->clear();
            $img->destroy();
            if ($ok) return true;
        } catch (Exception $e) {
            // Fall through to GD — won't help with HEIC, but keeps
            // standard formats working if Imagick chokes on a given file.
        }
    }
    if (extension_loaded('gd')) {
        $data = @file_get_contents($srcPath);
        if ($data === false) return false;
        $src = @imagecreatefromstring($data);
        if (!$src) return false;

        $ext = strtolower(pathinfo($srcPath, PATHINFO_EXTENSION));
        if (function_exists('exif_read_data') && in_array($ext, ['jpg', 'jpeg'], true)) {
            $exif = @exif_read_data($srcPath);
            if (!empty($exif['Orientation'])) {
                if ($exif['Orientation'] === 3) $src = imagerotate($src, 180, 0);
                if ($exif['Orientation'] === 6) $src = imagerotate($src, -90, 0);
                if ($exif['Orientation'] === 8) $src = imagerotate($src, 90, 0);
            }
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1, $maxDim / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imageinterlace($dst, true);
        $ok = imagejpeg($dst, $destPath, $quality);
        imagedestroy($src);
        imagedestroy($dst);
        return $ok;
    }
    return false;
}

/**
 * A square cut of a photo for a folder's cover card, taken from the original so it stays sharp at any card size. The
 * square is the largest one that fits; its position keeps the middle across a landscape photo, and the upper-middle
 * (38% of the way down the spare height) of a portrait one, where faces and horizons usually are, so most of the scene
 * survives the crop. $dim is the side of the finished square.
 */
function generate_cover_jpeg($srcPath, $destPath, $dim) {
    if (class_exists('Imagick')) {
        try {
            $img = new Imagick();
            $img->readImage($srcPath);
            if (method_exists($img, 'autoOrient')) $img->autoOrient();
            $img->setImageFormat('jpeg');
            $img->setImageCompressionQuality(84);
            $w = $img->getImageWidth();
            $h = $img->getImageHeight();
            $side = min($w, $h);
            $img->cropImage($side, $side, (int) round(($w - $side) / 2), (int) round(($h - $side) * 0.38));
            $img->setImagePage(0, 0, 0, 0);
            if ($side > $dim) $img->thumbnailImage($dim, $dim);
            $img->stripImage();
            $ok = $img->writeImage($destPath);
            $img->clear();
            $img->destroy();
            if ($ok) return true;
        } catch (Exception $e) {
            // Fall through to GD.
        }
    }
    if (extension_loaded('gd')) {
        $data = @file_get_contents($srcPath);
        if ($data === false) return false;
        $src = @imagecreatefromstring($data);
        if (!$src) return false;
        $ext = strtolower(pathinfo($srcPath, PATHINFO_EXTENSION));
        if (function_exists('exif_read_data') && in_array($ext, ['jpg', 'jpeg'], true)) {
            $exif = @exif_read_data($srcPath);
            if (!empty($exif['Orientation'])) {
                if ($exif['Orientation'] === 3) $src = imagerotate($src, 180, 0);
                if ($exif['Orientation'] === 6) $src = imagerotate($src, -90, 0);
                if ($exif['Orientation'] === 8) $src = imagerotate($src, 90, 0);
            }
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $side = min($w, $h);
        $out = min($dim, $side);
        $dst = imagecreatetruecolor($out, $out);
        imagecopyresampled($dst, $src, 0, 0, (int) round(($w - $side) / 2), (int) round(($h - $side) * 0.38), $out, $out, $side, $side);
        $ok = imagejpeg($dst, $destPath, 84);
        imagedestroy($src);
        imagedestroy($dst);
        return $ok;
    }
    return false;
}

/** The longest side, in pixels, a size of picture is cut to. */
function image_variant_dim($variant) {
    if (isset(TILE_SIZES[$variant])) return TILE_SIZES[$variant][0];
    return ['thumb' => 480, 'share' => 1200, 'full' => 2400, 'cover' => 640, 'coverlg' => 1200][$variant] ?? 480;
}

/** Makes the cached picture of one size ($variant) of a photo, whatever way that size is cut. True when it was written. */
function make_image_variant($pictureSrc, $cachePath, $variant) {
    // A folder's cover card is a square cut from the original photo (not from its small thumbnail), so it stays sharp.
    if ($variant === 'cover' || $variant === 'coverlg') return generate_cover_jpeg($pictureSrc, $cachePath, image_variant_dim($variant));
    if (isset(TILE_SIZES[$variant])) return generate_tile_jpegs($pictureSrc, [$variant => $cachePath]);
    return generate_resized_jpeg($pictureSrc, $cachePath, image_variant_dim($variant));
}

/** The square cut from the middle of a photo that a tile shows (the tile crops the same way, so nothing it would show is
 *  lost), made at each size asked for from one reading of the photo: $targets is [variant => where to write it].
 *  Never enlarged: a small photo gives a smaller square. Progressive JPEGs, touched up a little after shrinking. True
 *  when every one was written. */
function generate_tile_jpegs($srcPath, $targets) {
    $done = 0;
    if (class_exists('Imagick')) {
        try {
            $img = new Imagick();
            $img->readImage($srcPath);
            if (method_exists($img, 'autoOrient')) $img->autoOrient();
            $img->setImageFormat('jpeg');
            $w = $img->getImageWidth();
            $h = $img->getImageHeight();
            $side = min($w, $h);
            $img->cropImage($side, $side, (int) round(($w - $side) / 2), (int) round(($h - $side) / 2));
            $img->setImagePage(0, 0, 0, 0);
            $img->stripImage();
            foreach ($targets as $variant => $dest) {
                [$dim, $quality] = TILE_SIZES[$variant];
                $one = clone $img;
                $one->setImageCompressionQuality($quality);
                if ($side > $dim) {
                    $one->thumbnailImage($dim, $dim);
                    if ($side > $dim * 2 && method_exists($one, 'unsharpMaskImage')) $one->unsharpMaskImage(0, 0.5, 0.5, 0.02);
                }
                if (defined('Imagick::INTERLACE_PLANE')) $one->setInterlaceScheme(Imagick::INTERLACE_PLANE);
                if ($one->writeImage($dest)) $done++;
                $one->clear(); $one->destroy();
            }
            $img->clear(); $img->destroy();
            if ($done === count($targets)) return true;
        } catch (Exception $e) {
            // Fall through to GD for whatever is left.
        }
        $targets = array_filter($targets, function ($dest) { return !is_file($dest) || filesize($dest) === 0; });
        $done = 0;
        if (!$targets) return true;
    }
    if (extension_loaded('gd')) {
        $data = @file_get_contents($srcPath);
        if ($data === false) return false;
        $src = @imagecreatefromstring($data);
        if (!$src) return false;
        $ext = strtolower(pathinfo($srcPath, PATHINFO_EXTENSION));
        if (function_exists('exif_read_data') && in_array($ext, ['jpg', 'jpeg'], true)) {
            $exif = @exif_read_data($srcPath);
            if (!empty($exif['Orientation'])) {
                if ($exif['Orientation'] === 3) $src = imagerotate($src, 180, 0);
                if ($exif['Orientation'] === 6) $src = imagerotate($src, -90, 0);
                if ($exif['Orientation'] === 8) $src = imagerotate($src, 90, 0);
            }
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $side = min($w, $h);
        foreach ($targets as $variant => $dest) {
            [$dim, $quality] = TILE_SIZES[$variant];
            $out = min($dim, $side);
            $dst = imagecreatetruecolor($out, $out);
            imagecopyresampled($dst, $src, 0, 0, (int) round(($w - $side) / 2), (int) round(($h - $side) / 2), $out, $out, $side, $side);
            imageinterlace($dst, true);
            if (imagejpeg($dst, $dest, $quality)) $done++;
            imagedestroy($dst);
        }
        imagedestroy($src);
        return $done === count($targets);
    }
    return false;
}

/** How many photos have every tile size ready in the cache, and how many photos there are (videos are left to the
 *  moment they are first shown: cutting a frame out of one is heavy). */
function thumbnail_readiness($dir, $files) {
    $ready = 0; $total = 0;
    foreach ($files as $file) {
        if (is_video_file($file)) continue;
        $total++;
        $src = resolve_real_path($dir, $file);
        $cacheDir = containing_folder_real_path($dir, $file) . '/.gallery-cache';
        $all = true;
        foreach (array_keys(TILE_SIZES) as $variant) {
            $mtime = @filemtime($src);
            $path = $cacheDir . '/' . md5($file . '|' . $variant . '|' . $mtime) . '.jpg';
            if (!is_file($path)) { $all = false; break; }
        }
        if ($all) $ready++;
    }
    return [$ready, $total];
}

/** Makes the tile pictures of the photos from number $from onward, taking every $step-th one (several requests can share the
 *  work, each with its own start and the same step), for about $budget seconds, then says where to carry on. A photo that
 *  can't be made, such as a damaged file, is counted and passed over rather than tried forever. 'worked' is how many photos
 *  needed making (made or not) in this call. */
function warm_thumbnail_batch($dir, $files, $from, $budget, $step = 1) {
    $photos = array_values(array_filter($files, function ($f) { return !is_video_file($f); }));
    $total = count($photos);
    $start = microtime(true);
    $made = 0; $failed = 0; $worked = 0; $i = max(0, (int) $from); $step = max(1, (int) $step);
    for (; $i < $total; $i += $step) {
        if ($made + $failed > 0 && microtime(true) - $start > $budget) break;
        $file = $photos[$i];
        $src = resolve_real_path($dir, $file);
        $cacheDir = containing_folder_real_path($dir, $file) . '/.gallery-cache';
        $missing = [];
        foreach (array_keys(TILE_SIZES) as $variant) {
            $cachePath = image_cache_path($cacheDir, $file, $variant, $src);
            if ($cachePath === null) { $failed++; continue; }
            if (is_file($cachePath) && filemtime($cachePath) >= @filemtime($src)) continue;
            $missing[$variant] = $cachePath;
        }
        if ($missing) {   // one reading of the photo makes every size that is missing
            $worked++;
            if (generate_tile_jpegs($src, $missing)) $made += count($missing); else $failed += count($missing);
        }
    }
    return ['next' => $i, 'total' => $total, 'made' => $made, 'failed' => $failed, 'worked' => $worked, 'done' => $i >= $total];
}

/** Serves ?img=<filename-or-relative-path>&size=thumb|share|full,
 *  resizing/caching/converting as needed. $filename is validated by exact
 *  match against $validFiles — the real list this script's own scan
 *  already trusts — never basename()'d first, since that would strip a
 *  legitimate subfolder prefix like "Paris/" right back off. The exact-
 *  match allowlist is what actually provides the safety here: a
 *  traversal attempt never matches anything the scan itself produced,
 *  with or without that extra step, and nothing resembling "../" can
 *  survive that match regardless of how many segments the path has. */
function serve_image($dir, $filename, $variant, $validFiles, $itemsData = []) {
    // ?size[]=x arrives as an array; anything that isn't a plain string
    // is just the default size.
    if (!is_string($variant)) $variant = 'thumb';
    if (!in_array($filename, $validFiles, true)) {
        http_response_code(404);
        return;
    }
    // A checkbox that only skips a thumbnail in the grid isn't real
    // privacy if the file itself is still fetchable directly — this
    // refuses it to anyone without a valid reveal, the same allowlist-
    // plus-identity-check discipline as the line above.
    if (file_is_hidden($dir, $filename, $itemsData) && !is_revealed()) {
        http_response_code(404);
        return;
    }
    $cacheControl = file_cache_control($dir, $filename, $itemsData);
    $maxDim = image_variant_dim($variant);

    // No frame extraction (see ENABLE_VIDEO's doc comment) — every size
    // of a video's "thumbnail," grid tile or Open Graph share preview
    // alike, gets the same generic placeholder graphic instead.
    $srcPath = resolve_real_path($dir, $filename);
    $cacheDir = containing_folder_real_path($dir, $filename) . '/.gallery-cache';
    $pictureSrc = $srcPath;   // where the pixels come from: the file itself, or for a video the frame taken from it
    if (is_video_file($filename)) {
        // A real frame from the video when ffmpeg can take one; everything below then treats that picture as the source.
        $poster = video_poster_path($srcPath, $cacheDir, $filename);
        if ($poster === null) {
            render_video_placeholder_image(min($maxDim, 640));
            return;
        }
        if ($variant === 'full') {
            header('Content-Type: image/jpeg');
            header('Cache-Control: ' . $cacheControl);
            readfile($poster);
            return;
        }
        $pictureSrc = $poster;
    }
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $isHeic = in_array($ext, ['heic', 'heif'], true);

    // Non-HEIC files at full size are served untouched — no need to
    // resize or re-encode the original the lightbox already opens fine.
    // (Except for a visitor who isn't signed in and a photo whose location is hidden: the file is the original, with its
    // GPS, so that visitor is sent it with the location blanked out, or the re-encoded copy below if that can't be done.)
    if (!$isHeic && $variant === 'full') {
        if (send_original_for_visitor($srcPath, $filename, $itemsData, $cacheControl)) return;
    }

    $cachePath = image_cache_path($cacheDir, $filename, $variant, $srcPath);
    if ($cachePath && file_exists($cachePath) && filemtime($cachePath) >= @filemtime($srcPath)) {
        header('Content-Type: image/jpeg');
        header('Cache-Control: ' . $cacheControl);
        readfile($cachePath);
        return;
    }

    $ok = $cachePath ? make_image_variant($pictureSrc, $cachePath, $variant) : false;
    if ($ok) {
        header('Content-Type: image/jpeg');
        header('Cache-Control: ' . $cacheControl);
        readfile($cachePath);
        return;
    }
    if ($pictureSrc !== $srcPath) {
        render_video_placeholder_image(min($maxDim, 640));   // the frame couldn't be cut down: the placeholder, never the video file as a picture
        return;
    }

    // Couldn't generate (no Imagick/GD, cache folder unwritable, or a
    // HEIC file with no capable backend). Non-HEIC formats can still just
    // serve the original; HEIC genuinely cannot be shown without conversion.
    if ($isHeic) {
        http_response_code(415);
        return;
    }
    // Ordinary photos keep the headers they have always had on this path
    // (none); a hidden one must still never be stored by a shared cache.
    if (!send_original_for_visitor($srcPath, $filename, $itemsData, $cacheControl === 'private, no-store' ? $cacheControl : null)) {
        http_response_code(404);
    }
}

/**
 * A short tag that changes whenever a photo or video is replaced — a hash
 * of its modification time and size, so replacing a file under the same
 * name (even with one the same size, or an old timestamp preserved by a
 * copy tool) gives it a new tag in all but freak cases. It rides along on
 * the image/video address as &v=..., because those responses are cached
 * by browsers for a year ("immutable") and the address alone never
 * changed: without it, a replaced photo kept showing the old picture to
 * anyone who had seen it. Hashed rather than the raw timestamp, so a
 * visitor can't read when each file was uploaded or last touched. The
 * server ignores it. One stat per file per request, remembered.
 */
function file_version($file) {
    static $seen = [];
    if (isset($seen[$file])) return $seen[$file];
    $stat = @stat(resolve_real_path($GLOBALS['dir'], $file));
    return $seen[$file] = $stat ? substr(hash('sha256', $stat['mtime'] . '|' . $stat['size']), 0, 10) : '';
}

function img_url($file, $size) {
    $v = file_version($file);
    return '?img=' . rawurlencode($file) . '&size=' . $size . ($v !== '' ? '&v=' . $v : '');
}

/** The extra attributes that let the browser choose a tile's picture by how big the tile really is and how dense the screen
 *  is: the 560 pixel one for an ordinary screen, the 1080 one for a phone. (The tile is one column wide on a phone in
 *  portrait, otherwise 180 to 250 points.) Where thumbnails are off there is only the one picture. */
function tile_srcset_attrs($file) {
    if (!ENABLE_THUMBNAIL_CACHE) return '';
    return ' srcset="' . htmlspecialchars(img_url($file, 'tile') . ' ' . TILE_SIZES['tile'][0] . 'w, ' . img_url($file, 'tilehd') . ' ' . TILE_SIZES['tilehd'][0] . 'w', ENT_QUOTES, 'UTF-8')
         . '" sizes="(max-width: 417px) calc(100vw - 48px), 250px"';
}

/** A location-pin badge's link target — OpenStreetMap needs no API key
 *  and no dependency this project doesn't already avoid adding, unlike
 *  a full in-app map (a later, dedicated phase). */
function osm_location_url($lat, $lng) {
    return 'https://www.openstreetmap.org/?mlat=' . rawurlencode($lat) . '&mlon=' . rawurlencode($lng)
         . '#map=16/' . rawurlencode($lat) . '/' . rawurlencode($lng);
}


/**
 * Serves ?video=<filename-or-relative-path> with HTTP Range support —
 * the one genuinely new piece of serving logic video needs. The file is
 * never transcoded or re-encoded, but video playback does need seeking,
 * and a browser seeks by issuing a Range request; without honoring it,
 * seeking either breaks outright or forces re-downloading the whole file
 * from the start. $filename is validated the same exact-allowlist way as
 * serve_image() above — and since ENABLE_VIDEO=false simply never adds
 * .mov/.mp4 to $validFiles in the first place, a request here fails
 * closed on its own with no extra check needed for the feature being off.
 * The hidden-item check below runs before any Range handling, not after
 * — a second code path added later just for partial requests is exactly
 * the kind of place a gate like this gets forgotten.
 */
function serve_video($dir, $filename, $validFiles, $itemsData = []) {
    if (!in_array($filename, $validFiles, true)) {
        http_response_code(404);
        return;
    }
    if (file_is_hidden($dir, $filename, $itemsData) && !is_revealed()) {
        http_response_code(404);
        return;
    }
    $cacheControl = file_cache_control($dir, $filename, $itemsData);
    $srcPath = resolve_real_path($dir, $filename);
    $size = @filesize($srcPath);
    if ($size === false) {
        http_response_code(404);
        return;
    }
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    // A visitor who isn't signed in, and a video whose location is hidden: the location is blanked out of what is sent.
    $plan = visitor_scrub_plan($srcPath, $filename, $itemsData);
    if ($plan === null) {
        http_response_code(404);
        return;
    }
    if ($plan !== []) $cacheControl = 'private, max-age=86400';

    // Once nginx is configured for this (see serve_original_file()),
    // hand the whole request to its own static serving instead — nginx
    // already handles Range requests correctly on its own, a free
    // upgrade over the plain-PHP handling below, which stays as the
    // baseline that works on any host regardless.
    if (NGINX_ACCEL_REDIRECT_PREFIX !== '' && $plan === [] && file_is_under_web_root($srcPath, $filename)) {
        header('Cache-Control: ' . $cacheControl);
        serve_original_file($srcPath, $filename, guess_mime($ext));
        return;
    }

    header('Content-Type: ' . guess_mime($ext));
    header('Cache-Control: ' . $cacheControl);
    if ($plan !== []) header('Vary: Cookie');
    header('Accept-Ranges: bytes');

    $start = 0;
    $end = $size - 1;
    $range = $_SERVER['HTTP_RANGE'] ?? '';
    $isRangeRequest = false;
    if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m) && ($m[1] !== '' || $m[2] !== '')) {
        $isRangeRequest = true;
        if ($m[1] === '') {
            // Suffix range ("bytes=-500"): the last N bytes.
            $suffixLen = (int) $m[2];
            $start = max(0, $size - $suffixLen);
            $end = $size - 1;
        } else {
            $start = (int) $m[1];
            $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);
        }
        if ($start >= $size || $start > $end) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            return;
        }
    }
    // A Range header present but not matching "bytes=..." at all (some
    // other unit, or genuinely malformed) is simply ignored — falls back
    // to serving the whole file with a normal 200, rather than erroring.

    $fh = @fopen($srcPath, 'rb');
    if ($fh === false) {
        http_response_code(404);
        return;
    }
    if ($isRangeRequest) {
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }
    $length = $end - $start + 1;
    header('Content-Length: ' . $length);

    fseek($fh, $start);
    $remaining = $length;
    $at = $start;
    $chunkSize = 8192;
    while ($remaining > 0 && !feof($fh)) {
        $chunk = fread($fh, min($chunkSize, $remaining));
        if ($chunk === false || $chunk === '') break;
        echo $plan === [] ? $chunk : apply_patches_to_chunk($chunk, $at, $plan);
        $remaining -= strlen($chunk);
        $at += strlen($chunk);
    }
    fclose($fh);
}


/* -----------------------------------------------------------------------
 * GROUPING: discover qualifying .md files, extract {group:}/{photos:}
 * directives, and build the final $groups (auto-detected + explicit)
 * before anything below (stats, ?track, rendering) needs it.
 * ----------------------------------------------------------------------- */

// Collected as issues are found below; surfaced as an HTML comment near
// the top of the page (never anything a normal visitor would notice) so
// a typo'd filename or a missing {gallery} marker doesn't just fail
// silently with no way to ever find out why.
$galleryNotices = [];
foreach (($GLOBALS['__gallery_config_problems'] ?? []) as $configProblem) $galleryNotices[] = $configProblem;
if (!empty($subfolderMediaCount)) {
    $galleryNotices[] = ($subfolderMediaTruncated ? 'At least ' : '') . $subfolderMediaCount
        . ' photo' . ($subfolderMediaCount === 1 ? '' : 's') . '/video' . ($subfolderMediaCount === 1 ? ' was' : 's were')
        . ' found in subfolders but ' . ($subfolderMediaCount === 1 ? 'isn\'t' : 'aren\'t')
        . ' shown — set AUTO_GROUP_BY_FOLDER to true to include them (see the README\'s "Folders" section).';
}

if (!ENABLE_VIDEO) {
    [$ignoredVideoCount, $ignoredVideoTruncated] = count_ignored_videos($dir);
    if ($ignoredVideoCount > 0) {
        $galleryNotices[] = ($ignoredVideoTruncated ? 'At least ' : '') . $ignoredVideoCount
            . ' video file' . ($ignoredVideoCount === 1 ? ' (.mov/.mp4) was' : 's (.mov/.mp4) were')
            . ' found but ' . ($ignoredVideoCount === 1 ? 'isn\'t' : 'aren\'t')
            . ' shown, because ENABLE_VIDEO is off — set it to true to include them (see the README\'s "Video" section).';
    }
}

if (AUTO_GROUP_BY_FOLDER && EXTRA_ROOTS && ($looseInRoots = count_loose_media_in_roots()) > 0) {
    $galleryNotices[] = $looseInRoots . ' photo' . ($looseInRoots === 1 ? ' or video lies' : 's or videos lie')
        . ' directly in a place added from elsewhere on the server. Only the folders inside such a place are shown at the top of the gallery, so move '
        . ($looseInRoots === 1 ? 'it' : 'them') . ' into a folder to include ' . ($looseInRoots === 1 ? 'it' : 'them') . '.';
}

// A place from elsewhere on the server (EXTRA_ROOTS / EXTRA_FOLDERS) that has gone missing or can't be read just shows nothing;
// say so (without naming the path: a fresh install shows these notices to whoever opens the page).
$unreadableExtra = 0;
foreach (array_merge(array_values((array) EXTRA_ROOTS), array_values((array) EXTRA_FOLDERS)) as $extraPath) {
    if (is_string($extraPath) && (!is_dir($extraPath) || !is_readable($extraPath))) $unreadableExtra++;
}
if (AUTO_GROUP_BY_FOLDER && $unreadableExtra > 0) {
    $galleryNotices[] = ($unreadableExtra === 1 ? 'A folder from elsewhere on the server is missing or can\'t be read' : $unreadableExtra . ' folders from elsewhere on the server are missing or can\'t be read')
        . ', so nothing from ' . ($unreadableExtra === 1 ? 'it is' : 'them is') . ' shown. The Health check in Site settings names ' . ($unreadableExtra === 1 ? 'it' : 'them') . '.';
}

// Only a .md file whose first non-blank line is exactly "{gallery}" is
// treated as content for this script — anything else in the folder (a
// personal notes.md, a leftover README, etc.) is left completely alone.
$mdCandidates = scan_md_candidates($dir);

$mdScan = scan_gallery_md_files($dir);
$mdFiles = $mdScan['files'];
$rejectedMdCount = $mdScan['rejectedCount'];
if ($rejectedMdCount > 0) {
    // Deliberately doesn't name the file(s): unlike every other notice
    // here, this one would otherwise reveal the exact filename of a .md
    // the folder owner specifically did *not* choose to expose via the
    // gallery — and since a .md is served as a plain static file with
    // no access control tied to the {gallery} marker (that's a PHP-
    // application concept, not a permissions mechanism), naming it would
    // hand a visitor the literal URL to go read its contents directly.
    $galleryNotices[] = $rejectedMdCount === 1
        ? 'A .md file was found but ignored because its first line isn\'t the required {gallery} marker. If it\'s meant to be your gallery notes, add {gallery} as its very first line.'
        : $rejectedMdCount . ' .md files were found but ignored because none of them start with the required {gallery} marker. If one is meant to be your gallery notes, add {gallery} as its very first line.';
}

$folderName = basename($dir);

// If the first non-empty line remaining (after the marker) is a
// "# Heading", use it as the page title instead of the folder name —
// and strip it out of that file's content so it isn't shown twice.
$pageTitle = $folderName;
$mdContents = [];
foreach ($mdFiles as $idx => $mdFile) {
    $content = $mdScan['contents'][$mdFile];
    if ($idx === 0) {
        $lines = explode("\n", str_replace("\r\n", "\n", $content));
        foreach ($lines as $li => $l) {
            if (trim($l) === '') continue;
            if (preg_match('/^#\s+(.+)$/', trim($l), $m)) {
                $pageTitle = unprotect_inline_code(trim($m[1]));
                unset($lines[$li]);
                $content = implode("\n", $lines);
            }
            break; // only ever check the very first non-empty line
        }
    }
    $mdContents[$mdFile] = $content;
}
// The admin's gallery title (the root's own settings) wins over a "# Heading"
// in a notes file, which wins over the folder name.
$rootMeta = root_display_meta($dir);
if ($rootMeta['label'] !== '') $pageTitle = $rootMeta['label'];

// Extract {photos:}/{group:} directives across all qualifying .md files,
// in file order, tracking each directive's approximate position so it
// can be interleaved with auto-detected mention-order positions below.
$rootDirectives = collect_explicit_directives($mdContents, array_values(array_filter($files, function ($f) { return strpos($f, '/') === false; })), '', $files, $galleryNotices);
$explicitClaims = $rootDirectives['claims'];           // filename => true (removed from auto-detection)
$explicitGroups = $rootDirectives['groups'];           // slug => ['name' => .., 'files' => [...], 'position' => N]
$positionalClusters = $rootDirectives['clusters'];     // list of ['files' => [...], 'position' => N]

// Folder-groups: each top-level subfolder (an immediate child of the
// gallery root, or an EXTRA_FOLDERS virtual mount) with any files
// anywhere in its own subtree becomes one group, keyed by its own
// relative path. Every file inside a qualifying subfolder is claimed
// here, the same way a {photos:}-claimed file already is — removed from
// the filename-auto-grouping pool entirely below, so it's never
// re-clustered by its filename suffix a second time inside a folder it's
// already a member of. A folder's default label is its raw, unmodified
// name — {group: Name} in the root's own .md still renames it, the same
// mechanism that already renames any auto-detected group.
$folderGroups = [];       // relative folder path => [recursive member files]
$folderClaims = [];       // filename => true (removed from auto-detection, same as $explicitClaims)
if (AUTO_GROUP_BY_FOLDER && $folderTree !== null) {
    foreach ($folderTree['subdirs'] as $topName => $topNode) {
        $members = flatten_folder_tree($topNode, $topName);
        if (empty($members)) continue;
        $members = media_sort_apply($members, $dir);   // each folder's photos in its own order (see media_sort_apply)
        $folderGroups[$topName] = $members;
        foreach ($members as $m) {
            $folderClaims[$m] = true;
        }
    }
}
$folderGroupKeys = array_fill_keys(array_keys($folderGroups), true);

// Build the final groups: filename-based auto-detection (unless
// AUTO_GROUP_BY_FILENAME is off, or a file was explicitly claimed above)
// plus explicit named groups. A file claimed via a bare {photos:} (no
// {group:}) stays its own standalone single, keyed by its own filename
// so it can never merge with an unrelated auto-detected group.
$groups = [];
foreach ($files as $file) {
    if (isset($explicitClaims[$file]) || isset($folderClaims[$file])) continue;
    $groups[group_key($file)][] = $file;
}
foreach ($folderGroups as $folderKey => $members) {
    $groups[$folderKey] = $members;
}

// A key shared by 2+ files is either a real, intended multi-angle group
// (members' pre-extension names differ and only converge after suffix-
// stripping, e.g. "dp104_01"/"dp104_02" -> "dp104") or a coincidence:
// files whose pre-extension names were already identical before any
// stripping happened at all (e.g. "topology.jpg" and "topology.png",
// both already "topology" — nothing was stripped for either). Only the
// first case is an intentional grouping signal; split the second case
// back into individually-keyed standalone singles instead of merging
// files that just happen to share a name.
foreach ($groups as $key => $groupFiles) {
    if (count($groupFiles) < 2) continue;
    $rawBases = array_map(function ($f) { return pathinfo($f, PATHINFO_FILENAME); }, $groupFiles);
    $counts = array_count_values($rawBases);
    $keep = [];
    foreach ($groupFiles as $i => $f) {
        if ($counts[$rawBases[$i]] > 1) {
            $groups[$f] = [$f];
        } else {
            $keep[] = $f;
        }
    }
    if (count($keep) !== count($groupFiles)) {
        if (empty($keep)) {
            unset($groups[$key]);
        } else {
            $groups[$key] = $keep;
        }
    }
}

foreach ($explicitClaims as $file => $claimed) {
    $inNamedGroup = false;
    foreach ($explicitGroups as $g) {
        if (in_array($file, $g['files'], true)) { $inNamedGroup = true; break; }
    }
    if (!$inNamedGroup) {
        // Keyed by the file's own full filename (with extension) — see
        // group_key()'s docblock for why the extension-stripped base
        // isn't safe to use as a per-file key.
        $groups[$file][] = $file;
    }
}
foreach ($explicitGroups as $slug => $g) {
    $groups[$slug] = $g['files'];
}

// Explicit group slugs, so the renderer knows to box these even with
// just a single photo — an auto-detected single never gets a box, but
// an explicitly named one always does; that's the point of naming it. A
// folder-group gets the same treatment: a photo deliberately organized
// into its own named folder deserves a box (or, in 'overview' mode, a
// cover card) regardless of how many other photos share it. Computed
// here (not just once, further down) because zone membership during
// ordering depends on it too.
$explicitGroupKeys = array_fill_keys(
    array_merge(array_keys($explicitGroups), array_keys($folderGroups)),
    true
);

/**
 * Determines display order within a single zone (either "boxes" or
 * "singles+clusters" — see compute_zone_order() below, which calls this
 * once per zone). Two tiers, in order:
 *
 *  1. Anything with prose in its paragraph — a classic text-mention (an
 *     auto-detected group's name found in the .md), or an explicit
 *     {group:}/{photos:} directive whose paragraph has other text —
 *     sorted earliest-first by where that prose sits in the .md. This
 *     mirrors the narrative: a reader sees a description, then its
 *     photos, in the order they were written about.
 *  2. Anything without prose (a textless directive, or never
 *     mentioned/claimed at all) falls back to natural order
 *     ($naturalRank). A textless multi-file group/cluster is anchored
 *     on whichever file was listed *first* in its {photos:} directive —
 *     never re-sorted — with its other members following immediately
 *     after, in the exact order listed.
 *
 * $keysInZone restricts consideration to keys belonging to this zone;
 * every key in $keysInZone appears exactly once in the result.
 */
function order_within_zone($keysInZone, $mdContents, $groups, $explicitGroups, $positionalClusters, $naturalRank, $rankOverride = []) {
    $inZone = array_fill_keys($keysInZone, true);
    $handled = [];
    $positioned = []; // ['position' => N, 'keys' => [...]]
    $natural = [];    // ['rank' => N, 'keys' => [...]]

    $offset = 0;
    $mentionPos = [];
    foreach ($mdContents as $content) {
        // Search prose only — a filename sitting inside some *other*
        // paragraph's {photos: a.jpg, b.jpg} list must never count as a
        // mention of that name (masking preserves offsets exactly, so
        // this stays comparable to the raw-content-based positions
        // computed during extraction, further up).
        $maskedContent = mask_directives($content);
        foreach ($keysInZone as $key) {
            if (isset($mentionPos[$key]) || isset($explicitGroups[$key])) continue;
            if (preg_match(group_mention_pattern($key), $maskedContent, $m, PREG_OFFSET_CAPTURE)) {
                $mentionPos[$key] = $offset + $m[0][1];
            }
        }
        $offset += strlen($content) + 1;
    }
    foreach ($mentionPos as $key => $pos) {
        $positioned[] = ['position' => $pos, 'keys' => [$key]];
        $handled[$key] = true;
    }

    foreach ($explicitGroups as $slug => $g) {
        if (!isset($inZone[$slug]) || isset($handled[$slug])) continue;
        if ($g['hasProse']) {
            $positioned[] = ['position' => $g['position'], 'keys' => [$slug]];
        } else {
            $natural[] = ['rank' => $naturalRank[$g['files'][0]] ?? PHP_INT_MAX, 'keys' => [$slug]];
        }
        $handled[$slug] = true;
    }

    foreach ($positionalClusters as $cluster) {
        $keys = [];
        foreach ($cluster['files'] as $f) {
            if (isset($inZone[$f]) && !isset($handled[$f])) $keys[] = $f;
        }
        if (empty($keys)) continue;
        if ($cluster['hasProse']) {
            $positioned[] = ['position' => $cluster['position'], 'keys' => $keys];
        } else {
            $natural[] = ['rank' => $naturalRank[$cluster['files'][0]] ?? PHP_INT_MAX, 'keys' => $keys];
        }
        foreach ($keys as $k) $handled[$k] = true;
    }

    foreach ($keysInZone as $key) {
        if (isset($handled[$key])) continue;
        $natural[] = ['rank' => $rankOverride[$key] ?? ($naturalRank[$groups[$key][0]] ?? PHP_INT_MAX), 'keys' => [$key]];
        $handled[$key] = true;
    }

    usort($positioned, function ($a, $b) { return $a['position'] <=> $b['position']; });
    usort($natural, function ($a, $b) { return $a['rank'] <=> $b['rank']; });

    $order = [];
    foreach ($positioned as $block) {
        foreach ($block['keys'] as $k) $order[] = $k;
    }
    foreach ($natural as $block) {
        foreach ($block['keys'] as $k) $order[] = $k;
    }
    return $order;
}

/**
 * Splits $groups into two zones and orders each independently — see
 * order_within_zone() above. Boxes (2+ files, or an explicitly named
 * group even with just one photo) always render before every single
 * and unboxed {photos:} cluster; a box is a full-width grid item, so
 * putting all of them first guarantees the singles wall that follows
 * always starts on a fresh row, instead of a box interrupting a
 * partially-filled row of cards.
 */
function compute_zone_order($mdContents, $groups, $explicitGroups, $positionalClusters, $explicitGroupKeys, $naturalRank, $rankOverride = [], $folderGroupKeys = []) {
    $boxKeys = [];
    $singleKeys = [];
    foreach ($groups as $key => $groupFiles) {
        // A folder-group is always in the box zone, even with one photo:
        // its cover card is a folder, not a single photo's card, and an
        // admin-set position has to be able to reorder it against every
        // other folder, not only the ones that happen to hold 2+ photos.
        if (count($groupFiles) >= 2 || isset($explicitGroupKeys[$key]) || isset($folderGroupKeys[$key])) {
            $boxKeys[] = $key;
        } else {
            $singleKeys[] = $key;
        }
    }
    $boxOrder = order_within_zone($boxKeys, $mdContents, $groups, $explicitGroups, $positionalClusters, $naturalRank, $rankOverride);
    $singleOrder = order_within_zone($singleKeys, $mdContents, $groups, $explicitGroups, $positionalClusters, $naturalRank, $rankOverride);
    return array_merge($boxOrder, $singleOrder);
}

// Reorders $groups per compute_zone_order() (boxes first, singles+
// clusters after; prose-position within each, natural order otherwise),
// then rebuilds the flat photo list to match, so the lightbox's index
// numbering (shared by both the note thumbnails and the gallery cards)
// stays consistent with the new display order.
// Folder-groups order among themselves by order_folder_children(): an
// admin-set position first (see ?admin), then natural name order — or
// folder mtime when PHOTO_SORT_ORDER says so. They take over each
// other's existing natural-order *slots* rather than jumping ahead of
// everything, so how folders interleave with filename groups doesn't
// change; only the folders' order among themselves does. It replaces
// only the natural ranking, so a prose mention in the root's own .md
// still positions a folder first, same precedence as for any group.
$folderSlots = [];
foreach ($folderGroupKeys as $folderKey => $_true) {
    $folderSlots[] = $naturalRank[$groups[$folderKey][0]] ?? PHP_INT_MAX;
}
sort($folderSlots);
$folderSortRank = [];
foreach (order_folder_children($dir, '', array_keys($folderGroupKeys)) as $slot => $folderKey) {
    $folderSortRank[$folderKey] = $folderSlots[$slot];
}
$groupOrder = compute_zone_order($mdContents, $groups, $explicitGroups, $positionalClusters, $explicitGroupKeys, $naturalRank, $folderSortRank, $folderGroupKeys);
$orderedGroups = [];
foreach ($groupOrder as $key) {
    if (isset($groups[$key]) && !isset($orderedGroups[$key])) {
        $orderedGroups[$key] = $groups[$key];
    }
}
$groups = $orderedGroups;

$files = [];
foreach ($groups as $groupFiles) {
    foreach ($groupFiles as $f) {
        $files[] = $f;
    }
}

// Reverse lookup used by ?track below: which $groups key currently
// contains a given filename. Needed because an explicit named group's
// key is a slug chosen by the .md author, not something group_key()
// could ever derive from the filename itself.
$fileToGroupKey = [];
foreach ($groups as $key => $groupFiles) {
    foreach ($groupFiles as $gf) {
        $fileToGroupKey[$gf] = $key;
    }
}

// ?admin — checked here, as soon as $groups exists, and before anything
// below it (stats, ?track, the public page) does any work a management
// request has no business triggering. See run_admin_route()'s own
// docblock, further down, for the full auth/CSRF flow.
if (isset($_GET['admin'])) {
    run_admin_route($dir, $files, $groups, $folderGroupKeys, $explicitGroups, $explicitGroupKeys, $videoDurations, $galleryNotices);
    exit;
}

// A snapshot of every real file, taken before the hiding filter below
// ever touches $files — gc_image_cache()/gc_items_data() further down
// need the *complete* picture to tell "genuinely deleted" apart from
// "hidden from this particular unrevealed visitor." Using the
// already-filtered $files there would make every hidden item look
// deleted to the garbage collector, which would wipe out its own hide
// flag (and its cached thumbnails) the next time an unrevealed visitor
// just loads the page — the opposite of what hiding is supposed to do.
$allFiles = $files;
// Whether anything in the gallery is private: the lock in the icon strip only appears when there is something to unlock.
$hasPrivateItems = false;
foreach ($allFiles as $privateProbe) {
    if (file_is_hidden($dir, $privateProbe, $itemsData)) { $hasPrivateItems = true; break; }
}

// Private view, for everything from here on (the public page, ?track=,
// ?folder=): a hidden item — or an entirely-hidden folder-group — is
// removed from $groups/$files outright for an unrevealed visitor, not
// merely skipped in a display loop. That's what makes every downstream
// count, the lightbox's own file list, and every allowlist check below
// all naturally agree with what's actually rendered — a count that
// doesn't match what's visible would itself be a leak. (?img=/?video= answer even earlier, before the tree is scanned, with
// their own check; ?photo= runs its own just above — this filter runs
// too late to help them.) If
// every member of a group ends up hidden, the group itself disappears
// here too, rather than rendering an empty "0 photos" box, which would
// be its own tell that something's being withheld.
if (!is_revealed()) {
    foreach ($groups as $key => $groupFiles) {
        $visibleMembers = array_values(array_filter($groupFiles, function ($f) use ($dir, $itemsData) {
            return !file_is_hidden($dir, $f, $itemsData);
        }));
        if (empty($visibleMembers)) {
            unset($groups[$key], $folderGroupKeys[$key], $explicitGroupKeys[$key]);
        } else {
            $groups[$key] = $visibleMembers;
        }
    }
    $visibleSet = [];
    foreach ($groups as $groupFiles) {
        foreach ($groupFiles as $f) {
            $visibleSet[$f] = true;
        }
    }
    $files = array_values(array_filter($files, function ($f) use ($visibleSet) {
        return isset($visibleSet[$f]);
    }));
}

/* -----------------------------------------------------------------------
 * Small generic JSON file storage, flock-protected the same way the
 * stats JSON fallback below is — reused by two otherwise-unrelated
 * concerns (the admin tool's .gallery-items.php, and the login-attempt
 * throttle's .gallery-login-attempts.php) so the locking mechanics
 * aren't duplicated a third time. Deliberately generic over the data
 * shape, unlike stats_update_file below, since callers here have
 * genuinely different shapes rather than variations on the same one.
 * ----------------------------------------------------------------------- */

/** Reads a JSON file, merging it over $defaultData (missing file or
 *  corrupt content just yields $defaultData untouched). */
/**
 * The first line of every generated data file (.gallery-items.php,
 * .gallery-stats.php, .gallery-login-attempts.php). These hold captions,
 * GPS coordinates, hide flags — and with them the exact filenames of
 * hidden items — and a web server serves any file in the folder to
 * anyone who asks for its URL. Stored as a .php file starting with this
 * line, a direct request is handed to PHP instead, which hits the exit
 * on the first line and answers 404 with nothing else: the JSON below
 * is never sent, on any host that runs PHP, with no server rule needed.
 * (A server rule that blocks ".gallery-*" is still worth adding as a
 * second layer — see the README.) This script itself never *executes*
 * these files; it reads them as plain text and drops this line.
 */
function data_file_guard_line() {
    return "<?php http_response_code(404); exit; ?>\n";
}

/** The JSON text of a guarded data file: raw contents with the guard
 *  line removed. Tolerates a file with no guard (plain JSON). */
function data_file_strip_guard($raw) {
    $raw = (string) $raw;
    if (strncmp($raw, '<?php', 5) === 0) {
        $newline = strpos($raw, "\n");
        return $newline === false ? '' : substr($raw, $newline + 1);
    }
    return $raw;
}

/** JSON for a guarded data file. JSON_HEX_TAG writes "<" and ">" as
 *  \u003C/\u003E, so a caption that happens to contain "<?php" can never
 *  put a PHP open tag into the file — nothing after the guard line is
 *  ever parsed as code, even by a server with short tags on. */
function data_file_encode($data) {
    return data_file_guard_line() . json_encode($data, JSON_HEX_TAG);
}

function json_file_read($path, $defaultData) {
    if (!file_exists($path)) return $defaultData;
    $decoded = json_decode(data_file_strip_guard(@file_get_contents($path)), true);
    return is_array($decoded) ? array_merge($defaultData, $decoded) : $defaultData;
}
function json_file_update($path, $defaultData, $modifier) {
    $data = $defaultData;
    $fp = @fopen($path, 'c+');
    if (!$fp) {
        // Usually the folder isn't writable by the web server. Remember it
        // so the admin can say so instead of reporting a save that never
        // happened (see run_admin_route).
        $GLOBALS['__data_write_failed'] = true;
    }
    if ($fp) {
        if (flock($fp, LOCK_EX)) {
            $raw = stream_get_contents($fp);
            $decoded = json_decode(data_file_strip_guard($raw), true);
            if (is_array($decoded)) {
                $data = array_merge($data, $decoded);
            }
            $modifier($data);
            $encoded = data_file_encode($data);
            ftruncate($fp, 0);
            rewind($fp);
            if (fwrite($fp, $encoded) !== strlen($encoded)) {
                $GLOBALS['__data_write_failed'] = true;   // disk full, quota, ...
            }
            fflush($fp);
            flock($fp, LOCK_UN);
        } else {
            $GLOBALS['__data_write_failed'] = true;
        }
        fclose($fp);
    }
    return $data;
}

/* -----------------------------------------------------------------------
 * Admin auth: HTTP Basic Auth (checked with hash_equals — no timing
 * leak), an HTTPS requirement, a session-carried CSRF token for the
 * actual save actions, and a failed-attempt throttle shared with any
 * future second password this script grows (see ADMIN_PASSWORD's own
 * doc comment above; PRIVATE_VIEW_PASSWORD, when it exists, reuses this
 * unchanged).
 * ----------------------------------------------------------------------- */

/** True for a request this script considers already-local — the one
 *  exception to the HTTPS requirement below, so ?admin is still testable
 *  on a bare `php -S localhost:8000` during development. */
function is_local_request() {
    // Deliberately keyed off REMOTE_ADDR alone — the actual TCP peer
    // address, which a client can't forge in an ordinary request —
    // rather than also trusting the Host header. A request's Host header
    // is attacker-controlled: on a real public deployment, anyone could
    // send "Host: localhost" and bypass the HTTPS requirement entirely
    // if that were accepted here on its own.
    return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
}

/** True if this request arrived over HTTPS — checks the same three
 *  signals current_base_url() already does (a direct TLS connection, a
 *  trusted reverse-proxy header, or port 443), so a site behind a
 *  load balancer or CDN that terminates TLS upstream is still correctly
 *  recognized as HTTPS. */
function is_https_request() {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);
}

/**
 * Lightweight failed-login throttle, tracking failures per client IP in
 * a small flock-protected file at the gallery root — same mechanism the
 * view-stats JSON fallback already uses, nothing new conceptually,
 * shared across every password prompt this script has rather than one
 * per feature. 5 failures from one IP within 15 minutes forces a flat
 * 60-second lockout on that IP's *next* attempt — rejected outright,
 * without even checking the password it carries, matching "the next
 * attempt is not even evaluated." A flat delay, not a permanent
 * lockout: a legitimate visitor who fat-fingers a password a few times
 * just waits a minute, not calls the site owner.
 */
function login_throttle_path($rootDir) {
    return $rootDir . '/.gallery-login-attempts.php';
}

function login_throttle_key($realm, $ip) {
    return $realm . '|' . $ip;
}

/** True if this IP is currently clear to even have its password
 *  checked for the given realm (e.g. 'admin'). */
function login_throttle_allows($rootDir, $realm, $ip) {
    $data = json_file_read(login_throttle_path($rootDir), []);
    $entry = $data[login_throttle_key($realm, $ip)] ?? null;
    if ($entry === null) return true;
    return time() >= ($entry['lockUntil'] ?? 0);
}

/** Records one failed attempt; imposes a 60-second lockout once 5 have
 *  landed from the same IP within the last 15 minutes. */
function login_throttle_record_failure($rootDir, $realm, $ip) {
    $key = login_throttle_key($realm, $ip);
    json_file_update(login_throttle_path($rootDir), [], function (&$data) use ($key) {
        $now = time();
        $entry = $data[$key] ?? ['attempts' => [], 'lockUntil' => 0];
        $entry['attempts'] = array_values(array_filter($entry['attempts'], function ($t) use ($now) {
            return $t > $now - 900; // keep only the last 15 minutes
        }));
        $entry['attempts'][] = $now;
        if (count($entry['attempts']) >= 5) {
            $entry['lockUntil'] = $now + 60;
            $entry['attempts'] = [];
        }
        $data[$key] = $entry;
    });
}

/** Clears a realm/IP's failure history after a successful login. */
function login_throttle_record_success($rootDir, $realm, $ip) {
    $key = login_throttle_key($realm, $ip);
    json_file_update(login_throttle_path($rootDir), [], function (&$data) use ($key) {
        unset($data[$key]);
    });
}

/** Starts (or resumes) the PHP session used to carry the admin CSRF
 *  token, with a 4-hour server-side lifetime — set here, before
 *  session_start(), so no server or php.ini change is needed. The
 *  browser-side cookie itself keeps no explicit expiry (a plain session
 *  cookie), so in practice a login lasts up to 4 hours of continuous
 *  use, or until the browser closes, whichever comes first. Only ever
 *  called for ?admin requests — an ordinary visitor's page load never
 *  starts a session or sets this cookie. */
/** Headers for every admin / private-view response: it can't be put in
 *  someone else's frame (clickjacking a "Hide folder" button), must never
 *  be cached (it lists everything, hidden items included), and its forms
 *  may only post back to this site. */
function send_sensitive_page_headers() {
    header('Cache-Control: no-store');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: frame-ancestors 'none'; base-uri 'none'; form-action 'self'; object-src 'none'");
}
/** For pages whose content differs by who is asking (the public gallery,
 *  the map, ?track): tell caches so, and never let one keep a copy made
 *  for a trusted viewer — it would list hidden items to the next
 *  anonymous request for the same URL. */
function send_viewer_aware_cache_headers() {
    header('Vary: Cookie');
    if (is_revealed()) {
        header('Cache-Control: private, no-store');
    }
}
function admin_session_name() {
    return 'gallery_admin_' . substr(hash('sha256', __DIR__), 0, 10);
}

/** What a logged-in admin session carries. Derived from the password and
 *  this install's folder, so changing ADMIN_PASSWORD logs every existing
 *  session out, and a session from another gallery means nothing here. */
function admin_session_marker($stored = null) {
    return hash('sha256', 'admin|' . ($stored ?? ADMIN_PASSWORD) . '|' . __DIR__);
}

/* -----------------------------------------------------------------------
 * Passwords at rest. The settings file keeps each password as a bcrypt hash
 * (password_hash), so a copy of that file — a backup, a zip, a repository, a
 * server that sends .php files as text — does not hand over the password
 * itself, only something that has to be guessed at one try at a time. A
 * password written there in plain text still works (hand-edited files, older
 * versions); the first successful sign-in replaces it with its hash.
 * ----------------------------------------------------------------------- */

/** True if $v is a password hash made by password_hash(), not a password. */
function password_is_hashed($v) {
    return is_string($v) && $v !== '' && (password_get_info($v)['algo'] ?? null) !== null;
}

/** Does $supplied match the stored password: a hash (checked with
 *  password_verify) or, for a file not yet upgraded, the plain text. */
function password_matches($stored, $supplied) {
    if (!is_string($stored) || $stored === '' || !is_string($supplied)) return false;
    return password_is_hashed($stored) ? password_verify($supplied, $stored) : hash_equals($stored, $supplied);
}

/** What the settings file keeps for a new password. */
function password_for_storage($plain) {
    return password_hash($plain, PASSWORD_DEFAULT);
}

/** A random secret that belongs to this install, kept in its own small data
 *  file (.gallery-secret.php, answers 404 like the others). The sign-in
 *  cookies are keyed with it, so someone who only has a copy of the settings
 *  file (and so the password hashes) still can't make a valid cookie. $create
 *  makes it if it doesn't exist (at a sign-in); without it, a missing secret
 *  is just ''. If the folder can't be written the cookies fall back to being
 *  derived from the stored password alone. */
function install_secret($create = false) {
    static $secret = null;
    if ($secret !== null && ($secret !== '' || !$create)) return $secret;
    $path = __DIR__ . '/.gallery-secret.php';
    $read = function () use ($path) {
        $raw = is_file($path) ? (string) @file_get_contents($path) : '';
        return preg_match('/^[0-9a-f]{64}$/m', $raw, $m) ? $m[0] : '';
    };
    $secret = $read();
    if ($secret !== '' || !$create || !is_writable(__DIR__)) return $secret;
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, data_file_guard_line() . bin2hex(random_bytes(32)) . "\n", LOCK_EX) === false) { @unlink($tmp); return $secret; }
    @chmod($tmp, 0600);
    // link() refuses to replace a file that appeared meanwhile, so two first sign-ins can't end up with different secrets.
    if (!@link($tmp, $path) && !is_file($path)) @rename($tmp, $path);
    @unlink($tmp);
    return $secret = $read();
}

/** After a successful sign-in: any password the settings file still holds in
 *  plain text is replaced there by its hash (both at once, so one write).
 *  Returns the values now in force, name => stored value, for the passwords
 *  it changed ([] if none, or if the file can't be rewritten: then nothing
 *  changes and the plain text keeps working). */
function upgrade_plaintext_passwords() {
    $state = gallery_config_load();
    if ($state['status'] !== 'ok') return [];
    $names = [];
    foreach (['ADMIN_PASSWORD', 'PRIVATE_VIEW_PASSWORD'] as $n) {
        if (isset($state['cfg'][$n]) && is_string($state['cfg'][$n]) && $state['cfg'][$n] !== '' && !password_is_hashed($state['cfg'][$n])) $names[] = $n;
    }
    if (!$names || !is_writable(__DIR__)) return [];
    $lock = @fopen(__FILE__, 'r');
    if ($lock) flock($lock, LOCK_EX);
    try {
        $path = gallery_config_path();
        $raw = @file_get_contents($path);
        if ($raw === false) return [];
        [$status, $cfg] = gallery_config_parse($raw);
        if ($status !== 'ok') return [];
        $changed = [];
        foreach ($names as $n) {
            // Only what the file still holds, unchanged since this request began.
            if (($cfg[$n] ?? null) === $state['cfg'][$n]) { $cfg[$n] = password_for_storage($state['cfg'][$n]); $changed[$n] = $cfg[$n]; }
        }
        if (!$changed) return [];
        $content = gallery_config_render($cfg);
        if ($content === null) return [];
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $content, LOCK_EX) === false) { @unlink($tmp); return []; }
        @chmod($tmp, 0600);
        [$roundStatus, $roundTrip] = gallery_config_parse((string) @file_get_contents($tmp));
        ksort($roundTrip); ksort($cfg);
        if ($roundStatus !== 'ok' || $roundTrip !== $cfg || !@rename($tmp, $path)) { @unlink($tmp); return []; }
        return $changed;
    } finally {
        if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    }
}

function admin_session_authed() {
    return isset($_SESSION['admin_auth']) && is_string($_SESSION['admin_auth'])
        && hash_equals(admin_session_marker(), $_SESSION['admin_auth']);
}

function admin_start_session() {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.gc_maxlifetime', 14400);
    // Reject a session id the server never issued (blocks fixation), and give
    // the admin session its own name: a gallery living inside another PHP
    // site would otherwise share — and clobber — that site's PHPSESSID.
    ini_set('session.use_strict_mode', '1');
    session_name(admin_session_name());
    // Secure matches is_https_request() rather than being hardcoded true
    // — the one exception this feature already makes for localhost
    // testing over plain HTTP would otherwise never get the cookie back
    // from the browser at all, since a Secure cookie is withheld from
    // any non-HTTPS request.
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => is_https_request(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function admin_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function admin_csrf_valid($submittedToken) {
    return isset($_SESSION['csrf_token']) && is_string($submittedToken)
        && hash_equals($_SESSION['csrf_token'], $submittedToken);
}

/** Checks the request's HTTP Basic Auth credentials against
 *  ADMIN_PASSWORD. Any username is accepted — there's only one account. */
function admin_credentials_valid() {
    $pass = $_SERVER['PHP_AUTH_PW'] ?? null;
    if (!is_string($pass)) return false;
    return password_matches(ADMIN_PASSWORD, $pass);
}

/** A request carried an HTTP Basic password that is wrong: a script's typo, or
 *  — more often — a browser re-sending a password it saved when an older
 *  version of this gallery asked for one. The answer is 401 (so a script can
 *  tell) with the ordinary login form, and never a WWW-Authenticate header:
 *  that header is what makes a browser open its own username/password dialog,
 *  and a stale saved password must not bring that dialog back. */
function admin_reject_saved_credentials() {
    http_response_code(401);
    render_login_page('Enter your access code', '', 'Your browser sent a saved login that no longer matches. Type your current access code.');
}

/** The password form for ?admin and ?reveal: one field, no username, no
 *  browser pop-up. Never indexed or cached or framed. $error is shown above
 *  the field after a wrong password. */
/** The query string of the page a visitor was on when they asked to sign in or out ("?folder=Paris&photo=...&map"), so they
 *  can be brought back to it. Only these three are carried; anything odd is dropped. */
function login_return_query() {
    $ok = function ($k) { return isset($_REQUEST[$k]) && is_string($_REQUEST[$k]) && $_REQUEST[$k] !== '' && strlen($_REQUEST[$k]) < 1024 && strpbrk($_REQUEST[$k], "\0\r\n") === false; };
    $parts = [];
    if ($ok('folder')) $parts[] = 'folder=' . rawurlencode($_REQUEST['folder']);
    if ($ok('photo')) $parts[] = 'photo=' . rawurlencode($_REQUEST['photo']);
    if (isset($_REQUEST['map'])) $parts[] = 'map';
    return $parts ? '?' . implode('&', $parts) : '';
}

/** The password typed into the sign-in form, or null if this request isn't that form being sent. The box is called
 *  access_code (not a name a password manager recognises); login_password is still read, for scripts and older pages. */
function login_form_password() {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return null;
    foreach (['access_code', 'login_password'] as $name) {
        if (isset($_POST[$name]) && is_string($_POST[$name])) return $name === 'access_code' ? str_replace(["\r", "\n"], '', $_POST[$name]) : $_POST[$name];
    }
    return null;
}

function render_login_page($heading, $blurb, $error = '') {
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Robots-Tag: noindex, nofollow');
    // (This page is deliberately bare: one large line drawing and one box. A heading, a "Continue" button or the words
    // "password" and "sign in" are what make a phone's Autofill decide it is a login form and offer a saved one.)
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Access</title>
<style>
  :root { color-scheme: dark; }
  html, body { height:100%; }
  body { background:#0f0f10; color:#eaeaea; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; line-height:1.5; margin:0; }
  .wrap { box-sizing:border-box; min-height:100%; max-width:360px; margin:0 auto; padding:12vh 24px 0; display:flex; flex-direction:column; justify-content:flex-start; }
  .mark { display:block; width:104px; height:104px; margin:0 auto 14px; color:#8a8a93; transition:color .2s; }
  .mark:hover, .mark:focus-visible { color:#eaeaea; outline:none; }
  .mark svg { width:100%; height:100%; fill:none; stroke:currentColor; stroke-width:1.1; stroke-linecap:round; stroke-linejoin:round; }
  p { color:#9a9aa0; font-size:0.9rem; margin:0 0 18px; text-align:center; }
  .gate { display:block; width:100%; box-sizing:border-box; padding:12px 14px; font:inherit; font-size:1.05rem; text-align:center; background:#1a1a1c; color:#eaeaea; border:1px solid #3a3a3e; border-radius:10px; -webkit-text-security:disc; text-security:disc; }
  .gate:focus { outline:2px solid #5865f2; outline-offset:1px; }
  /* Safari's own key / contacts Autofill button inside a field */
  .gate::-webkit-credentials-auto-fill-button, .gate::-webkit-contacts-auto-fill-button, .gate::-webkit-autofill-button { display:none !important; visibility:hidden !important; pointer-events:none !important; }
  .err { background:rgba(237,66,69,0.15); color:#ff8a8c; padding:8px 12px; border-radius:8px; font-size:0.9rem; margin-bottom:14px; text-align:center; }
</style>
</head>
<body>
<div class="wrap">
  <a class="mark" id="goback" href="./<?= htmlspecialchars(login_return_query(), ENT_QUOTES, 'UTF-8') ?>" aria-label="Back to the gallery"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="11" width="14" height="9.5" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/><path d="M12 14.6v2.8"/></svg></a>
  <?php if ($blurb !== ''): ?><p><?= htmlspecialchars($blurb, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
  <?php if ($error !== ''): ?><div class="err" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <form method="post" action="" autocomplete="off">
    <?php // Not a password field (a browser or manager offers to fill, and to save, whatever is typed into one): a text box
          // drawn as dots, named nothing a manager recognises, every common manager told to leave it alone, and read-only
          // until it is focused, which keeps iOS Safari's Autofill from picking it up when the page loads. Return sends it. ?>
    <input type="text" class="gate" id="gate" name="access_code" readonly onfocus="this.removeAttribute('readonly');" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" enterkeyhint="go" autofocus required
           data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other">
  </form>
</div>
<script>
  (function () {
    var box = document.getElementById('gate');
    // Where the browser can't draw text as dots, showing what is typed would be worse than a manager's pop-up: use a real password box.
    if (!(window.CSS && CSS.supports && (CSS.supports('-webkit-text-security', 'disc') || CSS.supports('text-security', 'disc')))) {
      var pw = document.createElement('input');
      pw.type = 'password'; pw.name = box.name; pw.id = box.id; pw.className = box.className; pw.required = true; pw.autocomplete = 'current-password';
      box.parentNode.replaceChild(pw, box); pw.focus();
    }
  })();
  // The lock drawing is the way back, and really goes back to where the visitor was (same scroll position, same open photo)
  // when they came from a page of this site; otherwise its link takes them to that page.
  document.getElementById('goback').addEventListener('click', function (e) {
    try {
      var ref = document.referrer ? new URL(document.referrer) : null;
      if (ref && ref.origin === location.origin && history.length > 1 && !/[?&](reveal|admin)\b/.test(ref.search)) { e.preventDefault(); history.back(); }
    } catch (x) {}
  });
</script>
</body>
</html>
<?php
}

/** A plain-English refusal page for ?admin or ?reveal over an insecure
 *  connection — HTTP Basic Auth credentials are plaintext-equivalent
 *  without TLS, so this fails closed rather than just documenting the
 *  risk. Explains itself and points toward a free certificate, rather
 *  than a bare error that teaches a visitor nothing about what to do
 *  next. */
function render_https_required_page() {
    http_response_code(403);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>HTTPS required</title>
<style>
  body { background:#0f0f10; color:#eaeaea; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; line-height:1.6; margin:0; }
  .wrap { max-width:560px; margin:0 auto; padding:48px 24px; }
  h1 { font-size:1.4rem; }
  a { color:#aab2ff; text-decoration:none; border-radius:3px; }
  p a:hover { color:#e1e4ff; background:rgba(170,178,255,0.16); box-shadow:0 0 0 3px rgba(170,178,255,0.16); border-radius:3px; }
  code { background:rgba(255,255,255,0.08); padding:1px 6px; border-radius:4px; }
</style>
</head>
<body>
<div class="wrap">
  <h1>HTTPS required</h1>
  <p>This page asks for a password, and over a plain <code>http://</code> connection that password travels in a form anyone on the network path can read. To protect it, this page refuses to run at all without HTTPS.</p>
  <p>If you're testing this on your own machine, visiting it as <code>localhost</code> or <code>127.0.0.1</code> is allowed over plain HTTP. For a real deployment, put this site behind HTTPS — <a href="https://letsencrypt.org/" target="_blank" rel="noopener noreferrer">Let's Encrypt</a> issues free certificates, and most hosting control panels can set one up in a few clicks.</p>
</div>
</body>
</html>
<?php
}

/* -----------------------------------------------------------------------
 * Private view: a hidden item (or an entirely hidden folder-group) is
 * omitted from the public page outright, for any visitor without a
 * valid "reveal" — either the ?reveal password, or a valid admin login
 * (one-directional: admin implies revealed, revealed never implies
 * admin). The reveal cookie's value is a deterministic hash of
 * PRIVATE_VIEW_PASSWORD itself rather than a random per-session token —
 * there's no server-side session store to check it against, so this
 * needs to be independently re-derivable on every request; a visitor
 * who never entered the real password has no way to produce a matching
 * value. Each item's restriction is a small named value ("public" /
 * "private"), not a bare boolean, so a second audience tier later is an
 * addition, not a rewrite of the flag itself.
 * ----------------------------------------------------------------------- */

function reveal_cookie_value($stored = null) {
    // Mixed with this install's folder: the cookie is a bearer token for
    // *this* gallery only, not a bare hash of the password that another
    // gallery sharing the same password would also accept.
    return hash_hmac('sha256', 'reveal|' . ($stored ?? PRIVATE_VIEW_PASSWORD) . '|' . __DIR__, install_secret());
}

/** Sets the private-view cookie, after a successful ?reveal login. A year-long
 *  expiry gives the same "enter it once" persistence ?admin's own
 *  session-based login already has, just via a plain cookie instead of a
 *  PHP session, since this has to survive on the *public*, unauthenticated
 *  page view, not just inside ?admin/?reveal themselves. */
function set_reveal_cookie($stored = null) {
    if (PRIVATE_VIEW_PASSWORD === '') return;
    install_secret(true);
    setcookie(REVEAL_COOKIE_NAME, reveal_cookie_value($stored), [
        'expires' => time() + 31536000,
        'path' => '/',
        'secure' => is_https_request(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** What the admin's own reveal cookie holds: derived from the admin password
 *  and this install's folder, so it can't be forged and means nothing to any
 *  other gallery. This is how "a valid admin login counts as revealed too"
 *  works — the admin always sees their own hidden items (in the admin's
 *  thumbnails and when browsing the plain gallery) without a second
 *  credential, and without needing a private-view password to exist. */
function admin_reveal_cookie_value($password = null) {
    $pw = $password ?? ADMIN_PASSWORD;
    return $pw === '' ? '' : hash_hmac('sha256', 'admin-reveal|' . $pw . '|' . __DIR__, install_secret());
}

/** Sets that cookie on every authenticated admin request. $password is passed
 *  only right after the password was just set or changed, when the constant
 *  still holds the old value. */
function set_admin_reveal_cookie($password = null) {
    install_secret(true);
    $value = admin_reveal_cookie_value($password);
    if ($value === '') return;
    setcookie(REVEAL_COOKIE_NAME, $value, [
        'expires' => time() + 31536000,
        'path' => '/',
        'secure' => is_https_request(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** True if this visitor can see hidden items: they have unlocked private view
 *  (the cookie from ?reveal), or they are the admin (the cookie from an
 *  ?admin login). With neither password set, nothing can produce a matching
 *  cookie, so a hidden item simply stays hidden — the same closed-by-default
 *  posture as every other gate in this script. */
function is_revealed() {
    $cookie = $_COOKIE[REVEAL_COOKIE_NAME] ?? '';
    if (!is_string($cookie) || $cookie === '') return false;
    if (PRIVATE_VIEW_PASSWORD !== '' && hash_equals(reveal_cookie_value(), $cookie)) return true;
    $adminValue = admin_reveal_cookie_value();
    return $adminValue !== '' && hash_equals($adminValue, $cookie);
}

/** Normalizes a stored restriction value to exactly "inherit", "public",
 *  or "private" — missing/corrupt/unrecognized all degrade to "inherit",
 *  the always-safe default of "nothing changes until you touch it." Used
 *  both for an item's own three-state override (inherit the folder's
 *  setting / always show / always hide — same shape as location-
 *  visibility's own tri-state) and for a folder's own entry, where only
 *  the "private" value is ever meaningful (a folder has nothing to
 *  "inherit" from in this one-level-deep model). */
function item_restriction($entry) {
    $r = is_array($entry) ? ($entry['restriction'] ?? 'inherit') : 'inherit';
    return in_array($r, ['public', 'private'], true) ? $r : 'inherit';
}

/** Whether $file is hidden from an unrevealed visitor. An item's own
 *  explicit "public"/"private" always wins, in either direction — a
 *  folder hidden entirely can still have one specific item deliberately
 *  shown, and a public folder can still have one sensitive item hidden;
 *  neither needs the other's permission. "Inherit" (the default) falls
 *  through to the folders $file sits in: hidden if *any* ancestor, at any
 *  depth, is flagged private (see AUTO_GROUP_BY_FOLDER). */
function file_is_hidden($dir, $file, $itemsData) {
    $own = item_restriction($itemsData[$file] ?? null);
    if ($own === 'private') return true;
    if ($own === 'public') return false;
    return folder_above_is_private($dir, $file);
}

/** Whether any folder $file sits in, at any depth, is private (what an item with no setting of its own inherits). A folder
 *  made private at any depth hides everything beneath it, the same way a private top-level folder always has. */
function folder_above_is_private($dir, $file) {
    if (AUTO_GROUP_BY_FOLDER && strpos($file, '/') !== false) {
        $path = '';
        foreach (explode('/', dirname($file)) as $segment) {
            $path = $path === '' ? $segment : $path . '/' . $segment;
            if (folder_restriction_cached($dir, $path) === 'private') {
                return true;
            }
        }
    }
    return false;
}

/** The owner's view of one photo's privacy: its own setting ("private", "public", or "" when it has none and follows its folder),
 *  whether a folder above it is private (what "" would give it), and what that makes it. */
function owner_privacy_state($dir, $file, $itemsData) {
    $own = item_restriction($itemsData[$file] ?? null);
    $inh = folder_above_is_private($dir, $file);
    return ['own' => $own === 'inherit' ? '' : $own, 'inh' => $inh, 'priv' => $own === 'private' || ($own === 'inherit' && $inh)];
}

/** What pressing a photo's lock does, in words (its tooltip). In a private folder the lock goes round three states; elsewhere it is two. */
function owner_lock_title($ps) {
    if ($ps['inh']) {
        if ($ps['own'] === 'private') return 'Kept private on its own: press to make it public';
        if ($ps['own'] === 'public') return 'Public, though its folder is private: press to follow the folder again';
        return 'Private because its folder is: press to keep it private on its own';
    }
    return $ps['own'] === 'private' ? 'Private: press to make it public' : 'Public: press to make it private';
}

/** "3 photos" (or "3 items" when videos are shown too): a count with its noun, for the private-folder counts. */
function count_noun($n) {
    return number_format((int) $n) . ' ' . (ENABLE_VIDEO ? 'item' : 'photo') . ((int) $n === 1 ? '' : 's');
}

/** What opening a private folder would do, for the owner: how many of the photos and videos under $folderPath would still be
 *  hidden once the folder itself is public (switched private on their own, or inside another private folder) and how many would
 *  become public. Counts everything beneath the folder, to any depth. */
function folder_privacy_counts($dir, $files, $folderPath) {
    $stay = 0; $open = 0;
    $prefix = $folderPath . '/';
    $folders = [];   // each folder's items, read once
    foreach ($files as $f) {
        if (strncmp($f, $prefix, strlen($prefix)) !== 0) continue;
        $where = dirname($f);
        if (!isset($folders[$where])) $folders[$where] = items_read(resolve_real_path($dir, $where))['items'] ?? [];
        $own = item_restriction($folders[$where][basename($f)] ?? null);
        if ($own === 'private') { $stay++; continue; }
        if ($own === 'public') { $open++; continue; }
        $hiddenElsewhere = false;
        if (AUTO_GROUP_BY_FOLDER) {
            $path = '';
            foreach (explode('/', $where) as $segment) {
                $path = $path === '' ? $segment : $path . '/' . $segment;
                if ($path !== $folderPath && folder_restriction_cached($dir, $path) === 'private') { $hiddenElsewhere = true; break; }
            }
        }
        if ($hiddenElsewhere) $stay++; else $open++;
    }
    return ['stay' => $stay, 'open' => $open];
}

/** Cache-Control for a photo/video response. Ordinary files are immutable
 *  and public (the URL changes when the file does not, and a CDN is welcome
 *  to hold them). A *hidden* item is only ever served to a trusted viewer,
 *  and a shared cache or CDN keys on the URL, not the cookie: letting one
 *  store it would hand it to the next anonymous request for the same URL.
 *  So hidden items are private and never stored. */
function file_cache_control($dir, $file, $itemsData) {
    return file_is_hidden($dir, $file, $itemsData)
        ? 'private, no-store'
        : 'public, max-age=31536000, immutable';
}

/** One folder's own restriction, read once per request — file_is_hidden()
 *  asks about every ancestor of every file, so without this a deep tree
 *  would re-read the same small JSON files thousands of times.
 *  items_update() resets it, so an admin save is never read back stale. */
function folder_restriction_cached($dir, $folderRel, $reset = false) {
    static $cache = [];
    if ($reset) {
        $cache = [];
        return null;
    }
    if (!isset($cache[$folderRel])) {
        $cache[$folderRel] = item_restriction(items_read(resolve_real_path($dir, $folderRel)));
    }
    return $cache[$folderRel];
}

/* -----------------------------------------------------------------------
 * Folder levels. $files (already hiding-filtered by the time a page is
 * built from it) is the allowlist for everything here: a folder path is
 * only ever "valid" because some file the script itself found sits under
 * it, never because a request said so — the same discipline as ?img=.
 * ----------------------------------------------------------------------- */

/** Splits $files under $prefix ('' for the root, else 'A/B/') into the
 *  files loose directly at that level and each immediate subfolder's
 *  recursive members. */
function split_folder_level($files, $prefix) {
    $folders = [];
    $loose = [];
    $len = strlen($prefix);
    foreach ($files as $f) {
        if ($prefix !== '' && strncmp($f, $prefix, $len) !== 0) continue;
        $rest = substr($f, $len);
        $slash = strpos($rest, '/');
        if ($slash === false) {
            $loose[] = $f;
        } else {
            $folders[substr($rest, 0, $slash)][] = $f;
        }
    }
    return ['folders' => $folders, 'loose' => $loose];
}

/** Every folder path (at any depth) that has at least one file under it,
 *  parents before their children. */
function all_folder_paths($files) {
    $set = [];
    foreach ($files as $f) {
        $d = dirname($f);
        while ($d !== '.' && $d !== '' && !isset($set[$d])) {
            $set[$d] = true;
            $d = dirname($d);
        }
    }
    $paths = array_keys($set);
    usort($paths, 'strnatcasecmp');
    return $paths;
}

/** The files under a folder path, or [] if no file does — which is also
 *  the "this folder doesn't exist (or is hidden from you)" answer. */
function folder_files_under($files, $folderPath) {
    if (!is_string($folderPath) || $folderPath === '') return [];
    $prefix = $folderPath . '/';
    $len = strlen($prefix);
    return array_values(array_filter($files, function ($f) use ($prefix, $len) {
        return strncmp($f, $prefix, $len) === 0;
    }));
}

/** A folder's own admin-set identity, read from its own
 *  .gallery-items.php, normalized so callers never re-check types. */
/** The gallery root's own title, dates, description and cover — the same
 *  record a folder keeps (in the root's .gallery-items.php), so the root is
 *  configured the same way folders are. */
function root_display_meta($dir) {
    $d = items_read($dir);
    return [
        'label' => is_string($d['displayName'] ?? null) ? $d['displayName'] : '',
        'cover' => is_string($d['cover'] ?? null) && $d['cover'] !== '' ? $d['cover'] : null,
        'description' => is_string($d['description'] ?? null) ? $d['description'] : '',
        'dates' => is_string($d['dates'] ?? null) ? $d['dates'] : '',
    ];
}

function folder_display_meta($dir, $folderRel) {
    $d = items_read(resolve_real_path($dir, $folderRel));
    return [
        'label' => is_string($d['displayName'] ?? null) ? $d['displayName'] : '',
        'cover' => is_string($d['cover'] ?? null) && $d['cover'] !== '' ? $d['cover'] : null,
        'description' => is_string($d['description'] ?? null) ? $d['description'] : '',
        'dates' => is_string($d['dates'] ?? null) ? $d['dates'] : '',
        'sortOrder' => is_int($d['sortOrder'] ?? null) ? $d['sortOrder'] : null,
    ];
}

/** Orders sibling folder names: an explicit admin-set position first
 *  (ascending), everything else after in natural order — by name, or by
 *  folder mtime when PHOTO_SORT_ORDER says so. */
function order_folder_children($dir, $prefix, $names) {
    $meta = [];
    foreach ($names as $name) {
        $meta[$name] = folder_display_meta($dir, $prefix . $name);
    }
    usort($names, function ($a, $b) use ($meta, $dir, $prefix) {
        $oa = $meta[$a]['sortOrder'];
        $ob = $meta[$b]['sortOrder'];
        if ($oa !== null && $ob !== null && $oa !== $ob) return $oa <=> $ob;
        if ($oa !== null && $ob === null) return -1;
        if ($oa === null && $ob !== null) return 1;
        if (PHOTO_SORT_ORDER === 'mtime') {
            $ma = @filemtime(resolve_real_path($dir, $prefix . $a)) ?: 0;
            $mb = @filemtime(resolve_real_path($dir, $prefix . $b)) ?: 0;
            if ($ma !== $mb) return $ma <=> $mb;
        }
        return strnatcasecmp($a, $b);
    });
    return $names;
}

/**
 * ?reveal — the only thing that invokes a real HTTP Basic Auth prompt
 * for PRIVATE_VIEW_PASSWORD. Same HTTPS requirement and failed-attempt
 * throttle as ?admin (a separate throttle realm, so failures on one
 * password never lock out the other). On success, sets the reveal
 * cookie and redirects back to the bare URL — dropping ?reveal from the
 * address bar, not just leaving it there, so "bookmark the plain gallery
 * link afterward" is literally true.
 */
function run_reveal_route($dir) {
    send_sensitive_page_headers();
    // One sign-in for the lock in the icon strip: the private-view password shows the private items, the admin password
    // signs in to the admin. Either alone is enough for the form to exist.
    if (PRIVATE_VIEW_PASSWORD === '' && ADMIN_PASSWORD === '') {
        http_response_code(404);
        return;
    }
    // Back to the very same page the visitor came from: a folder, an open photo, the open map.
    $backPage = login_return_query();
    // The lock in the icon strip, when it is open, locks the private items again: whoever is signed in (a viewer with the
    // private-view password, or the owner signed in to the admin) is signed out of this browser.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
        if (is_admin_browser()) {
            admin_start_session();
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                setcookie(session_name(), '', ['expires' => 1, 'path' => '/', 'secure' => is_https_request(), 'httponly' => true, 'samesite' => 'Strict']);
            }
            session_destroy();
        }
        setcookie(REVEAL_COOKIE_NAME, '', ['expires' => 1, 'path' => '/', 'secure' => is_https_request(), 'httponly' => true, 'samesite' => 'Lax']);
        header('Location: ./' . $backPage, true, 303);
        return;
    }
    if (!is_https_request() && !is_local_request()) {
        render_https_required_page();
        return;
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    // One plain prompt, whichever password it turns out to be.
    $heading = 'Enter your access code';
    $prompt = '';
    // The form is the normal way in; a Basic Authorization header (a script,
    // a bookmarked login) is still honored. Only a password that was
    // actually *supplied* and wrong counts against the throttle: a plain
    // visit to the form tells us nothing about guessing.
    $pass = $_SERVER['PHP_AUTH_PW'] ?? null;
    $formPass = login_form_password();
    $supplied = $formPass ?? $pass;
    // Both passwords are guessed at through this one box, so a visitor who keeps failing is held back from the admin
    // sign-in as well (per address), and the admin's own lockout holds here too. Only an attempt is refused: just
    // looking at the form never is.
    $attempting = $formPass !== null || isset($_SERVER['PHP_AUTH_USER']) || isset($_SERVER['PHP_AUTH_PW']);
    if ($attempting && (!login_throttle_allows($dir, 'reveal', $ip) || (ADMIN_PASSWORD !== '' && !login_throttle_allows($dir, 'admin', $ip)))) {
        http_response_code(429);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Too many failed attempts. Try again in a moment.';
        return;
    }
    $asAdmin = is_string($supplied) && ADMIN_PASSWORD !== '' && password_matches(ADMIN_PASSWORD, $supplied);
    $asViewer = !$asAdmin && is_string($supplied) && PRIVATE_VIEW_PASSWORD !== '' && password_matches(PRIVATE_VIEW_PASSWORD, $supplied);
    if (!$asAdmin && !$asViewer) {
        if (isset($_SERVER['PHP_AUTH_USER']) || isset($_SERVER['PHP_AUTH_PW']) || $formPass !== null) {
            login_throttle_record_failure($dir, 'reveal', $ip);
            if (ADMIN_PASSWORD !== '') login_throttle_record_failure($dir, 'admin', $ip);
        }
        if ($formPass === null && (isset($_SERVER['PHP_AUTH_USER']) || isset($_SERVER['PHP_AUTH_PW']))) {
            // A wrong saved Basic password (see admin_reject_saved_credentials): the form, never a challenge.
            http_response_code(401);
            render_login_page($heading, $prompt, 'Your browser sent a saved login that no longer matches. Type the current access code.');
            return;
        }
        render_login_page($heading, $prompt, $formPass !== null ? 'That code isn\'t right.' : '');
        return;
    }
    login_throttle_record_success($dir, 'reveal', $ip);
    // A password still in plain text in the settings file becomes a hash now (everything below uses what is stored).
    $upgraded = upgrade_plaintext_passwords();
    if ($asAdmin) {
        // The owner: a fresh admin session, exactly as the admin's own sign-in makes one, then back to the very page they
        // were on (the title leads on to the admin).
        admin_start_session();
        session_regenerate_id(true);
        $_SESSION['admin_auth'] = admin_session_marker($upgraded['ADMIN_PASSWORD'] ?? null);
        login_throttle_record_success($dir, 'admin', $ip);
        set_admin_reveal_cookie($upgraded['ADMIN_PASSWORD'] ?? null);
        header('Location: ./' . $backPage, true, 303);
        return;
    }
    set_reveal_cookie($upgraded['PRIVATE_VIEW_PASSWORD'] ?? null);
    header('Location: ./' . $backPage, true, 302);
}

/* -----------------------------------------------------------------------
 * The trip map (?map, ENABLE_MAP): one overview map per gallery root,
 * every subfolder's pins together. Leaflet + OpenStreetMap tiles — the
 * only realistic option with no API key — loaded from a CDN only when
 * this is actually on, since it's the first thing in this script to ever
 * make an outbound, third-party request. Clustering is plain haversine-
 * distance arithmetic, no mapping-library plugin for that part.
 * ----------------------------------------------------------------------- */

/** Great-circle distance between two lat/lng points, in miles. */
function haversine_miles($lat1, $lng1, $lat2, $lng2) {
    $earthRadiusMiles = 3958.8;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return $earthRadiusMiles * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/** Greedy single-pass clustering: the first not-yet-clustered point
 *  anchors a new cluster and absorbs every remaining point within
 *  MAP_CLUSTER_RADIUS_MILES of *that anchor* (not re-measured from newly
 *  absorbed members) — simple and deterministic, not necessarily the
 *  tightest possible grouping, which is fine for "nearby photos read as
 *  one pin," not a precision clustering problem. */
function cluster_map_points($points, $radiusMiles) {
    // 0 means no grouping at all: every photo is its own pin (for a dense city, where even a tenth of a mile is too much).
    if ($radiusMiles <= 0) return array_map(function ($p) { return [$p]; }, array_values($points));
    $clusters = [];
    $used = array_fill(0, count($points), false);
    for ($i = 0; $i < count($points); $i++) {
        if ($used[$i]) continue;
        $cluster = [$points[$i]];
        $used[$i] = true;
        for ($j = $i + 1; $j < count($points); $j++) {
            if ($used[$j]) continue;
            if (haversine_miles($points[$i]['lat'], $points[$i]['lng'], $points[$j]['lat'], $points[$j]['lng']) <= $radiusMiles) {
                $cluster[] = $points[$j];
                $used[$j] = true;
            }
        }
        $clusters[] = $cluster;
    }
    return $clusters;
}

/** A fixed, high-contrast palette cycling by index — plenty of
 *  realistic top-level groups (trip folders) will fit before repeating,
 *  and a repeat is a cosmetic coincidence, not a correctness problem
 *  (the legend still names each group correctly regardless). */
function map_group_color($index) {
    static $palette = ['#e6194b', '#3cb44b', '#4363d8', '#f58231', '#911eb4', '#42d4f4', '#f032e6', '#bfef45', '#fabed4', '#469990'];
    return $palette[$index % count($palette)];
}

/** Where a pin's popup link actually goes — the identical destination
 *  logic already used for mention-links and copy-link buttons: a
 *  top-level folder-group in 'overview' mode gets its own ?folder= page;
 *  anything else (a filename-based group, an explicit group, or a plain
 *  standalone single) gets its #group-<slug> anchor on the current page,
 *  not a separate linking system. */
function map_point_link($file, $groupKey, $folderGroupKeys) {
    if ($groupKey !== null && isset($folderGroupKeys[$groupKey]) && FOLDER_DISPLAY_MODE === 'overview') {
        return '?folder=' . rawurlencode($groupKey);
    }
    $slug = slugify($groupKey !== null ? $groupKey : pathinfo(basename($file), PATHINFO_FILENAME));
    return '#group-' . $slug;
}

/** Whether a folder — or the gallery root, with $folderPath null — shows a map
 *  of its own photos on its page (and has its own ?map&folder= page). Stored
 *  with the folder like its location switch. The root keeps the behaviour it
 *  always had, so it is on unless switched off; a folder starts off, since
 *  turning one on makes its page load a third-party map. */
function folder_map_enabled($dir, $folderPath) {
    $data = items_read($folderPath === null ? $dir : resolve_real_path($dir, $folderPath));
    $v = $data['mapEnabled'] ?? null;
    return $folderPath === null ? $v !== false : $v === true;
}

/** How close (in miles) photos must be to share one pin on this page's map:
 *  the folder's own setting if it has one, else the site-wide default. */
function folder_map_radius($dir, $folderPath) {
    $data = items_read($folderPath === null ? $dir : resolve_real_path($dir, $folderPath));
    $r = $data['mapRadius'] ?? null;
    return (is_int($r) || is_float($r)) && $r >= 0 && $r <= 10000 ? $r : MAP_CLUSTER_RADIUS_MILES;
}

/** The points for one folder's map: just those inside it (and its subfolders),
 *  coloured by which subfolder they are in, with the photos directly in the
 *  folder under the folder's own name. At the root this is every point, as
 *  collected. Nothing here widens what is shown: the points arrive already
 *  filtered by hiding and by the location switches. */
function map_points_for_folder($points, $dir, $folderPath) {
    if ($folderPath === null) return $points;
    $prefix = $folderPath . '/';
    $metaLabel = folder_display_meta($dir, $folderPath)['label'];
    $folderLabel = $metaLabel !== '' ? $metaLabel : basename($folderPath);
    $out = []; $colorIndex = []; $next = 0;
    foreach ($points as $p) {
        if (strpos($p['file'], $prefix) !== 0) continue;
        $rest = substr($p['file'], strlen($prefix));
        $slash = strpos($rest, '/');
        $child = $slash === false ? '' : substr($rest, 0, $slash);
        if (!isset($colorIndex[$child])) $colorIndex[$child] = $next++;
        if ($child === '') {
            $p['label'] = $folderLabel;
            $p['link'] = '#group-' . slugify(pathinfo(basename($p['file']), PATHINFO_FILENAME));
        } else {
            $childMeta = folder_display_meta($dir, $prefix . $child);
            $p['label'] = $childMeta['label'] !== '' ? $childMeta['label'] : $child;
            $p['link'] = '?folder=' . rawurlencode($prefix . $child);
        }
        $p['color'] = map_group_color($colorIndex[$child]);
        $out[] = $p;
    }
    return $out;
}

/**
 * Every visible, located point worth plotting — already filtered the
 * same way everything else on the page is: $files/$itemsData here are
 * whatever they stand as *after* the hiding filter above, so a hidden
 * item is never plotted, same as its thumbnail and its file bytes never
 * load for an unrevealed visitor. photo_location_visible() applies the
 * same three location gates (global/folder/per-item) a grid badge
 * already does. Each point is colored and grouped by its top-level
 * identity (folder-group if it has one, otherwise its own group key) so
 * a Paris cluster and a StTropez cluster read as visually distinct.
 */
function collect_map_points($dir, $files, $itemsData, $fileToGroupKey, $folderGroupKeys, $groupLabels) {
    $points = [];
    $groupColorIndex = [];
    $nextColorIndex = 0;
    foreach ($files as $file) {
        $entry = $itemsData[$file] ?? [];
        if (!photo_location_visible($entry)) continue;
        $loc = $entry['location'];
        $groupKey = $fileToGroupKey[$file] ?? null;
        $colorKey = $groupKey ?? $file;
        if (!isset($groupColorIndex[$colorKey])) {
            $groupColorIndex[$colorKey] = $nextColorIndex++;
        }
        $label = $groupKey !== null ? ($groupLabels[$groupKey] ?? $groupKey) : pathinfo(basename($file), PATHINFO_FILENAME);
        $points[] = [
            'lat' => (float) $loc['lat'],
            'lng' => (float) $loc['lng'],
            'file' => $file,
            'caption' => $entry['caption'] ?? '',
            'label' => $label,
            'color' => map_group_color($groupColorIndex[$colorKey]),
            'thumbUrl' => img_url($file, 'thumb'),
            'link' => map_point_link($file, $groupKey, $folderGroupKeys),
        ];
    }
    return $points;
}

/** Recolours map pins by what the page's notes say about the group each photo is in: a {color:} gives its photos
 *  that colour, {sold} and {reserved} the same red and orange as their ribbons (status wins over colour, as it does on
 *  the page). A recoloured pin takes its group's name in the legend. Photos in groups the notes don't mention keep their
 *  folder colour. */
function apply_note_colors_to_points($points, $groups, $groupColors, $groupStatus, $groupLabels) {
    $byFile = [];
    foreach ($groups as $key => $members) {
        $status = $groupStatus[$key] ?? '';
        $color = $status === 'sold' ? '#e5484d' : ($status === 'reserved' ? '#f5a623' : ($groupColors[$key] ?? null));
        if ($color === null || !is_safe_color($color)) continue;
        foreach ($members as $f) $byFile[$f] = [$color, $groupLabels[$key] ?? $key];
    }
    foreach ($points as &$pt) {
        if (isset($byFile[$pt['file']])) { $pt['color'] = $byFile[$pt['file']][0]; $pt['label'] = $byFile[$pt['file']][1]; }
    }
    unset($pt);
    return $points;
}

/** The legend: one swatch + label per distinct group actually plotted,
 *  in first-seen order — a color-coded map means nothing without a key
 *  to read it against. */
function map_legend_from_points($points) {
    $seen = [];
    $legend = [];
    foreach ($points as $p) {
        if (isset($seen[$p['color'] . '|' . $p['label']])) continue;
        $seen[$p['color'] . '|' . $p['label']] = true;
        $legend[] = ['color' => $p['color'], 'label' => $p['label']];
    }
    return $legend;
}

/**
 * Renders one Leaflet map instance — the shared markup/JS for both the
 * condensed root-page preview and the full ?map page, parameterized over
 * container id and height so the same code draws both. Loads Leaflet's
 * CSS and JS from the cdnjs CDN (two third-party requests, plus the OSM
 * tiles themselves) — only emitted when there's actually at least one
 * point to plot.
 */
function render_leaflet_map($points, $legend, $mapId, $heightCss, $loadLibrary, $radiusMiles = null, $lazy = false) {
    if (empty($points)) return;
    $clusters = cluster_map_points($points, $radiusMiles ?? MAP_CLUSTER_RADIUS_MILES);
    // Hex-escaped so the raw JSON can sit directly inside a <script>
    // block with no further quoting — safe even if a caption happens to
    // contain "</script>", a quote, or an ampersand.
    $clustersJson = json_encode($clusters, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<?php if ($loadLibrary && !$lazy): ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.js"></script>
<?php endif; ?>
<div id="<?= htmlspecialchars($mapId, ENT_QUOTES, 'UTF-8') ?>" style="height:<?= htmlspecialchars($heightCss, ENT_QUOTES, 'UTF-8') ?>; border-radius:10px;"></div>
<?php if (!empty($legend)): ?>
<div class="map-legend">
  <?php foreach ($legend as $l): ?>
    <span class="map-legend-item"><span class="map-legend-swatch" style="background:<?= htmlspecialchars($l['color'], ENT_QUOTES, 'UTF-8') ?>;"></span><?= htmlspecialchars($l['label'], ENT_QUOTES, 'UTF-8') ?></span>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<script>
(function () {
  var clusters = <?= $clustersJson ?>;
  var mapEl = document.getElementById('<?= htmlspecialchars($mapId, ENT_QUOTES, 'UTF-8') ?>');
  function draw() {
  if (!mapEl || !window.L) return;
  var map = L.map(mapEl);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a> contributors',
    maxZoom: 19,
  }).addTo(map);

  function escapeHtml(s) {
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  function popupHtml(pt) {
    var cap = pt.caption ? pt.caption : pt.label;
    return '<a href="' + escapeHtml(pt.link) + '" style="display:block;text-decoration:none;color:inherit;">' +
      '<img src="' + escapeHtml(pt.thumbUrl) + '" style="width:120px;height:120px;object-fit:cover;border-radius:6px;display:block;margin-bottom:6px;" loading="lazy">' +
      '<strong>' + escapeHtml(cap) + '</strong></a>';
  }

  function addSingle(pt) {
    L.circleMarker([pt.lat, pt.lng], { radius: 9, color: '#fff', weight: 2, fillColor: pt.color, fillOpacity: 0.9 })
      .addTo(map)
      .bindPopup(popupHtml(pt));
  }

  var allBounds = [];
  clusters.forEach(function (members) {
    members.forEach(function (m) { allBounds.push([m.lat, m.lng]); });
    if (members.length === 1) {
      addSingle(members[0]);
      return;
    }
    var avgLat = members.reduce(function (s, m) { return s + m.lat; }, 0) / members.length;
    var avgLng = members.reduce(function (s, m) { return s + m.lng; }, 0) / members.length;
    var marker = L.marker([avgLat, avgLng], {
      icon: L.divIcon({
        html: '<div style="background:' + members[0].color + ';color:#fff;border-radius:50%;width:32px;height:32px;display:flex;align-items:center;justify-content:center;font-size:0.85rem;font-weight:600;box-shadow:0 0 0 2px #fff;">' + members.length + '</div>',
        className: '', iconSize: [32, 32], iconAnchor: [16, 16],
      }),
    }).addTo(map);
    marker.on('click', function () {
      map.removeLayer(marker);
      var bounds = members.map(function (m) { return [m.lat, m.lng]; });
      members.forEach(addSingle);
      map.fitBounds(bounds, { maxZoom: 18, padding: [30, 30] });
    });
  });

  if (allBounds.length === 1) {
    map.setView(allBounds[0], 13);
  } else {
    map.fitBounds(allBounds, { padding: [30, 30] });
  }
  }
<?php if ($lazy): ?>
  // Nothing is fetched from anywhere until a visitor asks for the map (see showLazyMap in the page script).
  (window.__lazyMaps = window.__lazyMaps || {})['<?= htmlspecialchars($mapId, ENT_QUOTES, 'UTF-8') ?>'] = draw;
<?php else: ?>
  draw();
<?php endif; ?>
})();
</script>
<?php
}


/* -----------------------------------------------------------------------
 * Per-item data: captions, location, a web link, a folder-group's own
 * display name and chosen cover photo, and a folder's own location
 * toggle. All of it machine-written by the admin tool, never hand-
 * edited, so plain JSON is enough — no custom parser to get wrong. Lives
 * in .gallery-items.php, colocated per folder exactly like
 * .gallery-stats.* and .gallery-cache: a photo's caption lives in the
 * same real folder the photo itself does, and a folder-group's own
 * display name/cover live inside *that* folder (not its parent) — the
 * same place a genuine folder rename already carries everything else
 * along for free, with nothing left to update in some other file.
 * ----------------------------------------------------------------------- */

function items_path($folderPath) {
    return $folderPath . '/.gallery-items.php';
}

/** items: bare filename => {caption, location: {lat,lng,label},
 *  locationVisibility, web}. groups: filename-based group key (root-level
 *  only — see the admin editor) => {displayName}. displayName/cover
 *  describe *this folder itself* when a parent renders it as a
 *  folder-group cover card, along with description (a short blurb), dates
 *  (a free-text date-range subtitle — typed by the admin, never derived
 *  from EXIF, since that would publish when the owner was away) and
 *  sortOrder (an explicit position among sibling folders).
 *  locationEnabled is this folder's own
 *  location-sharing toggle (see ENABLE_PHOTO_LOCATION's doc comment). */
function items_default() {
    return ['items' => [], 'groups' => [], 'displayName' => null, 'description' => null, 'dates' => null, 'sortOrder' => null, 'cover' => null, 'locationEnabled' => false, 'mapEnabled' => null, 'mapRadius' => null, 'photoSort' => null, 'stacks' => []];
}

function items_read($folderPath) {
    return json_file_read(items_path($folderPath), items_default());
}

function items_update($folderPath, $modifier) {
    folder_restriction_cached(null, null, true);
    return json_file_update(items_path($folderPath), items_default(), $modifier);
}

/** Every file's own item data (caption/location/web), across every real
 *  folder $files touches, re-keyed to each file's full relative path —
 *  same colocated-merge shape as stats_get_all_photo_views(). */
function items_get_all_for_files($dir, $files) {
    $byFolder = [];
    foreach ($files as $file) {
        $byFolder[containing_folder_real_path($dir, $file)][] = $file;
    }
    $result = [];
    foreach ($byFolder as $folderPath => $folderFiles) {
        $data = items_read($folderPath);
        foreach ($folderFiles as $file) {
            $bare = basename($file);
            $entry = $data['items'][$bare] ?? [];
            // Not real item data — a folder-level setting stashed onto
            // each of its own files' entries so callers (the location
            // badge/pin, specifically) don't need a second lookup
            // structure just to find out which folder a file lives in.
            $entry['_folderLocationEnabled'] = !empty($data['locationEnabled']);
            $result[$file] = $entry;
        }
    }
    return $result;
}

/** The gates (global constant, folder toggle, per-photo tri-state) that
 *  decide whether a visitor sees this photo's location — see
 *  ENABLE_PHOTO_LOCATION's own doc comment for the full reasoning. Someone
 *  signed in (the owner, or with the private-view password) sees the
 *  location of every photo that has one, whatever its folder or its own
 *  switch says: those switches are about what visitors who aren't signed
 *  in are shown. The global constant still switches the whole feature off
 *  for everyone. The admin tool itself ignores all of it; it can always
 *  see and edit location data for its own authenticated owner. */
function photo_location_visible($itemEntry) {
    if (!ENABLE_PHOTO_LOCATION) return false;
    if (empty($itemEntry['location'])) return false;
    static $trusted = null;
    if ($trusted === null) $trusted = is_revealed();
    if ($trusted) return true;
    $visibility = $itemEntry['locationVisibility'] ?? 'inherit';
    if ($visibility === 'hide') return false;
    if ($visibility === 'show') return true;
    return !empty($itemEntry['_folderLocationEnabled']);
}

/* -----------------------------------------------------------------------
 * The order of the photos inside a folder. A spec says: the base order (name,
 * taken = the capture date in the photo, modified), whether it runs backwards,
 * and which kinds go first whatever the base order (private, then videos, then
 * photos with a location that is shown). It comes from, in turn: a link's
 * ?sort=, the visitor's own choice (a cookie, set by the sort button), the
 * folder's own setting, the site's setting. A spec is written as text, for
 * example "taken.desc.video.located".
 * ----------------------------------------------------------------------- */

/** The three "put first" kinds in the order they stack in (the site's SORT_PRIORITY; anything missing or unknown is put back in the default order). */
function sort_priority_list() {
    static $list = null;
    if ($list !== null) return $list;
    $all = ['private', 'video', 'located'];
    $list = [];
    foreach (explode(',', (string) SORT_PRIORITY) as $k) {
        $k = strtolower(trim($k));
        if (in_array($k, $all, true) && !in_array($k, $list, true)) $list[] = $k;
    }
    foreach ($all as $k) if (!in_array($k, $list, true)) $list[] = $k;
    return $list;
}

/** What each kind is called, in the settings and in the sentence that sums them up. */
function sort_priority_names() {
    return ['private' => ['Private items', 'private items', 'SORT_PRIVATE_FIRST'], 'video' => ['Videos', 'videos', 'SORT_VIDEOS_FIRST'], 'located' => ['Located photos', 'photos with a location', 'SORT_LOCATED_FIRST']];
}

/** The one sentence that says what the "put first" list does, e.g. "Videos, then photos with a location, then the rest." */
function sort_priority_summary($order, $on, $siteOrder = null, $siteOn = null) {
    $names = sort_priority_names();
    $parts = [];
    foreach ($order as $k) if (!empty($on[$k])) $parts[] = $names[$k][1];
    if (!$parts && $siteOrder !== null) {   // a folder's list with everything off, while the site puts something first
        $siteParts = [];
        foreach ($siteOrder as $k) if (!empty($siteOn[$k])) $siteParts[] = $names[$k][1];
        if ($siteParts) return 'Nothing is put first in this folder, though the site puts ' . implode(', then ', $siteParts) . ' first.';
    }
    if (!$parts) return 'Nothing is put first: photos follow the order above.';
    $text = implode(', then ', $parts) . ', then the rest.';
    return ucfirst($text);
}

/** The site's own spec, from the settings. */
function media_sort_site_spec() {
    return ['order' => PHOTO_SORT_ORDER === 'mtime' ? 'modified' : (PHOTO_SORT_ORDER === 'taken' ? 'taken' : 'name'), 'desc' => (bool) PHOTO_SORT_DESC,
            'private' => (bool) SORT_PRIVATE_FIRST, 'video' => (bool) SORT_VIDEOS_FIRST, 'located' => (bool) SORT_LOCATED_FIRST];
}

/** A spec written as text, or null for anything else (including "default", which means: no choice of my own). */
function media_sort_parse($text) {
    if (!is_string($text) || $text === '' || strlen($text) > 60) return null;
    $spec = ['order' => 'name', 'desc' => false, 'private' => false, 'video' => false, 'located' => false];
    $parts = explode('.', strtolower($text));
    if (!in_array($parts[0], ['name', 'taken', 'modified'], true)) return null;
    $spec['order'] = array_shift($parts);
    foreach ($parts as $flag) {
        if ($flag === 'desc') $spec['desc'] = true;
        elseif (in_array($flag, ['private', 'video', 'located'], true)) $spec[$flag] = true;
        elseif ($flag !== 'asc') return null;
    }
    return $spec;
}

function media_sort_encode($spec) {
    $out = $spec['order'];
    foreach (['desc', 'private', 'video', 'located'] as $k) if (!empty($spec[$k])) $out .= '.' . $k;
    return $out;
}

/** The visitor's own choice: a link's ?sort= wins over the cookie the sort button sets. Null when there is none. */
function media_sort_override() {
    static $memo = false;
    if ($memo !== false) return $memo;
    $memo = null;
    foreach ([$_GET['sort'] ?? null, $_COOKIE['gallery_sort'] ?? null] as $text) {
        if (is_string($text) && $text !== '') { $memo = media_sort_parse($text); break; }
    }
    return $memo;
}

/** The order the "first" choices stack in for one folder: its own list when it has one, else the site's. */
function media_sort_priority_for_folder($folderReal) {
    $own = items_read($folderReal)['photoSort']['priority'] ?? null;
    if (is_array($own)) {
        $want = ['private', 'video', 'located']; $sorted = array_values($own); sort($sorted); sort($want);
        if ($sorted === $want) return array_values($own);
    }
    return sort_priority_list();
}

/** The spec in force for one folder (given its real path): the visitor's choice, else the folder's own setting laid over the site's. */
function media_sort_spec_for_folder($folderReal) {
    $override = media_sort_override();
    if ($override !== null) return $override;
    $spec = media_sort_site_spec();
    $own = items_read($folderReal)['photoSort'] ?? null;
    if (is_array($own)) {
        if (in_array($own['order'] ?? null, ['name', 'taken', 'modified'], true)) $spec['order'] = $own['order'];
        foreach (['desc', 'private', 'video', 'located'] as $k) if (isset($own[$k]) && is_bool($own[$k])) $spec[$k] = $own[$k];
    }
    return $spec;
}

/** The capture time in a TIFF block (the shape EXIF uses): the original date in the Exif part, else the digitized date, else
 *  the file's date field; null when there is none. Read by hand, so it needs no PHP extension and works for JPEG and HEIC alike.
 *  An offset the camera recorded (iPhones do) makes it exact; without one it is taken as UTC, good enough to order by. */
function tiff_capture_time($tiff) {
    $n = strlen($tiff);
    if ($n < 8) return null;
    $le = substr($tiff, 0, 2) === 'II';
    if (!$le && substr($tiff, 0, 2) !== 'MM') return null;
    $u16 = function ($o) use ($tiff, $n, $le) { return $o >= 0 && $o + 2 <= $n ? unpack($le ? 'v' : 'n', $tiff, $o)[1] : null; };
    $u32 = function ($o) use ($tiff, $n, $le) { return $o >= 0 && $o + 4 <= $n ? unpack($le ? 'V' : 'N', $tiff, $o)[1] : null; };
    if ($u16(2) !== 42) return null;
    $entries = function ($ifd) use ($u16) {   // tag => offset of its 12-byte entry
        $out = [];
        $count = $ifd === null ? null : $u16($ifd);
        if ($count === null || $count > 400) return $out;
        for ($i = 0; $i < $count; $i++) { $e = $ifd + 2 + 12 * $i; $tag = $u16($e); if ($tag !== null) $out[$tag] = $e; }
        return $out;
    };
    $text = function ($e) use ($tiff, $n, $u32) {
        $cnt = $u32($e + 4);
        if ($cnt === null || $cnt < 1 || $cnt > 64) return null;
        $at = $cnt <= 4 ? $e + 8 : $u32($e + 8);
        return $at !== null && $at + $cnt <= $n ? rtrim(substr($tiff, $at, $cnt), "\0 ") : null;
    };
    $ifd0 = $entries($u32(4));
    $exifAt = isset($ifd0[0x8769]) ? $u32($ifd0[0x8769] + 8) : null;
    $exif = $exifAt !== null ? $entries($exifAt) : [];
    foreach ([[$exif, 0x9003], [$exif, 0x9004], [$ifd0, 0x0132]] as [$map, $tag]) {
        if (!isset($map[$tag])) continue;
        $t = $text($map[$tag]);
        if ($t === null || !preg_match('/^(\d{4}):(\d\d):(\d\d) (\d\d):(\d\d):(\d\d)$/', $t, $m) || (int) $m[1] < 1971) continue;
        $ts = gmmktime((int) $m[4], (int) $m[5], (int) $m[6], (int) $m[2], (int) $m[3], (int) $m[1]);
        if ($tag === 0x9003 && isset($exif[0x9011]) && preg_match('/^([+-])(\d\d):(\d\d)$/', (string) $text($exif[0x9011]), $o)) {
            $ts -= ($o[1] === '-' ? -1 : 1) * ((int) $o[2] * 3600 + (int) $o[3] * 60);
        }
        return $ts;
    }
    return null;
}

/** The moment a QuickTime/MP4 video was really recorded, from the "creation date" an iPhone keeps in the movie's metadata
 *  (with the time zone, e.g. 2026-10-13T12:54:49+0200), as a timestamp; null when the file has none. The movie header's own
 *  "created" stamp is not used first because it is often when the file was last exported or copied, not when it was shot. */
function quicktime_recorded_time($fh, $meta) {
    if ($meta['end'] - $meta['data'] > 1048576) return null;
    $from = $meta['data'];
    $probe = scrub_read($fh, $from + 4, 4);
    if ($probe !== null && !ctype_alpha($probe)) $from += 4;   // (a version field comes first in some files, not in others)
    $kids = scrub_boxes($fh, $from, $meta['end']);
    $keys = $items = null;
    foreach ($kids ?? [] as $k) { if ($k['type'] === 'keys') $keys = $k; elseif ($k['type'] === 'ilst') $items = $k; }
    if ($keys === null || $items === null) return null;
    $head = scrub_read($fh, $keys['data'], 8);
    if ($head === null) return null;
    $count = unpack('N', $head, 4)[1];
    $pos = $keys['data'] + 8; $want = null;
    for ($i = 1; $i <= $count && $i <= 256 && $pos + 8 <= $keys['end']; $i++) {
        $h = scrub_read($fh, $pos, 8);
        if ($h === null) return null;
        $len = unpack('N', $h)[1];
        if ($len < 8 || $pos + $len > $keys['end']) return null;
        if ($len - 8 <= 64 && scrub_read($fh, $pos + 8, $len - 8) === 'com.apple.quicktime.creationdate') { $want = $i; break; }
        $pos += $len;
    }
    if ($want === null) return null;
    foreach (scrub_boxes($fh, $items['data'], $items['end']) ?? [] as $item) {
        if (unpack('N', $item['type'])[1] !== $want) continue;   // (an item is named by its key's number)
        foreach (scrub_boxes($fh, $item['data'], $item['end']) ?? [] as $d) {
            if ($d['type'] !== 'data' || $d['end'] - $d['data'] < 9 || $d['end'] - $d['data'] > 72) continue;
            $text = scrub_read($fh, $d['data'] + 8, $d['end'] - $d['data'] - 8);
            if ($text === null || !preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(\.\d+)?(Z|[+-]\d\d:?\d\d)$/', $text)) return null;
            $ts = strtotime($text);
            return $ts !== false && $ts > 31536000 && $ts < time() + 172800 ? $ts : null;
        }
    }
    return null;
}

/** When a photo or video was taken, from the file itself: a JPEG's or HEIC's EXIF, a video's creation time. Null when the file
 *  doesn't say (a PNG, a screenshot, a stripped copy). */
function media_capture_time($path) {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $size = (int) @filesize($path);
    $fh = $size > 12 ? @fopen($path, 'rb') : false;
    if (!$fh) return null;
    try {
        if ($ext === 'jpg' || $ext === 'jpeg') {
            if (scrub_read($fh, 0, 2) !== "\xFF\xD8") return null;
            $pos = 2;
            for ($guard = 0; $guard < 40 && $pos + 4 <= $size; $guard++) {
                $h = scrub_read($fh, $pos, 4);
                if ($h === null || $h[0] !== "\xFF") return null;
                $marker = ord($h[1]); $len = unpack('n', $h, 2)[1];
                if ($marker === 0xDA || $marker === 0xD9) return null;
                if ($marker === 0xE1 && $len >= 8 && $len < 1048576) {
                    $d = scrub_read($fh, $pos + 4, $len - 2);
                    if ($d !== null && substr($d, 0, 6) === "Exif\0\0") return tiff_capture_time(substr($d, 6));
                }
                $pos += 2 + $len;
            }
            return null;
        }
        $top = scrub_boxes($fh, 0, $size);
        if ($top === null) return null;
        if ($ext === 'mov' || $ext === 'mp4' || $ext === 'm4v') {
            foreach ($top as $b) {
                if ($b['type'] !== 'moov') continue;
                $kids = scrub_boxes($fh, $b['data'], $b['end']);
                foreach ($kids ?? [] as $k) {
                    if ($k['type'] !== 'meta') continue;
                    $recorded = quicktime_recorded_time($fh, $k);
                    if ($recorded !== null) return $recorded;   // (when it was shot, not when the file was last saved)
                }
                foreach ($kids ?? [] as $k) {
                    if ($k['type'] !== 'mvhd') continue;
                    $body = scrub_read($fh, $k['data'], 12);
                    if ($body === null) return null;
                    $created = ord($body[0]) === 1 ? unpack('J', scrub_read($fh, $k['data'] + 4, 8) ?? str_repeat("\0", 8))[1] : unpack('N', $body, 4)[1];
                    $ts = $created - 2082844800;   // QuickTime counts from 1904
                    return $ts > 31536000 && $ts < time() + 172800 ? $ts : null;
                }
            }
            return null;
        }
        if ($ext === 'heic' || $ext === 'heif') {
            $meta = null;
            foreach ($top as $b) if ($b['type'] === 'meta') $meta = $b;
            $kids = $meta === null ? null : scrub_boxes($fh, $meta['data'] + 4, $meta['end']);
            if ($kids === null) return null;
            $exifId = null;
            foreach ($kids as $k) {
                if ($k['type'] !== 'iinf') continue;
                $skip = scrub_read($fh, $k['data'], 1) === "\0" ? 6 : 8;
                foreach (scrub_boxes($fh, $k['data'] + $skip, $k['end']) ?? [] as $inf) {
                    if ($inf['type'] !== 'infe') continue;
                    $body = scrub_read($fh, $inf['data'], min(64, $inf['end'] - $inf['data']));
                    if ($body === null || strlen($body) < 12 || ord($body[0]) < 2) continue;
                    $v = ord($body[0]);
                    $id = $v === 2 ? unpack('n', $body, 4)[1] : unpack('N', $body, 4)[1];
                    if (substr($body, $v === 2 ? 8 : 10, 4) === 'Exif') $exifId = $id;
                }
            }
            if ($exifId === null) return null;
            foreach ($kids as $k) {
                if ($k['type'] !== 'iloc') continue;
                $h = scrub_read($fh, $k['data'], 8);
                if ($h === null) return null;
                $ver = ord($h[0]);
                $offSize = ord($h[4]) >> 4; $lenSize = ord($h[4]) & 15; $baseSize = ord($h[5]) >> 4; $idxSize = $ver > 0 ? ord($h[5]) & 15 : 0;
                $count = $ver < 2 ? unpack('n', $h, 6)[1] : unpack('N', scrub_read($fh, $k['data'] + 6, 4) ?? "\0\0\0\0")[1];
                $at = $k['data'] + ($ver < 2 ? 8 : 10);
                $num = function ($len) use ($fh, &$at) {
                    if ($len === 0) return 0;
                    $bytes = scrub_read($fh, $at, $len); $at += $len;
                    if ($bytes === null) return null;
                    $x = 0; for ($i = 0; $i < $len; $i++) $x = ($x << 8) | ord($bytes[$i]);
                    return $x;
                };
                for ($i = 0; $i < $count && $i < 4096; $i++) {
                    $id = $num($ver < 2 ? 2 : 4);
                    if ($ver > 0) $num(2);
                    $num(2);
                    $base = $num($baseSize); $ext2 = $num(2);
                    if ($id === null || $base === null || $ext2 === null || $ext2 > 64) return null;
                    $first = null;
                    for ($e = 0; $e < $ext2; $e++) {
                        if ($idxSize) $num($idxSize);
                        $eo = $num($offSize); $el = $num($lenSize);
                        if ($eo === null || $el === null) return null;
                        if ($first === null) $first = [$base + $eo, $el];
                    }
                    if ($id !== $exifId || $first === null || $first[1] < 12 || $first[1] > 1048576) continue;
                    $data = scrub_read($fh, $first[0], $first[1]);
                    return $data === null ? null : tiff_capture_time(substr($data, 4 + unpack('N', $data)[1]));
                }
            }
        }
        return null;
    } catch (\Throwable $e) {
        return null;
    } finally {
        fclose($fh);
    }
}

/** When each photo of a folder was taken, as a number to sort by: the capture date in the file (see media_capture_time), else
 *  its modified time. Reading dates out of many files is slow, so what is found is kept (by file name, size and modified
 *  time) in the folder's cache. */
function media_taken_times($folderReal, $names) {
    $cachePath = $folderReal . '/.gallery-cache/taken3.json';
    $known = [];
    $raw = is_file($cachePath) ? json_decode((string) @file_get_contents($cachePath), true) : null;
    if (is_array($raw)) $known = $raw;
    $out = []; $changed = false; $budget = 20000;
    foreach ($names as $name) {
        $path = $folderReal . '/' . $name;
        $mtime = (int) @filemtime($path); $size = (int) @filesize($path);
        $hit = $known[$name] ?? null;
        if (is_array($hit) && ($hit[0] ?? null) === $mtime && ($hit[1] ?? null) === $size && is_int($hit[2] ?? null)) { $out[$name] = $hit[2]; continue; }
        $when = $mtime;
        if ($budget > 0) {
            $budget--;
            $found = media_capture_time($path);
            if ($found !== null) $when = $found;
        }
        $out[$name] = $when;
        $known[$name] = [$mtime, $size, $when];
        $changed = true;
    }
    if ($changed) {
        $live = array_intersect_key($known, array_flip($names));
        $dirOk = is_dir($folderReal . '/.gallery-cache') || @mkdir($folderReal . '/.gallery-cache', 0755);
        if ($dirOk) {
            $tmp = $cachePath . '.tmp' . bin2hex(random_bytes(3));
            if (@file_put_contents($tmp, json_encode($live)) !== false && !@rename($tmp, $cachePath)) @unlink($tmp);
        }
    }
    return $out;
}

/** A folder's stacks: photos the owner placed next to each other by hand, which stay together (in the order set) wherever
 *  the sort puts them. Stored in the folder's settings as [{anchor, files: [bare names]}]; the anchor is the member whose own
 *  place in the sort the whole stack takes. $list is that folder's photos (relative paths): returns the stacks as lists of
 *  the paths found there, anchor first named, skipping what is gone and any stack left with fewer than two. */
function media_stacks_resolve($folderReal, $list) {
    $stacks = items_read($folderReal)['stacks'] ?? [];
    if (!is_array($stacks) || !$stacks) return [];
    $byBare = [];
    foreach ($list as $f) $byBare[basename($f)] = $f;
    $out = []; $used = [];
    foreach ($stacks as $s) {
        if (!is_array($s) || !is_array($s['files'] ?? null)) continue;
        $members = [];
        foreach ($s['files'] as $name) {
            if (is_string($name) && isset($byBare[$name]) && !isset($used[$byBare[$name]])) { $members[] = $byBare[$name]; $used[$byBare[$name]] = true; }
        }
        if (count($members) < 2) continue;
        $anchor = is_string($s['anchor'] ?? null) && isset($byBare[$s['anchor']]) && in_array($byBare[$s['anchor']], $members, true) ? $byBare[$s['anchor']] : $members[0];
        $out[] = ['anchor' => $anchor, 'files' => $members];
    }
    return $out;
}

/** The stacks as stored, cleaned: names only, each photo in one stack at most, an anchor that is a member, and nothing left
 *  that holds fewer than two photos. */
function media_stacks_clean($stacks) {
    $out = []; $used = [];
    foreach (is_array($stacks) ? $stacks : [] as $s) {
        if (!is_array($s) || !is_array($s['files'] ?? null)) continue;
        $files = [];
        foreach ($s['files'] as $n) if (is_string($n) && $n !== '' && !isset($used[$n])) { $files[] = $n; $used[$n] = true; }
        if (count($files) < 2) { foreach ($files as $n) unset($used[$n]); continue; }
        $anchor = is_string($s['anchor'] ?? null) && in_array($s['anchor'], $files, true) ? $s['anchor'] : $files[0];
        $out[] = ['anchor' => $anchor, 'files' => $files];
    }
    return $out;
}

/** Change a folder's stacks inside its settings ($data): 'place' puts the photos $names (bare names, in the order wanted)
 *  just before or after $target, joining its stack or starting one with it as the anchor; 'release' takes them out of their
 *  stacks; 'reset' clears every stack. */
function media_stacks_change(&$data, $mode, $names = [], $target = '', $side = 'after') {
    if ($mode === 'reset') { $data['stacks'] = []; return; }
    $stacks = media_stacks_clean($data['stacks'] ?? []);
    foreach ($stacks as &$s) {
        $s['files'] = array_values(array_diff($s['files'], $names));
        if (!in_array($s['anchor'], $s['files'], true)) $s['anchor'] = $s['files'][0] ?? '';
    }
    unset($s);
    if ($mode === 'place') {
        $placed = false;
        foreach ($stacks as &$s) {
            $i = array_search($target, $s['files'], true);
            if ($i === false) continue;
            array_splice($s['files'], $side === 'before' ? $i : $i + 1, 0, $names);
            $placed = true;
            break;
        }
        unset($s);
        if (!$placed) $stacks[] = ['anchor' => $target, 'files' => $side === 'before' ? array_merge($names, [$target]) : array_merge([$target], $names)];
    }
    $data['stacks'] = media_stacks_clean($stacks);
}

/** $files (relative paths) with the photos of each folder put in that folder's order (see above); folders keep their places.
 *  A stack placed by hand is sorted as its anchor is and its members follow in the order set. */
function media_sort_apply($files, $dir) {
    if (count($files) < 2) return $files;
    $byFolder = [];
    foreach ($files as $f) $byFolder[containing_folder_real_path($dir, $f)][] = $f;
    $scanned = PHOTO_SORT_ORDER === 'mtime' ? 'modified' : 'name';   // the order the scan already produced
    $revealed = is_revealed();
    $result = [];
    foreach ($byFolder as $folderReal => $list) {
        $spec = media_sort_spec_for_folder($folderReal);
        $prio = media_sort_priority_for_folder($folderReal);
        if (!$revealed) $spec['private'] = false;   // visitors never see private items: nothing to put first
        $stacks = count($list) < 2 ? [] : media_stacks_resolve($folderReal, $list);
        if (count($list) < 2 || (!$stacks && $spec['order'] === $scanned && !$spec['desc'] && !$spec['private'] && !$spec['video'] && !$spec['located'])) { foreach ($list as $f) $result[] = $f; continue; }
        $info = [];
        if ($spec['private'] || $spec['located']) $itemsData = items_get_all_for_files($dir, $list); else $itemsData = [];
        $times = $spec['order'] === 'name' ? [] : media_taken_times($folderReal, array_map('basename', $list));
        foreach ($list as $f) {
            $info[$f] = [
                'private' => $spec['private'] && file_is_hidden($dir, $f, $itemsData),
                'video' => $spec['video'] && is_video_file($f),
                'located' => $spec['located'] && photo_location_visible($itemsData[$f] ?? []),
                't' => $spec['order'] === 'modified' ? (int) @filemtime(resolve_real_path($dir, $f)) : ($times[basename($f)] ?? 0),
            ];
        }
        $unit = []; $follower = [];   // a stack's members by its anchor; the members that ride along with it
        foreach ($stacks as $st) {
            $unit[$st['anchor']] = $st['files'];
            foreach ($st['files'] as $m) if ($m !== $st['anchor']) $follower[$m] = true;
        }
        $list = array_values(array_filter($list, function ($f) use ($follower) { return !isset($follower[$f]); }));
        usort($list, function ($a, $b) use ($info, $spec, $prio) {
            foreach ($prio as $k) {
                if ($spec[$k]) { $d = ($info[$a][$k] ? 0 : 1) <=> ($info[$b][$k] ? 0 : 1); if ($d !== 0) return $d; }
            }
            $c = $spec['order'] === 'name' ? 0 : $info[$a]['t'] <=> $info[$b]['t'];
            if ($c === 0) $c = strnatcasecmp(basename($a), basename($b));
            return $spec['desc'] ? -$c : $c;
        });
        foreach ($list as $f) {
            if (isset($unit[$f])) { foreach ($unit[$f] as $m) $result[] = $m; } else $result[] = $f;
        }
    }
    return $result;
}

/** Whether this PHP can read embedded GPS out of this kind of file at all
 *  (JPEG needs the exif extension, HEIC/HEIF needs Imagick). Anything else
 *  — PNG, GIF, WEBP — has no GPS block, so there is nothing to wait
 *  for or to mark as checked. */
function gps_readable_file($file) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if ($ext === 'jpg' || $ext === 'jpeg') return function_exists('exif_read_data');
    if ($ext === 'heic' || $ext === 'heif') return class_exists('Imagick');
    if ($ext === 'mov' || $ext === 'mp4') return true;   // read from the file's own location tag (see extract_video_gps)
    return false;
}

/**
 * Once a folder's locations are switched on, every photo in it that has
 * GPS in the file gets that location stored, so turning the switch on
 * actually puts the places on the gallery and map — hiding one photo is
 * then the opt-out (its "Always hide" setting, or clearing its
 * coordinates), not showing each one. A photo is read once and marked
 * (gpsChecked), so a location the owner later clears stays cleared, and a
 * photo with no GPS isn't re-read on every visit. A photo that already
 * has a saved location is never touched. Returns how many photos were
 * given a location. Runs when the switch is turned on and whenever the
 * owner opens that folder in the admin (which is how photos uploaded
 * later get picked up); never on a visitor's request.
 */
function import_folder_gps($dir, $folderReal, $looseFiles) {
    if (!ENABLE_PHOTO_LOCATION) return 0;
    $data = items_read($folderReal);
    if (empty($data['locationEnabled'])) return 0;
    $found = [];
    foreach ($looseFiles as $file) {
        $bare = basename($file);
        $entry = $data['items'][$bare] ?? [];
        if (!empty($entry['location']) || !empty($entry['gpsChecked']) || !gps_readable_file($file)) continue;
        $found[$bare] = extract_photo_gps(resolve_real_path($dir, $file));
    }
    if (!$found) return 0;
    $imported = 0;
    items_update($folderReal, function (&$d) use ($found, &$imported) {
        foreach ($found as $bare => $loc) {
            $entry = $d['items'][$bare] ?? [];
            if (!empty($entry['location']) || !empty($entry['gpsChecked'])) continue;
            $entry['gpsChecked'] = true;
            if ($loc !== null) {
                $entry['location'] = ['lat' => $loc['lat'], 'lng' => $loc['lng'], 'label' => ''];
                if (($entry['locationVisibility'] ?? 'inherit') !== 'hide') $imported++;   // one switched off is stored, not shown
            }
            $d['items'][$bare] = $entry;
        }
    });
    return empty($GLOBALS['__data_write_failed']) ? $imported : 0;
}

/** A numeric latitude/longitude pair, validated the same discipline
 *  is_safe_color() already applies to {color:} before it's ever trusted
 *  into a generated map-link URL. */
function is_valid_coordinate($lat, $lng) {
    return is_numeric($lat) && is_numeric($lng)
        && (float) $lat >= -90 && (float) $lat <= 90
        && (float) $lng >= -180 && (float) $lng <= 180;
}

/** Converts one EXIF GPS component (3 degree/minute/second rationals,
 *  e.g. "48/1", "51/1", "3056/100") plus its N/S/E/W ref into a signed
 *  decimal-degree float. */
function exif_gps_to_decimal($component, $ref) {
    if (!is_array($component) || count($component) < 3) return null;
    $toFloat = function ($frac) {
        if (is_string($frac) && strpos($frac, '/') !== false) {
            [$num, $den] = array_pad(explode('/', $frac, 2), 2, '1');
            $den = (float) $den;
            return $den != 0 ? ((float) $num) / $den : 0.0;
        }
        return (float) $frac;
    };
    $decimal = $toFloat($component[0]) + ($toFloat($component[1]) / 60) + ($toFloat($component[2]) / 3600);
    return (strtoupper((string) $ref) === 'S' || strtoupper((string) $ref) === 'W') ? -$decimal : $decimal;
}

/** Auto-fills location from a photo's embedded EXIF GPS block, if any:
 *  JPEG via exif_read_data(), HEIC/HEIF via Imagick (see
 *  extract_heic_gps). Anything else (PNG, GIF, WEBP, BMP, video) has no
 *  GPS block to read, so manual entry is the only route. Returns null on
 *  anything missing or unparseable — manual entry is always the
 *  fallback, never a hard failure. */
function extract_photo_gps($realPath) {
    $ext = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
    if (in_array($ext, ['heic', 'heif'], true)) {
        return extract_heic_gps($realPath);
    }
    if (in_array($ext, ['mov', 'mp4'], true)) {
        return extract_video_gps($realPath);
    }
    return extract_jpeg_gps($realPath);
}

/** A video's location, from the tag phones write into it: QuickTime's "location.ISO6709" item (iPhone) or the
 *  \xA9xyz atom (Android and most others), both a string like "+48.8584+002.2945+035.000/". Null if it has none. */
function extract_video_gps($realPath) {
    $size = @filesize($realPath);
    $fh = $size === false ? false : @fopen($realPath, 'rb');
    if (!$fh) return null;
    $strings = [];
    try {
        $walk = function ($from, $to, $depth) use (&$walk, &$strings, $fh) {
            $boxes = scrub_boxes($fh, $from, $to);
            if ($boxes === null) return;
            foreach ($boxes as $b) {
                $type = $b['type'];
                if ($type === "\xA9xyz") {
                    $v = scrub_read($fh, $b['data'], min(64, $b['end'] - $b['data']));
                    if ($v !== null && strlen($v) > 4) $strings[] = substr($v, 4);   // 2 bytes length + 2 bytes language, then the text
                } elseif (in_array($type, ['moov', 'trak', 'udta'], true) && $depth < 6) {
                    $walk($b['data'], $b['end'], $depth + 1);
                } elseif ($type === 'meta' && $depth < 6) {
                    $probe = scrub_read($fh, $b['data'] + 4, 4);
                    $inner = $probe === 'hdlr' ? $b['data'] : $b['data'] + 4;
                    $kids = scrub_boxes($fh, $inner, $b['end']);
                    if ($kids === null) continue;
                    $locationKeys = [];
                    foreach ($kids as $k) {
                        if ($k['type'] !== 'keys') continue;
                        $head = scrub_read($fh, $k['data'], 8);
                        if ($head === null) continue;
                        $entries = unpack('N', $head, 4)[1];
                        $at = $k['data'] + 8;
                        for ($i = 1; $i <= $entries && $i < 512; $i++) {
                            $eh = scrub_read($fh, $at, 8);
                            if ($eh === null) break;
                            $elen = unpack('N', $eh)[1];
                            if ($elen < 8 || $at + $elen > $k['end']) break;
                            $name = scrub_read($fh, $at + 8, $elen - 8);
                            if ($name !== null && stripos($name, 'location.ISO6709') !== false) $locationKeys[$i] = true;
                            $at += $elen;
                        }
                    }
                    foreach ($kids as $k) {
                        if ($k['type'] !== 'ilst' || !$locationKeys) continue;
                        foreach (scrub_boxes($fh, $k['data'], $k['end']) ?? [] as $item) {
                            if (!isset($locationKeys[unpack('N', $item['type'])[1]])) continue;
                            foreach (scrub_boxes($fh, $item['data'], $item['end']) ?? [] as $d) {
                                if ($d['type'] === 'data' && $d['end'] - $d['data'] > 8) {
                                    $v = scrub_read($fh, $d['data'] + 8, min(64, $d['end'] - $d['data'] - 8));
                                    if ($v !== null) $strings[] = $v;
                                }
                            }
                        }
                    }
                }
            }
        };
        $walk(0, $size, 0);
    } catch (Throwable $e) {
        $strings = [];
    } finally {
        fclose($fh);
    }
    foreach ($strings as $str) {
        if (preg_match('/^\s*([+-]\d{1,2}(?:\.\d+)?)([+-]\d{1,3}(?:\.\d+)?)/', $str, $m) && is_valid_coordinate($m[1], $m[2])) {
            return ['lat' => round((float) $m[1], 6), 'lng' => round((float) $m[2], 6)];
        }
    }
    return null;
}

/** HEIC/HEIF carry the same EXIF GPS tags, but exif_read_data() can't
 *  open the container — Imagick can, and exposes them as exif:GPS*
 *  properties (each coordinate as "n/d,n/d,n/d"). pingImage() reads
 *  just the metadata, without decoding the pixels. Needs Imagick with
 *  HEIC support, the same condition HEIC display already has. */
function extract_heic_gps($realPath) {
    if (!class_exists('Imagick')) return null;
    try {
        $im = new Imagick();
        $im->pingImage($realPath);
        $props = $im->getImageProperties('exif:GPS*');
        $im->clear();
    } catch (Exception $e) {
        return null;
    }
    $split = function ($value) {
        return array_map('trim', explode(',', (string) $value));
    };
    if (empty($props['exif:GPSLatitude']) || empty($props['exif:GPSLongitude'])) return null;
    $lat = exif_gps_to_decimal($split($props['exif:GPSLatitude']), $props['exif:GPSLatitudeRef'] ?? 'N');
    $lng = exif_gps_to_decimal($split($props['exif:GPSLongitude']), $props['exif:GPSLongitudeRef'] ?? 'E');
    if ($lat === null || $lng === null || !is_valid_coordinate($lat, $lng)) return null;
    return ['lat' => round($lat, 6), 'lng' => round($lng, 6)];
}

/** JPEG half of extract_photo_gps(). */
function extract_jpeg_gps($realPath) {
    if (!function_exists('exif_read_data')) return null;
    $ext = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg'], true)) return null;
    $exif = @exif_read_data($realPath, 'GPS');
    if (!is_array($exif) || empty($exif['GPSLatitude']) || empty($exif['GPSLongitude'])) return null;
    $lat = exif_gps_to_decimal($exif['GPSLatitude'], $exif['GPSLatitudeRef'] ?? 'N');
    $lng = exif_gps_to_decimal($exif['GPSLongitude'], $exif['GPSLongitudeRef'] ?? 'E');
    if ($lat === null || $lng === null || !is_valid_coordinate($lat, $lng)) return null;
    return ['lat' => round($lat, 6), 'lng' => round($lng, 6)];
}

/**
 * Garbage-collects stale .gallery-items.php entries — a photo deleted
 * (or renamed by hand, not via a real folder rename) orphans its caption
 * entry within a folder that itself still exists. Same precedent as
 * thumbnail-cache cleanup: swept here, and reported through the existing
 * notices mechanism, the same way an unknown {photos:} reference already
 * is. A whole folder disappearing needs no separate handling — its
 * .gallery-items.php is deleted along with it in the same operation.
 *
 * An entry is an orphan only if its file is GONE from the disk — never
 * merely because a setting currently keeps that file out of the gallery.
 * Switching ENABLE_VIDEO off hides every video, and switching the thumbnail
 * cache off hides every HEIC photo; neither means those files were deleted,
 * and their captions, locations and hide flags must survive until the
 * setting is switched back on.
 */
function gc_items_data($dir, $files, &$galleryNotices) {
    $byFolder = [];
    foreach ($files as $file) {
        $byFolder[containing_folder_real_path($dir, $file)][] = basename($file);
    }
    // Only ever GC folders that actually have an items store — a gallery
    // that's never touched the admin tool pays nothing for this.
    $candidateFolders = array_unique(array_merge(array_keys($byFolder), [$dir]));
    foreach ($candidateFolders as $folderPath) {
        $path = items_path($folderPath);
        if (!file_exists($path)) continue;
        $validBareNames = array_fill_keys($byFolder[$folderPath] ?? [], true);
        $orphanCount = 0;
        items_update($folderPath, function (&$data) use ($validBareNames, $folderPath, &$orphanCount) {
            foreach (array_keys($data['items']) as $bare) {
                if (!isset($validBareNames[$bare]) && !file_exists($folderPath . '/' . $bare)) {
                    unset($data['items'][$bare]);
                    $orphanCount++;
                }
            }
        });
        if ($orphanCount > 0) {
            $galleryNotices[] = $orphanCount === 1
                ? 'A caption/location/web entry for a deleted or renamed photo was cleaned up.'
                : $orphanCount . ' caption/location/web entries for deleted or renamed photos were cleaned up.';
        }
    }
}

/* -----------------------------------------------------------------------
 * The admin editor (?admin). Entirely inert when ADMIN_PASSWORD is
 * empty — see its own doc comment above. Manages the current folder's
 * (root or one subfolder) caption/location/web per item, its folder- or
 * filename-group display names, its chosen cover photo, and every .md
 * in it (active or not) with raw-text save/save-as/delete.
 * ----------------------------------------------------------------------- */

/** ?admin's own &folder= — validated against the real $folderGroupKeys
 *  the same way the public ?folder= is. false means "was given but
 *  invalid" (caller 404s); null means root. */
function admin_resolve_folder($files) {
    if (!isset($_GET['folder'])) return null;
    $requested = $_GET['folder'];
    // Valid at any depth, but only if the script's own file list has
    // something under it — same allowlist-not-trust rule as the public
    // ?folder=.
    return !empty(folder_files_under($files, $requested)) ? $requested : false;
}

/** Every folder at every depth, as a flat row of links labelled with the
 *  full path — parents before children, so the tree reads top-down. */
/** How many photos/videos each folder holds: [own, total] keyed by folder path
 *  ('' is the root). "Own" is what is directly in that folder (what its Photos
 *  tab lists); "total" includes everything beneath it. */
function admin_folder_counts($files) {
    $own = []; $total = [];
    foreach ($files as $f) {
        $d = dirname($f);
        $d = ($d === '.' || $d === '') ? '' : $d;
        $own[$d] = ($own[$d] ?? 0) + 1;
        for ($p = $d; ; $p = (strpos($p, '/') === false ? '' : substr($p, 0, strrpos($p, '/')))) {
            $total[$p] = ($total[$p] ?? 0) + 1;
            if ($p === '') break;
        }
    }
    return [$own, $total];
}

/** The folders as a tree: each folder under its parent with guide lines, a
 *  count of what is directly in it, and a chevron to fold the ones beneath it.
 *  The folder being edited is marked (the script keeps its ancestors open and
 *  folds the other branches; without JavaScript everything stays open). */
function render_admin_folder_nav($files, $currentFolder) {
    $folderPaths = all_folder_paths($files);
    if (empty($folderPaths)) return '';
    [$own, $total] = admin_folder_counts($files);
    $children = [];
    foreach ($folderPaths as $path) {
        $parent = strpos($path, '/') === false ? '' : substr($path, 0, strrpos($path, '/'));
        $children[$parent][] = $path;
    }
    $link = function ($path, $label, $href) use ($own, $total, $currentFolder) {
        $current = ($path === '' ? $currentFolder === null : $currentFolder === $path);
        $here = $own[$path] ?? 0; $all = $total[$path] ?? 0;
        $title = ($path === '' ? 'Root' : $path) . ': ' . $here . ' here' . ($all !== $here ? ', ' . $all . ' including subfolders' : '');
        return '<a href="' . $href . '"' . ($current ? ' class="current" aria-current="page"' : '') . ' title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '">'
             . '<span class="fname">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span><span class="fcount">' . $here . '</span></a>';
    };
    $branch = function ($parent) use (&$branch, $children, $link) {
        $out = '';
        foreach ($children[$parent] ?? [] as $path) {
            $kids = !empty($children[$path]);
            $out .= '<li' . ($kids ? ' class="has-children"' : '') . '>'
                  . ($kids ? '<button type="button" class="ftoggle" aria-expanded="true" aria-label="Show or hide the folders inside ' . htmlspecialchars(basename($path), ENT_QUOTES, 'UTF-8') . '"></button>' : '')
                  . $link($path, basename($path), '?admin&amp;folder=' . htmlspecialchars(rawurlencode($path), ENT_QUOTES, 'UTF-8'));
            if ($kids) $out .= '<ul>' . $branch($path) . '</ul>';
            $out .= '</li>';
        }
        return $out;
    };
    return '<nav class="admin-folder-nav" aria-label="Folders"><ul class="ftree"><li>' . $link('', 'Root', '?admin') . '</li>' . $branch('') . '</ul></nav>';
}

/** The notes reference: everything about writing a notes file, in full, beside
 *  the editors rather than folded away. The Insert buttons put a directive at
 *  the cursor of the note you last clicked in (plain text insertion, no
 *  different from typing it, so it never touches the byte-for-byte save
 *  guarantee of the textarea itself). */
function render_admin_notes_reference() {
    $e = function ($t) { return htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); };
    $c = function ($t) use ($e) { return '<code>' . $e($t) . '</code>'; };
    $ins = function ($label, $snippet) use ($e) { return '<button type="button" class="admin-insert-btn" data-snippet="' . $e($snippet) . '">' . $e($label) . '</button>'; };
    $ex = function ($t) use ($e) { return '<pre class="ref-example">' . $e($t) . '</pre>'; };
    ob_start();
?>
    <h2 class="section-title">How notes work</h2>
    <nav class="ref-tabs" role="tablist" aria-label="Notes reference sections">
      <button type="button" role="tab" data-ref="start" class="active">Start here</button>
      <button type="button" role="tab" data-ref="format">Formatting</button>
      <button type="button" role="tab" data-ref="directives">Directives</button>
      <button type="button" role="tab" data-ref="photos">Photos</button>
      <button type="button" role="tab" data-ref="folders">Folders</button>
      <button type="button" role="tab" data-ref="fix">Fixing</button>
    </nav>

    <section class="ref-panel" data-ref="start">
      <h3>Start here</h3>
      <p>A notes file is a short box of text at the top of a page. Add <strong>directives</strong> in {curly braces} to attach photos, mark things sold, or colour a box. A complete small example:</p>
      <?= $ex("{gallery}\n# Summer trip\n\nOur week in Lisbon. {color: gold}\n\nHand-built keyboard, 65%. {group: Keyboard} {photos: kb1.jpg, kb2.jpg}\n\nThis one has gone. {sold}") ?>
      <ol class="ref-steps">
        <li>The <strong>first line must be exactly</strong> <?= $c('{gallery}') ?>. Any other <code>.md</code> file is left completely alone (a personal notes file is never read). A file without it is listed as "not active", with a one-click button to add the line.</li>
        <li>A <?= $c('# Heading') ?> on the next line becomes the <strong>page title</strong> (in the gallery's first notes file) and isn't repeated in the box. A <strong>Gallery title</strong> set in Settings wins over it.</li>
        <li>A paragraph can carry directives. A directive only ever affects <strong>its own paragraph</strong>, and visitors never see it.</li>
        <li>A notes file inside a folder shows on <strong>that folder's page</strong> and works the same way there.</li>
      </ol>
      <p class="hint">Not sure where to look? <button type="button" class="linklike" data-goto="directives">What can I put in {braces}?</button> <button type="button" class="linklike" data-goto="photos">How do I attach photos?</button> <button type="button" class="linklike" data-goto="fix">Something isn't working.</button></p>
    </section>

    <section class="ref-panel" data-ref="format">
      <h3>Formatting</h3>
      <table class="ref-table">
        <tr><td><?= $c('# Heading') ?><br><?= $c('## Smaller') ?> <?= $c('### Smaller still') ?></td><td>Headings</td></tr>
        <tr><td><?= $c('**bold**') ?> <?= $c('*italic*') ?> <?= $c('_italic_') ?><br><?= $c('***both***') ?> <?= $c('__underline__') ?><br><?= $c('~~strikethrough~~') ?></td><td>Text styles</td></tr>
        <tr><td><?= $c('`code`') ?></td><td>Inline code, shown as typed (this is also how you write a directive without it doing anything: <?= $c('`{sold}`') ?> shows the word and marks nothing)</td></tr>
        <tr><td><?= $c('[words](https://example.com)') ?></td><td>A link. Only <code>http://</code> and <code>https://</code> addresses become links.</td></tr>
        <tr><td><?= $c('- an item') ?> <?= $c('* an item') ?><br><?= $c('1. a numbered item') ?></td><td>Bulleted and numbered lists</td></tr>
        <tr><td><?= $c('> a quote') ?></td><td>A block quote</td></tr>
        <tr><td><?= $c('```') ?> on its own line, before and after</td><td>A block of code, shown exactly as typed</td></tr>
      </table>
      <p class="hint">HTML you type is never run: it appears as plain text.</p>
    </section>

    <section class="ref-panel" data-ref="directives">
      <h3>Directives</h3>
      <p>Write them anywhere in a paragraph. Press a button to put one at the cursor of the note you last clicked in.</p>
      <div class="ref-cards">
        <div class="ref-card"><div class="ref-card-head"><?= $ins('Group a box', '{group: Name}') ?> <?= $c('{group: Name}') ?></div>
          <p>Draws a labelled <strong>box</strong> around this paragraph's photos, with its own view count. On its own it renames a box the gallery already made (a folder, or <code>alpha_01.jpg</code> and <code>alpha_02.jpg</code> grouped by name).</p></div>
        <div class="ref-card"><div class="ref-card-head"><?= $ins('Claim photos', '{photos: a.jpg, b.jpg}') ?> <?= $c('{photos: a.jpg, b.jpg}') ?></div>
          <p>Attaches exactly these files to this paragraph, <strong>whatever they're called</strong>. Separate names with commas, spelled as in the folder. A file belongs to one paragraph only: the first claim wins.</p></div>
        <div class="ref-card"><div class="ref-card-head"><?= $ins('Accent color', '{color: #ff0000}') ?> <?= $c('{color: gold}') ?> or <?= $c('{color: #5865f2}') ?></div>
          <p>The accent colour of that item's box: a hex code or a plain colour word. The paragraph's own text gets a bar in the same colour, so the two read as one item; with no photo or folder to colour, the bar is all it does.</p></div>
        <div class="ref-card"><div class="ref-card-head"><?= $ins('Sold', '{sold}') ?> <?= $c('{sold}') ?></div>
          <p>Marks the item <strong>sold</strong>: dimmed, struck-through text, grayscale photos and a red SOLD ribbon.</p></div>
        <div class="ref-card"><div class="ref-card-head"><?= $ins('Reserved', '{reserved}') ?> <?= $c('{reserved}') ?> or <?= $c('{held}') ?></div>
          <p>Marks it <strong>reserved</strong>: an orange tag; the photos stay in colour.</p></div>
        <div class="ref-card"><div class="ref-card-head"><?= $c('{left}') ?> <?= $c('{center}') ?> <?= $c('{right}') ?></div>
          <p>Aligns that paragraph's text (or a heading's, written at the end of its line). Notes are left-aligned; a folder's or the gallery's description box is centred unless you say otherwise.</p></div>
      </div>
      <p class="hint insert-hint" hidden>Click inside the note you want to add it to first, then press the button.</p>
      <p class="hint">If an item has both, sold wins over reserved, and either one overrides a custom colour, so the signal is never ambiguous.</p>
    </section>

    <section class="ref-panel" data-ref="photos">
      <h3>Attaching photos to a paragraph</h3>
      <p>Three ways, from least to most typing:</p>
      <ol class="ref-steps">
        <li><strong>Just mention it.</strong> If a paragraph contains a photo's name as a whole word (not case-sensitive), its photos appear under it. With filename grouping off the name is the file's name without its extension (<code>zebra.jpg</code> is "zebra"); with it on, a trailing <code>_01</code> or <code>-02</code> is dropped, so <code>alpha_01.jpg</code> and <code>alpha_02.jpg</code> are both "alpha".</li>
        <li><strong>List them</strong>, which works with any filenames:
          <?= $ex("The blue one is my favourite. {photos: IMG_0231.jpg, IMG_0232.jpg}") ?></li>
        <li><strong>List them and give the item a box:</strong>
          <?= $ex("Hand-built keyboard, 65%. {group: Keyboard} {photos: kb1.jpg, kb2.jpg} {color: gold}") ?></li>
      </ol>
      <p>A photo you list always beats the automatic matching, and is taken out of it.</p>
      <h3>Where a directive applies</h3>
      <p>A directive only ever affects <strong>its own paragraph</strong>, never the one above or below, even with nothing in between. A paragraph ends at a <strong>blank line</strong> (the usual cause of surprises), a heading, a quote or a list item (each stands alone), a code block, and a line starting <?= $c('(1)') ?>, <?= $c('(2)') ?> and so on, which starts a new paragraph straight away so a numbered listing needs no blank lines.</p>
      <p>So this does <em>not</em> work as hoped, because the blank line makes two paragraphs:</p>
      <?= $ex("Take a look at my rack\n\n{group: Rack} {photos: rack.jpg, rack2.jpg}") ?>
      <p>Remove the blank line to make it one item:</p>
      <?= $ex("Take a look at my rack. {group: Rack} {photos: rack.jpg, rack2.jpg}") ?>
    </section>

    <section class="ref-panel" data-ref="folders">
      <h3>Notes and folders</h3>
      <p>Notes work the same at the top and inside every folder: a notes file affects the page it sits on, and can use every directive on the photos <strong>directly in its own folder</strong>.</p>
      <table class="ref-table">
        <tr><td>Mention a folder's name</td><td>Write a subfolder's name as a whole word in a paragraph. A <em>cover card</em> takes that paragraph's <?= $c('{sold}') ?>, <?= $c('{reserved}') ?> and <?= $c('{color:}') ?>. With <em>Inline</em> folders its photos also appear under the paragraph.</td></tr>
        <tr><td><?= $c('{group: Name}') ?> on a folder's paragraph</td><td>Renames that folder's card or box on the page you're on. To rename it everywhere, use the folder's <strong>Display name</strong> in its settings.</td></tr>
        <tr><td><?= $c('{photos: a.jpg}') ?></td><td>Reaches photos <strong>directly in the folder the notes are in</strong>. A photo inside a subfolder can't be claimed from above: write the subfolder's name instead, or put the notes in that subfolder.</td></tr>
        <tr><td>Mention a photo by name</td><td><?= $c('eiffel') ?> pulls <code>eiffel.jpg</code> up to the paragraph, in any folder's notes.</td></tr>
        <tr><td>The photo viewer</td><td>The arrows stay inside one folder, so a trip's days don't run into each other.</td></tr>
      </table>
    </section>

    <section class="ref-panel" data-ref="fix">
      <h3>If something doesn't work</h3>
      <p>The page shows a small, collapsed <strong>setup notices</strong> banner when something in a notes file didn't resolve:</p>
      <ul>
        <li>a filename in <?= $c('{photos:}') ?> that isn't in the folder, or is inside a subfolder,</li>
        <li>a file claimed twice, or a <?= $c('{group:}') ?> name used twice,</li>
        <li>a directive cut off from its text by a blank line,</li>
        <li>a <code>.md</code> file missing <?= $c('{gallery}') ?>.</li>
      </ul>
      <h3>Showing a directive as text</h3>
      <p>To write about a directive without it doing anything, put it in backticks: <?= $c('`{sold}`') ?> shows the word <code>{sold}</code> and marks nothing. Everything on this page is written that way.</p>
      <p class="hint">The complete reference, with a table of every combination of <code>{group:}</code> and <code>{photos:}</code>, is in the README under "Directive syntax".</p>
    </section>
<?php
    return ob_get_clean();
}

/**
 * Processes exactly one admin POST action. Every action is CSRF-checked
 * first; a save can touch at most one concern (an item, a group label, a
 * folder setting, or one .md file) so a failure in one never silently
 * implies success in another — reported per-action, not assumed
 * all-or-nothing.
 */
/** A short fingerprint of one record as it was when an admin form was
 *  rendered, posted back as `loaded_hash` and compared against the record
 *  as it is at save time, under the same lock. If it differs, someone
 *  else (a second tab, a second device) changed that exact record in the
 *  meantime — warn instead of silently overwriting. Per-record, not
 *  per-file: two sessions editing *different* photos in one folder never
 *  collide. A save with no loaded_hash at all (an older client) skips the
 *  check rather than failing. */
function admin_record_hash($record) {
    return md5(json_encode($record));
}

/** The fingerprint of a photo's own record for the stale-edit check. Its privacy and location-visibility switches save
 *  by themselves, so they are left out: flipping one must not make the photo's caption form look out of date. */
function admin_item_record_hash($entry) {
    unset($entry['restriction'], $entry['locationVisibility']);
    return admin_record_hash($entry);
}

/** The folder-level fields the "This folder's own settings" form edits,
 *  normalized so the render-time and save-time fingerprints match. */
function folder_identity_record($data) {
    return [
        'displayName' => $data['displayName'] ?? null,
        'description' => $data['description'] ?? null,
        'dates' => $data['dates'] ?? null,
        'sortOrder' => $data['sortOrder'] ?? null,
        'photoSort' => $data['photoSort'] ?? null,
    ];
}

/** A function, not a const: the admin route runs before execution ever
 *  reaches this point in the file, and only functions are hoisted. */
function admin_stale_message() {
    return ' was changed somewhere else (another tab or device) since you opened this page, so nothing was saved. Reload to see the current values, then make your change again.';
}

/** Plain single-line-ish text from a form field: control characters
 *  dropped, whitespace runs collapsed, length capped. Output is always
 *  HTML-escaped where it's shown, so this is about tidiness and bounding
 *  what a .gallery-items.php can grow to, not about escaping. */
/** Like admin_clean_text() but keeps line breaks and blank lines (a folder's description is a little Markdown, so
 *  paragraphs and lists must survive): carriage returns and control characters dropped, runs of blank lines
 *  collapsed to one, trailing spaces trimmed, length capped. */
function admin_clean_description($value, $maxLen) {
    $text = str_replace(["\r\n", "\r"], "\n", (string) $value);
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
    $text = preg_replace('/\t/', ' ', (string) $text);
    $text = preg_replace('/[ ]+\n/', "\n", (string) $text);
    $text = trim(preg_replace('/\n{3,}/', "\n\n", (string) $text));
    return function_exists('mb_substr') ? mb_substr($text, 0, $maxLen, 'UTF-8') : substr($text, 0, $maxLen);
}

/** A folder's (or the gallery's) description as HTML: the same small Markdown as the notes (bold, italic, links, lists,
 *  headings), centred by default; {left}, {center} or {right} in a paragraph sets that paragraph's alignment. */
function render_description_html($text) {
    $colors = []; $status = []; $labels = []; $claims = [];
    return markdown_to_html(protect_inline_code((string) $text), [], [], $colors, $status, $labels, $claims, []);
}

function admin_clean_text($value, $maxLen) {
    $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $value);
    $text = trim(preg_replace('/\s+/u', ' ', (string) $text));
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $maxLen, 'UTF-8');
    }
    return substr($text, 0, $maxLen);
}

/** The admin password rule: at least 8 characters, with a letter (either case) and a number. It is the one credential
 *  that can change the gallery. (The private-view password may be any length: it only unlocks looking.) */
function admin_password_ok($pw) {
    return strlen($pw) >= 8 && preg_match('/[A-Za-z]/', $pw) && preg_match('/\d/', $pw);
}

/** Answers a request made by the admin page's own script (a switch saved in
 *  place) with JSON, and ends the request. */
function admin_json_exit($payload) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

/** One photo's own record, as the editor in the viewer shows it: caption, link, place, the two switches, whether it is the
 *  folder's cover, and the fingerprint a later save is checked against. A place read from the photo's GPS but not saved yet
 *  comes back as `auto` (not as `lat`/`lng`), so opening the editor never turns it into a saved location by itself. */
function admin_item_json($dir, $files) {
    header('Cache-Control: no-store');
    $file = (string) $_GET['item'];
    if ($file === '' || strpos($file, "\0") !== false || !in_array($file, $files, true)) {
        http_response_code(404);
        admin_json_exit(['ok' => false, 'text' => 'Unknown file.']);
    }
    $real = containing_folder_real_path($dir, $file);
    $bare = basename($file);
    $data = items_read($real);
    $entry = $data['items'][$bare] ?? [];
    $rest = item_restriction($entry);
    $folderPrivate = folder_above_is_private($dir, $file);
    $vis = $entry['locationVisibility'] ?? 'inherit';
    $loc = $entry['location'] ?? null;
    $auto = null;
    if ($loc === null && empty($entry['gpsChecked']) && ENABLE_PHOTO_LOCATION && !is_video_file($file)) {
        $auto = extract_photo_gps(resolve_real_path($dir, $file));
    }
    $cover = $data['cover'] ?? null;
    admin_json_exit([
        'ok' => true,
        'file' => $file,
        'caption' => (string) ($entry['caption'] ?? ''),
        'web' => (string) ($entry['web'] ?? ''),
        'lat' => $loc !== null ? (string) $loc['lat'] : '',
        'lng' => $loc !== null ? (string) $loc['lng'] : '',
        'label' => $loc !== null ? (string) ($loc['label'] ?? '') : '',
        'auto' => $auto !== null ? ['lat' => (string) $auto['lat'], 'lng' => (string) $auto['lng']] : null,
        'hash' => admin_item_record_hash($entry),
        'private' => $rest === 'private' || ($rest === 'inherit' && $folderPrivate),
        'own' => $rest === 'inherit' ? '' : $rest,   // the photo's own setting: "private", "public", or "" (it follows its folder)
        'folderPrivate' => $folderPrivate,
        'locationShown' => $vis === 'show' || ($vis === 'inherit' && !empty($data['locationEnabled'])),
        'folderLocationOn' => !empty($data['locationEnabled']),
        'locationsOff' => !ENABLE_PHOTO_LOCATION,
        'cover' => is_string($cover) && $cover !== '' && $cover === $bare,
        'views' => TRACK_VIEWS ? (int) (stats_get_all_photo_views($dir, [$file])[$file] ?? 0) : null,
    ]);
}

function handle_admin_post($dir, $adminFolderPath, $adminFolderReal, $files, $groups, $explicitGroups) {
    // Normalize every posted value once, up front: a field sent as an
    // array (name[]=x) is dropped to '' rather than reaching code that
    // expects a string, and NUL bytes — which make file functions throw —
    // are removed. No admin field is ever legitimately either.
    foreach ($_POST as $postKey => $postValue) {
        $_POST[$postKey] = is_string($postValue) ? str_replace("\0", '', $postValue) : '';
    }
    $isFetch = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'gallery-fetch';
    if (!admin_csrf_valid($_POST['csrf_token'] ?? '')) {
        if ($isFetch) admin_json_exit(['ok' => false, 'text' => 'Your session expired — reload the page and sign in again.']);
        return ['type' => 'error', 'text' => 'Your session expired — please try again.'];
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'logout') {
        return ['type' => 'logout'];
    }

    if ($action === 'save_site_settings') {
        $result = site_settings_save($isFetch);
        if ($isFetch) {
            admin_json_exit(['ok' => ($result['type'] ?? '') === 'ok', 'text' => (string) ($result['text'] ?? ''), 'hash' => $result['hash'] ?? null, 'manual' => $result['manual'] ?? null, 'unchanged' => !empty($result['unchanged']), 'extra' => $result['extra'] ?? null]);
        }
        return $result;
    }

    // Makes the tile pictures ahead of time, a few seconds' worth per request; the page asks again until it is done.
    if ($action === 'warm_thumbnails') {
        if (!ENABLE_THUMBNAIL_CACHE) admin_json_exit(['ok' => false, 'text' => 'Thumbnails are switched off in the site settings.']);
        @set_time_limit(60);
        session_write_close();   // the page runs a few of these side by side: none may wait for another's hold on the session
        $r = warm_thumbnail_batch($dir, $files, (int) ($_POST['from'] ?? 0), 1.0, min(8, max(1, (int) ($_POST['step'] ?? 1))));
        $r['ok'] = true; $r['text'] = '';
        admin_json_exit($r);
    }

    if ($action === 'save_item') {
        $bare = basename((string) ($_POST['file'] ?? ''));
        $fullPath = $adminFolderPath === null ? $bare : $adminFolderPath . '/' . $bare;
        if (!in_array($fullPath, $files, true)) {
            return ['type' => 'error', 'text' => 'Unknown file.'];
        }
        $caption = trim((string) ($_POST['caption'] ?? ''));
        $web = trim((string) ($_POST['web'] ?? ''));
        $lat = trim((string) ($_POST['lat'] ?? ''));
        $lng = trim((string) ($_POST['lng'] ?? ''));
        $locLabel = trim((string) ($_POST['loc_label'] ?? ''));
        // The privacy and location-visibility switches save themselves (set_item_flag); a form that does post them (an
        // older page, a script) still sets them, and one that doesn't leaves them as they are.
        $visibility = isset($_POST['loc_visibility']) ? (string) $_POST['loc_visibility'] : null;
        if ($visibility !== null && !in_array($visibility, ['inherit', 'show', 'hide'], true)) $visibility = 'inherit';
        $restriction = isset($_POST['restriction']) ? (string) $_POST['restriction'] : null;
        if ($restriction !== null && !in_array($restriction, ['inherit', 'public', 'private'], true)) $restriction = 'inherit';
        if ($web !== '' && !preg_match('#^https?://#i', $web)) {
            return ['type' => 'error', 'text' => 'The web link needs to start with http:// or https://.'];
        }
        if (($lat !== '' || $lng !== '') && !is_valid_coordinate($lat === '' ? 0 : $lat, $lng === '' ? 0 : $lng)) {
            return ['type' => 'error', 'text' => 'That latitude/longitude isn\'t a valid coordinate.'];
        }
        $loadedHash = isset($_POST['loaded_hash']) ? (string) $_POST['loaded_hash'] : null;
        // The editor in the viewer saves caption and link as they are typed: with keep_loc it leaves the place alone (a place
        // read from the photo's GPS stays "not saved yet" until the owner chooses to keep it).
        $keepLoc = ($_POST['keep_loc'] ?? '') === '1';
        $stale = false; $newHash = null;
        items_update($adminFolderReal, function (&$data) use ($bare, $caption, $web, $lat, $lng, $locLabel, $visibility, $restriction, $loadedHash, $keepLoc, &$stale, &$newHash) {
            $entry = $data['items'][$bare] ?? [];
            if ($loadedHash !== null && !hash_equals(admin_item_record_hash($entry), $loadedHash)) {
                $stale = true;
                return;
            }
            if ($caption !== '') { $entry['caption'] = $caption; } else { unset($entry['caption']); }
            if ($web !== '') { $entry['web'] = $web; } else { unset($entry['web']); }
            if (!$keepLoc) {
                if ($lat !== '' && $lng !== '') {
                    $entry['location'] = ['lat' => (float) $lat, 'lng' => (float) $lng, 'label' => $locLabel];
                } else {
                    unset($entry['location']);
                }
                // Saved by the owner, so whatever location it has (or deliberately
                // doesn't) stands: never auto-import GPS over it later.
                $entry['gpsChecked'] = true;
            }
            if ($visibility !== null) $entry['locationVisibility'] = $visibility;
            if ($restriction === 'inherit') { unset($entry['restriction']); } elseif ($restriction !== null) { $entry['restriction'] = $restriction; }
            $data['items'][$bare] = $entry;
            $newHash = admin_item_record_hash($entry);
        });
        if ($stale) {
            if ($isFetch) admin_json_exit(['ok' => false, 'stale' => true, 'text' => '"' . $bare . '"' . admin_stale_message()]);
            return ['type' => 'error', 'text' => '"' . $bare . '"' . admin_stale_message()];
        }
        if ($isFetch) {
            if (!empty($GLOBALS['__data_write_failed'])) admin_json_exit(['ok' => false, 'text' => 'Nothing was saved: this folder isn\'t writable by the web server.']);
            $saved = items_read($adminFolderReal)['items'][$bare] ?? [];
            admin_json_exit(['ok' => true, 'text' => 'Saved "' . $bare . '".', 'hash' => $newHash, 'caption' => (string) ($saved['caption'] ?? ''), 'web' => (string) ($saved['web'] ?? ''),
                'lat' => isset($saved['location']) ? (string) $saved['location']['lat'] : '', 'lng' => isset($saved['location']) ? (string) $saved['location']['lng'] : '', 'label' => isset($saved['location']) ? (string) ($saved['location']['label'] ?? '') : '']);
        }
        return ['type' => 'ok', 'text' => 'Saved "' . $bare . '".'];
    }

    // Making photos private (or public again), and showing or not showing their location, one switch or many at a time.
    // What is posted is the state wanted ("on"). Switching privacy ON, or a location OFF, is always stored on the photo itself,
    // so the protection survives the folder's own setting changing later; switching the other way is stored only when the
    // photo has to differ from what its folder gives it (otherwise it simply follows the folder again).
    if ($action === 'set_item_flag' || $action === 'bulk_item_flag') {
        $flag = (string) ($_POST['flag'] ?? '');
        if (!in_array($flag, ['private', 'location'], true)) return ['type' => 'error', 'text' => 'Unknown setting.'];
        $on = ($_POST['on'] ?? '') === '1';
        // Privacy can also be asked for as a state: "private" (kept private on the photo itself), "public" (public even inside a private
        // folder) or "inherit" (no setting of its own: it follows its folder). Without it, "on" means private / back to public as before.
        $state = null;
        if ($flag === 'private' && isset($_POST['state'])) {
            $state = (string) $_POST['state'];
            if (!in_array($state, ['private', 'public', 'inherit'], true)) return ['type' => 'error', 'text' => 'Unknown setting.'];
            $on = $state === 'private';
        }
        if ($action === 'set_item_flag') {
            $names = [basename((string) ($_POST['file'] ?? ''))];
        } else {
            $decoded = json_decode((string) ($_POST['files'] ?? ''), true);
            if (!is_array($decoded) || !array_is_list($decoded) || !$decoded) return ['type' => 'error', 'text' => 'Choose at least one photo first.'];
            if (count($decoded) > 2000) return ['type' => 'error', 'text' => 'That is more photos at once than the gallery allows (2000).'];
            $names = array_values(array_unique(array_map(function ($f) { return basename((string) $f); }, $decoded)));
        }
        foreach ($names as $bare) {
            $fullPath = $adminFolderPath === null ? $bare : $adminFolderPath . '/' . $bare;
            if ($bare === '' || !in_array($fullPath, $files, true)) return ['type' => 'error', 'text' => 'Unknown file: nothing was changed.'];
        }
        $changed = 0;
        items_update($adminFolderReal, function (&$data) use ($names, $flag, $on, $state, $dir, $adminFolderPath, &$changed) {
            $folderLocationOn = !empty($data['locationEnabled']);
            foreach ($names as $bare) {
                $fullPath = $adminFolderPath === null ? $bare : $adminFolderPath . '/' . $bare;
                $entry = $data['items'][$bare] ?? [];
                $before = $entry;
                if ($flag === 'private' && $state !== null) {
                    if ($state === 'inherit') { unset($entry['restriction']); } else { $entry['restriction'] = $state; }
                } elseif ($flag === 'private') {
                    if ($on) { $entry['restriction'] = 'private'; }
                    elseif (folder_above_is_private($dir, $fullPath)) { $entry['restriction'] = 'public'; }
                    else { unset($entry['restriction']); }
                } else {
                    if (!$on) { $entry['locationVisibility'] = 'hide'; }
                    elseif ($folderLocationOn) { unset($entry['locationVisibility']); }
                    else { $entry['locationVisibility'] = 'show'; }
                }
                if ($entry !== $before) { $data['items'][$bare] = $entry; $changed++; }
            }
        });
        $n = count($names);
        $what = $flag === 'private' ? ($state === 'inherit' ? (count($names) === 1 ? 'following its folder' : 'following their folder') : ($on ? 'private' : 'public')) : ($on ? 'showing its location' : 'not showing its location');
        $text = $n === 1
            ? ($flag === 'private' ? '"' . $names[0] . '" is now ' . $what . '.' : '"' . $names[0] . '" is now ' . $what . '.')
            : $n . ' photos are now ' . $what . '.';
        if ($flag === 'private' && $on) $text .= ' Visitors can\'t see ' . ($n === 1 ? 'it' : 'them') . ' unless they have the private-view password.';
        return ['type' => 'ok', 'text' => $text];
    }

    if ($action === 'save_group_label') {
        $groupKey = (string) ($_POST['group_key'] ?? '');
        if (!isset($groups[$groupKey])) {
            return ['type' => 'error', 'text' => 'Unknown group.'];
        }
        if (isset($explicitGroups[$groupKey])) {
            return ['type' => 'error', 'text' => 'That name is controlled by a {group:} directive and can\'t be changed here.'];
        }
        $displayName = trim((string) ($_POST['display_name'] ?? ''));
        items_update($dir, function (&$data) use ($groupKey, $displayName) {
            if ($displayName === '') {
                unset($data['groups'][$groupKey]);
            } else {
                $data['groups'][$groupKey] = ['displayName' => $displayName];
            }
        });
        return ['type' => 'ok', 'text' => 'Display name saved.'];
    }

    if ($action === 'save_folder_identity') {
        // Only fields actually present in the POST are touched, so a form
        // (or an older client) that only sends one field never wipes the others
        // (the page saves each field as it is changed).
        $updates = [];
        if (array_key_exists('display_name', $_POST)) {
            $displayName = trim((string) $_POST['display_name']);
            $updates['displayName'] = $displayName !== '' ? $displayName : null;
        }
        if (array_key_exists('description', $_POST)) {
            $text = admin_clean_description($_POST['description'], 1000);
            $updates['description'] = $text !== '' ? $text : null;
        }
        if (array_key_exists('dates', $_POST)) {
            $text = admin_clean_text($_POST['dates'], 80);
            $updates['dates'] = $text !== '' ? $text : null;
        }
        if ($adminFolderPath !== null && array_key_exists('sort_order', $_POST)) {   // no position at the root
            $raw = trim((string) $_POST['sort_order']);
            if ($raw === '') {
                $updates['sortOrder'] = null;
            } elseif (preg_match('/^-?\d{1,5}$/', $raw)) {
                $updates['sortOrder'] = (int) $raw;
            } else {
                return ['type' => 'error', 'text' => 'Position must be a whole number (or blank for natural order).'];
            }
        }
        if (array_key_exists('photo_sort_order', $_POST)) {   // this folder's own order of photos: "" = the site's
            $own = [];
            $order = (string) $_POST['photo_sort_order'];
            if (in_array($order, ['name', 'taken', 'modified'], true)) $own['order'] = $order;
            $dirChoice = (string) ($_POST['photo_sort_dir'] ?? '');
            if ($dirChoice === 'asc' || $dirChoice === 'desc') $own['desc'] = $dirChoice === 'desc';
            if (array_key_exists('photo_sort_first', $_POST)) {   // the list form: "" = follow the site's, "own" = this folder's own list
                if ((string) $_POST['photo_sort_first'] === 'own') {
                    foreach (['private', 'video', 'located'] as $kind) $own[$kind] = ((string) ($_POST['photo_sort_' . $kind] ?? 'off')) === 'on';
                    $kinds = array_map('trim', explode(',', (string) ($_POST['photo_sort_priority'] ?? '')));
                    $want = ['private', 'video', 'located']; $sortedKinds = $kinds; sort($sortedKinds); sort($want);
                    if ($sortedKinds === $want) $own['priority'] = $kinds;
                    // A list that would sort exactly as the site's does is not a setting of this folder's own (so it keeps following the site).
                    if (isset($own['priority'])) {
                        $namesMap = sort_priority_names();
                        $ownEff = array_values(array_filter($own['priority'], function ($k) use ($own) { return !empty($own[$k]); }));
                        $siteEff = array_values(array_filter(sort_priority_list(), function ($k) use ($namesMap) { return (bool) constant($namesMap[$k][2]); }));
                        if ($ownEff === $siteEff) foreach (['private', 'video', 'located', 'priority'] as $drop) unset($own[$drop]);
                    }
                }
            } else {
                foreach (['private', 'video', 'located'] as $kind) {
                    $choice = (string) ($_POST['photo_sort_' . $kind] ?? '');
                    if ($choice === 'on' || $choice === 'off') $own[$kind] = $choice === 'on';
                }
            }
            $updates['photoSort'] = $own !== [] ? $own : null;
        }
        $loadedHash = isset($_POST['loaded_hash']) ? (string) $_POST['loaded_hash'] : null;
        $stale = false; $newHash = null;
        items_update($adminFolderReal, function (&$data) use ($updates, $loadedHash, &$stale, &$newHash) {
            if ($loadedHash !== null && !hash_equals(admin_record_hash(folder_identity_record($data)), $loadedHash)) {
                $stale = true;
                return;
            }
            foreach ($updates as $k => $v) {
                $data[$k] = $v;
            }
            $newHash = admin_record_hash(folder_identity_record($data));
        });
        if ($stale) {
            return ['type' => 'error', 'text' => ($adminFolderPath === null ? 'The gallery\'s settings' : 'This folder\'s settings') . admin_stale_message(), 'stale' => true];
        }
        return ['type' => 'ok', 'text' => $adminFolderPath === null ? 'Gallery settings saved.' : 'Folder settings saved.', 'hash' => $newHash];
    }

    if ($action === 'toggle_folder_restriction') {
        if ($adminFolderPath === null) {
            return ['type' => 'error', 'text' => 'Not applicable at the root — make individual items private instead.'];
        }
        $hidden = !empty($_POST['hidden']);
        items_update($adminFolderReal, function (&$data) use ($hidden) {
            if ($hidden) { $data['restriction'] = 'private'; } else { unset($data['restriction']); }
        });
        return ['type' => 'ok', 'text' => 'This entire folder is now ' . ($hidden ? 'private: visitors need the private-view password to see it.' : 'public again.'),
                'counts' => folder_privacy_counts($dir, $files, $adminFolderPath)];
    }

    if ($action === 'arrange_items') {
        // Placing photos by hand: they go next to another photo of the folder and stay with it whatever the sort (see media_stacks_change).
        $mode = (string) ($_POST['mode'] ?? '');
        if (!in_array($mode, ['place', 'release', 'reset'], true)) return ['type' => 'error', 'text' => 'Unknown request.'];
        $names = []; $target = ''; $side = 'after';
        if ($mode !== 'reset') {
            $decoded = json_decode((string) ($_POST['files'] ?? ''), true);
            if (!is_array($decoded) || !array_is_list($decoded) || !$decoded) return ['type' => 'error', 'text' => 'Choose at least one photo first.'];
            if (count($decoded) > 2000) return ['type' => 'error', 'text' => 'That is more photos at once than the gallery allows (2000).'];
            $names = array_values(array_unique(array_map(function ($f) { return basename((string) $f); }, $decoded)));
            foreach ($names as $bare) {
                if ($bare === '' || !in_array($adminFolderPath === null ? $bare : $adminFolderPath . '/' . $bare, $files, true)) return ['type' => 'error', 'text' => 'Unknown file: nothing was changed.'];
            }
        }
        if ($mode === 'place') {
            $target = basename((string) ($_POST['target'] ?? ''));
            $side = (string) ($_POST['side'] ?? '') === 'before' ? 'before' : 'after';
            if ($target === '' || !in_array($adminFolderPath === null ? $target : $adminFolderPath . '/' . $target, $files, true)) return ['type' => 'error', 'text' => 'Unknown file: nothing was changed.'];
            if (in_array($target, $names, true)) return ['type' => 'error', 'text' => 'A photo can\'t be placed next to itself.'];
        }
        items_update($adminFolderReal, function (&$data) use ($mode, $names, $target, $side) { media_stacks_change($data, $mode, $names, $target, $side); });
        $n = count($names);
        if ($mode === 'reset') return ['type' => 'ok', 'text' => 'Every photo in this folder is back in its sorted place.'];
        if ($mode === 'release') return ['type' => 'ok', 'text' => ($n === 1 ? '"' . $names[0] . '" is' : $n . ' photos are') . ' back in the sorted place.'];
        return ['type' => 'ok', 'text' => ($n === 1 ? '"' . $names[0] . '" now sits' : $n . ' photos now sit') . ' ' . $side . ' "' . $target . '".'];
    }

    if ($action === 'set_cover') {
        $bare = basename((string) ($_POST['file'] ?? ''));
        if (!in_array($adminFolderPath === null ? $bare : $adminFolderPath . '/' . $bare, $files, true)) {
            return ['type' => 'error', 'text' => 'Unknown file.'];
        }
        items_update($adminFolderReal, function (&$data) use ($bare) {
            $data['cover'] = $bare;
        });
        return ['type' => 'ok', 'text' => '"' . $bare . '" set as ' . ($adminFolderPath === null ? 'the gallery\'s cover photo (the picture in shared-link previews).' : 'this folder\'s cover photo.')];
    }

    if ($action === 'save_map_settings') {
        $targetReal = $adminFolderPath === null ? $dir : $adminFolderReal;
        $updates = [];
        if (isset($_POST['map_enabled'])) $updates['mapEnabled'] = $_POST['map_enabled'] === '1';
        if (isset($_POST['map_radius'])) {
            $rawRadius = trim((string) $_POST['map_radius']);
            if ($rawRadius === '') {
                $updates['mapRadius'] = null;
            } elseif (is_numeric($rawRadius) && (float) $rawRadius >= 0 && (float) $rawRadius <= 10000) {
                $updates['mapRadius'] = (float) $rawRadius == (int) $rawRadius ? (int) $rawRadius : (float) $rawRadius;
            } else {
                return ['type' => 'error', 'text' => 'Pin grouping must be a number of miles from 0 (no grouping) to 10000, or blank to use the site-wide setting.'];
            }
        }
        if (!$updates) return ['type' => 'error', 'text' => 'Nothing to save.'];
        items_update($targetReal, function (&$data) use ($updates) {
            foreach ($updates as $k => $v) $data[$k] = $v;
        });
        $where = $adminFolderPath === null ? 'the gallery\'s front page' : 'this folder\'s page';
        $parts = [];
        if (array_key_exists('mapEnabled', $updates)) {
            if ($updates['mapEnabled']) {
                $part = 'A map now shows on ' . $where . ', from the photos whose location is visible.';
                if (!ENABLE_MAP) $part .= ' It stays hidden until the map is switched on in Site settings.';
                elseif (!ENABLE_PHOTO_LOCATION) $part .= ' It stays empty until photo locations are allowed in Site settings.';
            } else {
                $part = 'The map no longer shows on ' . $where . '. Nothing is deleted.';
            }
            $parts[] = $part;
        }
        if (array_key_exists('mapRadius', $updates)) {
            $parts[] = $updates['mapRadius'] === null ? 'This map uses the site-wide pin grouping.' : 'Photos within ' . $updates['mapRadius'] . ' mile' . ($updates['mapRadius'] == 1 ? '' : 's') . ' of each other share a pin.';
        }
        $text = 'Saved. ' . implode(' ', $parts);
        return ['type' => 'ok', 'text' => $text];
    }

    if ($action === 'toggle_location_enabled') {
        $enabled = !empty($_POST['enabled']);
        $targetReal = $adminFolderPath === null ? $dir : $adminFolderReal;
        items_update($targetReal, function (&$data) use ($enabled) {
            $data['locationEnabled'] = $enabled;
        });
        if (!$enabled) {
            return ['type' => 'ok', 'text' => 'Location sharing disabled for this folder. Saved coordinates are kept, just not shown.'];
        }
        $prefix = $adminFolderPath === null ? '' : $adminFolderPath . '/';
        $imported = import_folder_gps($dir, $targetReal, split_folder_level($files, $prefix)['loose']);
        $text = 'Location sharing enabled for this folder.';
        if (!ENABLE_PHOTO_LOCATION) {
            $text .= ' Nothing shows yet, because ENABLE_PHOTO_LOCATION is off in index.php.';
        } elseif ($imported > 0) {
            $text .= ' ' . $imported . ' photo' . ($imported === 1 ? ' has' : 's have') . ' GPS in the file and now show' . ($imported === 1 ? 's' : '') . ' a location to visitors and on the map. To keep one private, press its pencil and switch off Location (or clear its coordinates).';
        } else {
            $text .= ' None of its photos has GPS to show; add coordinates by hand on any photo.';
        }
        return ['type' => 'ok', 'text' => $text];
    }

    if ($action === 'add_gallery_marker') {
        $mdFile = basename((string) ($_POST['md_file'] ?? ''));
        $path = $adminFolderReal . '/' . $mdFile;
        if (strtolower(pathinfo($mdFile, PATHINFO_EXTENSION)) !== 'md' || !is_file($path)) {
            return ['type' => 'error', 'text' => 'Unknown file.'];
        }
        $content = (string) file_get_contents($path);
        if (has_gallery_marker($content)) {
            return ['type' => 'error', 'text' => 'Already active.'];
        }
        if (@file_put_contents($path, "{gallery}\n" . $content) === false) {
            return ['type' => 'error', 'text' => 'Could not write to "' . $mdFile . '".'];
        }
        return ['type' => 'ok', 'text' => '"' . $mdFile . '" is now active.'];
    }

    if ($action === 'save_notes') {
        $existing = basename((string) ($_POST['md_file'] ?? ''));
        $newName = trim((string) ($_POST['new_name'] ?? ''));
        $content = (string) ($_POST['content'] ?? '');
        $target = basename($newName !== '' ? $newName : $existing);
        if ($target === '') {
            return ['type' => 'error', 'text' => 'A filename is required.'];
        }
        if (strtolower(pathinfo($target, PATHINFO_EXTENSION)) !== 'md') {
            $target .= '.md';
        }
        $targetPath = $adminFolderReal . '/' . $target;
        if (@file_put_contents($targetPath, $content) === false) {
            return ['type' => 'error', 'text' => 'Could not write to "' . $target . '".'];
        }
        if (!has_gallery_marker($content)) {
            return ['type' => 'ok', 'text' => 'Saved to "' . $target . '", but it is not active: its first line is not {gallery}, so the gallery ignores it. Put {gallery} on the first line to switch it on.'];
        }
        return ['type' => 'ok', 'text' => 'Notes saved to "' . $target . '".'];
    }

    if ($action === 'delete_notes') {
        $mdFile = basename((string) ($_POST['md_file'] ?? ''));
        $path = $adminFolderReal . '/' . $mdFile;
        if (strtolower(pathinfo($mdFile, PATHINFO_EXTENSION)) !== 'md' || !is_file($path)) {
            return ['type' => 'error', 'text' => 'Unknown file.'];
        }
        if (!@unlink($path)) {
            return ['type' => 'error', 'text' => 'Could not delete "' . $mdFile . '".'];
        }
        return ['type' => 'ok', 'text' => '"' . $mdFile . '" deleted.'];
    }

    if ($action === 'export_tree') {
        return ['type' => 'export_tree'];
    }

    if ($action === 'export_items') {
        return ['type' => 'export'];
    }

    return ['type' => 'error', 'text' => 'Unknown action.'];
}

/** Streams the current folder's own .gallery-items.php back out as a
 *  download — the only backup this irreplaceable, hand-entered data has
 *  at all. One caution worth repeating next to the download itself, not
 *  a design change: once a hidden flag exists in this file (a later
 *  phase), the export becomes a new way to reveal what's hidden and that
 *  it exists — mishandling the exported file is its own leak vector,
 *  independent of the actual hidden content. */
/** Every folder's .gallery-items.php in one download, keyed by relative
 *  folder path ("" is the root) — same irreplaceable data, same leak
 *  caution as admin_export_items(), just the whole tree at once. */
function admin_export_tree($dir, $files) {
    $tree = ['' => items_read($dir)];
    foreach (all_folder_paths($files) as $path) {
        $data = items_read(resolve_real_path($dir, $path));
        if ($data !== items_default()) {
            $tree[$path] = $data;
        }
    }
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="whole-tree-gallery-items.json"');
    echo json_encode($tree, JSON_PRETTY_PRINT);
}

function admin_export_items($adminFolderPath, $adminFolderReal) {
    $data = items_read($adminFolderReal);
    $name = ($adminFolderPath === null ? 'root' : slugify($adminFolderPath)) . '-gallery-items.json';
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    echo json_encode($data, JSON_PRETTY_PRINT);
}

/** True whenever anything in the whole tree is currently flagged hidden
 *  — drives the admin tool's persistent lockdown warning (see
 *  render_admin_page): reappearing every time the screen loads, not a
 *  one-time dismissible notice, since the goal is an honest, ongoing
 *  signal rather than implying a privacy guarantee this script can't
 *  actually confirm without the webserver-level step. */
function admin_any_hidden($dir, $files, $folderGroupKeys) {
    foreach (all_folder_paths($files) as $folderKey) {
        if (item_restriction(items_read(resolve_real_path($dir, $folderKey))) === 'private') {
            return true;
        }
    }
    foreach ($files as $file) {
        $entry = items_read(containing_folder_real_path($dir, $file))['items'][basename($file)] ?? null;
        if (item_restriction($entry) === 'private') {
            return true;
        }
    }
    return false;
}

/**
 * Everything hidden, across the whole tree, in one place — since
 * confirming "nothing is exposed that shouldn't be" by clicking into every
 * folder individually doesn't scale once depth is arbitrary. Three tiers,
 * not a flat "hidden" list — folders hidden entirely, individual items
 * hidden inside an otherwise-public folder, and individual items
 * deliberately shown despite sitting inside a hidden folder — blurring
 * them into one list risks reading as complete when it isn't. Feeds the
 * admin's "Hidden" tab.
 */
function admin_hidden_data($dir, $files) {
    $hiddenFolders = [];
    foreach (all_folder_paths($files) as $folderKey) {
        if (item_restriction(items_read(resolve_real_path($dir, $folderKey))) === 'private') {
            $hiddenFolders[] = $folderKey;
        }
    }
    $hiddenFolderSet = array_fill_keys($hiddenFolders, true);

    $hiddenItems = [];
    $shownDespiteHiddenFolder = [];
    foreach ($files as $file) {
        $entry = items_read(containing_folder_real_path($dir, $file))['items'][basename($file)] ?? null;
        $own = item_restriction($entry);
        // Hidden by any ancestor, at any depth.
        $folderHidden = false;
        $ancestorPath = '';
        foreach (explode('/', dirname($file)) as $segment) {
            if ($segment === '.') break;
            $ancestorPath = $ancestorPath === '' ? $segment : $ancestorPath . '/' . $segment;
            if (isset($hiddenFolderSet[$ancestorPath])) { $folderHidden = true; break; }
        }
        if ($own === 'private') {
            $hiddenItems[] = $file;
        } elseif ($folderHidden && $own === 'public') {
            $shownDespiteHiddenFolder[] = $file;
        }
    }

    return ['folders' => $hiddenFolders, 'items' => $hiddenItems, 'shown' => $shownDespiteHiddenFolder];
}

/** Where an item is edited: the gallery's viewer open on that photo, with the editor up (the pencil's own address). */
function admin_item_href($file) {
    return '?photo=' . htmlspecialchars(rawurlencode($file), ENT_QUOTES, 'UTF-8') . '&amp;edit=1';
}

/** Addresses for the owner's browser to try, to see whether the web server rules in render_server_rules_help are in place:
 *  one photo and one thumbnail from the cache folder (which the server should refuse), and one folder's tidy link. Only
 *  paths, in the same folder as this script; the browser makes the requests, so nothing is fetched from the server side. */
function lock_probe_targets($dir, $files) {
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
    $enc = function ($path) { return implode('/', array_map('rawurlencode', explode('/', $path))); };
    $out = ['photo' => '', 'cache' => '', 'folder' => '', 'folderUrl' => '', 'file' => '', 'count' => count($files)];
    foreach ($files as $f) {   // (a photo brought in from elsewhere on the server has no address of its own to ask for)
        if (preg_match('/\.(jpe?g|png|gif|webp|bmp|heic|heif|mov|mp4|m4v)$/i', $f) && file_is_under_web_root(resolve_real_path($dir, $f), $f)) { $out['photo'] = $base . $enc($f); $out['file'] = $f; break; }
    }
    foreach ($files as $f) {   // a folder anyone may open, for the tidy-link check (/kb/Folder/)
        $slash = strpos($f, '/');
        if ($slash === false) continue;
        $top = substr($f, 0, $slash);
        if (folder_restriction_cached($dir, $top) === 'private' || folder_above_is_private($dir, $f)) continue;
        $out['folder'] = $top; $out['folderUrl'] = $base . $enc($top) . '/';
        break;
    }
    $cached = @glob(rtrim($dir, '/') . '/.gallery-cache/*.jpg');
    if (!empty($cached)) $out['cache'] = $base . '.gallery-cache/' . rawurlencode(basename($cached[0]));
    return $out;
}

/** Ready-to-use web server rules that keep the photo files themselves from being fetched by address (private items are
 *  only private through this script otherwise), written out for this gallery's own address, in two collapsed blocks:
 *  nginx and Apache. Shown on the Private page and in Site settings so nobody has to leave the admin to set it up. */
function render_server_rules_help($withTidyLinks = false) {
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    $loc = $base === '' ? '/' : $base . '/';
    $nginx = "# The gallery's generated data files (captions, GPS, privacy flags, stats, login throttle, thumbnail cache):\n"
        . "# never public. (A second layer: the .php ones already answer 404 on their own.)\n"
        . "location ~ /\\.gallery- {\n    return 404;\n}\n\n"
        . "# The photo and video files themselves: reachable only through index.php.\n"
        . "location ~* ^" . $loc . ".*\\.(jpe?g|png|gif|webp|bmp|heic|heif|mov|mp4|m4v)\$ {\n    internal;\n}\n";
    $apache = "# The photo and video files themselves: reachable only through index.php.\n"
        . "# ((?i) matters: camera files are often IMG_0412.JPG.)\n"
        . "<FilesMatch \"(?i)\\.(jpe?g|png|gif|webp|bmp|heic|heif|mov|mp4|m4v)\$\">\n    Require all denied\n</FilesMatch>\n\n"
        . "# The gallery's generated data files (a second layer).\n"
        . "<FilesMatch \"^\\.gallery-\">\n    Require all denied\n</FilesMatch>\n";
    $prettyNginx = "location ~ ^" . $loc . ".+/\$ {\n    rewrite ^" . $loc . "(.+)/\$ " . $loc . "index.php?folder=\$1 last;\n}\n";
    $prettyApache = "RewriteEngine On\nRewriteRule ^(.+)/\$ index.php?folder=\$1 [B,L,QSA]\n";
    ob_start();
?>
    <div class="rules-help">
      <div data-lock-rules>
      <details>
        <summary><strong>nginx</strong> (strongest; needs shell access to the server)</summary>
        <p>Add inside the site's <code>server { }</code> block, first location above the second, then run <code>sudo nginx -t &amp;&amp; sudo systemctl reload nginx</code>. Written for this gallery's address, <code><?= $esc($loc) ?></code>.<?= $base === '' ? ' <strong>At the top of the site the second rule would block every image on it: narrow its path first.</strong>' : '' ?></p>
        <div class="rules-box"><button type="button" class="secondary copy-rules">Copy</button><pre><code><?= $esc($nginx) ?></code></pre></div>
        <p>Then set <em>nginx internal redirect</em> (Site settings → Server setup) to <code><?= $esc($base === '' ? '/' : $base) ?></code>. Test: <code>curl -I https://yoursite.com<?= $esc($loc) ?>some-photo.jpg</code> should answer 404. Behind <code>proxy_pass</code> instead of php-fpm, pass the real client address and protocol on.</p>
      </details>
      <details>
        <summary><strong>Apache</strong> (blunter, but works on FTP-only hosting)</summary>
        <p>Save as <code>.htaccess</code> in the gallery's folder.</p>
        <div class="rules-box"><button type="button" class="secondary copy-rules">Copy</button><pre><code><?= $esc($apache) ?></code></pre></div>
        <p>A bare photo address then answers 403. It only works if the server reads <code>.htaccess</code> here (<code>AllowOverride All</code>), so check with <code>curl -I</code>. Scripts that log in with an <code>Authorization</code> header on PHP-FPM also need <code>CGIPassAuth On</code>.</p>
      </details>
      </div>
      <?php if ($withTidyLinks): ?>
      <div class="lock-result" data-tidy-status></div>
      <div data-tidy-rules>
      <p class="rules-sub">Optional tidy folder links: <code><?= $esc($loc) ?>?folder=Europe/France</code> always works; with these, <code><?= $esc($loc) ?>Europe/France/</code> does too.</p>
      <details>
        <summary><strong>nginx</strong> folder links like <?= $esc($loc) ?>Europe/France/</summary>
        <p>Add inside <code>server { }</code>, next to the rules above. It matches only paths ending in <code>/</code> and needs php-fpm to pass the original <code>REQUEST_URI</code> (the standard <code>fastcgi_param</code> does).<?= $base === '' ? ' <strong>At the top of the site it would catch every folder address: narrow its path first.</strong>' : '' ?></p>
        <div class="rules-box"><button type="button" class="secondary copy-rules">Copy</button><pre><code><?= $esc($prettyNginx) ?></code></pre></div>
      </details>
      <details>
        <summary><strong>Apache</strong> folder links like <?= $esc($loc) ?>Europe/France/</summary>
        <p>Add to <code>.htaccess</code> (needs <code>mod_rewrite</code> and <code>AllowOverride FileInfo</code>). Keep the <code>B</code> flag: it escapes spaces, <code>&amp;</code>, <code>+</code> and <code>#</code>.</p>
        <div class="rules-box"><button type="button" class="secondary copy-rules">Copy</button><pre><code><?= $esc($prettyApache) ?></code></pre></div>
      </details>
      </div>
      <?php endif; ?>
    </div>
<?php
    return ob_get_clean();
}

/** The Hidden tab: the plain-English warning, then every hidden thing with
 *  a link to where it can be changed. */
function render_admin_hidden_panel($data) {
    $listFolders = function ($paths) {
        foreach ($paths as $f) {
            echo '<li><a href="?admin&amp;folder=' . htmlspecialchars(rawurlencode($f), ENT_QUOTES, 'UTF-8') . '#settings">' . htmlspecialchars($f, ENT_QUOTES, 'UTF-8') . '</a> <span class="muted">&mdash; open its settings</span></li>';
        }
    };
    $listItems = function ($paths) {
        foreach ($paths as $f) {
            echo '<li><a href="' . admin_item_href($f) . '">' . htmlspecialchars($f, ENT_QUOTES, 'UTF-8') . '</a> <span class="muted">&mdash; open it</span></li>';
        }
    };
?>
    <div class="flash error hidden-warning" id="lock-banner">
      <div class="lock-open-text"><strong>Private isn't locked.</strong> Making something private hides it from the gallery's pages, but the photo files can still be opened by their direct address. Add the rules below to stop that.</div>
      <div class="lock-locked-text" hidden><strong>Private is locked.</strong> Direct requests for photos are refused (checked just now from this browser). <button type="button" class="linklike show-rules-btn">Show the rules</button></div>
      <div class="lock-outside-text" hidden><strong>Private is locked.</strong> These photos live outside the gallery's own address, so only the gallery can send them. <button type="button" class="linklike show-rules-btn">Show the rules</button></div>
      <div class="lock-result" data-lock-detail></div>
      <button type="button" class="secondary lock-recheck" hidden>Check again</button>
    </div>
    <section class="panel" data-lock-rules-panel>
      <h2 class="section-title">Lock the files themselves</h2>
      <p class="muted" style="margin-top:0;">Copy the rules for your server. They stop photo files being fetched by their direct address.</p>
      <?= render_server_rules_help() ?>
      <p class="muted" data-tidy-rules>Optional tidy folder links and the nginx redirect setting are in <a href="#site" onclick="return adminGoTo('site', 'server-setup');">Site settings → Server setup</a>.</p>
    </section>
    <section class="panel">
      <h2 class="section-title">Folders that are private (<?= count($data['folders']) ?>)</h2>
      <?php if (empty($data['folders'])): ?><p class="muted">None.</p><?php else: ?><ul class="hidden-list"><?php $listFolders($data['folders']); ?></ul><?php endif; ?>
    </section>
    <section class="panel">
      <h2 class="section-title"><?= ucfirst(media_word()) ?> made private (<?= count($data['items']) ?>)</h2>
      <?php if (empty($data['items'])): ?><p class="muted">None.</p><?php else: ?><ul class="hidden-list"><?php $listItems($data['items']); ?></ul><?php endif; ?>
    </section>
    <section class="panel">
      <h2 class="section-title">Items public inside a private folder (<?= count($data['shown']) ?>)</h2>
      <?php if (empty($data['shown'])): ?><p class="muted">None.</p><?php else: ?><ul class="hidden-list"><?php $listItems($data['shown']); ?></ul><?php endif; ?>
    </section>
<?php
}

/** A setting that is one switch: flip it and it is saved on the spot (the page's
 *  script posts it; the small Save button only exists for a browser without
 *  JavaScript). The warning, if any, sits beside it in orange. */
function render_admin_instant_toggle($csrfToken, $action, $field, $on, $label, $warn = '') {
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
?>
    <form method="post" class="instant-form">
      <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
      <input type="hidden" name="action" value="<?= $esc($action) ?>">
      <input type="hidden" name="<?= $esc($field) ?>" value="0">
      <div class="instant-row">
        <label class="switch"><input type="checkbox" name="<?= $esc($field) ?>" value="1" <?= $on ? 'checked' : '' ?>><span class="slider"></span></label>
        <span class="instant-label"><?= $esc($label) ?></span>
        <span class="rowstate" role="status" aria-live="polite"></span>
      </div>
      <?php if ($warn !== ''): ?><div class="warn-note"><?= $esc($warn) ?></div><?php endif; ?>
      <div class="nojs-only"><button type="submit" class="secondary">Save</button></div>
    </form>
<?php
}

/** The "Privacy" section of a folder's (or the gallery's) settings: three rows with the same switch, each saved the moment it is
 *  pressed: the folder itself private (not at the top of the gallery), whether photo locations are public, and, under that because it
 *  only shows photos whose location is public, the map. */
function render_admin_privacy_section($itemsHere, $csrfToken, $adminFolderPath, $counts, $locWarn = '') {
    $isRoot = $adminFolderPath === null;
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $locOn = !empty($itemsHere['locationEnabled']) && ENABLE_PHOTO_LOCATION;
?>
    <h3 class="subhead">Privacy</h3>
<?php if (!$isRoot):
    $private = !empty($itemsHere['restriction']) && $itemsHere['restriction'] === 'private';
?>
    <form method="post" class="priv-folder-form" data-noun="<?= ENABLE_VIDEO ? 'item' : 'photo' ?>" data-stay="<?= (int) $counts['stay'] ?>" data-open="<?= (int) $counts['open'] ?>">
      <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
      <input type="hidden" name="action" value="toggle_folder_restriction">
      <input type="hidden" name="hidden" value="<?= $private ? '0' : '1' ?>">   <?php // what the Save button (no JavaScript) sends; the page's script sends its own request ?>
      <div class="instant-row">
        <label class="switch"><input type="checkbox" <?= $private ? 'checked' : '' ?> aria-label="Private folder"><span class="slider"></span></label>
        <span class="instant-label">Private folder</span>
        <span class="rowstate" role="status" aria-live="polite"></span>
      </div>
      <p class="hint priv-folder-note" data-on="Visitors can't see this folder or anything in it. <strong>%stay%</strong> will stay private (marked private on their own, or in another private folder); <strong>%open%</strong> %becomes% public when you switch this off. You'll be asked first." data-off="Switch it on to hide this folder and everything in it. Photos you mark private on their own stay private when you switch it off again, so you can go through them first."></p>
      <div class="nojs-only"><button type="submit" class="<?= $private ? 'secondary' : 'danger' ?>"><?= $private ? 'Make this folder public again' : 'Make this entire folder private' ?></button></div>
    </form>
    <div class="admin-modal" id="priv-modal" role="dialog" aria-modal="true" aria-labelledby="priv-modal-title" hidden>
      <div class="admin-modal-card">
        <h3 id="priv-modal-title">Make this folder public?</h3>
        <div class="pm-row pm-stay"><?= ui_icon('lock') ?><span><strong id="pm-stay">0 photos</strong> <span id="pm-stay-v">stay</span> private</span></div>
        <div class="pm-row pm-open"><?= ui_icon('unlock') ?><span><strong id="pm-open">0 photos</strong> <span id="pm-open-v">become</span> public</span></div>
        <p class="hint">Anyone with the link will see those. Not right? Cancel, mark more photos private first, then come back.</p>
        <div class="pm-btns"><button type="button" class="secondary" id="pm-cancel">Cancel</button><button type="button" id="pm-ok">Make public</button></div>
      </div>
    </div>
<?php endif; ?>
    <?php render_admin_instant_toggle($csrfToken, 'toggle_location_enabled', 'enabled', !empty($itemsHere['locationEnabled']), $isRoot ? 'Show photo locations publicly at the root' . (!ENABLE_PHOTO_LOCATION ? ' (locations are off for the whole gallery, in Site settings)' : '') : 'Show photo locations publicly for this folder' . (!ENABLE_PHOTO_LOCATION ? ' (locations are off for the whole gallery, in Site settings)' : ''), $locWarn); ?>
    <div class="map-under<?= $locOn ? '' : ' no-loc' ?>" id="map-under"<?= ENABLE_PHOTO_LOCATION ? '' : ' data-global-off="1"' ?>>
      <p class="warn-note map-loc-note">Locations are off here, so the map has nothing to show. A photo you set to show its location on its own still appears.</p>
      <?php render_admin_map_form($itemsHere, $csrfToken, $isRoot); ?>
    </div>
<?php
}

/** The "Map" section of a folder's (or the gallery's) settings: whether its page
 *  shows a map of its own photos, and how close photos must be to share a pin.
 *  Both are per page; a blank radius means the site-wide setting. */
function render_admin_map_form($itemsHere, $csrfToken, $isRoot) {
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $on = $isRoot ? (($itemsHere['mapEnabled'] ?? null) !== false) : (($itemsHere['mapEnabled'] ?? null) === true);
    $radius = $itemsHere['mapRadius'] ?? null;
?>
    <?php if (!ENABLE_MAP): ?>
      <p class="muted" style="margin-top:0;">The map is switched off for the whole gallery. Turn it on in <a href="#site" onclick="return adminGoTo('site');">Site settings</a> first; then each folder can show its own map.</p>
    <?php else: ?>
      <form method="post" class="instant-form map-form">
        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
        <input type="hidden" name="action" value="save_map_settings">
        <input type="hidden" name="map_enabled" value="0">
        <div class="instant-row">
          <label class="switch"><input type="checkbox" name="map_enabled" value="1" <?= $on ? 'checked' : '' ?>><span class="slider"></span></label>
          <span class="instant-label">Show a map of <?= $isRoot ? 'the whole gallery on its front page' : 'this folder\'s photos on its page' ?></span>
          <span class="rowstate" role="status" aria-live="polite"></span>
        </div>
        <div class="warn-note">Visitors' browsers load code from cdnjs and tiles from OpenStreetMap, so those sites see the visit. Only photos with a visible location are plotted.</div>
        <p class="hint" style="margin:0 0 10px;"><?= $isRoot ? 'Covers every photo in the gallery with a visible location.' : 'Covers this folder\'s photos and subfolders with a visible location (needs this folder\'s location switch).' ?></p>
        <?php // Pin grouping is part of the map: set in under it, and only there while the map is on. ?>
        <div class="map-sub"<?= $on ? '' : ' hidden' ?>>
          <div class="field" style="max-width:300px;">
            <label>Pin grouping (miles)</label>
            <input type="number" name="map_radius" min="0" max="10000" step="any" value="<?= $radius === null ? '' : $esc($radius) ?>" placeholder="<?= (int) MAP_CLUSTER_RADIUS_MILES ?>">
            <span class="rowstate" role="status" aria-live="polite"></span>
          </div>
          <p class="hint">Photos closer than this share one numbered pin; pressing it zooms in. <strong>0 turns grouping off.</strong> Blank uses the site setting (<?= $esc(MAP_CLUSTER_RADIUS_MILES) ?>).</p>
        </div>
        <div class="nojs-only"><button type="submit" class="secondary">Save map settings</button></div>
      </form>
    <?php endif; ?>
<?php
}

/** The identity form — title, dates, description (and position, for a
 *  folder) — shared by a folder's settings and the gallery's own, so the root
 *  is configured the same way a folder is. */
/** A date span as a person would write it: "Oct 11th, 2026" for one day, "Oct 1-11th, 2026" inside one month, and
 *  "12/30/2025 - 10/09/2026" when it crosses a month. */
function format_date_range($min, $max) {
    $ordinal = function ($n) {
        $n = (int) $n;
        $suffix = ($n % 100 >= 11 && $n % 100 <= 13) ? 'th' : ([1 => 'st', 2 => 'nd', 3 => 'rd'][$n % 10] ?? 'th');
        return $n . $suffix;
    };
    if (gmdate('Y-m', $min) === gmdate('Y-m', $max)) {
        $month = gmdate('M', $min);
        $year = gmdate('Y', $min);
        $d1 = (int) gmdate('j', $min);
        $d2 = (int) gmdate('j', $max);
        return $d1 === $d2 ? $month . ' ' . $ordinal($d1) . ', ' . $year : $month . ' ' . $d1 . '-' . $ordinal($d2) . ', ' . $year;
    }
    return gmdate('m/d/Y', $min) . ' - ' . gmdate('m/d/Y', $max);
}

/**
 * What dates do a folder's photos span? Offered to the admin as a suggestion for the folder's Dates field and never
 * published unless they save it. Uses each JPEG's own capture date when it has one, else the file's modified date.
 * Looks at no more than $cap photos, so a huge folder can't slow the admin down. Returns null with nothing to go on.
 */
function suggest_folder_dates($dir, $fileList, $cap = 300) {
    $times = [];
    $fromExif = 0;
    $looked = 0;
    foreach ($fileList as $f) {
        if (is_video_file($f)) continue;
        if (++$looked > $cap) break;
        $real = resolve_real_path($dir, $f);
        if (!$real || !is_file($real)) continue;
        $t = null;
        if (function_exists('exif_read_data') && preg_match('/\.jpe?g$/i', $f)) {
            $ex = @exif_read_data($real, 'EXIF', false);
            $raw = is_array($ex) ? ($ex['DateTimeOriginal'] ?? $ex['DateTimeDigitized'] ?? null) : null;
            if (is_string($raw) && preg_match('/^(\d{4}):(\d\d):(\d\d) (\d\d):(\d\d):(\d\d)/', $raw, $m) && (int) $m[1] > 1971 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                $t = gmmktime((int) $m[4], (int) $m[5], (int) $m[6], (int) $m[2], (int) $m[3], (int) $m[1]);
                $fromExif++;
            }
        }
        if ($t === null) $t = @filemtime($real) ?: null;
        if ($t !== null) $times[] = $t;
    }
    if (!$times) return null;
    return [
        'text' => format_date_range(min($times), max($times)),
        'source' => $fromExif * 2 >= count($times) ? '' : 'from the files\' modified dates: these photos carry no capture date',
    ];
}

/** A row of choices drawn as one segmented control (radio buttons underneath, so it posts like any form field and works
 *  without JavaScript). $options is value => label. */
function ui_segmented($name, $options, $current, $label = '') {
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $out = '<div class="seg" role="radiogroup" aria-label="' . $esc($label) . '">';
    foreach ($options as $value => $text) {
        $out .= '<label><input type="radio" name="' . $esc($name) . '" value="' . $esc($value) . '"' . ((string) $current === (string) $value ? ' checked' : '') . '><span>' . $esc($text) . '</span></label>';
    }
    return $out . '</div>';
}

function render_admin_identity_form($itemsHere, $csrfToken, $adminFolderPath, $dateSuggestion = null) {
    $isRoot = $adminFolderPath === null;
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $name = $isRoot ? basename(__DIR__) : basename($adminFolderPath);
?>
    <form method="post" class="identity-form">
      <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
      <input type="hidden" name="action" value="save_folder_identity">
      <input type="hidden" name="loaded_hash" value="<?= admin_record_hash(folder_identity_record($itemsHere)) ?>">
      <div class="settings-grid<?= $isRoot ? ' root' : '' ?>">
        <div class="field">
          <label><?= $isRoot ? 'Gallery title' : 'Display name' ?> <span class="rowstate" role="status" aria-live="polite"></span></label>
          <input type="text" name="display_name" value="<?= $esc($itemsHere['displayName'] ?? '') ?>">
          <?php if ($isRoot): ?>
            <p class="hint">Shown at the top of the page, in the browser tab and in shared links, instead of "<?= $esc($name) ?>". If blank, a "# Heading" at the top of a notes file is used, then the folder name.</p>
          <?php else: ?>
            <p class="hint">Shown on its cover card instead of "<?= $esc($name) ?>".</p>
          <?php endif; ?>
        </div>
        <div class="field">
          <label>Dates (optional) <span class="rowstate" role="status" aria-live="polite"></span></label>
          <input type="text" name="dates" maxlength="80" placeholder="Oct 1-11th, 2026" value="<?= $esc($itemsHere['dates'] ?? '') ?>">
          <?php if ($dateSuggestion && $dateSuggestion['text'] !== trim((string) ($itemsHere['dates'] ?? ''))): ?>
            <p class="hint">This folder's photos span <strong><?= $esc($dateSuggestion['text']) ?></strong><?= $dateSuggestion['source'] !== '' ? ' (' . $esc($dateSuggestion['source']) . ')' : '' ?>.
              <button type="button" class="secondary date-suggest" data-dates="<?= $esc($dateSuggestion['text']) ?>">Use these dates</button></p>
          <?php endif; ?>
        </div>
        <?php if (!$isRoot): ?>
        <div class="field">
          <label>Position <span class="rowstate" role="status" aria-live="polite"></span></label>
          <input type="number" name="sort_order" min="-99999" max="99999" step="1" value="<?= isset($itemsHere['sortOrder']) && is_int($itemsHere['sortOrder']) ? (int) $itemsHere['sortOrder'] : '' ?>">
          <p class="hint">Among sibling folders, lowest first. Blank = natural order, after numbered ones.</p>
        </div>
        <?php endif; ?>
        <?php $ps = is_array($itemsHere['photoSort'] ?? null) ? $itemsHere['photoSort'] : []; $psSel = function ($key) use ($ps) { return isset($ps[$key]) && is_bool($ps[$key]) ? ($ps[$key] ? 'on' : 'off') : ''; }; ?>
        <details class="field wide sort-details"<?= $ps ? ' open' : '' ?>>
          <summary>Order of the photos here <span class="muted"><?= $ps ? '&middot; custom' : "&middot; the site's order" ?></span> <span class="rowstate" role="status" aria-live="polite"></span></summary>
          <?php $dirNow = isset($ps['desc']) && is_bool($ps['desc']) ? ($ps['desc'] ? 'desc' : 'asc') : ''; $triState = ['' => "Site's", 'on' => 'On', 'off' => 'Off']; ?>
          <div class="sort-rows">
            <div class="sort-row"><span class="sort-label">Order</span><?= ui_segmented('photo_sort_order', ['' => "Site's", 'name' => 'Name', 'taken' => 'Date'], ($ps['order'] ?? '') === 'modified' ? 'taken' : (string) ($ps['order'] ?? ''), 'Order') ?></div>
            <div class="sort-row"><span class="sort-label">Direction</span><?= ui_segmented('photo_sort_dir', ['' => "Site's", 'asc' => 'First to last', 'desc' => 'Last to first'], $dirNow, 'Direction') ?></div>
            <?php
              $sfNames = sort_priority_names(); $sfOwn = isset($ps['priority']) || isset($ps['video']) || isset($ps['located']) || isset($ps['private']);
              $sfOrder = (isset($ps['priority']) && is_array($ps['priority']) && count($ps['priority']) === 3) ? array_values($ps['priority']) : sort_priority_list();
              foreach (array_keys($sfNames) as $k) if (!in_array($k, $sfOrder, true)) $sfOrder[] = $k;
              $sfOn = []; foreach ($sfNames as $k => $nm) $sfOn[$k] = isset($ps[$k]) && is_bool($ps[$k]) ? $ps[$k] : (bool) constant($nm[2]);
              $sfOrder = array_merge(array_values(array_filter($sfOrder, function ($k) use ($sfOn) { return $sfOn[$k]; })), array_values(array_filter($sfOrder, function ($k) use ($sfOn) { return !$sfOn[$k]; })));
            ?>
            <?php $siteOrder = sort_priority_list(); $siteOn = []; foreach ($sfNames as $k => $nm) $siteOn[$k] = (bool) constant($nm[2]); $siteOrder = array_merge(array_values(array_filter($siteOrder, function ($k) use ($siteOn) { return $siteOn[$k]; })), array_values(array_filter($siteOrder, function ($k) use ($siteOn) { return !$siteOn[$k]; }))); ?>
            <div class="sort-row sort-row-list"><span class="sort-label">Put first</span>
            <div class="sortfirst sf-form" data-mode="form" data-site-order="<?= $esc(implode(',', $siteOrder)) ?>" data-site-on="<?= $esc(json_encode($siteOn)) ?>">
              <?= ui_segmented('photo_sort_first', ['' => "Site's", 'own' => "This folder's own"], $sfOwn ? 'own' : '', 'Put first') ?>
              <ol class="sf-list">
              <?php foreach ($sfOrder as $k): $nm = $sfNames[$k]; ?>
                <li data-kind="<?= $esc($k) ?>">
                  <button type="button" class="sf-grip" aria-label="Move <?= $esc($nm[1]) ?>: drag it, or press the up and down arrow keys" title="Drag to reorder"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><g fill="currentColor"><circle cx="9" cy="6" r="1.7"/><circle cx="15" cy="6" r="1.7"/><circle cx="9" cy="12" r="1.7"/><circle cx="15" cy="12" r="1.7"/><circle cx="9" cy="18" r="1.7"/><circle cx="15" cy="18" r="1.7"/></g></svg></button>
                  <span class="sf-name"><?= $esc($nm[0]) ?></span>
                  <input type="hidden" name="photo_sort_<?= $esc($k) ?>" value="off">
                  <label class="switch"><input type="checkbox" name="photo_sort_<?= $esc($k) ?>" value="on" <?= $sfOn[$k] ? 'checked' : '' ?> aria-label="<?= $esc($nm[0]) ?> first"><span class="slider"></span></label>
                </li>
              <?php endforeach; ?>
              </ol>
              <input type="hidden" name="photo_sort_priority" class="sf-order" value="<?= $esc(implode(',', $sfOrder)) ?>">
              <p class="sf-summary" aria-live="polite"><?= $esc(sort_priority_summary($sfOrder, $sfOn, $siteOrder, $siteOn)) ?></p>
            </div>
            </div>
          </div>
          <p class="hint">"Put first" is the same list as in Site settings. Until you change it here, this folder follows the site's. Visitors can change the order with the sort button. Photos placed with <code>{photos:}</code> or a group keep your order.</p>
        </details>
        <div class="field wide">
          <label>Description <span class="rowstate" role="status" aria-live="polite"></span></label>
          <textarea name="description" maxlength="1000" rows="4" style="min-height:90px; font-family:inherit;"><?= $esc($itemsHere['description'] ?? '') ?></textarea>
          <p class="hint">Centred box at the top of <?= $isRoot ? 'the page' : 'its page' ?>, also used in shared-link previews. A little Markdown works (<code>**bold**</code>, <code>*italic*</code>, <code>[words](https://…)</code>, <code>- list</code>, <code>## heading</code>); <code>{left}</code>, <code>{center}</code> or <code>{right}</code> aligns a paragraph.</p>
        </div>
      </div>
      <?php // Every field saves by itself as it is changed (the page's script); the button is only here for a browser without JavaScript. ?>
      <div class="nojs-only"><button type="submit"><?= $isRoot ? 'Save gallery settings' : 'Save folder settings' ?></button></div>
    </form>
<?php
}

/**
 * The settings the admin's "Site settings" screen can change: one table that
 * drives both the form and the validation, so nothing outside it can ever be
 * written. Passwords are handled separately (never shown, confirmed), and
 * EXTRA_FOLDERS / NGINX_ACCEL_REDIRECT_PREFIX are deliberately file-only:
 * the first points the gallery at paths elsewhere on the server and the
 * second needs matching web-server config, so neither belongs behind a web
 * form. The screen only ever writes .gallery-config.php.
 */
function gallery_setting_definitions() {
    return [
        ['title' => 'What is shown', 'items' => [
            ['name' => 'AUTO_GROUP_BY_FOLDER', 'type' => 'bool', 'label' => 'Show subfolders',
             'help' => 'Each subfolder becomes its own album, to any depth.',
             'risk' => 'Every photo in every subfolder becomes visible.',
             'offwarn' => 'Subfolders and links to them disappear. Nothing is deleted.'],
            ['name' => 'FOLDER_DISPLAY_MODE', 'type' => 'enum', 'label' => 'Folder style',
             'options' => ['overview' => 'Cover cards', 'inline' => 'Inline'],
             'help' => 'Cover cards open each folder; inline shows all on one page. Only used when subfolders are shown.'],
            ['name' => 'ENABLE_VIDEO', 'type' => 'bool', 'label' => 'Show videos (.mov, .mp4)',
             'help' => 'Sent as recorded, with no conversion: big clips use real bandwidth.',
             'risk' => 'Every .mov and .mp4 becomes visible.',
             'offwarn' => 'Videos disappear. Nothing is deleted.'],
            ['name' => 'ENABLE_VIDEO_THUMBNAILS', 'type' => 'bool', 'label' => 'Video thumbnails', 'requires' => 'ENABLE_VIDEO', 'status' => 'ffmpeg',
             'help' => 'A real picture from each video, taken once with ffmpeg. Needs "Thumbnails".',
             'risk' => 'Runs ffmpeg on your server, once per new video.',
             'offwarn' => 'Videos show the generic icon again.'],
            ['name' => 'ENABLE_VIDEO_PREVIEWS', 'type' => 'bool', 'label' => 'Video previews', 'requires' => 'ENABLE_VIDEO',
             'help' => 'Hovering or scrubbing a video plays a short silent clip, made once with ffmpeg.',
             'risk' => 'Runs ffmpeg once per new video; each clip uses a little disk.',
             'offwarn' => 'Hover clips and scrubbing stop.'],
            ['name' => 'AUTO_GROUP_BY_FILENAME', 'type' => 'bool', 'label' => 'Group photos by filename',
             'help' => 'alpha_01.jpg and alpha_02.jpg become one group. Good for listings; on a camera roll it can merge unrelated photos.'],
            ['name' => 'ENABLE_PUBLIC_DOWNLOAD', 'type' => 'bool', 'label' => 'Let visitors download originals',
             'help' => 'A download button in the viewer for everyone (you always have it). Hidden locations are removed from a visitor\'s copy.',
             'risk' => 'Anyone can save every photo at full resolution.',
             'offwarn' => 'Visitors lose the download button.'],
            ['name' => 'PHOTO_SORT_ORDER', 'type' => 'enum', 'label' => 'Photo order',
             'options' => ['filename' => 'Name', 'taken' => 'Date'],
             'help' => 'Inside each folder. Date is when the photo was taken, else the file\'s date. Left alone it is Date, or Name when photos are grouped by filename (so a group stays in sequence). A folder can override it; visitors can change it with the sort button.'],
            ['name' => 'PHOTO_SORT_DESC', 'type' => 'bool', 'label' => 'Last first',
             'help' => 'Z to A, or newest first.'],
            ['name' => 'SORT_PRIORITY', 'type' => 'sortfirst', 'label' => 'Put first',
             'help' => 'Switch on what should come first, and drag the handles to set the order they stack in. Then come the rest, in the order above.'],
            ['name' => 'SORT_VIDEOS_FIRST', 'in' => 'SORT_PRIORITY', 'type' => 'bool', 'label' => 'Videos first',
             'help' => 'Videos at the front of each folder.'],
            ['name' => 'SORT_LOCATED_FIRST', 'in' => 'SORT_PRIORITY', 'type' => 'bool', 'label' => 'Photos with a location first',
             'help' => 'Photos whose location is shown come first (after videos).'],
            ['name' => 'SORT_PRIVATE_FIRST', 'in' => 'SORT_PRIORITY', 'type' => 'bool', 'label' => 'Private items first',
             'help' => 'For you and private-view visitors; nobody else sees private items.'],
            ['name' => 'SHOW_SORT_BUTTON', 'type' => 'bool', 'label' => 'Sort button',
             'help' => 'Lets visitors reorder the photos. Left out automatically on a listing laid out by a notes file, with no folders.'],
        ]],
        ['title' => 'Thumbnails and counting', 'items' => [
            ['name' => 'ENABLE_THUMBNAIL_CACHE', 'type' => 'bool', 'label' => 'Thumbnails',
             'help' => 'Small copies kept in .gallery-cache so pages load fast and iPhone photos show. If it can\'t write, originals are sent.',
             'offwarn' => 'Photos are sent at full size and iPhone (HEIC) photos can\'t show. Nothing is deleted.'],
            ['name' => 'TRACK_VIEWS', 'type' => 'bool', 'label' => 'Count views',
             'help' => 'Visits (one per visitor per half hour) and each photo\'s views. You always see them here.'],
            ['name' => 'SHOW_VIEW_COUNTS_PUBLICLY', 'type' => 'bool', 'label' => 'Show view counts to visitors',
             'help' => 'Needs "Count views". Off keeps the numbers to yourself (you still see them while signed in as the owner).'],
        ]],
        ['title' => 'Locations and the map', 'items' => [
            ['name' => 'ENABLE_PHOTO_LOCATION', 'type' => 'bool', 'label' => 'Allow photo locations',
             'help' => 'Required before any location shows. Each folder and photo still has its own switch.',
             'risk' => 'GPS can reveal where you live or have been. Nothing shows until a folder opts in.',
             'offwarn' => 'Locations and maps disappear. Nothing is deleted.'],
            ['name' => 'ENABLE_MAP', 'type' => 'bool', 'label' => 'Map',
             'help' => 'A map of photos with a visible location. Needs "Allow photo locations".',
             'risk' => 'Visitors\' browsers load code from cdnjs and tiles from OpenStreetMap, so those sites see the visit.',
             'offwarn' => 'Maps disappear. Locations stay saved.'],
            ['name' => 'MAP_CLUSTER_RADIUS_MILES', 'type' => 'number', 'label' => 'Map pin grouping (miles)', 'min' => 0, 'max' => 10000, 'step' => 'any', 'requires' => 'ENABLE_MAP',
             'help' => 'Photos closer than this share one numbered pin. 1 suits a city trip, more a road trip, 0 turns grouping off. Folders can override it.'],
        ]],
        ['title' => 'Server setup (advanced)', 'items' => [
            ['name' => 'EXTRA_FOLDERS', 'type' => 'folders', 'label' => 'Folders from elsewhere on the server',
             'help' => 'Show a place elsewhere on the server (an archive on another disk). Without a name its folders appear at the top; with one it shows as a single folder. Needs "Show subfolders".',
             'risk' => 'Every photo and video there becomes visible.'],
            ['name' => 'NGINX_ACCEL_REDIRECT_PREFIX', 'type' => 'text', 'label' => 'nginx internal redirect', 'status' => 'accel',
             'help' => 'Lets nginx send photos and videos instead of PHP: faster, and videos seek better. Needs the matching internal location (see the Private tab) and the gallery\'s own address, such as /kb. Files from elsewhere are always sent by the gallery.',
             'risk' => 'Without the matching nginx location, photos and videos stop loading.'],
        ]],
    ];
}

/** Checks the places from elsewhere that the Site settings screen posts (JSON: {"named": {name: path}, "roots": [path]})
 *  before anything is stored. A path must be a real, readable folder, written as the real path (no symlink anywhere in
 *  it, since the scan refuses to follow them), outside this gallery's own folder and not one that contains it. Returns
 *  ['named' => ..., 'roots' => ...] clean, or null with reasons in $errors. */
function site_settings_validate_extra_folders($raw, &$errors) {
    $in = json_decode((string) $raw, true);
    if (!is_array($in) || !isset($in['named'], $in['roots']) || !is_array($in['named']) || !is_array($in['roots']) || !array_is_list($in['roots'])) { $errors[] = 'The list of folders from elsewhere wasn\'t understood.'; return null; }
    if (count($in['named']) + count($in['roots']) > 50) { $errors[] = 'That is more folders from elsewhere than the gallery allows (50).'; return null; }
    $gallery = realpath(__DIR__);
    $bad = false;
    // One path check for both kinds; $q names the entry in any message.
    $checkPath = function ($path, $q) use ($gallery, &$errors, &$bad) {
        if (!is_string($path)) { $errors[] = "The path for $q isn't text."; $bad = true; return null; }
        $p = rtrim($path, '/\\');
        if ($p === '' || preg_match('#[\x00-\x1F\x7F]#', $path) || !preg_match('#^(/|[A-Za-z]:[\\\\/])#', $p) || preg_grep('#^\.\.?$#', preg_split('#[\\\\/]+#', $p))) {
            $errors[] = "The path for $q must be a full path starting with / (no .. in it)."; $bad = true; return null;
        }
        if (!@is_dir($p) || !@is_readable($p)) {
            $errors[] = "The path for $q ($p) isn't a folder this server can read. Check it exists and that the web server may open it (and, on shared hosting, that PHP is allowed outside the website's folder)."; $bad = true; return null;
        }
        $real = realpath($p);
        if (is_link($p) || $real === false || $real !== $p) {
            $errors[] = "The path for $q goes through a link or isn't written as its real path" . ($real ? " (it is $real)" : '') . ". The gallery doesn't follow links, so give the real path."; $bad = true; return null;
        }
        if ($gallery !== false && ($real === $gallery || str_starts_with($real . '/', $gallery . '/') || str_starts_with($gallery . '/', $real . '/'))) {
            $errors[] = "The path for $q overlaps this gallery's own folder (inside it, or containing it), which would show things twice or expose too much."; $bad = true; return null;
        }
        return $real;
    };
    $named = []; $seen = [];
    foreach ($in['named'] as $name => $path) {
        $name = (string) $name; $q = '"' . $name . '"';
        if ($name === '' || $name !== trim($name) || strlen($name) > 60 || $name[0] === '.' || preg_match('#[/\\\\\x00-\x1F\x7F]#', $name)) {
            $errors[] = "$q isn't a usable folder name: 1 to 60 characters, no slashes, no leading dot or space."; $bad = true; continue;
        }
        if (isset($seen[strtolower($name)])) { $errors[] = "The name $q is used twice."; $bad = true; continue; }
        $seen[strtolower($name)] = true;
        if (is_dir(__DIR__ . '/' . $name)) { $errors[] = "A folder called $q already exists in the gallery, so that name is taken."; $bad = true; continue; }
        $real = $checkPath($path, $q);
        if ($real !== null) $named[$name] = $real;
    }
    $roots = [];
    foreach ($in['roots'] as $path) {
        $real = $checkPath($path, is_string($path) ? '"' . $path . '"' : 'a folder');
        if ($real === null) continue;
        if (in_array($real, $roots, true)) { $errors[] = '"' . $real . '" is listed twice.'; $bad = true; continue; }
        $roots[] = $real;
    }
    return $bad ? null : ['named' => $named, 'roots' => $roots];
}

/** The nginx prefix: empty, or a plain URL path such as /kb. */
function site_settings_valid_nginx_prefix($value) {
    if ($value === '') return true;
    if (strlen($value) > 120 || $value[0] !== '/' || str_ends_with($value, '/') || str_contains($value, '//')) return false;
    foreach (explode('/', substr($value, 1)) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..' || !preg_match('/^[A-Za-z0-9._~-]+$/', $segment)) return false;
    }
    return true;
}

/** The settings file's exact text for $cfg: the data guard, then the keys in
 *  a stable order as JSON. Returns null if it can't be encoded (invalid
 *  UTF-8 in a value). */
function gallery_config_render($cfg) {
    $order = [];
    foreach (gallery_setting_definitions() as $group) foreach ($group['items'] as $d) $order[] = $d['name'];
    foreach (['ADMIN_PASSWORD', 'PRIVATE_VIEW_PASSWORD', 'EXTRA_FOLDERS', 'EXTRA_ROOTS', 'NGINX_ACCEL_REDIRECT_PREFIX'] as $n) $order[] = $n;
    $keys = array_values(array_unique(array_merge(array_values(array_intersect($order, array_keys($cfg))), array_keys($cfg))));
    $ordered = [];
    foreach ($keys as $k) $ordered[$k] = $cfg[$k];
    $json = json_encode($ordered === [] ? new stdClass() : $ordered, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
    return $json === false ? null : data_file_guard_line() . $json . "\n";
}

/** Validates the Site settings form and rewrites .gallery-config.php, and
 *  nothing else: captions, locations, hide flags, stats and notes are never
 *  read or written here. Returns an admin result array. */
function site_settings_save($ajax = false) {
    $state = gallery_config_load();
    $path = gallery_config_path();
    if (!in_array($state['status'], ['absent', 'ok'], true)) {
        return ['type' => 'error', 'text' => 'The settings file (.gallery-config.php) exists but can\'t be read as settings, so nothing was changed — saving would overwrite whatever is in it. Fix or delete that file, then try again.'];
    }
    $currentRaw = is_file($path) ? (string) file_get_contents($path) : '';
    if (!hash_equals(md5($currentRaw), (string) ($_POST['config_hash'] ?? ''))) {
        return ['type' => 'error', 'text' => 'The settings file' . admin_stale_message()];
    }
    $cfg = $state['cfg'];
    $defaults = $GLOBALS['__gallery_defaults'] ?? [];
    $errors = [];
    $setOrUnset = function ($name, $value) use (&$cfg, $defaults) {
        if (array_key_exists($name, $defaults) && $defaults[$name] === $value) unset($cfg[$name]); else $cfg[$name] = $value;
    };
    foreach (gallery_setting_definitions() as $group) {
        foreach ($group['items'] as $d) {
            $name = $d['name'];
            if (!isset($_POST['set_' . $name]) || !is_string($_POST['set_' . $name])) continue;
            $raw = $_POST['set_' . $name];
            if ($d['type'] === 'bool') {
                $value = $raw === '1';
            } elseif ($d['type'] === 'folders') {
                $value = site_settings_validate_extra_folders($raw, $errors);
                if ($value === null) continue;
                $setOrUnset('EXTRA_ROOTS', $value['roots']);
                $value = $value['named'];
            } elseif ($d['type'] === 'text') {
                if (!site_settings_valid_nginx_prefix($raw)) { $errors[] = '"' . $d['label'] . '" must be empty or a plain path like /kb (starting with /, no trailing slash, letters, digits and . _ ~ - only).'; continue; }
                $value = $raw;
            } elseif ($d['type'] === 'sortfirst') {
                $kinds = array_map('trim', explode(',', $raw));
                $want = ['private', 'video', 'located']; $sorted = $kinds; sort($sorted); sort($want);
                if ($sorted !== $want) { $errors[] = '"' . $d['label'] . '" must list private, video and located, each once.'; continue; }
                $value = implode(',', $kinds);
            } elseif ($d['type'] === 'enum') {
                if (!array_key_exists($raw, $d['options'])) { $errors[] = '"' . $d['label'] . '" must be one of the choices offered.'; continue; }
                $value = $raw;
            } else {
                if (!is_numeric($raw) || (float) $raw < $d['min'] || (float) $raw > $d['max']) { $errors[] = '"' . $d['label'] . '" must be a number from ' . $d['min'] . ' to ' . $d['max'] . '.'; continue; }
                $value = (float) $raw == (int) $raw ? (int) $raw : (float) $raw;
            }
            $setOrUnset($name, $value);
        }
    }
    $newAdmin = null;
    $adminPw = (string) ($_POST['pw_admin'] ?? '');
    if ($adminPw !== '') {
        if ($adminPw !== (string) ($_POST['pw_admin2'] ?? '')) $errors[] = 'The two admin passwords you typed don\'t match.';
        elseif (!admin_password_ok($adminPw)) $errors[] = 'The admin password needs at least 8 characters, with at least one letter and one number: it is the one credential that can change your gallery.';
        elseif (preg_match('/[\x00-\x1F\x7F]/', $adminPw)) $errors[] = 'The admin password can\'t contain control characters.';
        else { $newAdmin = password_for_storage($adminPw); $setOrUnset('ADMIN_PASSWORD', $newAdmin); }
    }
    $viewPw = (string) ($_POST['pw_private'] ?? '');
    if (!empty($_POST['pw_private_remove'])) {
        $setOrUnset('PRIVATE_VIEW_PASSWORD', '');
    } elseif ($viewPw !== '') {
        if ($viewPw !== (string) ($_POST['pw_private2'] ?? '')) $errors[] = 'The two private-view passwords you typed don\'t match.';
        elseif (preg_match('/[\x00-\x1F\x7F]/', $viewPw)) $errors[] = 'The private-view password can\'t contain control characters.';
        else $setOrUnset('PRIVATE_VIEW_PASSWORD', password_for_storage($viewPw));
    }
    if ($errors) {
        return ['type' => 'error', 'text' => 'Nothing was saved. ' . implode(' ', $errors)];
    }
    $a = $cfg; $b = $state['cfg']; ksort($a); ksort($b);
    if ($a === $b) {
        return ['type' => 'ok', 'text' => 'Nothing to change: those are the settings already in use.', 'hash' => md5($currentRaw), 'unchanged' => true];
    }
    $content = gallery_config_render($cfg);
    if ($content === null) {
        return ['type' => 'error', 'text' => 'Nothing was saved: one of the values isn\'t valid text (it has bytes that aren\'t UTF-8).'];
    }
    $fail = function ($why) use ($content) {
        $GLOBALS['__site_settings_manual'] = $content;
        return ['type' => 'error', 'text' => 'Nothing was saved: ' . $why . ' The text below is the settings file; put it in a file named .gallery-config.php next to index.php yourself.', 'manual' => $content];
    };
    if (!is_writable(__DIR__)) return $fail('the gallery\'s folder isn\'t writable by the web server.');
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $content, LOCK_EX) === false) { @unlink($tmp); return $fail('the settings file couldn\'t be written.'); }
    @chmod($tmp, 0600);
    // Read back what was written, as the loader will, and refuse to install it unless it is exactly the settings intended.
    [$roundStatus, $roundTrip] = gallery_config_parse((string) @file_get_contents($tmp));
    ksort($roundTrip);
    if ($roundStatus !== 'ok' || $roundTrip !== $a) { @unlink($tmp); return $fail('the file that would have been written didn\'t read back correctly, so it was not installed.'); }
    if (!@rename($tmp, $path)) { @unlink($tmp); return $fail('the settings file couldn\'t be replaced.'); }
    // Changing the admin password must not log the person who just changed it out.
    if ($newAdmin !== null) {
        $_SESSION['admin_auth'] = admin_session_marker($newAdmin);
        set_admin_reveal_cookie($newAdmin);   // the reveal token is derived from the stored password too
    }
    $n = count($cfg);
    if ($ajax) {
        // The page updates in place, so there is no "next page" to carry a message: say it now, and hand back the new file fingerprint.
        return ['type' => 'ok', 'text' => ($newAdmin !== null ? 'Admin password changed; you stay signed in.' : 'Saved.'), 'hash' => md5_file($path), 'differing' => $n, 'extra' => ['named' => (object) ($cfg['EXTRA_FOLDERS'] ?? []), 'roots' => array_values($cfg['EXTRA_ROOTS'] ?? [])]];
    }
    $_SESSION['admin_flash'] = ['type' => 'ok', 'text' => 'Settings saved' . ($newAdmin !== null ? ' (admin password changed; you stay signed in)' : '') . '. ' . ($n === 0 ? 'Everything is on its default.' : $n . ' setting' . ($n === 1 ? '' : 's') . ' differ' . ($n === 1 ? 's' : '') . ' from the defaults and will survive updating index.php.') . ' Your photos, captions, locations and counts were not touched.'];
    return ['type' => 'redirect'];
}

/** Remembers which version of this script last ran against this install (a small guarded data file beside index.php), so
 *  the health panel can say "upgraded from X on Y". Written only when the version changes; null if it can't be kept. */
function health_version_record($dir) {
    $path = rtrim($dir, '/') . '/.gallery-health.php';
    $data = json_file_read($path, ['version' => '', 'from' => '', 'at' => '', 'first' => '']);
    if (($data['version'] ?? '') === GALLERY_VERSION) return $data;
    $new = ['version' => GALLERY_VERSION, 'from' => (string) ($data['version'] ?? ''), 'at' => gmdate('Y-m-d'),
            'first' => ($data['first'] ?? '') !== '' ? (string) $data['first'] : gmdate('Y-m-d')];
    return @file_put_contents($path, data_file_encode($new), LOCK_EX) !== false ? $new : null;
}

/** The server-side half of the health panel: rows of [group, status (ok|warn|bad|info), label, detail], and nothing is
 *  changed by looking. Folders are sampled (the first 300 that hold media) so a huge gallery stays quick. */
function health_checks($dir, $files) {
    $rows = [];
    $add = function ($group, $status, $label, $detail = '') use (&$rows) { $rows[] = [$group, $status, $label, $detail]; };

    // --- this install
    $rec = health_version_record($dir);
    if ($rec === null) $add('This install', 'warn', 'Running ' . GALLERY_VERSION, 'The folder is not writable by the web server, so the version history can\'t be kept.');
    elseif (($rec['from'] ?? '') !== '') $add('This install', 'ok', 'Running ' . GALLERY_VERSION, 'Upgraded from ' . $rec['from'] . ' on ' . $rec['at'] . ' (first recorded ' . $rec['first'] . ').');
    else $add('This install', 'ok', 'Running ' . GALLERY_VERSION, 'First recorded ' . ($rec['first'] ?? $rec['at'] ?? '') . '; upgrades are noted here.');
    $add('This install', PHP_VERSION_ID >= 80300 ? 'ok' : 'bad', 'PHP ' . PHP_VERSION, PHP_VERSION_ID >= 80300 ? '' : 'This gallery needs PHP 8.3 or newer.');
    $hasGd = extension_loaded('gd'); $hasImagick = class_exists('Imagick');
    $add('This install', ($hasGd || $hasImagick) ? 'ok' : 'bad', 'Picture resizing: ' . ($hasImagick && $hasGd ? 'GD and Imagick' : ($hasImagick ? 'Imagick' : ($hasGd ? 'GD' : 'none')) ),
        ($hasGd || $hasImagick) ? '' : 'Neither GD nor Imagick is installed, so thumbnails can\'t be made.');
    $heic = 0; foreach ($files as $f) if (preg_match('/\.hei[cf]$/i', $f)) $heic++;
    if ($heic > 0 && !$hasImagick) $add('This install', 'warn', 'HEIC photos can\'t be shown', $heic . ' HEIC photo' . ($heic === 1 ? '' : 's') . ' found, and HEIC needs the Imagick extension (it isn\'t installed).');
    $add('This install', function_exists('exif_read_data') ? 'ok' : 'warn', 'Photo details (EXIF): ' . (function_exists('exif_read_data') ? 'available' : 'missing'),
        function_exists('exif_read_data') ? '' : 'Without the exif extension, a photo\'s location and date can\'t be read from the file.');

    // --- settings and passwords
    $state = gallery_config_load();
    $cfgPath = gallery_config_path();
    if ($state['status'] === 'absent') $add('Settings and passwords', 'info', 'No settings file yet', 'Everything runs on the defaults; .gallery-config.php appears when you change something here.');
    elseif ($state['status'] === 'ok') $add('Settings and passwords', is_writable($cfgPath) ? 'ok' : 'warn', 'Settings file readable' . (is_writable($cfgPath) ? ' and writable' : ' but not writable'), is_writable($cfgPath) ? '' : 'Changes in Site settings can\'t be saved until the web server may write .gallery-config.php.');
    else $add('Settings and passwords', 'bad', 'Settings file can\'t be read as settings', 'Fix or delete .gallery-config.php; until then the settings in index.php apply.');
    foreach (($GLOBALS['__gallery_config_problems'] ?? []) as $problem) $add('Settings and passwords', 'warn', 'Settings problem', $problem);
    $pwRow = function ($label, $value, $emptyStatus, $emptyDetail) use ($add) {
        if ($value === '') $add('Settings and passwords', $emptyStatus, $label . ': not set', $emptyDetail);
        elseif (password_is_hashed($value)) $add('Settings and passwords', 'ok', $label . ': stored as a hash', '');
        else $add('Settings and passwords', 'warn', $label . ': stored as plain text', 'It is changed to a hash by the next successful sign-in with it.');
    };
    $pwRow('Admin password', ADMIN_PASSWORD, 'warn', 'Without one the admin is not available (it can be set during the first minutes after upload).');
    $pwRow('Private-view password', PRIVATE_VIEW_PASSWORD, 'info', 'Fine if you have no private items to share.');
    $add('Settings and passwords', is_file(rtrim($dir, '/') . '/.gallery-secret.php') ? 'ok' : 'info', is_file(rtrim($dir, '/') . '/.gallery-secret.php') ? 'Sign-in key present' : 'Sign-in key not made yet', is_file(rtrim($dir, '/') . '/.gallery-secret.php') ? '' : 'It is made the first time someone signs in.');

    // --- where the gallery's folders live
    $named = is_array(EXTRA_FOLDERS) ? EXTRA_FOLDERS : [];
    $roots = is_array(EXTRA_ROOTS) ? EXTRA_ROOTS : [];
    $extraBad = [];
    foreach ($named as $name => $path) if (!is_string($path) || !is_dir($path) || !is_readable($path)) $extraBad[] = (is_string($name) ? $name . ' = ' : '') . (is_string($path) ? $path : '?');
    foreach ($roots as $path) if (!is_string($path) || !is_dir($path) || !is_readable($path)) $extraBad[] = is_string($path) ? $path : '?';
    if ($named || $roots) {
        $total = count($named) + count($roots);
        $add('Folders and files', $extraBad ? 'bad' : 'ok', $extraBad ? count($extraBad) . ' of ' . $total . ' folders from elsewhere are missing or unreadable' : 'All ' . $total . ' folders from elsewhere on the server exist and can be read',
            $extraBad ? 'Missing: ' . implode(', ', array_slice($extraBad, 0, 5)) . '. Their photos are not shown until the path exists again.' : '');
    }
    $folders = [rtrim($dir, '/') => true];
    foreach ($files as $f) { $folders[containing_folder_real_path($dir, $f)] = true; if (count($folders) >= 300) break; }
    $unwritable = []; $badData = []; $withData = 0; $entries = 0;
    foreach (array_keys($folders) as $folder) {
        if (!is_writable($folder)) $unwritable[] = $folder;
        $itemsFile = items_path($folder);
        if (!is_file($itemsFile)) continue;
        $withData++;
        $raw = trim(data_file_strip_guard(@file_get_contents($itemsFile)));
        $decoded = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($decoded)) { $badData[] = $folder; continue; }
        $entries += count($decoded['items'] ?? []);
    }
    $sampled = count($folders) >= 300 ? ' (the first 300 folders with media)' : '';
    $rel = function ($p) use ($dir) { $d = rtrim($dir, '/'); return $p === $d ? '(top folder)' : (strncmp($p, $d . '/', strlen($d) + 1) === 0 ? substr($p, strlen($d) + 1) : $p); };
    $add('Folders and files', $unwritable ? 'warn' : 'ok', $unwritable ? count($unwritable) . ' of ' . count($folders) . ' folders are not writable by the web server' . $sampled : 'All ' . count($folders) . ' folders are writable' . $sampled,
        $unwritable ? 'Captions, locations, privacy flags and cached thumbnails can\'t be saved in: ' . implode(', ', array_map($rel, array_slice($unwritable, 0, 5))) . (count($unwritable) > 5 ? ' and ' . (count($unwritable) - 5) . ' more' : '') . '. Photos still show.' : '');
    $add('Folders and files', $badData ? 'bad' : 'ok', $badData ? count($badData) . ' folder data file' . (count($badData) === 1 ? '' : 's') . ' can\'t be read' : 'Folder data files all read fine (' . $withData . ' folder' . ($withData === 1 ? '' : 's') . ', ' . $entries . ' photo' . ($entries === 1 ? '' : 's') . ' with saved details)',
        $badData ? 'Treated as empty, so the next save there would overwrite it. Restore .gallery-items.php from a backup first: ' . implode(', ', array_map($rel, array_slice($badData, 0, 5))) : 'Wiping a folder wipes its captions, locations and private flags.');
    $videos = 0; foreach ($files as $f) if (is_video_file($f)) $videos++;
    $add('Folders and files', 'info', number_format(count($files) - $videos) . ' photo' . (count($files) - $videos === 1 ? '' : 's') . ($videos ? ' and ' . number_format($videos) . ' video' . ($videos === 1 ? '' : 's') : ''), 'in ' . number_format(count($folders)) . ' folder' . (count($folders) === 1 ? '' : 's') . $sampled . '.');

    // --- videos
    if (ENABLE_VIDEO) {
        $ff = ffmpeg_binary();
        $add('Videos', $ff !== '' ? 'ok' : 'warn', 'ffmpeg ' . ($ff !== '' ? 'found' : 'not found'), $ff !== '' ? $ff . ' — real thumbnails, hover clips and scrubbing are available.' : 'Videos show the browser\'s own first frame; no hover clips or scrubbing.');
    }
    return $rows;
}

/** The health panel at the top of Site settings: the server-side rows from health_checks(), then three the browser fills in
 *  (see the script: the web server rules can only be tested by asking for addresses the way a visitor would). */
function render_admin_health_panel() {
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $rows = health_checks($GLOBALS['dir'], $GLOBALS['__admin_files'] ?? []);
    $loud = 0;
    foreach ($rows as $r) if ($r[1] === 'bad' || $r[1] === 'warn') $loud++;
    $mark = ['ok' => 'check', 'warn' => 'warn', 'bad' => 'warn'];
    $groups = [];
    foreach ($rows as $r) $groups[$r[0]][] = $r;
?>
    <section class="panel health-panel<?= $loud ? '' : ' is-checking' ?>" id="health-panel">
      <h2 class="section-title">Health check</h2>
      <p class="health-summary"><strong class="health-summary-text <?= $loud ? 'note-text' : 'muted' ?>"><?= $loud ? $esc($loud . ' thing' . ($loud === 1 ? '' : 's') . ' to look at.') : 'Checking…' ?></strong>
        <button type="button" class="secondary health-toggle">Show all checks</button> <button type="button" class="secondary" onclick="location.reload()">Check again</button></p>
      <p class="muted health-intro" style="margin-top:0;">Looks only; nothing is changed (apart from noting the running version).</p>
      <?php foreach ($groups as $g => $items): $allQuiet = true; foreach ($items as $it) if ($it[1] === 'bad' || $it[1] === 'warn') $allQuiet = false; ?>
        <div class="health-group<?= $allQuiet ? ' all-quiet' : '' ?>">
          <h3 class="subhead"><?= $esc($g) ?></h3>
          <?php foreach ($items as [, $status, $label, $detail]): $isLoud = $status === 'bad' || $status === 'warn'; ?>
            <div class="health-row health-<?= $esc($status) ?> <?= $isLoud ? 'loud' : 'quiet' ?>">
              <span class="health-mark <?= $status === 'ok' ? 'ok-text' : ($status === 'bad' ? 'warn-text' : ($status === 'warn' ? 'note-text' : 'muted')) ?>"><?= isset($mark[$status]) ? ui_icon($mark[$status]) : '&bull;' ?></span>
              <div><div class="health-label"><?= $esc($label) ?></div><?php if ($detail !== ''): ?><div class="health-detail"><?= $esc($detail) ?></div><?php endif; ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
      <div class="health-group all-quiet" data-health-group="web">
        <h3 class="subhead">Web server (tested by your browser, just now)</h3>
        <?php foreach ([['lock', 'Direct photo requests refused'], ['tidy', 'Tidy folder links'], ['accel', 'nginx internal redirect']] as [$key, $label]): ?>
          <div class="health-row health-info quiet pending" data-health-row="<?= $key ?>">
            <span class="health-mark muted">&bull;</span>
            <div><div class="health-label"><?= $esc($label) ?></div><div class="health-detail" data-health="<?= $key ?>">Checking&hellip;</div></div>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
<?php
}

/** The thumbnails panel: how many photos have their tile pictures ready, and a button that makes the rest now. */
function render_admin_thumbnails_panel($csrfToken) {
    if (!ENABLE_THUMBNAIL_CACHE) return;
    [$ready, $total] = thumbnail_readiness($GLOBALS['dir'], $GLOBALS['__admin_files'] ?? []);
    $left = $total - $ready;
?>
    <section class="panel warm-panel" id="warm-panel" data-csrf="<?= $csrfToken ?>" data-left="<?= (int) $left ?>">
      <h2 class="section-title">Thumbnails</h2>
      <p class="muted" style="margin-top:0;">A photo's tile pictures are made the first time it is shown, so the first visit to a big folder is slower. Prepare them all now and every visit is quick. It works through the photos as fast as the server allows, with a live percentage, and you can stop whenever you like; what is done stays done.</p>
      <p class="warm-line"><strong class="warm-count <?= $left === 0 ? 'ok-text' : '' ?>"><?= $left === 0 ? 'All ' . number_format($total) . ' photos are ready.' : number_format($ready) . ' of ' . number_format($total) . ' photos are ready.' ?></strong>
        <button type="button" class="secondary warm-go"<?= $left === 0 ? ' hidden' : '' ?>>Prepare the rest</button>
        <button type="button" class="secondary warm-stop" hidden>Stop</button></p>
      <div class="warm-bar" hidden><span></span></div>
    </section>
<?php
}

/** The Site settings panel. */
function render_admin_site_settings($csrfToken) {
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $state = gallery_config_load();
    $path = gallery_config_path();
    $hash = md5(is_file($path) ? (string) file_get_contents($path) : '');
    $defaults = $GLOBALS['__gallery_defaults'] ?? [];
    $fromFile = array_flip(array_unique($GLOBALS['__gallery_config_applied'] ?? []));
    $show = function ($v) { return is_bool($v) ? ($v ? 'on' : 'off') : (is_array($v) ? ($v ? count($v) . ' item' . (count($v) === 1 ? '' : 's') : 'none') : ($v === '' ? 'none' : (string) $v)); };
    $broken = !in_array($state['status'], ['absent', 'ok'], true);
?>
    <section class="panel site-settings">
      <h2 class="section-title">Site settings</h2>
      <p class="muted" style="margin-top:0;">Whole-gallery settings. <strong>Each change saves as you make it</strong>, in <code>.gallery-config.php</code> (it survives replacing <code>index.php</code>). Your photos, captions and stats are never touched.</p>
      <?php if ($broken): ?>
        <div class="flash error">The settings file exists but can't be read as settings, so this screen won't change it (that could overwrite what's in it). Fix or delete <code>.gallery-config.php</code>, then reload.</div>
      <?php endif; ?>
      <?php if (!empty($GLOBALS['__site_settings_manual'])): ?>
        <p class="muted">Create a file named <code>.gallery-config.php</code> next to <code>index.php</code> containing exactly this:</p>
        <textarea readonly rows="12" style="width:100%; font-family:ui-monospace,monospace;" onfocus="this.select()"><?= $esc($GLOBALS['__site_settings_manual']) ?></textarea>
      <?php endif; ?>
      <p class="muted sitesync" hidden>Saved. Other tabs refresh when you leave this one.</p>
      <form method="post" class="site-settings-form">
        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
        <input type="hidden" name="action" value="save_site_settings">
        <input type="hidden" name="config_hash" value="<?= $esc($hash) ?>">
        <?php $groupNo = 0; foreach (gallery_setting_definitions() as $group): $groupNo++; ?>
          <details class="site-group"<?= $groupNo === 1 ? ' open' : '' ?><?= $group['title'] === 'Server setup (advanced)' ? ' id="server-setup"' : '' ?>>
          <summary><span class="site-group-title"><?= $esc($group['title']) ?></span> <span class="muted"><?php $nShown = count(array_filter($group['items'], function ($i) { return empty($i['in']); })); echo $nShown; ?> setting<?= $nShown === 1 ? '' : 's' ?></span></summary>
          <?php foreach ($group['items'] as $d): if (!empty($d['in'])) continue; $name = $d['name']; $now = defined($name) ? constant($name) : ($defaults[$name] ?? null); $def = $defaults[$name] ?? null; ?>
            <?php $reqOn = empty($d['requires']) || (defined($d['requires']) && constant($d['requires'])); ?>
            <div class="site-row<?= !empty($d['requires']) ? ' sub-row' : '' ?>"<?= !empty($d['requires']) ? ' data-requires="set_' . $esc($d['requires']) . '"' . ($reqOn ? '' : ' hidden') : '' ?>>
              <div>
                <div class="name"><?= $esc($d['label']) ?>
                  <?php if (isset($fromFile[$name])): ?><span class="src" title="This value comes from .gallery-config.php">your setting</span><?php endif; ?></div>
                <div class="help"><?= $esc($d['help']) ?></div>
                <?php if (($d['status'] ?? '') === 'ffmpeg'): $ffFound = ffmpeg_binary(); ?>
                <div class="help ffstatus <?= $ffFound !== '' ? 'ok-text' : 'warn-text' ?>"><strong>ffmpeg on this server: <?= $ffFound !== '' ? 'found' : 'not found' ?></strong> <?= $ffFound !== '' ? '(' . $esc($ffFound) . ')' : 'Videos show the browser\'s own first frame. Installed somewhere unusual? Set "FFMPEG_PATH" in .gallery-config.php.' ?></div>
                <?php endif; ?>
                <?php if (($d['status'] ?? '') === 'accel'): $accelBase = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/'); ?>
                <div class="help accel-status" data-accel-status data-accel-current="<?= $esc(NGINX_ACCEL_REDIRECT_PREFIX) ?>" data-accel-candidate="<?= $esc($accelBase) ?>"></div>
                <?php endif; ?>
                <?php if (!empty($d['risk'])): ?><div class="risk"><?= $esc($d['risk']) ?></div><?php endif; ?>
                <?php if (!empty($d['offwarn'])): ?><div class="warn-note offnote" hidden><?= $esc($d['offwarn']) ?></div><?php endif; ?>
              </div>
              <div class="ctl">
                <?php if ($d['type'] === 'bool'): ?>
                  <input type="hidden" name="set_<?= $esc($name) ?>" value="0">
                  <label class="switch"><input type="checkbox" name="set_<?= $esc($name) ?>" value="1" <?= $now ? 'checked' : '' ?> data-initial="<?= $now ? '1' : '0' ?>" data-label="<?= $esc($d['label']) ?>"<?php if (!empty($d['offwarn'])): ?> data-offwarn="<?= $esc($d['offwarn']) ?>"<?php endif; ?>><span class="slider"></span></label>
                <?php elseif ($d['type'] === 'sortfirst'): $sfNames = sort_priority_names(); $sfOrder = sort_priority_list(); $sfOn = []; foreach ($sfNames as $k => $nm) $sfOn[$k] = (bool) constant($nm[2]); $sfOrder = array_merge(array_values(array_filter($sfOrder, function ($k) use ($sfOn) { return $sfOn[$k]; })), array_values(array_filter($sfOrder, function ($k) use ($sfOn) { return !$sfOn[$k]; }))); ?>
                  <div class="sortfirst" data-name="set_<?= $esc($name) ?>" data-order="<?= $esc(implode(',', $sfOrder)) ?>">
                    <ol class="sf-list">
                    <?php foreach ($sfOrder as $k): $nm = $sfNames[$k]; ?>
                      <li data-kind="<?= $esc($k) ?>">
                        <button type="button" class="sf-grip" aria-label="Move <?= $esc($nm[1]) ?>: drag it, or press the up and down arrow keys" title="Drag to reorder"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><g fill="currentColor"><circle cx="9" cy="6" r="1.7"/><circle cx="15" cy="6" r="1.7"/><circle cx="9" cy="12" r="1.7"/><circle cx="15" cy="12" r="1.7"/><circle cx="9" cy="18" r="1.7"/><circle cx="15" cy="18" r="1.7"/></g></svg></button>
                        <span class="sf-name"><?= $esc($nm[0]) ?></span>
                        <input type="hidden" name="set_<?= $esc($nm[2]) ?>" value="0">
                        <label class="switch"><input type="checkbox" name="set_<?= $esc($nm[2]) ?>" value="1" <?= $sfOn[$k] ? 'checked' : '' ?> data-initial="<?= $sfOn[$k] ? '1' : '0' ?>" data-label="<?= $esc($nm[0]) ?> first" aria-label="<?= $esc($nm[0]) ?> first"><span class="slider"></span></label>
                      </li>
                    <?php endforeach; ?>
                    </ol>
                    <p class="sf-summary" aria-live="polite"><?= $esc(sort_priority_summary($sfOrder, $sfOn)) ?></p>
                  </div>
                <?php elseif ($d['type'] === 'enum'): ?>
                  <?= ui_segmented('set_' . $name, $d['options'], ($name === 'PHOTO_SORT_ORDER' && $now === 'mtime') ? 'taken' : $now, $d['label']) ?>
                <?php elseif ($d['type'] === 'text'): ?>
                  <input type="text" name="set_<?= $esc($name) ?>" value="<?= $esc($now) ?>" placeholder="empty = off" spellcheck="false" autocomplete="off" data-label="<?= $esc($d['label']) ?>" style="max-width:180px;">
                <?php elseif ($d['type'] === 'folders'): $extraNamed = (array) EXTRA_FOLDERS; $extraRoots = array_values((array) EXTRA_ROOTS); ?>
                  <div class="extra-folders" data-name="set_<?= $esc($name) ?>" data-map="<?= $esc(json_encode(['named' => (object) $extraNamed, 'roots' => $extraRoots], JSON_HEX_TAG)) ?>" data-label="<?= $esc($d['label']) ?>">
                    <ul class="extra-list"><?php foreach ($extraRoots as $rpath): ?><li><code><?= $esc($rpath) ?></code> <span class="muted">&rarr; its folders are at the top of the gallery</span> <button type="button" class="extra-remove secondary" data-kind="root" data-name="<?= $esc($rpath) ?>">Remove</button></li><?php endforeach; ?><?php foreach ($extraNamed as $fname => $fpath): ?><li><strong><?= $esc($fname) ?></strong> <span class="muted">&rarr;</span> <code><?= $esc($fpath) ?></code> <button type="button" class="extra-remove secondary" data-kind="named" data-name="<?= $esc($fname) ?>">Remove</button></li><?php endforeach; ?></ul>
                    <p class="muted extra-none"<?= ($extraNamed || $extraRoots) ? ' hidden' : '' ?>>None added.</p>
                    <div class="extra-add">
                      <input type="text" class="extra-path" placeholder="/full/path/to/the/place" autocomplete="off" spellcheck="false">
                      <input type="text" class="extra-name" placeholder="Optional: show it as one folder with this name, e.g. OldTrip2020" maxlength="60" autocomplete="off">
                      <button type="button" class="extra-add-btn secondary">Add</button>
                    </div>
                    <p class="hint">Without a name, the folders inside that place appear at the top of the gallery (<code>/space/trip</code> holding <code>Paris</code> and <code>StTropez</code> gives you Paris and StTropez). With a name, the whole place shows as that one folder.</p>
                  </div>
                <?php else: ?>
                  <input type="number" name="set_<?= $esc($name) ?>" min="<?= (int) $d['min'] ?>" max="<?= (int) $d['max'] ?>" step="<?= $esc($d['step'] ?? '1') ?>" value="<?= $esc($now) ?>" style="max-width:110px;">
                <?php endif; ?>
                <span class="meta"><?= $d['type'] === 'sortfirst' ? 'default: all off, private then videos then located' : 'default: ' . $esc($show($def)) ?></span>
                <span class="rowstate" role="status" aria-live="polite"></span>
              </div>
            </div>
          <?php endforeach; ?>
          <?php if ($group['title'] === 'Server setup (advanced)'): ?>
            <div class="site-rules"><div class="name">Keep private photos from being fetched by address</div><div class="lock-result" data-lock-status></div><div class="help lock-help">Making something private only stops the gallery from showing it; the file can still be fetched by its direct address unless your web server is told not to. Rules to copy:</div><?= render_server_rules_help(true) ?></div>
          <?php endif; ?>
          </details>
        <?php endforeach; ?>

        <details class="site-group">
        <summary><span class="site-group-title">Passwords</span> <span class="muted">admin and private view</span></summary>
        <p class="muted" style="margin:0 0 6px;">Stored as hashes, so they can't be shown again. Forgotten? Delete the <code>ADMIN_PASSWORD</code> line in <code>.gallery-config.php</code>, add an empty file <code>.gallery-setup</code> beside <code>index.php</code>, and open <code>?admin</code>.</p>
        <div class="site-row">
          <div>
            <div class="name">Admin password <?php if (isset($fromFile['ADMIN_PASSWORD'])): ?><span class="src">your setting</span><?php endif; ?></div>
            <div class="help">Set. Leave blank to keep it; to change it use 8+ characters with a letter and a number. It can't be removed here.</div>
          </div>
          <div class="ctl col">
            <input type="password" name="pw_admin" autocomplete="new-password" placeholder="New admin password">
            <input type="password" name="pw_admin2" autocomplete="new-password" placeholder="Type it again">
            <span class="pwline"><button type="button" class="pw-btn secondary" data-pw="admin">Change admin password</button><span class="rowstate" role="status" aria-live="polite"></span></span>
          </div>
        </div>
        <div class="site-row">
          <div>
            <div class="name">Private-view password <?php if (isset($fromFile['PRIVATE_VIEW_PASSWORD'])): ?><span class="src">your setting</span><?php endif; ?></div>
            <div class="help"><?= PRIVATE_VIEW_PASSWORD === '' ? 'Not set: only you can see private items.' : 'Set.' ?> Give it to people who may see private items. Leave blank to keep it.</div>
            <?php if (PRIVATE_VIEW_PASSWORD !== ''): ?><div class="warn-note">Removing it ends private view for everyone you gave it to. Nothing is deleted.</div><?php endif; ?>
          </div>
          <div class="ctl col">
            <input type="password" name="pw_private" autocomplete="new-password" placeholder="New private-view password">
            <input type="password" name="pw_private2" autocomplete="new-password" placeholder="Type it again">
            <span class="pwline"><button type="button" class="pw-btn secondary" data-pw="private">Set private-view password</button><span class="rowstate" role="status" aria-live="polite"></span></span>
            <?php if (PRIVATE_VIEW_PASSWORD !== ''): ?><span class="pwline"><button type="button" class="pw-btn secondary" data-pw="private-remove">Remove it (turn private view off)</button><span class="rowstate" role="status" aria-live="polite"></span></span>
            <label class="muted nojs-only"><input type="checkbox" name="pw_private_remove" value="1"> Remove it (turn private view off)</label><?php endif; ?>
          </div>
        </div>

        </details>

        <div class="item-actions nojs-only" style="margin-top:14px;">
          <button type="submit"<?= $broken ? ' disabled' : '' ?>>Save site settings</button>
        </div>
        <p class="muted nojs-only" style="margin-top:14px;">Only settings that differ from the defaults are stored.</p>
      </form>
    </section>
<?php
}

/**
 * Renders the full ?admin page: folder nav, this folder's own identity
 * (display name/cover/location toggle, when viewing a subfolder), every
 * .md here (active or not) with a raw-text editor, and the photo/video
 * grid itself — caption, location, web link per item, usable at a phone
 * width same as the public gallery already holds itself to.
 */
function render_admin_page($dir, $files, $adminFolderPath, $adminFolderReal, $adminFiles, $groups, $folderGroupKeys, $explicitGroups, $explicitGroupKeys, $videoDurations, $mdCandidates, $resultMessage) {
    $version = htmlspecialchars(GALLERY_VERSION, ENT_QUOTES, 'UTF-8');
    $csrfToken = htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8');
    $folderLabel = $adminFolderPath ?? 'Root';
    import_folder_gps($dir, $adminFolderReal, $adminFiles);
    $adminHidden = admin_any_hidden($dir, $files, $folderGroupKeys) ? admin_hidden_data($dir, $files) : null;
    $GLOBALS['__lock_probe'] = lock_probe_targets($dir, $files);
    $GLOBALS['__admin_files'] = $files;
    $itemsHere = items_read($adminFolderReal);
    // Photos whose GPS the gallery can read from the file but that have no
    // *saved* location yet (and haven't been read before). With the
    // folder's switch on they were just imported above, so these are only
    // left when the switch is off — the editor pre-fills them for review,
    // and each is flagged below and counted in the folder panel.
    $adminAutoLoc = [];
    foreach ($adminFiles as $adminFile) {
        $adminEntry = $itemsHere['items'][basename($adminFile)] ?? [];
        if (($adminEntry['location'] ?? null) !== null || !empty($adminEntry['gpsChecked'])) continue;
        $autoLoc = extract_photo_gps(resolve_real_path($dir, $adminFile));
        if ($autoLoc !== null) $adminAutoLoc[$adminFile] = $autoLoc;
    }
    // The warning shown beside the location switch (turning it on publishes places, so say how many and how to keep one back).
    $gpsCount = count($adminAutoLoc);
    $locWarn = 'Turning this on shows the location of ' . ($gpsCount > 0
        ? $gpsCount . ' photo' . ($gpsCount === 1 ? '' : 's') . ' here'
        : 'any photo here that has one')
        . ' to visitors, on the gallery and the map. A photo can opt out with its own Location switch (press its pencil). You and private-view visitors always see every location.';
    $mdScan = scan_gallery_md_files($adminFolderReal);
    $activeMd = array_fill_keys($mdScan['files'], true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Gallery Admin — v<?= $version ?></title>
<style>
  :root { --bg:#0f0f10; --card:#1a1a1c; --text:#eaeaea; --muted:#8a8a8f; --accent:#5865f2; --ok:#3ba55c; --err:#ed4245; }
  * { box-sizing: border-box; }
  body { margin:0; background:var(--bg); color:var(--text); font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; line-height:1.5; }
  .wrap { max-width:960px; margin:0 auto; padding:24px 16px 64px; }  /* (hidden-items page) */
  a { color:#aab2ff; text-decoration:none; }
  h1 { font-size:1.4rem; margin:0 0 4px; }
  h2 { font-size:1.1rem; margin:28px 0 10px; }
  .muted { color:var(--muted); font-size:0.85rem; }
  /* Layout: a sticky bar, a folder tree beside the content, and sections
     you can jump to, so a long page is never a scroll hunt. */
  <?= ui_icon_css() ?>
  .admin-bar { position:sticky; top:0; z-index:20; background:rgba(15,15,16,0.94); backdrop-filter:blur(6px); border-bottom:1px solid #26262a; }
  .admin-bar-inner { max-width:1180px; margin:0 auto; padding:0 16px; min-height:56px; display:flex; align-items:center; gap:14px; }
  .admin-bar h1 { font-size:1.05rem; margin:0; white-space:nowrap; }
  .admin-bar .crumb { color:var(--muted); font-size:0.85rem; margin-right:auto; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .admin-bar a.back { color:var(--muted); text-decoration:none; font-size:0.85rem; white-space:nowrap; }
  .admin-bar .logout-form { margin:0; flex:none; }
  .admin-tabs { display:flex; align-self:stretch; gap:2px; overflow-x:auto; scrollbar-width:none; flex:none; }
  .admin-tabs::-webkit-scrollbar { display:none; }
  .admin-tabs a { color:var(--muted); text-decoration:none; font-size:0.9rem; padding:0 14px; border-top:3px solid transparent; border-bottom:3px solid transparent; white-space:nowrap; display:flex; align-items:center; gap:7px; }
  .admin-tabs a:hover { color:var(--text); background:rgba(255,255,255,0.04); }
  .admin-tabs a.active { color:#fff; background:rgba(88,101,242,0.16); border-bottom-color:var(--accent); font-weight:600; }
  .admin-tabs a .count, .side-hidden .count { font-size:0.72rem; font-weight:600; background:rgba(255,255,255,0.09); color:var(--muted); padding:1px 7px; border-radius:10px; }
  .admin-tabs a.active .count { background:rgba(88,101,242,0.45); color:#fff; }
  /* Hidden is about the whole tree, not the folder being edited, so it
     lives with the places in the sidebar, above Root, in red. */
  .side-hidden { display:flex; align-items:center; justify-content:space-between; gap:8px; margin:0 0 10px; padding:6px 10px 6px 11px; border-radius:6px; font-size:0.85rem; color:#ff7b7d; text-decoration:none; background:rgba(237,66,69,0.08); border:1px solid rgba(237,66,69,0.25); }
  .side-hidden .count { background:rgba(237,66,69,0.25); color:#ff9a9c; }
  .side-hidden:hover { background:rgba(237,66,69,0.14); }
  .side-hidden.active { color:#fff; font-weight:600; background:rgba(237,66,69,0.3); border-color:var(--err); }
  .side-hidden.active .count { background:rgba(237,66,69,0.7); color:#fff; }
  .js-tabs .tab-panel { display:none; }
  .js-tabs .tab-panel.active { display:block; }
  .tab-panel > .panel + .panel, .tab-panel > .flash + .panel { margin-top:0; }
  .hidden-list { margin:0; padding-left:20px; }
  .hidden-list li { margin-bottom:6px; font-size:0.9rem; }
  .hidden-list a { color:#aab2ff; }
  .admin-layout { max-width:1180px; margin:0 auto; padding:18px 16px 64px; display:grid; grid-template-columns:210px minmax(0,1fr); gap:22px; align-items:start; }
  .admin-side { position:sticky; top:76px; max-height:calc(100vh - 92px); overflow-y:auto; }
  .admin-folders > summary { font-size:0.72rem; text-transform:uppercase; letter-spacing:0.06em; color:var(--muted); margin:0 0 8px; padding-left:12px; cursor:pointer; list-style:none; }
  .admin-side .muted { padding-left:12px; }
  .admin-folders > summary::-webkit-details-marker { display:none; }
  .admin-folders > summary, .site-group > summary { position:relative; padding-right:30px; }
  .admin-folders > summary::after, .site-group > summary::after { content:""; position:absolute; right:12px; top:50%; width:7px; height:7px; border-right:2px solid var(--muted); border-bottom:2px solid var(--muted); transform:translateY(-70%) rotate(45deg); transition:transform .15s; }
  .admin-folders[open] > summary::after, .site-group[open] > summary::after { transform:translateY(-30%) rotate(-135deg); }
  .admin-folders > summary:hover::after, .site-group > summary:hover::after { border-color:var(--text); }
  .site-group > summary { list-style:none; }
  .site-group > summary::-webkit-details-marker { display:none; }
  .admin-folders > summary .here { text-transform:none; letter-spacing:0; display:none; color:var(--text); }
  .admin-folder-nav ul { list-style:none; margin:0; padding:0; }
  .admin-folder-nav li { position:relative; margin:1px 0; }
  /* A level in: a guide line down the left, so a child clearly belongs to its parent. */
  .admin-folder-nav li > ul { margin-left:15px; border-left:1px solid #34343a; }
  .admin-folder-nav a { display:flex; align-items:center; justify-content:space-between; gap:8px; color:var(--muted); text-decoration:none; padding:5px 8px 5px 12px; border-radius:6px; font-size:0.85rem; }
  .admin-folder-nav a .fname { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; min-width:0; }
  .admin-folder-nav a .fcount { flex:none; font-size:0.72rem; color:#6f6f78; font-variant-numeric:tabular-nums; }
  .admin-folder-nav a:hover { color:var(--text); background:var(--card); }
  .admin-folder-nav a:hover .fcount { color:var(--muted); }
  .admin-folder-nav a.current { color:#fff; background:var(--accent); font-weight:600; }
  .admin-folder-nav a.current .fcount { color:rgba(255,255,255,0.8); font-weight:500; }
  /* While Site settings (or Private) is the section on show, the folder you came from is only marked, not lit like the selection. */
  .admin-layout.off-folder .admin-folder-nav a.current { color:var(--text); background:rgba(255,255,255,0.05); font-weight:500; }
  .admin-layout.off-folder .admin-folder-nav a.current .fcount { color:#6f6f78; }
  /* The fold chevron sits in the gutter left of the name, so names stay in one column. */
  .admin-folder-nav .ftoggle { position:absolute; left:-1px; top:5px; width:14px; height:22px; padding:0; border:0; background:transparent; color:var(--muted); cursor:pointer; z-index:1; border-radius:4px; }
  .admin-folder-nav .ftoggle::before { content:"\25BE"; font-size:0.7rem; display:block; line-height:22px; text-align:center; transition:transform .12s; }
  .admin-folder-nav li.collapsed > .ftoggle::before { transform:rotate(-90deg); }
  .admin-folder-nav .ftoggle:hover { color:var(--text); background:rgba(255,255,255,0.08); }
  .admin-folder-nav li.collapsed > ul { display:none; }
  .admin-main { min-width:0; }
  .admin-main section, .admin-main details.panel { scroll-margin-top:84px; }
  .admin-main h2.section-title { font-size:1.05rem; margin:0 0 12px; }
  .admin-main details.panel > summary { cursor:pointer; font-weight:600; font-size:1.05rem; list-style:none; display:flex; align-items:center; gap:10px; }
  .admin-main details.panel > summary::-webkit-details-marker { display:none; }
  .admin-main details.panel > summary::before { content:"\25B8"; color:var(--muted); transition:transform .15s; }
  .admin-main details.panel[open] > summary::before { transform:rotate(90deg); }
  .admin-main details.panel > summary .count { font-weight:400; font-size:0.8rem; color:var(--muted); }
  .admin-main details.panel[open] > summary { margin-bottom:12px; }
  details.subtle { margin-top:10px; }
  details.subtle > summary { cursor:pointer; color:var(--muted); font-size:0.85rem; }
  .hint { color:var(--muted); font-size:0.75rem; margin:3px 0 0; }
  h3.subhead { font-size:0.72rem; text-transform:uppercase; letter-spacing:0.06em; color:var(--muted); margin:18px 0 8px; font-weight:600; }
  .settings-grid { display:grid; grid-template-columns:2fr 1fr 1fr; gap:10px 14px; }
  .settings-grid.root { grid-template-columns:2fr 1fr; }
  .settings-grid .wide { grid-column:1 / -1; }
  .privacy-row { display:flex; flex-wrap:wrap; gap:18px; align-items:center; }
  .privacy-row form { margin:0; }
  .hidden-warning { font-size:0.9rem; line-height:1.55; }
  .lock-result { margin-top:6px; font-size:0.85rem; }
  .lock-result:empty { display:none; }
  /* Links in the admin's running text: the teal of the sidebar link, not the browser's default blue. */
  .admin-main p a, .admin-main li a, .admin-main .help a, .admin-main .muted a { color:#aab2ff; border-radius:3px; transition:color .15s, background .15s, box-shadow .15s; }
  .admin-main p a:hover, .admin-main li a:hover, .admin-main .help a:hover, .admin-main .muted a:hover { color:#e1e4ff; background:rgba(170,178,255,0.16); box-shadow:0 0 0 3px rgba(170,178,255,0.16); border-radius:3px; }
  .admin-main .flash a, .admin-main .flash a:hover { color:inherit; }
  .admin-main .flash a:hover { background:rgba(255,255,255,0.14); box-shadow:0 0 0 3px rgba(255,255,255,0.14); }
  .subhead { scroll-margin-top:70px; }
  .site-group.flash-target { outline:2px solid var(--accent); outline-offset:3px; }
  .subhead.flash-target { outline:2px solid var(--accent); outline-offset:4px; border-radius:4px; }
  .note-text, .help.note-text, .site-row .help.note-text { color:#f5a623; }
  .health-row { display:flex; gap:10px; align-items:flex-start; padding:6px 0; border-top:1px solid #26262a; font-size:0.88rem; }
  .health-row:first-of-type { border-top:0; }
  .health-mark { flex:none; width:1.3em; text-align:center; line-height:1.5; }
  .health-label { font-weight:600; }
  .health-detail { color:var(--muted); font-size:0.82rem; overflow-wrap:anywhere; }
  .health-summary { margin:0 0 6px; display:flex; flex-wrap:wrap; align-items:center; gap:8px; }
  .site-group { scroll-margin-top:70px; margin:0 0 10px; border:1px solid #26262a; border-radius:9px; padding:0 12px; }
  .site-group[open] { padding-bottom:6px; }
  .site-group > summary { cursor:pointer; padding:10px 30px 10px 0; font-weight:600; font-size:0.95rem; }
  .site-group > summary .muted { font-weight:400; font-size:0.8rem; margin-left:6px; }
  /* Choices drawn as pills: the chosen one is lit, the others stay quiet. (Radio buttons underneath.) */
  .seg { display:inline-flex; flex-wrap:wrap; gap:6px; max-width:100%; }
  .seg > label { position:relative; display:block; margin:0; cursor:pointer; font-size:0.85rem; }
  .seg > label input { position:absolute; inset:0; width:100%; height:100%; margin:0; opacity:0; cursor:pointer; }
  .seg > label span { display:block; padding:6px 14px; border:1px solid #34343a; border-radius:999px; color:var(--muted); background:transparent; white-space:nowrap; transition:background .12s, color .12s, border-color .12s; }
  .seg > label:hover span { color:var(--text); border-color:#4a4a52; }
  .seg > label input:checked + span { background:var(--accent); border-color:var(--accent); color:#fff; }
  .seg > label input:focus-visible + span { outline:2px solid #fff; outline-offset:2px; }
  input[type=number] { -moz-appearance:textfield; appearance:textfield; }
  input[type=number]::-webkit-inner-spin-button, input[type=number]::-webkit-outer-spin-button { -webkit-appearance:none; margin:0; }
  .site-row input[type=number], .settings-grid input[type=number], .map-sub input[type=number] { width:96px; max-width:100%; }
  .export-cards { display:flex; flex-wrap:wrap; gap:10px; margin-top:10px; }
  .export-card { flex:1 1 220px; display:flex; flex-direction:column; align-items:flex-start; gap:2px; padding:12px 14px; border:1px solid #2c2c30; border-radius:10px; }
  .export-card .export-scope { font-size:0.72rem; text-transform:uppercase; letter-spacing:0.06em; color:var(--muted); }
  .export-card .export-name { font-weight:600; margin:0 0 8px; overflow-wrap:anywhere; }
  .export-card.export-all { border-color:rgba(88,101,242,0.55); background:rgba(88,101,242,0.08); }
  .export-card.export-all .export-scope { color:#aab2ff; }
  .md-actions { margin-top:8px; align-items:flex-end; }
  .md-actions .field { max-width:260px; margin-bottom:0; }
  .sort-rows { margin-top:8px; }
  .sort-row { display:flex; flex-wrap:wrap; align-items:center; gap:4px 12px; margin:0 0 8px; }
  .sort-label { min-width:96px; font-size:0.85rem; color:var(--muted); }
  .sort-row-list { align-items:flex-start; }
  .sort-row-list .sort-label { padding-top:9px; }
  .sortfirst .seg { margin:0 0 8px; }
  .sort-details > summary { cursor:pointer; font-size:0.85rem; color:var(--muted); margin:0 0 6px; }
  .sort-details > summary .muted { font-size:0.8rem; }
  .sort-fields { display:flex; flex-wrap:wrap; gap:8px; margin-top:8px; }
  .sort-fields select { max-width:100%; }
  .health-panel:not(.show-all) .health-row.quiet, .health-panel:not(.show-all) .health-group.all-quiet, .health-panel:not(.show-all) .health-intro { display:none; }
  .health-panel.is-checking .health-toggle { display:none; }
  .health-toggle { padding:3px 12px; font-size:0.8rem; }
  .warm-line { display:flex; flex-wrap:wrap; align-items:center; gap:10px; margin:0 0 8px; }
  .warm-bar { height:8px; border-radius:4px; background:#26262a; overflow:hidden; }
  .warm-bar span { display:block; height:100%; width:0; background:#5865f2; transition:width .4s ease-out; }
  .warm-count { font-variant-numeric:tabular-nums; }
  .health-mark .ico { width:1.1em; height:1.1em; }
  .health-summary .secondary { padding:3px 12px; font-size:0.8rem; }
  .accel-status:empty { display:none; }
  .ok-text, .help.ok-text, .site-row .help.ok-text { color:var(--ok); }
  .warn-text, .help.warn-text, .site-row .help.warn-text { color:var(--err); }
  .linklike { background:none; border:0; padding:0; color:inherit; font:inherit; text-decoration:none; border-radius:3px; cursor:pointer; transition:background .15s, box-shadow .15s; }
  .linklike:hover { background:rgba(255,255,255,0.14); box-shadow:0 0 0 3px rgba(255,255,255,0.14); }
  .lock-recheck { margin-top:8px; padding:3px 12px; font-size:0.8rem; }
  [hidden] { display:none !important; }
  @media (max-width: 960px) {
    .admin-bar-inner { flex-wrap:wrap; gap:0 10px; padding:8px 12px 0; }
    .admin-bar .crumb { flex:1 1 0; }
    .admin-tabs { order:5; flex-basis:100%; align-self:auto; margin-top:6px; }
    .admin-tabs a { padding:10px 12px; }
    .admin-side { top:112px; max-height:calc(100vh - 128px); }
    .admin-main section, .admin-main details.panel { scroll-margin-top:116px; }
  }
  @media (max-width: 800px) {
    .admin-folders > summary { background:var(--card); padding:9px 12px; border-radius:8px; font-size:0.85rem; text-transform:none; letter-spacing:0; color:var(--muted); margin:0; }
    .admin-folders > summary .here { display:inline; }
    .admin-folders[open] > summary { margin-bottom:6px; }
    .admin-layout { grid-template-columns:1fr; gap:12px; }
    .admin-side { position:static; max-height:none; }
    .admin-folder-nav { max-height:260px; overflow-y:auto; }
    .settings-grid, .settings-grid.root { grid-template-columns:1fr; }
  }
  .flash { padding:10px 14px; border-radius:8px; margin:14px 0; font-size:0.9rem; }
  .flash.ok { background:rgba(59,165,92,0.15); color:var(--ok); }
  .flash.error { background:rgba(237,66,69,0.15); color:var(--err); }
  .flash a { color:inherit; font-weight:600; text-decoration:none; border-radius:3px; }
  .panel { background:var(--card); border-radius:10px; padding:16px; margin-bottom:16px; }
  label { display:block; font-size:0.8rem; color:var(--muted); margin-bottom:3px; }
  input[type=text], input[type=url], input[type=number], textarea, select {
    color-scheme:dark; width:100%; background:#101012; border:1px solid #2a2a2e; color:var(--text);
    border-radius:6px; padding:7px 9px; font-size:0.9rem; font-family:inherit;
  }
  textarea { min-height:160px; font-family:ui-monospace,SFMono-Regular,Menlo,monospace; resize:vertical; }
  .field { margin-bottom:10px; }
  .field-row { display:flex; gap:10px; flex-wrap:wrap; }
  .field-row .field { flex:1; min-width:140px; }
  button, .btn { background:var(--accent); color:#fff; border:none; border-radius:6px; padding:7px 14px; font-size:0.85rem; cursor:pointer; }
  button.secondary { background:#2a2a2e; color:var(--text); }
  button.danger { background:var(--err); }
  .md-file { border-top:1px solid #26262a; padding:14px 0; }
  .md-file:first-child { border-top:none; }
  .md-file-head { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
  .md-badge { font-size:0.72rem; padding:2px 8px; border-radius:10px; }
  .md-badge.active { background:rgba(59,165,92,0.2); color:var(--ok); }
  .md-badge.inactive { background:rgba(255,255,255,0.08); color:var(--muted); }
  /* The notes reference sits beside the editors and stays in view while you type. */
  .notes-layout { display:grid; grid-template-columns:minmax(0,1fr) 400px; gap:18px; align-items:start; }
  .notes-reference { position:sticky; top:76px; max-height:calc(100vh - 92px); overflow-y:auto; background:var(--card); border-radius:10px; padding:16px 18px; font-size:0.85rem; line-height:1.55; }
  .notes-reference h2 { margin:0 0 8px; }
  .notes-reference h3 { font-size:0.74rem; text-transform:uppercase; letter-spacing:0.06em; color:var(--muted); margin:20px 0 8px; font-weight:600; }
  .notes-reference p { margin:0 0 10px; }
  .notes-reference ul, .notes-reference ol { margin:0 0 10px; padding-left:20px; }
  .notes-reference li { margin-bottom:5px; }
  .notes-reference code { background:#101012; padding:1px 6px; border-radius:4px; font-size:0.78rem; overflow-wrap:anywhere; }
  /* In a side column, each row reads better as the syntax on top and what it does underneath. */
  .notes-reference .ref-table, .notes-reference .ref-table tbody, .notes-reference .ref-table tr, .notes-reference .ref-table td { display:block; width:100%; }
  .notes-reference .ref-table tr { padding:9px 0; border-top:1px solid #26262a; }
  .notes-reference .ref-table tr:first-child { border-top:0; padding-top:0; }
  .notes-reference .ref-table td { padding:0; }
  .notes-reference .ref-table td:first-child { margin-bottom:5px; }
  .notes-reference .ref-table td:last-child { color:#cfcfd4; }
  .notes-reference pre.ref-example { background:#101012; border-radius:6px; padding:8px 10px; margin:6px 0 10px; font-size:0.78rem; white-space:pre-wrap; overflow-wrap:anywhere; }
  .admin-insert-btn { background:#2a2a2e; color:var(--text); font-size:0.78rem; padding:4px 9px; margin-bottom:4px; }
  .ref-tabs { display:flex; flex-wrap:wrap; gap:4px; margin:0 0 4px; padding-bottom:10px; border-bottom:1px solid #26262a; }
  .ref-tabs button { background:#202024; color:var(--muted); border:1px solid transparent; border-radius:6px; padding:5px 10px; font-size:0.78rem; }
  .ref-tabs button:hover { color:var(--text); }
  .ref-tabs button.active { background:var(--accent); color:#fff; }
  .notes-reference.js-ref .ref-panel { display:none; }
  .notes-reference.js-ref .ref-panel.active { display:block; }
  .notes-reference .ref-panel:first-of-type h3 { margin-top:14px; }
  .notes-reference .ref-steps { padding-left:22px; }
  .notes-reference .ref-cards { display:flex; flex-direction:column; gap:8px; margin:0 0 10px; }
  .notes-reference .ref-card { background:#18181b; border:1px solid #26262a; border-radius:8px; padding:9px 11px; }
  .notes-reference .ref-card p { margin:5px 0 0; color:#cfcfd4; }
  .notes-reference .ref-card-head { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
  .notes-reference .ref-card-head .admin-insert-btn { margin:0; }
  .notes-reference button.linklike { background:none; color:var(--accent); padding:0 2px; font-size:inherit; text-decoration:none; }
  @media (max-width: 1100px) { .notes-layout { grid-template-columns:1fr; } .notes-reference { position:static; max-height:none; } }
  /* The left column's text all starts at the same place: 12px in, counting a pill's 1px border, so the pills,
     the FOLDERS label and every folder name line up. Site settings is teal (blue is "selected", red is Hidden). */
  .side-link { display:flex; align-items:center; margin:0 0 10px; padding:6px 10px 6px 11px; border-radius:6px; font-size:0.85rem; color:var(--text); text-decoration:none; background:#1a1a1e; border:1px solid #2c2c30; }
  .side-link:hover { background:#222228; border-color:#3a3a42; }
  .side-link.active { color:#fff; font-weight:600; background:var(--accent); border-color:var(--accent); }
  .site-row { display:grid; grid-template-columns:minmax(0,1fr) minmax(190px,260px); gap:6px 22px; padding:12px 0; border-top:1px solid #26262a; align-items:start; }
  .site-settings h3.subhead + .site-row, .site-settings .muted + .site-row { border-top:0; }
  .rules-help details { border:1px solid #2c2c30; border-radius:8px; padding:8px 12px; margin:8px 0; background:#18181b; }
  .rules-help summary { cursor:pointer; }
  .rules-help p { font-size:0.82rem; color:#cfcfd4; margin:8px 0; }
  .rules-help p.rules-sub { margin:16px 0 4px; color:var(--muted); }
  .rules-box { position:relative; }
  .rules-box pre { background:#101012; border-radius:6px; padding:10px 12px; margin:6px 0; overflow:auto; font-size:0.78rem; line-height:1.45; }
  .rules-box .copy-rules { position:absolute; top:12px; right:10px; padding:3px 10px; font-size:0.75rem; }
  .site-rules { padding:12px 0; border-top:1px solid #26262a; }
  .site-rules .name { font-weight:600; font-size:0.92rem; }
  .site-rules .help { color:var(--muted); font-size:0.8rem; margin-top:3px; }
  .site-row[hidden], .map-sub[hidden] { display:none; }
  .map-sub { margin:4px 0 10px 26px; padding-left:16px; border-left:2px solid #2c2c30; }
  .map-sub .hint { max-width:none; margin:2px 0 0; }
  /* A setting that only matters when another is on sits under it, set in, so it reads as a part of it. */
  .site-row.sub-row { margin-left:26px; padding-left:16px; border-left:2px solid #2c2c30; border-top:0; }
  .site-row .name { font-weight:600; font-size:0.92rem; }
  .site-row .src { font-weight:500; font-size:0.68rem; color:#9bb0ff; background:rgba(88,101,242,0.18); padding:1px 7px; border-radius:9px; margin-left:6px; }
  .site-row .help { color:var(--muted); font-size:0.8rem; margin-top:3px; }
  .site-row .risk { color:#f5a623; font-size:0.78rem; margin-top:4px; }
  .site-row .risk::before { content:"\26A0\FE0E  "; }
  .site-row .ctl { display:flex; align-items:center; justify-content:flex-end; gap:12px; flex-wrap:wrap; }
  .site-row .ctl.col { flex-direction:column; align-items:stretch; gap:6px; }
  .site-row .meta { font-size:0.72rem; color:var(--muted); }
  .switch { position:relative; display:inline-block; width:42px; height:24px; margin:0; }
  .switch input { opacity:0; width:0; height:0; }
  .switch .slider { position:absolute; inset:0; background:#3a3a42; border-radius:24px; cursor:pointer; transition:background .15s; }
  .switch .slider::before { content:""; position:absolute; height:18px; width:18px; left:3px; top:3px; background:#fff; border-radius:50%; transition:transform .15s; }
  .switch input:checked + .slider { background:var(--accent); }
  .switch input:checked + .slider::before { transform:translateX(18px); }
  .switch input:focus-visible + .slider { outline:2px solid #fff; outline-offset:2px; }
  @media (max-width: 800px) {
    .admin-main .site-row { grid-template-columns:1fr; }
    .admin-main .site-row .ctl { justify-content:flex-start; }
  }
  /* The status text has its own reserved space, so a switch doesn't shift sideways while it says "Saved."; an error gets a line of its own. */
  .warn-note { color:#f5a623; font-size:0.78rem; margin:6px 0 10px; }
  .warn-note::before { content:"\26A0\FE0E  "; }
  .instant-row { display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
  .instant-label { font-size:0.9rem; }
  .instant-form { margin:0 0 6px; }
  .rowstate { font-size:0.78rem; color:var(--muted); min-width:3.6em; }
  .rowstate:empty { display:inline-block; }
  .rowstate.ok { color:var(--ok); }
  .rowstate.err { color:var(--err); flex-basis:100%; text-align:right; min-width:0; }
  .rowstate.busy { color:#aab2ff; }
  /* The folder settings save by themselves: each field says so beside its label, and an error sits on its own line under it. */
  .identity-form .field > label .rowstate, .identity-form summary .rowstate { margin-left:8px; min-width:0; }
  .identity-form .rowstate.err { display:block; margin:1px 0 3px; text-align:left; }
  .identity-form input.bad, .identity-form textarea.bad { border-color:var(--err); }
  .priv-folder-form { margin:0 0 6px; }
  .priv-folder-note { margin:2px 0 12px 54px; max-width:68ch; line-height:1.45; }
  .priv-folder-note strong { color:var(--text); }
  @media (max-width: 600px) {
    /* On a phone a switch keeps its label beside it (a long label wraps within its own column) and its status goes under the label. */
    .instant-row { display:grid; grid-template-columns:42px minmax(0,1fr); column-gap:12px; row-gap:0; align-items:center; }
    .instant-row .rowstate { grid-column:2; min-width:0; }
    .instant-row .rowstate.err { text-align:left; }
  }
  .map-under { margin:2px 0 10px 14px; padding-left:14px; border-left:2px solid #2c2c30; }
  .map-under .map-form { transition:opacity .15s; }
  .map-under.no-loc .map-form { opacity:0.55; }
  .map-under .map-loc-note { display:none; margin:6px 0 4px; }
  .map-under.no-loc .map-loc-note { display:block; }
  .map-under .map-sub { margin-left:26px; }
  .admin-modal { position:fixed; inset:0; z-index:200; display:flex; align-items:center; justify-content:center; padding:20px; background:rgba(0,0,0,0.62); }
  .admin-modal[hidden] { display:none; }
  .admin-modal-card { width:min(420px,100%); background:var(--card); border:1px solid #34343a; border-radius:14px; padding:18px; box-shadow:0 12px 40px rgba(0,0,0,0.6); }
  .admin-modal-card h3 { margin:0 0 10px; font-size:1.15rem; }
  .pm-row { display:flex; align-items:center; gap:9px; margin:4px 0; font-size:0.95rem; }
  .pm-row .ico { width:1.2em; height:1.2em; flex:none; }
  .pm-stay .ico { color:#ff7a7c; } .pm-open .ico { color:var(--ok); }
  .pm-btns { display:flex; gap:8px; margin-top:16px; } .pm-btns button { flex:1; padding:10px 14px; font-size:0.9rem; }
  .pwline .rowstate.err { text-align:left; }
  .pwline { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
  .pw-btn, .js-only { display:none; }
  .js-tabs .pw-btn { display:inline-block; }
  .js-tabs .js-only { display:inline; }
  .js-tabs .nojs-only { display:none !important; }
  .manual-box { margin:0 0 14px; }
  .site-row .ctl:has(.sortfirst) { flex-direction:column; align-items:stretch; justify-content:flex-start; }
  .sortfirst { width:100%; max-width:420px; }
  .sf-list { list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:6px; }
  .sf-list li { display:flex; align-items:center; gap:8px; padding:6px 10px; background:#1a1a1e; border:1px solid #2c2c30; border-radius:8px; }
  .sf-grip { color:var(--muted); cursor:grab; display:inline-flex; align-items:center; justify-content:center; width:34px; height:34px; margin:-6px 0 -6px -8px; padding:0; background:none; border:0; border-radius:6px; touch-action:none; -webkit-tap-highlight-color:transparent; }
  @media (hover: hover) { .sf-grip:hover { color:var(--text); background:rgba(255,255,255,0.06); } }
  .sf-grip:focus-visible { color:var(--text); background:rgba(255,255,255,0.06); outline:2px solid var(--accent); outline-offset:1px; }
  .sf-grip:focus:not(:focus-visible) { outline:none; }
  .sf-grip.rest, .sf-grip.rest:hover { color:var(--muted); background:none; }
  .sf-list li { transition:transform .12s; }
  .sf-list.is-dragging li { user-select:none; -webkit-user-select:none; }
  .sf-list li.dragging { transition:none; opacity:1; background:#24242a; border-color:var(--accent); box-shadow:0 6px 18px rgba(0,0,0,0.45); position:relative; z-index:2; }
  .sf-list li.dragging .sf-grip { cursor:grabbing; }
  .sf-name { flex:1; font-size:0.9rem; }
  .sf-summary { margin:8px 0 0; font-size:0.82rem; color:var(--muted); }
  .site-row .ctl:has(.extra-folders) { flex-direction:column; align-items:stretch; justify-content:flex-start; }
  .extra-folders { width:100%; }
  .extra-list { list-style:none; margin:0 0 8px; padding:0; }
  .extra-list li { display:flex; align-items:center; gap:8px; flex-wrap:wrap; padding:6px 0; border-top:1px solid #26262a; font-size:0.85rem; }
  .extra-list li:first-child { border-top:0; }
  .extra-list code { word-break:break-all; }
  .extra-add { display:flex; flex-direction:column; gap:6px; }
  @media (max-width: 600px) {
    .wrap { padding:16px 10px 48px; }
    .grid { grid-template-columns:1fr; }
  }
</style>
</head>
<body>
<?= ui_icon_sprite() ?>
<header class="admin-bar">
  <div class="admin-bar-inner">
    <?php // Back to the page you were editing: a folder's admin returns to that folder, not to the front page. ?>
    <a class="back" href="<?= htmlspecialchars($adminFolderPath === null ? gallery_root_href() : '?folder=' . rawurlencode($adminFolderPath), ENT_QUOTES, 'UTF-8') ?>">&larr; Gallery</a>
    <h1>Gallery Admin</h1>
    <span class="crumb">Editing: <?= htmlspecialchars($folderLabel, ENT_QUOTES, 'UTF-8') ?></span>
    <nav class="admin-tabs" role="tablist" aria-label="Sections">
      <a href="#settings" role="tab" data-tab="settings"><?= $adminFolderPath !== null ? 'Folder settings' : 'Settings' ?></a>
      <a href="#notes" role="tab" data-tab="notes">Notes <span class="count"><?= count($mdCandidates) ?></span></a>
      <a href="#export" role="tab" data-tab="export">Export</a>
    </nav>
    <form method="post" class="logout-form">
      <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
      <input type="hidden" name="action" value="logout">
      <button type="submit" class="secondary">Log out</button>
    </form>
  </div>
</header>
<div class="admin-layout">
<aside class="admin-side">
  <?php if ($adminHidden): ?>
    <a href="#hidden" data-tab="hidden" class="side-hidden">Private <span class="count"><?= count($adminHidden['folders']) + count($adminHidden['items']) ?></span></a>
  <?php endif; ?>
  <a href="#site" data-tab="site" class="side-link">Site settings</a>
  <details class="admin-folders" open>
    <summary>Folders <span class="here">&middot; <?= htmlspecialchars($folderLabel, ENT_QUOTES, 'UTF-8') ?></span></summary>
    <?= render_admin_folder_nav($files, $adminFolderPath) ?: '<p class="muted">No subfolders.</p>' ?>
  </details>
</aside>
<main class="admin-main">
  <?php if ($resultMessage): ?>
    <div class="flash <?= htmlspecialchars($resultMessage['type'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($resultMessage['text'], ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <div id="server-probe" hidden data-photo="<?= htmlspecialchars($GLOBALS['__lock_probe']['photo'] ?? '', ENT_QUOTES, 'UTF-8') ?>" data-cache="<?= htmlspecialchars($GLOBALS['__lock_probe']['cache'] ?? '', ENT_QUOTES, 'UTF-8') ?>" data-folder="<?= htmlspecialchars($GLOBALS['__lock_probe']['folder'] ?? '', ENT_QUOTES, 'UTF-8') ?>" data-folder-url="<?= htmlspecialchars($GLOBALS['__lock_probe']['folderUrl'] ?? '', ENT_QUOTES, 'UTF-8') ?>" data-files="<?= (int) ($GLOBALS['__lock_probe']['count'] ?? 0) ?>"></div>
<?php if ($adminHidden): ?>
  <div class="tab-panel" data-tab="hidden">
<?php render_admin_hidden_panel($adminHidden); ?>
  </div>
<?php endif; ?>
  <div class="tab-panel" data-tab="settings">
  <?php if (ENABLE_PHOTO_LOCATION && $adminAutoLoc): $unsavedCount = count($adminAutoLoc); ?>
    <div class="panel loc-unsaved-summary">
      <?php if (!empty($itemsHere['locationEnabled'])): ?>
        <strong><?= ui_icon('warn') ?> <?= $unsavedCount ?> photo<?= $unsavedCount === 1 ? '' : 's' ?> here <?= $unsavedCount === 1 ? 'has' : 'have' ?> GPS that isn't saved yet.</strong>
        <span class="muted">It couldn't be stored automatically (is this folder writable by the web server?). Open the photo in the gallery, press its pencil, and choose <em>Keep the location from the photo</em>.</span>
      <?php else: ?>
        <strong><?= ui_icon('warn') ?> <?= $unsavedCount ?> photo<?= $unsavedCount === 1 ? '' : 's' ?> here <?= $unsavedCount === 1 ? 'has' : 'have' ?> GPS in the file.</strong>
        <span class="muted">Nothing is public while this folder's location switch is off. Switching it on makes <?= $unsavedCount === 1 ? 'that location' : 'these ' . $unsavedCount . ' locations' ?> public on the gallery and the map, except for photos whose <em>Location</em> switch (in the pencil's editor) you turn off.</span>
      <?php endif; ?>
    </div>
  <?php endif; ?>
  <?php if ($adminFolderPath !== null): ?>
  <section class="panel" id="settings">
    <h2 class="section-title">This folder's own settings</h2>
    <?php render_admin_identity_form($itemsHere, $csrfToken, $adminFolderPath, suggest_folder_dates($dir, folder_files_under($files, $adminFolderPath))); ?>
    <?php render_admin_privacy_section($itemsHere, $csrfToken, $adminFolderPath, folder_privacy_counts($dir, $files, $adminFolderPath), $locWarn); ?>
  </section>
  <?php else: ?>
  <section class="panel" id="settings">
    <h2 class="section-title">Gallery settings</h2>
    <?php render_admin_identity_form($itemsHere, $csrfToken, null, suggest_folder_dates($dir, $files)); ?>
    <?php render_admin_privacy_section($itemsHere, $csrfToken, null, ['stay' => 0, 'open' => 0], $locWarn); ?>
  </section>
  <?php endif; ?>

  <?php
  // Display-name fields for root-level filename/explicit groups (a
  // folder-group's own name is edited from inside that folder — see
  // above). Only groups that actually render as a named box get one; a
  // lone standalone single has no separate "name" shown anywhere to edit.
  if ($adminFolderPath === null):
  ?>
  <div class="panel">
    <h2 style="margin-top:0;">Group names</h2>
    <?php $anyGroupNames = false; foreach ($groups as $gKey => $gFiles):
      if (isset($folderGroupKeys[$gKey])) continue;
      if (count($gFiles) < 2 && !isset($explicitGroupKeys[$gKey])) continue;
      $anyGroupNames = true;
      $isExplicit = isset($explicitGroups[$gKey]);
    ?>
      <form method="post" class="field-row" style="align-items:flex-end;">
        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
        <input type="hidden" name="action" value="save_group_label">
        <input type="hidden" name="group_key" value="<?= htmlspecialchars($gKey, ENT_QUOTES, 'UTF-8') ?>">
        <div class="field">
          <label><?= htmlspecialchars($gKey, ENT_QUOTES, 'UTF-8') ?> (<?= count($gFiles) ?> item<?= count($gFiles) === 1 ? '' : 's' ?>)</label>
          <input type="text" name="display_name" value="<?= htmlspecialchars($isExplicit ? $explicitGroups[$gKey]['name'] : ($itemsHere['groups'][$gKey]['displayName'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" <?= $isExplicit ? 'disabled' : '' ?>>
        </div>
        <?php if (!$isExplicit): ?><button type="submit">Save</button><?php else: ?><span class="muted">Controlled by {group:} in a .md</span><?php endif; ?>
      </form>
    <?php endforeach; if (!$anyGroupNames): ?>
      <p class="muted">No multi-photo groups at the root yet.</p>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  </div>
  <div class="tab-panel" data-tab="notes">
  <div class="notes-layout">
  <div class="notes-main">
  <section class="panel" id="notes">
    <h2 class="section-title">Notes <span class="muted">(<?= count($mdCandidates) ?> .md file<?= count($mdCandidates) === 1 ? '' : 's' ?> in this folder)</span></h2>
    <?php if (empty($mdCandidates)): ?>
      <p class="muted">No .md files here yet. A notes file is a short text box (with optional sold/reserved markers) shown above the photos.</p>
    <?php endif; ?>
    <?php foreach ($mdCandidates as $mdFile):
      $isActive = isset($activeMd[$mdFile]);
      $raw = (string) @file_get_contents($adminFolderReal . '/' . $mdFile);
      // Shown exactly as stored, {gallery} line included: what you see is what a save writes. (Hiding the line used to
      // write it back without the marker, which quietly switched the notes off.)
      $editable = $raw;
    ?>
      <div class="md-file">
        <div class="md-file-head">
          <strong><?= htmlspecialchars($mdFile, ENT_QUOTES, 'UTF-8') ?></strong>
          <span class="md-badge <?= $isActive ? 'active' : 'inactive' ?>"><?= $isActive ? 'active' : 'not active' ?></span>
          <?php if (!$isActive): ?>
            <form method="post" style="display:inline;">
              <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
              <input type="hidden" name="action" value="add_gallery_marker">
              <input type="hidden" name="md_file" value="<?= htmlspecialchars($mdFile, ENT_QUOTES, 'UTF-8') ?>">
              <button type="submit" class="secondary">Add {gallery} marker</button>
            </form>
          <?php endif; ?>
        </div>
        <form method="post" onsubmit="return admConfirmSaveAs(this);">
          <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
          <input type="hidden" name="action" value="save_notes">
          <input type="hidden" name="md_file" value="<?= htmlspecialchars($mdFile, ENT_QUOTES, 'UTF-8') ?>">
          <textarea name="content" class="adm-textarea"><?= htmlspecialchars($editable, ENT_QUOTES, 'UTF-8') ?></textarea>
          <div class="field-row md-actions">
            <div class="field">
              <label>Save as a different filename (optional)</label>
              <input type="text" name="new_name" class="adm-newname" placeholder="leave blank to overwrite this file">
            </div>
            <button type="submit">Save</button>
          </div>
        </form>
        <form method="post" onsubmit="return confirm('Delete <?= htmlspecialchars(addslashes($mdFile), ENT_QUOTES, 'UTF-8') ?>? This can\'t be undone.');" style="margin-top:6px;">
          <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
          <input type="hidden" name="action" value="delete_notes">
          <input type="hidden" name="md_file" value="<?= htmlspecialchars($mdFile, ENT_QUOTES, 'UTF-8') ?>">
          <button type="submit" class="danger">Delete</button>
        </form>
      </div>
    <?php endforeach; ?>
    <details class="subtle md-file" <?= empty($mdCandidates) ? 'open' : '' ?>>
      <summary><strong>+ New notes file</strong></summary>
      <form method="post" onsubmit="return admConfirmSaveAs(this);">
        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
        <input type="hidden" name="action" value="save_notes">
        <input type="hidden" name="md_file" value="">
        <textarea name="content" class="adm-textarea">{gallery}
</textarea>
        <div class="field-row md-actions">
          <div class="field">
            <label>Filename</label>
            <input type="text" name="new_name" class="adm-newname" placeholder="notes.md" required>
          </div>
          <button type="submit">Create</button>
        </div>
      </form>
    </details>
  </section>
  </div>
  <aside class="notes-reference" aria-label="Notes reference"><?= render_admin_notes_reference() ?></aside>
  </div>
  </div>
  <div class="tab-panel" data-tab="site">
<?php render_admin_health_panel(); render_admin_thumbnails_panel($csrfToken); render_admin_site_settings($csrfToken); ?>
  </div>
  <div class="tab-panel" data-tab="export">
  <section class="panel" id="export">
    <h2 class="section-title">Export</h2>
    <p class="muted">The only backup of what you've typed: captions, locations, web links, names, covers and private flags, as JSON. <strong>Handle the file with care:</strong> it records which items and folders are hidden, and any GPS you've entered.</p>
    <div class="export-cards">
      <form method="post" class="export-card">
        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
        <input type="hidden" name="action" value="export_items">
        <span class="export-scope">This folder</span>
        <span class="export-name"><?= htmlspecialchars($adminFolderPath ?? 'Top of the gallery', ENT_QUOTES, 'UTF-8') ?></span>
        <button type="submit" class="secondary">Export this folder</button>
      </form>
      <form method="post" class="export-card export-all">
        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
        <input type="hidden" name="action" value="export_tree">
        <span class="export-scope">Entire gallery</span>
        <span class="export-name">Every folder, one file</span>
        <button type="submit" class="secondary">Export entire gallery</button>
      </form>
    </div>
  </section>
  </div>
</main>
</div>
<script>
  // A link inside the admin that opens one of its tabs and, if given, scrolls to (and briefly marks) a place in it.
  function adminGoTo(tab, id) {
    var t = document.querySelector('.side-link[data-tab="' + tab + '"], .side-hidden[data-tab="' + tab + '"], .admin-tabs a[data-tab="' + tab + '"]');
    if (!t) return true;
    t.click();
    if (id) setTimeout(function () {
      var e = document.getElementById(id);
      if (!e) return;
      if (e.tagName === 'DETAILS') e.open = true;
      e.scrollIntoView({ behavior: 'smooth', block: 'start' });
      e.classList.add('flash-target');
      setTimeout(function () { e.classList.remove('flash-target'); }, 2500);
    }, 60);
    return false;
  }
  // Are the web server rules in place (the ones on the Hidden tab and in Site settings)? This browser asks, without any
  // sign-in: for a photo and a cached thumbnail by their plain addresses (a refusal means locked, a picture coming back
  // means not; HEAD, so nothing is downloaded), and for a folder by its tidy address (the gallery's own page for that folder
  // means the tidy links work). A warning only goes away on a clear answer; any doubt leaves it, and the rules, standing.
  (function () {
    const probe = document.getElementById('server-probe');
    if (!probe || typeof fetch === 'undefined') return;
    const photo = probe.getAttribute('data-photo'), cache = probe.getAttribute('data-cache');
    const folderUrl = probe.getAttribute('data-folder-url');
    const hasFiles = Number(probe.getAttribute('data-files')) > 0;
    const banner = document.getElementById('lock-banner');
    const mirrorLater = function () {
      if (photo) { if (!folderUrl) mirror('tidy', '', 'No folder to test with (turn on "Show subfolders" and add a folder with photos).'); return; }
      if (hasFiles) {
        mirror('lock', 'ok-text', 'Not needed: every photo and video here comes from folders outside the gallery\'s own address, so none can be fetched by its address.');
        mirror('accel', 'ok-text', 'Doesn\'t apply here: files from outside the gallery\'s own address are always sent by the gallery itself, whatever this is set to.');
        if (!folderUrl) mirror('tidy', '', 'No folder to test with (turn on "Show subfolders" and add a folder with photos).');
      } else ['lock', 'tidy', 'accel'].forEach(function (k) { mirror(k, '', 'Nothing to test with yet (no photos).'); });
    };
    const hideAll = function (sel, on) { document.querySelectorAll(sel).forEach(function (el) { el.hidden = on; }); };
    const report = function (selector, ok, text, rulesSel) {
      document.querySelectorAll(selector).forEach(function (el) {
        el.className = 'lock-result ' + (ok ? 'ok-text' : 'warn-text');
        el.textContent = text;
        if (ok) {
          const more = document.createElement('button');
          more.type = 'button'; more.className = 'linklike'; more.textContent = 'Show the rules';
          more.addEventListener('click', function () { hideAll(rulesSel, false); more.remove(); });
          el.appendChild(document.createTextNode(' ')); el.appendChild(more);
        }
      });
    };
    const ask = function (url) {
      const target = url + (url.indexOf('?') < 0 ? '?' : '&') + 'lockcheck=' + Date.now();   // (a server that won't answer HEAD gets a one-byte GET)
      return fetch(target, { method: 'HEAD', cache: 'no-store', credentials: 'omit', redirect: 'manual' })
        .then(function (r) { return r.status === 405 || r.status === 501 ? fetch(target, { headers: { Range: 'bytes=0-0' }, cache: 'no-store', credentials: 'omit', redirect: 'manual' }) : r; })
        .then(function (r) {
          if (r.type === 'opaqueredirect') return 'unknown';
          if (r.status === 401 || r.status === 403 || r.status === 404 || r.status === 410) return 'blocked';
          if (r.status >= 200 && r.status < 300) return 'open';
          return 'unknown';
        })
        .catch(function () { return 'unknown'; });
    };
    const icon = function (name) { return '<svg class="ico" aria-hidden="true"><use href="#i-' + name + '"></use></svg>'; };
    const refreshHealth = function () {
      const panel = document.getElementById('health-panel');
      if (!panel) return;
      const pending = panel.querySelectorAll('.health-row.pending').length, loud = panel.querySelectorAll('.health-row.loud').length, red = panel.querySelectorAll('.health-row.loud.red').length + panel.querySelectorAll('.health-row.health-bad').length;
      panel.classList.toggle('is-checking', pending > 0 && loud === 0);
      panel.querySelectorAll('.health-group').forEach(function (g) { g.classList.toggle('all-quiet', !g.querySelector('.health-row.loud')); });
      const text = panel.querySelector('.health-summary-text');
      if (!text) return;
      text.textContent = loud ? loud + ' thing' + (loud === 1 ? '' : 's') + ' to look at.' : pending ? 'Checking\u2026' : 'Everything checked looks right.';
      text.className = 'health-summary-text ' + (loud ? (red ? 'warn-text' : 'note-text') : pending ? 'muted' : 'ok-text');
    };
    (function () {
      const panel = document.getElementById('warm-panel');
      if (!panel) return;
      const go = panel.querySelector('.warm-go'), stop = panel.querySelector('.warm-stop'), count = panel.querySelector('.warm-count'), bar = panel.querySelector('.warm-bar'), fill = bar.firstElementChild;
      // Three requests work side by side, each on every third photo, and each asks again the moment it is answered; a request
      // lasts about a second and a half, so the percentage moves several times a second. The percentage is of the photos that
      // were missing when the page opened (or when the button was pressed again).
      const WORKERS = 3, fmt = function (n) { return n.toLocaleString(); };
      let running = false, stopping = false, gen = 0, active = 0, pending = Number(panel.dataset.left) || 0, worked = 0, failed = 0, total = 0, started = 0, problem = '';
      const pct = function () { return pending > 0 ? Math.min(99, Math.floor(100 * worked / pending)) : 99; };
      const paint = function () {
        const p = pct(), secs = (Date.now() - started) / 1000;
        let eta = '';
        if (secs >= 3 && worked >= 3 && pending > worked) {
          const left = Math.round((pending - worked) * secs / worked);
          const many = function (n, word) { return n + ' ' + word + (n === 1 ? '' : 's'); };
          if (left < 60) eta = ', about ' + many(Math.max(5, Math.round(left / 5) * 5), 'second') + ' left';
          else if (left < 3600) eta = ', about ' + many(Math.round(left / 60), 'minute') + ' left';
          else { let h = Math.floor(left / 3600), m = Math.round((left % 3600) / 60); if (m === 60) { h++; m = 0; } eta = ', about ' + many(h, 'hour') + (m ? ' ' + many(m, 'minute') : '') + ' left'; }
        }
        fill.style.width = p + '%';
        count.textContent = (stopping ? 'Stopping… ' : 'Preparing… ') + p + '% (' + fmt(worked) + ' of ' + fmt(pending) + ' photos made' + eta + ')';
      };
      const finish = function () {
        if (active > 0) return;
        running = false; stop.hidden = true; stop.disabled = false;
        if (problem) { go.hidden = false; go.textContent = 'Try again'; count.textContent = 'Stopped: ' + problem + '.'; count.className = 'warm-count warn-text'; return; }
        if (stopping) {
          stopping = false; pending = Math.max(1, pending - worked); worked = 0;
          go.hidden = false; go.textContent = 'Carry on';
          count.textContent = 'Stopped. Press the button to carry on; what is done stays done.';
          return;
        }
        bar.hidden = true; fill.style.width = '100%';
        count.textContent = failed ? 'Finished. ' + failed + ' picture' + (failed === 1 ? '' : 's') + ' could not be made (a damaged file, or a folder the web server cannot write to).' : 'All ' + fmt(total) + ' photos are ready.';
        count.className = 'warm-count ' + (failed ? 'note-text' : 'ok-text');
      };
      const work = function (k, mine) {
        active++;
        const next = function (from) {
          if (!running || stopping || mine !== gen) { active--; finish(); return; }
          const body = new URLSearchParams();
          body.set('csrf_token', panel.dataset.csrf); body.set('action', 'warm_thumbnails'); body.set('from', String(from)); body.set('step', String(WORKERS));
          fetch('?admin', { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Requested-With': 'gallery-fetch' } })
            .then(function (r) { return r.json(); })
            .then(function (j) {
              if (!j.ok) throw new Error(j.text || 'failed');
              failed += j.failed; worked += j.worked || 0; total = j.total;
              paint();
              if (j.done) { active--; finish(); } else next(j.next);
            })
            .catch(function (err) { problem = problem || (err && err.message ? err.message : 'the server did not answer'); running = false; active--; finish(); });
        };
        next(k);
      };
      go.addEventListener('click', function () {
        running = true; stopping = false; problem = ''; failed = 0; worked = 0; total = 0; gen++; started = Date.now();
        go.hidden = true; stop.hidden = false; bar.hidden = false; fill.style.width = '0'; count.className = 'warm-count';
        count.textContent = 'Starting…';
        for (let k = 0; k < WORKERS; k++) work(k, gen);
      });
      stop.addEventListener('click', function () { if (!running || stopping) return; stopping = true; stop.disabled = true; paint(); });
    })();
    const toggle = document.querySelector('.health-toggle');
    if (toggle) toggle.addEventListener('click', function () {
      const panel = document.getElementById('health-panel');
      const on = panel.classList.toggle('show-all');
      toggle.textContent = on ? 'Show only what needs attention' : 'Show all checks';
    });
    const mirror = function (key, cls, text) {
      document.querySelectorAll('[data-health="' + key + '"]').forEach(function (el) { el.textContent = text; el.className = 'health-detail ' + cls; });
      document.querySelectorAll('[data-health-row="' + key + '"]').forEach(function (row) {
        const problem = cls === 'warn-text' || cls === 'note-text';
        row.classList.remove('pending'); row.classList.toggle('loud', problem); row.classList.toggle('quiet', !problem); row.classList.toggle('red', cls === 'warn-text');
        const m = row.querySelector('.health-mark');
        m.className = 'health-mark ' + (cls === 'ok-text' ? 'ok-text' : cls === 'warn-text' ? 'warn-text' : cls === 'note-text' ? 'note-text' : 'muted');
        m.innerHTML = cls === 'ok-text' ? icon('check') : problem ? icon('warn') : '&bull;';
      });
      refreshHealth();
    };
    const lockShow = function (state, detail) {
      const outside = state === 'outside';
      const locked = state === 'locked' || outside;
      if (banner) {
        banner.classList.toggle('ok', locked); banner.classList.toggle('error', !locked);
        banner.querySelector('.lock-open-text').hidden = locked;
        banner.querySelector('.lock-locked-text').hidden = !(locked && !outside);
        banner.querySelector('.lock-outside-text').hidden = !outside;
        banner.querySelector('.lock-recheck').hidden = false;
      }
      hideAll('.lock-help, [data-lock-rules], [data-lock-rules-panel]', locked);
      if (outside) mirror('lock', 'ok-text', 'Not needed: every photo and video here comes from folders outside the gallery\'s own address, so none can be fetched by its address.');
      else mirror('lock', locked ? 'ok-text' : state === 'open' ? 'warn-text' : '', locked ? 'Yes: a photo and a cached thumbnail asked for by their plain addresses were refused, so private items can only be reached through the gallery.'
        : state === 'open' ? 'No: ' + detail + ' was handed out to a plain request, so a private photo can be fetched by its address. Use the rules on the Private tab.' : 'Couldn\'t tell (the request failed or was redirected).');
      document.querySelectorAll('[data-lock-detail]').forEach(function (el) {
        el.className = 'lock-result warn-text';
        el.textContent = locked ? '' : state === 'open' ? 'The server handed out ' + detail + ' to a plain request.' : 'Couldn\'t check from this browser just now.';
      });
      report('[data-lock-status]', locked, outside ? 'Nothing to ask the server for: no photo or video has an address of its own here.' : locked ? 'Checked just now from this browser: direct requests for photos are refused.'
        : state === 'open' ? 'Checked just now from this browser: the server handed out ' + detail + ' to a plain request, so a private photo could be fetched by its address. Use the rules below.'
        : 'Couldn\'t check from this browser just now (the request failed or was redirected).', '.lock-help, [data-lock-rules], [data-lock-rules-panel]');
    };
    const runLock = function () {
      if (!photo) { if (hasFiles) lockShow('outside'); return; }
      Promise.all([ask(photo), cache ? ask(cache) : Promise.resolve('skipped')]).then(function (r) {
        if (r[0] === 'open') return lockShow('open', photo);
        if (r[1] === 'open') return lockShow('open', cache);
        if (r[0] === 'blocked' && (r[1] === 'blocked' || r[1] === 'skipped')) return lockShow('locked');
        lockShow('unknown');
      });
    };
    const runTidy = function () {
      if (!folderUrl) return;
      fetch(folderUrl, { cache: 'no-store', credentials: 'omit', redirect: 'manual' })
        .then(function (r) {
          if (r.type === 'opaqueredirect') return 'unknown';
          if (r.status === 200) return r.text().then(function (t) { return t.indexOf('name="gallery-folder"') >= 0 ? 'works' : 'no'; });
          return r.status >= 400 && r.status < 500 ? 'no' : 'unknown';
        })
        .catch(function () { return 'unknown'; })
        .then(function (state) {
          mirror('tidy', state === 'works' ? 'ok-text' : state === 'no' ? 'note-text' : '', state === 'works' ? folderUrl + ' opens that folder.'
            : state === 'no' ? 'Not set up (optional): ' + folderUrl + ' doesn\'t open that folder; the ?folder= links work regardless.' : 'Couldn\'t tell (the request failed or was redirected).');
          if (state === 'works') {
            hideAll('[data-tidy-rules]', true);
            report('[data-tidy-status]', true, 'Tidy folder links work: ' + folderUrl + ' opens that folder (checked just now from this browser).', '[data-tidy-rules]');
          } else {
            hideAll('[data-tidy-rules]', false);
            report('[data-tidy-status]', false, state === 'no' ? 'Tidy folder links are not set up (optional): ' + folderUrl + ' does not open that folder yet. The gallery works without them, with ?folder= links. Rules to add are below.'
              : 'Couldn\'t check tidy folder links from this browser just now.', '[data-tidy-rules]');
          }
        });
    };
    // "nginx internal redirect": asks the gallery to answer a request the way it would with that setting, and sees whether
    // nginx turns the answer into the picture. With it set, that says whether it works; empty, whether setting it would.
    const accel = document.querySelector('[data-accel-status]');
    const runAccel = function () {
      if (!accel) return;
      const current = accel.getAttribute('data-accel-current'), candidate = accel.getAttribute('data-accel-candidate');
      const prefix = current || candidate;
      if (!photo) return;   // (mirrorLater has said why)
      if (!prefix) { mirror('accel', '', 'Not applicable: a gallery at the very top of a site has no folder to set it to.'); return; }
      fetch('?admin&accelcheck=1&prefix=' + encodeURIComponent(prefix), { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { return r.ok && /^(image|video)\//.test(r.headers.get('content-type') || '') ? r.blob().then(function (b) { return b.size > 0; }) : false; })
        .catch(function () { return null; })
        .then(function (works) {
          if (works === null) return mirror('accel', '', 'Couldn\'t tell (the request failed).');
          mirror('accel', current ? (works ? 'ok-text' : 'warn-text') : (works ? 'note-text' : ''), current
            ? (works ? 'Set to ' + current + ' and working: nginx sends the photos and videos itself.' : 'Set to ' + current + ' but nginx did not serve a test photo through it; photos may fail to load.')
            : (works ? 'Not set, though nginx has the internal location for ' + candidate + ' in place: setting it makes photos and videos faster.' : 'Not set, and not needed unless you run nginx with the internal location (see the Private tab).'));
          accel.textContent = '';
          const say = function (cls, text) { accel.className = 'help accel-status ' + cls; accel.appendChild(document.createTextNode(text)); };
          if (current) {
            if (works) say('ok-text', 'Working: nginx just served a test photo through ' + current + ', so photos and videos are sent by nginx itself (checked from this browser).');
            else say('warn-text', 'Not working: with this set, nginx did not serve ' + photo + ' (a photo in the gallery\'s own folder) through ' + current + ', so photos and videos may fail to load. The internal location must match that address: for the rules on the Private tab, a location starting ' + current + '/ whose photo extensions are marked internal, in the same server block that serves this page. Try: curl -I ' + location.origin + photo + ' (it should say 404 or 403), and look in nginx\'s error log for the line about the internal redirect. Or clear this setting.');
          } else if (works) {
            say('note-text', 'Not using it yet: nginx has the internal location for ' + candidate + ' in place (just tested), but this is empty, so the gallery reads every photo and video itself. Setting it to ' + candidate + ' lets nginx send them, which is faster and handles seeking in videos better. ');
            const use = document.createElement('button');
            use.type = 'button'; use.className = 'secondary'; use.textContent = 'Set it to ' + candidate;
            use.addEventListener('click', function () {
              const input = document.querySelector('input[name="set_NGINX_ACCEL_REDIRECT_PREFIX"]');
              if (!input) return;
              input.value = candidate; input.dispatchEvent(new Event('change', { bubbles: true }));
              use.remove();
              accel.className = 'help accel-status ok-text'; accel.textContent = 'Saved. Reload this page to check that it works.';
            });
            accel.appendChild(use);
          }
        });
    };
    if (banner) banner.querySelector('.lock-recheck').addEventListener('click', function () { runLock(); runTidy(); });
    document.querySelectorAll('.show-rules-btn').forEach(function (btn) { btn.addEventListener('click', function () { hideAll('.lock-help, [data-lock-rules], [data-lock-rules-panel]', false); btn.remove(); }); });
    mirrorLater();
    runLock();
    runTidy();
    runAccel();
  })();
  // The directive reference starts expanded on a desktop and collapsed
  // on a phone-width screen; it's a plain <details>, so either can toggle.
  // The folder list is a sidebar on a desktop and a collapsed "Folders" bar on a phone.
  if (window.matchMedia && window.matchMedia('(max-width: 800px)').matches) {
    document.querySelectorAll('.admin-folders').forEach(function (d) { d.removeAttribute('open'); });
  }
  // The notes reference's Insert buttons put a directive at the cursor of the note you last clicked in (or into
  // the only note there is); with several notes and none clicked, they say to click in one first.
  var lastNote = null;
  document.querySelectorAll('.adm-textarea').forEach(function (ta) {
    ta.addEventListener('focus', function () { lastNote = ta; });
  });
  // The notes reference shows one section at a time (without JavaScript every section is simply listed).
  [].forEach.call(document.querySelectorAll('.notes-reference'), function (ref) {
    var tabs = ref.querySelectorAll('.ref-tabs button'), panels = ref.querySelectorAll('.ref-panel');
    function show(name) {
      [].forEach.call(tabs, function (t) { var on = t.getAttribute('data-ref') === name; t.classList.toggle('active', on); t.setAttribute('aria-selected', on ? 'true' : 'false'); });
      [].forEach.call(panels, function (p) { p.classList.toggle('active', p.getAttribute('data-ref') === name); });
      ref.scrollTop = 0;
      try { sessionStorage.setItem('galleryRefTab', name); } catch (e) {}
    }
    ref.classList.add('js-ref');
    [].forEach.call(tabs, function (t) { t.addEventListener('click', function () { show(t.getAttribute('data-ref')); }); });
    [].forEach.call(ref.querySelectorAll('[data-goto]'), function (l) { l.addEventListener('click', function () { show(l.getAttribute('data-goto')); }); });
    var saved = null;
    try { saved = sessionStorage.getItem('galleryRefTab'); } catch (e) {}
    show(saved && ref.querySelector('.ref-panel[data-ref="' + saved + '"]') ? saved : 'start');
  });
  document.querySelectorAll('.admin-insert-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const notes = document.querySelectorAll('.adm-textarea');
      const textarea = lastNote || (notes.length === 1 ? notes[0] : null);
      const hint = document.querySelector('.insert-hint');
      if (!textarea) { if (hint) hint.hidden = false; return; }
      if (hint) hint.hidden = true;
      const snippet = btn.getAttribute('data-snippet');
      let start = textarea.selectionStart, end = textarea.selectionEnd;
      // Never write into the {gallery} line itself: a cursor still sitting in it (or at the very start) means the end of the text.
      const firstBreak = textarea.value.indexOf('\n'), firstLine = firstBreak < 0 ? textarea.value : textarea.value.slice(0, firstBreak);
      if (/^\s*\{gallery\}\s*$/i.test(firstLine) && start <= firstLine.length) {
        const sep = textarea.value === '' || textarea.value.slice(-1) === '\n' ? '' : ' ';
        start = end = textarea.value.length;
        textarea.value = textarea.value + sep;
        start = end = textarea.value.length;
      }
      textarea.value = textarea.value.slice(0, start) + snippet + textarea.value.slice(end);
      textarea.focus();
      textarea.selectionStart = textarea.selectionEnd = start + snippet.length;
    });
  });
  // Tabs: only the selected section is shown. #name in the address picks one
  // (so a link can point straight at a tab), the last
  // choice is remembered while moving between folders, and without JavaScript
  // every section simply stays visible.
  (function () {
    var main = document.querySelector('.admin-main');
    if (!main) return;
    var tabs = [].slice.call(document.querySelectorAll('.admin-tabs a[data-tab], .side-hidden[data-tab], .side-link[data-tab]'));
    var panels = [].slice.call(document.querySelectorAll('.tab-panel'));
    var names = tabs.map(function (t) { return t.getAttribute('data-tab'); });
    var KEY = 'galleryAdminTab';
    function show(name) {
      if (names.indexOf(name) < 0) name = names.indexOf('settings') >= 0 ? 'settings' : names[0];
      panels.forEach(function (p) { p.classList.toggle('active', p.getAttribute('data-tab') === name); });
      tabs.forEach(function (t) {
        var on = t.getAttribute('data-tab') === name;
        t.classList.toggle('active', on);
        t.setAttribute('aria-selected', on ? 'true' : 'false');
      });
      // On Site settings or Private nothing in the folder list is selected: the folder you came from stays marked, quietly.
      var lay = document.querySelector('.admin-layout');
      if (lay) lay.classList.toggle('off-folder', name === 'site' || name === 'hidden');
      // "Hidden" is a look at the whole tree, not a section of a folder:
      // moving to another folder shouldn't land you back on it.
      if (name !== 'hidden' && name !== 'site') { try { sessionStorage.setItem(KEY, name); } catch (e) {} }
      return name;
    }
    function fromLocation() {
      var h = decodeURIComponent((location.hash || '').slice(1));
      if (names.indexOf(h) >= 0) { show(h); return; }
      var saved = null;
      try { saved = sessionStorage.getItem(KEY); } catch (e) {}
      show(saved);
    }
    main.classList.add('js-tabs');
    tabs.forEach(function (t) {
      t.addEventListener('click', function (ev) {
        ev.preventDefault();
        var target = t.getAttribute('data-tab');
        if (window.__siteDirty && target !== 'site') {
          // Something in Site settings changed what the gallery shows: reload so the sidebar, counts and photos are current.
          if (target !== 'hidden') { try { sessionStorage.setItem(KEY, target); } catch (e) {} }
          if (history.replaceState) history.replaceState(null, '', '#' + target);
          location.reload();
          return;
        }
        var name = show(target);
        if (history.replaceState) history.replaceState(null, '', '#' + name);
        window.scrollTo(0, 0);
      });
    });
    window.addEventListener('hashchange', fromLocation);
    fromLocation();
  })();
  // Folder tree: branches not on the way to the folder being edited start folded; the chevron toggles one.
  // A function so it can be run again when Site settings changes which folders exist.
  function initFolderTree() {
    var nav = document.querySelector('.admin-folder-nav');
    if (!nav) return;
    [].forEach.call(nav.querySelectorAll('li.has-children'), function (li) {
      var toggle = li.querySelector(':scope > .ftoggle');
      if (!li.querySelector('a.current')) { li.classList.add('collapsed'); toggle.setAttribute('aria-expanded', 'false'); }
      toggle.addEventListener('click', function () {
        var folded = li.classList.toggle('collapsed');
        toggle.setAttribute('aria-expanded', folded ? 'false' : 'true');
      });
    });
  }
  initFolderTree();
  // The "Copy" buttons on the web-server rules.
  [].forEach.call(document.querySelectorAll('.copy-rules'), function (btn) {
    btn.addEventListener('click', function () {
      var text = btn.parentNode.querySelector('pre').textContent;
      function done() { var old = btn.textContent; btn.textContent = 'Copied'; setTimeout(function () { btn.textContent = old; }, 1400); }
      if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(done, function () {}); return; }
      var ta = document.createElement('textarea'); ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0'; document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); done(); } catch (e) {} document.body.removeChild(ta);
    });
  });
  // "Use these dates": copies the suggestion into the Dates box; nothing is saved until the form is.
  [].forEach.call(document.querySelectorAll('.date-suggest'), function (btn) {
    btn.addEventListener('click', function () {
      var input = btn.closest('.field').querySelector('input[name=dates]');
      if (!input) return;
      input.value = btn.getAttribute('data-dates');
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.focus();
      btn.remove();
    });
  });
  // Settings that are a single switch or number save the moment they change, in place: the control, the little status
  // beside it, and nothing else on the page moves. (The small Save button exists only without JavaScript.)
  [].forEach.call(document.querySelectorAll('.instant-form'), function (form) {
    form.addEventListener('submit', function (ev) { ev.preventDefault(); });
    var csrf = form.querySelector('input[name=csrf_token]').value, action = form.querySelector('input[name=action]').value;
    function say(node, text, kind) {
      if (!node) return;
      node.textContent = text; node.className = 'rowstate ' + (kind || '');
      clearTimeout(node._t);
      if (kind === 'ok') node._t = setTimeout(function () { node.textContent = ''; node.className = 'rowstate'; }, Math.max(3000, text.length * 60));
    }
    [].forEach.call(form.querySelectorAll('input[type=checkbox], input[type=number]'), function (el) {
      var isBox = el.type === 'checkbox';
      if (!isBox) el.setAttribute('data-prev', el.value);
      el.addEventListener('change', function () {
        var holder = el.closest('.instant-row, .field') || form, node = holder.querySelector('.rowstate');
        var sub = form.querySelector('.map-sub');
        if (el.name === 'map_enabled' && sub) sub.hidden = !el.checked;
        var body = new URLSearchParams();
        body.set('csrf_token', csrf); body.set('action', action); body.set(el.name, isBox ? (el.checked ? '1' : '0') : el.value);
        [].forEach.call(form.querySelectorAll('input[type=hidden][data-send]'), function (h) { body.set(h.name, h.value); });
        var revert = function () { if (isBox) el.checked = !el.checked; else el.value = el.getAttribute('data-prev'); if (el.name === 'map_enabled' && sub) sub.hidden = !el.checked; };
        say(node, 'Saving…', 'busy');
        fetch(location.pathname + location.search, { method: 'POST', credentials: 'same-origin', body: body.toString(),
            headers: { 'X-Requested-With': 'gallery-fetch', 'Content-Type': 'application/x-www-form-urlencoded' } })
          .then(function (r) { if ((r.headers.get('content-type') || '').indexOf('json') < 0) throw new Error('session'); return r.json(); })
          .then(function (j) {
            if (j.ok) { say(node, j.text, 'ok'); window.__siteDirty = true; if (!isBox) el.setAttribute('data-prev', el.value); form.dispatchEvent(new CustomEvent('instant-saved', { bubbles: true, detail: j })); }
            else { say(node, j.text, 'err'); revert(); }
          })
          .catch(function (e) { say(node, e.message === 'session' ? 'Your session ended — reload the page and sign in again.' : 'Couldn\'t save — check your connection.', 'err'); revert(); });
      });
    });
  });
  // A folder's own settings save by themselves: a text field when you leave it or stop typing for a second, the order choices as they are
  // made. One request at a time, each checked against the record's fingerprint (so another tab's change is never overwritten), and each
  // field says "Saving…", "Saved" or what is wrong beside its own label. Nothing else in the folder's data is touched.
  [].forEach.call(document.querySelectorAll('.identity-form'), function (form) {
    form.addEventListener('submit', function (ev) { ev.preventDefault(); });
    var csrf = form.querySelector('input[name=csrf_token]').value, hashInput = form.querySelector('input[name=loaded_hash]'), chain = Promise.resolve();
    function say(node, text, kind) {
      if (!node) return;
      node.textContent = text; node.className = 'rowstate ' + (kind || '');
      clearTimeout(node._t);
      if (kind === 'ok') node._t = setTimeout(function () { node.textContent = ''; node.className = 'rowstate'; }, 3000);
    }
    function stateOf(el) { var h = el.closest('.field') || el.closest('details'); return h ? h.querySelector('.rowstate') : null; }
    function post(fields, node, onOk, onErr) {
      chain = chain.then(function () {
        say(node, 'Saving…', 'busy');
        var body = new URLSearchParams();
        body.set('csrf_token', csrf); body.set('action', 'save_folder_identity'); body.set('loaded_hash', hashInput.value);
        fields.forEach(function (kv) { body.append(kv[0], kv[1]); });
        return fetch(location.pathname + location.search, { method: 'POST', credentials: 'same-origin', body: body.toString(),
            headers: { 'X-Requested-With': 'gallery-fetch', 'Content-Type': 'application/x-www-form-urlencoded' } })
          .then(function (r) { if ((r.headers.get('content-type') || '').indexOf('json') < 0) throw new Error('session'); return r.json(); })
          .then(function (j) {
            if (j.ok) { if (j.hash) hashInput.value = j.hash; say(node, 'Saved', 'ok'); window.__siteDirty = true; if (onOk) onOk(); }
            else { say(node, j.text, 'err'); if (onErr) onErr(); }
          })
          .catch(function (e) { say(node, e.message === 'session' ? 'Your session ended: reload the page and sign in again.' : 'Couldn\'t save: check your connection.', 'err'); if (onErr) onErr(); });
      });
    }
    [].forEach.call(form.querySelectorAll('input[type=text][name], input[type=number][name], textarea[name]'), function (el) {
      var saved = el.value, timer = 0;
      function go() {
        clearTimeout(timer);
        var node = stateOf(el), v = el.name === 'display_name' ? el.value.trim() : el.value;
        if (v === saved) { if (node && node.classList.contains('busy')) say(node, '', ''); return; }
        if (el.name === 'sort_order' && v.trim() !== '' && !/^-?\d{1,5}$/.test(v.trim())) {
          el.classList.add('bad'); say(node, 'Whole numbers only, from -99999 to 99999. Not saved.', 'err'); return;
        }
        el.classList.remove('bad');
        var sent = v;
        post([[el.name, v]], node, function () { saved = sent; }, function () { el.classList.add('bad'); });
      }
      el.addEventListener('input', function () {
        el.classList.remove('bad'); clearTimeout(timer);
        var node = stateOf(el); if (node && node.classList.contains('err')) say(node, '', '');
        timer = setTimeout(go, 1000);
      });
      el.addEventListener('blur', go);
      el.addEventListener('keydown', function (e) { if (e.key === 'Enter' && el.tagName !== 'TEXTAREA') { e.preventDefault(); go(); } });
      el.addEventListener('saved-externally', function () { saved = el.value; });
    });
    // "Use these dates" fills the field, which then saves like a typed one.
    [].forEach.call(form.querySelectorAll('.date-suggest'), function (b) {
      b.addEventListener('click', function () {
        var inp = form.querySelector('input[name=dates]'); if (!inp) return;
        inp.value = b.getAttribute('data-dates'); inp.dispatchEvent(new Event('input', { bubbles: true })); inp.blur();
        var hint = b.closest('.hint'); if (hint) hint.hidden = true;
      });
    });
    // The order of the photos: every choice sends the whole group (the server rebuilds the folder's own order from all of it).
    [].forEach.call(form.querySelectorAll('details.sort-details'), function (det) {
      var timer = 0, node = det.querySelector('summary .rowstate');
      function send() {
        var f = [];
        new FormData(form).forEach(function (v, k) { if (k.indexOf('photo_sort_') === 0) f.push([k, v]); });
        post(f, node);
      }
      function soon() { clearTimeout(timer); timer = setTimeout(send, 120); }
      det.addEventListener('change', soon);
      det.addEventListener('sf-changed', soon);
    });
  });
  // Private folder: a switch like the others. Switching it on saves at once; switching it off first says what will happen (how many
  // photos stay private, how many become public) and waits for a yes.
  [].forEach.call(document.querySelectorAll('.priv-folder-form'), function (form) {
    form.addEventListener('submit', function (ev) { ev.preventDefault(); });
    var csrf = form.querySelector('input[name=csrf_token]').value, box = form.querySelector('input[type=checkbox]'), note = form.querySelector('.priv-folder-note');
    var node = form.querySelector('.rowstate'), modal = document.getElementById('priv-modal');
    var counts = { stay: +form.getAttribute('data-stay') || 0, open: +form.getAttribute('data-open') || 0 };
    var noun = form.getAttribute('data-noun') || 'photo';
    function word(n) { return n + ' ' + noun + (n === 1 ? '' : 's'); }
    function paint() {
      if (!note) return;
      note.innerHTML = box.checked ? note.getAttribute('data-on').replace('%stay%', word(counts.stay)).replace('%open%', word(counts.open)).replace('%becomes%', counts.open === 1 ? 'becomes' : 'become') : note.getAttribute('data-off');
    }
    function say(text, kind) {
      node.textContent = text; node.className = 'rowstate ' + (kind || ''); clearTimeout(node._t);
      if (kind === 'ok') node._t = setTimeout(function () { node.textContent = ''; node.className = 'rowstate'; }, 3000);
    }
    function send(hide, done) {
      var body = new URLSearchParams();
      body.set('csrf_token', csrf); body.set('action', 'toggle_folder_restriction'); if (hide) body.set('hidden', '1');
      say('Saving…', 'busy');
      fetch(location.pathname + location.search, { method: 'POST', credentials: 'same-origin', body: body.toString(),
          headers: { 'X-Requested-With': 'gallery-fetch', 'Content-Type': 'application/x-www-form-urlencoded' } })
        .then(function (r) { if ((r.headers.get('content-type') || '').indexOf('json') < 0) throw new Error('session'); return r.json(); })
        .then(function (j) {
          if (!j.ok) { say(j.text, 'err'); done(false); return; }
          if (j.counts) counts = { stay: j.counts.stay, open: j.counts.open };
          box.checked = hide; paint(); say('Saved', 'ok'); window.__siteDirty = true; done(true);
        })
        .catch(function (e) { say(e.message === 'session' ? 'Your session ended: reload the page and sign in again.' : 'Couldn\'t save: check your connection.', 'err'); done(false); });
    }
    function closeModal() { if (modal) modal.hidden = true; box.focus(); }
    function ask() {
      say('', '');
      fetch(location.pathname + location.search + '&privcounts=1', { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { if ((r.headers.get('content-type') || '').indexOf('json') < 0) throw new Error('session'); return r.json(); })
        .then(function (j) {
          if (j.counts) counts = { stay: j.counts.stay, open: j.counts.open };
          document.getElementById('pm-stay').textContent = word(counts.stay); document.getElementById('pm-open').textContent = word(counts.open);
          document.getElementById('pm-stay-v').textContent = counts.stay === 1 ? 'stays' : 'stay'; document.getElementById('pm-open-v').textContent = counts.open === 1 ? 'becomes' : 'become';
          modal.hidden = false; document.getElementById('pm-cancel').focus();
        })
        .catch(function () { say('Couldn\'t check: reload the page and try again.', 'err'); });
    }
    box.addEventListener('change', function () {
      if (box.checked) { send(true, function (ok) { if (!ok) box.checked = false; }); return; }
      box.checked = true;   // stays on until it is confirmed
      if (modal) ask();
    });
    if (modal) {
      document.getElementById('pm-cancel').addEventListener('click', closeModal);
      modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
      modal.addEventListener('keydown', function (e) { if (e.key === 'Escape') { e.preventDefault(); closeModal(); } });
      document.getElementById('pm-ok').addEventListener('click', function () {
        modal.hidden = true;
        send(false, function () { box.focus(); });
      });
    }
    paint();
  });
  // The map sits under the location switch and only shows photos whose location is public, so it is dimmed (with a note) while
  // locations are off. (The note and dimming follow the switch once it has saved; with locations off for the whole gallery they stay.)
  (function () {
    var under = document.getElementById('map-under'); if (!under || under.getAttribute('data-global-off')) return;
    var box = document.querySelector('.instant-form input[type=checkbox][name=enabled]'), form = box && box.closest('form');
    if (form) form.addEventListener('instant-saved', function () { under.classList.toggle('no-loc', !box.checked); });
  })();
  // Site settings: every change saves the moment it is made (one request at a time, so the file's
  // fingerprint never races), and anything that publishes content, contacts a third party, or removes
  // something visitors see asks first. The other screens refresh when you leave this one.
  // "Put first": the three kinds are rows you drag (by the handle, with a finger or a mouse, or with the arrow keys on it) into the
  // order they stack in; each row's switch says whether it is used at all. What is switched on always stands above what is off. The
  // list is the same on the Site settings page (every change saved as it is made) and in a folder's own settings (written into the
  // form, saved with it); persist(order, ok, fail) is how each one keeps a change.
  function initSortFirst(box, persist) {
    var list = box.querySelector('.sf-list'), summary = box.querySelector('.sf-summary');
    var NAMES = { private: 'private items', video: 'videos', located: 'photos with a location' };
    function order() { return [].map.call(list.children, function (li) { return li.getAttribute('data-kind'); }); }
    function sum() {
      var parts = [].filter.call(list.children, function (li) { return li.querySelector('input[type=checkbox]').checked; }).map(function (li) { return NAMES[li.getAttribute('data-kind')]; });
      var t = parts.length ? parts.join(', then ') + ', then the rest.' : 'Nothing is put first: photos follow the order above.';
      if (!parts.length && box.getAttribute('data-mode') === 'form') {   // a folder with everything off, while the site puts something first
        var sOn = {}, sOrd = (box.getAttribute('data-site-order') || '').split(',');
        try { sOn = JSON.parse(box.getAttribute('data-site-on')) || {}; } catch (e) {}
        var sParts = sOrd.filter(function (k) { return sOn[k]; }).map(function (k) { return NAMES[k]; });
        if (sParts.length) t = 'Nothing is put first in this folder, though the site puts ' + sParts.join(', then ') + ' first.';
      }
      summary.textContent = t.charAt(0).toUpperCase() + t.slice(1);
    }
    var saved = order();
    function put(arr) { arr.forEach(function (k) { var li = list.querySelector('[data-kind="' + k + '"]'); if (li) list.appendChild(li); }); sum(); }
    // For a caller that wants to set the whole list at once (the folder page's "use the site's again").
    box.sfSet = function (arr, on) {
      [].forEach.call(list.children, function (li) { li.querySelector('input[type=checkbox]').checked = !!on[li.getAttribute('data-kind')]; });
      put(arr); settle(); saved = order(); sum();
    };
    function save() {
      var now = order();
      sum();
      persist(now, function () { saved = now; }, function () { put(saved); });
    }
    // What is switched on always stands above what is off (the off ones do nothing, so the list and the sentence agree):
    // switching one on brings it up under the ones already on, switching one off sends it down below them.
    function isOn(li) { return li.querySelector('input[type=checkbox]').checked; }
    function settle() {
      var items = [].slice.call(list.children);
      items.filter(isOn).concat(items.filter(function (li) { return !isOn(li); })).forEach(function (li) { list.appendChild(li); });
    }
    [].forEach.call(list.querySelectorAll('input[type=checkbox]'), function (cb) {
      cb.addEventListener('change', function () { settle(); if (order().join(',') !== saved.join(',')) save(); else sum(); });
    });
    // Drag a row by its handle: pointer events, so a finger works as well as a mouse (the browser's own drag and drop does
    // not run on a touch screen). While it is held, the row only follows the pointer and the rows it passes slide out of its
    // way; the list itself is reordered once, when it is let go (moving the row in the page while it is held would make the
    // browser let go of the pointer after the first step).
    [].forEach.call(list.children, function (li) {
      var grip = li.querySelector('.sf-grip'), active = false, rows, rects, startY, gap, newIdx, idx0;
      function clear() { rows.forEach(function (r) { r.style.transform = ''; }); }
      grip.addEventListener('pointerdown', function (ev) {
        if (ev.button !== undefined && ev.button !== 0) return;
        rows = [].slice.call(list.children);
        rects = rows.map(function (r) { return r.getBoundingClientRect(); });
        idx0 = rows.indexOf(li); newIdx = idx0; startY = ev.clientY;
        gap = rows.length > 1 ? Math.max(0, rects[1].top - rects[0].bottom) : 0;
        active = true; li.classList.add('dragging'); list.classList.add('is-dragging');
        try { grip.setPointerCapture(ev.pointerId); } catch (e) {}
        ev.preventDefault();
      });
      grip.addEventListener('pointermove', function (ev) {
        if (!active) return;
        var me = rects[idx0], dy = ev.clientY - startY;
        var lo = rects[0].top - me.top, hi = rects[rows.length - 1].bottom - me.bottom;
        dy = Math.max(lo, Math.min(hi, dy));   // stays inside the list
        li.style.transform = 'translateY(' + dy + 'px)';
        var centre = me.top + me.height / 2 + dy;
        newIdx = 0;
        rows.forEach(function (r, k) { if (r !== li && rects[k].top + rects[k].height / 2 < centre) newIdx++; });
        if (dy >= hi - 1) newIdx = rows.length - 1; else if (dy <= lo + 1) newIdx = 0;   // all the way down / up is the last / first place
        var by = me.height + gap;
        rows.forEach(function (r, k) {
          if (r === li) return;
          var shift = 0;
          if (idx0 < newIdx && k > idx0 && k <= newIdx) shift = -by; else if (idx0 > newIdx && k < idx0 && k >= newIdx) shift = by;
          r.style.transform = shift ? 'translateY(' + shift + 'px)' : '';
        });
      });
      function end(ev, commit) {
        if (!active) return;
        active = false;
        try { grip.releasePointerCapture(ev.pointerId); } catch (e) {}
        li.classList.remove('dragging'); list.classList.remove('is-dragging');
        li.style.transform = ''; clear();
        grip.blur(); grip.classList.add('rest');   // no focus ring and no hover tint left behind (a touch screen keeps :hover after a tap)
        if (!commit) return;
        var rest = rows.filter(function (r) { return r !== li; });
        rest.splice(newIdx, 0, li);
        rest.forEach(function (r) { list.appendChild(r); });
        settle();
        if (order().join(',') !== saved.join(',')) save(); else sum();
      }
      grip.addEventListener('pointerleave', function () { grip.classList.remove('rest'); });
      grip.addEventListener('pointerup', function (ev) { end(ev, true); });
      grip.addEventListener('pointercancel', function (ev) { end(ev, false); });
      // The keyboard: up and down arrows on the handle move the row.
      grip.addEventListener('keydown', function (ev) {
        if (ev.key !== 'ArrowUp' && ev.key !== 'ArrowDown') return;
        ev.preventDefault();
        if (ev.key === 'ArrowUp' && li.previousElementSibling) list.insertBefore(li, li.previousElementSibling);
        else if (ev.key === 'ArrowDown' && li.nextElementSibling) list.insertBefore(li.nextElementSibling, li);
        else return;
        settle(); grip.focus(); if (order().join(',') !== saved.join(',')) save(); else sum();
      });
    });
    
  }
  // In a folder's own settings: the list shows what the folder uses. It follows the site's until a switch or a row is changed;
  // then it is this folder's own (kept in the form until it is saved), and one button goes back to the site's.
  [].forEach.call(document.querySelectorAll('.sortfirst[data-mode=form]'), function (box) {
    var hidden = box.querySelector('input.sf-order'), radios = box.querySelectorAll('input[name=photo_sort_first]');
    function orderNow() { return [].map.call(box.querySelectorAll('.sf-list li'), function (li) { return li.getAttribute('data-kind'); }).join(','); }
    function own(isOwn) { [].forEach.call(radios, function (r) { r.checked = (r.value === 'own') === isOwn; }); label(); }
    // The heading of the section says whether anything here is the folder's own (it follows what is on the page, not only what is saved).
    function label() {
      var det = box.closest('details'), tag = det && det.querySelector('summary .muted');
      if (!tag) return;
      var custom = false;
      [].forEach.call(det.querySelectorAll('input[type=radio]:checked'), function (r) { if (r.value !== '') custom = true; });
      tag.innerHTML = custom ? '&middot; custom' : '&middot; the site\'s order';
    }
    [].forEach.call(det_radios(), function (r) { r.addEventListener('change', label); });
    function det_radios() { var det = box.closest('details'); return det ? det.querySelectorAll('.sort-row input[type=radio]') : []; }
    var siteOrder = (box.getAttribute('data-site-order') || '').split(','), siteOn = {};
    try { siteOn = JSON.parse(box.getAttribute('data-site-on')) || {}; } catch (e) {}
    function effective(arr, on) { return arr.filter(function (k) { return on[k]; }).join(','); }
    var siteEff = effective(siteOrder, siteOn);
    // After any change: if the list would sort exactly as the site's does, it IS the site's (the pill says so and the list shows the
    // site's own); otherwise it is this folder's own. (All switches off is this folder's own while the site puts something first.)
    function evaluate() {
      var on = {}; [].forEach.call(box.querySelectorAll('.sf-list li'), function (li) { on[li.getAttribute('data-kind')] = li.querySelector('input[type=checkbox]').checked; });
      if (effective(orderNow().split(','), on) === siteEff) { box.sfSet(siteOrder, siteOn); hidden.value = orderNow(); own(false); }
      else { hidden.value = orderNow(); own(true); }
      box.dispatchEvent(new CustomEvent('sf-changed', { bubbles: true }));
    }
    initSortFirst(box, function (now, ok) { ok(); evaluate(); });
    [].forEach.call(box.querySelectorAll('input[type=checkbox]'), function (cb) { cb.addEventListener('change', evaluate); });
    [].forEach.call(radios, function (r) {
      r.addEventListener('change', function () {
        if (!r.checked) return;
        if (r.value === '') {   // back to the site's: its switches and its order
          var arr = (box.getAttribute('data-site-order') || '').split(','), on = {};
          try { on = JSON.parse(box.getAttribute('data-site-on')) || {}; } catch (e) {}
          box.sfSet(arr, on); hidden.value = orderNow();
        } else { hidden.value = orderNow(); }
        label();
      });
    });
  });
  [].forEach.call(document.querySelectorAll('.site-settings-form'), function (form) {
    var hashInput = form.querySelector('input[name=config_hash]');
    var csrf = form.querySelector('input[name=csrf_token]').value;
    var chain = Promise.resolve();
    var sync = document.querySelector('.sitesync');
    form.addEventListener('submit', function (ev) { ev.preventDefault(); });   // no submit button without JavaScript: Enter must not post the whole form
    function say(node, text, kind) {
      if (!node) return;
      node.textContent = text; node.className = 'rowstate ' + (kind || '');
      clearTimeout(node._t);
      if (kind === 'ok') node._t = setTimeout(function () { node.textContent = ''; node.className = 'rowstate'; }, 3000);
    }
    // The folder list and the tab counts are redrawn from a fresh copy of the page the moment a setting is saved
    // (subfolders on or off, videos on or off ...), so they never lag behind what you just changed.
    var refreshTimer;
    function refreshChrome() {
      clearTimeout(refreshTimer);
      refreshTimer = setTimeout(function () {
        fetch(location.pathname + location.search, { credentials: 'same-origin' })
          .then(function (r) { return r.text(); })
          .then(function (html) {
            var doc = new DOMParser().parseFromString(html, 'text/html');
            var fresh = doc.querySelector('.admin-folders'), old = document.querySelector('.admin-folders');
            if (fresh && old) { old.innerHTML = fresh.innerHTML; initFolderTree(); }
            [].forEach.call(document.querySelectorAll('.admin-tabs a[data-tab]'), function (a) {
              var c = a.querySelector('.count'), f = doc.querySelector('.admin-tabs a[data-tab="' + a.getAttribute('data-tab') + '"] .count');
              if (c && f) c.textContent = f.textContent;
            });
          })
          .catch(function () {});
      }, 200);
    }
    function stateOf(el) { var holder = el.closest('.pwline') || el.closest('.ctl'); return holder ? holder.querySelector('.rowstate') : null; }
    function showManual(text) {
      var box = form.querySelector('.manual-box');
      if (!box) {
        box = document.createElement('div'); box.className = 'manual-box';
        box.innerHTML = '<p class="muted">The gallery\'s folder isn\'t writable, so nothing could be saved. Create a file named <code>.gallery-config.php</code> next to <code>index.php</code> containing exactly this:</p><textarea readonly rows="10" style="width:100%;font-family:ui-monospace,monospace;"></textarea>';
        form.insertBefore(box, form.firstChild.nextSibling);
      }
      box.querySelector('textarea').value = text;
    }
    function post(fields, node, onFail, onOk) {
      chain = chain.then(function () {
        say(node, 'Saving…', 'busy');
        var body = new URLSearchParams();
        body.set('csrf_token', csrf); body.set('action', 'save_site_settings'); body.set('config_hash', hashInput.value);
        Object.keys(fields).forEach(function (k) { body.set(k, fields[k]); });
        return fetch(location.pathname + location.search, { method: 'POST', credentials: 'same-origin', body: body.toString(),
            headers: { 'X-Requested-With': 'gallery-fetch', 'Content-Type': 'application/x-www-form-urlencoded' } })
          .then(function (r) { if ((r.headers.get('content-type') || '').indexOf('json') < 0) throw new Error('session'); return r.json(); })
          .then(function (j) {
            if (j.ok && j.hash) hashInput.value = j.hash;
            if (j.ok) { say(node, j.text, 'ok'); window.__siteDirty = true; if (sync) sync.hidden = false; refreshChrome(); if (onOk) onOk(j); }
            else { say(node, j.text, 'err'); if (onFail) onFail(); if (j.manual) showManual(j.manual); }
          })
          .catch(function (e) { say(node, e.message === 'session' ? 'Your session ended — reload the page and sign in again.' : 'Couldn\'t save — check your connection.', 'err'); if (onFail) onFail(); });
      });
    }
    [].forEach.call(form.querySelectorAll('input[type=checkbox][data-label]'), function (cb) {
      cb.addEventListener('change', function () {
        var on = cb.checked, off = cb.getAttribute('data-offwarn');
        var row = cb.closest('.site-row'), note = row ? row.querySelector('.offnote') : null;
        var f = {}; f[cb.name] = on ? '1' : '0';
        // Settings that only matter while this one is on (the pin grouping, under the map) follow it.
        function follow(shown) { [].forEach.call(form.querySelectorAll('.site-row[data-requires="' + cb.name + '"]'), function (r) { r.hidden = !shown; }); }
        follow(on);
        // What switching it off does appears beside the switch once it is off (and goes when it is back on), instead of in a dialog.
        post(f, stateOf(cb), function () { cb.checked = !on; follow(!on); }, function () { if (note) note.hidden = on || !off; });
      });
    });
    // On the Site settings page: the switches are saved by the checkbox code above; the order is saved here.
    [].forEach.call(form.querySelectorAll('.sortfirst'), function (box) {
      var status = stateOf(box);
      initSortFirst(box, function (now, ok, fail) { var f = {}; f[box.getAttribute('data-name')] = now.join(','); post(f, status, fail, ok); });
    });
    [].forEach.call(form.querySelectorAll('select[name^=set_], input[type=number][name^=set_]'), function (el) {
      el.setAttribute('data-prev', el.value);
      el.addEventListener('change', function () {
        var f = {}; f[el.name] = el.value;
        post(f, stateOf(el), function () { el.value = el.getAttribute('data-prev'); }, function () { el.setAttribute('data-prev', el.value); });
      });
    });
    [].forEach.call(form.querySelectorAll('.seg'), function (seg) {
      var radios = seg.querySelectorAll('input[type=radio][name^=set_]');
      if (!radios.length) return;
      var prev = (seg.querySelector('input:checked') || {}).value;
      [].forEach.call(radios, function (r) {
        r.addEventListener('change', function () {
          var f = {}; f[r.name] = r.value;
          post(f, stateOf(r), function () { [].forEach.call(radios, function (x) { x.checked = x.value === prev; }); }, function () { prev = r.value; });
        });
      });
    });
    [].forEach.call(form.querySelectorAll('input[type=text][name^=set_]'), function (el) {
      el.setAttribute('data-prev', el.value);
      el.addEventListener('change', function () {
        el.value = el.value.trim();
        var f = {}; f[el.name] = el.value;
        post(f, stateOf(el), function () { el.value = el.getAttribute('data-prev'); }, function () { el.setAttribute('data-prev', el.value); });
      });
    });
    [].forEach.call(form.querySelectorAll('.extra-folders'), function (box) {
      var state = { named: {}, roots: [] };
      try { var parsed = JSON.parse(box.getAttribute('data-map')) || {}; state.named = parsed.named || {}; state.roots = parsed.roots || []; } catch (e) {}
      var list = box.querySelector('.extra-list'), none = box.querySelector('.extra-none'), status = stateOf(box);
      function item(parts, kind, key) {
        var li = document.createElement('li'), btn = document.createElement('button');
        parts.forEach(function (p) { li.appendChild(p); li.appendChild(document.createTextNode(' ')); });
        btn.type = 'button'; btn.className = 'extra-remove secondary'; btn.textContent = 'Remove'; btn.setAttribute('data-kind', kind); btn.setAttribute('data-name', key);
        li.appendChild(btn); list.appendChild(li);
      }
      function el(tag, text, cls) { var e = document.createElement(tag); e.textContent = text; if (cls) e.className = cls; return e; }
      function draw() {
        list.textContent = '';
        state.roots.forEach(function (p) { item([el('code', p), el('span', '\u2192 its folders are at the top of the gallery', 'muted')], 'root', p); });
        Object.keys(state.named).forEach(function (name) { item([el('strong', name), el('span', '\u2192', 'muted'), el('code', state.named[name])], 'named', name); });
        none.hidden = state.roots.length + Object.keys(state.named).length > 0;
      }
      function copy() { var n = {}; Object.keys(state.named).forEach(function (k) { n[k] = state.named[k]; }); return { named: n, roots: state.roots.slice() }; }
      function send(next, onOk) {
        var f = {}; f[box.getAttribute('data-name')] = JSON.stringify(next);
        post(f, status, null, function (j) { state = (j.extra && typeof j.extra === 'object') ? { named: j.extra.named || {}, roots: j.extra.roots || [] } : next; draw(); if (onOk) onOk(); });
      }
      box.querySelector('.extra-add-btn').addEventListener('click', function () {
        var n = box.querySelector('.extra-name').value.trim(), p = box.querySelector('.extra-path').value.trim();
        if (!p) { say(status, 'Give the full path of the place to add.', 'err'); return; }
        var next = copy();
        if (n) next.named[n] = p; else next.roots.push(p);
        send(next, function () { box.querySelector('.extra-name').value = ''; box.querySelector('.extra-path').value = ''; });
      });
      list.addEventListener('click', function (ev) {
        var b = ev.target.closest ? ev.target.closest('.extra-remove') : null;
        if (!b) return;
        var n = b.getAttribute('data-name'), next = copy();
        if (b.getAttribute('data-kind') === 'root') next.roots = next.roots.filter(function (r) { return r !== n; }); else delete next.named[n];
        send(next, function () { say(status, 'Removed. Nothing was deleted: its photos and their captions stay where they are.', 'ok'); });
      });
      draw();
    });
    [].forEach.call(form.querySelectorAll('.pw-btn'), function (btn) {
      function go() {
        var kind = btn.getAttribute('data-pw'), node = stateOf(btn), f = {}, els = form.elements;
        if (kind === 'admin') { f.pw_admin = els['pw_admin'].value; f.pw_admin2 = els['pw_admin2'].value; }
        else if (kind === 'private') { f.pw_private = els['pw_private'].value; f.pw_private2 = els['pw_private2'].value; }
        else { f.pw_private_remove = '1'; }
        if ((kind === 'admin' && !f.pw_admin) || (kind === 'private' && !f.pw_private)) { say(node, 'Type the new password first.', 'err'); return; }
        post(f, node, null, function () {
          ['pw_admin', 'pw_admin2', 'pw_private', 'pw_private2'].forEach(function (n) { if (els[n]) els[n].value = ''; });
        });
      }
      btn.addEventListener('click', go);
      if (btn.getAttribute('data-pw') === 'private-remove') return;
      // Enter in a password box does what the button next to it does.
      [].forEach.call(btn.closest('.ctl').querySelectorAll('input[type=password]'), function (input) {
        input.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); btn.click(); } });
      });
    });
  });
  function admConfirmSaveAs(form) {
    const newNameField = form.querySelector('.adm-newname');
    if (newNameField && newNameField.value.trim() !== '') {
      return confirm('Save as "' + newNameField.value.trim() + '"? If that file already exists, it will be overwritten.');
    }
    return true;
  }
</script>
</body>
</html>
<?php
}

/**
 * First-run setup. With no admin password the admin doesn't exist (?admin is
 * a 404) — except for a short window right after this file is put on the
 * server, so the person who just uploaded it can choose a password in the
 * browser instead of editing a file. The window is SETUP seconds after
 * index.php's last modification (a re-upload reopens it), or whenever an
 * empty file named .gallery-setup sits next to it (proof you can write to the
 * folder, for coming back later). It closes for good once a password exists.
 * Whoever opens the page first in that window sets the password, which is
 * why the window is short and nothing public ever mentions it.
 */
function admin_setup_window_seconds() {
    return 300;
}

function admin_setup_window_open() {
    clearstatcache(true, __FILE__);
    $mtime = @filemtime(__FILE__);
    if ($mtime !== false) {
        $age = time() - $mtime;
        if ($age >= -60 && $age < admin_setup_window_seconds()) return true;
    }
    return is_file(__DIR__ . '/.gallery-setup');
}

/** Sets the first admin password, once, under a lock so two simultaneous
 *  visitors can't both win. Returns ['ok' => true] or ['error' => text,
 *  'manual' => settings-file text to create by hand (when it can't be
 *  written)]. Only the settings file is touched; any settings already in it
 *  are kept. */
function admin_setup_claim($password) {
    $lock = @fopen(__FILE__, 'r');
    if ($lock) flock($lock, LOCK_EX);
    try {
        $path = gallery_config_path();
        $raw = is_file($path) ? @file_get_contents($path) : '';
        if ($raw === false) return ['error' => 'The settings file exists but can\'t be read, so nothing was changed.'];
        [$status, $cfg] = gallery_config_parse((string) $raw);
        if ($status !== 'ok') return ['error' => 'The settings file (.gallery-config.php) exists but isn\'t valid, and saving would overwrite it. Fix or delete that file, then try again.'];
        if (isset($cfg['ADMIN_PASSWORD']) && is_string($cfg['ADMIN_PASSWORD']) && $cfg['ADMIN_PASSWORD'] !== '') {
            return ['error' => 'An admin password has just been set by someone else. If that wasn\'t you, delete .gallery-config.php and set it again.', 'taken' => true];
        }
        $stored = password_for_storage($password);
        $cfg['ADMIN_PASSWORD'] = $stored;
        $content = gallery_config_render($cfg);
        if ($content === null) return ['error' => 'That password has characters that can\'t be stored. Try a different one.'];
        $manual = ['error' => 'The gallery\'s folder isn\'t writable by the web server, so the password couldn\'t be saved. Create a file named .gallery-config.php next to index.php containing exactly the text below, then reload this page.', 'manual' => $content];
        if (!is_writable(__DIR__)) return $manual;
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $content, LOCK_EX) === false) { @unlink($tmp); return $manual; }
        @chmod($tmp, 0600);
        [$roundStatus, $roundTrip] = gallery_config_parse((string) @file_get_contents($tmp));
        if ($roundStatus !== 'ok' || ($roundTrip['ADMIN_PASSWORD'] ?? null) !== $stored) { @unlink($tmp); return ['error' => 'The settings file didn\'t read back correctly, so it was not installed.']; }
        if (!@rename($tmp, $path)) { @unlink($tmp); return $manual; }
        return ['ok' => true, 'stored' => $stored];
    } finally {
        if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    }
}

function render_setup_page($error = '', $manual = '') {
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Robots-Tag: noindex, nofollow');
    $minutes = (int) round(admin_setup_window_seconds() / 60);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Set up your gallery</title>
<style>
  :root { color-scheme: dark; }
  body { background:#0f0f10; color:#eaeaea; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; line-height:1.5; margin:0; }
  .wrap { max-width:440px; margin:0 auto; padding:64px 24px; }
  h1 { font-size:1.35rem; margin:0 0 8px; }
  p { color:#9a9aa0; font-size:0.92rem; margin:0 0 16px; }
  label { display:block; font-size:0.8rem; color:#9a9aa0; margin:12px 0 4px; }
  input[type=password] { width:100%; box-sizing:border-box; padding:10px 12px; font-size:1rem; background:#1a1a1c; color:#eaeaea; border:1px solid #3a3a3e; border-radius:8px; }
  input[type=password]:focus { outline:2px solid #5865f2; outline-offset:1px; }
  button { margin-top:16px; width:100%; padding:11px 12px; font-size:1rem; background:#5865f2; color:#fff; border:0; border-radius:8px; cursor:pointer; }
  .err { background:rgba(237,66,69,0.15); color:#ff8a8c; padding:9px 12px; border-radius:8px; font-size:0.9rem; margin-bottom:12px; }
  .warn { background:rgba(245,166,35,0.1); border:1px solid rgba(245,166,35,0.35); color:#f5c26b; padding:9px 12px; border-radius:8px; font-size:0.85rem; margin-bottom:16px; }
  textarea { width:100%; box-sizing:border-box; font-family:ui-monospace,monospace; font-size:0.8rem; background:#1a1a1c; color:#eaeaea; border:1px solid #3a3a3e; border-radius:8px; padding:8px; }
</style>
</head>
<body>
<div class="wrap">
  <h1>Set up your gallery</h1>
  <p>Choose the password that will unlock this gallery's admin: captions, folder settings, notes and the site settings. You'll be signed in straight away.</p>
  <div class="warn">Whoever opens this page first sets the password, so do it now. This page switches itself off <?= $minutes ?> minutes after <code>index.php</code> was uploaded, and for good once a password exists.</div>
  <?php if ($error !== ''): ?><div class="err" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <?php if ($manual !== ''): ?><textarea readonly rows="8" onfocus="this.select()"><?= htmlspecialchars($manual, ENT_QUOTES, 'UTF-8') ?></textarea><?php endif; ?>
  <form method="post" action="">
    <label for="setup_password">Admin password (8 or more characters, with letters and numbers)</label>
    <input type="password" id="setup_password" name="setup_password" autocomplete="new-password" autofocus required minlength="8">
    <label for="setup_password2">Type it again</label>
    <input type="password" id="setup_password2" name="setup_password2" autocomplete="new-password" required minlength="8">
    <button type="submit">Set password and sign in</button>
  </form>
</div>
</body>
</html>
<?php
}

function run_admin_setup() {
    if (!is_https_request() && !is_local_request()) {
        render_https_required_page();
        return;
    }
    $error = ''; $manual = '';
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['setup_password']) && is_string($_POST['setup_password'])) {
        $pw = str_replace("\0", '', $_POST['setup_password']);
        $pw2 = is_string($_POST['setup_password2'] ?? null) ? str_replace("\0", '', $_POST['setup_password2']) : '';
        if ($pw !== $pw2) $error = 'The two passwords you typed don\'t match.';
        elseif (!admin_password_ok($pw)) $error = 'Use at least 8 characters with at least one letter and one number: this one password can change your whole gallery.';
        elseif (preg_match('/[\x00-\x1F\x7F]/', $pw)) $error = 'The password can\'t contain control characters.';
        else {
            $result = admin_setup_claim($pw);
            if (!empty($result['ok'])) {
                // Signed in as part of setting it up, and the setup file (if any) has done its job.
                admin_start_session();
                session_regenerate_id(true);
                $_SESSION['admin_auth'] = admin_session_marker($result['stored']);
                set_admin_reveal_cookie($result['stored']);
                $_SESSION['admin_flash'] = ['type' => 'ok', 'text' => 'Your admin password is set and you\'re signed in. Site settings (left) is where the gallery-wide settings live; the password is kept in .gallery-config.php, which survives updating index.php.'];
                @unlink(__DIR__ . '/.gallery-setup');
                header('Location: ?admin#site', true, 303);
                return;
            }
            $error = (string) ($result['error'] ?? 'Could not set the password.');
            $manual = (string) ($result['manual'] ?? '');
            if (!empty($result['taken'])) {
                // Someone else got there first: this is a login page now.
                render_login_page('Enter your access code', '', $error);
                return;
            }
        }
    }
    render_setup_page($error, $manual);
}

/** ?admin&accelcheck=1&prefix=/kb: answers the way the gallery answers for a photo once "nginx internal redirect" is set to
 *  that prefix: no body, just an X-Accel-Redirect to one of the gallery's own photos. Behind an nginx that has the matching
 *  `internal` location the browser gets the picture; anywhere else it gets nothing, which is how the admin tells whether
 *  setting it would work (or whether what is set does). Admin only; sends no file itself. */
function send_accel_check($dir, $files) {
    header('Cache-Control: no-store');
    $prefix = (string) ($_GET['prefix'] ?? '');
    $target = lock_probe_targets($dir, $files)['file'];
    if ($prefix === '' || !site_settings_valid_nginx_prefix($prefix) || $target === '') {
        http_response_code(404);
        return;
    }
    $urlPath = implode('/', array_map('rawurlencode', explode('/', $target)));
    header('Content-Type: ' . guess_mime(strtolower(pathinfo($target, PATHINFO_EXTENSION))));
    header('X-Accel-Redirect: ' . rtrim($prefix, '/') . '/' . $urlPath);
}

/**
 * Entry point for ?admin — auth (HTTPS, Basic Auth, throttle), then
 * dispatches to a POST action handler or the page itself. Exits the
 * script either way; nothing below this ever runs for an admin request.
 */
function run_admin_route($dir, $files, $groups, $folderGroupKeys, $explicitGroups, $explicitGroupKeys, $videoDurations, $galleryNotices) {
    send_sensitive_page_headers();
    if (ADMIN_PASSWORD === '') {
        // No password yet: the admin doesn't exist (404) — except right after upload, when it can be set here.
        if (admin_setup_window_open()) run_admin_setup(); else http_response_code(404);
        return;
    }
    if (!is_https_request() && !is_local_request()) {
        render_https_required_page();
        return;
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (!login_throttle_allows($dir, 'admin', $ip)) {
        http_response_code(429);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Too many failed attempts. Try again in a moment.';
        return;
    }
    // Already logged in (the session carries it), or a Basic Authorization
    // header (scripts), or the password form. A visitor with no session
    // cookie never starts a session just by asking for ?admin.
    if (isset($_COOKIE[admin_session_name()])) admin_start_session();
    $sessionAuthed = session_status() === PHP_SESSION_ACTIVE && admin_session_authed();
    if (!$sessionAuthed) {
        $basicSupplied = isset($_SERVER['PHP_AUTH_USER']) || isset($_SERVER['PHP_AUTH_PW']);
        $formPass = !isset($_POST['action']) ? login_form_password() : null;
        if ($basicSupplied && $formPass === null) {
            if (!admin_credentials_valid()) {
                login_throttle_record_failure($dir, 'admin', $ip);
                admin_reject_saved_credentials();
                return;
            }
        } elseif ($formPass !== null) {
            if (!password_matches(ADMIN_PASSWORD, $formPass)) {
                login_throttle_record_failure($dir, 'admin', $ip);
                render_login_page('Enter your access code', '', 'That code isn\'t right.');
                return;
            }
            // A fresh session id at the moment of login, so nothing issued
            // before it can ever be a logged-in session.
            admin_start_session();
            session_regenerate_id(true);
            $upgraded = upgrade_plaintext_passwords();
            $_SESSION['admin_auth'] = admin_session_marker($upgraded['ADMIN_PASSWORD'] ?? null);
            login_throttle_record_success($dir, 'admin', $ip);
            set_admin_reveal_cookie($upgraded['ADMIN_PASSWORD'] ?? null);
            header('Location: ?' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? $_SERVER['QUERY_STRING'] : 'admin'), true, 303);
            return;
        } else {
            render_login_page('Enter your access code', '', $_SERVER['REQUEST_METHOD'] === 'POST' ? 'Your session expired. Sign in again, then repeat that change.' : '');
            return;
        }
    }
    if (!$sessionAuthed) login_throttle_record_success($dir, 'admin', $ip);
    // A valid admin login counts as revealed too — one-directional, and
    // this is what makes it true on the *public* gallery view, not just
    // inside ?admin itself (a plain cookie, since the public page is
    // never behind its own session/Basic-Auth check). Not when the password
    // is being changed in this very request: that sets the new token itself,
    // and a second Set-Cookie for the same name would only be noise.
    if (!(($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'save_site_settings' && !empty($_POST['pw_admin']))) {
        set_admin_reveal_cookie();
    }
    admin_start_session();

    if (isset($_GET['accelcheck'])) {
        send_accel_check($dir, $files);
        return;
    }
    if (isset($_GET['csrf'])) {   // the owner's quick private toggle on the gallery pages asks for this before it saves
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo json_encode(['token' => admin_csrf_token()]);
        return;
    }

    if (isset($_GET['item'])) {   // the owner's photo editor in the viewer asks for one photo's record before it opens
        admin_item_json($dir, $files);
        return;
    }

    if (isset($_GET['hidden'])) {
        // Older link: the hidden-items summary is the "Hidden" tab now.
        header('Location: ?admin#hidden', true, 302);
        return;
    }

    $adminFolderPath = admin_resolve_folder($files);
    if ($adminFolderPath === false) {
        http_response_code(404);
        return;
    }
    $adminFolderReal = $adminFolderPath === null ? $dir : resolve_real_path($dir, $adminFolderPath);
    if (isset($_GET['privcounts']) && $adminFolderPath !== null) {   // what making this folder public would do, asked for fresh when the switch is turned off
        header('Cache-Control: no-store');
        admin_json_exit(['ok' => true, 'counts' => folder_privacy_counts($dir, $files, $adminFolderPath)]);
    }
    // Just what sits loose directly in this folder — its subfolders get
    // their own admin page, reached from the nav, so an edit's target path
    // is always exactly <this folder>/<bare filename>.
    $adminFiles = split_folder_level($files, $adminFolderPath === null ? '' : $adminFolderPath . '/')['loose'];

    $resultMessage = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $GLOBALS['__data_write_failed'] = false;
        $resultMessage = handle_admin_post($dir, $adminFolderPath, $adminFolderReal, $files, $groups, $explicitGroups);
        // A save that couldn't write must not read as a save: say what to fix.
        if (!empty($GLOBALS['__data_write_failed']) && ($resultMessage['type'] ?? '') === 'ok') {
            $resultMessage = ['type' => 'error', 'text' => 'Nothing was saved: this folder isn\'t writable by the web server. Give the web server\'s user write permission on it (on many hosts: chmod 755 or 775 on the folder, owned by that user), then try again.'];
        }
        if ($resultMessage['type'] === 'redirect') {
            // Post, redirect, get: the new settings take effect on the next request, and the page shows them.
            header('Location: ?' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? $_SERVER['QUERY_STRING'] : 'admin') . '#site', true, 303);
            return;
        }
        if ($resultMessage['type'] === 'logout') {
            // Back to being an ordinary visitor: no admin session, and no
            // private-view cookie either (a login sets both).
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                setcookie(session_name(), '', ['expires' => 1, 'path' => '/', 'secure' => is_https_request(), 'httponly' => true, 'samesite' => 'Strict']);
            }
            session_destroy();
            setcookie(REVEAL_COOKIE_NAME, '', ['expires' => 1, 'path' => '/', 'secure' => is_https_request(), 'httponly' => true, 'samesite' => 'Lax']);
            // Back on the gallery page of the folder being edited, now signed out.
            header('Location: ' . ($adminFolderPath !== null ? '?folder=' . rawurlencode($adminFolderPath) : gallery_root_href()), true, 303);
            return;
        }
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'gallery-fetch' && in_array($resultMessage['type'] ?? '', ['ok', 'error'], true)) {
            // A setting flipped in place by the page's script: answer in JSON, no page.
            admin_json_exit(['ok' => $resultMessage['type'] === 'ok', 'text' => (string) ($resultMessage['text'] ?? '')] + array_intersect_key($resultMessage, ['hash' => 1, 'stale' => 1, 'counts' => 1]));
        }
        if ($resultMessage['type'] === 'export') {
            admin_export_items($adminFolderPath, $adminFolderReal);
            return;
        }
        if ($resultMessage['type'] === 'export_tree') {
            admin_export_tree($dir, $files);
            return;
        }
    }

    if ($resultMessage === null && !empty($_SESSION['admin_flash']) && is_array($_SESSION['admin_flash'])) {
        $resultMessage = $_SESSION['admin_flash'];
        unset($_SESSION['admin_flash']);
    }
    $mdCandidates = scan_md_candidates($adminFolderReal);
    render_admin_page($dir, $files, $adminFolderPath, $adminFolderReal, $adminFiles, $groups, $folderGroupKeys, $explicitGroups, $explicitGroupKeys, $videoDurations, $mdCandidates, $resultMessage);
}

/* -----------------------------------------------------------------------
 * View-stats storage. Two interchangeable backends behind one small set
 * of functions (stats_bump_pageview / stats_bump_photo /
 * stats_get_photo_views) so the rest of the script never needs to know
 * or care which one is active. SQLite is used automatically when PHP
 * supports it; otherwise a flock-protected JSON file is used instead.
 *
 * Per-photo stats are colocated in whichever real folder a photo lives
 * in — same pattern as the thumbnail cache — so each folder gets its own
 * small .gallery-stats.php/.sqlite, scoped to just its own photos. A
 * flat gallery has exactly one such store (the root itself), so this is
 * a no-op change in shape for that case. Keyed by each photo's full
 * relative path (not stripped to a bare filename) purely to keep every
 * $photoViews[$file]-style lookup elsewhere in this script working
 * unchanged regardless of which store a photo's data lives in — the
 * safety from colocation (no cross-folder key collisions) comes from
 * *which file* the data lives in, not the key format inside it.
 *
 * The one sitewide pageview counter is the exception: it's not a
 * per-photo concept, so it always lives at the gallery root, regardless
 * of how many folders contribute photos.
 * ----------------------------------------------------------------------- */

$GLOBALS['__stats_pool'] = []; // real folder path => ['mode' => .., 'pdo' => .., 'file' => ..]

/** Lazily opens (or reuses) the stats connection for a given real folder. */
function stats_connection($folderPath) {
    if (isset($GLOBALS['__stats_pool'][$folderPath])) {
        return $GLOBALS['__stats_pool'][$folderPath];
    }
    $conn = ['mode' => 'file', 'pdo' => null, 'file' => $folderPath . '/.gallery-stats.php'];
    if (class_exists('PDO') && in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        try {
            $pdo = new PDO('sqlite:' . $folderPath . '/.gallery-stats.sqlite');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('CREATE TABLE IF NOT EXISTS stats (key TEXT PRIMARY KEY, value INTEGER NOT NULL DEFAULT 0)');
            // Keyed by a hash of the photo's path, not the path itself: a
            // SQLite file is binary and can't carry the PHP guard the JSON
            // stores do, so it could be fetched by URL — and a readable
            // filename in it would hand over a hidden item's exact name.
            // Counts are still kept for every item, hidden or not.
            $pdo->exec('CREATE TABLE IF NOT EXISTS photo_views_h (key TEXT PRIMARY KEY, views INTEGER NOT NULL DEFAULT 0)');
            $conn['pdo'] = $pdo;
            $conn['mode'] = 'sqlite';
        } catch (Exception $e) {
            $conn['mode'] = 'file';
        }
    }
    $GLOBALS['__stats_pool'][$folderPath] = $conn;
    stats_import_legacy($folderPath, $conn);
    return $conn;
}

/** The stats key for a photo in the SQLite store. */
function stats_key($relativePath) {
    return hash('sha256', (string) $relativePath);
}

/**
 * One-time import of what a 1.x install already recorded, so upgrading
 * never zeroes the view counts: the plain-JSON store (.gallery-stats.json)
 * and the old SQLite table with readable filenames (photo_views). Both
 * are folded into the current store and then removed — the JSON file is
 * deleted, and the SQLite table is dropped and the file VACUUMed so the
 * old names don't linger in free pages. Best effort: if the folder isn't
 * writable the old data is simply left where it was.
 */
function stats_import_legacy($folderPath, $conn) {
    $legacy = ['pageviews' => 0, 'photos' => []];
    $legacyJson = $folderPath . '/.gallery-stats.json';
    $removeJson = false;
    if (is_file($legacyJson)) {
        $decoded = json_decode((string) @file_get_contents($legacyJson), true);
        if (is_array($decoded)) {
            $legacy['pageviews'] += (int) ($decoded['pageviews'] ?? 0);
            foreach ((array) ($decoded['photos'] ?? []) as $name => $views) {
                $legacy['photos'][(string) $name] = ($legacy['photos'][(string) $name] ?? 0) + (int) $views;
            }
            $removeJson = true;
        }
    }
    $dropLegacyTable = false;
    if ($conn['mode'] === 'sqlite') {
        try {
            $hasLegacyTable = (bool) $conn['pdo']->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'photo_views'")->fetchColumn();
            if ($hasLegacyTable) {
                foreach ($conn['pdo']->query('SELECT filename, views FROM photo_views') as $row) {
                    $legacy['photos'][(string) $row['filename']] = ($legacy['photos'][(string) $row['filename']] ?? 0) + (int) $row['views'];
                }
                $dropLegacyTable = true;
            }
        } catch (Exception $e) {
            return;
        }
    }
    if (!$removeJson && !$dropLegacyTable) return;

    if ($conn['mode'] === 'sqlite') {
        $pdo = $conn['pdo'];
        try {
            $pdo->beginTransaction();
            if ($legacy['pageviews'] > 0) {
                $pdo->prepare("INSERT INTO stats(key, value) VALUES('pageviews', :v) ON CONFLICT(key) DO UPDATE SET value = value + :v")
                    ->execute([':v' => $legacy['pageviews']]);
            }
            $ins = $pdo->prepare('INSERT INTO photo_views_h(key, views) VALUES(:k, :v) ON CONFLICT(key) DO UPDATE SET views = views + :v');
            foreach ($legacy['photos'] as $name => $views) {
                $ins->execute([':k' => stats_key($name), ':v' => $views]);
            }
            if ($dropLegacyTable) $pdo->exec('DROP TABLE photo_views');
            $pdo->commit();
            if ($dropLegacyTable) {
                $pdo->exec('VACUUM');
                $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return;
        }
    } else {
        stats_update_file($conn['file'], function (&$data) use ($legacy) {
            $data['pageviews'] = (int) ($data['pageviews'] ?? 0) + $legacy['pageviews'];
            foreach ($legacy['photos'] as $name => $views) {
                $data['photos'][$name] = (int) ($data['photos'][$name] ?? 0) + $views;
            }
        });
        // If the new store couldn't be created (unwritable folder), keep
        // the old file: deleting it now would lose the only copy.
        if (!file_exists($conn['file'])) return;
    }
    if ($removeJson) @unlink($legacyJson);
}

/** Reads-modifies-writes the flat-file JSON backend under an exclusive lock. */
function stats_update_file($statsFile, $modifier) {
    $data = ['pageviews' => 0, 'photos' => []];
    $fp = @fopen($statsFile, 'c+');
    if ($fp) {
        if (flock($fp, LOCK_EX)) {
            $raw = stream_get_contents($fp);
            $decoded = json_decode(data_file_strip_guard($raw), true);
            if (is_array($decoded)) {
                $data = array_merge($data, $decoded);
            }
            if (!isset($data['photos']) || !is_array($data['photos'])) {
                $data['photos'] = [];
            }
            $modifier($data);
            $encoded = data_file_encode($data);
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, $encoded);
            fflush($fp);
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }
    return $data;
}

/** The one sitewide pageview counter — always the gallery root's own store. */
function stats_bump_pageview($rootDir) {
    $conn = stats_connection($rootDir);
    if ($conn['mode'] === 'sqlite') {
        $pdo = $conn['pdo'];
        $pdo->exec("INSERT INTO stats(key, value) VALUES('pageviews', 1)
                    ON CONFLICT(key) DO UPDATE SET value = value + 1");
        return (int) $pdo->query("SELECT value FROM stats WHERE key = 'pageviews'")->fetchColumn();
    }
    $stats = stats_update_file($conn['file'], function (&$data) {
        $data['pageviews'] = ($data['pageviews'] ?? 0) + 1;
    });
    return (int) $stats['pageviews'];
}

/** The sitewide page-load total, read without counting a view (the admin
 *  shows it; stats_bump_pageview() is what counts one). */
function stats_get_pageviews($rootDir) {
    $conn = stats_connection($rootDir);
    if ($conn['mode'] === 'sqlite') {
        return (int) $conn['pdo']->query("SELECT value FROM stats WHERE key = 'pageviews'")->fetchColumn();
    }
    if (!file_exists($conn['file'])) return 0;
    $data = json_decode(data_file_strip_guard(@file_get_contents($conn['file'])), true);
    return is_array($data) ? (int) ($data['pageviews'] ?? 0) : 0;
}

/** Increments one photo's view count (in its own folder's store) and returns its new total. */
function stats_bump_photo($folderPath, $relativePath) {
    $conn = stats_connection($folderPath);
    if ($conn['mode'] === 'sqlite') {
        $pdo = $conn['pdo'];
        $key = stats_key($relativePath);
        $stmt = $pdo->prepare(
            "INSERT INTO photo_views_h(key, views) VALUES(:k, 1)
             ON CONFLICT(key) DO UPDATE SET views = views + 1"
        );
        $stmt->execute([':k' => $key]);
        $sel = $pdo->prepare('SELECT views FROM photo_views_h WHERE key = :k');
        $sel->execute([':k' => $key]);
        return (int) $sel->fetchColumn();
    }
    $stats = stats_update_file($conn['file'], function (&$data) use ($relativePath) {
        $data['photos'][$relativePath] = ($data['photos'][$relativePath] ?? 0) + 1;
    });
    return (int) ($stats['photos'][$relativePath] ?? 0);
}

/** View counts from one folder's own store, as relativePath => views.
 *  $candidateFiles names the files whose counts are wanted: the SQLite
 *  store keeps only hashes, so it can answer only for paths it's asked
 *  about (the JSON store has the names and returns everything). */
function stats_get_photo_views($folderPath, $candidateFiles = []) {
    $conn = stats_connection($folderPath);
    if ($conn['mode'] === 'sqlite') {
        $byKey = [];
        foreach ($conn['pdo']->query('SELECT key, views FROM photo_views_h') as $row) {
            $byKey[$row['key']] = (int) $row['views'];
        }
        $result = [];
        foreach ($candidateFiles as $file) {
            $key = stats_key($file);
            if (isset($byKey[$key])) $result[$file] = $byKey[$key];
        }
        return $result;
    }
    if (!file_exists($conn['file'])) return [];
    $data = json_decode(data_file_strip_guard(@file_get_contents($conn['file'])), true);
    return (is_array($data) && isset($data['photos']) && is_array($data['photos'])) ? $data['photos'] : [];
}

/** Every tracked photo's view count across the whole tree, merged from
 *  each contributing folder's own store — safe to merge with a plain
 *  array union since every key is already a full relative path, unique
 *  regardless of which folder it came from. */
function stats_get_all_photo_views($dir, $files) {
    $byFolder = [];
    foreach ($files as $file) {
        $byFolder[containing_folder_real_path($dir, $file)][] = $file;
    }
    $result = [];
    foreach ($byFolder as $folderPath => $folderFiles) {
        $result += stats_get_photo_views($folderPath, $folderFiles);
    }
    return $result;
}

// How long a repeat ?track= ping for the same photo, from the same
// browser, goes un-counted. Caps write amplification from a trivially
// scriptable GET and makes the counts themselves more honest — flipping
// back and forth on one photo in a single sitting shouldn't inflate it.
define('VIEW_DEBOUNCE_SECONDS', 1800);

/**
 * True if this filename was already "viewed" (per the gallery_viewed
 * cookie) within the debounce window — and either way, updates that
 * cookie with the current timestamp for this filename. Stale entries
 * (older than the window) are pruned on every call so the cookie stays
 * small regardless of how many different photos get viewed in a
 * session.
 */
function view_debounce_recently_viewed($filename) {
    $decoded = json_decode((string) ($_COOKIE['gallery_viewed'] ?? ''), true);
    if (!is_array($decoded)) {
        $decoded = [];
    }
    $now = time();
    foreach ($decoded as $f => $ts) {
        if (!is_int($ts) || ($now - $ts) > VIEW_DEBOUNCE_SECONDS) {
            unset($decoded[$f]);
        }
    }
    $recentlyViewed = isset($decoded[$filename]) && ($now - $decoded[$filename]) < VIEW_DEBOUNCE_SECONDS;
    $decoded[$filename] = $now;
    setcookie('gallery_viewed', json_encode($decoded), [
        'expires' => $now + VIEW_DEBOUNCE_SECONDS,
        'path' => '/',
        'samesite' => 'Lax',
    ]);
    return $recentlyViewed;
}

/** True if the request's User-Agent matches a known link-preview bot/crawler. */
function is_bot_request() {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if ($ua === '') return false;
    return (bool) preg_match(
        '/bot|crawl|spider|slurp|facebookexternalhit|discordbot|slackbot|telegrambot' .
        '|whatsapp|embedly|quora|pinterest|redditbot|linkedinbot|applebot|ia_archiver|preview/i',
        $ua
    );
}

/** How long a visitor can go quiet and still be the same visit. */
define('VISIT_WINDOW_SECONDS', 1800);

/** True if this browser is the admin's own (it carries the admin's reveal token,
 *  set at login). The owner looking at their own gallery is not an audience. */
function is_admin_browser() {
    $token = admin_reveal_cookie_value();
    $cookie = $_COOKIE[REVEAL_COOKIE_NAME] ?? '';
    return $token !== '' && is_string($cookie) && $cookie !== '' && hash_equals($token, $cookie);
}

/** True if this request is a browser quietly fetching a page ahead of time, or
 *  a link checker asking for headers: neither is anyone looking at the gallery. */
function is_non_viewing_request() {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return true;
    foreach (['HTTP_PURPOSE', 'HTTP_SEC_PURPOSE', 'HTTP_X_PURPOSE', 'HTTP_X_MOZ'] as $header) {
        if (isset($_SERVER[$header]) && preg_match('/prefetch|prerender|preview/i', (string) $_SERVER[$header])) return true;
    }
    return false;
}

/**
 * Whether this page load begins a new VISIT, and so adds one to the site total.
 * A visit is one visitor for as long as they keep coming back within
 * VISIT_WINDOW_SECONDS of their last page: opening a dozen folders, a photo
 * link, reload and back button are all one visit. (Counting every page load,
 * as this once did, made the total several times the number of people.) It
 * does not count the admin's own browser, link-preview bots, prefetches or
 * HEAD requests. A small first-party cookie holds the time of the visitor's
 * last page; every counted page load slides it forward.
 */
function visit_is_new() {
    if (is_bot_request() || is_non_viewing_request() || is_admin_browser()) return false;
    $now = time();
    $last = (int) ($_COOKIE['gallery_visit'] ?? 0);
    $isNew = $last <= 0 || $last > $now + 60 || ($now - $last) > VISIT_WINDOW_SECONDS;
    setcookie('gallery_visit', (string) $now, [
        'expires' => $now + VISIT_WINDOW_SECONDS,
        'path' => '/',
        'secure' => is_https_request(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    return $isNew;
}

// ?track=<filename> is a tiny background ping fired by the lightbox each
// time a photo is displayed. Only counts filenames that are actually in
// this folder's photo list, skips known bots, and debounces a repeat
// ping for the same photo from the same browser (see
// view_debounce_recently_viewed() above). Responds with the fresh photo +
// group totals as JSON so the page can update the visible numbers
// immediately, with no reload — and never renders the gallery itself.
if (isset($_GET['track'])) {
    $response = ['ok' => false];
    if (TRACK_VIEWS && !is_bot_request() && !is_admin_browser() && !is_non_viewing_request()) {
        $requested = $_GET['track'];
        if (in_array($requested, $files, true)) {
            // A group's members always live in the same folder (folder
            // membership and directive scoping both guarantee this — see
            // GROUPING), so this one folder's store covers the whole
            // group's totals below, not just the requested photo.
            $requestedFolder = containing_folder_real_path($dir, $requested);
            $groupKey = $fileToGroupKey[$requested] ?? group_key($requested);
            $groupFiles = $groups[$groupKey] ?? [$requested];
            $currentPhotoViews = stats_get_photo_views($requestedFolder, $groupFiles);
            if (view_debounce_recently_viewed($requested)) {
                // Debounced: still report the current total, unincremented,
                // so the live-updating badge reflects reality instead of
                // looking stuck or broken.
                $newCount = (int) ($currentPhotoViews[$requested] ?? 0);
            } else {
                $newCount = stats_bump_photo($requestedFolder, $requested);
            }
            $groupTotal = 0;
            foreach ($groupFiles as $gf) {
                $groupTotal += ($gf === $requested) ? $newCount : (int) ($currentPhotoViews[$gf] ?? 0);
            }
            $response = [
                'ok' => true,
                'photo' => $requested,
                'photoViews' => $newCount,
                'groupSlug' => slugify($groupKey),
                'groupViews' => $groupTotal,
            ];
        }
    }
    header('Content-Type: application/json');
    send_viewer_aware_cache_headers();
    echo json_encode($response);
    exit;
}

// Normal page load: bump the total page-view count (unless this is a
// known bot — see "VIEW STATS" above) and load current per-photo counts
// to display in the gallery below.
$viewCount = 0;
$photoViews = [];
if (TRACK_VIEWS) {
    // The site total counts visits, not page loads (see visit_is_new()): the number shown is the running total either way.
    $viewCount = visit_is_new() ? stats_bump_pageview($dir) : stats_get_pageviews($dir);
    $photoViews = stats_get_all_photo_views($dir, $files);
}

gc_image_cache($dir, $allFiles, $imagickAvailable);
gc_items_data($dir, $allFiles, $galleryNotices);

// Snapshot of the root's own title, before any ?folder= override below
// ever touches $pageTitle — used for the breadcrumb back-link and for
// building a folder page's own "Folder — Root Title" title.
$rootPageTitle = $pageTitle;

// "Last updated" — an absolute date, not a relative "2 days ago" (no
// ticking-clock library needed), derived from the running maximum mtime
// across every photo and every .md file. Deliberately not a
// separately-maintained marker file: that only reflects admin-tool
// saves, so anyone who just drops in new photos via FTP without ever
// opening an admin tool would see a frozen, misleading date. This one
// mechanism naturally captures all of it — a new photo's own mtime, a
// notes .md's mtime — with no dedicated bookkeeping. Computed once here,
// over the gallery's full (unscoped) file list, and shown on every page
// including a ?folder= subpage — "last updated" describes the whole
// gallery, not just what's currently in view.
$lastUpdatedMtime = 0;
foreach ($files as $f) {
    $fp = ($folderTree !== null) ? resolve_real_path($dir, $f) : "$dir/$f";
    $m = @filemtime($fp);
    if ($m !== false && $m > $lastUpdatedMtime) $lastUpdatedMtime = $m;
}
foreach ($mdFiles as $mdFile) {
    $m = @filemtime("$dir/$mdFile");
    if ($m !== false && $m > $lastUpdatedMtime) $lastUpdatedMtime = $m;
}

// ?folder=<relative path> drills into one folder-group's own page from
// its cover card (FOLDER_DISPLAY_MODE='overview') — the same gallery
// shell, scoped down to just that folder's files in a flat grid, not a
// separate template. Deliberately not recursive into a further level of
// its own cover cards yet: any of its own sub-subfolders' files just
// show up flattened into the same grid, the same way 'inline' mode would
// show them. Validated here (against $groups, already fully built above)
// so an invalid value 404s before any further rendering work happens.
//
// $pageTitle is prefixed right away, using the raw folder path — the
// <title>, <h1> and Open Graph tags all render before the root's own .md
// gets a chance to rename this folder via {group:} (see the
// notes-rendering section further down), so a nicer renamed label simply
// isn't known yet at this point. A reasonable simplification: still
// accurate, just not renamed. $groups/$files themselves are scoped down
// separately, later, after that .md has run.
$currentFolderPath = null;
// Defined up front so no later template line can ever hit an undefined
// variable, whatever order the folder code above/below is rearranged into.
$currentFolderFiles = [];
$currentFolderMeta = ['label' => '', 'cover' => null, 'description' => '', 'dates' => '', 'sortOrder' => null];
$currentFolderName = '';
$currentFolderFileCount = 0;
$folderCrumbs = [];
$folderPills = [];       // quick links to the folders beside this one: [href, label, is this one]
$folderPillsAll = null;  // where "All" goes when only some of them are shown
if (isset($_GET['folder'])) {
    $requestedFolder = $_GET['folder'];
    // Valid only if the script's own (already hiding-filtered) file list
    // has something under it — a nested path like Europe/France/Paris
    // works the same as a top-level one, and a hidden, nonexistent, or
    // path-trick value (../, a trailing slash, a file) all simply have
    // nothing under them.
    $currentFolderFiles = AUTO_GROUP_BY_FOLDER ? folder_files_under($files, $requestedFolder) : [];
    if (empty($currentFolderFiles)) {
        http_response_code(404);
        exit;
    }
    $currentFolderPath = $requestedFolder;
    // Quick links to the folders beside this one (same level), in the order the parent's page shows them: up to ten, else a
    // window of nine around this one plus "All" back to the parent.
    $pillParent = strpos($currentFolderPath, '/') === false ? '' : dirname($currentFolderPath);
    $pillPrefix = $pillParent === '' ? '' : $pillParent . '/';
    $pillNames = array_keys(split_folder_level($pillParent === '' ? $files : folder_files_under($files, $pillParent), $pillPrefix)['folders']);
    if ($pillParent === '' && !empty($groupOrder)) {
        $mainOrder = array_values(array_filter($groupOrder, function ($k) use ($pillNames) { return in_array($k, $pillNames, true); }));
        $pillNames = count($mainOrder) === count($pillNames) ? $mainOrder : order_folder_children($dir, '', $pillNames);
    } else {
        $pillNames = order_folder_children($dir, $pillPrefix, $pillNames);
    }
    if (count($pillNames) >= 2) {
        $pillHere = basename($currentFolderPath);
        $pillAt = array_search($pillHere, $pillNames, true);
        $pillShown = $pillNames;
        if (count($pillNames) > 10) {
            $pillStart = max(0, min(($pillAt === false ? 0 : $pillAt) - 4, count($pillNames) - 9));
            $pillShown = array_slice($pillNames, $pillStart, 9);
            $folderPillsAll = $pillParent === '' ? gallery_root_href() : '?folder=' . rawurlencode($pillParent);
        }
        foreach ($pillShown as $pillName) {
            $pillLabel = folder_display_meta($dir, $pillPrefix . $pillName)['label'];
            $folderPills[] = ['?folder=' . rawurlencode($pillPrefix . $pillName), $pillLabel !== '' ? $pillLabel : $pillName, $pillName === $pillHere];
        }
    }
    $currentFolderMeta = folder_display_meta($dir, $currentFolderPath);
    $currentFolderName = $currentFolderMeta['label'] !== '' ? $currentFolderMeta['label'] : basename($currentFolderPath);
    $pageTitle = $currentFolderName . ' — ' . $pageTitle;
    // Same early-computation reasoning as $pageTitle above: the header's
    // photo count renders before $files gets scoped down to the folder
    // later on, so it would otherwise show the root's total count.
    $currentFolderFileCount = count($currentFolderFiles);
    // Breadcrumb: every ancestor between the root and this folder, each
    // labelled by its own admin-set name when it has one.
    $folderCrumbs = [];
    $ancestor = '';
    $segments = explode('/', $currentFolderPath);
    array_pop($segments);
    foreach ($segments as $segment) {
        $ancestor = $ancestor === '' ? $segment : $ancestor . '/' . $segment;
        $ancestorMeta = folder_display_meta($dir, $ancestor);
        $folderCrumbs[] = ['?folder=' . rawurlencode($ancestor), $ancestorMeta['label'] !== '' ? $ancestorMeta['label'] : $segment];
    }
}

// ?archive: the originals of the gallery or of this folder as one ZIP (signed-in visitors only).
if (isset($_GET['archive'])) {
    run_archive_route($dir, $currentFolderPath === null ? $files : $currentFolderFiles, $currentFolderPath, $rootPageTitle, $currentFolderName);
    exit;
}
// ...and the button for it, with what it would send, for a signed-in visitor.
$archiveCount = 0; $archiveBytes = 0;
if (is_revealed()) {
    foreach (archive_entries($dir, $currentFolderPath === null ? $files : $currentFolderFiles) as $archiveEntry) { $archiveCount++; $archiveBytes += $archiveEntry['size']; }
}

/**
 * Minimal Markdown -> HTML converter covering the formatting people
 * actually use in a Discord message: headers, bold, italic, underline,
 * strikethrough, inline code, code blocks, links, blockquotes, and
 * bulleted/numbered lists. Everything is HTML-escaped first, so raw
 * HTML in the .md file is never executed.
 */
function inline_markdown($text) {
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $text = preg_replace('/\*\*\*(.+?)\*\*\*/s', '<strong><em>$1</em></strong>', $text);
    $text = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text);
    $text = preg_replace('/__(.+?)__/s', '<u>$1</u>', $text);
    $text = preg_replace('/\*(.+?)\*/s', '<em>$1</em>', $text);
    $text = preg_replace('/(?<![a-zA-Z0-9])_(.+?)_(?![a-zA-Z0-9])/s', '<em>$1</em>', $text);
    $text = preg_replace('/~~(.+?)~~/s', '<del>$1</del>', $text);
    $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);
    $text = preg_replace('/\[([^\]]+)\]\((https?:\/\/[^\)]+)\)/', '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>', $text);
    return $text;
}

/** Strips Markdown syntax and {directives} down to plain text, for the OG description. */
function markdown_to_plaintext($text, $maxLen = 160) {
    // Code content is stripped/unwrapped before the {directive} pass below,
    // so a stray { or } inside a code block or inline code span can never
    // pair up with an unrelated brace outside it and eat text between them.
    $text = preg_replace('/```.*?```/s', ' ', $text);
    $text = preg_replace('/`([^`]+)`/', '$1', $text);
    $text = preg_replace('/\{[^}]*\}/', '', $text);
    $text = preg_replace('/\[([^\]]+)\]\([^\)]+\)/', '$1', $text);
    $text = preg_replace('/[*_~#>]/', '', $text);
    $text = preg_replace('/^\(\d+\)\s*/m', '', $text);
    $text = preg_replace('/\s+/', ' ', $text);
    $text = trim($text);
    if (function_exists('mb_strlen') && mb_strlen($text) > $maxLen) {
        $text = mb_substr($text, 0, $maxLen - 1) . '…';
    } elseif (strlen($text) > $maxLen) {
        $text = substr($text, 0, $maxLen - 1) . '…';
    }
    return unprotect_inline_code($text);
}

/**
 * Small clickable thumbnail strip + jump link + live-updatable view total
 * for a photo group, shown right under the paragraph that mentions it.
 * $status is '', 'sold', or 'reserved' — grayscales the thumbnails
 * accordingly (the SOLD/RESERVED tag itself is only shown once, above
 * the paragraph text — see markdown_to_html — not repeated here too).
 * $permalinkHtml, if given, is appended to the meta row (used to avoid
 * showing the same permalink icon twice when a paragraph matches more
 * than one group).
 */
function render_group_thumbs($key, $groupFiles, $allFiles, $photoViews, $status, $permalinkHtml = '') {
    // A folder shown as a cover card on this very page needs no strip of thumbnails under the paragraph that
    // mentions it: the card is right there (and carries the paragraph's sold/reserved/colour).
    if (isset($GLOBALS['folderMentionLinks'][$key])) return '';
    $slug = slugify($key);
    $count = count($groupFiles);
    $groupTotal = 0;
    foreach ($groupFiles as $gf) {
        $groupTotal += isset($photoViews[$gf]) ? (int) $photoViews[$gf] : 0;
    }
    $viewsHidden = (view_counts_shown() && ($groupTotal > 0 || (TRACK_VIEWS && is_admin_browser()))) ? '' : ' hidden';

    $html = '<div class="linked-photos' . ($status ? ' ' . $status : '') . '">';
    $html .= '<div class="linked-photos-thumbs">';
    foreach ($groupFiles as $gf) {
        $idx = array_search($gf, $allFiles, true);
        if ($idx === false) continue;
        $vt = video_tile_parts($gf, $GLOBALS['videoDurations'][$gf] ?? null);
        $img = '<img src="' . htmlspecialchars(img_url($gf, 'thumb'), ENT_QUOTES, 'UTF-8')
             . '" loading="lazy" ' . ($vt['inner'] === '' ? 'onclick="openLightbox(' . (int)$idx . ')" ' : '') . 'alt="'
             . htmlspecialchars($gf, ENT_QUOTES, 'UTF-8') . '">';
        $html .= $vt['inner'] === '' ? $img : '<span class="vthumb vtile"' . $vt['attrs'] . ' onclick="openLightbox(' . (int)$idx . ')">' . $img . $vt['inner'] . '</span>';
    }
    $html .= '</div>';
    $html .= '<div class="linked-photos-meta">';
    // A folder-group in 'overview' mode has no inline grid to scroll to:
    // its jump link opens the folder's own page instead.
    $folderHref = $GLOBALS['folderMentionLinks'][$key] ?? null;
    $html .= '<a class="linked-photos-jump" href="' . ($folderHref !== null ? htmlspecialchars($folderHref, ENT_QUOTES, 'UTF-8') : '#group-' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8')) . '">View '
            . htmlspecialchars(media_count_label($groupFiles), ENT_QUOTES, 'UTF-8') . ($folderHref !== null ? ' &rarr;' : ' &darr;') . '</a>';
    $html .= '<span class="group-views' . $viewsHidden . '" data-group="' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') . '">&middot; '
            . number_format($groupTotal) . ' view' . ($groupTotal === 1 ? '' : 's') . '</span>';
    $html .= $permalinkHtml;
    $html .= '</div></div>';
    return $html;
}

/**
 * A bare thumbnail strip for a {photos:}-only paragraph (no {group:}) —
 * just the clickable thumbnails, with no "View N photos" jump link and
 * no aggregate view count, since these files were never boxed into a
 * real group in the first place (each keeps its own individual view
 * badge on its standalone card instead).
 */
function render_photo_thumbs_plain($clusterFiles, $allFiles, $permalinkHtml = '') {
    $html = '<div class="linked-photos plain"><div class="linked-photos-thumbs">';
    foreach ($clusterFiles as $gf) {
        $idx = array_search($gf, $allFiles, true);
        if ($idx === false) continue;
        $vt = video_tile_parts($gf, $GLOBALS['videoDurations'][$gf] ?? null);
        $img = '<img src="' . htmlspecialchars(img_url($gf, 'thumb'), ENT_QUOTES, 'UTF-8')
             . '" loading="lazy" ' . ($vt['inner'] === '' ? 'onclick="openLightbox(' . (int)$idx . ')" ' : '') . 'alt="'
             . htmlspecialchars($gf, ENT_QUOTES, 'UTF-8') . '">';
        $html .= $vt['inner'] === '' ? $img : '<span class="vthumb vtile"' . $vt['attrs'] . ' onclick="openLightbox(' . (int)$idx . ')">' . $img . $vt['inner'] . '</span>';
    }
    $html .= '</div>';
    if ($permalinkHtml !== '') {
        $html .= '<div class="linked-photos-meta">' . $permalinkHtml . '</div>';
    }
    $html .= '</div>';
    return $html;
}

/**
 * Small 🔗 button that copies a link to #<anchorId> to the clipboard —
 * shared by a .md item's own anchor and a gallery group's anchor, since
 * both are just "copy a link to this hash" in the same way.
 */
function render_permalink_button($anchorId) {
    return '<a class="permalink-copy" href="#' . htmlspecialchars($anchorId, ENT_QUOTES, 'UTF-8')
         . '" title="Copy link">' . ui_icon('link') . '</a>';
}

/** Whether a rendered notes file has nothing to show: no text and no
 *  photos. That is what a file holding only a "# Title" (lifted out as the
 *  page title) or only directives renders to, and an empty box captioned
 *  with a filename tells a visitor nothing. The file still takes effect
 *  (its title, its directives) — only the empty box is left out. */
function note_box_is_empty($html) {
    if (preg_match('/<(img|video|iframe|hr)\b/i', $html)) return false;
    return trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8')) === '';
}

/** Setup notices are for the owner. Once an admin password exists they are shown only to the owner signed in; before
 *  that (a fresh install, where there is nobody to sign in as) they are shown to whoever opens the page, since that is
 *  the person setting it up. */
function notices_visible() {
    return ADMIN_PASSWORD === '' || is_admin_browser();
}

/**
 * A collapsed-by-default banner surfacing $galleryNotices directly on
 * the page, not just in the HTML-comment fallback in the page source —
 * "view page source" isn't something most people would think to try.
 * Addressed explicitly to the gallery owner, so a visitor who clicks it
 * out of curiosity can see right away it isn't meant for them.
 */
function render_gallery_notices_banner($notices, $open = false) {
    if (empty($notices) || !notices_visible()) return '';
    $count = count($notices);
    $html = '<details class="gallery-notices"' . ($open ? ' open' : '') . '>';
    $html .= '<summary>' . ui_icon('warn') . ' ' . $count . ' setup notice' . ($count === 1 ? '' : 's') . ' for the gallery owner</summary>';
    $html .= '<ul>';
    foreach ($notices as $notice) {
        $html .= '<li>' . htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') . '</li>';
    }
    $html .= '</ul></details>';
    return $html;
}

/**
 * Validates a {color: value} directive's value before it's ever used in
 * an inline style attribute — either a hex code (#abc, #aabbcc, #aabbccdd)
 * or a plain alphabetic CSS color word (e.g. "gold", "steelblue").
 */
function is_safe_color($value) {
    return (bool) preg_match('/^#[0-9a-f]{3,8}$/i', $value) || (bool) preg_match('/^[a-z]{2,20}$/i', $value);
}

/**
 * Masks every known {directive} in text with same-length blank spans,
 * preserving every other character's original position — unlike
 * actually removing them, this keeps byte offsets valid for
 * PREG_OFFSET_CAPTURE against the original string. Used before
 * mention-matching a group's name against raw .md content: without
 * this, a filename inside another paragraph's {photos: a.jpg, b.jpg}
 * list could falsely match as if it were mentioned in prose (a real
 * risk for short keys especially, but not limited to them). Also used
 * to detect whether a paragraph has any prose left after directives.
 */
function mask_directives($text) {
    return preg_replace_callback(
        '/\{\s*(?:color\s*:\s*[^}]+|sold|reserved|held|group\s*:\s*[^}]+|photos\s*:\s*[^}]+)\}/i',
        function ($m) { return str_repeat(' ', strlen($m[0])); },
        $text
    );
}

/**
 * Makes `inline code` inert to the directive parser. A notes file that
 * *documents* directive syntax (like the shipped example, or anyone
 * writing "use `{photos: a.jpg}` to ...") must not have that example
 * parsed as a real directive — it would raise "unknown file" notices and
 * could attach photos. Braces inside a same-line backtick span are
 * swapped for two control bytes, byte for byte, so every character offset
 * the ordering logic relies on is unchanged; unprotect_inline_code()
 * turns them back wherever text is shown. Applied once, where the notes
 * file is read. (Fenced ``` blocks are already skipped by the parser.)
 */
function protect_inline_code($text) {
    return preg_replace_callback('/`[^`\n]*`/', function ($m) {
        return strtr($m[0], ['{' => "\x01", '}' => "\x02"]);
    }, (string) $text);
}
function unprotect_inline_code($text) {
    return strtr((string) $text, ["\x01" => '{', "\x02" => '}']);
}

/**
 * True if this .md content's first non-blank line is exactly the
 * required "{gallery}" marker (case-insensitive). A file without it is
 * left completely alone — not parsed, not rendered — which is what lets
 * this script sit in a folder that already has its own unrelated .md
 * files (notes, a README, etc.) without swallowing them.
 */
function has_gallery_marker($content) {
    foreach (explode("\n", str_replace("\r\n", "\n", $content)) as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') continue;
        return strcasecmp($trimmed, '{gallery}') === 0;
    }
    return false;
}

/** Removes a recognized leading "{gallery}" marker line, and nothing else. */
function strip_gallery_marker($content) {
    $lines = explode("\n", str_replace("\r\n", "\n", $content));
    foreach ($lines as $i => $line) {
        if (trim($line) === '') continue;
        unset($lines[$i]);
        break;
    }
    return implode("\n", $lines);
}

/** Every .md file sitting directly in a real folder, natural-sorted —
 *  every one, marker or not; the caller decides what counts as "active."
 *  Reused for the gallery root's own notes scan, a subfolder's own notes
 *  (see AUTO_GROUP_BY_FOLDER), and the admin tool's "every .md in this
 *  folder" listing, which deliberately includes non-marker ones too. */
function scan_md_candidates($realFolderPath) {
    $entries = @scandir($realFolderPath);
    if ($entries === false) return [];
    $candidates = array_filter($entries, function ($file) use ($realFolderPath) {
        if (!is_file("$realFolderPath/$file")) return false;
        return strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'md';
    });
    usort($candidates, function ($a, $b) {
        return strnatcasecmp($a, $b);
    });
    return array_values($candidates);
}

/** Qualifying (marker-having) .md files in a real folder, with the
 *  marker already stripped from their content — same rule as the
 *  gallery root's own notes: only a .md whose first non-blank line is
 *  exactly "{gallery}" counts. Used for the root's own notes, and for a
 *  subfolder's own ?folder= page (see AUTO_GROUP_BY_FOLDER) — a Paris
 *  .md can't claim a StTropez photo simply because it's only ever
 *  evaluated against Paris's own already-scoped $groups/$files. */
function scan_gallery_md_files($realFolderPath) {
    $candidates = scan_md_candidates($realFolderPath);
    $files = [];
    $contents = [];
    $rejectedCount = 0;
    foreach ($candidates as $candidate) {
        $raw = file_get_contents("$realFolderPath/$candidate");
        if (has_gallery_marker($raw)) {
            $files[] = $candidate;
            $contents[$candidate] = protect_inline_code(strip_gallery_marker($raw));
        } else {
            $rejectedCount++;
        }
    }
    return ['files' => $files, 'contents' => $contents, 'rejectedCount' => $rejectedCount];
}

/**
 * Reads the {photos:}/{group:} directives of one level's notes: the gallery's
 * top-level notes, or the notes inside one folder. Both go through here, so a
 * directive means the same thing wherever it is written. A notes file can claim
 * the photos that sit directly in its own folder ($candidates, full paths); a name
 * is read relative to that folder ($prefix is '' at the top, 'Paris/' in Paris).
 * Problems are added to $notices. Returns ['claims' => path => true,
 * 'groups' => slug => [...], 'clusters' => [...]].
 */
function collect_explicit_directives($mdContents, $candidates, $prefix, $allFiles, &$notices) {
    $claims = [];
    $groups = [];
    $clusters = [];
    $offset = 0;
    foreach ($mdContents as $content) {
        foreach (extract_photo_directives($content) as $rec) {
            $validPhotos = [];
            foreach ($rec['photos'] as $typed) {
                $bare = basename($typed);
                $full = $prefix . $bare;
                if (!in_array($full, $candidates, true)) {
                    // A photo that lives in a subfolder is not "unknown": say where the directive can and can't reach.
                    $inFolder = false;
                    foreach ($allFiles as $f) {
                        if (strncmp($f, $prefix, strlen($prefix)) === 0 && strpos(substr($f, strlen($prefix)), '/') !== false && substr($f, -strlen('/' . $bare)) === '/' . $bare) { $inFolder = true; break; }
                    }
                    $notices[] = $inFolder
                        ? '"' . $typed . '" is inside a folder, and {photos:} only reaches photos directly in the same folder as the notes. To show a folder under a paragraph, write the folder\'s name in it.'
                        : 'Unknown file "' . $bare . '" referenced in a {photos:} directive — ignored.';
                    continue;
                }
                if (isset($claims[$full])) {
                    $notices[] = '"' . $bare . '" is referenced in more than one {photos:} directive — only the first applies.';
                    continue;
                }
                $validPhotos[] = $full;
            }
            // A directive-only paragraph only sees prose in its own paragraph (see GROUPING/order_within_zone), never a
            // description written just before it — a blank line between them is enough to disconnect them, which is easy
            // to write by accident. Flag it: it's not invalid, just probably not what was intended.
            if (!$rec['hasProse'] && $rec['precededByPlainProse']) {
                $notices[] = 'A {group:}/{photos:} paragraph right after a plain description isn\'t attached to it (a blank line separates them into different paragraphs) — merge them into one paragraph if that was intended.';
            }
            if (empty($validPhotos)) continue;
            $position = $offset + $rec['position'];
            if ($rec['groupName'] !== null && $rec['groupName'] !== '') {
                $slug = slugify($rec['groupName']);
                if ($slug === '') continue;
                if (isset($groups[$slug])) {
                    $notices[] = '{group: ' . $rec['groupName'] . '} is defined more than once — only the first definition applies.';
                    continue;
                }
                foreach ($validPhotos as $p) { $claims[$p] = true; }
                $groups[$slug] = ['name' => $rec['groupName'], 'files' => $validPhotos, 'position' => $position, 'hasProse' => $rec['hasProse']];
            } else {
                foreach ($validPhotos as $p) { $claims[$p] = true; }
                $clusters[] = ['files' => $validPhotos, 'position' => $position, 'hasProse' => $rec['hasProse']];
            }
        }
        $offset += strlen($content) + 1;
    }
    return ['claims' => $claims, 'groups' => $groups, 'clusters' => $clusters];
}

/**
 * Scans one .md file's raw content for {photos: a.jpg, b.jpg, ...}
 * directives — each optionally paired with a {group: Name} directive in
 * the same paragraph — respecting the same paragraph/code-fence rules
 * markdown_to_html uses (a blank line, or a line starting "(1)", starts
 * a new paragraph; header/list/blockquote lines are never paragraph
 * text). A bare {group:} with no {photos:} in the same paragraph isn't
 * returned here — it only ever renames an already-existing group at
 * render time (see markdown_to_html), so it doesn't affect membership.
 *
 * Returns an ordered list of ['position' => <char offset within
 * $content>, 'groupName' => string|null, 'photos' => string[]].
 */
function extract_photo_directives($content) {
    $content = str_replace("\r\n", "\n", $content);
    $lines = explode("\n", $content);
    $records = [];
    $paraLines = [];
    $paraStartPos = null;
    $pos = 0;
    $inCode = false;

    // Tracks whether the most recently flushed paragraph was plain prose
    // with no directive of its own — used to flag a likely-accidental
    // disconnect: a description followed by a blank line, then a
    // directive-only paragraph the author probably meant to attach to
    // it. Anything that isn't "a real paragraph with prose and nothing
    // else" (a header, list, blockquote, code fence, or a directive-
    // bearing paragraph already claimed by its own directive) resets
    // this to false, since something else now sits in between.
    $lastParaWasPlainProse = false;

    $flush = function () use (&$paraLines, &$paraStartPos, &$records, &$lastParaWasPlainProse) {
        if (empty($paraLines)) return;
        $photosRaw = null;
        $groupName = null;
        $hasProse = false;
        $hasAnyDirective = false;
        foreach ($paraLines as $l) {
            if ($photosRaw === null && preg_match('/\{\s*photos\s*:\s*([^}]+)\}/i', $l, $pm)) {
                $photosRaw = $pm[1];
            }
            if ($groupName === null && preg_match('/\{\s*group\s*:\s*([^}]+)\}/i', $l, $gm)) {
                $groupName = trim($gm[1]);
            }
            if (preg_match('/\{\s*(?:color\s*:\s*[^}]+|sold|reserved|held|group\s*:\s*[^}]+|photos\s*:\s*[^}]+)\}/i', $l)) {
                $hasAnyDirective = true;
            }
            // Anything left after masking every known directive is
            // prose — this is what decides whether the paragraph
            // gets an early ("mirrors the .md") position or falls
            // back to natural order (see compute_zone_order()).
            $stripped = trim(mask_directives($l));
            if ($stripped !== '') $hasProse = true;
        }
        if ($photosRaw !== null) {
            $photos = array_values(array_filter(array_map('trim', explode(',', $photosRaw))));
            if (!empty($photos)) {
                $records[] = [
                    'position' => $paraStartPos, 'groupName' => $groupName, 'photos' => $photos,
                    'hasProse' => $hasProse, 'precededByPlainProse' => $lastParaWasPlainProse,
                ];
            }
        }
        // A paragraph only "donates" adjacency to whatever comes next if
        // it's prose with no directive of its own — one already claimed
        // by its own directive shouldn't also be offered to a different,
        // later one.
        $lastParaWasPlainProse = $hasProse && !$hasAnyDirective;
        $paraLines = [];
        $paraStartPos = null;
    };

    foreach ($lines as $line) {
        $trimmed = trim($line);
        $lineLen = strlen($line) + 1; // +1 for the \n explode() removed

        if (strpos($trimmed, '```') === 0) {
            $flush();
            $lastParaWasPlainProse = false;
            $inCode = !$inCode;
            $pos += $lineLen;
            continue;
        }
        if ($inCode) {
            $pos += $lineLen;
            continue;
        }
        if ($trimmed === '') {
            $flush();
            $pos += $lineLen;
            continue;
        }
        if (preg_match('/^(#{1,3})\s+/', $trimmed) || preg_match('/^>\s?/', $trimmed)
            || preg_match('/^[-*]\s+/', $trimmed) || preg_match('/^\d+\.\s+/', $trimmed)) {
            $flush();
            $lastParaWasPlainProse = false;
            $pos += $lineLen;
            continue;
        }
        if (preg_match('/^\(\d+\)\s+/', $trimmed)) {
            $flush();
        }
        if ($paraStartPos === null) {
            $paraStartPos = $pos;
        }
        $paraLines[] = $trimmed;
        $pos += $lineLen;
    }
    $flush();
    return $records;
}

/**
 * Converts one .md file's content to HTML. Also, as a side effect:
 *  - attaches a thumbnail strip + view count after any paragraph that
 *    mentions a photo group's name (auto-link),
 *  - records a per-group color into $groupColors (by reference) when a
 *    {color: value} directive appears in that same paragraph, and
 *  - records a per-group status ('sold' or 'reserved') into $groupStatus
 *    (by reference) when a {sold} or {reserved}/{held} directive appears
 *    in that same paragraph.
 */
function markdown_to_html($text, $groups, $files, &$groupColors, &$groupStatus, &$groupLabels, &$renderClaims, $photoViews, $prefix = '') {
    $text = str_replace("\r\n", "\n", $text);
    $lines = explode("\n", $text);
    $html = '';
    $listType = null;
    $inCode = false;
    $codeBuf = '';
    $paraLines = [];

    $flushPara = function () use (&$html, &$paraLines, $groups, $files, &$groupColors, &$groupStatus, &$groupLabels, &$renderClaims, $photoViews, $prefix) {
        if (empty($paraLines)) return;

        // Pull out optional {color: value} / {sold} / {reserved} (or
        // {held}) / {group: Name} / {photos: a.jpg, b.jpg} directives,
        // and strip them (and any resulting empty lines) from what
        // actually shows. If both {sold} and {reserved}/{held} appear,
        // sold wins — it's the more final state.
        $colorValue = null;
        $status = '';
        $explicitGroupName = null;
        $explicitPhotosRaw = null;
        $align = null;   // {left} / {center} / {right}: how this paragraph's text is aligned
        $cleanLines = [];
        foreach ($paraLines as $l) {
            if ($colorValue === null && preg_match('/\{\s*color\s*:\s*([^}]+)\}/i', $l, $cm)) {
                $candidate = trim($cm[1]);
                if (is_safe_color($candidate)) {
                    $colorValue = $candidate;
                }
            }
            if (preg_match('/\{\s*sold\s*\}/i', $l)) {
                $status = 'sold';
            } elseif ($status !== 'sold' && preg_match('/\{\s*(reserved|held)\s*\}/i', $l)) {
                $status = 'reserved';
            }
            if ($explicitGroupName === null && preg_match('/\{\s*group\s*:\s*([^}]+)\}/i', $l, $ggm)) {
                $explicitGroupName = trim($ggm[1]);
            }
            if ($explicitPhotosRaw === null && preg_match('/\{\s*photos\s*:\s*([^}]+)\}/i', $l, $ppm)) {
                $explicitPhotosRaw = $ppm[1];
            }
            if ($align === null && preg_match('/\{\s*(left|center|centre|right)\s*\}/i', $l, $am)) {
                $align = strtolower($am[1]) === 'centre' ? 'center' : strtolower($am[1]);
            }
            $l = trim(preg_replace(
                ['/\{\s*color\s*:\s*[^}]+\}/i', '/\{\s*sold\s*\}/i', '/\{\s*(reserved|held)\s*\}/i',
                 '/\{\s*group\s*:\s*[^}]+\}/i', '/\{\s*photos\s*:\s*[^}]+\}/i', '/\{\s*(left|center|centre|right)\s*\}/i'],
                '', $l
            ));
            if ($l !== '') $cleanLines[] = $l;
        }

        // Register this paragraph's {photos:} claims in render order —
        // before the empty-paragraph check below, so a directive-only
        // paragraph (no other text) still consumes its claim. Otherwise
        // a *later* paragraph re-referencing the same file would never
        // see it as already taken, and would win the claim instead of
        // correctly losing it (extraction, further up, already decided
        // this paragraph's claim wins — rendering has to agree).
        $explicitPhotos = [];
        if ($explicitPhotosRaw !== null) {
            foreach (explode(',', $explicitPhotosRaw) as $p) {
                // Names are read relative to the folder the notes are in ($prefix), like extraction does.
                $p = basename(trim($p));
                $p = $p === '' ? '' : $prefix . $p;
                if ($p !== '' && in_array($p, $files, true) && !isset($renderClaims[$p])) {
                    $explicitPhotos[] = $p;
                    $renderClaims[$p] = true;
                }
            }
        }

        // An explicit {group:}+{photos:} pair identifies a real, already-
        // built group (see GROUPING above) regardless of whether this
        // paragraph has any other text — so its color/status/label side
        // effects need to apply here, before the empty-paragraph check
        // below, or a textless {group:}/{photos:} paragraph would return
        // early and never actually apply them.
        $matchedKeys = [];
        if ($explicitGroupName !== null && !empty($explicitPhotos)) {
            $slug = slugify($explicitGroupName);
            if ($slug !== '' && isset($groups[$slug])) {
                $matchedKeys[] = $slug;
                if ($colorValue !== null) $groupColors[$slug] = $colorValue;
                if ($status) $groupStatus[$slug] = $status;
                $groupLabels[$slug] = $explicitGroupName;
            }
        }

        // A paragraph left empty after stripping directives renders
        // nothing in the note box — including for {group:}/{photos:}
        // with no other text. That's fine: their structural effect (the
        // box existing, membership, positional reordering, and the
        // color/status/label side effects above) already happened; this
        // check only decides whether there's a note-box entry to show,
        // and with no text, there's genuinely nothing to show.
        if (empty($cleanLines)) {
            $paraLines = [];
            return;
        }

        $rawText = implode(' ', $cleanLines);

        // With neither an explicit group match nor bare {photos:}, fall
        // back to the existing behavior: match any group whose name is
        // literally mentioned in the text.
        if (empty($matchedKeys) && empty($explicitPhotos)) {
            foreach ($groups as $key => $groupFiles) {
                if (preg_match(group_mention_pattern($key), $rawText)) {
                    $matchedKeys[] = $key;
                }
            }
        }

        $itemId = null;
        if (!empty($matchedKeys)) {
            $itemId = 'item-' . slugify($matchedKeys[0]);
        } elseif (!empty($explicitPhotos)) {
            // No box, no name — the closest thing to a stable identity
            // here is the first listed file (full filename, extension
            // included, to stay unique — see group_key()), so that's
            // what the permalink anchors on.
            $itemId = 'item-' . slugify(substr($explicitPhotos[0], strlen($prefix)));
        }
        $idAttr = $itemId ? (' id="' . htmlspecialchars($itemId, ENT_QUOTES, 'UTF-8') . '"') : '';

        $inner = implode('<br>', array_map('inline_markdown', $cleanLines));
        $alignCss = $align !== null ? 'text-align: ' . $align . ';' : '';
        $alignAttr = $alignCss !== '' ? ' style="' . $alignCss . '"' : '';
        if ($status === 'sold') {
            $html .= '<div class="sold-item"' . $idAttr . $alignAttr . '><span class="sold-badge">SOLD</span><p>' . $inner . '</p></div>';
        } elseif ($status === 'reserved') {
            $html .= '<div class="reserved-item"' . $idAttr . $alignAttr . '><span class="reserved-badge">RESERVED</span><p>' . $inner . '</p></div>';
        } else {
            // A {color:} always accents its own paragraph with a bar, so the text and the photos or folder it colours
            // read as one item (and with nothing to put a box round it still does something visible).
            $accent = ($colorValue !== null)
                ? ' class="accent-note" style="--bar: ' . htmlspecialchars($colorValue, ENT_QUOTES, 'UTF-8') . ';' . $alignCss . '"'
                : $alignAttr;
            $html .= '<p' . $idAttr . $accent . '>' . $inner . '</p>';
        }

        if (!empty($matchedKeys)) {
            foreach ($matchedKeys as $i => $key) {
                $groupFiles = $groups[$key];
                $linkHtml = ($i === 0) ? render_permalink_button($itemId) : '';
                $html .= render_group_thumbs($key, $groupFiles, $files, $photoViews, $status, $linkHtml);
                if ($colorValue !== null) {
                    $groupColors[$key] = $colorValue;
                }
                if ($status) {
                    $groupStatus[$key] = $status;
                }
                if ($explicitGroupName !== null) {
                    $groupLabels[$key] = $explicitGroupName;
                }
            }
        } elseif (!empty($explicitPhotos)) {
            $html .= render_photo_thumbs_plain($explicitPhotos, $files, render_permalink_button($itemId));
            foreach ($explicitPhotos as $pf) {
                // Must match the key these standalone singles were
                // stored under in $groups (see GROUPING above): the
                // file's own full filename, not its extension-stripped
                // base.
                $pfKey = substr($pf, strlen($prefix));
                if ($colorValue !== null) $groupColors[$pfKey] = $colorValue;
                if ($status) $groupStatus[$pfKey] = $status;
            }
        }
        $paraLines = [];
    };
    $closeList = function () use (&$html, &$listType) {
        if ($listType) { $html .= "</$listType>"; $listType = null; }
    };

    foreach ($lines as $line) {
        $trimmed = trim($line);

        if (strpos($trimmed, '```') === 0) {
            $flushPara();
            if ($inCode) {
                $html .= '<pre><code>' . htmlspecialchars(rtrim($codeBuf, "\n"), ENT_QUOTES, 'UTF-8') . '</code></pre>';
                $codeBuf = '';
                $inCode = false;
            } else {
                $closeList();
                $inCode = true;
            }
            continue;
        }
        if ($inCode) {
            $codeBuf .= $line . "\n";
            continue;
        }

        if ($trimmed === '') {
            $flushPara();
            $closeList();
            continue;
        }

        if (preg_match('/^(#{1,3})\s+(.+)$/', $trimmed, $m)) {
            $flushPara();
            $closeList();
            $level = strlen($m[1]);
            $headingText = $m[2];
            $headingAlign = '';
            if (preg_match('/\{\s*(left|center|centre|right)\s*\}/i', $headingText, $hm)) {
                $headingAlign = ' style="text-align: ' . (strtolower($hm[1]) === 'centre' ? 'center' : strtolower($hm[1])) . ';"';
                $headingText = trim(preg_replace('/\{\s*(left|center|centre|right)\s*\}/i', '', $headingText));
            }
            $html .= "<h{$level}{$headingAlign}>" . inline_markdown($headingText) . "</h{$level}>";
            continue;
        }

        if (preg_match('/^>\s?(.*)$/', $trimmed, $m)) {
            $flushPara();
            $closeList();
            $html .= '<blockquote>' . inline_markdown($m[1]) . '</blockquote>';
            continue;
        }

        if (preg_match('/^[-*]\s+(.+)$/', $trimmed, $m)) {
            $flushPara();
            if ($listType !== 'ul') {
                $closeList();
                $html .= '<ul>';
                $listType = 'ul';
            }
            $html .= '<li>' . inline_markdown($m[1]) . '</li>';
            continue;
        }

        if (preg_match('/^\d+\.\s+(.+)$/', $trimmed, $m)) {
            $flushPara();
            if ($listType !== 'ol') {
                $closeList();
                $html .= '<ol>';
                $listType = 'ol';
            }
            $html .= '<li>' . inline_markdown($m[1]) . '</li>';
            continue;
        }

        // A line like "(1) Item description..." always starts a new
        // paragraph, even with no blank line before it — this is what
        // lets each numbered item in a listing get its own auto-linked
        // thumbnails / color / sold state, without requiring blank lines.
        if (preg_match('/^\(\d+\)\s+/', $trimmed)) {
            $flushPara();
        }

        $closeList();
        $paraLines[] = $trimmed;
    }
    $flushPara();
    $closeList();
    if ($inCode && $codeBuf !== '') {
        $html .= '<pre><code>' . htmlspecialchars(rtrim($codeBuf, "\n"), ENT_QUOTES, 'UTF-8') . '</code></pre>';
    }
    return unprotect_inline_code($html);
}

/** The look of a video's picture wherever it is shown (see video_tile_parts); used by the gallery and the admin. */
function video_tile_css() {
    return <<<'CSS'
  .vtile { position: relative; }
  /* A video's own first frame, over the placeholder, only once the browser has it. */
  .vtile .tile-video { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; opacity: 0; pointer-events: none; transition: opacity 0.2s ease, transform 0.25s ease; }
  .vtile .tile-video.ready { opacity: 1; }
  .vtile .tile-preview { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; opacity: 0; pointer-events: none; transition: opacity 0.2s ease; }
  .vtile .tile-preview.ready { opacity: 1; }
  /* Dragging a finger sideways across a video tile scrubs through it; up and down still scrolls the page. */
  .vtile[data-strip] { touch-action: pan-y; }
  .vtile .tile-strip { position: absolute; inset: 0; background-repeat: no-repeat; pointer-events: none; }
  /* A video's picture carries a big play mark in the middle so it is told apart at a glance; the clip and the scrub frames
     are laid over the tile after it, so they cover it while they show. */
  .vtile .video-mark {
    position: absolute; left: 50%; top: 50%;
    width: clamp(48px, 30%, 92px); aspect-ratio: 1;
    transform: translate(-50%, -50%);
    display: flex; align-items: center; justify-content: center;
    border-radius: 50%;
    background: rgba(0,0,0,0.55); border: 2px solid rgba(255,255,255,0.85); color: #fff;
    box-shadow: 0 2px 12px rgba(0,0,0,0.45);
    pointer-events: none;
  }
  .vtile .video-mark .ico { width: 46%; height: 46%; margin-left: 6%; fill: currentColor; stroke: currentColor; stroke-linejoin: round; }
  .linked-photos-thumbs .vthumb { display: block; width: 56px; height: 56px; border-radius: 6px; overflow: hidden; cursor: pointer; transition: transform 0.15s ease; }
  .linked-photos-thumbs .vthumb img { width: 100%; height: 100%; }
  .linked-photos-thumbs .vthumb:hover { transform: scale(1.08); }
  .linked-photos-thumbs .vthumb .video-mark { width: 26px; border-width: 1.5px; box-shadow: none; }
CSS;
}

/** The script behind video tiles' hover clips, finger and mouse scrubbing, and first-frame pictures; shared by the gallery
 *  and the admin (see video_tile_parts for the markup it finds). */
function video_tile_script() {
    return <<<'JS'
(function () {
  const viewerOpen = function () { const l = document.getElementById('lightbox'); return !!(l && l.classList.contains('open')); };
  // Video tile clips. A made clip (a few seconds, silent, looping) plays over the tile: on a desktop when the pointer rests on
  // it, on a touch screen for the tile nearest the middle of the screen once the page has stopped moving. Only one plays at a
  // time, and starting another (or scrolling, leaving, switching tab, opening the viewer) stops the previous completely,
  // download included. Where the server has no clip, a desktop hover plays the video itself where the browser can. Nothing
  // plays by itself for someone who asked their device for reduced motion or for less data.
  const hoverish = !!(window.matchMedia && window.matchMedia('(hover: hover)').matches);
  const calmDevice = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) || !!(navigator.connection && navigator.connection.saveData);
  let active = null, pending = null;
  const halt = function () {
    if (pending) { clearTimeout(pending); pending = null; }
    if (active) { const a = active; active = null; a.stop(); }
  };
  window.__previewHalt = halt;
  const makeClip = function (card) {
    const el = document.createElement('video');
    el.className = 'tile-preview'; el.muted = true; el.loop = true; el.playsInline = true; el.preload = 'auto'; el.setAttribute('aria-hidden', 'true'); el.tabIndex = -1;
    const handle = {
      card: card, el: el,
      stop: function () { el.pause(); el.removeAttribute('src'); try { el.load(); } catch (e) {} el.remove(); }
    };
    el.addEventListener('error', function () { if (active === handle) active = null; handle.stop(); });
    el.addEventListener('playing', function () { el.classList.add('ready'); });
    el.src = card.dataset.preview;
    card.appendChild(el);
    const p = el.play(); if (p && p.catch) p.catch(function () { if (active === handle) active = null; handle.stop(); });
    return handle;
  };
  if (hoverish) {
    const attach = function (card, start) {
      card.addEventListener('mouseenter', function () {
        halt();
        pending = setTimeout(function () { pending = null; active = start(card); }, 250);
      });
      card.addEventListener('mouseleave', halt);
    };
    document.querySelectorAll('.vtile[data-preview]').forEach(function (card) { attach(card, makeClip); });
    document.querySelectorAll('.vtile:not([data-preview]) .tile-video').forEach(function (v) {
      attach(v.closest('.vtile'), function () {
        if (!v.classList.contains('ready')) return null;
        v.loop = true; const p = v.play(); if (p && p.catch) p.catch(function () {});
        return { card: v.closest('.vtile'), el: v, stop: function () { v.pause(); try { v.currentTime = 0.1; } catch (e) {} } };
      });
    });
    window.addEventListener('blur', halt);
    window.addEventListener('scroll', halt, { passive: true });
    document.addEventListener('visibilitychange', halt);
  } else if (!calmDevice) {
    const cards = Array.prototype.slice.call(document.querySelectorAll('.card[data-preview]'));
    if (cards.length) {
      let timer = null;
      const choose = function () {
        timer = null;
        if (document.hidden || viewerOpen()) { halt(); return; }
        const mid = window.innerHeight / 2;
        let best = null, bestDist = Infinity;
        cards.forEach(function (card) {
          const r = card.getBoundingClientRect();
          if (Math.min(r.bottom, window.innerHeight) - Math.max(r.top, 0) < r.height * 0.65) return;   // mostly on screen
          const d = Math.abs((r.top + r.bottom) / 2 - mid);
          if (d < bestDist) { best = card; bestDist = d; }
        });
        if (best && active && active.card === best) return;
        halt();
        if (best) active = makeClip(best);
      };
      const schedule = function () { if (timer) clearTimeout(timer); timer = setTimeout(choose, 400); };
      window.addEventListener('scroll', function () { halt(); schedule(); }, { passive: true });   // stops while the page moves, picks again when it settles
      window.addEventListener('resize', schedule);
      document.addEventListener('visibilitychange', function () { halt(); schedule(); });
      window.addEventListener('pageshow', schedule);
      window.__previewResume = schedule;
      schedule();
    }
  }
  // Getting the clips ready before anyone needs them: once the page is up, the tiles in view whose clip isn't made yet are
  // asked to make it, one request at a time, a dozen at most, with nothing to download. Stops at the first refusal.
  function warmUp(attr) {
    const cards = Array.prototype.slice.call(document.querySelectorAll('.vtile[' + attr + ']:not([' + attr + '-ready])'));
    if (!cards.length || typeof IntersectionObserver === 'undefined' || typeof fetch === 'undefined') return;
    let queue = [], running = false, done = 0, dead = false;
    const next = function () {
      if (running || dead || done >= 12 || !queue.length || document.hidden) return;
      running = true;
      const card = queue.shift();
      fetch(card.getAttribute(attr) + '&warm=1', { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { if (r.status === 204) { card.setAttribute(attr + '-ready', '1'); done++; } else { dead = true; } })
        .catch(function () { dead = true; })
        .then(function () { running = false; setTimeout(next, 300); });
    };
    const io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) { if (e.isIntersecting) { io.unobserve(e.target); queue.push(e.target); } });
      setTimeout(next, 800);
    }, { rootMargin: '200px' });
    cards.forEach(function (c) { io.observe(c); });
    document.addEventListener('visibilitychange', function () { if (!document.hidden) next(); });
  }
  warmUp('data-preview');
  if ((navigator.maxTouchPoints || 0) > 0 || !hoverish) warmUp('data-strip');
  // Scrubbing a video tile with a finger: a horizontal drag across it shows the frame under the finger (frames from the
  // whole video, left edge = the start, right edge = the end), following the finger back and forth; a vertical drag is still
  // the page scrolling, a plain tap still opens the viewer, and a drag never opens it. A tile whose sprite isn't made yet
  // simply doesn't scrub (it is prepared in the background), and a playing clip gives way to the scrub.
  document.querySelectorAll('.vtile[data-strip]').forEach(function (card) {
    const cols = Number(card.getAttribute('data-strip-cols')) || 10, rows = Number(card.getAttribute('data-strip-rows')) || 1;
    let sx = 0, sy = 0, tracking = false, scrubbing = false, overlay = null, img = null;
    const show = function (x) {
      const r = card.getBoundingClientRect();
      const f = Math.min(cols * rows - 1, Math.max(0, Math.floor(Math.min(0.999, Math.max(0, (x - r.left) / r.width)) * cols * rows)));
      const col = f % cols, row = Math.floor(f / cols);
      const cell = r.height;   // each frame is shown as tall as the tile (the frames are square)
      overlay.style.backgroundSize = (cell * cols) + 'px ' + (cell * rows) + 'px';
      overlay.style.backgroundPosition = (-(col * cell) + (r.width - cell) / 2) + 'px ' + (-(row * cell)) + 'px';
    };
    card.addEventListener('touchstart', function (e) {
      if (e.touches.length !== 1) { tracking = false; return; }
      tracking = true; scrubbing = false; sx = e.touches[0].clientX; sy = e.touches[0].clientY;
      if (!img) { img = new Image(); img.src = card.getAttribute('data-strip'); }
    }, { passive: true });
    card.addEventListener('touchmove', function (e) {
      if (!tracking) return;
      const t = e.touches[0];
      if (!scrubbing) {
        const dx = Math.abs(t.clientX - sx), dy = Math.abs(t.clientY - sy);
        if (dx < 8 && dy < 8) return;
        if (dy > dx) { tracking = false; return; }
        if (!img.complete || !img.naturalWidth) return;
        if (window.__previewHalt) window.__previewHalt();
        overlay = document.createElement('div'); overlay.className = 'tile-strip';
        overlay.style.backgroundImage = 'url("' + img.src.replace(/"/g, '%22') + '")';
        card.appendChild(overlay);
        scrubbing = true;
      }
      e.preventDefault();
      show(t.clientX);
    }, { passive: false });
    const end = function () {
      tracking = false;
      if (scrubbing) { scrubbing = false; window.__scrubbedAt = Date.now(); if (window.__previewResume) window.__previewResume(); }
      if (overlay) { overlay.remove(); overlay = null; }
    };
    card.addEventListener('touchend', end);
    card.addEventListener('touchcancel', end);
    // With a mouse the same frames follow the pointer sideways across the tile. A pointer that rests starts the clip as
    // before; one that moves sideways (a few pixels, or more once a clip is playing) swaps it for the frame under it, and
    // the tile keeps scrubbing until the pointer leaves, so a pause to look at a frame doesn't start the clip. A click
    // still opens the viewer.
    if (hoverish) {
      let mx = 0, over = false, mouseScrubbing = false;
      const leave = function () {
        over = false; mouseScrubbing = false;
        if (overlay) { overlay.remove(); overlay = null; }
      };
      card.addEventListener('mouseenter', function (e) {
        over = true; mouseScrubbing = false; mx = e.clientX;
        if (!img) { img = new Image(); img.src = card.getAttribute('data-strip'); }
      });
      card.addEventListener('mousemove', function (e) {
        if (!over) {   // the pointer is on the tile (a move says so) though nothing announced it, e.g. after the window was left and returned to
          over = true; mx = e.clientX;
          if (!img) { img = new Image(); img.src = card.getAttribute('data-strip'); }
        }
        if (!mouseScrubbing) {
          if (Math.abs(e.clientX - mx) < (active && active.card === card ? 15 : 6)) return;
          if (!img || !img.complete || !img.naturalWidth) return;
          halt();
          overlay = document.createElement('div'); overlay.className = 'tile-strip';
          overlay.style.backgroundImage = 'url("' + img.src.replace(/"/g, '%22') + '")';
          card.appendChild(overlay);
          mouseScrubbing = true;
        }
        show(e.clientX);
      });
      card.addEventListener('mouseleave', leave);
      window.addEventListener('blur', leave);
      document.addEventListener('visibilitychange', leave);
    }
  });
  // A video's own first frame over its placeholder, once the browser has one; if it can't play the video, nothing changes.
  document.querySelectorAll('.tile-video').forEach(function (v) {
    const ready = function () { v.classList.add('ready'); };
    v.addEventListener('loadeddata', ready);
    v.addEventListener('error', function () { v.remove(); });
    if (v.readyState >= 2) ready();
  });
})();
JS;
}

/** What makes a video's picture a video's picture wherever it is shown (a gallery tile, a strip of group thumbnails, a
 *  folder's cover, the admin's grid): the big play mark, the browser's own first frame where the server can't take one,
 *  and the addresses of the hover clip and of the frames a pointer or finger scrubs through. 'attrs' goes on the
 *  element that holds the picture (which also gets the class "vtile"), 'inner' inside it, after the <img>. A photo gets
 *  nothing of this. */
function video_tile_parts($file, $duration = null) {
    if (!is_video_file($file)) return ['attrs' => '', 'inner' => ''];
    $version = htmlspecialchars(file_version($file), ENT_QUOTES, 'UTF-8');
    $inner = '';
    // Where the server can't take a frame from a video, the browser's own first frame is laid over the placeholder (it
    // appears only if the browser can play the video; otherwise the placeholder stays).
    if (!video_pictures_expected() || video_poster_failed($file)) {
        $inner .= '<video class="tile-video" muted playsinline preload="metadata" tabindex="-1" aria-hidden="true" src="?video=' . rawurlencode($file) . '&amp;v=' . $version . '#t=0.1"></video>';
    }
    $inner .= '<span class="video-mark" aria-hidden="true">' . ui_icon('play') . '</span>';
    $attrs = '';
    // A tile carries its clip's address unless ffmpeg couldn't make one lately; "ready" says it already exists (so nothing
    // needs preparing when the page is opened).
    if (video_previews_expected()) {
        $clipFile = video_preview_file($file);
        if ($clipFile !== null && !(is_file($clipFile . '.failed') && !is_file($clipFile))) {
            $attrs .= ' data-preview="?preview=' . rawurlencode($file) . '&amp;v=' . $version . '"' . (is_file($clipFile) ? ' data-preview-ready="1"' : '');
        }
    }
    if (ENABLE_VIDEO_PREVIEWS && $duration !== null && $duration >= 1 && video_previews_expected()) {
        $stripBase = image_cache_path(containing_folder_real_path($GLOBALS['dir'], $file) . '/.gallery-cache', $file, 'strip2', resolve_real_path($GLOBALS['dir'], $file));
        if ($stripBase !== null && !(is_file($stripBase . '.failed') && !is_file($stripBase))) {
            [$cols, $rows] = video_strip_layout($duration);
            $attrs .= ' data-strip="?strip=' . rawurlencode($file) . '&amp;v=' . $version . '" data-strip-cols="' . $cols . '" data-strip-rows="' . $rows . '"'
                . (is_file($stripBase) ? ' data-strip-ready="1"' : '');
        }
    }
    return ['attrs' => $attrs, 'inner' => $inner];
}

/** One clickable gallery card. Shows a live-updatable view-count badge
 *  (hidden until >0); a small corner tag for a standalone sold/reserved
 *  item ($status is '', 'sold', or 'reserved' — a grouped item's ribbon
 *  is drawn on its parent box instead); and, for a standalone item whose
 *  matched {color: value} directive has nowhere else to apply (single-
 *  photo "groups" never get a box), a colored inset ring instead. A
 *  video's tile is this exact same markup — its <img> just resolves to
 *  the generic placeholder graphic (see render_video_placeholder_image)
 *  — plus a plain-text duration badge in the one corner not already
 *  spoken for, when $videoDuration is known. */
function render_card($file, $index, $photoViews, $status = '', $color = null, $anchorId = null, $videoDuration = null, $itemEntry = null) {
    $views = isset($photoViews[$file]) ? (int) $photoViews[$file] : 0;
    $hidden = (view_counts_shown() && ($views > 0 || (TRACK_VIEWS && is_admin_browser()))) ? '' : ' hidden';   // the owner sees every count, a zero too
    $badge = '<span class="view-badge' . $hidden . '" data-photo="' . htmlspecialchars($file, ENT_QUOTES, 'UTF-8') . '">' . ui_icon('eye') . ' '
           . number_format($views) . '</span>';
    $statusBadge = '';
    if ($status === 'sold') {
        $statusBadge = '<span class="sold-badge-corner">SOLD</span>';
    } elseif ($status === 'reserved') {
        $statusBadge = '<span class="reserved-badge-corner">RESERVED</span>';
    }
    $durationBadge = $videoDuration !== null
        ? '<span class="video-duration">' . htmlspecialchars(format_duration($videoDuration), ENT_QUOTES, 'UTF-8') . '</span>'
        : '';
    // Caption/location/web badges — bottom edge, right-justified,
    // extending the view-badge's own corner into a flex row (see
    // .card-badges) rather than stacking into a cramped cluster.
    $itemBadges = '';
    if (!empty($itemEntry['caption'])) {
        // A hover tooltip on desktop (title); a tap target that opens a
        // small popover on a touch screen, where hover doesn't exist.
        $itemBadges .= '<span class="item-badge caption-badge" title="' . htmlspecialchars($itemEntry['caption'], ENT_QUOTES, 'UTF-8')
                     . '" role="button" tabindex="0" onclick="toggleCaptionPopover(this, event)" onkeydown="if (event.key === \'Enter\' || event.key === \' \') toggleCaptionPopover(this, event)">' . ui_icon('caption') . '</span>';
    }
    if (photo_location_visible($itemEntry ?? [])) {
        $loc = $itemEntry['location'];
        $locTitle = trim(($loc['label'] ?? '') !== '' ? $loc['label'] : ($loc['lat'] . ', ' . $loc['lng']));
        $itemBadges .= '<a class="item-badge location-badge" href="' . htmlspecialchars(osm_location_url($loc['lat'], $loc['lng']), ENT_QUOTES, 'UTF-8')
                     . '" target="_blank" rel="noopener noreferrer" title="' . htmlspecialchars($locTitle, ENT_QUOTES, 'UTF-8')
                     . '" onclick="event.stopPropagation()">' . ui_icon('pin') . '</a>';
    }
    if (!empty($itemEntry['web'])) {
        $itemBadges .= '<a class="item-badge web-badge" href="' . htmlspecialchars($itemEntry['web'], ENT_QUOTES, 'UTF-8')
                     . '" target="_blank" rel="noopener noreferrer" title="Information link: ' . htmlspecialchars($itemEntry['web'], ENT_QUOTES, 'UTF-8')
                     . '" onclick="event.stopPropagation()">' . ui_icon('globe') . '</a>';
    }
    // The ring is drawn by .ringed::after, above the picture: an inset shadow on the card itself sat underneath it, unseen.
    $colorStyle = $color ? ' style="--ring: ' . htmlspecialchars($color, ENT_QUOTES, 'UTF-8') . ';"' : '';
    // $anchorId lets a standalone single be a valid "#group-<slug>" jump
    // target too — render_group_thumbs() always links there for a
    // mentioned item, whether it turned out to be a real box or just a
    // single card; only a box's own container carried that id before,
    // leaving the link dead for a single.
    $idAttr = $anchorId ? ' id="' . htmlspecialchars($anchorId, ENT_QUOTES, 'UTF-8') . '"' : '';
    $vt = video_tile_parts($file, $videoDuration);
    // For the signed-in owner: a lock on the tile showing whether it is private, and pressing it flips that.
    $ownerLock = '';
    if (is_admin_browser()) {
        $ps = owner_privacy_state($GLOBALS['dir'], $file, $GLOBALS['itemsData'] ?? []);
        $isPriv = $ps['priv']; $isFolder = $ps['own'] === '' && $ps['inh'];
        $lockTitle = owner_lock_title($ps);
        $ownerLock = '<button type="button" class="priv-toggle' . ($isPriv ? ' is-private' : '') . ($isFolder ? ' is-folder' : '') . '" data-file="' . htmlspecialchars($file, ENT_QUOTES, 'UTF-8') . '" aria-pressed="' . ($isPriv ? 'true' : 'false')
            . '" title="' . htmlspecialchars($lockTitle, ENT_QUOTES, 'UTF-8') . '">' . ui_icon($isPriv ? 'lock' : 'unlock') . ($isFolder ? '<span>Folder</span>' : ($isPriv ? '<span>Private</span>' : '')) . '</button>';
    }
    // And a star beside it: press to make this photo its folder's cover (a gold star when it already is).
    if ($ownerLock !== '') {
        $isCoverNow = !empty(owner_cover_flags($GLOBALS['dir'], [$file])[$file]);
        $ownerLock .= '<button type="button" class="cover-toggle' . ($isCoverNow ? ' is-cover' : '') . '" data-file="' . htmlspecialchars($file, ENT_QUOTES, 'UTF-8') . '" aria-pressed="' . ($isCoverNow ? 'true' : 'false')
            . '" title="' . ($isCoverNow ? 'This is the folder\'s cover photo' : 'Make this the folder\'s cover photo') . '">' . ui_icon('star') . '</button>';
        // And a pencil in the other lower corner: opens the viewer with the editor for this photo's caption, link, place and switches.
        $ownerLock .= '<button type="button" class="edit-toggle" data-file="' . htmlspecialchars($file, ENT_QUOTES, 'UTF-8')
            . '" title="Edit caption, link and location" aria-label="Edit caption, link and location">' . ui_icon('edit') . '</button>';
    }
    $ownerAttrs = $ownerLock !== '' ? ' data-file="' . htmlspecialchars($file, ENT_QUOTES, 'UTF-8') . '"' : '';
    if ($ownerLock !== '') $ownerLock .= '<span class="sel-mark" aria-hidden="true">' . ui_icon('check') . '</span>';
    return '<div class="card' . ($ownerLock !== '' ? ' owner-card' : '') . ($vt['inner'] !== '' ? ' vtile' : '') . ($status ? ' ' . $status : '') . ($color ? ' ringed' : '') . '"' . $ownerAttrs . $vt['attrs'] . $idAttr . $colorStyle . ' onclick="openLightbox(' . $index . ')"><img src="'
         . htmlspecialchars(img_url($file, 'thumb'), ENT_QUOTES, 'UTF-8')
         . '"' . tile_srcset_attrs($file) . ' loading="lazy" alt="' . htmlspecialchars($file, ENT_QUOTES, 'UTF-8') . '">' . $vt['inner'] . $ownerLock . $durationBadge . $statusBadge
         . '<div class="card-badges">' . $itemBadges . $badge . '</div>'
         . '</div>';
}

/** For the signed-in owner: which of these files is the cover photo of the folder it sits in (file => true/false), read
 *  from each folder's own settings, once per folder. */
function owner_cover_flags($dir, $files) {
    static $covers = [];
    $out = [];
    foreach ($files as $f) {
        $real = containing_folder_real_path($dir, $f);
        if (!array_key_exists($real, $covers)) {
            $c = items_read($real)['cover'] ?? null;
            $covers[$real] = is_string($c) && $c !== '' ? $c : null;
        }
        $out[$f] = $covers[$real] !== null && $covers[$real] === basename($f);
    }
    return $out;
}

/** Whether view counts are shown on this page: to everyone when the site setting says so, and always to the signed-in owner. */
function view_counts_shown() {
    return SHOW_VIEW_COUNTS_PUBLICLY || is_admin_browser();
}

/** The small line drawings used instead of emoji (so every device shows the same, clear picture): one set, drawn
 *  with the current text colour. ui_icon_sprite() prints them once per page; ui_icon() refers to one. */
function ui_icon_paths() {
    return [
        'map'     => '<path d="M9 4 3 6.5V20l6-2.5 6 2.5 6-2.5V4l-6 2.5z"/><path d="M9 4v13.5M15 6.5V20"/>',
        'sort'    => '<path d="M8 20V4M8 4 4.5 7.5M8 4l3.5 3.5M16 4v16M16 20l-3.5-3.5M16 20l3.5-3.5"/>',
        'pin'     => '<path d="M12 21s-6.5-5.4-6.5-10.5a6.5 6.5 0 1 1 13 0C18.5 15.6 12 21 12 21z"/><circle cx="12" cy="10.5" r="2.3"/>',
        'caption' => '<path d="M20.5 15.5a2 2 0 0 1-2 2H9l-5 3.5V6.5a2 2 0 0 1 2-2h12.5a2 2 0 0 1 2 2z"/><path d="M8 9.5h8M8 13h5"/>',
        'globe'   => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a14 14 0 0 1 0 18a14 14 0 0 1 0-18"/>',
        'link'    => '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
        'eye'     => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'check'   => '<path d="M4.5 12.5 9.5 17.5 19.5 7"/>',
        'warn'    => '<path d="M12 3.5 2.5 20h19z"/><path d="M12 10v4.5M12 17.4h.01"/>',
        'play'    => '<path d="M8 5.5v13l10.5-6.5z"/>',
        'star'    => '<path d="m12 3.5 2.6 5.5 6 .8-4.4 4.2 1.1 6-5.3-2.9-5.3 2.9 1.1-6L3.4 9.8l6-.8z"/>',
        'chart'   => '<path d="M5 20V11M12 20V4M19 20v-6"/>',
        'zoom-in' => '<circle cx="11" cy="11" r="6.5"/><path d="M20 20l-4.2-4.2M11 8.5v5M8.5 11h5"/>',
        'zoom-out' => '<circle cx="11" cy="11" r="6.5"/><path d="M20 20l-4.2-4.2M8.5 11h5"/>',
        'gallery' => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="1.7"/><path d="m21 16-5-5L7 20"/>',
        'lock'    => '<rect x="5" y="11" width="14" height="9.5" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
        'unlock'  => '<rect x="5" y="11" width="14" height="9.5" rx="2"/><path d="M8 11V8a4 4 0 0 1 7.6-1.7"/>',
        'expand'  => '<path d="M15 3h6v6M9 21H3v-6M21 3l-7.5 7.5M3 21l7.5-7.5"/>',
        'chev-left'  => '<path d="M15.25 5.5 8.75 12l6.5 6.5"/>',
        'chev-right' => '<path d="M8.75 5.5 15.25 12l-6.5 6.5"/>',
        'close'   => '<circle cx="12" cy="12" r="9"/><path d="M8.4 8.4l7.2 7.2M15.6 8.4l-7.2 7.2"/>',
        'download' => '<path d="M12 4v11M7.5 10.5 12 15l4.5-4.5M5 19.5h14"/>',
        'move'    => '<path d="M12 3v18M3 12h18M12 3 9 6M12 3l3 3M12 21l-3-3M12 21l3-3M3 12l3-3M3 12l3 3M21 12l-3-3M21 12l-3 3"/>',
        'archive' => '<path d="M3 7.5 5 4h14l2 3.5V19a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1z"/><path d="M3 7.5h18M12 11v5.5M9.4 14l2.6 2.6 2.6-2.6"/>',
        'edit'    => '<path d="M4 20l1-4L16.5 4.5a2.1 2.1 0 0 1 3 3L8 19z"/><path d="M14.5 6.5l3 3"/>',
        'select'  => '<circle cx="12" cy="12" r="9"/><path d="M8 12.5l3 3 5-6"/>',
        'chev-down' => '<path d="M5.5 8.75 12 15.25l6.5-6.5"/>',
    ];
}
function ui_icon($name) {
    return '<svg class="ico" aria-hidden="true"><use href="#i-' . $name . '"></use></svg>';
}
function ui_icon_sprite() {
    $out = '<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">';
    foreach (ui_icon_paths() as $name => $paths) {
        $out .= '<symbol id="i-' . $name . '" viewBox="0 0 24 24">' . $paths . '</symbol>';
    }
    return $out . '</svg>';
}
function ui_icon_css() {
    return '.ico { width: 1.15em; height: 1.15em; vertical-align: -0.22em; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; flex: none; }';
}

/**
 * Resolves the accent color to actually use for a group: a {sold} or
 * {reserved} status always wins over a custom {color: value} — status
 * is the more important signal, and having the box/ring match it (not
 * just the ribbon) avoids sending two different color signals at once.
 */
function status_accent_color($status, $customColor) {
    if ($status === 'sold') return 'var(--sold)';
    if ($status === 'reserved') return 'var(--reserved)';
    return $customColor;
}

/**
 * A folder's highlight photo: the one the admin chose with "Set as cover"
 * (matched by filename among the folder's recursive files), else the
 * first actual photo — a video's generic placeholder makes a flat,
 * uninteresting highlight, so a video is used only if the folder has no
 * photos at all. Shared by the cover card and the folder's link preview
 * so the two can never disagree about which photo represents a folder.
 */
function pick_folder_cover($recursiveFiles, $manualCover = null) {
    if ($manualCover !== null) {
        foreach ($recursiveFiles as $f) {
            if (basename($f) === $manualCover) return $f;
        }
    }
    foreach ($recursiveFiles as $f) {
        if (!is_video_file($f)) return $f;
    }
    return $recursiveFiles[0] ?? null;
}

/**
 * A folder-group's cover card in 'overview' mode: one highlight photo
 * (the first in natural order — $recursiveFiles is already sorted this
 * way by the scan), the label, and the recursive photo count across the
 * whole subtree ($recursiveFiles already *is* that full recursive list,
 * so no separate counting pass is needed). The copy-link icon is a
 * sibling of the navigation link, not nested inside it — two <a> tags,
 * never one inside the other. $manualCover (a bare filename from the
 * folder's own .gallery-items.php — see ?admin's "Set as cover")
 * overrides the automatic choice when it's still a real member of this
 * folder's own recursive file list.
 */
/** What a set of items is called when counted for a person: "3 photos",
 *  "1 video", or "2 photos · 1 video". A video is not a photo, and a folder
 *  of clips said "1 photo" before this. */
function media_count_label($files) {
    $videos = 0;
    foreach ($files as $f) { if (is_video_file($f)) $videos++; }
    $photos = count($files) - $videos;
    $parts = [];
    if ($photos > 0) $parts[] = number_format($photos) . ' photo' . ($photos === 1 ? '' : 's');
    if ($videos > 0) $parts[] = number_format($videos) . ' video' . ($videos === 1 ? '' : 's');
    return $parts ? implode(' · ', $parts) : '0 photos';
}

function render_folder_cover_card($folderPath, $label, $recursiveFiles, $manualCover = null, $idKey = null, $meta = null, $status = '', $accentColor = null) {
    $folderUrl = htmlspecialchars('?folder=' . rawurlencode($folderPath), ENT_QUOTES, 'UTF-8');
    $coverFile = pick_folder_cover($recursiveFiles, $manualCover);
    $count = count($recursiveFiles);
    $slug = slugify($idKey ?? $folderPath);
    // The paragraph that mentions a folder can mark it sold or reserved, or give it a colour, like any other group.
    $style = $accentColor ? ' style="box-shadow: 0 0 0 3px ' . htmlspecialchars($accentColor, ENT_QUOTES, 'UTF-8') . ';"' : '';
    $html = '<div class="folder-cover' . ($status ? ' ' . $status : '') . '" id="group-' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') . '"' . $style . '>';
    $coverParts = $coverFile !== null ? video_tile_parts($coverFile) : ['attrs' => '', 'inner' => ''];
    $html .= '<a class="folder-cover-link' . ($coverParts['inner'] !== '' ? ' vtile' : '') . '" href="' . $folderUrl . '">';
    if ($status === 'sold') $html .= '<span class="sold-badge-corner">SOLD</span>';
    elseif ($status === 'reserved') $html .= '<span class="reserved-badge-corner">RESERVED</span>';
    if ($coverFile !== null) {
        // The picture is a square cut from the original photo (see generate_cover_jpeg): a small one for ordinary cards, and
        // a larger one offered too so a big card, or a sharp screen, takes it. The browser picks by the card's width.
        $coverSmall = htmlspecialchars(img_url($coverFile, 'cover'), ENT_QUOTES, 'UTF-8');
        $coverLarge = htmlspecialchars(img_url($coverFile, 'coverlg'), ENT_QUOTES, 'UTF-8');
        $sizes = !empty($GLOBALS['__big_covers']) ? '(min-width: 640px) 520px, 100vw' : '(min-width: 640px) 330px, 100vw';
        $html .= '<img src="' . $coverSmall . '" srcset="' . $coverSmall . ' 640w, ' . $coverLarge . ' 1200w" sizes="' . $sizes
                . '" loading="lazy" alt="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '">' . $coverParts['inner'];
    }
    $html .= '<span class="folder-cover-label">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
    if (!empty($meta['dates'])) {
        $html .= '<span class="folder-cover-dates">' . htmlspecialchars($meta['dates'], ENT_QUOTES, 'UTF-8') . '</span>';
    }
    $html .= '<span class="folder-cover-count">' . htmlspecialchars(media_count_label($recursiveFiles), ENT_QUOTES, 'UTF-8') . '</span>';
    $html .= '</a>';
    $html .= '<a class="permalink-copy folder-permalink-copy" href="' . $folderUrl . '" title="Copy link">' . ui_icon('link') . '</a>';
    $html .= '</div>';
    return $html;
}

/** Builds the whole gallery section: boxed groups (with optional per-group
 *  accent color, sold/reserved ribbon, and a live-updatable total view
 *  count) + a plain row for standalone singles. */
function build_gallery_html($groups, $groupColors, $groupStatus, $photoViews, $groupLabels = [], $explicitGroupKeys = [], $folderGroupKeys = [], $videoDurations = [], $folderCovers = [], $itemsData = [], $folderMeta = [], $folderLinkPrefix = '') {
    $html = '';
    $flatIndex = 0;

    foreach ($groups as $key => $groupFiles) {
        // A folder-group in 'overview' mode renders as a cover card — one
        // highlight photo, a label, a recursive photo count — not a full
        // inline grid; drilling into it happens on its own page
        // (?folder=<path>), not in the lightbox, so none of its members'
        // lightbox indices are assigned here. $flatIndex still has to
        // advance by the group's full member count regardless, to stay
        // in sync with $files — the global flat list every other
        // rendered card's lightbox index is drawn from.
        if (isset($folderGroupKeys[$key]) && FOLDER_DISPLAY_MODE === 'overview') {
            $label = $groupLabels[$key] ?? $key;
            $coverStatus = $groupStatus[$key] ?? '';
            $html .= render_folder_cover_card($folderLinkPrefix . $key, $label, $groupFiles, $folderCovers[$key] ?? null, $key, $folderMeta[$key] ?? null, $coverStatus, status_accent_color($coverStatus, $groupColors[$key] ?? null));
            $flatIndex += count($groupFiles);
            continue;
        }

        $status = $groupStatus[$key] ?? '';
        $accentColor = status_accent_color($status, $groupColors[$key] ?? null);
        // An auto-detected single (one file, no explicit {group:} naming
        // it) never gets its own box. An explicitly named group always
        // does, even with just one photo — that's the point of naming
        // it. $groups is already ordered boxes-first by this point (see
        // compute_zone_order), so every single/cluster card below is
        // part of one uninterrupted run — no box ever lands in the
        // middle of it to fragment the grid.
        $slug = slugify($key);
        if (count($groupFiles) === 1 && !isset($explicitGroupKeys[$key])) {
            $html .= render_card($groupFiles[0], $flatIndex, $photoViews, $status, $accentColor, 'group-' . $slug, $videoDurations[$groupFiles[0]] ?? null, $itemsData[$groupFiles[0]] ?? null);
            $flatIndex++;
            continue;
        }
        $label = $groupLabels[$key] ?? $key;

        $boxStyle = '';
        $labelStyle = '';
        if ($accentColor) {
            $color = htmlspecialchars($accentColor, ENT_QUOTES, 'UTF-8');
            $boxStyle = ' style="border-left-color: ' . $color . ';"';
            $labelStyle = ' style="color: ' . $color . ';"';
        }

        $groupTotal = 0;
        foreach ($groupFiles as $gf) {
            $groupTotal += isset($photoViews[$gf]) ? (int) $photoViews[$gf] : 0;
        }
        $viewsHidden = (view_counts_shown() && ($groupTotal > 0 || (TRACK_VIEWS && is_admin_browser()))) ? '' : ' hidden';

        $html .= '<div class="group-box' . ($status ? ' ' . $status : '') . '"' . $boxStyle . ' id="group-' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') . '">';
        $html .= '<h2 class="group-label"' . $labelStyle . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
                . ' <span class="group-views' . $viewsHidden . '" data-group="' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') . '">&middot; '
                . number_format($groupTotal) . ' view' . ($groupTotal === 1 ? '' : 's') . '</span> '
                . render_permalink_button('group-' . $slug) . '</h2>';
        $html .= '<div class="group-grid">';
        foreach ($groupFiles as $file) {
            $html .= render_card($file, $flatIndex, $photoViews, '', null, null, $videoDurations[$file] ?? null, $itemsData[$file] ?? null);
            $flatIndex++;
        }
        $html .= '</div></div>';
    }
    return $html;
}

/**
 * Where "back to the gallery" points. Normally just "./", which resolves
 * to the gallery's own folder. But if the web server maps a pretty folder
 * link (/kb/Europe/France/) onto this script, "./" would resolve to that
 * folder's own page instead — the back-link would go nowhere. In that
 * case, point at the script's real directory explicitly. Every other
 * relative link on a page (?img=, ?folder=, ?about, #anchors) resolves
 * correctly from a pretty URL because the query string rides along.
 */
function gallery_root_href() {
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $scriptDir = rtrim(dirname($script), '/') . '/';
    if ($script === '' || !is_string($requestPath) || $requestPath === $script || $requestPath === $scriptDir) {
        return './';
    }
    return implode('/', array_map('rawurlencode', explode('/', $scriptDir)));
}

/** What makes "Add to Home Screen" open the gallery as an app with no browser bars at all (the phone's address bar and
 *  toolbar, which on an iPhone can't be hidden from a web page, are what keep popping up). Only the pages of someone who is
 *  signed in (the owner, or a viewer with the private-view password) carry it; nothing changes in an ordinary visitor's tab. */
function home_screen_app_tags($title) {
    if (!is_revealed()) return '';
    $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $root = htmlspecialchars(gallery_root_href(), ENT_QUOTES, 'UTF-8');
    return '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n"
         . '<meta name="mobile-web-app-capable" content="yes">' . "\n"
         . '<meta name="apple-mobile-web-app-status-bar-style" content="black">' . "\n"
         . '<meta name="apple-mobile-web-app-title" content="' . $t . '">' . "\n"
         . '<meta name="theme-color" content="#0f0f10">' . "\n"
         . '<link rel="apple-touch-icon" href="' . $root . '?appicon&amp;v=2">' . "\n"
         . '<link rel="manifest" href="' . $root . '?manifest&amp;name=' . htmlspecialchars(rawurlencode($title), ENT_QUOTES, 'UTF-8') . '">' . "\n";
}

/** Builds the current request's scheme://host/path, for absolute OG image URLs. */
function current_base_url() {
    $scheme = is_https_request() ? 'https' : 'http';
    // The Host header is whatever the client sent. It ends up in absolute
    // URLs (link previews), so only accept something shaped like a host
    // (name or IP, optional port, IPv6 in brackets) and otherwise fall back
    // to the server's own name — never echo arbitrary text into a URL.
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (!preg_match('/^(?:[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?|\[[0-9A-Fa-f:.]+\])(?::\d{1,5})?$/', $host)) {
        $host = $_SERVER['SERVER_NAME'] ?? 'localhost';
    }
    $path = isset($_SERVER['PHP_SELF']) ? rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'])), '/') : '';
    return $scheme . '://' . $host . $path;
}

/**
 * Renders the "?about" page: a standalone documentation page (with a
 * link to the GitHub repo at the top) explaining everything this
 * gallery does, for anyone who found it deployed somewhere and wants to
 * use it themselves. Kept as a self-contained HTML string rather than a
 * .md-style file so it never depends on anything else in the folder.
 */
function render_about_page() {
    $version = htmlspecialchars(GALLERY_VERSION, ENT_QUOTES, 'UTF-8');
    $aboutUrl = htmlspecialchars(current_base_url() . '/?about', ENT_QUOTES, 'UTF-8');
    $repoUrl = 'https://github.com/pcmike/gallery';
    $aboutDescription = 'A single-file, drop-in PHP photo gallery for trips and listings: folders, video, captions, optional maps, Markdown notes, a browser-based admin, and shareable links.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>About this gallery — v<?= $version ?></title>
<meta name="description" content="<?= htmlspecialchars($aboutDescription) ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="Drop-in Photo Gallery — v<?= $version ?>">
<meta property="og:description" content="<?= htmlspecialchars($aboutDescription) ?>">
<meta property="og:url" content="<?= $aboutUrl ?>">
<meta name="twitter:card" content="summary">
<style>
  :root {
    --bg: #0f0f10; --card: #1a1a1c; --text: #eaeaea; --muted: #8a8a8f; --accent: #5865f2;
  }
  * { box-sizing: border-box; }
  body {
    margin: 0; background: var(--bg); color: var(--text);
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    line-height: 1.6;
  }
  .wrap { max-width: 720px; margin: 0 auto; padding: 32px 24px 64px; }
  .top-bar {
    display: flex; align-items: center; justify-content: space-between;
    flex-wrap: wrap; gap: 12px; margin-bottom: 28px;
  }
  .back-link {
    font-size: 0.82rem; line-height: 1; color: var(--muted); text-decoration: none;
    background: var(--card); border: 1px solid rgba(255, 255, 255, 0.07);
    padding: 7px 13px; border-radius: 999px;
    transition: color .15s, border-color .15s, background .15s;
  }
  .back-link:hover { color: var(--text); border-color: var(--accent); background: rgba(88, 101, 242, 0.12); }
  .back-link:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
  .version-tag { color: var(--muted); font-size: 0.8rem; }
  h1 { font-size: 1.5rem; margin: 0 0 6px; }
  .subtitle { color: var(--muted); margin: 0 0 24px; }
  .download-btn {
    display: inline-block; background: var(--accent); color: #fff;
    text-decoration: none; font-weight: 600; padding: 12px 20px;
    border-radius: 8px;
  }
  .download-btn:hover { opacity: 0.9; }
  h2 { font-size: 1.15rem; margin: 32px 0 10px; border-top: 1px solid rgba(255,255,255,0.08); padding-top: 24px; }
  p, li { color: #d5d5d8; font-size: 0.95rem; }
  p a { border-radius: 3px; transition: color .15s, background .15s, box-shadow .15s; }
  p a:hover { color:#e1e4ff; background:rgba(170,178,255,0.16); box-shadow:0 0 0 3px rgba(170,178,255,0.16); border-radius:3px; }
  ul { padding-left: 22px; }
  li { margin-bottom: 6px; }
  code {
    background: rgba(255,255,255,0.08); padding: 1px 6px; border-radius: 4px;
    font-size: 0.88em; color: #eaeaea;
  }
  a { color:#aab2ff; text-decoration:none; }
</style>
</head>
<body>
<div class="wrap">

  <div class="top-bar">
    <a class="back-link" href="<?= htmlspecialchars(gallery_root_href(), ENT_QUOTES, 'UTF-8') ?>">&larr; Back to gallery</a>
    <span class="version-tag">v<?= $version ?></span>
  </div>

  <h1>Drop-in Photo Gallery</h1>
  <p class="subtitle">One PHP file. No dependencies, no build step. Drop it into a folder of photos.</p>
  <a class="download-btn" href="<?= htmlspecialchars($repoUrl, ENT_QUOTES, 'UTF-8') ?>">&#11015; Get it on GitHub</a>

  <h2>For visitors</h2>
  <ul>
    <li><strong>Sharp thumbnails:</strong> each photo's tile is a square cut from its middle, made at two sizes so a phone gets a crisp one and a desktop a light one, loaded only as you scroll</li>
    <li><strong>A full-screen viewer</strong> with arrow keys and swipe, captions, links, a copy-link button, and a small map when the photo has a place; click or press Z to fill the width, again for real pixels</li>
    <li><strong>Built for phones:</strong> the counter and buttons sit in one row across the top, so the photo is as big as the screen allows and nothing needs tapping near Safari's toolbar; download opens the share sheet at once</li>
    <li><strong>Over a zoomed photo</strong> the controls sit on a soft dark pill so they stay readable, and each lights up when you point at it</li>
    <li>Video: length badges, silent hover clips and finger-scrubbing on the tiles, real playback in the viewer</li>
    <li>Trips as folders: cover cards with dates and counts, to any depth, and a viewer that stays inside the folder</li>
    <li>Optional maps, loaded only when someone opens one</li>
    <li>Sorting by name or date either way round, with videos, located photos or private items first; visitors can re-sort for themselves with one button</li>
    <li>Drag and drop to place photos next to each other (the church shots taken on other days), kept together under every sort</li>
    <li>Download the original (if allowed) or a whole folder as a ZIP, with hidden locations removed from what visitors get</li>
    <li>Shareable links to a photo, folder or map, with proper previews; end a gallery link with <code>#map</code> and it opens with the small map showing (and a link icon on that map copies it)</li>
  </ul>

  <h2>For the owner</h2>
  <ul>
    <li>Set up in the browser: upload, open <code>?admin</code>, choose a password</li>
    <li><strong>Edit right on the gallery:</strong> a pencil on every photo opens its caption, link, place, private and cover switches beside or under the photo, saved as you type; <em>Select</em> makes many photos private or public, or places them next to each other by drag and drop</li>
    <li><strong>Go through a private folder before you open it:</strong> hide a folder, mark the photos that must stay private on their own, then make the folder public knowing what visitors will see; folder settings save as you change them</li>
    <li>An admin for folder names and dates, notes, and Site settings that save as you flip them, with a health check that looks over the server for you</li>
    <li><strong>Put first is one list you order:</strong> switch on private items, videos and located photos and drag them into the order they stack in; a sentence says the result, and a folder can follow the site's list or have its own</li>
    <li>A lock and a star on every tile, for you alone (and the same two in the viewer): one press makes a photo private or public, or its folder's cover</li>
    <li><strong>Prepare thumbnails</strong> makes every photo's pictures ahead of time with a progress bar, so the first visit to a big folder isn't slow</li>
    <li>Markdown notes with <code>{photos}</code>, <code>{group}</code>, <code>{sold}</code> and <code>{reserved}</code>, for trips or for-sale listings</li>
    <li>Private photos and folders behind a separate viewer password, and visit counts</li>
    <li>On an iPhone, add the gallery to your Home Screen while signed in and it opens as an app with no browser bars</li>
    <li>Export everything you typed as JSON, the only backup you need of captions and places</li>
  </ul>

  <h2>Careful by default</h2>
  <ul>
    <li>Writes only its own small files (thumbnails, settings, captions) and never touches your photos</li>
    <li>Locations, maps, video, subfolders and passwords stay off until you turn them on, and the admin says what each one publishes</li>
    <li>Private items are removed outright for anyone without the password, and the sign-in box is built so browsers don't offer to save it</li>
  </ul>

  <h2>Documentation</h2>
  <p>Setup, settings and every feature: <a href="<?= htmlspecialchars($repoUrl) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($repoUrl) ?></a></p>

</div>
</body>
</html>
<?php
}

// Populated as note files are parsed below; read by the gallery further down.
$groupColors = [];
$groupStatus = [];
$groupLabels = [];

// Admin-set display names (see ?admin) seed $groupLabels here, before any
// {group:} directive in a .md gets its turn further down — a directive
// always wins over an admin-set name, the same precedent as any other
// explicit claim beating auto-detection (the admin UI disables the field
// in that case rather than accepting a value that then appears to do
// nothing). A folder-group's name lives in that folder's own
// .gallery-items.php; a root-level filename-based group's lives in the
// root's own.
$folderCovers = [];
$folderMeta = [];       // folder key => ['description' => .., 'dates' => ..], for its cover card
$folderMentionLinks = []; // group key => href, for a .md mention's jump link (overview mode only)
foreach ($folderGroupKeys as $folderKey => $_true) {
    $m = folder_display_meta($dir, $folderKey);
    if ($m['label'] !== '') {
        $groupLabels[$folderKey] = $m['label'];
    }
    if ($m['cover'] !== null) {
        $folderCovers[$folderKey] = $m['cover'];
    }
    $folderMeta[$folderKey] = ['description' => $m['description'], 'dates' => $m['dates']];
    if (FOLDER_DISPLAY_MODE === 'overview') {
        $folderMentionLinks[$folderKey] = '?folder=' . rawurlencode($folderKey);
    }
}
$rootItemsData = items_read($dir);
foreach (($rootItemsData['groups'] ?? []) as $groupKey => $groupMeta) {
    if (isset($folderGroupKeys[$groupKey]) || !isset($groups[$groupKey])) continue;
    if (!empty($groupMeta['displayName'])) {
        $groupLabels[$groupKey] = $groupMeta['displayName'];
    }
}

// Tracks which files an explicit {photos:} directive has already
// consumed *during rendering*, across every .md file, mirroring the
// same first-occurrence-wins rule already applied once during
// extraction (see GROUPING above) — without this, a file that lost a
// duplicate-claim conflict there would still pass render-time's own
// (otherwise unaware) validation and render a second time.
$renderClaims = [];

// Open Graph / Twitter Card data for link previews. A ?photo= deep link
// reaches the server (unlike a #fragment one — see "DEEP LINKING" above),
// so its preview can point at that specific photo and mention its group.
$baseUrl = current_base_url();

// The map's data — computed once here, reused both by a ?map link
// (the page with the overlay open) and the page's own map buttons
// (see the main template further down). Once $files/
// $itemsData/$fileToGroupKey/$folderGroupKeys/$groupLabels all reflect
// their final, hiding-filtered, admin-named state. A {group:} directive
// in a .md could still rename a group after this point (during notes
// rendering, further down) — a map legend showing a folder's raw name
// in that specific case is a reasonable, minor simplification rather
// than forcing notes processing to run early just for this.
$mapPoints = ENABLE_MAP ? collect_map_points($dir, $files, $itemsData, $fileToGroupKey, $folderGroupKeys, $groupLabels) : [];
// A map belongs to the page it is on: the root's covers everything, a folder's just what is inside it, and each
// has its own switch (the root's is on unless turned off, a folder's off unless turned on).
$mapShown = ENABLE_MAP && folder_map_enabled($dir, $currentFolderPath);
$mapPoints = map_points_for_folder($mapPoints, $dir, $currentFolderPath);
$mapLegend = map_legend_from_points($mapPoints);
$mapPageHref = '?map' . ($currentFolderPath !== null ? '&folder=' . rawurlencode($currentFolderPath) : '');
$mapPageHrefAbs = '/' . $mapPageHref;
$mapPeekHref = ($currentFolderPath !== null ? '?folder=' . rawurlencode($currentFolderPath) : '') . '#map';   // the page with its small map already open
$mapRadius = ENABLE_MAP ? folder_map_radius($dir, $currentFolderPath) : MAP_CLUSTER_RADIUS_MILES;

// ?map — a shareable link to the map: the gallery page (?map&folder=X: that folder's page) with the map overlay open.
if (ENABLE_MAP && isset($_GET['map'])) {
    if (!$mapShown) {
        http_response_code(404);
        exit;
    }
    // The map has no page of its own: ?map (and ?map&folder=X) is this gallery page with the map overlay open, so one
    // link shows the map over the very page it belongs to.
    $openMapOnLoad = true;
}

$ogTitle = $pageTitle;
$ogUrl = $baseUrl . '/';
$ogDescription = $rootMeta['description'] !== '' ? markdown_to_plaintext($rootMeta['description']) : (!empty($mdFiles)
    ? markdown_to_plaintext($mdContents[$mdFiles[0]])
    : media_count_label($files));
// The cover chosen in the admin, if it is still a visible root-level photo.
// With no cover chosen: the cover of one of the gallery's folders (the cover its owner chose, else its first photo), picked from the
// folders this requester may see (a link-preview crawler has no cookie, so never a private one) and the same one all day, so the
// link doesn't show a different picture in every chat. A gallery without folders uses one of its photos the same way. Never a video.
$ogVisible = array_flip($files);
$ogCovers = [];
foreach ($folderGroups as $ogTop => $ogMembers) {
    $ogMembers = array_values(array_filter($ogMembers, function ($f) use ($ogVisible) { return isset($ogVisible[$f]); }));
    $ogCover = $ogMembers ? pick_folder_cover($ogMembers, folder_display_meta($dir, $ogTop)['cover']) : null;
    if ($ogCover !== null && !is_video_file($ogCover)) $ogCovers[] = $ogCover;
}
$ogPhotos = array_values(array_filter($files, function ($f) { return !is_video_file($f); }));
$ogPool = $ogCovers ?: $ogPhotos;
$ogRootPhoto = ($rootMeta['cover'] !== null && in_array($rootMeta['cover'], $files, true)) ? $rootMeta['cover']
    : ($ogPool ? $ogPool[crc32(gmdate('Y-m-d') . '|' . $dir) % count($ogPool)] : ($files[0] ?? null));
$ogImageUrl = $ogRootPhoto !== null ? $baseUrl . '/' . img_url($ogRootPhoto, 'share') : '';
if ($currentFolderPath !== null) {
    // $pageTitle is already folder-prefixed (see the ?folder= validation
    // block above), so $ogTitle below already picked that up — only the
    // url/description/image need their own folder-scoped values here.
    $ogUrl = $baseUrl . '/?folder=' . rawurlencode($currentFolderPath);
    $folderMemberFiles = $currentFolderFiles;
    $ogDescription = media_count_label($folderMemberFiles);
    if ($currentFolderMeta['description'] !== '') {
        $ogDescription = markdown_to_plaintext($currentFolderMeta['description']);
    }
    // A subfolder's own notes .md (see the full scan further down, once
    // $groups/$files are actually scoped to it) can supply a real
    // description here too — just the plaintext summary, which doesn't
    // need the heavier mention/directive-aware pass that scan to feed
    // the visible note box does.
    $earlyFolderMdScan = scan_gallery_md_files(resolve_real_path($dir, $currentFolderPath));
    if (!empty($earlyFolderMdScan['files'])) {
        $ogDescription = markdown_to_plaintext($earlyFolderMdScan['contents'][$earlyFolderMdScan['files'][0]]);
    }
    if (!empty($folderMemberFiles)) {
        $ogImageUrl = $baseUrl . '/' . img_url(pick_folder_cover($folderMemberFiles, $currentFolderMeta['cover']), 'share');
    }
}
if ($requestedPhoto) {
    $ogImageUrl = $baseUrl . '/' . img_url($requestedPhoto, 'share');
    $ogUrl = $baseUrl . '/?photo=' . rawurlencode($requestedPhoto);
    $photoGroupKey = group_key($requestedPhoto);
    if (isset($groups[$photoGroupKey])) {
        $ogTitle = $photoGroupKey . ' — ' . $pageTitle;
    }
}
if (!empty($openMapOnLoad)) {
    // A shared ?map link previews as the map, not as the page behind it.
    $mapLabel = $currentFolderPath === null ? $rootPageTitle : $pageTitle;
    $ogTitle = $mapLabel . ' — Map';
    $pageTitle = $ogTitle;
    $ogUrl = $baseUrl . $mapPageHrefAbs;
    $ogDescription = count($mapPoints) . ' location' . (count($mapPoints) === 1 ? '' : 's') . ' plotted from this gallery.';
    $ogImageUrl = $baseUrl . '/?mapicon';
}
send_viewer_aware_cache_headers();
?>
<!-- Drop-in Photo Gallery v<?= htmlspecialchars(GALLERY_VERSION) ?> — github.com/pcmike -->
<?php if (!empty($galleryNotices) && notices_visible()): ?>
<!--
gallery notices (also shown on the page itself, in a collapsed banner):
<?php foreach ($galleryNotices as $notice): ?>
  - <?= htmlspecialchars(str_replace('--', '- -', $notice), ENT_QUOTES, 'UTF-8') ?>

<?php endforeach; ?>
-->
<?php endif; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?> — Gallery</title>
<meta name="description" content="<?= htmlspecialchars($ogDescription) ?>">
<?= home_screen_app_tags($rootPageTitle) ?>
<?php if ($currentFolderPath !== null): ?><meta name="gallery-folder" content="<?= htmlspecialchars($currentFolderPath) ?>">
<?php endif; ?>
<meta property="og:type" content="website">
<meta property="og:title" content="<?= htmlspecialchars($ogTitle) ?>">
<meta property="og:description" content="<?= htmlspecialchars($ogDescription) ?>">
<meta property="og:url" content="<?= htmlspecialchars($ogUrl) ?>">
<?php if ($ogImageUrl): ?>
<meta property="og:image" content="<?= htmlspecialchars($ogImageUrl) ?>">
<meta name="twitter:card" content="summary_large_image">
<?php else: ?>
<meta name="twitter:card" content="summary">
<?php endif; ?>
<style>
  :root {
    --bg: #0f0f10;
    --card: #1a1a1c;
    --text: #eaeaea;
    --muted: #8a8a8f;
    --accent: #5865f2;
    --sold: #e5484d;
    --reserved: #f5a623;
  }
  * { box-sizing: border-box; }
  body {
    margin: 0;
    background: var(--bg);
    color: var(--text);
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
  }
  header {
    padding: 32px 24px 16px;
    text-align: center;
  }
  /* For the signed-in owner the title leads back to the admin, and looks and behaves exactly like plain text: no underline,
     colour, cursor or tooltip change, on hover or otherwise (keyboard focus still shows the browser's normal ring). */
  header h1 a.title-admin { color: inherit; text-decoration: none; cursor: inherit; -webkit-tap-highlight-color: transparent; }
  /* The lock is one of the icons in the strip (beside the archive icon): green while signed in. */
  .map-combo-btn.lock-link.open { color: #3ba55c; }
  /* Breadcrumb: a row of pills (the separators in the markup are hidden)
     with a chevron before each level after the first. */
  header p.breadcrumb {
    display: flex; flex-wrap: wrap; justify-content: center; gap: 6px;
    font-size: 0; margin: 0 0 14px;
  }
  header p.breadcrumb a {
    font-size: 0.82rem; line-height: 1;
    color: var(--muted); text-decoration: none;
    background: var(--card); border: 1px solid rgba(255, 255, 255, 0.07);
    padding: 7px 13px; border-radius: 999px;
    transition: color .15s, border-color .15s, background .15s;
  }
  header p.breadcrumb a:hover { color: var(--text); border-color: var(--accent); background: rgba(88, 101, 242, 0.12); }
  header p.breadcrumb a:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
  header p.breadcrumb a + a::before { content: "\203A"; margin-right: 9px; opacity: 0.55; }
  /* The way back and the folders beside this one share ONE line: the way back stays put at the left while the sibling pills
     swipe; the current folder is lit. When it all fits it is centred. */
  header .crumb-row { position: relative; display: flex; align-items: center; gap: 10px; overflow-x: auto; margin: 0 0 14px; padding: 2px 2px 6px; scrollbar-width: none; -webkit-overflow-scrolling: touch; }
  header .crumb-row::-webkit-scrollbar { display: none; }
  header .crumb-row p.breadcrumb { flex: none; flex-wrap: nowrap; margin: 0 0 0 auto; }
  header .crumb-row p.breadcrumb:only-child { margin-right: auto; }
  header .crumb-row nav.folder-pills { margin-right: auto; }
  header .crumb-row p.breadcrumb:not(:only-child) { position: sticky; left: 0; z-index: 2; background: var(--bg); box-shadow: 8px 0 8px -4px var(--bg); }
  header .crumb-row p.breadcrumb:not(:only-child)::after { content: "\2022"; font-size: 1rem; line-height: 1; color: var(--muted); opacity: 0.7; align-self: center; margin-left: 4px; margin-right: 0; }
  header nav.folder-pills { flex: none; display: flex; gap: 6px; }
  header nav.folder-pills a { flex: none; font-size: 0.82rem; line-height: 1; color: var(--muted); text-decoration: none; background: var(--card); border: 1px solid rgba(255, 255, 255, 0.07); padding: 7px 13px; border-radius: 999px; white-space: nowrap; transition: color .15s, border-color .15s, background .15s; }   /* the same pill as the breadcrumb's */
  header nav.folder-pills a:hover { color: var(--text); border-color: var(--accent); background: rgba(88, 101, 242, 0.12); }
  header nav.folder-pills a.current { color: #fff; background: var(--accent); border-color: var(--accent); }
  header nav.folder-pills a.all { border-style: dashed; }
  header h1 {
    margin: 0 0 4px;
    font-size: 1.6rem;
    font-weight: 600;
  }
  header p {
    margin: 0;
    color: var(--muted);
    font-size: 0.9rem;
  }

  .hidden { display: none !important; }

  /* Markdown note / announcement box */
  .notes {
    max-width: 700px;
    margin: 0 auto 8px;
    padding: 0 24px;
  }
  .gallery-notices {
    background: rgba(245, 166, 35, 0.08);
    border: 1px solid rgba(245, 166, 35, 0.35);
    border-radius: 8px;
    padding: 10px 14px;
    margin-bottom: 16px;
    font-size: 0.85rem;
  }
  .gallery-notices summary {
    cursor: pointer;
    color: var(--reserved);
    font-weight: 600;
  }
  .gallery-notices ul {
    margin: 10px 0 0;
    padding-left: 20px;
    color: var(--muted);
  }
  .gallery-notices li {
    margin-bottom: 6px;
  }
  .gallery-notices li:last-child {
    margin-bottom: 0;
  }
  .note-box {
    background: #202225;
    border-left: 4px solid var(--accent);
    border-radius: 8px;
    padding: 18px 20px;
    margin-bottom: 16px;
    font-size: 0.95rem;
    line-height: 1.5;
  }
  .note-box .note-filename {
    display: block;
    color: var(--muted);
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: 8px;
  }
  .note-box p { margin: 0 0 10px; }
  .note-box p:last-child { margin-bottom: 0; }
  .note-box h1, .note-box h2, .note-box h3 {
    margin: 0 0 10px;
    font-weight: 600;
  }
  .note-box h1 { font-size: 1.2rem; }
  .note-box h2 { font-size: 1.1rem; }
  .note-box h3 { font-size: 1rem; }
  .note-box ul, .note-box ol {
    margin: 0 0 10px;
    padding-left: 22px;
  }
  .note-box li { margin-bottom: 4px; }
  .note-box code {
    background: rgba(255,255,255,0.08);
    padding: 1px 6px;
    border-radius: 4px;
    font-size: 0.88em;
  }
  .note-box pre {
    background: rgba(255,255,255,0.08);
    padding: 10px 12px;
    border-radius: 6px;
    overflow-x: auto;
    margin: 0 0 10px;
  }
  .note-box pre code { background: none; padding: 0; }
  .note-box blockquote {
    margin: 0 0 10px;
    padding-left: 12px;
    border-left: 3px solid var(--muted);
    color: var(--muted);
  }
  .note-box a { color: #aab2ff; border-radius: 3px; transition: color .15s, background .15s, box-shadow .15s; }
  .note-box a:hover { color:#e1e4ff; background:rgba(170,178,255,0.16); box-shadow:0 0 0 3px rgba(170,178,255,0.16); border-radius:3px; }
  .note-box u { text-decoration: underline; }
  .note-box del { color: var(--muted); }
  .sold-item p { opacity: 0.55; text-decoration: line-through; margin-bottom: 6px; }
  .reserved-item p { opacity: 0.7; margin-bottom: 6px; }
  .sold-badge, .sold-badge-corner {
    display: inline-block;
    background: var(--sold);
    color: #fff;
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.05em;
    padding: 2px 8px;
    border-radius: 10px;
  }
  .reserved-badge, .reserved-badge-corner {
    display: inline-block;
    background: var(--reserved);
    color: #1a1305;
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.05em;
    padding: 2px 8px;
    border-radius: 10px;
  }
  .sold-badge, .reserved-badge { margin-bottom: 6px; }

  /* Per-item permalinks (#item-...) and the highlight flash when landing on one */
  .permalink-copy {
    color: var(--muted);
    text-decoration: none;
    font-size: 0.8rem;
    opacity: 0.75;
  }
  .permalink-copy:hover { opacity: 1; }
  .permalink-copy.copied { color: #3ba55c; opacity: 1; }
  .note-box p, .note-box .sold-item, .note-box .reserved-item { scroll-margin-top: 20px; }
  .note-box p:target,
  .note-box .sold-item:target,
  .note-box .reserved-item:target {
    border-radius: 6px;
    animation: item-flash 2s ease-out;
  }
  @keyframes item-flash {
    0% { background: rgba(88, 101, 242, 0.35); }
    100% { background: rgba(88, 101, 242, 0); }
  }

  /* Thumbnail strip auto-linked from note text to a photo group */
  .linked-photos {
    margin: 4px 0 14px;
    padding-top: 8px;
    border-top: 1px solid rgba(255,255,255,0.08);
  }
  .linked-photos-thumbs {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    margin-bottom: 6px;
  }
  .linked-photos-thumbs img {
    width: 56px;
    height: 56px;
    object-fit: cover;
    border-radius: 6px;
    cursor: pointer;
    transition: transform 0.15s ease;
  }
  .linked-photos-thumbs img:hover { transform: scale(1.08); }
  .linked-photos.sold .linked-photos-thumbs img { filter: grayscale(1) brightness(0.65); }
  .linked-photos-meta {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
  }
  .linked-photos-jump {
    color: #aab2ff;
    font-size: 0.8rem;
    text-decoration: none;
    border-radius: 3px;
    transition: color .15s, background .15s, box-shadow .15s;
  }
  .linked-photos-jump:hover { color:#e1e4ff; background:rgba(170,178,255,0.16); box-shadow:0 0 0 3px rgba(170,178,255,0.16); border-radius:3px; }

  /* Gallery */
  .gallery {
    max-width: 1400px;
    margin: 0 auto;
    padding: 16px 24px 48px;
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 10px;
  }
  /* A few folder cards and nothing else: larger on a wide screen, centred. Narrower screens keep the normal grid. */
  .gallery[class*="covers-"] .folder-cover-label { font-size: 1.1rem; }
  /* The box is as wide as its cards need, so the map buttons above (same class) share the first card's left edge. */
  .gallery.covers-1, .map-preview.covers-1 { max-width: 608px; }
  .gallery.covers-2, .map-preview.covers-2 { max-width: 1106px; }
  .gallery.covers-3, .map-preview.covers-3 { max-width: 1280px; }
  .gallery.covers-4, .map-preview.covers-4 { max-width: 1290px; }
  @media (min-width: 640px) {
    .gallery.covers-1 { grid-template-columns: minmax(0, 1fr); }
    .gallery.covers-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; }
  }
  @media (min-width: 860px) {
    .gallery.covers-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; }
  }
  @media (min-width: 1080px) {
    .gallery.covers-4 { grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; }
  }
  .group-box {
    grid-column: 1 / -1;
    margin: 6px 0;
    background: var(--card);
    border: 1px solid rgba(255,255,255,0.08);
    border-left: 4px solid rgba(255,255,255,0.18);
    border-radius: 12px;
    padding: 18px;
    scroll-margin-top: 16px;
    position: relative;
    overflow: hidden;
  }
  .group-box:target {
    animation: item-flash 2s ease-out;
  }
  .group-box.sold::after,
  .group-box.reserved::after {
    position: absolute;
    top: 14px;
    right: -34px;
    width: 130px;
    padding: 4px 0;
    font-size: 0.7rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-align: center;
    transform: rotate(45deg);
    box-shadow: 0 2px 6px rgba(0,0,0,0.35);
  }
  .group-box.sold::after {
    content: "SOLD";
    background: var(--sold);
    color: #fff;
  }
  .group-box.reserved::after {
    content: "RESERVED";
    background: var(--reserved);
    color: #1a1305;
  }
  .group-box.sold .card img { filter: grayscale(1) brightness(0.6); }
  .group-box.sold .group-label { opacity: 0.6; }
  .group-box.reserved .group-label { opacity: 0.75; }

  /* Folder-group cover card (FOLDER_DISPLAY_MODE='overview') — a normal
     grid tile, not a full-width row, so several albums sit side by side. */
  .folder-cover {
    position: relative;
    background: var(--card);
    border-radius: 12px;
    overflow: hidden;
  }
  .folder-cover-link {
    display: block;
    text-decoration: none;
    color: inherit;
  }
  .folder-cover-link img {
    width: 100%;
    aspect-ratio: 1 / 1;
    object-fit: cover;
    display: block;
    transition: transform 0.25s ease;
  }
  .folder-cover-link:hover img { transform: scale(1.05); }
  .folder-cover.sold img { filter: grayscale(1) brightness(0.6); }
  /* The sold picture has a filter, which makes it paint over an earlier, positioned sibling: lift the ribbon above it. */
  .folder-cover .sold-badge-corner, .folder-cover .reserved-badge-corner { z-index: 2; }
  .folder-cover-label {
    display: block;
    padding: 10px 12px 2px;
    font-weight: 600;
    font-size: 0.95rem;
  }
  .folder-cover-dates { display:block; padding:0 12px; font-size:0.78rem; color:var(--muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .folder-dates { margin:2px 0 0; color:var(--muted); font-size:0.9rem; }
  .note-box.folder-description { text-align: center; }
  .note-box.folder-description ul, .note-box.folder-description ol { display: inline-block; text-align: left; margin: 4px 0 10px; }
  /* A straight bar drawn inside the paragraph (a border curved whenever the paragraph was the target of a link). */
  .note-box p.accent-note { box-shadow: inset 4px 0 0 var(--bar); padding-left: 14px; border-radius: 0; }
  .note-box p.accent-note:target { border-radius: 0; }
  .folder-cover-count {
    display: block;
    padding: 0 12px 10px;
    color: var(--muted);
    font-size: 0.8rem;
  }
  .folder-permalink-copy {
    position: absolute;
    top: 8px;
    right: 8px;
    background: rgba(0,0,0,0.55);
    border-radius: 50%;
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .group-label {
    margin: 0 0 12px;
    font-size: 0.95rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--text);
  }
  .group-views {
    text-transform: none;
    letter-spacing: normal;
    font-weight: 400;
    font-size: 0.8rem;
    color: var(--muted);
  }
  .group-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 10px;
  }
  /* Any link without a style of its own looks like this (never the browser's blue and purple). */
  a { color:#aab2ff; text-decoration:none; }
  .card {
    background: var(--card);
    border-radius: 10px;
    overflow: hidden;
    aspect-ratio: 1 / 1;
    cursor: pointer;
    position: relative;
    scroll-margin-top: 16px;
  }
  /* Select (the owner's way to act on several photos): tiles are picked with a tap; where the photos sit one by one a picked tile
     can also be held and dragged next to another (Arrange). */
  .select-bar { position: fixed; left: 50%; bottom: calc(12px + env(safe-area-inset-bottom, 0px)); transform: translateX(-50%); width: min(560px, calc(100vw - 24px)); box-sizing: border-box; padding: 10px 12px; background: rgba(30,30,34,0.97); border: 1px solid #3a3a42; border-radius: 14px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); z-index: 90; display: flex; flex-direction: column; gap: 8px; }
  .select-bar[hidden] { display: none; }
  .select-top { display: flex; align-items: center; gap: 4px; }
  .select-count { flex: 1; min-width: 0; font-size: 0.95rem; font-weight: 600; }
  .select-hint { flex: 1 1 100%; font-size: 0.8rem; color: var(--muted); line-height: 1.35; }
  .select-btns, .select-arr { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
  .select-bar button { flex: 0 1 auto; padding: 7px 12px; background: none; border: 1px solid #34343a; border-radius: 999px; color: var(--text); font: inherit; font-size: 0.85rem; cursor: pointer; transition: background .12s, border-color .12s, transform .12s; display: inline-flex; align-items: center; justify-content: center; gap: 6px; }
  .select-btns button { flex: 1 1 0; padding: 10px 12px; font-size: 0.9rem; }
  /* In a private folder the three buttons are the three states of the lock: Private (solid), Public, Folder (dashed). */
  .folder-priv-banner { display: flex; gap: 10px; align-items: flex-start; width: calc(100% - 48px); max-width: 1352px; margin: 8px auto 0; padding: 10px 12px; border-radius: 10px; background: rgba(237,66,69,0.12); border: 1px solid rgba(237,66,69,0.45); font-size: 0.85rem; line-height: 1.4; box-sizing: border-box; }
  .folder-priv-banner > .ico { width: 1.3em; height: 1.3em; color: #ff7a7c; margin-top: 1px; flex: none; }
  .folder-priv-banner { cursor: pointer; transition: background .15s, border-color .15s, transform .12s; -webkit-tap-highlight-color: transparent; }
  .folder-priv-banner:hover { background: rgba(237,66,69,0.2); border-color: rgba(237,66,69,0.75); }
  .folder-priv-banner:active { transform: scale(0.995); background: rgba(237,66,69,0.26); }
  .folder-priv-banner:focus-visible { outline: 2px solid #fff; outline-offset: 2px; }
  .folder-priv-banner .fpb-hint { display: block; margin-top: 2px; font-style: normal; font-weight: 600; color: #aab2ff; }
  .fp-modal { position: fixed; inset: 0; z-index: 300; display: flex; align-items: center; justify-content: center; padding: 20px; background: rgba(0,0,0,0.62); }
  .fp-modal[hidden] { display: none; }
  .fp-modal-card { width: min(420px, 100%); box-sizing: border-box; background: var(--card); border: 1px solid #34343a; border-radius: 14px; padding: 18px; box-shadow: 0 12px 40px rgba(0,0,0,0.6); }
  .fp-modal-card h3 { margin: 0 0 10px; font-size: 1.15rem; }
  .fp-row { display: flex; align-items: center; gap: 9px; margin: 4px 0; font-size: 0.95rem; }
  .fp-row .ico { width: 1.2em; height: 1.2em; flex: none; }
  .fp-stay .ico { color: #ff7a7c; } .fp-open .ico { color: #3ba55c; }
  .fp-hint { margin: 10px 0 0; color: var(--muted); font-size: 0.8rem; line-height: 1.4; }
  .fp-btns { display: flex; gap: 8px; margin-top: 16px; }
  .fp-btns button { flex: 1; padding: 10px 14px; font: inherit; font-size: 0.9rem; border-radius: 8px; border: 1px solid #34343a; background: #26262a; color: var(--text); cursor: pointer; transition: background .12s, transform .12s; }
  .fp-btns button:hover { background: #303036; } .fp-btns button:active { transform: scale(0.97); }
  .fp-btns .primary { background: var(--accent); border-color: var(--accent); color: #fff; } .fp-btns .primary:hover { background: #6b77f0; }
  .folder-priv-banner strong { display: block; }
  .folder-priv-banner span { color: #c9c9ce; }
  .select-bar .sb-p { display: none; }
  .select-bar.in-private .sb-n { display: none; }
  .select-bar.in-private .sb-p { display: inline; }
  .select-bar.in-private .select-btns { flex-wrap: nowrap; }
  .select-bar.in-private .select-btns button { padding: 10px 4px; }
  .select-bar.in-private .sb-private:not(:disabled) { background: #ed4245; border-color: rgba(255,255,255,0.3); color: #fff; }
  .select-bar.in-private .sb-private:hover:not(:disabled) { background: #f4585b; border-color: rgba(255,255,255,0.4); }
  .select-bar.in-private .sb-folder { border: 1.5px dashed rgba(255,120,122,0.95); background: rgba(237,66,69,0.12); color: #ff9a9c; }
  .select-bar .sb-folder[hidden] { display: none; }
  .select-top button { border-color: transparent; color: #aab2ff; padding: 6px 9px; }
  .select-bar button:hover:not(:disabled) { background: #26262a; border-color: #4a4a52; }
  .select-bar button:active:not(:disabled) { transform: scale(0.96); }
  .select-bar button:disabled { opacity: 0.4; cursor: default; }
  .select-bar .primary { background: var(--accent); border-color: var(--accent); color: #fff; }
  .select-bar .select-top .primary { color: #fff; }
  .select-bar .primary:hover:not(:disabled) { background: #6b77f0; border-color: #6b77f0; }
  body.selecting { padding-bottom: 190px; }
  .selecting .card[data-file] { cursor: pointer; -webkit-user-select: none; user-select: none; -webkit-touch-callout: none; }
  .selecting.can-arrange .gallery > .card[data-file] { cursor: grab; }
  .selecting .card[data-file] img { pointer-events: none; -webkit-user-drag: none; }
  .selecting .card .priv-toggle, .selecting .card .cover-toggle, .selecting .card .edit-toggle { display: none; }
  .selecting .card .item-badge { pointer-events: none; }
  .selecting .card:hover img { transform: none; }
  @media (hover: hover) { .selecting .card[data-file]:hover { outline: 2px solid rgba(255,255,255,0.55); outline-offset: -2px; } }
  .selecting .card[data-file].sel { outline: 3px solid var(--accent); outline-offset: -3px; }
  .card .sel-mark { position: absolute; top: 8px; left: 8px; z-index: 5; display: none; align-items: center; justify-content: center; width: 24px; height: 24px; border-radius: 50%; background: var(--accent); color: #fff; box-shadow: 0 0 0 2px #fff; }
  .card .sel-mark .ico { width: 15px; height: 15px; stroke-width: 2.6; }
  .selecting .card.sel .sel-mark { display: flex; }
  .selecting .gallery > .card.dragging { opacity: 0.35; }
  .selecting .gallery > .card.is-stacked::before { content: ''; position: absolute; left: 0; right: 0; bottom: 0; height: 5px; z-index: 3; background: hsl(var(--stack-hue, 220) 70% 62%); pointer-events: none; }
  .arrange-ghost { position: fixed; left: 0; top: 0; width: 88px; height: 88px; z-index: 200; pointer-events: none; border: 2px solid var(--accent); border-radius: 10px; overflow: hidden; box-shadow: 0 8px 24px rgba(0,0,0,0.6); opacity: 0.92; }
  .arrange-ghost img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .arrange-ghost b { position: absolute; right: 4px; top: 4px; padding: 1px 7px; background: var(--accent); border-radius: 999px; color: #fff; font-size: 0.8rem; }
  .arrange-line { position: fixed; z-index: 150; width: 4px; border-radius: 2px; background: var(--accent); box-shadow: 0 0 0 2px rgba(88,101,242,0.35); pointer-events: none; }
  .card.ringed::after {
    content: ''; position: absolute; inset: 0; border-radius: inherit; pointer-events: none;
    box-shadow: inset 0 0 0 3px var(--ring);
  }
  .card img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    transition: transform 0.25s ease;
  }
  .card:hover img {
    transform: scale(1.05);
  }
  <?= video_tile_css() ?>
  .card:hover .tile-video { transform: scale(1.05); }
  .card.sold img, .card.sold .tile-video { filter: grayscale(1) brightness(0.6); }
  .sold-badge-corner, .reserved-badge-corner {
    position: absolute;
    top: 6px;
    left: 6px;
  }
  .card-badges {
    position: absolute;
    bottom: 6px;
    right: 6px;
    display: flex;
    align-items: center;
    gap: 4px;
  }
  <?= ui_icon_css() ?>
  .view-badge {
    background: rgba(0,0,0,0.65);
    color: #eaeaea;
    font-size: 0.68rem;
    padding: 2px 7px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    gap: 3px;
    pointer-events: none;
  }
  .item-badge {
    background: rgba(0,0,0,0.65);
    color: #eaeaea;
    font-size: 0.7rem;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    line-height: 1;
  }
  a.item-badge { pointer-events: auto; }
  .item-badge.caption-badge { pointer-events: auto; cursor: pointer; }
  /* The same answer as the owner's lock and star: over a caption, location or link badge it lights up (a ring, the accent colour, a
     little larger), and pressing it sinks it, so it is clear it is a button. */
  .item-badge { transition: background .15s, transform .12s, box-shadow .12s; cursor: pointer; }
  .item-badge:active { transform: scale(0.9); }
  @media (hover: hover) {
    .item-badge:hover { transform: scale(1.2); box-shadow: 0 0 0 2px #fff; background: var(--accent); color: #fff; }
    .item-badge:hover:active { transform: scale(1.04); }
  }
  .caption-popover {
    position: absolute; left: 8px; right: 8px; bottom: 34px; z-index: 3;
    background: rgba(0,0,0,0.88); color: #fff; padding: 8px 10px;
    border-radius: 8px; font-size: 0.85rem; line-height: 1.35;
    pointer-events: none; overflow-wrap: anywhere;
  }
  /* The owner's lock on a tile: red and labelled when private; a faint open lock when public (on a desktop only on hover). */
  .priv-toggle { position: absolute; top: 6px; left: 6px; z-index: 4; display: inline-flex; align-items: center; gap: 5px; padding: 5px 7px; border-radius: 999px; border: 1px solid rgba(255,255,255,0.2); background: rgba(0,0,0,0.55); color: #fff; font: inherit; font-size: 0.72rem; line-height: 1; cursor: pointer; opacity: 0.6; transition: opacity .15s, background .15s; }
  .priv-toggle .ico { width: 1em; height: 1em; }
  .priv-toggle.is-private { background: rgba(237,66,69,0.88); border-color: rgba(255,255,255,0.35); opacity: 1; }
  /* Private only because its folder is: dashed, not solid (it becomes public when the folder opens). */
  .priv-toggle.is-folder { background: rgba(0,0,0,0.55); border: 1.5px dashed rgba(255,120,122,0.95); color: #ff9a9c; }
  .priv-toggle.busy { opacity: 0.35; pointer-events: none; }
  @media (hover: hover) { .priv-toggle:not(.is-private) { opacity: 0; } .card:hover .priv-toggle:not(.is-private), .priv-toggle:focus-visible { opacity: 0.9; } }
  .cover-toggle { position: absolute; top: 6px; right: 6px; z-index: 4; display: inline-flex; align-items: center; justify-content: center; padding: 5px 6px; border-radius: 999px; border: 1px solid rgba(255,255,255,0.2); background: rgba(0,0,0,0.55); color: #fff; font: inherit; line-height: 1; cursor: pointer; opacity: 0.6; transition: opacity .15s, background .15s; }
  .cover-toggle .ico { width: 1em; height: 1em; font-size: 0.82rem; }
  .cover-toggle.is-cover { background: #f5c542; border-color: rgba(255,255,255,0.35); color: #1a1a1c; opacity: 1; }
  .cover-toggle.is-cover .ico { fill: currentColor; }
  .cover-toggle.busy { opacity: 0.35; pointer-events: none; }
  @media (hover: hover) { .cover-toggle:not(.is-cover) { opacity: 0; } .card:hover .cover-toggle:not(.is-cover), .cover-toggle:focus-visible { opacity: 0.9; } }
  /* The pencil, in the lower left corner (opposite the caption, place and link badges it changes): opens the editor in the viewer. */
  .edit-toggle { position: absolute; bottom: 6px; left: 6px; z-index: 4; display: inline-flex; align-items: center; justify-content: center; padding: 5px 6px; border-radius: 999px; border: 1px solid rgba(255,255,255,0.2); background: rgba(0,0,0,0.55); color: #fff; font: inherit; line-height: 1; cursor: pointer; opacity: 0.75; }
  .edit-toggle .ico { width: 1em; height: 1em; font-size: 0.82rem; }
  .edit-toggle.busy { opacity: 0.35; pointer-events: none; }
  @media (hover: hover) { .edit-toggle { opacity: 0; } .card:hover .edit-toggle, .edit-toggle:focus-visible { opacity: 0.9; } }
  .owner-card .video-duration { left: 38px; }
  .priv-toggle::after, .cover-toggle::after, .edit-toggle::after { content: ''; position: absolute; inset: -6px; }   /* a bigger target for a finger */
  /* Over one of these two, the button itself answers: full strength, a white ring, a little larger, and a colour of its own
     (the accent for a plain one, a lighter red or gold for one that is already lit); pressed, it sinks back. */
  .priv-toggle, .cover-toggle, .edit-toggle { transition: opacity .15s, background .15s, transform .12s, box-shadow .12s; }
  .priv-toggle:active, .cover-toggle:active, .edit-toggle:active { transform: scale(0.92); }
  @media (hover: hover) {
    .priv-toggle:hover, .cover-toggle:hover, .edit-toggle:hover { opacity: 1 !important; transform: scale(1.14); box-shadow: 0 0 0 2px #fff; background: var(--accent); border-color: #fff; }
    .priv-toggle.is-private:hover { background: #f4585b; }
    .priv-toggle.is-folder:hover { background: rgba(237,66,69,0.38); border-color: #ff9a9c; color: #fff; }
    .cover-toggle.is-cover:hover { background: #ffd86b; }
    .priv-toggle:hover:active, .cover-toggle:hover:active, .edit-toggle:hover:active { transform: scale(1.02); }
  }
  .lightbox .caption-row .lightbox-copy.owner-cover.on { color: #f5c542; opacity: 1; }
  .lightbox .caption-row .lightbox-copy.owner-cover.on .ico { fill: currentColor; }
  .lightbox .caption-row .lightbox-copy.owner-priv.on { color: #ed4245; opacity: 1; }
  .lightbox .caption-row .lightbox-copy.owner-priv.on.folder { color: #ff9a9c; position: relative; }
  .lightbox .caption-row .lightbox-copy.owner-priv.on.folder::after { content: ''; position: absolute; left: 50%; top: 50%; width: 30px; height: 30px; transform: translate(-50%, -50%); border: 1.5px dashed rgba(255,120,122,0.9); border-radius: 50%; pointer-events: none; }
  .card.owner-card .sold-badge-corner, .card.owner-card .reserved-badge-corner { top: 40px; }
  .video-duration {
    position: absolute;
    bottom: 6px;
    left: 6px;
    background: rgba(0,0,0,0.65);
    color: #eaeaea;
    font-size: 0.68rem;
    padding: 2px 7px;
    border-radius: 10px;
    pointer-events: none;
  }
  .empty {
    text-align: center;
    color: var(--muted);
    padding: 60px 20px;
  }
  .page-tools { margin-left: auto; display: inline-flex; align-items: center; gap: 8px; color: var(--muted); font-size: 0.85rem; }
  /* Same column as the photo grid below it (max-width and side padding
     match .gallery), so the map lines up with the tiles instead of
     running to the edges of the window. */
  .map-preview { max-width: 1400px; margin: 8px auto 0; padding: 0 24px; }
  /* The strip of buttons stays at the top of the screen while the photos scroll under it. */
  .map-bar { position: sticky; top: 0; z-index: 40; margin-top: 0; padding-top: 8px; padding-bottom: 10px; background: var(--bg); transition: box-shadow .15s; }
  .map-bar.stuck { box-shadow: 0 1px 0 #26262a, 0 10px 16px -10px rgba(0,0,0,0.9); }
  html { scroll-padding-top: 64px; }   /* a link that scrolls to a photo or group stops below the strip, not under it */
  .map-preview-heading {
    font-size: 1rem; margin: 0; font-weight: 400;
    display: flex; align-items: center; justify-content: flex-start; flex-wrap: wrap; gap: 8px;
  }
  .map-modal { position: fixed; inset: 0; z-index: 90; background: rgba(0,0,0,0.78); display: flex; align-items: center; justify-content: center; padding: 20px; }
  .map-modal[hidden] { display: none; }
  .map-modal-card { position: relative; width: min(100%, 1100px); background: var(--card); border-radius: 12px; padding: 12px; box-shadow: 0 12px 48px rgba(0,0,0,0.6); max-height: 100%; overflow: auto; }
  .map-modal-close { position: absolute; top: 10px; right: 10px; z-index: 5; width: 34px; height: 34px; padding: 0; border-radius: 50%; border: none; background: rgba(0,0,0,0.7); color: #fff; font-size: 1.4rem; line-height: 1; cursor: pointer; }
  .map-legend { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 10px; font-size: 0.8rem; color: var(--muted); }
  .map-legend-item { display: flex; align-items: center; gap: 6px; }
  .map-legend-swatch { width: 11px; height: 11px; border-radius: 50%; display: inline-block; }
  .leaflet-popup-content-wrapper { background: var(--card); color: var(--text); }
  .leaflet-popup-tip { background: var(--card); }
  /* Lightbox */
  .lightbox {
    display: none;
    position: fixed;
    inset: 0;
    height: 100vh; height: 100dvh;   /* dvh: what is really visible, so the phone browser's own bars never cover the caption and buttons */
    background: rgba(0,0,0,0.92);
    z-index: 100;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    touch-action: none;
  }
  .lightbox.open { display: flex; }
  .lightbox img, .lightbox video {
    object-fit: contain;   /* whatever size the box ends up, the picture inside keeps its own shape (Safari clamps one side of an image in a flex box without the other) */
    max-width: 90vw;
    max-height: 82vh;
    max-height: min(82vh, calc(100dvh - 130px - env(safe-area-inset-bottom, 0px)));   /* a tall photo leaves room for its caption row */
    border-radius: 6px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.5);
  }
  .caption-row {
    margin-top: 14px;
    display: flex;
    align-items: center;
    gap: 10px;
  }
  .lightbox .caption {
    color: var(--muted);
    font-size: 0.85rem;
  }
  .lightbox-copy {
    background: none;
    border: none;
    color: var(--muted);
    font-size: 0.9rem;
    cursor: pointer;
    padding: 8px;
    line-height: 1;
    opacity: 0.75;
  }
  .lightbox-copy:hover { opacity: 1; }
  /* Zoom: the photo is shown to fit the screen; one press fills the width of the window (scroll up and down, never
     distorted, from the original file), another shows it at its real pixel size (scroll both ways). */
  .lightbox img { cursor: zoom-in; }
  .lightbox.zoom-width, .lightbox.zoom-actual { overflow: auto; justify-content: flex-start; color-scheme: dark; }
  .lightbox.zoom-width { touch-action: pan-y; }
  .lightbox.zoom-actual { touch-action: pan-x pan-y; }
  .lightbox.zoom-width img, .lightbox.zoom-actual img {
    flex: none; max-height: none; height: auto; margin: auto; border-radius: 0; box-shadow: none;
  }
  .lightbox.zoom-width img { width: 100%; max-width: var(--nat, 100%); }
  .lightbox.zoom-actual img { width: var(--act, auto); max-width: none; cursor: zoom-out; }
  .lightbox.zoom-width .caption-row, .lightbox.zoom-actual .caption-row {
    position: fixed; left: 50%; right: auto; bottom: calc(14px + env(safe-area-inset-bottom, 0px)); transform: translateX(-50%); margin: 0;
    width: max-content; max-width: calc(100vw - 40px); padding: 3px 14px; justify-content: center; z-index: 6;
    border-radius: 999px; background: rgba(0,0,0,0.4); -webkit-backdrop-filter: blur(2px); backdrop-filter: blur(2px);   /* a soft dark pill: the controls stay readable and usable over the photo */
  }
  .lightbox.zoom-width .caption-row .caption, .lightbox.zoom-actual .caption-row .caption { color: #e4e4e8; }
  /* (not the ones in a state of their own: the map button when it is on, the pulsing pin, "copied", "ready": those keep their colour) */
  .lightbox.zoom-width .caption-row .lightbox-copy:not(.on):not(.nudge):not(.copied):not(.ready),
  .lightbox.zoom-actual .caption-row .lightbox-copy:not(.on):not(.nudge):not(.copied):not(.ready) { color: #e4e4e8; opacity: 0.95; }
  /* Zoomed in, the photo runs under the other controls too: the same soft backing for the close button and the arrows. */
  .lightbox.zoom-width .close, .lightbox.zoom-actual .close, .lightbox.zoom-width .nav, .lightbox.zoom-actual .nav {
    background: rgba(0,0,0,0.4); -webkit-backdrop-filter: blur(2px); backdrop-filter: blur(2px); opacity: 0.9;
  }
  .lightbox.is-video .lightbox-zoom { display: none; }
  .lightbox-close-row { display: none; }
  .lightbox-tools-wrap, .lightbox-tools { display: contents; }   /* (on a phone they become a strip you can swipe: see below) */
  .tools-more { display: none; }
  .caption .cap-sep { white-space: pre; }
  /* On a touch screen the counter and buttons, the close button last among them, are a row across the TOP, and the photo takes
     everything below it down to the bottom of the visible area (a tall photo on a phone is height-limited, so every point
     counts). Nothing to tap near the bottom edge, where Safari answers a tap by bringing its toolbar back (which costs a
     second tap), and nothing laid over the photo. The viewer is sized to what is really visible (dvh), so Safari's bars never
     cover the photo. In the zoomed modes the same row stays put while the photo scrolls under it. */
  @media (pointer: coarse) {
    .lightbox { padding: calc(env(safe-area-inset-top, 0px) + 54px) 0 calc(env(safe-area-inset-bottom, 0px) + 8px); background: #000; }   /* the page behind does not show through */
    .lightbox img, .lightbox video { max-height: calc(100dvh - 62px - env(safe-area-inset-top, 0px) - env(safe-area-inset-bottom, 0px)); }
    .lightbox .caption-row, .lightbox.zoom-width .caption-row, .lightbox.zoom-actual .caption-row {
      position: fixed; top: 0; left: 0; right: 0; bottom: auto; transform: none; margin: 0; z-index: 6; width: auto; max-width: none; border-radius: 0; -webkit-backdrop-filter: none; backdrop-filter: none;
      padding: calc(env(safe-area-inset-top, 0px) + 6px) 5px 4px 16px; justify-content: flex-start; align-items: center; gap: 0;
      background: linear-gradient(rgba(0,0,0,0.7), transparent);
    }
    .lightbox .caption-row { pointer-events: none; }
    /* The counter always shows (first, never cut off), the caption after it gives way first, then the buttons become a strip you can
       swipe sideways; the close button stays put at the right end. */
    .lightbox .caption-row .caption { flex: 1 1 0; min-width: 9ch; display: flex; flex-direction: row-reverse; justify-content: flex-end; white-space: nowrap; }
    .lightbox .caption-row .caption .cap-n { flex: none; }
    .lightbox .caption-row .caption .cap-sep { display: none; }
    .lightbox .caption-row .caption .cap-t { min-width: 0; overflow: hidden; text-overflow: ellipsis; margin-left: 8px; }
    .lightbox .caption-row .caption .cap-t:empty { display: none; }
    .lightbox-tools-wrap { position: relative; display: flex; flex: 0 1 auto; min-width: 0; }
    /* The strip itself is masked, so nothing of a scrolled-off icon is painted within 18px of an end that has an arrow (laying a dark
       backing over it instead left a hairline of the icon showing on Safari). */
    .lightbox-tools { --ml: -10px; --mr: -10px; display: flex; align-items: center; flex: 0 1 auto; min-width: 0; overflow-x: auto; overflow-y: hidden; touch-action: pan-x; pointer-events: auto; scrollbar-width: none; -webkit-overflow-scrolling: touch;
      -webkit-mask-image: linear-gradient(to right, transparent var(--ml), #000 calc(var(--ml) + 10px), #000 calc(100% - var(--mr) - 10px), transparent calc(100% - var(--mr)));
      mask-image: linear-gradient(to right, transparent var(--ml), #000 calc(var(--ml) + 10px), #000 calc(100% - var(--mr) - 10px), transparent calc(100% - var(--mr))); }
    .lightbox-tools-wrap.can-left .lightbox-tools { --ml: 18px; }
    .lightbox-tools-wrap.can-right .lightbox-tools { --mr: 18px; }
    .lightbox-tools::-webkit-scrollbar { display: none; }
    .lightbox-tools > * { flex: none; }
    /* A small arrow at whichever end has more to swipe to, in the clear zone the mask leaves there. */
    .tools-more { position: absolute; top: 0; bottom: 0; width: 18px; align-items: center; pointer-events: none; color: #e4e4e8; }
    .tools-more .ico { width: 16px; height: 16px; stroke-width: 2.4; }
    .tools-more-l { left: 0; justify-content: center; }
    .tools-more-r { right: 0; justify-content: center; }
    .lightbox-tools-wrap.can-left .tools-more-l, .lightbox-tools-wrap.can-right .tools-more-r { display: flex; }
    .lightbox .caption-row .lightbox-close-row { flex: none; }
    /* Every button of the row is the same box (44 points high) with the same drawn icon size, so the close button, which is
       the last of them, cannot differ from its neighbours in size or in where it sits. */
    .lightbox .caption-row .lightbox-copy { display: inline-flex; align-items: center; justify-content: center; height: 44px; padding: 0 9px; font-size: 0.95rem; pointer-events: auto; }
    .lightbox .caption-row .lightbox-copy .ico { width: 18px; height: 18px; vertical-align: 0; }
    .lightbox .caption-row .lightbox-copy[hidden] { display: none; }   /* the row's own display must not show a button that has nothing to do (no web link, no location) */
    .lightbox .caption-row .lightbox-close-row { display: inline-flex; }
    .lightbox .caption-row .lightbox-close-row .ico { transform: translateY(0.67px); }   /* two device pixels on a 3x phone, to sit level with its neighbours by eye */
    .lightbox button.close { display: none; }
  }
  .lightbox-web, .lightbox-download { text-decoration: none; font-size: 1rem; }
  .lightbox-copy.busy { opacity: 0.35; pointer-events: none; }
  .lightbox-copy.ready { color: var(--accent); opacity: 1; }
  .lightbox-toast { position: fixed; left: 50%; bottom: calc(76px + env(safe-area-inset-bottom, 0px)); transform: translateX(-50%); max-width: calc(100vw - 40px); padding: 8px 14px; background: rgba(30,30,34,0.96); color: var(--text); border: 1px solid #3a3a42; border-radius: 999px; font-size: 0.85rem; text-align: center; z-index: 110; pointer-events: none; }
  .lightbox-toast[hidden] { display: none; }
  /* The map button reads like the other caption icons when the map is off, and in the accent colour when it is on. */
  .lightbox-map-toggle.on { opacity: 1; color: var(--accent); }
  .lightbox-map-toggle.nudge { opacity: 1; color: var(--accent); animation: map-nudge 0.6s ease-in-out 2; }
  @keyframes map-nudge { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.5); } }
  @media (prefers-reduced-motion: reduce) { .lightbox-map-toggle.nudge { animation: none; } }
  /* The little map in the viewer: over the photo's top left corner; click to enlarge. */
  .lightbox-map {
    position: fixed; z-index: 5; width: 170px; height: 130px;
    border-radius: 8px; overflow: hidden; border: 2px solid rgba(255,255,255,0.85);
    box-shadow: 0 4px 18px rgba(0,0,0,0.55); cursor: zoom-in; background: #1a1a1c;
  }
  .lightbox-map[hidden] { display: none; }
  .lightbox-map.expanded {
    left: 50% !important; top: 50% !important; transform: translate(-50%, -50%);
    width: min(88vw, 900px); height: min(70vh, 640px); height: min(70dvh, 640px); cursor: auto;
  }
  .lightbox-map img { max-width: none !important; max-height: none !important; border-radius: 0; box-shadow: none; }
  .lbmap-close, .lbmap-osm { display: none; position: absolute; z-index: 1000; }
  .lightbox-map.expanded .lbmap-close, .lightbox-map.expanded .lbmap-osm { display: block; }
  .lbmap-close {
    top: 8px; right: 8px; width: 34px; height: 34px; padding: 0; border-radius: 50%;
    background: rgba(0,0,0,0.7); color: #fff; font-size: 1.4rem; line-height: 1; border: none; cursor: pointer;
  }
  .leaflet-container a.lbmap-osm {
    left: 8px; bottom: 22px; background: rgba(0,0,0,0.7); color: #fff; padding: 5px 10px;
    border-radius: 6px; font-size: 0.78rem; text-decoration: none;
  }
  /* Leaflet's panes use large z-indexes; keep them inside their own box so no map can land on top of the viewer. */
  .leaflet-container { isolation: isolate; z-index: 0; }
  .lightbox-map.leaflet-container { z-index: 5; }
  /* Two icon buttons joined into one control: show/hide the map, and open it large. */
  .map-combo { display: inline-flex; border: 1px solid #2c2c30; border-radius: 8px; overflow: hidden; background: var(--card); }
  .map-combo-btn { position: relative; display: inline-flex; align-items: center; justify-content: center; width: 42px; height: 34px; padding: 0; background: none; border: 0; color: var(--text); font-size: 1.05rem; cursor: pointer; text-decoration: none; }
  .map-combo-btn + .map-combo-btn { border-left: 1px solid #2c2c30; }
  .map-combo-btn:hover { background: #26262a; }
  .map-combo-btn[aria-expanded="true"], .map-combo-btn[aria-pressed="true"] { background: var(--accent); color: #fff; }
  .map-preview-heading { position: relative; }
  .sort-pop { position: absolute; top: 40px; left: 0; z-index: 30; min-width: 230px; max-width: calc(100vw - 32px); padding: 10px; background: var(--card); border: 1px solid #2c2c30; border-radius: 10px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); font-size: 0.85rem; }
  .sort-pop[hidden] { display: none; }
  .sort-pop-title { color: var(--muted); font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.06em; margin: 0 0 6px; }
  .sort-pop-row { display: flex; flex-wrap: wrap; gap: 6px; margin: 0 0 8px; }
  .sort-pop-row button { padding: 6px 12px; background: none; border: 1px solid #34343a; border-radius: 999px; color: var(--muted); font: inherit; cursor: pointer; }
  .sort-pop-row button:hover { color: var(--text); border-color: #4a4a52; }
  .sort-pop-row button[aria-pressed="true"] { background: var(--accent); border-color: var(--accent); color: #fff; }
  .sort-reset { background: none; border: 0; padding: 2px 0; color: var(--muted); font: inherit; font-size: 0.8rem; text-decoration: none; border-radius: 3px; cursor: pointer; }
  .sort-reset:hover { color: var(--text); }
  /* On a phone the buttons keep their places (map and sort at the left, download and about at the right) and never wrap:
     they get a little narrower if they have to. */
  @media (max-width: 600px) {
    .map-preview-heading { gap: 4px; flex-wrap: nowrap; }
    .map-combo { flex: 0 1 auto; min-width: 0; }
    .map-combo-btn { width: 36px; flex: 0 1 36px; min-width: 28px; }
    .page-tools { gap: 4px; min-width: 0; flex: 0 1 auto; }
  }
  .map-toggle-label { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
  .map-preview-body[hidden] { display: none; }
  .map-preview-body { position: relative; }
  /* Copy-link icon in the map's top right corner, built like Leaflet's own zoom buttons (top left) so the two look like a pair. */
  .map-corner-link { position: absolute; top: 10px; right: 10px; z-index: 1000; width: 34px; height: 34px; padding: 0; display: flex; align-items: center; justify-content: center; background: #fff; color: #333; border: 2px solid rgba(0,0,0,0.2); border-radius: 4px; background-clip: padding-box; cursor: pointer; }
  .map-corner-link .ico { width: 18px; height: 18px; }
  @media (hover: hover) { .map-corner-link:hover { background: #f4f4f4; } }
  .map-corner-link.copied { color: #3ba55c; }
  /* On the full-screen map the same icon sits in the bottom left corner (the card has 12px padding; the map is as tall as the call that draws it says). */
  .map-modal-card .map-corner-link { top: calc(12px + min(72vh, 720px) - 44px); right: auto; left: 22px; }
  .map-preview-body.peek-hit { outline: 2px solid var(--accent); outline-offset: 3px; border-radius: 10px; animation: item-flash 2s ease-out; }   /* the same mark a photo gets when a link snaps to it */
  .lightbox-copy.copied { color: #3ba55c; opacity: 1; }
  .lightbox .close,
  .lightbox .nav {
    position: fixed;
    background: none;
    border: none;
    color: var(--text);
    font-size: 2rem;
    cursor: pointer;
    padding: 10px 16px;
    opacity: 0.7;
    transition: opacity 0.15s;
  }
  .lightbox .close:hover,
  .lightbox .nav:hover { opacity: 1; }
  /* The close button and the arrows are drawn icons in identical square boxes, centred by the box (not by a text glyph's metrics). */
  .lightbox .close, .lightbox .nav { display: flex; align-items: center; justify-content: center; width: 52px; height: 52px; padding: 0; line-height: 1; border-radius: 50%; }
  .lightbox .nav .ico { width: 1em; height: 1em; vertical-align: 0; stroke-width: 2; }
  .lightbox .close { top: 10px; top: calc(10px + env(safe-area-inset-top, 0px)); right: 14px; font-size: 2.2rem; }
  .lightbox .close .ico { width: 0.85em; height: 0.85em; vertical-align: 0; stroke-width: 1.6; }
  /* Hovering a control (where there is a mouse) lights its own round or rounded box, so you can see which one you are over, in the
     plain view and on the dark pill over a zoomed photo alike. */
  .lightbox .caption-row .lightbox-copy, .lightbox .close, .lightbox .nav { transition: background .12s, opacity .12s, color .12s; }
  .lightbox .caption-row .lightbox-copy { border-radius: 8px; }
  @media (hover: hover) {
    .lightbox .caption-row .lightbox-copy:hover, .lightbox .close:hover, .lightbox .nav:hover,
    .lightbox.zoom-width .caption-row .lightbox-copy:hover, .lightbox.zoom-actual .caption-row .lightbox-copy:hover,
    .lightbox.zoom-width .close:hover, .lightbox.zoom-actual .close:hover, .lightbox.zoom-width .nav:hover, .lightbox.zoom-actual .nav:hover {
      background: rgba(255,255,255,0.2); opacity: 1;
    }
    .lightbox .caption-row .lightbox-copy:not(.on):not(.nudge):not(.copied):not(.ready):hover, .lightbox .close:hover, .lightbox .nav:hover { color: #fff; }
  }
  /* The owner's editor in the viewer: a sheet under the photo on a phone, a panel beside it on a wide screen. */
  .lightbox .caption-row .lightbox-copy.owner-edit.on { color: #aab2ff; opacity: 1; }
  .lb-editor { position: fixed; z-index: 9; box-sizing: border-box; display: flex; flex-direction: column; background: #1a1a1c; color: var(--text); touch-action: pan-y; font-size: 0.95rem; }
  .lb-editor[hidden] { display: none; }
  .lightbox.lb-sheet .lb-editor { left: 0; right: 0; bottom: 0; max-height: calc(var(--lbh, 100dvh) - 150px); border-radius: 18px 18px 0 0; box-shadow: 0 -6px 24px rgba(0,0,0,0.5); padding-bottom: env(safe-area-inset-bottom, 0px); }
  .lightbox.lb-side .lb-editor { top: 0; right: 0; bottom: 0; width: 360px; border-left: 1px solid #2c2c30; }
  @media (pointer: coarse) { .lightbox.lb-side .lb-editor { top: calc(env(safe-area-inset-top, 0px) + 54px); } }
  .lbe-grip { display: none; align-items: center; justify-content: center; height: 24px; flex: none; touch-action: none; cursor: grab; }
  .lightbox.lb-sheet .lbe-grip { display: flex; }
  .lbe-handle { width: 38px; height: 4px; border-radius: 2px; background: #4a4a50; }
  .lightbox.lb-sheet .lbe-head { touch-action: none; }
  .lbe-head { display: flex; align-items: center; flex-wrap: wrap; gap: 4px 10px; padding: 10px 16px 4px; min-height: 40px; flex: none; }
  .lbe-name { flex: 1 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 600; }
  .lbe-status { font-size: 0.8rem; color: var(--muted); display: inline-flex; align-items: center; gap: 4px; }
  .lbe-status.ok { color: #3ba55c; }
  .lbe-status.err { color: #ed4245; flex-basis: 100%; }
  .lbe-hidekb { display: none; align-items: center; gap: 5px; height: 34px; padding: 0 12px; border-radius: 999px; border: 1px solid #34343a; background: #26262a; color: var(--text); font: inherit; font-size: 0.8rem; font-weight: 600; cursor: pointer; }
  .lb-editor.kb .lbe-hidekb { display: inline-flex; }
  .lbe-body { flex: 1 1 auto; min-height: 0; overflow-y: auto; -webkit-overflow-scrolling: touch; padding: 6px 16px 10px; display: flex; flex-direction: column; gap: 12px; }
  .lbe-field { display: block; }
  .lbe-field > span { display: block; font-size: 0.75rem; color: var(--muted); margin-bottom: 5px; }
  .lbe-field input { width: 100%; height: 46px; box-sizing: border-box; padding: 0 13px; border-radius: 10px; border: 1px solid #34343a; background: #0f0f10; color: var(--text); font: inherit; font-size: 16px; }
  .lbe-field input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(88,101,242,0.28); }
  .lbe-field input.bad { border-color: #ed4245; box-shadow: 0 0 0 3px rgba(237,66,69,0.25); }
  .lbe-field input:disabled { opacity: 0.5; }
  @media (hover: hover) and (pointer: fine) { .lbe-field input { height: 42px; font-size: 14.5px; } }
  .lbe-err { display: block; min-height: 0; margin-top: 4px; font-style: normal; font-size: 0.78rem; color: #ed4245; }
  .lbe-err:empty { display: none; }
  .lbe-pills { display: flex; gap: 8px; }
  .lbe-pill { flex: 1 1 0; min-width: 0; height: 44px; display: inline-flex; align-items: center; justify-content: center; gap: 7px; border-radius: 999px; border: 1px solid #34343a; background: #0f0f10; color: var(--text); font: inherit; font-size: 0.9rem; cursor: pointer; transition: background .12s, border-color .12s, transform .12s; }
  .lbe-pill .ico { width: 1.1em; height: 1.1em; vertical-align: 0; }
  .lbe-pill[aria-pressed="true"] { background: var(--accent); border-color: var(--accent); color: #fff; }
  #lbe-private[aria-pressed="true"] { background: #ed4245; border-color: rgba(255,255,255,0.3); }
  #lbe-private.is-folder, #lbe-private.is-folder[aria-pressed="false"] { background: rgba(237,66,69,0.12); border: 1.5px dashed rgba(255,120,122,0.95); color: #ff9a9c; }
  #lbe-private[aria-pressed="true"] { background: #ed4245; border-color: #ed4245; }
  #lbe-cover[aria-pressed="true"] { background: #f5c542; border-color: #f5c542; color: #1a1a1c; }
  #lbe-cover[aria-pressed="true"] .ico { fill: currentColor; }
  .lbe-pill:active:not(:disabled) { transform: scale(0.97); }
  .lbe-pill:disabled { opacity: 0.5; cursor: default; }
  @media (hover: hover) { .lbe-pill:hover:not(:disabled) { border-color: #6b77f0; } .lbe-pill[aria-pressed="true"]:hover:not(:disabled) { filter: brightness(1.12); } }
  .lbe-note { margin: -4px 0 0; font-size: 0.78rem; color: var(--muted); line-height: 1.35; }
  .lbe-note[hidden] { display: none; }
  .lbe-locrow { display: flex; align-items: center; gap: 10px; width: 100%; min-height: 52px; padding: 0 13px; border-radius: 10px; border: 1px solid #34343a; background: #0f0f10; color: var(--text); font: inherit; text-align: left; cursor: pointer; }
  .lbe-locrow .ico { flex: none; color: #aab2ff; }
  .lbe-locrow #lbe-locsum { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 0.9rem; }
  .lbe-locrow .lbe-chev { color: var(--muted); transition: transform .15s; }
  .lb-editor.loc-open .lbe-chev { transform: rotate(180deg); }
  @media (hover: hover) { .lbe-locrow:hover { border-color: #4a4a52; background: #16161a; } }
  .lbe-locfields { display: none; margin-top: 10px; flex-direction: column; gap: 10px; }
  .lb-editor.loc-open .lbe-locfields { display: flex; }
  .lbe-two { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
  .lbe-auto { align-self: flex-start; padding: 8px 14px; border-radius: 999px; border: 1px solid var(--accent); background: none; color: #aab2ff; font: inherit; font-size: 0.85rem; cursor: pointer; }
  .lbe-auto[hidden] { display: none; }
  .lb-editor.typing .lbe-pills, .lb-editor.typing .lbe-note, .lb-editor.typing .lbe-loc { display: none; }
  .lbe-foot { display: grid; grid-template-columns: 1fr 1.3fr 1fr; gap: 8px; padding: 8px 16px 14px; flex: none; }
  /* On a phone the sheet is kept low, so the photo above it has room. */
  .lightbox.lb-sheet .lbe-head { padding-top: 0; min-height: 32px; }
  .lightbox.lb-sheet .lbe-body { gap: 9px; padding-top: 2px; padding-bottom: 8px; }
  .lightbox.lb-sheet .lbe-field > span { margin-bottom: 3px; }
  .lightbox.lb-sheet .lbe-field input { height: 42px; }
  .lightbox.lb-sheet .lbe-pill { height: 40px; }
  .lightbox.lb-sheet .lbe-locrow { min-height: 44px; }
  .lightbox.lb-sheet .lbe-foot { padding: 6px 16px 10px; }
  .lightbox.lb-sheet .lbe-btn { height: 42px; }
  .lbe-btn { height: 46px; display: inline-flex; align-items: center; justify-content: center; gap: 6px; border-radius: 10px; border: 1px solid #34343a; background: #26262a; color: var(--text); font: inherit; font-size: 0.95rem; font-weight: 600; cursor: pointer; transition: background .12s, transform .12s; }
  .lbe-btn .ico { width: 1.1em; height: 1.1em; vertical-align: 0; }
  .lbe-btn.primary { background: var(--accent); border-color: var(--accent); color: #fff; }
  .lbe-btn:active { transform: scale(0.97); }
  @media (hover: hover) { .lbe-btn:hover { background: #303036; } .lbe-btn.primary:hover { background: #6b77f0; } }
  /* The photo takes what the editor leaves. */
  .lightbox.editing .lightbox-zoom { display: none; }
  .lightbox.editing img { cursor: default; }
  .lightbox.editing.lb-side { padding-right: 360px; }
  .lightbox.editing.lb-side img, .lightbox.editing.lb-side video { max-width: calc(100vw - 400px); }
  .lightbox.editing.lb-side .next { right: 366px; }
  .lightbox.editing.lb-side .close { right: 374px; }
  .lightbox.editing.lb-sheet { padding-bottom: var(--sheet-h, 0px); }
  .lightbox.editing.lb-sheet img, .lightbox.editing.lb-sheet video { max-height: calc(var(--lbh, 100dvh) - var(--sheet-h, 0px) - 90px); }
  @media (pointer: coarse) { .lightbox.editing.lb-sheet img, .lightbox.editing.lb-sheet video { max-height: calc(var(--lbh, 100dvh) - var(--sheet-h, 0px) - 62px - env(safe-area-inset-top, 0px)); } }
  .lightbox.editing.lb-sheet .prev, .lightbox.editing.lb-sheet .next { top: calc((var(--lbh, 100dvh) - var(--sheet-h, 0px)) / 2); }   /* the arrows sit level with the photo, not with the whole screen */
  .lightbox .prev { left: 6px; top: 50%; transform: translateY(-50%); font-size: 3rem; }
  .lightbox .next { right: 6px; top: 50%; transform: translateY(-50%); font-size: 3rem; }
  @media (max-width: 600px) {
    .lightbox .prev, .lightbox .next { font-size: 2.2rem; }
    .lightbox .nav { width: 44px; height: 44px; }
  }
</style>
</head>
<body>
<?= ui_icon_sprite() ?>

<?php
// The lock in the icon strip, right beside the archive icon: the one way in. Shown when there is an admin password, or a private-view password and
// something private to unlock. Closed: a link to the sign-in (the private-view password shows the private items, the
// admin password opens the admin). Open: this browser is signed in (with either); pressing it signs out and locks the
// private items again.
$lockHtml = ''; $lockForm = '';
if (ADMIN_PASSWORD !== '' || (PRIVATE_VIEW_PASSWORD !== '' && $hasPrivateItems)) {
    $lockFolderQuery = $currentFolderPath !== null ? '&amp;folder=' . htmlspecialchars(rawurlencode($currentFolderPath), ENT_QUOTES, 'UTF-8') : '';
    // The open photo and the open map are part of "the page you are on" too: signing in or out brings you back to them.
    $lockPhoto = isset($_GET['photo']) && is_string($_GET['photo']) && $_GET['photo'] !== '' && strlen($_GET['photo']) < 1024 ? $_GET['photo'] : '';
    $lockHere = $lockFolderQuery . ($lockPhoto !== '' ? '&amp;photo=' . htmlspecialchars(rawurlencode($lockPhoto), ENT_QUOTES, 'UTF-8') : '') . (isset($_GET['map']) ? '&amp;map' : '');
    $lockHidden = '<input type="hidden" name="folder" value="' . htmlspecialchars((string) $currentFolderPath, ENT_QUOTES, 'UTF-8') . '">'
        . ($lockPhoto !== '' ? '<input type="hidden" name="photo" value="' . htmlspecialchars($lockPhoto, ENT_QUOTES, 'UTF-8') . '">' : '')
        . (isset($_GET['map']) ? '<input type="hidden" name="map" value="1">' : '');
    if (is_admin_browser() || is_revealed()) {
        $lockWho = is_admin_browser() ? 'You are signed in as the owner, so private items show. Press to sign out and lock them.' : 'Private items are showing. Press to lock them again.';
        $lockHtml = '<button type="submit" form="lock-form" name="logout" value="1" class="map-combo-btn lock-link open" title="' . htmlspecialchars($lockWho, ENT_QUOTES, 'UTF-8') . '" aria-label="' . htmlspecialchars($lockWho, ENT_QUOTES, 'UTF-8') . '">' . ui_icon('unlock') . '</button>';
        $lockForm = '<form method="post" action="?reveal" class="lock-form" id="lock-form" hidden>' . $lockHidden . '</form>';   // (the button in the strip submits it)
    } else {
        $lockHtml = '<a class="map-combo-btn lock-link" href="?reveal' . $lockHere . '" rel="nofollow" title="Sign in: a private-view password shows private items, the admin password signs you in as the owner." aria-label="Sign in">' . ui_icon('lock') . '</a>';
    }
}
?>
<header>
  <?php if ($currentFolderPath !== null): ?>
    <div class="crumb-row">
    <p class="breadcrumb"><a href="<?= htmlspecialchars(gallery_root_href(), ENT_QUOTES, 'UTF-8') ?>">&larr; <?= htmlspecialchars($rootPageTitle) ?></a><?php foreach ($folderCrumbs as [$crumbHref, $crumbLabel]): ?> / <a href="<?= htmlspecialchars($crumbHref, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($crumbLabel) ?></a><?php endforeach; ?></p>
    <?php if (count($folderPills) >= 2): ?>
    <nav class="folder-pills" aria-label="Other folders here">
      <?php foreach ($folderPills as [$pillHref, $pillText, $pillCurrent]): ?><a href="<?= htmlspecialchars($pillHref, ENT_QUOTES, 'UTF-8') ?>"<?= $pillCurrent ? ' class="current" aria-current="page"' : '' ?>><?= htmlspecialchars($pillText, ENT_QUOTES, 'UTF-8') ?></a><?php endforeach; ?>
      <?php if ($folderPillsAll !== null): ?><a class="all" href="<?= htmlspecialchars($folderPillsAll, ENT_QUOTES, 'UTF-8') ?>">All</a><?php endif; ?>
    </nav>
    <?php endif; ?>
    </div>
  <?php endif; ?>
  <?php if (ADMIN_PASSWORD !== '' && is_admin_browser()): ?>
    <?php // Signed in as the owner: the title leads back to the admin page for wherever you are (a folder's own admin on its page). ?>
    <h1><a class="title-admin" rel="nofollow" href="?admin<?= $currentFolderPath !== null ? '&amp;folder=' . htmlspecialchars(rawurlencode($currentFolderPath), ENT_QUOTES, 'UTF-8') : '' ?>"><?= htmlspecialchars($pageTitle) ?></a></h1>
  <?php else: ?>
    <h1><?= htmlspecialchars($pageTitle) ?></h1>
  <?php endif; ?>
  <?php $headerMeta = $currentFolderPath !== null ? $currentFolderMeta : $rootMeta; ?>
  <?php if ($headerMeta['dates'] !== ''): ?>
    <p class="folder-dates"><?= htmlspecialchars($headerMeta['dates']) ?></p>
  <?php endif; ?>
  <p><?= htmlspecialchars(media_count_label($currentFolderPath !== null ? $currentFolderFiles : $files), ENT_QUOTES, 'UTF-8') ?><?php if ($viewCount > 0 && view_counts_shown()): ?> &middot; <span class="page-visits"><?= number_format($viewCount) ?> visit<?= $viewCount === 1 ? '' : 's' ?></span><?php endif; ?><?php if ($lastUpdatedMtime > 0): ?> &middot; Last updated <?= htmlspecialchars(date('F j, Y', $lastUpdatedMtime)) ?><?php endif; ?></p>
</header>

<?php if ($headerMeta['description'] !== ''): ?>
  <div class="notes">
    <div class="note-box folder-description"><?= render_description_html($headerMeta['description']) ?></div>
  </div>
<?php endif; ?>

<?php if ($currentFolderPath === null && empty($mdFiles) && !empty($galleryNotices) && notices_visible()): ?>
  <div class="notes">
    <?= render_gallery_notices_banner($galleryNotices, empty($files)) ?>
  </div>
<?php endif; ?>

<?php
// markdown_to_html() still has to run against the *root's own, unscoped*
// $groups/$files whenever a .md qualifies — its side effects
// ($groupLabels especially) are what let a {group: Name} directive in
// the root's notes rename a folder-group, including one being viewed
// right now via ?folder=. Its visible output is a different question: a
// folder's own page doesn't show the root's notes box (they describe the
// whole trip, not specifically this one folder), so the rendered HTML is
// only kept, never discarded, when there's no $currentFolderPath to hide
// it for. A subfolder's own notes are a separate pipeline, further down,
// once $groups/$files have actually been scoped down to it.
$noteBoxesHtml = [];
foreach ($mdFiles as $mdFile) {
    $noteBoxesHtml[$mdFile] = markdown_to_html($mdContents[$mdFile], $groups, $files, $groupColors, $groupStatus, $groupLabels, $renderClaims, $photoViews);
}
?>
<?php if ($currentFolderPath === null && !empty($mdFiles)): ?>
  <div class="notes">
    <?= render_gallery_notices_banner($galleryNotices, empty($files)) ?>
    <?php foreach ($mdFiles as $mdFile): if (note_box_is_empty($noteBoxesHtml[$mdFile])) continue; ?>
      <div class="note-box">
        <span class="note-filename"><?= htmlspecialchars($mdFile) ?></span>
        <?= $noteBoxesHtml[$mdFile] ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php
// The actual ?folder= override: scope $groups/$files down to just what's
// under this folder, built from the already hiding-filtered $files. In
// 'overview' mode each immediate subfolder becomes a cover card (which
// links to its own ?folder= page, repeating this to any depth) and
// anything loose at this level is a flat card; in 'inline' mode there are
// no cover cards and everything beneath is one flat run.
// $explicitGroupKeys is cleared (a root-level {group:} box means nothing
// here) and $folderGroupKeys is rebuilt for this level only. ($pageTitle
// was already set earlier, in the ?folder= validation block.)
//
// Keyed the same bare, extension-stripped way a root-level standalone
// single already is (see group_key()) — "eiffel," not "Paris/eiffel.jpg"
// — so a mention or {color:}/{sold:}/{reserved:} directive in this
// subfolder's own notes (further down) can refer to it the natural way,
// and the card's own "#group-<slug>" anchor matches whatever that
// note's jump link points at. Two files that would collide on that bare
// key (different extensions, or two numbered angles — the same
// coincidence the root's own disambiguation pass already handles) fall
// back to their full bare filename instead, which is always unique
// within one folder; this doesn't recluster them into a group the way
// the root would, just keeps them from overwriting each other.
$rootFolderGroupKeys = $folderGroupKeys;
$folderLinkPrefix = '';
$folderMdFiles = [];
$folderMdContents = [];
$folderGalleryNotices = [];
$folderNoteBoxesHtml = [];
if ($currentFolderPath !== null) {
    $folderFiles = $currentFolderFiles;
    $folderPrefix = $currentFolderPath . '/';
    $folderLinkPrefix = $folderPrefix;
    // The root's own labels/covers/mention links are keyed by top-level
    // folder names; on a folder's page the keys are this level's own
    // names, so start clean rather than let a same-named folder deeper
    // down pick up the wrong top-level folder's settings.
    $groupLabels = [];
    $folderCovers = [];
    $folderMeta = [];
    $folderMentionLinks = [];

    // 'overview' mode drills one level at a time: each immediate
    // subfolder is a cover card (recursively counted), anything loose
    // directly here is a flat card, and the same logic repeats on that
    // subfolder's own page — to any depth. 'inline' mode never reaches
    // a cover card, so a folder's page just shows everything beneath it.
    $level = FOLDER_DISPLAY_MODE === 'overview'
        ? split_folder_level($folderFiles, $folderPrefix)
        : ['folders' => [], 'loose' => $folderFiles];

    $groups = [];
    $folderGroupKeys = [];
    foreach (order_folder_children($dir, $folderPrefix, array_keys($level['folders'])) as $childName) {
        $groups[$childName] = $level['folders'][$childName];
        $folderGroupKeys[$childName] = true;
        $m = folder_display_meta($dir, $folderPrefix . $childName);
        if ($m['label'] !== '') $groupLabels[$childName] = $m['label'];
        if ($m['cover'] !== null) $folderCovers[$childName] = $m['cover'];
        $folderMeta[$childName] = ['description' => $m['description'], 'dates' => $m['dates']];
        $folderMentionLinks[$childName] = '?folder=' . rawurlencode($folderPrefix . $childName);
    }

    $bareBaseCounts = [];
    foreach ($level['loose'] as $f) {
        $bare = substr($f, strlen($folderPrefix));
        $rawBase = pathinfo($bare, PATHINFO_FILENAME);
        $bareBaseCounts[$rawBase] = ($bareBaseCounts[$rawBase] ?? 0) + 1;
    }
    foreach ($level['loose'] as $f) {
        $bare = substr($f, strlen($folderPrefix));
        $rawBase = pathinfo($bare, PATHINFO_FILENAME);
        $localKey = $bareBaseCounts[$rawBase] > 1 ? $bare : group_key($bare);
        // A loose file whose bare name matches a subfolder's would
        // overwrite that folder's card; fall back to the full filename.
        if (isset($groups[$localKey])) $localKey = $bare;
        $groups[$localKey] = [$f];
    }
    // This folder's own notes go through the same directive reading as the top-level ones (see
    // collect_explicit_directives): {photos:} and {group:} claim the photos directly in this folder, a mention
    // pulls a photo or subfolder up to its paragraph. The photos it may claim are judged before the hiding
    // filter, so a hidden photo is dropped without a word instead of being reported.
    $folderRealPath = resolve_real_path($dir, $currentFolderPath);
    $folderMdScan = scan_gallery_md_files($folderRealPath);
    $folderMdFiles = $folderMdScan['files'];
    $folderMdContents = $folderMdScan['contents'];
    if ($folderMdScan['rejectedCount'] > 0) {
        $folderGalleryNotices[] = $folderMdScan['rejectedCount'] === 1
            ? 'A .md file was found in this folder but ignored because its first line isn\'t the required {gallery} marker.'
            : $folderMdScan['rejectedCount'] . ' .md files were found in this folder but ignored because none of them start with the required {gallery} marker.';
    }
    $directFiles = function ($list) use ($folderPrefix) {
        return array_values(array_filter($list, function ($f) use ($folderPrefix) {
            return strncmp($f, $folderPrefix, strlen($folderPrefix)) === 0 && strpos(substr($f, strlen($folderPrefix)), '/') === false;
        }));
    };
    $folderDirectives = collect_explicit_directives($folderMdContents, $directFiles($allFiles), $folderPrefix, $allFiles, $folderGalleryNotices);
    $visibleDirect = array_flip($directFiles($files));
    $folderExplicitGroups = [];
    foreach ($folderDirectives['groups'] as $slug => $g) {
        $g['files'] = array_values(array_filter($g['files'], function ($f) use ($visibleDirect) { return isset($visibleDirect[$f]); }));
        if ($g['files']) $folderExplicitGroups[$slug] = $g;
    }
    $folderClusters = [];
    foreach ($folderDirectives['clusters'] as $c) {
        $c['files'] = array_values(array_filter($c['files'], function ($f) use ($visibleDirect) { return isset($visibleDirect[$f]); }));
        if ($c['files']) $folderClusters[] = $c;
    }
    $folderClaimed = [];
    foreach (array_merge(array_column($folderExplicitGroups, 'files'), array_column($folderClusters, 'files')) as $claimedList) {
        foreach ($claimedList as $f) $folderClaimed[$f] = true;
    }
    // A claimed photo leaves its own card; it comes back as its box, or (a bare {photos:}) as a card of its own
    // that its paragraph can style, keyed by the whole file name like the top-level ones.
    $naturalRankHere = [];
    foreach ($groups as $groupFiles) {
        foreach ($groupFiles as $f) $naturalRankHere[$f] = count($naturalRankHere);
    }
    foreach ($groups as $groupKey => $groupFiles) {
        if (count($groupFiles) === 1 && isset($folderClaimed[$groupFiles[0]])) unset($groups[$groupKey]);
    }
    $inNamedGroup = [];
    foreach ($folderExplicitGroups as $slug => $g) {
        $groups[$slug] = $g['files'];
        foreach ($g['files'] as $f) $inNamedGroup[$f] = true;
    }
    foreach (array_keys($folderClaimed) as $f) {
        if (!isset($inNamedGroup[$f])) $groups[substr($f, strlen($folderPrefix))] = [$f];
    }
    $explicitGroupKeys = array_fill_keys(array_keys($folderExplicitGroups), true);
    // Same ordering as the top level: a paragraph pulls what it mentions or claims up to its place, everything
    // else keeps the order it had (folders first, then the photos).
    $groupOrder = compute_zone_order($folderMdContents, $groups, $folderExplicitGroups, $folderClusters, $explicitGroupKeys, $naturalRankHere, [], $folderGroupKeys);
    $orderedHere = [];
    foreach ($groupOrder as $key) {
        if (isset($groups[$key]) && !isset($orderedHere[$key])) $orderedHere[$key] = $groups[$key];
    }
    $groups = $orderedHere;
    // The flat list has to follow the new card order exactly: every
    // card's lightbox index is drawn from it.
    $files = [];
    foreach ($groups as $groupFiles) {
        foreach ($groupFiles as $f) {
            $files[] = $f;
        }
    }
}

// A subfolder may optionally carry its own {gallery}-marked .md,
// becoming a dedicated notes box just for that subfolder's own photos —
// scoped to it the same way the root's notes are scoped to the whole
// site, so a Paris .md simply has no way to claim a StTropez photo. Page
// title stays root-driven (see $rootPageTitle above) — a subfolder's own
// heading, if it has one, is just left as plain text in its own note
// box, not promoted to the page <title>. $groups/$files are already the
// folder-scoped, bare-keyed versions the override above produced, so
// this is the exact same call shape the root's own notes use — a mention
// or directive here naturally lines up with the card it actually
// describes, anchors included.
if ($currentFolderPath !== null) {
    foreach ($folderMdFiles as $folderMdFile) {
        $folderNoteBoxesHtml[$folderMdFile] = markdown_to_html($folderMdContents[$folderMdFile], $groups, $files, $groupColors, $groupStatus, $groupLabels, $renderClaims, $photoViews, $folderLinkPrefix);
    }
}
?>
<?php if ($currentFolderPath !== null && empty($folderMdFiles) && !empty($folderGalleryNotices) && notices_visible()): ?>
  <div class="notes">
    <?= render_gallery_notices_banner($folderGalleryNotices) ?>
  </div>
<?php endif; ?>
<?php if ($currentFolderPath !== null && !empty($folderMdFiles)): ?>
  <div class="notes">
    <?= render_gallery_notices_banner($folderGalleryNotices) ?>
    <?php foreach ($folderMdFiles as $folderMdFile): if (note_box_is_empty($folderNoteBoxesHtml[$folderMdFile])) continue; ?>
      <div class="note-box">
        <span class="note-filename"><?= htmlspecialchars($folderMdFile) ?></span>
        <?= $folderNoteBoxesHtml[$folderMdFile] ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php
// A page of nothing but a few folder cards gets bigger cards on a wide screen (two folders shouldn't be two small tiles
// in a big empty page). The map buttons above them use the same width, so they line up with the first card's corner.
// Anything else, and every phone, keeps the usual grid.
$coverCount = FOLDER_DISPLAY_MODE === 'overview' ? count($folderGroupKeys) : 0;
$fewCovers = ($coverCount >= 1 && $coverCount <= 4 && count($groups) === $coverCount) ? ' covers-' . $coverCount : '';
$GLOBALS['__big_covers'] = $fewCovers !== '';
?>
<?php
// A note can colour a group or folder ({color:}) or mark it sold/reserved: its photos' pins follow.
if (ENABLE_MAP && $mapPoints) {
    $mapPoints = apply_note_colors_to_points($mapPoints, $groups, $groupColors, $groupStatus, $groupLabels);
    $mapLegend = map_legend_from_points($mapPoints);
}
?>
<?php $hasMap = $mapShown && !empty($mapPoints); ?>
<?php
  // The sort button: for the photos shown at this level (the folder's own, or all of them when folders are shown inline), what
  // there is to put first, and the order in force here. Hidden when there is nothing to put in order.
  $sortScope = array_values(array_filter($files, function ($f) use ($currentFolderPath) {
      if (FOLDER_DISPLAY_MODE !== 'overview' || !AUTO_GROUP_BY_FOLDER) return true;
      return ($currentFolderPath === null ? '' : $currentFolderPath) === (strpos($f, '/') === false ? '' : dirname($f));
  }));
  $sortShown = SHOW_SORT_BUTTON && count($sortScope) >= 2 && !(!AUTO_GROUP_BY_FOLDER && !empty($mdFiles));   // (a notes-file listing sets its own order)
  $sortHasVideo = false; $sortHasLocated = false; $sortHasPrivate = false;
  if ($sortShown) {
      $sortRevealed = is_revealed();
      foreach ($sortScope as $sf) {
          if (!$sortHasVideo && is_video_file($sf)) $sortHasVideo = true;
          if (!$sortHasLocated && photo_location_visible($itemsData[$sf] ?? [])) $sortHasLocated = true;
          if ($sortRevealed && !$sortHasPrivate && file_is_hidden($dir, $sf, $itemsData)) $sortHasPrivate = true;
      }
      $sortSpec = media_sort_spec_for_folder($currentFolderPath === null ? $dir : resolve_real_path($dir, $currentFolderPath));
      if (!$sortRevealed) $sortSpec['private'] = false;
      $sortCookiePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
  }
  // The owner's Arrange button: photos shown one by one here (not in a filename group or a notes file's layout, which keep their own order).
  $arrangeShown = is_admin_browser() && !AUTO_GROUP_BY_FILENAME && count($sortScope) >= 2 && !(!AUTO_GROUP_BY_FOLDER && !empty($mdFiles));
  $selectShown = is_admin_browser() && count($sortScope) >= 1;
  $arrangeStacks = [];
  if ($arrangeShown) {
      $arrangeByFolder = [];
      foreach ($sortScope as $sf) $arrangeByFolder[containing_folder_real_path($dir, $sf)][] = $sf;
      foreach ($arrangeByFolder as $arrangeReal => $arrangeList) foreach (media_stacks_resolve($arrangeReal, $arrangeList) as $st) $arrangeStacks[] = $st['files'];
  }
?>
  <?php // The bar above the photos: the map buttons on the left (when there is a map), and on the right the visit count (when
        // it is shown to visitors) and the small gallery icon, which leads to the page about this gallery. ?>
  <div class="map-preview map-bar<?= $fewCovers ?>" id="map-bar">
    <div class="map-preview-heading">
      <?php if ($hasMap): ?>
      <span class="map-combo">
        <button type="button" class="map-combo-btn" id="map-toggle" title="Show map" aria-expanded="false" aria-controls="map-preview-body"><?= ui_icon('map') ?><span class="map-toggle-label">Show map</span></button>
        <a href="<?= htmlspecialchars($mapPageHref, ENT_QUOTES, 'UTF-8') ?>" class="map-combo-btn" id="map-full" title="View full map"><?= ui_icon('expand') ?><span class="map-toggle-label">View full map</span></a>
      </span>
      <?php endif; ?>
      <?php if ($sortShown): ?>
      <span class="map-combo sort-combo" id="sort-combo" data-spec="<?= htmlspecialchars(media_sort_encode($sortSpec), ENT_QUOTES, 'UTF-8') ?>" data-path="<?= htmlspecialchars($sortCookiePath, ENT_QUOTES, 'UTF-8') ?>">
        <button type="button" class="map-combo-btn" id="sort-menu-btn" title="Order of the photos" aria-haspopup="true" aria-expanded="false" aria-controls="sort-pop"><?= ui_icon('sort') ?><span class="map-toggle-label">Order of the photos</span></button>
        <?php if ($sortHasPrivate): ?><button type="button" class="map-combo-btn sort-flag" data-flag="private" title="Private items first" aria-pressed="false"><?= ui_icon('lock') ?><span class="map-toggle-label">Private items first</span></button><?php endif; ?>
        <?php if ($sortHasVideo): ?><button type="button" class="map-combo-btn sort-flag" data-flag="video" title="Videos first" aria-pressed="false"><?= ui_icon('play') ?><span class="map-toggle-label">Videos first</span></button><?php endif; ?>
        <?php if ($sortHasLocated): ?><button type="button" class="map-combo-btn sort-flag" data-flag="located" title="Photos with a location first" aria-pressed="false"><?= ui_icon('pin') ?><span class="map-toggle-label">Photos with a location first</span></button><?php endif; ?>
      </span>
      <div class="sort-pop" id="sort-pop" hidden role="dialog" aria-label="Order of the photos">
        <div class="sort-pop-title">Order</div>
        <div class="sort-pop-row"><button type="button" data-order="name">Name</button><button type="button" data-order="taken">Date</button></div>
        <div class="sort-pop-row"><button type="button" data-dir="asc"></button><button type="button" data-dir="desc"></button></div>
        <button type="button" class="sort-reset">Use this gallery's order</button>
      </div>
      <?php endif; ?>
      <?php if ($selectShown): ?>
      <span class="map-combo select-combo"><button type="button" class="map-combo-btn" id="select-btn" title="Select <?= media_word() ?>" aria-pressed="false"><?= ui_icon('select') ?><span class="map-toggle-label">Select <?= media_word() ?></span></button></span>
      <?php endif; ?>
      <span class="page-tools">
        <?php if ($archiveCount > 0 || $lockHtml !== ''): $archiveTitle = 'Download all ' . number_format($archiveCount) . ' original' . ($archiveCount === 1 ? '' : 's') . ' (' . human_bytes($archiveBytes) . ') as one ZIP'; ?>
        <span class="map-combo"><?php if ($archiveCount > 0): ?><a class="map-combo-btn page-archive" href="?archive<?= $currentFolderPath !== null ? '&amp;folder=' . htmlspecialchars(rawurlencode($currentFolderPath), ENT_QUOTES, 'UTF-8') : '' ?>" data-bytes="<?= (int) $archiveBytes ?>" data-ask="<?= htmlspecialchars($archiveTitle, ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($archiveTitle, ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($archiveTitle, ENT_QUOTES, 'UTF-8') ?>"><?= ui_icon('archive') ?></a><?php endif; ?><?= $lockHtml ?></span>
        <?php endif; ?>
        <span class="map-combo"><a class="map-combo-btn page-about" href="?about" title="About this gallery software" aria-label="About this gallery software"><?= ui_icon('gallery') ?></a></span>
      </span>
    </div>
  </div>
  <?php if ($hasMap): ?>
  <section class="map-preview<?= $fewCovers ?>" id="map-preview">
    <div class="map-preview-body" id="map-preview-body" data-map="trip-map-preview" hidden>
      <?= render_leaflet_map($mapPoints, $mapLegend, 'trip-map-preview', '300px', true, $mapRadius, true) ?>
      <button type="button" class="map-corner-link" id="map-peek-share" data-href="<?= htmlspecialchars($mapPeekHref, ENT_QUOTES, 'UTF-8') ?>" title="Copy a link that opens this page with this map showing" aria-label="Copy a link that opens this page with this map showing"><?= ui_icon('link') ?></button>
    </div>
  </section>
  <?php endif; ?>
<?= $lockForm ?>
<?php if (is_admin_browser() && $currentFolderPath !== null && AUTO_GROUP_BY_FOLDER && folder_restriction_cached($dir, $currentFolderPath) === 'private'): $privCounts = folder_privacy_counts($dir, $files, $currentFolderPath); ?>
  <?php // For the owner, on a private folder's own page: what making the folder public would do (the lock on each tile shows which is which). ?>
  <div class="folder-priv-banner" id="folder-priv-banner" role="button" tabindex="0" title="Make this folder public" data-noun="<?= ENABLE_VIDEO ? 'item' : 'photo' ?>" data-folder="<?= htmlspecialchars($currentFolderPath, ENT_QUOTES, 'UTF-8') ?>">
    <?= ui_icon('lock') ?>
    <div><strong>Private folder: visitors can't see it</strong>
      <span class="fpb-counts" aria-live="polite"><?= count_noun($privCounts['stay']) ?> will stay private &middot; <?= count_noun($privCounts['open']) ?> <?= (int) $privCounts['open'] === 1 ? 'becomes' : 'become' ?> public when you open it</span>
      <em class="fpb-hint">Make it public&hellip;</em></div>
  </div>
  <div class="fp-modal" id="fp-modal" role="dialog" aria-modal="true" aria-labelledby="fp-modal-title" hidden>
    <div class="fp-modal-card">
      <h3 id="fp-modal-title">Make this folder public?</h3>
      <div class="fp-row fp-stay"><?= ui_icon('lock') ?><span><strong id="fp-stay">0 photos</strong> <span id="fp-stay-v">stay</span> private</span></div>
      <div class="fp-row fp-open"><?= ui_icon('unlock') ?><span><strong id="fp-open">0 photos</strong> <span id="fp-open-v">become</span> public</span></div>
      <p class="fp-hint">Anyone with the link will see those. Not right? Cancel, mark more private first, then come back.</p>
      <div class="fp-btns"><button type="button" id="fp-cancel">Cancel</button><button type="button" class="primary" id="fp-ok">Make public</button></div>
    </div>
  </div>
<?php endif; ?>
<?php if ($hasMap): ?>
  <?php // The full map opens over the page: click outside it, press Escape or use the cross to close it. ?>
  <div class="map-modal" id="map-modal" data-map="trip-map-modal" role="dialog" aria-modal="true" aria-label="Map" hidden>
    <div class="map-modal-card">
      <button type="button" class="map-modal-close" aria-label="Close the map">&times;</button>
      <?= render_leaflet_map($mapPoints, $mapLegend, 'trip-map-modal', 'min(72vh, 720px)', true, $mapRadius, true) ?>
      <button type="button" class="map-corner-link" id="map-share" data-href="<?= htmlspecialchars($mapPageHref, ENT_QUOTES, 'UTF-8') ?>" title="Copy a link to this map" aria-label="Copy a link to this map"><?= ui_icon('link') ?></button>
    </div>
  </div>
<?php endif; ?>

<?php if (empty($files)): ?>
  <div class="empty">No media here yet.</div>
<?php else: ?>
  <div class="gallery<?= $fewCovers ?>">
    <?= build_gallery_html($groups, $groupColors, $groupStatus, $photoViews, $groupLabels, $explicitGroupKeys, $folderGroupKeys, $videoDurations, $folderCovers, $itemsData, $folderMeta, $folderLinkPrefix) ?>
  </div>
<?php endif; ?>


<?php if (is_admin_browser()): ?><div class="lightbox-toast" id="owner-toast" role="status" aria-live="polite" hidden></div><?php endif; ?>
<?php if (!empty($selectShown)): ?>
<div class="select-bar" id="select-bar" role="region" aria-label="Select <?= media_word() ?>" hidden>
  <div class="select-top">
    <span class="select-count" id="select-count" aria-live="polite" data-empty="Tap <?= media_word() ?> to select">Tap <?= media_word() ?> to select</span>
    <button type="button" data-select="all">Select all</button>
    <button type="button" data-select="clear">Clear</button>
    <button type="button" data-select="done" class="primary">Done</button>
  </div>
  <div class="select-btns">
    <button type="button" data-select="private" class="sb-private"><?= ui_icon('lock') ?> <span class="sb-n">Make private</span><span class="sb-p">Private</span></button>
    <button type="button" data-select="public" class="sb-public"><?= ui_icon('unlock') ?> <span class="sb-n">Make public</span><span class="sb-p">Public</span></button>
    <button type="button" data-select="inherit" class="sb-folder" title="Back to whatever the folder is: they follow it again" hidden><?= ui_icon('lock') ?> Folder</button>
  </div>
<?php if (!empty($arrangeShown)): ?>
  <div class="select-arr">
    <span class="select-hint">Hold and drag a picked <?= media_word() === 'media' ? 'one' : 'photo' ?> beside another.</span>
    <button type="button" data-select="release" title="Take the selected photos out of the places you gave them">Release</button>
    <button type="button" data-select="reset" title="Put every photo of this folder back in its sorted place">Reset folder</button>
  </div>
<?php endif; ?>
</div>
<?php endif; ?>
<div class="lightbox" id="lightbox">
  <button class="close" type="button" onclick="closeLightbox()" aria-label="Close"><?= ui_icon('close') ?></button>
  <button class="nav prev" type="button" onclick="navigate(-1)" aria-label="Previous"><?= ui_icon('chev-left') ?></button>
  <img id="lightbox-img" src="" alt="">
  <video id="lightbox-video" controls playsinline style="display:none;"></video>
<?php if (ENABLE_MAP): ?>
  <div class="lightbox-map" id="lightbox-map" hidden>
    <button type="button" class="lbmap-close" title="Close the big map" aria-label="Close the big map">&times;</button>
    <a class="lbmap-osm" href="https://www.openstreetmap.org/" target="_blank" rel="noopener noreferrer">Open in OpenStreetMap &#8599;</a>
  </div>
<?php endif; ?>
  <div class="lightbox-toast" id="lightbox-toast" role="status" aria-live="polite" hidden></div>
  <div class="caption-row">
    <div class="caption" id="lightbox-caption"></div>
    <div class="lightbox-tools-wrap" id="lightbox-tools-wrap">
    <div class="lightbox-tools" id="lightbox-tools">
<?php if (is_admin_browser()): ?>
    <button class="lightbox-copy owner-edit" id="lightbox-edit" type="button" title="Edit caption, link and location" aria-label="Edit caption, link and location"><?= ui_icon('edit') ?></button>
    <button class="lightbox-copy owner-cover" id="lightbox-cover" type="button" data-owner="cover" title="Make this the folder's cover photo" aria-label="Make this the folder's cover photo"><?= ui_icon('star') ?></button>
    <button class="lightbox-copy owner-priv" id="lightbox-private" type="button" data-owner="private" title="Make this private" aria-label="Make this private"><?= ui_icon('unlock') ?></button>
<?php endif; ?>
    <button class="lightbox-copy lightbox-zoom" id="lightbox-zoom" type="button" title="Fill the width of the screen" aria-label="Fill the width of the screen"><?= ui_icon('zoom-in') ?></button>
<?php if (is_revealed() || ENABLE_PUBLIC_DOWNLOAD): ?>
    <a class="lightbox-copy lightbox-download" id="lightbox-download" href="#" download title="Download the original" aria-label="Download the original"><?= ui_icon('download') ?></a>
<?php endif; ?>
    <button class="lightbox-copy" id="lightbox-copy-btn" title="Copy link"><?= ui_icon('link') ?></button>
    <a class="lightbox-copy lightbox-web" id="lightbox-web" href="#" target="_blank" rel="noopener noreferrer" title="Information link" hidden><?= ui_icon('globe') ?></a>
<?php if (ENABLE_MAP): ?>
    <button class="lightbox-copy lightbox-map-toggle" id="lightbox-map-toggle" type="button" title="Show or hide the little map" hidden><?= ui_icon('pin') ?></button>
<?php endif; ?>
    </div>
    <span class="tools-more tools-more-l" aria-hidden="true"><?= ui_icon('chev-left') ?></span>
    <span class="tools-more tools-more-r" aria-hidden="true"><?= ui_icon('chev-right') ?></span>
    </div>
    <button class="lightbox-copy lightbox-close-row" type="button" onclick="closeLightbox()" title="Close" aria-label="Close"><?= ui_icon('close') ?></button>
  </div>
  <button class="nav next" type="button" onclick="navigate(1)" aria-label="Next"><?= ui_icon('chev-right') ?></button>
<?php if (is_admin_browser()): ?>
  <div class="lb-editor" id="lb-editor" role="dialog" aria-label="Edit this photo" hidden>
    <div class="lbe-grip" id="lbe-grip" aria-hidden="true"><div class="lbe-handle"></div></div>
    <div class="lbe-head">
      <span class="lbe-name" id="lbe-name"></span>
      <span class="lbe-status" id="lbe-status" role="status" aria-live="polite"></span>
      <button type="button" class="lbe-hidekb" id="lbe-hidekb"><?= ui_icon('chev-down') ?> Hide keyboard</button>
    </div>
    <div class="lbe-body">
      <label class="lbe-field"><span>Caption</span><input type="text" id="lbe-caption" maxlength="500" autocomplete="off" enterkeyhint="done"></label>
      <label class="lbe-field"><span>Information link</span><input type="url" id="lbe-web" inputmode="url" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" placeholder="https://&hellip;" enterkeyhint="done"><em class="lbe-err" id="lbe-web-err"></em></label>
      <div class="lbe-pills" id="lbe-pills">
        <button type="button" class="lbe-pill" id="lbe-private" aria-pressed="false" title="Hidden from visitors unless they have the private-view password"><?= ui_icon('lock') ?> Private</button>
        <button type="button" class="lbe-pill" id="lbe-loc" aria-pressed="false" title="Show this photo's location to visitors"><?= ui_icon('pin') ?> Location</button>
        <button type="button" class="lbe-pill" id="lbe-cover" aria-pressed="false" title="Make this the folder's cover photo"><?= ui_icon('star') ?> Cover</button>
      </div>
      <p class="lbe-note" id="lbe-note" hidden></p>
      <div class="lbe-loc">
        <button type="button" class="lbe-locrow" id="lbe-locrow" aria-expanded="false"><?= ui_icon('pin') ?><span id="lbe-locsum">No location</span><span class="lbe-chev"><?= ui_icon('chev-down') ?></span></button>
        <div class="lbe-locfields">
          <div class="lbe-two">
            <label class="lbe-field"><span>Latitude</span><input type="text" id="lbe-lat" inputmode="text" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" enterkeyhint="done"></label>
            <label class="lbe-field"><span>Longitude</span><input type="text" id="lbe-lng" inputmode="text" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" enterkeyhint="done"></label>
          </div>
          <em class="lbe-err" id="lbe-loc-err"></em>
          <label class="lbe-field"><span>Location label (optional)</span><input type="text" id="lbe-label" maxlength="200" autocomplete="off" enterkeyhint="done"></label>
          <button type="button" class="lbe-auto" id="lbe-useauto" hidden>Keep the location from the photo</button>
        </div>
      </div>
    </div>
    <div class="lbe-foot">
      <button type="button" class="lbe-btn" id="lbe-prev"><?= ui_icon('chev-left') ?> Prev</button>
      <button type="button" class="lbe-btn primary" id="lbe-done">Done</button>
      <button type="button" class="lbe-btn" id="lbe-next">Next <?= ui_icon('chev-right') ?></button>
    </div>
  </div>
<?php endif; ?>
</div>

<script>
  const files = <?= json_encode($files) ?>;
  // A short tag per file that changes when the file is replaced — appended to
  // the full-size image / video address so a browser that cached the old
  // picture (they're cached for a year) fetches the new one.
  const fileVersions = <?= json_encode(array_map('file_version', $files)) ?>;
  // Admin-written captions only — never the raw filename. A visitor
  // curious enough to open dev tools shouldn't learn anything from a
  // filename an unrevealed caption-less photo never meant to publish.
  const captions = <?= json_encode(array_map(function ($f) use ($itemsData) {
      return (!empty($itemsData[$f]['caption'])) ? $itemsData[$f]['caption'] : null;
  }, $files)) ?>;
  // Where each file was taken, only for photos whose location is meant to be public and only when the map feature
  // is on (the little map in the viewer loads a third-party map, like the overview map does), and each file's web link.
  const locations = <?= json_encode(array_map(function ($f) use ($itemsData) {
      $entry = $itemsData[$f] ?? [];
      if (!ENABLE_MAP || !photo_location_visible($entry)) return null;
      return [(float) $entry['location']['lat'], (float) $entry['location']['lng']];
  }, $files)) ?>;
  const webLinks = <?= json_encode(array_map(function ($f) use ($itemsData) {
      $w = $itemsData[$f]['web'] ?? '';
      return preg_match('#^https?://#i', (string) $w) ? $w : null;
  }, $files)) ?>;
  // For the signed-in owner only: which files are private and which is its folder's cover (the tiles' stars and locks and the
  // viewer's two buttons are drawn from this, and kept in step when one is pressed).
  const ownerState = <?php
      if (is_admin_browser()) {
          $ownerPriv = []; $ownerOwn = []; $ownerInh = [];
          foreach ($files as $f) { $ps = owner_privacy_state($dir, $f, $itemsData); $ownerPriv[$f] = $ps['priv']; $ownerOwn[$f] = $ps['own']; $ownerInh[$f] = $ps['inh']; }
          echo json_encode(['priv' => (object) $ownerPriv, 'own' => (object) $ownerOwn, 'inh' => (object) $ownerInh, 'cover' => (object) owner_cover_flags($dir, $files)]);
      } else echo 'null';
  ?>;
  const initialPhoto = <?= json_encode($requestedPhoto) ?>;
  const trackingEnabled = <?= TRACK_VIEWS ? 'true' : 'false' ?>;
  const showCountsPublicly = <?= view_counts_shown() ? 'true' : 'false' ?>;
  let current = 0;
  let lbEdit = null;   // the owner's editor in the viewer, when signed in as the owner
  const lightbox = document.getElementById('lightbox');
  const lightboxImg = document.getElementById('lightbox-img');
  const lightboxVideo = document.getElementById('lightbox-video');
  const caption = document.getElementById('lightbox-caption');

  function isVideoFile(name) {
    return /\.(mp4|mov)$/i.test(name);
  }

  function cssEscape(value) {
    return (window.CSS && CSS.escape) ? CSS.escape(value) : value.replace(/["\\]/g, '\\$&');
  }

  // Writes fresh counts from a ?track response into every matching badge
  // on the page (a photo can appear both in the gallery and in a note-box
  // thumbnail strip; a group total can appear in both places too). A
  // no-op when counts aren't shown publicly — tracking still happens
  // server-side either way, there's just nothing to update on-page.
  function applyViewUpdate(data) {
    if (!showCountsPublicly) return;
    document.querySelectorAll('.view-badge[data-photo="' + cssEscape(data.photo) + '"]').forEach((el) => {
      el.innerHTML = '<svg class="ico" aria-hidden="true"><use href="#i-eye"></use></svg> ' + data.photoViews.toLocaleString();
      el.classList.remove('hidden');
    });
    if (data.groupSlug) {
      document.querySelectorAll('.group-views[data-group="' + cssEscape(data.groupSlug) + '"]').forEach((el) => {
        el.textContent = '· ' + data.groupViews.toLocaleString() + ' view' + (data.groupViews === 1 ? '' : 's');
        el.classList.remove('hidden');
      });
    }
  }

  function trackView(filename) {
    if (!trackingEnabled) return;
    fetch('?track=' + encodeURIComponent(filename))
      .then((res) => res.ok ? res.json() : null)
      .then((data) => { if (data && data.ok) applyViewUpdate(data); })
      .catch(() => {});
  }

  // Caption badge: tap/click/Enter shows the caption in a small popover
  // on that card (textContent, so it's never parsed as HTML); tapping
  // anywhere else, or the badge again, dismisses it. Stops the click
  // from also opening the lightbox.
  function toggleCaptionPopover(badge, e) {
    e.stopPropagation();
    e.preventDefault();
    const card = badge.closest('.card');
    if (!card) return;
    const existing = card.querySelector('.caption-popover');
    document.querySelectorAll('.caption-popover').forEach(function (p) { p.remove(); });
    if (existing) return;
    const pop = document.createElement('div');
    pop.className = 'caption-popover';
    pop.textContent = badge.getAttribute('title');
    card.appendChild(pop);
  }
  document.addEventListener('click', function () {
    document.querySelectorAll('.caption-popover').forEach(function (p) { p.remove(); });
  });

  function openLightbox(index) {
    if (window.__scrubbedAt && Date.now() - window.__scrubbedAt < 600) return;   // the tap that ends a finger-scrub of a video tile is not a tap
    if (window.__previewHalt) window.__previewHalt();   // a clip playing on a tile stops while the viewer is open
    current = index;
    updateImage();
    lightbox.classList.add('open');
    toolsFade();
  }
  function closeLightbox() {
    if (lbEdit && lbEdit.isOpen()) lbEdit.close();
    if (typeof collapseMiniMap === 'function') collapseMiniMap();
    if (typeof setZoom === 'function' && zoomMode !== 'fit') setZoom('fit');
    lightbox.classList.remove('open');
    lightboxVideo.pause();
    if (window.__previewResume) window.__previewResume();
  }
  // The arrows stay inside the folder the open photo is in: a folder is its own set, however the page lays
  // folders out, so Next from the last photo of one folder goes back to that folder's first, not into another's.
  function folderOf(name) {
    const slash = name.lastIndexOf('/');
    return slash < 0 ? '' : name.slice(0, slash);
  }
  function siblingsOf(index) {
    const dir = folderOf(files[index]), out = [];
    for (let i = 0; i < files.length; i++) if (folderOf(files[i]) === dir) out.push(i);
    return out;
  }
  function navigate(delta) {
    // With the editor open, what was typed on this photo is saved first (and the move waits if something cannot be saved).
    if (lbEdit && lbEdit.isOpen()) { lbEdit.beforeLeave().then(function (ok) { if (ok) step(delta); }); return; }
    step(delta);
  }
  function step(delta) {
    const set = siblingsOf(current), at = set.indexOf(current);
    current = set[(at + delta + set.length) % set.length];
    updateImage();
  }
  <?= video_tile_script() ?>
  // The sort button: what comes first and in which order. A choice is kept in a cookie (so it follows the visitor from folder
  // to folder) and the page is loaded again, because the server puts the photos in order, the viewer's next/previous with them.
  (function () {
    const combo = document.getElementById('sort-combo');
    if (!combo) return;
    const pop = document.getElementById('sort-pop'), menu = document.getElementById('sort-menu-btn');
    const resetBtn = pop && pop.querySelector('.sort-reset');
    if (!pop || !menu || !resetBtn) return;
    const parse = function (text) {
      const parts = String(text).split('.');
      const s = { order: parts.shift() || 'name', desc: false, private: false, video: false, located: false };
      parts.forEach(function (p) { if (p in s && p !== 'order') s[p] = true; });
      return s;
    };
    const encode = function (s) { return [s.order].concat(['desc', 'private', 'video', 'located'].filter(function (k) { return s[k]; })).join('.'); };
    let spec = parse(combo.getAttribute('data-spec'));
    const paint = function () {
      pop.querySelectorAll('[data-order]').forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-order') === (spec.order === 'modified' ? 'taken' : spec.order) ? 'true' : 'false'); });
      const dates = spec.order !== 'name';
      pop.querySelector('[data-dir="asc"]').textContent = dates ? 'Oldest first' : 'A to Z';
      pop.querySelector('[data-dir="desc"]').textContent = dates ? 'Newest first' : 'Z to A';
      pop.querySelectorAll('[data-dir]').forEach(function (b) { b.setAttribute('aria-pressed', (b.getAttribute('data-dir') === 'desc') === spec.desc ? 'true' : 'false'); });
      combo.querySelectorAll('.sort-flag').forEach(function (b) { b.setAttribute('aria-pressed', spec[b.getAttribute('data-flag')] ? 'true' : 'false'); });
    };
    const go = function (text) {
      const u = new URL(location.href);
      u.searchParams.delete('sort');
      const cookie = 'gallery_sort=' + (text === null ? '' : text) + '; path=' + combo.getAttribute('data-path') + '; max-age=' + (text === null ? 0 : 31536000) + '; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
      try { document.cookie = cookie; } catch (e) {}
      if (u.toString() === location.href) location.reload(); else location.assign(u.toString());
    };
    const close = function () { pop.hidden = true; menu.setAttribute('aria-expanded', 'false'); };
    menu.addEventListener('click', function (e) { e.stopPropagation(); const open = pop.hidden; pop.hidden = !open; menu.setAttribute('aria-expanded', open ? 'true' : 'false'); });
    pop.addEventListener('click', function (e) { e.stopPropagation(); });
    document.addEventListener('click', close);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
    pop.querySelectorAll('[data-order]').forEach(function (b) { b.addEventListener('click', function () { spec.order = b.getAttribute('data-order'); go(encode(spec)); }); });
    pop.querySelectorAll('[data-dir]').forEach(function (b) { b.addEventListener('click', function () { spec.desc = b.getAttribute('data-dir') === 'desc'; go(encode(spec)); }); });
    combo.querySelectorAll('.sort-flag').forEach(function (b) { b.addEventListener('click', function () { const k = b.getAttribute('data-flag'); spec[k] = !spec[k]; go(encode(spec)); }); });
    resetBtn.addEventListener('click', function () { go(null); });
    paint();
  })();
  // The owner's lock and star, on every tile and in the viewer: flip a photo between private and public, or make it its folder's
  // cover, with the admin's own save (a token is fetched first). The state lives in ownerState; every button is drawn from it.
  // A photo's privacy has up to three states. Its own setting is "private", "public" or "" (none: it follows its folder). Where a folder
  // above it is private ("inh") the lock goes round Folder (dashed) -> Private (kept on its own) -> Public -> Folder; elsewhere it is
  // Public <-> Private. lockKind() names what is drawn: own (solid red), inh (dashed, "Folder"), pub (open lock, public inside a
  // private folder) or none (an ordinary public photo).
  function lockKind(f) {
    const o = ownerState.own[f] || '';
    if (o === 'private') return 'own';
    if (o === 'public' && ownerState.inh[f]) return 'pub';
    return o === '' && ownerState.inh[f] ? 'inh' : 'none';
  }
  function nextOwn(f) {
    const o = ownerState.own[f] || '';
    if (ownerState.inh[f]) return o === '' ? 'private' : (o === 'private' ? 'public' : 'inherit');
    return o === 'private' ? 'inherit' : 'private';
  }
  function setOwn(f, state) {
    ownerState.own[f] = state === 'inherit' ? '' : state;
    ownerState.priv[f] = state === 'private' || (state === 'inherit' && !!ownerState.inh[f]);
    privBannerRefresh();
  }
  // On a private folder's own page the banner says how many photos would stay private and how many would become public if it were
  // opened; it is asked for again after each change, so it always matches the locks.
  let bannerTimer = 0;
  function privBannerRefresh() {
    const el = document.getElementById('folder-priv-banner'); if (!el) return;
    clearTimeout(bannerTimer);
    bannerTimer = setTimeout(function () {
      fetch('?admin&folder=' + encodeURIComponent(el.getAttribute('data-folder')) + '&privcounts=1', { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { return r.ok && (r.headers.get('content-type') || '').indexOf('json') >= 0 ? r.json() : null; })
        .then(function (j) {
          const c = j && j.counts, n = el.querySelector('.fpb-counts');
          const noun = el.getAttribute('data-noun') || 'photo', many = function (k) { return k + ' ' + noun + (k === 1 ? '' : 's'); };
          if (c && n) n.textContent = many(c.stay) + ' will stay private \u00b7 ' + many(c.open) + (c.open === 1 ? ' becomes' : ' become') + ' public when you open it';
        }).catch(function () {});
    }, 250);
  }
  function lockTitle(f) {
    const k = lockKind(f);
    if (k === 'inh') return 'Private because its folder is: press to keep it private on its own';
    if (k === 'pub') return 'Public, though its folder is private: press to follow the folder again';
    if (k === 'own') return ownerState.inh[f] ? 'Kept private on its own: press to make it public' : 'Private: press to make it public';
    return 'Public: press to make it private';
  }
  function lockSaid(f, state) {
    if (state === 'private') return ownerState.inh[f] ? 'Kept private on its own' : 'Now private';
    if (state === 'public') return ownerState.inh[f] ? 'Public, though its folder is private' : 'Now public';
    return ownerState.inh[f] ? 'Follows its folder (private)' : 'Now public';
  }
  function ownerPaint() {
    if (!ownerState) return;
    document.querySelectorAll('.priv-toggle').forEach(function (b) {
      const f = b.getAttribute('data-file'), k = lockKind(f), on = !!ownerState.priv[f];
      b.classList.toggle('is-private', on); b.classList.toggle('is-folder', k === 'inh'); b.setAttribute('aria-pressed', on ? 'true' : 'false');
      b.title = lockTitle(f);
      b.innerHTML = '<svg class="ico" aria-hidden="true"><use href="#i-' + (on ? 'lock' : 'unlock') + '"></use></svg>' + (k === 'inh' ? '<span>Folder</span>' : (on ? '<span>Private</span>' : ''));
    });
    document.querySelectorAll('.cover-toggle').forEach(function (b) {
      const on = !!ownerState.cover[b.getAttribute('data-file')];
      b.classList.toggle('is-cover', on); b.setAttribute('aria-pressed', on ? 'true' : 'false');
      b.title = on ? 'This is the folder’s cover photo' : 'Make this the folder’s cover photo';
    });
    const file = files[current], lc = document.getElementById('lightbox-cover'), lp = document.getElementById('lightbox-private');
    if (lc && file !== undefined) {
      const on = !!ownerState.cover[file];
      lc.classList.toggle('on', on); lc.title = on ? 'This is the folder’s cover photo' : 'Make this the folder’s cover photo'; lc.setAttribute('aria-label', lc.title);
    }
    if (lp && file !== undefined) {
      const on = !!ownerState.priv[file];
      lp.classList.toggle('on', on); lp.classList.toggle('folder', lockKind(file) === 'inh'); lp.title = lockTitle(file); lp.setAttribute('aria-label', lp.title);
      lp.innerHTML = '<svg class="ico" aria-hidden="true"><use href="#i-' + (on ? 'lock' : 'unlock') + '"></use></svg>';
    }
  }
  document.addEventListener('click', function (e) {
    const btn = e.target.closest ? e.target.closest('.priv-toggle, .cover-toggle, [data-owner]') : null;
    if (!btn || !ownerState) return;
    e.preventDefault(); e.stopPropagation();
    if (btn.classList.contains('busy')) return;
    const act = btn.hasAttribute('data-owner') ? btn.getAttribute('data-owner') : (btn.classList.contains('cover-toggle') ? 'cover' : 'private');
    const file = btn.hasAttribute('data-owner') ? files[current] : btn.getAttribute('data-file');
    const slash = file.lastIndexOf('/'), folder = slash < 0 ? '' : file.slice(0, slash), bare = file.slice(slash + 1);
    const say = function (text) {
      const t = document.getElementById(lightbox.classList.contains('open') ? 'lightbox-toast' : 'owner-toast'); if (!t) return;
      t.textContent = text; t.hidden = false; clearTimeout(say.timer); say.timer = setTimeout(function () { t.hidden = true; }, 3500);
    };
    const nextState = act === 'private' ? nextOwn(file) : '';
    if (act === 'cover' && ownerState.cover[file]) { say('Already this folder’s cover photo'); return; }
    btn.classList.add('busy');
    const base = '?admin' + (folder ? '&folder=' + encodeURIComponent(folder) : '');
    fetch('?admin&csrf=1', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { if ((r.headers.get('content-type') || '').indexOf('json') < 0) throw new Error('signed out'); return r.json(); })
      .then(function (j) {
        const body = new URLSearchParams();
        body.set('csrf_token', j.token); body.set('file', bare);
        if (act === 'cover') body.set('action', 'set_cover');
        else { body.set('action', 'set_item_flag'); body.set('flag', 'private'); body.set('state', nextState); }
        return fetch(base, { method: 'POST', credentials: 'same-origin', body: body.toString(), headers: { 'X-Requested-With': 'gallery-fetch', 'Content-Type': 'application/x-www-form-urlencoded' } });
      })
      .then(function (r) { if ((r.headers.get('content-type') || '').indexOf('json') < 0) throw new Error('signed out'); return r.json(); })
      .then(function (j) {
        if (!j.ok) throw new Error(j.text || 'not saved');
        if (act === 'cover') {
          files.forEach(function (f) { if (folderOf(f) === folder) ownerState.cover[f] = false; });
          ownerState.cover[file] = true;
          say('Now the folder’s cover photo');
        } else {
          setOwn(file, nextState);
          say(lockSaid(file, nextState));
        }
        ownerPaint();
      })
      .catch(function (err) { say(err && err.message === 'signed out' ? 'Your admin session ended: sign in again' : 'Not saved: ' + (err && err.message ? err.message : 'try again')); })
      .then(function () { btn.classList.remove('busy'); });
  }, true);
<?php if (is_admin_browser()): ?>
  // Select: the owner picks photos with a tap and acts on all of them: make them private or public in one go, and (where the photos are
  // laid out one by one) place them next to each other by holding one and dragging it beside another (on a touch screen a long press, so
  // scrolling still works). The server keeps each placement as a stack that follows its anchor through every sort; the page reloads to
  // show a placement. Privacy changes are made in place.
  (function () {
    const btn = document.getElementById('select-btn'), bar = document.getElementById('select-bar'), gal = document.querySelector('.gallery');
    if (!btn || !bar || !ownerState) return;
    const pageFolder = <?= json_encode($currentFolderPath === null ? '' : $currentFolderPath) ?>;
    const stacks = <?= json_encode($arrangeStacks) ?>;
    const arrRow = bar.querySelector('.select-arr');
    const loose = function () { return gal ? [].slice.call(gal.querySelectorAll(':scope > .card[data-file]')) : []; };
    const canArrange = !!arrRow && loose().length >= 2;   // photos shown one by one here, not only inside group boxes
    if (arrRow && !canArrange) arrRow.hidden = true;
    const stackOf = {};
    stacks.forEach(function (list, i) { list.forEach(function (f) { stackOf[f] = i; }); });
    let on = false, press = null, drag = null, swallow = false, pointer = { x: 0, y: 0 }, scrollTimer = 0, busy = false;
    const picked = [];   // the picked files, in the order they were picked
    const cards = function () { return [].slice.call(document.querySelectorAll('.card[data-file]')); };
    const fileOf = function (c) { return c.getAttribute('data-file'); };
    const bare = function (f) { return f.slice(f.lastIndexOf('/') + 1); };
    const say = function (text) {
      const t = document.getElementById('owner-toast'); if (!t) return;
      t.textContent = text; t.hidden = false; clearTimeout(say.timer); say.timer = setTimeout(function () { t.hidden = true; }, 3500);
    };
    const count = document.getElementById('select-count');
    const paint = function () {
      cards().forEach(function (c) {
        const f = fileOf(c), i = stackOf[f];
        c.classList.toggle('sel', picked.indexOf(f) >= 0);
        c.classList.toggle('is-stacked', canArrange && i !== undefined);
        if (canArrange && i !== undefined) c.style.setProperty('--stack-hue', String((i * 67 + 215) % 360)); else c.style.removeProperty('--stack-hue');
      });
      const n = picked.length;
      count.textContent = n ? n + ' selected' : count.getAttribute('data-empty');
      bar.querySelector('[data-select=clear]').disabled = !n;
      bar.querySelector('[data-select=private]').disabled = !n || busy;
      bar.querySelector('[data-select=public]').disabled = !n || busy;
      bar.querySelector('[data-select=inherit]').disabled = !n || busy;
      // In a private folder (the photos picked, or else the ones on the page) the bar offers the three states of the lock.
      const first = cards()[0], ctx = picked.length ? picked[0] : (first ? fileOf(first) : null), inPriv = !!(ctx && ownerState.inh[ctx]);
      bar.classList.toggle('in-private', inPriv);
      bar.querySelector('[data-select=inherit]').hidden = !inPriv;
      if (canArrange) {
        bar.querySelector('[data-select=release]').disabled = !picked.some(function (f) { return stackOf[f] !== undefined; });
        bar.querySelector('[data-select=reset]').disabled = !stacks.length;
      }
    };
    const setOn = function (v) {
      on = v; document.body.classList.toggle('selecting', v); document.body.classList.toggle('can-arrange', v && canArrange); bar.hidden = !v;
      btn.setAttribute('aria-pressed', v ? 'true' : 'false');
      if (!v) picked.length = 0;
      paint();
    };
    const toggle = function (f) {
      const i = picked.indexOf(f);
      if (i >= 0) picked.splice(i, 1);
      else {
        if (picked.length && folderOf(picked[0]) !== folderOf(f)) { picked.length = 0; say('A selection stays within one folder'); }
        picked.push(f);
      }
      paint();
    };
    btn.addEventListener('click', function () { setOn(!on); });
    // The admin's own save (a token is fetched first).
    const request = function (folder, fields) {
      return fetch('?admin&csrf=1', { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { if ((r.headers.get('content-type') || '').indexOf('json') < 0) throw new Error('signed out'); return r.json(); })
        .then(function (j) {
          const body = new URLSearchParams(); body.set('csrf_token', j.token);
          Object.keys(fields).forEach(function (k) { body.set(k, fields[k]); });
          return fetch('?admin' + (folder ? '&folder=' + encodeURIComponent(folder) : ''), { method: 'POST', credentials: 'same-origin', body: body.toString(), headers: { 'X-Requested-With': 'gallery-fetch', 'Content-Type': 'application/x-www-form-urlencoded' } });
        })
        .then(function (r) { if ((r.headers.get('content-type') || '').indexOf('json') < 0) throw new Error('signed out'); return r.json(); })
        .then(function (j) { if (!j.ok) throw new Error(j.text || 'not saved'); return j; });
    };
    const fail = function (err) { say(err && err.message === 'signed out' ? 'Your admin session ended: sign in again' : 'Not saved: ' + (err && err.message ? err.message : 'try again')); };
    // A placement is saved, then the page reloads in the same place with the same photos picked.
    const send = function (folder, fields, keep) {
      request(folder, Object.assign({ action: 'arrange_items' }, fields)).then(function () {
        try { sessionStorage.setItem('gallery_select', JSON.stringify({ y: window.scrollY, keep: keep || [] })); } catch (e) {}
        location.reload();
      }).catch(fail);
    };
    const bulk = function (state) {
      if (!picked.length || busy) return;
      const sel = picked.slice(), folder = folderOf(sel[0]);
      busy = true; paint();
      request(folder, { action: 'bulk_item_flag', flag: 'private', state: state, files: JSON.stringify(sel.map(bare)) }).then(function (j) {
        sel.forEach(function (f) { setOwn(f, state); });
        ownerPaint();
        say(j.text || (sel.length + (state === 'private' ? ' now private' : (state === 'public' ? ' now public' : ' follow their folder'))));
      }).catch(fail).then(function () { busy = false; paint(); });
    };
    const selectAll = function () {
      const all = cards();
      if (!all.length) return;
      const folder = picked.length ? folderOf(picked[0]) : (all.some(function (c) { return folderOf(fileOf(c)) === pageFolder; }) ? pageFolder : folderOf(fileOf(all[0])));
      all.forEach(function (c) { const f = fileOf(c); if (folderOf(f) === folder && picked.indexOf(f) < 0) picked.push(f); });
      paint();
    };
    bar.addEventListener('click', function (e) {
      const b = e.target.closest('[data-select]'); if (!b || b.disabled) return;
      const what = b.getAttribute('data-select');
      if (what === 'done') setOn(false);
      else if (what === 'all') selectAll();
      else if (what === 'clear') { picked.length = 0; paint(); }
      else if (what === 'private') bulk('private');
      else if (what === 'public') bulk('public');
      else if (what === 'inherit') bulk('inherit');
      else if (what === 'release') {
        const sel = picked.filter(function (f) { return stackOf[f] !== undefined; });
        send(folderOf(sel[0]), { mode: 'release', files: JSON.stringify(sel.map(bare)) }, picked.slice());
      } else if (what === 'reset') {
        const folder = picked.length ? folderOf(picked[0]) : pageFolder;
        if (confirm('Put every photo of this folder back in its sorted place?')) send(folder, { mode: 'reset' }, []);
      }
    });
    // Tapping a tile picks it instead of opening it.
    document.addEventListener('click', function (e) {
      if (!on) return;
      const c = e.target.closest ? e.target.closest('.card[data-file]') : null;
      if (!c) return;
      e.preventDefault(); e.stopPropagation();
      if (swallow) return;
      toggle(fileOf(c));
    }, true);
    document.addEventListener('contextmenu', function (e) { if (on && e.target.closest && e.target.closest('.card[data-file]')) e.preventDefault(); });
    // Dragging (only where the photos are laid out one by one)
    const line = document.createElement('div'); line.className = 'arrange-line'; line.hidden = true; document.body.appendChild(line);
    const begin = function () {
      const c = press.card, f = fileOf(c);
      if (picked.indexOf(f) < 0) { picked.length = 0; picked.push(f); paint(); }
      const set = cards().filter(function (x) { return picked.indexOf(fileOf(x)) >= 0; });
      const ghost = document.createElement('div'); ghost.className = 'arrange-ghost';
      ghost.innerHTML = '<img alt="" src="' + c.querySelector('img').currentSrc.replace(/"/g, '') + '">' + (set.length > 1 ? '<b>' + set.length + '</b>' : '');
      document.body.appendChild(ghost);
      set.forEach(function (x) { x.classList.add('dragging'); });
      drag = { set: set, ghost: ghost, folder: folderOf(f), target: null, side: 'after' };
      swallow = true; clearTimeout(press.timer);
      place(); loop();
    };
    const place = function () {
      if (!drag) return;
      drag.ghost.style.transform = 'translate(' + (pointer.x + 12) + 'px,' + (pointer.y + 12) + 'px)';
      let best = null, bestD = 1e9;
      loose().forEach(function (c) {
        if (drag.set.indexOf(c) >= 0 || folderOf(fileOf(c)) !== drag.folder) return;
        const r = c.getBoundingClientRect();
        const dx = Math.max(r.left - pointer.x, 0, pointer.x - r.right), dy = Math.max(r.top - pointer.y, 0, pointer.y - r.bottom), d = Math.hypot(dx, dy);
        if (d < bestD) { bestD = d; best = c; }
      });
      if (!best || bestD > 140) { drag.target = null; line.hidden = true; return; }
      const r = best.getBoundingClientRect();
      drag.target = best; drag.side = pointer.x < r.left + r.width / 2 ? 'before' : 'after';
      line.style.left = (drag.side === 'before' ? r.left - 6 : r.right + 2) + 'px'; line.style.top = r.top + 'px'; line.style.height = r.height + 'px'; line.hidden = false;
    };
    const loop = function () {   // scroll while the pointer is near the top or bottom edge
      cancelAnimationFrame(scrollTimer);
      if (!drag) return;
      const h = window.innerHeight, top = 80, low = h - 190;   // (the bar sits at the bottom)
      const dy = pointer.y < top ? -(top - pointer.y) / 6 : (pointer.y > low ? (pointer.y - low) / 6 : 0);
      if (dy) { window.scrollBy(0, dy); place(); }
      scrollTimer = requestAnimationFrame(loop);
    };
    const finish = function (drop) {
      clearTimeout(press && press.timer);
      const d = drag; drag = null; press = null; cancelAnimationFrame(scrollTimer);
      if (!d) return;
      d.ghost.remove(); line.hidden = true;
      d.set.forEach(function (x) { x.classList.remove('dragging'); });
      setTimeout(function () { swallow = false; }, 0);
      if (drop && d.target) {
        const names = d.set.map(function (x) { return bare(fileOf(x)); });
        send(d.folder, { mode: 'place', files: JSON.stringify(names), target: bare(fileOf(d.target)), side: d.side }, d.set.map(fileOf));
      }
    };
    document.addEventListener('pointerdown', function (e) {
      if (!on || !canArrange || (e.pointerType === 'mouse' && e.button !== 0)) return;
      const c = e.target.closest('.card[data-file]'); if (!c || c.parentNode !== gal) return;
      pointer = { x: e.clientX, y: e.clientY };
      press = { card: c, x: e.clientX, y: e.clientY, id: e.pointerId, touch: e.pointerType !== 'mouse', timer: 0 };
      if (press.touch) press.timer = setTimeout(function () { if (press && !drag) begin(); }, 380);
    });
    document.addEventListener('pointermove', function (e) {
      pointer = { x: e.clientX, y: e.clientY };
      if (drag) { place(); return; }
      if (!press || e.pointerId !== press.id) return;
      if (Math.hypot(e.clientX - press.x, e.clientY - press.y) > (press.touch ? 10 : 6)) {
        if (press.touch) { clearTimeout(press.timer); press = null; } else begin();
      }
    });
    document.addEventListener('pointerup', function () { if (drag) finish(true); else { clearTimeout(press && press.timer); press = null; } });
    document.addEventListener('pointercancel', function () { finish(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && on) { if (drag) finish(false); else if (picked.length) { picked.length = 0; paint(); } } });
    // Holding a tile on a touch screen must not scroll the page once the drag has begun.
    document.addEventListener('touchmove', function (e) { if (drag) e.preventDefault(); }, { passive: false });
    let back = null;
    try { back = JSON.parse(sessionStorage.getItem('gallery_select') || 'null'); sessionStorage.removeItem('gallery_select'); } catch (e) {}
    if (back) {
      setOn(true);
      (back.keep || []).forEach(function (f) { if (cards().some(function (c) { return fileOf(c) === f; })) picked.push(f); });
      paint();
      window.scrollTo(0, back.y || 0);
    }
  })();
  // The owner's editor, in the viewer: caption, information link, place and the Private / Location / Cover switches of the photo
  // being looked at. Nothing needs a Save button: the switches save the moment they are pressed; the text saves when you leave a
  // field, a second after you stop typing, and when you move to another photo or close the editor. On a phone it is a sheet under
  // the photo (the photo stays in view above it, above the keyboard too); on a wide screen a panel beside the photo.
  lbEdit = (function () {
    const ed = document.getElementById('lb-editor');
    if (!ed || !ownerState) return null;
    const $ = function (id) { return document.getElementById(id); };
    const f = { caption: $('lbe-caption'), web: $('lbe-web'), lat: $('lbe-lat'), lng: $('lbe-lng'), label: $('lbe-label') };
    const pills = { priv: $('lbe-private'), loc: $('lbe-loc'), cover: $('lbe-cover') };
    const statusEl = $('lbe-status'), nameEl = $('lbe-name'), noteEl = $('lbe-note'), webErr = $('lbe-web-err'), locErr = $('lbe-loc-err');
    const useAuto = $('lbe-useauto'), locRow = $('lbe-locrow'), locSum = $('lbe-locsum'), hideKb = $('lbe-hidekb'), toolBtn = $('lightbox-edit');
    const sheetMq = window.matchMedia ? window.matchMedia('(max-width: 700px), (hover: none) and (pointer: coarse) and (orientation: portrait)') : { matches: false };
    const MAPON = <?= ENABLE_MAP ? 'true' : 'false' ?>, LOCON = <?= ENABLE_PHOTO_LOCATION ? 'true' : 'false' ?>;
    let isOpen = false, st = null, seq = 0, token = null;
    const bareOf = function (file) { return file.slice(file.lastIndexOf('/') + 1); };
    const jsonOf = function (r) { if ((r.headers.get('content-type') || '').indexOf('json') < 0) throw new Error('signed out'); return r.json(); };
    const setStatus = function (kind, text) {
      statusEl.className = 'lbe-status' + (kind ? ' ' + kind : '');
      statusEl.textContent = '';
      if (kind === 'ok') statusEl.innerHTML = '<svg class="ico" aria-hidden="true"><use href="#i-check"></use></svg> ';
      statusEl.appendChild(document.createTextNode(text || ''));
    };
    const problem = function (err) { setStatus('err', err && err.message === 'signed out' ? 'Your admin session ended: sign in again' : (err && err.message ? err.message : 'Not saved: try again')); };
    const csrf = function () {
      if (token) return Promise.resolve(token);
      return fetch('?admin&csrf=1', { credentials: 'same-origin', cache: 'no-store' }).then(jsonOf).then(function (j) { token = j.token; return token; });
    };
    const post = function (file, fields) {
      return csrf().then(function (tok) {
        const body = new URLSearchParams(); body.set('csrf_token', tok);
        Object.keys(fields).forEach(function (k) { body.set(k, fields[k]); });
        const folder = folderOf(file);
        return fetch('?admin' + (folder ? '&folder=' + encodeURIComponent(folder) : ''), { method: 'POST', credentials: 'same-origin', body: body.toString(), headers: { 'X-Requested-With': 'gallery-fetch', 'Content-Type': 'application/x-www-form-urlencoded' } });
      }).then(jsonOf);
    };
    const values = function () { return { caption: f.caption.value.trim(), web: f.web.value.trim(), lat: f.lat.value.trim(), lng: f.lng.value.trim(), label: f.label.value.trim() }; };
    const textDirty = function (s, v) { return v.caption !== s.base.caption || v.web !== s.base.web; };
    const locDirty = function (s, v) { return v.lat !== s.base.lat || v.lng !== s.base.lng || v.label !== s.base.label; };
    const isNum = function (x) { return x !== '' && isFinite(Number(x)); };
    // Marks what cannot be saved, and says so under the field; true when everything that changed is fine to save.
    const validate = function (s, v, quiet) {
      let ok = true;
      const bad = function (el, err, msg) { ok = false; if (!quiet) { el.classList.add('bad'); err.textContent = msg; } };
      if (!quiet) { f.web.classList.remove('bad'); webErr.textContent = ''; f.lat.classList.remove('bad'); f.lng.classList.remove('bad'); locErr.textContent = ''; }
      if (textDirty(s, v) && v.web !== '' && !/^https?:\/\/\S+$/i.test(v.web)) bad(f.web, webErr, 'The link needs to start with http:// or https://');
      if (locDirty(s, v) && (v.lat !== '' || v.lng !== '')) {
        if (!(isNum(v.lat) && isNum(v.lng) && Math.abs(Number(v.lat)) <= 90 && Math.abs(Number(v.lng)) <= 180)) { bad(f.lat, locErr, 'That is not a valid latitude and longitude.'); if (!quiet) f.lng.classList.add('bad'); }
      }
      return ok;
    };
    const paintPills = function () {
      if (!st) return;
      const set = function (el, on) { el.setAttribute('aria-pressed', on ? 'true' : 'false'); };
      const k = lockKind(st.file);
      set(pills.priv, k === 'own'); set(pills.loc, st.cfg.locationShown); set(pills.cover, !!ownerState.cover[st.file]);
      pills.priv.classList.toggle('is-folder', k === 'inh');
      pills.priv.innerHTML = '<svg class="ico" aria-hidden="true"><use href="#i-' + (k === 'own' || k === 'inh' ? 'lock' : 'unlock') + '"></use></svg> ' + (k === 'inh' ? 'Folder' : (k === 'pub' ? 'Public' : 'Private'));
      let note = '';
      if (k === 'inh') note = 'Private because its folder is. Tap to keep it private on its own.';
      else if (k === 'own' && ownerState.inh[st.file]) note = 'Kept private on its own. Tap to make it public.';
      else if (k === 'pub') note = 'Public, though its folder is private. Tap to follow the folder again.';
      else if (st.cfg.locationsOff) note = 'Locations are switched off for the whole gallery (Site settings), so none show yet.';
      noteEl.textContent = note; noteEl.hidden = note === '';
    };
    const paintLoc = function () {
      if (!st) return;
      const v = values();
      const place = v.lat !== '' && v.lng !== '';
      locSum.textContent = place ? v.lat + ', ' + v.lng + (v.label ? ' · ' + v.label : '') : 'No location';
      useAuto.hidden = !st.auto;
      if (st.auto) locSum.textContent = 'From the photo’s GPS, not saved yet';
    };
    const photoBadges = function (file, v) {
      const place = LOCON && v.lat !== '' && v.lng !== '';
      document.querySelectorAll('.card[data-file="' + cssEscape(file) + '"]').forEach(function (card) {
        const box = card.querySelector('.card-badges'); if (!box) return;
        box.querySelectorAll('.caption-badge, .location-badge, .web-badge').forEach(function (b) { b.remove(); });
        const made = [], svg = function (n) { return '<svg class="ico" aria-hidden="true"><use href="#i-' + n + '"></use></svg>'; };
        if (v.caption) {
          const s = document.createElement('span'); s.className = 'item-badge caption-badge'; s.title = v.caption; s.setAttribute('role', 'button'); s.tabIndex = 0; s.innerHTML = svg('caption');
          s.addEventListener('click', function (e) { toggleCaptionPopover(s, e); });
          s.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') toggleCaptionPopover(s, e); });
          made.push(s);
        }
        if (place) {
          const a = document.createElement('a'); a.className = 'item-badge location-badge'; a.target = '_blank'; a.rel = 'noopener noreferrer'; a.innerHTML = svg('pin');
          a.href = 'https://www.openstreetmap.org/?mlat=' + encodeURIComponent(v.lat) + '&mlon=' + encodeURIComponent(v.lng) + '#map=16/' + encodeURIComponent(v.lat) + '/' + encodeURIComponent(v.lng);
          a.title = v.label || (v.lat + ', ' + v.lng); a.addEventListener('click', function (e) { e.stopPropagation(); });
          made.push(a);
        }
        if (v.web) {
          const a = document.createElement('a'); a.className = 'item-badge web-badge'; a.target = '_blank'; a.rel = 'noopener noreferrer'; a.innerHTML = svg('globe');
          a.href = v.web; a.title = 'Information link: ' + v.web; a.addEventListener('click', function (e) { e.stopPropagation(); });
          made.push(a);
        }
        made.reverse().forEach(function (el) { box.insertBefore(el, box.firstChild); });
      });
    };
    // What the page itself shows for this photo (its tile badges, the viewer's caption line, link button and little map) follows a save.
    const applyLive = function (s, v) {
      const i = files.indexOf(s.file); if (i < 0) return;
      captions[i] = v.caption || null; webLinks[i] = v.web || null;
      if (MAPON) locations[i] = (LOCON && v.lat !== '' && v.lng !== '') ? [parseFloat(v.lat), parseFloat(v.lng)] : null;
      photoBadges(s.file, v);
      if (i === current) { paintCaption(); updateLightboxExtras(); }
    };
    // Saves what changed on the photo that is open. quiet: a pause in typing, which never nags about a half-typed link. Resolves true
    // when nothing is left unsaved (or nothing was wrong), false when something could not be saved.
    const flush = function (quiet) {
      const s = st; if (!s) return Promise.resolve(true);
      clearTimeout(s.timer);
      const v = values();
      const run = function () {
        const td = textDirty(s, v), ld = locDirty(s, v);
        if (!td && !ld) return true;
        if (s === st && !validate(s, v, !!quiet)) { if (!quiet) setStatus('err', 'Not saved yet'); return !!quiet; }
        if (s === st) setStatus('busy', 'Saving…');
        return csrf().then(function () {
          return post(s.file, { action: 'save_item', file: bareOf(s.file), loaded_hash: s.hash, caption: v.caption, web: v.web, lat: v.lat, lng: v.lng, loc_label: v.label, keep_loc: ld ? '0' : '1' });
        }).then(function (j) {
          if (!j.ok) { if (s === st) setStatus('err', j.stale ? 'Changed somewhere else: reload this page' : (j.text || 'Not saved')); return false; }
          s.hash = j.hash;
          s.base.caption = v.caption; s.base.web = v.web;
          if (ld) { s.base.lat = v.lat; s.base.lng = v.lng; s.base.label = v.label; s.auto = false; }
          applyLive(s, { caption: v.caption, web: v.web, lat: s.auto ? '' : s.base.lat, lng: s.auto ? '' : s.base.lng, label: s.base.label });
          if (s === st) { paintLoc(); setStatus('ok', 'Saved'); }
          return true;
        }).catch(function (err) { if (s === st) problem(err); return false; });
      };
      s.chain = s.chain.then(run, run);
      return s.chain;
    };
    const focusBad = function () { const b = ed.querySelector('input.bad'); if (b) { if (b === f.lat || b === f.lng) ed.classList.add('loc-open'); b.focus(); } };
    const beforeLeave = function () {
      if (!isOpen) return Promise.resolve(true);
      return flush(false).then(function (ok) { if (!ok) focusBad(); return ok; });
    };
    // Where the editor sits: a sheet under the photo on a phone, a panel beside it otherwise. The photo takes what is left, and
    // follows the part of the screen that is really visible (the keyboard takes the lower part of it on a phone).
    const layout = function () {
      if (!isOpen) return;
      const sheet = sheetMq.matches, vv = window.visualViewport, st2 = lightbox.style;
      lightbox.classList.toggle('lb-sheet', sheet); lightbox.classList.toggle('lb-side', !sheet);
      if (sheet && vv) { st2.setProperty('--lbh', vv.height + 'px'); st2.height = vv.height + 'px'; st2.top = vv.offsetTop + 'px'; st2.bottom = 'auto'; }
      else { st2.removeProperty('--lbh'); st2.removeProperty('height'); st2.removeProperty('top'); st2.removeProperty('bottom'); }
      st2.setProperty('--sheet-h', (sheet ? ed.offsetHeight : 0) + 'px');
      fitPhoto(sheet);
      if (typeof placeMiniMap === 'function') placeMiniMap();
    };
    // Under the sheet the photo is given its exact size (width and height together, from its own shape), rather than leaving the
    // browser to shrink it to a height limit: Safari keeps the width when it clamps the height, which squashes the picture.
    const photoEls = [lightboxImg, lightboxVideo];
    const clearPhoto = function () { photoEls.forEach(function (el) { ['width', 'height', 'maxWidth', 'maxHeight'].forEach(function (p) { el.style.removeProperty(p === 'maxWidth' ? 'max-width' : (p === 'maxHeight' ? 'max-height' : p)); }); }); };
    const fitPhoto = function (sheet) {
      clearPhoto();
      if (!sheet) return;
      const video = isVideoFile(files[current]), el = video ? lightboxVideo : lightboxImg;
      const nw = video ? el.videoWidth : el.naturalWidth, nh = video ? el.videoHeight : el.naturalHeight;
      if (!nw || !nh) return;   // (not loaded yet: this runs again when it is)
      const cs = getComputedStyle(lightbox), row = lightbox.querySelector('.caption-row'), rowFixed = row && getComputedStyle(row).position === 'fixed';
      const availW = lightbox.clientWidth * 0.9;
      const availH = lightbox.clientHeight - parseFloat(cs.paddingTop) - (parseFloat(lightbox.style.getPropertyValue('--sheet-h')) || 0) - (rowFixed || !row ? 8 : row.offsetHeight + 24);
      if (availW <= 0 || availH <= 0) return;
      let w = Math.min(nw, availW), h = w * nh / nw;
      if (h > availH) { h = availH; w = h * nw / nh; }
      el.style.width = Math.round(w) + 'px'; el.style.height = Math.round(h) + 'px'; el.style.maxWidth = 'none'; el.style.maxHeight = 'none';
    };
    const setControls = function (enabled) {
      [].forEach.call(ed.querySelectorAll('input, .lbe-pill, #lbe-useauto'), function (el) { el.disabled = !enabled; });
    };
    const load = function () {
      const file = files[current], my = ++seq;
      st = null; setControls(false); clearPhoto();
      nameEl.textContent = bareOf(file);
      setStatus('busy', 'Loading…');
      [f.web, f.lat, f.lng].forEach(function (el) { el.classList.remove('bad'); }); webErr.textContent = ''; locErr.textContent = '';
      fetch('?admin&item=' + encodeURIComponent(file), { credentials: 'same-origin', cache: 'no-store' }).then(jsonOf).then(function (j) {
        if (my !== seq || !isOpen) return;
        if (!j.ok) throw new Error(j.text || 'Could not load this photo');
        const auto = !!(j.auto && j.lat === '' && j.lng === ''), shown = auto ? j.auto : j;
        f.caption.value = j.caption; f.web.value = j.web; f.lat.value = shown.lat; f.lng.value = shown.lng; f.label.value = j.label;
        st = { file: file, hash: j.hash, auto: auto, cfg: { private: !!j.private, own: j.own || '', locationShown: !!j.locationShown, folderPrivate: !!j.folderPrivate, locationsOff: !!j.locationsOff },
               base: { caption: j.caption, web: j.web, lat: shown.lat, lng: shown.lng, label: j.label }, timer: 0, chain: Promise.resolve(true) };
        ownerState.own[file] = st.cfg.own; ownerState.inh[file] = st.cfg.folderPrivate; ownerState.priv[file] = st.cfg.private; if (j.cover) ownerState.cover[file] = true;
        ownerPaint(); setControls(true); paintPills(); paintLoc(); setStatus('', '');
      }).catch(function (err) { if (my === seq) problem(err); });
    };
    const open = function () {
      if (isOpen) return;
      isOpen = true; ed.hidden = false;
      if (zoomMode !== 'fit') setZoom('fit');
      lightbox.classList.add('editing');
      ed.classList.toggle('loc-open', !sheetMq.matches);
      locRow.setAttribute('aria-expanded', ed.classList.contains('loc-open') ? 'true' : 'false');
      if (toolBtn) toolBtn.classList.add('on');
      layout(); load(); mapEditStart();
    };
    const close = function () {
      if (!isOpen) return;
      flush(false);
      isOpen = false; seq++; ed.hidden = true;
      if (ed.contains(document.activeElement)) document.activeElement.blur();
      ed.classList.remove('typing', 'kb');
      lightbox.classList.remove('editing', 'lb-sheet', 'lb-side');
      ['--lbh', '--sheet-h', 'height', 'top', 'bottom'].forEach(function (p) { lightbox.style.removeProperty(p); });
      clearPhoto();
      if (toolBtn) toolBtn.classList.remove('on');
      mapEditEnd();
      if (typeof placeMiniMap === 'function') placeMiniMap();
    };
    // The text fields
    ['caption', 'web', 'lat', 'lng', 'label'].forEach(function (k) {
      f[k].addEventListener('input', function () {
        if (!st) return;
        const s = st, v = values();
        paintLoc();
        if (k === 'web') { f.web.classList.remove('bad'); webErr.textContent = ''; }
        if (k === 'lat' || k === 'lng') { f.lat.classList.remove('bad'); f.lng.classList.remove('bad'); locErr.textContent = ''; }
        clearTimeout(s.timer);
        if (textDirty(s, v) || locDirty(s, v)) { setStatus('', 'Unsaved changes'); s.timer = setTimeout(function () { flush(true); }, 1000); }
        else setStatus('', '');
      });
      f[k].addEventListener('blur', function () { flush(false); });
      f[k].addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); f[k].blur(); } });
    });
    useAuto.addEventListener('click', function () {
      if (!st || !st.auto) return;
      st.base.lat = ''; st.base.lng = '';   // (so the place in the fields counts as a change, and is kept)
      flush(false);
    });
    locRow.addEventListener('click', function () {
      const openNow = ed.classList.toggle('loc-open');
      locRow.setAttribute('aria-expanded', openNow ? 'true' : 'false');
      layout();
    });
    // The three switches save as they are pressed.
    const flag = function (which, flagName) {
      pills[which].addEventListener('click', function () {
        if (!st) return;
        const s = st, on = !(flagName === 'private' ? s.cfg.private : s.cfg.locationShown), next = flagName === 'private' ? nextOwn(s.file) : '';
        setStatus('busy', 'Saving…');
        const fields = { action: 'set_item_flag', file: bareOf(s.file), flag: flagName };
        if (flagName === 'private') fields.state = next; else fields.on = on ? '1' : '0';
        post(s.file, fields).then(function (j) {
          if (!j.ok) throw new Error(j.text || 'Not saved');
          if (flagName === 'private') { setOwn(s.file, next); s.cfg.private = ownerState.priv[s.file]; s.cfg.own = ownerState.own[s.file]; ownerPaint(); } else s.cfg.locationShown = on;
          if (s === st) { paintPills(); setStatus('ok', 'Saved'); }
        }).catch(function (err) { if (s === st) problem(err); });
      });
    };
    flag('priv', 'private'); flag('loc', 'location');
    pills.cover.addEventListener('click', function () {
      if (!st) return;
      const s = st;
      if (ownerState.cover[s.file]) { setStatus('ok', 'Already this folder’s cover'); return; }
      setStatus('busy', 'Saving…');
      post(s.file, { action: 'set_cover', file: bareOf(s.file) }).then(function (j) {
        if (!j.ok) throw new Error(j.text || 'Not saved');
        const folder = folderOf(s.file);
        files.forEach(function (x) { if (folderOf(x) === folder) ownerState.cover[x] = false; });
        ownerState.cover[s.file] = true; ownerPaint();
        if (s === st) { paintPills(); setStatus('ok', 'Now the folder’s cover'); }
      }).catch(function (err) { if (s === st) problem(err); });
    });
    // Moving on, finishing
    $('lbe-prev').addEventListener('click', function () { navigate(-1); });
    $('lbe-next').addEventListener('click', function () { navigate(1); });
    $('lbe-done').addEventListener('click', function () { beforeLeave().then(function (ok) { if (ok) close(); }); });
    // These buttons must not take the focus from a field (the sheet would change shape under the finger before the press lands).
    ['pointerdown', 'mousedown'].forEach(function (t) { ed.querySelector('.lbe-foot').addEventListener(t, function (e) { if (e.target.closest('button')) e.preventDefault(); }); });
    ['pointerdown', 'mousedown'].forEach(function (t) { hideKb.addEventListener(t, function (e) { e.preventDefault(); if (ed.contains(document.activeElement)) document.activeElement.blur(); }); });
    hideKb.addEventListener('click', function () { if (ed.contains(document.activeElement)) document.activeElement.blur(); });
    // A phone's keyboard: while a text field has it, the sheet shows just the fields (the photo keeps as much room as possible).
    // (Not at the instant of the tap: the click that follows it must still land on the field, and the sheet changing shape under it would
    // send that click to the photo behind, which puts the keyboard straight away again.)
    let focusAt = 0, kbTimer = 0;
    ed.addEventListener('focusin', function (e) {
      if (!sheetMq.matches || !e.target.matches || !e.target.matches('input')) return;
      focusAt = Date.now();
      const field = e.target;
      clearTimeout(kbTimer);
      kbTimer = setTimeout(function () {
        if (document.activeElement !== field) return;
        ed.classList.add('kb'); ed.classList.toggle('typing', field === f.caption || field === f.web); layout();
      }, 320);
    });
    ed.addEventListener('focusout', function () {
      setTimeout(function () { const a = document.activeElement; if (!(a && ed.contains(a) && a.matches && a.matches('input'))) { ed.classList.remove('kb', 'typing'); layout(); } }, 250);
    });
    // What happens on the touch screen inside the editor stays there: it must not swipe the viewer to another photo or close it.
    ['touchstart', 'touchend'].forEach(function (t) { ed.addEventListener(t, function (e) { e.stopPropagation(); }, { passive: true }); });
    lightboxImg.addEventListener('load', layout); lightboxVideo.addEventListener('loadedmetadata', layout);
    // Pulling the sheet down by its handle (or its title row) closes it, like Done: it follows the finger, and past a short distance (or
    // a quick flick) it saves and goes; otherwise it springs back.
    let pull = null;
    const settle = function () { ed.style.transition = ''; ed.style.transform = ''; };
    const pullStart = function (e) {
      if (!sheetMq.matches || !isOpen || (e.pointerType === 'mouse' && e.button !== 0) || (e.target.closest && e.target.closest('button'))) return;
      pull = { id: e.pointerId, y: e.clientY, t: Date.now(), dy: 0 };
      try { ed.setPointerCapture(e.pointerId); } catch (x) {}
      ed.style.transition = 'none';
    };
    [$('lbe-grip'), ed.querySelector('.lbe-head')].forEach(function (el) { el.addEventListener('pointerdown', pullStart); });
    ed.addEventListener('pointermove', function (e) {
      if (!pull || e.pointerId !== pull.id) return;
      pull.dy = Math.max(0, e.clientY - pull.y);
      ed.style.transform = 'translateY(' + pull.dy + 'px)';
    });
    const pullEnd = function (e) {
      if (!pull || e.pointerId !== pull.id) return;
      const p = pull; pull = null;
      const quick = p.dy / Math.max(1, Date.now() - p.t) > 0.6;
      ed.style.transition = 'transform .18s ease-out';
      if (e.type === 'pointerup' && (p.dy > 90 || (p.dy > 40 && quick))) {
        ed.style.transform = 'translateY(' + ed.offsetHeight + 'px)';
        beforeLeave().then(function (ok) { if (ok) { close(); settle(); } else settle(); });
      } else ed.style.transform = '';
      setTimeout(function () { if (!pull && isOpen) ed.style.transition = ''; }, 220);
    };
    ed.addEventListener('pointerup', pullEnd); ed.addEventListener('pointercancel', pullEnd);
    if (window.ResizeObserver) new ResizeObserver(layout).observe(ed);
    if (window.visualViewport) { window.visualViewport.addEventListener('resize', layout); window.visualViewport.addEventListener('scroll', layout); }
    window.addEventListener('resize', layout);
    if (toolBtn) toolBtn.addEventListener('click', function () { if (isOpen) beforeLeave().then(function (ok) { if (ok) close(); }); else open(); });
    return { open: open, close: close, load: load, beforeLeave: beforeLeave, isOpen: function () { return isOpen; }, justFocused: function () { return Date.now() - focusAt < 900; }, blur: function () { if (ed.contains(document.activeElement)) document.activeElement.blur(); } };
  })();
  // The pencil on a tile opens the viewer on that photo with the editor up.
  document.addEventListener('click', function (e) {
    const b = e.target.closest ? e.target.closest('.edit-toggle') : null;
    if (!b || !lbEdit) return;
    e.preventDefault(); e.stopPropagation();
    const i = files.indexOf(b.getAttribute('data-file'));
    if (i < 0) return;
    openLightbox(i);
    lbEdit.open();
  }, true);
  // The banner on a private folder's page: pressing it asks (with the counts) whether to make the folder public; only a yes does.
  (function () {
    const banner = document.getElementById('folder-priv-banner'), modal = document.getElementById('fp-modal');
    if (!banner || !modal) return;
    const folder = banner.getAttribute('data-folder'), noun = banner.getAttribute('data-noun') || 'photo', $ = function (id) { return document.getElementById(id); };
    const many = function (k) { return k + ' ' + noun + (k === 1 ? '' : 's'); };
    const say = function (text) {
      const t = $('owner-toast'); if (!t) return;
      t.textContent = text; t.hidden = false; clearTimeout(say.timer); say.timer = setTimeout(function () { t.hidden = true; }, 3500);
    };
    const json = function (r) { if ((r.headers.get('content-type') || '').indexOf('json') < 0) throw new Error('signed out'); return r.json(); };
    const closeIt = function () { modal.hidden = true; banner.focus(); };
    const ask = function () {
      fetch('?admin&folder=' + encodeURIComponent(folder) + '&privcounts=1', { credentials: 'same-origin', cache: 'no-store' }).then(json).then(function (j) {
        const c = j.counts || { stay: 0, open: 0 };
        $('fp-stay').textContent = many(c.stay); $('fp-open').textContent = many(c.open);
        $('fp-stay-v').textContent = c.stay === 1 ? 'stays' : 'stay'; $('fp-open-v').textContent = c.open === 1 ? 'becomes' : 'become';
        modal.hidden = false; $('fp-cancel').focus();
      }).catch(function () { say('Your admin session ended: sign in again'); });
    };
    banner.addEventListener('click', ask);
    banner.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); ask(); } });
    $('fp-cancel').addEventListener('click', closeIt);
    modal.addEventListener('click', function (e) { if (e.target === modal) closeIt(); });
    modal.addEventListener('keydown', function (e) { if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closeIt(); } });
    $('fp-ok').addEventListener('click', function () {
      modal.hidden = true;
      fetch('?admin&csrf=1', { credentials: 'same-origin', cache: 'no-store' }).then(json).then(function (j) {
        const body = new URLSearchParams(); body.set('csrf_token', j.token); body.set('action', 'toggle_folder_restriction');
        return fetch('?admin&folder=' + encodeURIComponent(folder), { method: 'POST', credentials: 'same-origin', body: body.toString(), headers: { 'X-Requested-With': 'gallery-fetch', 'Content-Type': 'application/x-www-form-urlencoded' } });
      }).then(json).then(function (j) {
        if (!j.ok) throw new Error(j.text || 'not saved');
        say('This folder is public now'); setTimeout(function () { location.reload(); }, 500);
      }).catch(function (err) { say(err && err.message === 'signed out' ? 'Your admin session ended: sign in again' : 'Not saved: ' + (err && err.message ? err.message : 'try again')); banner.focus(); });
    });
  })();
<?php endif; ?>
  // The strip of buttons sticks to the top; once the page has scrolled under it, it gets a soft edge.
  (function () {
    const bar = document.getElementById('map-bar'); if (!bar) return;
    const edge = function () { bar.classList.toggle('stuck', window.scrollY > 0 && bar.getBoundingClientRect().top <= 0.5); };
    window.addEventListener('scroll', edge, { passive: true }); edge();
  })();
  // The row of sibling folders starts with the current one in view.
  (function () {
    const row = document.querySelector('.crumb-row'), cur = row && row.querySelector('.folder-pills .current'), back = row && row.querySelector('.breadcrumb');
    if (cur) row.scrollLeft = cur.offsetLeft - (back ? back.offsetWidth : 0) - (row.clientWidth - (back ? back.offsetWidth : 0) - cur.offsetWidth) / 2;
  })();
  // The archive always asks first, saying how many files and how big: it is a long, heavy download, and nothing is done on the server until the answer is yes.
  document.querySelectorAll('.page-archive').forEach(function (a) {
    a.addEventListener('click', function (e) {
      if (!confirm(a.dataset.ask + '?\n\nIt is sent as it is built, and a big one can take a while.')) e.preventDefault();
    });
  });
  const downloadLink = document.getElementById('lightbox-download');   // only on a page made for a signed-in visitor
  // The download button on a phone: the original goes to the share sheet, which offers "Save Image" / "Save Video" straight into
  // Photos (a plain download would land in Files). Where sharing a file isn't possible, or anything goes wrong, it is a plain
  // download as before. Safari only allows the share sheet right after a tap, so if the fetch took too long the button lights up
  // and the next tap opens it.
  if (downloadLink && navigator.share && navigator.canShare && (navigator.maxTouchPoints || 0) > 0) {
    let ready = null, toastTimer = null;
    const toast = document.getElementById('lightbox-toast');
    const say = function (text) {
      if (!toast) return;
      toast.textContent = text; toast.hidden = false;
      clearTimeout(toastTimer); toastTimer = setTimeout(function () { toast.hidden = true; }, 4000);
    };
    const plain = function (href) { const a = document.createElement('a'); a.href = href; a.download = ''; document.body.appendChild(a); a.click(); a.remove(); };
    downloadLink.addEventListener('click', function (e) {
      e.preventDefault();
      if (downloadLink.classList.contains('busy')) return;
      const href = downloadLink.getAttribute('href');
      if (ready && ready.href === href) {
        const file = ready.file; ready = null; downloadLink.classList.remove('ready');
        navigator.share({ files: [file] }).catch(function (err) { if (!err || err.name !== 'AbortError') plain(href); });
        return;
      }
      downloadLink.classList.add('busy'); say('Getting the original\u2026');
      // An ordinary photo on the screen IS the original file (the viewer shows it untouched), so the browser already holds
      // it: asking for that same address again is answered from its memory, at once, while the tap still counts. (A HEIC
      // photo is shown as a converted copy and a video isn't a picture, so those fetch the original.)
      const shown = files[current], fromMemory = !isVideoFile(shown) && !/\.hei[cf]$/i.test(shown) && lightboxImg.getAttribute('src');
      fetch(fromMemory || href, fromMemory ? { credentials: 'same-origin', cache: 'force-cache' } : { credentials: 'same-origin' })
        .then(function (r) {
          if (!r.ok) throw new Error('status');
          if ((Number(r.headers.get('content-length')) || 0) > 150 * 1024 * 1024) throw new Error('too big to hold in the browser');
          return r.blob();
        })
        .then(function (blob) {
          const name = decodeURIComponent((href.split('original=')[1] || 'photo').split('&')[0]).split('/').pop();
          const file = new File([blob], name, { type: blob.type || 'application/octet-stream' });
          if (!navigator.canShare({ files: [file] })) throw new Error('unsupported');
          toast && (toast.hidden = true);
          return navigator.share({ files: [file] }).catch(function (err) {
            if (err && err.name === 'AbortError') return;
            if (err && err.name === 'NotAllowedError') { ready = { href: href, file: file }; downloadLink.classList.add('ready'); say('Ready: tap the button again to save'); return; }
            throw err;
          });
        })
        .catch(function () { if (toast) toast.hidden = true; plain(href); })
        .then(function () { downloadLink.classList.remove('busy'); });
    });
  }
  function updateImage() {
    const file = files[current];
    if (isVideoFile(file)) {
      lightboxImg.style.display = 'none';
      lightboxImg.src = '';
      lightboxVideo.style.display = '';
      lightboxVideo.poster = <?= video_pictures_expected() ? 'true' : 'false' ?> ? '?img=' + encodeURIComponent(file) + '&size=share' + (fileVersions[current] ? '&v=' + fileVersions[current] : '') : '';
      lightboxVideo.src = '?video=' + encodeURIComponent(file) + (fileVersions[current] ? '&v=' + fileVersions[current] : '');
      lightboxVideo.load();
    } else {
      lightboxVideo.pause();
      lightboxVideo.style.display = 'none';
      lightboxVideo.src = '';
      lightboxImg.style.display = '';
      lightboxImg.src = '?img=' + encodeURIComponent(file) + '&size=full' + (fileVersions[current] ? '&v=' + fileVersions[current] : '');
    }
    paintCaption();
    if (downloadLink) { downloadLink.href = '?original=' + encodeURIComponent(file); downloadLink.classList.remove('ready'); }
    applyZoom();
    updateLightboxExtras();
    toolsFade();
    if (typeof ownerPaint === 'function') ownerPaint();
    if (lbEdit && lbEdit.isOpen()) lbEdit.load();
    trackView(file);
  }
  function paintCaption() {
    // Three parts, so a phone can put the counter first and let the caption give way (see .caption in the phone rules); read in order they are "caption — 3 / 42".
    const captionText = captions[current], set = siblingsOf(current);
    caption.textContent = '';
    [['cap-t', captionText || ''], ['cap-sep', captionText ? ' — ' : ''], ['cap-n', (set.indexOf(current) + 1) + ' / ' + set.length]].forEach(function (p) {
      const el = document.createElement('span'); el.className = p[0]; el.textContent = p[1]; caption.appendChild(el);
    });
    if (typeof toolsFade === 'function') toolsFade();
  }
  // The strip of buttons on a phone: a soft fade shows there is more to swipe to at either end. A swipe on it must not turn the page.
  const tools = document.getElementById('lightbox-tools');
  const toolsWrap = document.getElementById('lightbox-tools-wrap');
  function toolsFade() {
    if (!tools || !toolsWrap) return;
    const max = tools.scrollWidth - tools.clientWidth;
    toolsWrap.classList.toggle('can-right', max > 2 && tools.scrollLeft < max - 2);
    toolsWrap.classList.toggle('can-left', max > 2 && tools.scrollLeft > 2);
  }
  if (tools) {
    tools.addEventListener('scroll', toolsFade, { passive: true });
    ['touchstart', 'touchend'].forEach(function (name) { tools.addEventListener(name, function (e) { e.stopPropagation(); }, { passive: true }); });
    window.addEventListener('resize', toolsFade);
    // Whenever a button appears or goes (the link and place buttons by photo, the zoom button while the editor is up), or the viewer's own
    // state changes, look again: the fade must describe the strip as it is now.
    if (window.MutationObserver) {
      let queued = 0;
      const again = function () { if (!queued) queued = requestAnimationFrame(function () { queued = 0; toolsFade(); }); };
      new MutationObserver(again).observe(tools, { subtree: true, attributes: true, attributeFilter: ['hidden', 'class'] });
      new MutationObserver(again).observe(lightbox, { attributes: true, attributeFilter: ['class'] });
    }
  }
  // ---- Zoom: 'fit' (the whole photo on the screen), 'width' (fills the width of the window, scroll up and down) and
  // 'actual' (real pixels, scroll both ways). Always the original file at its own proportions, never stretched.
  let zoomMode = 'fit';
  const zoomBtn = document.getElementById('lightbox-zoom');
  const ZOOM_TEXT = { fit: 'Fill the width of the screen', width: 'Show at actual size', actual: 'Back to fitting the screen' };
  function sizeZoomImage() {
    const w = lightboxImg.naturalWidth;
    if (!w || !lightboxImg.style.setProperty) return;
    lightboxImg.style.setProperty('--nat', w + 'px');
    lightboxImg.style.setProperty('--act', (w / (window.devicePixelRatio || 1)) + 'px');
  }
  function applyZoom() {
    const video = isVideoFile(files[current]);
    lightbox.classList.toggle('is-video', video);
    lightbox.classList.toggle('zoom-width', !video && zoomMode === 'width');
    lightbox.classList.toggle('zoom-actual', !video && zoomMode === 'actual');
    if (zoomBtn) {
      zoomBtn.title = ZOOM_TEXT[zoomMode];
      zoomBtn.setAttribute('aria-label', ZOOM_TEXT[zoomMode]);
      zoomBtn.innerHTML = '<svg class="ico" aria-hidden="true"><use href="#i-' + (zoomMode === 'actual' ? 'zoom-out' : 'zoom-in') + '"></use></svg>';
    }
    lightbox.scrollTop = 0;
    lightbox.scrollLeft = 0;
    sizeZoomImage();
    if (typeof placeMiniMap === 'function') placeMiniMap();
  }
  function setZoom(mode) { zoomMode = mode; applyZoom(); }
  function cycleZoom() {
    if (isVideoFile(files[current]) || (lbEdit && lbEdit.isOpen())) return;
    let next = zoomMode === 'fit' ? 'width' : (zoomMode === 'width' ? 'actual' : 'fit');
    // Nothing more to see at real size when the photo is no wider than the window already shows it.
    if (next === 'actual' && lightboxImg.naturalWidth && lightboxImg.naturalWidth / (window.devicePixelRatio || 1) <= lightbox.clientWidth) next = 'fit';
    setZoom(next);
  }
  if (zoomBtn) zoomBtn.addEventListener('click', cycleZoom);
  lightboxImg.addEventListener('click', cycleZoom);
  lightboxImg.addEventListener('load', sizeZoomImage);
<?php if (ENABLE_MAP): ?>
  // ---- Maps: Leaflet is fetched only when a visitor asks for one (the overview's "Show map" button, or the little
  // map in the viewer), so a page that nobody opens a map on contacts no third party.
  let leafletQueue = null;
  function loadLeaflet(done) {
    if (window.L) { done(); return; }
    if (leafletQueue) { leafletQueue.push(done); return; }
    leafletQueue = [done];
    const css = document.createElement('link');
    css.rel = 'stylesheet';
    css.href = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.css';
    document.head.appendChild(css);
    const js = document.createElement('script');
    js.src = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.js';
    js.onload = function () { const queued = leafletQueue; leafletQueue = null; queued.forEach(function (f) { f(); }); };
    js.onerror = function () { leafletQueue = null; };
    document.head.appendChild(js);
  }
  function remembered(key, value) {
    try { if (value === undefined) return localStorage.getItem(key); localStorage.setItem(key, value); } catch (e) {}
    return null;
  }
  (function () {
    const btn = document.getElementById('map-toggle'), body = document.getElementById('map-preview-body');
    if (!btn || !body) return;
    const label = btn.querySelector('.map-toggle-label');
    if (!label) return;
    const noun = label.textContent.replace(/^Show /, '');
    let drawn = false;
    function set(open, fromLink) {
      body.hidden = !open;
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      label.textContent = (open ? 'Hide ' : 'Show ') + noun;
      btn.title = label.textContent;
      if (!fromLink) remembered('galleryMapOpen', open ? '1' : '0');   // (a link opening it is not the visitor's choice to keep it open)
      if (open && !drawn) {
        loadLeaflet(function () {
          drawn = true;
          const draw = window.__lazyMaps && window.__lazyMaps[body.getAttribute('data-map')];
          if (draw) draw();
        });
      }
    }
    btn.addEventListener('click', function () { set(body.hidden); });
    if (remembered('galleryMapOpen') === '1') set(true);
    // A link ending in #map opens the page with this small map showing (not the full-screen one, which is a lot to close
    // on a phone), and brings it into view.
    function peek() {
      body.classList.toggle('peek-hit', location.hash === '#map');
      if (location.hash !== '#map') return;
      if (body.hidden) set(true, true);
      const sec = document.getElementById('map-preview');
      if (sec && sec.scrollIntoView) sec.scrollIntoView({ block: 'start' });
    }
    peek();
    window.addEventListener('hashchange', peek);
    const peekShare = document.getElementById('map-peek-share');
    if (peekShare) peekShare.addEventListener('click', function () {
      copyToClipboard(location.origin + location.pathname + peekShare.getAttribute('data-href')).then(function () { flashCopied(peekShare); });
    });
  })();

  (function () {
    const modal = document.getElementById('map-modal'), opener = document.getElementById('map-full');
    if (!modal || !opener) return;
    const mapHref = opener.getAttribute('href');
    let drawn = false, pushed = false;
    function show() {
      modal.hidden = false;
      document.body.style.overflow = 'hidden';
      if (!drawn) {
        loadLeaflet(function () {
          drawn = true;
          const draw = window.__lazyMaps && window.__lazyMaps[modal.getAttribute('data-map')];
          if (draw) draw();
        });
      }
    }
    function hide() { modal.hidden = true; document.body.style.overflow = ''; }
    // The address bar follows the overlay (?map), so copying it shares the map; Back closes it.
    function open() {
      show();
      if (!/[?&]map\b/.test(location.search)) { history.pushState({ galleryMap: 1 }, '', mapHref); pushed = true; }
    }
    function close() {
      hide();
      if (pushed) { pushed = false; history.back(); }
      else if (/[?&]map\b/.test(location.search)) { history.replaceState(null, '', location.search.replace(/[?&]map\b(=[^&]*)?/, '').replace(/^&/, '?') || location.pathname); }
    }
    window.addEventListener('popstate', function () { if (!modal.hidden && !/[?&]map\b/.test(location.search)) { pushed = false; hide(); } });
    opener.addEventListener('click', function (e) {
      if (e.metaKey || e.ctrlKey || e.shiftKey || e.button === 1) return;   // "open in a new tab" still works
      e.preventDefault();
      open();
    });
    modal.addEventListener('click', function (e) {
      if (e.target === modal || (e.target.closest && e.target.closest('.map-modal-close'))) { close(); return; }
      const a = e.target.closest && e.target.closest('.leaflet-popup a[href^="#"]');
      if (a) close();   // a pin's link to something on this page: show it
    });
    const share = document.getElementById('map-share');
    if (share) share.addEventListener('click', function () {
      copyToClipboard(location.origin + location.pathname + share.getAttribute('data-href')).then(function () { flashCopied(share); });
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) close(); });
    if (<?= !empty($openMapOnLoad) ? 'true' : 'false' ?>) show();
  })();

  // ---- The little map in the viewer: where the open photo was taken, top left of the photo, about half a mile
  // across. Click it to enlarge and move around; the 📍 button beside the caption turns it off (and on again).
  const mapBox = document.getElementById('lightbox-map');
  const mapToggle = document.getElementById('lightbox-map-toggle');
  const webLink = document.getElementById('lightbox-web');
  let miniMap = null, miniMarker = null, miniExpanded = false;
  const MINI_ZOOM = 15;
  // On a phone the little map would cover a good part of the photo, so it starts off there (and stays wherever the viewer
  // puts it after that); the pin button beside the caption turns it on, and says so with a short pulse the first time.
  const phoneish = function () { return !!(window.matchMedia && window.matchMedia('(max-width: 700px), (hover: none) and (pointer: coarse)').matches); };
  function miniWanted() { const r = remembered('galleryLightboxMap'); return r === null || r === undefined ? !phoneish() : r !== '0'; }
  // While the owner's editor is up the map starts off (it would cover a photo made small for the editor) and the pin button turns it on
  // for as long as the editor is open, without changing what the viewer chose to keep.
  let editMap = false;
  function mapEditing() { return !!(lbEdit && lbEdit.isOpen()); }
  function miniShown() { return mapEditing() ? editMap : miniWanted(); }
  function mapEditStart() { editMap = false; updateLightboxExtras(); }
  function mapEditEnd() { editMap = false; updateLightboxExtras(); }
  function placeMiniMap() {
    if (!mapBox || mapBox.hidden || miniExpanded) return;
    if (zoomMode !== 'fit') { mapBox.style.left = '12px'; mapBox.style.top = '12px'; return; }
    const shown = lightboxImg.style.display === 'none' ? lightboxVideo : lightboxImg;
    const r = shown.getBoundingClientRect();
    if (mapEditing()) {   // smaller, so the photo stays mostly in view
      const w = Math.max(84, Math.min(170, Math.round(r.width * 0.42)));
      mapBox.style.width = w + 'px'; mapBox.style.height = Math.round(w * 0.76) + 'px';
      if (miniMap) miniMap.invalidateSize();
    } else { mapBox.style.width = ''; mapBox.style.height = ''; }
    mapBox.style.left = Math.round(Math.max(8, r.left + 10)) + 'px';
    mapBox.style.top = Math.round(Math.max(8, r.top + 10)) + 'px';
  }
  function setMiniInteractive(on) {
    if (!miniMap) return;
    ['dragging', 'scrollWheelZoom', 'doubleClickZoom', 'touchZoom', 'boxZoom', 'keyboard'].forEach(function (h) {
      if (miniMap[h]) { if (on) miniMap[h].enable(); else miniMap[h].disable(); }
    });
    if (miniMap.zoomControl) { if (on) miniMap.zoomControl.addTo(miniMap); else miniMap.zoomControl.remove(); }
  }
  function expandMiniMap() {
    if (miniExpanded || !miniMap) return;
    miniExpanded = true;
    mapBox.classList.add('expanded');
    mapBox.style.left = ''; mapBox.style.top = ''; mapBox.style.width = ''; mapBox.style.height = '';
    setMiniInteractive(true);
    miniMap.invalidateSize();
    const loc = locations[current];
    if (loc) {
      const link = mapBox.querySelector('.lbmap-osm');
      if (link) link.href = 'https://www.openstreetmap.org/?mlat=' + loc[0] + '&mlon=' + loc[1] + '#map=16/' + loc[0] + '/' + loc[1];
    }
  }
  function collapseMiniMap() {
    if (!miniExpanded) return;
    miniExpanded = false;
    if (mapBox) mapBox.classList.remove('expanded');
    setMiniInteractive(false);
    const loc = locations[current];
    if (miniMap && loc) { miniMap.invalidateSize(); miniMap.setView([loc[0], loc[1]], MINI_ZOOM, { animate: false }); }
    placeMiniMap();
  }
  function showMiniAt(loc) {
    if (!mapBox || mapBox.hidden || locations[current] !== loc) return;
    if (!miniMap) {
      miniMap = L.map(mapBox, { zoomControl: true, attributionControl: true });
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a>',
        maxZoom: 19,
      }).addTo(miniMap);
      miniMap.attributionControl.setPrefix(false);
      miniMarker = L.circleMarker([loc[0], loc[1]], { radius: 7, color: '#fff', weight: 2, fillColor: '#5865f2', fillOpacity: 0.95 }).addTo(miniMap);
      setMiniInteractive(false);
    }
    miniMarker.setLatLng([loc[0], loc[1]]);
    miniMap.invalidateSize();
    miniMap.setView([loc[0], loc[1]], MINI_ZOOM, { animate: false });
  }
  function updateLightboxExtras() {
    const web = webLinks[current];
    if (webLink) {
      if (web) { webLink.href = web; webLink.title = 'Information link: ' + web; webLink.hidden = false; } else { webLink.hidden = true; webLink.removeAttribute('href'); webLink.title = ''; }
    }
    if (!mapBox || !mapToggle) return;
    const loc = locations[current];
    mapToggle.hidden = !loc;
    mapToggle.classList.toggle('off', !miniShown());
    mapToggle.title = miniShown() ? 'Hide the little map' : 'Show where this was taken';
    mapToggle.classList.toggle('on', miniShown());
    // A reminder that the map is there, on every photo that has a location, for as long as the viewer hasn't pressed the button
    // (either way): a short pulse, restarted for each photo.
    mapToggle.classList.remove('nudge');
    if (loc && !miniShown() && !mapEditing() && remembered('galleryLightboxMap') === null) { void mapToggle.offsetWidth; mapToggle.classList.add('nudge'); }
    if (!loc || !miniShown()) { collapseMiniMap(); mapBox.hidden = true; return; }
    mapBox.hidden = false;
    placeMiniMap();
    loadLeaflet(function () { showMiniAt(loc); });
  }
  if (mapBox && mapToggle) {
    mapToggle.addEventListener('click', function () {
      if (mapEditing()) editMap = !editMap; else remembered('galleryLightboxMap', miniWanted() ? '0' : '1');
      mapToggle.classList.remove('nudge');
      updateLightboxExtras();
    });
    mapBox.addEventListener('click', function (e) {
      if (e.target.closest && e.target.closest('.lbmap-close')) { e.stopPropagation(); collapseMiniMap(); return; }
      if (e.target.closest && e.target.closest('.lbmap-osm')) return;
      if (!miniExpanded) expandMiniMap();
    });
    ['touchstart', 'touchend'].forEach(function (name) {
      mapBox.addEventListener(name, function (e) { e.stopPropagation(); }, { passive: true });
    });
    lightboxImg.addEventListener('load', placeMiniMap);
    lightboxVideo.addEventListener('loadedmetadata', placeMiniMap);
    window.addEventListener('resize', placeMiniMap);
  }
<?php else: ?>
  const webLink = document.getElementById('lightbox-web');
  const miniExpanded = false;
  function collapseMiniMap() {}
  function mapEditStart() {}
  function mapEditEnd() {}
  function updateLightboxExtras() {
    const web = webLinks[current];
    if (!webLink) return;
    if (web) { webLink.href = web; webLink.title = 'Information link: ' + web; webLink.hidden = false; } else { webLink.hidden = true; webLink.removeAttribute('href'); webLink.title = ''; }
  }
<?php endif; ?>
  document.addEventListener('keydown', (e) => {
    if (!lightbox.classList.contains('open')) return;
    if (lbEdit && lbEdit.isOpen()) {
      if (e.key === 'Escape') { lbEdit.close(); return; }
      if (e.target && e.target.closest && e.target.closest('#lb-editor')) return;   // typing: the arrows, Z and the rest belong to the field
    }
    if (e.key === 'Escape') { if (miniExpanded) { collapseMiniMap(); return; } if (zoomMode !== 'fit') { setZoom('fit'); return; } closeLightbox(); return; }
    if (e.key === 'z' || e.key === 'Z') { cycleZoom(); return; }
    // A focused <video>'s native controls already use arrow keys (seek)
    // and spacebar (play/pause) — don't also fire our own nav on top of
    // that, or an arrow press would both seek the clip and jump to a
    // different lightbox item at the same time.
    if (document.activeElement === lightboxVideo) return;
    if (e.key === 'ArrowLeft') navigate(-1);
    if (e.key === 'ArrowRight') navigate(1);
  });
  lightbox.addEventListener('click', (e) => {
    if (e.target !== lightbox) return;
    if (lbEdit && lbEdit.isOpen()) { if (!lbEdit.justFocused()) lbEdit.blur(); return; }   // (a tap beside the photo while editing only puts the keyboard away)
    closeLightbox();
  });

  // Touch gestures: swipe left/right to move between photos, swipe down to close.
  let touchStartX = 0;
  let touchStartY = 0;
  lightbox.addEventListener('touchstart', (e) => {
    const t = e.changedTouches[0];
    touchStartX = t.clientX;
    touchStartY = t.clientY;
  }, { passive: true });
  lightbox.addEventListener('touchend', (e) => {
    const t = e.changedTouches[0];
    const deltaX = t.clientX - touchStartX;
    const deltaY = t.clientY - touchStartY;
    const absX = Math.abs(deltaX);
    const absY = Math.abs(deltaY);
    const threshold = 50;
    if (zoomMode === 'actual') return;   // dragging pans the photo
    if (absX > absY && absX > threshold) {
      navigate(deltaX < 0 ? 1 : -1);
    } else if (absY > absX && deltaY > threshold && zoomMode === 'fit' && !(lbEdit && lbEdit.isOpen())) {
      closeLightbox();   // (when the photo fills the width, swiping down scrolls it instead)
    }
  }, { passive: true });

  // Clicking an item's 🔗 permalink icon copies its full URL to the
  // clipboard (in addition to the browser's normal anchor navigation —
  // it still jumps to and highlights the item as before).
  function legacyCopy(text) {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    try { document.execCommand('copy'); } catch (e) {}
    document.body.removeChild(ta);
  }
  function copyToClipboard(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text).catch(() => legacyCopy(text));
    }
    legacyCopy(text);
    return Promise.resolve();
  }
  function flashCopied(el) {
    const original = el.innerHTML;
    el.innerHTML = '<svg class="ico" aria-hidden="true"><use href="#i-check"></use></svg>';
    el.classList.add('copied');
    setTimeout(() => {
      el.innerHTML = original;
      el.classList.remove('copied');
    }, 1200);
  }
  document.addEventListener('click', (e) => {
    const link = e.target.closest('.permalink-copy');
    if (!link) return;
    // A .md item's or group box's permalink is a same-page #anchor; a
    // folder cover card's is a real "?folder=..." link instead (no
    // hash) — copy whichever one this button actually has.
    // A link made on a folder's page must keep pointing at that folder (and nothing else of the address, such as ?photo=).
    const folderQuery = () => { const f = new URLSearchParams(window.location.search).get('folder'); return f ? '?folder=' + encodeURIComponent(f) : ''; };
    const cleanUrl = link.hash
      ? window.location.origin + window.location.pathname + folderQuery() + link.hash
      : window.location.origin + window.location.pathname + link.getAttribute('href');
    copyToClipboard(cleanUrl).then(() => flashCopied(link));
  });

  // Copies a "?photo=" deep link to the photo currently open in the lightbox.
  const lightboxCopyBtn = document.getElementById('lightbox-copy-btn');
  lightboxCopyBtn.addEventListener('click', () => {
    const url = window.location.origin + window.location.pathname + '?photo=' + encodeURIComponent(files[current]);
    copyToClipboard(url).then(() => flashCopied(lightboxCopyBtn));
  });

  // ?photo=<filename> deep link: open straight to that photo on load.
  if (initialPhoto) {
    const idx = files.indexOf(initialPhoto);
    if (idx !== -1) {
      openLightbox(idx);
      if (lbEdit && /[?&]edit=/.test(location.search)) lbEdit.open();   // (the owner's link from the admin: that photo, with its editor)
    }
  }
</script>

</body>
</html>
