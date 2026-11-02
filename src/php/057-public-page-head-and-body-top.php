</style>
</head>
<body>
<div class="wrap">

  <div class="top-bar">
    <a class="back-link" href="<?= htmlspecialchars(gallery_root_href(), ENT_QUOTES, 'UTF-8') ?>">&larr; Back to gallery</a>
    <span class="version-tag">v<?= $version ?></span>
  </div>

  <h1>Drop-in Photo Gallery</h1>
  <p class="subtitle">One PHP file. No dependencies, no build step. Drop it into a folder of photos.</p>
  <a class="download-btn" href="<?= htmlspecialchars($repoUrl, ENT_QUOTES, 'UTF-8') ?>">&#11015; Get it on GitHub</a>

  <h2>For visitors</h2>
  <ul>
    <li><strong>Sharp thumbnails:</strong> each photo's tile is a square cut from its middle, made at two sizes so a phone gets a crisp one and a desktop a light one, loaded only as you scroll</li>
    <li><strong>A full-screen viewer</strong> with arrow keys and swipe, captions, links, a copy-link button, and a small map when the photo has a place; click or press Z to fill the width, again for real pixels</li>
    <li><strong>Built for phones:</strong> the counter and buttons sit in one row across the top, so the photo is as big as the screen allows and nothing needs tapping near Safari's toolbar; download opens the share sheet at once</li>
    <li><strong>Over a zoomed photo</strong> the controls sit on a soft dark pill so they stay readable, and each lights up when you point at it</li>
    <li>Video: length badges, silent hover clips and finger-scrubbing on the tiles, real playback in the viewer</li>
    <li>Trips as folders: cover cards with dates and counts, to any depth, and a viewer that stays inside the folder</li>
    <li>Optional maps, loaded only when someone opens one</li>
    <li>Sorting by name or date either way round, with videos, located photos or private items first; visitors can re-sort for themselves with one button</li>
    <li>Drag and drop to place photos next to each other (the church shots taken on other days), kept together under every sort</li>
    <li>Download the original (if allowed) or a whole folder as a ZIP, with hidden locations removed from what visitors get</li>
    <li>Shareable links to a photo, folder or map, with proper previews; end a gallery link with <code>#map</code> and it opens with the small map showing (and a link icon on that map copies it)</li>
  </ul>

  <h2>For the owner</h2>
  <ul>
    <li><strong>Set up in the browser:</strong> upload <code>index.php</code>, open <code>?admin</code>, type the one-time code from a small file the gallery makes beside it, and choose a password</li>
    <li><strong>An admin shell on a computer:</strong> a sidebar of folders, Features, Edited, Shares and Settings that stays put while one frame shows the page; on a phone, a bar at the bottom with Gallery, Folder, Notes and Menu</li>
    <li><strong>A Features page</strong> that lists everything you can switch on and where each one stands, and <strong>Settings</strong> in short folded groups that save as you flip them, with a health check that looks over the server for you</li>
    <li><strong>Edit right on the gallery:</strong> a pencil on every photo opens its caption, link, place, private and cover switches beside or under the photo, saved as you type; <em>Select</em> makes many photos private or public, or places them next to each other by drag and drop</li>
    <li><strong>A lock and a star on every tile</strong>, for you alone: one press makes a photo private or public, or its folder's cover</li>
    <li><strong>Go through a private folder before you open it:</strong> hide a folder, mark the photos that must stay private on their own, then make the folder public knowing what visitors will see; the Edited tab lists everything you set by hand</li>
    <li><strong>Put first is one list you order:</strong> switch on private items, videos and located photos and drag them into the order they stack in; a sentence says the result, and a folder can follow the site's list or have its own</li>
    <li><strong>Prepare media</strong> makes every photo's pictures, and reads what each file says about itself (when it was taken, where), ahead of time with a progress bar, so the first visit to a big folder isn't slow and nothing is read twice</li>
    <li>Markdown notes with <code>{photos}</code>, <code>{group}</code>, <code>{sold}</code> and <code>{reserved}</code>, for trips or for-sale listings</li>
    <li><strong>Files this gallery has made:</strong> see everything it has written, download it all as one backup ZIP, or remove what you no longer want, and nothing else can be touched</li>
    <li>On an iPhone, add the gallery to your Home Screen while signed in and it opens as an app with no browser bars</li>
  </ul>

  <h2>Share links for private things</h2>
  <ul>
    <li>Make a link from a private folder, a photo or a selection, with a name and an address from it (<code>.../johndoe</code>); it starts by letting 2 browsers in, which you can raise to as many as you like</li>
    <li>Limit it by browsers, by time and to chosen folders or photos; choose whether it may download, show locations or carry a note, and send it as an address or a QR code</li>
    <li>Preview exactly what it shows, read a log of who looked at what, and pause, extend or revoke it: it is checked on every request, so it stops at once</li>
    <li>Or close the public gallery altogether, so only you, private-view guests and people with a link see anything</li>
  </ul>

  <h2>Signing in</h2>
  <ul>
    <li>Every phone or computer that signs in gets its own token, lasting 3 days from its last visit by default; Settings lists them all and signs any one out, for a lost phone or a shared computer, without a new password for everyone</li>
    <li>Each one keeps the addresses it has been used from, and is flagged if it turns up as a different kind of device, from a new country, or from many networks in a day; each flag can sign it out by itself, if you switch that on</li>
    <li>An optional small country file (DB-IP, downloaded to your server, off until you turn it on) says which country an address is in; no visitor's address is ever sent anywhere</li>
  </ul>

  <h2>Careful by default</h2>
  <ul>
    <li>Writes only its own small files (thumbnails, settings, captions) and never touches your photos</li>
    <li>Locations, maps, video, subfolders, share links and passwords stay off until you turn them on, and the admin says what each one publishes</li>
    <li>Private items are removed outright for anyone without the password, and the sign-in box is built so browsers don't offer to save it</li>
    <li>A settings file that cannot be read hides its folder instead of opening it, and every save swaps in a whole file, so nothing is seen half-done</li>
    <li>It contacts no other site on its own, except the optional country file; a map, once someone opens one, loads its code and tiles from a CDN and OpenStreetMap</li>
  </ul>

  <h2>Documentation</h2>
  <p>Setup, settings and every feature: <a href="<?= htmlspecialchars($repoUrl) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($repoUrl) ?></a></p>

</div>
</body>
</html>
<?php
}

// Populated as note files are parsed below; read by the gallery further down.
$groupColors = [];
$groupStatus = [];
$groupLabels = [];

// Admin-set display names (see ?admin) seed $groupLabels here, before any
// {group:} directive in a .md gets its turn further down — a directive
// always wins over an admin-set name, the same precedent as any other
// explicit claim beating auto-detection (the admin UI disables the field
// in that case rather than accepting a value that then appears to do
// nothing). A folder-group's name lives in that folder's own
// .gallery-items.php; a root-level filename-based group's lives in the
// root's own.
$folderCovers = [];
$folderMeta = [];       // folder key => ['description' => .., 'dates' => ..], for its cover card
$folderMentionLinks = []; // group key => href, for a .md mention's jump link (overview mode only)
foreach ($folderGroupKeys as $folderKey => $_true) {
    $m = folder_display_meta($dir, $folderKey);
    if ($m['label'] !== '') {
        $groupLabels[$folderKey] = $m['label'];
    }
    if ($m['cover'] !== null) {
        $folderCovers[$folderKey] = $m['cover'];
    }
    $folderMeta[$folderKey] = ['description' => $m['description'], 'dates' => $m['dates']];
    if (FOLDER_DISPLAY_MODE === 'overview') {
        $folderMentionLinks[$folderKey] = '?folder=' . rawurlencode($folderKey);
    }
}
$rootItemsData = items_read($dir);
foreach (($rootItemsData['groups'] ?? []) as $groupKey => $groupMeta) {
    if (isset($folderGroupKeys[$groupKey]) || !isset($groups[$groupKey])) continue;
    if (!empty($groupMeta['displayName'])) {
        $groupLabels[$groupKey] = $groupMeta['displayName'];
    }
}

// Tracks which files an explicit {photos:} directive has already
// consumed *during rendering*, across every .md file, mirroring the
// same first-occurrence-wins rule already applied once during
// extraction (see GROUPING above) — without this, a file that lost a
// duplicate-claim conflict there would still pass render-time's own
// (otherwise unaware) validation and render a second time.
$renderClaims = [];

// Open Graph / Twitter Card data for link previews. A ?photo= deep link
// reaches the server (unlike a #fragment one — see "DEEP LINKING" above),
// so its preview can point at that specific photo and mention its group.
$baseUrl = current_base_url();

// The map's data — computed once here, reused both by a ?map link
// (the page with the overlay open) and the page's own map buttons
// (see the main template further down). Once $files/
// $itemsData/$fileToGroupKey/$folderGroupKeys/$groupLabels all reflect
// their final, hiding-filtered, admin-named state. A {group:} directive
// in a .md could still rename a group after this point (during notes
// rendering, further down) — a map legend showing a folder's raw name
// in that specific case is a reasonable, minor simplification rather
// than forcing notes processing to run early just for this.
$mapPoints = ENABLE_PHOTO_LOCATION ? collect_map_points($dir, $files, $itemsData, $fileToGroupKey, $folderGroupKeys, $groupLabels) : [];
// A map belongs to the page it is on: the root's covers everything, a folder's just what is inside it, and each
// has its own switch (the root's is on unless turned off, a folder's off unless turned on).
$mapShown = ENABLE_PHOTO_LOCATION && folder_map_enabled($dir, $currentFolderPath);
$mapPoints = map_points_for_folder($mapPoints, $dir, $currentFolderPath);
$mapLegend = map_legend_from_points($mapPoints);
$mapPageHref = '?map' . ($currentFolderPath !== null ? '&folder=' . rawurlencode($currentFolderPath) : '');
$mapPageHrefAbs = '/' . $mapPageHref;
$mapPeekHref = ($currentFolderPath !== null ? '?folder=' . rawurlencode($currentFolderPath) : '') . '#map';   // the page with its small map already open
$mapRadius = ENABLE_PHOTO_LOCATION ? folder_map_radius($dir, $currentFolderPath) : MAP_CLUSTER_RADIUS_MILES;

// ?map — a shareable link to the map: the gallery page (?map&folder=X: that folder's page) with the map overlay open.
if (ENABLE_PHOTO_LOCATION && isset($_GET['map'])) {
    if (!$mapShown) {
        http_response_code(404);
        exit;
    }
    // The map has no page of its own: ?map (and ?map&folder=X) is this gallery page with the map overlay open, so one
    // link shows the map over the very page it belongs to.
    $openMapOnLoad = true;
}

$ogTitle = $pageTitle;
$ogUrl = $baseUrl . '/';
$ogDescription = $rootMeta['description'] !== '' ? markdown_to_plaintext($rootMeta['description']) : (!empty($mdFiles)
    ? markdown_to_plaintext($mdContents[$mdFiles[0]])
    : media_count_label($files));
// The cover chosen in the admin, if it is still a visible root-level photo.
// With no cover chosen: the cover of one of the gallery's folders (the cover its owner chose, else its first photo), picked from the
// folders this requester may see (a link-preview crawler has no cookie, so never a private one) and the same one all day, so the
// link doesn't show a different picture in every chat. A gallery without folders uses one of its photos the same way. Never a video.
$ogVisible = array_flip($files);
$ogCovers = [];
foreach ($folderGroups as $ogTop => $ogMembers) {
    $ogMembers = array_values(array_filter($ogMembers, function ($f) use ($ogVisible) { return isset($ogVisible[$f]); }));
    $ogCover = $ogMembers ? pick_folder_cover($ogMembers, folder_display_meta($dir, $ogTop)['cover']) : null;
    if ($ogCover !== null && !is_video_file($ogCover)) $ogCovers[] = $ogCover;
}
$ogPhotos = array_values(array_filter($files, function ($f) { return !is_video_file($f); }));
$ogPool = $ogCovers ?: $ogPhotos;
$ogRootPhoto = ($rootMeta['cover'] !== null && in_array($rootMeta['cover'], $files, true)) ? $rootMeta['cover']
    : ($ogPool ? $ogPool[crc32(gmdate('Y-m-d') . '|' . $dir) % count($ogPool)] : ($files[0] ?? null));
$ogImageUrl = $ogRootPhoto !== null ? $baseUrl . '/' . img_url($ogRootPhoto, 'share') : '';
if ($currentFolderPath !== null) {
    // $pageTitle is already folder-prefixed (see the ?folder= validation
    // block above), so $ogTitle below already picked that up — only the
    // url/description/image need their own folder-scoped values here.
    $ogUrl = $baseUrl . '/?folder=' . rawurlencode($currentFolderPath);
    $folderMemberFiles = $currentFolderFiles;
    $ogDescription = media_count_label($folderMemberFiles);
    if ($currentFolderMeta['description'] !== '') {
        $ogDescription = markdown_to_plaintext($currentFolderMeta['description']);
    }
    // A subfolder's own notes .md (see the full scan further down, once
    // $groups/$files are actually scoped to it) can supply a real
    // description here too — just the plaintext summary, which doesn't
    // need the heavier mention/directive-aware pass that scan to feed
    // the visible note box does.
    $earlyFolderMdScan = scan_gallery_md_files(resolve_real_path($dir, $currentFolderPath));
    if (!empty($earlyFolderMdScan['files'])) {
        $ogDescription = markdown_to_plaintext($earlyFolderMdScan['contents'][$earlyFolderMdScan['files'][0]]);
    }
    if (!empty($folderMemberFiles)) {
        $ogImageUrl = $baseUrl . '/' . img_url(pick_folder_cover($folderMemberFiles, $currentFolderMeta['cover']), 'share');
    }
}
if ($requestedPhoto) {
    $ogImageUrl = $baseUrl . '/' . img_url($requestedPhoto, 'share');
    $ogUrl = $baseUrl . '/?photo=' . rawurlencode($requestedPhoto);
    $photoGroupKey = group_key($requestedPhoto);
    if (isset($groups[$photoGroupKey])) {
        $ogTitle = $photoGroupKey . ' — ' . $pageTitle;
    }
}
if (!empty($openMapOnLoad)) {
    // A shared ?map link previews as the map, not as the page behind it.
    $mapLabel = $currentFolderPath === null ? $rootPageTitle : $pageTitle;
    $ogTitle = $mapLabel . ' — Map';
    $pageTitle = $ogTitle;
    $ogUrl = $baseUrl . $mapPageHrefAbs;
    $ogDescription = count($mapPoints) . ' location' . (count($mapPoints) === 1 ? '' : 's') . ' plotted from this gallery.';
    $ogImageUrl = $baseUrl . '/?mapicon';
}
geo_maybe_refresh();   // (the country file, when that option is on: a look at one file's age; the work, if due, after the page has been sent)
send_viewer_aware_cache_headers();
?>
<!-- Drop-in Photo Gallery v<?= htmlspecialchars(GALLERY_VERSION) ?> — github.com/pcmike -->
<?php if (!empty($galleryNotices) && notices_visible()): ?>
<!--
gallery notices (also shown on the page itself, in a collapsed banner):
<?php foreach ($galleryNotices as $notice): ?>
  - <?= htmlspecialchars(str_replace('--', '- -', $notice), ENT_QUOTES, 'UTF-8') ?>

<?php endforeach; ?>
-->
<?php endif; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?> — Gallery</title>
<meta name="description" content="<?= htmlspecialchars($ogDescription) ?>">
<?= home_screen_app_tags($rootPageTitle) ?>
<?php if ($currentFolderPath !== null): ?><meta name="gallery-folder" content="<?= htmlspecialchars($currentFolderPath) ?>">
<?php endif; ?>
<meta property="og:type" content="website">
<meta property="og:title" content="<?= htmlspecialchars($ogTitle) ?>">
<meta property="og:description" content="<?= htmlspecialchars($ogDescription) ?>">
<meta property="og:url" content="<?= htmlspecialchars($ogUrl) ?>">
<?php if ($ogImageUrl): ?>
<meta property="og:image" content="<?= htmlspecialchars($ogImageUrl) ?>">
<meta name="twitter:card" content="summary_large_image">
<?php else: ?>
<meta name="twitter:card" content="summary">
<?php endif; ?>
<style>
