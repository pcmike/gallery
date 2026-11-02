<?php else: ?>
  const webLink = document.getElementById('lightbox-web');
  const miniExpanded = false;
  function collapseMiniMap() {}
  function mapEditStart() {}
  function mapEditEnd() {}
  function updateLightboxExtras() {
    const web = webLinks[current];
    if (!webLink) return;
    if (web) { webLink.href = web; webLink.title = 'Information link: ' + web; webLink.hidden = false; } else { webLink.hidden = true; webLink.removeAttribute('href'); webLink.title = ''; }
  }
<?php endif; ?>
