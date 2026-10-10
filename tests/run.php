<?php
/**
 * Black-box test suite for index.php.
 *
 * Spins up PHP's built-in web server against throwaway fixture folders
 * (built here, never committed to the repo) and exercises the gallery
 * through real HTTP requests — the same way this project has always been
 * tested by hand (`php -S` + curl). Covers the documented core behaviors
 * plus regression tests for every bug fixed so far.
 *
 * Fixtures:
 *  - "defaults": an unmodified copy of index.php, so TRACK_VIEWS,
 *    ENABLE_THUMBNAIL_CACHE, and AUTO_GROUP_BY_FILENAME are all at their
 *    real off/off/off defaults. Covers the {gallery} marker requirement,
 *    explicit {group:}/{photos:} directives, positional clustering, the
 *    mistake-surfacing notices comment, confirms nothing writes to disk
 *    unless turned on, and that ?download redirects to GitHub rather
 *    than serving the file.
 *  - "optedin": TRACK_VIEWS / ENABLE_THUMBNAIL_CACHE / AUTO_GROUP_BY_FILENAME
 *    all explicitly flipped on. Covers filename auto-grouping, view
 *    tracking (including the per-photo debounce cookie), thumbnail
 *    caching + GC, and directive/security regressions that predate this
 *    file's opt-in-defaults batch.
 *  - "hiddencounts": TRACK_VIEWS on, SHOW_VIEW_COUNTS_PUBLICLY off.
 *    Confirms tracking keeps working server-side while the public badges
 *    never render.
 *  - "mtimesort": PHOTO_SORT_ORDER set to 'mtime'.
 *  - "nonotesbox": every .md rejected, so there's no notes box for the
 *    setup-notices banner to sit in — covers its other placement.
 *
 * Run with: php tests/run.php
 * Requires: the GD extension, to synthesize tiny fixture JPEGs.
 * Exits 0 if every check passes, 1 otherwise.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

if (!extension_loaded('gd')) {
    fwrite(STDERR, "This test suite needs the GD extension to generate fixture images.\n");
    exit(1);
}

$repoRoot = dirname(__DIR__);
$indexSrc = $repoRoot . '/index.php';
$indexContents = file_get_contents($indexSrc);
if (!preg_match("/define\('GALLERY_VERSION', '([^']+)'\)/", $indexContents, $vm)) {
    fwrite(STDERR, "Could not find GALLERY_VERSION in index.php\n");
    exit(1);
}
$currentVersion = $vm[1];

$failCount = 0;
$passCount = 0;
$cleanupDirs = [];
$cleanupProcs = [];

register_shutdown_function(function () use (&$cleanupProcs, &$cleanupDirs) {
    foreach ($cleanupProcs as $proc) {
        if (is_resource($proc)) {
            @proc_terminate($proc);
            @proc_close($proc);
        }
    }
    foreach ($cleanupDirs as $dir) {
        rrmdir($dir);
    }
});

/* --------------------------------------------------------------------- */

function check($description, $condition) {
    global $failCount, $passCount;
    if ($condition) {
        $passCount++;
        echo "  ok    $description\n";
    } else {
        $failCount++;
        echo "  FAIL  $description\n";
    }
}

function count_occurrences($haystack, $needle, $expected, $label) {
    $actual = substr_count($haystack, $needle);
    check("$label (expected $expected, got $actual)", $actual === $expected);
}

function rrmdir($dir) {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = "$dir/$entry";
        // is_link first: is_dir() is true for a symlink to a directory, and
        // recursing would empty the link's target (possibly outside the
        // fixture) and then fail to rmdir the link itself.
        (is_dir($path) && !is_link($path)) ? rrmdir($path) : @unlink($path);
    }
    @rmdir($dir);
}

function make_jpeg($path, $w, $h, $r, $g, $b) {
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, $r, $g, $b));
    imagejpeg($im, $path, 85);
    imagedestroy($im);
}

// A minimal but well-formed enough MP4 container to exercise
// video_duration_seconds()'s box-walking: just an ftyp box (ignored,
// skipped over by size) followed by a moov box containing a single mvhd
// (version 0) child with a real duration. No mdat/track data at all —
// not meant to actually play, only to parse.
function make_minimal_mp4($path, $durationSeconds, $timescale = 1) {
    $ftypBody = 'isom' . pack('N', 0);
    $ftyp = pack('N', 8 + strlen($ftypBody)) . 'ftyp' . $ftypBody;

    $mvhdBody = pack('N', 0)         // version (0) + flags
              . pack('N', 0)         // creation_time
              . pack('N', 0)         // modification_time
              . pack('N', $timescale)
              . pack('N', (int) round($durationSeconds * $timescale));
    $mvhd = pack('N', 8 + strlen($mvhdBody)) . 'mvhd' . $mvhdBody;

    $moov = pack('N', 8 + strlen($mvhd)) . 'moov' . $mvhd;

    file_put_contents($path, $ftyp . $moov);
}

function find_free_port() {
    $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!$sock) {
        fwrite(STDERR, "Could not find a free port: $errstr\n");
        exit(1);
    }
    $name = stream_socket_get_name($sock, false);
    fclose($sock);
    return (int) substr($name, strrpos($name, ':') + 1);
}

function start_server($docroot, $port) {
    global $cleanupProcs;
    // argv-array form (no `sh -c` in between) so proc_terminate() in the
    // shutdown handler hits php itself — with a shell wrapper it didn't,
    // and every run orphaned its servers (~35MB each, 10 per run, never
    // reaped). Output goes to /dev/null rather than an undrained pipe,
    // which would eventually fill and block the server on a log write.
    $descriptors = [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
    $cmd = [PHP_BINARY, '-S', '127.0.0.1:' . (int) $port, '-t', $docroot];
    $proc = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        fwrite(STDERR, "Could not start the PHP built-in server\n");
        exit(1);
    }
    $cleanupProcs[] = $proc;

    $deadline = microtime(true) + 5;
    while (microtime(true) < $deadline) {
        $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($conn) {
            fclose($conn);
            return $proc;
        }
        usleep(100000);
    }
    fwrite(STDERR, "Server on port $port did not start in time\n");
    exit(1);
}

/** GET request. Returns [statusCode, body, headerLines]. Pass
 *  $followRedirects = false to inspect a redirect response itself
 *  (status + Location header) instead of transparently following it. */
function http_get($url, $headers = [], $followRedirects = true) {
    $opts = ['http' => ['method' => 'GET', 'ignore_errors' => true, 'timeout' => 5]];
    if (!$followRedirects) {
        $opts['http']['follow_location'] = 0;
    }
    if ($headers) {
        $opts['http']['header'] = implode("\r\n", $headers);
    }
    $context = stream_context_create($opts);
    $body = @file_get_contents($url, false, $context);
    $status = 0;
    $responseHeaders = $http_response_header ?? [];
    foreach ($responseHeaders as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) {
            $status = (int) $m[1];
        }
    }
    return [$status, $body === false ? '' : $body, $responseHeaders];
}

/** POST request with a form-urlencoded body. Returns [statusCode, body,
 *  headerLines], same shape as http_get(). */
function http_post($url, $postData, $headers = [], $followRedirects = true) {
    $opts = ['http' => [
        'method' => 'POST',
        'header' => implode("\r\n", array_merge(['Content-Type: application/x-www-form-urlencoded'], $headers)),
        'content' => http_build_query($postData),
        'ignore_errors' => true,
        'timeout' => 5,
    ]];
    if (!$followRedirects) {
        $opts['http']['follow_location'] = 0;
    }
    $context = stream_context_create($opts);
    $body = @file_get_contents($url, false, $context);
    $status = 0;
    $responseHeaders = $http_response_header ?? [];
    foreach ($responseHeaders as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) {
            $status = (int) $m[1];
        }
    }
    return [$status, $body === false ? '' : $body, $responseHeaders];
}

/** Pulls the first Set-Cookie response header's name=value pair (ignoring
 *  path/expiry attributes) out, for resending as a plain Cookie: header
 *  on a follow-up request — enough to carry a PHP session across the
 *  admin tool's CSRF-protected GET-then-POST flow. */
/** Collects every Set-Cookie response header's name=value pair (ignoring
 *  path/expiry attributes) into one semicolon-joined string, suitable for
 *  resending as a single Cookie: header — a response can set more than
 *  one cookie at once (e.g. a PHP session cookie *and* the reveal
 *  cookie, from the same admin login), and a real browser would carry
 *  all of them forward together. Returns null only if there were none. */
function extract_cookie_header($responseHeaders) {
    $pairs = [];
    foreach ($responseHeaders as $h) {
        if (stripos($h, 'Set-Cookie:') === 0) {
            $value = trim(substr($h, strlen('Set-Cookie:')));
            [$pair] = explode(';', $value, 2);
            $pairs[] = $pair;
        }
    }
    return $pairs ? implode('; ', $pairs) : null;
}

/** Pulls the csrf_token hidden input's value out of a rendered admin page. */
function extract_csrf_token($body) {
    return preg_match('/csrf_token" value="([a-f0-9]+)"/', $body, $m) ? $m[1] : null;
}

/** Writes a copy of index.php with one or more constants patched. */
function write_patched_index($destPath, $indexContents, $patches) {
    $content = $indexContents;
    foreach ($patches as $const => $value) {
        $pattern = "/define\('" . preg_quote($const, '/') . "',\s*[^)]+\)/";
        $replacement = "define('$const', " . $value . ')';
        $new = preg_replace($pattern, $replacement, $content, 1, $count);
        if ($count !== 1) {
            fwrite(STDERR, "Could not patch constant $const in index.php copy\n");
            exit(1);
        }
        $content = $new;
    }
    file_put_contents($destPath, $content);
}

function new_fixture_dir($label) {
    global $cleanupDirs;
    $dir = sys_get_temp_dir() . '/gallery-tests-' . $label . '-' . bin2hex(random_bytes(4));
    mkdir($dir, 0775, true);
    $cleanupDirs[] = $dir;
    return $dir;
}

/* ======================================================================= *
 * FIXTURE 1: "defaults" — an unmodified index.php copy.
 * ======================================================================= */

echo "== defaults fixture: {gallery} marker, explicit groups, opt-in checks ==\n";

$fx1 = new_fixture_dir('defaults');
copy($indexSrc, "$fx1/index.php");

$images1 = [
    'dp104_01.jpg', 'dp104_02.jpg',      // should NOT auto-merge (AUTO_GROUP_BY_FILENAME off)
    'widget_01.jpg', 'widget_02.jpg',    // claimed by an explicit named group
    'lonely.jpg',                        // explicit single-photo named group (still boxed)
    'gizmo.jpg',                         // {sold}+{reserved} precedence, no group
    'scatter-a.jpg', 'scatter-z.jpg',    // positional cluster, no group, with text
    'quiet-a.jpg', 'quiet-b.jpg',        // positional cluster, no group, NO text
    'dup.jpg',                           // referenced by two different {photos:} directives
    'xss.jpg',                           // plain single, paired with an XSS-attempt paragraph
];
foreach ($images1 as $i => $name) {
    make_jpeg("$fx1/$name", 200, 150, 10 + $i * 15, 40, 90);
}
// Regression: two unrelated files sharing a base name but differing
// only in extension must not collide onto the same fallback key when
// AUTO_GROUP_BY_FILENAME is off (group_key() must key by the full
// filename, not the extension-stripped base).
make_jpeg("$fx1/collide.jpg", 200, 150, 200, 30, 30);
make_jpeg("$fx1/collide.png", 200, 150, 30, 200, 30);
// Regression: fixing the collision above by keying standalone singles
// on their full filename broke classic mention-based auto-linking (a
// paragraph mentioning "zebra" could no longer match "zebra.jpg", since
// the key became "zebra.jpg" and prose never includes the extension).
// This file's mention must still auto-link and reorder it ahead of
// every unmentioned file below.
make_jpeg("$fx1/zebra.jpg", 200, 150, 90, 60, 10);

// Regression: a textless box, defined at the very END of the .md, must
// still render in the boxes zone ahead of every single — including one
// mentioned early in the file (zebra.jpg above) — since boxes always
// come first regardless of where their directive sits.
make_jpeg("$fx1/late-box-a.jpg", 200, 150, 5, 5, 5);
make_jpeg("$fx1/late-box-b.jpg", 200, 150, 6, 6, 6);

// Regression: an anchor file listed first in {photos:} decides where a
// textless cluster sits (its own natural rank), and the other members
// follow in the exact order listed — never re-sorted to their own
// natural order. zzz3 sorts last alphabetically among these three, so
// listing it first should pull zzz1/zzz2 forward to sit right after it,
// landing the whole block near the end of natural order (zzz3's spot),
// not near the start (zzz1's spot).
make_jpeg("$fx1/zzz1.jpg", 200, 150, 7, 7, 7);
make_jpeg("$fx1/zzz2.jpg", 200, 150, 8, 8, 8);
make_jpeg("$fx1/zzz3.jpg", 200, 150, 9, 9, 9);

// Regression: mention-matching must search prose only. "aa-friend.jpg"
// referenced inside another paragraph's bare {photos:} list contains
// "aa" followed by a hyphen — a valid word boundary — so without
// masking, that {photos:} directive's own raw text would falsely match
// as if "aa" (aa.jpg's key) were mentioned in real prose.
make_jpeg("$fx1/aa.jpg", 200, 150, 11, 11, 11);
make_jpeg("$fx1/aa-friend.jpg", 200, 150, 12, 12, 12);

// Regression: a description followed by a blank line, then a directive-
// only paragraph the author probably meant to attach to it, should be
// flagged as a likely-accidental disconnect (this exact pattern is what
// caused a real, reported ordering surprise).
make_jpeg("$fx1/adjacent-a.jpg", 200, 150, 13, 13, 13);
make_jpeg("$fx1/adjacent-b.jpg", 200, 150, 14, 14, 14);

$notes1 = <<<'MD'
{gallery}
# Defaults Fixture

Intro line before any code.

```
brace test { unmatched
```

Contact line stays intact after the code block. }

(0) ZEBRA is mentioned here with no directive at all — classic auto-linking.

(1) DP104 pair should stay separate cards, no auto-grouping. {color: gold}

(2) This is buy+model, not a real mention.

(3) WIDGET explicit group with a name. {group: Widget Set} {photos: widget_01.jpg, widget_02.jpg} {sold}

(4) LONELY explicit single, still boxed. {group: Solo Box} {photos: lonely.jpg}

{group: Textless Box} {photos: dup.jpg} {sold} {color: gold}

(6) GIZMO dual status, no group. {photos: gizmo.jpg} {sold} {reserved}

(7) Scattered pair with a caption. {photos: scatter-a.jpg, scatter-z.jpg}

(8) Bare cluster below has no caption.

{photos: quiet-a.jpg, quiet-b.jpg}

(9) Duplicate claim attempt. {photos: dup.jpg}

(10) Raw <script>alert(1)</script> attempt.

{photos: aa-friend.jpg}

{group: Late Box} {photos: late-box-a.jpg, late-box-b.jpg}

{photos: zzz3.jpg, zzz1.jpg, zzz2.jpg}

A plain description of the adjacent set.

{group: Adjacent Set} {photos: adjacent-a.jpg, adjacent-b.jpg}
MD;
file_put_contents("$fx1/gallery.md", $notes1);

$unrelated1 = "# Not for the gallery\nJust my own unrelated notes.\n";
file_put_contents("$fx1/unrelated.md", $unrelated1);

$port1 = find_free_port();
start_server($fx1, $port1);
$base1 = "http://127.0.0.1:$port1";

[$status, $body] = http_get("$base1/");
check('GET / returns 200', $status === 200);
check('page title from the {gallery}-marked file\'s heading', str_contains($body, '<title>Defaults Fixture — Gallery</title>'));
check('"Last updated" shows on a plain flat (non-nested) gallery too', (bool) preg_match('/Last updated [A-Z][a-z]+ \d{1,2}, \d{4}/', $body));
check('{gallery} marker line itself never shows as rendered .md content', !str_contains($body, '<p>{gallery}</p>') && !preg_match('#<div class="note-box">.*?\{gallery\}.*?</div>#s', $body));

check(
    'unrelated.md (no {gallery} marker) is ignored and noted, generically (count-based, no filename)',
    str_contains($body, 'A .md file was found but ignored because its first line')
);
check(
    'the rejected .md\'s filename is never revealed anywhere on the page (privacy: it wasn\'t opted into the gallery)',
    !str_contains($body, 'unrelated.md')
);
check(
    'duplicate {photos:} claim is rejected and noted',
    str_contains($body, '&quot;dup.jpg&quot; is referenced in more than one {photos:} directive')
);
check(
    'a directive right after a plain, unclaimed description is flagged as a likely-accidental disconnect',
    str_contains($body, 'attached to it')
);
check(
    'notices are surfaced on the page itself, not just in the HTML-comment fallback',
    (bool) preg_match('/<details class="gallery-notices"><summary>&#9888; \d+ setup notices? for the gallery owner<\/summary>/', $body)
);
check(
    '...and the on-page banner sits inside the notes box when one exists',
    (bool) preg_match('#<div class="notes">\s*<details class="gallery-notices">#', $body)
);

check(
    'AUTO_GROUP_BY_FILENAME is off by default: dp104_01/02 do not merge into a box',
    !str_contains($body, 'id="group-dp104"')
);
count_occurrences($body, 'alt="dp104_01.jpg"', 1, 'dp104_01.jpg appears once, as a plain standalone card');

check(
    'collide.jpg and collide.png (same base, different extension) do not merge into a box',
    !str_contains($body, 'id="group-collide"')
);
count_occurrences($body, 'alt="collide.jpg"', 1, 'collide.jpg appears once, as its own standalone card');
count_occurrences($body, 'alt="collide.png"', 1, 'collide.png appears once, as its own standalone card');

check(
    'a standalone single (no directive) is still classic-auto-linked by mention, keyed by its extension-stripped name',
    (bool) preg_match('/id="item-zebra"/', $body)
);
check(
    'the note box\'s "View 1 photo" jump link for a mentioned single has a real target (regression: only boxes used to get this id, leaving the link dead)',
    str_contains($body, 'href="#group-zebra"') && str_contains($body, 'id="group-zebra"')
);
preg_match_all('/onclick="openLightbox\((\d+)\)"><img src="\?img=([^&]+)&/', $body, $orderMatches);
$displayOrder = array_combine($orderMatches[2], array_map('intval', $orderMatches[1]));
check(
    'the mentioned zebra.jpg sorts ahead of an unmentioned single within the singles zone (mention-order, not group-vs-single)',
    isset($displayOrder['zebra.jpg'], $displayOrder['xss.jpg']) && $displayOrder['zebra.jpg'] < $displayOrder['xss.jpg']
);

check(
    'a textless box defined at the end of the .md still renders in the boxes zone, ahead of every single',
    str_contains($body, 'id="group-late-box"')
        && isset($displayOrder['late-box-a.jpg'], $displayOrder['zebra.jpg'])
        && $displayOrder['late-box-a.jpg'] < $displayOrder['zebra.jpg']
);

check(
    'a textless cluster anchors on its first-listed file\'s natural rank, not its lowest-sorting member\'s',
    isset($displayOrder['zzz3.jpg']) && $displayOrder['zzz3.jpg'] > $displayOrder['zebra.jpg']
);
check(
    'the cluster\'s other members follow in the exact order listed, not re-sorted',
    isset($displayOrder['zzz3.jpg'], $displayOrder['zzz1.jpg'], $displayOrder['zzz2.jpg'])
        && $displayOrder['zzz3.jpg'] + 1 === $displayOrder['zzz1.jpg']
        && $displayOrder['zzz1.jpg'] + 1 === $displayOrder['zzz2.jpg']
);

check(
    'mention-matching searches prose only: "aa" inside another paragraph\'s {photos: aa-friend.jpg} is not a false mention of aa.jpg',
    !str_contains($body, 'id="item-aa"')
);

check(
    '"buy+model" does not falsely match the "+model"-style token (word-boundary regression)',
    str_contains($body, '<p>(2) This is buy+model, not a real mention.</p>')
);

check('explicit {group:}+{photos:} creates a labeled box', str_contains($body, 'id="group-widget-set"'));
check('explicit group box uses the {group:} name as its label', str_contains($body, '>Widget Set <span'));
check(
    'a gallery group box has its own one-click copy-link button, same as a .md item',
    str_contains($body, '<a class="permalink-copy" href="#group-widget-set" title="Copy link">&#128279;</a>')
);
check('explicit group inherits {sold} status', str_contains($body, '<div class="group-box sold"'));

check(
    'a single-photo explicit group still gets boxed (unlike an auto-detected single)',
    str_contains($body, 'id="group-solo-box"')
);
check('explicit single-photo box label is correct', str_contains($body, '>Solo Box <span'));

count_occurrences($body, 'Textless Box', 1, '{group:}+{photos:} with no other text: the name appears only once (the gallery box label), nothing in the notes box');
check('...but its box still exists in the main gallery', str_contains($body, 'id="group-textless-box"'));
check(
    '...and {sold}/{color:} on that same textless paragraph still apply to the box (regression: these used to only apply when a matchedKeys loop ran, which a textless paragraph skips)',
    str_contains($body, '<div class="group-box sold" style="border-left-color: var(--sold);" id="group-textless-box">')
);

count_occurrences($body, '<span class="sold-badge-corner">SOLD</span>', 1, 'GIZMO ({sold}+{reserved}, no group) renders sold, not reserved (precedence)');
check('GIZMO never got a reserved badge', !str_contains($body, '<span class="reserved-badge-corner">RESERVED</span>'));

check(
    'bare {photos:}+text (no {group:}) gets a thumbnail strip with a permalink, no jump link',
    (bool) preg_match('/id="item-scatter-a-jpg"/', $body) && str_contains($body, 'linked-photos plain')
);
check('...but no aggregate view-count span for that cluster', !str_contains($body, 'data-group="scatter-a"'));
$idxA = null; $idxZ = null;
if (preg_match('/openLightbox\((\d+)\)"><img src="\?img=scatter-a\.jpg/', $body)) {
    preg_match('/alt="scatter-a\.jpg"/', $body, $mm, PREG_OFFSET_CAPTURE);
}
// Positional clustering: scatter-a.jpg and scatter-z.jpg must be adjacent
// in the flat lightbox index order, despite not being filename-adjacent.
preg_match_all('/onclick="openLightbox\((\d+)\)"><img src="\?img=([^&]+)&/', $body, $allCards);
$order = array_combine($allCards[2], array_map('intval', $allCards[1]));
check(
    'scatter-a.jpg and scatter-z.jpg are positioned adjacently (positional clustering)',
    isset($order['scatter-a.jpg'], $order['scatter-z.jpg']) && abs($order['scatter-a.jpg'] - $order['scatter-z.jpg']) === 1
);

check(
    'bare {photos:} with no text renders nothing in the notes box',
    !preg_match('/id="item-quiet-a"/', $body)
);
check(
    'quiet-a.jpg and quiet-b.jpg are still positioned adjacently despite no text',
    isset($order['quiet-a.jpg'], $order['quiet-b.jpg']) && abs($order['quiet-a.jpg'] - $order['quiet-b.jpg']) === 1
);

check(
    'the losing duplicate {photos:} claim does not also render (no double thumbnail strip)',
    !preg_match('/id="item-dup"/', $body)
);
count_occurrences($body, 'alt="dup.jpg"', 1, 'dup.jpg appears exactly once (claimed only by its first, winning directive)');

check('raw HTML in a .md file is escaped', str_contains($body, '&lt;script&gt;alert(1)&lt;/script&gt;'));
check('raw HTML in a .md file is never executed unescaped', !str_contains($body, '<script>alert(1)</script>'));

check(
    'OG description keeps text after a code block intact (brace-stripping order regression)',
    (bool) preg_match('/<meta name="description" content="([^"]*)"/', $body, $dm)
        && str_contains($dm[1], 'Contact line stays intact')
);

echo "== defaults fixture: opt-in checks (nothing written without permission) ==\n";
[$status, $body] = http_get("$base1/?track=widget_01.jpg");
$json = json_decode($body, true);
check('?track reports ok:false when TRACK_VIEWS is off (default)', is_array($json) && $json['ok'] === false);
check('no JSON stats file created by default', !file_exists("$fx1/.gallery-stats.json"));
check('no SQLite stats file created by default', !file_exists("$fx1/.gallery-stats.sqlite"));

[$status, , $headers] = http_get("$base1/?img=widget_01.jpg&size=thumb");
check('?img=<file>&size=thumb still returns 200 with caching off', $status === 200);
check('no .gallery-cache directory created by default', !is_dir("$fx1/.gallery-cache"));
$origBytes = filesize("$fx1/widget_01.jpg");
[, $thumbBody] = http_get("$base1/?img=widget_01.jpg&size=thumb");
check('with caching off, "thumb" is served at full original size (no resizing)', strlen($thumbBody) === $origBytes);

[$status] = http_get("$base1/?img=" . '../../../../etc/passwd' . "&size=thumb");
check('?img path traversal is blocked (basename + allow-list)', $status === 404);
[$status] = http_get("$base1/?img=does-not-exist.jpg&size=thumb");
check('?img=<missing file> returns 404', $status === 404);
[, $body2] = http_get("$base1/?track=" . '../../../etc/passwd');
$json2 = json_decode($body2, true);
check('?track path traversal is rejected (ok:false)', is_array($json2) && $json2['ok'] === false);

[$status, $body] = http_get("$base1/?about");
check('GET /?about returns 200', $status === 200);
check('?about links to the GitHub repo', str_contains($body, 'github.com/pcmike/gallery'));
check('?about shows the current version', str_contains($body, 'v' . $currentVersion));

[$status, $body, $dlHeaders] = http_get("$base1/?download", [], false);
check('?download redirects (302), does not serve the file', $status === 302);
check('?download body is empty, not the file contents', $body === '');
$locationHeader = '';
foreach ($dlHeaders as $h) {
    if (stripos($h, 'Location:') === 0) {
        $locationHeader = trim(substr($h, strlen('Location:')));
    }
}
check('?download redirects to the GitHub repo', $locationHeader === 'https://github.com/pcmike/gallery');

/* ======================================================================= *
 * FIXTURE 2: "optedin" — TRACK_VIEWS / ENABLE_THUMBNAIL_CACHE /
 * AUTO_GROUP_BY_FILENAME all explicitly turned on.
 * ======================================================================= */

echo "== optedin fixture: auto-grouping, view tracking, thumbnail cache ==\n";

$fx2 = new_fixture_dir('optedin');
write_patched_index("$fx2/index.php", $indexContents, [
    'TRACK_VIEWS' => 'true',
    'ENABLE_THUMBNAIL_CACHE' => 'true',
    'AUTO_GROUP_BY_FILENAME' => 'true',
]);

$images2 = [
    'dp104_01.jpg', 'dp104_02.jpg',
    'iphone15.jpg', 'iphone-15.jpg',
    'gc-orphan.jpg',
];
foreach ($images2 as $i => $name) {
    make_jpeg("$fx2/$name", 200, 150, 20 + $i * 20, 60, 120);
}
$notes2 = <<<'MD'
{gallery}
(1) The IPHONE15 model works too, unlike the IPHONE case which cracked.
MD;
file_put_contents("$fx2/notes.md", $notes2);

$port2 = find_free_port();
start_server($fx2, $port2);
$base2 = "http://127.0.0.1:$port2";

[$status, $body] = http_get("$base2/");
check('GET / returns 200 (optedin)', $status === 200);
check('AUTO_GROUP_BY_FILENAME=true merges dp104_01/02 into a box', str_contains($body, 'id="group-dp104"'));
count_occurrences($body, 'alt="iphone-15.jpg"', 2, 'iphone-15.jpg (group "iphone") matched once by mention, once by grid');
count_occurrences($body, 'alt="iphone15.jpg"', 2, 'iphone15.jpg (distinct group "iphone15") matched once by mention, once by grid');

[$status, $body, $trackHeaders] = http_get("$base2/?track=dp104_01.jpg");
$json = json_decode($body, true);
check('?track=<valid file> returns 200 (optedin)', $status === 200);
check('?track reports ok:true with a view count when TRACK_VIEWS is on', is_array($json) && $json['ok'] === true && $json['photoViews'] >= 1);

[, $body] = http_get("$base2/?track=dp104_01.jpg", ['User-Agent: facebookexternalhit/1.1']);
$json2 = json_decode($body, true);
check('?track from a known preview bot is excluded (ok:false)', is_array($json2) && $json2['ok'] === false);

// Debounce: a second ?track for the same photo, same browser (cookie
// forwarded), within the window should not increment the count again —
// but should still report ok:true with the current total, not an error.
$trackCookie = '';
foreach ($trackHeaders as $h) {
    if (stripos($h, 'Set-Cookie:') === 0 && str_contains($h, 'gallery_viewed=')) {
        $trackCookie = trim(explode(';', substr($h, strlen('Set-Cookie:')))[0]);
    }
}
check('?track response sets the gallery_viewed debounce cookie', $trackCookie !== '');
$firstViews = $json['photoViews'];
[, $debouncedBody] = http_get("$base2/?track=dp104_01.jpg", ["Cookie: $trackCookie"]);
$debouncedJson = json_decode($debouncedBody, true);
check('debounced ?track still reports ok:true', is_array($debouncedJson) && $debouncedJson['ok'] === true);
check('debounced ?track does not increment the count', $debouncedJson['photoViews'] === $firstViews);

// Debounce is per-photo, not a blanket "this browser already viewed
// something" — a different photo, same cookie, should still count.
[, $otherBody] = http_get("$base2/?track=dp104_02.jpg", ["Cookie: $trackCookie"]);
$otherJson = json_decode($otherBody, true);
check('debounce is per-photo: a different photo with the same cookie still counts', is_array($otherJson) && $otherJson['ok'] === true && $otherJson['photoViews'] >= 1);

[$status, , $headers] = http_get("$base2/?img=dp104_01.jpg&size=thumb");
check('?img=<valid file> returns 200 (optedin)', $status === 200);
check('?img serves image/jpeg', (bool) array_filter($headers, fn($h) => stripos($h, 'Content-Type: image/jpeg') === 0));
check('?img=<valid file> creates a cache entry when caching is on', is_dir("$fx2/.gallery-cache") && count(glob("$fx2/.gallery-cache/*.jpg")) > 0);

$orphanMtime = filemtime("$fx2/gc-orphan.jpg");
$orphanThumbHash = md5('gc-orphan.jpg|thumb|' . $orphanMtime) . '.jpg';
http_get("$base2/?img=gc-orphan.jpg&size=thumb");
check('gc-orphan.jpg thumbnail was cached', file_exists("$fx2/.gallery-cache/$orphanThumbHash"));
$dp104Mtime = filemtime("$fx2/dp104_01.jpg");
$dp104ThumbHash = md5('dp104_01.jpg|thumb|' . $dp104Mtime) . '.jpg';
check('dp104_01.jpg thumbnail was cached (sanity check before GC)', file_exists("$fx2/.gallery-cache/$dp104ThumbHash"));
unlink("$fx2/gc-orphan.jpg");
http_get("$base2/"); // normal page load triggers gc_image_cache()
check('orphaned cache entry removed after its source photo is deleted', !file_exists("$fx2/.gallery-cache/$orphanThumbHash"));
check('cache entries for surviving photos are left alone', file_exists("$fx2/.gallery-cache/$dp104ThumbHash"));

/* ======================================================================= *
 * FIXTURE: "hiddencounts" — TRACK_VIEWS on, SHOW_VIEW_COUNTS_PUBLICLY off.
 * Tracking keeps working server-side; the public badges never appear.
 * ======================================================================= */

echo "== hiddencounts fixture: tracking stays on, public badges stay off ==\n";

$fxHidden = new_fixture_dir('hiddencounts');
write_patched_index("$fxHidden/index.php", $indexContents, [
    'TRACK_VIEWS' => 'true',
    'SHOW_VIEW_COUNTS_PUBLICLY' => 'false',
]);
make_jpeg("$fxHidden/solo.jpg", 200, 150, 40, 80, 160);

$portHidden = find_free_port();
start_server($fxHidden, $portHidden);
$baseHidden = "http://127.0.0.1:$portHidden";

[, $hiddenBody] = http_get("$baseHidden/?track=solo.jpg");
$hiddenJson = json_decode($hiddenBody, true);
check('tracking still counts with SHOW_VIEW_COUNTS_PUBLICLY off', is_array($hiddenJson) && $hiddenJson['ok'] === true && $hiddenJson['photoViews'] >= 1);
check('a stats file is still written with SHOW_VIEW_COUNTS_PUBLICLY off', is_file("$fxHidden/.gallery-stats.json") || is_file("$fxHidden/.gallery-stats.sqlite"));

[, $pageBody] = http_get("$baseHidden/");
check('view-badge stays hidden even though its count is > 0', (bool) preg_match('/class="view-badge hidden"[^>]*data-photo="solo\.jpg"/', $pageBody));
check('showCountsPublicly is false in the page source', str_contains($pageBody, 'const showCountsPublicly = false;'));

/* ======================================================================= *
 * FIXTURE: "nested" — AUTO_GROUP_BY_FOLDER on, with AUTO_GROUP_BY_FILENAME
 * and ENABLE_THUMBNAIL_CACHE also on to exercise precedence and per-folder
 * colocation. Includes an EXTRA_FOLDERS virtual mount and a symlink
 * planted specifically to confirm it's refused.
 * ======================================================================= */

echo "== nested fixture: folder grouping, precedence, EXTRA_FOLDERS, symlink safety ==\n";

$fxNested = new_fixture_dir('nested');
$archiveDir = new_fixture_dir('nested-archive'); // a real dir outside the gallery root
mkdir("$fxNested/Paris");
mkdir("$fxNested/StTropez");
mkdir("$fxNested/outside-target"); // a real subfolder a symlink will point at
make_jpeg("$fxNested/Paris/eiffel.jpg", 60, 60, 10, 10, 10);
make_jpeg("$fxNested/Paris/dp104_01.jpg", 60, 60, 20, 20, 20);
make_jpeg("$fxNested/StTropez/beach.jpg", 60, 60, 30, 30, 30);
make_jpeg("$fxNested/StTropez/dp104_02.jpg", 60, 60, 40, 40, 40);
make_jpeg("$fxNested/loose.jpg", 60, 60, 50, 50, 50);
make_jpeg("$fxNested/outside-target/secret.jpg", 60, 60, 60, 60, 60);
make_jpeg("$archiveDir/archived.jpg", 60, 60, 70, 70, 70);
symlink("$fxNested/outside-target", "$fxNested/SneakyLink");

write_patched_index("$fxNested/index.php", $indexContents, [
    'AUTO_GROUP_BY_FOLDER' => 'true',
    'AUTO_GROUP_BY_FILENAME' => 'true',
    'ENABLE_THUMBNAIL_CACHE' => 'true',
    'TRACK_VIEWS' => 'true',
    'EXTRA_FOLDERS' => "['Archive' => " . var_export($archiveDir, true) . "]",
]);

$portNested = find_free_port();
start_server($fxNested, $portNested);
$baseNested = "http://127.0.0.1:$portNested";

[, $nestedBody] = http_get("$baseNested/");
check('folder-group box exists for Paris', str_contains($nestedBody, 'id="group-paris"'));
check('Paris renders as an overview cover card (default FOLDER_DISPLAY_MODE)', (bool) preg_match('/<div class="folder-cover" id="group-paris">/', $nestedBody));
check('Paris\'s cover card shows its recursive photo count (2 photos: eiffel + dp104_01)', (bool) preg_match('/id="group-paris">.*?2 photos/s', $nestedBody));
check('Paris\'s cover card links to ?folder=Paris', str_contains($nestedBody, 'href="?folder=Paris"'));
check('Paris\'s cover card has a copy-link button distinct from the navigation link', (bool) preg_match('/class="permalink-copy folder-permalink-copy" href="\?folder=Paris"/', $nestedBody));
check('folder-group box exists for StTropez', str_contains($nestedBody, 'id="group-sttropez"'));
check('folder-group box exists for the EXTRA_FOLDERS virtual mount Archive', str_contains($nestedBody, 'id="group-archive"'));
check('a loose root-level file still gets its own card', str_contains($nestedBody, 'id="group-loose"'));
check('the symlink is refused — no group for SneakyLink or outside-target leaking through it', !str_contains($nestedBody, 'sneakylink'));

check('folder membership beats AUTO_GROUP_BY_FILENAME: dp104_01 (Paris) and dp104_02 (StTropez) do not merge', !str_contains($nestedBody, 'id="group-dp104"'));

[$status] = http_get("$baseNested/?img=" . rawurlencode('Paris/eiffel.jpg') . '&size=thumb');
check('?img serves a file inside a real subfolder', $status === 200);
[$status] = http_get("$baseNested/?img=" . rawurlencode('Archive/archived.jpg') . '&size=thumb');
check('?img serves a file inside an EXTRA_FOLDERS virtual mount', $status === 200);
[$status] = http_get("$baseNested/?img=" . rawurlencode('SneakyLink/secret.jpg') . '&size=thumb');
check('?img refuses a file reached only through a symlink', $status === 404);

http_get("$baseNested/?track=" . rawurlencode('Paris/eiffel.jpg'));
check('per-photo stats are colocated inside the photo\'s own subfolder, not the root', is_file("$fxNested/Paris/.gallery-stats.json") || is_file("$fxNested/Paris/.gallery-stats.sqlite"));
// The root legitimately gets its own stats file regardless — the one
// sitewide pageview counter always lives there by design (every GET /
// above already bumped it). What must NOT be true is the root's store
// *also* holding an entry for a subfolder photo's own view count.
$rootHasEiffelEntry = false;
if (is_file("$fxNested/.gallery-stats.json")) {
    $rootData = json_decode((string) file_get_contents("$fxNested/.gallery-stats.json"), true);
    $rootHasEiffelEntry = isset($rootData['photos']['Paris/eiffel.jpg']);
} elseif (is_file("$fxNested/.gallery-stats.sqlite")) {
    $rootPdo = new PDO('sqlite:' . "$fxNested/.gallery-stats.sqlite");
    $stmt = $rootPdo->prepare('SELECT views FROM photo_views WHERE filename = :f');
    $stmt->execute([':f' => 'Paris/eiffel.jpg']);
    $rootHasEiffelEntry = $stmt->fetchColumn() !== false;
}
check('the gallery root\'s own stats store has no entry for a subfolder photo', !$rootHasEiffelEntry);

http_get("$baseNested/?img=" . rawurlencode('Paris/eiffel.jpg') . '&size=thumb');
check('thumbnail cache is colocated inside the photo\'s own subfolder, not the root', is_dir("$fxNested/Paris/.gallery-cache") && count(glob("$fxNested/Paris/.gallery-cache/*.jpg")) > 0);
check('the gallery root does NOT get a .gallery-cache for a subfolder photo', !is_dir("$fxNested/.gallery-cache") || count(glob("$fxNested/.gallery-cache/*.jpg")) === 0);

// ?folder=<name> drills into one folder-group's own scoped page.
$rootTitle = basename($fxNested);
[$folderStatus, $folderBody] = http_get("$baseNested/?folder=Paris");
check('?folder=Paris returns 200', $folderStatus === 200);
check('?folder=Paris shows its own files', str_contains($folderBody, 'eiffel.jpg') && str_contains($folderBody, 'dp104_01.jpg'));
check('?folder=Paris does not show other folders\' files', !str_contains($folderBody, 'beach.jpg') && !str_contains($folderBody, 'dp104_02.jpg'));
check('?folder=Paris does not show the loose root-level file', !str_contains($folderBody, 'loose.jpg'));
check('?folder=Paris sets a "Folder — Root" page title', str_contains($folderBody, "<title>Paris — $rootTitle — Gallery</title>"));
check('?folder=Paris sets a matching "Folder — Root" <h1>, not just <title>', str_contains($folderBody, "<h1>Paris — $rootTitle</h1>"));
check('?folder=Paris shows its own photo count (2), not the whole gallery\'s', (bool) preg_match('/<p>2 photos\b/', $folderBody));
check('every page shows a "Last updated" date derived from file mtimes', (bool) preg_match('/Last updated [A-Z][a-z]+ \d{1,2}, \d{4}/', $nestedBody));
check('?folder=Paris also shows the same "Last updated" line', (bool) preg_match('/Last updated [A-Z][a-z]+ \d{1,2}, \d{4}/', $folderBody));
check('?folder=Paris shows a breadcrumb back-link to the root', (bool) preg_match('/<p class="breadcrumb"><a href="\.\/">.*?' . preg_quote($rootTitle, '/') . '.*?<\/a><\/p>/', $folderBody));
check('?folder=Paris does not render its own nested folder-cover cards', !str_contains($folderBody, '<div class="folder-cover"'));
check('?folder=Paris reflects the folder in its Open Graph title', str_contains($folderBody, 'property="og:title" content="Paris — ' . $rootTitle . '"'));

[$badFolderStatus] = http_get("$baseNested/?folder=" . rawurlencode('../outside-target'));
check('?folder= with a path-traversal attempt 404s', $badFolderStatus === 404);
[$unknownFolderStatus] = http_get("$baseNested/?folder=NoSuchFolder");
check('?folder= with an unknown folder name 404s', $unknownFolderStatus === 404);
[$rootAsFolderStatus] = http_get("$baseNested/?folder=loose.jpg");
check('?folder= naming a non-folder file 404s', $rootAsFolderStatus === 404);

// A subfolder may optionally carry its own {gallery}-marked .md, scoped
// to just that subfolder's own photos.
file_put_contents("$fxNested/Paris/notes.md", "{gallery}\nThe eiffel photo turned out great. {color: gold}\n");
file_put_contents("$fxNested/Paris/unmarked.md", "Just some personal notes, not gallery content.\n");
[, $subfolderNotesBody] = http_get("$baseNested/?folder=Paris");
check('a subfolder\'s own notes.md renders as its own note box', (bool) preg_match('#<div class="note-box">\s*<span class="note-filename">notes\.md</span>#', $subfolderNotesBody));
check('the subfolder note\'s mention+{color:} directive applies to the matching card, anchors aligned', (bool) preg_match('#<div class="card" id="group-eiffel" style="box-shadow: 0 0 0 3px gold inset;"#', $subfolderNotesBody) && str_contains($subfolderNotesBody, 'href="#group-eiffel"'));
check('the subfolder note\'s thumbnail strip links to the real full-path image, not a bare filename', str_contains($subfolderNotesBody, 'src="?img=Paris%2Feiffel.jpg&amp;size=thumb"'));
check('an unmarked .md in the subfolder is rejected generically, filename never revealed', str_contains($subfolderNotesBody, 'A .md file was found in this folder but ignored') && !str_contains($subfolderNotesBody, 'unmarked.md'));
[, $subfolderOgBody] = http_get("$baseNested/?folder=Paris");
check('?folder= Open Graph description comes from the subfolder\'s own notes when it has one', str_contains($subfolderOgBody, 'property="og:description" content="The eiffel photo turned out great."'));
unlink("$fxNested/Paris/notes.md");
unlink("$fxNested/Paris/unmarked.md");

/* ======================================================================= *
 * FIXTURE: "video" — ENABLE_VIDEO on. Covers recognition, the generic
 * placeholder thumbnail, the duration badge (and graceful degradation
 * when a "video" doesn't parse as one), and the ?video= Range-request
 * serving endpoint (full GET, a ranged GET, a suffix range, past-EOF, and
 * a malformed Range header).
 * ======================================================================= */

echo "== video fixture: ENABLE_VIDEO, placeholder thumbnail, duration, Range requests ==\n";

$fxVideo = new_fixture_dir('video');
make_jpeg("$fxVideo/photo.jpg", 60, 60, 80, 80, 80);
make_minimal_mp4("$fxVideo/clip.mp4", 65, 1); // 65 seconds -> "1:05"
file_put_contents("$fxVideo/broken.mov", 'not actually a video container');

write_patched_index("$fxVideo/index.php", $indexContents, [
    'ENABLE_VIDEO' => 'true',
]);

$portVideo = find_free_port();
start_server($fxVideo, $portVideo);
$baseVideo = "http://127.0.0.1:$portVideo";

[$videoPageStatus, $videoPageBody] = http_get("$baseVideo/");
check('a video file is recognized and shown in the grid when ENABLE_VIDEO is on', $videoPageStatus === 200 && str_contains($videoPageBody, 'clip.mp4'));
check('the video card uses the normal ?img= thumbnail URL (served as a placeholder, not a broken image)', str_contains($videoPageBody, 'src="?img=clip.mp4&amp;size=thumb"'));
check('a video with a readable duration shows a duration badge', (bool) preg_match('#<span class="video-duration">1:05</span>#', $videoPageBody));
$brokenCardStart = strpos($videoPageBody, 'alt="broken.mov"');
$brokenCardEnd = $brokenCardStart === false ? false : strpos($videoPageBody, '</div>', $brokenCardStart);
$brokenCardHtml = ($brokenCardStart !== false && $brokenCardEnd !== false) ? substr($videoPageBody, $brokenCardStart, $brokenCardEnd - $brokenCardStart) : '';
check('a "video" that doesn\'t parse as a real container still renders, with no duration badge', $brokenCardStart !== false && !str_contains($brokenCardHtml, 'video-duration'));

[$placeholderStatus, , $placeholderHeaders] = http_get("$baseVideo/?img=" . rawurlencode('clip.mp4') . '&size=thumb');
check('?img= on a video file returns 200', $placeholderStatus === 200);
check('?img= on a video file serves image/jpeg (the generic placeholder, not the video itself)', (bool) array_filter($placeholderHeaders, fn($h) => stripos($h, 'Content-Type: image/jpeg') === 0));

$clipSize = filesize("$fxVideo/clip.mp4");

[$fullStatus, $fullBody, $fullHeaders] = http_get("$baseVideo/?video=clip.mp4");
check('?video= with no Range header returns 200 with the full file', $fullStatus === 200 && strlen($fullBody) === $clipSize);
check('?video= advertises Accept-Ranges: bytes', (bool) array_filter($fullHeaders, fn($h) => stripos($h, 'Accept-Ranges: bytes') === 0));
check('?video= sets the right video Content-Type', (bool) array_filter($fullHeaders, fn($h) => stripos($h, 'Content-Type: video/mp4') === 0));

[$rangeStatus, $rangeBody, $rangeHeaders] = http_get("$baseVideo/?video=clip.mp4", ['Range: bytes=0-9']);
check('a ranged ?video= request returns 206', $rangeStatus === 206);
check('a ranged ?video= request returns exactly the requested span', strlen($rangeBody) === 10);
check('a ranged ?video= request sets the right Content-Range', (bool) array_filter($rangeHeaders, fn($h) => stripos($h, "Content-Range: bytes 0-9/$clipSize") === 0));

[$suffixStatus, $suffixBody] = http_get("$baseVideo/?video=clip.mp4", ['Range: bytes=-10']);
check('a suffix range ("last 10 bytes") returns 206 with exactly 10 bytes', $suffixStatus === 206 && strlen($suffixBody) === 10);
$fullBytes = file_get_contents("$fxVideo/clip.mp4");
check('the suffix range\'s bytes actually are the file\'s last 10 bytes', $suffixBody === substr($fullBytes, -10));

[$eofStatus, , $eofHeaders] = http_get("$baseVideo/?video=clip.mp4", ['Range: bytes=' . ($clipSize + 1000) . '-' . ($clipSize + 2000)]);
check('a Range request entirely past end-of-file returns 416', $eofStatus === 416);
check('a 416 response reports the real file size in Content-Range', (bool) array_filter($eofHeaders, fn($h) => stripos($h, "Content-Range: bytes */$clipSize") === 0));

[$malformedStatus, $malformedBody] = http_get("$baseVideo/?video=clip.mp4", ['Range: bytes=abc-def']);
check('a malformed Range header is ignored, falling back to a full 200 response', $malformedStatus === 200 && strlen($malformedBody) === $clipSize);

[$videoTraversalStatus] = http_get("$baseVideo/?video=" . rawurlencode('../../../../etc/passwd'));
check('?video= refuses a path-traversal attempt', $videoTraversalStatus === 404);

// ENABLE_VIDEO stays off by default — a video file sitting in an
// otherwise-default gallery is invisible and unservable, exactly like an
// unmarked .md, even though the file genuinely exists on disk.
make_minimal_mp4("$fx1/ignored.mp4", 10);
[, $defaultsBodyWithVideo] = http_get("$base1/");
check('a video file is invisible when ENABLE_VIDEO is off (the default)', !str_contains($defaultsBodyWithVideo, 'ignored.mp4'));
[$videoOffStatus] = http_get("$base1/?video=ignored.mp4");
check('?video= 404s when ENABLE_VIDEO is off, even for a file that really exists', $videoOffStatus === 404);

// ?admin is completely inert — not just unauthenticated — when
// ADMIN_PASSWORD is unset, which $fx1/$base1 (the "defaults" fixture)
// never sets.
[$adminInertStatus] = http_get("$base1/?admin");
check('?admin 404s outright when ADMIN_PASSWORD is unset (the default)', $adminInertStatus === 404);

/* ======================================================================= *
 * FIXTURE: "admin" — ADMIN_PASSWORD set. Covers HTTP Basic Auth, the
 * failed-login throttle, CSRF, and every admin action: captions,
 * location (all three visibility gates), web links, folder display
 * name/cover, the {gallery}-marker one-click fix, raw notes save/save-
 * as/delete, export, and stale .gallery-items.json entry GC.
 * ======================================================================= */

echo "== admin fixture: auth, CSRF, throttle, and every admin action ==\n";

$fxAdmin = new_fixture_dir('admin');
mkdir("$fxAdmin/Paris");
make_jpeg("$fxAdmin/loose.jpg", 60, 60, 10, 10, 10);
make_jpeg("$fxAdmin/Paris/eiffel.jpg", 60, 60, 20, 20, 20);
make_jpeg("$fxAdmin/orphan-soon.jpg", 60, 60, 30, 30, 30);
file_put_contents("$fxAdmin/unmarked.md", "Some personal notes, not yet gallery content.\n");
file_put_contents("$fxAdmin/widgets.md", "{gallery}\nThe widget set. {group: Widget Set} {photos: loose.jpg}\n");

write_patched_index("$fxAdmin/index.php", $indexContents, [
    'ADMIN_PASSWORD' => "'test-admin-pw-123'",
    'AUTO_GROUP_BY_FOLDER' => 'true',
    'ENABLE_PHOTO_LOCATION' => 'true',
]);

$portAdmin = find_free_port();
start_server($fxAdmin, $portAdmin);
$baseAdmin = "http://127.0.0.1:$portAdmin";
$authHeader = 'Authorization: Basic ' . base64_encode('admin:test-admin-pw-123');
$wrongAuthHeader = 'Authorization: Basic ' . base64_encode('admin:wrong-password');

[$noAuthStatus, , $noAuthHeaders] = http_get("$baseAdmin/?admin");
check('?admin with no credentials returns 401', $noAuthStatus === 401);
check('?admin\'s 401 carries a WWW-Authenticate challenge', (bool) array_filter($noAuthHeaders, fn($h) => stripos($h, 'WWW-Authenticate: Basic') === 0));

[$wrongAuthStatus] = http_get("$baseAdmin/?admin", [$wrongAuthHeader]);
check('?admin with the wrong password returns 401', $wrongAuthStatus === 401);

[$okStatus, $adminBody, $adminHeaders] = http_get("$baseAdmin/?admin", [$authHeader]);
check('?admin with the correct password returns 200', $okStatus === 200);
check('the admin page actually renders', str_contains($adminBody, 'Gallery Admin'));
$sessionCookie = extract_cookie_header($adminHeaders);
check('logging in starts a PHP session (for the CSRF token)', $sessionCookie !== null);
$csrf = extract_csrf_token($adminBody);
check('the admin page embeds a CSRF token', $csrf !== null);

// CSRF: a save without a valid token is rejected and changes nothing.
[, $csrfRejectBody] = http_post("$baseAdmin/?admin", [
    'action' => 'save_item', 'file' => 'loose.jpg', 'caption' => 'should not stick', 'csrf_token' => 'bogus-token',
], [$authHeader, "Cookie: $sessionCookie"]);
check('a save with an invalid CSRF token is rejected', str_contains($csrfRejectBody, 'class="flash error"') && str_contains($csrfRejectBody, 'expired'));
[, $afterCsrfRejectBody] = http_get("$baseAdmin/");
check('the rejected save did not actually take effect', !str_contains($afterCsrfRejectBody, 'should not stick'));

// Caption.
[, $saveCaptionBody] = http_post("$baseAdmin/?admin", [
    'action' => 'save_item', 'file' => 'loose.jpg', 'caption' => 'A nice view', 'csrf_token' => $csrf,
], [$authHeader, "Cookie: $sessionCookie"]);
check('saving a caption with a valid CSRF token succeeds', str_contains($saveCaptionBody, 'class="flash ok"'));
[, $publicBodyWithCaption] = http_get("$baseAdmin/");
check('the saved caption shows publicly as a caption badge', (bool) preg_match('/caption-badge" title="A nice view"/', $publicBodyWithCaption));
check('the saved caption replaces the filename in the lightbox caption data', str_contains($publicBodyWithCaption, '"A nice view"') && !preg_match('/captions = \[[^\]]*"loose\.jpg"/', $publicBodyWithCaption));

// Web link: rejected without a scheme, accepted with one.
[, $badWebBody] = http_post("$baseAdmin/?admin", [
    'action' => 'save_item', 'file' => 'loose.jpg', 'web' => 'example.com', 'csrf_token' => $csrf,
], [$authHeader, "Cookie: $sessionCookie"]);
check('a web link missing http(s):// is rejected', str_contains($badWebBody, 'class="flash error"'));
http_post("$baseAdmin/?admin", [
    'action' => 'save_item', 'file' => 'loose.jpg', 'caption' => 'A nice view', 'web' => 'https://example.com/info', 'csrf_token' => $csrf,
], [$authHeader, "Cookie: $sessionCookie"]);
[, $publicBodyWithWeb] = http_get("$baseAdmin/");
check('a valid web link shows publicly as a web-link badge', str_contains($publicBodyWithWeb, 'class="item-badge web-badge" href="https://example.com/info"'));

// Location — three gates: global constant (on, in this fixture), the
// folder's own toggle (starts off), and per-photo visibility.
http_post("$baseAdmin/?admin", [
    'action' => 'save_item', 'file' => 'loose.jpg', 'caption' => 'A nice view', 'web' => 'https://example.com/info',
    'lat' => '48.8584', 'lng' => '2.2945', 'loc_visibility' => 'inherit', 'csrf_token' => $csrf,
], [$authHeader, "Cookie: $sessionCookie"]);
[, $bodyLocInherit] = http_get("$baseAdmin/");
check('a location with "inherit" visibility stays hidden while the folder\'s own toggle is off', !str_contains($bodyLocInherit, 'location-badge'));
http_post("$baseAdmin/?admin", [
    'action' => 'toggle_location_enabled', 'enabled' => '1', 'csrf_token' => $csrf,
], [$authHeader, "Cookie: $sessionCookie"]);
[, $bodyLocFolderOn] = http_get("$baseAdmin/");
check('once the folder\'s own location toggle is on, "inherit" visibility now shows the pin', str_contains($bodyLocFolderOn, 'location-badge'));
http_post("$baseAdmin/?admin", [
    'action' => 'save_item', 'file' => 'loose.jpg', 'caption' => 'A nice view', 'web' => 'https://example.com/info',
    'lat' => '48.8584', 'lng' => '2.2945', 'loc_visibility' => 'hide', 'csrf_token' => $csrf,
], [$authHeader, "Cookie: $sessionCookie"]);
[, $bodyLocHide] = http_get("$baseAdmin/");
check('a per-photo "always hide" wins even with the folder toggle on', !str_contains($bodyLocHide, 'location-badge'));

// Bad coordinates are rejected outright.
[, $badCoordBody] = http_post("$baseAdmin/?admin", [
    'action' => 'save_item', 'file' => 'loose.jpg', 'lat' => '999', 'lng' => '2.2945', 'csrf_token' => $csrf,
], [$authHeader, "Cookie: $sessionCookie"]);
check('an out-of-range latitude is rejected', str_contains($badCoordBody, 'class="flash error"'));

// A {group:}-controlled display name can't be overridden from the admin
// form (the field itself is also rendered disabled).
[, $widgetAdminBody] = http_get("$baseAdmin/?admin", [$authHeader, "Cookie: $sessionCookie"]);
check('a {group:}-controlled group\'s display-name field renders disabled', (bool) preg_match('/name="display_name"[^>]*disabled[^>]*>.*?widget-set/is', $widgetAdminBody) || (bool) preg_match('/widget-set.*?disabled/is', $widgetAdminBody));
[, $rejectGroupLabelBody] = http_post("$baseAdmin/?admin", [
    'action' => 'save_group_label', 'group_key' => 'widget-set', 'display_name' => 'Should not apply', 'csrf_token' => $csrf,
], [$authHeader, "Cookie: $sessionCookie"]);
check('saving a display name for a {group:}-controlled group is rejected server-side too', str_contains($rejectGroupLabelBody, 'class="flash error"'));

// Folder display name + cover (Paris, a real folder-group).
[, $parisAdminBody] = http_get("$baseAdmin/?admin&folder=Paris", [$authHeader, "Cookie: $sessionCookie"]);
$parisCsrf = extract_csrf_token($parisAdminBody);
http_post("$baseAdmin/?admin&folder=Paris", [
    'action' => 'save_folder_identity', 'display_name' => 'Our Trip to Paris', 'csrf_token' => $parisCsrf,
], [$authHeader, "Cookie: $sessionCookie"]);
[, $publicBodyRenamedFolder] = http_get("$baseAdmin/");
check('an admin-set folder display name shows on its public cover card', str_contains($publicBodyRenamedFolder, 'folder-cover-label">Our Trip to Paris<'));
http_post("$baseAdmin/?admin&folder=Paris", [
    'action' => 'set_cover', 'file' => 'eiffel.jpg', 'csrf_token' => $parisCsrf,
], [$authHeader, "Cookie: $sessionCookie"]);
[, $parisAdminAfterCover] = http_get("$baseAdmin/?admin&folder=Paris", [$authHeader, "Cookie: $sessionCookie"]);
check('"Set as cover" is recorded and shown back in the admin tool', str_contains($parisAdminAfterCover, 'eiffel.jpg'));

// {gallery} marker one-click fix.
http_post("$baseAdmin/?admin", [
    'action' => 'add_gallery_marker', 'md_file' => 'unmarked.md', 'csrf_token' => $csrf,
], [$authHeader, "Cookie: $sessionCookie"]);
check('the {gallery} marker fix actually prepends the marker on disk', str_starts_with((string) file_get_contents("$fxAdmin/unmarked.md"), "{gallery}\n"));
[, $adminAfterMarkerFix] = http_get("$baseAdmin/?admin", [$authHeader, "Cookie: $sessionCookie"]);
check('the fixed .md now shows as active in the admin tool', (bool) preg_match('/unmarked\.md.*?md-badge active/s', $adminAfterMarkerFix));

// Raw notes: create, overwrite, delete — byte-for-byte, no parsing.
http_post("$baseAdmin/?admin", [
    'action' => 'save_notes', 'md_file' => '', 'new_name' => 'newnotes', 'content' => "{gallery}\nBrand new notes.\n", 'csrf_token' => $csrf,
], [$authHeader, "Cookie: $sessionCookie"]);
check('creating a new notes file writes it to disk with a .md extension added', file_exists("$fxAdmin/newnotes.md") && str_contains((string) file_get_contents("$fxAdmin/newnotes.md"), 'Brand new notes.'));
http_post("$baseAdmin/?admin", [
    'action' => 'save_notes', 'md_file' => 'newnotes.md', 'new_name' => '', 'content' => "{gallery}\nUpdated content.\n", 'csrf_token' => $csrf,
], [$authHeader, "Cookie: $sessionCookie"]);
check('overwriting an existing notes file replaces its content exactly', trim((string) file_get_contents("$fxAdmin/newnotes.md")) === trim("{gallery}\nUpdated content."));
http_post("$baseAdmin/?admin", [
    'action' => 'delete_notes', 'md_file' => 'newnotes.md', 'csrf_token' => $csrf,
], [$authHeader, "Cookie: $sessionCookie"]);
check('deleting a notes file actually removes it from disk', !file_exists("$fxAdmin/newnotes.md"));

// Export as JSON.
[$exportStatus, $exportBody, $exportHeaders] = http_post("$baseAdmin/?admin", [
    'action' => 'export_items', 'csrf_token' => $csrf,
], [$authHeader, "Cookie: $sessionCookie"]);
check('export returns 200 with a JSON content type', $exportStatus === 200 && (bool) array_filter($exportHeaders, fn($h) => stripos($h, 'Content-Type: application/json') === 0));
check('export sets a download filename', (bool) array_filter($exportHeaders, fn($h) => stripos($h, 'Content-Disposition: attachment') === 0));
$exportedData = json_decode($exportBody, true);
check('the exported JSON actually contains the saved item data', is_array($exportedData) && isset($exportedData['items']['loose.jpg']['caption']) && $exportedData['items']['loose.jpg']['caption'] === 'A nice view');

// Stale entry GC: a caption for a file that then gets deleted is cleaned
// up (and reported), while one for a surviving file is left alone.
http_post("$baseAdmin/?admin", [
    'action' => 'save_item', 'file' => 'orphan-soon.jpg', 'caption' => 'about to vanish', 'csrf_token' => $csrf,
], [$authHeader, "Cookie: $sessionCookie"]);
unlink("$fxAdmin/orphan-soon.jpg");
[, $bodyAfterOrphan] = http_get("$baseAdmin/");
check('a stale caption entry for a deleted photo is reported in the notices', str_contains($bodyAfterOrphan, 'was cleaned up'));
$itemsAfterGc = json_decode((string) file_get_contents("$fxAdmin/.gallery-items.json"), true);
check('the orphaned entry is actually removed from .gallery-items.json', !isset($itemsAfterGc['items']['orphan-soon.jpg']));
check('a surviving file\'s own entry is left alone by the same GC pass', isset($itemsAfterGc['items']['loose.jpg']));

// Login throttle: 5 failures from one IP locks the 6th attempt out
// entirely (not even evaluating a correct password), until the lockout
// window passes.
for ($i = 0; $i < 5; $i++) {
    http_get("$baseAdmin/?admin", [$wrongAuthHeader]);
}
[$lockedStatus] = http_get("$baseAdmin/?admin", [$authHeader]);
check('after 5 failed attempts, even the correct password is locked out', $lockedStatus === 429);
$throttleData = json_decode((string) file_get_contents("$fxAdmin/.gallery-login-attempts.json"), true);
foreach ($throttleData as $k => $v) {
    $throttleData[$k]['lockUntil'] = time() - 1;
}
file_put_contents("$fxAdmin/.gallery-login-attempts.json", json_encode($throttleData));
[$unlockedStatus] = http_get("$baseAdmin/?admin", [$authHeader]);
check('once the lockout window passes, the correct password works again', $unlockedStatus === 200);

/* ======================================================================= *
 * FIXTURE: "private" — PRIVATE_VIEW_PASSWORD + ADMIN_PASSWORD both set.
 * Covers ?reveal (auth, cookie, bookmarkable redirect), per-item hiding,
 * per-folder hiding, the per-item tri-state override in both directions,
 * a group disappearing entirely once every member is hidden, hidden
 * items being excluded from every count (not just their thumbnail), a
 * valid admin login counting as revealed, the two password prompts
 * sharing the throttle mechanism but not its lockout state, and the
 * admin tool's hidden-items summary (three tiers) and persistent warning.
 * ======================================================================= */

echo "== private fixture: ?reveal, per-item/per-folder hiding, all three gates ==\n";

$fxPrivate = new_fixture_dir('private');
mkdir("$fxPrivate/Paris");
make_jpeg("$fxPrivate/loose1.jpg", 60, 60, 10, 10, 10);
make_jpeg("$fxPrivate/loose2.jpg", 60, 60, 20, 20, 20);
make_jpeg("$fxPrivate/Paris/eiffel.jpg", 60, 60, 30, 30, 30);
make_jpeg("$fxPrivate/Paris/other.jpg", 60, 60, 40, 40, 40);

write_patched_index("$fxPrivate/index.php", $indexContents, [
    'ADMIN_PASSWORD' => "'admin-pw-456'",
    'PRIVATE_VIEW_PASSWORD' => "'reveal-pw-789'",
    'AUTO_GROUP_BY_FOLDER' => 'true',
    'TRACK_VIEWS' => 'true',
]);

$portPrivate = find_free_port();
start_server($fxPrivate, $portPrivate);
$basePrivate = "http://127.0.0.1:$portPrivate";
$adminAuth = 'Authorization: Basic ' . base64_encode('admin:admin-pw-456');
$revealAuth = 'Authorization: Basic ' . base64_encode('x:reveal-pw-789');
$revealWrongAuth = 'Authorization: Basic ' . base64_encode('x:wrong');

// Public baseline: everything visible, footer carries the Private view link.
[, $privateBaselineBody] = http_get("$basePrivate/");
check('the "Private view" footer link appears once PRIVATE_VIEW_PASSWORD is set', str_contains($privateBaselineBody, 'href="?reveal">Private view</a>'));
check('nothing is hidden yet — loose1.jpg is visible', str_contains($privateBaselineBody, 'loose1.jpg'));

// Log into admin, flag loose1.jpg hidden.
[, $adminLoginBody, $adminLoginHeadersFirst] = http_get("$basePrivate/?admin", [$adminAuth]);
$sessCookie = extract_cookie_header($adminLoginHeadersFirst);
$adminCsrf = extract_csrf_token($adminLoginBody);
http_post("$basePrivate/?admin", [
    'action' => 'save_item', 'file' => 'loose1.jpg', 'restriction' => 'private', 'csrf_token' => $adminCsrf,
], [$adminAuth, "Cookie: $sessCookie"]);

[, $afterHideBody] = http_get("$basePrivate/");
check('a hidden item is omitted from the public page entirely', !str_contains($afterHideBody, 'loose1.jpg'));
[$hiddenImgStatus] = http_get("$basePrivate/?img=loose1.jpg&size=thumb");
check('?img= refuses a hidden item to an unrevealed visitor', $hiddenImgStatus === 404);
[, $hiddenPhotoBody] = http_get("$basePrivate/?photo=loose1.jpg");
check('?photo= deep link does not open a hidden item for an unrevealed visitor', str_contains($hiddenPhotoBody, 'initialPhoto = null;'));
[$hiddenTrackStatus, $hiddenTrackBody] = http_get("$basePrivate/?track=loose1.jpg");
$hiddenTrackJson = json_decode($hiddenTrackBody, true);
check('?track= does not count a view for a hidden item from an unrevealed visitor', is_array($hiddenTrackJson) && $hiddenTrackJson['ok'] === false);

// Hide the entire Paris folder.
http_post("$basePrivate/?admin&folder=Paris", [
    'action' => 'toggle_folder_restriction', 'hidden' => '1',
    'csrf_token' => extract_csrf_token(http_get("$basePrivate/?admin&folder=Paris", [$adminAuth, "Cookie: $sessCookie"])[1]),
], [$adminAuth, "Cookie: $sessCookie"]);
[, $afterFolderHideBody] = http_get("$basePrivate/");
check('an entirely-hidden folder has no cover card on the overview page', !str_contains($afterFolderHideBody, '<div class="folder-cover"'));
[$hiddenFolderPageStatus] = http_get("$basePrivate/?folder=Paris");
check('typing ?folder=<hidden folder> directly 404s for an unrevealed visitor, not just missing its cover card', $hiddenFolderPageStatus === 404);
[$hiddenFolderImgStatus] = http_get("$basePrivate/?img=" . rawurlencode('Paris/eiffel.jpg') . '&size=thumb');
check('?img= for a file inside a hidden folder is refused too', $hiddenFolderImgStatus === 404);
check('loose2.jpg (never flagged) is unaffected by hiding loose1.jpg and Paris', str_contains($afterFolderHideBody, 'loose2.jpg'));

// Per-item override: Paris/other.jpg explicitly shown despite the hidden folder.
$parisCsrf2 = extract_csrf_token(http_get("$basePrivate/?admin&folder=Paris", [$adminAuth, "Cookie: $sessCookie"])[1]);
http_post("$basePrivate/?admin&folder=Paris", [
    'action' => 'save_item', 'file' => 'other.jpg', 'restriction' => 'public', 'csrf_token' => $parisCsrf2,
], [$adminAuth, "Cookie: $sessCookie"]);
[, $afterOverrideBody] = http_get("$basePrivate/");
check('an item explicitly shown overrides its own hidden folder', str_contains($afterOverrideBody, 'Paris%2Fother.jpg'));
check('eiffel.jpg (no override) still stays hidden inside the same folder', !str_contains($afterOverrideBody, 'eiffel.jpg'));
[$overrideImgStatus] = http_get("$basePrivate/?img=" . rawurlencode('Paris/other.jpg') . '&size=thumb');
check('?img= honors the per-item "always show" override too', $overrideImgStatus === 200);
// Revert the override so the "whole group disappears" check below is clean.
http_post("$basePrivate/?admin&folder=Paris", [
    'action' => 'save_item', 'file' => 'other.jpg', 'restriction' => 'inherit', 'csrf_token' => $parisCsrf2,
], [$adminAuth, "Cookie: $sessCookie"]);
[, $afterRevertBody] = http_get("$basePrivate/");
check('once every member of a group is hidden, the group itself disappears (no "0 photos" tell)', !preg_match('/id="group-paris"/', $afterRevertBody));

// ?reveal: wrong password, correct password, cookie, redirect, then everything visible.
[$revealWrongStatus] = http_get("$basePrivate/?reveal", [$revealWrongAuth]);
check('?reveal with the wrong password returns 401', $revealWrongStatus === 401);
[$revealStatus, , $revealHeaders] = http_get("$basePrivate/?reveal", [$revealAuth], false);
check('?reveal with the correct password redirects (302)', $revealStatus === 302);
check('the redirect drops back to the bare URL, not leaving ?reveal in it', (bool) array_filter($revealHeaders, fn($h) => stripos($h, 'Location: ./') === 0));
$revealCookie = extract_cookie_header($revealHeaders);
check('?reveal sets a cookie', $revealCookie !== null);
[, $revealedBody] = http_get("$basePrivate/", ["Cookie: $revealCookie"]);
check('once revealed, every hidden item is visible again — including the entirely-hidden folder', str_contains($revealedBody, 'loose1.jpg') && str_contains($revealedBody, '<div class="folder-cover"'));
[$revealedImgStatus] = http_get("$basePrivate/?img=loose1.jpg&size=thumb", ["Cookie: $revealCookie"]);
check('?img= serves a hidden item once revealed', $revealedImgStatus === 200);

// A valid admin login counts as revealed too, on the *public* page.
[, , $adminLoginHeaders] = http_get("$basePrivate/?admin", [$adminAuth]);
$adminRevealCookie = extract_cookie_header($adminLoginHeaders);
[, $adminRevealedBody] = http_get("$basePrivate/", ["Cookie: $adminRevealCookie"]);
check('a valid admin login also sets the reveal cookie, unlocking the public page too', str_contains($adminRevealedBody, 'loose1.jpg'));

// The admin tool itself always sees everything, regardless of hiding.
[, $adminGridBody] = http_get("$basePrivate/?admin", [$adminAuth, "Cookie: $sessCookie"]);
check('the admin tool\'s own grid always shows a hidden item, hide flag and all', str_contains($adminGridBody, 'loose1.jpg'));
check('the admin tool shows a persistent lockdown warning while hidden items exist', str_contains($adminGridBody, 'Hidden items exist'));

// Hidden-items summary: three tiers, not a flat list.
[, $hiddenSummaryBody] = http_get("$basePrivate/?admin&hidden=1", [$adminAuth, "Cookie: $sessCookie"]);
check('the hidden-items summary lists the entirely-hidden folder', (bool) preg_match('/Folders hidden entirely \(1\)/', $hiddenSummaryBody) && str_contains($hiddenSummaryBody, '>Paris<'));
check('the hidden-items summary lists the individually-hidden item', (bool) preg_match('/Individual items hidden \(1\)/', $hiddenSummaryBody) && str_contains($hiddenSummaryBody, 'loose1.jpg'));

// Aggregate counts exclude hidden items, not just their thumbnails —
// loose1 hidden + Paris hidden leaves only loose2 visible (1 photo).
check('the header photo count excludes hidden items, not just their thumbnails', (bool) preg_match('/<p>1 photo\b/', $afterRevertBody));

// The two password prompts share the throttle mechanism but not its
// lockout state — failing the admin login doesn't lock out ?reveal.
for ($i = 0; $i < 5; $i++) {
    http_get("$basePrivate/?admin", ['Authorization: Basic ' . base64_encode('admin:wrong' . $i)]);
}
[$adminLockedStatus] = http_get("$basePrivate/?admin", [$adminAuth]);
check('5 failed admin attempts lock out the admin login', $adminLockedStatus === 429);
[$revealStillWorksStatus] = http_get("$basePrivate/?reveal", [$revealAuth], false);
check('...but ?reveal is on a separate throttle realm and still works', $revealStillWorksStatus === 302);

// Neither password feature does anything when unset (the default).
[$revealInertStatus] = http_get("$base1/?reveal");
check('?reveal 404s outright when PRIVATE_VIEW_PASSWORD is unset (the default)', $revealInertStatus === 404);

// ENABLE_MAP off (the default): ?map is simply ignored, not a crash or a
// special endpoint — the normal gallery page renders, with no map
// preview section and no Leaflet request of any kind.
[$mapOffStatus, $mapOffBody] = http_get("$base1/?map");
check('?map with ENABLE_MAP off just renders the normal page', $mapOffStatus === 200 && str_contains($mapOffBody, 'Defaults Fixture'));
check('no map preview or Leaflet library loads when ENABLE_MAP is off', !str_contains($mapOffBody, '<section class="map-preview"') && !str_contains($mapOffBody, 'cdnjs.cloudflare.com/ajax/libs/leaflet'));

/* ======================================================================= *
 * FIXTURE: "map" — ENABLE_MAP + ENABLE_PHOTO_LOCATION both on. Covers
 * the dedicated ?map page, the condensed root-page preview, clustering
 * (near points merge, far points don't), per-group color-coding and the
 * legend, correct popup link targets (?folder= for a folder-group, a
 * #group-<slug> anchor for a standalone item), and that a hidden item
 * never appears on the map at all.
 * ======================================================================= */

echo "== map fixture: ?map, clustering, legend, link targets, hiding ==\n";

$fxMap = new_fixture_dir('map');
mkdir("$fxMap/Paris");
mkdir("$fxMap/Rome");
make_jpeg("$fxMap/Paris/eiffel.jpg", 60, 60, 10, 10, 10);
make_jpeg("$fxMap/Paris/louvre.jpg", 60, 60, 15, 15, 15);
make_jpeg("$fxMap/Rome/colosseum.jpg", 60, 60, 20, 20, 20);
make_jpeg("$fxMap/standalone.jpg", 60, 60, 30, 30, 30);
make_jpeg("$fxMap/hidden.jpg", 60, 60, 40, 40, 40);

// Paris + Louvre are ~2.5 miles apart (well within the 50-mile default
// radius) and should cluster; Rome is ~690 miles from Paris and should
// not. Coordinates are real-world for a meaningful distance check.
file_put_contents("$fxMap/Paris/.gallery-items.json", json_encode([
    'items' => [
        'eiffel.jpg' => ['location' => ['lat' => 48.8584, 'lng' => 2.2945, 'label' => '']],
        'louvre.jpg' => ['location' => ['lat' => 48.8606, 'lng' => 2.3376, 'label' => '']],
    ],
    'groups' => [], 'displayName' => null, 'cover' => null, 'locationEnabled' => true,
]));
file_put_contents("$fxMap/Rome/.gallery-items.json", json_encode([
    'items' => ['colosseum.jpg' => ['location' => ['lat' => 41.8902, 'lng' => 12.4922, 'label' => '']]],
    'groups' => [], 'displayName' => null, 'cover' => null, 'locationEnabled' => true,
]));
file_put_contents("$fxMap/.gallery-items.json", json_encode([
    'items' => [
        'standalone.jpg' => ['location' => ['lat' => 40.7128, 'lng' => -74.0060, 'label' => 'NYC']],
        'hidden.jpg' => ['location' => ['lat' => 34.0522, 'lng' => -118.2437], 'restriction' => 'private'],
    ],
    'groups' => [], 'displayName' => null, 'cover' => null, 'locationEnabled' => true,
]));

write_patched_index("$fxMap/index.php", $indexContents, [
    'ENABLE_MAP' => 'true',
    'ENABLE_PHOTO_LOCATION' => 'true',
    'AUTO_GROUP_BY_FOLDER' => 'true',
]);

$portMap = find_free_port();
start_server($fxMap, $portMap);
$baseMap = "http://127.0.0.1:$portMap";

[, $mapRootBody] = http_get("$baseMap/");
check('the gallery root embeds a condensed map preview when ENABLE_MAP is on', str_contains($mapRootBody, 'id="trip-map-preview"'));
check('the root preview links to the full ?map page', str_contains($mapRootBody, 'href="?map"'));
check('the root preview shows a legend with one entry per distinct group', substr_count($mapRootBody, '<span class="map-legend-item">') === 3);

[$mapStatus, $mapBody] = http_get("$baseMap/?map");
check('?map returns 200', $mapStatus === 200);
check('?map loads the Leaflet library from a CDN', str_contains($mapBody, 'cdnjs.cloudflare.com/ajax/libs/leaflet'));
check('?map\'s Open Graph description mentions the point count', str_contains($mapBody, 'property="og:description" content="4 locations plotted'));

preg_match('/var clusters = (\[.*?\]);/s', $mapBody, $clusterMatch);
$clusters = json_decode($clusterMatch[1] ?? '', true);
check('clustering produced the right number of clusters (Paris+Louvre merged, Rome/NYC separate)', is_array($clusters) && count($clusters) === 3);

$clusterSizes = array_map('count', $clusters);
sort($clusterSizes);
check('the Paris+Louvre cluster has 2 members; Rome and NYC are singles', $clusterSizes === [1, 1, 2]);

check('a hidden item never appears on the map at all', !str_contains($mapBody, 'hidden.jpg'));

// Find the Paris cluster specifically (2 members) to check its link/color.
$parisCluster = null;
foreach ($clusters as $c) {
    if (count($c) === 2) { $parisCluster = $c; break; }
}
check('a folder-group member\'s popup link points at its ?folder= page', $parisCluster !== null && $parisCluster[0]['link'] === '?folder=Paris');
check('both members of the same folder-group share the same color', $parisCluster !== null && $parisCluster[0]['color'] === $parisCluster[1]['color']);

$standaloneCluster = null;
foreach ($clusters as $c) {
    if (count($c) === 1 && $c[0]['file'] === 'standalone.jpg') { $standaloneCluster = $c; break; }
}
check('a standalone item\'s popup link points at its own #group-<slug> anchor, not a folder page', $standaloneCluster !== null && $standaloneCluster[0]['link'] === '#group-standalone');

$romeCluster = null;
foreach ($clusters as $c) {
    if (count($c) === 1 && $c[0]['file'] === 'Rome/colosseum.jpg') { $romeCluster = $c; break; }
}
check('Rome (a different folder-group) gets a different color than Paris', $romeCluster !== null && $parisCluster !== null && $romeCluster[0]['color'] !== $parisCluster[0]['color']);

/* ======================================================================= *
 * FIXTURE 3: "mtimesort" — PHOTO_SORT_ORDER = 'mtime'.
 * ======================================================================= */

echo "== mtimesort fixture: PHOTO_SORT_ORDER=mtime ==\n";

$fx3 = new_fixture_dir('mtimesort');
write_patched_index("$fx3/index.php", $indexContents, ['PHOTO_SORT_ORDER' => "'mtime'"]);
make_jpeg("$fx3/z-first.jpg", 50, 50, 10, 10, 10);
sleep(1);
make_jpeg("$fx3/a-second.jpg", 50, 50, 20, 20, 20);

$port3 = find_free_port();
start_server($fx3, $port3);
$base3 = "http://127.0.0.1:$port3";

[$status, $body] = http_get("$base3/");
check('GET / returns 200 (mtimesort)', $status === 200);
$posFirst = strpos($body, 'alt="z-first.jpg"');
$posSecond = strpos($body, 'alt="a-second.jpg"');
check(
    'mtime sort places the older file first despite reverse alphabetical filenames',
    $posFirst !== false && $posSecond !== false && $posFirst < $posSecond
);

/* ======================================================================= *
 * FIXTURE 4: "nonotesbox" — every .md is rejected (missing marker), so
 * there's no notes box at all — the banner needs its other placement.
 * ======================================================================= */

echo "== nonotesbox fixture: notices banner with no notes box to sit in ==\n";

$fx4 = new_fixture_dir('nonotesbox');
copy($indexSrc, "$fx4/index.php");
make_jpeg("$fx4/only.jpg", 50, 50, 30, 30, 30);
file_put_contents("$fx4/notes.md", "# Not marked for gallery\nNo {gallery} marker here.\n");

$port4 = find_free_port();
start_server($fx4, $port4);
$base4 = "http://127.0.0.1:$port4";

[$status, $body] = http_get("$base4/");
check('GET / returns 200 (nonotesbox)', $status === 200);
check('no note-box renders when every .md is rejected', !str_contains($body, 'class="note-box"'));
check(
    'the notices banner still appears, right after the header, when there is no notes box to sit in',
    (bool) preg_match('#</header>\s*<div class="notes">\s*<details class="gallery-notices">#', $body)
);
check(
    'the rejected .md\'s filename is never revealed here either',
    !str_contains($body, 'notes.md')
);

/* --------------------------------------------------------------------- */

echo "\n$passCount passed, $failCount failed\n";
exit($failCount > 0 ? 1 : 0);
