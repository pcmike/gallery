/* -----------------------------------------------------------------------
 * Where a visitor's IP address comes from. REMOTE_ADDR is the other end of
 * the connection, which behind a CDN (Cloudflare) is the CDN, so every
 * visitor looks like one of a few addresses. The real address arrives in a
 * header, but a header is whatever the sender says: it is only believed when
 * the connection itself comes from a proxy the owner has said to trust (see
 * TRUSTED_PROXY). Used by the share-link logs and the sign-in throttle; the
 * "is this request local" test deliberately stays on the raw connection.
 * ----------------------------------------------------------------------- */

/** An address as raw bytes (4 for IPv4, 16 for IPv6; an IPv4 address written as ::ffff:a.b.c.d becomes 4), or null. */
function ip_binary($ip) {
    $bin = is_string($ip) ? @inet_pton($ip) : false;
    if ($bin === false) return null;
    if (strlen($bin) === 16 && strncmp($bin, str_repeat("\0", 10) . "\xff\xff", 12) === 0) return substr($bin, 12);
    return $bin;
}

/** True if $ip lies in $cidr ("173.245.48.0/20", "2400:cb00::/32", or one bare address). */
function ip_in_cidr($ip, $cidr) {
    $ipBin = ip_binary($ip);
    if ($ipBin === null || !is_string($cidr)) return false;
    $parts = explode('/', $cidr, 2);
    $netBin = ip_binary($parts[0]);
    if ($netBin === null || strlen($netBin) !== strlen($ipBin)) return false;
    $bits = isset($parts[1]) ? (ctype_digit($parts[1]) ? (int) $parts[1] : -1) : strlen($netBin) * 8;
    if ($bits < 0 || $bits > strlen($netBin) * 8) return false;
    $whole = intdiv($bits, 8);
    if ($whole > 0 && strncmp($ipBin, $netBin, $whole) !== 0) return false;
    $rest = $bits % 8;
    if ($rest === 0) return true;
    $mask = (0xFF << (8 - $rest)) & 0xFF;
    return ((ord($ipBin[$whole]) ^ ord($netBin[$whole])) & $mask) === 0;
}

function ip_in_ranges($ip, $ranges) {
    foreach ($ranges as $r) if (ip_in_cidr($ip, $r)) return true;
    return false;
}

/** Cloudflare's published address ranges (https://www.cloudflare.com/ips/), as of October 2026. They change rarely; an
 *  address that isn't listed here can be added with TRUSTED_PROXY_RANGES. */
function cloudflare_ranges() {
    return ['173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
            '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
            '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
            '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32'];
}

/** The proxy addresses whose headers are believed under the current TRUSTED_PROXY setting. */
function trusted_proxy_ranges() {
    $own = array_values(array_filter((array) TRUSTED_PROXY_RANGES, 'is_string'));
    return TRUSTED_PROXY === 'cloudflare' ? array_merge(cloudflare_ranges(), $own) : $own;
}

/** The visitor's address: the connection's, unless it comes from a trusted proxy, which says who is behind it. */
function client_ip() {
    static $ip = null;
    if ($ip !== null) return $ip;
    $peer = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $ip = $peer;
    if ($peer !== '' && TRUSTED_PROXY !== 'direct' && ip_in_ranges($peer, trusted_proxy_ranges())) {
        if (TRUSTED_PROXY === 'cloudflare') {
            $header = trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
            if (filter_var($header, FILTER_VALIDATE_IP)) $ip = $header;
        } else {
            // The proxies add the address they received from to the right-hand end: the client is the first one, reading
            // from the right, that is not itself a trusted proxy.
            $chain = array_map('trim', explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')));
            for ($i = count($chain) - 1; $i >= 0; $i--) {
                if (!filter_var($chain[$i], FILTER_VALIDATE_IP)) break;
                $ip = $chain[$i];
                if (!ip_in_ranges($chain[$i], trusted_proxy_ranges())) break;
            }
        }
    }
    return $ip === '' ? 'unknown' : $ip;
}

/** What to tell the owner when "The connection" is chosen but the request looks like it came through a proxy: only when the
 *  connection itself is a proxy's (inside Cloudflare's ranges, or a private or loopback address with a forwarding header), since a
 *  web server that already passes the real address on (nginx's real-ip rule) leaves a Cloudflare header behind and is right as it is. */
function client_ip_proxy_hint() {
    if (TRUSTED_PROXY !== 'direct') return '';
    $peer = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (!filter_var($peer, FILTER_VALIDATE_IP)) return '';
    $cf = trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '')) !== '';
    $fwd = $cf || trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')) !== '';
    if ($cf && ip_in_ranges($peer, cloudflare_ranges())) return 'This request came through Cloudflare: choose Cloudflare so the real address is used.';
    $private = !filter_var($peer, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    if ($fwd && $private && !is_local_request()) return 'This request came through a proxy: choose the matching setting so the real address is used.';
    return '';
}

/** The country of this visitor ("US"), or '': what a trusted proxy says (Cloudflare's header; CloudFront's and Vercel's when the proxy setting is "another
 *  proxy" and the connection comes from one you named), else the country file when that option is on. */
function client_country() {
    static $c = null;
    if ($c !== null) return $c;
    $peer = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $h = '';
    if ($peer !== '' && TRUSTED_PROXY !== 'direct' && ip_in_ranges($peer, trusted_proxy_ranges())) {
        $h = TRUSTED_PROXY === 'cloudflare' ? (string) ($_SERVER['HTTP_CF_IPCOUNTRY'] ?? '') : (string) ($_SERVER['HTTP_CLOUDFRONT_VIEWER_COUNTRY'] ?? $_SERVER['HTTP_X_VERCEL_IP_COUNTRY'] ?? '');
    }
    $h = strtoupper(trim($h));
    if (!preg_match('/^[A-Z]{2}$/', $h) || $h === 'XX' || $h === 'T1') $h = '';
    return $c = $h !== '' ? $h : geo_db_country(client_ip());
}

/* -----------------------------------------------------------------------
 * Share links. A link is a record in .gallery-links.php (a guarded data file
 * beside this script, like the others): who it is for, what it may show, how
 * many times it may be opened and until when. Opening the address
 * (?link=<token>, or the tidy /<token> the server rule maps onto it) shows a page with one button; pressing it
 * counts a use (unless this browser, or this address a moment ago, already
 * did) and sets a cookie that is checked against the record on every request,
 * so pausing, revoking or ending a link cuts off people already in. A person
 * holding a link is never the owner, and never sees more than the link says.
 * ----------------------------------------------------------------------- */


function links_path() { return __DIR__ . '/.gallery-links.php'; }
function links_default() { return ['links' => [], 'folderIndex' => [], 'seenAt' => 0]; }
function links_read() { return json_file_read(links_path(), links_default()); }
function links_update($modifier) { return json_file_update(links_path(), links_default(), $modifier, true); }

/** A random 16-character token of letters and digits (about 95 bits), with no modulo bias. */
function link_new_token() {
    $alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    $token = '';
    while (strlen($token) < LINK_TOKEN_LENGTH) {
        $v = ord(random_bytes(1));
        if ($v < 248) $token .= $alphabet[$v % 62];   // 248 = 4 x 62
    }
    return $token;
}
function link_new_id() { return bin2hex(random_bytes(5)); }
/** An address a link can have: the random token, or a custom name (4 to 40 letters, digits and hyphens, none at either end). */
function link_token_format_ok($t) { return is_string($t) && preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{2,38})[A-Za-z0-9]$/', $t) === 1; }

/** The name of a folder or file at the gallery's top level that has this custom link name (ignoring case), or ''. A link added before the folder
 *  (an FTP upload later) is fine with the current server rule, but worth saying, and a clue when an older rule is still in place. */
function link_name_shadowed_by($name, $dir) {
    $n = strtolower((string) $name);
    foreach ((array) @scandir($dir) as $entry) if ($entry !== '.' && $entry !== '..' && strtolower($entry) === $n) return $entry;
    foreach (array_keys(gallery_mounts()) as $mountName) if (strtolower((string) $mountName) === $n) return (string) $mountName;
    return '';
}

/** What is wrong with this custom name, in words, or '' when it can be used: the shape, the reserved words, a folder or file of
 *  the gallery's top level, or another link's address (current or replaced). $exceptId is the link being edited. */
function link_custom_name_problem($name, $dir, $exceptId = '') {
    $n = strtolower($name);
    if (strlen($n) < 4 || strlen($n) > 40 || preg_match('/^[a-z0-9][a-z0-9-]*[a-z0-9]$/', $n) !== 1) return 'An address needs 4 to 40 letters, digits or hyphens, and cannot start or end with a hyphen.';
    if (in_array($n, LINK_RESERVED_NAMES, true)) return '"' . $n . '" is an address the gallery uses itself: pick another.';
    foreach ([$dir, __DIR__] as $d) {
        foreach ((array) @scandir($d) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            if (strtolower($entry) === $n || strtolower(pathinfo($entry, PATHINFO_FILENAME)) === $n && pathinfo($entry, PATHINFO_EXTENSION) === 'php') return 'A folder or file called "' . $entry . '" is in the gallery, and it would hide this address: pick another.';
        }
    }
    foreach (array_keys(gallery_mounts()) as $mountName) {
        if (strtolower((string) $mountName) === $n) return 'A folder called "' . $mountName . '" is in the gallery, and it would hide this address: pick another.';
    }
    foreach ((array) (links_read()['links'] ?? []) as $l) {
        if (!is_array($l) || ($l['id'] ?? '') === $exceptId) continue;
        foreach (link_all_addresses($l) as $t) {
            if (strtolower($t) === $n) return 'Another link has (or had) that address: pick another.';
        }
    }
    return '';
}

/** A new link record with every field at its default. */
function link_record_new($now) {
    return [
        'id' => link_new_id(), 'label' => '', 'token' => link_new_token(), 'custom' => false, 'status' => 'active', 'created' => $now,
        'startsAt' => 0, 'endsAt' => 0, 'maxOpens' => 0, 'opens' => 0,
        'note' => '', 'titleMode' => 'gallery', 'titleText' => '', 'picture' => false, 'pictureSeed' => '', 'pictureFile' => '',
        'scope' => ['type' => 'all', 'folders' => [], 'photos' => []], 'openIn' => '',
        'downloads' => ['mode' => 'off', 'folders' => []], 'zip' => ['mode' => 'off', 'folders' => []],
        'locations' => 'public', 'headsUp' => 'default',
        'lastOpened' => 0, 'lastOpen' => ['ip' => '', 'at' => 0], 'revokedAt' => 0, 'pausedAt' => 0,
    ];
}

/** The link with this token (compared in constant time against every link), or null. */
function link_find_by_token($data, $token) {
    $found = null;
    foreach ((array) ($data['links'] ?? []) as $l) {
        if (!is_array($l) || link_is_public_log($l) || !isset($l['token']) || !is_string($l['token'])) continue;
        // (a custom name is stored in lower case and answers in any case; a random token is exact)
        if (!empty($l['custom']) ? hash_equals(strtolower($l['token']), strtolower((string) $token)) : hash_equals($l['token'], (string) $token)) $found = $l;
    }
    return $found;
}

/** Every address a link answers to or holds: its own, the replaced ones and the ones kept working. */
function link_all_addresses($l) {
    $out = [(string) ($l['token'] ?? '')];
    foreach ((array) ($l['oldTokens'] ?? []) as $t) $out[] = (string) $t;
    foreach ((array) ($l['aliases'] ?? []) as $a) if (is_array($a)) $out[] = (string) ($a['name'] ?? '');
    return array_values(array_filter($out, function ($t) { return $t !== ''; }));
}

/** [the link, the kept address it was reached by] for an old address that was kept working, or null. */
function link_find_by_alias($data, $token) {
    $found = null;
    foreach ((array) ($data['links'] ?? []) as $l) {
        if (!is_array($l) || link_is_public_log($l)) continue;
        foreach ((array) ($l['aliases'] ?? []) as $a) {
            if (!is_array($a) || !is_string($a['name'] ?? null)) continue;
            if (!empty($a['custom']) ? hash_equals(strtolower($a['name']), strtolower((string) $token)) : hash_equals($a['name'], (string) $token)) $found = [$l, $a['name']];
        }
    }
    return $found;
}

/** True for an address that looks like a name of the owner's own; a random code is 16 letters and digits with a capital among them. */
function link_address_is_custom($name) {
    $name = (string) $name;
    return !(preg_match('/^[A-Za-z0-9]{16}$/', $name) === 1 && preg_match('/[A-Z]/', $name) === 1);
}

/** An address as it is shown in the log: a name of your own whole, a random code as its first four and last four characters. */
function link_address_label($name, $custom) {
    $name = (string) $name;
    return $custom || strlen($name) < 9 ? $name : substr($name, 0, 4) . '…' . substr($name, -4);
}

/** Does the Visitors list need to say which address each visitor came in on? Yes once a link has had a second one working. */
function link_has_many_addresses($l, $log) {
    return !link_is_public_log($l) && (!empty($l['aliases']) || !empty($log['via']));
}

/** How a visitor came in: the kept addresses they used; else, if they came after the link got a second address, the present one; else '' (before that, nobody noted it). */
function link_visitor_via($l, $log, $vid, $v) {
    $e = $log['via'][$vid] ?? null;
    if (is_array($e) && !empty($e['n'])) {
        $parts = [];
        foreach (array_keys($e['n']) as $n) $parts[] = link_address_label($n, link_address_is_custom($n));
        return implode(', ', $parts);
    }
    $since = (int) ($l['aliasSince'] ?? 0);
    if ($since > 0 && (int) ($v['first'] ?? 0) > $since) return link_address_label($l['token'], !empty($l['custom']));
    return '';
}

/** Counts one use of a kept address (how many times, and when last), so the owner can tell whether it can go. */
function link_alias_used($id, $name) {
    links_update(function (&$d) use ($id, $name) {
        foreach ((array) ($d['links'][$id]['aliases'] ?? []) as $i => $a) {
            if (is_array($a) && ($a['name'] ?? '') === $name) { $d['links'][$id]['aliases'][$i]['hits'] = (int) ($a['hits'] ?? 0) + 1; $d['links'][$id]['aliases'][$i]['last'] = time(); }
        }
    });
}

/** Notes in the link's log that this visitor used one of the link's old addresses: 'via' for a kept one (the visitor is sent on and opens the
 *  link on the present address, so this is the only place it shows), 'tried' for a paused one (they saw the "not valid" page). */
function link_note_address($link, $kind, $name) {
    [$vid, $ip] = link_visitor_identity();
    $now = time();
    link_log_update($link['id'], function (&$log) use ($kind, $vid, $ip, $name, $now) {
        $all = (array) ($log[$kind] ?? []);
        if (!isset($all[$vid]) && count($all) >= LINK_LOG_VISITORS) { uasort($all, function ($a, $b) { return (int) ($a['last'] ?? 0) <=> (int) ($b['last'] ?? 0); }); array_shift($all); }
        $e = $all[$vid] ?? ['ip' => $ip, 'n' => [], 'last' => $now];
        $e['n'][$name] = (int) ($e['n'][$name] ?? 0) + 1; $e['last'] = $now;
        $all[$vid] = $e; $log[$kind] = $all;
    });
}

/** What the log says about one old address: [uses, distinct IPs, last time] from its 'via' notes (a kept address) or 'tried' notes (a paused one). */
function link_address_log_stats($log, $kind, $name) {
    $uses = 0; $ips = []; $last = 0;
    foreach ((array) ($log[$kind] ?? []) as $e) {
        if (!isset($e['n'][$name])) continue;
        $uses += (int) $e['n'][$name]; $ips[(string) ($e['ip'] ?? '')] = true; $last = max($last, (int) ($e['last'] ?? 0));
    }
    return [$uses, count($ips), $last];
}

/** The stored spelling of the replaced address that matches this one (a custom name answers in any case), or ''. */
function link_old_name($link, $token) {
    foreach ((array) ($link['oldTokens'] ?? []) as $old) if (is_string($old) && hash_equals(strtolower($old), strtolower((string) $token))) return $old;
    return '';
}

/** The link that used to have this address (before "Replace address"), or null. */
function link_find_by_old_token($data, $token) {
    $found = null;
    foreach ((array) ($data['links'] ?? []) as $l) {
        foreach ((array) ($l['oldTokens'] ?? []) as $old) if (is_string($old) && hash_equals(strtolower($old), strtolower((string) $token))) $found = $l;
    }
    return $found;
}

/** What a link is right now: active, paused, revoked, scheduled (not started), expired, used_up (no opens left). */
function link_state($l, $now = null) {
    $now = $now ?? time();
    $status = $l['status'] ?? 'active';
    if ($status === 'revoked') return 'revoked';
    if ($status === 'paused') return 'paused';
    if ((int) ($l['endsAt'] ?? 0) > 0 && $now >= (int) $l['endsAt']) return 'expired';
    if ((int) ($l['startsAt'] ?? 0) > $now) return 'scheduled';
    if ((int) ($l['maxOpens'] ?? 0) > 0 && (int) ($l['opens'] ?? 0) >= (int) $l['maxOpens']) return 'used_up';
    return 'active';
}
/** Someone already signed in through the link keeps access while it is active or only out of opens. */
function link_is_live($l, $now = null) { return in_array(link_state($l, $now), ['active', 'used_up'], true); }

/* The public address's log. It is kept as one more record in the links file (so the Shares tab's log pages, the list of IP addresses and the
 * CSV downloads need nothing special), with this fixed id, no address and the scope "just the public gallery". It is never a link
 * anyone can use: nothing looks it up by address or by cookie. */
function link_is_public_log($l) { return is_array($l) && ($l['kind'] ?? '') === 'public'; }

/** Is this visitor one the public address's log should record? Not the owner, nor the private-view password, nor someone using a share
 *  link (they have their own log), nor a link-preview bot. */
function public_log_wanted() {
    static $w = null;
    if ($w !== null) return $w;
    return $w = ENABLE_SHARE_LINKS && LOG_PUBLIC_VISITS && link_viewer() === null && !is_revealed() && !is_admin_browser() && link_preview() === null && !is_bot_request();
}

/** How this page was reached, for the public address's log: an arrival (an address typed or pasted, a bookmark, a link from another site or an app: the
 *  browser says it was not a click inside the gallery) as [folder path ('' is the front page) or, for a Not listed photo opened by its address, the photo,
 *  how, whether it was that photo]; null for a click inside the gallery, or a browser that doesn't say. */
function public_log_arrival() {
    if (!public_log_wanted()) return null;
    $site = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
    if ($site === '' || $site === 'same-origin') return null;
    $how = $site === 'none' ? 'typed' : (in_array($site, ['cross-site', 'same-site'], true) ? 'link' : 'other');
    $pass = $GLOBALS['photoPass'] ?? null;
    if ($pass !== null) return ['f' => $pass, 'h' => $how, 'u' => true];
    $folder = $GLOBALS['currentFolderPath'] ?? null;
    return ['f' => $folder === null ? '' : $folder, 'h' => $how];
}

/** The log's record, made the first time something is logged. */
function public_log_link() {
    $l = links_read()['links'][PUBLIC_LOG_ID] ?? null;
    if (link_is_public_log($l)) return $l;
    $made = null; $now = time();
    links_update(function (&$d) use ($now, &$made) {
        if (!isset($d['links'][PUBLIC_LOG_ID])) {
            $d['links'][PUBLIC_LOG_ID] = array_replace(link_record_new($now), ['id' => PUBLIC_LOG_ID, 'label' => 'Public address', 'token' => '', 'kind' => 'public', 'scope' => ['type' => 'public', 'folders' => [], 'photos' => []]]);
        }
        $made = $d['links'][PUBLIC_LOG_ID];
    });
    return link_is_public_log($made) ? $made : null;
}

function link_cookie_value($l) {
    return $l['id'] . '.' . hash_hmac('sha256', 'link|' . $l['id'] . '|' . $l['token'] . '|' . __DIR__, install_secret());
}
function link_set_cookie($l) {
    install_secret(true);
    $expires = time() + 31536000;
    if ((int) ($l['endsAt'] ?? 0) > 0) $expires = min($expires, (int) $l['endsAt']);
    setcookie(LINK_COOKIE_NAME, link_cookie_value($l), ['expires' => $expires, 'path' => gallery_cookie_path(), 'secure' => is_https_request(), 'httponly' => true, 'samesite' => 'Lax']);
}

/** The link record this request is signed in through, or null. Reads the links file only when the cookie is there, so an
 *  ordinary visitor costs nothing. The owner's own browser is never a link holder. */
function link_viewer() {
    static $resolved = false, $link = null;
    if ($resolved) return $link;
    $resolved = true;
    if (!ENABLE_SHARE_LINKS) return null;
    if (($previewed = link_preview()) !== null) return $link = $previewed;
    $cookie = $_COOKIE[LINK_COOKIE_NAME] ?? '';
    if (!is_string($cookie) || !preg_match('/^([0-9a-f]{10})\.[0-9a-f]{64}$/', $cookie, $m)) return null;
    if (is_admin_browser()) return null;
    $l = links_read()['links'][$m[1]] ?? null;
    if (!is_array($l) || link_is_public_log($l) || !isset($l['token']) || !hash_equals(link_cookie_value($l), $cookie) || !link_is_live($l)) return null;
    return $link = $l;
}
/** A person using a share link, and not someone with the owner's or the private-view sign-in. */
function viewer_is_link() { return !is_revealed() && link_viewer() !== null; }

/* Preview as this link. The owner presses it on the Shares tab; a signed cookie then makes this browser see the gallery exactly as
 * someone using that link does (the same checks, the same pages, no owner tools), and nothing is counted or logged. It never touches
 * the admin pages (?admin keeps the owner's sign-in), a thumbnail asked for by an admin page is still the owner's, and it ends by
 * itself after four hours or when "Exit preview" is pressed. */
function preview_cookie_value($l, $ts) {
    return $l['id'] . '.' . (int) $ts . '.' . hash_hmac('sha256', 'preview|' . $l['id'] . '|' . (int) $ts . '|' . $l['token'] . '|' . __DIR__, install_secret());
}
function preview_set_cookie($l) {
    install_secret(true);
    $now = time();
    setcookie(PREVIEW_COOKIE_NAME, preview_cookie_value($l, $now), ['expires' => $now + PREVIEW_SECONDS, 'path' => gallery_cookie_path(), 'secure' => is_https_request(), 'httponly' => true, 'samesite' => 'Lax']);
}
function preview_clear_cookie() {
    gallery_cookie_expire(PREVIEW_COOKIE_NAME, 'Lax');
}
/** True when the page that asked for this picture or video is one of the admin's own pages (so the owner gets the owner's version of it). */
function preview_referer_is_admin() {
    $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    if ($ref === '') return false;
    $query = parse_url($ref, PHP_URL_QUERY); $host = parse_url($ref, PHP_URL_HOST); $mine = parse_url('//' . (string) ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
    if (!is_string($query) || !is_string($host) || strcasecmp($host, (string) $mine) !== 0) return false;
    parse_str($query, $q);
    return array_key_exists('admin', $q);
}
/** The link a preview cookie in this browser belongs to, if the cookie is genuine, fresh and the link isn't revoked, whatever page is being asked for. */
function preview_cookie_link() {
    static $resolved = false, $link = null;
    if ($resolved) return $link;
    $resolved = true;
    if (!ENABLE_SHARE_LINKS) return null;
    $cookie = $_COOKIE[PREVIEW_COOKIE_NAME] ?? '';
    if (!is_string($cookie) || !preg_match('/^([0-9a-f]{10})\.(\d{1,12})\.[0-9a-f]{64}$/', $cookie, $m)) return null;
    $ts = (int) $m[2];
    if ($ts > time() + 60 || time() - $ts > PREVIEW_SECONDS) return null;
    $l = links_read()['links'][$m[1]] ?? null;
    if (!is_array($l) || !isset($l['token']) || !hash_equals(preview_cookie_value($l, $ts), $cookie) || link_state($l) === 'revoked') return null;
    return $link = $l;
}
/** The link the owner is previewing, as far as THIS request is concerned: gallery and media pages only. The admin's own pages are always the
 *  owner's, and so is a picture or video that an admin page asked for. */
function link_preview() {
    static $resolved = false, $link = null;
    if ($resolved) return $link;
    $resolved = true;
    if (isset($_GET['admin'])) return null;
    if ((isset($_GET['img']) || isset($_GET['video']) || isset($_GET['strip']) || isset($_GET['preview'])) && preview_referer_is_admin()) return null;
    return $link = preview_cookie_link();
}
/** The strip across the top of a previewed gallery: who it is a preview of, a list of what the link includes, and the way out. */
function render_link_preview_banner($l, $dir, $files) {
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $map = folder_id_map($dir, $files);
    $index = (array) (links_read()['folderIndex'] ?? []);
    $rootFid = folder_id_read($dir);
    $report = link_scope_report($l, $map, $index, $dir, $rootFid);
    $state = link_state($l);
    $type = $l['scope']['type'] ?? 'all';
    $yes = function ($html) { return '<li class="yes">' . ui_icon('check') . '<span>' . $html . '</span></li>'; };
    $no = function ($html) { return '<li class="no">' . ui_icon('close') . '<span>' . $html . '</span></li>'; };
    $items = [];
    if ($type !== 'public') $items[] = $yes(($type === 'all' ? 'The whole gallery, public and private, including what you add later' : ($type === 'folders' && !array_filter(array_column($report['folders'], 'pub')) ? $esc(implode(', ', array_map(function ($x) { return $x['label']; }, $report['folders']))) . ' and everything inside ' . (count($report['folders']) === 1 ? 'it' : 'them') : link_scope_text_html($l, $report))));
    $items[] = $yes('All public folders and photos, as any visitor sees them');
    if ($type === 'public') $items[] = $no('<strong>Nothing private</strong> is shown or hinted at');
    elseif ($type !== 'all') $items[] = $no('Your other private folders and photos are <strong>not shown and not hinted at</strong>');
    if (array_filter(array_column($report['folders'], 'pub'))) $items[] = $no('Private photos and videos in the folders marked <strong>public items only</strong> are not shown (unless you named one)');
    foreach (['downloads' => ['Originals can be downloaded: ', 'Original downloads are off.'], 'zip' => ['ZIP of a folder: ', 'ZIP downloads are off.']] as $cap => [$onText, $offText]) {
        if (($l[$cap]['mode'] ?? 'off') === 'off') $items[] = $no($offText);
        else $items[] = $yes($onText . strtolower(link_capability_text_html($l, $cap, $report, $map, $index, $dir, $rootFid)));
    }
    $items[] = $yes(($l['locations'] ?? 'public') === 'all' ? 'Locations: every saved location is shown' : 'Locations: only the ones set to show');
    $quiet = $state === 'active' || $state === 'used_up' ? '' : ' This link is ' . $esc(strtolower(link_state_label($state))) . ' right now, so the people who have it see nothing until that changes.';
    return '<div class="link-preview" id="link-preview" role="status"><div class="lp-bar">' . ui_icon('eye')
        . '<span class="lp-t"><strong>Previewing as ' . $esc($l['label']) . '.</strong> You see exactly what this link shows. Nothing you do here is counted or logged.' . $quiet . '</span>'
        . '<button type="button" class="lp-more" aria-expanded="false" aria-controls="lp-panel">What\'s included ' . ui_icon('chev-down') . '</button>'
        . '<a class="lp-exit" href="?exitpreview">Exit preview</a></div>'
        . '<div class="lp-panel" id="lp-panel" hidden><h3>What this link includes</h3><ul>' . implode('', $items) . '</ul><p class="lp-note">Pressing Exit preview ends the preview and takes you back to the Shares tab.</p></div></div>'
        . '<script>(function(){var b=document.querySelector("#link-preview .lp-more"),p=document.getElementById("lp-panel");if(!b||!p)return;b.addEventListener("click",function(){var o=p.hidden;p.hidden=!o;b.setAttribute("aria-expanded",o?"true":"false");});})();</script>';
}

/** Whether a link shows the "expires on" line at the top of the gallery: its own choice, else the site setting; never when it has no end date. */
function link_headsup_on($l) {
    $h = $l['headsUp'] ?? 'default';
    return ($h === 'on' || ($h === 'default' && SHARE_HEADS_UP)) && (int) ($l['endsAt'] ?? 0) > 0;
}

/** The thin line itself. The page's script puts the time in the visitor's own zone, turns it amber in the last day and remembers a dismissal. */
function render_link_headsup($l) {
    $ends = (int) $l['endsAt'];
    return '<div class="link-headsup" id="link-headsup" role="status" data-ends="' . $ends . '" data-key="' . htmlspecialchars($l['id'] . '.' . $ends, ENT_QUOTES, 'UTF-8') . '">'
        . '<span class="lh-t">The link being used to access this media expires on <time datetime="' . gmdate('c', $ends) . '">' . htmlspecialchars(gmdate('D, M j, g:i a', $ends) . ' UTC', ENT_QUOTES, 'UTF-8') . '</time>.</span>'
        . '<button type="button" class="lh-x" aria-label="Dismiss" title="Dismiss">&times;</button></div>';
}

/* A folder's hidden id. A share link limited to chosen folders remembers each by an id kept in the folder's own settings file
 * (stamped the first time a link covers it), not by its name: renaming or moving the folder keeps the link working, a folder
 * deleted or wiped is simply gone, and a new folder that happens to reuse the old name has no id and is not covered. */

