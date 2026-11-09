/** HEIC/HEIF carry the same EXIF GPS tags, but exif_read_data() can't
 *  open the container — Imagick can, and exposes them as exif:GPS*
 *  properties (each coordinate as "n/d,n/d,n/d"). pingImage() reads
 *  just the metadata, without decoding the pixels. Needs Imagick with
 *  HEIC support, the same condition HEIC display already has. */
function extract_heic_gps($realPath, &$status = null) {
    $status = 'none';
    if (!class_exists('Imagick')) { $status = 'unreadable'; return null; }
    try {
        $im = new Imagick();
        $im->pingImage($realPath);
        $props = $im->getImageProperties('exif:GPS*');
        $im->clear();
    } catch (Exception $e) {
        $status = 'unreadable';
        return null;
    }
    $split = function ($value) {
        return array_map('trim', explode(',', (string) $value));
    };
    if (empty($props['exif:GPSLatitude']) || empty($props['exif:GPSLongitude'])) return null;
    $lat = exif_gps_to_decimal($split($props['exif:GPSLatitude']), $props['exif:GPSLatitudeRef'] ?? 'N');
    $lng = exif_gps_to_decimal($split($props['exif:GPSLongitude']), $props['exif:GPSLongitudeRef'] ?? 'E');
    if ($lat === null || $lng === null || !is_valid_coordinate($lat, $lng)) return null;
    $status = 'ok';
    return ['lat' => round($lat, 6), 'lng' => round($lng, 6)];
}

/** JPEG half of extract_photo_gps(). */
function extract_jpeg_gps($realPath, &$status = null) {
    $status = 'none';
    if (!function_exists('exif_read_data')) { $status = 'unreadable'; return null; }
    $ext = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg'], true)) return null;
    $exif = @exif_read_data($realPath, 'GPS');
    if (!is_array($exif) || empty($exif['GPSLatitude']) || empty($exif['GPSLongitude'])) return null;
    $lat = exif_gps_to_decimal($exif['GPSLatitude'], $exif['GPSLatitudeRef'] ?? 'N');
    $lng = exif_gps_to_decimal($exif['GPSLongitude'], $exif['GPSLongitudeRef'] ?? 'E');
    if ($lat === null || $lng === null || !is_valid_coordinate($lat, $lng)) return null;
    $status = 'ok';
    return ['lat' => round($lat, 6), 'lng' => round($lng, 6)];
}

/**
 * Garbage-collects stale .gallery-items.php entries — a photo deleted
 * (or renamed by hand, not via a real folder rename) orphans its caption
 * entry within a folder that itself still exists. Same precedent as
 * thumbnail-cache cleanup: swept here, and reported through the existing
 * notices mechanism, the same way an unknown {photos:} reference already
 * is. A whole folder disappearing needs no separate handling — its
 * .gallery-items.php is deleted along with it in the same operation.
 *
 * An entry is an orphan only if its file is GONE from the disk — never
 * merely because a setting currently keeps that file out of the gallery.
 * Switching ENABLE_VIDEO off hides every video, and switching the thumbnail
 * cache off hides every HEIC photo; neither means those files were deleted,
 * and their captions, locations and hide flags must survive until the
 * setting is switched back on.
 */
function gc_items_data($dir, $files, &$galleryNotices) {
    $byFolder = [];
    foreach ($files as $file) {
        $byFolder[containing_folder_real_path($dir, $file)][] = basename($file);
    }
    // Only ever GC folders that actually have an items store — a gallery
    // that's never touched the admin tool pays nothing for this.
    $candidateFolders = array_unique(array_merge(array_keys($byFolder), [$dir]));
    foreach ($candidateFolders as $folderPath) {
        $path = items_path($folderPath);
        if (!file_exists($path)) continue;
        [$status, $stored] = json_file_load($path, items_default());
        if ($status === 'broken') {
            // (Not written to: see items_unreadable. The owner is told which folder, the visitor nothing.)
            $galleryNotices[] = 'The settings file of ' . ($folderPath === $dir ? 'the top folder' : 'the folder "' . basename($folderPath) . '"') . ' can\'t be read, so its photos are hidden from visitors until it is restored from a backup or deleted. The Health check in Settings names it.';
            items_unreadable($folderPath, $stored);
            continue;
        }
        $validBareNames = array_fill_keys($byFolder[$folderPath] ?? [], true);
        $orphanCount = 0;
        foreach (array_keys($stored['items']) as $bare) {
            if (!isset($validBareNames[$bare]) && !file_exists($folderPath . '/' . $bare)) $orphanCount++;
        }
        if ($orphanCount > 0) items_update($folderPath, function (&$data) use ($validBareNames, $folderPath) {
            foreach (array_keys($data['items']) as $bare) {
                if (!isset($validBareNames[$bare]) && !file_exists($folderPath . '/' . $bare)) unset($data['items'][$bare]);
            }
        });
        if ($orphanCount > 0) {
            $galleryNotices[] = $orphanCount === 1
                ? 'A caption/location/web entry for a deleted or renamed photo was cleaned up.'
                : $orphanCount . ' caption/location/web entries for deleted or renamed photos were cleaned up.';
        }
    }
}

/* -----------------------------------------------------------------------
 * The admin editor (?admin). Entirely inert when ADMIN_PASSWORD is
 * empty — see its own doc comment above. Manages the current folder's
 * (root or one subfolder) caption/location/web per item, its folder- or
 * filename-group display names, its chosen cover photo, and every .md
 * in it (active or not) with raw-text save/save-as/delete.
 * ----------------------------------------------------------------------- */

/** ?admin's own &folder= — validated against the real $folderGroupKeys
 *  the same way the public ?folder= is. false means "was given but
 *  invalid" (caller 404s); null means root. */
function admin_resolve_folder($files) {
    if (!isset($_GET['folder'])) return null;
    $requested = $_GET['folder'];
    // Valid at any depth, but only if the script's own file list has
    // something under it — same allowlist-not-trust rule as the public
    // ?folder=.
    if (!empty(folder_files_under($files, $requested))) return $requested;
    return is_string($requested) && AUTO_GROUP_BY_FOLDER && collection_at($GLOBALS['dir'], $requested, $files) !== null ? $requested : false;   // (a collection has a settings page of its own)
}

/** Every folder at every depth, as a flat row of links labelled with the
 *  full path — parents before children, so the tree reads top-down. */
/** How many photos/videos each folder holds: [own, total] keyed by folder path
 *  ('' is the root). "Own" is what is directly in that folder (what its Photos
 *  tab lists); "total" includes everything beneath it. */
function admin_folder_counts($files) {
    $own = []; $total = [];
    foreach ($files as $f) {
        $d = dirname($f);
        $d = ($d === '.' || $d === '') ? '' : $d;
        $own[$d] = ($own[$d] ?? 0) + 1;
        for ($p = $d; ; $p = (strpos($p, '/') === false ? '' : substr($p, 0, strrpos($p, '/')))) {
            $total[$p] = ($total[$p] ?? 0) + 1;
            if ($p === '') break;
        }
    }
    return [$own, $total];
}

/** The folders as a tree: each folder under its parent with guide lines, a
 *  count of what is directly in it, and a chevron to fold the ones beneath it.
 *  The folder being edited is marked (the script keeps its ancestors open and
 *  folds the other branches; without JavaScript everything stays open). */
function render_admin_folder_nav($files, $currentFolder) {
    $folderPaths = all_folder_paths($files);   // (no subfolders still leaves the Root entry: it is the way back to the folder's own tabs from Edited, Share and Settings)
    [$own, $total] = admin_folder_counts($files);
    $children = [];
    foreach ($folderPaths as $path) {
        $parent = strpos($path, '/') === false ? '' : substr($path, 0, strrpos($path, '/'));
        $children[$parent][] = $path;
    }
    $collNodes = []; $collCount = [];   // the collections stand among the folders they live beside, in italics (they are not folders on disk)
    if (AUTO_GROUP_BY_FOLDER && collections_any()) {
        foreach (collections_registry($GLOBALS['dir'], $files)['byPath'] as $cPath => $cRec) {
            $collNodes[$cPath] = $cRec; $collCount[$cPath] = count(collection_member_files($GLOBALS['dir'], $cRec, $files));
            $children[$cRec['parentPath']][] = $cPath;
        }
        foreach ($children as $cParent => $cList) { usort($cList, function ($a, $b) { return strnatcasecmp(basename($a), basename($b)); }); $children[$cParent] = $cList; }
    }
    $link = function ($path, $label, $href) use ($own, $total, $currentFolder, $collNodes, $collCount) {
        $current = ($path === '' ? $currentFolder === null : $currentFolder === $path);
        if (isset($collNodes[$path])) {
            return '<a href="' . $href . '"' . ($current ? ' class="current coll" aria-current="page"' : ' class="coll"') . ' title="' . htmlspecialchars('Collection: ' . $collCount[$path] . ' photo' . ($collCount[$path] === 1 ? '' : 's') . ' in it', ENT_QUOTES, 'UTF-8') . '">'
                 . '<span class="fname coll">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span><span class="fcount">' . $collCount[$path] . '</span></a>';
        }
        $here = $own[$path] ?? 0; $all = $total[$path] ?? 0;
        $title = ($path === '' ? 'Root' : $path) . ': ' . $here . ' here' . ($all !== $here ? ', ' . $all . ' including subfolders' : '');
        return '<a href="' . $href . '"' . ($current ? ' class="current" aria-current="page"' : '') . ' title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '">'
             . '<span class="fname">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span><span class="fcount">' . $here . '</span></a>';
    };
    $branch = function ($parent) use (&$branch, $children, $link, $collNodes) {
        $out = '';
        foreach ($children[$parent] ?? [] as $path) {
            $kids = !empty($children[$path]);
            $label = isset($collNodes[$path]) ? ($collNodes[$path]['title'] !== '' ? $collNodes[$path]['title'] : basename($path)) : basename($path);
            $out .= '<li' . ($kids ? ' class="has-children"' : '') . '>'
                  . ($kids ? '<button type="button" class="ftoggle" aria-expanded="true" aria-label="Show or hide the folders inside ' . htmlspecialchars(basename($path), ENT_QUOTES, 'UTF-8') . '"></button>' : '')
                  . $link($path, $label, '?admin&amp;folder=' . htmlspecialchars(rawurlencode($path), ENT_QUOTES, 'UTF-8'));
            if ($kids) $out .= '<ul>' . $branch($path) . '</ul>';
            $out .= '</li>';
        }
        return $out;
    };
    $hiddenHtml = '';
    if (!AUTO_GROUP_BY_FOLDER) {   // folders that exist but are not shown (Show subfolders is off): listed greyed, so they are not a surprise
        [$hiddenFolders] = hidden_subfolders_summary($GLOBALS['dir'], $GLOBALS['mediaExtensions'] ?? []);
        foreach ($hiddenFolders as $hf) $hiddenHtml .= '<li class="hidden-folder" title="Not shown: Show subfolders is off"><span class="fname">' . htmlspecialchars($hf['name'], ENT_QUOTES, 'UTF-8') . '</span><span class="fcount">' . (int) $hf['count'] . '</span><span class="ftag">hidden</span></li>';
        if ($hiddenHtml !== '') $hiddenHtml .= '<li class="hidden-note">Subfolders are off. <a href="#site" onclick="return adminGoTo(\'site\', \'setting-AUTO_GROUP_BY_FOLDER\');">Show subfolders</a></li>';
    }
    return '<nav class="admin-folder-nav" aria-label="Folders"><ul class="ftree"><li>' . $link('', 'Root', '?admin') . '</li>' . $branch('') . $hiddenHtml . '</ul></nav>';
}

/** The notes reference: everything about writing a notes file, in full, beside
 *  the editors rather than folded away. The Insert buttons put a directive at
 *  the cursor of the note you last clicked in (plain text insertion, no
 *  different from typing it, so it never touches the byte-for-byte save
 *  guarantee of the textarea itself). */
function render_admin_notes_reference() {
    $e = function ($t) { return htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); };
    $c = function ($t) use ($e) { return '<code>' . $e($t) . '</code>'; };
    $ins = function ($label, $snippet) use ($e) { return '<button type="button" class="admin-insert-btn" data-snippet="' . $e($snippet) . '">' . $e($label) . '</button>'; };
    $ex = function ($t) use ($e) { return '<pre class="ref-example">' . $e($t) . '</pre>'; };
    ob_start();
?>
    <h2 class="section-title">How notes work</h2>
    <nav class="ref-tabs" role="tablist" aria-label="Notes reference sections">
      <button type="button" role="tab" data-ref="start" class="active">Start here</button>
      <button type="button" role="tab" data-ref="format">Formatting</button>
      <button type="button" role="tab" data-ref="directives">Directives</button>
      <button type="button" role="tab" data-ref="photos">Photos</button>
      <button type="button" role="tab" data-ref="folders">Folders</button>
      <button type="button" role="tab" data-ref="fix">Fixing</button>
    </nav>

    <section class="ref-panel" data-ref="start">
      <h3>Start here</h3>
      <p>A notes file is a short box of text at the top of a page. Add <strong>directives</strong> in {curly braces} to attach photos, mark things sold, or colour a box. A complete small example:</p>
      <?= $ex("{gallery}\n# Summer trip\n\nOur week in Lisbon. {color: gold}\n\nHand-built keyboard, 65%. {group: Keyboard} {photos: kb1.jpg, kb2.jpg}\n\nThis one has gone. {sold}") ?>
      <ol class="ref-steps">
        <li>The <strong>first line must be exactly</strong> <?= $c('{gallery}') ?>. Any other <code>.md</code> file is left completely alone (a personal notes file is never read). A file without it is listed as "not active", with a one-click button to add the line.</li>
        <li>A <?= $c('# Heading') ?> on the next line becomes the <strong>page title</strong> (in the gallery's first notes file) and isn't repeated in the box. A <strong>Gallery title</strong> set in Settings wins over it.</li>
        <li>A paragraph can carry directives. A directive only ever affects <strong>its own paragraph</strong>, and visitors never see it.</li>
        <li>A notes file inside a folder shows on <strong>that folder's page</strong> and works the same way there.</li>
      </ol>
      <p class="hint">Not sure where to look? <button type="button" class="linklike" data-goto="directives">What can I put in {braces}?</button> <button type="button" class="linklike" data-goto="photos">How do I attach photos?</button> <button type="button" class="linklike" data-goto="fix">Something isn't working.</button></p>
    </section>

    <section class="ref-panel" data-ref="format">
      <h3>Formatting</h3>
      <table class="ref-table">
        <tr><td><?= $c('# Heading') ?><br><?= $c('## Smaller') ?> <?= $c('### Smaller still') ?></td><td>Headings</td></tr>
        <tr><td><?= $c('**bold**') ?> <?= $c('*italic*') ?> <?= $c('_italic_') ?><br><?= $c('***both***') ?> <?= $c('__underline__') ?><br><?= $c('~~strikethrough~~') ?></td><td>Text styles</td></tr>
        <tr><td><?= $c('`code`') ?></td><td>Inline code, shown as typed (this is also how you write a directive without it doing anything: <?= $c('`{sold}`') ?> shows the word and marks nothing)</td></tr>
        <tr><td><?= $c('[words](https://example.com)') ?></td><td>A link. Only <code>http://</code> and <code>https://</code> addresses become links.</td></tr>
        <tr><td><?= $c('- an item') ?> <?= $c('* an item') ?><br><?= $c('1. a numbered item') ?></td><td>Bulleted and numbered lists</td></tr>
        <tr><td><?= $c('> a quote') ?></td><td>A block quote</td></tr>
        <tr><td><?= $c('```') ?> on its own line, before and after</td><td>A block of code, shown exactly as typed</td></tr>
      </table>
      <p class="hint">HTML you type is never run: it appears as plain text.</p>
    </section>

    <section class="ref-panel" data-ref="directives">
      <h3>Directives</h3>
      <p>Write them anywhere in a paragraph. Press a button to put one at the cursor of the note you last clicked in.</p>
      <div class="ref-cards">
        <div class="ref-card"><div class="ref-card-head"><?= $ins('Group a box', '{group: Name}') ?> <?= $c('{group: Name}') ?></div>
          <p>Draws a labelled <strong>box</strong> around this paragraph's photos, with its own view count. On its own it renames a box the gallery already made (a folder, or <code>alpha_01.jpg</code> and <code>alpha_02.jpg</code> grouped by name).</p></div>
        <div class="ref-card"><div class="ref-card-head"><?= $ins('Claim photos', '{photos: a.jpg, b.jpg}') ?> <?= $c('{photos: a.jpg, b.jpg}') ?></div>
          <p>Attaches exactly these files to this paragraph, <strong>whatever they're called</strong>. Separate names with commas, spelled as in the folder. A file belongs to one paragraph only: the first claim wins.</p></div>
        <div class="ref-card"><div class="ref-card-head"><?= $ins('Accent color', '{color: #ff0000}') ?> <?= $c('{color: gold}') ?> or <?= $c('{color: #5865f2}') ?></div>
          <p>The accent colour of that item's box: a hex code or a plain colour word. The paragraph's own text gets a bar in the same colour, so the two read as one item; with no photo or folder to colour, the bar is all it does.</p></div>
        <div class="ref-card"><div class="ref-card-head"><?= $ins('Sold', '{sold}') ?> <?= $c('{sold}') ?></div>
          <p>Marks the item <strong>sold</strong>: dimmed, struck-through text, grayscale photos and a red SOLD ribbon.</p></div>
        <div class="ref-card"><div class="ref-card-head"><?= $ins('Reserved', '{reserved}') ?> <?= $c('{reserved}') ?> or <?= $c('{held}') ?></div>
          <p>Marks it <strong>reserved</strong>: an orange tag; the photos stay in colour.</p></div>
        <div class="ref-card"><div class="ref-card-head"><?= $c('{left}') ?> <?= $c('{center}') ?> <?= $c('{right}') ?></div>
          <p>Aligns that paragraph's text (or a heading's, written at the end of its line). Notes are left-aligned; a folder's or the gallery's description box is centred unless you say otherwise.</p></div>
      </div>
      <p class="hint insert-hint" hidden>Click inside the note you want to add it to first, then press the button.</p>
      <p class="hint">If an item has both, sold wins over reserved, and either one overrides a custom colour, so the signal is never ambiguous.</p>
    </section>

    <section class="ref-panel" data-ref="photos">
      <h3>Attaching photos to a paragraph</h3>
      <p>Three ways, from least to most typing:</p>
      <ol class="ref-steps">
        <li><strong>Just mention it.</strong> If a paragraph contains a photo's name as a whole word (not case-sensitive), its photos appear under it. With filename grouping off the name is the file's name without its extension (<code>zebra.jpg</code> is "zebra"); with it on, a trailing <code>_01</code> or <code>-02</code> is dropped, so <code>alpha_01.jpg</code> and <code>alpha_02.jpg</code> are both "alpha".</li>
        <li><strong>List them</strong>, which works with any filenames:
          <?= $ex("The blue one is my favourite. {photos: IMG_0231.jpg, IMG_0232.jpg}") ?></li>
        <li><strong>List them and give the item a box:</strong>
          <?= $ex("Hand-built keyboard, 65%. {group: Keyboard} {photos: kb1.jpg, kb2.jpg} {color: gold}") ?></li>
      </ol>
      <p>A photo you list always beats the automatic matching, and is taken out of it.</p>
      <h3>Where a directive applies</h3>
      <p>A directive only ever affects <strong>its own paragraph</strong>, never the one above or below, even with nothing in between. A paragraph ends at a <strong>blank line</strong> (the usual cause of surprises), a heading, a quote or a list item (each stands alone), a code block, and a line starting <?= $c('(1)') ?>, <?= $c('(2)') ?> and so on, which starts a new paragraph straight away so a numbered listing needs no blank lines.</p>
      <p>So this does <em>not</em> work as hoped, because the blank line makes two paragraphs:</p>
      <?= $ex("Take a look at my rack\n\n{group: Rack} {photos: rack.jpg, rack2.jpg}") ?>
      <p>Remove the blank line to make it one item:</p>
      <?= $ex("Take a look at my rack. {group: Rack} {photos: rack.jpg, rack2.jpg}") ?>
    </section>

    <section class="ref-panel" data-ref="folders">
      <h3>Notes and folders</h3>
      <p>Notes work the same at the top and inside every folder: a notes file affects the page it sits on, and can use every directive on the photos <strong>directly in its own folder</strong>.</p>
      <table class="ref-table">
        <tr><td>Mention a folder's name</td><td>Write a subfolder's name as a whole word in a paragraph. A <em>cover card</em> takes that paragraph's <?= $c('{sold}') ?>, <?= $c('{reserved}') ?> and <?= $c('{color:}') ?>. With <em>Inline</em> folders its photos also appear under the paragraph.</td></tr>
        <tr><td><?= $c('{group: Name}') ?> on a folder's paragraph</td><td>Renames that folder's card or box on the page you're on. To rename it everywhere, use the folder's <strong>Display name</strong> in its settings.</td></tr>
        <tr><td><?= $c('{photos: a.jpg}') ?></td><td>Reaches photos <strong>directly in the folder the notes are in</strong>. A photo inside a subfolder can't be claimed from above: write the subfolder's name instead, or put the notes in that subfolder.</td></tr>
        <tr><td>Mention a photo by name</td><td><?= $c('eiffel') ?> pulls <code>eiffel.jpg</code> up to the paragraph, in any folder's notes.</td></tr>
        <tr><td>The photo viewer</td><td>The arrows stay inside one folder, so a trip's days don't run into each other.</td></tr>
      </table>
    </section>

    <section class="ref-panel" data-ref="fix">
      <h3>If something doesn't work</h3>
      <p>The page shows a small, collapsed <strong>setup notices</strong> banner when something in a notes file didn't resolve:</p>
      <ul>
        <li>a filename in <?= $c('{photos:}') ?> that isn't in the folder, or is inside a subfolder,</li>
        <li>a file claimed twice, or a <?= $c('{group:}') ?> name used twice,</li>
        <li>a directive cut off from its text by a blank line,</li>
        <li>a <code>.md</code> file missing <?= $c('{gallery}') ?>.</li>
      </ul>
      <h3>Showing a directive as text</h3>
      <p>To write about a directive without it doing anything, put it in backticks: <?= $c('`{sold}`') ?> shows the word <code>{sold}</code> and marks nothing. Everything on this page is written that way.</p>
      <p class="hint">The complete reference, with a table of every combination of <code>{group:}</code> and <code>{photos:}</code>, is in the README under "Directive syntax".</p>
    </section>
<?php
    return ob_get_clean();
}

/**
 * Processes exactly one admin POST action. Every action is CSRF-checked
 * first; a save can touch at most one concern (an item, a group label, a
 * folder setting, or one .md file) so a failure in one never silently
 * implies success in another — reported per-action, not assumed
 * all-or-nothing.
 */
/** A short fingerprint of one record as it was when an admin form was
 *  rendered, posted back as `loaded_hash` and compared against the record
 *  as it is at save time, under the same lock. If it differs, someone
 *  else (a second tab, a second device) changed that exact record in the
 *  meantime — warn instead of silently overwriting. Per-record, not
 *  per-file: two sessions editing *different* photos in one folder never
 *  collide. A save with no loaded_hash at all (an older client) skips the
 *  check rather than failing. */
function admin_record_hash($record) {
    return md5(json_encode($record));
}

/** The fingerprint of a photo's own record for the stale-edit check. Its privacy and location-visibility switches save
 *  by themselves, so they are left out: flipping one must not make the photo's caption form look out of date. */
function admin_item_record_hash($entry) {
    unset($entry['restriction'], $entry['locationVisibility'], $entry['addr']);   // (the photo's address is replaced by itself too)
    return admin_record_hash($entry);
}

/** The folder-level fields the "This folder's own settings" form edits,
 *  normalized so the render-time and save-time fingerprints match. */
function folder_identity_record($data) {
    return [
        'displayName' => $data['displayName'] ?? null,
        'description' => $data['description'] ?? null,
        'dates' => $data['dates'] ?? null,
        'sortOrder' => $data['sortOrder'] ?? null,
        'photoSort' => $data['photoSort'] ?? null,
    ];
}

/** A function, not a const: the admin route runs before execution ever
 *  reaches this point in the file, and only functions are hoisted. */
function admin_stale_message() {
    return ' was changed somewhere else (another tab or device) since you opened this page, so nothing was saved. Reload to see the current values, then make your change again.';
}

/** Plain single-line-ish text from a form field: control characters
 *  dropped, whitespace runs collapsed, length capped. Output is always
 *  HTML-escaped where it's shown, so this is about tidiness and bounding
 *  what a .gallery-items.php can grow to, not about escaping. */
/** Like admin_clean_text() but keeps line breaks and blank lines (a folder's description is a little Markdown, so
 *  paragraphs and lists must survive): carriage returns and control characters dropped, runs of blank lines
 *  collapsed to one, trailing spaces trimmed, length capped. */
function admin_clean_description($value, $maxLen) {
    $text = str_replace(["\r\n", "\r"], "\n", (string) $value);
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
    $text = preg_replace('/\t/', ' ', (string) $text);
    $text = preg_replace('/[ ]+\n/', "\n", (string) $text);
    $text = trim(preg_replace('/\n{3,}/', "\n\n", (string) $text));
    return function_exists('mb_substr') ? mb_substr($text, 0, $maxLen, 'UTF-8') : substr($text, 0, $maxLen);
}

/** A folder's (or the gallery's) description as HTML: the same small Markdown as the notes (bold, italic, links, lists,
 *  headings), centred by default; {left}, {center} or {right} in a paragraph sets that paragraph's alignment. */
function render_description_html($text) {
    $colors = []; $status = []; $labels = []; $claims = [];
    return markdown_to_html(protect_inline_code((string) $text), [], [], $colors, $status, $labels, $claims, []);
}

function admin_clean_text($value, $maxLen) {
    $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $value);
    $text = trim(preg_replace('/\s+/u', ' ', (string) $text));
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $maxLen, 'UTF-8');
    }
    return substr($text, 0, $maxLen);
}

/** The admin password rule: at least 8 characters, with a letter (either case) and a number. It is the one credential
 *  that can change the gallery. (The private-view password may be any length: it only unlocks looking.) */
function admin_password_ok($pw) {
    return strlen($pw) >= 8 && preg_match('/[A-Za-z]/', $pw) && preg_match('/\d/', $pw);
}

/** Answers a request made by the admin page's own script (a switch saved in
 *  place) with JSON, and ends the request. */
function admin_json_exit($payload) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

/** A folder's own order of photos (or a collection's), as the settings form posts it: the array kept in its record, or [] for "follow the site's". */
function admin_photo_sort_from_post() {
    $own = [];
    $order = (string) $_POST['photo_sort_order'];
    if (in_array($order, ['name', 'taken', 'modified'], true)) $own['order'] = $order;
    $dirChoice = (string) ($_POST['photo_sort_dir'] ?? '');
    if ($dirChoice === 'asc' || $dirChoice === 'desc') $own['desc'] = $dirChoice === 'desc';
    if (array_key_exists('photo_sort_first', $_POST)) {   // the list form: "" = follow the site's, "own" = this folder's own list
        if ((string) $_POST['photo_sort_first'] === 'own') {
            foreach (['private', 'video', 'located'] as $kind) $own[$kind] = ((string) ($_POST['photo_sort_' . $kind] ?? 'off')) === 'on';
            $kinds = array_map('trim', explode(',', (string) ($_POST['photo_sort_priority'] ?? '')));
            $want = ['private', 'video', 'located']; $sortedKinds = $kinds; sort($sortedKinds); sort($want);
            if ($sortedKinds === $want) $own['priority'] = $kinds;
            // A list that would sort exactly as the site's does is not a setting of this folder's own (so it keeps following the site).
            if (isset($own['priority'])) {
                $namesMap = sort_priority_names();
                $ownEff = array_values(array_filter($own['priority'], function ($k) use ($own) { return !empty($own[$k]); }));
                $siteEff = array_values(array_filter(sort_priority_list(), function ($k) use ($namesMap) { return (bool) constant($namesMap[$k][2]); }));
                if ($ownEff === $siteEff) foreach (['private', 'video', 'located', 'priority'] as $drop) unset($own[$drop]);
            }
        }
    } else {
        foreach (['private', 'video', 'located'] as $kind) {
            $choice = (string) ($_POST['photo_sort_' . $kind] ?? '');
            if ($choice === 'on' || $choice === 'off') $own[$kind] = $choice === 'on';
        }
    }
    return $own;
}

/** One photo's own record, as the editor in the viewer shows it: caption, link, place, the two switches, whether it is the
 *  folder's cover, and the fingerprint a later save is checked against. A place read from the photo's GPS but not saved yet
 *  comes back as `auto` (not as `lat`/`lng`), so opening the editor never turns it into a saved location by itself. */
function admin_item_json($dir, $files) {
    header('Cache-Control: no-store');
    $file = (string) $_GET['item'];
    if ($file === '' || strpos($file, "\0") !== false || !in_array($file, $files, true)) {
        http_response_code(404);
        admin_json_exit(['ok' => false, 'text' => 'Unknown file.']);
    }
    $real = containing_folder_real_path($dir, $file);
    $bare = basename($file);
    $data = items_read($real);
    $entry = $data['items'][$bare] ?? [];
    $rest = item_restriction($entry);
    $folderPrivate = folder_above_is_private($dir, $file);
    $vis = $entry['locationVisibility'] ?? 'inherit';
    $loc = $entry['location'] ?? null;
    $auto = null;
    if ($loc === null && empty($entry['gpsChecked']) && empty($entry['locationCleared']) && ENABLE_PHOTO_LOCATION) {
        if (media_meta_enabled()) {
            $rec = media_meta_load($real)[$bare] ?? null;
            $fromFile = media_meta_fresh($rec, $real . '/' . $bare) ? media_file_location($entry, $rec) : null;
            if ($fromFile !== null) $auto = ['lat' => $fromFile['lat'], 'lng' => $fromFile['lng']];
        } else {
            $auto = extract_photo_gps(resolve_real_path($dir, $file));   // (no cache to keep a record in: read it now)
        }
    }
    $cover = $data['cover'] ?? null;
    admin_json_exit([
        'ok' => true,
        'file' => $file,
        'caption' => (string) ($entry['caption'] ?? ''),
        'web' => (string) ($entry['web'] ?? ''),
        'lat' => $loc !== null ? (string) $loc['lat'] : '',
        'lng' => $loc !== null ? (string) $loc['lng'] : '',
        'label' => $loc !== null ? (string) ($loc['label'] ?? '') : '',
        'auto' => $auto !== null ? ['lat' => (string) $auto['lat'], 'lng' => (string) $auto['lng']] : null,
        'hash' => admin_item_record_hash($entry),
        'private' => $rest === 'private' || ($rest === 'inherit' && $folderPrivate),
        'own' => $rest === 'inherit' ? '' : $rest,   // the photo's own setting: "private", "public", or "" (it follows its folder)
        'folderPrivate' => $folderPrivate,
        'locationShown' => $vis === 'show' || ($vis === 'inherit' && !empty($data['locationEnabled'])),
        'folderLocationOn' => !empty($data['locationEnabled']),
        'locationsOff' => !ENABLE_PHOTO_LOCATION,
        'cover' => is_string($cover) && $cover !== '' && $cover === $bare,
        'views' => TRACK_VIEWS ? (int) (stats_get_all_photo_views($dir, [$file])[$file] ?? 0) : null,
    ]);
}

function handle_admin_post($dir, $adminFolderPath, $adminFolderReal, $files, $groups, $explicitGroups) {
    // Normalize every posted value once, up front: a field sent as an
    // array (name[]=x) is dropped to '' rather than reaching code that
    // expects a string, and NUL bytes — which make file functions throw —
    // are removed. No admin field is ever legitimately either.
    foreach ($_POST as $postKey => $postValue) {
        $_POST[$postKey] = is_string($postValue) ? str_replace("\0", '', $postValue) : '';
    }
    $isFetch = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'gallery-fetch';
    if (!admin_csrf_valid($_POST['csrf_token'] ?? '')) {
        if ($isFetch) admin_json_exit(['ok' => false, 'text' => 'Your session expired — reload the page and sign in again.']);
        return ['type' => 'error', 'text' => 'Your session expired — please try again.'];
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'logout') {
        return ['type' => 'logout'];
    }

    if ($action === 'visits_trim') visits_trim_action();   // the Visitors tab's Trim the log (answers JSON and ends the request)
    if (strncmp($action, 'link_', 5) === 0) {   // the Shares tab (answers JSON and ends the request)
        links_admin_action($action, $dir, $files);
    }
    if (strncmp($action, 'collection_', 11) === 0 || $action === 'toggle_collection_flag' || ($action === 'save_folder_identity' && $adminFolderPath !== null && collection_at($dir, $adminFolderPath, $files) !== null)) collections_admin_action($action, $dir, $adminFolderPath, $files);   // (collections: a record in one file; answers JSON and ends the request)
    if (strncmp($action, 'update_', 7) === 0) update_admin_action($action);   // the Health check's new-version buttons (answers JSON and ends the request)
    if (strncmp($action, 'geo_', 4) === 0) geo_admin_action($action);   // Settings > Signing in: the country file (answers JSON and ends the request)
    if ($action === 'browser_ack') {   // "Looks fine": that flag is cleared until something new raises it
        $bid = (string) ($_POST['id'] ?? ''); $bflag = (string) ($_POST['flag'] ?? '');
        if (!preg_match('/^[0-9a-f]{12}$/', $bid) || !in_array($bflag, ['device', 'family', 'country', 'networks'], true)) admin_json_exit(['ok' => false, 'text' => 'Unknown flag.']);
        $acked = browser_ack($bid, $bflag);
        if (!empty($GLOBALS['__data_write_failed'])) admin_json_exit(['ok' => false, 'text' => data_write_failure_text('the gallery\'s folder')]);
        admin_json_exit(['ok' => true, 'count' => $acked, 'text' => 'Cleared. It comes back if something new happens.']);
    }
    if ($action === 'browser_cancel' || $action === 'browser_cancel_others') {   // Settings > Signing in: end one signed-in browser, or every other one (answers JSON)
        $bid = (string) ($_POST['id'] ?? '');
        if ($action === 'browser_cancel' && !preg_match('/^[0-9a-f]{12}$/', $bid)) admin_json_exit(['ok' => false, 'text' => 'Unknown browser.']);
        $ended = browser_cancel($bid, $action === 'browser_cancel_others');
        if (!empty($GLOBALS['__data_write_failed'])) admin_json_exit(['ok' => false, 'text' => data_write_failure_text('the gallery\'s folder')]);
        admin_json_exit(['ok' => true, 'count' => $ended, 'text' => $ended === 0 ? 'Nothing to end: it had already ended.' : ($ended === 1 ? 'Signed out.' : $ended . ' browsers signed out.')]);
    }
    if (strncmp($action, 'files_', 6) === 0) {   // Settings > Files this gallery has made (answers JSON and ends the request)
        files_admin_action($action, $dir, $files);
    }

    if ($action === 'save_site_settings') {
        $result = site_settings_save($isFetch);
        if ($isFetch) {
            admin_json_exit(['ok' => ($result['type'] ?? '') === 'ok', 'text' => (string) ($result['text'] ?? ''), 'hash' => $result['hash'] ?? null, 'manual' => $result['manual'] ?? null, 'unchanged' => !empty($result['unchanged']), 'extra' => $result['extra'] ?? null]);
        }
        return $result;
    }

    // Prepare media: makes the tile pictures and records what the files say about themselves (when taken, where), a few seconds' worth
    // per request; the page asks again until it is done. ('warm_thumbnails' is the older name of the same action.)
    if ($action === 'prepare_media' || $action === 'warm_thumbnails') {
        if (!ENABLE_THUMBNAIL_CACHE) admin_json_exit(['ok' => false, 'text' => 'The thumbnail cache is switched off in Settings, so there is nowhere to keep anything it prepares.']);
        @set_time_limit(60);
        session_write_close();   // the page runs a few of these side by side: none may wait for another's hold on the session
        $again = !empty($_POST['again']);
        $r = media_prepare_batch($dir, $files, (int) ($_POST['from'] ?? 0), 1.0, min(8, max(1, (int) ($_POST['step'] ?? 1))), $again, $again && !empty($_POST['remake']));
        $r['ok'] = true; $r['text'] = '';
        admin_json_exit($r);
    }

    // Tidy up saved locations: what the folders' data holds that the files already say (a preview, or the real thing with a backup first).
    if ($action === 'media_tidy') {
        if (!ENABLE_THUMBNAIL_CACHE) admin_json_exit(['ok' => false, 'text' => 'The thumbnail cache is switched off in Settings, so the files can\'t have been read and there is nothing to compare with.']);
        @set_time_limit(120);
        $apply = !empty($_POST['apply']);
        $res = media_tidy_run($dir, $files, ($_POST['mode'] ?? '') === 'refresh', $apply);
        admin_json_exit(['ok' => true, 'text' => ''] + $res + ['applied' => $apply]);
    }

    // The Prepare media panel itself, for the Settings page to put in place the moment the Thumbnails switch is turned on (no reload).
    if ($action === 'media_panel') {
        $GLOBALS['dir'] = $dir; $GLOBALS['__admin_files'] = $files;
        ob_start(); render_admin_thumbnails_panel(htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8')); $html = ob_get_clean();
        admin_json_exit(['ok' => true, 'text' => '', 'cache' => ENABLE_THUMBNAIL_CACHE, 'html' => $html]);
    }

    // What the gallery knows and doesn't know about each folder's files (Prepare media's list).
    if ($action === 'media_report') {
        session_write_close();
        $out = [];
        $earlier = TRACK_VIEWS ? moved_in_earlier_views($dir, $files)['rows'] : [];   // (views an older gallery counted for these photos: shown as a column only when there are some)
        foreach (media_report($dir, $files) as $row) { $row['earlier'] = $earlier[$row['folder']] ?? null; $out[] = $row; }
        $att = media_attention($dir, $files);
        admin_json_exit(['ok' => true, 'text' => '', 'cache' => ENABLE_THUMBNAIL_CACHE, 'rows' => $out, 'earlierAny' => count($earlier) > 0, 'attention' => $att['attention'], 'backupsHtml' => render_media_backups_html(media_tidy_backups($dir, $files))]);
    }

    // Moved in from another gallery (Prepare media): what an older gallery left in these folders, and the two things to do about it.
    if ($action === 'media_moved') {
        session_write_close();
        $ev = TRACK_VIEWS ? moved_in_earlier_views($dir, $files) : ['views' => 0, 'folders' => 0];
        $lo = moved_in_leftovers($dir, $files);
        admin_json_exit(['ok' => true, 'text' => '', 'track' => TRACK_VIEWS, 'views' => $ev['views'], 'viewFolders' => $ev['folders'],
            'leftovers' => ['count' => $lo['count'], 'bytes' => $lo['bytes'], 'folders' => array_map(function ($f) { return ['folder' => $f['folder'], 'count' => $f['count'], 'bytes' => $f['bytes']]; }, array_slice($lo['folders'], 0, 60)), 'more' => max(0, count($lo['folders']) - 60)]]);
    }
    if ($action === 'media_moved_views') {
        if (!TRACK_VIEWS) admin_json_exit(['ok' => false, 'text' => 'Counting views is switched off in Settings, so there is nothing to add them to.']);
        @set_time_limit(120);
        $r = moved_in_earlier_views($dir, $files, true);
        admin_json_exit(['ok' => true, 'text' => '', 'added' => $r['added'], 'folders' => $r['addedFolders']]);
    }
    if ($action === 'media_moved_remove') {
        if (empty($_POST['others_gone'])) admin_json_exit(['ok' => false, 'text' => 'Tick the box that says no other gallery still shows these folders first.']);
        @set_time_limit(120);
        $r = moved_in_remove_leftovers($dir, $files);
        admin_json_exit(['ok' => true, 'text' => '', 'count' => $r['count'], 'bytes' => $r['bytes']]);
    }
    // Set aside / Bring back a Health check row about something left behind. The amount noted is worked out here, now, never taken from the request:
    // the row comes back by itself if there is more of it later.
    if ($action === 'health_set_aside') {
        $key = (string) ($_POST['key'] ?? '');
        $amounts = ['earlier-views' => function () use ($dir, $files) { return moved_in_earlier_views($dir, $files)['views']; },
                    'leftover-thumbs' => function () use ($dir, $files) { return moved_in_leftovers($dir, $files)['count']; },
                    'loose-photos' => function () { return EXTRA_ROOTS ? count_loose_media_in_roots() : 0; }];
        if (!isset($amounts[$key])) admin_json_exit(['ok' => false, 'text' => 'That row can\'t be set aside.']);
        $now = is_array(HEALTH_SET_ASIDE) ? HEALTH_SET_ASIDE : [];
        if (!empty($_POST['back'])) unset($now[$key]); else $now[$key] = (int) $amounts[$key]();
        $why = gallery_config_merge(['HEALTH_SET_ASIDE' => $now ?: null]);
        admin_json_exit($why === '' ? ['ok' => true, 'text' => !empty($_POST['back']) ? 'Brought back.' : 'Set aside.'] : ['ok' => false, 'text' => $why]);
    }
    if ($action === 'health_remove_old_logs') {   // the visitor logs of versions before 2.6.5 (Health > Left behind): the exact files and the stand-in record, nothing else
        $n = visitlog_old_leftovers_remove();
        admin_json_exit($n === null ? ['ok' => false, 'text' => 'Nothing was removed: one of the files didn\'t check out as the gallery\'s own.'] : ['ok' => true, 'removed' => $n, 'text' => 'Removed.']);
    }
    if ($action === 'media_backups_delete') {
        $n = media_tidy_backups_delete($dir, $files);
        admin_json_exit(['ok' => true, 'deleted' => $n, 'text' => $n ? 'Deleted ' . $n . ' backup' . ($n === 1 ? '' : 's') . '.' : 'There were no backups.']);
    }

    if ($action === 'save_item') {
        $bare = basename((string) ($_POST['file'] ?? ''));
        $fullPath = $adminFolderPath === null ? $bare : $adminFolderPath . '/' . $bare;
        if (!in_array($fullPath, $files, true)) {
            return ['type' => 'error', 'text' => 'Unknown file.'];
        }
        $caption = trim((string) ($_POST['caption'] ?? ''));
        $web = trim((string) ($_POST['web'] ?? ''));
        $lat = trim((string) ($_POST['lat'] ?? ''));
        $lng = trim((string) ($_POST['lng'] ?? ''));
        $locLabel = trim((string) ($_POST['loc_label'] ?? ''));
        // The privacy and location-visibility switches save themselves (set_item_flag); a form that does post them (an
        // older page, a script) still sets them, and one that doesn't leaves them as they are.
        $visibility = isset($_POST['loc_visibility']) ? (string) $_POST['loc_visibility'] : null;
        if ($visibility !== null && !in_array($visibility, ['inherit', 'show', 'hide'], true)) $visibility = 'inherit';
        $restriction = isset($_POST['restriction']) ? (string) $_POST['restriction'] : null;
        if ($restriction !== null && !in_array($restriction, ['inherit', 'public', 'private'], true)) $restriction = 'inherit';
        if ($web !== '' && !preg_match('#^https?://#i', $web)) {
            return ['type' => 'error', 'text' => 'The web link needs to start with http:// or https://.'];
        }
        if (($lat !== '' || $lng !== '') && !is_valid_coordinate($lat === '' ? 0 : $lat, $lng === '' ? 0 : $lng)) {
            return ['type' => 'error', 'text' => 'That latitude/longitude isn\'t a valid coordinate.'];
        }
        $loadedHash = isset($_POST['loaded_hash']) ? (string) $_POST['loaded_hash'] : null;
        // The editor in the viewer saves caption and link as they are typed: with keep_loc it leaves the place alone (a place
        // read from the photo's GPS stays "not saved yet" until the owner chooses to keep it).
        $keepLoc = ($_POST['keep_loc'] ?? '') === '1';
        $stale = false; $newHash = null;
        items_update($adminFolderReal, function (&$data) use ($bare, $caption, $web, $lat, $lng, $locLabel, $visibility, $restriction, $loadedHash, $keepLoc, &$stale, &$newHash) {
            $entry = $data['items'][$bare] ?? [];
            if ($loadedHash !== null && !hash_equals(admin_item_record_hash($entry), $loadedHash)) {
                $stale = true;
                return;
            }
            if ($caption !== '') { $entry['caption'] = $caption; } else { unset($entry['caption']); }
            if ($web !== '') { $entry['web'] = $web; } else { unset($entry['web']); }
            if (!$keepLoc) {
                if ($lat !== '' && $lng !== '') {
                    $entry['location'] = ['lat' => (float) $lat, 'lng' => (float) $lng, 'label' => $locLabel];
                    unset($entry['locationCleared']);
                } else {
                    // Cleared by the owner: what the file says is not shown in its place.
                    unset($entry['location']);
                    $entry['locationCleared'] = true;
                }
            }
            if ($visibility !== null) $entry['locationVisibility'] = $visibility;
            if ($restriction === 'inherit') { unset($entry['restriction']); } elseif ($restriction !== null) { $entry['restriction'] = $restriction; }
            $data['items'][$bare] = $entry;
            $newHash = admin_item_record_hash($entry);
        });
        if ($stale) {
            if ($isFetch) admin_json_exit(['ok' => false, 'stale' => true, 'text' => '"' . $bare . '"' . admin_stale_message()]);
            return ['type' => 'error', 'text' => '"' . $bare . '"' . admin_stale_message()];
        }
        if ($isFetch) {
            if (!empty($GLOBALS['__data_write_failed'])) admin_json_exit(['ok' => false, 'text' => data_write_failure_text(data_write_failure_place($dir, $adminFolderReal))]);
            $saved = items_read($adminFolderReal)['items'][$bare] ?? [];
            admin_json_exit(['ok' => true, 'text' => 'Saved "' . $bare . '".', 'hash' => $newHash, 'caption' => (string) ($saved['caption'] ?? ''), 'web' => (string) ($saved['web'] ?? ''),
                'lat' => isset($saved['location']) ? (string) $saved['location']['lat'] : '', 'lng' => isset($saved['location']) ? (string) $saved['location']['lng'] : '', 'label' => isset($saved['location']) ? (string) ($saved['location']['label'] ?? '') : '']);
        }
        return ['type' => 'ok', 'text' => 'Saved "' . $bare . '".'];
    }

    // Making photos private (or public again), and showing or not showing their location, one switch or many at a time.
    // What is posted is the state wanted ("on"). Switching privacy ON, or a location OFF, is always stored on the photo itself,
    // so the protection survives the folder's own setting changing later; switching the other way is stored only when the
    // photo has to differ from what its folder gives it (otherwise it simply follows the folder again).
    if ($action === 'set_item_flag' || $action === 'bulk_item_flag') {
        $flag = (string) ($_POST['flag'] ?? '');
        if (!in_array($flag, ['private', 'location'], true)) return ['type' => 'error', 'text' => 'Unknown setting.'];
        $on = ($_POST['on'] ?? '') === '1';
        // Privacy can also be asked for as a state: "private" (kept private on the photo itself), "public" (public even inside a private
        // folder) or "inherit" (no setting of its own: it follows its folder). Without it, "on" means private / back to public as before.
        $state = null;
        if ($flag === 'private' && isset($_POST['state'])) {
            $state = (string) $_POST['state'];
            if (!in_array($state, ['private', 'public', 'inherit'], true)) return ['type' => 'error', 'text' => 'Unknown setting.'];
            $on = $state === 'private';
        }
        if ($action === 'set_item_flag') {
            $names = [basename((string) ($_POST['file'] ?? ''))];
        } else {
            $decoded = json_decode((string) ($_POST['files'] ?? ''), true);
            if (!is_array($decoded) || !array_is_list($decoded) || !$decoded) return ['type' => 'error', 'text' => 'Choose at least one photo first.'];
            if (count($decoded) > 2000) return ['type' => 'error', 'text' => 'That is more photos at once than the gallery allows (2000).'];
            $names = array_values(array_unique(array_map(function ($f) { return basename((string) $f); }, $decoded)));
        }
        foreach ($names as $bare) {
            $fullPath = $adminFolderPath === null ? $bare : $adminFolderPath . '/' . $bare;
            if ($bare === '' || !in_array($fullPath, $files, true)) return ['type' => 'error', 'text' => 'Unknown file: nothing was changed.'];
        }
        $changed = 0;
        items_update($adminFolderReal, function (&$data) use ($names, $flag, $on, $state, $dir, $adminFolderPath, &$changed) {
            $folderLocationOn = !empty($data['locationEnabled']);
            foreach ($names as $bare) {
                $fullPath = $adminFolderPath === null ? $bare : $adminFolderPath . '/' . $bare;
                $entry = $data['items'][$bare] ?? [];
                $before = $entry;
                if ($flag === 'private' && $state !== null) {
                    if ($state === 'inherit') { unset($entry['restriction']); } else { $entry['restriction'] = $state; }
                } elseif ($flag === 'private') {
                    if ($on) { $entry['restriction'] = 'private'; }
                    elseif (folder_above_is_private($dir, $fullPath)) { $entry['restriction'] = 'public'; }
                    else { unset($entry['restriction']); }
                } else {
                    if (!$on) { $entry['locationVisibility'] = 'hide'; }
                    elseif ($folderLocationOn) { unset($entry['locationVisibility']); }
                    else { $entry['locationVisibility'] = 'show'; }
                }
                if ($entry !== $before) { $data['items'][$bare] = $entry; $changed++; }
            }
        });
        $n = count($names);
        $what = $flag === 'private' ? ($state === 'inherit' ? (count($names) === 1 ? 'following its folder' : 'following their folder') : ($on ? 'private' : 'public')) : ($on ? 'showing its location' : 'not showing its location');
        $text = $n === 1
            ? ($flag === 'private' ? '"' . $names[0] . '" is now ' . $what . '.' : '"' . $names[0] . '" is now ' . $what . '.')
            : $n . ' photos are now ' . $what . '.';
        if ($flag === 'private' && $on) $text .= ' Visitors can\'t see ' . ($n === 1 ? 'it' : 'them') . ' unless they have the private-view password.';
        return ['type' => 'ok', 'text' => $text];
    }

    if ($action === 'save_group_label') {
        $groupKey = (string) ($_POST['group_key'] ?? '');
        if (!isset($groups[$groupKey])) {
            return ['type' => 'error', 'text' => 'Unknown group.'];
        }
        if (isset($explicitGroups[$groupKey])) {
            return ['type' => 'error', 'text' => 'That name is controlled by a {group:} directive and can\'t be changed here.'];
        }
        $displayName = trim((string) ($_POST['display_name'] ?? ''));
        items_update($dir, function (&$data) use ($groupKey, $displayName) {
            if ($displayName === '') {
                unset($data['groups'][$groupKey]);
            } else {
                $data['groups'][$groupKey] = ['displayName' => $displayName];
            }
        });
        return ['type' => 'ok', 'text' => 'Display name saved.'];
    }

    if ($action === 'save_folder_identity') {
        // Only fields actually present in the POST are touched, so a form
        // (or an older client) that only sends one field never wipes the others
        // (the page saves each field as it is changed).
        $updates = [];
        if (array_key_exists('display_name', $_POST)) {
            $displayName = trim((string) $_POST['display_name']);
            $updates['displayName'] = $displayName !== '' ? $displayName : null;
        }
        if (array_key_exists('description', $_POST)) {
            $text = admin_clean_description($_POST['description'], 1000);
            $updates['description'] = $text !== '' ? $text : null;
        }
        if (array_key_exists('dates', $_POST)) {
            $text = admin_clean_text($_POST['dates'], 80);
            $updates['dates'] = $text !== '' ? $text : null;
        }
        if ($adminFolderPath !== null && array_key_exists('sort_order', $_POST)) {   // no position at the root
            $raw = trim((string) $_POST['sort_order']);
            if ($raw === '') {
                $updates['sortOrder'] = null;
            } elseif (preg_match('/^-?\d{1,5}$/', $raw)) {
                $updates['sortOrder'] = (int) $raw;
            } else {
                return ['type' => 'error', 'text' => 'Position must be a whole number (or blank for natural order).'];
            }
        }
        foreach (['flat_names' => 'flatNames', 'flat_notes' => 'flatNotes'] as $postKey => $itemKey) {   // this folder's own choice for Flat: "" = what Settings says
            if ($adminFolderPath !== null && array_key_exists($postKey, $_POST)) {
                $v = (string) $_POST[$postKey];
                $updates[$itemKey] = $v === '1' ? true : ($v === '0' ? false : null);
            }
        }
        if ($adminFolderPath !== null && array_key_exists('subfolder_style', $_POST)) {   // how this folder's own page shows the folders under it: "" = the site's
            $style = (string) $_POST['subfolder_style'];
            $updates['subfolderStyle'] = in_array($style, ['overview', 'flat'], true) ? $style : null;
        }
        if (array_key_exists('photo_sort_order', $_POST)) {   // this folder's own order of photos: "" = the site's
            $own = admin_photo_sort_from_post();
            $updates['photoSort'] = $own !== [] ? $own : null;
        }
        $loadedHash = isset($_POST['loaded_hash']) ? (string) $_POST['loaded_hash'] : null;
        $stale = false; $newHash = null;
        items_update($adminFolderReal, function (&$data) use ($updates, $loadedHash, &$stale, &$newHash) {
            if ($loadedHash !== null && !hash_equals(admin_record_hash(folder_identity_record($data)), $loadedHash)) {
                $stale = true;
                return;
            }
            foreach ($updates as $k => $v) {
                $data[$k] = $v;
            }
            $newHash = admin_record_hash(folder_identity_record($data));
        });
        if ($stale) {
            return ['type' => 'error', 'text' => ($adminFolderPath === null ? 'The gallery\'s settings' : 'This folder\'s settings') . admin_stale_message(), 'stale' => true];
        }
        return ['type' => 'ok', 'text' => $adminFolderPath === null ? 'Gallery settings saved.' : 'Folder settings saved.', 'hash' => $newHash];
    }

    if ($action === 'toggle_folder_restriction') {
        if ($adminFolderPath === null) {
            return ['type' => 'error', 'text' => 'Not applicable at the root — make individual items private instead.'];
        }
        $hidden = !empty($_POST['hidden']);
        items_update($adminFolderReal, function (&$data) use ($hidden) {
            if ($hidden) { $data['restriction'] = 'private'; } else { unset($data['restriction'], $data['unlisted']); }   // (public again is not "not listed" either)
        });
        return ['type' => 'ok', 'text' => 'This entire folder is now ' . ($hidden ? 'private: visitors need the private-view password to see it.' : 'public again.'),
                'counts' => folder_privacy_counts($dir, $files, $adminFolderPath)];
    }

    // Not listed: private, and the contents are the owner's alone (someone with the private-view password does not see them either). It is
    // stored as private plus a flag, so a version that doesn't know the flag still keeps the folder private. Turning it off leaves the
    // folder private; the Private switch is what makes it public.
    if ($action === 'toggle_folder_unlisted') {
        if ($adminFolderPath === null) return ['type' => 'error', 'text' => 'Not applicable at the root.'];
        $on = !empty($_POST['unlisted']);
        items_update($adminFolderReal, function (&$data) use ($on) {
            if ($on) { $data['restriction'] = 'private'; $data['unlisted'] = true; } else { unset($data['unlisted']); }
        });
        if ($on) { install_secret(true); folder_id_stamp($adminFolderReal); }   // (what a photo's address is worked out from: so every photo in it has one from now on, with nothing to press)
        $private = item_restriction(items_read($adminFolderReal)) === 'private';
        return ['type' => 'ok', 'private' => $private, 'unlisted' => $on, 'counts' => folder_privacy_counts($dir, $files, $adminFolderPath),
                'text' => $on ? 'Not listed: only you can open this folder. Visitors and people with the private-view password see nothing of it.'
                              : 'No longer not listed. The folder is still private, so people with the private-view password can open it.'];
    }

    // A Not listed folder's photo has an address (eight letters and digits) that opens that one photo for anyone who has it. This gives the
    // owner the address (the page already carries it, so this is for a folder that has no id yet and for "fresh"), and with "fresh" a new one:
    // the old stops working at once (a random string kept on the photo changes the code).
    if ($action === 'photo_address') {
        if ($adminFolderPath === null || !folder_is_unlisted($dir, $adminFolderPath)) return ['type' => 'error', 'text' => 'Only a photo in a Not listed folder has an address like this.'];
        $name = basename((string) ($_POST['file'] ?? ''));
        $rel = $adminFolderPath . '/' . $name;
        if ($name === '' || !in_array($rel, $files, true)) return ['type' => 'error', 'text' => 'Unknown file: nothing was changed.'];
        if (item_restriction(items_read($adminFolderReal)['items'][$name] ?? null) === 'private') return ['type' => 'error', 'text' => 'This photo is marked private on its own, so it has no address. Make it public first (its lock).'];
        if (install_secret(true) === '') return ['type' => 'error', 'text' => 'The gallery could not make its secret key, so it cannot make an address (is the folder writable?).'];
        $fid = folder_id_stamp($adminFolderReal);
        if ($fid === '') return ['type' => 'error', 'text' => 'This folder is not writable, so it cannot be given the id its photos\' addresses need.'];
        $fresh = !empty($_POST['fresh']);
        if ($fresh) items_update($adminFolderReal, function (&$data) use ($name) { $data['items'][$name]['addr'] = bin2hex(random_bytes(6)); });
        $code = photo_address_code($dir, $rel, items_get_all_for_files($dir, [$rel]), $fid);
        if ($code === '') return ['type' => 'error', 'text' => 'Could not make the address.'];
        return ['type' => 'ok', 'code' => $code, 'fresh' => $fresh,
                'text' => $fresh ? 'New address copied. The old one no longer opens this photo.' : 'Address copied.'];
    }

    // "Gallery of its own": visitors who arrive inside this folder are offered nothing above it (see nav_top). The gallery's own settings file
    // keeps whether any folder is marked, so a gallery that uses none reads nothing more and sets no cookie.
    if ($action === 'toggle_folder_own_gallery') {
        if ($adminFolderPath === null) return ['type' => 'error', 'text' => 'Not applicable at the root.'];
        $on = !empty($_POST['owngallery']);
        items_update($adminFolderReal, function (&$data) use ($on) { if ($on) $data['ownGallery'] = true; else unset($data['ownGallery']); });
        own_galleries_recompute($dir, $files);
        return ['type' => 'ok', 'text' => $on ? 'Visitors who arrive inside this folder are offered nothing above it.' : 'Back to ordinary: visitors who arrive inside this folder can go up as usual.'];
    }

    // Whether visitors without a link may download the originals of the photos directly in this folder (or, with no folder, on the main page).
    // Each folder stands on its own. The choice is kept in the gallery's own settings file, not in the folder.
    if ($action === 'toggle_folder_downloads') {
        $on = !empty($_POST['dl']);
        [$cf, $cr] = public_download_current();
        if ($adminFolderPath === null) {
            $cr = $on;
        } else {
            if (!in_array($adminFolderPath, all_folder_paths($files), true)) return ['type' => 'error', 'text' => 'Unknown folder: nothing was changed.'];
            if ($on && folder_is_private($dir, $adminFolderPath)) return ['type' => 'error', 'text' => 'A private folder offers nothing to visitors, so there is nothing to switch on.'];
            $cf = array_values(array_diff($cf, [$adminFolderPath])); if ($on) $cf[] = $adminFolderPath;
        }
        $why = public_download_write($cf, $cr);
        if ($why !== '') return ['type' => 'error', 'text' => $why];
        return ['type' => 'ok', 'text' => $on ? 'Visitors can now download the originals of ' . ($adminFolderPath === null ? 'the photos on the main page' : 'the photos in this folder') . '.' : 'Visitors can no longer download them.'];
    }
    // The same for every folder inside this one, once (nothing links them afterwards). Asked first (no "apply"): what would change, for the
    // confirmation; the list is always worked out here from the gallery's own files.
    if ($action === 'downloads_inside') {
        if ($adminFolderPath === null) return ['type' => 'error', 'text' => 'Not applicable at the main page.'];
        $mode = (string) ($_POST['mode'] ?? '');
        if (!in_array($mode, ['on', 'off'], true)) return ['type' => 'error', 'text' => 'Unknown choice.'];
        [$cf, $cr] = public_download_current();
        [$inPub, $inPriv] = admin_download_folders_inside($dir, $files, $adminFolderPath);
        $targets = $mode === 'on' ? $inPub : array_values(array_intersect(array_merge($inPub, $inPriv), $cf));
        $short = function ($p) use ($adminFolderPath) { return substr($p, strlen($adminFolderPath) + 1); };
        $names = implode(', ', array_map($short, array_slice($targets, 0, 3))) . (count($targets) > 3 ? ' and ' . (count($targets) - 3) . ' more' : '');
        if (empty($_POST['apply'])) {
            if (!$targets) return ['type' => 'ok', 'n' => 0, 'text' => $mode === 'on' ? 'There is no public folder inside this one to switch on.' : 'None of the folders inside this one has it switched on.'];
            $text = $mode === 'on'
                ? 'Switch on downloads in ' . count($targets) . ' folder' . (count($targets) === 1 ? '' : 's') . ' inside this one: ' . $names . '.' . ($inPriv ? ' ' . count($inPriv) . ' private folder' . (count($inPriv) === 1 ? ' is' : 's are') . ' skipped.' : '') . ' The original files of ' . (count($targets) === 1 ? 'that folder become' : 'these folders become') . ' downloadable by anyone who opens ' . (count($targets) === 1 ? 'it' : 'them') . '.'
                : 'Switch off downloads in ' . count($targets) . ' folder' . (count($targets) === 1 ? '' : 's') . ' inside this one: ' . $names . '.';
            return ['type' => 'ok', 'n' => count($targets), 'text' => $text];
        }
        $cf = $mode === 'on' ? array_merge($cf, $targets) : array_values(array_diff($cf, $targets));
        $why = public_download_write($cf, $cr);
        if ($why !== '') return ['type' => 'error', 'text' => $why];
        $row = admin_downloads_inside_html($dir, $files, $adminFolderPath, array_values(array_unique($cf)));
        if (!$targets) return ['type' => 'ok', 'n' => 0, 'html' => $row, 'short' => 'Nothing to change.', 'text' => $mode === 'on' ? 'There is no public folder inside this one to switch on.' : 'None of the folders inside this one had it switched on.'];
        return ['type' => 'ok', 'n' => count($targets), 'html' => $row, 'short' => ($mode === 'on' ? 'Switched on in ' : 'Switched off in ') . count($targets) . ' folder' . (count($targets) === 1 ? '' : 's') . '.', 'text' => ($mode === 'on' ? 'Switched on in ' : 'Switched off in ') . count($targets) . ' folder' . (count($targets) === 1 ? '' : 's') . ': ' . $names . '.' . ($mode === 'on' && $inPriv ? ' ' . count($inPriv) . ' private folder' . (count($inPriv) === 1 ? '' : 's') . ' skipped.' : '')];
    }

    // What a Not listed folder's photo addresses open: off, the photo in the gallery's viewer; on, the bare picture or video.
    if ($action === 'toggle_folder_address_file') {
        if ($adminFolderPath === null) return ['type' => 'error', 'text' => 'Not applicable at the root.'];
        $on = !empty($_POST['addrfile']);
        items_update($adminFolderReal, function (&$data) use ($on) { if ($on) $data['addressFile'] = true; else unset($data['addressFile']); });
        return ['type' => 'ok', 'label' => $on ? 'Links send the bare file' : 'Links open in the gallery', 'text' => $on ? 'A photo\'s address now sends the bare picture (or video), with no page around it.' : 'A photo\'s address opens the photo in the gallery.'];
    }

    if ($action === 'toggle_folder_originals') {
        if ($adminFolderPath === null) return ['type' => 'error', 'text' => 'Not applicable at the root.'];
        $on = !empty($_POST['originals']);
        items_update($adminFolderReal, function (&$data) use ($on) { if ($on) $data['allowOriginals'] = true; else unset($data['allowOriginals']); });
        return ['type' => 'ok', 'text' => $on ? 'Someone opening a photo by its address can download the original.' : 'Photos opened by their address can\'t be downloaded.'];
    }

    if ($action === 'arrange_items') {
        // Placing photos by hand: they go next to another photo of the folder and stay with it whatever the sort (see media_stacks_change).
        $mode = (string) ($_POST['mode'] ?? '');
        if (!in_array($mode, ['place', 'release', 'reset'], true)) return ['type' => 'error', 'text' => 'Unknown request.'];
        $names = []; $target = ''; $side = 'after';
        if ($mode !== 'reset') {
            $decoded = json_decode((string) ($_POST['files'] ?? ''), true);
            if (!is_array($decoded) || !array_is_list($decoded) || !$decoded) return ['type' => 'error', 'text' => 'Choose at least one photo first.'];
            if (count($decoded) > 2000) return ['type' => 'error', 'text' => 'That is more photos at once than the gallery allows (2000).'];
            $names = array_values(array_unique(array_map(function ($f) { return basename((string) $f); }, $decoded)));
            foreach ($names as $bare) {
                if ($bare === '' || !in_array($adminFolderPath === null ? $bare : $adminFolderPath . '/' . $bare, $files, true)) return ['type' => 'error', 'text' => 'Unknown file: nothing was changed.'];
            }
        }
        if ($mode === 'place') {
            $target = basename((string) ($_POST['target'] ?? ''));
            $side = (string) ($_POST['side'] ?? '') === 'before' ? 'before' : 'after';
            if ($target === '' || !in_array($adminFolderPath === null ? $target : $adminFolderPath . '/' . $target, $files, true)) return ['type' => 'error', 'text' => 'Unknown file: nothing was changed.'];
            if (in_array($target, $names, true)) return ['type' => 'error', 'text' => 'A photo can\'t be placed next to itself.'];
        }
        items_update($adminFolderReal, function (&$data) use ($mode, $names, $target, $side) { media_stacks_change($data, $mode, $names, $target, $side); });
        $n = count($names);
        if ($mode === 'reset') return ['type' => 'ok', 'text' => 'Every photo in this folder is back in its sorted place.'];
        if ($mode === 'release') return ['type' => 'ok', 'text' => ($n === 1 ? '"' . $names[0] . '" is' : $n . ' photos are') . ' back in the sorted place.'];
        return ['type' => 'ok', 'text' => ($n === 1 ? '"' . $names[0] . '" now sits' : $n . ' photos now sit') . ' ' . $side . ' "' . $target . '".'];
    }

    if ($action === 'set_cover') {
        $bare = basename((string) ($_POST['file'] ?? ''));
        if (!in_array($adminFolderPath === null ? $bare : $adminFolderPath . '/' . $bare, $files, true)) {
            return ['type' => 'error', 'text' => 'Unknown file.'];
        }
        items_update($adminFolderReal, function (&$data) use ($bare) {
            $data['cover'] = $bare;
            unset($data['coverFollow']);   // (the latest choice is the one that counts)
        });
        return ['type' => 'ok', 'text' => '"' . $bare . '" set as ' . ($adminFolderPath === null ? 'the front page\'s cover photo (a shared link\'s preview may use it).' : 'this folder\'s cover photo.')];
    }

    // The cover of a folder that holds folders: follow the cover of one folder under it, a photo from anywhere inside it, or back to automatic.
    // What it follows or holds is checked against the folder's own files here, never taken from the request.
    if ($action === 'set_folder_cover') {
        if ($adminFolderPath === null) return ['type' => 'error', 'text' => 'Not applicable at the root.'];
        $prefix = $adminFolderPath . '/'; $mode = (string) ($_POST['mode'] ?? '');
        if ($mode === 'auto') {
            items_update($adminFolderReal, function (&$data) { unset($data['cover'], $data['coverFollow']); });
            $text = 'The cover is automatic again.';
        } elseif ($mode === 'follow') {
            $child = (string) ($_POST['child'] ?? '');
            $has = false; foreach ($files as $f) if (strncmp($f, $prefix . $child . '/', strlen($prefix . $child) + 1) === 0) { $has = true; break; }
            if ($child === '' || strpbrk($child, "/\\") !== false || !$has) return ['type' => 'error', 'text' => 'Unknown folder: nothing was changed.'];
            items_update($adminFolderReal, function (&$data) use ($child) { unset($data['cover']); $data['coverFollow'] = $child; });
            $text = 'This folder now shows the cover of that folder.';
        } elseif ($mode === 'pin') {
            $rel = (string) ($_POST['file'] ?? '');
            if ($rel === '' || strpos($rel, '/') === false || !in_array($prefix . $rel, $files, true)) return ['type' => 'error', 'text' => 'Unknown photo: nothing was changed.'];
            items_update($adminFolderReal, function (&$data) use ($rel) { $data['cover'] = $rel; unset($data['coverFollow']); });
            $text = '"' . basename($rel) . '" is now this folder\'s cover.';
        } else return ['type' => 'error', 'text' => 'Unknown choice.'];
        return ['type' => 'ok', 'text' => $text, 'cover' => admin_cover_state($dir, $files, $adminFolderPath)];
    }
    // The photos inside one folder under this one (the first 300, in the folder's own order), for choosing one as the cover.
    if ($action === 'cover_photos') {
        if ($adminFolderPath === null) return ['type' => 'error', 'text' => 'Not applicable at the root.'];
        $prefix = $adminFolderPath . '/'; $child = (string) ($_POST['child'] ?? '');
        if ($child === '' || strpbrk($child, "/\\") !== false) return ['type' => 'error', 'text' => 'Unknown folder.'];
        $under = array_values(array_filter($files, function ($f) use ($prefix, $child) { return strncmp($f, $prefix . $child . '/', strlen($prefix . $child) + 1) === 0 && !is_video_file($f); }));
        $out = [];
        foreach (array_slice($under, 0, 300) as $f) $out[] = ['path' => substr($f, strlen($prefix)), 'url' => img_url($f, 'cover')];
        return ['type' => 'ok', 'text' => '', 'photos' => $out, 'more' => count($under) > 300];
    }

    if ($action === 'save_map_settings') {
        $targetReal = $adminFolderPath === null ? $dir : $adminFolderReal;
        $updates = [];
        if (isset($_POST['map_enabled'])) $updates['mapEnabled'] = $_POST['map_enabled'] === '1';
        if (isset($_POST['map_radius'])) {
            $rawRadius = trim((string) $_POST['map_radius']);
            if ($rawRadius === '') {
                $updates['mapRadius'] = null;
            } elseif (is_numeric($rawRadius) && (float) $rawRadius >= 0 && (float) $rawRadius <= 10000) {
                $updates['mapRadius'] = (float) $rawRadius == (int) $rawRadius ? (int) $rawRadius : (float) $rawRadius;
            } else {
                return ['type' => 'error', 'text' => 'Pin grouping must be a number of miles from 0 (no grouping) to 10000, or blank to use the site-wide setting.'];
            }
        }
        if (!$updates) return ['type' => 'error', 'text' => 'Nothing to save.'];
        items_update($targetReal, function (&$data) use ($updates) {
            foreach ($updates as $k => $v) $data[$k] = $v;
        });
        $where = $adminFolderPath === null ? 'the gallery\'s front page' : 'this folder\'s page';
        $parts = [];
        if (array_key_exists('mapEnabled', $updates)) {
            if ($updates['mapEnabled']) {
                $part = 'A map now shows on ' . $where . ', from the photos whose location is visible.';
                if (!ENABLE_PHOTO_LOCATION) $part .= ' It stays empty until Allow photo locations is switched on in Settings → Location data.';
            } else {
                $part = 'The map no longer shows on ' . $where . '. Nothing is deleted.';
            }
            $parts[] = $part;
        }
        if (array_key_exists('mapRadius', $updates)) {
            $parts[] = $updates['mapRadius'] === null ? 'This map uses the default pin grouping.' : 'Photos within ' . $updates['mapRadius'] . ' mile' . ($updates['mapRadius'] == 1 ? '' : 's') . ' of each other share a pin.';
        }
        $text = 'Saved. ' . implode(' ', $parts);
        return ['type' => 'ok', 'text' => $text];
    }

    // "Keep the location in files visitors get": this folder's photos whose location the gallery shows are sent with it, though Scrub location data is
    // on for the gallery. A location the gallery hides is still taken out. Only the photos directly in the folder.
    if ($action === 'toggle_keep_location') {
        $on = !empty($_POST['keeploc']);
        $targetReal = $adminFolderPath === null ? $dir : $adminFolderReal;
        items_update($targetReal, function (&$data) use ($on) { if ($on) $data['keepLocation'] = true; else unset($data['keepLocation']); });
        return ['type' => 'ok', 'text' => $on ? 'Files visitors get from here keep the location, where the gallery shows it.' : 'Files visitors get from here lose the location, as everywhere else.'];
    }

    if ($action === 'toggle_location_enabled') {
        $enabled = !empty($_POST['enabled']);
        $targetReal = $adminFolderPath === null ? $dir : $adminFolderReal;
        items_update($targetReal, function (&$data) use ($enabled) {
            $data['locationEnabled'] = $enabled;
        });
        if (!$enabled) {
            return ['type' => 'ok', 'text' => 'Location sharing disabled for this folder. Saved coordinates are kept, just not shown.'];
        }
        $prefix = $adminFolderPath === null ? '' : $adminFolderPath . '/';
        $loose = split_folder_level($files, $prefix)['loose'];
        $text = 'Location sharing enabled for this folder.';
        if (!ENABLE_PHOTO_LOCATION) {
            $text .= ' Nothing shows yet, because Allow photo locations is off for the whole gallery (Settings → Location data).';
        } else {
            // Nothing is read now: the gallery has already read what its files say (when their pictures were made, or by Prepare media).
            $shown = 0;
            foreach (items_get_all_for_files($dir, $loose) as $e) if (!empty($e['location']) && ($e['locationVisibility'] ?? 'inherit') !== 'hide') $shown++;
            $sum = media_folder_summary($dir, $targetReal, $loose);
            if ($shown > 0) $text .= ' ' . $shown . ' photo' . ($shown === 1 ? ' has' : 's have') . ' a location and now show' . ($shown === 1 ? 's' : '') . ' it to visitors and on the map. To keep one private, press its pencil and switch off Location (or clear its coordinates).';
            else $text .= ' None of its photos has a location to show; add coordinates by hand on any photo.';
            if ($sum['unread'] > 0) $text .= ' ' . $sum['unread'] . ' file' . ($sum['unread'] === 1 ? ' hasn\'t' : 's haven\'t') . ' been read yet: Prepare media (in Settings) reads them.';
            if ($sum['legacy'] > 0) $text .= ' ' . $sum['legacy'] . ' ' . ($sum['legacy'] === 1 ? 'was' : 'were') . ' read under older rules and found nothing, and are left alone: "Read everything again" in Prepare media reads them again.';
            if ($sum['unreadable'] > 0) $text .= ' ' . $sum['unreadable'] . ' couldn\'t be read at all (' . implode(', ', array_slice($sum['bad'], 0, 3)) . ($sum['unreadable'] > 3 ? ', …' : '') . ').';
        }
        return ['type' => 'ok', 'text' => $text];
    }

    if ($action === 'add_gallery_marker') {
        $mdFile = basename((string) ($_POST['md_file'] ?? ''));
        $path = $adminFolderReal . '/' . $mdFile;
        if (strtolower(pathinfo($mdFile, PATHINFO_EXTENSION)) !== 'md' || !is_file($path)) {
            return ['type' => 'error', 'text' => 'Unknown file.'];
        }
        $content = (string) file_get_contents($path);
        if (has_gallery_marker($content)) {
            return ['type' => 'error', 'text' => 'Already active.'];
        }
        if (@file_put_contents($path, "{gallery}\n" . $content) === false) {
            return ['type' => 'error', 'text' => 'Could not write to "' . $mdFile . '".'];
        }
        return ['type' => 'ok', 'text' => '"' . $mdFile . '" is now active.'];
    }

    if ($action === 'save_notes') {
        $existing = basename((string) ($_POST['md_file'] ?? ''));
        $newName = trim((string) ($_POST['new_name'] ?? ''));
        $content = (string) ($_POST['content'] ?? '');
        $target = basename($newName !== '' ? $newName : $existing);
        if ($target === '') {
            return ['type' => 'error', 'text' => 'A filename is required.'];
        }
        if (strtolower(pathinfo($target, PATHINFO_EXTENSION)) !== 'md') {
            $target .= '.md';
        }
        $targetPath = $adminFolderReal . '/' . $target;
        if (@file_put_contents($targetPath, $content) === false) {
            return ['type' => 'error', 'text' => 'Could not write to "' . $target . '".'];
        }
        if (!has_gallery_marker($content)) {
            return ['type' => 'ok', 'text' => 'Saved to "' . $target . '", but it is not active: its first line is not {gallery}, so the gallery ignores it. Put {gallery} on the first line to switch it on.'];
        }
        return ['type' => 'ok', 'text' => 'Notes saved to "' . $target . '".'];
    }

    if ($action === 'delete_notes') {
        $mdFile = basename((string) ($_POST['md_file'] ?? ''));
        $path = $adminFolderReal . '/' . $mdFile;
        if (strtolower(pathinfo($mdFile, PATHINFO_EXTENSION)) !== 'md' || !is_file($path)) {
            return ['type' => 'error', 'text' => 'Unknown file.'];
        }
        if (!@unlink($path)) {
            return ['type' => 'error', 'text' => 'Could not delete "' . $mdFile . '".'];
        }
        return ['type' => 'ok', 'text' => '"' . $mdFile . '" deleted.'];
    }

    if ($action === 'export_tree') {
        return ['type' => 'export_tree'];
    }

    if ($action === 'export_items') {
        return ['type' => 'export'];
    }

    return ['type' => 'error', 'text' => 'Unknown action.'];
}

