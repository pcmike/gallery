/* -----------------------------------------------------------------------
 * Moved in from another gallery. A folder shown here that another gallery showed before (the old copy being retired) holds two things
 * that gallery left behind and this one cannot reuse:
 *   - its thumbnails and video pictures, named with that gallery's own key, which sit in the folder's .gallery-cache for ever when the
 *     folder is a place from elsewhere on the server (the clean-up skips those, see gc_image_cache; a folder inside the gallery's own
 *     folder is swept clean by itself); and
 *   - its view counts, kept in the folder's own .gallery-stats.sqlite under the path as that gallery saw the photo.
 * Prepare media offers both: add the old counts to this gallery's own (once per folder, old rows never changed), and remove the
 * leftover thumbnails (only after the owner says no other gallery still shows the folders). Nothing here ever touches a photo, a
 * caption, a flag, or the files that hold what was read from the photos.
 * ----------------------------------------------------------------------- */

/** The folders of $files (paths relative to $dir) as ['real' => real path, 'rel' => the folder as the gallery names it, 'files' => [...]]. */
function moved_in_folders($dir, $files) {
    $by = [];
    foreach ($files as $file) {
        $real = containing_folder_real_path($dir, $file);
        if (!isset($by[$real])) $by[$real] = ['real' => $real, 'rel' => ($slash = strrpos($file, '/')) === false ? '' : substr($file, 0, $slash), 'files' => []];
        $by[$real]['files'][] = $file;
    }
    return array_values($by);
}

/** The picture and clip files in places from elsewhere on the server that this gallery would not make or read now, named like a made
 *  picture (32 hex digits and .jpg or .mp4). [['folder' => as the gallery names it, 'real', 'names' => [...], 'count', 'bytes'], ...] and totals. */
function moved_in_leftovers($dir, $files) {
    $ownRoot = rtrim((string) (realpath($dir) ?: $dir), '/') . '/';
    $imagick = !empty($GLOBALS['imagickAvailable']);
    $folders = []; $count = 0; $bytes = 0;
    foreach (moved_in_folders($dir, $files) as $f) {
        $cacheDir = $f['real'] . '/.gallery-cache';
        if (!is_dir($cacheDir) || is_link($cacheDir)) continue;
        if (strpos(rtrim((string) (realpath($f['real']) ?: $f['real']), '/') . '/', $ownRoot) === 0) continue;   // (a folder inside the gallery's own folder is tidied by the clean-up itself)
        [$valid, $renamed] = image_cache_expected_names($dir, $f['files'], $imagick);
        $names = []; $b = 0;
        foreach (scandir($cacheDir) ?: [] as $entry) {
            if (!preg_match('/^[0-9a-f]{32}\.(jpg|mp4)$/', $entry) || isset($valid[$entry]) || isset($renamed[$entry])) continue;
            $path = $cacheDir . '/' . $entry;
            if (!is_file($path) || is_link($path)) continue;
            $names[] = $entry; $b += (int) @filesize($path);
        }
        if (!$names) continue;
        $folders[] = ['folder' => $f['rel'] === '' ? '(top level)' : $f['rel'], 'real' => $f['real'], 'names' => $names, 'count' => count($names), 'bytes' => $b];
        $count += count($names); $bytes += $b;
    }
    return ['count' => $count, 'bytes' => $bytes, 'folders' => $folders];
}

/** What a Left behind row of the Health check is about, as [how many, a fingerprint of exactly those things] (null for a row that is not one of these). "Set aside" keeps the fingerprint:
 *  the row stays quiet only while it is about the very same things, so anything new (or anything gone) brings it back, and a count that happens to match does not hide different things. */
function health_aside_state($key, $dir, $files) {
    if ($key === 'earlier-views') { $ev = moved_in_earlier_views($dir, $files); return [(int) $ev['views'], md5(json_encode($ev['rows']))]; }
    if ($key === 'leftover-thumbs') {
        $lo = moved_in_leftovers($dir, $files); $all = [];
        foreach ($lo['folders'] as $f) foreach ($f['names'] as $nm) $all[] = $f['real'] . '/' . $nm;
        sort($all); return [(int) $lo['count'], md5(implode("\n", $all))];
    }
    if ($key === 'loose-photos') { $l = EXTRA_ROOTS ? loose_media_in_roots() : []; sort($l); return [count($l), md5(implode("\n", $l))]; }
    return null;
}

/** Removes those leftovers (the list is worked out again here, never taken from the request). Returns how many files and bytes went. */
function moved_in_remove_leftovers($dir, $files) {
    $n = 0; $bytes = 0;
    foreach (moved_in_leftovers($dir, $files)['folders'] as $f) {
        foreach ($f['names'] as $name) {
            $path = $f['real'] . '/.gallery-cache/' . $name;
            if (!preg_match('/^[0-9a-f]{32}\.(jpg|mp4)$/', $name) || !is_file($path) || is_link($path)) continue;
            $size = (int) @filesize($path);
            if (@unlink($path)) { $n++; $bytes += $size; }
        }
    }
    return ['count' => $n, 'bytes' => $bytes];
}

/** The shorter paths an older gallery may have saved a photo's views under: $file without its leading folders, one at a time, down to the bare name
 *  (a gallery showing "Eurotrip/paris/a.jpg" here may have shown "paris/a.jpg", or, with the folder as its top, "a.jpg"). */
function moved_in_old_paths($file) {
    $parts = explode('/', $file); $out = [];
    for ($i = 1; $i < count($parts); $i++) $out[] = implode('/', array_slice($parts, $i));
    return $out;
}

/** The marker kept in a folder's stats file once its earlier views were added, so that pressing the button again adds nothing. */
function moved_in_marker_key($folderRel) {
    return 'carried:' . stats_key((string) $folderRel);
}

/** Views other galleries counted for the photos shown here, found in each folder's own stats file under an older path, and not added yet.
 *  Read-only. ['views' => total waiting, 'folders' => number with some, 'done' => folders already added, 'rows' => [folder => ['found' => n, 'added' => bool]]]. */
function moved_in_earlier_views($dir, $files, $apply = false) {
    $out = ['views' => 0, 'folders' => 0, 'done' => 0, 'rows' => [], 'added' => 0, 'addedFolders' => 0];
    $drivers = class_exists('PDO') ? PDO::getAvailableDrivers() : [];
    if (!in_array('sqlite', $drivers, true)) return $out;   // (no SQLite: the older flat-file counts are not read)
    foreach (moved_in_folders($dir, $files) as $f) {
        $db = $f['real'] . '/.gallery-stats.sqlite';
        if (!is_file($db) || is_link($db)) continue;
        try {
            $pdo = new PDO('sqlite:' . $db);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $has = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='photo_views_h'")->fetchColumn();
            if (!$has) continue;
            $rowsByKey = [];
            foreach ($pdo->query('SELECT key, views FROM photo_views_h') as $r) $rowsByKey[$r['key']] = (int) $r['views'];
            $marker = moved_in_marker_key($f['rel']);
            $hasStats = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='stats'")->fetchColumn();
            $done = false;
            if ($hasStats) { $m = $pdo->prepare('SELECT value FROM stats WHERE key = :k'); $m->execute([':k' => $marker]); $done = $m->fetchColumn() !== false; }
            $found = 0; $adds = [];
            foreach ($f['files'] as $file) {
                $own = stats_key($file); $sum = 0; $seen = [];
                foreach (moved_in_old_paths($file) as $old) {
                    $k = stats_key($old);
                    if ($k === $own || isset($seen[$k]) || !isset($rowsByKey[$k])) continue;
                    $seen[$k] = true; $sum += $rowsByKey[$k];
                }
                if ($sum > 0) { $found += $sum; $adds[$own] = $sum; }
            }
            if ($found === 0 && !$done) continue;
            $out['rows'][$f['rel']] = ['found' => $done ? 0 : $found, 'added' => $done];
            if ($done) { $out['done']++; continue; }
            $out['views'] += $found; $out['folders']++;
            if ($apply && $adds) {
                $pdo->exec('PRAGMA busy_timeout = 5000');
                $pdo->exec('CREATE TABLE IF NOT EXISTS stats (key TEXT PRIMARY KEY, value INTEGER NOT NULL DEFAULT 0)');
                $pdo->beginTransaction();
                $up = $pdo->prepare('INSERT INTO photo_views_h(key, views) VALUES(:k, :v) ON CONFLICT(key) DO UPDATE SET views = views + :v');
                foreach ($adds as $key => $v) $up->execute([':k' => $key, ':v' => $v]);
                $pdo->prepare('INSERT OR REPLACE INTO stats(key, value) VALUES(:k, 1)')->execute([':k' => $marker]);
                $pdo->commit();
                $out['added'] += $found; $out['addedFolders']++;
            }
        } catch (Exception $e) {
            continue;
        }
    }
    return $out;
}
