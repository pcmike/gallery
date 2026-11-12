/* -----------------------------------------------------------------------
 * The Health check as a dashboard: the rows for the database, the backup, the share links and collections files, and the server. Each row is
 * added with $add($group, $status, $label, $detail, $link, $html). The statuses (see health_checks and the panel):
 *   bad / warn : something to fix now (counted in "N things to fix"; bad is red)
 *   opt        : a suggestion, something that works but has a worthwhile action (counted separately, "N suggestions")
 *   heads      : nothing to fix, worth a look (an amber "!"; not counted)
 *   info       : a fact          ok : checked and fine
 * ----------------------------------------------------------------------- */

/** "3 days ago", "today (5 hours ago)", "just now": no time zone to get wrong. */
function health_ago($t) {
    $d = max(0, time() - (int) $t);
    if ($d < 90) return 'just now';
    if ($d < 3600) return (int) round($d / 60) . ' minutes ago';
    if ($d < 86400) { $h = (int) round($d / 3600); return $h . ' hour' . ($h === 1 ? '' : 's') . ' ago'; }
    $n = (int) floor($d / 86400);
    return $n . ' day' . ($n === 1 ? '' : 's') . ' ago';
}
/** The same, short enough for a small box: "just now", "12m ago", "17h ago", "3d ago". */
function health_ago_short($t) {
    $d = max(0, time() - (int) $t);
    if ($d < 90) return 'just now';
    if ($d < 3600) return (int) round($d / 60) . 'm ago';
    if ($d < 86400) return (int) round($d / 3600) . 'h ago';
    return (int) floor($d / 86400) . 'd ago';
}
function health_bytes($b) { return $b >= 1073741824 ? round($b / 1073741824, 1) . ' GB' : ($b >= 1048576 ? round($b / 1048576, $b >= 10485760 ? 0 : 1) . ' MB' : max(1, (int) ceil($b / 1024)) . ' KB'); }

/** The checks that take a moment (each database looked through, every folder's data files listed), kept for an hour and looked at again by "Check again". */
function health_heavy($dir, $files, $force) {
    $p = __DIR__ . '/.gallery-health-cache.php';
    $c = is_file($p) ? json_file_read($p, []) : [];
    $live = visitlog_find();
    $same = (($c['log'] ?? null) === null) === ($live === null) && ($c['file'] ?? '') === ($live === null ? '' : basename($live));   // (a log that came or went, or was swapped, since the last look: looked at again)
    if (!$force && $same && array_key_exists('log', $c) && isset($c['at'], $c['stats']) && time() - (int) $c['at'] < 3600 && ($c['v'] ?? '') === GALLERY_VERSION) return $c;
    $r = ['at' => time(), 'v' => GALLERY_VERSION, 'log' => null, 'file' => $live === null ? '' : basename($live), 'stats' => ['n' => 0, 'bad' => []]];
    if ($live !== null && visitlog_driver_ok()) $r['log'] = ['why' => backup_sqlite_check($live)];
    foreach (owned_plan($dir, $files)['entries'] as $e) {
        if ($e['kind'] !== 'stats' || substr($e['path'], -7) !== '.sqlite') continue;
        $r['stats']['n']++;
        $w = backup_sqlite_check($e['path']);
        if ($w !== '') $r['stats']['bad'][] = [$e['rel'], $w];
    }
    if (is_writable(__DIR__)) json_file_update($p, [], function (&$d) use ($r) { $d = $r; });
    return $r;
}

/** Does the backup hold a visitor log? (so "Restore" can be offered) */
function backup_has_log() {
    $zip = backup_find(); if ($zip === null) return false;
    $ix = backup_zip_index($zip); if ($ix === null) return false;
    foreach ($ix as $n => $m) if (preg_match('~^\.gallery-visits-[0-9a-f]{24}\.sqlite$~', $n)) return true;
    return false;
}

function health_backup_buttons($withRestore, $withNew, $withDownload) {
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $b = '<div class="health-act">';
    if ($withRestore) { $bk = backup_status(); $b .= '<button type="button" class="secondary" data-backup-act="restore" data-backup-ask="Put the log from the backup (made ' . $esc(health_ago($bk['at'])) . ') in place of the damaged one? The damaged one is kept, renamed with .damaged- and the date.">Restore the log from the backup</button> '; }
    if ($withNew) $b .= '<button type="button" class="secondary" data-backup-act="newlog" data-backup-ask="Set the visitor log aside and start a new, empty one? The old file is kept, renamed with .damaged- and the date, but the Visitors and Changes tabs start from nothing.">Set it aside and start a new log</button> ';
    if ($withDownload) $b .= '<button type="button" class="secondary" data-backup-act="run">Back up now</button> <button type="button" class="secondary" data-backup-act="download">Download</button> ';
    return $b . '<span class="muted health-act-out" role="status" aria-live="polite"></span></div>';
}

/** The database (visitor log, change log, lockouts: one SQLite file), the view counts' databases and the backup. */
function health_database_rows($add, $dir, $files) {
    $G = 'Database and backup';
    $heavy = health_heavy($dir, $files, !empty($_POST['recheck']));
    $uses = 'The Visitors tab, the record of who opened each share link, the lockout history and the Changes tab are kept in it. Share links themselves keep working without it.';
    [$vlOk, $vlWhy] = visitlog_check_quiet();
    $bk = backup_status();
    $logFile = visitlog_find();
    if ($logFile !== null && ($heavy['log']['why'] ?? '') !== '') {   // (a damaged file is looked at first: it also makes the check below fail, and "can't be written" would be the wrong thing to say)
        $add($G, 'bad', 'The visitor log is damaged', 'The file ' . $heavy['log']['why'] . '. ' . $uses . ' Only that history is at risk: your photos, share links, settings and captions are in other files. ' . ($bk['have'] && backup_has_log() ? 'The backup from ' . health_ago($bk['at']) . ' has a sound copy.' : 'There is no sound copy in a backup.'),
            null, health_backup_buttons($bk['have'] && backup_has_log(), true, false));
    } elseif (!$vlOk) {
        $add($G, (LOG_PUBLIC_VISITS || ENABLE_SHARE_LINKS) ? 'warn' : 'info', 'Visitor log: needs SQLite', $vlWhy . ' Until then nothing is logged and the Visitors and Changes tabs stay hidden. ' . $uses, null, '', [
            'PHP needs its SQLite extension (`pdo_sqlite`). Debian or Ubuntu: `sudo apt install php-sqlite3`, then reload PHP-FPM (or Apache). Shared hosting: switch `pdo_sqlite` on in the host\'s PHP extensions page.',
            'Then press Check again at the top of this page.']);
    } elseif ($logFile !== null) {
        $vlSize = visitlog_size(); $vlOld = visitlog_oldest(); $vlText = health_bytes($vlSize);
        if (LOG_WARN_MB > 0 && $vlSize > LOG_WARN_MB * 1048576)
            $add($G, 'opt', 'The visitor records take ' . $vlText, 'More than the ' . number_format(LOG_WARN_MB) . ' MB you asked to be warned at. The oldest record is from ' . ($vlOld > 0 ? gmdate('j M Y', $vlOld) : 'an unknown date') . '. Trim the log in the Visitors tab, or set how long records are kept in Settings.', ['visits', 'vt-root', '→ Visitors']);
        else $add($G, 'ok', 'Visitor log: sound', 'SQLite works here and the file was looked through (' . health_ago($heavy['at']) . '). ' . number_format(visitlog_visitor_count()) . ' visitor' . (visitlog_visitor_count() === 1 ? '' : 's') . ' logged, taking ' . $vlText . '.' . ($vlOld > 0 ? ' The oldest record is from ' . gmdate('j M Y', $vlOld) . '.' : ''));
    } else {
        $add($G, 'ok', 'Visitor log: ready', 'SQLite works here. Nothing has been logged yet.');
    }
    if (changes_tab_wanted() && ($chN = changes_count()) > 0) {   // --- the Changes tab's log of disk activity (the same file)
        $chSize = changes_size(); $chOld = changes_oldest(); $chText = health_bytes($chSize);
        if (CHANGES_WARN_MB > 0 && $chSize > CHANGES_WARN_MB * 1048576)
            $add($G, 'opt', 'The change records take ' . $chText, 'More than the ' . number_format(CHANGES_WARN_MB) . ' MB you asked to be warned at. The oldest entry is from ' . ($chOld > 0 ? gmdate('j M Y', $chOld) : 'an unknown date') . '. Trim the log in the Changes tab, or set how long entries are kept in Settings (Visitors and logs).');
        else $add($G, 'ok', 'Change log: ready', number_format($chN) . ' entr' . ($chN === 1 ? 'y' : 'ies') . ' in the Changes tab, taking ' . $chText . '.' . ($chOld > 0 ? ' The oldest is from ' . gmdate('j M Y', $chOld) . '.' : ''));
    }
    if ($heavy['stats']['n'] > 0) {
        $bad = $heavy['stats']['bad'];
        if ($bad) {
            $names = implode('; ', array_map(function ($b) { return $b[0]; }, array_slice($bad, 0, 3))) . (count($bad) > 3 ? ' and ' . (count($bad) - 3) . ' more' : '');
            $add($G, 'bad', 'View counts are damaged in ' . count($bad) . ' folder' . (count($bad) === 1 ? '' : 's'), $names . '. Counting views there does not work until the file is replaced. The backup holds a copy of each one (unzip it over the folder: the README in the backup says how); or delete the damaged .gallery-stats.sqlite file and counting starts again from nothing.', null,
                $bk['have'] ? '<div class="health-act"><button type="button" class="secondary" data-backup-act="download">Download the backup</button> <span class="muted health-act-out" role="status" aria-live="polite"></span></div>' : '');
        } else $add($G, 'ok', 'View counts: sound', number_format($heavy['stats']['n']) . ' folder' . ($heavy['stats']['n'] === 1 ? ' keeps its' : 's keep their') . ' view counts in a database of its own, ' . ($heavy['stats']['n'] === 1 ? 'looked through (' : 'all looked through (') . health_ago($heavy['at']) . ').');
    }
    // --- the backup
    $buttons = health_backup_buttons(false, false, true);
    $hasProblem = false; foreach ($bk['problems'] as $pr) if (is_array($pr)) $hasProblem = true;
    if (!is_writable(__DIR__)) $add($G, 'warn', 'No backup can be made', 'The gallery\'s folder is not writable by the web server, so the backup (a file beside index.php) can\'t be written. Make the folder writable.');
    elseif (!$bk['have']) {
        if ($bk['result'] === 'failed') $add($G, 'warn', 'There is no backup: the last try did not work', $bk['why'], null, $buttons);
        else $add($G, 'opt', 'No backup yet', 'The first one is made by itself after a coming visit, and then once a week. Or make it now.', null, $buttons);
    } elseif ($bk['result'] === 'failed' && $bk['tried'] > $bk['at']) {
        $add($G, 'warn', 'The last backup did not work', $bk['why'] . ' The backup from ' . health_ago($bk['at']) . ' is still there.', null, $buttons);
    } elseif ($hasProblem) {
        $parts = []; foreach ($bk['problems'] as $pr) if (is_array($pr)) $parts[] = $pr[0] . ' ' . $pr[1] . ($pr[2] === 'carried' ? ' (the copy from the backup before is kept)' : ' (no earlier copy: it is not in the backup)');
        $add($G, 'warn', 'Backup made ' . health_ago($bk['at']) . ', but ' . count($parts) . ' part' . (count($parts) === 1 ? '' : 's') . ' could not be read soundly', implode('. ', array_slice($parts, 0, 3)) . (count($parts) > 3 ? '. And ' . (count($parts) - 3) . ' more.' : '.') . ' A damaged file never replaces a good copy in the backup.', null, $buttons);
    } elseif (time() - $bk['at'] > 2 * backup_week()) {
        $add($G, 'opt', 'The backup is ' . health_ago($bk['at']), 'It is made once a week by the first visit after the week; none has come since. Make one now.', null, $buttons);
    } else {
        $add($G, 'ok', 'Backup: made ' . health_ago($bk['at']), number_format($bk['files']) . ' file' . ($bk['files'] === 1 ? '' : 's') . ', ' . health_bytes($bk['bytes']) . ', each one looked at before it went in and the whole ZIP read back (' . ($bk['by'] === 'you' ? 'asked for' : 'the weekly one') . '). The next is made by itself a week after this one. It holds your settings, share links, collections, captions, view counts and the visitor log, not your photos.', null, $buttons);
    }
}

/** The share links and collections files, and a few states of the public side worth knowing. */
function health_sharing_rows($add) {
    $G = 'Sharing and visitors';
    if (is_file(links_path())) {
        [$st, $data] = json_file_load(links_path(), links_default());
        if ($st === 'broken') $add($G, 'bad', 'The share links file can\'t be read', 'Every share link stops working until it can be read again. The file (.gallery-links.php) was left exactly as it is. The backup, if you have one, holds the last good copy: unzip it over the file (the README in it says how).', null, backup_find() !== null ? '<div class="health-act"><button type="button" class="secondary" data-backup-act="download">Download the backup</button> <span class="muted health-act-out" role="status" aria-live="polite"></span></div>' : '');
        else {
            $by = ['active' => 0, 'paused' => 0, 'ended' => 0]; $soon = 0; $now = time();
            foreach ((array) ($data['links'] ?? []) as $l) {
                if (!is_array($l) || link_is_public_log($l)) continue;
                $s = link_state($l, $now);
                if ($s === 'active' || $s === 'used_up') { $by['active']++; if ((int) ($l['endsAt'] ?? 0) > 0 && (int) $l['endsAt'] - $now < 7 * 86400) $soon++; }
                elseif ($s === 'paused') $by['paused']++;
                elseif ($s === 'expired' || $s === 'scheduled') $by['ended']++;
            }
            $tot = array_sum($by);
            $add($G, 'ok', 'Share links file: sound', $tot === 0 ? 'No share links yet.' : number_format($tot) . ' link' . ($tot === 1 ? '' : 's') . ': ' . $by['active'] . ' active, ' . $by['paused'] . ' paused, ' . $by['ended'] . ' ended or not started.');
            if ($soon > 0) $add($G, 'heads', $soon . ' share link' . ($soon === 1 ? ' ends' : 's end') . ' within a week', 'People using ' . ($soon === 1 ? 'it' : 'them') . ' lose access then. Extend ' . ($soon === 1 ? 'it' : 'them') . ' in the Shares tab if that is not what you want.', ['links', 'sl-root', '→ Shares']);
        }
    }
    if (is_file(collections_path())) {
        [$st] = json_file_load(collections_path(), collections_default());
        if ($st === 'broken') $add($G, 'bad', 'The collections file can\'t be read', 'Your collections are not shown until it can be read again. The file (.gallery-collections.php) was left exactly as it is; the backup holds the last good copy.');
    }
    if (gallery_is_closed()) $add($G, 'heads', 'The public gallery is closed', 'Someone who comes without a share link sees nothing. Only you, private-view visitors and share-link holders see photos.', ['site', 'setting-CLOSE_PUBLIC_GALLERY', '→ Close the public gallery']);
    if (LOG_PUBLIC_VISITS) $add($G, 'info', 'The public address is logged', 'Who opens the gallery without a link (their address and browser) is kept in the visitor log.', ['visits', 'vt-root', '→ Visitors']);
}

/** The disks the gallery's photos and files live on, least free space first: [{name, free, total, pct, writable}]. The gallery's own folder, every folder from elsewhere
 *  (mapped by name or as a root) and the folders its media sit in are grouped by the disk they are on (the device a folder is on); a disk that will not say how much is
 *  free (some network shares) is left out. Read-only ones are in too: the room left matters for the photos kept there whether or not the gallery writes to it. */
function health_volumes($dir, $files) {
    static $cache = [];
    $ck = rtrim((string) $dir, '/') . '|' . count((array) $files);
    if (isset($cache[$ck])) return $cache[$ck];
    $dir = rtrim((string) (realpath($dir) ?: $dir), '/');
    $named = is_array(EXTRA_FOLDERS) ? EXTRA_FOLDERS : [];
    $paths = [$dir => 'the gallery folder'];
    foreach ($named as $nm => $pth) if (is_string($pth) && is_dir($pth)) $paths[rtrim((string) (realpath($pth) ?: $pth), '/')] = is_string($nm) && $nm !== '' ? $nm : $pth;
    foreach ((is_array(EXTRA_ROOTS) ? EXTRA_ROOTS : []) as $pth) if (is_string($pth) && is_dir($pth)) { $r = rtrim((string) (realpath($pth) ?: $pth), '/'); if (!isset($paths[$r])) $paths[$r] = $r; }
    $seen = 0;
    foreach ((array) $files as $f) { $c = rtrim((string) containing_folder_real_path($dir, $f), '/'); if ($c !== '' && !isset($paths[$c])) { $paths[$c] = null; if (++$seen >= 300) break; } }
    $by = [];
    foreach ($paths as $p => $label) {
        $st = @stat($p); if (!$st) continue;
        $key = (string) $st['dev'];
        if ($label === null) {   // a folder only the scan found: named by the place it belongs to
            $label = strncmp($p . '/', $dir . '/', strlen($dir) + 1) === 0 ? 'the gallery folder' : $p;
            foreach ($named as $nm => $pth) { $np = rtrim((string) (realpath((string) $pth) ?: $pth), '/'); if ($np !== '' && strncmp($p . '/', $np . '/', strlen($np) + 1) === 0) { $label = (string) $nm; break; } }
        }
        if (!isset($by[$key])) {
            $free = @disk_free_space($p); $tot = @disk_total_space($p);
            if ($free === false || $tot === false || $tot <= 0) { $by[$key] = false; continue; }
            $by[$key] = ['names' => [], 'free' => (float) $free, 'total' => (float) $tot, 'writable' => false];
        }
        if ($by[$key] === false) continue;
        if (!in_array($label, $by[$key]['names'], true)) $by[$key]['names'][] = $label;
        if (is_writable($p)) $by[$key]['writable'] = true;
    }
    $out = [];
    foreach ($by as $v) {
        if (!$v) continue;
        $isHome = in_array('the gallery folder', $v['names'], true);
        $n = $v['names']; $name = implode(', ', array_slice($n, 0, 3)) . (count($n) > 3 ? ' and ' . (count($n) - 3) . ' more' : '');
        $out[] = ['name' => $isHome ? ucfirst($name) : $name, 'free' => $v['free'], 'total' => $v['total'], 'pct' => (int) round($v['free'] / $v['total'] * 100), 'writable' => $v['writable']];
    }
    // two entries with the very same size and free space are one disk seen twice (sub-volumes of one pool)
    $uniq = []; foreach ($out as $v) { $k = $v['total'] . '/' . $v['free']; if (isset($uniq[$k])) { $uniq[$k]['name'] .= ', ' . $v['name']; $uniq[$k]['writable'] = $uniq[$k]['writable'] || $v['writable']; } else $uniq[$k] = $v; }
    $out = array_values($uniq);
    usort($out, function ($a, $b) { return $a['free'] <=> $b['free']; });
    return $cache[$ck] = $out;
}

/** A "How to fix" fold for a row that needs a change on the server (something the gallery can't do from its own folder). $steps: a list of lines, each a text (`like this` is
 *  shown as code) or [text, code], where the code gets a box with a Copy button. $key + $hidden: for a row the browser tests, the fold starts hidden and the script shows it
 *  when the test fails. */
function health_how_html($steps, $key = '', $hidden = false) {
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $fmt = function ($t) use ($esc) { return preg_replace('/`([^`]+)`/', '<code>$1</code>', $esc($t)); };
    $h = '<details class="health-how"' . ($key !== '' ? ' data-how-for="' . $esc($key) . '"' : '') . ($hidden ? ' hidden' : '') . '><summary>How to fix</summary><div class="health-how-body">';
    foreach ($steps as $st) {
        $text = is_array($st) ? $st[0] : $st; $code = is_array($st) ? ($st[1] ?? '') : '';
        if ($text !== '') $h .= '<p>' . $fmt($text) . '</p>';
        if ($code !== '') $h .= '<div class="rules-box"><button type="button" class="secondary copy-rules">Copy</button><pre><code>' . $esc($code) . '</code></pre></div>';
    }
    return $h . '</div></details>';
}

/** The steps for a file the server must refuse (anything starting with .gallery-): the visitor log's and the backup's rows use them. */
function health_how_gallery_files() {
    return [
        ['nginx (a server of your own): add this inside the site\'s `server { }` block, then run `sudo nginx -t && sudo systemctl reload nginx`.', rtrim(gallery_files_rule_nginx())],
        ['Apache, or any host that reads `.htaccess` (FTP-only hosting too): save this as a file named `.htaccess` in the gallery\'s folder. If one is there already, add it at the end.', rtrim(gallery_files_rule_apache())],
        'This rule is also part of the block under Settings → Server setup → Web server rules, with the others the gallery can use.',
        'Then press the circular arrow at the top of this page (Check again): this row turns green when the server refuses the address.',
    ];
}

/** The server: disk room, the connection, the visitor address, what PHP allows. */
function health_server_rows($add) {
    $G = 'Server';
    $vols = health_volumes($GLOBALS['dir'], $GLOBALS['__admin_files'] ?? []);
    $many = count($vols) > 1;
    foreach ($vols as $v) {
        $where = $many ? ' on ' . $v['name'] : '';
        if ($v['free'] < 100 * 1048576) $add($G, 'warn', 'Disk space is almost gone' . $where . ': ' . health_bytes($v['free']) . ' free', $v['writable'] ? 'Thumbnails, logs and captions are written there; when the disk is full they stop being kept and settings can fail to save. New photos can\'t be added either.' : 'No room is left to add photos there. (The gallery only reads it, so nothing of its own is affected.)');
        elseif ($v['free'] < 1073741824) $add($G, 'opt', 'Disk space is getting low' . $where . ': ' . health_bytes($v['free']) . ' free', $v['writable'] ? 'Photos, thumbnails, logs and the backup grow over time. The Delete list in Settings shows what the gallery\'s own files take.' : 'Little room is left to add photos there. (The gallery only reads it.)');
    }
    if ($vols && !$many && $vols[0]['free'] >= 1073741824) $add($G, 'info', 'Disk space: ' . health_bytes($vols[0]['free']) . ' free (' . $vols[0]['pct'] . '%)');
    if ($many) {
        $lines = []; foreach ($vols as $v) $lines[] = $v['name'] . ': ' . health_bytes($v['free']) . ' free of ' . health_bytes($v['total']) . ' (' . $v['pct'] . '%)' . ($v['writable'] ? '' : ', read only');
        $add($G, 'info', 'Disk space on ' . count($vols) . ' disks', implode('. ', $lines) . '.');
    }
    if (is_https_request()) $add($G, 'ok', 'Connection: HTTPS', 'This page was reached over an encrypted connection.');
    elseif (!is_local_request()) $add($G, 'opt', 'You are using the admin over plain HTTP', 'Your sign-in code and the sign-in cookie cross the network unencrypted. Use the https:// address of this gallery (set it up on the web server if there is none).', null, '', [
            'A server of your own: get a free certificate from Let\'s Encrypt (`sudo certbot --nginx`, or `sudo certbot --apache`), then open the gallery by its `https://` address.',
            'Behind a proxy or tunnel that already does HTTPS (Cloudflare, Caddy, nginx in front, a tunnel): the gallery only sees plain HTTP, so have the proxy tell it. In nginx, in the proxy\'s location:', 
            ['', 'proxy_set_header X-Forwarded-Proto $scheme;'],
            'Shared hosting: switch on the free certificate in the host\'s control panel (often called SSL, or Let\'s Encrypt), then use the `https://` address.']);
    $hint = client_ip_proxy_hint();
    if ($hint !== '') $add($G, 'opt', 'Visitor addresses may be the proxy\'s, not the visitor\'s', $hint . ' Until then the visitor log and the sign-in pause see the proxy\'s address.', ['site', 'setting-TRUSTED_PROXY', '→ Visitor address'], '', [
            'Open Settings → Visitor address and choose what sits in front of the gallery: Cloudflare, or another proxy.',
            'For another proxy, also list the proxy\'s own address under the proxy addresses, so only it is believed (a tunnel or proxy on this same machine is `127.0.0.1/32`). Anyone else\'s claim about a visitor\'s address is ignored.',
            'To tell it worked: the Visitors tab then shows each visitor\'s own address, not the same one every time.']);
    if ((CHECK_FOR_UPDATES || COUNTRY_LOOKUP) && !function_exists('curl_init') && !filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN))
        $add($G, 'opt', 'This server lets PHP fetch no web addresses', 'Looking for a new version' . (COUNTRY_LOOKUP ? ' and downloading the country file' : '') . ' need either the curl extension or allow_url_fopen. Neither is on, so they can\'t run.', null, '', [
            'Install the curl extension. Debian or Ubuntu: `sudo apt install php-curl`, then reload PHP-FPM (or Apache). Shared hosting: switch curl on in the host\'s PHP extensions page.',
            'Or, in the php.ini that PHP-FPM reads, set `allow_url_fopen = On` and reload PHP-FPM.',
            'Then press Check again at the top of this page.']);
    // PHP's own picture library (GD) needs a photo in memory (about 100 MB for 12 megapixels). The gallery asks for 256M itself; this row is only for a server that refuses, and only
    // where GD is what makes the thumbnails (Imagick keeps pictures outside PHP's memory).
    if (!class_exists('Imagick') && extension_loaded('gd') && !image_memory_raise())
        $add($G, 'opt', 'PHP\'s memory limit is too low for big photos (' . trim((string) ini_get('memory_limit')) . ')', 'This server makes thumbnails with PHP\'s own picture library, which needs about 100 MB for a 12-megapixel photo. The gallery asked for more and the server said no, so large photos may get no thumbnail. Set memory_limit to 256M in the server\'s PHP settings (php.ini, or the host\'s PHP settings page).', null, '', [
            'A server of your own (nginx or Apache with PHP-FPM): edit the php.ini that PHP-FPM reads, for example `/etc/php/8.4/fpm/php.ini` (the command `php --ini` shows the command-line one; FPM keeps its own copy). Set `memory_limit = 256M`, then reload PHP-FPM, for example `sudo systemctl reload php8.4-fpm`.',
            'If the site\'s PHP-FPM pool file (under `/etc/php/8.4/fpm/pool.d/`) already has a line starting `php_admin_value[memory_limit]`, change that one instead: it overrides php.ini and is the usual reason the gallery was refused.',
            'Shared hosting: the host\'s PHP settings page, or a file named `.user.ini` next to `index.php` containing `memory_limit = 256M` (it can take a few minutes to be noticed).',
            'Then press Check again at the top of this page.']);
}

/** The numbers across the top: [[label, value]]. Only what is cheap to know. */
function health_strip($dir, $files) {
    $videos = 0; $n = 0;
    foreach ($files as $f) { $n++; if (is_video_file($f)) $videos++; }
    $out = [['Photos', number_format($n - $videos)]];
    if ($videos) $out[] = ['Videos', number_format($videos)];
    $folders = count(all_folder_paths($files)) + 1;   // (and the front page)
    if (collections_any()) $folders += count(collections_registry($dir, $files)['byPath']);   // (a collection is a card in the gallery like a folder; it is not a folder on disk, so the list above does not hold it)
    $out[] = ['Folders', number_format($folders)];
    if (is_file(links_path())) {
        $act = 0; $other = 0;
        foreach ((array) (links_read()['links'] ?? []) as $l) if (is_array($l) && !link_is_public_log($l)) { if (link_is_live($l)) $act++; else $other++; }
        // (the value is two numbers: the ones in use in green, the others, paused or ended, in red; the box says what each is when it is touched)
        $html = '<span class="hs-two">' . '<span class="hs-ok">' . number_format($act) . '</span>' . ($other ? '<span class="hs-bad">' . number_format($other) . '</span>' : '') . '</span>';   // (the two numbers sit at the left and the right of the box, not side by side)
        $out[] = ['Links', number_format($act) . ($other ? ' / ' . number_format($other) : ''), null, $html, number_format($act) . ' in use' . ($other ? ', ' . number_format($other) . ' paused, ended or not started yet' : '')];
    }
    if (visitlog_find() !== null && visitlog_driver_ok()) { $out[] = ['Visitors', number_format(visitlog_visitor_count())]; $out[] = ['Log', health_bytes(visitlog_size())]; }
    $bk = backup_status(); $out[] = ['Backup', $bk['have'] ? health_ago_short($bk['at']) : 'none', null, null, $bk['have'] ? 'The backup was made ' . health_ago($bk['at']) : 'No backup has been made yet'];
    $vols = health_volumes($dir, $files);
    if (count($vols) === 1) $out[] = ['Disk free', health_bytes($vols[0]['free'])];
    elseif ($vols) {
        $lines = []; foreach ($vols as $v) $lines[] = [$v['name'], health_bytes($v['free']), health_bytes($v['total'])];
        $out[] = ['Disk free', health_bytes($vols[0]['free']), $lines];   // (the lowest of them; the chip opens the list)
    }
    return $out;
}
