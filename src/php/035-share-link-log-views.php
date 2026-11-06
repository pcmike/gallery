/* -----------------------------------------------------------------------
 * What the log shows the owner (all server-rendered fragments, fetched into
 * the Shares tab): a link's visitors, its folders and its photos; one
 * address across every link (folders, media, timeline); the list of every
 * address; and the CSV exports.
 * ----------------------------------------------------------------------- */

/** A link's log with the server's queued notes folded in, plus its per-item tallies. */
function link_activity($id) {
    static $busy = false;
    if (!$busy) { $busy = true; link_queue_merge($id); $busy = false; }
    return [link_log_read($id), link_items_read($id)];
}

/** [[visitor id, item, tally], ...] from the items file, optionally for one visitor id. */
function link_item_rows($items, $onlyVid = null) {
    $out = [];
    foreach ($items['t'] as $key => $t) {
        [$vid, $rel] = explode('|', $key, 2) + [1 => ''];
        if ($onlyVid === null || $vid === $onlyVid) $out[] = [$vid, $rel, $t];
    }
    return $out;
}

/** The folder an item lives in ('' for the gallery's own). */
function link_item_folder($rel) { $s = strrpos($rel, '/'); return $s === false ? '' : substr($rel, 0, $s); }

/** How many items are under a folder in the gallery (all of them: the owner's list). */
function link_folder_total($files, $folder) {
    static $counts = null;
    if ($counts === null) { $counts = ['' => count($files)]; foreach ($files as $f) { $d = link_item_folder($f); while ($d !== '') { $counts[$d] = ($counts[$d] ?? 0) + 1; $d = link_item_folder($d); } } }
    return $counts[$folder] ?? 0;
}

function link_csv_cell($v) {
    $s = (string) $v;
    if ($s !== '' && strpbrk($s[0], "=+-@\t\r") !== false) $s = "'" . $s;   // a spreadsheet would run it as a formula otherwise
    return '"' . str_replace('"', '""', $s) . '"';
}

function link_seg_html($name, $options, $current, $label = 'Choose one') {
    $out = '<div class="sl-segbar" role="group" aria-label="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '">';
    foreach ($options as $v => $t) $out .= '<button type="button" class="sl-segbtn' . ((string) $v === (string) $current ? ' on' : '') . '" data-tab="' . htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . '</button>';
    return $out . '</div>';
}

function link_place_html($country) { return htmlspecialchars($country !== '' ? $country : '—', ENT_QUOTES, 'UTF-8'); }

/** What the log pages take from the address bar besides the tab: addresses and folders (lists; "/" stands for the front page), the Media choice, one link, and "show all". */
function link_scope_filters() {
    $list = function ($k) {
        $out = [];
        foreach (isset($_GET[$k]) && is_array($_GET[$k]) ? array_slice($_GET[$k], 0, 300) : [] as $v) if (is_string($v)) { $v = str_replace("\0", '', $v); if ($v !== '') $out[$v] = true; }
        return array_keys($out);
    };
    $what = isset($_GET['what']) && is_string($_GET['what']) ? $_GET['what'] : '';
    return [
        'ips' => array_map('strval', $list('ips')),
        'folders' => array_map(function ($f) { return $f === '/' ? '' : (string) $f; }, $list('folders')),
        'what' => in_array($what, ['viewed', 'recent', 'photos', 'videos', 'private', 'opened', 'downloaded'], true) ? $what : 'viewed',
        'link' => isset($_GET['linkid']) && is_string($_GET['linkid']) && preg_match('/^[0-9a-f]{10}$/', $_GET['linkid']) ? $_GET['linkid'] : '',   // (not "link": that is a share link's own address)
        'all' => isset($_GET['all']) && $_GET['all'] === '1',
        'exact' => isset($_GET['exact']) && $_GET['exact'] === '1',   // (a folder's own files only, not the folders inside it: what a number on the Folders tab counted)
    ];
}

/** One scope's log, read once: the given links, keeping only visitors at the given addresses (none given: everyone). */
function link_scope_load($data, array $linkIds, array $ips) {
    $sc = ['links' => [], 'allVis' => [], 'vis' => [], 'rows' => [], 'fopen' => [], 'events' => [], 'full' => false];
    foreach ($linkIds as $lid) {
        $l = $data['links'][$lid] ?? null;
        if (!is_array($l)) continue;
        [$log, $items] = link_activity($lid);
        $sc['links'][$lid] = $l; $sc['allVis'][$lid] = $log['visitors'];
        $keep = [];
        foreach ($log['visitors'] as $vid => $v) if (!$ips || in_array($v['ip'], $ips, true)) $keep[$vid] = $v;
        $sc['vis'][$lid] = $keep;
        if (count($log['events']) >= LINK_LOG_EVENTS) $sc['full'] = true;
        foreach (link_item_rows($items) as [$vid, $rel, $t]) if (isset($keep[$vid])) $sc['rows'][] = [$lid, $vid, $rel, $t];
        foreach ($items['f'] as $key => $f) {
            [$vid, $rel] = explode('|', $key, 2) + [1 => ''];
            if (!isset($keep[$vid])) continue;
            $a = &$sc['fopen'][$rel]; $a = $a ?? ['n' => 0, 'first' => 0, 'last' => 0];
            $a['n'] += (int) $f[0]; $a['first'] = $a['first'] ? min($a['first'], (int) $f[1]) : (int) $f[1]; $a['last'] = max($a['last'], (int) $f[2]); unset($a);
        }
        foreach ($log['events'] as $e) if (isset($keep[$e['v'] ?? ''])) { $e['_link'] = $l['label']; $e['_pub'] = link_is_public_log($l); $e['_ip'] = $keep[$e['v']]['ip']; $sc['events'][] = $e; }
    }
    return $sc;
}

/** Per file, across the scope's visitors: when first seen, times opened / played / downloaded, last activity. */
function link_scope_media($sc) {
    $m = [];
    foreach ($sc['rows'] as [$lid, $vid, $rel, $t]) {
        $a = &$m[$rel]; $a = $a ?? ['sf' => 0, 'on' => 0, 'pn' => 0, 'dn' => 0, 'last' => 0];
        if (isset($t['sf'])) $a['sf'] = $a['sf'] ? min($a['sf'], (int) $t['sf']) : (int) $t['sf'];
        $a['on'] += (int) ($t['on'] ?? 0); $a['pn'] += (int) ($t['pn'] ?? 0); $a['dn'] += (int) ($t['dn'] ?? 0);
        $a['last'] = max($a['last'], (int) ($t['sl'] ?? 0), (int) ($t['ol'] ?? 0), (int) ($t['pl'] ?? 0), (int) ($t['dl'] ?? 0)); unset($a);
    }
    return $m;
}

/** Per folder: pages opened, files seen / opened (or played) / downloaded, first and last activity. Newest activity first. */
function link_scope_folders($sc, $m) {
    $f = [];
    $blank = ['opens' => 0, 'seen' => 0, 'opened' => 0, 'down' => 0, 'first' => 0, 'last' => 0];
    foreach ($m as $rel => $a) {
        $d = link_item_folder($rel); $x = &$f[$d]; $x = $x ?? $blank;
        if ($a['sf']) { $x['seen']++; $x['first'] = $x['first'] ? min($x['first'], $a['sf']) : $a['sf']; }
        if ($a['on'] || $a['pn']) $x['opened']++;
        if ($a['dn']) $x['down']++;
        $x['last'] = max($x['last'], $a['last']); unset($x);
    }
    foreach ($sc['fopen'] as $d => $o) {
        $x = &$f[$d]; $x = $x ?? $blank;
        $x['opens'] = $o['n']; $x['first'] = $x['first'] ? min($x['first'], $o['first']) : $o['first']; $x['last'] = max($x['last'], $o['last']); unset($x);
    }
    uasort($f, function ($a, $b) { return $b['last'] <=> $a['last']; });
    return $f;
}

/** One menu of the filter line: a button that opens a list of tick boxes (any number; the first row, "All", is ticked while none of the others is) or of round buttons (one). Both apply as soon as they are touched.
 *  $options: value => [text, text for the button, depth in the tree]. */
function link_pop_html($key, $multi, $allLabel, $noun, $options, $chosen, $default = '', $note = '', $exact = false) {
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $chosen = array_values(array_filter(array_map('strval', $chosen), function ($v) use ($options) { return isset($options[$v]); }));
    $n = count($chosen);
    if ($multi) $label = $n === 0 ? $allLabel : ($n === 1 ? $options[$chosen[0]][1] . ($exact ? ' only' : '') : $n . ' ' . $noun);
    else $label = $options[$chosen[0] ?? $default][1] ?? $allLabel;
    $active = $multi ? $n > 0 : ($chosen && $chosen[0] !== $default);
    $out = '<div class="sl-pop" data-pop="' . $esc($key) . '" data-multi="' . ($multi ? '1' : '0') . '"><button type="button" class="sl-popbtn' . ($active ? ' on' : '') . '" aria-haspopup="true" aria-expanded="false"><span class="sl-poplab">' . $esc($label) . '</span><span class="sl-caret" aria-hidden="true">&#9662;</span></button><div class="sl-popmenu" hidden>';
    if ($multi) $out .= '<label class="sl-popopt sl-popall"><input type="checkbox" data-all value=""' . ($n === 0 ? ' checked' : '') . '><span>' . $esc($allLabel) . '</span></label>';
    foreach ($options as $v => $o) {
        $on = $multi ? in_array((string) $v, $chosen, true) : ((string) ($chosen[0] ?? $default) === (string) $v);
        $depth = min(6, (int) ($o[2] ?? 0));
        $out .= '<label class="sl-popopt' . ($depth > 0 ? ' sl-d' . $depth : '') . '"><input type="' . ($multi ? 'checkbox' : 'radio') . '" name="pop-' . $esc($key) . '" value="' . $esc($v) . '"' . ($on ? ' checked' : '') . '><span>' . $esc($o[0]) . '</span></label>';
    }
    if ($note !== '') $out .= '<p class="sl-popnote">' . $esc($note) . '</p>';
    return $out . '</div></div>';
}

/** Is this folder one of the chosen folders, or inside one? (The front page is only itself.) $exact: only the chosen folders themselves. */
function link_folder_selected($f, $set, $exact) {
    if (isset($set[$f])) return true;
    if ($exact || $f === '') return false;
    for ($d = dirname($f); $d !== '.' && $d !== ''; $d = dirname($d)) if (isset($set[$d])) return true;
    return false;
}

/** The filter line under the tabs. In a link's page the addresses can be chosen; in an address's page its own address is named, and a menu of the links it used shows when it used more than one. */
function link_filter_bar($mode, $tab, $flt, $sc, $m, $dir, $ip, $linkOpts) {
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $out = '<div class="sl-filterbar">';
    if ($mode === 'ip') $out .= '<span class="sl-popbtn sl-popfixed"><span class="sl-poplab">' . $esc($ip) . '</span></span>';
    else {
        $byIp = [];
        foreach ($sc['allVis'] as $vs) foreach ($vs as $v) { $b = &$byIp[$v['ip']]; $b = $b ?? ['last' => 0, 'ua' => []]; $b['last'] = max($b['last'], (int) $v['last']); $b['ua'][$v['ua']] = true; unset($b); }
        uasort($byIp, function ($a, $b) { return $b['last'] <=> $a['last']; });
        $opts = []; foreach ($byIp as $addr => $b) $opts[(string) $addr] = [$addr . ' · ' . implode(', ', array_keys($b['ua'])), (string) $addr, 0];
        $out .= link_pop_html('ips', true, 'All IPs', 'IPs', $opts, $flt['ips']);
    }
    if ($tab === 'media') {
        $dirs = []; foreach (array_keys($m) as $rel) $dirs[link_item_folder($rel)] = true; foreach (array_keys($sc['fopen']) as $d) $dirs[(string) $d] = true;
        $opts = [];
        if (isset($dirs[''])) $opts['/'] = ['Front page', 'Front page', 0];   // (the front page first, then the folders as a tree in the gallery's own order)
        $nested = false;
        foreach (folder_tree_rows($dir, array_values(array_filter(array_map('strval', array_keys($dirs)), function ($d) { return $d !== ''; }))) as $r) { $opts[$r['p']] = [$r['n'], link_folder_label($dir, $r['p']), $r['d']]; if ($r['d'] > 0) $nested = true; }
        $out .= link_pop_html('folders', true, 'All folders', 'folders', $opts, array_map(function ($f) { return $f === '' ? '/' : $f; }, $flt['folders']), '', $nested ? 'A folder includes the folders inside it.' : '', $flt['exact']);
        $what = [];
        foreach (['viewed' => 'Most viewed', 'recent' => 'Most recent', 'photos' => 'Photos', 'videos' => 'Videos', 'private' => 'Private', 'opened' => 'Opened', 'downloaded' => 'Downloaded'] as $k => $t) $what[$k] = [$t, $t, 0];
        $out .= link_pop_html('what', false, 'Most viewed', '', $what, [$flt['what']], 'viewed');
    }
    if ($mode === 'ip' && count($linkOpts) > 1) {
        $opts = ['' => ['All links (' . count($linkOpts) . ')', 'All links (' . count($linkOpts) . ')', 0]];
        foreach ($linkOpts as $lid => $label) $opts[$lid] = [$label, $label, 0];
        $out .= link_pop_html('link', false, 'All links', '', $opts, [$flt['link']]);
    }
    return $out . '</div>';
}

/** A small linked picture of a photo or video: it opens the folder at that photo, as the thumbnails everywhere else in the log do. */
function link_pic_html($rel, $cls = 'sl-tth') {
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    return '<a class="' . $cls . '" href="' . admin_find_href($rel) . '" title="' . $esc($rel) . '"><img src="' . $esc(img_url($rel, 'thumb')) . '" alt="" loading="lazy" decoding="async"></a>';
}

/** The Visitors / Folders / Media / Timeline of one link ($mode "link", $l its record) or the Folders / Media / Timeline of one address across every link it used ($mode "ip"). One set of tabs, the same tables and menus; the scope is what differs. */
function render_link_scope($mode, $l, $ip, $tab, $flt, $dir, $files) {
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $data = links_read();
    $linkOpts = [];   // an address's links: id => name
    if ($mode === 'ip') {
        foreach ($data['links'] as $x) {
            if (!is_array($x) || !isset($x['id'])) continue;
            [$lg] = link_activity($x['id']);
            foreach ($lg['visitors'] as $v) if ($v['ip'] === $ip) { $linkOpts[$x['id']] = $x['label']; break; }
        }
        if (!$linkOpts) return '<div class="sl-ippage"><p class="muted">No link has seen this address.</p></div>';
        $ids = ($flt['link'] !== '' && isset($linkOpts[$flt['link']])) ? [$flt['link']] : array_keys($linkOpts);
        $sc = link_scope_load($data, $ids, [$ip]);
    } else {
        $sc = link_scope_load($data, [$l['id']], $flt['ips']);
    }
    $m = link_scope_media($sc); $folders = link_scope_folders($sc, $m);
    $tabs = $mode === 'ip' ? ['folders' => 'Folders', 'media' => 'Media', 'timeline' => 'Timeline'] : ['visitors' => 'Visitors', 'folders' => 'Folders', 'media' => 'Media', 'timeline' => 'Timeline'];
    if (!isset($tabs[$tab])) $tab = array_key_first($tabs);
    $out = '<div class="' . ($mode === 'ip' ? 'sl-ippage' : 'sl-activity') . '"' . ($mode === 'link' ? ' data-link="' . $esc($l['id']) . '"' : '') . '>';
    if ($mode === 'ip') {
        $country = ''; $uas = []; $first = 0; $last = 0; $linksUsed = '';
        foreach ($data['links'] as $x) {
            if (!is_array($x) || !isset($x['id']) || !isset($linkOpts[$x['id']])) continue;
            $opens = 0;
            foreach (link_activity($x['id'])[0]['visitors'] as $v) if ($v['ip'] === $ip) {
                $opens += (int) $v['opens']; $uas[$v['ua']] = true; if (($v['country'] ?? '') !== '') $country = $v['country'];
                $first = $first ? min($first, (int) $v['first']) : (int) $v['first']; $last = max($last, (int) $v['last']);
            }
            $linksUsed .= '<button type="button" class="sl-lchip sl-golink' . (link_is_public_log($x) ? ' pub' : '') . '" data-golink="' . $esc($x['id']) . '">' . $esc($x['label']) . ' &middot; ' . $opens . ' open' . ($opens === 1 ? '' : 's') . '</button>';
        }
        $seen = $opened = $down = 0; foreach ($m as $a) { if ($a['sf']) $seen++; if ($a['on'] || $a['pn']) $opened++; if ($a['dn']) $down++; }
        $out .= '<div class="sl-ip-head"><span class="sl-ipbig">' . $esc($ip) . '</span> <span class="muted">' . $esc(($country !== '' ? $country . ' · ' : '') . implode(', ', array_keys($uas))) . '</span></div>'
            . '<div class="sl-lchips">' . $linksUsed . '</div>'
            . '<div class="sl-kpi"><div><b>' . count($folders) . '</b><span>folders</span></div><div><b>' . $seen . '</b><span>seen</span></div><div><b>' . $opened . '</b><span>opened</span></div><div><b>' . $down . '</b><span>downloaded</span></div><div><b>' . link_time_html($first) . '</b><span>first seen</span></div><div><b>' . link_time_html($last) . '</b><span>last seen</span></div></div>'
            . '<p class="note" style="margin:6px 0"><b>Seen</b> it was on their screen, even as a thumbnail &middot; <b>Opened</b> shown large in the viewer (or played, for a video) &middot; <b>Downloaded</b> the original was downloaded</p>';
    }
    $out .= link_seg_html('tab', $tabs, $tab, 'Part of the log');
    if ($tab !== 'visitors') $out .= link_filter_bar($mode, $tab, $flt, $sc, $m, $dir, $ip, $linkOpts);

    if ($tab === 'visitors') {
        $log = link_log_read($l['id']); $vis = $log['visitors'];
        uasort($vis, function ($a, $b) { return (int) $b['last'] <=> (int) $a['last']; });
        if (!$vis) return $out . '<p class="muted sl-none">' . (link_is_public_log($l) ? 'Nobody has been logged yet.' : 'No one has opened this link yet.') . '</p></div>';
        $manyAddr = link_has_many_addresses($l, $log);
        $out .= '<div class="sl-scroll"><table class="sl-table"><thead><tr><th>IP address</th><th>Where</th><th>Browser</th>' . ($manyAddr ? '<th class="sl-viacol">Came in on</th>' : '') . '<th>First seen</th><th>Last seen</th><th>' . (link_is_public_log($l) ? 'Visits' : 'Opens') . '</th><th>Viewed</th></tr></thead><tbody>';
        foreach ($vis as $vid => $v) {
            $via = $manyAddr ? link_visitor_via($l, $log, $vid, $v) : '';
            $out .= '<tr><td><button type="button" class="linklike sl-ipt" data-ip="' . $esc($v['ip']) . '">' . $esc($v['ip']) . '</button>' . ($via !== '' ? '<span class="sl-via">via: ' . $esc($via) . '</span>' : '') . '</td><td>' . link_place_html($v['country'] ?? '') . '</td><td>' . $esc($v['ua']) . '</td>' . ($manyAddr ? '<td class="sl-viacol">' . ($via !== '' ? $esc($via) : '—') . '</td>' : '') . '<td>' . link_time_html($v['first']) . '</td><td>' . link_time_html($v['last']) . '</td><td>' . (int) $v['opens'] . '</td><td><button type="button" class="linklike sl-viewed" data-ip="' . $esc($v['ip']) . '" data-go="media">' . (int) $v['views'] . ' views &rsaquo;</button></td></tr>';
        }
        $out .= '</tbody></table></div>';
        if ((int) $log['folded'] > 0) $out .= '<p class="muted">+ ' . (int) $log['folded'] . ' earlier visitor' . ((int) $log['folded'] === 1 ? '' : 's') . ' (the log keeps the latest ' . number_format(LINK_LOG_VISITORS) . ' visitors; download the log to keep the rest, and trim it whenever you like).</p>';
        if ($log['blocked']) {
            uasort($log['blocked'], function ($a, $b) { return (int) $b['last'] <=> (int) $a['last']; });
            $out .= '<div class="sl-sub2 warn">Blocked tries &middot; ' . count($log['blocked']) . ' address' . (count($log['blocked']) === 1 ? '' : 'es') . '</div><div class="sl-scroll"><table class="sl-table"><thead><tr><th>IP address</th><th>Where</th><th>Browser</th><th>First tried</th><th>Last tried</th><th>Tries</th></tr></thead><tbody>';
            foreach ($log['blocked'] as $b) {
                $why = []; foreach ($b['why'] as $s => $n) $why[] = ['expired' => 'expired', 'used_up' => 'already used', 'paused' => 'paused', 'revoked' => 'revoked', 'scheduled' => 'not started', 'replaced' => 'old address'][$s] ?? $s;
                $out .= '<tr class="rj"><td><span class="sl-ipt">' . $esc($b['ip']) . '</span></td><td>' . link_place_html($b['country'] ?? '') . '</td><td>' . $esc($b['ua']) . '</td><td>' . link_time_html($b['first']) . '</td><td>' . link_time_html($b['last']) . '</td><td class="sl-why">' . (int) $b['tries'] . ' &middot; ' . $esc(implode(', ', array_unique($why))) . '</td></tr>';
            }
            $out .= '</tbody></table></div>';
        }
        return $out . '<details class="fold sl-howto"><summary>How to read this</summary><p class="note">An IP address is a device on a network, not a person: a phone moving from wifi to cellular looks like two. ' . (link_is_public_log($l) ? 'A visit is a visitor arriving, or coming back after half an hour; ' : 'Opens count the &ldquo;Open the gallery&rdquo; presses; ') . 'views count photos and folder pages looked at afterwards.</p></details></div>';
    }

    if ($tab === 'folders') {
        if (!$folders) return $out . '<p class="muted sl-none">Nothing has been looked at yet.</p></div>';
        $out .= '<div class="sl-scroll"><table class="sl-table"><thead><tr><th>Folder</th><th>Opens</th><th>Media seen</th><th>Opened</th><th>Downloaded</th><th>First seen</th><th>Last activity</th></tr></thead><tbody>';
        foreach ($folders as $rel => $x) {
            $rel = (string) $rel; $priv = $rel !== '' && folder_is_private($dir, $rel);
            $go = function ($n, $what, $text) use ($esc, $rel) { return $n > 0 ? '<button type="button" class="linklike sl-num" data-media="' . $what . '" data-folder="' . $esc($rel === '' ? '/' : $rel) . '">' . $esc($text) . '</button>' : '—'; };
            $out .= '<tr><td>' . ($priv ? '<span class="sl-lock" title="Private folder">' . ui_icon('lock') . '</span> ' : '') . $esc($rel === '' ? 'Front page' : link_folder_label($dir, $rel)) . '</td><td>' . ($x['opens'] ?: '—') . '</td><td>' . $go($x['seen'], 'viewed', $x['seen'] . ' of ' . link_folder_total($files, $rel)) . '</td><td>' . $go($x['opened'], 'opened', $x['opened']) . '</td><td>' . $go($x['down'], 'downloaded', $x['down']) . '</td><td>' . ($x['first'] ? link_time_html($x['first']) : '—') . '</td><td>' . ($x['last'] ? link_time_html($x['last']) : '—') . '</td></tr>';
        }
        return $out . '</tbody></table></div><details class="fold sl-howto"><summary>How to read this</summary><p class="note">&ldquo;Opens&rdquo; is how many times the folder&rsquo;s page was opened. &ldquo;Media seen&rdquo; is photos and videos that were on their screen, even as a thumbnail; &ldquo;Opened&rdquo; is the ones shown large in the viewer or played; &ldquo;Downloaded&rdquo; is the ones whose original was downloaded. A lock marks a private folder. Press a number to see those photos and videos.</p></details></div>';
    }

    if ($tab === 'media') {
        $kept = $m;
        if ($flt['folders']) { $fs = array_flip($flt['folders']); $ex = $flt['exact']; $m = array_filter($m, function ($a, $rel) use ($fs, $ex) { return link_folder_selected(link_item_folder($rel), $fs, $ex); }, ARRAY_FILTER_USE_BOTH); }
        $what = $flt['what'];
        if ($what === 'photos') $m = array_filter($m, function ($a, $rel) { return !is_video_file($rel); }, ARRAY_FILTER_USE_BOTH);
        elseif ($what === 'videos') $m = array_filter($m, function ($a, $rel) { return is_video_file($rel); }, ARRAY_FILTER_USE_BOTH);
        elseif ($what === 'opened') $m = array_filter($m, function ($a) { return $a['on'] + $a['pn'] > 0; });
        elseif ($what === 'downloaded') $m = array_filter($m, function ($a) { return $a['dn'] > 0; });
        elseif ($what === 'private') { $all = items_get_all_for_files($dir, array_keys($m)); $m = array_filter($m, function ($a, $rel) use ($dir, $all) { return file_is_hidden($dir, $rel, $all); }, ARRAY_FILTER_USE_BOTH); }
        if ($what === 'recent') uasort($m, function ($a, $b) { return $b['last'] <=> $a['last']; });
        else uasort($m, function ($a, $b) { return ($b['on'] + $b['pn']) <=> ($a['on'] + $a['pn']) ?: $b['last'] <=> $a['last']; });
        if (!$m) return $out . '<p class="muted sl-none">' . ($kept ? 'No photo or video matches that.' : 'Nothing has been looked at yet.') . '</p></div>';
        $shown = $flt['all'] ? $m : array_slice($m, 0, 60, true);
        $inAll = items_get_all_for_files($dir, array_keys($shown));
        $out .= '<div class="sl-grid">';
        foreach ($shown as $rel => $a) {
            $priv = file_is_hidden($dir, $rel, $inAll);
            $badge = ($a['pn'] > 0 ? 'played ×' . $a['pn'] : ($a['on'] > 0 ? '×' . $a['on'] : 'seen')) . ($a['dn'] > 0 ? ' · downloaded' : '');
            $out .= '<a class="sl-th" href="' . admin_find_href($rel) . '" title="' . $esc($rel) . '"><img src="' . $esc(img_url($rel, 'thumb')) . '" alt="" loading="lazy" decoding="async"><span class="sl-badge">' . $esc($badge) . '</span>' . ($priv ? '<span class="sl-lk">' . ui_icon('lock') . '</span>' : '') . '</a>';
        }
        $out .= '</div>';
        if (!$flt['all'] && count($m) > 60) $out .= '<button type="button" class="secondary sl-more" data-more>Show all ' . count($m) . '</button>';
        return $out . '<p class="note">Showing ' . count($shown) . ' of ' . count($m) . ' photos and videos that were on their screen. A lock marks a private one; the badge is how many times it was opened (&ldquo;seen&rdquo;: only on screen). Press one to open it in the gallery.</p></div>';
    }

    // timeline
    $events = $sc['events'];
    usort($events, function ($a, $b) { return (int) ($b['t1'] ?? $b['t']) <=> (int) ($a['t1'] ?? $a['t']); });
    if (!$events) return $out . '<p class="muted sl-none">Nothing to show yet.</p></div>';
    $out .= '<div class="sl-timeline">';
    foreach (array_slice($events, 0, 300) as $e) {
        $k = $e['k'] ?? ''; $pic = '';
        $when = isset($e['t1']) && (int) $e['t1'] !== (int) $e['t'] ? link_time_html($e['t']) . ' – ' . link_time_html($e['t1']) : link_time_html($e['t']);
        if ($k === 'scroll') {
            $text = 'Scrolled through <b>' . $esc(($e['d'] ?? '') === '' ? 'the front page' : link_folder_label($dir, $e['d'])) . '</b> &middot; saw ' . (int) $e['n'] . ' photo' . ((int) $e['n'] === 1 ? '' : 's');
            $thumbs = ''; foreach (($e['s'] ?? []) as $s) $thumbs .= link_pic_html($s);
            $more = (int) $e['n'] - count($e['s'] ?? []);
            if ($thumbs !== '') $text .= '<div class="sl-strip">' . $thumbs . ($more > 0 ? '<span class="sl-more-n">+' . $more . ' more</span>' : '') . '</div>';
        }
        elseif ($k === 'opened' || $k === 'played' || $k === 'saved') {
            $verb = ['opened' => 'Opened', 'played' => 'Played', 'saved' => 'Downloaded the original of'][$k];
            $text = $verb . ' <b>' . $esc(basename($e['f'])) . '</b>' . ($k === 'opened' ? '<div class="muted">' . $esc(link_folder_label($dir, link_item_folder($e['f']))) . '</div>' : '');
            $pic = link_pic_html($e['f']);
        }
        elseif ($k === 'folder') $text = 'Opened the folder <b>' . $esc(($e['f'] ?? '') === '' ? 'front page' : link_folder_label($dir, $e['f'])) . '</b>';
        elseif ($k === 'zip') $text = 'Downloaded a ZIP of <b>' . $esc(($e['f'] ?? '') === '' ? 'the gallery' : link_folder_label($dir, $e['f'])) . '</b> (' . (int) ($e['n'] ?? 0) . ' files)';
        elseif ($k === 'link') $text = !empty($e['_pub']) ? 'Visited the gallery' : 'Opened the link';
        else continue;
        $who = $mode === 'ip' ? 'via ' . $esc($e['_link']) : '<span class="sl-ipt">' . $esc($e['_ip']) . '</span>';
        $out .= '<div class="sl-ev"><span class="sl-evt">' . $when . '</span><div class="' . ($pic !== '' ? 'sl-evpic' : 'sl-evbody') . '">' . $pic . '<div>' . $text . '<div class="muted sl-evl">' . $who . '</div></div></div></div>';
    }
    return $out . '</div>' . ($sc['full'] ? '<p class="note">The timeline is full: it keeps the latest ' . number_format(LINK_LOG_EVENTS) . ' lines, so older ones were folded away. Download the log to keep them.</p>' : '') . '<details class="fold sl-howto"><summary>How to read this</summary><p class="note">Scrolling is one line per stretch. Opened, played and downloaded items get their own line. The Folders and Media tabs keep their own, exact tallies.</p></details></div>';
}

/** Every address that has opened or tried any link, with the links each used. */
function render_link_ips_list() {
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $data = links_read(); $ips = [];
    foreach ($data['links'] as $l) {
        if (!is_array($l) || !isset($l['id'])) continue;
        [$log, $items] = link_activity($l['id']);
        $viewsBy = [];
        foreach ($log['visitors'] as $vid => $v) { $a = &$ips[$v['ip']]; $a = $a ?? ['country' => '', 'ua' => [], 'links' => [], 'views' => 0, 'last' => 0, 'blocked' => 0]; $a['country'] = $v['country'] ?? $a['country']; $a['ua'][$v['ua']] = true; $a['links'][$l['id']] = [$l['label'], link_is_public_log($l)]; $a['views'] += (int) $v['views']; $a['last'] = max($a['last'], (int) $v['last']); unset($a); }
        foreach ($log['blocked'] as $b) { $a = &$ips[$b['ip']]; $a = $a ?? ['country' => '', 'ua' => [], 'links' => [], 'views' => 0, 'last' => 0, 'blocked' => 0]; $a['ua'][$b['ua']] = true; $a['links'][$l['id']] = [$l['label'], link_is_public_log($l)]; $a['blocked'] += (int) $b['tries']; $a['last'] = max($a['last'], (int) $b['last']); unset($a); }
    }
    if (!$ips) return '<p class="muted sl-none">No address has opened a link' . (LOG_PUBLIC_VISITS ? ' or been logged' : '') . ' yet.</p>';
    uasort($ips, function ($a, $b) { return $b['last'] <=> $a['last']; });
    $out = '<div class="sl-scroll"><table class="sl-table sl-cards"><thead><tr><th>IP address</th><th>Where</th><th>Browser</th><th>Links used</th><th>Views</th><th>Last seen</th></tr></thead><tbody>';
    foreach ($ips as $ip => $a) {
        $chips = ''; foreach ($a['links'] as $lid => [$name, $isPub]) $chips .= '<button type="button" class="sl-lchip sl-golink' . ($isPub ? ' pub' : '') . '" data-golink="' . $esc($lid) . '">' . $esc($name) . '</button>';
        $out .= '<tr><td data-label="IP address"><button type="button" class="linklike sl-ipt" data-ip="' . $esc($ip) . '">' . $esc($ip) . '</button></td><td data-label="Where">' . link_place_html($a['country']) . '</td><td data-label="Browser">' . $esc(implode(', ', array_keys($a['ua']))) . '</td><td data-label="Links used"><span class="sl-lchips">' . $chips . ($a['blocked'] > 0 ? '<span class="sl-why">blocked ×' . (int) $a['blocked'] . '</span>' : '') . '</span></td><td data-label="Views">' . (int) $a['views'] . '</td><td data-label="Last seen">' . link_time_html($a['last']) . '</td></tr>';
    }
    return $out . '</tbody></table></div><p class="note">Every address that has opened or tried any link' . (LOG_PUBLIC_VISITS ? ', or been logged on the public address' : '') . '. An address on two links is worth a look. Press one to see everything it opened.</p>';
}

/** The fragments and downloads the Shares tab asks for: ?admin&linkview=ID, &linkip=ADDRESS, &linkips=1, &linkexport=ID|all. */
function run_links_view_route($dir, $files) {
    header('Cache-Control: no-store');
    $str = function ($k) { return isset($_GET[$k]) && is_string($_GET[$k]) ? str_replace("\0", '', $_GET[$k]) : ''; };
    $data = links_read();
    if (isset($_GET['linkexport'])) { link_export($str('linkexport'), $str('what'), $str('ip'), $data, $dir); return; }
    header('Content-Type: text/html; charset=UTF-8');
    if (isset($_GET['linkips'])) { echo render_link_ips_list(); return; }
    if (isset($_GET['linkphotos'])) { echo render_link_photo_picker($str('linkphotos'), $files, $dir, $str('cover') === '1', link_draft_scope_from_request()); return; }
    if ($str('linkcover') === 'folders') {   // the folders that have public photos directly in them (among those the link covers), as a tree, for the preview photo dialog: the first one is where it opens
        header('Content-Type: application/json; charset=UTF-8');
        [, $public] = link_public_media($dir);
        $coverDraft = link_draft_scope_from_request();
        $public = array_values(array_filter($public, function ($f) use ($dir, $coverDraft) { return link_cover_in_scope(null, $dir, $f, $coverDraft); }));
        $count = [];
        foreach ($public as $f) if (!is_video_file($f)) { $d = link_item_folder($f); $count[$d] = ($count[$d] ?? 0) + 1; }
        $out = [];
        if (!empty($count[''])) $out[] = ['p' => '', 'l' => 'The front page', 'n' => 'The front page', 'd' => 0, 'c' => $count['']];
        foreach (folder_tree_rows($dir, array_values(array_filter(array_map('strval', array_keys($count)), function ($d) { return $d !== ''; }))) as $r) {
            if (!empty($count[$r['p']])) $out[] = ['p' => $r['p'], 'l' => link_folder_label($dir, $r['p']), 'n' => $r['n'], 'd' => $r['d'], 'c' => $count[$r['p']]];
        }
        echo json_encode(['folders' => $out]);
        return;
    }
    if (isset($_GET['linkcover'])) {   // the picture a link would use, for the form's preview and the settings line: ?admin&linkcover=1&id=ID&seed=SEED&file=CHOSEN
        header('Content-Type: application/json; charset=UTF-8');
        $rec = preg_match('/^[0-9a-f]{10}$/', $str('id')) ? ($data['links'][$str('id')] ?? null) : null;
        $asked = isset($_GET['file']) ? $str('file') : (is_array($rec) ? (string) ($rec['pictureFile'] ?? '') : '');
        $l = ['picture' => true, 'token' => is_array($rec) ? (string) ($rec['token'] ?? '') : '', 'pictureFile' => $asked,
              'pictureSeed' => preg_match('/^[0-9a-f]{8}$/', $str('seed')) ? $str('seed') : (is_array($rec) ? (string) ($rec['pictureSeed'] ?? '') : '')];
        $draft = link_draft_scope_from_request();   // (the form's unsaved choice of what the link covers)
        if ($draft === null && is_array($rec)) $l['scope'] = $rec['scope'] ?? null;
        $file = link_preview_picture($l, $dir, $draft);
        echo json_encode(['file' => $file, 'how' => $file === null ? 'none' : ($asked !== '' && $file === $asked ? 'chosen' : 'auto'), 'name' => $file === null ? '' : basename($file), 'img' => $file === null ? '' : img_url($file, 'thumb')]);
        return;
    }
    if (isset($_GET['linkip'])) {
        $tab = in_array($str('tab'), ['folders', 'media', 'timeline'], true) ? $str('tab') : 'folders';
        echo render_link_scope('ip', null, $str('linkip'), $tab, link_scope_filters(), $dir, $files);
        return;
    }
    $id = $str('linkview');
    $l = preg_match('/^[0-9a-f]{10}$/', $id) ? ($data['links'][$id] ?? null) : null;
    if (!is_array($l)) { http_response_code(404); echo '<p class="muted">That link no longer exists.</p>'; return; }
    $tab = $str('tab') === 'photos' ? 'media' : $str('tab');   // (the Media tab was called Photos: old addresses keep working)
    $tab = in_array($tab, ['visitors', 'folders', 'media', 'timeline'], true) ? $tab : 'visitors';
    echo render_link_scope('link', $l, '', $tab, link_scope_filters(), $dir, $files);
}

/** The photos of one folder (not the ones inside its subfolders), as check boxes with thumbnails: the "Add photos" picker of the link form. */
function render_link_photo_picker($folder, $files, $dir = '', $cover = false, $draft = null) {
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $folder = trim(str_replace('\\', '/', $folder), '/');
    if ($folder !== '' && !in_array($folder, all_folder_paths($files), true)) return '<p class="muted">That folder isn\'t in the gallery.</p>';
    $list = [];
    foreach ($files as $f) {
        $slash = strrpos($f, '/');
        if (($slash === false ? '' : substr($f, 0, $slash)) === $folder && (!$cover || (link_picture_ok($f, $dir) && link_cover_in_scope(null, $dir, $f, $draft)))) $list[] = $f;   // (a cover: public photos only, and only ones the link covers)
    }
    natcasesort($list);
    if (!$list) return '<p class="muted">' . ($cover ? 'There are no public photos the link covers directly in this folder.' : 'There are no photos directly in this folder.') . '</p>';
    $out = '';
    foreach (array_slice(array_values($list), 0, 400) as $f) {
        $out .= '<label class="sl-pickphoto"><input type="' . ($cover ? 'radio' : 'checkbox') . '"' . ($cover ? ' name="sl-cover"' : '') . ' value="' . $esc($f) . '"><img src="?img=' . rawurlencode($f) . '&amp;size=thumb" alt="" loading="lazy"><span>' . $esc(basename($f)) . '</span></label>';
    }
    return $out . (count($list) > 400 ? '<p class="muted">Showing the first 400 of ' . count($list) . '.</p>' : '');
}

/** CSV of a link's (or every link's) log: what=visitors | items | events; ip limits it to one address. */
function link_export($which, $what, $ip, $data, $dir) {
    $links = [];
    foreach ($data['links'] as $l) if (is_array($l) && isset($l['id']) && ($which === 'all' || $l['id'] === $which)) $links[] = $l;
    if (!$links) { http_response_code(404); header('Content-Type: text/plain; charset=UTF-8'); echo 'No such link.'; return; }
    if (!in_array($what, ['visitors', 'items', 'events'], true)) $what = 'items';
    $rows = [];
    $head = ['visitors' => ['link', 'ip', 'where', 'browser', 'first_seen', 'last_seen', 'opens', 'views', 'came_in_on'], 'items' => ['link', 'ip', 'browser', 'folder', 'file', 'first_seen', 'opened', 'played', 'downloaded', 'last_activity'], 'events' => ['time', 'link', 'ip', 'what', 'folder', 'file', 'count']][$what];
    $iso = function ($t) { return $t ? gmdate('Y-m-d H:i:s', (int) $t) : ''; };
    foreach ($links as $l) {
        [$log, $items] = link_activity($l['id']);
        if ($what === 'visitors') foreach ($log['visitors'] as $vid => $v) { if ($ip !== '' && $v['ip'] !== $ip) continue; $rows[] = [$l['label'], $v['ip'], $v['country'] ?? '', $v['ua'], $iso($v['first']), $iso($v['last']), $v['opens'], $v['views'], link_has_many_addresses($l, $log) ? link_visitor_via($l, $log, $vid, $v) : '']; }
        elseif ($what === 'items') foreach (link_item_rows($items) as [$vid, $rel, $t]) { $v = $log['visitors'][$vid] ?? null; if ($v === null || ($ip !== '' && $v['ip'] !== $ip)) continue; $rows[] = [$l['label'], $v['ip'], $v['ua'], link_item_folder($rel), basename($rel), $iso($t['sf'] ?? 0), (int) ($t['on'] ?? 0), (int) ($t['pn'] ?? 0), (int) ($t['dn'] ?? 0), $iso(max((int) ($t['sl'] ?? 0), (int) ($t['ol'] ?? 0), (int) ($t['pl'] ?? 0), (int) ($t['dl'] ?? 0)))]; }
        else foreach ($log['events'] as $e) { $v = $log['visitors'][$e['v'] ?? ''] ?? null; if ($v === null || ($ip !== '' && $v['ip'] !== $ip)) continue; $k = $e['k'] ?? ''; $f = $e['f'] ?? ($e['d'] ?? ''); $rows[] = [$iso($e['t']), $l['label'], $v['ip'], $k === 'scroll' ? 'seen' : ($k === 'saved' ? 'downloaded' : $k), ($k === 'scroll' || $k === 'folder' || $k === 'zip') ? $f : link_item_folder((string) $f), ($k === 'scroll' || $k === 'folder' || $k === 'zip') ? '' : basename((string) $f), $e['n'] ?? 1]; }
    }
    header('Content-Type: text/csv; charset=UTF-8');
    shell_frame_headers();   // (a download may sit in this site's own admin frame, as it does on a desktop; no other site may frame it)
    header('Content-Disposition: attachment; filename="share-links-' . $what . '.csv"');
    header('X-Content-Type-Options: nosniff');
    echo "\xEF\xBB\xBF" . implode(',', array_map('link_csv_cell', $head)) . "\r\n";
    foreach ($rows as $r) echo implode(',', array_map('link_csv_cell', $r)) . "\r\n";
}

/** Remembers which version of this script last ran against this install (a small guarded data file beside index.php), so
 *  the health panel can say "upgraded from X on Y". Written only when the version changes; null if it can't be kept. */
function health_version_record($dir) {
    $path = rtrim($dir, '/') . '/.gallery-health.php';
    $data = json_file_read($path, ['version' => '', 'from' => '', 'at' => '', 'first' => '']);
    if (($data['version'] ?? '') === GALLERY_VERSION) return $data;
    $new = ['version' => GALLERY_VERSION, 'from' => (string) ($data['version'] ?? ''), 'at' => gmdate('Y-m-d'),
            'first' => ($data['first'] ?? '') !== '' ? (string) $data['first'] : gmdate('Y-m-d')];
    return @file_put_contents($path, data_file_encode($new), LOCK_EX) !== false ? $new : null;
}

/** The server-side half of the health panel: rows of [group, status (ok|warn|bad|info), label, detail], and nothing is
 *  changed by looking. Folders are sampled (the first 300 that hold media) so a huge gallery stays quick. */
function health_checks($dir, $files) {
    $rows = [];
    $add = function ($group, $status, $label, $detail = '', $link = null, $html = '') use (&$rows) { $rows[] = [$group, $status, $label, $detail, $link, $html]; };

    // --- this install
    $rec = health_version_record($dir);
    if ($rec === null) $add('This install', 'warn', 'Running ' . GALLERY_VERSION, 'The folder is not writable by the web server, so the version history can\'t be kept.');
    elseif (($rec['from'] ?? '') !== '') $add('This install', 'ok', 'Running ' . GALLERY_VERSION, (version_compare((string) $rec['from'], GALLERY_VERSION, '>') ? 'Went back from ' : 'Upgraded from ') . $rec['from'] . ' on ' . $rec['at'] . ' (first recorded ' . $rec['first'] . ').');
    else $add('This install', 'ok', 'Running ' . GALLERY_VERSION, 'First recorded ' . ($rec['first'] ?? $rec['at'] ?? '') . '; upgrades are noted here.');
    $add('This install', 'ok', 'New versions', '', null, update_status_html());
    $add('This install', PHP_VERSION_ID >= 80300 ? 'ok' : 'bad', 'PHP ' . PHP_VERSION, PHP_VERSION_ID >= 80300 ? '' : 'This gallery needs PHP 8.3 or newer.');
    $hasGd = extension_loaded('gd'); $hasImagick = class_exists('Imagick');
    $add('This install', ($hasGd || $hasImagick) ? 'ok' : 'bad', 'Picture resizing: ' . ($hasImagick && $hasGd ? 'GD and Imagick' : ($hasImagick ? 'Imagick' : ($hasGd ? 'GD' : 'none')) ),
        ($hasGd || $hasImagick) ? '' : 'Neither GD nor Imagick is installed, so thumbnails can\'t be made.');
    $heic = 0; foreach ($files as $f) if (preg_match('/\.hei[cf]$/i', $f)) $heic++;
    if ($heic > 0 && !$hasImagick) $add('This install', 'warn', 'HEIC photos can\'t be shown', $heic . ' HEIC photo' . ($heic === 1 ? '' : 's') . ' found, and HEIC needs the Imagick extension (it isn\'t installed).');
    $add('This install', function_exists('exif_read_data') ? 'ok' : 'warn', 'Photo details (EXIF): ' . (function_exists('exif_read_data') ? 'available' : 'missing'),
        function_exists('exif_read_data') ? '' : 'Without the exif extension, a photo\'s location and date can\'t be read from the file.');

    // --- settings and passwords
    $state = gallery_config_load();
    $cfgPath = gallery_config_path();
    if ($state['status'] === 'absent') $add('Settings and passwords', 'info', 'No settings file yet', 'Everything runs on the defaults; .gallery-config.php appears when you change something here.');
    elseif ($state['status'] === 'ok') $add('Settings and passwords', is_writable($cfgPath) ? 'ok' : 'warn', 'Settings file readable' . (is_writable($cfgPath) ? ' and writable' : ' but not writable'), is_writable($cfgPath) ? '' : 'Changes in Settings can\'t be saved until the web server may write .gallery-config.php.');
    else $add('Settings and passwords', 'bad', 'Settings file can\'t be read as settings', 'Fix or delete .gallery-config.php; until then the settings in index.php apply.');
    foreach (($GLOBALS['__gallery_config_problems'] ?? []) as $problem) $add('Settings and passwords', 'warn', 'Settings problem', $problem);
    $pwRow = function ($label, $value, $emptyStatus, $emptyDetail) use ($add) {
        if ($value === '') $add('Settings and passwords', $emptyStatus, $label . ': not set', $emptyDetail);
        elseif (password_is_hashed($value)) $add('Settings and passwords', 'ok', $label . ': stored as a hash', '');
        else $add('Settings and passwords', 'opt', $label . ': stored as plain text', 'It is changed to a hash by the next successful sign-in with it; nothing to do.');
    };
    $pwRow('Admin password', ADMIN_PASSWORD, 'warn', 'Without one the admin is not available (open ?admin to set one: it asks for the code in gallery-setup-code.php).');
    $pwRow('Private-view password', PRIVATE_VIEW_PASSWORD, 'info', 'Fine if you have no private items to share.');
    $add('Settings and passwords', is_file(rtrim($dir, '/') . '/.gallery-secret.php') ? 'ok' : 'info', is_file(rtrim($dir, '/') . '/.gallery-secret.php') ? 'Sign-in key present' : 'Sign-in key not made yet', is_file(rtrim($dir, '/') . '/.gallery-secret.php') ? '' : 'It is made the first time someone signs in.');

    // --- where the gallery's folders live
    $named = is_array(EXTRA_FOLDERS) ? EXTRA_FOLDERS : [];
    $roots = is_array(EXTRA_ROOTS) ? EXTRA_ROOTS : [];
    $extraBad = [];
    foreach ($named as $name => $path) if (!is_string($path) || !is_dir($path) || !is_readable($path)) $extraBad[] = (is_string($name) ? $name . ' = ' : '') . (is_string($path) ? $path : '?');
    foreach ($roots as $path) if (!is_string($path) || !is_dir($path) || !is_readable($path)) $extraBad[] = is_string($path) ? $path : '?';
    if ($named || $roots) {
        $total = count($named) + count($roots);
        $add('Folders and files', $extraBad ? 'bad' : 'ok', $extraBad ? count($extraBad) . ' of ' . $total . ' folders from elsewhere are missing or unreadable' : 'All ' . $total . ' folders from elsewhere on the server exist and can be read',
            $extraBad ? 'Missing: ' . implode(', ', array_slice($extraBad, 0, 5)) . '. Their photos are not shown until the path exists again.' : '');
    }
    $folders = [rtrim($dir, '/') => true];
    foreach ($files as $f) { $folders[containing_folder_real_path($dir, $f)] = true; if (count($folders) >= 300) break; }
    $unwritable = []; $badData = []; $withData = 0; $entries = 0;
    foreach (array_keys($folders) as $folder) {
        if (!is_writable($folder)) $unwritable[] = $folder;
        $itemsFile = items_path($folder);
        if (!is_file($itemsFile)) continue;
        $withData++;
        [$state, $decoded] = json_file_load($itemsFile, []);
        if ($state === 'broken') { $badData[] = $folder; continue; }
        $entries += count($decoded['items'] ?? []);
    }
    $sampled = count($folders) >= 300 ? ' (the first 300 folders with media)' : '';
    $rel = function ($p) use ($dir) { $d = rtrim($dir, '/'); return $p === $d ? '(top folder)' : (strncmp($p, $d . '/', strlen($d) + 1) === 0 ? substr($p, strlen($d) + 1) : $p); };
    $add('Folders and files', $unwritable ? 'warn' : 'ok', $unwritable ? count($unwritable) . ' of ' . count($folders) . ' folders are not writable by the web server' . $sampled : 'All ' . count($folders) . ' folders are writable' . $sampled,
        $unwritable ? 'Captions, locations, privacy flags and cached thumbnails can\'t be saved in: ' . implode(', ', array_map($rel, array_slice($unwritable, 0, 5))) . (count($unwritable) > 5 ? ' and ' . (count($unwritable) - 5) . ' more' : '') . '. Photos still show.' : '');
    $add('Folders and files', $badData ? 'bad' : 'ok', $badData ? count($badData) . ' folder data file' . (count($badData) === 1 ? '' : 's') . ' can\'t be read' : 'Folder data files all read fine (' . $withData . ' folder' . ($withData === 1 ? '' : 's') . ', ' . $entries . ' photo' . ($entries === 1 ? '' : 's') . ' with saved details)',
        $badData ? 'Everything in them is hidden from visitors until the file is restored from a backup or deleted, and nothing is saved over them meanwhile: ' . implode(', ', array_map($rel, array_slice($badData, 0, 5))) : 'Wiping a folder wipes its captions, locations and private flags.');
    $videos = 0; foreach ($files as $f) if (is_video_file($f)) $videos++;
    $add('Folders and files', 'info', number_format(count($files) - $videos) . ' photo' . (count($files) - $videos === 1 ? '' : 's') . ($videos ? ' and ' . number_format($videos) . ' video' . ($videos === 1 ? '' : 's') : ''), 'in ' . number_format(count($folders)) . ' folder' . (count($folders) === 1 ? '' : 's') . $sampled . '.');

    if (!empty($GLOBALS['__scan_truncated'])) $add('Folders and files', 'warn', 'The folder scan stopped early', 'This gallery looks at no more than ' . number_format(SCAN_MAX_FOLDERS) . ' folders and ' . number_format(SCAN_MAX_FILES) . ' photos on one visit, and this folder has more, so the rest are left out. Move the gallery into a smaller folder, or the extra folders out of it.');

    // --- signed-in browsers: what looks wrong, and how big the histories have grown
    foreach (browser_rows() as $br) {
        foreach ($br['flags'] as $flagName => [$flagDetail, $flagAt, $flagLoud]) {
            $add('Signed-in browsers', $flagLoud, signin_flag_info($flagName)[0] . ': ' . ($br['kind'] === 'owner' ? 'you' : 'private view') . ' on ' . ($br['lab'] !== '' ? $br['lab'] : 'a device'),
                $flagDetail . ' (' . date('M j, g:i a', $flagAt) . '). Looks fine clears it, Sign out ends that browser.', ['site', 'browsers-row', 'Open the list']);
        }
    }
    $sgFiles = (array) @glob(__DIR__ . '/.gallery-signin-*.php'); $sgTotal = 0; $sgBig = 0; $sgBigId = '';
    foreach ($sgFiles as $sgF) { $sgSz = (int) @filesize($sgF); $sgTotal += $sgSz; if ($sgSz > $sgBig && preg_match('/-([0-9a-f]{12})\.php$/', $sgF, $sgM)) { $sgBig = $sgSz; $sgBigId = $sgM[1]; } }
    if ($sgFiles) {
        $sgSizes = (array) (signin_log_read()['sizes'] ?? []); krsort($sgSizes);
        $sgAgo = function ($days) use ($sgSizes) { $want = date('Y-m-d', time() - $days * 86400); foreach ($sgSizes as $day => $v) if ($day <= $want) return signin_size_text((int) ($v[0] ?? 0)); return 'not noted yet'; };
        $sgWho = ''; foreach (browser_rows() as $sgR) if ($sgR['id'] === $sgBigId) $sgWho = ' (' . ($sgR['kind'] === 'owner' ? 'you' : 'private view') . ' on ' . $sgR['lab'] . ')';
        $add('Signed-in browsers', 'info', 'Address history: ' . signin_size_text($sgTotal) . ' in ' . count($sgFiles) . ' file' . (count($sgFiles) === 1 ? '' : 's'),
            'The largest is ' . signin_size_text($sgBig) . $sgWho . '. A week ago the total was ' . $sgAgo(7) . ', a month ago ' . $sgAgo(30) . '. It is kept ' . BROWSER_HISTORY_DAYS . ' days and nothing else caps it; a history that grows fast is worth a look.', ['site', 'browsers-row', 'Open the list']);
    }

    // --- the country file
    if (COUNTRY_LOOKUP) {
        $gs = geo_state(); $gHave = is_file(geo_db_path());
        $add('Signed-in browsers', 'info', $gHave ? 'Country file: the ' . ($gs['ym'] !== '' ? date('F Y', (int) strtotime($gs['ym'] . '-01')) : 'current') . ' edition, ' . signin_size_text((int) @filesize(geo_db_path())) : 'Country file: not fetched yet',
            ($gHave ? 'IP geolocation by DB-IP (CC BY 4.0); fetched ' . date('M j', (int) ($gs['at'] ?: @filemtime(geo_db_path()))) . ', and it looks for a newer one every 7 days.' : 'It is fetched at the next visit, or press Refresh now in Settings.') . ($gs['error'] !== '' ? ' The last try (' . date('M j, g:i a', (int) $gs['triedAt']) . ') did not work: ' . $gs['error'] : ''), ['site', 'geo-row', 'Open the setting']);
    }

    // --- videos
    if (ENABLE_VIDEO) {
        $ff = ffmpeg_binary();
        $add('Videos', $ff !== '' ? 'ok' : 'opt', 'ffmpeg ' . ($ff !== '' ? 'found' : 'not found'), $ff !== '' ? $ff . ' — real thumbnails, hover clips and scrubbing are available.' : 'Videos show the browser\'s own first frame; no hover clips or scrubbing.');
    }
    // --- media: what Prepare media looks after. Loud (amber) for what needs doing something about; quiet rows (shown under "Show all
    // checks") for what the Prepare button does by itself, or is only for information. Each lists its files, linked to their editors.
    if (ENABLE_THUMBNAIL_CACHE && $files) {
        $mr = media_readiness($dir, $files); $ma = media_attention($dir, $files);
        $go = ['site', 'media-panel', 'Open Prepare media'];
        $n = function ($x, $one, $many = null) { return number_format($x) . ' ' . ($x === 1 ? $one : ($many ?? $one . 's')); };
        $rowFor = [
            'unreadable' => ['warn', function ($c) use ($n) { return $n($c, 'file') . ' couldn\'t be read'; }, 'No capture date or location could be read from these (a damaged file, or a kind the gallery can\'t read). They still show.'],
            'legacy' => ['opt', function ($c) use ($n) { return $n($c, 'old "nothing found" mark'); }, 'An earlier version looked at these and found no location; they are left alone. Tidy up and Read everything again deal with them.'],
            'videoFailed' => ['warn', function ($c) use ($n) { return $n($c, 'video') . ' ffmpeg couldn\'t make pictures for'; }, 'Not tried again for six hours.'],
            'unread' => ['info', function ($c) use ($n) { return $n($c, 'file') . ' not read yet'; }, 'Prepare media reads them (it happens by itself the first time a photo is shown).'],
            'videoMissing' => ['info', function ($c) use ($n) { return $n($c, 'video') . ' without pictures yet'; }, 'Prepare media makes them.'],
            'undated' => ['info', function ($c) use ($n) { return $n($c, 'file') . ' with no capture date'; }, 'They are ordered by when the file was changed. Nothing to fix: screenshots, downloads and some videos carry no date.'],
            'differs' => ['info', function ($c) use ($n) { return $n($c, 'saved location') . ' that differ from their file'; }, 'Yours, kept as they are.'],
        ];
        foreach ($ma['groups'] as $grp) {
            [$status, $labelFn, $detail] = $rowFor[$grp['key']];
            $add('Media', $status, $labelFn($grp['count']), $detail, $status === 'warn' ? $go : null, render_media_group_html($grp));
        }
        if ($mr['left'] > 0) $add('Media', 'info', $n($mr['left'], 'file') . ' not prepared yet', 'Thumbnails, what the files say about themselves and video pictures are made the first time a photo is shown; Prepare media does the rest in one go.', $go);
        elseif (!$ma['attention']) $add('Media', 'ok', 'All prepared', number_format($mr['total']) . ' file' . ($mr['total'] === 1 ? '' : 's') . ': thumbnails made, video pictures made and read from the files.', $go);
        if ($mr['videos'] > 0 && !$mr['videoWanted']) $add('Videos', 'info', 'Video pictures are switched off', 'Video thumbnails and video previews are off in Settings, so videos show the browser\'s own first frame.');
    }
    return $rows;
}

