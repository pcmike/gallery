/* -----------------------------------------------------------------------
 * The visitor log. Who opened the gallery without a link (the public
 * address) and who opened each share link ("visitors": an address and a
 * browser), the tries that were turned away, what each visitor saw, opened,
 * played and downloaded (per item, with first and last time), the folders
 * they looked at, and every happening in order, are kept in one SQLite file
 * beside this script. Nothing is capped: the owner decides how long records
 * stay (Settings: Keep visitor records for) and trims the log by hand. A
 * share link's records go only when the link itself is deleted (pausing,
 * revoking or letting a link end never does). "Seen" is reported by the
 * visitor's own browser as pictures come on screen (so a CDN or the
 * browser's cache can't hide it), "opened" when the viewer shows a picture,
 * "played" when a video plays; "downloaded" is noted by the server when an
 * original or a ZIP is sent. For a private picture the server also notes
 * each delivery, so a script blocker can't hide the sensitive ones.
 *
 * The log needs PHP's SQLite (pdo_sqlite) and a folder the web server may
 * write to. Without them nothing is logged, and Health says why.
 * ----------------------------------------------------------------------- */

/** The log's file: .gallery-visits- and 24 random hex digits, then .sqlite, beside this script. The random part is made once (the first time something is logged) and stays in the
 *  name, so the address of a file with IP addresses in it can't be guessed even where the server rule that refuses files starting with .gallery- is missing. Before the file exists this is
 *  the name it would get (asking makes nothing). */
function visitlog_path($set = null) {
    static $path = null;
    if ($set !== null) return $path = $set;
    if ($path !== null) return $path;
    return $path = visitlog_find() ?? __DIR__ . '/.gallery-visits-' . bin2hex(random_bytes(12)) . '.sqlite';
}
/** The log's file that is already there (the smallest name if two first hits made one each at the same moment), or null. */
function visitlog_find() {
    $best = null;
    foreach ((array) glob(__DIR__ . '/.gallery-visits-*.sqlite') as $f) if (preg_match('~/\.gallery-visits-[0-9a-f]{24}\.sqlite$~', $f) && is_file($f) && !is_link($f) && ($best === null || $f < $best)) $best = $f;
    return $best;
}
function visitlog_driver_ok() { return class_exists('PDO') && in_array('sqlite', \PDO::getAvailableDrivers(), true); }   // (spelt with the leading backslash on purpose: the upgrade test forces the view counts' flat-file mode by replacing the other spelling)

function visitlog_schema($pdo) {
    $pdo->exec('CREATE TABLE IF NOT EXISTS visitors (src TEXT NOT NULL, vid TEXT NOT NULL, ip TEXT NOT NULL, ua TEXT NOT NULL DEFAULT \'\', country TEXT NOT NULL DEFAULT \'\', first INTEGER NOT NULL, last INTEGER NOT NULL, opens INTEGER NOT NULL DEFAULT 0, views INTEGER NOT NULL DEFAULT 0, entry_f TEXT, entry_h TEXT, entry_u INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (src, vid))');
    $pdo->exec('CREATE INDEX IF NOT EXISTS visitors_last ON visitors (last)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS visitors_ip ON visitors (ip)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS blocked (src TEXT NOT NULL, vid TEXT NOT NULL, ip TEXT NOT NULL, ua TEXT NOT NULL DEFAULT \'\', country TEXT NOT NULL DEFAULT \'\', first INTEGER NOT NULL, last INTEGER NOT NULL, tries INTEGER NOT NULL DEFAULT 0, why TEXT NOT NULL DEFAULT \'{}\', PRIMARY KEY (src, vid))');
    $pdo->exec('CREATE TABLE IF NOT EXISTS notes (src TEXT NOT NULL, kind TEXT NOT NULL, vid TEXT NOT NULL, ip TEXT NOT NULL, name TEXT NOT NULL, n INTEGER NOT NULL DEFAULT 0, last INTEGER NOT NULL, PRIMARY KEY (src, kind, vid, name))');
    $pdo->exec('CREATE TABLE IF NOT EXISTS events (id INTEGER PRIMARY KEY AUTOINCREMENT, src TEXT NOT NULL, t INTEGER NOT NULL, t1 INTEGER NOT NULL, vid TEXT NOT NULL, k TEXT NOT NULL, f TEXT, d TEXT, n INTEGER NOT NULL DEFAULT 0, s TEXT, h TEXT, u INTEGER NOT NULL DEFAULT 0)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS events_t ON events (t1)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS events_src ON events (src, t1)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS items (src TEXT NOT NULL, vid TEXT NOT NULL, item TEXT NOT NULL, sf INTEGER NOT NULL DEFAULT 0, sl INTEGER NOT NULL DEFAULT 0, opn INTEGER NOT NULL DEFAULT 0, opf INTEGER NOT NULL DEFAULT 0, opl INTEGER NOT NULL DEFAULT 0, pln INTEGER NOT NULL DEFAULT 0, pll INTEGER NOT NULL DEFAULT 0, dwn INTEGER NOT NULL DEFAULT 0, dwl INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (src, vid, item)) WITHOUT ROWID');
    $pdo->exec('CREATE TABLE IF NOT EXISTS folders (src TEXT NOT NULL, vid TEXT NOT NULL, folder TEXT NOT NULL, n INTEGER NOT NULL DEFAULT 0, first INTEGER NOT NULL, last INTEGER NOT NULL, PRIMARY KEY (src, vid, folder)) WITHOUT ROWID');
    $pdo->exec('CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY, v TEXT NOT NULL)');
}

function visitlog_open($file, $new) {
    $pdo = new PDO('sqlite:' . $file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    if ($new) $pdo->exec('PRAGMA auto_vacuum = FULL');   // (a trim gives the room back; set before the first table)
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() < 1) {   // (the tables are made once, not on every connection)
        visitlog_schema($pdo);
        $pdo->exec('PRAGMA user_version = 1');
    }
    return $pdo;
}

/** The open database (made on first use), or null when this server can't keep one. */
function visitlog_db() {
    static $db = false;
    if ($db !== false) return $db;
    $db = null;
    $GLOBALS['__visitlog_error'] = '';
    if (!visitlog_driver_ok()) { $GLOBALS['__visitlog_error'] = 'nodriver'; return null; }
    try {
        $file = visitlog_path(); $new = !is_file($file);
        $pdo = visitlog_open($file, $new);
        if ($new && ($best = visitlog_find()) !== null && $best !== $file) {   // (another first hit made its own file at the same moment: the smaller name is the log, and this empty one goes)
            $pdo = null;
            foreach ([$file, $file . '-wal', $file . '-shm'] as $f) @unlink($f);
            $pdo = visitlog_open(visitlog_path($best), false);
        }
        $db = $pdo;
    } catch (Throwable $e) {
        error_log('gallery: the visitor log could not be opened: ' . $e->getMessage());
        $GLOBALS['__visitlog_error'] = 'cantwrite';
        $db = null;
    }
    return $db;
}

/** Can the log be kept here? A real write, not only a look for the driver: [ok, short reason when not]. Asked by Health, by the Settings switch and by whatever is about to log. */
function visitlog_check() {
    static $r = null;
    if ($r !== null) return $r;
    if (!visitlog_driver_ok()) return $r = [false, 'This server\'s PHP has no SQLite (the pdo_sqlite extension), which the visitor log needs.'];
    $db = visitlog_db();
    if ($db === null) return $r = [false, 'SQLite is there, but the log\'s file can\'t be made or written in the gallery\'s folder. The web server has to be allowed to write there.'];
    try { $db->exec('INSERT OR REPLACE INTO meta (k, v) VALUES (\'checked\', \'' . time() . '\')'); }
    catch (Throwable $e) { return $r = [false, 'SQLite is there, but writing to the log failed (' . $e->getMessage() . ').']; }
    return $r = [true, ''];
}
function visitlog_usable() { return visitlog_check()[0]; }

/** The cheap look the hot paths take (a public page, the tab's own pages): the driver is there and the file (or the folder it would be made in) can be written. No write is made; visitlog_check is the real test. */
function visitlog_ready() {
    static $r = null;
    if ($r !== null) return $r;
    if (!visitlog_driver_ok()) return $r = false;
    $p = visitlog_path();
    return $r = is_file($p) ? is_writable($p) : is_writable(__DIR__);
}

/** The same answer without making the log's file while nothing uses the log (no share links, no public logging, no file yet): the driver and the folder are looked at, nothing is written. */
function visitlog_check_quiet() {
    if (is_file(visitlog_path()) || ENABLE_SHARE_LINKS || LOG_PUBLIC_VISITS) return visitlog_check();
    if (!visitlog_driver_ok()) return [false, 'This server\'s PHP has no SQLite (the pdo_sqlite extension), which the visitor log needs.'];
    return is_writable(__DIR__) ? [true, ''] : [false, 'SQLite is there, but the gallery\'s folder isn\'t writable by the web server, so the log\'s file can\'t be made.'];
}

/** Runs $fn($db) inside one transaction; returns what it returns, or null when there is no log or it failed (nothing is half written). */
function visitlog_tx(callable $fn) {
    $db = visitlog_db();
    if ($db === null) return null;
    try {
        $db->exec('BEGIN IMMEDIATE');
        $r = $fn($db);
        $db->exec('COMMIT');
        return $r;
    } catch (Throwable $e) {
        try { $db->exec('ROLLBACK'); } catch (Throwable $x) {}
        error_log('gallery: the visitor log was not written: ' . $e->getMessage());
        return null;
    }
}

/** What the log takes on disk (the file and the side files SQLite keeps beside it), in bytes. */
function visitlog_size() {
    $p = visitlog_path(); $n = 0;
    foreach ([$p, $p . '-wal', $p . '-shm'] as $f) if (is_file($f)) $n += (int) @filesize($f);
    return $n;
}
/** The time of the oldest record (0 when there is none). */
function visitlog_oldest() {
    if (!is_file(visitlog_path()) || ($db = visitlog_db()) === null) return 0;
    try {
        $a = (int) $db->query('SELECT MIN(first) FROM visitors')->fetchColumn();
        $b = (int) $db->query('SELECT MIN(t) FROM events')->fetchColumn();
        return $a > 0 && $b > 0 ? min($a, $b) : max($a, $b);
    } catch (Throwable $e) { return 0; }
}
/** [records of every kind, ...] is not needed: the number of visitors the log holds, for the tab's own count. */
function visitlog_visitor_count() {
    if (!is_file(visitlog_path()) || ($db = visitlog_db()) === null) return 0;
    try { return (int) $db->query('SELECT COUNT(*) FROM visitors')->fetchColumn(); } catch (Throwable $e) { return 0; }
}

function visitlog_touch($db, $src, $vid, $ip, $label, $country, $now) {
    $db->prepare('INSERT INTO visitors (src, vid, ip, ua, country, first, last) VALUES (?, ?, ?, ?, ?, ?, ?) ON CONFLICT (src, vid) DO UPDATE SET last = excluded.last, country = CASE WHEN excluded.country <> \'\' THEN excluded.country ELSE visitors.country END')
       ->execute([$src, $vid, $ip, $label, $country, $now, $now]);
}
function visitlog_event($db, $src, array $e) {
    $t = (int) $e['t'];
    $db->prepare('INSERT INTO events (src, t, t1, vid, k, f, d, n, s, h, u) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
       ->execute([$src, $t, (int) ($e['t1'] ?? $t), (string) $e['v'], (string) $e['k'], isset($e['f']) ? (string) $e['f'] : null, isset($e['d']) ? (string) $e['d'] : null, (int) ($e['n'] ?? 0),
                  isset($e['s']) ? json_encode($e['s'], JSON_UNESCAPED_SLASHES) : null, isset($e['h']) ? (string) $e['h'] : null, !empty($e['u']) ? 1 : 0]);
}
function visitlog_meta_get($db, $k) { $st = $db->prepare('SELECT v FROM meta WHERE k = ?'); $st->execute([$k]); $v = $st->fetchColumn(); return $v === false ? '' : (string) $v; }
function visitlog_meta_set($db, $k, $v) { $db->prepare('INSERT OR REPLACE INTO meta (k, v) VALUES (?, ?)')->execute([$k, (string) $v]); }

/** Who is in a log's counts but no longer in its list ("earlier visitors" once a trim removed them). */
function visitlog_folded($db, $src) { return (int) visitlog_meta_get($db, 'folded:' . $src); }

/** A link's log as the pages that count and list read it (visitors, turned-away tries, old-address notes); the timeline and the per-item tallies are asked for by the Visitors tab itself. */
function link_log_read($id) {
    $log = ['v' => 1, 'visitors' => [], 'blocked' => [], 'via' => [], 'tried' => [], 'folded' => 0, 'last' => 0];
    $db = is_file(visitlog_path()) ? visitlog_db() : null;
    if ($db === null) return $log;
    try {
        $st = $db->prepare('SELECT * FROM visitors WHERE src = ? ORDER BY last DESC'); $st->execute([$id]);
        foreach ($st as $r) {
            $v = ['ip' => $r['ip'], 'ua' => $r['ua'], 'country' => $r['country'], 'first' => (int) $r['first'], 'last' => (int) $r['last'], 'opens' => (int) $r['opens'], 'views' => (int) $r['views']];
            if ($r['entry_f'] !== null) $v['entry'] = ['f' => $r['entry_f'], 'h' => (string) $r['entry_h']] + ($r['entry_u'] ? ['u' => 1] : []);
            $log['visitors'][$r['vid']] = $v; $log['last'] = max($log['last'], (int) $r['last']);
        }
        $st = $db->prepare('SELECT * FROM blocked WHERE src = ? ORDER BY last DESC'); $st->execute([$id]);
        foreach ($st as $r) { $log['blocked'][$r['vid']] = ['ip' => $r['ip'], 'ua' => $r['ua'], 'country' => $r['country'], 'first' => (int) $r['first'], 'last' => (int) $r['last'], 'tries' => (int) $r['tries'], 'why' => (array) json_decode((string) $r['why'], true)]; $log['last'] = max($log['last'], (int) $r['last']); }
        $st = $db->prepare('SELECT * FROM notes WHERE src = ?'); $st->execute([$id]);
        foreach ($st as $r) {
            $e = $log[$r['kind']][$r['vid']] ?? ['ip' => $r['ip'], 'n' => [], 'last' => 0];
            $e['n'][$r['name']] = (int) $r['n']; $e['last'] = max($e['last'], (int) $r['last']);
            $log[$r['kind']][$r['vid']] = $e;
        }
        $log['folded'] = visitlog_folded($db, $id);
    } catch (Throwable $e) { error_log('gallery: the visitor log could not be read: ' . $e->getMessage()); }
    return $log;
}

/** A link's log for the pages that only count: [log, []] (the second part was the per-item file of the old log). */
function link_activity($id) { return [link_log_read($id), []]; }

/** Records for one source ('' for all of them) older than $cutoff: counts of what is there, or (apply) removes it. Assumes the caller holds the transaction. */
function visitlog_trim_run($db, $src, $cutoff, $apply) {
    $w = $src === null ? '' : ' AND src = ?'; $a = $src === null ? [$cutoff] : [$cutoff, $src];
    $n = ['events' => 0, 'visitors' => 0, 'blocked' => 0, 'items' => 0, 'folders' => 0];
    $q = function ($table, $cond) use ($db, $w, $a) { $st = $db->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $cond . $w); $st->execute($a); return (int) $st->fetchColumn(); };
    $d = function ($table, $cond) use ($db, $w, $a) { $st = $db->prepare('DELETE FROM ' . $table . ' WHERE ' . $cond . $w); $st->execute($a); };
    $n['events'] = $q('events', 't1 < CAST(? AS INTEGER)'); $n['blocked'] = $q('blocked', 'last < CAST(? AS INTEGER)'); $n['folders'] = $q('folders', 'last < CAST(? AS INTEGER)');
    $n['items'] = $q('items', 'MAX(sf, sl, opf, opl, pll, dwl) < CAST(? AS INTEGER)');   // (a bound value is text: an expression has no column to make it a number, so it is cast)
    $n['visitors'] = $q('visitors', 'last < CAST(? AS INTEGER)');
    if (!$apply) return $n;
    $st = $db->prepare('SELECT src, COUNT(*) AS c FROM visitors WHERE last < CAST(? AS INTEGER)' . $w . ' GROUP BY src'); $st->execute($a);
    foreach ($st->fetchAll() as $r) visitlog_meta_set($db, 'folded:' . $r['src'], visitlog_folded($db, $r['src']) + (int) $r['c']);   // (a trimmed visitor stays in the "earlier visitors" count)
    $d('events', 't1 < CAST(? AS INTEGER)'); $d('blocked', 'last < CAST(? AS INTEGER)'); $d('folders', 'last < CAST(? AS INTEGER)'); $d('items', 'MAX(sf, sl, opf, opl, pll, dwl) < CAST(? AS INTEGER)'); $d('visitors', 'last < CAST(? AS INTEGER)');
    $dn = $db->prepare('DELETE FROM notes WHERE last < CAST(? AS INTEGER)' . $w); $dn->execute($a);
    return $n;
}

/** Trim the log: takes what is older than $cutoff (a timestamp) out of one source's records (a link's id, or the public address's) or, with $id null, out of all of them, and returns what went:
 *  ['events', 'visitors', 'blocked', 'items', 'folders']. Totals (opens, the "earlier visitors" count) stay. Without $apply it only counts. */
function link_log_trim($id, $cutoff, $apply) {
    $none = ['events' => 0, 'visitors' => 0, 'blocked' => 0, 'items' => 0, 'folders' => 0];
    if (!is_file(visitlog_path())) return $none;
    $r = visitlog_tx(function ($db) use ($id, $cutoff, $apply) { return visitlog_trim_run($db, $id, $cutoff, $apply); });
    return is_array($r) ? $r : $none;
}

/** Once a day, what is older than the number of days Settings says goes (0 keeps everything until it is trimmed by hand). Assumes the caller holds the transaction. */
function visitlog_retention($db, $now) {
    if (LOG_KEEP_DAYS <= 0) return;
    if ($now - (int) visitlog_meta_get($db, 'trimmed_at') <= 86400) return;
    visitlog_trim_run($db, null, $now - LOG_KEEP_DAYS * 86400, true);
    visitlog_meta_set($db, 'trimmed_at', $now);
}

/** A link's records go only when the link itself is deleted (revoking, pausing and expiring keep them). */
function link_delete_log($id) {
    if (!is_file(visitlog_path())) return;
    visitlog_tx(function ($db) use ($id) {
        foreach (['visitors', 'blocked', 'notes', 'events', 'items', 'folders'] as $t) $db->prepare('DELETE FROM ' . $t . ' WHERE src = ?')->execute([$id]);
        $db->prepare('DELETE FROM meta WHERE k = ?')->execute(['folded:' . $id]);
    });
}


/** "Safari on iPhone": the browser and where it runs, as far as the User-Agent says. An app's own browser is named as such. */
function ua_label($ua) {
    $ua = (string) $ua;
    if ($ua === '') return 'Unknown browser';
    $os = '';
    foreach (['iPhone' => 'iPhone', 'iPad' => 'iPad', 'Android' => 'Android', 'Windows' => 'Windows', 'Macintosh|Mac OS X' => 'Mac', 'CrOS' => 'ChromeOS', 'Linux|X11' => 'Linux'] as $rx => $name) {
        if (preg_match('/' . $rx . '/', $ua)) { $os = $name; break; }
    }
    if (preg_match('/Instagram|FBAN|FBAV|FB_IAB|Messenger|Line\/|MicroMessenger|Snapchat|TikTok|Twitter|LinkedInApp|Pinterest|Slack|Discord/i', $ua, $m)) {
        $app = preg_match('/Instagram/i', $ua) ? 'Instagram' : (preg_match('/FBAN|FBAV|FB_IAB/i', $ua) ? 'Facebook' : ucfirst(strtolower(rtrim($m[0], '/'))));
        return $app . '\'s browser' . ($os !== '' ? ' on ' . $os : '');
    }
    if (preg_match('/bot|crawl|spider|preview|facebookexternalhit|whatsapp|telegram|discord|slack/i', $ua)) return 'A link preview';
    $b = 'A browser';
    if (preg_match('/Edg\//', $ua)) $b = 'Edge'; elseif (preg_match('/OPR\/|Opera/', $ua)) $b = 'Opera'; elseif (preg_match('/Firefox\/|FxiOS/', $ua)) $b = 'Firefox';
    elseif (preg_match('/CriOS|Chrome\//', $ua)) $b = 'Chrome'; elseif (preg_match('/Safari\//', $ua)) $b = 'Safari'; elseif (preg_match('/curl|wget|python|http/i', $ua)) $b = 'A script';
    return $b . ($os !== '' ? ' on ' . $os : '');
}

/** [visitor id, address, browser label, country] for this request. A visitor is an address together with a browser. */
function link_visitor_identity() {
    $ip = client_ip();
    $label = ua_label($_SERVER['HTTP_USER_AGENT'] ?? '');
    return [substr(md5($ip . '|' . $label), 0, 10), $ip, $label, client_country()];
}

/** A counted opening of the link (someone pressed the button). */
function link_log_opened($link) {
    if (link_preview() !== null) return;
    [$vid, $ip, $label, $country] = link_visitor_identity();
    $now = time(); $src = $link['id'];
    visitlog_tx(function ($db) use ($src, $vid, $ip, $label, $country, $now) {
        visitlog_touch($db, $src, $vid, $ip, $label, $country, $now);
        $db->prepare('UPDATE visitors SET opens = opens + 1 WHERE src = ? AND vid = ?')->execute([$src, $vid]);
        visitlog_event($db, $src, ['t' => $now, 'v' => $vid, 'k' => 'link']);
        visitlog_retention($db, $now);
    });
}

/** Someone pressed a real link that no longer lets anyone in (and why: its state), or an old address after a replace. */
function link_note_blocked($link, $state) {
    if (link_preview() !== null) return;
    [$vid, $ip, $label, $country] = link_visitor_identity();
    $now = time(); $src = $link['id'];
    visitlog_tx(function ($db) use ($src, $vid, $ip, $label, $country, $now, $state) {
        $st = $db->prepare('SELECT why FROM blocked WHERE src = ? AND vid = ?'); $st->execute([$src, $vid]);
        $row = $st->fetchColumn();
        $why = $row === false ? [] : (array) json_decode((string) $row, true);
        $why[$state] = (int) ($why[$state] ?? 0) + 1;
        $db->prepare('INSERT INTO blocked (src, vid, ip, ua, country, first, last, tries, why) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?) ON CONFLICT (src, vid) DO UPDATE SET last = excluded.last, tries = tries + 1, why = excluded.why')
           ->execute([$src, $vid, $ip, $label, $country, $now, $now, json_encode($why)]);
    });
}

/** Notes in the link's records that this visitor used one of the link's old addresses: 'via' for a kept one (the visitor is sent on and opens the
 *  link on the present address, so this is the only place it shows), 'tried' for a paused one (they saw the "not valid" page). */
function link_note_address($link, $kind, $name) {
    if (link_preview() !== null || !in_array($kind, ['via', 'tried'], true)) return;
    [$vid, $ip] = link_visitor_identity();
    $now = time(); $src = $link['id'];
    visitlog_tx(function ($db) use ($src, $kind, $vid, $ip, $name, $now) {
        $db->prepare('INSERT INTO notes (src, kind, vid, ip, name, n, last) VALUES (?, ?, ?, ?, ?, 1, ?) ON CONFLICT (src, kind, vid, name) DO UPDATE SET n = n + 1, last = excluded.last')
           ->execute([$src, $kind, $vid, $ip, (string) $name, $now]);
    });
}

/** Applies a batch of happenings to a source's records: [[kind, item], ...] with kinds s (seen), o (opened), p (played), d (downloaded), f (a folder page), z (a ZIP). */
function link_log_apply($link, $vid, $ip, $label, $country, $batch, $now = null, $arrive = null) {
    if (link_preview() !== null) return;
    $now = $now ?? time();
    $src = $link['id'];
    $isPublic = link_is_public_log($link);
    visitlog_tx(function ($db) use ($src, $vid, $ip, $label, $country, $batch, $now, $arrive, $isPublic) {
        $newSeen = [];   // folder => the items seen for the first time in this batch
        $getItem = $db->prepare('SELECT * FROM items WHERE src = ? AND vid = ? AND item = ?');
        $putItem = $db->prepare('INSERT OR REPLACE INTO items (src, vid, item, sf, sl, opn, opf, opl, pln, pll, dwn, dwl) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $putFolder = $db->prepare('INSERT INTO folders (src, vid, folder, n, first, last) VALUES (?, ?, ?, 1, ?, ?) ON CONFLICT (src, vid, folder) DO UPDATE SET n = n + 1, last = excluded.last');
        foreach ($batch as [$k, $item]) {
            if ($k === 'z') continue;   // a ZIP is one line in the timeline; its files arrive as saves of their own
            if ($k === 'f') { $putFolder->execute([$src, $vid, $item, $now, $now]); continue; }
            $getItem->execute([$src, $vid, $item]);
            $t = $getItem->fetch() ?: ['sf' => 0, 'sl' => 0, 'opn' => 0, 'opf' => 0, 'opl' => 0, 'pln' => 0, 'pll' => 0, 'dwn' => 0, 'dwl' => 0];
            if ($k === 's') {
                if (!(int) $t['sf']) { $t['sf'] = $now; $slash = strrpos($item, '/'); $newSeen[$slash === false ? '' : substr($item, 0, $slash)][] = $item; }
                $t['sl'] = $now;
            } elseif ($k === 'o') { $t['opn']++; if (!(int) $t['opf']) $t['opf'] = $now; $t['opl'] = $now; if (!(int) $t['sf']) $t['sf'] = $now; $t['sl'] = $now; }
            elseif ($k === 'p') { $t['pln']++; $t['pll'] = $now; }
            elseif ($k === 'd') { $t['dwn']++; $t['dwl'] = $now; if (!(int) $t['sf']) $t['sf'] = $now; }
            $putItem->execute([$src, $vid, $item, (int) $t['sf'], (int) $t['sl'], (int) $t['opn'], (int) $t['opf'], (int) $t['opl'], (int) $t['pln'], (int) $t['pll'], (int) $t['dwn'], (int) $t['dwl']]);
        }
        $st = $db->prepare('SELECT last FROM visitors WHERE src = ? AND vid = ?'); $st->execute([$src, $vid]);
        $prev = (int) $st->fetchColumn();
        visitlog_touch($db, $src, $vid, $ip, $label, $country, $now);
        if ($isPublic && $now - $prev > VISIT_WINDOW_SECONDS) {   // the public address has no button to press: a visit is a visitor back after half an hour
            $db->prepare('UPDATE visitors SET opens = opens + 1 WHERE src = ? AND vid = ?')->execute([$src, $vid]);
            visitlog_event($db, $src, ['t' => $now, 'v' => $vid, 'k' => 'link']);
        }
        if ($isPublic && $arrive !== null) {   // where this visit came in (the latest is kept on the visitor, each is a line of the timeline)
            $db->prepare('UPDATE visitors SET entry_f = ?, entry_h = ?, entry_u = ? WHERE src = ? AND vid = ?')->execute([(string) $arrive['f'], (string) ($arrive['h'] ?? ''), !empty($arrive['u']) ? 1 : 0, $src, $vid]);
            visitlog_event($db, $src, ['t' => $now, 'v' => $vid, 'k' => 'arrive', 'f' => (string) $arrive['f'], 'h' => (string) ($arrive['h'] ?? ''), 'u' => !empty($arrive['u'])]);
        }
        $views = 0;
        foreach ($batch as [$k, $item]) {
            if ($k === 'o' || $k === 'f') $views++;
            if ($k === 'o') visitlog_event($db, $src, ['t' => $now, 'v' => $vid, 'k' => 'opened', 'f' => $item]);
            elseif ($k === 'p') visitlog_event($db, $src, ['t' => $now, 'v' => $vid, 'k' => 'played', 'f' => $item]);
            elseif ($k === 'd') visitlog_event($db, $src, ['t' => $now, 'v' => $vid, 'k' => 'saved', 'f' => $item]);
            elseif ($k === 'f') visitlog_event($db, $src, ['t' => $now, 'v' => $vid, 'k' => 'folder', 'f' => $item]);
            elseif ($k === 'z') { $zp = explode("\0", $item, 2); visitlog_event($db, $src, ['t' => $now, 'v' => $vid, 'k' => 'zip', 'f' => $zp[0], 'n' => (int) ($zp[1] ?? 0)]); }
        }
        if ($views > 0) $db->prepare('UPDATE visitors SET views = views + ? WHERE src = ? AND vid = ?')->execute([$views, $src, $vid]);
        // Scrolling is one line per stretch: pictures first seen in one folder within five minutes of the last such line extend it.
        foreach ($newSeen as $folder => $items) {
            $st = $db->prepare('SELECT id, n, s, t1 FROM events WHERE src = ? AND vid = ? AND k = \'scroll\' AND d = ? ORDER BY id DESC LIMIT 1');
            $st->execute([$src, $vid, (string) $folder]);
            $last = $st->fetch();
            if ($last && $now - (int) $last['t1'] < 300) {
                $s = (array) json_decode((string) $last['s'], true);
                foreach ($items as $it2) if (count($s) < 6) $s[] = $it2;
                $db->prepare('UPDATE events SET t1 = ?, n = n + ?, s = ? WHERE id = ?')->execute([$now, count($items), json_encode($s, JSON_UNESCAPED_SLASHES), (int) $last['id']]);
            } else {
                visitlog_event($db, $src, ['t' => $now, 't1' => $now, 'v' => $vid, 'k' => 'scroll', 'd' => (string) $folder, 'n' => count($items), 's' => array_slice($items, 0, 6)]);
            }
        }
        visitlog_retention($db, $now);
    });
}

/** The server noting that it sent a private picture to someone using a link (or an original, which is a save). */
function link_queue_delivery($link, $kind, $item) { link_queue_deliveries($link, [[$kind, $item]]); }

/** The same for many items at once (a ZIP's files): one transaction. */
function link_queue_deliveries($link, array $pairs) {
    if (!$pairs || link_preview() !== null) return;
    [$vid, $ip, $label, $country] = link_visitor_identity();
    link_log_apply($link, $vid, $ip, $label, $country, array_values($pairs));
}

/** ?linklog (a POST of {"e": [["s", "Rome/r1.jpg"], ...]}) from the page of someone using a link: what came on screen, what the viewer
 *  showed, what played, which folder page. Answers nothing (204). Anything the person couldn't see is ignored. */
function run_linklog_route($dir, $mediaExtensions, $self) {
    header('Cache-Control: no-store');
    $link = link_viewer();
    if ($link === null && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && public_log_wanted()) $link = public_log_link();   // an ordinary visitor, when the public address's log is on
    if ($link === null || is_revealed() || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(204); return; }
    $raw = (string) file_get_contents('php://input', false, null, 0, 20000);
    $in = json_decode($raw, true);
    $batch = [];
    if (is_array($in) && is_array($in['e'] ?? null)) {
        $mine = [];
        foreach (array_slice($in['e'], 0, 120) as $e) {
            if (!is_array($e) || count($e) !== 2 || !is_string($e[0]) || !is_string($e[1]) || !in_array($e[0], ['s', 'o', 'p', 'f'], true) || strlen($e[1]) > 600 || strpbrk($e[1], "\0\r\n") !== false) continue;
            [$k, $item] = $e;
            if ($k === 'f') {
                if ($item !== '' && (!is_dir(resolve_real_path($dir, $item)) || !preg_match('#^[^/]#', $item) || str_contains($item, '..'))) continue;
                if ($item !== '' && folder_is_private($dir, $item) && !link_folder_reachable($link, $dir, $item)) continue;
                $batch[] = [$k, $item]; continue;
            }
            if (!valid_media_path($dir, $item, $mediaExtensions, $self)) continue;
            if (!isset($mine[$item])) { $mine[$item] = viewer_may_see_item($dir, $item, items_get_all_for_files($dir, [$item])); }
            if ($mine[$item]) $batch[] = [$k, $item];
        }
    }
    // How the visit began (the public address's log only): where someone came in, when it was an arrival and not a click inside the gallery.
    $arrive = null;
    if (is_array($in) && is_array($in['a'] ?? null) && link_is_public_log($link)) {
        $a = $in['a']; $af = $a['f'] ?? null; $ah = $a['h'] ?? '';
        if (is_string($af) && strlen($af) <= 600 && strpbrk($af, "\0\r\n") === false && in_array($ah, ['typed', 'link', 'other'], true)) {
            if (!empty($a['u'])) {   // a Not listed photo opened by its own address (the code is on this request too)
                if (valid_media_path($dir, $af, $mediaExtensions, $self) && file_is_unlisted($dir, $af) && viewer_may_see_item($dir, $af, items_get_all_for_files($dir, [$af]))) $arrive = ['f' => $af, 'h' => $ah, 'u' => 1];
            } elseif ($af === '' || ($af[0] !== '/' && !str_contains($af, '..') && is_dir(resolve_real_path($dir, $af)) && !folder_is_private($dir, $af))) $arrive = ['f' => $af, 'h' => $ah];
        }
    }
    if ($batch || $arrive) {
        [$vid, $ip, $label, $country] = link_visitor_identity();
        link_log_apply($link, $vid, $ip, $label, $country, $batch, null, $arrive);
    }
    http_response_code(204);
}

/** Does the link reach this folder (a path, not a file)? */
function link_folder_reachable($l, $dir, $folderRel) {
    $type = $l['scope']['type'] ?? '';
    if ($type === 'all') return true;
    if ($type === 'folders' || $type === 'mixed') return link_item_under_folder_ids($dir, $folderRel . '/x', $l['scope']['folders'] ?? []);
    return false;
}

