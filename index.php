<?php
/**
 * DROP-IN PHOTO GALLERY
 * Single PHP file, no dependencies, no build step. Drop into any folder
 * of photos and it renders a gallery for that folder.
 *
 * Full documentation (grouping, nested folders, video, captions and
 * locations, the admin editor, hiding items, the trip map, Markdown
 * notes, {color}/{sold}/{reserved}/{group}/{photos} directives, view
 * stats, deep linking, image caching, etc.) lives in the repo's README: github.com/pcmike/gallery. The
 * "?about" page this script serves itself (open any deployed gallery and
 * click "about" in the footer, or visit "?about" directly) is a short
 * in-app pitch that links to the GitHub repo, including a "Get it on
 * GitHub" button — ?download used to serve this file's own bytes
 * directly, but no longer exists; see the ?download handler below.
 *
 * Quick reference for anyone editing this file directly:
 *   - GALLERY_VERSION / TRACK_VIEWS / SHOW_VIEW_COUNTS_PUBLICLY /
 *     ENABLE_THUMBNAIL_CACHE / AUTO_GROUP_BY_FILENAME / PHOTO_SORT_ORDER /
 *     AUTO_GROUP_BY_FOLDER / FOLDER_DISPLAY_MODE / EXTRA_FOLDERS /
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
define('GALLERY_VERSION', '2.0.1');

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

// How photos are ordered before any .md-driven repositioning: 'filename'
// (natural sort, the default) or 'mtime' (file modification time,
// oldest first). 'filename' is the more reliable default for a script
// meant to be copied/uploaded/backed up anywhere — many deployment
// methods (FTP, zip/extract, a fresh git clone, cloud sync) reset a
// file's modification time to "when it was placed here," not when the
// photo was actually taken, which can make 'mtime' order effectively
// arbitrary. Only switch to 'mtime' if you've verified your own
// deployment method preserves the original timestamps.
define('PHOTO_SORT_ORDER', gallery_setting('PHOTO_SORT_ORDER', 'filename', ['filename', 'mtime']));

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
// what an ordinary visitor is ever shown. Real coverage is narrower than
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
// own doc comment for that gating. Reached at its own shareable ?map
// URL, with a smaller preview embedded on the gallery root.
define('ENABLE_MAP', gallery_setting('ENABLE_MAP', false));

// How close together (in miles) two photos' locations have to be before
// the map merges them into a single cluster pin instead of showing each
// individually. 50 is a reasonable middle ground out of the box — a
// dense city trip and a cross-country road trip want very different
// values, so this is deliberately tunable per deployment rather than a
// fixed constant buried in code.
define('MAP_CLUSTER_RADIUS_MILES', gallery_setting('MAP_CLUSTER_RADIUS_MILES', 50));
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
    foreach (EXTRA_FOLDERS as $extraPath) {
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
    foreach (EXTRA_FOLDERS as $virtualName => $realPath) {
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
            $mount = EXTRA_FOLDERS[$seg] ?? null;
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
    if (isset(EXTRA_FOLDERS[$first]) && is_dir(EXTRA_FOLDERS[$first])) {
        $rest = $slash === false ? '' : substr($relPath, $slash + 1);
        return rtrim(EXTRA_FOLDERS[$first], '/') . ($rest === '' ? '' : '/' . $rest);
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

// ?reveal — the "Private view" link in the footer. See run_reveal_route()
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
function serve_original_file($srcPath, $relativePath, $mimeType) {
    header('Content-Type: ' . $mimeType);
    if (NGINX_ACCEL_REDIRECT_PREFIX !== '') {
        // Each path segment encoded separately — the slashes themselves
        // are the URL structure nginx's location block matches against,
        // not something to escape away.
        $urlPath = implode('/', array_map('rawurlencode', explode('/', $relativePath)));
        header('X-Accel-Redirect: ' . rtrim(NGINX_ACCEL_REDIRECT_PREFIX, '/') . '/' . $urlPath);
        return;
    }
    readfile($srcPath);
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
            $variants = $isHeic ? ['thumb', 'share', 'full'] : ['thumb', 'share'];
            $mtime = @filemtime(resolve_real_path($dir, $file));
            foreach ($variants as $variant) {
                $valid[md5($file . '|' . $variant . '|' . $mtime) . '.jpg'] = true;
            }
        }
        foreach (scandir($cacheDir) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            if (!isset($valid[$entry])) {
                @unlink($cacheDir . '/' . $entry);
            }
        }
    }
}

/** Resizes (and, for HEIC, converts) an image to JPEG using Imagick if
 *  available, otherwise GD. Returns true on success. */
function generate_resized_jpeg($srcPath, $destPath, $maxDim) {
    if (class_exists('Imagick')) {
        try {
            $img = new Imagick();
            $img->readImage($srcPath);
            if (method_exists($img, 'autoOrient')) {
                $img->autoOrient();
            }
            $img->setImageFormat('jpeg');
            $img->setImageCompressionQuality(82);
            $w = $img->getImageWidth();
            $h = $img->getImageHeight();
            if (max($w, $h) > $maxDim) {
                $img->thumbnailImage($maxDim, $maxDim, true);
            }
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
        $ok = imagejpeg($dst, $destPath, 82);
        imagedestroy($src);
        imagedestroy($dst);
        return $ok;
    }
    return false;
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
    $maxDims = ['thumb' => 480, 'share' => 1200, 'full' => 2400];
    $maxDim = $maxDims[$variant] ?? 480;

    // No frame extraction (see ENABLE_VIDEO's doc comment) — every size
    // of a video's "thumbnail," grid tile or Open Graph share preview
    // alike, gets the same generic placeholder graphic instead.
    if (is_video_file($filename)) {
        render_video_placeholder_image($maxDim);
        return;
    }

    $srcPath = resolve_real_path($dir, $filename);
    $cacheDir = containing_folder_real_path($dir, $filename) . '/.gallery-cache';
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $isHeic = in_array($ext, ['heic', 'heif'], true);

    // Non-HEIC files at full size are served untouched — no need to
    // resize or re-encode the original the lightbox already opens fine.
    if (!$isHeic && $variant === 'full') {
        header('Cache-Control: ' . $cacheControl);
        serve_original_file($srcPath, $filename, guess_mime($ext));
        return;
    }

    $cachePath = image_cache_path($cacheDir, $filename, $variant, $srcPath);
    if ($cachePath && file_exists($cachePath) && filemtime($cachePath) >= @filemtime($srcPath)) {
        header('Content-Type: image/jpeg');
        header('Cache-Control: ' . $cacheControl);
        readfile($cachePath);
        return;
    }

    $ok = $cachePath ? generate_resized_jpeg($srcPath, $cachePath, $maxDim) : false;
    if ($ok) {
        header('Content-Type: image/jpeg');
        header('Cache-Control: ' . $cacheControl);
        readfile($cachePath);
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
    if ($cacheControl === 'private, no-store') {
        header('Cache-Control: private, no-store');
    }
    serve_original_file($srcPath, $filename, guess_mime($ext));
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

    // Once nginx is configured for this (see serve_original_file()),
    // hand the whole request to its own static serving instead — nginx
    // already handles Range requests correctly on its own, a free
    // upgrade over the plain-PHP handling below, which stays as the
    // baseline that works on any host regardless.
    if (NGINX_ACCEL_REDIRECT_PREFIX !== '') {
        header('Cache-Control: ' . $cacheControl);
        serve_original_file($srcPath, $filename, guess_mime($ext));
        return;
    }

    header('Content-Type: ' . guess_mime($ext));
    header('Cache-Control: ' . $cacheControl);
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
    $chunkSize = 8192;
    while ($remaining > 0 && !feof($fh)) {
        $chunk = fread($fh, min($chunkSize, $remaining));
        if ($chunk === false || $chunk === '') break;
        echo $chunk;
        $remaining -= strlen($chunk);
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
$explicitClaims = [];     // filename => true (removed from auto-detection)
$explicitGroups = [];     // slug => ['name' => .., 'files' => [...], 'position' => N]
$positionalClusters = []; // list of ['files' => [...], 'position' => N]
$offset = 0;
foreach ($mdContents as $content) {
    foreach (extract_photo_directives($content) as $rec) {
        $validPhotos = [];
        foreach ($rec['photos'] as $p) {
            $p = basename($p);
            if (!in_array($p, $files, true)) {
                $galleryNotices[] = 'Unknown file "' . $p . '" referenced in a {photos:} directive — ignored.';
                continue;
            }
            if (isset($explicitClaims[$p])) {
                $galleryNotices[] = '"' . $p . '" is referenced in more than one {photos:} directive — only the first applies.';
                continue;
            }
            $validPhotos[] = $p;
        }
        // A directive-only paragraph only sees prose in its own
        // paragraph (see GROUPING/order_within_zone), never a
        // description written just before it — a blank line between
        // them is enough to disconnect them, which is easy to write by
        // accident. Flag it: it's not invalid, just probably not what
        // was intended.
        if (!$rec['hasProse'] && $rec['precededByPlainProse']) {
            $galleryNotices[] = 'A {group:}/{photos:} paragraph right after a plain description isn\'t attached to it (a blank line separates them into different paragraphs) — merge them into one paragraph if that was intended.';
        }
        if (empty($validPhotos)) continue;
        $position = $offset + $rec['position'];
        if ($rec['groupName'] !== null && $rec['groupName'] !== '') {
            $slug = slugify($rec['groupName']);
            if ($slug === '') continue;
            if (isset($explicitGroups[$slug])) {
                $galleryNotices[] = '{group: ' . $rec['groupName'] . '} is defined more than once — only the first definition applies.';
                continue;
            }
            foreach ($validPhotos as $p) { $explicitClaims[$p] = true; }
            $explicitGroups[$slug] = ['name' => $rec['groupName'], 'files' => $validPhotos, 'position' => $position, 'hasProse' => $rec['hasProse']];
        } else {
            foreach ($validPhotos as $p) { $explicitClaims[$p] = true; }
            $positionalClusters[] = ['files' => $validPhotos, 'position' => $position, 'hasProse' => $rec['hasProse']];
        }
    }
    $offset += strlen($content) + 1;
}

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
function admin_session_marker() {
    return hash('sha256', 'admin|' . ADMIN_PASSWORD . '|' . __DIR__);
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
    return hash_equals(ADMIN_PASSWORD, $pass);
}

/** A request carried an HTTP Basic password that is wrong: a script's typo, or
 *  — more often — a browser re-sending a password it saved when an older
 *  version of this gallery asked for one. The answer is 401 (so a script can
 *  tell) with the ordinary login form, and never a WWW-Authenticate header:
 *  that header is what makes a browser open its own username/password dialog,
 *  and a stale saved password must not bring that dialog back. */
function admin_reject_saved_credentials() {
    http_response_code(401);
    render_login_page('Gallery admin', 'Enter the admin password.', 'Your browser sent a saved password that doesn\'t match the current one. Type the current admin password below.');
}

/** The password form for ?admin and ?reveal: one field, no username, no
 *  browser pop-up. Never indexed or cached or framed. $error is shown above
 *  the field after a wrong password. */
function render_login_page($heading, $blurb, $error = '') {
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Robots-Tag: noindex, nofollow');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title><?= htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') ?></title>
<style>
  :root { color-scheme: dark; }
  body { background:#0f0f10; color:#eaeaea; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; line-height:1.5; margin:0; }
  .wrap { max-width:360px; margin:0 auto; padding:72px 24px; }
  h1 { font-size:1.3rem; margin:0 0 6px; }
  p { color:#9a9aa0; font-size:0.9rem; margin:0 0 18px; }
  input[type=password] { width:100%; box-sizing:border-box; padding:10px 12px; font-size:1rem; background:#1a1a1c; color:#eaeaea; border:1px solid #3a3a3e; border-radius:8px; }
  input[type=password]:focus { outline:2px solid #5865f2; outline-offset:1px; }
  button { margin-top:12px; width:100%; padding:10px 12px; font-size:1rem; background:#5865f2; color:#fff; border:0; border-radius:8px; cursor:pointer; }
  .err { background:rgba(237,66,69,0.15); color:#ff8a8c; padding:8px 12px; border-radius:8px; font-size:0.9rem; margin-bottom:12px; }
</style>
</head>
<body>
<div class="wrap">
  <h1><?= htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') ?></h1>
  <p><?= htmlspecialchars($blurb, ENT_QUOTES, 'UTF-8') ?></p>
  <?php if ($error !== ''): ?><div class="err" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <form method="post" action="">
    <input type="password" name="login_password" autocomplete="current-password" autofocus required aria-label="Password">
    <button type="submit">Continue</button>
  </form>
</div>
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
  a { color:#8ab4ff; }
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

function reveal_cookie_value() {
    // Mixed with this install's folder: the cookie is a bearer token for
    // *this* gallery only, not a bare hash of the password that another
    // gallery sharing the same password would also accept.
    return hash('sha256', PRIVATE_VIEW_PASSWORD . '|' . __DIR__);
}

/** Sets the private-view cookie, after a successful ?reveal login. A year-long
 *  expiry gives the same "enter it once" persistence ?admin's own
 *  session-based login already has, just via a plain cookie instead of a
 *  PHP session, since this has to survive on the *public*, unauthenticated
 *  page view, not just inside ?admin/?reveal themselves. */
function set_reveal_cookie() {
    if (PRIVATE_VIEW_PASSWORD === '') return;
    setcookie(REVEAL_COOKIE_NAME, reveal_cookie_value(), [
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
    return $pw === '' ? '' : hash('sha256', 'admin-reveal|' . $pw . '|' . __DIR__);
}

/** Sets that cookie on every authenticated admin request. $password is passed
 *  only right after the password was just set or changed, when the constant
 *  still holds the old value. */
function set_admin_reveal_cookie($password = null) {
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
    if (AUTO_GROUP_BY_FOLDER && strpos($file, '/') !== false) {
        // Every ancestor, not just the top-level one: a folder hidden at
        // any depth hides everything beneath it, the same way a hidden
        // top-level folder always has.
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
    if (PRIVATE_VIEW_PASSWORD === '') {
        http_response_code(404);
        return;
    }
    if (!is_https_request() && !is_local_request()) {
        render_https_required_page();
        return;
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (!login_throttle_allows($dir, 'reveal', $ip)) {
        http_response_code(429);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Too many failed attempts. Try again in a moment.';
        return;
    }
    // The form is the normal way in; a Basic Authorization header (a script,
    // a bookmarked login) is still honored. Only a password that was
    // actually *supplied* and wrong counts against the throttle: a plain
    // visit to the form tells us nothing about guessing.
    $pass = $_SERVER['PHP_AUTH_PW'] ?? null;
    $formPass = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_password']) && is_string($_POST['login_password'])) ? $_POST['login_password'] : null;
    $supplied = $formPass ?? $pass;
    if (!is_string($supplied) || !hash_equals(PRIVATE_VIEW_PASSWORD, $supplied)) {
        if (isset($_SERVER['PHP_AUTH_USER']) || isset($_SERVER['PHP_AUTH_PW']) || $formPass !== null) {
            login_throttle_record_failure($dir, 'reveal', $ip);
        }
        if ($formPass === null && (isset($_SERVER['PHP_AUTH_USER']) || isset($_SERVER['PHP_AUTH_PW']))) {
            // A wrong saved Basic password (see admin_reject_saved_credentials): the form, never a challenge.
            http_response_code(401);
            render_login_page('Private view', 'Enter the password to see the hidden items.', 'Your browser sent a saved password that doesn\'t match the current one. Type the current private-view password below.');
            return;
        }
        render_login_page('Private view', 'Enter the password to see the hidden items.', $formPass !== null ? 'That password isn\'t right.' : '');
        return;
    }
    login_throttle_record_success($dir, 'reveal', $ip);
    set_reveal_cookie();
    header('Location: ./', true, 302);
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
    return (is_int($r) || is_float($r)) && $r >= 1 && $r <= 10000 ? $r : MAP_CLUSTER_RADIUS_MILES;
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
function render_leaflet_map($points, $legend, $mapId, $heightCss, $loadLibrary, $radiusMiles = null) {
    if (empty($points)) return;
    $clusters = cluster_map_points($points, $radiusMiles ?? MAP_CLUSTER_RADIUS_MILES);
    // Hex-escaped so the raw JSON can sit directly inside a <script>
    // block with no further quoting — safe even if a caption happens to
    // contain "</script>", a quote, or an ampersand.
    $clustersJson = json_encode($clusters, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<?php if ($loadLibrary): ?>
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
      map.fitBounds(bounds, { maxZoom: 15, padding: [30, 30] });
    });
  });

  if (allBounds.length === 1) {
    map.setView(allBounds[0], 13);
  } else {
    map.fitBounds(allBounds, { padding: [30, 30] });
  }
})();
</script>
<?php
}

/**
 * The dedicated, shareable ?map page — a condensed preview also lives
 * inline on the gallery root (see the main template), but "check out our
 * whole route" deserves its own link, the same treatment ?folder=/
 * ?photo= already get. No live rendered-map snapshot for its Open Graph
 * image — that needs a third-party static-maps API, the same dependency
 * objection that already ruled out geocoding — a generic map icon
 * instead, same honesty standard as the video placeholder.
 */
function render_map_page($points, $legend, $pageTitle, $baseUrl, $folderPath = null, $radiusMiles = null) {
    send_viewer_aware_cache_headers();
    $version = htmlspecialchars(GALLERY_VERSION, ENT_QUOTES, 'UTF-8');
    $title = htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8');
    $ogUrl = htmlspecialchars($baseUrl . '/?map' . ($folderPath !== null ? '&folder=' . rawurlencode($folderPath) : ''), ENT_QUOTES, 'UTF-8');
    $backHref = $folderPath === null ? gallery_root_href() : '?folder=' . rawurlencode($folderPath);
    $backText = $folderPath === null ? 'Back to gallery' : 'Back to ' . basename($folderPath);
    $ogImage = htmlspecialchars($baseUrl . '/?mapicon', ENT_QUOTES, 'UTF-8');
    // A bare "#group-<slug>" resolves against the *current* URL — here
    // /?map — so it would scroll nowhere on this page. Point in-page
    // anchors at the gallery root instead; ?folder= links are already
    // root-relative query strings and need no help.
    foreach ($points as &$p) {
        if ($p['link'][0] === '#') $p['link'] = ($folderPath === null ? './' : '?folder=' . rawurlencode($folderPath)) . $p['link'];
    }
    unset($p);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $title ?> — Map</title>
<meta property="og:type" content="website">
<meta property="og:title" content="<?= $title ?> — Map">
<meta property="og:description" content="<?= count($points) ?> location<?= count($points) === 1 ? '' : 's' ?> plotted from this gallery.">
<meta property="og:url" content="<?= $ogUrl ?>">
<meta property="og:image" content="<?= $ogImage ?>">
<meta name="twitter:card" content="summary_large_image">
<style>
  :root { --bg:#0f0f10; --card:#1a1a1c; --text:#eaeaea; --muted:#8a8a8f; }
  * { box-sizing: border-box; }
  body { margin:0; background:var(--bg); color:var(--text); font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; }
  .wrap { max-width:1100px; margin:0 auto; padding:20px 16px 48px; }
  h1 { font-size:1.3rem; margin:0 0 14px; }
  a.back { color:var(--muted); text-decoration:none; font-size:0.9rem; }
  .map-legend { display:flex; flex-wrap:wrap; gap:12px; margin-top:12px; font-size:0.85rem; color:var(--muted); }
  .map-legend-item { display:flex; align-items:center; gap:6px; }
  .map-legend-swatch { width:12px; height:12px; border-radius:50%; display:inline-block; }
  .leaflet-popup-content-wrapper { background:var(--card); color:var(--text); }
  .leaflet-popup-tip { background:var(--card); }
</style>
</head>
<body>
<div class="wrap">
  <a href="<?= htmlspecialchars($backHref, ENT_QUOTES, 'UTF-8') ?>" class="back">&larr; <?= htmlspecialchars($backText, ENT_QUOTES, 'UTF-8') ?></a>
  <h1><?= $title ?> — Map</h1>
  <?php if (empty($points)): ?>
    <p style="color:var(--muted);">No photos have a visible location yet.</p>
  <?php else: ?>
    <?= render_leaflet_map($points, $legend, 'trip-map-full', '70vh', true, $radiusMiles) ?>
  <?php endif; ?>
</div>
</body>
</html>
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
    return ['items' => [], 'groups' => [], 'displayName' => null, 'description' => null, 'dates' => null, 'sortOrder' => null, 'cover' => null, 'locationEnabled' => false, 'mapEnabled' => null, 'mapRadius' => null];
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

/** The three-gate check (global constant, folder toggle, per-photo
 *  tri-state) that decides whether an unrevealed visitor ever sees this
 *  photo's location — see ENABLE_PHOTO_LOCATION's own doc comment for
 *  the full reasoning. The admin tool itself ignores all three; it can
 *  always see and edit location data for its own authenticated owner. */
function photo_location_visible($itemEntry) {
    if (!ENABLE_PHOTO_LOCATION) return false;
    if (empty($itemEntry['location'])) return false;
    $visibility = $itemEntry['locationVisibility'] ?? 'inherit';
    if ($visibility === 'hide') return false;
    if ($visibility === 'show') return true;
    return !empty($itemEntry['_folderLocationEnabled']);
}

/** Whether this PHP can read embedded GPS out of this kind of file at all
 *  (JPEG needs the exif extension, HEIC/HEIF needs Imagick). Anything else
 *  — PNG, GIF, WEBP, video — has no GPS block, so there is nothing to wait
 *  for or to mark as checked. */
function gps_readable_file($file) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if ($ext === 'jpg' || $ext === 'jpeg') return function_exists('exif_read_data');
    if ($ext === 'heic' || $ext === 'heif') return class_exists('Imagick');
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
                $imported++;
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
    return extract_jpeg_gps($realPath);
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
    ob_start();
?>
    <h2 class="section-title">How notes work</h2>
    <p>A notes file is a short box of text at the top of the gallery page (or at the top of a folder's page, if the file is in that folder). Write plain text with a little formatting, and use <strong>directives</strong> in {curly braces} to attach photos, mark an item sold, and so on.</p>

    <h3>Making a file count</h3>
    <ul>
      <li>The <strong>first line must be exactly</strong> <?= $c('{gallery}') ?>. Any other <code>.md</code> file in the folder is left completely alone (a personal notes file is never read). A file without it is listed as "not active", with a one-click button to add the line.</li>
      <li>In the gallery's first notes file, a <?= $c('# Heading') ?> on the first line after that becomes the <strong>page title</strong>, and isn't repeated in the box. A <strong>Gallery title</strong> set in Settings wins over it.</li>
      <li>A notes file inside a subfolder only affects <strong>that folder's page</strong> and photos.</li>
    </ul>

    <h3>Formatting</h3>
    <table class="ref-table">
      <tr><td><?= $c('# Heading') ?><br><?= $c('## Smaller') ?> <?= $c('### Smaller still') ?></td><td>Headings</td></tr>
      <tr><td><?= $c('**bold**') ?> <?= $c('*italic*') ?> <?= $c('_italic_') ?><br><?= $c('***both***') ?> <?= $c('__underline__') ?><br><?= $c('~~strikethrough~~') ?></td><td>Text styles</td></tr>
      <tr><td><?= $c('`code`') ?></td><td>Inline code, shown as typed (see "Showing a directive as text" below)</td></tr>
      <tr><td><?= $c('[words](https://example.com)') ?></td><td>A link. Only <code>http://</code> and <code>https://</code> addresses become links.</td></tr>
      <tr><td><?= $c('- an item') ?> <?= $c('* an item') ?><br><?= $c('1. a numbered item') ?></td><td>Bulleted and numbered lists</td></tr>
      <tr><td><?= $c('> a quote') ?></td><td>A block quote</td></tr>
      <tr><td><?= $c('```') ?> on its own line, before and after</td><td>A block of code, shown exactly as typed</td></tr>
    </table>
    <p class="hint">HTML you type is never run: it appears as plain text.</p>

    <h3>Directives</h3>
    <p>A directive is a word in {curly braces}, written anywhere in an item's paragraph. It's removed from what visitors read. Click a button to put one at the cursor of the note you last clicked in.</p>
    <table class="ref-table">
      <tr><td><?= $ins('Group a box', '{group: Name}') ?><br><?= $c('{group: Name}') ?></td><td>Draws a labelled <strong>box</strong> around this item's photos (with its own view count). On its own, it renames a box the gallery already made, such as a folder or a group of <code>alpha_01.jpg</code>, <code>alpha_02.jpg</code>.</td></tr>
      <tr><td><?= $ins('Claim photos', '{photos: a.jpg, b.jpg}') ?><br><?= $c('{photos: a.jpg, b.jpg}') ?></td><td>Attaches exactly these files to this paragraph, <strong>whatever they're called</strong>. Separate the names with commas, spelled as in the folder. A file can belong to one paragraph only: the first claim wins.</td></tr>
      <tr><td><?= $ins('Accent color', '{color: #ff0000}') ?><br><?= $c('{color: #5865f2}') ?> or <?= $c('{color: gold}') ?></td><td>The accent colour of that item's box: a hex code or a plain colour word.</td></tr>
      <tr><td><?= $ins('Sold', '{sold}') ?><br><?= $c('{sold}') ?></td><td>Marks the item <strong>sold</strong>: dimmed, struck-through text, grayscale photos and a red SOLD ribbon.</td></tr>
      <tr><td><?= $ins('Reserved', '{reserved}') ?><br><?= $c('{reserved}') ?> or <?= $c('{held}') ?></td><td>Marks it <strong>reserved</strong>: an orange tag; the photos stay in colour.</td></tr>
    </table>
    <p class="hint insert-hint" hidden>Click inside the note you want to add it to first, then press the button.</p>
    <p class="hint">If an item has both, sold wins over reserved, and either one overrides a custom colour, so the signal is never ambiguous.</p>

    <h3>Attaching photos to a paragraph</h3>
    <p>Three ways, from least to most typing:</p>
    <ol>
      <li><strong>Just mention it.</strong> If a paragraph contains a photo's name as a whole word (not case-sensitive), its photos appear under it. With filename grouping off, the name is the file's name without its extension (<code>zebra.jpg</code> is "zebra"); with it on, a trailing <code>_01</code> or <code>-02</code> is dropped too, so <code>alpha_01.jpg</code> and <code>alpha_02.jpg</code> are both "alpha". Mentioning a folder's name (with folders shown as cover cards) links to that folder.</li>
      <li><strong>List them:</strong> works with any filenames.
<pre class="ref-example">The blue one is my favourite. {photos: IMG_0231.jpg, IMG_0232.jpg}</pre></li>
      <li><strong>List them and give the item a box:</strong>
<pre class="ref-example">Hand-built keyboard, 65%. {group: Keyboard} {photos: kb1.jpg, kb2.jpg} {color: gold}</pre></li>
    </ol>
    <p>A photo you list always beats the automatic matching, and is taken out of it.</p>

    <h3>Where a directive applies</h3>
    <p>A directive only ever affects <strong>its own paragraph</strong>, never the one above or below, even with nothing in between. A paragraph ends at:</p>
    <ul>
      <li>a <strong>blank line</strong> (the usual cause of surprises),</li>
      <li>a heading, a quote or a list item (each stands alone), and a code block,</li>
      <li>a line starting <?= $c('(1)') ?>, <?= $c('(2)') ?> and so on, which starts a new paragraph straight away so a numbered listing needs no blank lines.</li>
    </ul>
    <p>So this does <em>not</em> work as hoped, because the blank line makes two paragraphs:</p>
<pre class="ref-example">Take a look at my rack

{group: Rack} {photos: rack.jpg, rack2.jpg}</pre>
    <p>Remove the blank line to make it one item:</p>
<pre class="ref-example">Take a look at my rack. {group: Rack} {photos: rack.jpg, rack2.jpg}</pre>

    <h3>Showing a directive as text</h3>
    <p>To write about a directive without it doing anything, put it in backticks: <?= $c('`{sold}`') ?> shows the word <code>{sold}</code> and marks nothing. Everything on this page is written that way.</p>

    <h3>If something doesn't work</h3>
    <p>The gallery page shows a small, collapsed <strong>setup notices</strong> banner when something in a notes file didn't resolve: a filename in <?= $c('{photos:}') ?> that isn't in the folder, a file claimed twice, a directive cut off from its text by a blank line, or a file missing <?= $c('{gallery}') ?>.</p>
    <p class="hint">The complete reference, with a table of every combination of <code>{group:}</code> and <code>{photos:}</code>, is in the README under "Directive syntax".</p>
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

/** The folder-level fields the "This folder's own settings" form edits,
 *  normalized so the render-time and save-time fingerprints match. */
function folder_identity_record($data) {
    return [
        'displayName' => $data['displayName'] ?? null,
        'description' => $data['description'] ?? null,
        'dates' => $data['dates'] ?? null,
        'sortOrder' => $data['sortOrder'] ?? null,
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
function admin_clean_text($value, $maxLen) {
    $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $value);
    $text = trim(preg_replace('/\s+/u', ' ', (string) $text));
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $maxLen, 'UTF-8');
    }
    return substr($text, 0, $maxLen);
}

/** Answers a request made by the admin page's own script (a switch saved in
 *  place) with JSON, and ends the request. */
function admin_json_exit($payload) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
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
        $visibility = $_POST['loc_visibility'] ?? 'inherit';
        if (!in_array($visibility, ['inherit', 'show', 'hide'], true)) $visibility = 'inherit';
        $restriction = $_POST['restriction'] ?? 'inherit';
        if (!in_array($restriction, ['inherit', 'public', 'private'], true)) $restriction = 'inherit';
        if ($web !== '' && !preg_match('#^https?://#i', $web)) {
            return ['type' => 'error', 'text' => 'The web link needs to start with http:// or https://.'];
        }
        if (($lat !== '' || $lng !== '') && !is_valid_coordinate($lat === '' ? 0 : $lat, $lng === '' ? 0 : $lng)) {
            return ['type' => 'error', 'text' => 'That latitude/longitude isn\'t a valid coordinate.'];
        }
        $loadedHash = isset($_POST['loaded_hash']) ? (string) $_POST['loaded_hash'] : null;
        $stale = false;
        items_update($adminFolderReal, function (&$data) use ($bare, $caption, $web, $lat, $lng, $locLabel, $visibility, $restriction, $loadedHash, &$stale) {
            $entry = $data['items'][$bare] ?? [];
            if ($loadedHash !== null && !hash_equals(admin_record_hash($entry), $loadedHash)) {
                $stale = true;
                return;
            }
            if ($caption !== '') { $entry['caption'] = $caption; } else { unset($entry['caption']); }
            if ($web !== '') { $entry['web'] = $web; } else { unset($entry['web']); }
            if ($lat !== '' && $lng !== '') {
                $entry['location'] = ['lat' => (float) $lat, 'lng' => (float) $lng, 'label' => $locLabel];
            } else {
                unset($entry['location']);
            }
            // Saved by the owner, so whatever location it has (or deliberately
            // doesn't) stands: never auto-import GPS over it later.
            $entry['gpsChecked'] = true;
            $entry['locationVisibility'] = $visibility;
            if ($restriction === 'inherit') { unset($entry['restriction']); } else { $entry['restriction'] = $restriction; }
            $data['items'][$bare] = $entry;
        });
        if ($stale) {
            return ['type' => 'error', 'text' => '"' . $bare . '"' . admin_stale_message()];
        }
        return ['type' => 'ok', 'text' => 'Saved "' . $bare . '".'];
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
        $displayName = trim((string) ($_POST['display_name'] ?? ''));
        // Only fields actually present in the POST are touched, so a form
        // (or an older client) that only sends the display name never
        // wipes the other three.
        $updates = ['displayName' => $displayName !== '' ? $displayName : null];
        if (array_key_exists('description', $_POST)) {
            $text = admin_clean_text($_POST['description'], 500);
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
        $loadedHash = isset($_POST['loaded_hash']) ? (string) $_POST['loaded_hash'] : null;
        $stale = false;
        items_update($adminFolderReal, function (&$data) use ($updates, $loadedHash, &$stale) {
            if ($loadedHash !== null && !hash_equals(admin_record_hash(folder_identity_record($data)), $loadedHash)) {
                $stale = true;
                return;
            }
            foreach ($updates as $k => $v) {
                $data[$k] = $v;
            }
        });
        if ($stale) {
            return ['type' => 'error', 'text' => ($adminFolderPath === null ? 'The gallery\'s settings' : 'This folder\'s settings') . admin_stale_message()];
        }
        return ['type' => 'ok', 'text' => $adminFolderPath === null ? 'Gallery settings saved.' : 'Folder settings saved.'];
    }

    if ($action === 'toggle_folder_restriction') {
        if ($adminFolderPath === null) {
            return ['type' => 'error', 'text' => 'Not applicable at the root — hide individual items instead.'];
        }
        $hidden = !empty($_POST['hidden']);
        items_update($adminFolderReal, function (&$data) use ($hidden) {
            if ($hidden) { $data['restriction'] = 'private'; } else { unset($data['restriction']); }
        });
        return ['type' => 'ok', 'text' => 'This entire folder is now ' . ($hidden ? 'hidden from public view.' : 'public again.')];
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
        $enabled = !empty($_POST['map_enabled']);
        $rawRadius = trim((string) ($_POST['map_radius'] ?? ''));
        if ($rawRadius === '') {
            $radius = null;
        } elseif (is_numeric($rawRadius) && (float) $rawRadius >= 1 && (float) $rawRadius <= 10000) {
            $radius = (float) $rawRadius == (int) $rawRadius ? (int) $rawRadius : (float) $rawRadius;
        } else {
            return ['type' => 'error', 'text' => 'Pin grouping must be a number of miles from 1 to 10000, or blank to use the site-wide setting.'];
        }
        $targetReal = $adminFolderPath === null ? $dir : $adminFolderReal;
        items_update($targetReal, function (&$data) use ($enabled, $radius) {
            $data['mapEnabled'] = $enabled;
            $data['mapRadius'] = $radius;
        });
        $where = $adminFolderPath === null ? 'the gallery\'s front page' : 'this folder\'s page';
        $text = $enabled ? 'A map now shows on ' . $where . ', from the photos whose location is visible.' : 'The map no longer shows on ' . $where . '. Nothing is deleted.';
        if ($enabled && !ENABLE_MAP) $text .= ' It stays hidden until the Trip map is switched on in Site settings.';
        elseif ($enabled && !ENABLE_PHOTO_LOCATION) $text .= ' It stays empty until photo locations are allowed in Site settings.';
        return ['type' => 'ok', 'text' => $text . ($radius !== null ? ' Photos within ' . $radius . ' mile' . ($radius == 1 ? '' : 's') . ' of each other share a pin.' : '')];
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
            $text .= ' ' . $imported . ' photo' . ($imported === 1 ? ' has' : 's have') . ' GPS in the file and now show' . ($imported === 1 ? 's' : '') . ' a location to visitors and on the map. To keep one private, set its Location visibility to "Always hide" (or clear its coordinates) and Save.';
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

/** Where an item lives in the admin: its folder's page, Photos tab, scrolled
 *  to its own card. */
function admin_item_href($file) {
    $folder = dirname($file);
    return '?admin' . ($folder === '.' ? '' : '&amp;folder=' . htmlspecialchars(rawurlencode($folder), ENT_QUOTES, 'UTF-8'))
         . '#photo-' . md5($file);
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
    <div class="flash error hidden-warning">
      <strong>Hidden isn't locked.</strong>
      Hiding only stops a visitor from reaching an item <em>through this gallery</em> — the file itself still sits on disk as an ordinary static file, servable directly by the webserver if its bare URL is known or guessed, unless you've separately locked that down at the webserver level (see the README for the nginx/<code>X-Accel-Redirect</code> or Apache <code>.htaccess</code> setup).
    </div>
    <section class="panel">
      <h2 class="section-title">Folders hidden entirely (<?= count($data['folders']) ?>)</h2>
      <?php if (empty($data['folders'])): ?><p class="muted">None.</p><?php else: ?><ul class="hidden-list"><?php $listFolders($data['folders']); ?></ul><?php endif; ?>
    </section>
    <section class="panel">
      <h2 class="section-title">Individual items hidden (<?= count($data['items']) ?>)</h2>
      <?php if (empty($data['items'])): ?><p class="muted">None.</p><?php else: ?><ul class="hidden-list"><?php $listItems($data['items']); ?></ul><?php endif; ?>
    </section>
    <section class="panel">
      <h2 class="section-title">Items shown despite a hidden folder (<?= count($data['shown']) ?>)</h2>
      <?php if (empty($data['shown'])): ?><p class="muted">None.</p><?php else: ?><ul class="hidden-list"><?php $listItems($data['shown']); ?></ul><?php endif; ?>
    </section>
<?php
}

/** The "Map" section of a folder's (or the gallery's) settings: whether its page
 *  shows a map of its own photos, and how close photos must be to share a pin.
 *  Both are per page; a blank radius means the site-wide setting. */
function render_admin_map_form($itemsHere, $csrfToken, $isRoot) {
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $on = $isRoot ? (($itemsHere['mapEnabled'] ?? null) !== false) : (($itemsHere['mapEnabled'] ?? null) === true);
    $radius = $itemsHere['mapRadius'] ?? null;
    $confirm = 'Show a map on this page? The map loads its code from cdnjs and its tiles from OpenStreetMap in each visitor\'s browser, so those two sites can see that someone visited. It only plots photos whose location is visible.';
?>
    <h3 class="subhead">Map</h3>
    <?php if (!ENABLE_MAP): ?>
      <p class="muted" style="margin-top:0;">The trip map is switched off for the whole gallery. Turn it on in <a href="#site" onclick="var l=document.querySelector('.side-link'); if (l) { l.click(); return false; }">Site settings</a> first; then each folder can show its own map.</p>
    <?php else: ?>
      <form method="post" class="map-form">
        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
        <input type="hidden" name="action" value="save_map_settings">
        <label><input type="checkbox" name="map_enabled" value="1" <?= $on ? 'checked' : '' ?> data-initial="<?= $on ? '1' : '0' ?>"> Show a map of <?= $isRoot ? 'the whole gallery on its front page' : 'this folder\'s photos on its page' ?></label>
        <div class="field" style="max-width:300px; margin-top:8px;">
          <label>Pin grouping (miles)</label>
          <input type="number" name="map_radius" min="1" max="10000" step="1" value="<?= $radius === null ? '' : $esc($radius) ?>" placeholder="<?= (int) MAP_CLUSTER_RADIUS_MILES ?> (site-wide)">
          <p class="hint">Photos closer together than this share one numbered pin. Leave blank to use the site-wide setting (<?= (int) MAP_CLUSTER_RADIUS_MILES ?>). A city trip wants a small number, a road trip a big one.</p>
        </div>
        <p class="hint" style="margin:0 0 8px;"><?= $isRoot ? 'The map covers every photo in the gallery with a visible location.' : 'The map covers the photos in this folder and its subfolders that have a visible location (this folder\'s own location switch above must be on).' ?></p>
        <button type="submit" class="secondary" onclick="var c = this.form.map_enabled; if (c.checked &amp;&amp; c.getAttribute('data-initial') !== '1') return confirm(<?= $esc(json_encode($confirm)) ?>);">Save map settings</button>
      </form>
    <?php endif; ?>
<?php
}

/** The identity form — title, dates, description (and position, for a
 *  folder) — shared by a folder's settings and the gallery's own, so the root
 *  is configured the same way a folder is. */
function render_admin_identity_form($itemsHere, $csrfToken, $adminFolderPath) {
    $isRoot = $adminFolderPath === null;
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $name = $isRoot ? basename(__DIR__) : basename($adminFolderPath);
?>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
      <input type="hidden" name="action" value="save_folder_identity">
      <input type="hidden" name="loaded_hash" value="<?= admin_record_hash(folder_identity_record($itemsHere)) ?>">
      <div class="settings-grid<?= $isRoot ? ' root' : '' ?>">
        <div class="field">
          <label><?= $isRoot ? 'Gallery title' : 'Display name' ?></label>
          <input type="text" name="display_name" value="<?= $esc($itemsHere['displayName'] ?? '') ?>">
          <?php if ($isRoot): ?>
            <p class="hint">Shown at the top of the page, in the browser tab and in shared links, instead of "<?= $esc($name) ?>". If blank, a "# Heading" at the top of a notes file is used, then the folder name.</p>
          <?php else: ?>
            <p class="hint">Shown on its cover card instead of "<?= $esc($name) ?>".</p>
          <?php endif; ?>
        </div>
        <div class="field">
          <label>Dates (optional)</label>
          <input type="text" name="dates" maxlength="80" placeholder="June 12&ndash;15, 2026" value="<?= $esc($itemsHere['dates'] ?? '') ?>">
          <p class="hint">Typed by you, never read from the photos' EXIF, so it can't reveal when you were away.</p>
        </div>
        <?php if (!$isRoot): ?>
        <div class="field">
          <label>Position</label>
          <input type="number" name="sort_order" min="-99999" max="99999" step="1" value="<?= isset($itemsHere['sortOrder']) && is_int($itemsHere['sortOrder']) ? (int) $itemsHere['sortOrder'] : '' ?>">
          <p class="hint">Among sibling folders, lowest first. Blank = natural order, after numbered ones.</p>
        </div>
        <?php endif; ?>
        <div class="field wide">
          <label>Description</label>
          <textarea name="description" maxlength="500" style="min-height:70px; font-family:inherit;"><?= $esc($itemsHere['description'] ?? '') ?></textarea>
          <p class="hint"><?= $isRoot ? 'A sentence or two, shown in a box at the top of the page and in shared-link previews. For anything longer or richer, use a notes file.' : 'A sentence or two, shown in a box at the top of its page and in shared-link previews.' ?></p>
        </div>
      </div>
      <button type="submit"><?= $isRoot ? 'Save gallery settings' : 'Save folder settings' ?></button>
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
             'help' => 'Each subfolder becomes its own group, to any depth (a trip with Paris/, Lisbon/ …). Off by default because this script is often dropped into a folder that already holds other things.',
             'risk' => 'Every photo in every subfolder becomes visible to visitors.',
             'offwarn' => 'Visitors will stop seeing the subfolders, and links to them will stop working. Nothing is deleted: the folders, photos, captions and settings all stay exactly as they are, and come back when you turn this on again.'],
            ['name' => 'FOLDER_DISPLAY_MODE', 'type' => 'enum', 'label' => 'Folder style',
             'options' => ['overview' => 'Cover cards (click to open)', 'inline' => 'Inline (all on one page)'],
             'help' => 'Only used when subfolders are shown.'],
            ['name' => 'ENABLE_VIDEO', 'type' => 'bool', 'label' => 'Show videos (.mov, .mp4)',
             'help' => 'Videos are served exactly as recorded, with no conversion, so big clips use real bandwidth and some phone formats don\'t play in every browser.',
             'risk' => 'Every .mov and .mp4 in the gallery folders becomes visible to visitors.',
             'offwarn' => 'Videos will disappear from the gallery, and links to them will stop working. Nothing is deleted: the files and their captions, locations and hide settings stay, and come back when you turn this on again.'],
            ['name' => 'AUTO_GROUP_BY_FILENAME', 'type' => 'bool', 'label' => 'Group photos by filename',
             'help' => 'alpha_01.jpg and alpha_02.jpg become one group, "alpha". Handy for for-sale listings; on a camera roll it can merge unrelated photos. Folders are better for trips.'],
            ['name' => 'PHOTO_SORT_ORDER', 'type' => 'enum', 'label' => 'Photo order',
             'options' => ['filename' => 'By filename', 'mtime' => 'By date modified (oldest first)'],
             'help' => 'Date order is only reliable if your upload method keeps the original file dates.'],
        ]],
        ['title' => 'Thumbnails and counting', 'items' => [
            ['name' => 'ENABLE_THUMBNAIL_CACHE', 'type' => 'bool', 'label' => 'Thumbnails',
             'help' => 'Small thumbnails kept in a .gallery-cache folder, so pages load fast and iPhone photos can be shown. If the folder can\'t be written, the original photo is sent instead. Off sends every photo at full size.',
             'offwarn' => 'Every photo will be sent at full size, so pages get slow, and iPhone (HEIC) photos can\'t be shown at all. Nothing is deleted; thumbnails already made are left in place.'],
            ['name' => 'TRACK_VIEWS', 'type' => 'bool', 'label' => 'Count views',
             'help' => 'Counts visits (one per visitor per half hour, however many pages they open; not you, and not bots) and views of each photo, in a stats file in the gallery folder. You always see the numbers in this admin.'],
            ['name' => 'SHOW_VIEW_COUNTS_PUBLICLY', 'type' => 'bool', 'label' => 'Show view counts to visitors',
             'help' => 'Needs "Count views". Off keeps the numbers to yourself.'],
        ]],
        ['title' => 'Locations and the map', 'items' => [
            ['name' => 'ENABLE_PHOTO_LOCATION', 'type' => 'bool', 'label' => 'Allow photo locations',
             'help' => 'Needed before any location can show. Each folder still has its own switch in its settings, and any photo can opt out.',
             'risk' => 'GPS coordinates can reveal where you live or where you were. They stay private until a folder\'s own location switch is turned on.',
             'offwarn' => 'Photo locations and the map will disappear for visitors. Nothing is deleted: every saved location stays and comes back when you turn this on again.'],
            ['name' => 'ENABLE_MAP', 'type' => 'bool', 'label' => 'Trip map',
             'help' => 'A map of every photo that has a visible location. Needs "Allow photo locations".',
             'risk' => 'The map loads its code from cdnjs and its map tiles from OpenStreetMap in each visitor\'s browser, so those two sites can see that someone visited.',
             'offwarn' => 'The trip map will disappear. Nothing is deleted: every photo location stays saved.'],
            ['name' => 'MAP_CLUSTER_RADIUS_MILES', 'type' => 'number', 'label' => 'Map pin grouping (miles)', 'min' => 1, 'max' => 10000,
             'help' => 'Photos closer together than this share one numbered pin. 50 suits most trips; a dense city trip wants less. This is the default: each folder, and the gallery front page, can set its own in its settings.'],
        ]],
        ['title' => 'Server setup (advanced)', 'items' => [
            ['name' => 'EXTRA_FOLDERS', 'type' => 'folders', 'label' => 'Folders from elsewhere on the server',
             'help' => 'Show a folder that lives somewhere else on the server (an archive on another disk) as one more subfolder, under the name you give it. Needs "Show subfolders". Photos stay where they are; only a path is stored here.',
             'risk' => 'Every photo and video in that folder, and in its subfolders, becomes visible to visitors.'],
            ['name' => 'NGINX_ACCEL_REDIRECT_PREFIX', 'type' => 'text', 'label' => 'nginx internal redirect',
             'help' => 'Hands photo and video files to nginx instead of reading them with PHP, which is what makes a hidden photo unreachable at its direct address. Leave empty unless nginx already has the matching "internal" location (see the README), for example /kb.',
             'risk' => 'Unless nginx already has the matching internal location, every photo and video will stop loading until you clear this again.'],
        ]],
    ];
}

/** Checks the map of extra folders the Site settings screen posts (JSON, name =>
 *  path) before it is ever stored. A path must be a real, readable folder,
 *  written as the real path (no symlink anywhere in it, since the scan refuses
 *  to follow them), outside this gallery's own folder and not one that
 *  contains it. Returns the clean map, or null with reasons in $errors. */
function site_settings_validate_extra_folders($raw, &$errors) {
    $map = json_decode((string) $raw, true);
    if (!is_array($map) || ($map !== [] && array_is_list($map))) { $errors[] = 'The list of folders from elsewhere wasn\'t understood.'; return null; }
    if (count($map) > 50) { $errors[] = 'That is more folders from elsewhere than the gallery allows (50).'; return null; }
    $gallery = realpath(__DIR__);
    $out = []; $seen = []; $bad = false;
    foreach ($map as $name => $path) {
        $name = (string) $name; $q = '"' . $name . '"';
        if (!is_string($path)) { $errors[] = "The path for $q isn't text."; $bad = true; continue; }
        if ($name === '' || $name !== trim($name) || strlen($name) > 60 || $name[0] === '.' || preg_match('#[/\\\\\x00-\x1F\x7F]#', $name)) {
            $errors[] = "$q isn't a usable folder name: 1 to 60 characters, no slashes, no leading dot or space."; $bad = true; continue;
        }
        if (isset($seen[strtolower($name)])) { $errors[] = "The name $q is used twice."; $bad = true; continue; }
        $seen[strtolower($name)] = true;
        if (is_dir(__DIR__ . '/' . $name)) { $errors[] = "A folder called $q already exists in the gallery, so that name is taken."; $bad = true; continue; }
        $p = rtrim($path, '/\\');
        if ($p === '' || preg_match('#[\x00-\x1F\x7F]#', $path) || !preg_match('#^(/|[A-Za-z]:[\\\\/])#', $p) || preg_grep('#^\.\.?$#', preg_split('#[\\\\/]+#', $p))) {
            $errors[] = "The path for $q must be a full path starting with / (no .. in it)."; $bad = true; continue;
        }
        if (!@is_dir($p) || !@is_readable($p)) {
            $errors[] = "The path for $q ($p) isn't a folder this server can read. Check it exists and that the web server may open it (and, on shared hosting, that PHP is allowed outside the website's folder)."; $bad = true; continue;
        }
        $real = realpath($p);
        if (is_link($p) || $real === false || $real !== $p) {
            $errors[] = "The path for $q goes through a link or isn't written as its real path" . ($real ? " (it is $real)" : '') . ". The gallery doesn't follow links, so give the real path."; $bad = true; continue;
        }
        if ($gallery !== false && ($real === $gallery || str_starts_with($real . '/', $gallery . '/') || str_starts_with($gallery . '/', $real . '/'))) {
            $errors[] = "The path for $q overlaps this gallery's own folder (inside it, or containing it), which would show things twice or expose too much."; $bad = true; continue;
        }
        $out[$name] = $real;
    }
    return $bad ? null : $out;
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
    foreach (['ADMIN_PASSWORD', 'PRIVATE_VIEW_PASSWORD', 'EXTRA_FOLDERS', 'NGINX_ACCEL_REDIRECT_PREFIX'] as $n) $order[] = $n;
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
            } elseif ($d['type'] === 'text') {
                if (!site_settings_valid_nginx_prefix($raw)) { $errors[] = '"' . $d['label'] . '" must be empty or a plain path like /kb (starting with /, no trailing slash, letters, digits and . _ ~ - only).'; continue; }
                $value = $raw;
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
        elseif (strlen($adminPw) < 12) $errors[] = 'The admin password needs at least 12 characters — it is the one credential that can change your gallery.';
        elseif (preg_match('/[\x00-\x1F\x7F]/', $adminPw)) $errors[] = 'The admin password can\'t contain control characters.';
        else { $setOrUnset('ADMIN_PASSWORD', $adminPw); $newAdmin = $adminPw; }
    }
    $viewPw = (string) ($_POST['pw_private'] ?? '');
    if (!empty($_POST['pw_private_remove'])) {
        $setOrUnset('PRIVATE_VIEW_PASSWORD', '');
    } elseif ($viewPw !== '') {
        if ($viewPw !== (string) ($_POST['pw_private2'] ?? '')) $errors[] = 'The two private-view passwords you typed don\'t match.';
        elseif (strlen($viewPw) < 6) $errors[] = 'The private-view password needs at least 6 characters.';
        elseif (preg_match('/[\x00-\x1F\x7F]/', $viewPw)) $errors[] = 'The private-view password can\'t contain control characters.';
        else $setOrUnset('PRIVATE_VIEW_PASSWORD', $viewPw);
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
        $_SESSION['admin_auth'] = hash('sha256', 'admin|' . $newAdmin . '|' . __DIR__);
        set_admin_reveal_cookie($newAdmin);   // the reveal token is derived from the password too
    }
    $n = count($cfg);
    if ($ajax) {
        // The page updates in place, so there is no "next page" to carry a message: say it now, and hand back the new file fingerprint.
        return ['type' => 'ok', 'text' => ($newAdmin !== null ? 'Admin password changed; you stay signed in.' : 'Saved.'), 'hash' => md5_file($path), 'differing' => $n, 'extra' => (object) ($cfg['EXTRA_FOLDERS'] ?? [])];
    }
    $_SESSION['admin_flash'] = ['type' => 'ok', 'text' => 'Settings saved' . ($newAdmin !== null ? ' (admin password changed; you stay signed in)' : '') . '. ' . ($n === 0 ? 'Everything is on its default.' : $n . ' setting' . ($n === 1 ? '' : 's') . ' differ' . ($n === 1 ? 's' : '') . ' from the defaults and will survive updating index.php.') . ' Your photos, captions, locations and counts were not touched.'];
    return ['type' => 'redirect'];
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
      <p class="muted" style="margin-top:0;">These apply to the whole gallery, and <strong>each change saves the moment you make it</strong>. They are saved in <code>.gallery-config.php</code> beside <code>index.php</code>, which survives replacing <code>index.php</code> with a newer version. <strong>Saving here only changes that file</strong>: your photos, captions, locations, hide flags, notes and view counts are never touched, and switching a setting off hides things without deleting anything.</p>
      <?php if ($broken): ?>
        <div class="flash error">The settings file exists but can't be read as settings, so this screen won't change it (that could overwrite what's in it). Fix or delete <code>.gallery-config.php</code>, then reload.</div>
      <?php endif; ?>
      <?php if (!empty($GLOBALS['__site_settings_manual'])): ?>
        <p class="muted">Create a file named <code>.gallery-config.php</code> next to <code>index.php</code> containing exactly this:</p>
        <textarea readonly rows="12" style="width:100%; font-family:ui-monospace,monospace;" onfocus="this.select()"><?= $esc($GLOBALS['__site_settings_manual']) ?></textarea>
      <?php endif; ?>
      <p class="muted sitesync" hidden>Saved. The folder list is already up to date; the photo grids and other tabs refresh when you leave this one.</p>
      <form method="post" class="site-settings-form">
        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
        <input type="hidden" name="action" value="save_site_settings">
        <input type="hidden" name="config_hash" value="<?= $esc($hash) ?>">
        <?php foreach (gallery_setting_definitions() as $group): ?>
          <h3 class="subhead"><?= $esc($group['title']) ?></h3>
          <?php foreach ($group['items'] as $d): $name = $d['name']; $now = defined($name) ? constant($name) : ($defaults[$name] ?? null); $def = $defaults[$name] ?? null; ?>
            <div class="site-row">
              <div>
                <div class="name"><?= $esc($d['label']) ?>
                  <?php if (isset($fromFile[$name])): ?><span class="src" title="This value comes from .gallery-config.php">your setting</span><?php endif; ?></div>
                <div class="help"><?= $esc($d['help']) ?></div>
                <?php if (!empty($d['risk'])): ?><div class="risk"><?= $esc($d['risk']) ?></div><?php endif; ?>
              </div>
              <div class="ctl">
                <?php if ($d['type'] === 'bool'): ?>
                  <input type="hidden" name="set_<?= $esc($name) ?>" value="0">
                  <label class="switch"><input type="checkbox" name="set_<?= $esc($name) ?>" value="1" <?= $now ? 'checked' : '' ?> data-initial="<?= $now ? '1' : '0' ?>" data-label="<?= $esc($d['label']) ?>"<?php if (!empty($d['risk'])): ?> data-risk="<?= $esc($d['risk']) ?>"<?php endif; ?><?php if (!empty($d['offwarn'])): ?> data-offwarn="<?= $esc($d['offwarn']) ?>"<?php endif; ?>><span class="slider"></span></label>
                <?php elseif ($d['type'] === 'enum'): ?>
                  <select name="set_<?= $esc($name) ?>"><?php foreach ($d['options'] as $val => $lab): ?><option value="<?= $esc($val) ?>" <?= (string) $now === (string) $val ? 'selected' : '' ?>><?= $esc($lab) ?></option><?php endforeach; ?></select>
                <?php elseif ($d['type'] === 'text'): ?>
                  <input type="text" name="set_<?= $esc($name) ?>" value="<?= $esc($now) ?>" placeholder="empty = off" spellcheck="false" autocomplete="off" data-label="<?= $esc($d['label']) ?>" data-risk="<?= $esc($d['risk'] ?? '') ?>" style="max-width:180px;">
                <?php elseif ($d['type'] === 'folders'): ?>
                  <div class="extra-folders" data-name="set_<?= $esc($name) ?>" data-map="<?= $esc(json_encode((object) $now, JSON_HEX_TAG)) ?>" data-label="<?= $esc($d['label']) ?>" data-risk="<?= $esc($d['risk']) ?>">
                    <ul class="extra-list"><?php foreach ($now as $fname => $fpath): ?><li><strong><?= $esc($fname) ?></strong> <span class="muted">&rarr;</span> <code><?= $esc($fpath) ?></code> <button type="button" class="extra-remove secondary" data-name="<?= $esc($fname) ?>">Remove</button></li><?php endforeach; ?></ul>
                    <p class="muted extra-none"<?= $now ? ' hidden' : '' ?>>None added.</p>
                    <div class="extra-add">
                      <input type="text" class="extra-name" placeholder="Name, e.g. OldTrip2020" maxlength="60" autocomplete="off">
                      <input type="text" class="extra-path" placeholder="/full/path/to/the/folder" autocomplete="off" spellcheck="false">
                      <button type="button" class="extra-add-btn secondary">Add folder</button>
                    </div>
                  </div>
                <?php else: ?>
                  <input type="number" name="set_<?= $esc($name) ?>" min="<?= (int) $d['min'] ?>" max="<?= (int) $d['max'] ?>" step="1" value="<?= $esc($now) ?>" style="max-width:110px;">
                <?php endif; ?>
                <span class="meta">default: <?= $esc($show($def)) ?></span>
                <span class="rowstate" role="status" aria-live="polite"></span>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endforeach; ?>

        <h3 class="subhead">Passwords</h3>
        <div class="site-row">
          <div>
            <div class="name">Admin password <?php if (isset($fromFile['ADMIN_PASSWORD'])): ?><span class="src">your setting</span><?php endif; ?></div>
            <div class="help">Currently set. Leave blank to keep it. At least 12 characters; you stay signed in after changing it. It can't be removed here, since that would lock this screen away.</div>
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
            <div class="help"><?= PRIVATE_VIEW_PASSWORD === '' ? 'Not set, so hidden items stay hidden from everyone but you.' : 'Currently set.' ?> What you tell someone so they can see the hidden items (at least 6 characters). Leave blank to keep it as it is.</div>
          </div>
          <div class="ctl col">
            <input type="password" name="pw_private" autocomplete="new-password" placeholder="New private-view password">
            <input type="password" name="pw_private2" autocomplete="new-password" placeholder="Type it again">
            <span class="pwline"><button type="button" class="pw-btn secondary" data-pw="private">Set private-view password</button><span class="rowstate" role="status" aria-live="polite"></span></span>
            <?php if (PRIVATE_VIEW_PASSWORD !== ''): ?><span class="pwline"><button type="button" class="pw-btn secondary" data-pw="private-remove" data-confirm="Remove the private-view password? Anyone you gave it to will no longer be able to see the hidden items. The hidden items themselves stay hidden and nothing is deleted.">Remove it (turn private view off)</button><span class="rowstate" role="status" aria-live="polite"></span></span>
            <label class="muted nojs-only"><input type="checkbox" name="pw_private_remove" value="1"> Remove it (turn private view off)</label><?php endif; ?>
          </div>
        </div>

        <div class="item-actions nojs-only" style="margin-top:14px;">
          <button type="submit"<?= $broken ? ' disabled' : '' ?>>Save site settings</button>
        </div>
        <p class="muted" style="margin-top:14px;"><span class="js-only">Changes save as you make them.</span> Only settings that differ from the defaults are stored, so a future update's better defaults still reach you.</p>
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
    // After saving a photo, bring that photo's card back into view.
    $focusCard = ($resultMessage && isset($_POST['file']) && is_string($_POST['file']))
        ? 'photo-' . md5(($adminFolderPath === null ? '' : $adminFolderPath . '/') . basename($_POST['file'])) : '';
    $itemsHere = items_read($adminFolderReal);
    $adminViews = TRACK_VIEWS ? stats_get_all_photo_views($dir, $adminFiles) : [];
    // Photos whose GPS the gallery can read from the file but that have no
    // *saved* location yet (and haven't been read before). With the
    // folder's switch on they were just imported above, so these are only
    // left when the switch is off — the editor pre-fills them for review,
    // and each is flagged below and counted in the folder panel.
    $adminAutoLoc = [];
    foreach ($adminFiles as $adminFile) {
        if (is_video_file($adminFile)) continue;
        $adminEntry = $itemsHere['items'][basename($adminFile)] ?? [];
        if (($adminEntry['location'] ?? null) !== null || !empty($adminEntry['gpsChecked'])) continue;
        $autoLoc = extract_photo_gps(resolve_real_path($dir, $adminFile));
        if ($autoLoc !== null) $adminAutoLoc[$adminFile] = $autoLoc;
    }
    // Shown before locations are turned on: it publishes places, so say how
    // many, and how to keep one back. Turning them off never asks.
    $gpsCount = count($adminAutoLoc);
    $locConfirm = 'Turn on public locations for this folder? ' . ($gpsCount > 0
        ? $gpsCount . ' photo' . ($gpsCount === 1 ? ' here has' : 's here have') . ' GPS in the file, and will show ' . ($gpsCount === 1 ? 'its' : 'their') . ' location on the gallery and the map.'
        : 'Any photo here with GPS in the file will show its location on the gallery and the map.')
        . ' You can keep a single photo private afterwards (Location visibility: Always hide).';
    $locConfirmAttr = htmlspecialchars(json_encode($locConfirm), ENT_QUOTES, 'UTF-8');
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
  h1 { font-size:1.4rem; margin:0 0 4px; }
  h2 { font-size:1.1rem; margin:28px 0 10px; }
  .muted { color:var(--muted); font-size:0.85rem; }
  /* Layout: a sticky bar, a folder tree beside the content, and sections
     you can jump to, so a long page is never a scroll hunt. */
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
  .hidden-list a { color:#8ab4ff; }
  .admin-layout { max-width:1180px; margin:0 auto; padding:18px 16px 64px; display:grid; grid-template-columns:210px minmax(0,1fr); gap:22px; align-items:start; }
  .admin-side { position:sticky; top:76px; max-height:calc(100vh - 92px); overflow-y:auto; }
  .admin-folders > summary { font-size:0.72rem; text-transform:uppercase; letter-spacing:0.06em; color:var(--muted); margin:0 0 8px; padding-left:12px; cursor:pointer; list-style:none; }
  .admin-side .muted { padding-left:12px; }
  .admin-folders > summary::-webkit-details-marker { display:none; }
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
    .admin-folders > summary::before { content:"\25B8  "; }
    .admin-folders[open] > summary::before { content:"\25BE  "; }
    .admin-folders > summary .here { display:inline; }
    .admin-folders[open] > summary { margin-bottom:6px; }
    .admin-layout { grid-template-columns:1fr; gap:12px; }
    .admin-side { position:static; max-height:none; }
    .admin-main .editor-grid { grid-template-columns:1fr 1fr; }
    .admin-main .editor-grid .wide2 { grid-column:1 / -1; }
    .admin-main .editor-head { flex-wrap:wrap; }
    .admin-main .editor-nav { margin-left:0; justify-content:flex-start; }
    .admin-main .thumb-grid { grid-template-columns:repeat(auto-fill, minmax(96px, 1fr)); gap:8px; }
    .admin-folder-nav { max-height:260px; overflow-y:auto; }
    .settings-grid, .settings-grid.root { grid-template-columns:1fr; }
  }
  .flash { padding:10px 14px; border-radius:8px; margin:14px 0; font-size:0.9rem; }
  .flash.ok { background:rgba(59,165,92,0.15); color:var(--ok); }
  .flash.error { background:rgba(237,66,69,0.15); color:var(--err); }
  .flash a { color:inherit; font-weight:600; text-decoration:underline; }
  .panel { background:var(--card); border-radius:10px; padding:16px; margin-bottom:16px; }
  label { display:block; font-size:0.8rem; color:var(--muted); margin-bottom:3px; }
  input[type=text], input[type=url], input[type=number], textarea, select {
    width:100%; background:#101012; border:1px solid #2a2a2e; color:var(--text);
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
  @media (max-width: 1100px) { .notes-layout { grid-template-columns:1fr; } .notes-reference { position:static; max-height:none; } }
  /* Photos tab: a grid of thumbnails, and one editor panel above it that
     opens with the selected photo's own form (every photo's real form is in
     the page; only the selected one is shown). */
  /* The left column's text all starts at the same place: 12px in, counting a pill's 1px border, so the pills,
     the FOLDERS label and every folder name line up. Site settings is teal (blue is "selected", red is Hidden). */
  .side-link { display:flex; align-items:center; margin:0 0 10px; padding:6px 10px 6px 11px; border-radius:6px; font-size:0.85rem; color:#5eead4; text-decoration:none; background:rgba(45,212,191,0.09); border:1px solid rgba(45,212,191,0.3); }
  .side-link:hover { background:rgba(45,212,191,0.17); }
  .side-link.active { color:#04201c; font-weight:600; background:#2dd4bf; border-color:#2dd4bf; }
  .site-row { display:grid; grid-template-columns:minmax(0,1fr) minmax(190px,260px); gap:6px 22px; padding:12px 0; border-top:1px solid #26262a; align-items:start; }
  .site-settings h3.subhead + .site-row, .site-settings .muted + .site-row { border-top:0; }
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
  .rowstate { font-size:0.78rem; color:var(--muted); min-width:3.6em; }
  .rowstate:empty { display:inline-block; }
  .rowstate.ok { color:var(--ok); }
  .rowstate.err { color:var(--err); flex-basis:100%; text-align:right; min-width:0; }
  .pwline .rowstate.err { text-align:left; }
  .pwline { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
  .pw-btn, .js-only { display:none; }
  .js-tabs .pw-btn { display:inline-block; }
  .js-tabs .js-only { display:inline; }
  .js-tabs .nojs-only { display:none !important; }
  .manual-box { margin:0 0 14px; }
  .site-row .ctl:has(.extra-folders) { flex-direction:column; align-items:stretch; justify-content:flex-start; }
  .extra-folders { width:100%; }
  .extra-list { list-style:none; margin:0 0 8px; padding:0; }
  .extra-list li { display:flex; align-items:center; gap:8px; flex-wrap:wrap; padding:6px 0; border-top:1px solid #26262a; font-size:0.85rem; }
  .extra-list li:first-child { border-top:0; }
  .extra-list code { word-break:break-all; }
  .extra-add { display:flex; flex-direction:column; gap:6px; }
  .photos-hint { margin:0 0 12px; }
  .thumb-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(124px, 1fr)); gap:10px; }
  .thumb { position:relative; padding:0; border:2px solid transparent; background:var(--card); border-radius:9px; cursor:pointer; overflow:hidden; text-align:left; color:var(--text); display:flex; flex-direction:column; font:inherit; }
  .thumb:hover { border-color:#3a3a42; }
  .thumb.selected { border-color:var(--accent); box-shadow:0 0 0 2px rgba(88,101,242,0.35); }
  .thumb .thumb-img { width:100%; aspect-ratio:1/1; object-fit:cover; display:block; background:#101012; }
  .thumb .thumb-video { display:flex; flex-direction:column; align-items:center; justify-content:center; font-size:1.6rem; color:var(--muted); }
  .thumb .thumb-video small { font-size:0.7rem; }
  .thumb-badges { position:absolute; top:5px; right:5px; display:flex; gap:3px; font-size:0.7rem; }
  .thumb-badges span { background:rgba(0,0,0,0.68); padding:1px 5px; border-radius:8px; line-height:1.4; }
  .thumb-badges .warn { color:#f5a623; }
  .thumb-badges .hid { color:#ff9a9c; }
  .thumb-name { font-size:0.72rem; color:var(--muted); padding:5px 7px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .thumb.dirty::after { content:""; position:absolute; left:6px; top:6px; width:10px; height:10px; border-radius:50%; background:#f5a623; box-shadow:0 0 0 2px rgba(0,0,0,0.6); }
  .thumb.flash-target { outline:2px solid var(--accent); outline-offset:2px; }
  .photo-editor { background:var(--card); border-radius:10px; padding:14px; margin-bottom:16px; scroll-margin-top:84px; }
  .js-tabs .photo-editor { display:none; }
  .js-tabs .photo-editor.has-open { display:block; }
  /* Only without JavaScript, where every photo's form is listed one after another. With it, one editor is shown at a time and must look identical whichever photo it is. */
  .admin-main:not(.js-tabs) .item-editor + .item-editor { border-top:1px solid #26262a; margin-top:16px; padding-top:16px; }
  .js-tabs .item-editor { display:none; }
  .js-tabs .item-editor.open { display:block; }
  .editor-head { display:flex; gap:14px; align-items:center; margin-bottom:14px; }
  .editor-head img, .editor-head .item-thumb-placeholder { width:96px; height:96px; flex:none; object-fit:cover; border-radius:8px; background:#101012; }
  .item-thumb-placeholder { display:flex; align-items:center; justify-content:center; color:var(--muted); font-size:0.75rem; text-align:center; }
  .item-meta { min-width:0; }
  .item-filename { font-size:0.9rem; color:var(--text); word-break:break-all; }
  .item-sub { font-size:0.78rem; color:var(--muted); margin-top:3px; }
  .editor-nav { margin-left:auto; display:flex; gap:6px; flex-wrap:wrap; justify-content:flex-end; }
  .editor-grid { display:grid; grid-template-columns:repeat(4, 1fr); gap:0 12px; }
  .editor-grid .wide2 { grid-column:span 2; }
  .item-actions { display:flex; gap:8px; align-items:center; margin-top:6px; }
  .item-actions form { margin:0; }
  .item-flags { color:var(--muted); font-size:0.75rem; }
  .loc-row { display:flex; gap:6px; }
  .loc-row .field { flex:1; }
  @media (max-width: 600px) {
    .wrap { padding:16px 10px 48px; }
    .grid { grid-template-columns:1fr; }
  }
</style>
</head>
<body>
<header class="admin-bar">
  <div class="admin-bar-inner">
    <a class="back" href="<?= htmlspecialchars(gallery_root_href(), ENT_QUOTES, 'UTF-8') ?>">&larr; Gallery</a>
    <h1>Gallery Admin</h1>
    <span class="crumb">Editing: <?= htmlspecialchars($folderLabel, ENT_QUOTES, 'UTF-8') ?></span>
    <nav class="admin-tabs" role="tablist" aria-label="Sections">
      <a href="#photos" role="tab" data-tab="photos">Photos <span class="count"><?= count($adminFiles) ?></span></a>
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
    <a href="#hidden" data-tab="hidden" class="side-hidden">Hidden <span class="count"><?= count($adminHidden['folders']) + count($adminHidden['items']) ?></span></a>
  <?php endif; ?>
  <a href="#site" data-tab="site" class="side-link">Site settings</a>
  <details class="admin-folders" open>
    <summary>Folders <span class="here">&middot; <?= htmlspecialchars($folderLabel, ENT_QUOTES, 'UTF-8') ?></span></summary>
    <?= render_admin_folder_nav($files, $adminFolderPath) ?: '<p class="muted">No subfolders.</p>' ?>
  </details>
</aside>
<main class="admin-main" data-focus-card="<?= htmlspecialchars($focusCard, ENT_QUOTES, 'UTF-8') ?>">
  <?php if ($resultMessage): ?>
    <div class="flash <?= htmlspecialchars($resultMessage['type'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($resultMessage['text'], ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

<?php if ($adminHidden): ?>
  <div class="tab-panel" data-tab="hidden">
<?php render_admin_hidden_panel($adminHidden); ?>
  </div>
<?php endif; ?>
  <div class="tab-panel" data-tab="photos">
  <?php if (TRACK_VIEWS): ?>
    <p class="muted" style="margin-top:0;">&#128202; <?= number_format(stats_get_pageviews($dir)) ?> visit<?= stats_get_pageviews($dir) === 1 ? '' : 's' ?> in total (one per visitor per half hour, not counting you or bots). Select a photo to see its own count. Visitors <?= SHOW_VIEW_COUNTS_PUBLICLY ? 'can see these counts' : 'cannot see any counts (SHOW_VIEW_COUNTS_PUBLICLY is off) — only you, here' ?>.</p>
  <?php endif; ?>

  <?php if (ENABLE_PHOTO_LOCATION && $adminAutoLoc): $unsavedCount = count($adminAutoLoc); ?>
    <div class="panel loc-unsaved-summary">
      <?php if (!empty($itemsHere['locationEnabled'])): ?>
        <strong>&#9888; <?= $unsavedCount ?> photo<?= $unsavedCount === 1 ? '' : 's' ?> here <?= $unsavedCount === 1 ? 'has' : 'have' ?> GPS that isn't saved yet.</strong>
        <span class="muted">It couldn't be stored automatically (is this folder writable by the web server?). Click <em>Save</em> on each photo to publish its location.</span>
      <?php else: ?>
        <strong>&#9888; <?= $unsavedCount ?> photo<?= $unsavedCount === 1 ? '' : 's' ?> here <?= $unsavedCount === 1 ? 'has' : 'have' ?> GPS in the file.</strong>
        <span class="muted">Nothing is public while this folder's location switch is off. Switching it on makes <?= $unsavedCount === 1 ? 'that location' : 'these ' . $unsavedCount . ' locations' ?> public on the gallery and the map, except for photos you set to <em>Always hide</em>.</span>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <h2 class="section-title" id="photos" style="margin-top:0; scroll-margin-top:84px;">Photos &amp; video <span class="muted">(<?= count($adminFiles) ?>)</span></h2>
<?php
$editorsHtml = '';
$thumbsHtml = '';
foreach ($adminFiles as $file) {
    $bare = basename($file);
    $entry = $itemsHere['items'][$bare] ?? [];
    $loc = $entry['location'] ?? ($adminAutoLoc[$file] ?? null);
    $locUnsaved = isset($adminAutoLoc[$file]);
    $rest = item_restriction($entry);
    $vis = $entry['locationVisibility'] ?? 'inherit';
    $isVideo = is_video_file($file);
    $isCover = !empty($itemsHere['cover']) && $itemsHere['cover'] === $bare;
    $hid = md5($file);
    $coverFormId = 'cover-' . $hid;
    $thumbUrl = htmlspecialchars(img_url($file, 'thumb'), ENT_QUOTES, 'UTF-8');
    $durationText = ($isVideo && isset($videoDurations[$file])) ? htmlspecialchars(format_duration($videoDurations[$file]), ENT_QUOTES, 'UTF-8') : '';
    ob_start();
?>
    <div class="item-editor" id="edit-<?= $hid ?>">
      <div class="editor-head">
        <?php if ($isVideo): ?>
          <div class="item-thumb-placeholder">&#9654; video<?php if ($durationText !== ''): ?><br><?= $durationText ?><?php endif; ?></div>
        <?php else: ?>
          <img src="<?= $thumbUrl ?>" alt="">
        <?php endif; ?>
        <div class="item-meta">
          <div class="item-filename"><?= htmlspecialchars($file, ENT_QUOTES, 'UTF-8') ?></div>
          <div class="item-sub">
            <?php if (TRACK_VIEWS): ?>&#128065; <?= number_format($adminViews[$file] ?? 0) ?> view<?= ($adminViews[$file] ?? 0) === 1 ? '' : 's' ?><?php endif; ?>
            <?php if ($rest === 'private'): ?> &middot; hidden<?php endif; ?>
            <?php if ($isCover): ?> &middot; folder cover<?php endif; ?>
          </div>
        </div>
        <div class="editor-nav">
          <button type="button" class="secondary" data-nav="-1" title="Previous photo">&lsaquo; Prev</button>
          <button type="button" class="secondary" data-nav="1" title="Next photo">Next &rsaquo;</button>
          <button type="button" class="secondary" data-close title="Close the editor">Close</button>
        </div>
      </div>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
        <input type="hidden" name="action" value="save_item">
        <input type="hidden" name="loaded_hash" value="<?= admin_record_hash($entry) ?>">
        <input type="hidden" name="file" value="<?= htmlspecialchars($bare, ENT_QUOTES, 'UTF-8') ?>">
        <div class="editor-grid">
          <div class="field wide2">
            <label>Caption</label>
            <input type="text" name="caption" value="<?= htmlspecialchars($entry['caption'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
          </div>
          <div class="field wide2">
            <label>Web link</label>
            <input type="url" name="web" value="<?= htmlspecialchars($entry['web'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="https://&hellip;">
          </div>
          <div class="field">
            <label>Latitude</label>
            <input type="text" name="lat" value="<?= htmlspecialchars($loc['lat'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
          </div>
          <div class="field">
            <label>Longitude</label>
            <input type="text" name="lng" value="<?= htmlspecialchars($loc['lng'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
          </div>
          <div class="field wide2">
            <label>Location label (optional)</label>
            <input type="text" name="loc_label" value="<?= htmlspecialchars($loc['label'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
          </div>
          <div class="field wide2">
            <label>Location visibility</label>
            <select name="loc_visibility">
              <option value="inherit" <?= $vis === 'inherit' ? 'selected' : '' ?>>Inherit folder setting</option>
              <option value="show" <?= $vis === 'show' ? 'selected' : '' ?>>Always show</option>
              <option value="hide" <?= $vis === 'hide' ? 'selected' : '' ?>>Always hide</option>
            </select>
          </div>
          <div class="field wide2">
            <label>Visibility</label>
            <select name="restriction">
              <option value="inherit" <?= $rest === 'inherit' ? 'selected' : '' ?>>Inherit folder setting</option>
              <option value="public" <?= $rest === 'public' ? 'selected' : '' ?>>Always show (even if this folder is hidden)</option>
              <option value="private" <?= $rest === 'private' ? 'selected' : '' ?>>Always hide</option>
            </select>
          </div>
        </div>
        <?php if ($locUnsaved): ?>
            <p class="muted loc-unsaved" style="margin:0 0 8px;">&#9888; <?= !empty($itemsHere['locationEnabled']) ? "Read from this photo's GPS but <strong>not saved yet</strong> &mdash; visitors see no location for it until you click Save." : "GPS found in this photo's file. <strong>Private for now</strong> &mdash; turning on locations for this folder makes it public (set <em>Always hide</em> to keep it out)." ?></p>
          <?php endif; ?>
        <div class="item-actions">
          <button type="submit">Save</button>
          <button type="submit" class="secondary" form="<?= $coverFormId ?>">Set as cover</button>
        </div>
      </form>
      <form method="post" id="<?= $coverFormId ?>">
        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
        <input type="hidden" name="action" value="set_cover">
        <input type="hidden" name="file" value="<?= htmlspecialchars($bare, ENT_QUOTES, 'UTF-8') ?>">
      </form>
    </div>
<?php
    $editorsHtml .= ob_get_clean();
    $thumbTitle = $file . (($entry['caption'] ?? '') !== '' ? ' — ' . $entry['caption'] : '');
    ob_start();
?>
    <button type="button" class="thumb" id="photo-<?= $hid ?>" data-edit="edit-<?= $hid ?>" title="<?= htmlspecialchars($thumbTitle, ENT_QUOTES, 'UTF-8') ?>">
      <?php if ($isVideo): ?>
        <span class="thumb-img thumb-video">&#9654;<?php if ($durationText !== ''): ?><small><?= $durationText ?></small><?php endif; ?></span>
      <?php else: ?>
        <img class="thumb-img" src="<?= $thumbUrl ?>" loading="lazy" alt="">
      <?php endif; ?>
      <span class="thumb-badges">
        <?php if (($entry['caption'] ?? '') !== ''): ?><span title="Has a caption">&#128172;</span><?php endif; ?>
        <?php if ($loc !== null && !$locUnsaved): ?><span title="Has a location">&#128205;</span><?php endif; ?>
        <?php if ($locUnsaved): ?><span class="warn" title="GPS found in the file, not saved">&#9888;</span><?php endif; ?>
        <?php if ($isCover): ?><span title="Folder cover">&#9733;</span><?php endif; ?>
        <?php if ($rest === 'private'): ?><span class="hid" title="Hidden from visitors">hidden</span><?php endif; ?>
      </span>
      <span class="thumb-name"><?= htmlspecialchars($bare, ENT_QUOTES, 'UTF-8') ?></span>
    </button>
<?php
    $thumbsHtml .= ob_get_clean();
}
?>
  <?php if (empty($adminFiles)): ?>
    <p class="muted">No photos or videos in this folder.</p>
  <?php else: ?>
    <p class="muted photos-hint">Select a photo to edit its caption, link, location and visibility.</p>
    <div class="photo-editor" id="photo-editor"><?= $editorsHtml ?></div>
    <div class="thumb-grid"><?= $thumbsHtml ?></div>
  <?php endif; ?>
  </div>
  <div class="tab-panel" data-tab="settings">
  <?php if ($adminFolderPath !== null): ?>
  <section class="panel" id="settings">
    <h2 class="section-title">This folder's own settings</h2>
    <?php render_admin_identity_form($itemsHere, $csrfToken, $adminFolderPath); ?>
    <h3 class="subhead">Privacy</h3>
    <div class="privacy-row">
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
      <input type="hidden" name="action" value="toggle_location_enabled">
      <label><input type="checkbox" name="enabled" value="1" <?= !empty($itemsHere['locationEnabled']) ? 'checked' : '' ?> onchange="if (this.checked &amp;&amp; !confirm(<?= $locConfirmAttr ?>)) { this.checked = false; return; } this.form.submit()"> Show photo locations publicly for this folder<?php if (!ENABLE_PHOTO_LOCATION): ?> <span class="muted">(ENABLE_PHOTO_LOCATION is off — nothing shows regardless)</span><?php endif; ?></label>
    </form>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
      <input type="hidden" name="action" value="toggle_folder_restriction">
      <input type="hidden" name="hidden" value="<?= !empty($itemsHere['restriction']) && $itemsHere['restriction'] === 'private' ? '0' : '1' ?>">
      <button type="submit" class="<?= (!empty($itemsHere['restriction']) && $itemsHere['restriction'] === 'private') ? 'secondary' : 'danger' ?>"><?= (!empty($itemsHere['restriction']) && $itemsHere['restriction'] === 'private') ? 'Make this folder public again' : 'Hide this entire folder from public view' ?></button>
    </form>
    </div>
    <?php render_admin_map_form($itemsHere, $csrfToken, false); ?>
    <?php if (!empty($itemsHere['cover'])): ?>
      <p class="muted" style="margin-top:10px;">Cover photo: <?= htmlspecialchars($itemsHere['cover'], ENT_QUOTES, 'UTF-8') ?> — click "Set as cover" on any photo below to change it.</p>
    <?php endif; ?>
  </section>
  <?php else: ?>
  <section class="panel" id="settings">
    <h2 class="section-title">Gallery settings</h2>
    <?php render_admin_identity_form($itemsHere, $csrfToken, null); ?>
    <?php if (!empty($itemsHere['cover'])): ?>
      <p class="muted" style="margin-top:10px;">Cover photo (the picture in shared-link previews): <?= htmlspecialchars($itemsHere['cover'], ENT_QUOTES, 'UTF-8') ?> &mdash; choose another with "Set as cover" on a photo in the Photos tab.</p>
    <?php else: ?>
      <p class="muted" style="margin-top:10px;">Shared links use the first photo as their picture. To choose one, select a photo in the Photos tab and use "Set as cover".</p>
    <?php endif; ?>
    <h3 class="subhead">Privacy</h3>
    <div class="privacy-row">
      <label><input type="checkbox" disabled <?= !empty($itemsHere['locationEnabled']) ? 'checked' : '' ?>> Show photo locations publicly at the root</label>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
        <input type="hidden" name="action" value="toggle_location_enabled">
        <input type="hidden" name="enabled" value="<?= !empty($itemsHere['locationEnabled']) ? '0' : '1' ?>">
        <button type="submit" class="secondary"<?php if (empty($itemsHere['locationEnabled'])): ?> onclick="return confirm(<?= $locConfirmAttr ?>)"<?php endif; ?>><?= !empty($itemsHere['locationEnabled']) ? 'Disable' : 'Enable' ?> for the root</button>
      </form>
    </div>
    <?php render_admin_map_form($itemsHere, $csrfToken, true); ?>
    <h3 class="subhead">Settings file</h3>
    <?php $cfgApplied = array_values(array_unique($GLOBALS['__gallery_config_applied'] ?? [])); ?>
    <?php if (is_file(__DIR__ . '/.gallery-config.php')): ?>
      <p class="muted" style="margin:0;"><code>.gallery-config.php</code> is in use and keeps its settings when you replace <code>index.php</code>.
        <?= $cfgApplied ? count($cfgApplied) . ' setting' . (count($cfgApplied) === 1 ? '' : 's') . ' come from it: ' . htmlspecialchars(implode(', ', $cfgApplied), ENT_QUOTES, 'UTF-8') . '.' : 'It doesn\'t override anything yet.' ?>
        Everything else uses the defaults in <code>index.php</code>.</p>
    <?php else: ?>
      <p class="muted" style="margin:0;">No settings file. Your settings live in <code>index.php</code>, so replacing it with a newer version resets them. To keep them across updates, put them in a <code>.gallery-config.php</code> next to it (see the README, "Keeping your settings across updates").</p>
    <?php endif; ?>
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
      $editable = $isActive ? strip_gallery_marker($raw) : $raw;
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
          <div class="field-row" style="margin-top:8px; align-items:flex-end;">
            <div class="field" style="max-width:260px;">
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
        <div class="field-row" style="margin-top:8px; align-items:flex-end;">
          <div class="field" style="max-width:260px;">
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
<?php render_admin_site_settings($csrfToken); ?>
  </div>
  <div class="tab-panel" data-tab="export">
  <section class="panel" id="export">
    <h2 class="section-title">Export</h2>
    <p class="muted">Downloads this folder's captions, locations, web links, display name, and chosen cover as JSON — the only backup this data has.</p>
    <p class="muted"><strong>Handle the file with care:</strong> it also records which items and folders you've hidden, and any GPS coordinates you've entered. Pasting it into a support email or leaving it somewhere public would reveal both, regardless of what's actually hidden on the gallery.</p>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
      <input type="hidden" name="action" value="export_items">
      <button type="submit" class="secondary">Export as JSON</button>
    </form>
    <form method="post" style="margin-top:8px;">
      <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
      <input type="hidden" name="action" value="export_tree">
      <button type="submit" class="secondary">Export the whole tree as JSON</button>
    </form>
  </section>
  </div>
</main>
</div>
<script>
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
  document.querySelectorAll('.admin-insert-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const notes = document.querySelectorAll('.adm-textarea');
      const textarea = lastNote || (notes.length === 1 ? notes[0] : null);
      const hint = document.querySelector('.insert-hint');
      if (!textarea) { if (hint) hint.hidden = false; return; }
      if (hint) hint.hidden = true;
      const snippet = btn.getAttribute('data-snippet');
      const start = textarea.selectionStart, end = textarea.selectionEnd;
      textarea.value = textarea.value.slice(0, start) + snippet + textarea.value.slice(end);
      textarea.focus();
      textarea.selectionStart = textarea.selectionEnd = start + snippet.length;
    });
  });
  // Tabs: only the selected section is shown. #name in the address picks one
  // (so a link can point straight at a tab, or at one photo's card), the last
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
      if (names.indexOf(name) < 0) name = names.indexOf('photos') >= 0 ? 'photos' : names[0];
      panels.forEach(function (p) { p.classList.toggle('active', p.getAttribute('data-tab') === name); });
      tabs.forEach(function (t) {
        var on = t.getAttribute('data-tab') === name;
        t.classList.toggle('active', on);
        t.setAttribute('aria-selected', on ? 'true' : 'false');
      });
      // "Hidden" is a look at the whole tree, not a section of a folder:
      // moving to another folder shouldn't land you back on it.
      if (name !== 'hidden' && name !== 'site') { try { sessionStorage.setItem(KEY, name); } catch (e) {} }
      return name;
    }
    // The photo editor: one panel above the thumbnail grid that shows the
    // selected photo's own form (and none at all until one is selected).
    var slot = document.getElementById('photo-editor');
    var editors = [].slice.call(document.querySelectorAll('.item-editor'));
    var thumbs = [].slice.call(document.querySelectorAll('.thumb[data-edit]'));
    function openEditor(id, scroll) {
      var any = false;
      editors.forEach(function (e) { var on = e.id === id; e.classList.toggle('open', on); if (on) any = true; });
      thumbs.forEach(function (t) { t.classList.toggle('selected', t.getAttribute('data-edit') === id); });
      if (slot) slot.classList.toggle('has-open', any);
      if (any && scroll && slot) slot.scrollIntoView({ block: 'start' });
    }
    thumbs.forEach(function (t) {
      t.addEventListener('click', function () { openEditor(t.getAttribute('data-edit'), true); });
    });
    editors.forEach(function (e, i) {
      var thumb = document.querySelector('.thumb[data-edit="' + e.id + '"]');
      e.addEventListener('input', function () { if (thumb) thumb.classList.add('dirty'); });
      [].forEach.call(e.querySelectorAll('[data-nav]'), function (b) {
        b.addEventListener('click', function () {
          var n = i + parseInt(b.getAttribute('data-nav'), 10);
          if (n >= 0 && n < editors.length) { openEditor(editors[n].id, false); if (slot) slot.scrollIntoView({ block: 'nearest' }); }
        });
      });
      var close = e.querySelector('[data-close]');
      if (close) close.addEventListener('click', function () { openEditor(null); });
    });
    function focusCard(id) {
      var t = id && document.getElementById(id);
      if (!t || !t.getAttribute('data-edit')) return;
      t.classList.add('flash-target');
      openEditor(t.getAttribute('data-edit'), true);
      setTimeout(function () { t.classList.remove('flash-target'); }, 2500);
    }
    function fromLocation() {
      var h = decodeURIComponent((location.hash || '').slice(1));
      if (h.indexOf('photo-') === 0) { show('photos'); focusCard(h); return; }
      // Just saved a photo: its editor comes back open, whichever tab the
      // address still names.
      var card = main.getAttribute('data-focus-card');
      if (card) { show('photos'); focusCard(card); return; }
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
  // Site settings: every change saves the moment it is made (one request at a time, so the file's
  // fingerprint never races), and anything that publishes content, contacts a third party, or removes
  // something visitors see asks first. The other screens refresh when you leave this one.
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
        var on = cb.checked, label = cb.getAttribute('data-label'), risk = cb.getAttribute('data-risk'), off = cb.getAttribute('data-offwarn');
        var msg = on && risk ? 'Turn on "' + label + '"?\n\n' + risk : (!on && off ? 'Turn off "' + label + '"?\n\n' + off : '');
        if (msg && !confirm(msg)) { cb.checked = !on; return; }
        var f = {}; f[cb.name] = on ? '1' : '0';
        post(f, stateOf(cb), function () { cb.checked = !on; });
      });
    });
    [].forEach.call(form.querySelectorAll('select[name^=set_], input[type=number][name^=set_]'), function (el) {
      el.setAttribute('data-prev', el.value);
      el.addEventListener('change', function () {
        var f = {}; f[el.name] = el.value;
        post(f, stateOf(el), function () { el.value = el.getAttribute('data-prev'); }, function () { el.setAttribute('data-prev', el.value); });
      });
    });
    [].forEach.call(form.querySelectorAll('input[type=text][name^=set_]'), function (el) {
      el.setAttribute('data-prev', el.value);
      el.addEventListener('change', function () {
        el.value = el.value.trim();
        var risk = el.getAttribute('data-risk'), label = el.getAttribute('data-label');
        if (el.value !== '' && risk && !confirm('Set "' + label + '" to ' + el.value + '?\n\n' + risk)) { el.value = el.getAttribute('data-prev'); return; }
        var f = {}; f[el.name] = el.value;
        post(f, stateOf(el), function () { el.value = el.getAttribute('data-prev'); }, function () { el.setAttribute('data-prev', el.value); });
      });
    });
    [].forEach.call(form.querySelectorAll('.extra-folders'), function (box) {
      var map = {};
      try { map = JSON.parse(box.getAttribute('data-map')) || {}; } catch (e) {}
      var list = box.querySelector('.extra-list'), none = box.querySelector('.extra-none'), status = stateOf(box);
      function draw() {
        list.textContent = '';
        Object.keys(map).forEach(function (name) {
          var li = document.createElement('li'), strong = document.createElement('strong'), arrow = document.createElement('span'), code = document.createElement('code'), btn = document.createElement('button');
          strong.textContent = name; arrow.className = 'muted'; arrow.textContent = '\u2192'; code.textContent = map[name];
          btn.type = 'button'; btn.className = 'extra-remove secondary'; btn.textContent = 'Remove'; btn.setAttribute('data-name', name);
          li.appendChild(strong); li.appendChild(document.createTextNode(' ')); li.appendChild(arrow); li.appendChild(document.createTextNode(' ')); li.appendChild(code); li.appendChild(document.createTextNode(' ')); li.appendChild(btn);
          list.appendChild(li);
        });
        none.hidden = Object.keys(map).length > 0;
      }
      function send(next, onOk) {
        var f = {}; f[box.getAttribute('data-name')] = JSON.stringify(next);
        post(f, status, null, function (j) { map = (j.extra && typeof j.extra === 'object') ? j.extra : next; draw(); if (onOk) onOk(); });
      }
      box.querySelector('.extra-add-btn').addEventListener('click', function () {
        var n = box.querySelector('.extra-name').value.trim(), p = box.querySelector('.extra-path').value.trim();
        if (!n || !p) { say(status, 'Give it a name and a full path.', 'err'); return; }
        if (!confirm('Add "' + n + '" (' + p + ')?\n\n' + box.getAttribute('data-risk'))) return;
        var next = {}; Object.keys(map).forEach(function (k) { next[k] = map[k]; }); next[n] = p;
        send(next, function () { box.querySelector('.extra-name').value = ''; box.querySelector('.extra-path').value = ''; });
      });
      list.addEventListener('click', function (ev) {
        var b = ev.target.closest ? ev.target.closest('.extra-remove') : null;
        if (!b) return;
        var n = b.getAttribute('data-name');
        if (!confirm('Stop showing "' + n + '"?\n\nNothing is deleted: its photos and their captions stay where they are, and come back if you add it again.')) return;
        var next = {}; Object.keys(map).forEach(function (k) { if (k !== n) next[k] = map[k]; });
        send(next);
      });
      draw();
    });
    [].forEach.call(form.querySelectorAll('.pw-btn'), function (btn) {
      function go() {
        var kind = btn.getAttribute('data-pw'), node = stateOf(btn), f = {}, els = form.elements;
        if (kind === 'admin') { f.pw_admin = els['pw_admin'].value; f.pw_admin2 = els['pw_admin2'].value; }
        else if (kind === 'private') { f.pw_private = els['pw_private'].value; f.pw_private2 = els['pw_private2'].value; }
        else { if (!confirm(btn.getAttribute('data-confirm'))) return; f.pw_private_remove = '1'; }
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
        $cfg['ADMIN_PASSWORD'] = $password;
        $content = gallery_config_render($cfg);
        if ($content === null) return ['error' => 'That password has characters that can\'t be stored. Try a different one.'];
        $manual = ['error' => 'The gallery\'s folder isn\'t writable by the web server, so the password couldn\'t be saved. Create a file named .gallery-config.php next to index.php containing exactly the text below, then reload this page.', 'manual' => $content];
        if (!is_writable(__DIR__)) return $manual;
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $content, LOCK_EX) === false) { @unlink($tmp); return $manual; }
        @chmod($tmp, 0600);
        [$roundStatus, $roundTrip] = gallery_config_parse((string) @file_get_contents($tmp));
        if ($roundStatus !== 'ok' || ($roundTrip['ADMIN_PASSWORD'] ?? null) !== $password) { @unlink($tmp); return ['error' => 'The settings file didn\'t read back correctly, so it was not installed.']; }
        if (!@rename($tmp, $path)) { @unlink($tmp); return $manual; }
        return ['ok' => true];
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
    <label for="setup_password">Admin password (at least 12 characters)</label>
    <input type="password" id="setup_password" name="setup_password" autocomplete="new-password" autofocus required minlength="12">
    <label for="setup_password2">Type it again</label>
    <input type="password" id="setup_password2" name="setup_password2" autocomplete="new-password" required minlength="12">
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
        elseif (strlen($pw) < 12) $error = 'Use at least 12 characters: this one password can change your whole gallery.';
        elseif (preg_match('/[\x00-\x1F\x7F]/', $pw)) $error = 'The password can\'t contain control characters.';
        else {
            $result = admin_setup_claim($pw);
            if (!empty($result['ok'])) {
                // Signed in as part of setting it up, and the setup file (if any) has done its job.
                admin_start_session();
                session_regenerate_id(true);
                $_SESSION['admin_auth'] = hash('sha256', 'admin|' . $pw . '|' . __DIR__);
                set_admin_reveal_cookie($pw);
                $_SESSION['admin_flash'] = ['type' => 'ok', 'text' => 'Your admin password is set and you\'re signed in. Site settings (left) is where the gallery-wide settings live; the password is kept in .gallery-config.php, which survives updating index.php.'];
                @unlink(__DIR__ . '/.gallery-setup');
                header('Location: ?admin#site', true, 303);
                return;
            }
            $error = (string) ($result['error'] ?? 'Could not set the password.');
            $manual = (string) ($result['manual'] ?? '');
            if (!empty($result['taken'])) {
                // Someone else got there first: this is a login page now.
                render_login_page('Gallery admin', 'Enter the admin password.', $error);
                return;
            }
        }
    }
    render_setup_page($error, $manual);
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
        $formPass = ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action']) && isset($_POST['login_password']) && is_string($_POST['login_password'])) ? $_POST['login_password'] : null;
        if ($basicSupplied && $formPass === null) {
            if (!admin_credentials_valid()) {
                login_throttle_record_failure($dir, 'admin', $ip);
                admin_reject_saved_credentials();
                return;
            }
        } elseif ($formPass !== null) {
            if (!hash_equals(ADMIN_PASSWORD, $formPass)) {
                login_throttle_record_failure($dir, 'admin', $ip);
                render_login_page('Gallery admin', 'Enter the admin password.', 'That password isn\'t right.');
                return;
            }
            // A fresh session id at the moment of login, so nothing issued
            // before it can ever be a logged-in session.
            admin_start_session();
            session_regenerate_id(true);
            $_SESSION['admin_auth'] = admin_session_marker();
            login_throttle_record_success($dir, 'admin', $ip);
            set_admin_reveal_cookie();
            header('Location: ?' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? $_SERVER['QUERY_STRING'] : 'admin'), true, 303);
            return;
        } else {
            render_login_page('Gallery admin', 'Enter the admin password.', $_SERVER['REQUEST_METHOD'] === 'POST' ? 'Your session expired. Sign in again, then repeat that change.' : '');
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
            header('Location: ' . gallery_root_href(), true, 303);
            return;
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
    $slug = slugify($key);
    $count = count($groupFiles);
    $groupTotal = 0;
    foreach ($groupFiles as $gf) {
        $groupTotal += isset($photoViews[$gf]) ? (int) $photoViews[$gf] : 0;
    }
    $viewsHidden = ($groupTotal > 0 && SHOW_VIEW_COUNTS_PUBLICLY) ? '' : ' hidden';

    $html = '<div class="linked-photos' . ($status ? ' ' . $status : '') . '">';
    $html .= '<div class="linked-photos-thumbs">';
    foreach ($groupFiles as $gf) {
        $idx = array_search($gf, $allFiles, true);
        if ($idx === false) continue;
        $html .= '<img src="' . htmlspecialchars(img_url($gf, 'thumb'), ENT_QUOTES, 'UTF-8')
                . '" loading="lazy" onclick="openLightbox(' . (int)$idx . ')" alt="'
                . htmlspecialchars($gf, ENT_QUOTES, 'UTF-8') . '">';
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
        $html .= '<img src="' . htmlspecialchars(img_url($gf, 'thumb'), ENT_QUOTES, 'UTF-8')
                . '" loading="lazy" onclick="openLightbox(' . (int)$idx . ')" alt="'
                . htmlspecialchars($gf, ENT_QUOTES, 'UTF-8') . '">';
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
         . '" title="Copy link">&#128279;</a>';
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

/**
 * A collapsed-by-default banner surfacing $galleryNotices directly on
 * the page, not just in the HTML-comment fallback in the page source —
 * "view page source" isn't something most people would think to try.
 * Addressed explicitly to the gallery owner, so a visitor who clicks it
 * out of curiosity can see right away it isn't meant for them.
 */
function render_gallery_notices_banner($notices, $open = false) {
    if (empty($notices)) return '';
    $count = count($notices);
    $html = '<details class="gallery-notices"' . ($open ? ' open' : '') . '>';
    $html .= '<summary>&#9888; ' . $count . ' setup notice' . ($count === 1 ? '' : 's') . ' for the gallery owner</summary>';
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
function markdown_to_html($text, $groups, $files, &$groupColors, &$groupStatus, &$groupLabels, &$renderClaims, $photoViews) {
    $text = str_replace("\r\n", "\n", $text);
    $lines = explode("\n", $text);
    $html = '';
    $listType = null;
    $inCode = false;
    $codeBuf = '';
    $paraLines = [];

    $flushPara = function () use (&$html, &$paraLines, $groups, $files, &$groupColors, &$groupStatus, &$groupLabels, &$renderClaims, $photoViews) {
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
            $l = trim(preg_replace(
                ['/\{\s*color\s*:\s*[^}]+\}/i', '/\{\s*sold\s*\}/i', '/\{\s*(reserved|held)\s*\}/i',
                 '/\{\s*group\s*:\s*[^}]+\}/i', '/\{\s*photos\s*:\s*[^}]+\}/i'],
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
                $p = basename(trim($p));
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
            $itemId = 'item-' . slugify($explicitPhotos[0]);
        }
        $idAttr = $itemId ? (' id="' . htmlspecialchars($itemId, ENT_QUOTES, 'UTF-8') . '"') : '';

        $inner = implode('<br>', array_map('inline_markdown', $cleanLines));
        if ($status === 'sold') {
            $html .= '<div class="sold-item"' . $idAttr . '><span class="sold-badge">SOLD</span><p>' . $inner . '</p></div>';
        } elseif ($status === 'reserved') {
            $html .= '<div class="reserved-item"' . $idAttr . '><span class="reserved-badge">RESERVED</span><p>' . $inner . '</p></div>';
        } else {
            $html .= '<p' . $idAttr . '>' . $inner . '</p>';
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
                if ($colorValue !== null) $groupColors[$pf] = $colorValue;
                if ($status) $groupStatus[$pf] = $status;
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
            $html .= "<h{$level}>" . inline_markdown($m[2]) . "</h{$level}>";
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
    $hidden = ($views > 0 && SHOW_VIEW_COUNTS_PUBLICLY) ? '' : ' hidden';
    $badge = '<span class="view-badge' . $hidden . '" data-photo="' . htmlspecialchars($file, ENT_QUOTES, 'UTF-8') . '">&#128065; '
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
                     . '" role="button" tabindex="0" onclick="toggleCaptionPopover(this, event)" onkeydown="if (event.key === \'Enter\' || event.key === \' \') toggleCaptionPopover(this, event)">&#128172;</span>';
    }
    if (photo_location_visible($itemEntry ?? [])) {
        $loc = $itemEntry['location'];
        $locTitle = trim(($loc['label'] ?? '') !== '' ? $loc['label'] : ($loc['lat'] . ', ' . $loc['lng']));
        $itemBadges .= '<a class="item-badge location-badge" href="' . htmlspecialchars(osm_location_url($loc['lat'], $loc['lng']), ENT_QUOTES, 'UTF-8')
                     . '" target="_blank" rel="noopener noreferrer" title="' . htmlspecialchars($locTitle, ENT_QUOTES, 'UTF-8')
                     . '" onclick="event.stopPropagation()">&#128205;</a>';
    }
    if (!empty($itemEntry['web'])) {
        $itemBadges .= '<a class="item-badge web-badge" href="' . htmlspecialchars($itemEntry['web'], ENT_QUOTES, 'UTF-8')
                     . '" target="_blank" rel="noopener noreferrer" title="' . htmlspecialchars($itemEntry['web'], ENT_QUOTES, 'UTF-8')
                     . '" onclick="event.stopPropagation()">&#8505;</a>';
    }
    $colorStyle = $color ? ' style="box-shadow: 0 0 0 3px ' . htmlspecialchars($color, ENT_QUOTES, 'UTF-8') . ' inset;"' : '';
    // $anchorId lets a standalone single be a valid "#group-<slug>" jump
    // target too — render_group_thumbs() always links there for a
    // mentioned item, whether it turned out to be a real box or just a
    // single card; only a box's own container carried that id before,
    // leaving the link dead for a single.
    $idAttr = $anchorId ? ' id="' . htmlspecialchars($anchorId, ENT_QUOTES, 'UTF-8') . '"' : '';
    return '<div class="card' . ($status ? ' ' . $status : '') . '"' . $idAttr . $colorStyle . ' onclick="openLightbox(' . $index . ')"><img src="'
         . htmlspecialchars(img_url($file, 'thumb'), ENT_QUOTES, 'UTF-8')
         . '" loading="lazy" alt="' . htmlspecialchars($file, ENT_QUOTES, 'UTF-8') . '">' . $durationBadge . $statusBadge
         . '<div class="card-badges">' . $itemBadges . $badge . '</div></div>';
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

function render_folder_cover_card($folderPath, $label, $recursiveFiles, $manualCover = null, $idKey = null, $meta = null) {
    $folderUrl = htmlspecialchars('?folder=' . rawurlencode($folderPath), ENT_QUOTES, 'UTF-8');
    $coverFile = pick_folder_cover($recursiveFiles, $manualCover);
    $count = count($recursiveFiles);
    $slug = slugify($idKey ?? $folderPath);
    $html = '<div class="folder-cover" id="group-' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') . '">';
    $html .= '<a class="folder-cover-link" href="' . $folderUrl . '">';
    if ($coverFile !== null) {
        $html .= '<img src="' . htmlspecialchars(img_url($coverFile, 'thumb'), ENT_QUOTES, 'UTF-8')
                . '" loading="lazy" alt="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '">';
    }
    $html .= '<span class="folder-cover-label">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
    if (!empty($meta['dates'])) {
        $html .= '<span class="folder-cover-dates">' . htmlspecialchars($meta['dates'], ENT_QUOTES, 'UTF-8') . '</span>';
    }
    $html .= '<span class="folder-cover-count">' . htmlspecialchars(media_count_label($recursiveFiles), ENT_QUOTES, 'UTF-8') . '</span>';
    $html .= '</a>';
    $html .= '<a class="permalink-copy folder-permalink-copy" href="' . $folderUrl . '" title="Copy link">&#128279;</a>';
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
            $html .= render_folder_cover_card($folderLinkPrefix . $key, $label, $groupFiles, $folderCovers[$key] ?? null, $key, $folderMeta[$key] ?? null);
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
        $viewsHidden = ($groupTotal > 0 && SHOW_VIEW_COUNTS_PUBLICLY) ? '' : ' hidden';

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
    $aboutDescription = 'A single-file, drop-in PHP photo gallery for trips and listings: nested folders, video, captions, a map, Markdown notes, view stats, and deep links.';
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
  .back-link { color: var(--muted); text-decoration: none; font-size: 0.9rem; }
  .back-link:hover { color: var(--text); text-decoration: underline; }
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
  ul { padding-left: 22px; }
  li { margin-bottom: 6px; }
  code {
    background: rgba(255,255,255,0.08); padding: 1px 6px; border-radius: 4px;
    font-size: 0.88em; color: #eaeaea;
  }
  a { color: #8ab4ff; }
</style>
</head>
<body>
<div class="wrap">

  <div class="top-bar">
    <a class="back-link" href="<?= htmlspecialchars(gallery_root_href(), ENT_QUOTES, 'UTF-8') ?>">&larr; Back to gallery</a>
    <span class="version-tag">v<?= $version ?></span>
  </div>

  <h1>Drop-in Photo Gallery</h1>
  <p class="subtitle">A single PHP file, no dependencies, no build step. Drop it into any folder of photos.</p>
  <a class="download-btn" href="<?= htmlspecialchars($repoUrl, ENT_QUOTES, 'UTF-8') ?>">&#11015; Get it on GitHub</a>

  <h2>What it does</h2>
  <ul>
    <li>Groups photos automatically by filename, or curate groups explicitly in a <code>.md</code> file — no naming convention required</li>
    <li>Full-screen lightbox with keyboard nav and mobile swipe</li>
    <li>Optional <code>.md</code> notes box — auto-linked thumbnails, <code>{color}</code>/<code>{sold}</code>/<code>{reserved}</code> directives</li>
    <li>Live view stats and thumbnail caching — both opt-in, so nothing is written to your folder unless you turn them on</li>
    <li>HEIC/HEIF (iPhone photo) support once thumbnail caching is enabled</li>
    <li>Deep links (<code>?photo=</code>, <code>#group-name</code>, <code>#item-name</code>) with correct Open Graph previews when shared</li>
    <li>For trips: nested folders to any depth, video, per-photo captions and locations, an owner-only admin editor, items you can hide from the public link, and a map &mdash; each one opt-in</li>
  </ul>

  <h2>Full documentation &amp; source</h2>
  <p>This page is just the pitch — setup, configuration, directive syntax, and every feature in depth are documented on GitHub: <a href="<?= htmlspecialchars($repoUrl) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($repoUrl) ?></a>.</p>

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

// The trip map's data — computed once here, reused both by ?map's own
// dedicated page below and the condensed preview embedded on the
// gallery root (see the main template further down). Once $files/
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
$mapRadius = ENABLE_MAP ? folder_map_radius($dir, $currentFolderPath) : MAP_CLUSTER_RADIUS_MILES;

// ?map — the dedicated, shareable map page (?map&folder=X for one folder's own).
if (ENABLE_MAP && isset($_GET['map'])) {
    if (!$mapShown) {
        http_response_code(404);
        exit;
    }
    render_map_page($mapPoints, $mapLegend, $currentFolderPath === null ? $rootPageTitle : $pageTitle, $baseUrl, $currentFolderPath, $mapRadius);
    exit;
}

$ogTitle = $pageTitle;
$ogUrl = $baseUrl . '/';
$ogDescription = $rootMeta['description'] !== '' ? $rootMeta['description'] : (!empty($mdFiles)
    ? markdown_to_plaintext($mdContents[$mdFiles[0]])
    : media_count_label($files));
// The cover chosen in the admin, if it is still a visible root-level photo.
$ogRootPhoto = ($rootMeta['cover'] !== null && in_array($rootMeta['cover'], $files, true)) ? $rootMeta['cover'] : ($files[0] ?? null);
$ogImageUrl = $ogRootPhoto !== null ? $baseUrl . '/' . img_url($ogRootPhoto, 'share') : '';
if ($currentFolderPath !== null) {
    // $pageTitle is already folder-prefixed (see the ?folder= validation
    // block above), so $ogTitle below already picked that up — only the
    // url/description/image need their own folder-scoped values here.
    $ogUrl = $baseUrl . '/?folder=' . rawurlencode($currentFolderPath);
    $folderMemberFiles = $currentFolderFiles;
    $ogDescription = media_count_label($folderMemberFiles);
    if ($currentFolderMeta['description'] !== '') {
        $ogDescription = $currentFolderMeta['description'];
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
send_viewer_aware_cache_headers();
?>
<!-- Drop-in Photo Gallery v<?= htmlspecialchars(GALLERY_VERSION) ?> — github.com/pcmike -->
<?php if (!empty($galleryNotices)): ?>
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
  /* The title is also the way into ?admin, deliberately unmarked: it looks
     and behaves like plain text (no underline, colour, cursor or hover
     change). Keyboard focus still shows the browser's normal ring. */
  header h1 a.title-admin {
    color: inherit;
    text-decoration: none;
    cursor: inherit;
    -webkit-tap-highlight-color: transparent;
  }
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
  .note-box a { color: #8ab4ff; }
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
    color: #8ab4ff;
    font-size: 0.8rem;
    text-decoration: none;
  }
  .linked-photos-jump:hover { text-decoration: underline; }

  /* Gallery */
  .gallery {
    max-width: 1400px;
    margin: 0 auto;
    padding: 16px 24px 48px;
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 10px;
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
  .folder-cover-label {
    display: block;
    padding: 10px 12px 2px;
    font-weight: 600;
    font-size: 0.95rem;
  }
  .folder-cover-dates { display:block; padding:0 12px; font-size:0.78rem; color:var(--muted); }
  .folder-dates { margin:2px 0 0; color:var(--muted); font-size:0.9rem; }
  .note-box.folder-description { white-space: pre-line; }
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
  .card {
    background: var(--card);
    border-radius: 10px;
    overflow: hidden;
    aspect-ratio: 1 / 1;
    cursor: pointer;
    position: relative;
    scroll-margin-top: 16px;
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
  .card.sold img { filter: grayscale(1) brightness(0.6); }
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
  .caption-popover {
    position: absolute; left: 8px; right: 8px; bottom: 34px; z-index: 3;
    background: rgba(0,0,0,0.88); color: #fff; padding: 8px 10px;
    border-radius: 8px; font-size: 0.85rem; line-height: 1.35;
    pointer-events: none; overflow-wrap: anywhere;
  }
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
  footer {
    text-align: center;
    padding: 0 24px 40px;
    color: var(--muted);
    font-size: 0.85rem;
  }
  footer a {
    color: var(--muted);
    text-decoration: none;
  }
  footer a:hover {
    color: var(--text);
    text-decoration: underline;
  }
  /* Same column as the photo grid below it (max-width and side padding
     match .gallery), so the map lines up with the tiles instead of
     running to the edges of the window. */
  .map-preview { max-width: 1400px; margin: 8px auto 0; padding: 0 24px; }
  .map-preview-heading {
    font-size: 1rem; margin: 0 0 10px;
    display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;
  }
  .map-preview-link { font-size: 0.85rem; color: var(--muted); text-decoration: none; }
  .map-preview-link:hover { color: var(--text); text-decoration: underline; }
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
    background: rgba(0,0,0,0.92);
    z-index: 100;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    touch-action: none;
  }
  .lightbox.open { display: flex; }
  .lightbox img, .lightbox video {
    max-width: 90vw;
    max-height: 82vh;
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
  .lightbox-copy.copied { color: #3ba55c; opacity: 1; }
  .lightbox .close,
  .lightbox .nav {
    position: absolute;
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
  .lightbox .close { top: 10px; right: 14px; font-size: 2.2rem; }
  .lightbox .prev { left: 6px; top: 50%; transform: translateY(-50%); font-size: 3rem; }
  .lightbox .next { right: 6px; top: 50%; transform: translateY(-50%); font-size: 3rem; }
  @media (max-width: 600px) {
    .lightbox .prev, .lightbox .next { font-size: 2.2rem; }
  }
</style>
</head>
<body>

<header>
  <?php if ($currentFolderPath !== null): ?>
    <p class="breadcrumb"><a href="<?= htmlspecialchars(gallery_root_href(), ENT_QUOTES, 'UTF-8') ?>">&larr; <?= htmlspecialchars($rootPageTitle) ?></a><?php foreach ($folderCrumbs as [$crumbHref, $crumbLabel]): ?> / <a href="<?= htmlspecialchars($crumbHref, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($crumbLabel) ?></a><?php endforeach; ?></p>
  <?php endif; ?>
  <?php if (ADMIN_PASSWORD !== ''): ?>
    <h1><a class="title-admin" rel="nofollow" href="?admin<?= $currentFolderPath !== null ? '&amp;folder=' . htmlspecialchars(rawurlencode($currentFolderPath), ENT_QUOTES, 'UTF-8') : '' ?>"><?= htmlspecialchars($pageTitle) ?></a></h1>
  <?php else: ?>
    <h1><?= htmlspecialchars($pageTitle) ?></h1>
  <?php endif; ?>
  <?php $headerMeta = $currentFolderPath !== null ? $currentFolderMeta : $rootMeta; ?>
  <?php if ($headerMeta['dates'] !== ''): ?>
    <p class="folder-dates"><?= htmlspecialchars($headerMeta['dates']) ?></p>
  <?php endif; ?>
  <p><?= htmlspecialchars(media_count_label($currentFolderPath !== null ? $currentFolderFiles : $files), ENT_QUOTES, 'UTF-8') ?><?php if ($lastUpdatedMtime > 0): ?> &middot; Last updated <?= htmlspecialchars(date('F j, Y', $lastUpdatedMtime)) ?><?php endif; ?></p>
</header>

<?php if ($headerMeta['description'] !== ''): ?>
  <div class="notes">
    <div class="note-box folder-description"><?= htmlspecialchars($headerMeta['description']) ?></div>
  </div>
<?php endif; ?>

<?php if ($currentFolderPath === null && empty($mdFiles) && !empty($galleryNotices)): ?>
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
    // The flat list has to follow the new card order exactly: every
    // card's lightbox index is drawn from it.
    $files = [];
    foreach ($groups as $groupFiles) {
        foreach ($groupFiles as $f) {
            $files[] = $f;
        }
    }
    $explicitGroupKeys = [];
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
$folderMdFiles = [];
$folderMdContents = [];
$folderGalleryNotices = [];
$folderNoteBoxesHtml = [];
if ($currentFolderPath !== null) {
    $folderRealPath = resolve_real_path($dir, $currentFolderPath);
    $folderMdScan = scan_gallery_md_files($folderRealPath);
    $folderMdFiles = $folderMdScan['files'];
    $folderMdContents = $folderMdScan['contents'];
    if ($folderMdScan['rejectedCount'] > 0) {
        $folderGalleryNotices[] = $folderMdScan['rejectedCount'] === 1
            ? 'A .md file was found in this folder but ignored because its first line isn\'t the required {gallery} marker.'
            : $folderMdScan['rejectedCount'] . ' .md files were found in this folder but ignored because none of them start with the required {gallery} marker.';
    }
    foreach ($folderMdFiles as $folderMdFile) {
        $folderNoteBoxesHtml[$folderMdFile] = markdown_to_html($folderMdContents[$folderMdFile], $groups, $files, $groupColors, $groupStatus, $groupLabels, $renderClaims, $photoViews);
    }
}
?>
<?php if ($currentFolderPath !== null && empty($folderMdFiles) && !empty($folderGalleryNotices)): ?>
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

<?php if ($mapShown && !empty($mapPoints)): ?>
  <section class="map-preview">
    <h2 class="map-preview-heading"><?= $currentFolderPath === null ? 'Trip map' : 'Map' ?> <a href="<?= htmlspecialchars($mapPageHref, ENT_QUOTES, 'UTF-8') ?>" class="map-preview-link">View full map &rarr;</a></h2>
    <?= render_leaflet_map($mapPoints, $mapLegend, 'trip-map-preview', '300px', true, $mapRadius) ?>
  </section>
<?php endif; ?>

<?php if (empty($files)): ?>
  <div class="empty">No photos found in this folder.</div>
<?php else: ?>
  <div class="gallery">
    <?= build_gallery_html($groups, $groupColors, $groupStatus, $photoViews, $groupLabels, $explicitGroupKeys, $folderGroupKeys, $videoDurations, $folderCovers, $itemsData, $folderMeta, $folderLinkPrefix) ?>
  </div>
<?php endif; ?>

<footer>
  <a href="?about">gallery</a><?php if ($viewCount > 0 && SHOW_VIEW_COUNTS_PUBLICLY): ?> · <?= number_format($viewCount) ?> visit<?= $viewCount === 1 ? '' : 's' ?><?php endif; ?><?php if (PRIVATE_VIEW_PASSWORD !== ''): ?> · <a href="?reveal">Private view</a><?php endif; ?>
</footer>

<div class="lightbox" id="lightbox">
  <button class="close" onclick="closeLightbox()">&times;</button>
  <button class="nav prev" onclick="navigate(-1)">&#10094;</button>
  <img id="lightbox-img" src="" alt="">
  <video id="lightbox-video" controls playsinline style="display:none;"></video>
  <div class="caption-row">
    <div class="caption" id="lightbox-caption"></div>
    <button class="lightbox-copy" id="lightbox-copy-btn" title="Copy link to this photo">&#128279;</button>
  </div>
  <button class="nav next" onclick="navigate(1)">&#10095;</button>
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
  const initialPhoto = <?= json_encode($requestedPhoto) ?>;
  const trackingEnabled = <?= TRACK_VIEWS ? 'true' : 'false' ?>;
  const showCountsPublicly = <?= SHOW_VIEW_COUNTS_PUBLICLY ? 'true' : 'false' ?>;
  let current = 0;
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
      el.textContent = '👁 ' + data.photoViews.toLocaleString();
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
    current = index;
    updateImage();
    lightbox.classList.add('open');
  }
  function closeLightbox() {
    lightbox.classList.remove('open');
    lightboxVideo.pause();
  }
  function navigate(delta) {
    current = (current + delta + files.length) % files.length;
    updateImage();
  }
  function updateImage() {
    const file = files[current];
    if (isVideoFile(file)) {
      lightboxImg.style.display = 'none';
      lightboxImg.src = '';
      lightboxVideo.style.display = '';
      lightboxVideo.src = '?video=' + encodeURIComponent(file) + (fileVersions[current] ? '&v=' + fileVersions[current] : '');
      lightboxVideo.load();
    } else {
      lightboxVideo.pause();
      lightboxVideo.style.display = 'none';
      lightboxVideo.src = '';
      lightboxImg.style.display = '';
      lightboxImg.src = '?img=' + encodeURIComponent(file) + '&size=full' + (fileVersions[current] ? '&v=' + fileVersions[current] : '');
    }
    const captionText = captions[current];
    caption.textContent = (captionText ? captionText + ' — ' : '') + (current + 1) + ' / ' + files.length;
    trackView(file);
  }
  document.addEventListener('keydown', (e) => {
    if (!lightbox.classList.contains('open')) return;
    if (e.key === 'Escape') { closeLightbox(); return; }
    // A focused <video>'s native controls already use arrow keys (seek)
    // and spacebar (play/pause) — don't also fire our own nav on top of
    // that, or an arrow press would both seek the clip and jump to a
    // different lightbox item at the same time.
    if (document.activeElement === lightboxVideo) return;
    if (e.key === 'ArrowLeft') navigate(-1);
    if (e.key === 'ArrowRight') navigate(1);
  });
  lightbox.addEventListener('click', (e) => {
    if (e.target === lightbox) closeLightbox();
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
    if (absX > absY && absX > threshold) {
      navigate(deltaX < 0 ? 1 : -1);
    } else if (absY > absX && deltaY > threshold) {
      closeLightbox();
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
    const original = el.textContent;
    el.textContent = '✅';
    el.classList.add('copied');
    setTimeout(() => {
      el.textContent = original;
      el.classList.remove('copied');
    }, 1200);
  }
  document.addEventListener('click', (e) => {
    const link = e.target.closest('.permalink-copy');
    if (!link) return;
    // A .md item's or group box's permalink is a same-page #anchor; a
    // folder cover card's is a real "?folder=..." link instead (no
    // hash) — copy whichever one this button actually has.
    const cleanUrl = link.hash
      ? window.location.origin + window.location.pathname + link.hash
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
    if (idx !== -1) openLightbox(idx);
  }
</script>

</body>
</html>
