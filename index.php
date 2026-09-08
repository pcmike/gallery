<?php
/**
 * DROP-IN PHOTO GALLERY — v1.1.0
 * Single PHP file, no dependencies, no build step. Drop into any folder
 * of photos and it renders a gallery for that folder.
 *
 * Full documentation (grouping, Markdown notes, {color}/{sold}/{reserved}/
 * {group}/{photos} directives, view stats, deep linking, image caching,
 * etc.) lives in the repo's README: github.com/pcmike/gallery. The
 * "?about" page this script serves itself (open any deployed gallery and
 * click "about" in the footer, or visit "?about" directly) is a short
 * in-app pitch that links back there, plus the download link for this
 * file.
 *
 * Quick reference for anyone editing this file directly:
 *   - GALLERY_VERSION / TRACK_VIEWS / ENABLE_THUMBNAIL_CACHE /
 *     AUTO_GROUP_BY_FILENAME / PHOTO_SORT_ORDER constants are just below.
 *     TRACK_VIEWS and ENABLE_THUMBNAIL_CACHE are off by default — this
 *     script writes nothing to disk unless you turn one on.
 *   - A .md file is only read if its first line is exactly {gallery}.
 *   - Photo grouping: auto (filename minus a trailing "_01"/"-02"
 *     suffix, if AUTO_GROUP_BY_FILENAME is on) or explicit via
 *     {group: Name} + {photos: a.jpg, b.jpg} in a .md file.
 *   - .md directives: {color: value}, {sold}, {reserved} (or {held}),
 *     {group: Name}, {photos: a.jpg, b.jpg, ...}.
 *   - See each function's own docblock below for implementation details.
 */

// Version shown on the "?about" page and stamped in the page source.
define('GALLERY_VERSION', '1.1.0');

// Turn photo/group/page view tracking on or off. Off by default — this
// script writes nothing to the folder's disk unless you turn something
// on. Turning this on writes .gallery-stats.json or .gallery-stats.sqlite
// into the folder, and is what enables the live view-count badges. See
// the README for details.
define('TRACK_VIEWS', false);

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

$dir = __DIR__;
$self = basename(__FILE__);
$cacheDir = $dir . '/.gallery-cache';

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

$files = array_filter(scandir($dir), function ($file) use ($dir, $imageExtensions, $self) {
    if ($file === $self || $file === '.' || $file === '..') return false;
    if (!is_file("$dir/$file")) return false;
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    return in_array($ext, $imageExtensions);
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

// Snapshot of each file's position in this base sort, before any .md-
// driven repositioning happens — used as the "natural order" anchor for
// a group/cluster that has no prose to derive an early position from
// (see GROUPING and compute_zone_order() below).
$naturalRank = [];
foreach ($files as $i => $f) {
    $naturalRank[$f] = $i;
}

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

// ?photo=<filename> deep-links straight to that photo in the lightbox on
// page load. Only ever a filename that's actually in this folder.
$requestedPhoto = null;
if (isset($_GET['photo'])) {
    $candidate = basename($_GET['photo']);
    if (in_array($candidate, $files, true)) {
        $requestedPhoto = $candidate;
    }
}

// Visiting this page with ?download serves this very file as a plain
// download, so anyone browsing a gallery can grab a copy of the script
// for their own folder. Must run before anything else, and exit — no
// gallery HTML should be sent alongside a file download.
if (isset($_GET['download'])) {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="index.php"');
    header('Content-Length: ' . filesize(__FILE__));
    readfile(__FILE__);
    exit;
}

// ?about renders a full documentation page (with the download link for
// this script at the top) instead of the gallery — this is what the
// "gallery" link in the footer points to.
if (isset($_GET['about'])) {
    render_about_page();
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
    ];
    return $map[$ext] ?? 'application/octet-stream';
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
 */
function gc_image_cache($dir, $cacheDir, $files, $imagickAvailable) {
    if (!ENABLE_THUMBNAIL_CACHE || !is_dir($cacheDir)) return;
    $valid = [];
    foreach ($files as $file) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $isHeic = in_array($ext, ['heic', 'heif'], true) && $imagickAvailable;
        $variants = $isHeic ? ['thumb', 'share', 'full'] : ['thumb', 'share'];
        $mtime = @filemtime($dir . '/' . $file);
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

/** Serves ?img=<filename>&size=thumb|share|full, resizing/caching/converting as needed. */
function serve_image($dir, $cacheDir, $filename, $variant, $validFiles) {
    $filename = basename($filename);
    if (!in_array($filename, $validFiles, true)) {
        http_response_code(404);
        return;
    }
    $srcPath = $dir . '/' . $filename;
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $isHeic = in_array($ext, ['heic', 'heif'], true);
    $maxDims = ['thumb' => 480, 'share' => 1200, 'full' => 2400];
    $maxDim = $maxDims[$variant] ?? 480;

    // Non-HEIC files at full size are served untouched — no need to
    // resize or re-encode the original the lightbox already opens fine.
    if (!$isHeic && $variant === 'full') {
        header('Content-Type: ' . guess_mime($ext));
        header('Cache-Control: public, max-age=31536000, immutable');
        readfile($srcPath);
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
    header('Content-Type: ' . guess_mime($ext));
    readfile($srcPath);
}

function img_url($file, $size) {
    return '?img=' . rawurlencode($file) . '&size=' . $size;
}

if (isset($_GET['img'])) {
    serve_image($dir, $cacheDir, $_GET['img'], $_GET['size'] ?? 'thumb', $files);
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
$mdCandidates = array_filter(scandir($dir), function ($file) use ($dir) {
    if (!is_file("$dir/$file")) return false;
    return strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'md';
});
usort($mdCandidates, function ($a, $b) {
    return strnatcasecmp($a, $b);
});
$mdCandidates = array_values($mdCandidates);

$mdFiles = [];
$rejectedMdCount = 0;
foreach ($mdCandidates as $candidate) {
    if (has_gallery_marker(file_get_contents("$dir/$candidate"))) {
        $mdFiles[] = $candidate;
    } else {
        $rejectedMdCount++;
    }
}
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
    $content = strip_gallery_marker(file_get_contents("$dir/$mdFile"));
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

// Build the final groups: filename-based auto-detection (unless
// AUTO_GROUP_BY_FILENAME is off, or a file was explicitly claimed above)
// plus explicit named groups. A file claimed via a bare {photos:} (no
// {group:}) stays its own standalone single, keyed by its own filename
// so it can never merge with an unrelated auto-detected group.
$groups = [];
foreach ($files as $file) {
    if (isset($explicitClaims[$file])) continue;
    $groups[group_key($file)][] = $file;
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
// an explicitly named one always does; that's the point of naming it.
// Computed here (not just once, further down) because zone membership
// during ordering depends on it too.
$explicitGroupKeys = array_fill_keys(array_keys($explicitGroups), true);

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
function order_within_zone($keysInZone, $mdContents, $groups, $explicitGroups, $positionalClusters, $naturalRank) {
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
        $natural[] = ['rank' => $naturalRank[$groups[$key][0]] ?? PHP_INT_MAX, 'keys' => [$key]];
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
function compute_zone_order($mdContents, $groups, $explicitGroups, $positionalClusters, $explicitGroupKeys, $naturalRank) {
    $boxKeys = [];
    $singleKeys = [];
    foreach ($groups as $key => $groupFiles) {
        if (count($groupFiles) >= 2 || isset($explicitGroupKeys[$key])) {
            $boxKeys[] = $key;
        } else {
            $singleKeys[] = $key;
        }
    }
    $boxOrder = order_within_zone($boxKeys, $mdContents, $groups, $explicitGroups, $positionalClusters, $naturalRank);
    $singleOrder = order_within_zone($singleKeys, $mdContents, $groups, $explicitGroups, $positionalClusters, $naturalRank);
    return array_merge($boxOrder, $singleOrder);
}

// Reorders $groups per compute_zone_order() (boxes first, singles+
// clusters after; prose-position within each, natural order otherwise),
// then rebuilds the flat photo list to match, so the lightbox's index
// numbering (shared by both the note thumbnails and the gallery cards)
// stays consistent with the new display order.
$groupOrder = compute_zone_order($mdContents, $groups, $explicitGroups, $positionalClusters, $explicitGroupKeys, $naturalRank);
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

/* -----------------------------------------------------------------------
 * View-stats storage. Two interchangeable backends behind one small set
 * of functions (stats_bump_pageview / stats_bump_photo /
 * stats_get_photo_views) so the rest of the script never needs to know
 * or care which one is active. SQLite is used automatically when PHP
 * supports it; otherwise a flock-protected JSON file is used instead.
 * ----------------------------------------------------------------------- */

$GLOBALS['__stats_mode'] = 'file';
$GLOBALS['__stats_pdo'] = null;
$GLOBALS['__stats_file'] = $dir . '/.gallery-stats.json';

function stats_init($dir) {
    if (class_exists('PDO') && in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        try {
            $pdo = new PDO('sqlite:' . $dir . '/.gallery-stats.sqlite');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('CREATE TABLE IF NOT EXISTS stats (key TEXT PRIMARY KEY, value INTEGER NOT NULL DEFAULT 0)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS photo_views (filename TEXT PRIMARY KEY, views INTEGER NOT NULL DEFAULT 0)');
            $GLOBALS['__stats_pdo'] = $pdo;
            $GLOBALS['__stats_mode'] = 'sqlite';
        } catch (Exception $e) {
            $GLOBALS['__stats_mode'] = 'file';
        }
    }
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

function stats_bump_pageview() {
    if ($GLOBALS['__stats_mode'] === 'sqlite') {
        $pdo = $GLOBALS['__stats_pdo'];
        $pdo->exec("INSERT INTO stats(key, value) VALUES('pageviews', 1)
                    ON CONFLICT(key) DO UPDATE SET value = value + 1");
        return (int) $pdo->query("SELECT value FROM stats WHERE key = 'pageviews'")->fetchColumn();
    }
    $stats = stats_update_file($GLOBALS['__stats_file'], function (&$data) {
        $data['pageviews'] = ($data['pageviews'] ?? 0) + 1;
    });
    return (int) $stats['pageviews'];
}

/** Increments one photo's view count and returns its new total. */
function stats_bump_photo($filename) {
    if ($GLOBALS['__stats_mode'] === 'sqlite') {
        $pdo = $GLOBALS['__stats_pdo'];
        $stmt = $pdo->prepare(
            "INSERT INTO photo_views(filename, views) VALUES(:f, 1)
             ON CONFLICT(filename) DO UPDATE SET views = views + 1"
        );
        $stmt->execute([':f' => $filename]);
        $sel = $pdo->prepare('SELECT views FROM photo_views WHERE filename = :f');
        $sel->execute([':f' => $filename]);
        return (int) $sel->fetchColumn();
    }
    $stats = stats_update_file($GLOBALS['__stats_file'], function (&$data) use ($filename) {
        $data['photos'][$filename] = ($data['photos'][$filename] ?? 0) + 1;
    });
    return (int) ($stats['photos'][$filename] ?? 0);
}

function stats_get_photo_views() {
    if ($GLOBALS['__stats_mode'] === 'sqlite') {
        $result = [];
        foreach ($GLOBALS['__stats_pdo']->query('SELECT filename, views FROM photo_views') as $row) {
            $result[$row['filename']] = (int) $row['views'];
        }
        return $result;
    }
    $statsFile = $GLOBALS['__stats_file'];
    if (!file_exists($statsFile)) return [];
    $data = json_decode((string) @file_get_contents($statsFile), true);
    return (is_array($data) && isset($data['photos']) && is_array($data['photos'])) ? $data['photos'] : [];
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

if (TRACK_VIEWS) {
    stats_init($dir);
}

// ?track=<filename> is a tiny background ping fired by the lightbox each
// time a photo is displayed. Only counts filenames that are actually in
// this folder's photo list, and skips known bots. Responds with the fresh
// photo + group totals as JSON so the page can update the visible numbers
// immediately, with no reload — and never renders the gallery itself.
if (isset($_GET['track'])) {
    $response = ['ok' => false];
    if (TRACK_VIEWS && !is_bot_request()) {
        $requested = basename($_GET['track']);
        if (in_array($requested, $files, true)) {
            $newCount = stats_bump_photo($requested);
            $groupKey = $fileToGroupKey[$requested] ?? group_key($requested);
            $groupFiles = $groups[$groupKey] ?? [$requested];
            $currentPhotoViews = stats_get_photo_views();
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
        $viewCount = stats_bump_pageview();
    }
    $photoViews = stats_get_photo_views();
}

gc_image_cache($dir, $cacheDir, $files, $imagickAvailable);

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
    $viewsHidden = $groupTotal > 0 ? '' : ' hidden';

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
    $html .= '<a class="linked-photos-jump" href="#group-' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') . '">View '
            . $count . ' photo' . ($count === 1 ? '' : 's') . ' &darr;</a>';
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

/** Small 🔗 icon linking to #<itemId> — the permalink for one .md item. */
function render_item_permalink($itemId) {
    return '<a class="item-permalink" href="#' . htmlspecialchars($itemId, ENT_QUOTES, 'UTF-8')
         . '" title="Link to this item">&#128279;</a>';
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
                $linkHtml = ($i === 0) ? render_item_permalink($itemId) : '';
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
            $html .= render_photo_thumbs_plain($explicitPhotos, $files, render_item_permalink($itemId));
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
 *  photo "groups" never get a box), a colored inset ring instead. */
function render_card($file, $index, $photoViews, $status = '', $color = null, $anchorId = null) {
    $views = isset($photoViews[$file]) ? (int) $photoViews[$file] : 0;
    $hidden = $views > 0 ? '' : ' hidden';
    $badge = '<span class="view-badge' . $hidden . '" data-photo="' . htmlspecialchars($file, ENT_QUOTES, 'UTF-8') . '">&#128065; '
           . number_format($views) . '</span>';
    $statusBadge = '';
    if ($status === 'sold') {
        $statusBadge = '<span class="sold-badge-corner">SOLD</span>';
    } elseif ($status === 'reserved') {
        $statusBadge = '<span class="reserved-badge-corner">RESERVED</span>';
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
         . '" loading="lazy" alt="' . htmlspecialchars($file, ENT_QUOTES, 'UTF-8') . '">' . $statusBadge . $badge . '</div>';
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

/** Builds the whole gallery section: boxed groups (with optional per-group
 *  accent color, sold/reserved ribbon, and a live-updatable total view
 *  count) + a plain row for standalone singles. */
function build_gallery_html($groups, $groupColors, $groupStatus, $photoViews, $groupLabels = [], $explicitGroupKeys = []) {
    $html = '';
    $flatIndex = 0;

    foreach ($groups as $key => $groupFiles) {
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
            $html .= render_card($groupFiles[0], $flatIndex, $photoViews, $status, $accentColor, 'group-' . $slug);
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
        $viewsHidden = $groupTotal > 0 ? '' : ' hidden';

        $html .= '<div class="group-box' . ($status ? ' ' . $status : '') . '"' . $boxStyle . ' id="group-' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') . '">';
        $html .= '<h2 class="group-label"' . $labelStyle . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
                . ' <span class="group-views' . $viewsHidden . '" data-group="' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') . '">&middot; '
                . number_format($groupTotal) . ' view' . ($groupTotal === 1 ? '' : 's') . '</span></h2>';
        $html .= '<div class="group-grid">';
        foreach ($groupFiles as $file) {
            $html .= render_card($file, $flatIndex, $photoViews);
            $flatIndex++;
        }
        $html .= '</div></div>';
    }
    return $html;
}

/** Builds the current request's scheme://host/path, for absolute OG image URLs. */
function current_base_url() {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
          || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    $path = isset($_SERVER['PHP_SELF']) ? rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'])), '/') : '';
    return $scheme . '://' . $host . $path;
}

/**
 * Renders the "?about" page: a standalone documentation page (with the
 * download link for this script at the top) explaining everything this
 * gallery does, for anyone who found it deployed somewhere and wants to
 * use it themselves. Kept as a self-contained HTML string rather than a
 * .md-style file so it never depends on anything else in the folder.
 */
function render_about_page() {
    $version = htmlspecialchars(GALLERY_VERSION, ENT_QUOTES, 'UTF-8');
    $aboutUrl = htmlspecialchars(current_base_url() . '/?about', ENT_QUOTES, 'UTF-8');
    $repoUrl = 'https://github.com/pcmike/gallery';
    $aboutDescription = 'A single-file, drop-in PHP photo gallery with Markdown notes, sold/reserved markers, view stats, and deep links.';
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
  <a class="download-btn" href="?download">&#11015; Download index.php</a>

  <h2>What it does</h2>
  <ul>
    <li>Groups photos automatically by filename, or curate groups explicitly in a <code>.md</code> file — no naming convention required</li>
    <li>Full-screen lightbox with keyboard nav and mobile swipe</li>
    <li>Optional <code>.md</code> notes box — auto-linked thumbnails, <code>{color}</code>/<code>{sold}</code>/<code>{reserved}</code> directives</li>
    <li>Live view stats and thumbnail caching — both opt-in, so nothing is written to your folder unless you turn them on</li>
    <li>HEIC/HEIF (iPhone photo) support once thumbnail caching is enabled</li>
    <li>Deep links (<code>?photo=</code>, <code>#group-name</code>, <code>#item-name</code>) with correct Open Graph previews when shared</li>
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
$ogTitle = $pageTitle;
$ogUrl = $baseUrl . '/';
$ogDescription = !empty($mdFiles)
    ? markdown_to_plaintext($mdContents[$mdFiles[0]])
    : (count($files) . ' photo' . (count($files) === 1 ? '' : 's'));
$ogImageUrl = !empty($files) ? $baseUrl . '/' . img_url($files[0], 'share') : '';
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
  .item-permalink {
    color: var(--muted);
    text-decoration: none;
    font-size: 0.8rem;
    opacity: 0.75;
  }
  .item-permalink:hover { opacity: 1; }
  .item-permalink.copied { color: #3ba55c; opacity: 1; }
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
  .view-badge {
    position: absolute;
    bottom: 6px;
    right: 6px;
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
  .lightbox img {
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
  <h1><?= htmlspecialchars($pageTitle) ?></h1>
  <p><?= count($files) ?> photo<?= count($files) === 1 ? '' : 's' ?></p>
</header>

<?php if (empty($mdFiles) && !empty($galleryNotices)): ?>
  <div class="notes">
    <?= render_gallery_notices_banner($galleryNotices) ?>
  </div>
<?php endif; ?>

<?php if (!empty($mdFiles)): ?>
  <div class="notes">
    <?= render_gallery_notices_banner($galleryNotices) ?>
    <?php foreach ($mdFiles as $mdFile): ?>
      <div class="note-box">
        <span class="note-filename"><?= htmlspecialchars($mdFile) ?></span>
        <?= markdown_to_html($mdContents[$mdFile], $groups, $files, $groupColors, $groupStatus, $groupLabels, $renderClaims, $photoViews) ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if (empty($files)): ?>
  <div class="empty">No photos found in this folder.</div>
<?php else: ?>
  <div class="gallery">
    <?= build_gallery_html($groups, $groupColors, $groupStatus, $photoViews, $groupLabels, $explicitGroupKeys) ?>
  </div>
<?php endif; ?>

<footer>
  <a href="?about">gallery</a><?php if ($viewCount > 0): ?> · <?= number_format($viewCount) ?> views<?php endif; ?>
</footer>

<div class="lightbox" id="lightbox">
  <button class="close" onclick="closeLightbox()">&times;</button>
  <button class="nav prev" onclick="navigate(-1)">&#10094;</button>
  <img id="lightbox-img" src="" alt="">
  <div class="caption-row">
    <div class="caption" id="lightbox-caption"></div>
    <button class="lightbox-copy" id="lightbox-copy-btn" title="Copy link to this photo">&#128279;</button>
  </div>
  <button class="nav next" onclick="navigate(1)">&#10095;</button>
</div>

<script>
  const files = <?= json_encode($files) ?>;
  const initialPhoto = <?= json_encode($requestedPhoto) ?>;
  const trackingEnabled = <?= TRACK_VIEWS ? 'true' : 'false' ?>;
  let current = 0;
  const lightbox = document.getElementById('lightbox');
  const lightboxImg = document.getElementById('lightbox-img');
  const caption = document.getElementById('lightbox-caption');

  function cssEscape(value) {
    return (window.CSS && CSS.escape) ? CSS.escape(value) : value.replace(/["\\]/g, '\\$&');
  }

  // Writes fresh counts from a ?track response into every matching badge
  // on the page (a photo can appear both in the gallery and in a note-box
  // thumbnail strip; a group total can appear in both places too).
  function applyViewUpdate(data) {
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
  }
  function navigate(delta) {
    current = (current + delta + files.length) % files.length;
    updateImage();
  }
  function updateImage() {
    lightboxImg.src = '?img=' + encodeURIComponent(files[current]) + '&size=full';
    caption.textContent = files[current] + ' — ' + (current + 1) + ' / ' + files.length;
    trackView(files[current]);
  }
  document.addEventListener('keydown', (e) => {
    if (!lightbox.classList.contains('open')) return;
    if (e.key === 'Escape') closeLightbox();
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
    const link = e.target.closest('.item-permalink');
    if (!link) return;
    const cleanUrl = window.location.origin + window.location.pathname + link.hash;
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
