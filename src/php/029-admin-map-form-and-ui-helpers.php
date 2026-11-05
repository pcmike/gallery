/** The "Map" section of a folder's (or the gallery's) settings: whether its page
 *  shows a map of its own photos, and how close photos must be to share a pin.
 *  Both are per page; a blank radius means the site-wide setting. */
function render_admin_map_form($itemsHere, $csrfToken, $isRoot) {
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $on = $isRoot ? (($itemsHere['mapEnabled'] ?? null) !== false) : (($itemsHere['mapEnabled'] ?? null) === true);
    $radius = $itemsHere['mapRadius'] ?? null;
?>
    <?php if (ENABLE_PHOTO_LOCATION): ?>
      <form method="post" class="instant-form map-form">
        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
        <input type="hidden" name="action" value="save_map_settings">
        <input type="hidden" name="map_enabled" value="0">
        <div class="instant-row">
          <label class="switch"><input type="checkbox" name="map_enabled" value="1" aria-label="Show a map of <?= $isRoot ? 'the whole gallery' : 'this folder' ?>" <?= $on ? 'checked' : '' ?>><span class="slider"></span></label>
          <span class="instant-label">Show a map of <?= $isRoot ? 'the whole gallery on its front page' : 'this folder\'s photos on its page' ?></span>
          <span class="rowstate" role="status" aria-live="polite"></span>
        </div>
        <div class="warn-note">Visitors' browsers load code from cdnjs and tiles from OpenStreetMap, so those sites see the visit. Only photos with a visible location are plotted.</div>
        <p class="hint" style="margin:0 0 10px;"><?= $isRoot ? 'Covers every photo in the gallery with a visible location.' : 'Covers this folder\'s photos and subfolders with a visible location (needs this folder\'s location switch).' ?></p>
        <?php // Pin grouping is part of the map: set in under it, and only there while the map is on. ?>
        <div class="map-sub"<?= $on ? '' : ' hidden' ?>>
          <div class="field" style="max-width:300px;">
            <label>Pin grouping (miles)</label>
            <input type="number" name="map_radius" min="0" max="10000" step="any" value="<?= $radius === null ? '' : $esc($radius) ?>" placeholder="<?= (int) MAP_CLUSTER_RADIUS_MILES ?>">
            <span class="rowstate" role="status" aria-live="polite"></span>
          </div>
          <p class="hint">Photos closer than this always share one numbered pin; pressing it zooms in (pins that would overlap on screen also merge until you zoom in). <strong>0 turns grouping off.</strong> Blank uses the site setting (<?= $esc(MAP_CLUSTER_RADIUS_MILES) ?>).</p>
        </div>
        <div class="nojs-only"><button type="submit" class="secondary">Save map settings</button></div>
      </form>
    <?php endif; ?>
<?php
}

/** The identity form — title, dates, description (and position, for a
 *  folder) — shared by a folder's settings and the gallery's own, so the root
 *  is configured the same way a folder is. */
/** A date span as a person would write it: "Oct 11th, 2026" for one day, "Oct 1-11th, 2026" inside one month, and
 *  "12/30/2025 - 10/09/2026" when it crosses a month. */
function format_date_range($min, $max) {
    $ordinal = function ($n) {
        $n = (int) $n;
        $suffix = ($n % 100 >= 11 && $n % 100 <= 13) ? 'th' : ([1 => 'st', 2 => 'nd', 3 => 'rd'][$n % 10] ?? 'th');
        return $n . $suffix;
    };
    if (gmdate('Y-m', $min) === gmdate('Y-m', $max)) {
        $month = gmdate('M', $min);
        $year = gmdate('Y', $min);
        $d1 = (int) gmdate('j', $min);
        $d2 = (int) gmdate('j', $max);
        return $d1 === $d2 ? $month . ' ' . $ordinal($d1) . ', ' . $year : $month . ' ' . $d1 . '-' . $ordinal($d2) . ', ' . $year;
    }
    return gmdate('m/d/Y', $min) . ' - ' . gmdate('m/d/Y', $max);
}

/**
 * What dates do a folder's photos span? Offered to the admin as a suggestion for the folder's Dates field and never
 * published unless they save it. Uses each photo's own capture date (JPEG or HEIC) when it has one, else the file's modified date.
 * Looks at no more than $cap photos, so a huge folder can't slow the admin down. Returns null with nothing to go on.
 */
function suggest_folder_dates($dir, $fileList, $cap = 300) {
    $times = [];
    $fromExif = 0;
    $looked = 0;
    foreach ($fileList as $f) {
        if (is_video_file($f)) continue;
        if (++$looked > $cap) break;
        $real = resolve_real_path($dir, $f);
        if (!$real || !is_file($real)) continue;
        // The capture date the gallery already recorded for this file, else read from the file now (a JPEG's or a HEIC's EXIF).
        $rec = media_meta_load(containing_folder_real_path($dir, $f))[basename($f)] ?? null;
        $t = media_meta_fresh($rec, $real) ? ($rec['t'] ?? null) : media_capture_time($real);
        if (is_int($t)) $fromExif++; else $t = null;
        if ($t === null) $t = @filemtime($real) ?: null;
        if ($t !== null) $times[] = $t;
    }
    if (!$times) return null;
    return [
        'text' => format_date_range(min($times), max($times)),
        'source' => $fromExif * 2 >= count($times) ? '' : 'from the files\' modified dates: these photos carry no capture date',
    ];
}

/** A row of choices drawn as one segmented control (radio buttons underneath, so it posts like any form field and works
 *  without JavaScript). $options is value => label. */
function ui_segmented($name, $options, $current, $label = '') {
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $out = '<div class="seg" role="radiogroup" aria-label="' . $esc($label) . '">';
    foreach ($options as $value => $text) {
        $out .= '<label><input type="radio" name="' . $esc($name) . '" value="' . $esc($value) . '"' . ((string) $current === (string) $value ? ' checked' : '') . '><span>' . $esc($text) . '</span></label>';
    }
    return $out . '</div>';
}

/** Two choices for something a folder can set for itself while Settings has an answer too: the choice that is what Settings says is drawn
 *  outlined and, picked, means "follow Settings" (its radio's value is ""); the other, picked, is the folder's own and is drawn filled.
 *  $options: [value => label], two of them; $current: the folder's own value, or "" for none. */
function ui_segmented_site($name, $options, $siteValue, $current, $label = '') {
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $out = '<div class="seg seg-site" role="radiogroup" aria-label="' . $esc($label) . '">';
    foreach ($options as $value => $text) {
        $isSite = (string) $value === (string) $siteValue;
        $checked = $isSite ? ($current === '' || (string) $current === (string) $value) : (string) $current === (string) $value;
        $out .= '<label' . ($isSite ? ' class="site" title="What Settings says"' : '') . '><input type="radio" name="' . $esc($name) . '" value="' . $esc($isSite ? '' : $value) . '"' . ($checked ? ' checked' : '') . '><span>' . $esc($text) . '</span></label>';
    }
    return $out . '</div>';
}

/** A choice of three or more (or with long labels): a button showing the current choice, which opens a small dark list with a tick on the
 *  chosen row. The choices are real radio buttons inside it (so the page's scripts that listen to radios work unchanged); the menu code
 *  only opens, closes and shows the label. No browser dropdown anywhere. */
function ui_menu($name, $options, $current, $label = '') {
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $cur = isset($options[$current]) ? $options[$current] : (string) reset($options);
    $out = '<div class="menu" data-menu><button type="button" class="menu-btn" aria-haspopup="listbox" aria-expanded="false" aria-label="' . $esc($label) . ': ' . $esc($cur) . '"><span class="menu-label">' . $esc($cur) . '</span>'
         . '<svg class="menu-chev" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M6 9l6 6 6-6" fill="none" stroke="#aab2ff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg></button>'
         . '<div class="menu-pop" role="listbox" aria-label="' . $esc($label) . '" hidden>';
    foreach ($options as $value => $text) {
        $out .= '<label class="menu-opt"><input type="radio" name="' . $esc($name) . '" value="' . $esc($value) . '"' . ((string) $current === (string) $value ? ' checked' : '') . '><span class="menu-text">' . $esc($text) . '</span><span class="menu-tick" aria-hidden="true">&#10003;</span></label>';
    }
    return $out . '</div></div>';
}

function render_admin_identity_form($itemsHere, $csrfToken, $adminFolderPath, $dateSuggestion = null) {
    $isRoot = $adminFolderPath === null;
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $name = $isRoot ? basename(__DIR__) : basename($adminFolderPath);
?>
    <form method="post" class="identity-form">
      <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
      <input type="hidden" name="action" value="save_folder_identity">
      <input type="hidden" name="loaded_hash" value="<?= admin_record_hash(folder_identity_record($itemsHere)) ?>">
      <div class="settings-grid<?= $isRoot ? ' root' : '' ?>">
        <div class="field">
          <label><?= $isRoot ? 'Gallery title' : 'Display name' ?> <span class="rowstate" role="status" aria-live="polite"></span></label>
          <input type="text" name="display_name" aria-label="Name" value="<?= $esc($itemsHere['displayName'] ?? '') ?>">
          <?php if ($isRoot): ?>
            <p class="hint">Shown at the top of the page, in the browser tab and in shared links, instead of "<?= $esc($name) ?>". If blank, a "# Heading" at the top of a notes file is used, then the folder name.</p>
          <?php else: ?>
            <p class="hint">Shown on its cover card instead of "<?= $esc($name) ?>".</p>
          <?php endif; ?>
        </div>
        <div class="field">
          <label>Dates (optional) <span class="rowstate" role="status" aria-live="polite"></span></label>
          <input type="text" name="dates" aria-label="Dates" maxlength="80" placeholder="Oct 1-11th, 2026" value="<?= $esc($itemsHere['dates'] ?? '') ?>">
          <?php if ($dateSuggestion && $dateSuggestion['text'] !== trim((string) ($itemsHere['dates'] ?? ''))): ?>
            <p class="hint">This folder's photos span <strong><?= $esc($dateSuggestion['text']) ?></strong><?= $dateSuggestion['source'] !== '' ? ' (' . $esc($dateSuggestion['source']) . ')' : '' ?>.
              <button type="button" class="secondary date-suggest" data-dates="<?= $esc($dateSuggestion['text']) ?>">Use these dates</button></p>
          <?php endif; ?>
        </div>
        <?php if (!$isRoot): ?>
        <div class="field">
          <label>Position <span class="rowstate" role="status" aria-live="polite"></span></label>
          <input type="number" name="sort_order" aria-label="Position" min="-99999" max="99999" step="1" value="<?= isset($itemsHere['sortOrder']) && is_int($itemsHere['sortOrder']) ? (int) $itemsHere['sortOrder'] : '' ?>">
          <p class="hint">Among sibling folders, lowest first. Blank = natural order, after numbered ones.</p>
        </div>
        <?php endif; ?>
        <?php if (!$isRoot && AUTO_GROUP_BY_FOLDER):
          $siteFlat = in_array(FOLDER_DISPLAY_MODE, ['flat', 'inline'], true);
          $ownStyle = (string) ($itemsHere['subfolderStyle'] ?? ''); $ownNames = $itemsHere['flatNames'] ?? null; $ownNotes = $itemsHere['flatNotes'] ?? null;
          $nowFlat = $ownStyle !== '' ? $ownStyle === 'flat' : $siteFlat;
          $nowNames = is_bool($ownNames) ? $ownNames : FOLDER_FLAT_NAMES; ?>
        <div class="field wide flat-choices" data-site-flat="<?= $siteFlat ? '1' : '0' ?>" data-site-names="<?= FOLDER_FLAT_NAMES ? '1' : '0' ?>">
          <div class="flat-choice">
            <label>Folders under this one appear as <span class="rowstate" role="status" aria-live="polite"></span></label>
            <?= ui_segmented_site('subfolder_style', ['overview' => 'Cards', 'flat' => 'Flat'], $siteFlat ? 'flat' : 'overview', $ownStyle, 'Folders under this one appear as') ?>
          </div>
          <div class="flat-choice" data-flat-row="names"<?= $nowFlat ? '' : ' hidden' ?>>
            <label>Show folder names <span class="rowstate" role="status" aria-live="polite"></span></label>
            <?= ui_segmented_site('flat_names', ['0' => 'Off', '1' => 'On'], FOLDER_FLAT_NAMES ? '1' : '0', is_bool($ownNames) ? ($ownNames ? '1' : '0') : '', 'Show folder names') ?>
          </div>
          <div class="flat-choice" data-flat-row="notes"<?= $nowFlat && $nowNames ? '' : ' hidden' ?>>
            <label>Show folder notes <span class="rowstate" role="status" aria-live="polite"></span></label>
            <?= ui_segmented_site('flat_notes', ['0' => 'Off', '1' => 'On'], FOLDER_FLAT_NOTES ? '1' : '0', is_bool($ownNotes) ? ($ownNotes ? '1' : '0') : '', 'Show folder notes') ?>
          </div>
        </div>
        <?php endif; ?>
        <?php $ps = is_array($itemsHere['photoSort'] ?? null) ? $itemsHere['photoSort'] : []; $psSel = function ($key) use ($ps) { return isset($ps[$key]) && is_bool($ps[$key]) ? ($ps[$key] ? 'on' : 'off') : ''; }; ?>
        <details class="field wide sort-details"<?= $ps ? ' open' : '' ?>>
          <summary>Order of the photos here <span class="muted"><?= $ps ? '&middot; custom' : "&middot; the site's order" ?></span> <span class="rowstate" role="status" aria-live="polite"></span></summary>
          <?php $dirNow = isset($ps['desc']) && is_bool($ps['desc']) ? ($ps['desc'] ? 'desc' : 'asc') : ''; $triState = ['' => "Site's", 'on' => 'On', 'off' => 'Off']; ?>
          <div class="sort-rows">
            <div class="sort-row"><span class="sort-label">Order</span><?= ui_segmented('photo_sort_order', ['' => "Site's", 'name' => 'Name', 'taken' => 'Date'], ($ps['order'] ?? '') === 'modified' ? 'taken' : (string) ($ps['order'] ?? ''), 'Order') ?></div>
            <div class="sort-row"><span class="sort-label">Direction</span><?= ui_segmented('photo_sort_dir', ['' => "Site's", 'asc' => 'First to last', 'desc' => 'Last to first'], $dirNow, 'Direction') ?></div>
            <?php
              $sfNames = sort_priority_names(); $sfOwn = isset($ps['priority']) || isset($ps['video']) || isset($ps['located']) || isset($ps['private']);
              $sfOrder = (isset($ps['priority']) && is_array($ps['priority']) && count($ps['priority']) === 3) ? array_values($ps['priority']) : sort_priority_list();
              foreach (array_keys($sfNames) as $k) if (!in_array($k, $sfOrder, true)) $sfOrder[] = $k;
              $sfOn = []; foreach ($sfNames as $k => $nm) $sfOn[$k] = isset($ps[$k]) && is_bool($ps[$k]) ? $ps[$k] : (bool) constant($nm[2]);
              $sfOrder = array_merge(array_values(array_filter($sfOrder, function ($k) use ($sfOn) { return $sfOn[$k]; })), array_values(array_filter($sfOrder, function ($k) use ($sfOn) { return !$sfOn[$k]; })));
            ?>
            <?php $siteOrder = sort_priority_list(); $siteOn = []; foreach ($sfNames as $k => $nm) $siteOn[$k] = (bool) constant($nm[2]); $siteOrder = array_merge(array_values(array_filter($siteOrder, function ($k) use ($siteOn) { return $siteOn[$k]; })), array_values(array_filter($siteOrder, function ($k) use ($siteOn) { return !$siteOn[$k]; }))); ?>
            <div class="sort-row sort-row-list"><span class="sort-label">Put first</span>
            <div class="sortfirst sf-form" data-mode="form" data-site-order="<?= $esc(implode(',', $siteOrder)) ?>" data-site-on="<?= $esc(json_encode($siteOn)) ?>">
              <?= ui_segmented('photo_sort_first', ['' => "Site's", 'own' => "This folder's own"], $sfOwn ? 'own' : '', 'Put first') ?>
              <ol class="sf-list">
              <?php foreach ($sfOrder as $k): $nm = $sfNames[$k]; ?>
                <li data-kind="<?= $esc($k) ?>">
                  <button type="button" class="sf-grip" aria-label="Move <?= $esc($nm[1]) ?>: drag it, or press the up and down arrow keys" title="Drag to reorder"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><g fill="currentColor"><circle cx="9" cy="6" r="1.7"/><circle cx="15" cy="6" r="1.7"/><circle cx="9" cy="12" r="1.7"/><circle cx="15" cy="12" r="1.7"/><circle cx="9" cy="18" r="1.7"/><circle cx="15" cy="18" r="1.7"/></g></svg></button>
                  <span class="sf-name"><?= $esc($nm[0]) ?></span>
                  <input type="hidden" name="photo_sort_<?= $esc($k) ?>" value="off">
                  <label class="switch"><input type="checkbox" name="photo_sort_<?= $esc($k) ?>" value="on" <?= $sfOn[$k] ? 'checked' : '' ?> aria-label="<?= $esc($nm[0]) ?> first"><span class="slider"></span></label>
                </li>
              <?php endforeach; ?>
              </ol>
              <input type="hidden" name="photo_sort_priority" class="sf-order" value="<?= $esc(implode(',', $sfOrder)) ?>">
              <p class="sf-summary" aria-live="polite"><?= $esc(sort_priority_summary($sfOrder, $sfOn, $siteOrder, $siteOn)) ?></p>
            </div>
            </div>
          </div>
          <p class="hint">"Put first" is the same list as in Settings. Until you change it here, this folder follows the site's. Visitors can change the order with the sort button. Photos placed with <code>{photos:}</code> or a group keep your order.</p>
        </details>
        <div class="field wide">
          <label>Description <span class="rowstate" role="status" aria-live="polite"></span></label>
          <textarea name="description" aria-label="Description" maxlength="1000" rows="4" style="min-height:90px; font-family:inherit;"><?= $esc($itemsHere['description'] ?? '') ?></textarea>
          <p class="hint">Centred box at the top of <?= $isRoot ? 'the page' : 'its page' ?>, also used in shared-link previews. A little Markdown works (<code>**bold**</code>, <code>*italic*</code>, <code>[words](https://…)</code>, <code>- list</code>, <code>## heading</code>); <code>{left}</code>, <code>{center}</code> or <code>{right}</code> aligns a paragraph.</p>
        </div>
      </div>
      <?php // Every field saves by itself as it is changed (the page's script); the button is only here for a browser without JavaScript. ?>
      <div class="nojs-only"><button type="submit"><?= $isRoot ? 'Save gallery settings' : 'Save folder settings' ?></button></div>
    </form>
<?php
}

/**
 * The settings the admin's "Settings" screen can change: one table that
 * drives both the form and the validation, so nothing outside it can ever be
 * written. Passwords are handled separately (never shown, confirmed), and
 * EXTRA_FOLDERS / NGINX_ACCEL_REDIRECT_PREFIX are deliberately file-only:
 * the first points the gallery at paths elsewhere on the server and the
 * second needs matching web-server config, so neither belongs behind a web
 * form. The screen only ever writes .gallery-config.php.
 */
function gallery_setting_definitions() {
    return [
        ['title' => 'Folders and photos', 'items' => [
            ['name' => 'AUTO_GROUP_BY_FOLDER', 'type' => 'bool', 'label' => 'Show subfolders',
             'help' => 'Each subfolder becomes its own album, to any depth.',
             'risk' => 'Every photo in every subfolder becomes visible.',
             'offwarn' => 'Subfolders and links to them disappear. Nothing is deleted.'],
            ['name' => 'FOLDER_DISPLAY_MODE', 'type' => 'enum', 'label' => 'Folder style',
             'options' => ['overview' => 'Cover cards', 'flat' => 'Flat'],
             'help' => 'Cover cards open each folder. Flat puts every photo from the folders on one page, folder by folder.'],
            ['name' => 'FOLDER_FLAT_NAMES', 'type' => 'bool', 'label' => 'Show folder names',
             'help' => 'Only used in Flat. Each folder directly under a page gets a box with its name, and folders inside it a divider, so people can see how the photos are organised. The row at the top gets a pill for each folder to jump to it.', 'shown_when' => 'FOLDER_DISPLAY_MODE=flat'],
            ['name' => 'FOLDER_FLAT_NOTES', 'type' => 'bool', 'label' => 'Show folder notes',
             'help' => 'Only used in Flat, with folder names on. Each folder\'s notes text shows under its name.', 'requires' => 'FOLDER_FLAT_NAMES', 'shown_when' => 'FOLDER_DISPLAY_MODE=flat'],
            ['name' => 'AUTO_GROUP_BY_FILENAME', 'type' => 'bool', 'label' => 'Group photos by filename',
             'help' => 'alpha_01.jpg and alpha_02.jpg become one group. Good for listings; on a camera roll it can merge unrelated photos.'],
            ['name' => 'PHOTO_SORT_ORDER', 'type' => 'enum', 'label' => 'Photo order',
             'options' => ['filename' => 'Name', 'taken' => 'Date'],
             'help' => 'Inside each folder. Date is when the photo was taken. Left alone: Date, or Name when photos are grouped by filename. A folder can override it; visitors can use the sort button.'],
            ['name' => 'PHOTO_SORT_DESC', 'in' => 'PHOTO_SORT_ORDER', 'type' => 'bool', 'label' => 'Last first',   // (shown as the second half of the Photo order control; still saved as its own setting)
             'help' => 'Z to A, or newest first.'],
            ['name' => 'SORT_PRIORITY', 'type' => 'sortfirst', 'label' => 'Put first',
             'help' => 'Switch on what should come first, and drag the handles to order them. The rest follow in the order above.'],
            ['name' => 'SORT_VIDEOS_FIRST', 'in' => 'SORT_PRIORITY', 'type' => 'bool', 'label' => 'Videos first',
             'help' => 'Videos at the front of each folder.'],
            ['name' => 'SORT_LOCATED_FIRST', 'in' => 'SORT_PRIORITY', 'type' => 'bool', 'label' => 'Photos with a location first',
             'help' => 'Photos whose location is shown come first (after videos).'],
            ['name' => 'SORT_PRIVATE_FIRST', 'in' => 'SORT_PRIORITY', 'type' => 'bool', 'label' => 'Private items first',
             'help' => 'For you and private-view visitors; nobody else sees private items.'],
            ['name' => 'SHOW_SORT_BUTTON', 'type' => 'bool', 'label' => 'Sort button',
             'help' => 'Lets visitors reorder the photos (left out where a notes file places the photos).'],
        ]],
        ['title' => 'Videos and thumbnails', 'items' => [
            ['name' => 'ENABLE_VIDEO', 'type' => 'bool', 'label' => 'Show videos (.mov, .mp4)',
             'help' => 'Sent as recorded, with no conversion: big clips use real bandwidth.',
             'risk' => 'Every .mov and .mp4 becomes visible.',
             'offwarn' => 'Videos disappear. Nothing is deleted.'],
            ['name' => 'ENABLE_VIDEO_THUMBNAILS', 'type' => 'bool', 'label' => 'Video thumbnails', 'requires' => 'ENABLE_VIDEO', 'status' => 'ffmpeg',
             'help' => 'A real picture from each video, taken once with ffmpeg. Needs "Thumbnails".',
             'risk' => 'Runs ffmpeg on your server, once per new video.',
             'offwarn' => 'Videos show the generic icon again.'],
            ['name' => 'ENABLE_VIDEO_PREVIEWS', 'type' => 'bool', 'label' => 'Video previews', 'requires' => 'ENABLE_VIDEO',
             'help' => 'Hovering or scrubbing a video plays a short silent clip, made once with ffmpeg.',
             'risk' => 'Runs ffmpeg once per new video; each clip uses a little disk.',
             'offwarn' => 'Hover clips and scrubbing stop.'],
            ['name' => 'ENABLE_THUMBNAIL_CACHE', 'type' => 'bool', 'label' => 'Thumbnails',
             'help' => 'Small copies kept in .gallery-cache so pages load fast and iPhone photos show, plus what each file says about itself (when, where), read once. If it can\'t write, originals are sent.',
             'offwarn' => 'Photos are sent at full size, iPhone (HEIC) photos can\'t show, and nothing can be read from the files ahead of time, so photo locations read from the files stop showing. Nothing is deleted.'],
        ]],
        ['title' => 'Visitors and logs', 'items' => [
            ['name' => 'TRACK_VIEWS', 'type' => 'bool', 'label' => 'Count views',
             'help' => 'Visits (one per visitor per half hour) and each photo\'s views. You always see them here.'],
            ['name' => 'SHOW_VIEW_COUNTS_PUBLICLY', 'type' => 'bool', 'label' => 'Show view counts to visitors',
             'help' => 'Off keeps the numbers to yourself (you still see them when signed in).', 'requires' => 'TRACK_VIEWS'],
            ['name' => 'LOG_PUBLIC_VISITS', 'type' => 'bool', 'label' => 'Log visitors to the public address', 'requires' => 'ENABLE_SHARE_LINKS',
             'status' => 'closednote', 'help' => 'A log in the Shares tab of who opens the gallery without a link: IP address, browser, country and what they looked at. Left out: you, private-view visitors, link holders, preview bots and browsers that don\'t run scripts.',
             'risk' => 'This stores every visitor\'s IP address. Check what your local privacy rules ask of you, and keep it only as long as you need it.',
             'offwarn' => 'Nothing more is logged. The log so far stays in the Shares tab until you trim or delete it.'],
            ['name' => 'LOG_PUBLIC_DAYS', 'type' => 'number', 'label' => 'Keep that log for (days)', 'min' => 0, 'max' => 3650, 'step' => 1, 'requires' => 'LOG_PUBLIC_VISITS',
             'help' => 'Older lines go by themselves, once a day. 0 keeps everything until you trim it (at most the latest 2,000 visitors and 5,000 timeline lines either way).'],
        ]],
        ['title' => 'Locations and the map', 'items' => [
            ['name' => 'ENABLE_PHOTO_LOCATION', 'type' => 'bool', 'label' => 'Allow photo locations',
             'help' => 'Needed before any location shows; each folder and photo still has its own switch. Locations are read when thumbnails are made, or with Prepare media. Maps use OpenStreetMap, which the visitor\'s browser contacts.',
             'risk' => 'GPS can reveal where you live or have been. Nothing shows until a folder opts in.',
             'offwarn' => 'Locations and maps disappear. Nothing is deleted.'],   // (the map is part of this: no separate switch)
            ['name' => 'MAP_CLUSTER_RADIUS_MILES', 'type' => 'number', 'label' => 'Map pin grouping (miles)', 'min' => 0, 'max' => 10000, 'step' => 'any', 'requires' => 'ENABLE_PHOTO_LOCATION',
             'help' => 'Photos closer than this always share one numbered pin; on top of that, pins that would overlap on screen merge until you zoom in. 1 suits a city trip, 0 turns grouping off (every photo keeps its own pin). Folders can override it.'],
        ]],
        ['title' => 'Sharing and access', 'items' => [
            ['name' => 'ENABLE_SHARE_LINKS', 'type' => 'bool', 'label' => 'Allow share links',
             'help' => 'Temporary links that let someone see private photos, made in the Shares tab; each can be limited by opens, time, folders or photos, and keeps a log.',
             'risk' => 'Whoever opens a link sees what it allows. Keep each one as narrow and short-lived as you can.',
             'offwarn' => 'Every share link stops working (it looks invalid to whoever opens it). Nothing is deleted, and turning this back on revives them.'],
            ['name' => 'DEFAULT_LINK_USES', 'type' => 'number', 'label' => 'New links can be opened by (browsers)', 'min' => 0, 'max' => 1000, 'step' => 1, 'requires' => 'ENABLE_SHARE_LINKS',
             'help' => 'How many different browsers a new link lets in; once they are in, the next one is turned away and shows as a blocked try. 2 suits a link for one person (a phone and a computer). 0 is unlimited, for a group. Each link can be changed when you make it, or later with Edit.'],
            ['name' => 'SHARE_HEADS_UP', 'type' => 'bool', 'label' => 'Heads-up for people using a link', 'requires' => 'ENABLE_SHARE_LINKS',
             'help' => 'A thin line at the top for someone using a link: "The link being used to access this media expires on …". Each link can override it; a link with no end never shows it.'],
            ['name' => 'CLOSE_PUBLIC_GALLERY', 'type' => 'bool', 'label' => 'Close the public gallery', 'requires' => 'ENABLE_SHARE_LINKS',
             'help' => 'Strangers get the "link isn\'t valid" page; only you, private-view visitors and link holders see anything. Sign in at /admin. Needs share links on and an admin password.',
             'risk' => 'Nobody can see the gallery without a link. The web server can still hand out a photo file to anyone who knows its exact address unless the rule that makes a bare photo address answer 403 is in place (see the Health check).',
             'offwarn' => 'The public part of the gallery can be seen by anyone again.'],
            ['name' => 'PUBLIC_DOWNLOAD_MODE', 'type' => 'enum', 'hidden_when' => 'CLOSE_PUBLIC_GALLERY', 'hidden_why' => 'The gallery is closed, so strangers see nothing to download; each share link has its own download choice.', 'label' => 'Let visitors download originals', 'status' => 'dlfolders',
             'options' => ['off' => 'Off', 'all' => 'Everything', 'some' => 'Only some folders'],
             'help' => 'A download button in the viewer for visitors without a link (you always have it). Public photos only, never a ZIP, hidden locations removed. A share link has its own answer.',
             'risk' => 'With Everything, or a folder you choose, anyone can save those photos at full resolution.'],
            ['name' => 'PUBLIC_DOWNLOAD_FOLDERS', 'type' => 'pathlist', 'in' => true, 'label' => 'Folders visitors may download from', 'help' => ''],
        ]],
        ['title' => 'Signing in and passwords', 'items' => [
            ['name' => 'ADMIN_STAY_SIGNED_IN_DAYS', 'type' => 'number', 'label' => 'Stay signed in as the admin for (days)', 'min' => 0, 'max' => 365, 'step' => 1,
             'help' => 'Counted from your last visit, so regular use never signs you out; it keeps a Home Screen app signed in. 0 signs you out when the browser closes. Changing the password or pressing Log out ends it on every device, and the list below ends one device at a time.',
             'risk' => 'A phone or computer that is lost or left unlocked stays signed in as the admin for that long. End it in the list below, or change the admin password.'],
            ['name' => 'PRIVATE_VIEW_SIGNED_IN_DAYS', 'type' => 'number', 'label' => 'Stay in private view for (days)', 'min' => 0, 'max' => 365, 'step' => 1,
             'help' => 'How long a phone or computer that was given the private-view password keeps seeing private items, counted from its last visit. 0 ends it when the browser closes. Changing the private-view password ends it for everyone; the list below ends one device at a time.',
             'risk' => 'Whoever has typed the private-view password stays in private view on that device for that long.'],
            ['name' => 'COUNTRY_LOOKUP', 'type' => 'bool', 'in' => true, 'label' => 'Look up the country of each address', 'help' => ''],
            ['name' => 'COUNTRY_DB_URL', 'type' => 'text', 'in' => true, 'label' => 'Where the country file comes from', 'help' => ''],
            ['name' => 'BROWSER_HISTORY_DAYS', 'type' => 'number', 'label' => 'Keep each browser\'s address history for (days)', 'min' => 1, 'max' => 3650, 'step' => 1,
             'help' => 'Every address a signed-in browser has used, with the time, the country when known and the device it said it was. Inside this time nothing limits how much is kept; the list shows how big each one has grown.'],
            ['name' => 'BROWSER_ENDED_DAYS', 'type' => 'number', 'label' => 'Keep signed-out browsers listed for (days)', 'min' => 0, 'max' => 3650, 'step' => 1,
             'help' => 'A browser that was signed out, logged out, expired or ended automatically stays under Recently signed out, with its addresses, so you can see what it did. 0 forgets it at once.'],
            ['name' => 'BROWSER_NETWORKS_ALARM', 'type' => 'number', 'label' => 'Flag a browser used from more than this many networks in 24 hours', 'min' => 2, 'max' => 1000, 'step' => 1,
             'help' => 'A network is an IPv4 block of 256 addresses or an IPv6 /64 (one home, one phone connection). A phone moving between wifi and mobile data uses a few; a stolen cookie sent through a rotating pool uses many. Each row shows its busiest day: set this above your own.'],
            ['name' => 'BROWSER_END_ON_DEVICE', 'type' => 'bool', 'label' => 'Sign a browser out if it is used from a different device of the same kind',
             'help' => 'A sign-in made on an iPhone, then seen as an iPad or a Mac. An iPad (and an iPhone using "Request Desktop Website") says it is a Mac, so this can sign the right person out. Always flagged in amber; best left off.'],
            ['name' => 'BROWSER_END_ON_FAMILY', 'type' => 'bool', 'label' => 'Sign a browser out if it is used from a different kind of device',
             'help' => 'Apple (iPhone, iPad, Mac), Android, Windows and Linux are kinds: a sign-in made on an iPhone that is then used as a Windows PC. Always flagged in red; this also signs it out.'],
            ['name' => 'BROWSER_END_ON_COUNTRY', 'type' => 'bool', 'label' => 'Sign a browser out if it is used from a new country',
             'help' => 'Needs a country for each address (the country lookup below, or a country header from your proxy). Always flagged in red; this also signs it out. Someone travelling or on a VPN will have to type the password again.'],
            ['name' => 'BROWSER_END_ON_NETWORKS', 'type' => 'bool', 'label' => 'Sign a browser out if it passes the networks limit',
             'help' => 'The limit above. Always flagged in red; this also signs it out.'],
        ]],
        ['title' => 'Server setup (advanced)', 'items' => [
            ['name' => 'TRUSTED_PROXY', 'type' => 'enum', 'label' => 'Where visitors\' IP addresses come from', 'status' => 'clientip',
             'options' => ['direct' => 'The connection', 'cloudflare' => 'Cloudflare', 'proxy' => 'Another proxy'],
             'help' => 'Behind Cloudflare or another CDN, every visitor looks the same unless the gallery reads their real address. Choose yours; leave it on "The connection" if your web server already passes the real address on (the Visitors tab then shows no country, which comes from Cloudflare). Another proxy: set TRUSTED_PROXY_RANGES in .gallery-config.php.'],
            ['name' => 'EXTRA_FOLDERS', 'type' => 'folders', 'label' => 'Folders from elsewhere on the server',
             'help' => 'Show a place elsewhere on the server (an archive on another disk). Without a name, the folders inside it appear at the top of the gallery (/space/trip holding Paris and StTropez gives you Paris and StTropez); with a name, the whole place shows as that one folder. Needs "Show subfolders".',
             'risk' => 'Every photo and video there becomes visible.'],
            ['name' => 'NGINX_ACCEL_REDIRECT_PREFIX', 'type' => 'text', 'label' => 'nginx internal redirect', 'status' => 'accel',
             'help' => 'Lets nginx send photos and videos instead of PHP: faster, and videos seek better. Needs the matching internal location (the README shows it) and the gallery\'s own address, such as /kb. Files from elsewhere are always sent by the gallery.',
             'risk' => 'Without the matching nginx location, photos and videos stop loading.'],
        ]],
    ];
}

/** Checks the places from elsewhere that the Settings screen posts (JSON: {"named": {name: path}, "roots": [path]})
 *  before anything is stored. A path must be a real, readable folder, written as the real path (no symlink anywhere in
 *  it, since the scan refuses to follow them), outside this gallery's own folder and not one that contains it. Returns
 *  ['named' => ..., 'roots' => ...] clean, or null with reasons in $errors. */
function site_settings_validate_extra_folders($raw, &$errors) {
    $in = json_decode((string) $raw, true);
    if (!is_array($in) || !isset($in['named'], $in['roots']) || !is_array($in['named']) || !is_array($in['roots']) || !array_is_list($in['roots'])) { $errors[] = 'The list of folders from elsewhere wasn\'t understood.'; return null; }
    if (count($in['named']) + count($in['roots']) > 50) { $errors[] = 'That is more folders from elsewhere than the gallery allows (50).'; return null; }
    $gallery = realpath(__DIR__);
    $bad = false;
    // One path check for both kinds; $q names the entry in any message.
    $checkPath = function ($path, $q) use ($gallery, &$errors, &$bad) {
        if (!is_string($path)) { $errors[] = "The path for $q isn't text."; $bad = true; return null; }
        $p = rtrim($path, '/\\');
        if ($p === '' || preg_match('#[\x00-\x1F\x7F]#', $path) || !preg_match('#^(/|[A-Za-z]:[\\\\/])#', $p) || preg_grep('#^\.\.?$#', preg_split('#[\\\\/]+#', $p))) {
            $errors[] = "The path for $q must be a full path starting with / (no .. in it)."; $bad = true; return null;
        }
        if (!@is_dir($p) || !@is_readable($p)) {
            $errors[] = "The path for $q ($p) isn't a folder this server can read. Check it exists and that the web server may open it (and, on shared hosting, that PHP is allowed outside the website's folder)."; $bad = true; return null;
        }
        $real = realpath($p);
        if (is_link($p) || $real === false || $real !== $p) {
            $errors[] = "The path for $q goes through a link or isn't written as its real path" . ($real ? " (it is $real)" : '') . ". The gallery doesn't follow links, so give the real path."; $bad = true; return null;
        }
        if ($gallery !== false && ($real === $gallery || str_starts_with($real . '/', $gallery . '/') || str_starts_with($gallery . '/', $real . '/'))) {
            $errors[] = "The path for $q overlaps this gallery's own folder (inside it, or containing it), which would show things twice or expose too much."; $bad = true; return null;
        }
        return $real;
    };
    $named = []; $seen = [];
    foreach ($in['named'] as $name => $path) {
        $name = (string) $name; $q = '"' . $name . '"';
        if ($name === '' || $name !== trim($name) || strlen($name) > 60 || $name[0] === '.' || preg_match('#[/\\\\\x00-\x1F\x7F]#', $name)) {
            $errors[] = "$q isn't a usable folder name: 1 to 60 characters, no slashes, no leading dot or space."; $bad = true; continue;
        }
        if (isset($seen[strtolower($name)])) { $errors[] = "The name $q is used twice."; $bad = true; continue; }
        $seen[strtolower($name)] = true;
        if (is_dir(__DIR__ . '/' . $name)) { $errors[] = "A folder called $q already exists in the gallery, so that name is taken."; $bad = true; continue; }
        $real = $checkPath($path, $q);
        if ($real !== null) $named[$name] = $real;
    }
    $roots = [];
    foreach ($in['roots'] as $path) {
        $real = $checkPath($path, is_string($path) ? '"' . $path . '"' : 'a folder');
        if ($real === null) continue;
        if (in_array($real, $roots, true)) { $errors[] = '"' . $real . '" is listed twice.'; $bad = true; continue; }
        $roots[] = $real;
    }
    return $bad ? null : ['named' => $named, 'roots' => $roots];
}

/** The nginx prefix: empty, or a plain URL path such as /kb. */
function site_settings_valid_nginx_prefix($value) {
    if ($value === '') return true;
    if (strlen($value) > 120 || $value[0] !== '/' || str_ends_with($value, '/') || str_contains($value, '//')) return false;
    foreach (explode('/', substr($value, 1)) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..' || !preg_match('/^[A-Za-z0-9._~-]+$/', $segment)) return false;
    }
    return true;
}

/** The settings file's exact text for $cfg: the data guard, then the keys in
 *  a stable order as JSON. Returns null if it can't be encoded (invalid
 *  UTF-8 in a value). */
function gallery_config_render($cfg) {
    $order = [];
    foreach (gallery_setting_definitions() as $group) foreach ($group['items'] as $d) $order[] = $d['name'];
    foreach (['ADMIN_PASSWORD', 'PRIVATE_VIEW_PASSWORD', 'EXTRA_FOLDERS', 'EXTRA_ROOTS', 'NGINX_ACCEL_REDIRECT_PREFIX'] as $n) $order[] = $n;
    $keys = array_values(array_unique(array_merge(array_values(array_intersect($order, array_keys($cfg))), array_keys($cfg))));
    $ordered = [];
    foreach ($keys as $k) $ordered[$k] = $cfg[$k];
    $json = json_encode($ordered === [] ? new stdClass() : $ordered, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
    return $json === false ? null : data_file_guard_line() . $json . "\n";
}

/** Validates the Settings form and rewrites .gallery-config.php, and
 *  nothing else: captions, locations, hide flags, stats and notes are never
 *  read or written here. Returns an admin result array. */
function site_settings_save($ajax = false) {
    $state = gallery_config_load();
    $path = gallery_config_path();
    if (!in_array($state['status'], ['absent', 'ok'], true)) {
        return ['type' => 'error', 'text' => 'The settings file (.gallery-config.php) exists but can\'t be read as settings, so nothing was changed — saving would overwrite whatever is in it. Fix or delete that file, then try again.'];
    }
    $currentRaw = is_file($path) ? (string) file_get_contents($path) : '';
    if (!hash_equals(md5($currentRaw), (string) ($_POST['config_hash'] ?? ''))) {
        return ['type' => 'error', 'text' => 'The settings file' . admin_stale_message()];
    }
    $cfg = $state['cfg'];
    $defaults = $GLOBALS['__gallery_defaults'] ?? [];
    $errors = [];
    $setOrUnset = function ($name, $value) use (&$cfg, $defaults) {
        if (array_key_exists($name, $defaults) && $defaults[$name] === $value) unset($cfg[$name]); else $cfg[$name] = $value;
    };
    foreach (gallery_setting_definitions() as $group) {
        foreach ($group['items'] as $d) {
            $name = $d['name'];
            if (!isset($_POST['set_' . $name]) || !is_string($_POST['set_' . $name])) continue;
            $raw = $_POST['set_' . $name];
            if ($d['type'] === 'bool') {
                $value = $raw === '1';
            } elseif ($d['type'] === 'folders') {
                $value = site_settings_validate_extra_folders($raw, $errors);
                if ($value === null) continue;
                $setOrUnset('EXTRA_ROOTS', $value['roots']);
                $value = $value['named'];
            } elseif ($d['type'] === 'text') {
                if (!site_settings_valid_nginx_prefix($raw)) { $errors[] = '"' . $d['label'] . '" must be empty or a plain path like /kb (starting with /, no trailing slash, letters, digits and . _ ~ - only).'; continue; }
                $value = $raw;
            } elseif ($d['type'] === 'pathlist') {
                $list = json_decode($raw, true);
                $value = [];
                if (!is_array($list) || count($list) > 500) { $errors[] = '"' . $d['label'] . '" must be a list of folders.'; continue; }
                foreach ($list as $p) {
                    if (!is_string($p) || $p === '' || strlen($p) > 400 || $p[0] === '/' || strpbrk($p, "\0\\") !== false || preg_match('~(^|/)\.\.?(/|$)~', $p)) { $errors[] = '"' . $d['label'] . '" holds something that is not a folder of the gallery.'; continue 2; }
                    $value[] = rtrim($p, '/');
                }
                $value = array_values(array_unique($value));
            } elseif ($d['type'] === 'sortfirst') {
                $kinds = array_map('trim', explode(',', $raw));
                $want = ['private', 'video', 'located']; $sorted = $kinds; sort($sorted); sort($want);
                if ($sorted !== $want) { $errors[] = '"' . $d['label'] . '" must list private, video and located, each once.'; continue; }
                $value = implode(',', $kinds);
            } elseif ($d['type'] === 'enum') {
                if (!array_key_exists($raw, $d['options'])) { $errors[] = '"' . $d['label'] . '" must be one of the choices offered.'; continue; }
                $value = $raw;
            } else {
                if (!is_numeric($raw) || (float) $raw < $d['min'] || (float) $raw > $d['max']) { $errors[] = '"' . $d['label'] . '" must be a number from ' . $d['min'] . ' to ' . $d['max'] . '.'; continue; }
                $value = (float) $raw == (int) $raw ? (int) $raw : (float) $raw;
            }
            $setOrUnset($name, $value);
            if ($name === 'PUBLIC_DOWNLOAD_MODE') unset($cfg['ENABLE_PUBLIC_DOWNLOAD']);   // (the answer is now this setting's: the older on/off line would only get in its way)
        }
    }
    unset($cfg['ENABLE_MAP']);   // (the gallery-wide Map switch is gone: an older file's line is dropped by the next save)
    $newAdmin = null;
    $adminPw = (string) ($_POST['pw_admin'] ?? '');
    if ($adminPw !== '') {
        if ($adminPw !== (string) ($_POST['pw_admin2'] ?? '')) $errors[] = 'The two admin passwords you typed don\'t match.';
        elseif (!admin_password_ok($adminPw)) $errors[] = 'The admin password needs at least 8 characters, with at least one letter and one number: it is the one credential that can change your gallery.';
        elseif (preg_match('/[\x00-\x1F\x7F]/', $adminPw)) $errors[] = 'The admin password can\'t contain control characters.';
        else { $newAdmin = password_for_storage($adminPw); $setOrUnset('ADMIN_PASSWORD', $newAdmin); }
    }
    $viewPw = (string) ($_POST['pw_private'] ?? '');
    if (!empty($_POST['pw_private_remove'])) {
        $setOrUnset('PRIVATE_VIEW_PASSWORD', '');
    } elseif ($viewPw !== '') {
        if ($viewPw !== (string) ($_POST['pw_private2'] ?? '')) $errors[] = 'The two private-view passwords you typed don\'t match.';
        elseif (preg_match('/[\x00-\x1F\x7F]/', $viewPw)) $errors[] = 'The private-view password can\'t contain control characters.';
        else $setOrUnset('PRIVATE_VIEW_PASSWORD', password_for_storage($viewPw));
    }
    // A closed gallery needs a way in: share links on and an admin password. Refused only when this save is what makes it so (a settings
    // file that was already that way by hand does not block every other change).
    $effective = function ($c, $n) use ($defaults) { return array_key_exists($n, $c) ? $c[$n] : ($defaults[$n] ?? false); };
    if ($effective($cfg, 'CLOSE_PUBLIC_GALLERY') === true
        && ($effective($cfg, 'CLOSE_PUBLIC_GALLERY') !== $effective($state['cfg'], 'CLOSE_PUBLIC_GALLERY') || $effective($cfg, 'ENABLE_SHARE_LINKS') !== $effective($state['cfg'], 'ENABLE_SHARE_LINKS'))) {
        if ($effective($cfg, 'ENABLE_SHARE_LINKS') !== true) $errors[] = '"Close the public gallery" needs share links: they are the only way in for anyone else. Turn "Allow share links" on, or switch "Close the public gallery" off first.';
        elseif ($newAdmin === null && (string) $effective($cfg, 'ADMIN_PASSWORD') === '') $errors[] = '"Close the public gallery" needs an admin password first, or nobody could sign in.';
    }
    if ($errors) {
        return ['type' => 'error', 'text' => 'Nothing was saved. ' . implode(' ', $errors)];
    }
    $a = $cfg; $b = $state['cfg']; ksort($a); ksort($b);
    if ($a === $b) {
        return ['type' => 'ok', 'text' => 'Nothing to change: those are the settings already in use.', 'hash' => md5($currentRaw), 'unchanged' => true];
    }
    $content = gallery_config_render($cfg);
    if ($content === null) {
        return ['type' => 'error', 'text' => 'Nothing was saved: one of the values isn\'t valid text (it has bytes that aren\'t UTF-8).'];
    }
    $fail = function ($why) use ($content) {
        $GLOBALS['__site_settings_manual'] = $content;
        return ['type' => 'error', 'text' => 'Nothing was saved: ' . $why . ' The text below is the settings file; put it in a file named .gallery-config.php next to index.php yourself.', 'manual' => $content];
    };
    if (!is_writable(__DIR__)) return $fail('the gallery\'s folder isn\'t writable by the web server.');
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $content, LOCK_EX) === false) { @unlink($tmp); return $fail('the settings file couldn\'t be written.'); }
    @chmod($tmp, 0600);
    // Read back what was written, as the loader will, and refuse to install it unless it is exactly the settings intended.
    [$roundStatus, $roundTrip] = gallery_config_parse((string) @file_get_contents($tmp));
    ksort($roundTrip);
    if ($roundStatus !== 'ok' || $roundTrip !== $a) { @unlink($tmp); return $fail('the file that would have been written didn\'t read back correctly, so it was not installed.'); }
    if (!@rename($tmp, $path)) { @unlink($tmp); return $fail('the settings file couldn\'t be replaced.'); }
    // Changing the admin password must not log the person who just changed it out.
    if ($newAdmin !== null) {
        $_SESSION['admin_auth'] = admin_session_marker($newAdmin);
        set_admin_reveal_cookie($newAdmin);   // every token made under the old password stops working; this browser gets a new one
    }
    $n = count($cfg);
    if ($ajax) {
        // The page updates in place, so there is no "next page" to carry a message: say it now, and hand back the new file fingerprint.
        return ['type' => 'ok', 'text' => ($newAdmin !== null ? 'Admin password changed; you stay signed in.' : 'Saved.'), 'hash' => md5_file($path), 'differing' => $n, 'extra' => ['named' => (object) ($cfg['EXTRA_FOLDERS'] ?? []), 'roots' => array_values($cfg['EXTRA_ROOTS'] ?? [])]];
    }
    $_SESSION['admin_flash'] = ['type' => 'ok', 'text' => 'Settings saved' . ($newAdmin !== null ? ' (admin password changed; you stay signed in)' : '') . '. ' . ($n === 0 ? 'Everything is on its default.' : $n . ' setting' . ($n === 1 ? '' : 's') . ' differ' . ($n === 1 ? 's' : '') . ' from the defaults and will survive updating index.php.') . ' Your photos, captions, locations and counts were not touched.'];
    return ['type' => 'redirect'];
}

