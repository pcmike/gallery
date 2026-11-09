/* -----------------------------------------------------------------------
 * "Files this gallery has made": see them, take a copy, remove them.
 *
 * ONE function decides what the gallery made (owned_plan). The list the owner reads, the ZIP and the delete all come from it, so the
 * delete can only ever touch what was shown. The rules, because a mistake here could cost someone their photos:
 *   - names are an exact list plus a few strict patterns (never a wildcard, never "everything starting with .gallery-");
 *   - only the direct children of a folder the gallery itself scanned (the gallery's own folder, or one it shows, mapped ones too);
 *   - a regular file, never a link; a data file must start with the gallery's own guard line (the settings file: with "<?php");
 *   - the delete unlinks one path at a time, never removes a folder except an empty .gallery-cache, and stops at the first thing that
 *     does not check out, deleting nothing if the plan is not clean.
 * ----------------------------------------------------------------------- */

/** The files the gallery makes beside index.php, by exact name: name => kind. */
function owned_top_names() {
    return ['.gallery-config.php' => 'settings', '.gallery-links.php' => 'links', '.gallery-collections.php' => 'collections', '.gallery-secret.php' => 'secret', '.gallery-stay.php' => 'signin', '.gallery-browsers.php' => 'signin', '.gallery-browsers-log.php' => 'signin',
            '.gallery-login-attempts.php' => 'signin', '.gallery-health.php' => 'health', '.gallery-update.php' => 'update', '.gallery-previous.php' => 'previous', '.gallery-geo.php' => 'geo', '.gallery-geo-state.php' => 'geo'];
}

/** The kind of a file name, or '' when the gallery makes no file with that name. $isTop: the folder is the gallery's own (the one index.php is in). */
function owned_kind_of_name($name, $isTop) {
    if ($isTop) {
        $top = owned_top_names();
        if (isset($top[$name])) return $top[$name];
        if (preg_match('/^\.gallery-linklog-[0-9a-f]{10}(?:-items|-q)?\.php$/D', $name)) return 'oldlogs';   // (what versions before 2.6.5 kept the visitor logs in)
        if (preg_match('/^\.gallery-visits-[0-9a-f]{24}\.sqlite(?:-wal|-shm)?$/D', $name)) return 'visits';   // (the visitor log and the side files SQLite keeps beside it)
        if (preg_match('/^\.gallery-geo\.[0-9a-f]{8}\.php$/D', $name)) return 'geo';   // (a download that was cut off)
        if (preg_match('/^\.gallery-signin-[0-9a-f]{12}\.php$/D', $name)) return 'signin';   // (one signed-in browser's address history)
    }
    if ($name === '.gallery-items.php') return 'items';
    if ($name === '.gallery-stats.php' || $name === '.gallery-stats.sqlite' || $name === '.gallery-stats.json') return 'stats';
    if (preg_match('/^\.gallery-items\.before-tidy-\d{8}-\d{6}\.php$/D', $name)) return 'backups';
    return '';
}

/** A thumbnail or clip in a .gallery-cache folder: 32 hex digits and .jpg or .mp4 (or one still being written). */
function owned_is_thumb_name($name) { return preg_match('/^[0-9a-f]{32}\.(?:jpg|mp4)(?:\.tmp[0-9a-f]{6})?$/D', $name) === 1; }

/** What each kind is, for the list: [label, where, what it is for, what happens if it is deleted]. */
function owned_kind_info() {
    return [
        'settings' => ['Settings', 'beside index.php', 'The choices made in Settings (and any set by hand in this file).', 'Back to the defaults; the admin password is gone.'],
        'links' => ['Share links', 'beside index.php', 'Every share link: what it covers, how long it lasts, its name.', 'Every share link stops working.'],
        'visits' => ['Visitor log', 'beside index.php', 'Who opened the gallery and each share link, what they looked at and when (a SQLite file).', 'The log is gone; it starts again from nothing.'],
        'oldlogs' => ['Older visitor logs', 'beside index.php', 'The logs that versions before 2.6.5 kept in these files. Nothing reads them any more.', 'They are gone; nothing else changes.'],
        'collections' => ['Collections', 'beside index.php', 'The collections you made: where each one lives and which folders and photos it holds.', 'Every collection is gone; the folders and photos in them are untouched.'],
        'secret' => ['Install secret', 'beside index.php', 'Keeps sign-ins and private-view cookies from being forged.', 'Everyone signed in is signed out.'],
        'signin' => ['Sign-in records', 'beside index.php', 'Who is signed in (one token each), the address history of each browser, and the wrong-password throttle.', 'Everyone is signed out and the histories are gone; the throttle starts again.'],
        'geo' => ['Country file', 'beside index.php', 'The list of address ranges per country (from DB-IP) that says which country an address is in, and when it was fetched.', 'Countries are no longer looked up; turning the option on again fetches it again.'],
        'health' => ['Version record', 'beside index.php', 'Which version last ran here.', 'Made again by the next visit.'],
        'update' => ['New-version check', 'beside index.php', 'The newest version GitHub last reported, and when this gallery was last updated.', 'Made again by the next check.'],
        'previous' => ['Earlier version kept', 'beside index.php', 'The index.php that Update now replaced, kept so Go back can put it in again.', 'Go back is no longer possible; the gallery itself is not affected.'],
        'items' => ['Captions, locations, private marks, folder names', 'in each folder', 'What you typed or chose for the photos and folders in it.', 'All of it is lost.'],
        'stats' => ['View counts', 'in each folder', 'How many times each photo was looked at.', 'Counts start again from 0.'],
        'backups' => ['Tidy-up backups', 'in each folder', 'Copies kept when Tidy up changed a folder\'s data.', 'The backups are gone.'],
        'meta' => ['What the files say about themselves', 'in each .gallery-cache', 'When each photo was taken and where, read once.', 'Read again as photos are shown (Prepare media).'],
        'thumbs' => ['Thumbnails and video clips', 'in each .gallery-cache', 'Small copies that make pages fast and let iPhone photos show.', 'Made again as photos are shown (Prepare media).'],
    ];
}

/** Does this file really look like the gallery's own? A regular file, not a link, directly inside $folderReal, whose name is one the gallery makes
 *  and whose first line is the gallery's guard line (the settings file: any PHP file; a thumbnail: its name is the test). */
function owned_verify($path, $folderReal, $kind, $isTop) {
    $name = basename($path);
    if (dirname($path) !== $folderReal) return false;
    if (is_link($path) || !is_file($path)) return false;
    if ($kind === 'thumbs') return owned_is_thumb_name($name) && basename($folderReal) === '.gallery-cache';
    if ($kind === 'meta') return $name === MEDIA_META_FILE && basename($folderReal) === '.gallery-cache' && owned_head_ok($path, true);
    if (owned_kind_of_name($name, $isTop) !== $kind) return false;
    if (substr($name, -4) === '.php') return owned_head_ok($path, $kind !== 'settings');
    return true;   // (a .sqlite or .json file of view counts has no guard line)
}

function owned_head_ok($path, $strict) {
    $fh = @fopen($path, 'rb'); if (!$fh) return false;
    $guard = data_file_guard_line();
    $head = (string) fread($fh, strlen($guard));
    fclose($fh);
    return $strict ? $head === $guard : strncmp($head, '<?php', 5) === 0;
}

/** Every file the gallery has made, from the gallery's own folder and every folder it shows (a mapped folder under its name in the gallery).
 *  Returns ['entries' => [{rel, path, kind, size, mtime, folder, top}], 'caches' => [{rel, path, thumbs, bytes}], 'unknown' => [paths that look like
 *  ours (start with ".gallery-") but are not on the list: they are never touched], 'fingerprint' => string]. */
function owned_plan($dir, $files) {
    $dir = rtrim((string) (realpath($dir) ?: $dir), '/');
    $folders = ['' => $dir];
    foreach (all_folder_paths($files) as $rel) {
        $real = resolve_real_path($dir, $rel);
        if (is_string($real) && $real !== '' && is_dir($real) && !is_link($real)) $folders[$rel] = rtrim($real, '/');
    }
    $entries = []; $caches = []; $unknown = [];
    foreach ($folders as $rel => $real) {
        $top = $rel === '';
        foreach ((array) @scandir($real) as $name) {
            if ($name === '.' || $name === '..' || strncmp($name, '.gallery-', 9) !== 0) continue;
            $path = $real . '/' . $name;
            if ($name === '.gallery-cache') {
                if (is_link($path) || !is_dir($path)) continue;
                $c = ['rel' => ($rel === '' ? '' : $rel . '/') . '.gallery-cache', 'path' => $path, 'thumbs' => 0, 'bytes' => 0];
                foreach ((array) @scandir($path) as $cn) {
                    if ($cn === '.' || $cn === '..') continue;
                    $cp = $path . '/' . $cn;
                    if (owned_is_thumb_name($cn)) { if (owned_verify($cp, $path, 'thumbs', $top)) { $c['thumbs']++; $c['bytes'] += (int) @filesize($cp); } continue; }
                    if ($cn === MEDIA_META_FILE && owned_verify($cp, $path, 'meta', $top)) $entries[] = ['rel' => $c['rel'] . '/' . $cn, 'path' => $cp, 'kind' => 'meta', 'size' => (int) @filesize($cp), 'mtime' => (int) @filemtime($cp), 'folder' => $path, 'top' => $top];
                }
                $caches[] = $c;
                continue;
            }
            $kind = owned_kind_of_name($name, $top);
            if ($kind !== '' && owned_verify($path, $real, $kind, $top)) $entries[] = ['rel' => ($rel === '' ? '' : $rel . '/') . $name, 'path' => $path, 'kind' => $kind, 'size' => (int) @filesize($path), 'mtime' => (int) @filemtime($path), 'folder' => $real, 'top' => $top];
            elseif ($name !== '.gallery-setup' && is_file($path)) $unknown[] = ($rel === '' ? '' : $rel . '/') . $name;
        }
    }
    usort($entries, function ($a, $b) { return strcmp($a['rel'], $b['rel']); });
    $lines = [];
    foreach ($entries as $e) $lines[] = $e['kind'] . '|' . $e['rel'] . '|' . $e['size'];
    foreach ($caches as $c) $lines[] = 'cache|' . $c['rel'];
    $mapped = 0;   // (how many of these files lie in a place from elsewhere on the server: another gallery may use them too)
    foreach ($entries as $e) if (strpos($e['path'], $dir . '/') !== 0) $mapped++;
    return ['entries' => $entries, 'caches' => $caches, 'unknown' => $unknown, 'folders' => count($folders), 'mapped' => $mapped, 'fingerprint' => hash('sha256', implode("\n", $lines))];
}

/** The list for the page: per kind, how many files, how many bytes. */
function owned_summary($plan) {
    $info = owned_kind_info(); $rows = [];
    foreach ($info as $k => $i) $rows[$k] = ['kind' => $k, 'label' => $i[0], 'where' => $i[1], 'what' => $i[2], 'ifgone' => $i[3], 'files' => 0, 'bytes' => 0];
    foreach ($plan['entries'] as $e) { $rows[$e['kind']]['files']++; $rows[$e['kind']]['bytes'] += $e['size']; }
    foreach ($plan['caches'] as $c) { $rows['thumbs']['files'] += $c['thumbs']; $rows['thumbs']['bytes'] += $c['bytes']; }
    $files = 0; $bytes = 0; $thumbs = $rows['thumbs']['files'];
    foreach ($rows as $k => $r) { if ($k !== 'thumbs') $files += $r['files']; $bytes += $r['bytes']; }
    return ['rows' => array_values(array_filter($rows, function ($r) { return $r['files'] > 0; })), 'files' => $files, 'thumbs' => $thumbs, 'bytes' => $bytes, 'thumbBytes' => $rows['thumbs']['bytes'],
            'unknown' => array_slice($plan['unknown'], 0, 20), 'folders' => $plan['folders'], 'mapped' => $plan['mapped'], 'fingerprint' => $plan['fingerprint'],
            'paths' => array_slice(array_column($plan['entries'], 'rel'), 0, 300), 'pathsMore' => max(0, count($plan['entries']) - 300)];
}

/** The text inside the ZIP that says what each file is and how to put them back. */
function owned_readme($plan, $withThumbs) {
    $t = "THE FILES THIS GALLERY MADE\r\n\r\nMade " . gmdate('Y-m-d H:i') . " UTC by gallery " . GALLERY_VERSION . ".\r\n\r\n";
    $t .= "These are laid out like they are on the server, starting from the folder index.php is in. A folder shown elsewhere on the server (a\r\n\"mapped\" folder) appears under the name it has in the gallery.\r\n\r\n";
    $t .= "HOW TO PUT THEM BACK: unzip this file over the same folder (the one index.php is in), so each file lands next to its photos.\r\nFor a mapped folder, put its files in the real folder it comes from:\r\n";
    $maps = [];
    foreach (gallery_mounts() as $name => $real) $maps[] = '  ' . $name . '/  =>  ' . $real;
    $t .= ($maps ? implode("\r\n", $maps) : '  (none)') . "\r\n\r\n";
    $t .= "WHAT IS IN HERE (keep this file private: it holds password hashes, the install secret, share-link addresses and visitor IP addresses):\r\n";
    $info = owned_kind_info();
    $by = [];
    foreach ($plan['entries'] as $e) $by[$e['kind']][] = $e['rel'];
    foreach ($info as $k => $i) {
        if ($k === 'thumbs') { if ($withThumbs) $t .= "\r\n- " . $i[0] . ' (' . $i[2] . ") in the .gallery-cache folders.\r\n"; continue; }
        if (empty($by[$k])) continue;
        $t .= "\r\n- " . $i[0] . ': ' . $i[2] . "\r\n";
        foreach (array_slice($by[$k], 0, 12) as $r) $t .= '    ' . $r . "\r\n";
        if (count($by[$k]) > 12) $t .= '    and ' . (count($by[$k]) - 12) . " more\r\n";
    }
    return $t . "\r\nYour photos, videos and notes (.md files) are not in this file, and the gallery never changes them.\r\n";
}

/** A data file copied whole to a temporary file, under a shared lock (a writer holds an exclusive one while it rewrites the file), or
 *  null. The copy is made at once, from the disk to the disk, not paced by the person downloading, so a file that is being written to
 *  neither holds a writer up nor changes size while the ZIP is sent; nothing is held in memory, whatever the size. */
function owned_snapshot($path) {
    $src = @fopen($path, 'rb'); if (!$src) return null;
    $tmp = @tempnam(sys_get_temp_dir(), 'gallery-files-');
    $dst = $tmp === false ? false : @fopen($tmp, 'wb');
    $copied = false;
    if ($dst && flock($src, LOCK_SH)) { $copied = stream_copy_to_stream($src, $dst); flock($src, LOCK_UN); }
    fclose($src);
    $closed = $dst ? fclose($dst) : false;
    clearstatcache(true, (string) $tmp);
    if ($tmp !== false && $copied !== false && $closed && @filesize($tmp) === $copied) return $tmp;
    if ($tmp !== false) owned_zip_forget([['temp' => true, 'path' => $tmp]]);
    return null;
}

/** Removes the temporary copies owned_zip_entries() made (only entries it marked 'temp', and only files named gallery-files-…). */
function owned_zip_forget($entries) {
    foreach ($entries as $e) {
        if (empty($e['temp']) || strncmp(basename($e['path']), 'gallery-files-', 14) !== 0) continue;
        if (is_file($e['path']) && !is_link($e['path'])) @unlink($e['path']);
    }
}

/** What the ZIP holds: the README first, every data file of the plan (each one a snapshot on disk, of any size), and the thumbnails when
 *  asked (sent straight from where they are). Returns null when a file cannot be copied whole (a copy that is not whole is no copy); the
 *  entries marked 'temp' are copies to remove with owned_zip_forget() when the ZIP has been sent. */
function owned_zip_entries($plan, $withThumbs) {
    $readme = owned_readme($plan, $withThumbs);
    $out = [['name' => 'README.txt', 'data' => $readme, 'size' => strlen($readme), 'mtime' => time()]];
    foreach ($plan['entries'] as $e) {
        $tmp = owned_snapshot($e['path']);
        if ($tmp === null) { owned_zip_forget($out); return null; }
        $out[] = ['name' => $e['rel'], 'path' => $tmp, 'size' => (int) @filesize($tmp), 'mtime' => $e['mtime'], 'temp' => true];
    }
    if ($withThumbs) foreach ($plan['caches'] as $c) {
        foreach ((array) @scandir($c['path']) as $cn) {
            if (!owned_is_thumb_name($cn) || !owned_verify($c['path'] . '/' . $cn, $c['path'], 'thumbs', false)) continue;
            $sz = (int) @filesize($c['path'] . '/' . $cn);
            $out[] = ['name' => $c['rel'] . '/' . $cn, 'path' => $c['path'] . '/' . $cn, 'size' => $sz, 'mtime' => (int) @filemtime($c['path'] . '/' . $cn)];
        }
    }
    return $out;
}

/** Delete exactly the plan. Returns ['ok' => bool, 'deleted' => n, 'thumbs' => n, 'left' => [paths], 'text' => message]. Fails closed: when any
 *  file in the plan does not pass its check again, nothing at all is deleted. */
function owned_delete($plan) {
    foreach ($plan['entries'] as $e) {
        if (!owned_verify($e['path'], $e['folder'], $e['kind'], $e['top'])) return ['ok' => false, 'deleted' => 0, 'thumbs' => 0, 'left' => [], 'text' => 'Something in the list did not check out, so nothing was deleted: ' . $e['rel']];
    }
    // order: everything in the folders and the thumbnails first, then the gallery's own data, the settings file last
    $order = ['thumbs', 'meta', 'items', 'stats', 'backups', 'links', 'collections', 'signin', 'geo', 'update', 'previous', 'health', 'secret', 'settings'];
    $deleted = 0; $thumbs = 0; $left = [];
    $byKind = [];
    foreach ($plan['entries'] as $e) $byKind[$e['kind']][] = $e;
    foreach ($order as $kind) {
        if ($kind === 'thumbs') {
            foreach ($plan['caches'] as $c) {
                foreach ((array) @scandir($c['path']) as $cn) {
                    if (!owned_is_thumb_name($cn)) continue;
                    $cp = $c['path'] . '/' . $cn;
                    if (!owned_verify($cp, $c['path'], 'thumbs', false)) continue;
                    if (@unlink($cp)) $thumbs++; else $left[] = $c['rel'] . '/' . $cn;
                }
            }
            continue;
        }
        foreach ($byKind[$kind] ?? [] as $e) {
            if ($left) break 2;   // something could not be removed: stop here, the settings file (last) stays, and the page says what is left
            if (!owned_verify($e['path'], $e['folder'], $e['kind'], $e['top'])) { $left[] = $e['rel']; continue; }
            if (@unlink($e['path'])) $deleted++; else $left[] = $e['rel'];
        }
    }
    // an emptied .gallery-cache folder goes; a folder with anything else in it stays (rmdir refuses a folder that is not empty)
    foreach ($plan['caches'] as $c) { if (!$left && basename($c['path']) === '.gallery-cache' && !is_link($c['path'])) @rmdir($c['path']); }
    $ok = !$left;
    return ['ok' => $ok, 'deleted' => $deleted, 'thumbs' => $thumbs, 'left' => array_slice($left, 0, 20), 'text' => $ok ? 'Deleted ' . $deleted . ' file' . ($deleted === 1 ? '' : 's') . ' and ' . $thumbs . ' thumbnail' . ($thumbs === 1 ? '' : 's') . '.' : 'Stopped: ' . count($left) . ' file' . (count($left) === 1 ? '' : 's') . ' could not be removed, and the settings file was kept.'];
}

/** The Settings group's POST actions: files_list (what is there), files_zip_prepare (password, then a one-time address for the ZIP) and
 *  files_delete (password, the word DELETE, the list as it was shown, and either a copy taken or "no copy"). JSON; ends the request. */
function files_admin_action($action, $dir, $files) {
    $bad = function ($text) { admin_json_exit(['ok' => false, 'text' => $text]); };
    if (ADMIN_PASSWORD === '') $bad('There is no admin password.');
    $plan = owned_plan($dir, $files);
    if ($action === 'files_list') admin_json_exit(['ok' => true] + owned_summary($plan));
    if ($action !== 'files_zip_prepare' && $action !== 'files_delete') $bad('Unknown action.');
    $ip = client_ip();
    if (!login_throttle_allows($dir, 'admin', $ip)) $bad('Too many wrong passwords. Wait a few minutes and try again.');
    if (!password_matches(ADMIN_PASSWORD, (string) ($_POST['password'] ?? ''))) { login_throttle_record_failure($dir, 'admin', $ip); $bad('That is not the admin password.'); }
    login_throttle_record_success($dir, 'admin', $ip);
    if ($action === 'files_zip_prepare') {
        $sum = owned_summary($plan);
        $need = $sum['bytes'] - $sum['thumbBytes'];   // (the data files are copied to the server's temporary folder while the ZIP is sent; the thumbnails are not)
        $room = @disk_free_space(sys_get_temp_dir());
        if ($room !== false && $room < $need + 16 * 1048576) $bad('There is not enough room in the server\'s temporary folder (' . sys_get_temp_dir() . ') to make the copy: it needs about ' . (int) ceil(($need + 16 * 1048576) / 1048576) . ' MB free.');
        $token = bin2hex(random_bytes(16));
        $_SESSION['files_zip'] = ['token' => $token, 'exp' => time() + 120, 'thumbs' => ($_POST['thumbs'] ?? '') === '1'];
        admin_json_exit(['ok' => true, 'url' => '?admin&filesdownload=' . $token]);
    }
    // files_delete
    if ((string) ($_POST['typed'] ?? '') !== 'DELETE') $bad('Type DELETE (in capitals) to go on.');
    if (!hash_equals($plan['fingerprint'], (string) ($_POST['fingerprint'] ?? ''))) $bad('The list has changed since you looked at it, so nothing was deleted. Look at it again.');
    $copied = (int) ($_SESSION['files_zip_done'] ?? 0) > time() - 3600;
    if (!$copied && ($_POST['nocopy'] ?? '') !== '1') $bad('Download a copy first, or say that you do not want one.');
    $res = owned_delete($plan);
    if ($res['ok']) $_SESSION = [];
    admin_json_exit($res);
}

/** ?admin&filesdownload=TOKEN: the ZIP, once, for a token files_zip_prepare gave out in the last two minutes. */
function files_download_route($dir, $files) {
    $t = (string) ($_GET['filesdownload'] ?? '');
    $ok = $_SESSION['files_zip'] ?? null;
    unset($_SESSION['files_zip']);
    if (!is_array($ok) || !hash_equals((string) $ok['token'], $t) || (int) $ok['exp'] < time()) { http_response_code(404); return; }
    send_sensitive_page_headers();
    $plan = owned_plan($dir, $files);
    $entries = owned_zip_entries($plan, !empty($ok['thumbs']));
    if ($entries === null) { http_response_code(500); header('Content-Type: text/plain; charset=UTF-8'); echo 'These files could not all be copied whole (is there room in the server\'s temporary folder?), so no ZIP was made.'; return; }
    register_shutdown_function('owned_zip_forget', $entries);   // (the copies go when the request ends, however it ends)
    $sizes = array_column($entries, 'size'); $names = array_column($entries, 'name');
    $zip64 = count($entries) >= 0xFFFF || max($sizes) >= 0xFFFFFFFF || archive_total_size($sizes, $names, false) >= 0xFFFFFFF0;
    $total = archive_total_size($sizes, $names, $zip64);
    $slot = archive_take_slot();
    if ($slot === null) { http_response_code(503); header('Retry-After: 30'); header('Content-Type: text/plain; charset=UTF-8'); echo 'An archive is already being made. Try again in a minute.'; return; }
    $_SESSION['files_zip_done'] = time();
    session_write_close();
    @set_time_limit(0);
    ignore_user_abort(false);
    @ini_set('zlib.output_compression', '0');
    while (ob_get_level() > 0) @ob_end_clean();
    header('Content-Type: application/zip');
    shell_frame_headers();   // (a download may sit in this site's own admin frame, as it does on a desktop; no other site may frame it)
    header('Content-Disposition: attachment; filename="gallery-files-' . gmdate('Y-m-d') . '.zip"');
    header('Content-Length: ' . $total);
    header('X-Content-Type-Options: nosniff');
    header('X-Accel-Buffering: no');
    header('Cache-Control: no-store');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') return;
    archive_stream($entries, $zip64);
}


// KIT-BEGIN
/** The pieces the owner shares with: a QR code drawn in the browser (with the gallery's icon in the middle), Copy / Share… / QR code for a
 *  link, the large white code, Copy image and Save image, and the short "Share this folder / photo" form. Written out on the owner's own
 *  gallery pages and on the admin page, when share links are switched on; nobody else gets any of it. */
function render_share_kit() {
    if (!ENABLE_SHARE_LINKS) return '';
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    $cfg = ['probe' => ($base === '' ? '/' : $base . '/') . LINK_PROBE_TOKEN, 'admin' => '?admin', 'closed' => gallery_is_closed(), 'defaultUses' => (int) DEFAULT_LINK_USES];
    $css = <<<'KIT_CSS'
  .gs-panel { margin-top: 10px; margin-bottom: 14px; }
  .gs-addrbox { position: fixed; left: 12px; right: 12px; bottom: 64px; z-index: 90; display: flex; gap: 8px; align-items: center; padding: 8px; background: #18181b; border: 1px solid #3a3a42; border-radius: 10px; }
  .gs-addrbox input { flex: 1 1 auto; min-width: 0; box-sizing: border-box; min-height: 40px; padding: 6px 10px; background: #0f0f10; color: #eaeaea; border: 1px solid #34343a; border-radius: 8px; font: inherit; }
  .gs-btns { display: flex; flex-wrap: wrap; gap: 8px; }
  .gs-btn { display: inline-flex; align-items: center; gap: 6px; box-sizing: border-box; min-height: 36px; padding: 7px 13px; background: #232327; border: 1px solid #3a3a42; border-radius: 9px; color: #eaeaea; font: inherit; font-size: 0.92rem; font-weight: 600; text-decoration: none; cursor: pointer; }
  .gs-btn:hover:not(:disabled) { background: #2c2c31; border-color: #4a4a54; }
  .gs-btn:disabled { opacity: 0.5; cursor: default; }
  .gs-btn.gs-bad { color: #ffb4b6; border-color: #ed4245; }
  .gs-btn[aria-expanded=true] { border-color: #5865f2; background: #20233a; color: #cfd3ff; }
  .gs-btn .ico { width: 16px; height: 16px; vertical-align: 0; }
  .gs-primary { background: #5865f2; border-color: #5865f2; color: #fff; }
  .gs-primary:hover:not(:disabled) { background: #6b77f0; border-color: #6b77f0; }
  .gs-light { background: #ececf0; border-color: #ececf0; color: #111; }
  .gs-light:hover:not(:disabled) { background: #fff; border-color: #fff; }
  .gs-dark { background: #111; border-color: #111; color: #fff; }
  .gs-dark:hover:not(:disabled) { background: #000; border-color: #000; }
  .gs-toast { position: fixed; left: 50%; bottom: calc(24px + env(safe-area-inset-bottom, 0px)); transform: translateX(-50%); z-index: 100002; max-width: calc(100vw - 32px); padding: 10px 16px; background: #26262b; border: 1px solid #3a3a42; border-radius: 10px; color: #eaeaea; font-size: 0.92rem; box-shadow: 0 6px 24px rgba(0,0,0,0.5); }
  .gs-toast.err { border-color: #ed4245; color: #ffb4b6; }
  .gs-toast[hidden] { display: none; }
  .gs-modal { position: fixed; inset: 0; z-index: 100000; display: flex; align-items: center; justify-content: center; padding: 12px; background: rgba(0,0,0,0.65); overflow: auto; }
  .gs-card { box-sizing: border-box; width: 480px; max-width: 100%; max-height: calc(100vh - 24px); overflow: auto; padding: 20px; background: #1a1a1d; border: 1px solid #323238; border-radius: 14px; color: #eaeaea; box-shadow: 0 20px 60px rgba(0,0,0,0.6); text-align: left; font-size: 0.95rem; line-height: 1.45; }
  .gs-title { margin: 0 0 12px; font-size: 1.15rem; font-weight: 700; }
  .gs-f { display: block; margin: 0 0 14px; }
  .gs-k { display: block; margin: 0 0 6px; font-size: 0.85rem; font-weight: 700; color: #cfcfd6; }
  .gs-name, .gs-url { box-sizing: border-box; width: 100%; padding: 10px 12px; background: #101012; border: 1px solid #34343a; border-radius: 9px; color: #fff; font: inherit; }
  .gs-name:focus, .gs-url:focus { outline: 2px solid #5865f2; outline-offset: 0; border-color: #5865f2; }
  .gs-url { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.88rem; }
  .gs-seg { display: inline-flex; flex-wrap: wrap; border: 1px solid #34343a; border-radius: 999px; overflow: hidden; max-width: 100%; }
  .gs-seg button { padding: 7px 14px; background: transparent; border: 0; border-right: 1px solid #34343a; color: #c8c8cf; font: inherit; font-size: 0.9rem; cursor: pointer; }
  .gs-seg button:last-child { border-right: 0; }
  .gs-seg button.on { background: #5865f2; color: #fff; }
  .gs-chips { display: flex; flex-wrap: wrap; gap: 6px; margin: 0 0 4px; }
  .gs-chip { display: inline-block; padding: 5px 11px; background: #232327; border: 1px solid #3a3a42; border-radius: 999px; font-size: 0.88rem; }
  .gs-muted { color: #9a9aa2; font-size: 0.85rem; }
  .gs-photo { display: flex; gap: 12px; align-items: center; margin: 0 0 14px; }
  .gs-thumbs { display: flex; gap: 4px; flex-wrap: wrap; }
  .gs-thumbs img { width: 56px; height: 56px; object-fit: cover; border-radius: 8px; background: #26262b; }
  .gs-fine { margin: 0 0 8px; font-size: 0.85rem; color: #9a9aa2; }
  .gs-only { display: flex; align-items: center; gap: 9px; margin: 0 0 10px; font-size: 0.95rem; font-weight: 600; cursor: pointer; }
  .gs-only input { width: 18px; height: 18px; flex: none; accent-color: #5865f2; }
  .gs-link { padding: 0; background: none; border: 0; color: #aab2ff; font: inherit; font-weight: 600; cursor: pointer; }
  .gs-addrnote { margin: 0 0 10px; padding: 8px 12px; border-radius: 8px; background: rgba(245,166,35,.14); color: #f3c97a; font-size: 0.85rem; line-height: 1.4; }
  .gs-addrprev { margin-left: 6px; font-size: 0.85rem; font-weight: 400; color: #9a9aa2; }
  .gs-addrnote[hidden], .gs-addrrand[hidden] { display: none; }
  .gs-link:hover { text-decoration: underline; }
  .gs-err { min-height: 1.2em; color: #ff8587; font-size: 0.88rem; }
  .gs-acts { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 8px; margin-top: 8px; }
  .gs-large { position: fixed; inset: 0; z-index: 100001; display: flex; align-items: center; justify-content: center; background: #fff; color: #111; overflow: auto; }
  .gs-large-in { box-sizing: border-box; display: flex; flex-direction: column; align-items: center; width: 100%; max-width: 760px; padding: 24px 20px calc(24px + env(safe-area-inset-bottom, 0px)); text-align: center; }
  .gs-large-code { width: min(86vw, 68vh, 640px); line-height: 0; }   /* (no name and no text under it: the code can be a little bigger) */
  .gs-large-code canvas { display: block; width: 100%; height: auto; image-rendering: pixelated; }
  .gs-large-btns { display: flex; gap: 10px; margin-top: 20px; width: 100%; justify-content: center; }
  .gs-large-btns { max-width: 460px; }
  .gs-large-btns .gs-btn { flex: 1 1 0; min-width: 0; max-width: 150px; justify-content: center; min-height: 44px; }   /* (three buttons of one size, short names: they fit one row on a phone and Done is no bigger than the rest) */
KIT_CSS;
    $js = <<<'KIT_JS'
  // ---- a QR code: byte mode, error correction H (a third of the code can be lost, so the gallery's icon can sit in the middle),
  // versions 1 to 20 (up to about 380 characters). Written to the QR standard; the browser checks compare it module for module
  // with an independent encoder and read it back with an independent decoder.
  var QR_ECC = [0, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28];
  var QR_BLOCKS = [0, [[1, 9]], [[1, 16]], [[2, 13]], [[4, 9]], [[2, 11], [2, 12]], [[4, 15]], [[4, 13], [1, 14]], [[4, 14], [2, 15]], [[4, 12], [4, 13]], [[6, 15], [2, 16]],
    [[3, 12], [8, 13]], [[7, 14], [4, 15]], [[12, 11], [4, 12]], [[11, 12], [5, 13]], [[11, 12], [7, 13]], [[3, 15], [13, 16]], [[2, 14], [17, 15]], [[2, 14], [19, 15]],
    [[9, 13], [16, 14]], [[15, 15], [10, 16]]];
  var QR_MASKS = [function (x, y) { return (x + y) % 2 === 0; }, function (x, y) { return y % 2 === 0; }, function (x, y) { return x % 3 === 0; },
    function (x, y) { return (x + y) % 3 === 0; }, function (x, y) { return (Math.floor(x / 3) + Math.floor(y / 2)) % 2 === 0; },
    function (x, y) { return x * y % 2 + x * y % 3 === 0; }, function (x, y) { return (x * y % 2 + x * y % 3) % 2 === 0; },
    function (x, y) { return ((x + y) % 2 + x * y % 3) % 2 === 0; }];
  function qrDataCw(v) { var n = 0; QR_BLOCKS[v].forEach(function (g) { n += g[0] * g[1]; }); return n; }
  function qrGfMul(x, y) { var z = 0; for (var i = 7; i >= 0; i--) { z = (z << 1) ^ ((z >> 7) * 0x11D); z ^= ((y >> i) & 1) * x; } return z; }
  function qrRsGen(d) {
    var r = [], i, j, root = 1; for (i = 0; i < d - 1; i++) r.push(0); r.push(1);
    for (i = 0; i < d; i++) { for (j = 0; j < d; j++) { r[j] = qrGfMul(r[j], root); if (j + 1 < d) r[j] ^= r[j + 1]; } root = qrGfMul(root, 2); }
    return r;
  }
  function qrRsRem(data, gen) {
    var r = gen.map(function () { return 0; });
    data.forEach(function (b) { var f = b ^ r.shift(); r.push(0); for (var i = 0; i < gen.length; i++) r[i] ^= qrGfMul(gen[i], f); });
    return r;
  }
  function qrAlign(v) {
    if (v === 1) return [];
    var n = Math.floor(v / 7) + 2, step = Math.ceil((v * 4 + 4) / (n * 2 - 2)) * 2, out = [];
    for (var p = v * 4 + 10; out.length < n - 1; p -= step) out.unshift(p);
    out.unshift(6); return out;
  }
  function qrFormatBits(m) { var d = (2 << 3) | m, r = d; for (var i = 0; i < 10; i++) r = (r << 1) ^ ((r >> 9) * 0x537); return ((d << 10) | r) ^ 0x5412; }
  function qrVersionBits(v) { var r = v; for (var i = 0; i < 12; i++) r = (r << 1) ^ ((r >> 11) * 0x1F25); return (v << 12) | r; }
  function qrGrid(size, val) { var g = []; for (var y = 0; y < size; y++) { var row = []; for (var x = 0; x < size; x++) row.push(val); g.push(row); } return g; }
  function qrFunctionMap(v) {
    var size = v * 4 + 17, dark = qrGrid(size, false), fn = qrGrid(size, false), i, j, dx, dy;
    function put(x, y, d) { dark[y][x] = d; fn[y][x] = true; }
    for (i = 0; i < size; i++) { put(6, i, i % 2 === 0); put(i, 6, i % 2 === 0); }
    [[3, 3], [size - 4, 3], [3, size - 4]].forEach(function (c) {
      for (dy = -4; dy <= 4; dy++) for (dx = -4; dx <= 4; dx++) {
        var x = c[0] + dx, y = c[1] + dy, d = Math.max(Math.abs(dx), Math.abs(dy));
        if (x >= 0 && x < size && y >= 0 && y < size) put(x, y, d !== 2 && d !== 4);
      }
    });
    var ap = qrAlign(v);
    for (i = 0; i < ap.length; i++) for (j = 0; j < ap.length; j++) {
      if ((i === 0 && j === 0) || (i === 0 && j === ap.length - 1) || (i === ap.length - 1 && j === 0)) continue;
      for (dy = -2; dy <= 2; dy++) for (dx = -2; dx <= 2; dx++) put(ap[i] + dx, ap[j] + dy, Math.max(Math.abs(dx), Math.abs(dy)) !== 1);
    }
    for (i = 0; i < 9; i++) if (i !== 6) { fn[i][8] = true; fn[8][i] = true; }
    for (i = 0; i < 8; i++) { fn[8][size - 1 - i] = true; fn[size - 1 - i][8] = true; }
    put(8, size - 8, true);
    if (v >= 7) {
      var vb = qrVersionBits(v);
      for (i = 0; i < 18; i++) { var a = size - 11 + i % 3, b = Math.floor(i / 3), bit = ((vb >> i) & 1) === 1; put(a, b, bit); put(b, a, bit); }
    }
    return { dark: dark, fn: fn };
  }
  function qrDrawFormat(g, m) {
    var size = g.length, b = qrFormatBits(m), i;
    function bit(k) { return ((b >> k) & 1) === 1; }
    for (i = 0; i < 6; i++) g[i][8] = bit(i);
    g[7][8] = bit(6); g[8][8] = bit(7); g[8][7] = bit(8);
    for (i = 9; i < 15; i++) g[8][14 - i] = bit(i);
    for (i = 0; i < 8; i++) g[8][size - 1 - i] = bit(i);
    for (i = 8; i < 15; i++) g[size - 15 + i][8] = bit(i);
    g[size - 8][8] = true;
  }
  function qrPenalty(g) {
    var size = g.length, p = 0, lines = g.slice(), x, y, a, run, s;
    for (x = 0; x < size; x++) { var col = []; for (y = 0; y < size; y++) col.push(g[y][x]); lines.push(col); }
    lines.forEach(function (line) {
      run = 1;
      for (a = 1; a < size; a++) { if (line[a] === line[a - 1]) run++; else { if (run >= 5) p += 3 + run - 5; run = 1; } }
      if (run >= 5) p += 3 + run - 5;
      s = line.map(function (t) { return t ? '1' : '0'; }).join('');
      p += 40 * ((s.split('00001011101').length - 1) + (s.split('10111010000').length - 1));
    });
    for (y = 0; y < size - 1; y++) for (x = 0; x < size - 1; x++) if (g[y][x] === g[y][x + 1] && g[y][x] === g[y + 1][x] && g[y][x] === g[y + 1][x + 1]) p += 3;
    var dark = 0; g.forEach(function (row) { row.forEach(function (t) { if (t) dark++; }); });
    var total = size * size;
    return p + (Math.ceil(Math.abs(dark * 20 - total * 10) / total) - 1) * 10;
  }
  // text -> a square array of booleans (true = dark), or null when it won't fit.
  function qrEncode(text) {
    var s = unescape(encodeURIComponent(String(text))), data = [], i, v;
    for (i = 0; i < s.length; i++) data.push(s.charCodeAt(i));
    for (v = 1; v <= 20; v++) if (4 + (v < 10 ? 8 : 16) + 8 * data.length <= qrDataCw(v) * 8) break;
    if (v > 20) return null;
    var bits = [];
    function put(val, n) { for (var k = n - 1; k >= 0; k--) bits.push((val >> k) & 1); }
    put(4, 4); put(data.length, v < 10 ? 8 : 16);
    data.forEach(function (b) { put(b, 8); });
    var cap = qrDataCw(v); put(0, Math.min(4, cap * 8 - bits.length));
    while (bits.length % 8) bits.push(0);
    var cw = [];
    for (i = 0; i < bits.length; i += 8) { var n = 0; for (var k = 0; k < 8; k++) n = (n << 1) | bits[i + k]; cw.push(n); }
    for (var pad = 0; cw.length < cap; pad++) cw.push(pad % 2 === 0 ? 0xEC : 0x11);
    var gen = qrRsGen(QR_ECC[v]), blocks = [], pos = 0;
    QR_BLOCKS[v].forEach(function (g) { for (var c = 0; c < g[0]; c++) { var d = cw.slice(pos, pos + g[1]); pos += g[1]; blocks.push([d, qrRsRem(d, gen)]); } });
    var out = [], longest = 0;
    blocks.forEach(function (b) { longest = Math.max(longest, b[0].length); });
    for (i = 0; i < longest; i++) blocks.forEach(function (b) { if (i < b[0].length) out.push(b[0][i]); });
    for (i = 0; i < QR_ECC[v]; i++) blocks.forEach(function (b) { out.push(b[1][i]); });
    var size = v * 4 + 17, map = qrFunctionMap(v), base = map.dark, fn = map.fn, allbits = [];
    out.forEach(function (c) { for (var k = 7; k >= 0; k--) allbits.push((c >> k) & 1); });
    var bi = 0, x = size - 1, up = true, y, dx;
    while (x > 0) {
      if (x === 6) x--;
      for (var yy = 0; yy < size; yy++) {
        y = up ? size - 1 - yy : yy;
        for (dx = 0; dx < 2; dx++) { var xx = x - dx; if (!fn[y][xx]) { base[y][xx] = bi < allbits.length ? allbits[bi] === 1 : false; bi++; } }
      }
      up = !up; x -= 2;
    }
    var best = null;
    for (var m = 0; m < 8; m++) {
      var g = base.map(function (row) { return row.slice(); });
      for (y = 0; y < size; y++) for (x = 0; x < size; x++) if (!fn[y][x] && QR_MASKS[m](x, y)) g[y][x] = !g[y][x];
      qrDrawFormat(g, m);
      var sc = qrPenalty(g);
      if (best === null || sc < best[0]) best = [sc, g];
    }
    return best[1];
  }
  // ---- the pieces the owner shares with
  var cfg = window.__shareCfg || {};
  var pretty = null, prettyAsked = null, toastTimer = null;
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function icon(name) { return '<svg class="ico" aria-hidden="true"><use href="#i-' + name + '"></use></svg>'; }
  // Does the tidy address (/trip/<code>) work on this server? Asked once; the long form always works.
  function tidy() {
    if (prettyAsked) return prettyAsked;
    prettyAsked = cfg.probe ? fetch(cfg.probe, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { pretty = r.headers.get('X-Gallery-Link') === 'invalid'; return pretty; }).catch(function () { pretty = false; return false; }) : Promise.resolve(false);
    return prettyAsked;
  }
  function say(text, bad) {
    var t = document.getElementById('gs-toast');
    if (!t) { t = document.createElement('div'); t.id = 'gs-toast'; t.className = 'gs-toast'; t.setAttribute('role', 'status'); t.setAttribute('aria-live', 'polite'); document.body.appendChild(t); }
    clearTimeout(toastTimer); t.className = 'gs-toast' + (bad ? ' err' : ''); t.textContent = text; t.hidden = false;
    toastTimer = setTimeout(function () { t.hidden = true; }, bad ? 6000 : 3000);
  }
  // Feedback on the button that was pressed (its own text changes for a moment), where it can't be missed: no banner at the bottom of the screen.
  function tell(btn, text, bad) {
    if (!btn) { say(text, bad); return; }
    if (btn._gsOld === undefined) btn._gsOld = btn.innerHTML;
    clearTimeout(btn._gsTimer); btn.textContent = text; btn.classList.toggle('gs-bad', !!bad);
    btn._gsTimer = setTimeout(function () { btn.innerHTML = btn._gsOld; btn._gsOld = undefined; btn.classList.remove('gs-bad'); }, bad ? 2600 : 1500);
  }
  // Copying an address. The older way goes first: it is synchronous, so it always happens inside the click, which every browser, Safari most of all,
  // insists on (Safari refused the first press of the newer call). The newer clipboard call is the second choice, and an error is shown only if both are refused.
  function legacyCopy(text) {
    var prev = document.activeElement, ta = document.createElement('textarea'), ok = false;
    ta.value = text; ta.setAttribute('readonly', ''); ta.setAttribute('aria-hidden', 'true');
    ta.style.cssText = 'position:fixed;top:0;left:0;width:1px;height:1px;padding:0;border:0;opacity:0;font-size:16px';
    document.body.appendChild(ta); ta.select();
    try { ta.setSelectionRange(0, text.length); } catch (e) {}
    try { ok = document.execCommand('copy'); } catch (e) {}
    document.body.removeChild(ta);
    if (prev && prev !== document.body && prev.focus) { try { prev.focus(); } catch (e) {} }
    return ok;
  }
  function copyText(text, done) {
    function fail() {
      say('Couldn\'t copy: select it and copy by hand.', true);
      // the address, in a box that is already selected (there is no address box on the page to fall back on)
      var old = document.getElementById('gs-addrbox'); if (old && old.parentNode) old.parentNode.removeChild(old);
      var d = document.createElement('div'); d.id = 'gs-addrbox'; d.className = 'gs-addrbox';
      d.innerHTML = '<input type="text" readonly aria-label="The address" value="' + esc(text) + '"><button type="button" class="gs-btn">Close</button>';
      document.body.appendChild(d);
      var i = d.querySelector('input'); i.focus(); i.select();
      d.querySelector('button').addEventListener('click', function () { if (d.parentNode) d.parentNode.removeChild(d); });
    }
    if (legacyCopy(text)) { done(); return; }
    if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(done, fail); return; }
    fail();
  }
  // The code, drawn on a canvas: white all round, the gallery's own icon in the middle (the error correction covers it with room to spare).
  function qrCanvas(text, px) {
    var g = qrEncode(text); if (!g) return null;
    var n = g.length, quiet = 4, mods = n + quiet * 2, scale = Math.max(2, Math.floor((px || 480) / mods)), cv = document.createElement('canvas');
    cv.width = cv.height = mods * scale;
    var c = cv.getContext('2d'); c.fillStyle = '#fff'; c.fillRect(0, 0, cv.width, cv.height); c.fillStyle = '#000';
    for (var y = 0; y < n; y++) for (var x = 0; x < n; x++) if (g[y][x]) c.fillRect((x + quiet) * scale, (y + quiet) * scale, scale, scale);
    var inner = Math.max(5, Math.floor(n * 0.19)) * scale, pad = scale * 1.5, mid = cv.width / 2, bx = mid - inner / 2 - pad, bw = inner + pad * 2;
    c.fillStyle = '#fff'; c.fillRect(bx, bx, bw, bw);
    var r = inner * 0.2, x0 = mid - inner / 2;
    c.fillStyle = '#111'; c.beginPath();
    if (c.roundRect) c.roundRect(x0, x0, inner, inner, r); else c.rect(x0, x0, inner, inner);
    c.fill();
    c.strokeStyle = '#fff'; c.lineWidth = inner * 0.07; c.lineJoin = 'round'; c.lineCap = 'round';
    var f = inner * 0.2, fw = inner - f * 2;   // the picture-frame glyph, like the gallery icon
    c.beginPath(); if (c.roundRect) c.roundRect(x0 + f, x0 + f, fw, fw, fw * 0.12); else c.rect(x0 + f, x0 + f, fw, fw); c.stroke();
    c.beginPath(); c.arc(x0 + f + fw * 0.33, x0 + f + fw * 0.34, fw * 0.09, 0, Math.PI * 2); c.stroke();
    c.beginPath(); c.moveTo(x0 + f + fw * 0.06, x0 + f + fw * 0.86); c.lineTo(x0 + f + fw * 0.4, x0 + f + fw * 0.55); c.lineTo(x0 + f + fw * 0.6, x0 + f + fw * 0.72);
    c.lineTo(x0 + f + fw * 0.72, x0 + f + fw * 0.6); c.lineTo(x0 + f + fw * 0.94, x0 + f + fw * 0.82); c.stroke();
    return cv;
  }
  var blobs = {};   // address -> { p: a promise of the picture, blob: the picture once it is made }
  function warm(text) {
    if (blobs[text]) return blobs[text];
    var e = blobs[text] = { blob: null };
    e.p = new Promise(function (res, rej) {
      var cv = qrCanvas(text, 1024); if (!cv) { rej(new Error('long')); return; }
      cv.toBlob(function (b) { if (b) { e.blob = b; res(b); } else rej(new Error('blob')); }, 'image/png');
    });
    e.p.catch(function () {});
    return e;
  }
  function copyImage(text, btn) {
    if (!(window.ClipboardItem && navigator.clipboard && navigator.clipboard.write)) { tell(btn, 'Use Save', true); return; }
    // The picture goes over as a promise, right now, inside the click: making it takes a moment, and Safari refuses a write that comes later.
    try {
      navigator.clipboard.write([new ClipboardItem({ 'image/png': warm(text).p })]).then(function () { tell(btn, 'Copied'); }, function () { tell(btn, 'Couldn\'t copy: use Save', true); });
    } catch (e) { tell(btn, 'Couldn\'t copy: use Save', true); }
  }
  function saveImage(text, name, btn) {
    var e = warm(text), fname = (name || 'share-link').replace(/[^\w.-]+/g, '-') + '-qr.png';
    function go(blob) {
      var file = null; try { file = new File([blob], fname, { type: 'image/png' }); } catch (x) {}
      if (file && (navigator.maxTouchPoints || 0) > 0 && navigator.canShare && navigator.canShare({ files: [file] })) { return navigator.share({ files: [file] }).catch(function () {}); }   // a phone or tablet: "Save Image" is in its share sheet (a computer's browser can share files too, but there it would only pop up a sheet of contacts: it just downloads)
      var a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = fname;
      document.body.appendChild(a); a.click(); document.body.removeChild(a); setTimeout(function () { URL.revokeObjectURL(a.href); }, 4000);
    }
    if (e.blob) return go(e.blob);   // already made, so a phone's share sheet is asked inside the click
    return e.p.then(go, function () { tell(btn, 'Too long for a QR code', true); });
  }
  // The large, white version, to hold up to someone's camera across a table.
  function showLarge(url, name, btn) {
    var cv = qrCanvas(url, 900); if (!cv) { tell(btn, 'Too long for a QR code', true); return; }
    warm(url);
    var o = document.createElement('div'); o.className = 'gs-large'; o.setAttribute('role', 'dialog'); o.setAttribute('aria-modal', 'true'); o.setAttribute('aria-label', 'QR code');
    o.innerHTML = '<div class="gs-large-in"><div class="gs-large-code"></div><div class="gs-large-btns"><button type="button" class="gs-btn gs-light" data-gs="copyimg">' + icon('copy') + ' Copy</button><button type="button" class="gs-btn gs-light" data-gs="saveimg">Save</button><button type="button" class="gs-btn gs-dark" data-gs="done">Done</button></div></div>';
    o.querySelector('.gs-large-code').appendChild(cv);
    function close() { document.removeEventListener('keydown', key, true); if (o.parentNode) o.parentNode.removeChild(o); }
    function key(e) { if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(); } }
    o.addEventListener('click', function (e) { var b = e.target.closest ? e.target.closest('[data-gs]') : null; if (b && b.getAttribute('data-gs') === 'done') close(); else if (b && b.getAttribute('data-gs') === 'copyimg') copyImage(url, b); else if (b && b.getAttribute('data-gs') === 'saveimg') saveImage(url, name, b); });
    document.addEventListener('keydown', key, true); document.body.appendChild(o); o.querySelector('[data-gs=done]').focus();
  }
  // Copy / Share… / QR code, drawn into an element. opts.url() gives the address as it is right now; opts.copy false leaves out Copy.
  function mount(el, opts) {
    if (!el || el._gs) return; el._gs = true; el.classList.add('gs-panel');
    var canShare = !!navigator.share;
    el.innerHTML = '<div class="gs-btns">' + (opts.copy === false ? '' : '<button type="button" class="gs-btn" data-gs="copy">' + icon('copy') + ' Copy</button>')
      + (canShare ? '<button type="button" class="gs-btn" data-gs="share">' + icon('share') + ' Share…</button>' : '') + '<button type="button" class="gs-btn" data-gs="qr">' + icon('qr') + ' QR code</button></div>';
    el.addEventListener('click', function (e) {
      var b = e.target.closest ? e.target.closest('[data-gs]') : null; if (!b || !el.contains(b)) return;
      var what = b.getAttribute('data-gs'), url = opts.url();
      if (what === 'copy') copyText(url, function () { var old = b.innerHTML; b.textContent = 'Copied'; setTimeout(function () { b.innerHTML = old; }, 1400); });
      else if (what === 'share') navigator.share({ title: opts.name || 'Gallery', url: url }).catch(function () {});
      else if (what === 'qr') showLarge(url, opts.name, b);
    });
  }
  // ---- the short form: Share this folder / photo / these photos
  var modal = null, draftKey = 'galleryShareDraft';
  function seg(name, list, cur) { return '<div class="gs-seg" data-seg="' + name + '" role="radiogroup">' + list.map(function (o) { return '<button type="button" role="radio" aria-checked="' + (String(o[0]) === String(cur)) + '" data-v="' + o[0] + '" class="' + (String(o[0]) === String(cur) ? 'on' : '') + '">' + o[1] + '</button>'; }).join('') + '</div>'; }
  function segValue(root, name) { var b = root.querySelector('[data-seg=' + name + '] .on'); return b ? b.getAttribute('data-v') : ''; }
  function csrf() { return fetch((cfg.admin || '?admin') + '&csrf=1', { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { if ((r.headers.get('content-type') || '').indexOf('json') < 0) throw new Error('session'); return r.json(); }).then(function (j) { return j.token; }); }
  function closeModal() { if (modal && modal.parentNode) modal.parentNode.removeChild(modal); modal = null; document.removeEventListener('keydown', onKey, true); }
  function onKey(e) { if (e.key === 'Escape' && modal) { e.preventDefault(); e.stopPropagation(); closeModal(); } }
  function when(sec) { return sec > 0 ? new Date((Math.floor(Date.now() / 1000) + sec) * 1000).toLocaleString(undefined, { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }) : ''; }
  // What the person will see, said plainly: what the link covers, and the public rest of the gallery (listed too, or not).
  function fineText(photos, one, only) {
    var what = !photos ? 'this folder and what is inside it' : (one ? 'this photo' : 'these photos');
    return only ? 'They can see only ' + what + (cfg.closed ? ', and nothing else can be opened through the gallery. ' : '. The rest of the gallery stays public to everyone: this is a tidy view, not a lock. ') : 'They can see ' + what + ', and everything that is already public. ';
  }
  // What the quick form says about how many browsers can open the link (the number is the setting; 0 is unlimited). `more` is the
  // "Meant for more than one person?" box: it makes the link unlimited, and the part that changed is in bold.
  function usesFine(n, more) {
    if (more) return '<b>As many browsers as they like</b> can open it and it never stops working. ';
    return n === 0 ? 'As many browsers as they like can open it and it never stops working. ' : n === 1 ? 'Just one browser can open it, and it never stops working. ' : 'Up to ' + n + ' browsers can open it' + (n === 2 ? ' (their phone and their computer, say)' : '') + ' and it never stops working. ';
  }
  // opts: { kind: 'folder' | 'photos', path, label (folder), files [paths] (photos), name (a suggestion) }
  function quick(opts) {
    closeModal(); tidy();
    var photos = opts.kind === 'photos', files = opts.files || [], one = photos && files.length === 1;
    // A one-off share (one private photo, or a group that are all private) starts on a random address; a folder link is likely used again, so it starts on the name.
    var oneOff = photos && opts.allPrivate === true, anyPriv = opts.anyPrivate !== false;
    var title = photos ? (one ? 'Share just this photo' : 'Share these ' + files.length + ' photos') : 'Share ' + (opts.label || opts.path || 'the gallery');
    var subject = '';
    if (photos) {
      var shown = files.slice(0, 6).map(function (f) { return '<img src="?img=' + encodeURIComponent(f) + '&size=thumb" alt="" loading="lazy">'; }).join('');
      subject = one ? '<div class="gs-photo"><div class="gs-thumbs">' + shown + '</div><div><strong>' + esc(files[0].split('/').pop()) + '</strong><div class="gs-muted">' + esc(files[0].indexOf('/') > 0 ? files[0].replace(/\/[^\/]*$/, '') + ' · private' : 'The front page · private') + '</div></div></div>'
        : '<div class="gs-photo"><div class="gs-thumbs">' + shown + '</div><div class="gs-muted">' + (files.length > 6 ? '+' + (files.length - 6) + ' more' : '') + '</div></div>';
    } else subject = '<div class="gs-f"><span class="gs-k">Folder</span><div class="gs-chips"><span class="gs-chip">' + esc(opts.label || opts.path) + '</span></div><div class="gs-muted">Everything inside it too.</div></div>';
    var o = document.createElement('div'); o.className = 'gs-modal'; o.setAttribute('role', 'dialog'); o.setAttribute('aria-modal', 'true'); o.setAttribute('aria-label', title);
    var whoField = '<label class="gs-f"><span class="gs-k">Who is it for?</span><input type="text" class="gs-name" maxlength="80" placeholder="Aunt Carol" autocomplete="off" value="' + esc(opts.name || '') + '"></label>';
    o.innerHTML = '<div class="gs-card"><h3 class="gs-title">' + esc(title) + '</h3><div class="gs-body">' + (photos ? subject + whoField : whoField + subject)
      + '<div class="gs-f gs-addr"><label class="gs-only"><input type="checkbox" id="gs-addr"' + (oneOff ? '' : ' checked') + '> <span>Use the name as the address <code class="gs-addrprev"></code></span></label>'
      + '<div class="gs-addrnote" role="status" hidden>This link opens private photos. An address made from a name is easy to guess, so untick the box to get a random one that can\'t be.</div>'
      + '<div class="gs-fine gs-addrrand" hidden>A random code is made for you, one that can\'t be guessed. <button type="button" class="gs-link" data-gs="own">Type my own instead…</button></div></div>'
      + '<label class="gs-only"><input type="checkbox" id="gs-only" checked> <span>Show only ' + (photos ? (one ? 'this photo' : 'these photos') : 'this folder') + '</span></label>'
      + ((+cfg.defaultUses || 0) > 0 ? '<label class="gs-only"><input type="checkbox" id="gs-many"> <span>Meant for more than one person?</span></label>' : '')
      + '<p class="gs-fine"><span class="gs-fine-t">' + fineText(photos, one, true) + '</span><span class="gs-fine-u">' + usesFine(+cfg.defaultUses || 0, false) + '</span>Downloads off, locations shown only where set to show, no note. <button type="button" class="gs-link" data-gs="more">More options…</button></p>'
      + '<div class="gs-err" role="alert"></div></div>'
      + '<div class="gs-acts"><button type="button" class="gs-btn" data-gs="cancel">Cancel</button><button type="button" class="gs-btn gs-primary" data-gs="create">Create link</button></div></div>';
    modal = o; document.body.appendChild(o); document.addEventListener('keydown', onKey, true);
    var card = o.querySelector('.gs-card'), nameIn = o.querySelector('.gs-name');
    var ck = o.querySelector('#gs-addr'), prev = o.querySelector('.gs-addrprev'), noteEl = o.querySelector('.gs-addrnote'), randEl = o.querySelector('.gs-addrrand');
    function slug(t) { var x = String(t || ''); if (x.normalize) x = x.normalize('NFD').replace(/[\u0300-\u036f]/g, ''); return x.toLowerCase().replace(/[^a-z0-9]+/g, '').slice(0, 40); }
    function syncAddr() { var on = ck.checked; prev.textContent = on ? '…/' + (slug(nameIn.value) || 'auntcarol') : ''; noteEl.hidden = !(on && anyPriv); randEl.hidden = on; }
    syncAddr(); nameIn.addEventListener('input', syncAddr); ck.addEventListener('change', syncAddr);
    setTimeout(function () { nameIn.focus(); }, 30);
    o.addEventListener('change', function (e) {
      if (e.target && e.target.id === 'gs-only') card.querySelector('.gs-fine-t').textContent = fineText(photos, one, e.target.checked);
      if (e.target && e.target.id === 'gs-many') card.querySelector('.gs-fine-u').innerHTML = usesFine(+cfg.defaultUses || 0, e.target.checked);
    });
    o.addEventListener('click', function (e) {
      if (e.target === o) { closeModal(); return; }
      var sb = e.target.closest ? e.target.closest('.gs-seg button') : null;
      if (sb) { [].forEach.call(sb.parentNode.children, function (x) { x.classList.toggle('on', x === sb); x.setAttribute('aria-checked', x === sb ? 'true' : 'false'); }); return; }
      var b = e.target.closest ? e.target.closest('[data-gs]') : null; if (!b) return;
      var what = b.getAttribute('data-gs');
      if (what === 'cancel') { closeModal(); return; }
      var draftOf = function () {
        return { label: nameIn.value.trim(), scopeType: photos ? 'photos' : 'folders', only: !!card.querySelector('#gs-only').checked, scopeFolders: photos ? [] : [opts.path], scopePhotos: photos ? files : [], openIn: photos ? '' : opts.path, maxOpens: (card.querySelector('#gs-many') || {}).checked ? 0 : +cfg.defaultUses || 0, endsAt: 0, addrMode: ck.checked ? 'name' : 'random' };
      };
      if (what === 'more' || what === 'own') {
        var d = draftOf(); closeModal();
        if (what === 'own') { d.addrMode = 'custom'; d.focusAddress = true; }   // (to type their own: the full form, with the address box open and the cursor in it)
        if (window.__slOpenForm) { if (window.adminGoTo) window.adminGoTo('links'); window.__slOpenForm(d, what === 'own' ? 'address' : undefined); }
        else {
          try { sessionStorage.setItem(draftKey, JSON.stringify(d)); } catch (x) {}
          // Inside the admin shell this page is a frame, and an admin page that is not marked as framed (&inframe=1) refuses to be shown in one: blank.
          location.href = (cfg.admin || '?admin') + (window.parent !== window ? '&inframe=1' : '') + '#links';
        }
        return;
      }
      if (what !== 'create') return;
      var err = card.querySelector('.gs-err'); err.textContent = '';
      if (!nameIn.value.trim()) { err.textContent = 'Give it a name, so you can recognise it later.'; nameIn.focus(); return; }
      if (ck.checked && slug(nameIn.value).length < 4) { err.textContent = 'An address needs at least 4 letters or digits. Add to the name, or untick the box.'; nameIn.focus(); return; }
      b.disabled = true;
      var d2 = draftOf();
      csrf().then(function (token) {
        var body = new URLSearchParams();
        body.set('csrf_token', token); body.set('action', 'link_create'); body.set('label', d2.label); body.set('note', ''); body.set('address', ck.checked ? slug(nameIn.value) : ''); body.set('address_auto', ck.checked ? '1' : '0'); body.set('title_mode', 'gallery'); body.set('picture', '1');
        body.set('scope_type', d2.scopeType); body.set('only', d2.only ? '1' : '0'); body.set('scope_folders', JSON.stringify(d2.scopeFolders)); body.set('scope_photos', JSON.stringify(d2.scopePhotos)); body.set('open_in', d2.openIn);
        body.set('downloads', 'off'); body.set('zip', 'off'); body.set('locations', 'public'); body.set('heads_up', 'default'); body.set('starts_at', '0');
        body.set('max_opens', d2.maxOpens); body.set('ends_at', d2.endsAt);
        return fetch((cfg.admin || '?admin'), { method: 'POST', credentials: 'same-origin', body: body.toString(), headers: { 'X-Requested-With': 'gallery-fetch', 'Content-Type': 'application/x-www-form-urlencoded' } })
          .then(function (r) { if ((r.headers.get('content-type') || '').indexOf('json') < 0) throw new Error('session'); return r.json(); });
      }).then(function (j) {
        if (!j.ok) { b.disabled = false; err.textContent = j.text || 'Not saved.'; return; }
        return tidy().then(function () { result(card, j, d2); if (window.__slRefresh) window.__slRefresh(); });
      }).catch(function (x) { b.disabled = false; err.textContent = x && x.message === 'session' ? 'Your session ended: reload the page and sign in again.' : 'Couldn\'t save: check your connection.'; });
    });
  }
  function result(card, j, d) {
    var urls = j.urls || {}, url = pretty && urls.short ? urls.short : (urls.long || '');
    var opensText = d.maxOpens > 0 ? (d.maxOpens === 1 ? 'just one browser' : 'up to ' + d.maxOpens + ' browsers') : 'as many browsers as they like';
    card.innerHTML = '<h3 class="gs-title">Link made for ' + esc(d.label) + '</h3><div class="gs-body"><input type="text" class="gs-url" readonly value="' + esc(url) + '" aria-label="The link" onfocus="this.select()"><div class="gs-mount"></div>'
      + (j.text && j.text.indexOf('is taken') > -1 ? '<p class="gs-fine">' + esc(j.text) + '</p>' : '') + '<p class="gs-fine">' + (d.endsAt > 0 ? 'Ends ' + esc(when(d.endsAt - Math.floor(Date.now() / 1000))) + ' · ' : 'No end date · ') + opensText + '. You can copy it again any time from the Shares tab.</p></div>'
      + '<div class="gs-acts"><a class="gs-btn" href="' + esc((cfg.admin || '?admin') + '#links') + '">Open Shares</a><button type="button" class="gs-btn gs-primary" data-gs="done">Done</button></div>';
    mount(card.querySelector('.gs-mount'), { url: function () { return card.querySelector('.gs-url').value; }, name: d.label, copy: true });
    card.querySelector('.gs-url').select();
    card.addEventListener('click', function (e) { var b = e.target.closest ? e.target.closest('[data-gs=done]') : null; if (b) closeModal(); });
  }
  // Anything marked data-gs-folder (a button on a private folder's page, a link on the Private tab) opens the short form for that folder.
  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-gs-folder]') : null; if (!b) return;
    e.preventDefault(); e.stopPropagation();
    quick({ kind: 'folder', path: b.getAttribute('data-gs-folder'), label: b.getAttribute('data-gs-label') || b.getAttribute('data-gs-folder') });
  }, true);
  window.GalleryShare = { qr: qrEncode, canvas: qrCanvas, mount: mount, large: showLarge, quick: quick, say: say, copyText: copyText, tidy: tidy, draftKey: draftKey };
KIT_JS;
    return '<style>' . $css . '</style><script>window.__shareCfg = ' . json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP) . ';(function () {' . "\n" . $js . "\n" . '})();</script>';
}
// KIT-END

