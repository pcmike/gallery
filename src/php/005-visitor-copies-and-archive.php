/** What to overwrite in the original at $srcPath before it is given to this visitor: [] for nothing (the owner, someone with the
 *  private-view password or on a share link set to show every location gets the file exactly as it is; so does anyone, for a photo
 *  whose location the gallery shows, when "Scrub location data" is switched off), a plan for any other visitor (the location is
 *  taken out of the file, whether the gallery shows it on the page or not), or null when that can't be done safely, in which case
 *  the file must not be given out. */
function visitor_scrub_plan($srcPath, $filename, $itemsData) {
    if (viewer_sees_all_locations()) return [];   // (the owner, the private-view password, a share link set to show every location: the file as it is)
    $entry = $itemsData[$filename] ?? [];
    // With the switch off, or in a folder that keeps the location in what visitors get, only a location the gallery hides is taken out.
    if ((!SCRUB_LOCATION_DATA || !empty($entry['_folderKeepLocation'])) && photo_location_visible($entry)) return [];
    return location_scrub_plan($srcPath);
}

/** Sends the original file to this visitor (see visitor_scrub_plan). False, with nothing sent, when it may not be.
 *  $cacheControl is sent with a file that is the same for everyone; a file made differently for different visitors is
 *  kept out of shared caches. */
function send_original_for_visitor($srcPath, $filename, $itemsData, $cacheControl = null) {
    $plan = visitor_scrub_plan($srcPath, $filename, $itemsData);
    if ($plan === null) return false;
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if ($plan === []) {
        if ($cacheControl !== null) header('Cache-Control: ' . $cacheControl);
        serve_original_file($srcPath, $filename, guess_mime($ext));
        return true;
    }
    $size = @filesize($srcPath);
    $fh = $size === false ? false : @fopen($srcPath, 'rb');
    if (!$fh) return false;
    header('Content-Type: ' . guess_mime($ext));
    header('Content-Length: ' . $size);
    header('Cache-Control: private, max-age=86400');
    header('Vary: Cookie');
    stream_with_patches($fh, $size, $plan, function ($chunk) { echo $chunk; flush(); });
    fclose($fh);
    return true;
}

/* -----------------------------------------------------------------------
 * ?archive: every original in the gallery (or in one folder and below) as
 * one ZIP, for the owner and for visitors with the private-view password.
 * It is written to the visitor's connection as it is built, so nothing is
 * stored on the server however big the gallery is: each file is read once,
 * in small pieces, and sent. Files are stored, not compressed (photos and
 * videos are already compressed, so it would only cost time), each with a
 * "data descriptor" after it carrying its checksum and size, which is what
 * lets the header go out before the file has been read. The total size is
 * known in advance (nothing is compressed), so the download has a real
 * progress bar and an interrupted one is noticed as incomplete. Over 4 GB or
 * 65,535 files it switches to the ZIP64 variant, which every current unzip
 * tool reads. Only a couple of archives are made at the same time.
 * ----------------------------------------------------------------------- */

/** The files an archive of $files would hold: [{rel, path, size, mtime}], skipping anything unreadable. */
function archive_entries($dir, $files) {
    $out = [];
    foreach ($files as $rel) {
        $path = resolve_real_path($dir, $rel);
        $st = is_file($path) && is_readable($path) ? @stat($path) : false;
        if ($st) $out[] = ['rel' => $rel, 'path' => $path, 'size' => (int) $st['size'], 'mtime' => (int) $st['mtime']];
    }
    return $out;
}

/** "1.4 GB" */
function human_bytes($n) {
    if ($n < 1024) return $n . ' bytes';
    foreach (['KB', 'MB', 'GB', 'TB'] as $i => $unit) {
        $n /= 1024;
        if ($n < 1024 || $unit === 'TB') return ($n >= 100 ? number_format($n, 0) : number_format($n, 1)) . ' ' . $unit;
    }
}

/** [dosTime, dosDate] for a ZIP entry. */
function archive_dos_time($ts) {
    $d = getdate(max(315532800, $ts));
    return [($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2), (($d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday']];
}

/** A path as a ZIP entry name: UTF-8, forward slashes, nothing that could climb out of the folder it is unpacked into. */
function archive_entry_name($name) {
    if (!preg_match('//u', $name)) $name = mb_convert_encoding($name, 'UTF-8', 'ISO-8859-1');
    $name = preg_replace('/[\x00-\x1F\x7F\\\\]/', '_', $name);
    $parts = array_filter(explode('/', $name), function ($p) { return $p !== '' && $p !== '.' && $p !== '..'; });
    return implode('/', $parts);
}

/** The exact size of the ZIP that archive_stream() will send. */
function archive_total_size($sizes, $names, $zip64) {
    $total = 0;
    foreach ($sizes as $i => $size) {
        $n = strlen($names[$i]);
        $total += 30 + $n + ($zip64 ? 20 : 0) + $size + ($zip64 ? 24 : 16) + 46 + $n + ($zip64 ? 28 : 0);
    }
    return $total + 22 + ($zip64 ? 56 + 20 : 0);
}

/** Sends the ZIP of $entries ([{name, path, size, mtime}]) to the visitor. Returns false (having sent nothing) if the
 *  total size is beyond what a ZIP can describe. */
function archive_stream($entries, $zip64) {
    $offset = 0;
    $central = '';
    $emit = function ($bytes) use (&$offset) {
        echo $bytes;
        $offset += strlen($bytes);
    };
    foreach ($entries as $e) {
        if (connection_aborted()) return;
        $name = $e['name']; $size = $e['size'];
        [$time, $date] = archive_dos_time($e['mtime']);
        $at = $offset;
        $emit(pack('VvvvvvVVVvv', 0x04034b50, $zip64 ? 45 : 20, 0x0808, 0, $time, $date, 0, $zip64 ? 0xFFFFFFFF : 0, $zip64 ? 0xFFFFFFFF : 0, strlen($name), $zip64 ? 20 : 0)
            . $name . ($zip64 ? pack('vvPP', 1, 16, 0, 0) : ''));
        if (isset($e['data'])) { $fh = fopen('php://memory', 'r+b'); fwrite($fh, $e['data']); rewind($fh); } else $fh = @fopen($e['path'], 'rb');
        if (!$fh) return;   // the connection ends short of its length, so the download shows as failed, never as a good file
        $ctx = hash_init('crc32b');
        for ($left = $size, $at0 = 0; $left > 0;) {
            $chunk = fread($fh, min(1048576, $left));
            if ($chunk === false || $chunk === '') { fclose($fh); return; }
            if (!empty($e['patches'])) $chunk = apply_patches_to_chunk($chunk, $at0, $e['patches']);   // same length: the size worked out in advance stays right
            $at0 += strlen($chunk);
            hash_update($ctx, $chunk);
            $emit($chunk);
            $left -= strlen($chunk);
            if (connection_aborted()) { fclose($fh); return; }
            flush();
        }
        fclose($fh);
        $crc = hexdec(hash_final($ctx));
        $emit(pack('VV', 0x08074b50, $crc) . ($zip64 ? pack('PP', $size, $size) : pack('VV', $size, $size)));
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 0x0300 | ($zip64 ? 45 : 20), $zip64 ? 45 : 20, 0x0808, 0, $time, $date, $crc,
                $zip64 ? 0xFFFFFFFF : $size, $zip64 ? 0xFFFFFFFF : $size, strlen($name), $zip64 ? 28 : 0, 0, 0, 0, 0x81A40000, $zip64 ? 0xFFFFFFFF : $at)
            . $name . ($zip64 ? pack('vvPPP', 1, 24, $size, $size, $at) : '');
    }
    $n = count($entries);
    $cdAt = $offset;
    $emit($central);
    if ($zip64) {
        $emit(pack('VPvvVVPPPP', 0x06064b50, 44, 45, 45, 0, 0, $n, $n, strlen($central), $cdAt));
        $emit(pack('VVPV', 0x07064b50, 0, $cdAt + strlen($central), 1));
        $emit(pack('VvvvvVVv', 0x06054b50, 0, 0, 0xFFFF, 0xFFFF, 0xFFFFFFFF, 0xFFFFFFFF, 0));
    } else {
        $emit(pack('VvvvvVVv', 0x06054b50, 0, 0, $n, $n, strlen($central), $cdAt, 0));
    }
    flush();
}

/** One of a couple of places to make an archive at once, or null if they are all taken. The lock goes when the request ends. */
function archive_take_slot() {
    $base = sys_get_temp_dir() . '/gallery-archive-' . substr(hash('sha256', __DIR__), 0, 10) . '-';
    for ($i = 1; $i <= 2; $i++) {
        $fh = @fopen($base . $i . '.lock', 'c');
        if ($fh && flock($fh, LOCK_EX | LOCK_NB)) return $fh;
        if ($fh) fclose($fh);
    }
    return null;
}

/** The files of $files this visitor may have in a ZIP: everything for the owner and the private-view password; for someone using a
 *  share link, what their link's ZIP answer covers, public or private; nothing for anyone else. */
function archive_files_for_viewer($dir, $files) {
    if (is_admin_browser()) return $files;
    if (is_revealed()) return array_values(array_filter($files, function ($f) use ($dir) { return !file_is_unlisted($dir, $f); }));   // (a Not listed folder is in nobody's ZIP but the owner's)
    $l = link_viewer();
    if ($l === null || ($l['zip']['mode'] ?? 'off') === 'off') return [];
    global $itemsData;
    return array_values(array_filter($files, function ($f) use ($dir, $l, $itemsData) {
        return (!file_is_hidden($dir, $f, $itemsData) || link_allows_item($l, $dir, $f, $itemsData)) && link_capability_allows($l, 'zip', $dir, $f, $itemsData);
    }));
}

/** ?archive[&folder=...]: the originals of this gallery or folder as one ZIP. $files is every file the visitor may see;
 *  $folder is the folder asked for (null for the whole gallery). The owner and the private-view password get files exactly as
 *  they are; someone using a share link gets what the link allows, with any location the gallery hides taken out. */
function run_archive_route($dir, $files, $folder, $rootTitle, $folderName) {
    send_sensitive_page_headers();
    $files = archive_files_for_viewer($dir, $files);
    $entries = $files ? archive_entries($dir, $files) : [];
    if ($entries && !viewer_sees_all_locations()) {
        global $itemsData;
        foreach ($entries as $i => $e) {
            $plan = visitor_scrub_plan($e['path'], $e['rel'], $itemsData);
            if ($plan === null) unset($entries[$i]); else $entries[$i]['patches'] = $plan;   // a file whose location can't be taken out safely is left out
        }
        $entries = array_values($entries);
    }
    if (!$entries) {
        http_response_code(404);
        return;
    }
    if (count($entries) > 100000) {
        http_response_code(413);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'That is more files than one archive can hold. Download a folder at a time.';
        return;
    }
    $root = archive_entry_name($folder === null ? $rootTitle : $folderName);
    if ($root === '') $root = 'gallery';
    $prefix = $folder === null ? '' : $folder . '/';
    foreach ($entries as $i => $e) {
        $entries[$i]['name'] = $root . '/' . archive_entry_name($prefix !== '' && strncmp($e['rel'], $prefix, strlen($prefix)) === 0 ? substr($e['rel'], strlen($prefix)) : $e['rel']);
    }
    $sizes = array_column($entries, 'size'); $names = array_column($entries, 'name');
    $zip64 = count($entries) >= 0xFFFF || max($sizes) >= 0xFFFFFFFF || archive_total_size($sizes, $names, false) >= 0xFFFFFFF0;
    $total = archive_total_size($sizes, $names, $zip64);
    $slot = archive_take_slot();
    if ($slot === null) {
        http_response_code(503);
        header('Retry-After: 30');
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'An archive is already being made for someone else. Try again in a minute.';
        return;
    }
    $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $root); $ascii = trim($ascii, '_') !== '' ? $ascii : 'gallery';
    @set_time_limit(0);
    ignore_user_abort(false);
    @ini_set('zlib.output_compression', '0');
    if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');
    while (ob_get_level() > 0) @ob_end_clean();
    header('Content-Type: application/zip');
    shell_frame_headers();   // (a download may sit in this site's own admin frame, as it does on a desktop; no other site may frame it)
    header('Content-Disposition: attachment; filename="' . $ascii . '.zip"; filename*=UTF-8\'\'' . rawurlencode($root . '.zip'));
    header('Content-Length: ' . $total);
    header('X-Content-Type-Options: nosniff');
    header('X-Accel-Buffering: no');
    header('Cache-Control: no-store');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') return;
    if (($linkNote = link_viewer()) !== null && !is_revealed()) {   // noted in the link's log: one line for the ZIP, each file as saved
        $pairs = [['z', ($folder ?? '') . "\0" . count($entries)]];
        foreach ($entries as $e) $pairs[] = ['d', $e['rel']];
        link_queue_deliveries($linkNote, $pairs);
    }
    archive_stream($entries, $zip64);
}

/** ?original=: the original file as a download. Offered to anyone signed in, and to everyone when ENABLE_PUBLIC_DOWNLOAD is
 *  on; a visitor who isn't signed in gets a photo whose location the gallery hides with the location blanked out. */
function serve_original_download($dir, $filename, $validFiles, $itemsData = []) {
    send_sensitive_page_headers();
    if (!is_string($filename) || !in_array($filename, $validFiles, true) || !viewer_may_see_item($dir, $filename, $itemsData)
        || !viewer_may_download($dir, $filename, $itemsData)) {
        http_response_code(404);
        return;
    }
    $srcPath = resolve_real_path($dir, $filename);
    $size = @filesize($srcPath);
    if (!is_file($srcPath) || $size === false) {
        http_response_code(404);
        return;
    }
    $name = basename($filename);
    $ascii = preg_replace('/[^A-Za-z0-9._ -]/', '_', $name);
    // Checked before anything is sent: a file whose location can't be removed safely is not handed out.
    if (visitor_scrub_plan($srcPath, $filename, $itemsData) === null) {
        http_response_code(422);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "This file's location can't be removed safely, so it isn't offered as a download.";
        return;
    }
    shell_frame_headers();   // (a download may sit in this site's own admin frame, as it does on a desktop; no other site may frame it)
    header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
    header('X-Content-Type-Options: nosniff');
    if (($linkNote = link_viewer()) !== null && !is_revealed()) link_queue_delivery($linkNote, 'd', $filename);   // saved: noted in the link's log
    if (!send_original_for_visitor($srcPath, $filename, $itemsData)) http_response_code(404);
}

