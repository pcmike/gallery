/* -----------------------------------------------------------------------
 * What each signed-in browser has done. Next to its token (see "Browsers that are signed in") every browser keeps a history: one
 * line, appended to its own guarded file (.gallery-signin-<id>.php), each time it turns up from a different address or as a different
 * device, with the time, the full address, the country when it is known and what the browser said it was. A line is added the moment
 * the address changes, whether it is new or one the browser has used before; coming back from the same address as the last request
 * only refreshes "last seen", at most once an hour. Nothing limits how much is kept inside BROWSER_HISTORY_DAYS: the owner can see how
 * big each history has grown (the list, the Health check and a daily size note) and decides.
 *
 * Four things raise a flag on a browser, each answerable with "Looks fine" and each with its own switch to sign the browser out
 * at once (BROWSER_END_ON_*): the device it was made on is now a different one of the same kind (warning), a different kind of device,
 * a country it has not been used from, and more than BROWSER_NETWORKS_ALARM networks in 24 hours (all action needed). A new address on
 * its own is shown, not flagged. A browser that is signed out (by the owner, a log out, an expiry, a password change or a switch above)
 * stays listed for BROWSER_ENDED_DAYS with its history, which is what is wanted when something looked wrong.
 *
 * A "network" is an IPv4 /24 (a block of 256 addresses: one carrier pool, one office) or an IPv6 /64 (one home, one phone connection).
 * ----------------------------------------------------------------------- */

function signin_log_path() {
    return __DIR__ . '/.gallery-browsers-log.php';
}
function signin_log_default() {
    return ['ended' => [], 'sizes' => []];
}
function signin_log_read() {
    return json_file_read(signin_log_path(), signin_log_default());
}
function signin_log_update($fn) {
    return json_file_update(signin_log_path(), signin_log_default(), $fn);
}
function signin_history_path($id) {
    return __DIR__ . '/.gallery-signin-' . $id . '.php';
}

/** The network an address belongs to, as text: "203.0.113" for IPv4 (its /24), "2001:0db8:85a3:0000" for IPv6 (its /64). */
function signin_net_key($ip) {
    $bin = ip_binary($ip);
    if ($bin === null) return (string) $ip;
    if (strlen($bin) === 4) return ord($bin[0]) . '.' . ord($bin[1]) . '.' . ord($bin[2]);
    return implode(':', str_split(bin2hex(substr($bin, 0, 8)), 4));
}

/** The kind of device a label ("iPhone · Safari") is: apple (iPhone, iPad, Mac), android, windows, linux, or other (not known). */
/** True for the identities Apple's own networking sends on a phone's behalf (NetworkingExtension, CFNetwork, Darwin): the same device, not a new one. */
function signin_is_system_ua($ua) {
    return (bool) preg_match('~NetworkingExtension|CFNetwork|Darwin/~', (string) $ua);
}

/** The device a browser really is, as far as its last ordinary request said: its record's 'real', else its last label unless that was
 *  a system identity (then the one it was made on). */
function signin_real_label(array $r) {
    $real = (string) ($r['real'] ?? '');
    if ($real !== '') return $real;
    $lab0 = (string) ($r['lab0'] ?? $r['label'] ?? '');
    $lab = (string) ($r['lab'] ?? $lab0);
    return $lab === 'A device · a browser' ? $lab0 : $lab;
}

function signin_family($label) {
    $dev = explode(' · ', (string) $label)[0];
    if (in_array($dev, ['iPhone', 'iPad', 'Mac'], true)) return 'apple';
    $map = ['Android' => 'android', 'Windows' => 'windows', 'Linux' => 'linux'];
    return $map[$dev] ?? 'other';
}

/** Adds one line to a browser's history file ($id: the start of its token's hash). The first line of a new file is the guard line. */
function signin_history_append($id, array $line) {
    $fp = @fopen(signin_history_path($id), 'a');
    if (!$fp) return false;
    flock($fp, LOCK_EX);
    $st = fstat($fp);
    fwrite($fp, (($st && (int) $st['size'] === 0) ? data_file_guard_line() : '') . json_encode($line, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

/** Every line of a browser's history, oldest first: [['t' => time, 'ip' => address, 'cc' => country or '', 'ua' => what it said, 'e' => 'signin'|'change']]. */
function signin_history_read($id) {
    $raw = @file_get_contents(signin_history_path($id));
    $out = [];
    if (!is_string($raw)) return $out;
    foreach (explode("\n", $raw) as $l) {
        if ($l === '' || $l[0] !== '{') continue;
        $j = json_decode($l, true);
        if (is_array($j) && isset($j['t'], $j['ip'])) $out[] = $j;
    }
    return $out;
}

/** What a history file holds, counted: ['lines', 'ips' (different addresses), 'dev' (times the identity changed from one line to the next),
 *  'sys' (how many of those changes were to or from Apple's system networking, which is never flagged)]. */
function signin_history_stats($id, $lines = null) {
    $lines = $lines ?? signin_history_read($id);
    $ips = []; $dev = 0; $sys = 0; $prev = null; $prevSys = false;
    foreach ($lines as $l) {
        $ips[(string) $l['ip']] = true;
        $ua = (string) ($l['ua'] ?? ''); $lab = browser_label($ua); $isSys = signin_is_system_ua($ua);
        if ($prev !== null && $lab !== $prev) { $dev++; if ($isSys || $prevSys) $sys++; }
        $prev = $lab; $prevSys = $isSys;
    }
    return ['lines' => count($lines), 'ips' => count($ips), 'dev' => $dev, 'sys' => $sys];
}

/** "1 address · 0 device changes · 3 lines", the line that heads a history. */
function signin_stats_text(array $st) {
    return number_format($st['ips']) . ' address' . ($st['ips'] === 1 ? '' : 'es') . ' · ' . number_format($st['dev']) . ' device change' . ($st['dev'] === 1 ? '' : 's')
        . ($st['sys'] > 0 ? ' (' . ($st['sys'] === $st['dev'] ? ($st['dev'] === 1 ? 'it was' : 'all') : $st['sys'] . ' of them') . ' Apple system networking, not flagged)' : '') . ' · ' . number_format($st['lines']) . ' line' . ($st['lines'] === 1 ? '' : 's');
}

/** Why a browser's sign-in ended, in words. */
function signin_reason_text($reason) {
    $map = ['you' => 'Signed out by you', 'logout' => 'Logged out', 'expired' => 'Expired', 'password' => 'Password changed', 'auto' => 'Signed out automatically', 'again' => 'Signed in again'];
    return $map[$reason] ?? 'Ended';
}

/** What each flag is called, and how loud: 'bad' (action needed) or 'warn'. */
function signin_flag_info($name) {
    $map = [
        'device' => ['A different device of the same kind', 'warn'],
        'family' => ['A different kind of device', 'bad'],
        'country' => ['A new country', 'bad'],
        'networks' => ['Many networks in 24 hours', 'bad'],
    ];
    return $map[$name] ?? [$name, 'warn'];
}

/** Notes a browser that has gone out of the list (signed out, expired...) in the ended list; its history stays on disk meanwhile. */
function signin_ended_add($id, array $rec, $reason, $detail = '', $at = null) {
    if (($rec['via'] ?? '') === 'script') return;
    $at = $at ?? time();
    signin_log_update(function (&$d) use ($id, $rec, $reason, $detail, $at) {
        $d['ended'][] = ['id' => $id, 'kind' => (string) ($rec['kind'] ?? ''), 'label' => (string) ($rec['label'] ?? ''), 'made' => (int) ($rec['made'] ?? 0), 'ended' => $at, 'reason' => $reason, 'detail' => (string) $detail,
            'n' => (int) ($rec['n'] ?? 0), 'peak' => (int) ($rec['peak'] ?? 0), 'flags' => array_keys((array) ($rec['flags'] ?? []))];
    });
}

/**
 * This request's browser has come from a different address, or as a different device, than its last request. Writes the line, updates
 * its record, and raises the flags that apply; if one of them has its sign-out switch on, ends the browser and returns false.
 */
function browser_note_event($h, $ip, $label) {
    $now = time(); $id = substr($h, 0, 12); $cc = geo_country($ip); $net = signin_net_key($ip);
    $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300);
    $sys = signin_is_system_ua($ua);
    $raised = []; $detailOf = []; $ended = null;
    json_file_update(browsers_path(), [], function (&$d) use ($h, $now, $ip, $label, $cc, $net, $sys, &$raised, &$detailOf, &$ended) {
        if (!isset($d[$h]) || !is_array($d[$h])) return;
        $r =& $d[$h];
        $days = browser_days($r['kind']);
        $r['exp'] = $now + ($days >= 1 ? $days * 86400 : 86400);
        $lab0 = (string) ($r['lab0'] ?? $r['label'] ?? '');
        $prevReal = signin_real_label($r);
        $r['seen'] = $now; $r['ip'] = $ip; $r['lab'] = $label; $r['n'] = (int) ($r['n'] ?? 0) + 1;
        if (!$sys) $r['real'] = $label;
        $nets = [];
        foreach ((array) ($r['nets'] ?? []) as $k => $t) if ((int) $t > $now - 86400) $nets[(string) $k] = (int) $t;
        $nets[$net] = $now;
        if (count($nets) > 500) { arsort($nets); $nets = array_slice($nets, 0, 500, true); }   // (what is tracked for the 24-hour count, not the history: that is in the file)
        $r['nets'] = $nets;
        $count = count($nets);
        if ($count > (int) ($r['peak'] ?? 0)) { $r['peak'] = $count; $r['peakAt'] = $now; }
        $flag = function ($name, $detail, $v = 0) use (&$r, $now, &$raised, &$detailOf) {
            $f = $r['flags'][$name] ?? null;
            $again = $f === null || ((int) ($f['a'] ?? 0) > 0 && ($name !== 'networks' || $v > (int) ($f['v'] ?? 0)));
            if ($again) { $r['flags'][$name] = ['t' => $now, 'd' => $detail, 'a' => 0, 'v' => $v]; $raised[] = $name; $detailOf[$name] = $detail; }
            else { $r['flags'][$name]['d'] = $detail; $r['flags'][$name]['v'] = $v; }
        };
        if (!$sys && $label !== $prevReal && $label !== $lab0) {   // (a system identity is logged and counted but never flagged)
            $f0 = signin_family($lab0); $f1 = signin_family($label);
            if ($f0 !== 'other' && $f1 !== 'other' && $f0 !== $f1) $flag('family', 'Made on ' . $lab0 . ', now used as ' . $label);
            else $flag('device', 'Made on ' . $lab0 . ', now used as ' . $label);
        }
        $ccs = array_values((array) ($r['ccs'] ?? []));
        if ($cc !== '') {
            if ($ccs && !in_array($cc, $ccs, true)) $flag('country', 'Used from ' . implode(', ', $ccs) . ' before, now from ' . $cc);
            if (!in_array($cc, $ccs, true)) $ccs[] = $cc;
            $r['ccs'] = $ccs;
        }
        if ($count > BROWSER_NETWORKS_ALARM) $flag('networks', $count . ' networks in the last 24 hours (the limit is ' . BROWSER_NETWORKS_ALARM . ')', $count);
        $switch = ['device' => BROWSER_END_ON_DEVICE, 'family' => BROWSER_END_ON_FAMILY, 'country' => BROWSER_END_ON_COUNTRY, 'networks' => BROWSER_END_ON_NETWORKS];
        foreach ($raised as $name) {
            if (!empty($switch[$name])) { $ended = [$r, $detailOf[$name]]; break; }
        }
        unset($r);
        if ($ended !== null) unset($d[$h]);
    });
    signin_history_append($id, ['t' => $now, 'ip' => $ip, 'cc' => $cc, 'ua' => $ua, 'e' => 'change']);
    if ($ended !== null) {
        signin_ended_add($id, $ended[0], 'auto', $ended[1]);
        gallery_cookie_expire(REVEAL_COOKIE_NAME, 'Lax');
        return false;
    }
    return true;
}

/** "Looks fine": a flag is cleared until something new raises it again. */
function browser_ack($id, $name) {
    $n = 0;
    json_file_update(browsers_path(), [], function (&$d) use ($id, $name, &$n) {
        foreach ($d as $h => &$r) {
            if (!is_array($r) || strncmp((string) $h, $id, 12) !== 0 || !isset($r['flags'][$name])) continue;
            $r['flags'][$name]['a'] = time(); $n++;
        }
        unset($r);
    });
    return $n;
}

/** The flags of a record that are showing (not answered with "Looks fine"): name => [detail, time, 'bad'|'warn']. */
function browser_flags_of(array $rec) {
    $out = [];
    foreach ((array) ($rec['flags'] ?? []) as $name => $f) {
        if (!is_array($f) || (int) ($f['a'] ?? 0) > 0) continue;
        $out[$name] = [(string) ($f['d'] ?? ''), (int) ($f['t'] ?? 0), signin_flag_info($name)[1]];
    }
    return $out;
}

/**
 * The tidying that keeps the files in order: browsers whose end has come (expired, or made under a password that has changed) move to the
 * ended list; ended entries and their histories go after BROWSER_ENDED_DAYS; a live history loses lines older than BROWSER_HISTORY_DAYS
 * (once a day each); a history file nothing refers to goes after the same time; and today's size is noted (kept 90 days). Once per request.
 */
function browser_housekeeping() {
    static $done = false;
    if ($done) return;
    $done = true;
    $now = time(); $moved = [];
    if (is_file(browsers_path())) {
        json_file_update(browsers_path(), [], function (&$d) use ($now, &$moved) {
            foreach ($d as $k => $r) {
                if (!is_array($r)) { unset($d[$k]); continue; }
                $kind = (string) ($r['kind'] ?? '');
                $m = in_array($kind, ['owner', 'view'], true) ? browser_marker($kind) : '';
                $expired = (int) ($r['exp'] ?? 0) <= $now;
                if ($expired || $m === '' || !hash_equals($m, (string) ($r['pw'] ?? ''))) {
                    $moved[] = [(string) $k, $r, $expired ? 'expired' : 'password', $expired ? (int) $r['exp'] : $now];
                    unset($d[$k]);
                }
            }
        });
    }
    foreach ($moved as [$k, $r, $why, $at]) signin_ended_add(substr($k, 0, 12), $r, $why, '', $at);
    $live = [];
    foreach (json_file_read(browsers_path(), []) as $k => $r) if (is_array($r)) $live[substr((string) $k, 0, 12)] = (int) ($r['trim'] ?? 0);
    $keepEnded = []; $dropFiles = [];
    signin_log_update(function (&$d) use ($now, &$keepEnded, &$dropFiles) {
        $cut = $now - BROWSER_ENDED_DAYS * 86400;
        $kept = [];
        foreach ((array) ($d['ended'] ?? []) as $e) {
            if (is_array($e) && (int) ($e['ended'] ?? 0) > $cut) { $kept[] = $e; $keepEnded[(string) $e['id']] = true; }
            elseif (is_array($e)) $dropFiles[] = (string) $e['id'];
        }
        $d['ended'] = $kept;
        $sizes = (array) ($d['sizes'] ?? []);
        $bytes = 0; $files = 0;
        foreach ((array) @glob(__DIR__ . '/.gallery-signin-*.php') as $f) { $bytes += (int) @filesize($f); $files++; }
        $sizes[date('Y-m-d', $now)] = [$bytes, $files];
        krsort($sizes);
        $d['sizes'] = array_slice($sizes, 0, 90, true);
    });
    foreach ($dropFiles as $id) if (preg_match('/^[0-9a-f]{12}$/', $id) && !isset($live[$id])) @unlink(signin_history_path($id));
    foreach ((array) @glob(__DIR__ . '/.gallery-signin-*.php') as $f) {   // a history nothing refers to (a record that was pushed out of the sixty)
        if (!preg_match('/\.gallery-signin-([0-9a-f]{12})\.php$/', $f, $m) || isset($live[$m[1]]) || isset($keepEnded[$m[1]])) continue;
        if ($now - (int) @filemtime($f) > max(1, BROWSER_ENDED_DAYS) * 86400) @unlink($f);
    }
    $trimmed = [];
    foreach ($live as $id => $at) {
        if ($now - $at < 86400) continue;
        $lines = signin_history_read($id);
        $cut = $now - BROWSER_HISTORY_DAYS * 86400;
        $keep = array_values(array_filter($lines, fn ($l) => (int) $l['t'] > $cut));
        if (count($keep) !== count($lines)) {
            $body = data_file_guard_line();
            foreach ($keep as $l) $body .= json_encode($l, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
            @file_put_contents(signin_history_path($id), $body, LOCK_EX);
        }
        $trimmed[$id] = $now;
    }
    if ($trimmed) json_file_update(browsers_path(), [], function (&$d) use ($trimmed) { foreach ($d as $k => &$r) if (is_array($r) && isset($trimmed[substr((string) $k, 0, 12)])) $r['trim'] = $trimmed[substr((string) $k, 0, 12)]; unset($r); });
}

/** The ended browsers for the list, newest first. */
function browser_ended_rows() {
    $rows = array_values(array_filter((array) (signin_log_read()['ended'] ?? []), 'is_array'));
    usort($rows, fn ($a, $b) => (int) $b['ended'] <=> (int) $a['ended']);
    return $rows;
}

/** One browser's history file size in bytes. */
function signin_history_size($id) {
    return (int) @filesize(signin_history_path($id));
}

/** "96 KB", "1.2 MB". */
function signin_size_text($bytes) {
    $bytes = (int) $bytes;
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return number_format($bytes / 1024, $bytes < 10240 ? 1 : 0) . ' KB';
    return number_format($bytes / 1048576, 1) . ' MB';
}

