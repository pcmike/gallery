/* -----------------------------------------------------------------------
 * Collections, the owner's side (see 021a-collections.php). Every change is a change to one record in .gallery-collections.php: nothing
 * here ever writes in, moves or deletes anything in a photo folder, except that a folder used as a parent or a member is given its hidden id
 * (folder_id_stamp, the same small field a share link puts there) so the collection can find it again whatever it is renamed.
 * ----------------------------------------------------------------------- */

/** The stored record with this id, whether or not its parent can be found. */
function collection_find_by_id($id) {
    foreach (collections_list() as $r) if ($r['id'] === $id) return $r;
    return null;
}

/** '' when $name may be the address name of a collection in $parentPath, else what is wrong (said to the owner). $exceptId is the collection being renamed. */
function collection_address_problem($dir, $files, $parentPath, $name, $exceptId = '') {
    if (!collection_name_shape_ok($name)) return 'An address uses letters, digits, dots, dashes and underscores, starts with a letter or digit, and is not a word the gallery uses itself.';
    $path = $parentPath === '' ? $name : $parentPath . '/' . $name;
    $real = array_flip(array_map('strtolower', all_folder_paths($files)));   // (compared without regard to case: on some servers Italy and italy are one place)
    $mounts = array_flip(array_map('strtolower', array_keys(gallery_mounts())));
    if (isset($real[strtolower($path)]) || ($parentPath === '' && isset($mounts[strtolower($name)]))) return 'A folder is already at that address.';
    foreach (collections_registry($dir, $files)['byPath'] as $p => $r) if (strtolower($p) === strtolower($path) && $r['id'] !== $exceptId) return 'Another collection is already at that address.';
    return '';
}

/** An address name that is free in $parentPath, made from $base (a dash and a number added when it is taken). */
function collection_free_name($dir, $files, $parentPath, $base) {
    $base = collection_slug($base);
    for ($n = 1; $n < 200; $n++) {
        $try = $n === 1 ? $base : substr($base, 0, 56) . '-' . $n;
        if (collection_address_problem($dir, $files, $parentPath, $try) === '') return $try;
    }
    return $base . '-' . bin2hex(random_bytes(2));
}

/** The folder's id for a record to remember, '' when it can't be written: ($relFolder is a folder path; '' is the gallery's own folder). */
function collection_folder_id($dir, $relFolder) {
    return folder_id_stamp($relFolder === '' ? $dir : resolve_real_path($dir, $relFolder));
}

/** The record as the folder settings form knows a folder's own settings (so the same form and fingerprint serve both). */
function collection_as_items($r) {
    return ['displayName' => $r['title'] !== '' ? $r['title'] : null, 'description' => $r['description'] !== '' ? $r['description'] : null, 'dates' => $r['dates'] !== '' ? $r['dates'] : null,
            'sortOrder' => $r['position'], 'photoSort' => $r['photoSort'] ?: null, 'subfolderStyle' => $r['subfolderStyle'], 'flatNames' => $r['flatNames'], 'flatNotes' => $r['flatNotes']];
}

/** Does any folder or collection use "Gallery of its own"? The gallery's own record keeps the answer so that a gallery that uses none reads nothing more. */
function own_galleries_recompute($dir, $files) {
    $any = false;
    foreach (all_folder_paths($files) as $p) { if ($p !== '' && folder_own_gallery_cached($dir, $p)) { $any = true; break; } }
    if (!$any) foreach (collections_list() as $r) if ($r['ownGallery']) { $any = true; break; }
    items_update($dir, function (&$data) use ($any) { if ($any) $data['ownGalleries'] = true; else unset($data['ownGalleries']); });
}

function collection_fail_warn($text) { admin_json_exit(['ok' => true, 'text' => '', 'warn' => $text]); }
function collection_fail($text) { admin_json_exit(['ok' => false, 'text' => $text]); }
function collection_saved_or_fail() { if (!empty($GLOBALS['__data_write_failed'])) collection_fail(data_write_failure_text('the gallery\'s folder')); }

/** The members a posted list names, as records to add: [{path, file?}] where path is a folder's path ('' only for a photo at the gallery's top). Checked
 *  against the gallery's own file list, never trusted. Returns [records, error]. */
function collection_members_from_post($dir, $files, $json) {
    $list = json_decode((string) $json, true);
    if (!is_array($list) || !$list || count($list) > 2000) return [[], 'Nothing was chosen.'];
    $out = [];
    foreach ($list as $m) {
        if (!is_array($m) || !is_string($m['path'] ?? null)) continue;
        $path = trim($m['path'], '/');
        if (isset($m['file'])) {
            if (!is_string($m['file']) || $m['file'] === '' || strpos($m['file'], '/') !== false) continue;
            $f = $path === '' ? $m['file'] : $path . '/' . $m['file'];
            if (!in_array($f, $files, true)) continue;
            $fid = collection_folder_id($dir, $path);
            if ($fid === '') return [[], 'Nothing was saved: ' . data_write_failure_place($dir, $path === '' ? $dir : resolve_real_path($dir, $path)) . ' isn\'t writable by the web server, so the photo can\'t be remembered there. Give the web server\'s user write permission on it, then try again.'];
            $out[] = ['fid' => $fid, 'file' => $m['file']];
        } else {
            if ($path === '' || empty(folder_files_under($files, $path))) continue;
            $fid = collection_folder_id($dir, $path);
            if ($fid === '') return [[], 'Nothing was saved: ' . data_write_failure_place($dir, resolve_real_path($dir, $path)) . ' isn\'t writable by the web server, so the folder can\'t be remembered there. Give the web server\'s user write permission on it, then try again.'];
            $out[] = ['fid' => $fid, 'mode' => ($m['mode'] ?? 'all') === 'own' ? 'own' : 'all'];
        }
    }
    return [$out, $out ? '' : 'Nothing that is here was chosen.'];
}

/** The owner's collection actions (they answer in JSON and end the request, like the Shares tab's). $adminFolderPath is the page being edited (a collection's own
 *  address for the settings and the switches). */
function collections_admin_action($action, $dir, $adminFolderPath, $files) {
    if ($action === 'collection_create') {
        $parentRel = trim((string) ($_POST['parent'] ?? ''), '/');
        if ($parentRel !== '' && empty(folder_files_under($files, $parentRel))) collection_fail('That folder is not here.');
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title === '') collection_fail('Give the collection a name.');
        if (strlen($title) > 200) $title = substr($title, 0, 200);
        $parentFid = '';
        if ($parentRel !== '') {
            $parentFid = collection_folder_id($dir, $parentRel);
            if ($parentFid === '') collection_fail('Nothing was saved: ' . data_write_failure_place($dir, resolve_real_path($dir, $parentRel)) . ' isn\'t writable by the web server, and a collection kept in a folder needs the folder to remember it by. Give the web server\'s user write permission on it, then try again.');
        }
        $name = collection_free_name($dir, $files, $parentRel, $title);
        $rec = ['id' => 'c' . bin2hex(random_bytes(6)), 'parent' => $parentFid, 'name' => $name, 'title' => $title, 'description' => '', 'cover' => null, 'dates' => '', 'position' => null,
                'private' => false, 'front' => false, 'ownGallery' => false, 'items' => [], 'at' => time()];
        $GLOBALS['__data_write_failed'] = false; $GLOBALS['__data_refused'] = [];
        collections_update(function (&$d) use ($rec) { $d['collections'][] = $rec; });
        collection_saved_or_fail();
        $path = $parentRel === '' ? $name : $parentRel . '/' . $name;
        admin_json_exit(['ok' => true, 'text' => 'Collection made.', 'url' => '?admin&folder=' . rawurlencode($path), 'path' => $path, 'name' => $name, 'id' => $rec['id']]);
    }
    if ($action === 'collection_delete') {
        $id = (string) ($_POST['id'] ?? '');
        if (collection_find_by_id($id) === null) collection_fail('That collection is not here (it may already be gone).');
        $GLOBALS['__data_write_failed'] = false; $GLOBALS['__data_refused'] = [];
        collections_update(function (&$d) use ($id) { $d['collections'] = array_values(array_filter((array) $d['collections'], function ($r) use ($id) { return !is_array($r) || ($r['id'] ?? '') !== $id; })); });
        collection_saved_or_fail();
        own_galleries_recompute($dir, $files);
        admin_json_exit(['ok' => true, 'text' => 'Collection deleted. Nothing in it was touched.']);
    }
    if ($action === 'collection_photos') {   // the photos directly in a folder, for the picker (names, thumbnails, whether each is public)
        $folder = trim((string) ($_POST['folder'] ?? ''), '/');
        $out = [];
        foreach ($files as $f) {
            $d = dirname($f); $d = ($d === '.' || $d === '') ? '' : $d;
            if ($d !== $folder) continue;
            $out[] = ['file' => basename($f), 'thumb' => img_url($f, 'thumb'), 'state' => file_is_unlisted($dir, $f) ? 'unlisted' : (file_is_hidden($dir, $f, items_get_all_for_files($dir, [$f])) ? 'private' : 'public')];
            if (count($out) >= 600) break;
        }
        admin_json_exit(['ok' => true, 'text' => '', 'photos' => $out]);
    }
    // Everything below changes one collection: the one at the page being edited, or the one named by id.
    $rec = isset($_POST['id']) ? collection_find_by_id((string) $_POST['id']) : ($adminFolderPath !== null ? collection_at($dir, $adminFolderPath, $files) : null);
    if ($rec === null) collection_fail('That collection is not here.');
    $id = $rec['id'];
    $GLOBALS['__data_write_failed'] = false; $GLOBALS['__data_refused'] = [];
    if ($action === 'toggle_collection_flag') {
        $field = isset($_POST['front']) ? 'front' : (isset($_POST['owngallery']) ? 'ownGallery' : (isset($_POST['private']) ? 'private' : ''));
        if ($field === '') collection_fail('Nothing to change.');
        $on = (string) ($_POST[$field === 'ownGallery' ? 'owngallery' : $field]) === '1';
        collections_update(function (&$d) use ($id, $field, $on) { foreach ($d['collections'] as &$r) if (is_array($r) && ($r['id'] ?? '') === $id) $r[$field] = $on; });
        collection_saved_or_fail();
        if ($field === 'ownGallery') own_galleries_recompute($dir, $files);
        $now = collection_find_by_id($id);
        admin_json_exit(['ok' => true, 'private' => $now['private'], 'text' => $field === 'front' ? ($on ? 'It shows on the front page too.' : 'It no longer shows on the front page.') : ($field === 'private' ? ($on ? 'Private: visitors see nothing of this collection (its folders and photos stay as they are).' : 'Public again.') : ($on ? 'Visitors who arrive in this collection are offered nothing above it.' : 'Back to ordinary.'))]);
    }
    if ($action === 'save_folder_identity') {
        $changes = [];
        if (array_key_exists('display_name', $_POST)) $changes['title'] = substr(trim((string) $_POST['display_name']), 0, 200);
        if (array_key_exists('description', $_POST)) $changes['description'] = admin_clean_description($_POST['description'], 1000);
        if (array_key_exists('dates', $_POST)) $changes['dates'] = admin_clean_text($_POST['dates'], 80);
        if (array_key_exists('sort_order', $_POST)) {
            $raw = trim((string) $_POST['sort_order']);
            if ($raw === '') $changes['position'] = null;
            elseif (preg_match('/^-?\d{1,5}$/', $raw)) $changes['position'] = (int) $raw;
            else collection_fail('Position must be a whole number (or blank for natural order).');
        }
        if (array_key_exists('subfolder_style', $_POST)) { $st = (string) $_POST['subfolder_style']; $changes['subfolderStyle'] = in_array($st, ['overview', 'flat'], true) ? $st : null; }
        foreach (['flat_names' => 'flatNames', 'flat_notes' => 'flatNotes'] as $pk => $rk) if (array_key_exists($pk, $_POST)) { $v = (string) $_POST[$pk]; $changes[$rk] = $v === '1' ? true : ($v === '0' ? false : null); }
        if (array_key_exists('photo_sort_order', $_POST)) $changes['photoSort'] = admin_photo_sort_from_post();
        if (array_key_exists('coll_address', $_POST)) {
            $name = trim((string) $_POST['coll_address']);
            if ($name !== $rec['name']) {
                $why = collection_address_problem($dir, $files, $rec['parentPath'] ?? ($adminFolderPath !== null && strpos($adminFolderPath, '/') !== false ? dirname($adminFolderPath) : ''), $name, $id);
                if ($why !== '') collection_fail($why);
                $changes['name'] = $name;
            }
        }
        $loaded = isset($_POST['loaded_hash']) ? (string) $_POST['loaded_hash'] : null;
        $stale = false; $newHash = null;
        collections_update(function (&$d) use ($id, $changes, $loaded, &$stale, &$newHash) {
            foreach ($d['collections'] as &$r) {
                if (!is_array($r) || ($r['id'] ?? '') !== $id) continue;
                $cur = collection_clean($r);
                if ($loaded !== null && !hash_equals(admin_record_hash(folder_identity_record(collection_as_items($cur))), $loaded)) { $stale = true; return; }
                foreach ($changes as $k => $v) $r[$k] = $v;
                $newHash = admin_record_hash(folder_identity_record(collection_as_items(collection_clean($r))));
            }
        });
        if ($stale) admin_json_exit(['ok' => false, 'stale' => true, 'text' => 'This collection\'s settings' . admin_stale_message()]);
        collection_saved_or_fail();
        $now = collection_find_by_id($id);
        $reg = collections_registry($dir, $files);
        $newPath = null; foreach ($reg['byPath'] as $p => $r) if ($r['id'] === $id) $newPath = $p;
        admin_json_exit(['ok' => true, 'text' => 'Saved', 'hash' => $newHash, 'path' => $newPath, 'name' => $now['name']]);
    }
    if ($action === 'collection_member_add' || $action === 'collection_member_add_files') {
        $json = (string) ($_POST['items'] ?? '');
        if ($action === 'collection_member_add_files') {   // the Select bar: photos of one folder page, by their names
            $folder = trim((string) ($_POST['folder'] ?? ''), '/');
            $names = json_decode((string) ($_POST['files'] ?? ''), true);
            if (!is_array($names)) collection_fail('Nothing was chosen.');
            $json = json_encode(array_map(function ($n) use ($folder) { return ['path' => $folder, 'file' => is_string($n) ? basename($n) : '']; }, $names));
        }
        [$add, $err] = collection_members_from_post($dir, $files, $json);
        if ($err !== '') collection_fail($err);
        if (!empty($_POST['check'])) {   // the page asks first: would this open anything private to people on a share link that names this collection?
            $linkNames = [];
            foreach ((array) (links_read()['links'] ?? []) as $lk) if (is_array($lk) && in_array($id, (array) ($lk['scope']['folders'] ?? []), true) && !link_is_public_log($lk)) $linkNames[] = (string) ($lk['label'] ?? 'a link');
            $reg = collections_registry($dir, $files); $opens = [];
            foreach ($add as $m) {
                $p = $reg['idPaths'][$m['fid']] ?? null; if ($p === null) continue;
                if (isset($m['file'])) { $f = $p === '' ? $m['file'] : $p . '/' . $m['file']; if (file_is_unlisted($dir, $f) || file_is_hidden($dir, $f, items_get_all_for_files($dir, [$f]))) $opens[] = $m['file']; }
                elseif (folder_is_unlisted($dir, $p) || folder_is_private($dir, $p) || folder_above_is_private($dir, $p)) $opens[] = $p;
            }
            if ($linkNames && $opens) {
                $n = count($linkNames);
                collection_fail_warn($n . ' share link' . ($n === 1 ? '' : 's') . ' include' . ($n === 1 ? 's' : '') . ' this collection (' . implode(', ', array_slice($linkNames, 0, 3)) . ($n > 3 ? ' and ' . ($n - 3) . ' more' : '') . '): ' . ($n === 1 ? 'it' : 'they') . ' would gain access to ' . implode(', ', array_slice($opens, 0, 3)) . (count($opens) > 3 ? ' and ' . (count($opens) - 3) . ' more' : '') . ', which ' . (count($opens) === 1 ? 'is' : 'are') . ' private.');
            }
            admin_json_exit(['ok' => true, 'text' => '', 'warn' => '']);
        }
        $added = 0;
        collections_update(function (&$d) use ($id, $add, &$added) {
            foreach ($d['collections'] as &$r) {
                if (!is_array($r) || ($r['id'] ?? '') !== $id) continue;
                $r['items'] = array_values((array) ($r['items'] ?? []));
                foreach ($add as $m) {
                    $dup = false;
                    foreach ($r['items'] as $have) if (is_array($have) && ($have['fid'] ?? '') === $m['fid'] && ((isset($m['file']) && ($have['file'] ?? null) === $m['file']) || (!isset($m['file']) && !isset($have['file'])))) { $dup = true; break; }
                    if (!$dup) { $r['items'][] = $m; $added++; }
                }
            }
        });
        collection_saved_or_fail();
        admin_json_exit(['ok' => true, 'added' => $added, 'text' => $added === 0 ? 'They were all in it already.' : 'Added ' . $added . ($added === 1 ? ' member.' : ' members.')]);
    }
    if ($action === 'collection_member_remove') {
        $key = (string) ($_POST['key'] ?? '');   // f:<folder id> or p:<folder id>/<file name>
        $removed = 0;
        collections_update(function (&$d) use ($id, $key, &$removed) {
            foreach ($d['collections'] as &$r) {
                if (!is_array($r) || ($r['id'] ?? '') !== $id) continue;
                $keep = [];
                foreach ((array) ($r['items'] ?? []) as $it) {
                    $k = !is_array($it) ? '' : (isset($it['file']) ? 'p:' . ($it['fid'] ?? '') . '/' . $it['file'] : 'f:' . ($it['fid'] ?? ''));
                    if ($k === $key && !$removed) { $removed++; continue; }
                    $keep[] = $it;
                }
                $r['items'] = $keep;
            }
        });
        collection_saved_or_fail();
        admin_json_exit(['ok' => true, 'removed' => $removed, 'text' => $removed ? 'Taken out of the collection. Nothing was deleted.' : 'It was not in the collection.']);
    }
    if ($action === 'collection_member_remove_files') {   // the Select bar on a collection's page: single photos by their full path
        $paths = json_decode((string) ($_POST['files'] ?? ''), true);
        if (!is_array($paths) || !$paths) collection_fail('Nothing was chosen.');
        $reg = collections_registry($dir, $files);
        $removed = 0; $covered = 0;
        $want = [];
        foreach ($paths as $f) { if (!is_string($f)) continue; $d = dirname($f); $fid = collection_folder_id($dir, ($d === '.' || $d === '') ? '' : $d); if ($fid !== '') $want['p:' . $fid . '/' . basename($f)] = true; }
        collections_update(function (&$d) use ($id, $want, &$removed) {
            foreach ($d['collections'] as &$r) {
                if (!is_array($r) || ($r['id'] ?? '') !== $id) continue;
                $keep = [];
                foreach ((array) ($r['items'] ?? []) as $it) {
                    $k = is_array($it) && isset($it['file']) ? 'p:' . ($it['fid'] ?? '') . '/' . $it['file'] : '';
                    if ($k !== '' && isset($want[$k])) { $removed++; continue; }
                    $keep[] = $it;
                }
                $r['items'] = $keep;
            }
        });
        collection_saved_or_fail();
        $covered = max(0, count($want) - $removed);
        admin_json_exit(['ok' => true, 'removed' => $removed, 'covered' => $covered, 'text' => ($removed ? 'Taken out of the collection (nothing was deleted).' : 'Nothing was taken out.') . ($covered ? ' ' . $covered . ' of them come with a whole folder in it: take that folder out, or change it to "Only its own photos", to remove them.' : '')]);
    }
    if ($action === 'collection_member_mode') {
        $fid = (string) ($_POST['fid'] ?? ''); $mode = (string) ($_POST['mode'] ?? '') === 'own' ? 'own' : 'all';
        collections_update(function (&$d) use ($id, $fid, $mode) {
            foreach ($d['collections'] as &$r) {
                if (!is_array($r) || ($r['id'] ?? '') !== $id) continue;
                foreach ($r['items'] as &$it) if (is_array($it) && !isset($it['file']) && ($it['fid'] ?? '') === $fid) $it['mode'] = $mode;
            }
        });
        collection_saved_or_fail();
        admin_json_exit(['ok' => true, 'text' => $mode === 'own' ? 'Only that folder\'s own photos are in it.' : 'Everything inside that folder is in it.']);
    }
    collection_fail('Unknown collection action.');
}

/* ---- what the owner sees ------------------------------------------------ */

/** The address of a collection as someone types it: the gallery's own address and the path (the tidy form where the server has the rule). */
function collection_address_text($path) {
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $base . '/' . $path;
}

/** One line per member of a collection, with what the owner needs to see: where it is, whether it is public, how much of it is there, or that it is
 *  missing. key is what a remove names. */
function collection_member_rows($dir, $rec, $files) {
    $reg = collections_registry($dir, $files);
    $rows = [];
    foreach ($rec['items'] as $it) {
        $path = $reg['idPaths'][$it['fid']] ?? null;
        if (isset($it['file'])) {
            $key = 'p:' . $it['fid'] . '/' . $it['file'];
            $file = $path === null ? null : ($path === '' ? $it['file'] : $path . '/' . $it['file']);
            if ($file === null || !in_array($file, $files, true)) { $rows[] = ['key' => $key, 'kind' => 'photo', 'label' => $it['file'], 'where' => $path ?? '', 'state' => 'missing', 'count' => 0, 'mode' => '', 'thumb' => null]; continue; }
            $state = file_is_unlisted($dir, $file) ? 'unlisted' : (file_is_hidden($dir, $file, items_get_all_for_files($dir, [$file])) ? 'private' : 'public');
            $rows[] = ['key' => $key, 'kind' => 'photo', 'label' => $it['file'], 'where' => $path, 'path' => $file, 'state' => $state, 'count' => 1, 'mode' => '', 'thumb' => img_url($file, 'thumb')];
            continue;
        }
        $key = 'f:' . $it['fid'];
        if ($path === null || $path === '' || empty(folder_files_under($files, $path))) { $rows[] = ['key' => $key, 'kind' => 'folder', 'fid' => $it['fid'], 'label' => $path === null ? 'a folder that is gone' : $path, 'where' => '', 'state' => 'missing', 'count' => 0, 'mode' => $it['mode'], 'thumb' => null]; continue; }
        $own = $it['mode'] === 'own';
        $n = 0; foreach ($files as $f) { if (strncmp($f, $path . '/', strlen($path) + 1) !== 0) continue; if ($own && dirname($f) !== $path) continue; $n++; }
        $state = folder_is_unlisted($dir, $path) ? 'unlisted' : (folder_is_private($dir, $path) || folder_above_is_private($dir, $path) ? 'private' : 'public');
        $rows[] = ['key' => $key, 'kind' => 'folder', 'fid' => $it['fid'], 'label' => $path, 'where' => '', 'path' => $path, 'state' => $state, 'count' => $n, 'mode' => $it['mode'], 'thumb' => null];
    }
    return $rows;
}

function collection_pill($state) {
    $t = ['public' => ['', 'Public'], 'private' => [' priv', 'Private'], 'unlisted' => [' priv', 'Not listed'], 'missing' => [' miss', 'Not found']][$state] ?? ['', ''];
    return '<span class="cm-pill' . $t[0] . '">' . $t[1] . '</span>';
}

/** The "Collections" section of a folder's (or the front page's) settings: the collections in it, with a way into each and to delete it, and a box to make one
 *  from just a name. $parentPath is the folder (null or '' for the front page). */
function render_admin_collections_section($dir, $files, $parentPath, $csrfToken) {
    $parent = (string) $parentPath;
    $reg = collections_registry($dir, $files);
    $here = $reg['byParent'][$parent] ?? [];
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
?>
    <h3 class="subhead">Collections</h3>
    <div class="cm-section" data-csrf="<?= $csrfToken ?>" data-parent="<?= $esc($parent) ?>">
    <p class="hint" style="margin:0 0 6px"><?= $parent === '' ? 'A collection is a hand-picked set that shows up on the front page as a card of its own.' : 'A collection is a hand-picked set that shows up in this folder as a card of its own.' ?> What is in it stays where it is on disk.</p>
    <?php if ($here): ?>
    <ul class="cm-list">
      <?php foreach ($here as $r): $rows = collection_member_rows($dir, $r, $files); $nf = count(array_filter($rows, fn ($x) => $x['kind'] === 'folder')); $np = count($rows) - $nf; $href = '?admin&amp;folder=' . $esc(rawurlencode($r['path'])); ?>
      <li class="cm-row" data-id="<?= $esc($r['id']) ?>">
        <div class="cm-main"><a class="coll" href="<?= $href ?>"><?= $esc($r['title'] !== '' ? $r['title'] : $r['name']) ?></a><?= $r['private'] ? ' ' . collection_pill('private') : '' ?>
          <div class="cm-sub"><?= $nf ?> folder<?= $nf === 1 ? '' : 's' ?>, <?= $np ?> photo<?= $np === 1 ? '' : 's' ?> &middot; <?= $esc(collection_address_text($r['path'])) ?></div></div>
        <div class="cm-acts"><a class="secondary btn-link" href="<?= $href ?>">Open settings</a><button type="button" class="secondary cm-del" data-id="<?= $esc($r['id']) ?>" data-title="<?= $esc($r['title'] !== '' ? $r['title'] : $r['name']) ?>">Delete collection</button></div>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <form class="cm-new" method="post" onsubmit="return false;">
      <input type="text" name="title" maxlength="80" placeholder="Name of the new collection" aria-label="Name of the new collection">
      <button type="submit" class="primary">New collection</button>
      <span class="rowstate" role="status" aria-live="polite"></span>
    </form>
    <p class="hint">You only choose a name now; everything else is on the next page. Deleting a collection removes only the collection: nothing in it is touched on disk.</p>
    </div>
<?php
}

/** A collection's own settings page: the folder settings form (with the address after the name), the three switches, the order of its photos, and Members. */
function render_admin_collection_panel($dir, $files, $rec, $csrfToken) {
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $items = collection_as_items($rec);
    $path = $rec['path'];
    $rows = collection_member_rows($dir, $rec, $files);
    $hidden = ['folder' => 0, 'photo' => 0];
    foreach ($rows as $m) if (in_array($m['state'], ['private', 'unlisted'], true)) $hidden[$m['kind']]++;
    $onFront = $rec['parentPath'] === '';
?>
  <section class="panel" id="settings" data-collection="<?= $esc($rec['id']) ?>">
    <h2 class="section-title">This collection's own settings</h2>
    <?php render_admin_identity_form($items, $csrfToken, $path, null, 'main', $rec); ?>
    <div class="folder-switches coll-switches" data-private="<?= $rec['private'] ? '1' : '0' ?>">
      <?php if (!$onFront) render_admin_instant_toggle($csrfToken, 'toggle_collection_flag', 'front', $rec['front'] && !$rec['private'], 'Show on front page', '', '', 'unlisted-form coll-front' . ($rec['private'] ? ' is-off' : '')); ?>
      <?php render_admin_instant_toggle($csrfToken, 'toggle_collection_flag', 'owngallery', $rec['ownGallery'], 'Gallery of its own', '', ' <small class="muted">(limits navigation)</small>', 'unlisted-form'); ?>
      <?php render_admin_instant_toggle($csrfToken, 'toggle_collection_flag', 'private', $rec['private'], 'Private collection', '', '', 'unlisted-form coll-private'); ?>
    </div>
    <?php render_admin_identity_form($items, $csrfToken, $path, null, 'sort', $rec); ?>
    <h3 class="subhead">Members</h3>
    <?php
      $folderOpts = [];
      foreach (folder_tree_rows($dir, all_folder_paths($files)) as $fr) $folderOpts[] = ['p' => $fr['p'], 'n' => $fr['n'], 'd' => $fr['d'], 'x' => folder_is_private($dir, $fr['p']) || folder_above_is_private($dir, $fr['p']), 'u' => folder_is_unlisted($dir, $fr['p'])];
      $memberFolders = []; $memberPhotos = [];
      foreach ($rows as $m) { if ($m['state'] === 'missing') continue; if ($m['kind'] === 'folder') $memberFolders[] = $m['path']; else $memberPhotos[] = $m['path']; }
    ?>
    <div class="cm-members" data-csrf="<?= $csrfToken ?>" data-id="<?= $esc($rec['id']) ?>" data-folder="<?= $esc($path) ?>" data-title="<?= $esc($rec['title'] !== '' ? $rec['title'] : $rec['name']) ?>" data-folders="<?= $esc(json_encode($folderOpts, JSON_HEX_TAG | JSON_HEX_AMP)) ?>" data-have="<?= $esc(json_encode(['folders' => $memberFolders, 'photos' => $memberPhotos], JSON_HEX_TAG | JSON_HEX_AMP)) ?>">
    <p class="hint" style="margin:0 0 6px">The folders and photos shown in this collection. They stay where they are on disk: removing one here only takes it out of the collection.</p>
    <ul class="cm-list" id="cm-members">
      <?php foreach ($rows as $m): ?>
      <li class="cm-row" data-key="<?= $esc($m['key']) ?>"<?= isset($m['fid']) ? ' data-fid="' . $esc($m['fid']) . '"' : '' ?>>
        <span class="cm-kind"><?= $m['kind'] === 'folder' ? 'Folder' : 'Photo' ?></span>
        <?php if ($m['thumb'] !== null): ?><img class="cm-th" src="<?= $esc($m['thumb']) ?>" alt="" loading="lazy"><?php endif; ?>
        <div class="cm-main">
          <?php if ($m['state'] === 'missing'): ?><span class="coll"><?= $esc($m['label']) ?></span><?php else: ?><a href="<?= $m['kind'] === 'folder' ? '?folder=' . $esc(rawurlencode($m['path'])) : '?folder=' . $esc(rawurlencode($m['where'])) ?>"><?= $esc($m['label']) ?></a><?php endif; ?>
          <?= collection_pill($m['state']) ?>
          <div class="cm-sub"><?php
            if ($m['state'] === 'missing') echo $m['kind'] === 'photo' ? 'It was renamed or moved. Remove it, or put it back.' : 'The folder is gone, or has no photos. Remove it, or put it back.';
            elseif ($m['kind'] === 'photo') echo $m['where'] === '' ? 'at the top of the gallery' : 'in ' . $esc($m['where']);
            elseif (in_array($m['state'], ['private', 'unlisted'], true)) echo 'Visitors will not see this one in the collection until it is public.';
            else echo number_format($m['count']) . ' photo' . ($m['count'] === 1 ? '' : 's');
          ?></div>
        </div>
        <?php if ($m['kind'] === 'folder' && $m['state'] !== 'missing'): ?>
        <span class="cm-seg" role="radiogroup" aria-label="What is in it">
          <label><input type="radio" name="cm-mode-<?= $esc($m['fid']) ?>" value="all"<?= $m['mode'] === 'all' ? ' checked' : '' ?>><span>Everything inside</span></label><label><input type="radio" name="cm-mode-<?= $esc($m['fid']) ?>" value="own"<?= $m['mode'] === 'own' ? ' checked' : '' ?>><span>Only its own photos</span></label>
        </span>
        <?php endif; ?>
        <button type="button" class="secondary cm-rm">Remove</button>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php if ($hidden['folder'] + $hidden['photo'] > 0): ?>
    <div class="warn-note"><?= $hidden['folder'] ? $hidden['folder'] . ' folder' . ($hidden['folder'] === 1 ? '' : 's') : '' ?><?= $hidden['folder'] && $hidden['photo'] ? ' and ' : '' ?><?= $hidden['photo'] ? $hidden['photo'] . ' photo' . ($hidden['photo'] === 1 ? '' : 's') : '' ?> here <?= $hidden['folder'] + $hidden['photo'] === 1 ? 'is' : 'are' ?> private. Visitors will not see <?= $hidden['folder'] + $hidden['photo'] === 1 ? 'it' : 'them' ?> in this collection.</div>
    <?php endif; ?>
    <p class="cm-addrow"><button type="button" class="secondary cm-add">Add folders or photos&hellip;</button></p>
    <div id="cm-picker" hidden></div>
    </div>
  </section>
<?php
}

/** The "Collections" tab: every collection with what is known about it from the top. */
function render_admin_collections_overview($dir, $files, $csrfToken) {
    $reg = collections_registry($dir, $files);
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $links = [];
    foreach ((array) (links_read()['links'] ?? []) as $l) if (is_array($l) && isset($l['id'], $l['label']) && !link_is_public_log($l)) $links[] = $l;
?>
  <section class="panel" id="collections" data-csrf="<?= $csrfToken ?>">
    <h2 class="section-title">Collections</h2>
    <p class="hint" style="margin:0 0 6px">Hand-picked sets that show up as cards. Everything here is a view of folders and photos that stay where they are. Make a new one from a folder&rsquo;s own settings.</p>
    <?php foreach (array_merge(array_values($reg['byPath']), $reg['taken']) as $r):
        $rows = collection_member_rows($dir, $r, $files); $nf = count(array_filter($rows, fn ($x) => $x['kind'] === 'folder')); $np = count($rows) - $nf;
        $miss = count(array_filter($rows, fn ($x) => $x['state'] === 'missing')); $priv = count(array_filter($rows, fn ($x) => in_array($x['state'], ['private', 'unlisted'], true)));
        $taken = isset($reg['byPath'][$r['path']]) ? false : true;
        $href = '?admin&amp;folder=' . $esc(rawurlencode($r['path']));
        $using = [];
        foreach ($links as $l) if (collection_link_covers($l, $r, $reg)) $using[] = $l;
    ?>
    <div class="cm-card">
      <h4><a class="coll" href="<?= $href ?>"><?= $esc($r['title'] !== '' ? $r['title'] : $r['name']) ?></a><?= $r['private'] ? ' ' . collection_pill('private') : '' ?><span class="cm-acts"><a class="secondary btn-link" href="<?= $href ?>">Open settings</a><?php if (!$taken): ?><a class="secondary btn-link" href="?folder=<?= $esc(rawurlencode($r['path'])) ?>">View</a><?php endif; ?></span></h4>
      <dl class="cm-dl">
        <dt>Lives in</dt><dd><?= $r['parentPath'] === '' ? 'the front page' : $esc($r['parentPath']) ?></dd>
        <dt>Address</dt><dd><?= $esc(collection_address_text($r['path'])) ?><?= $taken ? ' <span class="cm-pill miss">Taken by a folder</span>' : '' ?></dd>
        <dt>On the front page</dt><dd><?= $r['parentPath'] === '' ? 'Yes (it lives there)' : ($r['front'] ? 'Yes' : 'No') ?></dd>
        <dt>Holds</dt><dd><?= $nf ?> folder<?= $nf === 1 ? '' : 's' ?>, <?= $np ?> photo<?= $np === 1 ? '' : 's' ?></dd>
        <dt>Problems</dt><dd><?php if ($miss || $priv): ?><?= $miss ? '<span class="cm-pill miss">' . $miss . ' not found</span> ' : '' ?><?= $priv ? '<span class="cm-pill priv">' . $priv . ' private</span>' : '' ?><?php else: ?>Nothing missing<?php endif; ?></dd>
        <dt>Share links</dt><dd><?php if ($using): foreach ($using as $i => $l): ?><?= $i ? ', ' : '' ?><a href="#links" data-tab="links"><?= $esc($l['label']) ?></a><?php endforeach; else: ?>None<?php endif; ?></dd>
        <dt>Gallery of its own</dt><dd><?= $r['ownGallery'] ? 'On' : 'Off' ?></dd>
      </dl>
    </div>
    <?php endforeach; ?>
    <?php foreach ($reg['lost'] as $r): ?>
    <div class="cm-card"><h4><span class="coll"><?= $esc($r['title'] !== '' ? $r['title'] : $r['name']) ?></span> <span class="cm-pill miss">Its folder is missing</span></h4>
      <p class="hint">The folder this collection lived in is gone, so it is not shown. Put the folder back and it returns; or delete the collection.</p>
      <p><button type="button" class="secondary cm-del" data-id="<?= $esc($r['id']) ?>" data-title="<?= $esc($r['title'] !== '' ? $r['title'] : $r['name']) ?>">Delete collection</button></p></div>
    <?php endforeach; ?>
  </section>
<?php
}

/** Does a share link cover this collection? (A link that picks the collection, 'Everything', or a folder that holds all of it: the Shares part is built after the picker.) */
function collection_link_covers($l, $rec, $reg) {
    return in_array($rec['id'], (array) ($l['scope']['folders'] ?? []), true);   // (a link names a collection the way it names a folder)
}
