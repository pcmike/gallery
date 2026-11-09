/** How long a visitor can go quiet and still be the same visit (the view counts, and the public address's log). */
define('VISIT_WINDOW_SECONDS', 1800);
const LINK_ICON_GALLERY = '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="5" width="16" height="14" rx="2.5"/><circle cx="9" cy="10" r="1.6"/><path d="m5 17 5-5 3.5 3.5L16 13l3 3"/></svg>';
const LINK_ICON_WARN = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4 3.5 19h17zM12 10v4M12 16.8v.2"/></svg>';

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

// How many days a signed-in browser stays signed in, counted from its last visit: the owner (ADMIN_STAY_SIGNED_IN_DAYS) and someone
// given the private-view password (PRIVATE_VIEW_SIGNED_IN_DAYS). 0 is only until the browser closes (and, on the server, a day). Each
// browser holds a random token that the server can end one by one (Settings → Signing in), so a lost phone or a shared computer needs
// no new password. A token is also what lets an owner whose session cookie is gone (iOS drops one when a Home Screen app is closed)
// come back without typing the password.
define('ADMIN_STAY_SIGNED_IN_DAYS', max(0, min(365, (int) gallery_setting('ADMIN_STAY_SIGNED_IN_DAYS', 3))));
// How many browsers a NEW share link may let in (its "number of uses"): 0 is unlimited. Most links are made for one person, who uses one or
// two browsers; a group link is made unlimited by hand.
define('DEFAULT_LINK_USES', max(0, min(1000, (int) gallery_setting('DEFAULT_LINK_USES', 2))));
define('PRIVATE_VIEW_SIGNED_IN_DAYS', max(0, min(365, (int) gallery_setting('PRIVATE_VIEW_SIGNED_IN_DAYS', 3))));
// What each signed-in browser's history keeps and when it raises a flag (see "What each signed-in browser has done"). All of these are
// settings: what counts as odd is the owner's call. The four END_ON switches end a browser's sign-in the moment that flag is raised; off
// by default, a flag is only shown.
define('BROWSER_HISTORY_DAYS', max(1, min(3650, (int) gallery_setting('BROWSER_HISTORY_DAYS', 90))));
define('BROWSER_ENDED_DAYS', max(0, min(3650, (int) gallery_setting('BROWSER_ENDED_DAYS', 30))));
define('BROWSER_NETWORKS_ALARM', max(2, min(1000, (int) gallery_setting('BROWSER_NETWORKS_ALARM', 20))));
define('BROWSER_END_ON_DEVICE', (bool) gallery_setting('BROWSER_END_ON_DEVICE', false));
define('BROWSER_END_ON_FAMILY', (bool) gallery_setting('BROWSER_END_ON_FAMILY', false));
define('BROWSER_END_ON_COUNTRY', (bool) gallery_setting('BROWSER_END_ON_COUNTRY', false));
define('BROWSER_END_ON_NETWORKS', (bool) gallery_setting('BROWSER_END_ON_NETWORKS', false));
// The country of each address, from a small file of address ranges (see "The country of an address"). Off until the owner switches it on in
// Settings, which fetches the file. COUNTRY_DB_URL is only for a mirror of your own or a test: {ym} stands for the month ("2026-11").
define('COUNTRY_LOOKUP', (bool) gallery_setting('COUNTRY_LOOKUP', false));
define('COUNTRY_DB_URL', (string) gallery_setting('COUNTRY_DB_URL', ''));
// Looking for a new version once a day (see "New versions"); UPDATE_FEED_URL is only for a mirror of your own or a test.
define('CHECK_FOR_UPDATES', (bool) gallery_setting('CHECK_FOR_UPDATES', true));
define('UPDATE_FEED_URL', (string) gallery_setting('UPDATE_FEED_URL', ''));
// (the cookie 2.3.2 kept the owner's token in; only read now to move a browser over to the one above, see LEGACY-REMOVAL)
define('ADMIN_STAY_COOKIE_NAME', 'gallery_stay_' . substr(hash('sha256', __DIR__), 0, 10));

// Plots every visible, located photo on one overview map — Leaflet plus
// OpenStreetMap tiles, the only realistic option with no API key. Off by
// default: this is the *first* feature in this script to break the
// "one file, nothing else happens" promise, since it loads an external
// script and image tiles from a third party at page-load time. Has no
// effect at all unless ENABLE_PHOTO_LOCATION is also on and at least one
// photo actually has a visible location — see ENABLE_PHOTO_LOCATION's
// own doc comment for that gating. Shared as ?map
// URL, with a smaller preview embedded on the gallery root.
gallery_setting('ENABLE_MAP', false);   // (older settings files have this line: it is still understood, with no "isn't a setting" notice, and ignored; a map is available wherever locations are, and each folder's own Map switch and the visitors' map buttons are the control. The next save in Settings drops it.)

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
 * it must never follow a symlink, to a folder or to a file (a link pointing outside the gallery
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
    // A folder it has no business walking into (a system or recycle folder), and a ceiling on how much one visit scans, so a gallery
    // dropped into a huge tree (a whole web root) cannot make every page crawl. The Health check says when the ceiling was hit.
    static $dirs = 0, $files = 0;
    if ($isRoot) { $dirs = 0; $files = 0; $GLOBALS['__scan_truncated'] = false; }
    if (++$dirs > SCAN_MAX_FOLDERS || $files > SCAN_MAX_FILES) { $GLOBALS['__scan_truncated'] = true; return $node; }
    $entries = @scandir($realDir);
    if ($entries === false) return $node;
    $parentReal = realpath($realDir);
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        if ($isRoot && $entry === $self) continue;
        $path = $realDir . '/' . $entry;
        if (is_link($path)) continue;   // (a link is never followed: not to a folder, and not to a file, which could be anywhere on the server)
        if (is_file($path)) {
            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if (in_array($ext, $mediaExtensions, true)) {
                $node['files'][] = $entry; $files++;
            }
            continue;
        }
        if (!is_dir($path) || $entry[0] === '.' || is_link($path) || in_array($entry, SCAN_SKIP_FOLDERS, true)) {
            continue;   // (dot folders such as .git and .gallery-cache are never photo folders)
        }
        $childReal = realpath($path);
        if ($childReal === false || $parentReal === false
            || strpos($childReal, $parentReal . DIRECTORY_SEPARATOR) !== 0) {
            continue;
        }
        $node['subdirs'][$entry] = scan_folder_tree($path, $mediaExtensions, $self, false);
    }
    if (PHOTO_SORT_ORDER === 'mtime') {
        $times = [];   // (each file's date is read once, not at every comparison)
        foreach ($node['files'] as $f) $times[$f] = @filemtime("$realDir/$f") ?: 0;
        usort($node['files'], function ($a, $b) use ($times) {
            return $times[$a] <=> $times[$b];
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

/** The top-level subfolders that hold photos or videos, with how many each holds (what is hidden while Show subfolders is off): a
 *  list of ['name' => .., 'count' => ..], biggest first, and whether the search was cut short. Bounded and link-safe like the count above. */
function hidden_subfolders_summary($realDir, $mediaExtensions, $maxDirs = 40) {
    $per = []; $visited = 0; $truncated = false; $queue = [];
    foreach ((@scandir($realDir) ?: []) as $entry) {
        if ($entry[0] === '.') continue;
        $path = $realDir . '/' . $entry;
        if (is_dir($path) && !is_link($path)) $queue[] = [$path, $entry];
    }
    while ($queue) {
        if ($visited++ >= $maxDirs) { $truncated = true; break; }
        [$current, $top] = array_shift($queue);
        foreach ((@scandir($current) ?: []) as $entry) {
            if ($entry[0] === '.') continue;
            $path = $current . '/' . $entry;
            if (is_link($path)) continue;
            if (is_dir($path)) $queue[] = [$path, $top];
            elseif (in_array(strtolower(pathinfo($entry, PATHINFO_EXTENSION)), $mediaExtensions, true)) $per[$top] = ($per[$top] ?? 0) + 1;
        }
    }
    arsort($per);
    $out = [];
    foreach ($per as $name => $count) $out[] = ['name' => (string) $name, 'count' => $count];
    return [$out, $truncated];
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
    foreach ($segments as $seg) {   // (the scan never enters a dot folder or a system folder, so nothing in one is a gallery photo)
        if ($seg[0] === '.' || in_array($seg, SCAN_SKIP_FOLDERS, true)) return false;
    }
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
    return is_file($current . '/' . $file) && !is_link($current . '/' . $file);
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

/** Whether the public address is closed right now: the setting is on, and there is a way in (share links on, an admin password set). */
function gallery_is_closed() {
    return CLOSE_PUBLIC_GALLERY && ENABLE_SHARE_LINKS && ADMIN_PASSWORD !== '';
}

/** True when a live link uses this photo as its preview picture: the one thing a stranger may fetch while the gallery is closed,
 *  since a chat app and the opening page ask for it with no cookie. */
function closed_gallery_serves_picture($rel, $dir) {
    if (!is_string($rel) || $rel === '') return false;
    foreach ((array) (links_read()['links'] ?? []) as $l) {
        if (!is_array($l) || link_is_public_log($l) || !link_is_live($l)) continue;
        if (link_preview_picture($l, $dir) === $rel) return true;
    }
    return false;
}

/** The addresses "admin" and "reveal" (with or without a closing slash) go to the one sign-in; other places that name them do too. */
function sign_in_redirect() {
    header('Cache-Control: no-store');
    header('Location: ' . gallery_root_href() . '?reveal', true, 302);
    exit;
}

// A closed gallery: before anything is read or served (a photo, a thumbnail, a folder, the map), a stranger gets the page of a link
// that isn't valid, the same as for any bad address, so nothing says there is a gallery here. Still open to everyone: the sign-in, the
// admin, a share link's own address, and the preview picture of a live link. "/admin/" (a folder address) is the sign-in as well.
if (isset($_GET['folder']) && is_string($_GET['folder']) && in_array(strtolower(trim($_GET['folder'], '/')), ['admin', 'reveal'], true) && !is_dir($dir . '/' . trim($_GET['folder'], '/'))) {
    sign_in_redirect();
}
/**
 * Every address that is answered by a script of its own, not by the gallery page, in the order they are tried: the first one whose
 * parameter is in the address answers, and nothing after it runs (?img=...&admin=1 is the picture's, not the admin's). This one list is
 * what the closed-gallery gate below reads and what every handler asks (request_route() === 'name'), so the gate and the handlers cannot
 * disagree about which of two parameters wins, and a new route is closed to a stranger until it is marked 'open' here. 'open' is for the
 * ways in only (the sign-in, a share link's own address, the admin's sign-in). The gallery page itself and its folder, map and photo
 * views are no route: they are the gallery, closed to a stranger like everything else.
 */
function request_routes() {
    return [
        'original'    => ['params' => ['original']],
        'linklog'     => ['params' => ['linklog']],
        'exitpreview' => ['params' => ['exitpreview']],
        'strip'       => ['params' => ['strip']],
        'preview'     => ['params' => ['preview']],
        'img'         => ['params' => ['img', 'video']],
        'download'    => ['params' => ['download']],
        'about'       => ['params' => ['about']],
        'mapicon'     => ['params' => ['mapicon'], 'when' => ENABLE_PHOTO_LOCATION],
        'appicon'     => ['params' => ['appicon']],
        'manifest'    => ['params' => ['manifest']],
        'reveal'      => ['params' => ['reveal'], 'open' => true],
        'link'        => ['params' => ['link'], 'open' => true],
        'admin'       => ['params' => ['admin'], 'open' => true],
        'track'       => ['params' => ['track']],
        'archive'     => ['params' => ['archive']],
    ];
}

/** The name of the route this request is for (see request_routes), or '' when it is the gallery page. */
function request_route($reset = false) {
    static $route = null;
    if ($reset) $route = null;
    if ($route !== null) return $route;
    $route = '';
    foreach (request_routes() as $name => $r) {
        if (array_key_exists('when', $r) && !$r['when']) continue;
        foreach ($r['params'] as $p) if (isset($_GET[$p])) return $route = $name;
    }
    return $route;
}

/** The words a bare query (?word, nothing else) means for the gallery itself. Any other single word is an address someone typed: a share link's or a
 *  Not listed photo's (see bare_query_as_address). */
function reserved_query_words() {
    $words = ['index.php', 'map', 'archive', 'all', 'csv', 'file', 'folder', 'hidden', 'name', 'photo', 'size', 'sort', 'tab', 'what', 'warm', 'shell', 'item', 'exact', 'prefix', 'inframe', 'csrf', 'k',
        'view', 'accelcheck', 'browserhist', 'filesdownload', 'linkcover', 'linkphotos', 'linkpreview', 'q', 'visits', 'visitsexport', 'pbcounts', 'privcounts', 'scope_type',
        'shellcounts', 'shellmeta', 'shelltree'];
    foreach (request_routes() as $r) foreach ($r['params'] as $p) $words[] = $p;
    return $words;
}

/** A query that is one bare word and nothing else (the address typed as /photos/?Ab3x9Qz2 where the server's one-word rule isn't set up) is the same as
 *  ?link=<word>: the one place that tells a share link's or a Not listed photo's address from a wrong guess, and counts the guess. */
function bare_query_as_address() {
    $qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
    if (preg_match('/^([A-Za-z0-9._~-]{1,60})(?:&.*)?$/Ds', $qs, $m) !== 1 || in_array($m[1], reserved_query_words(), true)) return;   // (the word first, nothing like word=value; other parameters a chat app added are ignored)
    unset($_GET[str_replace(['.', ' '], '_', $m[1])]);
    $_GET['link'] = $m[1];
}
bare_query_as_address();

if (gallery_is_closed()) {
    $route = request_route();
    $routeIsOpen = $route !== '' && !empty(request_routes()[$route]['open']);
    // The one thing a stranger may fetch: the preview picture of a live link (a chat app asks for it with no cookie).
    $pictureOnly = $route === 'img' && ($_GET['size'] ?? '') === 'share' && !isset($_GET['video']);
    if (!$routeIsOpen && !is_revealed() && !is_admin_browser() && link_viewer() === null
        && !($pictureOnly && closed_gallery_serves_picture($_GET['img'], $dir))) {
        render_link_invalid_page();
        exit;
    }
}

// A code (k=...) that opens nothing is a wrong guess whether or not the path it came with exists, counted once per request when it ends: otherwise who
// gets paused would tell a Not listed photo that is there from one that is not.
if (isset($_GET['k']) && is_string($_GET['k']) && in_array(request_route(), ['', 'img', 'original', 'track', 'strip'], true)) {
    register_shutdown_function(function () use ($dir) { if (empty($GLOBALS['__photo_pass_ok']) && !photo_code_is_right_for_request($dir)) link_guess_failed($dir, (string) $_GET['k']); });
}

// ?original=<file> hands a signed-in visitor (the owner, or someone with the private-view password) the original file
// itself, as a download. Everyone else gets 404, as for any other thing that isn't there.
if (request_route() === 'original') {
    $claimed = $_GET['original'];
    $claimedList = valid_media_path($dir, $claimed, $mediaExtensions, $self) ? [$claimed] : [];
    serve_original_download($dir, $claimed, $claimedList, $claimedList ? items_get_all_for_files($dir, $claimedList) : []);
    exit;
}

// ?linklog: what someone using a share link has had on screen, opened and played (a small POST from their page).
if (request_route() === 'linklog') {
    run_linklog_route($dir, $mediaExtensions, $self);
    exit;
}

// ?exitpreview: ends "Preview as this link" in this browser and goes back to the Shares tab.
if (request_route() === 'exitpreview') {
    preview_clear_cookie();
    header('Cache-Control: no-store');
    header('Location: ?admin#links', true, 303);
    exit;
}

// ?strip=<video>: the grid of frames a finger scrubs through on a video's tile.
if (request_route() === 'strip') {
    $claimed = $_GET['strip'];
    $claimedList = valid_media_path($dir, $claimed, $mediaExtensions, $self) ? [$claimed] : [];
    serve_video_strip($dir, $claimed, $claimedList, $claimedList ? items_get_all_for_files($dir, $claimedList) : []);
    exit;
}

// ?preview=<video>: the short hover clip of a video.
if (request_route() === 'preview') {
    $claimed = $_GET['preview'];
    $claimedList = valid_media_path($dir, $claimed, $mediaExtensions, $self) ? [$claimed] : [];
    serve_video_preview($dir, $claimed, $claimedList, $claimedList ? items_get_all_for_files($dir, $claimedList) : []);
    exit;
}

// ?img= and ?video= answer here, before the tree is scanned: one
// direct path validation (see valid_media_path) instead of walking every
// folder for every thumbnail. serve_image()/serve_video() still run
// their own allowlist check and the hide check on whatever is passed in.
if (request_route() === 'img') {
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
        if (!is_file("$dir/$file") || is_link("$dir/$file")) return false;   // (a link is never followed: it could point anywhere on the server)
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        return in_array($ext, $mediaExtensions);
    });

    // Base ordering, before any .md-driven repositioning — see
    // PHOTO_SORT_ORDER above.
    if (PHOTO_SORT_ORDER === 'mtime') {
        $times = [];
        foreach ($files as $f) $times[$f] = @filemtime("$dir/$f") ?: 0;
        usort($files, function ($a, $b) use ($times) {
            return $times[$a] <=> $times[$b];
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

