/* -----------------------------------------------------------------------
 * Collections. A collection is a folder that exists only in the gallery: it has no place on disk. It lives inside a parent (the front
 * page or any folder) and holds members: real folders and single photos from anywhere. Nothing moves on disk, and a collection never
 * takes anything away from the place a member really is. It grants nothing and hides nothing: a visitor sees, in a collection, only the
 * members they could already see where they really are.
 *
 * The records are in .gallery-collections.php beside index.php (a guarded data file, like the share links):
 *   {collections: [{id, parent, name, title, description, cover, dates, position, private, front, ownGallery, subfolderStyle, photoSort,
 *                   items: [{fid, mode: all|own} | {fid, file}], at}]}
 * A folder is remembered by its hidden id (folder_id_stamp), as share links do, so renaming or moving a folder keeps the collection whole;
 * `parent` is such an id too ('' is the front page). The address is the parent's path plus `name`, like a folder's: ?folder=<parent>/<name>.
 * A real folder with the same path always wins (the collection is then "address taken" and is not shown).
 * ----------------------------------------------------------------------- */

function collections_path() { return __DIR__ . '/.gallery-collections.php'; }
function collections_default() { return ['collections' => []]; }
function collections_read() { return json_file_read(collections_path(), collections_default()); }
function collections_update($modifier) { collections_cache_reset(); $r = json_file_update(collections_path(), collections_default(), $modifier, true); collections_cache_reset(); return $r; }
/** Forget what was worked out this request (after a change, and between the parts of a test). */
function collections_cache_reset() { $GLOBALS['__collections_cache'] = null; $GLOBALS['__collections_registry'] = null; }

/** Is a collection's address name well formed? Letters, digits, dot, dash, underscore; not starting with a dot; at most 60 characters;
 *  and not a word the gallery uses as an address itself. */
function collection_name_shape_ok($name) {
    return is_string($name) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,59}$/D', $name) === 1 && !in_array(strtolower($name), ['admin', 'reveal', 'link', 'photo', 'index.php'], true);
}

/** An address name made from a title: lower case, runs of other characters become one dash. */
function collection_slug($title) {
    $s = strtolower(trim((string) $title));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim((string) $s, '-');
    if ($s === '') $s = 'collection';
    return substr($s, 0, 60);
}

/** One stored record, made safe to use (every field the right type), or null when it is not a collection at all. */
function collection_clean($r) {
    if (!is_array($r) || !is_string($r['id'] ?? null) || !preg_match('/^c[0-9a-f]{12}$/D', $r['id']) || !collection_name_shape_ok($r['name'] ?? null)) return null;
    $parent = $r['parent'] ?? '';
    if (!is_string($parent) || ($parent !== '' && !preg_match('/^[0-9a-f]{12}$/D', $parent))) return null;
    $items = [];
    foreach ((array) ($r['items'] ?? []) as $it) {
        if (!is_array($it) || !is_string($it['fid'] ?? null) || !preg_match('/^[0-9a-f]{12}$/D', $it['fid'])) continue;
        if (isset($it['file'])) {
            if (is_string($it['file']) && $it['file'] !== '' && strpos($it['file'], '/') === false && strpos($it['file'], "\0") === false && strlen($it['file']) <= 255) $items[] = ['fid' => $it['fid'], 'file' => $it['file']];
        } else {
            $items[] = ['fid' => $it['fid'], 'mode' => ($it['mode'] ?? 'all') === 'own' ? 'own' : 'all'];
        }
    }
    $style = $r['subfolderStyle'] ?? null;
    return [
        'id' => $r['id'], 'parent' => $parent, 'name' => $r['name'],
        'title' => is_string($r['title'] ?? null) ? $r['title'] : '',
        'description' => is_string($r['description'] ?? null) ? $r['description'] : '',
        'cover' => is_string($r['cover'] ?? null) && $r['cover'] !== '' ? $r['cover'] : null,
        'dates' => is_string($r['dates'] ?? null) ? $r['dates'] : '',
        'position' => is_int($r['position'] ?? null) ? $r['position'] : null,
        'private' => !empty($r['private']), 'front' => !empty($r['front']), 'ownGallery' => !empty($r['ownGallery']),
        'subfolderStyle' => $style === 'flat' || $style === 'overview' ? $style : null,
        'flatNames' => is_bool($r['flatNames'] ?? null) ? $r['flatNames'] : null, 'flatNotes' => is_bool($r['flatNotes'] ?? null) ? $r['flatNotes'] : null,
        'photoSort' => is_array($r['photoSort'] ?? null) ? $r['photoSort'] : [],
        'items' => $items, 'at' => is_int($r['at'] ?? null) ? $r['at'] : 0,
    ];
}

/** Every well-formed record, in the order stored. [] when there is no file, or it can't be read. */
function collections_list() {
    if (isset($GLOBALS['__collections_cache']) && is_array($GLOBALS['__collections_cache'])) return $GLOBALS['__collections_cache'];
    $out = [];
    if (is_file(collections_path())) {
        [$state, $data] = json_file_load(collections_path(), collections_default());
        if ($state === 'ok') foreach ((array) ($data['collections'] ?? []) as $r) if (($c = collection_clean($r)) !== null) $out[] = $c;
    }
    return $GLOBALS['__collections_cache'] = $out;
}

/** Has the gallery any collections at all (the cheap question the rest of the code asks first)? */
function collections_any() { return is_file(collections_path()) && collections_list() !== []; }

/** The collections, with where they are: ['byPath' => full path => record, 'byParent' => parent path => [records], 'idPaths' => folder id => its path, 'taken' => the
 *  records whose address a real folder holds]. Each record gains 'path' and 'parentPath'. A record whose parent folder can't be found is left out of
 *  byPath/byParent (and is in 'lost'); so is one whose address a real folder has. $files is the complete list of the gallery's files (not the one
 *  filtered for a visitor): a folder is found by its id through the folders that hold files. */
function collections_registry($dir, $files = null) {
    if ($files === null) $files = $GLOBALS['allFiles'] ?? ($GLOBALS['files'] ?? []);
    $key = count($files) . '|' . (string) ($files[0] ?? '') . '|' . (string) ($files[count($files) - 1] ?? '');
    $cached = $GLOBALS['__collections_registry'] ?? null;
    if (is_array($cached) && $cached['key'] === $key && $files !== []) return $cached['reg'];
    $reg = ['byPath' => [], 'byParent' => [], 'idPaths' => [], 'taken' => [], 'lost' => []];
    $list = collections_list();
    if ($list) {
        foreach (folder_id_map_folders($dir, $files) as $id => $paths) if (count($paths) === 1) $reg['idPaths'][$id] = $paths[0];   // (an id that more than one folder carries points at nothing until it is sorted out)
        $real = array_flip(all_folder_paths($files));
        foreach (array_keys(gallery_mounts()) as $m) $real[$m] = true;
        foreach ($list as $r) {
            $parentPath = $r['parent'] === '' ? '' : ($reg['idPaths'][$r['parent']] ?? null);
            if ($parentPath === null) { $reg['lost'][] = $r; continue; }
            $path = $parentPath === '' ? $r['name'] : $parentPath . '/' . $r['name'];
            $r['path'] = $path; $r['parentPath'] = $parentPath;
            if (isset($real[$path])) { $reg['taken'][] = $r; continue; }
            if (isset($reg['byPath'][$path])) continue;   // (the first one stored keeps an address two share)
            $reg['byPath'][$path] = $r; $reg['byParent'][$parentPath][] = $r;
        }
    }
    if ($files !== []) $GLOBALS['__collections_registry'] = ['key' => $key, 'reg' => $reg];
    return $reg;
}

/** The collection at this full path, or null. */
function collection_at($dir, $path, $files = null) {
    if (!is_string($path) || $path === '' || !collections_any()) return null;
    return collections_registry($dir, $files)['byPath'][$path] ?? null;
}

/** May this visitor see this collection at all (its own card and page)? A private one is for the owner, the people who have the private-view
 *  password, and people on a share link that names it or is for everything; whoever may not sees nothing of it, not even that it is there. A link that
 *  limits what is seen shows only the collections it names. */
function collection_viewer_sees($rec) {
    if (is_revealed()) return true;
    $l = link_viewer();
    if ($l !== null) {
        $type = $l['scope']['type'] ?? 'all';
        if (in_array($type, ['folders', 'photos', 'mixed'], true)) return in_array($rec['id'], (array) ($l['scope']['folders'] ?? []), true);   // (a link that limits what is seen shows only the collections it names)
        if ($type === 'all') return true;   // (everything, public and private)
    }
    return empty($rec['private']);
}

/** Does the collection with this id hold this file (as it holds it: folders everything-inside or own-photos, single photos)? Worked out once per collection. */
function collection_holds_file($dir, $collectionId, $file) {
    static $sets = [];
    if (!isset($sets[$collectionId])) {
        $rec = collection_find_by_id($collectionId);
        $sets[$collectionId] = $rec === null ? [] : array_fill_keys(collection_member_files($dir, $rec, $GLOBALS['allFiles'] ?? ($GLOBALS['files'] ?? [])), true);
    }
    return isset($sets[$collectionId][$file]);
}

/** The files a collection holds, in the order of its members (a folder's files in the folder's order, a single photo where it is listed), not
 *  yet narrowed to what a visitor may see: that is done by the caller from the visitor's own list. $files is the complete list. */
function collection_member_files($dir, $rec, $files) {
    $reg = collections_registry($dir, $files);
    $by = []; $folderOf = [];
    foreach ($files as $f) { $by[$f] = true; $d = dirname($f); $folderOf[$f] = ($d === '.' || $d === '') ? '' : $d; }
    $out = []; $seen = [];
    foreach ($rec['items'] as $it) {
        $path = $reg['idPaths'][$it['fid']] ?? null;
        if ($path === null) continue;   // (the folder is gone, or its id is shared: the member is missing, and shows nowhere)
        if (isset($it['file'])) {
            $f = $path === '' ? $it['file'] : $path . '/' . $it['file'];
            if (isset($by[$f]) && !isset($seen[$f])) { $seen[$f] = true; $out[] = $f; }
            continue;
        }
        $prefix = $path === '' ? '' : $path . '/';
        foreach ($files as $f) {
            if ($prefix !== '' && strncmp($f, $prefix, strlen($prefix)) !== 0) continue;
            if (($it['mode'] ?? 'all') === 'own' && $folderOf[$f] !== $path) continue;
            if (!isset($seen[$f])) { $seen[$f] = true; $out[] = $f; }
        }
    }
    return $out;
}

/** What a folder's own record says about itself, for a collection: the same shape folder_display_meta gives, so a card, a page title and the
 *  order among siblings treat a collection like a folder. */
function collection_display_meta($rec) {
    return ['label' => $rec['title'], 'cover' => $rec['cover'], 'description' => $rec['description'], 'dates' => $rec['dates'], 'sortOrder' => $rec['position']];
}

/** The collections shown as cards on a page: those in this parent ('' is the front page), plus, on the front page, those from any other parent
 *  that are marked to show there. Each card is [key, record, files]: the key is the card's name on the page (the address name for a collection in
 *  this parent, its full path for one shown elsewhere), the files are the members the visitor may see ($visible is that visitor's list, and $all the complete one).
 *  A collection with nothing the visitor may see, or a private one for a visitor who may not, is left out. */
function collection_cards($dir, $parentPath, $all, $visible) {
    if (!collections_any()) return [];
    $reg = collections_registry($dir, $all);
    $vis = array_flip($visible);
    $recs = $reg['byParent'][$parentPath] ?? [];
    $fromElsewhere = [];
    if ($parentPath === '') foreach ($reg['byPath'] as $path => $r) if ($r['front'] && $r['parentPath'] !== '') $fromElsewhere[$path] = $r;
    $out = [];
    foreach ($recs as $r) {
        if (!collection_viewer_sees($r)) continue;
        $files = array_values(array_filter(collection_member_files($dir, $r, $all), function ($f) use ($vis) { return isset($vis[$f]); }));
        if ($files) $out[] = [$r['name'], $r, $files];
    }
    foreach ($fromElsewhere as $path => $r) {
        if (!collection_viewer_sees($r)) continue;
        $files = array_values(array_filter(collection_member_files($dir, $r, $all), function ($f) use ($vis) { return isset($vis[$f]); }));
        if ($files) $out[] = [$path, $r, $files];
    }
    return $out;
}

/** What the page for a collection address needs: ['rec' => the record, 'files' => the members this visitor may see, 'folders' => [member folder path =>
 *  its files], 'loose' => [single photos]], or null when there is no such collection for this visitor (the answer is then the same missing page as any
 *  other address that leads nowhere). $all is the complete list of files, $visible this visitor's. */
function collection_page($dir, $path, $all, $visible) {
    $rec = collection_at($dir, $path);
    if ($rec === null || !collection_viewer_sees($rec)) return null;
    $vis = array_flip($visible);
    $reg = collections_registry($dir, $all);
    if ($rec['parentPath'] !== '' && !array_filter($visible, function ($f) use ($rec) { return strncmp($f, $rec['parentPath'] . '/', strlen($rec['parentPath']) + 1) === 0; })) return null;   // (the folder it is in shows this visitor nothing: nor does it)
    $folders = []; $loose = []; $files = [];
    foreach ($rec['items'] as $it) {
        $p = $reg['idPaths'][$it['fid']] ?? null;
        if ($p === null) continue;
        if (isset($it['file'])) {
            $f = $p === '' ? $it['file'] : $p . '/' . $it['file'];
            if (isset($vis[$f]) && !isset($files[$f])) { $files[$f] = true; $loose[] = $f; }
            continue;
        }
        if ($p === '') continue;   // (the gallery's own top is not a member)
        $mine = collection_member_files($dir, ['items' => [$it]], $all);
        $mine = array_values(array_filter($mine, function ($f) use ($vis) { return isset($vis[$f]); }));
        if (!$mine) continue;
        $folders[$p] = isset($folders[$p]) ? array_values(array_unique(array_merge($folders[$p], $mine))) : $mine;
        foreach ($mine as $f) $files[$f] = true;
    }
    if (!$files) return null;
    return ['rec' => $rec, 'files' => array_keys($files), 'folders' => $folders, 'loose' => $loose];
}
