/** The stacks as stored, cleaned: names only, each photo in one stack at most, an anchor that is a member, and nothing left
 *  that holds fewer than two photos. */
function media_stacks_clean($stacks) {
    $out = []; $used = [];
    foreach (is_array($stacks) ? $stacks : [] as $s) {
        if (!is_array($s) || !is_array($s['files'] ?? null)) continue;
        $files = [];
        foreach ($s['files'] as $n) if (is_string($n) && $n !== '' && !isset($used[$n])) { $files[] = $n; $used[$n] = true; }
        if (count($files) < 2) { foreach ($files as $n) unset($used[$n]); continue; }
        $anchor = is_string($s['anchor'] ?? null) && in_array($s['anchor'], $files, true) ? $s['anchor'] : $files[0];
        $out[] = ['anchor' => $anchor, 'files' => $files];
    }
    return $out;
}

/** Change a folder's stacks inside its settings ($data): 'place' puts the photos $names (bare names, in the order wanted)
 *  just before or after $target, joining its stack or starting one with it as the anchor; 'release' takes them out of their
 *  stacks; 'reset' clears every stack. */
function media_stacks_change(&$data, $mode, $names = [], $target = '', $side = 'after') {
    if ($mode === 'reset') { $data['stacks'] = []; return; }
    $stacks = media_stacks_clean($data['stacks'] ?? []);
    foreach ($stacks as &$s) {
        $s['files'] = array_values(array_diff($s['files'], $names));
        if (!in_array($s['anchor'], $s['files'], true)) $s['anchor'] = $s['files'][0] ?? '';
    }
    unset($s);
    if ($mode === 'place') {
        $placed = false;
        foreach ($stacks as &$s) {
            $i = array_search($target, $s['files'], true);
            if ($i === false) continue;
            array_splice($s['files'], $side === 'before' ? $i : $i + 1, 0, $names);
            $placed = true;
            break;
        }
        unset($s);
        if (!$placed) $stacks[] = ['anchor' => $target, 'files' => $side === 'before' ? array_merge($names, [$target]) : array_merge([$target], $names)];
    }
    $data['stacks'] = media_stacks_clean($stacks);
}

/** $files (relative paths) with the photos of each folder put in that folder's order (see above); folders keep their places.
 *  A stack placed by hand is sorted as its anchor is and its members follow in the order set. */
function media_sort_apply($files, $dir) {
    if (count($files) < 2) return $files;
    $byFolder = [];
    foreach ($files as $f) $byFolder[containing_folder_real_path($dir, $f)][] = $f;
    $scanned = PHOTO_SORT_ORDER === 'mtime' ? 'modified' : 'name';   // the order the scan already produced
    $revealed = is_revealed();
    $result = [];
    foreach ($byFolder as $folderReal => $list) {
        $spec = media_sort_spec_for_folder($folderReal);
        $prio = media_sort_priority_for_folder($folderReal);
        if (!$revealed) $spec['private'] = false;   // visitors never see private items: nothing to put first
        $stacks = count($list) < 2 ? [] : media_stacks_resolve($folderReal, $list);
        if (count($list) < 2 || (!$stacks && $spec['order'] === $scanned && !$spec['desc'] && !$spec['private'] && !$spec['video'] && !$spec['located'])) { foreach ($list as $f) $result[] = $f; continue; }
        $info = [];
        if ($spec['private'] || $spec['located']) $itemsData = items_get_all_for_files($dir, $list); else $itemsData = [];
        $times = $spec['order'] === 'name' ? [] : media_taken_times($folderReal, array_map('basename', $list));
        foreach ($list as $f) {
            $info[$f] = [
                'private' => $spec['private'] && item_restriction($itemsData[$f] ?? null) === 'private',   // marked private on their own (all of a private folder is hidden, which sets nothing apart)
                'video' => $spec['video'] && is_video_file($f),
                'located' => $spec['located'] && photo_location_visible($itemsData[$f] ?? []),
                't' => $spec['order'] === 'modified' ? (int) @filemtime(resolve_real_path($dir, $f)) : ($times[basename($f)] ?? 0),
            ];
        }
        $unit = []; $follower = [];   // a stack's members by its anchor; the members that ride along with it
        foreach ($stacks as $st) {
            $unit[$st['anchor']] = $st['files'];
            foreach ($st['files'] as $m) if ($m !== $st['anchor']) $follower[$m] = true;
        }
        $list = array_values(array_filter($list, function ($f) use ($follower) { return !isset($follower[$f]); }));
        usort($list, function ($a, $b) use ($info, $spec, $prio) {
            foreach ($prio as $k) {
                if ($spec[$k]) { $d = ($info[$a][$k] ? 0 : 1) <=> ($info[$b][$k] ? 0 : 1); if ($d !== 0) return $d; }
            }
            $c = $spec['order'] === 'name' ? 0 : $info[$a]['t'] <=> $info[$b]['t'];
            if ($c === 0) $c = strnatcasecmp(basename($a), basename($b));
            return $spec['desc'] ? -$c : $c;
        });
        foreach ($list as $f) {
            if (isset($unit[$f])) { foreach ($unit[$f] as $m) $result[] = $m; } else $result[] = $f;
        }
    }
    return $result;
}

/** Whether this PHP can read embedded GPS out of this kind of file at all
 *  (JPEG needs the exif extension, HEIC/HEIF needs Imagick). Anything else
 *  — PNG, GIF, WEBP — has no GPS block, so there is nothing to wait
 *  for or to mark as checked. */
function gps_readable_file($file) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if ($ext === 'jpg' || $ext === 'jpeg') return function_exists('exif_read_data');
    if ($ext === 'heic' || $ext === 'heif') return class_exists('Imagick');
    if ($ext === 'mov' || $ext === 'mp4') return true;   // read from the file's own location tag (see extract_video_gps)
    return false;
}

/* -----------------------------------------------------------------------
 * What the gallery has read from the media files themselves ("media records").
 *
 * Whenever the gallery first meets a photo or video that has no record, it
 * reads from the file when it was taken and where (its GPS), and keeps the
 * answer in the folder's cache, next to the thumbnails: one record per file,
 * keyed by the file's modified time and size, so a replaced file is simply a
 * file with no record and is read again, and a file that couldn't be read is
 * retried. Nothing is "checked off" for good. The owner's own entries live in
 * the folder's data file and always win over what a file says (a location
 * typed in, or cleared on purpose, stands). The folder's and the site's
 * location switches decide what is SHOWN, never what is read: a location the
 * gallery has read is only a location it knows.
 *
 * The coordinates are private data like the data files, so the records are
 * kept in a guarded .php file (a direct request answers 404), not in a plain
 * file next to the pictures. They are never part of the Export. The cache can
 * be deleted at any time; the records are read again.
 *
 * Files are read when their thumbnails are first made (a visitor's first look
 * at a new photo, or Prepare media), and by Prepare media for everything else.
 * ----------------------------------------------------------------------- */

/** The records live in the thumbnail cache's folder, so with the cache switched off there is nowhere to keep them. */
function media_meta_enabled() { return ENABLE_THUMBNAIL_CACHE; }

function media_meta_path($folderReal) { return $folderReal . '/.gallery-cache/' . MEDIA_META_FILE; }

/** A folder's records, name => record, read once per request ($reset forgets what was read, after a write). */
function media_meta_load($folderReal, $reset = false) {
    static $memo = [];
    if ($reset) { unset($memo[$folderReal]); return []; }
    if (!isset($memo[$folderReal])) {
        $memo[$folderReal] = [];
        if (media_meta_enabled()) {
            $path = media_meta_path($folderReal);
            if (is_file($path)) {
                $d = json_file_read($path, ['v' => 1, 'f' => []]);
                if (is_array($d['f'] ?? null)) $memo[$folderReal] = $d['f'];
            }
        }
    }
    return $memo[$folderReal];
}

/** Whether a record still describes the file as it is now (the same modified time and size). */
function media_meta_fresh($rec, $realPath) {
    return is_array($rec) && ($rec['m'] ?? null) === (int) @filemtime($realPath) && ($rec['s'] ?? null) === (int) @filesize($realPath);
}

/** A fresh record whose file could be read: nothing is left to do for this file. (A file that couldn't be read is tried again.) */
function media_meta_ready($rec, $realPath) {
    return media_meta_fresh($rec, $realPath) && ($rec['gs'] ?? '') !== 'unreadable';
}

/** What one file says about itself: [m, s] its modified time and size, t when it was taken (or null), g its [lat, lng] (or null),
 *  gs how the location read went ('ok', 'none' = the file has none, 'unreadable' = the file couldn't be read, 'na' = this kind of
 *  file doesn't carry one), r when it was read. Never throws. */
function media_meta_read_file($realPath) {
    $rec = ['m' => (int) @filemtime($realPath), 's' => (int) @filesize($realPath), 't' => null, 'g' => null, 'gs' => 'na', 'r' => time()];
    if (!is_readable($realPath)) { $rec['gs'] = 'unreadable'; return $rec; }
    try { $t = media_capture_time($realPath); } catch (Throwable $e) { $t = null; }
    $rec['t'] = is_int($t) ? $t : null;
    $ext = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg', 'jpeg', 'heic', 'heif', 'mov', 'mp4'], true)) {
        if (!gps_readable_file($realPath)) {
            $rec['gs'] = 'unreadable';   // this PHP lacks what it takes to read this kind of file (the exif extension, or Imagick)
        } else {
            $status = 'none';
            try { $loc = extract_photo_gps($realPath, $status); } catch (Throwable $e) { $loc = null; $status = 'unreadable'; }
            $rec['gs'] = $status;
            if ($loc !== null) $rec['g'] = [$loc['lat'], $loc['lng']];
        }
    }
    return $rec;
}

/** Saves records ([name => record]) into a folder's cache file, merging with what is there. False when it can't be written (no
 *  cache folder to keep them in, or it isn't writable): nothing is lost then, the files are simply read again next time. */
function media_meta_store($folderReal, array $records) {
    if (!$records || !media_meta_enabled()) return false;
    $cacheDir = $folderReal . '/.gallery-cache';
    if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0755)) return false;
    $failedBefore = !empty($GLOBALS['__data_write_failed']);
    $GLOBALS['__data_write_failed'] = false;
    json_file_update(media_meta_path($folderReal), ['v' => 1, 'f' => []], function (&$d) use ($records) {
        if (!is_array($d['f'] ?? null)) $d['f'] = [];
        foreach ($records as $name => $rec) $d['f'][$name] = $rec;
    });
    $ok = empty($GLOBALS['__data_write_failed']);
    $GLOBALS['__data_write_failed'] = $failedBefore;   // (a cache that can't be written to is not a failed save of the owner's data)
    media_meta_load($folderReal, true);
    return $ok;
}

/** Makes sure one file has a record: the first time the gallery meets it (when its thumbnail is made), or when it has changed or couldn't be read before. */
function media_meta_ensure($dir, $file) {
    if (!media_meta_enabled()) return;
    $real = resolve_real_path($dir, $file);
    if (!$real || !is_file($real)) return;
    $folderReal = containing_folder_real_path($dir, $file);
    if (media_meta_ready(media_meta_load($folderReal)[basename($file)] ?? null, $real)) return;
    media_meta_store($folderReal, [basename($file) => media_meta_read_file($real)]);
}

/** What the gallery knows from the files of one folder level ($looseFiles: the files directly in it, as relative paths): how many files,
 *  how many have a location in the file / none / couldn't be read / haven't been read yet / are of a kind that has none, how many
 *  carry a capture date, how many locations are the owner's own or were cleared on purpose, how many carry an old "read, nothing
 *  found" mark, how many have a location from the file that is the one shown (`fromFile`), and the names of the unreadable ones. */
function media_folder_summary($dir, $folderReal, $looseFiles) {
    $items = items_read($folderReal)['items'] ?? [];
    $recs = media_meta_load($folderReal);
    $out = ['files' => 0, 'found' => 0, 'none' => 0, 'unreadable' => 0, 'unread' => 0, 'na' => 0, 'dated' => 0, 'undated' => 0, 'own' => 0, 'cleared' => 0, 'legacy' => 0, 'fromFile' => 0, 'bad' => []];
    foreach ($looseFiles as $f) {
        $bare = basename($f);
        $real = $folderReal . '/' . $bare;
        $entry = $items[$bare] ?? [];
        $out['files']++;
        if (!empty($entry['location'])) $out['own']++;
        elseif (!empty($entry['locationCleared'])) $out['cleared']++;
        elseif (!empty($entry['gpsChecked'])) $out['legacy']++;
        $rec = $recs[$bare] ?? null;
        if (!media_meta_fresh($rec, $real)) { $out['unread']++; continue; }
        $gs = (string) ($rec['gs'] ?? 'na');
        if ($gs === 'ok') $out['found']++;
        elseif ($gs === 'none') $out['none']++;
        elseif ($gs === 'unreadable') { $out['unreadable']++; $out['bad'][] = $bare; }
        else $out['na']++;
        if (($rec['t'] ?? null) !== null) $out['dated']++; else $out['undated']++;
        if (media_file_location($entry, $rec) !== null) $out['fromFile']++;
    }
    return $out;
}

/** The saved-location shape for a photo that has no location of its own but whose file has one: what the gallery shows (when the
 *  gates are open) instead. Null when the owner has a say: a location typed in (it stands), one cleared on purpose, or, for
 *  entries made before the records existed, a photo marked as read with no location (which can't be told from one the owner
 *  cleared, so it is left alone; Prepare media's "Read everything again" reads those too). */
function media_file_location($entry, $rec) {
    if (!empty($entry['location']) || !empty($entry['locationCleared']) || !empty($entry['gpsChecked'])) return null;
    if (!is_array($rec) || !is_array($rec['g'] ?? null) || ($rec['gs'] ?? '') !== 'ok') return null;
    return ['lat' => (float) $rec['g'][0], 'lng' => (float) $rec['g'][1], 'label' => ''];
}

/** Tidy up: what a folder's saved data holds that the files already say. Older versions copied each photo's location from its file into the
 *  folder's data (and marked the photo "checked"); the files, read once into the cache, are now the only place that belongs. This goes through
 *  $names (bare file names of one folder) in $items (the folder's saved item data, changed in place) and takes out the copies and the redundant
 *  marks, and nothing else: captions, links, private flags and every other field are never touched.
 *   - a saved location equal to the file's (to about 10 m) with no label is a copy: removed;
 *   - one that differs from the file's with no label: kept and listed, or with $refresh (the owner typed none) removed so the file's shows;
 *   - one with a label (the owner's words): always kept, and listed;
 *   - one the file can't confirm (it has none, couldn't be read, or hasn't been read yet): kept, and listed;
 *   - "checked" marks: removed where redundant (a location is saved, the file has none, a location was cleared on purpose) and, with $refresh,
 *     where the file has a location; otherwise left, and listed, because they may mean the owner removed the location.
 *  Returns counts (n) and capped lists of what was kept. */
function media_tidy_items(array &$items, array $recs, $folderReal, array $names, $refresh) {
    $n = ['copies' => 0, 'refreshed' => 0, 'marks' => 0, 'edited' => 0, 'labelled' => 0, 'noFile' => 0, 'ambiguous' => 0, 'unread' => 0, 'changed' => 0];
    $lists = ['edited' => [], 'labelled' => [], 'noFile' => [], 'ambiguous' => []];
    $note = function ($kind, $name, $saved, $file) use (&$lists) { if (count($lists[$kind]) < 60) $lists[$kind][] = ['name' => $name, 'saved' => $saved, 'file' => $file]; };
    foreach ($names as $bare) {
        if (!isset($items[$bare]) || !is_array($items[$bare])) continue;
        $e = $items[$bare]; $new = $e;
        $rec = $recs[$bare] ?? null;
        $fresh = media_meta_fresh($rec, $folderReal . '/' . $bare);
        $fileLoc = ($fresh && ($rec['gs'] ?? '') === 'ok' && is_array($rec['g'] ?? null)) ? [(float) $rec['g'][0], (float) $rec['g'][1]] : null;
        $fileNone = $fresh && in_array($rec['gs'] ?? '', ['none', 'na'], true);
        if (!empty($e['location']) && is_array($e['location'])) {
            $saved = [(float) ($e['location']['lat'] ?? 0), (float) ($e['location']['lng'] ?? 0)];
            $label = trim((string) ($e['location']['label'] ?? ''));
            if ($fileLoc === null) {
                if (!$fresh) $n['unread']++;
                $n['noFile']++; $note('noFile', $bare, $saved, null);
            } elseif ($label !== '') {
                $n['labelled']++; $note('labelled', $bare, $saved, $fileLoc);
            } elseif (abs($saved[0] - $fileLoc[0]) < 0.0001 && abs($saved[1] - $fileLoc[1]) < 0.0001) {
                $n['copies']++; unset($new['location']);
            } elseif ($refresh) {
                $n['refreshed']++; unset($new['location']);
            } else {
                $n['edited']++; $note('edited', $bare, $saved, $fileLoc);
            }
            unset($new['gpsChecked']);   // (a photo with a location of its own needs no mark)
        } elseif (!empty($e['gpsChecked'])) {
            if (!empty($e['locationCleared']) || $fileNone || ($fileLoc !== null && $refresh)) unset($new['gpsChecked']);
            elseif ($fileLoc !== null) { $n['ambiguous']++; $note('ambiguous', $bare, null, $fileLoc); }
            elseif (!$fresh) $n['unread']++;
        }
        if (array_key_exists('gpsChecked', $e) && !array_key_exists('gpsChecked', $new)) $n['marks']++;
        if ($new !== $e) {
            $n['changed']++;
            if ($new === []) unset($items[$bare]); else $items[$bare] = $new;
        }
    }
    return ['n' => $n, 'lists' => $lists];
}

/** Tidy up across the whole gallery: a preview ($apply false: nothing is written) or the real thing. Each folder that will change is copied
 *  first to .gallery-items.before-tidy-<date>.php (guarded like the original); a folder whose backup can't be made is left alone and reported. */
function media_tidy_run($dir, $files, $refresh, $apply) {
    $byFolder = [];
    foreach ($files as $file) {
        $real = containing_folder_real_path($dir, $file);
        $byFolder[$real]['names'][] = basename($file);
        $byFolder[$real]['folder'] = ($slash = strrpos($file, '/')) === false ? '' : substr($file, 0, $slash);
    }
    $rows = []; $errors = []; $backups = 0; $stamp = date('Ymd-His');
    foreach ($byFolder as $folderReal => $info) {
        $recs = media_meta_load($folderReal);
        $items = items_read($folderReal)['items'] ?? [];
        if (!is_array($items)) continue;
        $res = media_tidy_items($items, $recs, $folderReal, $info['names'], $refresh);
        $row = ['folder' => $info['folder']] + $res;
        if ($apply && $res['n']['changed'] > 0) {
            $src = items_path($folderReal);
            $backup = $folderReal . '/.gallery-items.before-tidy-' . $stamp . '.php';
            if (!@copy($src, $backup)) {
                $errors[] = ($info['folder'] === '' ? '(top level)' : $info['folder']) . ': a backup could not be made (is the folder writable by the web server?), so it was left as it is.';
                $row['skipped'] = true;
                $rows[] = $row; continue;
            }
            $backups++;
            $failedBefore = !empty($GLOBALS['__data_write_failed']); $GLOBALS['__data_write_failed'] = false;
            $names = $info['names'];
            items_update($folderReal, function (&$d) use ($recs, $folderReal, $names, $refresh) {
                $its = is_array($d['items'] ?? null) ? $d['items'] : [];
                media_tidy_items($its, $recs, $folderReal, $names, $refresh);
                $d['items'] = $its;
            });
            if (!empty($GLOBALS['__data_write_failed'])) { $errors[] = ($info['folder'] === '' ? '(top level)' : $info['folder']) . ': the changes could not be saved.'; $row['skipped'] = true; }
            $GLOBALS['__data_write_failed'] = $failedBefore;
        }
        $rows[] = $row;
    }
    $tot = ['copies' => 0, 'refreshed' => 0, 'marks' => 0, 'edited' => 0, 'labelled' => 0, 'noFile' => 0, 'ambiguous' => 0, 'unread' => 0, 'changed' => 0, 'folders' => 0];
    foreach ($rows as $r) { foreach ($r['n'] as $k => $v) $tot[$k] += $v; if ($r['n']['changed'] > 0) $tot['folders']++; }
    return ['rows' => $rows, 'totals' => $tot, 'errors' => $errors, 'backups' => $backups];
}

/** A numeric latitude/longitude pair, validated the same discipline
 *  is_safe_color() already applies to {color:} before it's ever trusted
 *  into a generated map-link URL. */
function is_valid_coordinate($lat, $lng) {
    return is_numeric($lat) && is_numeric($lng)
        && (float) $lat >= -90 && (float) $lat <= 90
        && (float) $lng >= -180 && (float) $lng <= 180;
}

/** Converts one EXIF GPS component (3 degree/minute/second rationals,
 *  e.g. "48/1", "51/1", "3056/100") plus its N/S/E/W ref into a signed
 *  decimal-degree float. */
function exif_gps_to_decimal($component, $ref) {
    if (!is_array($component) || count($component) < 3) return null;
    $toFloat = function ($frac) {
        if (is_string($frac) && strpos($frac, '/') !== false) {
            [$num, $den] = array_pad(explode('/', $frac, 2), 2, '1');
            $den = (float) $den;
            return $den != 0 ? ((float) $num) / $den : 0.0;
        }
        return (float) $frac;
    };
    $decimal = $toFloat($component[0]) + ($toFloat($component[1]) / 60) + ($toFloat($component[2]) / 3600);
    return (strtoupper((string) $ref) === 'S' || strtoupper((string) $ref) === 'W') ? -$decimal : $decimal;
}

/** Auto-fills location from a photo's embedded EXIF GPS block, if any:
 *  JPEG via exif_read_data(), HEIC/HEIF via Imagick (see
 *  extract_heic_gps). Anything else (PNG, GIF, WEBP, BMP, video) has no
 *  GPS block to read, so manual entry is the only route. Returns null on
 *  anything missing or unparseable — manual entry is always the
 *  fallback, never a hard failure. */
function extract_photo_gps($realPath, &$status = null) {
    $status = 'none';   // 'ok' (a location was found), 'none' (the file has none) or 'unreadable' (the file couldn't be read at all)
    $ext = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
    if (in_array($ext, ['heic', 'heif'], true)) {
        return extract_heic_gps($realPath, $status);
    }
    if (in_array($ext, ['mov', 'mp4'], true)) {
        return extract_video_gps($realPath, $status);
    }
    return extract_jpeg_gps($realPath, $status);
}

/** A video's location, from the tag phones write into it: QuickTime's "location.ISO6709" item (iPhone) or the
 *  \xA9xyz atom (Android and most others), both a string like "+48.8584+002.2945+035.000/". Null if it has none. */
function extract_video_gps($realPath, &$status = null) {
    $status = 'none';
    $size = @filesize($realPath);
    $fh = $size === false ? false : @fopen($realPath, 'rb');
    if (!$fh) { $status = 'unreadable'; return null; }
    $strings = [];
    try {
        $walk = function ($from, $to, $depth) use (&$walk, &$strings, $fh) {
            $boxes = scrub_boxes($fh, $from, $to);
            if ($boxes === null) return;
            foreach ($boxes as $b) {
                $type = $b['type'];
                if ($type === "\xA9xyz") {
                    $v = scrub_read($fh, $b['data'], min(64, $b['end'] - $b['data']));
                    if ($v !== null && strlen($v) > 4) $strings[] = substr($v, 4);   // 2 bytes length + 2 bytes language, then the text
                } elseif (in_array($type, ['moov', 'trak', 'udta'], true) && $depth < 6) {
                    $walk($b['data'], $b['end'], $depth + 1);
                } elseif ($type === 'meta' && $depth < 6) {
                    $probe = scrub_read($fh, $b['data'] + 4, 4);
                    $inner = $probe === 'hdlr' ? $b['data'] : $b['data'] + 4;
                    $kids = scrub_boxes($fh, $inner, $b['end']);
                    if ($kids === null) continue;
                    $locationKeys = [];
                    foreach ($kids as $k) {
                        if ($k['type'] !== 'keys') continue;
                        $head = scrub_read($fh, $k['data'], 8);
                        if ($head === null) continue;
                        $entries = unpack('N', $head, 4)[1];
                        $at = $k['data'] + 8;
                        for ($i = 1; $i <= $entries && $i < 512; $i++) {
                            $eh = scrub_read($fh, $at, 8);
                            if ($eh === null) break;
                            $elen = unpack('N', $eh)[1];
                            if ($elen < 8 || $at + $elen > $k['end']) break;
                            $name = scrub_read($fh, $at + 8, $elen - 8);
                            if ($name !== null && stripos($name, 'location.ISO6709') !== false) $locationKeys[$i] = true;
                            $at += $elen;
                        }
                    }
                    foreach ($kids as $k) {
                        if ($k['type'] !== 'ilst' || !$locationKeys) continue;
                        foreach (scrub_boxes($fh, $k['data'], $k['end']) ?? [] as $item) {
                            if (!isset($locationKeys[unpack('N', $item['type'])[1]])) continue;
                            foreach (scrub_boxes($fh, $item['data'], $item['end']) ?? [] as $d) {
                                if ($d['type'] === 'data' && $d['end'] - $d['data'] > 8) {
                                    $v = scrub_read($fh, $d['data'] + 8, min(64, $d['end'] - $d['data'] - 8));
                                    if ($v !== null) $strings[] = $v;
                                }
                            }
                        }
                    }
                }
            }
        };
        $walk(0, $size, 0);
    } catch (Throwable $e) {
        $strings = [];
    } finally {
        fclose($fh);
    }
    foreach ($strings as $str) {
        if (preg_match('/^\s*([+-]\d{1,2}(?:\.\d+)?)([+-]\d{1,3}(?:\.\d+)?)/', $str, $m) && is_valid_coordinate($m[1], $m[2])) {
            $status = 'ok';
            return ['lat' => round((float) $m[1], 6), 'lng' => round((float) $m[2], 6)];
        }
    }
    return null;
}

