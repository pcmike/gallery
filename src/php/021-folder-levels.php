/* -----------------------------------------------------------------------
 * Folder levels. $files (already hiding-filtered by the time a page is
 * built from it) is the allowlist for everything here: a folder path is
 * only ever "valid" because some file the script itself found sits under
 * it, never because a request said so — the same discipline as ?img=.
 * ----------------------------------------------------------------------- */

/** Splits $files under $prefix ('' for the root, else 'A/B/') into the
 *  files loose directly at that level and each immediate subfolder's
 *  recursive members. */
function split_folder_level($files, $prefix) {
    $folders = [];
    $loose = [];
    $len = strlen($prefix);
    foreach ($files as $f) {
        if ($prefix !== '' && strncmp($f, $prefix, $len) !== 0) continue;
        $rest = substr($f, $len);
        $slash = strpos($rest, '/');
        if ($slash === false) {
            $loose[] = $f;
        } else {
            $folders[substr($rest, 0, $slash)][] = $f;
        }
    }
    return ['folders' => $folders, 'loose' => $loose];
}

/** Every folder path (at any depth) that has at least one file under it,
 *  parents before their children. */
function all_folder_paths($files) {
    $set = [];
    foreach ($files as $f) {
        $d = dirname($f);
        while ($d !== '.' && $d !== '' && !isset($set[$d])) {
            $set[$d] = true;
            $d = dirname($d);
        }
    }
    $paths = array_keys($set);
    usort($paths, 'strnatcasecmp');
    return $paths;
}

/** Folder paths as a tree in the gallery's own order (a position set by the owner first, then by name): each folder is followed by
 *  the folders inside it. A folder's parents are added when they are not in the list, so the tree always reads. Rows: p (path),
 *  d (depth, 0 at the top), n (its own name as the gallery shows it). */
function folder_tree_rows($dir, array $paths) {
    $set = [];
    foreach ($paths as $p) { $p = (string) $p; while ($p !== '' && $p !== '.') { $set[$p] = true; $p = dirname($p); } }
    $kids = [];
    foreach (array_keys($set) as $p) { $p = (string) $p; $parent = dirname($p); $kids[$parent === '.' ? '' : $parent][] = basename($p); }
    $rows = [];
    $walk = function ($parent, $depth) use (&$walk, &$rows, $dir, $kids) {
        if (empty($kids[$parent])) return;
        foreach (order_folder_children($dir, $parent === '' ? '' : $parent . '/', array_map('strval', $kids[$parent])) as $name) {
            $path = $parent === '' ? $name : $parent . '/' . $name;
            $label = folder_display_meta($dir, $path)['label'];
            $rows[] = ['p' => $path, 'd' => $depth, 'n' => $label !== '' ? $label : $name];
            $walk($path, $depth + 1);
        }
    };
    $walk('', 0);
    return $rows;
}

/** The files under a folder path, or [] if no file does — which is also
 *  the "this folder doesn't exist (or is hidden from you)" answer. */
function folder_files_under($files, $folderPath) {
    if (!is_string($folderPath) || $folderPath === '') return [];
    $prefix = $folderPath . '/';
    $len = strlen($prefix);
    return array_values(array_filter($files, function ($f) use ($prefix, $len) {
        return strncmp($f, $prefix, $len) === 0;
    }));
}

/** A folder's own admin-set identity, read from its own
 *  .gallery-items.php, normalized so callers never re-check types. */
/** The gallery root's own title, dates, description and cover — the same
 *  record a folder keeps (in the root's .gallery-items.php), so the root is
 *  configured the same way folders are. */
function root_display_meta($dir) {
    $d = items_read($dir);
    return [
        'label' => is_string($d['displayName'] ?? null) ? $d['displayName'] : '',
        'cover' => is_string($d['cover'] ?? null) && $d['cover'] !== '' ? $d['cover'] : null,
        'description' => is_string($d['description'] ?? null) ? $d['description'] : '',
        'dates' => is_string($d['dates'] ?? null) ? $d['dates'] : '',
    ];
}

function folder_display_meta($dir, $folderRel) {
    if ($folderRel !== '' && collections_any() && ($coll = collection_at($dir, $folderRel)) !== null) return collection_display_meta($coll);   // (a collection is a folder that exists only in the gallery: its own record answers)
    $d = items_read(resolve_real_path($dir, $folderRel));
    $cover = is_string($d['cover'] ?? null) && $d['cover'] !== '' ? $d['cover'] : null;   // (a photo's name, or its path inside this folder when the photo is in a folder under it)
    if ($cover === null && is_string($d['coverFollow'] ?? null) && $d['coverFollow'] !== '' && strpbrk($d['coverFollow'], "/\\") === false) $cover = 'follow:' . $d['coverFollow'];   // (the cover of a folder directly under it)
    return [
        'label' => is_string($d['displayName'] ?? null) ? $d['displayName'] : '',
        'cover' => $cover,
        'description' => is_string($d['description'] ?? null) ? $d['description'] : '',
        'dates' => is_string($d['dates'] ?? null) ? $d['dates'] : '',
        'sortOrder' => is_int($d['sortOrder'] ?? null) ? $d['sortOrder'] : null,
    ];
}

/** Orders sibling folder names: an explicit admin-set position first
 *  (ascending), everything else after in natural order — by name, or by
 *  folder mtime when PHOTO_SORT_ORDER says so. */
function order_folder_children($dir, $prefix, $names) {
    $meta = [];
    foreach ($names as $name) {
        $meta[$name] = folder_display_meta($dir, $prefix . $name);
    }
    usort($names, function ($a, $b) use ($meta, $dir, $prefix) {
        $oa = $meta[$a]['sortOrder'];
        $ob = $meta[$b]['sortOrder'];
        if ($oa !== null && $ob !== null && $oa !== $ob) return $oa <=> $ob;
        if ($oa !== null && $ob === null) return -1;
        if ($oa === null && $ob !== null) return 1;
        if (PHOTO_SORT_ORDER === 'mtime') {
            $ma = @filemtime(resolve_real_path($dir, $prefix . $a)) ?: 0;
            $mb = @filemtime(resolve_real_path($dir, $prefix . $b)) ?: 0;
            if ($ma !== $mb) return $ma <=> $mb;
        }
        return strnatcasecmp($a, $b);
    });
    return $names;
}

/**
 * ?reveal — the only thing that invokes a real HTTP Basic Auth prompt
 * for PRIVATE_VIEW_PASSWORD. Same HTTPS requirement and failed-attempt
 * throttle as ?admin (a separate throttle realm, so failures on one
 * password never lock out the other). On success, sets the reveal
 * cookie and redirects back to the bare URL — dropping ?reveal from the
 * address bar, not just leaving it there, so "bookmark the plain gallery
 * link afterward" is literally true.
 */
function run_reveal_route($dir) {
    send_sensitive_page_headers();
    // One sign-in for the lock in the icon strip: the private-view password shows the private items, the admin password
    // signs in to the admin. Either alone is enough for the form to exist.
    if (PRIVATE_VIEW_PASSWORD === '' && ADMIN_PASSWORD === '') {
        http_response_code(404);
        return;
    }
    // Back to the very same page the visitor came from: a folder, an open photo, the open map.
    $backPage = login_return_query();
    // The lock in the icon strip, when it is open, locks the private items again: whoever is signed in (a viewer with the
    // private-view password, or the owner signed in to the admin) is signed out of this browser.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
        if (is_admin_browser()) {
            admin_start_session();
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                gallery_cookie_expire(session_name(), 'Strict');
            }
            session_destroy();
        }
        browser_revoke_current();
        header('Location: ./' . $backPage, true, 303);
        return;
    }
    if (!is_https_request() && !is_local_request()) {
        render_https_required_page();
        return;
    }
    $ip = client_ip();
    // One plain prompt, whichever password it turns out to be.
    $heading = 'Enter your access code';
    $prompt = '';
    // The form is the normal way in; a Basic Authorization header (a script,
    // a bookmarked login) is still honored. Only a password that was
    // actually *supplied* and wrong counts against the throttle: a plain
    // visit to the form tells us nothing about guessing.
    $pass = $_SERVER['PHP_AUTH_PW'] ?? null;
    $formPass = login_form_password();
    $supplied = $formPass ?? $pass;
    // Both passwords are guessed at through this one box, so a visitor who keeps failing is held back from the admin
    // sign-in as well (per address), and the admin's own lockout holds here too. Only an attempt is refused: just
    // looking at the form never is.
    $attempting = $formPass !== null || isset($_SERVER['PHP_AUTH_USER']) || isset($_SERVER['PHP_AUTH_PW']);
    if ($attempting && (!login_throttle_allows($dir, 'reveal', $ip) || (ADMIN_PASSWORD !== '' && !login_throttle_allows($dir, 'admin', $ip)))) {
        http_response_code(429);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Too many failed attempts. Try again in a moment.';
        return;
    }
    $asAdmin = is_string($supplied) && ADMIN_PASSWORD !== '' && password_matches(ADMIN_PASSWORD, $supplied);
    $asViewer = !$asAdmin && is_string($supplied) && PRIVATE_VIEW_PASSWORD !== '' && password_matches(PRIVATE_VIEW_PASSWORD, $supplied);
    if (!$asAdmin && !$asViewer) {
        if (isset($_SERVER['PHP_AUTH_USER']) || isset($_SERVER['PHP_AUTH_PW']) || $formPass !== null) {
            login_throttle_record_failure($dir, 'reveal', $ip);
            if (ADMIN_PASSWORD !== '') login_throttle_record_failure($dir, 'admin', $ip);
        }
        if ($formPass === null && (isset($_SERVER['PHP_AUTH_USER']) || isset($_SERVER['PHP_AUTH_PW']))) {
            // A wrong saved Basic password (see admin_reject_saved_credentials): the form, never a challenge.
            http_response_code(401);
            render_login_page($heading, $prompt, 'Your browser sent a saved login that no longer matches. Type the current access code.');
            return;
        }
        render_login_page($heading, $prompt, $formPass !== null ? 'That code isn\'t right.' : '');
        return;
    }
    login_throttle_record_success($dir, 'reveal', $ip);
    // A password still in plain text in the settings file becomes a hash now (everything below uses what is stored).
    $upgraded = upgrade_plaintext_passwords();
    if ($asAdmin) {
        // The owner: a fresh admin session, exactly as the admin's own sign-in makes one, then back to the very page they
        // were on (the title leads on to the admin).
        admin_start_session();
        session_regenerate_id(true);
        $_SESSION['admin_auth'] = admin_session_marker($upgraded['ADMIN_PASSWORD'] ?? null);
        login_throttle_record_success($dir, 'admin', $ip);
        set_admin_reveal_cookie($upgraded['ADMIN_PASSWORD'] ?? null);
        header('Location: ./' . $backPage, true, 303);
        return;
    }
    set_reveal_cookie($upgraded['PRIVATE_VIEW_PASSWORD'] ?? null);
    header('Location: ./' . $backPage, true, 302);
}

