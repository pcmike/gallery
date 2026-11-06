/**
 * Derive a group key from a filename by stripping a trailing "_01" /
 * "-02" style numeric suffix. Only strips when there's an explicit
 * separator before the digits, so a standalone file like "iphone15.jpg"
 * keeps its full name rather than losing the "15". A no-op entirely
 * when AUTO_GROUP_BY_FILENAME is off — the key is then just the
 * extension-stripped filename, same as always, which is what lets
 * prose mention an item by name (e.g. "the zebra print blanket") and
 * auto-link a standalone "zebra.jpg". This can still coincidentally
 * collide two unrelated files that share a base name but differ only
 * in extension (e.g. "topology.jpg" and "topology.png") — see the
 * disambiguation pass right after $groups is built, further down.
 */
function group_key($filename) {
    $base = pathinfo($filename, PATHINFO_FILENAME);
    if (!AUTO_GROUP_BY_FILENAME) {
        return $base;
    }
    $key = preg_replace('/[_\-]\d+$/', '', $base);
    return $key === '' ? $base : $key;
}

/**
 * Builds a whole-word, case-insensitive match pattern for a group key
 * appearing in free text. Uses explicit alnum/underscore lookarounds
 * instead of \b — \b only checks for a \w/\W transition, so it doesn't
 * treat a key starting or ending in punctuation (e.g. "+model") as a
 * distinct token and can match inside an unrelated word.
 */
function group_mention_pattern($key) {
    return '/(?<![\p{L}\p{N}_])' . preg_quote($key, '/') . '(?![\p{L}\p{N}_])/iu';
}

function slugify($text) {
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-');
}

// $groups is built later (see "GROUPING" below), after .md files are
// discovered and their {group:}/{photos:} directives are extracted —
// both the ?track endpoint and the gallery renderer need the final,
// merged version, not just the filename-based auto-detection.

// ?photo=<filename-or-relative-path> deep-links straight to that photo in
// the lightbox on page load. Only ever a value that's an exact match
// against $files, the real list this script's own scan already trusts —
// never basename()'d first, since that would strip a legitimate
// subfolder prefix like "Paris/" right back off. The allowlist's exact-
// match is what actually provides the safety here, not basename(); a
// traversal attempt never matches anything the scan itself produced,
// with or without that extra step. See serve_image() below for the same
// reasoning applied to ?img=.
$requestedPhoto = null;
if (isset($_GET['photo'])) {
    $candidate = $_GET['photo'];
    if (in_array($candidate, $files, true) && viewer_may_see_item($dir, $candidate, $itemsData)) {
        $requestedPhoto = $candidate;
    }
}
// A Not listed photo opened by its own address (?photo=<path>&k=<code>): the page is that one photo and nothing else (see the filter in
// "GROUPING" below), kept out of search engines and not passed on in a Referer.
$photoPass = null;
if ($requestedPhoto !== null && !is_admin_browser() && isset($_GET['k']) && photo_pass_valid($dir, $requestedPhoto, $itemsData)) {
    $photoPass = $requestedPhoto;
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: private, no-store');
}

// ?download used to serve this file's own bytes directly. Redirects to
// the GitHub repo instead, so anyone with an old bookmarked or shared
// link still lands somewhere useful rather than a dead end. Removed
// outright, not scrubbed, specifically so this script can never hand out
// a copy of itself — including whatever secrets later constants define —
// to a visitor who asks. A live snapshot was also just a worse copy than
// GitHub's anyway: unversioned, possibly stale or hand-edited.
if (request_route() === 'download') {
    header('Location: https://github.com/pcmike/gallery', true, 302);
    exit;
}

// ?about renders a full documentation page (with the download link for
// this script at the top) instead of the gallery — this is what the
// "gallery" link in the footer points to.
if (request_route() === 'about') {
    render_about_page();
    exit;
}

// ?mapicon — the generic share-preview image for ?map (see
// render_map_icon_image()). Inert unless ENABLE_PHOTO_LOCATION is on, like ?map.
if (request_route() === 'mapicon') {
    render_map_icon_image();
    exit;
}

// ?appicon and ?manifest — what "Add to Home Screen" needs for the gallery to open as an app with no browser bars (the
// page only points at them for the signed-in owner for now; see home_screen_app_tags()). Nothing private in either.
if (request_route() === 'appicon') {
    render_app_icon_image(isset($_GET['size']) && $_GET['size'] === 'large' ? 512 : 180);
    exit;
}
if (request_route() === 'manifest') {
    $name = trim(preg_replace('/[\x00-\x1f]+/', ' ', (string) ($_GET['name'] ?? '')));
    $name = $name === '' ? 'Gallery' : (function_exists('mb_substr') ? mb_substr($name, 0, 40) : substr($name, 0, 40));
    $start = gallery_root_href();
    if ($start === './') {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $start = is_string($path) ? preg_replace('#[^/]*$#', '', $path) : '/';
    }
    header('Content-Type: application/manifest+json; charset=UTF-8');
    header('Cache-Control: no-cache');
    echo json_encode([
        'name' => $name, 'short_name' => $name, 'start_url' => $start, 'scope' => $start, 'display' => 'standalone',
        'background_color' => '#0f0f10', 'theme_color' => '#0f0f10',
        'icons' => [
            ['src' => $start . '?appicon&v=2', 'sizes' => '180x180', 'type' => 'image/png'],
            ['src' => $start . '?appicon&size=large&v=2', 'sizes' => '512x512', 'type' => 'image/png'],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

// ?reveal — the lock in the icon strip (the one sign-in). See run_reveal_route()
// for the full HTTPS/throttle/auth flow; checked early, like ?about/
// ?download, since it's a distinct mode of this one file, not something
// that needs $groups or any of the scan below.
if (request_route() === 'reveal') {
    run_reveal_route($dir);
    exit;
}

// ?link=<token> — a share link (see the "Share links" section). Early, like ?reveal: it needs nothing from the scan below.
if (request_route() === 'link') {
    run_link_route($dir);
    exit;
}

