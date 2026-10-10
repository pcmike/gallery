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

// A PHP warning or notice in this test script means a check may have run on a missing value (an undefined
// variable is null, and "starts with null" is always true): count it as a failure instead of letting it scroll by.
set_error_handler(function ($errno, $message, $file, $line) {
    if (!(error_reporting() & $errno)) return false;   // @-suppressed on purpose (e.g. polling a server that is still starting)
    global $failCount;
    $failCount++;
    echo "  FAIL  PHP " . ($errno === E_WARNING || $errno === E_USER_WARNING ? 'warning' : 'notice') . " in the test script: $message (line $line)\n";
    return true;
});

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
/* --- "act like a browser" helpers, for the recipe/walkthrough tests ---------
 * The earlier admin tests POST hand-crafted field lists. A real user clicks
 * the page's own forms, which send *every* field with its current value (and
 * carry the stale-edit fingerprint, CSRF token, pre-filled GPS, ...). These
 * parse a rendered page the way a browser would, so a walkthrough can change
 * just what a person would type and submit the rest untouched. */

/** Attributes of a tag's inside as name => decoded value (flags => ''). */
function html_attrs($s) {
    $out = [];
    preg_match_all('#([a-zA-Z_:][-a-zA-Z0-9_:.]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+)))?#', $s, $m, PREG_SET_ORDER);
    foreach ($m as $a) {
        $v = $a[2] ?? ''; if ($v === '' && isset($a[3])) $v = $a[3]; if ($v === '' && isset($a[4])) $v = $a[4];
        $out[strtolower($a[1])] = html_entity_decode($v, ENT_QUOTES, 'UTF-8');
    }
    return $out;
}

/** Every <form method="post"> on a page, as ['attrs' =>, 'html' =>]. */
function html_post_forms($html) {
    preg_match_all('#<form\b([^>]*)>(.*?)</form>#s', $html, $m, PREG_SET_ORDER);
    $forms = [];
    foreach ($m as $f) if (stripos($f[1], 'method="post"') !== false) $forms[] = ['attrs' => $f[1], 'html' => $f[2]];
    return $forms;
}

/** What a browser would submit for a form left untouched: every named,
 *  enabled control with its current value (a checkbox/radio only if
 *  checked, a select's chosen or first option, a textarea's text). */
function form_default_fields($formHtml) {
    $f = [];
    preg_match_all('#<input\b([^>]*)>#i', $formHtml, $ins);
    foreach ($ins[1] as $raw) {
        $a = html_attrs($raw);
        if (!isset($a['name']) || array_key_exists('disabled', $a)) continue;
        $type = strtolower($a['type'] ?? 'text');
        if (in_array($type, ['submit', 'button', 'file', 'image', 'reset'], true)) continue;
        if ($type === 'checkbox' || $type === 'radio') { if (array_key_exists('checked', $a)) $f[$a['name']] = $a['value'] ?? 'on'; continue; }
        $f[$a['name']] = $a['value'] ?? '';
    }
    preg_match_all('#<textarea\b([^>]*)>(.*?)</textarea>#is', $formHtml, $tas, PREG_SET_ORDER);
    foreach ($tas as $ta) { $a = html_attrs($ta[1]); if (isset($a['name']) && !array_key_exists('disabled', $a)) $f[$a['name']] = html_entity_decode($ta[2], ENT_QUOTES, 'UTF-8'); }
    preg_match_all('#<select\b([^>]*)>(.*?)</select>#is', $formHtml, $sels, PREG_SET_ORDER);
    foreach ($sels as $sel) {
        $a = html_attrs($sel[1]); if (!isset($a['name']) || array_key_exists('disabled', $a)) continue;
        preg_match_all('#<option\b([^>]*)>#i', $sel[2], $opts); $chosen = null;
        foreach ($opts[1] as $i => $raw) { $o = html_attrs($raw); if ($chosen === null || array_key_exists('selected', $o)) $chosen = $o['value'] ?? ''; if (array_key_exists('selected', $o)) break; }
        $f[$a['name']] = $chosen ?? '';
    }
    return $f;
}

/** "Click the button" on the first form on $pageHtml containing $needle,
 *  changing only $overrides. $postUrl is the page's own URL (these forms
 *  have no action, so a browser posts them back to it). Returns
 *  [status, body, headers, fieldsSent]. */
function user_submit($postUrl, $pageHtml, $needle, $overrides = [], $headers = []) {
    foreach (html_post_forms($pageHtml) as $form) {
        if (!str_contains($form['html'], $needle)) continue;
        $fields = array_merge(form_default_fields($form['html']), $overrides);
        [$st, $body, $hd] = http_post($postUrl, $fields, $headers);
        return [$st, $body, $hd, $fields];
    }
    return [0, "NO FORM CONTAINING: $needle", [], []];
}

/** The generated data files start with a PHP guard line (see
 *  data_file_guard_line() in index.php) so a direct request for one
 *  answers 404 with nothing else. These read/write that exact format. */
function read_data_file($path) {
    $raw = (string) file_get_contents($path);
    if (strncmp($raw, '<?php', 5) === 0) {
        $raw = (string) substr($raw, strpos($raw, "\n") + 1);
    }
    return json_decode($raw, true);
}

/** The settings file (.gallery-config.php): the 404 guard line, then JSON. */
$cfgGuard = "<?php http_response_code(404); exit; ?>\n";
function cfg_text($settings) { return $GLOBALS['cfgGuard'] . json_encode($settings === [] ? new stdClass() : $settings, JSON_HEX_TAG) . "\n"; }
function cfg_read($path) { $raw = (string) file_get_contents($path); $raw = substr($raw, (int) strpos($raw, "\n") + 1); return json_decode($raw, true); }

function write_data_file($path, $data) {
    file_put_contents($path, "<?php http_response_code(404); exit; ?>\n" . json_encode($data, JSON_HEX_TAG));
}

/** A JPEG whose EXIF block holds only a GPS IFD — PHP has no EXIF writer,
 *  so this hand-assembles a minimal little-endian TIFF inside an APP1
 *  segment. $latDMS/$lngDMS are three [numerator, denominator] pairs. */
function make_gps_jpeg($path, $latRef, $latDMS, $lngRef, $lngDMS) {
    $im = imagecreatetruecolor(60, 60);
    imagefill($im, 0, 0, imagecolorallocate($im, 10, 200, 10));
    ob_start();
    imagejpeg($im);
    $jpeg = ob_get_clean();
    imagedestroy($im);
    $rationals = function ($dms) {
        $out = '';
        foreach ($dms as [$n, $d]) $out .= pack('VV', $n, $d);
        return $out;
    };
    $gpsOffset = 8 + 2 + 12 + 4; // TIFF header + IFD0 (one entry) + next-IFD pointer
    $gpsEntries = 4;
    $dataStart = $gpsOffset + 2 + 12 * $gpsEntries + 4;
    $latData = $rationals($latDMS);
    $lngData = $rationals($lngDMS);
    $tiff = 'II' . pack('v', 42) . pack('V', 8)
          . pack('v', 1) . pack('vvVV', 0x8825, 4, 1, $gpsOffset) . pack('V', 0)
          . pack('v', $gpsEntries)
          . pack('vvV', 1, 2, 2) . $latRef . "\0\0\0"
          . pack('vvVV', 2, 5, 3, $dataStart)
          . pack('vvV', 3, 2, 2) . $lngRef . "\0\0\0"
          . pack('vvVV', 4, 5, 3, $dataStart + strlen($latData))
          . pack('V', 0)
          . $latData . $lngData;
    $exif = "Exif\0\0" . $tiff;
    file_put_contents($path, substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif . substr($jpeg, 2));
}

/** A JPEG with the EXIF layout an iPhone writes: big-endian ("MM"), IFD0 with
 *  Make/Orientation before the GPS pointer, and a GPS IFD with the extra tags
 *  real phones add (VersionID, altitude, timestamp). Latitude/longitude are
 *  [deg, min, sec*100] integer triples. */
function make_iphone_gps_jpeg($path, $latRef, $lat, $lngRef, $lng) {
    $im = imagecreatetruecolor(40, 40); ob_start(); imagejpeg($im); $j = ob_get_clean(); imagedestroy($im);
    $rat = fn($n, $d) => pack('NN', $n, $d);
    $ent = fn($tag, $type, $count, $val) => pack('nnN', $tag, $type, $count) . $val;
    $make = "Apple\0"; $base = 8; $ifd0Len = 2 + 12 * 3 + 4; $gpsEntries = 8; $gpsLen = 2 + 12 * $gpsEntries + 4;
    $gpsOff = $base + $ifd0Len + strlen($make); $dataOff = $gpsOff + $gpsLen;
    $latD = $rat($lat[0], 1) . $rat($lat[1], 1) . $rat($lat[2], 100); $lngD = $rat($lng[0], 1) . $rat($lng[1], 1) . $rat($lng[2], 100);
    $alt = $rat(1234, 100); $ts = $rat(17, 1) . $rat(5, 1) . $rat(33, 1);
    $ifd0 = pack('n', 3) . $ent(0x010F, 2, strlen($make), pack('N', $base + $ifd0Len)) . $ent(0x0112, 3, 1, pack('nn', 6, 0)) . $ent(0x8825, 4, 1, pack('N', $gpsOff)) . pack('N', 0);
    $gps = pack('n', $gpsEntries) . $ent(0, 1, 4, "\x02\x02\x00\x00") . $ent(1, 2, 2, $latRef . "\0\0\0") . $ent(2, 5, 3, pack('N', $dataOff))
         . $ent(3, 2, 2, $lngRef . "\0\0\0") . $ent(4, 5, 3, pack('N', $dataOff + strlen($latD))) . $ent(5, 1, 1, "\x00\0\0\0")
         . $ent(6, 5, 1, pack('N', $dataOff + strlen($latD) + strlen($lngD))) . $ent(7, 5, 3, pack('N', $dataOff + strlen($latD) + strlen($lngD) + strlen($alt))) . pack('N', 0);
    $exif = "Exif\0\0" . 'MM' . pack('n', 42) . pack('N', $base) . $ifd0 . $make . $gps . $latD . $lngD . $alt . $ts;
    file_put_contents($path, substr($j, 0, 2) . "\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif . substr($j, 2));
}

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
    // A just-copied index.php is inside the first-run setup window (?admin offers to set a password for a few
    // minutes after upload). Fixtures are not "just uploaded", so age the script, as an hour-old install would be.
    if (is_file("$docroot/index.php")) @touch("$docroot/index.php", time() - 3600);
    // opcache is on for the built-in server and re-checks files only every 2 seconds, so a test that swaps index.php
    // and requests it at once could be served the old compiled copy: tests must be deterministic, so it's off here.
    $cmd = [PHP_BINARY, '-d', 'opcache.enable=0', '-S', '127.0.0.1:' . (int) $port, '-t', $docroot];
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
        // A setting is define('NAME', gallery_setting('NAME', default[, allowed])): replace the whole define.
        $pattern = "/define\('" . preg_quote($const, '/') . "',\s*gallery_setting\([^)]*\)\)/";
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
check('no JSON stats file created by default', !file_exists("$fx1/.gallery-stats.php"));
check('no SQLite stats file created by default', !file_exists("$fx1/.gallery-stats.sqlite"));

[$status, , $headers] = http_get("$base1/?img=widget_01.jpg&size=thumb");
check('?img=<file>&size=thumb returns 200 by default (thumbnails are on)', $status === 200);
$cacheFiles = is_dir("$fx1/.gallery-cache") ? array_values(array_diff(scandir("$fx1/.gallery-cache"), ['.', '..'])) : [];
check('a default install writes thumbnails into .gallery-cache, and only there: no stats or data file appears anywhere', $cacheFiles !== [] && !array_filter(scandir($fx1), fn($f) => str_starts_with($f, '.gallery-stats') || str_starts_with($f, '.gallery-items')));
// With the cache switched off explicitly, nothing is written and "thumb" is the original.
$fxNoCache = new_fixture_dir('nocache');
make_jpeg("$fxNoCache/big.jpg", 2400, 1800, 200, 80, 60);
write_patched_index("$fxNoCache/index.php", $indexContents, ['ENABLE_THUMBNAIL_CACHE' => 'false']);
$portNoCache = find_free_port(); start_server($fxNoCache, $portNoCache);
[$ncStatus, $ncBody] = http_get("http://127.0.0.1:$portNoCache/?img=big.jpg&size=thumb");
check('with ENABLE_THUMBNAIL_CACHE set to false, no .gallery-cache directory is created', $ncStatus === 200 && !is_dir("$fxNoCache/.gallery-cache"));
check('...and "thumb" is then served at full original size (no resizing)', strlen($ncBody) === filesize("$fxNoCache/big.jpg"));
$fxCacheOn = new_fixture_dir('cacheon');
make_jpeg("$fxCacheOn/big.jpg", 2400, 1800, 200, 80, 60);
copy(__DIR__ . '/../index.php', "$fxCacheOn/index.php");
$portCacheOn = find_free_port(); start_server($fxCacheOn, $portCacheOn);
[, $coBody] = http_get("http://127.0.0.1:$portCacheOn/?img=big.jpg&size=thumb");
check('by default a large photo\'s thumbnail really is smaller than the original', strlen($coBody) > 0 && strlen($coBody) < filesize("$fxCacheOn/big.jpg"));
// An unwritable folder must not break anything: the original is served, as with the cache off.
$fxRo2 = new_fixture_dir('cachero');
make_jpeg("$fxRo2/big.jpg", 2400, 1800, 200, 80, 60);
copy(__DIR__ . '/../index.php', "$fxRo2/index.php");
chmod($fxRo2, 0555);
$portRo2 = find_free_port(); start_server($fxRo2, $portRo2);
[$roStatus, $roBody] = http_get("http://127.0.0.1:$portRo2/?img=big.jpg&size=thumb");
check('with the cache on (the default) but the folder unwritable, the photo is still served (the original)', $roStatus === 200 && strlen($roBody) === filesize("$fxRo2/big.jpg") && !is_dir("$fxRo2/.gallery-cache"));
chmod($fxRo2, 0775);

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
check('a stats file is still written with SHOW_VIEW_COUNTS_PUBLICLY off', is_file("$fxHidden/.gallery-stats.php") || is_file("$fxHidden/.gallery-stats.sqlite"));

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

// ?img= is validated by resolving the one claimed path directly (before
// the tree is scanned), so every way of lying about a path must still 404.
foreach ([
    'a doubled slash' => 'Paris//eiffel.jpg',
    'a ./ prefix' => './Paris/eiffel.jpg',
    'a .. segment' => 'Paris/../Paris/eiffel.jpg',
    'a leading slash' => '/Paris/eiffel.jpg',
    'a backslash' => 'Paris\\eiffel.jpg',
    'a NUL byte' => "Paris/eiffel.jpg\0.png",
    'a folder, not a file' => 'Paris',
    'the script itself' => 'index.php',
    'a non-media extension' => 'Paris/notes.txt',
    'something inside .gallery-cache' => '.gallery-cache/anything.jpg',
    'a name that does not exist' => 'Paris/nope.jpg',
    'a file reached only through a symlinked folder' => 'SneakyLink/secret.jpg',
    'a wrong-case spelling of a real file' => 'Paris/EIFFEL.JPG',
] as $label => $badImg) {
    [$badImgStatus] = http_get("$baseNested/?img=" . rawurlencode($badImg) . '&size=thumb');
    check("?img= with $label returns 404", $badImgStatus === 404);
}
[$arrayImgStatus] = http_get("$baseNested/?img[]=" . rawurlencode('Paris/eiffel.jpg'));
check('?img[]= (an array, not a string) returns 404', $arrayImgStatus === 404);
[$okImgStatus] = http_get("$baseNested/?img=" . rawurlencode('Paris/eiffel.jpg') . '&size=thumb');
check('...while the real nested path still serves', $okImgStatus === 200);

http_get("$baseNested/?track=" . rawurlencode('Paris/eiffel.jpg'));
check('per-photo stats are colocated inside the photo\'s own subfolder, not the root', is_file("$fxNested/Paris/.gallery-stats.php") || is_file("$fxNested/Paris/.gallery-stats.sqlite"));
foreach (['.gallery-stats.php', 'Paris/.gallery-stats.php'] as $statsFileName) {
    if (is_file("$fxNested/$statsFileName")) {
        [$statsDirectStatus, $statsDirectBody] = http_get("$baseNested/$statsFileName");
        check("the view-stats file ($statsFileName) is a guarded PHP file: fetching it returns 404 and nothing else", $statsDirectStatus === 404 && $statsDirectBody === '' && strncmp((string) file_get_contents("$fxNested/$statsFileName"), '<?php', 5) === 0);
    }
}
// The root legitimately gets its own stats file regardless — the one
// sitewide pageview counter always lives there by design (every GET /
// above already bumped it). What must NOT be true is the root's store
// *also* holding an entry for a subfolder photo's own view count.
$rootHasEiffelEntry = false;
if (is_file("$fxNested/.gallery-stats.php")) {
    $rootData = read_data_file("$fxNested/.gallery-stats.php");
    $rootHasEiffelEntry = isset($rootData['photos']['Paris/eiffel.jpg']);
} elseif (is_file("$fxNested/.gallery-stats.sqlite")) {
    $rootPdo = new PDO('sqlite:' . "$fxNested/.gallery-stats.sqlite");
    $stmt = $rootPdo->prepare('SELECT views FROM photo_views_h WHERE key = :k');
    $stmt->execute([':k' => hash('sha256', 'Paris/eiffel.jpg')]);
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
check('the subfolder note\'s thumbnail strip links to the real full-path image, not a bare filename', (bool) preg_match('#src="\?img=Paris%2Feiffel\.jpg&amp;size=thumb&amp;v=[0-9a-f]{10}"#', $subfolderNotesBody));
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
check('the video card uses the normal ?img= thumbnail URL (served as a placeholder, not a broken image)', (bool) preg_match('#src="\?img=clip\.mp4&amp;size=thumb&amp;v=[0-9a-f]{10}"#', $videoPageBody));
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
 * as/delete, export, and stale .gallery-items.php entry GC.
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

[$noAuthStatus, $noAuthBody, $noAuthHeaders] = http_get("$baseAdmin/?admin");
check('?admin with no credentials shows a password form (200), not a browser Basic-Auth pop-up', $noAuthStatus === 200 && str_contains($noAuthBody, 'name="login_password"') && !array_filter($noAuthHeaders, fn($h) => stripos($h, 'WWW-Authenticate') === 0));
check('?admin\'s login form is never indexed or cached', str_contains($noAuthBody, 'noindex') && (bool) array_filter($noAuthHeaders, fn($h) => stripos($h, 'X-Robots-Tag: noindex') === 0) && (bool) array_filter($noAuthHeaders, fn($h) => stripos($h, 'Cache-Control: no-store') === 0));
check('...and asking for it starts no session (no Set-Cookie for an anonymous visitor)', !array_filter($noAuthHeaders, fn($h) => stripos($h, 'Set-Cookie:') === 0));

[$wrongAuthStatus, $wrongAuthBody, $wrongAuthHeaders] = http_get("$baseAdmin/?admin", [$wrongAuthHeader]);
check('?admin with a wrong password in a Basic header (a script, or a browser re-sending an old saved one) returns 401 and the login form, and NEVER a WWW-Authenticate challenge (that is what opens the browser\'s own dialog)', $wrongAuthStatus === 401 && !array_filter($wrongAuthHeaders, fn($h) => stripos($h, 'WWW-Authenticate') === 0) && str_contains($wrongAuthBody, 'name="login_password"') && (str_contains($wrongAuthBody, 'saved password') ));

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
[, $editorPage] = http_get("$baseAdmin/?admin", [$authHeader, "Cookie: $sessionCookie"]);
preg_match('#<textarea name="content" class="adm-textarea">(.*?)</textarea>#s', substr($editorPage, (int) strpos($editorPage, 'newnotes.md')), $editorTa);
check('[notes editor] an active notes file is shown exactly as stored, {gallery} line included (hiding it made every save switch the note off)', str_starts_with(html_entity_decode($editorTa[1] ?? ''), "{gallery}\nUpdated content."));
[, $savedNoMarker] = http_post("$baseAdmin/?admin", ['action' => 'save_notes', 'md_file' => 'newnotes.md', 'new_name' => '', 'content' => "Updated again.\n", 'csrf_token' => $csrf], [$authHeader, "Cookie: $sessionCookie"]);
check('[notes editor] saving text with no {gallery} first line says the file is not active', str_contains($savedNoMarker, 'not active'));
http_post("$baseAdmin/?admin", ['action' => 'save_notes', 'md_file' => 'newnotes.md', 'new_name' => '', 'content' => "{gallery}\nUpdated content.\n", 'csrf_token' => $csrf], [$authHeader, "Cookie: $sessionCookie"]);
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
$itemsAfterGc = read_data_file("$fxAdmin/.gallery-items.php");
check('the orphaned entry is actually removed from .gallery-items.php', !isset($itemsAfterGc['items']['orphan-soon.jpg']));
check('a surviving file\'s own entry is left alone by the same GC pass', isset($itemsAfterGc['items']['loose.jpg']));

// Login throttle: 5 failures from one IP locks the 6th attempt out
// entirely (not even evaluating a correct password), until the lockout
// window passes.
for ($i = 0; $i < 5; $i++) {
    http_get("$baseAdmin/?admin", [$wrongAuthHeader]);
}
[$lockedStatus] = http_get("$baseAdmin/?admin", [$authHeader]);
check('after 5 failed attempts, even the correct password is locked out', $lockedStatus === 429);
$throttleData = read_data_file("$fxAdmin/.gallery-login-attempts.php");
foreach ($throttleData as $k => $v) {
    $throttleData[$k]['lockUntil'] = time() - 1;
}
write_data_file("$fxAdmin/.gallery-login-attempts.php", ($throttleData));
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
// A revealed viewer opening a hidden item IS counted (the owner wants to
// know), but no file a web server could hand out may contain its name:
// the JSON store is a guarded PHP file, and the SQLite store keys rows by
// a hash, never the filename.
[, $hiddenTrackBody] = http_get("$basePrivate/?track=loose1.jpg", ["Cookie: $revealCookie"]);
check('tracking a hidden item as a revealed viewer counts it', str_contains($hiddenTrackBody, '"ok":true') && str_contains($hiddenTrackBody, '"photoViews":1'));
http_get("$basePrivate/?track=loose2.jpg", ["Cookie: $revealCookie"]);
$sqliteBlob = is_file("$fxPrivate/.gallery-stats.sqlite") ? (string) file_get_contents("$fxPrivate/.gallery-stats.sqlite") : '';
$sqliteWal = is_file("$fxPrivate/.gallery-stats.sqlite-wal") ? (string) file_get_contents("$fxPrivate/.gallery-stats.sqlite-wal") : '';
$jsonBlob = is_file("$fxPrivate/.gallery-stats.php") ? (string) file_get_contents("$fxPrivate/.gallery-stats.php") : '';
check('(setup) a stats store exists', $sqliteBlob !== '' || $jsonBlob !== '');
check('the SQLite stats file (and its WAL) never contains a filename, hidden or not', !str_contains($sqliteBlob . $sqliteWal, 'loose1.jpg') && !str_contains($sqliteBlob . $sqliteWal, 'loose2.jpg'));
check('...and the hidden item\'s count is real and comes back (revealed viewer\'s page badge)', str_contains((string) http_get("$basePrivate/", ["Cookie: $revealCookie"])[1], 'data-photo="loose1.jpg">&#128065; 1<'));
if ($jsonBlob !== '') {
    [$privStatsStatus, $privStatsBody] = http_get("$basePrivate/.gallery-stats.php");
    check('the guarded JSON stats file answers 404 with an empty body when fetched', $privStatsStatus === 404 && $privStatsBody === '');
}
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
check('the admin shows a red Hidden entry in the sidebar, above the folder list, with the not-a-lock explanation, while hidden items exist', str_contains($adminGridBody, 'class="side-hidden"') && strpos($adminGridBody, 'class="side-hidden"') < strpos($adminGridBody, 'class="admin-folder-nav"') && str_contains($adminGridBody, 'data-tab="hidden"') && str_contains($adminGridBody, "Hidden isn't locked") && str_contains($adminGridBody, 'servable directly'));
check('...and the top tabs stay the same four sections (Hidden is not one of them)', (bool) preg_match('#<nav class="admin-tabs".*?</nav>#s', $adminGridBody, $tabNav) && !str_contains($tabNav[0], 'hidden'));
check('...with the tabs in the same bar as the title and Log out (one header row), in that order', (function ($b) { $p1 = strpos($b, '<h1>Gallery Admin</h1>'); $p2 = strpos($b, '<nav class="admin-tabs"'); $p3 = strpos($b, 'class="logout-form"'); return $p1 !== false && $p1 < $p2 && $p2 < $p3 && substr_count(substr($b, 0, $p3), '<header') === 1 && strpos($b, '</header>') > $p3; })($adminGridBody));

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

// A bare request (no credentials) is just a visit to the form, so any
// number of them must never lock anyone out.
$bareStatuses = [];
for ($i = 0; $i < 8; $i++) { [$bareStatuses[]] = http_get("$basePrivate/?reveal"); }
check('eight bare ?reveal requests all just get the password form (no lockout from missing credentials)', $bareStatuses === array_fill(0, 8, 200));

// ENABLE_MAP off (the default): ?map is simply ignored, not a crash or a
// special endpoint — the normal gallery page renders, with no map
// preview section and no Leaflet request of any kind.
[$mapOffStatus, $mapOffBody] = http_get("$base1/?map");
check('?map with ENABLE_MAP off just renders the normal page', $mapOffStatus === 200 && str_contains($mapOffBody, 'Defaults Fixture'));
[, $mapIconOffBody] = http_get("$base1/?mapicon");
check('?mapicon is ignored (normal page) when ENABLE_MAP is off', str_contains($mapIconOffBody, 'Defaults Fixture'));
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
write_data_file("$fxMap/Paris/.gallery-items.php", ([
    'items' => [
        'eiffel.jpg' => ['location' => ['lat' => 48.8584, 'lng' => 2.2945, 'label' => '']],
        'louvre.jpg' => ['location' => ['lat' => 48.8606, 'lng' => 2.3376, 'label' => '']],
    ],
    'groups' => [], 'displayName' => null, 'cover' => null, 'locationEnabled' => true,
]));
write_data_file("$fxMap/Rome/.gallery-items.php", ([
    'items' => ['colosseum.jpg' => ['location' => ['lat' => 41.8902, 'lng' => 12.4922, 'label' => '']]],
    'groups' => [], 'displayName' => null, 'cover' => null, 'locationEnabled' => true,
]));
write_data_file("$fxMap/.gallery-items.php", ([
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

check('?map has an og:image pointing at ?mapicon and a large-image twitter card', str_contains($mapBody, 'property="og:image" content="http://127.0.0.1:' . $portMap . '/?mapicon"') && str_contains($mapBody, 'summary_large_image'));
[$iconStatus, $iconBody, $iconHeaders] = http_get("$baseMap/?mapicon");
$iconIsJpeg = false;
foreach ($iconHeaders as $h) { if (stripos($h, 'content-type: image/jpeg') === 0) $iconIsJpeg = true; }
$iconSize = @getimagesizefromstring($iconBody);
check('?mapicon serves a 1200x630 JPEG', $iconStatus === 200 && $iconIsJpeg && $iconSize !== false && $iconSize[0] === 1200 && $iconSize[1] === 630);

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
check('on the ?map page a standalone item\'s popup link goes to the root\'s #group-<slug> anchor (./#..., since a bare #... would stay on ?map), not a folder page', $standaloneCluster !== null && $standaloneCluster[0]['link'] === './#group-standalone');

preg_match('/var clusters = (\[.*?\]);/s', $mapRootBody, $rootClusterMatch);
$rootClusters = json_decode($rootClusterMatch[1] ?? '', true) ?: [];
$rootStandaloneLink = null;
foreach ($rootClusters as $c) {
    if (count($c) === 1 && $c[0]['file'] === 'standalone.jpg') { $rootStandaloneLink = $c[0]['link']; break; }
}
check('on the gallery root itself the same popup link stays a plain in-page #group-<slug> anchor', $rootStandaloneLink === '#group-standalone');

$romeCluster = null;
foreach ($clusters as $c) {
    if (count($c) === 1 && $c[0]['file'] === 'Rome/colosseum.jpg') { $romeCluster = $c; break; }
}
check('Rome (a different folder-group) gets a different color than Paris', $romeCluster !== null && $parisCluster !== null && $romeCluster[0]['color'] !== $parisCluster[0]['color']);

/* ======================================================================= *
 * FIXTURE: "depth" — folders nested three levels deep. Covers
 * drill-down at every level (cover cards for subfolders, flat cards for
 * loose files, breadcrumbs), path-trick 404s, a hide flag placed on a
 * *nested* folder (honored at depth, for ?folder=, ?img= and counts),
 * the admin at depth, and the folder description/dates/position fields.
 * ======================================================================= */

echo "== depth fixture: nested drill-down, nested hiding, admin at depth, folder fields ==\n";

$fxDepth = new_fixture_dir('depth');
mkdir("$fxDepth/Europe/France/Paris", 0777, true);
mkdir("$fxDepth/Europe/France/Lyon", 0777, true);
mkdir("$fxDepth/Europe/Germany", 0777, true);
mkdir("$fxDepth/Asia");
make_jpeg("$fxDepth/loose.jpg", 60, 60, 10, 10, 10);
make_jpeg("$fxDepth/Europe/intro.jpg", 60, 60, 20, 20, 20);
make_jpeg("$fxDepth/Europe/France/Paris/eiffel.jpg", 60, 60, 30, 30, 30);
make_jpeg("$fxDepth/Europe/France/Paris/louvre.jpg", 60, 60, 40, 40, 40);
make_jpeg("$fxDepth/Europe/France/Lyon/lyon.jpg", 60, 60, 50, 50, 50);
make_jpeg("$fxDepth/Europe/Germany/berlin.jpg", 60, 60, 60, 60, 60);
make_jpeg("$fxDepth/Asia/tokyo.jpg", 60, 60, 70, 70, 70);
// Hand-placed, as if from an earlier admin session: Germany (a *nested*
// folder) is hidden from public view.
write_data_file("$fxDepth/Europe/Germany/.gallery-items.php", ([
    'items' => [], 'groups' => [], 'displayName' => null, 'cover' => null, 'locationEnabled' => false, 'restriction' => 'private',
]));
file_put_contents("$fxDepth/trip.md", "{gallery}\nWe loved Europe so much.\n");

write_patched_index("$fxDepth/index.php", $indexContents, [
    'AUTO_GROUP_BY_FOLDER' => 'true',
    'ADMIN_PASSWORD' => "'test-admin-pw-123'",
    'PRIVATE_VIEW_PASSWORD' => "'viewer-pw-456'",
]);
$portDepth = find_free_port();
start_server($fxDepth, $portDepth);
$baseDepth = "http://127.0.0.1:$portDepth";

[, $depthRoot] = http_get("$baseDepth/");
check('the root shows a cover card per top-level folder', str_contains($depthRoot, 'id="group-europe"') && str_contains($depthRoot, 'id="group-asia"'));
check('the root does not show nested folders as cards of their own', !str_contains($depthRoot, 'id="group-france"') && !str_contains($depthRoot, 'id="group-paris"'));
check('Europe\'s count is recursive and excludes the hidden nested Germany (intro + 3 France = 4)', (bool) preg_match('/id="group-europe">.*?4 photos/s', $depthRoot));
check('a .md mention of a folder in overview mode adds no thumbnail strip (the cover card is on the same page), though the paragraph stays and the card is there', !str_contains($depthRoot, 'class="linked-photos-jump" href="?folder=Europe"') && str_contains($depthRoot, 'id="item-europe"') && str_contains($depthRoot, '<div class="folder-cover" id="group-europe"'));

[$europeStatus, $europeBody] = http_get("$baseDepth/?folder=Europe");
check('?folder=Europe returns 200', $europeStatus === 200);
check('Europe\'s page shows France as a cover card (its recursive count, 3 photos)', (bool) preg_match('/<div class="folder-cover" id="group-france">.*?href="\?folder=Europe%2FFrance".*?3 photos/s', $europeBody));
check('Europe\'s page shows its loose photo as a plain card, not a folder', str_contains($europeBody, 'alt="Europe/intro.jpg"') && !str_contains($europeBody, 'id="group-intro"><a class="folder-cover-link"'));
check('the hidden nested Germany has no cover card and no mention anywhere on Europe\'s page', !str_contains($europeBody, 'group-germany') && !str_contains($europeBody, 'berlin') && !str_contains($europeBody, 'Germany'));
check('a one-folder-deep page\'s breadcrumb is just the way back to the root', (bool) preg_match('/<p class="breadcrumb"><a href="\.\/">[^<]*<\/a><\/p>/', $europeBody));

// A web server may rewrite a pretty URL (/Europe/France/) onto this script
// with the query string riding along. "./" would then resolve to that
// pretty folder itself, so the back-link has to name the real root. (php -S
// treats a nonexistent path the same way: index.php, original REQUEST_URI.)
[$prettyStatus, $prettyBody] = http_get("$baseDepth/trip/Europe/France/?folder=" . rawurlencode('Europe/France'));
check('a pretty-style path with ?folder= still renders that folder', $prettyStatus === 200 && str_contains($prettyBody, 'id="group-paris"'));
check('...and its back-link names the real gallery root, not "./" (which would resolve to the pretty path itself)', (bool) preg_match('#<p class="breadcrumb"><a href="/">#', $prettyBody));

[$franceStatus, $franceBody] = http_get("$baseDepth/?folder=" . rawurlencode('Europe/France'));
check('?folder=Europe/France (two levels deep) returns 200', $franceStatus === 200);
check('France\'s page shows Paris and Lyon as cover cards', str_contains($franceBody, 'id="group-paris"') && str_contains($franceBody, 'id="group-lyon"'));
check('a cover card links to its full nested path', str_contains($franceBody, 'href="?folder=Europe%2FFrance%2FParis"'));
check('a two-deep page\'s breadcrumb links back through Europe', (bool) preg_match('/<p class="breadcrumb"><a href="\.\/">[^<]*<\/a> \/ <a href="\?folder=Europe">Europe<\/a><\/p>/', $franceBody));

[$parisStatus, $parisBody] = http_get("$baseDepth/?folder=" . rawurlencode('Europe/France/Paris'));
check('?folder=Europe/France/Paris (three levels deep) returns 200', $parisStatus === 200);
check('the deepest page shows its own photos as plain cards', str_contains($parisBody, 'alt="Europe/France/Paris/eiffel.jpg"') && str_contains($parisBody, 'alt="Europe/France/Paris/louvre.jpg"') && !str_contains($parisBody, 'lyon.jpg'));
check('its breadcrumb links through every ancestor', (bool) preg_match('/<a href="\?folder=Europe">Europe<\/a> \/ <a href="\?folder=Europe%2FFrance">France<\/a><\/p>/', $parisBody));
check('its title is the folder\'s own name, not the whole path', (bool) preg_match('/<title>Paris — /', $parisBody));
check('its card thumbnails use the full nested path', (bool) preg_match('#src="\?img=Europe%2FFrance%2FParis%2Feiffel\.jpg&amp;size=thumb&amp;v=[0-9a-f]{10}"#', $parisBody));

foreach ([
    'a nonexistent nested folder' => 'Europe/Nope',
    'a ../ path trick' => '../',
    'a ../ trick hidden inside a real path' => 'Europe/../Asia',
    'a trailing slash' => 'Europe/',
    'a leading slash' => '/Europe',
    'a file path, not a folder' => 'Europe/France/Paris/eiffel.jpg',
    'an empty value' => '',
    'the hidden nested folder, for an unrevealed visitor' => 'Europe/Germany',
] as $label => $badPath) {
    [$badStatus] = http_get("$baseDepth/?folder=" . rawurlencode($badPath));
    check("?folder= with $label returns 404", $badStatus === 404);
}
[$hiddenImgStatus] = http_get("$baseDepth/?img=" . rawurlencode('Europe/Germany/berlin.jpg') . '&size=thumb');
check('a photo inside a nested hidden folder is refused at ?img= too', $hiddenImgStatus === 404);

// Reveal: the viewer password unlocks the nested folder everywhere.
[, , $depthRevealHeaders] = http_get("$baseDepth/?reveal", ['Authorization: Basic ' . base64_encode('viewer:viewer-pw-456')], false);
$depthRevealCookie = extract_cookie_header($depthRevealHeaders);
[$revealedGermanyStatus, $revealedGermanyBody] = http_get("$baseDepth/?folder=" . rawurlencode('Europe/Germany'), ["Cookie: $depthRevealCookie"]);
check('once revealed, the nested hidden folder\'s own page loads', $revealedGermanyStatus === 200 && str_contains($revealedGermanyBody, 'berlin.jpg'));
[, $revealedEuropeBody] = http_get("$baseDepth/?folder=Europe", ["Cookie: $depthRevealCookie"]);
check('once revealed, Europe\'s page shows the Germany cover card', str_contains($revealedEuropeBody, 'id="group-germany"'));

// Admin at depth.
$depthAuth = 'Authorization: Basic ' . base64_encode('admin:test-admin-pw-123');
[, $depthAdminBody, $depthAdminHeaders] = http_get("$baseDepth/?admin", [$depthAuth]);
$depthCookie = extract_cookie_header($depthAdminHeaders);
$depthCsrf = extract_csrf_token($depthAdminBody);
$depthPost = function ($query, $fields) use ($baseDepth, $depthAuth, &$depthCookie, &$depthCsrf) {
    return http_post("$baseDepth/?admin$query", $fields + ['csrf_token' => $depthCsrf], [$depthAuth, "Cookie: $depthCookie"]);
};
check('the admin nav lists nested folders at every depth', str_contains($depthAdminBody, 'folder=Europe%2FFrance%2FParis') && str_contains($depthAdminBody, 'folder=Europe%2FFrance"'));

$parisAdminQuery = '&folder=' . rawurlencode('Europe/France/Paris');
[$parisAdminStatus, $parisAdminBody] = http_get("$baseDepth/?admin$parisAdminQuery", [$depthAuth, "Cookie: $depthCookie"]);
check('?admin&folder= works three levels deep', $parisAdminStatus === 200 && str_contains($parisAdminBody, 'eiffel.jpg'));
check('the admin grid shows only what is loose in that folder (not Lyon\'s photo)', !str_contains($parisAdminBody, 'lyon.jpg'));
[$badAdminStatus] = http_get("$baseDepth/?admin&folder=" . rawurlencode('Europe/Nope'), [$depthAuth, "Cookie: $depthCookie"]);
check('?admin&folder= with a bogus nested path returns 404', $badAdminStatus === 404);

[, $captionBody] = $depthPost($parisAdminQuery, ['action' => 'save_item', 'file' => 'eiffel.jpg', 'caption' => 'Iron lady']);
check('a caption saved on a deeply nested photo succeeds', str_contains($captionBody, 'class="flash ok"'));
check('...and its colocated data file lives inside that folder', is_file("$fxDepth/Europe/France/Paris/.gallery-items.php"));
[, $parisAfterCaption] = http_get("$baseDepth/?folder=" . rawurlencode('Europe/France/Paris'));
check('...and shows publicly on that folder\'s page', str_contains($parisAfterCaption, 'Iron lady'));

// Folder fields: description, dates, position.
$europeAdminQuery = '&folder=Europe';
[, $fieldsBody] = $depthPost($europeAdminQuery, ['action' => 'save_folder_identity', 'display_name' => 'Europe 2026', 'description' => "Three weeks,\n  two <script>alert(1)</script> countries.", 'dates' => 'June 12-15, 2026', 'sort_order' => '2']);
check('saving a folder\'s description, dates and position succeeds', str_contains($fieldsBody, 'class="flash ok"'));
[, $fieldsRoot] = http_get("$baseDepth/");
check('the description is NOT on the folder\'s cover card (it lives on the folder\'s own page), and nothing from it leaks unescaped into the root page', !str_contains($fieldsRoot, 'folder-cover-desc') && !str_contains($fieldsRoot, 'Three weeks') && !str_contains($fieldsRoot, '<script>alert(1)'));
check('the dates show on the folder\'s cover card', str_contains($fieldsRoot, 'folder-cover-dates">June 12-15, 2026<'));
[, $fieldsPage] = http_get("$baseDepth/?folder=Europe");
check('the dates sit under the folder\'s title, and the description is a centered note box (not loose text in the header)', str_contains($fieldsPage, '<p class="folder-dates">June 12-15, 2026</p>') && str_contains($fieldsPage, '<div class="notes">' . "\n" . '    <div class="note-box folder-description">Three weeks, two &lt;script&gt;') && !preg_match('#<header>.*folder-description.*</header>#s', $fieldsPage));
check('the folder\'s page title uses its display name', (bool) preg_match('/<title>Europe 2026 — /', $fieldsPage));
check('the folder\'s Open Graph description uses its description', str_contains($fieldsPage, 'property="og:description" content="Three weeks, two &lt;script&gt;'));

// Position: Asia first, then Europe (position 2); positions beat natural
// order. (The root notes' mention of Europe is what the mention check
// above needed — and by design a prose mention positions a folder ahead
// of natural order, where a position acts, so swap in notes that mention
// neither folder before testing position on its own.)
file_put_contents("$fxDepth/trip.md", "{gallery}\nNothing in here names a folder.\n");
$depthPost('&folder=Asia', ['action' => 'save_folder_identity', 'display_name' => '', 'sort_order' => '1']);
[, $orderedRoot] = http_get("$baseDepth/");
check('numbered folders sort by position ahead of natural order (Asia=1 before Europe=2)', strpos($orderedRoot, 'id="group-asia"') < strpos($orderedRoot, 'id="group-europe"'));
$depthPost('&folder=Asia', ['action' => 'save_folder_identity', 'display_name' => '', 'sort_order' => '3']);
[, $reorderedRoot] = http_get("$baseDepth/");
check('changing positions reorders them (Europe=2 now before Asia=3)', strpos($reorderedRoot, 'id="group-europe"') < strpos($reorderedRoot, 'id="group-asia"'));
$depthPost('&folder=Asia', ['action' => 'save_folder_identity', 'display_name' => '', 'sort_order' => '']);
[, $unorderedRoot] = http_get("$baseDepth/");
check('clearing a position puts the folder back in natural order, after numbered ones', strpos($unorderedRoot, 'id="group-europe"') < strpos($unorderedRoot, 'id="group-asia"'));

// Nested siblings order too (position on Lyon pulls it ahead of Paris).
$depthPost('&folder=' . rawurlencode('Europe/France/Lyon'), ['action' => 'save_folder_identity', 'display_name' => '', 'sort_order' => '1']);
[, $franceOrdered] = http_get("$baseDepth/?folder=" . rawurlencode('Europe/France'));
check('position works among nested siblings (Lyon=1 ahead of Paris)', strpos($franceOrdered, 'id="group-lyon"') < strpos($franceOrdered, 'id="group-paris"'));

[, $badPosBody] = $depthPost($europeAdminQuery, ['action' => 'save_folder_identity', 'display_name' => 'Europe 2026', 'sort_order' => 'first']);
check('a non-numeric position is rejected with an error', str_contains($badPosBody, 'class="flash error"') && str_contains($badPosBody, 'whole number'));
$depthPost($europeAdminQuery, ['action' => 'save_folder_identity', 'display_name' => 'Europe 2026']);
[, $keptFieldsRoot] = http_get("$baseDepth/");
check('a save that omits the other fields leaves them untouched', str_contains($keptFieldsRoot, 'folder-cover-dates">June 12-15, 2026<'));

// Hiding at depth through the admin itself.
[, $hideNestedBody] = $depthPost($parisAdminQuery, ['action' => 'toggle_folder_restriction', 'hidden' => '1']);
check('hiding a deeply nested folder through the admin succeeds', str_contains($hideNestedBody, 'class="flash ok"'));
[$hiddenParisStatus] = http_get("$baseDepth/?folder=" . rawurlencode('Europe/France/Paris'));
check('...and the nested folder\'s page is then a 404 to the public', $hiddenParisStatus === 404);
[, $franceAfterHide] = http_get("$baseDepth/?folder=" . rawurlencode('Europe/France'));
check('...and its parent no longer shows its cover card, and the count drops', !str_contains($franceAfterHide, 'id="group-paris"') && (bool) preg_match('/id="group-lyon">.*?1 photo/s', $franceAfterHide));
[, $hiddenSummary] = http_get("$baseDepth/?admin&hidden", [$depthAuth, "Cookie: $depthCookie"]);
check('the admin\'s Hidden items summary lists nested hidden folders (Germany and Paris)', str_contains($hiddenSummary, 'folder=Europe%2FGermany') && str_contains($hiddenSummary, 'folder=Europe%2FFrance%2FParis'));

// Whole-tree export.
[$treeStatus, $treeBody] = $depthPost('', ['action' => 'export_tree']);
$treeJson = json_decode($treeBody, true);
check('the whole-tree export is JSON keyed by folder path, root as ""', $treeStatus === 200 && is_array($treeJson) && array_key_exists('', $treeJson) && isset($treeJson['Europe']) && isset($treeJson['Europe/France/Paris']));
check('...and carries the saved fields', ($treeJson['Europe']['description'] ?? '') !== '' && ($treeJson['Europe']['dates'] ?? '') === 'June 12-15, 2026');

// The generated data files are PHP files starting with an exit guard, so
// asking the web server for one by URL yields a bare 404 and none of the
// JSON — captions, GPS, and (above all) the filenames of hidden items.
$guard = "<?php http_response_code(404); exit; ?>\n";
$onDisk = (string) file_get_contents("$fxDepth/Europe/France/Paris/.gallery-items.php");
check('a data file on disk starts with the exit guard line', strncmp($onDisk, $guard, strlen($guard)) === 0);
$depthPost('', ['action' => 'save_item', 'file' => 'loose.jpg', 'caption' => 'a root-level caption']);
check('(setup) the root data file now exists', is_file("$fxDepth/.gallery-items.php"));
foreach ([
    'a folder\'s captions file' => '/Europe/France/Paris/.gallery-items.php',
    'the hidden-flag file naming a hidden folder' => '/Europe/Germany/.gallery-items.php',
    'the root data file' => '/.gallery-items.php',
] as $label => $dataUrl) {
    [$dataStatus, $dataBody] = http_get("$baseDepth$dataUrl");
    check("requesting $label directly returns 404 with an empty body", $dataStatus === 404 && $dataBody === '');
}
[, $germanyDirect] = http_get("$baseDepth/Europe/Germany/.gallery-items.php");
check('...and the hide flag is not in anything it sends', !str_contains($germanyDirect, 'private') && !str_contains($germanyDirect, 'restriction'));

// A caption containing PHP open tags must neither end up as live code in
// the file nor reach the page unescaped.
$evil = "x<?php echo 'PWNED'; ?>y <?= 'PWNED2' ?>";
[, $evilSave] = $depthPost($parisAdminQuery, ['action' => 'save_item', 'file' => 'louvre.jpg', 'caption' => $evil]);
check('saving a caption with PHP open tags succeeds', str_contains($evilSave, 'class="flash ok"'));
$evilOnDisk = (string) file_get_contents("$fxDepth/Europe/France/Paris/.gallery-items.php");
check('...but the file holds no "<?" after its guard line (tags are written as <)', substr_count($evilOnDisk, '<?') === 1 && str_contains($evilOnDisk, '<'));
[$evilStatus, $evilDirect] = http_get("$baseDepth/Europe/France/Paris/.gallery-items.php");
check('...so requesting the file still runs only the guard (404, nothing echoed)', $evilStatus === 404 && $evilDirect === '');
[, $evilPage] = http_get("$baseDepth/?folder=" . rawurlencode('Europe/France/Paris'), ["Cookie: $depthCookie"]);
check('...and the caption comes back literally, escaped, never executed', !str_contains($evilPage, 'PWNED') || str_contains($evilPage, '&lt;?php echo'));
$evilRoundTrip = read_data_file("$fxDepth/Europe/France/Paris/.gallery-items.php");
check('...and round-trips through the file exactly', ($evilRoundTrip['items']['louvre.jpg']['caption'] ?? null) === $evil);

// A plain-JSON file with no guard line (hand-made, or left by something
// else) is still read.
file_put_contents("$fxDepth/Asia/.gallery-items.php", json_encode(['items' => ['tokyo.jpg' => ['caption' => 'plain json, no guard']]]));
[, $plainJsonPage] = http_get("$baseDepth/?folder=Asia");
check('an unguarded plain-JSON data file is still read', str_contains($plainJsonPage, 'plain json, no guard'));

// Fuzz-found crashes (each was a fatal error or warning from a crafted
// request): ?size[]= on ?img=, an array-valued admin field, a NUL byte in
// a notes filename.
[$arraySizeStatus] = http_get("$baseDepth/?img=loose.jpg&size[]=x");
check('?img= with an array-valued size no longer crashes (falls back to the default size)', $arraySizeStatus === 200);
$lyonQuery = '&folder=' . rawurlencode('Europe/France/Lyon');
[$arrayFieldStatus, $arrayFieldBody] = $depthPost($lyonQuery, ['action' => 'save_item', 'file' => ['a', 'b'], 'caption' => ['x'], 'web' => ['y']]);
check('an admin form field sent as an array is refused cleanly (no PHP warning, no crash)', $arrayFieldStatus === 200 && str_contains($arrayFieldBody, 'class="flash error"') && !str_contains($arrayFieldBody, 'Warning') && !str_contains($arrayFieldBody, 'Fatal'));
[$nulStatus, $nulBody] = $depthPost($lyonQuery, ['action' => 'save_notes', 'md_file' => "evil\0.md", 'content' => "{gallery}\nhi"]);
check('a NUL byte in a notes filename no longer crashes the admin', $nulStatus === 200 && !str_contains($nulBody, 'Fatal'));
check('...and the NUL was stripped, not used to truncate the name (no bare "evil" file)', !file_exists("$fxDepth/Europe/France/Lyon/evil"));

// Two admin "tabs" on one record: a stale save is refused, not silently
// overwritten; a save to a *different* record in the same folder is fine.
$hashFor = function ($body, $file) {
    return preg_match('/item-filename">[^<]*' . preg_quote($file, '/') . '<\/div>.*?<form method="post">.*?name="loaded_hash" value="([a-f0-9]{32})"/s', $body, $m) ? $m[1] : null;
};
[, $tabA] = http_get("$baseDepth/?admin$parisAdminQuery", [$depthAuth, "Cookie: $depthCookie"]);
$eiffelHashA = $hashFor($tabA, 'eiffel.jpg');
$louvreHashA = $hashFor($tabA, 'louvre.jpg');
check('the admin grid embeds a per-record fingerprint in each item form', $eiffelHashA !== null && $louvreHashA !== null && $eiffelHashA !== $louvreHashA);
[, $tabBSave] = $depthPost($parisAdminQuery, ['action' => 'save_item', 'file' => 'eiffel.jpg', 'caption' => 'Saved from tab B', 'loaded_hash' => $eiffelHashA]);
check('tab B saves eiffel with the fingerprint it loaded: accepted', str_contains($tabBSave, 'class="flash ok"'));
[, $tabAStale] = $depthPost($parisAdminQuery, ['action' => 'save_item', 'file' => 'eiffel.jpg', 'caption' => 'Tab A overwrite attempt', 'loaded_hash' => $eiffelHashA]);
check('tab A then saves eiffel from its now-stale page: refused with a clear message', str_contains($tabAStale, 'class="flash error"') && str_contains($tabAStale, 'was changed somewhere else'));
[, $afterStale] = http_get("$baseDepth/?folder=" . rawurlencode('Europe/France/Paris'), ["Cookie: $depthCookie"]);
check('...and the refused save changed nothing (tab B\'s caption survives)', str_contains($afterStale, 'Saved from tab B') && !str_contains($afterStale, 'Tab A overwrite attempt'));
[, $tabALouvre] = $depthPost($parisAdminQuery, ['action' => 'save_item', 'file' => 'louvre.jpg', 'caption' => 'Different photo, same folder', 'loaded_hash' => $louvreHashA]);
check('a stale page can still save a *different* photo in the same folder (no false conflict)', str_contains($tabALouvre, 'class="flash ok"'));
[, $tabAFresh] = http_get("$baseDepth/?admin$parisAdminQuery", [$depthAuth, "Cookie: $depthCookie"]);
[, $tabARetry] = $depthPost($parisAdminQuery, ['action' => 'save_item', 'file' => 'eiffel.jpg', 'caption' => 'Tab A, after reloading', 'loaded_hash' => $hashFor($tabAFresh, 'eiffel.jpg')]);
check('after reloading, the same edit goes through', str_contains($tabARetry, 'class="flash ok"'));
// Folder settings get the same protection.
[, $folderPage] = http_get("$baseDepth/?admin&folder=Europe", [$depthAuth, "Cookie: $depthCookie"]);
$folderHash = preg_match('/action" value="save_folder_identity">\s*<input type="hidden" name="loaded_hash" value="([a-f0-9]{32})"/', $folderPage, $fm) ? $fm[1] : null;
check('the folder settings form carries a fingerprint too', $folderHash !== null);
$depthPost('&folder=Europe', ['action' => 'save_folder_identity', 'display_name' => 'Europe 2026', 'dates' => 'Changed in tab B', 'loaded_hash' => $folderHash]);
[, $folderStale] = $depthPost('&folder=Europe', ['action' => 'save_folder_identity', 'display_name' => 'Europe 2026', 'dates' => 'Stale overwrite', 'loaded_hash' => $folderHash]);
check('a stale folder-settings save is refused too', str_contains($folderStale, 'class="flash error"') && str_contains($folderStale, 'was changed somewhere else'));
[, $folderAfter] = http_get("$baseDepth/");
check('...leaving the newer value in place', str_contains($folderAfter, 'Changed in tab B') && !str_contains($folderAfter, 'Stale overwrite'));

// Admin page pieces.
check('the Export section warns that the file records hidden items and GPS', str_contains($parisAdminBody, 'Handle the file with care') && str_contains($parisAdminBody, 'hidden'));
preg_match('#<aside class="notes-reference".*?</aside>#s', $depthAdminBody, $refM); $ref = $refM[0] ?? '';
check('the notes reference is on the Notes tab in full, once (not folded away, and not repeated inside every editor), never collapsed', $ref !== '' && substr_count($depthAdminBody, 'How notes work') === 1 && !str_contains($depthAdminBody, '<details class="admin-reference"') && !str_contains($ref, '<details'));
foreach (['Making a file count', 'Formatting', 'Directives', 'Attaching photos to a paragraph', 'Where a directive applies', 'Showing a directive as text', "If something doesn't work"] as $section) {
    $sections[] = str_contains(str_replace('&#039;', "'", $ref), $section);
}
check('[notes reference] it has every section: making a file count, formatting, the directives, attaching photos, where a directive applies, showing one as text, and troubleshooting', !in_array(false, $sections, true));
check('[notes reference] it has a "Notes and folders" section saying what works with folders (a mention, {group:}, {sold}, {color:}) and that {photos:} reaches only top-level photos', str_contains($ref, 'Notes and folders') && str_contains($ref, 'Only works in the <strong>top-level notes</strong>') && str_contains($ref, 'cover card ignores it'));
// {photos:} that cannot work in folder mode says so (it used to say "Unknown file", or fail silently).
$fxFN = new_fixture_dir('foldernotes');
mkdir("$fxFN/Paris");
make_jpeg("$fxFN/Paris/eiffel.jpg", 60, 60, 10, 10, 10);
make_jpeg("$fxFN/top.jpg", 60, 60, 50, 50, 50);
file_put_contents("$fxFN/n.md", "{gallery}\n\nA trip {photos: Paris/eiffel.jpg} {group: Tower}\n\nTop {photos: top.jpg} {group: Top Box}\n\nPlain text only, nothing to do with a missing file.\n");
file_put_contents("$fxFN/Paris/n.md", "{gallery}\n\nBoth {photos: eiffel.jpg} {group: Landmarks}\n");
write_patched_index("$fxFN/index.php", $indexContents, ['AUTO_GROUP_BY_FOLDER' => 'true']);
$portFN = find_free_port(); start_server($fxFN, $portFN);
[, $fnRoot] = http_get("http://127.0.0.1:$portFN/");
[, $fnParis] = http_get("http://127.0.0.1:$portFN/?folder=Paris");
check('[folder notes] a top-level {photos:} that names a photo inside a folder says so, instead of calling the file unknown', str_contains($fnRoot, 'Paris/eiffel.jpg&quot; is inside a folder') && !str_contains($fnRoot, 'Unknown file'));
check('[folder notes] ...and a {photos:} on a loose top-level photo still works (a labelled box)', str_contains($fnRoot, 'id="group-top-box"') && str_contains($fnRoot, '>Top Box'));
check('[folder notes] a {photos:} + {group:} in a folder\'s own notes work like the top level: a labelled box, the thumbnails under the paragraph, no notice', str_contains($fnParis, 'id="group-landmarks"') && str_contains($fnParis, '>Landmarks') && str_contains($fnParis, 'id="item-landmarks"') && !str_contains($fnParis, 'setup notice'));
[, $fnParisClean] = (function () use ($fxFN, $portFN) { file_put_contents("$fxFN/Paris/n.md", "{gallery}\n\nSeen the eiffel yet? {sold}\n"); usleep(300000); return http_get("http://127.0.0.1:$portFN/?folder=Paris"); })();
check('[folder notes] a folder note that just mentions a photo raises no notice and marks it sold', !str_contains($fnParisClean, 'setup notice') && str_contains($fnParisClean, 'sold-badge-corner'));
file_put_contents("$fxFN/Paris/n.md", "{gallery}\n\nNice view {photos: eiffel.jpg} {color: gold}\n");
usleep(300000);
[, $fnParisPlain] = http_get("http://127.0.0.1:$portFN/?folder=Paris");
check('[folder notes] a bare {photos:} in a folder note keeps the photo as its own card and colours it', (bool) preg_match('/<div class="card" id="group-eiffel-jpg" style="box-shadow: 0 0 0 3px gold inset;"/', $fnParisPlain));
file_put_contents("$fxFN/n.md", "{gallery}\n\nThe Paris leg {sold}\n\nTop {photos: top.jpg} {group: Top Box}\n");
usleep(300000);
[, $fnRoot2] = http_get("http://127.0.0.1:$portFN/");
check('[folder notes] a paragraph that mentions a folder marks its cover card sold (ribbon and class)', (bool) preg_match('/<div class="folder-cover sold" id="group-paris"[^>]*>\s*<a class="folder-cover-link"[^>]*><span class="sold-badge-corner">SOLD<\/span>/', $fnRoot2));

check('[notes reference] each of the five directives is explained in full next to its insert button', str_contains($ref, 'data-snippet="{group: Name}"') && str_contains($ref, 'data-snippet="{photos: a.jpg, b.jpg}"') && str_contains($ref, 'data-snippet="{color: #ff0000}"') && str_contains($ref, 'data-snippet="{sold}"') && str_contains($ref, 'data-snippet="{reserved}"') && str_contains($ref, "whatever they're called") && str_contains($ref, 'red SOLD ribbon') && str_contains($ref, 'orange tag') && str_contains($ref, '{held}'));
check('[notes reference] it explains the formatting that really works (bold, italic, underline, strikethrough, code, links, lists, quotes, code blocks) and that HTML is never run', str_contains($ref, '**bold**') && str_contains($ref, '__underline__') && str_contains($ref, '~~strikethrough~~') && str_contains($ref, '[words](https://example.com)') && str_contains($ref, '&gt; a quote') && str_contains($ref, 'never run'));
check('[notes reference] it explains the rules that trip people up: {gallery} on the first line, the page-title heading, a directive only affecting its own paragraph, and the blank-line mistake with its fix', str_contains($ref, 'first line must be exactly') && str_contains($ref, 'page title') && str_contains($ref, 'its own paragraph') && str_contains($ref, 'Take a look at my rack') && str_contains($ref, 'Remove the blank line'));
check('[notes reference] the Insert buttons exist only in the reference (five of them), not repeated in each editor', substr_count($ref, 'class="admin-insert-btn"') === 5 && substr_count(str_replace($ref, '', $depthAdminBody), 'class="admin-insert-btn"') === 0);
check('the page script no longer folds the notes reference away on a phone: it is shown in full everywhere', !str_contains($depthAdminBody, ".admin-reference") && !str_contains($depthAdminBody, "querySelectorAll('.notes-reference"));
check('a caption badge is a keyboard-reachable button that opens a popover on tap, not a tooltip only', str_contains($parisAfterCaption, 'role="button" tabindex="0" onclick="toggleCaptionPopover(this, event)"') && str_contains($parisAfterCaption, 'function toggleCaptionPopover(badge, e)') && !str_contains($parisAfterCaption, '.item-badge.caption-badge { pointer-events: none; }'));

// FOLDER_DISPLAY_MODE = 'inline': folders are only an organizing
// convenience, so a folder's own page shows everything beneath it as one
// flat run — no cover cards at any depth.
$fxInline = new_fixture_dir('depth-inline');
mkdir("$fxInline/Europe/France", 0777, true);
make_jpeg("$fxInline/Europe/intro.jpg", 60, 60, 20, 20, 20);
make_jpeg("$fxInline/Europe/France/eiffel.jpg", 60, 60, 30, 30, 30);
write_patched_index("$fxInline/index.php", $indexContents, [
    'AUTO_GROUP_BY_FOLDER' => 'true',
    'FOLDER_DISPLAY_MODE' => "'inline'",
]);
$portInline = find_free_port();
start_server($fxInline, $portInline);
[, $inlineRoot] = http_get("http://127.0.0.1:$portInline/");
check('inline mode: the root shows the folder as an inline box, not a cover card', str_contains($inlineRoot, 'class="group-box') && !str_contains($inlineRoot, '<div class="folder-cover"'));
[$inlineStatus, $inlinePage] = http_get("http://127.0.0.1:$portInline/?folder=Europe");
check('inline mode: ?folder=Europe still loads', $inlineStatus === 200);
check('inline mode: a folder\'s page is flat — everything beneath it as plain cards, no cover cards', str_contains($inlinePage, 'alt="Europe/intro.jpg"') && str_contains($inlinePage, 'alt="Europe/France/eiffel.jpg"') && !str_contains($inlinePage, '<div class="folder-cover"'));

// AUTO_GROUP_BY_FOLDER off (the default) with photos in subfolders: the
// gallery looks empty of them, so it says why — a count only, never a
// folder or file name.
$fxSubNotice = new_fixture_dir('subnotice');
mkdir("$fxSubNotice/TopSecretTrip/Day1", 0777, true);
make_jpeg("$fxSubNotice/loose.jpg", 60, 60, 10, 10, 10);
make_jpeg("$fxSubNotice/TopSecretTrip/a.jpg", 60, 60, 20, 20, 20);
make_jpeg("$fxSubNotice/TopSecretTrip/Day1/b.jpg", 60, 60, 30, 30, 30);
write_patched_index("$fxSubNotice/index.php", $indexContents, []);
$portSubNotice = find_free_port();
start_server($fxSubNotice, $portSubNotice);
[, $subNoticeBody] = http_get("http://127.0.0.1:$portSubNotice/");
check('photos in subfolders with AUTO_GROUP_BY_FOLDER off produce a setup notice with the right count', str_contains($subNoticeBody, '2 photos/videos were found in subfolders but aren&#039;t shown') && str_contains($subNoticeBody, 'AUTO_GROUP_BY_FOLDER'));
check('...which names no folder or file', !str_contains($subNoticeBody, 'TopSecretTrip') && !str_contains($subNoticeBody, 'Day1'));
check('...and the gallery itself still shows only the loose photo', str_contains($subNoticeBody, 'alt="loose.jpg"') && !str_contains($subNoticeBody, 'a.jpg') && !str_contains($subNoticeBody, 'b.jpg'));
check('the defaults fixture (a flat folder) gets no such notice', $mapOffBody !== '' && !str_contains($mapOffBody, 'found in subfolders'));

// ENABLE_VIDEO off (the default) with videos in a folder: the folder is
// invisible, so the banner has to say why — a count only, never a name.
// This is the "I made a Videos/ folder for the trip and nothing shows"
// case.
foreach (['grouping off' => 'false', 'grouping on' => 'true'] as $label => $grouping) {
    $fxVid = new_fixture_dir('vidnotice');
    mkdir("$fxVid/SecretVideos/Day1", 0777, true);
    make_jpeg("$fxVid/loose.jpg", 60, 60, 10, 10, 10);
    file_put_contents("$fxVid/SecretVideos/a.mp4", 'x');
    file_put_contents("$fxVid/SecretVideos/Day1/b.MOV", 'x');
    file_put_contents("$fxVid/loose.mov", 'x');
    write_patched_index("$fxVid/index.php", $indexContents, ['AUTO_GROUP_BY_FOLDER' => $grouping]);
    $portVid = find_free_port();
    start_server($fxVid, $portVid);
    [, $vidBody] = http_get("http://127.0.0.1:$portVid/");
    check("[video notice, $label] videos with ENABLE_VIDEO off produce a setup notice with the right count", str_contains($vidBody, '3 video files (.mov/.mp4) were found but aren&#039;t shown, because ENABLE_VIDEO is off') && str_contains($vidBody, 'setup notice'));
    check("[video notice, $label] ...which names no folder or file", !str_contains($vidBody, 'SecretVideos') && !str_contains($vidBody, 'Day1') && !str_contains($vidBody, 'a.mp4') && !str_contains($vidBody, 'loose.mov'));
    check("[video notice, $label] ...and the banner stays collapsed when there are photos to look at", str_contains($vidBody, '<details class="gallery-notices">'));
    check("[video notice, $label] ...and the videos really are not shown (the photo is)", str_contains($vidBody, 'alt="loose.jpg"') && http_get("http://127.0.0.1:$portVid/?folder=SecretVideos")[0] === 404);
}

// A video-only gallery: nothing at all to show, but the owner is told why.
$fxVidOnly = new_fixture_dir('vidonly');
mkdir("$fxVidOnly/Videos", 0777, true);
file_put_contents("$fxVidOnly/Videos/only.mp4", 'x');
write_patched_index("$fxVidOnly/index.php", $indexContents, []);
$portVidOnly = find_free_port();
start_server($fxVidOnly, $portVidOnly);
[, $vidOnlyBody] = http_get("http://127.0.0.1:$portVidOnly/");
check('[video notice] ...and, with nothing else to show, the banner is already open rather than collapsed', str_contains($vidOnlyBody, '<details class="gallery-notices" open>'));
check('[video notice] a gallery holding only a Videos/ folder says why it is empty (singular wording)', str_contains($vidOnlyBody, '1 video file (.mov/.mp4) was found but isn&#039;t shown, because ENABLE_VIDEO is off') && str_contains($vidOnlyBody, '1 setup notice'));

// With ENABLE_VIDEO on, nothing is ignored, so there is nothing to say.
$fxVidOn = new_fixture_dir('vidon');
mkdir("$fxVidOn/Videos", 0777, true);
file_put_contents("$fxVidOn/Videos/only.mp4", 'x');
file_put_contents("$fxVidOn/loose.mp4", 'x');
write_patched_index("$fxVidOn/index.php", $indexContents, ['ENABLE_VIDEO' => 'true']);
$portVidOn = find_free_port();
start_server($fxVidOn, $portVidOn);
[, $vidOnBody] = http_get("http://127.0.0.1:$portVidOn/");
check('[video notice] with ENABLE_VIDEO on there is no such notice', !str_contains($vidOnBody, 'ENABLE_VIDEO is off') && str_contains($vidOnBody, 'loose.mp4'));
check('[video notice] a folder with no videos at all gets no such notice', $subNoticeBody !== '' && !str_contains($subNoticeBody, 'ENABLE_VIDEO is off'));
check('[video notice] the defaults fixture, which does hold a video, does get it (that is the point)', str_contains($mapOffBody, 'ENABLE_VIDEO is off'));

// Root folder order: natural name order (Day 2 before Day 10, case-blind),
// not scan order — and folder mtime when PHOTO_SORT_ORDER = 'mtime'.
$fxFolderOrder = new_fixture_dir('folderorder');
foreach (['Day 10', 'Day 2', 'day 1'] as $i => $name) {
    mkdir("$fxFolderOrder/$name");
    make_jpeg("$fxFolderOrder/$name/p.jpg", 60, 60, 30 * $i + 10, 10, 10);
}
write_patched_index("$fxFolderOrder/index.php", $indexContents, ['AUTO_GROUP_BY_FOLDER' => 'true']);
$portFolderOrder = find_free_port();
start_server($fxFolderOrder, $portFolderOrder);
[, $folderOrderBody] = http_get("http://127.0.0.1:$portFolderOrder/");
$posDay1 = strpos($folderOrderBody, 'id="group-day-1"');
$posDay2 = strpos($folderOrderBody, 'id="group-day-2"');
$posDay10 = strpos($folderOrderBody, 'id="group-day-10"');
check('root folders sort naturally by name: day 1, Day 2, Day 10 (not scan order, not case-sensitive)', $posDay1 !== false && $posDay1 < $posDay2 && $posDay2 < $posDay10);

$fxFolderMtime = new_fixture_dir('foldermtime');
foreach (['Alpha', 'Bravo', 'Charlie'] as $name) {
    mkdir("$fxFolderMtime/$name");
    make_jpeg("$fxFolderMtime/$name/p.jpg", 60, 60, 40, 40, 40);
}
touch("$fxFolderMtime/Charlie", time() - 3000);
touch("$fxFolderMtime/Alpha", time() - 2000);
touch("$fxFolderMtime/Bravo", time() - 1000);
write_patched_index("$fxFolderMtime/index.php", $indexContents, ['AUTO_GROUP_BY_FOLDER' => 'true', 'PHOTO_SORT_ORDER' => "'mtime'"]);
$portFolderMtime = find_free_port();
start_server($fxFolderMtime, $portFolderMtime);
[, $folderMtimeBody] = http_get("http://127.0.0.1:$portFolderMtime/");
$mA = strpos($folderMtimeBody, 'id="group-alpha"');
$mB = strpos($folderMtimeBody, 'id="group-bravo"');
$mC = strpos($folderMtimeBody, 'id="group-charlie"');
check('with PHOTO_SORT_ORDER=mtime, root folders sort by the folder\'s own mtime, oldest first (Charlie, Alpha, Bravo)', $mC !== false && $mC < $mA && $mA < $mB);

/* ======================================================================= *
 * FIXTURE: "upgrade" — a 1.x install's stats survive the move to 2.0.
 * 1.x kept view counts in a plain .gallery-stats.json (or a SQLite file
 * with readable filenames); 2.0 stores them in guarded/hashed form. A
 * first request must import the old counts, not zero them, and must not
 * leave the readable old copies lying around.
 * ======================================================================= */

echo "== upgrade fixtures: 1.x view counts carried into 2.0 ==\n";

foreach (['json' => true, 'sqlite' => false] as $upgradeMode => $forceJsonMode) {
    $fxUp = new_fixture_dir("upgrade-$upgradeMode");
    make_jpeg("$fxUp/old-photo.jpg", 60, 60, 10, 10, 10);
    make_jpeg("$fxUp/other.jpg", 60, 60, 20, 20, 20);
    $patched = $indexContents;
    if ($forceJsonMode) {
        // Force the flat-file backend even though pdo_sqlite exists here.
        $patched = str_replace("in_array('sqlite', PDO::getAvailableDrivers(), true)", 'false', $patched, $replaced);
        if ($replaced !== 1) { echo "  FAIL  (setup) could not force JSON stats mode\n"; $failCount++; }
    }
    write_patched_index("$fxUp/index.php", $patched, ['TRACK_VIEWS' => 'true', 'SHOW_VIEW_COUNTS_PUBLICLY' => 'true']);
    if ($upgradeMode === 'json') {
        file_put_contents("$fxUp/.gallery-stats.json", json_encode(['pageviews' => 41, 'photos' => ['old-photo.jpg' => 7, 'other.jpg' => 3]]));
    } elseif (class_exists('PDO') && in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        $legacyPdo = new PDO('sqlite:' . "$fxUp/.gallery-stats.sqlite");
        $legacyPdo->exec('CREATE TABLE stats (key TEXT PRIMARY KEY, value INTEGER NOT NULL DEFAULT 0)');
        $legacyPdo->exec('CREATE TABLE photo_views (filename TEXT PRIMARY KEY, views INTEGER NOT NULL DEFAULT 0)');
        $legacyPdo->exec("INSERT INTO stats VALUES ('pageviews', 41)");
        $legacyPdo->exec("INSERT INTO photo_views VALUES ('old-photo.jpg', 7), ('other.jpg', 3)");
        $legacyPdo = null;
    } else {
        echo "  skip  SQLite upgrade fixture (no pdo_sqlite)\n";
        continue;
    }
    $portUp = find_free_port();
    start_server($fxUp, $portUp);
    [, $upBody] = http_get("http://127.0.0.1:$portUp/");
    check("[$upgradeMode] the old per-photo counts are imported, not zeroed", str_contains($upBody, 'data-photo="old-photo.jpg">&#128065; 7<') && str_contains($upBody, 'data-photo="other.jpg">&#128065; 3<'));
    check("[$upgradeMode] the old sitewide pageview total carries over (41, then this request makes 42)", str_contains($upBody, '42 visits'));
    $upTrack = json_decode(http_get("http://127.0.0.1:$portUp/?track=old-photo.jpg")[1], true);
    check("[$upgradeMode] counting continues from the imported total", ($upTrack['photoViews'] ?? null) === 8);
    if ($upgradeMode === 'json') {
        check('[json] the readable 1.x .gallery-stats.json is removed after import', !file_exists("$fxUp/.gallery-stats.json"));
        check('[json] the new store is a guarded PHP file', strncmp((string) @file_get_contents("$fxUp/.gallery-stats.php"), '<?php http_response_code(404); exit; ?>', 38) === 0);
    } else {
        $upBlob = (string) file_get_contents("$fxUp/.gallery-stats.sqlite") . (is_file("$fxUp/.gallery-stats.sqlite-wal") ? (string) file_get_contents("$fxUp/.gallery-stats.sqlite-wal") : '');
        check('[sqlite] no readable filename remains anywhere in the SQLite file after the upgrade (table dropped and VACUUMed)', !str_contains($upBlob, 'old-photo.jpg') && !str_contains($upBlob, 'other.jpg'));
    }
}

// If the new store can't be created (read-only folder), the 1.x counts must
// be left alone, not deleted after a failed import.
if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    $fxRo = new_fixture_dir('upgrade-readonly');
    make_jpeg("$fxRo/p.jpg", 60, 60, 10, 10, 10);
    $patchedRo = str_replace("in_array('sqlite', PDO::getAvailableDrivers(), true)", 'false', $indexContents);
    write_patched_index("$fxRo/index.php", $patchedRo, ['TRACK_VIEWS' => 'true', 'SHOW_VIEW_COUNTS_PUBLICLY' => 'true']);
    file_put_contents("$fxRo/.gallery-stats.json", json_encode(['pageviews' => 9, 'photos' => ['p.jpg' => 4]]));
    chmod($fxRo, 0555);
    $portRo = find_free_port();
    start_server($fxRo, $portRo);
    http_get("http://127.0.0.1:$portRo/");
    check('a read-only folder: the 1.x stats file is NOT deleted when the new store can\'t be written', is_file("$fxRo/.gallery-stats.json") && !is_file("$fxRo/.gallery-stats.php"));
    chmod($fxRo, 0755);
}

/* ======================================================================= *
 * The REAL 1.1.1 script builds up view counts; then 2.0.0 is dropped over it.
 * (Skipped if the v1.1.1 tag isn't in this checkout.) Unlike the imitation
 * upgrade fixtures above, this is the actual old code writing the actual old
 * data files.
 * ======================================================================= */

echo "== upgrade from the real v1.1.1 script ==\n";
$v1Source = (string) @shell_exec('git -C ' . escapeshellarg(dirname(__DIR__)) . ' show v1.1.1:index.php 2>/dev/null');
if (strlen($v1Source) < 1000) {
    echo "  skip  real v1.1.1 upgrade (the v1.1.1 tag isn't available in this checkout)\n";
} else {
    foreach (['sqlite', 'json'] as $v1Flavour) {
        $fxReal = new_fixture_dir("upgrade-real-$v1Flavour");
        make_jpeg("$fxReal/a.jpg", 40, 40, 10, 10, 10); make_jpeg("$fxReal/b.jpg", 40, 40, 20, 20, 20);
        $oldCode = str_replace("define('TRACK_VIEWS', false)", "define('TRACK_VIEWS', true)", $v1Source);
        if ($v1Flavour === 'json') $oldCode = str_replace("in_array('sqlite', PDO::getAvailableDrivers(), true)", 'false', $oldCode);
        file_put_contents("$fxReal/index.php", $oldCode);
        $portReal = find_free_port(); start_server($fxReal, $portReal); $baseReal = "http://127.0.0.1:$portReal";
        for ($i = 0; $i < 3; $i++) http_get("$baseReal/");
        for ($i = 0; $i < 5; $i++) http_get("$baseReal/?track=a.jpg");
        for ($i = 0; $i < 2; $i++) http_get("$baseReal/?track=b.jpg");
        [, $v1Page] = http_get("$baseReal/");
        $v1Files = array_values(array_filter(scandir($fxReal), fn($f) => str_starts_with($f, '.gallery-stats')));
        check("[real 1.1.1, $v1Flavour] the old script really recorded 5 and 2 views and wrote its own stats file", str_contains($v1Page, 'data-photo="a.jpg">&#128065; 5<') && str_contains($v1Page, 'data-photo="b.jpg">&#128065; 2<') && $v1Files !== []);
        write_patched_index("$fxReal/index.php", $indexContents, ['TRACK_VIEWS' => 'true', 'SHOW_VIEW_COUNTS_PUBLICLY' => 'true']);   // the upgrade: same folder, same data
        [, $v2Page] = http_get("$baseReal/");
        check("[real 1.1.1, $v1Flavour] after swapping in 2.0.0, both per-photo counts carry over exactly (5 and 2)", str_contains($v2Page, 'data-photo="a.jpg">&#128065; 5<') && str_contains($v2Page, 'data-photo="b.jpg">&#128065; 2<'));
        check("[real 1.1.1, $v1Flavour] ...and the sitewide total carries over (4 loads under 1.1.1, plus this one visit)", str_contains($v2Page, '· 5 visits'));
        $leftover = ''; foreach (scandir($fxReal) as $f) if (str_starts_with($f, '.gallery-stats') && !str_ends_with($f, '.php')) $leftover .= (string) file_get_contents("$fxReal/$f");
        check("[real 1.1.1, $v1Flavour] ...with no readable photo name left in any stats file, and the old JSON file gone", !str_contains($leftover, 'a.jpg') && !str_contains($leftover, 'b.jpg') && !is_file("$fxReal/.gallery-stats.json"));
    }
}

/* ======================================================================= *
 * FIXTURE: "gps" — EXIF GPS auto-fill in the admin, for JPEG and (where
 * Imagick can write/read HEIC) HEIC. Never covered before: 48°51'30.24"N
 * 2°17'40.2"E is 48.8584, 2.2945; the southern/western pair checks the
 * sign handling.
 * ======================================================================= */

echo "== gps fixture: EXIF location auto-fill (JPEG, HEIC) ==\n";

$fxGps = new_fixture_dir('gps');
make_gps_jpeg("$fxGps/north-east.jpg", 'N', [[48, 1], [51, 1], [3024, 100]], 'E', [[2, 1], [17, 1], [402, 10]]);
make_gps_jpeg("$fxGps/south-west.jpg", 'S', [[33, 1], [51, 1], [0, 1]], 'W', [[151, 1], [12, 1], [0, 1]]);
make_jpeg("$fxGps/no-gps.jpg", 60, 60, 80, 80, 80);
make_iphone_gps_jpeg("$fxGps/iphone.jpg", 'N', [37, 46, 2964], 'W', [122, 25, 984]);   // San Francisco
$heicOk = false;
if (class_exists('Imagick') && count(Imagick::queryFormats('HEIC')) > 0) {
    try {
        $heicSrc = new Imagick("$fxGps/north-east.jpg");
        $heicSrc->setImageFormat('heic');
        $heicSrc->writeImage("$fxGps/phone.heic");
        $heicOk = is_file("$fxGps/phone.heic");
    } catch (Exception $e) {
        $heicOk = false;
    }
}
write_patched_index("$fxGps/index.php", $indexContents, [
    'ADMIN_PASSWORD' => "'test-admin-pw-123'",
    'ENABLE_PHOTO_LOCATION' => 'true',
    'ENABLE_THUMBNAIL_CACHE' => 'true',
]);
$portGps = find_free_port();
start_server($fxGps, $portGps);
[, $gpsAdmin] = http_get("http://127.0.0.1:$portGps/?admin", ['Authorization: Basic ' . base64_encode('admin:test-admin-pw-123')]);
$gpsCard = function ($body, $name) {
    return preg_match('/<div class="item-editor"[^>]*>(?:(?!<div class="item-editor").)*?' . preg_quote($name, '/') . '.*?<\/form>/s', $body, $m) ? $m[0] : '';
};
check('a JPEG\'s EXIF GPS pre-fills the admin\'s location fields (N/E)', str_contains($gpsCard($gpsAdmin, 'north-east.jpg'), '48.8584') && str_contains($gpsCard($gpsAdmin, 'north-east.jpg'), '2.2945'));
check('southern and western hemispheres come out negative', str_contains($gpsCard($gpsAdmin, 'south-west.jpg'), '-33.85') && str_contains($gpsCard($gpsAdmin, 'south-west.jpg'), '-151.2'));
check('an iPhone-style (big-endian EXIF, extra tags) JPEG pre-fills too: San Francisco is 37.7749, -122.4194 (west is negative)', str_contains($gpsCard($gpsAdmin, 'iphone.jpg'), '37.7749') && str_contains($gpsCard($gpsAdmin, 'iphone.jpg'), '-122.4194'));
check('a JPEG with no GPS block gets no pre-filled location', !preg_match('/value="-?\d+\.\d+"/', $gpsCard($gpsAdmin, 'no-gps.jpg')));
if ($heicOk) {
    check('a HEIC\'s EXIF GPS pre-fills the admin\'s location fields too', str_contains($gpsCard($gpsAdmin, 'phone.heic'), '48.8584') && str_contains($gpsCard($gpsAdmin, 'phone.heic'), '2.2945'));
} else {
    echo "  skip  HEIC GPS auto-fill (this PHP's Imagick can't write HEIC to build a fixture)\n";
}

/* ======================================================================= *
 * RECIPE: a trip album. Followed as a person would, through the page's own
 * forms (see the "act like a browser" helpers): turn on the settings the
 * README names, sign in to ?admin, fill in a folder's settings, caption a
 * photo with its auto-filled GPS, opt a folder into location sharing, pick
 * a cover, and check what a visitor — and a pasted link — then sees.
 * ======================================================================= */

echo "== locations: turning a folder on publishes what it can; opting out stays out ==\n";

$fxGps = new_fixture_dir('gps-import');
mkdir("$fxGps/Rome");
$romeGps = [['N', [[41, 1], [53, 1], [0, 1]], 'E', [[12, 1], [29, 1], [0, 1]]], ['N', [[41, 1], [54, 1], [0, 1]], 'E', [[12, 1], [30, 1], [0, 1]]], ['N', [[41, 1], [55, 1], [0, 1]], 'E', [[12, 1], [31, 1], [0, 1]]]];
foreach (['colosseum', 'hotel', 'cleared'] as $n => $name) make_gps_jpeg("$fxGps/Rome/$name.jpg", ...$romeGps[$n]);
make_jpeg("$fxGps/Rome/plain.jpg", 60, 60, 1, 2, 3);
write_patched_index("$fxGps/index.php", $indexContents, [
    'AUTO_GROUP_BY_FOLDER' => 'true', 'ENABLE_PHOTO_LOCATION' => 'true', 'ENABLE_MAP' => 'true', 'ADMIN_PASSWORD' => "'gps-admin-pw'",
]);
$portGps = find_free_port(); start_server($fxGps, $portGps); $baseGps = "http://127.0.0.1:$portGps";
$gpsAuth = 'Authorization: Basic ' . base64_encode('admin:gps-admin-pw');
[, $gAdmin, $gAdminHd] = http_get("$baseGps/?admin&folder=Rome", [$gpsAuth]);
$gpsHdrs = [$gpsAuth, 'Cookie: ' . extract_cookie_header($gAdminHd)];
$romeUrl = "$baseGps/?admin&folder=Rome";
$gpsRomeFile = fn ($page, $file) => (function ($h) use ($file) { foreach (html_post_forms($h) as $f) if (str_contains($f['html'], 'name="file" value="' . $file . '"') && str_contains($f['html'], 'save_item')) return $f['html']; return ''; })($page);
check('[gps import] before the switch: three photos have GPS, the admin warns and nothing is public', str_contains($gAdmin, '3 photos here have GPS in the file') && !str_contains(http_get("$baseGps/")[1], 'trip-map-preview'));
// The owner opts one photo out (hotel) and clears another (cleared) *before* turning the folder on.
[, $r] = user_submit($romeUrl, $gAdmin, 'name="file" value="hotel.jpg"', ['loc_visibility' => 'hide'], $gpsHdrs);
check('[gps import] "Always hide" on a photo saves (its coordinates are kept, just never shown)', str_contains($r, 'class="flash ok"'));
[, $gAdmin] = http_get($romeUrl, $gpsHdrs);
[, $r] = user_submit($romeUrl, $gAdmin, 'name="file" value="cleared.jpg"', ['lat' => '', 'lng' => '', 'loc_label' => ''], $gpsHdrs);
check('[gps import] clearing a photo\'s coordinates saves', str_contains($r, 'class="flash ok"'));
[, $gAdmin] = http_get($romeUrl, $gpsHdrs);
[, $r] = user_submit($romeUrl, $gAdmin, 'value="toggle_location_enabled"', ['enabled' => '1'], $gpsHdrs);
check('[gps import] turning the switch on reports how many photos now show a location', str_contains($r, 'class="flash ok"') && str_contains($r, '1 photo has GPS in the file and now shows a location'));
$items = read_data_file("$fxGps/Rome/.gallery-items.php")['items'] ?? [];
check('[gps import] the unhidden photo got its GPS stored (41.883, 12.483)', abs(($items['colosseum.jpg']['location']['lat'] ?? 0) - 41.8833) < 0.001 && abs(($items['colosseum.jpg']['location']['lng'] ?? 0) - 12.4833) < 0.001);
check('[gps import] a photo with no GPS is marked checked and given no location', !isset($items['plain.jpg']['location']) && !empty($items['plain.jpg']['gpsChecked']));
$romePub = http_get("$baseGps/?folder=Rome")[1];
check('[gps import] visitors see the imported photo\'s location badge and the map', str_contains(http_get("$baseGps/")[1], 'trip-map-preview') && substr_count($romePub, 'location-badge') === 1);
$mapPins = (function ($h) { preg_match('/var clusters = (\[.*?\]);/s', $h, $m); return json_decode($m[1] ?? '[]', true); })(http_get("$baseGps/")[1]);
check('[gps import] ...only that photo is on the map: the hidden one and the cleared one stay out', count($mapPins) === 1 && str_contains(json_encode($mapPins), 'colosseum.jpg') && !str_contains(json_encode($mapPins), 'hotel.jpg') && !str_contains(json_encode($mapPins), 'cleared.jpg'));
// Opening the folder again (which also picks up later uploads) must not resurrect the cleared location.
[, $gAdmin] = http_get($romeUrl, $gpsHdrs);
check('[gps import] re-opening the folder does not bring a deliberately cleared location back', str_contains($gpsRomeFile($gAdmin, 'cleared.jpg'), 'name="lat" value=""'));
// A photo uploaded after the switch is on is picked up the next time the owner opens the folder.
make_gps_jpeg("$fxGps/Rome/late.jpg", 'N', [[41, 1], [56, 1], [0, 1]], 'E', [[12, 1], [32, 1], [0, 1]]);
check('[gps import] a photo uploaded later is not public until the owner next opens the folder...', substr_count(http_get("$baseGps/?folder=Rome")[1], 'location-badge') === 1);
[, $gAdmin] = http_get($romeUrl, $gpsHdrs);
check('[gps import] ...and then it is', substr_count(http_get("$baseGps/?folder=Rome")[1], 'location-badge') === 2);
// The switch asks first, and turning it off keeps the data.
[, $r] = user_submit($romeUrl, $gAdmin, 'value="toggle_location_enabled"', ['enabled' => '0'], $gpsHdrs);
check('[gps import] turning it off hides every location again but keeps the stored coordinates', str_contains($r, 'Saved coordinates are kept') && substr_count(http_get("$baseGps/?folder=Rome")[1], 'location-badge') === 0 && abs((read_data_file("$fxGps/Rome/.gallery-items.php")['items']['colosseum.jpg']['location']['lat'] ?? 0) - 41.8833) < 0.001);

echo "== the admin always sees their own hidden items, with no private-view password needed ==\n";

$fxAr = new_fixture_dir('adminreveal');
mkdir("$fxAr/Paris");
make_jpeg("$fxAr/Paris/visible.jpg", 600, 400, 200, 80, 60); make_jpeg("$fxAr/Paris/hidden.jpg", 600, 400, 60, 80, 200);
write_data_file("$fxAr/Paris/.gallery-items.php", ['items' => ['hidden.jpg' => ['restriction' => 'private', 'caption' => 'secret one']], 'groups' => []]);
file_put_contents("$fxAr/.gallery-config.php", cfg_text(['ADMIN_PASSWORD' => 'reveal-test-pw-12', 'AUTO_GROUP_BY_FOLDER' => true]));
copy(__DIR__ . '/../index.php', "$fxAr/index.php");
$portAr = find_free_port(); start_server($fxAr, $portAr); $baseAr = "http://127.0.0.1:$portAr";
$arThumb = "$baseAr/?img=Paris%2Fhidden.jpg&size=thumb";
check('[admin reveal] (setup) anonymous visitors cannot load a hidden photo\'s thumbnail', http_get($arThumb)[0] === 404);
[, , $arLoginHd] = http_post("$baseAr/?admin", ['login_password' => 'reveal-test-pw-12'], [], false);
$arCookie = extract_cookie_header($arLoginHd);
check('[admin reveal] a logged-in admin CAN load a hidden photo\'s thumbnail even though no private-view password exists (this was a 404: broken thumbnails in the admin)', str_contains($arCookie, 'gallery_admin_') && http_get($arThumb, ["Cookie: $arCookie"])[0] === 200 && http_get("$baseAr/?img=Paris%2Fvisible.jpg&size=thumb", ["Cookie: $arCookie"])[0] === 200);
[, $arAdminPage] = http_get("$baseAr/?admin&folder=Paris", ["Cookie: $arCookie"]);
check('[admin reveal] ...and the admin page lists that hidden photo, marked hidden', str_contains($arAdminPage, 'hidden.jpg') && str_contains($arAdminPage, 'class="hid"'));
check('[admin reveal] the admin also sees the hidden photo when browsing the public gallery; an anonymous visitor does not', str_contains(http_get("$baseAr/?folder=Paris", ["Cookie: $arCookie"])[1], 'hidden.jpg') && !str_contains(http_get("$baseAr/?folder=Paris")[1], 'hidden.jpg'));
[, , $arPageHd] = http_get("$baseAr/?folder=Paris", ["Cookie: $arCookie"]);
check('[admin reveal] a page rendered for the admin is private and uncached, like any revealed page', (bool) array_filter($arPageHd, fn($h) => stripos($h, 'Cache-Control: private, no-store') === 0));
$arRevealName = ''; foreach ($arLoginHd as $h) if (preg_match('/^Set-Cookie: (gallery_reveal_[a-f0-9]+)=/i', $h, $m)) $arRevealName = $m[1];
check('[admin reveal] the cookie that does it is a per-gallery token, not something a visitor can make up: a forged value, or the token another gallery or password would make, gets nothing', $arRevealName !== '' && http_get($arThumb, ["Cookie: $arRevealName=1"])[0] === 404 && http_get($arThumb, ["Cookie: $arRevealName=" . hash('sha256', '')])[0] === 404 && http_get($arThumb, ["Cookie: $arRevealName=" . hash('sha256', 'admin-reveal|some-other-password|' . __DIR__)])[0] === 404);
// Basic auth for scripts reveals too (a valid admin request sets it).
[, , $arBasicHd] = http_get("$baseAr/?admin", ['Authorization: Basic ' . base64_encode('x:reveal-test-pw-12')]);
check('[admin reveal] an admin request made with a Basic header (a script) is given the same cookie', http_get($arThumb, ['Cookie: ' . extract_cookie_header($arBasicHd)])[0] === 200);
// Logging out takes it away.
[, $arAdminPg2] = http_get("$baseAr/?admin", ["Cookie: $arCookie"]);
$arLogout = []; foreach (html_post_forms($arAdminPg2) as $f) if (str_contains($f['html'], 'value="logout"')) $arLogout = form_default_fields($f['html']);
[, , $arOutHd] = http_post("$baseAr/?admin", $arLogout, ["Cookie: $arCookie"], false);
check('[admin reveal] logging out clears that cookie too', (bool) array_filter($arOutHd, fn($h) => stripos($h, "Set-Cookie: $arRevealName=") === 0 && stripos($h, '1970') !== false));
// Changing the admin password keeps the admin revealed (a new token) and retires the old one.
[, , $arLogin2Hd] = http_post("$baseAr/?admin", ['login_password' => 'reveal-test-pw-12'], [], false);
$arC2 = extract_cookie_header($arLogin2Hd);
[, $arSet] = http_get("$baseAr/?admin", ["Cookie: $arC2"]); $arF = form_default_fields((function ($pg) { foreach (html_post_forms($pg) as $f) if (str_contains($f['html'], 'value="save_site_settings"')) return $f['html']; return ''; })($arSet));
[, $arPwBody, $arPwHd] = http_post("$baseAr/?admin", ['csrf_token' => $arF['csrf_token'], 'action' => 'save_site_settings', 'config_hash' => $arF['config_hash'], 'pw_admin' => 'a-brand-new-admin-pw', 'pw_admin2' => 'a-brand-new-admin-pw'], ["Cookie: $arC2", 'X-Requested-With: gallery-fetch'], false);
$arC3 = extract_cookie_header($arPwHd);
$arPwJson = json_decode($arPwBody, true);
check('[admin reveal] changing the admin password through the page\'s script works', ($arPwJson['ok'] ?? null) === true, (string) $arPwBody);
check('[admin reveal] ...and hands the admin a fresh reveal token in the same response', str_contains($arC3, $arRevealName . '='), $arC3);
check('[admin reveal] ...which reveals hidden photos', http_get($arThumb, ["Cookie: $arC3"])[0] === 200);
check('[admin reveal] ...while the token from the old password no longer does', http_get($arThumb, ["Cookie: $arRevealName=" . hash('sha256', 'admin-reveal|reveal-test-pw-12|' . $fxAr)])[0] === 404);
// A gallery set up in the browser: the person who sets the password is revealed at once.
$fxAr2 = new_fixture_dir('adminreveal-setup');
mkdir("$fxAr2/Paris"); make_jpeg("$fxAr2/Paris/hidden.jpg", 300, 200, 1, 2, 3);
write_data_file("$fxAr2/Paris/.gallery-items.php", ['items' => ['hidden.jpg' => ['restriction' => 'private']], 'groups' => []]);
file_put_contents("$fxAr2/.gallery-config.php", cfg_text(['AUTO_GROUP_BY_FOLDER' => true]));
copy(__DIR__ . '/../index.php', "$fxAr2/index.php");
$portAr2 = find_free_port(); start_server($fxAr2, $portAr2); touch("$fxAr2/index.php", time());
[, , $arClaimHd] = http_post("http://127.0.0.1:$portAr2/?admin", ['setup_password' => 'first-run-admin-pw-1', 'setup_password2' => 'first-run-admin-pw-1'], [], false);
check('[admin reveal] a person who has just set their admin password in the first-run page can already see their hidden photos', http_get("http://127.0.0.1:$portAr2/?img=Paris%2Fhidden.jpg&size=thumb")[0] === 404 && http_get("http://127.0.0.1:$portAr2/?img=Paris%2Fhidden.jpg&size=thumb", ['Cookie: ' . extract_cookie_header($arClaimHd)])[0] === 200);

echo "== first-run setup: choosing the admin password in the browser, for five minutes after upload ==\n";

$fxSetup = new_fixture_dir('setup');
make_jpeg("$fxSetup/photo.jpg", 60, 60, 10, 10, 10);
copy(__DIR__ . '/../index.php', "$fxSetup/index.php");
$portSetup = find_free_port(); start_server($fxSetup, $portSetup); $baseSetup = "http://127.0.0.1:$portSetup";   // the harness ages index.php: "uploaded an hour ago"
$setupCfg = "$fxSetup/.gallery-config.php"; $setupIndex = "$fxSetup/index.php";
check('[setup] an index.php uploaded long ago with no password: ?admin does not exist (404), as before', http_get("$baseSetup/?admin")[0] === 404);
touch($setupIndex, time() - 290);
[$sst, $sbody, $shd] = http_get("$baseSetup/?admin");
check('[setup] just after upload (inside the five minutes) ?admin offers to choose a password: a form, never indexed, never cached', $sst === 200 && str_contains($sbody, 'Set up your gallery') && str_contains($sbody, 'name="setup_password"') && str_contains($sbody, 'noindex') && (bool) array_filter($shd, fn($h) => stripos($h, 'Cache-Control: no-store') === 0) && (bool) array_filter($shd, fn($h) => stripos($h, 'X-Robots-Tag: noindex') === 0));
check('[setup] ...and it says plainly that whoever opens it first sets the password, and that it closes by itself', str_contains($sbody, 'Whoever opens this page first') && str_contains($sbody, '5 minutes'));
check('[setup] ...and nothing on the public page advertises it', !str_contains(http_get("$baseSetup/")[1], 'Set up your gallery') && !str_contains(http_get("$baseSetup/")[1], 'name="setup_password"') && !str_contains(http_get("$baseSetup/")[1], 'href="?admin"'));
touch($setupIndex, time() - 310);
check('[setup] after the five minutes it is gone again (404)', http_get("$baseSetup/?admin")[0] === 404);
file_put_contents("$fxSetup/.gallery-setup", '');
check('[setup] an empty .gallery-setup file next to index.php reopens it, for coming back later', str_contains(http_get("$baseSetup/?admin")[1], 'name="setup_password"'));
// Bad input writes nothing.
[, $rMismatch] = http_post("$baseSetup/?admin", ['setup_password' => 'a-good-long-password', 'setup_password2' => 'another-long-password']);
[, $rShort] = http_post("$baseSetup/?admin", ['setup_password' => 'short', 'setup_password2' => 'short']);
check('[setup] two passwords that differ, or one under 12 characters, are refused with the reason and nothing is written', (str_contains($rMismatch, "don't match") || str_contains($rMismatch, 'don&#039;t match')) && str_contains($rShort, 'at least 12 characters') && !file_exists($setupCfg));
// A settings file that exists but isn't valid is never overwritten.
file_put_contents($setupCfg, $cfgGuard . "{\"ENABLE_VIDEO\": true,\n");
$brokenBytes = (string) file_get_contents($setupCfg);
[, $rBroken] = http_post("$baseSetup/?admin", ['setup_password' => 'a-good-long-password', 'setup_password2' => 'a-good-long-password']);
check('[setup] a settings file that is not valid is never overwritten: it says so and leaves the file exactly as it was', str_contains($rBroken, 'isn&#039;t valid') && (string) file_get_contents($setupCfg) === $brokenBytes);
// Settings that were already there are kept when the password is added.
file_put_contents($setupCfg, cfg_text(['ENABLE_VIDEO' => true, 'TRACK_VIEWS' => true]));
$claimPw = "my 'first' admin password // with, odd } chars";
[$cst, , $chd] = http_post("$baseSetup/?admin", ['setup_password' => $claimPw, 'setup_password2' => $claimPw], [], false);
$claimLoc = ''; foreach ($chd as $h) if (stripos($h, 'Location:') === 0) $claimLoc = trim(substr($h, 9));
$claimCookie = extract_cookie_header($chd);
check('[setup] choosing a password redirects into the admin\'s Site settings, already signed in (a session cookie)', $cst === 303 && $claimLoc === '?admin#site' && str_contains($claimCookie, 'gallery_admin_'));
$claimCfg = cfg_read($setupCfg); ksort($claimCfg);
check('[setup] the password is stored in the settings file next to the settings that were already there, and nowhere else', $claimCfg === ['ADMIN_PASSWORD' => $claimPw, 'ENABLE_VIDEO' => true, 'TRACK_VIEWS' => true] && (fileperms($setupCfg) & 0o077) === 0 && str_starts_with((string) file_get_contents($setupCfg), $cfgGuard));
[, $claimedPage] = http_get("$baseSetup/?admin", ["Cookie: $claimCookie"]);
check('[setup] with that session the admin opens with no further password, and says it is set and where it is kept', str_contains($claimedPage, 'Gallery Admin') && str_contains($claimedPage, 'Your admin password is set'));
check('[setup] the .gallery-setup file has done its job and is removed', !file_exists("$fxSetup/.gallery-setup"));
// Closed for good.
touch($setupIndex, time());
file_put_contents("$fxSetup/.gallery-setup", '');
[, $loginAgain] = http_get("$baseSetup/?admin");
$beforeSecond = (string) file_get_contents($setupCfg);
[, $second] = http_post("$baseSetup/?admin", ['setup_password' => 'an-attackers-password-1', 'setup_password2' => 'an-attackers-password-1']);
check('[setup] once a password exists, setup never appears again, even with a fresh index.php and a .gallery-setup file: it is the login form', str_contains($loginAgain, 'name="login_password"') && !str_contains($loginAgain, 'name="setup_password"'));
check('[setup] ...and a second visitor posting a new password changes nothing', !str_contains($second, 'Your admin password is set') && (string) file_get_contents($setupCfg) === $beforeSecond && cfg_read($setupCfg)['ADMIN_PASSWORD'] === $claimPw);
check('[setup] ...the chosen password (with quotes, slashes, a comma and a brace in it) logs in through the form', http_post("$baseSetup/?admin", ['login_password' => $claimPw], [], false)[0] === 303 && http_post("$baseSetup/?admin", ['login_password' => 'an-attackers-password-1'], [], false)[0] === 200);
unlink("$fxSetup/.gallery-setup");
// An unwritable folder: say so and hand over the exact file.
$fxSetupRo = new_fixture_dir('setup-ro');
make_jpeg("$fxSetupRo/a.jpg", 60, 60, 1, 2, 3);
copy(__DIR__ . '/../index.php', "$fxSetupRo/index.php");
$portSetupRo = find_free_port(); start_server($fxSetupRo, $portSetupRo);
touch("$fxSetupRo/index.php", time());
chmod($fxSetupRo, 0555);
[, $roSetup] = http_post("http://127.0.0.1:$portSetupRo/?admin", ['setup_password' => 'read-only-folder-password', 'setup_password2' => 'read-only-folder-password']);
chmod($fxSetupRo, 0775);
check('[setup] when the folder can\'t be written it says so, saves nothing, and shows the exact settings file to create by hand', str_contains($roSetup, 'isn&#039;t writable') && str_contains($roSetup, '&quot;ADMIN_PASSWORD&quot;: &quot;read-only-folder-password&quot;') && !file_exists("$fxSetupRo/.gallery-config.php"));

echo "== settings file: .gallery-config.php overrides the defaults and survives an update ==\n";

$fxCfg = new_fixture_dir('config');
make_jpeg("$fxCfg/photo.jpg", 60, 60, 10, 10, 10);
make_minimal_mp4("$fxCfg/clip.mp4", 2);
mkdir("$fxCfg/Sub"); make_jpeg("$fxCfg/Sub/in.jpg", 60, 60, 20, 20, 20);
copy(__DIR__ . '/../index.php', "$fxCfg/index.php");
$portCfg = find_free_port(); start_server($fxCfg, $portCfg); $baseCfg = "http://127.0.0.1:$portCfg";
$cfgHome = fn () => http_get("$baseCfg/")[1];
$h0 = $cfgHome();
check('[settings file] with no settings file, the defaults apply (video off, no admin, no subfolders, no title link to the admin)', !str_contains($h0, 'clip.mp4') && http_get("$baseCfg/?admin")[0] === 404 && !str_contains($h0, 'href="?admin"'));
file_put_contents("$fxCfg/.gallery-config.php", cfg_text(['ENABLE_VIDEO' => true, 'ADMIN_PASSWORD' => 'cfg-pw-123', 'AUTO_GROUP_BY_FOLDER' => true, 'TRACK_VIEWS' => true]));
$h1 = $cfgHome();
check('[settings file] a .gallery-config.php beside index.php overrides the defaults (video shown, folders on, admin exists, title links to it)', str_contains($h1, 'alt="clip.mp4"') && str_contains($h1, 'id="group-sub"') && str_contains($h1, 'href="?admin"') && http_get("$baseCfg/?admin")[0] === 200);
check('[settings file] ...and the password in it really works for the admin login', str_contains(http_post("$baseCfg/?admin", ['login_password' => 'cfg-pw-123'])[1] . http_get("$baseCfg/?admin", ['Authorization: Basic ' . base64_encode('x:cfg-pw-123')])[1], 'Gallery Admin'));
check('[settings file] a direct request for the file answers 404 and shows nothing', (function () use ($baseCfg) { [$st, $b] = http_get("$baseCfg/.gallery-config.php"); return $st === 404 && !str_contains($b, 'cfg-pw-123') && !str_contains($b, 'ADMIN_PASSWORD'); })());
// An update: index.php is replaced by a fresh copy. The settings stay.
copy(__DIR__ . '/../index.php', "$fxCfg/index.php");
check('[settings file] replacing index.php with a fresh copy (an update) keeps every setting', str_contains($cfgHome(), 'alt="clip.mp4"') && str_contains($cfgHome(), 'id="group-sub"') && http_get("$baseCfg/?admin")[0] === 200);
// The settings file beats an edit made in index.php itself: someone changed the
// defaults in index.php (video off, folders off here would be the defaults, so
// flip the other way), and the file says otherwise.
$edited = str_replace(["gallery_setting('ENABLE_VIDEO', false)", "gallery_setting('AUTO_GROUP_BY_FOLDER', false)"], ["gallery_setting('ENABLE_VIDEO', true)", "gallery_setting('AUTO_GROUP_BY_FOLDER', true)"], $indexContents, $editedCount);
file_put_contents("$fxCfg/.gallery-config.php", cfg_text(['ENABLE_VIDEO' => false, 'AUTO_GROUP_BY_FOLDER' => false]));
file_put_contents("$fxCfg/index.php", $edited);
$hEdit = $cfgHome();
check('[settings file] it wins over a value edited into index.php (file says off, index.php says on: off)', $editedCount === 2 && !str_contains($hEdit, 'clip.mp4') && !str_contains($hEdit, 'id="group-sub"'));
unlink("$fxCfg/.gallery-config.php");
check('[settings file] ...and without the file, the edited index.php applies as before (on)', str_contains($cfgHome(), 'alt="clip.mp4"'));
file_put_contents("$fxCfg/.gallery-config.php", cfg_text(['ENABLE_VIDEO' => true, 'ADMIN_PASSWORD' => 'cfg-pw-123', 'AUTO_GROUP_BY_FOLDER' => true, 'TRACK_VIEWS' => true]));
copy(__DIR__ . '/../index.php', "$fxCfg/index.php");
// Anything wrong in the file is reported, never fatal, and the rest still applies.
file_put_contents("$fxCfg/.gallery-config.php", cfg_text(['ENABLE_VIDDEO' => true, 'TRACK_VIEWS' => 'yes', 'PHOTO_SORT_ORDER' => 'random', 'ENABLE_VIDEO' => true, 'MAP_CLUSTER_RADIUS_MILES' => 'far']));
$h2 = $cfgHome();
check('[settings file] a typo\'d name, a wrong kind of value and a value not on the allowed list are each reported in the setup notices', str_contains($h2, '&quot;ENABLE_VIDDEO&quot; isn&#039;t a setting') && str_contains($h2, '&quot;TRACK_VIEWS&quot; should be true or false') && str_contains($h2, '&quot;PHOTO_SORT_ORDER&quot; should be one of') && str_contains($h2, '&quot;MAP_CLUSTER_RADIUS_MILES&quot; should be a number'));
check('[settings file] ...and the good setting in the same file still applies', str_contains($h2, 'alt="clip.mp4"'));
file_put_contents("$fxCfg/.gallery-config.php", $cfgGuard . "{\"ENABLE_VIDEO\": true,\n");   // not valid JSON
[$st3, $h3] = http_get("$baseCfg/");
check('[settings file] a settings file that is not valid JSON does not take the site down: it loads on the defaults, with a notice', $st3 === 200 && str_contains($h3, 'alt="photo.jpg"') && str_contains($h3, 'isn&#039;t valid JSON') && !str_contains($h3, 'alt="clip.mp4"'));
file_put_contents("$fxCfg/.gallery-config.php", $cfgGuard . "[1, 2, 3]\n");   // valid JSON, but not an object of settings
[$st4, $h4] = http_get("$baseCfg/");
check('[settings file] a file that is valid JSON but not an object of settings is reported and ignored', $st4 === 200 && str_contains($h4, 'must hold an object of settings'));
// Hand-editing: comments and a trailing comma are fine, and the shipped example is a safe template.
file_put_contents("$fxCfg/.gallery-config.php", $cfgGuard . "// my notes\n{\n    // \"ENABLE_TIMES\": true,\n    \"ENABLE_VIDEO\": true,   // yes please\n}\n");
$hHand = $cfgHome();
check('[settings file] hand-written JSON with // note lines, a trailing comment after a value and a trailing comma loads cleanly, with no settings-file notice', str_contains($hHand, 'alt="clip.mp4"') && !str_contains($hHand, '.gallery-config.php'));
$exampleText = (string) file_get_contents(__DIR__ . '/../gallery-config.example.php');
file_put_contents("$fxCfg/.gallery-config.php", $exampleText);
$hExample = $cfgHome();
check('[settings file] the shipped gallery-config.example.php, copied as it is, changes nothing and raises no settings-file notice', !str_contains($hExample, 'alt="clip.mp4"') && !str_contains($hExample, '.gallery-config.php'));
$uncommented = str_replace('// "ENABLE_VIDEO": true,', '"ENABLE_VIDEO": true,', $exampleText, $uncommentCount);
file_put_contents("$fxCfg/.gallery-config.php", $uncommented);
check('[settings file] ...and removing the // from one line of it (leaving the explanation after it) turns that setting on', $uncommentCount === 1 && str_contains($uncommented, '"ENABLE_VIDEO": true,') && str_contains($cfgHome(), 'alt="clip.mp4"') && http_get("$baseCfg/.gallery-config.php")[0] === 404);
// A "//" or a comma inside a value is data, never a comment or a stray comma.
file_put_contents("$fxCfg/.gallery-config.php", $cfgGuard . "{\n  \"ADMIN_PASSWORD\": \"a//b, }c // not a comment\",   // a real comment\n  \"ENABLE_VIDEO\": true,\n}\n");
check('[settings file] a password containing "//", a comma and a brace is read back exactly, and a real comment after it is still ignored', http_post("$baseCfg/?admin", ['login_password' => 'a//b, }c // not a comment'], [], false)[0] === 303 && str_contains($cfgHome(), 'alt="clip.mp4"'));
// The admin says what comes from the file, by name only.
file_put_contents("$fxCfg/.gallery-config.php", cfg_text(['ENABLE_VIDEO' => true, 'ADMIN_PASSWORD' => 'cfg-pw-123']));
[, $cfgAdmin] = http_get("$baseCfg/?admin", ['Authorization: Basic ' . base64_encode('x:cfg-pw-123')]);
check('[settings file] the root Settings tab no longer carries a "settings file" paragraph (Site settings is where that lives), and never shows a password', !str_contains($cfgAdmin, 'is in use and keeps its settings') && !str_contains($cfgAdmin, 'cfg-pw-123'));
unlink("$fxCfg/.gallery-config.php");
[, $cfgAdmin2] = http_get("$baseCfg/?admin", ['Authorization: Basic ' . base64_encode('x:cfg-pw-123')]);
touch("$fxCfg/index.php", time() - 3600);
check('[settings file] with no password anywhere and an old index.php, ?admin does not exist (404)', http_get("$baseCfg/?admin")[0] === 404);

echo "== dates suggested from the photos ==\n";
$fxDates = new_fixture_dir('datesug');
mkdir("$fxDates/Trip"); mkdir("$fxDates/Trip/Day2");
make_jpeg("$fxDates/Trip/a.jpg", 60, 60, 10, 10, 10);
make_jpeg("$fxDates/Trip/Day2/b.jpg", 60, 60, 20, 20, 20);
make_jpeg("$fxDates/top.jpg", 60, 60, 30, 30, 30);
touch("$fxDates/Trip/a.jpg", gmmktime(12, 0, 0, 10, 3, 2026));
touch("$fxDates/Trip/Day2/b.jpg", gmmktime(12, 0, 0, 10, 9, 2026));
touch("$fxDates/top.jpg", gmmktime(12, 0, 0, 12, 30, 2025));
write_patched_index("$fxDates/index.php", $indexContents, ['AUTO_GROUP_BY_FOLDER' => 'true', 'ADMIN_PASSWORD' => "'dates-pw-123456'"]);
$portDates = find_free_port(); start_server($fxDates, $portDates);
$datesAuth = ['Authorization: Basic ' . base64_encode('x:dates-pw-123456')];
[, $datesAdmin] = http_get("http://127.0.0.1:$portDates/?admin&folder=Trip", $datesAuth);
check('[dates] a folder\'s settings offer the span of its photos (here from file dates, subfolders included) with a one-click button, and say where the dates come from', str_contains($datesAdmin, 'span <strong>Oct 3' . "\u{2013}" . '9, 2026</strong>') && str_contains($datesAdmin, 'class="secondary date-suggest" data-dates="Oct 3' . "\u{2013}" . '9, 2026"') && str_contains($datesAdmin, 'files were last modified'));
[, $datesRoot] = http_get("http://127.0.0.1:$portDates/?admin", $datesAuth);
check('[dates] the gallery root suggests a range across years', str_contains($datesRoot, 'Dec 30, 2025 ' . "\u{2013}" . ' Oct 9, 2026'));
check('[dates] nothing is stored or shown to visitors until the form is saved', !str_contains(http_get("http://127.0.0.1:$portDates/?folder=Trip")[1], 'Oct 3'));
write_data_file("$fxDates/Trip/.gallery-items.php", ['dates' => "Oct 3" . "\u{2013}" . "9, 2026", 'items' => [], 'groups' => []]);
[, $datesSaved] = http_get("http://127.0.0.1:$portDates/?admin&folder=Trip", $datesAuth);
check('[dates] once the dates field already holds the suggestion, the suggestion is not offered again', !str_contains($datesSaved, 'class="secondary date-suggest"'));

echo "== settings never destroy data: captions, locations, hide flags and stats survive any setting being switched ==\n";

$fxKeep = new_fixture_dir('keepdata');
make_jpeg("$fxKeep/photo.jpg", 60, 60, 10, 10, 10);
make_minimal_mp4("$fxKeep/clip.mp4", 2);
mkdir("$fxKeep/Sub"); make_jpeg("$fxKeep/Sub/deep.jpg", 60, 60, 20, 20, 20); make_minimal_mp4("$fxKeep/Sub/deepclip.mp4", 2);
copy(__DIR__ . '/../index.php', "$fxKeep/index.php");
$keepOn = cfg_text(['ENABLE_VIDEO' => true, 'AUTO_GROUP_BY_FOLDER' => true, 'TRACK_VIEWS' => true, 'SHOW_VIEW_COUNTS_PUBLICLY' => true, 'ENABLE_PHOTO_LOCATION' => true]);
$keepOff = cfg_text(['ENABLE_VIDEO' => false, 'AUTO_GROUP_BY_FOLDER' => false, 'TRACK_VIEWS' => false, 'ENABLE_PHOTO_LOCATION' => false, 'ENABLE_THUMBNAIL_CACHE' => false]);
file_put_contents("$fxKeep/.gallery-config.php", $keepOn);
write_data_file("$fxKeep/.gallery-items.php", ['items' => [
    'clip.mp4' => ['caption' => 'Waves at dusk', 'location' => ['lat' => 38.7, 'lng' => -9.1, 'label' => 'Lisbon'], 'locationVisibility' => 'show', 'restriction' => 'private', 'gpsChecked' => true],
    'photo.jpg' => ['caption' => 'Kept caption', 'web' => 'https://example.test/x'],
], 'groups' => [], 'displayName' => 'Keep me', 'locationEnabled' => true]);
write_data_file("$fxKeep/Sub/.gallery-items.php", ['items' => [
    'deepclip.mp4' => ['caption' => 'Deep clip caption'], 'deep.jpg' => ['caption' => 'Deep photo caption', 'location' => ['lat' => 1.5, 'lng' => 2.5, 'label' => '']],
], 'groups' => [], 'description' => 'A folder description', 'locationEnabled' => true]);
$portKeep = find_free_port(); start_server($fxKeep, $portKeep); $baseKeep = "http://127.0.0.1:$portKeep";
foreach (['/', '/?folder=Sub', '/?track=photo.jpg', '/?track=photo.jpg'] as $u) http_get("$baseKeep$u");
$snapshot = function () use ($fxKeep) {
    $snap = [];
    foreach (['.gallery-items.php', 'Sub/.gallery-items.php'] as $f) $snap[$f] = read_data_file("$fxKeep/$f");
    foreach (scandir($fxKeep) as $f) if (str_starts_with($f, '.gallery-stats')) $snap[$f] = md5_file("$fxKeep/$f");
    return $snap;
};
$onPage = http_get("$baseKeep/")[1];
check('[keep data] (setup) with everything on: the folder is shown, the hidden video is not (its hide flag works), and the two counted views are visible', str_contains($onPage, 'id="group-sub"') && !str_contains($onPage, 'alt="clip.mp4"') && !str_contains($onPage, 'img=clip.mp4') && str_contains($onPage, 'data-photo="photo.jpg">&#128065; 2<'));
$before = $snapshot();   // taken after every request that is allowed to count a view
// Switch every one of those settings OFF and use the site (public pages, admin) several times.
file_put_contents("$fxKeep/.gallery-config.php", $keepOff);
foreach (['/', '/', '/?folder=Sub', '/?map', '/?admin', '/?admin&folder=Sub'] as $u) http_get("$baseKeep$u", ['Authorization: Basic ' . base64_encode('x:nopw')]);
$offPage = http_get("$baseKeep/")[1];
check('[keep data] (setup) with those settings off the videos and folders are really gone from the page', !str_contains($offPage, 'clip.mp4') && !str_contains($offPage, 'id="group-sub"'));
$after = $snapshot();
check('[keep data] every caption, location, hide flag, link, folder description and stats file is exactly as it was after switching those settings OFF', $before === $after);
// Switch them back on: everything is back, and counting resumes from where it left off.
file_put_contents("$fxKeep/.gallery-config.php", $keepOn);
$onAgain = http_get("$baseKeep/")[1];
check('[keep data] switching them back ON brings the folder back, and the video\'s hide flag still hides it (it did not reset to visible)', str_contains($onAgain, 'id="group-sub"') && !str_contains($onAgain, 'alt="clip.mp4"') && !str_contains($onAgain, 'img=clip.mp4'));
check('[keep data] ...and counting resumes from where it left off (the earlier 2 views, now 3)', http_get("$baseKeep/?track=photo.jpg")[0] === 200 && str_contains(http_get("$baseKeep/")[1], 'data-photo="photo.jpg">&#128065; 3<'));
$subAgain = http_get("$baseKeep/?folder=Sub")[1];
check('[keep data] ...the folder page shows its description and its photo\'s caption again', str_contains($subAgain, 'A folder description') && str_contains($subAgain, 'Deep photo caption'));
$afterOn = $snapshot();
check('[keep data] ...and the stored captions, locations, hide flags and descriptions are exactly what was there before (only counts moved)', array_intersect_key($afterOn, ['.gallery-items.php' => 1, 'Sub/.gallery-items.php' => 1]) === array_intersect_key($before, ['.gallery-items.php' => 1, 'Sub/.gallery-items.php' => 1]));
// A file that really is deleted is still cleaned up (the old, intended behaviour).
unlink("$fxKeep/photo.jpg");
http_get("$baseKeep/");
$afterDelete = read_data_file("$fxKeep/.gallery-items.php");
check('[keep data] but an entry whose file was really deleted is still cleaned up, and the video\'s entry is untouched', !isset($afterDelete['items']['photo.jpg']) && isset($afterDelete['items']['clip.mp4']) && ($afterDelete['items']['clip.mp4']['caption'] ?? '') === 'Waves at dusk');

echo "== site settings: the admin screen for the constants, which only ever writes .gallery-config.php ==\n";

$fxSite = new_fixture_dir('sitesettings');
make_jpeg("$fxSite/photo.jpg", 60, 60, 10, 10, 10);
make_minimal_mp4("$fxSite/clip.mp4", 2);
mkdir("$fxSite/Sub"); make_jpeg("$fxSite/Sub/deep.jpg", 60, 60, 20, 20, 20);
file_put_contents("$fxSite/notes.md", "{gallery}\n# Notes title\n\nBody text that must survive.\n");
file_put_contents("$fxSite/Sub/readme-notes.md", "{gallery}\nA folder's own notes.\n");
write_data_file("$fxSite/.gallery-items.php", ['items' => [
    'photo.jpg' => ['caption' => 'Precious caption', 'location' => ['lat' => 48.8584, 'lng' => 2.2945, 'label' => 'Paris'], 'locationVisibility' => 'show', 'gpsChecked' => true],
    'clip.mp4' => ['caption' => 'Video caption', 'restriction' => 'private'],
], 'groups' => [], 'displayName' => 'Kept title', 'description' => 'Kept description', 'locationEnabled' => true]);
write_data_file("$fxSite/Sub/.gallery-items.php", ['items' => ['deep.jpg' => ['caption' => 'Deep caption', 'location' => ['lat' => 1.5, 'lng' => 2.5, 'label' => '']]], 'groups' => [], 'description' => 'Sub description', 'locationEnabled' => true]);
copy(__DIR__ . '/../index.php', "$fxSite/index.php");
$siteCfg = "$fxSite/.gallery-config.php";
$siteHead = $cfgGuard;
file_put_contents($siteCfg, cfg_text(['ADMIN_PASSWORD' => 'first-admin-pw-123', 'TRACK_VIEWS' => true, 'SHOW_VIEW_COUNTS_PUBLICLY' => true]));
$portSite = find_free_port(); start_server($fxSite, $portSite); $baseSite = "http://127.0.0.1:$portSite";
$siteAuth = 'Authorization: Basic ' . base64_encode('x:first-admin-pw-123');
http_get("$baseSite/?track=photo.jpg"); http_get("$baseSite/?track=photo.jpg"); http_get("$baseSite/");
[, $sAdmin, $sAdminHd] = http_get("$baseSite/?admin", [$siteAuth]);
$siteHdrs = [$siteAuth, 'Cookie: ' . extract_cookie_header($sAdminHd)];
$siteForm = function ($page) { foreach (html_post_forms($page) as $f) if (str_contains($f['html'], 'value="save_site_settings"')) return $f['html']; return ''; };
$sForm = $siteForm($sAdmin);
check('[site settings] the admin has a "Site settings" entry in the sidebar and a form with every managed setting', str_contains($sAdmin, 'data-tab="site" class="side-link"') && $sForm !== '' && str_contains($sForm, 'name="set_ENABLE_VIDEO"') && str_contains($sForm, 'name="set_AUTO_GROUP_BY_FOLDER"') && str_contains($sForm, 'name="set_PHOTO_SORT_ORDER"') && str_contains($sForm, 'name="set_MAP_CLUSTER_RADIUS_MILES"') && str_contains($sForm, 'name="pw_admin"'));
check('[site settings] ...it shows what comes from the settings file, never the password itself, and offers the server settings (extra folders, nginx prefix) as editable controls', str_contains($sAdmin, 'your setting') && !str_contains($sAdmin, 'first-admin-pw-123') && str_contains($sForm, 'data-name="set_EXTRA_FOLDERS"') && str_contains($sForm, 'name="set_NGINX_ACCEL_REDIRECT_PREFIX"') && str_contains($sForm, 'class="extra-add-btn'));
check('[site settings] each setting says what the default is', str_contains($sForm, 'default: off') && str_contains($sForm, 'default: on'));
// Snapshot everything that is NOT the settings file.
$siteSnap = function () use ($fxSite) {
    $snap = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fxSite, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $rel = substr($f->getPathname(), strlen($fxSite) + 1);
        if ($rel === '.gallery-config.php' || $rel === 'index.php' || str_contains($rel, '.gallery-cache/') || str_contains($rel, '.tmp.')) continue;
        $snap[$rel] = md5_file($f->getPathname());
    }
    ksort($snap); return $snap;
};
$snapBefore = $siteSnap();
// A real save: turn video, folders and the map on, thumbnails off, change two enums and the number.
[, $r] = user_submit("$baseSite/?admin", $sAdmin, 'value="save_site_settings"', [
    'set_ENABLE_VIDEO' => '1', 'set_AUTO_GROUP_BY_FOLDER' => '1', 'set_ENABLE_THUMBNAIL_CACHE' => '0', 'set_FOLDER_DISPLAY_MODE' => 'inline',
    'set_PHOTO_SORT_ORDER' => 'mtime', 'set_MAP_CLUSTER_RADIUS_MILES' => '25',
], $siteHdrs);
check('[site settings] a save redirects back and then shows a confirmation that says nothing else was touched', str_contains($r, 'Settings saved') && str_contains($r, 'were not touched') && str_contains($r, 'class="flash ok"'));
$snapAfterSave = $siteSnap();   // before any public page is viewed (a view is counted, legitimately)
$written = (string) file_get_contents($siteCfg);
$loadedCfg = cfg_read($siteCfg);
$expectedCfg = ['ADMIN_PASSWORD' => 'first-admin-pw-123', 'AUTO_GROUP_BY_FOLDER' => true, 'ENABLE_THUMBNAIL_CACHE' => false, 'ENABLE_VIDEO' => true, 'FOLDER_DISPLAY_MODE' => 'inline', 'MAP_CLUSTER_RADIUS_MILES' => 25, 'PHOTO_SORT_ORDER' => 'mtime', 'SHOW_VIEW_COUNTS_PUBLICLY' => true, 'TRACK_VIEWS' => true];
ksort($loadedCfg);
check('[site settings] the settings file is the 404 guard plus JSON holding exactly the intended settings, keeping the ones already there', str_starts_with($written, $siteHead) && $loadedCfg === $expectedCfg);
check('[site settings] the file is private to the web server user (not world-readable), since it holds the passwords', (fileperms($siteCfg) & 0o077) === 0);
$siteHome = http_get("$baseSite/")[1];
check('[site settings] the new settings take effect immediately: the folder is shown and the video is a gallery item', str_contains($siteHome, 'id="group-sub"') || str_contains($siteHome, 'Sub/deep.jpg'));
check('[site settings] ...and a direct request for the settings file still answers 404', http_get("$baseSite/.gallery-config.php")[0] === 404);
check('[site settings] NOTHING ELSE changed: every caption, location, hide flag, description, notes file, photo and the stats store is byte-for-byte what it was', $snapAfterSave === $snapBefore && count($snapBefore) >= 8);
// Setting something back to its default removes it from the file.
[, $sAdmin2] = http_get("$baseSite/?admin", $siteHdrs);
$snapBaseline2 = $siteSnap();
user_submit("$baseSite/?admin", $sAdmin2, 'value="save_site_settings"', ['set_ENABLE_VIDEO' => '0', 'set_AUTO_GROUP_BY_FOLDER' => '0', 'set_ENABLE_THUMBNAIL_CACHE' => '1', 'set_FOLDER_DISPLAY_MODE' => 'overview', 'set_PHOTO_SORT_ORDER' => 'filename', 'set_MAP_CLUSTER_RADIUS_MILES' => '50'], $siteHdrs);
$backCfg = cfg_read($siteCfg); ksort($backCfg);
check('[site settings] putting settings back to their defaults removes them from the file, so later default improvements still reach you', $backCfg == ['ADMIN_PASSWORD' => 'first-admin-pw-123', 'SHOW_VIEW_COUNTS_PUBLICLY' => true, 'TRACK_VIEWS' => true]);
check('[site settings] ...and, again, no data file changed (compared with a baseline taken after the public page was viewed)', $siteSnap() === $snapBaseline2);
// Settings the screen doesn't manage, and hand-added keys, are kept.
file_put_contents($siteCfg, cfg_text(['ADMIN_PASSWORD' => 'first-admin-pw-123', 'EXTRA_FOLDERS' => ['Old' => '/tmp/somewhere'], 'NGINX_ACCEL_REDIRECT_PREFIX' => '/kb', 'MY_OWN_NOTE' => 'keep me']));
[, $sAdmin3] = http_get("$baseSite/?admin", $siteHdrs);
user_submit("$baseSite/?admin", $sAdmin3, 'value="save_site_settings"', ['set_ENABLE_VIDEO' => '1'], $siteHdrs);
$keptCfg = cfg_read($siteCfg);
check('[site settings] file-only settings (EXTRA_FOLDERS, the nginx prefix) and any key it doesn\'t manage survive a save untouched', ($keptCfg['EXTRA_FOLDERS'] ?? null) === ['Old' => '/tmp/somewhere'] && ($keptCfg['NGINX_ACCEL_REDIRECT_PREFIX'] ?? null) === '/kb' && ($keptCfg['MY_OWN_NOTE'] ?? null) === 'keep me' && ($keptCfg['ENABLE_VIDEO'] ?? null) === true);
file_put_contents($siteCfg, cfg_text(['ADMIN_PASSWORD' => 'first-admin-pw-123']));
// Bad input: nothing is written, the reasons are given.
[, $sAdmin4] = http_get("$baseSite/?admin", $siteHdrs); $cfgBytes = (string) file_get_contents($siteCfg);
[, $rBad] = user_submit("$baseSite/?admin", $sAdmin4, 'value="save_site_settings"', ['set_PHOTO_SORT_ORDER' => 'random', 'set_MAP_CLUSTER_RADIUS_MILES' => '-5', 'set_ENABLE_VIDEO' => '1'], $siteHdrs);
check('[site settings] a value that isn\'t one of the choices, or a number out of range, saves nothing and says why', str_contains($rBad, 'Nothing was saved') && str_contains($rBad, 'Photo order') && str_contains($rBad, 'Map pin grouping') && (string) file_get_contents($siteCfg) === $cfgBytes);
// Passwords.
[, $rMis] = user_submit("$baseSite/?admin", $sAdmin4, 'value="save_site_settings"', ['pw_admin' => 'a-new-long-password-1', 'pw_admin2' => 'different-long-password-2'], $siteHdrs);
[, $rShort] = user_submit("$baseSite/?admin", $sAdmin4, 'value="save_site_settings"', ['pw_admin' => 'short', 'pw_admin2' => 'short'], $siteHdrs);
check('[site settings] a new admin password that doesn\'t match, or is too short, is refused and the old one stays', str_contains($rMis, "don't match") || str_contains($rMis, 'don&#039;t match'));
check('[site settings] ...too short is refused with the reason', str_contains($rShort, 'at least 12 characters') && (string) file_get_contents($siteCfg) === $cfgBytes);
$nasty = "p'a\\ss\"w \$x<?php echo 'X'; system('id'); ?>\n";
$nasty = str_replace("\n", '', $nasty);
[, $rPw] = user_submit("$baseSite/?admin", $sAdmin4, 'value="save_site_settings"', ['pw_admin' => $nasty, 'pw_admin2' => $nasty], $siteHdrs);
check('[site settings] changing the admin password works, and you stay signed in (the session still opens the admin, no password asked)', str_contains($rPw, 'admin password changed') && str_contains(http_get("$baseSite/?admin", [$siteHdrs[1]])[1], 'Gallery Admin'));
$pwCfg = cfg_read($siteCfg);
check('[site settings] a password full of quotes, backslashes, $ and PHP tags is stored as plain data and reads back exactly', ($pwCfg['ADMIN_PASSWORD'] ?? null) === $nasty);
check('[site settings] the new password logs in through the form (a redirect into the admin); the old one no longer does (the form again)', http_post("$baseSite/?admin", ['login_password' => $nasty], [], false)[0] === 303 && http_post("$baseSite/?admin", ['login_password' => 'first-admin-pw-123'], [], false)[0] === 200);
// Hostile text can't be injected through any value: only typed, allow-listed values are ever written.
$cfgFileText = (string) file_get_contents($siteCfg);
$cfgAfterGuard = substr($cfgFileText, strpos($cfgFileText, "\n") + 1);
check('[site settings] the written file is the 404 guard plus plain JSON data, never code: one PHP tag in the whole file even though the password contained "system(\'id\')" and a PHP tag, and the rest decodes as JSON', substr_count($cfgFileText, '<?php') === 1 && str_starts_with($cfgFileText, $cfgGuard) && is_array(json_decode($cfgAfterGuard, true)) && (json_decode($cfgAfterGuard, true)['ADMIN_PASSWORD'] ?? null) === $nasty);
// A fresh login for the rest (the password changed).
$siteAuth2 = 'Authorization: Basic ' . base64_encode('x:' . $nasty);
[, $sAdmin5, $sAdmin5Hd] = http_get("$baseSite/?admin", [$siteAuth2]);
$siteHdrs2 = [$siteAuth2, 'Cookie: ' . extract_cookie_header($sAdmin5Hd)];
// Private-view password and removing it.
user_submit("$baseSite/?admin", $sAdmin5, 'value="save_site_settings"', ['pw_private' => 'viewme', 'pw_private2' => 'viewme'], $siteHdrs2);
$pv = cfg_read($siteCfg);
check('[site settings] the private-view password can be set from here', ($pv['PRIVATE_VIEW_PASSWORD'] ?? null) === 'viewme');
[, $sAdmin6] = http_get("$baseSite/?admin", $siteHdrs2);
check('[site settings] ...and the screen never shows it back, offering "Remove it" instead', !str_contains($sAdmin6, 'value="viewme"') && str_contains($sAdmin6, 'name="pw_private_remove"'));
user_submit("$baseSite/?admin", $sAdmin6, 'value="save_site_settings"', ['pw_private_remove' => '1'], $siteHdrs2);
check('[site settings] removing it turns private view off', !isset(cfg_read($siteCfg)['PRIVATE_VIEW_PASSWORD']));
// A concurrent change to the file is detected, not overwritten.
[, $sAdmin7] = http_get("$baseSite/?admin", $siteHdrs2);
file_put_contents($siteCfg, (string) file_get_contents($siteCfg) . "// edited by hand meanwhile\n");   // a full-line // note is allowed
$handEdited = (string) file_get_contents($siteCfg);
[, $rStale] = user_submit("$baseSite/?admin", $sAdmin7, 'value="save_site_settings"', ['set_ENABLE_VIDEO' => '1'], $siteHdrs2);
check('[site settings] if the file was changed since the page loaded, the save is refused and the file left as it is', str_contains($rStale, 'was changed somewhere else') && (string) file_get_contents($siteCfg) === $handEdited);
// A settings file the screen can't understand is never overwritten.
file_put_contents($siteCfg, $siteHead . "{\"ADMIN_PASSWORD\": \"x\",\n");   // not valid JSON
[, $sBroken] = http_get("$baseSite/?admin", [$siteAuth2]);   // site falls back to the defaults, so use Basic with the... default (no admin)
check('[site settings] (setup) a syntax error in the file means the site runs on defaults', http_get("$baseSite/")[0] === 200);
// CSRF.
file_put_contents($siteCfg, cfg_text(['ADMIN_PASSWORD' => $nasty]));
[, $sAdmin8] = http_get("$baseSite/?admin", $siteHdrs2);
$noCsrf = []; foreach (html_post_forms($sAdmin8) as $f) if (str_contains($f['html'], 'value="save_site_settings"')) { $noCsrf = form_default_fields($f['html']); }
$noCsrf['csrf_token'] = 'wrong'; $noCsrf['set_ENABLE_VIDEO'] = '1'; $beforeCsrf = (string) file_get_contents($siteCfg);
[, $rCsrf] = http_post("$baseSite/?admin", $noCsrf, $siteHdrs2);
check('[site settings] a save without a valid CSRF token is refused and writes nothing', str_contains($rCsrf, 'session expired') && (string) file_get_contents($siteCfg) === $beforeCsrf);
// Every warning shown before a change is a complete sentence, used exactly as written (not spliced into a template).
preg_match_all("/'(?:risk|offwarn)' => '((?:[^'\\\\]|\\\\.)*)'/", $indexContents, $warnM);
$warnings = array_map(fn($w) => stripslashes($w), $warnM[1]);
$badWarnings = array_values(array_filter($warnings, fn($w) => !preg_match('/^[A-Z][^\n]*[.!]$/', $w)));
check('[warnings] every confirmation text for a risky setting is a complete sentence (capital letter, full stop), so it reads right on its own', count($warnings) >= 10 && $badWarnings === [], implode(' | ', $badWarnings));
check('[warnings] ...and none is glued onto a fixed lead-in ("This ...") in the page\'s script, which is what produced "This the map loads ..."', !preg_match("/'\\\\n\\\\nThis '/", $indexContents) && !str_contains($indexContents, 'Turning this on <?='));

// Saving in place: the page's own script posts one setting at a time and gets JSON back.
$ajaxHdrs = array_merge($siteHdrs2, ['X-Requested-With: gallery-fetch']);
[, $pgA] = http_get("$baseSite/?admin", $siteHdrs2); $fa = form_default_fields($siteForm($pgA));
$snapAjaxBefore = $siteSnap(); $cfgAjaxBefore = cfg_read($siteCfg);
[$jst, $jb, $jhd] = http_post("$baseSite/?admin", ['csrf_token' => $fa['csrf_token'], 'action' => 'save_site_settings', 'config_hash' => $fa['config_hash'], 'set_ENABLE_VIDEO' => '1'], $ajaxHdrs);
$j = json_decode($jb, true);
check('[autosave] one setting posted by the page\'s script is answered with JSON, "Saved.", and the new fingerprint of the file', $jst === 200 && (bool) array_filter($jhd, fn($h) => stripos($h, 'Content-Type: application/json') === 0) && ($j['ok'] ?? null) === true && ($j['text'] ?? '') === 'Saved.' && ($j['hash'] ?? '') === md5_file($siteCfg));
$cfgAjaxAfter = cfg_read($siteCfg);
$expectedAjax = $cfgAjaxBefore + ['ENABLE_VIDEO' => true]; ksort($expectedAjax); $gotAjax = $cfgAjaxAfter; ksort($gotAjax);
check('[autosave] only that one setting changed in the file; everything else in it is exactly as it was', $gotAjax === $expectedAjax);
check('[autosave] ...and no data file changed', $siteSnap() === $snapAjaxBefore);
check('[autosave] ...and no "Settings saved" message is left waiting to appear on the next page load', !str_contains(http_get("$baseSite/?admin", $siteHdrs2)[1], 'Settings saved'));
[$jst2, $jb2] = http_post("$baseSite/?admin", ['csrf_token' => $fa['csrf_token'], 'action' => 'save_site_settings', 'config_hash' => $fa['config_hash'], 'set_TRACK_VIEWS' => '1'], $ajaxHdrs);
$j2 = json_decode($jb2, true);
check('[autosave] a request carrying the file\'s OLD fingerprint (it changed since) is refused as JSON and writes nothing', ($j2['ok'] ?? null) === false && str_contains((string) ($j2['text'] ?? ''), 'was changed somewhere else') && cfg_read($siteCfg) == $cfgAjaxAfter);
[$jst3, $jb3] = http_post("$baseSite/?admin", ['csrf_token' => $fa['csrf_token'], 'action' => 'save_site_settings', 'config_hash' => $j['hash'], 'set_TRACK_VIEWS' => '1'], $ajaxHdrs);
$j3 = json_decode($jb3, true);
check('[autosave] ...while one carrying the fingerprint it was just given succeeds, so consecutive changes chain', ($j3['ok'] ?? null) === true && (cfg_read($siteCfg)['TRACK_VIEWS'] ?? null) === true && (cfg_read($siteCfg)['ENABLE_VIDEO'] ?? null) === true);
[, $jb4] = http_post("$baseSite/?admin", ['csrf_token' => 'wrong', 'action' => 'save_site_settings', 'config_hash' => $j3['hash'], 'set_ENABLE_MAP' => '1'], $ajaxHdrs);
$j4 = json_decode($jb4, true); $cfgNow = cfg_read($siteCfg);
check('[autosave] a request without a valid CSRF token is refused as JSON and writes nothing', ($j4['ok'] ?? null) === false && str_contains((string) ($j4['text'] ?? ''), 'session expired') && !isset($cfgNow['ENABLE_MAP']));
[, $jb5] = http_post("$baseSite/?admin", ['csrf_token' => $fa['csrf_token'], 'action' => 'save_site_settings', 'config_hash' => $j3['hash'], 'set_PHOTO_SORT_ORDER' => 'random'], $ajaxHdrs);
$j5 = json_decode($jb5, true);
check('[autosave] a value that isn\'t an allowed choice is refused as JSON with the reason, and nothing is written', ($j5['ok'] ?? null) === false && str_contains((string) ($j5['text'] ?? ''), 'Photo order') && !isset(cfg_read($siteCfg)['PHOTO_SORT_ORDER']));
[, $jb6] = http_post("$baseSite/?admin", ['csrf_token' => $fa['csrf_token'], 'action' => 'save_site_settings', 'config_hash' => $j3['hash'], 'set_TRACK_VIEWS' => '1'], $ajaxHdrs);
check('[autosave] saving a value the file already has says there is nothing to change, and gives the same fingerprint', (json_decode($jb6, true)['ok'] ?? null) === true && !empty(json_decode($jb6, true)['unchanged']) && (json_decode($jb6, true)['hash'] ?? '') === $j3['hash']);
[$jst7, , $jhd7] = http_post("$baseSite/?admin", ['csrf_token' => $fa['csrf_token'], 'action' => 'save_site_settings', 'config_hash' => $j3['hash'], 'set_ENABLE_VIDEO' => '0'], $siteHdrs2, false);
$loc7 = ''; foreach ($jhd7 as $h) if (stripos($h, 'Location:') === 0) $loc7 = trim(substr($h, 9));
check('[autosave] the ordinary form post (what a browser without JavaScript sends) still saves and redirects back to Site settings, as before', $jst7 === 303 && str_ends_with($loc7, '#site') && !isset(cfg_read($siteCfg)['ENABLE_VIDEO']));
check('[autosave] a visitor who is not signed in gets nothing from the endpoint: no JSON, no change', !str_contains(http_post("$baseSite/?admin", ['action' => 'save_site_settings', 'set_ENABLE_MAP' => '1'], ['X-Requested-With: gallery-fetch'])[1], '"ok"') && !isset(cfg_read($siteCfg)['ENABLE_MAP']));

// Server setup in the admin: folders from elsewhere on the server, and the nginx prefix.
$siteAjax = function (array $fields) use ($baseSite, $siteHdrs2, $siteCfg, $siteForm) {
    [, $pg] = http_get("$baseSite/?admin", $siteHdrs2); $ff = form_default_fields($siteForm($pg));
    [, $body] = http_post("$baseSite/?admin", array_merge(['csrf_token' => $ff['csrf_token'], 'action' => 'save_site_settings', 'config_hash' => md5_file($siteCfg)], $fields), array_merge($siteHdrs2, ['X-Requested-With: gallery-fetch']));
    return json_decode($body, true) ?? ['ok' => null, 'text' => $body];
};
$extraSrc = new_fixture_dir('extra-source');
make_jpeg("$extraSrc/archive1.jpg", 60, 60, 90, 90, 200);
write_data_file("$extraSrc/.gallery-items.php", ['items' => ['archive1.jpg' => ['caption' => 'From the archive disk']], 'groups' => []]);
$extraSrcReal = realpath($extraSrc);
@symlink($extraSrc, "$extraSrc-link");
$cfgBeforeExtra = (string) file_get_contents($siteCfg);
$bad = [
    'a relative path' => ['Rel' => 'photos/archive'],
    'a path with ..' => ['Dots' => $extraSrcReal . '/../x'],
    'a folder that does not exist' => ['Nope' => '/no/such/folder/here'],
    'a file, not a folder' => ['AFile' => "$extraSrcReal/archive1.jpg"],
    'a symlink' => ['Linked' => "$extraSrc-link"],
    'a path through a symlink' => ['Through' => "$extraSrc-link/"],
    'the gallery\'s own folder' => ['Self' => $fxSite],
    'a folder inside the gallery' => ['Inside' => "$fxSite/Sub"],
    'a folder that contains the gallery' => ['Outer' => sys_get_temp_dir()],
    'a name with a slash' => ['a/b' => $extraSrcReal],
    'a name starting with a dot' => ['.hidden' => $extraSrcReal],
    'a name already used by a real folder' => ['Sub' => $extraSrcReal],
];
foreach ($bad as $why => $map) {
    $r = $siteAjax(['set_EXTRA_FOLDERS' => json_encode($map)]);
    check("[extra folders] $why is refused with a reason, and the settings file is untouched", ($r['ok'] ?? null) === false && ($r['text'] ?? '') !== '' && (string) file_get_contents($siteCfg) === $cfgBeforeExtra);
}
$dup = $siteAjax(['set_EXTRA_FOLDERS' => '{"Same":"' . $extraSrcReal . '","same":"' . $extraSrcReal . '"}']);
check('[extra folders] the same name twice (ignoring case) is refused', ($dup['ok'] ?? null) === false && str_contains((string) $dup['text'], 'twice'));
check('[extra folders] something that is not a list of folders is refused', ($siteAjax(['set_EXTRA_FOLDERS' => 'not json'])['ok'] ?? null) === false && ($siteAjax(['set_EXTRA_FOLDERS' => '["a","b"]'])['ok'] ?? null) === false);
// A good one, end to end.
$siteAjax(['set_AUTO_GROUP_BY_FOLDER' => '1']);
$extraPhotoMd5 = md5_file("$extraSrc/archive1.jpg");
$good = $siteAjax(['set_EXTRA_FOLDERS' => json_encode(['OldTrip' => $extraSrcReal])]);
check('[extra folders] a real, readable folder elsewhere on the server is accepted, and handed back as stored', ($good['ok'] ?? null) === true && (array) ($good['extra'] ?? []) === ['OldTrip' => $extraSrcReal] && (cfg_read($siteCfg)['EXTRA_FOLDERS'] ?? null) === ['OldTrip' => $extraSrcReal]);
$homeExtra = http_get("$baseSite/", $siteHdrs2)[1];
check('[extra folders] its photos then appear in the gallery as a folder under that name, with thumbnails that load', str_contains($homeExtra, 'id="group-oldtrip"') && http_get("$baseSite/?img=OldTrip%2Farchive1.jpg&size=thumb")[0] === 200 && str_contains(http_get("$baseSite/?folder=OldTrip")[1], 'archive1.jpg'));
check('[extra folders] the sidebar list in the admin includes it', str_contains(http_get("$baseSite/?admin", $siteHdrs2)[1], 'folder=OldTrip'));
$rm = $siteAjax(['set_EXTRA_FOLDERS' => '{}']);
check('[extra folders] removing it takes it out of the gallery and out of the settings file (back to the default)', ($rm['ok'] ?? null) === true && !isset(cfg_read($siteCfg)['EXTRA_FOLDERS']) && !str_contains(http_get("$baseSite/")[1], 'id="group-oldtrip"'));
check('[extra folders] and nothing in that other folder was lost: the photo is byte-for-byte as it was and the caption saved there is intact (the gallery only adds its standard empty fields to a data file the first time it visits the folder)', md5_file("$extraSrc/archive1.jpg") === $extraPhotoMd5 && (read_data_file("$extraSrc/.gallery-items.php")['items'] ?? null) === ['archive1.jpg' => ['caption' => 'From the archive disk']]);
@unlink("$extraSrc-link");
// The nginx prefix.
foreach (['kb' => 'no leading slash', '/kb/' => 'a trailing slash', '/a b' => 'a space', '//x' => 'a double slash', '/../x' => 'a ..', '/kb?x=1' => 'a query', '/kb;x' => 'a semicolon'] as $value => $why) {
    $before = (string) file_get_contents($siteCfg);
    $r = $siteAjax(['set_NGINX_ACCEL_REDIRECT_PREFIX' => $value]);
    check("[nginx prefix] \"$value\" ($why) is refused and nothing is written", ($r['ok'] ?? null) === false && (string) file_get_contents($siteCfg) === $before);
}
check('[nginx prefix] a plain path like /kb is accepted and stored', ($siteAjax(['set_NGINX_ACCEL_REDIRECT_PREFIX' => '/kb'])['ok'] ?? null) === true && (cfg_read($siteCfg)['NGINX_ACCEL_REDIRECT_PREFIX'] ?? null) === '/kb');
check('[nginx prefix] clearing it takes it out of the file again', ($siteAjax(['set_NGINX_ACCEL_REDIRECT_PREFIX' => ''])['ok'] ?? null) === true && !isset(cfg_read($siteCfg)['NGINX_ACCEL_REDIRECT_PREFIX']));
$siteAjax(['set_AUTO_GROUP_BY_FOLDER' => '0']);

// An unwritable gallery folder: say so, and hand over the exact text.
$fxSiteRo = new_fixture_dir('sitesettings-ro');
make_jpeg("$fxSiteRo/a.jpg", 60, 60, 1, 2, 3);
copy(__DIR__ . '/../index.php', "$fxSiteRo/index.php");
file_put_contents("$fxSiteRo/.gallery-config.php", cfg_text(['ADMIN_PASSWORD' => 'ro-admin-pw-1234']));
$portSiteRo = find_free_port(); start_server($fxSiteRo, $portSiteRo);
$roAuth = 'Authorization: Basic ' . base64_encode('x:ro-admin-pw-1234');
[, $roAdmin, $roHd] = http_get("http://127.0.0.1:$portSiteRo/?admin", [$roAuth]);
chmod($fxSiteRo, 0555); chmod("$fxSiteRo/.gallery-config.php", 0644);
$roBytes = (string) file_get_contents("$fxSiteRo/.gallery-config.php");
[, $roRes] = user_submit("http://127.0.0.1:$portSiteRo/?admin", $roAdmin, 'value="save_site_settings"', ['set_ENABLE_VIDEO' => '1'], [$roAuth, 'Cookie: ' . extract_cookie_header($roHd)]);
chmod($fxSiteRo, 0775);
check('[site settings] when the folder can\'t be written it says so, saves nothing, and shows the exact file to create by hand', str_contains($roRes, 'Nothing was saved') && str_contains($roRes, '&quot;ENABLE_VIDEO&quot;: true') && (string) file_get_contents("$fxSiteRo/.gallery-config.php") === $roBytes && !glob("$fxSiteRo/.gallery-config.php.tmp.*"));

echo "== root identity: the gallery is configured like a folder ==\n";

$fxRoot = new_fixture_dir('rootid');
make_jpeg("$fxRoot/one.jpg", 60, 60, 10, 10, 10); make_jpeg("$fxRoot/two.jpg", 60, 60, 200, 10, 10);
mkdir("$fxRoot/Sub"); make_jpeg("$fxRoot/Sub/in.jpg", 60, 60, 20, 20, 20);
file_put_contents("$fxRoot/notes.md", "{gallery}\n# From The Heading\n\nSome longer notes.\n");
write_patched_index("$fxRoot/index.php", $indexContents, ['AUTO_GROUP_BY_FOLDER' => 'true', 'ADMIN_PASSWORD' => "'root-pw'"]);
$portRoot = find_free_port(); start_server($fxRoot, $portRoot); $baseRoot = "http://127.0.0.1:$portRoot";
$rootAuth = 'Authorization: Basic ' . base64_encode('x:root-pw');
[, $rAdmin, $rAdminHd] = http_get("$baseRoot/?admin", [$rootAuth]);
$rootHdrs = [$rootAuth, 'Cookie: ' . extract_cookie_header($rAdminHd)];
check('[root identity] the root\'s Settings tab has the same form a folder has: title, dates, description (no position at the root)', str_contains($rAdmin, 'Gallery settings') && str_contains($rAdmin, '<label>Gallery title</label>') && str_contains($rAdmin, 'name="dates"') && str_contains($rAdmin, 'name="description"') && str_contains($rAdmin, 'Save gallery settings') && !str_contains($rAdmin, 'name="sort_order"'));
[, $rHome0] = http_get("$baseRoot/");
check('[root identity] before anything is set, the "# Heading" in the notes file is still the page title', str_contains($rHome0, '<title>From The Heading — Gallery</title>') && !str_contains($rHome0, 'class="folder-dates"'));
[, $r] = user_submit("$baseRoot/?admin", $rAdmin, 'value="save_folder_identity"', ['display_name' => 'Summer 2026', 'dates' => 'June 1–9', 'description' => 'Two weeks of nothing but sun.'], $rootHdrs);
check('[root identity] saving the gallery settings works', str_contains($r, 'class="flash ok"') && str_contains($r, 'Gallery settings saved.'));
[, $rHome1] = http_get("$baseRoot/");
check('[root identity] the form\'s title beats the "# Heading" (page title, tab and h1)', str_contains($rHome1, '<title>Summer 2026 — Gallery</title>') && str_contains($rHome1, 'Summer 2026</a></h1>') && !str_contains($rHome1, 'From The Heading'));
check('[root identity] the dates show under the title, like a folder\'s', str_contains($rHome1, '<p class="folder-dates">June 1–9</p>'));
check('[root identity] the description is a centered note box above the notes box, like a folder\'s', (bool) preg_match('#</header>\s*<div class="notes">\s*<div class="note-box folder-description">Two weeks of nothing but sun\.</div>.*?Some longer notes\.#s', $rHome1));
check('[root identity] shared-link previews use the title and the description', str_contains($rHome1, 'property="og:title" content="Summer 2026"') && str_contains($rHome1, 'property="og:description" content="Two weeks of nothing but sun."'));
[, $rFolder] = http_get("$baseRoot/?folder=Sub");
check('[root identity] a folder\'s way back uses the gallery title', str_contains($rFolder, '&larr; Summer 2026</a>') || str_contains($rFolder, '← Summer 2026</a>'));
// A cover for shared links.
[, $rAdmin2] = http_get("$baseRoot/?admin", $rootHdrs);
[, $r] = user_submit("$baseRoot/?admin", $rAdmin2, 'name="file" value="two.jpg"', [], $rootHdrs);
$coverForm = ''; foreach (html_post_forms($rAdmin2) as $f) if (str_contains($f['html'], 'value="set_cover"') && str_contains($f['html'], 'value="two.jpg"')) $coverForm = $f['html'];
[$cs, $cb] = http_post("$baseRoot/?admin", form_default_fields($coverForm), $rootHdrs);
check('[root identity] "Set as cover" works at the root too', $cs === 200 && str_contains($cb, 'set as the gallery') && str_contains($cb, 'class="flash ok"'));
check('[root identity] ...and the shared-link picture is then that photo, not simply the first', (bool) preg_match('#property="og:image" content="[^"]*img=two\.jpg#', http_get("$baseRoot/")[1]));
// Hostile text is escaped like every other field.
[, $rAdmin3] = http_get("$baseRoot/?admin", $rootHdrs);
user_submit("$baseRoot/?admin", $rAdmin3, 'value="save_folder_identity"', ['display_name' => '<b>x</b>', 'description' => '<script>alert(1)</script>'], $rootHdrs);
[, $rHome2] = http_get("$baseRoot/");
check('[root identity] hostile title and description are escaped', !str_contains($rHome2, '<script>alert(1)</script>') && str_contains($rHome2, '&lt;script&gt;alert(1)&lt;/script&gt;') && str_contains($rHome2, '&lt;b&gt;x&lt;/b&gt;'));
// Blank the title again: the heading comes back.
[, $rAdmin4] = http_get("$baseRoot/?admin", $rootHdrs);
user_submit("$baseRoot/?admin", $rAdmin4, 'value="save_folder_identity"', ['display_name' => ''], $rootHdrs);
check('[root identity] clearing the title falls back to the "# Heading" again', str_contains(http_get("$baseRoot/")[1], '<title>From The Heading — Gallery</title>'));
// Folders are unchanged: they still have a position and their own form.
[, $subAdmin] = http_get("$baseRoot/?admin&folder=Sub", $rootHdrs);
check('[root identity] a folder\'s own form is unchanged (display name, position)', str_contains($subAdmin, '<label>Display name</label>') && str_contains($subAdmin, 'name="sort_order"') && str_contains($subAdmin, 'Save folder settings'));

echo "== maps per folder: each folder and the root can show their own map, with their own settings ==\n";

$fxMapF = new_fixture_dir('foldermap');
mkdir("$fxMapF/Paris/Montmartre", 0777, true); mkdir("$fxMapF/Lisbon"); mkdir("$fxMapF/NoLoc");
foreach (['top.jpg', 'Paris/a.jpg', 'Paris/b.jpg', 'Paris/hidden.jpg', 'Paris/Montmartre/m.jpg', 'Lisbon/l.jpg', 'NoLoc/n.jpg'] as $i => $f) make_jpeg("$fxMapF/$f", 60, 60, 20 + $i * 20, 40, 90);
$mapLoc = fn ($lat, $lng) => ['location' => ['lat' => $lat, 'lng' => $lng, 'label' => ''], 'locationVisibility' => 'inherit'];
write_data_file("$fxMapF/.gallery-items.php", ['items' => ['top.jpg' => $mapLoc(40.7, -74.0)], 'groups' => [], 'locationEnabled' => true]);
write_data_file("$fxMapF/Paris/.gallery-items.php", ['items' => ['a.jpg' => $mapLoc(48.85, 2.35) + ['caption' => 'A'], 'b.jpg' => $mapLoc(48.92, 2.35), 'hidden.jpg' => $mapLoc(48.9, 2.4) + ['restriction' => 'private']], 'groups' => [], 'locationEnabled' => true]);
write_data_file("$fxMapF/Paris/Montmartre/.gallery-items.php", ['items' => ['m.jpg' => $mapLoc(48.886, 2.343)], 'groups' => [], 'locationEnabled' => true, 'displayName' => 'The Hill']);
write_data_file("$fxMapF/Lisbon/.gallery-items.php", ['items' => ['l.jpg' => $mapLoc(38.72, -9.14)], 'groups' => [], 'locationEnabled' => true]);
write_data_file("$fxMapF/NoLoc/.gallery-items.php", ['items' => ['n.jpg' => $mapLoc(10.0, 10.0)], 'groups' => [], 'locationEnabled' => false]);
file_put_contents("$fxMapF/.gallery-config.php", cfg_text(['ADMIN_PASSWORD' => 'map-admin-pw-123', 'AUTO_GROUP_BY_FOLDER' => true, 'ENABLE_PHOTO_LOCATION' => true, 'ENABLE_MAP' => true]));
copy(__DIR__ . '/../index.php', "$fxMapF/index.php");
$portMapF = find_free_port(); start_server($fxMapF, $portMapF); $baseMapF = "http://127.0.0.1:$portMapF";
$mapAuth = 'Authorization: Basic ' . base64_encode('x:map-admin-pw-123');
[, $mAdminPg, $mAdminHd] = http_get("$baseMapF/?admin&folder=Paris", [$mapAuth]);
$mapHdrs = [$mapAuth, 'Cookie: ' . extract_cookie_header($mAdminHd)];
$mapFiles = function ($html) { if (!preg_match('/var clusters = (\[.*?\]);/s', $html, $m)) return null; $clusters = json_decode($m[1], true); $files = []; foreach ($clusters as $c) foreach ($c as $pt) $files[] = $pt['file']; sort($files); return ['files' => $files, 'clusters' => count($clusters), 'raw' => $clusters]; };
$mapForm = function ($pg) { foreach (html_post_forms($pg) as $f) if (str_contains($f['html'], 'value="save_map_settings"')) return $f['html']; return ''; };
$rootMapPage = http_get("$baseMapF/")[1]; $rm = $mapFiles($rootMapPage);
check('[folder maps] the gallery root keeps its map by default, plotting every visible located photo from every folder (and no hidden or location-off ones)', $rm !== null && $rm['files'] === ['Lisbon/l.jpg', 'Paris/Montmartre/m.jpg', 'Paris/a.jpg', 'Paris/b.jpg', 'top.jpg'] && str_contains($rootMapPage, 'View full map'));
check('[folder maps] a folder has NO map until it is switched on (a folder page must not start loading a third-party map by surprise)', $mapFiles(http_get("$baseMapF/?folder=Paris")[1]) === null && http_get("$baseMapF/?map&folder=Paris")[0] === 404);
check('[folder maps] the admin offers the same map settings for a folder and for the gallery: show it, and the pin grouping', (function () use ($mapForm, $mAdminPg, $baseMapF, $mapHdrs) { $f = $mapForm($mAdminPg); $r = $mapForm(http_get("$baseMapF/?admin", $mapHdrs)[1]); return $f !== '' && $r !== '' && str_contains($f, 'name="map_enabled"') && str_contains($f, 'name="map_radius"') && str_contains($r, 'name="map_enabled"') && str_contains($r, 'name="map_radius"'); })());
[, $mSaved] = user_submit("$baseMapF/?admin&folder=Paris", $mAdminPg, 'value="save_map_settings"', ['map_enabled' => '1', 'map_radius' => ''], $mapHdrs);
check('[folder maps] switching on Paris\'s map saves, and says what it does', str_contains($mSaved, 'A map now shows on this folder') && str_contains($mSaved, 'class="flash ok"'));
$parisPage = http_get("$baseMapF/?folder=Paris")[1]; $pm = $mapFiles($parisPage);
check('[folder maps] Paris\'s page then shows a map of just what is in Paris and below it: not Lisbon, not the loose photo at the root, not the hidden photo, not the location-off folder', $pm !== null && $pm['files'] === ['Paris/Montmartre/m.jpg', 'Paris/a.jpg', 'Paris/b.jpg'] && str_contains($parisPage, 'href="?map&amp;folder=Paris"'));
$colors = []; foreach ($pm['raw'] as $c) foreach ($c as $pt) $colors[basename(dirname($pt['file'])) . '|' . $pt['label']] = $pt['color'];
check('[folder maps] points are coloured by sub-folder: the photos directly in Paris under the folder\'s name, the photos in Montmartre under its display name, in two colours, linking to it', count(array_unique($colors)) === 2 && isset($colors['Paris|Paris']) && isset($colors['Montmartre|The Hill']) && str_contains($parisPage, '"link":"?folder=Paris%2FMontmartre"'));
check('[folder maps] with the site-wide pin grouping (50 miles) all three Paris photos are one pin', $pm['clusters'] === 1);
[, $mAdminPg2] = http_get("$baseMapF/?admin&folder=Paris", $mapHdrs);
user_submit("$baseMapF/?admin&folder=Paris", $mAdminPg2, 'value="save_map_settings"', ['map_enabled' => '1', 'map_radius' => '1'], $mapHdrs);
check('[folder maps] a folder\'s own pin grouping (1 mile) overrides the site-wide one: the same three photos are now three pins', ($mapFiles(http_get("$baseMapF/?folder=Paris")[1])['clusters'] ?? 0) === 3);
check('[folder maps] ...without touching any other page: the root map, on the site-wide 50 miles, still groups Paris into one pin (three pins in all: New York, Paris, Lisbon)', ($mapFiles(http_get("$baseMapF/")[1])['clusters'] ?? 0) === 3);
[, $mapOnly] = http_get("$baseMapF/?map&folder=Paris");
check('[folder maps] the folder has its own shareable map page: only its photos, its own link in the share preview, and a way back to the folder', str_contains($mapOnly, 'og:url" content="http://127.0.0.1:' . $portMapF . '/?map&amp;folder=Paris"') && $mapFiles($mapOnly)['files'] === ['Paris/Montmartre/m.jpg', 'Paris/a.jpg', 'Paris/b.jpg'] && str_contains($mapOnly, 'href="?folder=Paris" class="back"'));
check('[folder maps] a folder that has not switched its map on, or that does not exist, has no map page (404)', http_get("$baseMapF/?map&folder=Lisbon")[0] === 404 && http_get("$baseMapF/?map&folder=Nope")[0] === 404);
[, $badR] = user_submit("$baseMapF/?admin&folder=Paris", $mAdminPg2, 'value="save_map_settings"', ['map_enabled' => '1', 'map_radius' => '0'], $mapHdrs);
[, $badR2] = user_submit("$baseMapF/?admin&folder=Paris", $mAdminPg2, 'value="save_map_settings"', ['map_enabled' => '1', 'map_radius' => 'abc'], $mapHdrs);
check('[folder maps] a pin grouping that is not a number from 1 to 10000 is refused with the reason, and the saved one stays', str_contains($badR, 'from 1 to 10000') && str_contains($badR2, 'from 1 to 10000') && ($mapFiles(http_get("$baseMapF/?folder=Paris")[1])['clusters'] ?? 0) === 3);
$rootMapAdmin = http_get("$baseMapF/?admin", $mapHdrs)[1];
$rootMapFields = form_default_fields($mapForm($rootMapAdmin)); $rootMapFields['map_enabled'] = '0'; $rootMapFields['map_radius'] = '10';   // the hidden 0 is what an unchecked switch sends
http_post("$baseMapF/?admin", $rootMapFields, $mapHdrs);
check('[folder maps] the gallery front page can switch its map off too, and then its map page is gone as well', !str_contains(http_get("$baseMapF/")[1], 'View full map') && http_get("$baseMapF/?map")[0] === 404 && $mapFiles(http_get("$baseMapF/?folder=Paris")[1]) !== null);
user_submit("$baseMapF/?admin", http_get("$baseMapF/?admin", $mapHdrs)[1], 'value="save_map_settings"', ['map_enabled' => '1', 'map_radius' => ''], $mapHdrs);
check('[folder maps] ...and switching it back on restores it', $mapFiles(http_get("$baseMapF/")[1]) !== null && http_get("$baseMapF/?map")[0] === 200);
check('[folder maps] the admin (who sees hidden items) gets the hidden photo on the map; a visitor never does', in_array('Paris/hidden.jpg', $mapFiles(http_get("$baseMapF/?folder=Paris", $mapHdrs)[1])['files'] ?? [], true) && !in_array('Paris/hidden.jpg', $mapFiles(http_get("$baseMapF/?folder=Paris")[1])['files'], true));
$parisItems = read_data_file("$fxMapF/Paris/.gallery-items.php")['items'];
check('[folder maps] saving map settings only stores the two map settings: every photo\'s caption, location and hide flag is as it was', ($parisItems['a.jpg']['caption'] ?? '') === 'A' && ($parisItems['hidden.jpg']['restriction'] ?? '') === 'private' && ($parisItems['b.jpg']['location']['lat'] ?? 0) == 48.92 && (read_data_file("$fxMapF/Paris/.gallery-items.php")['mapRadius'] ?? null) === 1);
// Flipped in place by the page's script: each control posts only itself, so changing one never disturbs the other.
$mapAjax = function ($path, array $fields) use ($baseMapF, $mapHdrs, $mapForm) {
    [, $pg] = http_get("$baseMapF/?admin$path", $mapHdrs); $ff = form_default_fields($mapForm($pg));
    [, $body] = http_post("$baseMapF/?admin$path", array_merge(['csrf_token' => $ff['csrf_token'], 'action' => 'save_map_settings'], $fields), array_merge($mapHdrs, ['X-Requested-With: gallery-fetch']));
    return json_decode($body, true) ?? ['ok' => null, 'text' => $body];
};
$parisData = fn () => read_data_file("$fxMapF/Paris/.gallery-items.php");
$mapAjax('&folder=Paris', ['map_radius' => '7']);
$jOff = $mapAjax('&folder=Paris', ['map_enabled' => '0']);
check('[instant] switching a folder\'s map off by itself (the page\'s script posts just that switch) answers JSON and leaves its pin grouping alone', ($jOff['ok'] ?? null) === true && str_contains((string) $jOff['text'], 'no longer shows') && ($parisData()['mapEnabled'] ?? null) === false && ($parisData()['mapRadius'] ?? null) === 7);
$jOn = $mapAjax('&folder=Paris', ['map_enabled' => '1']);
check('[instant] ...and switching it back on does not touch the radius either', ($jOn['ok'] ?? null) === true && ($parisData()['mapEnabled'] ?? null) === true && ($parisData()['mapRadius'] ?? null) === 7);
$jRad = $mapAjax('&folder=Paris', ['map_radius' => '12']);
check('[instant] changing only the pin grouping leaves the switch as it was', ($jRad['ok'] ?? null) === true && ($parisData()['mapRadius'] ?? null) === 12 && ($parisData()['mapEnabled'] ?? null) === true);
$jBlank = $mapAjax('&folder=Paris', ['map_radius' => '']);
check('[instant] clearing the pin grouping goes back to the site-wide setting, and says so', ($jBlank['ok'] ?? null) === true && str_contains((string) $jBlank['text'], 'site-wide') && array_key_exists('mapRadius', $parisData()) && $parisData()['mapRadius'] === null);
$jBad = $mapAjax('&folder=Paris', ['map_radius' => '-4']);
check('[instant] an invalid pin grouping answers JSON with the reason and changes nothing', ($jBad['ok'] ?? null) === false && str_contains((string) $jBad['text'], 'from 1 to 10000') && array_key_exists('mapRadius', $parisData()) && $parisData()['mapRadius'] === null);
$locAjax = function ($path, $on) use ($baseMapF, $mapHdrs, $mapForm) {
    [, $pg] = http_get("$baseMapF/?admin$path", $mapHdrs); $ff = form_default_fields($mapForm($pg));
    [, $body] = http_post("$baseMapF/?admin$path", ['csrf_token' => $ff['csrf_token'], 'action' => 'toggle_location_enabled', 'enabled' => $on ? '1' : '0'], array_merge($mapHdrs, ['X-Requested-With: gallery-fetch']));
    return json_decode($body, true) ?? ['ok' => null, 'text' => $body];
};
$jLocOff = $locAjax('&folder=Lisbon', false);
check('[instant] the folder location switch works in place too: off answers JSON, says saved coordinates are kept, and the folder stops showing them', ($jLocOff['ok'] ?? null) === true && str_contains((string) $jLocOff['text'], 'kept') && empty(read_data_file("$fxMapF/Lisbon/.gallery-items.php")['locationEnabled']) && read_data_file("$fxMapF/Lisbon/.gallery-items.php")['items']['l.jpg']['location']['lat'] == 38.72);
$jLocOn = $locAjax('&folder=Lisbon', true);
check('[instant] ...and on answers JSON and turns it back on', ($jLocOn['ok'] ?? null) === true && !empty(read_data_file("$fxMapF/Lisbon/.gallery-items.php")['locationEnabled']));
[, $mapAdminAgain] = http_get("$baseMapF/?admin&folder=Paris", $mapHdrs);
check('[instant] in the admin the map and location settings are switches with orange warnings beside them and no dialogs: a Save button exists only for browsers without JavaScript', substr_count($mapAdminAgain, 'class="instant-form') >= 2 && str_contains($mapAdminAgain, 'class="warn-note">A map makes each visitor') && !preg_match('/<form[^>]*instant-form[^>]*onsubmit/', $mapAdminAgain) && substr_count(preg_replace('#<style.*?</style>#s', '', $mapAdminAgain), 'confirm(') <= 2);

// The whole map feature off: nothing anywhere.
file_put_contents("$fxMapF/.gallery-config.php", cfg_text(['ADMIN_PASSWORD' => 'map-admin-pw-123', 'AUTO_GROUP_BY_FOLDER' => true, 'ENABLE_PHOTO_LOCATION' => true]));
check('[folder maps] with the trip map off for the whole gallery there is no map anywhere, whatever each folder says, and the admin says why', $mapFiles(http_get("$baseMapF/?folder=Paris")[1]) === null && $mapFiles(http_get("$baseMapF/")[1]) === null && str_contains(http_get("$baseMapF/?admin&folder=Paris", $mapHdrs)[1], 'switched off for the whole gallery') && !str_contains(http_get("$baseMapF/?map&folder=Paris")[1], 'trip-map-full'));

echo "== visits: the site total counts one per visitor per half hour, not every page load ==\n";

$fxVis = new_fixture_dir('visits');
mkdir("$fxVis/Paris"); make_jpeg("$fxVis/top.jpg", 60, 60, 10, 10, 10); make_jpeg("$fxVis/Paris/a.jpg", 60, 60, 20, 20, 20);
file_put_contents("$fxVis/.gallery-config.php", cfg_text(['TRACK_VIEWS' => true, 'SHOW_VIEW_COUNTS_PUBLICLY' => true, 'AUTO_GROUP_BY_FOLDER' => true, 'ADMIN_PASSWORD' => 'visit-admin-pw-123']));
copy(__DIR__ . '/../index.php', "$fxVis/index.php");
$portVis = find_free_port(); start_server($fxVis, $portVis); $baseVis = "http://127.0.0.1:$portVis";
// A browser: keeps the cookies it is given and sends them back.
$vGet = function ($path, &$jar, $headers = []) use ($baseVis) {
    $cookie = implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($jar), $jar));
    [$st, $body, $hd] = http_get("$baseVis$path", array_merge($cookie !== '' ? ["Cookie: $cookie"] : [], $headers));
    foreach ($hd as $h) if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $h, $m)) $jar[trim($m[1])] = $m[2];
    return [$st, $body, $hd];
};
$vCount = fn($body) => preg_match('/· ([0-9,]+) visits?/', $body, $m) ? (int) str_replace(',', '', $m[1]) : null;
$alice = [];
[, $v1, $vh1] = $vGet('/', $alice);
$counts = [$vCount($v1)];
foreach (['/?folder=Paris', '/?photo=top.jpg', '/', '/?folder=Paris', '/'] as $path) $counts[] = $vCount($vGet($path, $alice)[1]);
check('[visits] one visitor opening the front page, a folder, a shared photo link and reloading counts as ONE visit throughout, not six', $counts === [1, 1, 1, 1, 1, 1], json_encode($counts));
$visitCookie = ''; foreach ($vh1 as $h) if (stripos($h, 'Set-Cookie: gallery_visit=') === 0) $visitCookie = $h;
check('[visits] the visit is remembered in a small first-party cookie: HttpOnly, SameSite=Lax, and expiring in about half an hour', $visitCookie !== '' && stripos($visitCookie, 'httponly') !== false && stripos($visitCookie, 'samesite=lax') !== false && (bool) preg_match('/expires=/i', $visitCookie));
$bob = [];
check('[visits] a different visitor (a browser with no cookie) is a second visit, and the footer says "visits"', $vCount($vGet('/', $bob)[1]) === 2 && str_contains($vGet('/', $bob)[1], '· 2 visits'));
$late = ['gallery_visit' => (string) (time() - 1801)]; $justIn = ['gallery_visit' => (string) (time() - 1700)];
check('[visits] someone who comes back after more than half an hour is a new visit; within half an hour is still the same one', $vCount($vGet('/', $late)[1]) === 3 && $vCount($vGet('/', $justIn)[1]) === 3);
check('[visits] a visit keeps going as long as they keep clicking: the half hour slides forward with each page', (function () use ($vGet, $vCount) { $j = ['gallery_visit' => (string) (time() - 1700)]; $vGet('/?folder=Paris', $j); return (int) $j['gallery_visit'] >= time() - 2 && $vCount($vGet('/', $j)[1]) === 3; })());
$none = [];
[, $botBody, $botHd] = $vGet('/', $none, ['User-Agent: Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)']);
check('[visits] a search-engine or link-preview bot is not counted, and is given no cookie', $vCount($botBody) === 3 && !array_filter($botHd, fn($h) => stripos($h, 'Set-Cookie: gallery_visit') === 0));
$headCtx = stream_context_create(['http' => ['method' => 'HEAD', 'ignore_errors' => true, 'timeout' => 5]]);
@file_get_contents("$baseVis/", false, $headCtx);
$pref = []; $vGet('/', $pref, ['Purpose: prefetch']); $pref2 = []; $vGet('/', $pref2, ['Sec-Purpose: prefetch;prerender']);
check('[visits] a HEAD request (a link checker) and a browser prefetching or prerendering a page are not visits', $vCount($vGet('/', $none, ['User-Agent: Mozilla/5.0 (compatible; Googlebot/2.1)'])[1]) === 3 && $pref === [] && $pref2 === []);
// The owner.
[, , $visAdminHd] = http_post("$baseVis/?admin", ['login_password' => 'visit-admin-pw-123'], [], false);
$owner = []; foreach (explode('; ', (string) extract_cookie_header($visAdminHd)) as $pair) { if (str_contains($pair, '=')) { [$k, $v] = explode('=', $pair, 2); $owner[$k] = $v; } }
$ownerBody = $vGet('/', $owner)[1]; $ownerHd = $vGet('/?folder=Paris', $owner)[2];
check('[visits] the admin\'s own browsing is not counted (and sets no visit cookie), however much they click around', $vCount($ownerBody) === 3 && !isset($owner['gallery_visit']) && !array_filter($ownerHd, fn($h) => stripos($h, 'Set-Cookie: gallery_visit') === 0));
$pingOwner = json_decode($vGet('/?track=top.jpg', $owner)[1], true);
check('[visits] ...nor are the admin\'s photo views', ($pingOwner['ok'] ?? null) === false);
$carol = [];
$pingCarol = json_decode($vGet('/?track=top.jpg', $carol)[1], true);
$pingCarol2 = json_decode($vGet('/?track=top.jpg', $carol)[1], true);
check('[visits] a visitor\'s photo views still count, once per photo per half hour as before', ($pingCarol['ok'] ?? null) === true && ($pingCarol['photoViews'] ?? 0) === 1 && ($pingCarol2['photoViews'] ?? 0) === 1);
$adminVisits = http_get("$baseVis/?admin", array_merge(['Authorization: Basic ' . base64_encode('x:visit-admin-pw-123')]))[1];
check('[visits] the admin says what is counted: visits in total, one per visitor per half hour, not counting you or bots', str_contains($adminVisits, 'visits in total') && str_contains($adminVisits, 'one per visitor per half hour'));
$dave = []; $vGet('/', $dave);
check('[visits] in the end the total is exactly the number of real visits: Alice, Bob, one returning after a long gap, and Dave, which is four, with everything else (bots, prefetches, the admin, reloads) left out', $vCount($vGet('/', $dave)[1]) === 4);

echo "== admin folder tree: nesting, counts, the chosen folder ==\n";
[, $treePage] = http_get("$baseDepth/?admin$parisAdminQuery", [$depthAuth, "Cookie: $depthCookie"]);
preg_match('#<nav class="admin-folder-nav".*?</nav>#s', $treePage, $treeM); $tree = $treeM[0] ?? '';
check('[tree] the folders are a nested tree: each folder\'s children are a list inside it (Europe > France > Paris)', (bool) preg_match('#<li class="has-children">.*?fname">Europe</span>.*?<ul><li class="has-children">.*?fname">France</span>.*?<ul>.*?fname">Paris</span>#s', $tree));
check('[tree] every folder, and the root, carries a count of what is directly in it', substr_count($tree, 'class="fcount"') === substr_count($tree, '<a ') && preg_match('#fname">Paris</span><span class="fcount">\d+</span>#', $tree));
check('[tree] a folder\'s tooltip gives its own count and, for a parent, the count including its subfolders', (bool) preg_match('#title="Europe: \d+ here, \d+ including subfolders"#', $tree));
check('[tree] only the folder being edited is marked current, and it is Paris', substr_count($tree, 'class="current" aria-current="page"') === 1 && (bool) preg_match('#class="current" aria-current="page"[^>]*><span class="fname">Paris</span>#', $tree));
check('[tree] a folder with subfolders has a fold toggle for them, and a folder without has none', substr_count($tree, 'class="ftoggle"') === substr_count($tree, '<li class="has-children">') && substr_count($tree, 'class="ftoggle"') >= 2);
check('[tree] the chosen folder is bold, like the active tab, in the page\'s styles', (bool) preg_match('#\.admin-folder-nav a\.current \{[^}]*font-weight:\s*600#', $treePage));
[, $rootTreePage] = http_get("$baseDepth/?admin", [$depthAuth, "Cookie: $depthCookie"]);
check('[tree] at the root the Root entry is the current one', (bool) preg_match('#class="current" aria-current="page"[^>]*><span class="fname">Root</span>#', $rootTreePage));

echo "== counts: photos and videos are counted separately ==\n";

$fxCount = new_fixture_dir('counts');
mkdir("$fxCount/Pics"); mkdir("$fxCount/Clips"); mkdir("$fxCount/Mixed");
make_jpeg("$fxCount/Pics/one.jpg", 60, 60, 10, 10, 10);
make_minimal_mp4("$fxCount/Clips/clip.mp4", 2);
make_jpeg("$fxCount/Mixed/a.jpg", 60, 60, 20, 20, 20); make_jpeg("$fxCount/Mixed/b.jpg", 60, 60, 30, 30, 30); make_minimal_mp4("$fxCount/Mixed/c.mp4", 2);
write_patched_index("$fxCount/index.php", $indexContents, ['AUTO_GROUP_BY_FOLDER' => 'true', 'ENABLE_VIDEO' => 'true']);
$portCount = find_free_port(); start_server($fxCount, $portCount); $baseCount = "http://127.0.0.1:$portCount";
[, $countHome] = http_get("$baseCount/");
check('[counts] the root header says "3 photos · 2 videos" (not "5 photos") for 3 photos and 2 videos spread over three folders', str_contains($countHome, '<p>3 photos · 2 videos'));
check('[counts] a folder holding one photo says "1 photo" on its cover card', (bool) preg_match('#id="group-pics".*?folder-cover-count">1 photo</span>#s', $countHome));
check('[counts] a folder holding only a video says "1 video", not "1 photo"', (bool) preg_match('#id="group-clips".*?folder-cover-count">1 video</span>#s', $countHome));
check('[counts] a mixed folder says "2 photos · 1 video"', (bool) preg_match('#id="group-mixed".*?folder-cover-count">2 photos · 1 video</span>#s', $countHome));
[, $clipsPage] = http_get("$baseCount/?folder=Clips");
check('[counts] the video-only folder\'s own page header says "1 video"', str_contains($clipsPage, '<p>1 video') && !str_contains($clipsPage, '<p>1 photo'));
check('[counts] the shared-link preview text uses the same wording', str_contains($countHome, 'property="og:description" content="3 photos · 2 videos"') && str_contains($clipsPage, 'property="og:description" content="1 video"'));

echo "== login: a password form, not a browser pop-up; discreet entry; empty notes box ==\n";

$fxLogin = new_fixture_dir('login');
make_jpeg("$fxLogin/one.jpg", 60, 60, 10, 10, 10);
mkdir("$fxLogin/Sub"); make_jpeg("$fxLogin/Sub/two.jpg", 60, 60, 20, 20, 20);
file_put_contents("$fxLogin/trip.md", "{gallery}\n# Summer in Lisbon\n");
write_patched_index("$fxLogin/index.php", $indexContents, ['AUTO_GROUP_BY_FOLDER' => 'true', 'ADMIN_PASSWORD' => "'login-pw'", 'PRIVATE_VIEW_PASSWORD' => "'view-pw'"]);
$portLogin = find_free_port(); start_server($fxLogin, $portLogin); $baseLogin = "http://127.0.0.1:$portLogin";
$cookieOf = fn ($hd) => extract_cookie_header($hd);

// Wrong password: the form again, with a message, and nothing logged in.
[$s1, $b1, $h1] = http_post("$baseLogin/?admin", ['login_password' => 'nope']);
check('[login] a wrong password re-shows the form with a plain message (200), and sets no session', $s1 === 200 && str_contains($b1, 'That password isn&#039;t right') && str_contains($b1, 'name="login_password"') && !array_filter($h1, fn($h) => stripos($h, 'Set-Cookie: gallery_admin_') === 0));
// Right password: redirected back to the same admin URL, with a session cookie; no Authorization header needed afterwards.
[$s2, , $h2] = http_post("$baseLogin/?admin&folder=Sub", ['login_password' => 'login-pw'], [], false);
$loginCookie = $cookieOf($h2); $loc2 = ''; foreach ($h2 as $h) if (stripos($h, 'Location:') === 0) $loc2 = trim(substr($h, 9));
check('[login] the right password redirects to the very URL asked for, and sets the session cookie', $s2 === 303 && $loc2 === '?admin&folder=Sub' && str_contains($loginCookie, 'gallery_admin_'));
[$s3, $b3] = http_get("$baseLogin/?admin&folder=Sub", ["Cookie: $loginCookie"]);
check('[login] with just that cookie (no Authorization header) the admin page opens, on the right folder', $s3 === 200 && str_contains($b3, 'Gallery Admin') && str_contains($b3, 'Editing: Sub'));
check('[admin layout] a sticky top bar carries the title, the folder being edited, section links and Log out', str_contains($b3, '<header class="admin-bar">') && str_contains($b3, 'Editing: Sub') && str_contains($b3, 'href="#photos"') && str_contains($b3, 'href="#settings"') && str_contains($b3, 'value="logout"'));
check('[admin layout] folders are a tree beside the content, with the current one marked', str_contains($b3, 'class="admin-folder-nav"') && str_contains($b3, 'class="current" aria-current="page"') && str_contains($b3, '<main class="admin-main"'));
check('[admin layout] the sections are real tabs (Photos, Folder settings, Notes, Export), each with its own panel; no Hidden tab when nothing is hidden', str_contains($b3, 'role="tablist"') && str_contains($b3, 'data-tab="photos"') && str_contains($b3, 'data-tab="settings"') && str_contains($b3, 'data-tab="notes"') && str_contains($b3, 'data-tab="export"') && str_contains($b3, '<div class="tab-panel" data-tab="export">') && !str_contains($b3, 'data-tab="hidden"'));
check('[admin layout] the Photos tab is a grid of thumbnails with one editor panel above it (every photo\'s own form is in the page, shown only when selected)', str_contains($b3, 'class="photo-editor" id="photo-editor"') && str_contains($b3, 'class="thumb-grid"') && substr_count($b3, 'class="thumb"') === substr_count($b3, 'class="item-editor"') && substr_count($b3, 'class="thumb"') >= 1 && strpos($b3, 'id="photo-editor"') < strpos($b3, 'class="thumb-grid"'));
check('[admin layout] each thumbnail has a stable id to link to and points at its editor', (bool) preg_match('#<button type="button" class="thumb" id="photo-([0-9a-f]{32})" data-edit="edit-\1"#', $b3) && (bool) preg_match('#<div class="item-editor" id="edit-[0-9a-f]{32}">#', $b3));
check('[admin layout] the anchors inside the panels still exist', str_contains($b3, 'id="settings"') && str_contains($b3, 'id="photos"') && str_contains($b3, 'id="notes"') && str_contains($b3, 'id="export"'));
check('[login] the same URL without the cookie is the form again', str_contains(http_get("$baseLogin/?admin&folder=Sub")[1], 'name="login_password"'));
check('[login] a login also counts as private view (the viewer cookie is set too)', str_contains($loginCookie, '=') && substr_count($loginCookie, '=') >= 2);
// A saved change from an expired session must not be saved, and must say why.
[$s4, $b4] = http_post("$baseLogin/?admin", ['action' => 'save_item', 'file' => 'one.jpg', 'caption' => 'should not stick']);
check('[login] a change posted without a session is refused with a "session expired" form, and nothing is saved', $s4 === 200 && str_contains($b4, 'Your session expired') && !is_file("$fxLogin/.gallery-items.php"));
// Basic auth for scripts still works.
check('[login] a script can still send a Basic Authorization header instead', str_contains(http_get("$baseLogin/?admin", ['Authorization: Basic ' . base64_encode('x:login-pw')])[1], 'Gallery Admin'));
// Logging out.
[, $b5] = http_get("$baseLogin/?admin", ["Cookie: $loginCookie"]);
$logoutForm = ''; foreach (html_post_forms($b5) as $f) if (str_contains($f['html'], 'value="logout"')) $logoutForm = $f['html'];
check('[login] the admin page has a Log out button', $logoutForm !== '');
[$s6, , $h6] = http_post("$baseLogin/?admin", form_default_fields($logoutForm), ["Cookie: $loginCookie"], false);
check('[login] logging out redirects away and expires the cookies', $s6 === 303 && (bool) array_filter($h6, fn($h) => stripos($h, 'Set-Cookie: gallery_admin_') === 0 && stripos($h, 'expires=Thu, 01 Jan 1970') !== false));
check('[login] after logging out, the old cookie no longer opens the admin', str_contains(http_get("$baseLogin/?admin", ["Cookie: $loginCookie"])[1], 'name="login_password"'));
// Five wrong passwords lock the next attempt for a minute, even a right one.
for ($i = 0; $i < 5; $i++) http_post("$baseLogin/?admin", ['login_password' => 'bad' . $i]);
[$s7] = http_post("$baseLogin/?admin", ['login_password' => 'login-pw']);
check('[login] five wrong passwords lock the next attempt (429), even with the right password', $s7 === 429);
// The private view uses the same kind of form.
[$sv1, $bv1] = http_get("$baseLogin/?reveal");
check('[login] ?reveal shows a one-field password form too', $sv1 === 200 && str_contains($bv1, 'name="login_password"') && str_contains($bv1, 'Private view'));
[$sv2, , $hv2] = http_post("$baseLogin/?reveal", ['login_password' => 'view-pw'], [], false);
check('[login] the right private-view password redirects to the plain gallery and sets the viewer cookie', $sv2 === 302 && str_contains($cookieOf($hv2), '='));

// A browser that saved a Basic password from an older version keeps sending it. That must never bring the
// browser's own dialog back: a 401 with the form, no WWW-Authenticate, and the form still logs you in.
$fxStale = new_fixture_dir('stale-password');
make_jpeg("$fxStale/a.jpg", 60, 60, 1, 2, 3); mkdir("$fxStale/Sub"); make_jpeg("$fxStale/Sub/b.jpg", 60, 60, 4, 5, 6);
file_put_contents("$fxStale/.gallery-config.php", cfg_text(['ADMIN_PASSWORD' => 'the-current-password-1', 'PRIVATE_VIEW_PASSWORD' => 'view-pw', 'AUTO_GROUP_BY_FOLDER' => true, 'ENABLE_MAP' => true, 'ENABLE_PHOTO_LOCATION' => true]));
copy(__DIR__ . '/../index.php', "$fxStale/index.php");
$portStale = find_free_port(); start_server($fxStale, $portStale); $baseStale = "http://127.0.0.1:$portStale";
$staleHdr = ['Authorization: Basic ' . base64_encode('admin:a-password-saved-long-ago')];
$challenges = [];
foreach (['?admin', '?reveal', '?admin&folder=Sub', '?admin&hidden=1', '?map', ''] as $route) {
    [$stSt, , $stHd] = http_get("$baseStale/$route", $staleHdr, false);
    foreach ($stHd as $h) if (stripos($h, 'WWW-Authenticate') === 0) $challenges[] = "$route: $h";
}
[$stA, $stBodyA] = http_get("$baseStale/?admin", $staleHdr);
[$stR, $stBodyR] = http_get("$baseStale/?reveal", $staleHdr);
check('[stale password] no route ever answers with a WWW-Authenticate challenge, even when the browser re-sends an old saved password', $challenges === [], implode(' | ', $challenges));
check('[stale password] ?admin and ?reveal answer such a request with 401 and the ordinary login form, saying the saved password doesn\'t match', $stA === 401 && $stR === 401 && str_contains($stBodyA, 'name="login_password"') && str_contains($stBodyR, 'name="login_password"') && str_contains($stBodyA, 'saved password') && str_contains($stBodyR, 'saved password'));
check('[stale password] typing the current password into the form still logs you in while the browser keeps sending the old one', http_post("$baseStale/?reveal", ['login_password' => 'view-pw'], $staleHdr, false)[0] === 302);
check('[stale password] the source never sends a WWW-Authenticate header at all (a guard against it creeping back)', !preg_match('/header\(\s*[\'"]WWW-Authenticate/i', $indexContents));

// The gallery title is the (unmarked) way to the admin.
[, $homeLogin] = http_get("$baseLogin/");
check('[login] the gallery title links to ?admin', str_contains($homeLogin, '<h1><a class="title-admin" rel="nofollow" href="?admin">Summer in Lisbon</a></h1>'));
check('[login] ...and the link carries no styling that would give it away (no underline, colour, cursor or hover rule)', (bool) preg_match('/header h1 a\.title-admin \{\s*color: inherit;\s*text-decoration: none;\s*cursor: inherit;/', $homeLogin) && !str_contains($homeLogin, 'title-admin:hover'));
[, $subLogin] = http_get("$baseLogin/?folder=Sub");
check('[login] on a folder page the title leads to that folder\'s admin', str_contains($subLogin, 'href="?admin&amp;folder=Sub"'));
$fxPlainTitle = new_fixture_dir('plaintitle'); make_jpeg("$fxPlainTitle/a.jpg", 60, 60, 1, 2, 3); copy(__DIR__ . '/../index.php', "$fxPlainTitle/index.php");
$portPlainTitle = find_free_port(); start_server($fxPlainTitle, $portPlainTitle);
$plainTitle = http_get("http://127.0.0.1:$portPlainTitle/")[1];
check('[login] with no ADMIN_PASSWORD (the default) the title is plain text, nothing links to the admin', !str_contains($plainTitle, 'title-admin"') && !str_contains($plainTitle, 'href="?admin'));

// A notes file whose only content is "# Title" gives the page its title and shows no empty box.
check('[login] a notes file holding only a "# Title" becomes the title, with no empty "trip.md" box', str_contains($homeLogin, 'Summer in Lisbon') && !str_contains($homeLogin, 'class="note-box"') && !str_contains($homeLogin, 'trip.md'));
file_put_contents("$fxLogin/trip.md", "{gallery}\n# Summer in Lisbon\n\nBring sunscreen.\n");
check('[login] ...but the box comes back as soon as the file has any text', str_contains(http_get("$baseLogin/")[1], 'class="note-box"') && str_contains(http_get("$baseLogin/")[1], 'Bring sunscreen.'));

echo "== recipe: trip album (folders, admin forms, GPS, map, video, share links) ==\n";

$fxTrip = new_fixture_dir('recipe-trip');
mkdir("$fxTrip/Paris"); mkdir("$fxTrip/Lisbon");
make_gps_jpeg("$fxTrip/Paris/eiffel.jpg", 'N', [[48, 1], [51, 1], [3024, 100]], 'E', [[2, 1], [17, 1], [402, 10]]);
make_jpeg("$fxTrip/Paris/louvre.jpg", 60, 60, 200, 100, 50);
make_minimal_mp4("$fxTrip/Paris/seine.mp4", 5);
make_gps_jpeg("$fxTrip/Lisbon/tram.jpg", 'N', [[38, 1], [42, 1], [0, 1]], 'W', [[9, 1], [8, 1], [0, 1]]);
make_jpeg("$fxTrip/Lisbon/castle.jpg", 60, 60, 50, 100, 200);
make_jpeg("$fxTrip/hello.jpg", 60, 60, 90, 90, 90);
write_patched_index("$fxTrip/index.php", $indexContents, [
    'AUTO_GROUP_BY_FOLDER' => 'true', 'ENABLE_PHOTO_LOCATION' => 'true', 'ENABLE_MAP' => 'true',
    'ENABLE_VIDEO' => 'true', 'ENABLE_THUMBNAIL_CACHE' => 'true', 'ADMIN_PASSWORD' => "'trip-admin-pw'",
]);
$portTrip = find_free_port(); start_server($fxTrip, $portTrip); $baseTrip = "http://127.0.0.1:$portTrip";
$tripAuth = 'Authorization: Basic ' . base64_encode('admin:trip-admin-pw');

// 1. A visitor's first look: one cover card per folder, natural order, and no map yet
// (nothing has a visible location until a folder opts in).
[, $tripHome] = http_get("$baseTrip/");
check('[trip] the root shows a cover card per folder, Lisbon before Paris (natural order)', strpos($tripHome, 'id="group-lisbon"') !== false && strpos($tripHome, 'id="group-lisbon"') < strpos($tripHome, 'id="group-paris"'));
check('[trip] ...and the loose photo as its own card', str_contains($tripHome, 'alt="hello.jpg"'));
check('[trip] no map yet: ENABLE_MAP is on, but no photo has a *visible* location', !str_contains($tripHome, 'trip-map-preview'));

// 2. Sign in and open a folder.
[, $pAdmin, $pAdminHd] = http_get("$baseTrip/?admin&folder=Paris", [$tripAuth]);
$tripCookie = extract_cookie_header($pAdminHd); $tripHdrs = [$tripAuth, "Cookie: $tripCookie"];
$parisUrl = "$baseTrip/?admin&folder=Paris";
check('[trip] the folder admin page shows the settings form, the location switch, and its photos', str_contains($pAdmin, 'Save folder settings') && str_contains($pAdmin, 'Show photo locations publicly for this folder') && str_contains($pAdmin, 'eiffel.jpg') && str_contains($pAdmin, 'seine.mp4'));

// 3. Fill in the folder's own settings: name, dates, description, position.
[, $r] = user_submit($parisUrl, $pAdmin, 'value="save_folder_identity"', ['display_name' => 'Paris, France', 'dates' => 'May 3–7, 2026', 'description' => 'Five days of croissants.', 'sort_order' => '1'], $tripHdrs);
check('[trip] saving the folder settings form succeeds', str_contains($r, 'class="flash ok"'));
[, $tripHome2] = http_get("$baseTrip/");
check('[trip] the cover card shows the display name and dates, but not the description (that is on the folder\'s own page)', str_contains($tripHome2, 'folder-cover-label">Paris, France<') && str_contains($tripHome2, 'folder-cover-dates">May 3–7, 2026<') && !str_contains($tripHome2, 'croissants'));
check('[trip] ...and Position 1 moved Paris ahead of Lisbon', strpos($tripHome2, 'id="group-paris"') < strpos($tripHome2, 'id="group-lisbon"'));

// 4. Opt the folder into location sharing, then caption a photo. The admin must
// pre-fill the photo's coordinates from its EXIF without being asked.
[, $pAdmin] = http_get($parisUrl, $tripHdrs);
[, $r] = user_submit($parisUrl, $pAdmin, 'value="toggle_location_enabled"', ['enabled' => '1'], $tripHdrs);
check('[trip] ticking "Show photo locations publicly for this folder" saves', str_contains($r, 'class="flash ok"'));
[, $pAdmin] = http_get($parisUrl, $tripHdrs);
$eiffelForm = '';
foreach (html_post_forms($pAdmin) as $form) { if (str_contains($form['html'], 'name="file" value="eiffel.jpg"') && str_contains($form['html'], 'save_item')) $eiffelForm = $form['html']; }
$eiffelFields = form_default_fields($eiffelForm);
check('[trip] the admin pre-fills the photo\'s location from its EXIF GPS (48.8584, 2.2945) before anything is typed', abs((float) ($eiffelFields['lat'] ?? 0) - 48.8584) < 0.0001 && abs((float) ($eiffelFields['lng'] ?? 0) - 2.2945) < 0.0001);
check('[trip] turning the folder\'s switch on says how many photos now show a location, and how to keep one private', str_contains($r, '1 photo has GPS in the file and now shows a location') && str_contains($r, 'Always hide'));
check('[trip] ...the GPS was stored for that photo right then (nothing to Save first), so there is no "not saved yet" nagging', str_contains($pAdmin, 'name="lat" value="48.8584') && !str_contains($pAdmin, 'not saved yet') && !str_contains($pAdmin, 'loc-unsaved-summary'));
check('[trip] ...and visitors see the map and the location badge straight away', str_contains(http_get("$baseTrip/")[1], 'trip-map-preview') && str_contains(http_get("$baseTrip/?folder=Paris")[1], 'location-badge'));
[, $r] = user_submit($parisUrl, $pAdmin, 'name="file" value="eiffel.jpg"', ['caption' => 'The iron lady', 'web' => 'https://en.wikipedia.org/wiki/Eiffel_Tower', 'loc_label' => 'Champ de Mars'], $tripHdrs);
check('[trip] saving the photo (caption + link, untouched pre-filled GPS) succeeds', str_contains($r, 'class="flash ok"'));
[, $pAdminSaved] = http_get($parisUrl, $tripHdrs);
$eiffelSavedForm = '';
foreach (html_post_forms($pAdminSaved) as $form) { if (str_contains($form['html'], 'name="file" value="eiffel.jpg"') && str_contains($form['html'], 'save_item')) $eiffelSavedForm = $form['html']; }
check('[trip] once saved, the "not saved yet" warning is gone from that photo', $eiffelSavedForm !== '' && !str_contains($eiffelSavedForm, 'not saved yet'));
[, $parisPub] = http_get("$baseTrip/?folder=Paris");
check('[trip] visitors see the caption badge, the location badge (OpenStreetMap link) and the web-link badge', str_contains($parisPub, 'caption-badge" title="The iron lady"') && (bool) preg_match('#class="item-badge location-badge" href="https://www\.openstreetmap\.org/[^"]*48\.8584#', $parisPub) && str_contains($parisPub, 'href="https://en.wikipedia.org/wiki/Eiffel_Tower"'));
check('[trip] the lightbox caption data carries the caption, never the filename', str_contains($parisPub, '"The iron lady"') && !preg_match('/captions = \[[^\]]*eiffel/', $parisPub));

// 5. The map appears, with only what has opted in; a second folder opts in separately.
[, $tripHome3] = http_get("$baseTrip/");
preg_match('/var clusters = (\[.*?\]);/s', $tripHome3, $cm); $clusters3 = json_decode($cm[1] ?? '[]', true);
check('[trip] the root now has a trip-map preview with exactly one pin (Paris\'s eiffel.jpg)', str_contains($tripHome3, 'id="trip-map-preview"') && count($clusters3) === 1 && ($clusters3[0][0]['file'] ?? '') === 'Paris/eiffel.jpg');
check('[trip] ...whose legend uses the folder\'s display name', str_contains($tripHome3, 'map-legend-item"><span class="map-legend-swatch" style="background:#e6194b;"></span>Paris, France'));
[$tripMapStatus, $tripMapPage] = http_get("$baseTrip/?map");
check('[trip] the shareable ?map page loads and has its own link preview', $tripMapStatus === 200 && str_contains($tripMapPage, 'property="og:image"') && str_contains($tripMapPage, '?mapicon'));
[, $lAdmin] = http_get("$baseTrip/?admin&folder=Lisbon", $tripHdrs);
check('[trip] Lisbon\'s tram photo has GPS pre-filled too, but Lisbon has not opted in so it is not on the map', abs((float) (form_default_fields((function ($h) { foreach (html_post_forms($h) as $f) if (str_contains($f['html'], 'name="file" value="tram.jpg"') && str_contains($f['html'], 'save_item')) return $f['html']; return ''; })($lAdmin))['lat'] ?? 0) - 38.7) < 0.01 && count($clusters3) === 1);
check('[trip] ...and Lisbon\'s admin warns that its GPS stays private until the switch is turned on, naming what turning it on does', str_contains($lAdmin, 'Nothing is public while this folder') && str_contains($lAdmin, 'Private for now') && str_contains($lAdmin, 'Always hide'));
check('[trip] ...the location switch is an instant toggle with the warning beside it in orange (naming how many photos it will publish), not a dialog', str_contains($lAdmin, 'class="instant-form"') && (bool) preg_match('#<div class="warn-note">Turning this on makes the GPS location of 1 photo here visible to visitors#', $lAdmin) && !str_contains($lAdmin, 'confirm(&quot;Turn on public locations'));
[, $r] = user_submit("$baseTrip/?admin&folder=Lisbon", $lAdmin, 'value="toggle_location_enabled"', ['enabled' => '1'], $tripHdrs);
[, $lAdmin] = http_get("$baseTrip/?admin&folder=Lisbon", $tripHdrs);
[, $tripHome4] = http_get("$baseTrip/");
preg_match('/var clusters = (\[.*?\]);/s', $tripHome4, $cm4); $clusters4 = json_decode($cm4[1] ?? '[]', true);
check('[trip] once Lisbon opts in, its tram photo is on the map with no further Save, so the map has two pins', count($clusters4) === 2);
user_submit("$baseTrip/?admin&folder=Lisbon", $lAdmin, 'name="file" value="tram.jpg"', ['caption' => 'Tram 28'], $tripHdrs);

// 6. Pick a cover photo with the page's own "Set as cover" button.
[, $pAdmin] = http_get($parisUrl, $tripHdrs);
foreach (html_post_forms($pAdmin) as $form) { if (str_contains($form['html'], 'value="set_cover"') && str_contains($form['html'], 'value="louvre.jpg"')) { [, $r] = http_post($parisUrl, form_default_fields($form['html']), $tripHdrs); } }
[, $tripHome5] = http_get("$baseTrip/");
check('[trip] after "Set as cover" on louvre.jpg, Paris\'s cover card shows the Louvre photo', (bool) preg_match('#id="group-paris">.*?src="\?img=Paris%2Flouvre\.jpg&amp;size=thumb&amp;v=[0-9a-f]{10}"#s', $tripHome5));

// 7. Video: duration badge, in the gallery and the lightbox list.
check('[trip] the 5-second video shows a duration badge and is in the lightbox list', str_contains($parisPub, 'video-duration">0:05<') && str_contains($parisPub, 'Paris\/seine.mp4'));

// 8. Pasting the folder\'s link somewhere: title, description and the *chosen cover* as the preview image.
check('[trip] a pasted ?folder=Paris link previews with the folder\'s name and description', str_contains($parisPub, 'property="og:title" content="Paris, France — ') && str_contains($parisPub, 'property="og:description" content="Five days of croissants."'));
check('[trip] ...and the preview image is the cover photo chosen in the admin (louvre.jpg), not just the first file', (bool) preg_match('#property="og:image" content="[^"]*img=Paris%2Flouvre\.jpg#', http_get("$baseTrip/?folder=Paris")[1]));

// 9. The whole tree exports as one backup file carrying everything typed.
$exportPage = http_get($parisUrl, $tripHdrs)[1];
[, $treeJson] = user_submit($parisUrl, $exportPage, 'value="export_tree"', [], $tripHdrs);
$tree = json_decode($treeJson, true);
check('[trip] "Export the whole tree as JSON" contains the typed settings and captions for both folders', ($tree['Paris']['displayName'] ?? '') === 'Paris, France' && ($tree['Paris']['dates'] ?? '') === 'May 3–7, 2026' && ($tree['Paris']['items']['eiffel.jpg']['caption'] ?? '') === 'The iron lady' && ($tree['Lisbon']['items']['tram.jpg']['caption'] ?? '') === 'Tram 28');

/* ======================================================================= *
 * RECIPE: a "for sale" listing — using the notes file the repo ships
 * (examples/example-forsale.md), untouched, exactly as its own text tells a
 * new user to: turn on AUTO_GROUP_BY_FILENAME and name the photos to match.
 * ======================================================================= */

echo "== recipe: for-sale listing (the shipped example file, as written) ==\n";

$fxSale = new_fixture_dir('recipe-forsale');
foreach (['alpha_01' => 10, 'alpha_02' => 20, 'bravo_01' => 30, 'charlie_01' => 40, 'charlie_02' => 50, 'unrelated' => 60] as $n => $c) make_jpeg("$fxSale/$n.jpg", 60, 60, $c, $c, $c);
copy(__DIR__ . '/../examples/example-forsale.md', "$fxSale/forsale.md");
write_patched_index("$fxSale/index.php", $indexContents, [
    'AUTO_GROUP_BY_FILENAME' => 'true', 'TRACK_VIEWS' => 'true', 'SHOW_VIEW_COUNTS_PUBLICLY' => 'false', 'ENABLE_THUMBNAIL_CACHE' => 'true',
    'ADMIN_PASSWORD' => "'sale-admin'",
]);
$portSale = find_free_port(); start_server($fxSale, $portSale); $baseSale = "http://127.0.0.1:$portSale";
[, $sale] = http_get("$baseSale/");
check('[sale] the example\'s "# heading" becomes the page title', str_contains($sale, '<title>Example listing — replace this with your own — Gallery</title>'));
check('[sale] the shipped example raises no setup notices on a fresh install', !str_contains($sale, 'setup notice'));
check('[sale] "alpha" (2 photos) and "charlie" (2 photos) become boxes; "bravo" (1 photo) stays a single card', str_contains($sale, 'id="group-alpha"') && str_contains($sale, 'id="group-charlie"') && !str_contains($sale, 'class="group-box" id="group-bravo"') && str_contains($sale, 'alt="bravo_01.jpg"'));
check('[sale] each paragraph gets its photos attached because its text contains the filename prefix (View 2 photos / 1 photo / 2 photos)', substr_count($sale, 'linked-photos-jump" href="#group-alpha">View 2 photos') === 1 && substr_count($sale, 'linked-photos-jump" href="#group-bravo">View 1 photo') === 1 && substr_count($sale, 'linked-photos-jump" href="#group-charlie">View 2 photos') === 1);
check('[sale] {sold} on item (3) greys its photos and ribbons its box; {reserved} on (2) tags it; {color:} on (1) colors its box', (bool) preg_match('#class="group-box sold"[^>]*id="group-charlie"#', $sale) && str_contains($sale, 'reserved') && str_contains($sale, 'border-left-color: #5865f2'));
check('[sale] a photo that no paragraph mentions still appears, as a plain card', str_contains($sale, 'alt="unrelated.jpg"'));
check('[sale] directives written inside `inline code` in the example\'s explanation are shown literally, never acted on', str_contains($sale, '<code>{photos: a.jpg, b.jpg, ...}</code>') && str_contains($sale, '<code>{group: Name}</code>'));
// View stats: tracked for the owner, hidden from buyers.
$trackSale = json_decode(http_get("$baseSale/?track=alpha_01.jpg")[1], true);
check('[sale] TRACK_VIEWS on: a view is counted (the ?track ping answers with the new total)', ($trackSale['photoViews'] ?? null) === 1);
[, $saleAfter] = http_get("$baseSale/");
check('[sale] SHOW_VIEW_COUNTS_PUBLICLY off: the count is kept but every public badge stays hidden', (bool) preg_match('#class="view-badge hidden" data-photo="alpha_01.jpg"#', $saleAfter) && !preg_match('#class="view-badge" data-photo#', $saleAfter));
check('[sale] ...and the stats file is not readable by URL', (function () use ($baseSale, $fxSale) { foreach (['.gallery-stats.php', '.gallery-stats.sqlite', '.gallery-stats.json'] as $f) { [$st, $b] = http_get("$baseSale/$f"); if (is_file("$fxSale/$f") && $f === '.gallery-stats.php' && !($st === 404 && $b === '')) return false; } return true; })());
// Pasting a photo link into Discord/Reddit.
[, $salePhoto] = http_get("$baseSale/?photo=alpha_01.jpg");
check('[sale] a pasted ?photo= link previews with an absolute share-size image and the group in its title', (bool) preg_match('#property="og:image" content="http://127\.0\.0\.1:\d+/\?img=alpha_01\.jpg&amp;size=share&amp;v=[0-9a-f]{10}"#', $salePhoto) && str_contains($salePhoto, 'property="og:title" content="alpha — Example listing'));
http_get("$baseSale/?img=alpha_01.jpg&size=thumb");
check('[sale] ENABLE_THUMBNAIL_CACHE on: thumbnails are cached in .gallery-cache next to the photos', is_dir("$fxSale/.gallery-cache") && count(glob("$fxSale/.gallery-cache/*.jpg")) > 0);
preg_match('#<footer>(.*?)</footer>#s', $saleAfter, $saleFooter);
check('[sale] ...and the sitewide "N views" total is hidden from the public footer too', isset($saleFooter[1]) && !preg_match('/\d+ views/', $saleFooter[1]) && str_contains($saleFooter[1], 'href="?about"'));
// ...but the seller can see every count in the admin.
[, $saleAdmin] = http_get("$baseSale/?admin", ['Authorization: Basic ' . base64_encode('admin:sale-admin')]);
check('[sale] the seller sees the counts in the admin: the page-load total, and per-photo views (alpha_01.jpg: 1 view)', str_contains($saleAdmin, 'visit in total') || str_contains($saleAdmin, 'visits in total') && str_contains($saleAdmin, 'cannot see any counts') && (bool) preg_match('#alpha_01\.jpg</div>\s*<div class="item-sub">\s*&\#128065; 1 view\b#', $saleAdmin));
check('[sale] ...and an untouched photo reads 0 views', (bool) preg_match('#unrelated\.jpg</div>\s*<div class="item-sub">\s*&\#128065; 0 views\b#', $saleAdmin));

/* ======================================================================= *
 * RECIPE: private family photos — hide an item and a whole folder through
 * the admin's own forms, share a viewer password, and see what each kind
 * of visitor gets.
 * ======================================================================= */

echo "== recipe: private family photos (hide via the admin forms, Private view) ==\n";

$fxFam = new_fixture_dir('recipe-family');
mkdir("$fxFam/Birthday"); mkdir("$fxFam/Vacation");
make_jpeg("$fxFam/park.jpg", 60, 60, 10, 100, 10); make_jpeg("$fxFam/kids.jpg", 60, 60, 100, 10, 10);
make_jpeg("$fxFam/Birthday/cake.jpg", 60, 60, 150, 50, 50); make_jpeg("$fxFam/Birthday/candles.jpg", 60, 60, 200, 50, 50);
make_jpeg("$fxFam/Vacation/beach.jpg", 60, 60, 50, 50, 200);
write_patched_index("$fxFam/index.php", $indexContents, ['AUTO_GROUP_BY_FOLDER' => 'true', 'ADMIN_PASSWORD' => "'fam-admin'", 'PRIVATE_VIEW_PASSWORD' => "'fam-view'"]);
$portFam = find_free_port(); start_server($fxFam, $portFam); $baseFam = "http://127.0.0.1:$portFam";
$famAuth = 'Authorization: Basic ' . base64_encode('admin:fam-admin');
[, $famHome] = http_get("$baseFam/");
check('[family] everyone sees everything at first, plus a discreet "Private view" footer link', str_contains($famHome, 'alt="kids.jpg"') && str_contains($famHome, 'id="group-birthday"') && str_contains($famHome, 'href="?reveal">Private view</a>') && str_contains($famHome, '5 photos'));
[, $fAdmin, $fHd] = http_get("$baseFam/?admin", [$famAuth]); $famCookie = extract_cookie_header($fHd); $famHdrs = [$famAuth, "Cookie: $famCookie"];
[, $r] = user_submit("$baseFam/?admin", $fAdmin, 'name="file" value="kids.jpg"', ['restriction' => 'private'], $famHdrs);
check('[family] choosing Visibility → "Always hide" on kids.jpg and saving works', str_contains($r, 'class="flash ok"'));
[, $famHome2] = http_get("$baseFam/");
check('[family] kids.jpg is gone from the public page, the lightbox list and the photo count (4 now)', !str_contains($famHome2, 'kids.jpg') && str_contains($famHome2, '4 photos'));
[, $bAdmin] = http_get("$baseFam/?admin&folder=Birthday", $famHdrs);
[, $r] = user_submit("$baseFam/?admin&folder=Birthday", $bAdmin, 'value="toggle_folder_restriction"', [], $famHdrs);
check('[family] the "Hide this entire folder from public view" button works', str_contains($r, 'class="flash ok"'));
[, $famHome3] = http_get("$baseFam/");
check('[family] the Birthday folder disappears entirely (no card, count now 2), and typing its address gives a 404', !str_contains($famHome3, 'birthday') && !str_contains($famHome3, 'Birthday') && str_contains($famHome3, '2 photos') && http_get("$baseFam/?folder=Birthday")[0] === 404);
check('[family] its photos are refused by URL too (?img= 404)', http_get("$baseFam/?img=Birthday%2Fcake.jpg&size=thumb")[0] === 404);
[, $hiddenSum] = http_get("$baseFam/?admin&hidden", $famHdrs);
check('[family] the admin\'s "Hidden items" summary lists the folder and the photo, with the not-a-lock warning', str_contains($hiddenSum, 'Birthday') && str_contains($hiddenSum, 'kids.jpg') && str_contains((string) http_get("$baseFam/?admin", $famHdrs)[1], 'servable directly'));
[, $famAdminPage] = http_get("$baseFam/?admin", $famHdrs);
check('[family] the Hidden tab links each hidden folder to its settings, and each hidden photo to its own card', str_contains($famAdminPage, 'href="?admin&amp;folder=Birthday#settings"') && str_contains($famAdminPage, 'href="?admin#photo-' . md5('kids.jpg') . '"'));
check('[family] ...and the card that link points at really exists on that page, so the link lands on it', str_contains($famAdminPage, 'id="photo-' . md5('kids.jpg') . '"'));
check('[family] the old ?admin&hidden address still works: it goes to the Hidden tab', (function () use ($baseFam, $famHdrs) { [$st, , $hd] = http_get("$baseFam/?admin&hidden=1", $famHdrs, false); $loc = ''; foreach ($hd as $h) if (stripos($h, 'Location:') === 0) $loc = trim(substr($h, 9)); return $st === 302 && $loc === '?admin#hidden'; })());
// A trusted viewer.
[$noPwStatus] = http_get("$baseFam/?reveal");
[$badPwStatus] = http_get("$baseFam/?reveal", ['Authorization: Basic ' . base64_encode('x:wrong')]);
[$goodPwStatus, , $goodPwHd] = http_get("$baseFam/?reveal", ['Authorization: Basic ' . base64_encode('x:fam-view')], false);
$famViewer = extract_cookie_header($goodPwHd);
$loc = ''; foreach ($goodPwHd as $h) if (stripos($h, 'Location:') === 0) $loc = trim(substr($h, 9));
check('[family] "Private view": no password shows the form, a wrong Basic password gets 401, the right one redirects back to the plain gallery link', $noPwStatus === 200 && $badPwStatus === 401 && $goodPwStatus === 302 && ($loc === './' || $loc === '/'));
[, $famViewed] = http_get("$baseFam/", ["Cookie: $famViewer"]);
check('[family] with the viewer cookie, the whole gallery is back (5 photos, Birthday card, kids.jpg)', str_contains($famViewed, 'alt="kids.jpg"') && str_contains($famViewed, 'id="group-birthday"') && str_contains($famViewed, '5 photos') && http_get("$baseFam/?folder=Birthday", ["Cookie: $famViewer"])[0] === 200);
// Two galleries on one domain, different viewer passwords, ONE browser: browsers
// key cookies by host (ignoring the port here), so the cookie names must differ
// or unlocking one gallery would overwrite the other's. $revealCookie is from the
// "private" fixture, $famViewer from this one.
preg_match('/^([^=]+)=/', (string) $revealCookie, $nameA); preg_match('/^([^=]+)=/', (string) $famViewer, $nameB);
$bothCookies = "$revealCookie; $famViewer";
check('[two galleries] each gallery\'s viewer cookie has its own name (so one browser can hold both)', !empty($nameA[1]) && !empty($nameB[1]) && $nameA[1] !== $nameB[1]);
check('[two galleries] a browser holding BOTH cookies is unlocked on gallery A...', str_contains(http_get("$basePrivate/", ["Cookie: $bothCookies"])[1], 'loose1.jpg'));
check('[two galleries] ...and on gallery B, each cookie doing its own job', str_contains(http_get("$baseFam/", ["Cookie: $bothCookies"])[1], 'alt="kids.jpg"'));
check('[two galleries] gallery B\'s cookie does not unlock gallery A (and vice versa)', !str_contains(http_get("$basePrivate/", ["Cookie: $famViewer"])[1], 'loose1.jpg') && !str_contains(http_get("$baseFam/", ["Cookie: $revealCookie"])[1], 'alt="kids.jpg"'));
// --- Security headers and caching (the privacy of hidden items must survive a CDN) ---
$hv = function ($headers, $name) { foreach ($headers as $h) if (stripos($h, "$name:") === 0) return trim(substr($h, strlen($name) + 1)); return null; };
[, , $hiddenImgHd] = http_get("$baseFam/?img=kids.jpg&size=full", ["Cookie: $famViewer"]);
[, , $hiddenThumbHd] = http_get("$baseFam/?img=kids.jpg&size=thumb", ["Cookie: $famViewer"]);
[, , $publicImgHd] = http_get("$baseFam/?img=park.jpg&size=full");
check('[headers] a HIDDEN photo served to a viewer — full size and thumbnail alike — is "private, no-store": a CDN or shared proxy must never keep it', $hv($hiddenImgHd, 'Cache-Control') === 'private, no-store' && $hv($hiddenThumbHd, 'Cache-Control') === 'private, no-store');
check('[headers] an ordinary photo is still cacheable ("public, max-age=31536000, immutable")', $hv($publicImgHd, 'Cache-Control') === 'public, max-age=31536000, immutable');
[, , $anonPageHd] = http_get("$baseFam/"); [, , $viewerPageHd] = http_get("$baseFam/", ["Cookie: $famViewer"]);
check('[headers] the page for a revealed viewer is "private, no-store" and says it varies by cookie', $hv($viewerPageHd, 'Cache-Control') === 'private, no-store' && stripos((string) $hv($viewerPageHd, 'Vary'), 'Cookie') !== false);
check('[headers] the anonymous page also declares Vary: Cookie (and is not marked no-store)', stripos((string) $hv($anonPageHd, 'Vary'), 'Cookie') !== false && $hv($anonPageHd, 'Cache-Control') !== 'private, no-store');
[, , $trackHd] = http_get("$baseFam/?track=park.jpg", ["Cookie: $famViewer"]);
check('[headers] ...and so does the revealed viewer\'s ?track response', $hv($trackHd, 'Cache-Control') === 'private, no-store');
[, , $adminHd] = http_get("$baseFam/?admin", $famHdrs); [, , $admin401Hd] = http_get("$baseFam/?admin"); [, , $reveal401Hd] = http_get("$baseFam/?reveal");
foreach (['the admin page' => $adminHd, 'the admin 401' => $admin401Hd, 'the ?reveal 401' => $reveal401Hd] as $lbl => $hd) {
    check("[headers] $lbl can't be framed, is never cached, and its forms may only post back to the site", str_contains((string) $hv($hd, 'Cache-Control'), 'no-store') && $hv($hd, 'X-Frame-Options') === 'DENY' && str_contains((string) $hv($hd, 'Content-Security-Policy'), "frame-ancestors 'none'") && str_contains((string) $hv($hd, 'Content-Security-Policy'), "form-action 'self'"));
}
foreach (['a page' => $anonPageHd, 'a photo' => $publicImgHd, 'the admin' => $adminHd] as $lbl => $hd) check("[headers] $lbl carries X-Content-Type-Options: nosniff", $hv($hd, 'X-Content-Type-Options') === 'nosniff');
// The admin session has its own name (not PHPSESSID, which a host site may use), is HttpOnly/SameSite=Strict, and rejects ids it never issued.
$adminSetCookie = ''; foreach ($adminHd as $h) if (stripos($h, 'Set-Cookie: gallery_admin_') === 0) $adminSetCookie = $h;
[, , $firstLoginHd] = http_get("$baseFam/?admin", [$famAuth]); $sessLine = ''; foreach ($firstLoginHd as $h) if (stripos($h, 'Set-Cookie: gallery_admin_') === 0) $sessLine = $h;
check('[session] the admin session cookie is named gallery_admin_… (not PHPSESSID), HttpOnly and SameSite=Strict', $sessLine !== '' && stripos($sessLine, 'httponly') !== false && stripos($sessLine, 'samesite=strict') !== false && !preg_match('/PHPSESSID/i', implode("\n", $firstLoginHd)));
preg_match('/Set-Cookie: (gallery_admin_[a-z0-9]+)=/i', $sessLine, $sn);
[, , $forgedHd] = http_get("$baseFam/?admin", [$famAuth, 'Cookie: ' . ($sn[1] ?? 'x') . '=attackerchosenid0123456789abcdef']);
$forgedSet = ''; foreach ($forgedHd as $h) if (stripos($h, 'Set-Cookie: gallery_admin_') === 0) $forgedSet = $h;
check('[session] a session id the attacker chose is not accepted: the server issues a fresh one (no fixation)', $forgedSet !== '' && !str_contains($forgedSet, 'attackerchosenid0123456789abcdef'));
// A hostile Host header must not be echoed into the absolute URLs of link previews.
[, $badHostPage] = http_get("$baseFam/", ['Host: "><svg onload=alert(1)>']);
[, $goodHostPage] = http_get("$baseFam/", ['Host: gallery.example.test:8080']);
check('[headers] a malformed Host header is never echoed into the page (og:url falls back to the server\'s own name)', !str_contains($badHostPage, 'svg onload') && str_contains($badHostPage, 'property="og:url" content="http://127.0.0.1'));
check('[headers] ...while a normal Host (with a port) is used as before', str_contains($goodHostPage, 'property="og:url" content="http://gallery.example.test:8080/'));
// One photo can be shown again inside a hidden folder.
[, $bAdmin] = http_get("$baseFam/?admin&folder=Birthday", $famHdrs);
[, $r] = user_submit("$baseFam/?admin&folder=Birthday", $bAdmin, 'name="file" value="cake.jpg"', ['restriction' => 'public'], $famHdrs);
[, $famHome4] = http_get("$baseFam/");
check('[family] choosing "Always show" on cake.jpg inside the hidden folder shows just that photo (a Birthday card, 1 photo, without the candles)', (bool) preg_match('#id="group-birthday">.*?1 photo#s', $famHome4) && !str_contains($famHome4, 'candles') && http_get("$baseFam/?img=Birthday%2Fcake.jpg&size=thumb")[0] === 200 && http_get("$baseFam/?img=Birthday%2Fcandles.jpg&size=thumb")[0] === 404);

// `inline code` in a notes file is documentation, never a directive.
$fxCode = new_fixture_dir('inlinecode');
make_jpeg("$fxCode/real.jpg", 60, 60, 10, 10, 10); make_jpeg("$fxCode/other.jpg", 60, 60, 20, 20, 20);
file_put_contents("$fxCode/notes.md", "{gallery}\n# Docs & `{sold}` demo\n\nWrite `{photos: ghost.jpg}` or `{sold}` or `{group: Nope}` to use them. The real one: {photos: real.jpg} {group: Real Box}\n\nAlso a `lone { brace` and a `}` here.\n");
write_patched_index("$fxCode/index.php", $indexContents, []);
$portCode = find_free_port(); start_server($fxCode, $portCode);
[, $codeBody] = http_get("http://127.0.0.1:$portCode/");
check('[inline code] directive examples inside `backticks` raise no notices and are not acted on (no "unknown file" warning, no sold ribbon)', !str_contains($codeBody, 'setup notice') && !str_contains($codeBody, 'Unknown file'));
check('[inline code] ...they are shown literally, as code', str_contains($codeBody, '<code>{photos: ghost.jpg}</code>') && str_contains($codeBody, '<code>{sold}</code>') && str_contains($codeBody, '<code>{group: Nope}</code>'));
check('[inline code] ...while a real directive in the same paragraph still works (Real Box holds real.jpg)', str_contains($codeBody, 'Real Box') && str_contains($codeBody, 'alt="real.jpg"') && !str_contains($codeBody, 'class="group-box sold"'));
check('[inline code] ...unbalanced braces inside code do not swallow the text around them', str_contains($codeBody, '<code>lone { brace</code>') && str_contains($codeBody, '<code>}</code>'));
check('[inline code] ...and a heading containing a code span keeps its braces in the page title', str_contains($codeBody, '<title>Docs &amp; `{sold}` demo — Gallery</title>'));

/* ======================================================================= *
 * RECIPE: an archive on another disk (EXTRA_FOLDERS) — the README says it
 * "works exactly like" a real folder, admin features included.
 * ======================================================================= */

echo "== recipe: an archive on another disk (EXTRA_FOLDERS + the admin) ==\n";

$fxArc = new_fixture_dir('recipe-archive'); $arcDisk = new_fixture_dir('recipe-archive-disk');
make_jpeg("$fxArc/home.jpg", 60, 60, 10, 10, 10);
make_jpeg("$arcDisk/old1.jpg", 60, 60, 100, 10, 10); make_jpeg("$arcDisk/old2.jpg", 60, 60, 110, 10, 10);
write_patched_index("$fxArc/index.php", $indexContents, ['AUTO_GROUP_BY_FOLDER' => 'true', 'ADMIN_PASSWORD' => "'arc-admin'", 'PRIVATE_VIEW_PASSWORD' => "'arc-view'", 'EXTRA_FOLDERS' => "['OldTrip2020' => " . var_export($arcDisk, true) . "]"]);
$portArc = find_free_port(); start_server($fxArc, $portArc); $baseArc = "http://127.0.0.1:$portArc";
$arcAuth = 'Authorization: Basic ' . base64_encode('admin:arc-admin');
[, $arcHome] = http_get("$baseArc/");
check('[archive] the folder named in EXTRA_FOLDERS appears as an album next to the real photos', str_contains($arcHome, 'id="group-oldtrip2020"') && str_contains($arcHome, 'alt="home.jpg"') && str_contains($arcHome, '2 photos'));
[, $arcPage] = http_get("$baseArc/?folder=OldTrip2020");
check('[archive] opening it shows its photos, served from the other disk', str_contains($arcPage, 'alt="OldTrip2020/old1.jpg"') && http_get("$baseArc/?img=OldTrip2020%2Fold1.jpg&size=thumb")[0] === 200);
[, $arcAdm, $arcHd] = http_get("$baseArc/?admin&folder=OldTrip2020", [$arcAuth]); $arcHdrs = [$arcAuth, "Cookie: " . extract_cookie_header($arcHd)];
check('[archive] it is a normal folder in the admin\'s folder list', str_contains($arcAdm, 'folder=OldTrip2020') && str_contains($arcAdm, 'old1.jpg'));
[, $r] = user_submit("$baseArc/?admin&folder=OldTrip2020", $arcAdm, 'value="save_folder_identity"', ['display_name' => '2020 Archive', 'description' => 'From the old disk.'], $arcHdrs);
[, $arcAdm] = http_get("$baseArc/?admin&folder=OldTrip2020", $arcHdrs);
user_submit("$baseArc/?admin&folder=OldTrip2020", $arcAdm, 'name="file" value="old1.jpg"', ['caption' => 'Before everything'], $arcHdrs);
[, $arcHome2] = http_get("$baseArc/"); [, $arcPage2] = http_get("$baseArc/?folder=OldTrip2020");
check('[archive] display name, description and a caption can be saved on it; the data is kept with the archive itself, on that disk', str_contains($arcHome2, 'folder-cover-label">2020 Archive<') && str_contains($arcPage2, 'caption-badge" title="Before everything"') && is_file("$arcDisk/.gallery-items.php"));
[, $arcAdm] = http_get("$baseArc/?admin&folder=OldTrip2020", $arcHdrs);
user_submit("$baseArc/?admin&folder=OldTrip2020", $arcAdm, 'value="toggle_folder_restriction"', [], $arcHdrs);
check('[archive] it can be hidden like any folder: gone for the public, back for a viewer with the password', http_get("$baseArc/?folder=OldTrip2020")[0] === 404 && !str_contains(http_get("$baseArc/")[1], 'oldtrip2020'));
[, , $arcViewHd] = http_get("$baseArc/?reveal", ['Authorization: Basic ' . base64_encode('v:arc-view')], false);
check('[archive] ...(viewer password)', http_get("$baseArc/?folder=OldTrip2020", ['Cookie: ' . extract_cookie_header($arcViewHd)])[0] === 200);

// The most common real-world admin failure: PHP can't write to the folder.
// A save must say so, not claim success.
if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    $fxRo2 = new_fixture_dir('recipe-readonly-admin');
    make_jpeg("$fxRo2/a.jpg", 60, 60, 10, 10, 10);
    write_patched_index("$fxRo2/index.php", $indexContents, ['ADMIN_PASSWORD' => "'ro-admin'"]);
    $portRo2 = find_free_port(); start_server($fxRo2, $portRo2); $baseRo2 = "http://127.0.0.1:$portRo2";
    $roAuth = 'Authorization: Basic ' . base64_encode('admin:ro-admin');
    [, $roPage, $roHd] = http_get("$baseRo2/?admin", [$roAuth]); $roHdrs = [$roAuth, 'Cookie: ' . extract_cookie_header($roHd)];
    chmod($fxRo2, 0555);
    [, $roSave] = user_submit("$baseRo2/?admin", $roPage, 'name="file" value="a.jpg"', ['caption' => 'will not stick'], $roHdrs);
    check('[unwritable folder] a save the server cannot write says "Nothing was saved" and why — not "Saved"', str_contains($roSave, 'class="flash error"') && str_contains($roSave, 'Nothing was saved') && str_contains($roSave, 'writable') && !str_contains($roSave, 'class="flash ok"'));
    check('[unwritable folder] ...and nothing was written', !file_exists("$fxRo2/.gallery-items.php"));
    chmod($fxRo2, 0755);
    [, $roPage] = http_get("$baseRo2/?admin", $roHdrs);
    [, $roSaveOk] = user_submit("$baseRo2/?admin", $roPage, 'name="file" value="a.jpg"', ['caption' => 'sticks now'], $roHdrs);
    check('[unwritable folder] ...and once the permission is fixed, the same save works', str_contains($roSaveOk, 'class="flash ok"') && is_file("$fxRo2/.gallery-items.php"));
}

/* ======================================================================= *
 * Replacing a photo under the same name must reach visitors. Images are
 * cached by browsers for a year ("immutable") and their address never
 * changed, so a swapped file kept showing its old picture. The address now
 * carries a tag (&v=…) derived from the file's modification time and size.
 * ======================================================================= */

echo "== cache-busting tag on image and video addresses ==\n";

$fxBust = new_fixture_dir('cachebust');
make_jpeg("$fxBust/swap.jpg", 60, 60, 200, 30, 30);
make_jpeg("$fxBust/steady.jpg", 60, 60, 30, 30, 200);
make_minimal_mp4("$fxBust/clip.mp4", 5);
write_patched_index("$fxBust/index.php", $indexContents, ['ENABLE_VIDEO' => 'true', 'ENABLE_THUMBNAIL_CACHE' => 'true']);
$portBust = find_free_port(); start_server($fxBust, $portBust); $baseBust = "http://127.0.0.1:$portBust";
$vOf = function ($page, $file) { return preg_match('#src="\?img=' . preg_quote(rawurlencode($file), '#') . '&amp;size=thumb&amp;v=([0-9a-f]{10})"#', $page, $m) ? $m[1] : null; };
[, $bust1] = http_get("$baseBust/"); $vSwap1 = $vOf($bust1, 'swap.jpg'); $vSteady1 = $vOf($bust1, 'steady.jpg');
check('[cache-bust] every thumbnail address carries a 10-character tag', $vSwap1 !== null && $vSteady1 !== null && $vSwap1 !== $vSteady1);
check('[cache-bust] ...the same file gets the same tag on every request (so caching still works)', $vOf(http_get("$baseBust/")[1], 'swap.jpg') === $vSwap1);
preg_match('/const files = (\[.*?\]);\s*\/\/.*?const fileVersions = (\[.*?\]);/s', $bust1, $fvm);
$fvFiles = json_decode($fvm[1] ?? '[]', true); $fvVers = json_decode($fvm[2] ?? '[]', true);
check('[cache-bust] the lightbox script is given the same tags (so full-size images and videos are busted too)', count($fvFiles) === count($fvVers) && count($fvFiles) === 3 && $fvVers[array_search('swap.jpg', $fvFiles)] === $vSwap1);
check('[cache-bust] the lightbox builds its full-size and video addresses with the tag', str_contains($bust1, "'&size=full' + (fileVersions[current] ? '&v=' + fileVersions[current] : '')") && str_contains($bust1, "'?video=' + encodeURIComponent(file) + (fileVersions[current]"));
$rawMtime = (string) filemtime("$fxBust/swap.jpg"); $rawSize = (string) filesize("$fxBust/swap.jpg");
check('[cache-bust] the tag is a hash, never the raw modification time or size (a visitor can\'t read when a file changed)', $vSwap1 !== $rawMtime && !str_contains($bust1, "v=$rawMtime") && !str_contains($bust1, "v=$rawSize") && !str_contains($bust1, "v=" . dechex((int) $rawMtime)) && (bool) preg_match('/^[0-9a-f]{10}$/', $vSwap1));
// Now replace the photo, under the same name.
sleep(1); make_jpeg("$fxBust/swap.jpg", 90, 90, 20, 200, 20);
[, $bust2] = http_get("$baseBust/");
check('[cache-bust] replacing swap.jpg under the same name changes its tag', $vOf($bust2, 'swap.jpg') !== null && $vOf($bust2, 'swap.jpg') !== $vSwap1);
check('[cache-bust] ...and only its tag: the untouched photo keeps its (cacheable) address', $vOf($bust2, 'steady.jpg') === $vSteady1);
// Same size, only the timestamp differs (e.g. an edit that keeps the byte count): still a new tag.
$sameSize = file_get_contents("$fxBust/swap.jpg"); $vBefore = $vOf($bust2, 'swap.jpg'); sleep(1); touch("$fxBust/swap.jpg", time() + 5);
check('[cache-bust] ...and touching a file (new timestamp, identical bytes) also changes it', $vOf(http_get("$baseBust/")[1], 'swap.jpg') !== $vBefore);
// The server must still answer the plain, tag-less addresses: bookmarks, pasted links, old caches.
[$plainStatus] = http_get("$baseBust/?img=swap.jpg&size=thumb"); [$taggedStatus] = http_get("$baseBust/?img=swap.jpg&size=thumb&v=whatever");
check('[cache-bust] the server ignores the tag: the old tag-less address and any tag both still work', $plainStatus === 200 && $taggedStatus === 200);
check('[cache-bust] a video\'s tile and the lightbox list carry tags too (clip.mp4)', $vOf($bust2, 'clip.mp4') !== null);
check('[cache-bust] the pasted-link preview image address carries the tag, so a refreshed photo refreshes its preview', (bool) preg_match('#property="og:image" content="[^"]*size=share&amp;v=[0-9a-f]{10}"#', http_get("$baseBust/?photo=swap.jpg")[1]));

/* ======================================================================= *
 * RECIPES: the default install, and iPhone (HEIC) photos.
 * ======================================================================= */

echo "== recipe: the plain default install, and HEIC photos ==\n";

$fxPlain = new_fixture_dir('recipe-plain');
make_jpeg("$fxPlain/one.jpg", 60, 60, 10, 10, 10); make_jpeg("$fxPlain/two.jpg", 60, 60, 20, 20, 20);
mkdir("$fxPlain/Subfolder"); make_jpeg("$fxPlain/Subfolder/three.jpg", 60, 60, 30, 30, 30);
copy(__DIR__ . '/../index.php', "$fxPlain/index.php");
$before = array_diff(scandir($fxPlain), ['.', '..']); sort($before);
$portPlain = find_free_port(); start_server($fxPlain, $portPlain); $basePlain = "http://127.0.0.1:$portPlain";
[, $plain] = http_get("$basePlain/");
foreach (['/', '/?photo=one.jpg', '/?about', '/?img=one.jpg&size=thumb', '/?track=one.jpg', '/?map', '/?folder=Subfolder', '/?admin', '/?reveal'] as $u) http_get("$basePlain$u");
$after = array_diff(scandir($fxPlain), ['.', '..']); sort($after);
check('[plain] a copy of index.php with no settings changed shows the root photos, and not the ones in the subfolder', str_contains($plain, 'alt="one.jpg"') && str_contains($plain, 'alt="two.jpg"') && !str_contains($plain, 'three.jpg') && !str_contains($plain, 'Subfolder'));
$newEntries = array_values(array_diff($after, $before));
$plainCache = is_dir("$fxPlain/.gallery-cache") ? array_diff(scandir("$fxPlain/.gallery-cache"), ['.', '..']) : [];
check('[plain] ...writes only thumbnails (a .gallery-cache folder of images) into the folder, whatever is requested: no stats, data or settings files', ($newEntries === [] || $newEntries === ['.gallery-cache']) && !array_filter($plainCache, fn($f) => !preg_match('/\.(jpe?g|png|webp)$/i', $f)));
check('[plain] ...makes no request to any third party (no external host in any src/href except github)', !preg_match('#(src|href)="https?://(?!github\.com|127\.0\.0\.1)#', $plain));
check('[plain] ...?admin, ?reveal, ?map, ?folder= are all inert (404 / the normal page)', http_get("$basePlain/?admin")[0] === 404 && http_get("$basePlain/?reveal")[0] === 404 && http_get("$basePlain/?folder=Subfolder")[0] === 404 && !str_contains(http_get("$basePlain/?map")[1], 'cdnjs.cloudflare.com'));
check('[plain] ...and says (in the collapsed notices banner) that a photo in a subfolder is being skipped', str_contains($plain, '1 photo/video was found in subfolders but isn&#039;t shown'));

if (class_exists('Imagick') && count(Imagick::queryFormats('HEIC')) > 0) {
    foreach (['off' => false, 'on' => true] as $mode => $cache) {
        $fxHeic = new_fixture_dir("recipe-heic-$mode");
        $src = new Imagick(); $src->newImage(60, 60, new ImagickPixel('rgb(200,120,40)')); $src->setImageFormat('heic'); $src->writeImage("$fxHeic/iphone.heic");
        make_jpeg("$fxHeic/normal.jpg", 60, 60, 10, 10, 10);
        write_patched_index("$fxHeic/index.php", $indexContents, ['ENABLE_THUMBNAIL_CACHE' => $cache ? 'true' : 'false']);
        $portHeic = find_free_port(); start_server($fxHeic, $portHeic); $baseHeic = "http://127.0.0.1:$portHeic";
        [, $heicPage] = http_get("$baseHeic/");
        if (!$cache) {
            check('[heic] ENABLE_THUMBNAIL_CACHE off: the iPhone .heic photo is not in the gallery at all (as the README says)', !str_contains($heicPage, 'iphone.heic') && str_contains($heicPage, 'normal.jpg'));
        } else {
            [$hThumbStatus, $hThumb, $hThumbHd] = http_get("$baseHeic/?img=iphone.heic&size=thumb");
            [$hFullStatus, $hFull] = http_get("$baseHeic/?img=iphone.heic&size=full");
            check('[heic] ENABLE_THUMBNAIL_CACHE on: the .heic photo appears, with a real JPEG thumbnail and a converted full-size image', str_contains($heicPage, 'alt="iphone.heic"') && $hThumbStatus === 200 && str_starts_with($hThumb, "\xFF\xD8") && $hFullStatus === 200 && str_starts_with($hFull, "\xFF\xD8"));
        }
    }
} else {
    echo "  skip  HEIC recipe (this PHP's Imagick can't write HEIC)\n";
}

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
