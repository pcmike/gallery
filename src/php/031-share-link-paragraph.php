/** A link's settings in plain words, one clause for each thing (what they see; how often; how long; downloads and ZIP; locations; the opening
 *  page; when it was made). A clause that is the default (public gallery, unlimited, never ends, starts now, downloads and ZIP off, locations
 *  shown only, the gallery's title, an automatic preview photo, no note) is plain grey, one that is not is darker and bolder. */
function link_paragraph_html($l, $state, $report, $map, $index, $dir, $rootFid) {
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $cl = function ($html, $changed) { return '<span class="' . ($changed ? 'sl-cl-ch' : 'sl-cl') . '">' . $html . '</span>'; };
    if ($state === 'revoked') return $cl('Revoked ' . link_time_html($l['revokedAt'] ?? 0) . '. It stays in the list and its log is kept; nobody can open it.', false) . ' ' . $cl('Made ' . link_time_html($l['created']) . '.', false);
    $item = function ($x) use ($esc) {
        return $x['state'] === 'ok' ? $esc($x['label']) : '<span class="sl-bad">' . $esc($x['label']) . ' <em>(' . ($x['state'] === 'ambiguous' ? 'found in more than one folder, so it is not shown' : 'can\'t be found: renamed away, moved or deleted?') . ')</em></span>';
    };
    $type = $l['scope']['type'] ?? 'all';
    $only = link_shows_only_its_own($l);
    $rest = $only ? (gallery_is_closed() ? ' and nothing else.' : ' and nothing else on their pages (a tidy view: the rest of the gallery stays public to everyone).') : ' and the public gallery.';
    if ($type === 'public') $c1 = $cl('They see the public gallery and nothing private.', false);
    elseif ($type === 'all') $c1 = $cl('They see the whole gallery, public and private, including what you add later.', true);
    elseif ($type === 'folders') $c1 = $cl('They see ' . link_folder_list_html($report, $item) . $rest, true);
    elseif ($type === 'mixed') { $n = count($report['photos']); $c1 = $cl('They see ' . ($report['folders'] ? link_folder_list_html($report, $item) : '') . ($report['folders'] && $n ? ' and ' : '') . ($n ? $n . ' photo' . ($n === 1 ? '' : 's') . ' you picked: ' . implode(', ', array_map($item, array_slice($report['photos'], 0, 3))) . ($n > 3 ? ' and ' . ($n - 3) . ' more' : '') : '') . $rest, true); }
    else { $n = count($report['photos']); $c1 = $cl('They see ' . ($only ? 'only ' : '') . $n . ' photo' . ($n === 1 ? '' : 's') . ' you picked: ' . implode(', ', array_map($item, array_slice($report['photos'], 0, 3))) . ($n > 3 ? ' and ' . ($n - 3) . ' more' : '') . $rest, true); }
    if (($l['openIn'] ?? '') !== '' && $type !== 'photos') $c1 .= ' ' . $cl('It opens in ' . ($report['paths']['openIn'] !== '' ? $esc(link_folder_label($dir, $report['paths']['openIn'])) : '<span class="sl-bad">a folder that can\'t be found (it opens the front page)</span>') . '.', true);
    $max = (int) ($l['maxOpens'] ?? 0); $ends = (int) ($l['endsAt'] ?? 0); $starts = (int) ($l['startsAt'] ?? 0);
    $c2 = $max > 0 ? $cl('It can be opened in ' . ($max === 1 ? 'just one browser' : 'up to ' . $max . ' browsers') . ',', $max !== (int) DEFAULT_LINK_USES) : $cl('It can be opened in as many browsers as they like,', (int) DEFAULT_LINK_USES !== 0);
    $c3 = $ends > 0 ? $cl('and ' . ($state === 'expired' ? 'stopped' : 'stops') . ' working on ' . link_time_html($ends) . '.', true) : $cl('and never stops working.', false);
    if ($starts > 0) $c3 .= ' ' . $cl('It ' . ($state === 'scheduled' ? 'starts' : 'started') . ' on ' . link_time_html($starts) . '.', true);
    $dl = $l['downloads']['mode'] ?? 'off'; $zp = $l['zip']['mode'] ?? 'off';
    $low = function ($t) { return preg_match('/^(Off|Everything)/', $t) ? lcfirst($t) : lcfirst($t); };
    $c4 = $dl === 'off' && $zp === 'off' ? $cl('Downloads and ZIP are off.', false)
        : $cl('Downloads: ' . $low(link_capability_text_html($l, 'downloads', $report, $map, $index, $dir, $rootFid)) . '; ZIP: ' . $low(link_capability_text_html($l, 'zip', $report, $map, $index, $dir, $rootFid)) . '.', true);
    $c5 = ($l['locations'] ?? 'public') === 'all' ? $cl('All saved locations are shown.', true) : $cl('Photo locations are shown only where you set them to show.', false);
    $titleMode = $l['titleMode'] ?? 'gallery'; $pic = !empty($l['picture']); $picFile = (string) ($l['pictureFile'] ?? ''); $note = trim((string) ($l['note'] ?? '')); $heads = (string) ($l['headsUp'] ?? 'default');
    $titleSay = ['gallery' => 'the gallery\'s title', 'neutral' => 'a neutral title', 'custom' => $esc('a title of your own')][$titleMode] ?? 'the gallery\'s title';
    if ($titleMode === 'custom' && trim((string) ($l['titleText'] ?? '')) !== '') $titleSay = 'the title “' . $esc($l['titleText']) . '”';
    $picSay = !$pic ? 'no preview photo' : ($picFile !== '' ? 'a preview photo you chose' : 'an automatic preview photo');
    $c6 = $cl('The opening page uses ' . $titleSay . ' and ' . $picSay . ', ' . ($note !== '' ? 'with the note “' . $esc($note) . '”.' : 'with no note.') . ($heads === 'default' ? '' : ' The heads-up line is ' . $esc($heads) . '.'), $titleMode !== 'gallery' || !$pic || $picFile !== '' || $note !== '' || $heads !== 'default');
    return $c1 . ' ' . $c2 . ' ' . $c3 . ' ' . $c4 . ' ' . $c5 . ' ' . $c6 . ' ' . $cl('Made ' . link_time_html($l['created']) . '.', false);
}

/** The summary line under a link's name: opens, addresses, views, tries turned away, and when it ends. */
function link_summary_html($l, $state, $log = null) {
    $max = (int) ($l['maxOpens'] ?? 0); $opens = (int) ($l['opens'] ?? 0);
    $parts = [$max > 0 ? '<b>' . $opens . ' of ' . $max . '</b> browsers' : ($opens === 0 ? 'Not opened yet' : '<b>' . $opens . '</b> ' . ($opens === 1 ? 'browser' : 'browsers'))];
    if ($log !== null) {
        $ips = count(array_unique(array_column($log['visitors'], 'ip'))); $views = array_sum(array_column($log['visitors'], 'views'));
        if ($ips > 0) $parts[] = '<b>' . $ips . '</b> IP' . ($ips === 1 ? '' : 's');
        if ($views > 0) $parts[] = $views . ' view' . ($views === 1 ? '' : 's');
        $tries = array_sum(array_column($log['blocked'], 'tries'));
        if ($tries > 0) $parts[] = '<b class="sl-amber">' . $tries . ' blocked ' . ($tries === 1 ? 'try' : 'tries') . '</b>';
    }
    $ends = (int) ($l['endsAt'] ?? 0);
    if ($state === 'revoked') $parts[] = 'revoked ' . link_time_html($l['revokedAt'] ?? 0);
    elseif ($state === 'paused') $parts[] = 'paused ' . link_time_html($l['pausedAt'] ?? 0);
    elseif ($state === 'scheduled') $parts[] = 'starts ' . link_time_html($l['startsAt']);
    elseif ($state === 'expired') $parts[] = 'ended ' . link_time_html($ends);
    elseif ($state === 'used_up') $parts[] = $ends > 0 ? 'ends ' . link_time_html($ends) : 'used up';
    elseif ($ends > 0) $parts[] = 'ends ' . link_time_html($ends);
    return implode(' <span class="sl-dot">&middot;</span> ', $parts);
}

/** How many visitors have been active, and tries turned away, since the owner last looked at the tab. */
function link_news_count($log, $seenAt) {
    if ($seenAt <= 0) $seenAt = 0;
    $n = 0;
    foreach ($log['visitors'] as $v) if ((int) $v['last'] > $seenAt) $n++;
    foreach ($log['blocked'] as $b) if ((int) $b['last'] > $seenAt) $n++;
    return $n;
}

/** Where a folder id is now: [state, path]: ok (found once), ambiguous (found in more than one folder), missing (nowhere;
 *  the path is where it was last seen). */
function link_fid_state($fid, $map, $index, $rootFid) {
    if ($rootFid !== '' && $fid === $rootFid) return ['ok', ''];
    if (isset($map[$fid])) return [count($map[$fid]) === 1 ? 'ok' : 'ambiguous', $map[$fid][0]];
    return ['missing', (string) ($index[$fid] ?? '')];
}

/** What a link covers, for the tab: lists of [label, state] for its folders and photos, how many are not found, and the paths
 *  the edit form starts from. */
function link_scope_report($l, $map, $index, $dir, $rootFid) {
    $out = ['folders' => [], 'photos' => [], 'except' => [], 'problems' => 0, 'paths' => ['scope' => [], 'publicOnly' => [], 'except' => [], 'downloads' => [], 'zip' => [], 'photos' => [], 'openIn' => '']];
    $one = function ($fid) use ($map, $index, $dir, $rootFid, &$out) {
        [$state, $rel] = link_fid_state($fid, $map, $index, $rootFid);
        if ($state !== 'ok') $out['problems']++;
        return [$state, $rel, $rel === '' ? 'the gallery\'s own folder' : link_folder_label($dir, $rel)];
    };
    foreach ((array) ($l['scope']['except'] ?? []) as $fid) {   // (folders the link leaves out of a folder it covers; one that is gone is nothing to leave out, so it is no problem)
        [$s, $rel] = link_fid_state($fid, $map, $index, $rootFid);
        if ($s !== 'ok' || $rel === '') continue;
        $out['except'][] = ['fid' => $fid, 'rel' => $rel, 'label' => link_folder_label($dir, $rel)]; $out['paths']['except'][] = $rel;
    }
    foreach ((array) ($l['scope']['folders'] ?? []) as $fid) { [$s, $rel, $label] = $one($fid); $pub = in_array($fid, (array) ($l['scope']['publicOnly'] ?? []), true); $out['folders'][] = ['fid' => $fid, 'state' => $s, 'rel' => $s === 'ok' ? $rel : null, 'pub' => $pub, 'counts' => $s === 'ok' ? link_folder_item_counts($dir, $rel, $out['paths']['except']) : null, 'label' => $s === 'missing' && $rel === '' ? 'a folder that is gone' : $label]; if ($s === 'ok') { $out['paths']['scope'][] = $rel; if ($pub) $out['paths']['publicOnly'][] = $rel; } }
    foreach ((array) ($l['scope']['photos'] ?? []) as $p) {
        if (!is_array($p)) continue;
        [$s, $rel] = link_fid_state((string) ($p['fid'] ?? ''), $map, $index, $rootFid);
        if ($s !== 'ok') $out['problems']++;
        $out['photos'][] = ['fid' => $p['fid'] ?? '', 'state' => $s, 'label' => ($rel === '' ? '' : link_folder_label($dir, $rel) . ' / ') . (string) ($p['name'] ?? '')];
        if ($s === 'ok') $out['paths']['photos'][] = ($rel === '' ? '' : $rel . '/') . $p['name'];
    }
    foreach (['downloads', 'zip'] as $c) foreach ((array) ($l[$c]['folders'] ?? []) as $fid) { [$s, $rel] = link_fid_state($fid, $map, $index, $rootFid); if ($s === 'ok') $out['paths'][$c][] = $rel; }
    if (($l['openIn'] ?? '') !== '') { [$s, $rel] = link_fid_state($l['openIn'], $map, $index, $rootFid); if ($s === 'ok') $out['paths']['openIn'] = $rel; }
    return $out;
}

/** How many photos and videos are inside a folder (at any depth), and how many of those are public: [all, public]. */
function link_folder_item_counts($dir, $rel, $except = []) {
    [$all, $public] = link_public_media($dir);
    $pre = $rel === '' ? '' : $rel . '/'; $n = strlen($pre);
    $left = []; foreach ((array) $except as $e) if ($pre === '' || strncmp($e . '/', $pre, $n) === 0) $left[] = $e . '/';   // (what is left out is not counted)
    $count = function ($list) use ($pre, $n, $left) { $c = 0; foreach ($list as $f) { if (strncmp($f, $pre, $n) !== 0) continue; foreach ($left as $e) if (strncmp($f, $e, strlen($e)) === 0) continue 2; $c++; } return $c; };
    return [$count($all), $count($public)];
}

/** The folders a link covers, as a phrase that says for each what the link takes from it and how many items that is: everything inside (and
 *  how many of those are private), or only the public ones (and how many private ones are left out). */
function link_folder_list_html($report, $item) {
    return implode(', ', array_map(function ($x) use ($item, $report) {
        $say = '';
        if ($x['state'] === 'ok' && is_array($x['counts'] ?? null)) {
            [$all, $pub] = $x['counts']; $priv = $all - $pub;
            if (!empty($x['pub'])) $say = ' (public items only: ' . ($priv > 0 ? $pub . ' of ' . $all . '; ' . $priv . ' private left out' : $all) . ')';
            else $say = ' (everything inside: ' . $all . ($priv > 0 ? ', ' . $priv . ' private' : '') . ')';
        } elseif (!empty($x['pub'])) $say = ' (public items only)';
        $out = array_map(function ($e) { return $e['label']; }, array_values(array_filter((array) ($report['except'] ?? []), function ($e) use ($x) { return $x['state'] === 'ok' && ($x['rel'] === '' || str_starts_with($e['rel'], $x['rel'] . '/')); })));
        if ($out) { $w = 'without ' . htmlspecialchars(implode(', ', $out), ENT_QUOTES, 'UTF-8'); $say = $say !== '' && substr($say, -1) === ')' ? substr($say, 0, -1) . '; ' . $w . ')' : $say . ' (' . $w . ')'; }
        return $item($x) . $say;
    }, $report['folders']));
}

function link_scope_text_html($l, $report) {
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $item = function ($x) use ($esc) {
        return $x['state'] === 'ok' ? $esc($x['label']) : '<span class="sl-bad">' . $esc($x['label']) . ' <em>(' . ($x['state'] === 'ambiguous' ? 'found in more than one folder, so it is not shown' : 'can\'t be found: renamed away, moved or deleted?') . ')</em></span>';
    };
    $type = $l['scope']['type'] ?? 'all';
    if ($type === 'folders') return 'Only ' . link_folder_list_html($report, $item);
    if ($type === 'mixed') { $n = count($report['photos']); return 'Only ' . link_folder_list_html($report, $item) . ($n ? ' and ' . $n . ' photo' . ($n === 1 ? '' : 's') . ': ' . implode(', ', array_map($item, array_slice($report['photos'], 0, 4))) . ($n > 4 ? ' and ' . ($n - 4) . ' more' : '') : ''); }
    if ($type === 'photos') return count($report['photos']) . ' photo' . (count($report['photos']) === 1 ? '' : 's') . ': ' . implode(', ', array_map($item, array_slice($report['photos'], 0, 6))) . (count($report['photos']) > 6 ? ' and ' . (count($report['photos']) - 6) . ' more' : '');
    if ($type === 'public') return 'Nothing private: just the public gallery';
    return 'Everything, public and private';
}

function link_capability_text_html($l, $cap, $report, $map, $index, $dir, $rootFid) {
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $mode = $l[$cap]['mode'] ?? 'off';
    if ($mode === 'off') return 'Off';
    if ($mode === 'all') return 'Everything';
    if ($mode === 'public') return 'Everything but private';
    $names = [];
    foreach ((array) ($l[$cap]['folders'] ?? []) as $fid) { [$s, $rel] = link_fid_state($fid, $map, $index, $rootFid); $names[] = $s === 'ok' ? $esc(link_folder_label($dir, $rel)) : '<span class="sl-bad">a folder that can\'t be found</span>'; }
    return 'Only some folders: ' . implode(', ', $names);
}

