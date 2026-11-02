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

/** Reads a JSON data file and says how it went: ['missing', $defaultData] when there is no file, ['ok', data merged over the
 *  defaults], or ['broken', $defaultData] when the file is there but can't be read or what is in it isn't JSON (cut short by a full
 *  disk, a half-finished copy, a typo in a hand edit). A file that can't be read is not the same as a file with nothing in it: the
 *  callers that guard something decide what that means (see items_read, json_file_update). */
function json_file_load($path, $defaultData) {
    if (!file_exists($path)) return ['missing', $defaultData];
    $raw = @file_get_contents($path);
    $decoded = $raw === false ? null : json_decode(data_file_strip_guard($raw), true);
    if (!is_array($decoded)) return ['broken', $defaultData];
    return ['ok', array_merge($defaultData, $decoded)];
}

/** Reads a JSON file, merging it over $defaultData (a missing or broken file yields $defaultData untouched). */
function json_file_read($path, $defaultData) {
    return json_file_load($path, $defaultData)[1];
}

/** Changes a JSON data file under a lock and returns the new data. The new content is written to a temporary file next to it and
 *  renamed over it, so a reader (which takes no lock) sees the whole old file or the whole new one, never a half-written or empty
 *  one. With $protect (the owner's own data: the folder settings and the share links), a file that exists but can't be read is left
 *  exactly as it is and nothing is saved ($GLOBALS['__data_refused'] names it): writing over it would replace something the owner may
 *  still be able to restore with an almost empty file. The other files (sign-in throttle, logs) are rebuilt instead. */
function json_file_update($path, $defaultData, $modifier, $protect = false) {
    $data = $defaultData;
    $existed = file_exists($path);
    // A writer that waited on the lock may be holding a file that was replaced (renamed over) in the meantime; it must start again
    // on the current one, or it would save its change on top of a version that no longer exists.
    for ($try = 0, $fp = false; $try < 5; $try++) {
        $fp = @fopen($path, 'c+');
        if (!$fp || !flock($fp, LOCK_EX)) break;
        clearstatcache(true, $path);
        $here = @stat($path); $mine = fstat($fp);
        if ($here && $mine && $here['ino'] === $mine['ino'] && $here['dev'] === $mine['dev']) break;
        fclose($fp); $fp = false;
    }
    if (!$fp) {
        // Usually the folder isn't writable by the web server. Remember it
        // so the admin can say so instead of reporting a save that never
        // happened (see run_admin_route).
        $GLOBALS['__data_write_failed'] = true;
        return $data;
    }
    $raw = stream_get_contents($fp);
    $decoded = json_decode(data_file_strip_guard($raw), true);
    if (is_array($decoded)) $data = array_merge($data, $decoded);
    if ($protect && !is_array($decoded) && $existed) {
        $GLOBALS['__data_write_failed'] = true;
        $GLOBALS['__data_refused'][$path] = true;
    } else {
        $modifier($data);
        if (!json_file_replace($path, data_file_encode($data), $fp)) $GLOBALS['__data_write_failed'] = true;   // disk full, quota, ...
    }
    flock($fp, LOCK_UN);
    fclose($fp);
    return $data;
}

/** Puts $encoded in $path as a whole: written to a temporary file in the same folder, then renamed over the original (atomic on
 *  the same filesystem). If a temporary file can't be made (a folder that allows changing the file but not adding to it) it writes
 *  into the open handle $fp instead, as before. */
function json_file_replace($path, $encoded, $fp) {
    $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.php';   // (ends in .php and starts with the guard line, so a leftover is answered with a 404 like the real file)
    $ok = false;
    if (!is_link($path) && @file_put_contents($tmp, $encoded) === strlen($encoded)) {   // (a symlinked data file is written through, so the link stays a link)
        @chmod($tmp, (@fileperms($path) ?: 0644) & 0777);
        $ok = @rename($tmp, $path);
    }
    if ($ok) return true;
    @unlink($tmp);
    ftruncate($fp, 0);
    rewind($fp);
    $wrote = fwrite($fp, $encoded) === strlen($encoded);
    fflush($fp);
    return $wrote;
}

/** What to tell the owner when a save did not happen: the data file that could not be read (nothing was changed), or the folder not
 *  being writable. $folder is how the sentence says where ("this folder"). */
function data_write_failure_text($folder = 'this folder') {
    if (!empty($GLOBALS['__data_refused'])) {
        return 'Nothing was saved: a settings file in ' . $folder . ' can\'t be read (it may have been cut short or edited by hand), so it was left exactly as it is. Restore .gallery-items.php there from a backup, or delete it to start that folder fresh. Until then its photos stay hidden from visitors. The Health check in Settings names it.';
    }
    return 'Nothing was saved: ' . $folder . ' isn\'t writable by the web server. Give the web server\'s user write permission on it (on many hosts: chmod 755 or 775 on the folder, owned by that user), then try again.';
}

