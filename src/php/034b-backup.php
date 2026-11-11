/* -----------------------------------------------------------------------
 * The backup. One ZIP beside index.php (.gallery-backup-<24 random hex>.zip) holds everything the gallery keeps that can't be made again: the settings,
 * the share links, the collections, the install secret, the captions and flags in every folder's data file, the view counts, the visitor log (with the
 * change log and lockouts in it), the lockout history and the notes (.md files that begin with {gallery}). Not in it: photos and videos (they are the
 * owner's own files), thumbnails and read-once file data (made again), the country file (downloaded again), the signed-in browsers (people sign in again).
 *
 * Rules (the owner's): made by itself once a week, and when asked; only ONE copy, which each new one replaces; and nothing is trusted before it is
 * looked at. Every part is copied whole, then checked (a SQLite file with PRAGMA quick_check, a data file for being whole and readable); a part that
 * fails its check is not put in: the same part of the backup before is carried over instead (and Health says so), so a damaged file never replaces a
 * good copy. The finished ZIP is read back and every file checked against its checksum before it takes the old one's place (one rename). A SQLite
 * file is copied with VACUUM INTO, never with a file copy: a log that is being written to can't be copied whole by copying its file.
 *
 * There is no cron here, so "weekly" means the first request after a week has passed (answered first; the backup runs after the page has been sent).
 * ----------------------------------------------------------------------- */

function backup_find() {
    $best = null;
    foreach ((array) glob(__DIR__ . '/.gallery-backup-*.zip') as $f) if (preg_match('~/\.gallery-backup-[0-9a-f]{24}\.zip$~', $f) && is_file($f) && !is_link($f) && ($best === null || $f < $best)) $best = $f;
    return $best;
}
function backup_state_path() { return __DIR__ . '/.gallery-backup.php'; }
/** How the last tries went: ok_at, try_at, by (auto|you), result (ok|partial|failed), why, files, bytes, problems [[name, why, carried|left out]]. */
function backup_state() { return is_file(backup_state_path()) ? json_file_read(backup_state_path(), []) : []; }
function backup_state_set(array $kv) { json_file_update(backup_state_path(), [], function (&$d) use ($kv) { $d = array_merge($d, $kv); }); }
function backup_week() { return 7 * 86400; }

/** The folders the gallery shows (the gallery's own first): [rel => real path]. */
function backup_folders($dir, $files) {
    $dir = rtrim((string) (realpath($dir) ?: $dir), '/');
    $folders = ['' => $dir];
    foreach (all_folder_paths($files) as $rel) {
        $real = resolve_real_path($dir, $rel);
        if (is_string($real) && $real !== '' && is_dir($real) && !is_link($real)) $folders[$rel] = rtrim($real, '/');
    }
    return $folders;
}

/** What goes in: [{rel, path, kind, mtime, sqlite}], the names as the Files-this-gallery-made list gives them (a mapped folder under its name in the gallery). */
function backup_parts($dir, $files) {
    $keep = ['settings', 'links', 'collections', 'secret', 'lockouts', 'items', 'stats'];
    $parts = [];
    foreach (owned_plan($dir, $files)['entries'] as $e) {
        if (!in_array($e['kind'], $keep, true)) continue;
        $parts[] = ['rel' => $e['rel'], 'path' => $e['path'], 'kind' => $e['kind'], 'mtime' => (int) $e['mtime'], 'sqlite' => substr($e['path'], -7) === '.sqlite'];
    }
    $live = visitlog_find();
    if ($live !== null) $parts[] = ['rel' => basename($live), 'path' => $live, 'kind' => 'visits', 'mtime' => (int) @filemtime($live), 'sqlite' => true];
    foreach (backup_folders($dir, $files) as $rel => $real) {
        foreach ((array) @glob($real . '/*.md') as $f) {
            if (!is_file($f) || is_link($f) || (int) @filesize($f) > 1048576) continue;
            $fh = @fopen($f, 'rb'); $first = $fh ? fgets($fh, 64) : false; if ($fh) fclose($fh);
            if ($first === false || trim($first) !== '{gallery}') continue;
            $parts[] = ['rel' => ($rel === '' ? '' : $rel . '/') . basename($f), 'path' => $f, 'kind' => 'notes', 'mtime' => (int) @filemtime($f), 'sqlite' => false];
        }
    }
    return $parts;
}

/** A SQLite file looked through (PRAGMA quick_check): '' when it is sound, else the reason. */
function backup_sqlite_check($path) {
    if (!visitlog_driver_ok()) return 'could not be read (this server has no SQLite)';
    if (!is_file($path)) return 'is not there';
    try {
        $p = new PDO('sqlite:' . $path);
        $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $p->exec('PRAGMA busy_timeout = 5000');
        $q = $p->query('PRAGMA quick_check')->fetchAll(PDO::FETCH_COLUMN);
        $p = null;
        if (!$q || $q[0] !== 'ok') { $n = 0; foreach ($q as $line) if (strpos((string) $line, '***') === false && trim((string) $line) !== '') $n++; return 'is damaged (SQLite found ' . max(1, $n) . ' problem' . ($n === 1 ? '' : 's') . ' inside it)'; }
        return '';
    } catch (Throwable $e) {
        return 'is damaged or can\'t be read (' . substr(preg_replace('~^SQLSTATE\[[^\]]*\]:\s*(?:General error:\s*\d+\s*)?~', '', preg_replace('~\s+~', ' ', $e->getMessage())), 0, 120) . ')';
    }
}

/** A SQLite file looked at and copied whole: '' when it is sound and $dest now holds a sound copy, else the reason. */
function backup_copy_sqlite($src, $dest) {
    $why = backup_sqlite_check($src);
    if ($why !== '') return $why;
    try {
        $p = new PDO('sqlite:' . $src);
        $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $p->exec('PRAGMA busy_timeout = 5000');
        @unlink($dest);
        $ver = (string) $p->query('SELECT sqlite_version()')->fetchColumn();
        if (version_compare($ver, '3.27.0', '>=')) $p->exec('VACUUM INTO ' . $p->quote($dest));
        else {   // (an older SQLite: hold the writers off, fold the journal in, copy the file)
            $p->exec('BEGIN IMMEDIATE'); $p->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            $ok = @copy($src, $dest); $p->exec('COMMIT');
            if (!$ok) return 'could not be copied';
        }
        $p = null;
        return backup_sqlite_check($dest) === '' ? '' : 'could not be copied soundly';
    } catch (Throwable $e) {
        return 'could not be copied (' . substr(preg_replace('~\s+~', ' ', $e->getMessage()), 0, 120) . ')';
    }
}

/** Is a copied data file whole and readable as what it is? '' or the reason. */
function backup_check_file($path, $kind) {
    $raw = (string) @file_get_contents($path);
    if ($raw === '') return 'is empty';
    if ($kind === 'settings') return gallery_config_parse($raw)[0] === 'ok' ? '' : 'can\'t be read as settings';
    if ($kind === 'secret') return preg_match('/^[0-9a-f]{64}$/m', $raw) ? '' : 'does not hold the key';
    if ($kind === 'notes') return trim((string) strtok($raw, "\n")) === '{gallery}' ? '' : 'is not a note';
    if ($kind === 'lockouts') return '';
    return is_array(json_decode(data_file_strip_guard($raw), true)) ? '' : 'can\'t be read (it is not whole)';
}

/** One part, copied whole and checked, into a temporary file: [path, ''] or [null, why]. */
function backup_copy_part($part, $tmpDir) {
    if ($part['sqlite']) {
        $dest = @tempnam($tmpDir, 'gallery-backup-');
        if ($dest === false) return [null, 'could not be copied (no room for a temporary copy)'];
        $why = backup_copy_sqlite($part['path'], $dest);
        if ($why !== '') { @unlink($dest); return [null, $why]; }
        return [$dest, ''];
    }
    $tmp = owned_snapshot($part['path']);
    if ($tmp === null) return [null, 'could not be copied whole'];
    $why = backup_check_file($tmp, $part['kind']);
    if ($why !== '') { @unlink($tmp); return [null, $why]; }
    return [$tmp, ''];
}

/* ---- reading a ZIP this gallery wrote (stored, not compressed; no ZIP64: a backup is far smaller than 4 GB) ---- */

/** [name => [crc, size, offset]] from the central directory, or null when the file is not a whole ZIP of ours. */
function backup_zip_index($path) {
    $size = (int) @filesize($path);
    if ($size < 22 || !($fh = @fopen($path, 'rb'))) return null;
    fseek($fh, $size - 22);
    $e = unpack('Vsig/vdisk/vcd/vn/vtotal/Vlen/Voff/vcomment', (string) fread($fh, 22));
    if (!$e || $e['sig'] !== 0x06054b50 || $e['comment'] !== 0 || $e['off'] + $e['len'] + 22 !== $size) { fclose($fh); return null; }
    fseek($fh, $e['off']); $cd = (string) fread($fh, $e['len']); fclose($fh);
    $out = []; $at = 0;
    for ($i = 0; $i < $e['n']; $i++) {
        $h = unpack('Vsig/vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen/vclen/vdisk/vint/Vext/Voff', substr($cd, $at, 46));
        if (!$h || $h['sig'] !== 0x02014b50 || $h['method'] !== 0 || $h['csize'] !== $h['usize'] || $h['csize'] === 0xFFFFFFFF) return null;
        $name = substr($cd, $at + 46, $h['nlen']);
        $out[$name] = ['crc' => $h['crc'], 'size' => $h['usize'], 'offset' => $h['off'], 'time' => $h['time'], 'date' => $h['date']];
        $at += 46 + $h['nlen'] + $h['elen'] + $h['clen'];
    }
    return $at === $e['len'] ? $out : null;
}

/** Copies one file out of the ZIP into $dest, checking its checksum on the way: true, or false (and $dest removed). */
function backup_zip_extract($path, $index, $name, $dest) {
    if (!isset($index[$name]) || !($in = @fopen($path, 'rb')) || !($out = @fopen($dest, 'wb'))) return false;
    $m = $index[$name];
    fseek($in, $m['offset']); $lh = unpack('Vsig/vver/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen', (string) fread($in, 30));
    if (!$lh || $lh['sig'] !== 0x04034b50) { fclose($in); fclose($out); @unlink($dest); return false; }
    fseek($in, $m['offset'] + 30 + $lh['nlen'] + $lh['elen']);
    $ctx = hash_init('crc32b'); $ok = true;
    for ($left = $m['size']; $left > 0;) {
        $chunk = fread($in, min(1048576, $left));
        if ($chunk === false || $chunk === '') { $ok = false; break; }
        hash_update($ctx, $chunk); $left -= strlen($chunk);
        if (fwrite($out, $chunk) === false) { $ok = false; break; }
    }
    fclose($in); $closed = fclose($out);
    $ok = $ok && $closed && hexdec(hash_final($ctx)) === $m['crc'] && (int) @filesize($dest) === $m['size'];
    if (!$ok) @unlink($dest);
    return $ok;
}

/** Every file in the ZIP read back and checked against its checksum: [ok, how many files]. */
function backup_zip_verify($path) {
    $index = backup_zip_index($path);
    if ($index === null) return [false, 0];
    $tmp = @tempnam(sys_get_temp_dir(), 'gallery-backup-');
    if ($tmp === false) return [false, 0];
    $n = 0;
    foreach ($index as $name => $m) { if (!backup_zip_extract($path, $index, $name, $tmp)) { @unlink($tmp); return [false, $n]; } $n++; }
    @unlink($tmp);
    return [true, $n];
}

function backup_readme($manifest) {
    $t = "THE BACKUP\r\n\r\nMade " . gmdate('Y-m-d H:i', $manifest['made']) . " UTC by gallery " . $manifest['version'] . ($manifest['by'] === 'you' ? ' (asked for)' : ' (the weekly one)') . ".\r\n\r\n";
    $t .= "Everything the gallery keeps that can't be made again: the settings, share links, collections, the install secret, the captions and flags in each folder's data file,\r\nthe view counts, the visitor log (with the change log and lockouts in it) and the notes (.md files that begin with {gallery}).\r\n";
    $t .= "Not in it: your photos and videos (they are your own files), thumbnails and the read-once file data (made again), the country file (downloaded again) and the signed-in browsers (people sign in again).\r\n\r\n";
    $t .= "HOW TO PUT FILES BACK: unzip over the folder index.php is in, so each file lands next to its photos (a folder shown from elsewhere on the server appears under the name it has in the gallery:\r\nput its files in the real folder it comes from). To put back the visitor log only, press Restore in the Health check.\r\n\r\n";
    $t .= "KEEP THIS FILE PRIVATE: it holds password hashes, the install secret, share-link addresses and visitor IP addresses.\r\n\r\nFILES (MANIFEST.json has the checksums):\r\n";
    foreach ($manifest['parts'] as $p) $t .= '  ' . $p['name'] . ($p['state'] === 'carried' ? '   (kept from the backup before: ' . $p['why'] . ')' : '') . "\r\n";
    if ($manifest['left_out']) { $t .= "\r\nNOT IN THIS BACKUP (they could not be read soundly, and there was no earlier copy):\r\n"; foreach ($manifest['left_out'] as $p) $t .= '  ' . $p['name'] . ' ' . $p['why'] . "\r\n"; }
    return $t;
}

/** Makes the backup now: a whole new ZIP, checked, replacing the old one. Returns ['ok' => bool, 'busy' => bool, 'why' => text, 'files' => n, 'bytes' => n, 'problems' => [[name, why, carried|left out]]]. */
function backup_run($manual, $dir, $files) {
    $now = time();
    $zip = backup_find() ?? (__DIR__ . '/.gallery-backup-' . bin2hex(random_bytes(12)) . '.zip');
    $work = $zip . '.tmp';
    $fail = function ($why, $problems = []) use ($now, $manual) {
        backup_state_set(['try_at' => $now, 'by' => $manual ? 'you' : 'auto', 'result' => 'failed', 'why' => $why, 'problems' => $problems]);
        return ['ok' => false, 'busy' => false, 'why' => $why, 'files' => 0, 'bytes' => 0, 'problems' => $problems];
    };
    if (!is_writable(__DIR__)) return $fail('The gallery\'s folder is not writable by the web server, so a backup can\'t be made.');
    if (is_file($work)) {
        if ($now - (int) @filemtime($work) < 900) return ['ok' => false, 'busy' => true, 'why' => 'A backup is being made right now.', 'files' => 0, 'bytes' => 0, 'problems' => []];
        @unlink($work);
    }
    $lock = @fopen($work, 'xb');
    if (!$lock) return ['ok' => false, 'busy' => true, 'why' => 'A backup is being made right now.', 'files' => 0, 'bytes' => 0, 'problems' => []];
    @chmod($work, 0600);
    backup_state_set(['try_at' => $now, 'by' => $manual ? 'you' : 'auto']);
    $cleanup = []; $tmpDir = sys_get_temp_dir();
    try {
        $parts = backup_parts($dir, $files);
        $old = is_file($zip) ? backup_zip_index($zip) : null;
        $oldOk = $old !== null && backup_zip_verify($zip)[0];   // (an old backup that does not check out is not carried from)
        $need = 0; foreach ($parts as $p) $need += (int) @filesize($p['path']);
        $tf = @disk_free_space($tmpDir); $df = @disk_free_space(__DIR__);
        if (($tf !== false && $tf < $need + 8388608) || ($df !== false && $df < $need + (int) @filesize($zip) + 8388608)) return $fail('There is not enough room on the disk to make the backup (it needs about ' . (int) ceil(($need * 2 + 8388608) / 1048576) . ' MB free).');
        if ($need > 1500000000) return $fail('What there is to back up is over 1.5 GB, more than a backup of this kind holds.');
        $entries = []; $mparts = []; $problems = []; $leftOut = [];
        foreach ($parts as $p) {
            [$tmp, $why] = backup_copy_part($p, $tmpDir);
            if ($tmp !== null) {
                $cleanup[] = $tmp;
                $entries[] = ['name' => $p['rel'], 'path' => $tmp, 'size' => (int) filesize($tmp), 'mtime' => $p['mtime']];
                $mparts[] = ['name' => $p['rel'], 'kind' => $p['kind'], 'size' => (int) filesize($tmp), 'sha256' => hash_file('sha256', $tmp), 'state' => 'new', 'why' => ''];
                continue;
            }
            $carry = null;
            if ($oldOk && isset($old[$p['rel']])) { $carry = @tempnam($tmpDir, 'gallery-backup-'); if ($carry !== false) $cleanup[] = $carry; if ($carry === false || !backup_zip_extract($zip, $old, $p['rel'], $carry)) $carry = null; }
            if ($carry !== null) {
                $entries[] = ['name' => $p['rel'], 'path' => $carry, 'size' => (int) filesize($carry), 'mtime' => $p['mtime']];
                $mparts[] = ['name' => $p['rel'], 'kind' => $p['kind'], 'size' => (int) filesize($carry), 'sha256' => hash_file('sha256', $carry), 'state' => 'carried', 'why' => 'it ' . $why . ' now'];
                $problems[] = [$p['rel'], $why, 'carried'];
            } else { $leftOut[] = ['name' => $p['rel'], 'why' => $why]; $problems[] = [$p['rel'], $why, 'left out']; }
        }
        if (!$entries) return $fail('Nothing could be copied soundly, so there is no new backup (the old one is untouched).', $problems);
        $manifest = ['version' => GALLERY_VERSION, 'made' => $now, 'by' => $manual ? 'you' : 'auto', 'parts' => $mparts, 'left_out' => $leftOut];
        $readme = backup_readme($manifest); $mj = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $all = array_merge([['name' => 'README.txt', 'data' => $readme, 'size' => strlen($readme), 'mtime' => $now], ['name' => 'MANIFEST.json', 'data' => $mj, 'size' => strlen($mj), 'mtime' => $now]], $entries);
        $failed = false;
        $okWrite = archive_stream($all, false, function ($b) use ($lock, &$failed) { if (@fwrite($lock, $b) === false) $failed = true; });
        $flushed = @fflush($lock); fclose($lock); $lock = null;
        if (!$okWrite || $failed || !$flushed) return $fail('The backup could not be written (the disk may be full); the old one is untouched.', $problems);
        [$vok, $vn] = backup_zip_verify($work);
        if (!$vok || $vn !== count($all)) return $fail('The new backup did not check out when it was read back, so it was thrown away; the old one is untouched.', $problems);
        if (!@rename($work, $zip)) return $fail('The new backup could not be put in place; the old one is untouched.', $problems);
        $bytes = (int) @filesize($zip);
        backup_state_set(['ok_at' => $now, 'try_at' => $now, 'by' => $manual ? 'you' : 'auto', 'result' => $problems ? 'partial' : 'ok', 'why' => '', 'files' => count($entries), 'bytes' => $bytes, 'problems' => $problems]);
        return ['ok' => true, 'busy' => false, 'why' => '', 'files' => count($entries), 'bytes' => $bytes, 'problems' => $problems];
    } catch (Throwable $e) {
        error_log('gallery: the backup failed: ' . $e->getMessage());
        return $fail('The backup stopped on an error (' . substr($e->getMessage(), 0, 120) . '); the old one is untouched.');
    } finally {
        if (is_resource($lock)) fclose($lock);
        foreach ($cleanup as $c) if (strncmp(basename($c), 'gallery-', 8) === 0 && is_file($c) && !is_link($c)) @unlink($c);
        if (is_file($work)) @unlink($work);
    }
}

/** Is a backup due: none yet or the last good one is a week old, and the last try was not in the last six hours (a try that keeps failing does not run on every request). */
function backup_due() {
    if (!is_file(__DIR__ . '/.gallery-config.php')) return false;   // (a gallery that was never set up has nothing the owner made: a bare drop-in folder writes nothing at all)
    $st = backup_state();
    $have = backup_find() !== null;
    $last = $have ? (int) ($st['ok_at'] ?? 0) : 0;
    $try = (int) ($st['try_at'] ?? 0);
    return time() - $last >= backup_week() && time() - $try >= 6 * 3600;
}

/** Called by every page: when a backup is due, it is made after the page has been sent. */
function backup_after_response($dir, $files) {
    static $set = false;
    if ($set || !backup_due() || !is_writable(__DIR__)) return;
    $set = true;
    register_shutdown_function(function () use ($dir, $files) {
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
        ignore_user_abort(true); @set_time_limit(180);
        try { backup_run(false, $dir, $files); } catch (Throwable $e) { error_log('gallery: the weekly backup failed: ' . $e->getMessage()); }
    });
}

/** What Health shows about the backup: ['have', 'at', 'bytes', 'files', 'result', 'why', 'by', 'problems', 'tried']. */
function backup_status() {
    $st = backup_state(); $zip = backup_find();
    return ['have' => $zip !== null, 'at' => $zip !== null ? (int) ($st['ok_at'] ?? (int) @filemtime($zip)) : 0, 'bytes' => $zip !== null ? (int) @filesize($zip) : 0, 'files' => (int) ($st['files'] ?? 0),
            'result' => (string) ($st['result'] ?? ''), 'why' => (string) ($st['why'] ?? ''), 'by' => (string) ($st['by'] ?? ''), 'problems' => is_array($st['problems'] ?? null) ? $st['problems'] : [], 'tried' => (int) ($st['try_at'] ?? 0)];
}

/* ---- the visitor log: set a damaged one aside, restore the backup's ---- */

/** The log (and the side files SQLite keeps beside it) renamed to .damaged-<date>: never deleted. The next write starts a new log. Returns the new names, or null when there was no log. */
function backup_set_log_aside() {
    $live = visitlog_find();
    if ($live === null) return null;
    @unlink(__DIR__ . '/.gallery-health-cache.php');   // (what Health last found about the log is not true any more)
    $stamp = gmdate('Ymd-His'); $moved = [];
    for ($n = 0; ; $n++) {   // (a name that is taken, even by an earlier one set aside in the same second, is never written over)
        $base = preg_replace('~\.sqlite$~', '.damaged-' . $stamp . ($n ? '-' . $n : '') . '.sqlite', $live);
        if (!file_exists($base) && !file_exists($base . '-wal') && !file_exists($base . '-shm')) break;
    }
    foreach (['', '-wal', '-shm'] as $sfx) {
        $f = $live . $sfx;
        if (is_file($f) && @rename($f, $base . $sfx)) $moved[] = basename($base . $sfx);
    }
    return $moved;
}

/** Puts the backup's copy of the log in place of the live one (which is set aside first). [ok, text]. */
function backup_restore_log() {
    $zip = backup_find();
    if ($zip === null) return [false, 'There is no backup.'];
    $index = backup_zip_index($zip);
    if ($index === null || !backup_zip_verify($zip)[0]) return [false, 'The backup itself does not check out, so nothing was changed.'];
    $name = null; foreach ($index as $n => $m) if (preg_match('~^\.gallery-visits-[0-9a-f]{24}\.sqlite$~', $n)) { $name = $n; break; }
    if ($name === null) return [false, 'The backup has no visitor log in it.'];
    $tmp = __DIR__ . '/.gallery-restore-' . bin2hex(random_bytes(4)) . '.tmp';
    if (!backup_zip_extract($zip, $index, $name, $tmp)) { @unlink($tmp); return [false, 'The log could not be read out of the backup.']; }
    $why = '';
    try { $c = new PDO('sqlite:' . $tmp); $c->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $q = $c->query('PRAGMA quick_check')->fetchAll(PDO::FETCH_COLUMN); $c = null; if (!$q || $q[0] !== 'ok') $why = 'is damaged'; }
    catch (Throwable $e) { $why = 'can\'t be read'; }
    if ($why !== '') { @unlink($tmp); return [false, 'The backup\'s copy of the log ' . $why . ', so nothing was changed.']; }
    backup_set_log_aside();
    if (!@rename($tmp, __DIR__ . '/' . $name)) { @unlink($tmp); return [false, 'The log could not be put in place.']; }
    @chmod(__DIR__ . '/' . $name, 0600);
    @unlink(__DIR__ . '/.gallery-health-cache.php');
    return [true, 'The log from the backup is back. The one it replaced was kept, renamed with .damaged- and the date.'];
}

/* ---- the Health check's buttons ---- */

/** The buttons' requests (JSON; ends the request): backup_run (Back up now), backup_restore_log, backup_new_log (set the log aside), backup_download_prepare (password, then a one-time address). */
function backup_admin_action($action, $dir, $files) {
    if ($action === 'backup_run') {
        $r = backup_run(true, $dir, $files);
        $left = 0; foreach ($r['problems'] as $pr) if ($pr[2] === 'left out') $left++;
        admin_json_exit(['ok' => $r['ok'], 'busy' => $r['busy'], 'problems' => count($r['problems']), 'text' => $r['ok'] ? 'Backed up ' . number_format($r['files']) . ' file' . ($r['files'] === 1 ? '' : 's') . ' (' . ($r['bytes'] >= 1048576 ? round($r['bytes'] / 1048576, 1) . ' MB' : max(1, (int) round($r['bytes'] / 1024)) . ' KB') . ').' . ($r['problems'] ? ' Some parts could not be read soundly: see the Backup row.' : '') : $r['why']]);
    }
    if ($action === 'backup_restore_log') { [$ok, $text] = backup_restore_log(); admin_json_exit(['ok' => $ok, 'text' => $text]); }
    if ($action === 'backup_new_log') {
        $moved = backup_set_log_aside();
        admin_json_exit($moved === null ? ['ok' => false, 'text' => 'There is no visitor log to set aside.'] : ['ok' => true, 'text' => 'The log was set aside (kept, renamed with .damaged- and the date). A new one starts with the next visit.']);
    }
    if ($action === 'backup_download_prepare') {
        if (ADMIN_PASSWORD === '') admin_json_exit(['ok' => false, 'text' => 'There is no admin password.']);
        $ip = client_ip();
        if (!login_throttle_allows($dir, 'admin', $ip)) { $w = login_throttle_wait($dir, 'admin', $ip); admin_json_exit(['ok' => false, 'text' => 'Too many wrong passwords. Try again in ' . $w . ' second' . ($w === 1 ? '' : 's') . '.']); }
        if (!password_matches(ADMIN_PASSWORD, (string) ($_POST['password'] ?? ''))) { login_throttle_record_failure($dir, 'admin', $ip); admin_json_exit(['ok' => false, 'text' => 'That is not the admin password.']); }
        login_throttle_record_success($dir, 'admin', $ip);
        if (backup_find() === null) admin_json_exit(['ok' => false, 'text' => 'There is no backup yet.']);
        $token = bin2hex(random_bytes(16));
        $_SESSION['backup_zip'] = ['token' => $token, 'exp' => time() + 120];
        admin_json_exit(['ok' => true, 'url' => '?admin&backupdownload=' . $token]);
    }
    admin_json_exit(['ok' => false, 'text' => 'Unknown action.']);
}

/** ?admin&backupdownload=TOKEN: the backup file itself, once, for a token backup_download_prepare gave out in the last two minutes (it is read back and checked first). */
function backup_download_route() {
    $t = (string) ($_GET['backupdownload'] ?? '');
    $ok = $_SESSION['backup_zip'] ?? null;
    unset($_SESSION['backup_zip']);
    if (!is_array($ok) || !hash_equals((string) $ok['token'], $t) || (int) $ok['exp'] < time()) { http_response_code(404); return; }
    $zip = backup_find();
    send_sensitive_page_headers();
    if ($zip === null || !backup_zip_verify($zip)[0]) { http_response_code(500); header('Content-Type: text/plain; charset=UTF-8'); echo 'The backup file did not check out, so it was not sent. Make a new one with Back up now.'; return; }
    session_write_close();
    @set_time_limit(0);
    while (ob_get_level() > 0) @ob_end_clean();
    header('Content-Type: application/zip');
    shell_frame_headers();
    header('Content-Disposition: attachment; filename="gallery-backup-' . gmdate('Y-m-d') . '.zip"');
    header('Content-Length: ' . (int) filesize($zip));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') return;
    readfile($zip);
}
