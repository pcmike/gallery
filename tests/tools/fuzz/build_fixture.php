<?php
// Builds a "kitchen sink" gallery: every feature on, awkward names everywhere.
// Usage: php build_fixture.php <output-dir> [path/to/index.php]
$d = rtrim($argv[1] ?? (__DIR__ . '/site'), '/');
@mkdir($d, 0777, true);
$src = file_get_contents($argv[2] ?? (dirname(__DIR__, 3) . '/index.php'));
// A setting is define('NAME', gallery_setting('NAME', default...)); replace the whole define.
foreach ([
  'AUTO_GROUP_BY_FOLDER' => 'true', 'AUTO_GROUP_BY_FILENAME' => 'true', 'ENABLE_VIDEO' => 'true', 'ENABLE_THUMBNAIL_CACHE' => 'true',
  'TRACK_VIEWS' => 'true', 'SHOW_VIEW_COUNTS_PUBLICLY' => 'true', 'ENABLE_PHOTO_LOCATION' => 'true', 'ENABLE_MAP' => 'true',
  'ADMIN_PASSWORD' => "'adm-pw'", 'PRIVATE_VIEW_PASSWORD' => "'viewer-pw'",
] as $name => $value) {
  $src = preg_replace("/define\\('" . $name . "',\\s*gallery_setting\\([^)]*\\)\\)/", "define('$name', $value)", $src, 1, $n);
  if ($n !== 1) { fwrite(STDERR, "patch miss: $name\n"); exit(1); }
}
file_put_contents("$d/index.php", $src);
function jpg($path, $r=100,$g=100,$b=100) { @mkdir(dirname($path), 0777, true); $im=imagecreatetruecolor(80,60); imagefill($im,0,0,imagecolorallocate($im,$r,$g,$b)); imagejpeg($im,$path); }
function gpsjpg($path,$lat,$lng) {
  $im=imagecreatetruecolor(80,60); ob_start(); imagejpeg($im); $j=ob_get_clean();
  $rat=function($dms){$o='';foreach($dms as [$n,$dd])$o.=pack('VV',$n,$dd);return $o;};
  $off=8+2+12+4; $ds=$off+2+12*4+4; $la=$rat([[$lat,1],[0,1],[0,1]]); $lo=$rat([[$lng,1],[0,1],[0,1]]);
  $t='II'.pack('v',42).pack('V',8).pack('v',1).pack('vvVV',0x8825,4,1,$off).pack('V',0).pack('v',4)
   .pack('vvV',1,2,2).'N'."\0\0\0".pack('vvVV',2,5,3,$ds).pack('vvV',3,2,2).'E'."\0\0\0".pack('vvVV',4,5,3,$ds+strlen($la)).pack('V',0).$la.$lo;
  $e="Exif\0\0".$t; @mkdir(dirname($path),0777,true);
  file_put_contents($path, substr($j,0,2)."\xFF\xE1".pack('n',strlen($e)+2).$e.substr($j,2));
}
function data($path, $arr) { file_put_contents($path, "<?php http_response_code(404); exit; ?>\n".json_encode($arr, JSON_HEX_TAG)); }
jpg("$d/loose1.jpg"); jpg("$d/loose2.jpg",200,50,50); jpg("$d/dp104_01.jpg"); jpg("$d/dp104_02.jpg"); jpg("$d/secret.jpg",9,9,9);
gpsjpg("$d/geo1.jpg", 48, 2); gpsjpg("$d/Asia/geo2.jpg", 35, 139);
file_put_contents("$d/clip.mp4", random_bytes(50000));
$h=new Imagick("$d/geo1.jpg"); $h->setImageFormat('heic'); $h->writeImage("$d/phone.heic");
foreach (['Europe/France/Paris','Europe/France/Lyon','Europe/Germany','Asia','Day 1','Weird & Name','Üñí','quo\'te','a<b>c',"semi;colon", 'dot.dot', '100%'] as $f) jpg("$d/$f/p.jpg");
jpg("$d/Europe/intro.jpg"); jpg("$d/Europe/France/Paris/eiffel.jpg"); jpg("$d/Europe/France/Paris/louvre.jpg");
data("$d/Europe/Germany/.gallery-items.php", ['restriction'=>'private','items'=>[],'groups'=>[]]);
data("$d/.gallery-items.php", ['items'=>[
  'loose1.jpg'=>['caption'=>'He said "hi" & <b>left</b> \'x\'','web'=>'https://example.com/?a=1&b=2','location'=>['lat'=>48.85,'lng'=>2.29,'label'=>'Tour <Eiffel>'],'locationVisibility'=>'show'],
  'secret.jpg'=>['restriction'=>'private','caption'=>'hidden caption'],
  'geo1.jpg'=>['location'=>['lat'=>48.0,'lng'=>2.0,'label'=>''],'locationVisibility'=>'show'],
], 'groups'=>[], 'displayName'=>null,'cover'=>null,'locationEnabled'=>true]);
data("$d/Asia/.gallery-items.php", ['items'=>['geo2.jpg'=>['location'=>['lat'=>35.0,'lng'=>139.0,'label'=>'Tokyo'],'locationVisibility'=>'show']],'groups'=>[],'displayName'=>'Asia & <Pacific>','description'=>'Hot "and" humid <i>!</i>','dates'=>'1-5 <Jan>','sortOrder'=>1,'cover'=>null,'locationEnabled'=>true]);
file_put_contents("$d/forsale.md", "{gallery}\n# Trip & <Sale>\n\nLoved Europe and Asia. Also dp104 here.\n\n{group: My <Box>} {photos: loose2.jpg, nope.jpg} {color: #ff0000}\n\n- list **bold** _it_ ~~x~~ `code` [link](https://example.com) [bad](javascript:alert(1))\n\n```\n{sold}\n```\n\n{sold} thing geo1\n");
file_put_contents("$d/Europe/notes.md", "{gallery}\nFrance rocks {color: blue}\n");
echo "built\n";
