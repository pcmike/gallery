  let current = 0;
  let lbEdit = null;   // the owner's editor in the viewer, when signed in as the owner
  const lightbox = document.getElementById('lightbox');
  const lightboxImg = document.getElementById('lightbox-img');
  const lightboxVideo = document.getElementById('lightbox-video');
  const caption = document.getElementById('lightbox-caption');

  function isVideoFile(name) {
    return /\.(mp4|mov)$/i.test(name);
  }

  function cssEscape(value) {
    return (window.CSS && CSS.escape) ? CSS.escape(value) : value.replace(/["\\]/g, '\\$&');
  }

  // ?find=<file> (only the owner's cover picture on the Edited tab links this way): that tile is scrolled to the middle of the screen
  // and ringed for a moment, so the grid can be looked through for another cover. Plain folder links never carry it, and a folder still
  // opens at the top for everyone else.
  (function () {
    const m = /[?&]find=([^&#]+)/.exec(location.search); if (!m) return;
    let file; try { file = decodeURIComponent(m[1].replace(/\+/g, ' ')); } catch (e) { return; }
    const tile = document.querySelector('.card[data-file="' + cssEscape(file) + '"]'); if (!tile) return;
    const go = function () { tile.scrollIntoView({ block: 'center' }); };
    go(); tile.classList.add('find-ring'); if (/[?&]as=cover(&|#|$)/.test(location.search)) tile.classList.add('as-cover');
    window.addEventListener('load', go);   // (pictures finishing can move it)
    setTimeout(function () { tile.classList.remove('find-ring'); }, 3500);
  })();

  // Arriving on #group-<name> (a pin of the map on another page, a copied link to one photo, or that address being opened again on this
  // page): that tile is brought to the middle of the screen and ringed for a moment, the same mark as a pin pressed on this page.
  (function () {
    let timer = null, ringedTile = null;
    function land(first) {
      if (/[?&]find=/.test(location.search) || location.hash.indexOf('#group-') !== 0) return;
      let id; try { id = decodeURIComponent(location.hash.slice(1)); } catch (e) { return; }
      const tile = document.getElementById(id); if (!tile || !(tile.classList.contains('card') || tile.classList.contains('group-box'))) return;
      const go = function () { tile.scrollIntoView({ block: 'center' }); };
      go(); if (ringedTile) ringedTile.classList.remove('find-ring'); clearTimeout(timer);
      tile.classList.add('find-ring'); ringedTile = tile;
      if (first) window.addEventListener('load', go);   // (pictures finishing can move it)
      timer = setTimeout(function () { tile.classList.remove('find-ring'); ringedTile = null; }, 3500);
    }
    land(true);
    window.addEventListener('hashchange', function () { land(false); });
  })();

  // ?show=lock|pencil|star|select|share (the admin's Features page, "Show me"): that button is brought into view, ringed, and named in a small
  // label next to it; the first press, a key or ten seconds takes it away. Only the owner has these buttons, so only they see anything.
  (function () {
    const m = /[?&]show=([a-z]+)/.exec(location.search); if (!m) return;
    let what = {
      lock: ['.card .priv-toggle, .folder-card .priv-toggle', 'The lock: press it to make this photo private (a visitor never sees it); press again to make it public.'],
      pencil: ['.card .edit-toggle', 'The pencil: press it to add a caption, a place or a link.'],
      star: ['.card .cover-toggle', 'The star: press it to make this photo the folder\'s cover picture.'],
