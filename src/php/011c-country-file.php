/* -----------------------------------------------------------------------
 * The country of an address. A proxy in front (Cloudflare and a few others) can say which country a visitor is in; almost nobody has one.
 * So there is an option that works anywhere: a small file of address ranges per country, kept in a guarded .gallery- file beside this one
 * and read by a short reader below (the MaxMind DB format, which DB-IP publishes free as "IP to Country Lite", CC BY 4.0, a new one on the
 * first of each month: https://db-ip.com/db/download/ip-to-country-lite). Nothing about a visitor ever leaves the server: the only outside
 * request is the download of that public file, made when the owner turns the option on and then about once a month (it checks every 7
 * days whether there is a newer one) from an ordinary page load, after the visitor has been answered where PHP allows, never making
 * a visitor wait. It is off until the owner switches it on in Settings, which first says how much room it needs.
 * ----------------------------------------------------------------------- */

function geo_db_path() {
    return __DIR__ . '/.gallery-geo.php';
}
function geo_state_path() {
    return __DIR__ . '/.gallery-geo-state.php';
}

/** The state of the file we hold: ['ym' => '2026-11', 'modified' => the Last-Modified it came with, 'length' => bytes it came as, 'at' => when we got it,
 *  'error' => why the last try failed or '', 'triedAt' => when the last try was, 'raw' => bytes unpacked]. */
function geo_state() {
    return json_file_read(geo_state_path(), ['ym' => '', 'modified' => '', 'length' => 0, 'at' => 0, 'error' => '', 'triedAt' => 0, 'raw' => 0]);
}

/** An address a download may come from: https anywhere, or plain http only to this very machine (for tests). */
function geo_url_ok($url) {
    $p = parse_url((string) $url);
    if (!is_array($p) || empty($p['host']) || isset($p['user']) || isset($p['pass'])) return false;
    if (($p['scheme'] ?? '') === 'https') return true;
    return ($p['scheme'] ?? '') === 'http' && in_array(strtolower($p['host']), ['127.0.0.1', 'localhost', '[::1]'], true);
}

/** The address of a month's file: the setting COUNTRY_DB_URL with {ym} replaced by the month ("2026-11"), or DB-IP's own. */
function geo_url_for($ym) {
    $base = COUNTRY_DB_URL !== '' ? COUNTRY_DB_URL : 'https://download.db-ip.com/free/dbip-country-lite-{ym}.mmdb.gz';
    return str_replace('{ym}', $ym, $base);
}

/** The months to try, newest first: this one (the file appears on the 1st), then last month's. */
function geo_months() {
    return [gmdate('Y-m'), gmdate('Y-m', strtotime('first day of last month 00:00:00 UTC'))];
}

/** One request, with curl if this PHP has it, else PHP's own streams. Returns ['status' => int (0: no answer), 'headers' => [lowercase name => value], 'body' => string, 'error' => text]. */
function geo_http($url, $method = 'GET', array $headers = [], $maxBytes = 30000000, $timeout = 40) {
    $out = ['status' => 0, 'headers' => [], 'body' => '', 'error' => ''];
    if (!geo_url_ok($url)) { $out['error'] = 'That address is not allowed (it must be https).'; return $out; }
    if (function_exists('curl_init')) {
        $ch = curl_init($url); $hd = [];
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_NOBODY => $method === 'HEAD',
            CURLOPT_HTTPHEADER => $headers, CURLOPT_USERAGENT => 'Drop-in Photo Gallery ' . GALLERY_VERSION, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_HEADERFUNCTION => function ($c, $l) use (&$hd) { if (strpos($l, ':') !== false) { [$k, $v] = explode(':', $l, 2); $hd[strtolower(trim($k))] = trim($v); } return strlen($l); },
            CURLOPT_WRITEFUNCTION => function ($c, $d) use (&$out, $maxBytes) { $out['body'] .= $d; return strlen($out['body']) > $maxBytes ? 0 : strlen($d); }]);
        curl_exec($ch);
        $out['status'] = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $out['headers'] = $hd;
        if (curl_errno($ch) !== 0 && $out['status'] === 0) $out['error'] = curl_error($ch);
        curl_close($ch);
        return $out;
    }
    if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) { $out['error'] = 'This server does not allow PHP to fetch addresses (allow_url_fopen is off and curl is not installed).'; return $out; }
    $ctx = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", array_merge($headers, ['User-Agent: Drop-in Photo Gallery ' . GALLERY_VERSION])), 'timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => 1, 'max_redirects' => 3]]);
    $fh = @fopen($url, 'rb', false, $ctx);
    if (!$fh) { $out['error'] = 'Could not connect.'; return $out; }
    $meta = stream_get_meta_data($fh);
    foreach ((array) ($meta['wrapper_data'] ?? []) as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) { $out['status'] = (int) $m[1]; $out['headers'] = []; }
        elseif (strpos($line, ':') !== false) { [$k, $v] = explode(':', $line, 2); $out['headers'][strtolower(trim($k))] = trim($v); }
    }
    if ($method !== 'HEAD') while (!feof($fh) && strlen($out['body']) <= $maxBytes) { $d = fread($fh, 65536); if ($d === false || $d === '') break; $out['body'] .= $d; }
    fclose($fh);
    return $out;
}

/**
 * What the file for the newest month would need and say, without downloading it: ['ok' => true, 'ym', 'gz' => bytes to download, 'raw' => bytes unpacked,
 * 'exact' => whether 'raw' was read from the file (the last four bytes of a gzip file say how big it unpacks to) or only estimated, 'modified', 'url']
 * or ['ok' => false, 'error' => why].
 */
function geo_probe() {
    $err = 'Could not reach the country list.';
    foreach (geo_months() as $ym) {
        $url = geo_url_for($ym);
        $h = geo_http($url, 'HEAD', [], 1000, 12);
        if ($h['status'] === 0) { $err = $h['error'] !== '' ? $h['error'] : $err; break; }
        if ($h['status'] !== 200) { $err = 'The file for ' . $ym . ' is not there yet (' . $h['status'] . ').'; continue; }
        $gz = (int) ($h['headers']['content-length'] ?? 0);
        $raw = null;
        if ($gz > 4) {
            $t = geo_http($url, 'GET', ['Range: bytes=' . ($gz - 4) . '-' . ($gz - 1)], 64, 12);
            if ($t['status'] === 206 && strlen($t['body']) === 4) $raw = (int) unpack('V', $t['body'])[1];
        }
        return ['ok' => true, 'ym' => $ym, 'gz' => $gz, 'raw' => $raw !== null ? $raw : (int) round($gz * 2.03), 'exact' => $raw !== null, 'modified' => (string) ($h['headers']['last-modified'] ?? ''), 'url' => $url];
    }
    return ['ok' => false, 'error' => $err];
}

/** Writes the settings file with $changes merged in, safely (locked, read back, then swapped in), like choosing the first password does. Returns '' or why not. */
function gallery_config_merge(array $changes) {
    $lock = @fopen(__FILE__, 'r');
    if ($lock) flock($lock, LOCK_EX);
    try {
        $path = gallery_config_path();
        $raw = is_file($path) ? @file_get_contents($path) : '';
        if ($raw === false) return 'The settings file can\'t be read.';
        [$status, $cfg] = gallery_config_parse((string) $raw);
        if ($status !== 'ok') return 'The settings file (.gallery-config.php) isn\'t valid, so nothing was changed. Fix or delete it first.';
        foreach ($changes as $k => $v) { if ($v === null) unset($cfg[$k]); else $cfg[$k] = $v; }
        $content = gallery_config_render($cfg);
        if ($content === null || !is_writable(__DIR__)) return 'The settings file can\'t be written.';
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $content, LOCK_EX) === false) { @unlink($tmp); return 'The settings file can\'t be written.'; }
        @chmod($tmp, 0600);
        [$st, $round] = gallery_config_parse((string) @file_get_contents($tmp));
        if ($st !== 'ok' || $round != $cfg || !@rename($tmp, $path)) { @unlink($tmp); return 'The settings file didn\'t read back correctly, so it was not changed.'; }
        return '';
    } finally {
        if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    }
}

/* ---- The reader: just enough of the MaxMind DB format to find a country (https://maxmind.github.io/MaxMind-DB/) ---- */

function geo_rd($fh, $pos, $n) {
    fseek($fh, $pos);
    $s = $n > 0 ? fread($fh, $n) : '';
    return $s === false ? '' : $s;
}

/** Decodes one value of the data section at $pos (moved on past it); $base is where the data section (or the metadata) starts, which pointers are counted from. */
function geo_mmdb_value($fh, &$pos, $base, $depth = 0) {
    if ($depth > 8) return null;
    $ctrl = ord(geo_rd($fh, $pos, 1)); $pos++;
    $type = $ctrl >> 5;
    if ($type === 1) {   // a pointer: the value is somewhere else in the data section
        $ss = ($ctrl >> 3) & 3; $vvv = $ctrl & 7;
        $b = geo_rd($fh, $pos, $ss + 1); $pos += $ss + 1;
        $n = array_values(unpack('C*', $b));
        $p = $ss === 0 ? ($vvv << 8) + $n[0] : ($ss === 1 ? ($vvv << 16) + ($n[0] << 8) + $n[1] + 2048 : ($ss === 2 ? ($vvv << 24) + ($n[0] << 16) + ($n[1] << 8) + $n[2] + 526336 : ($n[0] << 24) + ($n[1] << 16) + ($n[2] << 8) + $n[3]));
        $at = $base + $p;
        return geo_mmdb_value($fh, $at, $base, $depth + 1);
    }
    if ($type === 0) { $type = 7 + ord(geo_rd($fh, $pos, 1)); $pos++; }
    $size = $ctrl & 31;
    if ($size >= 29) {
        $k = $size - 28; $e = array_values(unpack('C*', geo_rd($fh, $pos, $k))); $pos += $k;
        $size = $size === 29 ? 29 + $e[0] : ($size === 30 ? 285 + ($e[0] << 8) + $e[1] : 65821 + ($e[0] << 16) + ($e[1] << 8) + $e[2]);
    }
    switch ($type) {
        case 2: $s = geo_rd($fh, $pos, $size); $pos += $size; return $s;
        case 3: $s = geo_rd($fh, $pos, 8); $pos += 8; return $size === 8 ? unpack('E', $s)[1] : 0.0;
        case 4: $s = geo_rd($fh, $pos, $size); $pos += $size; return $s;
        case 5: case 6: case 9: case 10: case 8:
            $s = geo_rd($fh, $pos, $size); $pos += $size; $v = 0;
            for ($i = 0; $i < strlen($s); $i++) $v = ($v << 8) + ord($s[$i]);
            return $v;
        case 7:
            $m = [];
            for ($i = 0; $i < $size; $i++) { $k = geo_mmdb_value($fh, $pos, $base, $depth + 1); $v = geo_mmdb_value($fh, $pos, $base, $depth + 1); if (is_string($k)) $m[$k] = $v; }
            return $m;
        case 11:
            $a = [];
            for ($i = 0; $i < $size; $i++) $a[] = geo_mmdb_value($fh, $pos, $base, $depth + 1);
            return $a;
        case 14: return $size === 1;
        case 15: $s = geo_rd($fh, $pos, 4); $pos += 4; return unpack('G', $s)[1];
    }
    return null;
}

/** Opens a country file (with or without the guard line in front): ['fh', 'off', 'nodes', 'rec', 'ver', 'treeSize', 'nodeBytes'] or null if it is not a usable one. */
function geo_mmdb_open($path) {
    static $cache = [];
    if (array_key_exists($path, $cache)) return $cache[$path];
    $cache[$path] = null;
    $fh = @fopen($path, 'rb');
    if (!$fh) return null;
    $guard = data_file_guard_line();
    $off = geo_rd($fh, 0, strlen($guard)) === $guard ? strlen($guard) : 0;
    $st = fstat($fh); $size = (int) ($st['size'] ?? 0) - $off;
    if ($size < 100) { fclose($fh); return null; }
    $tail = min($size, 131072);
    $buf = geo_rd($fh, $off + $size - $tail, $tail);
    $p = strrpos($buf, "\xAB\xCD\xEFMaxMind.com");
    if ($p === false) { fclose($fh); return null; }
    $at = $off + $size - $tail + $p + 14; $metaBase = $at;
    $meta = geo_mmdb_value($fh, $at, $metaBase);
    if (!is_array($meta) || empty($meta['node_count']) || !in_array($meta['record_size'] ?? 0, [24, 28, 32], true) || !in_array($meta['ip_version'] ?? 0, [4, 6], true) || ($meta['binary_format_major_version'] ?? 2) !== 2) { fclose($fh); return null; }
    $nodeBytes = (int) $meta['record_size'] * 2 / 8;
    $treeSize = (int) $meta['node_count'] * $nodeBytes;
    if ($treeSize + 16 > $size) { fclose($fh); return null; }
    return $cache[$path] = ['fh' => $fh, 'off' => $off, 'nodes' => (int) $meta['node_count'], 'rec' => (int) $meta['record_size'], 'ver' => (int) $meta['ip_version'], 'treeSize' => $treeSize, 'nodeBytes' => (int) $nodeBytes, 'meta' => $meta];
}

/** One record of a search tree node: the node a bit leads to (or, past the node count, where the data is). */
function geo_mmdb_record(array $db, $node, $bit) {
    $b = array_values(unpack('C*', geo_rd($db['fh'], $db['off'] + $node * $db['nodeBytes'], $db['nodeBytes'])));
    if ($db['rec'] === 24) return $bit === 0 ? ($b[0] << 16) + ($b[1] << 8) + $b[2] : ($b[3] << 16) + ($b[4] << 8) + $b[5];
    if ($db['rec'] === 28) return $bit === 0 ? (($b[3] & 0xF0) << 20) + ($b[0] << 16) + ($b[1] << 8) + $b[2] : (($b[3] & 0x0F) << 24) + ($b[4] << 16) + ($b[5] << 8) + $b[6];
    return $bit === 0 ? ($b[0] << 24) + ($b[1] << 16) + ($b[2] << 8) + $b[3] : ($b[4] << 24) + ($b[5] << 16) + ($b[6] << 8) + $b[7];
}

/** The two-letter country the file gives an address ("US"), or ''. */
function geo_mmdb_lookup(array $db, $ip) {
    $bin = @inet_pton((string) $ip);
    if ($bin === false) return '';
    if (strlen($bin) === 16 && strncmp($bin, str_repeat("\0", 10) . "\xff\xff", 12) === 0) $bin = substr($bin, 12);   // an IPv4 address written the IPv6 way
    if ($db['ver'] === 4 && strlen($bin) === 16) return '';
    if ($db['ver'] === 6 && strlen($bin) === 4) $bin = str_repeat("\0", 12) . $bin;   // IPv4 lives under ::/96 in an IPv6 tree
    $bits = strlen($bin) * 8; $node = 0;
    for ($i = 0; $i < $bits && $node < $db['nodes']; $i++) $node = geo_mmdb_record($db, $node, (ord($bin[$i >> 3]) >> (7 - ($i & 7))) & 1);
    if ($node <= $db['nodes'] || $node === 0) return '';
    $pos = $db['off'] + $db['treeSize'] + ($node - $db['nodes']); $base = $db['off'] + $db['treeSize'] + 16;
    $rec = geo_mmdb_value($db['fh'], $pos, $base);
    $c = is_array($rec) ? ($rec['country']['iso_code'] ?? $rec['registered_country']['iso_code'] ?? '') : '';
    return is_string($c) && preg_match('/^[A-Z]{2}$/', $c) ? $c : '';
}

/** The country of an address from the file, or '' (the option is off, there is no usable file, or the address is not in it). */
function geo_db_country($ip) {
    static $memo = [];
    if (!COUNTRY_LOOKUP) return '';
    if (isset($memo[$ip])) return $memo[$ip];
    $db = is_file(geo_db_path()) ? geo_mmdb_open(geo_db_path()) : null;
    return $memo[$ip] = $db ? geo_mmdb_lookup($db, $ip) : '';
}

/** The country of an address: what a trusted proxy says when it is this request's own address, else the file. */
function geo_country($ip) {
    return $ip === client_ip() ? client_country() : geo_db_country($ip);
}

/**
 * Gets the newest country file if there is one we do not have: asks first (a small request) whether the newest month's file differs from ours
 * (its date and size), and only then downloads, unpacks and checks it (the header must read and a known address must come out right) before it
 * replaces the old one. Returns ['ok' => bool, 'changed' => bool, 'error' => text]. Records the try either way; a failure waits a day.
 */
function geo_refresh($force = false) {
    $state = geo_state(); $now = time();
    $fail = function ($why) use ($state, $now) {
        $state['error'] = $why; $state['triedAt'] = $now;
        json_file_update(geo_state_path(), [], function (&$d) use ($state) { $d = $state; });
        @touch(geo_state_path(), $now - 6 * 86400);   // (due again in a day)
        return ['ok' => false, 'changed' => false, 'error' => $why];
    };
    if (!function_exists('gzdecode')) return $fail('This PHP has no zlib, which unpacking the file needs.');
    $probe = geo_probe();
    if (!$probe['ok']) return $fail($probe['error']);
    if (!$force && is_file(geo_db_path()) && $state['modified'] === $probe['modified'] && (int) $state['length'] === $probe['gz'] && $state['ym'] === $probe['ym']) {
        $state['error'] = ''; $state['triedAt'] = $now;
        json_file_update(geo_state_path(), [], function (&$d) use ($state) { $d = $state; });
        @touch(geo_state_path(), $now);
        return ['ok' => true, 'changed' => false, 'error' => ''];
    }
    $free = @disk_free_space(__DIR__);
    if ($free !== false && $free < ($probe['gz'] + 2 * $probe['raw'] + 1048576)) return $fail('Not enough room on this server\'s disk (about ' . signin_size_text($probe['gz'] + 2 * $probe['raw']) . ' is needed, ' . signin_size_text((int) $free) . ' is free).');
    @set_time_limit(120);
    $dl = geo_http($probe['url'], 'GET', [], 40000000, 100);
    if ($dl['status'] !== 200 || strlen($dl["body"]) < 100) return $fail($dl['status'] === 0 ? ($dl['error'] !== '' ? $dl['error'] : 'The download did not finish.') : 'The download answered ' . $dl['status'] . '.');
    $raw = @gzdecode($dl['body']); unset($dl);
    if (!is_string($raw) || strlen($raw) < 100) return $fail('The downloaded file could not be unpacked.');
    $tmp = __DIR__ . '/.gallery-geo.' . bin2hex(random_bytes(4)) . '.php';   // (ends in .php and starts with the guard line, so a leftover is answered with a 404 like the real file)
    if (@file_put_contents($tmp, data_file_guard_line() . $raw, LOCK_EX) === false) { @unlink($tmp); return $fail('The folder can\'t be written to.'); }
    $rawLen = strlen($raw); unset($raw);
    $db = geo_mmdb_open($tmp);
    $good = $db !== null && (COUNTRY_DB_URL !== '' || geo_mmdb_lookup($db, '8.8.8.8') !== '');   // (a file from an address of your own only has to read; DB-IP's must also know a well-known address)
    if ($db !== null) { @fclose($db['fh']); }
    if (!$good) { @unlink($tmp); return $fail('The downloaded file is not a country list this gallery can read.'); }
    if (!@rename($tmp, geo_db_path())) { @unlink($tmp); return $fail('The new file could not be put in place.'); }
    $state = ['ym' => $probe['ym'], 'modified' => $probe['modified'], 'length' => $probe['gz'], 'at' => $now, 'error' => '', 'triedAt' => $now, 'raw' => $rawLen];
    json_file_update(geo_state_path(), [], function (&$d) use ($state) { $d = $state; });
    @touch(geo_state_path(), $now);
    return ['ok' => true, 'changed' => true, 'error' => ''];
}

/**
 * Called from a gallery page, a link's opening page and the admin: if the option is on and it has been 7 days since the last look (or the file is
 * not there), looks for a newer file. The cost of asking whether it is time is one look at one file's date. The work happens after the visitor has
 * been answered when PHP can do that (php-fpm); otherwise only in the admin, so a visitor never waits for a download.
 */
function geo_maybe_refresh($inAdmin = false) {
    static $armed = false;
    if ($armed || !COUNTRY_LOOKUP) return;
    $stamp = (int) @filemtime(geo_state_path());
    if (is_file(geo_db_path()) && $stamp > 0 && time() - $stamp < 7 * 86400) return;
    if (!$inAdmin && !function_exists('fastcgi_finish_request')) return;
    $armed = true;
    register_shutdown_function(function () use ($inAdmin) {
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
        @ignore_user_abort(true);
        $lock = @fopen(geo_state_path() . '.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return;   // (someone else is already doing it)
        try { geo_refresh(); } catch (Throwable $e) { error_log('gallery: country file refresh: ' . $e->getMessage()); }
        flock($lock, LOCK_UN); fclose($lock); @unlink(geo_state_path() . '.lock');
    });
}

/** Turns the option on: gets the file (which must work), then writes the setting. Returns ['ok', 'text']. */
function geo_enable() {
    $r = geo_refresh(true);
    if (!$r['ok']) return ['ok' => false, 'text' => $r['error']];
    $why = gallery_config_merge(['COUNTRY_LOOKUP' => true]);
    return $why === '' ? ['ok' => true, 'text' => 'Done: the country file is in place and addresses are looked up.'] : ['ok' => false, 'text' => $why];
}

/** Turns the option off, and removes the file and its notes when asked. Only those two names are ever removed. */
function geo_disable($deleteFile) {
    $why = gallery_config_merge(['COUNTRY_LOOKUP' => null]);
    if ($why !== '') return ['ok' => false, 'text' => $why];
    if ($deleteFile) foreach ([geo_db_path(), geo_state_path()] as $f) if (is_file($f) && !is_link($f) && dirname($f) === __DIR__) @unlink($f);
    return ['ok' => true, 'text' => $deleteFile ? 'Turned off, and the country file is deleted.' : 'Turned off. The file stays, in case you turn it on again.'];
}

/** The admin's buttons for the option: geo_probe (what it needs, exactly), geo_enable, geo_refresh and geo_disable. Answers JSON and ends the request. */
function geo_admin_action($action) {
    if ($action === 'geo_probe') {
        $p = geo_probe();
        if (!$p['ok']) admin_json_exit(['ok' => false, 'text' => $p['error'] . ' You can download the file yourself and put it here as ' . basename(geo_db_path()) . '.']);
        $free = @disk_free_space(__DIR__);
        $need = $p['gz'] + 2 * $p['raw'];
        admin_json_exit(['ok' => true, 'gz' => signin_size_text($p['gz']), 'raw' => signin_size_text($p['raw']), 'exact' => $p['exact'], 'need' => signin_size_text($need), 'free' => $free === false ? '' : signin_size_text((int) $free), 'enough' => $free === false || $free > $need + 1048576,
            'canFetch' => function_exists('curl_init') || filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN), 'zlib' => function_exists('gzdecode'), 'url' => $p['url']]);
    }
    if ($action === 'geo_enable') admin_json_exit(geo_enable());
    if ($action === 'geo_refresh') { $r = geo_refresh(false); admin_json_exit(['ok' => $r['ok'], 'text' => $r['ok'] ? ($r['changed'] ? 'Fetched the newest file.' : 'You already have the newest file.') : $r['error']]); }
    if ($action === 'geo_disable') admin_json_exit(geo_disable(($_POST['delete'] ?? '') === '1'));
    admin_json_exit(['ok' => false, 'text' => 'Unknown action.']);
}

