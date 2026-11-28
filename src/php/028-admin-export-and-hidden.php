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
    $name = ($adminFolderPath === null ? 'root' : (trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($adminFolderPath)), '-') ?: 'folder')) . '-gallery-items.json';
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

/** Addresses for the owner's browser to try, to see whether the web server rules in render_web_server_rules are in place:
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

/** The nginx rule that keeps the gallery's generated data files (anything starting .gallery-) from being fetched: the Health check's "How to fix" folds use it too. */
function gallery_files_rule_nginx() {
    return "location ~ /\\.gallery- {\n    return 404;\n}\n";
}

/** The same for Apache (a .htaccess). */
function gallery_files_rule_apache() {
    return "<FilesMatch \"^\\.gallery-\">\n    Require all denied\n</FilesMatch>\n";
}

/** What the web server says it is: 'nginx', 'apache' (LiteSpeed reads .htaccess too) or '' when it says something else. Only a hint (behind a proxy it is the server
 *  in the back that answers): it picks which block is offered first and tagged "looks like yours", and nothing is ever hidden because of it. */
function web_server_kind() {
    $s = strtolower((string) ($_SERVER['SERVER_SOFTWARE'] ?? ''));
    if (strpos($s, 'nginx') === 0 || strpos($s, 'openresty') === 0) return 'nginx';
    if (strpos($s, 'apache') === 0 || strpos($s, 'litespeed') !== false) return 'apache';
    return '';
}

/** Every web server rule the gallery can use, written for this gallery's address, as one block for nginx and one for Apache: [$nginx, $apache, $base, $loc].
 *  Each block has the same parts, marked "needed" (the photo files and the data files can't be fetched by address) and "optional" (tidy folder links, tidy share
 *  addresses when share links are on), between a begin and an end line. The one place this text is made: Settings shows it, and everything else points there. */
function web_server_rule_texts() {
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    $loc = $base === '' ? '/' : $base . '/';
    $shape = '[A-Za-z0-9][A-Za-z0-9-]{2,38}[A-Za-z0-9]';   // a random share address or a custom name: 4 to 40 letters, digits and hyphens, none at either end
    $begin = '# ===== gallery rules: begin (for ' . $loc . ') =====';
    $end = '# ===== gallery rules: end =====';
    $nginx = $begin . "\n\n"
        . "# needed: the gallery's data files (everything starting .gallery-) are never public\n"
        . "# (a second layer: the .php ones already answer 404 on their own)\n"
        . gallery_files_rule_nginx() . "\n"
        . "# needed: the photo and video files themselves are reachable only through index.php\n"
        . "# (the \"nginx internal redirect\" setting uses this same location)\n"
        . nginx_internal_location_rule($loc) . "\n"
        . "# optional: tidy folder links, " . $loc . "Europe/France/\n"
        . "location ~ ^" . $loc . ".+/\$ {\n    rewrite ^" . $loc . "(.+)/\$ " . $loc . "index.php?folder=\$1 last;\n}\n\n";
    if (ENABLE_SHARE_LINKS)   // (nginx reads a { in an unquoted pattern as the start of a block, so both patterns are quoted)
        $nginx .= "# optional: tidy share addresses, " . $loc . "k7QpR2mX, a name of your own, and " . $loc . "admin\n"
            . "location ~ \"^" . $loc . $shape . "\$\" {\n    rewrite \"^" . $loc . "(" . $shape . ")\$\" " . $loc . "index.php?link=\$1 last;\n}\n\n";
    $nginx .= $end . "\n";
    $apache = $begin . "\n\n"
        . "# needed: the photo and video files themselves are reachable only through index.php\n"
        . "# ((?i) matters: camera files are often IMG_0412.JPG)\n"
        . "<FilesMatch \"(?i)\\.(jpe?g|png|gif|webp|bmp|heic|heif|mov|mp4|m4v)\$\">\n    Require all denied\n</FilesMatch>\n\n"
        . "# needed: the gallery's data files (everything starting .gallery-) are never public (a second layer)\n"
        . gallery_files_rule_apache() . "\n"
        . "# optional: the tidy addresses below need mod_rewrite (without it they are skipped)\n"
        . "<IfModule mod_rewrite.c>\nRewriteEngine On\n\n"
        . "# optional: tidy folder links, " . $loc . "Europe/France/\n"
        . "RewriteRule ^(.+)/\$ index.php?folder=\$1 [B,L,QSA]\n";
    if (ENABLE_SHARE_LINKS)
        $apache .= "\n# optional: tidy share addresses, " . $loc . "k7QpR2mX, a name of your own, and " . $loc . "admin\n"
            . "RewriteCond %{REQUEST_FILENAME} !-f\nRewriteRule ^(" . $shape . ")\$ index.php?link=\$1 [L,QSA]\n";
    $apache .= "</IfModule>\n\n" . $end . "\n";
    return [$nginx, $apache, $base, $loc];
}

/** Web server rules, the one section of Settings (under Server setup) that holds them all: a line that says which of the rules this browser found working
 *  (the page's script fills it in: see data-wr-chip), and under it a fold for nginx and a fold for Apache, each one block with one Copy button. The fold that
 *  looks like this server's own is first and tagged; the script opens it when something is missing. Everywhere else in the admin points here. */
function render_web_server_rules() {
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    [$nginx, $apache, $base, $loc] = web_server_rule_texts();
    $kind = web_server_kind();
    $chips = [['lock', 'Private photos'], ['tidy', 'Tidy folder links']];
    if (ENABLE_SHARE_LINKS) $chips[] = ['sharetidy', 'Tidy share addresses'];
    $chips[] = ['accel', 'nginx redirect'];
    $folds = [
        'nginx' => ['nginx', '(needs shell access to the server)',
            '<p>Add inside the site\'s <code>server { }</code> block, then run <code>sudo nginx -t &amp;&amp; sudo systemctl reload nginx</code>. Written for this gallery\'s address, <code>' . $esc($loc) . '</code>.'
                . ($base === '' ? ' <strong>At the top of the site the photo rule would block every image on it, and the optional ones would catch other addresses: narrow their paths first.</strong>' : '') . '</p>',
            $nginx,
            ['Then set <em>nginx internal redirect</em> (above) to <code>' . $esc($base === '' ? '/' : $base) . '</code>.',
             'Test: <code>curl -I https://yoursite.com' . $esc($loc) . 'some-photo.jpg</code> should answer 404.',
             'The folder rule matches only paths ending in <code>/</code> and needs php-fpm to pass the original <code>REQUEST_URI</code> (the standard <code>fastcgi_param</code> does). Behind <code>proxy_pass</code> instead of php-fpm, pass the real client address and protocol on.']
            + (ENABLE_SHARE_LINKS ? [3 => 'The share-address rule matches one word of 4 to 40 letters, digits and hyphens, with no dot and no closing slash: a photo (it has a dot) and a folder\'s own address (it ends in a slash) are never touched, and a folder typed without its slash is sent on to the folder by the gallery. If you added the older rule for exactly ' . (int) LINK_TOKEN_LENGTH . ' letters and digits, remove it: this one replaces it.'] : [])],
        'apache' => ['Apache', '(works on FTP-only hosting)',
            '<p>Save as <code>.htaccess</code> in the gallery\'s folder. If one is there already, add this at the end.</p>',
            $apache,
            ['It only works if the server reads <code>.htaccess</code> here (<code>AllowOverride All</code>; the tidy addresses need <code>AllowOverride FileInfo</code>). Check with <code>curl -I</code>: a bare photo address should then answer 403, and the gallery works as before.',
             'Keep the <code>B</code> flag on the folder rule: it escapes spaces, <code>&amp;</code>, <code>+</code> and <code>#</code>.',
             'Scripts that log in with an <code>Authorization</code> header on PHP-FPM also need <code>CGIPassAuth On</code>.']
            + (ENABLE_SHARE_LINKS ? [3 => 'The share-address rule matches one word of 4 to 40 letters, digits and hyphens, with no dot and no closing slash. A real folder with the same name as a link doesn\'t hide it: the folder\'s own address (with the closing slash) still opens the folder. If you added the older rule for exactly ' . (int) LINK_TOKEN_LENGTH . ' letters and digits, remove it: this one replaces it.'] : [])],
    ];
    $order = $kind === 'apache' ? ['apache', 'nginx'] : ['nginx', 'apache'];
    ob_start();
?>
            <div class="site-rules" id="server-rules" data-wr data-wr-server="<?= $esc($kind) ?>">
              <div class="name">Web server rules</div>
              <div class="help">Making something private only stops the gallery from showing it; the file can still be fetched by its direct address unless your web server is told not to. Everything the gallery can use is in one block per server: copy the block for yours.</div>
              <div class="wr-line" data-wr-line>
                <?php foreach ($chips as [$ck, $cl]): ?><span class="wr-chip" data-wr-chip="<?= $ck ?>"<?= $ck === 'accel' ? ' hidden' : '' ?>><i aria-hidden="true">&hellip;</i> <?= $esc($cl) ?></span><?php endforeach; ?>
              </div>
              <?php foreach ($chips as [$ck, $cl]): ?><p class="lock-result" data-wr-msg="<?= $ck ?>" hidden></p><?php endforeach; ?>
              <div class="rules-help">
                <?php foreach ($order as $fk): [$fname, $fwhen, $fintro, $ftext, $fnotes] = $folds[$fk]; ?>
                <details class="fold wr-fold" data-wr-fold="<?= $fk ?>"<?= $kind === $fk ? ' data-likely="1"' : '' ?>>
                  <summary><span class="wr-name"><strong><?= $esc($fname) ?></strong> <?= $esc($fwhen) ?></span><?= $kind === $fk ? ' <span class="wr-tag">looks like yours</span>' : '' ?></summary>
                  <?= $fintro ?>
                  <div class="rules-box"><button type="button" class="secondary copy-rules">Copy</button><pre><code><?= $esc($ftext) ?></code></pre></div>
                  <ul class="wr-notes"><?php foreach ($fnotes as $note): ?><li><?= $note ?></li><?php endforeach; ?></ul>
                </details>
                <?php endforeach; ?>
              </div>
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
            'ownGallery' => 'shown', 'allowOriginals' => 'shown', 'addressFile' => 'shown', 'keepLocation' => 'shown', 'subfolderStyle' => 'shown', 'flatNames' => 'shown', 'flatNotes' => 'shown', 'locationEnabled' => 'shown', 'mediaInfo' => 'shown', 'quiet' => 'shown',
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
        if (!empty($d['unlisted']) && isset($privFolders[$path])) $set[] = ['hid', 'Only me'];
        if (($d['ownGallery'] ?? null) === true) $set[] = ['', 'Gallery of its own'];
        elseif (($d['ownGallery'] ?? null) === false && quiet_flag_of($d)) $set[] = ['', 'Not a gallery of its own (its own choice)'];   // (an Unlisted folder is one by default: switching that off is a choice kept)
        if (quiet_flag_of($d)) $set[] = ['', 'Unlisted: public, left out of every list'];   // (the folder's ['quiet'] mark, read the one way everywhere: quiet_flag_of)
        if (!empty($d['allowOriginals'])) $set[] = ['', 'Originals can be downloaded from a photo\'s link'];
        if (($d['keepLocation'] ?? null) === true) $set[] = ['loc', 'Visitors\' copies keep the location'];
        if (!empty($d['addressFile']) && !empty($d['unlisted'])) $set[] = ['', 'Photo links send the bare file'];
        if (in_array($d['subfolderStyle'] ?? '', ['overview', 'flat'], true)) $set[] = ['', 'Folders under it: ' . ($d['subfolderStyle'] === 'flat' ? 'Flat' : 'Cards')];
        if (is_bool($d['flatNames'] ?? null)) $set[] = ['', 'Folder names ' . ($d['flatNames'] ? 'shown' : 'hidden') . ' (Flat)'];
        if (is_bool($d['flatNotes'] ?? null)) $set[] = ['', 'Folder notes ' . ($d['flatNotes'] ? 'shown' : 'hidden') . ' (Flat)'];
        if (!empty($d['stacks']) && is_array($d['stacks'])) { $nStacks = count(array_filter($d['stacks'], function ($st) { return is_array($st) && count((array) ($st['files'] ?? [])) > 1; })); if ($nStacks) $set[] = ['', $nStacks . ' stack' . ($nStacks === 1 ? '' : 's') . ' of photos']; }
        if (ENABLE_PUBLIC_DOWNLOAD && (($path === '' && PUBLIC_DOWNLOAD_ROOT) || ($path !== '' && in_array($path, PUBLIC_DOWNLOAD_FOLDERS, true)))) $set[] = ['', $path === '' ? 'Visitors can download the photos on this page' : 'Visitors can download originals'];
        if (($d['locationEnabled'] ?? null) === true) $set[] = ['loc', 'Locations on'];
        elseif (($d['locationEnabled'] ?? null) === false && $path !== '' && folder_flag_inherited($dir, $path, 'locationEnabled')['on']) $set[] = ['loc', 'Locations off (its own choice)'];   // (an off that overrides a folder above that has them on; an off where nothing above is on is what every folder is, and older versions wrote it into every folder they saved)
        if (is_bool($d['mediaInfo'] ?? null)) $set[] = ['', 'Media info ' . ($d['mediaInfo'] ? 'on' : 'off')];
        if (array_key_exists('mapEnabled', $d) && $d['mapEnabled'] !== null) $set[] = ['', 'Map ' . ($d['mapEnabled'] ? 'on' : 'off') . ' here'];
        if (array_key_exists('mapRadius', $d) && $d['mapRadius'] !== null) $set[] = ['', 'Map grouping set'];
        if (is_array($d['photoSort'] ?? null) && $d['photoSort']) $set[] = ['', 'Photo order set'];   // (stored as a list of choices: order, direction, what goes first)
        if (!empty($d['groups'])) $set[] = ['', count((array) $d['groups']) . ' group' . (count((array) $d['groups']) === 1 ? '' : 's')];
        // The folder's cover photo, shown as a picture in its card (not as a setting): its path, or missing when the file is gone.
        $cover = null;
        if (($d['cover'] ?? null) === COVER_LATEST || ($d['cover'] ?? null) === COVER_RANDOM) {   // (chosen by rule: shown as what it comes to today)
            $latest = $d['cover'] === COVER_LATEST;
            $cover = ['name' => $latest ? 'the most recent photo' : 'a random photo', 'file' => $path === '' ? root_cover_choice($dir, $files, $d['cover']) : pick_folder_cover(folder_files_under($files, $path), $d['cover'], $path)];
        } elseif (is_string($d['cover'] ?? null) && $d['cover'] !== '') {
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
                if ($web !== '') { $kinds[] = 'link'; $tags[] = ['cap', 'info link']; $text[] = ['link', $web]; }
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
    $chips = [['all', 'All'], ['private', 'Private'], ['caption', 'Captions'], ['link', 'Info links'], ['location', 'Locations'], ['address', 'New links'], ['folder', 'Folder settings']];
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
      <p class="lock-fix-go" data-lock-rules-panel hidden><button type="button" class="secondary lock-go" onclick="return adminGoToRules();">Fix this: the web server rules &rarr;</button> <span class="muted">One block for nginx and one for Apache, in Settings &rarr; Server setup.</span></p>
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
function render_admin_instant_toggle($csrfToken, $action, $field, $on, $label, $warn = '', $labelHtml = '', $formClass = '', $following = false) {
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
?>
    <form method="post" class="<?= $formClass !== '' ? $esc($formClass) : 'instant-form' ?>">
      <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
      <input type="hidden" name="action" value="<?= $esc($action) ?>">
      <input type="hidden" name="<?= $esc($field) ?>" value="0">
      <div class="instant-row">
        <label class="switch<?= $following ? ' following' : '' ?>"<?= $following ? ' title="Following the folder above (dotted): press it to choose for this folder"' : '' ?>><input type="checkbox" name="<?= $esc($field) ?>" value="1" aria-label="<?= $esc($label) ?>" <?= $on ? 'checked' : '' ?>><span class="slider"></span></label>
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
function admin_cover_state($dir, $files, $folderPath) {   // $folderPath null: the front page
    $isRoot = $folderPath === null;
    $prefix = $isRoot ? '' : $folderPath . '/';
    $members = $isRoot ? array_values($files) : folder_files_under($files, $folderPath);
    $kids = []; $own = 0;
    foreach ($members as $f) {
        $rest = substr($f, strlen($prefix)); $slash = strpos($rest, '/');
        if ($slash === false) $own++; else $kids[substr($rest, 0, $slash)] = true;
    }
    $items = $members ? items_get_all_for_files($dir, $members) : [];
    $label = function ($path, $fallback) use ($dir) { $l = folder_display_meta($dir, $path)['label']; return $l !== '' ? $l : $fallback; };
    // What a visitor gets is what the row shows: the owner sees everything, so the pick is made from what a stranger could see (the front page: public photos only, nothing narrower).
    $seen = function ($list) use ($dir, $items, $isRoot, $folderPath) {
        $segs = $isRoot ? 0 : substr_count($folderPath, '/') + 1;
        $pub = array_values(array_filter($list, function ($f) use ($dir, $items, $isRoot, $segs) {
            if ($isRoot) return root_cover_allows($dir, $f, $items);
            $q = quiet_root_depth($dir, $f);   // (an Unlisted folder beneath this one is left out of its page, so out of its cover too)
            return !file_is_hidden($dir, $f, $items) && ($q === 0 || $q <= $segs);
        }));
        return $pub ?: ($isRoot ? [] : array_values($list));   // (a folder with nothing public: the owner's own view of it)
    };
    $spec = $isRoot ? root_display_meta($dir)['cover'] : folder_display_meta($dir, $folderPath)['cover'];
    $mine = $seen($members);
    $name = function ($f) { return $f === null ? '' : basename($f); };
    $childOf = function ($f) use ($prefix) { $rest = substr((string) $f, strlen($prefix)); $slash = strpos($rest, '/'); return $slash === false ? '' : substr($rest, 0, $slash); };
    $file = null; $from = ''; $notPublic = false;
    if ($spec === null) {
        $mode = 'auto';
        if ($isRoot) { $file = null; foreach ($seen($members) as $f) { $file = $f; break; } $title = 'Automatic: one of your folders\' covers'; $small = 'A different one each day. Nothing chosen.'; $pool = []; foreach (array_map('strval', array_keys($kids)) as $k) { $u = $seen(folder_files_under($members, $k)); $c = $u ? pick_folder_cover($u, folder_display_meta($dir, $k)['cover'], $k) : null; if ($c !== null && !is_video_file($c)) $pool[] = $c; } if ($pool) $file = $pool[crc32(gmdate('Y-m-d') . '|' . $dir) % count($pool)]; }
        else {
            $file = pick_folder_cover($mine, null, $folderPath); $src = $childOf($file);
            $title = $own || $src === '' ? 'Automatic: the first photo in it' : 'Automatic: the cover of ' . $label($prefix . $src, $src);
            $small = $own || $src === '' ? 'Nothing chosen.' : 'Its first folder. Nothing chosen.';
            $from = $own ? '' : $src;
        }
    } elseif ($spec === COVER_LATEST) {
        $mode = 'latest'; $file = $isRoot ? root_cover_choice($dir, $members, $spec, $items) : pick_folder_cover($mine, $spec, $folderPath);
        $title = 'Most recent photo'; $small = ($isRoot ? 'The newest public photo in the whole gallery' : 'The newest photo in it by date taken') . ($file !== null ? ' (now: ' . $name($file) . ')' : '') . '. ' . ($isRoot ? 'Used when the gallery is shared.' : 'It changes as photos are added.');
    } elseif ($spec === COVER_RANDOM) {
        $mode = 'random'; $file = $isRoot ? root_cover_choice($dir, $members, $spec, $items) : pick_folder_cover($mine, $spec, $folderPath);
        $title = 'A random photo'; $small = 'A different photo each day' . ($file !== null ? ' (today: ' . $name($file) . ')' : '') . ($isRoot ? ', from the whole gallery\'s public photos.' : '.');
    } elseif (strncmp($spec, 'follow:', 7) === 0 && !$isRoot) {
        $mode = 'follow'; $file = pick_folder_cover($mine, $spec, $folderPath); $from = substr($spec, 7);
        $title = 'Follows the cover of ' . $label($prefix . $from, $from); $small = 'Change that folder\'s cover and this changes too.';
    } else {
        $mode = 'pin';
        if ($isRoot) {
            $notPublic = !in_array($spec, $members, true) || !root_cover_allows($dir, $spec, $items);
            $file = in_array($spec, $members, true) ? $spec : null;
            $title = 'Chosen: ' . basename($spec);
            $small = $notPublic ? 'That photo is not public (or is gone), so visitors see the automatic pick instead. Choose another.' : 'That photo, whatever else the gallery holds.';
        } else {
            $file = pick_folder_cover($mine, $spec, $folderPath);
            if (strpos($spec, '/') !== false) { $title = 'Chosen: ' . $label($prefix . $childOf($prefix . $spec), $childOf($prefix . $spec)) . ' / ' . basename($spec); $small = 'That photo, whatever the folder\'s own cover is.'; $from = ''; }
            else { $title = 'Chosen: ' . $spec; $small = 'A photo in this folder.'; }
        }
    }
    $tiles = []; $tabs = [];
    $ownCount = 0; foreach ($members as $f) { if (strpos(substr($f, strlen($prefix)), '/') === false && !is_video_file($f) && (!$isRoot || root_cover_allows($dir, $f, $items))) $ownCount++; }
    if ($ownCount > 0) $tabs[] = ['child' => '', 'label' => $isRoot ? 'Front page' : 'This folder'];
    foreach (order_folder_children($dir, $prefix, array_keys($kids)) as $kname) {
        $under = array_values(array_filter($members, function ($f) use ($prefix, $kname) { return strncmp($f, $prefix . $kname . '/', strlen($prefix . $kname) + 1) === 0; }));
        $vis = $isRoot ? array_values(array_filter($under, function ($f) use ($dir, $items) { return root_cover_allows($dir, $f, $items); })) : $under;
        if ($isRoot && !$vis) continue;   // (a folder with nothing public in it is not offered for the front page)
        $cspec = folder_display_meta($dir, $prefix . $kname)['cover'];
        $cf = pick_folder_cover($isRoot ? $vis : $under, $cspec, $prefix . $kname);
        $rec = items_read(resolve_real_path($dir, $prefix . $kname));
        $tiles[] = ['name' => $kname, 'label' => $label($prefix . $kname, $kname), 'file' => $cf, 'url' => $cf !== null ? img_url($cf, 'cover') : '', 'chosen' => $cspec !== null,
            'state' => ($rec['restriction'] ?? '') === 'private' ? (!empty($rec['unlisted']) ? 'only me' : 'private') : (quiet_flag_of($rec) ? 'unlisted' : '')];
        $tabs[] = ['child' => $kname, 'label' => $label($prefix . $kname, $kname)];
    }
    return ['mode' => $mode, 'title' => $title, 'small' => $small, 'file' => $file, 'url' => $file !== null ? img_url($file, 'cover') : '', 'from' => $from, 'tiles' => $tiles, 'tabs' => $tabs,
            'root' => $isRoot, 'hasKids' => (bool) $kids, 'notPublic' => $notPublic];
}

/** The Cover photo row (folder settings, under the name; the front page's, under its title): the cover in use as a small card (its picture, what it is, "Pick a photo…", and for a
 *  folder that holds folders "Follow a folder…"), the choices Automatic, Most recent and Random, and two pickers the page's script opens: a photo (the front page offers public ones only)
 *  and, for a folder that holds folders, the folder whose cover this follows. Each choice is one request and shows at once. */
function render_admin_cover_row($folderPath) {
    $dir = $GLOBALS['dir']; $files = $GLOBALS['__admin_files'] ?? [];
    $st = admin_cover_state($dir, $files, $folderPath);
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $modes = ['auto' => 'Automatic', 'latest' => 'Most recent', 'random' => 'Random'];
    $hint = $st['root'] ? 'Automatic rotates through the covers of your folders each day. Only public photos can be chosen.' : 'Random is a different photo each day. Pressing the star on a photo picks that one instead.';
?>
        <div class="field cover-field fs-cover" data-cover data-root="<?= $st['root'] ? '1' : '0' ?>">
          <label>Cover photo <span class="rowstate" role="status" aria-live="polite"></span></label>
          <div class="cv-card">
            <div class="cv-row">
              <img class="cv-img" src="<?= $esc($st['url']) ?>" alt=""<?= $st['url'] === '' ? ' hidden' : '' ?>>
              <div class="cv-main"><b class="cv-title"><?= $esc($st['title']) ?></b><small class="cv-small"><?= $esc($st['small']) ?></small></div>
            </div>
            <div class="cv-acts"><button type="button" class="secondary cv-choose">Pick a photo&hellip;</button><?php if ($st['hasKids'] && !$st['root']): ?><button type="button" class="secondary cv-follow">Follow a folder&hellip;</button><?php endif; ?></div>
          </div>
          <div class="seg seg-cover" role="radiogroup" aria-label="How the cover is chosen">
<?php foreach ($modes as $v => $nm): ?>
            <label><input type="radio" name="cover_mode" value="<?= $esc($v) ?>"<?= $st['mode'] === $v ? ' checked' : '' ?>><span><?= $esc($nm) ?></span></label>
<?php endforeach; ?>
          </div>
          <p class="hint cv-hint"><?= $esc($hint) ?></p>
          <div class="cv-pick" hidden>
            <h4>Pick a photo</h4>
            <p class="cv-pick-note"><?= $st['root'] ? 'Public photos only: the front page is what everyone sees.' : 'This photo is the cover whatever else changes.' ?></p>
            <div class="cv-tabs">
<?php foreach ($st['tabs'] as $t): ?>
              <button type="button" class="cv-tab" data-child="<?= $esc($t['child']) ?>"><?= $esc($t['label']) ?></button>
<?php endforeach; ?>
            </div>
            <div class="cv-photos"><?= $st['tabs'] ? '' : 'There are no photos to pick from here.' ?></div>
            <div class="cv-foot"><button type="button" class="secondary cv-cancel">Cancel</button></div>
          </div>
<?php if ($st['hasKids'] && !$st['root']): ?>
          <div class="cv-follow-pick" hidden>
            <h4>Show the cover of&hellip;</h4>
            <p>This folder follows that folder's cover: change it there and this changes too.</p>
            <div class="cv-grid">
<?php foreach ($st['tiles'] as $t): ?>
              <button type="button" class="cv-tile<?= $t['name'] === $st['from'] && $st['mode'] === 'follow' ? ' sel' : '' ?>" data-child="<?= $esc($t['name']) ?>"><?php if ($t['url'] !== ''): ?><img src="<?= $esc($t['url']) ?>" alt="" loading="lazy"><?php endif; ?><i aria-hidden="true"></i><b><?= $esc($t['label']) ?></b><?php if ($t['state'] !== ''): ?><small><?= $esc($t['state']) ?></small><?php endif; ?></button>
<?php endforeach; ?>
            </div>
            <div class="cv-foot"><button type="button" class="secondary cv-cancel">Cancel</button></div>
          </div>
<?php endif; ?>
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

/** A folder's visibility, in one run under the folder's description and its Cards/Flat choice: "Who can see this folder" (Public, Unlisted, Private, Only me:
 *  one choice, each button wearing the colour of the badge on the folder's card), then Gallery of its own and, for a public folder, Visitors can download
 *  originals; for Only me, its own two switches. Each is saved the moment it is pressed. The gallery's own top has none of them. */
function render_admin_folder_switches($itemsHere, $csrfToken, $adminFolderPath, $counts) {
    if ($adminFolderPath === null) return;
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $private = !empty($itemsHere['restriction']) && $itemsHere['restriction'] === 'private';
    $me = $private && ($itemsHere['unlisted'] ?? null) === true;
    $quiet = !$private && quiet_flag_of($itemsHere);
    $level = $me ? 'me' : ($private ? 'private' : ($quiet ? 'unlisted' : 'public'));
    $names = ['public' => 'Public', 'unlisted' => 'Unlisted', 'private' => 'Private', 'me' => 'Only me'];
    $descs = visibility_descriptions();
    $rank = ['public' => 0, 'unlisted' => 1, 'private' => 2, 'me' => 3];
    $above = folder_above_info($GLOBALS['dir'], $adminFolderPath);     // (the strictest folder above, whatever this folder's own setting is)
    $follow = $above !== null && $rank[$above['level']] > $rank[$level] ? $above : null;   // (a stricter folder above: this folder, with no say of its own that is as strict, follows it)
    $sameAbove = $above !== null && $level !== 'public' && $rank[$above['level']] === $rank[$level];   // (its own setting is as strict as the folder above: pressing it again goes back to following)
    $eff = $follow !== null ? $follow['level'] : $level;               // (what visitors actually get)
    $effPrivate = $eff === 'private' || $eff === 'me';
    $ownExplicit = array_key_exists('ownGallery', $itemsHere) && is_bool($itemsHere['ownGallery']) ? $itemsHere['ownGallery'] : null;
    $ownOn = $ownExplicit ?? $quiet;   // (an Unlisted folder is a gallery of its own unless the owner has said otherwise)
?>
    <div class="fs-vis">
    <label id="vis-label">Who can see this folder <span class="rowstate" role="status" aria-live="polite"></span></label>
    <div class="folder-switches">
    <div class="vis-choice<?= $follow !== null ? ' following' : '' ?>" id="vis-choice" data-level="<?= $esc($level) ?>" data-follow="<?= $follow !== null ? $esc($follow['level']) : '' ?>" data-from-name="<?= $follow !== null ? $esc($follow['label']) : '' ?>" data-above="<?= $above !== null ? $esc($above['level']) : '' ?>" data-above-name="<?= $above !== null ? $esc($above['label']) : '' ?>" data-above-path="<?= $above !== null ? $esc($above['path']) : '' ?>" data-csrf="<?= $esc($csrfToken) ?>" data-noun="<?= ENABLE_VIDEO ? 'item' : 'photo' ?>" data-stay="<?= (int) $counts['stay'] ?>" data-open="<?= (int) $counts['open'] ?>" data-descs="<?= $esc(json_encode($descs)) ?>">
      
      <div class="seg vis-seg" role="radiogroup" aria-labelledby="vis-label">
        <?php foreach ($names as $v => $nm): $off = $follow !== null && $rank[$v] < $rank[$eff]; ?><label data-v="<?= $esc($v) ?>"<?= $off ? ' class="is-off" title="' . $esc('Cannot be more open than the folder above it (' . $follow['label'] . ' is ' . $names[$follow['level']] . ')') . '"' : '' ?>><input type="radio" name="vis" value="<?= $esc($v) ?>"<?= $eff === $v ? ' checked' : '' ?><?= $off ? ' disabled' : '' ?>><span><?= $esc($nm) ?></span></label><?php endforeach; ?>
      </div>
      <p class="hint vis-follow" id="vis-follow"<?= $follow !== null ? '' : ' hidden' ?>><span class="vf-text"><?php if ($follow !== null): ?><strong><?= $esc($names[$follow['level']]) ?></strong> because the folder above it, <a href="?admin&amp;folder=<?= $esc(rawurlencode($follow['path'])) ?>#settings"><?= $esc($follow['label']) ?></a>, is <?= $esc($names[$follow['level']]) ?>. Change it there; this folder follows it.<?php endif; ?></span> <span class="vf-own"<?= $follow !== null && $level !== 'public' ? '' : ' hidden' ?>><?php if ($follow !== null && $level !== 'public'): ?>Its own choice, <?= $esc($names[$level]) ?>, takes over if <?= $esc($follow['label']) ?> is opened up. <button type="button" class="linklike vf-remove">Remove it</button><?php endif; ?></span></p>
      <p class="hint vis-same" id="vis-same"<?= $sameAbove ? '' : ' hidden' ?>><?php if ($sameAbove && $level === 'unlisted'): ?>This folder is Unlisted in its own right, so it is left off the page of <?= $esc($above['label']) ?> above it as well. Press Unlisted again to take that away (it would then show on that page).<?php elseif ($sameAbove): ?>This folder has its own <?= $esc($names[$level]) ?> setting, as strict as <?= $esc($above['label']) ?> above it. Press <?= $esc($names[$level]) ?> again to follow <?= $esc($above['label']) ?> instead.<?php endif; ?></p>
      <p class="hint vis-desc" aria-live="polite"><?= $esc($descs[$eff]) ?></p>
      <form method="post" class="nojs-only"><input type="hidden" name="csrf_token" value="<?= $esc($csrfToken) ?>"><input type="hidden" name="action" value="set_folder_visibility"><input type="hidden" name="was" value="<?= $esc($level) ?>"><input type="hidden" name="above" value="<?= $above !== null ? $esc($above['level']) : '' ?>">   <?php // what the page does without JavaScript: the same choice, and a Save button ?>
        <select name="level" aria-label="Who can see this folder"><?php foreach ($names as $v => $nm): ?><option value="<?= $esc($v) ?>"<?= $level === $v ? ' selected' : '' ?>><?= $esc($nm) ?></option><?php endforeach; ?></select> <button type="submit">Save</button>
      </form>
    </div>
    <div class="unlisted-sub"<?= $me ? '' : ' hidden' ?>>
      <?php render_admin_instant_toggle($csrfToken, 'toggle_folder_address_file', 'addrfile', !empty($itemsHere['addressFile']), !empty($itemsHere['addressFile']) ? 'Links send the bare file' : 'Links open in the gallery', '', '', 'unlisted-form'); ?>
      <?php render_admin_instant_toggle($csrfToken, 'toggle_folder_originals', 'originals', !empty($itemsHere['allowOriginals']), 'Allow downloading originals', '', ' <small class="muted">from a photo\'s link</small>', 'unlisted-form'); ?>
    </div>
    <div class="own-gallery-row<?= $me ? ' is-moot' : '' ?>" id="own-gallery-row">
      <?php render_admin_instant_toggle($csrfToken, 'toggle_folder_own_gallery', 'owngallery', $ownOn, 'Gallery of its own', '', ' <small class="muted">(limits navigation)</small>', 'unlisted-form'); ?>
      <p class="hint own-note" data-own-explicit="<?= $ownExplicit === null ? '' : ($ownExplicit ? '1' : '0') ?>"><?= $esc(own_gallery_note($level, $ownExplicit)) ?></p>
    </div>
    <?php if (!gallery_is_closed()): $dlFolders = public_download_current()[0]; ?>
    <div class="dl-block"<?= $effPrivate ? ' hidden' : '' ?>>
      <?php render_admin_instant_toggle($csrfToken, 'toggle_folder_downloads', 'dl', in_array($adminFolderPath, $dlFolders, true), 'Visitors can download originals', '', '', 'unlisted-form'); ?>
      <?php $dlRow = admin_downloads_inside_html($GLOBALS['dir'], $GLOBALS['__admin_files'] ?? [], $adminFolderPath); if ($dlRow !== ''): ?>
      <div class="dl-inside" data-dl-inside data-csrf="<?= $esc($csrfToken) ?>"<?= in_array($adminFolderPath, $dlFolders, true) ? '' : ' hidden' ?>>
        <span class="dl-inside-body"><?= $dlRow ?></span>
        <span class="rowstate" role="status" aria-live="polite"></span>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    </div>
    </div>
    <div class="admin-modal" id="priv-modal" role="dialog" aria-modal="true" aria-labelledby="priv-modal-title" hidden>
      <div class="admin-modal-card">
        <h3 id="priv-modal-title">Make this folder public?</h3>
        <div class="pm-row pm-stay"><?= ui_icon('lock') ?><span><strong id="pm-stay">0 photos</strong> <span id="pm-stay-v">stay</span> private</span></div>
        <div class="pm-row pm-open"><?= ui_icon('unlock') ?><span><strong id="pm-open">0 photos</strong> <span id="pm-open-v">become</span> public</span></div>
        <p class="hint" id="pm-hint">Anyone with the link will see those. Not right? Cancel, mark more photos private first, then come back.</p>
        <div class="pm-btns"><button type="button" class="secondary" id="pm-cancel">Cancel</button><button type="button" id="pm-ok">Make public</button></div>
      </div>
    </div>
<?php
}

/** What each level of "Who can see this folder" means, in one plain sentence (shown under the buttons, and kept in step by the page's script). */
function visibility_descriptions() {
    return [
        'public' => 'Everyone can open it, and it shows in the gallery.',
        'unlisted' => 'Anyone with the address can open it, but it is not shown anywhere: not on the gallery\'s page, among the folders, on the map or in counts, and search engines are asked to skip it.',
        'private' => 'Only you, people with the private-view password, and share links that include it. Everyone else gets "not found".',
        'me' => 'Only you. Not even the private-view password opens it. You share it one photo at a time, with each photo\'s own address, or with a share link that picks this folder; an "Everything" share link leaves it out.',
    ];
}

/** The line under "Gallery of its own": why it is on when the folder is Unlisted and the owner has not chosen, and that it does nothing for Only me. */
function own_gallery_note($level, $explicit) {
    if ($level === 'me') return 'No effect: only you can open this folder.';
    if ($level === 'unlisted' && $explicit === null) return 'On because this folder is Unlisted: someone who types the address sees only this folder, with no way up or across to the rest of your gallery. Switch it off if you want them to find their way to the rest.';
    return 'Someone who arrives here by a typed or pasted address sees only this folder, with no way up or across.';
}

/** Under a switch a folder takes from the folder above it: where its setting comes from (this folder's own choice, or the folder it is taken from), and
 *  a link back to taking the one above's when the folder has made its own. */
function render_admin_flag_source($csrfToken, $action, $field, $flag, $isRoot, $dir, $inheritedOn = null) {
    $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    if ($isRoot) return;
    if ($flag['own'] !== null) {
        $text = 'This folder\'s own choice.';
        if ($inheritedOn === false && $flag['own'] === false) $text .= ' It stays off even if the folder above turns it on.';   // (a protective choice: said, so it is not a surprise later)
        elseif ($inheritedOn === false && $flag['own'] === true) $text .= ' On here, though the folder above has it off.';
        elseif ($inheritedOn === true && $flag['own'] === false) $text .= ' Off here, though the folder above has it on.';
    } elseif ($flag['from'] === null) {
        $text = 'No folder above has chosen, so this is off.';
    } else {
        // Named exactly: the folder it comes from, or the gallery root (the front page's own settings), each a link to the settings it comes from
        $text = $flag['from'] === ''
            ? 'Taken from <a href="?admin#settings">the gallery root</a>.'
            : 'Taken from <a href="?admin&amp;folder=' . rawurlencode((string) $flag['from']) . '#settings">' . $esc($flag['from']) . '</a>.';
    }
?>
    <p class="hint flag-source"><?= $text ?><?php if ($flag['own'] !== null): ?> <button type="button" class="linklike" data-flag-inherit data-csrf="<?= $esc($csrfToken) ?>" data-action="<?= $esc($action) ?>" data-field="<?= $esc($field) ?>">Use the folder above's choice</button><?php elseif (!$flag['on']): ?> <button type="button" class="linklike" data-flag-pin data-csrf="<?= $esc($csrfToken) ?>" data-action="<?= $esc($action) ?>" data-field="<?= $esc($field) ?>" title="Keep it off for this folder even if the folder above turns it on later">Keep it off here</button><?php endif; ?></p>
<?php
}

/** What the page needs after a folder's switch that follows the folder above was pressed: whether it now follows (drawn dotted), what it is, and the line under it as HTML. */
function admin_flag_payload($dir, $adminFolderPath, $key, $action, $field) {
    $isRoot = $adminFolderPath === null;
    $flag = $key === 'mapEnabled' && !$isRoot ? folder_map_resolve($dir, $adminFolderPath) : folder_flag_resolve($dir, $isRoot ? '' : $adminFolderPath, $key);
    $inh = $isRoot ? null : ($key === 'mapEnabled' ? folder_map_inherited($dir, $adminFolderPath)['on'] : folder_flag_inherited($dir, $adminFolderPath, $key)['on']);
    ob_start(); render_admin_flag_source(admin_csrf_token(), $action, $field, $flag, $isRoot, $dir, $inh); $html = trim((string) ob_get_clean());
    return ['following' => !$isRoot && $flag['own'] === null, 'on' => (bool) $flag['on'], 'html' => $html];
}

/** The "Media metadata" section, last on a folder's (or the gallery's) settings: whether photo locations show, under that because it
 *  only shows photos whose location is public the map. First comes whether the folder offers the viewer's info panel. */
function render_admin_locations_section($itemsHere, $csrfToken, $adminFolderPath, $locWarn = '', $locStatus = '', $dir = null) {
    $isRoot = $adminFolderPath === null;
    $locFlag = folder_flag_resolve($dir, $isRoot ? '' : $adminFolderPath, 'locationEnabled');   // (this folder's own choice, or the one it takes from the folder above)
    $infoFlag = folder_flag_resolve($dir, $isRoot ? '' : $adminFolderPath, 'mediaInfo');
    $locOn = $locFlag['on'] && ENABLE_PHOTO_LOCATION;
?>
    <h3 class="subhead">Media metadata</h3>
    <?php $infoJump = !INFO_PANEL ? ' (the info panel is off for the whole gallery, in <a href="#site" onclick="return adminGoTo(\'site\', \'setting-INFO_PANEL\');">Settings</a>)' : ''; ?>
    <div class="info-under<?= INFO_PANEL ? '' : ' no-info' ?>" id="info-under">
      <?php render_admin_instant_toggle($csrfToken, 'toggle_media_info', 'info', $infoFlag['on'], $isRoot ? 'Show media info to visitors at the root' : 'Show media info to visitors for this folder', '', $infoJump, '', !$isRoot && $infoFlag['own'] === null); render_admin_flag_source($csrfToken, 'toggle_media_info', 'info', $infoFlag, $isRoot, $dir, $isRoot ? null : folder_flag_inherited($dir, $adminFolderPath, 'mediaInfo')['on']); ?>
      <p class="hint">The viewer gets an info panel for each photo or video here (what the file says about itself: camera, when it was taken, its size, and whatever else is switched on in Settings, under Media metadata). A position or a time zone shows only where the photo's location does.</p>
    </div>
    <?php $locJump = !ENABLE_PHOTO_LOCATION ? ' (locations are off for the whole gallery, in <a href="#site" onclick="return adminGoTo(\'site\', \'setting-ENABLE_PHOTO_LOCATION\');">Settings</a>)' : '';
          render_admin_instant_toggle($csrfToken, 'toggle_location_enabled', 'enabled', $locFlag['on'], $isRoot ? 'Show photo locations to visitors at the root' : 'Show photo locations to visitors for this folder', $locWarn, $locJump, '', !$isRoot && $locFlag['own'] === null);
          render_admin_flag_source($csrfToken, 'toggle_location_enabled', 'enabled', $locFlag, $isRoot, $dir, $isRoot ? null : folder_flag_inherited($dir, $adminFolderPath, 'locationEnabled')['on']); ?>
    <div class="loc-group">
    <?php if (ENABLE_PHOTO_LOCATION): ?>
    <div class="loc-keep"<?= $locFlag['on'] ? '' : ' hidden' ?>>
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
      <?php render_admin_map_form($itemsHere, $csrfToken, $isRoot, $dir, $adminFolderPath); ?>
    </div>
    </div>
<?php
}

