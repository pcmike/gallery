<?php
/**
 * DROP-IN PHOTO GALLERY — v2.0.0
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
 *     MAP_CLUSTER_RADIUS_MILES constants are just below. TRACK_VIEWS,
 *     ENABLE_THUMBNAIL_CACHE, AUTO_GROUP_BY_FOLDER, ENABLE_VIDEO,
 *     ENABLE_PHOTO_LOCATION, and ENABLE_MAP are off by default — this
 *     script writes nothing to disk, makes no outbound/third-party
 *     requests, and doesn't look at a single subfolder, video file, or
 *     photo's location unless you turn one on.
 *     ADMIN_PASSWORD and PRIVATE_VIEW_PASSWORD are empty by default,
 *     which makes ?admin and ?reveal completely inert rather than merely
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

// Version shown on the "?about" page and stamped in the page source.
define('GALLERY_VERSION', '2.0.0');

// Turn photo/group/page view tracking on or off. Off by default — this
// script writes nothing to the folder's disk unless you turn something
// on. Turning this on writes .gallery-stats.json or .gallery-stats.sqlite
// into the folder, and is what enables the live view-count badges. See
// the README for details.
define('TRACK_VIEWS', false);

// Whether view counts are shown publicly once TRACK_VIEWS is on. True by
// default — preserves exactly today's behavior. Set to false to keep
// tracking counts for yourself while hiding the public badges entirely —
// useful if you'd rather not have a low view count sitting next to a
// "for sale" listing making it look undesirable. Has no effect unless
// TRACK_VIEWS is also on.
define('SHOW_VIEW_COUNTS_PUBLICLY', true);

// Turn thumbnail generation/caching on or off. Off by default — same
// reasoning as TRACK_VIEWS, this writes into a .gallery-cache folder.
// Strongly recommended for any real deployment: without it, every photo
// is served at full original resolution as its own "thumbnail" (real
// bandwidth cost), and HEIC/HEIF (iPhone) photos are excluded from the
// gallery entirely, even with Imagick installed — HEIC can't be shown to
// a browser without conversion, and conversion has nowhere to go without
// this cache. See the README for details.
define('ENABLE_THUMBNAIL_CACHE', false);

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
define('AUTO_GROUP_BY_FILENAME', false);

// How photos are ordered before any .md-driven repositioning: 'filename'
// (natural sort, the default) or 'mtime' (file modification time,
// oldest first). 'filename' is the more reliable default for a script
// meant to be copied/uploaded/backed up anywhere — many deployment
// methods (FTP, zip/extract, a fresh git clone, cloud sync) reset a
// file's modification time to "when it was placed here," not when the
// photo was actually taken, which can make 'mtime' order effectively
// arbitrary. Only switch to 'mtime' if you've verified your own
// deployment method preserves the original timestamps.
define('PHOTO_SORT_ORDER', 'filename');

// Scan subfolders recursively (to any depth) and group photos by which
// one they're in. Off by default — a flat folder behaves exactly as it
// always has. Turn this on for a trip folder like /trip/Paris,
// /trip/StTropez, etc. See the README for FOLDER_DISPLAY_MODE and
// EXTRA_FOLDERS, which only matter once this is on.
define('AUTO_GROUP_BY_FOLDER', false);

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
define('FOLDER_DISPLAY_MODE', 'overview');

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
define('EXTRA_FOLDERS', []);

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
define('ENABLE_VIDEO', false);

// Password for the admin editor, reached via ?admin — captions, per-item
// location/web link, display names, and raw .md editing. Empty by
// default, and that's not merely "unauthenticated": with no password
// set, ?admin is completely inert — the whole admin code path doesn't
// run at all, the same way ?about/?download are modes of this one file
// rather than separate scripts. Set this to a long random token, not a
// memorable phrase — this is the one credential in this script that, if
// guessed, grants write access, so it deserves the strongest value you'd
// put in any constant here. See the README for the HTTPS requirement and
// the failed-login throttle this password is protected by.
define('ADMIN_PASSWORD', '');

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
// it sounds: EXIF GPS auto-fill only works for JPEG (via exif_read_data)
// — HEIC, PNG, GIF, WEBP, and BMP need the coordinates typed in by hand.
define('ENABLE_PHOTO_LOCATION', false);

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
define('PRIVATE_VIEW_PASSWORD', '');

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
define('NGINX_ACCEL_REDIRECT_PREFIX', '');

// Internal — not meant to be edited. The name of the cookie that
// remembers a browser unlocked private view (see PRIVATE_VIEW_PASSWORD
// and is_revealed() below). A plain define() alongside the others here,
// rather than inline where it's first used further down, specifically
// because define() executes in file order (unlike a function
// declaration) — it has to run before anything below ever calls
// is_revealed(), which happens well before this script reaches that
// point in its own source otherwise.
define('REVEAL_COOKIE_NAME', 'gallery_reveal');

// Plots every visible, located photo on one overview map — Leaflet plus
// OpenStreetMap tiles, the only realistic option with no API key. Off by
// default: this is the *first* feature in this script to break the
// "one file, nothing else happens" promise, since it loads an external
// script and image tiles from a third party at page-load time. Has no
// effect at all unless ENABLE_PHOTO_LOCATION is also on and at least one
// photo actually has a visible location — see ENABLE_PHOTO_LOCATION's
// own doc comment for that gating. Reached at its own shareable ?map
// URL, with a smaller preview embedded on the gallery root.
define('ENABLE_MAP', false);

// How close together (in miles) two photos' locations have to be before
// the map merges them into a single cluster pin instead of showing each
// individually. 50 is a reasonable middle ground out of the box — a
// dense city trip and a cross-country road trip want very different
// values, so this is deliberately tunable per deployment rather than a
// fixed constant buried in code.
define('MAP_CLUSTER_RADIUS_MILES', 50);

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

if (AUTO_GROUP_BY_FOLDER) {
    $folderTree = merge_extra_folders(
        scan_folder_tree($dir, $mediaExtensions, $self),
        $mediaExtensions,
        $self
    );
    $files = flatten_folder_tree($folderTree);
} else {
    $folderTree = null;
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
// see the ".gallery-items.json" section below for the storage shape.
// Read unconditionally (not gated behind ADMIN_PASSWORD): a gallery that
// never touches the admin tool just gets an empty result per folder,
// cheaply, the same way an untouched .gallery-stats.json costs nothing.
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
    // imagefilledpolygon()'s $num_points argument was removed in PHP 8 (it's
    // now inferred from $points); passing it explicitly still works on 7.x.
    if (PHP_VERSION_ID >= 80000) {
        imagefilledpolygon($img, $points, $triangleColor);
    } else {
        imagefilledpolygon($img, $points, 3, $triangleColor);
    }
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
    // Same PHP 7/8 imagefilledpolygon() signature split as the video tile.
    if (PHP_VERSION_ID >= 80000) {
        imagefilledpolygon($img, $points, $pin);
    } else {
        imagefilledpolygon($img, $points, 3, $pin);
    }
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
        header('Cache-Control: public, max-age=31536000, immutable');
        serve_original_file($srcPath, $filename, guess_mime($ext));
        return;
    }

    $cachePath = image_cache_path($cacheDir, $filename, $variant, $srcPath);
    if ($cachePath && file_exists($cachePath) && filemtime($cachePath) >= @filemtime($srcPath)) {
        header('Content-Type: image/jpeg');
        header('Cache-Control: public, max-age=31536000, immutable');
        readfile($cachePath);
        return;
    }

    $ok = $cachePath ? generate_resized_jpeg($srcPath, $cachePath, $maxDim) : false;
    if ($ok) {
        header('Content-Type: image/jpeg');
        header('Cache-Control: public, max-age=31536000, immutable');
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
    serve_original_file($srcPath, $filename, guess_mime($ext));
}

function img_url($file, $size) {
    return '?img=' . rawurlencode($file) . '&size=' . $size;
}

/** A location-pin badge's link target — OpenStreetMap needs no API key
 *  and no dependency this project doesn't already avoid adding, unlike
 *  a full in-app map (a later, dedicated phase). */
function osm_location_url($lat, $lng) {
    return 'https://www.openstreetmap.org/?mlat=' . rawurlencode($lat) . '&mlon=' . rawurlencode($lng)
         . '#map=16/' . rawurlencode($lat) . '/' . rawurlencode($lng);
}

if (isset($_GET['img'])) {
    serve_image($dir, $_GET['img'], $_GET['size'] ?? 'thumb', $files, $itemsData);
    exit;
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
        header('Cache-Control: public, max-age=31536000, immutable');
        serve_original_file($srcPath, $filename, guess_mime($ext));
        return;
    }

    header('Content-Type: ' . guess_mime($ext));
    header('Cache-Control: public, max-age=31536000, immutable');
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

if (isset($_GET['video'])) {
    serve_video($dir, $_GET['video'], $files, $itemsData);
    exit;
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
                $pageTitle = trim($m[1]);
                unset($lines[$li]);
                $content = implode("\n", $lines);
            }
            break; // only ever check the very first non-empty line
        }
    }
    $mdContents[$mdFile] = $content;
}

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
// A folder-group with an admin-set position (see ?admin) sorts ahead of
// every folder without one, ascending — it replaces only the *natural*
// ranking, so a prose mention in the root's own .md still positions a
// folder first, same precedence as for any other group.
$folderSortRank = [];
foreach ($folderGroupKeys as $folderKey => $_true) {
    $sortOrder = folder_display_meta($dir, $folderKey)['sortOrder'];
    if ($sortOrder !== null) {
        $folderSortRank[$folderKey] = $sortOrder - 1000000000;
    }
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
// doesn't match what's visible would itself be a leak. (?img=/?video=/
// ?photo= already ran their own check above, since they execute before
// $groups even exists — this filter runs too late to help them.) If
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
 * concerns (the admin tool's .gallery-items.json, and the login-attempt
 * throttle's .gallery-login-attempts.json) so the locking mechanics
 * aren't duplicated a third time. Deliberately generic over the data
 * shape, unlike stats_update_file below, since callers here have
 * genuinely different shapes rather than variations on the same one.
 * ----------------------------------------------------------------------- */

/** Reads a JSON file, merging it over $defaultData (missing file or
 *  corrupt content just yields $defaultData untouched). */
function json_file_read($path, $defaultData) {
    if (!file_exists($path)) return $defaultData;
    $decoded = json_decode((string) @file_get_contents($path), true);
    return is_array($decoded) ? array_merge($defaultData, $decoded) : $defaultData;
}

/** Read-modify-write a JSON file under an exclusive lock; $modifier
 *  receives the current data (merged over $defaultData) by reference. */
function json_file_update($path, $defaultData, $modifier) {
    $data = $defaultData;
    $fp = @fopen($path, 'c+');
    if ($fp) {
        if (flock($fp, LOCK_EX)) {
            $raw = stream_get_contents($fp);
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $data = array_merge($data, $decoded);
            }
            $modifier($data);
            $encoded = json_encode($data);
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
    return $rootDir . '/.gallery-login-attempts.json';
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
function admin_start_session() {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.gc_maxlifetime', 14400);
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

function admin_send_auth_challenge() {
    header('WWW-Authenticate: Basic realm="Gallery Admin"');
    http_response_code(401);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Authentication required.';
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
  <p>This page logs in with a password sent over HTTP Basic Auth, which is sent in a form that's trivially readable by anyone on the network path if the connection isn't encrypted. To protect that password, this page refuses to run at all over a plain <code>http://</code> connection.</p>
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
    return hash('sha256', PRIVATE_VIEW_PASSWORD);
}

/** Sets the reveal cookie — called both on a successful ?reveal login and
 *  on a successful ?admin login (a valid admin login counts as revealed
 *  too, so the admin doesn't need a second credential just to see their
 *  own hidden items while browsing the plain gallery). A year-long
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

/** True if this visitor has already unlocked private view — via the
 *  reveal cookie (set by ?reveal or a prior ?admin login). Always false
 *  when PRIVATE_VIEW_PASSWORD is unset: with no password to ever enter,
 *  nothing can produce a matching cookie, so a hidden item simply stays
 *  hidden from everyone but the admin tool itself — the same
 *  closed-by-default posture as every other gate in this script. */
function is_revealed() {
    if (PRIVATE_VIEW_PASSWORD === '') return false;
    $cookie = $_COOKIE[REVEAL_COOKIE_NAME] ?? '';
    return is_string($cookie) && $cookie !== '' && hash_equals(reveal_cookie_value(), $cookie);
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
 *  through to whichever top-level folder-group $file sits in, if any —
 *  only ever checked one level deep, matching this project's existing
 *  folder-group scope elsewhere (see AUTO_GROUP_BY_FOLDER). */
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
 *  .gallery-items.json, normalized so callers never re-check types. */
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
    $pass = $_SERVER['PHP_AUTH_PW'] ?? null;
    if (!is_string($pass) || !hash_equals(PRIVATE_VIEW_PASSWORD, $pass)) {
        login_throttle_record_failure($dir, 'reveal', $ip);
        header('WWW-Authenticate: Basic realm="Gallery Private View"');
        http_response_code(401);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Authentication required.';
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
function render_leaflet_map($points, $legend, $mapId, $heightCss, $loadLibrary) {
    if (empty($points)) return;
    $clusters = cluster_map_points($points, MAP_CLUSTER_RADIUS_MILES);
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
function render_map_page($points, $legend, $pageTitle, $baseUrl) {
    $version = htmlspecialchars(GALLERY_VERSION, ENT_QUOTES, 'UTF-8');
    $title = htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8');
    $ogUrl = htmlspecialchars($baseUrl . '/?map', ENT_QUOTES, 'UTF-8');
    $ogImage = htmlspecialchars($baseUrl . '/?mapicon', ENT_QUOTES, 'UTF-8');
    // A bare "#group-<slug>" resolves against the *current* URL — here
    // /?map — so it would scroll nowhere on this page. Point in-page
    // anchors at the gallery root instead; ?folder= links are already
    // root-relative query strings and need no help.
    foreach ($points as &$p) {
        if ($p['link'][0] === '#') $p['link'] = './' . $p['link'];
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
  <a href="./" class="back">&larr; Back to gallery</a>
  <h1><?= $title ?> — Map</h1>
  <?php if (empty($points)): ?>
    <p style="color:var(--muted);">No photos have a visible location yet.</p>
  <?php else: ?>
    <?= render_leaflet_map($points, $legend, 'trip-map-full', '70vh', true) ?>
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
 * in .gallery-items.json, colocated per folder exactly like
 * .gallery-stats.* and .gallery-cache: a photo's caption lives in the
 * same real folder the photo itself does, and a folder-group's own
 * display name/cover live inside *that* folder (not its parent) — the
 * same place a genuine folder rename already carries everything else
 * along for free, with nothing left to update in some other file.
 * ----------------------------------------------------------------------- */

function items_path($folderPath) {
    return $folderPath . '/.gallery-items.json';
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
    return ['items' => [], 'groups' => [], 'displayName' => null, 'description' => null, 'dates' => null, 'sortOrder' => null, 'cover' => null, 'locationEnabled' => false];
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

/** Auto-fills location from a JPEG's embedded EXIF GPS block, if any.
 *  JPEG only — real coverage is narrower than it sounds: HEIC, PNG, GIF,
 *  WEBP, and BMP need their coordinates entered by hand (see
 *  ENABLE_PHOTO_LOCATION's own doc comment). Returns null on anything
 *  missing or unparseable — manual entry is always the fallback, never a
 *  hard failure. */
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
 * Garbage-collects stale .gallery-items.json entries — a photo deleted
 * (or renamed by hand, not via a real folder rename) orphans its caption
 * entry within a folder that itself still exists. Same precedent as
 * thumbnail-cache cleanup: swept here, and reported through the existing
 * notices mechanism, the same way an unknown {photos:} reference already
 * is. A whole folder disappearing needs no separate handling — its
 * .gallery-items.json is deleted along with it in the same operation.
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
        items_update($folderPath, function (&$data) use ($validBareNames, &$orphanCount) {
            foreach (array_keys($data['items']) as $bare) {
                if (!isset($validBareNames[$bare])) {
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
function render_admin_folder_nav($files, $currentFolder) {
    $folderPaths = all_folder_paths($files);
    if (empty($folderPaths)) return '';
    $html = '<nav class="admin-folder-nav"><a href="?admin"' . ($currentFolder === null ? ' class="current"' : '') . '>Root</a>';
    foreach ($folderPaths as $key) {
        $active = ($currentFolder === $key) ? ' class="current"' : '';
        $html .= ' <a href="?admin&folder=' . rawurlencode($key) . '"' . $active . '>' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '</a>';
    }
    return $html . '</nav>';
}

/** A collapsed-by-default-on-mobile reference panel: the directive table
 *  plus buttons that insert syntax at the textarea's cursor — plain text
 *  insertion, no different from typing it, so it never touches the
 *  byte-for-byte save guarantee the textarea itself keeps. */
function render_admin_reference_panel() {
    $directives = [
        ['Group a box', '{group: Name}'],
        ['Claim photos', '{photos: a.jpg, b.jpg}'],
        ['Accent color', '{color: #ff0000}'],
        ['Sold', '{sold}'],
        ['Reserved', '{reserved}'],
    ];
    $html = '<details class="admin-reference" open><summary>Directive reference</summary><div class="admin-reference-body">';
    $html .= '<p>Click to insert at the cursor:</p><div class="admin-reference-buttons">';
    foreach ($directives as [$label, $snippet]) {
        $html .= '<button type="button" class="admin-insert-btn" data-snippet="' . htmlspecialchars($snippet, ENT_QUOTES, 'UTF-8') . '">'
                . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</button>';
    }
    $html .= '</div><p class="admin-reference-note">Full syntax, including multi-file groups and auto-linked mentions, is documented in the README.</p></div></details>';
    return $html;
}

/**
 * Processes exactly one admin POST action. Every action is CSRF-checked
 * first; a save can touch at most one concern (an item, a group label, a
 * folder setting, or one .md file) so a failure in one never silently
 * implies success in another — reported per-action, not assumed
 * all-or-nothing.
 */
/** Plain single-line-ish text from a form field: control characters
 *  dropped, whitespace runs collapsed, length capped. Output is always
 *  HTML-escaped where it's shown, so this is about tidiness and bounding
 *  what a .gallery-items.json can grow to, not about escaping. */
function admin_clean_text($value, $maxLen) {
    $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $value);
    $text = trim(preg_replace('/\s+/u', ' ', (string) $text));
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $maxLen, 'UTF-8');
    }
    return substr($text, 0, $maxLen);
}

function handle_admin_post($dir, $adminFolderPath, $adminFolderReal, $files, $groups, $explicitGroups) {
    if (!admin_csrf_valid($_POST['csrf_token'] ?? '')) {
        return ['type' => 'error', 'text' => 'Your session expired — please try again.'];
    }
    $action = $_POST['action'] ?? '';

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
        items_update($adminFolderReal, function (&$data) use ($bare, $caption, $web, $lat, $lng, $locLabel, $visibility, $restriction) {
            $entry = $data['items'][$bare] ?? [];
            if ($caption !== '') { $entry['caption'] = $caption; } else { unset($entry['caption']); }
            if ($web !== '') { $entry['web'] = $web; } else { unset($entry['web']); }
            if ($lat !== '' && $lng !== '') {
                $entry['location'] = ['lat' => (float) $lat, 'lng' => (float) $lng, 'label' => $locLabel];
            } else {
                unset($entry['location']);
            }
            $entry['locationVisibility'] = $visibility;
            if ($restriction === 'inherit') { unset($entry['restriction']); } else { $entry['restriction'] = $restriction; }
            $data['items'][$bare] = $entry;
        });
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
        if ($adminFolderPath === null) {
            return ['type' => 'error', 'text' => 'Not applicable at the root.'];
        }
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
        if (array_key_exists('sort_order', $_POST)) {
            $raw = trim((string) $_POST['sort_order']);
            if ($raw === '') {
                $updates['sortOrder'] = null;
            } elseif (preg_match('/^-?\d{1,5}$/', $raw)) {
                $updates['sortOrder'] = (int) $raw;
            } else {
                return ['type' => 'error', 'text' => 'Position must be a whole number (or blank for natural order).'];
            }
        }
        items_update($adminFolderReal, function (&$data) use ($updates) {
            foreach ($updates as $k => $v) {
                $data[$k] = $v;
            }
        });
        return ['type' => 'ok', 'text' => 'Folder settings saved.'];
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
        if ($adminFolderPath === null) {
            return ['type' => 'error', 'text' => 'Not applicable at the root.'];
        }
        $bare = basename((string) ($_POST['file'] ?? ''));
        if (!in_array($adminFolderPath . '/' . $bare, $files, true)) {
            return ['type' => 'error', 'text' => 'Unknown file.'];
        }
        items_update($adminFolderReal, function (&$data) use ($bare) {
            $data['cover'] = $bare;
        });
        return ['type' => 'ok', 'text' => '"' . $bare . '" set as this folder\'s cover photo.'];
    }

    if ($action === 'toggle_location_enabled') {
        $enabled = !empty($_POST['enabled']);
        $targetReal = $adminFolderPath === null ? $dir : $adminFolderReal;
        items_update($targetReal, function (&$data) use ($enabled) {
            $data['locationEnabled'] = $enabled;
        });
        return ['type' => 'ok', 'text' => 'Location sharing ' . ($enabled ? 'enabled' : 'disabled') . ' for this folder.'];
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

/** Streams the current folder's own .gallery-items.json back out as a
 *  download — the only backup this irreplaceable, hand-entered data has
 *  at all. One caution worth repeating next to the download itself, not
 *  a design change: once a hidden flag exists in this file (a later
 *  phase), the export becomes a new way to reveal what's hidden and that
 *  it exists — mishandling the exported file is its own leak vector,
 *  independent of the actual hidden content. */
/** Every folder's .gallery-items.json in one download, keyed by relative
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
 * The admin tool's "Hidden items" summary — across the *whole* tree in
 * one place, since confirming "nothing is exposed that shouldn't be" by
 * clicking into every folder individually doesn't scale once depth is
 * arbitrary. Three tiers, not a flat "hidden" list — folders hidden
 * entirely, individual items hidden inside an otherwise-public folder,
 * and individual items deliberately shown despite sitting inside a
 * hidden folder — blurring them into one list risks reading as complete
 * when it isn't.
 */
function render_admin_hidden_summary($dir, $files, $folderGroupKeys) {
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

    $version = htmlspecialchars(GALLERY_VERSION, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Hidden items — Gallery Admin v<?= $version ?></title>
<style>
  :root { --bg:#0f0f10; --card:#1a1a1c; --text:#eaeaea; --muted:#8a8a8f; --accent:#5865f2; }
  * { box-sizing: border-box; }
  body { margin:0; background:var(--bg); color:var(--text); font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; line-height:1.5; }
  .wrap { max-width:720px; margin:0 auto; padding:24px 16px 64px; }
  h1 { font-size:1.4rem; }
  h2 { font-size:1rem; margin:24px 0 8px; }
  .muted { color:var(--muted); font-size:0.85rem; }
  .panel { background:var(--card); border-radius:10px; padding:16px; margin-bottom:14px; }
  ul { margin:0; padding-left:20px; }
  li { margin-bottom:4px; font-size:0.9rem; }
  a { color:#8ab4ff; }
</style>
</head>
<body>
<div class="wrap">
  <a href="?admin" class="muted">&larr; Back to admin</a>
  <h1>Hidden items</h1>
  <p class="muted">Across the whole tree, in one place.</p>

  <div class="panel">
    <h2 style="margin-top:0;">Folders hidden entirely (<?= count($hiddenFolders) ?>)</h2>
    <?php if (empty($hiddenFolders)): ?>
      <p class="muted">None.</p>
    <?php else: ?>
      <ul><?php foreach ($hiddenFolders as $f): ?><li><a href="?admin&folder=<?= rawurlencode($f) ?>"><?= htmlspecialchars($f, ENT_QUOTES, 'UTF-8') ?></a></li><?php endforeach; ?></ul>
    <?php endif; ?>
  </div>

  <div class="panel">
    <h2 style="margin-top:0;">Individual items hidden (<?= count($hiddenItems) ?>)</h2>
    <?php if (empty($hiddenItems)): ?>
      <p class="muted">None.</p>
    <?php else: ?>
      <ul><?php foreach ($hiddenItems as $f): ?><li><?= htmlspecialchars($f, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
  </div>

  <div class="panel">
    <h2 style="margin-top:0;">Items shown despite a hidden folder (<?= count($shownDespiteHiddenFolder) ?>)</h2>
    <?php if (empty($shownDespiteHiddenFolder)): ?>
      <p class="muted">None.</p>
    <?php else: ?>
      <ul><?php foreach ($shownDespiteHiddenFolder as $f): ?><li><?= htmlspecialchars($f, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
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
    $itemsHere = items_read($adminFolderReal);
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
  .wrap { max-width:960px; margin:0 auto; padding:24px 16px 64px; }
  h1 { font-size:1.4rem; margin:0 0 4px; }
  h2 { font-size:1.1rem; margin:28px 0 10px; }
  .muted { color:var(--muted); font-size:0.85rem; }
  .admin-folder-nav { margin:14px 0; display:flex; flex-wrap:wrap; gap:8px; }
  .admin-folder-nav a { color:var(--muted); text-decoration:none; padding:5px 10px; border-radius:6px; background:var(--card); font-size:0.85rem; }
  .admin-folder-nav a.current { color:var(--text); background:var(--accent); }
  .flash { padding:10px 14px; border-radius:8px; margin:14px 0; font-size:0.9rem; }
  .flash.ok { background:rgba(59,165,92,0.15); color:var(--ok); }
  .flash.error { background:rgba(237,66,69,0.15); color:var(--err); }
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
  .admin-reference { margin-top:10px; font-size:0.85rem; }
  .admin-reference summary { cursor:pointer; color:var(--muted); }
  .admin-reference-buttons { display:flex; flex-wrap:wrap; gap:6px; margin:8px 0; }
  .admin-insert-btn { background:#2a2a2e; color:var(--text); font-size:0.78rem; padding:4px 9px; }
  .admin-reference-note { color:var(--muted); font-size:0.78rem; }
  .grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(260px, 1fr)); gap:14px; }
  .item-card { background:var(--card); border-radius:10px; padding:12px; }
  .item-card img, .item-card .item-thumb-placeholder { width:100%; aspect-ratio:1/1; object-fit:cover; border-radius:6px; margin-bottom:8px; background:#101012; }
  .item-filename { font-size:0.78rem; color:var(--muted); word-break:break-all; margin-bottom:8px; }
  .loc-row { display:flex; gap:6px; }
  .loc-row .field { flex:1; }
  @media (max-width: 600px) {
    .wrap { padding:16px 10px 48px; }
    .grid { grid-template-columns:1fr; }
  }
</style>
</head>
<body>
<div class="wrap">
  <a href="./" class="muted">&larr; Back to gallery</a>
  <h1>Gallery Admin</h1>
  <p class="muted">Editing: <?= htmlspecialchars($folderLabel, ENT_QUOTES, 'UTF-8') ?></p>
  <?= render_admin_folder_nav($files, $adminFolderPath) ?>

  <?php if (admin_any_hidden($dir, $files, $folderGroupKeys)): ?>
    <div class="flash error">
      <strong>Hidden items exist.</strong> Hiding only stops a visitor from reaching an item <em>through this gallery</em> — the file itself still sits on disk as an ordinary static file, servable directly by the webserver if its bare URL is known or guessed, unless you've separately locked that down at the webserver level (see the README for the nginx/<code>X-Accel-Redirect</code> or Apache <code>.htaccess</code> setup). This warning reappears every time this screen loads — it isn't a one-time check.
      <a href="?admin&hidden=1">Review hidden items &rarr;</a>
    </div>
  <?php endif; ?>

  <?php if ($resultMessage): ?>
    <div class="flash <?= htmlspecialchars($resultMessage['type'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($resultMessage['text'], ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <?php if ($adminFolderPath !== null): ?>
  <div class="panel">
    <h2 style="margin-top:0;">This folder's own settings</h2>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
      <input type="hidden" name="action" value="save_folder_identity">
      <div class="field">
        <label>Display name (shown on its cover card instead of "<?= htmlspecialchars(basename($adminFolderPath), ENT_QUOTES, 'UTF-8') ?>")</label>
        <input type="text" name="display_name" value="<?= htmlspecialchars($itemsHere['displayName'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
      </div>
      <div class="field">
        <label>Dates (optional subtitle, typed by you — never read from the photos' EXIF, so it never reveals when you were away unless you say so)</label>
        <input type="text" name="dates" maxlength="80" placeholder="June 12&ndash;15, 2026" value="<?= htmlspecialchars($itemsHere['dates'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
      </div>
      <div class="field">
        <label>Description (a sentence or two, shown on its cover card and at the top of its page)</label>
        <textarea name="description" maxlength="500" style="min-height:70px; font-family:inherit;"><?= htmlspecialchars($itemsHere['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
      </div>
      <div class="field">
        <label>Position among its sibling folders (lowest first; blank = natural order, after any numbered ones)</label>
        <input type="number" name="sort_order" min="-99999" max="99999" step="1" value="<?= isset($itemsHere['sortOrder']) && is_int($itemsHere['sortOrder']) ? (int) $itemsHere['sortOrder'] : '' ?>">
      </div>
      <button type="submit">Save folder settings</button>
    </form>
    <form method="post" style="margin-top:12px;">
      <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
      <input type="hidden" name="action" value="toggle_location_enabled">
      <label><input type="checkbox" name="enabled" value="1" <?= !empty($itemsHere['locationEnabled']) ? 'checked' : '' ?> onchange="this.form.submit()"> Show photo locations publicly for this folder<?php if (!ENABLE_PHOTO_LOCATION): ?> <span class="muted">(ENABLE_PHOTO_LOCATION is off — nothing shows regardless)</span><?php endif; ?></label>
    </form>
    <form method="post" style="margin-top:8px;">
      <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
      <input type="hidden" name="action" value="toggle_folder_restriction">
      <input type="hidden" name="hidden" value="<?= !empty($itemsHere['restriction']) && $itemsHere['restriction'] === 'private' ? '0' : '1' ?>">
      <button type="submit" class="<?= (!empty($itemsHere['restriction']) && $itemsHere['restriction'] === 'private') ? 'secondary' : 'danger' ?>"><?= (!empty($itemsHere['restriction']) && $itemsHere['restriction'] === 'private') ? 'Make this folder public again' : 'Hide this entire folder from public view' ?></button>
    </form>
    <?php if (!empty($itemsHere['cover'])): ?>
      <p class="muted" style="margin-top:10px;">Cover photo: <?= htmlspecialchars($itemsHere['cover'], ENT_QUOTES, 'UTF-8') ?> — click "Set as cover" on any photo below to change it.</p>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <div class="panel">
    <label><input type="checkbox" disabled <?= !empty($itemsHere['locationEnabled']) ? 'checked' : '' ?>> Show photo locations publicly at the root</label>
    <form method="post" style="margin-top:6px;">
      <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
      <input type="hidden" name="action" value="toggle_location_enabled">
      <input type="hidden" name="enabled" value="<?= !empty($itemsHere['locationEnabled']) ? '0' : '1' ?>">
      <button type="submit" class="secondary"><?= !empty($itemsHere['locationEnabled']) ? 'Disable' : 'Enable' ?> for the root</button>
    </form>
  </div>
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

  <div class="panel">
    <h2 style="margin-top:0;">Notes (.md files in this folder)</h2>
    <?php if (empty($mdCandidates)): ?>
      <p class="muted">No .md files here yet.</p>
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
          <?= render_admin_reference_panel() ?>
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
    <div class="md-file">
      <strong>New file</strong>
      <form method="post" onsubmit="return admConfirmSaveAs(this);">
        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
        <input type="hidden" name="action" value="save_notes">
        <input type="hidden" name="md_file" value="">
        <textarea name="content" class="adm-textarea">{gallery}
</textarea>
        <?= render_admin_reference_panel() ?>
        <div class="field-row" style="margin-top:8px; align-items:flex-end;">
          <div class="field" style="max-width:260px;">
            <label>Filename</label>
            <input type="text" name="new_name" class="adm-newname" placeholder="notes.md" required>
          </div>
          <button type="submit">Create</button>
        </div>
      </form>
    </div>
  </div>

  <h2>Photos &amp; video (<?= count($adminFiles) ?>)</h2>
  <div class="grid">
    <?php foreach ($adminFiles as $file): $bare = basename($file);
      $entry = $itemsHere['items'][$bare] ?? [];
      $loc = $entry['location'] ?? null;
      if ($loc === null && !is_video_file($file)) {
          $auto = extract_jpeg_gps(resolve_real_path($dir, $file));
          if ($auto !== null) { $loc = $auto; }
      }
    ?>
      <div class="item-card">
        <?php if (is_video_file($file)): ?>
          <div class="item-thumb-placeholder" style="display:flex;align-items:center;justify-content:center;color:var(--muted);">&#9654; video<?php if (isset($videoDurations[$file])): ?> &middot; <?= htmlspecialchars(format_duration($videoDurations[$file]), ENT_QUOTES, 'UTF-8') ?><?php endif; ?></div>
        <?php else: ?>
          <img src="<?= htmlspecialchars(img_url($file, 'thumb'), ENT_QUOTES, 'UTF-8') ?>" alt="">
        <?php endif; ?>
        <div class="item-filename"><?= htmlspecialchars($file, ENT_QUOTES, 'UTF-8') ?></div>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
          <input type="hidden" name="action" value="save_item">
          <input type="hidden" name="file" value="<?= htmlspecialchars($bare, ENT_QUOTES, 'UTF-8') ?>">
          <div class="field">
            <label>Caption</label>
            <input type="text" name="caption" value="<?= htmlspecialchars($entry['caption'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
          </div>
          <div class="field">
            <label>Web link</label>
            <input type="url" name="web" value="<?= htmlspecialchars($entry['web'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="https://&hellip;">
          </div>
          <div class="loc-row">
            <div class="field">
              <label>Latitude</label>
              <input type="text" name="lat" value="<?= htmlspecialchars($loc['lat'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="field">
              <label>Longitude</label>
              <input type="text" name="lng" value="<?= htmlspecialchars($loc['lng'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>
          </div>
          <div class="field">
            <label>Location label (optional)</label>
            <input type="text" name="loc_label" value="<?= htmlspecialchars($loc['label'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
          </div>
          <div class="field">
            <label>Location visibility</label>
            <select name="loc_visibility">
              <?php $vis = $entry['locationVisibility'] ?? 'inherit'; ?>
              <option value="inherit" <?= $vis === 'inherit' ? 'selected' : '' ?>>Inherit folder setting</option>
              <option value="show" <?= $vis === 'show' ? 'selected' : '' ?>>Always show</option>
              <option value="hide" <?= $vis === 'hide' ? 'selected' : '' ?>>Always hide</option>
            </select>
          </div>
          <div class="field">
            <label>Visibility</label>
            <select name="restriction">
              <?php $rest = item_restriction($entry); ?>
              <option value="inherit" <?= $rest === 'inherit' ? 'selected' : '' ?>>Inherit folder setting</option>
              <option value="public" <?= $rest === 'public' ? 'selected' : '' ?>>Always show (even if this folder is hidden)</option>
              <option value="private" <?= $rest === 'private' ? 'selected' : '' ?>>Always hide</option>
            </select>
          </div>
          <button type="submit">Save</button>
        </form>
        <?php if ($adminFolderPath !== null): ?>
          <form method="post" style="margin-top:6px;">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="set_cover">
            <input type="hidden" name="file" value="<?= htmlspecialchars($bare, ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="secondary">Set as cover</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="panel" style="margin-top:20px;">
    <h2 style="margin-top:0;">Export</h2>
    <p class="muted">Downloads this folder's captions, locations, web links, display name, and chosen cover as JSON — the only backup this data has.</p>
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
  </div>
</div>
<script>
  document.querySelectorAll('.admin-insert-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const textarea = btn.closest('.md-file').querySelector('.adm-textarea');
      const snippet = btn.getAttribute('data-snippet');
      const start = textarea.selectionStart, end = textarea.selectionEnd;
      textarea.value = textarea.value.slice(0, start) + snippet + textarea.value.slice(end);
      textarea.focus();
      textarea.selectionStart = textarea.selectionEnd = start + snippet.length;
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
 * Entry point for ?admin — auth (HTTPS, Basic Auth, throttle), then
 * dispatches to a POST action handler or the page itself. Exits the
 * script either way; nothing below this ever runs for an admin request.
 */
function run_admin_route($dir, $files, $groups, $folderGroupKeys, $explicitGroups, $explicitGroupKeys, $videoDurations, $galleryNotices) {
    if (ADMIN_PASSWORD === '') {
        http_response_code(404);
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
    if (!admin_credentials_valid()) {
        login_throttle_record_failure($dir, 'admin', $ip);
        admin_send_auth_challenge();
        return;
    }
    login_throttle_record_success($dir, 'admin', $ip);
    // A valid admin login counts as revealed too — one-directional, and
    // this is what makes it true on the *public* gallery view, not just
    // inside ?admin itself (a plain cookie, since the public page is
    // never behind its own session/Basic-Auth check).
    set_reveal_cookie();
    admin_start_session();

    if (isset($_GET['hidden'])) {
        render_admin_hidden_summary($dir, $files, $folderGroupKeys);
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
        $resultMessage = handle_admin_post($dir, $adminFolderPath, $adminFolderReal, $files, $groups, $explicitGroups);
        if ($resultMessage['type'] === 'export') {
            admin_export_items($adminFolderPath, $adminFolderReal);
            return;
        }
        if ($resultMessage['type'] === 'export_tree') {
            admin_export_tree($dir, $files);
            return;
        }
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
 * small .gallery-stats.json/.sqlite, scoped to just its own photos. A
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
    $conn = ['mode' => 'file', 'pdo' => null, 'file' => $folderPath . '/.gallery-stats.json'];
    if (class_exists('PDO') && in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        try {
            $pdo = new PDO('sqlite:' . $folderPath . '/.gallery-stats.sqlite');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('CREATE TABLE IF NOT EXISTS stats (key TEXT PRIMARY KEY, value INTEGER NOT NULL DEFAULT 0)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS photo_views (filename TEXT PRIMARY KEY, views INTEGER NOT NULL DEFAULT 0)');
            $conn['pdo'] = $pdo;
            $conn['mode'] = 'sqlite';
        } catch (Exception $e) {
            $conn['mode'] = 'file';
        }
    }
    $GLOBALS['__stats_pool'][$folderPath] = $conn;
    return $conn;
}

/** Reads-modifies-writes the flat-file JSON backend under an exclusive lock. */
function stats_update_file($statsFile, $modifier) {
    $data = ['pageviews' => 0, 'photos' => []];
    $fp = @fopen($statsFile, 'c+');
    if ($fp) {
        if (flock($fp, LOCK_EX)) {
            $raw = stream_get_contents($fp);
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $data = array_merge($data, $decoded);
            }
            if (!isset($data['photos']) || !is_array($data['photos'])) {
                $data['photos'] = [];
            }
            $modifier($data);
            $encoded = json_encode($data);
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

/** Increments one photo's view count (in its own folder's store) and returns its new total. */
function stats_bump_photo($folderPath, $relativePath) {
    $conn = stats_connection($folderPath);
    if ($conn['mode'] === 'sqlite') {
        $pdo = $conn['pdo'];
        $stmt = $pdo->prepare(
            "INSERT INTO photo_views(filename, views) VALUES(:f, 1)
             ON CONFLICT(filename) DO UPDATE SET views = views + 1"
        );
        $stmt->execute([':f' => $relativePath]);
        $sel = $pdo->prepare('SELECT views FROM photo_views WHERE filename = :f');
        $sel->execute([':f' => $relativePath]);
        return (int) $sel->fetchColumn();
    }
    $stats = stats_update_file($conn['file'], function (&$data) use ($relativePath) {
        $data['photos'][$relativePath] = ($data['photos'][$relativePath] ?? 0) + 1;
    });
    return (int) ($stats['photos'][$relativePath] ?? 0);
}

/** Every tracked photo's view count from one folder's own store. */
function stats_get_photo_views($folderPath) {
    $conn = stats_connection($folderPath);
    if ($conn['mode'] === 'sqlite') {
        $result = [];
        foreach ($conn['pdo']->query('SELECT filename, views FROM photo_views') as $row) {
            $result[$row['filename']] = (int) $row['views'];
        }
        return $result;
    }
    if (!file_exists($conn['file'])) return [];
    $data = json_decode((string) @file_get_contents($conn['file']), true);
    return (is_array($data) && isset($data['photos']) && is_array($data['photos'])) ? $data['photos'] : [];
}

/** Every tracked photo's view count across the whole tree, merged from
 *  each contributing folder's own store — safe to merge with a plain
 *  array union since every key is already a full relative path, unique
 *  regardless of which folder it came from. */
function stats_get_all_photo_views($dir, $files) {
    $byFolder = [];
    foreach ($files as $file) {
        $byFolder[containing_folder_real_path($dir, $file)] = true;
    }
    $result = [];
    foreach (array_keys($byFolder) as $folderPath) {
        $result += stats_get_photo_views($folderPath);
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

// ?track=<filename> is a tiny background ping fired by the lightbox each
// time a photo is displayed. Only counts filenames that are actually in
// this folder's photo list, skips known bots, and debounces a repeat
// ping for the same photo from the same browser (see
// view_debounce_recently_viewed() above). Responds with the fresh photo +
// group totals as JSON so the page can update the visible numbers
// immediately, with no reload — and never renders the gallery itself.
if (isset($_GET['track'])) {
    $response = ['ok' => false];
    if (TRACK_VIEWS && !is_bot_request()) {
        $requested = $_GET['track'];
        if (in_array($requested, $files, true)) {
            // A group's members always live in the same folder (folder
            // membership and directive scoping both guarantee this — see
            // GROUPING), so this one folder's store covers the whole
            // group's totals below, not just the requested photo.
            $requestedFolder = containing_folder_real_path($dir, $requested);
            $currentPhotoViews = stats_get_photo_views($requestedFolder);
            if (view_debounce_recently_viewed($requested)) {
                // Debounced: still report the current total, unincremented,
                // so the live-updating badge reflects reality instead of
                // looking stuck or broken.
                $newCount = (int) ($currentPhotoViews[$requested] ?? 0);
            } else {
                $newCount = stats_bump_photo($requestedFolder, $requested);
            }
            $groupKey = $fileToGroupKey[$requested] ?? group_key($requested);
            $groupFiles = $groups[$groupKey] ?? [$requested];
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
    echo json_encode($response);
    exit;
}

// Normal page load: bump the total page-view count (unless this is a
// known bot — see "VIEW STATS" above) and load current per-photo counts
// to display in the gallery below.
$viewCount = 0;
$photoViews = [];
if (TRACK_VIEWS) {
    if (!is_bot_request()) {
        $viewCount = stats_bump_pageview($dir);
    }
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
    return $text;
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
            . $count . ' photo' . ($count === 1 ? '' : 's') . ($folderHref !== null ? ' &rarr;' : ' &darr;') . '</a>';
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

/**
 * A collapsed-by-default banner surfacing $galleryNotices directly on
 * the page, not just in the HTML-comment fallback in the page source —
 * "view page source" isn't something most people would think to try.
 * Addressed explicitly to the gallery owner, so a visitor who clicks it
 * out of curiosity can see right away it isn't meant for them.
 */
function render_gallery_notices_banner($notices) {
    if (empty($notices)) return '';
    $count = count($notices);
    $html = '<details class="gallery-notices">';
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
            $contents[$candidate] = strip_gallery_marker($raw);
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
    return $html;
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
        $itemBadges .= '<span class="item-badge caption-badge" title="' . htmlspecialchars($itemEntry['caption'], ENT_QUOTES, 'UTF-8') . '">&#128172;</span>';
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
 * A folder-group's cover card in 'overview' mode: one highlight photo
 * (the first in natural order — $recursiveFiles is already sorted this
 * way by the scan), the label, and the recursive photo count across the
 * whole subtree ($recursiveFiles already *is* that full recursive list,
 * so no separate counting pass is needed). The copy-link icon is a
 * sibling of the navigation link, not nested inside it — two <a> tags,
 * never one inside the other. $manualCover (a bare filename from the
 * folder's own .gallery-items.json — see ?admin's "Set as cover")
 * overrides the automatic choice when it's still a real member of this
 * folder's own recursive file list.
 */
function render_folder_cover_card($folderPath, $label, $recursiveFiles, $manualCover = null, $idKey = null, $meta = null) {
    $folderUrl = htmlspecialchars('?folder=' . rawurlencode($folderPath), ENT_QUOTES, 'UTF-8');
    $coverFile = null;
    if ($manualCover !== null) {
        foreach ($recursiveFiles as $f) {
            if (basename($f) === $manualCover) {
                $coverFile = $f;
                break;
            }
        }
    }
    // The automatic fallback prefers an actual photo over a video, even
    // if a video sorts first — a video's generic placeholder graphic
    // makes a flat, uninteresting highlight. Only fall back to a video
    // if the folder has no photos at all.
    if ($coverFile === null) {
        foreach ($recursiveFiles as $f) {
            if (!is_video_file($f)) {
                $coverFile = $f;
                break;
            }
        }
    }
    if ($coverFile === null) {
        $coverFile = $recursiveFiles[0] ?? null;
    }
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
    if (!empty($meta['description'])) {
        $html .= '<span class="folder-cover-desc">' . htmlspecialchars($meta['description'], ENT_QUOTES, 'UTF-8') . '</span>';
    }
    $html .= '<span class="folder-cover-count">' . number_format($count) . ' photo' . ($count === 1 ? '' : 's') . '</span>';
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

/** Builds the current request's scheme://host/path, for absolute OG image URLs. */
function current_base_url() {
    $scheme = is_https_request() ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
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
    <a class="back-link" href="./">&larr; Back to gallery</a>
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
// .gallery-items.json; a root-level filename-based group's lives in the
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
$mapLegend = map_legend_from_points($mapPoints);

// ?map — the dedicated, shareable map page.
if (ENABLE_MAP && isset($_GET['map'])) {
    render_map_page($mapPoints, $mapLegend, $rootPageTitle, $baseUrl);
    exit;
}

$ogTitle = $pageTitle;
$ogUrl = $baseUrl . '/';
$ogDescription = !empty($mdFiles)
    ? markdown_to_plaintext($mdContents[$mdFiles[0]])
    : (count($files) . ' photo' . (count($files) === 1 ? '' : 's'));
$ogImageUrl = !empty($files) ? $baseUrl . '/' . img_url($files[0], 'share') : '';
if ($currentFolderPath !== null) {
    // $pageTitle is already folder-prefixed (see the ?folder= validation
    // block above), so $ogTitle below already picked that up — only the
    // url/description/image need their own folder-scoped values here.
    $ogUrl = $baseUrl . '/?folder=' . rawurlencode($currentFolderPath);
    $folderMemberFiles = $currentFolderFiles;
    $ogDescription = count($folderMemberFiles) . ' photo' . (count($folderMemberFiles) === 1 ? '' : 's');
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
        $ogImageUrl = $baseUrl . '/' . img_url($folderMemberFiles[0], 'share');
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
  .folder-cover-desc { display:block; padding:2px 12px 0; font-size:0.82rem; color:var(--muted); overflow:hidden; display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; }
  .folder-dates { margin:2px 0 0; color:var(--muted); font-size:0.9rem; }
  .folder-description { margin:6px 0 0; color:var(--muted); max-width:62ch; }
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
  .item-badge.caption-badge { pointer-events: none; }
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
  .map-preview { margin-top: 32px; }
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
    <p class="breadcrumb"><a href="./">&larr; <?= htmlspecialchars($rootPageTitle) ?></a><?php foreach ($folderCrumbs as [$crumbHref, $crumbLabel]): ?> / <a href="<?= htmlspecialchars($crumbHref, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($crumbLabel) ?></a><?php endforeach; ?></p>
  <?php endif; ?>
  <h1><?= htmlspecialchars($pageTitle) ?></h1>
  <?php if ($currentFolderPath !== null && $currentFolderMeta['dates'] !== ''): ?>
    <p class="folder-dates"><?= htmlspecialchars($currentFolderMeta['dates']) ?></p>
  <?php endif; ?>
  <?php if ($currentFolderPath !== null && $currentFolderMeta['description'] !== ''): ?>
    <p class="folder-description"><?= htmlspecialchars($currentFolderMeta['description']) ?></p>
  <?php endif; ?>
  <?php $headerPhotoCount = $currentFolderPath !== null ? $currentFolderFileCount : count($files); ?>
  <p><?= $headerPhotoCount ?> photo<?= $headerPhotoCount === 1 ? '' : 's' ?><?php if ($lastUpdatedMtime > 0): ?> &middot; Last updated <?= htmlspecialchars(date('F j, Y', $lastUpdatedMtime)) ?><?php endif; ?></p>
</header>

<?php if ($currentFolderPath === null && empty($mdFiles) && !empty($galleryNotices)): ?>
  <div class="notes">
    <?= render_gallery_notices_banner($galleryNotices) ?>
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
    <?= render_gallery_notices_banner($galleryNotices) ?>
    <?php foreach ($mdFiles as $mdFile): ?>
      <div class="note-box">
        <span class="note-filename"><?= htmlspecialchars($mdFile) ?></span>
        <?= $noteBoxesHtml[$mdFile] ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php
// The actual ?folder= override: scope $groups/$files down to just this
// one folder's files for the gallery grid below — a flat run of
// individual cards, not further cover-card drilling.
// $explicitGroupKeys/$folderGroupKeys are cleared since there's no
// further boxing or drilling happening on this page. ($pageTitle was
// already folder-prefixed earlier, in the ?folder= validation block.)
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
    <?php foreach ($folderMdFiles as $folderMdFile): ?>
      <div class="note-box">
        <span class="note-filename"><?= htmlspecialchars($folderMdFile) ?></span>
        <?= $folderNoteBoxesHtml[$folderMdFile] ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if (empty($files)): ?>
  <div class="empty">No photos found in this folder.</div>
<?php else: ?>
  <div class="gallery">
    <?= build_gallery_html($groups, $groupColors, $groupStatus, $photoViews, $groupLabels, $explicitGroupKeys, $folderGroupKeys, $videoDurations, $folderCovers, $itemsData, $folderMeta, $folderLinkPrefix) ?>
  </div>
<?php endif; ?>

<?php if (ENABLE_MAP && $currentFolderPath === null && !empty($mapPoints)): ?>
  <section class="map-preview">
    <h2 class="map-preview-heading">Trip map <a href="?map" class="map-preview-link">View full map &rarr;</a></h2>
    <?= render_leaflet_map($mapPoints, $mapLegend, 'trip-map-preview', '320px', true) ?>
  </section>
<?php endif; ?>

<footer>
  <a href="?about">gallery</a><?php if ($viewCount > 0): ?> · <?= number_format($viewCount) ?> views<?php endif; ?><?php if (PRIVATE_VIEW_PASSWORD !== ''): ?> · <a href="?reveal">Private view</a><?php endif; ?>
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
      lightboxVideo.src = '?video=' + encodeURIComponent(file);
      lightboxVideo.load();
    } else {
      lightboxVideo.pause();
      lightboxVideo.style.display = 'none';
      lightboxVideo.src = '';
      lightboxImg.style.display = '';
      lightboxImg.src = '?img=' + encodeURIComponent(file) + '&size=full';
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
