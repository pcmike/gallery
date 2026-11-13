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

/** The same photo under the other names its place has had ("eurotrip/paris/a.jpg" for "Eurotrip/paris/a.jpg", say), for a photo in a named place
 *  from elsewhere on the server (EXTRA_FOLDERS); [] for any other. Its views were recorded under each of those names and are added up. */
function stats_alias_paths($relativePath) {
    static $byLabel = [];
    $slash = strpos((string) $relativePath, '/');
    if ($slash === false) return [];
    $label = substr($relativePath, 0, $slash);
    if (!array_key_exists($label, $byLabel)) {
        $byLabel[$label] = [];
        $named = is_array(EXTRA_FOLDERS) ? EXTRA_FOLDERS : [];
        $names = is_array(EXTRA_FOLDER_NAMES) ? EXTRA_FOLDER_NAMES : [];
        $place = $named[$label] ?? null;
        if (is_string($place) && isset($names[$place]) && is_array($names[$place])) {
            foreach ($names[$place] as $other) if (is_string($other) && $other !== '' && $other !== $label) $byLabel[$label][] = $other;
        }
    }
    $rest = substr($relativePath, $slash);
    return array_map(function ($other) use ($rest) { return $other . $rest; }, $byLabel[$label]);
}

/** Increments one photo's view count (in its own folder's store) and returns its new total (with what it was counted under its place's earlier names). */
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
        $total = (int) $sel->fetchColumn();
        foreach (stats_alias_paths($relativePath) as $alt) { $sel->execute([':k' => stats_key($alt)]); $total += (int) $sel->fetchColumn(); }
        return $total;
    }
    $stats = stats_update_file($conn['file'], function (&$data) use ($relativePath) {
        $data['photos'][$relativePath] = ($data['photos'][$relativePath] ?? 0) + 1;
    });
    $total = (int) ($stats['photos'][$relativePath] ?? 0);
    foreach (stats_alias_paths($relativePath) as $alt) $total += (int) ($stats['photos'][$alt] ?? 0);
    return $total;
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
            $key = stats_key($file); $sum = $byKey[$key] ?? 0; $any = isset($byKey[$key]);
            foreach (stats_alias_paths($file) as $alt) { $ak = stats_key($alt); if (isset($byKey[$ak])) { $sum += $byKey[$ak]; $any = true; } }
            if ($any) $result[$file] = $sum;
        }
        return $result;
    }
    if (!file_exists($conn['file'])) return [];
    $data = json_decode(data_file_strip_guard(@file_get_contents($conn['file'])), true);
    $photos = (is_array($data) && isset($data['photos']) && is_array($data['photos'])) ? $data['photos'] : [];
    foreach ($candidateFiles as $file) {   // (the counts under the place's earlier names are added to the one under its present name)
        foreach (stats_alias_paths($file) as $alt) if (isset($photos[$alt])) $photos[$file] = (int) ($photos[$file] ?? 0) + (int) $photos[$alt];
    }
    return $photos;
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

/** True if this browser is the admin's own (it carries the owner's token,
 *  made at login). The owner looking at their own gallery is not an audience. */
function is_admin_browser() {
    if (link_preview() !== null) return false;
    $b = browser_lookup();
    return $b !== null && $b['kind'] === 'owner';
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

/** Whether this browser is one the view counts count: anyone but the owner (their own browser, or while previewing a link as its guest) and the known
 *  bots and link-preview fetchers. A share link's guest and a private-view visitor are counted like anyone else: they looked. */
function view_is_counted() {
    return !is_bot_request() && !is_admin_browser() && link_preview() === null;
}

/**
 * Whether this page load begins a new VISIT, and so adds one to the site total.
 * A visit is one visitor for as long as they keep coming back within
 * VISIT_WINDOW_SECONDS of their last page: opening a dozen folders, a photo
 * link, reload and back button are all one visit. (Counting every page load,
 * as this once did, made the total several times the number of people.) It
 * does not count the admin's own browser, link-preview bots, prefetches or
 * HEAD requests; everyone else is counted, someone on a share link or with the
 * private-view password included. A small first-party cookie holds the time of the visitor's
 * last page; every counted page load slides it forward.
 */
function visit_is_new() {
    if (!view_is_counted() || is_non_viewing_request()) return false;
    $now = time();
    $last = (int) ($_COOKIE['gallery_visit'] ?? 0);
    $isNew = $last <= 0 || $last > $now + 60 || ($now - $last) > VISIT_WINDOW_SECONDS;
    setcookie('gallery_visit', (string) $now, [
        'expires' => $now + VISIT_WINDOW_SECONDS,
        'path' => gallery_cookie_path(),
        'secure' => is_https_request(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    return $isNew;
}

// ?track=<filename> is a tiny background ping fired by the lightbox each
// time a photo is displayed. Only counts filenames that are actually in
// this folder's photo list and skips known bots, previews and the owner's
// own browser; everyone else counts (a share link's guest and a private-view
// visitor too), every time, with no waiting period. Responds with the fresh photo +
// group totals as JSON so the page can update the visible numbers
// immediately, with no reload — and never renders the gallery itself.
if (request_route() === 'track') {
    $response = ['ok' => false];
    if (TRACK_VIEWS && view_is_counted() && !is_non_viewing_request()) {
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
            $newCount = stats_bump_photo($requestedFolder, $requested);   // (every time the viewer shows the photo: the same moment the visitor log counts as "opened")
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
$currentCollectionPage = null;   // (set when the address is a collection: see collection_page)
$currentFolderMeta = ['label' => '', 'cover' => null, 'description' => '', 'dates' => '', 'sortOrder' => null];
$currentFolderName = '';
$currentFolderFileCount = 0;
$folderCrumbs = [];
$folderPills = [];       // quick links to the folders beside this one: [href, label, is this one]
$folderPillsAll = null;  // where "All" goes when only some of them are shown
$navTop = '';            // how high this visitor may go (see nav_top): '' is all of it
$navBack = null;         // where the way back leads when it stops short of the gallery's front: [href, label]
if (!isset($_GET['folder'])) nav_top($dir, null);   // (the gallery's front page: someone who came in here is remembered as having come in above any folder)
if (isset($_GET['folder'])) {
    if ($photoPass !== null) { http_response_code(404); exit; }   // (a photo's own address is not a way into its folder)
    $requestedFolder = $_GET['folder'];
    // Valid only if the script's own (already hiding-filtered) file list
    // has something under it — a nested path like Europe/France/Paris
    // works the same as a top-level one, and a hidden, nonexistent, or
    // path-trick value (../, a trailing slash, a file) all simply have
    // nothing under them.
    $currentFolderFiles = AUTO_GROUP_BY_FOLDER ? folder_files_under($files, $requestedFolder) : [];
    // No folder is at that address: it may be a collection (a folder that exists only in the gallery). A collection's page is made from the members this
    // visitor may see; when there are none, or it is private to someone else, or there is no such collection, the answer is the same 404 as ever.
    if (empty($currentFolderFiles) && AUTO_GROUP_BY_FOLDER && is_string($requestedFolder) && collections_any()) {
        $currentCollectionPage = collection_page($dir, $requestedFolder, $allFiles, $files);
        if ($currentCollectionPage !== null) $currentFolderFiles = $currentCollectionPage['files'];
    }
    if (empty($currentFolderFiles)) {
        http_response_code(404);
        exit;
    }
    $currentFolderPath = $requestedFolder;
    // A folder can say how its own page shows the folders under it (its own choice, else the gallery's).
    $folderItemsOwn = $currentCollectionPage !== null ? $currentCollectionPage['rec'] : items_read(resolve_real_path($dir, $requestedFolder));   // (a collection keeps the same choices in its own record)
    $folderStyleOwn = $folderItemsOwn['subfolderStyle'] ?? null;
    if ($folderStyleOwn === 'flat' || $folderStyleOwn === 'overview') $GLOBALS['__folder_style_flat'] = $folderStyleOwn === 'flat';
    if (is_bool($folderItemsOwn['flatNames'] ?? null)) $GLOBALS['__flat_names'] = $folderItemsOwn['flatNames'];
    if (is_bool($folderItemsOwn['flatNotes'] ?? null)) $GLOBALS['__flat_notes'] = $folderItemsOwn['flatNotes'];
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
            $folderPills[] = ['?folder=' . rawurlencode($pillPrefix . $pillName), $pillLabel !== '' ? $pillLabel : $pillName, $pillName === $pillHere, owner_folder_privacy($dir, $files, $pillPrefix . $pillName, false)['state']];
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
        $folderCrumbs[] = ['?folder=' . rawurlencode($ancestor), $ancestorMeta['label'] !== '' ? $ancestorMeta['label'] : $segment, $ancestor];
    }
    // Someone who arrived inside a folder marked "Gallery of its own" is offered nothing above it, nor beside it: the way back stops at that folder
    // (the page is simply built without them, so their names are not in it).
    $navTop = nav_top($dir, $currentFolderPath);
    if ($navTop !== '') {
        $folderCrumbs = array_values(array_filter($folderCrumbs, function ($c) use ($navTop) { return strncmp($c[2], $navTop . '/', strlen($navTop) + 1) === 0; }));
        if ($currentFolderPath !== $navTop) { $topMeta = folder_display_meta($dir, $navTop); $navBack = ['?folder=' . rawurlencode($navTop), $topMeta['label'] !== '' ? $topMeta['label'] : basename($navTop)]; }
        else { $folderPills = []; $folderPillsAll = null; }
        if ($currentFolderPath !== $navTop && strncmp($currentFolderPath, $navTop . '/', strlen($navTop) + 1) !== 0) { $folderPills = []; $folderPillsAll = null; }   // (the top is a collection and this is a folder of its own elsewhere: the folders beside it on disk are not offered)
    }
}

// ?archive: the originals of the gallery or of this folder as one ZIP (signed-in visitors only).
if (request_route() === 'archive') {
    if ($currentCollectionPage !== null) { http_response_code(404); exit; }   // (a collection is not a folder on disk: there is nothing of it to zip)
    run_archive_route($dir, $currentFolderPath === null ? $files : $currentFolderFiles, $currentFolderPath, $rootPageTitle, $currentFolderName);
    exit;
}
// ...and the button for it, with what it would send, for a signed-in visitor.
$archiveCount = 0; $archiveBytes = 0;
if ($currentCollectionPage === null && ($archiveButtonFiles = archive_files_for_viewer($dir, $currentFolderPath === null ? $files : $currentFolderFiles))) {
    foreach (archive_entries($dir, $archiveButtonFiles) as $archiveEntry) { $archiveCount++; $archiveBytes += $archiveEntry['size']; }
}

