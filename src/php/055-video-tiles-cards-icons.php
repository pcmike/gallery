/** What makes a video's picture a video's picture wherever it is shown (a gallery tile, a strip of group thumbnails, a
 *  folder's cover, the admin's grid): the big play mark, the browser's own first frame where the server can't take one,
 *  and the addresses of the hover clip and of the frames a pointer or finger scrubs through. 'attrs' goes on the
 *  element that holds the picture (which also gets the class "vtile"), 'inner' inside it, after the <img>. A photo gets
 *  nothing of this. */
function video_tile_parts($file, $duration = null) {
    if (!is_video_file($file)) return ['attrs' => '', 'inner' => ''];
    $version = htmlspecialchars(file_version($file), ENT_QUOTES, 'UTF-8');
    $inner = '';
    // Where the server can't take a frame from a video, the browser's own first frame is laid over the placeholder (it
    // appears only if the browser can play the video; otherwise the placeholder stays).
    if (!video_pictures_expected() || video_poster_failed($file)) {
        $inner .= '<video class="tile-video" muted playsinline preload="metadata" tabindex="-1" aria-hidden="true" src="?video=' . rawurlencode($file) . '&amp;v=' . $version . '#t=0.1"></video>';
    }
    $inner .= '<span class="video-mark" aria-hidden="true">' . ui_icon('play') . '</span>';
    $attrs = '';
    // A tile carries its clip's address unless ffmpeg couldn't make one lately; "ready" says it already exists (so nothing
    // needs preparing when the page is opened).
    if (video_previews_expected()) {
        $clipFile = video_preview_file($file);
        if ($clipFile !== null && !(is_file($clipFile . '.failed') && !is_file($clipFile))) {
            $attrs .= ' data-preview="?preview=' . rawurlencode($file) . '&amp;v=' . $version . '"' . (is_file($clipFile) ? ' data-preview-ready="1"' : '');
        }
    }
    if (ENABLE_VIDEO_PREVIEWS && $duration !== null && $duration >= 1 && video_previews_expected()) {
        $stripBase = image_cache_path(containing_folder_real_path($GLOBALS['dir'], $file) . '/.gallery-cache', $file, 'strip2', resolve_real_path($GLOBALS['dir'], $file));
        if ($stripBase !== null && !(is_file($stripBase . '.failed') && !is_file($stripBase))) {
            [$cols, $rows] = video_strip_layout($duration);
            $attrs .= ' data-strip="?strip=' . rawurlencode($file) . '&amp;v=' . $version . '" data-strip-cols="' . $cols . '" data-strip-rows="' . $rows . '"'
                . (is_file($stripBase) ? ' data-strip-ready="1"' : '');
        }
    }
    return ['attrs' => $attrs, 'inner' => $inner];
}

/** One clickable gallery card. Shows a live-updatable view-count badge
 *  (hidden until >0); a small corner tag for a standalone sold/reserved
 *  item ($status is '', 'sold', or 'reserved' — a grouped item's ribbon
 *  is drawn on its parent box instead); and, for a standalone item whose
 *  matched {color: value} directive has nowhere else to apply (single-
 *  photo "groups" never get a box), a colored inset ring instead. A
 *  video's tile is this exact same markup — its <img> just resolves to
 *  the generic placeholder graphic (see render_video_placeholder_image)
 *  — plus a plain-text duration badge in the one corner not already
 *  spoken for, when $videoDuration is known. */
function render_card($file, $index, $photoViews, $status = '', $color = null, $anchorId = null, $videoDuration = null, $itemEntry = null) {
    $views = isset($photoViews[$file]) ? (int) $photoViews[$file] : 0;
    $hidden = (view_counts_shown() && ($views > 0 || (TRACK_VIEWS && is_admin_browser()))) ? '' : ' hidden';   // the owner sees every count, a zero too
    $badge = '<span class="view-badge' . $hidden . '" data-photo="' . htmlspecialchars($file, ENT_QUOTES, 'UTF-8') . '">' . ui_icon('eye') . ' '
           . number_format($views) . '</span>';
    $statusBadge = '';
    if ($status === 'sold') {
        $statusBadge = '<span class="sold-badge-corner">SOLD</span>';
    } elseif ($status === 'reserved') {
        $statusBadge = '<span class="reserved-badge-corner">RESERVED</span>';
    }
    $durationBadge = $videoDuration !== null
        ? '<span class="video-duration">' . htmlspecialchars(format_duration($videoDuration), ENT_QUOTES, 'UTF-8') . '</span>'
        : '';
    // Caption/location/web badges — bottom edge, right-justified,
    // extending the view-badge's own corner into a flex row (see
    // .card-badges) rather than stacking into a cramped cluster.
    $itemBadges = '';
    if (!empty($itemEntry['caption'])) {
        // A hover tooltip on desktop (title); a tap target that opens a
        // small popover on a touch screen, where hover doesn't exist.
        $itemBadges .= '<span class="item-badge caption-badge" title="' . htmlspecialchars($itemEntry['caption'], ENT_QUOTES, 'UTF-8')
                     . '" role="button" tabindex="0" onclick="toggleCaptionPopover(this, event)" onkeydown="if (event.key === \'Enter\' || event.key === \' \') toggleCaptionPopover(this, event)">' . ui_icon('caption') . '</span>';
    }
    if (photo_location_visible($itemEntry ?? [])) {
        $loc = $itemEntry['location'];
        $locTitle = trim(($loc['label'] ?? '') !== '' ? $loc['label'] : ($loc['lat'] . ', ' . $loc['lng']));
        $itemBadges .= '<a class="item-badge location-badge" href="' . htmlspecialchars(osm_location_url($loc['lat'], $loc['lng']), ENT_QUOTES, 'UTF-8')
                     . '" target="_blank" rel="noopener noreferrer" title="' . htmlspecialchars($locTitle, ENT_QUOTES, 'UTF-8')
                     . '" onclick="event.stopPropagation()">' . ui_icon('pin') . '</a>';
    }
    if (!empty($itemEntry['web'])) {
        $itemBadges .= '<a class="item-badge web-badge" href="' . htmlspecialchars($itemEntry['web'], ENT_QUOTES, 'UTF-8')
                     . '" target="_blank" rel="noopener noreferrer" title="Information link: ' . htmlspecialchars($itemEntry['web'], ENT_QUOTES, 'UTF-8')
                     . '" onclick="event.stopPropagation()">' . ui_icon('globe') . '</a>';
    }
    // The ring is drawn by .ringed::after, above the picture: an inset shadow on the card itself sat underneath it, unseen.
    $colorStyle = $color ? ' style="--ring: ' . htmlspecialchars($color, ENT_QUOTES, 'UTF-8') . ';"' : '';
    // $anchorId lets a standalone single be a valid "#group-<slug>" jump
    // target too — render_group_thumbs() always links there for a
    // mentioned item, whether it turned out to be a real box or just a
    // single card; only a box's own container carried that id before,
    // leaving the link dead for a single.
    $idAttr = $anchorId ? ' id="' . htmlspecialchars($anchorId, ENT_QUOTES, 'UTF-8') . '"' : '';
    $vt = video_tile_parts($file, $videoDuration);
    // For the signed-in owner: a lock on the tile showing whether it is private, and pressing it flips that.
    $ownerLock = '';
    if (is_admin_browser()) {
        $ps = owner_privacy_state($GLOBALS['dir'], $file, $GLOBALS['itemsData'] ?? []);
        $isPriv = $ps['priv']; $isFolder = $ps['own'] === '' && $ps['inh'];
        $lockTitle = owner_lock_title($ps);
        $ownerLock = '<button type="button" class="priv-toggle' . ($isPriv ? ' is-private' : '') . ($isFolder ? ' is-folder' : '') . '" data-file="' . htmlspecialchars($file, ENT_QUOTES, 'UTF-8') . '" aria-pressed="' . ($isPriv ? 'true' : 'false')
            . '" title="' . htmlspecialchars($lockTitle, ENT_QUOTES, 'UTF-8') . '">' . ui_icon($isPriv ? 'lock' : 'unlock') . ($isFolder ? '<span>Folder</span>' : ($isPriv ? '<span>Private</span>' : '')) . '</button>';
    }
    // And a star beside it: press to make this photo its folder's cover (a gold star when it already is).
    if ($ownerLock !== '') {
        $isCoverNow = !empty(owner_cover_flags($GLOBALS['dir'], [$file])[$file]);
        $ownerLock .= '<button type="button" class="cover-toggle' . ($isCoverNow ? ' is-cover' : '') . '" data-file="' . htmlspecialchars($file, ENT_QUOTES, 'UTF-8') . '" aria-pressed="' . ($isCoverNow ? 'true' : 'false')
            . '" title="' . ($isCoverNow ? 'This is the folder\'s cover photo' : 'Make this the folder\'s cover photo') . '">' . ui_icon('star') . '</button>';
        // And a pencil in the other lower corner: opens the viewer with the editor for this photo's caption, link, place and switches.
        $ownerLock .= '<button type="button" class="edit-toggle" data-file="' . htmlspecialchars($file, ENT_QUOTES, 'UTF-8')
            . '" title="Edit caption, link and location" aria-label="Edit caption, link and location">' . ui_icon('edit') . '</button>';
    }
    $ownerAttrs = $ownerLock !== '' ? ' data-file="' . htmlspecialchars($file, ENT_QUOTES, 'UTF-8') . '"' : '';
    if ($ownerLock !== '') $ownerLock .= '<span class="sel-mark" aria-hidden="true">' . ui_icon('check') . '</span>';
    return '<div class="card' . ($ownerLock !== '' ? ' owner-card' : '') . ($vt['inner'] !== '' ? ' vtile' : '') . ($status ? ' ' . $status : '') . ($color ? ' ringed' : '') . '"' . $ownerAttrs . $vt['attrs'] . $idAttr . $colorStyle . ' onclick="openLightbox(' . $index . ')"><img src="'
         . htmlspecialchars(img_url($file, 'thumb'), ENT_QUOTES, 'UTF-8')
         . '"' . tile_srcset_attrs($file) . ' loading="lazy" alt="' . htmlspecialchars($file, ENT_QUOTES, 'UTF-8') . '">' . $vt['inner'] . $ownerLock . $durationBadge . $statusBadge
         . '<div class="card-badges">' . $itemBadges . $badge . '</div>'
         . '</div>';
}

/** For the signed-in owner: which of these files is the cover photo of the folder it sits in (file => true/false), read
 *  from each folder's own settings, once per folder. */
function owner_cover_flags($dir, $files) {
    static $covers = [];
    $out = [];
    foreach ($files as $f) {
        $real = containing_folder_real_path($dir, $f);
        if (!array_key_exists($real, $covers)) {
            $c = items_read($real)['cover'] ?? null;
            $covers[$real] = is_string($c) && $c !== '' ? $c : null;
        }
        $out[$f] = $covers[$real] !== null && $covers[$real] === basename($f);
    }
    return $out;
}

/** Whether view counts are shown on this page: to everyone when the site setting says so, and always to the signed-in owner. */
function view_counts_shown() {
    return SHOW_VIEW_COUNTS_PUBLICLY || is_admin_browser();
}

/** The small line drawings used instead of emoji (so every device shows the same, clear picture): one set, drawn
 *  with the current text colour. ui_icon_sprite() prints them once per page; ui_icon() refers to one. */
function ui_icon_paths() {
    return [
        'map'     => '<path d="M9 4 3 6.5V20l6-2.5 6 2.5 6-2.5V4l-6 2.5z"/><path d="M9 4v13.5M15 6.5V20"/>',
        'sort'    => '<path d="M8 20V4M8 4 4.5 7.5M8 4l3.5 3.5M16 4v16M16 20l-3.5-3.5M16 20l3.5-3.5"/>',
        'pin'     => '<path d="M12 21s-6.5-5.4-6.5-10.5a6.5 6.5 0 1 1 13 0C18.5 15.6 12 21 12 21z"/><circle cx="12" cy="10.5" r="2.3"/>',
        'caption' => '<path d="M20.5 15.5a2 2 0 0 1-2 2H9l-5 3.5V6.5a2 2 0 0 1 2-2h12.5a2 2 0 0 1 2 2z"/><path d="M8 9.5h8M8 13h5"/>',
        'globe'   => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a14 14 0 0 1 0 18a14 14 0 0 1 0-18"/>',
        'link'    => '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
        'eye'     => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'check'   => '<path d="M4.5 12.5 9.5 17.5 19.5 7"/>',
        'refresh' => '<path d="M19.5 12a7.5 7.5 0 1 1-2.2-5.3"/><path d="M19.5 4.5v4h-4"/>',
        'warn'    => '<path d="M12 3.5 2.5 20h19z"/><path d="M12 10v4.5M12 17.4h.01"/>',
        'play'    => '<path d="M8 5.5v13l10.5-6.5z"/>',
        'star'    => '<path d="m12 3.5 2.6 5.5 6 .8-4.4 4.2 1.1 6-5.3-2.9-5.3 2.9 1.1-6L3.4 9.8l6-.8z"/>',
        'chart'   => '<path d="M5 20V11M12 20V4M19 20v-6"/>',
        'zoom-in' => '<circle cx="11" cy="11" r="6.5"/><path d="M20 20l-4.2-4.2M11 8.5v5M8.5 11h5"/>',
        'zoom-out' => '<circle cx="11" cy="11" r="6.5"/><path d="M20 20l-4.2-4.2M8.5 11h5"/>',
        'gallery' => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="1.7"/><path d="m21 16-5-5L7 20"/>',
        'lock'    => '<rect x="5" y="11" width="14" height="9.5" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
        'unlock'  => '<rect x="5" y="11" width="14" height="9.5" rx="2"/><path d="M8 11V8a4 4 0 0 1 7.6-1.7"/>',
        'expand'  => '<path d="M15 3h6v6M9 21H3v-6M21 3l-7.5 7.5M3 21l7.5-7.5"/>',
        'chev-left'  => '<path d="M15.25 5.5 8.75 12l6.5 6.5"/>',
        'chev-right' => '<path d="M8.75 5.5 15.25 12l-6.5 6.5"/>',
        'close'   => '<circle cx="12" cy="12" r="9"/><path d="M8.4 8.4l7.2 7.2M15.6 8.4l-7.2 7.2"/>',
        'download' => '<path d="M12 4v11M7.5 10.5 12 15l4.5-4.5M5 19.5h14"/>',
        'move'    => '<path d="M12 3v18M3 12h18M12 3 9 6M12 3l3 3M12 21l-3-3M12 21l3-3M3 12l3-3M3 12l3 3M21 12l-3-3M21 12l-3 3"/>',
        'archive' => '<path d="M3 7.5 5 4h14l2 3.5V19a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1z"/><path d="M3 7.5h18M12 11v5.5M9.4 14l2.6 2.6 2.6-2.6"/>',
        'edit'    => '<path d="M4 20l1-4L16.5 4.5a2.1 2.1 0 0 1 3 3L8 19z"/><path d="M14.5 6.5l3 3"/>',
        'select'  => '<circle cx="12" cy="12" r="9"/><path d="M8 12.5l3 3 5-6"/>',
        'pause'   => '<rect x="6.5" y="5" width="4" height="14" rx="1"/><rect x="13.5" y="5" width="4" height="14" rx="1"/>',
        'clock'   => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'ban'     => '<circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/>',
        'trash'   => '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12M9 7V4h6v3"/>',
        'chev-down' => '<path d="M5.5 8.75 12 15.25l6.5-6.5"/>',
        'jump-down' => '<path d="M12 4v11M7.5 10.5 12 15l4.5-4.5M5 19.5h14"/>',
        'jump-up' => '<path d="M12 20V9M7.5 13.5 12 9l4.5 4.5M5 4.5h14"/>',
        'share'   => '<path d="M12 15V4M7.5 8.5 12 4l4.5 4.5M5 13v5.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V13"/>',
        'qr'      => '<rect x="4" y="4" width="6.5" height="6.5" rx="1"/><rect x="13.5" y="4" width="6.5" height="6.5" rx="1"/><rect x="4" y="13.5" width="6.5" height="6.5" rx="1"/><path d="M14 14h2.5v2.5H14zM19.5 14v.01M14 19.5v.01M17 19.5h3.5M19.5 17v3.5"/>',
        'copy'    => '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 0 1 2-2h9"/>',
        'gear'    => '<path d="M12.2 2h-.4a2 2 0 0 0-2 2v.2a2 2 0 0 1-1 1.7l-.4.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.7.73l-.2.38a2 2 0 0 0 .73 2.7l.15.1a2 2 0 0 1 1 1.72v.5a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.7l.2.4a2 2 0 0 0 2.7.73l.15-.08a2 2 0 0 1 2 0l.4.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.4a2 2 0 0 0 2-2v-.2a2 2 0 0 1 1-1.7l.4-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.7-.73l.2-.4a2 2 0 0 0-.73-2.7l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.7l-.2-.38a2 2 0 0 0-2.7-.73l-.15.08a2 2 0 0 1-2 0l-.4-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
        'notes'   => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/>',
        'menu'    => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'logout'  => '<path d="M9.5 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h3.5M15.5 8l4 4-4 4M19.5 12H9.5"/>',
        'external' => '<path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
    ];
}
function ui_icon($name) {
    return '<svg class="ico" aria-hidden="true"><use href="#i-' . $name . '"></use></svg>';
}
function ui_icon_sprite() {
    $out = '<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">';
    foreach (ui_icon_paths() as $name => $paths) {
        $out .= '<symbol id="i-' . $name . '" viewBox="0 0 24 24">' . $paths . '</symbol>';
    }
    return $out . '</svg>';
}
function ui_icon_css() {
    return '.ico { width: 1.15em; height: 1.15em; vertical-align: -0.22em; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; flex: none; }';
}

/**
 * Resolves the accent color to actually use for a group: a {sold} or
 * {reserved} status always wins over a custom {color: value} — status
 * is the more important signal, and having the box/ring match it (not
 * just the ribbon) avoids sending two different color signals at once.
 */
function status_accent_color($status, $customColor) {
    if ($status === 'sold') return 'var(--sold)';
    if ($status === 'reserved') return 'var(--reserved)';
    return $customColor;
}

/**
 * A folder's highlight photo: the one the admin chose with "Set as cover"
 * (matched by filename among the folder's recursive files), else the
 * first actual photo — a video's generic placeholder makes a flat,
 * uninteresting highlight, so a video is used only if the folder has no
 * photos at all. Shared by the cover card and the folder's link preview
 * so the two can never disagree about which photo represents a folder.
 */
function pick_folder_cover($recursiveFiles, $manualCover = null) {
    if ($manualCover !== null) {
        foreach ($recursiveFiles as $f) {
            if (basename($f) === $manualCover) return $f;
        }
    }
    foreach ($recursiveFiles as $f) {
        if (!is_video_file($f)) return $f;
    }
    return $recursiveFiles[0] ?? null;
}

/**
 * A folder-group's cover card in 'overview' mode: one highlight photo
 * (the first in natural order — $recursiveFiles is already sorted this
 * way by the scan), the label, and the recursive photo count across the
 * whole subtree ($recursiveFiles already *is* that full recursive list,
 * so no separate counting pass is needed). The copy-link icon is a
 * sibling of the navigation link, not nested inside it — two <a> tags,
 * never one inside the other. $manualCover (a bare filename from the
 * folder's own .gallery-items.php — see ?admin's "Set as cover")
 * overrides the automatic choice when it's still a real member of this
 * folder's own recursive file list.
 */
/** What a set of items is called when counted for a person: "3 photos",
 *  "1 video", or "2 photos · 1 video". A video is not a photo, and a folder
 *  of clips said "1 photo" before this. */
function media_count_label($files) {
    $videos = 0;
    foreach ($files as $f) { if (is_video_file($f)) $videos++; }
    $photos = count($files) - $videos;
    $parts = [];
    if ($photos > 0) $parts[] = number_format($photos) . ' photo' . ($photos === 1 ? '' : 's');
    if ($videos > 0) $parts[] = number_format($videos) . ' video' . ($videos === 1 ? '' : 's');
    return $parts ? implode(' · ', $parts) : '0 photos';
}

function render_folder_cover_card($folderPath, $label, $recursiveFiles, $manualCover = null, $idKey = null, $meta = null, $status = '', $accentColor = null) {
    global $dir;
    $priv = owner_folder_privacy($dir, $recursiveFiles, $folderPath);   // the owner's lock and count; empty for everyone else
    $folderUrl = htmlspecialchars('?folder=' . rawurlencode($folderPath), ENT_QUOTES, 'UTF-8');
    $coverFile = pick_folder_cover($recursiveFiles, $manualCover);
    $count = count($recursiveFiles);
    $slug = slugify($idKey ?? $folderPath);
    // The paragraph that mentions a folder can mark it sold or reserved, or give it a colour, like any other group.
    $style = $accentColor ? ' style="box-shadow: 0 0 0 3px ' . htmlspecialchars($accentColor, ENT_QUOTES, 'UTF-8') . ';"' : '';
    $html = '<div class="folder-cover' . ($status ? ' ' . $status : '') . ($priv['state'] !== '' ? ' has-priv' : '') . '" id="group-' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') . '"' . $style . '>';
    $coverParts = $coverFile !== null ? video_tile_parts($coverFile) : ['attrs' => '', 'inner' => ''];
    $html .= '<a class="folder-cover-link' . ($coverParts['inner'] !== '' ? ' vtile' : '') . '" href="' . $folderUrl . '">';
    if ($status === 'sold') $html .= '<span class="sold-badge-corner">SOLD</span>';
    elseif ($status === 'reserved') $html .= '<span class="reserved-badge-corner">RESERVED</span>';
    // Only the owner sees this: solid red when the folder is private on its own, dashed when only a folder above it is.
    $pill = '';
    if ($priv['state'] === 'own') $pill = '<button type="button" class="folder-priv own" data-folder="' . htmlspecialchars($folderPath, ENT_QUOTES, 'UTF-8') . '" title="Private folder: press to make it public">' . ui_icon('lock') . 'Private</button>';
    elseif ($priv['state'] === 'inh') $pill = '<span class="folder-priv inh" title="Hidden because a folder above it is private">' . ui_icon('lock') . 'Folder</span>';
    if ($coverFile !== null) {
        // The picture is a square cut from the original photo (see generate_cover_jpeg): a small one for ordinary cards, and
        // a larger one offered too so a big card, or a sharp screen, takes it. The browser picks by the card's width.
        $coverSmall = htmlspecialchars(img_url($coverFile, 'cover'), ENT_QUOTES, 'UTF-8');
        $coverLarge = htmlspecialchars(img_url($coverFile, 'coverlg'), ENT_QUOTES, 'UTF-8');
        $sizes = !empty($GLOBALS['__big_covers']) ? '(min-width: 640px) 520px, 100vw' : '(min-width: 640px) 330px, 100vw';
        $html .= '<img src="' . $coverSmall . '" srcset="' . $coverSmall . ' 640w, ' . $coverLarge . ' 1200w" sizes="' . $sizes
                . '" loading="lazy" alt="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '">' . $coverParts['inner'];
    }
    $html .= '<span class="folder-cover-label">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
    if (!empty($meta['dates'])) {
        $html .= '<span class="folder-cover-dates">' . htmlspecialchars($meta['dates'], ENT_QUOTES, 'UTF-8') . '</span>';
    }
    if ($priv['stay'] > 0) {
        // The count of what is private inside is a link of its own (so the card's link ends first): to the folder with those first.
        $privHref = htmlspecialchars(folder_private_first_href($dir, $folderPath), ENT_QUOTES, 'UTF-8');
        $n = (int) $priv['stay'];
        $privText = number_format($n) . ($priv['state'] === 'own' ? ($n === 1 ? ' stays private' : ' stay private') : ' private');
        $html .= '</a><span class="folder-cover-count has-priv-count"><a class="fc-main" href="' . $folderUrl . '">' . htmlspecialchars(media_count_label($recursiveFiles), ENT_QUOTES, 'UTF-8')
               . '</a> <a class="fc-priv" href="' . $privHref . '" title="Open the folder with those first">(' . $privText . ui_icon('chev-right') . ')</a></span>';
    } else {
        $html .= '<span class="folder-cover-count">' . htmlspecialchars(media_count_label($recursiveFiles), ENT_QUOTES, 'UTF-8') . '</span>';
        $html .= '</a>';
    }
    $html .= $pill;
    // A plain link to a private folder opens nothing for anyone without access, so only the owner is offered one there.
    if (ENABLE_SHARE_LINKS && is_admin_browser() && folder_is_private($dir, $folderPath)) {
        // for the owner, on a private folder, the link icon asks what to share: a plain link opens nothing for anyone else
        $html .= '<a class="permalink-copy folder-permalink-copy" href="' . $folderUrl . '" title="Share this folder…" aria-label="Share this folder…" data-gs-folder="' . htmlspecialchars($folderPath, ENT_QUOTES, 'UTF-8') . '" data-gs-label="' . htmlspecialchars(link_folder_label($dir, $folderPath), ENT_QUOTES, 'UTF-8') . '">' . ui_icon('link') . '</a>';
    } elseif (is_admin_browser() || !folder_is_private($dir, $folderPath)) $html .= '<a class="permalink-copy folder-permalink-copy" href="' . $folderUrl . '" title="Copy link">' . ui_icon('link') . '</a>';
    $html .= '</div>';
    return $html;
}

/** What a Flat folder carries besides its name: the description typed in Folder settings, its dates, and (when Show folder notes is on)
 *  its notes text; and from its own notes file the {color:} and {sold}/{reserved} that style its box (the page's own notes, which name
 *  the folder, win over these). Read once per folder per request. */
function flat_folder_own($folderRel) {
    static $memo = [];
    if (isset($memo[$folderRel])) return $memo[$folderRel];
    $dir = $GLOBALS['dir'];
    $meta = folder_display_meta($dir, $folderRel);
    $out = ['desc' => trim($meta['description']), 'dates' => trim($meta['dates']), 'notes' => '', 'color' => null, 'status' => ''];
    $md = scan_gallery_md_files(resolve_real_path($dir, $folderRel));
    $raw = implode("\n\n", $md['contents']);
    if ($raw !== '') {
        if (preg_match('/\{color:\s*([^}]+)\}/i', $raw, $m) && preg_match('/^(#[0-9a-fA-F]{3,8}|[a-zA-Z]{3,20}|rgba?\([0-9 ,.%]+\))$/', trim($m[1]))) $out['color'] = trim($m[1]);
        if (preg_match('/\{(sold|reserved)\}/i', $raw, $m)) $out['status'] = strtolower($m[1]);
        if (flat_notes_on()) {
            $paras = [];
            foreach (preg_split('/\n\s*\n/', $raw) as $p) {
                $p = trim(preg_replace('/\{[a-z]+(:[^}]*)?\}/i', '', $p));   // (directives style photos; they are not words)
                $p = trim(preg_replace('/^#{1,6}\s*/m', '', $p));
                if ($p !== '') $paras[] = '<p>' . nl2br(inline_markdown($p), false) . '</p>';
            }
            $out['notes'] = implode('', $paras);
        }
    }
    return $memo[$folderRel] = $out;
}

/** The description and notes under a Flat folder's label or divider (nothing when it has neither). */
function flat_extras_html($folderRel, $class = 'group-extra') {
    $o = flat_folder_own($folderRel);
    if ($o['desc'] === '' && $o['notes'] === '') return '';
    return '<div class="' . $class . '">' . ($o['desc'] !== '' ? '<p class="gd-desc">' . htmlspecialchars($o['desc'], ENT_QUOTES, 'UTF-8') . '</p>' : '') . ($o['notes'] !== '' ? '<div class="gd-notes">' . $o['notes'] . '</div>' : '') . '</div>';
}

/** A label that copies a link to its own place when pressed (Flat with folder names): the folder's name, with a small link icon that shows
 *  on hover. $anchorId is the element's id on the page; the script turns the press into a copied address and a brief "Link copied". */
function flat_label_button($anchorId, $textHtml, $title) {
    return '<button type="button" class="label-copy" data-anchor="' . htmlspecialchars($anchorId, ENT_QUOTES, 'UTF-8') . '" title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '"><span class="lc-t">' . $textHtml . '</span></button>';
}

/** What a Flat folder's first element on the page carries so the row at the top can have a pill that goes to it: its name and, for the
 *  owner, whether it is private. (A visitor's pills come from the boxes that Show folder names makes; the owner also gets them without.) */
function flat_sec_attrs($label, $folderPath) {
    $priv = owner_folder_privacy($GLOBALS['dir'], [], $folderPath, false)['state'];
    return ' data-sec="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '"' . ($priv !== '' ? ' data-sec-priv="' . $priv . '"' : '');
}

/** The divider row inside a Flat box for a folder below it: its parent folders in muted text, then its own name, on one line, with the path
 *  measured from the box ($boxKey, the top folder). $sub is the folder's path from the gallery root. */
function flat_divider_html($boxKey, $sub, $boxSlug) {
    $dir = $GLOBALS['dir'];
    $rel = array_values(array_filter(explode('/', substr($sub, strlen($boxKey) + 1)), 'strlen'));
    if (!$rel) return '';
    $name = array_pop($rel);
    $meta = folder_display_meta($dir, $sub);
    $label = $meta['label'] !== '' ? $meta['label'] : $name;
    $path = '';
    foreach ($rel as $p) $path .= htmlspecialchars($p, ENT_QUOTES, 'UTF-8') . ' &rsaquo; ';
    $id = 'group-' . $boxSlug . '-' . slugify(implode('-', explode('/', substr($sub, strlen($boxKey) + 1))));
    $own = flat_folder_own($sub);
    return '<div class="group-divider" id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '">'
        . flat_label_button($id, ($path !== '' ? '<span class="gd-path">' . $path . '</span>' : '') . '<span class="gd-name">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>', 'Copy a link to ' . $label)
        . ($own['dates'] !== '' ? '<span class="gd-dates">' . htmlspecialchars($own['dates'], ENT_QUOTES, 'UTF-8') . '</span>' : '') . '</div>' . flat_extras_html($sub);
}

/** Builds the whole gallery section: boxed groups (with optional per-group
 *  accent color, sold/reserved ribbon, and a live-updatable total view
 *  count) + a plain row for standalone singles. */
function build_gallery_html($groups, $groupColors, $groupStatus, $photoViews, $groupLabels = [], $explicitGroupKeys = [], $folderGroupKeys = [], $videoDurations = [], $folderCovers = [], $itemsData = [], $folderMeta = [], $folderLinkPrefix = '') {
    $html = '';
    $flatIndex = 0;

    foreach ($groups as $key => $groupFiles) {
        // A folder-group in 'overview' mode renders as a cover card — one
        // highlight photo, a label, a recursive photo count — not a full
        // inline grid; drilling into it happens on its own page
        // (?folder=<path>), not in the lightbox, so none of its members'
        // lightbox indices are assigned here. $flatIndex still has to
        // advance by the group's full member count regardless, to stay
        // in sync with $files — the global flat list every other
        // rendered card's lightbox index is drawn from.
        if (isset($folderGroupKeys[$key]) && !folder_style_flat()) {
            $label = $groupLabels[$key] ?? $key;
            $coverStatus = $groupStatus[$key] ?? '';
            $html .= render_folder_cover_card($folderLinkPrefix . $key, $label, $groupFiles, $folderCovers[$key] ?? null, $key, $folderMeta[$key] ?? null, $coverStatus, status_accent_color($coverStatus, $groupColors[$key] ?? null));
            $flatIndex += count($groupFiles);
            continue;
        }

        // Flat without folder names: the folder's photos are part of the page's one grid, no box and no label.
        $isFlatFolder = isset($folderGroupKeys[$key]) && folder_style_flat();
        if ($isFlatFolder && !flat_names_on()) {
            foreach ($groupFiles as $i => $file) {
                // (the owner's row of pills still has one for this folder: its first photo is what a press scrolls to)
                $first = $i === 0 && is_admin_browser();
                $card = render_card($file, $flatIndex, $photoViews, '', null, $first ? 'group-' . slugify($key) : null, $videoDurations[$file] ?? null, $itemsData[$file] ?? null);
                $html .= $first ? preg_replace('/^<div /', '<div' . flat_sec_attrs($groupLabels[$key] ?? $key, $folderLinkPrefix . $key) . ' ', $card, 1) : $card;
                $flatIndex++;
            }
            continue;
        }

        $flatPath = $folderLinkPrefix . $key;   // (the folder's path from the gallery root; on a folder's own page the keys are its children's names)
        $flatOwn = $isFlatFolder ? flat_folder_own($flatPath) : null;
        $status = $groupStatus[$key] ?? ($flatOwn['status'] ?? '');
        $accentColor = status_accent_color($status, $groupColors[$key] ?? ($flatOwn['color'] ?? null));
        // An auto-detected single (one file, no explicit {group:} naming
        // it) never gets its own box. An explicitly named group always
        // does, even with just one photo — that's the point of naming
        // it. $groups is already ordered boxes-first by this point (see
        // compute_zone_order), so every single/cluster card below is
        // part of one uninterrupted run — no box ever lands in the
        // middle of it to fragment the grid.
        $slug = slugify($key);
        if (count($groupFiles) === 1 && !isset($explicitGroupKeys[$key]) && !$isFlatFolder) {   // (a folder shown with its name gets its box even for one photo)
            $html .= render_card($groupFiles[0], $flatIndex, $photoViews, $status, $accentColor, 'group-' . $slug, $videoDurations[$groupFiles[0]] ?? null, $itemsData[$groupFiles[0]] ?? null);
            $flatIndex++;
            continue;
        }
        $label = $groupLabels[$key] ?? $key;

        $boxStyle = '';
        $labelStyle = '';
        if ($accentColor) {
            $color = htmlspecialchars($accentColor, ENT_QUOTES, 'UTF-8');
            $boxStyle = ' style="border-left-color: ' . $color . ';"';
            $labelStyle = ' style="color: ' . $color . ';"';
        }

        $groupTotal = 0;
        foreach ($groupFiles as $gf) {
            $groupTotal += isset($photoViews[$gf]) ? (int) $photoViews[$gf] : 0;
        }
        $viewsHidden = (view_counts_shown() && ($groupTotal > 0 || (TRACK_VIEWS && is_admin_browser()))) ? '' : ' hidden';

        // A Flat folder's box says what it is called (and, for the owner, whether it is private) so the row at the top can have a pill that goes to it.
        $secAttrs = $isFlatFolder ? flat_sec_attrs($label, $flatPath) : '';
        $html .= '<div class="group-box' . ($status ? ' ' . $status : '') . '"' . $boxStyle . $secAttrs . ' id="group-' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') . '">';
        $labelText = $isFlatFolder
            ? flat_label_button('group-' . $slug, htmlspecialchars($label, ENT_QUOTES, 'UTF-8'), 'Copy a link to ' . $label)
            : htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        $html .= '<h2 class="group-label"' . $labelStyle . '>' . $labelText
                . ($isFlatFolder && $flatOwn['dates'] !== '' ? ' <span class="gd-dates">' . htmlspecialchars($flatOwn['dates'], ENT_QUOTES, 'UTF-8') . '</span>' : '')
                . ' <span class="group-views' . $viewsHidden . '" data-group="' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') . '">&middot; '
                . number_format($groupTotal) . ' view' . ($groupTotal === 1 ? '' : 's') . '</span> '
                . ($isFlatFolder ? '' : render_permalink_button('group-' . $slug)) . '</h2>';
        if ($isFlatFolder) $html .= flat_extras_html($flatPath, 'group-extra box-extra');
        $html .= '<div class="group-grid">';
        $prevSub = $flatPath;
        foreach ($groupFiles as $file) {
            if ($isFlatFolder) {
                // A divider each time the photos move to another folder inside this box (the box's own photos come first and need none).
                $sub = dirname($file);
                if ($sub !== $prevSub) { $prevSub = $sub; if ($sub !== $flatPath) $html .= flat_divider_html($flatPath, $sub, $slug); }
            }
            $html .= render_card($file, $flatIndex, $photoViews, '', null, null, $videoDurations[$file] ?? null, $itemsData[$file] ?? null);
            $flatIndex++;
        }
        $html .= '</div></div>';
    }
    return $html;
}

/**
 * Where "back to the gallery" points. Normally just "./", which resolves
 * to the gallery's own folder. But if the web server maps a pretty folder
 * link (/kb/Europe/France/) onto this script, "./" would resolve to that
 * folder's own page instead — the back-link would go nowhere. In that
 * case, point at the script's real directory explicitly. Every other
 * relative link on a page (?img=, ?folder=, ?about, #anchors) resolves
 * correctly from a pretty URL because the query string rides along.
 */
function gallery_root_href() {
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $scriptDir = rtrim(dirname($script), '/') . '/';
    if ($script === '' || !is_string($requestPath) || $requestPath === $script || $requestPath === $scriptDir) {
        return './';
    }
    return implode('/', array_map('rawurlencode', explode('/', $scriptDir)));
}

/** What makes "Add to Home Screen" open the gallery as an app with no browser bars at all (the phone's address bar and
 *  toolbar, which on an iPhone can't be hidden from a web page, are what keep popping up). Only the pages of someone who is
 *  signed in (the owner, or a viewer with the private-view password) carry it; nothing changes in an ordinary visitor's tab. */
function home_screen_app_tags($title) {
    if (!is_revealed()) return '';
    $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $root = htmlspecialchars(gallery_root_href(), ENT_QUOTES, 'UTF-8');
    return '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n"
         . '<meta name="mobile-web-app-capable" content="yes">' . "\n"
         . '<meta name="apple-mobile-web-app-status-bar-style" content="black">' . "\n"
         . '<meta name="apple-mobile-web-app-title" content="' . $t . '">' . "\n"
         . '<meta name="theme-color" content="#0f0f10">' . "\n"
         . '<link rel="apple-touch-icon" href="' . $root . '?appicon&amp;v=2">' . "\n"
         . '<link rel="manifest" href="' . $root . '?manifest&amp;name=' . htmlspecialchars(rawurlencode($title), ENT_QUOTES, 'UTF-8') . '">' . "\n";
}

/** Builds the current request's scheme://host/path, for absolute OG image URLs. */
function current_base_url() {
    $scheme = is_https_request() ? 'https' : 'http';
    // The Host header is whatever the client sent. It ends up in absolute
    // URLs (link previews), so only accept something shaped like a host
    // (name or IP, optional port, IPv6 in brackets) and otherwise fall back
    // to the server's own name — never echo arbitrary text into a URL.
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (!preg_match('/^(?:[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?|\[[0-9A-Fa-f:.]+\])(?::\d{1,5})?$/', $host)) {
        $host = $_SERVER['SERVER_NAME'] ?? 'localhost';
    }
    $path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');   // (not PHP_SELF: that carries any extra path the visitor put after index.php)
    return $scheme . '://' . $host . $path;
}

/**
 * Renders the "?about" page: a standalone documentation page (with a
 * link to the GitHub repo at the top) explaining everything this
 * gallery does, for anyone who found it deployed somewhere and wants to
 * use it themselves. Kept as a self-contained HTML string rather than a
 * .md-style file so it never depends on anything else in the folder.
 */
function render_about_page() {
    $version = htmlspecialchars(GALLERY_VERSION, ENT_QUOTES, 'UTF-8');
    $aboutUrl = htmlspecialchars(current_base_url() . '/?about', ENT_QUOTES, 'UTF-8');
    $repoUrl = 'https://github.com/pcmike/gallery';
    $aboutDescription = 'A single-file, drop-in PHP photo gallery for trips and listings: folders, video, captions, optional maps, Markdown notes, a browser-based admin, and shareable links.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>About this gallery — v<?= $version ?></title>
<meta name="description" content="<?= htmlspecialchars($aboutDescription) ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="Drop-in Photo Gallery — v<?= $version ?>">
<meta property="og:description" content="<?= htmlspecialchars($aboutDescription) ?>">
<meta property="og:url" content="<?= $aboutUrl ?>">
<meta name="twitter:card" content="summary">
<style>
