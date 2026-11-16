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

/** JSON for a guarded data file, or false when the data can't be written as JSON (a string in it that is not valid UTF-8). The
 *  callers must stop on false: what they would write otherwise is the guard line and nothing else, in place of the file's data.
 *  JSON_HEX_TAG writes "<" and ">" as \u003C/\u003E, so a caption that happens to contain "<?php" can never put a PHP open tag
 *  into the file: nothing after the guard line is ever parsed as code, even by a server with short tags on. */
function data_file_encode($data) {
    $json = json_encode($data, JSON_HEX_TAG);
    return $json === false ? false : data_file_guard_line() . $json;
}

/** A data file that is a link (to another file, or to nothing) is never read or written: the gallery's own files are plain files in
 *  its own folders, and writing through a link would change a file somewhere else on the server (the same rule as for photos and
 *  folders, which are never followed). */
function data_file_is_link($path) {
    return is_link($path);
}

/** True for a data file with nothing in it (no bytes, or only the guard line): there is nothing a save could lose by replacing it. */
function data_file_is_empty($raw) {
    return trim(data_file_strip_guard($raw)) === '';
}

/** Reads a JSON data file and says how it went: ['missing', $defaultData] when there is no file, ['ok', data merged over the
 *  defaults], or ['broken', $defaultData] when the file is there but can't be read or what is in it isn't JSON (cut short by a full
 *  disk, a half-finished copy, a typo in a hand edit). A file that can't be read is not the same as a file with nothing in it: the
 *  callers that guard something decide what that means (see items_read, json_file_update). */
function json_file_load($path, $defaultData) {
    if (data_file_is_link($path)) return ['broken', $defaultData];   // (never read through a link; for the folder's settings, can't be read means hidden)
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
    if (data_file_is_link($path)) {   // a link is not the gallery's own file: nothing is read from it, nothing is written through it
        $GLOBALS['__data_write_failed'] = true;
        $GLOBALS['__data_refused'][$path] = 'link';
        return $data;
    }
    $existed = file_exists($path);
    // A writer that waited on the lock may be holding a file that was replaced (renamed over) in the meantime; it must start again
    // on the current one, or it would save its change on top of a version that no longer exists.
    // (Every time round means another writer got in and replaced the file while this one waited, so the tries can't run out
    // for as long as writers are making progress: the cap is only there to end a loop that would otherwise never finish.)
    $lockFailed = false;
    for ($try = 0, $fp = false; $try < 200; $try++) {
        $fp = @fopen($path, 'c+');
        if (!$fp) break;
        if (!flock($fp, LOCK_EX)) { fclose($fp); $fp = false; $lockFailed = true; break; }   // (a lock that can't be taken is a save that can't be made safely)
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
        $GLOBALS['__data_write_cause'][$path] = $try >= 200 ? 'busy' : ($lockFailed ? 'lock' : (is_dir($path) ? 'dir' : (file_exists($path) && !(is_readable($path) && is_writable($path)) && is_writable(dirname($path)) ? 'file' : 'folder')));
        return $data;
    }
    $raw = (string) stream_get_contents($fp);
    $decoded = json_decode(data_file_strip_guard($raw), true);
    if (is_array($decoded)) $data = array_merge($data, $decoded);
    $saved = false;
    if ($protect && !is_array($decoded) && $existed && !data_file_is_empty($raw)) {
        $GLOBALS['__data_write_failed'] = true;
        $GLOBALS['__data_refused'][$path] = true;
    } else {
        $modifier($data);
        $encoded = data_file_encode($data);
        if ($encoded === false) {   // text in it that isn't valid UTF-8: the file is left exactly as it is
            $GLOBALS['__data_write_failed'] = true;
            $GLOBALS['__data_refused'][$path] = 'text';
        } elseif (json_file_replace($path, $encoded, $fp, $raw)) {
            $saved = true;
        } else {
            $GLOBALS['__data_write_failed'] = true;   // disk full, quota, ...
            $GLOBALS['__data_write_cause'][$path] = 'space';
        }
    }
    // The empty file that opening it made (there was none before) is taken away again when nothing was saved into it: an empty file
    // is what "can't be read" is made of, and it would hide the folder's photos from visitors and refuse the next save.
    if (!$saved && !$existed && (int) (fstat($fp)['size'] ?? 1) === 0) @unlink($path);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $data;
}

/** Puts $content in $path as a whole without ever cutting the live file short: it is written to a temporary file in the same folder
 *  (named $path, a dot, eight hex digits, $tempEnding), checked to be complete, and renamed over the original (atomic on the same
 *  filesystem). Says 'done'; or 'failed' when a temporary file could be made but the save could not be finished (no room, a quota): the
 *  temporary file is taken away and the live file is exactly as it was; or 'no-temp' when no temporary file could be made (a folder
 *  that allows changing a file but not adding to it), which the callers answer by writing in place. A data file's temporary name
 *  ends in .php (it starts with the guard line, so a leftover is answered with a 404 like the real file); a text file's must not. */
function file_swap_in($path, $content, $tempEnding = '.php') {
    $content = (string) $content;
    $tmp = $path . '.' . bin2hex(random_bytes(4)) . $tempEnding;
    @file_put_contents($tmp, $content);
    if (!file_exists($tmp)) return 'no-temp';
    clearstatcache(true, $tmp);
    if (@filesize($tmp) === strlen($content)) {
        @chmod($tmp, (@fileperms($path) ?: 0644) & 0777);
        if (@rename($tmp, $path)) return 'done';
    }
    @unlink($tmp);
    return 'failed';
}

/** Writes $content into the open handle $fp in place, the way a save has to where no temporary file can be made. $old is what the file
 *  held: nothing is attempted when the disk says there is no room for what the new text adds, and if the write comes out short, $old is
 *  put back. */
function file_write_in_place($path, $content, $fp, $old = '') {
    $content = (string) $content; $old = (string) $old;
    $free = @disk_free_space(dirname($path));
    if ($free !== false && strlen($content) - strlen($old) > $free) return false;
    $wrote = @ftruncate($fp, 0) && @rewind($fp) && @fwrite($fp, $content) === strlen($content) && @fflush($fp);
    if (!$wrote) {   // (the file is empty or cut short now)
        @ftruncate($fp, 0); @rewind($fp); @fwrite($fp, $old); @fflush($fp);
    }
    return $wrote;
}

/** Puts $encoded in the data file $path as a whole, which the caller has open and locked as $fp ($old is what it held). */
function json_file_replace($path, $encoded, $fp, $old = '') {
    $done = file_swap_in($path, $encoded, '.php');
    return $done === 'no-temp' ? file_write_in_place($path, $encoded, $fp, $old) : $done === 'done';
}

/** Writes a file that has no lock of its own (a text file, a state file) as a whole: never cut short by a save that can't be finished,
 *  and never seen half-written. Returns whether it was written. */
function file_write_whole($path, $content, $tempEnding = '.tmp') {
    $done = file_swap_in($path, $content, $tempEnding);
    if ($done !== 'no-temp') return $done === 'done';
    $old = is_file($path) ? (string) @file_get_contents($path) : '';
    $fp = @fopen($path, 'c+');
    if (!$fp) return false;
    $ok = flock($fp, LOCK_EX) && file_write_in_place($path, $content, $fp, $old);
    flock($fp, LOCK_UN); fclose($fp);
    return $ok;
}

/** How a failed save names a folder: "this folder", or, for a place from elsewhere on the server, its real path (the owner may not know where
 *  that is, and it is the folder that has to be made writable). */
function data_write_failure_place($dir, $folderReal) {
    $own = rtrim((string) (realpath($dir) ?: $dir), '/') . '/';
    $real = rtrim((string) (realpath((string) $folderReal) ?: $folderReal), '/');
    return ($real !== '' && strpos($real . '/', $own) !== 0) ? 'the folder ' . $real : 'this folder';
}

/** What to tell the owner when a save did not happen: the data file that could not be read (nothing was changed), or the folder not
 *  being writable. $folder is how the sentence says where ("this folder"). */
function data_write_failure_text($folder = 'this folder') {
    if (!empty($GLOBALS['__data_refused'])) {
        $path = (string) array_key_first($GLOBALS['__data_refused']);
        $why = $GLOBALS['__data_refused'][$path];
        if ($why === 'link') {
            return 'Nothing was saved: ' . basename($path) . ' in ' . $folder . ' is a link to a file somewhere else, which the gallery won\'t use (it would change that other file). Replace it with a real file, or delete the link, then try again.';
        }
        if ($why === 'text') {
            return 'Nothing was saved: something in what you changed contains a character the gallery can\'t store (it isn\'t valid text), so ' . basename($path) . ' was left exactly as it is. Retype that part, or paste it as plain text, and save again.';
        }
        return 'Nothing was saved: a settings file in ' . $folder . ' can\'t be read (it may have been cut short or edited by hand), so it was left exactly as it is. Restore .gallery-items.php there from a backup, or delete it to start that folder fresh. Until then its photos stay hidden from visitors. The Health check in Settings names it.';
    }
    $cause = (array) ($GLOBALS['__data_write_cause'] ?? []);
    $cpath = (string) array_key_first($cause); $cname = basename($cpath); $why = $cause[$cpath] ?? '';
    if ($why === 'busy') return 'Nothing was saved: other saves kept changing ' . $cname . ' in ' . $folder . ' for too long, so this one gave up without changing it. Nothing was lost. Wait a moment and save again.';
    if ($why === 'lock') return 'Nothing was saved: the server would not let the gallery lock ' . $cname . ' in ' . $folder . ', which it needs to save safely, so the file was left as it is. Ask your host whether file locking is turned off there, then try again.';
    if ($why === 'dir') return 'Nothing was saved: ' . $cname . ' in ' . $folder . ' is a folder, not a file, so the gallery can\'t keep its settings there. Move or remove that folder, then try again.';
    if ($why === 'file') return 'Nothing was saved: ' . $cname . ' in ' . $folder . ' can\'t be opened by the web server (its permissions don\'t allow it), so it was left as it is. Give the web server\'s user read and write permission on that file (on many hosts: chmod 644 or 664, owned by that user), then try again.';
    if ($why === 'space') return 'Nothing was saved: there was no room to write ' . $cname . ' in ' . $folder . ' (the disk or the account\'s storage allowance is full), so it was left exactly as it is. Free some space, then try again.';
    return 'Nothing was saved: ' . $folder . ' isn\'t writable by the web server. Give the web server\'s user write permission on it (on many hosts: chmod 755 or 775 on the folder, owned by that user), then try again.';
}

