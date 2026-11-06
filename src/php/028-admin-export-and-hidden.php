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
    header('Content-Disposition: attachment; filename="whole-tree-gallery-items.json"');
    echo json_encode($tree, JSON_PRETTY_PRINT);
}

function admin_export_items($adminFolderPath, $adminFolderReal) {
    $data = items_read($adminFolderReal);
    $name = ($adminFolderPath === null ? 'root' : slugify($adminFolderPath)) . '-gallery-items.json';
    header('Content-Type: application/json');
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
        . "location ~* ^" . $loc . ".*\\.(jpe?g|png|gif|webp|bmp|heic|heif|mov|mp4|m4v)\$ {\n    internal;\n}\n";
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

/** Everything the owner has set by hand, for the Edited tab: per folder (those with anything), its own settings (hidden, name, description,
 *  dates, position, cover, location and map switches, photo order, stacks, groups) and, for each photo or video, what was set on it
 *  (private or public on its own, a caption, an information link, a location typed in, labelled or removed on purpose, its own location
 *  switch). What the gallery read from the files is never listed: it was not set by hand. $hidden is admin_hidden_data()'s result (or null). */
function admin_edited_overview($dir, $files, $hidden) {
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
    $folders = []; $c = ['all' => 0, 'private' => 0, 'caption' => 0, 'link' => 0, 'location' => 0, 'folder' => 0, 'photos' => 0];
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
        if (!empty($d['locationEnabled'])) $set[] = ['loc', 'Locations on'];
        if (array_key_exists('mapEnabled', $d) && $d['mapEnabled'] !== null) $set[] = ['', 'Map ' . ($d['mapEnabled'] ? 'on' : 'off') . ' here'];
        if (array_key_exists('mapRadius', $d) && $d['mapRadius'] !== null) $set[] = ['', 'Map grouping set'];
        if (is_string($d['photoSort'] ?? null) && $d['photoSort'] !== '') $set[] = ['', 'Photo order set'];
        if (!empty($d['groups'])) $set[] = ['', count((array) $d['groups']) . ' group' . (count((array) $d['groups']) === 1 ? '' : 's')];
        // The folder's cover photo, shown as a picture in its card (not as a setting): its path, or missing when the file is gone.
        $cover = null;
        if (is_string($d['cover'] ?? null) && $d['cover'] !== '') {
            $cf = ($path === '' ? '' : $path . '/') . $d['cover'];
            $cover = ['name' => $d['cover'], 'file' => in_array($cf, $byFolder[$path] ?? [], true) ? $cf : null];
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
    $chips = [['all', 'All'], ['private', 'Private'], ['caption', 'Captions'], ['link', 'Links'], ['location', 'Locations'], ['folder', 'Folder settings']];
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

/** The "Privacy" section of a folder's (or the gallery's) settings: three rows with the same switch, each saved the moment it is
 *  pressed: the folder itself private (not at the top of the gallery), whether photo locations are public, and, under that because it
 *  only shows photos whose location is public, the map. */
function render_admin_privacy_section($itemsHere, $csrfToken, $adminFolderPath, $counts, $locWarn = '', $locStatus = '') {
    $isRoot = $adminFolderPath === null;
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $locOn = !empty($itemsHere['locationEnabled']) && ENABLE_PHOTO_LOCATION;
?>
    <h3 class="subhead">Privacy</h3>
<?php if (!$isRoot):
    $private = !empty($itemsHere['restriction']) && $itemsHere['restriction'] === 'private';
?>
    <form method="post" class="priv-folder-form" data-noun="<?= ENABLE_VIDEO ? 'item' : 'photo' ?>" data-stay="<?= (int) $counts['stay'] ?>" data-open="<?= (int) $counts['open'] ?>">
      <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
      <input type="hidden" name="action" value="toggle_folder_restriction">
      <input type="hidden" name="hidden" value="<?= $private ? '0' : '1' ?>">   <?php // what the Save button (no JavaScript) sends; the page's script sends its own request ?>
      <div class="instant-row">
        <label class="switch"><input type="checkbox" <?= $private ? 'checked' : '' ?> aria-label="Private folder"><span class="slider"></span></label>
        <span class="instant-label">Private folder</span>
        <span class="rowstate" role="status" aria-live="polite"></span>
      </div>
      <p class="hint priv-folder-note" data-on="Visitors can't see this folder or anything in it. <strong>%stay%</strong> will stay private (marked private on their own, or in another private folder); <strong>%open%</strong> %becomes% public when you switch this off. You'll be asked first." data-off="Switch it on to hide this folder and everything in it. Photos you mark private on their own stay private when you switch it off again, so you can go through them first."></p>
      <div class="nojs-only"><button type="submit" class="<?= $private ? 'secondary' : 'danger' ?>"><?= $private ? 'Make this folder public again' : 'Make this entire folder private' ?></button></div>
    </form>
    <?php render_admin_instant_toggle($csrfToken, 'toggle_folder_unlisted', 'unlisted', $private && !empty($itemsHere['unlisted']), 'Not listed', '', ' <small class="muted">only you can open it</small>', 'unlisted-form'); ?>
    <p class="hint unlisted-note">Only you see what is in this folder: visitors and people with the private-view password get nothing, and an "Everything" link leaves it out. Share it one photo at a time (each photo has its own address), or pick the folder in a link. Switching this on makes the folder private too.</p>
    <div class="admin-modal" id="priv-modal" role="dialog" aria-modal="true" aria-labelledby="priv-modal-title" hidden>
      <div class="admin-modal-card">
        <h3 id="priv-modal-title">Make this folder public?</h3>
        <div class="pm-row pm-stay"><?= ui_icon('lock') ?><span><strong id="pm-stay">0 photos</strong> <span id="pm-stay-v">stay</span> private</span></div>
        <div class="pm-row pm-open"><?= ui_icon('unlock') ?><span><strong id="pm-open">0 photos</strong> <span id="pm-open-v">become</span> public</span></div>
        <p class="hint">Anyone with the link will see those. Not right? Cancel, mark more photos private first, then come back.</p>
        <div class="pm-btns"><button type="button" class="secondary" id="pm-cancel">Cancel</button><button type="button" id="pm-ok">Make public</button></div>
      </div>
    </div>
<?php endif; ?>
    <?php $locJump = !ENABLE_PHOTO_LOCATION ? ' (locations are off for the whole gallery, in <a href="#site" onclick="return adminGoTo(\'site\', \'setting-ENABLE_PHOTO_LOCATION\');">Settings</a>)' : '';
          render_admin_instant_toggle($csrfToken, 'toggle_location_enabled', 'enabled', !empty($itemsHere['locationEnabled']), $isRoot ? 'Show photo locations to visitors at the root' : 'Show photo locations to visitors for this folder', $locWarn, $locJump); ?>
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

