<?php if (is_admin_browser()): ?>
  // Select: the owner picks photos with a tap and acts on all of them: make them private or public in one go, and (where the photos are
  // laid out one by one) place them next to each other by holding one and dragging it beside another (on a touch screen a long press, so
  // scrolling still works). The server keeps each placement as a stack that follows its anchor through every sort; the page reloads to
  // show a placement. Privacy changes are made in place.
  (function () {
    const btn = document.getElementById('select-btn'), bar = document.getElementById('select-bar'), gal = document.querySelector('.gallery');
    if (!btn || !bar || !ownerState) return;
    const pageFolder = <?= json_encode($currentFolderPath === null ? '' : $currentFolderPath) ?>;
    const stacks = <?= json_encode($arrangeStacks) ?>;
