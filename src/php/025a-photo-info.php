/* -----------------------------------------------------------------------
 * Photo information (the viewer's "info" panel).
 *
 * Two things live here. The first is the per-folder switches that a folder takes from the folder above it (the location switch and the
 * info switch): a folder's own choice wins, a folder with none takes the nearest folder above it that has one, and a folder with none
 * above it is off. The gallery-wide setting is never what switches a folder on: for locations it is a master "allow" (ENABLE_PHOTO_LOCATION),
 * for info there is none. The second is what the panel says about one photo: asked for when the panel opens (?photoinfo=<file>), read
 * from the file then, and only the fields whose gallery-wide switch (INFO_*) is on are even read. Anything that says where (the position,
 * the time zone the camera wrote) is allowed only when that photo's location is. A camera's serial numbers, the owner's name, the
 * copyright text, the image's unique id and its comments have switches of their own, all off to start with.
 * ----------------------------------------------------------------------- */

/** A folder's setting for $key ('locationEnabled' or 'mediaInfo'), as the folder gets it: ['on' => bool, 'own' => this folder's own choice (true,
 *  false) or null when it takes the one above, 'from' => the folder the choice came from ('' is the gallery's own folder) or null]. */
function folder_flag_resolve($dir, $folderRel, $key, $reset = false) {
    static $cache = [];
    if ($reset) { $cache = []; return null; }
    $folderRel = (string) $folderRel;
    if ($folderRel === '.') $folderRel = '';
    $ck = $key . '|' . $folderRel;
    if (isset($cache[$ck])) return $cache[$ck];
    $segments = ($folderRel === '' || !AUTO_GROUP_BY_FOLDER) ? [] : explode('/', $folderRel);
    $paths = [];
    for ($n = count($segments); $n >= 1; $n--) $paths[] = implode('/', array_slice($segments, 0, $n));   // (the folder itself, then each folder above it...)
    $paths[] = '';                                                                                         // (...and last the gallery's own folder)
    foreach ($paths as $p) {
        $data = items_read($p === '' ? $dir : resolve_real_path($dir, $p));
        $own = $data[$key] ?? null;
        if (is_bool($own)) return $cache[$ck] = ['on' => $own, 'own' => $p === $folderRel ? $own : null, 'from' => $p];
    }
    return $cache[$ck] = ['on' => false, 'own' => null, 'from' => null];
}

function folder_location_on($dir, $folderRel) { return folder_flag_resolve($dir, $folderRel, 'locationEnabled')['on']; }
function folder_info_on($dir, $folderRel) { return folder_flag_resolve($dir, $folderRel, 'mediaInfo')['on']; }

/** The gallery-wide switches, in the order their rows are shown: group => setting. */
function photo_info_groups() {
    return ['camera' => 'INFO_CAMERA', 'lens' => 'INFO_LENS', 'exposure' => 'INFO_EXPOSURE', 'taken' => 'INFO_TAKEN', 'resolution' => 'INFO_RESOLUTION',
            'size' => 'INFO_FILE_SIZE', 'position' => 'INFO_POSITION', 'video' => 'INFO_VIDEO', 'software' => 'INFO_SOFTWARE', 'serials' => 'INFO_SERIALS',
            'owner' => 'INFO_OWNER', 'copyright' => 'INFO_COPYRIGHT', 'imageid' => 'INFO_IMAGE_ID', 'comments' => 'INFO_COMMENTS'];
}

/** The groups the owner has switched on: group => true. Empty when the main switch (INFO_PANEL) is off or none is on (then there is nothing for a panel to show). */
function photo_info_wanted() {
    $w = [];
    if (!INFO_PANEL) return $w;   // (the main switch is off: no panel anywhere, so nothing is read and no folder is offered one)
    foreach (photo_info_groups() as $group => $const) if (defined($const) && constant($const)) $w[$group] = true;
    return $w;
}

/** Text from a file made safe to show: valid UTF-8, no control characters, one line, not long. */
function photo_info_text($v, $max = 400) {
    if (is_array($v)) $v = implode(', ', array_map('strval', $v));
    $v = (string) $v;
    if (!preg_match('//u', $v)) $v = (string) @iconv('UTF-8', 'UTF-8//IGNORE', $v);
    $v = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v)));
    return function_exists('mb_substr') ? mb_substr($v, 0, $max) : substr($v, 0, $max);
}

/** An EXIF rational written as text ("18/10", "1/120", or a plain number) as a number, or null. */
function photo_info_ratio($v) {
    if (is_int($v) || is_float($v)) return (float) $v;
    if (!is_string($v) || !preg_match('#^\s*(-?\d+(?:\.\d+)?)\s*(?:/\s*(-?\d+(?:\.\d+)?))?\s*$#', $v, $m)) return null;
    $d = isset($m[2]) ? (float) $m[2] : 1.0;
    return $d == 0.0 ? null : (float) $m[1] / $d;
}

/** The tags a picture file carries, under plain names: JPEG and TIFF through PHP's own reader, HEIC and HEIF through Imagick (as the location is read
 *  from them). Only the keys of $wanted's groups are filled in. Anything unreadable is simply left out. */
function photo_info_tags($realPath, $wanted) {
    $ext = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
    $raw = [];
    if (in_array($ext, ['jpg', 'jpeg', 'tif', 'tiff'], true) && function_exists('exif_read_data')) {
        $e = @exif_read_data($realPath, null, false);
        if (is_array($e)) {
            $raw = [
                'make' => $e['Make'] ?? null, 'model' => $e['Model'] ?? null, 'software' => $e['Software'] ?? null, 'artist' => $e['Artist'] ?? null,
                'copyright' => $e['Copyright'] ?? null, 'description' => $e['ImageDescription'] ?? null, 'taken' => $e['DateTimeOriginal'] ?? ($e['DateTime'] ?? null),
                'offset' => $e['UndefinedTag:0x9011'] ?? ($e['OffsetTimeOriginal'] ?? null), 'exposure_time' => $e['ExposureTime'] ?? null, 'fnumber' => $e['FNumber'] ?? null,
                'iso' => $e['ISOSpeedRatings'] ?? null, 'focal' => $e['FocalLength'] ?? null, 'focal35' => $e['FocalLengthIn35mmFilm'] ?? null, 'flash' => $e['Flash'] ?? null,
                'lens' => $e['UndefinedTag:0xA434'] ?? ($e['LensModel'] ?? null), 'lens_make' => $e['UndefinedTag:0xA433'] ?? ($e['LensMake'] ?? null),
                'body_serial' => $e['UndefinedTag:0xA431'] ?? ($e['BodySerialNumber'] ?? null), 'lens_serial' => $e['UndefinedTag:0xA435'] ?? ($e['LensSerialNumber'] ?? null),
                'owner_name' => $e['UndefinedTag:0xA430'] ?? ($e['CameraOwnerName'] ?? null), 'image_id' => $e['ImageUniqueID'] ?? null,
                'user_comment' => $e['COMPUTED']['UserComment'] ?? null, 'xp_comment' => $e['XPComment'] ?? null,
            ];
            if (is_string($raw['xp_comment']) && function_exists('mb_convert_encoding')) $raw['xp_comment'] = @mb_convert_encoding($raw['xp_comment'], 'UTF-8', 'UTF-16LE');
        }
    } elseif (in_array($ext, ['heic', 'heif'], true) && class_exists('Imagick') && function_exists('picture_coder_for')) {
        try {
            $coder = picture_coder_for($realPath);
            if ($coder !== null) {
                $im = new Imagick();
                $im->pingImage($coder . ':' . $realPath);
                $p = $im->getImageProperties('exif:*');
                $im->clear();
                $g = function ($name) use ($p) { return $p['exif:' . $name] ?? null; };
                $raw = [
                    'make' => $g('Make'), 'model' => $g('Model'), 'software' => $g('Software'), 'artist' => $g('Artist'), 'copyright' => $g('Copyright'),
                    'description' => $g('ImageDescription'), 'taken' => $g('DateTimeOriginal') ?? $g('DateTime'), 'offset' => $g('OffsetTimeOriginal'),
                    'exposure_time' => $g('ExposureTime'), 'fnumber' => $g('FNumber'), 'iso' => $g('ISOSpeedRatings') ?? $g('PhotographicSensitivity'), 'focal' => $g('FocalLength'),
                    'focal35' => $g('FocalLengthIn35mmFilm'), 'flash' => $g('Flash'), 'lens' => $g('LensModel'), 'lens_make' => $g('LensMake'),
                    'body_serial' => $g('BodySerialNumber'), 'lens_serial' => $g('LensSerialNumber'), 'owner_name' => $g('CameraOwnerName'),
                    'image_id' => $g('ImageUniqueID'), 'user_comment' => $g('UserComment'), 'xp_comment' => null,
                ];
            }
        } catch (Throwable $e) { $raw = []; }
    }
    $need = [   // (only what a switched-on group will use is kept: the rest is dropped here, not later)
        'camera' => ['make', 'model'], 'lens' => ['lens', 'lens_make'], 'exposure' => ['exposure_time', 'fnumber', 'iso', 'focal', 'focal35', 'flash'],
        'taken' => ['taken', 'offset'], 'software' => ['software'], 'serials' => ['body_serial', 'lens_serial'], 'owner' => ['artist', 'owner_name'],
        'copyright' => ['copyright'], 'imageid' => ['image_id'], 'comments' => ['description', 'user_comment', 'xp_comment'],
    ];
    $out = [];
    foreach ($need as $group => $keys) if (isset($wanted[$group])) foreach ($keys as $k) if (isset($raw[$k]) && $raw[$k] !== '') $out[$k] = $raw[$k];
    return $out;
}

/** A picture's size in pixels as [width, height], or null. */
function photo_info_pixels($realPath) {
    $s = @getimagesize($realPath);
    if (is_array($s) && !empty($s[0]) && !empty($s[1])) return [(int) $s[0], (int) $s[1]];
    $ext = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
    if (in_array($ext, ['heic', 'heif'], true) && class_exists('Imagick') && function_exists('picture_coder_for')) {
        try {
            $coder = picture_coder_for($realPath);
            if ($coder !== null) { $im = new Imagick(); $im->pingImage($coder . ':' . $realPath); $w = $im->getImageWidth(); $h = $im->getImageHeight(); $im->clear(); if ($w > 0 && $h > 0) return [$w, $h]; }
        } catch (Throwable $e) {}
    }
    return null;
}

/** What an MP4 or MOV file says about itself (read from its boxes, no tool needed): length, size in pixels, frame rate, the video and audio kinds,
 *  when it was made and, when a phone wrote them, the make, model, software and local time. Anything else (WebM...) gives an empty list. */
function photo_info_video_tags($realPath) {
    $size = @filesize($realPath);
    $fh = $size === false ? false : @fopen($realPath, 'rb');
    if (!$fh) return [];
    $t = [];
    try {
        $u32 = function ($s, $o) { return strlen($s) >= $o + 4 ? unpack('N', $s, $o)[1] : null; };
        $time = function ($fhh, $box) use ($u32) {   // mvhd or mdhd: [timescale, duration, creation (seconds since 1904)]
            $h = scrub_read($fhh, $box['data'], 36);
            if ($h === null) return null;
            if (ord($h[0]) === 1) { if (strlen($h) < 32) return null; return [$u32($h, 20), unpack('J', $h, 24)[1], unpack('J', $h, 4)[1]]; }
            return [$u32($h, 12), $u32($h, 16), $u32($h, 4)];
        };
        $moovs = array_filter(scrub_boxes($fh, 0, $size) ?? [], function ($b) { return $b['type'] === 'moov'; });
        foreach ($moovs as $moov) {
            foreach (scrub_boxes($fh, $moov['data'], $moov['end']) ?? [] as $b) {
                if ($b['type'] === 'mvhd') {
                    $m = $time($fh, $b);
                    if ($m && $m[0]) { $t['duration'] = $m[1] / $m[0]; if ($m[2] > 2082844800) $t['created_utc'] = $m[2] - 2082844800; }
                } elseif ($b['type'] === 'trak') {
                    $kind = null; $codec = null; $w = $h = null; $audio = null; $frames = null; $ts = null; $dur = null;
                    foreach (scrub_boxes($fh, $b['data'], $b['end']) ?? [] as $tb) {
                        if ($tb['type'] === 'tkhd') {
                            $d = scrub_read($fh, $tb['data'], 96);
                            if ($d !== null) { $v1 = ord($d[0]) === 1; $o = $v1 ? 88 : 76; if (strlen($d) >= $o + 8) { $w = unpack('n', $d, $o)[1]; $h = unpack('n', $d, $o + 4)[1]; } }
                        } elseif ($tb['type'] === 'mdia') {
                            foreach (scrub_boxes($fh, $tb['data'], $tb['end']) ?? [] as $mb) {
                                if ($mb['type'] === 'hdlr') { $hd = scrub_read($fh, $mb['data'], 12); if ($hd !== null && strlen($hd) >= 12) $kind = substr($hd, 8, 4); }
                                elseif ($mb['type'] === 'mdhd') { $mm = $time($fh, $mb); if ($mm) { $ts = $mm[0]; $dur = $mm[1]; } }
                                elseif ($mb['type'] === 'minf') {
                                    foreach (scrub_boxes($fh, $mb['data'], $mb['end']) ?? [] as $nb) {
                                        if ($nb['type'] !== 'stbl') continue;
                                        foreach (scrub_boxes($fh, $nb['data'], $nb['end']) ?? [] as $sb) {
                                            if ($sb['type'] === 'stsd') {
                                                $e = scrub_read($fh, $sb['data'] + 8, 40);
                                                if ($e !== null && strlen($e) >= 12) {
                                                    $codec = substr($e, 4, 4);
                                                    if (strlen($e) >= 36) $audio = ['channels' => unpack('n', $e, 24)[1], 'rate' => unpack('n', $e, 32)[1]];
                                                }
                                            } elseif ($sb['type'] === 'stts') {
                                                $sd = scrub_read($fh, $sb['data'], min(8 + 8 * 512, $sb['end'] - $sb['data']));
                                                if ($sd !== null && strlen($sd) >= 8) { $n = min($u32($sd, 4), intdiv(strlen($sd) - 8, 8)); $frames = 0; for ($i = 0; $i < $n; $i++) $frames += $u32($sd, 8 + 8 * $i); }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                    if ($kind === 'vide' && !isset($t['video'])) {
                        $t['video'] = $codec; if ($w && $h) { $t['width'] = $w; $t['height'] = $h; }
                        if ($frames && $ts && $dur) $t['fps'] = $frames / ($dur / $ts);
                    } elseif ($kind === 'soun' && !isset($t['audio'])) {
                        $t['audio'] = $codec; if ($audio) { $t['audio_channels'] = $audio['channels']; $t['audio_rate'] = $audio['rate']; }
                    }
                } elseif ($b['type'] === 'meta') {   // a phone's own notes (make, model, software, local time), under "keys" and "ilst"
                    $probe = scrub_read($fh, $b['data'] + 4, 4);
                    $kids = scrub_boxes($fh, $probe === 'hdlr' ? $b['data'] : $b['data'] + 4, $b['end']);
                    $names = [];
                    foreach ($kids ?? [] as $k) {
                        if ($k['type'] !== 'keys') continue;
                        $head = scrub_read($fh, $k['data'], 8);
                        if ($head === null) continue;
                        $entries = $u32($head, 4); $at = $k['data'] + 8;
                        for ($i = 1; $i <= $entries && $i < 512; $i++) {
                            $eh = scrub_read($fh, $at, 8);
                            if ($eh === null) break;
                            $elen = $u32($eh, 0);
                            if ($elen < 8 || $at + $elen > $k['end']) break;
                            $names[$i] = (string) scrub_read($fh, $at + 8, $elen - 8);
                            $at += $elen;
                        }
                    }
                    foreach ($kids ?? [] as $k) {
                        if ($k['type'] !== 'ilst') continue;
                        foreach (scrub_boxes($fh, $k['data'], $k['end']) ?? [] as $item) {
                            $name = $names[unpack('N', $item['type'])[1]] ?? '';
                            $slot = ['com.apple.quicktime.make' => 'make', 'com.apple.quicktime.model' => 'model', 'com.apple.quicktime.software' => 'software', 'com.apple.quicktime.creationdate' => 'local_time'][$name] ?? null;
                            if ($slot === null) continue;
                            foreach (scrub_boxes($fh, $item['data'], $item['end']) ?? [] as $d) {
                                if ($d['type'] === 'data' && $d['end'] - $d['data'] > 8) { $v = scrub_read($fh, $d['data'] + 8, min(120, $d['end'] - $d['data'] - 8)); if ($v !== null) $t[$slot] = $v; }
                            }
                        }
                    }
                }
            }
            break;
        }
    } catch (Throwable $e) {
        $t = [];
    } finally {
        fclose($fh);
    }
    return $t;
}

/** The video kind from its four-letter code in the file, in words. */
function photo_info_codec_name($code) {
    $names = ['avc1' => 'H.264', 'avc3' => 'H.264', 'hvc1' => 'HEVC (H.265)', 'hev1' => 'HEVC (H.265)', 'av01' => 'AV1', 'vp09' => 'VP9', 'mp4v' => 'MPEG-4',
              'apch' => 'ProRes', 'apcn' => 'ProRes', 'apcs' => 'ProRes', 'ap4h' => 'ProRes', 'mp4a' => 'AAC', 'alac' => 'ALAC', 'ac-3' => 'AC-3', 'ec-3' => 'E-AC-3', 'Opus' => 'Opus', 'sowt' => 'PCM', 'lpcm' => 'PCM'];
    $code = (string) $code;
    return $names[$code] ?? photo_info_text($code, 12);
}

/** A length in seconds as 0:07, 1:05 or 1:02:03. */
function photo_info_length($seconds) {
    $s = (int) round($seconds);
    return $s >= 3600 ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60) : sprintf('%d:%02d', intdiv($s, 60), $s % 60);
}

/** The rows the panel shows for one photo: [['label' => ..., 'value' => ..., 'href' => (a link, or absent)], ...], only for the groups switched on, with
 *  anything that says where left out unless this photo's location is allowed. $entry is the photo's own item data, as the gallery's pages see it. */
function photo_info_rows($dir, $file, $entry) {
    $wanted = photo_info_wanted();
    if (!$wanted) return [];
    $real = resolve_real_path($dir, $file);
    $isVideo = is_video_file($file);
    // May anything that says where be shown for this photo? The same switches as its pin: the gallery, the folder, the photo, who is looking. A photo
    // whose location the owner cleared is one whose place they do not want said.
    $probe = $entry; $probe['location'] = ['lat' => 1.0, 'lng' => 1.0];
    $whereOk = empty($entry['locationCleared']) && photo_location_visible($probe);
    $rows = [];
    $add = function ($label, $value, $href = null) use (&$rows) {
        $value = photo_info_text($value);
        if ($value === '') return;
        $row = ['label' => $label, 'value' => $value];
        if ($href !== null) $row['href'] = $href;
        $rows[] = $row;
    };
    $tags = $isVideo ? photo_info_video_tags($real) : photo_info_tags($real, $wanted);
    $pixels = ($isVideo || !isset($wanted['resolution'])) ? null : photo_info_pixels($real);
    $make = isset($wanted['camera']) ? photo_info_text($tags['make'] ?? '') : '';
    $model = isset($wanted['camera']) ? photo_info_text($tags['model'] ?? '') : '';
    foreach (photo_info_groups() as $group => $const) {
        if (!isset($wanted[$group])) continue;
        switch ($group) {
            case 'camera':
                $add('Camera', ($model !== '' && ($make === '' || stripos($model, $make) === 0)) ? $model : trim($make . ' ' . $model));
                break;
            case 'lens':
                $lens = photo_info_text($tags['lens'] ?? '');
                $lmake = photo_info_text($tags['lens_make'] ?? '');
                $add('Lens', ($lens !== '' && $lmake !== '' && stripos($lens, $lmake) !== 0) ? $lmake . ' ' . $lens : $lens);
                break;
            case 'exposure':
                $f = photo_info_ratio($tags['focal'] ?? null); $f35 = photo_info_ratio($tags['focal35'] ?? null);
                if ($f !== null && $f > 0) $add('Focal length', rtrim(rtrim(sprintf('%.1f', $f), '0'), '.') . ' mm' . ($f35 !== null && $f35 > 0 ? ' (' . (int) round($f35) . ' mm equivalent)' : ''));
                $n = photo_info_ratio($tags['fnumber'] ?? null);
                if ($n !== null && $n > 0) $add('Aperture', 'f/' . rtrim(rtrim(sprintf('%.1f', $n), '0'), '.'));
                $x = photo_info_ratio($tags['exposure_time'] ?? null);
                if ($x !== null && $x > 0) $add('Shutter speed', $x >= 1 ? rtrim(rtrim(sprintf('%.1f', $x), '0'), '.') . ' s' : '1/' . (int) round(1 / $x) . ' s');
                $iso = $tags['iso'] ?? null;
                if (is_array($iso)) $iso = $iso[0] ?? null;
                if ($iso !== null && is_numeric($iso) && (int) $iso > 0) $add('ISO', (string) (int) $iso);
                if (isset($tags['flash']) && is_numeric($tags['flash'])) $add('Flash', ((int) $tags['flash'] & 1) ? 'Fired' : 'Did not fire');
                break;
            case 'taken':
                if ($isVideo) {
                    $lt = (string) ($tags['local_time'] ?? '');
                    if (preg_match('/^(\d{4})-(\d\d)-(\d\d)T(\d\d):(\d\d):(\d\d)([+-]\d\d:?\d\d)?/', $lt, $m)) {
                        $when = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', "$m[1]-$m[2]-$m[3] $m[4]:$m[5]:$m[6]", new DateTimeZone('UTC'));
                        $zone = isset($m[7]) ? substr($m[7], 0, 3) . ':' . substr($m[7], -2) : '';
                        if ($when) $add('Taken', $when->format('M j, Y, H:i') . ($whereOk && $zone !== '' ? ' (UTC' . $zone . ')' : ''));
                    } elseif (isset($tags['created_utc'])) {
                        $add('Taken', gmdate('M j, Y, H:i', (int) $tags['created_utc']) . ' UTC');
                    }
                } else {
                    $when = isset($tags['taken']) ? DateTimeImmutable::createFromFormat('Y:m:d H:i:s', trim((string) $tags['taken']), new DateTimeZone('UTC')) : false;
                    if ($when) {
                        $zone = (string) ($tags['offset'] ?? '');
                        $add('Taken', $when->format('M j, Y, H:i') . ($whereOk && preg_match('/^[+-]\d\d:\d\d$/', $zone) ? ' (UTC' . $zone . ')' : ''));
                    }
                }
                break;
            case 'resolution':
                if ($isVideo) { if (!empty($tags['width']) && !empty($tags['height'])) $add('Resolution', $tags['width'] . ' × ' . $tags['height']); }
                elseif ($pixels) $add('Resolution', sprintf('%.1f MP (%d × %d)', $pixels[0] * $pixels[1] / 1000000, $pixels[0], $pixels[1]));
                break;
            case 'size':
                $bytes = @filesize($real);
                if ($bytes !== false) $add('File size', human_bytes($bytes));
                break;
            case 'position':
                $loc = $entry['location'] ?? null;
                if ($whereOk && is_array($loc) && isset($loc['lat'], $loc['lng']) && is_valid_coordinate($loc['lat'], $loc['lng'])) {
                    $lat = (float) $loc['lat']; $lng = (float) $loc['lng'];
                    $add('Position', sprintf('%.4f° %s, %.4f° %s', abs($lat), $lat >= 0 ? 'N' : 'S', abs($lng), $lng >= 0 ? 'E' : 'W'),
                        'https://www.openstreetmap.org/?mlat=' . rtrim(rtrim(sprintf('%.6F', $lat), '0'), '.') . '&mlon=' . rtrim(rtrim(sprintf('%.6F', $lng), '0'), '.') . '#map=16/' . rtrim(rtrim(sprintf('%.6F', $lat), '0'), '.') . '/' . rtrim(rtrim(sprintf('%.6F', $lng), '0'), '.'));
                }
                break;
            case 'video':
                if (!$isVideo) break;
                if (isset($tags['duration']) && $tags['duration'] > 0) $add('Length', photo_info_length($tags['duration']));
                if (isset($tags['fps']) && $tags['fps'] > 0) $add('Frame rate', rtrim(rtrim(sprintf('%.2f', $tags['fps']), '0'), '.') . ' frames/s');
                if (!empty($tags['video'])) $add('Video', photo_info_codec_name($tags['video']));
                if (!empty($tags['audio'])) {
                    $ch = (int) ($tags['audio_channels'] ?? 0);
                    $add('Audio', photo_info_codec_name($tags['audio']) . ($ch === 1 ? ', mono' : ($ch === 2 ? ', stereo' : ($ch > 2 ? ', ' . $ch . ' channels' : ''))) . (!empty($tags['audio_rate']) ? ', ' . rtrim(rtrim(sprintf('%.1f', $tags['audio_rate'] / 1000), '0'), '.') . ' kHz' : ''));
                }
                $bytes = @filesize($real);
                if ($bytes !== false && !empty($tags['duration']) && $tags['duration'] > 0) {
                    $mbit = $bytes * 8 / $tags['duration'] / 1000000;
                    $add('Bitrate', $mbit >= 1 ? rtrim(rtrim(sprintf('%.1f', $mbit), '0'), '.') . ' Mbit/s' : max(1, (int) round($mbit * 1000)) . ' kbit/s');
                }
                break;
            case 'software':
                $add('Software', $tags['software'] ?? '');
                break;
            case 'serials':
                $add('Camera serial number', $tags['body_serial'] ?? '');
                $add('Lens serial number', $tags['lens_serial'] ?? '');
                break;
            case 'owner':
                $add('Owner', ($tags['artist'] ?? '') !== '' ? $tags['artist'] : ($tags['owner_name'] ?? ''));
                break;
            case 'copyright':
                $add('Copyright', $tags['copyright'] ?? '');
                break;
            case 'imageid':
                $add('Image ID', $tags['image_id'] ?? '');
                break;
            case 'comments':
                $add('Description', $tags['description'] ?? '');
                $add('Comment', $tags['user_comment'] ?? '');
                $add('Windows comment', $tags['xp_comment'] ?? '');
                break;
        }
    }
    return $rows;
}

/** ?photoinfo=<file>: the panel's rows for one photo, as JSON. Answered exactly as ?img= answers for who may see the file (anything else is a 404),
 *  and only for a folder whose info is on; a Not listed photo's own address has no panel. */
function serve_photo_info($dir, $mediaExtensions, $self) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    $claimed = $_GET['photoinfo'] ?? null;
    $list = (is_string($claimed) && valid_media_path($dir, $claimed, $mediaExtensions, $self)) ? [$claimed] : [];
    $items = $list ? items_get_all_for_files($dir, $list) : [];
    $folder = $list ? (dirname($claimed) === '.' ? '' : dirname($claimed)) : '';
    if (!$list || isset($_GET['k']) || !viewer_may_see_item($dir, $claimed, $items) || !photo_info_wanted() || !folder_info_on($dir, $folder)) {
        http_response_code(404);
        echo '{"ok":false}';
        return;
    }
    echo json_encode(['ok' => true, 'rows' => photo_info_rows($dir, $claimed, $items[$claimed] ?? [])], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}
