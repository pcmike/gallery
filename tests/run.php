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
        // A photo's privacy and location switches are forms of their own: a search for "the photo's form" means the one with its caption.
        if (str_contains($form['html'], 'value="set_item_flag"') && !str_contains($needle, 'set_item_flag')) continue;
        $fields = array_merge(form_default_fields($form['html']), $overrides);
        [$st, $body, $hd] = http_post($postUrl, $fields, $headers);
        return [$st, $body, $hd, $fields];
    }
    return [0, "NO FORM CONTAINING: $needle", [], []];
}

/** The generated data files start with a PHP guard line (see
 *  data_file_guard_line() in index.php) so a direct request for one
 *  answers 404 with nothing else. These read/write that exact format. */
/** The markup of one of the page's line-drawing icons (see ui_icon in index.php). */
function ico($name) { return '<svg class="ico" aria-hidden="true"><use href="#i-' . $name . '"></use></svg>'; }

/** What the admin page's switches send when a photo's privacy or location switch is flipped (or several photos are chosen). */
function flag_post($url, $page, array $hdrs, $flag, $on, array $names) {
    $csrf = preg_match('/name="csrf_token" value="([^"]+)"/', $page, $m) ? $m[1] : '';
    $fields = ['csrf_token' => $csrf, 'action' => count($names) === 1 ? 'set_item_flag' : 'bulk_item_flag', 'flag' => $flag, 'on' => $on ? '1' : '0'];
    if (count($names) === 1) $fields['file'] = $names[0]; else $fields['files'] = json_encode($names);
    [, $body] = http_post($url, $fields, array_merge($hdrs, ['X-Requested-With: gallery-fetch']));
    return json_decode($body, true) ?? ['ok' => null, 'text' => $body];
}

/** One photo's record as the editor in the viewer asks for it. $root is the gallery's address (ending in /), $file the photo's path. */
function item_get($root, array $hdrs, $file) {
    $j = json_decode(http_get(rtrim($root, '/') . '/?admin&item=' . rawurlencode($file), $hdrs)[1] ?? '', true);
    return is_array($j) ? $j : [];
}

/** The token the page asks the admin for before it saves anything. */
function admin_token($root, array $hdrs) {
    return json_decode(http_get(rtrim($root, '/') . '/?admin&csrf=1', $hdrs)[1] ?? '', true)['token'] ?? '';
}

/** "Save" for one photo as the editor sends it: what the photo already has (its caption, link and place, a place read from its
 *  GPS too, and the fingerprint) with $overrides on top. $url is the admin address of its folder. Returns like user_submit. */
function item_save($url, array $hdrs, $bare, array $overrides = []) {
    $root = preg_replace('/\?.*$/', '', $url);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    $j = item_get($root, $hdrs, (($q['folder'] ?? '') !== '' ? $q['folder'] . '/' : '') . $bare);
    $auto = $j['auto'] ?? null;
    $fields = ['csrf_token' => admin_token($root, $hdrs), 'action' => 'save_item', 'file' => $bare, 'caption' => $j['caption'] ?? '', 'web' => $j['web'] ?? '',
        'lat' => ($j['lat'] ?? '') !== '' ? $j['lat'] : ($auto['lat'] ?? ''), 'lng' => ($j['lng'] ?? '') !== '' ? $j['lng'] : ($auto['lng'] ?? ''),
        'loc_label' => $j['label'] ?? '', 'loaded_hash' => $j['hash'] ?? ''];
    $fields = array_merge($fields, $overrides);
    [$st, $body, $hd] = http_post($url, $fields, $hdrs);
    return [$st, $body, $hd, $fields];
}

/** Makes one photo its folder's cover, as the star and the editor's Cover button do. */
function item_cover($url, array $hdrs, $bare) {
    $root = preg_replace('/\?.*$/', '', $url);
    return http_post($url, ['csrf_token' => admin_token($root, $hdrs), 'action' => 'set_cover', 'file' => $bare], $hdrs);
}

/** The markup of the lock or unlock drawing (see ui_icon in index.php). */
function ui_lock_probe($name) { return ico($name); }

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
/** The settings file keeps passwords as hashes: does $stored belong to $plain? */
function pw_stored_ok($stored, $plain) { return is_string($stored) && str_starts_with($stored, '$2y$') && password_verify($plain, $stored); }
/** The settings, with a password that is stored as a hash of the given plain one shown as that plain one (so a whole file can be compared). */
function cfg_plain($cfg, $known) { foreach ($known as $name => $plain) if (pw_stored_ok($cfg[$name] ?? null, $plain)) $cfg[$name] = $plain; return $cfg; }
function cfg_read($path) { $raw = (string) file_get_contents($path); $raw = substr($raw, (int) strpos($raw, "\n") + 1); return json_decode($raw, true); }

function write_data_file($path, $data) {
    file_put_contents($path, "<?php http_response_code(404); exit; ?>\n" . json_encode($data, JSON_HEX_TAG));
}

/** A JPEG whose EXIF block holds only a GPS IFD — PHP has no EXIF writer,
 *  so this hand-assembles a minimal little-endian TIFF inside an APP1
 *  segment. $latDMS/$lngDMS are three [numerator, denominator] pairs. */
/** A JPEG whose EXIF says it was taken at $dateText ("2022:01:01 12:00:00"). */
function make_dated_jpeg($path, $dateText) {
    $im = imagecreatetruecolor(60, 60); imagefill($im, 0, 0, imagecolorallocate($im, 90, 90, 200));
    ob_start(); imagejpeg($im); $jpeg = ob_get_clean(); imagedestroy($im);
    $data = $dateText . "\0";
    $exifIfd = 8 + 2 + 12 + 4;
    $dataStart = $exifIfd + 2 + 12 + 4;
    $tiff = 'II' . pack('v', 42) . pack('V', 8)
          . pack('v', 1) . pack('vvVV', 0x8769, 4, 1, $exifIfd) . pack('V', 0)
          . pack('v', 1) . pack('vvVV', 0x9003, 2, strlen($data), $dataStart) . pack('V', 0)
          . $data;
    $exif = "Exif\0\0" . $tiff;
    file_put_contents($path, substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif . substr($jpeg, 2));
}

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
function data_file_guard_line_for_tests() { return "<?php http_response_code(404); exit; ?>\n"; }
function data_file_strip_guard_for_tests($raw) { $raw = (string) $raw; return strncmp($raw, '<?php', 5) === 0 ? substr($raw, strpos($raw, "\n") + 1) : $raw; }
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

/** index.php's link_state(), for the test script (which never loads index.php): the same rules. */
function link_state_for_tests($l, $now = null) {
    $now = $now ?? time();
    if (($l['status'] ?? '') === 'revoked') return 'revoked';
    if (($l['status'] ?? '') === 'paused') return 'paused';
    if ((int) ($l['endsAt'] ?? 0) > 0 && $now >= (int) $l['endsAt']) return 'expired';
    if ((int) ($l['startsAt'] ?? 0) > $now) return 'scheduled';
    if ((int) ($l['maxOpens'] ?? 0) > 0 && (int) ($l['opens'] ?? 0) >= (int) $l['maxOpens']) return 'used_up';
    return 'active';
}

/** The visitors in a link's log file, for the test script. */
function link_log_visitors_for_tests($file) {
    $raw = (string) @file_get_contents($file);
    $d = json_decode(strncmp($raw, '<?php', 5) === 0 ? substr($raw, strpos($raw, "\n") + 1) : $raw, true);
    return is_array($d) ? ($d['visitors'] ?? []) : [];
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
    (bool) preg_match('/<details class="gallery-notices"><summary>' . preg_quote(ico('warn'), '/') . ' \d+ setup notices? for the gallery owner<\/summary>/', $body)
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
    str_contains($body, '<a class="permalink-copy" href="#group-widget-set" title="Copy link">' . ico('link') . '</a>')
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
check('?about covers what the gallery now does: sharp tiles, the viewer built for phones and its zoom controls, putting things first in an order you set, the lock on tiles, prepare thumbnails and the Home Screen app', str_contains($body, 'Sharp thumbnails') && str_contains($body, 'Built for phones') && str_contains($body, 'soft dark pill') && str_contains($body, 'Put first is one list you order') && str_contains($body, 'A lock and a star on every tile') && str_contains($body, 'Prepare thumbnails') && str_contains($body, 'Home Screen'));

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
check('the subfolder note\'s mention+{color:} directive applies to the matching card, anchors aligned', (bool) preg_match('#<div class="card ringed" id="group-eiffel" style="--ring: gold;"#', $subfolderNotesBody) && str_contains($subfolderNotesBody, 'href="#group-eiffel"'));
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

$videoMarks = substr_count($videoPageBody, 'class="video-mark"');
$videoCards = preg_match_all('#<div class="card[^>]*><img src="\?img=[^"]*\.(mp4|mov|webm|m4v)#i', $videoPageBody);
check('[video mark] every video tile carries the big play mark over the whole tile (one per video)', $videoCards >= 2 && $videoMarks === $videoCards && $brokenCardStart !== false && str_contains(substr($videoPageBody, $brokenCardStart, 600), 'video-mark'));

// With "nginx internal redirect" set, nginx sends only files that sit under the gallery's own address; a video brought in from
// elsewhere on the server is still sent by the gallery itself (nginx has no such file to find).
$fxAc = new_fixture_dir('accelroot'); $fxAcExtra = new_fixture_dir('accelextra'); mkdir("$fxAcExtra/Paris");
make_minimal_mp4("$fxAc/loose.mp4", 12, 1);
make_minimal_mp4("$fxAcExtra/Paris/far.mp4", 12, 1);
write_patched_index("$fxAc/index.php", $indexContents, ['ENABLE_VIDEO' => 'true']);
file_put_contents("$fxAc/.gallery-config.php", cfg_text(['NGINX_ACCEL_REDIRECT_PREFIX' => '/trip', 'EXTRA_ROOTS' => [$fxAcExtra], 'AUTO_GROUP_BY_FOLDER' => true, 'ADMIN_PASSWORD' => 'ac-admin-pw']));
$portAc = find_free_port(); start_server($fxAc, $portAc);
[$acV1St, $acV1Body, $acV1Hd] = http_get("http://127.0.0.1:$portAc/?video=loose.mp4");
[$acV2St, $acV2Body, $acV2Hd] = http_get("http://127.0.0.1:$portAc/?video=Paris%2Ffar.mp4");
$acHdr = function ($hd, $name) { foreach ($hd as $h) if (stripos($h, "$name:") === 0) return trim(substr($h, strlen($name) + 1)); return null; };
check('[nginx redirect] a video in the gallery\'s own folder is handed to nginx (an X-Accel-Redirect under the prefix, no body from PHP)', $acV1St === 200 && $acHdr($acV1Hd, 'X-Accel-Redirect') === '/trip/loose.mp4' && $acV1Body === '');
check('[nginx redirect] a video brought in from elsewhere on the server is sent by the gallery itself, whole, with no X-Accel-Redirect (nginx has no file at that address)', $acV2St === 200 && $acHdr($acV2Hd, 'X-Accel-Redirect') === null && strlen($acV2Body) === filesize("$fxAcExtra/Paris/far.mp4"));
[, $acAdmin] = http_get("http://127.0.0.1:$portAc/?admin", ['Authorization: Basic ' . base64_encode('admin:ac-admin-pw')]);
check('[server checks] the browser is only ever sent addresses that really exist under the gallery: a photo from elsewhere on the server, and a folder that is only a mount, are not used as test targets (they would read as "refused" and "tidy links not working" for the wrong reason)', str_contains($acAdmin, 'id="server-probe" hidden data-photo="/loose.mp4"') && str_contains($acAdmin, 'data-folder="Paris" data-folder-url="/Paris/"') && str_contains($acAdmin, 'data-files="2"'));
$fxAo = new_fixture_dir('onlyextra'); $fxAoExtra = new_fixture_dir('onlyextra2'); mkdir("$fxAoExtra/Paris");
make_jpeg("$fxAoExtra/Paris/far.jpg", 60, 60, 90, 90, 90);
file_put_contents("$fxAo/.gallery-config.php", cfg_text(['EXTRA_ROOTS' => [$fxAoExtra], 'AUTO_GROUP_BY_FOLDER' => true, 'ADMIN_PASSWORD' => 'ao-admin-pw']));
copy(__DIR__ . '/../index.php', "$fxAo/index.php");
$portAo = find_free_port(); start_server($fxAo, $portAo);
[, $aoAdmin] = http_get("http://127.0.0.1:$portAo/?admin", ['Authorization: Basic ' . base64_encode('admin:ao-admin-pw')]);
check('[server checks] a gallery whose photos all come from elsewhere on the server has nothing to ask a web server for (data-photo is empty, but the gallery knows it has files and a folder), so the browser can say the lock is not needed instead of "nothing to test"', str_contains($aoAdmin, 'id="server-probe" hidden data-photo=""') && str_contains($aoAdmin, 'data-files="1"') && str_contains($aoAdmin, 'data-folder="Paris"') && str_contains($aoAdmin, 'lock-outside-text'));

// ---- The order of the photos inside a folder: base order, direction, and what goes first.
echo "== the order of the photos inside a folder ==\n";
$fxSo = new_fixture_dir('sortorder');
make_dated_jpeg("$fxSo/a-early.jpg", '2020:01:01 10:00:00');
make_dated_jpeg("$fxSo/b-late.jpg", '2024:06:01 10:00:00');
make_dated_jpeg("$fxSo/c-mid.jpg", '2022:01:01 10:00:00');
make_jpeg("$fxSo/p-private.jpg", 40, 40, 200, 60, 60);
make_jpeg("$fxSo/z-located.jpg", 40, 40, 60, 200, 60);
make_minimal_mp4("$fxSo/m.mp4", 5, 1);
touch("$fxSo/m.mp4", strtotime('2025-01-01')); touch("$fxSo/z-located.jpg", strtotime('2025-02-01')); touch("$fxSo/p-private.jpg", strtotime('2025-03-01'));
write_data_file("$fxSo/.gallery-items.php", ['items' => ['z-located.jpg' => ['location' => ['lat' => 1.5, 'lng' => 2.5, 'label' => 'Somewhere']], 'p-private.jpg' => ['restriction' => 'private']], 'groups' => [], 'displayName' => null, 'cover' => null, 'locationEnabled' => true]);
write_patched_index("$fxSo/index.php", $indexContents, ['ENABLE_VIDEO' => 'true', 'ENABLE_PHOTO_LOCATION' => 'true', 'ADMIN_PASSWORD' => "'so-admin-pw'"]);
$portSo = find_free_port(); start_server($fxSo, $portSo); $baseSo = "http://127.0.0.1:$portSo";
$soOrder = function ($query = '', $headers = []) use ($baseSo) {
    [, $body] = http_get("$baseSo/" . $query, $headers);
    preg_match_all('#<div class="card[^>]*><img src="[^"]*"(?: srcset="[^"]*")?(?: sizes="[^"]*")? loading="lazy" alt="([^"]+)"#', $body, $m);
    return $m[1];
};
$soAdminAuth = 'Authorization: Basic ' . base64_encode('admin:so-admin-pw');
check('[sort] by default the photos run by the date inside each photo, oldest first (a file with none falls back to its modified date), so a video sits among the photos it was shot with', $soOrder() === ['a-early.jpg', 'c-mid.jpg', 'b-late.jpg', 'm.mp4', 'z-located.jpg'], json_encode($soOrder()));
check('[sort] ...and ?sort=name runs them by file name instead', $soOrder('?sort=name') === ['a-early.jpg', 'b-late.jpg', 'c-mid.jpg', 'm.mp4', 'z-located.jpg'], json_encode($soOrder('?sort=name')));
check('[sort] ?sort=taken orders by the date inside each photo (a file with none falls back to its modified date)', $soOrder('?sort=taken') === ['a-early.jpg', 'c-mid.jpg', 'b-late.jpg', 'm.mp4', 'z-located.jpg'], json_encode($soOrder('?sort=taken')));
check('[sort] ...and .desc runs it the other way round', $soOrder('?sort=taken.desc') === ['z-located.jpg', 'm.mp4', 'b-late.jpg', 'c-mid.jpg', 'a-early.jpg'], json_encode($soOrder('?sort=taken.desc')));
check('[sort] ?sort=modified orders by modified date (here the video and the located photo were touched long ago, the others just now)', array_slice($soOrder('?sort=modified'), 0, 2) === ['m.mp4', 'z-located.jpg'] && array_slice($soOrder('?sort=modified.desc'), -2) === ['z-located.jpg', 'm.mp4'], json_encode($soOrder('?sort=modified')));
check('[sort] videos first puts the video in front of the rest, the rest keeping their order', $soOrder('?sort=name.video') === ['m.mp4', 'a-early.jpg', 'b-late.jpg', 'c-mid.jpg', 'z-located.jpg'], json_encode($soOrder('?sort=name.video')));
check('[sort] the "first" choices stack: videos, then located photos, then the rest', $soOrder('?sort=name.located.video') === ['m.mp4', 'z-located.jpg', 'a-early.jpg', 'b-late.jpg', 'c-mid.jpg'], json_encode($soOrder('?sort=name.located.video')));
check('[sort] a nonsense ?sort= is ignored', $soOrder('?sort=banana') === $soOrder() && $soOrder('?sort=name.bogus') === $soOrder());
check('[sort] a visitor asking for private first changes nothing (they never see private items, so nothing can be told from the order)', $soOrder('?sort=name.private') === $soOrder('?sort=name') && !in_array('p-private.jpg', $soOrder('?sort=name.private'), true));
[, , $soAdminHd] = http_get("$baseSo/?admin", [$soAdminAuth]); $soCookie = extract_cookie_header($soAdminHd);
check('[sort] the owner sees the private item, and private first puts it in front', $soOrder('?sort=name.private', ["Cookie: $soCookie"]) === ['p-private.jpg', 'a-early.jpg', 'b-late.jpg', 'c-mid.jpg', 'm.mp4', 'z-located.jpg'], json_encode($soOrder('?sort=name.private', ["Cookie: $soCookie"])));
check('[sort] a visitor\'s own choice kept in the cookie sets the order, and a link\'s ?sort= beats the cookie', $soOrder('', ['Cookie: gallery_sort=taken.desc']) === $soOrder('?sort=taken.desc') && $soOrder('?sort=name', ['Cookie: gallery_sort=taken.desc']) === $soOrder('?sort=name') && $soOrder('?sort=name', ['Cookie: gallery_sort=taken.desc']) !== $soOrder());
// The owner's lock on each tile (private or not, and a quick way to flip it); nobody else gets it.
[, $soOwnerPage] = http_get("$baseSo/", ["Cookie: $soCookie"]);
check('[owner lock] the signed-in owner gets a lock on each tile: red and labelled "Private" on a private photo, an open lock on a public one', preg_match('#<button type="button" class="priv-toggle is-private" data-file="p-private\.jpg"[^>]*><svg[^>]*><use href="\#i-lock"></use></svg><span>Private</span>#', $soOwnerPage) === 1 && preg_match('#<button type="button" class="priv-toggle" data-file="a-early\.jpg"[^>]*><svg[^>]*><use href="\#i-unlock"></use></svg></button>#', $soOwnerPage) === 1 && str_contains($soOwnerPage, 'id="owner-toast"'));
check('[owner lock] a visitor, who can\'t see private photos anyway, gets no locks', !str_contains(http_get("$baseSo/")[1], 'class="priv-toggle') && !str_contains(http_get("$baseSo/")[1], 'id="owner-toast"'));
[, $csrfBody] = http_get("$baseSo/?admin&csrf=1", [$soAdminAuth, "Cookie: $soCookie"]);
$csrfTok = (json_decode($csrfBody, true)['token'] ?? '');
check('[owner lock] the lock gets a token from the admin (only for a signed-in owner)', strlen($csrfTok) >= 32 && !str_contains(http_get("$baseSo/?admin&csrf=1")[1], 'token'));
http_post("$baseSo/?admin", ['csrf_token' => $csrfTok, 'action' => 'set_item_flag', 'file' => 'a-early.jpg', 'flag' => 'private', 'on' => '1'], [$soAdminAuth, "Cookie: $soCookie", 'X-Requested-With: gallery-fetch']);
check('[owner lock] with that token the same save the admin uses makes the photo private (gone for visitors, locked for the owner)', !in_array('a-early.jpg', $soOrder(), true) && str_contains(http_get("$baseSo/", ["Cookie: $soCookie"])[1], 'class="priv-toggle is-private" data-file="a-early.jpg"'));
http_post("$baseSo/?admin", ['csrf_token' => $csrfTok, 'action' => 'set_item_flag', 'file' => 'a-early.jpg', 'flag' => 'private', 'on' => '0'], [$soAdminAuth, "Cookie: $soCookie", 'X-Requested-With: gallery-fetch']);
check('[owner lock] ...and back to public', in_array('a-early.jpg', $soOrder(), true));
// The owner's star on each tile and the two buttons in the viewer: one tap to make a photo its folder's cover.
check('[owner cover] the owner gets a star on every tile, none gold while no cover is chosen', preg_match_all('#<button type="button" class="cover-toggle" data-file="#', $soOwnerPage) === 6 && !str_contains($soOwnerPage, 'cover-toggle is-cover'));
check('[owner cover] the viewer has a cover button and a private button for the owner, left of the zoom button', preg_match('#id="lightbox-cover"[^>]*>.*?id="lightbox-private"[^>]*>.*?id="lightbox-zoom"#s', $soOwnerPage) === 1 && str_contains($soOwnerPage, 'const ownerState = {'));
check('[owner cover] hovering either overlay button lights it (full strength, a ring, a colour of its own), and pressing it sinks it', str_contains($soOwnerPage, '.priv-toggle:hover, .cover-toggle:hover, .edit-toggle:hover {') && str_contains($soOwnerPage, '.priv-toggle:active, .cover-toggle:active, .edit-toggle:active {'));
check('[badges] the caption, location and link badges on a tile light up under the pointer like the owner\'s buttons, and sink when pressed', str_contains($soOwnerPage, '.item-badge:hover {') && str_contains($soOwnerPage, '.item-badge:active {'));
$soVisitorPage = http_get("$baseSo/")[1];
check('[owner cover] a visitor gets no stars, no viewer buttons and no owner state', !str_contains($soVisitorPage, 'cover-toggle"') && !str_contains($soVisitorPage, 'id="lightbox-cover"') && !str_contains($soVisitorPage, 'id="lightbox-private"') && str_contains($soVisitorPage, 'const ownerState = null;'));
$soCoverReply = http_post("$baseSo/?admin", ['csrf_token' => $csrfTok, 'action' => 'set_cover', 'file' => 'c-mid.jpg'], [$soAdminAuth, "Cookie: $soCookie", 'X-Requested-With: gallery-fetch']);
[, $soOwnerPage2] = http_get("$baseSo/", ["Cookie: $soCookie"]);
check('[owner cover] pressing a star saves that photo as the folder\'s cover (the same save as the admin\'s), and only it is gold', str_contains($soCoverReply[1] ?? '', '"ok":true') && str_contains($soOwnerPage2, 'class="cover-toggle is-cover" data-file="c-mid.jpg"') && substr_count($soOwnerPage2, 'cover-toggle is-cover') === 1 && str_contains($soOwnerPage2, '"c-mid.jpg":true') && str_contains($soOwnerPage2, '"b-late.jpg":false'));
// Arrange: the owner places photos next to each other by hand; each placement is a stack that follows its anchor through every sort.
check('[select] the owner gets the Select button, its bar (with the Arrange part here) and a file name on every tile; a visitor gets none of it', str_contains($soOwnerPage2, 'id="select-btn"') && str_contains($soOwnerPage2, 'id="select-bar"') && str_contains($soOwnerPage2, 'data-select="release"') && str_contains($soOwnerPage2, 'class="card owner-card" data-file="a-early.jpg"') && !str_contains($soVisitorPage, 'id="select-btn"') && !str_contains($soVisitorPage, 'id="select-bar"') && !str_contains($soVisitorPage, 'data-file="a-early.jpg"'));
check('[select] the Select button replaces Arrange on the strip: no Arrange button any more, and the bar offers Select all, Clear, Make private, Make public and Done', !str_contains($soOwnerPage2, 'id="arrange-btn"') && !str_contains($soOwnerPage2, 'id="arrange-bar"') && preg_match('#data-select="all".*data-select="clear".*data-select="done".*data-select="private".*data-select="public"#s', $soOwnerPage2) === 1);
// The pencil and the editor in the viewer, for the owner only.
check('[editor] the owner gets a pencil on every tile and in the viewer, and the editor sheet with its fields, three switches and Prev, Done, Next', preg_match_all('#<button type="button" class="edit-toggle" data-file="#', $soOwnerPage2) === 6 && str_contains($soOwnerPage2, 'id="lightbox-edit"') && str_contains($soOwnerPage2, 'id="lb-editor"') && preg_match('#id="lbe-caption".*id="lbe-web".*id="lbe-private".*id="lbe-loc".*id="lbe-cover".*id="lbe-prev".*id="lbe-done".*id="lbe-next"#s', $soOwnerPage2) === 1);
check('[editor] the photo keeps its shape whatever box it is given, and the phone\'s top row keeps its counter and close button with a strip of buttons that swipes between them', str_contains($soOwnerPage2, 'object-fit: contain;') && str_contains($soOwnerPage2, 'id="lightbox-tools"') && str_contains($soOwnerPage2, 'function mapEditStart()') === str_contains($soOwnerPage2, 'function mapEditEnd()') && str_contains($soOwnerPage2, 'id="lbe-grip"'));
check('[editor] a visitor gets no pencil, no editor and no editor code', !str_contains($soVisitorPage, 'edit-toggle"') && !str_contains($soVisitorPage, 'id="lb-editor"') && !str_contains($soVisitorPage, 'id="lightbox-edit"') && str_contains($soVisitorPage, 'let lbEdit = null;') && !str_contains($soVisitorPage, 'lbe-caption'));
$soItem = function ($file) use ($baseSo, $soAdminAuth, $soCookie) { return json_decode(http_get("$baseSo/?admin&item=" . rawurlencode($file), [$soAdminAuth, "Cookie: $soCookie"])[1] ?? '', true) ?: []; };
$soItemA = $soItem('a-early.jpg');
check('[editor] the owner\'s item request returns that photo\'s record: caption, link, place, switches, cover flag and a fingerprint', ($soItemA['ok'] ?? false) === true && ($soItemA['file'] ?? '') === 'a-early.jpg' && array_key_exists('caption', $soItemA) && array_key_exists('web', $soItemA) && array_key_exists('lat', $soItemA) && strlen((string) ($soItemA['hash'] ?? '')) === 32 && ($soItemA['private'] ?? null) === false && ($soItemA['cover'] ?? null) === false && ($soItem('c-mid.jpg')['cover'] ?? null) === true);
check('[editor] ...and nobody else gets it: a visitor is sent to the sign-in, an unknown file is refused', !str_contains(http_get("$baseSo/?admin&item=a-early.jpg")[1], '"hash"') && ($soItem('nope.jpg')['ok'] ?? true) === false && ($soItem('../etc/passwd')['ok'] ?? true) === false);

$soArrange = function ($fields) use ($baseSo, $soAdminAuth, $soCookie, $csrfTok) {
    $r = http_post("$baseSo/?admin", $fields + ['csrf_token' => $csrfTok, 'action' => 'arrange_items'], [$soAdminAuth, "Cookie: $soCookie", 'X-Requested-With: gallery-fetch']);
    return json_decode($r[1] ?? '', true) ?: [];
};
$soStacks = function () use ($fxSo) { return json_decode(data_file_strip_guard_for_tests(file_get_contents("$fxSo/.gallery-items.php")), true)['stacks'] ?? null; };
$soBefore = $soOrder();
$soReply = $soArrange(['mode' => 'place', 'files' => json_encode(['z-located.jpg']), 'target' => 'c-mid.jpg', 'side' => 'before']);
check('[arrange] placing a photo before another saves a stack anchored on the one that stayed put, and the photo sits there for visitors', ($soReply['ok'] ?? false) === true && $soStacks() === [['anchor' => 'c-mid.jpg', 'files' => ['z-located.jpg', 'c-mid.jpg']]] && $soOrder() === ['a-early.jpg', 'z-located.jpg', 'c-mid.jpg', 'b-late.jpg', 'm.mp4'], json_encode([$soReply, $soStacks(), $soOrder()]));
check('[arrange] under every sort the stack stays together, in the order set, at its anchor\'s place', $soOrder('?sort=name') === ['a-early.jpg', 'b-late.jpg', 'z-located.jpg', 'c-mid.jpg', 'm.mp4'] && $soOrder('?sort=taken.desc') === ['m.mp4', 'b-late.jpg', 'z-located.jpg', 'c-mid.jpg', 'a-early.jpg'] && $soOrder('?sort=name.desc') === ['m.mp4', 'z-located.jpg', 'c-mid.jpg', 'b-late.jpg', 'a-early.jpg'], json_encode([$soOrder('?sort=name'), $soOrder('?sort=taken.desc'), $soOrder('?sort=name.desc')]));
check('[arrange] the "first" choices still apply (a stack goes where its anchor does)', $soOrder('?sort=name.video') === ['m.mp4', 'a-early.jpg', 'b-late.jpg', 'z-located.jpg', 'c-mid.jpg'] && $soOrder('?sort=name.located')[0] === 'a-early.jpg', json_encode([$soOrder('?sort=name.video'), $soOrder('?sort=name.located')]));
$soArrange(['mode' => 'place', 'files' => json_encode(['m.mp4']), 'target' => 'z-located.jpg', 'side' => 'after']);
check('[arrange] placing a photo beside a stacked one joins that stack at that spot', $soStacks() === [['anchor' => 'c-mid.jpg', 'files' => ['z-located.jpg', 'm.mp4', 'c-mid.jpg']]] && $soOrder() === ['a-early.jpg', 'z-located.jpg', 'm.mp4', 'c-mid.jpg', 'b-late.jpg'], json_encode([$soStacks(), $soOrder()]));
$soArrange(['mode' => 'place', 'files' => json_encode(['z-located.jpg', 'm.mp4']), 'target' => 'a-early.jpg', 'side' => 'after']);
check('[arrange] several photos move together, keeping the order sent, and a stack left with one photo is dissolved', $soStacks() === [['anchor' => 'a-early.jpg', 'files' => ['a-early.jpg', 'z-located.jpg', 'm.mp4']]] && $soOrder() === ['a-early.jpg', 'z-located.jpg', 'm.mp4', 'c-mid.jpg', 'b-late.jpg'], json_encode([$soStacks(), $soOrder()]));
$soArrange(['mode' => 'place', 'files' => json_encode(['p-private.jpg']), 'target' => 'a-early.jpg', 'side' => 'after']);
check('[arrange] a private photo placed in a stack shows for the owner there, and a visitor sees the rest in the same places', $soOrder() === ['a-early.jpg', 'z-located.jpg', 'm.mp4', 'c-mid.jpg', 'b-late.jpg'] && array_slice($soOrder('', ["Cookie: $soCookie"]), 0, 2) === ['a-early.jpg', 'p-private.jpg'], json_encode([$soOrder(), $soOrder('', ["Cookie: $soCookie"])]));
$soArrange(['mode' => 'release', 'files' => json_encode(['p-private.jpg', 'm.mp4'])]);
check('[arrange] releasing photos takes them out of their stacks, back to where the sort puts them', $soStacks() === [['anchor' => 'a-early.jpg', 'files' => ['a-early.jpg', 'z-located.jpg']]] && $soOrder() === ['a-early.jpg', 'z-located.jpg', 'c-mid.jpg', 'b-late.jpg', 'm.mp4'], json_encode([$soStacks(), $soOrder()]));
$soBad = [
    $soArrange(['mode' => 'place', 'files' => json_encode(['nope.jpg']), 'target' => 'a-early.jpg', 'side' => 'after']),
    $soArrange(['mode' => 'place', 'files' => json_encode(['b-late.jpg']), 'target' => 'nope.jpg', 'side' => 'after']),
    $soArrange(['mode' => 'place', 'files' => json_encode(['b-late.jpg']), 'target' => 'b-late.jpg', 'side' => 'after']),
    $soArrange(['mode' => 'place', 'files' => '', 'target' => 'a-early.jpg']),
    $soArrange(['mode' => 'sideways', 'files' => json_encode(['b-late.jpg'])]),
    $soArrange(['mode' => 'place', 'files' => json_encode(['../index.php']), 'target' => 'a-early.jpg']),
];
check('[arrange] an unknown photo, an unknown target, a photo beside itself, no photos, an unknown mode: each is refused and nothing changes', count(array_filter($soBad, function ($r) { return ($r['ok'] ?? true) === false; })) === 6 && $soStacks() === [['anchor' => 'a-early.jpg', 'files' => ['a-early.jpg', 'z-located.jpg']]], json_encode($soBad));
$soNoToken = http_post("$baseSo/?admin", ['action' => 'arrange_items', 'mode' => 'reset', 'csrf_token' => 'wrong'], [$soAdminAuth, "Cookie: $soCookie", 'X-Requested-With: gallery-fetch']);
$soNoLogin = http_post("$baseSo/?admin", ['action' => 'arrange_items', 'mode' => 'reset', 'csrf_token' => $csrfTok], ['X-Requested-With: gallery-fetch']);
check('[arrange] it needs the owner and the token: a wrong token or a visitor changes nothing', $soStacks() === [['anchor' => 'a-early.jpg', 'files' => ['a-early.jpg', 'z-located.jpg']]] && !str_contains($soNoLogin[1] ?? '', '"ok":true') && !str_contains($soNoToken[1] ?? '', '"ok":true'));
$soCoverKeep = http_post("$baseSo/?admin", ['csrf_token' => $csrfTok, 'action' => 'set_cover', 'file' => 'b-late.jpg'], [$soAdminAuth, "Cookie: $soCookie", 'X-Requested-With: gallery-fetch']);
check('[arrange] other settings saved afterwards leave the stacks alone (and placing leaves the cover, captions and locations alone)', $soStacks() === [['anchor' => 'a-early.jpg', 'files' => ['a-early.jpg', 'z-located.jpg']]] && (json_decode(data_file_strip_guard_for_tests(file_get_contents("$fxSo/.gallery-items.php")), true)['cover'] ?? '') === 'b-late.jpg' && isset(json_decode(data_file_strip_guard_for_tests(file_get_contents("$fxSo/.gallery-items.php")), true)['items']['z-located.jpg']['location']));
$soRaw = json_decode(data_file_strip_guard_for_tests(file_get_contents("$fxSo/.gallery-items.php")), true);
$soRaw['stacks'][] = ['anchor' => 'ghost.jpg', 'files' => ['ghost.jpg', 'c-mid.jpg']];
$soRaw['stacks'][] = ['anchor' => 7, 'files' => 'junk'];
write_data_file("$fxSo/.gallery-items.php", $soRaw);
check('[arrange] a stack naming a photo that is gone (or damaged data) is quietly ignored: the page still comes up in its usual order', $soOrder() === ['a-early.jpg', 'z-located.jpg', 'c-mid.jpg', 'b-late.jpg', 'm.mp4'], json_encode($soOrder()));
$soArrange(['mode' => 'reset']);
check('[arrange] reset puts every photo of the folder back where the sort puts it', $soStacks() === [] && $soOrder() === $soBefore, json_encode([$soStacks(), $soOrder()]));
// the folder's own setting, saved through the admin form
[, $soAdminBody, $soAdminHd2] = http_get("$baseSo/?admin", [$soAdminAuth]);
$soSess = extract_cookie_header($soAdminHd2); $soCsrf = extract_csrf_token($soAdminBody);
check('[sort] the admin form offers the order of the photos here', str_contains($soAdminBody, 'name="photo_sort_order"') && str_contains($soAdminBody, 'name="photo_sort_video"'));
http_post("$baseSo/?admin", ['action' => 'save_folder_identity', 'csrf_token' => $soCsrf, 'display_name' => '', 'photo_sort_order' => 'taken', 'photo_sort_dir' => 'desc', 'photo_sort_video' => 'on', 'photo_sort_located' => '', 'photo_sort_private' => 'off'], [$soAdminAuth, "Cookie: $soSess"]);
$soStored = json_decode(data_file_strip_guard_for_tests(file_get_contents("$fxSo/.gallery-items.php")), true);
check('[sort] saved, the folder keeps only what was chosen (order, direction, videos on, private off; "the site\'s" choices are not stored)', ($soStored['photoSort'] ?? null) == ['order' => 'taken', 'desc' => true, 'video' => true, 'private' => false] && isset($soStored['items']['z-located.jpg']) && true && $soStored['locationEnabled'] === true, json_encode($soStored));
check('[sort] ...and it is the order visitors get in that folder', $soOrder() === ['m.mp4', 'z-located.jpg', 'b-late.jpg', 'c-mid.jpg', 'a-early.jpg'], json_encode($soOrder()));
check('[sort] ...but a visitor\'s own choice still beats the folder\'s', $soOrder('?sort=name') === ['a-early.jpg', 'b-late.jpg', 'c-mid.jpg', 'm.mp4', 'z-located.jpg']);
check('[sort] the dates found are kept in the folder\'s cache (and removed with it), so they are read from the photos only once', is_file("$fxSo/.gallery-cache/taken3.json") && count(json_decode(file_get_contents("$fxSo/.gallery-cache/taken3.json"), true)) >= 5);
[, $soPage] = http_get("$baseSo/");
check('[sort] the page has the sort button, with only the toggles that apply here (videos, located; private only for the owner)', str_contains($soPage, 'id="sort-menu-btn"') && str_contains($soPage, 'data-flag="video"') && str_contains($soPage, 'data-flag="located"') && !str_contains($soPage, 'data-flag="private"'));
[, $soPageOwner] = http_get("$baseSo/", ["Cookie: $soSess"]);
check('[sort] ...the owner also gets the private toggle', str_contains($soPageOwner, 'data-flag="private"'));
// ---- "Put first" as one list: the site's order of the three kinds (SORT_PRIORITY), and a folder's own list on its settings page.
echo "== put first: one list, in an order you set ==\n";
$fxPf = new_fixture_dir('putfirst');
make_jpeg("$fxPf/a.jpg", 40, 40, 10, 20, 30); make_jpeg("$fxPf/b.jpg", 40, 40, 30, 20, 10);
make_jpeg("$fxPf/z-located.jpg", 40, 40, 60, 200, 60);
make_minimal_mp4("$fxPf/m.mp4", 5, 1);
write_data_file("$fxPf/.gallery-items.php", ['items' => ['z-located.jpg' => ['location' => ['lat' => 1.5, 'lng' => 2.5, 'label' => 'Somewhere']]], 'groups' => [], 'displayName' => null, 'cover' => null, 'locationEnabled' => true]);
file_put_contents("$fxPf/.gallery-config.php", cfg_text(['ENABLE_VIDEO' => true, 'ENABLE_PHOTO_LOCATION' => true, 'ADMIN_PASSWORD' => 'pf-admin-pw']));
copy(__DIR__ . '/../index.php', "$fxPf/index.php");
$portPf = find_free_port(); start_server($fxPf, $portPf); $basePf = "http://127.0.0.1:$portPf";
$pfOrder = function ($query = '') use ($basePf) {
    [, $body] = http_get("$basePf/" . $query);
    preg_match_all('#<div class="card[^>]*><img src="[^"]*"(?: srcset="[^"]*")?(?: sizes="[^"]*")? loading="lazy" alt="([^"]+)"#', $body, $m);
    return $m[1];
};
$pfSiteList = function ($html) { preg_match('#<div class="sortfirst" data-name.*?</ol>#s', $html, $m); preg_match_all('#<li data-kind="(\w+)">#', $m[0] ?? '', $k); return $k[1]; };
$pfAuth = 'Authorization: Basic ' . base64_encode('admin:pf-admin-pw');
[, $pfAdmin, $pfHd] = http_get("$basePf/?admin", [$pfAuth]);
$pfHdrs = [$pfAuth, 'Cookie: ' . extract_cookie_header($pfHd)];
$pfSite = fn ($page) => (function ($pg) { foreach (html_post_forms($pg) as $f) if (str_contains($f['html'], 'value="save_site_settings"')) return $f['html']; return ''; })($page);
check('[put first] Site settings has ONE list for the three kinds: a row each, in the stacking order, with a handle and a switch (no separate toggles)', str_contains($pfAdmin, 'class="sortfirst"') && $pfSiteList($pfAdmin) === ['private', 'video', 'located'] && substr_count($pfAdmin, 'class="sf-grip"') >= 3 && !preg_match('#<div class="name">(Videos first|Photos with a location first|Private items first)#', $pfAdmin));
check('[put first] ...with a sentence under it, and the same list is on the folder\'s settings page under the Site\'s / This folder\'s own pills', str_contains($pfAdmin, 'Nothing is put first: photos follow the order above.') && str_contains($pfAdmin, 'class="sortfirst sf-form"') && str_contains($pfAdmin, 'name="photo_sort_first"') && str_contains($pfAdmin, 'name="photo_sort_priority"'));
check('[put first] by default the three stack private, videos, located: with videos and located both on, the video comes first', true);
[, $r] = user_submit("$basePf/?admin", $pfAdmin, 'value="save_site_settings"', ['set_SORT_VIDEOS_FIRST' => '1', 'set_SORT_LOCATED_FIRST' => '1'], $pfHdrs);
check('[put first] the site\'s two switches on, in the default order: videos, then located photos, then the rest', $pfOrder() === ['m.mp4', 'z-located.jpg', 'a.jpg', 'b.jpg'], json_encode($pfOrder()));
[, $pfAdmin] = http_get("$basePf/?admin", $pfHdrs);
[, $r] = user_submit("$basePf/?admin", $pfAdmin, 'value="save_site_settings"', ['set_SORT_PRIORITY' => 'located,video,private'], $pfHdrs);
$pfCfg = cfg_plain(cfg_read("$fxPf/.gallery-config.php"), ['ADMIN_PASSWORD' => 'pf-admin-pw']);
check('[put first] saving the site\'s order (SORT_PRIORITY) keeps it, and the sort follows it: located photos now come before videos', ($pfCfg['SORT_PRIORITY'] ?? null) === 'located,video,private' && $pfOrder() === ['z-located.jpg', 'm.mp4', 'a.jpg', 'b.jpg'], json_encode([$pfCfg['SORT_PRIORITY'] ?? null, $pfOrder()]));
[, $pfAdmin] = http_get("$basePf/?admin", $pfHdrs);
check('[put first] ...and the list is shown in that order, switched-on kinds above the rest', array_slice($pfSiteList($pfAdmin), 0, 2) === ['located', 'video'] && str_contains($pfAdmin, 'Photos with a location, then videos, then the rest.'), json_encode($pfSiteList($pfAdmin)));
[, $r] = user_submit("$basePf/?admin", $pfAdmin, 'value="save_site_settings"', ['set_SORT_PRIORITY' => 'video,video,private'], $pfHdrs);
$pfCfg = cfg_plain(cfg_read("$fxPf/.gallery-config.php"), ['ADMIN_PASSWORD' => 'pf-admin-pw']);
check('[put first] an order that does not list each kind once is refused and nothing changes', str_contains($r, 'must list private, video and located') && ($pfCfg['SORT_PRIORITY'] ?? null) === 'located,video,private');
// A folder's own list (the root folder's settings, here).
[, $pfAdmin2, $pfHd2] = http_get("$basePf/?admin", $pfHdrs);
$pfFolderPost = function ($fields) use ($basePf, $pfHdrs, $pfAdmin2) {
    return http_post("$basePf/?admin", array_merge(['action' => 'save_folder_identity', 'csrf_token' => extract_csrf_token($pfAdmin2), 'display_name' => '', 'photo_sort_order' => ''], $fields), $pfHdrs);
};
$pfStored = function () use ($fxPf) { $d = json_decode(data_file_strip_guard_for_tests(file_get_contents("$fxPf/.gallery-items.php")), true); return $d['photoSort'] ?? null; };
$pfFolderPost(['photo_sort_order' => '', 'photo_sort_first' => 'own', 'photo_sort_priority' => 'video,located,private', 'photo_sort_video' => 'on', 'photo_sort_located' => 'on', 'photo_sort_private' => 'off']);
check('[put first] a folder\'s own list is stored with the folder (each kind on or off, and the order), and it beats the site\'s order in that folder', $pfStored() == ['video' => true, 'located' => true, 'private' => false, 'priority' => ['video', 'located', 'private']] && $pfOrder() === ['m.mp4', 'z-located.jpg', 'a.jpg', 'b.jpg'], json_encode([$pfStored(), $pfOrder()]));
[, $pfAdmin3] = http_get("$basePf/?admin", $pfHdrs);
check('[put first] ...and the folder\'s page opens with the section open, "custom", the pill on This folder\'s own, in the folder\'s order', str_contains($pfAdmin3, 'class="field wide sort-details" open') && str_contains($pfAdmin3, '&middot; custom') && preg_match('#name="photo_sort_first" value="own" checked#', $pfAdmin3) && str_contains($pfAdmin3, 'name="photo_sort_priority" class="sf-order" value="video,located,private"'));
$pfFolderPost(['photo_sort_first' => 'own', 'photo_sort_priority' => 'located,video,private', 'photo_sort_video' => 'on', 'photo_sort_located' => 'on', 'photo_sort_private' => 'off']);
check('[put first] a folder\'s own list that sorts exactly as the site\'s does (same kinds on, same order) is not stored: the folder keeps following the site', $pfStored() === null, json_encode($pfStored()));
$pfFolderPost(['photo_sort_first' => 'own', 'photo_sort_priority' => 'located,video,private', 'photo_sort_video' => 'off', 'photo_sort_located' => 'off', 'photo_sort_private' => 'off']);
check('[put first] with every kind off while the site puts some first, it IS the folder\'s own ("put nothing first here"): stored, and the folder is in plain order', $pfStored() == ['video' => false, 'located' => false, 'private' => false, 'priority' => ['located', 'video', 'private']] && $pfOrder() === ['a.jpg', 'b.jpg', 'm.mp4', 'z-located.jpg'], json_encode([$pfStored(), $pfOrder()]));
[, $pfAdmin4] = http_get("$basePf/?admin", $pfHdrs);
check('[put first] ...and its sentence says what the site does', str_contains($pfAdmin4, 'Nothing is put first in this folder, though the site puts photos with a location, then videos first.'));
$pfFolderPost(['photo_sort_first' => '']);
check('[put first] choosing the Site\'s pill stores nothing for the folder', $pfStored() === null && $pfOrder() === ['z-located.jpg', 'm.mp4', 'a.jpg', 'b.jpg'], json_encode([$pfStored(), $pfOrder()]));
check('[put first] a priority list that is not a permutation of the three kinds is ignored (the kinds are still stored, the order is the site\'s)', (function () use ($pfFolderPost, $pfStored) { $pfFolderPost(['photo_sort_first' => 'own', 'photo_sort_priority' => 'video,video,private', 'photo_sort_video' => 'on', 'photo_sort_located' => 'off', 'photo_sort_private' => 'off']); return $pfStored() == ['video' => true, 'located' => false, 'private' => false]; })(), json_encode($pfStored()));
$pfFolderPost(['photo_sort_first' => '']);
// the owner's page chrome that goes with it
check('[admin] while Site settings or Private is on show, the folder you came from is only softly tinted, not lit like the selection (the page marks the layout as off-folder)', str_contains($pfAdmin4, "classList.toggle('off-folder'") && str_contains($pfAdmin4, '.admin-layout.off-folder .admin-folder-nav a.current'));
[, $pfPage] = http_get("$basePf/");
check('[viewer] the close button and the arrows are drawn icons (a chevron each way, a circled cross) in identical round boxes, and the close button is also the last button of the touch screen\'s top row', str_contains($pfPage, '<symbol id="i-chev-left"') && str_contains($pfPage, '<symbol id="i-chev-right"') && str_contains($pfPage, '<symbol id="i-close"') && preg_match('#class="nav prev"[^>]*aria-label="Previous"><svg#', $pfPage) && preg_match('#class="nav next"[^>]*aria-label="Next"><svg#', $pfPage) && str_contains($pfPage, 'class="lightbox-copy lightbox-close-row"') && str_contains($pfPage, '.lightbox .close, .lightbox .nav { display: flex; align-items: center; justify-content: center; width: 52px; height: 52px;'));
check('[viewer] zoomed in on a desktop, the counter and buttons are one soft dark pill (not a strip), the close button and arrows get the same backing, and each control lights its own box on hover; a button in a state (map on, copied) keeps its colour', str_contains($pfPage, 'border-radius: 999px; background: rgba(0,0,0,0.4); -webkit-backdrop-filter: blur(2px)') && str_contains($pfPage, '.lightbox.zoom-width .close, .lightbox.zoom-actual .close, .lightbox.zoom-width .nav, .lightbox.zoom-actual .nav {') && str_contains($pfPage, '@media (hover: hover) {') && str_contains($pfPage, ':not(.on):not(.nudge):not(.copied):not(.ready)'));
check('[toolbar] the download and gallery buttons keep the same 8px gap as the map and sort controls', str_contains($pfPage, '.page-tools { margin-left: auto; display: inline-flex; align-items: center; gap: 8px;'));

// The sort button is left out of a listing laid out by a notes file (the for-sale pattern), and can be switched off.
$fxSl = new_fixture_dir('sortlisting');
make_jpeg("$fxSl/alpha_01.jpg", 40, 40, 1, 2, 3); make_jpeg("$fxSl/bravo_01.jpg", 40, 40, 3, 2, 1);
file_put_contents("$fxSl/forsale.md", "{gallery}\n# Listing\nAlpha thing {sold}\nBravo thing\n");
write_patched_index("$fxSl/index.php", $indexContents, []);
$portSl = find_free_port(); start_server($fxSl, $portSl);
check('[sort] a listing laid out by a notes file (no folders) has no sort button', !str_contains(http_get("http://127.0.0.1:$portSl/")[1], 'id="sort-combo"'));
$fxSs = new_fixture_dir('sortswitch'); make_jpeg("$fxSs/a.jpg", 40, 40, 1, 2, 3); make_jpeg("$fxSs/b.jpg", 40, 40, 3, 2, 1);
write_patched_index("$fxSs/index.php", $indexContents, []);
$portSs = find_free_port(); start_server($fxSs, $portSs);
check('[sort] a plain gallery of two photos has the sort button', str_contains(http_get("http://127.0.0.1:$portSs/")[1], 'id="sort-combo"'));
check('[sort] left alone, the site\'s order is by date taken', str_contains(http_get("http://127.0.0.1:$portSs/")[1], 'id="sort-combo" data-spec="taken"'));
file_put_contents("$fxSs/.gallery-config.php", cfg_text(['AUTO_GROUP_BY_FILENAME' => true]));
check('[sort] ...but when photos are grouped by filename it is by name, which keeps a group\'s files in sequence', str_contains(http_get("http://127.0.0.1:$portSs/")[1], 'id="sort-combo" data-spec="name"'));
file_put_contents("$fxSs/.gallery-config.php", cfg_text(['AUTO_GROUP_BY_FILENAME' => true, 'PHOTO_SORT_ORDER' => 'taken']));
check('[sort] ...and an order the owner chose is kept either way', str_contains(http_get("http://127.0.0.1:$portSs/")[1], 'id="sort-combo" data-spec="taken"'));
file_put_contents("$fxSs/.gallery-config.php", cfg_text(['SHOW_SORT_BUTTON' => false]));
check('[sort] and the Site setting "Sort button" switches it off', !str_contains(http_get("http://127.0.0.1:$portSs/")[1], 'id="sort-combo"'));
$fxSo1 = new_fixture_dir('sortone'); make_jpeg("$fxSo1/only.jpg", 40, 40, 1, 2, 3);
write_patched_index("$fxSo1/index.php", $indexContents, []);
$portSo1 = find_free_port(); start_server($fxSo1, $portSo1);
check('[sort] a folder with a single photo has no sort button', !str_contains(http_get("http://127.0.0.1:$portSo1/")[1], 'id="sort-combo"'));

// The tile pictures (a square at two sizes, chosen by the browser), made ahead of time by "prepare thumbnails", the viewer that keeps one size,
// and the full-screen app mode the owner can try.
$fxTh = new_fixture_dir('thumbhd');
make_jpeg("$fxTh/land.jpg", 3000, 2000, 200, 80, 60);
make_jpeg("$fxTh/port.jpg", 1800, 2400, 60, 80, 200);
make_jpeg("$fxTh/pano.jpg", 6000, 1000, 60, 200, 80);
make_jpeg("$fxTh/small.jpg", 300, 200, 90, 90, 90);
make_minimal_mp4("$fxTh/clip.mp4", 5, 1);
write_patched_index("$fxTh/index.php", $indexContents, ['ENABLE_VIDEO' => 'true', 'ADMIN_PASSWORD' => "'th-admin-pw'"]);
$portTh = find_free_port(); start_server($fxTh, $portTh); $baseTh = "http://127.0.0.1:$portTh";
[, $thPage] = http_get("$baseTh/");
check('[tile] a tile offers the 560 pixel picture for ordinary screens and the 1080 one for a phone, and tells the browser how wide a tile is (src stays the small thumbnail)', preg_match('#<div class="card[^>]*><img src="\?img=land\.jpg&amp;size=thumb&amp;v=[0-9a-f]{10}" srcset="\?img=land\.jpg&amp;size=tile&amp;v=[0-9a-f]{10} 560w, \?img=land\.jpg&amp;size=tilehd&amp;v=[0-9a-f]{10} 1080w" sizes="\(max-width: 417px\) calc\(100vw - 48px\), 250px"#', $thPage) === 1);
$thSize = function ($file, $size) use ($baseTh) { [$st, $body] = http_get("$baseTh/?img=$file&size=$size"); $d = @getimagesizefromstring($body); return [$st, $d ? [$d[0], $d[1]] : null, $body]; };
[$st1, $d1, $b1] = $thSize('land.jpg', 'tile'); [$st2, $d2, $b2] = $thSize('land.jpg', 'tilehd');
check('[tile] a landscape photo gives a square from its middle at each size: 560x560 and 1080x1080', $st1 === 200 && $d1 === [560, 560] && $st2 === 200 && $d2 === [1080, 1080], json_encode([$d1, $d2]));
[, $d3] = $thSize('port.jpg', 'tile'); [, $d4] = $thSize('port.jpg', 'tilehd');
check('[tile] and so does a portrait one (a tile crops it that way anyway, so nothing visible is lost, and the whole width is sharp)', $d3 === [560, 560] && $d4 === [1080, 1080], json_encode([$d3, $d4]));
[, $d5] = $thSize('pano.jpg', 'tilehd');
check('[tile] a picture is never enlarged: the 6000x1000 panorama\'s square is 1000, not 1080', $d5 === [1000, 1000], json_encode($d5));
[, $d6] = $thSize('small.jpg', 'tilehd');
check('[tile] ...and a 300x200 photo gives a 200 square', $d6 === [200, 200], json_encode($d6));
// the square is taken from the middle: left and right thirds of a landscape photo are cut away
$thMid = imagecreatefromstring($b1);
check('[tile] the square is the middle of the photo (the plain test photo keeps its colour right across)', $thMid && ($rgb = imagecolorat($thMid, 280, 280)) && (($rgb >> 16) & 255) > 150 && (($rgb & 255) < 100));
check('[tile] the pictures are progressive JPEGs (they paint coarse to fine as they load)', str_contains($b1, "\xFF\xC2") && str_contains($b2, "\xFF\xC2") && !str_contains($b1, "\xFF\xC0"));
check('[tile] the big one is not a heavy one (under 150 KB for this plain test picture)', strlen($b2) < 150000, (string) strlen($b2));
[$st7, $d7] = $thSize('land.jpg', 'thumb');
check('[tile] the ordinary thumbnail is as it was (480 on its long side), for the strips and the admin', $st7 === 200 && $d7 === [480, 320], json_encode($d7));
$thCacheBefore = count(glob("$fxTh/.gallery-cache/*.jpg"));
http_get("$baseTh/"); http_get("$baseTh/?folder=nothing");
check('[tile] the cache clean-up keeps the tile pictures (it knows they belong to a photo)', count(glob("$fxTh/.gallery-cache/*.jpg")) === $thCacheBefore && $thCacheBefore >= 5);
check('[tile] with thumbnails switched off a tile has no srcset (there is only the original)', !str_contains(http_get("http://127.0.0.1:$portNoCache/")[1], 'srcset='));
// prepare thumbnails
[, $thAdminBody, $thAdminHd] = http_get("$baseTh/?admin", ['Authorization: Basic ' . base64_encode('admin:th-admin-pw')]);
$thCookie = extract_cookie_header($thAdminHd);
$thAuthH = ['Authorization: Basic ' . base64_encode('admin:th-admin-pw'), "Cookie: $thCookie", 'X-Requested-With: gallery-fetch'];
$thTok = (json_decode(http_get("$baseTh/?admin&csrf=1", $thAuthH)[1], true)['token'] ?? '');
check('[prepare thumbnails] the Site tab says how many photos are ready, and offers to make the rest', preg_match('#id="warm-panel"#', $thAdminBody) === 1 && str_contains($thAdminBody, 'of 4 photos are ready') && str_contains($thAdminBody, 'warm-go'), substr(strip_tags(substr($thAdminBody, strpos($thAdminBody, 'id="warm-panel"'), 900)), 0, 200));
foreach (glob("$fxTh/.gallery-cache/*.jpg") as $f) @unlink($f);
// (a request lasts about a second at most and says where to carry on, so ask again until it says it is done)
$warm = ['ok' => true, 'done' => false, 'total' => 0, 'made' => 0, 'failed' => 0]; $warmFrom = 0;
for ($warmTry = 0; $warmTry < 20 && empty($warm['done']); $warmTry++) {
    [, $warmBody] = http_post("$baseTh/?admin", ['csrf_token' => $thTok, 'action' => 'warm_thumbnails', 'from' => (string) $warmFrom], $thAuthH);
    $one = json_decode($warmBody, true) ?: ['ok' => false];
    $warm = ['ok' => $one['ok'] ?? false, 'done' => $one['done'] ?? false, 'total' => $one['total'] ?? 0, 'made' => $warm['made'] + ($one['made'] ?? 0), 'failed' => $warm['failed'] + ($one['failed'] ?? 0)];
    $warmFrom = $one['next'] ?? 0;
}
check('[prepare thumbnails] asking until it says it is done makes both tile sizes of every photo (the video is left alone)', ($warm['ok'] ?? false) === true && ($warm['done'] ?? false) === true && $warm['total'] === 4 && $warm['made'] === 8 && $warm['failed'] === 0 && count(glob("$fxTh/.gallery-cache/*.jpg")) === 8, $warmBody);
[, $warm2Body] = http_post("$baseTh/?admin", ['csrf_token' => $thTok, 'action' => 'warm_thumbnails', 'from' => '0'], $thAuthH);
check('[prepare thumbnails] a second run finds nothing left to make', (json_decode($warm2Body, true)['made'] ?? -1) === 0);
[, $warm3Body] = http_post("$baseTh/?admin", ['csrf_token' => $thTok, 'action' => 'warm_thumbnails', 'from' => '2'], $thAuthH);
check('[prepare thumbnails] it can carry on from where it stopped', (json_decode($warm3Body, true)['next'] ?? 0) === 4);
[, $warmNoTok] = http_post("$baseTh/?admin", ['action' => 'warm_thumbnails', 'from' => '0'], $thAuthH);
// Shared work: three requests, each taking every third photo, make everything between them with nothing done twice.
foreach (glob("$fxTh/.gallery-cache/*.jpg") as $f) @unlink($f);
$warmShares = [];
foreach ([0, 1, 2] as $k) {
    $share = ['worked' => 0, 'made' => 0, 'failed' => 0, 'done' => false]; $shareFrom = $k;
    for ($shareTry = 0; $shareTry < 20 && !$share['done']; $shareTry++) {
        [, $wb] = http_post("$baseTh/?admin", ['csrf_token' => $thTok, 'action' => 'warm_thumbnails', 'from' => (string) $shareFrom, 'step' => '3'], $thAuthH);
        $one = json_decode($wb, true) ?: [];
        $share = ['worked' => $share['worked'] + ($one['worked'] ?? 0), 'made' => $share['made'] + ($one['made'] ?? 0), 'failed' => $share['failed'] + ($one['failed'] ?? 0), 'done' => $one['done'] ?? false];
        $shareFrom = $one['next'] ?? 0;
    }
    $warmShares[$k] = $share;
}
check('[prepare thumbnails] several requests can share the work, each taking every n-th photo: between them every photo is made once, and each says how many photos it made', ($warmShares[0]['worked'] ?? -1) === 2 && ($warmShares[1]['worked'] ?? -1) === 1 && ($warmShares[2]['worked'] ?? -1) === 1 && array_sum(array_column($warmShares, 'made')) === 8 && !array_filter($warmShares, function ($r) { return empty($r['done']) || ($r['failed'] ?? 1) !== 0; }) && count(glob("$fxTh/.gallery-cache/*.jpg")) === 8, json_encode($warmShares));
check('[prepare thumbnails] without the admin\'s token nothing happens', (json_decode($warmNoTok, true)['ok'] ?? true) === false);
check('[prepare thumbnails] a visitor cannot run it', !isset(json_decode(http_post("$baseTh/?admin", ['csrf_token' => $thTok, 'action' => 'warm_thumbnails', 'from' => '0'], ['X-Requested-With: gallery-fetch'])[1] ?? '', true)['made']));
[, $thAdminBody2] = http_get("$baseTh/?admin", $thAuthH);
check('[prepare thumbnails] once everything is made the panel says all photos are ready', str_contains($thAdminBody2, 'All 4 photos are ready.'));
// the viewer and the download
check('[viewer] on a touch screen the counter and buttons are a row across the top and the photo takes the rest of the visible area (dvh), nothing is laid over the photo or near the bottom edge', str_contains($thPage, '@media (pointer: coarse)') && str_contains($thPage, '100dvh') && str_contains($thPage, 'position: fixed; top: 0; left: 0; right: 0; bottom: auto') && !str_contains($thPage, 'lvh') && !str_contains($thPage, 'bars-away'));
[, $thOwnerPage] = http_get("$baseTh/", $thAuthH);
check('[viewer] the download button takes the photo the viewer already holds (from the browser\'s memory), so the share sheet can open on the first tap', str_contains($thOwnerPage, "cache: 'force-cache'") && str_contains($thOwnerPage, 'lightboxImg.getAttribute(\'src\')'));
// full-screen app mode (owner only, for now)
check('[app mode] the owner\'s pages carry what "Add to Home Screen" needs to open with no browser bars', str_contains($thOwnerPage, 'name="apple-mobile-web-app-capable" content="yes"') && preg_match('#<link rel="manifest" href="\./\?manifest&amp;name=#', $thOwnerPage) === 1 && str_contains($thOwnerPage, 'rel="apple-touch-icon" href="./?appicon&amp;v=2"'));
check('[app mode] a visitor\'s pages carry none of it (an ordinary tab is unchanged)', !str_contains($thPage, 'apple-mobile-web-app') && !str_contains($thPage, 'rel="manifest"'));
[$mfSt, $mfBody, $mfHd] = http_get("$baseTh/?manifest&name=" . rawurlencode('My <b>Trip</b>'));
$mf = json_decode($mfBody, true);
check('[app mode] the manifest is JSON that opens the gallery full screen, from its own address, with icons', $mfSt === 200 && ($mf['display'] ?? '') === 'standalone' && ($mf['start_url'] ?? '') === '/' && ($mf['scope'] ?? '') === '/' && count($mf['icons'] ?? []) === 2 && $mf['name'] === 'My <b>Trip</b>' && stripos(implode("\n", $mfHd), 'application/manifest+json') !== false, $mfBody);
[, $iconBody] = http_get("$baseTh/?appicon&size=large&v=2"); $iconIm = @imagecreatefromstring($iconBody);
$lum = function ($x, $y) use ($iconIm) { $c = imagecolorat($iconIm, $x, $y); return (($c >> 16 & 255) + ($c >> 8 & 255) + ($c & 255)) / 3; };
check('[app mode] the home screen icon is the gallery icon: a light rounded frame (left edge, bottom edge) and sun ring on a dark ground, dark in the corner, the frame\'s inside and the sun\'s middle', $iconIm && $lum(2, 2) < 30 && $lum(108, 256) > 200 && $lum(256, 388) > 200 && $lum(205, 190) > 200 && $lum(300, 300) < 30 && $lum(205, 222) < 30 && $lum(150, 256) < 30);
foreach ([['?appicon', 180], ['?appicon&size=large', 512]] as [$q, $px]) { [$iSt, $iBody] = http_get("$baseTh/$q"); $di = @getimagesizefromstring($iBody); check("[app mode] the home screen icon ($px px) is a PNG", $iSt === 200 && $di && $di[0] === $px && $di[1] === $px && $di[2] === IMAGETYPE_PNG); }

// A place from elsewhere that has gone missing is reported to the owner instead of silently showing nothing.
$fxGone = new_fixture_dir('extragone');
make_jpeg("$fxGone/here.jpg", 40, 40, 1, 2, 3);
write_patched_index("$fxGone/index.php", $indexContents, ['AUTO_GROUP_BY_FOLDER' => 'true']);
file_put_contents("$fxGone/.gallery-config.php", cfg_text(['AUTO_GROUP_BY_FOLDER' => true, 'EXTRA_ROOTS' => ['/nonexistent/space/trip']]));
$portGone = find_free_port(); start_server($fxGone, $portGone);
[, $goneBody] = http_get("http://127.0.0.1:$portGone/");
check('[setup notice] a folder from elsewhere that is missing is reported (without naming its path) instead of silently showing nothing', str_contains($goneBody, 'A folder from elsewhere on the server is missing or can&#039;t be read') && !str_contains($goneBody, '/nonexistent/space/trip'));

// Capture dates also come from HEIC photos and videos (and an iPhone's recorded UTC offset makes them exact), not only from JPEGs.
$fxSd = new_fixture_dir('sortdates');
$sdBox = fn ($type, $data) => pack('N', 8 + strlen($data)) . $type . $data;
$sdTiff = function ($dateText) { $d = $dateText . "\0"; $ex = 8 + 2 + 12 + 4; $at = $ex + 2 + 12 + 4; return 'MM' . pack('n', 42) . pack('N', 8) . pack('n', 1) . pack('nnNN', 0x8769, 4, 1, $ex) . pack('N', 0) . pack('n', 1) . pack('nnNN', 0x9003, 2, strlen($d), $at) . pack('N', 0) . $d; };
$sdHeic = function ($dateText) use ($sdBox, $sdTiff) {
    $ftyp = $sdBox('ftyp', 'heic' . pack('N', 0) . 'mif1heic'); $item = pack('N', 0) . $sdTiff($dateText);
    $mk = function ($off) use ($sdBox, $item) {
        $infe = $sdBox('infe', "\x02\0\0\0" . pack('n', 1) . pack('n', 0) . 'Exif' . "Exif\0");
        $iinf = $sdBox('iinf', "\0\0\0\0" . pack('n', 1) . $infe);
        $iloc = $sdBox('iloc', "\0\0\0\0" . "\x44\x00" . pack('n', 1) . pack('n', 1) . pack('n', 0) . pack('n', 1) . pack('N', $off) . pack('N', strlen($item)));
        return $sdBox('meta', "\0\0\0\0" . $iinf . $iloc);
    };
    $off = strlen($ftyp) + strlen($mk(0)) + 8;
    return $ftyp . $mk($off) . $sdBox('mdat', $item . 'PIXELS');
};
file_put_contents("$fxSd/a-late.heic", $sdHeic('2024:06:01 10:00:00'));
make_dated_jpeg("$fxSd/b-early.jpg", '2020:01:01 10:00:00');
make_minimal_mp4("$fxSd/c.mp4", 5, 1);
$sdMp4 = file_get_contents("$fxSd/c.mp4"); $sdAt = strpos($sdMp4, 'mvhd');
file_put_contents("$fxSd/c.mp4", substr_replace($sdMp4, pack('N', gmmktime(0, 0, 0, 1, 1, 2022) + 2082844800), $sdAt + 8, 4));
// A phone video: the movie header says it was made on 2026-10-09 (when the file was last saved), the metadata says it was shot on 2021-06-01.
$sdQt = function ($key, $value) use ($sdBox) {
    $keys = $sdBox('keys', pack('N', 0) . pack('N', 2) . pack('N', 8 + 4) . 'mdtamake' . pack('N', 8 + strlen($key)) . 'mdta' . $key);
    $item = function ($n, $text) use ($sdBox) { return $sdBox(pack('N', $n), $sdBox('data', pack('N', 1) . pack('N', 0) . $text)); };
    return $sdBox('meta', $sdBox('hdlr', str_repeat("\0", 8) . 'mdta' . str_repeat("\0", 13)) . $keys . $sdBox('ilst', $item(1, 'Apple') . $item(2, $value)));
};
make_minimal_mp4("$fxSd/d.mp4", 5, 1);
$sdMp4 = file_get_contents("$fxSd/d.mp4"); $sdAt = strpos($sdMp4, 'mvhd');
$sdMp4 = substr_replace($sdMp4, pack('N', gmmktime(0, 0, 0, 10, 9, 2026) + 2082844800), $sdAt + 8, 4);
$sdMoov = strpos($sdMp4, 'moov') - 4;
$sdMeta = $sdQt('com.apple.quicktime.creationdate', '2021-06-01T10:00:00+0200');
file_put_contents("$fxSd/d.mp4", substr($sdMp4, 0, $sdMoov) . pack('N', unpack('N', substr($sdMp4, $sdMoov, 4))[1] + strlen($sdMeta)) . substr($sdMp4, $sdMoov + 4) . $sdMeta);
foreach (['a-late.heic', 'b-early.jpg', 'c.mp4', 'd.mp4'] as $sdF) touch("$fxSd/$sdF", strtotime('2026-10-10'));
write_patched_index("$fxSd/index.php", $indexContents, ['ENABLE_VIDEO' => 'true']);
$portSd = find_free_port(); start_server($fxSd, $portSd);
$sdOrder = function ($q) use ($portSd) { [, $body] = http_get("http://127.0.0.1:$portSd/$q"); preg_match_all('#<div class="card[^>]*><img src="[^"]*"(?: srcset="[^"]*")?(?: sizes="[^"]*")? loading="lazy" alt="([^"]+)"#', $body, $mm); return $mm[1]; };
if (class_exists('Imagick')) {
    check('[sort] date order reads the capture date out of a HEIC photo, a JPEG and two videos, one of them a phone video whose metadata says when it was shot rather than the date its header was last saved (all four files have the same modified date, so only the dates inside them can order these)', $sdOrder('?sort=name') === ['a-late.heic', 'b-early.jpg', 'c.mp4', 'd.mp4'] && $sdOrder('?sort=taken') === ['b-early.jpg', 'd.mp4', 'c.mp4', 'a-late.heic'] && $sdOrder('?sort=taken.desc') === ['a-late.heic', 'c.mp4', 'd.mp4', 'b-early.jpg'], json_encode([$sdOrder('?sort=name'), $sdOrder('?sort=taken')]));
} else {
    echo "  (no Imagick here: the HEIC date check was skipped)\n";
}

// The order also holds on a folder's own page (the first version only reordered the photos at the top of the gallery).
$fxSf = new_fixture_dir('sortfolder'); mkdir("$fxSf/Trip"); mkdir("$fxSf/Trip/Day2");
foreach (['a', 'b', 'c'] as $n) make_jpeg("$fxSf/Trip/$n.jpg", 40, 40, 10, 20, 30);
make_minimal_mp4("$fxSf/Trip/z.mp4", 5, 1);
make_jpeg("$fxSf/Trip/Day2/d.jpg", 40, 40, 1, 2, 3); make_minimal_mp4("$fxSf/Trip/Day2/y.mp4", 5, 1);
write_patched_index("$fxSf/index.php", $indexContents, ['AUTO_GROUP_BY_FOLDER' => 'true', 'ENABLE_VIDEO' => 'true']);
$portSf = find_free_port(); start_server($fxSf, $portSf);
$sfOrder = function ($q) use ($portSf) { [, $body] = http_get("http://127.0.0.1:$portSf/?folder=Trip$q"); preg_match_all('#<div class="card[^>]*><img src="[^"]*"(?: srcset="[^"]*")?(?: sizes="[^"]*")? loading="lazy" alt="([^"]+)"#', $body, $m); return array_map('basename', $m[1]); };
$sfDay = function ($q) use ($portSf) { [, $body] = http_get("http://127.0.0.1:$portSf/?folder=Trip%2FDay2$q"); preg_match_all('#<div class="card[^>]*><img src="[^"]*"(?: srcset="[^"]*")?(?: sizes="[^"]*")? loading="lazy" alt="([^"]+)"#', $body, $m); return array_map('basename', $m[1]); };
check('[sort] on a folder\'s page the photos run in the chosen order: by default, last first, and videos first', $sfOrder('') === ['a.jpg', 'b.jpg', 'c.jpg', 'z.mp4'] && $sfOrder('&sort=name.desc') === ['z.mp4', 'c.jpg', 'b.jpg', 'a.jpg'] && $sfOrder('&sort=name.video') === ['z.mp4', 'a.jpg', 'b.jpg', 'c.jpg'], json_encode([$sfOrder(''), $sfOrder('&sort=name.desc'), $sfOrder('&sort=name.video')]));
check('[sort] ...and on a subfolder\'s own page too', $sfDay('') === ['d.jpg', 'y.mp4'] && $sfDay('&sort=name.video') === ['y.mp4', 'd.jpg'], json_encode([$sfDay(''), $sfDay('&sort=name.video')]));

// The preview picture of the gallery's own link: a photo from anywhere in it (not a video, not a private one), steady for the day.
$ogOf = function ($port, $headers = []) { [, $body] = http_get("http://127.0.0.1:$port/", $headers); return preg_match('#<meta property="og:image" content="[^"]*\?img=([^&"]+)#', $body, $mm) ? rawurldecode(html_entity_decode($mm[1])) : null; };
$ogSeen = []; for ($i = 0; $i < 3; $i++) $ogSeen[] = $ogOf($portSo);
check('[preview] the gallery link\'s picture is a photo (never a private one or a video), and the same on every request that day', count(array_unique($ogSeen)) === 1 && $ogSeen[0] !== null && !in_array($ogSeen[0], ['p-private.jpg', 'm.mp4'], true) && is_file("$fxSo/{$ogSeen[0]}"), json_encode($ogSeen));
$ogFolder = $ogOf($portSf);
check('[preview] with folders, the gallery link\'s picture is a folder\'s cover (here the only folder\'s first photo, never the video)', $ogFolder === 'Trip/a.jpg', json_encode($ogFolder));
write_data_file("$fxSf/Trip/.gallery-items.php", ['items' => [], 'groups' => [], 'displayName' => null, 'cover' => 'c.jpg', 'locationEnabled' => false]);
check('[preview] ...and when the owner chose that folder\'s cover, that is the picture', $ogOf($portSf) === 'Trip/c.jpg', json_encode($ogOf($portSf)));

// Quick links to the folders beside the one you're in.
$fxPl = new_fixture_dir('folderpills');
foreach (['Alpha', 'Bravo', 'Charlie'] as $pn) { mkdir("$fxPl/$pn"); make_jpeg("$fxPl/$pn/x.jpg", 30, 30, 1, 2, 3); }
mkdir("$fxPl/Secret"); make_jpeg("$fxPl/Secret/x.jpg", 30, 30, 9, 9, 9);
write_data_file("$fxPl/Secret/.gallery-items.php", ['items' => [], 'groups' => [], 'displayName' => null, 'cover' => null, 'locationEnabled' => false, 'restriction' => 'private']);
mkdir("$fxPl/Solo"); mkdir("$fxPl/Solo/Only"); make_jpeg("$fxPl/Solo/Only/x.jpg", 30, 30, 1, 2, 3);
mkdir("$fxPl/Many"); foreach (range(1, 14) as $pi) { $pd = sprintf("$fxPl/Many/F%02d", $pi); mkdir($pd); make_jpeg("$pd/x.jpg", 30, 30, 1, 2, 3); }
write_patched_index("$fxPl/index.php", $indexContents, ['AUTO_GROUP_BY_FOLDER' => 'true', 'ADMIN_PASSWORD' => "'pills-pw-123456'"]);
$portPl = find_free_port(); start_server($fxPl, $portPl);
$plPills = function ($q) use ($portPl) { [, $b] = http_get("http://127.0.0.1:$portPl/?folder=$q"); if (!preg_match('#<nav class="folder-pills"[^>]*>(.*?)</nav>#s', $b, $nm)) return null; preg_match_all('#<a ([^>]*)>([^<]*)</a>#', $nm[1], $am, PREG_SET_ORDER); return array_map(fn ($a) => [html_entity_decode($a[2]), str_contains($a[1], 'class="current"'), str_contains($a[1], 'class="all"')], $am); };
$plA = $plPills('Bravo');
check('[folder pills] inside a folder, the other folders at that level are quick links (in the order the main page shows them), the current one marked, and a private folder is not among them', $plA !== null && array_column($plA, 0) === ['Alpha', 'Bravo', 'Charlie', 'Many', 'Solo'] && array_column($plA, 1) === [false, true, false, false, false], json_encode($plA));
$plM = $plPills('Many%2FF08');
check('[folder pills] with more than ten siblings it shows a window of nine around the current one, plus "All" back to the parent', $plM !== null && count($plM) === 10 && $plM[9][2] === true && in_array(['F08', true, false], $plM, true) && array_column($plM, 0)[0] === 'F04' && array_column(array_slice($plM, 0, 9), 0)[8] === 'F12', json_encode($plM));
$plEnd = $plPills('Many%2FF14');
check('[folder pills] ...and at the end of a long row the window stays full', $plEnd !== null && array_column(array_slice($plEnd, 0, 9), 0)[0] === 'F06' && in_array(['F14', true, false], $plEnd, true));
check('[folder pills] the gallery front page has none', !str_contains(http_get("http://127.0.0.1:$portPl/")[1], 'class="folder-pills"'));
check('[folder pills] a folder with no siblings has none', !str_contains(http_get("http://127.0.0.1:$portPl/?folder=Solo%2FOnly")[1], 'class="folder-pills"'));
$plOg = []; for ($i = 0; $i < 3; $i++) $plOg[] = $ogOf($portPl);
check('[preview] among several folders it is one of their covers, never a private folder\'s, and the same on every request that day', count(array_unique($plOg)) === 1 && preg_match('#^(Alpha|Bravo|Charlie|Many|Solo)/(.+/)?x\.jpg$#', (string) $plOg[0]) === 1 && !str_starts_with((string) $plOg[0], 'Secret/'), json_encode($plOg));

$fxSe = new_fixture_dir('sortextra'); $fxSeX = new_fixture_dir('sortextra2'); mkdir("$fxSeX/Paris");
make_jpeg("$fxSeX/Paris/a.jpg", 40, 40, 5, 5, 5); make_minimal_mp4("$fxSeX/Paris/z.mp4", 5, 1);
write_patched_index("$fxSe/index.php", $indexContents, ['ENABLE_VIDEO' => 'true']);
file_put_contents("$fxSe/.gallery-config.php", cfg_text(['AUTO_GROUP_BY_FOLDER' => true, 'EXTRA_ROOTS' => [$fxSeX], 'ENABLE_VIDEO' => true]));
$portSe = find_free_port(); start_server($fxSe, $portSe);
[, $seBody] = http_get("http://127.0.0.1:$portSe/?folder=Paris&sort=name.video");
preg_match_all('#<div class="card[^>]*><img src="[^"]*"(?: srcset="[^"]*")?(?: sizes="[^"]*")? loading="lazy" alt="([^"]+)"#', $seBody, $seM);
check('[sort] the order also holds inside a folder brought in from elsewhere on the server', array_map('basename', $seM[1]) === ['z.mp4', 'a.jpg'], json_encode($seM[1]));

// A video looks like a video wherever it is shown: the admin's grid and editor, a strip of group thumbnails, a folder's cover.
$fxVm = new_fixture_dir('videomark'); mkdir("$fxVm/Reels");
make_jpeg("$fxVm/photo.jpg", 60, 60, 80, 80, 80);
make_minimal_mp4("$fxVm/clip.mp4", 20, 1);
make_minimal_mp4("$fxVm/Reels/reel.mp4", 9, 1);
file_put_contents("$fxVm/trip.md", "{gallery}\nClips. {group: Clips} {photos: clip.mp4, photo.jpg}\n");
write_patched_index("$fxVm/index.php", $indexContents, ['ENABLE_VIDEO' => 'true', 'ADMIN_PASSWORD' => "'vm-admin-pw'", 'AUTO_GROUP_BY_FOLDER' => 'true']);
$portVm = find_free_port(); start_server($fxVm, $portVm); $baseVm = "http://127.0.0.1:$portVm";
[, $vmAdmin] = http_get("$baseVm/?admin", ['Authorization: Basic ' . base64_encode('admin:vm-admin-pw')]);
[, , $vmAdminHd] = http_get("$baseVm/?admin", ['Authorization: Basic ' . base64_encode('admin:vm-admin-pw')]);
[, $vmOwnerPage] = http_get("$baseVm/", ['Cookie: ' . extract_cookie_header($vmAdminHd)]);
check('[video mark] the admin page has no thumbnail grid or per-photo forms any more; a video on the owner\'s gallery page is a video tile (play mark, length) with its pencil beside the length', !str_contains($vmAdmin, 'class="thumb-grid"') && !str_contains($vmAdmin, 'class="item-editor"') && preg_match('#class="card owner-card vtile"[^>]*data-file="clip\.mp4"|class="card owner-card vtile"[^>]*data-strip#', $vmOwnerPage) === 1 && str_contains($vmOwnerPage, 'class="edit-toggle" data-file="clip.mp4"') && str_contains($vmOwnerPage, '.owner-card .video-duration { left: 38px; }'));
$vmAuth = ['Authorization: Basic ' . base64_encode('admin:vm-admin-pw')];
check('[wording] with videos switched on the strip\'s button and bar say "media" (Select media, Tap media to select); without them "photos"', str_contains($vmOwnerPage, 'title="Select media"') && str_contains($vmOwnerPage, 'Tap media to select') && !str_contains($vmAdmin, 'data-tab="photos"') && str_contains($vmAdmin, 'data-tab="settings"'));
check('[health] Site settings opens with a Health check: the running version is recorded, the checks read fine on a healthy install', str_contains($vmAdmin, 'Health check') && str_contains($vmAdmin, 'Running ' . (preg_match("/define\('GALLERY_VERSION', '([^']+)'\)/", $indexContents, $verM) ? $verM[1] : '?')) && str_contains($vmAdmin, 'Folder data files all read fine') && str_contains($vmAdmin, 'All 2 folders are writable') && str_contains($vmAdmin, 'data-health="lock"') && is_file("$fxVm/.gallery-health.php") && !str_contains($vmAdmin, 'health-row health-bad'));
$vmHealthGuard = (string) @file_get_contents("$fxVm/.gallery-health.php");
check('[health] the version file is a guarded data file (a direct request for it answers 404 with nothing else)', str_starts_with($vmHealthGuard, "<?php http_response_code(404); exit; ?>\n") && http_get("$baseVm/.gallery-health.php")[0] === 404);
file_put_contents("$fxVm/.gallery-health.php", data_file_guard_line_for_tests() . json_encode(['version' => '2.0.1', 'from' => '', 'at' => '2026-01-02', 'first' => '2026-01-02']));
[, $vmHealthUp] = http_get("$baseVm/?admin", $vmAuth);
check('[health] after an upgrade the panel says what it was upgraded from, and the file is brought up to date', str_contains($vmHealthUp, 'Upgraded from 2.0.1') && str_contains((string) file_get_contents("$fxVm/.gallery-health.php"), '"from":"2.0.1"'));
file_put_contents("$fxVm/Reels/.gallery-items.php", data_file_guard_line_for_tests() . '{"items": {"reel.mp4": {"caption": "unfinished');
[, $vmHealthBad] = http_get("$baseVm/?admin", $vmAuth);
check('[health] a folder data file that is not valid JSON is called out with its folder (it would otherwise be read as empty and overwritten by the next save)', str_contains($vmHealthBad, '1 folder data file can&#039;t be read') && str_contains($vmHealthBad, 'Reels') && str_contains($vmHealthBad, 'would overwrite') && str_contains($vmHealthBad, 'health-row health-bad loud') && str_contains($vmHealthBad, 'to look at.'));
unlink("$fxVm/Reels/.gallery-items.php");
@chmod("$fxVm/Reels", 0555);
[, $vmHealthRo] = http_get("$baseVm/?admin", $vmAuth);
@chmod("$fxVm/Reels", 0755);
check('[health] a folder the web server can\'t write to is called out, with what that costs', function_exists('posix_geteuid') && posix_geteuid() === 0 ? true : (str_contains($vmHealthRo, '1 of 2 folders are not writable') && str_contains($vmHealthRo, 'Reels')));
[, $vmPage] = http_get("$baseVm/");
check('[video mark] a video in a strip of group thumbnails is wrapped as a video tile with the play mark and opens the viewer; a photo there stays a bare picture', preg_match('#<span class="vthumb vtile"[^>]* onclick="openLightbox\(\d+\)"><img src="\?img=clip\.mp4[^>]*>(<video[^>]*></video>)?<span class="video-mark"#', $vmPage) === 1 && preg_match('#<img src="\?img=photo\.jpg[^>]* onclick="openLightbox#', $vmPage) === 1);
[, $vmFolder] = http_get("$baseVm/?folder=Reels");
check('[video mark] a video card on a folder page is a video tile and the page carries the video-tile script', str_contains($vmFolder, 'class="card vtile"') && str_contains($vmFolder, 'vtile[data-strip]') && str_contains($vmFolder, 'tile-strip'));
check('[video mark] a folder whose cover is a video shows the play mark on its cover card', preg_match('#<a class="folder-cover-link vtile"[^>]*>.*?class="video-mark"#s', $vmPage) === 1);

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
check('?admin with no credentials shows a password form (200), not a browser Basic-Auth pop-up', $noAuthStatus === 200 && str_contains($noAuthBody, 'name="access_code"') && !array_filter($noAuthHeaders, fn($h) => stripos($h, 'WWW-Authenticate') === 0));
check('?admin\'s login form is never indexed or cached', str_contains($noAuthBody, 'noindex') && (bool) array_filter($noAuthHeaders, fn($h) => stripos($h, 'X-Robots-Tag: noindex') === 0) && (bool) array_filter($noAuthHeaders, fn($h) => stripos($h, 'Cache-Control: no-store') === 0));
check('...and asking for it starts no session (no Set-Cookie for an anonymous visitor)', !array_filter($noAuthHeaders, fn($h) => stripos($h, 'Set-Cookie:') === 0));

[$wrongAuthStatus, $wrongAuthBody, $wrongAuthHeaders] = http_get("$baseAdmin/?admin", [$wrongAuthHeader]);
check('?admin with a wrong password in a Basic header (a script, or a browser re-sending an old saved one) returns 401 and the login form, and NEVER a WWW-Authenticate challenge (that is what opens the browser\'s own dialog)', $wrongAuthStatus === 401 && !array_filter($wrongAuthHeaders, fn($h) => stripos($h, 'WWW-Authenticate') === 0) && str_contains($wrongAuthBody, 'name="access_code"') && (str_contains($wrongAuthBody, 'saved login') ));

[$okStatus, $adminBody, $adminHeaders] = http_get("$baseAdmin/?admin", [$authHeader]);
[$acSt, $acBody, $acHd] = http_get("$baseAdmin/?admin&accelcheck=1&prefix=%2Fkb", [$authHeader]);
$acH = function ($hd, $name) { foreach ($hd as $h) if (stripos($h, "$name:") === 0) return trim(substr($h, strlen($name) + 1)); return null; };
check('[nginx redirect check] the admin can ask the gallery to answer as it would with the setting on: no body, an X-Accel-Redirect to one of its photos under the prefix, never cached', $acSt === 200 && $acBody === '' && preg_match('#^/kb/[^/]+\.jpe?g$#i', (string) $acH($acHd, 'X-Accel-Redirect')) === 1 && str_contains((string) $acH($acHd, 'Cache-Control'), 'no-store'));
[$ac2St, , $ac2Hd] = http_get("$baseAdmin/?admin&accelcheck=1&prefix=kb%2F..%2Fetc", [$authHeader]);
[$ac3St, , $ac3Hd] = http_get("$baseAdmin/?admin&accelcheck=1&prefix=%2Fkb");
check('[nginx redirect check] a prefix that is not a plain path is refused, and without signing in no X-Accel-Redirect is ever sent', $ac2St === 404 && $acH($ac2Hd, 'X-Accel-Redirect') === null && $acH($ac3Hd, 'X-Accel-Redirect') === null);

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
check('...whose tooltip says where it goes: "Information link: <url>"', str_contains($publicBodyWithWeb, 'title="Information link: https://example.com/info"'));

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
[, $bodyAfterOrphan] = http_get("$baseAdmin/", [$authHeader, "Cookie: $sessionCookie"]);   // (setup notices are for the signed-in owner)
$bodyAfterOrphanPublic = http_get("$baseAdmin/")[1];
check('a stale caption entry for a deleted photo is reported in the notices', str_contains($bodyAfterOrphan, 'was cleaned up'));
check('...but only to the signed-in owner: once an admin password exists, the banner and the comment in the page source are not sent to anyone else', !str_contains($bodyAfterOrphanPublic, 'was cleaned up') && !str_contains($bodyAfterOrphanPublic, '<details class="gallery-notices"') && !str_contains($bodyAfterOrphanPublic, 'gallery notices (also shown'));
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
check('with an admin password the closed lock is there from the start (it is the way to the admin), and there is no "Private view" footer link', str_contains($privateBaselineBody, 'class="map-combo-btn lock-link" href="?reveal"') && !str_contains($privateBaselineBody, '>Private view</a>'));
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
check('[lock] once something is private, a closed lock appears in the icon strip (not beside the title) and links to the password form; the old footer link is gone', str_contains($afterHideBody, 'class="map-combo-btn lock-link" href="?reveal"') && preg_match('#<h1>(.*?)</h1>#s', $afterHideBody, $lockH1) === 1 && !str_contains($lockH1[1], 'lock-link') && !str_contains($lockH1[1], 'title-admin') && preg_match('#<span class="page-tools">.*?class="map-combo-btn lock-link".*?class="map-combo-btn page-about"#s', $afterHideBody) === 1 && str_contains($afterHideBody, ui_lock_probe('lock')) && !str_contains($afterHideBody, '>Private view</a>'));
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
[, $revealedHomeBody] = http_get("$basePrivate/", ["Cookie: $revealCookie"]);
check('[app mode] a viewer signed in with the private-view password gets the Home Screen tags too (so a family member can add the gallery to their Home Screen)', str_contains($revealedHomeBody, 'name="apple-mobile-web-app-capable" content="yes"') && str_contains($revealedHomeBody, 'rel="manifest"') && str_contains($revealedHomeBody, 'rel="apple-touch-icon"'));
check('[app mode] ...and a visitor who is not signed in gets none of it', !str_contains(http_get("$basePrivate/")[1], 'apple-mobile-web-app'));
check('[counts] a signed-in viewer is not the owner: with public counts off, no count shows for them', str_contains($revealedHomeBody, 'const showCountsPublicly = false;') && !preg_match('#class="view-badge"[^>]*data-photo#', $revealedHomeBody));
[, $ownerCountsBody] = http_get("$basePrivate/", ["Cookie: $sessCookie"]);
check('[counts] the owner always sees each photo\'s view count, even with public counts off and a count of zero (loose2.jpg: no view yet)', (bool) preg_match('#<span class="view-badge" data-photo="loose2\.jpg"><svg[^>]*><use href="\#i-eye"></use></svg> 0</span>#', $ownerCountsBody) && str_contains($ownerCountsBody, 'const showCountsPublicly = true;'), substr($ownerCountsBody, 0, 0));
check('[counts] ...and visitors still see none', (bool) preg_match('#class="view-badge hidden"[^>]*data-photo="loose2\.jpg"#', http_get("$basePrivate/")[1]));
check('[lock] a viewer who has the password sees the lock open: a button in a small form that posts to ?reveal', str_contains($revealedBody, 'class="lock-form"') && str_contains($revealedBody, 'name="logout"') && str_contains($revealedBody, ui_lock_probe('unlock')) && !str_contains($revealedBody, 'href="?reveal"'));
[$lockOutStatus, , $lockOutHd] = http_post("$basePrivate/?reveal", ['logout' => '1', 'folder' => ''], ["Cookie: $revealCookie"], false);
$lockOutCookie = (string) extract_cookie_header($lockOutHd);
check('[lock] pressing it forgets this browser\'s private-view cookie and goes back to the page, which is then locked again', $lockOutStatus === 303 && (bool) array_filter($lockOutHd, fn($h) => stripos($h, 'Location: ./') === 0) && !str_contains(http_get("$basePrivate/", ['Cookie: ' . ($lockOutCookie !== '' ? $lockOutCookie : 'x=y')])[1], 'loose1.jpg'));
[, $lockAdminBody] = http_get("$basePrivate/", ["Cookie: $sessCookie"]);
check('[lock] the owner (signed in to the admin) sees it open too, and it is the same sign-out button', str_contains($lockAdminBody, 'class="lock-form"') && str_contains($lockAdminBody, 'name="logout"') && str_contains($lockAdminBody, 'signed in as the owner'));
[$lockAdminOutStatus, , $lockAdminOutHd] = http_post("$basePrivate/?reveal", ['logout' => '1', 'folder' => ''], ["Cookie: $sessCookie"], false);
[, $afterOwnerOut] = http_get("$basePrivate/?admin", ["Cookie: $sessCookie"]);
check('[lock] pressing it as the owner signs the owner out completely: the admin session is gone (the old cookie opens the login form, not the admin) and both its cookies are expired, so the private items lock again', $lockAdminOutStatus === 303 && str_contains($afterOwnerOut, 'name="access_code"') && !str_contains($afterOwnerOut, 'Gallery Admin') && count(array_filter($lockAdminOutHd, fn($h) => stripos($h, 'Set-Cookie:') === 0 && (stripos($h, '1970') !== false || stripos($h, 'Max-Age=0') !== false))) >= 2);
[, $adminLoginBody, $adminLoginHeadersFirst] = http_get("$basePrivate/?admin", [$adminAuth]);
$sessCookie = extract_cookie_header($adminLoginHeadersFirst);
$adminCsrf = extract_csrf_token($adminLoginBody);
[, $lockFolderBody] = http_get("$basePrivate/?folder=Paris", ["Cookie: $revealCookie"]);
check('[lock] on a folder\'s page the lock remembers the folder, so locking or unlocking brings you back to it', str_contains($lockFolderBody, 'name="folder" value="Paris"'));
[, , $lockBackHd] = http_post("$basePrivate/?reveal", ['logout' => '1', 'folder' => 'Paris'], ["Cookie: $revealCookie"], false);
check('[lock] ...pressing it there returns to that folder', (bool) array_filter($lockBackHd, fn($h) => stripos($h, 'Location: ./?folder=Paris') === 0));
[, , $loginBackHd] = http_get("$basePrivate/?reveal&folder=Paris", [$revealAuth], false);
check('[lock] ...and unlocking from a folder\'s page returns there too', (bool) array_filter($loginBackHd, fn($h) => stripos($h, 'Location: ./?folder=Paris') === 0));
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
check('...and the hidden item\'s count is real and comes back (revealed viewer\'s page badge)', str_contains((string) http_get("$basePrivate/", ["Cookie: $revealCookie"])[1], 'data-photo="loose1.jpg">' . ico('eye') . ' 1<'));
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
check('the admin shows a red Hidden entry in the sidebar, above the folder list, with the not-a-lock explanation, while hidden items exist', str_contains($adminGridBody, 'class="side-hidden"') && strpos($adminGridBody, 'class="side-hidden"') < strpos($adminGridBody, 'class="admin-folder-nav"') && str_contains($adminGridBody, 'data-tab="hidden"') && str_contains($adminGridBody, "Private isn't locked") && str_contains($adminGridBody, 'by their direct address'));
check('...and the top tabs stay the same four sections (Hidden is not one of them)', (bool) preg_match('#<nav class="admin-tabs".*?</nav>#s', $adminGridBody, $tabNav) && !str_contains($tabNav[0], 'hidden'));
check('...with the tabs in the same bar as the title and Log out (one header row), in that order', (function ($b) { $p1 = strpos($b, '<h1>Gallery Admin</h1>'); $p2 = strpos($b, '<nav class="admin-tabs"'); $p3 = strpos($b, 'class="logout-form"'); return $p1 !== false && $p1 < $p2 && $p2 < $p3 && substr_count(substr($b, 0, $p3), '<header') === 1 && strpos($b, '</header>') > $p3; })($adminGridBody));

// Hidden-items summary: three tiers, not a flat list.
[, $hiddenSummaryBody] = http_get("$basePrivate/?admin&hidden=1", [$adminAuth, "Cookie: $sessCookie"]);
check('the Private tab lists the entirely-hidden folder as a row of its own, under "Private folders", with a one-line account of what opening it would do', (bool) preg_match('#<div class="pv-grp">Private folders</div><details class="pv-row" data-key="Paris">.*?<span class="pv-name">Paris</span><span class="pv-sub">[^<]*public when opened#s', $hiddenSummaryBody) && str_contains($hiddenSummaryBody, '<div class="pv-stat"><b>1</b><span>private folder</span>'));
check('the Private tab lists the individually-hidden photo under its (public) folder, as a thumbnail with a working lock that links to its editor', str_contains($hiddenSummaryBody, 'Public folders with private') && (bool) preg_match('#<a class="pv-img" href="\?photo=loose1\.jpg&amp;edit=1"#', $hiddenSummaryBody) && (bool) preg_match('#<button type="button" class="pv-lock own" data-file="loose1\.jpg" data-bare="loose1\.jpg" data-folder="" data-own="private"#', $hiddenSummaryBody));
check('[private tab] each private folder row offers See them (that folder with the private photos first), Make public and its settings; the old flat lists are gone', (bool) preg_match('#class="pv-lnk" href="\?folder=Paris&amp;sort=[a-z]+(?:\.desc)?\.private">See them in the gallery#', $hiddenSummaryBody) && str_contains($hiddenSummaryBody, 'class="pv-lnk pv-makepub"') && str_contains($hiddenSummaryBody, '<button type="button" class="pv-ic own pv-makepub" data-folder="Paris"') && str_contains($hiddenSummaryBody, 'data-folder="Paris"') && !str_contains($hiddenSummaryBody, 'class="hidden-list"') && str_contains($hiddenSummaryBody, 'id="pt-modal"') && str_contains($hiddenSummaryBody, 'id="pv-overview" data-csrf="'));

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
check('...and the shared sign-in box holds it too (429 for that address, even with the right private-view password), so it can\'t be used to keep guessing the admin password', $revealStillWorksStatus === 429 && http_get("$basePrivate/?reveal")[0] === 200);

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
check('no map preview or Leaflet library loads when ENABLE_MAP is off', !str_contains($mapOffBody, 'id="map-toggle"') && !str_contains($mapOffBody, 'id="map-preview-body"') && !str_contains($mapOffBody, 'cdnjs.cloudflare.com/ajax/libs/leaflet'));

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
    'MAP_CLUSTER_RADIUS_MILES' => '50',   // wide, so Paris's two photos merge (the default is 1 mile)
]);

$portMap = find_free_port();
start_server($fxMap, $portMap);
$baseMap = "http://127.0.0.1:$portMap";

[, $mapRootBody] = http_get("$baseMap/");
check('the gallery root embeds a condensed map preview when ENABLE_MAP is on', str_contains($mapRootBody, 'id="trip-map-preview"'));
check('the root preview links to the full ?map page', str_contains($mapRootBody, 'href="?map"'));
check('the open preview has a link icon (copy link) for the page with that small map open (#map), not the full-screen one', str_contains($mapRootBody, 'id="map-peek-share" data-href="#map"'));
preg_match('#<div class="map-preview-body".*?</section>#s', $mapRootBody, $mapPreviewHtml);
check('the root preview shows a legend with one entry per distinct group', substr_count($mapPreviewHtml[0] ?? '', '<span class="map-legend-item">') === 3);
check('the full map opens over the page: a dialog with its own copy of the map and legend, a close cross, and a button to copy its link', str_contains($mapRootBody, 'id="map-modal"') && str_contains($mapRootBody, 'class="map-modal-close"') && substr_count($mapRootBody, '<span class="map-legend-item">') === 6 && str_contains($mapRootBody, 'id="map-share" data-href="?map"') && str_contains($mapRootBody, 'id="map-full"'));
check('the two map buttons are one joined control (show/hide and full map), not two buttons pushed apart', (bool) preg_match('#id="map-toggle".*?</button>\s*<a href="\?map" class="map-combo-btn" id="map-full"#s', $mapRootBody));

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
check('a standalone item\'s popup link goes to its #group-<slug> anchor on the same page (the map is an overlay on that page, so a bare #... lands right), not a folder page', $standaloneCluster !== null && $standaloneCluster[0]['link'] === '#group-standalone');

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
check('the dates sit under the folder\'s title, and the description is a centered note box (not loose text in the header)', str_contains($fieldsPage, '<p class="folder-dates">June 12-15, 2026</p>') && str_contains($fieldsPage, '<div class="notes">' . "\n" . '    <div class="note-box folder-description"><p>Three weeks,<br>two &lt;script&gt;') && !preg_match('#<header>.*folder-description.*</header>#s', $fieldsPage));
check('the folder\'s page title uses its display name', (bool) preg_match('/<title>Europe 2026 — /', $fieldsPage));
check('the folder\'s Open Graph description uses its description (as plain text, escaped)', str_contains($fieldsPage, 'property="og:description" content="Three weeks, two &lt;script') && !str_contains($fieldsPage, 'content="Three weeks, two <script'));
$mdDesc = "**Bold** and *italic* with a [link](https://example.com) and `{center}` as text\n\nSecond paragraph {left}\n\n- one\n- two\n\n## Heading {right}\n\nBad [link](javascript:alert(1)) and <b>raw</b>";
[, $mdSave] = $depthPost($europeAdminQuery, ['action' => 'save_folder_identity', 'display_name' => 'Europe 2026', 'description' => $mdDesc, 'dates' => 'June 12-15, 2026', 'sort_order' => '2']);
[, $mdPage] = http_get("$baseDepth/?folder=Europe");
preg_match('#<div class="note-box folder-description">(.*?)</div>#s', $mdPage, $mdBox);
$mdHtml = $mdBox[1] ?? '';
check('[description] it accepts the notes\' small Markdown: bold, italic, a link, a list and a heading', str_contains($mdHtml, '<strong>Bold</strong>') && str_contains($mdHtml, '<em>italic</em>') && str_contains($mdHtml, '<a href="https://example.com"') && str_contains($mdHtml, '<ul><li>one</li><li>two</li></ul>') && str_contains($mdHtml, '<h2'));
check('[description] {left}, {center} and {right} align just their own paragraph or heading, and are never shown', str_contains($mdHtml, '<p style="text-align: left;">Second paragraph</p>') && str_contains($mdHtml, '<h2 style="text-align: right;">Heading</h2>') && !str_contains(str_replace('{center}</code>', '', $mdHtml), '{left}') && !str_contains($mdHtml, '{right}'));
check('[description] a directive written in backticks is just text, not acted on', str_contains($mdHtml, '<code>{center}</code>'));
check('[description] the box centres its text by default (a paragraph can override that)', str_contains($mdPage, '.note-box.folder-description { text-align: center; }'));
check('[description] raw HTML and a javascript: link stay inert', !str_contains($mdHtml, '<b>raw</b>') && str_contains($mdHtml, '&lt;b&gt;raw&lt;/b&gt;') && !str_contains($mdHtml, 'href="javascript:'));
[, $mdAdmin] = http_get("$baseDepth/?admin&folder=Europe", $depthHdrs ?? []);
check('[description] the admin keeps the line breaks you typed (it is stored as written, not flattened to one line)', str_contains(read_data_file("$fxDepth/Europe/.gallery-items.php")['description'] ?? '', "\n\nSecond paragraph {left}\n\n- one") );
check('[description] the share preview text is plain: no asterisks, brackets or braces', (bool) preg_match('#property="og:description" content="Bold and italic with a link#', $mdPage) && !preg_match('#property="og:description" content="[^"]*[*\[\]]#', $mdPage));
// put the original text back for the tests that follow
$depthPost($europeAdminQuery, ['action' => 'save_folder_identity', 'display_name' => 'Europe 2026', 'description' => "Three weeks,\n  two <script>alert(1)</script> countries.", 'dates' => 'June 12-15, 2026', 'sort_order' => '2']);

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

// A photo's privacy in three states (private on its own / public / follows its folder), what opening a private folder would do, and
// folder settings that save field by field.
$p3Hdrs = [$depthAuth, "Cookie: $depthCookie", 'X-Requested-With: gallery-fetch'];
$p3Post = function ($query, $fields) use ($baseDepth, &$depthCsrf, $p3Hdrs) {
    [, $body] = http_post("$baseDepth/?admin$query", $fields + ['csrf_token' => $depthCsrf], $p3Hdrs);
    return json_decode($body, true) ?? ['ok' => null, 'text' => $body];
};
$p3Items = function () use ($fxDepth) { return read_data_file("$fxDepth/Europe/France/Paris/.gallery-items.php")['items'] ?? []; };
$p3Own = function ($bare) use ($p3Items) { return ($p3Items()[$bare] ?? [])['restriction'] ?? 'inherit'; };
$p3Root = "$baseDepth/";
$p3Item = function ($file) use ($p3Root, $depthAuth, $depthCookie) { return item_get($p3Root, [$depthAuth, "Cookie: $depthCookie"], $file); };
$p3One = $p3Post($parisAdminQuery, ['action' => 'set_item_flag', 'file' => 'eiffel.jpg', 'flag' => 'private', 'state' => 'private']);
check('[privacy] a photo can be kept private on its own (state=private) inside a private folder', ($p3One['ok'] ?? false) === true && $p3Own('eiffel.jpg') === 'private');
$p3Two = $p3Post($parisAdminQuery, ['action' => 'set_item_flag', 'file' => 'eiffel.jpg', 'flag' => 'private', 'state' => 'public']);
check('[privacy] ...or made public inside it (state=public)', ($p3Two['ok'] ?? false) === true && $p3Own('eiffel.jpg') === 'public');
$p3Three = $p3Post($parisAdminQuery, ['action' => 'set_item_flag', 'file' => 'eiffel.jpg', 'flag' => 'private', 'state' => 'inherit']);
check('[privacy] ...or put back to following its folder (state=inherit): nothing is stored on the photo', ($p3Three['ok'] ?? false) === true && $p3Own('eiffel.jpg') === 'inherit' && !isset($p3Items()['eiffel.jpg']['restriction']));
$p3Bad = $p3Post($parisAdminQuery, ['action' => 'set_item_flag', 'file' => 'eiffel.jpg', 'flag' => 'private', 'state' => 'maybe']);
check('[privacy] an unknown state is refused and changes nothing', ($p3Bad['ok'] ?? null) === false && $p3Own('eiffel.jpg') === 'inherit');
$p3Post($parisAdminQuery, ['action' => 'set_item_flag', 'file' => 'eiffel.jpg', 'flag' => 'private', 'state' => 'private']);
$p3Rec = $p3Item('Europe/France/Paris/eiffel.jpg'); $p3Rec2 = $p3Item('Europe/France/Paris/louvre.jpg');
check('[privacy] the owner\'s item record says what the photo has of its own and whether its folder is private', ($p3Rec['own'] ?? null) === 'private' && ($p3Rec['private'] ?? null) === true && ($p3Rec['folderPrivate'] ?? null) === true && ($p3Rec2['own'] ?? null) === '' && ($p3Rec2['private'] ?? null) === true && ($p3Rec2['folderPrivate'] ?? null) === true);
$p3Bulk = $p3Post($parisAdminQuery, ['action' => 'bulk_item_flag', 'flag' => 'private', 'state' => 'public', 'files' => json_encode(['louvre.jpg', 'eiffel.jpg'])]);
check('[privacy] several photos at once take a state too, and the answer says so', ($p3Bulk['ok'] ?? false) === true && $p3Own('louvre.jpg') === 'public' && $p3Own('eiffel.jpg') === 'public' && str_contains($p3Bulk['text'] ?? '', 'public'));
$p3Bulk2 = $p3Post($parisAdminQuery, ['action' => 'bulk_item_flag', 'flag' => 'private', 'state' => 'inherit', 'files' => json_encode(['louvre.jpg', 'eiffel.jpg'])]);
check('[privacy] ...including back to following the folder', ($p3Bulk2['ok'] ?? false) === true && $p3Own('louvre.jpg') === 'inherit' && $p3Own('eiffel.jpg') === 'inherit' && str_contains($p3Bulk2['text'] ?? '', 'following their folder'));
$p3Old = $p3Post($parisAdminQuery, ['action' => 'set_item_flag', 'file' => 'louvre.jpg', 'flag' => 'private', 'on' => '1']);
$p3Old2 = $p3Own('louvre.jpg');
$p3Post($parisAdminQuery, ['action' => 'set_item_flag', 'file' => 'louvre.jpg', 'flag' => 'private', 'on' => '0']);
check('[privacy] the older on/off request still works: on is kept private; off inside a private folder is public', ($p3Old['ok'] ?? false) === true && $p3Old2 === 'private' && $p3Own('louvre.jpg') === 'public');
$p3Post($parisAdminQuery, ['action' => 'set_item_flag', 'file' => 'louvre.jpg', 'flag' => 'private', 'state' => 'inherit']);
$p3Post($parisAdminQuery, ['action' => 'set_item_flag', 'file' => 'eiffel.jpg', 'flag' => 'private', 'state' => 'private']);
// What opening the folder would do
[, $p3CountsBody] = http_get("$baseDepth/?admin$parisAdminQuery&privcounts=1", [$depthAuth, "Cookie: $depthCookie"]);
$p3Counts = json_decode($p3CountsBody, true)['counts'] ?? [];
check('[privacy] the count of what opening a private folder would do: the photo kept private stays, the one that follows the folder becomes public', ($p3Counts['stay'] ?? null) === 1 && ($p3Counts['open'] ?? null) === 1);
[, $p3EuropeBody] = http_get("$baseDepth/?admin&folder=Europe&privcounts=1", [$depthAuth, "Cookie: $depthCookie"]);
$p3Europe = json_decode($p3EuropeBody, true)['counts'] ?? [];
$p3Jpgs = count(glob("$fxDepth/Europe/*.jpg")) + count(glob("$fxDepth/Europe/*/*.jpg")) + count(glob("$fxDepth/Europe/*/*/*.jpg"));
check('[privacy] ...counted across everything beneath a folder, and a photo inside another private folder (Paris, Germany) stays private', ($p3Europe['stay'] ?? 0) >= 3 && ($p3Europe['open'] ?? 0) >= 2 && ($p3Europe['stay'] ?? 0) + ($p3Europe['open'] ?? 0) === $p3Jpgs);
check('[privacy] ...and only the owner can ask: a visitor gets no counts', !str_contains(http_get("$baseDepth/?admin$parisAdminQuery&privcounts=1")[1], '"counts"'));
$p3Toggle = $p3Post($parisAdminQuery, ['action' => 'toggle_folder_restriction', 'hidden' => '1']);
check('[privacy] switching a folder private answers with the same counts, for the page to show', ($p3Toggle['ok'] ?? false) === true && ($p3Toggle['counts']['stay'] ?? null) === 1 && ($p3Toggle['counts']['open'] ?? null) === 1);
// The owner's pages
[, $p3Page] = http_get("$baseDepth/?folder=" . rawurlencode('Europe/France/Paris'), [$depthAuth, "Cookie: $depthCookie"]);
check('[privacy] on a private folder\'s page the owner sees a banner with the counts, a solid lock on the photo kept private and a dashed "Folder" lock on the one that follows the folder', str_contains($p3Page, 'id="folder-priv-banner"') && str_contains($p3Page, '1 photo will stay private') && str_contains($p3Page, '1 photo becomes public when you open it') && preg_match('#class="priv-toggle is-private" data-file="Europe/France/Paris/eiffel.jpg"#', $p3Page) === 1 && preg_match('#class="priv-toggle is-private is-folder" data-file="Europe/France/Paris/louvre.jpg"#', $p3Page) === 1 && str_contains($p3Page, '<span>Folder</span>'));
check('[privacy] the banner is a button (the whole box presses) that asks whether to make the folder public, and the question is on the page', str_contains($p3Page, 'id="folder-priv-banner" role="button" tabindex="0"') && str_contains($p3Page, 'id="fp-modal"') && str_contains($p3Page, 'id="fp-ok"') && str_contains($p3Page, 'id="fp-cancel"') && str_contains($p3Page, 'Make it public&hellip;'));
check('[privacy] the owner\'s page carries the three states for the script (own, inherited, effective) and the Select bar\'s third button', str_contains($p3Page, '"own":{') && str_contains($p3Page, '"inh":{') && str_contains($p3Page, 'data-select="inherit"') && str_contains($p3Page, 'class="sb-p"') && str_contains($p3Page, 'function lockKind(f)') && str_contains($p3Page, 'function nextOwn(f)'));
[, $p3Visitor] = http_get("$baseDepth/");
check('[privacy] a visitor gets none of it: no banner, no owner state, no Select bar', !str_contains($p3Visitor, 'id="folder-priv-banner"') && !str_contains($p3Visitor, 'id="fp-modal"') && !str_contains($p3Visitor, 'data-select="inherit"') && str_contains($p3Visitor, 'const ownerState = null;') && !str_contains($p3Visitor, 'class="priv-toggle'));
[, $p3Public] = http_get("$baseDepth/?folder=" . rawurlencode('Europe/France/Lyon'), [$depthAuth, "Cookie: $depthCookie"]);
check('[privacy] a public folder shows no banner and no dashed locks', !str_contains($p3Public, 'id="folder-priv-banner"') && str_contains($p3Public, 'class="priv-toggle"') && preg_match('#class="priv-toggle[^"]*is-folder#', $p3Public) === 0);
// The owner's mark on a folder's card and button, and the count of what is private inside
[, $p3France] = http_get("$baseDepth/?folder=" . rawurlencode('Europe/France'), [$depthAuth, "Cookie: $depthCookie"]);
check('[privacy] on the main page the owner sees a private folder\'s card marked with a solid red pill, and no mark on a public one', (bool) preg_match('#id="group-paris".*?<button type="button" class="folder-priv own" data-folder="Europe/France/Paris"[^>]*>.*?Private</button>#s', $p3France) && !preg_match('#id="group-lyon"(?:(?!id="group-).)*class="folder-priv#s', $p3France));
check('[privacy] ...with the count of what stays private added to the card\'s own count line, a link that opens the folder with those first (the order kept, "private first" added)', (bool) preg_match('#id="group-paris".*?<a class="fc-priv" href="\?folder=Europe%2FFrance%2FParis&amp;sort=[a-z]+(?:\.desc)?\.private"[^>]*>\(1 stays private<svg class="ico"[^>]*><use href="\#i-chev-right"></use></svg>\)</a>#s', $p3France));
check('[privacy] ...the red pill is a button that asks whether to make the folder public (the question is on the page; a visitor gets neither)', str_contains($p3France, 'id="fp-modal"') && str_contains($p3France, 'press to make it public') && !str_contains(http_get("$baseDepth/?folder=" . rawurlencode('Europe/France'))[1], 'id="fp-modal"'));
check('[privacy] ...and the count line still opens the folder (the number of photos is a link to it too)', (bool) preg_match('#class="fc-main" href="\?folder=Europe%2FFrance%2FParis">\d+ photos?#', $p3France));
[, $p3FranceVisitor] = http_get("$baseDepth/?folder=" . rawurlencode('Europe/France'));
check('[privacy] a visitor sees no pill, no count and no hidden folder\'s card on the same page', !str_contains($p3FranceVisitor, 'class="folder-priv') && !str_contains($p3FranceVisitor, 'class="fc-priv"') && !str_contains($p3FranceVisitor, 'id="group-paris"'));
[, $p3LyonVisitor] = http_get("$baseDepth/?folder=" . rawurlencode('Europe/France/Lyon'));
check('[privacy] the owner\'s folder buttons carry a lock in each private folder (the current one too); a visitor\'s row has none', (bool) preg_match('#<nav class="folder-pills".*?<a [^>]*class="current priv"[^>]*>.*?<svg class="ico".*?Paris</a>#s', $p3Page) && !preg_match('#<nav class="folder-pills"[^>]*>.*?class="[^"]*priv#s', $p3LyonVisitor));
[, $p3PrivFirst] = http_get("$baseDepth/?folder=" . rawurlencode('Europe/France/Paris') . '&sort=name.private', [$depthAuth, "Cookie: $depthCookie"]);
check('[privacy] "private first" puts the photos kept private on their own first, even in a private folder where everything is hidden', preg_match_all('#class="card[^"]*"[^>]*data-file="([^"]+)"#', $p3PrivFirst, $p3Order) && ($p3Order[1][0] ?? '') === 'Europe/France/Paris/eiffel.jpg');
// Folder settings that save field by field
[, $p3Settings] = http_get("$baseDepth/?admin$parisAdminQuery", [$depthAuth, "Cookie: $depthCookie"]);
check('[settings] the folder settings page: a form that saves by itself, a Privacy section with the folder switch, locations and the map under it, and no separate Map heading', str_contains($p3Settings, 'class="identity-form"') && str_contains($p3Settings, 'class="priv-folder-form"') && str_contains($p3Settings, 'id="priv-modal"') && str_contains($p3Settings, 'id="map-under"') && substr_count($p3Settings, '<h3 class="subhead">Privacy</h3>') === 1 && !str_contains($p3Settings, '<h3 class="subhead">Map</h3>'));
check('[settings] ...each field has its own save note, and the Save buttons are only for a browser without JavaScript', substr_count($p3Settings, 'class="rowstate"') >= 8 && preg_match('#<div class="nojs-only"><button type="submit">Save folder settings</button></div>#', $p3Settings) === 1);
$p3Hash = preg_match('/name="loaded_hash" value="([0-9a-f]{32})"/', $p3Settings, $m3) ? $m3[1] : '';
$p3Desc = $p3Post($parisAdminQuery, ['action' => 'save_folder_identity', 'description' => 'Typed on its own', 'loaded_hash' => $p3Hash]);
$p3Data = read_data_file("$fxDepth/Europe/France/Paris/.gallery-items.php");
check('[settings] saving one field answers JSON with the record\'s new fingerprint and touches nothing else (the display name and position stay)', ($p3Desc['ok'] ?? false) === true && strlen((string) ($p3Desc['hash'] ?? '')) === 32 && $p3Desc['hash'] !== $p3Hash && ($p3Data['description'] ?? '') === 'Typed on its own' && array_key_exists('displayName', $p3Data) && ($p3Data['restriction'] ?? '') === 'private');
$p3Before = $p3Data['displayName'] ?? null;
$p3Next = $p3Post($parisAdminQuery, ['action' => 'save_folder_identity', 'dates' => 'May 2026', 'loaded_hash' => $p3Desc['hash']]);
$p3Data2 = read_data_file("$fxDepth/Europe/France/Paris/.gallery-items.php");
check('[settings] the next save carries the new fingerprint, and a field that is not sent (the display name) is not wiped', ($p3Next['ok'] ?? false) === true && ($p3Data2['dates'] ?? '') === 'May 2026' && ($p3Data2['displayName'] ?? null) === $p3Before && ($p3Data2['description'] ?? '') === 'Typed on its own');
$p3Stale = $p3Post($parisAdminQuery, ['action' => 'save_folder_identity', 'dates' => 'Another time', 'loaded_hash' => $p3Hash]);
check('[settings] a save made from an out-of-date page (changed in another tab) is refused with JSON saying so, and nothing is overwritten', ($p3Stale['ok'] ?? null) === false && ($p3Stale['stale'] ?? null) === true && (read_data_file("$fxDepth/Europe/France/Paris/.gallery-items.php")['dates'] ?? '') === 'May 2026');
$p3Sort = $p3Post($parisAdminQuery, ['action' => 'save_folder_identity', 'photo_sort_order' => 'name', 'photo_sort_dir' => 'desc', 'photo_sort_first' => '', 'photo_sort_private' => 'off', 'photo_sort_video' => 'off', 'photo_sort_located' => 'off', 'photo_sort_priority' => 'private,video,located', 'loaded_hash' => $p3Next['hash']]);
$p3Data3 = read_data_file("$fxDepth/Europe/France/Paris/.gallery-items.php");
check('[settings] the order of the photos saves as a group of its own, leaving the other fields alone', ($p3Sort['ok'] ?? false) === true && ($p3Data3['photoSort']['order'] ?? '') === 'name' && ($p3Data3['photoSort']['desc'] ?? null) === true && ($p3Data3['dates'] ?? '') === 'May 2026');
// (put things back as they were for the tests that follow)
$p3Post($parisAdminQuery, ['action' => 'set_item_flag', 'file' => 'eiffel.jpg', 'flag' => 'private', 'state' => 'inherit']);
$p3Post($parisAdminQuery, ['action' => 'save_folder_identity', 'description' => '', 'dates' => '', 'photo_sort_order' => '', 'photo_sort_dir' => '', 'photo_sort_first' => '']);

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
$hashFor = function ($ignored, $file) use ($baseDepth, $depthAuth, $depthCookie) {   // (the editor in the viewer asks for a photo's record; the fingerprint is in it)
    $j = json_decode(http_get("$baseDepth/?admin&item=" . rawurlencode('Europe/France/Paris/' . $file), [$depthAuth, "Cookie: $depthCookie"])[1] ?? '', true);
    return is_array($j) && preg_match('/^[a-f0-9]{32}$/', (string) ($j['hash'] ?? '')) ? $j['hash'] : null;
};
[, $tabA] = http_get("$baseDepth/?admin$parisAdminQuery", [$depthAuth, "Cookie: $depthCookie"]);
$eiffelHashA = $hashFor($tabA, 'eiffel.jpg');
$louvreHashA = $hashFor($tabA, 'louvre.jpg');
check('a photo\'s record comes with its own fingerprint (one per photo, for the stale-edit check)', $eiffelHashA !== null && $louvreHashA !== null && $eiffelHashA !== $louvreHashA);
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
foreach (['Start here', 'Formatting', 'Directives', 'Attaching photos to a paragraph', 'Where a directive applies', 'Notes and folders', 'Showing a directive as text', "If something doesn't work"] as $section) {
    $sections[] = str_contains(str_replace('&#039;', "'", $ref), $section);
}
check('[notes reference] it has every section: start here, formatting, the directives, attaching photos, where a directive applies, folders, showing one as text, and troubleshooting', !in_array(false, $sections, true));
check('[notes reference] it is split into six tabs (start, formatting, directives, photos, folders, fixing), each with a panel, and starts with a complete example', substr_count($ref, 'role="tab"') === 6 && substr_count($ref, '<section class="ref-panel"') === 6 && str_contains($ref, 'Our week in Lisbon.'));
check('[notes reference] it has a "Notes and folders" section saying what works with folders (a mention, {group:}, {sold}, {color:}) and that {photos:} reaches only top-level photos', str_contains($ref, 'Notes and folders') && str_contains($ref, 'directly in the folder the notes are in') && str_contains($ref, 'A <em>cover card</em> takes that paragraph'));
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
file_put_contents("$fxFN/Paris/n.md", "{gallery}\n\nCheck out these pictures from around the city!{color: gold}\n");
usleep(300000);
[, $fnParisAccent] = http_get("http://127.0.0.1:$portFN/?folder=Paris");
check('[folder notes] a {color:} in a paragraph that mentions no photo or folder accents the paragraph itself (it used to do nothing)', str_contains($fnParisAccent, '<p class="accent-note" style="--bar: gold;">Check out these pictures from around the city!</p>') && !str_contains($fnParisAccent, '{color'));
file_put_contents("$fxFN/Paris/n.md", "{gallery}\n\nCheck out eiffel {photos: eiffel.jpg}{color: gold}\n");
usleep(300000);
[, $fnParisBoth] = http_get("http://127.0.0.1:$portFN/?folder=Paris");
check('[folder notes] a {color:} that also colours a photo puts the same bar beside the paragraph, so the two match', str_contains($fnParisBoth, '<p id="item-eiffel-jpg" class="accent-note" style="--bar: gold;">') && str_contains($fnParisBoth, 'class="card ringed" id="group-eiffel-jpg" style="--ring: gold;"'));
file_put_contents("$fxFN/Paris/n.md", "{gallery}\n\nNice view {photos: eiffel.jpg} {color: gold}\n");
usleep(300000);
[, $fnParisPlain] = http_get("http://127.0.0.1:$portFN/?folder=Paris");
check('[folder notes] a bare {photos:} in a folder note keeps the photo as its own card and colours it', (bool) preg_match('/<div class="card ringed" id="group-eiffel-jpg" style="--ring: gold;"/', $fnParisPlain));
file_put_contents("$fxFN/n.md", "{gallery}\n\nThe Paris leg {sold}\n\nTop {photos: top.jpg} {group: Top Box}\n");
usleep(300000);
[, $fnRoot2] = http_get("http://127.0.0.1:$portFN/");
check('[folder notes] a paragraph that mentions a folder marks its cover card sold (ribbon and class)', (bool) preg_match('/<div class="folder-cover sold" id="group-paris"[^>]*>\s*<a class="folder-cover-link"[^>]*><span class="sold-badge-corner">SOLD<\/span>/', $fnRoot2));

check('[notes reference] each of the five directives is explained in full next to its insert button', str_contains($ref, 'data-snippet="{group: Name}"') && str_contains($ref, 'data-snippet="{photos: a.jpg, b.jpg}"') && str_contains($ref, 'data-snippet="{color: #ff0000}"') && str_contains($ref, 'data-snippet="{sold}"') && str_contains($ref, 'data-snippet="{reserved}"') && str_contains($ref, "whatever they're called") && str_contains($ref, 'red SOLD ribbon') && str_contains($ref, 'orange tag') && str_contains($ref, '{held}'));
check('[notes reference] it explains the formatting that really works (bold, italic, underline, strikethrough, code, links, lists, quotes, code blocks) and that HTML is never run', str_contains($ref, '**bold**') && str_contains($ref, '__underline__') && str_contains($ref, '~~strikethrough~~') && str_contains($ref, '[words](https://example.com)') && str_contains($ref, '&gt; a quote') && str_contains($ref, 'never run'));
check('[notes reference] it explains the rules that trip people up: {gallery} on the first line, the page-title heading, a directive only affecting its own paragraph, and the blank-line mistake with its fix', str_contains($ref, 'first line must be exactly') && str_contains($ref, 'page title') && str_contains($ref, 'its own paragraph') && str_contains($ref, 'Take a look at my rack') && str_contains($ref, 'Remove the blank line'));
check('[notes reference] the Insert buttons exist only in the reference (five of them), not repeated in each editor', substr_count($ref, 'class="admin-insert-btn"') === 5 && substr_count(str_replace($ref, '', $depthAdminBody), 'class="admin-insert-btn"') === 0);
check('the page script no longer folds the notes reference away on a phone: it is shown in full everywhere', !str_contains($depthAdminBody, ".admin-reference") && str_contains($depthAdminBody, 'ref.classList.add(\'js-ref\')'));
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
    check("[$upgradeMode] the old per-photo counts are imported, not zeroed", str_contains($upBody, 'data-photo="old-photo.jpg">' . ico('eye') . ' 7<') && str_contains($upBody, 'data-photo="other.jpg">' . ico('eye') . ' 3<'));
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
        check("[real 1.1.1, $v1Flavour] after swapping in 2.0.0, both per-photo counts carry over exactly (5 and 2)", str_contains($v2Page, 'data-photo="a.jpg">' . ico('eye') . ' 5<') && str_contains($v2Page, 'data-photo="b.jpg">' . ico('eye') . ' 2<'));
        check("[real 1.1.1, $v1Flavour] ...and the sitewide total carries over (4 loads under 1.1.1, plus this one visit)", str_contains($v2Page, '<span class="page-visits">5 visits</span>'));
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
$gpsCard = function ($body, $name) use ($portGps) {   // (the photo's record, as the editor in the viewer asks for it: a place read from its GPS is `auto` until it is kept)
    return json_encode(item_get("http://127.0.0.1:$portGps", ['Authorization: Basic ' . base64_encode('admin:test-admin-pw-123')], $name));
};
check('a JPEG\'s EXIF GPS pre-fills the admin\'s location fields (N/E)', str_contains($gpsCard($gpsAdmin, 'north-east.jpg'), '48.8584') && str_contains($gpsCard($gpsAdmin, 'north-east.jpg'), '2.2945'));
check('southern and western hemispheres come out negative', str_contains($gpsCard($gpsAdmin, 'south-west.jpg'), '-33.85') && str_contains($gpsCard($gpsAdmin, 'south-west.jpg'), '-151.2'));
check('an iPhone-style (big-endian EXIF, extra tags) JPEG pre-fills too: San Francisco is 37.7749, -122.4194 (west is negative)', str_contains($gpsCard($gpsAdmin, 'iphone.jpg'), '37.7749') && str_contains($gpsCard($gpsAdmin, 'iphone.jpg'), '-122.4194'));
check('a JPEG with no GPS block gets no pre-filled location', str_contains($gpsCard($gpsAdmin, 'no-gps.jpg'), '"auto":null') && str_contains($gpsCard($gpsAdmin, 'no-gps.jpg'), '"lat":""'));
if ($heicOk) {
    check('a HEIC\'s EXIF GPS pre-fills the admin\'s location fields too', str_contains($gpsCard($gpsAdmin, 'phone.heic'), '48.8584') && str_contains($gpsCard($gpsAdmin, 'phone.heic'), '2.2945'));
} else {
    echo "  skip  HEIC GPS auto-fill (this PHP's Imagick can't write HEIC to build a fixture)\n";
}

// The editor in the viewer saves as it is typed: a caption saved with keep_loc leaves a place that was only read from the photo's GPS unsaved.
[, , $gpsHd0] = http_get("http://127.0.0.1:$portGps/?admin", ['Authorization: Basic ' . base64_encode('admin:test-admin-pw-123')]);
$gpsRootHdrs = ['Authorization: Basic ' . base64_encode('admin:test-admin-pw-123'), 'Cookie: ' . extract_cookie_header($gpsHd0)];
$gpsRoot = "http://127.0.0.1:$portGps";
$kj0 = item_get($gpsRoot, $gpsRootHdrs, 'north-east.jpg');
[, $keepBody] = http_post("$gpsRoot/?admin", ['csrf_token' => admin_token($gpsRoot, $gpsRootHdrs), 'action' => 'save_item', 'file' => 'north-east.jpg', 'caption' => 'Typed while the place stays unsaved', 'web' => '', 'lat' => '', 'lng' => '', 'loc_label' => '', 'loaded_hash' => $kj0['hash'] ?? '', 'keep_loc' => '1'], array_merge($gpsRootHdrs, ['X-Requested-With: gallery-fetch']));
$keepJ = json_decode($keepBody, true) ?: []; $kj1 = item_get($gpsRoot, $gpsRootHdrs, 'north-east.jpg');
check('[editor] saving a caption with keep_loc answers JSON with the photo\'s new fingerprint, and leaves a place read from the GPS unsaved (still offered, not stored)', ($keepJ['ok'] ?? false) === true && ($keepJ['hash'] ?? '') === ($kj1['hash'] ?? 'x') && ($kj1['caption'] ?? '') === 'Typed while the place stays unsaved' && ($kj1['lat'] ?? 'x') === '' && abs((float) ($kj1['auto']['lat'] ?? 0) - 48.8584) < 0.0001);
[, $staleBody] = http_post("$gpsRoot/?admin", ['csrf_token' => admin_token($gpsRoot, $gpsRootHdrs), 'action' => 'save_item', 'file' => 'north-east.jpg', 'caption' => 'From a stale editor', 'lat' => '', 'lng' => '', 'loaded_hash' => $kj0['hash'] ?? '', 'keep_loc' => '1'], array_merge($gpsRootHdrs, ['X-Requested-With: gallery-fetch']));
$staleJ = json_decode($staleBody, true) ?: [];
check('[editor] an editor that is out of date (the record changed in another tab) is refused with JSON saying so, and nothing is overwritten', ($staleJ['ok'] ?? null) === false && ($staleJ['stale'] ?? null) === true && (item_get($gpsRoot, $gpsRootHdrs, 'north-east.jpg')['caption'] ?? '') === 'Typed while the place stays unsaved');
[, $keepBody2] = http_post("$gpsRoot/?admin", ['csrf_token' => admin_token($gpsRoot, $gpsRootHdrs), 'action' => 'save_item', 'file' => 'north-east.jpg', 'caption' => 'Typed while the place stays unsaved', 'web' => '', 'lat' => '48.8584', 'lng' => '2.2945', 'loc_label' => 'Kept', 'loaded_hash' => $keepJ['hash'] ?? '', 'keep_loc' => '0'], array_merge($gpsRootHdrs, ['X-Requested-With: gallery-fetch']));
$kj2 = item_get($gpsRoot, $gpsRootHdrs, 'north-east.jpg');
check('[editor] choosing to keep the place (no keep_loc) stores it with its label, and the photo then has nothing left to offer', (json_decode($keepBody2, true)['ok'] ?? false) === true && abs((float) ($kj2['lat'] ?? 0) - 48.8584) < 0.0001 && ($kj2['label'] ?? '') === 'Kept' && array_key_exists('auto', $kj2) && $kj2['auto'] === null);

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
$gpsRomeFile = fn ($page, $file) => json_encode(item_get($baseGps, $gpsHdrs, 'Rome/' . $file));
check('[gps import] before the switch: three photos have GPS, the admin warns and nothing is public', str_contains($gAdmin, '3 photos here have GPS in the file') && !str_contains(http_get("$baseGps/")[1], 'trip-map-preview'));
// The owner opts one photo out (hotel) and clears another (cleared) *before* turning the folder on.
$r = flag_post($romeUrl, $gAdmin, $gpsHdrs, 'location', false, ['hotel.jpg']);
check('[gps import] switching "Show its location" off for a photo saves, even while the folder is off (coordinates are kept, just never shown, whatever the folder later does)', ($r['ok'] ?? null) === true && (read_data_file("$fxGps/Rome/.gallery-items.php")['items']['hotel.jpg']['locationVisibility'] ?? null) === 'hide');
[, $gAdmin] = http_get($romeUrl, $gpsHdrs);
[, $r] = item_save($romeUrl, $gpsHdrs, 'cleared.jpg', ['lat' => '', 'lng' => '', 'loc_label' => '']);
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
check('[gps import] re-opening the folder does not bring a deliberately cleared location back', str_contains($gpsRomeFile($gAdmin, 'cleared.jpg'), '"lat":""') && str_contains($gpsRomeFile($gAdmin, 'cleared.jpg'), '"auto":null'));
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
check('[admin reveal] ...and the admin lists that hidden photo on its Private tab (linking to its editor), and its record says private', str_contains($arAdminPage, 'data-tab="hidden"') && str_contains($arAdminPage, 'Paris/hidden.jpg') && str_contains($arAdminPage, 'href="?photo=Paris%2Fhidden.jpg&amp;edit=1"') && (item_get($baseAr, ["Cookie: $arCookie"], 'Paris/hidden.jpg')['private'] ?? null) === true);
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
[, $arPwBody, $arPwHd] = http_post("$baseAr/?admin", ['csrf_token' => $arF['csrf_token'], 'action' => 'save_site_settings', 'config_hash' => $arF['config_hash'], 'pw_admin' => 'a-brand-new-admin-pw-1', 'pw_admin2' => 'a-brand-new-admin-pw-1'], ["Cookie: $arC2", 'X-Requested-With: gallery-fetch'], false);
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
[, $rMismatch] = http_post("$baseSetup/?admin", ['setup_password' => 'a-good-long-password-1', 'setup_password2' => 'another-long-password']);
[, $rShort] = http_post("$baseSetup/?admin", ['setup_password' => 'short', 'setup_password2' => 'short']);
check('[setup] two passwords that differ, or one under 8 characters, or one without a letter and a number, are refused with the reason and nothing is written', (str_contains($rMismatch, "don't match") || str_contains($rMismatch, 'don&#039;t match')) && str_contains($rShort, 'at least 8 characters') && !file_exists($setupCfg));
// A settings file that exists but isn't valid is never overwritten.
file_put_contents($setupCfg, $cfgGuard . "{\"ENABLE_VIDEO\": true,\n");
$brokenBytes = (string) file_get_contents($setupCfg);
[, $rBroken] = http_post("$baseSetup/?admin", ['setup_password' => 'a-good-long-password-1', 'setup_password2' => 'a-good-long-password-1']);
check('[setup] a settings file that is not valid is never overwritten: it says so and leaves the file exactly as it was', str_contains($rBroken, 'isn&#039;t valid') && (string) file_get_contents($setupCfg) === $brokenBytes);
// Settings that were already there are kept when the password is added.
file_put_contents($setupCfg, cfg_text(['ENABLE_VIDEO' => true, 'TRACK_VIEWS' => true]));
$claimPw = "my 'first' admin password 1 // with, odd } chars";
[$cst, , $chd] = http_post("$baseSetup/?admin", ['setup_password' => $claimPw, 'setup_password2' => $claimPw], [], false);
$claimLoc = ''; foreach ($chd as $h) if (stripos($h, 'Location:') === 0) $claimLoc = trim(substr($h, 9));
$claimCookie = extract_cookie_header($chd);
check('[setup] choosing a password redirects into the admin\'s Site settings, already signed in (a session cookie)', $cst === 303 && $claimLoc === '?admin#site' && str_contains($claimCookie, 'gallery_admin_'));
$claimCfg = cfg_read($setupCfg); ksort($claimCfg);
check('[setup] the password is stored in the settings file next to the settings that were already there, and nowhere else', pw_stored_ok($claimCfg['ADMIN_PASSWORD'] ?? null, $claimPw) && !str_contains((string) file_get_contents($setupCfg), 'first') && array_diff_key($claimCfg, ['ADMIN_PASSWORD' => 1]) === ['ENABLE_VIDEO' => true, 'TRACK_VIEWS' => true] && (fileperms($setupCfg) & 0o077) === 0 && str_starts_with((string) file_get_contents($setupCfg), $cfgGuard));
[, $claimedPage] = http_get("$baseSetup/?admin", ["Cookie: $claimCookie"]);
check('[setup] with that session the admin opens with no further password, and says it is set and where it is kept', str_contains($claimedPage, 'Gallery Admin') && str_contains($claimedPage, 'Your admin password is set'));
check('[setup] the .gallery-setup file has done its job and is removed', !file_exists("$fxSetup/.gallery-setup"));
// Closed for good.
touch($setupIndex, time());
file_put_contents("$fxSetup/.gallery-setup", '');
[, $loginAgain] = http_get("$baseSetup/?admin");
$beforeSecond = (string) file_get_contents($setupCfg);
[, $second] = http_post("$baseSetup/?admin", ['setup_password' => 'an-attackers-password-1', 'setup_password2' => 'an-attackers-password-1']);
check('[setup] once a password exists, setup never appears again, even with a fresh index.php and a .gallery-setup file: it is the login form', str_contains($loginAgain, 'name="access_code"') && !str_contains($loginAgain, 'name="setup_password"'));
check('[setup] ...and a second visitor posting a new password changes nothing', !str_contains($second, 'Your admin password is set') && (string) file_get_contents($setupCfg) === $beforeSecond && pw_stored_ok(cfg_read($setupCfg)['ADMIN_PASSWORD'] ?? null, $claimPw));
check('[setup] ...the chosen password (with quotes, slashes, a comma and a brace in it) logs in through the form', http_post("$baseSetup/?admin", ['login_password' => $claimPw], [], false)[0] === 303 && http_post("$baseSetup/?admin", ['login_password' => 'an-attackers-password-1'], [], false)[0] === 200);
unlink("$fxSetup/.gallery-setup");
// An unwritable folder: say so and hand over the exact file.
$fxSetupRo = new_fixture_dir('setup-ro');
make_jpeg("$fxSetupRo/a.jpg", 60, 60, 1, 2, 3);
copy(__DIR__ . '/../index.php', "$fxSetupRo/index.php");
$portSetupRo = find_free_port(); start_server($fxSetupRo, $portSetupRo);
touch("$fxSetupRo/index.php", time());
chmod($fxSetupRo, 0555);
[, $roSetup] = http_post("http://127.0.0.1:$portSetupRo/?admin", ['setup_password' => 'read-only-folder-password-1', 'setup_password2' => 'read-only-folder-password-1']);
chmod($fxSetupRo, 0775);
check('[setup] when the folder can\'t be written it says so, saves nothing, and shows the exact settings file to create by hand', str_contains($roSetup, 'isn&#039;t writable') && str_contains($roSetup, '&quot;ADMIN_PASSWORD&quot;: &quot;$2y$') && !str_contains($roSetup, 'read-only-folder-password-1') && !file_exists("$fxSetupRo/.gallery-config.php"));

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
check('[settings file] a .gallery-config.php beside index.php overrides the defaults (video shown, folders on, admin exists, a lock beside the title leads to the sign-in)', str_contains($h1, 'alt="clip.mp4"') && str_contains($h1, 'id="group-sub"') && str_contains($h1, 'class="map-combo-btn lock-link" href="?reveal"') && http_get("$baseCfg/?admin")[0] === 200);
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

echo "== note colours reach the map pins ==\n";
$fxPin = new_fixture_dir('pincolor');
mkdir("$fxPin/Paris"); mkdir("$fxPin/Rome");
make_jpeg("$fxPin/Paris/a.jpg", 60, 60, 10, 10, 10);
make_jpeg("$fxPin/Rome/b.jpg", 60, 60, 20, 20, 20);
write_data_file("$fxPin/Paris/.gallery-items.php", ['items' => ['a.jpg' => ['location' => ['lat' => 48.85, 'lng' => 2.35, 'label' => ''], 'locationVisibility' => 'show']], 'groups' => [], 'locationEnabled' => true]);
write_data_file("$fxPin/Rome/.gallery-items.php", ['items' => ['b.jpg' => ['location' => ['lat' => 41.9, 'lng' => 12.5, 'label' => ''], 'locationVisibility' => 'show']], 'groups' => [], 'locationEnabled' => true]);
file_put_contents("$fxPin/n.md", "{gallery}\n\nThe Paris leg {color: #ff0000}\n\nRome was hot. {sold}\n");
write_patched_index("$fxPin/index.php", $indexContents, ['AUTO_GROUP_BY_FOLDER' => 'true', 'ENABLE_PHOTO_LOCATION' => 'true', 'ENABLE_MAP' => 'true']);
$portPin = find_free_port(); start_server($fxPin, $portPin);
$pinCols = function ($body) { preg_match_all('/"color":"(#[0-9a-f]+|[a-z]+)"/i', $body, $m); return array_values(array_unique($m[1])); };
[, $pinHome] = http_get("http://127.0.0.1:$portPin/");
[, $pinPage] = http_get("http://127.0.0.1:$portPin/?map");
check('[pin colours] a note\'s {color:} on a folder colours that folder\'s pins on the page map, and {sold} turns its pins red', in_array('#ff0000', $pinCols($pinHome), true) && in_array('#e5484d', $pinCols($pinHome), true));
check('[pin colours] ...and the full map (the page with ?map, overlay open) agrees, with the legend using the same colours', in_array('#ff0000', $pinCols($pinPage), true) && in_array('#e5484d', $pinCols($pinPage), true) && str_contains($pinPage, 'map-legend-swatch" style="background:#ff0000;"') && str_contains($pinPage, 'id="trip-map-modal"'));
check('[page bar] the bar above the photos holds the map buttons on the left and, on the right, the gallery icon that leads to ?about; there is no footer', (bool) preg_match('#<div class="map-preview-heading">\\s*<span class="map-combo">.*?</span>\\s*<span class="page-tools">.*?<span class="map-combo"><a class="map-combo-btn page-about" href="\\?about" title="About this gallery software"[^>]*>' . preg_quote(ico('gallery'), '#') . '</a>#s', $pinHome) && !str_contains($pinHome, '<footer'));
check('[pin colours] ?map is the gallery page itself with the map overlay set to open, previewing as the map (title, description and the map icon), and exactly one document', str_contains($pinPage, 'class="gallery') && str_contains($pinPage, 'if (true) show();') && str_contains($pinPage, '<meta property="og:title" content="') && str_contains($pinPage, ' — Map">') && str_contains($pinPage, 'og:image" content="http://127.0.0.1:' . $portPin . '/?mapicon"') && str_contains($pinPage, '2 locations plotted') && substr_count($pinPage, '<!DOCTYPE html>') === 1 && str_contains($pinHome, 'if (false) show();'));
check('[cover size] a page of nothing but two folders gets the larger-card layout (covers-2)', str_contains($pinHome, '<div class="gallery covers-2">') && str_contains($pinHome, '.gallery.covers-2, .map-preview.covers-2 { max-width: 1106px; }') && str_contains($pinHome, '<section class="map-preview covers-2"') && str_contains($pinHome, '1200w'));
check('[cover size] a page that also has a loose photo keeps the normal grid', str_contains($fnRoot, '<div class="gallery">') && !str_contains($fnRoot, 'class="gallery covers-'));
file_put_contents("$fxPin/n.md", "{gallery}\n\nNothing coloured here.\n");
usleep(300000);
[, $pinPlain] = http_get("http://127.0.0.1:$portPin/?map");
check('[pin colours] with no colour in the notes the pins keep their folder colours', !in_array('#ff0000', $pinCols($pinPlain), true) && !in_array('#e5484d', $pinCols($pinPlain), true) && count($pinCols($pinPlain)) === 2);

echo "== private switches, bulk private, password rules ==\n";
$fxFl = new_fixture_dir('flags');
mkdir("$fxFl/Trip");
foreach (['a', 'b', 'c', 'd'] as $i => $n) make_jpeg("$fxFl/Trip/$n.jpg", 60, 60, 10 * $i, 10, 10);
write_patched_index("$fxFl/index.php", $indexContents, ['AUTO_GROUP_BY_FOLDER' => 'true', 'ENABLE_PHOTO_LOCATION' => 'true']);
file_put_contents("$fxFl/.gallery-config.php", cfg_text(['ADMIN_PASSWORD' => 'flag-admin-pw-1']));
$portFl = find_free_port(); start_server($fxFl, $portFl); $baseFl = "http://127.0.0.1:$portFl";
$flAuth = 'Authorization: Basic ' . base64_encode('x:flag-admin-pw-1');
[, $flPage, $flHd] = http_get("$baseFl/?admin&folder=Trip", [$flAuth]);
$flHdrs = [$flAuth, 'Cookie: ' . extract_cookie_header($flHd)];
$flItems = fn () => read_data_file("$fxFl/Trip/.gallery-items.php")['items'] ?? [];
[, $flOwner] = http_get("$baseFl/?folder=Trip", $flHdrs);
check('[flags] the pencil\'s editor has Private and Location switches that save themselves (the same save as the lock), and no visibility dropdowns', str_contains($flOwner, 'id="lbe-private"') && str_contains($flOwner, 'id="lbe-loc"') && !str_contains($flOwner, 'name="restriction"') && !str_contains($flOwner, 'name="loc_visibility"') && !str_contains($flPage, 'class="instant-form flag-form"'));
check('[flags] the gallery\'s Select bar has Select all, Clear, Make private, Make public and Done; the admin page has no thumbnail grid or Select bar of its own any more', str_contains($flOwner, 'data-select="private"') && str_contains($flOwner, 'data-select="public"') && str_contains($flOwner, 'data-select="all"') && str_contains($flOwner, 'data-select="done"') && !str_contains($flPage, 'id="bulk-start"') && !str_contains($flPage, 'class="thumb-grid"'));
$r = flag_post("$baseFl/?admin&folder=Trip", $flPage, $flHdrs, 'private', true, ['a.jpg']);
check('[flags] switching Private on for one photo answers JSON, stores it on the photo, and hides it from visitors', ($r['ok'] ?? null) === true && ($flItems()['a.jpg']['restriction'] ?? null) === 'private' && !str_contains(http_get("$baseFl/?folder=Trip")[1], 'alt="Trip/a.jpg"') && str_contains(http_get("$baseFl/?folder=Trip")[1], 'alt="Trip/b.jpg"'));
$r = flag_post("$baseFl/?admin&folder=Trip", $flPage, $flHdrs, 'private', false, ['a.jpg']);
check('[flags] switching it off again leaves the photo with no setting of its own (it follows its folder)', ($r['ok'] ?? null) === true && !isset($flItems()['a.jpg']['restriction']) && str_contains(http_get("$baseFl/?folder=Trip")[1], 'alt="Trip/a.jpg"'));
$r = flag_post("$baseFl/?admin&folder=Trip", $flPage, $flHdrs, 'private', true, ['b.jpg', 'c.jpg', 'd.jpg']);
$pubFl = http_get("$baseFl/?folder=Trip")[1];
check('[flags] choosing three photos and making them private is one request: all three are private, the fourth is not, and it says how many', ($r['ok'] ?? null) === true && str_contains((string) $r['text'], '3 photos are now private') && ($flItems()['c.jpg']['restriction'] ?? null) === 'private' && str_contains($pubFl, 'alt="Trip/a.jpg"') && !str_contains($pubFl, 'alt="Trip/b.jpg"') && !str_contains($pubFl, 'alt="Trip/d.jpg"'));
$before = $flItems();
$r = flag_post("$baseFl/?admin&folder=Trip", $flPage, $flHdrs, 'private', false, ['b.jpg', 'nope.jpg']);
check('[flags] a bulk request naming a file that is not there is refused as a whole, and changes nothing', ($r['ok'] ?? null) === false && $flItems() === $before);
check('[flags] choosing none, or too many, is refused with a reason', (flag_post("$baseFl/?admin&folder=Trip", $flPage, $flHdrs, 'private', true, [])['ok'] ?? null) === false);
$r = flag_post("$baseFl/?admin&folder=Trip", $flPage, $flHdrs, 'private', false, ['b.jpg', 'c.jpg', 'd.jpg']);
check('[flags] and Make public undoes it for the whole selection', ($r['ok'] ?? null) === true && !isset($flItems()['b.jpg']['restriction']) && str_contains(http_get("$baseFl/?folder=Trip")[1], 'alt="Trip/d.jpg"'));
// Stale-edit fingerprint ignores the switches: an editor opened before a switch was flipped still saves.
[, $flPage2] = http_get("$baseFl/?admin&folder=Trip", $flHdrs);
$flHashBefore = item_get($baseFl, $flHdrs, 'Trip/a.jpg')['hash'] ?? '';
flag_post("$baseFl/?admin&folder=Trip", $flPage2, $flHdrs, 'private', true, ['a.jpg']);
[, $rs] = item_save("$baseFl/?admin&folder=Trip", $flHdrs, 'a.jpg', ['caption' => 'Still saves', 'loaded_hash' => $flHashBefore]);
check('[flags] flipping a switch does not make that photo\'s editor look out of date (its fingerprint ignores the switches)', str_contains($rs, 'class="flash ok"') && ($flItems()['a.jpg']['caption'] ?? null) === 'Still saves' && ($flItems()['a.jpg']['restriction'] ?? null) === 'private');
check('[flags] saving a photo\'s caption form leaves its privacy as the switch set it (the form no longer carries it)', true === (($flItems()['a.jpg']['restriction'] ?? null) === 'private'));
// Location: off is always stored on the photo; on follows the folder when the folder already shows locations.
$r = flag_post("$baseFl/?admin&folder=Trip", $flPage2, $flHdrs, 'location', true, ['b.jpg']);
check('[flags] "Show its location" on, while the folder\'s location switch is off, stores Always show for that photo only', ($r['ok'] ?? null) === true && ($flItems()['b.jpg']['locationVisibility'] ?? null) === 'show');
$r = flag_post("$baseFl/?admin&folder=Trip", $flPage2, $flHdrs, 'location', false, ['b.jpg']);
check('[flags] ...and off is stored as Always hide, so it stays off if the folder is switched on later', ($flItems()['b.jpg']['locationVisibility'] ?? null) === 'hide');
check('[flags] an unknown setting name is refused', (flag_post("$baseFl/?admin&folder=Trip", $flPage2, $flHdrs, 'caption', true, ['b.jpg'])['ok'] ?? null) === false);
// The cover photo is highlighted in the photo grid, and mentioned there rather than in the settings.
$coverSet = item_cover("$baseFl/?admin&folder=Trip", $flHdrs, 'c.jpg');
[, $flCoverPage] = http_get("$baseFl/?admin&folder=Trip", $flHdrs);
[, $flOwner2] = http_get("$baseFl/?folder=Trip", $flHdrs);
check('[cover mark] the owner\'s page lights exactly the photo that is the folder\'s cover (a gold star), and nothing else', substr_count($flOwner2, 'class="cover-toggle is-cover"') === 1 && str_contains($flOwner2, 'class="cover-toggle is-cover" data-file="Trip/c.jpg"') && (item_get($baseFl, $flHdrs, 'Trip/c.jpg')['cover'] ?? null) === true);
check('[cover mark] the editor knows it already is the cover (its Cover switch is lit), and every other photo\'s is not', (item_get($baseFl, $flHdrs, 'Trip/a.jpg')['cover'] ?? null) === false && (item_get($baseFl, $flHdrs, 'Trip/b.jpg')['cover'] ?? null) === false && (item_get($baseFl, $flHdrs, 'Trip/d.jpg')['cover'] ?? null) === false);
check('[cover mark] the folder\'s settings carry no cover paragraph (the cover is chosen with the star or the editor\'s Cover switch)', !str_contains($flCoverPage, 'click "Set as cover" on any photo below') && !str_contains($flCoverPage, 'Shared links use the first photo as their picture') && !str_contains($flCoverPage, 'data-tab="photos"'));
[, $flRootAdmin] = http_get("$baseFl/?admin", $flHdrs);
check('[cover mark] nor does the gallery\'s own settings page', !str_contains($flRootAdmin, 'Shared links use the first photo as their picture') && !str_contains($flRootAdmin, 'Cover photo (the picture in shared-link previews)'));
check('[rules] the Private page offers ready-made nginx and Apache rules, collapsed, written for this gallery\'s address, with copy buttons', str_contains($flCoverPage, '<h2 class="section-title">Lock the files themselves</h2>') && substr_count($flCoverPage, 'class="secondary copy-rules"') >= 2);
check('[rules] Site settings carries the same rules in its Server setup section', str_contains($flRootAdmin, 'class="site-rules"') && str_contains($flRootAdmin, '<summary><strong>nginx</strong>') && str_contains($flRootAdmin, '<summary><strong>Apache</strong>') && str_contains($flRootAdmin, 'location ~* ^/.*\.(jpe?g|png|gif|webp|bmp|heic|heif|mov|mp4|m4v)$') && str_contains($flRootAdmin, 'FilesMatch') && str_contains($flRootAdmin, 'class="secondary copy-rules"'));
check('[rules] Site settings also has the optional tidy-folder-link rules for nginx and Apache, exactly as the README gives them', str_contains($flRootAdmin, 'rewrite ^/(.+)/$ /index.php?folder=$1 last;') && str_contains($flRootAdmin, 'RewriteRule ^(.+)/$ index.php?folder=$1 [B,L,QSA]') && substr_count($flRootAdmin, '<summary><strong>') >= 4);
check('[rules] the Private page keeps to the privacy rules (two drop-downs) and points to Site settings for the optional ones and the nginx redirect setting', (preg_match('#Lock the files themselves.*?</section>#s', $flCoverPage, $lockSec) === 1 && substr_count($lockSec[0], '<summary><strong>') === 2) && str_contains($lockSec[0] ?? '', 'tidy folder links and the nginx redirect') && !str_contains($lockSec[0] ?? '', 'RewriteRule ^(.+)/$') && !str_contains($lockSec[0] ?? '', 'rewrite ^'));
check('[pin grouping] in Site settings the pin-grouping row is set in under the map and hidden while the map is off', (bool) preg_match('#<div class="site-row sub-row" data-requires="set_ENABLE_MAP" hidden>#', $flRootAdmin));
// Passwords.
$flFields = fn ($pw) => user_submit("$baseFl/?admin", http_get("$baseFl/?admin#site", $flHdrs)[1], 'value="save_site_settings"', ['pw_admin' => $pw, 'pw_admin2' => $pw], $flHdrs)[1];
foreach (['abcdefghijkl' => 'letters only', '1234567890' => 'numbers only', 'abc123' => 'under 8', '          ' => 'spaces'] as $pw => $why) {
    $out = $flFields((string) $pw);
    check("[passwords] an admin password that is $why is refused with the rule", str_contains($out, 'at least 8 characters') && str_contains($out, 'letter') && str_contains($out, 'number'));
}
$onePw = user_submit("$baseFl/?admin", http_get("$baseFl/?admin#site", $flHdrs)[1], 'value="save_site_settings"', ['pw_private' => 'x', 'pw_private2' => 'x'], $flHdrs)[1];
check('[passwords] the private-view password may be any length: a single character is accepted', str_contains($onePw, 'class="flash ok"') && pw_stored_ok(cfg_read("$fxFl/.gallery-config.php")['PRIVATE_VIEW_PASSWORD'] ?? null, 'x'));
$okOut = $flFields('Ab3defgh');
check('[passwords] eight characters with a letter (either case) and a number is accepted for the admin', str_contains($okOut, 'Settings saved') || str_contains($okOut, 'class="flash ok"'));
check('[passwords] ...and it logs in', (http_post("$baseFl/?admin", ['login_password' => 'Ab3defgh'], [], false)[0] ?? 0) === 303);

echo "== the lock's two jobs ==\n";
$fxLk = new_fixture_dir('lockonly');
make_jpeg("$fxLk/a.jpg", 60, 60, 1, 2, 3); make_jpeg("$fxLk/b.jpg", 60, 60, 4, 5, 6);
file_put_contents("$fxLk/.gallery-config.php", cfg_text(['PRIVATE_VIEW_PASSWORD' => 'only-viewers']));
copy(__DIR__ . '/../index.php', "$fxLk/index.php"); touch("$fxLk/index.php", time() - 3600);
$portLk = find_free_port(); start_server($fxLk, $portLk); $baseLk = "http://127.0.0.1:$portLk";
check('[lock] with only a private-view password and nothing private there is nothing to unlock, so no lock', !str_contains(http_get("$baseLk/")[1], 'class="lock-link'));
write_data_file("$fxLk/.gallery-items.php", ['items' => ['b.jpg' => ['restriction' => 'private']], 'groups' => []]);
$lkHome = http_get("$baseLk/")[1];
check('[lock] ...and once a photo is private the closed lock appears', str_contains($lkHome, 'class="map-combo-btn lock-link" href="?reveal"') && !str_contains($lkHome, 'alt="b.jpg"'));
[, $lkForm] = http_get("$baseLk/?reveal");
$lkFormText = preg_replace('#<(script|style)\b.*?</\1>#s', '', $lkForm);
check('[lock] the sign-in page is bare: one large lock drawing and one box, with no heading, no button and no words about passwords or signing in', substr_count($lkFormText, '<h1') === 0 && !str_contains($lkForm, '<button') && !str_contains($lkFormText, 'Continue') && !str_contains($lkFormText, 'Go Back') && str_contains($lkForm, 'class="mark" id="goback"') && str_contains($lkForm, 'name="access_code"') && !preg_match('/password|sign in|admin|private-view/i', trim(strip_tags($lkFormText))));
[$lkBadSt, $lkBad] = http_post("$baseLk/?reveal", ['login_password' => 'nope'], [], false);
check('[lock] a wrong password is refused with a message', $lkBadSt === 200 && str_contains($lkBad, "isn't right") || str_contains($lkBad, 'isn&#039;t right'));
$fxLk2 = new_fixture_dir('lockboth');
make_jpeg("$fxLk2/a.jpg", 60, 60, 1, 2, 3); mkdir("$fxLk2/Sub"); make_jpeg("$fxLk2/Sub/b.jpg", 60, 60, 4, 5, 6);
file_put_contents("$fxLk2/.gallery-config.php", cfg_text(['PRIVATE_VIEW_PASSWORD' => 'view-only', 'ADMIN_PASSWORD' => 'owner-pw-12345', 'AUTO_GROUP_BY_FOLDER' => true]));
copy(__DIR__ . '/../index.php', "$fxLk2/index.php"); touch("$fxLk2/index.php", time() - 3600);
$portLk2 = find_free_port(); start_server($fxLk2, $portLk2); $baseLk2 = "http://127.0.0.1:$portLk2";
[$lkAdSt, , $lkAdHd] = http_post("$baseLk2/?reveal&folder=Sub", ['login_password' => 'owner-pw-12345'], [], false);
check('[lock] the admin password typed into the same box signs the owner in and returns to the page the lock was pressed on (not to the admin page: the title leads there)', $lkAdSt === 303 && (bool) array_filter($lkAdHd, fn($h) => stripos($h, 'Location: ./?folder=Sub') === 0) && !array_filter($lkAdHd, fn($h) => stripos($h, '?admin') !== false));
$lkOwnerCookie = (string) extract_cookie_header($lkAdHd);
[, $lkAdminPage] = http_get("$baseLk2/?admin&folder=Sub", ['Cookie: ' . $lkOwnerCookie]);
check('[lock] ...and that really is a signed-in admin session (the admin page opens with no further password)', str_contains($lkAdminPage, 'Gallery Admin'));
[$lkVwSt, , $lkVwHd] = http_post("$baseLk2/?reveal", ['login_password' => 'view-only'], [], false);
check('[lock] the private-view password typed into the same box shows the private items, and is not the admin', $lkVwSt === 302 && (bool) array_filter($lkVwHd, fn($h) => stripos($h, 'Location: ./') === 0) && !str_contains(http_get("$baseLk2/?admin", ['Cookie: ' . (string) extract_cookie_header($lkVwHd)])[1], 'Gallery Admin'));
[, $lkOwnerHome] = http_get("$baseLk2/?folder=Sub", ['Cookie: ' . $lkOwnerCookie]);
check('[lock] a signed-in owner has no Admin link in the footer (there is no footer any more); the title is the way back to the admin, for the folder they are in', !str_contains($lkOwnerHome, '>Admin</a>') && !str_contains($lkOwnerHome, '<footer') && str_contains($lkOwnerHome, '<a class="title-admin" rel="nofollow" href="?admin&amp;folder=Sub">'));
[, , $lkOutHd] = http_post("$baseLk2/?reveal", ['logout' => '1', 'folder' => 'Sub', 'photo' => 'Sub/b.jpg', 'map' => '1'], ['Cookie: ' . $lkOwnerCookie], false);
check('[lock] signing out returns to the very same page, not just the same folder: the open photo and the open map are kept', (bool) array_filter($lkOutHd, fn($h) => stripos($h, 'Location: ./?folder=Sub&photo=Sub%2Fb.jpg&map') === 0));
[, $lkPageHome] = http_get("$baseLk2/?folder=Sub&photo=Sub%2Fb.jpg");
check('[lock] the closed lock on such a page carries the photo too, so signing in brings you back to it', str_contains($lkPageHome, 'href="?reveal&amp;folder=Sub&amp;photo=Sub%2Fb.jpg"'));
[, , $lkAdHd2] = http_post("$baseLk2/?reveal&folder=Sub", ['login_password' => 'owner-pw-12345'], [], false);
$lkOwnerCookie = (string) extract_cookie_header($lkAdHd2);
[, $lkAdmForm] = http_get("$baseLk2/?admin&folder=Sub", ['Cookie: ' . $lkOwnerCookie]);
$lkAdmOutHd = [];
foreach (html_post_forms($lkAdmForm) as $lkF) if (str_contains($lkF['html'], 'value="logout"')) { [, , $lkAdmOutHd] = http_post("$baseLk2/?admin&folder=Sub", form_default_fields($lkF['html']), ['Cookie: ' . $lkOwnerCookie], false); break; }
check('[lock] the admin\'s own Log out from a folder lands on that folder\'s gallery page, signed out', (bool) array_filter($lkAdmOutHd, fn($h) => stripos($h, 'Location: ?folder=Sub') === 0));
[, $lkCancelRoot] = http_get("$baseLk2/?reveal");
[, $lkCancelFolder] = http_get("$baseLk2/?reveal&folder=Sub&photo=Sub%2Fb.jpg");
[, $lkCancelAdmin] = http_get("$baseLk2/?admin&folder=Sub");
check('[lock] the lock drawing is the way back: it returns to the page the visitor came from (the gallery, the folder and photo, the folder), so a curious visitor with no code is not stuck', str_contains($lkCancelRoot, 'class="mark" id="goback" href="./"') && str_contains($lkCancelFolder, 'class="mark" id="goback" href="./?folder=Sub&amp;photo=Sub%2Fb.jpg"') && str_contains($lkCancelAdmin, 'class="mark" id="goback" href="./?folder=Sub"') && str_contains($lkCancelRoot, 'aria-label="Back to the gallery"'));
check('[lock] a visitor\'s title is still plain text: it leads to the admin only for the signed-in owner', !str_contains(http_get("$baseLk2/")[1], 'class="title-admin"') && !str_contains(http_get("$baseLk2/?folder=Sub")[1], 'class="title-admin"'));
[, $lkOwnerRoot] = http_get("$baseLk2/", ['Cookie: ' . $lkOwnerCookie]);
check('[lock] ...and it looks and behaves exactly like plain text: no hover, focus or cursor rule, and no tooltip', (bool) preg_match('/header h1 a\\.title-admin \\{ color: inherit; text-decoration: none; cursor: inherit;/', $lkOwnerRoot) && !str_contains($lkOwnerRoot, 'title-admin:hover') && !str_contains($lkOwnerRoot, 'title-admin:focus') && !str_contains($lkOwnerRoot, 'title="Back to the admin"'));
check('[lock] ...and at the top of the gallery it leads to the admin\'s own front page', str_contains($lkOwnerRoot, '<a class="title-admin" rel="nofollow" href="?admin">'));

echo "== a folder opens on its chosen cover photo ==\n";
$fxCv = new_fixture_dir('covertarget');
mkdir("$fxCv/Trip"); mkdir("$fxCv/Rome");
foreach (['a', 'b', 'c'] as $i => $n) make_jpeg("$fxCv/Trip/$n.jpg", 60, 60, 10 * $i, 10, 10);
make_jpeg("$fxCv/Rome/r.jpg", 60, 60, 1, 2, 3);
write_data_file("$fxCv/Trip/.gallery-items.php", ['items' => [], 'groups' => [], 'cover' => 'c.jpg']);
write_patched_index("$fxCv/index.php", $indexContents, ['AUTO_GROUP_BY_FOLDER' => 'true']);
$portCv = find_free_port(); start_server($fxCv, $portCv); $baseCv = "http://127.0.0.1:$portCv";
[, $cvRoot] = http_get("$baseCv/");
check('[cover jump] a folder whose owner chose a cover photo opens at the top like any other: its link carries no #fragment that scrolls the page to that photo', str_contains($cvRoot, 'class="folder-cover-link" href="?folder=Trip"') && !str_contains($cvRoot, '#folder-cover'));
[, $stkPage] = http_get("$baseCv/");
check('[sticky strip] the strip of buttons above the photos is a bar that sticks to the top of the screen while the photos scroll under it, and a link that scrolls to a photo stops below it', str_contains($stkPage, 'id="map-bar"') && str_contains($stkPage, '.map-bar { position: sticky; top: 0;') && str_contains($stkPage, 'scroll-padding-top'));
check('[cover jump] a folder with no chosen cover opens at the top, as before', str_contains($cvRoot, 'class="folder-cover-link" href="?folder=Rome"') && !str_contains($cvRoot, 'href="?folder=Rome#'));
[, $cvTrip] = http_get("$baseCv/?folder=Trip");
check('[cover jump] the folder page no longer marks a photo to land on', !str_contains($cvTrip, 'id="folder-cover"') && !str_contains($cvTrip, 'cover-anchor'));
// A cover card's picture is a square cut from the original photo, so it is sharp however big the card is.
$cvSrc = imagecreatetruecolor(1200, 1800); imagefill($cvSrc, 0, 0, imagecolorallocate($cvSrc, 0, 0, 255)); imagefilledrectangle($cvSrc, 0, 0, 1199, 150, imagecolorallocate($cvSrc, 255, 0, 0)); imagejpeg($cvSrc, "$fxCv/Rome/tall.jpg", 95);
$cvLand = imagecreatetruecolor(1800, 1200); imagejpeg($cvLand, "$fxCv/Rome/wide.jpg", 95);
$cvPx = function ($url) { [, $b] = http_get($url); $im = @imagecreatefromstring($b); return $im ? [imagesx($im), imagesy($im), imagecolorat($im, imagesx($im) / 2, 2) >> 16 & 255] : null; };
$cvSmall = $cvPx("$baseCv/?img=Rome%2Ftall.jpg&size=cover");
$cvLarge = $cvPx("$baseCv/?img=Rome%2Fwide.jpg&size=coverlg");
check('[cover square] the cover picture of a portrait photo is a 640-pixel square, cut from the original', $cvSmall !== null && $cvSmall[0] === 640 && $cvSmall[1] === 640);
check('[cover square] ...taken from the upper-middle, not the very top: the red strip along the top of the original is cropped away', $cvSmall !== null && $cvSmall[2] < 100);
check('[cover square] the large cover picture of a landscape photo is a 1200-pixel square', $cvLarge !== null && $cvLarge[0] === 1200 && $cvLarge[1] === 1200);
[, $cvPage] = http_get("$baseCv/");
check('[cover square] a cover card offers both sizes, and the page asks for the square, not the thumbnail', str_contains($cvPage, 'size=cover&amp;') && str_contains($cvPage, 'size=coverlg&amp;') && str_contains($cvPage, '640w') && str_contains($cvPage, '1200w') && !preg_match('#class="folder-cover-link"[^>]*><img src="\?img=[^"]*size=thumb#', $cvPage));
check('[cover square] a hidden photo\'s cover cut is refused to a visitor like every other size', http_get("$baseCv/?img=Rome%2Fnope.jpg&size=cover")[0] === 404);
check('[cover jump] the copy-link icon on the cover card still shares the plain folder address', str_contains($cvRoot, 'folder-permalink-copy" href="?folder=Trip"'));

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
check('[dates] a folder\'s settings offer the span of its photos (here from file dates, subfolders included) with a one-click button, and say where the dates come from', str_contains($datesAdmin, 'span <strong>Oct 3-9th, 2026</strong>') && str_contains($datesAdmin, 'class="secondary date-suggest" data-dates="Oct 3-9th, 2026"') && str_contains($datesAdmin, 'placeholder="Oct 1-11th, 2026"') && str_contains($datesAdmin, 'modified dates') && !str_contains($datesAdmin, 'Only what you save is shown') && !str_contains($datesAdmin, 'the dates the photos were taken'));
[, $datesRoot] = http_get("http://127.0.0.1:$portDates/?admin", $datesAuth);
check('[dates] the gallery root suggests a range across years', str_contains($datesRoot, '12/30/2025 - 10/09/2026'));
check('[dates] nothing is stored or shown to visitors until the form is saved', !str_contains(http_get("http://127.0.0.1:$portDates/?folder=Trip")[1], 'Oct 3-9th'));
write_data_file("$fxDates/Trip/.gallery-items.php", ['dates' => 'Oct 3-9th, 2026', 'items' => [], 'groups' => []]);
[, $datesSaved] = http_get("http://127.0.0.1:$portDates/?admin&folder=Trip", $datesAuth);
[, $datesDay] = http_get("http://127.0.0.1:$portDates/?admin&folder=Trip%2FDay2", $datesAuth);
check('[dates] a single day reads "Oct 9th, 2026", with its ordinal', str_contains($datesDay, 'span <strong>Oct 9th, 2026</strong>'));
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
check('[keep data] (setup) with everything on: the folder is shown, the hidden video is not (its hide flag works), and the two counted views are visible', str_contains($onPage, 'id="group-sub"') && !str_contains($onPage, 'alt="clip.mp4"') && !str_contains($onPage, 'img=clip.mp4') && str_contains($onPage, 'data-photo="photo.jpg">' . ico('eye') . ' 2<'));
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
check('[keep data] ...and counting resumes from where it left off (the earlier 2 views, now 3)', http_get("$baseKeep/?track=photo.jpg")[0] === 200 && str_contains(http_get("$baseKeep/")[1], 'data-photo="photo.jpg">' . ico('eye') . ' 3<'));
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
    'set_PHOTO_SORT_ORDER' => 'filename', 'set_MAP_CLUSTER_RADIUS_MILES' => '25',
], $siteHdrs);
check('[site settings] a save redirects back and then shows a confirmation that says nothing else was touched', str_contains($r, 'Settings saved') && str_contains($r, 'were not touched') && str_contains($r, 'class="flash ok"'));
$snapAfterSave = $siteSnap();   // before any public page is viewed (a view is counted, legitimately)
$written = (string) file_get_contents($siteCfg);
$loadedCfg = cfg_plain(cfg_read($siteCfg), ['ADMIN_PASSWORD' => 'first-admin-pw-123']);
$expectedCfg = ['ADMIN_PASSWORD' => 'first-admin-pw-123', 'AUTO_GROUP_BY_FOLDER' => true, 'ENABLE_THUMBNAIL_CACHE' => false, 'ENABLE_VIDEO' => true, 'FOLDER_DISPLAY_MODE' => 'inline', 'MAP_CLUSTER_RADIUS_MILES' => 25, 'PHOTO_SORT_ORDER' => 'filename', 'SHOW_VIEW_COUNTS_PUBLICLY' => true, 'TRACK_VIEWS' => true];
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
user_submit("$baseSite/?admin", $sAdmin2, 'value="save_site_settings"', ['set_ENABLE_VIDEO' => '0', 'set_AUTO_GROUP_BY_FOLDER' => '0', 'set_ENABLE_THUMBNAIL_CACHE' => '1', 'set_FOLDER_DISPLAY_MODE' => 'overview', 'set_PHOTO_SORT_ORDER' => 'taken', 'set_MAP_CLUSTER_RADIUS_MILES' => '1'], $siteHdrs);
$backCfg = cfg_plain(cfg_read($siteCfg), ['ADMIN_PASSWORD' => 'first-admin-pw-123']); ksort($backCfg);
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
check('[site settings] ...too short is refused with the reason', str_contains($rShort, 'at least 8 characters') && (string) file_get_contents($siteCfg) === $cfgBytes);
$nasty = "p'a1\\ss\"w \$x<?php echo 'X'; system('id'); ?>\n";
$nasty = str_replace("\n", '', $nasty);
[, $rPw] = user_submit("$baseSite/?admin", $sAdmin4, 'value="save_site_settings"', ['pw_admin' => $nasty, 'pw_admin2' => $nasty], $siteHdrs);
check('[site settings] changing the admin password works, and you stay signed in (the session still opens the admin, no password asked)', str_contains($rPw, 'admin password changed') && str_contains(http_get("$baseSite/?admin", [$siteHdrs[1]])[1], 'Gallery Admin'));
$pwCfg = cfg_read($siteCfg);
check('[site settings] a password full of quotes, backslashes, $ and PHP tags is stored as a hash that verifies exactly (and none of its text is in the file)', pw_stored_ok($pwCfg['ADMIN_PASSWORD'] ?? null, $nasty) && !str_contains((string) file_get_contents($siteCfg), 'system'));
check('[site settings] the new password logs in through the form (a redirect into the admin); the old one no longer does (the form again)', http_post("$baseSite/?admin", ['login_password' => $nasty], [], false)[0] === 303 && http_post("$baseSite/?admin", ['login_password' => 'first-admin-pw-123'], [], false)[0] === 200);
// Hostile text can't be injected through any value: only typed, allow-listed values are ever written.
$cfgFileText = (string) file_get_contents($siteCfg);
$cfgAfterGuard = substr($cfgFileText, strpos($cfgFileText, "\n") + 1);
check('[site settings] the written file is the 404 guard plus plain JSON data, never code: one PHP tag in the whole file even though the password contained "system(\'id\')" and a PHP tag, and the rest decodes as JSON', substr_count($cfgFileText, '<?php') === 1 && str_starts_with($cfgFileText, $cfgGuard) && is_array(json_decode($cfgAfterGuard, true)) && pw_stored_ok(json_decode($cfgAfterGuard, true)['ADMIN_PASSWORD'] ?? null, $nasty));
// A fresh login for the rest (the password changed).
$siteAuth2 = 'Authorization: Basic ' . base64_encode('x:' . $nasty);
[, $sAdmin5, $sAdmin5Hd] = http_get("$baseSite/?admin", [$siteAuth2]);
$siteHdrs2 = [$siteAuth2, 'Cookie: ' . extract_cookie_header($sAdmin5Hd)];
// Private-view password and removing it.
user_submit("$baseSite/?admin", $sAdmin5, 'value="save_site_settings"', ['pw_private' => 'viewme', 'pw_private2' => 'viewme'], $siteHdrs2);
$pv = cfg_read($siteCfg);
check('[site settings] the private-view password can be set from here', pw_stored_ok($pv['PRIVATE_VIEW_PASSWORD'] ?? null, 'viewme'));
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
$exJson = function (array $named = [], array $roots = []) { return json_encode(['named' => (object) $named, 'roots' => $roots]); };
foreach ($bad as $why => $map) {
    $r = $siteAjax(['set_EXTRA_FOLDERS' => $exJson($map)]);
    check("[extra folders] $why is refused with a reason, and the settings file is untouched", ($r['ok'] ?? null) === false && ($r['text'] ?? '') !== '' && (string) file_get_contents($siteCfg) === $cfgBeforeExtra);
}
$dup = $siteAjax(['set_EXTRA_FOLDERS' => '{"named":{"Same":"' . $extraSrcReal . '","same":"' . $extraSrcReal . '"},"roots":[]}']);
check('[extra folders] the same name twice (ignoring case) is refused', ($dup['ok'] ?? null) === false && str_contains((string) $dup['text'], 'twice'));
check('[extra folders] something that is not a list of folders is refused', ($siteAjax(['set_EXTRA_FOLDERS' => 'not json'])['ok'] ?? null) === false && ($siteAjax(['set_EXTRA_FOLDERS' => '["a","b"]'])['ok'] ?? null) === false);
// A good one, end to end.
$siteAjax(['set_AUTO_GROUP_BY_FOLDER' => '1']);
$extraPhotoMd5 = md5_file("$extraSrc/archive1.jpg");
$good = $siteAjax(['set_EXTRA_FOLDERS' => $exJson(['OldTrip' => $extraSrcReal])]);
check('[extra folders] a real, readable folder elsewhere on the server is accepted, and handed back as stored', ($good['ok'] ?? null) === true && (array) ($good['extra']['named'] ?? []) === ['OldTrip' => $extraSrcReal] && (cfg_read($siteCfg)['EXTRA_FOLDERS'] ?? null) === ['OldTrip' => $extraSrcReal]);
$homeExtra = http_get("$baseSite/", $siteHdrs2)[1];
check('[extra folders] its photos then appear in the gallery as a folder under that name, with thumbnails that load', str_contains($homeExtra, 'id="group-oldtrip"') && http_get("$baseSite/?img=OldTrip%2Farchive1.jpg&size=thumb")[0] === 200 && str_contains(http_get("$baseSite/?folder=OldTrip")[1], 'archive1.jpg'));
check('[extra folders] the sidebar list in the admin includes it', str_contains(http_get("$baseSite/?admin", $siteHdrs2)[1], 'folder=OldTrip'));
$rm = $siteAjax(['set_EXTRA_FOLDERS' => $exJson()]);
check('[extra folders] removing it takes it out of the gallery and out of the settings file (back to the default)', ($rm['ok'] ?? null) === true && !isset(cfg_read($siteCfg)['EXTRA_FOLDERS']) && !str_contains(http_get("$baseSite/")[1], 'id="group-oldtrip"'));
check('[extra folders] and nothing in that other folder was lost: the photo is byte-for-byte as it was and the caption saved there is intact (the gallery only adds its standard empty fields to a data file the first time it visits the folder)', md5_file("$extraSrc/archive1.jpg") === $extraPhotoMd5 && (read_data_file("$extraSrc/.gallery-items.php")['items'] ?? null) === ['archive1.jpg' => ['caption' => 'From the archive disk']]);
// A place whose FOLDERS belong at the top of the gallery (no wrapper folder): /space/trip holding Paris and StTropez.
$rootsSrc = new_fixture_dir('extra-roots'); $rootsSrcReal = realpath($rootsSrc);
mkdir("$rootsSrc/Paris"); mkdir("$rootsSrc/StTropez"); mkdir("$rootsSrc/Sub");
make_jpeg("$rootsSrc/Paris/p.jpg", 60, 60, 10, 10, 10);
make_jpeg("$rootsSrc/StTropez/s.jpg", 60, 60, 20, 20, 20);
make_jpeg("$rootsSrc/Sub/shadowed.jpg", 60, 60, 30, 30, 30);
make_jpeg("$rootsSrc/loose.jpg", 60, 60, 40, 40, 40);
$cfgBeforeRoots = (string) file_get_contents($siteCfg);
$r1 = $siteAjax(['set_EXTRA_FOLDERS' => $exJson([], [$rootsSrcReal])]);
check('[extra roots] a place added without a name is accepted and stored as a path in its own list, not as a named folder', ($r1['ok'] ?? null) === true && (cfg_read($siteCfg)['EXTRA_ROOTS'] ?? null) === [$rootsSrcReal] && !isset(cfg_read($siteCfg)['EXTRA_FOLDERS']) && (array) ($r1['extra']['roots'] ?? []) === [$rootsSrcReal]);
[, , $hrHd] = http_get("$baseSite/?admin", [$siteAuth2]);   // (the owner signs in afresh: the settings file was rewritten above, and setup notices are for the signed-in owner)
$homeRoots = http_get("$baseSite/", [$siteAuth2, 'Cookie: ' . extract_cookie_header($hrHd)])[1];
check('[extra roots] its folders appear AT THE TOP of the gallery under their own names, with no wrapper folder named after the place', str_contains($homeRoots, 'id="group-paris"') && str_contains($homeRoots, 'id="group-sttropez"') && !str_contains($homeRoots, basename($rootsSrcReal)));
check('[extra roots] ...with thumbnails that load, and their own pages', http_get("$baseSite/?img=Paris%2Fp.jpg&size=thumb")[0] === 200 && str_contains(http_get("$baseSite/?folder=StTropez")[1], 's.jpg'));
check('[extra roots] a real folder of the same name wins over one from the added place (Sub keeps its own photos, not shadowed.jpg)', !str_contains(http_get("$baseSite/?folder=Sub")[1], 'shadowed.jpg') && http_get("$baseSite/?img=Sub%2Fshadowed.jpg&size=thumb")[0] === 404);
check('[extra roots] photos lying directly in the added place are not shown, and a setup notice says how many (a count, never a name)', !str_contains($homeRoots, 'loose.jpg') && str_contains($homeRoots, '1 photo or video lies directly in a place added from elsewhere'));
check('[extra roots] the admin sidebar lists the folders too', str_contains(http_get("$baseSite/?admin", $siteHdrs2)[1], 'folder=StTropez'));
check('[extra roots] the Site settings row lists the place and says its folders are at the top', str_contains(http_get("$baseSite/?admin", $siteHdrs2)[1], 'its folders are at the top of the gallery') && str_contains(http_get("$baseSite/?admin", $siteHdrs2)[1], htmlspecialchars($rootsSrcReal)));
$both = $siteAjax(['set_EXTRA_FOLDERS' => $exJson(['OldTrip' => $extraSrcReal], [$rootsSrcReal])]);
$homeBoth = http_get("$baseSite/", $siteHdrs2)[1];
check('[extra roots] a named place and an unnamed one live together: one wrapper folder, plus the other place\'s folders at the top', ($both['ok'] ?? null) === true && str_contains($homeBoth, 'id="group-oldtrip"') && str_contains($homeBoth, 'id="group-paris"') && (cfg_read($siteCfg)['EXTRA_FOLDERS'] ?? null) === ['OldTrip' => $extraSrcReal] && (cfg_read($siteCfg)['EXTRA_ROOTS'] ?? null) === [$rootsSrcReal]);
$cfgBothText = (string) file_get_contents($siteCfg);
foreach (['a path that does not exist' => ['/no/such/place'], 'the gallery itself' => [$fxSite], 'a relative path' => ['photos/x'], 'the same place twice' => [$rootsSrcReal, $rootsSrcReal], 'a file' => ["$rootsSrcReal/loose.jpg"]] as $why => $roots) {
    $rr = $siteAjax(['set_EXTRA_FOLDERS' => $exJson([], $roots)]);
    check("[extra roots] $why is refused with a reason, and nothing changes", ($rr['ok'] ?? null) === false && ($rr['text'] ?? '') !== '' && (string) file_get_contents($siteCfg) === $cfgBothText);
}
$gone = $siteAjax(['set_EXTRA_FOLDERS' => $exJson()]);
check('[extra roots] removing the place takes its folders off the top of the gallery and out of the settings file; nothing in it was touched', ($gone['ok'] ?? null) === true && !isset(cfg_read($siteCfg)['EXTRA_ROOTS']) && !isset(cfg_read($siteCfg)['EXTRA_FOLDERS']) && !str_contains(http_get("$baseSite/")[1], 'id="group-paris"') && file_exists("$rootsSrc/Paris/p.jpg") && file_exists("$rootsSrc/loose.jpg"));
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
check('[root identity] the root\'s Settings tab has the same form a folder has: title, dates, description (no position at the root)', str_contains($rAdmin, 'Gallery settings') && str_contains($rAdmin, '<label>Gallery title <span class="rowstate"') && str_contains($rAdmin, 'name="dates"') && str_contains($rAdmin, 'name="description"') && str_contains($rAdmin, 'Save gallery settings') && !str_contains($rAdmin, 'name="sort_order"'));
[, $rHome0] = http_get("$baseRoot/");
check('[root identity] before anything is set, the "# Heading" in the notes file is still the page title', str_contains($rHome0, '<title>From The Heading — Gallery</title>') && !str_contains($rHome0, 'class="folder-dates"'));
[, $r] = user_submit("$baseRoot/?admin", $rAdmin, 'value="save_folder_identity"', ['display_name' => 'Summer 2026', 'dates' => 'June 1–9', 'description' => 'Two weeks of nothing but sun.'], $rootHdrs);
check('[root identity] saving the gallery settings works', str_contains($r, 'class="flash ok"') && str_contains($r, 'Gallery settings saved.'));
[, $rHome1] = http_get("$baseRoot/");
check('[root identity] the form\'s title beats the "# Heading" (page title, tab and h1)', str_contains($rHome1, '<title>Summer 2026 — Gallery</title>') && preg_match('#<h1>Summer 2026(<form|<a class="lock-link"|</h1>)#', $rHome1) === 1 && !str_contains($rHome1, 'From The Heading'));
check('[root identity] the dates show under the title, like a folder\'s', str_contains($rHome1, '<p class="folder-dates">June 1–9</p>'));
check('[root identity] the description is a centered note box above the notes box, like a folder\'s', (bool) preg_match('#</header>\s*<div class="notes">\s*<div class="note-box folder-description"><p>Two weeks of nothing but sun\.</p></div>.*?Some longer notes\.#s', $rHome1));
check('[root identity] shared-link previews use the title and the description', str_contains($rHome1, 'property="og:title" content="Summer 2026"') && str_contains($rHome1, 'property="og:description" content="Two weeks of nothing but sun."'));
[, $rFolder] = http_get("$baseRoot/?folder=Sub");
check('[root identity] a folder\'s way back uses the gallery title', str_contains($rFolder, '&larr; Summer 2026</a>') || str_contains($rFolder, '← Summer 2026</a>'));
// A cover for shared links.
[, $rAdmin2] = http_get("$baseRoot/?admin", $rootHdrs);
[$cs, $cb] = item_cover("$baseRoot/?admin", $rootHdrs, 'two.jpg');
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
check('[root identity] a folder\'s own form is unchanged (display name, position)', str_contains($subAdmin, '<label>Display name <span class="rowstate"') && str_contains($subAdmin, 'name="sort_order"') && str_contains($subAdmin, 'Save folder settings'));

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
file_put_contents("$fxMapF/.gallery-config.php", cfg_text(['ADMIN_PASSWORD' => 'map-admin-pw-123', 'AUTO_GROUP_BY_FOLDER' => true, 'ENABLE_PHOTO_LOCATION' => true, 'ENABLE_MAP' => true, 'MAP_CLUSTER_RADIUS_MILES' => 50]));
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
check('[folder maps] ...and a folder\'s small map copies its own link: the folder page with #map', str_contains($mapOnly, 'id="map-peek-share" data-href="?folder=Paris#map"'));
check('[folder maps] the folder has its own shareable map link: the page of the folder with the map open, only its photos, its own link in the share preview, and the breadcrumb back', str_contains($mapOnly, 'og:url" content="http://127.0.0.1:' . $portMapF . '/?map&amp;folder=Paris"') && $mapFiles($mapOnly)['files'] === ['Paris/Montmartre/m.jpg', 'Paris/a.jpg', 'Paris/b.jpg'] && str_contains($mapOnly, 'if (true) show();') && str_contains($mapOnly, 'class="breadcrumb"'));
check('[folder maps] a folder that has not switched its map on, or that does not exist, has no map page (404)', http_get("$baseMapF/?map&folder=Lisbon")[0] === 404 && http_get("$baseMapF/?map&folder=Nope")[0] === 404);
[, $badR] = user_submit("$baseMapF/?admin&folder=Paris", $mAdminPg2, 'value="save_map_settings"', ['map_enabled' => '1', 'map_radius' => '-1'], $mapHdrs);
[, $badR2] = user_submit("$baseMapF/?admin&folder=Paris", $mAdminPg2, 'value="save_map_settings"', ['map_enabled' => '1', 'map_radius' => 'abc'], $mapHdrs);
check('[folder maps] a pin grouping that is not a number from 0 to 10000 is refused with the reason, and the saved one stays', str_contains($badR, 'from 0 (no grouping) to 10000') && str_contains($badR2, 'from 0 (no grouping) to 10000') && ($mapFiles(http_get("$baseMapF/?folder=Paris")[1])['clusters'] ?? 0) === 3);
// 0 means no grouping at all, and fractions of a mile are allowed.
user_submit("$baseMapF/?admin&folder=Paris", $mAdminPg2, 'value="save_map_settings"', ['map_enabled' => '1', 'map_radius' => '0'], $mapHdrs);
$noGroup = $mapFiles(http_get("$baseMapF/?folder=Paris")[1]);
check('[folder maps] a pin grouping of 0 is accepted and means no grouping: every photo is its own pin, however close', (read_data_file("$fxMapF/Paris/.gallery-items.php")['mapRadius'] ?? null) === 0 && ($noGroup['clusters'] ?? 0) === count($noGroup['files'] ?? []) && count($noGroup['files'] ?? []) === 3);
user_submit("$baseMapF/?admin&folder=Paris", $mAdminPg2, 'value="save_map_settings"', ['map_enabled' => '1', 'map_radius' => '0.1'], $mapHdrs);
check('[folder maps] a fraction of a mile is accepted too (0.1)', (read_data_file("$fxMapF/Paris/.gallery-items.php")['mapRadius'] ?? null) === 0.1);
user_submit("$baseMapF/?admin&folder=Paris", $mAdminPg2, 'value="save_map_settings"', ['map_enabled' => '1', 'map_radius' => '1'], $mapHdrs);
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
[, $mapSubOn] = http_get("$baseMapF/?admin&folder=Paris", $mapHdrs);
check('[pin grouping] with the folder\'s map on, its pin grouping is shown, set in under the map switch, with its explanation at full width', str_contains($mapSubOn, '<div class="map-sub">') && preg_match('#<div class="map-sub">.*?name="map_radius".*?</div>\s*<p class="hint">Photos closer than this#s', $mapSubOn) === 1);
$mapAjax('&folder=Paris', ['map_enabled' => '0']);
[, $mapSubOff] = http_get("$baseMapF/?admin&folder=Paris", $mapHdrs);
check('[pin grouping] with the folder\'s map off, the pin grouping is hidden (and comes back with the switch)', str_contains($mapSubOff, '<div class="map-sub" hidden>'));
$mapAjax('&folder=Paris', ['map_enabled' => '1']);
$jRad = $mapAjax('&folder=Paris', ['map_radius' => '12']);
check('[instant] changing only the pin grouping leaves the switch as it was', ($jRad['ok'] ?? null) === true && ($parisData()['mapRadius'] ?? null) === 12 && ($parisData()['mapEnabled'] ?? null) === true);
$jBlank = $mapAjax('&folder=Paris', ['map_radius' => '']);
check('[instant] clearing the pin grouping goes back to the site-wide setting, and says so', ($jBlank['ok'] ?? null) === true && str_contains((string) $jBlank['text'], 'site-wide') && array_key_exists('mapRadius', $parisData()) && $parisData()['mapRadius'] === null);
$jBad = $mapAjax('&folder=Paris', ['map_radius' => '-4']);
check('[instant] an invalid pin grouping answers JSON with the reason and changes nothing', ($jBad['ok'] ?? null) === false && str_contains((string) $jBad['text'], 'from 0 (no grouping) to 10000') && array_key_exists('mapRadius', $parisData()) && $parisData()['mapRadius'] === null);
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
check('[instant] in the admin the map and location settings are switches with orange warnings beside them and no dialogs: a Save button exists only for browsers without JavaScript', substr_count($mapAdminAgain, 'class="instant-form') >= 2 && str_contains($mapAdminAgain, 'class="warn-note">Visitors\' browsers load code') && !preg_match('/<form[^>]*instant-form[^>]*onsubmit/', $mapAdminAgain) && substr_count(preg_replace('#<style.*?</style>#s', '', $mapAdminAgain), 'confirm(') <= 2);

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
$vCount = fn($body) => preg_match('#<span class="page-visits">([0-9,]+) visits?</span>#', $body, $m) ? (int) str_replace(',', '', $m[1]) : null;
$alice = [];
[, $v1, $vh1] = $vGet('/', $alice);
$counts = [$vCount($v1)];
foreach (['/?folder=Paris', '/?photo=top.jpg', '/', '/?folder=Paris', '/'] as $path) $counts[] = $vCount($vGet($path, $alice)[1]);
check('[visits] one visitor opening the front page, a folder, a shared photo link and reloading counts as ONE visit throughout, not six', $counts === [1, 1, 1, 1, 1, 1], json_encode($counts));
$visitCookie = ''; foreach ($vh1 as $h) if (stripos($h, 'Set-Cookie: gallery_visit=') === 0) $visitCookie = $h;
check('[visits] the visit is remembered in a small first-party cookie: HttpOnly, SameSite=Lax, and expiring in about half an hour', $visitCookie !== '' && stripos($visitCookie, 'httponly') !== false && stripos($visitCookie, 'samesite=lax') !== false && (bool) preg_match('/expires=/i', $visitCookie));
$bob = [];
$bobPage = $vGet('/', $bob)[1]; $bobStrip = (string) substr($bobPage, (int) strpos($bobPage, '<div class="map-preview-heading"'));
check('[visits] the total sits in the header line after the media count and before "Last updated", not in the icon strip', preg_match('#<header>.*?<p>[^<]*(photos?|media) &middot; <span class="page-visits">2 visits</span> &middot; Last updated #s', $bobPage) === 1 && !str_contains($bobStrip, 'page-visits'));
check('[visits] a different visitor (a browser with no cookie) is a second visit, and the page bar says "visits"', $vCount($vGet('/', $bob)[1]) === 2 && str_contains($vGet('/', $bob)[1], '<span class="page-visits">2 visits</span>'));
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
check('[visits] the admin still says what is counted (visits: one per visitor per half hour), now in Site settings, and carries no photo list', str_contains($adminVisits, 'one per visitor per half hour') && !str_contains($adminVisits, 'class="thumb-grid"'));
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
check('[login] a wrong password re-shows the form with a plain message (200), and sets no session', $s1 === 200 && str_contains($b1, 'That code isn&#039;t right') && str_contains($b1, 'name="access_code"') && !array_filter($h1, fn($h) => stripos($h, 'Set-Cookie: gallery_admin_') === 0));
// Right password: redirected back to the same admin URL, with a session cookie; no Authorization header needed afterwards.
[$s2, , $h2] = http_post("$baseLogin/?admin&folder=Sub", ['login_password' => 'login-pw'], [], false);
$loginCookie = $cookieOf($h2); $loc2 = ''; foreach ($h2 as $h) if (stripos($h, 'Location:') === 0) $loc2 = trim(substr($h, 9));
check('[login] the right password redirects to the very URL asked for, and sets the session cookie', $s2 === 303 && $loc2 === '?admin&folder=Sub' && str_contains($loginCookie, 'gallery_admin_'));
[$s3, $b3] = http_get("$baseLogin/?admin&folder=Sub", ["Cookie: $loginCookie"]);
check('[login] with just that cookie (no Authorization header) the admin page opens, on the right folder', $s3 === 200 && str_contains($b3, 'Gallery Admin') && str_contains($b3, 'Editing: Sub'));
check('[admin layout] a sticky top bar carries the title, the folder being edited, section links and Log out', str_contains($b3, '<header class="admin-bar">') && str_contains($b3, 'Editing: Sub') && !str_contains($b3, 'href="#photos"') && str_contains($b3, 'href="#settings"') && str_contains($b3, 'value="logout"'));
check('[admin layout] folders are a tree beside the content, with the current one marked', str_contains($b3, 'class="admin-folder-nav"') && str_contains($b3, 'class="current" aria-current="page"') && str_contains($b3, '<main class="admin-main"'));
check('[admin layout] the sections are real tabs (Folder settings, Notes, Export; no Photos tab any more: photos are edited in the gallery), each with its own panel; no Hidden tab when nothing is hidden', str_contains($b3, 'role="tablist"') && !str_contains($b3, 'data-tab="photos"') && str_contains($b3, 'data-tab="settings"') && str_contains($b3, 'data-tab="notes"') && str_contains($b3, 'data-tab="export"') && str_contains($b3, '<div class="tab-panel" data-tab="export">') && !str_contains($b3, 'data-tab="hidden"'));
check('[admin layout] the page has no thumbnail grid, per-photo forms or Select bar (the pencil and the Select button on the gallery do all of that)', !str_contains($b3, 'class="photo-editor"') && !str_contains($b3, 'class="thumb-grid"') && !str_contains($b3, 'class="item-editor"') && !str_contains($b3, 'id="bulk-bar"') && !str_contains($b3, 'name="loaded_hash" value="' . md5('x') . '"'));
check('[admin layout] the Folder settings tab is the one that opens first', str_contains($b3, "names.indexOf('settings') >= 0 ? 'settings' : names[0]"));
check('[admin layout] the anchors inside the panels still exist', str_contains($b3, 'id="settings"') && str_contains($b3, 'id="notes"') && str_contains($b3, 'id="export"'));
check('[login] the same URL without the cookie is the form again', str_contains(http_get("$baseLogin/?admin&folder=Sub")[1], 'name="access_code"'));
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
check('[login] after logging out, the old cookie no longer opens the admin', str_contains(http_get("$baseLogin/?admin", ["Cookie: $loginCookie"])[1], 'name="access_code"'));
// The private view uses the same kind of form.
[$sv1, $bv1] = http_get("$baseLogin/?reveal");
check('[login] ?reveal (the sign-in the lock leads to) shows a one-box form (no heading)', $sv1 === 200 && str_contains($bv1, 'name="access_code"') && !str_contains($bv1, '<h1'));
[$sv2, , $hv2] = http_post("$baseLogin/?reveal", ['login_password' => 'view-pw'], [], false);
check('[login] the right private-view password redirects to the plain gallery and sets the viewer cookie', $sv2 === 302 && str_contains($cookieOf($hv2), '='));
// Five wrong passwords lock the next attempt for a minute, even a right one.
for ($i = 0; $i < 5; $i++) http_post("$baseLogin/?admin", ['login_password' => 'bad' . $i]);
[$s7] = http_post("$baseLogin/?admin", ['login_password' => 'login-pw']);
check('[login] five wrong passwords lock the next attempt (429), even with the right password', $s7 === 429);
check('[login] ...and the shared sign-in box holds that address too: the right private-view password is refused (429) until the lockout passes, while just opening the form is not', http_post("$baseLogin/?reveal", ['login_password' => 'view-pw'], [], false)[0] === 429 && http_get("$baseLogin/?reveal")[0] === 200);

// A browser that saved a Basic password from an older version keeps sending it. That must never bring the
// browser's own dialog back: a 401 with the form, no WWW-Authenticate, and the form still logs you in.
$fxStale = new_fixture_dir('stale-password');
make_jpeg("$fxStale/a.jpg", 60, 60, 1, 2, 3); mkdir("$fxStale/Sub"); make_jpeg("$fxStale/Sub/b.jpg", 60, 60, 4, 5, 6);
file_put_contents("$fxStale/.gallery-config.php", cfg_text(['ADMIN_PASSWORD' => 'the-current-password-1', 'PRIVATE_VIEW_PASSWORD' => 'view-pw', 'AUTO_GROUP_BY_FOLDER' => true, 'ENABLE_MAP' => true, 'ENABLE_PHOTO_LOCATION' => true]));
copy(__DIR__ . '/../index.php', "$fxStale/index.php");
$portStale = find_free_port(); start_server($fxStale, $portStale); $baseStale = "http://127.0.0.1:$portStale";
$staleHdr = ['Authorization: Basic ' . base64_encode('admin:a-password-saved-long-ago')];
// (These three come first: every request that carries a wrong password counts against the sign-in throttle, which is shared.)
[$stA, $stBodyA] = http_get("$baseStale/?admin", $staleHdr);
[$stR, $stBodyR] = http_get("$baseStale/?reveal", $staleHdr);
$stLoginStatus = http_post("$baseStale/?reveal", ['login_password' => 'view-pw'], $staleHdr, false)[0];
$challenges = [];
foreach (['?admin', '?reveal', '?admin&folder=Sub', '?admin&hidden=1', '?map', ''] as $route) {
    [$stSt, , $stHd] = http_get("$baseStale/$route", $staleHdr, false);
    foreach ($stHd as $h) if (stripos($h, 'WWW-Authenticate') === 0) $challenges[] = "$route: $h";
}
check('[stale password] no route ever answers with a WWW-Authenticate challenge, even when the browser re-sends an old saved password', $challenges === [], implode(' | ', $challenges));
check('[stale password] ?admin and ?reveal answer such a request with 401 and the ordinary login form, saying the saved password doesn\'t match', $stA === 401 && $stR === 401 && str_contains($stBodyA, 'name="access_code"') && str_contains($stBodyR, 'name="access_code"') && str_contains($stBodyA, 'saved login') && str_contains($stBodyR, 'saved login'));
check('[stale password] typing the current password into the form still logs you in while the browser keeps sending the old one', $stLoginStatus === 302);
check('[stale password] the source never sends a WWW-Authenticate header at all (a guard against it creeping back)', !preg_match('/header\(\s*[\'"]WWW-Authenticate/i', $indexContents));

// The gallery title is the (unmarked) way to the admin.
[, $homeLogin] = http_get("$baseLogin/");
check('[login] to a visitor the gallery title is plain text, and the lock that leads to the sign-in is an icon in the strip below it', str_contains($homeLogin, '<h1>Summer in Lisbon</h1>') && str_contains($homeLogin, 'class="map-combo-btn lock-link" href="?reveal"') && !str_contains($homeLogin, 'class="title-admin"'));
[, $subLogin] = http_get("$baseLogin/?folder=Sub");
check('[login] on a folder page the lock remembers the folder, so signing in brings you back to that folder', str_contains($subLogin, 'class="map-combo-btn lock-link" href="?reveal&amp;folder=Sub"'));
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
check('[trip] the folder admin page shows the settings form and the location switch (its photos are edited in the gallery)', str_contains($pAdmin, 'Save folder settings') && str_contains($pAdmin, 'Show photo locations publicly for this folder') && !str_contains($pAdmin, 'class="item-editor"'));

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
$eiffelFields = item_get($baseTrip, $tripHdrs, 'Paris/eiffel.jpg');
check('[trip] the editor has the photo\'s location from its EXIF GPS (48.8584, 2.2945) before anything is typed', abs((float) ($eiffelFields['lat'] ?? 0) - 48.8584) < 0.0001 && abs((float) ($eiffelFields['lng'] ?? 0) - 2.2945) < 0.0001);
check('[trip] turning the folder\'s switch on says how many photos now show a location, and how to keep one private', str_contains($r, '1 photo has GPS in the file and now shows a location') && str_contains($r, 'switch off Location'));
check('[trip] ...the GPS was stored for that photo right then (nothing to Save first), so there is no "not saved yet" nagging', array_key_exists('auto', $eiffelFields) && $eiffelFields['auto'] === null && !str_contains($pAdmin, 'loc-unsaved-summary'));
check('[trip] ...and visitors see the map and the location badge straight away', str_contains(http_get("$baseTrip/")[1], 'trip-map-preview') && str_contains(http_get("$baseTrip/?folder=Paris")[1], 'location-badge'));
[, $r] = item_save($parisUrl, $tripHdrs, 'eiffel.jpg', ['caption' => 'The iron lady', 'web' => 'https://en.wikipedia.org/wiki/Eiffel_Tower', 'loc_label' => 'Champ de Mars']);
check('[trip] saving the photo (caption + link, untouched pre-filled GPS) succeeds', str_contains($r, 'class="flash ok"'));
[, $pAdminSaved] = http_get($parisUrl, $tripHdrs);
check('[trip] once saved, the photo has no "not saved yet" place left', ($eiffelSaved = item_get($baseTrip, $tripHdrs, 'Paris/eiffel.jpg')) && array_key_exists('auto', $eiffelSaved) && $eiffelSaved['auto'] === null && ($eiffelSaved['label'] ?? '') === 'Champ de Mars' && ($eiffelSaved['caption'] ?? '') === 'The iron lady');
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
check('[trip] Lisbon\'s tram photo has GPS too (offered, not saved), but Lisbon has not opted in so it is not on the map', abs((float) (item_get($baseTrip, $tripHdrs, 'Lisbon/tram.jpg')['auto']['lat'] ?? 0) - 38.7) < 0.01 && count($clusters3) === 1);
check('[trip] ...and Lisbon\'s admin warns that its GPS stays private until the switch is turned on, naming what turning it on does and how a photo opts out', str_contains($lAdmin, 'Nothing is public while this folder') && str_contains($lAdmin, '<em>Location</em> switch'));
check('[trip] ...the location switch is an instant toggle with the warning beside it in orange (naming how many photos it will publish), not a dialog', str_contains($lAdmin, 'class="instant-form"') && (bool) preg_match('#<div class="warn-note">Turning this on shows the location of 1 photo here to visitors#', $lAdmin) && !str_contains($lAdmin, 'confirm(&quot;Turn on public locations'));
[, $r] = user_submit("$baseTrip/?admin&folder=Lisbon", $lAdmin, 'value="toggle_location_enabled"', ['enabled' => '1'], $tripHdrs);
[, $lAdmin] = http_get("$baseTrip/?admin&folder=Lisbon", $tripHdrs);
[, $tripHome4] = http_get("$baseTrip/");
preg_match('/var clusters = (\[.*?\]);/s', $tripHome4, $cm4); $clusters4 = json_decode($cm4[1] ?? '[]', true);
check('[trip] once Lisbon opts in, its tram photo is on the map with no further Save, so the map has two pins', count($clusters4) === 2);
item_save("$baseTrip/?admin&folder=Lisbon", $tripHdrs, 'tram.jpg', ['caption' => 'Tram 28']);

// 6. Pick a cover photo with the page's own "Set as cover" button.
[, $pAdmin] = http_get($parisUrl, $tripHdrs);
[, $r] = item_cover($parisUrl, $tripHdrs, 'louvre.jpg');
[, $tripHome5] = http_get("$baseTrip/");
check('[trip] after "Set as cover" on louvre.jpg, Paris\'s cover card shows the Louvre photo', (bool) preg_match('#id="group-paris">.*?src="\?img=Paris%2Flouvre\.jpg&amp;size=cover&amp;v=[0-9a-f]{10}"#s', $tripHome5));

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
preg_match('#<span class="page-tools">(.*?)</span>\s*</div>#s', $saleAfter, $saleFooter);
check('[sale] ...and the sitewide "N views" total is hidden from the public page bar too', isset($saleFooter[1]) && !preg_match('/\d+ views/', $saleFooter[1]) && str_contains($saleFooter[1], 'href="?about"') && !str_contains($saleAfter, '<footer'));
// ...but the seller can see every count in the admin.
[, $saleAdmin] = http_get("$baseSale/?admin", ['Authorization: Basic ' . base64_encode('admin:sale-admin')]);
[, , $saleAdminHd] = http_get("$baseSale/?admin", ['Authorization: Basic ' . base64_encode('admin:sale-admin')]);
[, $saleOwner] = http_get("$baseSale/", ['Cookie: ' . extract_cookie_header($saleAdminHd)]);
check('[sale] the seller sees the counts on their own gallery page, though visitors cannot: a photo with one view (alpha_01.jpg) says 1', (bool) preg_match('#<span class="view-badge" data-photo="alpha_01\.jpg"><svg[^>]*><use href="\#i-eye"></use></svg> 1</span>#', $saleOwner));
check('[select] in a gallery grouped by filename the owner still gets Select (private/public in bulk) and the pencil, without the Arrange part that only fits photos shown one by one', str_contains($saleOwner, 'id="select-btn"') && str_contains($saleOwner, 'data-select="private"') && !str_contains($saleOwner, 'data-select="release"') && str_contains($saleOwner, 'class="edit-toggle"'));
check('[sale] ...and an untouched photo reads 0 views', (bool) preg_match('#<span class="view-badge" data-photo="unrelated\.jpg"><svg[^>]*><use href="\#i-eye"></use></svg> 0</span>#', $saleOwner));

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
check('[family] everyone sees everything at first (the lock is already there, as the way to the admin)', str_contains($famHome, 'alt="kids.jpg"') && str_contains($famHome, 'id="group-birthday"') && str_contains($famHome, 'class="map-combo-btn lock-link" href="?reveal"') && str_contains($famHome, '5 photos'));
[, $fAdmin, $fHd] = http_get("$baseFam/?admin", [$famAuth]); $famCookie = extract_cookie_header($fHd); $famHdrs = [$famAuth, "Cookie: $famCookie"];
$r = flag_post("$baseFam/?admin", $fAdmin, $famHdrs, 'private', true, ['kids.jpg']);
check('[family] switching "Private" on for kids.jpg works, in place', ($r['ok'] ?? null) === true && str_contains((string) $r['text'], 'now private'));
[, $famHome2] = http_get("$baseFam/");
check('[family] kids.jpg is gone from the public page, the lightbox list and the photo count (4 now)', !str_contains($famHome2, 'kids.jpg') && str_contains($famHome2, '4 photos'));
[, $bAdmin] = http_get("$baseFam/?admin&folder=Birthday", $famHdrs);
[, $r] = user_submit("$baseFam/?admin&folder=Birthday", $bAdmin, 'value="toggle_folder_restriction"', [], $famHdrs);
check('[family] the "Hide this entire folder from public view" button works', str_contains($r, 'class="flash ok"'));
[, $famHome3] = http_get("$baseFam/");
check('[family] the Birthday folder disappears entirely (no card, count now 2), and typing its address gives a 404', !str_contains($famHome3, 'birthday') && !str_contains($famHome3, 'Birthday') && str_contains($famHome3, '2 photos') && http_get("$baseFam/?folder=Birthday")[0] === 404);
check('[family] its photos are refused by URL too (?img= 404)', http_get("$baseFam/?img=Birthday%2Fcake.jpg&size=thumb")[0] === 404);
[, $hiddenSum] = http_get("$baseFam/?admin&hidden", $famHdrs);
check('[family] the admin\'s "Hidden items" summary lists the folder and the photo, with the not-a-lock warning', str_contains($hiddenSum, 'Birthday') && str_contains($hiddenSum, 'kids.jpg') && str_contains((string) http_get("$baseFam/?admin", $famHdrs)[1], 'by their direct address'));
[, $famAdminPage] = http_get("$baseFam/?admin", $famHdrs);
check('[family] the Private tab links each hidden folder to its settings, and each hidden photo to its editor in the gallery', str_contains($famAdminPage, 'href="?admin&amp;folder=Birthday#settings"') && str_contains($famAdminPage, 'href="?photo=' . rawurlencode('Birthday/kids.jpg') . '&amp;edit=1"') || str_contains($famAdminPage, 'href="?photo=kids.jpg&amp;edit=1"'));
check('[family] ...and that link really opens the photo with its editor: the owner\'s viewer starts on it and opens the editor', (function () use ($baseFam, $famHdrs) { $p = http_get("$baseFam/?photo=kids.jpg&edit=1", $famHdrs)[1]; return str_contains($p, 'const initialPhoto = "kids.jpg"') && str_contains($p, 'lbEdit.open()'); })());
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
$r = flag_post("$baseFam/?admin&folder=Birthday", $bAdmin, $famHdrs, 'private', false, ['cake.jpg']);
check('[family] switching "Private" off for cake.jpg inside the private folder stores a public override, and says so', ($r['ok'] ?? null) === true && str_contains((string) $r['text'], 'now public') && (read_data_file("$fxFam/Birthday/.gallery-items.php")['items']['cake.jpg']['restriction'] ?? null) === 'public');
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
check('[archive] it is a normal folder in the admin\'s folder list', str_contains($arcAdm, 'folder=OldTrip2020'));
[, $r] = user_submit("$baseArc/?admin&folder=OldTrip2020", $arcAdm, 'value="save_folder_identity"', ['display_name' => '2020 Archive', 'description' => 'From the old disk.'], $arcHdrs);
[, $arcAdm] = http_get("$baseArc/?admin&folder=OldTrip2020", $arcHdrs);
item_save("$baseArc/?admin&folder=OldTrip2020", $arcHdrs, 'old1.jpg', ['caption' => 'Before everything']);
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
    [, $roSave] = item_save("$baseRo2/?admin", $roHdrs, 'a.jpg', ['caption' => 'will not stick']);
    check('[unwritable folder] a save the server cannot write says "Nothing was saved" and why — not "Saved"', str_contains($roSave, 'class="flash error"') && str_contains($roSave, 'Nothing was saved') && str_contains($roSave, 'writable') && !str_contains($roSave, 'class="flash ok"'));
    check('[unwritable folder] ...and nothing was written', !file_exists("$fxRo2/.gallery-items.php"));
    chmod($fxRo2, 0755);
    [, $roPage] = http_get("$baseRo2/?admin", $roHdrs);
    [, $roSaveOk] = item_save("$baseRo2/?admin", $roHdrs, 'a.jpg', ['caption' => 'sticks now']);
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
check('[plain] ...writes only thumbnails and the dates found in the photos (a .gallery-cache folder) into the folder, whatever is requested: no stats, data or settings files', ($newEntries === [] || $newEntries === ['.gallery-cache']) && !array_filter($plainCache, fn($f) => !preg_match('/\.(jpe?g|png|webp)$/i', $f) && $f !== 'taken3.json'));
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

echo "== passwords are kept as hashes in the settings file ==\n";

$fxHp = new_fixture_dir('pwhash');
mkdir("$fxHp/Paris");
make_jpeg("$fxHp/Paris/open.jpg", 600, 400, 200, 80, 60); make_jpeg("$fxHp/Paris/closed.jpg", 600, 400, 60, 80, 200);
write_data_file("$fxHp/Paris/.gallery-items.php", ['items' => ['closed.jpg' => ['restriction' => 'private', 'caption' => 'the private one']], 'groups' => []]);
$hpCfg = "$fxHp/.gallery-config.php";
file_put_contents($hpCfg, cfg_text(['ADMIN_PASSWORD' => 'plain-admin-pw-1', 'PRIVATE_VIEW_PASSWORD' => 'plain view pw', 'AUTO_GROUP_BY_FOLDER' => true]));
copy(__DIR__ . '/../index.php', "$fxHp/index.php");
$portHp = find_free_port(); start_server($fxHp, $portHp); $baseHp = "http://127.0.0.1:$portHp";
$hpPrivThumb = "$baseHp/?img=Paris%2Fclosed.jpg&size=thumb";
// A password written in plain text (an older file, or by hand) still works, and a script's Basic header too.
check('[pw hash] a password still in plain text in the settings file works for a script (Basic header)', http_get("$baseHp/?admin", ['Authorization: Basic ' . base64_encode('x:plain-admin-pw-1')])[0] === 200);
check('[pw hash] ...a script signing in does not rewrite the file: the plain text stays until someone signs in through the form', cfg_read($hpCfg)['ADMIN_PASSWORD'] === 'plain-admin-pw-1');
check('[pw hash] a wrong password is refused', http_post("$baseHp/?reveal", ['login_password' => 'plain view pw '], [], false)[0] === 200);
// The first sign-in with either password turns every plain-text password in the file into a hash.
[$hpSt, , $hpHd] = http_post("$baseHp/?reveal", ['login_password' => 'plain view pw'], [], false);
$hpViewerCookie = extract_cookie_header($hpHd);
$hpAfter = cfg_read($hpCfg);
check('[pw hash] signing in with the private-view password succeeds', $hpSt === 302 && $hpViewerCookie !== '');
check('[pw hash] ...and both passwords in the settings file are now hashes of what they were (the other settings are untouched)', pw_stored_ok($hpAfter['ADMIN_PASSWORD'] ?? null, 'plain-admin-pw-1') && pw_stored_ok($hpAfter['PRIVATE_VIEW_PASSWORD'] ?? null, 'plain view pw') && ($hpAfter['AUTO_GROUP_BY_FOLDER'] ?? null) === true && count($hpAfter) === 3);
check('[pw hash] ...neither plain password appears anywhere in the file, and it is still private to the web server user', !str_contains((string) file_get_contents($hpCfg), 'plain-admin-pw-1') && !str_contains((string) file_get_contents($hpCfg), 'plain view pw') && (fileperms($hpCfg) & 0o077) === 0 && str_starts_with((string) file_get_contents($hpCfg), $cfgGuard));
$hpSecretText = (string) @file_get_contents("$fxHp/.gallery-secret.php");
check('[pw hash] a secret of this install now exists, in a guarded private file that a direct request cannot read', str_starts_with($hpSecretText, $cfgGuard) && (bool) preg_match('/^[0-9a-f]{64}$/m', $hpSecretText) && ((fileperms("$fxHp/.gallery-secret.php") ?: 0) & 0o077) === 0 && http_get("$baseHp/.gallery-secret.php")[0] === 404);
check('[pw hash] the cookie the sign-in set shows the private items', http_get($hpPrivThumb)[0] === 404 && http_get($hpPrivThumb, ["Cookie: $hpViewerCookie"])[0] === 200);
// Both passwords keep working, with the same words, once hashed.
[$hpS2, , $hpHd2] = http_post("$baseHp/?reveal", ['login_password' => 'plain view pw'], [], false);
check('[pw hash] the private-view password still works once hashed', $hpS2 === 302 && http_get($hpPrivThumb, ['Cookie: ' . extract_cookie_header($hpHd2)])[0] === 200);
[$hpS3, , $hpHd3] = http_post("$baseHp/?reveal", ['login_password' => 'plain-admin-pw-1'], [], false);
check('[pw hash] the admin password still works once hashed, through the lock, and opens the admin', $hpS3 === 303 && str_contains(http_get("$baseHp/?admin", ['Cookie: ' . extract_cookie_header($hpHd3)])[1], 'Gallery Admin'));
check('[pw hash] ...and through ?admin\'s own form and a script\'s Basic header', http_post("$baseHp/?admin", ['login_password' => 'plain-admin-pw-1'], [], false)[0] === 303 && http_get("$baseHp/?admin", ['Authorization: Basic ' . base64_encode('x:plain-admin-pw-1')])[0] === 200);
// What someone holding a copy of the file has is no password.
$hpStoredAdmin = cfg_read($hpCfg)['ADMIN_PASSWORD']; $hpStoredView = cfg_read($hpCfg)['PRIVATE_VIEW_PASSWORD'];
check('[pw hash] the hash from the file is not itself a password: typed into either form it is refused', http_post("$baseHp/?reveal", ['login_password' => $hpStoredAdmin], [], false)[0] === 200 && http_post("$baseHp/?reveal", ['login_password' => $hpStoredView], [], false)[0] === 200 && http_post("$baseHp/?admin", ['login_password' => $hpStoredAdmin], [], false)[0] === 200);
check('[pw hash] ...and neither is a Basic header carrying it', http_get("$baseHp/?admin", ['Authorization: Basic ' . base64_encode('x:' . $hpStoredAdmin)])[0] === 401);
// ...nor can the cookie be made from it: the token is keyed with the secret, which is not in that file.
$hpRevealName = 'gallery_reveal_' . substr(hash('sha256', realpath($fxHp)), 0, 10);
$hpForged = [hash('sha256', $hpStoredView . '|' . realpath($fxHp)), hash_hmac('sha256', 'reveal|' . $hpStoredView . '|' . realpath($fxHp), ''), hash('sha256', 'admin-reveal|' . $hpStoredAdmin . '|' . realpath($fxHp)), hash_hmac('sha256', 'admin-reveal|' . $hpStoredAdmin . '|' . realpath($fxHp), '')];
$hpForgedOk = false; foreach ($hpForged as $f) if (http_get($hpPrivThumb, ["Cookie: $hpRevealName=$f"])[0] === 200) $hpForgedOk = true;
check('[pw hash] a cookie made from the hashes in the file (the old way, or with no secret) shows nothing', !$hpForgedOk);
// A hash put in the file by hand is accepted as it is, and left alone.
$hpHandHash = password_hash('pasted-by-hand-9', PASSWORD_DEFAULT);
file_put_contents($hpCfg, cfg_text(['ADMIN_PASSWORD' => $hpHandHash, 'AUTO_GROUP_BY_FOLDER' => true]));
$hpBytes = (string) file_get_contents($hpCfg);
check('[pw hash] a hash pasted into the settings file by hand is accepted, the plain words are not the hash, and signing in leaves the file exactly as it was', http_post("$baseHp/?admin", ['login_password' => 'pasted-by-hand-9'], [], false)[0] === 303 && http_post("$baseHp/?admin", ['login_password' => $hpHandHash], [], false)[0] === 200 && (string) file_get_contents($hpCfg) === $hpBytes);
// A folder the server can't write to: the plain text keeps working and nothing is touched.
file_put_contents($hpCfg, cfg_text(['ADMIN_PASSWORD' => 'plain-admin-pw-1', 'AUTO_GROUP_BY_FOLDER' => true]));
$hpBytes = (string) file_get_contents($hpCfg);
chmod($fxHp, 0555);
$hpRo = http_post("$baseHp/?admin", ['login_password' => 'plain-admin-pw-1'], [], false)[0];
chmod($fxHp, 0775);
check('[pw hash] when the folder can\'t be written, signing in still works and the file is left as it was (the plain text stays until it can be replaced)', $hpRo === 303 && (string) file_get_contents($hpCfg) === $hpBytes);
// Changing a password from Site settings stores a hash, keeps the owner signed in, and signs the viewers out.
[$hpS4, , $hpHd4] = http_post("$baseHp/?admin", ['login_password' => 'plain-admin-pw-1'], [], false);
$hpAdminCookie = extract_cookie_header($hpHd4);
[, $hpAdminPg] = http_get("$baseHp/?admin", ["Cookie: $hpAdminCookie"]);
check('[pw hash] (setup) the owner is signed in, and the plain password that was left in the file is a hash by now', $hpS4 === 303 && pw_stored_ok(cfg_read($hpCfg)['ADMIN_PASSWORD'] ?? null, 'plain-admin-pw-1'));
user_submit("$baseHp/?admin", $hpAdminPg, 'value="save_site_settings"', ['pw_admin' => 'changed-admin-pw-2', 'pw_admin2' => 'changed-admin-pw-2', 'pw_private' => 'new view pw', 'pw_private2' => 'new view pw'], ["Cookie: $hpAdminCookie"]);
$hpNow = cfg_read($hpCfg);
check('[pw hash] changing both passwords in Site settings stores two new hashes, and nothing readable', pw_stored_ok($hpNow['ADMIN_PASSWORD'] ?? null, 'changed-admin-pw-2') && pw_stored_ok($hpNow['PRIVATE_VIEW_PASSWORD'] ?? null, 'new view pw') && !str_contains((string) file_get_contents($hpCfg), 'changed-admin-pw-2'));
check('[pw hash] ...the new ones work and the old admin password does not', http_post("$baseHp/?admin", ['login_password' => 'changed-admin-pw-2'], [], false)[0] === 303 && http_post("$baseHp/?admin", ['login_password' => 'plain-admin-pw-1'], [], false)[0] === 200 && http_post("$baseHp/?reveal", ['login_password' => 'new view pw'], [], false)[0] === 302);
check('[pw hash] ...a viewer who had the old private-view cookie is shut out again', http_get($hpPrivThumb, ["Cookie: $hpViewerCookie"])[0] === 404);

/* --------------------------------------------------------------------- */

echo "== downloading the original (signed-in visitors), with a hidden location taken out of the copy ==\n";

// The code that finds and blanks a location, taken out of index.php so every file format can be tried directly.
$scrubSrc = (string) file_get_contents(__DIR__ . '/../index.php');
$scrubFrom = strpos($scrubSrc, "/* -----------------------------------------------------------------------\n * A copy of an original without its location.");
$scrubTo = strpos($scrubSrc, "/* -----------------------------------------------------------------------\n * ?archive: every original");
eval(substr($scrubSrc, $scrubFrom, $scrubTo - $scrubFrom));
/** What would be sent for $path: the file with its plan laid over it (null if it may not be sent). */
$scrubbed = function ($path) {
    $plan = location_scrub_plan($path);
    if ($plan === null) return null;
    $fh = fopen($path, 'rb'); $out = '';
    stream_with_patches($fh, filesize($path), $plan, function ($c) use (&$out) { $out .= $c; });
    fclose($fh);
    return $out;
};
$latBytes = pack('VV', 3042, 100); $lngBytes = pack('VV', 1507, 100);   // the minutes of the test photos' coordinates
$fxSc = new_fixture_dir('scrubunit');
$gpsDMS = ['N', [[48, 1], [51, 1], [3042, 100]], 'E', [[2, 1], [21, 1], [1507, 100]]];
$box = fn ($type, $body) => pack('N', 8 + strlen($body)) . $type . $body;
$tiffOf = function ($jpeg) { $at = strpos($jpeg, "Exif\0\0"); $len = unpack('n', $jpeg, $at - 2)[1]; return substr($jpeg, $at + 6, $len - 8); };
make_gps_jpeg("$fxSc/gps.jpg", ...$gpsDMS);
$gpsJpeg = (string) file_get_contents("$fxSc/gps.jpg");
$gpsScrubbed = $scrubbed("$fxSc/gps.jpg");
$sosAt = strrpos($gpsJpeg, "\xFF\xDA");
check('[scrub] (setup) the test photo carries its coordinates', str_contains($gpsJpeg, $latBytes) && str_contains($gpsJpeg, $lngBytes));
check('[scrub] JPEG: the coordinates are gone from the copy, which is exactly as long as the original and has the picture byte for byte', is_string($gpsScrubbed) && !str_contains($gpsScrubbed, $latBytes) && !str_contains($gpsScrubbed, $lngBytes) && strlen($gpsScrubbed) === strlen($gpsJpeg) && substr($gpsScrubbed, $sosAt) === substr($gpsJpeg, $sosAt));
file_put_contents("$fxSc/out.jpg", (string) $gpsScrubbed);
$exifBefore = @exif_read_data("$fxSc/gps.jpg", 'GPS', true); $exifAfter = @exif_read_data("$fxSc/out.jpg", 'GPS', true);
check('[scrub] JPEG: an EXIF reader finds GPS in the original and none in the copy, and the copy is still a valid picture', isset($exifBefore['GPS']['GPSLatitude']) && !isset($exifAfter['GPS']['GPSLatitude']) && @imagecreatefromjpeg("$fxSc/out.jpg") !== false);
check('[scrub] the file on disk is not changed by any of this', (string) file_get_contents("$fxSc/gps.jpg") === $gpsJpeg);
make_jpeg("$fxSc/plain.jpg", 40, 30, 5, 6, 7);
check('[scrub] a JPEG with no metadata needs nothing: nothing to replace, so it is sent as it is', location_scrub_plan("$fxSc/plain.jpg") === []);
// XMP and IPTC place fields.
$xmp = 'http://ns.adobe.com/xap/1.0/' . "\0" . '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF><rdf:Description exif:GPSLatitude="48,51.5N" exif:GPSLongitude=\'2,21.2E\' photoshop:City="Paris"><exif:GPSAltitude>35/1</exif:GPSAltitude><dc:title>Kept title</dc:title></rdf:Description></rdf:RDF></x:xmpmeta>';
$iptcRec = fn ($tag, $val) => "\x1C\x02" . chr($tag) . pack('n', strlen($val)) . $val;
$iptc = $iptcRec(90, 'Paris') . $iptcRec(101, 'France') . $iptcRec(120, 'A caption that stays');
$iptcBlock = "Photoshop 3.0\0" . '8BIM' . pack('n', 0x0404) . "\0\0" . pack('N', strlen($iptc)) . $iptc . (strlen($iptc) % 2 ? "\0" : '');
$withMeta = substr($gpsJpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($xmp) + 2) . $xmp . "\xFF\xED" . pack('n', strlen($iptcBlock) + 2) . $iptcBlock . substr($gpsJpeg, 2);
file_put_contents("$fxSc/meta.jpg", $withMeta);
$metaScrubbed = (string) $scrubbed("$fxSc/meta.jpg");
check('[scrub] XMP: the GPS and city properties are blanked (attribute and element forms), the other properties are kept, and the length is unchanged', strlen($metaScrubbed) === strlen($withMeta) && !str_contains($metaScrubbed, '48,51.5N') && !str_contains($metaScrubbed, '2,21.2E') && !str_contains($metaScrubbed, 'Paris') && !str_contains($metaScrubbed, '35/1') && str_contains($metaScrubbed, 'Kept title') && !str_contains($metaScrubbed, $latBytes));
check('[scrub] IPTC: the city and country are blanked, the caption is kept', !str_contains($metaScrubbed, 'France') && str_contains($metaScrubbed, 'A caption that stays'));
// A file that can't be read safely is refused, not sent as it is.
file_put_contents("$fxSc/bad.jpg", substr($gpsJpeg, 0, 2) . "\xFF\xE1" . pack('n', 5000) . "Exif\0\0II*\0" . substr($gpsJpeg, 2, 40));
check('[scrub] a JPEG whose metadata runs past the end of the file is refused (null), not passed through with its coordinates', location_scrub_plan("$fxSc/bad.jpg") === null);
file_put_contents("$fxSc/notajpeg.jpg", 'this is not a picture');
check('[scrub] ...and so is a file that is not a JPEG at all', location_scrub_plan("$fxSc/notajpeg.jpg") === null);
// PNG (eXIf chunk with a checksum) and WebP.
$im = imagecreatetruecolor(8, 8); ob_start(); imagepng($im); $png = ob_get_clean(); imagedestroy($im);
$tiff = $tiffOf($gpsJpeg);
$pngChunk = fn ($type, $data) => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
$pngWith = substr($png, 0, 33) . $pngChunk('eXIf', $tiff) . substr($png, 33);
file_put_contents("$fxSc/gps.png", $pngWith);
$pngScrubbed = (string) $scrubbed("$fxSc/gps.png");
$crcOk = true; $pp = 8; while ($pp + 12 <= strlen($pngScrubbed)) { $l = unpack('N', $pngScrubbed, $pp)[1]; $t = substr($pngScrubbed, $pp + 4, 4); if (unpack('N', $pngScrubbed, $pp + 8 + $l)[1] !== crc32($t . substr($pngScrubbed, $pp + 8, $l))) $crcOk = false; $pp += 12 + $l; }
check('[scrub] PNG: the coordinates in its eXIf chunk are gone, every chunk\'s checksum still matches, and it is still a picture', str_contains($pngWith, $latBytes) && !str_contains($pngScrubbed, $latBytes) && strlen($pngScrubbed) === strlen($pngWith) && $crcOk && (function () use ($pngScrubbed, $fxSc) { file_put_contents("$fxSc/o.png", $pngScrubbed); return @imagecreatefrompng("$fxSc/o.png") !== false; })());
$webpBody = 'WEBP' . 'VP8L' . pack('V', 4) . "\0\0\0\0" . 'EXIF' . pack('V', strlen($tiff)) . $tiff . (strlen($tiff) % 2 ? "\0" : '');
file_put_contents("$fxSc/gps.webp", 'RIFF' . pack('V', strlen($webpBody)) . $webpBody);
$webpScrubbed = (string) $scrubbed("$fxSc/gps.webp");
check('[scrub] WebP: the coordinates in its EXIF chunk are gone and the length is unchanged', str_contains((string) file_get_contents("$fxSc/gps.webp"), $latBytes) && !str_contains($webpScrubbed, $latBytes) && strlen($webpScrubbed) === filesize("$fxSc/gps.webp"));
// HEIC: the EXIF block lives in an item the file's index points to.
$heic = function () use ($box, $tiff) {
    $ftyp = $box('ftyp', 'heic' . pack('N', 0) . 'mif1heic');
    $item = pack('N', 0) . $tiff;
    $mk = function ($offset) use ($box, $item) {
        $infe = $box('infe', "\x02\0\0\0" . pack('n', 1) . pack('n', 0) . 'Exif' . "Exif\0");
        $iinf = $box('iinf', "\0\0\0\0" . pack('n', 1) . $infe);
        $iloc = $box('iloc', "\0\0\0\0" . "\x44\x00" . pack('n', 1) . pack('n', 1) . pack('n', 0) . pack('n', 1) . pack('N', $offset) . pack('N', strlen($item)));
        return $box('meta', "\0\0\0\0" . $iinf . $iloc);
    };
    $meta = $mk(0);
    $offset = strlen($ftyp) + strlen($meta) + 8;
    return $ftyp . $mk($offset) . $box('mdat', $item . 'PIXELS-OF-THE-PICTURE');
};
file_put_contents("$fxSc/gps.heic", $heic());
$heicScrubbed = (string) $scrubbed("$fxSc/gps.heic");
check('[scrub] HEIC: the coordinates in the EXIF item are gone, the pixels and the length are untouched', str_contains($heic(), $latBytes) && !str_contains($heicScrubbed, $latBytes) && !str_contains($heicScrubbed, $lngBytes) && strlen($heicScrubbed) === strlen($heic()) && str_ends_with($heicScrubbed, 'PIXELS-OF-THE-PICTURE'));
// MP4/MOV: the location atom and QuickTime's keyed location.
$iso = '+48.8584+002.2945/';
$xyz = $box("\xA9xyz", pack('nn', strlen($iso), 0x15C7) . $iso);
$keyName = fn ($n) => pack('N', 8 + strlen($n)) . 'mdta' . $n;
$keys = $box('keys', "\0\0\0\0" . pack('N', 2) . $keyName('com.apple.quicktime.make') . $keyName('com.apple.quicktime.location.ISO6709'));
$ilstItem = fn ($index, $value) => $box(pack('N', $index), $box('data', pack('N', 1) . pack('N', 0) . $value));
$qtMeta = $box('meta', $box('hdlr', "\0\0\0\0\0\0\0\0mdta" . str_repeat("\0", 13)) . $keys . $box('ilst', $ilstItem(1, 'Apple') . $ilstItem(2, $iso)));
$mvhd = $box('mvhd', pack('N', 0) . pack('N', 0) . pack('N', 0) . pack('N', 1) . pack('N', 3));
$mp4 = $box('ftyp', 'isom' . pack('N', 0)) . $box('moov', $mvhd . $box('udta', $xyz) . $qtMeta) . $box('mdat', 'VIDEO-BYTES');
file_put_contents("$fxSc/gps.mov", $mp4);
$mp4Scrubbed = (string) $scrubbed("$fxSc/gps.mov");
check('[scrub] MP4/MOV: both the location atom and QuickTime\'s location item are blanked, the other items and the video are untouched, and the length is unchanged', substr_count($mp4, '48.8584') === 2 && !str_contains($mp4Scrubbed, '48.8584') && !str_contains($mp4Scrubbed, '2.2945') && str_contains($mp4Scrubbed, 'Apple') && str_ends_with($mp4Scrubbed, 'VIDEO-BYTES') && strlen($mp4Scrubbed) === strlen($mp4));

// Through the server.
$fxDl = new_fixture_dir('download');
copy("$fxSc/gps.jpg", "$fxDl/hidden.jpg"); copy("$fxSc/gps.jpg", "$fxDl/shown.jpg"); copy("$fxSc/gps.jpg", "$fxDl/not set.jpg"); copy("$fxSc/gps.jpg", "$fxDl/secret.jpg"); copy("$fxSc/plain.jpg", "$fxDl/plain.jpg"); copy("$fxSc/bad.jpg", "$fxDl/bad.jpg"); copy("$fxSc/gps.mov", "$fxDl/clip.mov");
$dlLoc = fn ($vis) => ['location' => ['lat' => 48.85, 'lng' => 2.35, 'label' => ''], 'locationVisibility' => $vis];
write_data_file("$fxDl/.gallery-items.php", ['items' => ['hidden.jpg' => $dlLoc('hide'), 'shown.jpg' => $dlLoc('show'), 'clip.mov' => $dlLoc('hide'), 'secret.jpg' => ['restriction' => 'private']], 'groups' => [], 'locationEnabled' => true]);
$dlCfg = fn ($public) => cfg_text(['ADMIN_PASSWORD' => 'download-admin-1', 'PRIVATE_VIEW_PASSWORD' => 'download view', 'ENABLE_PHOTO_LOCATION' => true, 'ENABLE_VIDEO' => true] + ($public ? ['ENABLE_PUBLIC_DOWNLOAD' => true] : []));
file_put_contents("$fxDl/.gallery-config.php", $dlCfg(false));
copy(__DIR__ . '/../index.php', "$fxDl/index.php");
$portDl = find_free_port(); start_server($fxDl, $portDl); $baseDl = "http://127.0.0.1:$portDl";
[, , $dlAdminHd] = http_post("$baseDl/?admin", ['login_password' => 'download-admin-1'], [], false);
$dlAdmin = ['Cookie: ' . extract_cookie_header($dlAdminHd)];
[, , $dlViewerHd] = http_post("$baseDl/?reveal", ['login_password' => 'download view'], [], false);
$dlViewer = ['Cookie: ' . extract_cookie_header($dlViewerHd)];
$dlOrig = (string) file_get_contents("$fxDl/hidden.jpg");
$dlMov = (string) file_get_contents("$fxDl/clip.mov");
check('[download] with the setting off (the default), a visitor who is not signed in gets nothing (404), for every photo, and a cookie that merely has the right name changes nothing', http_get("$baseDl/?original=plain.jpg")[0] === 404 && http_get("$baseDl/?original=shown.jpg")[0] === 404 && http_get("$baseDl/?original=plain.jpg", ['Cookie: gallery_reveal_x=1'])[0] === 404);
[$dlSt, $dlBody, $dlHd] = http_get("$baseDl/?original=hidden.jpg", $dlAdmin);
$dlDisp = ''; foreach ($dlHd as $h) if (stripos($h, 'Content-Disposition:') === 0) $dlDisp = $h;
check('[download] the owner is sent the original as a download (an attachment with its own name)', $dlSt === 200 && str_contains($dlDisp, 'attachment') && str_contains($dlDisp, 'filename="hidden.jpg"'));
check('[download] ...exactly as it is on disk, GPS and all, even for a photo whose location is hidden', $dlBody === $dlOrig && str_contains($dlBody, $latBytes));
check('[download] someone with the private-view password is trusted with the same: the file exactly as it is, location included, photos and videos alike', http_get("$baseDl/?original=hidden.jpg", $dlViewer)[1] === $dlOrig && http_get("$baseDl/?original=not%20set.jpg", $dlViewer)[1] === $dlOrig && http_get("$baseDl/?original=clip.mov", $dlViewer)[1] === $dlMov);
check('[download] ...including a private photo, which only they can have', http_get("$baseDl/?original=secret.jpg", $dlViewer)[0] === 200 && http_get("$baseDl/?original=secret.jpg", $dlAdmin)[0] === 200);
$dlPageOff = http_get("$baseDl/")[1];
check('[download] with the setting off the viewer\'s download button is not on the public page', !str_contains($dlPageOff, 'id="lightbox-download"'));
// Switched on: everyone may download; a visitor who isn't signed in gets the location taken out where the gallery hides it.
file_put_contents("$fxDl/.gallery-config.php", $dlCfg(true));
// (The settings file was written afresh with the passwords in plain text, so everyone signs in again.)
[, , $dlAdminHd] = http_post("$baseDl/?admin", ['login_password' => 'download-admin-1'], [], false);
$dlAdmin = ['Cookie: ' . extract_cookie_header($dlAdminHd)];
[, , $dlViewerHd] = http_post("$baseDl/?reveal", ['login_password' => 'download view'], [], false);
$dlViewer = ['Cookie: ' . extract_cookie_header($dlViewerHd)];
[$aSt, $aBody] = http_get("$baseDl/?original=hidden.jpg");
check('[download] with the setting on, a visitor who is not signed in is sent a photo whose location is hidden with the location taken out: the same length, the picture untouched', $aSt === 200 && strlen($aBody) === strlen($dlOrig) && !str_contains($aBody, $latBytes) && !str_contains($aBody, $lngBytes) && substr($aBody, $sosAt) === substr($dlOrig, $sosAt));
check('[download] ...also a photo that has no location setting at all (its folder\'s switch is on, but it has none)', !str_contains(http_get("$baseDl/?original=not%20set.jpg")[1], $latBytes));
check('[download] ...a photo whose location the gallery shows, and one with nothing to remove, are sent as they are', http_get("$baseDl/?original=shown.jpg")[1] === $dlOrig && http_get("$baseDl/?original=plain.jpg")[1] === (string) file_get_contents("$fxDl/plain.jpg"));
[$badSt, $badBody] = http_get("$baseDl/?original=bad.jpg");
check('[download] ...a damaged file whose location can\'t be removed safely is refused (422), and the owner can still have it', $badSt === 422 && !str_contains($badBody, 'Exif') && http_get("$baseDl/?original=bad.jpg", $dlAdmin)[0] === 200);
[$clipSt, $clipBody] = http_get("$baseDl/?original=clip.mov");
check('[download] a video is treated the same way: its location is taken out for a visitor who is not signed in and left in for the owner', $clipSt === 200 && strlen($clipBody) === strlen($dlMov) && !str_contains($clipBody, '48.8584') && http_get("$baseDl/?original=clip.mov", $dlAdmin)[1] === $dlMov);
check('[download] a private photo is still a 404 for a visitor who is not signed in, whatever the setting', http_get("$baseDl/?original=secret.jpg")[0] === 404);
check('[download] a path outside the gallery, a folder and a missing file are all 404', http_get("$baseDl/?original=../index.php")[0] === 404 && http_get("$baseDl/?original=index.php", $dlAdmin)[0] === 404 && http_get("$baseDl/?original=nope.jpg", $dlAdmin)[0] === 404 && http_get("$baseDl/?original[]=plain.jpg", $dlAdmin)[0] === 404);
check('[download] the viewer\'s download button is on the public page now, and on the signed-in pages', str_contains(http_get("$baseDl/")[1], 'id="lightbox-download"') && str_contains(http_get("$baseDl/", $dlViewer)[1], 'id="lightbox-download"') && str_contains(http_get("$baseDl/", $dlAdmin)[1], 'id="lightbox-download"'));
// Locations the gallery hides from visitors are not hidden from people who are signed in.
$dlBadges = fn ($headers) => substr_count(http_get("$baseDl/", $headers)[1], 'class="item-badge location-badge"');
check('[locations] a visitor who is not signed in sees the location badge only on the photo whose location is shown (the two with the location switched off show none)', $dlBadges([]) === 1);
check('[locations] the owner, and anyone with the private-view password, see every saved location (the hidden photo and the hidden video too)', $dlBadges($dlAdmin) === 3 && $dlBadges($dlViewer) === 3);
check('[locations] a cookie that merely has the right name changes nothing', $dlBadges(['Cookie: gallery_reveal_x=1']) === 1);
// The picture the viewer shows is the original too, so it is held to the same rule.
[$fSt, $fBody, $fHd] = http_get("$baseDl/?img=hidden.jpg&size=full");
$fCache = ''; foreach ($fHd as $h) if (stripos($h, 'Cache-Control:') === 0) $fCache = $h;
check('[viewer image] the full-size picture is the original file, so a visitor who is not signed in is sent it with a hidden location taken out, and kept out of shared caches', $fSt === 200 && strlen($fBody) === strlen($dlOrig) && !str_contains($fBody, $latBytes) && str_contains($fCache, 'private'));
check('[viewer image] ...a signed-in visitor is sent it as it is, and so is anyone for a photo whose location is shown', http_get("$baseDl/?img=hidden.jpg&size=full", $dlViewer)[1] === $dlOrig && http_get("$baseDl/?img=hidden.jpg&size=full", $dlAdmin)[1] === $dlOrig && http_get("$baseDl/?img=shown.jpg&size=full")[1] === $dlOrig);
check('[viewer image] ...and a photo whose location can\'t be removed is never sent with it', !str_contains(http_get("$baseDl/?img=bad.jpg&size=full")[1], 'Exif'));
check('[viewer image] ...the little pictures were never the original, so they carry no location either', !str_contains(http_get("$baseDl/?img=hidden.jpg&size=thumb")[1], $latBytes));
[$vSt, $vBody] = http_get("$baseDl/?video=clip.mov");
check('[viewer video] the video itself is held to the same rule: a visitor who is not signed in is sent it with the location blanked, a signed-in one as it is', $vSt === 200 && strlen($vBody) === strlen($dlMov) && !str_contains($vBody, '48.8584') && http_get("$baseDl/?video=clip.mov", $dlViewer)[1] === $dlMov && http_get("$baseDl/?video=clip.mov", $dlAdmin)[1] === $dlMov);
$atomAt = strpos($dlMov, '48.8584');
[$rSt, $rBody] = http_get("$baseDl/?video=clip.mov", ['Range: bytes=' . ($atomAt - 10) . '-' . ($atomAt + 30)]);
check('[viewer video] ...and so is a part of it fetched with a Range request (what a player does when seeking): the same bytes as that part of the whole, blanked', $rSt === 206 && $rBody === substr($vBody, $atomAt - 10, 41) && !str_contains($rBody, '48.8584'));
check('[download] the files on the server were not changed by any of it', (string) file_get_contents("$fxDl/hidden.jpg") === $dlOrig && (string) file_get_contents("$fxDl/clip.mov") === $dlMov);
// Site settings offers the switch, off, with its warning.
[, $dlSettings] = http_get("$baseDl/?admin", $dlAdmin);
check('[download] Site settings has the "Let visitors download originals" switch, with its orange warning', str_contains($dlSettings, 'name="set_ENABLE_PUBLIC_DOWNLOAD"') && str_contains($dlSettings, 'Anyone can save every photo at full resolution'));

/* --------------------------------------------------------------------- */

echo "== archive: every original as one streamed ZIP (signed-in visitors only) ==\n";

$haveUnzip = trim((string) shell_exec('command -v unzip')) !== '';
$haveZipPy = trim((string) shell_exec('command -v python3')) !== '';
$zipTest = function ($file) { return (string) shell_exec('unzip -tq ' . escapeshellarg($file) . ' 2>&1'); };
$zipNames = function ($file) { $o = trim((string) shell_exec('unzip -Z1 ' . escapeshellarg($file) . ' 2>/dev/null')); return $o === '' ? [] : explode("\n", $o); };
$zipPy = function ($file) { return trim((string) shell_exec('python3 -c ' . escapeshellarg('import sys,zipfile; z=zipfile.ZipFile(sys.argv[1]); print(z.testzip() or "OK", len(z.namelist()))') . ' ' . escapeshellarg($file) . ' 2>&1')); };
$zipEntry = function ($file, $name) { return (string) shell_exec('unzip -p ' . escapeshellarg($file) . ' ' . escapeshellarg($name) . ' 2>/dev/null'); };

// The archive code taken out of index.php, so the ZIP64 form (for a gallery over 4 GB) can be tried on small files.
$arSrc = (string) file_get_contents(__DIR__ . '/../index.php');
$arFrom = strpos($arSrc, "/* -----------------------------------------------------------------------\n * ?archive: every original");
$arTo = strpos($arSrc, '/** ?original=: the original file as a download.');
eval(substr($arSrc, $arFrom, $arTo - $arFrom));
$fxArU = new_fixture_dir('archiveunit');
file_put_contents("$fxArU/one.bin", random_bytes(3000)); file_put_contents("$fxArU/two.bin", ''); file_put_contents("$fxArU/three.bin", random_bytes(2500000));
$unitEntries = [];
foreach (['one.bin' => 'top/one.bin', 'two.bin' => 'top/sub/empty.bin', 'three.bin' => 'top/sub/Tür ünï.bin'] as $f => $n) $unitEntries[] = ['name' => $n, 'path' => "$fxArU/$f", 'size' => filesize("$fxArU/$f"), 'mtime' => filemtime("$fxArU/$f")];
check('[archive] entry names can\'t climb out of the folder they are unpacked into, and keep their folders', archive_entry_name('../../etc/passwd') === 'etc/passwd' && archive_entry_name('/a//b/./c') === 'a/b/c' && archive_entry_name("a\\b") === 'a_b' && archive_entry_name("Tür/ünï.jpg") === "Tür/ünï.jpg");
foreach ([false, true] as $z64) {
    ob_start(); archive_stream($unitEntries, $z64); $zbytes = ob_get_clean();
    $zf = "$fxArU/" . ($z64 ? 'z64' : 'z32') . '.zip'; file_put_contents($zf, $zbytes);
    $label = $z64 ? 'ZIP64' : 'ZIP';
    check("[archive] $label: the size worked out in advance is exactly the size sent", strlen($zbytes) === archive_total_size(array_column($unitEntries, 'size'), array_column($unitEntries, 'name'), $z64));
    if ($haveUnzip) check("[archive] $label: unzip finds no errors, every checksum matches, the names (a folder, an empty file, accents) and the contents are right", str_contains($zipTest($zf), 'No errors detected') && $zipNames($zf) === array_column($unitEntries, 'name') && $zipEntry($zf, 'top/one.bin') === file_get_contents("$fxArU/one.bin") && md5($zipEntry($zf, 'top/sub/Tür ünï.bin')) === md5_file("$fxArU/three.bin"));
    if ($haveZipPy) check("[archive] $label: a second reader (Python's zipfile) agrees", $zipPy($zf) === 'OK 3');
}
$fxAr2 = new_fixture_dir('archive');
mkdir("$fxAr2/Paris/Louvre", 0777, true); mkdir("$fxAr2/Rome");
copy("$fxSc/gps.jpg", "$fxAr2/top.jpg"); make_jpeg("$fxAr2/priv.jpg", 70, 50, 9, 8, 7); make_jpeg("$fxAr2/Paris/b.jpg", 60, 40, 1, 2, 3); make_jpeg("$fxAr2/Paris/Louvre/c.jpg", 50, 50, 4, 5, 6);
make_jpeg("$fxAr2/Paris/Nice dé.jpg", 50, 50, 7, 7, 7); make_jpeg("$fxAr2/Rome/r.jpg", 50, 50, 8, 8, 8); copy("$fxSc/gps.mov", "$fxAr2/clip.mov");
write_data_file("$fxAr2/.gallery-items.php", ['items' => ['priv.jpg' => ['restriction' => 'private'], 'top.jpg' => $dlLoc('hide')], 'groups' => [], 'locationEnabled' => true]);
file_put_contents("$fxAr2/.gallery-config.php", cfg_text(['ADMIN_PASSWORD' => 'archive-admin-1', 'PRIVATE_VIEW_PASSWORD' => 'archive view', 'AUTO_GROUP_BY_FOLDER' => true, 'ENABLE_VIDEO' => true, 'ENABLE_PHOTO_LOCATION' => true]));
copy(__DIR__ . '/../index.php', "$fxAr2/index.php");
$portAr2 = find_free_port(); start_server($fxAr2, $portAr2); $baseAr2 = "http://127.0.0.1:$portAr2";
[, , $arAdminHd] = http_post("$baseAr2/?admin", ['login_password' => 'archive-admin-1'], [], false); $arAdmin = ['Cookie: ' . extract_cookie_header($arAdminHd)];
[, , $arViewerHd] = http_post("$baseAr2/?reveal", ['login_password' => 'archive view'], [], false); $arViewer = ['Cookie: ' . extract_cookie_header($arViewerHd)];
check('[archive] a visitor who is not signed in gets nothing (404), and a cookie that merely has the right name changes nothing', http_get("$baseAr2/?archive")[0] === 404 && http_get("$baseAr2/?archive&folder=Paris")[0] === 404 && http_get("$baseAr2/?archive", ['Cookie: gallery_reveal_x=1'])[0] === 404);
[$aSt, $aBody, $aHd] = http_get("$baseAr2/?archive", $arViewer);
$aH = fn ($name) => (function () use ($aHd, $name) { foreach ($aHd as $h) if (stripos($h, "$name:") === 0) return trim(substr($h, strlen($name) + 1)); return ''; })();
check('[archive] someone with the private-view password is sent a ZIP, as a download, whose announced length is its real length', $aSt === 200 && $aH('Content-Type') === 'application/zip' && str_contains($aH('Content-Disposition'), 'attachment') && str_contains($aH('Content-Disposition'), '.zip') && (int) $aH('Content-Length') === strlen($aBody));
$arZip = "$fxAr2/../" . basename($fxAr2) . '-root.zip'; file_put_contents($arZip, $aBody);
if ($haveUnzip) {
    $names = $zipNames($arZip); sort($names);
    $root = explode('/', $names[0])[0];
    $expect = ["$root/Paris/Louvre/c.jpg", "$root/Paris/Nice dé.jpg", "$root/Paris/b.jpg", "$root/Rome/r.jpg", "$root/clip.mov", "$root/priv.jpg", "$root/top.jpg"]; sort($expect);
    check('[archive] unzip finds no errors, and it holds every photo and video in its folders, the private photo too (they are trusted with it), under one top folder', str_contains($zipTest($arZip), 'No errors detected') && $names === $expect);
    check('[archive] every file in it is byte for byte the original, including a photo whose location the gallery hides and a video with a location (nothing is cleaned for someone trusted)', md5($zipEntry($arZip, "$root/top.jpg")) === md5_file("$fxAr2/top.jpg") && str_contains($zipEntry($arZip, "$root/top.jpg"), $latBytes) && md5($zipEntry($arZip, "$root/clip.mov")) === md5_file("$fxAr2/clip.mov") && md5($zipEntry($arZip, "$root/Paris/Nice dé.jpg")) === md5_file("$fxAr2/Paris/Nice dé.jpg"));
}
if ($haveZipPy) check('[archive] a second reader (Python\'s zipfile) agrees: every checksum matches', $zipPy($arZip) === 'OK 7');
[$fSt, $fBody] = http_get("$baseAr2/?archive&folder=Paris", $arAdmin);
file_put_contents($arZip, $fBody);
if ($haveUnzip) { $fn = $zipNames($arZip); sort($fn); check('[archive] a folder\'s archive (the owner this time) holds that folder and what is below it, named from the folder', $fSt === 200 && str_contains($zipTest($arZip), 'No errors detected') && $fn === ['Paris/Louvre/c.jpg', 'Paris/Nice dé.jpg', 'Paris/b.jpg']); }
check('[archive] a folder that doesn\'t exist, a path out of the gallery and a file are all 404', http_get("$baseAr2/?archive&folder=Nope", $arAdmin)[0] === 404 && http_get("$baseAr2/?archive&folder=../", $arAdmin)[0] === 404 && http_get("$baseAr2/?archive&folder=top.jpg", $arAdmin)[0] === 404);
// Only two at a time: with both places taken the answer is "try again", and it works as soon as one is free.
$arLockBase = sys_get_temp_dir() . '/gallery-archive-' . substr(hash('sha256', realpath($fxAr2)), 0, 10) . '-';
$arHeld = []; foreach ([1, 2] as $i) { $h = fopen($arLockBase . $i . '.lock', 'c'); flock($h, LOCK_EX | LOCK_NB); $arHeld[] = $h; }
[$busySt, $busyBody, $busyHd] = http_get("$baseAr2/?archive", $arViewer);
check('[archive] with two archives already being made, a third is told to try again (503, with Retry-After) instead of tying up the server', $busySt === 503 && (bool) array_filter($busyHd, fn ($h) => stripos($h, 'Retry-After:') === 0) && str_contains($busyBody, 'Try again'));
flock($arHeld[1], LOCK_UN); fclose($arHeld[1]);
check('[archive] ...and it goes through as soon as one is free', http_get("$baseAr2/?archive", $arViewer)[0] === 200);
flock($arHeld[0], LOCK_UN); fclose($arHeld[0]);
// The button.
$arPageAdmin = http_get("$baseAr2/", $arAdmin)[1]; $arPageFolder = http_get("$baseAr2/?folder=Paris", $arViewer)[1];
check('[archive] the owner and the private-view visitor have a line-drawing archive button beside the gallery icon, saying how many originals and how big', (bool) preg_match('#class="map-combo-btn page-archive" href="\?archive" data-bytes="\d+" data-ask="Download all 7 originals \([^)]+\) as one ZIP"#', $arPageAdmin) && str_contains(http_get("$baseAr2/", $arViewer)[1], 'class="map-combo-btn page-archive"'));
check('[archive] on a folder\'s page it is that folder\'s archive, with that folder\'s count', str_contains($arPageFolder, 'href="?archive&amp;folder=Paris"') && str_contains($arPageFolder, 'Download all 3 originals'));
check('[archive] a visitor who is not signed in has no archive button', !str_contains(http_get("$baseAr2/")[1], 'class="map-combo-btn page-archive"') && !str_contains(http_get("$baseAr2/?folder=Paris")[1], 'page-archive" href'));

/* --------------------------------------------------------------------- */

echo "== a real picture for each video (ffmpeg, when the server has it) ==\n";

$fxVt = new_fixture_dir('videopics');
$vtFrame = "$fxVt/_frame.jpg"; make_jpeg($vtFrame, 800, 600, 210, 30, 30);   // what the pretend ffmpeg "takes" from every video: red
$vtLog = "$fxVt/_ffmpeg.log";
$vtMakeBin = function ($path, $exit) use ($vtFrame, $vtLog) {
    file_put_contents($path, "#!/bin/sh\necho \"\$@\" >> " . escapeshellarg($vtLog) . "\nif [ \"\$1\" = \"-version\" ]; then echo 'ffmpeg version pretend'; exit 0; fi\n"
        . ($exit === 0 ? "for a; do last=\"\$a\"; done\ncp " . escapeshellarg($vtFrame) . " \"\$last\"\nexit 0\n" : "exit $exit\n"));
    chmod($path, 0755);
};
$vtOk = "$fxVt/_ffmpeg-ok"; $vtMakeBin($vtOk, 0);
$vtFail = "$fxVt/_ffmpeg-fail"; $vtMakeBin($vtFail, 1);
$vtSite = function ($label, $settings, $videos) use ($vtLog) {
    $d = new_fixture_dir('vp-' . $label);
    foreach ($videos as $name => $secs) { if (str_contains($name, '/')) @mkdir(dirname("$d/$name"), 0777, true); make_minimal_mp4("$d/$name", $secs); }
    file_put_contents("$d/.gallery-config.php", cfg_text($settings + ['ENABLE_VIDEO' => true]));
    copy(__DIR__ . '/../index.php', "$d/index.php");
    $port = find_free_port(); start_server($d, $port);
    return [$d, "http://127.0.0.1:$port"];
};
$vtCount = fn () => (int) trim((string) shell_exec('grep -c -E -e " -ss .* -frames:v" ' . escapeshellarg($vtLog) . ' 2>/dev/null'));   // runs that take a thumbnail's frame (not clips, not strips)
$vtCenter = function ($body) { $im = @imagecreatefromstring($body); if (!$im) return null; $c = imagecolorat($im, (int) (imagesx($im) * 0.9), (int) (imagesy($im) * 0.9)); /* near a corner: the placeholder's play triangle is in the middle */ $r = ($c >> 16) & 255; $g = ($c >> 8) & 255; $b = $c & 255; return [$r, $g, $b, imagesx($im), imagesy($im)]; };
[$vtDir, $vtBase] = $vtSite('ok', ['FFMPEG_PATH' => $vtOk, 'AUTO_GROUP_BY_FOLDER' => true], ['clip.mp4' => 3, "we ird';touch HACKED;.mp4" => 3, 'Sub/inner.mp4' => 3]);
[$vSt, $vBody, $vHd] = http_get("$vtBase/?img=clip.mp4&size=thumb");
$vC = $vtCenter($vBody);
check('[video pictures] with ffmpeg, a video\'s thumbnail is the frame taken from it (here the pretend ffmpeg\'s red picture), cut to the usual thumbnail size, not the dark placeholder', $vSt === 200 && $vC !== null && $vC[0] > 150 && $vC[1] < 80 && max($vC[3], $vC[4]) === 480);
check('[video pictures] ffmpeg was started once, with the video, a seek, one frame and no metadata, never through a shell', $vtCount() === 1 && str_contains((string) file_get_contents($vtLog), '-ss') && str_contains((string) file_get_contents($vtLog), "$vtDir/clip.mp4") && str_contains((string) file_get_contents($vtLog), '-map_metadata -1'));
$vShare = $vtCenter(http_get("$vtBase/?img=clip.mp4&size=share")[1]); $vCover = $vtCenter(http_get("$vtBase/?img=clip.mp4&size=cover")[1]);
check('[video pictures] the share preview and the folder cover come from the same frame (one ffmpeg run for all of them), the cover a square cut', $vShare !== null && $vShare[0] > 150 && max($vShare[3], $vShare[4]) <= 1200 && $vCover !== null && $vCover[0] > 150 && $vCover[3] === $vCover[4] && $vtCount() === 1);
http_get("$vtBase/?img=clip.mp4&size=thumb");
check('[video pictures] it is kept: asking again runs nothing', $vtCount() === 1);
// The short hover clip.
$vtClips = fn () => (int) trim((string) shell_exec('grep -c -e " -t 3 " ' . escapeshellarg($vtLog) . ' 2>/dev/null'));
$vtHome = http_get("$vtBase/")[1];
check('[video previews] with ffmpeg, a video\'s tile carries the address of its hover clip (and a photo\'s does not)', str_contains($vtHome, 'data-preview="?preview=clip.mp4&amp;v=') && substr_count($vtHome, 'data-preview=') === 2);
[$pvSt, $pvBody, $pvHd] = http_get("$vtBase/?preview=clip.mp4");
$pvH = fn ($n) => (function () use ($pvHd, $n) { foreach ($pvHd as $h) if (stripos($h, "$n:") === 0) return trim(substr($h, strlen($n) + 1)); return ''; })();
$pvLog = (string) file_get_contents($vtLog);
check('[video previews] the clip is made once, a few seconds, no sound, no metadata, never through a shell, and sent as video/mp4 with its length', $pvSt === 200 && $pvH('Content-Type') === 'video/mp4' && (int) $pvH('Content-Length') === strlen($pvBody) && $pvBody === file_get_contents($vtFrame) && $vtClips() === 1 && str_contains($pvLog, ' -an ') && str_contains($pvLog, 'libx264') && str_contains($pvLog, '-t 3 -i ' . "$vtDir/clip.mp4"));
[$pvSt2, $pvBody2, $pvHd2] = http_get("$vtBase/?preview=clip.mp4", ['Range: bytes=0-9']);
check('[video previews] it can be fetched in parts (Safari plays nothing from a server that can\'t), and asking again makes nothing new', $pvSt2 === 206 && strlen($pvBody2) === 10 && $pvBody2 === substr($pvBody, 0, 10) && (bool) array_filter($pvHd2, fn ($h) => stripos($h, 'Content-Range: bytes 0-9/' . strlen($pvBody)) === 0) && $vtClips() === 1);
$pvListing = array_diff(scandir("$vtDir/.gallery-cache"), ['.', '..']); http_get("$vtBase/");
check('[video previews] loading the gallery doesn\'t clear the clips away, and the clip is the only .mp4 in the cache', array_diff(scandir("$vtDir/.gallery-cache"), ['.', '..']) === $pvListing && count(glob("$vtDir/.gallery-cache/*.mp4")) === 1);
// While another clip or picture is being made, a request waits its turn instead of being refused.
make_minimal_mp4("$vtDir/late.mp4", 3);
$vtLockPath = sys_get_temp_dir() . '/gallery-ffmpeg-' . substr(hash('sha256', realpath($vtDir)), 0, 10) . '.lock';
$vtHolder = proc_open([PHP_BINARY, '-r', '$h = fopen($argv[1], "c"); flock($h, LOCK_EX); echo "held\n"; sleep(2);', $vtLockPath], [1 => ['pipe', 'w']], $vtHoldPipes);
fgets($vtHoldPipes[1]);   // it has the turn now
$vtT0 = microtime(true); [$lateSt, $lateBody] = http_get("$vtBase/?preview=late.mp4"); $vtWaited = microtime(true) - $vtT0;
proc_close($vtHolder);
check('[video previews] a request that comes while another is being made waits its turn (here about two seconds) and then gets its clip, instead of being refused', $lateSt === 200 && $lateBody === file_get_contents($vtFrame) && $vtWaited > 1.2 && $vtWaited < 10);
// The sprite of frames a finger scrubs through on a phone.
$vtStrips = fn () => (int) trim((string) shell_exec('grep -c -e "tile=10x" ' . escapeshellarg($vtLog) . ' 2>/dev/null'));
if (!is_file("$vtDir/warm.mp4")) make_minimal_mp4("$vtDir/warm.mp4", 3);
$stBefore = $vtStrips();
$stHome = http_get("$vtBase/")[1];
check('[video scrub] with ffmpeg a video tile carries the address of its sprite of frames and its grid (a short video: 10 columns, 1 row), a photo\'s does not', (bool) preg_match('#data-strip="\\?strip=clip\\.mp4&amp;v=[0-9a-f]+" data-strip-cols="10" data-strip-rows="1"#', $stHome));
[$sSt, $sBody, $sHd] = http_get("$vtBase/?strip=clip.mp4");
$sType = ''; foreach ($sHd as $h) if (stripos($h, 'Content-Type:') === 0) $sType = trim(substr($h, 13));
$sLog = (string) file_get_contents($vtLog);
check('[video scrub] the sprite is made once, as one JPEG of square frames across the video, with no sound and no metadata, never through a shell', $sSt === 200 && $sType === 'image/jpeg' && $sBody === file_get_contents($vtFrame) && $vtStrips() === $stBefore + 1 && str_contains($sLog, 'fps=') && str_contains($sLog, 'crop=min(iw') && str_contains($sLog, 'scale=320:320,tile=10x1') && str_contains($sLog, '-an') && str_contains($sLog, '-map_metadata -1'));
http_get("$vtBase/?strip=clip.mp4");
check('[video scrub] it is kept: asking again makes nothing, and a warm-up for a video without one makes it and answers 204 with no body', $vtStrips() === $stBefore + 1 && http_get("$vtBase/?strip=warm.mp4&warm=1")[0] === 204 && $vtStrips() === $stBefore + 2);
check('[video scrub] a tile whose sprite exists says so, so a page of ready sprites asks for nothing', (bool) preg_match('#data-strip="\\?strip=clip\\.mp4[^"]*" data-strip-cols="10" data-strip-rows="1" data-strip-ready="1"#', http_get("$vtBase/")[1]));
make_minimal_mp4("$vtDir/mid.mp4", 20); make_minimal_mp4("$vtDir/long.mp4", 60);
http_get("$vtBase/?strip=mid.mp4&warm=1"); http_get("$vtBase/?strip=long.mp4&warm=1");
$stLogNow = (string) file_get_contents($vtLog); $stHomeLong = http_get("$vtBase/")[1];
check('[video scrub] a longer video gets more frames (a row of ten for every five seconds, up to six rows: a frame every half second from half a minute), and the tile says the grid', str_contains($stLogNow, 'tile=10x4') && str_contains($stLogNow, 'tile=10x6') && (bool) preg_match('#data-strip="\\?strip=mid\\.mp4[^"]*" data-strip-cols="10" data-strip-rows="4"#', $stHomeLong) && (bool) preg_match('#data-strip="\\?strip=long\\.mp4[^"]*" data-strip-cols="10" data-strip-rows="6"#', $stHomeLong));
check('[video scrub] not a photo, a missing video or a path outside the gallery', http_get("$vtBase/?strip=nope.mp4")[0] === 404 && http_get("$vtBase/?strip=../index.php")[0] === 404 && http_get("$vtBase/?strip[]=clip.mp4")[0] === 404);
// Warming: the page asks quietly for the clips of the tiles in view, with nothing to download.
$vtWarmFile = "$vtDir/warm.mp4"; if (!is_file($vtWarmFile)) make_minimal_mp4($vtWarmFile, 3);
$vtBefore = $vtClips();
[$wSt, $wBody] = http_get("$vtBase/?preview=warm.mp4&v=x&warm=1");
check('[video previews] a warm-up request (what the page sends for a tile whose clip isn\'t made) makes the clip and answers 204 with nothing to download', $wSt === 204 && $wBody === '' && $vtClips() === $vtBefore + 1 && count(glob("$vtDir/.gallery-cache/*.mp4")) === 3);
http_get("$vtBase/?preview=warm.mp4&warm=1");
$vtHome2 = http_get("$vtBase/")[1];
check('[video previews] asking again makes nothing, and a tile whose clip exists says so (so a page of ready clips sends no warm-up at all)', $vtClips() === $vtBefore + 1 && str_contains($vtHome2, 'data-preview="?preview=warm.mp4') && (bool) preg_match('#data-preview="\\?preview=warm\\.mp4[^"]*" data-preview-ready="1"#', $vtHome2));
check('[video previews] a warm-up for a video that can\'t be had is a 404 and a missing one too', http_get("$vtBase/?preview=nope.mp4&warm=1")[0] === 404);
check('[video previews] not a photo, a path outside the gallery or a missing video', http_get("$vtBase/?preview=nope.mp4")[0] === 404 && http_get("$vtBase/?preview=../index.php")[0] === 404 && http_get("$vtBase/?preview[]=clip.mp4")[0] === 404);
$vPageBefore = array_diff(scandir("$vtDir/.gallery-cache"), ['.', '..']);
$vHome = http_get("$vtBase/")[1];
$vPageAfter = array_diff(scandir("$vtDir/.gallery-cache"), ['.', '..']);
check('[video pictures] loading the gallery doesn\'t clear them away (the tidy-up of old thumbnails knows them), and the tiles are the plain picture with no browser-frame video laid over them', $vPageBefore === $vPageAfter && count($vPageAfter) >= 4 && !str_contains($vHome, 'class="tile-video"'));
http_get("$vtBase/?img=" . rawurlencode("we ird';touch HACKED;.mp4") . "&size=thumb");
check('[video pictures] a video with quotes, spaces and a semicolon in its name works and nothing it says is run', $vtCount() === 2 && !file_exists("$vtDir/HACKED") && !file_exists("HACKED"));
http_get("$vtBase/?img=Sub%2Finner.mp4&size=thumb");
check('[video pictures] a video in a folder gets its own picture, kept in that folder\'s cache', $vtCount() === 3 && is_dir("$vtDir/Sub/.gallery-cache") && count(glob("$vtDir/Sub/.gallery-cache/*.jpg")) >= 2);
touch("$vtDir/clip.mp4", time() + 5);
http_get("$vtBase/?img=clip.mp4&size=thumb");
check('[video pictures] a video that is replaced gets a new picture', $vtCount() === 4);
check('[video scrub] a private video has no sprite for anyone who is not signed in either, and ffmpeg is not run for the asking', (function () use ($vtDir, $vtBase, $vtStrips) { write_data_file("$vtDir/.gallery-items.php", ['items' => ['clip.mp4' => ['restriction' => 'private']], 'groups' => []]); $n = $vtStrips(); return http_get("$vtBase/?strip=clip.mp4")[0] === 404 && http_get("$vtBase/?strip=clip.mp4&warm=1")[0] === 404 && $vtStrips() === $n; })());
check('[video previews] a private video has no clip for anyone who is not signed in either (the gate is before ffmpeg)', (function () use ($vtDir, $vtBase, $vtClips) { write_data_file("$vtDir/.gallery-items.php", ['items' => ['clip.mp4' => ['restriction' => 'private']], 'groups' => []]); $n = $vtClips(); return http_get("$vtBase/?preview=clip.mp4")[0] === 404 && $vtClips() === $n; })());
check('[video pictures] nobody can have it for a private video (the gate is before ffmpeg), and ffmpeg is not run for the asking', (function () use ($vtDir, $vtBase, $vtCount) { write_data_file("$vtDir/.gallery-items.php", ['items' => ['clip.mp4' => ['restriction' => 'private']], 'groups' => []]); $n = $vtCount(); return http_get("$vtBase/?img=clip.mp4&size=thumb")[0] === 404 && $vtCount() === $n; })());
// A failing ffmpeg: the placeholder, and not tried over and over.
file_put_contents($vtLog, '');
[$vfDir, $vfBase] = $vtSite('fail', ['FFMPEG_PATH' => $vtFail], ['clip.mp4' => 3]);
[$fSt, $fBody] = http_get("$vfBase/?img=clip.mp4&size=thumb"); $fC = $vtCenter($fBody);
$tries = $vtCount();
http_get("$vfBase/?img=clip.mp4&size=thumb"); http_get("$vfBase/?img=clip.mp4&size=share");
check('[video pictures] when ffmpeg can\'t read the video the generic placeholder is sent as before (a dark picture, with its play triangle), and it is not tried again every time', $fSt === 200 && $fC !== null && $fC[0] < 80 && $tries >= 1 && $vtCount() === $tries && count(glob("$vfDir/.gallery-cache/*.failed")) === 1);
http_get("$vfBase/?preview=clip.mp4"); $clipTries = $vtClips(); http_get("$vfBase/?preview=clip.mp4");
check('[video previews] when ffmpeg can\'t make the clip it is a 404 (the tile just stays as it is), and not tried again every time', http_get("$vfBase/?preview=clip.mp4")[0] === 404 && $clipTries >= 1 && $vtClips() === $clipTries && count(glob("$vfDir/.gallery-cache/*.mp4.failed")) === 1);
check('[video previews] ...and a tile whose clip couldn\'t be made lately no longer promises one (no hover address, so nothing is asked for again)', !str_contains(http_get("$vfBase/")[1], 'data-preview='));
check('[video pictures] ...and then the browser\'s own first frame is laid over the placeholder in the tile where it can be shown', str_contains(http_get("$vfBase/")[1], 'class="tile-video"'));
// Off, and no ffmpeg at all.
file_put_contents($vtLog, '');
[, $voBase] = $vtSite('off', ['FFMPEG_PATH' => $vtOk, 'ENABLE_VIDEO_THUMBNAILS' => false, 'ENABLE_VIDEO_PREVIEWS' => false], ['clip.mp4' => 3]);
$vOff = $vtCenter(http_get("$voBase/?img=clip.mp4&size=thumb")[1]);
check('[video pictures] switched off, it is the placeholder and ffmpeg is never started', $vOff !== null && $vOff[0] < 80 && $vtCount() === 0 && !str_contains((string) file_get_contents($vtLog), '-version'));
check('[video scrub] switched off there is no sprite either: no address on the tile, a 404, and ffmpeg is not started', http_get("$voBase/?strip=clip.mp4")[0] === 404 && !str_contains(http_get("$voBase/")[1], 'data-strip='));
check('[video previews] switched off, there is no clip, no address on the tile, and ffmpeg is not started', http_get("$voBase/?preview=clip.mp4")[0] === 404 && !str_contains(http_get("$voBase/")[1], 'data-preview=') && $vtClips() === 0);
[, $vnBase] = $vtSite('none', ['FFMPEG_PATH' => '/nonexistent/ffmpeg'], ['clip.mp4' => 3]);
$vNone = $vtCenter(http_get("$vnBase/?img=clip.mp4&size=thumb")[1]);
check('[video pictures] with no ffmpeg there it is the placeholder, the video file is never sent as a picture, and the tile gets the browser\'s first frame', $vNone !== null && $vNone[0] < 80 && str_contains(http_get("$vnBase/")[1], 'class="tile-video"'));
// Site settings.
[$vsDir, $vsBase] = $vtSite('settings', ['FFMPEG_PATH' => $vtOk, 'ADMIN_PASSWORD' => 'vp-admin-pw-1'], ['clip.mp4' => 3]);
[, , $vsHd] = http_post("$vsBase/?admin", ['login_password' => 'vp-admin-pw-1'], [], false);
$vsPage = http_get("$vsBase/?admin", ['Cookie: ' . extract_cookie_header($vsHd)])[1];
check('[video previews] Site settings says whether ffmpeg was found (and where), so a missing one is easy to spot', str_contains($vsPage, 'ffmpeg on this server: found') && str_contains($vsPage, '(' . htmlspecialchars($vtOk, ENT_QUOTES, 'UTF-8') . ')') && str_contains($vsPage, 'ffstatus ok-text'));
check('[video previews] Site settings has a "Video previews" switch next to it, on, with its warning', str_contains($vsPage, 'name="set_ENABLE_VIDEO_PREVIEWS"') && str_contains($vsPage, 'Hovering or scrubbing a video plays a short silent clip'));
check('[video pictures] Site settings has a "Video thumbnails" switch, on, with its warning, shown only while videos are on', str_contains($vsPage, 'name="set_ENABLE_VIDEO_THUMBNAILS"') && str_contains($vsPage, 'Runs ffmpeg on your server') && (bool) preg_match('#data-requires="set_ENABLE_VIDEO"[^>]*>(?:(?!</div>).)*set_ENABLE_VIDEO_THUMBNAILS#s', $vsPage) || str_contains($vsPage, 'Video thumbnails'));
// The real thing, when this machine has ffmpeg.
$realFf = getenv('FFMPEG_TEST_BIN') ?: trim((string) shell_exec('command -v ffmpeg'));
if ($realFf !== '' && is_executable($realFf)) {
    $rfDir = new_fixture_dir('vp-real');
    shell_exec(escapeshellarg($realFf) . ' -y -loglevel error -f lavfi -i testsrc=duration=3:size=640x360:rate=24 -pix_fmt yuv420p ' . escapeshellarg("$rfDir/real.mp4") . ' 2>&1');
    file_put_contents("$rfDir/.gallery-config.php", cfg_text(['ENABLE_VIDEO' => true, 'FFMPEG_PATH' => $realFf]));
    copy(__DIR__ . '/../index.php', "$rfDir/index.php");
    $rfPort = find_free_port(); start_server($rfDir, $rfPort);
    $rf = $vtCenter(http_get("http://127.0.0.1:$rfPort/?img=real.mp4&size=thumb")[1]);
    [$rpSt, $rpBody] = http_get("http://127.0.0.1:$rfPort/?preview=real.mp4");
    [$rsSt, $rsBody] = http_get("http://127.0.0.1:$rfPort/?strip=real.mp4"); $rsSize = @getimagesizefromstring($rsBody);
    check('[video scrub] with the real ffmpeg the sprite is one JPEG of ten 320-pixel square frames (3200 x 320 for a 3-second video, each cut from the middle of the picture), and its last frame is a picture, not padding', $rsSt === 200 && $rsSize !== false && $rsSize[0] === 3200 && $rsSize[1] === 320 && (function () use ($rsBody) { $im = imagecreatefromstring($rsBody); $c = imagecolorat($im, 3200 - 160, 160); return (($c >> 16) & 255) + (($c >> 8) & 255) + ($c & 255) > 60; })());
    check('[video previews] with the real ffmpeg the clip is a small silent MP4 (an ftyp box at the start, under 400 KB for a 3-second 640-pixel clip)', $rpSt === 200 && substr($rpBody, 4, 4) === 'ftyp' && strlen($rpBody) < 400000 && strlen($rpBody) > 1000 && !str_contains($rpBody, 'mp4a'));
    check('[video pictures] with the real ffmpeg: a 640x360 clip gives a 480x270 thumbnail that is a picture of the video (not the placeholder)', is_file("$rfDir/real.mp4") && $rf !== null && $rf[3] === 480 && $rf[4] === 270 && $rf[0] > 150 && $rf[1] > 150);
} else {
    echo "  (no ffmpeg on this machine: the real-ffmpeg check was skipped; set FFMPEG_TEST_BIN to run it)\n";
}

/* --------------------------------------------------------------------- */

echo "== function names: nothing in index.php may share a name with a function PHP or one of its extensions provides ==\n";
// (zip_entry_name() once did, and was a fatal error on every host that has the zip extension.)
preg_match_all('/^function\s+([A-Za-z0-9_]+)\s*\(/m', (string) file_get_contents(__DIR__ . '/../index.php'), $fnm);
$fnClash = array_values(array_filter(array_unique($fnm[1]), fn ($f) => in_array($f, get_defined_functions()['internal'], true)));
$fnReserved = array_values(array_filter(array_unique($fnm[1]), fn ($f) => preg_match('/^(zip_|gz[a-z]|bz[a-z]|curl_|mb_|imagick|iconv_|ctype_|openssl_|sodium_|ftp_|finfo_)/', $f)));
check('[names] no function declared in index.php is also a function of PHP or of an extension loaded here', $fnClash === []);
check('[names] ...and none uses a name reserved by an extension that may be loaded on another server (zip_, gz, bz, curl_, mb_, ...)', $fnReserved === []);

/* --------------------------------------------------------------------- */

echo "== the sign-in box is not a password field (so no password-manager pop-up or save prompt) ==\n";

$fxQ = new_fixture_dir('gate');
make_jpeg("$fxQ/a.jpg", 40, 30, 1, 2, 3);
file_put_contents("$fxQ/.gallery-config.php", cfg_text(['ADMIN_PASSWORD' => 'gate-admin-pw-1', 'PRIVATE_VIEW_PASSWORD' => 'gate view']));
copy(__DIR__ . '/../index.php', "$fxQ/index.php");
$portQ = find_free_port(); start_server($fxQ, $portQ); $baseQ = "http://127.0.0.1:$portQ";
$qForm = http_get("$baseQ/?reveal")[1]; $qAdminForm = http_get("$baseQ/?admin")[1];
foreach (['?reveal' => $qForm, '?admin' => $qAdminForm] as $label => $html) {
    check("[sign-in box] $label: the box is a text box (never type=password), called access_code, read-only until it is focused, with every common password manager told to leave it alone, and the form asks the browser not to remember it", str_contains($html, '<input type="text" class="gate"') && !str_contains($html, '<textarea') && !str_contains($html, 'type="password"') && str_contains($html, 'name="access_code"') && !str_contains($html, 'login_password') && !str_contains($html, 'aria-labelledby') && str_contains($html, 'readonly onfocus="this.removeAttribute(\'readonly\');"') && str_contains($html, '::-webkit-credentials-auto-fill-button')
        && str_contains($html, 'autocomplete="off"') && str_contains($html, 'data-lpignore="true"') && str_contains($html, 'data-1p-ignore="true"') && str_contains($html, 'data-bwignore="true"') && str_contains($html, 'data-form-type="other"') && str_contains($html, '<form method="post" action="" autocomplete="off">'));
    check("[sign-in box] $label: what is typed is drawn as dots (CSS), with autocorrect, capitals and spell-check off, and a script falls back to a real password box where the browser can't draw dots", str_contains($html, '-webkit-text-security:disc') && str_contains($html, 'autocapitalize="off"') && str_contains($html, 'autocorrect="off"') && str_contains($html, 'spellcheck="false"') && str_contains($html, "pw.type = 'password'"));
}
[$qs1, , $qh1] = http_post("$baseQ/?reveal", ['access_code' => 'gate view'], [], false);
[$qs2] = http_post("$baseQ/?reveal", ['login_password' => 'gate view'], [], false);
[$qs3, , $qh3] = http_post("$baseQ/?admin", ['access_code' => 'gate-admin-pw-1'], [], false);
check('[sign-in box] the new field name signs in (the viewer password through the lock, the admin password through ?admin), and the old name still does, for scripts', $qs1 === 302 && $qs2 === 302 && $qs3 === 303 && http_post("$baseQ/?admin", ['access_code' => 'nope'], [], false)[0] === 200);
foreach (['?reveal' => $qForm, '?admin' => $qAdminForm] as $label => $html) {
    $visible = strip_tags(preg_replace('#<(script|style)\\b.*?</\\1>#s', '', $html));
    check("[sign-in box] $label: nothing on the page, in its title, heading or the box's own name, says \"password\" or \"sign in\" (words a browser's Autofill reads), and a wrong code says so without them", !preg_match('/password|sign in/i', $visible) && !preg_match('/<title>[^<]*(password|sign in)/i', $html) && !preg_match('/aria-label="[^"]*(password|sign)/i', $html));
}
check('[sign-in box] a code typed with a line break in it (a paste) is sent without it, and a wrong one is answered in the same words', http_post("$baseQ/?reveal", ['access_code' => "gate view\r\n"], [], false)[0] === 302 && str_contains(http_post("$baseQ/?reveal", ['access_code' => 'nope'])[1], "That code isn&#039;t right"));
check('[sign-in box] the first-run setup page keeps real password fields, since choosing a password is where a manager should help (and its boxes accept the 8 characters the rule allows: they wrongly demanded 12)', (function () { $d = new_fixture_dir('gate-setup'); make_jpeg("$d/a.jpg", 40, 30, 1, 2, 3); copy(__DIR__ . '/../index.php', "$d/index.php"); $p = find_free_port(); start_server($d, $p); touch("$d/index.php", time()); $h = http_get("http://127.0.0.1:$p/?admin")[1]; return str_contains($h, 'type="password" id="setup_password" name="setup_password"') && str_contains($h, 'minlength="8"') && !str_contains($h, 'minlength="12"'); })());

/* --------------------------------------------------------------------- */

echo "== a video's own location is read, so its tile shows a pin ==\n";

// (the reader and the coordinate check, taken out of index.php to be tried on small files)
foreach (['function is_valid_coordinate(', 'function extract_video_gps('] as $fnHead) {
    $fnAt = strpos($scrubSrc, $fnHead); eval(substr($scrubSrc, $fnAt, strpos($scrubSrc, "\n}\n", $fnAt) + 3 - $fnAt));
}
$fxVg = new_fixture_dir('videogps');
$vgIso = fn ($s) => $box("\xA9xyz", pack('nn', strlen($s), 0x15C7) . $s);
$vgQt = fn ($s) => $box('meta', $box('hdlr', "\0\0\0\0\0\0\0\0mdta" . str_repeat("\0", 13)) . $box('keys', "\0\0\0\0" . pack('N', 2) . $keyName('com.apple.quicktime.make') . $keyName('com.apple.quicktime.location.ISO6709')) . $box('ilst', $ilstItem(1, 'Apple') . $ilstItem(2, $s)));
$vgFile = fn ($extra) => $box('ftyp', 'isom' . pack('N', 0)) . $box('moov', $mvhd . $extra) . $box('mdat', 'VIDEO');
file_put_contents("$fxVg/iphone.mov", $vgFile($vgQt('+48.8584+002.2945+035.000/')));      // QuickTime's item, with an altitude
file_put_contents("$fxVg/android.mp4", $vgFile($box('udta', $vgIso('-33.8688+151.2093/'))));   // the location atom, south and east
file_put_contents("$fxVg/plain.mp4", $vgFile(''));
file_put_contents("$fxVg/nonsense.mp4", $vgFile($box('udta', $vgIso('not a place'))));
file_put_contents("$fxVg/outside.mp4", $vgFile($box('udta', $vgIso('+91.0000+002.0000/'))));
check('[video gps] iPhone\'s QuickTime item and Android\'s location atom are both read (signs, decimals and an altitude handled); a video without one, with text that isn\'t a place, or with a latitude off the earth gives nothing', (function () use ($fxVg) {
    $got = []; foreach (['iphone.mov', 'android.mp4', 'plain.mp4', 'nonsense.mp4', 'outside.mp4'] as $f) $got[$f] = extract_video_gps("$fxVg/$f");
    return $got['iphone.mov'] === ['lat' => 48.8584, 'lng' => 2.2945] && $got['android.mp4'] === ['lat' => -33.8688, 'lng' => 151.2093] && $got['plain.mp4'] === null && $got['nonsense.mp4'] === null && $got['outside.mp4'] === null;
})());
write_data_file("$fxVg/.gallery-items.php", ['items' => [], 'groups' => [], 'locationEnabled' => true]);
file_put_contents("$fxVg/.gallery-config.php", cfg_text(['ADMIN_PASSWORD' => 'videogps-admin-1', 'ENABLE_VIDEO' => true, 'ENABLE_PHOTO_LOCATION' => true]));
copy(__DIR__ . '/../index.php', "$fxVg/index.php");
$portVg = find_free_port(); start_server($fxVg, $portVg); $baseVg = "http://127.0.0.1:$portVg";
$vgBadges = fn () => substr_count(http_get("$baseVg/")[1], 'class="item-badge location-badge"');
$vgBefore = $vgBadges();
[, , $vgHd] = http_post("$baseVg/?admin", ['access_code' => 'videogps-admin-1'], [], false);
http_get("$baseVg/?admin", ['Cookie: ' . extract_cookie_header($vgHd)]);   // the owner opens the folder: its photos' and videos' locations are read once
$vgItems = json_decode(substr((string) file_get_contents("$fxVg/.gallery-items.php"), strpos((string) file_get_contents("$fxVg/.gallery-items.php"), "\n") + 1), true)['items'] ?? [];
check('[video gps] the owner opening the folder stores the two locations (and marks every video as read), and a video without one gets none', ($vgItems['iphone.mov']['location']['lat'] ?? null) === 48.8584 && ($vgItems['android.mp4']['location']['lng'] ?? null) === 151.2093 && !isset($vgItems['plain.mp4']['location']) && !empty($vgItems['plain.mp4']['gpsChecked']));
check('[video gps] the tiles of those two videos now show a location badge (before, none did)', $vgBefore === 0 && $vgBadges() === 2);

/* ======================================================================= *
 * SHARE LINKS (2.3.0): temporary addresses that sign a person in as a private viewer.
 * ======================================================================= */

echo "== share links: opening page, counting, statuses, what a link holder reaches ==\n";

$fxSl = new_fixture_dir('sharelinks');
mkdir("$fxSl/Rome"); mkdir("$fxSl/Pisa");
make_jpeg("$fxSl/pub.jpg", 80, 60, 200, 60, 60); make_jpeg("$fxSl/sec.jpg", 80, 60, 50, 60, 60);
make_jpeg("$fxSl/Rome/r1.jpg", 80, 60, 100, 60, 60); make_jpeg("$fxSl/Pisa/p1.jpg", 80, 60, 150, 60, 60);
write_data_file("$fxSl/.gallery-items.php", ['items' => ['sec.jpg' => ['restriction' => 'private']], 'groups' => []]);
write_data_file("$fxSl/Rome/.gallery-items.php", ['restriction' => 'private', 'items' => [], 'groups' => []]);
file_put_contents("$fxSl/.gallery-config.php", cfg_text(['ADMIN_PASSWORD' => 'sl-admin-pw-1', 'AUTO_GROUP_BY_FOLDER' => true]));   // share links are off to begin with
copy($indexSrc, "$fxSl/index.php");
$portSl = find_free_port(); start_server($fxSl, $portSl); $baseSl = "http://127.0.0.1:$portSl";
$slLinks = fn () => read_data_file("$fxSl/.gallery-links.php");
$slPage = fn ($path, $cookie = null) => http_get("$baseSl/$path", $cookie ? ["Cookie: $cookie"] : []);
$slThrottleReset = function () use ($fxSl) { @unlink("$fxSl/.gallery-login-attempts.php"); };
$slInvalid = fn ($body, $status) => $status === 404 && str_contains($body, 'This link isn\'t valid') && str_contains($body, 'Please let the person who sent it to you know.');

[$slOffStatus, $slOffBody, $slOffHeaders] = http_get("$baseSl/?link=AAAAAAAAAAAAAAAA");
check('[share links] with the setting off, a link address shows the plain "isn\'t valid" page (and a link can\'t be made)', $slInvalid($slOffBody, $slOffStatus) && in_array('X-Gallery-Link: invalid', $slOffHeaders, true));
check('[share links] ...and the page has no button, no link back and no reason why', !str_contains($slOffBody, '<button') && !str_contains($slOffBody, '<a ') && !str_contains($slOffBody, 'expired') && !str_contains($slOffBody, 'used up') && !str_contains($slOffBody, 'public'));
file_put_contents("$fxSl/.gallery-config.php", cfg_text(['ADMIN_PASSWORD' => 'sl-admin-pw-1', 'AUTO_GROUP_BY_FOLDER' => true, 'ENABLE_SHARE_LINKS' => true]));

[, , $slLoginHeaders] = http_post("$baseSl/?admin", ['access_code' => 'sl-admin-pw-1'], [], false);
$slAdminCookie = extract_cookie_header($slLoginHeaders);
$slAdminPage = http_get("$baseSl/?admin", ["Cookie: $slAdminCookie"])[1];
$slCsrf = extract_csrf_token($slAdminPage);
$slAdmin = function (array $fields) use ($baseSl, $slAdminCookie, $slCsrf) {
    [, $body] = http_post("$baseSl/?admin", $fields + ['csrf_token' => $slCsrf], ['X-Requested-With: gallery-fetch', "Cookie: $slAdminCookie"]);
    return json_decode($body, true) ?? ['ok' => false, 'text' => 'not JSON: ' . substr($body, 0, 80)];
};
$slForm = fn (array $o = []) => $o + ['action' => 'link_create', 'label' => 'Aunt Carol', 'note' => 'Hi Carol, here are the Rome photos.', 'title_mode' => 'gallery', 'max_opens' => 5, 'ends_at' => time() + 86400, 'locations' => 'public', 'heads_up' => 'default', 'downloads' => 'off'];

check('[share links] the admin has a Share links tab and the tab\'s panel, with the server rule written for this install\'s own address', str_contains($slAdminPage, 'data-tab="links"') && str_contains($slAdminPage, 'id="sl-root"') && str_contains($slAdminPage, 'location ~ ^/[A-Za-z0-9]{16}$') && str_contains($slAdminPage, 'index.php?link=$1 last;') && str_contains($slAdminPage, 'RewriteRule ^([A-Za-z0-9]{16})$ index.php?link=$1 [L,QSA]') && str_contains($slAdminPage, 'At the top of the site'));
$slMade = $slAdmin($slForm());
$slAll = $slLinks()['links'] ?? [];
$slId = $slMade['id'] ?? '';
$slLink = $slAll[$slId] ?? [];
$slToken = $slLink['token'] ?? '';
check('[share links] making a link stores it (a 16-letter-and-digit token, active, nothing opened yet, everything-private scope) and the data file answers 404 to a direct request', ($slMade['ok'] ?? false) === true && preg_match('/^[A-Za-z0-9]{16}$/', $slToken) === 1 && ($slLink['status'] ?? '') === 'active' && ($slLink['opens'] ?? -1) === 0 && ($slLink['scope']['type'] ?? '') === 'all' && ($slLink['label'] ?? '') === 'Aunt Carol' && http_get("$baseSl/.gallery-links.php")[0] === 404);
check('[share links] a link needs a name, and an end time that is still to come', ($slAdmin($slForm(['label' => '  ']))['ok'] ?? true) === false && ($slAdmin($slForm(['ends_at' => time() - 60]))['ok'] ?? true) === false && count($slLinks()['links']) === 1);

[$slGetStatus, $slGetBody, $slGetHeaders] = http_get("$baseSl/?link=$slToken");
$slHeaderLines = implode("\n", $slGetHeaders);
check('[share links] opening the address shows the page with one button, the note and the title, and counts nothing yet', $slGetStatus === 200 && str_contains($slGetBody, 'Open the gallery') && str_contains($slGetBody, 'Hi Carol, here are the Rome photos.') && str_contains($slGetBody, '<h1>' . basename($fxSl) . '</h1>') && ($slLinks()['links'][$slId]['opens'] ?? -1) === 0);
check('[share links] ...the page is private to the visitor (no storing, not indexed, no referrer, nothing framed) and does not give away the link\'s name or any photo', stripos($slHeaderLines, 'Cache-Control: no-store') !== false && stripos($slHeaderLines, 'X-Robots-Tag: noindex') !== false && stripos($slHeaderLines, 'Referrer-Policy: no-referrer') !== false && stripos($slHeaderLines, 'frame-ancestors') !== false && !str_contains($slGetBody, 'Aunt Carol') && !str_contains($slGetBody, 'sec.jpg') && !str_contains($slGetBody, 'Rome/') && !str_contains($slGetBody, 'og:image'));
check('[share links] before anyone presses the button the public sees nothing private', !str_contains($slPage('')[1], 'sec.jpg') && http_get("$baseSl/?img=sec.jpg&size=thumb")[0] === 404 && http_get("$baseSl/?img=Rome%2Fr1.jpg&size=thumb")[0] === 404);

[$slGo1Status, , $slGo1Headers] = http_post("$baseSl/?link=$slToken", ['go' => '1'], [], false);
$slCookie = extract_cookie_header($slGo1Headers);
$slLocation = ''; foreach ($slGo1Headers as $h) if (stripos($h, 'Location:') === 0) $slLocation = trim(substr($h, 9));
check('[share links] pressing the button counts one use, sets the link cookie (not readable by scripts) and goes to the gallery', $slGo1Status === 303 && $slLocation === './' && $slCookie !== null && str_starts_with((string) $slCookie, 'gallery_link_') && ($slLinks()['links'][$slId]['opens'] ?? -1) === 1 && (bool) array_filter($slGo1Headers, fn ($h) => stripos($h, 'Set-Cookie: gallery_link_') === 0 && stripos($h, 'HttpOnly') !== false && stripos($h, 'SameSite=Lax') !== false));
[$slHome, $slHomeBody, $slHomeHeaders] = $slPage('', $slCookie);
check('[share links] with the cookie the private photo and the private folder show, the page is kept out of shared caches, and the owner\'s tools (the sign-in lock) are absent', $slHome === 200 && str_contains($slHomeBody, 'sec.jpg') && str_contains($slHomeBody, 'Rome') && in_array('Cache-Control: private, no-store', $slHomeHeaders, true) && in_array('Vary: Cookie', $slHomeHeaders, true) && !str_contains($slHomeBody, 'class="map-combo-btn lock-link') && !str_contains($slHomeBody, 'id="lightbox-edit"'));
check('[share links] ...and its pictures load, while without the cookie they still don\'t', http_get("$baseSl/?img=sec.jpg&size=thumb", ["Cookie: $slCookie"])[0] === 200 && http_get("$baseSl/?img=Rome%2Fr1.jpg&size=full", ["Cookie: $slCookie"])[0] === 200 && http_get("$baseSl/?img=sec.jpg&size=thumb")[0] === 404);
check('[share links] downloads are off for this link: no download of an original and no ZIP, and the viewer has no download button', http_get("$baseSl/?original=sec.jpg", ["Cookie: $slCookie"])[0] === 404 && http_get("$baseSl/?archive", ["Cookie: $slCookie"])[0] === 404 && !str_contains($slHomeBody, 'id="lightbox-download"'));
check('[share links] in the viewer, copy-link is off for private photos (a plain link to one opens nothing for others) and on for public ones', preg_match('/const copyLinkOk = \[([^\]]*)\]/', $slHomeBody, $slCopyOk) === 1 && str_contains($slCopyOk[1], 'false') && str_contains($slCopyOk[1], 'true'));
$slAdminHome = http_get("$baseSl/", ["Cookie: $slAdminCookie"])[1];
check('[share links] the owner still sees everything, with the owner\'s tools (a link holder is never the owner)', str_contains($slAdminHome, 'sec.jpg') && str_contains($slAdminHome, 'id="lightbox-edit"'));

// A second browser, a moment later, from the same address: the in-app browser, then Safari. Not a second use.
[, , $slGoAgainHeaders] = http_post("$baseSl/?link=$slToken", ['go' => '1'], [], false);
check('[share links] the same address pressing again within a few minutes (another browser on the same phone) is let in without using another open', ($slLinks()['links'][$slId]['opens'] ?? -1) === 1 && extract_cookie_header($slGoAgainHeaders) !== null);
// Pressing from the browser that already holds the cookie: never counted.
http_post("$baseSl/?link=$slToken", ['go' => '1'], ["Cookie: $slCookie"], false);
check('[share links] and a browser that already holds the cookie is never counted again', ($slLinks()['links'][$slId]['opens'] ?? -1) === 1);
[$slRevisitStatus, , $slRevisitHeaders] = http_get("$baseSl/?link=$slToken", ["Cookie: $slCookie"], false);
check('[share links] returning to the address with the cookie goes straight in, with no page to press', $slRevisitStatus === 303);

// The same page for every way a link can stop working, and it never says why.
$slThrottleReset();
$slBodies = [];
$slBad = function ($label, $token) use ($baseSl, &$slBodies) { [$s, $b, $h] = http_get("$baseSl/?link=$token"); $slBodies[$label] = [$s, $b]; };
$slBad('unknown', 'ZZZZZZZZZZZZZZZZ'); $slBad('malformed', 'short'); $slBad('empty', '');
$slFile = $slLinks();
foreach (['paused' => ['status' => 'paused'], 'revoked' => ['status' => 'revoked'], 'expired' => ['endsAt' => time() - 5], 'later' => ['startsAt' => time() + 3600], 'used' => ['maxOpens' => 1, 'opens' => 1]] as $slWhy => $slPatch) {
    $f = $slFile; $f['links'][$slId] = array_replace($f['links'][$slId], $slPatch); write_data_file("$fxSl/.gallery-links.php", $f);
    $slBad($slWhy, $slToken);
}
write_data_file("$fxSl/.gallery-links.php", $slFile);
$slSame = array_unique(array_map(fn ($v) => md5($v[1]), $slBodies));
check('[share links] an unknown, mistyped, empty, paused, revoked, expired, not-yet-started and used-up link all show exactly the same page (status 404), so nothing says why', count($slBodies) === 8 && count($slSame) === 1 && count(array_unique(array_column($slBodies, 0))) === 1 && array_unique(array_column($slBodies, 0))[0] === 404);

$slThrottleReset();
// People already in are cut off the moment the link stops being live (and come back when it is live again).
$slPatchLink = function (array $patch) use ($fxSl, $slId, $slFile) { $f = $slFile; $f['links'][$slId] = array_replace($f['links'][$slId], $patch); write_data_file("$fxSl/.gallery-links.php", $f); };
$slSees = fn () => str_contains($slPage('', $slCookie)[1], 'sec.jpg');
$slPatchLink(['status' => 'paused']); $slPausedSees = $slSees();
$slPatchLink([]); $slResumedSees = $slSees();
$slPatchLink(['endsAt' => time() - 5]); $slExpiredSees = $slSees();
$slPatchLink(['status' => 'revoked']); $slRevokedSees = $slSees();
$slPatchLink(['maxOpens' => 1, 'opens' => 1]); $slUsedUpSees = $slSees();   // out of opens only stops new people: those already in stay in
$slPatchLink([]);
check('[share links] pausing, expiring or revoking cuts off someone already in at once; resuming lets them back; a link out of opens only stops new people', !$slPausedSees && $slResumedSees && !$slExpiredSees && !$slRevokedSees && $slUsedUpSees);

// A one-use link: the second person (a different address) is turned away, the first keeps looking.
$slOnce = $slAdmin($slForm(['label' => 'Once', 'max_opens' => 1])); $slOnceId = $slOnce['id'] ?? ''; $slOnceToken = $slLinks()['links'][$slOnceId]['token'] ?? '';
[, , $slOnceH1] = http_post("$baseSl/?link=$slOnceToken", ['go' => '1'], [], false); $slOnceCookie = extract_cookie_header($slOnceH1);
$f = $slLinks(); $f['links'][$slOnceId]['lastOpen']['at'] = time() - 4000; write_data_file("$fxSl/.gallery-links.php", $f);   // long enough ago for the re-open allowance to be over
[$slOnce2Status, $slOnce2Body] = http_post("$baseSl/?link=$slOnceToken", ['go' => '1'], [], false);
check('[share links] a link for one opening lets the first person in and turns the next away (same page as any dead link), without disturbing the first', $slOnceCookie !== null && ($slLinks()['links'][$slOnceId]['opens'] ?? -1) === 1 && $slInvalid($slOnce2Body, $slOnce2Status) && str_contains($slPage('', $slOnceCookie)[1], 'sec.jpg') && $slInvalid(http_get("$baseSl/?link=$slOnceToken")[1], http_get("$baseSl/?link=$slOnceToken")[0]));

// Pause / resume / extend / replace / revoke / delete from the tab.
check('[share links] pausing a link in the tab, then resuming it', ($slAdmin(['action' => 'link_pause', 'id' => $slId])['ok'] ?? false) && ($slLinks()['links'][$slId]['status'] ?? '') === 'paused' && ($slAdmin(['action' => 'link_pause', 'id' => $slId])['ok'] ?? true) === false && ($slAdmin(['action' => 'link_resume', 'id' => $slId])['ok'] ?? false) && ($slLinks()['links'][$slId]['status'] ?? '') === 'active');
$slThrottleReset();
$slOld = $slToken;
$slRep = $slAdmin(['action' => 'link_replace', 'id' => $slId]); $slNewToken = $slLinks()['links'][$slId]['token'] ?? '';
check('[share links] giving a link a new address: the old address and the old cookie stop working, the new one works, name and settings stay, and opens already used stay counted', ($slRep['ok'] ?? false) && $slNewToken !== $slOld && preg_match('/^[A-Za-z0-9]{16}$/', $slNewToken) === 1 && $slInvalid(http_get("$baseSl/?link=$slOld")[1], http_get("$baseSl/?link=$slOld")[0]) && !str_contains($slPage('', $slCookie)[1], 'sec.jpg') && str_contains(http_get("$baseSl/?link=$slNewToken")[1], 'Open the gallery') && ($slLinks()['links'][$slId]['label'] ?? '') === 'Aunt Carol' && ($slLinks()['links'][$slId]['opens'] ?? -1) === 1);
$slToken = $slNewToken;
$slPatchLink = function (array $patch) use ($fxSl, $slId) { $f = read_data_file("$fxSl/.gallery-links.php"); $f['links'][$slId] = array_replace($f['links'][$slId], $patch); write_data_file("$fxSl/.gallery-links.php", $f); };
$slPatchLink(['endsAt' => time() - 60]);
$slExt = $slAdmin(['action' => 'link_extend', 'id' => $slId, 'ends_at' => time() + 7200]);
check('[share links] extending an expired link makes it live again (and a past end time is refused)', ($slExt['ok'] ?? false) && link_state_for_tests($slLinks()['links'][$slId]) === 'active' && ($slAdmin(['action' => 'link_extend', 'id' => $slId, 'ends_at' => time() - 5])['ok'] ?? true) === false);
$slEdit = $slAdmin($slForm(['action' => 'link_save', 'id' => $slId, 'label' => 'Aunt Carol (Rome)', 'title_mode' => 'neutral', 'max_opens' => 9, 'heads_up' => 'on', 'locations' => 'all', 'downloads' => 'all']));
$slEdited = $slLinks()['links'][$slId] ?? [];
check('[share links] editing a link saves its fields without touching its token or its opens', ($slEdit['ok'] ?? false) && ($slEdited['label'] ?? '') === 'Aunt Carol (Rome)' && ($slEdited['titleMode'] ?? '') === 'neutral' && ($slEdited['maxOpens'] ?? 0) === 9 && ($slEdited['headsUp'] ?? '') === 'on' && ($slEdited['locations'] ?? '') === 'all' && ($slEdited['downloads']['mode'] ?? '') === 'all' && ($slEdited['token'] ?? '') === $slToken && ($slEdited['opens'] ?? -1) === 1);
$slThrottleReset();
check('[share links] a neutral title shows "A shared gallery" on the opening page, with no photo or folder name', str_contains(http_get("$baseSl/?link=$slToken")[1], '<h1>A shared gallery</h1>') && !str_contains(http_get("$baseSl/?link=$slToken")[1], basename($fxSl)));
$slThrottleReset();
$slRev = $slAdmin(['action' => 'link_revoke', 'id' => $slId]);
check('[share links] revoking keeps the link in the list as revoked (it can\'t be edited, replaced or extended), and it no longer opens', ($slRev['ok'] ?? false) && ($slLinks()['links'][$slId]['status'] ?? '') === 'revoked' && ($slAdmin(['action' => 'link_extend', 'id' => $slId, 'ends_at' => time() + 100])['ok'] ?? true) === false && ($slAdmin(['action' => 'link_replace', 'id' => $slId])['ok'] ?? true) === false && $slInvalid(http_get("$baseSl/?link=$slToken")[1], http_get("$baseSl/?link=$slToken")[0]));
$slDel = $slAdmin(['action' => 'link_delete', 'id' => $slId]);
check('[share links] deleting a link removes it', ($slDel['ok'] ?? false) && !isset($slLinks()['links'][$slId]) && isset($slLinks()['links'][$slOnceId]));
check('[share links] every action needs the admin\'s session and token: with a wrong token or no session nothing happens', ($slAdmin(['action' => 'link_delete', 'id' => $slOnceId, 'csrf_token' => 'nope'])['ok'] ?? true) === false && json_decode(http_post("$baseSl/?admin", ['action' => 'link_delete', 'id' => $slOnceId, 'csrf_token' => $slCsrf], ['X-Requested-With: gallery-fetch'])[1], true) === null && isset($slLinks()['links'][$slOnceId]));

// The owner opening a link is not counted and gets no cookie.
$slBefore = $slLinks()['links'][$slOnceId]['opens'];
[$slOwnerStatus, , $slOwnerHeaders] = http_get("$baseSl/?link=$slOnceToken", ["Cookie: $slAdminCookie"], false);
check('[share links] the owner opening a link just goes to the gallery: nothing counted, no link cookie', $slOwnerStatus === 303 && ($slLinks()['links'][$slOnceId]['opens'] ?? -1) === $slBefore && !array_filter($slOwnerHeaders, fn ($h) => stripos($h, 'Set-Cookie: gallery_link_') === 0));

// Wrong tokens are throttled like wrong passwords.
for ($i = 0; $i < 5; $i++) http_get("$baseSl/?link=BADBADBADBADBAD" . $i);
check('[share links] after five wrong addresses the sixth try (even a real one) is held back for a moment', http_get("$baseSl/?link=$slOnceToken")[0] === 429);
$f = read_data_file("$fxSl/.gallery-login-attempts.php"); foreach ($f as $k => $v) $f[$k]['lockUntil'] = time() - 1; write_data_file("$fxSl/.gallery-login-attempts.php", $f);

// Where the visitor's address comes from.
$slIpOf = function () use ($fxSl, $slOnceId) { return read_data_file("$fxSl/.gallery-links.php")['links'][$slOnceId]['lastOpen']['ip'] ?? ''; };
$slOpenFrom = function (array $headers) use ($baseSl, $fxSl, $slOnceId, $slOnceToken) {
    $f = read_data_file("$fxSl/.gallery-links.php"); $f['links'][$slOnceId]['maxOpens'] = 0; $f['links'][$slOnceId]['lastOpen'] = ['ip' => '', 'at' => 0]; write_data_file("$fxSl/.gallery-links.php", $f);
    http_post("$baseSl/?link=$slOnceToken", ['go' => '1'], $headers, false);
    return read_data_file("$fxSl/.gallery-links.php")['links'][$slOnceId]['lastOpen']['ip'] ?? '';
};
// (The first sign-in replaced the plain password in the settings file with its hash: keep whatever is stored, or the owner's session would end.)
$slCfg = function (array $extra) use ($fxSl) { $cur = cfg_read("$fxSl/.gallery-config.php"); file_put_contents("$fxSl/.gallery-config.php", cfg_text(['ADMIN_PASSWORD' => $cur['ADMIN_PASSWORD'] ?? 'sl-admin-pw-1', 'AUTO_GROUP_BY_FOLDER' => true, 'ENABLE_SHARE_LINKS' => true] + $extra)); };
$slIpDirect = $slOpenFrom(['CF-Connecting-IP: 203.0.113.9']);
$slCfg(['TRUSTED_PROXY' => 'cloudflare']);
$slIpCfNotTrusted = $slOpenFrom(['CF-Connecting-IP: 203.0.113.9']);   // the connection is 127.0.0.1, which is not Cloudflare
$slCfg(['TRUSTED_PROXY' => 'cloudflare', 'TRUSTED_PROXY_RANGES' => ['127.0.0.1/32']]);
$slIpCfTrusted = $slOpenFrom(['CF-Connecting-IP: 203.0.113.9']);
$slIpCfJunk = $slOpenFrom(['CF-Connecting-IP: not-an-address']);
$slIpCfV6 = $slOpenFrom(['CF-Connecting-IP: 2001:db8::1']);
$slCfg(['TRUSTED_PROXY' => 'proxy', 'TRUSTED_PROXY_RANGES' => ['127.0.0.1/32', '10.0.0.0/8']]);
$slIpChain = $slOpenFrom(['X-Forwarded-For: 198.51.100.7, 10.1.2.3']);   // a trusted hop on the right: the client is the one before it
$slIpForged = $slOpenFrom(['X-Forwarded-For: 6.6.6.6, 198.51.100.7, 10.1.2.3']);   // a forged value further left is never believed
$slCfg(['TRUSTED_PROXY' => 'proxy']);
$slIpProxyUnlisted = $slOpenFrom(['X-Forwarded-For: 198.51.100.7']);
$slCfg([]);
check('[share links] the visitor\'s address is the connection\'s, and a header is believed only from a proxy you named: not by default, not when the connection isn\'t Cloudflare, not when it isn\'t a listed proxy', $slIpDirect === '127.0.0.1' && $slIpCfNotTrusted === '127.0.0.1' && $slIpProxyUnlisted === '127.0.0.1');
check('[share links] behind a trusted Cloudflare connection the header gives the real address (IPv4 or IPv6), a junk header is ignored, and with X-Forwarded-For the client is the first address from the right that is not itself a trusted proxy', $slIpCfTrusted === '203.0.113.9' && $slIpCfJunk === '127.0.0.1' && $slIpCfV6 === '2001:db8::1' && $slIpChain === '198.51.100.7' && $slIpForged === '198.51.100.7');

// The sign-in throttle sees the real address too (it used to see only the proxy's).
$slCfg(['TRUSTED_PROXY' => 'cloudflare', 'TRUSTED_PROXY_RANGES' => ['127.0.0.1/32']]);
http_get("$baseSl/?link=BADBADBADBADBAD9", ['CF-Connecting-IP: 198.51.100.55']);
$slThrottleKeys = array_keys(read_data_file("$fxSl/.gallery-login-attempts.php"));
check('[share links] the throttle counts failures per real visitor address, not per proxy', in_array('link|198.51.100.55', $slThrottleKeys, true));
$slCfg([]);

// Site settings: the new group.
$slSettings = http_get("$baseSl/?admin", ["Cookie: $slAdminCookie"])[1];
check('[share links] Site settings has a Share links group (allow, heads-up, where addresses come from) that shows the visitor address it sees', str_contains($slSettings, 'name="set_ENABLE_SHARE_LINKS"') && str_contains($slSettings, 'name="set_SHARE_HEADS_UP"') && str_contains($slSettings, 'name="set_TRUSTED_PROXY"') && str_contains($slSettings, 'Your address as the gallery sees it: <strong>127.0.0.1</strong>'));

// Installed in a folder: the rule and the probe are written for that folder.
$fxSlSub = new_fixture_dir('sharelinks-sub');
mkdir("$fxSlSub/trip"); copy($indexSrc, "$fxSlSub/trip/index.php"); make_jpeg("$fxSlSub/trip/pub.jpg", 80, 60, 200, 60, 60);
file_put_contents("$fxSlSub/trip/.gallery-config.php", cfg_text(['ADMIN_PASSWORD' => 'sl-sub-pw-1', 'ENABLE_SHARE_LINKS' => true]));
$portSlSub = find_free_port(); @touch("$fxSlSub/trip/index.php", time() - 3600); start_server($fxSlSub, $portSlSub);
[, , $slSubLogin] = http_post("http://127.0.0.1:$portSlSub/trip/index.php?admin", ['access_code' => 'sl-sub-pw-1'], [], false);
$slSubPage = http_get("http://127.0.0.1:$portSlSub/trip/index.php?admin", ['Cookie: ' . extract_cookie_header($slSubLogin)])[1];
check('[share links] in a gallery installed at /trip/ the nginx rule is for /trip/ (no top-of-site warning) and the probe asks for /trip/ plus the probe token', str_contains($slSubPage, 'location ~ ^/trip/[A-Za-z0-9]{16}$ {') && str_contains($slSubPage, 'rewrite ^/trip/([A-Za-z0-9]{16})$ /trip/index.php?link=$1 last;') && str_contains($slSubPage, 'data-probe="/trip/0000000000000000"') && !str_contains($slSubPage, 'At the top of the site'));
[$slSubProbeStatus, , $slSubProbeHeaders] = http_get("http://127.0.0.1:$portSlSub/trip/index.php?link=0000000000000000");
check('[share links] the probe token answers with the marker header but is not counted as a wrong guess', $slSubProbeStatus === 404 && in_array('X-Gallery-Link: invalid', $slSubProbeHeaders, true) && !preg_grep('/^link\|/', array_keys(read_data_file("$fxSlSub/trip/.gallery-login-attempts.php"))));


echo "== share links: folder and photo scopes, downloads, ZIPs and locations: what a link holder can reach and nothing more ==\n";

$fxS2 = new_fixture_dir('slscope');
foreach (['Florence', 'Rome/Sub', 'Pisa', 'Other'] as $d) mkdir("$fxS2/$d", 0775, true);
foreach (['pub.jpg' => 1, 'secret.jpg' => 2, 'Florence/f1.jpg' => 3, 'Florence/f2.jpg' => 4, 'Rome/r1.jpg' => 5, 'Rome/r2.jpg' => 6, 'Rome/Sub/s1.jpg' => 7, 'Pisa/p1.jpg' => 8, 'Other/o1.jpg' => 9] as $f => $n) make_jpeg("$fxS2/$f", 60 + $n, 50, 20 * $n % 255, 90, 30 * $n % 255);
make_minimal_mp4("$fxS2/Rome/clip.mp4", 5);
make_gps_jpeg("$fxS2/Rome/geo.jpg", 'N', [[48, 1], [51, 1], [0, 1]], 'E', [[2, 1], [21, 1], [0, 1]]);
write_data_file("$fxS2/.gallery-items.php", ['items' => ['secret.jpg' => ['restriction' => 'private']], 'groups' => []]);
write_data_file("$fxS2/Florence/.gallery-items.php", ['items' => ['f2.jpg' => ['restriction' => 'private']], 'groups' => []]);
foreach (['Rome', 'Pisa', 'Other'] as $d) write_data_file("$fxS2/$d/.gallery-items.php", ['restriction' => 'private', 'items' => [], 'groups' => []]);
file_put_contents("$fxS2/.gallery-config.php", cfg_text(['ADMIN_PASSWORD' => 'sl2-admin-pw-1', 'AUTO_GROUP_BY_FOLDER' => true, 'ENABLE_SHARE_LINKS' => true, 'ENABLE_VIDEO' => true, 'ENABLE_PHOTO_LOCATION' => true]));
copy($indexSrc, "$fxS2/index.php");
$portS2 = find_free_port(); start_server($fxS2, $portS2); $baseS2 = "http://127.0.0.1:$portS2";
[, , $s2Login] = http_post("$baseS2/?admin", ['access_code' => 'sl2-admin-pw-1'], [], false);
$s2AdminCookie = extract_cookie_header($s2Login);
$s2Csrf = extract_csrf_token(http_get("$baseS2/?admin", ["Cookie: $s2AdminCookie"])[1]);
$s2Admin = function (array $fields) use ($baseS2, $s2AdminCookie, $s2Csrf) {
    [, $body] = http_post("$baseS2/?admin", $fields + ['csrf_token' => $s2Csrf], ['X-Requested-With: gallery-fetch', "Cookie: $s2AdminCookie"]);
    return json_decode($body, true) ?? ['ok' => false, 'text' => 'not JSON: ' . substr($body, 0, 80)];
};
$s2Links = fn () => read_data_file("$fxS2/.gallery-links.php");
$s2Reset = function () use ($fxS2) { @unlink("$fxS2/.gallery-login-attempts.php"); };
// Makes a link (the form's fields as the page sends them), presses its button, and returns [id, token, cookie, where the button led].
$s2Make = function (string $label, array $f) use ($s2Admin, $s2Links, $baseS2, $s2Reset) {
    $made = $s2Admin($f + ['action' => 'link_create', 'label' => $label, 'note' => '', 'title_mode' => 'gallery', 'max_opens' => 0, 'ends_at' => time() + 86400, 'locations' => 'public', 'heads_up' => 'default', 'scope_type' => 'all', 'downloads' => 'off', 'zip' => 'off']);
    if (!($made['ok'] ?? false)) return [null, null, null, $made['text'] ?? 'failed'];
    $token = $s2Links()['links'][$made['id']]['token'];
    $s2Reset();
    [, , $h] = http_post("$baseS2/?link=$token", ['go' => '1'], [], false);
    $loc = ''; foreach ($h as $x) if (stripos($x, 'Location:') === 0) $loc = trim(substr($x, 9));
    return [$made['id'], $token, extract_cookie_header($h), $loc];
};
[$idA, $tokA, $ckA, $locA] = $s2Make('A Rome', ['scope_type' => 'folders', 'scope_folders' => json_encode(['Rome']), 'open_in' => 'Rome', 'downloads' => 'all', 'zip' => 'all']);
[$idB, $tokB, $ckB] = $s2Make('B Rome and Pisa', ['scope_type' => 'folders', 'scope_folders' => json_encode(['Rome', 'Pisa']), 'downloads' => 'some', 'downloads_folders' => json_encode(['Pisa'])]);
[$idC, $tokC, $ckC, $locC] = $s2Make('C two photos', ['scope_type' => 'photos', 'scope_photos' => json_encode(['Rome/r1.jpg', 'Florence/f2.jpg'])]);
[$idD, $tokD, $ckD] = $s2Make('D everything', ['downloads' => 'all', 'locations' => 'all', 'zip' => 'all']);
check('[share links] links for a folder, two folders, two photos and everything can all be made', $idA && $idB && $idC && $idD);

$s2All = ['pub.jpg', 'secret.jpg', 'Florence/f1.jpg', 'Florence/f2.jpg', 'Rome/r1.jpg', 'Rome/r2.jpg', 'Rome/geo.jpg', 'Rome/clip.mp4', 'Rome/Sub/s1.jpg', 'Pisa/p1.jpg', 'Other/o1.jpg'];
$s2Public = ['pub.jpg', 'Florence/f1.jpg'];
$s2Rome = ['Rome/r1.jpg', 'Rome/r2.jpg', 'Rome/geo.jpg', 'Rome/clip.mp4', 'Rome/Sub/s1.jpg'];
$s2Viewers = [   // name => [cookie, the files that viewer may reach]
    'the public' => [null, $s2Public],
    'a link to Rome' => [$ckA, array_merge($s2Public, $s2Rome)],
    'a link to Rome and Pisa' => [$ckB, array_merge($s2Public, $s2Rome, ['Pisa/p1.jpg'])],
    'a link to two photos' => [$ckC, array_merge($s2Public, ['Rome/r1.jpg', 'Florence/f2.jpg'])],
    'a link to everything' => [$ckD, $s2All],
];
$s2Hdr = fn ($c) => $c ? ["Cookie: $c"] : [];
$s2Visible = function ($body) { return preg_match('/const files = (\[[^\n]*\]);/', $body, $m) ? (json_decode($m[1], true) ?: []) : null; };
$s2Same = function ($a, $b) { sort($a); sort($b); return $a === $b; };
foreach ($s2Viewers as $name => [$ck, $expect]) {
    $s2Reset();
    $problems = [];
    // the page and the lightbox's own list of files
    $rootBody = http_get("$baseS2/", $s2Hdr($ck))[1];
    $seen = $s2Visible($rootBody);
    if ($seen === null || !$s2Same($seen, $expect)) $problems[] = 'the page lists ' . json_encode($seen);
    // every picture, every size, and the video
    foreach ($s2All as $f) {
        $isVideo = str_ends_with($f, '.mp4'); $want = in_array($f, $expect, true);
        foreach ($isVideo ? [null] : ['thumb', 'tile', 'tilehd', 'share', 'full', 'cover'] as $size) {
            $url = $isVideo ? "$baseS2/?video=" . rawurlencode($f) : "$baseS2/?img=" . rawurlencode($f) . "&size=$size";
            $st = http_get($url, $s2Hdr($ck))[0];
            if (($want && $st !== 200) || (!$want && $st !== 404)) $problems[] = "$f " . ($size ?? 'video') . " answered $st";
        }
        $photoBody = http_get("$baseS2/?photo=" . rawurlencode($f), $s2Hdr($ck))[1];
        $initial = preg_match('/const initialPhoto = ([^\n]*);/', $photoBody, $pm) ? json_decode($pm[1], true) : 'none';
        if (($want && $initial !== $f) || (!$want && $initial !== null)) $problems[] = "?photo=$f opened " . json_encode($initial);
    }
    // folder pages
    foreach (['Florence', 'Rome', 'Rome/Sub', 'Pisa', 'Other'] as $folder) {
        $has = (bool) array_filter($expect, fn ($x) => str_starts_with($x, $folder . '/'));
        $st = http_get("$baseS2/?folder=" . rawurlencode($folder), $s2Hdr($ck))[0];
        if (($has && $st !== 200) || (!$has && $st !== 404)) $problems[] = "?folder=$folder answered $st";
    }
    // names of what they may not see never appear anywhere on the page
    foreach (['Pisa' => 'Pisa/p1.jpg', 'Other' => 'Other/o1.jpg'] as $word => $witness) if (!in_array($witness, $expect, true) && str_contains($rootBody, $word)) $problems[] = "the page names $word";
    if (!in_array('secret.jpg', $expect, true) && str_contains($rootBody, 'secret')) $problems[] = 'the page names secret.jpg';
    check("[share links] leak matrix, $name: the page, every picture at every size, the video, ?photo= and each folder page show exactly what that viewer may reach" . ($problems ? ' -- ' . implode('; ', array_slice($problems, 0, 4)) : ''), !$problems);
}

// Folder pages show only what the link covers: counts, folder buttons and the covers are made from what that visitor can see.
$s2RomeBody = http_get("$baseS2/?folder=Rome", $s2Hdr($ckA))[1];
check('[share links] a link to Rome shows 4 photos and 1 video in Rome, with no mention of the folders beside it (no Pisa, no Other) in its buttons or breadcrumbs', preg_match('/4 photos\s*(?:·|&middot;)\s*1 video/', $s2RomeBody) === 1 && !str_contains($s2RomeBody, 'Pisa') && !str_contains($s2RomeBody, '>Other<') && !str_contains($s2RomeBody, 'o1.jpg'));
$s2RootA = http_get("$baseS2/", $s2Hdr($ckA))[1];
check('[share links] ...and on the front page the folder card counts only what the link may see', preg_match('/folder-cover-count[^>]*>[^<]*4 photos\s*(?:·|&middot;)\s*1 video/', $s2RootA) === 1);

// The viewer's buttons: a link to Rome has downloads (originals) but a plain link to a private photo is not offered.
check('[share links] the viewer\'s download button exists for a link that allows downloads, and is per file (private files only)', str_contains($s2RootA, 'id="lightbox-download"') && preg_match('/const downloadOk = (\[[^\n]*\]);/', $s2RootA, $dm) === 1 && count(array_filter(json_decode($dm[1], true))) === 5);
check('[share links] and for a link with downloads off there is no download button at all', !str_contains(http_get("$baseS2/", $s2Hdr($ckC))[1], 'id="lightbox-download"'));

// Downloads of originals.
$s2Dl = fn ($ck, $f) => http_get("$baseS2/?original=" . rawurlencode($f), $s2Hdr($ck))[0];
check('[share links] a link that allows downloads everywhere hands out the originals of what it covers, and never a private file outside it or a public one the gallery doesn\'t offer', $s2Dl($ckA, 'Rome/r1.jpg') === 200 && $s2Dl($ckA, 'Pisa/p1.jpg') === 404 && $s2Dl($ckA, 'pub.jpg') === 404 && $s2Dl($ckA, 'secret.jpg') === 404);
check('[share links] "only in some folders" means exactly those folders (here Pisa, not Rome), and a link with downloads off gives nothing', $s2Dl($ckB, 'Pisa/p1.jpg') === 200 && $s2Dl($ckB, 'Rome/r1.jpg') === 404 && $s2Dl($ckC, 'Rome/r1.jpg') === 404 && $s2Dl(null, 'Rome/r1.jpg') === 404);
check('[share links] a link to everything with downloads on reaches every private original', $s2Dl($ckD, 'secret.jpg') === 200 && $s2Dl($ckD, 'Other/o1.jpg') === 200);

// Locations: "as the public sees them" removes a hidden location from the copy (same size), "all saved locations" leaves the file as it is.
$s2Orig = (string) file_get_contents("$fxS2/Rome/geo.jpg");
$s2GetA = http_get("$baseS2/?original=" . rawurlencode('Rome/geo.jpg'), $s2Hdr($ckA)); $s2GetD = http_get("$baseS2/?original=" . rawurlencode('Rome/geo.jpg'), $s2Hdr($ckD));
check('[share links] a hidden location is taken out of the copy a link gives (same size, different bytes) unless the link shows all locations', strlen($s2GetA[1]) === strlen($s2Orig) && $s2GetA[1] !== $s2Orig && $s2GetD[1] === $s2Orig);

// ZIPs: only what the link's ZIP setting covers, with the same location clean-up.
$s2Zip = function ($ck, $query) use ($baseS2, $s2Hdr, $haveUnzip, $zipNames, $zipEntry) {
    [$st, $body] = http_get("$baseS2/?archive$query", $s2Hdr($ck));
    if ($st !== 200) return [$st, []];
    $f = tempnam(sys_get_temp_dir(), 'slz'); file_put_contents($f, $body);
    $names = $haveUnzip ? $zipNames($f) : [];
    return [$st, $names, $f];
};
if ($haveUnzip) {
    [$zStA, $zNamesA, $zFileA] = $s2Zip($ckA, '');
    $zBare = array_map(fn ($n) => preg_replace('#^[^/]+/#', '', $n), $zNamesA);
    check('[share links] a link with ZIP on gets a ZIP of exactly the private files it covers (the public photos stay out: the gallery doesn\'t offer them as downloads)', $zStA === 200 && $s2Same($zBare, $s2Rome));
    $zGeo = (string) $zipEntry($zFileA, $zNamesA[array_search('Rome/geo.jpg', $zBare)]);
    check('[share links] ...with the hidden location taken out of the copy of geo.jpg in the ZIP (same size, different bytes)', strlen($zGeo) === strlen($s2Orig) && $zGeo !== $s2Orig);
    [, $zNamesD, $zFileD] = $s2Zip($ckD, '&folder=Rome');
    $zGeoD = (string) $zipEntry($zFileD, preg_grep('#geo\.jpg$#', $zNamesD) ? array_values(preg_grep('#geo\.jpg$#', $zNamesD))[0] : 'none');
    check('[share links] a link to everything with all locations gets the files exactly as they are', $zGeoD === $s2Orig);
    foreach ([$zFileA ?? null, $zFileD ?? null] as $zf) if ($zf) @unlink($zf);
}
check('[share links] a link with ZIP off (B), the public, and a link to photos get no ZIP', http_get("$baseS2/?archive", $s2Hdr($ckB))[0] === 404 && http_get("$baseS2/?archive", $s2Hdr($ckC))[0] === 404 && http_get("$baseS2/?archive")[0] === 404);

// Where the button leads.
check('[share links] pressing the button leads where the link opens: a chosen folder, the first photo of a link to photos, else the front page', $locA === './?folder=Rome' && $locC === './?folder=Rome&photo=Rome%2Fr1.jpg');

// Folders are remembered by a hidden id, not by name.
$s2Id = fn ($rel) => (read_data_file("$fxS2/$rel/.gallery-items.php")['folderId'] ?? '');
check('[share links] the folders a link covers got a hidden id (12 letters/digits), and a folder it doesn\'t cover didn\'t', preg_match('/^[0-9a-f]{12}$/', $s2Id('Rome')) === 1 && preg_match('/^[0-9a-f]{12}$/', $s2Id('Pisa')) === 1 && $s2Id('Other') === '' && ($s2Links()['links'][$idA]['scope']['folders'][0] ?? '') === $s2Id('Rome'));
rename("$fxS2/Rome", "$fxS2/Roma");
$s2Reset();
$s2AfterRename = $s2Visible(http_get("$baseS2/", $s2Hdr($ckA))[1]);
check('[share links] renaming a folder keeps the link working (the id travels with it): the link now shows Roma, and the old name shows nothing', $s2AfterRename !== null && in_array('Roma/r1.jpg', $s2AfterRename, true) && !array_filter($s2AfterRename, fn ($x) => str_starts_with($x, 'Rome/')) && http_get("$baseS2/?img=" . rawurlencode('Roma/r1.jpg') . '&size=thumb', $s2Hdr($ckA))[0] === 200);
$s2AdminTab = http_get("$baseS2/?admin", ["Cookie: $s2AdminCookie"])[1];
check('[share links] the tab shows no problem for a renamed folder, and lists it under its new name', !str_contains($s2AdminTab, 'Folder missing') && str_contains($s2AdminTab, 'Only Roma'));
// A copy of the folder, with its settings file and so its id: the id now points at two folders, so it points at nothing.
mkdir("$fxS2/Roma copy", 0775, true); copy("$fxS2/Roma/r1.jpg", "$fxS2/Roma copy/r1.jpg"); copy("$fxS2/Roma/.gallery-items.php", "$fxS2/Roma copy/.gallery-items.php");
http_get("$baseS2/", $s2Hdr($ckA));   // the first page the link holder loads notices the duplicate
$s2Dup = $s2Visible(http_get("$baseS2/", $s2Hdr($ckA))[1]);
check('[share links] a folder copied together with its settings (so its id) leaves the id ambiguous: the link shows neither folder, and the routes that answer early agree', $s2Dup !== null && !array_filter($s2Dup, fn ($x) => str_starts_with($x, 'Roma')) && http_get("$baseS2/?img=" . rawurlencode('Roma/r1.jpg') . '&size=thumb', $s2Hdr($ckA))[0] === 404 && http_get("$baseS2/?img=" . rawurlencode('Roma copy/r1.jpg') . '&size=thumb', $s2Hdr($ckA))[0] === 404);
$s2AdminTab = http_get("$baseS2/?admin", ["Cookie: $s2AdminCookie"])[1];
check('[share links] the tab says so: the link is marked "Folder missing" and the folder is listed as found in more than one place', str_contains($s2AdminTab, 'Folder missing') && str_contains($s2AdminTab, 'found in more than one folder'));
rrmdir("$fxS2/Roma copy");
$s2Reset();
http_get("$baseS2/", $s2Hdr($ckA));
check('[share links] taking the copy away makes the link work again', in_array('Roma/r1.jpg', $s2Visible(http_get("$baseS2/", $s2Hdr($ckA))[1]) ?? [], true));
// A folder deleted and another made under the old name: the newcomer has no id, so the link doesn't cover it.
rrmdir("$fxS2/Roma"); mkdir("$fxS2/Rome", 0775, true); make_jpeg("$fxS2/Rome/new.jpg", 70, 50, 1, 2, 3);
write_data_file("$fxS2/Rome/.gallery-items.php", ['restriction' => 'private', 'items' => [], 'groups' => []]);
$s2Reset();
$s2New = $s2Visible(http_get("$baseS2/", $s2Hdr($ckA))[1]);
check('[share links] a folder deleted and a new one made under the same name: the link does NOT cover the newcomer (nothing private shows)', $s2New !== null && !array_filter($s2New, fn ($x) => str_starts_with($x, 'Rome/') || str_starts_with($x, 'Roma')) && http_get("$baseS2/?img=" . rawurlencode('Rome/new.jpg') . '&size=thumb', $s2Hdr($ckA))[0] === 404);
$s2AdminTab = http_get("$baseS2/?admin", ["Cookie: $s2AdminCookie"])[1];
check('[share links] and the tab shows the link as having a missing folder, with the way to take it out', str_contains($s2AdminTab, 'Folder missing') && str_contains($s2AdminTab, 'can\'t be found') && str_contains($s2AdminTab, 'data-sl="forget"'));
check('[share links] taking out the only folder of a link is refused (a link keeps at least one)', ($s2Admin(['action' => 'link_forget', 'id' => $idA, 'fid' => $s2Links()['links'][$idA]['scope']['folders'][0]])['ok'] ?? true) === false);
$s2B = $s2Links()['links'][$idB]['scope']['folders'];
$s2BForget = $s2Admin(['action' => 'link_forget', 'id' => $idB, 'fid' => $s2B[0]]);
check('[share links] taking one folder out of a link with two works (and clears it from the downloads list too)', ($s2BForget['ok'] ?? false) && count($s2Links()['links'][$idB]['scope']['folders']) === 1);

// A folder the web server can't write in can't be remembered, so a link can't be limited to it.
if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    chmod("$fxS2/Other", 0555); chmod("$fxS2/Other/.gallery-items.php", 0444);   // (a folder's settings file that already exists can be written even in a locked folder: lock both)
    $s2Locked = $s2Admin(['action' => 'link_create', 'label' => 'x', 'title_mode' => 'gallery', 'max_opens' => 0, 'ends_at' => time() + 600, 'locations' => 'public', 'heads_up' => 'default', 'scope_type' => 'folders', 'scope_folders' => json_encode(['Other']), 'downloads' => 'off', 'zip' => 'off']);
    chmod("$fxS2/Other", 0775); chmod("$fxS2/Other/.gallery-items.php", 0664);
    check('[share links] a link can\'t be limited to a folder the web server can\'t write in (it says why), and nothing is made', ($s2Locked['ok'] ?? true) === false && str_contains($s2Locked['text'] ?? '', 'can\'t write') && count($s2Links()['links']) === 4);
}

// Checking the form's choices.
$s2Bad = fn (array $f) => ($s2Admin($f + ['action' => 'link_create', 'label' => 'bad', 'title_mode' => 'gallery', 'max_opens' => 0, 'ends_at' => time() + 600, 'locations' => 'public', 'heads_up' => 'default', 'scope_type' => 'all', 'downloads' => 'off', 'zip' => 'off'])['ok'] ?? true) === false;
check('[share links] a link to folders needs at least one, a folder that isn\'t there is refused, downloads "only in some folders" need folders among those the link covers, and it can only open in a folder it covers', $s2Bad(['scope_type' => 'folders', 'scope_folders' => '[]']) && $s2Bad(['scope_type' => 'folders', 'scope_folders' => json_encode(['Nope'])]) && $s2Bad(['scope_type' => 'folders', 'scope_folders' => json_encode(['Pisa']), 'downloads' => 'some', 'downloads_folders' => json_encode(['Other'])]) && $s2Bad(['scope_type' => 'folders', 'scope_folders' => json_encode(['Pisa']), 'open_in' => 'Other']) && $s2Bad(['scope_type' => 'photos', 'scope_photos' => json_encode(['nope.jpg'])]) && count($s2Links()['links']) === 4);

// The heads-up line.
$s2Ends = time() + 3 * 86400;
$s2Hu = function (array $f, array $cfg = []) use ($s2Make, $fxS2, $baseS2, $s2Hdr) {
    $cur = cfg_read("$fxS2/.gallery-config.php");
    file_put_contents("$fxS2/.gallery-config.php", cfg_text(['ADMIN_PASSWORD' => $cur['ADMIN_PASSWORD'], 'AUTO_GROUP_BY_FOLDER' => true, 'ENABLE_SHARE_LINKS' => true, 'ENABLE_VIDEO' => true, 'ENABLE_PHOTO_LOCATION' => true] + $cfg));
    [, , $ck] = $s2Make('hu', $f);
    return http_get("$baseS2/", $s2Hdr($ck))[1];
};
$huOn = $s2Hu(['heads_up' => 'on', 'ends_at' => $s2Ends]);
$huDefaultOff = $s2Hu(['heads_up' => 'default', 'ends_at' => $s2Ends]);
$huDefaultSite = $s2Hu(['heads_up' => 'default', 'ends_at' => $s2Ends], ['SHARE_HEADS_UP' => true]);
$huOffSite = $s2Hu(['heads_up' => 'off', 'ends_at' => $s2Ends], ['SHARE_HEADS_UP' => true]);
$huNoEnd = $s2Hu(['heads_up' => 'on', 'ends_at' => 0]);
check('[share links] the heads-up line says "The link being used to access this media expires on" with the end time, when the link asks for it, or when it follows a site setting that is on', str_contains($huOn, 'id="link-headsup"') && str_contains($huOn, 'The link being used to access this media expires on') && str_contains($huOn, 'data-ends="' . $s2Ends . '"') && !str_contains($huDefaultOff, 'id="link-headsup"') && str_contains($huDefaultSite, 'id="link-headsup"'));
check('[share links] ...never when the link says off (even with the site setting on), and never for a link with no end date', !str_contains($huOffSite, 'id="link-headsup"') && !str_contains($huNoEnd, 'id="link-headsup"'));
check('[share links] the public and the owner never see the heads-up line', !str_contains(http_get("$baseS2/")[1], 'id="link-headsup"') && !str_contains(http_get("$baseS2/", ["Cookie: $s2AdminCookie"])[1], 'id="link-headsup"'));

// Someone using a link is not counted in the gallery's view counts, and the owner's pages show the private tab unchanged.
check('[share links] the owner\'s own view of the gallery still shows everything, including folders the links no longer reach', str_contains(http_get("$baseS2/", ["Cookie: $s2AdminCookie"])[1], 'Other'));


echo "== share links: the log (opens, what was seen, opened, played and saved, blocked tries), kept until the link is deleted ==\n";

$fxL = new_fixture_dir('sllog');
mkdir("$fxL/Rome", 0775); mkdir("$fxL/Pisa", 0775);
make_jpeg("$fxL/pub.jpg", 60, 50, 200, 60, 60); make_jpeg("$fxL/Rome/r1.jpg", 61, 50, 100, 60, 60); make_jpeg("$fxL/Rome/r2.jpg", 62, 50, 110, 60, 60); make_jpeg("$fxL/Pisa/p1.jpg", 63, 50, 150, 60, 60);
make_minimal_mp4("$fxL/Rome/clip.mp4", 5);
foreach (['Rome', 'Pisa'] as $d) write_data_file("$fxL/$d/.gallery-items.php", ['restriction' => 'private', 'items' => [], 'groups' => []]);
file_put_contents("$fxL/.gallery-config.php", cfg_text(['ADMIN_PASSWORD' => 'sllog-admin-1', 'AUTO_GROUP_BY_FOLDER' => true, 'ENABLE_SHARE_LINKS' => true, 'ENABLE_VIDEO' => true, 'TRACK_VIEWS' => true]));
copy($indexSrc, "$fxL/index.php");
$portL = find_free_port(); start_server($fxL, $portL); $baseL = "http://127.0.0.1:$portL";
[, , $lgLogin] = http_post("$baseL/?admin", ['access_code' => 'sllog-admin-1'], [], false);
$lgAdminCookie = extract_cookie_header($lgLogin);
$lgCsrf = extract_csrf_token(http_get("$baseL/?admin", ["Cookie: $lgAdminCookie"])[1]);
$lgAdmin = function (array $fields) use ($baseL, $lgAdminCookie, $lgCsrf) {
    [, $body] = http_post("$baseL/?admin", $fields + ['csrf_token' => $lgCsrf], ['X-Requested-With: gallery-fetch', "Cookie: $lgAdminCookie"]);
    return json_decode($body, true) ?? ['ok' => false, 'text' => 'not JSON: ' . substr($body, 0, 80)];
};
$lgLinks = fn () => read_data_file("$fxL/.gallery-links.php");
$lgUa = 'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
$lgMade = $lgAdmin(['action' => 'link_create', 'label' => '=cmd|calc', 'note' => '', 'title_mode' => 'gallery', 'max_opens' => 0, 'ends_at' => time() + 86400, 'locations' => 'all', 'heads_up' => 'default', 'scope_type' => 'all', 'downloads' => 'all', 'zip' => 'all']);
$lgId = $lgMade['id'] ?? ''; $lgTok = $lgLinks()['links'][$lgId]['token'] ?? '';
[, , $lgGoH] = http_post("$baseL/?link=$lgTok", ['go' => '1'], [$lgUa], false); $lgCk = extract_cookie_header($lgGoH);
$lgH = ["Cookie: $lgCk", $lgUa];
$lgLog = fn () => read_data_file("$fxL/.gallery-linklog-$lgId.php");
$lgItems = fn () => read_data_file("$fxL/.gallery-linklog-$lgId-items.php");
$lgV = array_values($lgLog()['visitors'] ?? [])[0] ?? [];
check('[share log] pressing the button starts the link\'s log: one visitor (address and browser, named plainly), one open, in a data file that answers 404 to a direct request', count($lgLog()['visitors'] ?? []) === 1 && ($lgV['ip'] ?? '') === '127.0.0.1' && ($lgV['ua'] ?? '') === 'Safari on iPhone' && ($lgV['opens'] ?? 0) === 1 && http_get("$baseL/.gallery-linklog-$lgId.php")[0] === 404 && ($lgLog()['events'][0]['k'] ?? '') === 'link');

$lgBeacon = function (array $events, $headers = null) use ($baseL, $lgH) {
    $opts = ['http' => ['method' => 'POST', 'header' => implode("\r\n", array_merge(['Content-Type: application/json'], $headers ?? $lgH)), 'content' => json_encode(['e' => $events]), 'ignore_errors' => true, 'timeout' => 5]];
    $body = @file_get_contents("$baseL/?linklog", false, stream_context_create($opts)); $st = 0;
    foreach ($http_response_header ?? [] as $h) if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $st = (int) $m[1];
    return [$st, $body];
};
[$lgBs] = $lgBeacon([['s', 'Rome/r1.jpg'], ['s', 'Rome/r2.jpg'], ['s', 'pub.jpg'], ['f', 'Rome'], ['o', 'Rome/r1.jpg'], ['o', 'Rome/r1.jpg'], ['p', 'Rome/clip.mp4'], ['s', 'Pisa/nope.jpg'], ['s', '../etc/passwd'], ['x', 'pub.jpg'], ['f', 'Nope']]);
$lgT = $lgItems()['t'] ?? [];
$lgVid = array_key_first($lgLog()['visitors']);
$lgTally = fn ($rel) => $lgT["$lgVid|$rel"] ?? null;
check('[share log] the page\'s report is taken: seen (first time kept), opened twice, played once, and the folder page; answers 204', $lgBs === 204 && (($lgTally('Rome/r1.jpg')['sf'] ?? null) !== null) && ($lgTally('Rome/r1.jpg')['on'] ?? 0) === 2 && (($lgTally('Rome/r2.jpg')['sf'] ?? null) !== null) && (($lgTally('pub.jpg')['sf'] ?? null) !== null) && ($lgTally('Rome/clip.mp4')['pn'] ?? 0) === 1 && (($lgItems()['f']["$lgVid|Rome"][0] ?? 0) === 1));
check('[share log] ...and what doesn\'t exist, what a link can\'t reach (Pisa isn\'t covered... here everything is, so a missing file), a path trying to climb out, an unknown kind and a missing folder are all ignored', ($lgTally('Pisa/nope.jpg') === null) && ($lgTally('../etc/passwd') === null) && !isset($lgItems()['f']["$lgVid|Nope"]) && count($lgT) === 4);
$lgEvents = $lgLog()['events'];
check('[share log] the timeline has the opening, one line for the scroll (3 pictures first seen), the folder, two openings, and the play', count(array_filter($lgEvents, fn ($e) => $e['k'] === 'scroll')) >= 1 && count(array_filter($lgEvents, fn ($e) => $e['k'] === 'opened')) === 2 && count(array_filter($lgEvents, fn ($e) => $e['k'] === 'played')) === 1 && count(array_filter($lgEvents, fn ($e) => $e['k'] === 'folder')) === 1);
check('[share log] the visitor\'s views are counted (two openings and one folder page)', (array_values($lgLog()['visitors'])[0]['views'] ?? 0) === 3);
check('[share log] a report from someone who is not using a link (the public, the owner) is dropped, and nothing is written', $lgBeacon([['s', 'pub.jpg']], [])[0] === 204 && $lgBeacon([['s', 'pub.jpg']], ["Cookie: $lgAdminCookie"])[0] === 204 && count($lgItems()['t']) === 4 && ($lgTally('pub.jpg')['sl'] ?? 0) === ($lgItems()['t']["$lgVid|pub.jpg"]['sl'] ?? -1));

// What the server notes itself: a private picture it sends, an original, a ZIP.
http_get("$baseL/?img=" . rawurlencode('Pisa/p1.jpg') . '&size=thumb', $lgH);   // (not covered... this link covers everything, so it is sent)
http_get("$baseL/?img=" . rawurlencode('Rome/r2.jpg') . '&size=tile', $lgH);
http_get("$baseL/?img=" . rawurlencode('pub.jpg') . '&size=tile', $lgH);        // public: not noted by the server (the browser reports it)
http_get("$baseL/?original=" . rawurlencode('Rome/r2.jpg'), $lgH);
$lgQueue = (string) @file_get_contents("$fxL/.gallery-linklog-$lgId-q.php");
check('[share log] the server appends a note for each private picture it sends a link holder and for each original it hands out, in a guarded queue file (a cheap write); a public picture is left to the browser', substr_count($lgQueue, '"s"') === 2 && substr_count($lgQueue, '"d"') === 1 && !str_contains($lgQueue, 'pub.jpg') && str_starts_with($lgQueue, '<?php') && http_get("$baseL/.gallery-linklog-$lgId-q.php")[0] === 404);
[$lgZipSt, $lgZipBody] = http_get("$baseL/?archive&folder=Rome", $lgH);
$lgFrag = fn ($qs) => http_get("$baseL/?admin&$qs", ["Cookie: $lgAdminCookie"]);
$lgVisitorsHtml = $lgFrag("linkview=$lgId&tab=visitors")[1];
check('[share log] the Visitors tab folds the queue in and lists the address (a button), where from, the browser, opens and views', $lgZipSt === 200 && str_contains($lgVisitorsHtml, 'data-ip="127.0.0.1"') && str_contains($lgVisitorsHtml, 'Safari on iPhone') && str_contains($lgVisitorsHtml, 'data-vid="' . $lgVid . '"') && !str_contains((string) @file_get_contents("$fxL/.gallery-linklog-$lgId-q.php"), '"s"'));
$lgT = $lgItems()['t']; $lgTally = fn ($rel) => $lgT["$lgVid|$rel"] ?? null;
check('[share log] the queued notes became "seen" (Pisa/p1.jpg, Rome/r2.jpg) and "saved" (the original and each file of the ZIP), and the ZIP is one line of the timeline', (($lgTally('Pisa/p1.jpg')['sf'] ?? null) !== null) && ($lgTally('Rome/r2.jpg')['dn'] ?? 0) === 2 && ($lgTally('Rome/r1.jpg')['dn'] ?? 0) === 1 && count(array_filter($lgLog()['events'], fn ($e) => $e['k'] === 'zip')) === 1);
$lgFolders = $lgFrag("linkview=$lgId&tab=folders")[1];
check('[share log] the Folders tab says, per folder, how many items were seen of how many there are, opened and saved, with a lock on private folders', str_contains($lgFolders, 'Rome') && preg_match('/<td>\s*3 of 3\s*<\/td>|<td>3 of 3<\/td>/', $lgFolders) === 1 && str_contains($lgFolders, 'sl-lock') && str_contains($lgFolders, 'Front page'));
$lgPhotos = $lgFrag("linkview=$lgId&tab=photos")[1];
$lgPhotosOne = $lgFrag("linkview=$lgId&tab=photos&vid=$lgVid&folder=Rome")[1];
check('[share log] filtering to one address and one folder shows only that folder\'s items', substr_count($lgPhotosOne, 'class="sl-th"') === 3 && !str_contains($lgPhotosOne, rawurlencode('pub.jpg')) && str_contains($lgPhotosOne, 'sl-fchip'));
$lgIp = $lgFrag('linkip=127.0.0.1&tab=folders')[1];
check('[share log] one address\'s page: its links, totals, a key for seen / opened / saved, and the Folders, Media and Timeline tabs', str_contains($lgIp, 'sl-ipbig') && str_contains($lgIp, '127.0.0.1') && str_contains($lgIp, 'Safari on iPhone') && str_contains($lgIp, 'sl-lchip') && str_contains($lgIp, 'was on their screen') && str_contains($lgIp, 'data-tab="media"') && str_contains($lgIp, 'data-tab="timeline"'));
$lgIpMedia = $lgFrag('linkip=127.0.0.1&tab=media')[1]; $lgIpTime = $lgFrag('linkip=127.0.0.1&tab=timeline')[1];
check('[share log] its Media tab names each file with first seen, opened and saved, and its Timeline lists the happenings in order (newest first), scrolling as one line', str_contains($lgIpMedia, 'r1.jpg') && str_contains($lgIpMedia, 'Opened ×2') && str_contains($lgIpMedia, '✓ saved') && str_contains($lgIpTime, 'Scrolled through') && str_contains($lgIpTime, 'Saved a ZIP of') && str_contains($lgIpTime, 'Played'));
check('[share log] the list of every address names the links each used and what it viewed', str_contains($lgFrag('linkips=1')[1], 'data-ip="127.0.0.1"') && str_contains($lgFrag('linkips=1')[1], 'sl-lchip'));
[$lgCsvSt, $lgCsv, $lgCsvH] = $lgFrag("linkexport=$lgId&what=items");
check('[share log] the CSV export is a download with a header row and one line per item, and a name starting with = can\'t run as a spreadsheet formula', $lgCsvSt === 200 && (bool) array_filter($lgCsvH, fn ($h) => stripos($h, 'Content-Disposition: attachment') === 0 && str_contains($h, '.csv')) && str_contains($lgCsv, '"link","ip","browser","folder","file"') && str_contains($lgCsv, '"\'=cmd|calc"') && substr_count($lgCsv, "\r\n") >= 6);
check('[share log] the other exports work too (visitors, everything in order) and one address can be exported alone', str_contains($lgFrag("linkexport=$lgId&what=visitors")[1], '"link","ip","where"') && str_contains($lgFrag("linkexport=$lgId&what=events")[1], '"time","link","ip","what"') && !str_contains($lgFrag("linkexport=$lgId&what=items&ip=9.9.9.9")[1], 'r1.jpg') && $lgFrag('linkexport=nope&what=items')[0] === 404);
check('[share log] these fragments and downloads are for the owner only', http_get("$baseL/?admin&linkview=$lgId&tab=visitors", [])[0] !== 200 || !str_contains(http_get("$baseL/?admin&linkview=$lgId&tab=visitors", [$lgUa])[1], 'data-ip='));

// The list's summary line and the "new" markers.
$lgTab = http_get("$baseL/?admin", ["Cookie: $lgAdminCookie"])[1];
check('[share log] the link\'s summary line in the tab carries the log: its addresses and views', preg_match('/<b>1<\/b> IP\s*<span class="sl-dot">&middot;<\/span>\s*\d+ views/', $lgTab) === 1);
check('[share log] until the owner has looked, the row says how many are new since, and the sidebar counts the links with news', str_contains($lgTab, 'new since you last looked') && preg_match('/data-tab="links" class="side-link">Share links <span class="count">1<\/span>/', $lgTab) === 1);
check('[share log] telling the server the tab was looked at clears them (a later page load shows none)', ($lgAdmin(['action' => 'link_seen'])['ok'] ?? false) && !str_contains(http_get("$baseL/?admin", ["Cookie: $lgAdminCookie"])[1], 'new since you last looked'));

// Blocked tries and a replaced address.
$lgAdmin(['action' => 'link_pause', 'id' => $lgId]);
http_post("$baseL/?link=$lgTok", ['go' => '1'], [$lgUa], false); http_get("$baseL/?link=$lgTok", [$lgUa]);
$lgAdmin(['action' => 'link_resume', 'id' => $lgId]);
$lgOldTok = $lgTok; $lgAdmin(['action' => 'link_replace', 'id' => $lgId]); $lgTok = $lgLinks()['links'][$lgId]['token'];
@unlink("$fxL/.gallery-login-attempts.php");
$lgOldBody = http_get("$baseL/?link=$lgOldTok", [$lgUa]);
$lgBlocked = array_values($lgLog()['blocked'] ?? [])[0] ?? [];
check('[share log] tries turned away are logged with the address and why (paused; the old address after a replace), the invalid page is the same as ever, and an old address isn\'t counted as a guess', ($lgBlocked['tries'] ?? 0) === 3 && ($lgBlocked['why']['paused'] ?? 0) === 2 && ($lgBlocked['why']['replaced'] ?? 0) === 1 && $lgOldBody[0] === 404 && str_contains($lgOldBody[1], 'This link isn\'t valid') && !str_contains(json_encode(is_file("$fxL/.gallery-login-attempts.php") ? read_data_file("$fxL/.gallery-login-attempts.php") : []), 'link|'));
check('[share log] the Visitors tab lists them as blocked tries (with the reason), and the summary line says so', str_contains($lgFrag("linkview=$lgId&tab=visitors")[1], 'Blocked tries') && str_contains($lgFrag("linkview=$lgId&tab=visitors")[1], 'old address') && str_contains(http_get("$baseL/?admin", ["Cookie: $lgAdminCookie"])[1], '3 blocked tries'));

// The log is kept when a link ends, whatever way, and goes only when the link is deleted.
$lgAdmin(['action' => 'link_revoke', 'id' => $lgId]);
$lgFile = function ($p) use ($fxL, $lgId) { clearstatcache(); return is_file("$fxL/.gallery-linklog-$lgId$p.php"); };   // (the server removes files: don't trust PHP's cached answer)
$lgKeptRevoked = $lgFile('') && $lgFile('-items') && count(link_log_visitors_for_tests("$fxL/.gallery-linklog-$lgId.php")) === 1;
$f = $lgLinks(); $f['links'][$lgId]['endsAt'] = time() - 5; $f['links'][$lgId]['status'] = 'active'; write_data_file("$fxL/.gallery-links.php", $f);   // (an expired link)
$lgKeptExpired = $lgFile('') && $lgFile('-items');
$lgEndedView = $lgFrag("linkview=$lgId&tab=visitors")[1];
check('[share log] revoking or letting a link expire keeps its log (still readable in the tab); nothing but deleting removes it', $lgKeptRevoked && $lgKeptExpired && str_contains($lgEndedView, 'data-ip="127.0.0.1"'));
$lgDelRes = $lgAdmin(['action' => 'link_delete', 'id' => $lgId]);
check('[share log] deleting the link deletes its log files (the log, the per-item record and the queue)', !$lgFile('') && !$lgFile('-items') && !$lgFile('-q') && !isset($lgLinks()['links'][$lgId]));

// Someone using a link is not counted in the gallery's own view counts.
$lgMade2 = $lgAdmin(['action' => 'link_create', 'label' => 'second', 'note' => '', 'title_mode' => 'gallery', 'max_opens' => 0, 'ends_at' => time() + 600, 'locations' => 'public', 'heads_up' => 'default', 'scope_type' => 'all', 'downloads' => 'off', 'zip' => 'off']);
$lgTok2 = $lgLinks()['links'][$lgMade2['id']]['token'] ?? '';
[, , $lgGo2] = http_post("$baseL/?link=$lgTok2", ['go' => '1'], [], false); $lgCk2 = extract_cookie_header($lgGo2);
http_get("$baseL/?track=" . rawurlencode('Rome/r1.jpg'), ["Cookie: $lgCk2"]); http_get("$baseL/", ["Cookie: $lgCk2"]);
check('[share log] a link holder\'s views stay out of the gallery\'s view counts: the same ?track ping that counts for a visitor is refused (ok:false) for someone using a link', str_contains(http_get("$baseL/?track=" . rawurlencode('pub.jpg'))[1], '"ok":true') && str_contains(http_get("$baseL/?track=" . rawurlencode('pub.jpg'), ["Cookie: $lgCk2"])[1], '"ok":false'));
// The log is bounded: the latest 300 timeline lines and the latest 200 visitors are kept; the visitor who makes the 201st is
// let in by folding the least recently seen one into a count ("+ 1 earlier visitor").
$lgCapId = $lgMade2['id'] ?? '';
$lgCapFile = "$fxL/.gallery-linklog-$lgCapId.php";
$seed = read_data_file($lgCapFile); $seed['visitors'] = []; $seed['events'] = []; $seed['folded'] = 0;
for ($i = 0; $i < 200; $i++) {
    $vid = sprintf('seed%06d', $i);
    $seed['visitors'][$vid] = ['ip' => '10.1.' . intdiv($i, 250) . '.' . ($i % 250 + 1), 'ua' => 'Safari on iPhone', 'country' => '', 'first' => 1000 + $i, 'last' => 1000 + $i, 'opens' => 1, 'views' => 1];
}
for ($i = 0; $i < 300; $i++) $seed['events'][] = ['t' => 1000 + $i, 'v' => sprintf('seed%06d', $i % 200), 'k' => 'link'];
write_data_file($lgCapFile, $seed);
$lgFirefox = 'User-Agent: Mozilla/5.0 (X11; Linux x86_64; rv:120.0) Gecko/20100101 Firefox/120.0';
$f = $lgLinks(); unset($f['links'][$lgCapId]['lastOpen']); write_data_file("$fxL/.gallery-links.php", $f);   // (the same address pressing again within 15 minutes isn't a new opening)
http_post("$baseL/?link=$lgTok2", ['go' => '1'], [$lgFirefox], false);
$capLog = read_data_file($lgCapFile);
$capNew = array_filter($capLog['visitors'], fn ($v) => $v['ip'] === '127.0.0.1' && $v['ua'] === 'Firefox on Linux');
check('[share log] the log is bounded: a 201st visitor folds the least recently seen one into a count, and the timeline keeps its latest 300 lines', count($capLog['visitors']) === 200 && count($capNew) === 1 && !isset($capLog['visitors']['seed000000']) && isset($capLog['visitors']['seed000001']) && (int) $capLog['folded'] === 1 && count($capLog['events']) === 300 && $capLog['events'][0]['t'] === 1001 && ($capLog['events'][299]['k'] ?? '') === 'link' && ($capLog['events'][299]['v'] ?? '') === array_key_first($capNew));
check('[share log] ...and the Visitors tab says how many earlier visitors are no longer listed', str_contains($lgFrag("linkview=$lgCapId&tab=visitors")[1], '+ 1 earlier visitor'));
$lgHolderPage = http_get("$baseL/", ["Cookie: $lgCk2"])[1];
check('[share log] the page of a link holder carries the logging script, the public page and the owner\'s don\'t', str_contains($lgHolderPage, 'window.__linkLog = log') && !str_contains(http_get("$baseL/")[1], 'window.__linkLog = log') && !str_contains(http_get("$baseL/", ["Cookie: $lgAdminCookie"])[1], 'window.__linkLog = log'));

/* --------------------------------------------------------------------- */

echo "\n$passCount passed, $failCount failed\n";
exit($failCount > 0 ? 1 : 0);
