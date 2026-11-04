  document.addEventListener('keydown', (e) => {
    if (!lightbox.classList.contains('open')) return;
    if (lbEdit && lbEdit.isOpen()) {
      if (e.key === 'Escape') { lbEdit.close(); return; }
      if (e.target && e.target.closest && e.target.closest('#lb-editor')) return;   // typing: the arrows, Z and the rest belong to the field
    }
    if (e.key === 'Escape') { if (miniExpanded) { collapseMiniMap(); return; } if (zoomMode !== 'fit') { setZoom('fit'); return; } closeLightbox(); return; }
    if (e.key === 'z' || e.key === 'Z') { cycleZoom(); return; }
    // A focused <video>'s native controls already use arrow keys (seek)
    // and spacebar (play/pause) — don't also fire our own nav on top of
    // that, or an arrow press would both seek the clip and jump to a
    // different lightbox item at the same time.
    if (document.activeElement === lightboxVideo) return;
    if (e.key === 'ArrowLeft') navigate(-1);
    if (e.key === 'ArrowRight') navigate(1);
  });
  lightbox.addEventListener('click', (e) => {
    if (e.target !== lightbox) return;
    if (lbEdit && lbEdit.isOpen()) { if (!lbEdit.justFocused()) lbEdit.blur(); return; }   // (a tap beside the photo while editing only puts the keyboard away)
    closeLightbox();
  });

  // Touch gestures: swipe left/right to move between photos, swipe down to close.
  let touchStartX = 0;
  let touchStartY = 0;
  lightbox.addEventListener('touchstart', (e) => {
    const t = e.changedTouches[0];
    touchStartX = t.clientX;
    touchStartY = t.clientY;
  }, { passive: true });
  lightbox.addEventListener('touchend', (e) => {
    const t = e.changedTouches[0];
    const deltaX = t.clientX - touchStartX;
    const deltaY = t.clientY - touchStartY;
    const absX = Math.abs(deltaX);
    const absY = Math.abs(deltaY);
    const threshold = 50;
    if (zoomMode === 'actual') return;   // dragging pans the photo
    if (absX > absY && absX > threshold) {
      navigate(deltaX < 0 ? 1 : -1);
    } else if (absY > absX && deltaY > threshold && zoomMode === 'fit' && !(lbEdit && lbEdit.isOpen())) {
      closeLightbox();   // (when the photo fills the width, swiping down scrolls it instead)
    }
  }, { passive: true });

  // Clicking an item's 🔗 permalink icon copies its full URL to the
  // clipboard (in addition to the browser's normal anchor navigation —
  // it still jumps to and highlights the item as before).
  function legacyCopy(text) {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    try { document.execCommand('copy'); } catch (e) {}
    document.body.removeChild(ta);
  }
  function copyToClipboard(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text).catch(() => legacyCopy(text));
    }
    legacyCopy(text);
    return Promise.resolve();
  }
  function flashCopied(el) {
    const original = el.innerHTML;
    el.innerHTML = '<svg class="ico" aria-hidden="true"><use href="#i-check"></use></svg>';
    el.classList.add('copied');
    setTimeout(() => {
      el.innerHTML = original;
      el.classList.remove('copied');
    }, 1200);
  }
  document.addEventListener('click', (e) => {
    const link = e.target.closest('.permalink-copy');
    if (!link) return;
    // A .md item's or group box's permalink is a same-page #anchor; a
    // folder cover card's is a real "?folder=..." link instead (no
    // hash) — copy whichever one this button actually has.
    // A link made on a folder's page must keep pointing at that folder (and nothing else of the address, such as ?photo=).
    const folderQuery = () => { const f = new URLSearchParams(window.location.search).get('folder'); return f ? '?folder=' + encodeURIComponent(f) : ''; };
    const cleanUrl = link.hash
      ? window.location.origin + window.location.pathname + folderQuery() + link.hash
      : window.location.origin + window.location.pathname + link.getAttribute('href');
    copyToClipboard(cleanUrl).then(() => flashCopied(link));
  });

  // A Flat folder label or divider: pressing the name copies a link to that place and the name says so for a moment.
  document.addEventListener('click', (e) => {
    const b = e.target.closest('.label-copy');
    if (!b) return;
    const f = new URLSearchParams(window.location.search).get('folder');
    const id = b.getAttribute('data-anchor');
    const url = window.location.origin + window.location.pathname + (f ? '?folder=' + encodeURIComponent(f) : '') + '#' + id;
    copyToClipboard(url).then(() => {
      const t = b.querySelector('.lc-t');
      if (!t || b.classList.contains('copied')) return;
      const keep = t.innerHTML; t.textContent = 'Link copied'; b.classList.add('copied');
      setTimeout(() => { t.innerHTML = keep; b.classList.remove('copied'); }, 1400);
    });
    try { history.replaceState(null, '', '#' + id); } catch (x) {}
  });

  // Copies a "?photo=" deep link to the photo currently open in the lightbox.
  const lightboxCopyBtn = document.getElementById('lightbox-copy-btn');
  const copyPhotoLink = () => {
    const url = window.location.origin + window.location.pathname + '?photo=' + encodeURIComponent(files[current]);
    copyToClipboard(url).then(() => flashCopied(lightboxCopyBtn));
  };
  lightboxCopyBtn.addEventListener('click', () => {
    // A plain link to a private photo only opens for people who already have access, so the owner is asked what they mean.
    if (ownerState && ownerState.priv[files[current]] && window.GalleryShare) { openPrivateLinkPopup(); return; }
    copyPhotoLink();
  });
