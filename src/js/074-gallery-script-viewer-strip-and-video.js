      lightboxVideo.src = '?video=' + encodeURIComponent(file) + (fileVersions[current] ? '&v=' + fileVersions[current] : '') + passQ;
      lightboxVideo.load();
    } else {
      lightboxVideo.pause();
      lightboxVideo.style.display = 'none';
      lightboxVideo.src = '';
      lightboxImg.style.display = '';
      const fullQ = '?img=' + encodeURIComponent(file), verQ = (fileVersions[current] ? '&v=' + fileVersions[current] : '') + passQ;
      const asOriginal = heicOk && /\.hei[cf]$/i.test(file);   // (a browser that shows HEIC is sent the photo itself; if it can't after all, the converted copy)
      lightboxImg.onerror = asOriginal ? function () { lightboxImg.onerror = null; lightboxImg.src = fullQ + '&size=full' + verQ; } : null;
      lightboxImg.src = fullQ + '&size=' + (asOriginal ? 'orig' : 'full') + verQ;
    }
    paintCaption();
    if (downloadLink) { downloadLink.href = '?original=' + encodeURIComponent(file) + passQ; downloadLink.classList.remove('ready'); downloadLink.hidden = !!(downloadOk && !downloadOk[current]); }
    const copyBtn = document.getElementById('lightbox-copy-btn');
    if (copyBtn) copyBtn.hidden = !!(copyLinkOk && !copyLinkOk[current]);
    const newAddrBtn = document.getElementById('lightbox-newaddr');
    if (newAddrBtn) newAddrBtn.hidden = !ownerIsUnlisted(file);   // (a Not listed photo has an address of its own, and the owner can replace it)
    if (copyBtn) copyBtn.title = ownerIsUnlisted(file) ? 'Copy this photo\'s link' : 'Copy link';
    applyZoom();
    updateLightboxExtras();
    toolsFade();
    if (typeof ownerPaint === 'function') ownerPaint();
    if (lbEdit && lbEdit.isOpen()) lbEdit.load();
    trackView(file);
    if (window.__linkLog) window.__linkLog('o', file);
  }
  function paintCaption() {
    // Three parts, so a phone can put the counter first and let the caption give way (see .caption in the phone rules); read in order they are "caption — 3 / 42".
    const captionText = captions[current], set = siblingsOf(current);
    caption.textContent = '';
    [['cap-t', captionText || ''], ['cap-sep', captionText ? ' — ' : ''], ['cap-n', (set.indexOf(current) + 1) + ' / ' + set.length]].forEach(function (p) {
      const el = document.createElement('span'); el.className = p[0]; el.textContent = p[1]; caption.appendChild(el);
    });
    if (typeof toolsFade === 'function') toolsFade();
  }
  // The strip of buttons on a phone: a soft fade shows there is more to swipe to at either end. A swipe on it must not turn the page.
  const tools = document.getElementById('lightbox-tools');
  const toolsWrap = document.getElementById('lightbox-tools-wrap');
  function toolsFade() {
    if (!tools || !toolsWrap) return;
    const max = tools.scrollWidth - tools.clientWidth;
    toolsWrap.classList.toggle('can-right', max > 2 && tools.scrollLeft < max - 2);
    toolsWrap.classList.toggle('can-left', max > 2 && tools.scrollLeft > 2);
  }
  if (tools) {
    tools.addEventListener('scroll', toolsFade, { passive: true });
    ['touchstart', 'touchend'].forEach(function (name) { tools.addEventListener(name, function (e) { e.stopPropagation(); }, { passive: true }); });
    window.addEventListener('resize', toolsFade);
    // Whenever a button appears or goes (the link and place buttons by photo, the zoom button while the editor is up), or the viewer's own
    // state changes, look again: the fade must describe the strip as it is now.
    if (window.MutationObserver) {
      let queued = 0;
      const again = function () { if (!queued) queued = requestAnimationFrame(function () { queued = 0; toolsFade(); }); };
      new MutationObserver(again).observe(tools, { subtree: true, attributes: true, attributeFilter: ['hidden', 'class'] });
      new MutationObserver(again).observe(lightbox, { attributes: true, attributeFilter: ['class'] });
    }
  }
  // ---- Zoom: 'fit' (the whole photo on the screen), 'width' (fills the width of the window, scroll up and down) and
  // 'actual' (real pixels, scroll both ways). Always the original file at its own proportions, never stretched.
  let zoomMode = 'fit';
  const zoomBtn = document.getElementById('lightbox-zoom');
  const ZOOM_TEXT = { fit: 'Fill the width of the screen', width: 'Show at actual size', actual: 'Back to fitting the screen' };
  function sizeZoomImage() {
    const w = lightboxImg.naturalWidth;
    if (!w || !lightboxImg.style.setProperty) return;
    lightboxImg.style.setProperty('--nat', w + 'px');
    lightboxImg.style.setProperty('--act', (w / (window.devicePixelRatio || 1)) + 'px');
  }
  function applyZoom() {
    const video = isVideoFile(files[current]);
    lightbox.classList.toggle('is-video', video);
    lightbox.classList.toggle('zoom-width', !video && zoomMode === 'width');
    lightbox.classList.toggle('zoom-actual', !video && zoomMode === 'actual');
    if (zoomBtn) {
      zoomBtn.title = ZOOM_TEXT[zoomMode];
      zoomBtn.setAttribute('aria-label', ZOOM_TEXT[zoomMode]);
      zoomBtn.innerHTML = '<svg class="ico" aria-hidden="true"><use href="#i-' + (zoomMode === 'actual' ? 'zoom-out' : 'zoom-in') + '"></use></svg>';
    }
    lightbox.scrollTop = 0;
    lightbox.scrollLeft = 0;
    sizeZoomImage();
    if (typeof placeMiniMap === 'function') placeMiniMap();
  }
  function setZoom(mode) { zoomMode = mode; applyZoom(); }
  function cycleZoom() {
    if (isVideoFile(files[current]) || (lbEdit && lbEdit.isOpen())) return;
    let next = zoomMode === 'fit' ? 'width' : (zoomMode === 'width' ? 'actual' : 'fit');
    // Nothing more to see at real size when the photo is no wider than the window already shows it.
    if (next === 'actual' && lightboxImg.naturalWidth && lightboxImg.naturalWidth / (window.devicePixelRatio || 1) <= lightbox.clientWidth) next = 'fit';
    setZoom(next);
  }
  if (zoomBtn) zoomBtn.addEventListener('click', cycleZoom);
  lightboxImg.addEventListener('click', cycleZoom);
  lightboxImg.addEventListener('load', sizeZoomImage);
