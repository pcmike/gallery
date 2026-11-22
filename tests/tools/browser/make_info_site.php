<?php
/** The browser suite's gallery for the info panel: folders Trip (info on, locations on) and Trip/Sub (takes both from Trip), Other (nothing chosen), photos with the
 *  EXIF a phone writes (made by hand: PHP has no EXIF writer), the second with a comment that tries to be markup. Usage: php make_info_site.php <dir> <index.php> */
[, $dir, $index] = $argv;
function exif_jpeg($path, $make, $model, $taken, $comment) {
    $im = imagecreatetruecolor(300, 200); imagefill($im, 0, 0, imagecolorallocate($im, 90, 120, 200)); ob_start(); imagejpeg($im); $jpeg = ob_get_clean();
    $ascii = function ($s) { return $s . "\0"; };
    $entries0 = [0x010F => $make, 0x0110 => $model];
    $entriesE = [0x9003 => $taken, 0x9011 => '+02:00'];
    if ($comment !== null) $entriesE[0x9286] = "ASCII\0\0\0" . $comment;
    $build = function ($entries, $base, $types) {
        ksort($entries); $n = count($entries); $head = pack('v', $n); $body = ''; $blob = ''; $dataBase = $base + 2 + 12 * $n + 4;
        foreach ($entries as $tag => $val) {
            $type = $types[$tag] ?? 2; $raw = $type === 2 ? $val . "\0" : $val; $count = strlen($raw);
            if ($type === 4) { $raw = pack('V', $val); $count = 1; }
            if (strlen($raw) <= 4) $field = str_pad($raw, 4, "\0"); else { $field = pack('V', $dataBase + strlen($blob)); $blob .= $raw . (strlen($raw) % 2 ? "\0" : ''); }
            $body .= pack('vvV', $tag, $type, $count) . $field;
        }
        return [$head . $body . pack('V', 0), $blob];
    };
    $size = function ($entries, $types) { $b = 0; foreach ($entries as $tag => $v) { $t = $types[$tag] ?? 2; $len = $t === 2 ? strlen($v) + 1 : ($t === 4 ? 4 : strlen($v)); if ($len > 4) $b += $len + ($len % 2); } return 2 + 12 * count($entries) + 4 + $b; };
    $types = [0x8769 => 4, 0x9286 => 7];
    $entries0[0x8769] = 0; $o0 = 8; $oE = $o0 + $size($entries0, $types); $entries0[0x8769] = $oE;
    [$t0, $x0] = $build($entries0, $o0, $types); [$tE, $xE] = $build($entriesE, $oE, $types);
    $seg = "Exif\0\0" . 'II' . pack('v', 42) . pack('V', 8) . $t0 . $x0 . $tE . $xE;
    file_put_contents($path, substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($seg) + 2) . $seg . substr($jpeg, 2));
}
$guard = "<?php http_response_code(404); exit; ?>\n";
foreach (['Trip', 'Trip/Sub', 'Other'] as $d) mkdir("$dir/$d", 0777, true);
exif_jpeg("$dir/Trip/1.jpg", 'Apple', 'iPhone 15 Pro', '2026:10:06 14:32:10', null);
exif_jpeg("$dir/Trip/2.jpg", 'Google', 'Pixel 8', '2026:10:07 09:05:00', '<img src=x onerror="window.__xss=1"> <script>window.__xss=2</script>');
exif_jpeg("$dir/Trip/3.jpg", 'Apple', 'iPhone 15 Pro', '2026:10:08 18:20:30', null);
exif_jpeg("$dir/Trip/Sub/4.jpg", 'Sony', 'ILCE-7M4', '2026:10:09 11:00:00', null);
exif_jpeg("$dir/Other/5.jpg", 'Apple', 'iPhone 15 Pro', '2026:10:06 14:32:10', null);
exif_jpeg("$dir/Other/6.jpg", 'Apple', 'iPhone 15 Pro', '2026:10:06 14:33:10', null);
$loc = ['lat' => 48.8566, 'lng' => 2.3522, 'label' => ''];
file_put_contents("$dir/Trip/.gallery-items.php", $guard . json_encode(['items' => ['1.jpg' => ['location' => $loc], '2.jpg' => ['location' => $loc], '3.jpg' => ['location' => $loc]], 'mediaInfo' => true, 'locationEnabled' => true]) . "\n");
file_put_contents("$dir/Trip/Sub/.gallery-items.php", $guard . json_encode(['items' => ['4.jpg' => ['location' => $loc]]]) . "\n");
file_put_contents("$dir/.gallery-config.php", $guard . json_encode(['AUTO_GROUP_BY_FOLDER' => true, 'ENABLE_PHOTO_LOCATION' => true, 'INFO_COMMENTS' => true]) . "\n");
copy($index, "$dir/index.php");
