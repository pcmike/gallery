/* -----------------------------------------------------------------------
 * The trip map (?map, ENABLE_PHOTO_LOCATION): one overview map per gallery root,
 * every subfolder's pins together. Leaflet + OpenStreetMap tiles — the
 * only realistic option with no API key — loaded from a CDN only when
 * this is actually on, since it's the first thing in this script to ever
 * make an outbound, third-party request. Clustering is plain haversine-
 * distance arithmetic, no mapping-library plugin for that part.
 * ----------------------------------------------------------------------- */

/** Great-circle distance between two lat/lng points, in miles. */
function haversine_miles($lat1, $lng1, $lat2, $lng2) {
    $earthRadiusMiles = 3958.8;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return $earthRadiusMiles * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/** Greedy single-pass clustering: the first not-yet-clustered point
 *  anchors a new cluster and absorbs every remaining point within
 *  MAP_CLUSTER_RADIUS_MILES of *that anchor* (not re-measured from newly
 *  absorbed members) — simple and deterministic, not necessarily the
 *  tightest possible grouping, which is fine for "nearby photos read as
 *  one pin," not a precision clustering problem. */
function cluster_map_points($points, $radiusMiles) {
    // 0 means no grouping at all: every photo is its own pin (for a dense city, where even a tenth of a mile is too much).
    if ($radiusMiles <= 0) return array_map(function ($p) { return [$p]; }, array_values($points));
    $clusters = [];
    $used = array_fill(0, count($points), false);
    for ($i = 0; $i < count($points); $i++) {
        if ($used[$i]) continue;
        $cluster = [$points[$i]];
        $used[$i] = true;
        for ($j = $i + 1; $j < count($points); $j++) {
            if ($used[$j]) continue;
            if (haversine_miles($points[$i]['lat'], $points[$i]['lng'], $points[$j]['lat'], $points[$j]['lng']) <= $radiusMiles) {
                $cluster[] = $points[$j];
                $used[$j] = true;
            }
        }
        $clusters[] = $cluster;
    }
    return $clusters;
}

/** A fixed, high-contrast palette cycling by index — plenty of
 *  realistic top-level groups (trip folders) will fit before repeating,
 *  and a repeat is a cosmetic coincidence, not a correctness problem
 *  (the legend still names each group correctly regardless). */
function map_group_color($index) {
    static $palette = ['#e6194b', '#3cb44b', '#4363d8', '#f58231', '#911eb4', '#42d4f4', '#f032e6', '#bfef45', '#fabed4', '#469990'];
    return $palette[$index % count($palette)];
}

/** Where a pin's popup link actually goes — the identical destination
 *  logic already used for mention-links and copy-link buttons: a
 *  top-level folder-group in 'overview' mode gets its own ?folder= page;
 *  anything else (a filename-based group, an explicit group, or a plain
 *  standalone single) gets its #group-<slug> anchor on the current page,
 *  not a separate linking system. */
function map_point_link($file, $groupKey, $folderGroupKeys) {
    if ($groupKey !== null && isset($folderGroupKeys[$groupKey]) && FOLDER_DISPLAY_MODE === 'overview') {
        // The photo is inside a folder this page shows only as a card: open the folder it is really in, on that photo (a bare folder link
        // left you at the top of a page of hundreds with no sign of the one you pressed). If the photo is part of a group there, the
        // anchor finds nothing and the folder's page simply opens.
        $dirOf = dirname($file);
        return '?folder=' . rawurlencode($dirOf !== '.' && $dirOf !== '' ? $dirOf : $groupKey) . '#group-' . slugify(pathinfo(basename($file), PATHINFO_FILENAME));
    }
    $slug = slugify($groupKey !== null ? $groupKey : pathinfo(basename($file), PATHINFO_FILENAME));
    return '#group-' . $slug;
}

/** Whether a folder — or the gallery root, with $folderPath null — shows a map
 *  of its own photos on its page (and has its own ?map&folder= page). Stored
 *  with the folder like its location switch. The root keeps the behaviour it
 *  always had, so it is on unless switched off; a folder starts off, since
 *  turning one on makes its page load a third-party map. */
function folder_map_enabled($dir, $folderPath) {
    $data = items_read($folderPath === null ? $dir : resolve_real_path($dir, $folderPath));
    $v = $data['mapEnabled'] ?? null;
    return $folderPath === null ? $v !== false : $v === true;
}

/** How close (in miles) photos must be to share one pin on this page's map:
 *  the folder's own setting if it has one, else the site-wide default. */
function folder_map_radius($dir, $folderPath) {
    $data = items_read($folderPath === null ? $dir : resolve_real_path($dir, $folderPath));
    $r = $data['mapRadius'] ?? null;
    return (is_int($r) || is_float($r)) && $r >= 0 && $r <= 10000 ? $r : MAP_CLUSTER_RADIUS_MILES;
}

/** The points for one folder's map: just those inside it (and its subfolders),
 *  coloured by which subfolder they are in, with the photos directly in the
 *  folder under the folder's own name. At the root this is every point, as
 *  collected. Nothing here widens what is shown: the points arrive already
 *  filtered by hiding and by the location switches. */
function map_points_for_folder($points, $dir, $folderPath) {
    if ($folderPath === null) return $points;
    $prefix = $folderPath . '/';
    $metaLabel = folder_display_meta($dir, $folderPath)['label'];
    $folderLabel = $metaLabel !== '' ? $metaLabel : basename($folderPath);
    $out = []; $colorIndex = []; $next = 0;
    foreach ($points as $p) {
        if (strpos($p['file'], $prefix) !== 0) continue;
        $rest = substr($p['file'], strlen($prefix));
        $slash = strpos($rest, '/');
        $child = $slash === false ? '' : substr($rest, 0, $slash);
        if (!isset($colorIndex[$child])) $colorIndex[$child] = $next++;
        if ($child === '') {
            $p['label'] = $folderLabel;
            $p['link'] = '#group-' . slugify(pathinfo(basename($p['file']), PATHINFO_FILENAME));
        } else {
            $childMeta = folder_display_meta($dir, $prefix . $child);
            $p['label'] = $childMeta['label'] !== '' ? $childMeta['label'] : $child;
            $p['link'] = '?folder=' . rawurlencode(dirname($p['file'])) . '#group-' . slugify(pathinfo(basename($p['file']), PATHINFO_FILENAME));   // (the folder the photo is really in, on that photo)
        }
        $p['color'] = map_group_color($colorIndex[$child]);
        $out[] = $p;
    }
    return $out;
}

/**
 * Every visible, located point worth plotting — already filtered the
 * same way everything else on the page is: $files/$itemsData here are
 * whatever they stand as *after* the hiding filter above, so a hidden
 * item is never plotted, same as its thumbnail and its file bytes never
 * load for an unrevealed visitor. photo_location_visible() applies the
 * same three location gates (global/folder/per-item) a grid badge
 * already does. Each point is colored and grouped by its top-level
 * identity (folder-group if it has one, otherwise its own group key) so
 * a Paris cluster and a StTropez cluster read as visually distinct.
 */
function collect_map_points($dir, $files, $itemsData, $fileToGroupKey, $folderGroupKeys, $groupLabels) {
    $points = [];
    $groupColorIndex = [];
    $nextColorIndex = 0;
    foreach ($files as $file) {
        $entry = $itemsData[$file] ?? [];
        if (!photo_location_visible($entry)) continue;
        $loc = $entry['location'];
        $groupKey = $fileToGroupKey[$file] ?? null;
        $colorKey = $groupKey ?? $file;
        if (!isset($groupColorIndex[$colorKey])) {
            $groupColorIndex[$colorKey] = $nextColorIndex++;
        }
        $label = $groupKey !== null ? ($groupLabels[$groupKey] ?? $groupKey) : pathinfo(basename($file), PATHINFO_FILENAME);
        $points[] = [
            'lat' => (float) $loc['lat'],
            'lng' => (float) $loc['lng'],
            'file' => $file,
            'caption' => $entry['caption'] ?? '',
            'label' => $label,
            'color' => map_group_color($groupColorIndex[$colorKey]),
            'thumbUrl' => img_url($file, 'thumb'),
            'link' => map_point_link($file, $groupKey, $folderGroupKeys),
        ];
    }
    return $points;
}

/** Recolours map pins by what the page's notes say about the group each photo is in: a {color:} gives its photos
 *  that colour, {sold} and {reserved} the same red and orange as their ribbons (status wins over colour, as it does on
 *  the page). A recoloured pin takes its group's name in the legend. Photos in groups the notes don't mention keep their
 *  folder colour. */
function apply_note_colors_to_points($points, $groups, $groupColors, $groupStatus, $groupLabels) {
    $byFile = [];
    foreach ($groups as $key => $members) {
        $status = $groupStatus[$key] ?? '';
        $color = $status === 'sold' ? '#e5484d' : ($status === 'reserved' ? '#f5a623' : ($groupColors[$key] ?? null));
        if ($color === null || !is_safe_color($color)) continue;
        foreach ($members as $f) $byFile[$f] = [$color, $groupLabels[$key] ?? $key];
    }
    foreach ($points as &$pt) {
        if (isset($byFile[$pt['file']])) { $pt['color'] = $byFile[$pt['file']][0]; $pt['label'] = $byFile[$pt['file']][1]; }
    }
    unset($pt);
    return $points;
}

/** The legend: one swatch + label per distinct group actually plotted,
 *  in first-seen order — a color-coded map means nothing without a key
 *  to read it against. */
function map_legend_from_points($points) {
    $seen = [];
    $legend = [];
    foreach ($points as $p) {
        if (isset($seen[$p['color'] . '|' . $p['label']])) continue;
        $seen[$p['color'] . '|' . $p['label']] = true;
        $legend[] = ['color' => $p['color'], 'label' => $p['label']];
    }
    return $legend;
}

/**
 * Renders one Leaflet map instance — the shared markup/JS for both the
 * condensed root-page preview and the full ?map page, parameterized over
 * container id and height so the same code draws both. Loads Leaflet's
 * CSS and JS from the cdnjs CDN (two third-party requests, plus the OSM
 * tiles themselves) — only emitted when there's actually at least one
 * point to plot.
 */
function render_leaflet_map($points, $legend, $mapId, $heightCss, $loadLibrary, $radiusMiles = null, $lazy = false, $twoFingers = false) {
    if (empty($points)) return;
    $clusters = cluster_map_points($points, $radiusMiles ?? MAP_CLUSTER_RADIUS_MILES);
    // Hex-escaped so the raw JSON can sit directly inside a <script>
    // block with no further quoting — safe even if a caption happens to
    // contain "</script>", a quote, or an ampersand.
    $clustersJson = json_encode($clusters, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<?php if ($loadLibrary && !$lazy): ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.css" integrity="sha384-sHL9NAb7lN7rfvG5lfHpm643Xkcjzp4jFvuavGOndn6pjVqS6ny56CAt3nsEVT4H" crossorigin="anonymous">
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.js" integrity="sha384-cxOPjt7s7Iz04uaHJceBmS+qpjv2JkIHNVcuOrM+YHwZOmJGBXI00mdUXEq65HTH" crossorigin="anonymous"></script>
<?php endif; ?>
<div id="<?= htmlspecialchars($mapId, ENT_QUOTES, 'UTF-8') ?>" style="height:<?= htmlspecialchars($heightCss, ENT_QUOTES, 'UTF-8') ?>; border-radius:10px;"></div>
<?php if (!empty($legend)): ?>
<div class="map-legend">
  <?php foreach ($legend as $l): ?>
    <span class="map-legend-item"><span class="map-legend-swatch" style="background:<?= htmlspecialchars($l['color'], ENT_QUOTES, 'UTF-8') ?>;"></span><?= htmlspecialchars($l['label'], ENT_QUOTES, 'UTF-8') ?></span>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<script>
(function () {
  var clusters = <?= $clustersJson ?>;
  var mapEl = document.getElementById('<?= htmlspecialchars($mapId, ENT_QUOTES, 'UTF-8') ?>');
  function draw() {
  if (!mapEl || !window.L) return;
  var coarse = !!(window.matchMedia && window.matchMedia('(pointer: coarse)').matches);
  var twoFingers = <?= $twoFingers ? 'true' : 'false' ?> && coarse;   // (a map in the middle of a page on a phone: one finger scrolls the page, two move the map)
  var groupOnScreen = <?= (($radiusMiles ?? MAP_CLUSTER_RADIUS_MILES) > 0) ? 'true' : 'false' ?>;
