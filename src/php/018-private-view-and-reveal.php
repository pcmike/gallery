</style>
</head>
<body>
<div class="wrap">
  <h1>HTTPS required</h1>
  <p>This page asks for a password, and over a plain <code>http://</code> connection that password travels in a form anyone on the network path can read. To protect it, this page refuses to run at all without HTTPS.</p>
  <p>If you're testing this on your own machine, visiting it as <code>localhost</code> or <code>127.0.0.1</code> is allowed over plain HTTP. For a real deployment, put this site behind HTTPS — <a href="https://letsencrypt.org/" target="_blank" rel="noopener noreferrer">Let's Encrypt</a> issues free certificates, and most hosting control panels can set one up in a few clicks.</p>
</div>
</body>
</html>
<?php
}

/* -----------------------------------------------------------------------
 * Private view: a hidden item (or an entirely hidden folder-group) is
 * omitted from the public page outright, for any visitor without a
 * valid "reveal" — either the ?reveal password, or a valid admin login
 * (one-directional: admin implies revealed, revealed never implies
 * admin). The reveal cookie holds a random token that the server
 * recognises (see "Browsers that are signed in"), so one browser can be
 * signed out without touching any other; a visitor who never entered a
 * real password has no way to produce a good one. Each item's restriction is a small named value ("public" /
 * "private"), not a bare boolean, so a second audience tier later is an
 * addition, not a rewrite of the flag itself.
 * ----------------------------------------------------------------------- */

/** Sets the private-view token, after a successful ?reveal login: this browser is then recognised as a guest for PRIVATE_VIEW_SIGNED_IN_DAYS
 *  (see "Browsers that are signed in"). A plain cookie, not a PHP session, since this has to survive on the *public*, unauthenticated
 *  page view, not just inside ?admin/?reveal themselves. */
function set_reveal_cookie($stored = null) {
    if (PRIVATE_VIEW_PASSWORD === '' && $stored === null) return;
    browser_issue('view', $stored);
}

/** Makes sure this browser carries the owner's token, on every authenticated admin request: it is how "a valid admin login counts as
 *  revealed too" works, so the owner sees their own hidden items (in the admin's thumbnails and when browsing the plain gallery) without a
 *  second credential, and without needing a private-view password to exist. $password is passed only right after the password was just
 *  set or changed, when the constant still holds the old value: that always makes a new token. */
function set_admin_reveal_cookie($password = null) {
    if (ADMIN_PASSWORD === '' && $password === null) return;
    $b = browser_lookup();
    if ($password === null && $b !== null && $b['kind'] === 'owner') return;   // (already the owner's; its end slides with use)
    browser_issue('owner', $password, $password === null && (isset($_SERVER['PHP_AUTH_PW']) || isset($_SERVER['PHP_AUTH_USER'])) && !(session_status() === PHP_SESSION_ACTIVE && admin_session_authed()));
}

/** True if this visitor can see hidden items: they carry a good token, either the guest's (from ?reveal) or the owner's (from an ?admin
 *  login). With neither password set, no token can be good, so a hidden item simply stays hidden: the same closed-by-default
 *  posture as every other gate in this script. */
function is_revealed() {
    if (link_preview() !== null) return false;   // while previewing a link this browser is not the owner's (gallery and media pages only)
    return browser_lookup() !== null;
}

/** Normalizes a stored restriction value to exactly "inherit", "public",
 *  or "private" — missing/corrupt/unrecognized all degrade to "inherit",
 *  the always-safe default of "nothing changes until you touch it." Used
 *  both for an item's own three-state override (inherit the folder's
 *  setting / always show / always hide — same shape as location-
 *  visibility's own tri-state) and for a folder's own entry, where only
 *  the "private" value is ever meaningful (a folder has nothing to
 *  "inherit" from in this one-level-deep model). */
function item_restriction($entry) {
    $r = is_array($entry) ? ($entry['restriction'] ?? 'inherit') : 'inherit';
    return in_array($r, ['public', 'private'], true) ? $r : 'inherit';
}

/** Whether $file is hidden from an unrevealed visitor. An item's own
 *  explicit "public"/"private" always wins, in either direction — a
 *  folder hidden entirely can still have one specific item deliberately
 *  shown, and a public folder can still have one sensitive item hidden;
 *  neither needs the other's permission. "Inherit" (the default) falls
 *  through to the folders $file sits in: hidden if *any* ancestor, at any
 *  depth, is flagged private (see AUTO_GROUP_BY_FOLDER). */
function file_is_hidden($dir, $file, $itemsData) {
    $own = item_restriction($itemsData[$file] ?? null);
    if ($own === 'private') return true;
    if ($own === 'public') return file_is_unlisted($dir, $file);   // (inside a Not listed folder a public mark changes nothing: the folder is the owner's alone)
    return folder_above_is_private($dir, $file);
}

/** Whether any folder $file sits in, at any depth, is private (what an item with no setting of its own inherits). A folder
 *  made private at any depth hides everything beneath it, the same way a private top-level folder always has. */
function folder_above_is_private($dir, $file) {
    if (AUTO_GROUP_BY_FOLDER && strpos($file, '/') !== false) {
        $path = '';
        foreach (explode('/', dirname($file)) as $segment) {
            $path = $path === '' ? $segment : $path . '/' . $segment;
            if (folder_restriction_cached($dir, $path) === 'private') {
                return true;
            }
        }
    }
    return false;
}

/** Whether any folder $file sits in, at any depth, is Not listed: private, with the owner's "not listed" flag (a folder the owner keeps for
 *  themselves and shares from one photo at a time). It is stored as private plus the flag, so a version that doesn't know the flag
 *  still treats the folder as private. Anything beneath a Not listed folder is Not listed too. */
function folder_above_is_unlisted($dir, $file) {
    return unlisted_root_depth($dir, $file) > 0;
}

/** How many path segments of $file's folders it takes to reach the topmost Not listed folder above it (1 for a top-level folder), or 0
 *  when none of them is. */
function unlisted_root_depth($dir, $file) {
    if (AUTO_GROUP_BY_FOLDER && strpos($file, '/') !== false) {
        $path = ''; $depth = 0;
        foreach (explode('/', dirname($file)) as $segment) {
            $path = $path === '' ? $segment : $path . '/' . $segment;
            $depth++;
            if (folder_unlisted_cached($dir, $path)) return $depth;
        }
    }
    return 0;
}

/** Is the item in a Not listed folder? (Its own marks make no difference: see file_is_hidden.) */
function file_is_unlisted($dir, $file) {
    return folder_above_is_unlisted($dir, $file);
}

/** Is this folder Not listed, on its own or because a folder above it is? */
function folder_is_unlisted($dir, $folderPath) {
    return folder_unlisted_cached($dir, $folderPath) || folder_above_is_unlisted($dir, $folderPath . '/x');
}

/** One folder's own Not listed flag, read once per request like folder_restriction_cached (and reset with it). True only while the
 *  folder is also private, so removing the privacy removes this too. */
function folder_unlisted_cached($dir, $folderRel, $reset = false) {
    static $cache = [];
    if ($reset) {
        $cache = [];
        return null;
    }
    if (!isset($cache[$folderRel])) {
        $data = items_read(resolve_real_path($dir, $folderRel));
        $cache[$folderRel] = item_restriction($data) === 'private' && ($data['unlisted'] ?? null) === true;
    }
    return $cache[$folderRel];
}

/** The owner's view of one photo's privacy: its own setting ("private", "public", or "" when it has none and follows its folder),
 *  whether a folder above it is private (what "" would give it), and what that makes it. */
function owner_privacy_state($dir, $file, $itemsData) {
    $own = item_restriction($itemsData[$file] ?? null);
    $inh = folder_above_is_private($dir, $file);
    return ['own' => $own === 'inherit' ? '' : $own, 'inh' => $inh, 'priv' => $own === 'private' || ($own === 'inherit' && $inh)];
}

/** What pressing a photo's lock does, in words (its tooltip). In a private folder the lock goes round three states; elsewhere it is two. */
function owner_lock_title($ps) {
    if ($ps['inh']) {
        if ($ps['own'] === 'private') return 'Kept private on its own: press to make it public';
        if ($ps['own'] === 'public') return 'Public, though its folder is private: press to follow the folder again';
        return 'Private because its folder is: press to keep it private on its own';
    }
    return $ps['own'] === 'private' ? 'Private: press to make it public' : 'Public: press to make it private';
}

/** "3 photos" (or "3 items" when videos are shown too): a count with its noun, for the private-folder counts. */
function count_noun($n) {
    return number_format((int) $n) . ' ' . (ENABLE_VIDEO ? 'item' : 'photo') . ((int) $n === 1 ? '' : 's');
}

/** What opening a private folder would do, for the owner: how many of the photos and videos under $folderPath would still be
 *  hidden once the folder itself is public (switched private on their own, or inside another private folder) and how many would
 *  become public. Counts everything beneath the folder, to any depth. */
function folder_privacy_counts($dir, $files, $folderPath) {
    $stay = 0; $open = 0;
    $prefix = $folderPath . '/';
    $folders = [];   // each folder's items, read once
    foreach ($files as $f) {
        if (strncmp($f, $prefix, strlen($prefix)) !== 0) continue;
        $where = dirname($f);
        if (!isset($folders[$where])) $folders[$where] = items_read(resolve_real_path($dir, $where))['items'] ?? [];
        $own = item_restriction($folders[$where][basename($f)] ?? null);
        if ($own === 'private') { $stay++; continue; }
        if ($own === 'public') { $open++; continue; }
        $hiddenElsewhere = false;
        if (AUTO_GROUP_BY_FOLDER) {
            $path = '';
            foreach (explode('/', $where) as $segment) {
                $path = $path === '' ? $segment : $path . '/' . $segment;
                if ($path !== $folderPath && folder_restriction_cached($dir, $path) === 'private') { $hiddenElsewhere = true; break; }
            }
        }
        if ($hiddenElsewhere) $stay++; else $open++;
    }
    return ['stay' => $stay, 'open' => $open];
}

/** What the owner sees on a folder's card and button: whether it is private on its own ("own"), only because a folder above it is
 *  ("inh") or neither (""), and, for the first and last, how many photos and videos under it are private (so stay hidden from a
 *  visitor even if the folder is opened). Nothing for anyone but the owner. $withCount is false where only the state is drawn. */
function owner_folder_privacy($dir, $files, $folderPath, $withCount = true) {
    $none = ['state' => '', 'stay' => 0];
    if (!AUTO_GROUP_BY_FOLDER || !is_admin_browser()) return $none;
    $state = folder_restriction_cached($dir, $folderPath) === 'private' ? 'own' : (folder_above_is_private($dir, $folderPath) ? 'inh' : '');
    $stay = $withCount && $state !== 'inh' ? folder_privacy_counts($dir, $files, $folderPath)['stay'] : 0;
    return ['state' => $state, 'stay' => $stay];
}

/** Where "See them" goes for a folder: the folder's page with the photos that are private on their own first, in the order the
 *  folder already has (a ?sort= lasts for that visit only). $folderPath '' is the gallery's own page. */
function folder_private_first_href($dir, $folderPath) {
    $spec = media_sort_spec_for_folder($folderPath === '' ? $dir : resolve_real_path($dir, $folderPath));
    $spec['private'] = true;
    return ($folderPath === '' ? '?' : '?folder=' . rawurlencode($folderPath) . '&') . 'sort=' . media_sort_encode($spec);
}

/** Cache-Control for a photo/video response. Ordinary files are immutable
 *  and public (the URL changes when the file does not, and a CDN is welcome
 *  to hold them). A *hidden* item is only ever served to a trusted viewer,
 *  and a shared cache or CDN keys on the URL, not the cookie: letting one
 *  store it would hand it to the next anonymous request for the same URL.
 *  So hidden items are private and never stored. */
function file_cache_control($dir, $file, $itemsData) {
    return file_is_hidden($dir, $file, $itemsData)
        ? 'private, no-store'
        : 'public, max-age=31536000, immutable';
}

/** Whether a folder is private (on its own, or because a folder above it is). */
function folder_is_private($dir, $folderPath) {
    return folder_restriction_cached($dir, $folderPath) === 'private' || folder_above_is_private($dir, $folderPath);
}

/** One folder's own restriction, read once per request — file_is_hidden()
 *  asks about every ancestor of every file, so without this a deep tree
 *  would re-read the same small JSON files thousands of times.
 *  items_update() resets it, so an admin save is never read back stale. */
function folder_restriction_cached($dir, $folderRel, $reset = false) {
    static $cache = [];
    if ($reset) {
        $cache = [];
        folder_unlisted_cached(null, null, true);
        return null;
    }
    if (!isset($cache[$folderRel])) {
        $cache[$folderRel] = item_restriction(items_read(resolve_real_path($dir, $folderRel)));
    }
    return $cache[$folderRel];
}

