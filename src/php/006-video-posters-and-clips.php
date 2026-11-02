/* -----------------------------------------------------------------------
 * A picture for a video. ffmpeg, when this server has it, takes one frame
 * (about a third of a second in, or the first frame of a very short clip),
 * once per video; that frame is kept in the thumbnail cache and everything
 * else (the grid thumbnail, the folder cover, the share preview) is cut from
 * it exactly as for a photo. ffmpeg is started with a fixed list of
 * arguments (never through a shell), with a time limit, one at a time, and
 * what it writes carries no metadata. A video it can't read, or no ffmpeg,
 * gives the generic placeholder as before.
 * ----------------------------------------------------------------------- */

/** Where ffmpeg is on this server, or '' if it can't be used: the setting is off, the functions to start a program are
 *  not available, or no ffmpeg answers. Looked up once a day and remembered outside the web folder. */
function ffmpeg_binary() {
    static $found = null;
    if ($found !== null) return $found;
    $found = '';
    if (!function_exists('proc_open')) return $found;
    $remember = sys_get_temp_dir() . '/gallery-ffmpeg-' . substr(hash('sha256', __DIR__ . '|' . FFMPEG_PATH), 0, 10) . '.txt';
    if (is_file($remember) && time() - (int) @filemtime($remember) < 86400) {
        return $found = trim((string) @file_get_contents($remember));
    }
    $candidates = FFMPEG_PATH !== '' ? [FFMPEG_PATH] : ['ffmpeg', '/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/opt/homebrew/bin/ffmpeg', '/snap/bin/ffmpeg'];
    foreach ($candidates as $c) {
        if ($c !== 'ffmpeg' && !is_executable($c)) continue;
        if (run_quietly([$c, '-version'], 5) === 0) { $found = $c; break; }
    }
    @file_put_contents($remember, $found, LOCK_EX);
    return $found;
}

/** Runs a program (the arguments as a list, no shell), with a time limit; its exit code, or null if it couldn't be run
 *  or was stopped for taking too long. Whatever it prints is thrown away. */
function run_quietly($args, $seconds) {
    $proc = @proc_open($args, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (!is_resource($proc)) return null;
    $deadline = microtime(true) + $seconds;
    while (true) {
        $st = proc_get_status($proc);
        if (!$st['running']) { $code = $st['exitcode']; proc_close($proc); return $code; }
        if (microtime(true) > $deadline) { proc_terminate($proc, 9); proc_close($proc); return null; }
        usleep(20000);
    }
}

/** Takes the one ffmpeg place (only one runs at a time), waiting up to $seconds for whoever has it to finish, so a request
 *  that arrives during another's work is served in its turn rather than refused. Null if it never came free. */
function ffmpeg_take_turn($seconds) {
    $lock = @fopen(sys_get_temp_dir() . '/gallery-ffmpeg-' . substr(hash('sha256', __DIR__), 0, 10) . '.lock', 'c');
    if (!$lock) return null;
    $deadline = microtime(true) + $seconds;
    while (!flock($lock, LOCK_EX | LOCK_NB)) {
        if (microtime(true) > $deadline) { fclose($lock); return null; }
        usleep(100000);
    }
    return $lock;
}

/** The path of the frame taken from this video (a JPEG up to 1600 pixels), making it first if need be; null if it can't
 *  be had now (no ffmpeg, a video it can't read, someone else's frame is being taken, a cache that can't be written). */
function video_poster_path($srcPath, $cacheDir, $filename) {
    if (!ENABLE_VIDEO_THUMBNAILS || !ENABLE_THUMBNAIL_CACHE) return null;
    $poster = image_cache_path($cacheDir, $filename, 'poster', $srcPath);
    if ($poster === null) return null;
    if (is_file($poster) && filesize($poster) > 0) return $poster;
    $failed = $poster . '.failed';
    if (is_file($failed) && time() - (int) @filemtime($failed) < 21600) return null;   // it didn't work lately; not again for a few hours
    $bin = ffmpeg_binary();
    if ($bin === '') return null;
    $lock = ffmpeg_take_turn(20);   // one at a time, in turn
    if (!$lock) return null;
    if (is_file($poster) && filesize($poster) > 0) { flock($lock, LOCK_UN); fclose($lock); return $poster; }   // made by whoever had the turn before
    try {
        $duration = video_duration_seconds($srcPath);
        $tmp = $poster . '.tmp' . bin2hex(random_bytes(3));
        foreach ([$duration !== null ? max(0, min(1.0, $duration / 3)) : 0, 0] as $at) {
            $code = run_quietly([$bin, '-v', 'error', '-nostdin', '-y', '-ss', sprintf('%.2f', $at), '-i', $srcPath, '-map_metadata', '-1', '-frames:v', '1',
                '-vf', "scale='min(1600,iw)':'min(1600,ih)':force_original_aspect_ratio=decrease", '-q:v', '3', '-f', 'image2', $tmp], 25);
            if ($code === 0 && is_file($tmp) && filesize($tmp) > 0) { @chmod($tmp, 0644); if (@rename($tmp, $poster)) return $poster; }
            @unlink($tmp);
        }
        @touch($failed);
        return null;
    } finally {
        flock($lock, LOCK_UN); fclose($lock);
    }
}

/** True when a picture couldn't be taken from this video lately (ffmpeg is there but couldn't read it). */
function video_poster_failed($file) {
    $srcPath = resolve_real_path($GLOBALS['dir'], $file);
    $poster = image_cache_path(containing_folder_real_path($GLOBALS['dir'], $file) . '/.gallery-cache', $file, 'poster', $srcPath);
    return $poster !== null && !is_file($poster) && is_file($poster . '.failed');
}

/** The short clip for a video (a small silent H.264 .mp4), making it first if need be; null if it can't be had now. Same
 *  care as video_poster_path(): fixed arguments, no shell, a time limit, one ffmpeg at a time, and not retried for hours
 *  after a failure. */
function video_preview_path($srcPath, $cacheDir, $filename) {
    if (!ENABLE_VIDEO_PREVIEWS || !ENABLE_THUMBNAIL_CACHE) return null;
    $base = image_cache_path($cacheDir, $filename, 'preview2', $srcPath);
    if ($base === null) return null;
    $clip = substr($base, 0, -4) . '.mp4';
    if (is_file($clip) && filesize($clip) > 0) return $clip;
    $failed = $clip . '.failed';
    if (is_file($failed) && time() - (int) @filemtime($failed) < 21600) return null;
    $bin = ffmpeg_binary();
    if ($bin === '') return null;
 
    $lock = ffmpeg_take_turn(15);
    if (!$lock) return null;
    if (is_file($clip) && filesize($clip) > 0) { flock($lock, LOCK_UN); fclose($lock); return $clip; }
    try {
        $duration = video_duration_seconds($srcPath);
        $start = $duration !== null && $duration > 3.5 ? min($duration / 3, $duration - 3) : 0;
        $tmp = $clip . '.tmp' . bin2hex(random_bytes(3));
        $code = run_quietly([$bin, '-v', 'error', '-nostdin', '-y', '-ss', sprintf('%.2f', $start), '-t', '3', '-i', $srcPath, '-an', '-map_metadata', '-1',
            '-vf', 'scale=w=min(640\\,iw):h=-2,fps=15', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '27', '-pix_fmt', 'yuv420p', '-movflags', '+faststart', '-f', 'mp4', $tmp], 45);
        if ($code === 0 && is_file($tmp) && filesize($tmp) > 0) { @chmod($tmp, 0644); if (@rename($tmp, $clip)) return $clip; }
        @unlink($tmp);
        @touch($failed);
        return null;
    } finally {
        flock($lock, LOCK_UN); fclose($lock);
    }
}

/** Where this video's clip is (or would be) kept, or null if the cache can't be used. */
function video_preview_file($file) {
    $base = image_cache_path(containing_folder_real_path($GLOBALS['dir'], $file) . '/.gallery-cache', $file, 'preview2', resolve_real_path($GLOBALS['dir'], $file));
    return $base === null ? null : substr($base, 0, -4) . '.mp4';
}

/** The grid of frames a video's scrub sprite holds: ten columns and one row for every five seconds (1 to 6 rows), so a frame
 *  about every half second for a video of half a minute or more, spread over its whole length. [columns, rows]. */
function video_strip_layout($duration) {
    return [10, (int) max(1, min(6, ceil($duration / 5)))];
}

/** The sprite of frames for a video (one JPEG, small square frames in a grid, spread over the whole video), which a finger
 *  dragged across a video's tile scrubs through. Made once with ffmpeg, like the clip; null if it can't be had. */
function video_strip_path($srcPath, $cacheDir, $filename) {
    if (!ENABLE_VIDEO_PREVIEWS || !ENABLE_THUMBNAIL_CACHE) return null;
    $strip = image_cache_path($cacheDir, $filename, 'strip2', $srcPath);
    if ($strip === null) return null;
    if (is_file($strip) && filesize($strip) > 0) return $strip;
    $failed = $strip . '.failed';
    if (is_file($failed) && time() - (int) @filemtime($failed) < 21600) return null;
    $duration = video_duration_seconds($srcPath);
    if ($duration === null || $duration < 1) return null;
    $bin = ffmpeg_binary();
    if ($bin === '') return null;
    $lock = ffmpeg_take_turn(30);
    if (!$lock) return null;
    if (is_file($strip) && filesize($strip) > 0) { flock($lock, LOCK_UN); fclose($lock); return $strip; }
    try {
        $tmp = $strip . '.tmp' . bin2hex(random_bytes(3));
        [$cols, $rows] = video_strip_layout($duration);
        $args = [$bin, '-v', 'error', '-nostdin', '-y'];
        if ($duration > 20) array_push($args, '-skip_frame', 'nokey');   // a long video: only its key frames are read, which is much quicker
        array_push($args, '-i', $srcPath, '-an', '-map_metadata', '-1', '-vf',
            sprintf('fps=%.5F,crop=min(iw\\,ih):min(iw\\,ih),scale=320:320,tile=%dx%d', $cols * $rows / $duration * 1.05, $cols, $rows),
            '-frames:v', '1', '-q:v', '4', '-f', 'image2', $tmp);
        $code = run_quietly($args, 90);
        if ($code === 0 && is_file($tmp) && filesize($tmp) > 0) { @chmod($tmp, 0644); if (@rename($tmp, $strip)) return $strip; }
        @unlink($tmp);
        @touch($failed);
        return null;
    } finally {
        flock($lock, LOCK_UN); fclose($lock);
    }
}

/** ?strip=<video>: that sprite, for anyone who may see the video (same gates as the video itself); &warm only makes it. */
function serve_video_strip($dir, $filename, $validFiles, $itemsData = []) {
    if (!is_string($filename) || !in_array($filename, $validFiles, true) || !is_video_file($filename)
        || !viewer_may_see_item($dir, $filename, $itemsData)) {
        http_response_code(404);
        return;
    }
    $strip = video_strip_path(resolve_real_path($dir, $filename), containing_folder_real_path($dir, $filename) . '/.gallery-cache', $filename);
    if (isset($_GET['warm'])) {
        http_response_code($strip === null ? 404 : 204);
        header('Cache-Control: no-store');
        return;
    }
    if ($strip === null) {
        http_response_code(404);
        return;
    }
    header('Content-Type: image/jpeg');
    header('Cache-Control: ' . file_cache_control($dir, $filename, $itemsData));
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . filesize($strip));
    readfile($strip);
}

/** True when a hover over a video tile can play a made clip. */
function video_previews_expected() {
    return ENABLE_VIDEO_PREVIEWS && ENABLE_THUMBNAIL_CACHE && ffmpeg_binary() !== '';
}

/** ?preview=<video>: the short clip, for anyone who may see the video (the same gates as the video itself), with Range
 *  support (Safari plays nothing without it). Nothing is sent that wasn't made by ffmpeg from the video: no metadata. */
function serve_video_preview($dir, $filename, $validFiles, $itemsData = []) {
    if (!is_string($filename) || !in_array($filename, $validFiles, true) || !is_video_file($filename)
        || !viewer_may_see_item($dir, $filename, $itemsData)) {
        http_response_code(404);
        return;
    }
    $srcPath = resolve_real_path($dir, $filename);
    $clip = video_preview_path($srcPath, containing_folder_real_path($dir, $filename) . '/.gallery-cache', $filename);
    if (isset($_GET['warm'])) {
        // Only make it (the page asks quietly for the clips of the tiles in view, one at a time): no body to download.
        http_response_code($clip === null ? 404 : 204);
        header('Cache-Control: no-store');
        return;
    }
    $size = $clip === null ? false : @filesize($clip);
    if ($size === false || $size < 1) {
        http_response_code(404);
        return;
    }
    $start = 0; $end = $size - 1;
    if (preg_match('/^bytes=(\d*)-(\d*)$/', trim($_SERVER['HTTP_RANGE'] ?? ''), $m) && ($m[1] !== '' || $m[2] !== '')) {
        if ($m[1] === '') { $start = max(0, $size - (int) $m[2]); }
        else { $start = (int) $m[1]; $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1); }
        if ($start >= $size || $start > $end) { http_response_code(416); header('Content-Range: bytes */' . $size); return; }
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }
    header('Content-Type: video/mp4');
    header('Accept-Ranges: bytes');
    header('Cache-Control: ' . file_cache_control($dir, $filename, $itemsData));
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . ($end - $start + 1));
    $fh = @fopen($clip, 'rb');
    if (!$fh) return;
    fseek($fh, $start);
    for ($left = $end - $start + 1; $left > 0 && !feof($fh);) {
        $chunk = fread($fh, min(65536, $left));
        if ($chunk === false || $chunk === '') break;
        echo $chunk;
        $left -= strlen($chunk);
    }
    fclose($fh);
}

/** True when this server can make a real picture of a video, so the page can leave the browser's own first frame out. */
function video_pictures_expected() {
    return ENABLE_VIDEO_THUMBNAILS && ENABLE_THUMBNAIL_CACHE && ffmpeg_binary() !== '';
}

/**
 * Renders a generic "this is a video" placeholder graphic — a dark tile
 * with a plain play triangle — wherever a video's "thumbnail" or share-
 * preview image is requested. No frame extraction: that would mean
 * adding a video-processing dependency (ffmpeg or similar) this project
 * has never carried and isn't worth adding just for this. Same role for
 * every video regardless of filename, so nothing to cache — generating
 * one is cheap enough to just do on every request.
 */
function render_video_placeholder_image($maxDim) {
    $w = max(1, (int) $maxDim);
    $h = max(1, (int) round($w * 0.75));
    $img = imagecreatetruecolor($w, $h);
    imagefilledrectangle($img, 0, 0, $w, $h, imagecolorallocate($img, 28, 28, 30));
    $triangleColor = imagecolorallocate($img, 200, 200, 205);
    $size = min($w, $h) * 0.22;
    $cx = $w / 2;
    $cy = $h / 2;
    $points = [
        $cx - $size * 0.55, $cy - $size,
        $cx - $size * 0.55, $cy + $size,
        $cx + $size * 0.85, $cy,
    ];
    imagefilledpolygon($img, $points, $triangleColor);
    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=31536000, immutable');
    imagejpeg($img, null, 82);
    unset($img);
}

/**
 * The ?map page's share-preview image: a dark tile with a plain map
 * pin. Not a rendered snapshot of the actual map — that needs a
 * third-party static-maps API, the same dependency objection that ruled
 * out geocoding — so, same honesty standard as the video placeholder, a
 * generic icon that says "this link is a map" and nothing more. 1200x630
 * is the usual large-link-preview size. Identical for every gallery, so
 * nothing to cache or key; cheap enough to draw per request.
 */
function render_map_icon_image() {
    $w = 1200;
    $h = 630;
    $img = imagecreatetruecolor($w, $h);
    $bg = imagecolorallocate($img, 28, 28, 30);
    imagefilledrectangle($img, 0, 0, $w, $h, $bg);
    $pin = imagecolorallocate($img, 224, 138, 60);
    $r = (int) round($h * 0.19);
    $cx = (int) ($w / 2);
    $cy = (int) round($h * 0.42);
    imagefilledellipse($img, $cx, $cy, $r * 2, $r * 2, $pin);
    // Tangent-ish taper from the circle's lower flanks down to the point.
    $tipY = (int) round($h * 0.82);
    $dx = (int) round($r * 0.86);
    $dy = (int) round($r * 0.5);
    $points = [$cx - $dx, $cy + $dy, $cx + $dx, $cy + $dy, $cx, $tipY];
    imagefilledpolygon($img, $points, $pin);
    imagefilledellipse($img, $cx, $cy, (int) round($r * 0.8), (int) round($r * 0.8), $bg);
    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=31536000, immutable');
    imagejpeg($img, null, 85);
    unset($img);
}

/** The home-screen icon: a dark square with a small mountain-and-sun picture, as a PNG of $px pixels. */
function render_app_icon_image($px) {
    // The same line drawing as the gallery icon in the icon strip (a rounded frame, a sun, a hill line; ui_icon_paths()'s 'gallery'),
    // drawn here in PHP so no image file is needed. Drawn four times larger and shrunk, which smooths the edges.
    $k = 4; $big = $px * $k;
    $img = imagecreatetruecolor($big, $big);
    $bg = imagecolorallocate($img, 15, 15, 16);
    $ink = imagecolorallocate($img, 234, 234, 234);
    imagefilledrectangle($img, 0, 0, $big, $big, $bg);
    $u = $big * 0.58 / 18;                       // one unit of the 24-unit drawing; the frame (18 wide) fills 58% of the icon
    $X = function ($x) use ($big, $u) { return $big / 2 + ($x - 12) * $u; };
    $Y = function ($y) use ($big, $u) { return $big / 2 + ($y - 12) * $u; };
    $w = 1.8 * $u;                               // the icon's stroke width
    $rrect = function ($x1, $y1, $x2, $y2, $r, $col) use ($img) {
        imagefilledrectangle($img, (int) round($x1 + $r), (int) round($y1), (int) round($x2 - $r), (int) round($y2), $col);
        imagefilledrectangle($img, (int) round($x1), (int) round($y1 + $r), (int) round($x2), (int) round($y2 - $r), $col);
        foreach ([[$x1 + $r, $y1 + $r], [$x2 - $r, $y1 + $r], [$x1 + $r, $y2 - $r], [$x2 - $r, $y2 - $r]] as [$cx, $cy]) {
            imagefilledellipse($img, (int) round($cx), (int) round($cy), (int) round($r * 2), (int) round($r * 2), $col);
        }
    };
    // the frame: x 3..21, y 4..20, corner radius 2, a stroke centred on that outline
    $rrect($X(3) - $w / 2, $Y(4) - $w / 2, $X(21) + $w / 2, $Y(20) + $w / 2, 2 * $u + $w / 2, $ink);
    $rrect($X(3) + $w / 2, $Y(4) + $w / 2, $X(21) - $w / 2, $Y(20) - $w / 2, max(1, 2 * $u - $w / 2), $bg);
    // the sun: a circle of radius 1.7 at (9,10)
    imagefilledellipse($img, (int) round($X(9)), (int) round($Y(10)), (int) round(($u * 1.7 + $w / 2) * 2), (int) round(($u * 1.7 + $w / 2) * 2), $ink);
    imagefilledellipse($img, (int) round($X(9)), (int) round($Y(10)), (int) round(($u * 1.7 - $w / 2) * 2), (int) round(($u * 1.7 - $w / 2) * 2), $bg);
    // the hill line: (7,20) up to (16,11) down to (21,16), round ends and corner
    foreach ([[[7, 20], [16, 11]], [[16, 11], [21, 16]]] as [[$ax, $ay], [$bx, $by]]) {   // each segment as a filled strip of the stroke's width
        $dx = $X($bx) - $X($ax); $dy = $Y($by) - $Y($ay); $len = sqrt($dx * $dx + $dy * $dy);
        $nx = -$dy / $len * $w / 2; $ny = $dx / $len * $w / 2;
        imagefilledpolygon($img, [(int) round($X($ax) + $nx), (int) round($Y($ay) + $ny), (int) round($X($bx) + $nx), (int) round($Y($by) + $ny), (int) round($X($bx) - $nx), (int) round($Y($by) - $ny), (int) round($X($ax) - $nx), (int) round($Y($ay) - $ny)], $ink);
    }
    foreach ([[7, 20], [16, 11], [21, 16]] as [$x, $y]) imagefilledellipse($img, (int) round($X($x)), (int) round($Y($y)), (int) round($w), (int) round($w), $ink);
    $out = imagecreatetruecolor($px, $px);
    imagecopyresampled($out, $img, 0, 0, 0, 0, $px, $px, $big, $big);
    unset($img);
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=31536000, immutable');   // the address carries a version (v=), so a new drawing is a new address
    imagepng($out);
    unset($out);
}

/**
 * Reads a clip's duration (in seconds) straight out of its MP4/MOV
 * container's mvhd box — no transcoding, no external dependency, just
 * walking a handful of well-documented box headers. iPhone video
 * commonly places the moov atom at the *end* of the file (mdat written
 * first, moov finalized after recording stops), so this walks top-level
 * boxes by their declared size rather than assuming moov is near the
 * start — each step is a seek + an 8-or-16-byte read, so a large
 * preceding mdat costs nothing extra to skip over. Returns null on
 * anything that doesn't parse as a well-formed enough container — a
 * missing duration badge is a fine degradation, not worth failing the
 * whole page over.
 */
function video_duration_seconds($path) {
    $fh = @fopen($path, 'rb');
    if ($fh === false) return null;
    $fileSize = @filesize($path);
    if ($fileSize === false || $fileSize < 8) {
        fclose($fh);
        return null;
    }

    $maxBoxes = 10000; // sanity guard against a corrupt/adversarial file forcing an infinite loop
    $findBox = function ($startOffset, $rangeEnd, $wantType) use ($fh, &$maxBoxes) {
        $pos = $startOffset;
        $count = 0;
        while ($pos < $rangeEnd && $count++ < $maxBoxes) {
            if (fseek($fh, $pos) !== 0) return null;
            $header = fread($fh, 8);
            if ($header === false || strlen($header) < 8) return null;
            $size = unpack('N', substr($header, 0, 4))[1];
            $type = substr($header, 4, 4);
            $headerLen = 8;
            if ($size === 1) {
                $ext = fread($fh, 8);
                if ($ext === false || strlen($ext) < 8) return null;
                $size = unpack('J', $ext)[1];
                $headerLen = 16;
            }
            if ($size !== 0 && $size < $headerLen) return null; // corrupt
            if ($type === $wantType) {
                return ['offset' => $pos + $headerLen, 'size' => $size === 0 ? ($rangeEnd - $pos - $headerLen) : $size - $headerLen];
            }
            if ($size === 0) return null; // box extends to EOF and it's not the one we want
            $pos += $size;
        }
        return null;
    };

    $moov = $findBox(0, $fileSize, 'moov');
    if ($moov === null) {
        fclose($fh);
        return null;
    }
    $mvhd = $findBox($moov['offset'], $moov['offset'] + $moov['size'], 'mvhd');
    if ($mvhd === null) {
        fclose($fh);
        return null;
    }

    fseek($fh, $mvhd['offset']);
    $body = fread($fh, min($mvhd['size'], 32));
    fclose($fh);
    if ($body === false || strlen($body) < 1) return null;

    $version = ord($body[0]);
    if ($version === 1) {
        if (strlen($body) < 32) return null;
        $timescale = unpack('N', substr($body, 20, 4))[1];
        $duration = unpack('J', substr($body, 24, 8))[1];
    } else {
        if (strlen($body) < 20) return null;
        $timescale = unpack('N', substr($body, 12, 4))[1];
        $duration = unpack('N', substr($body, 16, 4))[1];
    }
    if ($timescale <= 0) return null;
    return $duration / $timescale;
}

/** Formats a duration in seconds as a plain "M:SS" badge (no leading
 *  hours unit — these are short trip clips, not feature-length video). */
function format_duration($seconds) {
    $seconds = (int) round($seconds);
    if ($seconds < 0) $seconds = 0;
    $m = intdiv($seconds, 60);
    $s = $seconds % 60;
    return $m . ':' . str_pad((string) $s, 2, '0', STR_PAD_LEFT);
}

/** The name (without its extension) a made file is kept under: 32 hex characters of a keyed hash of the file's path, which kind it is and
 *  when the original was changed. Keyed with this install's secret, so nobody can work out where a private photo's thumbnail is kept
 *  from the photo's name and date alone (the cache folder is also closed by a server rule, but that is one setting). The secret is made
 *  at the first sign-in; until then (a gallery nobody has signed in to) the unkeyed name earlier versions used stays, and a page view
 *  renames those files once the secret exists (see gc_image_cache). */
