function cache_name($file, $variant, $mtime) {
    $secret = install_secret();   // (made at the first sign-in; a gallery nobody has signed in to has nothing private to protect, and a visitor never makes a file but a thumbnail)
    $text = $file . '|' . $variant . '|' . $mtime;
    return $secret === '' ? md5($text) : substr(hash_hmac('sha256', $text, $secret), 0, 32);
}

function image_cache_path($cacheDir, $filename, $variant, $srcPath) {
    if (!ENABLE_THUMBNAIL_CACHE) return null;
    if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0775, true)) return null;
    if (!is_writable($cacheDir)) return null;
    $mtime = @filemtime($srcPath);
    return $cacheDir . '/' . cache_name($filename, $variant, $mtime) . '.jpg';
}

/** What a folder's .gallery-cache holds for its files, by name: [the names this gallery would make or read now (including capture dates
 *  and the file data), the names an unkeyed older version used => the keyed name they become]. $folderFiles are paths as the gallery
 *  knows them (relative to $dir). Shared by the clean-up (anything else is removed) and by the list of leftovers (see moved_in_leftovers). */
function image_cache_expected_names($dir, $folderFiles, $imagickAvailable) {
    $valid = []; $renamed = [];
    foreach ($folderFiles as $file) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $isHeic = in_array($ext, ['heic', 'heif'], true) && $imagickAvailable;
        $variants = $isHeic ? ['thumb', 'tile', 'tilehd', 'share', 'full', 'cover', 'coverlg'] : ['thumb', 'tile', 'tilehd', 'share', 'cover', 'coverlg'];
        if (is_video_file($file)) { $variants[] = 'poster'; $variants[] = 'strip2'; }   // the frame taken from a video, and its sprite of frames
        $mtime = @filemtime(resolve_real_path($dir, $file));
        $valid['taken3.json'] = true;   // the capture dates found for sorting (see media_taken_times)
        $valid[MEDIA_META_FILE] = true;   // what was read from the files: when taken, where (see media_meta_ensure)
        if (is_video_file($file)) { $valid[cache_name($file, 'preview2', $mtime) . '.mp4'] = true; $renamed[md5($file . '|preview2|' . $mtime) . '.mp4'] = cache_name($file, 'preview2', $mtime) . '.mp4'; }   // and its short clip (see video_preview_path)
        foreach ($variants as $variant) {
            $valid[cache_name($file, $variant, $mtime) . '.jpg'] = true;
            $renamed[md5($file . '|' . $variant . '|' . $mtime) . '.jpg'] = cache_name($file, $variant, $mtime) . '.jpg';
        }
    }
    return [$valid, $renamed];
}

/**
 * Deletes cached thumbnails that no longer correspond to any current
 * photo. A cache entry is keyed by filename+variant+mtime (see
 * image_cache_path), so a renamed/deleted/edited photo just orphans its
 * old cache file rather than overwriting it — this sweeps those away,
 * so a folder whose photos get swapped out over time doesn't
 * accumulate stale thumbnails forever. Only runs on normal gallery page
 * loads (never on ?img/?track), so it doesn't add overhead to the
 * requests that actually serve images.
 *
 * $files are relative paths, and each one's cache lives colocated in its
 * *own* containing folder (see containing_folder_real_path()) rather
 * than one centralized cache at the root — so this groups by containing
 * folder first and GCs each one independently. A flat gallery has
 * exactly one such group (the root itself), so this is a no-op change
 * in shape for that case, not just in effect.
 */
function gc_image_cache($dir, $files, $imagickAvailable) {
    if (!ENABLE_THUMBNAIL_CACHE) return;
    $byFolder = [];
    foreach ($files as $file) {
        $byFolder[containing_folder_real_path($dir, $file)][] = $file;
    }
    $ownRoot = rtrim((string) (realpath($dir) ?: $dir), '/') . '/';
    foreach ($byFolder as $folderPath => $folderFiles) {
        $cacheDir = $folderPath . '/.gallery-cache';
        if (!is_dir($cacheDir)) continue;
        // A place from elsewhere on the server may also be shown by another gallery (an older copy still running there): its
        // thumbnails are named with that gallery's own key, so to this one they look like leftovers. Nothing is swept there.
        if (strpos(rtrim((string) (realpath($folderPath) ?: $folderPath), '/') . '/', $ownRoot) !== 0) continue;
        [$valid, $renamed] = image_cache_expected_names($dir, $folderFiles, $imagickAvailable);
        foreach (scandir($cacheDir) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            // LEGACY (see docs/LEGACY-REMOVAL.md): before 2.4.0 the names were an unkeyed md5; a made file under the old name is moved to the new one, not made again.
            if (isset($renamed[$entry]) && $renamed[$entry] !== $entry) {
                if (!file_exists($cacheDir . '/' . $renamed[$entry])) @rename($cacheDir . '/' . $entry, $cacheDir . '/' . $renamed[$entry]);
                continue;
            }
            // A video's "didn't work lately" note and a picture still being written belong to a current video.
            $base = preg_replace('/\.(failed|tmp[0-9a-f]*)$/', '', $entry);
            if ($base !== $entry && isset($valid[$base]) && ($base === $entry || time() - (int) @filemtime($cacheDir . '/' . $entry) < 21600)) continue;
            if (!isset($valid[$entry])) {
                @unlink($cacheDir . '/' . $entry);
            }
        }
    }
}

/** Resizes (and, for HEIC, converts) an image to JPEG using Imagick if
 *  available, otherwise GD. Returns true on success. The long side ends up at most $maxDim (a picture is never enlarged).
 *  The JPEG is progressive: it paints coarse-to-fine while it loads. */
function generate_resized_jpeg($srcPath, $destPath, $maxDim, $quality = 82) {
    if (class_exists('Imagick')) {
        try {
            $img = new Imagick();
            $img->readImage($srcPath);
            if (method_exists($img, 'autoOrient')) {
                $img->autoOrient();
            }
            $img->setImageFormat('jpeg');
            $img->setImageCompressionQuality($quality);
            $w = $img->getImageWidth();
            $h = $img->getImageHeight();
            if (max($w, $h) > $maxDim) {
                $img->thumbnailImage($maxDim, $maxDim, true);
                if (max($w, $h) > $maxDim * 2 && method_exists($img, 'unsharpMaskImage')) $img->unsharpMaskImage(0, 0.5, 0.5, 0.02);   // a picture shrunk a lot comes out a touch soft
            }
            if (defined('Imagick::INTERLACE_PLANE')) $img->setInterlaceScheme(Imagick::INTERLACE_PLANE);
            $img->stripImage();
            $ok = $img->writeImage($destPath);
            $img->clear();
            $img->destroy();
            if ($ok) return true;
        } catch (Exception $e) {
            // Fall through to GD — won't help with HEIC, but keeps
            // standard formats working if Imagick chokes on a given file.
        }
    }
    if (extension_loaded('gd')) {
        $data = @file_get_contents($srcPath);
        if ($data === false) return false;
        $src = @imagecreatefromstring($data);
        if (!$src) return false;

        $ext = strtolower(pathinfo($srcPath, PATHINFO_EXTENSION));
        if (function_exists('exif_read_data') && in_array($ext, ['jpg', 'jpeg'], true)) {
            $exif = @exif_read_data($srcPath);
            if (!empty($exif['Orientation'])) {
                if ($exif['Orientation'] === 3) $src = imagerotate($src, 180, 0);
                if ($exif['Orientation'] === 6) $src = imagerotate($src, -90, 0);
                if ($exif['Orientation'] === 8) $src = imagerotate($src, 90, 0);
            }
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1, $maxDim / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imageinterlace($dst, true);
        $ok = imagejpeg($dst, $destPath, $quality);
        unset($src);
        unset($dst);
        return $ok;
    }
    return false;
}

/**
 * A square cut of a photo for a folder's cover card, taken from the original so it stays sharp at any card size. The
 * square is the largest one that fits; its position keeps the middle across a landscape photo, and the upper-middle
 * (38% of the way down the spare height) of a portrait one, where faces and horizons usually are, so most of the scene
 * survives the crop. $dim is the side of the finished square.
 */
function generate_cover_jpeg($srcPath, $destPath, $dim) {
    if (class_exists('Imagick')) {
        try {
            $img = new Imagick();
            $img->readImage($srcPath);
            if (method_exists($img, 'autoOrient')) $img->autoOrient();
            $img->setImageFormat('jpeg');
            $img->setImageCompressionQuality(84);
            $w = $img->getImageWidth();
            $h = $img->getImageHeight();
            $side = min($w, $h);
            $img->cropImage($side, $side, (int) round(($w - $side) / 2), (int) round(($h - $side) * 0.38));
            $img->setImagePage(0, 0, 0, 0);
            if ($side > $dim) $img->thumbnailImage($dim, $dim);
            $img->stripImage();
            $ok = $img->writeImage($destPath);
            $img->clear();
            $img->destroy();
            if ($ok) return true;
        } catch (Exception $e) {
            // Fall through to GD.
        }
    }
    if (extension_loaded('gd')) {
        $data = @file_get_contents($srcPath);
        if ($data === false) return false;
        $src = @imagecreatefromstring($data);
        if (!$src) return false;
        $ext = strtolower(pathinfo($srcPath, PATHINFO_EXTENSION));
        if (function_exists('exif_read_data') && in_array($ext, ['jpg', 'jpeg'], true)) {
            $exif = @exif_read_data($srcPath);
            if (!empty($exif['Orientation'])) {
                if ($exif['Orientation'] === 3) $src = imagerotate($src, 180, 0);
                if ($exif['Orientation'] === 6) $src = imagerotate($src, -90, 0);
                if ($exif['Orientation'] === 8) $src = imagerotate($src, 90, 0);
            }
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $side = min($w, $h);
        $out = min($dim, $side);
        $dst = imagecreatetruecolor($out, $out);
        imagecopyresampled($dst, $src, 0, 0, (int) round(($w - $side) / 2), (int) round(($h - $side) * 0.38), $out, $out, $side, $side);
        $ok = imagejpeg($dst, $destPath, 84);
        unset($src);
        unset($dst);
        return $ok;
    }
    return false;
}

/** The longest side, in pixels, a size of picture is cut to. */
function image_variant_dim($variant) {
    if (isset(TILE_SIZES[$variant])) return TILE_SIZES[$variant][0];
    return ['thumb' => 480, 'share' => 1200, 'full' => 2400, 'cover' => 640, 'coverlg' => 1200][$variant] ?? 480;
}

/** Makes the cached picture of one size ($variant) of a photo, whatever way that size is cut. True when it was written. */
function make_image_variant($pictureSrc, $cachePath, $variant) {
    // A folder's cover card is a square cut from the original photo (not from its small thumbnail), so it stays sharp.
    if ($variant === 'cover' || $variant === 'coverlg') return generate_cover_jpeg($pictureSrc, $cachePath, image_variant_dim($variant));
    if (isset(TILE_SIZES[$variant])) return generate_tile_jpegs($pictureSrc, [$variant => $cachePath]);
    return generate_resized_jpeg($pictureSrc, $cachePath, image_variant_dim($variant));
}

/** The square cut from the middle of a photo that a tile shows (the tile crops the same way, so nothing it would show is
 *  lost), made at each size asked for from one reading of the photo: $targets is [variant => where to write it].
 *  Never enlarged: a small photo gives a smaller square. Progressive JPEGs, touched up a little after shrinking. True
 *  when every one was written. */
function generate_tile_jpegs($srcPath, $targets) {
    $done = 0;
    if (class_exists('Imagick')) {
        try {
            $img = new Imagick();
            $img->readImage($srcPath);
            if (method_exists($img, 'autoOrient')) $img->autoOrient();
            $img->setImageFormat('jpeg');
            $w = $img->getImageWidth();
            $h = $img->getImageHeight();
            $side = min($w, $h);
            $img->cropImage($side, $side, (int) round(($w - $side) / 2), (int) round(($h - $side) / 2));
            $img->setImagePage(0, 0, 0, 0);
            $img->stripImage();
            foreach ($targets as $variant => $dest) {
                [$dim, $quality] = TILE_SIZES[$variant];
                $one = clone $img;
                $one->setImageCompressionQuality($quality);
                if ($side > $dim) {
                    $one->thumbnailImage($dim, $dim);
                    if ($side > $dim * 2 && method_exists($one, 'unsharpMaskImage')) $one->unsharpMaskImage(0, 0.5, 0.5, 0.02);
                }
                if (defined('Imagick::INTERLACE_PLANE')) $one->setInterlaceScheme(Imagick::INTERLACE_PLANE);
                if ($one->writeImage($dest)) $done++;
                $one->clear(); $one->destroy();
            }
            $img->clear(); $img->destroy();
            if ($done === count($targets)) return true;
        } catch (Exception $e) {
            // Fall through to GD for whatever is left.
        }
        $targets = array_filter($targets, function ($dest) { return !is_file($dest) || filesize($dest) === 0; });
        $done = 0;
        if (!$targets) return true;
    }
    if (extension_loaded('gd')) {
        $data = @file_get_contents($srcPath);
        if ($data === false) return false;
        $src = @imagecreatefromstring($data);
        if (!$src) return false;
        $ext = strtolower(pathinfo($srcPath, PATHINFO_EXTENSION));
        if (function_exists('exif_read_data') && in_array($ext, ['jpg', 'jpeg'], true)) {
            $exif = @exif_read_data($srcPath);
            if (!empty($exif['Orientation'])) {
                if ($exif['Orientation'] === 3) $src = imagerotate($src, 180, 0);
                if ($exif['Orientation'] === 6) $src = imagerotate($src, -90, 0);
                if ($exif['Orientation'] === 8) $src = imagerotate($src, 90, 0);
            }
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $side = min($w, $h);
        foreach ($targets as $variant => $dest) {
            [$dim, $quality] = TILE_SIZES[$variant];
            $out = min($dim, $side);
            $dst = imagecreatetruecolor($out, $out);
            imagecopyresampled($dst, $src, 0, 0, (int) round(($w - $side) / 2), (int) round(($h - $side) / 2), $out, $out, $side, $side);
            imageinterlace($dst, true);
            if (imagejpeg($dst, $dest, $quality)) $done++;
            unset($dst);
        }
        unset($src);
        return $done === count($targets);
    }
    return false;
}

/** Which of a video's three made files the settings ask for: its poster frame, its hover clip and its scrub strip. */
function video_made_wanted() {
    $cache = ENABLE_THUMBNAIL_CACHE;
    return ['poster' => $cache && ENABLE_VIDEO_THUMBNAILS, 'clip' => $cache && ENABLE_VIDEO_PREVIEWS, 'strip' => $cache && ENABLE_VIDEO_PREVIEWS];
}

/** Where each of a video's made files is (or would be) kept: 'poster', 'clip', 'strip' => a path. Nothing is created. */
function video_made_paths($dir, $file) {
    $src = resolve_real_path($dir, $file);
    $cacheDir = containing_folder_real_path($dir, $file) . '/.gallery-cache';
    $mt = @filemtime($src);
    return ['poster' => $cacheDir . '/' . cache_name($file, 'poster', $mt) . '.jpg', 'clip' => $cacheDir . '/' . cache_name($file, 'preview2', $mt) . '.mp4', 'strip' => $cacheDir . '/' . cache_name($file, 'strip2', $mt) . '.jpg'];
}

/** The state of each of a video's made files: 'ready', 'missing' (to be made), 'failed' (ffmpeg could not, lately: not tried again for six
 *  hours), 'unavailable' (no scrub strip can be made: the length can't be read) or 'off' (the settings don't ask for it). */
function video_made_state($dir, $file) {
    $want = video_made_wanted(); $paths = video_made_paths($dir, $file); $out = [];
    foreach ($paths as $k => $path) {
        if (!$want[$k]) { $out[$k] = 'off'; continue; }
        if (is_file($path) && filesize($path) > 0) $out[$k] = 'ready';
        elseif (is_file($path . '.failed') && time() - (int) @filemtime($path . '.failed') < 21600) $out[$k] = 'failed';
        else $out[$k] = 'missing';
    }
    if ($out['strip'] === 'missing') { $d = video_duration_seconds(resolve_real_path($dir, $file)); if ($d === null || $d < 1) $out['strip'] = 'unavailable'; }
    return $out;
}

/** What Prepare media still has to do: how many files there are, how many photos lack some tile size, how many files have no usable
 *  record of what they say about themselves (when taken, where), how many videos lack their poster, clip or strip (when ffmpeg is there to
 *  make them), and how many files need any of it (that is what the progress counts). */
function media_readiness($dir, $files) {
    $ff = ffmpeg_binary() !== '';
    $r = ['total' => 0, 'photos' => 0, 'tilesReady' => 0, 'metaLeft' => 0, 'left' => 0, 'videos' => 0, 'videosReady' => 0, 'posters' => 0, 'clips' => 0, 'strips' => 0, 'videoFailed' => 0, 'videoLeft' => 0, 'ffmpeg' => $ff, 'videoWanted' => array_values(array_filter(array_keys(video_made_wanted()), function ($k) { return video_made_wanted()[$k]; }))];
    foreach ($files as $file) {
        $src = resolve_real_path($dir, $file);
        if (!$src) continue;
        $r['total']++;
        $needTiles = false; $needVideo = false;
        if (!is_video_file($file)) {
            $r['photos']++;
            $cacheDir = containing_folder_real_path($dir, $file) . '/.gallery-cache';
            $all = true;
            foreach (array_keys(TILE_SIZES) as $variant) {
                $path = $cacheDir . '/' . cache_name($file, $variant, @filemtime($src)) . '.jpg';
                if (!is_file($path)) { $all = false; break; }
            }
            if ($all) $r['tilesReady']++; else $needTiles = true;
        } else {
            $r['videos']++;
            $vs = video_made_state($dir, $file);
            foreach (['poster' => 'posters', 'clip' => 'clips', 'strip' => 'strips'] as $k => $count) if ($vs[$k] === 'ready') $r[$count]++;
            if (!in_array('missing', $vs, true) && !in_array('failed', $vs, true)) $r['videosReady']++;
            if (in_array('failed', $vs, true)) $r['videoFailed']++;
            if ($ff && in_array('missing', $vs, true)) { $needVideo = true; $r['videoLeft']++; }
        }
        $needMeta = !media_meta_fresh(media_meta_load(containing_folder_real_path($dir, $file))[basename($file)] ?? null, $src);   // (one that couldn't be read is tried again by a run, but isn't counted as left)
        if ($needMeta) $r['metaLeft']++;
        if ($needTiles || $needMeta || $needVideo) $r['left']++;
    }
    return $r;
}

/** Prepare media, one slice: from file number $from onward, every $step-th one (several requests share the work, each with its own
 *  start and the same step), for about $budget seconds, then says where to carry on. For each file: a photo's missing tile pictures are
 *  made (all of them again when $remake), and what the file says about itself is read and recorded when it has no usable record (every
 *  file again when $again, which also lets a location in the file show for photos carrying an old "read, nothing found" mark, see
 *  media_file_location). A file that can't be handled, such as a damaged one, is counted and passed over rather than tried forever.
 *  'worked' is how many files needed something in this call. */
function media_prepare_batch($dir, $files, $from, $budget, $step = 1, $again = false, $remake = false) {
    $total = count($files);
    $start = microtime(true);
    $made = 0; $failed = 0; $worked = 0; $read = 0; $unreadable = 0; $i = max(0, (int) $from); $step = max(1, (int) $step);
    $records = []; $unmark = [];   // folderReal => [bare name => record], folderReal => [bare names whose old "read, nothing found" mark goes]
    for (; $i < $total; $i += $step) {
        if ($worked > 0 && microtime(true) - $start > $budget) break;
        $file = $files[$i];
        $src = resolve_real_path($dir, $file);
        if (!$src || !is_file($src)) continue;
        $folderReal = containing_folder_real_path($dir, $file);
        $did = false;
        if (!is_video_file($file)) {
            $cacheDir = $folderReal . '/.gallery-cache';
            $missing = [];
            foreach (array_keys(TILE_SIZES) as $variant) {
                $cachePath = image_cache_path($cacheDir, $file, $variant, $src);
                if ($cachePath === null) { $failed++; continue; }
                if (!$remake && is_file($cachePath) && filemtime($cachePath) >= @filemtime($src)) continue;
                $missing[$variant] = $cachePath;
            }
            if ($missing) {   // one reading of the photo makes every size that is missing
                $did = true;
                if (generate_tile_jpegs($src, $missing)) $made += count($missing); else $failed += count($missing);
            }
        }
        $videoMade = false;
        if (is_video_file($file) && ffmpeg_binary() !== '') {   // a video's poster frame, hover clip and scrub strip: seconds each, so one video per call
            $vs = video_made_state($dir, $file); $paths = video_made_paths($dir, $file); $cacheDir = $folderReal . '/.gallery-cache';
            $need = array_keys(array_filter($vs, function ($st) use ($remake) { return $st === 'missing' || ($remake && ($st === 'ready' || $st === 'failed')); }));
            if ($need && (is_dir($cacheDir) || @mkdir($cacheDir, 0775, true)) && is_writable($cacheDir)) {
                $did = true; $videoMade = true;
                foreach ($need as $k) {
                    if ($remake) { @unlink($paths[$k]); @unlink($paths[$k] . '.failed'); }
                    $res = $k === 'poster' ? video_poster_path($src, $cacheDir, $file) : ($k === 'clip' ? video_preview_path($src, $cacheDir, $file) : video_strip_path($src, $cacheDir, $file));
                    if ($res !== null) $made++; else $failed++;
                }
            }
        }
        $bare = basename($file);
        if (media_meta_enabled() && ($again || !media_meta_ready(media_meta_load($folderReal)[$bare] ?? null, $src))) {
            $did = true;
            $rec = media_meta_read_file($src);
            $records[$folderReal][$bare] = $rec;
            $read++;
            if ($rec['gs'] === 'unreadable') $unreadable++;
            if ($again) $unmark[$folderReal][] = $bare;
        }
        if ($did) $worked++;
        if ($videoMade) { $i += $step; break; }   // (one video per call: the page asks again at once)
    }
    foreach ($records as $folderReal => $recs) {
        media_meta_store($folderReal, $recs);
        if (!empty($unmark[$folderReal])) {
            $names = $unmark[$folderReal];
            items_update($folderReal, function (&$d) use ($names) {
                foreach ($names as $n) {
                    if (isset($d['items'][$n]) && empty($d['items'][$n]['location']) && empty($d['items'][$n]['locationCleared'])) unset($d['items'][$n]['gpsChecked']);
                }
            });
        }
    }
    return ['next' => $i, 'total' => $total, 'made' => $made, 'failed' => $failed, 'worked' => $worked, 'read' => $read, 'unreadable' => $unreadable, 'done' => $i >= $total];
}

/** The backups Tidy up made: every .gallery-items.before-tidy-<date>.php in the folders that hold files, newest last. [['folder', 'file', 'at', 'bytes'], ...] */
function media_tidy_backups($dir, $files) {
    $reals = [];
    foreach ($files as $file) $reals[containing_folder_real_path($dir, $file)] = ($slash = strrpos($file, '/')) === false ? '' : substr($file, 0, $slash);
    $out = [];
    foreach ($reals as $real => $folder) {
        foreach (glob($real . '/.gallery-items.before-tidy-*.php') ?: [] as $f) {
            if (!preg_match('/^\.gallery-items\.before-tidy-\d{8}-\d{6}\.php$/', basename($f)) || !is_file($f) || is_link($f)) continue;
            $out[] = ['folder' => $folder, 'file' => $f, 'at' => (int) @filemtime($f), 'bytes' => (int) @filesize($f)];
        }
    }
    usort($out, function ($a, $b) { return $a['at'] <=> $b['at'] ?: strcmp($a['file'], $b['file']); });
    return $out;
}

/** Removes the Tidy up backups (only files named exactly like them, in the folders that hold files); how many went. */
function media_tidy_backups_delete($dir, $files) {
    $n = 0;
    foreach (media_tidy_backups($dir, $files) as $b) if (@unlink($b['file'])) $n++;
    return $n;
}

/** What a person might want to look at in the media, in groups: [['key', 'title', 'hint', 'actionable', 'count', 'items' => [file, ...]], ...]
 *  (empty groups left out). 'actionable' ones are things to deal with; the others are for information. 'attention' counts the files in
 *  the first kind. */
function media_attention($dir, $files) {
    static $memo = [];
    $key = count($files) . '|' . (string) ($files[0] ?? '') . '|' . (string) ($files[count($files) - 1] ?? '');
    if (isset($memo[$key])) return $memo[$key];
    $g = ['unreadable' => [], 'unread' => [], 'legacy' => [], 'videoFailed' => [], 'videoMissing' => [], 'undated' => [], 'differs' => []];
    $ff = ffmpeg_binary() !== '';
    $byFolder = [];
    foreach ($files as $file) $byFolder[containing_folder_real_path($dir, $file)][] = $file;
    foreach ($byFolder as $folderReal => $list) {
        $items = items_read($folderReal)['items'] ?? [];
        $recs = media_meta_enabled() ? media_meta_load($folderReal) : [];
        foreach ($list as $file) {
            $bare = basename($file); $entry = $items[$bare] ?? [];
            if (media_meta_enabled()) {
                $rec = $recs[$bare] ?? null;
                if (!media_meta_fresh($rec, $folderReal . '/' . $bare)) $g['unread'][] = $file;
                else {
                    if (($rec['gs'] ?? '') === 'unreadable') $g['unreadable'][] = $file;
                    if (($rec['t'] ?? null) === null) $g['undated'][] = $file;
                }
                if (!empty($entry['gpsChecked']) && empty($entry['location']) && empty($entry['locationCleared'])) $g['legacy'][] = $file;
            }
            if (is_video_file($file) && $ff) {
                $vs = video_made_state($dir, $file);
                if (in_array('failed', $vs, true)) $g['videoFailed'][] = $file;
                elseif ($vs['poster'] === 'missing') $g['videoMissing'][] = $file;
            }
        }
    }
    if (media_meta_enabled() && ENABLE_THUMBNAIL_CACHE) {
        foreach (media_tidy_run($dir, $files, false, false)['rows'] as $row) foreach ($row['lists']['edited'] ?? [] as $x) $g['differs'][] = ($row['folder'] === '' ? '' : $row['folder'] . '/') . $x['name'];
    }
    // [title, hint, to deal with (else for information), counted as "needs attention"]: files not read yet and videos not made yet are
    // to be done by the Prepare button, which is routine (every new photo is that until it is first shown), so they are listed but not counted.
    $meta = [
        'unreadable' => ['Files that could not be read', 'The gallery could not get a capture date or location from these (a damaged file, or a kind it cannot read). They still show.', true, true],
        'unread' => ['Files not read yet', 'Prepare the rest reads them.', true, false],
        'legacy' => ['Old "nothing found" marks', 'An earlier version looked at these and found no location; they are left alone. Tidy up and Read everything again deal with them.', true, true],
        'videoFailed' => ['Videos ffmpeg could not make pictures for', 'Not tried again for six hours.', true, true],
        'videoMissing' => ['Videos without a poster frame yet', 'Prepare the rest makes them.', true, false],
        'undated' => ['Files with no capture date', 'They are ordered by when the file was changed. Nothing to fix: screenshots, downloads and some videos carry no date.', false, false],
        'differs' => ['Saved locations that differ from their file', 'Yours, kept as they are. Tidy up would not touch them in its careful mode.', false, false],
    ];
    $groups = []; $attention = 0;
    foreach ($meta as $k => [$title, $hint, $actionable, $counted]) {
        if (!$g[$k]) continue;
        $groups[] = ['key' => $k, 'title' => $title, 'hint' => $hint, 'actionable' => $actionable, 'count' => count($g[$k]), 'items' => array_slice($g[$k], 0, 40)];
        if ($counted) $attention += count($g[$k]);
    }
    return $memo[$key] = ['groups' => $groups, 'attention' => $attention];
}

