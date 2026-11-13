/** One group of media_attention() as a folded list of file names, each linked to that file's editor (for the Health check's Media rows). */
function render_media_group_html($grp) {
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $out = '<details class="media-attn media-attn-' . $esc($grp['key']) . '"><summary>Show the file' . ($grp['count'] === 1 ? '' : 's') . '</summary><ul class="media-attn-list">';
    foreach ($grp['items'] as $file) $out .= '<li><a href="' . admin_find_href($file) . '" title="Show it in its folder">' . $esc($file) . '</a></li>';
    if ($grp['count'] > count($grp['items'])) $out .= '<li class="muted">and ' . number_format($grp['count'] - count($grp['items'])) . ' more</li>';
    return $out . '</ul></details>';
}

/** The "Backups from Tidy up" line of the Prepare media panel, as HTML; empty when there are none. */
function render_media_backups_html($backups) {
    if (!$backups) return '';
    $folders = []; foreach ($backups as $b) $folders[$b['folder']] = true;
    $first = $backups[0]['at']; $last = $backups[count($backups) - 1]['at'];
    $fmt = function ($t) { return gmdate('j M Y', $t); };
    return '<p class="hint media-backups-line"><strong>' . count($backups) . ' backup' . (count($backups) === 1 ? '' : 's') . ' from Tidy up</strong> (the folders\' data as it was before, in ' . count($folders) . ' folder' . (count($folders) === 1 ? '' : 's') . ', ' . ($fmt($first) === $fmt($last) ? $fmt($first) : $fmt($first) . ' to ' . $fmt($last)) . '). Keep them until you have looked through the gallery. <button type="button" class="secondary media-backups-delete">Delete the backups&hellip;</button> <span class="media-backups-out" aria-live="polite"></span></p>';
}

/** Everything Prepare media's list says, one row per folder that has files: the folder, its files, how many photos have their tile pictures, and
 *  media_folder_summary()'s counts. */
function media_report($dir, $files) {
    $byFolder = [];
    foreach ($files as $file) {
        $slash = strrpos($file, '/');
        $byFolder[$slash === false ? '' : substr($file, 0, $slash)][] = $file;
    }
    ksort($byFolder, SORT_NATURAL | SORT_FLAG_CASE);
    $rows = [];
    foreach ($byFolder as $path => $list) {
        $folderReal = containing_folder_real_path($dir, $list[0]);
        $sum = media_folder_summary($dir, $folderReal, $list);
        $tiles = 0; $photos = 0;
        foreach ($list as $file) {
            if (is_video_file($file)) continue;
            $photos++;
            $src = resolve_real_path($dir, $file);
            $ok = true;
            foreach (array_keys(TILE_SIZES) as $variant) {
                if (!is_file($folderReal . '/.gallery-cache/' . cache_name($file, $variant, @filemtime($src)) . '.jpg')) { $ok = false; break; }
            }
            if ($ok) $tiles++;
        }
        $vids = 0; $posters = 0; $clips = 0; $strips = 0; $vfail = 0;
        foreach ($list as $file) {
            if (!is_video_file($file)) continue;
            $vids++; $vs = video_made_state($dir, $file);
            if ($vs['poster'] === 'ready') $posters++;
            if ($vs['clip'] === 'ready') $clips++;
            if ($vs['strip'] === 'ready') $strips++;
            if (in_array('failed', $vs, true)) $vfail++;
        }
        $rows[] = ['folder' => $path, 'photos' => $photos, 'tiles' => $tiles, 'videos' => $vids, 'posters' => $posters, 'clips' => $clips, 'strips' => $strips, 'videoFailed' => $vfail] + $sum;
    }
    return $rows;
}

/** Serves ?img=<filename-or-relative-path>&size=thumb|share|full,
 *  resizing/caching/converting as needed. $filename is validated by exact
 *  match against $validFiles — the real list this script's own scan
 *  already trusts — never basename()'d first, since that would strip a
 *  legitimate subfolder prefix like "Paris/" right back off. The exact-
 *  match allowlist is what actually provides the safety here: a
 *  traversal attempt never matches anything the scan itself produced,
 *  with or without that extra step, and nothing resembling "../" can
 *  survive that match regardless of how many segments the path has. */
function serve_image($dir, $filename, $variant, $validFiles, $itemsData = []) {
    // ?size[]=x arrives as an array; anything that isn't a plain string
    // is just the default size.
    if (!is_string($variant)) $variant = 'thumb';
    $wantOriginal = $variant === 'orig';   // (what the viewer asks for a HEIC photo when this browser can show one: the file itself, not a converted copy)
    if ($wantOriginal) $variant = 'full';
    if (!in_array($filename, $validFiles, true)) {
        http_response_code(404);
        return;
    }
    // A checkbox that only skips a thumbnail in the grid isn't real
    // privacy if the file itself is still fetchable directly — this
    // refuses it to anyone without a valid reveal, the same allowlist-
    // plus-identity-check discipline as the line above.
    if (!viewer_may_see_item($dir, $filename, $itemsData)) {
        http_response_code(404);
        return;
    }
    // Someone using a share link is sent a private picture: noted in that link's log (their browser reports what was on screen too).
    if ($variant !== 'full' && ($linkNote = visitor_logged_link()) !== null && file_is_hidden($dir, $filename, $itemsData)) link_queue_delivery($linkNote, 's', $filename);
    $cacheControl = file_cache_control($dir, $filename, $itemsData);
    $maxDim = image_variant_dim($variant);

    // No frame extraction (see ENABLE_VIDEO's doc comment) — every size
    // of a video's "thumbnail," grid tile or Open Graph share preview
    // alike, gets the same generic placeholder graphic instead.
    $srcPath = resolve_real_path($dir, $filename);
    $cacheDir = containing_folder_real_path($dir, $filename) . '/.gallery-cache';
    $pictureSrc = $srcPath;   // where the pixels come from: the file itself, or for a video the frame taken from it
    if (is_video_file($filename)) {
        // A real frame from the video when ffmpeg can take one; everything below then treats that picture as the source.
        $poster = video_poster_path($srcPath, $cacheDir, $filename);
        if ($poster === null) {
            render_video_placeholder_image(min($maxDim, 640));
            return;
        }
        if ($variant === 'full') {
            header('Content-Type: image/jpeg');
            header('Cache-Control: ' . $cacheControl);
            readfile($poster);
            return;
        }
        $pictureSrc = $poster;
    }
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $isHeic = in_array($ext, ['heic', 'heif'], true);

    // A HEIC photo to a browser that shows HEIC (Safari): the original, at its own size and with its own colours; the same rules as any original
    // (a visitor who may not see where it was taken gets it with the location blanked out). Anything that can't be done that way is the converted copy below.
    if ($wantOriginal && $isHeic && $variant === 'full' && send_original_for_visitor($srcPath, $filename, $itemsData, $cacheControl)) return;

    // Non-HEIC files at full size are served untouched — no need to
    // resize or re-encode the original the lightbox already opens fine.
    // (Except for a visitor who isn't signed in and a photo whose location is hidden: the file is the original, with its
    // GPS, so that visitor is sent it with the location blanked out, or the re-encoded copy below if that can't be done.)
    if (!$isHeic && $variant === 'full') {
        if (send_original_for_visitor($srcPath, $filename, $itemsData, $cacheControl)) return;
    }

    $cachePath = image_cache_path($cacheDir, $filename, $variant, $srcPath);
    if ($cachePath && file_exists($cachePath) && filemtime($cachePath) >= @filemtime($srcPath)) {
        header('Content-Type: image/jpeg');
        header('Cache-Control: ' . $cacheControl);
        readfile($cachePath);
        return;
    }

    $ok = $cachePath ? make_image_variant($pictureSrc, $cachePath, $variant) : false;
    if ($ok) {
        header('Content-Type: image/jpeg');
        header('Cache-Control: ' . $cacheControl);
        readfile($cachePath);
        // The first time the gallery meets this file: now that its picture is made, read what the file says about itself
        // (when it was taken, where) and keep it, after the visitor has been answered where the server allows.
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
        media_meta_ensure($dir, $filename);
        return;
    }
    if ($pictureSrc !== $srcPath) {
        render_video_placeholder_image(min($maxDim, 640));   // the frame couldn't be cut down: the placeholder, never the video file as a picture
        return;
    }

    // Couldn't generate (no Imagick/GD, cache folder unwritable, or a
    // HEIC file with no capable backend). Non-HEIC formats can still just
    // serve the original; HEIC genuinely cannot be shown without conversion.
    if ($isHeic) {
        http_response_code(415);
        return;
    }
    // Ordinary photos keep the headers they have always had on this path
    // (none); a hidden one must still never be stored by a shared cache.
    if (!send_original_for_visitor($srcPath, $filename, $itemsData, $cacheControl === 'private, no-store' ? $cacheControl : null)) {
        http_response_code(404);
    }
}

/**
 * A short tag that changes whenever a photo or video is replaced — a hash
 * of its modification time and size, so replacing a file under the same
 * name (even with one the same size, or an old timestamp preserved by a
 * copy tool) gives it a new tag in all but freak cases. It rides along on
 * the image/video address as &v=..., because those responses are cached
 * by browsers for a year ("immutable") and the address alone never
 * changed: without it, a replaced photo kept showing the old picture to
 * anyone who had seen it. Hashed rather than the raw timestamp, so a
 * visitor can't read when each file was uploaded or last touched. The
 * server ignores it. One stat per file per request, remembered.
 */
function file_version($file) {
    static $seen = [];
    if (isset($seen[$file])) return $seen[$file];
    $stat = @stat(resolve_real_path($GLOBALS['dir'], $file));
    return $seen[$file] = $stat ? substr(hash('sha256', $stat['mtime'] . '|' . $stat['size']), 0, 10) : '';
}

function img_url($file, $size) {
    $v = file_version($file);
    return '?img=' . rawurlencode($file) . '&size=' . $size . ($v !== '' ? '&v=' . $v : '') . (($GLOBALS['photoPass'] ?? null) === $file ? '&k=' . rawurlencode((string) ($_GET['k'] ?? '')) : '');   // (on a photo's own address its pictures ask with the same code)
}

/** The extra attributes that let the browser choose a tile's picture by how big the tile really is and how dense the screen
 *  is: the 560 pixel one for an ordinary screen, the 1080 one for a phone. (The tile is one column wide on a phone in
 *  portrait, otherwise 180 to 250 points.) Where thumbnails are off there is only the one picture. */
function tile_srcset_attrs($file) {
    if (!ENABLE_THUMBNAIL_CACHE) return '';
    return ' srcset="' . htmlspecialchars(img_url($file, 'tile') . ' ' . TILE_SIZES['tile'][0] . 'w, ' . img_url($file, 'tilehd') . ' ' . TILE_SIZES['tilehd'][0] . 'w', ENT_QUOTES, 'UTF-8')
         . '" sizes="(max-width: 417px) calc(100vw - 48px), 250px"';
}

/** A location-pin badge's link target — OpenStreetMap needs no API key
 *  and no dependency this project doesn't already avoid adding, unlike
 *  a full in-app map (a later, dedicated phase). */
function osm_location_url($lat, $lng) {
    return 'https://www.openstreetmap.org/?mlat=' . rawurlencode($lat) . '&mlon=' . rawurlencode($lng)
         . '#map=16/' . rawurlencode($lat) . '/' . rawurlencode($lng);
}


/**
 * Serves ?video=<filename-or-relative-path> with HTTP Range support —
 * the one genuinely new piece of serving logic video needs. The file is
 * never transcoded or re-encoded, but video playback does need seeking,
 * and a browser seeks by issuing a Range request; without honoring it,
 * seeking either breaks outright or forces re-downloading the whole file
 * from the start. $filename is validated the same exact-allowlist way as
 * serve_image() above — and since ENABLE_VIDEO=false simply never adds
 * .mov/.mp4 to $validFiles in the first place, a request here fails
 * closed on its own with no extra check needed for the feature being off.
 * The hidden-item check below runs before any Range handling, not after
 * — a second code path added later just for partial requests is exactly
 * the kind of place a gate like this gets forgotten.
 */
function serve_video($dir, $filename, $validFiles, $itemsData = []) {
    if (!in_array($filename, $validFiles, true)) {
        http_response_code(404);
        return;
    }
    if (!viewer_may_see_item($dir, $filename, $itemsData)) {
        http_response_code(404);
        return;
    }
    $cacheControl = file_cache_control($dir, $filename, $itemsData);
    $srcPath = resolve_real_path($dir, $filename);
    $size = @filesize($srcPath);
    if ($size === false) {
        http_response_code(404);
        return;
    }
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    // A visitor who isn't signed in, and a video whose location is hidden: the location is blanked out of what is sent.
    $plan = visitor_scrub_plan($srcPath, $filename, $itemsData);
    if ($plan === null) {
        http_response_code(404);
        return;
    }
    if ($plan !== []) $cacheControl = 'private, max-age=86400';

    // Once nginx is configured for this (see serve_original_file()),
    // hand the whole request to its own static serving instead — nginx
    // already handles Range requests correctly on its own, a free
    // upgrade over the plain-PHP handling below, which stays as the
    // baseline that works on any host regardless.
    if (NGINX_ACCEL_REDIRECT_PREFIX !== '' && $plan === [] && file_is_under_web_root($srcPath, $filename)) {
        header('Cache-Control: ' . $cacheControl);
        serve_original_file($srcPath, $filename, guess_mime($ext));
        return;
    }

    header('Content-Type: ' . guess_mime($ext));
    header('Cache-Control: ' . $cacheControl);
    if ($plan !== []) header('Vary: Cookie');
    header('Accept-Ranges: bytes');

    $start = 0;
    $end = $size - 1;
    $range = $_SERVER['HTTP_RANGE'] ?? '';
    $isRangeRequest = false;
    if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m) && ($m[1] !== '' || $m[2] !== '')) {
        $isRangeRequest = true;
        if ($m[1] === '') {
            // Suffix range ("bytes=-500"): the last N bytes.
            $suffixLen = (int) $m[2];
            $start = max(0, $size - $suffixLen);
            $end = $size - 1;
        } else {
            $start = (int) $m[1];
            $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);
        }
        if ($start >= $size || $start > $end) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            return;
        }
    }
    // A Range header present but not matching "bytes=..." at all (some
    // other unit, or genuinely malformed) is simply ignored — falls back
    // to serving the whole file with a normal 200, rather than erroring.

    $fh = @fopen($srcPath, 'rb');
    if ($fh === false) {
        http_response_code(404);
        return;
    }
    if ($isRangeRequest) {
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }
    $length = $end - $start + 1;
    header('Content-Length: ' . $length);

    fseek($fh, $start);
    $remaining = $length;
    $at = $start;
    $chunkSize = 8192;
    while ($remaining > 0 && !feof($fh)) {
        $chunk = fread($fh, min($chunkSize, $remaining));
        if ($chunk === false || $chunk === '') break;
        echo $plan === [] ? $chunk : apply_patches_to_chunk($chunk, $at, $plan);
        $remaining -= strlen($chunk);
        $at += strlen($chunk);
    }
    fclose($fh);
}


