</style>
</head>
<body>
<?= ui_icon_sprite() ?>
<header class="sh-bar">
  <div class="sh-lead"><nav class="sh-path" id="sh-path" aria-label="Where you are"><a class="sh-title" href="?admin&amp;shell=1" data-go=""><?= $e($title) ?></a></nav></div>
  <span class="map-combo sh-tools" id="sh-tools">
    <button type="button" class="map-combo-btn sh-tool" data-tool="settings" aria-pressed="false"><?= ui_icon('gear') ?></button>
    <button type="button" class="map-combo-btn sh-tool" data-tool="notes" aria-pressed="false"><?= ui_icon('notes') ?></button>
  </span>
  <span class="map-combo" id="sh-alone-box"><a class="map-combo-btn" id="sh-alone" href="<?= $e($rootHref . ($adminFolderPath !== null ? '?folder=' . rawurlencode($adminFolderPath) : '')) ?>" title="Open on its own" aria-label="Open on its own"><?= ui_icon('external') ?></a></span>
  <form method="post" action="?admin">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="logout">
    <span class="map-combo"><button type="submit" class="map-combo-btn" title="Log out" aria-label="Log out"><?= ui_icon('logout') ?></button></span>
  </form>
</header>
<div class="sh-body">
  <aside class="sh-side">
    <div class="sh-label">The whole gallery</div>
    <div class="sh-whole" id="sh-whole">
      <?= shell_whole_nav($dir, $files, $folderGroupKeys) ?>
    </div>
    <div class="sh-label folders">Folders</div>
    <?= shell_folder_tree($files, $adminFolderPath) ?>
  </aside>
  <main class="sh-main"><iframe id="sh-frame" title="<?= $e($title) ?>" src="<?= $e($src) ?>"></iframe></main>
</div>
<script type="application/json" id="sh-cfg"><?= json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) ?></script>
<script>
