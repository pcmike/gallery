  <?= owner_phone_bar_css() ?>
  body.inframe .crumb-bar { display: none; }
  body.inframe .crumb-bar.has-sec { display: flex; }   /* (inside the admin the tree and the title line are the way back and the folders beside this one; the pills for the page's own folders stay) */
  body.inframe .crumb-row > :not(.sec-pills) { display: none; }
  body.inframe .crumb-row nav.sec-pills { margin-left: auto; }
  body.inframe header { padding-top: 10px; }  /* (the same start under the shell's bar on the front page as in a folder, where the crumb bar's rule gives it) */
</style>
</head>
<?php $shellOwner = ADMIN_PASSWORD !== '' && is_admin_browser(); $shellInframe = $shellOwner && !empty($_GET['inframe']); ?>
<body<?= $shellInframe ? ' class="inframe"' : ($shellOwner ? ' class="pb-on"' : '') ?>>
<?= ui_icon_sprite() ?>
<?php if ($shellOwner): ?><div id="live-folder" hidden data-folder="<?= htmlspecialchars((string) $currentFolderPath, ENT_QUOTES, 'UTF-8') ?>" data-last="<?= changes_last_id() ?>" data-noun="<?= ENABLE_VIDEO ? 'item' : 'photo' ?>"></div><?php endif; ?>
<?php // Once the page has scrolled, two round buttons float at the bottom right of the screen: to the top, and to the bottom. The same on every page and device. ?>
<div class="jump-fab" id="jump-fab" hidden>
  <button type="button" class="jump-btn" data-jump="top" title="Jump to the top" aria-label="Jump to the top of the page"><?= ui_icon('jump-up') ?></button>
  <button type="button" class="jump-btn" data-jump="bottom" title="Jump to the bottom" aria-label="Jump to the bottom of the page"><?= ui_icon('jump-down') ?></button>
</div>
<?= $shellInframe ? shell_frame_script('gallery') : '' ?>
<?php if (($previewLink = link_preview()) !== null) echo render_link_preview_banner($previewLink, $dir, $allFiles); ?>
<?php if (($headsUpLink = link_viewer()) !== null && !is_revealed() && link_headsup_on($headsUpLink)) echo render_link_headsup($headsUpLink); ?>

<?php
// The lock in the icon strip, right beside the archive icon: the one way in. Shown when there is an admin password, or a private-view password and
// something private to unlock. Closed: a link to the sign-in (the private-view password shows the private items, the
// admin password opens the admin). Open: this browser is signed in (with either); pressing it signs out and locks the
// private items again.
$lockHtml = ''; $lockForm = '';
if ((ADMIN_PASSWORD !== '' || (PRIVATE_VIEW_PASSWORD !== '' && $hasPrivateItems)) && !viewer_is_link()) {
    $lockFolderQuery = $currentFolderPath !== null ? '&amp;folder=' . htmlspecialchars(rawurlencode($currentFolderPath), ENT_QUOTES, 'UTF-8') : '';
    // The open photo and the open map are part of "the page you are on" too: signing in or out brings you back to them.
    $lockPhoto = isset($_GET['photo']) && is_string($_GET['photo']) && $_GET['photo'] !== '' && strlen($_GET['photo']) < 1024 ? $_GET['photo'] : '';
    $lockHere = $lockFolderQuery . ($lockPhoto !== '' ? '&amp;photo=' . htmlspecialchars(rawurlencode($lockPhoto), ENT_QUOTES, 'UTF-8') : '') . (isset($_GET['map']) ? '&amp;map' : '');
    $lockHidden = '<input type="hidden" name="folder" value="' . htmlspecialchars((string) $currentFolderPath, ENT_QUOTES, 'UTF-8') . '">'
        . ($lockPhoto !== '' ? '<input type="hidden" name="photo" value="' . htmlspecialchars($lockPhoto, ENT_QUOTES, 'UTF-8') . '">' : '')
        . (isset($_GET['map']) ? '<input type="hidden" name="map" value="1">' : '');
    // No lock icon in the icon strip any more: the admin (?admin or /admin) is the way in. What stays is the small form the owner's Log out
    // (in the phone Menu) submits to sign out of this page.
    if (is_admin_browser() || is_revealed()) $lockForm = '<form method="post" action="?reveal" class="lock-form" id="lock-form" hidden>' . $lockHidden . '</form>';
}
?>
<?php if ($shellOwner && !$shellInframe) echo owner_phone_bar('gallery', $files, $currentFolderPath, (bool) (admin_any_hidden($dir, $files, $folderGroupKeys) ? true : admin_edited_overview($dir, $files, null)['total'] > 0), $lockForm !== '' ? '<button type="submit" form="lock-form" name="logout" value="1" title="Log out" aria-label="Log out">' . ui_icon('logout') . '</button>' : ''); ?>
<?php if ($currentFolderPath !== null): ?>
<div class="crumb-bar" id="crumb-bar" role="navigation" aria-label="Where you are">
    <div class="crumb-row">
<?php if ($navTop === '' || $navBack !== null): ?>
    <p class="breadcrumb"><a href="<?= htmlspecialchars($navTop === '' ? gallery_root_href() : $navBack[0], ENT_QUOTES, 'UTF-8') ?>">&larr; <?= htmlspecialchars($navTop === '' ? $rootPageTitle : $navBack[1]) ?></a><?php foreach ($folderCrumbs as [$crumbHref, $crumbLabel]): ?> / <a href="<?= htmlspecialchars($crumbHref, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($crumbLabel) ?></a><?php endforeach; ?></p>
<?php endif; ?>
    <?php if (count($folderPills) >= 2): ?>
    <nav class="folder-pills" aria-label="Other folders here">
      <?php foreach ($folderPills as [$pillHref, $pillText, $pillCurrent, $pillPriv]): ?><a href="<?= htmlspecialchars($pillHref, ENT_QUOTES, 'UTF-8') ?>" class="<?= trim(($pillCurrent ? 'current' : '') . ($pillPriv !== '' ? ' priv' : '')) ?>"<?= $pillCurrent ? ' aria-current="page"' : '' ?><?= $pillPriv !== '' ? ' title="' . ($pillPriv === 'own' ? 'Private folder' : 'Hidden because a folder above it is private') . '"' : '' ?>><?= $pillPriv !== '' ? ui_icon('lock') : '' ?><?= htmlspecialchars($pillText, ENT_QUOTES, 'UTF-8') ?></a><?php endforeach; ?>
      <?php if ($folderPillsAll !== null): ?><a class="all" href="<?= htmlspecialchars($folderPillsAll, ENT_QUOTES, 'UTF-8') ?>">All</a><?php endif; ?>
    </nav>
    <?php else: ?>
    <?php // A folder with no sibling has no row of folders beside it, so nothing would say where you are but the title: it gets its own pill, the same blue one, after the way up. ?>
    <nav class="folder-pills" aria-label="This folder"><a href="?folder=<?= htmlspecialchars(rawurlencode($currentFolderPath), ENT_QUOTES, 'UTF-8') ?>" class="current" aria-current="page"><?= htmlspecialchars($currentFolderName, ENT_QUOTES, 'UTF-8') ?></a></nav>
    <?php endif; ?>
    </div>
</div>
<?php elseif (AUTO_GROUP_BY_FOLDER && folder_style_flat() && (flat_names_on() || is_admin_browser()) && count(split_folder_level($files, '')['folders']) >= 2): ?>
<?php // The gallery's own page, Flat with folder names (or the owner, who gets them without): no way back and no folders beside it, but the folders below get their row of pills (the script fills it in and takes the bar away if it finds fewer than two). ?>
<div class="crumb-bar" id="crumb-bar" role="navigation" aria-label="Folders on this page">
  <div class="crumb-row"></div>
</div>
<?php endif; ?>
<header>
  <?php if (ADMIN_PASSWORD !== '' && is_admin_browser() && !$shellInframe): ?>
    <?php // Signed in as the owner: the title opens the admin (the shell) on wherever you are (a folder's own settings on its page); a phone gets the admin page. ?>
    <h1><a class="title-admin" rel="nofollow" href="?admin&amp;shell=1<?= $currentFolderPath !== null ? '&amp;folder=' . htmlspecialchars(rawurlencode($currentFolderPath), ENT_QUOTES, 'UTF-8') : '' ?>"><?= htmlspecialchars($pageTitle) ?></a></h1>
  <?php else: ?>
    <h1><?= htmlspecialchars($pageTitle) ?></h1>
  <?php endif; ?>
  <?php $headerMeta = $currentFolderPath !== null ? $currentFolderMeta : $rootMeta; ?>
  <?php if ($headerMeta['dates'] !== ''): ?>
    <p class="folder-dates"><?= htmlspecialchars($headerMeta['dates']) ?></p>
  <?php endif; ?>
  <?php // Flat, for the owner: how many folders the photos below come from (so a flat grid never hides that there are folders)
  $flatFolderCount = 0;
  if (is_admin_browser() && AUTO_GROUP_BY_FOLDER && folder_style_flat()) {
      $flatDirs = [];
      foreach ($currentFolderPath !== null ? $currentFolderFiles : $files as $ff) { $fd = dirname($ff); if ($fd !== '.' && $fd !== $currentFolderPath) $flatDirs[$fd] = true; }
      $flatFolderCount = count($flatDirs);
  } ?>
  <p><?= $flatFolderCount > 0 ? number_format($flatFolderCount) . ' folder' . ($flatFolderCount === 1 ? '' : 's') . ' &middot; ' : '' ?><?= htmlspecialchars(media_count_label($currentFolderPath !== null ? $currentFolderFiles : $files), ENT_QUOTES, 'UTF-8') ?><?php if ($viewCount > 0 && view_counts_shown()): ?> &middot; <span class="page-visits"><?= number_format($viewCount) ?> visit<?= $viewCount === 1 ? '' : 's' ?></span><?php endif; ?><?php if ($lastUpdatedMtime > 0): ?> &middot; Last updated <?= htmlspecialchars(date('F j, Y', $lastUpdatedMtime)) ?><?php endif; ?></p>
</header>
<main id="content">

<?php if ($headerMeta['description'] !== ''): ?>
  <div class="notes">
    <div class="note-box folder-description"><?= render_description_html($headerMeta['description']) ?></div>
  </div>
<?php endif; ?>

<?php if ($currentFolderPath === null && empty($mdFiles) && !empty($galleryNotices) && notices_visible()): ?>
  <div class="notes">
    <?= render_gallery_notices_banner($galleryNotices, empty($files)) ?>
  </div>
<?php endif; ?>

<?php
// markdown_to_html() still has to run against the *root's own, unscoped*
// $groups/$files whenever a .md qualifies — its side effects
// ($groupLabels especially) are what let a {group: Name} directive in
// the root's notes rename a folder-group, including one being viewed
// right now via ?folder=. Its visible output is a different question: a
// folder's own page doesn't show the root's notes box (they describe the
// whole trip, not specifically this one folder), so the rendered HTML is
// only kept, never discarded, when there's no $currentFolderPath to hide
// it for. A subfolder's own notes are a separate pipeline, further down,
// once $groups/$files have actually been scoped down to it.
$noteBoxesHtml = [];
foreach ($mdFiles as $mdFile) {
    $noteBoxesHtml[$mdFile] = markdown_to_html($mdContents[$mdFile], $groups, $files, $groupColors, $groupStatus, $groupLabels, $renderClaims, $photoViews);
}
?>
<?php if ($currentFolderPath === null && !empty($mdFiles)): ?>
  <div class="notes">
    <?= render_gallery_notices_banner($galleryNotices, empty($files)) ?>
    <?php foreach ($mdFiles as $mdFile): if (note_box_is_empty($noteBoxesHtml[$mdFile])) continue; ?>
      <div class="note-box">
        <span class="note-filename"><?= htmlspecialchars($mdFile) ?></span>
        <?= $noteBoxesHtml[$mdFile] ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php
// The actual ?folder= override: scope $groups/$files down to just what's
// under this folder, built from the already hiding-filtered $files. In
// 'overview' mode each immediate subfolder becomes a cover card (which
// links to its own ?folder= page, repeating this to any depth) and
// anything loose at this level is a flat card; in 'inline' mode there are
// no cover cards and everything beneath is one flat run.
// $explicitGroupKeys is cleared (a root-level {group:} box means nothing
// here) and $folderGroupKeys is rebuilt for this level only. ($pageTitle
// was already set earlier, in the ?folder= validation block.)
//
// Keyed the same bare, extension-stripped way a root-level standalone
// single already is (see group_key()) — "eiffel," not "Paris/eiffel.jpg"
// — so a mention or {color:}/{sold:}/{reserved:} directive in this
// subfolder's own notes (further down) can refer to it the natural way,
// and the card's own "#group-<slug>" anchor matches whatever that
// note's jump link points at. Two files that would collide on that bare
// key (different extensions, or two numbered angles — the same
// coincidence the root's own disambiguation pass already handles) fall
// back to their full bare filename instead, which is always unique
// within one folder; this doesn't recluster them into a group the way
// the root would, just keeps them from overwriting each other.
$rootFolderGroupKeys = $folderGroupKeys;
$folderLinkPrefix = '';
$folderMdFiles = [];
$folderMdContents = [];
$folderGalleryNotices = [];
$folderNoteBoxesHtml = [];
if ($currentFolderPath !== null) {
    $folderFiles = $currentFolderFiles;
    $folderPrefix = $currentFolderPath . '/';
    $folderLinkPrefix = $folderPrefix;
    // The root's own labels/covers/mention links are keyed by top-level
    // folder names; on a folder's page the keys are this level's own
    // names, so start clean rather than let a same-named folder deeper
    // down pick up the wrong top-level folder's settings.
    $groupLabels = [];
    $folderCovers = [];
    $folderMeta = [];
    $folderMentionLinks = [];

    // 'overview' mode drills one level at a time: each immediate
    // subfolder is a cover card (recursively counted), anything loose
    // directly here is a flat card, and the same logic repeats on that
    // subfolder's own page — to any depth. 'inline' mode never reaches
    // a cover card, so a folder's page just shows everything beneath it.
    if ($currentCollectionPage !== null) {
        // A collection's page: its member folders are cards (each named by its real path, so the card leads to the real folder) and its single photos
        // are loose tiles; nothing here is a path under the collection's own address, which only exists in the gallery.
        $folderPrefix = ''; $folderLinkPrefix = '';
        $level = ['folders' => $currentCollectionPage['folders'], 'loose' => $currentCollectionPage['loose']];
    } else {
        $level = split_folder_level($folderFiles, $folderPrefix);   // (Flat shows the same folders, in one grid or in boxes, instead of as cards)
        // The collections that live in this folder are cards among its folders (never in Flat, which shows the photos in boxes).
        if (AUTO_GROUP_BY_FOLDER && !folder_style_flat() && collections_any()) {
            foreach (collection_cards($dir, $currentFolderPath, $allFiles, $files) as [$collectionKey, $collectionRec, $collectionFiles]) {
                if (!isset($level['folders'][$collectionKey])) $level['folders'][$collectionKey] = $collectionFiles;
            }
        }
    }

    $groups = [];
    $folderGroupKeys = [];
    foreach (order_folder_children($dir, $folderPrefix, array_keys($level['folders'])) as $childName) {
        $groups[$childName] = $level['folders'][$childName];
        $folderGroupKeys[$childName] = true;
        $m = folder_display_meta($dir, $folderPrefix . $childName);
        if ($m['label'] !== '') $groupLabels[$childName] = $m['label'];
        elseif ($currentCollectionPage !== null) $groupLabels[$childName] = basename($childName);   // (on a collection's page a member folder is named by its full path: show its own name)
        if ($m['cover'] !== null) $folderCovers[$childName] = $m['cover'];
        $folderMeta[$childName] = ['description' => $m['description'], 'dates' => $m['dates']];
        $folderMentionLinks[$childName] = '?folder=' . rawurlencode($folderPrefix . $childName);
    }

    $bareBaseCounts = [];
    foreach ($level['loose'] as $f) {
        $bare = substr($f, strlen($folderPrefix));
        $rawBase = pathinfo($bare, PATHINFO_FILENAME);
        $bareBaseCounts[$rawBase] = ($bareBaseCounts[$rawBase] ?? 0) + 1;
    }
    foreach ($level['loose'] as $f) {
        $bare = substr($f, strlen($folderPrefix));
        $rawBase = pathinfo($bare, PATHINFO_FILENAME);
        $localKey = $bareBaseCounts[$rawBase] > 1 ? $bare : group_key($bare);
        // A loose file whose bare name matches a subfolder's would
        // overwrite that folder's card; fall back to the full filename.
        if (isset($groups[$localKey])) $localKey = $bare;
        $groups[$localKey] = [$f];
    }
    // This folder's own notes go through the same directive reading as the top-level ones (see
    // collect_explicit_directives): {photos:} and {group:} claim the photos directly in this folder, a mention
    // pulls a photo or subfolder up to its paragraph. The photos it may claim are judged before the hiding
    // filter, so a hidden photo is dropped without a word instead of being reported.
    $folderRealPath = resolve_real_path($dir, $currentFolderPath);
    $folderMdScan = scan_gallery_md_files($folderRealPath);
    $folderMdFiles = $folderMdScan['files'];
    $folderMdContents = $folderMdScan['contents'];
    if ($folderMdScan['rejectedCount'] > 0) {
        $folderGalleryNotices[] = $folderMdScan['rejectedCount'] === 1
            ? 'A .md file was found in this folder but ignored because its first line isn\'t the required {gallery} marker.'
            : $folderMdScan['rejectedCount'] . ' .md files were found in this folder but ignored because none of them start with the required {gallery} marker.';
    }
    $directFiles = function ($list) use ($folderPrefix) {
        return array_values(array_filter($list, function ($f) use ($folderPrefix) {
            return strncmp($f, $folderPrefix, strlen($folderPrefix)) === 0 && strpos(substr($f, strlen($folderPrefix)), '/') === false;
        }));
    };
    $folderDirectives = collect_explicit_directives($folderMdContents, $directFiles($allFiles), $folderPrefix, $allFiles, $folderGalleryNotices);
    $visibleDirect = array_flip($directFiles($files));
    $folderExplicitGroups = [];
    foreach ($folderDirectives['groups'] as $slug => $g) {
        $g['files'] = array_values(array_filter($g['files'], function ($f) use ($visibleDirect) { return isset($visibleDirect[$f]); }));
        if ($g['files']) $folderExplicitGroups[$slug] = $g;
    }
    $folderClusters = [];
    foreach ($folderDirectives['clusters'] as $c) {
        $c['files'] = array_values(array_filter($c['files'], function ($f) use ($visibleDirect) { return isset($visibleDirect[$f]); }));
        if ($c['files']) $folderClusters[] = $c;
    }
    $folderClaimed = [];
    foreach (array_merge(array_column($folderExplicitGroups, 'files'), array_column($folderClusters, 'files')) as $claimedList) {
        foreach ($claimedList as $f) $folderClaimed[$f] = true;
    }
    // A claimed photo leaves its own card; it comes back as its box, or (a bare {photos:}) as a card of its own
    // that its paragraph can style, keyed by the whole file name like the top-level ones.
    $naturalRankHere = [];
    foreach ($groups as $groupFiles) {
        foreach ($groupFiles as $f) $naturalRankHere[$f] = count($naturalRankHere);
    }
    foreach ($groups as $groupKey => $groupFiles) {
        if (count($groupFiles) === 1 && isset($folderClaimed[$groupFiles[0]])) unset($groups[$groupKey]);
    }
    $inNamedGroup = [];
    foreach ($folderExplicitGroups as $slug => $g) {
        $groups[$slug] = $g['files'];
        foreach ($g['files'] as $f) $inNamedGroup[$f] = true;
    }
    foreach (array_keys($folderClaimed) as $f) {
        if (!isset($inNamedGroup[$f])) $groups[substr($f, strlen($folderPrefix))] = [$f];
    }
    $explicitGroupKeys = array_fill_keys(array_keys($folderExplicitGroups), true);
    // Same ordering as the top level: a paragraph pulls what it mentions or claims up to its place, everything
    // else keeps the order it had (folders first, then the photos).
    $groupOrder = compute_zone_order($folderMdContents, $groups, $folderExplicitGroups, $folderClusters, $explicitGroupKeys, $naturalRankHere, [], $folderGroupKeys);
    $orderedHere = [];
    foreach ($groupOrder as $key) {
        if (isset($groups[$key]) && !isset($orderedHere[$key])) $orderedHere[$key] = $groups[$key];
    }
    $groups = $orderedHere;
    // The flat list has to follow the new card order exactly: every
    // card's lightbox index is drawn from it.
    $files = [];
    foreach ($groups as $groupFiles) {
        foreach ($groupFiles as $f) {
            $files[] = $f;
        }
    }
    $files = array_values(array_unique($files));   // (a photo can be on two cards: in its folder and in a collection)
} elseif (!is_revealed()) {
    // The front page: a link's own photos were moved into loose groups above, so the flat list follows the cards' order.
    $files = [];
    foreach ($groups as $groupFiles) {
        foreach ($groupFiles as $f) {
            $files[] = $f;
        }
    }
}

// A subfolder may optionally carry its own {gallery}-marked .md,
// becoming a dedicated notes box just for that subfolder's own photos —
// scoped to it the same way the root's notes are scoped to the whole
// site, so a Paris .md simply has no way to claim a StTropez photo. Page
// title stays root-driven (see $rootPageTitle above) — a subfolder's own
// heading, if it has one, is just left as plain text in its own note
// box, not promoted to the page <title>. $groups/$files are already the
// folder-scoped, bare-keyed versions the override above produced, so
// this is the exact same call shape the root's own notes use — a mention
// or directive here naturally lines up with the card it actually
// describes, anchors included.
if ($currentFolderPath !== null) {
    foreach ($folderMdFiles as $folderMdFile) {
        $folderNoteBoxesHtml[$folderMdFile] = markdown_to_html($folderMdContents[$folderMdFile], $groups, $files, $groupColors, $groupStatus, $groupLabels, $renderClaims, $photoViews, $folderLinkPrefix);
    }
}
?>
<?php if ($currentFolderPath !== null && empty($folderMdFiles) && !empty($folderGalleryNotices) && notices_visible()): ?>
  <div class="notes">
    <?= render_gallery_notices_banner($folderGalleryNotices) ?>
  </div>
<?php endif; ?>
<?php if ($currentFolderPath !== null && !empty($folderMdFiles)): ?>
  <div class="notes">
    <?= render_gallery_notices_banner($folderGalleryNotices) ?>
    <?php foreach ($folderMdFiles as $folderMdFile): if (note_box_is_empty($folderNoteBoxesHtml[$folderMdFile])) continue; ?>
      <div class="note-box">
        <span class="note-filename"><?= htmlspecialchars($folderMdFile) ?></span>
        <?= $folderNoteBoxesHtml[$folderMdFile] ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php
// A page of nothing but a few folder cards gets bigger cards on a wide screen (two folders shouldn't be two small tiles
// in a big empty page). The map buttons above them use the same width, so they line up with the first card's corner.
// Anything else, and every phone, keeps the usual grid.
$coverCount = !folder_style_flat() ? count($folderGroupKeys) : 0;
$fewCovers = ($coverCount >= 1 && $coverCount <= 6 && count($groups) === $coverCount) ? ' covers-' . $coverCount : '';
$GLOBALS['__big_covers'] = $fewCovers !== '';
// A page with fewer than ten things in its grid (folder cards, photos and videos together) shows one per row on a phone: a few big pictures read better than a few small ones. Ten or more keep the usual grid.
$oneUp = (count($groups) >= 1 && count($groups) < 10) ? ' one-up' : '';
?>
<?php
// A note can colour a group or folder ({color:}) or mark it sold/reserved: its photos' pins follow.
if (ENABLE_PHOTO_LOCATION && $mapPoints) {
    $mapPoints = apply_note_colors_to_points($mapPoints, $groups, $groupColors, $groupStatus, $groupLabels);
    $mapLegend = map_legend_from_points($mapPoints);
}
?>
<?php $hasMap = $mapShown && !empty($mapPoints); ?>
<?php
  // The sort button: for the photos shown at this level (the folder's own, or all of them when folders are shown inline), what
  // there is to put first, and the order in force here. Hidden when there is nothing to put in order.
  $sortScope = array_values(array_filter($files, function ($f) use ($currentFolderPath, $currentCollectionPage) {
      if ($currentCollectionPage !== null) return in_array($f, $currentCollectionPage['loose'], true);   // (a collection's own tiles are its single photos)
      if (folder_style_flat() || !AUTO_GROUP_BY_FOLDER) return true;
      return ($currentFolderPath === null ? '' : $currentFolderPath) === (strpos($f, '/') === false ? '' : dirname($f));
  }));
  $notesLayOut = (!AUTO_GROUP_BY_FOLDER || !$folderGroupKeys) && !empty($mdFiles) && notes_place_photos($mdContents, $groups, $explicitGroups, $positionalClusters);   // (a notes file that places photos sets their order; one that is only words does not)
  $sortShown = SHOW_SORT_BUTTON && count($sortScope) >= 2 && !$notesLayOut;
  $sortHasVideo = false; $sortHasLocated = false; $sortHasPrivate = false;
  if ($sortShown) {
      $sortRevealed = is_revealed();
      foreach ($sortScope as $sf) {
          if (!$sortHasVideo && is_video_file($sf)) $sortHasVideo = true;
          if (!$sortHasLocated && photo_location_visible($itemsData[$sf] ?? [])) $sortHasLocated = true;
          if ($sortRevealed && !$sortHasPrivate && file_is_hidden($dir, $sf, $itemsData)) $sortHasPrivate = true;
      }
      $sortSpec = media_sort_spec_for_folder($currentFolderPath === null ? $dir : resolve_real_path($dir, $currentFolderPath));
      if (!$sortRevealed) $sortSpec['private'] = false;
      $sortCookiePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
  }
  // The owner's Arrange button: photos shown one by one here (not in a filename group or a notes file's layout, which keep their own order).
  $arrangeShown = is_admin_browser() && !AUTO_GROUP_BY_FILENAME && count($sortScope) >= 2 && !$notesLayOut;
  $selectShown = is_admin_browser() && count($sortScope) >= 1;
  $selectCollections = [];   // the collections the owner can add the picked photos to (the Select bar's "Add to collection", shown once there is one)
  if ($selectShown && AUTO_GROUP_BY_FOLDER && collections_any()) {
      foreach (collections_registry($dir, $allFiles)['byPath'] as $cPath => $cRec) $selectCollections[] = ['id' => $cRec['id'], 'title' => $cRec['title'] !== '' ? $cRec['title'] : $cRec['name'], 'where' => $cRec['parentPath'] === '' ? 'on the front page' : 'in ' . $cRec['parentPath'], 'private' => $cRec['private']];
  }
  $arrangeStacks = [];
  if ($arrangeShown) {
      $arrangeByFolder = [];
      foreach ($sortScope as $sf) $arrangeByFolder[containing_folder_real_path($dir, $sf)][] = $sf;
      foreach ($arrangeByFolder as $arrangeReal => $arrangeList) foreach (media_stacks_resolve($arrangeReal, $arrangeList) as $st) $arrangeStacks[] = $st['files'];
  }
?>
  <?php // The bar above the photos: the map buttons on the left (when there is a map), and on the right the visit count (when
        // it is shown to visitors) and the small gallery icon, which leads to the page about this gallery. ?>
  <div class="map-preview map-bar<?= $fewCovers ?>" id="map-bar">
    <div class="map-preview-heading">
      <?php if ($hasMap): ?>
      <span class="map-combo">
        <button type="button" class="map-combo-btn" id="map-toggle" title="Show map" aria-expanded="false" aria-controls="map-preview-body"><?= ui_icon('map') ?><span class="map-toggle-label">Show map</span></button>
        <a href="<?= htmlspecialchars($mapPageHref, ENT_QUOTES, 'UTF-8') ?>" class="map-combo-btn" id="map-full" title="View full map"><?= ui_icon('expand') ?><span class="map-toggle-label">View full map</span></a>
      </span>
      <?php endif; ?>
      <?php if ($sortShown): ?>
      <span class="map-combo sort-combo" id="sort-combo" data-spec="<?= htmlspecialchars(media_sort_encode($sortSpec), ENT_QUOTES, 'UTF-8') ?>" data-path="<?= htmlspecialchars($sortCookiePath, ENT_QUOTES, 'UTF-8') ?>">
        <button type="button" class="map-combo-btn" id="sort-menu-btn" title="Order of the photos" aria-haspopup="true" aria-expanded="false" aria-controls="sort-pop"><?= ui_icon('sort') ?><span class="map-toggle-label">Order of the photos</span></button>
        <?php if ($sortHasPrivate): ?><button type="button" class="map-combo-btn sort-flag" data-flag="private" title="Private items first" aria-pressed="false"><?= ui_icon('lock') ?><span class="map-toggle-label">Private items first</span></button><?php endif; ?>
        <?php if ($sortHasVideo): ?><button type="button" class="map-combo-btn sort-flag" data-flag="video" title="Videos first" aria-pressed="false"><?= ui_icon('play') ?><span class="map-toggle-label">Videos first</span></button><?php endif; ?>
        <?php if ($sortHasLocated): ?><button type="button" class="map-combo-btn sort-flag" data-flag="located" title="Photos with a location first" aria-pressed="false"><?= ui_icon('pin') ?><span class="map-toggle-label">Photos with a location first</span></button><?php endif; ?>
      </span>
      <div class="sort-pop" id="sort-pop" hidden role="dialog" aria-label="Order of the photos">
        <div class="sort-pop-title">Order</div>
        <div class="sort-pop-row"><button type="button" data-order="name">Name</button><button type="button" data-order="taken">Date</button></div>
        <div class="sort-pop-row"><button type="button" data-dir="asc"></button><button type="button" data-dir="desc"></button></div>
        <button type="button" class="sort-reset">Use this gallery's order</button>
      </div>
      <?php endif; ?>
      <?php if ($selectShown): ?>
      <span class="map-combo select-combo"><button type="button" class="map-combo-btn" id="select-btn" title="Select <?= media_word() ?>" aria-pressed="false"><?= ui_icon('select') ?><span class="map-toggle-label">Select <?= media_word() ?></span></button></span>
      <?php endif; ?>
      <span class="page-tools">
        <?php if ($archiveCount > 0 || $lockHtml !== ''): $archiveTitle = 'Download all ' . number_format($archiveCount) . ' original' . ($archiveCount === 1 ? '' : 's') . ' (' . human_bytes($archiveBytes) . ') as one ZIP'; ?>
        <span class="map-combo"><?php if ($archiveCount > 0): ?><a class="map-combo-btn page-archive" href="?archive<?= $currentFolderPath !== null ? '&amp;folder=' . htmlspecialchars(rawurlencode($currentFolderPath), ENT_QUOTES, 'UTF-8') : '' ?>" data-bytes="<?= (int) $archiveBytes ?>" data-ask="<?= htmlspecialchars($archiveTitle, ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($archiveTitle, ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($archiveTitle, ENT_QUOTES, 'UTF-8') ?>"><?= ui_icon('archive') ?></a><?php endif; ?><?= $lockHtml ?></span>
        <?php endif; ?>
        <?php if (ENABLE_SHARE_LINKS && is_admin_browser() && $currentFolderPath !== null && folder_is_private($dir, $currentFolderPath)): ?>
        <span class="map-combo"><button type="button" class="map-combo-btn page-share" data-gs-folder="<?= htmlspecialchars($currentFolderPath, ENT_QUOTES, 'UTF-8') ?>" data-gs-label="<?= htmlspecialchars(link_folder_label($dir, $currentFolderPath), ENT_QUOTES, 'UTF-8') ?>" title="Share this folder…" aria-label="Share this folder…"><?= ui_icon('share') ?></button></span>
        <?php endif; ?>
        <span class="map-combo"><a class="map-combo-btn page-about" href="?about" target="_blank" rel="noopener" title="About this gallery software (opens in a new tab)" aria-label="About this gallery software (opens in a new tab)"><?= ui_icon('gallery') ?></a></span>
      </span>
    </div>
  </div>
  <?php if ($hasMap): ?>
  <section class="map-preview<?= $fewCovers ?>" id="map-preview">
    <div class="map-preview-body" id="map-preview-body" data-map="trip-map-preview" hidden>
      <?= render_leaflet_map($mapPoints, $mapLegend, 'trip-map-preview', '300px', true, $mapRadius, true, true) ?>
      <button type="button" class="map-corner-link" id="map-peek-share" data-href="<?= htmlspecialchars($mapPeekHref, ENT_QUOTES, 'UTF-8') ?>" title="Copy a link that opens this page with this map showing" aria-label="Copy a link that opens this page with this map showing"><?= ui_icon('link') ?></button>
    </div>
  </section>
  <?php endif; ?>
<?= $lockForm ?>
<?php if (is_admin_browser() && $currentFolderPath !== null && AUTO_GROUP_BY_FOLDER && folder_restriction_cached($dir, $currentFolderPath) === 'private'): ?>
  <?php if (folder_unlisted_cached($dir, $currentFolderPath)): // A Not listed folder is not something to review before opening it up: one quiet line saying so, nothing to press. ?>
  <div class="folder-priv-banner fpb-note" id="folder-unlisted-note" role="note">
    <?= ui_icon('lock') ?>
    <div><strong>Not listed: only you can open this folder</strong></div>
  </div>
  <?php else: $privCounts = folder_privacy_counts($dir, $files, $currentFolderPath); ?>
  <?php // For the owner, on a private folder's own page: what making the folder public would do (the lock on each tile shows which is which). ?>
  <div class="folder-priv-banner" id="folder-priv-banner" role="button" tabindex="0" title="Make this folder public" data-noun="<?= ENABLE_VIDEO ? 'item' : 'photo' ?>" data-folder="<?= htmlspecialchars($currentFolderPath, ENT_QUOTES, 'UTF-8') ?>">
    <?= ui_icon('lock') ?>
    <div><strong>Private folder: visitors can't see it</strong>
      <span class="fpb-counts" aria-live="polite"><?= count_noun($privCounts['stay']) ?> will stay private &middot; <?= count_noun($privCounts['open']) ?> <?= (int) $privCounts['open'] === 1 ? 'becomes' : 'become' ?> public when you open it</span>
      <em class="fpb-hint">Make it public&hellip;</em></div>
  </div>
  <?php endif; ?>
<?php endif; ?>
<?php if (is_admin_browser() && AUTO_GROUP_BY_FOLDER): ?>
  <div class="fp-modal" id="fp-modal" role="dialog" aria-modal="true" aria-labelledby="fp-modal-title" data-noun="<?= ENABLE_VIDEO ? 'item' : 'photo' ?>" hidden>
    <div class="fp-modal-card">
      <h3 id="fp-modal-title">Make this folder public?</h3>
      <p class="fp-hint" id="fp-folder" style="margin:0 0 8px" hidden></p>
      <div class="fp-row fp-stay"><?= ui_icon('lock') ?><span><strong id="fp-stay">0 photos</strong> <span id="fp-stay-v">stay</span> private</span></div>
      <div class="fp-row fp-open"><?= ui_icon('unlock') ?><span><strong id="fp-open">0 photos</strong> <span id="fp-open-v">become</span> public</span></div>
      <p class="fp-hint">Anyone with the link will see those. Not right? Cancel, mark more private first, then come back.</p>
      <div class="fp-btns"><button type="button" id="fp-cancel">Cancel</button><button type="button" class="primary" id="fp-ok">Make public</button></div>
    </div>
  </div>
<?php endif; ?>
<?php if (is_admin_browser()): ?>
  <div class="fp-modal" id="addr-modal" role="dialog" aria-modal="true" aria-labelledby="addr-modal-title" hidden>
    <div class="fp-modal-card">
      <h3 id="addr-modal-title">Make a new link?</h3>
      <p class="fp-hint" style="margin:0 0 8px">Anyone who has this photo's present link will no longer be able to open it. The new link is copied for you.</p>
      <div class="fp-btns"><button type="button" id="addr-cancel">Cancel</button><button type="button" class="primary" id="addr-ok">New link</button></div>
    </div>
  </div>
<?php endif; ?>
<?php if ($hasMap): ?>
  <?php // The full map opens over the page: click outside it, press Escape or use the cross to close it. ?>
  <div class="map-modal" id="map-modal" data-map="trip-map-modal" role="dialog" aria-modal="true" aria-label="Map" hidden>
    <div class="map-modal-card">
      <button type="button" class="map-modal-close" aria-label="Close the map">&times;</button>
      <?= render_leaflet_map($mapPoints, $mapLegend, 'trip-map-modal', 'min(72vh, 720px)', true, $mapRadius, true) ?>
      <button type="button" class="map-corner-link" id="map-share" data-href="<?= htmlspecialchars($mapPageHref, ENT_QUOTES, 'UTF-8') ?>" title="Copy a link to this map" aria-label="Copy a link to this map"><?= ui_icon('link') ?></button>
    </div>
  </div>
<?php endif; ?>

<?php if (empty($files)): ?>
  <div class="empty">No media here yet.</div>
<?php else: ?>
  <div class="gallery<?= $fewCovers . $oneUp ?>">
    <?= build_gallery_html($groups, $groupColors, $groupStatus, $photoViews, $groupLabels, $explicitGroupKeys, $folderGroupKeys, $videoDurations, $folderCovers, $itemsData, $folderMeta, $folderLinkPrefix) ?>
  </div>
<?php endif; ?>


</main>
<?php if (is_admin_browser()): ?><div class="lightbox-toast" id="owner-toast" role="status" aria-live="polite" hidden></div><?php endif; ?>
<?php if (!empty($selectShown)): ?>
<div class="select-bar" id="select-bar" role="region" aria-label="Select <?= media_word() ?>" hidden>
  <div class="select-top">
    <span class="select-count" id="select-count" aria-live="polite" data-empty="Tap <?= media_word() ?> to select">Tap <?= media_word() ?> to select</span>
    <button type="button" data-select="all">Select all</button>
    <button type="button" data-select="clear">Clear</button>
    <button type="button" data-select="done" class="primary">Done</button>
  </div>
  <div class="select-btns">
    <button type="button" data-select="private" class="sb-private"><?= ui_icon('lock') ?> <span class="sb-n">Make private</span><span class="sb-p">Private</span></button>
    <button type="button" data-select="public" class="sb-public"><?= ui_icon('unlock') ?> <span class="sb-n">Make public</span><span class="sb-p">Public</span></button>
    <button type="button" data-select="inherit" class="sb-folder" title="Back to whatever the folder is: they follow it again" hidden><?= ui_icon('lock') ?> <span class="sb-lab">Folder</span></button>
<?php if (ENABLE_SHARE_LINKS): ?>
    <button type="button" data-select="share" class="sb-share" title="Make a share link for just these photos"><?= ui_icon('share') ?> Share&hellip;</button>
<?php endif; ?>
<?php if ($currentCollectionPage !== null): ?>
    <button type="button" data-select="collrm" class="sb-share sb-coll" title="Take these photos out of this collection (nothing is deleted)"><?= ui_icon('close') ?> <span class="sb-n">Remove from collection</span><span class="sb-p">Remove</span></button>
<?php elseif (!empty($selectCollections)): ?>
    <button type="button" data-select="coll" class="sb-share sb-coll" title="Add these photos to a collection"><?= ui_icon('gallery') ?> <span class="sb-n">Add to collection&hellip;</span><span class="sb-p">Collection&hellip;</span></button>
<?php endif; ?>
  </div>
  <div id="select-coll" hidden data-collection-page="<?= $currentCollectionPage !== null ? htmlspecialchars($currentCollectionPage['rec']['id'], ENT_QUOTES, 'UTF-8') : '' ?>" data-list="<?= htmlspecialchars(json_encode($selectCollections ?? [], JSON_HEX_TAG | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8') ?>"></div>
<?php if (!empty($arrangeShown)): ?>
  <div class="select-arr">
    <span class="select-hint">Hold and drag a picked <?= media_word() === 'media' ? 'one' : 'photo' ?> beside another.</span>
    <button type="button" data-select="release" title="Take the selected photos out of the places you gave them">Release</button>
    <button type="button" data-select="reset" title="Put every photo of this folder back in its sorted place">Reset folder</button>
  </div>
<?php endif; ?>
</div>
<?php endif; ?>
<div class="lightbox" id="lightbox">
  <button class="close" type="button" onclick="closeLightbox()" aria-label="Close"><?= ui_icon('close') ?></button>
  <button class="nav prev" type="button" onclick="navigate(-1)" aria-label="Previous"><?= ui_icon('chev-left') ?></button>
  <img id="lightbox-img" src="" alt="">
  <video id="lightbox-video" controls playsinline style="display:none;"></video>
<?php if (ENABLE_PHOTO_LOCATION): ?>
  <div class="lightbox-map" id="lightbox-map" hidden>
    <button type="button" class="lbmap-close" title="Close the big map" aria-label="Close the big map">&times;</button>
    <a class="lbmap-osm" href="https://www.openstreetmap.org/" target="_blank" rel="noopener noreferrer">Open in OpenStreetMap &#8599;</a>
  </div>
<?php endif; ?>
  <div class="lightbox-toast" id="lightbox-toast" role="status" aria-live="polite" hidden></div>
  <div class="caption-row">
    <div class="caption" id="lightbox-caption"></div>
    <div class="lightbox-tools-wrap" id="lightbox-tools-wrap">
    <div class="lightbox-tools" id="lightbox-tools">
<?php if (is_admin_browser()): ?>
    <button class="lightbox-copy owner-edit" id="lightbox-edit" type="button" title="Edit caption, link and location" aria-label="Edit caption, link and location"><?= ui_icon('edit') ?></button>
    <button class="lightbox-copy owner-cover" id="lightbox-cover" type="button" data-owner="cover" title="Make this the folder's cover photo" aria-label="Make this the folder's cover photo"><?= ui_icon('star') ?></button>
    <button class="lightbox-copy owner-priv" id="lightbox-private" type="button" data-owner="private" title="Make this private" aria-label="Make this private"><?= ui_icon('unlock') ?></button>
<?php endif; ?>
    <button class="lightbox-copy lightbox-zoom" id="lightbox-zoom" type="button" title="Fill the width of the screen" aria-label="Fill the width of the screen"><?= ui_icon('zoom-in') ?></button>
<?php $linkViewer = link_viewer(); if ($photoPass !== null ? viewer_may_download($dir, $photoPass, $itemsData) : (is_revealed() || ($linkViewer !== null ? ($linkViewer['downloads']['mode'] ?? 'off') !== 'off' : ENABLE_PUBLIC_DOWNLOAD))): ?>
    <a class="lightbox-copy lightbox-download" id="lightbox-download" href="#" download title="Download the original" aria-label="Download the original"><?= ui_icon('download') ?></a>
<?php endif; ?>
<?php if (is_admin_browser()): ?>
    <button class="lightbox-copy owner-newaddr" id="lightbox-newaddr" type="button" title="New link: the old one stops working" aria-label="New link: the old one stops working" hidden><?= ui_icon('linkoff') ?></button>
<?php endif; ?>
    <button class="lightbox-copy" id="lightbox-copy-btn" title="Copy link"><?= ui_icon('link') ?></button>
    <a class="lightbox-copy lightbox-web" id="lightbox-web" href="#" target="_blank" rel="noopener noreferrer" title="Information link" hidden><?= ui_icon('globe') ?></a>
<?php if (ENABLE_PHOTO_LOCATION): ?>
    <button class="lightbox-copy lightbox-map-toggle" id="lightbox-map-toggle" type="button" title="Show or hide the little map" hidden><?= ui_icon('pin') ?></button>
<?php endif; ?>
    </div>
    <span class="tools-more tools-more-l" aria-hidden="true"><?= ui_icon('chev-left') ?></span>
    <span class="tools-more tools-more-r" aria-hidden="true"><?= ui_icon('chev-right') ?></span>
    </div>
    <button class="lightbox-copy lightbox-close-row" type="button" onclick="closeLightbox()" title="Close" aria-label="Close"><?= ui_icon('close') ?></button>
  </div>
  <button class="nav next" type="button" onclick="navigate(1)" aria-label="Next"><?= ui_icon('chev-right') ?></button>
<?php if (is_admin_browser()): ?>
  <div class="lb-editor" id="lb-editor" role="dialog" aria-label="Edit this photo" hidden>
    <div class="lbe-grip" id="lbe-grip" aria-hidden="true"><div class="lbe-handle"></div></div>
    <div class="lbe-head">
      <span class="lbe-name" id="lbe-name"></span>
      <span class="lbe-status" id="lbe-status" role="status" aria-live="polite"></span>
      <button type="button" class="lbe-hidekb" id="lbe-hidekb"><?= ui_icon('chev-down') ?> Hide keyboard</button>
    </div>
    <div class="lbe-body">
      <label class="lbe-field"><span>Caption</span><input type="text" id="lbe-caption" maxlength="500" autocomplete="off" enterkeyhint="done"></label>
      <label class="lbe-field"><span>Information link</span><input type="url" id="lbe-web" inputmode="url" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" placeholder="https://&hellip;" enterkeyhint="done"><em class="lbe-err" id="lbe-web-err"></em></label>
      <div class="lbe-pills" id="lbe-pills">
        <button type="button" class="lbe-pill" id="lbe-private" aria-pressed="false" title="Hidden from visitors unless they have the private-view password"><?= ui_icon('lock') ?> Private</button>
        <button type="button" class="lbe-pill" id="lbe-loc" aria-pressed="false" title="Show this photo's location to visitors"><?= ui_icon('pin') ?> Location</button>
        <button type="button" class="lbe-pill" id="lbe-cover" aria-pressed="false" title="Make this the folder's cover photo"><?= ui_icon('star') ?> Cover</button>
      </div>
      <p class="lbe-note" id="lbe-note" hidden></p>
      <div class="lbe-loc">
        <button type="button" class="lbe-locrow" id="lbe-locrow" aria-expanded="false"><?= ui_icon('pin') ?><span id="lbe-locsum">No location</span><span class="lbe-chev"><?= ui_icon('chev-down') ?></span></button>
        <div class="lbe-locfields">
          <div class="lbe-two">
            <label class="lbe-field"><span>Latitude</span><input type="text" id="lbe-lat" inputmode="text" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" enterkeyhint="done"></label>
            <label class="lbe-field"><span>Longitude</span><input type="text" id="lbe-lng" inputmode="text" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" enterkeyhint="done"></label>
          </div>
          <em class="lbe-err" id="lbe-loc-err"></em>
          <label class="lbe-field"><span>Location label (optional)</span><input type="text" id="lbe-label" maxlength="200" autocomplete="off" enterkeyhint="done"></label>
          <p class="lbe-autohint" id="lbe-autohint" hidden>Shown as it is, nothing to save. Type a label to name the place, or change the numbers to set your own.</p>
        </div>
      </div>
    </div>
    <div class="lbe-foot">
      <button type="button" class="lbe-btn" id="lbe-prev"><?= ui_icon('chev-left') ?> Prev</button>
      <button type="button" class="lbe-btn primary" id="lbe-done">Done</button>
      <button type="button" class="lbe-btn" id="lbe-next">Next <?= ui_icon('chev-right') ?></button>
    </div>
  </div>
<?php endif; ?>
</div>
<?php if (is_admin_browser()) echo render_share_kit(); ?>

<script>
  const files = <?= json_encode($files) ?>;
  // A short tag per file that changes when the file is replaced — appended to
  // the full-size image / video address so a browser that cached the old
  // picture (they're cached for a year) fetches the new one.
  const fileVersions = <?= json_encode(array_map('file_version', $files)) ?>;
  // Admin-written captions only — never the raw filename. A visitor
  // curious enough to open dev tools shouldn't learn anything from a
  // filename an unrevealed caption-less photo never meant to publish.
  const captions = <?= json_encode(array_map(function ($f) use ($itemsData) {
      return (!empty($itemsData[$f]['caption'])) ? $itemsData[$f]['caption'] : null;
  }, $files)) ?>;
  // Where each file was taken, only for photos whose location is meant to be public and only when the map feature
  // is on (the little map in the viewer loads a third-party map, like the overview map does), and each file's web link.
  const locations = <?= json_encode(array_map(function ($f) use ($itemsData) {
      $entry = $itemsData[$f] ?? [];
      if (!ENABLE_PHOTO_LOCATION || !photo_location_visible($entry)) return null;
      return [(float) $entry['location']['lat'], (float) $entry['location']['lng']];
  }, $files)) ?>;
  const webLinks = <?= json_encode(array_map(function ($f) use ($itemsData) {
      $w = $itemsData[$f]['web'] ?? '';
      return preg_match('#^https?://#i', (string) $w) ? $w : null;
  }, $files)) ?>;
  // For the signed-in owner only: which files are private and which is its folder's cover (the tiles' stars and locks and the
  // viewer's two buttons are drawn from this, and kept in step when one is pressed).
  const ownerState = <?php
      if (is_admin_browser()) {
          $ownerPriv = []; $ownerOwn = []; $ownerInh = []; $ownerUnl = [];
          foreach ($files as $f) { $ps = owner_privacy_state($dir, $f, $itemsData); $ownerPriv[$f] = $ps['priv']; $ownerOwn[$f] = $ps['own']; $ownerInh[$f] = $ps['inh']; if (file_is_unlisted($dir, $f)) $ownerUnl[$f] = photo_address_code($dir, $f, $itemsData) ?: true; }   // (the address itself when it is known, so Copy needs no round trip)
          echo json_encode(['priv' => (object) $ownerPriv, 'own' => (object) $ownerOwn, 'inh' => (object) $ownerInh, 'unl' => (object) $ownerUnl, 'cover' => (object) owner_cover_flags($dir, $files)]);
      } else echo 'null';
  ?>;
  // Per file, for someone who is not the owner: whether the viewer's download button and copy-link button apply to it. A plain
  // link to a private photo opens nothing for anyone without access, and a download is offered per what the person's link
  // allows. null means "all of them".
  const downloadOk = <?php
      if ($photoPass !== null || (($linkViewer !== null || PUBLIC_DOWNLOAD_MODE === 'some') && !is_revealed())) echo json_encode(array_map(function ($f) use ($dir, $itemsData) { return viewer_may_download($dir, $f, $itemsData); }, $files));
      else echo 'null';
  ?>;
  const copyLinkOk = <?php
      if (!is_admin_browser()) echo json_encode(array_map(function ($f) use ($dir, $itemsData) { return !file_is_hidden($dir, $f, $itemsData); }, $files));
      else echo 'null';
  ?>;
  const initialPhoto = <?= json_encode($requestedPhoto) ?>;
  const galleryRootHref = <?= json_encode(gallery_root_href()) ?>;
  const passQ = <?= json_encode($photoPass !== null ? '&k=' . rawurlencode((string) $_GET['k']) : '') ?>;   // (on a photo's own address, the viewer's own requests for it carry the code too)
  const trackingEnabled = <?= TRACK_VIEWS ? 'true' : 'false' ?>;
  const showCountsPublicly = <?= view_counts_shown() ? 'true' : 'false' ?>;
