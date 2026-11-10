</body>
</html>
<?php
}

function run_admin_setup() {
    if (!is_https_request() && !is_local_request()) {
        render_https_required_page();
        return;
    }
    $error = ''; $manual = '';
    $code = admin_setup_code_ensure();   // '' only when the folder can't be written: then nothing can be saved here either (see the page's own message)
    $needCode = $code !== '';
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['setup_password']) && is_string($_POST['setup_password'])) {
        $codeOk = true;
        if ($needCode) {
            if (!login_throttle_allows(__DIR__, 'setup', client_ip())) {
                render_lockout_page('Setup paused', 'Too many wrong setup codes were entered from your connection. Setting up is paused for a moment.', login_throttle_wait(__DIR__, 'setup', client_ip()));
                return;
            }
            $given = admin_setup_code_normalize($_POST['setup_code'] ?? '');
            $codeOk = $given !== '' && hash_equals($code, $given);
            if (!$codeOk) {
                login_throttle_record_failure(__DIR__, 'setup', client_ip());
                $error = 'That is not the code in gallery-setup-code.php. Open the file again and copy the code from its second line.';
            }
        }
        $pw = str_replace("\0", '', $_POST['setup_password']);
        $pw2 = is_string($_POST['setup_password2'] ?? null) ? str_replace("\0", '', $_POST['setup_password2']) : '';
        if (!$codeOk) {
            // (nothing about the password is looked at until the code is right)
        } elseif ($pw !== $pw2) $error = 'The two passwords you typed don\'t match.';
        elseif (!admin_password_ok($pw)) $error = 'Use at least 8 characters with at least one letter and one number: this one password can change your whole gallery.';
        elseif (preg_match('/[\x00-\x1F\x7F]/', $pw)) $error = 'The password can\'t contain control characters.';
        else {
            $result = admin_setup_claim($pw);
            if (!empty($result['ok'])) {
                // Signed in as part of setting it up, and the code file (and the marker file older versions used) has done its job.
                login_throttle_record_success(__DIR__, 'setup', client_ip());
                admin_start_session();
                session_regenerate_id(true);
                $_SESSION['admin_auth'] = admin_session_marker($result['stored']);
                set_admin_reveal_cookie($result['stored']);
                $_SESSION['admin_flash'] = ['type' => 'ok', 'text' => 'Your admin password is set and you\'re signed in. Settings (left) is where the gallery-wide settings live; the password is kept in .gallery-config.php, which survives updating index.php.'];
                @unlink(admin_setup_code_path());
                @unlink(__DIR__ . '/.gallery-setup');
                header('Location: ?admin#site', true, 303);
                return;
            }
            $error = (string) ($result['error'] ?? 'Could not set the password.');
            $manual = (string) ($result['manual'] ?? '');
            if (!empty($result['taken'])) {
                // Someone else got there first: this is a login page now.
                render_login_page('Enter your access code', '', $error);
                return;
            }
        }
    }
    render_setup_page($error, $manual, $needCode);
}

/** ?admin&accelcheck=1&prefix=/kb: answers the way the gallery answers for a photo once "nginx internal redirect" is set to
 *  that prefix: no body, just an X-Accel-Redirect to one of the gallery's own photos. Behind an nginx that has the matching
 *  `internal` location the browser gets the picture; anywhere else it gets nothing, which is how the admin tells whether
 *  setting it would work (or whether what is set does). Admin only; sends no file itself. */
function send_accel_check($dir, $files) {
    header('Cache-Control: no-store');
    $prefix = (string) ($_GET['prefix'] ?? '');
    $target = lock_probe_targets($dir, $files)['file'];
    if ($prefix === '' || !site_settings_valid_nginx_prefix($prefix) || $target === '') {
        http_response_code(404);
        return;
    }
    $urlPath = implode('/', array_map('rawurlencode', explode('/', $target)));
    header('Content-Type: ' . guess_mime(strtolower(pathinfo($target, PATHINFO_EXTENSION))));
    header('X-Accel-Redirect: ' . rtrim($prefix, '/') . '/' . $urlPath);
}

/**
 * Entry point for ?admin — auth (HTTPS, Basic Auth, throttle), then
 * dispatches to a POST action handler or the page itself. Exits the
 * script either way; nothing below this ever runs for an admin request.
 */
function run_admin_route($dir, $files, $groups, $folderGroupKeys, $explicitGroups, $explicitGroupKeys, $videoDurations, $galleryNotices) {
    send_sensitive_page_headers();
    if (ADMIN_PASSWORD === '') {
        // No password yet: the page that sets one (it asks for the code in gallery-setup-code.php).
        run_admin_setup();
        return;
    }
    if (!is_https_request() && !is_local_request()) {
        render_https_required_page();
        return;
    }
    $ip = client_ip();
    // Already logged in (the session carries it), or a Basic Authorization
    // header (scripts), or the password form. A visitor with no session
    // cookie never starts a session just by asking for ?admin.
    if (isset($_COOKIE[admin_session_name()])) admin_start_session();
    $sessionAuthed = session_status() === PHP_SESSION_ACTIVE && admin_session_authed();
    if (!$sessionAuthed && !isset($_SERVER['PHP_AUTH_USER']) && !isset($_SERVER['PHP_AUTH_PW']) && browser_owner_may_resume()) {
        // The session is gone (a browser that forgets session cookies, or four hours passed) but this browser signed in a while
        // ago and kept the cookie: start a fresh session, as a sign-in does. Its CSRF token is new, so a change made from a page
        // opened before that is refused, and the page asks to be reloaded.
        admin_start_session();
        session_regenerate_id(true);
        $_SESSION['admin_auth'] = admin_session_marker();
        $sessionAuthed = true;
    }
    if (!$sessionAuthed && !login_throttle_allows($dir, 'admin', $ip)) {
        // Only someone who is not signed in is held back: a session that is already signed in is not guessing anything, and whoever shares this address
        // (a roommate, a proxy that hides the real one) must not be able to lock the owner out of the admin by guessing wrong.
        render_lockout_page('Sign-in paused', 'Too many wrong access codes were entered from your connection. Sign-in is paused for a moment.', login_throttle_wait($dir, 'admin', $ip));
        return;
    }
    if (!$sessionAuthed) {
        $basicSupplied = isset($_SERVER['PHP_AUTH_USER']) || isset($_SERVER['PHP_AUTH_PW']);
        $formPass = !isset($_POST['action']) ? login_form_password() : null;
        if ($basicSupplied && $formPass === null) {
            if (!admin_credentials_valid()) {
                login_throttle_record_failure($dir, 'admin', $ip);
                admin_reject_saved_credentials();
                return;
            }
        } elseif ($formPass !== null) {
            if (!password_matches(ADMIN_PASSWORD, $formPass)) {
                login_throttle_record_failure($dir, 'admin', $ip);
                render_login_page('Enter your access code', '', 'That code isn\'t right.');
                return;
            }
            // A fresh session id at the moment of login, so nothing issued
            // before it can ever be a logged-in session.
            admin_start_session();
            session_regenerate_id(true);
            $upgraded = upgrade_plaintext_passwords();
            $_SESSION['admin_auth'] = admin_session_marker($upgraded['ADMIN_PASSWORD'] ?? null);
            login_throttle_record_success($dir, 'admin', $ip);
            set_admin_reveal_cookie($upgraded['ADMIN_PASSWORD'] ?? null);
            header('Location: ?' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? $_SERVER['QUERY_STRING'] : 'admin'), true, 303);
            return;
        } else {
            render_login_page('Enter your access code', '', $_SERVER['REQUEST_METHOD'] === 'POST' ? 'Your session expired. Sign in again, then repeat that change.' : '');
            return;
        }
    }
    if (!$sessionAuthed) login_throttle_record_success($dir, 'admin', $ip);
    // A valid admin login counts as revealed too — one-directional, and
    // this is what makes it true on the *public* gallery view, not just
    // inside ?admin itself (a plain cookie, since the public page is
    // never behind its own session/Basic-Auth check). Not when the password
    // is being changed in this very request: that sets the new token itself,
    // and a second Set-Cookie for the same name would only be noise.
    if (!(($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'save_site_settings' && !empty($_POST['pw_admin']))) {
        set_admin_reveal_cookie();
    }
    admin_start_session();
    geo_maybe_refresh(true);

    if (isset($_GET['accelcheck'])) {
        send_accel_check($dir, $files);
        return;
    }
    if (isset($_GET['csrf'])) {   // the owner's quick private toggle on the gallery pages asks for this before it saves
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo json_encode(['token' => admin_csrf_token()]);
        return;
    }

    if (isset($_GET['browserhist'])) {   // the list of signed-in browsers opens one browser's addresses (and its CSV)
        admin_browser_history_route();
        return;
    }
    if (isset($_GET['item'])) {   // the owner's photo editor in the viewer asks for one photo's record before it opens
        admin_item_json($dir, $files);
        return;
    }

    if (isset($_GET['linkpreview']) || isset($_POST['linkpreview'])) {   // "Preview as this link": opens the link's own view of the gallery, in a new tab
        // Sent as a form (POST), never as an address: the token would end up in the browser's history and the server's logs.
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); header('Allow: POST'); echo 'Use the Preview button in the Shares tab.'; return; }
        $pl = ENABLE_SHARE_LINKS && is_string($_POST['linkpreview']) && admin_csrf_valid($_POST['csrf_token'] ?? '') && preg_match('/^[0-9a-f]{10}$/', $_POST['linkpreview']) ? (links_read()['links'][$_POST['linkpreview']] ?? null) : null;
        if (link_is_public_log($pl)) $pl = null;   // (the public address's log isn't a link to look through)
        if (!is_array($pl) || link_state($pl) === 'revoked') { http_response_code(400); echo 'That link can\'t be previewed.'; return; }
        preview_set_cookie($pl);
        header('Cache-Control: no-store');
        header('Location: ' . link_open_in_href($pl), true, 303);
        return;
    }
    if (isset($_GET['filesdownload'])) { files_download_route($dir, $files); return; }
    if (isset($_GET['visits']) || isset($_GET['visitsexport']) || isset($_GET['linkphotos']) || isset($_GET['linkcover']) || isset($_GET['linkspanel']) || isset($_GET['browserspanel'])) {   // the Shares tab's fragments and CSV downloads
        run_links_view_route($dir, $files);
        return;
    }

    if (isset($_GET['hidden'])) {
        // Older link: the hidden-items summary is the "Hidden" tab now.
        header('Location: ?admin#hidden', true, 302);
        return;
    }

    $adminFolderPath = admin_resolve_folder($files);
    if ($adminFolderPath === false) {
        http_response_code(404);
        return;
    }
    $adminFolderReal = $adminFolderPath === null ? $dir : resolve_real_path($dir, $adminFolderPath);
    if (isset($_GET['changed'])) {   // the gallery page asks again (the window came back): what did the log of disk activity take in since the entry it knew (after=), and, with since=, which files and folders are new since then (to mark)
        header('Cache-Control: no-store');
        $out = ['ok' => true, 'last' => changes_last_id()];
        if (isset($_GET['after'])) {
            $out['entries'] = array_map(function ($r) use ($dir) { return ['id' => (int) $r['id'], 'kind' => $r['kind'], 'folder' => $r['folder'], 'label' => $r['folder'] === '' ? '' : link_folder_label($dir, $r['folder']), 'n' => (int) $r['n'], 't0' => (int) $r['t0']]; }, changes_after((int) $_GET['after'], $adminFolderPath));
        }
        if (isset($_GET['since']) && (int) $_GET['since'] > 0) { $out['fresh'] = changes_fresh_files($dir, $files, $adminFolderPath, (int) $_GET['since']); $out['folders'] = changes_new_folders($adminFolderPath, (int) $_GET['since']); }
        admin_json_exit($out);
    }
    if (isset($_GET['changes_list'])) {   // the Changes tab asks again (it was opened, or the window came back): the entries as they are now, with how many of each kind
        header('Cache-Control: no-store');
        $chRows = changes_list(500);
        $chSeen = (int) changes_read(function ($db) { return visitlog_meta_get($db, 'chg_seen'); }, 0);
        admin_json_exit(['ok' => true, 'n' => count($chRows), 'html' => changes_entries_html($chRows, $chSeen), 'counts' => array_map(function ($g) { return $g[1]; }, changes_group_counts($chRows))]);
    }
    if (isset($_GET['pbcounts'])) {   // the phone bar's Menu asks for its numbers when it is opened (the gallery page does not work them out on every view)
        header('Cache-Control: no-store');
        header('Content-Type: application/json; charset=UTF-8');
        $pbHidden = admin_any_hidden($dir, $files, $folderGroupKeys) ? admin_hidden_data($dir, $files) : null;
        echo json_encode(admin_nav_counts($dir, $files) + ['edited' => (int) admin_edited_overview($dir, $files, $pbHidden)['total']]);
        return;
    }
    if (isset($_GET['shellcounts'])) {   // the shell asks again for its sidebar's numbers (they change as things are done in the frame)
        header('Cache-Control: no-store');
        header('Content-Type: text/html; charset=UTF-8');
        echo shell_whole_nav($dir, $files, $folderGroupKeys);
        return;
    }
    if (isset($_GET['shellmeta'])) {   // the shell asks again for the gallery's title and the folders' display names (changed in the frame)
        header('Cache-Control: no-store');
        admin_json_exit(['ok' => true, 'title' => (string) ($GLOBALS['pageTitle'] ?? 'Gallery'), 'labels' => (object) shell_folder_labels($dir, $files)]);
    }
    if (isset($_GET['shelltree'])) {   // the shell asks again for its folder tree (its locks, counts and folders change as things are done in the frame)
        header('Cache-Control: no-store');
        header('Content-Type: text/html; charset=UTF-8');
        echo shell_folder_tree($files, null);
        return;
    }
    if (isset($_GET['shell']) && empty($_GET['inframe'])) {   // the owner's shell: this page's header and sidebar around one frame (the frame's pages ask for ?inframe=1)
        render_shell_page($dir, $files, $adminFolderPath, $folderGroupKeys);
        return;
    }
    if (isset($_GET['privcounts']) && $adminFolderPath !== null) {   // what making this folder public would do, asked for fresh when the switch is turned off
        header('Cache-Control: no-store');
        admin_json_exit(['ok' => true, 'counts' => folder_privacy_counts($dir, $files, $adminFolderPath)]);
    }
    // Just what sits loose directly in this folder — its subfolders get
    // their own admin page, reached from the nav, so an edit's target path
    // is always exactly <this folder>/<bare filename>.
    $adminFiles = split_folder_level($files, $adminFolderPath === null ? '' : $adminFolderPath . '/')['loose'];

    $resultMessage = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $GLOBALS['__data_write_failed'] = false; $GLOBALS['__data_refused'] = [];
        $resultMessage = handle_admin_post($dir, $adminFolderPath, $adminFolderReal, $files, $groups, $explicitGroups);
        // A save that couldn't write must not read as a save: say what to fix.
        if (!empty($GLOBALS['__data_write_failed']) && ($resultMessage['type'] ?? '') === 'ok') {
            $resultMessage = ['type' => 'error', 'text' => data_write_failure_text($adminFolderPath === null ? 'this folder' : data_write_failure_place($dir, $adminFolderReal))];
        }
        if ($resultMessage['type'] === 'redirect') {
            // Post, redirect, get: the new settings take effect on the next request, and the page shows them.
            header('Location: ?' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? $_SERVER['QUERY_STRING'] : 'admin') . '#site', true, 303);
            return;
        }
        if ($resultMessage['type'] === 'logout') {
            // Back to being an ordinary visitor: no admin session, and no
            // private-view cookie either (a login sets both).
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                gallery_cookie_expire(session_name(), 'Strict');
            }
            session_destroy();
            browser_revoke_current();
            // Back on the gallery page of the folder being edited, now signed out.
            header('Location: ' . ($adminFolderPath !== null ? '?folder=' . rawurlencode($adminFolderPath) : gallery_root_href()), true, 303);
            return;
        }
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'gallery-fetch' && in_array($resultMessage['type'] ?? '', ['ok', 'error'], true)) {
            // A setting flipped in place by the page's script: answer in JSON, no page.
            admin_json_exit(['ok' => $resultMessage['type'] === 'ok', 'text' => (string) ($resultMessage['text'] ?? '')] + array_intersect_key($resultMessage, ['hash' => 1, 'stale' => 1, 'counts' => 1, 'private' => 1, 'unlisted' => 1, 'code' => 1, 'fresh' => 1, 'cover' => 1, 'photos' => 1, 'more' => 1, 'label' => 1, 'n' => 1, 'html' => 1, 'short' => 1]));
        }
        if ($resultMessage['type'] === 'export') {
            admin_export_items($adminFolderPath, $adminFolderReal);
            return;
        }
        if ($resultMessage['type'] === 'export_tree') {
            admin_export_tree($dir, $files);
            return;
        }
    }

    if ($resultMessage === null && !empty($_SESSION['admin_flash']) && is_array($_SESSION['admin_flash'])) {
        $resultMessage = $_SESSION['admin_flash'];
        unset($_SESSION['admin_flash']);
    }
    $mdCandidates = scan_md_candidates($adminFolderReal);
    render_admin_page($dir, $files, $adminFolderPath, $adminFolderReal, $adminFiles, $groups, $folderGroupKeys, $explicitGroups, $explicitGroupKeys, $videoDurations, $mdCandidates, $resultMessage);
}

