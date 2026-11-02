<?php
/**
 * Builds the shipped index.php from the parts in src/.
 *
 *   php build.php            writes index.php next to this file
 *   php build.php --check    changes nothing; exits 1 if index.php is not what the parts build
 *   php build.php OUT.php    writes the build to another path (the tests use this)
 *
 * src/manifest.txt lists the parts in order; the build is their plain concatenation, byte for byte.
 * A part is a stretch of the file, not a PHP file of its own (it can start or end inside a function
 * or inside a <script> block), so `php -l` only makes sense on the built index.php. The .js and .css
 * parts hold exactly what sat between <script> and </script> (or <style> and </style>); the
 * neighbouring .php parts hold the tags.
 */
$root = __DIR__;
$list = file($root . '/src/manifest.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if ($list === false) { fwrite(STDERR, "src/manifest.txt not found\n"); exit(2); }
$out = '';
foreach ($list as $rel) {
    $rel = trim($rel);
    if ($rel === '' || $rel[0] === '#') continue;
    if (str_contains($rel, '..') || !preg_match('#^[a-z0-9/._-]+$#i', $rel)) { fwrite(STDERR, "bad path in manifest: $rel\n"); exit(2); }
    $part = @file_get_contents($root . '/src/' . $rel);
    if ($part === false) { fwrite(STDERR, "missing part: $rel\n"); exit(2); }
    $out .= $part;
}
$arg = $argv[1] ?? '';
if ($arg === '--check') {
    $have = @file_get_contents($root . '/index.php');
    if ($have === $out) { echo "index.php is up to date with src/ (" . strlen($out) . " bytes, " . count($list) . " parts)\n"; exit(0); }
    fwrite(STDERR, "index.php is NOT what src/ builds (" . strlen((string) $have) . " bytes vs " . strlen($out) . "): run php build.php\n");
    exit(1);
}
$dest = $arg !== '' ? $arg : $root . '/index.php';
if (file_put_contents($dest, $out) !== strlen($out)) { fwrite(STDERR, "could not write $dest\n"); exit(2); }
echo "wrote $dest (" . strlen($out) . " bytes, " . count($list) . " parts)\n";
