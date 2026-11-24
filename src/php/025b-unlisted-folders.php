/* -----------------------------------------------------------------------
 * Unlisted folders (what the owner is shown as "Unlisted"; kept as `quiet: true` in the folder's own settings, so that it is never mixed up with the
 * older `unlisted` flag, which is what the owner is shown as "Only me").
 *
 * An Unlisted folder is PUBLIC: anyone who types its address can open it, with no sign-in. What makes it unlisted is that it is left out of everything
 * that lists things for a visitor: the gallery's front page and the folders around it (cards, pills, counts, the map, notes, the ZIP of the folder
 * above, collections, link previews, the "last updated" date) and search engines are asked to skip it. It covers the folder and everything beneath
 * it. The owner sees all of it, with a pill that says so.
 *
 * The one place that takes it out of the lists is the filter that already decides what a visitor's page is made of (see GROUPING in
 * 009-notes-grouping-and-order.php): $groups and $files are filtered there, so every count, list and allowlist after it agrees. Pages that are
 * MEANT to reach it keep its photos in that list: a page inside the folder (?folder=<it or a folder in it>), and a share link that names it.
 * Nothing here asks "is anything Unlisted?" first, on purpose: a folder moved in from another gallery, restored from a backup or edited by hand
 * carries the setting without any note anywhere else, and must be left out all the same. (The one thing that does use a note is the tidiness of "Gallery of
 * its own", which an Unlisted folder has by default: the gallery's own settings file says whether any folder has it, so a gallery that has none reads nothing
 * and sets no cookie. Such a folder keeps its way back until its owner presses a button for it once; it is still left out of every list.)
 * ----------------------------------------------------------------------- */

/** One folder's own Unlisted setting, read once per request (and reset with the other folder settings when one is saved). */
function folder_quiet_cached($dir, $folderRel, $reset = false) {
    static $cache = [];
    if ($reset) { $cache = []; return null; }
    $folderRel = (string) $folderRel;
    if (!isset($cache[$folderRel])) {
        $cache[$folderRel] = ($folderRel === '' ? false : (folder_items_memo($dir, $folderRel)['quiet'] ?? null) === true);   // (the gallery's own folder cannot be Unlisted: it is the gallery)
    }
    return $cache[$folderRel];
}

/** How many path segments of $file's folders it takes to reach the NEAREST Unlisted folder above it (1 for a top-level folder), or 0 when none is. The nearest, not
 *  the topmost: an Unlisted folder inside an Unlisted folder stays out of its parent's page too (the page of the folder above does not list it), and a share link
 *  that names the folder above does not bring it along. A folder inside an Unlisted folder that is not marked itself just follows it. */
function quiet_root_depth($dir, $file) {
    $found = 0;
    if (AUTO_GROUP_BY_FOLDER && strpos($file, '/') !== false) {
        $path = ''; $depth = 0;
        foreach (explode('/', dirname($file)) as $segment) {
            $path = $path === '' ? $segment : $path . '/' . $segment;
            $depth++;
            if (folder_quiet_cached($dir, $path)) $found = $depth;
        }
    }
    return $found;
}

/** The path of the nearest Unlisted folder $file sits in, or '' when it is in none. */
function quiet_root_path($dir, $file) {
    $depth = quiet_root_depth($dir, $file);
    return $depth > 0 ? implode('/', array_slice(explode('/', dirname($file)), 0, $depth)) : '';
}

/** Is the item in an Unlisted folder? */
function file_is_quiet($dir, $file) {
    return quiet_root_depth($dir, $file) > 0;
}

/** Is this folder Unlisted, on its own or because a folder above it is? */
function folder_is_quiet($dir, $folderPath) {
    return folder_quiet_cached($dir, $folderPath) || quiet_root_depth($dir, $folderPath . '/x') > 0;
}

/** Is the page being asked for inside the Unlisted folder $root (the folder itself, or one in it)? */
function quiet_request_inside($root) {
    $asked = $_GET['folder'] ?? null;
    if (!is_string($asked) || $asked === '' || $root === '') return false;
    return $asked === $root || strncmp($asked, $root . '/', strlen($root) + 1) === 0;
}

/** The level of "Who can see this folder" a folder's own settings add up to: 'me' (private with the Only me flag), 'private', 'unlisted', or 'public'. Private wins over
 *  Unlisted: a folder that is both (a hand-edited file) is private. */
function folder_visibility_level($data) {
    if (item_restriction($data) === 'private') return ($data['unlisted'] ?? null) === true ? 'me' : 'private';
    return ($data['quiet'] ?? null) === true ? 'unlisted' : 'public';
}

/** Which folder a folder's visibility follows, when the folders above it are stricter than its own setting: ['level', 'path', 'label'] or null. A folder cannot be more open
 *  than the one it sits in (private at any depth hides everything beneath it, and an Unlisted folder's folders follow it), so a folder with no setting of its own (Public)
 *  inside a private folder is, in fact, private. The strictest level above wins (Only me, then Private, then Unlisted); of several folders at that level it is the topmost,
 *  the one that was actually set. This is what the folder's settings tell the owner, so that "Public" is never shown for a folder visitors cannot open. */
function folder_follow_info($dir, $folderPath) {
    $rank = ['public' => 0, 'unlisted' => 1, 'private' => 2, 'me' => 3];
    $own = $rank[folder_visibility_level(folder_items_memo($dir, $folderPath))];
    $best = null; $bestRank = 0; $path = '';
    $parts = explode('/', (string) $folderPath); array_pop($parts);
    foreach ($parts as $segment) {
        $path = $path === '' ? $segment : $path . '/' . $segment;
        $level = folder_visibility_level(folder_items_memo($dir, $path));
        if ($rank[$level] > $bestRank) { $bestRank = $rank[$level]; $best = ['level' => $level, 'path' => $path]; }   // (strictly stricter only: the topmost of a level stays)
    }
    if ($best === null || $bestRank <= $own) return null;
    $label = folder_display_meta($dir, $best['path'])['label'];
    $best['label'] = $label !== '' ? $label : basename($best['path']);
    return $best;
}

/** May the share link $l show this item of an Unlisted folder? Only when the link NAMES the Unlisted folder (or a folder inside it) or the photo itself:
 *  an "Everything" link and a link that names a folder above it leave it out, as they do a folder that is Only me, so a link meant for "all my photos" never
 *  carries the folder that was meant to stay out of sight. A collection the link names does not bring it along either (a collection is a list). */
function link_allows_quiet_item($l, $dir, $file, $itemsData = null) {
    $type = $l['scope']['type'] ?? '';
    $from = max(0, quiet_root_depth($dir, $file) - 1);
    if ($type === 'folders' || $type === 'mixed') {
        $cover = link_folder_cover($dir, $file, $l['scope']['folders'] ?? [], $l['scope']['publicOnly'] ?? [], $l['scope']['except'] ?? [], $from);
        if ($cover === 'all' || $cover === 'public') return true;   // (an Unlisted folder is public, so "public items only" covers it too)
        if ($type === 'folders') return false;
    }
    if ($type === 'photos' || $type === 'mixed') {
        $set = [];
        foreach ((array) ($l['scope']['photos'] ?? []) as $p) if (is_array($p) && is_string($p['fid'] ?? null) && is_string($p['name'] ?? null)) $set[$p['fid'] . "\0" . $p['name']] = true;
        if (!$set) return false;
        $slash = strrpos($file, '/');
        $fid = folder_id_cached($dir, $slash === false ? '' : substr($file, 0, $slash));
        return $fid !== '' && !isset(link_ambiguous_set()[$fid]) && isset($set[$fid . "\0" . basename($file)]);
    }
    return false;
}

/** May this file be the preview picture of the link $l (or of the form's unsaved choice $draft: type, folders, except, photos)? A file that is in no Unlisted folder, or
 *  that the link names, may. */
function link_cover_allows_quiet($l, $dir, $file, $draft = null) {
    if (!file_is_quiet($dir, $file)) return true;
    if ($draft === null) return link_allows_quiet_item($l, $dir, $file);
    $type = (string) ($draft['type'] ?? 'all');
    if (!in_array($type, ['folders', 'photos', 'mixed'], true)) return false;
    if ($type !== 'photos') {
        $root = quiet_root_path($dir, $file);   // (the draft names folders by path: only those at the Unlisted folder or inside it count, as for a saved link)
        $within = function ($list) use ($root) { return array_values(array_filter((array) $list, function ($d) use ($root) { return is_string($d) && ($d === $root || strncmp($d, $root . '/', strlen($root) + 1) === 0); })); };
        if (link_path_in_folders($file, $within($draft['folders'] ?? []), $within($draft['except'] ?? []))) return true;
    }
    return $type !== 'folders' && in_array($file, (array) ($draft['photos'] ?? []), true);
}

/** Is this item left out of the visitor's lists because it is in an Unlisted folder? False for the owner, for a page inside the folder, and for a link
 *  that names it. This is what the page's filter asks, once for every file (see GROUPING). */
function quiet_hidden_for_visitor($dir, $file, $itemsData = null) {
    $depth = quiet_root_depth($dir, $file);
    if ($depth === 0 || is_admin_browser()) return false;
    if (request_route() === 'track') return false;   // (the viewer's own "this photo was shown" ping names only the photo, not a folder: it lists nothing, and an Unlisted photo's views must count like any other's)
    $root = implode('/', array_slice(explode('/', dirname($file)), 0, $depth));
    if (quiet_request_inside($root)) return false;
    $l = link_viewer();
    if ($l !== null && link_allows_quiet_item($l, $dir, $file, $itemsData)) return false;
    return true;
}
