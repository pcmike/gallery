/* -----------------------------------------------------------------------
 * Admin auth: HTTP Basic Auth (checked with hash_equals — no timing
 * leak), an HTTPS requirement, a session-carried CSRF token for the
 * actual save actions, and a failed-attempt throttle shared with any
 * future second password this script grows (see ADMIN_PASSWORD's own
 * doc comment above; PRIVATE_VIEW_PASSWORD, when it exists, reuses this
 * unchanged).
 * ----------------------------------------------------------------------- */

/** True for a request this script considers already-local — the one
 *  exception to the HTTPS requirement below, so ?admin is still testable
 *  on a bare `php -S localhost:8000` during development. */
function is_local_request() {
    // Deliberately keyed off REMOTE_ADDR alone — the actual TCP peer
    // address, which a client can't forge in an ordinary request —
    // rather than also trusting the Host header. A request's Host header
    // is attacker-controlled: on a real public deployment, anyone could
    // send "Host: localhost" and bypass the HTTPS requirement entirely
    // if that were accepted here on its own.
    return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
}

/** True if this request arrived over HTTPS — checks the same three
 *  signals current_base_url() already does (a direct TLS connection, a
 *  trusted reverse-proxy header, or port 443), so a site behind a
 *  load balancer or CDN that terminates TLS upstream is still correctly
 *  recognized as HTTPS. */
function is_https_request() {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);
}

/**
 * Lightweight failed-login throttle, tracking failures per client IP in
 * a small flock-protected file at the gallery root — same mechanism the
 * view-stats JSON fallback already uses, nothing new conceptually,
 * shared across every password prompt this script has rather than one
 * per feature. 5 failures from one IP within 15 minutes forces a flat
 * 60-second lockout on that IP's *next* attempt — rejected outright,
 * without even checking the password it carries, matching "the next
 * attempt is not even evaluated." A flat delay, not a permanent
 * lockout: a legitimate visitor who fat-fingers a password a few times
 * just waits a minute, not calls the site owner.
 */
function login_throttle_path($rootDir) {
    return $rootDir . '/.gallery-login-attempts.php';
}

function login_throttle_key($realm, $ip) {
    // An IPv6 customer is given a whole /64 (billions of addresses) and can change address at will, so the /64 is the unit, not the address.
    $bin = ip_binary($ip);
    if ($bin !== null && strlen($bin) === 16) $ip = bin2hex(substr($bin, 0, 8)) . '/64';
    return $realm . '|' . $ip;
}

/** True if this IP is currently clear to even have its password
 *  checked for the given realm (e.g. 'admin'). */
function login_throttle_allows($rootDir, $realm, $ip) {
    $data = json_file_read(login_throttle_path($rootDir), []);
    $entry = $data[login_throttle_key($realm, $ip)] ?? null;
    if ($entry === null) return true;
    return time() >= ($entry['lockUntil'] ?? 0);
}

/** Seconds left on this realm's lockout for this address (0 when there is none). The lockout pages count these down. */
function login_throttle_wait($rootDir, $realm, $ip) {
    $data = json_file_read(login_throttle_path($rootDir), []);
    $entry = $data[login_throttle_key($realm, $ip)] ?? null;
    return $entry === null ? 0 : max(0, (int) ($entry['lockUntil'] ?? 0) - time());
}

/** Records one failed attempt; imposes a 60-second lockout once $limit (5 for a password, more for a guessed address) have
 *  landed from the same IP within the last 15 minutes. */
function login_throttle_record_failure($rootDir, $realm, $ip, $limit = 5) {
    $key = login_throttle_key($realm, $ip);
    $tripped = false;
    json_file_update(login_throttle_path($rootDir), [], function (&$data) use ($key, $limit, &$tripped) {
        $now = time();
        $entry = $data[$key] ?? ['attempts' => [], 'lockUntil' => 0];
        $entry['attempts'] = array_values(array_filter($entry['attempts'], function ($t) use ($now) {
            return $t > $now - 900; // keep only the last 15 minutes
        }));
        $entry['attempts'][] = $now;
        if (count($entry['attempts']) >= $limit) {
            $entry['lockUntil'] = $now + 60;
            $entry['attempts'] = [];
            $tripped = true;
        }
        $data[$key] = $entry;
        // The file is read on every sign-in and share-link visit, so it must not grow: forget whoever has no lockout running and no
        // recent failure, and keep at most the 2,000 most recent of the rest.
        foreach ($data as $k => $e) {
            $last = max((int) ($e['lockUntil'] ?? 0), (int) max(array_merge([0], (array) ($e['attempts'] ?? []))));
            if ($k !== $key && $last < $now - 900) unset($data[$k]);
        }
        if (count($data) > 2000) {
            uasort($data, function ($a, $b) { return max((int) ($b['lockUntil'] ?? 0), (int) max(array_merge([0], (array) ($b['attempts'] ?? [])))) <=> max((int) ($a['lockUntil'] ?? 0), (int) max(array_merge([0], (array) ($a['attempts'] ?? [])))); });
            $data = array_slice($data, 0, 2000, true);
        }
    });
    if ($tripped) lockout_noted($realm, $ip);   // (a lockout has just begun: it is written down, see lockout_noted)
}

/** Clears a realm/IP's failure history after a successful login. */
function login_throttle_record_success($rootDir, $realm, $ip) {
    $key = login_throttle_key($realm, $ip);
    json_file_update(login_throttle_path($rootDir), [], function (&$data) use ($key) {
        unset($data[$key]);
    });
}

/** Headers for every admin / private-view response: it can't be put in
 *  someone else's frame (clickjacking a "Hide folder" button), must never
 *  be cached (it lists everything, hidden items included), and its forms
 *  may only post back to this site. */
function send_sensitive_page_headers() {
    header('Cache-Control: no-store');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: frame-ancestors 'none'; base-uri 'none'; form-action 'self'; object-src 'none'");
}
/** For pages whose content differs by who is asking (the public gallery,
 *  the map, ?track): tell caches so, and never let one keep a copy made
 *  for a trusted viewer — it would list hidden items to the next
 *  anonymous request for the same URL. */
function send_viewer_aware_cache_headers() {
    header('Vary: Cookie');
    if (is_revealed() || link_viewer() !== null) {
        header('Cache-Control: private, no-store');
    }
}
function admin_session_name() {
    return 'gallery_admin_' . substr(hash('sha256', __DIR__), 0, 10);
}

/** What a logged-in admin session carries. Derived from the password and
 *  this install's folder, so changing ADMIN_PASSWORD logs every existing
 *  session out, and a session from another gallery means nothing here. */
function admin_session_marker($stored = null) {
    return hash('sha256', 'admin|' . ($stored ?? ADMIN_PASSWORD) . '|' . __DIR__);
}

