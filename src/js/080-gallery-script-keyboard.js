  // A small picture under a note, or a clip's picture, is a button: Enter and Space press it (Space must not also scroll the page).
  document.addEventListener('keydown', (e) => {
    if ((e.key !== 'Enter' && e.key !== ' ') || e.ctrlKey || e.metaKey || e.altKey || !e.target || !e.target.closest) return;
    const b = e.target.closest('[data-open]');
    if (!b || b !== e.target) return;
    e.preventDefault();
    b.click();
  });
  // Backspace does what Escape does, for a keyboard that has no Escape key: it closes the box or menu that is open, or, with none, the viewer. Never while typing in a field
  // (there it deletes a letter), and never in a box the person has already done something in (typed, ticked or chosen: it may hold what they have been working on; Escape and the
  // box's own buttons still close it). It asks the dialog's own Escape to run, so each one closes as it always does (a question is cancelled; the viewer's info panel or editor, if
  // open, goes first, as with Escape).
  const DIALOGS = '.fp-modal, .admin-modal, .gs-modal, .gs-large, .fm-ov, .map-modal, .lb-sharepop, .coll-pop, .sort-pop';
  const markWorked = (e) => { if (!e.isTrusted || !e.target || !e.target.closest) return; const d = e.target.closest(DIALOGS); if (d) d.__worked = true; };   // (only real typing, ticking and choosing counts: a page filling a field in does not)
  document.addEventListener('input', markWorked, true); document.addEventListener('change', markWorked, true);
  if (window.MutationObserver) new MutationObserver((list) => { list.forEach((m) => { if (m.target.hidden) m.target.__worked = false; }); }).observe(document.body, { attributes: true, attributeFilter: ['hidden'], subtree: true });   // (a box shown again starts untouched)
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Backspace' || e.ctrlKey || e.metaKey || e.altKey || e.shiftKey || e.repeat) return;
    var t = e.target;
    if (t && t.closest && t.closest('input:not([type=checkbox]):not([type=radio]):not([type=button]):not([type=submit]), textarea, select, [contenteditable]')) return;
    if (document.fullscreenElement && document.fullscreenElement.tagName === 'VIDEO') return;   // (a video in its own full screen: the browser's Escape leaves it; the viewer under it is not to be closed)
    var open = [].slice.call(document.querySelectorAll('.fp-modal:not([hidden]), .admin-modal:not([hidden]), .gs-modal, .gs-large, .fm-ov, .map-modal:not([hidden]), .lb-sharepop, .coll-pop, .sort-pop:not([hidden])'));
    // the box on top: the highest in the stacking order, the later one in the page where they are level (the map's box lies under the viewer, a question over it)
    var top = null, topZ = -Infinity;
    open.forEach(function (el) { var z = parseInt(getComputedStyle(el).zIndex, 10); if (isNaN(z)) z = 0; if (z >= topZ) { top = el; topZ = z; } });
    var target = top || (lightbox.classList.contains('open') ? lightbox : null);
    if (!target) return;
    if (top && top.__worked) return;   // (something has been done in it: Backspace leaves it alone)
    e.preventDefault(); e.stopPropagation();
    target.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', code: 'Escape', keyCode: 27, which: 27, bubbles: true, cancelable: true }));
  }, true);
  document.addEventListener('keydown', (e) => {
    if (!lightbox.classList.contains('open')) return;
    // Another box opened over the viewer (the owner's "Share just this photo", the QR code, a question) has the keys, and so does any field:
    // typing a name must not zoom or change the photo behind it, nor Escape close the viewer under a box that has just taken it.
    if (e.target && e.target.closest && e.target.closest('.gs-modal, .gs-large, .fp-modal, .admin-modal, .map-modal, .lb-sharepop')) return;   // (the owner's link menu has its own keys: Tab, the arrows, Escape)
    if (e.ctrlKey || e.metaKey || e.altKey) return;   // (a browser's own shortcut: Ctrl+Z, Alt+Left for back)
    // Tab stays inside the viewer: from the last button to the first and back (a screen reader is told the rest of the page is gone: aria-modal).
    if (e.key === 'Tab') {
      const ring = [].slice.call(lightbox.querySelectorAll('button, a[href], input, select, textarea, video[controls], [tabindex]:not([tabindex="-1"])')).filter(function (el) { return !el.disabled && !el.hidden && el.getClientRects().length > 0 && !el.closest('[hidden]'); });
      if (!ring.length) { e.preventDefault(); return; }
      const first = ring[0], last = ring[ring.length - 1], at = document.activeElement;
      if (e.shiftKey && (at === first || at === lightbox || !lightbox.contains(at))) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && (at === last || !lightbox.contains(at))) { e.preventDefault(); first.focus(); }
      return;
    }
    if (lbEdit && lbEdit.isOpen()) {
      if (e.key === 'Escape') { lbEdit.close(); return; }
      if (e.target && e.target.closest && e.target.closest('#lb-editor')) return;   // typing: the arrows, Z and the rest belong to the field
    }
    if (e.target && e.target.closest && e.target.closest('input, textarea, select, [contenteditable=""], [contenteditable="true"]')) return;   // (any other field: its keys are its own)
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
  let multiTouch = false;   // a second finger came down: it is a pinch (or a two-finger drag), never a swipe, until every finger is up
  lightbox.addEventListener('touchstart', (e) => {
    if (e.touches && e.touches.length > 1) { multiTouch = true; return; }
    multiTouch = false;
    const t = e.changedTouches[0];
    touchStartX = t.clientX;
    touchStartY = t.clientY;
  }, { passive: true });
  lightbox.addEventListener('touchcancel', () => { multiTouch = false; }, { passive: true });
  lightbox.addEventListener('touchend', (e) => {
    if (multiTouch) { if (!e.touches || e.touches.length === 0) multiTouch = false; return; }
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
    const ta = document.createElement('textarea'); let ok = false;
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    try { ok = document.execCommand('copy'); } catch (e) {}
    document.body.removeChild(ta);
    return ok;
  }
  // Both ways refused (some browsers refuse a copy that is not inside the press that asked for it): the address, in a box that is already selected, to copy by hand.
  function showAddressBox(text) {
    const old = document.getElementById('copy-addrbox'); if (old) old.remove();
    const d = document.createElement('div'); d.id = 'copy-addrbox'; d.className = 'copy-addrbox'; d.setAttribute('role', 'dialog'); d.setAttribute('aria-label', 'Copy this address');
    const i = document.createElement('input'); i.type = 'text'; i.readOnly = true; i.value = text; i.setAttribute('aria-label', 'The address');
    const b = document.createElement('button'); b.type = 'button'; b.textContent = 'Close'; b.addEventListener('click', () => d.remove());
    d.appendChild(i); d.appendChild(b); document.body.appendChild(d); try { i.focus(); i.select(); } catch (x) {}
  }
  // Resolves true when the address was copied; false when it was not (the box above is shown instead), so a tick or "copied" is never shown for a copy that did not happen.
  function copyToClipboard(text) {
    const second = () => legacyCopy(text) || (showAddressBox(text), false);
    if (navigator.clipboard && navigator.clipboard.writeText) return navigator.clipboard.writeText(text).then(() => true, second);
    return Promise.resolve(second());
  }
  // The tick is only a picture: say it too, for a screen reader (a polite status line nobody sees).
  function sayCopied() {
    let r = document.getElementById('sr-live');
    if (!r) { r = document.createElement('div'); r.id = 'sr-live'; r.className = 'sr-only'; r.setAttribute('role', 'status'); r.setAttribute('aria-live', 'polite'); document.body.appendChild(r); }
    r.textContent = '';
    setTimeout(function () { r.textContent = 'Link copied'; }, 50);
  }
  function flashCopied(el) {
    if (el.classList.contains('copied')) return;
    sayCopied();   // (pressed again within the moment: the first one's timer puts the icon back; keeping the tick as "the original" left it for good)
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
    // A folder card's copy button is a real link to the folder, but pressing it is only a copy: it must not also open the folder. (A same-page anchor, on a note or a group box, still
    // jumps there as well, on purpose; and a press with Ctrl, Cmd, Shift or Alt held is the browser's own "open in a new tab or window" and is left to it.)
    if (link.classList.contains('folder-permalink-copy')) { if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return; e.preventDefault(); }
    // A .md item's or group box's permalink is a same-page #anchor; a
    // folder cover card's is a real "?folder=..." link instead (no
    // hash) — copy whichever one this button actually has.
    // A link made on a folder's page must keep pointing at that folder (and nothing else of the address, such as ?photo=).
    const folderQuery = () => { const f = new URLSearchParams(window.location.search).get('folder'); return f ? '?folder=' + encodeURIComponent(f) : ''; };
    // (In the admin's frame the page's own script makes every link a full address with inframe=1 added, so the link is worked out from the address it is, and inframe is left out.)
    const hrefUrl = () => { try { return new URL(link.getAttribute('href'), window.location.href); } catch (x) { return null; } };
    const cleanUrl = link.hash
      ? window.location.origin + window.location.pathname + folderQuery() + link.hash
      : (() => { const u = hrefUrl(); if (!u) return window.location.origin + window.location.pathname + link.getAttribute('href'); const q = u.search.slice(1).split('&').filter(p => p && p !== 'inframe=1'); return u.origin + u.pathname + (q.length ? '?' + q.join('&') : ''); })();
    copyToClipboard(cleanUrl).then((ok) => { if (ok) flashCopied(link); });
  });

  // A Flat folder label or divider: pressing the name copies a link to that place and the name says so for a moment.
  document.addEventListener('click', (e) => {
    const b = e.target.closest('.label-copy');
    if (!b) return;
    const f = new URLSearchParams(window.location.search).get('folder');
    const id = b.getAttribute('data-anchor');
    const url = window.location.origin + window.location.pathname + (f ? '?folder=' + encodeURIComponent(f) : '') + '#' + id;
    copyToClipboard(url).then((ok) => {
      const t = b.querySelector('.lc-t');
      if (!ok || !t || b.classList.contains('copied')) return;
      const keep = t.innerHTML; t.textContent = 'Link copied'; b.classList.add('copied');
      setTimeout(() => { t.innerHTML = keep; b.classList.remove('copied'); }, 1400);
    });
    try { history.replaceState(null, '', '#' + id); } catch (x) {}
  });

  // Copies a deep link to the photo currently open in the lightbox: the gallery's own address, the photo's own folder (so whoever opens it lands on that
  // folder's page, with the navigation the folder allows, e.g. only the album for a Gallery of its own) and the photo. It is built from where the photo
  // is, not from the address this visitor arrived by (a link copied after clicking in from the front page, or after typing a folder's address, used to differ).
  const lightboxCopyBtn = document.getElementById('lightbox-copy-btn');
  const copyPhotoLink = () => {
    const file = files[current], slash = file.lastIndexOf('/');
    const root = new URL(galleryRootHref, window.location.href);
    const url = root.origin + root.pathname + (slash > 0 ? '?folder=' + encodeURIComponent(file.slice(0, slash)) + '&photo=' : '?photo=') + encodeURIComponent(file);
    copyToClipboard(url).then((ok) => { if (ok) flashCopied(lightboxCopyBtn); });
  };
  lightboxCopyBtn.addEventListener('click', () => {
    if (ownerIsUnlisted(files[current])) { ownerAddress(files[current], false, lightboxCopyBtn); return; }   // (an Only me photo is opened by its own address, not a plain link)
    // The owner gets a small menu of links to this photo (what is offered depends on what the photo is); everyone else copies the viewer link at once.
    if (ownerState) { openOwnerLinkMenu(files[current], lightboxCopyBtn); return; }
    copyPhotoLink();
  });
