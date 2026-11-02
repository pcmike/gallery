      share: ['.page-share', 'Share: this makes a link, a QR code and a log of who looked.']
    }[m[1]];
    if (m[1] === 'share' && !document.querySelector('.page-share')) what = ['#select-btn', 'Select: press it, tick the photos you want to share, then press Share…'];
    try { history.replaceState(null, '', location.pathname + location.search.replace(/([?&])show=[a-z]+&?/, '$1').replace(/[?&]$/, '') + location.hash); } catch (e) {}
    if (!what) return;
    const target = document.querySelector(what[0]);
    const note = document.createElement('div'); note.className = 'show-me-note'; note.setAttribute('role', 'status');
    let ringed = null, timer = null;
    function done() { if (timer) clearTimeout(timer); if (ringed) ringed.classList.remove('show-me-ring', 'show-me-force'); if (ringed && ringed.closest('.card')) ringed.closest('.card').classList.remove('show-me-card'); if (note.parentNode) note.parentNode.removeChild(note); document.removeEventListener('pointerdown', done, true); document.removeEventListener('keydown', done, true); }
    if (!target) { note.textContent = 'Nothing to point at here yet: ' + what[1]; document.body.appendChild(note); note.style.cssText = 'left:50%;top:80px;transform:translateX(-50%)'; }
    else {
      ringed = target; target.classList.add('show-me-ring', 'show-me-force'); if (target.closest('.card')) target.closest('.card').classList.add('show-me-card');
      target.scrollIntoView({ block: 'center', behavior: 'instant' });   // (not 'auto': a page that scrolls smoothly would still be moving when the note is placed)
      note.textContent = what[1]; document.body.appendChild(note);
      // The note sits beside the button with a small tail aimed at its centre, above or below, and is placed again whenever anything moves
      // (scrolling, resizing, pictures finishing and pushing the page about): a note placed once lands where the button used to be.
      let queued = false;
      const place = function () {
        queued = false;
        if (!note.parentNode) return;   // (it has been dismissed)
        const r = target.getBoundingClientRect(), w = Math.min(280, window.innerWidth - 24);
        note.style.width = w + 'px';
        const left = Math.max(12, Math.min(window.innerWidth - w - 12, r.left + r.width / 2 - w / 2));
        note.style.left = left + 'px';
        const below = r.bottom + 12 + note.offsetHeight < window.innerHeight;
        note.style.top = (below ? r.bottom + 12 : Math.max(12, r.top - 12 - note.offsetHeight)) + 'px';
        note.classList.toggle('below', below); note.classList.toggle('above', !below);
        note.style.setProperty('--tail-x', Math.max(16, Math.min(w - 16, r.left + r.width / 2 - left)) + 'px');
      };
      const later = function () { if (!queued) { queued = true; requestAnimationFrame(place); } };
      place(); window.addEventListener('resize', later); window.addEventListener('scroll', later, true); window.addEventListener('load', later);
      [150, 500, 1500].forEach(function (ms) { setTimeout(later, ms); });
    }
    timer = setTimeout(done, 10000);
    setTimeout(function () { document.addEventListener('pointerdown', done, true); document.addEventListener('keydown', done, true); }, 400);
  })();

  // Writes fresh counts from a ?track response into every matching badge
  // on the page (a photo can appear both in the gallery and in a note-box
  // thumbnail strip; a group total can appear in both places too). A
  // no-op when counts aren't shown publicly — tracking still happens
  // server-side either way, there's just nothing to update on-page.
  function applyViewUpdate(data) {
    if (!showCountsPublicly) return;
    document.querySelectorAll('.view-badge[data-photo="' + cssEscape(data.photo) + '"]').forEach((el) => {
      el.innerHTML = '<svg class="ico" aria-hidden="true"><use href="#i-eye"></use></svg> ' + data.photoViews.toLocaleString();
      el.classList.remove('hidden');
    });
    if (data.groupSlug) {
      document.querySelectorAll('.group-views[data-group="' + cssEscape(data.groupSlug) + '"]').forEach((el) => {
        el.textContent = '· ' + data.groupViews.toLocaleString() + ' view' + (data.groupViews === 1 ? '' : 's');
        el.classList.remove('hidden');
      });
    }
  }

  function trackView(filename) {
    if (!trackingEnabled) return;
    fetch('?track=' + encodeURIComponent(filename))
      .then((res) => res.ok ? res.json() : null)
      .then((data) => { if (data && data.ok) applyViewUpdate(data); })
      .catch(() => {});
  }

  // Caption badge: tap/click/Enter shows the caption in a small popover
  // on that card (textContent, so it's never parsed as HTML); tapping
  // anywhere else, or the badge again, dismisses it. Stops the click
  // from also opening the lightbox.
  function toggleCaptionPopover(badge, e) {
    e.stopPropagation();
    e.preventDefault();
    const card = badge.closest('.card');
    if (!card) return;
    const existing = card.querySelector('.caption-popover');
    document.querySelectorAll('.caption-popover').forEach(function (p) { p.remove(); });
    if (existing) return;
    const pop = document.createElement('div');
    pop.className = 'caption-popover';
    pop.textContent = badge.getAttribute('title');
    card.appendChild(pop);
  }
  document.addEventListener('click', function () {
    document.querySelectorAll('.caption-popover').forEach(function (p) { p.remove(); });
  });

  function openLightbox(index) {
    if (window.__scrubbedAt && Date.now() - window.__scrubbedAt < 600) return;   // the tap that ends a finger-scrub of a video tile is not a tap
    if (window.__previewHalt) window.__previewHalt();   // a clip playing on a tile stops while the viewer is open
    current = index;
    updateImage();
    lightbox.classList.add('open');
    toolsFade();
  }
  function closeLightbox() {
    if (lbEdit && lbEdit.isOpen()) lbEdit.close();
    if (typeof collapseMiniMap === 'function') collapseMiniMap();
    if (typeof setZoom === 'function' && zoomMode !== 'fit') setZoom('fit');
    lightbox.classList.remove('open');
    lightboxVideo.pause();
    if (window.__previewResume) window.__previewResume();
  }
  // The arrows stay inside the folder the open photo is in: a folder is its own set, however the page lays
  // folders out, so Next from the last photo of one folder goes back to that folder's first, not into another's.
  function folderOf(name) {
    const slash = name.lastIndexOf('/');
    return slash < 0 ? '' : name.slice(0, slash);
  }
  function siblingsOf(index) {
    const dir = folderOf(files[index]), out = [];
    for (let i = 0; i < files.length; i++) if (folderOf(files[i]) === dir) out.push(i);
    return out;
  }
  function navigate(delta) {
    // With the editor open, what was typed on this photo is saved first (and the move waits if something cannot be saved).
    if (lbEdit && lbEdit.isOpen()) { lbEdit.beforeLeave().then(function (ok) { if (ok) step(delta); }); return; }
    step(delta);
  }
  function step(delta) {
    const set = siblingsOf(current), at = set.indexOf(current);
    current = set[(at + delta + set.length) % set.length];
    updateImage();
  }
