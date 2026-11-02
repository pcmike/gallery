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
function folder_id_map($dir, $files) {
    $map = [];
    foreach (all_folder_paths($files) as $rel) {
        $id = folder_id_cached($dir, $rel);
        if ($id !== '') $map[$id][] = $rel;
    }
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

/** May the link $l show this item? */
function link_allows_item($l, $dir, $file) {
    $type = $l['scope']['type'] ?? '';
    if ($type === 'all') return true;
    if ($type === 'folders') return link_item_under_folder_ids($dir, $file, $l['scope']['folders'] ?? []);
    if ($type === 'photos' || $type === 'mixed') {
        if ($type === 'mixed' && link_item_under_folder_ids($dir, $file, $l['scope']['folders'] ?? [])) return true;   // ("some folders and some photos": under a picked folder, or a picked photo)
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
function link_item_is_picked_photo($l, $dir, $file) {
    $type = $l['scope']['type'] ?? '';
    if ($type !== 'photos' && $type !== 'mixed') return false;
    if ($type === 'mixed' && link_item_under_folder_ids($dir, $file, $l['scope']['folders'] ?? [])) return false;
    return link_allows_item($l, $dir, $file);
}

/** May this visitor see the item (a public one, or a private one they were given)? */
function viewer_may_see_item($dir, $file, $itemsData) {
    if (is_revealed()) return true;
    $l = link_viewer();
    if ($l !== null && link_shows_only_its_own($l) && !link_allows_item($l, $dir, $file)) return false;   // a link that shows only its own things: not the public rest of the gallery either
    if (!file_is_hidden($dir, $file, $itemsData)) return true;
    return $l !== null && link_allows_item($l, $dir, $file);
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
    if (is_revealed()) return true;
    $l = link_viewer();
    // Someone using a share link: the link's own answer decides (the gallery-wide switch is for people who came in without one).
    if ($l !== null) return (!file_is_hidden($dir, $file, $itemsData) || link_allows_item($l, $dir, $file)) && link_capability_allows($l, 'downloads', $dir, $file, $itemsData);
    if (PUBLIC_DOWNLOAD_MODE === 'off' || file_is_hidden($dir, $file, $itemsData)) return false;
    return PUBLIC_DOWNLOAD_MODE === 'all' || public_download_folder_covers($file);
}

/** Is this file inside one of the folders the gallery lets strangers download from (Only some folders)? */
function public_download_folder_covers($file) {
    foreach (PUBLIC_DOWNLOAD_FOLDERS as $p) {
        if ($p !== '' && strncmp($file, $p . '/', strlen($p) + 1) === 0) return true;
    }
    return false;
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
    if ($type !== 'photos') foreach ((array) ($draft['folders'] ?? []) as $d) if ($d !== '' && str_starts_with($f, $d . '/')) return true;
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
        $cover = pick_folder_cover($members, folder_display_meta($dir, $top)['cover']);
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
    return ['short' => $base . '/' . $l['token'], 'long' => $base . '/?link=' . $l['token']];
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
        . '.wrap{box-sizing:border-box;min-height:100%;max-width:420px;margin:0 auto;padding:12vh 24px 24px;text-align:center}'
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
        if (!$isLink && $mayLook && (is_dir($dir . '/' . $token) || isset(gallery_mounts()[$token]))) {
            header('Cache-Control: no-store');
            header('Location: ' . gallery_root_href() . rawurlencode($token) . '/', true, 302);
            return;
        }
    }
    if (!ENABLE_SHARE_LINKS) { render_link_invalid_page(); return; }
    if (!is_https_request() && !is_local_request()) { render_https_required_page(); return; }
    if ($token === LINK_PROBE_TOKEN) { render_link_invalid_page(200); return; }   // the admin's check that the tidy address works: not a guess, and not a 404, which a browser would log in its console on every look at the Shares tab
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
    if (!login_throttle_allows($dir, 'link', $ip)) {
        http_response_code(429);
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store');
        echo 'Too many tries. Try again in a moment.';
        return;
    }
    if ($link !== null && $viaAlias !== null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && link_state($link) === 'active') {
        // (a temporary redirect, never a permanent one: it must stop the moment the owner removes the old address; and the same form the person used, short or long)
        link_alias_used($link['id'], $viaAlias);
        link_note_address($link, 'via', $viaAlias);
        header('Cache-Control: no-store');
        header('Location: ' . link_urls($link)[preg_match('/[?&]link=/', $_SERVER['REQUEST_URI'] ?? '') === 1 ? 'long' : 'short'], true, 302);
        return;
    }
    if ($link === null) {
        // An address from before a link was given a new one: not a guess, so not held against the visitor, but noted in that link's log.
        $oldOwner = link_token_format_ok($token) ? link_find_by_old_token(links_read(), $token) : null;
        if ($oldOwner !== null) { link_note_blocked($oldOwner, 'replaced'); link_note_address($oldOwner, 'tried', link_old_name($oldOwner, $token)); render_link_invalid_page(); return; }
        login_throttle_record_failure($dir, 'link', $ip);
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


