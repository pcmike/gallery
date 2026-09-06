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

/* --------------------------------------------------------------------- *
 * Main fixture: a folder covering grouping, markdown/directives, deep
 * links, and the endpoints — everything except the TRACK_VIEWS toggle.
 * --------------------------------------------------------------------- */

$fixtureDir = sys_get_temp_dir() . '/gallery-tests-' . bin2hex(random_bytes(4));
mkdir($fixtureDir, 0775, true);
$cleanupDirs[] = $fixtureDir;
copy($indexSrc, $fixtureDir . '/index.php');

$images = [
    'dp104_01.jpg', 'dp104_02.jpg', 'single.jpg', '+model_01.jpg',
    'widget_01.jpg', 'widget_02.jpg', 'gizmo.jpg',
    'iphone15.jpg', 'iphone-15.jpg', 'gc-orphan.jpg',
];
foreach ($images as $i => $name) {
    make_jpeg("$fixtureDir/$name", 200, 150, 10 + $i * 20, 40, 90);
}

$notes = <<<'MD'
# Test Gallery

Intro line before any code.

```
brace test { unmatched
```

Contact line stays intact after the code block. }

(1) DP104 in great shape. {color: gold}

(2) This is buy+model, not a real mention.

(3) The +model is available now. {reserved}

(4) SINGLE item sold. {sold}

(5) WIDGET listing here. {color: red; background:blue}

(6) GIZMO gadget, dual status. {sold} {reserved}

(7) Raw <script>alert(1)</script> attempt.

(8) The IPHONE15 model works too, unlike the IPHONE case which cracked.
MD;
file_put_contents("$fixtureDir/notes.md", $notes);

$port = find_free_port();
start_server($fixtureDir, $port);
$base = "http://127.0.0.1:$port";

echo "== Gallery rendering, grouping, markdown, directives ==\n";
[$status, $body] = http_get("$base/");
check('GET / returns 200', $status === 200);
check('page title comes from the .md heading', str_contains($body, '<title>Test Gallery — Gallery</title>'));
check('h1 uses the .md heading', str_contains($body, '<h1>Test Gallery</h1>'));
check('heading line is stripped from the note box', !str_contains($body, '# Test Gallery'));
check('photo count matches fixture', str_contains($body, '<p>' . count($images) . ' photos</p>'));

check(
    'OG description keeps text after a code block intact (brace-stripping order regression)',
    (bool) preg_match('/<meta name="description" content="([^"]*)"/', $body, $dm)
        && str_contains($dm[1], 'Contact line stays intact')
);

check('safe {color: gold} directive applied to the dp104 group', str_contains($body, 'border-left-color: gold'));
check('unsafe {color: red; background:blue} value never leaks into output', !str_contains($body, 'background:blue'));

check(
    '"buy+model" does not falsely match the "+model" group (word-boundary regression)',
    str_contains($body, '<p>(2) This is buy+model, not a real mention.</p>')
);
check('"+model" mention gets a valid anchor id (slugify strips the leading +)', str_contains($body, 'id="item-model"'));

check('{sold} renders a corner badge', str_contains($body, '<span class="sold-badge-corner">SOLD</span>'));
check('{sold} renders the note-box sold wrapper', str_contains($body, 'class="sold-item"'));
check('{reserved} renders a corner badge', str_contains($body, '<span class="reserved-badge-corner">RESERVED</span>'));
check('{reserved} renders the note-box reserved wrapper', str_contains($body, 'class="reserved-item"'));

count_occurrences($body, '<span class="sold-badge-corner">SOLD</span>', 2, 'exactly 2 sold badges (single.jpg + gizmo.jpg)');
count_occurrences($body, '<span class="reserved-badge-corner">RESERVED</span>', 1, 'exactly 1 reserved badge ({sold}+{reserved} together: sold wins)');

check('raw HTML in a .md file is escaped', str_contains($body, '&lt;script&gt;alert(1)&lt;/script&gt;'));
check('raw HTML in a .md file is never executed unescaped', !str_contains($body, '<script>alert(1)</script>'));

count_occurrences($body, 'alt="iphone-15.jpg"', 2, 'iphone-15.jpg (group "iphone") matched once by mention, once by grid');
count_occurrences($body, 'alt="iphone15.jpg"', 2, 'iphone15.jpg (distinct group "iphone15") matched once by mention, once by grid');

echo "== ?about / ?download ==\n";
[$status, $body] = http_get("$base/?about");
check('GET /?about returns 200', $status === 200);
check('?about links to the GitHub repo', str_contains($body, 'github.com/pcmike/gallery'));
check('?about does not repeat the removed duplicate footer', !str_contains($body, 'created by github.com/pcmike'));
check('?about shows the current version', str_contains($body, 'v' . $currentVersion));

[$status, $body] = http_get("$base/?download");
check('GET /?download returns 200', $status === 200);
check('?download serves index.php byte-for-byte', $body === $indexContents);

echo "== ?img endpoint ==\n";
[$status, , $headers] = http_get("$base/?img=dp104_01.jpg&size=thumb");
check('GET /?img=<valid file> returns 200', $status === 200);
check('?img serves image/jpeg', (bool) array_filter($headers, fn($h) => stripos($h, 'Content-Type: image/jpeg') === 0));
check('?img=<valid file> creates a cache entry', is_dir("$fixtureDir/.gallery-cache") && count(glob("$fixtureDir/.gallery-cache/*.jpg")) > 0);

[$status] = http_get("$base/?img=" . '../../../../etc/passwd' . "&size=thumb");
check('?img path traversal is blocked (basename + allow-list)', $status === 404);

[$status] = http_get("$base/?img=does-not-exist.jpg&size=thumb");
check('?img=<missing file> returns 404', $status === 404);

echo "== ?track endpoint ==\n";
[$status, $body] = http_get("$base/?track=dp104_01.jpg");
$json = json_decode($body, true);
check('?track=<valid file> returns 200', $status === 200);
check('?track=<valid file> reports ok:true with a view count', is_array($json) && $json['ok'] === true && $json['photoViews'] >= 1);

[, $body] = http_get("$base/?track=dp104_01.jpg", ['User-Agent: facebookexternalhit/1.1']);
$json = json_decode($body, true);
check('?track from a known preview bot is excluded (ok:false)', is_array($json) && $json['ok'] === false);

[, $body] = http_get("$base/?track=" . '../../../etc/passwd');
$json = json_decode($body, true);
check('?track path traversal is rejected (ok:false)', is_array($json) && $json['ok'] === false);

echo "== Thumbnail cache garbage collection ==\n";
$orphanMtime = filemtime("$fixtureDir/gc-orphan.jpg");
$orphanThumbHash = md5('gc-orphan.jpg|thumb|' . $orphanMtime) . '.jpg';
http_get("$base/?img=gc-orphan.jpg&size=thumb");
check('gc-orphan.jpg thumbnail was cached', file_exists("$fixtureDir/.gallery-cache/$orphanThumbHash"));

$dp104Mtime = filemtime("$fixtureDir/dp104_01.jpg");
$dp104ThumbHash = md5('dp104_01.jpg|thumb|' . $dp104Mtime) . '.jpg';
check('dp104_01.jpg thumbnail was cached (sanity check before GC)', file_exists("$fixtureDir/.gallery-cache/$dp104ThumbHash"));

unlink("$fixtureDir/gc-orphan.jpg");
http_get("$base/"); // normal page load triggers gc_image_cache()

check('orphaned cache entry removed after its source photo is deleted', !file_exists("$fixtureDir/.gallery-cache/$orphanThumbHash"));
check('cache entries for surviving photos are left alone', file_exists("$fixtureDir/.gallery-cache/$dp104ThumbHash"));

/* --------------------------------------------------------------------- *
 * Second fixture: TRACK_VIEWS disabled.
 * --------------------------------------------------------------------- */

echo "== TRACK_VIEWS = false ==\n";
$fixtureDir2 = sys_get_temp_dir() . '/gallery-tests-notrack-' . bin2hex(random_bytes(4));
mkdir($fixtureDir2, 0775, true);
$cleanupDirs[] = $fixtureDir2;
$noTrackSrc = str_replace("define('TRACK_VIEWS', true);", "define('TRACK_VIEWS', false);", $indexContents);
check('TRACK_VIEWS constant was actually rewritten for this fixture', $noTrackSrc !== $indexContents);
file_put_contents("$fixtureDir2/index.php", $noTrackSrc);
make_jpeg("$fixtureDir2/photo.jpg", 200, 150, 80, 80, 80);

$port2 = find_free_port();
start_server($fixtureDir2, $port2);
$base2 = "http://127.0.0.1:$port2";

[$status] = http_get("$base2/");
check('GET / still returns 200 with tracking disabled', $status === 200);

[, $body] = http_get("$base2/?track=photo.jpg");
$json = json_decode($body, true);
check('?track always reports ok:false when TRACK_VIEWS is off', is_array($json) && $json['ok'] === false);

check('no JSON stats file is created when TRACK_VIEWS is off', !file_exists("$fixtureDir2/.gallery-stats.json"));
check('no SQLite stats file is created when TRACK_VIEWS is off', !file_exists("$fixtureDir2/.gallery-stats.sqlite"));

/* --------------------------------------------------------------------- */

echo "\n$passCount passed, $failCount failed\n";
exit($failCount > 0 ? 1 : 0);
