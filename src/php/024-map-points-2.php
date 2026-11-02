<?php if ($lazy): ?>
  // Nothing is fetched from anywhere until a visitor asks for the map (see showLazyMap in the page script).
  (window.__lazyMaps = window.__lazyMaps || {})['<?= htmlspecialchars($mapId, ENT_QUOTES, 'UTF-8') ?>'] = draw;
<?php else: ?>
  draw();
<?php endif; ?>
})();
</script>
<?php
}


