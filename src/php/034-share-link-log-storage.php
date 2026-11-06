/* -----------------------------------------------------------------------
 * The log of a share link. Each link keeps its own, in guarded data files
 * that only the link's deletion removes (pausing, revoking or letting a link
 * end never does): who opened it (an address and a browser: "visitors"), the
 * tries that were turned away, what each visitor saw, opened, played and downloaded
 * (per item, with first and last time), the folders they looked at, and the
 * latest 300 happenings in order. "Seen" is reported by the visitor's own
 * browser as pictures come on screen (so a CDN or the browser's cache can't hide
 * it), "opened" when the viewer shows a picture, "played" when a video plays;
 * "downloaded" is noted by the server when an original or a ZIP is sent. For a
 * private picture the server also notes each delivery, so a script blocker can't
 * hide the sensitive ones: those notes are appended to a small queue file (a
 * cheap write) and folded into the log the next time it is written or read.
 * ----------------------------------------------------------------------- */


function link_log_path($id, $part = '') { return __DIR__ . '/.gallery-linklog-' . $id . $part . '.php'; }
function link_log_default() { return ['v' => 1, 'visitors' => [], 'blocked' => [], 'events' => [], 'folded' => 0, 'last' => 0]; }
function link_items_default() { return ['t' => [], 'f' => []]; }   // t: "visitor|item" => tally; f: "visitor|folder" => [times, first, last]
function link_log_read($id) { return json_file_read(link_log_path($id), link_log_default()); }
function link_items_read($id) { return json_file_read(link_log_path($id, '-items'), link_items_default()); }
function link_log_update($id, $fn) { if (link_preview() !== null) return link_log_default(); return json_file_update(link_log_path($id), link_log_default(), $fn); }
function link_items_update($id, $fn) { if (link_preview() !== null) return link_items_default(); return json_file_update(link_log_path($id, '-items'), link_items_default(), $fn); }

/** Trim the log: takes what is older than $cutoff (a timestamp) out of a link's log and returns what went. Totals (opens, the "earlier
 *  visitors" count) stay: a trimmed visitor is added to that count. Works on a copy when only counting. */
function link_log_trim_data(&$log, $cutoff) {
    $n = ['events' => 0, 'visitors' => 0, 'blocked' => 0];
    $keep = [];
    foreach ((array) ($log['events'] ?? []) as $e) { if ((int) ($e['t1'] ?? $e['t'] ?? 0) < $cutoff) $n['events']++; else $keep[] = $e; }
    $log['events'] = $keep;
    foreach ((array) ($log['visitors'] ?? []) as $k => $v) { if ((int) ($v['last'] ?? 0) < $cutoff) { unset($log['visitors'][$k]); $n['visitors']++; $log['folded'] = (int) ($log['folded'] ?? 0) + 1; } }
    foreach ((array) ($log['blocked'] ?? []) as $k => $b) { if ((int) ($b['last'] ?? 0) < $cutoff) { unset($log['blocked'][$k]); $n['blocked']++; } }
    foreach (['via', 'tried'] as $kind) foreach ((array) ($log[$kind] ?? []) as $k => $e) { if ((int) ($e['last'] ?? 0) < $cutoff) unset($log[$kind][$k]); }
    return $n;
}
function link_items_trim_data(&$it, $cutoff) {
    $n = ['items' => 0, 'folders' => 0];
    foreach ((array) ($it['t'] ?? []) as $k => $t) {
        $last = max(array_map('intval', array_intersect_key((array) $t, array_flip(['sf', 'sl', 'of', 'ol', 'pl', 'dl']))) ?: [0]);
        if ($last < $cutoff) { unset($it['t'][$k]); $n['items']++; }
    }
    foreach ((array) ($it['f'] ?? []) as $k => $f) { if ((int) ($f[2] ?? 0) < $cutoff) { unset($it['f'][$k]); $n['folders']++; } }
    return $n;
}
/** What would go (or, with $apply, what went) from one link's log: ['events', 'visitors', 'blocked', 'items', 'folders']. */
function link_log_trim($id, $cutoff, $apply) {
    link_queue_merge($id);
    $n = ['events' => 0, 'visitors' => 0, 'blocked' => 0, 'items' => 0, 'folders' => 0];
    if (!$apply) {
        $log = link_log_read($id); $it = link_items_read($id);
        return array_merge($n, link_log_trim_data($log, $cutoff), link_items_trim_data($it, $cutoff));
    }
    $a = $n; $b = [];
    link_log_update($id, function (&$log) use ($cutoff, &$a) { $a = link_log_trim_data($log, $cutoff); });
    link_items_update($id, function (&$it) use ($cutoff, &$b) { $b = link_items_trim_data($it, $cutoff); });
    return array_merge($n, $a, $b);
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

/** Makes sure the visitor has a row (making room by folding the least recently seen one into a count); returns nothing. */
function link_log_touch_visitor(&$log, $vid, $ip, $label, $country, $now) {
    if (!isset($log['visitors'][$vid])) {
        if (count($log['visitors']) >= LINK_LOG_VISITORS) {
            uasort($log['visitors'], function ($a, $b) { return (int) $a['last'] <=> (int) $b['last']; });
            array_shift($log['visitors']);
            $log['folded'] = (int) $log['folded'] + 1;
        }
        $log['visitors'][$vid] = ['ip' => $ip, 'ua' => $label, 'country' => $country, 'first' => $now, 'last' => $now, 'opens' => 0, 'views' => 0];
    }
    $log['visitors'][$vid]['last'] = $now;
    if ($country !== '') $log['visitors'][$vid]['country'] = $country;
    $log['last'] = $now;
}

function link_log_add_event(&$log, $ev) {
    $log['events'][] = $ev;
    if (count($log['events']) > LINK_LOG_EVENTS) $log['events'] = array_slice($log['events'], -LINK_LOG_EVENTS);
}

/** A counted opening of the link (someone pressed the button). */
function link_log_opened($link) {
    [$vid, $ip, $label, $country] = link_visitor_identity();
    $now = time();
    link_log_update($link['id'], function (&$log) use ($vid, $ip, $label, $country, $now) {
        link_log_touch_visitor($log, $vid, $ip, $label, $country, $now);
        $log['visitors'][$vid]['opens']++;
        link_log_add_event($log, ['t' => $now, 'v' => $vid, 'k' => 'link']);
    });
}

/** Someone pressed a real link that no longer lets anyone in (and why: its state), or an old address after a replace. */
function link_note_blocked($link, $state) {
    [$vid, $ip, $label, $country] = link_visitor_identity();
    $now = time();
    link_log_update($link['id'], function (&$log) use ($vid, $ip, $label, $country, $now, $state) {
        if (!isset($log['blocked'][$vid]) && count($log['blocked']) >= LINK_LOG_BLOCKED) {
            uasort($log['blocked'], function ($a, $b) { return (int) $a['last'] <=> (int) $b['last']; });
            array_shift($log['blocked']);
        }
        $b = $log['blocked'][$vid] ?? ['ip' => $ip, 'ua' => $label, 'country' => $country, 'first' => $now, 'last' => $now, 'tries' => 0, 'why' => []];
        $b['last'] = $now; $b['tries']++; $b['why'][$state] = (int) ($b['why'][$state] ?? 0) + 1;
        $log['blocked'][$vid] = $b;
        $log['last'] = $now;
    });
}

/** Applies a batch of happenings to a link's log: [[kind, item], ...] with kinds s (seen), o (opened), p (played), d (downloaded), f (a folder page). */
function link_log_apply($link, $vid, $ip, $label, $country, $batch, $now = null, $arrive = null) {
    $now = $now ?? time();
    $id = $link['id'];
    $newSeen = [];   // folder => the items seen for the first time in this batch
    link_items_update($id, function (&$it) use ($batch, $vid, $now, &$newSeen) {
        foreach ($batch as [$k, $item]) {
            if ($k === 'z') continue;   // a ZIP is one line in the timeline; its files arrive as saves of their own
            if ($k === 'f') {
                $key = $vid . '|' . $item;
                $f = $it['f'][$key] ?? [0, $now, $now];
                $f[0]++; $f[2] = $now;
                if (isset($it['f'][$key]) || count($it['f']) < LINK_LOG_ITEMS) $it['f'][$key] = $f;
                continue;
            }
            $key = $vid . '|' . $item;
            if (!isset($it['t'][$key]) && count($it['t']) >= LINK_LOG_ITEMS) continue;
            $t = $it['t'][$key] ?? [];
            if ($k === 's') {
                if (!isset($t['sf'])) { $t['sf'] = $now; $slash = strrpos($item, '/'); $newSeen[$slash === false ? '' : substr($item, 0, $slash)][] = $item; }
                $t['sl'] = $now;
            } elseif ($k === 'o') { $t['on'] = (int) ($t['on'] ?? 0) + 1; $t['of'] = $t['of'] ?? $now; $t['ol'] = $now; if (!isset($t['sf'])) { $t['sf'] = $now; } $t['sl'] = $now; }
            elseif ($k === 'p') { $t['pn'] = (int) ($t['pn'] ?? 0) + 1; $t['pl'] = $now; }
            elseif ($k === 'd') { $t['dn'] = (int) ($t['dn'] ?? 0) + 1; $t['dl'] = $now; if (!isset($t['sf'])) $t['sf'] = $now; }
            $it['t'][$key] = $t;
        }
    });
    $isPublic = link_is_public_log($link); $didTrim = false;
    link_log_update($id, function (&$log) use ($batch, $vid, $ip, $label, $country, $now, $newSeen, $isPublic, $arrive, &$didTrim) {
        $prev = (int) ($log['visitors'][$vid]['last'] ?? 0);
        link_log_touch_visitor($log, $vid, $ip, $label, $country, $now);
        if ($isPublic && $now - $prev > VISIT_WINDOW_SECONDS) {   // the public address has no button to press: a visit is a visitor back after half an hour
            $log['visitors'][$vid]['opens']++;
            link_log_add_event($log, ['t' => $now, 'v' => $vid, 'k' => 'link']);
        }
        if ($isPublic && $arrive !== null) {   // where this visit came in (the latest is kept on the visitor, each is a line of the timeline)
            $log['visitors'][$vid]['entry'] = $arrive;
            link_log_add_event($log, ['t' => $now, 'v' => $vid, 'k' => 'arrive'] + $arrive);
        }
        foreach ($batch as [$k, $item]) {
            if ($k === 'o' || $k === 'f') $log['visitors'][$vid]['views']++;
            if ($k === 'o') link_log_add_event($log, ['t' => $now, 'v' => $vid, 'k' => 'opened', 'f' => $item]);
            elseif ($k === 'p') link_log_add_event($log, ['t' => $now, 'v' => $vid, 'k' => 'played', 'f' => $item]);
            elseif ($k === 'd') link_log_add_event($log, ['t' => $now, 'v' => $vid, 'k' => 'saved', 'f' => $item]);
            elseif ($k === 'f') link_log_add_event($log, ['t' => $now, 'v' => $vid, 'k' => 'folder', 'f' => $item]);
            elseif ($k === 'z') { $zp = explode("\0", $item, 2); link_log_add_event($log, ['t' => $now, 'v' => $vid, 'k' => 'zip', 'f' => $zp[0], 'n' => (int) ($zp[1] ?? 0)]); }
        }
        // Scrolling is one line per stretch: pictures first seen in one folder within five minutes of the last such line extend it.
        foreach ($newSeen as $folder => $items) {
            $merged = false;
            for ($i = count($log['events']) - 1; $i >= 0 && $i >= count($log['events']) - 40; $i--) {
                $e = $log['events'][$i];
                if (($e['k'] ?? '') === 'scroll' && ($e['v'] ?? '') === $vid && ($e['d'] ?? null) === $folder && $now - (int) $e['t1'] < 300) {
                    $log['events'][$i]['t1'] = $now; $log['events'][$i]['n'] += count($items);
                    foreach ($items as $it2) if (count($log['events'][$i]['s']) < 6) $log['events'][$i]['s'][] = $it2;
                    $merged = true; break;
                }
            }
            if (!$merged) link_log_add_event($log, ['t' => $now, 't1' => $now, 'v' => $vid, 'k' => 'scroll', 'd' => $folder, 'n' => count($items), 's' => array_slice($items, 0, 6)]);
        }
        if ($isPublic && LOG_PUBLIC_DAYS > 0 && $now - (int) ($log['trimmedAt'] ?? 0) > 86400) {   // once a day, what is older than the chosen number of days goes
            link_log_trim_data($log, $now - LOG_PUBLIC_DAYS * 86400);
            $log['trimmedAt'] = $now; $didTrim = true;
        }
    });
    if ($didTrim) link_items_update($id, function (&$it) use ($now) { link_items_trim_data($it, $now - LOG_PUBLIC_DAYS * 86400); });
}

/** The server noting that it sent a private picture to someone using a link (or an original, which is a save): a cheap append. */
function link_queue_delivery($link, $kind, $item) {
    if (link_preview() !== null) return;
    [$vid, $ip, $label, $country] = link_visitor_identity();
    $path = link_log_path($link['id'], '-q');
    $new = !is_file($path);
    $fh = @fopen($path, 'ab');
    if (!$fh) return;
    if (flock($fh, LOCK_EX)) {
        if ($new || ftell($fh) === 0) fwrite($fh, data_file_guard_line());
        fwrite($fh, json_encode([time(), $vid, $ip, $label, $country, $kind, $item], JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) . "\n");
        flock($fh, LOCK_UN);
    }
    fclose($fh);
}

/** The same for many items at once (a ZIP's files): one open, one lock. */
function link_queue_deliveries($link, array $pairs) {
    if (!$pairs || link_preview() !== null) return;
    [$vid, $ip, $label, $country] = link_visitor_identity();
    $path = link_log_path($link['id'], '-q');
    $new = !is_file($path);
    $fh = @fopen($path, 'ab');
    if (!$fh) return;
    if (flock($fh, LOCK_EX)) {
        if ($new || ftell($fh) === 0) fwrite($fh, data_file_guard_line());
        $now = time(); $text = '';
        foreach ($pairs as [$kind, $item]) $text .= json_encode([$now, $vid, $ip, $label, $country, $kind, $item], JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) . "\n";
        fwrite($fh, $text);
        flock($fh, LOCK_UN);
    }
    fclose($fh);
}

/** Folds the queue of server notes into the log (before the log is read or written by anything else). */
function link_queue_merge($id) {
    $path = link_log_path($id, '-q');
    if (!is_file($path) || filesize($path) < 60) return;
    $fh = @fopen($path, 'c+');
    if (!$fh) return;
    $rows = [];
    if (flock($fh, LOCK_EX)) {
        $first = true;
        while (($line = fgets($fh)) !== false) {
            if ($first) { $first = false; if (strncmp($line, '<?php', 5) === 0) continue; }
            $r = json_decode(trim($line), true);
            if (is_array($r) && count($r) === 7) $rows[] = $r;
        }
        ftruncate($fh, 0); rewind($fh); fwrite($fh, data_file_guard_line()); fflush($fh);
        flock($fh, LOCK_UN);
    }
    fclose($fh);
    $link = ['id' => $id];
    $byVisitor = [];
    foreach ($rows as [$t, $vid, $ip, $label, $country, $kind, $item]) $byVisitor[$vid . '|' . $ip . '|' . $label . '|' . $country][] = [$t, $kind, $item];
    foreach ($byVisitor as $key => $list) {
        [$vid, $ip, $label, $country] = explode('|', $key, 4);
        $batch = array_map(function ($x) { return [$x[1], $x[2]]; }, $list);
        link_log_apply($link, $vid, $ip, $label, $country, $batch, $list[count($list) - 1][0]);
    }
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

