function folder_id_read($folderReal) {
    $id = items_read($folderReal)['folderId'] ?? '';
    return is_string($id) && preg_match('/^[0-9a-f]{12}$/', $id) ? $id : '';
}

/** The folder's id, giving it one first if it has none. '' when that can't be written (the folder isn't writable). */
function folder_id_stamp($folderReal) {
    $id = folder_id_read($folderReal);
    if ($id !== '') return $id;
    $new = bin2hex(random_bytes(6));
    $failedBefore = !empty($GLOBALS['__data_write_failed']);
    $GLOBALS['__data_write_failed'] = false;
    items_update($folderReal, function (&$d) use ($new) {
        if (!is_string($d['folderId'] ?? null) || $d['folderId'] === '') $d['folderId'] = $new;
    });
    $failed = !empty($GLOBALS['__data_write_failed']);
    $GLOBALS['__data_write_failed'] = $failedBefore || $failed;
    return $failed ? '' : folder_id_read($folderReal);
}

/** The id of the folder at this relative path ('' is the gallery's own folder), read once per request. */
function folder_id_cached($dir, $rel) {
    static $cache = [];
    if (!isset($cache[$rel])) $cache[$rel] = folder_id_read($rel === '' ? $dir : resolve_real_path($dir, $rel));
    return $cache[$rel];
}

/** Every id found among the folders that hold $files: id => the relative paths that carry it (more than one means a folder was copied). */
function folder_id_map_folders($dir, $files) {
    $map = [];
    foreach (all_folder_paths($files) as $rel) {
        $id = folder_id_cached($dir, $rel);
        if ($id !== '') $map[$id][] = $rel;
    }
    return $map;
}

/** The same, with the collections too: a link names a collection the way it names a folder, by an id, and it is found where it lives. */
function folder_id_map($dir, $files) {
    $map = folder_id_map_folders($dir, $files);
    if (collections_any()) foreach (collections_registry($dir, $files)['byPath'] as $cPath => $cRec) $map[$cRec['id']][] = $cPath;
    return $map;
}

/** The ids that more than one folder carries: they point at nothing until the owner sorts it out. Kept in the links file so the
 *  routes that answer before the tree is scanned know too. */
function link_ambiguous_set($set = null) {
    static $cache = null;
    if ($set !== null) { $cache = $set; return $cache; }
    if ($cache === null) $cache = array_fill_keys(array_filter((array) (links_read()['ambiguous'] ?? []), 'is_string'), true);
    return $cache;
}
function link_scan_ambiguity($dir, $allFiles) {
    $dups = [];
    foreach (folder_id_map($dir, $allFiles) as $id => $paths) if (count($paths) > 1) $dups[$id] = true;
    $stored = array_keys(link_ambiguous_set());
    $now = array_keys($dups); sort($stored); sort($now);
    if ($stored !== $now) links_update(function (&$d) use ($now) { $d['ambiguous'] = $now; });
    link_ambiguous_set($dups);
}

/** True if the item is inside one of the folders with these ids (the folder it is in, or any folder above it). */
function link_item_under_folder_ids($dir, $file, $fids) {
    $set = array_fill_keys(array_filter((array) $fids, 'is_string'), true);
    if (!$set) return false;
    $amb = link_ambiguous_set();
    $parts = explode('/', $file); array_pop($parts);
    $prefix = '';
    foreach ($parts as $seg) {
        $prefix = $prefix === '' ? $seg : $prefix . '/' . $seg;
        $id = folder_id_cached($dir, $prefix);
        if ($id !== '' && isset($set[$id]) && !isset($amb[$id])) return true;
    }
    return false;
}

/** Is the item public (not private)? Without the gallery's item settings to ask, it is not: a link never shows what it can't be sure of. */
function link_item_is_public($dir, $file, $itemsData = null) {
    $data = $itemsData ?? ($GLOBALS['itemsData'] ?? null);
    return is_array($data) && !file_is_hidden($dir, $file, $data);
}

/** How a link's folders cover an item: 'all' (it is under a folder the link shares with everything inside), 'public' (it is under folders
 *  the link shares as "public items only", and under no other), or '' (under none of them). A private folder's items are all private,
 *  so a public-only folder that is itself private covers nothing. */
function link_folder_cover($dir, $file, $fids, $publicOnly = []) {
    $set = array_fill_keys(array_filter((array) $fids, 'is_string'), true);
    if (!$set) return '';
    $pub = array_fill_keys(array_filter((array) $publicOnly, 'is_string'), true);
    $amb = link_ambiguous_set();
    $cover = '';
    foreach (array_keys($set) as $cid) {   // a collection the link names covers the files it holds (as it holds them); the link's own "public items only" applies to it as to a folder
        if ($cid[0] !== 'c' || file_is_unlisted($dir, $file) || !collection_holds_file($dir, $cid, $file)) continue;   // (a Not listed folder's photos come through no collection, as through no folder above it: it has to be picked itself)
        if (!isset($pub[$cid])) return 'all';
        $cover = 'public';
    }
    $parts = explode('/', $file); array_pop($parts);
    $prefix = '';
    $from = max(0, unlisted_root_depth($dir, $file) - 1);   // (a folder picked above a Not listed one does not bring it along; the Not listed folder, or one inside it, has to be picked)
    $n = 0;
    foreach ($parts as $seg) {
        $prefix = $prefix === '' ? $seg : $prefix . '/' . $seg;
        if ($n++ < $from) continue;
        $id = folder_id_cached($dir, $prefix);
        if ($id === '' || !isset($set[$id]) || isset($amb[$id])) continue;
        if (!isset($pub[$id])) return 'all';
        $cover = 'public';
    }
    return $cover;
}

/** May the link $l show this item? */
function link_allows_item($l, $dir, $file, $itemsData = null) {
    $type = $l['scope']['type'] ?? '';
    if ($type === 'all') return !file_is_unlisted($dir, $file);   // ("Everything" leaves out a Not listed folder: that one is shared only by picking it)
    if ($type === 'folders' || $type === 'mixed') {
        $cover = link_folder_cover($dir, $file, $l['scope']['folders'] ?? [], $l['scope']['publicOnly'] ?? []);
        if ($cover === 'all') return true;
        if ($cover === 'public' && link_item_is_public($dir, $file, $itemsData)) return true;   // ("public items only": the private ones are left out, unless one is named below)
        if ($type === 'folders') return false;
    }
    if ($type === 'photos' || $type === 'mixed') {   // ("some folders and some photos": under a picked folder, or a picked photo)
        $set = [];
        foreach ((array) ($l['scope']['photos'] ?? []) as $p) if (is_array($p) && is_string($p['fid'] ?? null) && is_string($p['name'] ?? null)) $set[$p['fid'] . "\0" . $p['name']] = true;
        if (!$set) return false;
        $slash = strrpos($file, '/');
        $fid = folder_id_cached($dir, $slash === false ? '' : substr($file, 0, $slash));
        return $fid !== '' && !isset(link_ambiguous_set()[$fid]) && isset($set[$fid . "\0" . basename($file)]);
    }
    return false;
}

/** Is this item one the link names as a photo (not merely one inside a folder it shares)? Those are shown as loose tiles, not inside a folder card. */
function link_item_is_picked_photo($l, $dir, $file, $itemsData = null) {
    $type = $l['scope']['type'] ?? '';
    if ($type !== 'photos' && $type !== 'mixed') return false;
    if ($type === 'mixed') {   // (inside a folder the link shares, it is part of that folder; named as well as private inside a "public items only" folder, it is a tile of its own)
        $cover = link_folder_cover($dir, $file, $l['scope']['folders'] ?? [], $l['scope']['publicOnly'] ?? []);
        if ($cover === 'all' || ($cover === 'public' && link_item_is_public($dir, $file, $itemsData))) return false;
    }
    return link_allows_item($l, $dir, $file, $itemsData);
}

/** The topmost Not listed folder $file sits in ('' when it is in none): the one whose own choices (downloads) cover everything beneath it. */
function unlisted_root_path($dir, $file) {
    $depth = unlisted_root_depth($dir, $file);
    return $depth > 0 ? implode('/', array_slice(explode('/', dirname($file)), 0, $depth)) : '';
}

/** The eight characters (A-Z, a-z, 0-9, none left out) of a photo's address, from a secret raw string: bytes below 248 are used, each mapped to one of the
 *  62 characters without favouring any (248 is four times 62). */
function photo_code_chars($raw) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $out = ''; $round = 0;
    while (strlen($out) < 8) {
        for ($i = 0, $len = strlen($raw); $i < $len && strlen($out) < 8; $i++) {
            $b = ord($raw[$i]);
            if ($b < 248) $out .= $alphabet[$b % 62];
        }
        if (strlen($out) < 8) $raw = hash('sha256', $raw . $round++, true);   // (not reached with 32 bytes of hash in practice, but it can't loop for ever)
    }
    return $out;
}

/** A photo's address when the photo is in a Not listed folder: eight letters and digits (for example Ab3x9Qz2, used as /photos/Ab3x9Qz2 or
 *  /photos/?Ab3x9Qz2) worked out from the folder's hidden id, the file name, a secret of this install and, once the owner has asked for a new address,
 *  a random string kept on the photo. Nothing else is stored, so there is no list of codes and nothing to press: the folder can be renamed or moved
 *  and its photos keep their addresses, while renaming the photo ends its address. '' when it can't be worked out (no secret yet, or the folder has
 *  no id yet, which switching Not listed on gives it): nothing then matches. */
function photo_address_code($dir, $file, $itemsData = null, $folderId = null) {
    $secret = install_secret();
    $slash = strrpos((string) $file, '/');
    if ($secret === '' || $slash === false) return '';
    $fid = $folderId ?? folder_id_cached($dir, substr($file, 0, $slash));
    if ($fid === '') return '';
    $salt = is_array($itemsData) && is_string($itemsData[$file]['addr'] ?? null) ? $itemsData[$file]['addr'] : '';
    return photo_code_chars(hash_hmac('sha256', 'photo|' . $fid . '|' . basename($file) . '|' . $salt, $secret, true));
}

/** The photo an address (eight letters and digits) belongs to, or null. Only photos in Not listed folders have one, so only those are worked out
 *  (about a microsecond each), and a folder whose id is carried by a copy of it is left out until that is sorted. Nothing is read for an address of
 *  the wrong shape. */
function photo_address_resolve($dir, $files, $itemsData, $token) {
    if (!is_string($token) || preg_match('/^[A-Za-z0-9]{8}$/D', $token) !== 1 || install_secret() === '') return null;
    link_scan_ambiguity($dir, $files);   // (looking an address up keeps the list of copied ids current: the pictures of the page this opens are refused or served by it)
    $amb = link_ambiguous_set();
    $folderId = []; $carriers = [];   // folder => its id (only a Not listed folder with one), and id => how many such folders carry it
    foreach ($files as $f) {
        $folder = dirname($f);
        if ($folder === '.' || $folder === '' || isset($folderId[$folder])) continue;
        $fid = folder_is_unlisted($dir, $folder) ? folder_id_cached($dir, $folder) : '';   // (only a Not listed folder's id is read)
        $folderId[$folder] = $fid;
        if ($fid !== '') $carriers[$fid] = ($carriers[$fid] ?? 0) + 1;
    }
    foreach ($files as $f) {
        $fid = $folderId[dirname($f)] ?? '';
        if ($fid === '' || ($carriers[$fid] ?? 0) !== 1 || isset($amb[$fid])) continue;   // (a folder copied with its id points at nothing until that is sorted)
        $want = photo_address_code($dir, $f, $itemsData, $fid);
        if ($want !== '' && hash_equals($want, $token)) return $f;
    }
    return null;
}

/** A request for /photos/<something> (or /photos/?<something>) reaches the script as ?link=<something>. When that something is a Not listed photo's
 *  address, the request becomes the photo's own page (?photo=<path>&k=<code>) and true is returned; otherwise nothing is changed and the request goes
 *  on as a share link's (where a wrong one is counted). A closed gallery shows a stranger no photo, by an address or otherwise, and a visitor held
 *  back for guessing gets no answer, a right one included. */
function photo_address_translate($dir, $files, $itemsData) {
    $token = $_GET['link'] ?? null;
    if (!is_string($token) || preg_match('/^[A-Za-z0-9]{8}$/D', $token) !== 1) return false;
    if (gallery_is_closed() && !is_revealed() && !is_admin_browser() && link_viewer() === null) return false;
    if (!is_admin_browser() && !is_revealed() && !login_throttle_allows($dir, 'link', client_ip())) return false;
    if (is_dir($dir . '/' . $token) || isset(gallery_mounts()[$token])) return false;   // (a real folder of that name is what the server would have shown)
    $rel = photo_address_resolve($dir, $files, $itemsData, $token);
    if ($rel === null) return false;
    unset($_GET['link']);
    // The folder says what its photos' addresses open: the photo in the viewer (the default), or the bare picture or video. A ?file or ?view added
    // to one address overrides that for that address only.
    $bare = !empty(items_read(resolve_real_path($dir, unlisted_root_path($dir, $rel)))['addressFile']);
    if (isset($_GET['view'])) $bare = false; elseif (isset($_GET['file'])) $bare = true;
    unset($_GET['file'], $_GET['view']);
    $_GET['k'] = $token;
    if ($bare) {
        $_GET[is_video_file($rel) ? 'video' : 'img'] = $rel; if (!is_video_file($rel)) $_GET['size'] = 'full';
        request_route(true);
        photo_address_bare($dir, $rel, $itemsData);
        exit;
    }
    $_GET['photo'] = $rel;
    request_route(true);
    return true;
}

/** A Not listed photo's address answered with the file itself: what the viewer shows at full size (a picture is the file with its location removed for a
 *  visitor, a HEIC photo its converted JPEG) or, for a video, the video, with no page around it. It is counted and logged as the page would be. */
function photo_address_bare($dir, $rel, $itemsData) {
    header('X-Robots-Tag: noindex, nofollow');
    header('Referrer-Policy: no-referrer');
    try {
        if (!is_non_viewing_request()) {
            if (TRACK_VIEWS && !is_bot_request() && !is_admin_browser() && link_viewer() === null && !view_debounce_recently_viewed($rel)) stats_bump_photo(containing_folder_real_path($dir, $rel), $rel);
            if (public_log_wanted() && ($pl = public_log_link()) !== null) {
                $site = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
                $how = $site === 'none' ? 'typed' : (in_array($site, ['cross-site', 'same-site'], true) ? 'link' : 'other');
                [$vid, $ip, $label, $country] = link_visitor_identity();
                link_log_apply($pl, $vid, $ip, $label, $country, [['o', $rel]], null, ['f' => $rel, 'h' => $how, 'u' => 1]);
            }
        }
    } catch (Throwable $e) { error_log('gallery: counting or logging a bare-file open failed: ' . $e->getMessage()); }   // (it must never stop the picture)
    $claimed = [$rel]; $items = items_get_all_for_files($dir, $claimed);
    if (isset($_GET['video'])) serve_video($dir, $rel, $claimed, $items); else serve_image($dir, $rel, 'full', $claimed, $items);
}

/** Is the k= of this request the right code for the photo the request names? Asked when a request ends without having been let in by its code, to tell
 *  a wrong guess (counted) from a right code that was turned away for another reason (a download that is switched off, a video strip of a photo). The
 *  name is only ever hashed and looked up inside the gallery's folders: anything with a dot-dot, a backslash or a NUL is not asked about. */
function photo_code_is_right_for_request($dir) {
    $k = $_GET['k'] ?? null;
    if (!is_string($k) || $k === '') return false;
    foreach (['photo', 'img', 'original', 'track', 'strip', 'video'] as $param) {
        $f = $_GET[$param] ?? null;
        if (!is_string($f) || $f === '' || $f[0] === '/' || strpbrk($f, "\0\\") !== false || preg_match('#(^|/)\.\.?(/|$)#', $f) === 1) continue;
        $want = photo_address_code($dir, $f, items_get_all_for_files($dir, [$f]));
        if ($want !== '' && hash_equals($want, $k)) return true;
    }
    return false;
}

/** Does this request carry the right code for this very photo, and is the photo one that has an address (in a Not listed folder, and not
 *  marked private on its own)? The code is for that one photo: it opens nothing else. */
function photo_pass_valid($dir, $file, $itemsData) {
    static $held = null;
    $k = $_GET['k'] ?? null;
    if (!is_string($k) || $k === '' || strlen($k) > 40 || !is_string($file) || !file_is_unlisted($dir, $file)) return false;
    // The code is for the photo the request names (as ?photo=, ?img=, ?original=, ?track=, ?strip= or ?video=), not for that photo wherever it might turn up on another page.
    $named = false;
    foreach (['photo', 'img', 'original', 'track', 'strip', 'video'] as $param) if (($_GET[$param] ?? null) === $file) { $named = true; break; }
    if (!$named) return false;
    if (item_restriction(is_array($itemsData) ? ($itemsData[$file] ?? null) : null) === 'private') return false;
    $fid = folder_id_cached($dir, (string) dirname($file));
    if ($fid !== '' && isset(link_ambiguous_set()[$fid])) return false;   // (a folder copied with its id: its photos' addresses open nothing until that is sorted, as at /photos/<code>)
    // The code can be tried here as well as at /photos/<code> (any request that names a Not listed photo and carries one), so a visitor held back for
    // guessing is not asked at all. (A wrong code is counted at the end of the request, see 003, whether or not the photo it came with exists.)
    if ($held === null) $held = !is_admin_browser() && !is_revealed() && !login_throttle_allows($dir, 'link', client_ip());
    if ($held) return false;
    $want = photo_address_code($dir, $file, $itemsData);
    if ($want === '' || !hash_equals($want, $k)) return false;
    $GLOBALS['__photo_pass_ok'] = true;
    return true;
}

/** May this visitor see the item (a public one, or a private one they were given)? */
function viewer_may_see_item($dir, $file, $itemsData) {
    if (is_admin_browser()) return true;
    if (isset($_GET['k']) && photo_pass_valid($dir, $file, $itemsData)) return true;   // (a Not listed photo, by its own address)
    if (is_revealed()) return !file_is_unlisted($dir, $file);   // (what the private-view password opens is everything private except a Not listed folder, which is the owner's alone)
    $l = link_viewer();
    if ($l !== null && link_shows_only_its_own($l) && !link_allows_item($l, $dir, $file, $itemsData)) return false;   // a link that shows only its own things: not the public rest of the gallery either
    if (!file_is_hidden($dir, $file, $itemsData)) return true;
    return $l !== null && link_allows_item($l, $dir, $file, $itemsData);
}

/** Is this link set to show only what it covers (its photos or folders), the public rest of the gallery left out of the pages it shows? */
function link_shows_only_its_own($l) {
    return !empty($l['only']) && in_array($l['scope']['type'] ?? 'all', ['folders', 'photos', 'mixed'], true);
}

/** Does the link allow $cap ('downloads' or 'zip') for this item? The answer is one of: off; all (every item the link shows, public or
 *  private); public (every item that is not private); some (the items under the chosen folders). */
function link_capability_allows($l, $cap, $dir, $file, $itemsData = null) {
    $mode = $l[$cap]['mode'] ?? 'off';
    if ($mode === 'all') return true;
    if ($mode === 'public') return !file_is_hidden($dir, $file, $itemsData ?? ($GLOBALS['itemsData'] ?? []));
    if ($mode === 'some') return link_item_under_folder_ids($dir, $file, $l[$cap]['folders'] ?? []);
    return false;
}

/** May this visitor download the original of an item they can see? The owner and the private-view password: always. Everyone
 *  else: a public photo when the gallery lets visitors, a private one when their link says so. */
function viewer_may_download($dir, $file, $itemsData) {
    if (is_admin_browser()) return true;
    if (isset($_GET['k']) && photo_pass_valid($dir, $file, $itemsData)) return !empty(items_read(resolve_real_path($dir, unlisted_root_path($dir, $file)))['allowOriginals']);   // (a photo opened by its address: its Not listed folder's own choice, not the gallery's)
    if (is_revealed()) return !file_is_unlisted($dir, $file);
    $l = link_viewer();
    // Someone using a share link: the link's own answer decides (the gallery-wide switch is for people who came in without one).
    if ($l !== null) return (!file_is_hidden($dir, $file, $itemsData) || link_allows_item($l, $dir, $file, $itemsData)) && link_capability_allows($l, 'downloads', $dir, $file, $itemsData);
    if (!ENABLE_PUBLIC_DOWNLOAD || file_is_hidden($dir, $file, $itemsData)) return false;
    return public_download_folder_covers($file);
}

/** Does the folder the file lies directly in allow strangers to download? Each folder stands on its own: a photo in a folder inside a folder that
 *  allows it is not covered by that one. A photo on the gallery's main page is the main page's own switch. */
function public_download_folder_covers($file) {
    $slash = strrpos($file, '/');
    if ($slash === false) return PUBLIC_DOWNLOAD_ROOT;
    return in_array(substr($file, 0, $slash), PUBLIC_DOWNLOAD_FOLDERS, true);
}

/** Does this visitor see every saved location (the owner, the private-view password, or a link that says so)? */
function viewer_sees_all_locations() {
    if (is_revealed()) return true;
    $l = link_viewer();
    return $l !== null && ($l['locations'] ?? 'public') === 'all';
}

/** The gallery's own title, found without the page scan the pages themselves wait for. */
function gallery_title_early($dir) {
    $label = root_display_meta($dir)['label'];
    if ($label !== '') return $label;
    $scan = scan_gallery_md_files($dir);
    if (!empty($scan['files'])) {
        foreach (explode("\n", str_replace("\r\n", "\n", (string) $scan['contents'][$scan['files'][0]])) as $line) {
            if (trim($line) === '') continue;
            if (preg_match('/^#\s+(.+)$/', trim($line), $m)) return unprotect_inline_code(trim($m[1]));
            break;
        }
    }
    return basename($dir);
}

/** The title a link's opening page (and a chat app's preview of it) shows. */
function link_page_title($l, $dir) {
    $mode = $l['titleMode'] ?? 'gallery';
    if ($mode === 'neutral') {   // one of a few plain titles, the same one every time for a given link (a chat app keeps the title it first saw)
        $titles = ['A shared gallery', 'Some photos for you', 'A few photos to look at', 'Shared with you', 'A little gallery', 'Something to look at', 'Photos, shared', 'A collection of photos', 'Take a look', 'Photos for you'];
        $seed = (string) (($l['pictureSeed'] ?? '') !== '' ? $l['pictureSeed'] : (($l['token'] ?? '') !== '' ? $l['token'] : ($l['id'] ?? '')));
        return $titles[hexdec(substr(hash('sha256', 'title' . $seed), 0, 8)) % count($titles)];
    }
    if ($mode === 'custom' && trim((string) ($l['titleText'] ?? '')) !== '') return (string) $l['titleText'];
    return gallery_title_early($dir);
}

/** The picture a link's message preview and opening page show, when the link has its picture switched on: the gallery's own cover if
 *  it has one, else a cover from one of the PUBLIC folders (a public photo from the front page when there are no folders). It is
 *  worked out as a signed-out visitor would see the gallery, whoever is asking, so a private photo can never be chosen; the same one
 *  every time for a given link. A relative file path, or null (switched off, or nothing public to show). */
/** Is this photo one a link with this scope covers, as far as a preview picture goes? A link to everything or to the public gallery covers every photo
 *  (they are filtered to the public ones elsewhere); a link limited to folders and/or photos covers only those. $draft is the form's unsaved choice
 *  as paths (type, folders, photos); without it the saved link's own record decides. */
function link_cover_in_scope($l, $dir, $f, $draft = null) {
    $type = $draft !== null ? (string) ($draft['type'] ?? 'all') : (string) ($l['scope']['type'] ?? 'all');
    if (!in_array($type, ['folders', 'photos', 'mixed'], true)) return true;
    if ($draft === null) return link_allows_item($l, $dir, $f);
    if ($type !== 'photos') foreach ((array) ($draft['folders'] ?? []) as $d) {
        if ($d !== '' && str_starts_with($f, $d . '/')) return true;
        if ($d !== '' && ($c = collection_at($dir, $d)) !== null && in_array($f, collection_member_files($dir, $c, $GLOBALS['allFiles'] ?? ($GLOBALS['files'] ?? [])), true)) return true;
    }
    return $type !== 'folders' && in_array($f, (array) ($draft['photos'] ?? []), true);
}

/** The form's unsaved choice of what a link covers, from a request's scope_type / scope_folders / scope_photos (null when they are not sent). */
function link_draft_scope_from_request() {
    if (!isset($_GET['scope_type']) || !is_string($_GET['scope_type'])) return null;
    $list = function ($k) { $v = json_decode(is_string($_GET[$k] ?? null) ? $_GET[$k] : '[]', true); return is_array($v) ? array_values(array_filter($v, 'is_string')) : []; };
    return ['type' => $_GET['scope_type'], 'folders' => $list('scope_folders'), 'photos' => $list('scope_photos')];
}

function link_preview_picture($l, $dir, $draft = null) {
    if (empty($l['picture'])) return null;
    [, $public] = link_public_media($dir);
    $photo = function ($f) { return !is_video_file($f); };
    // A link limited to some folders and/or photos takes its picture, chosen or automatic, only from the public photos it covers: the opening page
    // and a chat's preview show it to anyone before the link is opened, so a photo the link does not cover must not appear there. A link whose
    // picks hold no public photo shows the gallery's icon.
    $public = array_values(array_filter($public, function ($f) use ($l, $dir, $draft) { return link_cover_in_scope($l, $dir, $f, $draft); }));
    // The owner's own choice, looked at again every time it is used: a photo that has since gone private, been deleted or fallen outside what the link covers is not in the list, and the automatic pick takes over.
    $chosen = (string) ($l['pictureFile'] ?? '');
    if ($chosen !== '' && $photo($chosen) && in_array($chosen, $public, true)) return $chosen;
    $rootCover = root_display_meta($dir)['cover'];
    if ($rootCover !== null && in_array($rootCover, $public, true) && $photo($rootCover)) return $rootCover;
    $level = split_folder_level($public, '');
    $pool = [];
    foreach ($level['folders'] as $top => $members) {
        $cover = pick_folder_cover($members, folder_display_meta($dir, $top)['cover'], $top);
        if ($cover !== null && $photo($cover)) $pool[] = $cover;
    }
    if (!$pool) $pool = array_values(array_filter($public, $photo));
    if (!$pool) return null;
    $seed = (string) (($l['pictureSeed'] ?? '') !== '' ? $l['pictureSeed'] : ($l['token'] ?? ''));   // (a link made before the seed existed keeps the pick its address gave it)
    return $pool[hexdec(substr(hash('sha256', $seed), 0, 8)) % count($pool)];
}

/** Every photo and video of the gallery in its own order, and the ones a signed-out visitor could see: [all, public]. Worked out once a request. */
function link_public_media($dir) {
    global $mediaExtensions, $self;
    static $cache = [];
    if (isset($cache[$dir])) return $cache[$dir];
    if (AUTO_GROUP_BY_FOLDER) {
        $all = flatten_folder_tree(merge_extra_folders(scan_folder_tree($dir, $mediaExtensions, $self), $mediaExtensions, $self));
    } else {
        $all = [];
        foreach ((array) @scandir($dir) as $f) {
            if ($f === $self || $f === '.' || $f === '..' || !is_file("$dir/$f")) continue;
            if (in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), $mediaExtensions, true)) $all[] = $f;
        }
        usort($all, 'strnatcasecmp');
    }
    $all = array_values(media_sort_apply($all, $dir));
    $itemsData = items_get_all_for_files($dir, $all);
    $public = array_values(array_filter($all, function ($f) use ($dir, $itemsData) { return !file_is_hidden($dir, $f, $itemsData); }));
    return $cache[$dir] = [$all, $public];
}

/** May this file be a link's cover? Only a public photo (never a private one, never a video). */
function link_picture_ok($rel, $dir) {
    [, $public] = link_public_media($dir);
    return $rel !== '' && !is_video_file($rel) && in_array($rel, $public, true);
}

/** A folder's last known path (from the links file), if the folder is still there carrying that id. */
function link_folder_path_hint($fid) {
    $rel = (string) (links_read()['folderIndex'][$fid] ?? '');
    if ($rel === '') return null;
    $real = resolve_real_path(__DIR__, $rel);
    return is_dir($real) && folder_id_read($real) === $fid ? $rel : null;
}

/** Where pressing "Open the gallery" leads: the front page (for a link to photos, the grid of them; for exactly one photo, the viewer on it) or the folder the link opens in. */
function link_open_in_href($l) {
    $root = gallery_root_href();
    if (($l['scope']['type'] ?? '') === 'photos') {
        // One photo opens straight in the viewer; several open on the grid of them.
        $picked = (array) ($l['scope']['photos'] ?? []);
        $p = count($picked) === 1 ? reset($picked) : null;
        if (is_array($p) && is_string($p['fid'] ?? null) && is_string($p['name'] ?? null)) {
            $rel = $p['fid'] === folder_id_read(__DIR__) ? '' : link_folder_path_hint($p['fid']);
            if ($rel !== null) {
                $file = ($rel === '' ? '' : $rel . '/') . $p['name'];
                if (is_file(resolve_real_path(__DIR__, $file))) return $root . '?photo=' . rawurlencode($file);
            }
        }
        return $root;
    }
    $in = (string) ($l['openIn'] ?? '');
    if ($in !== '') {
        $rel = link_folder_path_hint($in);
        if ($rel !== null) return $root . '?folder=' . rawurlencode($rel);
    }
    return $root;
}

/** A link's addresses: the tidy one (needs the server rule) and the one that always works. */
function link_urls($l) {
    $base = current_base_url();
    return ['short' => $base . '/' . $l['token'], 'long' => $base . '/?' . $l['token']];   // (?link=<token> works too)
}

function link_standalone_head($title, $picture = '') {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'none'; " . ($picture !== '' ? "img-src 'self'; " : '') . "style-src 'unsafe-inline'; script-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
    $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $og = $picture === '' ? '' : '<meta property="og:image" content="' . htmlspecialchars(current_base_url() . '/' . img_url($picture, 'share'), ENT_QUOTES, 'UTF-8') . '"><meta name="twitter:card" content="summary_large_image">';
    return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow"><meta name="referrer" content="no-referrer"><title>' . $t . '</title>'
        . '<meta property="og:title" content="' . $t . '"><meta property="og:type" content="website">' . $og
        . '<style>:root{color-scheme:dark}html,body{height:100%}body{margin:0;background:#0f0f10;color:#eaeaea;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;line-height:1.5}'
        . '.wrap{box-sizing:border-box;min-height:100%;max-width:420px;margin:0 auto;padding:6vh 24px 24px;text-align:center}'
        . '.ico{width:64px;height:64px;border-radius:50%;background:#1a1a1c;display:inline-flex;align-items:center;justify-content:center;color:#aab2ff;margin-bottom:6px}.ico svg{width:30px;height:30px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}'
        . 'h1{font-size:1.5rem;margin:14px 0 10px}p{color:#b9b9c0;font-size:1rem;margin:0 0 20px}'
        . '.note{margin:6px 0 22px;padding:12px 14px;border-left:3px solid #5865f2;background:#141416;border-radius:0 8px 8px 0;font-size:1rem;line-height:1.45;color:#e4e4e8;text-align:left;white-space:pre-wrap;overflow-wrap:anywhere}'
        . '.big{display:block;width:100%;box-sizing:border-box;height:50px;border:0;border-radius:12px;background:#5865f2;color:#fff;font:inherit;font-size:1.05rem;font-weight:600;cursor:pointer}.big:focus-visible{outline:2px solid #fff;outline-offset:2px}'
        . '.pic{display:block;width:auto;height:auto;max-width:100%;max-height:60vh;margin:0 auto 18px;border-radius:14px;background:#1a1a1c}.wrap.has-pic{padding-top:6vh}'   // (the whole photo at its own proportions: as wide as the page allows and no taller than 60% of the screen, never cropped)
        . '.small{font-size:.8rem;color:#6f6f77;margin-top:14px}</style></head><body><div class="wrap' . ($picture !== '' ? ' has-pic' : '') . '">';
}


/** The page behind a link that doesn't work: the same for every reason (expired, used up, paused, revoked, mistyped,
 *  switched off), with nothing to press and nothing about why. The person using it is meant to tell whoever sent it. */
function render_link_invalid_page($status = 404) {
    http_response_code($status);
    header('X-Gallery-Link: invalid');
    echo link_standalone_head('This link is not valid');
    echo '<div class="ico" style="color:#8a8a8f">' . LINK_ICON_WARN . '</div><h1>This link is not valid</h1><p>Please contact the person who gave it to you.</p></div></body></html>';
}

/** The page a working link opens: its title, an optional note, and the one button that counts as using it. */
function render_link_open_page($l, $dir) {
    header('X-Gallery-Link: open');
    $picture = link_preview_picture($l, $dir);
    echo link_standalone_head(link_page_title($l, $dir), (string) $picture);
    echo ($picture !== null ? '<img class="pic" src="' . htmlspecialchars(gallery_root_href() . img_url($picture, 'share'), ENT_QUOTES, 'UTF-8') . '" alt="">' : '<div class="ico">' . LINK_ICON_GALLERY . '</div>') . '<h1>' . htmlspecialchars(link_page_title($l, $dir), ENT_QUOTES, 'UTF-8') . '</h1>';
    if (trim((string) ($l['note'] ?? '')) !== '') echo '<div class="note">' . htmlspecialchars((string) $l['note'], ENT_QUOTES, 'UTF-8') . '</div>';
    echo '<form method="post" action=""><input type="hidden" name="go" value="1"><button type="submit" class="big">Open the gallery</button></form>';
    if ((int) ($l['endsAt'] ?? 0) > 0) {
        $ts = (int) $l['endsAt'];
        echo '<div class="small">This link works until <span id="until" data-ts="' . $ts . '">' . htmlspecialchars(gmdate('D, M j, Y g:i a', $ts) . ' UTC', ENT_QUOTES, 'UTF-8') . '</span>.</div>'
           . '<script>(function(){var s=document.getElementById("until");var d=new Date(+s.getAttribute("data-ts")*1000);if(isNaN(d))return;'
           . 's.textContent=d.toLocaleDateString(undefined,{weekday:"long",month:"short",day:"numeric"})+" at "+d.toLocaleTimeString(undefined,{hour:"numeric",minute:"2-digit"});})();</script>';
    }
    echo '</div></body></html>';
}

/** The page for a visitor held back for guessing addresses: wait a minute and try again. A right address gets the same until the minute is over (or
 *  nothing would slow a guesser down). */
function render_link_locked_page() {
    http_response_code(429);
    header('Retry-After: 60');
    echo link_standalone_head('Please wait a moment');
    echo '<div class="ico" style="color:#8a8a8f">' . LINK_ICON_WARN . '</div><h1>Please wait a moment</h1><p>Too many addresses that did not work were tried from your connection. Wait a minute and try again.</p></div></body></html>';
}

/** Is this visitor held back for guessing? (You, and anyone with the private-view password, never are.) Says so with the page when they are. */
function link_guess_locked($dir) {
    if (is_admin_browser() || is_revealed() || login_throttle_allows($dir, 'link', client_ip())) return false;
    render_link_locked_page();
    return true;
}

/** A wrong address was tried. It counts against the visitor only when it is shaped like one (4 to 40 letters, digits and hyphens): that is what a
 *  guess looks like; a stray word or a mistyped bit of a web address is not held against anyone. You and the private-view password never count. */
function link_guess_failed($dir, $token) {
    if (is_admin_browser() || is_revealed() || !link_token_format_ok($token)) return;
    login_throttle_record_failure($dir, 'link', client_ip(), LINK_GUESS_LIMIT);
}

/** ?link=<token> (and the tidy address the server rule maps onto it). */
function run_link_route($dir) {
    geo_maybe_refresh();
    $token = $_GET['link'] ?? '';
    // The server rule for tidy addresses sends every one-word path here (/gallery/carol), so a few of them are not links: the
    // sign-in ("admin" or "reveal") and a real folder typed without its closing slash (sent on to the folder, as the server itself would have).
    // A link of that name wins over both.
    if (is_string($token) && preg_match('/^[A-Za-z0-9][A-Za-z0-9-]*$/', $token) === 1 && $token !== LINK_PROBE_TOKEN) {
        $isLink = false;
        if (ENABLE_SHARE_LINKS && link_token_format_ok($token)) { $all = links_read(); $isLink = link_find_by_token($all, $token) !== null || link_find_by_alias($all, $token) !== null; }
        if (!$isLink && in_array(strtolower($token), ['admin', 'reveal'], true) && !is_dir($dir . '/' . $token)) sign_in_redirect();
        $mayLook = !gallery_is_closed() || is_revealed() || is_admin_browser() || link_viewer() !== null;   // (a closed gallery doesn't tell a stranger which folders exist)
        if ($mayLook && !is_admin_browser() && (is_dir($dir . '/' . $token) || isset(gallery_mounts()[$token])) && folder_is_unlisted($dir, $token)) $mayLook = false;   // (nor does it say a Not listed folder is there: it answers as for a name that isn't)
        if (!$isLink && $mayLook && (is_dir($dir . '/' . $token) || isset(gallery_mounts()[$token]))) {
            header('Cache-Control: no-store');
            header('Location: ' . gallery_root_href() . rawurlencode($token) . '/', true, 302);
            return;
        }
    }
    if ($token === LINK_PROBE_TOKEN) { render_link_invalid_page(200); return; }   // the check that the tidy address works (the admin's, and the owner's Copy address on a photo): not a guess, and not a 404, which a browser would log in its console. Before the switches below: a Not listed photo's short address needs the server rule whether or not share links are on
    if (!ENABLE_SHARE_LINKS) { if (link_guess_locked($dir)) return; link_guess_failed($dir, $token); render_link_invalid_page(); return; }   // (still counted: a Not listed photo's address is guessed here too)
    if (!is_https_request() && !is_local_request()) { render_https_required_page(); return; }
    $ip = client_ip();
    $allLinks = links_read();
    $link = link_token_format_ok($token) ? link_find_by_token($allLinks, $token) : null;
    $viaAlias = null;   // (an old address the owner kept working: it sends people on to the link's present one)
    if ($link === null && link_token_format_ok($token) && ($hit = link_find_by_alias($allLinks, $token)) !== null) [$link, $viaAlias] = $hit;
    // The owner, signed in, just goes where the link goes: nothing is counted or logged, no cookie is set, and the throttle
    // (which is for guessing) doesn't apply.
    if ($link !== null && is_admin_browser()) {
        header('Cache-Control: no-store');
        header('Location: ' . link_open_in_href($link), true, 303);
        return;
    }
    if (link_guess_locked($dir)) return;
    if ($link !== null && $viaAlias !== null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && link_state($link) === 'active') {
        // (a temporary redirect, never a permanent one: it must stop the moment the owner removes the old address; and the same form the person used, short or long)
        link_alias_used($link['id'], $viaAlias);
        link_note_address($link, 'via', $viaAlias);
        header('Cache-Control: no-store');
        header('Location: ' . link_urls($link)[strpos((string) ($_SERVER['REQUEST_URI'] ?? ''), '?') !== false ? 'long' : 'short'], true, 302);
        return;
    }
    if ($link === null) {
        // An address from before a link was given a new one: not a guess, so not held against the visitor, but noted in that link's log.
        $oldOwner = link_token_format_ok($token) ? link_find_by_old_token(links_read(), $token) : null;
        if ($oldOwner !== null) { link_note_blocked($oldOwner, 'replaced'); link_note_address($oldOwner, 'tried', link_old_name($oldOwner, $token)); render_link_invalid_page(); return; }
        link_guess_failed($dir, $token);
        render_link_invalid_page();
        return;
    }
    $cookie = $_COOKIE[LINK_COOKIE_NAME] ?? '';
    install_secret(true);
    $hasCookie = is_string($cookie) && hash_equals(link_cookie_value($link), $cookie) && link_is_live($link);
    $posted = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['go']);
    if (!$posted) {
        if ($hasCookie) { header('Cache-Control: no-store'); header('Location: ' . link_open_in_href($link), true, 303); return; }
        if (link_state($link) === 'active') { render_link_open_page($link, $dir); return; }
        link_note_blocked($link, link_state($link));
        render_link_invalid_page();
        return;
    }
    // The button was pressed: count the use, atomically, so a link for one opening can't be opened twice at the same moment.
    $outcome = 'blocked'; $stateNow = 'revoked'; $now = time(); $counted = false;
    links_update(function (&$d) use ($link, $ip, $hasCookie, $now, &$outcome, &$stateNow, &$counted) {
        $l = $d['links'][$link['id']] ?? null;
        if (!is_array($l)) return;
        $stateNow = link_state($l, $now);
        if (!link_is_live($l, $now)) return;
        if ($hasCookie) { $outcome = 'ok'; return; }
        if (($l['lastOpen']['ip'] ?? '') === $ip && $now - (int) ($l['lastOpen']['at'] ?? 0) < LINK_REOPEN_GRACE_SECONDS) {
            $d['links'][$link['id']]['lastOpen']['at'] = $now;
            $outcome = 'ok';
            return;
        }
        if ($stateNow !== 'active') return;   // no opens left
        $d['links'][$link['id']]['opens'] = (int) ($l['opens'] ?? 0) + 1;
        $d['links'][$link['id']]['lastOpened'] = $now;
        $d['links'][$link['id']]['lastOpen'] = ['ip' => $ip, 'at' => $now];
        $outcome = 'ok'; $counted = true;
    });
    if ($outcome !== 'ok') {
        link_note_blocked($link, $stateNow);
        render_link_invalid_page();
        return;
    }
    link_set_cookie($link);
    if ($counted) link_log_opened($link);
    header('Cache-Control: no-store');
    header('Location: ' . link_open_in_href($link), true, 303);
}


