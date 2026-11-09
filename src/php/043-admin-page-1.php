</script>
</body>
</html>
<?php
}

/**
 * Renders the full ?admin page: folder nav, this folder's own identity
 * (display name/cover/location toggle, when viewing a subfolder), every
 * .md here (active or not) with a raw-text editor, and the photo/video
 * grid itself — caption, location, web link per item, usable at a phone
 * width same as the public gallery already holds itself to.
 */
function render_admin_page($dir, $files, $adminFolderPath, $adminFolderReal, $adminFiles, $groups, $folderGroupKeys, $explicitGroups, $explicitGroupKeys, $videoDurations, $mdCandidates, $resultMessage) {
    $version = htmlspecialchars(GALLERY_VERSION, ENT_QUOTES, 'UTF-8');
    $csrfToken = htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8');
    $folderLabel = $adminFolderPath ?? 'Root';
    $GLOBALS['__admin_folder_label'] = $folderLabel;
    $adminHidden = admin_any_hidden($dir, $files, $folderGroupKeys) ? admin_hidden_data($dir, $files) : null;
    $adminEdited = admin_edited_overview($dir, $files, $adminHidden);   // (what was set by hand: the Edited tab)
    $fresh = (function () { $st = gallery_config_load(); if (!in_array($st['status'], ['absent', 'ok'], true)) return false; foreach (array_keys($st['cfg'] ?? []) as $k) if (!in_array($k, ['ADMIN_PASSWORD', 'PRIVATE_VIEW_PASSWORD'], true)) return false; return true; })();   // (nothing configured yet: a fresh install)
    $GLOBALS['__admin_fresh'] = $fresh;
    $GLOBALS['__lock_probe'] = lock_probe_targets($dir, $files);
    $GLOBALS['__lock_probe']['anyPrivate'] = !empty($adminHidden['folders']) || !empty($adminHidden['items']);   // (the lock check only worries once something is private)
    $GLOBALS['__admin_files'] = $files;
    $itemsHere = items_read($adminFolderReal);
    $adminCollection = $adminFolderPath !== null && AUTO_GROUP_BY_FOLDER ? collection_at($dir, $adminFolderPath, $files) : null;   // (a collection has a settings page of its own, and no folder on disk)
    // What the gallery already knows from this folder's files (nothing is read here: see the media records), for the warning beside the
    // location switch and the line under it.
    $adminSummary = media_folder_summary($dir, $adminFolderReal, $adminFiles);
    $gpsCount = $adminSummary['fromFile'];
    $locWarn = 'Turning this on shows the location of ' . ($gpsCount > 0
        ? $gpsCount . ' photo' . ($gpsCount === 1 ? '' : 's') . ' here'
        : 'any photo here that has one')
        . ' to visitors, on the gallery and the map. A photo can opt out with its own Location switch (press its pencil). You and private-view visitors always see every location.';
    $s_ = $adminSummary;
    $locStatus = '';
    if (media_meta_enabled() && ENABLE_PHOTO_LOCATION) {
        $locStatus = 'What the gallery has read from the files here: <strong>' . $s_['found'] . '</strong> with a location, ' . $s_['none'] . ' without'
            . ($s_['unreadable'] > 0 ? ', <strong class="warn-text">' . $s_['unreadable'] . ' couldn\'t be read</strong>' : '')
            . ($s_['unread'] > 0 ? ', <strong>' . $s_['unread'] . ' not read yet</strong>' : '')
            . '.' . (($s_['unread'] > 0 || $s_['unreadable'] > 0) ? ' <a href="#site" onclick="return adminGoTo(\'site\', \'media-panel\');">Prepare media</a> reads the rest.' : '');
    }
    $mdScan = scan_gallery_md_files($adminFolderReal);
    $activeMd = array_fill_keys($mdScan['files'], true);
    $inframe = !empty($_GET['inframe']);
    if ($inframe) shell_frame_headers();   // (only this signed-in request, from this site: every other admin page stays unframeable)
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<?php if (!$inframe): ?>
<script>(function () {   // on a computer the admin is the shell (this page is what the shell frames, and what a phone gets); ?classic=1 stays on this page
  try { if (/[?&]classic=1/.test(location.search)) document.cookie = 'galleryClassic=1; path=/; max-age=31536000; SameSite=Lax'; if (document.cookie.indexOf('galleryClassic=1') >= 0) return; } catch (e) {}
  if (window.matchMedia && window.matchMedia('(min-width: 801px)').matches) {
    var h = (location.hash || '').slice(1);
    location.replace(location.search.replace(/[?&]classic=1/, '') + '&shell=1' + (/^(settings|notes|features|hidden|links|collections|site)$/.test(h) ? '&tab=' + h : ''));
  }
})();</script>
<?php endif; ?>
<title>Gallery Admin — v<?= $version ?></title>
<style>
  :root { --bg:#0f0f10; --card:#1a1a1c; --text:#eaeaea; --muted:#8a8a8f; --accent:#5865f2; --ok:#3ba55c; --err:#ed4245; }
  * { box-sizing: border-box; }
  body { margin:0; background:var(--bg); color:var(--text); font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; line-height:1.5; }
  .wrap { max-width:960px; margin:0 auto; padding:24px 16px 64px; }  /* (hidden-items page) */
  a { color:#aab2ff; text-decoration:none; }
  h1 { font-size:1.4rem; margin:0 0 4px; }
  h2 { font-size:1.1rem; margin:28px 0 10px; }
  .muted { color:var(--muted); font-size:0.85rem; }
  /* Layout: a sticky bar, a folder tree beside the content, and sections
     you can jump to, so a long page is never a scroll hunt. */
  <?= ui_icon_css() ?>
