/* -----------------------------------------------------------------------
 * GROUPING: discover qualifying .md files, extract {group:}/{photos:}
 * directives, and build the final $groups (auto-detected + explicit)
 * before anything below (stats, ?track, rendering) needs it.
 * ----------------------------------------------------------------------- */

// Collected as issues are found below; surfaced as an HTML comment near
// the top of the page (never anything a normal visitor would notice) so
// a typo'd filename or a missing {gallery} marker doesn't just fail
// silently with no way to ever find out why.
$galleryNotices = [];
foreach (($GLOBALS['__gallery_config_problems'] ?? []) as $configProblem) $galleryNotices[] = $configProblem;
if (!empty($subfolderMediaCount)) {
    $galleryNotices[] = ($subfolderMediaTruncated ? 'At least ' : '') . $subfolderMediaCount
        . ' photo' . ($subfolderMediaCount === 1 ? '' : 's') . '/video' . ($subfolderMediaCount === 1 ? ' was' : 's were')
        . ' found in subfolders but ' . ($subfolderMediaCount === 1 ? 'isn\'t' : 'aren\'t')
        . ' shown. Open the admin and turn on Show subfolders (Settings → Folders and photos) to include them.';
}

if (!ENABLE_VIDEO) {
    [$ignoredVideoCount, $ignoredVideoTruncated] = count_ignored_videos($dir);
    if ($ignoredVideoCount > 0) {
        $galleryNotices[] = ($ignoredVideoTruncated ? 'At least ' : '') . $ignoredVideoCount
            . ' video file' . ($ignoredVideoCount === 1 ? ' (.mov/.mp4) was' : 's (.mov/.mp4) were')
            . ' found but ' . ($ignoredVideoCount === 1 ? 'isn\'t' : 'aren\'t')
            . ' shown, because Show videos is off. Open the admin and turn it on (Settings → Videos and thumbnails) to include them.';
    }
}

if (AUTO_GROUP_BY_FOLDER && EXTRA_ROOTS && ($looseInRoots = count_loose_media_in_roots()) > 0) {
    $galleryNotices[] = $looseInRoots . ' photo' . ($looseInRoots === 1 ? ' or video lies' : 's or videos lie')
        . ' directly in a place added from elsewhere on the server. Only the folders inside such a place are shown at the top of the gallery, so move '
        . ($looseInRoots === 1 ? 'it' : 'them') . ' into a folder to include ' . ($looseInRoots === 1 ? 'it' : 'them') . '.';
}

// A place from elsewhere on the server (EXTRA_ROOTS / EXTRA_FOLDERS) that has gone missing or can't be read just shows nothing;
// say so (without naming the path: a fresh install shows these notices to whoever opens the page).
$unreadableExtra = 0;
foreach (array_merge(array_values((array) EXTRA_ROOTS), array_values((array) EXTRA_FOLDERS)) as $extraPath) {
    if (is_string($extraPath) && (!is_dir($extraPath) || !is_readable($extraPath))) $unreadableExtra++;
}
if (AUTO_GROUP_BY_FOLDER && $unreadableExtra > 0) {
    $galleryNotices[] = ($unreadableExtra === 1 ? 'A folder from elsewhere on the server is missing or can\'t be read' : $unreadableExtra . ' folders from elsewhere on the server are missing or can\'t be read')
        . ', so nothing from ' . ($unreadableExtra === 1 ? 'it is' : 'them is') . ' shown. The Health check in Settings names ' . ($unreadableExtra === 1 ? 'it' : 'them') . '.';
}

// Only a .md file whose first non-blank line is exactly "{gallery}" is
// treated as content for this script — anything else in the folder (a
// personal notes.md, a leftover README, etc.) is left completely alone.
$mdCandidates = scan_md_candidates($dir);

$mdScan = scan_gallery_md_files($dir);
$mdFiles = $mdScan['files'];
$rejectedMdCount = $mdScan['rejectedCount'];
if ($rejectedMdCount > 0) {
    // Deliberately doesn't name the file(s): unlike every other notice
    // here, this one would otherwise reveal the exact filename of a .md
    // the folder owner specifically did *not* choose to expose via the
    // gallery — and since a .md is served as a plain static file with
    // no access control tied to the {gallery} marker (that's a PHP-
    // application concept, not a permissions mechanism), naming it would
    // hand a visitor the literal URL to go read its contents directly.
    $galleryNotices[] = $rejectedMdCount === 1
        ? 'A .md file was found but ignored because its first line isn\'t the required {gallery} marker. If it\'s meant to be your gallery notes, add {gallery} as its very first line.'
        : $rejectedMdCount . ' .md files were found but ignored because none of them start with the required {gallery} marker. If one is meant to be your gallery notes, add {gallery} as its very first line.';
}

$folderName = basename($dir);

// If the first non-empty line remaining (after the marker) is a
// "# Heading", use it as the page title instead of the folder name —
// and strip it out of that file's content so it isn't shown twice.
$pageTitle = $folderName;
$mdContents = [];
foreach ($mdFiles as $idx => $mdFile) {
    $content = $mdScan['contents'][$mdFile];
    if ($idx === 0) {
        $lines = explode("\n", str_replace("\r\n", "\n", $content));
        foreach ($lines as $li => $l) {
            if (trim($l) === '') continue;
            if (preg_match('/^#\s+(.+)$/', trim($l), $m)) {
                $pageTitle = unprotect_inline_code(trim($m[1]));
                unset($lines[$li]);
                $content = implode("\n", $lines);
            }
            break; // only ever check the very first non-empty line
        }
    }
    $mdContents[$mdFile] = $content;
}
// The admin's gallery title (the root's own settings) wins over a "# Heading"
// in a notes file, which wins over the folder name.
$rootMeta = root_display_meta($dir);
if ($rootMeta['label'] !== '') $pageTitle = $rootMeta['label'];

// Extract {photos:}/{group:} directives across all qualifying .md files,
// in file order, tracking each directive's approximate position so it
// can be interleaved with auto-detected mention-order positions below.
$rootDirectives = collect_explicit_directives($mdContents, array_values(array_filter($files, function ($f) { return strpos($f, '/') === false; })), '', $files, $galleryNotices);
$explicitClaims = $rootDirectives['claims'];           // filename => true (removed from auto-detection)
$explicitGroups = $rootDirectives['groups'];           // slug => ['name' => .., 'files' => [...], 'position' => N]
$positionalClusters = $rootDirectives['clusters'];     // list of ['files' => [...], 'position' => N]

// Folder-groups: each top-level subfolder (an immediate child of the
// gallery root, or an EXTRA_FOLDERS virtual mount) with any files
// anywhere in its own subtree becomes one group, keyed by its own
// relative path. Every file inside a qualifying subfolder is claimed
// here, the same way a {photos:}-claimed file already is — removed from
// the filename-auto-grouping pool entirely below, so it's never
// re-clustered by its filename suffix a second time inside a folder it's
// already a member of. A folder's default label is its raw, unmodified
// name — {group: Name} in the root's own .md still renames it, the same
// mechanism that already renames any auto-detected group.
$folderGroups = [];       // relative folder path => [recursive member files]
$folderClaims = [];       // filename => true (removed from auto-detection, same as $explicitClaims)
if (AUTO_GROUP_BY_FOLDER && $folderTree !== null) {
    foreach ($folderTree['subdirs'] as $topName => $topNode) {
        $members = flatten_folder_tree($topNode, $topName);
        if (empty($members)) continue;
        $members = media_sort_apply($members, $dir);   // each folder's photos in its own order (see media_sort_apply)
        $folderGroups[$topName] = $members;
        foreach ($members as $m) {
            $folderClaims[$m] = true;
        }
    }
}
// Collections shown on the front page (those in it, and those marked to show there) are cards among the folders. They claim no photo: every
// member stays where it really is, and a card shows only the members this visitor may see (the filter below drops one with none).
if (AUTO_GROUP_BY_FOLDER && !folder_style_flat() && collections_any()) {
    foreach (collection_cards($dir, '', $files, $files) as [$collectionKey, $collectionRec, $collectionFiles]) {
        if (!isset($folderGroups[$collectionKey])) $folderGroups[$collectionKey] = $collectionFiles;
    }
}
$folderGroupKeys = array_fill_keys(array_keys($folderGroups), true);

// Build the final groups: filename-based auto-detection (unless
// AUTO_GROUP_BY_FILENAME is off, or a file was explicitly claimed above)
// plus explicit named groups. A file claimed via a bare {photos:} (no
// {group:}) stays its own standalone single, keyed by its own filename
// so it can never merge with an unrelated auto-detected group.
$groups = [];
foreach ($files as $file) {
    if (isset($explicitClaims[$file]) || isset($folderClaims[$file])) continue;
    $groups[group_key($file)][] = $file;
}
foreach ($folderGroups as $folderKey => $members) {
    $groups[$folderKey] = $members;
}

// A key shared by 2+ files is either a real, intended multi-angle group
// (members' pre-extension names differ and only converge after suffix-
// stripping, e.g. "dp104_01"/"dp104_02" -> "dp104") or a coincidence:
// files whose pre-extension names were already identical before any
// stripping happened at all (e.g. "topology.jpg" and "topology.png",
// both already "topology" — nothing was stripped for either). Only the
// first case is an intentional grouping signal; split the second case
// back into individually-keyed standalone singles instead of merging
// files that just happen to share a name.
foreach ($groups as $key => $groupFiles) {
    if (count($groupFiles) < 2 || isset($folderGroups[$key])) continue;   // (a folder's photos are its own by their folders: the same file name in two of its subfolders is nothing to split)
    $rawBases = array_map(function ($f) { return pathinfo($f, PATHINFO_FILENAME); }, $groupFiles);
    $counts = array_count_values($rawBases);
    $keep = [];
    foreach ($groupFiles as $i => $f) {
        if ($counts[$rawBases[$i]] > 1) {
            $groups[$f] = [$f];
        } else {
            $keep[] = $f;
        }
    }
    if (count($keep) !== count($groupFiles)) {
        if (empty($keep)) {
            unset($groups[$key]);
        } else {
            $groups[$key] = $keep;
        }
    }
}

foreach ($explicitClaims as $file => $claimed) {
    $inNamedGroup = false;
    foreach ($explicitGroups as $g) {
        if (in_array($file, $g['files'], true)) { $inNamedGroup = true; break; }
    }
    if (!$inNamedGroup) {
        // Keyed by the file's own full filename (with extension) — see
        // group_key()'s docblock for why the extension-stripped base
        // isn't safe to use as a per-file key.
        $groups[$file][] = $file;
    }
}
foreach ($explicitGroups as $slug => $g) {
    $groups[$slug] = $g['files'];
}

// Explicit group slugs, so the renderer knows to box these even with
// just a single photo — an auto-detected single never gets a box, but
// an explicitly named one always does; that's the point of naming it. A
// folder-group gets the same treatment: a photo deliberately organized
// into its own named folder deserves a box (or, in 'overview' mode, a
// cover card) regardless of how many other photos share it. Computed
// here (not just once, further down) because zone membership during
// ordering depends on it too.
$explicitGroupKeys = array_fill_keys(
    array_merge(array_keys($explicitGroups), array_keys($folderGroups)),
    true
);

/**
 * Determines display order within a single zone (either "boxes" or
 * "singles+clusters" — see compute_zone_order() below, which calls this
 * once per zone). Two tiers, in order:
 *
 *  1. Anything with prose in its paragraph — a classic text-mention (an
 *     auto-detected group's name found in the .md), or an explicit
 *     {group:}/{photos:} directive whose paragraph has other text —
 *     sorted earliest-first by where that prose sits in the .md. This
 *     mirrors the narrative: a reader sees a description, then its
 *     photos, in the order they were written about.
 *  2. Anything without prose (a textless directive, or never
 *     mentioned/claimed at all) falls back to natural order
 *     ($naturalRank). A textless multi-file group/cluster is anchored
 *     on whichever file was listed *first* in its {photos:} directive —
 *     never re-sorted — with its other members following immediately
 *     after, in the exact order listed.
 *
 * $keysInZone restricts consideration to keys belonging to this zone;
 * every key in $keysInZone appears exactly once in the result.
 */
/** Whether a notes file actually places any photo: a {photos:} or {group:} directive, or a paragraph that names one. A notes file that is
 *  only a title and some words leaves the photos where the order setting puts them (so the sort button stays). */
function notes_place_photos($mdContents, $groups, $explicitGroups, $positionalClusters) {
    if (!empty($explicitGroups) || !empty($positionalClusters)) return true;
    foreach ($mdContents as $content) {
        $masked = mask_directives($content);
        foreach (array_keys($groups) as $key) {
            if (preg_match(group_mention_pattern($key), $masked)) return true;
        }
    }
    return false;
}

function order_within_zone($keysInZone, $mdContents, $groups, $explicitGroups, $positionalClusters, $naturalRank, $rankOverride = []) {
    $inZone = array_fill_keys($keysInZone, true);
    $handled = [];
    $positioned = []; // ['position' => N, 'keys' => [...]]
    $natural = [];    // ['rank' => N, 'keys' => [...]]

    $offset = 0;
    $mentionPos = [];
    foreach ($mdContents as $content) {
        // Search prose only — a filename sitting inside some *other*
        // paragraph's {photos: a.jpg, b.jpg} list must never count as a
        // mention of that name (masking preserves offsets exactly, so
        // this stays comparable to the raw-content-based positions
        // computed during extraction, further up).
        $maskedContent = mask_directives($content);
        foreach ($keysInZone as $key) {
            if (isset($mentionPos[$key]) || isset($explicitGroups[$key])) continue;
            if (preg_match(group_mention_pattern($key), $maskedContent, $m, PREG_OFFSET_CAPTURE)) {
                $mentionPos[$key] = $offset + $m[0][1];
            }
        }
        $offset += strlen($content) + 1;
    }
    foreach ($mentionPos as $key => $pos) {
        $positioned[] = ['position' => $pos, 'keys' => [$key]];
        $handled[$key] = true;
    }

    foreach ($explicitGroups as $slug => $g) {
        if (!isset($inZone[$slug]) || isset($handled[$slug])) continue;
        if ($g['hasProse']) {
            $positioned[] = ['position' => $g['position'], 'keys' => [$slug]];
        } else {
            $natural[] = ['rank' => $naturalRank[$g['files'][0]] ?? PHP_INT_MAX, 'keys' => [$slug]];
        }
        $handled[$slug] = true;
    }

    foreach ($positionalClusters as $cluster) {
        $keys = [];
        foreach ($cluster['files'] as $f) {
            if (isset($inZone[$f]) && !isset($handled[$f])) $keys[] = $f;
        }
        if (empty($keys)) continue;
        if ($cluster['hasProse']) {
            $positioned[] = ['position' => $cluster['position'], 'keys' => $keys];
        } else {
            $natural[] = ['rank' => $naturalRank[$cluster['files'][0]] ?? PHP_INT_MAX, 'keys' => $keys];
        }
        foreach ($keys as $k) $handled[$k] = true;
    }

    foreach ($keysInZone as $key) {
        if (isset($handled[$key])) continue;
        $natural[] = ['rank' => $rankOverride[$key] ?? ($naturalRank[$groups[$key][0]] ?? PHP_INT_MAX), 'keys' => [$key]];
        $handled[$key] = true;
    }

    usort($positioned, function ($a, $b) { return $a['position'] <=> $b['position']; });
    usort($natural, function ($a, $b) { return $a['rank'] <=> $b['rank']; });

    $order = [];
    foreach ($positioned as $block) {
        foreach ($block['keys'] as $k) $order[] = $k;
    }
    foreach ($natural as $block) {
        foreach ($block['keys'] as $k) $order[] = $k;
    }
    return $order;
}

/**
 * Splits $groups into two zones and orders each independently — see
 * order_within_zone() above. Boxes (2+ files, or an explicitly named
 * group even with just one photo) always render before every single
 * and unboxed {photos:} cluster; a box is a full-width grid item, so
 * putting all of them first guarantees the singles wall that follows
 * always starts on a fresh row, instead of a box interrupting a
 * partially-filled row of cards.
 */
function compute_zone_order($mdContents, $groups, $explicitGroups, $positionalClusters, $explicitGroupKeys, $naturalRank, $rankOverride = [], $folderGroupKeys = []) {
    $boxKeys = [];
    $singleKeys = [];
    $folderKeys = [];   // (Flat: the folders' photos come after the page's own, in one run)
    foreach ($groups as $key => $groupFiles) {
        if (isset($folderGroupKeys[$key]) && folder_style_flat()) { $folderKeys[] = $key; continue; }
        // A folder-group is always in the box zone, even with one photo:
        // its cover card is a folder, not a single photo's card, and an
        // admin-set position has to be able to reorder it against every
        // other folder, not only the ones that happen to hold 2+ photos.
        if (count($groupFiles) >= 2 || isset($explicitGroupKeys[$key]) || isset($folderGroupKeys[$key])) {
            $boxKeys[] = $key;
        } else {
            $singleKeys[] = $key;
        }
    }
    $boxOrder = order_within_zone($boxKeys, $mdContents, $groups, $explicitGroups, $positionalClusters, $naturalRank, $rankOverride);
    $singleOrder = order_within_zone($singleKeys, $mdContents, $groups, $explicitGroups, $positionalClusters, $naturalRank, $rankOverride);
    return array_merge($boxOrder, $singleOrder, $folderKeys ? order_within_zone($folderKeys, $mdContents, $groups, $explicitGroups, $positionalClusters, $naturalRank, $rankOverride) : []);
}

// Reorders $groups per compute_zone_order() (boxes first, singles+
// clusters after; prose-position within each, natural order otherwise),
// then rebuilds the flat photo list to match, so the lightbox's index
// numbering (shared by both the note thumbnails and the gallery cards)
// stays consistent with the new display order.
// Folder-groups order among themselves by order_folder_children(): an
// admin-set position first (see ?admin), then natural name order — or
// folder mtime when PHOTO_SORT_ORDER says so. They take over each
// other's existing natural-order *slots* rather than jumping ahead of
// everything, so how folders interleave with filename groups doesn't
// change; only the folders' order among themselves does. It replaces
// only the natural ranking, so a prose mention in the root's own .md
// still positions a folder first, same precedence as for any group.
$folderSlots = [];
foreach ($folderGroupKeys as $folderKey => $_true) {
    $folderSlots[] = $naturalRank[$groups[$folderKey][0]] ?? PHP_INT_MAX;
}
sort($folderSlots);
$folderSortRank = [];
foreach (order_folder_children($dir, '', array_keys($folderGroupKeys)) as $slot => $folderKey) {
    $folderSortRank[$folderKey] = $folderSlots[$slot];
}
$groupOrder = compute_zone_order($mdContents, $groups, $explicitGroups, $positionalClusters, $explicitGroupKeys, $naturalRank, $folderSortRank, $folderGroupKeys);
$orderedGroups = [];
foreach ($groupOrder as $key) {
    if (isset($groups[$key]) && !isset($orderedGroups[$key])) {
        $orderedGroups[$key] = $groups[$key];
    }
}
$groups = $orderedGroups;

$files = [];
foreach ($groups as $groupFiles) {
    foreach ($groupFiles as $f) {
        $files[] = $f;
    }
}
$files = array_values(array_unique($files));   // (a photo can be on two cards: in its folder and in a collection)

// Reverse lookup used by ?track below: which $groups key currently
// contains a given filename. Needed because an explicit named group's
// key is a slug chosen by the .md author, not something group_key()
// could ever derive from the filename itself.
$fileToGroupKey = [];
foreach ($groups as $key => $groupFiles) {
    foreach ($groupFiles as $gf) {
        $fileToGroupKey[$gf] = $key;
    }
}

// ?admin — checked here, as soon as $groups exists, and before anything
// below it (stats, ?track, the public page) does any work a management
// request has no business triggering. See run_admin_route()'s own
// docblock, further down, for the full auth/CSRF flow.
if (request_route() === 'admin') {
    changes_check($dir, $files, !is_admin_browser());   // (the owner's own pages always look: what changed on disk since the last look is written down, see the Changes tab; anyone else's request at most once a minute)
    run_admin_route($dir, $files, $groups, $folderGroupKeys, $explicitGroups, $explicitGroupKeys, $videoDurations, $galleryNotices);
    exit;
}

// A snapshot of every real file, taken before the hiding filter below
// ever touches $files — gc_image_cache()/gc_items_data() further down
// need the *complete* picture to tell "genuinely deleted" apart from
// "hidden from this particular unrevealed visitor." Using the
// already-filtered $files there would make every hidden item look
// deleted to the garbage collector, which would wipe out its own hide
// flag (and its cached thumbnails) the next time an unrevealed visitor
// just loads the page — the opposite of what hiding is supposed to do.
$allFiles = $files;
changes_check($dir, $allFiles, !is_admin_browser());   // (every page of the gallery looks at what changed on disk: the owner's every time, a visitor's at most once a minute)
// Whether anything in the gallery is private: the lock in the icon strip only appears when there is something to unlock.
$hasPrivateItems = false;
foreach ($allFiles as $privateProbe) {
    if (file_is_hidden($dir, $privateProbe, $itemsData)) { $hasPrivateItems = true; break; }
}

// Private view, for everything from here on (the public page, ?track=,
// ?folder=): a hidden item — or an entirely-hidden folder-group — is
// removed from $groups/$files outright for an unrevealed visitor, not
// merely skipped in a display loop. That's what makes every downstream
// count, the lightbox's own file list, and every allowlist check below
// all naturally agree with what's actually rendered — a count that
// doesn't match what's visible would itself be a leak. (?img=/?video= answer even earlier, before the tree is scanned, with
// their own check; ?photo= runs its own just above — this filter runs
// too late to help them.) If
// every member of a group ends up hidden, the group itself disappears
// here too, rather than rendering an empty "0 photos" box, which would
// be its own tell that something's being withheld.
if (is_admin_browser() || (!is_revealed() && ($linkHere = link_viewer()) !== null && ($linkHere['scope']['type'] ?? 'all') !== 'all')) link_scan_ambiguity($dir, $allFiles);   // (the owner's own look keeps the list current too, so a photo address in a copied folder is refused by the pictures as well)   // a folder copied with its id is shown to nobody
if (!is_admin_browser()) {   // (someone with the private-view password is filtered too, but only of what is Not listed: viewer_may_see_item says so)
    foreach ($groups as $key => $groupFiles) {
        $visibleMembers = array_values(array_filter($groupFiles, function ($f) use ($dir, $itemsData, $photoPass) {
            return $photoPass !== null ? $f === $photoPass : viewer_may_see_item($dir, $f, $itemsData);   // (opened by its own address: that photo, not the gallery's public rest)
        }));
        if (empty($visibleMembers)) {
            unset($groups[$key], $folderGroupKeys[$key], $explicitGroupKeys[$key]);
        } else {
            $groups[$key] = $visibleMembers;
        }
    }
    $visibleSet = [];
    foreach ($groups as $groupFiles) {
        foreach ($groupFiles as $f) {
            $visibleSet[$f] = true;
        }
    }
    $files = array_values(array_filter($files, function ($f) use ($visibleSet) {
        return isset($visibleSet[$f]);
    }));
    // A link's own photos are loose tiles on the front page, not folder cards: each leaves its folder's group and stands alone,
    // after the rest. (A folder the link shares stays a card; ?folder= pages are built from $files and are not affected.)
    if ($photoPass !== null) {   // the photo stands alone: no folder card around it (its name would say where it lives)
        foreach ($folderGroupKeys as $folderKey => $_true) { unset($groups[$folderKey], $folderGroupKeys[$folderKey], $explicitGroupKeys[$folderKey]); }
        $looseKey = group_key(basename($photoPass));
        if (isset($groups[$looseKey])) $looseKey = $photoPass;
        $groups[$looseKey] = [$photoPass];
        $fileToGroupKey[$photoPass] = $looseKey;
    } elseif (($pickLink = link_viewer()) !== null && !is_revealed()) {
        $pickedOut = [];
        foreach ($folderGroupKeys as $folderKey => $_true) {
            if (!isset($groups[$folderKey])) continue;
            $stay = [];
            foreach ($groups[$folderKey] as $f) {
                if (link_item_is_picked_photo($pickLink, $dir, $f)) $pickedOut[] = $f; else $stay[] = $f;
            }
            if ($stay) { $groups[$folderKey] = $stay; continue; }
            unset($groups[$folderKey], $folderGroupKeys[$folderKey], $explicitGroupKeys[$folderKey]);
        }
        foreach ($pickedOut as $f) {
            $looseKey = group_key(basename($f));
            if (isset($groups[$looseKey])) $looseKey = $f;
            $groups[$looseKey] = [$f];
            $fileToGroupKey[$f] = $looseKey;
        }
    }
}

