<?php
/**
 * DROP-IN PHOTO GALLERY
 * Single PHP file, no dependencies, no build step. Drop into any folder
 * of photos and it renders a gallery for that folder.
 *
 * License: MIT with the Commons Clause (use, change and share it freely;
 * do not sell it). The full text is the LICENSE file in the repository,
 * github.com/pcmike/gallery. Versions up to 2.4.1 were plain MIT.
 *
 * Full documentation (grouping, nested folders, video, captions and
 * locations, the admin editor, hiding items, the maps, Markdown
 * notes, {color}/{sold}/{reserved}/{group}/{photos} directives, view
 * stats, deep linking, image caching, etc.) lives in the repo's guide (docs/guide): github.com/pcmike/gallery. The
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
 *     PRIVATE_VIEW_PASSWORD / NGINX_ACCEL_REDIRECT_PREFIX /
 *     MAP_CLUSTER_RADIUS_MILES constants are just below, and are only the
 *     DEFAULTS: a .gallery-config.php beside this file overrides them and
 *     survives replacing this file with a newer version (see
 *     gallery_setting() below and the guide). TRACK_VIEWS,
 *     ENABLE_VIDEO and ENABLE_PHOTO_LOCATION are off by default
 *     (subfolders are on by default since 2.5.0) — this script writes nothing to disk
 *     (apart from thumbnails in .gallery-cache, what the admin saves and,
 *     once, the setup code file when ?admin is first opened with no password),
 *     makes no outbound/third-party requests of its own (a map, which only appears
 *     once photo locations are on, loads its code from a CDN and its tiles
 *     from OpenStreetMap when a visitor opens it; the country file,
 *     COUNTRY_LOOKUP, is the one thing the server fetches, only once
 *     switched on), and doesn't show a single
 *     video file or photo location unless you turn one on; subfolders
 *     show as albums unless you switch them off.
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

// Nothing here is for a stranger to read: no PHP version in a header, and a problem is written to the server's error log, never printed
// into the page (a printed one names folders on the server). An error nobody caught ends as a plain 500, not PHP's own page.
header_remove('X-Powered-By');
@ini_set('display_errors', '0');
set_exception_handler(function ($e) {
    error_log('gallery: uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store');
    }
    echo "Something went wrong. The details are in the server's error log.\n";
});

// Version shown on the "?about" page and stamped in the page source.
define('GALLERY_VERSION', '2.6.5');

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
    $GLOBALS['__gallery_defaults'][$name] = $default;   // what the admin's Settings shows as "default"
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
function loose_media_in_roots() {
    global $mediaExtensions;
    $found = [];
    foreach (EXTRA_ROOTS as $root) {
        if (!is_string($root) || !is_dir($root) || is_link($root)) continue;
        foreach ((@scandir($root) ?: []) as $entry) {
            if ($entry[0] !== '.' && is_file($root . '/' . $entry) && in_array(strtolower(pathinfo($entry, PATHINFO_EXTENSION)), $mediaExtensions, true)) $found[] = $root . '/' . $entry;
        }
    }
    return $found;
}
function count_loose_media_in_roots() { return count(loose_media_in_roots()); }

// Turn photo/group/page view tracking on or off. Off by default — this
// script writes nothing to the folder's disk unless you turn something
// on. Turning this on writes .gallery-stats.php or .gallery-stats.sqlite
// into the folder, and is what enables the live view-count badges. See
// the guide for details.
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
// 2.4.0: the same question a share link answers, minus what does not apply to a stranger (they only ever see public photos, and they get no
// ZIP): off, everything, or only some folders. A settings file from before has the old on/off switch; on reads as Everything until the
// new setting is chosen (then the old line is dropped, see site_settings_save()).
define('PUBLIC_DOWNLOAD_MODE', (function () {
    $legacy = gallery_setting('ENABLE_PUBLIC_DOWNLOAD', false);
    $mode = gallery_setting('PUBLIC_DOWNLOAD_MODE', 'off', ['off', 'all', 'some']);
    $mode = (!array_key_exists('PUBLIC_DOWNLOAD_MODE', gallery_config_load()['cfg']) && $legacy) ? 'all' : $mode;
    // 2.6.5: each folder stands on its own (a folder's switch covers the photos directly in it), so there is no "Everything" any more. A settings
    // file that still says so (or has the old on/off line) is read as Off: downloads are never on by accident. The line itself is left as it is until
    // a download switch is used (then it is replaced), so going back to an older version still finds it.
    return $mode === 'all' ? 'off' : $mode;
})());
define('PUBLIC_DOWNLOAD_FOLDERS', array_values(array_filter((array) gallery_setting('PUBLIC_DOWNLOAD_FOLDERS', []), 'is_string')));
define('PUBLIC_DOWNLOAD_ROOT', gallery_setting('PUBLIC_DOWNLOAD_ROOT', false) === true);   // (the photos lying directly on the gallery's main page)
// 2.6.5: location data is taken out of every original sent to a visitor (the viewer's full-size picture, a download, a video that plays, a Not
// listed photo's own address), not only where the gallery hides the location. On to start with; the owner, the private-view password and a share link
// set to show every location are always sent the file as it is. See visitor_scrub_plan.
define('SCRUB_LOCATION_DATA', gallery_setting('SCRUB_LOCATION_DATA', true));
define('ENABLE_PUBLIC_DOWNLOAD', PUBLIC_DOWNLOAD_MODE === 'some' && (PUBLIC_DOWNLOAD_FOLDERS !== [] || PUBLIC_DOWNLOAD_ROOT));   // (the question most of the code asks: is there a download button for strangers at all)

// Thumbnail generation/caching. On by default: without it every photo is
// served at full original resolution as its own "thumbnail" (real
// bandwidth cost — a page of phone photos can be hundreds of megabytes),
// and HEIC/HEIF (iPhone) photos can't be shown at all, since converting
// them needs somewhere to keep the result. It only ever writes small
// derived images into a .gallery-cache folder beside this file, exposes
// nothing that isn't already in the gallery, and if that folder can't be
// written (or GD/Imagick is missing) the original photo is served as
// before. Set to false for a gallery that must never write to its folder.
// See the guide for details.
define('ENABLE_THUMBNAIL_CACHE', gallery_setting('ENABLE_THUMBNAIL_CACHE', true));
// The two pictures a tile can show (size=tile and size=tilehd), both square cuts from the middle of the photo, which is all
// a tile ever shows of it: [side in pixels, JPEG quality]. A tile is 180 to 250 points wide (a phone in portrait with one
// column: about 350), so 560 is right for an ordinary screen and 1080 for a 3x phone.
/** The thumbnail cache's file of what the gallery has read from each file (when taken, where): see media_meta_read_file(). */
const MEDIA_META_FILE = 'meta1.php';

const TILE_SIZES = ['tile' => [560, 82], 'tilehd' => [1080, 80]];

// Auto-group photos by filename (stripping a trailing "_01"/"-02" style
// suffix, e.g. "dp104_01.jpg" + "dp104_02.jpg" -> "dp104"). Off by
// default. Turn this on if your photos actually use that multi-angle
// naming convention (e.g. a "for sale" listing shot from several
// angles) — leaving it on for an arbitrary folder of camera photos can
// falsely merge unrelated shots that just happen to share a numeric
// filename pattern (e.g. IMG_0234.jpg / IMG_0235.jpg from two different,
// unrelated photos). See the guide for details, and for how to define
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
// /trip/StTropez, etc. See the guide for FOLDER_DISPLAY_MODE and
// EXTRA_FOLDERS, which only matter once this is on.
define('AUTO_GROUP_BY_FOLDER', gallery_setting('AUTO_GROUP_BY_FOLDER', true));   // (on by default since 2.5.0: most people keep photos in folders)
// Folders the scan never enters (system, recycle and thumbnail folders that file managers and NAS boxes create), and the most one visit
// will scan: past either limit the rest is left out and the Health check says so.
const SCAN_SKIP_FOLDERS = ['@eaDir', '__MACOSX', '#recycle', '$RECYCLE.BIN', 'System Volume Information', 'Thumbs', 'node_modules', 'cgi-bin'];
const SCAN_MAX_FOLDERS = 2000;
const SCAN_MAX_FILES = 60000;

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
// Since 2.5.0 the style is Cover cards ('overview') or Flat ('flat'). The older words keep working: 'cards' is 'overview', and 'inline' is Flat
// with the folder names shown, which is how Inline always looked (one labelled box per folder).
define('FOLDER_DISPLAY_MODE', gallery_setting('FOLDER_DISPLAY_MODE', 'overview', ['overview', 'cards', 'flat', 'inline']));
// Flat's two switches: show each folder's name (a box for the folders directly under the page, a divider for those inside), and show
// each folder's notes under it (needs the names).
define('FOLDER_FLAT_NAMES', gallery_setting('FOLDER_FLAT_NAMES', FOLDER_DISPLAY_MODE === 'inline'));
define('FOLDER_FLAT_NOTES', gallery_setting('FOLDER_FLAT_NOTES', false) && FOLDER_FLAT_NAMES);
/** Folder names and folder notes in Flat for the page being built: the folder's own choice, else the gallery's. */
function flat_names_on() {
    return $GLOBALS['__flat_names'] ?? FOLDER_FLAT_NAMES;
}
function flat_notes_on() {
    return flat_names_on() && ($GLOBALS['__flat_notes'] ?? FOLDER_FLAT_NOTES);
}
/** Is the page being built shown Flat? The gallery's style, unless the page's folder says otherwise (set per page once the folder is known). */
function folder_style_flat() {
    return $GLOBALS['__folder_style_flat'] ?? in_array(FOLDER_DISPLAY_MODE, ['flat', 'inline'], true);
}

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
// The names each named place (an EXTRA_FOLDERS path) has been shown under: path => [names]. Kept by the admin's Settings whenever the list is
// saved, never typed by hand. A photo's views are recorded under the path as shown (so under the name), and its count is the sum over every name
// the place has had, so renaming a place (and renaming it back) loses nothing.
define('EXTRA_FOLDER_NAMES', gallery_setting('EXTRA_FOLDER_NAMES', []));

// Health check rows the owner has set aside: row key => how much there was at the time. A row comes back by itself if there is more than that.
define('HEALTH_SET_ASIDE', gallery_setting('HEALTH_SET_ASIDE', []));

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
// carry. See the guide for the real limitations this implies: codec
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
// set, ?admin has nothing to sign in to — the admin code path doesn't
// run at all — with one exception: it offers a one-time page to choose
// the password (the first request makes a small file, gallery-setup-code.php,
// beside this one; the page asks for the code in it, which only someone who
// can read the folder knows), and the choice is saved in the settings file. Set this to a long random token, not a
// memorable phrase — this is the one credential in this script that, if
// guessed, grants write access, so it deserves the strongest value you'd
// put in any constant here. See the guide for the HTTPS requirement and
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
// (Share links, below, are the other way to let someone in: a secret address that can be limited, paused, revoked and
// logged, whose page needs one press of a button before it counts as used, so a link-preview bot can't use one up.)
define('PRIVATE_VIEW_PASSWORD', gallery_setting('PRIVATE_VIEW_PASSWORD', ''));

// Share links: temporary addresses (https://example.com/gallery/k7QpR2mXv9LwTb4e) that sign a person in as a private viewer,
// limited by number of opens and by time, and optionally to chosen folders or photos. Off by default. Made and managed in
// the admin's Shares tab. A link is a secret in an address, so the page it opens asks for one press of a button
// before it counts as used (a chat app that fetches a link to draw its preview never uses it up), and a link can be
// paused, revoked or replaced at any time. See the guide.
define('ENABLE_SHARE_LINKS', gallery_setting('ENABLE_SHARE_LINKS', false));

// Whether a person using a share link sees "The link being used to access this media expires on ..." at the top of the
// gallery (a link with no end date never does). Each link can override this either way.
define('SHARE_HEADS_UP', gallery_setting('SHARE_HEADS_UP', false));

// Whether the gallery keeps a log of who opens its plain, public address (their IP address, browser and what they looked at),
// shown in the Visitors tab beside the share links' visitors. Off by default. The visitor log (this and the share links') is kept in
// SQLite, so it needs PHP's pdo_sqlite and a folder the web server may write to.
define('LOG_PUBLIC_VISITS', gallery_setting('LOG_PUBLIC_VISITS', false));
// How many days the visitor records (the public address's and every share link's) are kept; 0 keeps them until you trim the log.
define('LOG_KEEP_DAYS', max(0, (int) gallery_setting('LOG_KEEP_DAYS', 0)));
// Health warns when the visitor records together take more than this many megabytes on disk (0: no warning).
define('LOG_WARN_MB', max(0, (int) gallery_setting('LOG_WARN_MB', 100)));
// The Changes tab's log of disk activity: how many days its entries are kept (0: until trimmed), and the size, in megabytes, at which the Health check warns (0: never).
define('CHANGES_KEEP_DAYS', max(0, (int) gallery_setting('CHANGES_KEEP_DAYS', 0)));
define('CHANGES_WARN_MB', max(0, (int) gallery_setting('CHANGES_WARN_MB', 20)));

// Whether the public address is closed: only the owner, someone with the private-view password and someone who came in through a
// share link see anything; a stranger gets the page of a link that isn't valid. Off by default. It only takes effect while share
// links are on and an admin password is set (gallery_is_closed()), so a closed gallery can never lock everybody out.
define('CLOSE_PUBLIC_GALLERY', gallery_setting('CLOSE_PUBLIC_GALLERY', false));
if (CLOSE_PUBLIC_GALLERY && (!ENABLE_SHARE_LINKS || ADMIN_PASSWORD === '')) {
    $GLOBALS['__gallery_config_problems'][] = ".gallery-config.php: \"CLOSE_PUBLIC_GALLERY\" is on, but it needs share links on and an admin password, so the gallery stays open until both are in place.";
}

// Where a visitor's IP address comes from. 'direct': the connection itself. 'cloudflare': Cloudflare's CF-Connecting-IP
// header, believed only when the connection really comes from one of Cloudflare's own addresses (or one listed in
// TRUSTED_PROXY_RANGES, e.g. 127.0.0.1/32 for a tunnel running on this machine). 'proxy': the X-Forwarded-For header,
// believed only when the connection comes from an address in TRUSTED_PROXY_RANGES. Behind a CDN the connection is always
// the CDN's, so without this every visitor looks like the same few addresses (share-link logs, the sign-in throttle).
define('TRUSTED_PROXY', gallery_setting('TRUSTED_PROXY', 'direct', ['direct', 'cloudflare', 'proxy']));
define('TRUSTED_PROXY_RANGES', gallery_setting('TRUSTED_PROXY_RANGES', []));

// Share links: the cookie that carries a link's sign-in (named for this copy's folder, like the other cookies), the one token the
// admin asks for to test the tidy address, and a few fixed values. Plain constants here because they must exist before the
// first request is routed.
define('LINK_COOKIE_NAME', 'gallery_link_' . substr(hash('sha256', __DIR__), 0, 10));
define('PREVIEW_COOKIE_NAME', 'gallery_preview_' . substr(hash('sha256', __DIR__), 0, 10));   // the owner previewing a link in this browser
const PREVIEW_SECONDS = 14400;   // a preview ends by itself after four hours
define('LINK_PROBE_TOKEN', '0000-probe-0000');   // the admin asks for this one to learn whether the tidy address reaches the script (it has hyphens, so only the rule that allows custom names lets it through)
const LINK_TOKEN_LENGTH = 8;   // a new random address (the same length as a Not listed photo's); links made before keep their 16
const LINK_GUESS_LIMIT = 10;   // wrong share-link or photo addresses from one visitor in 15 minutes before a one-minute pause (a sign-in keeps 5)
const LINK_ALIASES_MAX = 9;   // old addresses a link may keep working at the same time (with its own, ten ways in)
const LINK_ADDRESS_TAKEN = 'Another link has (or had) that address: pick another.';
// Names a link may not take: addresses the gallery uses itself.
const LINK_RESERVED_NAMES = ['admin', 'administrator', 'login', 'logout', 'signin', 'reveal', 'index', 'about', 'link', 'links', 'share', 'gallery', 'robots', 'sitemap', 'favicon', 'assets', 'static', 'public', 'private', 'photos', 'media', 'download', 'downloads', 'cache', 'config', 'null', 'none', 'test'];
const LINK_REOPEN_GRACE_SECONDS = 900;   // the same address opening again this soon (an app's own browser, then Safari) is not a second use
const LOCKOUT_LOG_ID = 'fffffffffe';   // the source of the lockouts in the visitor log (a lockout is a line there, like a visit)
const PRIVATE_LOG_ID = 'fffffffffd';   // the source of the private-view visitors' records in the visitor log (people who came in with the private-view password; not a link, never stored in the links file)
const PUBLIC_LOG_ID = 'ffffffffff';   // the source of the public address's records in the visitor log (an older version kept it as a record in the links file)
