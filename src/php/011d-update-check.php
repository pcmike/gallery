/* -----------------------------------------------------------------------
 * New versions. The gallery can ask GitHub (the project's releases page) which version is the newest, at most once a day, from the Settings
 * page and never while a page is being built: the page asks afterwards, in the background, and says nothing when it can't be reached. If the
 * newest is newer than this one, the Health check says so with two buttons, View release and Update now. The only thing sent is an ordinary
 * request for the releases page: GitHub sees this server's address and the User-Agent "Drop-in Photo Gallery <version>" (the owner chose to
 * keep the version in it, 2026-11-17); nothing about the gallery's photos or its visitors. CHECK_FOR_UPDATES
 * turns the daily check off (the Check now button still works).
 *
 * Update now replaces this file (index.php) and nothing else. It downloads the release's index.php and its .sha256 from the project's own
 * release address, refuses anything whose checksum differs or that isn't a PHP file for the version named, keeps the file it replaces
 * (guarded, as .gallery-previous.php) and swaps the new one in with a single rename. Go back puts the kept file in again. Photos, settings and
 * every other file are never touched.
 * ----------------------------------------------------------------------- */

function update_state_path() {
    return __DIR__ . '/.gallery-update.php';
}
function update_backup_path() {
    return __DIR__ . '/.gallery-previous.php';
}

/** What we last learned: ['triedAt' => when we last asked, 'checkedAt' => when an answer last came, 'latest' => '2.7.0' or '', 'page' => the release's page, 'indexUrl'/'sumUrl' => its two files,
 *  'error' => why the last try failed or '', 'updatedFrom' => the version we replaced, 'updatedAt' => when]. */
function update_state() {
    return json_file_read(update_state_path(), ['triedAt' => 0, 'checkedAt' => 0, 'latest' => '', 'page' => '', 'indexUrl' => '', 'sumUrl' => '', 'error' => '', 'updatedFrom' => '', 'updatedAt' => 0]);
}
function update_state_save(array $state) {
    $encoded = data_file_encode($state);
    return $encoded !== false && file_write_whole(update_state_path(), $encoded, '.php');
}

/** Where the newest release is announced: GitHub's own, unless the setting UPDATE_FEED_URL names another (a mirror of your own, or a test). */
function update_feed_url() {
    return UPDATE_FEED_URL !== '' ? UPDATE_FEED_URL : 'https://api.github.com/repos/pcmike/gallery/releases/latest';
}
/** The start every address the feed names must have: the project's own release files, or (with a feed of your own) the feed's own server. */
function update_allowed_prefix() {
    if (UPDATE_FEED_URL === '') return 'https://github.com/pcmike/gallery/releases/';
    $p = parse_url(UPDATE_FEED_URL);
    return is_array($p) && !empty($p['host']) ? ($p['scheme'] ?? 'https') . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') . '/' : '';
}

/** The servers a release download may be sent on to, besides the one it started at: GitHub's own (a release file on github.com is answered with a redirect to
 *  release-assets.githubusercontent.com, older ones to objects.githubusercontent.com). Anything else is refused, with the host named. */
function update_redirect_hosts() {
    return ['github.com', 'api.github.com', '.githubusercontent.com'];
}

/** Asks the feed what the newest release is and remembers the answer (or the failure). Returns the state. */
function update_fetch() {
    $st = update_state();
    $st['triedAt'] = time();
    $fail = function ($why) use (&$st) { $st['error'] = $why; update_state_save($st); return $st; };
    $r = geo_http(update_feed_url(), 'GET', ['Accept: application/vnd.github+json'], 1000000, 10, update_redirect_hosts());
    if ($r['status'] === 0) return $fail($r['error'] !== '' ? $r['error'] : 'Could not reach the releases page.');
    if ($r['status'] !== 200) return $fail('The releases page answered ' . $r['status'] . '.');
    $j = json_decode($r['body'], true);
    $tag = is_array($j) ? ltrim((string) ($j['tag_name'] ?? ''), 'vV') : '';
    if (!preg_match('/^\d+\.\d+\.\d+$/', $tag)) return $fail('The releases page did not name a version.');
    $prefix = update_allowed_prefix();
    $ok = function ($u) use ($prefix) { return is_string($u) && $prefix !== '' && strncmp($u, $prefix, strlen($prefix)) === 0 && geo_url_ok($u); };
    $st['latest'] = $tag; $st['checkedAt'] = time(); $st['error'] = '';
    $st['page'] = $ok($j['html_url'] ?? null) ? (string) $j['html_url'] : '';
    $st['indexUrl'] = ''; $st['sumUrl'] = '';
    foreach ((array) ($j['assets'] ?? []) as $a) {
        if (!is_array($a)) continue;
        if (($a['name'] ?? '') === 'index.php' && $ok($a['browser_download_url'] ?? null)) $st['indexUrl'] = (string) $a['browser_download_url'];
        if (($a['name'] ?? '') === 'index.php.sha256' && $ok($a['browser_download_url'] ?? null)) $st['sumUrl'] = (string) $a['browser_download_url'];
    }
    update_state_save($st);
    return $st;
}

/** Is it time for the daily look? (Only where the answer can be kept: without that, every Settings page would ask.) */
function update_check_due() {
    if (!CHECK_FOR_UPDATES || !is_writable(__DIR__)) return false;
    return time() - (int) update_state()['triedAt'] >= 86400;
}

/** Takes this look for this request: the time of the try is written BEFORE the releases page is asked (under the file's lock), so a second admin page opened while the first answer is still
 *  waited for sees that a look is under way and does not ask again. $force (the Check now button) always goes ahead. True when this request should ask. */
function update_claim_try($force) {
    if (!$force && !update_check_due()) return false;
    $got = false;
    $defaults = ['triedAt' => 0, 'checkedAt' => 0, 'latest' => '', 'page' => '', 'indexUrl' => '', 'sumUrl' => '', 'error' => '', 'updatedFrom' => '', 'updatedAt' => 0];
    $sp = update_state_path(); unset($GLOBALS['__data_write_cause'][$sp]);
    json_file_update($sp, $defaults, function (&$d) use (&$got, $force) {
        if (!$force && time() - (int) ($d['triedAt'] ?? 0) < 86400) return;   // (another request took it a moment ago)
        $d['triedAt'] = time() - 82800; $got = true;   // (held for one hour only: the answer, or the failure, writes the real time and holds for the day; a request that dies while it waits is looked at again within the hour)
    }, true);
    if (!$got && !$force && isset($GLOBALS['__data_write_cause'][$sp])) return true;   // (the state file can't be written, so nothing can be claimed: it asks as it always did, rather than never)
    return $got || $force;
}

/** The newest version, when it is newer than this one (from what we last learned; nothing is asked here). Else null. */
function update_newer() {
    $st = update_state();
    if ($st['latest'] === '' || !version_compare($st['latest'], GALLERY_VERSION, '>')) return null;
    return $st;
}

/** Can this file be replaced here? ['ok' => bool, 'why' => the reason when not]. */
function update_can_write() {
    if (is_link(__FILE__)) return ['ok' => false, 'why' => 'index.php is a link to a file elsewhere'];
    if (!is_writable(__FILE__) || !is_writable(__DIR__)) return ['ok' => false, 'why' => 'the web server can\'t write to index.php here'];
    return ['ok' => true, 'why' => ''];
}
/** Can this gallery fetch a new one and replace its own file here? */
function update_can_replace() {
    if (!function_exists('curl_init') && !filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) return ['ok' => false, 'why' => 'this server doesn\'t let PHP fetch addresses'];
    return update_can_write();
}

/** The version a PHP text says it is (its GALLERY_VERSION line), or ''. */
function update_text_version($code) {
    return preg_match("/define\\('GALLERY_VERSION', '(\\d+\\.\\d+\\.\\d+)'\\)/", (string) $code, $m) ? $m[1] : '';
}

/** Does this text read as PHP, start to end? (A download cut short, or a page of something else, does not.) Without the tokenizer, true: the checksum has already agreed. */
function update_php_parses($code) {
    if (!function_exists('token_get_all') || !defined('TOKEN_PARSE')) return true;
    $limit = (string) ini_get('memory_limit');
    if ($limit !== '-1' && (int) $limit < 256 && !preg_match('/[gG]$/', $limit)) @ini_set('memory_limit', '256M');
    try { $t = token_get_all((string) $code, TOKEN_PARSE); unset($t); return true; } catch (\Throwable $e) { return false; }
}

/** The version of the file kept by the last update ('' when there is none). */
function update_backup_version() {
    $raw = is_file(update_backup_path()) ? @file_get_contents(update_backup_path(), false, null, 0, 40000) : '';
    return is_string($raw) ? update_text_version($raw) : '';
}

/** Puts $content where index.php is, in one rename, and checks what landed. Returns '' or why not. */
function update_swap_in($content) {
    $tmp = __DIR__ . '/.gallery-update-' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, $content) !== strlen($content)) { @unlink($tmp); return 'The new file could not be written beside index.php.'; }
    @chmod($tmp, @fileperms(__FILE__) & 0777 ?: 0644);
    if (!@rename($tmp, __FILE__)) { @unlink($tmp); return 'index.php could not be replaced.'; }
    clearstatcache(true, __FILE__);
    if (function_exists('opcache_invalidate')) @opcache_invalidate(__FILE__, true);
    return hash_file('sha256', __FILE__) === hash('sha256', $content) ? '' : 'The file that was written did not read back the same.';
}

/** Update now. Returns ['ok' => bool, 'text' => what to tell the owner, 'version' => the new one]. */
function update_apply() {
    $can = update_can_replace();
    if (!$can['ok']) return ['ok' => false, 'text' => 'Nothing was changed: ' . $can['why'] . '. Download it from the release page instead.'];
    $st = update_fetch();
    if ($st['error'] !== '') return ['ok' => false, 'text' => 'Nothing was changed: ' . $st['error']];
    $new = $st['latest'];
    if (!version_compare($new, GALLERY_VERSION, '>')) return ['ok' => false, 'text' => 'You already have the newest version.'];
    if ($st['indexUrl'] === '' || $st['sumUrl'] === '') return ['ok' => false, 'text' => 'Nothing was changed: that release has no index.php with a checksum to update from. Download it from the release page.'];
    $lock = @fopen(__FILE__, 'r');
    if ($lock) flock($lock, LOCK_EX);
    try {
        // Two presses at once: the second waits here for the first, and by now index.php on disk is the new one. It is not "the file to keep" any more.
        $onDisk = update_text_version((string) @file_get_contents(__FILE__));
        if ($onDisk !== '' && $onDisk !== GALLERY_VERSION) return ['ok' => false, 'text' => 'Nothing was changed: this gallery was just updated to ' . $onDisk . ' by another request. Reload the page.'];
        $f = geo_http($st['indexUrl'], 'GET', [], 12000000, 120, update_redirect_hosts());
        if ($f['status'] !== 200 || strlen($f['body']) < 100000) return ['ok' => false, 'text' => 'Nothing was changed: the file could not be downloaded' . ($f['error'] !== '' ? ' (' . $f['error'] . ')' : '') . '.'];
        $s = geo_http($st['sumUrl'], 'GET', [], 4096, 30, update_redirect_hosts());
        if ($s['status'] !== 200 || !preg_match('/^([0-9a-fA-F]{64})\b/', trim($s['body']), $m)) return ['ok' => false, 'text' => 'Nothing was changed: the checksum could not be downloaded.'];
        $code = $f['body'];
        if (!hash_equals(strtolower($m[1]), hash('sha256', $code))) return ['ok' => false, 'text' => 'Nothing was changed: the download did not match its checksum.'];
        if (strncmp($code, '<?php', 5) !== 0 || update_text_version($code) !== $new || !update_php_parses($code)) return ['ok' => false, 'text' => 'Nothing was changed: the download is not the PHP file for version ' . $new . '.'];
        $old = (string) @file_get_contents(__FILE__);
        $oldV = update_text_version($old);
        if ($oldV === '' || !version_compare($oldV, $new, '<')) return ['ok' => false, 'text' => 'Nothing was changed: the file in place is not older than the one to install, so it is not kept over an earlier one.'];
        $guarded = data_file_guard_line() . $old;
        if ($old === '' || @file_put_contents(update_backup_path(), $guarded) !== strlen($guarded)) return ['ok' => false, 'text' => 'Nothing was changed: the current file could not be kept first (is the folder writable?).'];
        $why = update_swap_in($code);
        if ($why !== '') {
            $back = update_swap_in($old);
            return ['ok' => false, 'text' => 'Updating failed: ' . $why . ($back === '' ? ' The old file is back in place.' : ' The old file could not be put back either: it is kept as ' . basename(update_backup_path()) . '.')];
        }
        $st['updatedFrom'] = GALLERY_VERSION; $st['updatedAt'] = time();
        update_state_save($st);
        return ['ok' => true, 'text' => 'Updated to ' . $new . '.', 'version' => $new];
    } finally {
        if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    }
}

/** Go back: puts the file the last update kept back in place. Returns ['ok' => bool, 'text' => ...]. */
function update_undo() {
    $can = update_can_write();
    if (!is_file(update_backup_path())) return ['ok' => false, 'text' => 'There is no earlier version kept.'];
    if (!$can['ok']) return ['ok' => false, 'text' => 'Nothing was changed: ' . $can['why'] . '.'];
    $lock = @fopen(__FILE__, 'r');
    if ($lock) flock($lock, LOCK_EX);
    try {
        $old = data_file_strip_guard((string) @file_get_contents(update_backup_path()));
        $v = update_text_version($old);
        if (strncmp($old, '<?php', 5) !== 0 || $v === '' || !update_php_parses($old)) return ['ok' => false, 'text' => 'Nothing was changed: the kept file is not a whole gallery file.'];
        $why = update_swap_in($old);
        if ($why !== '') return ['ok' => false, 'text' => 'Going back failed: ' . $why];
        @unlink(update_backup_path());
        $st = update_state(); $st['updatedFrom'] = ''; $st['updatedAt'] = 0; update_state_save($st);
        return ['ok' => true, 'text' => 'Back on ' . $v . '.', 'version' => $v];
    } finally {
        if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    }
}

/** What to say before going back to the kept version: [the button's text, what to confirm]. $recent: within the day after the update (the note's own button), when little can have changed since. '' text = no kept version to go back to. */
function update_undo_offer($recent) {
    $back = update_backup_version();
    if ($back === '' || !version_compare($back, GALLERY_VERSION, '<')) return ['', ''];
    // Going back to a version from before Only me (2.6.4 and older) turns every Only me folder into an ordinary private one, which anyone holding an
    // "Everything" share link can open. The mark stays, so going forward again hides them again; the button says so first when there are any.
    $warn = '';
    if (version_compare($back, '2.6.4', '<=')) {
        $dir = $GLOBALS['dir'] ?? __DIR__; $n = 0;
        foreach (all_folder_paths($GLOBALS['__admin_files'] ?? []) as $fp) if (folder_unlisted_cached($dir, $fp)) $n++;
        if ($n > 0) $warn = 'You have ' . $n . ' Only me folder' . ($n === 1 ? '' : 's') . '. Version ' . $back . ' does not know Only me: it treats ' . ($n === 1 ? 'that folder' : 'them') . ' as ordinary private folders, which anyone you gave an "Everything" share link to can open. The Only me mark is kept, and updating again hides ' . ($n === 1 ? 'it' : 'them') . ' again. Go back anyway?';
    }
    if (!$recent) $warn = ($warn !== '' ? $warn . "\n\n" : '') . 'Put version ' . $back . ' back? It is the file this gallery was running before it was updated. Your photos, captions and settings stay as they are, but anything this version has saved since may not be understood by the older one (new kinds of settings, the visitor log, share links made since). Going back is for when something does not work after an update.';
    return ['Go back to ' . $back, $warn];
}

/** The "new version" heads-up for the Health check, or the "just updated" one (with Go back), or null. Same shape as health_headsups(): label, detail, html (buttons). */
function update_headsups() {
    $e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $out = [];
    $st = update_state();
    $back = update_backup_version();
    if ($back !== '' && $st['updatedAt'] > 0 && time() - (int) $st['updatedAt'] < 86400 && version_compare($back, GALLERY_VERSION, '<')) {
        [, $warn] = update_undo_offer(true);
        $out[] = ['label' => 'Updated from ' . $back . ' to ' . GALLERY_VERSION, 'detail' => 'The file it replaced is kept, in case something doesn\'t look right.',
            'html' => '<div class="health-act"><button type="button" class="secondary" data-update="undo"' . ($warn !== '' ? ' data-warn="' . $e($warn) . '"' : '') . '>Go back to ' . $e($back) . '</button> <button type="button" class="secondary" data-update="dismiss">Hide this</button></div>', 'attr' => ' data-update-row'];
    }
    $n = update_newer();
    if ($n !== null) {
        $can = update_can_replace();
        $btns = ($can['ok'] ? '<button type="button" class="secondary" data-update="apply">Update now</button> ' : '')
            . ($n['page'] !== '' ? '<a class="secondary btn-link" href="' . $e($n['page']) . '" target="_blank" rel="noopener noreferrer">View release</a>' : '');
        $out[] = ['label' => 'Version ' . $n['latest'] . ' is out', 'detail' => 'You are on ' . GALLERY_VERSION . '.' . ($can['ok'] ? '' : ' This gallery can\'t update itself here (' . $can['why'] . '), so download it from the release page.') . ' ',
            'html' => '<div class="health-act">' . $btns . '</div>', 'attr' => ' data-update-row'];
    }
    return $out;
}

/** What the "Updates" row says: when it was last looked, and what it found. */
function update_status_text() {
    $st = update_state();
    if ($st['error'] !== '' && $st['triedAt'] > 0) return 'Could not check (' . $st['error'] . ')';
    if ($st['checkedAt'] > 0) return (update_newer() ? 'A newer version is out' : 'Up to date') . ' · checked ' . date('M j, g:i a', (int) $st['checkedAt']);
    return CHECK_FOR_UPDATES ? 'Not checked yet' : 'Not checking (switched off in Settings)';
}
/** The "Updates" row's own line, under its label: what it found, and Check now. */
function update_status_html() {
    [$undoText, $undoWarn] = update_undo_offer(false);   // (the file the last update replaced is kept: Go back stays on offer beside Check now, asking first)
    $e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    return '<div class="health-detail"><span data-update-text>' . $e(update_status_text()) . '</span> <button type="button" class="linklike" data-update="check">Check now</button>'
        . ($undoText !== '' ? ' <button type="button" class="linklike" data-update="undo" data-warn="' . $e($undoWarn) . '">' . $e($undoText) . '</button>' : '') . '</div>';
}

/** The Health check's buttons for this (Settings, answers JSON and ends the request): update_check, update_apply, update_undo. */
function update_admin_action($action) {
    if ($action === 'update_check') {
        if (update_claim_try(($_POST['force'] ?? '') === '1')) {
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();   // (the sign-in is not needed while the releases page is asked: every other admin request from this browser would wait for the answer)
            update_fetch();
        }
        $rows = array_map('health_headsup_row_html', update_headsups());
        admin_json_exit(['ok' => true, 'rows' => $rows, 'text' => update_status_text(), 'newer' => update_newer() !== null]);
    }
    if ($action === 'update_apply') admin_json_exit(update_apply());
    if ($action === 'update_undo') admin_json_exit(update_undo());
    if ($action === 'update_dismiss') { $st = update_state(); $st['updatedAt'] = 0; update_state_save($st); admin_json_exit(['ok' => true, 'text' => 'Hidden.']); }   // (the note after an update is shown for a day; this ends it now. The file the update replaced stays, as before.)
    admin_json_exit(['ok' => false, 'text' => 'Unknown action.']);
}
