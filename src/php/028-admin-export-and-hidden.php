/** Streams the current folder's own .gallery-items.php back out as a
 *  download — the only backup this irreplaceable, hand-entered data has
 *  at all. One caution worth repeating next to the download itself, not
 *  a design change: once a hidden flag exists in this file (a later
 *  phase), the export becomes a new way to reveal what's hidden and that
 *  it exists — mishandling the exported file is its own leak vector,
 *  independent of the actual hidden content. */
/** Every folder's .gallery-items.php in one download, keyed by relative
 *  folder path ("" is the root) — same irreplaceable data, same leak
 *  caution as admin_export_items(), just the whole tree at once. */
function admin_export_tree($dir, $files) {
    $tree = ['' => items_read($dir)];
    foreach (all_folder_paths($files) as $path) {
        $data = items_read(resolve_real_path($dir, $path));
        if ($data !== items_default()) {
            $tree[$path] = $data;
        }
    }
    header('Content-Type: application/json');
    shell_frame_headers();   // (a download may sit in this site's own admin frame, as it does on a desktop; no other site may frame it)
    header('Content-Disposition: attachment; filename="whole-tree-gallery-items.json"');
    echo json_encode($tree, JSON_PRETTY_PRINT);
}

function admin_export_items($adminFolderPath, $adminFolderReal) {
    $data = items_read($adminFolderReal);
    $name = ($adminFolderPath === null ? 'root' : slugify($adminFolderPath)) . '-gallery-items.json';
    header('Content-Type: application/json');
    shell_frame_headers();   // (a download may sit in this site's own admin frame, as it does on a desktop; no other site may frame it)
    header('Content-Disposition: attachment; filename="' . $name . '"');
    echo json_encode($data, JSON_PRETTY_PRINT);
}

/** True whenever anything in the whole tree is currently flagged hidden
 *  — drives the admin tool's persistent lockdown warning (see
 *  render_admin_page): reappearing every time the screen loads, not a
 *  one-time dismissible notice, since the goal is an honest, ongoing
 *  signal rather than implying a privacy guarantee this script can't
 *  actually confirm without the webserver-level step. */
function admin_any_hidden($dir, $files, $folderGroupKeys) {
    foreach (all_folder_paths($files) as $folderKey) {
        if (item_restriction(items_read(resolve_real_path($dir, $folderKey))) === 'private') {
            return true;
        }
    }
    foreach ($files as $file) {
        $entry = items_read(containing_folder_real_path($dir, $file))['items'][basename($file)] ?? null;
        if (item_restriction($entry) === 'private') {
            return true;
        }
    }
    return false;
}

/**
 * Everything hidden, across the whole tree, in one place — since
 * confirming "nothing is exposed that shouldn't be" by clicking into every
 * folder individually doesn't scale once depth is arbitrary. Three tiers,
 * not a flat "hidden" list — folders hidden entirely, individual items
 * hidden inside an otherwise-public folder, and individual items
 * deliberately shown despite sitting inside a hidden folder — blurring
 * them into one list risks reading as complete when it isn't. Feeds the
 * admin's "Hidden" tab.
 */
function admin_hidden_data($dir, $files) {
    $hiddenFolders = [];
    foreach (all_folder_paths($files) as $folderKey) {
        if (item_restriction(items_read(resolve_real_path($dir, $folderKey))) === 'private') {
            $hiddenFolders[] = $folderKey;
        }
    }
    $hiddenFolderSet = array_fill_keys($hiddenFolders, true);

    $hiddenItems = [];
    $shownDespiteHiddenFolder = [];
    foreach ($files as $file) {
        $entry = items_read(containing_folder_real_path($dir, $file))['items'][basename($file)] ?? null;
        $own = item_restriction($entry);
        // Hidden by any ancestor, at any depth.
        $folderHidden = false;
        $ancestorPath = '';
        foreach (explode('/', dirname($file)) as $segment) {
            if ($segment === '.') break;
            $ancestorPath = $ancestorPath === '' ? $segment : $ancestorPath . '/' . $segment;
            if (isset($hiddenFolderSet[$ancestorPath])) { $folderHidden = true; break; }
        }
        if ($own === 'private') {
            $hiddenItems[] = $file;
        } elseif ($folderHidden && $own === 'public') {
            $shownDespiteHiddenFolder[] = $file;
        }
    }

    return ['folders' => $hiddenFolders, 'items' => $hiddenItems, 'shown' => $shownDespiteHiddenFolder];
}

/** The folder a file is in ('' at the top level). */
function admin_file_folder($file) { $d = dirname((string) $file); return $d === '.' ? '' : $d; }

/** Where an item is edited: the gallery's viewer open on that photo, with the editor up (the pencil's own address), in that photo's
 *  own folder, so that closing the viewer leaves you on the folder's grid and not on the main page. (HTML-escaped, for an attribute.) */
function admin_item_href($file) {
    $folder = admin_file_folder($file);
    return '?' . ($folder !== '' ? 'folder=' . htmlspecialchars(rawurlencode($folder), ENT_QUOTES, 'UTF-8') . '&amp;' : '') . 'photo=' . htmlspecialchars(rawurlencode($file), ENT_QUOTES, 'UTF-8') . '&amp;edit=1';
}

/** Where a photo is shown in its folder: the folder's grid scrolled to that photo, which is ringed for a moment (periwinkle; the folder's
 *  cover is $as 'cover', gold). The place to land from a thumbnail or a file name; from there the pencil is a press away. (HTML-escaped.) */
function admin_find_href($file, $as = '') {
    $folder = admin_file_folder($file);
    return '?' . ($folder !== '' ? 'folder=' . htmlspecialchars(rawurlencode($folder), ENT_QUOTES, 'UTF-8') . '&amp;' : '') . 'find=' . htmlspecialchars(rawurlencode($file), ENT_QUOTES, 'UTF-8') . ($as === 'cover' ? '&amp;as=cover' : '');
}

/** Addresses for the owner's browser to try, to see whether the web server rules in render_server_rules_help are in place:
 *  one photo and one thumbnail from the cache folder (which the server should refuse), and one folder's tidy link. Only
 *  paths, in the same folder as this script; the browser makes the requests, so nothing is fetched from the server side. */
function lock_probe_targets($dir, $files) {
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
    $enc = function ($path) { return implode('/', array_map('rawurlencode', explode('/', $path))); };
    $out = ['photo' => '', 'cache' => '', 'folder' => '', 'folderUrl' => '', 'file' => '', 'count' => count($files)];
    foreach ($files as $f) {   // (a photo brought in from elsewhere on the server has no address of its own to ask for)
        if (preg_match('/\.(jpe?g|png|gif|webp|bmp|heic|heif|mov|mp4|m4v)$/i', $f) && file_is_under_web_root(resolve_real_path($dir, $f), $f)) { $out['photo'] = $base . $enc($f); $out['file'] = $f; break; }
    }
    foreach ($files as $f) {   // a folder anyone may open, for the tidy-link check (/kb/Folder/)
        $slash = strpos($f, '/');
        if ($slash === false) continue;
        $top = substr($f, 0, $slash);
        if (folder_restriction_cached($dir, $top) === 'private' || folder_above_is_private($dir, $f)) continue;
        $out['folder'] = $top; $out['folderUrl'] = $base . $enc($top) . '/';
        break;
    }
    $cached = @glob(rtrim($dir, '/') . '/.gallery-cache/*.jpg');
    if (!empty($cached)) $out['cache'] = $base . '.gallery-cache/' . rawurlencode(basename($cached[0]));
    return $out;
}

/** The nginx location that keeps the photo and video files from being fetched by address (and that the internal redirect needs), for an address like /kb/. */
function nginx_internal_location_rule($loc) {
    return "location ~* ^" . $loc . ".*\\.(jpe?g|png|gif|webp|bmp|heic|heif|mov|mp4|m4v)\$ {\n    internal;\n}\n";
}

/** The one nginx location the "nginx internal redirect" setting needs, written for this gallery's address and folded under that setting. It is the same
 *  rule as the one in "Keep private photos from being fetched by address", which also has the Apache version. Nothing at the top of a site: there the rule
 *  would block every image, and the setting has no folder to be set to (render_server_rules_help says so). */
function render_accel_rule_help() {
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    if ($base === '') return '';
    $loc = $base . '/';
    ob_start();
?>
                <details class="fold accel-rule">
                  <summary>Show the rule</summary>
                  <p>Add inside the site's <code>server { }</code> block, then run <code>sudo nginx -t &amp;&amp; sudo systemctl reload nginx</code>. Written for this gallery's address, <code><?= $esc($loc) ?></code>. It is the same rule as the one under <em>Keep private photos from being fetched by address</em>, below.</p>
                  <div class="rules-box"><button type="button" class="secondary copy-rules">Copy</button><pre><code><?= $esc(nginx_internal_location_rule($loc)) ?></code></pre></div>
                </details>
<?php
    return ob_get_clean();
}

/** Ready-to-use web server rules that keep the photo files themselves from being fetched by address (private items are
 *  only private through this script otherwise), written out for this gallery's own address, in two collapsed blocks:
 *  nginx and Apache. Shown on the Private page and in Settings so nobody has to leave the admin to set it up. */
function render_server_rules_help($withTidyLinks = false) {
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    $loc = $base === '' ? '/' : $base . '/';
    $nginx = "# The gallery's generated data files (captions, GPS, privacy flags, stats, login throttle, thumbnail cache):\n"
        . "# never public. (A second layer: the .php ones already answer 404 on their own.)\n"
        . "location ~ /\\.gallery- {\n    return 404;\n}\n\n"
        . "# The photo and video files themselves: reachable only through index.php.\n"
        . nginx_internal_location_rule($loc);
    $apache = "# The photo and video files themselves: reachable only through index.php.\n"
        . "# ((?i) matters: camera files are often IMG_0412.JPG.)\n"
        . "<FilesMatch \"(?i)\\.(jpe?g|png|gif|webp|bmp|heic|heif|mov|mp4|m4v)\$\">\n    Require all denied\n</FilesMatch>\n\n"
        . "# The gallery's generated data files (a second layer).\n"
        . "<FilesMatch \"^\\.gallery-\">\n    Require all denied\n</FilesMatch>\n";
    $prettyNginx = "location ~ ^" . $loc . ".+/\$ {\n    rewrite ^" . $loc . "(.+)/\$ " . $loc . "index.php?folder=\$1 last;\n}\n";
    $prettyApache = "RewriteEngine On\nRewriteRule ^(.+)/\$ index.php?folder=\$1 [B,L,QSA]\n";
    ob_start();
?>
    <div class="rules-help">
      <div data-lock-rules>
      <details class="fold">
        <summary><strong>nginx</strong> (strongest; needs shell access to the server)</summary>
        <p>Add inside the site's <code>server { }</code> block, first location above the second, then run <code>sudo nginx -t &amp;&amp; sudo systemctl reload nginx</code>. Written for this gallery's address, <code><?= $esc($loc) ?></code>.<?= $base === '' ? ' <strong>At the top of the site the second rule would block every image on it: narrow its path first.</strong>' : '' ?></p>
        <div class="rules-box"><button type="button" class="secondary copy-rules">Copy</button><pre><code><?= $esc($nginx) ?></code></pre></div>
        <p>Then set <em>nginx internal redirect</em> (Settings → Server setup) to <code><?= $esc($base === '' ? '/' : $base) ?></code>. Test: <code>curl -I https://yoursite.com<?= $esc($loc) ?>some-photo.jpg</code> should answer 404. Behind <code>proxy_pass</code> instead of php-fpm, pass the real client address and protocol on.</p>
      </details>
      <details class="fold">
        <summary><strong>Apache</strong> (blunter, but works on FTP-only hosting)</summary>
        <p>Save as <code>.htaccess</code> in the gallery's folder.</p>
        <div class="rules-box"><button type="button" class="secondary copy-rules">Copy</button><pre><code><?= $esc($apache) ?></code></pre></div>
        <p>A bare photo address then answers 403. It only works if the server reads <code>.htaccess</code> here (<code>AllowOverride All</code>), so check with <code>curl -I</code>. Scripts that log in with an <code>Authorization</code> header on PHP-FPM also need <code>CGIPassAuth On</code>.</p>
      </details>
      </div>
      <?php if ($withTidyLinks): ?>
      <div class="lock-result" data-tidy-status></div>
      <div data-tidy-rules>
      <p class="rules-sub">Optional tidy folder links: <code><?= $esc($loc) ?>?folder=Europe/France</code> always works; with these, <code><?= $esc($loc) ?>Europe/France/</code> does too.</p>
      <details class="fold">
        <summary><strong>nginx</strong> folder links like <?= $esc($loc) ?>Europe/France/</summary>
        <p>Add inside <code>server { }</code>, next to the rules above. It matches only paths ending in <code>/</code> and needs php-fpm to pass the original <code>REQUEST_URI</code> (the standard <code>fastcgi_param</code> does).<?= $base === '' ? ' <strong>At the top of the site it would catch every folder address: narrow its path first.</strong>' : '' ?></p>
        <div class="rules-box"><button type="button" class="secondary copy-rules">Copy</button><pre><code><?= $esc($prettyNginx) ?></code></pre></div>
      </details>
      <details class="fold">
        <summary><strong>Apache</strong> folder links like <?= $esc($loc) ?>Europe/France/</summary>
        <p>Add to <code>.htaccess</code> (needs <code>mod_rewrite</code> and <code>AllowOverride FileInfo</code>). Keep the <code>B</code> flag: it escapes spaces, <code>&amp;</code>, <code>+</code> and <code>#</code>.</p>
        <div class="rules-box"><button type="button" class="secondary copy-rules">Copy</button><pre><code><?= $esc($prettyApache) ?></code></pre></div>
      </details>
      </div>
      <?php endif; ?>
    </div>
<?php
    return ob_get_clean();
}

/** Everything a folder's own record or a photo's record in it can hold, and whether the Edited tab lists it ('shown') or it is kept for the gallery's own
 *  use and is not something the owner set ('internal'). A test reads this list: any key the admin code saves that is on neither list fails until
 *  it is put here, and any 'shown' key must be named in admin_edited_overview below. */
function edited_record_keys() {
    return [
        'folder' => [
            'displayName' => 'shown', 'description' => 'shown', 'dates' => 'shown', 'sortOrder' => 'shown', 'restriction' => 'shown', 'unlisted' => 'shown',
            'ownGallery' => 'shown', 'allowOriginals' => 'shown', 'addressFile' => 'shown', 'keepLocation' => 'shown', 'subfolderStyle' => 'shown', 'flatNames' => 'shown', 'flatNotes' => 'shown', 'locationEnabled' => 'shown',
            'mapEnabled' => 'shown', 'mapRadius' => 'shown', 'photoSort' => 'shown', 'groups' => 'shown', 'stacks' => 'shown', 'cover' => 'shown', 'coverFollow' => 'shown',
            'folderId' => 'internal', 'pageviews' => 'internal', 'items' => 'internal',
        ],
        'photo' => [
            'caption' => 'shown', 'web' => 'shown', 'location' => 'shown', 'locationCleared' => 'shown', 'locationVisibility' => 'shown', 'restriction' => 'shown', 'addr' => 'shown',
            'gpsChecked' => 'internal',
        ],
    ];
}

/** Everything the owner has set by hand, for the Edited tab: per folder (those with anything), its own settings (hidden, name, description,
 *  dates, position, cover, location and map switches, photo order, stacks, groups) and, for each photo or video, what was set on it
 *  (private or public on its own, a caption, an information link, a location typed in, labelled or removed on purpose, its own location
 *  switch). What the gallery read from the files is never listed: it was not set by hand. $hidden is admin_hidden_data()'s result (or null). */
function admin_edited_overview($dir, $files, $hidden) {
    // ('restriction', private or public on its own, comes in through $hidden: the private and public flags of folders and photos.)
    $privFolders = array_fill_keys($hidden['folders'] ?? [], true);
    $privItems = array_fill_keys($hidden['items'] ?? [], true);
    $shownItems = array_fill_keys($hidden['shown'] ?? [], true);
    $label = function ($path) use ($dir) {
        if ($path === '') return 'Root';
        $parts = []; $acc = '';
        foreach (explode('/', $path) as $seg) {
            $acc = $acc === '' ? $seg : $acc . '/' . $seg;
            $l = folder_display_meta($dir, $acc)['label'];
            $parts[] = $l !== '' ? $l : $seg;
        }
        return implode(' / ', $parts);
    };
    $byFolder = [];
    foreach ($files as $file) { $slash = strrpos($file, '/'); $byFolder[$slash === false ? '' : substr($file, 0, $slash)][] = $file; }
    $paths = array_merge([''], all_folder_paths($files));
    $folders = []; $c = ['all' => 0, 'private' => 0, 'caption' => 0, 'link' => 0, 'location' => 0, 'address' => 0, 'folder' => 0, 'photos' => 0];
    foreach ($paths as $path) {
        $real = $path === '' ? $dir : resolve_real_path($dir, $path);
        if (!$real || !is_dir($real)) continue;
        $d = items_read($real);
        $set = [];
        if (isset($privFolders[$path])) $set[] = ['hid', 'Hidden folder'];
        if (is_string($d['displayName'] ?? null) && $d['displayName'] !== '') $set[] = ['', 'Own name: ' . $d['displayName']];
        if (is_string($d['description'] ?? null) && $d['description'] !== '') $set[] = ['', 'Description'];
        if (is_string($d['dates'] ?? null) && $d['dates'] !== '') $set[] = ['', 'Dates: ' . $d['dates']];
        if (is_int($d['sortOrder'] ?? null)) $set[] = ['', 'Position ' . $d['sortOrder']];
        if (!empty($d['unlisted']) && isset($privFolders[$path])) $set[] = ['hid', 'Not listed'];
        if (!empty($d['ownGallery'])) $set[] = ['', 'Gallery of its own'];
        if (!empty($d['allowOriginals'])) $set[] = ['', 'Originals can be downloaded from a photo\'s link'];
        if (($d['keepLocation'] ?? null) === true) $set[] = ['loc', 'Visitors\' copies keep the location'];
        if (!empty($d['addressFile']) && !empty($d['unlisted'])) $set[] = ['', 'Photo links send the bare file'];
        if (in_array($d['subfolderStyle'] ?? '', ['overview', 'flat'], true)) $set[] = ['', 'Folders under it: ' . ($d['subfolderStyle'] === 'flat' ? 'Flat' : 'Cards')];
        if (is_bool($d['flatNames'] ?? null)) $set[] = ['', 'Folder names ' . ($d['flatNames'] ? 'shown' : 'hidden') . ' (Flat)'];
        if (is_bool($d['flatNotes'] ?? null)) $set[] = ['', 'Folder notes ' . ($d['flatNotes'] ? 'shown' : 'hidden') . ' (Flat)'];
        if (!empty($d['stacks']) && is_array($d['stacks'])) { $nStacks = count(array_filter($d['stacks'], function ($st) { return is_array($st) && count((array) ($st['files'] ?? [])) > 1; })); if ($nStacks) $set[] = ['', $nStacks . ' stack' . ($nStacks === 1 ? '' : 's') . ' of photos']; }
        if (ENABLE_PUBLIC_DOWNLOAD && (($path === '' && PUBLIC_DOWNLOAD_ROOT) || ($path !== '' && in_array($path, PUBLIC_DOWNLOAD_FOLDERS, true)))) $set[] = ['', $path === '' ? 'Visitors can download the photos on this page' : 'Visitors can download originals'];
        if (!empty($d['locationEnabled'])) $set[] = ['loc', 'Locations on'];
        if (array_key_exists('mapEnabled', $d) && $d['mapEnabled'] !== null) $set[] = ['', 'Map ' . ($d['mapEnabled'] ? 'on' : 'off') . ' here'];
        if (array_key_exists('mapRadius', $d) && $d['mapRadius'] !== null) $set[] = ['', 'Map grouping set'];
        if (is_string($d['photoSort'] ?? null) && $d['photoSort'] !== '') $set[] = ['', 'Photo order set'];
        if (!empty($d['groups'])) $set[] = ['', count((array) $d['groups']) . ' group' . (count((array) $d['groups']) === 1 ? '' : 's')];
        // The folder's cover photo, shown as a picture in its card (not as a setting): its path, or missing when the file is gone.
        $cover = null;
        if (is_string($d['cover'] ?? null) && $d['cover'] !== '') {
            $cf = ($path === '' ? '' : $path . '/') . $d['cover'];   // (a photo in the folder itself, or, with a / in it, one in a folder under it)
            $cover = ['name' => $d['cover'], 'file' => in_array($cf, strpos($d['cover'], '/') !== false ? $files : ($byFolder[$path] ?? []), true) ? $cf : null];
        } elseif (is_string($d['coverFollow'] ?? null) && $d['coverFollow'] !== '' && $path !== '') {
            $under = folder_files_under($files, $path . '/' . $d['coverFollow']);
            $cover = ['name' => 'follows ' . $d['coverFollow'], 'file' => $under ? pick_folder_cover(folder_files_under($files, $path), 'follow:' . $d['coverFollow'], $path) : null];
        }
        $items = [];
        foreach ($byFolder[$path] ?? [] as $file) {
            $e = $d['items'][basename($file)] ?? null;
            $kinds = []; $tags = []; $text = [];
            $own = '';
            if (isset($privItems[$file])) { $kinds[] = 'private'; $tags[] = ['priv', 'private']; $own = 'private'; }
            elseif (isset($shownItems[$file])) { $kinds[] = 'private'; $tags[] = ['pub', 'public']; $text[] = 'Public though its folder is private.'; $own = 'public'; }
            if (is_array($e)) {
                $cap = is_string($e['caption'] ?? null) ? trim($e['caption']) : '';
                if ($cap !== '') { $kinds[] = 'caption'; $tags[] = ['cap', 'caption']; $text[] = ['caption', $cap]; }
                $web = is_string($e['web'] ?? null) ? trim($e['web']) : '';
                if ($web !== '') { $kinds[] = 'link'; $tags[] = ['cap', 'link']; $text[] = ['link', $web]; }
                $loc = [];
                if (!empty($e['location']) && is_array($e['location'])) {
                    $lab = is_string($e['location']['label'] ?? null) ? trim($e['location']['label']) : '';
                    $loc[] = $lab !== '' ? 'Place you set: ' . $lab : 'A place you set';
                } elseif (!empty($e['locationCleared'])) $loc[] = 'Location removed on purpose';
                $vis = (string) ($e['locationVisibility'] ?? 'inherit');
                if ($vis === 'show') $loc[] = 'Its location is shown to visitors (its own switch)';
                elseif ($vis === 'hide') $loc[] = 'Its location is hidden from visitors (its own switch)';
                if ($loc) { $kinds[] = 'location'; $tags[] = ['loc', 'location']; $text[] = ['loc', implode('. ', $loc)]; }
                if (is_string($e['addr'] ?? null) && $e['addr'] !== '') { $kinds[] = 'address'; $tags[] = ['cap', 'new link']; $text[] = 'A new link was made for it: the one before stopped working.'; }
            }
            if (!$kinds) continue;
            $items[] = ['file' => $file, 'kinds' => $kinds, 'tags' => $tags, 'text' => $text, 'own' => $own];
        }
        if (!$set && !$items && $cover === null) continue;
        foreach ($items as $it) { $c['photos']++; foreach (array_unique($it['kinds']) as $k) $c[$k]++; }
        if ($set || $cover !== null) $c['folder']++;
        $isHidden = isset($privFolders[$path]);
        $folders[] = ['path' => $path, 'label' => $label($path), 'hidden' => $isHidden, 'set' => $set, 'cover' => $cover, 'items' => $items,
            'counts' => $isHidden && $path !== '' ? folder_privacy_counts($dir, $files, $path) : null];
    }
    $c['all'] = $c['photos'] + $c['folder'];
    return ['folders' => $folders, 'counts' => $c, 'total' => $c['all']];
}

/** The Edited tab's list, as HTML: a summary, filter chips and one collapsed card per folder (see the script, "ed-root"). A card holds the
 *  folder's cover (a picture that jumps to it in the gallery), its buttons and settings, for a private folder what opening it would do
 *  with Make public… and Share…, a row for each photo with a caption, link or place, and the photos whose only edit is their privacy
 *  as thumbnails with a lock that works. */
function render_admin_edited_html($ov, $csrfToken) {
    global $dir;
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $c = $ov['counts']; $noun = ENABLE_VIDEO ? 'item' : 'photo';
    $chips = [['all', 'All'], ['private', 'Private'], ['caption', 'Captions'], ['link', 'Links'], ['location', 'Locations'], ['address', 'New links'], ['folder', 'Folder settings']];
    $out = '<div class="ed" id="ed-root" data-csrf="' . $esc($csrfToken) . '" data-noun="' . $noun . '">';
    if ($ov['total'] === 0) return $out . '<p class="muted ed-empty">Nothing is set by hand yet. When you add a caption, a link or a private flag to a ' . $noun . ', or change a folder\'s own settings, it is listed here.</p></div>';
    $out .= '<p class="ed-sum"><span><b>' . number_format($c['all']) . '</b> edit' . ($c['all'] === 1 ? '' : 's') . ' in <b>' . number_format(count($ov['folders'])) . '</b> folder' . (count($ov['folders']) === 1 ? '' : 's') . '</span><span><b>' . number_format($c['private']) . '</b> private</span><span><b>' . number_format($c['caption']) . '</b> caption' . ($c['caption'] === 1 ? '' : 's') . '</span><span><b>' . number_format($c['link']) . '</b> link' . ($c['link'] === 1 ? '' : 's') . '</span></p>';
    $out .= '<div class="ed-chips" role="group" aria-label="Show only">';
    foreach ($chips as [$k, $name]) $out .= '<button type="button" class="ed-chip" data-k="' . $k . '" aria-pressed="' . ($k === 'all' ? 'true' : 'false') . '">' . $name . ' <em>' . number_format($c[$k]) . '</em></button>';
    $out .= '</div><div class="ed-tools"><button type="button" class="linklike" data-ed-expand="all">Expand all</button><span class="ed-sep"> &middot; </span><button type="button" class="linklike" data-ed-expand="none">Collapse all</button></div>';
    foreach ($ov['folders'] as $f) {
        $path = $f['path']; $settings = $path === '' ? '?admin#settings' : '?admin&folder=' . rawurlencode($path) . '#settings';
        $open = $path === '' ? '?' : '?folder=' . rawurlencode($path);
        $out .= '<section class="ed-folder" data-path="' . $esc($path) . '"><button type="button" class="ed-head" aria-expanded="false"><span class="ed-name">' . $esc($f['label']) . '<small>' . $esc($path === '' ? 'the main page' : $path) . '</small></span>' . ($f['hidden'] ? '<span class="tag ed-hid">hidden folder</span>' : '') . '<span class="ed-chev" aria-hidden="true">&rsaquo;</span><span class="ed-fsum"></span></button><div class="ed-body" hidden>';
        // The top of the card: the cover as a picture on the left, the buttons and the settings beside it.
        $out .= '<div class="ed-top">';
        if ($f['cover'] !== null && $f['cover']['file'] !== null) {
            $out .= '<a class="ed-cover" href="' . admin_find_href($f['cover']['file'], 'cover') . '" title="This folder\'s cover photo: show it in the folder"><img src="' . $esc(img_url($f['cover']['file'], 'thumb')) . '" alt="Cover photo: ' . $esc($f['cover']['name']) . '" loading="lazy" decoding="async"><i>' . ui_icon('star') . '</i></a>';
        }
        $out .= '<div class="ed-top-r"><div class="ed-fset-links"><a class="ed-btn" href="' . $esc($open) . '">Open folder</a> <a class="ed-btn" href="' . $esc($settings) . '">Folder settings</a>';
        if ($f['hidden'] && $path !== '') {
            $out .= ' <button type="button" class="ed-btn pv-makepub" data-folder="' . $esc($path) . '" data-label="' . $esc($f['label']) . '">Make public&hellip;</button>';
            if (ENABLE_SHARE_LINKS) $out .= ' <a class="ed-btn" href="#links" data-gs-folder="' . $esc($path) . '" data-gs-label="' . $esc($f['label']) . '">Share&hellip;</a>';
        }
        $out .= '</div>';
        if ($f['counts'] !== null) {
            $stay = (int) $f['counts']['stay']; $openN = (int) $f['counts']['open'];
            $out .= '<p class="ed-pf muted">' . ($stay > 0 ? $esc(count_noun($stay)) . ($stay === 1 ? ' stays' : ' stay') . ' private' : 'Nothing private on its own') . ' &middot; ' . $esc(count_noun($openN)) . ($openN === 1 ? ' becomes' : ' become') . ' public when opened</p>';
        }
        if ($f['set'] || ($f['cover'] !== null && $f['cover']['file'] === null)) {
            $out .= '<div class="ed-fset" data-kinds="folder" data-n="' . (count($f['set']) + ($f['cover'] !== null ? 1 : 0)) . '">';
            foreach ($f['set'] as [$cls, $text]) $out .= '<span class="tag' . ($cls !== '' ? ' ed-' . $cls : '') . '">' . $esc($text) . '</span>';
            if ($f['cover'] !== null && $f['cover']['file'] === null) $out .= '<span class="tag ed-cap">Cover: ' . $esc($f['cover']['name']) . ' (missing)</span>';
            $out .= '</div>';
        } elseif ($f['cover'] !== null) {
            $out .= '<div class="ed-fset" data-kinds="folder" data-n="1" hidden></div>';
        }
        $out .= '</div></div>';
        $rows = []; $strip = [];
        foreach ($f['items'] as $it) { if (count($it['kinds']) === 1 && $it['kinds'][0] === 'private') $strip[] = $it; else $rows[] = $it; }
        foreach (array_slice($rows, 0, 150) as $it) {
            $tags = ''; foreach ($it['tags'] as [$cls, $name]) $tags .= '<span class="tag ed-' . $cls . '">' . $esc($name) . '</span>';
            $txt = '';
            foreach ($it['text'] as $t) {
                if (is_string($t)) { $txt .= '<div class="ed-t">' . $esc($t) . '</div>'; continue; }
                if ($t[0] === 'caption') $txt .= '<div class="ed-t">&ldquo;' . $esc($t[1]) . '&rdquo;</div>';
                elseif ($t[0] === 'link') { $host = (string) parse_url($t[1], PHP_URL_HOST); $txt .= '<div class="ed-t">' . (preg_match('#^https?://#i', $t[1]) ? '<a href="' . $esc($t[1]) . '" target="_blank" rel="noopener noreferrer">' . $esc($host !== '' ? $host : $t[1]) . '</a>' : $esc($t[1])) . '</div>'; }
                else $txt .= '<div class="ed-t">' . $esc($t[1]) . '</div>';
            }
            $lock = '';
            if ($it['own'] !== '') {   // a photo with its own private/public setting, and more set on it: the same lock as in the strip
                $where = dirname($it['file']) === '.' ? '' : dirname($it['file']);
                $ltitle = $it['own'] === 'private' ? 'Private on its own: press to make it public' : 'Public though its folder is private: press to make it private again';
                $lock = '<button type="button" class="pv-lock ' . ($it['own'] === 'private' ? 'own' : 'pub') . '" data-file="' . $esc($it['file']) . '" data-bare="' . $esc(basename($it['file'])) . '" data-folder="' . $esc($where) . '" data-own="' . $it['own'] . '" title="' . $esc($ltitle) . '" aria-label="' . $esc($ltitle) . '">' . ui_icon($it['own'] === 'private' ? 'lock' : 'unlock') . '</button>';
            }
            $out .= '<div class="ed-it ed-row" data-kinds="' . $esc(implode(' ', array_unique($it['kinds']))) . '"><span class="ed-th"><a class="pv-img" href="' . admin_find_href($it['file']) . '" title="Show ' . $esc(basename($it['file'])) . ' in its folder"><img src="' . $esc(img_url($it['file'], 'thumb')) . '" alt="" loading="lazy" decoding="async"></a>' . $lock . '</span><div class="ed-rb"><div class="ed-rn">' . $esc(basename($it['file'])) . '</div><div class="ed-tags">' . $tags . '</div>' . $txt . '</div><a class="ed-btn" href="' . admin_item_href($it['file']) . '">Edit</a></div>';
        }
        if (count($rows) > 150) $out .= '<p class="muted ed-more-note">And ' . number_format(count($rows) - 150) . ' more with a caption, link or place: open the folder to see them.</p>';
        if ($strip) {
            $out .= '<div class="ed-strip"><p class="ed-strip-h">Only their privacy is set <span class="tag ed-priv-n">' . number_format(count($strip)) . '</span></p><div class="ed-sth">';
            foreach (array_slice($strip, 0, 300) as $it) {
                $where = dirname($it['file']) === '.' ? '' : dirname($it['file']);
                $title = $it['own'] === 'private' ? 'Private on its own: press to make it public' : 'Public though its folder is private: press to make it private again';
                $out .= '<span class="pv-th ed-it ed-sm" data-kinds="private"><a class="pv-img" href="' . admin_find_href($it['file']) . '" title="Show ' . $esc(basename($it['file'])) . ' in its folder"><img src="' . $esc(img_url($it['file'], 'thumb')) . '" alt="" loading="lazy" decoding="async"></a>'
                    . '<button type="button" class="pv-lock ' . ($it['own'] === 'private' ? 'own' : 'pub') . '" data-file="' . $esc($it['file']) . '" data-bare="' . $esc(basename($it['file'])) . '" data-folder="' . $esc($where) . '" data-own="' . $it['own'] . '" title="' . $esc($title) . '" aria-label="' . $esc($title) . '">' . ui_icon($it['own'] === 'private' ? 'lock' : 'unlock') . '</button></span>';
            }
            $out .= '<button type="button" class="ed-more" hidden></button></div>' . (count($strip) > 300 ? '<p class="muted ed-more-note">And ' . number_format(count($strip) - 300) . ' more: see them in the gallery.</p>' : '') . '<p class="pv-hint">Press a lock to switch a photo between private and public' . (array_filter($strip, function ($x) { return $x['own'] === 'public'; }) ? '; an open lock is a photo that is public though its folder is private' : '') . '. Press a photo to see it in its folder.</p></div>';
        }
        $out .= '</div></section>';
    }
    return $out . '</div>';
}

/** The Hidden tab: the plain-English warning, then every hidden thing with
 *  a link to where it can be changed. */
function render_admin_hidden_panel($data, $csrfToken = '') {
    global $dir, $files;
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $ed = admin_edited_overview($dir, $files, $data);
?>
<?php if ($data): ?>
    <div class="flash error hidden-warning" id="lock-banner" hidden>
      <div class="lock-open-text"><strong>Private isn't locked.</strong> The photo files can still be opened by their direct address.</div>
      <div class="lock-locked-text" hidden><strong>Private is locked.</strong> Direct requests for photos are refused (checked just now from this browser).</div>
      <div class="lock-outside-text" hidden><strong>Private is locked.</strong> These photos live outside the gallery's own address, so only the gallery can send them.</div>
      <div class="lock-result" data-lock-detail></div>
      <button type="button" class="secondary lock-recheck" hidden>Check again</button>
      <details class="fold lock-fix" data-lock-rules-panel hidden>
        <summary>Fix this: the rules to add</summary>
        <p class="muted" style="margin-top:0;">Copy the rules for your server. They stop photo files being fetched by their direct address.</p>
        <?= render_server_rules_help() ?>
        <p class="muted" data-tidy-rules>Optional tidy folder links and the nginx redirect setting are in <a href="#site" onclick="return adminGoTo('site', 'server-setup');">Settings → Server setup</a>.</p>
      </details>
    </div>
<?php endif; ?>
    <section class="panel ed-panel" id="ed-panel">
      <h2 class="section-title">Edited</h2>
      <p class="muted" style="margin-top:0;">Everything you set by hand: private flags, captions, links, places you typed or removed, and each folder's own settings. Press a folder to open it.</p>
      <?= render_admin_edited_html($ed, $csrfToken) ?>
    </section>
<?php if ($data): ?>
    <div class="pv-toast" id="pv-toast" role="status" aria-live="polite" hidden></div>
    <div class="admin-modal" id="pt-modal" role="dialog" aria-modal="true" aria-labelledby="pt-modal-title" hidden>
      <div class="admin-modal-card">
        <h3 id="pt-modal-title">Make this folder public?</h3>
        <p class="hint" id="pt-folder" style="margin:0 0 8px"></p>
        <div class="pm-row pm-stay"><?= ui_icon('lock') ?><span><strong id="pt-stay">0 photos</strong> <span id="pt-stay-v">stay</span> private</span></div>
        <div class="pm-row pm-open"><?= ui_icon('unlock') ?><span><strong id="pt-open">0 photos</strong> <span id="pt-open-v">become</span> public</span></div>
        <p class="hint">Anyone with the link will see those. Not right? Cancel, mark more photos private first, then come back.</p>
        <div class="pm-btns"><button type="button" class="secondary" id="pt-cancel">Cancel</button><button type="button" id="pt-ok">Make public</button></div>
      </div>
    </div>
<?php endif; ?>
<?php
}

/** A setting that is one switch: flip it and it is saved on the spot (the page's
 *  script posts it; the small Save button only exists for a browser without
 *  JavaScript). The warning, if any, sits beside it in orange. */
function render_admin_instant_toggle($csrfToken, $action, $field, $on, $label, $warn = '', $labelHtml = '', $formClass = '') {
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
?>
    <form method="post" class="<?= $formClass !== '' ? $esc($formClass) : 'instant-form' ?>">
      <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
      <input type="hidden" name="action" value="<?= $esc($action) ?>">
      <input type="hidden" name="<?= $esc($field) ?>" value="0">
      <div class="instant-row">
        <label class="switch"><input type="checkbox" name="<?= $esc($field) ?>" value="1" aria-label="<?= $esc($label) ?>" <?= $on ? 'checked' : '' ?>><span class="slider"></span></label>
        <span class="instant-label"><?= $esc($label) ?><?= $labelHtml ?></span>
        <span class="rowstate" role="status" aria-live="polite"></span>
      </div>
      <?php if ($warn !== ''): ?><div class="warn-note"><?= $esc($warn) ?></div><?php endif; ?>
      <div class="nojs-only"><button type="submit" class="secondary">Save</button></div>
    </form>
<?php
}

/** What the Cover photo row of a folder's settings shows, for the owner (every photo counts, private or not): the picture in use, how it was
 *  chosen, and the folders directly under it with the cover each one shows. Null when the folder holds no folder with photos in it. */
function admin_cover_state($dir, $files, $folderPath) {
    $prefix = $folderPath . '/';
    $members = folder_files_under($files, $folderPath);
    $kids = []; $own = 0;
    foreach ($members as $f) {
        $rest = substr($f, strlen($prefix)); $slash = strpos($rest, '/');
        if ($slash === false) $own++; else $kids[substr($rest, 0, $slash)] = true;
    }
    if (!$kids) return null;
    $label = function ($path, $fallback) use ($dir) { $l = folder_display_meta($dir, $path)['label']; return $l !== '' ? $l : $fallback; };
    $spec = folder_display_meta($dir, $folderPath)['cover'];
    $file = pick_folder_cover($members, $spec, $folderPath);
    $childOf = function ($f) use ($prefix) { $rest = substr((string) $f, strlen($prefix)); $slash = strpos($rest, '/'); return $slash === false ? '' : substr($rest, 0, $slash); };
    $src = $childOf($file);
    if ($spec === null) { $mode = 'auto'; $title = $own || $src === '' ? 'Automatic: the first photo in it' : 'Automatic: the cover of ' . $label($prefix . $src, $src); $small = $own || $src === '' ? 'Nothing chosen.' : 'Its first folder. Nothing chosen.'; }
    elseif (strncmp($spec, 'follow:', 7) === 0) { $mode = 'follow'; $title = 'Follows the cover of ' . $label($prefix . substr($spec, 7), substr($spec, 7)); $small = 'Change that folder\'s cover and this changes too.'; }
    elseif (strpos($spec, '/') !== false) { $mode = 'pin'; $title = 'Chosen: ' . $label($prefix . $childOf($prefix . $spec), $childOf($prefix . $spec)) . ' / ' . basename($spec); $small = 'That photo, whatever the folder\'s own cover is.'; }
    else { $mode = 'pin'; $title = 'Chosen: ' . $spec; $small = 'A photo in this folder.'; }
    $tiles = [];
    foreach (order_folder_children($dir, $prefix, array_keys($kids)) as $name) {
        $under = array_values(array_filter($members, function ($f) use ($prefix, $name) { return strncmp($f, $prefix . $name . '/', strlen($prefix . $name) + 1) === 0; }));
        $cspec = folder_display_meta($dir, $prefix . $name)['cover'];
        $cf = pick_folder_cover($under, $cspec, $prefix . $name);
        $rec = items_read(resolve_real_path($dir, $prefix . $name));
        $tiles[] = ['name' => $name, 'label' => $label($prefix . $name, $name), 'file' => $cf, 'url' => $cf !== null ? img_url($cf, 'cover') : '', 'chosen' => $cspec !== null,
            'state' => ($rec['restriction'] ?? '') === 'private' ? (!empty($rec['unlisted']) ? 'not listed' : 'private') : ''];
    }
    return ['mode' => $mode, 'title' => $title, 'small' => $small, 'file' => $file, 'url' => $file !== null ? img_url($file, 'cover') : '', 'from' => $mode === 'follow' ? substr($spec, 7) : ($mode === 'auto' ? $src : ''), 'tiles' => $tiles];
}

/** The Cover photo row (folder settings, above the description) of a folder that holds folders: the cover in use and a picker with a tile for each
 *  folder under it. The page's script (data-cover) does the rest. */
function render_admin_cover_row($folderPath) {
    $dir = $GLOBALS['dir']; $files = $GLOBALS['__admin_files'] ?? [];
    $st = admin_cover_state($dir, $files, $folderPath);
    if ($st === null) return;
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
?>
        <div class="field wide cover-field" data-cover>
          <label>Cover photo <span class="rowstate" role="status" aria-live="polite"></span></label>
          <div class="cv-row">
            <img class="cv-img" src="<?= $esc($st['url']) ?>" alt=""<?= $st['url'] === '' ? ' hidden' : '' ?>>
            <div class="cv-main"><b class="cv-title"><?= $esc($st['title']) ?></b><small class="cv-small"><?= $esc($st['small']) ?></small></div>
            <div class="cv-acts"><button type="button" class="secondary cv-choose">Choose&hellip;</button><button type="button" class="secondary cv-auto"<?= $st['mode'] === 'auto' ? ' hidden' : '' ?>>Automatic</button></div>
          </div>
          <div class="cv-pick" hidden>
            <h4>Show the cover of&hellip;</h4>
            <p>This folder follows that folder's cover: change it there and this changes too.</p>
            <div class="cv-grid">
<?php foreach ($st['tiles'] as $t): ?>
              <button type="button" class="cv-tile<?= $t['name'] === $st['from'] ? ' sel' : '' ?>" data-child="<?= $esc($t['name']) ?>"><?php if ($t['url'] !== ''): ?><img src="<?= $esc($t['url']) ?>" alt="" loading="lazy"><?php endif; ?><i aria-hidden="true">&#10003;</i><span><?= $esc($t['label']) ?><?= $t['state'] !== '' ? ' <em>' . $esc($t['state']) . '</em>' : '' ?> <small><?= $t['chosen'] ? 'chosen' : 'automatic' ?></small></span></button>
<?php endforeach; ?>
            </div>
            <div class="cv-foot"><button type="button" class="linklike cv-any">Pick any photo instead&hellip;</button><button type="button" class="secondary cv-cancel">Cancel</button></div>
            <div class="cv-anywhere" hidden>
              <p>A photo in:</p>
              <div class="cv-tabs">
<?php foreach ($st['tiles'] as $t): ?>
                <button type="button" class="cv-tab" data-child="<?= $esc($t['name']) ?>"><?= $esc($t['label']) ?></button>
<?php endforeach; ?>
              </div>
              <div class="cv-photos"></div>
            </div>
          </div>
        </div>
<?php
}

/** What visitors without a link may download: [the folders (paths) that allow it, whether the main page's own photos do]. Off reads as none. */
function public_download_current() {
    return [PUBLIC_DOWNLOAD_MODE === 'some' ? PUBLIC_DOWNLOAD_FOLDERS : [], PUBLIC_DOWNLOAD_MODE === 'some' && PUBLIC_DOWNLOAD_ROOT];
}

/** Saves that choice in the settings file (replacing an older "Everything" or on/off line), or says why not. Nothing is stored when nothing is on. */
function public_download_write(array $folders, $root) {
    $folders = array_values(array_unique($folders)); sort($folders, SORT_NATURAL | SORT_FLAG_CASE);
    $on = $folders !== [] || $root;
    return gallery_config_merge(['PUBLIC_DOWNLOAD_MODE' => $on ? 'some' : null, 'PUBLIC_DOWNLOAD_FOLDERS' => $folders ?: null, 'PUBLIC_DOWNLOAD_ROOT' => $root ? true : null, 'ENABLE_PUBLIC_DOWNLOAD' => null]);
}

/** The folders inside $folderPath, at any depth, that hold photos directly: [the public ones, the private ones (which never offer downloads)]. */
function admin_download_folders_inside($dir, $files, $folderPath) {
    $prefix = $folderPath . '/'; $have = [];
    foreach ($files as $f) {
        $slash = strrpos($f, '/'); if ($slash === false) continue;
        $d = substr($f, 0, $slash);
        if (strncmp($d, $prefix, strlen($prefix)) === 0) $have[$d] = true;
    }
    $names = array_keys($have); sort($names, SORT_NATURAL | SORT_FLAG_CASE);
    $public = []; $private = [];
    foreach ($names as $d) { if (folder_is_private($dir, $d)) $private[] = $d; else $public[] = $d; }
    return [$public, $private];
}

/** The main page's own download switch, under its settings (outside the settings form: it is a form of its own, and forms do not nest). Only when
 *  photos lie directly on the main page and the gallery is not closed. */
function render_admin_root_downloads($csrfToken) {
    if (gallery_is_closed() || !array_filter($GLOBALS['__admin_files'] ?? [], function ($f) { return strpos($f, '/') === false; })) return;
?>
    <div class="folder-switches root-dl">
      <?php render_admin_instant_toggle($csrfToken, 'toggle_folder_downloads', 'dl', PUBLIC_DOWNLOAD_MODE === 'some' && PUBLIC_DOWNLOAD_ROOT, 'Visitors can download the photos on this page', '', '', 'unlisted-form'); ?>
    </div>
<?php
}

/** The line under a folder's download switch, worded for where things stand with the public folders inside it (at any depth): all off, all on, or
 *  some on (named, each a link to that folder's settings), with the buttons that make sense. $list is the list of folders that allow it, when it
 *  is not the saved one (just changed). '' when there is no public folder inside. */
function admin_downloads_inside_html($dir, $files, $folderPath, $list = null) {
    [$pub] = admin_download_folders_inside($dir, $files, $folderPath);
    if (!$pub) return '';
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $on = $list !== null ? $list : public_download_current()[0];
    $isOn = array_values(array_filter($pub, function ($p) use ($on) { return in_array($p, $on, true); }));
    $n = count($pub); $k = count($isOn);
    $short = function ($p) use ($folderPath) { return substr($p, strlen($folderPath) + 1); };
    $btnOn = '<button type="button" class="linklike dl-in-on">Allow downloads in all</button>';
    $btnOff = '<button type="button" class="linklike dl-in-off">Turn off in all</button>';
    if ($k === 0) return '<span class="dl-inside-text">' . ($n === 1 ? 'The folder inside it has downloads off.' : 'The ' . $n . ' folders inside it have downloads off.') . '</span> ' . $btnOn;
    if ($k === $n) return '<span class="dl-inside-text">' . ($n === 1 ? 'The folder inside it allows downloads.' : 'All ' . $n . ' folders inside it allow downloads.') . '</span> ' . $btnOff;
    $names = implode(', ', array_map(function ($p) use ($esc, $short) { return '<a href="?admin&amp;folder=' . $esc(rawurlencode($p)) . '#settings">' . $esc($short($p)) . '</a>'; }, $isOn));
    return '<span class="dl-inside-text">' . $k . ' of the ' . $n . ' folders inside it allow downloads: ' . $names . '.</span> ' . $btnOn . '<span class="dl-sep"> &middot; </span>' . $btnOff;
}

/** A folder's switches, in one run under the folder's description and its Cards/Flat choice: Gallery of its own, Private folder
 *  (contents included) and Not listed (with, once it is on, Allow downloading originals). Each is saved the moment it is pressed.
 *  The gallery's own top has none of them. */
function render_admin_folder_switches($itemsHere, $csrfToken, $adminFolderPath, $counts) {
    if ($adminFolderPath === null) return;
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $private = !empty($itemsHere['restriction']) && $itemsHere['restriction'] === 'private';
?>
    <div class="folder-switches">
    <?php render_admin_instant_toggle($csrfToken, 'toggle_folder_own_gallery', 'owngallery', !empty($itemsHere['ownGallery']), 'Gallery of its own', '', ' <small class="muted">(limits navigation)</small>', 'unlisted-form'); ?>
    <?php if (!gallery_is_closed()): $dlFolders = public_download_current()[0]; ?>
    <div class="dl-block"<?= $private ? ' hidden' : '' ?>>
      <?php render_admin_instant_toggle($csrfToken, 'toggle_folder_downloads', 'dl', in_array($adminFolderPath, $dlFolders, true), 'Visitors can download originals', '', '', 'unlisted-form'); ?>
      <?php $dlRow = admin_downloads_inside_html($GLOBALS['dir'], $GLOBALS['__admin_files'] ?? [], $adminFolderPath); if ($dlRow !== ''): ?>
      <div class="dl-inside" data-dl-inside data-csrf="<?= $esc($csrfToken) ?>"<?= in_array($adminFolderPath, $dlFolders, true) ? '' : ' hidden' ?>>
        <span class="dl-inside-body"><?= $dlRow ?></span>
        <span class="rowstate" role="status" aria-live="polite"></span>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <form method="post" class="priv-folder-form" data-noun="<?= ENABLE_VIDEO ? 'item' : 'photo' ?>" data-stay="<?= (int) $counts['stay'] ?>" data-open="<?= (int) $counts['open'] ?>">
      <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
      <input type="hidden" name="action" value="toggle_folder_restriction">
      <input type="hidden" name="hidden" value="<?= $private ? '0' : '1' ?>">   <?php // what the Save button (no JavaScript) sends; the page's script sends its own request ?>
      <div class="instant-row">
        <label class="switch"><input type="checkbox" <?= $private ? 'checked' : '' ?> aria-label="Private folder"><span class="slider"></span></label>
        <span class="instant-label">Private folder <small class="muted">(contents included)</small></span>
        <span class="rowstate" role="status" aria-live="polite"></span>
      </div>
      <div class="nojs-only"><button type="submit" class="<?= $private ? 'secondary' : 'danger' ?>"><?= $private ? 'Make this folder public again' : 'Make this entire folder private' ?></button></div>
    </form>
    <?php render_admin_instant_toggle($csrfToken, 'toggle_folder_unlisted', 'unlisted', $private && !empty($itemsHere['unlisted']), 'Not listed', '', ' <small class="muted">only you can open it</small>', 'unlisted-form'); ?>
    <div class="unlisted-sub"<?= $private && !empty($itemsHere['unlisted']) ? '' : ' hidden' ?>>
      <?php render_admin_instant_toggle($csrfToken, 'toggle_folder_address_file', 'addrfile', !empty($itemsHere['addressFile']), !empty($itemsHere['addressFile']) ? 'Links send the bare file' : 'Links open in the gallery', '', '', 'unlisted-form'); ?>
      <?php render_admin_instant_toggle($csrfToken, 'toggle_folder_originals', 'originals', !empty($itemsHere['allowOriginals']), 'Allow downloading originals', '', ' <small class="muted">from a photo\'s link</small>', 'unlisted-form'); ?>
    </div>
    <p class="hint unlisted-note">Only you see what is in this folder. Visitors and people with the private-view password get nothing, and an "Everything" share link leaves it out. To share it, send a photo's own link, or make a share link that picks this folder.</p>
    </div>
    <div class="admin-modal" id="priv-modal" role="dialog" aria-modal="true" aria-labelledby="priv-modal-title" hidden>
      <div class="admin-modal-card">
        <h3 id="priv-modal-title">Make this folder public?</h3>
        <div class="pm-row pm-stay"><?= ui_icon('lock') ?><span><strong id="pm-stay">0 photos</strong> <span id="pm-stay-v">stay</span> private</span></div>
        <div class="pm-row pm-open"><?= ui_icon('unlock') ?><span><strong id="pm-open">0 photos</strong> <span id="pm-open-v">become</span> public</span></div>
        <p class="hint">Anyone with the link will see those. Not right? Cancel, mark more photos private first, then come back.</p>
        <div class="pm-btns"><button type="button" class="secondary" id="pm-cancel">Cancel</button><button type="button" id="pm-ok">Make public</button></div>
      </div>
    </div>
<?php
}

/** The "Locations" section, last on a folder's (or the gallery's) settings: whether photo locations show, and, under that because it
 *  only shows photos whose location is public, the map. */
function render_admin_locations_section($itemsHere, $csrfToken, $adminFolderPath, $locWarn = '', $locStatus = '') {
    $isRoot = $adminFolderPath === null;
    $locOn = !empty($itemsHere['locationEnabled']) && ENABLE_PHOTO_LOCATION;
?>
    <h3 class="subhead">Locations</h3>
    <?php $locJump = !ENABLE_PHOTO_LOCATION ? ' (locations are off for the whole gallery, in <a href="#site" onclick="return adminGoTo(\'site\', \'setting-ENABLE_PHOTO_LOCATION\');">Settings</a>)' : '';
          render_admin_instant_toggle($csrfToken, 'toggle_location_enabled', 'enabled', !empty($itemsHere['locationEnabled']), $isRoot ? 'Show photo locations to visitors at the root' : 'Show photo locations to visitors for this folder', $locWarn, $locJump); ?>
    <?php if (ENABLE_PHOTO_LOCATION): ?>
    <div class="loc-keep"<?= !empty($itemsHere['locationEnabled']) ? '' : ' hidden' ?>>
      <?php render_admin_instant_toggle($csrfToken, 'toggle_keep_location', 'keeploc', ($itemsHere['keepLocation'] ?? null) === true, 'Keep the location in files visitors get', '', '', 'unlisted-form'); ?>
    </div>
    <?php endif; ?>
    <?php if ($locStatus !== ''): ?>
    <p class="hint loc-status"><?= $locStatus ?></p>
    <?php endif; ?>
    <div class="map-under<?= $locOn ? '' : ' no-loc' ?>" id="map-under"<?= ENABLE_PHOTO_LOCATION ? '' : ' data-global-off="1"' ?>>
      <?php if (!ENABLE_PHOTO_LOCATION): ?>
      <p class="warn-note map-loc-note">Locations are off for the whole gallery, so nothing shows on a map yet. Turn on <strong>Allow photo locations</strong> in <a href="#site" onclick="return adminGoTo('site', 'setting-ENABLE_PHOTO_LOCATION');">Settings</a>; each folder and photo still has its own switch.</p>
      <?php else: ?>
      <p class="warn-note map-loc-note">Locations are off for this folder, so its map has nothing to show. A photo you set to show its location on its own still appears.</p>
      <?php endif; ?>
      <?php render_admin_map_form($itemsHere, $csrfToken, $isRoot); ?>
    </div>
<?php
}

