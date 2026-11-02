/* -----------------------------------------------------------------------
 * Passwords at rest. The settings file keeps each password as a bcrypt hash
 * (password_hash), so a copy of that file — a backup, a zip, a repository, a
 * server that sends .php files as text — does not hand over the password
 * itself, only something that has to be guessed at one try at a time. A
 * password written there in plain text still works (hand-edited files, older
 * versions); the first successful sign-in replaces it with its hash.
 * ----------------------------------------------------------------------- */

/** True if $v is a password hash made by password_hash(), not a password. */
function password_is_hashed($v) {
    return is_string($v) && $v !== '' && (password_get_info($v)['algo'] ?? null) !== null;
}

/** Does $supplied match the stored password: a hash (checked with
 *  password_verify) or, for a file not yet upgraded, the plain text. */
function password_matches($stored, $supplied) {
    if (!is_string($stored) || $stored === '' || !is_string($supplied)) return false;
    return password_is_hashed($stored) ? password_verify($supplied, $stored) : hash_equals($stored, $supplied);
}

/** What the settings file keeps for a new password. */
function password_for_storage($plain) {
    return password_hash($plain, PASSWORD_DEFAULT);
}

/** A random secret that belongs to this install, kept in its own small data
 *  file (.gallery-secret.php, answers 404 like the others). The sign-in
 *  cookies are keyed with it, so someone who only has a copy of the settings
 *  file (and so the password hashes) still can't make a valid cookie. $create
 *  makes it if it doesn't exist (at a sign-in); without it, a missing secret
 *  is just ''. If the folder can't be written the cookies fall back to being
 *  derived from the stored password alone. */
function install_secret($create = false) {
    static $secret = null;
    if ($secret !== null && ($secret !== '' || !$create)) return $secret;
    $path = __DIR__ . '/.gallery-secret.php';
    $read = function () use ($path) {
        $raw = is_file($path) ? (string) @file_get_contents($path) : '';
        return preg_match('/^[0-9a-f]{64}$/m', $raw, $m) ? $m[0] : '';
    };
    $secret = $read();
    if ($secret !== '' || !$create || !is_writable(__DIR__)) return $secret;
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, data_file_guard_line() . bin2hex(random_bytes(32)) . "\n", LOCK_EX) === false) { @unlink($tmp); return $secret; }
    @chmod($tmp, 0600);
    // link() refuses to replace a file that appeared meanwhile, so two first sign-ins can't end up with different secrets.
    if (!@link($tmp, $path) && !is_file($path)) @rename($tmp, $path);
    @unlink($tmp);
    return $secret = $read();
}

/** After a successful sign-in: any password the settings file still holds in
 *  plain text is replaced there by its hash (both at once, so one write).
 *  Returns the values now in force, name => stored value, for the passwords
 *  it changed ([] if none, or if the file can't be rewritten: then nothing
 *  changes and the plain text keeps working). */
function upgrade_plaintext_passwords() {
    $state = gallery_config_load();
    if ($state['status'] !== 'ok') return [];
    $names = [];
    foreach (['ADMIN_PASSWORD', 'PRIVATE_VIEW_PASSWORD'] as $n) {
        if (isset($state['cfg'][$n]) && is_string($state['cfg'][$n]) && $state['cfg'][$n] !== '' && !password_is_hashed($state['cfg'][$n])) $names[] = $n;
    }
    if (!$names || !is_writable(__DIR__)) return [];
    $lock = @fopen(__FILE__, 'r');
    if ($lock) flock($lock, LOCK_EX);
    try {
        $path = gallery_config_path();
        $raw = @file_get_contents($path);
        if ($raw === false) return [];
        [$status, $cfg] = gallery_config_parse($raw);
        if ($status !== 'ok') return [];
        $changed = [];
        foreach ($names as $n) {
            // Only what the file still holds, unchanged since this request began.
            if (($cfg[$n] ?? null) === $state['cfg'][$n]) { $cfg[$n] = password_for_storage($state['cfg'][$n]); $changed[$n] = $cfg[$n]; }
        }
        if (!$changed) return [];
        $content = gallery_config_render($cfg);
        if ($content === null) return [];
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $content, LOCK_EX) === false) { @unlink($tmp); return []; }
        @chmod($tmp, 0600);
        [$roundStatus, $roundTrip] = gallery_config_parse((string) @file_get_contents($tmp));
        ksort($roundTrip); ksort($cfg);
        if ($roundStatus !== 'ok' || $roundTrip !== $cfg || !@rename($tmp, $path)) { @unlink($tmp); return []; }
        return $changed;
    } finally {
        if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    }
}

function admin_session_authed() {
    return isset($_SESSION['admin_auth']) && is_string($_SESSION['admin_auth'])
        && hash_equals(admin_session_marker(), $_SESSION['admin_auth']);
}

/* -----------------------------------------------------------------------
 * Browsers that are signed in. The owner and anyone given the private-view password are each recognised by one cookie
 * (REVEAL_COOKIE_NAME) that holds a random token. The server keeps only the token's hash (.gallery-browsers.php), with who it is for
 * ('owner' or 'view'), when it was made and last used, when it ends, a short label for the device ("iPhone · Safari") and a mark of the
 * password it was made under. So a token can't be forged, Log out ends that browser's for good, the owner can end any one from
 * Settings, and changing a password ends every token made under the old one. A token lasts ADMIN_STAY_SIGNED_IN_DAYS (the owner) or
 * PRIVATE_VIEW_SIGNED_IN_DAYS (a guest) from the last visit; 0 is until the browser closes (and, on the server, a day).
 * ----------------------------------------------------------------------- */
function browsers_path() {
    return __DIR__ . '/.gallery-browsers.php';
}

function browser_days($kind) {
    return $kind === 'owner' ? ADMIN_STAY_SIGNED_IN_DAYS : PRIVATE_VIEW_SIGNED_IN_DAYS;
}

/** What a token carries to show which password it was made under ($stored: the stored password, passed right after it changed, when
 *  the constant may still hold the old one). '' when there is no such password, so no token of that kind can be good. */
function browser_marker($kind, $stored = null) {
    if ($kind === 'owner') return ($stored ?? ADMIN_PASSWORD) === '' ? '' : admin_session_marker($stored);
    $pw = $stored ?? PRIVATE_VIEW_PASSWORD;
    return $pw === '' ? '' : hash('sha256', 'view|' . $pw . '|' . __DIR__);
}

/** A short, plain name for the device that is asking ("iPhone · Safari"), for the owner's list. Never the raw user-agent. */
function browser_label() {
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $dev = preg_match('/iPhone/i', $ua) ? 'iPhone' : (preg_match('/iPad/i', $ua) ? 'iPad' : (preg_match('/Android/i', $ua) ? 'Android' : (preg_match('/Macintosh|Mac OS X/i', $ua) ? 'Mac' : (preg_match('/Windows/i', $ua) ? 'Windows' : (preg_match('/Linux|X11/i', $ua) ? 'Linux' : 'A device')))));
    $app = preg_match('/Edg(e|A|iOS)?\//', $ua) ? 'Edge' : (preg_match('/OPR\/|Opera/', $ua) ? 'Opera' : (preg_match('/Firefox|FxiOS/', $ua) ? 'Firefox' : (preg_match('/CriOS|Chrome\//', $ua) ? 'Chrome' : (preg_match('/Safari\//', $ua) ? 'Safari' : ($ua === '' ? '' : 'a browser')))));
    return $dev . ($app !== '' ? ' · ' . $app : '');
}

function browser_cookie($token, $expiresAt) {
    if (headers_sent()) return;
    setcookie(REVEAL_COOKIE_NAME, $token, ['expires' => $expiresAt, 'path' => gallery_cookie_path(), 'secure' => is_https_request(), 'httponly' => true, 'samesite' => 'Lax']);
}

/** The signed-in browser this request is: ['hash', 'kind' => 'owner'|'view', 'rec'], or null (no cookie, not genuine, ended, expired, made under a
 *  password that has changed since, or signed out just now because of a flag). A request from a different address or as a different device
 *  than the browser's last one is noted in its history (browser_note_event); otherwise once an hour of use its last-seen time and end move on. */
function browser_lookup($reset = false) {
    static $found = null;
    if ($reset) { $found = null; return null; }
    if ($found !== null) return $found ?: null;
    $found = false;
    $c = $_COOKIE[REVEAL_COOKIE_NAME] ?? '';
    if (!is_string($c) || !preg_match('/^[0-9a-f]{64}$/', $c)) return null;
    $h = hash('sha256', $c);
    $r = json_file_read(browsers_path(), [])[$h] ?? null;
    if (!is_array($r) || !in_array($r['kind'] ?? '', ['owner', 'view'], true) || (int) ($r['exp'] ?? 0) <= time()) return null;
    $m = browser_marker($r['kind']);
    if ($m === '' || !hash_equals($m, (string) ($r['pw'] ?? ''))) return null;
    $found = ['hash' => $h, 'kind' => $r['kind'], 'rec' => $r];
    $now = time();
    if (($r['via'] ?? '') !== 'script' && !headers_sent()) {
        $ip = client_ip(); $lab = browser_label();
        if (!isset($r['ip']) || $ip !== $r['ip'] || $lab !== (string) ($r['lab'] ?? '')) {
            if (!browser_note_event($h, $ip, $lab)) { $found = false; return null; }
            return $found;
        }
    }
    if ($now - (int) ($r['seen'] ?? 0) >= 3600 && !headers_sent()) {
        $days = browser_days($r['kind']); $exp = $now + ($days >= 1 ? $days * 86400 : 86400);
        json_file_update(browsers_path(), [], function (&$d) use ($h, $now, $exp) { if (isset($d[$h])) { $d[$h]['seen'] = $now; $d[$h]['exp'] = $exp; } });
        if ($days >= 1) browser_cookie($c, $exp);
    }
    return $found;
}

/** A new token for this browser, after a sign-in or a password change. $stored is passed only then. This browser's earlier token goes (it is
 *  listed as signed in again); expired ones and ones made under another password are moved to the ended list by browser_housekeeping();
 *  at most sixty are kept (the most recently used), and ten of the one-hour tokens a script makes. */
function browser_issue($kind, $stored = null, $viaScript = false) {
    $marker = browser_marker($kind, $stored);
    if ($marker === '') return;
    install_secret(true);   // (the key the made files' names use; made at the first sign-in)
    $days = browser_days($kind); $now = time(); $exp = $now + ($days >= 1 ? $days * 86400 : 86400);
    if ($viaScript) $exp = min($exp, $now + 3600);   // (a request that carried the password itself, not a person at a browser: an hour is plenty)
    $token = bin2hex(random_bytes(32));
    $old = is_string($_COOKIE[REVEAL_COOKIE_NAME] ?? null) ? hash('sha256', $_COOKIE[REVEAL_COOKIE_NAME]) : '';
    $label = browser_label(); $ip = client_ip(); $cc = $viaScript ? '' : geo_country($ip); $newHash = hash('sha256', $token);
    $replaced = null;
    $rec = ['kind' => $kind, 'made' => $now, 'seen' => $now, 'exp' => $exp, 'label' => $label, 'pw' => $marker];
    $rec += $viaScript ? ['via' => 'script'] : ['lab0' => $label, 'lab' => $label, 'ip' => $ip, 'n' => 1, 'nets' => [signin_net_key($ip) => $now], 'peak' => 1, 'peakAt' => $now, 'ccs' => $cc !== '' ? [$cc] : [], 'flags' => []];
    json_file_update(browsers_path(), [], function (&$d) use ($newHash, $rec, $old, $viaScript, &$replaced) {
        if ($old !== '' && isset($d[$old])) { $replaced = $d[$old]; unset($d[$old]); }
        $d = [$newHash => $rec] + $d;   // (first, so that when several were made in the same second the newest is the one that stays)
        // A script that sends the password with every request makes a token each time: those are kept to the last ten, and never push a
        // person's browser out of the sixty.
        uasort($d, fn ($x, $y) => ($y['seen'] ?? 0) <=> ($x['seen'] ?? 0));
        $scripts = 0;
        foreach ($d as $k => $r) if (is_array($r) && ($r['via'] ?? '') === 'script' && ++$scripts > 10) unset($d[$k]);
        if (count($d) > 60) $d = array_slice($d, 0, 60, true);
    });
    if (!$viaScript) signin_history_append(substr($newHash, 0, 12), ['t' => $now, 'ip' => $ip, 'cc' => $cc, 'ua' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300), 'e' => 'signin']);
    if (is_array($replaced)) signin_ended_add(substr($old, 0, 12), $replaced, 'again');
    browser_cookie($token, $days >= 1 ? $exp : 0);
    $_COOKIE[REVEAL_COOKIE_NAME] = $token;   // (so the rest of this request already is that browser)
    browser_lookup(true);
    if ($stored === null) browser_housekeeping();   // (not right after a password was set or changed: the constants still hold the old value, which would make the token just made look old)
}

function browser_revoke_current() {
    $c = $_COOKIE[REVEAL_COOKIE_NAME] ?? '';
    if (is_string($c) && preg_match('/^[0-9a-f]{64}$/', $c) && is_file(browsers_path())) {
        $h = hash('sha256', $c); $gone = null;
        json_file_update(browsers_path(), [], function (&$d) use ($h, &$gone) { if (isset($d[$h])) { $gone = $d[$h]; unset($d[$h]); } });
        if (is_array($gone)) signin_ended_add(substr($h, 0, 12), $gone, 'logout');
    }
    gallery_cookie_expire(REVEAL_COOKIE_NAME, 'Lax');
    legacy_stay_forget();
    browser_lookup(true);
}

/** The signed-in browsers for the owner's list, newest use first. Each: id (the start of the hash), kind, label (what it was made on), lab (what it
 *  last said it was), made, seen, exp, ip (its last address) and its country, n (address changes), peak/peakAt (most networks in 24 hours), size
 *  of its history in bytes, flags (those showing), current (this very browser). */
function browser_rows() {
    browser_housekeeping();
    $me = browser_lookup(); $now = time(); $rows = [];
    foreach (json_file_read(browsers_path(), []) as $h => $r) {
        if (!is_array($r) || !in_array($r['kind'] ?? '', ['owner', 'view'], true) || (int) ($r['exp'] ?? 0) <= $now || ($r['via'] ?? '') === 'script') continue;
        $m = browser_marker($r['kind']);
        if ($m === '' || !hash_equals($m, (string) ($r['pw'] ?? ''))) continue;
        $id = substr((string) $h, 0, 12);
        $rows[] = ['id' => $id, 'kind' => $r['kind'], 'label' => (string) ($r['label'] ?? ''), 'lab' => (string) ($r['lab'] ?? $r['label'] ?? ''), 'made' => (int) ($r['made'] ?? 0), 'seen' => (int) ($r['seen'] ?? 0), 'exp' => (int) $r['exp'],
            'ip' => (string) ($r['ip'] ?? ''), 'n' => (int) ($r['n'] ?? 0), 'peak' => (int) ($r['peak'] ?? 0), 'peakAt' => (int) ($r['peakAt'] ?? 0), 'size' => signin_history_size($id),
            'ccs' => array_values((array) ($r['ccs'] ?? [])), 'flags' => browser_flags_of($r), 'current' => $me !== null && $me['hash'] === $h];
    }
    usort($rows, fn ($a, $b) => $b['seen'] <=> $a['seen']);
    return $rows;
}

function browser_cancel($id, $allOthers = false) {
    $me = browser_lookup(); $mine = $me['hash'] ?? ''; $gone = [];
    json_file_update(browsers_path(), [], function (&$d) use ($id, $allOthers, $mine, &$gone) {
        foreach (array_keys($d) as $h) {
            if ($h === $mine) continue;
            if ($allOthers || ($id !== '' && strncmp((string) $h, $id, strlen($id)) === 0)) { if (is_array($d[$h])) $gone[(string) $h] = $d[$h]; unset($d[$h]); }
        }
    });
    foreach ($gone as $h => $r) signin_ended_add(substr($h, 0, 12), $r, 'you');
    return count($gone);
}

/* LEGACY (see docs/LEGACY-REMOVAL.md): the "stay signed in" token of 2.3.2 lived in its own cookie and file (.gallery-stay.php). A
 * browser that still holds one is signed in by it once more and given a token of the new kind. */
function legacy_stay_path() {
    return __DIR__ . '/.gallery-stay.php';
}

/** True (and the old token used up) when this request carries a good 2.3.2 stay-signed-in token. */
function legacy_stay_take() {
    if (ADMIN_STAY_SIGNED_IN_DAYS < 1 || ADMIN_PASSWORD === '') return false;
    $c = $_COOKIE[ADMIN_STAY_COOKIE_NAME] ?? '';
    if (!is_string($c) || !preg_match('/^[0-9a-f]{64}$/', $c)) return false;
    $h = hash('sha256', $c);
    $r = json_file_read(legacy_stay_path(), [])[$h] ?? null;
    if (!is_array($r) || (int) ($r['exp'] ?? 0) <= time() || !hash_equals(admin_session_marker(), (string) ($r['pw'] ?? ''))) return false;
    legacy_stay_forget();
    return true;
}

/** Ends the 2.3.2 stay token this request carries, if any (its record and its cookie). */
function legacy_stay_forget() {
    $c = $_COOKIE[ADMIN_STAY_COOKIE_NAME] ?? '';
    if (is_string($c) && preg_match('/^[0-9a-f]{64}$/', $c) && is_file(legacy_stay_path())) {
        $h = hash('sha256', $c);
        json_file_update(legacy_stay_path(), [], function (&$d) use ($h) { unset($d[$h]); });
    }
    if ($c !== '') gallery_cookie_expire(ADMIN_STAY_COOKIE_NAME, 'Strict');
}

/** The session is gone but the owner's token is good (or a 2.3.2 one is, and is swapped for a new one): sign in again without the password. */
function browser_owner_may_resume() {
    if (ADMIN_STAY_SIGNED_IN_DAYS < 1) return false;
    $b = browser_lookup();
    if ($b !== null) return $b['kind'] === 'owner';
    if (!legacy_stay_take()) return false;
    browser_issue('owner');
    return true;
}

/** The path this gallery's cookies are kept to: the folder the script is installed in ("/trip" for /trip/index.php, so a blog or another
 *  application elsewhere on the same domain is never sent the gallery's sign-in cookies), or "/" for one installed at the top. Used only
 *  when the address the visitor typed really is under that folder; behind a rewrite or a proxy that maps another address onto the
 *  script it stays "/" (the cookie would otherwise never come back). */
function gallery_cookie_path() {
    static $path = null;
    if ($path !== null) return $path;
    $path = '/';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    if ($dir !== '' && preg_match('#^/[A-Za-z0-9._~/-]*$#', $dir)) {   // (a folder name with anything else in it keeps the whole domain: no escaping to get wrong)
        $uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        if ($uri === $dir || strncmp($uri, $dir . '/', strlen($dir) + 1) === 0) $path = $dir;
    }
    return $path;
}

/** Ends a cookie: expired on the gallery's own path and on "/" (where every cookie was kept before 2.4.0, and where a browser may still hold one). */
function gallery_cookie_expire($name, $sameSite) {
    foreach (array_unique([gallery_cookie_path(), '/']) as $path) {
        setcookie($name, '', ['expires' => 1, 'path' => $path, 'secure' => is_https_request(), 'httponly' => true, 'samesite' => $sameSite]);
    }
}

/** Starts (or resumes) the PHP session used to carry the admin CSRF
 *  token, with a 4-hour server-side lifetime — set here, before
 *  session_start(), so no server or php.ini change is needed. The
 *  browser-side cookie itself keeps no explicit expiry (a plain session
 *  cookie), so on its own a login lasts up to 4 hours without a visit, or until the browser
 *  closes; the owner's sign-in token (browser_*, ADMIN_STAY_SIGNED_IN_DAYS) brings a
 *  browser that lost the session back in without the password. Only ever
 *  called for ?admin requests — an ordinary visitor's page load never
 *  starts a session or sets this cookie. */
function admin_start_session() {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.gc_maxlifetime', 14400);
    // Reject a session id the server never issued (blocks fixation), and give
    // the admin session its own name: a gallery living inside another PHP
    // site would otherwise share — and clobber — that site's PHPSESSID.
    ini_set('session.use_strict_mode', '1');
    session_name(admin_session_name());
    // Secure matches is_https_request() rather than being hardcoded true
    // — the one exception this feature already makes for localhost
    // testing over plain HTTP would otherwise never get the cookie back
    // from the browser at all, since a Secure cookie is withheld from
    // any non-HTTPS request.
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => gallery_cookie_path(),
        'secure' => is_https_request(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function admin_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function admin_csrf_valid($submittedToken) {
    return isset($_SESSION['csrf_token']) && is_string($submittedToken)
        && hash_equals($_SESSION['csrf_token'], $submittedToken);
}

/** Checks the request's HTTP Basic Auth credentials against
 *  ADMIN_PASSWORD. Any username is accepted — there's only one account. */
function admin_credentials_valid() {
    $pass = $_SERVER['PHP_AUTH_PW'] ?? null;
    if (!is_string($pass)) return false;
    return password_matches(ADMIN_PASSWORD, $pass);
}

/** A request carried an HTTP Basic password that is wrong: a script's typo, or
 *  — more often — a browser re-sending a password it saved when an older
 *  version of this gallery asked for one. The answer is 401 (so a script can
 *  tell) with the ordinary login form, and never a WWW-Authenticate header:
 *  that header is what makes a browser open its own username/password dialog,
 *  and a stale saved password must not bring that dialog back. */
function admin_reject_saved_credentials() {
    http_response_code(401);
    render_login_page('Enter your access code', '', 'Your browser sent a saved login that no longer matches. Type your current access code.');
}

/** The password form for ?admin and ?reveal: one field, no username, no
 *  browser pop-up. Never indexed or cached or framed. $error is shown above
 *  the field after a wrong password. */
/** The query string of the page a visitor was on when they asked to sign in or out ("?folder=Paris&photo=...&map"), so they
 *  can be brought back to it. Only these three are carried; anything odd is dropped. */
function login_return_query() {
    $ok = function ($k) { return isset($_REQUEST[$k]) && is_string($_REQUEST[$k]) && $_REQUEST[$k] !== '' && strlen($_REQUEST[$k]) < 1024 && strpbrk($_REQUEST[$k], "\0\r\n") === false; };
    $parts = [];
    if ($ok('folder')) $parts[] = 'folder=' . rawurlencode($_REQUEST['folder']);
    if ($ok('photo')) $parts[] = 'photo=' . rawurlencode($_REQUEST['photo']);
    if (isset($_REQUEST['map'])) $parts[] = 'map';
    return $parts ? '?' . implode('&', $parts) : '';
}

/** The password typed into the sign-in form, or null if this request isn't that form being sent. The box is called
 *  access_code (not a name a password manager recognises); login_password is still read, for scripts and older pages. */
function login_form_password() {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return null;
    foreach (['access_code', 'login_password'] as $name) {
        if (isset($_POST[$name]) && is_string($_POST[$name])) return $name === 'access_code' ? str_replace(["\r", "\n"], '', $_POST[$name]) : $_POST[$name];
    }
    return null;
}

function render_login_page($heading, $blurb, $error = '') {
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Robots-Tag: noindex, nofollow');
    // (This page is deliberately bare: one large line drawing and one box. A heading, a "Continue" button or the words
    // "password" and "sign in" are what make a phone's Autofill decide it is a login form and offer a saved one.)
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Access</title>
<style>
