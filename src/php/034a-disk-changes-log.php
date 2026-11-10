// ---------------------------------------------------------------------------------------------------------------------------------------------------------------
// The log of disk activity (the Changes tab): what the gallery noticed change in its folders on its own (a sync, an upload, a rename by hand). It lives in the visitor log's
// SQLite file (two more tables), is written by a cheap check that every admin page and (at most once a minute) every visitor's page makes, and keeps no process of its own:
// there is nothing running in the background, the check is the only thing that looks.
//
// How the check works: the page already has the list of every media file. It is grouped by folder, each folder's names are sorted and fingerprinted (an md5 of the names), and
// that is compared with the fingerprints kept from the last time. Everywhere equal: nothing is written. A folder that differs is looked at closely (which names are new, which are
// gone, when the new files arrived by their inode change time) and one entry is written for it; its snapshot is replaced. The first ever check only takes the snapshot (no flood of
// "added"), and so does the first one after something that changes what is scanned (the places from elsewhere, the subfolders and videos switches) was altered.
// ---------------------------------------------------------------------------------------------------------------------------------------------------------------

/** The names an entry keeps (the rest is only counted). (A function, not a constant: this part is read after the code that calls the check.) */
function changes_names_per_entry() { return 200; }
/** Seconds between two checks made by a visitor's request (the owner's own pages check every time). */
function changes_visitor_every() { return 60; }

/** The tables (made with the rest of the log's, and on a log made before them). */
function changes_schema($pdo) {
    $pdo->exec('CREATE TABLE IF NOT EXISTS changes (id INTEGER PRIMARY KEY AUTOINCREMENT, t INTEGER NOT NULL, t0 INTEGER NOT NULL, t1 INTEGER NOT NULL, kind TEXT NOT NULL, folder TEXT NOT NULL, n INTEGER NOT NULL DEFAULT 0, names TEXT NOT NULL DEFAULT \'[]\', more INTEGER NOT NULL DEFAULT 0, gone TEXT NOT NULL DEFAULT \'[]\')');
    $pdo->exec('CREATE INDEX IF NOT EXISTS changes_t ON changes (t)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS changes_snap (folder TEXT PRIMARY KEY, n INTEGER NOT NULL, fp TEXT NOT NULL, names TEXT NOT NULL) WITHOUT ROWID');
}

/** What decides which files are scanned at all: when it changes the snapshot is taken again quietly (files appearing because a place was added are not "added"). */
function changes_scan_signature() {
    return md5(json_encode([ENABLE_VIDEO, AUTO_GROUP_BY_FOLDER, EXTRA_FOLDERS, EXTRA_ROOTS]));
}

/** The media files grouped by the folder they are directly in ('' is the front page): folder => sorted bare names. */
function changes_by_folder($files) {
    $by = [];
    foreach ($files as $f) $by[link_item_folder($f)][] = basename($f);
    foreach ($by as $d => $names) { sort($names, SORT_STRING); $by[$d] = $names; }
    return $by;
}

/** The names that are in $new and not in $old (in $new's order), in linear time. */
function changes_names_not_in($new, $old) {
    $have = array_flip($old);
    $out = [];
    foreach ($new as $n) if (!isset($have[$n])) $out[] = $n;
    return $out;
}

/** One entry's names: the first changes_names_per_entry() as JSON, and how many more there were. */
function changes_pack($names) {
    return [json_encode(array_slice($names, 0, changes_names_per_entry()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), max(0, count($names) - changes_names_per_entry())];
}

/** The earliest and latest time (their inode change time: copied in, renamed, synced) of the files named in a folder, looking at no more than 500 of them. */
function changes_arrival($dir, $folder, $names, $fallback) {
    $lo = 0; $hi = 0;
    foreach (array_slice($names, 0, 500) as $n) {
        $real = resolve_real_path($dir, $folder === '' ? $n : $folder . '/' . $n);
        $c = is_string($real) ? (int) @filectime($real) : 0;
        if ($c <= 0) continue;
        $lo = $lo === 0 ? $c : min($lo, $c); $hi = max($hi, $c);
    }
    return $lo === 0 ? [$fallback, $fallback] : [$lo, $hi];
}

/** Looks at the list of files against what was seen last time and writes what differs. $files is the COMPLETE list (never the one a visitor's page filters), $throttle makes it
 *  skip when it looked less than changes_visitor_every() seconds ago (a visitor's page). Quiet and safe: no log, no write access or any trouble means it does nothing. Once per request. */
function changes_check($dir, $files, $throttle) {
    static $ran = false;
    if ($ran) return;
    $ran = true;
    if (!visitlog_driver_ok() || !visitlog_ready()) return;
    try {
        $db = visitlog_db();
        if ($db === null) return;
        $now = time();
        if ($throttle && $now - (int) visitlog_meta_get($db, 'chg_checked') < changes_visitor_every()) return;
        $by = changes_by_folder($files);
        $cur = [];
        foreach ($by as $d => $names) $cur[(string) $d] = md5(implode("\n", $names));
        $sig = changes_scan_signature();
        // the first look, and the first after what is scanned changed: the picture is taken, nothing is said
        if (visitlog_meta_get($db, 'chg_base') !== '1' || visitlog_meta_get($db, 'chg_sig') !== $sig) {
            visitlog_tx(function ($db) use ($by, $cur, $sig, $now) {
                $db->exec('DELETE FROM changes_snap');
                $ins = $db->prepare('INSERT INTO changes_snap (folder, n, fp, names) VALUES (?, ?, ?, ?)');
                foreach ($by as $d => $names) $ins->execute([(string) $d, count($names), $cur[(string) $d], json_encode($names, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
                visitlog_meta_set($db, 'chg_base', '1'); visitlog_meta_set($db, 'chg_sig', $sig); visitlog_meta_set($db, 'chg_checked', $now); visitlog_meta_set($db, 'chg_wait', '');
            });
            return;
        }
        $snap = [];
        foreach ($db->query('SELECT folder, n, fp FROM changes_snap') as $r) $snap[(string) $r['folder']] = [(int) $r['n'], (string) $r['fp']];
        // a drive that went away for a moment must not look like everything was deleted (and then added again): when nearly everything is gone it takes a second look to believe it
        $was = array_sum(array_column($snap, 0)); $isNow = count($files);
        if ($was >= 20 && $isNow < $was * 0.1) {
            $key = md5(json_encode($cur));
            if (visitlog_meta_get($db, 'chg_wait') !== $key) { visitlog_tx(function ($db) use ($key) { visitlog_meta_set($db, 'chg_wait', $key); }); return; }
        }
        $changed = [];
        foreach ($cur as $d => $fp) if (!isset($snap[$d]) || $snap[$d][1] !== $fp) $changed[] = $d;
        $goneFolders = array_keys(array_diff_key($snap, $cur));
        if (!$changed && !$goneFolders) {
            if ($throttle) visitlog_tx(function ($db) use ($now) { visitlog_meta_set($db, 'chg_checked', $now); });
            changes_retention($now);
            return;
        }
        $oldNames = function ($d) use ($db) { $st = $db->prepare('SELECT names FROM changes_snap WHERE folder = ?'); $st->execute([$d]); $j = $st->fetchColumn(); $a = is_string($j) ? json_decode($j, true) : null; return is_array($a) ? $a : []; };
        $entries = [];
        foreach ($changed as $d) {
            $new = $by[$d];
            if (!isset($snap[$d])) {
                [$j, $more] = changes_pack($new);
                [$t0, $t1] = changes_arrival($dir, $d, $new, $now);
                $entries[] = [$now, $t0, $t1, 'new', $d, count($new), $j, $more, '[]'];
                continue;
            }
            $old = $oldNames($d);
            $added = changes_names_not_in($new, $old); $removed = changes_names_not_in($old, $new);
            if ($added && $removed && count($added) === count($removed)) {
                [$j, $more] = changes_pack($added); [$g] = changes_pack($removed);
                [$t0, $t1] = changes_arrival($dir, $d, $added, $now);
                $entries[] = [$now, $t0, $t1, 'ren', $d, count($added), $j, $more, $g];
                continue;
            }
            if ($added) { [$j, $more] = changes_pack($added); [$t0, $t1] = changes_arrival($dir, $d, $added, $now); $entries[] = [$now, $t0, $t1, 'add', $d, count($added), $j, $more, '[]']; }
            if ($removed) { [$j, $more] = changes_pack($removed); $entries[] = [$now, $now, $now, 'rem', $d, count($removed), $j, $more, '[]']; }
        }
        foreach ($goneFolders as $d) {
            $old = $oldNames($d); [$j, $more] = changes_pack($old);
            $entries[] = [$now, $now, $now, 'gone', $d, count($old), $j, $more, '[]'];
        }
        visitlog_tx(function ($db) use ($entries, $changed, $goneFolders, $by, $cur, $now) {
            $ins = $db->prepare('INSERT INTO changes (t, t0, t1, kind, folder, n, names, more, gone) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($entries as $e) $ins->execute($e);
            $up = $db->prepare('INSERT OR REPLACE INTO changes_snap (folder, n, fp, names) VALUES (?, ?, ?, ?)');
            foreach ($changed as $d) $up->execute([$d, count($by[$d]), $cur[$d], json_encode($by[$d], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            $del = $db->prepare('DELETE FROM changes_snap WHERE folder = ?');
            foreach ($goneFolders as $d) $del->execute([$d]);
            visitlog_meta_set($db, 'chg_checked', $now); visitlog_meta_set($db, 'chg_wait', '');
        });
        changes_retention($now);
    } catch (Throwable $e) {
        error_log('gallery: the disk-activity check did not run: ' . $e->getMessage());
    }
}

/** Does this server keep the log of disk activity (and so show the Changes tab)? It lives in the visitor log's SQLite file. */
function changes_tab_wanted() {
    return visitlog_driver_ok() && visitlog_ready();
}

/** Runs $fn($db) against the log, or returns $none when there is none (no SQLite, nothing written yet, any trouble): the readers below never make the file. */
function changes_read(callable $fn, $none = null) {
    if (!visitlog_driver_ok() || !is_file(visitlog_path()) || ($db = visitlog_db()) === null) return $none;
    try { return $fn($db); } catch (Throwable $e) { return $none; }
}

/** How many entries the owner has not seen: those newer than the newest one there was when the Changes tab was last opened. */
function changes_unseen_count() {
    return (int) changes_read(function ($db) {
        $st = $db->prepare('SELECT COUNT(*) FROM changes WHERE id > ?'); $st->execute([(int) visitlog_meta_get($db, 'chg_seen')]);
        return $st->fetchColumn();
    }, 0);
}

/** The Changes tab was opened: everything in the log counts as seen, and when and how many were new then is kept (the number goes grey, and is gone a day later). */
function changes_mark_seen() {
    visitlog_tx(function ($db) {
        $new = (int) $db->query('SELECT COUNT(*) FROM changes WHERE id > ' . (int) visitlog_meta_get($db, 'chg_seen'))->fetchColumn();
        $top = (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM changes')->fetchColumn();
        visitlog_meta_set($db, 'chg_seen', $top);
        visitlog_meta_set($db, 'chg_opened', time());
        visitlog_meta_set($db, 'chg_open_n', $new);
    });
}

/** The number on the Changes entry of the sidebar and the phone Menu: amber while there is something new; grey with how many were new when the tab was opened, for a day after;
 *  then nothing. In the shape admin_nav_badges() draws. */
function changes_badges() {
    if (!changes_tab_wanted()) return [];
    $un = changes_unseen_count();
    if ($un > 0) return [['text' => (string) $un, 'kind' => 'warn', 'title' => number_format($un) . ' change' . ($un === 1 ? '' : 's') . ' on disk you have not looked at']];
    [$at, $n] = changes_read(function ($db) { return [(int) visitlog_meta_get($db, 'chg_opened'), (int) visitlog_meta_get($db, 'chg_open_n')]; }, [0, 0]);
    if ($n > 0 && $at > 0 && time() - $at < 86400) return [['text' => (string) $n, 'kind' => '', 'title' => number_format($n) . ' change' . ($n === 1 ? '' : 's') . ' were new when you last opened the tab']];
    return [];
}

/** The newest entries first, as rows. */
function changes_list($limit = 500) {
    return changes_read(function ($db) use ($limit) { return $db->query('SELECT id, t, t0, t1, kind, folder, n, names, more, gone FROM changes ORDER BY id DESC LIMIT ' . (int) $limit)->fetchAll(); }, []);
}

/** Entries older than $cutoff: how many there are, or (apply) removed. */
function changes_trim($cutoff, $apply) {
    $r = visitlog_tx(function ($db) use ($cutoff, $apply) {
        $st = $db->prepare('SELECT COUNT(*) FROM changes WHERE t < ?'); $st->execute([(int) $cutoff]); $n = (int) $st->fetchColumn();
        if ($apply && $n > 0) { $d = $db->prepare('DELETE FROM changes WHERE t < ?'); $d->execute([(int) $cutoff]); }
        return $n;
    });
    return is_int($r) ? $r : 0;
}

/** Once a day, entries older than the days Settings says go (0 keeps them until they are trimmed by hand). Assumes the caller holds no transaction. */
function changes_retention($now) {
    if (CHANGES_KEEP_DAYS <= 0) return;
    $last = changes_read(function ($db) { return (int) visitlog_meta_get($db, 'chg_trimmed'); }, $now);
    if ($now - $last <= 86400) return;
    changes_trim($now - CHANGES_KEEP_DAYS * 86400, true);
    visitlog_tx(function ($db) use ($now) { visitlog_meta_set($db, 'chg_trimmed', $now); });
}

/** What the entries take in the log, in bytes (an estimate from what is stored: the names, the folders and a little for each row; not the whole file). */
function changes_size() {
    return (int) changes_read(function ($db) { return $db->query('SELECT COALESCE(SUM(LENGTH(names) + LENGTH(gone) + LENGTH(folder) + 48), 0) FROM changes')->fetchColumn(); }, 0);
}
function changes_oldest() { return (int) changes_read(function ($db) { return $db->query('SELECT COALESCE(MIN(t), 0) FROM changes')->fetchColumn(); }, 0); }
function changes_count() { return (int) changes_read(function ($db) { return $db->query('SELECT COUNT(*) FROM changes')->fetchColumn(); }, 0); }

/** The newest entry's number (0 when there is none): what a page remembers, to ask later for what came after. */
function changes_last_id() { return (int) changes_read(function ($db) { return $db->query('SELECT COALESCE(MAX(id), 0) FROM changes')->fetchColumn(); }, 0); }

/** Entries newer than $after, in or under $folder (null: anywhere), oldest first, no more than 200. */
function changes_after($after, $folder) {
    return changes_read(function ($db) use ($after, $folder) {
        if ($folder === null || $folder === '') { $st = $db->prepare('SELECT id, t, t0, kind, folder, n FROM changes WHERE id > ? ORDER BY id LIMIT 200'); $st->execute([(int) $after]); }
        else {
            $st = $db->prepare('SELECT id, t, t0, kind, folder, n FROM changes WHERE id > ? AND (folder = ? OR folder LIKE ? ESCAPE \'\\\') ORDER BY id LIMIT 200');
            $st->execute([(int) $after, $folder, addcslashes($folder, '%_\\') . '/%']);
        }
        return $st->fetchAll();
    }, []);
}

/** What the owner has not seen, for each folder and every folder above it: path => counts of media added (add, ren), removed (rem), folders that came (new) and went (gone),
 *  and the earliest arrival (t0), for the amber text on a folder's card. Worked out once per request. */
function changes_unseen_map() {
    static $map = null;
    if ($map !== null) return $map;
    $map = [];
    $rows = changes_read(function ($db) { $st = $db->prepare('SELECT kind, folder, n, t0 FROM changes WHERE id > ?'); $st->execute([(int) visitlog_meta_get($db, 'chg_seen')]); return $st->fetchAll(); }, []);
    foreach ($rows as $r) {
        $path = (string) $r['folder'];
        while ($path !== '') {
            $m = &$map[$path];
            if ($m === null) $m = ['add' => 0, 'rem' => 0, 'new' => 0, 'gone' => 0, 'ren' => 0, 't0' => (int) $r['t0']];
            // a folder that came adds its media to the folder itself and, with itself counted as one folder, to every folder above it; one that went takes its media and itself from those above
            $own = $path === (string) $r['folder'];
            if ($r['kind'] === 'new') { $m['add'] += (int) $r['n']; if (!$own) $m['new']++; }
            elseif ($r['kind'] === 'gone') { if (!$own) { $m['rem'] += (int) $r['n']; $m['gone']++; } }
            else $m[$r['kind']] += (int) $r['n'];
            $m['t0'] = min($m['t0'], (int) $r['t0']);
            unset($m);
            $path = link_item_folder($path);
        }
    }
    return $map;
}

/** The files under $folder (null: the front page) that arrived since $since (their inode changed: copied, renamed, synced in), no more than 200, for a page to mark as new. */
function changes_fresh_files($dir, $files, $folder, $since) {
    $under = $folder === null || $folder === '' ? $files : folder_files_under($files, $folder);
    sort($under);
    $fresh = [];
    foreach ($under as $f) {
        $path = resolve_real_path($dir, $f);
        if (is_string($path) && @filectime($path) >= $since) { $fresh[] = $f; if (count($fresh) >= 200) break; }
    }
    return $fresh;
}

/** The folders that came since $since, in or under $folder: their paths, for a page to mark their cards. */
function changes_new_folders($folder, $since) {
    return changes_read(function ($db) use ($folder, $since) {
        $st = $db->prepare('SELECT folder FROM changes WHERE kind = \'new\' AND t0 >= ?'); $st->execute([(int) $since]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $p) if ($folder === null || $folder === '' || $p === $folder || strncmp($p, $folder . '/', strlen($folder) + 1) === 0) $out[] = $p;
        return array_slice($out, 0, 200);
    }, []);
}

/** The amber text on a folder's card for the owner: what in it (and below it) the Changes tab has not been opened for, "(+1 photo ›)", "(+2 photos, −1 ›)", "(+1 folder ›)",
 *  "(changed ›)" when it would take more than two kinds. A link to the folder that marks what is new. Empty when there is nothing (a rename changes no count: the pill and the tab say it). */
function changes_card_text($folderPath) {
    if (!is_admin_browser() || !changes_tab_wanted()) return '';
    $m = changes_unseen_map()[(string) $folderPath] ?? null;
    if ($m === null) return '';
    $parts = [];
    if ($m['add'] > 0) $parts[] = '+' . count_noun((int) $m['add']);
    if ($m['rem'] > 0) $parts[] = "\xE2\x88\x92" . number_format((int) $m['rem']);
    if ($m['new'] > 0) $parts[] = '+' . number_format((int) $m['new']) . ' folder' . ($m['new'] === 1 ? '' : 's');
    if ($m['gone'] > 0) $parts[] = "\xE2\x88\x92" . number_format((int) $m['gone']) . ' folder' . ($m['gone'] === 1 ? '' : 's');
    if (!$parts) return '';
    $text = count($parts) > 2 ? 'changed' : implode(', ', $parts);
    $href = '?folder=' . rawurlencode((string) $folderPath) . '&since=' . max(0, (int) $m['t0'] - 2);
    return '<a class="fc-chg" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" data-chg="' . htmlspecialchars(json_encode(['add' => (int) $m['add'], 'rem' => (int) $m['rem'], 'new' => (int) $m['new'], 'gone' => (int) $m['gone']]), ENT_QUOTES, 'UTF-8') . '" title="Open the folder with what is new marked">(' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . ui_icon('chev-right') . ')</a>';
}
