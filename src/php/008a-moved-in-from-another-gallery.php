/* -----------------------------------------------------------------------
 * Moved in from another gallery. A folder shown here that another gallery showed before (the old copy being retired) holds two things
 * that gallery left behind and this one cannot reuse: its thumbnails and video pictures, named with that gallery's own key, which sit in the
 * folder's .gallery-cache for ever when the folder is a place from elsewhere on the server (the clean-up skips those, see gc_image_cache; a
 * folder inside the gallery's own folder is swept clean by itself). Prepare media offers to remove them (only after the owner says no other
 * gallery still shows the folders). Nothing here ever touches a photo, a caption, a flag, or the files that hold what was read from the photos.
 * (Its view counts used to be a second thing to carry over by a button. They are kept under the folder's own key now and follow it by themselves.)
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

/* -----------------------------------------------------------------------
 * The plain capture-dates files. Every release up to 2.6.4 kept the dates used for the date order in .gallery-cache/taken3.json (and taken.json and taken2.json before it):
 * plain JSON that holds the name of every file in the folder. 2.6.5 keeps them in the guarded taken4.php. Looking at a folder removes the old file when the order is by
 * date, and the thumbnail clean-up when the cache is on, in the gallery's own folders; what those never reach (the cache off with the order by name, and a place from
 * elsewhere on the server, which is never swept) is named in Health, and removed by its button.
 * ----------------------------------------------------------------------- */

/** The names the capture dates were kept under before taken4.php (plain JSON, so each can be read by its address where the web server has no rule for .gallery- names). */
function old_dates_names() { return ['taken.json', 'taken2.json', 'taken3.json', 'taken4.json']; }

/** How many folders the look for the old capture-date files goes through at most. */
function old_dates_ceiling() { return max(10, SCAN_MAX_FOLDERS * 10); }

/** Every folder under $realDir (and itself) as ['real', 'rel'], not following a link and leaving out dot folders and the ones the scan skips, at most $budget of them. */
function old_dates_walk($realDir, $rel, array &$out, &$budget) {
    if ($budget <= 0 || microtime(true) > (float) ($GLOBALS['__old_dates_until'] ?? PHP_INT_MAX)) { $GLOBALS['__old_dates_cut'] = true; return; }   // (a folder is left unlooked at: the Health row says the look was cut short)
    $budget--;
    $out[] = ['real' => $realDir, 'rel' => $rel];
    foreach (@scandir($realDir) ?: [] as $e) {
        if ($e === '.' || $e === '..' || $e[0] === '.' || in_array($e, SCAN_SKIP_FOLDERS, true)) continue;
        $p = $realDir . '/' . $e;
        if (is_link($p) || !is_dir($p)) continue;
        old_dates_walk($p, $rel === '' ? $e : $rel . '/' . $e, $out, $budget);
    }
}

/** The old plain capture-dates files in the folders of $files [['path', 'folder' (as the gallery names it, '' for the main page), 'elsewhere' (a place from elsewhere on the server), 'bytes'], ...].
 *  Regular files only: a link by that name is never counted or followed. */
function old_dates_leftovers($dir, $files) {
    $ownRoot = rtrim((string) (realpath($dir) ?: $dir), '/') . '/';
    $GLOBALS['__old_dates_cut'] = false; $GLOBALS['__old_dates_until'] = microtime(true) + 2.5;   // (a look that takes longer than that stops there and says so: a very large tree)
    $found = [];
    // The folders that hold files that are shown, and also the folders that hold none now (emptied, or only things that are no pictures): the old file in one of those
    // is as readable by its address as any other. Folders are walked the way the scan walks them (never a link, never a dot folder), up to the scan's own ceiling.
    $folders = []; $seen = [];
    foreach (moved_in_folders($dir, $files) as $f) { $folders[] = $f; $seen[(string) (realpath($f['real']) ?: $f['real'])] = true; }
    $budget = old_dates_ceiling(); $walked = [];   // (ten times what the gallery shows on one visit: a gallery just over the limit is looked through whole, and only a tree far beyond it is cut short)
    old_dates_walk((string) (realpath($dir) ?: $dir), '', $walked, $budget);
    foreach (gallery_mounts() as $name => $real) {
        $name = (string) $name;
        if ($name === '' || strpbrk($name, "/\\") !== false || !is_dir($real) || is_link($real)) continue;
        old_dates_walk((string) (realpath($real) ?: $real), $name, $walked, $budget);
    }
    $GLOBALS['__old_dates_looked'] = count($walked);
    foreach ($walked as $w) {
        if (isset($seen[$w['real']])) continue;
        $seen[$w['real']] = true; $folders[] = ['real' => $w['real'], 'rel' => $w['rel'], 'files' => []];
    }
    foreach ($folders as $f) {
        $cacheDir = $f['real'] . '/.gallery-cache';
        if (!is_dir($cacheDir) || is_link($cacheDir)) continue;
        $elsewhere = strpos(rtrim((string) (realpath($f['real']) ?: $f['real']), '/') . '/', $ownRoot) !== 0;
        foreach (old_dates_names() as $name) {
            $path = $cacheDir . '/' . $name;
            if (is_file($path) && !is_link($path)) $found[] = ['path' => $path, 'folder' => $f['rel'], 'elsewhere' => $elsewhere, 'bytes' => (int) @filesize($path)];
        }
    }
    return $found;
}

/** Takes them away: the ones in the gallery's own folders, and those in places from elsewhere only when $alsoElsewhere (the owner said no other gallery needs them).
 *  The list is worked out here, now, never taken from the request. [removed, left]. */
function old_dates_remove($dir, $files, $alsoElsewhere) {
    $n = 0; $left = 0;
    foreach (old_dates_leftovers($dir, $files) as $e) {
        if ($e['elsewhere'] && !$alsoElsewhere) { $left++; continue; }
        if (@unlink($e['path'])) $n++; else $left++;
    }
    return [$n, $left];
}
