/* -----------------------------------------------------------------------
 * Per-item data: captions, location, a web link, a folder-group's own
 * display name and chosen cover photo, and a folder's own location
 * toggle. All of it machine-written by the admin tool, never hand-
 * edited, so plain JSON is enough — no custom parser to get wrong. Lives
 * in .gallery-items.php, colocated per folder exactly like
 * .gallery-stats.* and .gallery-cache: a photo's caption lives in the
 * same real folder the photo itself does, and a folder-group's own
 * display name/cover live inside *that* folder (not its parent) — the
 * same place a genuine folder rename already carries everything else
 * along for free, with nothing left to update in some other file.
 * ----------------------------------------------------------------------- */

function items_path($folderPath) {
    return $folderPath . '/.gallery-items.php';
}

/** items: bare filename => {caption, location: {lat,lng,label},
 *  locationVisibility, web}. groups: filename-based group key (root-level
 *  only — see the admin editor) => {displayName}. displayName/cover
 *  describe *this folder itself* when a parent renders it as a
 *  folder-group cover card, along with description (a short blurb), dates
 *  (a free-text date-range subtitle — typed by the admin, never derived
 *  from EXIF, since that would publish when the owner was away) and
 *  sortOrder (an explicit position among sibling folders).
 *  locationEnabled is this folder's own
 *  location-sharing toggle (see ENABLE_PHOTO_LOCATION's doc comment). */
function items_default() {
    return ['items' => [], 'groups' => [], 'displayName' => null, 'description' => null, 'dates' => null, 'sortOrder' => null, 'subfolderStyle' => null, 'flatNames' => null, 'flatNotes' => null, 'cover' => null, 'locationEnabled' => false, 'mapEnabled' => null, 'mapRadius' => null, 'photoSort' => null, 'stacks' => [], 'folderId' => null];
}

function items_read($folderPath) {
    [$status, $data] = json_file_load(items_path($folderPath), items_default());
    return $status === 'broken' ? items_unreadable($folderPath, $data) : $data;
}

/** What a folder whose settings file can't be read looks like: private. The privacy flags live in that file, so not being able to
 *  read it means not knowing what the owner hid; the safe answer is that everything in the folder (and, being private, below it)
 *  is hidden from visitors until the file is restored or removed. The owner still sees it all, with a notice naming the file. */
function items_unreadable($folderPath, $data) {
    static $seen = [];
    if (!isset($seen[$folderPath])) {
        $items = [];
        foreach ((array) @scandir($folderPath) as $name) {
            if (is_string($name) && $name !== '' && $name[0] !== '.' && is_file($folderPath . '/' . $name)) $items[$name] = ['restriction' => 'private'];
        }
        $seen[$folderPath] = $items;
        $GLOBALS['__data_unreadable'][$folderPath] = true;
    }
    $data['items'] = $seen[$folderPath];
    $data['restriction'] = 'private';
    return $data;
}

function items_update($folderPath, $modifier) {
    folder_restriction_cached(null, null, true);
    return json_file_update(items_path($folderPath), items_default(), $modifier, true);
}

/** Every file's own item data (caption/location/web), across every real
 *  folder $files touches, re-keyed to each file's full relative path —
 *  same colocated-merge shape as stats_get_all_photo_views(). */
function items_get_all_for_files($dir, $files) {
    $byFolder = [];
    foreach ($files as $file) {
        $byFolder[containing_folder_real_path($dir, $file)][] = $file;
    }
    $result = [];
    $withFileLocations = ENABLE_PHOTO_LOCATION && media_meta_enabled();   // (nothing to merge when locations are off for the whole gallery)
    foreach ($byFolder as $folderPath => $folderFiles) {
        $data = items_read($folderPath);
        $records = $withFileLocations ? media_meta_load($folderPath) : [];
        foreach ($folderFiles as $file) {
            $bare = basename($file);
            $entry = $data['items'][$bare] ?? [];
            // A location the gallery has read from the file stands in for one the owner hasn't set (the owner's always wins).
            if ($records && isset($records[$bare]) && media_meta_fresh($records[$bare], $folderPath . '/' . $bare)) {
                $fromFile = media_file_location($entry, $records[$bare]);
                if ($fromFile !== null) $entry['location'] = $fromFile;
            }
            // Not real item data — a folder-level setting stashed onto
            // each of its own files' entries so callers (the location
            // badge/pin, specifically) don't need a second lookup
            // structure just to find out which folder a file lives in.
            $entry['_folderLocationEnabled'] = !empty($data['locationEnabled']);
            $result[$file] = $entry;
        }
    }
    return $result;
}

/** The gates (global constant, folder toggle, per-photo tri-state) that
 *  decide whether a visitor sees this photo's location — see
 *  ENABLE_PHOTO_LOCATION's own doc comment for the full reasoning. Someone
 *  signed in (the owner, or with the private-view password) sees the
 *  location of every photo that has one, whatever its folder or its own
 *  switch says: those switches are about what visitors who aren't signed
 *  in are shown. The global constant still switches the whole feature off
 *  for everyone. The admin tool itself ignores all of it; it can always
 *  see and edit location data for its own authenticated owner. */
function photo_location_visible($itemEntry) {
    if (!ENABLE_PHOTO_LOCATION) return false;
    if (empty($itemEntry['location'])) return false;
    static $trusted = null;
    if ($trusted === null) $trusted = viewer_sees_all_locations();
    if ($trusted) return true;
    $visibility = $itemEntry['locationVisibility'] ?? 'inherit';
    if ($visibility === 'hide') return false;
    if ($visibility === 'show') return true;
    return !empty($itemEntry['_folderLocationEnabled']);
}

/* -----------------------------------------------------------------------
 * The order of the photos inside a folder. A spec says: the base order (name,
 * taken = the capture date in the photo, modified), whether it runs backwards,
 * and which kinds go first whatever the base order (private, then videos, then
 * photos with a location that is shown). It comes from, in turn: a link's
 * ?sort=, the visitor's own choice (a cookie, set by the sort button), the
 * folder's own setting, the site's setting. A spec is written as text, for
 * example "taken.desc.video.located".
 * ----------------------------------------------------------------------- */

/** The three "put first" kinds in the order they stack in (the site's SORT_PRIORITY; anything missing or unknown is put back in the default order). */
function sort_priority_list() {
    static $list = null;
    if ($list !== null) return $list;
    $all = ['private', 'video', 'located'];
    $list = [];
    foreach (explode(',', (string) SORT_PRIORITY) as $k) {
        $k = strtolower(trim($k));
        if (in_array($k, $all, true) && !in_array($k, $list, true)) $list[] = $k;
    }
    foreach ($all as $k) if (!in_array($k, $list, true)) $list[] = $k;
    return $list;
}

/** What each kind is called, in the settings and in the sentence that sums them up. */
function sort_priority_names() {
    return ['private' => ['Private items', 'private items', 'SORT_PRIVATE_FIRST'], 'video' => ['Videos', 'videos', 'SORT_VIDEOS_FIRST'], 'located' => ['Located photos', 'photos with a location', 'SORT_LOCATED_FIRST']];
}

/** The one sentence that says what the "put first" list does, e.g. "Videos, then photos with a location, then the rest." */
function sort_priority_summary($order, $on, $siteOrder = null, $siteOn = null) {
    $names = sort_priority_names();
    $parts = [];
    foreach ($order as $k) if (!empty($on[$k])) $parts[] = $names[$k][1];
    if (!$parts && $siteOrder !== null) {   // a folder's list with everything off, while the site puts something first
        $siteParts = [];
        foreach ($siteOrder as $k) if (!empty($siteOn[$k])) $siteParts[] = $names[$k][1];
        if ($siteParts) return 'Nothing is put first in this folder, though the site puts ' . implode(', then ', $siteParts) . ' first.';
    }
    if (!$parts) return 'Nothing is put first: photos follow the order above.';
    $text = implode(', then ', $parts) . ', then the rest.';
    return ucfirst($text);
}

/** The site's own spec, from the settings. */
function media_sort_site_spec() {
    return ['order' => PHOTO_SORT_ORDER === 'mtime' ? 'modified' : (PHOTO_SORT_ORDER === 'taken' ? 'taken' : 'name'), 'desc' => (bool) PHOTO_SORT_DESC,
            'private' => (bool) SORT_PRIVATE_FIRST, 'video' => (bool) SORT_VIDEOS_FIRST, 'located' => (bool) SORT_LOCATED_FIRST];
}

/** A spec written as text, or null for anything else (including "default", which means: no choice of my own). */
function media_sort_parse($text) {
    if (!is_string($text) || $text === '' || strlen($text) > 60) return null;
    $spec = ['order' => 'name', 'desc' => false, 'private' => false, 'video' => false, 'located' => false];
    $parts = explode('.', strtolower($text));
    if (!in_array($parts[0], ['name', 'taken', 'modified'], true)) return null;
    $spec['order'] = array_shift($parts);
    foreach ($parts as $flag) {
        if ($flag === 'desc') $spec['desc'] = true;
        elseif (in_array($flag, ['private', 'video', 'located'], true)) $spec[$flag] = true;
        elseif ($flag !== 'asc') return null;
    }
    return $spec;
}

function media_sort_encode($spec) {
    $out = $spec['order'];
    foreach (['desc', 'private', 'video', 'located'] as $k) if (!empty($spec[$k])) $out .= '.' . $k;
    return $out;
}

/** The visitor's own choice: a link's ?sort= wins over the cookie the sort button sets. Null when there is none. */
function media_sort_override() {
    static $memo = false;
    if ($memo !== false) return $memo;
    $memo = null;
    foreach ([$_GET['sort'] ?? null, $_COOKIE['gallery_sort'] ?? null] as $text) {
        if (is_string($text) && $text !== '') { $memo = media_sort_parse($text); break; }
    }
    return $memo;
}

/** The order the "first" choices stack in for one folder: its own list when it has one, else the site's. */
function media_sort_priority_for_folder($folderReal) {
    $own = items_read($folderReal)['photoSort']['priority'] ?? null;
    if (is_array($own)) {
        $want = ['private', 'video', 'located']; $sorted = array_values($own); sort($sorted); sort($want);
        if ($sorted === $want) return array_values($own);
    }
    return sort_priority_list();
}

/** The spec in force for one folder (given its real path): the visitor's choice, else the folder's own setting laid over the site's. */
function media_sort_spec_for_folder($folderReal) {
    $override = media_sort_override();
    if ($override !== null) return $override;
    $spec = media_sort_site_spec();
    $own = items_read($folderReal)['photoSort'] ?? null;
    if (is_array($own)) {
        if (in_array($own['order'] ?? null, ['name', 'taken', 'modified'], true)) $spec['order'] = $own['order'];
        foreach (['desc', 'private', 'video', 'located'] as $k) if (isset($own[$k]) && is_bool($own[$k])) $spec[$k] = $own[$k];
    }
    return $spec;
}

/** The capture time in a TIFF block (the shape EXIF uses): the original date in the Exif part, else the digitized date, else
 *  the file's date field; null when there is none. Read by hand, so it needs no PHP extension and works for JPEG and HEIC alike.
 *  An offset the camera recorded (iPhones do) makes it exact; without one it is taken as UTC, good enough to order by. */
function tiff_capture_time($tiff) {
    $n = strlen($tiff);
    if ($n < 8) return null;
    $le = substr($tiff, 0, 2) === 'II';
    if (!$le && substr($tiff, 0, 2) !== 'MM') return null;
    $u16 = function ($o) use ($tiff, $n, $le) { return $o >= 0 && $o + 2 <= $n ? unpack($le ? 'v' : 'n', $tiff, $o)[1] : null; };
    $u32 = function ($o) use ($tiff, $n, $le) { return $o >= 0 && $o + 4 <= $n ? unpack($le ? 'V' : 'N', $tiff, $o)[1] : null; };
    if ($u16(2) !== 42) return null;
    $entries = function ($ifd) use ($u16) {   // tag => offset of its 12-byte entry
        $out = [];
        $count = $ifd === null ? null : $u16($ifd);
        if ($count === null || $count > 400) return $out;
        for ($i = 0; $i < $count; $i++) { $e = $ifd + 2 + 12 * $i; $tag = $u16($e); if ($tag !== null) $out[$tag] = $e; }
        return $out;
    };
    $text = function ($e) use ($tiff, $n, $u32) {
        $cnt = $u32($e + 4);
        if ($cnt === null || $cnt < 1 || $cnt > 64) return null;
        $at = $cnt <= 4 ? $e + 8 : $u32($e + 8);
        return $at !== null && $at + $cnt <= $n ? rtrim(substr($tiff, $at, $cnt), "\0 ") : null;
    };
    $ifd0 = $entries($u32(4));
    $exifAt = isset($ifd0[0x8769]) ? $u32($ifd0[0x8769] + 8) : null;
    $exif = $exifAt !== null ? $entries($exifAt) : [];
    foreach ([[$exif, 0x9003], [$exif, 0x9004], [$ifd0, 0x0132]] as [$map, $tag]) {
        if (!isset($map[$tag])) continue;
        $t = $text($map[$tag]);
        if ($t === null || !preg_match('/^(\d{4}):(\d\d):(\d\d) (\d\d):(\d\d):(\d\d)$/', $t, $m) || (int) $m[1] < 1971) continue;
        $ts = gmmktime((int) $m[4], (int) $m[5], (int) $m[6], (int) $m[2], (int) $m[3], (int) $m[1]);
        if ($tag === 0x9003 && isset($exif[0x9011]) && preg_match('/^([+-])(\d\d):(\d\d)$/', (string) $text($exif[0x9011]), $o)) {
            $ts -= ($o[1] === '-' ? -1 : 1) * ((int) $o[2] * 3600 + (int) $o[3] * 60);
        }
        return $ts;
    }
    return null;
}

/** The moment a QuickTime/MP4 video was really recorded, from the "creation date" an iPhone keeps in the movie's metadata
 *  (with the time zone, e.g. 2026-10-13T12:54:49+0200), as a timestamp; null when the file has none. The movie header's own
 *  "created" stamp is not used first because it is often when the file was last exported or copied, not when it was shot. */
function quicktime_recorded_time($fh, $meta) {
    if ($meta['end'] - $meta['data'] > 1048576) return null;
    $from = $meta['data'];
    $probe = scrub_read($fh, $from + 4, 4);
    if ($probe !== null && !ctype_alpha($probe)) $from += 4;   // (a version field comes first in some files, not in others)
    $kids = scrub_boxes($fh, $from, $meta['end']);
    $keys = $items = null;
    foreach ($kids ?? [] as $k) { if ($k['type'] === 'keys') $keys = $k; elseif ($k['type'] === 'ilst') $items = $k; }
    if ($keys === null || $items === null) return null;
    $head = scrub_read($fh, $keys['data'], 8);
    if ($head === null) return null;
    $count = unpack('N', $head, 4)[1];
    $pos = $keys['data'] + 8; $want = null;
    for ($i = 1; $i <= $count && $i <= 256 && $pos + 8 <= $keys['end']; $i++) {
        $h = scrub_read($fh, $pos, 8);
        if ($h === null) return null;
        $len = unpack('N', $h)[1];
        if ($len < 8 || $pos + $len > $keys['end']) return null;
        if ($len - 8 <= 64 && scrub_read($fh, $pos + 8, $len - 8) === 'com.apple.quicktime.creationdate') { $want = $i; break; }
        $pos += $len;
    }
    if ($want === null) return null;
    foreach (scrub_boxes($fh, $items['data'], $items['end']) ?? [] as $item) {
        if (unpack('N', $item['type'])[1] !== $want) continue;   // (an item is named by its key's number)
        foreach (scrub_boxes($fh, $item['data'], $item['end']) ?? [] as $d) {
            if ($d['type'] !== 'data' || $d['end'] - $d['data'] < 9 || $d['end'] - $d['data'] > 72) continue;
            $text = scrub_read($fh, $d['data'] + 8, $d['end'] - $d['data'] - 8);
            if ($text === null || !preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(\.\d+)?(Z|[+-]\d\d:?\d\d)$/', $text)) return null;
            $ts = strtotime($text);
            return $ts !== false && $ts > 31536000 && $ts < time() + 172800 ? $ts : null;
        }
    }
    return null;
}

/** When a photo or video was taken, from the file itself: a JPEG's or HEIC's EXIF, a video's creation time. Null when the file
 *  doesn't say (a PNG, a screenshot, a stripped copy). */
function media_capture_time($path) {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $size = (int) @filesize($path);
    $fh = $size > 12 ? @fopen($path, 'rb') : false;
    if (!$fh) return null;
    try {
        if ($ext === 'jpg' || $ext === 'jpeg') {
            if (scrub_read($fh, 0, 2) !== "\xFF\xD8") return null;
            $pos = 2;
            for ($guard = 0; $guard < 40 && $pos + 4 <= $size; $guard++) {
                $h = scrub_read($fh, $pos, 4);
                if ($h === null || $h[0] !== "\xFF") return null;
                $marker = ord($h[1]); $len = unpack('n', $h, 2)[1];
                if ($marker === 0xDA || $marker === 0xD9) return null;
                if ($marker === 0xE1 && $len >= 8 && $len < 1048576) {
                    $d = scrub_read($fh, $pos + 4, $len - 2);
                    if ($d !== null && substr($d, 0, 6) === "Exif\0\0") return tiff_capture_time(substr($d, 6));
                }
                $pos += 2 + $len;
            }
            return null;
        }
        $top = scrub_boxes($fh, 0, $size);
        if ($top === null) return null;
        if ($ext === 'mov' || $ext === 'mp4' || $ext === 'm4v') {
            foreach ($top as $b) {
                if ($b['type'] !== 'moov') continue;
                $kids = scrub_boxes($fh, $b['data'], $b['end']);
                foreach ($kids ?? [] as $k) {
                    if ($k['type'] !== 'meta') continue;
                    $recorded = quicktime_recorded_time($fh, $k);
                    if ($recorded !== null) return $recorded;   // (when it was shot, not when the file was last saved)
                }
                foreach ($kids ?? [] as $k) {
                    if ($k['type'] !== 'mvhd') continue;
                    $body = scrub_read($fh, $k['data'], 12);
                    if ($body === null) return null;
                    $created = ord($body[0]) === 1 ? unpack('J', scrub_read($fh, $k['data'] + 4, 8) ?? str_repeat("\0", 8))[1] : unpack('N', $body, 4)[1];
                    $ts = $created - 2082844800;   // QuickTime counts from 1904
                    return $ts > 31536000 && $ts < time() + 172800 ? $ts : null;
                }
            }
            return null;
        }
        if ($ext === 'heic' || $ext === 'heif') {
            $meta = null;
            foreach ($top as $b) if ($b['type'] === 'meta') $meta = $b;
            $kids = $meta === null ? null : scrub_boxes($fh, $meta['data'] + 4, $meta['end']);
            if ($kids === null) return null;
            $exifId = null;
            foreach ($kids as $k) {
                if ($k['type'] !== 'iinf') continue;
                $skip = scrub_read($fh, $k['data'], 1) === "\0" ? 6 : 8;
                foreach (scrub_boxes($fh, $k['data'] + $skip, $k['end']) ?? [] as $inf) {
                    if ($inf['type'] !== 'infe') continue;
                    $body = scrub_read($fh, $inf['data'], min(64, $inf['end'] - $inf['data']));
                    if ($body === null || strlen($body) < 12 || ord($body[0]) < 2) continue;
                    $v = ord($body[0]);
                    $id = $v === 2 ? unpack('n', $body, 4)[1] : unpack('N', $body, 4)[1];
                    if (substr($body, $v === 2 ? 8 : 10, 4) === 'Exif') $exifId = $id;
                }
            }
            if ($exifId === null) return null;
            foreach ($kids as $k) {
                if ($k['type'] !== 'iloc') continue;
                $h = scrub_read($fh, $k['data'], 8);
                if ($h === null) return null;
                $ver = ord($h[0]);
                $offSize = ord($h[4]) >> 4; $lenSize = ord($h[4]) & 15; $baseSize = ord($h[5]) >> 4; $idxSize = $ver > 0 ? ord($h[5]) & 15 : 0;
                $count = $ver < 2 ? unpack('n', $h, 6)[1] : unpack('N', scrub_read($fh, $k['data'] + 6, 4) ?? "\0\0\0\0")[1];
                $at = $k['data'] + ($ver < 2 ? 8 : 10);
                $num = function ($len) use ($fh, &$at) {
                    if ($len === 0) return 0;
                    $bytes = scrub_read($fh, $at, $len); $at += $len;
                    if ($bytes === null) return null;
                    $x = 0; for ($i = 0; $i < $len; $i++) $x = ($x << 8) | ord($bytes[$i]);
                    return $x;
                };
                for ($i = 0; $i < $count && $i < 4096; $i++) {
                    $id = $num($ver < 2 ? 2 : 4);
                    if ($ver > 0) $num(2);
                    $num(2);
                    $base = $num($baseSize); $ext2 = $num(2);
                    if ($id === null || $base === null || $ext2 === null || $ext2 > 64) return null;
                    $first = null;
                    for ($e = 0; $e < $ext2; $e++) {
                        if ($idxSize) $num($idxSize);
                        $eo = $num($offSize); $el = $num($lenSize);
                        if ($eo === null || $el === null) return null;
                        if ($first === null) $first = [$base + $eo, $el];
                    }
                    if ($id !== $exifId || $first === null || $first[1] < 12 || $first[1] > 1048576) continue;
                    $data = scrub_read($fh, $first[0], $first[1]);
                    return $data === null ? null : tiff_capture_time(substr($data, 4 + unpack('N', $data)[1]));
                }
            }
        }
        return null;
    } catch (\Throwable $e) {
        return null;
    } finally {
        fclose($fh);
    }
}

/** When each photo of a folder was taken, as a number to sort by: the capture date in the file (see media_capture_time), else
 *  its modified time. Reading dates out of many files is slow, so what is found is kept (by file name, size and modified
 *  time) in the folder's cache. */
function media_taken_times($folderReal, $names) {
    $cachePath = $folderReal . '/.gallery-cache/taken3.json';
    $known = [];
    $raw = is_file($cachePath) ? json_decode((string) @file_get_contents($cachePath), true) : null;
    if (is_array($raw)) $known = $raw;
    $out = []; $changed = false; $budget = 20000;
    foreach ($names as $name) {
        $path = $folderReal . '/' . $name;
        $mtime = (int) @filemtime($path); $size = (int) @filesize($path);
        $hit = $known[$name] ?? null;
        if (is_array($hit) && ($hit[0] ?? null) === $mtime && ($hit[1] ?? null) === $size && is_int($hit[2] ?? null)) { $out[$name] = $hit[2]; continue; }
        $when = $mtime;
        if ($budget > 0) {
            $budget--;
            $found = media_capture_time($path);
            if ($found !== null) $when = $found;
        }
        $out[$name] = $when;
        $known[$name] = [$mtime, $size, $when];
        $changed = true;
    }
    if ($changed) {
        $live = array_intersect_key($known, array_flip($names));
        $dirOk = is_dir($folderReal . '/.gallery-cache') || @mkdir($folderReal . '/.gallery-cache', 0755);
        if ($dirOk) {
            $tmp = $cachePath . '.tmp' . bin2hex(random_bytes(3));
            if (@file_put_contents($tmp, json_encode($live)) !== false && !@rename($tmp, $cachePath)) @unlink($tmp);
        }
    }
    return $out;
}

/** A folder's stacks: photos the owner placed next to each other by hand, which stay together (in the order set) wherever
 *  the sort puts them. Stored in the folder's settings as [{anchor, files: [bare names]}]; the anchor is the member whose own
 *  place in the sort the whole stack takes. $list is that folder's photos (relative paths): returns the stacks as lists of
 *  the paths found there, anchor first named, skipping what is gone and any stack left with fewer than two. */
function media_stacks_resolve($folderReal, $list) {
    $stacks = items_read($folderReal)['stacks'] ?? [];
    if (!is_array($stacks) || !$stacks) return [];
    $byBare = [];
    foreach ($list as $f) $byBare[basename($f)] = $f;
    $out = []; $used = [];
    foreach ($stacks as $s) {
        if (!is_array($s) || !is_array($s['files'] ?? null)) continue;
        $members = [];
        foreach ($s['files'] as $name) {
            if (is_string($name) && isset($byBare[$name]) && !isset($used[$byBare[$name]])) { $members[] = $byBare[$name]; $used[$byBare[$name]] = true; }
        }
        if (count($members) < 2) continue;
        $anchor = is_string($s['anchor'] ?? null) && isset($byBare[$s['anchor']]) && in_array($byBare[$s['anchor']], $members, true) ? $byBare[$s['anchor']] : $members[0];
        $out[] = ['anchor' => $anchor, 'files' => $members];
    }
    return $out;
}

