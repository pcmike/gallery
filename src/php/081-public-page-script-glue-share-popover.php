<?php if (is_admin_browser()): ?>
  // The owner's menu of links to a photo (opened by the link button on a tile and by the viewer's link button): what to copy depends on what the photo is.
  //  Public or Unlisted: the viewer link (the photo in the gallery), the picture link (the image itself, as the viewer shows it) and, only where a stranger could download it,
  //  the original's link. Private: Share just this photo (a share link), and the viewer link, which only people who already have access can open. An Only me photo has
  //  its own address and its own buttons, so it never gets this menu.
  let linkPop = null, linkPopFrom = null;
  function closeLinkPopup() { if (linkPop) { linkPop.remove(); linkPop = null; document.removeEventListener('keydown', linkPopKey, true); document.removeEventListener('pointerdown', linkPopAway, true); } }
  function linkPopKey(e) {
    if (!linkPop) return;
    if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); const f = linkPopFrom; closeLinkPopup(); if (f && f.focus) { try { f.focus({ preventScroll: true }); } catch (x) {} } return; }
    if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') { closeLinkPopup(); return; }   // (on to another photo)
    // The menu keeps the keys while it is open: Up and Down (and Home, End) move between its rows, Tab goes round inside it (the page and the viewer behind are not reached)
    const rowsEl = [].slice.call(linkPop.querySelectorAll('.lm-row')); if (!rowsEl.length) return;
    const at = rowsEl.indexOf(document.activeElement);
    let to = null;
    if (e.key === 'ArrowDown' || (e.key === 'Tab' && !e.shiftKey)) to = rowsEl[(at + 1) % rowsEl.length];
    else if (e.key === 'ArrowUp' || (e.key === 'Tab' && e.shiftKey)) to = rowsEl[(at <= 0 ? rowsEl.length : at) - 1];
    else if (e.key === 'Home') to = rowsEl[0];
    else if (e.key === 'End') to = rowsEl[rowsEl.length - 1];
    if (to) { e.preventDefault(); e.stopPropagation(); try { to.focus({ preventScroll: true }); } catch (x) {} }
  }
  // A press anywhere else closes the menu, and that press does nothing more (the photo is not opened, the viewer is not closed, the zoom does not change): the click that follows it is
  // eaten. Another tile's link button is the exception: it moves the menu to its own photo in one press.
  function linkPopAway(e) {
    if (!linkPop || linkPop.contains(e.target) || (linkPopFrom && linkPopFrom.contains && linkPopFrom.contains(e.target))) return;
    const other = e.target.closest ? e.target.closest('.link-toggle') : null;
    closeLinkPopup();
    if (other) return;
    const eat = (ev) => { ev.preventDefault(); ev.stopPropagation(); ev.stopImmediatePropagation(); done(); };
    const done = () => { clearTimeout(timer); document.removeEventListener('click', eat, true); };
    const timer = setTimeout(done, 700);   // (a press that was a drag or a scroll has no click after it)
    document.addEventListener('click', eat, true);
  }
  function ownerLinkRows(file) {
    const isVideo = /\.(mov|mp4)$/i.test(file), rows = [];
    const priv = !!(ownerState && ownerState.priv && ownerState.priv[file]);
    const closed = !!(ownerState && ownerState.closed);   // (a closed gallery: a plain link opens only for people who are signed in, so a share link is how anyone else gets in, for a public photo too)
    <?php if (ENABLE_SHARE_LINKS): ?>if ((priv || closed) && window.GalleryShare) rows.push({ kind: 'share', icon: 'share', name: 'Share just this photo\u2026', hint: 'a share link you can end, with a date and a log' });<?php endif; ?>

    rows.push({ kind: 'page', icon: 'link', name: 'Viewer link', hint: priv ? 'opens it in the gallery, for people who already have access' : 'opens the photo in the gallery' });
    if (!priv) {
      rows.push({ kind: 'picture', icon: isVideo ? 'video' : 'gallery', name: isVideo ? 'Video link' : 'Picture link', hint: isVideo ? 'the video file itself' : 'the image itself, as the viewer shows it' });
      if (!closed && ownerState && ownerState.dl && ownerState.dl[file]) rows.push({ kind: 'original', icon: 'download', name: 'Original link', hint: 'the original file, as a download' });
    }
    return rows;
  }
  function ownerLinkUrl(file, kind) {
    const root = new URL(galleryRootHref, window.location.href), base = root.origin + root.pathname, slash = file.lastIndexOf('/');
    if (kind === 'picture') return base + (/\.(mov|mp4)$/i.test(file) ? '?video=' : '?img=') + encodeURIComponent(file) + (/\.(mov|mp4)$/i.test(file) ? '' : '&size=full');
    if (kind === 'original') return base + '?original=' + encodeURIComponent(file);
    return base + (slash > 0 ? '?folder=' + encodeURIComponent(file.slice(0, slash)) + '&photo=' : '?photo=') + encodeURIComponent(file);
  }
  function openOwnerLinkMenu(file, anchor) {
    if (linkPop) { const same = linkPopFrom === anchor; closeLinkPopup(); if (same) return; }
    const rows = ownerLinkRows(file), icons = { link: '<?= ui_icon('link') ?>', gallery: '<?= ui_icon('gallery') ?>', video: '<?= ui_icon('video') ?>', download: '<?= ui_icon('download') ?>', share: '<?= ui_icon('share') ?>' };
    linkPop = document.createElement('div'); linkPopFrom = anchor;
    linkPop.className = 'lb-sharepop lm-menu'; linkPop.setAttribute('role', 'menu'); linkPop.setAttribute('aria-label', 'Links to this photo');
    rows.forEach((r) => {
      const b = document.createElement('button'); b.type = 'button'; b.className = 'lm-row'; b.setAttribute('role', 'menuitem'); b.setAttribute('data-kind', r.kind);
      b.innerHTML = icons[r.icon] + '<span class="lm-tx"><strong></strong><small></small></span>';
      b.querySelector('strong').textContent = r.name; b.querySelector('small').textContent = r.hint;
      b.addEventListener('click', () => {
        closeLinkPopup();
        if (r.kind === 'share') { const isPriv = !!(ownerState && ownerState.priv && ownerState.priv[file]); window.GalleryShare.quick({ kind: 'photos', files: [file], allPrivate: isPriv, anyPrivate: isPriv }); return; }
        if (anchor && anchor.focus) { try { anchor.focus({ preventScroll: true }); } catch (x) {} }   // (the focus goes back to the button: for a keyboard it is where it was, and the tile's button stays in view for the tick)
        copyToClipboard(ownerLinkUrl(file, r.kind)).then((ok) => { if (ok && anchor && anchor.isConnected !== false) flashCopied(anchor); });
      });
      linkPop.appendChild(b);
    });
    if (ownerState && ownerState.closed) { const n = document.createElement('p'); n.className = 'lm-note'; n.textContent = 'This gallery is closed: these links open only for people who are signed in or have a share link.'; linkPop.appendChild(n); }
    document.body.appendChild(linkPop);
    // Beside the button that was pressed, with a small pointer at it: below it when there is room, above it when not, always kept on the screen.
    const r = anchor.getBoundingClientRect(), w = linkPop.offsetWidth, h = linkPop.offsetHeight, vw = window.innerWidth, vh = window.innerHeight, gap = 9;
    const below = r.bottom + gap + h <= vh - 24 || r.top - gap - h < 8;   // (below when there is comfortable room; otherwise above, over the photo if need be)
    const cx = r.left + r.width / 2, left = Math.max(12, Math.min(cx - w / 2, vw - w - 12));
    linkPop.classList.add(below ? 'lm-below' : 'lm-above');
    linkPop.style.right = 'auto'; linkPop.style.left = left + 'px';
    linkPop.style.top = Math.max(8, below ? r.bottom + gap : r.top - gap - h) + 'px';
    linkPop.style.setProperty('--lm-x', Math.max(18, Math.min(cx - left, w - 18)) + 'px');   // (the pointer, over the middle of the button)
    const first = linkPop.querySelector('.lm-row'); if (first) { try { first.focus({ preventScroll: true }); } catch (x) {} }
    document.addEventListener('keydown', linkPopKey, true); document.addEventListener('pointerdown', linkPopAway, true);
  }
  // (moving to another photo, or closing the viewer, closes the menu)
  document.addEventListener('click', (e) => { if (linkPop && e.target.closest && e.target.closest('.lightbox .close, .lightbox .nav, .lightbox-close-row')) closeLinkPopup(); }, true);
  // It sits by the button it came from, so it goes when the page moves or changes size.
  window.addEventListener('scroll', () => { if (linkPop) closeLinkPopup(); }, true);
  window.addEventListener('resize', () => { if (linkPop) closeLinkPopup(); });
  // The link button on a tile (the card itself opens the viewer, so the button keeps the press to itself).
  document.addEventListener('click', (e) => {
    const b = e.target.closest ? e.target.closest('.link-toggle') : null;
    if (!b) return;
    e.preventDefault(); e.stopPropagation();
    openOwnerLinkMenu(b.getAttribute('data-file'), b);
  }, true);
<?php else: ?>
  function openOwnerLinkMenu() {}
<?php endif; ?>

  // The "this link expires on ..." line for someone using a share link: the time in their own zone, amber in the last day, and a dismissal kept for as long as the end time and that state stay the same.
  (function () {
    var el = document.getElementById('link-headsup'); if (!el) return;
    var ends = +el.getAttribute('data-ends'), t = el.querySelector('time'), d = new Date(ends * 1000);
    if (!isNaN(d)) t.textContent = d.toLocaleString(undefined, { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
    var soon = ends * 1000 - Date.now() < 86400000;
    el.classList.toggle('soon', soon);
    var key = 'galleryHeadsUp:' + el.getAttribute('data-key') + (soon ? ':soon' : '');
    try { if (localStorage.getItem(key)) el.hidden = true; } catch (e) {}
    el.querySelector('.lh-x').addEventListener('click', function () { el.hidden = true; try { localStorage.setItem(key, '1'); } catch (e) {} });
  })();

<?php if (visitor_log_link() !== null): ?>
  const galleryEntry = <?= js_json(public_log_arrival()) ?>;   // (how this page was reached, for the public address's log: null unless it was an arrival)
