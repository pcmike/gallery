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
 * Three fixtures:
 *  - "defaults": an unmodified copy of index.php, so TRACK_VIEWS,
 *    ENABLE_THUMBNAIL_CACHE, and AUTO_GROUP_BY_FILENAME are all at their
 *    real off/off/off defaults. Covers the {gallery} marker requirement,
 *    explicit {group:}/{photos:} directives, positional clustering, the
 *    mistake-surfacing notices comment, and confirms nothing writes to
 *    disk unless turned on.
 *  - "optedin": TRACK_VIEWS / ENABLE_THUMBNAIL_CACHE / AUTO_GROUP_BY_FILENAME
 *    all explicitly flipped on. Covers filename auto-grouping, view
 *    tracking, thumbnail caching + GC, and directive/security regressions
 *    that predate this file's opt-in-defaults batch.
 *  - "mtimesort": PHOTO_SORT_ORDER set to 'mtime'.
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
        is_dir($path) ? rrmdir($path) : @unlink($path);
    }
    @rmdir($dir);
}

function make_jpeg($path, $w, $h, $r, $g, $b) {
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, $r, $g, $b));
    imagejpeg($im, $path, 85);
    imagedestroy($im);
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
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $cmd = escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . (int) $port . ' -t ' . escapeshellarg($docroot);
    $proc = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        fwrite(STDERR, "Could not start the PHP built-in server\n");
        exit(1);
    }
    $cleanupProcs[] = $proc;
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

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

/** GET request. Returns [statusCode, body, headerLines]. */
function http_get($url, $headers = []) {
    $opts = ['http' => ['method' => 'GET', 'ignore_errors' => true, 'timeout' => 5]];
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
check('{gallery} marker line itself never shows as rendered content', !str_contains($body, '<p>{gallery}</p>') && !preg_match('#<div class="notes">.*\{gallery\}#s', $body));

check(
    'unrelated.md (no {gallery} marker) is ignored and noted',
    str_contains($body, '&quot;unrelated.md&quot; was found but ignored')
);
check(
    'duplicate {photos:} claim is rejected and noted',
    str_contains($body, '&quot;dup.jpg&quot; is referenced in more than one {photos:} directive')
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

[$status, $body] = http_get("$base1/?download");
check('GET /?download returns 200', $status === 200);
check('?download serves index.php byte-for-byte', $body === $indexContents);

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

[$status, $body] = http_get("$base2/?track=dp104_01.jpg");
$json = json_decode($body, true);
check('?track=<valid file> returns 200 (optedin)', $status === 200);
check('?track reports ok:true with a view count when TRACK_VIEWS is on', is_array($json) && $json['ok'] === true && $json['photoViews'] >= 1);

[, $body] = http_get("$base2/?track=dp104_01.jpg", ['User-Agent: facebookexternalhit/1.1']);
$json = json_decode($body, true);
check('?track from a known preview bot is excluded (ok:false)', is_array($json) && $json['ok'] === false);

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

/* --------------------------------------------------------------------- */

echo "\n$passCount passed, $failCount failed\n";
exit($failCount > 0 ? 1 : 0);
