  <?= owner_phone_bar_css() ?>
  html:has(> body.inframe) { scrollbar-gutter:stable; }  /* (the scrollbar's room is always kept, blank when there is nothing to scroll, so a short page is not centred a few pixels differently from a long one) */
  body.inframe .admin-bar, body.inframe .admin-side, body.inframe .admin-folder-head { display:none !important; }
  body.inframe .admin-layout { display:block; max-width:968px; margin:0 auto; padding:18px 24px 64px; }  /* (the classic admin's column width, centred in the frame) */
</style>
</head>
<body<?= $inframe ? ' class="inframe"' : ' class="pb-on"' ?>>
<?= ui_icon_sprite() ?>
<?= $inframe ? shell_frame_script('admin') : owner_phone_bar('admin', $files, $adminFolderPath, (bool) ($adminHidden || $adminEdited['total'] > 0), '', admin_nav_counts($dir, $files), (int) $adminEdited['total']) ?>
<header class="admin-bar">
  <div class="admin-bar-inner">
    <?php // Back to the page you were editing: a folder's admin returns to that folder, not to the front page. ?>
    <?php $backLabel = $adminFolderPath === null ? (string) ($GLOBALS['pageTitle'] ?? 'Gallery') : ((($backMeta = folder_display_meta($dir, $adminFolderPath))['label'] ?? '') !== '' ? $backMeta['label'] : basename($adminFolderPath)); ?>
    <a class="back" href="<?= htmlspecialchars($adminFolderPath === null ? gallery_root_href() : '?folder=' . rawurlencode($adminFolderPath), ENT_QUOTES, 'UTF-8') ?>" title="Back to <?= htmlspecialchars($backLabel, ENT_QUOTES, 'UTF-8') ?>"><?= ui_icon('chev-left') ?><span><?= htmlspecialchars($backLabel, ENT_QUOTES, 'UTF-8') ?></span></a>
    <h1>Gallery Admin</h1>
    <form method="post" class="logout-form">
      <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
      <input type="hidden" name="action" value="logout">
      <button type="submit" class="secondary" title="Log out" aria-label="Log out"><span class="lo-text">Log out</span><?= ui_icon('logout') ?></button>
    </form>
  </div>
</header>
<div class="admin-layout">
<aside class="admin-side">
  <div class="side-group" role="group" aria-label="The whole gallery">
  <div class="side-label">The whole gallery</div>
  <?php $navCounts = admin_nav_counts($dir, $files); ?>
  <a href="#features" data-tab="features" class="side-link">Features<?= admin_nav_badges($navCounts['features']) ?></a>
  <?php if ($adminHidden || $adminEdited['total'] > 0): ?>
    <a href="#hidden" data-tab="hidden" class="side-hidden edited">Edited <span class="count"><?= number_format($adminEdited['total']) ?></span></a>
  <?php endif; ?>
  <?php if (ENABLE_SHARE_LINKS): ?>
    <a href="#links" data-tab="links" class="side-link">Shares<?= admin_nav_badges($navCounts['links']) ?></a>
  <?php endif; ?>
  <?php if (collections_any()): ?>
    <a href="#collections" data-tab="collections" class="side-link">Collections <span class="count"><?= count(collections_list()) ?></span></a>
  <?php endif; ?>
  <a href="#site" data-tab="site" class="side-link">Settings<?= admin_nav_badges($navCounts['site']) ?></a>
  <?php if (visits_tab_wanted()): ?>
    <a href="#visits" data-tab="visits" class="side-link">Visitors<?= admin_nav_badges($navCounts['visits']) ?></a>
  <?php endif; ?>
  </div>
  <details class="admin-folders" open>
    <summary>Folders <span class="here">&middot; <?= htmlspecialchars($folderLabel, ENT_QUOTES, 'UTF-8') ?></span></summary>
    <?= render_admin_folder_nav($files, $adminFolderPath) ?: '<p class="muted">No subfolders.</p>' ?>
  </details>
</aside>
<main class="admin-main"<?= !empty($GLOBALS['__admin_fresh']) ? ' data-land="features"' : '' ?>>
  <div class="admin-folder-head" id="admin-folder-head">
    <div class="afh-title"><span class="muted"><?= $adminCollection !== null ? 'Collection' : 'Folder' ?></span> <strong<?= $adminCollection !== null ? ' class="coll"' : '' ?>><?= htmlspecialchars($folderLabel, ENT_QUOTES, 'UTF-8') ?></strong></div>
    <nav class="admin-tabs" role="tablist" aria-label="<?= $adminCollection !== null ? 'This collection' : 'This folder' ?>">
      <a href="#settings" role="tab" data-tab="settings"><?= $adminCollection !== null ? 'Collection settings' : 'Folder settings' ?></a>
<?php if ($adminCollection === null): ?>
      <a href="#notes" role="tab" data-tab="notes">Notes <span class="count"><?= count($mdCandidates) ?></span></a>
<?php endif; ?>
    </nav>
  </div>
  <?php if ($resultMessage): ?>
    <div class="flash <?= htmlspecialchars($resultMessage['type'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($resultMessage['text'], ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <div id="server-probe" hidden data-any-private="<?= !empty($GLOBALS['__lock_probe']['anyPrivate']) ? '1' : '0' ?>" data-photo="<?= htmlspecialchars($GLOBALS['__lock_probe']['photo'] ?? '', ENT_QUOTES, 'UTF-8') ?>" data-cache="<?= htmlspecialchars($GLOBALS['__lock_probe']['cache'] ?? '', ENT_QUOTES, 'UTF-8') ?>" data-folder="<?= htmlspecialchars($GLOBALS['__lock_probe']['folder'] ?? '', ENT_QUOTES, 'UTF-8') ?>" data-folder-url="<?= htmlspecialchars($GLOBALS['__lock_probe']['folderUrl'] ?? '', ENT_QUOTES, 'UTF-8') ?>" data-files="<?= (int) ($GLOBALS['__lock_probe']['count'] ?? 0) ?>" data-visits="<?= htmlspecialchars(is_file(visitlog_path()) ? (rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/') . basename(visitlog_path()) : '', ENT_QUOTES, 'UTF-8') ?>" data-share-probe="<?= htmlspecialchars(ENABLE_SHARE_LINKS ? (rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/') . LINK_PROBE_TOKEN : '', ENT_QUOTES, 'UTF-8') ?>"></div>
  <div class="tab-panel" data-tab="features">
<?= render_admin_features_panel($adminEdited, $adminHidden ?: []) ?>
  </div>
<?php if ($adminHidden || $adminEdited['total'] > 0): ?>
  <div class="tab-panel" data-tab="hidden">
<?php render_admin_hidden_panel($adminHidden, $csrfToken); ?>
  </div>
<?php endif; ?>
<?php if (ENABLE_SHARE_LINKS): ?>
  <div class="tab-panel" data-tab="links">
<?php render_admin_links_panel($csrfToken, $dir, $files); ?>
  </div>
<?php endif; ?>
<?php if (visits_tab_wanted()): ?>
  <div class="tab-panel" data-tab="visits">
<?php render_admin_visits_panel($csrfToken, $dir, $files); ?>
  </div>
<?php endif; ?>
  <div class="tab-panel" data-tab="settings">
  <?php if (ENABLE_PHOTO_LOCATION && empty($itemsHere['locationEnabled']) && $adminSummary['fromFile'] > 0): $unsavedCount = $adminSummary['fromFile']; ?>
    <div class="panel loc-unsaved-summary">
        <strong><?= ui_icon('warn') ?> <?= $unsavedCount ?> photo<?= $unsavedCount === 1 ? '' : 's' ?> here <?= $unsavedCount === 1 ? 'has' : 'have' ?> a location in the file.</strong>
        <span class="muted">Nothing is public while this folder's location switch is off. Switching it on makes <?= $unsavedCount === 1 ? 'that location' : 'these ' . $unsavedCount . ' locations' ?> public on the gallery and the map, except for photos whose <em>Location</em> switch (in the pencil's editor) you turn off.</span>
    </div>
  <?php endif; ?>
  <?php if ($adminCollection !== null): ?>
  <?php render_admin_collection_panel($dir, $files, $adminCollection, $csrfToken); ?>
  <?php elseif ($adminFolderPath !== null): ?>
  <section class="panel" id="settings">
    <h2 class="section-title">This folder's own settings</h2>
    <?php render_admin_identity_form($itemsHere, $csrfToken, $adminFolderPath, suggest_folder_dates($dir, folder_files_under($files, $adminFolderPath)), 'main'); ?>
    <?php render_admin_folder_switches($itemsHere, $csrfToken, $adminFolderPath, folder_privacy_counts($dir, $files, $adminFolderPath)); ?>
    <?php render_admin_identity_form($itemsHere, $csrfToken, $adminFolderPath, null, 'sort'); ?>
    <?php if (AUTO_GROUP_BY_FOLDER) render_admin_collections_section($dir, $files, $adminFolderPath, $csrfToken); ?>
    <?php render_admin_locations_section($itemsHere, $csrfToken, $adminFolderPath, $locWarn, $locStatus); ?>
  </section>
  <?php else: ?>
  <section class="panel" id="settings">
    <h2 class="section-title">Gallery settings</h2>
    <?php render_admin_identity_form($itemsHere, $csrfToken, null, suggest_folder_dates($dir, $files), 'main'); ?>
    <?php render_admin_root_downloads($csrfToken); ?>
    <?php render_admin_identity_form($itemsHere, $csrfToken, null, null, 'sort'); ?>
    <?php if (AUTO_GROUP_BY_FOLDER) render_admin_collections_section($dir, $files, '', $csrfToken); ?>
    <?php render_admin_locations_section($itemsHere, $csrfToken, null, $locWarn, $locStatus); ?>
  </section>
  <?php endif; ?>

  <?php
  // Display-name fields for root-level filename/explicit groups (a
  // folder-group's own name is edited from inside that folder — see
  // above). Only groups that actually render as a named box get one; a
  // lone standalone single has no separate "name" shown anywhere to edit.
  if ($adminFolderPath === null):
  ?>
  <div class="panel">
    <h2 style="margin-top:0;">Group names</h2>
    <?php $anyGroupNames = false; foreach ($groups as $gKey => $gFiles):
      if (isset($folderGroupKeys[$gKey])) continue;
      if (count($gFiles) < 2 && !isset($explicitGroupKeys[$gKey])) continue;
      $anyGroupNames = true;
      $isExplicit = isset($explicitGroups[$gKey]);
    ?>
      <form method="post" class="field-row" style="align-items:flex-end;">
        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
        <input type="hidden" name="action" value="save_group_label">
        <input type="hidden" name="group_key" value="<?= htmlspecialchars($gKey, ENT_QUOTES, 'UTF-8') ?>">
        <div class="field">
          <label><?= htmlspecialchars($gKey, ENT_QUOTES, 'UTF-8') ?> (<?= count($gFiles) ?> item<?= count($gFiles) === 1 ? '' : 's' ?>)</label>
          <input type="text" name="display_name" aria-label="Group name" value="<?= htmlspecialchars($isExplicit ? $explicitGroups[$gKey]['name'] : ($itemsHere['groups'][$gKey]['displayName'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" <?= $isExplicit ? 'disabled' : '' ?>>
        </div>
        <?php if (!$isExplicit): ?><button type="submit">Save</button><?php else: ?><span class="muted">Controlled by {group:} in a .md</span><?php endif; ?>
      </form>
    <?php endforeach; if (!$anyGroupNames): ?>
      <p class="muted">No multi-photo groups at the root yet.</p>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  </div>
<?php if ($adminCollection === null): ?>
  <div class="tab-panel" data-tab="notes">
  <div class="notes-layout">
  <div class="notes-main">
  <section class="panel" id="notes">
    <h2 class="section-title">Notes <span class="muted">(<?= count($mdCandidates) ?> .md file<?= count($mdCandidates) === 1 ? '' : 's' ?> in this folder)</span></h2>
    <?php if (empty($mdCandidates)): ?>
      <p class="muted">No .md files here yet. A notes file is a short text box (with optional sold/reserved markers) shown above the photos.</p>
    <?php endif; ?>
    <?php foreach ($mdCandidates as $mdFile):
      $isActive = isset($activeMd[$mdFile]);
      $raw = (string) @file_get_contents($adminFolderReal . '/' . $mdFile);
      // Shown exactly as stored, {gallery} line included: what you see is what a save writes. (Hiding the line used to
      // write it back without the marker, which quietly switched the notes off.)
      $editable = $raw;
    ?>
      <div class="md-file">
        <div class="md-file-head">
          <strong><?= htmlspecialchars($mdFile, ENT_QUOTES, 'UTF-8') ?></strong>
          <span class="md-badge <?= $isActive ? 'active' : 'inactive' ?>"><?= $isActive ? 'active' : 'not active' ?></span>
          <?php if (!$isActive): ?>
            <form method="post" style="display:inline;">
              <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
              <input type="hidden" name="action" value="add_gallery_marker">
              <input type="hidden" name="md_file" value="<?= htmlspecialchars($mdFile, ENT_QUOTES, 'UTF-8') ?>">
              <button type="submit" class="secondary">Add {gallery} marker</button>
            </form>
          <?php endif; ?>
        </div>
        <form method="post" onsubmit="return admConfirmSaveAs(this);">
          <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
          <input type="hidden" name="action" value="save_notes">
          <input type="hidden" name="md_file" value="<?= htmlspecialchars($mdFile, ENT_QUOTES, 'UTF-8') ?>">
          <textarea name="content" class="adm-textarea" aria-label="Contents of this notes file"><?= htmlspecialchars($editable, ENT_QUOTES, 'UTF-8') ?></textarea>
          <div class="field-row md-actions">
            <div class="field">
              <label>Save as a different filename (optional)</label>
              <input type="text" name="new_name" class="adm-newname" placeholder="leave blank to overwrite this file">
            </div>
            <button type="submit">Save</button>
          </div>
        </form>
        <form method="post" onsubmit="return confirm('Delete <?= htmlspecialchars(addslashes($mdFile), ENT_QUOTES, 'UTF-8') ?>? This can\'t be undone.');" style="margin-top:6px;">
          <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
          <input type="hidden" name="action" value="delete_notes">
          <input type="hidden" name="md_file" value="<?= htmlspecialchars($mdFile, ENT_QUOTES, 'UTF-8') ?>">
          <button type="submit" class="danger">Delete</button>
        </form>
      </div>
    <?php endforeach; ?>
    <details class="subtle md-file" <?= empty($mdCandidates) ? 'open' : '' ?>>
      <summary><strong>+ New notes file</strong></summary>
      <form method="post" onsubmit="return admConfirmSaveAs(this);">
        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
        <input type="hidden" name="action" value="save_notes">
        <input type="hidden" name="md_file" value="">
        <textarea name="content" class="adm-textarea" aria-label="Contents of the new notes file">{gallery}
</textarea>
        <div class="field-row md-actions">
          <div class="field">
            <label>Filename</label>
            <input type="text" name="new_name" class="adm-newname" placeholder="notes.md" required>
          </div>
          <button type="submit">Create</button>
        </div>
      </form>
    </details>
  </section>
  </div>
  <aside class="notes-reference" aria-label="Notes reference"><?= render_admin_notes_reference() ?></aside>
  </div>
  </div>
<?php endif; ?>
<?php if (collections_any()): ?>
  <div class="tab-panel" data-tab="collections">
<?php render_admin_collections_overview($dir, $files, $csrfToken); ?>
  </div>
<?php endif; ?>
  <div class="tab-panel" data-tab="site">
<?php render_admin_health_panel(); render_admin_site_settings($csrfToken); ?>
  </div>
  </div>
</main>
</div>
<script>
