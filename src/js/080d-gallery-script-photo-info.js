  // ---- The info panel: what a photo's own file says about it (camera, when it was taken, its size, and whatever else the owner has switched on).
  // The button is offered only for the photos of a folder that has info on (infoFolders). The panel asks for one photo's rows when it opens and again on every
  // photo you move to while it stays open (nothing is read ahead of time); the answer is drawn with textContent, never as markup. Beside the photo on a wide
  // screen, a sheet over the lower part of the screen on a narrow one; a slideshow has none of it.
  const infoBtn = document.getElementById('lightbox-info');
  const infoPanel = document.getElementById('info-panel');
  const infoBody = document.getElementById('info-body');
  const infoClose = document.getElementById('info-close');
  const infoState = { open: false, token: 0, touchY: null };
  const infoSheet = function () { return !!(window.matchMedia && window.matchMedia('(max-width: 600px)').matches); };   // (the narrow layout: a sheet at the bottom)

  function infoRefit() {   // the strip of buttons has just changed: once the layout has settled, look again at whether its "more to swipe" arrow is needed
    if (typeof toolsFade === 'function') { toolsFade(); if (typeof requestAnimationFrame === 'function') { requestAnimationFrame(toolsFade); setTimeout(toolsFade, 300); } }
  }
  function infoAvailable() {
    return !!infoBtn && typeof infoFolders !== 'undefined' && infoFolders.indexOf(folderOf(files[current])) >= 0 && !lightbox.classList.contains('ss-on');
  }
  function infoFit() {   // the photo makes room for the sheet: how tall it is decides how much
    if (infoState.open && infoSheet()) lightbox.style.setProperty('--ip-h', infoPanel.offsetHeight + 'px');
    else lightbox.style.removeProperty('--ip-h');
  }
  function infoSay(text) {
    infoBody.textContent = '';
    const p = document.createElement('p'); p.className = 'ip-note'; p.textContent = text; infoBody.appendChild(p);
  }
  function infoDraw(rows) {
    infoBody.textContent = '';
    if (!rows.length) { infoSay('Nothing to show for this photo.'); return; }
    rows.forEach(function (r) {
      const row = document.createElement('div'); row.className = 'ip-row';
      const k = document.createElement('span'); k.className = 'ip-k'; k.textContent = r.label;
      const v = document.createElement('span'); v.className = 'ip-v';
      if (r.href && /^https:\/\/www\.openstreetmap\.org\//.test(r.href)) {   // (the one kind of link the panel makes: the position, on the map)
        const a = document.createElement('a'); a.href = r.href; a.target = '_blank'; a.rel = 'noopener noreferrer'; a.textContent = r.value + ' ↗';
        a.title = 'Open this position on a map (a new tab)'; v.appendChild(a);
      } else v.textContent = r.value;
      row.appendChild(k); row.appendChild(v); infoBody.appendChild(row);
    });
  }
  function infoLoad() {
    if (!infoState.open) return;
    const my = ++infoState.token, file = files[current];
    infoSay('Loading…');
    infoFit();
    fetch('?photoinfo=' + encodeURIComponent(file), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) { if (my !== infoState.token || !infoState.open) return; if (j && j.ok) infoDraw(j.rows || []); else infoSay('Nothing to show for this photo.'); infoFit(); })
      .catch(function () { if (my !== infoState.token || !infoState.open) return; infoSay('Could not load the photo information.'); infoFit(); });
  }
  function infoSet(on, fromKey) {
    if (on && !infoAvailable()) return;
    if (infoState.open === on) return;
    if (on && typeof ssStop === 'function' && ss.on) ssStop();   // (a show and the panel do not go together)
    const hadFocus = infoPanel.contains(document.activeElement);
    infoState.open = on;
    infoPanel.hidden = !on;
    lightbox.classList.toggle('info-open', on);
    if (infoBtn) { infoBtn.setAttribute('aria-pressed', on ? 'true' : 'false'); infoBtn.classList.toggle('on', on); }
    if (on) { infoLoad(); try { infoPanel.focus({ preventScroll: true }); } catch (x) {} }
    else { infoState.token++; lightbox.style.removeProperty('--ip-h'); if ((hadFocus || fromKey) && infoBtn && !infoBtn.hidden) { try { infoBtn.focus({ preventScroll: true }); } catch (x) {} } }
    infoRefit();
  }

  if (infoBtn) infoBtn.addEventListener('click', function () { infoSet(!infoState.open); });
  if (infoClose) infoClose.addEventListener('click', function () { infoSet(false); });
  // Esc closes the panel before it closes the viewer; the letter i opens and closes it.
  document.addEventListener('keydown', function (e) {
    if (!lightbox.classList.contains('open') || e.ctrlKey || e.metaKey || e.altKey) return;
    if (e.target && e.target.closest && e.target.closest('.gs-modal, .gs-large, .fp-modal, .admin-modal, .map-modal, input, textarea, select, [contenteditable="true"]')) return;
    if (e.key === 'Escape' && infoState.open) { e.preventDefault(); e.stopImmediatePropagation(); infoSet(false, true); return; }
    if ((e.key === 'i' || e.key === 'I') && (infoState.open || infoAvailable())) { e.preventDefault(); infoSet(!infoState.open, true); }
  }, true);
  // Swiping inside the panel is not a swipe of the viewer; a swipe down on the sheet puts it away, and so does one on the photo while a sheet is up
  // (the first swipe down closes the sheet, the next one the viewer).
  if (infoPanel) {
    infoPanel.addEventListener('touchstart', function (e) { e.stopPropagation(); infoState.touchY = e.changedTouches && e.changedTouches[0] ? e.changedTouches[0].clientY : null; }, { passive: true });
    infoPanel.addEventListener('touchend', function (e) {
      e.stopPropagation();
      const y = e.changedTouches && e.changedTouches[0] ? e.changedTouches[0].clientY : null;
      if (infoSheet() && infoState.touchY !== null && y !== null && y - infoState.touchY > 60 && infoBody.scrollTop <= 0) infoSet(false);
      infoState.touchY = null;
    }, { passive: true });
  }
  let infoSwipeY = null;
  lightbox.addEventListener('touchstart', function (e) { infoSwipeY = e.changedTouches && e.changedTouches[0] ? e.changedTouches[0].clientY : null; }, { capture: true, passive: true });
  lightbox.addEventListener('touchend', function (e) {
    if (!infoState.open || !infoSheet() || infoSwipeY === null || !e.changedTouches || !e.changedTouches[0]) return;
    if (e.changedTouches[0].clientY - infoSwipeY > 50) { e.stopPropagation(); infoSet(false); }
  }, true);
  window.addEventListener('resize', function () { infoFit(); });

  // Any button of the viewer's strip changing size (the pin button does while the little map loads) is a reason to look again at the strip's "more to swipe" arrow.
  const infoTools = document.getElementById('lightbox-tools');
  if (infoTools && window.ResizeObserver) {
    const ro = new ResizeObserver(function () { if (typeof toolsFade === 'function') toolsFade(); });
    [].forEach.call(infoTools.children, function (c) { ro.observe(c); });
  }

  // The viewer calls these: after every photo it shows, and when it closes.
  window.__infoShown = function () {
    const ok = infoAvailable();
    if (infoBtn) { const was = infoBtn.hidden; infoBtn.hidden = !ok; if (was !== infoBtn.hidden) infoRefit(); }
    if (infoState.open) { if (ok) infoLoad(); else infoSet(false); }
  };
  window.__infoHide = function () { infoSet(false); };
  window.__infoClose = function () { infoSet(false); if (infoBtn) infoBtn.hidden = true; };
