/* -----------------------------------------------------------------------
 * Image serving: resized/cached thumbnails, HEIC conversion.
 * ----------------------------------------------------------------------- */

function guess_mime($ext) {
    $map = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp',
        'heic' => 'image/heic', 'heif' => 'image/heif',
        'mp4' => 'video/mp4', 'mov' => 'video/quicktime',
    ];
    return $map[$ext] ?? 'application/octet-stream';
}

/**
 * Serves an original, untransformed file — hands it to nginx's own
 * static serving via X-Accel-Redirect once NGINX_ACCEL_REDIRECT_PREFIX
 * is configured to match a real `internal` location block for this
 * gallery's own path (see the README), instead of reading it with PHP.
 * $relativePath is the file's path relative to the gallery root, used to
 * build the URL path nginx matches against its own location blocks —
 * not a filesystem path, and it has to line up exactly with that config.
 * Falls back to plain readfile() when the prefix is unset, which is also
 * what makes leaving it empty safe to ship as the default: zero nginx
 * config required, works correctly on any host, same as always.
 */
/** True when a file lies where the web server itself would find it at the address the gallery shows it under: inside the
 *  gallery's own folder, at the same relative path. Photos and videos brought in from elsewhere on the server
 *  (EXTRA_FOLDERS, EXTRA_ROOTS) do not, so nginx cannot send them: the gallery sends those itself. */
function file_is_under_web_root($srcPath, $relativePath) {
    $real = @realpath($srcPath);
    $expected = @realpath(rtrim($GLOBALS['dir'], '/') . '/' . $relativePath);
    return $real !== false && $expected !== false && $real === $expected;
}

function serve_original_file($srcPath, $relativePath, $mimeType) {
    header('Content-Type: ' . $mimeType);
    if (NGINX_ACCEL_REDIRECT_PREFIX !== '' && file_is_under_web_root($srcPath, $relativePath)) {
        // Each path segment encoded separately — the slashes themselves
        // are the URL structure nginx's location block matches against,
        // not something to escape away.
        $urlPath = implode('/', array_map('rawurlencode', explode('/', $relativePath)));
        header('X-Accel-Redirect: ' . rtrim(NGINX_ACCEL_REDIRECT_PREFIX, '/') . '/' . $urlPath);
        return;
    }
    readfile($srcPath);
}

/* -----------------------------------------------------------------------
 * A copy of an original without its location. A photo whose location the
 * gallery does not show is downloaded with the coordinates blanked out
 * of the file, never re-encoded: location_scrub_plan() finds where the
 * location sits (EXIF's GPS block, the XMP and IPTC place fields, a
 * video's location atom) and returns same-length replacements, so the
 * picture is byte-for-byte what it was and the file keeps its size.
 * Null means the file couldn't be read safely, and then nothing is sent.
 * ----------------------------------------------------------------------- */

/** $len bytes at $off, or null if they aren't there (or the block is absurdly large for metadata). */
function scrub_read($fh, $off, $len) {
    if ($len < 0 || $off < 0 || $len > 33554432) return null;
    if ($len === 0) return '';
    if (fseek($fh, $off) !== 0) return null;
    $b = fread($fh, $len);
    return is_string($b) && strlen($b) === $len ? $b : null;
}

/** The GPS block of a TIFF (the shape EXIF uses), zeroed: [[fileOffset, bytes], ...], or null if it doesn't parse.
 *  $base is where the TIFF starts in the file. */
function scrub_tiff_gps($tiff, $base) {
    $n = strlen($tiff);
    if ($n < 8) return null;
    $le = substr($tiff, 0, 2) === 'II';
    if (!$le && substr($tiff, 0, 2) !== 'MM') return null;
    $u16 = function ($o) use ($tiff, $n, $le) { return $o >= 0 && $o + 2 <= $n ? unpack($le ? 'v' : 'n', $tiff, $o)[1] : null; };
    $u32 = function ($o) use ($tiff, $n, $le) { return $o >= 0 && $o + 4 <= $n ? unpack($le ? 'V' : 'N', $tiff, $o)[1] : null; };
    if ($u16(2) !== 42) return null;
    $sizes = [1 => 1, 2 => 1, 3 => 2, 4 => 4, 5 => 8, 6 => 1, 7 => 1, 8 => 2, 9 => 4, 10 => 8, 11 => 4, 12 => 8, 13 => 4, 16 => 8, 17 => 4, 18 => 4];
    $patches = [];
    $zero = function ($off, $len) use (&$patches, $base, $n) {
        if ($off < 0 || $len < 0 || $off + $len > $n) return false;
        if ($len > 0) $patches[] = [$base + $off, str_repeat("\0", $len)];
        return true;
    };
    $ifd = $u32(4);
    for ($guard = 0; $ifd !== null && $ifd !== 0 && $guard < 4; $guard++) {
        $count = $u16($ifd);
        if ($count === null || $count > 1000) return null;
        for ($i = 0; $i < $count; $i++) {
            $e = $ifd + 2 + 12 * $i;
            $tag = $u16($e);
            if ($tag === null) return null;
            if ($tag !== 0x8825) continue;
            $gps = $u32($e + 8);
            $g = $gps === null ? null : $u16($gps);
            if ($g === null || $g > 256) return null;
            for ($j = 0; $j < $g; $j++) {
                $ge = $gps + 2 + 12 * $j;
                $type = $u16($ge + 2); $cnt = $u32($ge + 4);
                if ($type === null || $cnt === null) return null;
                $size = ($sizes[$type] ?? 1) * $cnt;
                if ($size > 4) { $at = $u32($ge + 8); if ($at === null || !$zero($at, $size)) return null; }
            }
            if (!$zero($gps, 2 + 12 * $g + 4)) return null;
        }
        $ifd = $u32($ifd + 2 + 12 * $count);
    }
    return $patches;
}

/** XMP text: the values of GPS and place-name properties, replaced by spaces of the same length. */
function scrub_xmp_patches($xml, $base) {
    $patches = [];
    $names = 'exif:GPS[A-Za-z]+|photoshop:(?:City|State|Country)|Iptc4xmpCore:(?:Location|CountryCode)';
    foreach (['/\b(?:' . $names . ')\s*=\s*(["\'])(.*?)\1/s' => 2, '/<(' . $names . ')(?:\s[^>]*)?>(.*?)<\/\1\s*>/s' => 2] as $re => $group) {
        if (!preg_match_all($re, $xml, $m, PREG_OFFSET_CAPTURE)) continue;
        foreach ($m[$group] as [$text, $off]) {
            if ($text !== '') $patches[] = [$base + $off, str_repeat(' ', strlen($text))];
        }
    }
    return $patches;
}

/** IPTC (inside a Photoshop APP13 block): the city, sub-location, state and country fields, blanked. */
function scrub_iptc_patches($d, $base) {
    $patches = [];
    $n = strlen($d);
    $p = 0;
    while ($p + 12 <= $n && substr($d, $p, 4) === '8BIM') {
        $id = unpack('n', $d, $p + 4)[1];
        $nameLen = ord($d[$p + 6]);
        $q = $p + 7 + $nameLen; if (($nameLen + 1) % 2) $q++;
        if ($q + 4 > $n) break;
        $len = unpack('N', $d, $q)[1];
        $dataAt = $q + 4;
        if ($id === 0x0404) {
            $r = $dataAt; $end = min($n, $dataAt + $len);
            while ($r + 5 <= $end && $d[$r] === "\x1C") {
                $rec = ord($d[$r + 1]); $tag = ord($d[$r + 2]); $l = unpack('n', $d, $r + 3)[1];
                if ($l & 0x8000 || $r + 5 + $l > $end) break;
                if ($rec === 2 && in_array($tag, [90, 92, 95, 100, 101], true) && $l > 0) $patches[] = [$base + $r + 5, str_repeat(' ', $l)];
                $r += 5 + $l;
            }
        }
        $p = $dataAt + $len + ($len % 2);
    }
    return $patches;
}

function scrub_plan_jpeg($fh, $size) {
    if (scrub_read($fh, 0, 2) !== "\xFF\xD8") return null;
    $patches = [];
    $pos = 2;
    while (true) {
        if ($pos + 2 > $size) return null;
        $h = scrub_read($fh, $pos, 2);
        if ($h === null || $h[0] !== "\xFF") return null;
        $m = ord($h[1]);
        if ($m === 0xFF) { $pos++; continue; }
        if ($m === 0xD8 || $m === 0x01 || ($m >= 0xD0 && $m <= 0xD7)) { $pos += 2; continue; }
        if ($m === 0xDA || $m === 0xD9) break;   // the picture itself starts: every piece of metadata is before this
        $lenBytes = scrub_read($fh, $pos + 2, 2);
        if ($lenBytes === null) return null;
        $len = unpack('n', $lenBytes)[1];
        if ($len < 2) return null;
        $dataOff = $pos + 4; $dataLen = $len - 2;
        if ($m === 0xE1 || $m === 0xED) {
            $data = scrub_read($fh, $dataOff, $dataLen);
            if ($data === null) return null;
            if ($m === 0xE1 && strncmp($data, "Exif\0\0", 6) === 0) {
                $t = scrub_tiff_gps(substr($data, 6), $dataOff + 6);
                if ($t === null) return null;
                $patches = array_merge($patches, $t);
            } elseif ($m === 0xE1 && strncmp($data, 'http://ns.adobe.com/', 20) === 0) {
                $patches = array_merge($patches, scrub_xmp_patches($data, $dataOff));
            } elseif ($m === 0xED && strncmp($data, "Photoshop 3.0\0", 14) === 0) {
                $patches = array_merge($patches, scrub_iptc_patches(substr($data, 14), $dataOff + 14));
            }
        }
        $pos += 2 + $len;
    }
    return $patches;
}

function scrub_plan_png($fh, $size) {
    if (scrub_read($fh, 0, 8) !== "\x89PNG\r\n\x1a\n") return null;
    $patches = [];
    $pos = 8;
    while ($pos + 12 <= $size) {
        $h = scrub_read($fh, $pos, 8);
        if ($h === null) return null;
        $len = unpack('N', $h)[1]; $type = substr($h, 4, 4);
        if ($pos + 12 + $len > $size) return null;
        if ($type === 'eXIf' || $type === 'iTXt') {
            $data = scrub_read($fh, $pos + 8, $len);
            if ($data === null) return null;
            $local = null;
            if ($type === 'eXIf') $local = scrub_tiff_gps($data, 0);
            elseif (strncmp($data, 'XML:com.adobe.xmp', 17) === 0) $local = scrub_xmp_patches($data, 0);
            else $local = [];
            if ($local === null) return null;
            if ($local) {
                foreach ($local as [$o, $b]) $data = substr_replace($data, $b, $o, strlen($b));
                $patches[] = [$pos + 8, $data];
                $patches[] = [$pos + 8 + $len, pack('N', crc32($type . $data))];   // the chunk's checksum
            }
        }
        if ($type === 'IEND') break;
        $pos += 12 + $len;
    }
    return $patches;
}

function scrub_plan_webp($fh, $size) {
    $h = scrub_read($fh, 0, 12);
    if ($h === null || substr($h, 0, 4) !== 'RIFF' || substr($h, 8, 4) !== 'WEBP') return null;
    $patches = [];
    $pos = 12;
    while ($pos + 8 <= $size) {
        $c = scrub_read($fh, $pos, 8);
        if ($c === null) return null;
        $type = substr($c, 0, 4); $len = unpack('V', $c, 4)[1];
        if ($pos + 8 + $len > $size) return null;
        if ($type === 'EXIF' || $type === 'XMP ') {
            $data = scrub_read($fh, $pos + 8, $len);
            if ($data === null) return null;
            if ($type === 'EXIF') {
                $skip = strncmp($data, "Exif\0\0", 6) === 0 ? 6 : 0;
                $t = scrub_tiff_gps(substr($data, $skip), $pos + 8 + $skip);
                if ($t === null) return null;
                $patches = array_merge($patches, $t);
            } else {
                $patches = array_merge($patches, scrub_xmp_patches($data, $pos + 8));
            }
        }
        $pos += 8 + $len + ($len % 2);
    }
    return $patches;
}

/** The boxes (size, type) laid end to end between $from and $to of an MP4/MOV/HEIC file. */
function scrub_boxes($fh, $from, $to) {
    $out = [];
    $pos = $from;
    while ($pos + 8 <= $to && count($out) < 4096) {
        $h = scrub_read($fh, $pos, 8);
        if ($h === null) return null;
        $len = unpack('N', $h)[1]; $type = substr($h, 4, 4); $head = 8;
        if ($len === 1) {
            $big = scrub_read($fh, $pos + 8, 8);
            if ($big === null) return null;
            $len = unpack('J', $big)[1]; $head = 16;
        } elseif ($len === 0) {
            $len = $to - $pos;
        }
        if ($len < $head || $pos + $len > $to) return null;
        $out[] = ['at' => $pos, 'type' => $type, 'data' => $pos + $head, 'end' => $pos + $len];
        $pos += $len;
    }
    return $out;
}

function scrub_plan_mp4($fh, $size) {
    $patches = [];
    $walk = function ($from, $to, $depth) use (&$walk, &$patches, $fh) {
        $boxes = scrub_boxes($fh, $from, $to);
        if ($boxes === null) return false;
        foreach ($boxes as $b) {
            $type = $b['type'];
            if ($type === "\xA9xyz" || $type === 'loci') {
                if ($b['end'] > $b['data']) $patches[] = [$b['data'], str_repeat("\0", $b['end'] - $b['data'])];
            } elseif (in_array($type, ['moov', 'trak', 'udta'], true) && $depth < 6) {
                if (!$walk($b['data'], $b['end'], $depth + 1)) return false;
            } elseif ($type === 'meta' && $depth < 6) {
                // ISO's meta box has a 4-byte version first; QuickTime's doesn't.
                $probe = scrub_read($fh, $b['data'] + 4, 4);
                $inner = $probe === 'hdlr' ? $b['data'] : $b['data'] + 4;
                $kids = scrub_boxes($fh, $inner, $b['end']);
                if ($kids === null) return false;
                $locationKeys = [];
                foreach ($kids as $k) {
                    if ($k['type'] !== 'keys') continue;
                    $head = scrub_read($fh, $k['data'], 8);
                    if ($head === null) return false;
                    $entries = unpack('N', $head, 4)[1];
                    $at = $k['data'] + 8;
                    for ($i = 1; $i <= $entries && $i < 512; $i++) {
                        $eh = scrub_read($fh, $at, 8);
                        if ($eh === null) return false;
                        $elen = unpack('N', $eh)[1];
                        if ($elen < 8 || $at + $elen > $k['end']) return false;
                        $name = scrub_read($fh, $at + 8, $elen - 8);
                        if ($name !== null && stripos($name, 'location') !== false) $locationKeys[$i] = true;
                        $at += $elen;
                    }
                }
                foreach ($kids as $k) {
                    if ($k['type'] !== 'ilst' || !$locationKeys) continue;
                    $items = scrub_boxes($fh, $k['data'], $k['end']);
                    if ($items === null) return false;
                    foreach ($items as $item) {
                        if (!isset($locationKeys[unpack('N', $item['type'])[1]])) continue;
                        $datas = scrub_boxes($fh, $item['data'], $item['end']);
                        if ($datas === null) return false;
                        foreach ($datas as $d) {
                            if ($d['type'] === 'data' && $d['end'] - $d['data'] > 8) $patches[] = [$d['data'] + 8, str_repeat(' ', $d['end'] - $d['data'] - 8)];
                        }
                    }
                }
            }
        }
        return true;
    };
    return $walk(0, $size, 0) ? $patches : null;
}

function scrub_plan_heif($fh, $size) {
    $top = scrub_boxes($fh, 0, $size);
    if ($top === null) return null;
    $meta = null;
    foreach ($top as $b) if ($b['type'] === 'meta') $meta = $b;
    if ($meta === null) return [];
    $kids = scrub_boxes($fh, $meta['data'] + 4, $meta['end']);
    if ($kids === null) return null;
    $items = [];   // item id => 'exif' | 'xmp'
    foreach ($kids as $k) {
        if ($k['type'] !== 'iinf') continue;
        $ver = scrub_read($fh, $k['data'], 1);
        $skip = $ver === "\0" ? 6 : 8;
        $infes = scrub_boxes($fh, $k['data'] + $skip, $k['end']);
        if ($infes === null) return null;
        foreach ($infes as $inf) {
            if ($inf['type'] !== 'infe') continue;
            $body = scrub_read($fh, $inf['data'], min(256, $inf['end'] - $inf['data']));
            if ($body === null || strlen($body) < 12) continue;
            $v = ord($body[0]);
            if ($v < 2) continue;
            $id = $v === 2 ? unpack('n', $body, 4)[1] : unpack('N', $body, 4)[1];
            $o = $v === 2 ? 8 : 10;
            $itemType = substr($body, $o, 4);
            if ($itemType === 'Exif') $items[$id] = 'exif';
            elseif ($itemType === 'mime' && stripos(substr($body, $o + 4), 'rdf+xml') !== false) $items[$id] = 'xmp';
        }
    }
    if (!$items) return [];
    $patches = [];
    foreach ($kids as $k) {
        if ($k['type'] !== 'iloc') continue;
        $h = scrub_read($fh, $k['data'], 8);
        if ($h === null) return null;
        $ver = ord($h[0]);
        $offSize = ord($h[4]) >> 4; $lenSize = ord($h[4]) & 15; $baseSize = ord($h[5]) >> 4; $idxSize = $ver > 0 ? ord($h[5]) & 15 : 0;
        $count = $ver < 2 ? unpack('n', $h, 6)[1] : unpack('N', scrub_read($fh, $k['data'] + 6, 4))[1];
        $at = $k['data'] + ($ver < 2 ? 8 : 10);
        $num = function ($len) use ($fh, &$at) {
            if ($len === 0) return 0;
            $b = scrub_read($fh, $at, $len); $at += $len;
            if ($b === null) return null;
            $v = 0; for ($i = 0; $i < $len; $i++) $v = ($v << 8) | ord($b[$i]);
            return $v;
        };
        for ($i = 0; $i < $count && $i < 4096; $i++) {
            $id = $num($ver < 2 ? 2 : 4);
            $method = 0;
            if ($ver > 0) { $m = $num(2); if ($m === null) return null; $method = $m & 15; }
            $num(2);
            $base = $num($baseSize); $ext = $num(2);
            if ($id === null || $base === null || $ext === null || $ext > 64) return null;
            $extents = [];
            for ($e = 0; $e < $ext; $e++) {
                if ($idxSize) $num($idxSize);
                $eo = $num($offSize); $el = $num($lenSize);
                if ($eo === null || $el === null) return null;
                $extents[] = [$base + $eo, $el];
            }
            if (!isset($items[$id])) continue;
            if ($method !== 0 || count($extents) !== 1) return null;   // not where it can be safely reached
            [$eo, $el] = $extents[0];
            $data = scrub_read($fh, $eo, $el);
            if ($data === null) return null;
            if ($items[$id] === 'exif') {
                if (strlen($data) < 12) return null;
                $skip = 4 + unpack('N', $data)[1];
                $t = scrub_tiff_gps(substr($data, $skip), $eo + $skip);
                if ($t === null) return null;
                $patches = array_merge($patches, $t);
            } else {
                $patches = array_merge($patches, scrub_xmp_patches($data, $eo));
            }
        }
    }
    return $patches;
}

/** What to overwrite in this file to take its location out: [[fileOffset, bytes], ...] sorted, not overlapping, each
 *  replacing as many bytes as it holds; [] when there is nothing to remove; null when the file can't be read
 *  safely (a damaged or unusual one), in which case it must not be handed out. */
function location_scrub_plan($path) {
    $size = @filesize($path);
    $fh = $size === false ? false : @fopen($path, 'rb');
    if (!$fh) return null;
    try {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === 'jpg' || $ext === 'jpeg') $plan = scrub_plan_jpeg($fh, $size);
        elseif ($ext === 'png') $plan = scrub_plan_png($fh, $size);
        elseif ($ext === 'webp') $plan = scrub_plan_webp($fh, $size);
        elseif ($ext === 'heic' || $ext === 'heif') $plan = scrub_plan_heif($fh, $size);
        elseif ($ext === 'mp4' || $ext === 'mov') $plan = scrub_plan_mp4($fh, $size);
        else $plan = [];   // gif, bmp: nothing in them that says where
    } catch (Throwable $e) {
        $plan = null;
    } finally {
        fclose($fh);
    }
    if ($plan === null) return null;
    usort($plan, function ($a, $b) { return $a[0] <=> $b[0]; });
    $merged = [];
    foreach ($plan as [$o, $b]) {
        $last = count($merged) - 1;
        if ($last >= 0 && $o <= $merged[$last][0] + strlen($merged[$last][1])) {
            $rel = $o - $merged[$last][0];
            $merged[$last][1] = substr($merged[$last][1], 0, $rel) . $b . substr($merged[$last][1], $rel + strlen($b));
        } else {
            $merged[] = [$o, $b];
        }
    }
    return $merged;
}

/** $chunk, which starts at byte $pos of the file, with whatever part of $patches falls inside it laid over it. */
function apply_patches_to_chunk($chunk, $pos, $patches) {
    $end = $pos + strlen($chunk);
    foreach ($patches as [$o, $b]) {
        if ($o >= $end || $o + strlen($b) <= $pos) continue;
        $from = max($o, $pos); $to = min($o + strlen($b), $end);
        $chunk = substr_replace($chunk, substr($b, $from - $o, $to - $from), $from - $pos, $to - $from);
    }
    return $chunk;
}

/** Sends $fh's bytes (from its current start, $size of them) to the visitor with $patches laid over them. */
function stream_with_patches($fh, $size, $patches, $emit) {
    $pos = 0;
    while ($pos < $size) {
        $chunk = fread($fh, min(1048576, $size - $pos));
        if ($chunk === false || $chunk === '') break;
        $emit(apply_patches_to_chunk($chunk, $pos, $patches));
        $pos += strlen($chunk);
    }
}

