/* -----------------------------------------------------------------------
 * Share links: the admin side. The Shares tab lists every link, makes
 * new ones and changes them; each change is a small POST that answers JSON
 * (the page then fetches itself again, as the Private tab does, so what it
 * shows is always what is stored). Nothing here is reachable without the
 * admin's own session and CSRF token.
 * ----------------------------------------------------------------------- */

function link_clip($s, $max) {
    $s = (string) $s;
    return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max);
}

function link_state_label($state) {
    return ['active' => 'Active', 'paused' => 'Paused', 'scheduled' => 'Starts later', 'expired' => 'Expired', 'used_up' => 'Used up', 'revoked' => 'Revoked'][$state] ?? $state;
}

/** The labels of the folders on the way to a folder, joined ("Eurotrip 2026 / Rome"). */
function link_folder_label($dir, $rel) {
    $out = []; $prefix = '';
    foreach (explode('/', $rel) as $seg) {
        $prefix = $prefix === '' ? $seg : $prefix . '/' . $seg;
        $label = folder_display_meta($dir, $prefix)['label'];
        $out[] = $label !== '' ? $label : $seg;
    }
    return implode(' / ', $out);
}

/** The New link / Edit form's fields, checked. Returns [fields, errors]; $existing is the link being edited (its own end time may
 *  stay as it is). Folders arrive as paths and leave as hidden ids (stamped into the folder on the way). */
function link_input_from_post($now, $existing, $dir, $files) {
    $errors = [];
    $label = link_clip(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) ($_POST['label'] ?? ''))), 80);
    if ($label === '') $errors[] = 'Give the link a name, so you can recognise it later.';
    $note = (string) preg_replace('/[^\P{C}\n]+/u', '', str_replace("\r\n", "\n", (string) ($_POST['note'] ?? '')));
    $note = link_clip(trim($note), 200);
    $titleMode = (string) ($_POST['title_mode'] ?? 'gallery');
    if (!in_array($titleMode, ['gallery', 'neutral', 'custom'], true)) $titleMode = 'gallery';
    $titleText = link_clip(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) ($_POST['title_text'] ?? ''))), 80);
    if ($titleMode === 'custom' && $titleText === '') $titleMode = 'gallery';
    // A preview photo in the message preview and on the opening page: on when the title is the gallery's own, unless the form says otherwise;
    // a request that doesn't say (an older page, a script) leaves an existing link as it was.
    $picture = isset($_POST['picture']) ? $_POST['picture'] === '1' : ($existing !== null ? !empty($existing['picture']) : $titleMode === 'gallery');
    // Which cover: a small number that makes the automatic pick (kept for the link's life, so the picture is the same every time), and the owner's own choice, if any.
    $pictureSeed = (string) ($existing['pictureSeed'] ?? '');
    if (isset($_POST['picture_seed']) && is_string($_POST['picture_seed']) && preg_match('/^[0-9a-f]{8}$/', $_POST['picture_seed'])) $pictureSeed = $_POST['picture_seed'];
    if ($pictureSeed === '' && $existing === null) $pictureSeed = bin2hex(random_bytes(4));
    $pictureFile = (string) ($existing['pictureFile'] ?? '');
    if (isset($_POST['picture_file']) && is_string($_POST['picture_file'])) {
        $want = trim(str_replace(['\\', "\0"], ['/', ''], $_POST['picture_file']), '/');
        if ($want === '') $pictureFile = '';
        elseif ($want === $pictureFile) { /* unchanged: left as it is (it is looked at again whenever it is used) */ }
        elseif (link_picture_ok($want, $dir)) $pictureFile = $want;
        else $errors[] = 'That photo can\'t be the preview photo: only a public photo can.';
    }
    // The link's own address: left blank it keeps the random one it has (or gets one); a custom name is checked here, and again where it is saved.
    // '_address' is null when nothing changes, '' for "back to a random one" and the name otherwise.
    $address = null;
    if (isset($_POST['address']) && is_string($_POST['address'])) {
        $want = strtolower(trim($_POST['address']));
        $current = $existing !== null && !empty($existing['custom']) ? strtolower((string) $existing['token']) : '';
        if ($want !== $current) {
            if ($want === '') $address = '';
            else {
                $why = link_custom_name_problem($want, $dir, (string) ($existing['id'] ?? ''));
                // An address made from the link's name ("address_auto"): when it is taken or is one the gallery uses itself, a number goes on the end
                // (johndoe2) and the owner is told, instead of being refused. A name that is too short or has odd characters is still refused.
                if ($why !== '' && ($_POST['address_auto'] ?? '') === '1' && preg_match('/^[a-z0-9][a-z0-9-]*[a-z0-9]$/', $want) === 1 && strlen($want) >= 4) {
                    for ($n = 2; $n <= 99 && $why !== ''; $n++) {
                        $try = substr($want, 0, 40 - strlen((string) $n)) . $n;
                        if (link_custom_name_problem($try, $dir, (string) ($existing['id'] ?? '')) === '') { $GLOBALS['__address_renamed'] = [$want, $try]; $want = $try; $why = ''; }
                    }
                }
                if ($why !== '') $errors[] = $why; else $address = $want;
            }
        }
    }
    $maxOpens = max(0, min(100000, (int) ($_POST['max_opens'] ?? 0)));
    $endsAt = max(0, (int) ($_POST['ends_at'] ?? 0));
    $startsAt = max(0, (int) ($_POST['starts_at'] ?? 0));
    if ($endsAt > $now + 3650 * 86400) $endsAt = $now + 3650 * 86400;
    if ($endsAt > 0 && $endsAt <= $now && !($existing !== null && (int) ($existing['endsAt'] ?? 0) === $endsAt)) $errors[] = 'The end time has to be in the future.';
    if ($startsAt > 0 && $startsAt <= $now) $startsAt = 0;
    if ($startsAt > 0 && $endsAt > 0 && $startsAt >= $endsAt) $errors[] = 'The link has to start before it ends.';
    $locations = (string) ($_POST['locations'] ?? 'public');
    if (!in_array($locations, ['public', 'all'], true)) $locations = 'public';
    $headsUp = (string) ($_POST['heads_up'] ?? 'default');
    if (!in_array($headsUp, ['default', 'on', 'off'], true)) $headsUp = 'default';

    // What it covers, and where it opens. Folders come as paths; each is checked against the folders that really exist, and
    // stamped with its hidden id (a folder that can't be written to can't be remembered, so it is refused).
    $validFolders = array_fill_keys(all_folder_paths($files), true);
    $collectionIds = [];   // a collection can be picked like a folder: the link remembers it by the collection's own id
    if (AUTO_GROUP_BY_FOLDER && collections_any()) foreach (collections_registry($dir, $files)['byPath'] as $cPath => $cRec) { $validFolders[$cPath] = true; $collectionIds[$cPath] = $cRec['id']; }
    $validFiles = array_fill_keys($files, true);
    $index = [];   // id => path, for the links file's list of where folders were last seen
    $list = function ($key) { $v = json_decode((string) ($_POST[$key] ?? '[]'), true); return is_array($v) ? array_slice(array_values(array_unique(array_filter($v, 'is_string'))), 0, 60) : []; };
    $stamp = function ($rel) use ($dir, $validFolders, $collectionIds, &$errors, &$index) {
        if (!isset($validFolders[$rel])) { $errors[] = 'One of the folders you chose isn\'t in the gallery any more.'; return null; }
        if (isset($collectionIds[$rel])) { $index[$collectionIds[$rel]] = $rel; return $collectionIds[$rel]; }
        $id = folder_id_stamp(resolve_real_path($dir, $rel));
        if ($id === '') { $errors[] = 'A link can\'t be limited to "' . link_clip($rel, 60) . '": the web server can\'t write in that folder, and a link remembers a folder by a small id kept in it.'; return null; }
        $index[$id] = $rel;
        return $id;
    };
    $scopeType = (string) ($_POST['scope_type'] ?? 'all');
    if (!in_array($scopeType, ['all', 'folders', 'photos', 'mixed', 'public'], true)) $scopeType = 'all';
    $scopeFolderPaths = []; $scopeExceptPaths = [];
    $scope = ['type' => 'all', 'folders' => [], 'photos' => []];
    // The picked photos, each remembered by the id of its folder and its name (shared by "only some photos" and "some folders and some photos").
    $stampPhotos = function ($rels) use ($validFiles, $dir, $stamp, &$errors, &$index) {
        $photos = [];
        foreach ($rels as $rel) {
            if (!isset($validFiles[$rel])) { $errors[] = 'One of the photos you chose isn\'t in the gallery any more.'; continue; }
            $slash = strrpos($rel, '/');
            $folderRel = $slash === false ? '' : substr($rel, 0, $slash);
            $fid = $folderRel === '' ? folder_id_stamp($dir) : $stamp($folderRel);
            if ($folderRel === '' && $fid === '') { $errors[] = 'The gallery\'s own folder can\'t be written to, so a link can\'t remember a photo in it.'; continue; }
            if ($fid !== null) { $photos[] = ['fid' => $fid, 'name' => basename($rel)]; if ($folderRel === '') $index[$fid] = ''; }
        }
        return $photos;
    };
    if ($scopeType === 'folders' || $scopeType === 'mixed') {
        if (!AUTO_GROUP_BY_FOLDER) $errors[] = 'Turn on "Show subfolders" in Settings to limit a link to folders.';
        $scopeFolderPaths = $list('scope_folders');
        $ids = [];
        foreach ($scopeFolderPaths as $rel) { $id = $stamp($rel); if ($id !== null) $ids[] = $id; }
        $photos = $scopeType === 'mixed' ? $stampPhotos($list('scope_photos')) : [];
        if ($scopeType === 'folders' && !$scopeFolderPaths) $errors[] = 'Choose at least one folder, or choose "Everything".';
        if ($scopeType === 'mixed' && !$scopeFolderPaths && !$photos) $errors[] = 'Choose at least one folder or photo.';
        // "Public items only", folder by folder: the private photos and videos inside it are left out (unless one is named in this link).
        $pubIds = [];
        foreach (array_values(array_intersect($list('scope_public_only'), $scopeFolderPaths)) as $rel) {
            if (folder_is_private($dir, $rel)) { $errors[] = '"' . link_clip($rel, 60) . '" is private as a whole, so "Public items only" would show nothing in it. Choose "Everything" for it, or make the folder visible first.'; continue; }
            $id = $stamp($rel); if ($id !== null) $pubIds[] = $id;
        }
        $scope = ['type' => $scopeType, 'folders' => $ids, 'photos' => $photos, 'publicOnly' => $pubIds];
        // Folders left out of a folder that is picked (the folder and everything inside it), each remembered by its id: kept only where a picked folder is what covers it from above.
        $exIds = []; $exList = array_values(array_diff($list('scope_except'), $scopeFolderPaths));   // (a folder that is itself picked is not left out)
        foreach ($exList as $rel) {
            if (!isset($validFolders[$rel]) || isset($collectionIds[$rel]) || !link_path_in_folders($rel, $scopeFolderPaths, array_diff($exList, [$rel]))) continue;
            $id = folder_id_stamp(resolve_real_path($dir, $rel));
            if ($id === '') { $errors[] = 'A link can\'t leave out "' . link_clip($rel, 60) . '": the web server can\'t write in that folder, and a link remembers a folder by a small id kept in it.'; continue; }
            $index[$id] = $rel; $exIds[] = $id; $scopeExceptPaths[] = $rel;
        }
        if ($exIds) $scope['except'] = $exIds;
    } elseif ($scopeType === 'photos') {
        $photos = $stampPhotos($list('scope_photos'));
        if (!$photos) $errors[] = 'Choose at least one photo.';
        $scope = ['type' => 'photos', 'folders' => [], 'photos' => $photos];
    } elseif ($scopeType === 'public') {
        $scope = ['type' => 'public', 'folders' => [], 'photos' => []];   // nothing private: the person sees what any visitor sees, and the log records it
    }
    $underScope = function ($rel) use ($scopeType, $scopeFolderPaths, &$scopeExceptPaths) {
        if ($scopeType !== 'folders' && $scopeType !== 'mixed') return true;
        return link_path_in_folders($rel, $scopeFolderPaths, $scopeExceptPaths);
    };
    $openIn = '';
    $inRel = trim((string) ($_POST['open_in'] ?? ''));
    if ($inRel !== '' && $scopeType !== 'photos') {
        if (!$underScope($inRel)) $errors[] = 'It can only open in a folder it covers.';
        elseif ($scopeType === 'public' && folder_is_private($dir, $inRel)) $errors[] = 'A link to the public gallery can\'t open in a private folder.';
        else { $id = $stamp($inRel); if ($id !== null) $openIn = $id; }
    }
    $cap = function ($modeKey, $listKey, $noun) use ($stamp, $list, $underScope, &$errors, $scopeType) {
        $mode = (string) ($_POST[$modeKey] ?? 'off');
        if (!in_array($mode, ['off', 'all', 'public', 'some'], true)) $mode = 'off';
        if ($mode === 'public' && $scopeType === 'public') $mode = 'all';   // (a link that shows nothing private: "everything" and "everything but private" are the same)
        $ids = [];
        if ($mode === 'some') {
            $paths = $list($listKey);
            if (!$paths) $errors[] = 'Choose the folders ' . $noun . ' is allowed in, or pick another choice.';
            foreach ($paths as $rel) {
                if (!$underScope($rel)) { $errors[] = 'The ' . $noun . ' folders have to be among the folders the link covers.'; continue; }
                $id = $stamp($rel); if ($id !== null) $ids[] = $id;
            }
        }
        return ['mode' => $mode, 'folders' => $ids];
    };
    if ($pictureFile !== '' && in_array($scopeType, ['folders', 'photos', 'mixed'], true)
        && !link_cover_in_scope(null, $dir, $pictureFile, ['type' => $scopeType, 'folders' => $scopeFolderPaths, 'except' => $scopeExceptPaths, 'photos' => $scopeType === 'folders' ? [] : $list('scope_photos')])) $pictureFile = '';   // (a chosen preview photo the link does not cover: the automatic pick takes over)
    $downloads = $cap('downloads', 'downloads_folders', 'downloads');
    $zip = $cap('zip', 'zip_folders', 'ZIP downloads');
    $only = ($scopeType === 'folders' || $scopeType === 'photos' || $scopeType === 'mixed') && ($_POST['only'] ?? '') === '1';   // "show only these": the person's pages list nothing but what the link covers, the rest of the public gallery too
    return [[
        'label' => $label, 'note' => $note, 'titleMode' => $titleMode, 'titleText' => $titleText, 'picture' => $picture, 'pictureSeed' => $pictureSeed, 'pictureFile' => $pictureFile, 'maxOpens' => $maxOpens,
        'startsAt' => $startsAt, 'endsAt' => $endsAt, 'locations' => $locations, 'headsUp' => $headsUp,
        'scope' => $scope, 'only' => $only, 'openIn' => $openIn, 'downloads' => $downloads, 'zip' => $zip, '_index' => $index, '_address' => $address, '_keep' => ($_POST['keep_old'] ?? '') === '1',
    ], $errors];
}

/** Gives a link record its address: a custom name, or ('') a fresh random token. The address it had goes to the list of replaced
 *  ones (so it is noted in the log as such and stops working), or, with $keep, to the ones kept working (they send people on to the
 *  new address); $d is the links file's data, to refuse a name another link took in the meantime. Returns '' or the reason it
 *  couldn't be done. */
function link_apply_address(&$rec, $address, $d = null, $keep = false) {
    if ($address === null) return '';
    if ($address !== '' && $d !== null) {
        foreach ((array) ($d['links'] ?? []) as $other) {
            if (!is_array($other) || ($other['id'] ?? '') === ($rec['id'] ?? '')) continue;
            foreach (link_all_addresses($other) as $t) if (strtolower($t) === $address) return LINK_ADDRESS_TAKEN;
        }
    }
    $aliases = array_values(array_filter((array) ($rec['aliases'] ?? []), 'is_array'));
    // (a name this link already keeps working becomes its address: it stops being a kept one)
    if ($address !== '') $aliases = array_values(array_filter($aliases, function ($a) use ($address) { return strtolower((string) ($a['name'] ?? '')) !== $address; }));
    $hadOne = ($rec['token'] ?? '') !== '' && empty($rec['_new']);
    if ($hadOne && $keep) {
        if (count($aliases) >= LINK_ALIASES_MAX) return 'This link already has ' . LINK_ALIASES_MAX . ' old addresses working. Pause or kill one first (Settings, "Also works at").';
        $aliases[] = ['name' => $rec['token'], 'custom' => !empty($rec['custom']), 'added' => time(), 'hits' => 0, 'last' => 0];
        if (empty($rec['aliasSince'])) $rec['aliasSince'] = time();
    } elseif ($hadOne) {
        $old = (array) ($rec['oldTokens'] ?? []); $old[] = $rec['token'];
        $rec['oldTokens'] = array_values(array_unique($old));   // (replaced addresses are all kept: that is what stops another link taking the name)
    }
    $rec['aliases'] = $aliases;
    $rec['token'] = $address === '' ? link_new_token() : $address;
    $rec['custom'] = $address !== '';
    return '';
}

function link_apply_fields(&$rec, $f) {
    foreach (['label', 'note', 'titleMode', 'titleText', 'picture', 'pictureSeed', 'pictureFile', 'maxOpens', 'startsAt', 'endsAt', 'locations', 'headsUp', 'scope', 'only', 'openIn', 'downloads', 'zip'] as $k) $rec[$k] = $f[$k];
}

/** The Shares tab's POST actions (link_create, link_save, link_pause, link_resume, link_revoke, link_extend, link_replace,
 *  link_delete). Always answers JSON and ends the request. */
function links_admin_action($action, $dir, $files) {
    $now = time();
    $bad = function ($text) { admin_json_exit(['ok' => false, 'text' => $text]); };
    if (!ENABLE_SHARE_LINKS) $bad('Share links are switched off in Settings.');
    $id = (string) ($_POST['id'] ?? '');
    if ($action !== 'link_create' && $action !== 'link_seen' && !preg_match('/^[0-9a-f]{10}$/', $id)) $bad('Unknown link.');
    $wrote = function () use ($bad) {
        if (!empty($GLOBALS['__data_write_failed'])) $bad(data_write_failure_text('the gallery\'s folder'));
    };
    if ($id === PUBLIC_LOG_ID) $bad('That is not a link.');
    if ($action === 'link_seen') {   // the owner has looked at the tab: what happened before now is no longer "new"
        links_update(function (&$d) use ($now) { $d['seenAt'] = $now; });
        admin_json_exit(['ok' => true, 'text' => '']);
    }
    if ($action === 'link_create') {
        [$f, $errors] = link_input_from_post($now, null, $dir, $files);
        if ($errors) $bad(implode(' ', array_unique($errors)));
        $rec = link_record_new($now);
        link_apply_fields($rec, $f);
        $rec['_new'] = true;
        $taken = false;
        $GLOBALS['__data_write_failed'] = false; $GLOBALS['__data_refused'] = [];
        links_update(function (&$d) use (&$rec, $f, &$taken) {
            if (($why = link_apply_address($rec, $f['_address'], $d)) !== '') { $taken = $why; return; }
            unset($rec['_new']);
            $d['links'][$rec['id']] = $rec; foreach ($f['_index'] as $fid => $rel) $d['folderIndex'][$fid] = $rel;
        });
        if ($taken !== false) $bad($taken);
        $wrote();
        $renamed = $GLOBALS['__address_renamed'] ?? null;
        admin_json_exit(['ok' => true, 'id' => $rec['id'], 'text' => 'Link made.' . ($renamed ? ' The address "' . $renamed[0] . '" is taken or is one the gallery uses, so this link\'s address is "' . $renamed[1] . '".' : ''), 'urls' => link_urls($rec)]);
    }
    $problem = null; $text = 'Saved.';
    $saveFields = null; $saveErrors = [];
    if ($action === 'link_save') [$saveFields, $saveErrors] = link_input_from_post($now, ($links0 = links_read()['links'][$id] ?? null) === null ? null : $links0, $dir, $files);
    $restoreName = ''; $restoreWhy = ''; $killName = ''; $killUse = [0, 0, 0]; $killTried = [0, 0, 0];
    if ($action === 'link_alias_resume') {
        $restoreName = (string) ($_POST['alias'] ?? '');
        if (link_address_is_custom($restoreName)) $restoreWhy = link_custom_name_problem(strtolower($restoreName), $dir, $id);   // (a folder may have taken the name since)
    }
    if ($action === 'link_alias_kill') {   // (what the log says about it now, for the line that is left behind)
        $killName = (string) ($_POST['alias'] ?? '');
        $killLog = link_log_read($id);
        $killUse = link_address_log_stats($killLog, 'via', $killName); $killTried = link_address_log_stats($killLog, 'tried', $killName);
    }
    links_update(function (&$d) use ($action, $id, $now, &$problem, &$text, $saveFields, $saveErrors, $restoreName, $restoreWhy, $killName, $killUse, $killTried) {
        $l = $d['links'][$id] ?? null;
        if (!is_array($l)) { $problem = 'That link no longer exists.'; return; }
        $state = link_state($l, $now);
        if ($action === 'link_delete') { unset($d['links'][$id]); $text = 'Link and its log deleted.'; return; }
        if ($action === 'link_save') {
            if ($state === 'revoked') { $problem = 'A revoked link can\'t be changed.'; return; }
            if ($saveErrors) { $problem = implode(' ', array_unique($saveErrors)); return; }
            if (($why = link_apply_address($d['links'][$id], $saveFields['_address'], $d, !empty($saveFields['_keep']))) !== '') { $problem = $why; return; }
            link_apply_fields($d['links'][$id], $saveFields);
            foreach ($saveFields['_index'] as $fid => $rel) $d['folderIndex'][$fid] = $rel;
            return;
        }
        if ($action === 'link_forget') {
            // A folder that is gone: take it out of this link (and out of what it opens in, downloads and ZIPs).
            $fid = (string) ($_POST['fid'] ?? '');
            $had = in_array($fid, $d['links'][$id]['scope']['folders'] ?? [], true);
            $photosLeft = count(array_filter((array) ($d['links'][$id]['scope']['photos'] ?? []), function ($p) use ($fid) { return ($p['fid'] ?? '') !== $fid; }));
            if ($had && count($d['links'][$id]['scope']['folders']) < 2 && !(($d['links'][$id]['scope']['type'] ?? '') === 'mixed' && $photosLeft > 0)) { $problem = 'A link has to keep at least one folder: pick another one with Edit, or delete the link.'; return; }
            $keep = function ($ids) use ($fid) { return array_values(array_filter((array) $ids, function ($x) use ($fid) { return $x !== $fid; })); };
            $d['links'][$id]['scope']['folders'] = $keep($d['links'][$id]['scope']['folders'] ?? []);
            $d['links'][$id]['scope']['publicOnly'] = $keep($d['links'][$id]['scope']['publicOnly'] ?? []);
            if (isset($d['links'][$id]['scope']['except'])) $d['links'][$id]['scope']['except'] = $keep($d['links'][$id]['scope']['except']);
            $d['links'][$id]['scope']['photos'] = array_values(array_filter((array) ($d['links'][$id]['scope']['photos'] ?? []), function ($p) use ($fid) { return ($p['fid'] ?? '') !== $fid; }));
            foreach (['downloads', 'zip'] as $c) $d['links'][$id][$c]['folders'] = $keep($d['links'][$id][$c]['folders'] ?? []);
            if (($d['links'][$id]['openIn'] ?? '') === $fid) $d['links'][$id]['openIn'] = '';
            $text = 'Taken out of this link.';
            return;
        }
        if ($action === 'link_pause') {
            if (($l['status'] ?? '') !== 'active') { $problem = 'Only an active link can be paused.'; return; }
            $d['links'][$id]['status'] = 'paused'; $d['links'][$id]['pausedAt'] = $now; $text = 'Paused. Anyone using it is locked out until you resume it.';
            return;
        }
        if ($action === 'link_resume') {
            if (($l['status'] ?? '') !== 'paused') { $problem = 'That link isn\'t paused.'; return; }
            $d['links'][$id]['status'] = 'active'; $d['links'][$id]['pausedAt'] = 0; $text = 'Resumed.';
            return;
        }
        if ($action === 'link_revoke') {
            if (($l['status'] ?? '') === 'revoked') { $problem = 'Already revoked.'; return; }
            $d['links'][$id]['status'] = 'revoked'; $d['links'][$id]['revokedAt'] = $now; $text = 'Revoked. It stays here with its log.';
            return;
        }
        if ($action === 'link_replace') {
            if ($state === 'revoked') { $problem = 'A revoked link can\'t be given a new address.'; return; }
            // (this is the "it leaked" button: addresses kept working go the same way as the current one)
            $old = (array) ($d['links'][$id]['oldTokens'] ?? []); $old[] = $l['token'];
            $kept = 0;
            $stats = (array) ($l['aliasStats'] ?? []);
            foreach ((array) ($l['aliases'] ?? []) as $a) if (is_array($a) && ($a['name'] ?? '') !== '') { $old[] = $a['name']; $kept++; $stats[$a['name']] = ['hits' => (int) ($a['hits'] ?? 0), 'last' => (int) ($a['last'] ?? 0)]; }
            $d['links'][$id]['oldTokens'] = array_values(array_unique($old));
            $d['links'][$id]['aliasStats'] = $stats;
            $d['links'][$id]['aliases'] = [];
            $d['links'][$id]['token'] = link_new_token(); $d['links'][$id]['custom'] = false;
            $text = $kept > 0 ? 'New address ready. The old one, and the ' . ($kept === 1 ? 'one' : $kept) . ' you kept working, are paused.' : 'New address ready. The old one no longer works.';
            return;
        }
        if ($action === 'link_alias_resume') {
            if ($state === 'revoked') { $problem = 'A revoked link can\'t be changed.'; return; }
            if ($restoreWhy !== '') { $problem = $restoreWhy; return; }
            $old = array_values(array_map('strval', (array) ($l['oldTokens'] ?? [])));
            if (!in_array($restoreName, $old, true)) { $problem = 'That address isn\'t paused.'; return; }
            if (strtolower($restoreName) === strtolower((string) ($l['token'] ?? ''))) { $problem = 'That is the link\'s own address.'; return; }
            foreach ((array) ($d['links'] ?? []) as $other) {
                if (!is_array($other) || ($other['id'] ?? '') === $id) continue;
                foreach (link_all_addresses($other) as $t) if (strtolower($t) === strtolower($restoreName)) { $problem = LINK_ADDRESS_TAKEN; return; }
            }
            $aliases = array_values(array_filter((array) ($l['aliases'] ?? []), 'is_array'));
            if (count($aliases) >= LINK_ALIASES_MAX) { $problem = 'This link already has ' . LINK_ALIASES_MAX . ' old addresses working. Pause or kill one first (Settings, "Also works at").'; return; }
            $stats = (array) ($l['aliasStats'] ?? []); $was = (array) ($stats[$restoreName] ?? []);
            $aliases[] = ['name' => $restoreName, 'custom' => link_address_is_custom($restoreName), 'added' => $now, 'hits' => (int) ($was['hits'] ?? 0), 'last' => (int) ($was['last'] ?? 0)];
            unset($stats[$restoreName]);
            $d['links'][$id]['aliases'] = $aliases;
            $d['links'][$id]['aliasStats'] = $stats;
            $d['links'][$id]['oldTokens'] = array_values(array_filter($old, function ($t) use ($restoreName) { return $t !== $restoreName; }));
            if (empty($l['aliasSince'])) $d['links'][$id]['aliasSince'] = $now;
            $text = 'Resumed. It sends people on to the link\'s present address again.';
            return;
        }
        if ($action === 'link_alias_pause') {
            $name = (string) ($_POST['alias'] ?? ''); $left = []; $gone = null;
            foreach ((array) ($l['aliases'] ?? []) as $a) {
                if (is_array($a) && ($a['name'] ?? '') === $name) { $gone = $a; continue; }
                if (is_array($a)) $left[] = $a;
            }
            if ($gone === null) { $problem = 'That address isn\'t working any more.'; return; }
            $old = (array) ($l['oldTokens'] ?? []); $old[] = $name;
            $stats = (array) ($l['aliasStats'] ?? []); $stats[$name] = ['hits' => (int) ($gone['hits'] ?? 0), 'last' => (int) ($gone['last'] ?? 0)];   // (kept, so Resume gives them back)
            $d['links'][$id]['oldTokens'] = array_values(array_unique($old));
            $d['links'][$id]['aliasStats'] = $stats;
            $d['links'][$id]['aliases'] = $left;
            $text = 'Paused. It shows the “not valid” page until you resume it.';
            return;
        }
        if ($action === 'link_alias_kill') {
            $name = $killName; $custom = link_address_is_custom($name); $hits = 0; $last = 0; $found = false; $left = [];
            foreach ((array) ($l['aliases'] ?? []) as $a) {
                if (is_array($a) && ($a['name'] ?? '') === $name) { $found = true; $hits = (int) ($a['hits'] ?? 0); $last = (int) ($a['last'] ?? 0); continue; }
                if (is_array($a)) $left[] = $a;
            }
            $old = array_values(array_filter(array_map('strval', (array) ($l['oldTokens'] ?? [])), function ($t) use ($name, &$found) { if ($t === $name) { $found = true; return false; } return true; }));
            $stats = (array) ($l['aliasStats'] ?? []);
            if (isset($stats[$name])) { $hits = (int) ($stats[$name]['hits'] ?? 0); $last = (int) ($stats[$name]['last'] ?? 0); unset($stats[$name]); }
            if (!$found) { $problem = 'That address isn\'t one of this link\'s old addresses.'; return; }
            // (only the short label is kept, never the address itself: a killed address can't be brought back, and its name is free)
            $killed = (array) ($l['killed'] ?? []);
            $killed[] = ['label' => link_address_label($name, $custom), 'custom' => $custom, 'killedAt' => $now, 'hits' => $hits, 'ips' => (int) $killUse[1], 'tries' => (int) $killTried[0], 'last' => max($last, (int) $killUse[2])];
            $d['links'][$id]['aliases'] = $left; $d['links'][$id]['oldTokens'] = $old; $d['links'][$id]['aliasStats'] = $stats; $d['links'][$id]['killed'] = $killed;
            $text = $custom ? 'Killed. The name is free to use again.' : 'Killed.';
            return;
        }
        if ($action === 'link_killed_clear') {
            $d['links'][$id]['killed'] = [];
            $text = 'Cleared.';
            return;
        }
        if ($action === 'link_extend') {
            if ($state === 'revoked') { $problem = 'A revoked link can\'t be extended.'; return; }
            $endsAt = (int) ($_POST['ends_at'] ?? 0);
            $addOpens = max(0, min(100000, (int) ($_POST['add_opens'] ?? 0)));
            if ($endsAt > 0) {
                if ($endsAt <= $now) { $problem = 'The new end time has to be in the future.'; return; }
                $d['links'][$id]['endsAt'] = min($endsAt, $now + 3650 * 86400);
            } elseif (($_POST['no_end'] ?? '') === '1') {
                $d['links'][$id]['endsAt'] = 0;
            }
            if (($_POST['no_limit'] ?? '') === '1') $d['links'][$id]['maxOpens'] = 0;   // "Unlimited": the limit comes off
            elseif ($addOpens > 0 && (int) ($l['maxOpens'] ?? 0) > 0) $d['links'][$id]['maxOpens'] = (int) $l['maxOpens'] + $addOpens;
            $text = 'Extended.';
            return;
        }
        $problem = 'Unknown action.';
    });
    if ($problem !== null) $bad($problem);
    $wrote();
    if ($action === 'link_delete') link_delete_log($id);
    admin_json_exit(['ok' => true, 'id' => $id, 'text' => $text]);
}

/** One date for the tab: the page's script turns it into the visitor's own time zone; without it, UTC. */
function link_time_html($ts) {
    $ts = (int) $ts;
    return '<time class="sl-time" data-ts="' . $ts . '" datetime="' . gmdate('c', $ts) . '">' . htmlspecialchars(gmdate('D, M j, g:i a', $ts) . ' UTC', ENT_QUOTES, 'UTF-8') . '</time>';
}

