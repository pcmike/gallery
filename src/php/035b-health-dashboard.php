/* -----------------------------------------------------------------------
 * The Health check as a dashboard: the rows for the database, the backup, the share links and collections files, and the server. Each row is
 * added with $add($group, $status, $label, $detail, $link, $html). The statuses (see health_checks and the panel):
 *   bad / warn : something to fix now (counted in "N things to fix"; bad is red)
 *   opt        : a suggestion, something that works but has a worthwhile action (counted separately, "N suggestions")
 *   heads      : nothing to fix, worth a look (an amber "!"; not counted)
 *   info       : a fact          ok : checked and fine
 * ----------------------------------------------------------------------- */

/** "3 days ago", "today (5 hours ago)", "just now": no time zone to get wrong. */
function health_ago($t) {
    $d = max(0, time() - (int) $t);
    if ($d < 90) return 'just now';
    if ($d < 3600) return (int) round($d / 60) . ' minutes ago';
    if ($d < 86400) { $h = (int) round($d / 3600); return $h . ' hour' . ($h === 1 ? '' : 's') . ' ago'; }
    $n = (int) floor($d / 86400);
    return $n . ' day' . ($n === 1 ? '' : 's') . ' ago';
}
/** The same, short enough for a small box: "just now", "12m ago", "17h ago", "3d ago". */
function health_ago_short($t) {
    $d = max(0, time() - (int) $t);
    if ($d < 90) return 'just now';
    if ($d < 3600) return (int) round($d / 60) . 'm ago';
    if ($d < 86400) return (int) round($d / 3600) . 'h ago';
    return (int) floor($d / 86400) . 'd ago';
}
function health_bytes($b) { return $b >= 1073741824 ? round($b / 1073741824, 1) . ' GB' : ($b >= 1048576 ? round($b / 1048576, $b >= 10485760 ? 0 : 1) . ' MB' : max(1, (int) ceil($b / 1024)) . ' KB'); }

/** The folders the web server may not open, from the gallery's own folder (and every place from elsewhere it shows) down: [path from the gallery's folder]. The page leaves them out without a word; this is how the Health check can say so.
 *  The same ceilings as the page's own scan, and a link or a dot folder is never entered. */
function gallery_unreadable_folders($dir) {
    $roots = [rtrim((string) (realpath($dir) ?: $dir), '/') => '']; $self = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
    foreach (gallery_mounts() as $name => $real) if (is_string($real) && $real !== '') $roots[rtrim($real, '/')] = (string) $name;
    $bad = []; $seen = 0;
    foreach ($roots as $root => $label) {
        $todo = [[$root, $label]];
        while ($todo && $seen < SCAN_MAX_FOLDERS) {
            [$here, $rel] = array_pop($todo); $seen++;
            $entries = @scandir($here);
            if ($entries === false) { $bad[] = $rel === '' ? '(the gallery\'s own folder)' : $rel; continue; }
            foreach ($entries as $e) {
                if ($e === '.' || $e === '..' || $e[0] === '.' || ($here === $root && $label === '' && $e === $self) || in_array($e, SCAN_SKIP_FOLDERS, true)) continue;
                $p = $here . '/' . $e;
                if (is_link($p) || !is_dir($p) || !name_is_text($e)) continue;
                $todo[] = [$p, ($rel === '' ? '' : $rel . '/') . $e];
            }
        }
    }
    return $bad;
}

/** The checks that take a moment (each database looked through, every folder's data files listed), kept for an hour and looked at again by "Check again". */
function health_heavy($dir, $files, $force) {
    $p = __DIR__ . '/.gallery-health-cache.php';
    $c = is_file($p) ? json_file_read($p, []) : [];
    $live = visitlog_find();
    $zipNow = backup_find(); $zsig = $zipNow === null ? '' : basename($zipNow) . '|' . (int) @filesize($zipNow) . '|' . (int) @filemtime($zipNow);   // (a backup made, replaced or cut short since the last look: looked at again)
    $same = (($c['log'] ?? null) === null) === ($live === null) && ($c['file'] ?? '') === ($live === null ? '' : basename($live));   // (a log that came or went, or was swapped, since the last look: looked at again)
    if (!$force && $same && array_key_exists('log', $c) && isset($c['at'], $c['stats']) && time() - (int) $c['at'] < 3600 && ($c['v'] ?? '') === GALLERY_VERSION) {
        if (($c['zsig'] ?? null) !== $zsig) {   // (the backup file was made, replaced or changed since: only it is looked through again, nothing else)
            $c['zsig'] = $zsig; $c['zip'] = $zipNow === null ? null : backup_zip_verify($zipNow)[0];
            if (is_writable(__DIR__)) json_file_update($p, [], function (&$d) use ($c) { $d = $c; });
        }
        return $c;
    }
    $r = ['at' => time(), 'v' => GALLERY_VERSION, 'log' => null, 'file' => $live === null ? '' : basename($live), 'zsig' => $zsig, 'zip' => $zipNow === null ? null : backup_zip_verify($zipNow)[0], 'unreadable' => gallery_unreadable_folders($dir), 'stats' => ['n' => 0, 'bad' => [], 'ro' => [], 'nodriver' => [], 'key' => []]];
    if ($live !== null && visitlog_driver_ok()) $r['log'] = ['why' => backup_sqlite_check($live, 1000)];
    foreach (owned_plan($dir, $files)['entries'] as $e) {
        if ($e['kind'] !== 'stats') continue;
        if (substr($e['path'], -7) === '.sqlite') {
            if (!visitlog_driver_ok()) { $r['stats']['nodriver'][] = $e['rel']; continue; }   // (this PHP can't open it: the counts are in it, not lost)
            if (@filesize($e['path']) === 0) { $r['stats']['empty'][] = $e['rel']; continue; }   // (SQLite takes a file of no bytes for a new database, so the counts began again from nothing without a word)
            $w = backup_sqlite_check($e['path'], 1000);
            if (backup_sqlite_busy($w)) { $r['stats']['busy'] = ($r['stats']['busy'] ?? 0) + 1; continue; }   // (another process has the view counts open right now: it is over in a moment, nothing is wrong with the file, and it is not counted as looked through)
            $r['stats']['n']++;
            if ($w !== '') { $r['stats'][backup_sqlite_trouble($w) ? 'ro' : 'bad'][] = [$e['rel'], $w]; continue; }   // (a file SQLite can't open or write to for the folder's, the disk's or another owner's sake is not a damaged one)
            $w = stats_sqlite_write_check($e['path']);
            if ($w !== '') $r['stats']['ro'][] = [$e['rel'], $w];
            $w = stats_key_check($e['path']);
            if ($w !== '') $r['stats']['key'][] = [$e['rel'], $w];
        } elseif (basename($e['path']) === '.gallery-stats.php' && !data_file_is_link($e['path'])) {   // (the JSON store: a file that can't be read is left as it is, and nothing is counted there until it is mended)
            $raw = (string) @file_get_contents($e['path']);
            if (!data_file_is_empty($raw) && !is_array(json_decode(data_file_strip_guard($raw), true))) $r['stats']['bad'][] = [$e['rel'], 'could not be read as view counts'];
        }
    }
    if (is_writable(__DIR__)) json_file_update($p, [], function (&$d) use ($r) { $d = $r; });
    return $r;
}

/** Does the backup hold a visitor log? (so "Restore" can be offered) */
function backup_has_log() {
    $zip = backup_find(); if ($zip === null) return false;
    $ix = backup_zip_index($zip); if ($ix === null) return false;
    foreach ($ix as $n => $m) if (preg_match('~^\.gallery-visits-[0-9a-f]{24}\.sqlite$~D', $n)) return true;
    return false;
}

/** The buttons under "View counts can't be read": one per folder named in the row (the first three), each asking first and sending the folder's file name. */
function health_stats_afresh_buttons(array $statsKey) {
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $b = '<div class="health-act">';
    foreach (array_slice($statsKey, 0, 3) as $k) {
        $folder = dirname((string) $k[0]); $folder = $folder === '.' ? 'the gallery\'s top folder' : $folder;
        $b .= '<button type="button" class="secondary" data-backup-act="statsafresh" data-stats-rel="' . $esc($k[0]) . '" data-backup-ask="Start counting afresh in ' . $esc($folder) . '? Its view counts (.gallery-stats.sqlite and its two side files) are deleted for good, and counting starts again from 0. They can\'t be read without their key anyway. Nothing else in the folder is touched. If you can still find the folder\'s .gallery-stats-key.php, put that back instead.">Start counting afresh in ' . $esc($folder) . '</button> ';
    }
    return $b . '<span class="muted health-act-out" role="status" aria-live="polite"></span></div>';
}

function health_backup_buttons($withRestore, $withNew, $withDownload, $replaces = 'damaged') {   // ($replaces: what the log from the backup would replace: 'damaged' for a damaged log, 'now in use' for a log that is sound but was found empty)
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $b = '<div class="health-act">';
    if ($withRestore) { $bk = backup_status(); $b .= '<button type="button" class="secondary" data-backup-act="restore" data-backup-ask="Put the log from the backup (made ' . $esc(health_ago($bk['at'])) . ') in place of ' . ($replaces === 'damaged' ? 'the damaged one? The damaged one' : 'the log now in use? The one now in use') . ' is kept, renamed with .damaged- and the date.">Restore the log from the backup</button> '; }
    if ($withNew) $b .= '<button type="button" class="secondary" data-backup-act="newlog" data-backup-ask="Set the visitor log aside and start a new, empty one? The old file is kept, renamed with .damaged- and the date, but the Visitors and Changes tabs start from nothing.">Set it aside and start a new log</button> ';
    if ($withDownload) $b .= '<button type="button" class="secondary" data-backup-act="run">Back up now</button> <button type="button" class="secondary" data-backup-act="download">Download</button> ';
    return $b . '<span class="muted health-act-out" role="status" aria-live="polite"></span></div>';
}

/** The database (visitor log, change log, lockouts: one SQLite file), the view counts' databases and the backup. */
function health_database_rows($add, $dir, $files) {
    $G = 'Database and backup';
    $heavy = health_heavy($dir, $files, !empty($_POST['recheck']));
    $uses = 'The Visitors tab, the record of who opened each share link, the lockout history and the Changes tab are kept in it. Share links themselves keep working without it.';
    [$vlOk, $vlWhy] = visitlog_check_quiet();
    $bk = backup_status();
    $logFile = visitlog_find();
    $logWhy = $heavy['log']['why'] ?? '';
    if ($logFile !== null && $logWhy !== '' && (stripos($logWhy, 'unable to open') !== false || backup_sqlite_trouble($logWhy) || backup_sqlite_busy($logWhy)) && visitlog_driver_ok()) $logWhy = backup_sqlite_check($logFile, 1000);   // (a look kept from when the file could not be opened is not believed: the owner may have mended it since)
    $logCantOpen = $logWhy !== '' && stripos($logWhy, 'unable to open') !== false;
    $logBusy = backup_sqlite_busy($logWhy) || ($logFile !== null && !$vlOk && stripos((string) $vlWhy, 'is locked') !== false);   // (another process holds the log: stuck, or a network share whose locks misbehave)
    $logSaid = preg_match('~\((.{1,200})\)$~s', $logWhy, $sm) ? $sm[1] : (preg_match('~\((.{1,200})\)~s', (string) $vlWhy, $sm2) ? $sm2[1] : '');   // (what SQLite itself said)
    $logTrouble = backup_sqlite_trouble($logWhy);   // (SQLite can't open or write it: another owner's files, a folder the web server can't write in, or no room left; the file itself may be sound)   // (the file or its side files can't be opened: another owner's, not damaged)
    $logLocked = function ($canRead) use ($add, $G, $logFile, $uses, $logSaid) {
        $add($G, 'bad', 'The visitor and change log can\'t be ' . ($canRead ? 'written' : 'read or written'), 'The file ' . basename($logFile) . ' is there, but the web server may not ' . ($canRead ? 'write to it (or to the small files SQLite keeps beside it)' : 'read or write it (or the small files SQLite keeps beside it)') . ' (a restore, or a copy made as root, leaves files with another owner; a gallery folder the web server can\'t write in, or a disk with no room left, stops it the same way). Nothing is being logged.' . ($logSaid !== '' ? ' SQLite said: "' . $logSaid . '".' : '') . ' And because the Changes tab can\'t record it first, the caption, location and web link typed for a deleted or renamed photo are kept in the folder, not removed, until this is fixed. This does not mean the log is damaged. ' . $uses, null, '', [
            'Find the web server\'s user (usually `www-data` on Debian or Ubuntu; `nginx`, `apache` or `http` elsewhere: `ps aux | grep -E "php-fpm|apache|nginx"` shows it).',
            ['Give it the log files, for example:', 'sudo chown www-data: /path/to/the/gallery/.gallery-visits-*'],
            'If the files already belong to the web server\'s user and only their permissions were changed, this puts them right: `sudo chmod 600 /path/to/the/gallery/.gallery-visits-*`.',
            'If the files are the web server\'s own, look at the gallery\'s folder (the Folders and files group says if the web server can\'t write in it: SQLite keeps two small files there beside the log) and at the room on the disk (the Server group).',
            'Then press Check again at the top of this page.']);
    };
    if ($logFile !== null && visitlog_driver_ok() && $logBusy && @is_readable($logFile)) {   // (the file is sound and the owner is not the trouble: another process has it)
        $add($G, 'warn', 'The visitor log is in use by another process', 'The file ' . basename($logFile) . ' is there, but another process has it open and lets nothing in' . ($logSaid !== '' ? ' (SQLite said: "' . $logSaid . '")' : '') . ', so for now nothing can be logged. ' . $uses . ' This is usually over in a moment; the file is not damaged. If it stays, look for a stuck PHP process (restarting PHP lets go of it), or, when the gallery is on a network share, know that file locking on some shares does not work for SQLite.', null, '', [
            'Look for a PHP process that is stuck: `ps aux | grep -E "php-fpm|apache"` shows them. Restarting PHP (or the web server) lets go of what it holds.',
            'If the gallery\'s folder is on a network share (NFS, SMB), SQLite\'s locks may not work there: keep the gallery on a local disk.',
            'Then press Check again at the top of this page.']);
    } elseif ($logFile !== null && visitlog_driver_ok() && (!@is_readable($logFile) || $logCantOpen || $logTrouble)) {   // (a file the server can't even open can't be looked through: it is not "damaged", it has another owner)
        $logLocked(@is_readable($logFile) && !$logCantOpen);
    } elseif ($logFile !== null && $logWhy !== '') {   // (a damaged file is looked at first: it also makes the check below fail, and "can't be written" would be the wrong thing to say)
        $add($G, 'bad', 'The visitor log is damaged', 'The file ' . $logWhy . '. ' . $uses . ' Only that history is at risk: your photos, share links, settings and captions are in other files. ' . ($bk['have'] && backup_has_log() ? 'The backup from ' . health_ago($bk['at']) . ' has a sound copy.' : 'There is no sound copy in a backup.'),
            null, health_backup_buttons($bk['have'] && backup_has_log(), true, false));
    } elseif (!$vlOk && ($GLOBALS['__visitlog_error'] ?? '') === 'newer') {
        $add($G, 'warn', 'The visitor log is from a newer version', $vlWhy . ' ' . $uses . ' The file is not damaged: the older Visitors history is safe in it.', null, health_backup_buttons(false, true, false));
    } elseif (visitlog_driver_ok() && $logFile !== null && !visitlog_ready()) {   // (the file is there and the web server may not write it: another user's after a restore, or set read-only)
        $logLocked(true);
    } elseif (!$vlOk && visitlog_driver_ok() && $logFile !== null) {   // (SQLite is there, the log is there, and it still can't be kept: another owner's files, the folder or the disk, whatever the look kept an hour ago said; never a missing SQLite)
        $logLocked(@is_readable($logFile));
    } elseif (!$vlOk && visitlog_driver_ok()) {   // (SQLite is there and the log still can't be kept: its folder or the disk, not a missing SQLite)
        $add($G, (LOG_PUBLIC_VISITS || ENABLE_SHARE_LINKS) ? 'warn' : 'info', 'The visitor log can\'t be written', $vlWhy . ' Until then nothing is logged and the Visitors and Changes tabs stay hidden. ' . $uses, null, '', [
            'If the disk or the account\'s storage is full, free some room (the Server group below says how much is left). Otherwise the web server must be allowed to write in the gallery\'s folder.',
            'Then press Check again at the top of this page.']);
    } elseif (!$vlOk) {
        $add($G, (LOG_PUBLIC_VISITS || ENABLE_SHARE_LINKS) ? 'warn' : 'info', 'Visitor log: needs SQLite', $vlWhy . ' Until then nothing is logged and the Visitors and Changes tabs stay hidden. ' . $uses, null, '', [
            'PHP needs its SQLite extension (`pdo_sqlite`). Debian or Ubuntu: `sudo apt install php-sqlite3`, then reload PHP-FPM (or Apache). Shared hosting: switch `pdo_sqlite` on in the host\'s PHP extensions page.',
            'Then press Check again at the top of this page.']);
    } elseif ($logFile !== null) {
        $vlSize = visitlog_size(); $vlOld = visitlog_oldest(); $vlText = health_bytes($vlSize);
        if (LOG_WARN_MB > 0 && $vlSize > LOG_WARN_MB * 1048576)
            $add($G, 'opt', 'The visitor records take ' . $vlText, 'More than the ' . number_format(LOG_WARN_MB) . ' MB you asked to be warned at. The oldest record is from ' . ($vlOld > 0 ? gmdate('j M Y', $vlOld) : 'an unknown date') . '. Trim the log in the Visitors tab, or set how long records are kept in Settings.', ['visits', 'vt-root', '→ Visitors']);
        else $add($G, 'ok', 'Visitor log: sound', 'SQLite works here and the file was looked through (' . health_ago($heavy['at']) . '). ' . number_format(visitlog_visitor_count()) . ' visitor' . (visitlog_visitor_count() === 1 ? '' : 's') . ' logged, taking ' . $vlText . '.' . ($vlOld > 0 ? ' The oldest record is from ' . gmdate('j M Y', $vlOld) . '.' : ''));
    } else {
        $add($G, 'ok', 'Visitor log: ready', 'SQLite works here. Nothing has been logged yet.');
    }
    if (changes_tab_wanted() && ($chN = changes_count()) > 0) {   // --- the Changes tab's log of disk activity (the same file)
        $chSize = changes_size(); $chOld = changes_oldest(); $chText = health_bytes($chSize);
        if (CHANGES_WARN_MB > 0 && $chSize > CHANGES_WARN_MB * 1048576)
            $add($G, 'opt', 'The change records take ' . $chText, 'More than the ' . number_format(CHANGES_WARN_MB) . ' MB you asked to be warned at. The oldest entry is from ' . ($chOld > 0 ? gmdate('j M Y', $chOld) : 'an unknown date') . '. Trim the log in the Changes tab, or set how long entries are kept in Settings (Visitors and logs).');
        else $add($G, 'ok', 'Change log: ready', number_format($chN) . ' entr' . ($chN === 1 ? 'y' : 'ies') . ' in the Changes tab, taking ' . $chText . '.' . ($chOld > 0 ? ' The oldest is from ' . gmdate('j M Y', $chOld) . '.' : ''));
    }
    $statsRo = $heavy['stats']['ro'] ?? []; $statsNoDriver = $heavy['stats']['nodriver'] ?? []; $statsKey = $heavy['stats']['key'] ?? [];
    $statsNames = function ($list) { $n = array_map(function ($b) { return is_array($b) ? $b[0] : $b; }, $list); return implode('; ', array_slice($n, 0, 3)) . (count($n) > 3 ? ' and ' . (count($n) - 3) . ' more' : ''); };
    if ($statsNoDriver) {
        $add($G, 'warn', 'View counts are kept in a database this server can\'t open (' . count($statsNoDriver) . ' folder' . (count($statsNoDriver) === 1 ? '' : 's') . ')', $statsNames($statsNoDriver) . '. This PHP has no SQLite (`pdo_sqlite`), so the counts in those files are neither shown nor added to; nothing is lost, and nothing is counted in a second place beside them. Turn `pdo_sqlite` on (Debian or Ubuntu: `sudo apt install php-sqlite3`, then reload PHP-FPM or Apache) and they carry on as they were.');
    }
    if ($statsKey) {   // (a folder's counts were made under a key that is not there: nothing is counted in it, and nothing is made anew behind the counts)
        $kn = count($statsKey);
        $why = ['missing' => 'its key file (.gallery-stats-key.php) is missing', 'mismatch' => 'its key file is not the one the counts were made with', 'broken' => 'its key file can\'t be read'];
        $add($G, 'warn', 'View counts can\'t be read in ' . $kn . ' folder' . ($kn === 1 ? '' : 's'), $statsNames(array_map(function ($b) use ($why) { return [$b[0] . ' (' . ($why[$b[1]] ?? $b[1]) . ')']; }, $statsKey))
            . '. Each folder keeps its view counts under a key of its own, in .gallery-stats-key.php beside the database. A folder copied or moved without that file (some FTP programs and tools skip files that start with a dot) can\'t read what it brought, so nothing is counted there, and the gallery will not start new counts behind the old ones. Put the key file back (from where the folder came from, or from the backup), or, to count afresh, delete .gallery-stats.sqlite and its -wal and -shm files in that folder (the counts in them are lost), then press Check again. Or press the button below: it does exactly that deletion for one folder.', null, health_stats_afresh_buttons($statsKey));
    }
    $emptiedAt = ($logFile !== null && visitlog_driver_ok() && !$logBusy && @is_readable($logFile)) ? (int) changes_read(function ($db) { return visitlog_meta_get($db, 'emptied'); }, 0) : 0;   // (written once, inside the new log, when the file was found with no content)
    if ($emptiedAt > 0 && time() - $emptiedAt < 30 * 86400) {
        $bkOlder = $bk['have'] && backup_has_log() && (int) $bk['at'] < $emptiedAt;
        $add($G, 'warn', 'The visitor log was found empty and started again', 'On ' . gmdate('j M Y H:i', $emptiedAt) . ' (UTC) the log\'s file had no content (a copy or a restore that failed leaves that, and so can a first log that could not be made on a disk with no room). The log was used as it was and its tables made again, so the Visitors and Changes tabs started from nothing then; what they held before is gone from the log. ' . ($bkOlder ? 'The backup from ' . health_ago($bk['at']) . ' was made before that and holds an older copy of the log: restoring it puts that history back (what was logged since is replaced; the log now in use is kept, renamed with .damaged- and the date).' : (($bk['have'] && backup_has_log()) ? 'The backup from ' . health_ago($bk['at']) . ' was made after that, so it holds only the log as it was started again.' : ($bk['have'] ? 'The backup from ' . health_ago($bk['at']) . ' holds no copy of the log.' : 'There is no backup.'))) . ' This row goes away by itself after 30 days.',
            null, $bkOlder ? health_backup_buttons(true, false, false, 'now in use') : '');
    }
    $statsEmpty = $heavy['stats']['empty'] ?? [];
    if ($statsEmpty) {   // (a view-count database of no bytes: its counts are gone and counting has started again from zero)
        $en = count($statsEmpty);
        $add($G, 'warn', 'A view-count database is empty in ' . $en . ' folder' . ($en === 1 ? '' : 's'), $statsNames($statsEmpty) . '. ' . ($en === 1 ? 'The file is' : 'The files are') . ' 0 bytes, so ' . ($en === 1 ? 'its' : 'their') . ' counts are gone and counting has started again from zero (a copy that failed, a disk that filled up, or the file was emptied). If you have the file in a backup or an earlier copy, put it back beside its .gallery-stats-key.php; if not, nothing needs doing, and this row goes away once the folder has counted a view.');
    }
    if ($statsRo) {
        $add($G, 'warn', 'View counts can\'t be written in ' . count($statsRo) . ' folder' . (count($statsRo) === 1 ? '' : 's'), $statsNames($statsRo) . '. The database is there but the web server can\'t open or write to it (another owner\'s file, a folder it can\'t write in, or a disk with no room), so views are not being counted there (the pages carry on without the numbers). Make the file, and its two side files `.gallery-stats.sqlite-shm` and `.gallery-stats.sqlite-wal`, writable by the web server (the same owner as the folder), then press Check again. SQLite makes the side files read-only itself while the main file is, so mending only the main file is not always enough.');
    }
    if ($heavy['stats']['n'] > 0 || ($heavy['stats']['bad'] ?? [])) {
        $bad = $heavy['stats']['bad'];
        if ($bad) {
            $names = implode('; ', array_map(function ($b) { return $b[0]; }, array_slice($bad, 0, 3))) . (count($bad) > 3 ? ' and ' . (count($bad) - 3) . ' more' : '');
            $add($G, 'bad', 'View counts are damaged in ' . count($bad) . ' folder' . (count($bad) === 1 ? '' : 's'), $names . '. Counting views there does not work until the file is replaced (it is left exactly as it is: nothing is counted beside it). The backup holds a copy of each one (unzip it over the folder: the README in the backup says how); or delete the damaged view-count file and counting starts again from nothing.', null,
                $bk['have'] ? '<div class="health-act"><button type="button" class="secondary" data-backup-act="download">Download the backup</button> <span class="muted health-act-out" role="status" aria-live="polite"></span></div>' : '');
        } elseif (!$statsRo && !$statsKey) $add($G, 'ok', 'View counts: sound', number_format($heavy['stats']['n']) . ' folder' . ($heavy['stats']['n'] === 1 ? ' keeps its' : 's keep their') . ' view counts in a database of its own, ' . (($heavy['stats']['busy'] ?? 0) > 0 ? 'looked through (' : ($heavy['stats']['n'] === 1 ? 'looked through (' : 'all looked through (')) . health_ago($heavy['at']) . ').' . (($heavy['stats']['busy'] ?? 0) > 0 ? ' ' . number_format($heavy['stats']['busy']) . ' more ' . ($heavy['stats']['busy'] === 1 ? 'was' : 'were') . ' in use by another process at that moment and not looked through; Check again looks again.' : ''));
    } elseif (($heavy['stats']['busy'] ?? 0) > 0) {
        $add($G, 'info', 'View counts: not looked through yet', number_format($heavy['stats']['busy']) . ' view-count database' . ($heavy['stats']['busy'] === 1 ? ' was' : 's were') . ' in use by another process at that moment, so ' . ($heavy['stats']['busy'] === 1 ? 'it was' : 'they were') . ' not looked through. Check again looks again.');
    }
    // --- the backup
    $buttons = health_backup_buttons(false, false, true);
    $hasProblem = false; $heldRows = [];
    foreach ($bk['problems'] as $pr) { if (!is_array($pr)) continue; if (($pr[2] ?? '') === 'held') $heldRows[] = $pr; else $hasProblem = true; }
    foreach ($heldRows as $pr) {   // (a list that lost a lot: the backup kept the copy from before; the owner says whether the live one is right)
        $nm = htmlspecialchars((string) $pr[0], ENT_QUOTES, 'UTF-8');
        $add($G, 'warn', 'The backup kept the earlier copy of ' . $pr[0], $pr[1] . ', so the backup kept the copy from before (made ' . health_ago($bk['at']) . ') instead of this one. The live file was not touched. If they are meant to be gone, press the button and the next backup takes the live file; if not, put the file back from the backup (unzip it over the gallery\'s folder). Until then this part of the backup is not updated.', null,
            '<div class="health-act"><button type="button" class="secondary" data-backup-act="accept" data-backup-name="' . $nm . '" data-backup-ask="Use the live copy of ' . $nm . ' in the backup from now on? The earlier copy in the backup is replaced by it.">That\'s right, use the new one</button> <span class="muted health-act-out" role="status" aria-live="polite"></span></div>');
    }
    if (!is_writable(__DIR__)) $add($G, 'warn', 'No backup can be made', 'The gallery\'s folder is not writable by the web server, so the backup (a file beside index.php) can\'t be written. Make the folder writable.');
    elseif (!$bk['have']) {
        if ($bk['result'] === 'failed') $add($G, 'warn', 'There is no backup: the last try did not work', $bk['why'], null, $buttons);
        else $add($G, 'opt', 'No backup yet', 'The first one is made by itself after a coming visit, and then once a week. Or make it now.', null, $buttons);
    } elseif (($heavy['zip'] ?? true) === false) {
        $add($G, 'warn', 'The backup file is damaged', 'The backup from ' . health_ago($bk['at']) . ' does not check out when it is read back: the file was cut short or changed after it was made. Restore and Download refuse it, so nothing bad can come from it, but it can\'t help you either. Make a new one now.', null, $buttons);
    } elseif ($bk['result'] === 'failed' && $bk['tried'] > $bk['at']) {
        $add($G, 'warn', 'The last backup did not work', $bk['why'] . ' The backup from ' . health_ago($bk['at']) . ' is still there.', null, $buttons);
    } elseif ($hasProblem) {
        $parts = []; foreach ($bk['problems'] as $pr) if (is_array($pr) && ($pr[2] ?? '') !== 'held') $parts[] = $pr[0] . ' ' . $pr[1] . ($pr[2] === 'carried' ? ' (the copy from the backup before is kept)' : ' (no earlier copy: it is not in the backup)');
        $add($G, 'warn', 'Backup made ' . health_ago($bk['at']) . ', but ' . count($parts) . ' part' . (count($parts) === 1 ? '' : 's') . ' could not be read soundly', implode('. ', array_slice($parts, 0, 3)) . (count($parts) > 3 ? '. And ' . (count($parts) - 3) . ' more.' : '.') . ' A damaged file never replaces a good copy in the backup.', null, $buttons);
    } elseif (time() - $bk['at'] > 2 * backup_week()) {
        $add($G, 'opt', 'The backup is ' . health_ago($bk['at']), 'It is made once a week by the first visit after the week; none has come since. Make one now.', null, $buttons);
    } else {
        $add($G, 'ok', 'Backup: made ' . health_ago($bk['at']), number_format($bk['files']) . ' file' . ($bk['files'] === 1 ? '' : 's') . ', ' . health_bytes($bk['bytes']) . ', each one looked at before it went in and the whole ZIP read back (' . ($bk['by'] === 'you' ? 'asked for' : 'the weekly one') . '). The next is made by itself a week after this one. It holds your settings, share links, collections, captions, view counts and the visitor log, not your photos.', null, $buttons);
    }
}

/** The share links and collections files, and a few states of the public side worth knowing. */
function health_sharing_rows($add) {
    $G = 'Sharing and visitors';
    if (is_file(links_path())) {
        [$st, $data] = json_file_load(links_path(), links_default());
        if ($st === 'broken') $add($G, 'bad', 'The share links file can\'t be read', 'Every share link stops working until it can be read again. The file (.gallery-links.php) was left exactly as it is. The backup, if you have one, holds the last good copy: unzip it over the file (the README in it says how).', null, backup_find() !== null ? '<div class="health-act"><button type="button" class="secondary" data-backup-act="download">Download the backup</button> <span class="muted health-act-out" role="status" aria-live="polite"></span></div>' : '');
        else {
            $by = ['active' => 0, 'paused' => 0, 'ended' => 0]; $soon = 0; $now = time();
            foreach ((array) ($data['links'] ?? []) as $l) {
                if (!is_array($l) || link_is_public_log($l)) continue;
                $s = link_state($l, $now);
                if ($s === 'active' || $s === 'used_up') { $by['active']++; if ((int) ($l['endsAt'] ?? 0) > 0 && (int) $l['endsAt'] - $now < 7 * 86400) $soon++; }
                elseif ($s === 'paused') $by['paused']++;
                elseif ($s === 'expired' || $s === 'scheduled') $by['ended']++;
            }
            $tot = array_sum($by);
            $add($G, 'ok', 'Share links file: sound', $tot === 0 ? 'No share links yet.' : number_format($tot) . ' link' . ($tot === 1 ? '' : 's') . ': ' . $by['active'] . ' active, ' . $by['paused'] . ' paused, ' . $by['ended'] . ' ended or not started.');
            if ($soon > 0) $add($G, 'heads', $soon . ' share link' . ($soon === 1 ? ' ends' : 's end') . ' within a week', 'People using ' . ($soon === 1 ? 'it' : 'them') . ' lose access then. Extend ' . ($soon === 1 ? 'it' : 'them') . ' in the Shares tab if that is not what you want.', ['links', 'sl-root', '→ Shares']);
        }
    }
    if (is_file(collections_path())) {
        [$st] = json_file_load(collections_path(), collections_default());
        if ($st === 'broken') $add($G, 'bad', 'The collections file can\'t be read', 'Your collections are not shown until it can be read again. The file (.gallery-collections.php) was left exactly as it is; the backup holds the last good copy.');
    }
    if (gallery_is_closed()) $add($G, 'heads', 'The public gallery is closed', 'Someone who comes without a share link sees nothing. Only you, private-view visitors and share-link holders see photos.', ['site', 'setting-CLOSE_PUBLIC_GALLERY', '→ Close the public gallery']);
    if (LOG_PUBLIC_VISITS) $add($G, 'info', 'The public address is logged', 'Who opens the gallery without a link (their address and browser) is kept in the visitor log.', ['visits', 'vt-root', '→ Visitors']);
}

/** The disks the gallery's photos and files live on, least free space first: [{name, free, total, pct, writable}]. The gallery's own folder, every folder from elsewhere
 *  (mapped by name or as a root) and the folders its media sit in are grouped by the disk they are on (the device a folder is on); a disk that will not say how much is
 *  free (some network shares) is left out. Read-only ones are in too: the room left matters for the photos kept there whether or not the gallery writes to it. */
function health_volumes($dir, $files) {
    static $cache = [];
    $ck = rtrim((string) $dir, '/') . '|' . count((array) $files);
    if (isset($cache[$ck])) return $cache[$ck];
    $dir = rtrim((string) (realpath($dir) ?: $dir), '/');
    $named = is_array(EXTRA_FOLDERS) ? EXTRA_FOLDERS : [];
    $paths = [$dir => 'the gallery folder'];
    foreach ($named as $nm => $pth) if (is_string($pth) && is_dir($pth)) $paths[rtrim((string) (realpath($pth) ?: $pth), '/')] = is_string($nm) && $nm !== '' ? $nm : $pth;
    foreach ((is_array(EXTRA_ROOTS) ? EXTRA_ROOTS : []) as $pth) if (is_string($pth) && is_dir($pth)) { $r = rtrim((string) (realpath($pth) ?: $pth), '/'); if (!isset($paths[$r])) $paths[$r] = $r; }
    $seen = 0; $t0 = microtime(true);
    foreach ((array) $files as $f) { if (microtime(true) - $t0 > 1.5) break; $c = rtrim((string) containing_folder_real_path($dir, $f), '/'); if ($c !== '' && !isset($paths[$c])) { $paths[$c] = null; if (++$seen >= SCAN_MAX_FOLDERS) break; } }   // (up to the gallery's own limit of folders, within a second and a half: a disk reached only after the first few hundred folders still gets its row)
    $by = [];
    $homeDev = (string) (@stat($dir)['dev'] ?? '');   // (the disk the gallery's own folder is on: its logs, backup and settings are there)
    foreach ($paths as $p => $label) {
        if (microtime(true) - $t0 > 2.5) break;   // (the look at the disk, folder by folder, has its own limit: a slow disk or a very big tree cuts it short)
        $st = @stat($p); if (!$st) continue;
        $key = (string) $st['dev'];
        if ($label === null) {   // a folder only the scan found: named by the place it belongs to (a folder inside the gallery that is a disk of its own, a mount, is named by its folder: the gallery's own files are not on it)
            $inside = strncmp($p . '/', $dir . '/', strlen($dir) + 1) === 0;
            if ($inside && ($key === $homeDev || $homeDev === '')) $label = 'the gallery folder';
            elseif ($inside) {   // a disk of its own inside the gallery: named by the folder it is mounted at (the top of that disk), not by whichever of its folders came first
                $top = $p;
                while (($up = dirname($top)) !== $dir && strncmp($up . '/', $dir . '/', strlen($dir) + 1) === 0 && (string) (@stat($up)['dev'] ?? '') === $key) $top = $up;
                $label = substr($top, strlen($dir) + 1);
            } else $label = $p;
            foreach ($named as $nm => $pth) { $np = rtrim((string) (realpath((string) $pth) ?: $pth), '/'); if ($np !== '' && strncmp($p . '/', $np . '/', strlen($np) + 1) === 0) { $label = (string) $nm; break; } }
        }
        if (!isset($by[$key])) {
            $free = @disk_free_space($p); $tot = @disk_total_space($p);
            if ($free === false || $tot === false || $tot <= 0) { $by[$key] = false; continue; }
            $by[$key] = ['names' => [], 'free' => (float) $free, 'total' => (float) $tot, 'writable' => false];
        }
        if ($by[$key] === false) continue;
        if (!in_array($label, $by[$key]['names'], true)) $by[$key]['names'][] = $label;
        if (is_writable($p)) $by[$key]['writable'] = true;
    }
    $out = [];
    foreach ($by as $v) {
        if (!$v) continue;
        $isHome = in_array('the gallery folder', $v['names'], true);
        $n = $v['names']; $name = implode(', ', array_slice($n, 0, 3)) . (count($n) > 3 ? ' and ' . (count($n) - 3) . ' more' : '');
        $out[] = ['home' => $isHome, 'name' => $isHome ? ucfirst($name) : $name, 'free' => $v['free'], 'total' => $v['total'], 'pct' => (int) round($v['free'] / $v['total'] * 100), 'writable' => $v['writable']];
    }
    // two entries with the very same size and free space are one disk seen twice (sub-volumes of one pool)
    $uniq = []; foreach ($out as $v) { $k = $v['total'] . '/' . $v['free']; if (isset($uniq[$k])) { $uniq[$k]['name'] .= ', ' . $v['name']; $uniq[$k]['writable'] = $uniq[$k]['writable'] || $v['writable']; $uniq[$k]['home'] = $uniq[$k]['home'] || $v['home']; } else $uniq[$k] = $v; }
    $out = array_values($uniq);
    usort($out, function ($a, $b) { return $a['free'] <=> $b['free']; });
    return $cache[$ck] = $out;
}

/** A "How to fix" fold for a row that needs a change on the server (something the gallery can't do from its own folder). $steps: a list of lines, each a text (`like this` is
 *  shown as code) or [text, code], where the code gets a box with a Copy button. $key + $hidden: for a row the browser tests, the fold starts hidden and the script shows it
 *  when the test fails. */
function health_how_html($steps, $key = '', $hidden = false) {
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $fmt = function ($t) use ($esc) { return preg_replace('/`([^`]+)`/', '<code>$1</code>', $esc($t)); };
    $h = '<details class="health-how"' . ($key !== '' ? ' data-how-for="' . $esc($key) . '"' : '') . ($hidden ? ' hidden' : '') . '><summary>How to fix</summary><div class="health-how-body">';
    foreach ($steps as $st) {
        $text = is_array($st) ? $st[0] : $st; $code = is_array($st) ? ($st[1] ?? '') : '';
        if ($text !== '') $h .= '<p>' . $fmt($text) . '</p>';
        if ($code !== '') $h .= '<div class="rules-box"><button type="button" class="secondary copy-rules">Copy</button><pre><code>' . $esc($code) . '</code></pre></div>';
    }
    return $h . '</div></details>';
}

/** The steps for a file the server must refuse (anything starting with .gallery-): the visitor log's and the backup's rows use them. */
function health_how_gallery_files() {
    return [
        ['nginx (a server of your own): add this inside the site\'s `server { }` block, then run `sudo nginx -t && sudo systemctl reload nginx`.', rtrim(gallery_files_rule_nginx())],
        ['Apache, or any host that reads `.htaccess` (FTP-only hosting too): save this as a file named `.htaccess` in the gallery\'s folder. If one is there already, add it at the end.', rtrim(gallery_files_rule_apache())],
        'This rule is also part of the block under Settings → Server setup → Web server rules, with the others the gallery can use.',
        'Then press the circular arrow at the top of this page (Check again): this row turns green when the server refuses the address.',
    ];
}

/** The server: disk room, the connection, the visitor address, what PHP allows. */
function health_server_rows($add) {
    $G = 'Server';
    $vols = health_volumes($GLOBALS['dir'], $GLOBALS['__admin_files'] ?? []);
    $many = count($vols) > 1;
    foreach ($vols as $v) {
        $where = $many ? ' on ' . $v['name'] : '';
        $ownDisk = !empty($v['home']);   // (the logs, the backup and the gallery's own settings are in the gallery's folder: on another disk only each folder's own captions and thumbnails are)
        if ($v['free'] < 100 * 1048576) $add($G, 'warn', 'Disk space is almost gone' . $where . ': ' . health_bytes($v['free']) . ' free', $v['writable'] ? ($ownDisk ? 'The gallery needs room for itself on this disk too, not only for your photos: captions and settings, thumbnails, view counts, the visitor and change logs and the backup are all kept here. When the disk is full they stop being saved, settings fail to save, the Changes tab can\'t record what is removed (so the captions of deleted photos are kept until there is room), and new photos can\'t be added.' : 'The gallery keeps things on this disk too, not only your photos: each folder\'s captions and settings and its thumbnails are saved inside the folder. When the disk is full they stop being saved, and new photos can\'t be added.') : 'No room is left to add photos there. (The gallery only reads it, so nothing of its own is affected.)');
        elseif ($v['free'] < 1073741824) $add($G, 'opt', 'Disk space is getting low' . $where . ': ' . health_bytes($v['free']) . ' free', $v['writable'] ? ($ownDisk ? 'This disk holds more than the photos you share: the gallery keeps its captions and settings, thumbnails, view counts, logs and backup here, and they grow over time. If it runs out, saving fails and the logs (the Changes tab included) stop being kept. The Delete list in Settings shows what the gallery\'s own files take.' : 'This disk holds more than the photos you share: each folder\'s captions and settings and its thumbnails are saved inside the folder and grow over time. If it runs out, saving fails. The Delete list in Settings shows what the gallery\'s own files take.') : 'Little room is left to add photos there. (The gallery only reads it.)');
    }
    if ($vols && !$many && $vols[0]['free'] >= 1073741824) $add($G, 'info', 'Disk space: ' . health_bytes($vols[0]['free']) . ' free (' . $vols[0]['pct'] . '%)');
    if ($many) {
        $lines = []; foreach ($vols as $v) $lines[] = $v['name'] . ': ' . health_bytes($v['free']) . ' free of ' . health_bytes($v['total']) . ' (' . $v['pct'] . '%)' . ($v['writable'] ? '' : ', read only');
        $add($G, 'info', 'Disk space on ' . count($vols) . ' disks', implode('. ', $lines) . '.');
    }
    if (is_https_request()) $add($G, 'ok', 'Connection: HTTPS', 'This page was reached over an encrypted connection.');
    elseif (!is_local_request()) $add($G, 'opt', 'You are using the admin over plain HTTP', 'Your sign-in code and the sign-in cookie cross the network unencrypted. Use the https:// address of this gallery (set it up on the web server if there is none).', null, '', [
            'A server of your own: get a free certificate from Let\'s Encrypt (`sudo certbot --nginx`, or `sudo certbot --apache`), then open the gallery by its `https://` address.',
            'Behind a proxy or tunnel that already does HTTPS (Cloudflare, Caddy, nginx in front, a tunnel): the gallery only sees plain HTTP, so have the proxy tell it. In nginx, in the proxy\'s location:', 
            ['', 'proxy_set_header X-Forwarded-Proto $scheme;'],
            'Shared hosting: switch on the free certificate in the host\'s control panel (often called SSL, or Let\'s Encrypt), then use the `https://` address.']);
    $hint = client_ip_proxy_hint();
    if ($hint !== '') $add($G, 'opt', 'Visitor addresses may be the proxy\'s, not the visitor\'s', $hint . ' Until then the visitor log and the sign-in pause see the proxy\'s address.', ['site', 'setting-TRUSTED_PROXY', '→ Visitor address'], '', [
            'Open Settings → Visitor address and choose what sits in front of the gallery: Cloudflare, or another proxy.',
            'For another proxy, also list the proxy\'s own address under the proxy addresses, so only it is believed (a tunnel or proxy on this same machine is `127.0.0.1/32`). Anyone else\'s claim about a visitor\'s address is ignored.',
            'To tell it worked: the Visitors tab then shows each visitor\'s own address, not the same one every time.']);
    if ((CHECK_FOR_UPDATES || COUNTRY_LOOKUP) && !function_exists('curl_init') && !filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN))
        $add($G, 'opt', 'This server lets PHP fetch no web addresses', 'Looking for a new version' . (COUNTRY_LOOKUP ? ' and downloading the country file' : '') . ' need either the curl extension or allow_url_fopen. Neither is on, so they can\'t run.', null, '', [
            'Install the curl extension. Debian or Ubuntu: `sudo apt install php-curl`, then reload PHP-FPM (or Apache). Shared hosting: switch curl on in the host\'s PHP extensions page.',
            'Or, in the php.ini that PHP-FPM reads, set `allow_url_fopen = On` and reload PHP-FPM.',
            'Then press Check again at the top of this page.']);
    // PHP's own picture library (GD) needs a photo in memory (about 100 MB for 12 megapixels). The gallery asks for 256M itself; this row is only for a server that refuses, and only
    // where GD is what makes the thumbnails (Imagick keeps pictures outside PHP's memory).
    if (!class_exists('Imagick') && extension_loaded('gd') && !image_memory_raise())
        $add($G, 'opt', 'PHP\'s memory limit is too low for big photos (' . trim((string) ini_get('memory_limit')) . ')', 'This server makes thumbnails with PHP\'s own picture library, which needs about 100 MB for a 12-megapixel photo. The gallery asked for more and the server said no, so large photos may get no thumbnail. Set memory_limit to 256M in the server\'s PHP settings (php.ini, or the host\'s PHP settings page).', null, '', [
            'A server of your own (nginx or Apache with PHP-FPM): edit the php.ini that PHP-FPM reads, for example `/etc/php/8.4/fpm/php.ini` (the command `php --ini` shows the command-line one; FPM keeps its own copy). Set `memory_limit = 256M`, then reload PHP-FPM, for example `sudo systemctl reload php8.4-fpm`.',
            'If the site\'s PHP-FPM pool file (under `/etc/php/8.4/fpm/pool.d/`) already has a line starting `php_admin_value[memory_limit]`, change that one instead: it overrides php.ini and is the usual reason the gallery was refused.',
            'Shared hosting: the host\'s PHP settings page, or a file named `.user.ini` next to `index.php` containing `memory_limit = 256M` (it can take a few minutes to be noticed).',
            'Then press Check again at the top of this page.']);
}

/** The numbers across the top: [[label, value, disks, html, title, go]]. Only what is cheap to know. Order: what is in the gallery (photos, videos, folders), what the server has (disk room, the log's size),
 *  then what has a tab or a row of its own (links, visitors, backup): those three are buttons ($go: the tab to open, or 'backup' for the Backup row with its buttons). */
function health_strip($dir, $files) {
    $videos = 0; $n = 0;
    foreach ($files as $f) { $n++; if (is_video_file($f)) $videos++; }
    $out = [['Photos', number_format($n - $videos)]];
    if ($videos) $out[] = ['Videos', number_format($videos)];
    $folders = count(all_folder_paths($files)) + 1;   // (and the front page)
    if (collections_any()) $folders += count(collections_registry($dir, $files)['byPath']);   // (a collection is a card in the gallery like a folder; it is not a folder on disk, so the list above does not hold it)
    $out[] = ['Folders', number_format($folders)];
    $vols = health_volumes($dir, $files);
    if ($vols) {
        // One card for the disk, with the room taken and the room left: it shows the used space to start with and a press turns it over to the free space (the page remembers the choice).
        // The figures are the lowest-on-room disk's. With more than one disk a second small card opens the list of them all.
        $v0 = $vols[0]; $used0 = max(0.0, $v0['total'] - $v0['free']); $usedPct = $v0['total'] > 0 ? (int) round($used0 / $v0['total'] * 100) : 0;
        $disk = ['used' => ['Disk used', health_bytes($used0), health_bytes($used0) . ' used of ' . health_bytes($v0['total']) . ' (' . $usedPct . '%). Press to see the free space instead'],
                 'free' => ['Disk free', health_bytes($v0['free']), health_bytes($v0['free']) . ' free of ' . health_bytes($v0['total']) . ' (' . $v0['pct'] . '%). Press to see the used space instead']];
        $lines = null;
        if (count($vols) > 1) { $lines = []; foreach ($vols as $v) $lines[] = [$v['name'], health_bytes($v['free']), health_bytes($v['total']), health_bytes(max(0.0, $v['total'] - $v['free']))]; }
        $out[] = ['Disk used', health_bytes($used0), $lines, null, $disk['used'][2], '', $disk];
    }
    $hasLog = visitlog_find() !== null && visitlog_driver_ok();
    if ($hasLog) $out[] = ['Log', health_bytes(visitlog_size())];
    if (is_file(links_path())) {
        $act = 0; $other = 0;
        foreach ((array) (links_read()['links'] ?? []) as $l) if (is_array($l) && !link_is_public_log($l)) { if (link_is_live($l)) $act++; else $other++; }
        // (the value is two numbers: the ones in use in green, the others, paused or ended, in red; the box says what each is when it is touched)
        $html = '<span class="hs-two">' . '<span class="hs-ok">' . number_format($act) . '</span>' . ($other ? '<span class="hs-bad">' . number_format($other) . '</span>' : '') . '</span>';   // (the two numbers sit at the left and the right of the box, not side by side)
        $out[] = ['Links', number_format($act) . ($other ? ' / ' . number_format($other) : ''), null, $html, number_format($act) . ' in use' . ($other ? ', ' . number_format($other) . ' paused, ended or not started yet' : ''), 'links'];
    }
    if ($hasLog) $out[] = ['Visitors', number_format(visitlog_visitor_count()), null, null, '', 'visits'];
    $bk = backup_status(); $bkBad = $bk['have'] && (health_heavy($dir, $files, false)['zip'] ?? true) === false;   // (the file read back, as the row below does: a ZIP cut short is not "just now")
    $out[] = ['Backup', !$bk['have'] ? 'none' : ($bkBad ? 'damaged' : health_ago_short($bk['at'])), null, null, !$bk['have'] ? 'No backup has been made yet' : ($bkBad ? 'The backup file does not check out when it is read back' : 'The backup was made ' . health_ago($bk['at'])), 'backup'];
    return $out;
}
