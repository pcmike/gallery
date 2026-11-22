  // ---- Slideshow: Play in the viewer (or on the page) and the photos keep coming, in the folder's order, round and round (or shuffled).
  // A photo stays for the chosen time once it has loaded; a video plays to its end, then the next comes. The viewer's own state
  // (updateImage, closeLightbox) calls the two hooks at the bottom; nothing else here changes how the viewer works.
  const ssBar = document.getElementById('lightbox-ss');
  const ssPlayBtn = document.getElementById('lightbox-play');
  const ssPageBtn = document.getElementById('slideshow-start');
  const ssToggleBtn = document.getElementById('ss-play');
  const ssSpeedBtn = document.getElementById('ss-speed');
  const ssShuffleBtn = document.getElementById('ss-shuffle');
  const ssStopBtn = document.getElementById('ss-stop');
  const ssTools = document.getElementById('lightbox-tools');
  const SS_SPEEDS = [3, 5, 8, 12, 20];
  const ss = { on: false, paused: false, shuffle: false, seconds: 5, timer: 0, idle: 0, token: 0, order: null, cancel: null, pre: null, fs: false, wake: null, muted: false };
  try { const saved = parseInt(localStorage.getItem('gallerySlideSeconds'), 10); if (SS_SPEEDS.indexOf(saved) >= 0) ss.seconds = saved; } catch (x) {}

  function ssIcon(btn, name) { if (btn) btn.innerHTML = '<svg class="ico" aria-hidden="true"><use href="#i-' + name + '"></use></svg>'; }
  function ssPaint() {
    const running = ss.on && !ss.paused;
    ssIcon(ssToggleBtn, running ? 'pause' : 'play');
    if (ssToggleBtn) { ssToggleBtn.title = running ? 'Pause the slideshow' : 'Carry on with the slideshow'; ssToggleBtn.setAttribute('aria-label', ssToggleBtn.title); }
    ssIcon(ssPlayBtn, running ? 'pause' : 'play');
    if (ssPlayBtn) { ssPlayBtn.title = !ss.on ? 'Play a slideshow' : (running ? 'Pause the slideshow' : 'Carry on with the slideshow'); ssPlayBtn.setAttribute('aria-label', ssPlayBtn.title); }
    if (ssSpeedBtn) { ssSpeedBtn.textContent = ss.seconds + ' s'; ssSpeedBtn.setAttribute('aria-label', 'How long each photo stays: ' + ss.seconds + ' seconds'); }
    if (ssShuffleBtn) { ssShuffleBtn.setAttribute('aria-pressed', ss.shuffle ? 'true' : 'false'); ssShuffleBtn.title = ss.shuffle ? 'Shuffled: back to the folder\'s order' : 'Shuffle'; }
    if (ssBar) ssBar.classList.toggle('paused', ss.on && ss.paused);
    // The viewer's strip of buttons has just changed (Play became Pause, the zoom button went or came back): once the layout has settled,
    // look again at whether the "more this way" arrow is needed, so a moment half-way through the change cannot leave it showing.
    if (typeof toolsFade === 'function' && typeof requestAnimationFrame === 'function') { requestAnimationFrame(toolsFade); setTimeout(toolsFade, 300); }
  }

  // What comes next: the folder's order, or a shuffled walk through the folder (every photo once, then shuffled again).
  function ssPeek() {
    const set = siblingsOf(current);
    if (set.length < 2) return current;
    if (!ss.shuffle) return set[(set.indexOf(current) + 1) % set.length];
    if (!ss.order || !ss.order.length) {
      const rest = set.filter(function (i) { return i !== current; });
      for (let i = rest.length - 1; i > 0; i--) { const j = Math.floor(Math.random() * (i + 1)), t = rest[i]; rest[i] = rest[j]; rest[j] = t; }
      ss.order = rest;
    }
    return ss.order[0];
  }
  function ssAdvance() {
    if (!ss.on || ss.paused) return;
    ss.timer = 0;
    const next = ssPeek();
    if (ss.shuffle && ss.order && ss.order[0] === next) ss.order.shift();
    current = next;
    updateImage();   // (its hook below sets up the next wait)
  }
  function ssPreload() {   // the next photo is fetched while this one is showing
    try {
      const n = ssPeek(), f = files[n];
      if (n === current || isVideoFile(f) || /\.hei[cf]$/i.test(f)) return;
      ss.pre = new Image();
      ss.pre.src = '?img=' + encodeURIComponent(f) + '&size=full' + (fileVersions[n] ? '&v=' + fileVersions[n] : '') + passQ;
    } catch (x) {}
  }
  function ssDisarm() {
    clearTimeout(ss.timer); ss.timer = 0; ss.token++;
    if (ss.cancel) { ss.cancel(); ss.cancel = null; }
  }
  // Start the wait for the photo (or video) that is showing now.
  function ssArm() {
    ssDisarm();
    if (!ss.on || ss.paused) return;
    const my = ss.token, wait = function () { if (my === ss.token && ss.on && !ss.paused) ss.timer = setTimeout(ssAdvance, ss.seconds * 1000); };
    if (isVideoFile(files[current])) {
      const onEnd = function () { if (my === ss.token && ss.on && !ss.paused) ssAdvance(); };
      lightboxVideo.addEventListener('ended', onEnd);
      lightboxVideo.addEventListener('error', wait);
      ss.cancel = function () { lightboxVideo.removeEventListener('ended', onEnd); lightboxVideo.removeEventListener('error', wait); };
      const tryPlay = function (quiet) {
        if (quiet) { lightboxVideo.muted = true; ss.muted = true; }
        const p = lightboxVideo.play();
        if (p && p.catch) p.catch(function () { if (!quiet) tryPlay(true); else wait(); });   // (a browser that won't start it alone: it stays like a photo for a while, then moves on)
      };
      tryPlay(false);
    } else if (lightboxImg.complete && lightboxImg.naturalWidth) {
      wait();
    } else {
      lightboxImg.addEventListener('load', wait, { once: true });
      lightboxImg.addEventListener('error', wait, { once: true });
      ss.cancel = function () { lightboxImg.removeEventListener('load', wait); lightboxImg.removeEventListener('error', wait); };
    }
    ssPreload();
  }

  function ssShowBar() {
    if (!ss.on || !ssBar) return;
    ssBar.classList.remove('idle');
    clearTimeout(ss.idle);
    ss.idle = setTimeout(function () { if (ss.on && !ss.paused && ssBar) ssBar.classList.add('idle'); }, 3500);
  }
  function ssWake() {
    try { if (navigator.wakeLock && navigator.wakeLock.request && !ss.wake) navigator.wakeLock.request('screen').then(function (l) { if (ss.on) { ss.wake = l; l.addEventListener('release', function () { if (ss.wake === l) ss.wake = null; }); } else l.release(); }, function () {}); } catch (x) {}
  }
  function ssPause() {
    if (!ss.on || ss.paused) return;
    ss.paused = true; ssDisarm();
    if (isVideoFile(files[current])) lightboxVideo.pause();
    ssPaint(); ssShowBar();
    if (ssBar) { clearTimeout(ss.idle); ssBar.classList.remove('idle'); }   // (paused: the controls stay in view)
  }
  function ssResume() {
    if (!ss.on || !ss.paused) return;
    ss.paused = false; ssPaint(); ssShowBar(); ssArm();
  }
  function ssToggle() { if (ss.paused) ssResume(); else ssPause(); }

  function ssStart(from) {
    if (ss.on) return;
    if (lbEdit && lbEdit.isOpen()) {   // (what was typed is saved first; then the editor goes and the show begins)
      lbEdit.beforeLeave().then(function (ok) { if (ok) { lbEdit.close(); ssStart(from); } });
      return;
    }
    if (typeof from === 'number' && !lightbox.classList.contains('open')) openLightbox(from);
    if (!lightbox.classList.contains('open') || siblingsOf(current).length < 2) return;
    ss.on = true; ss.paused = false; ss.order = null;
    if (typeof setZoom === 'function' && zoomMode !== 'fit') setZoom('fit');
    lightbox.classList.add('ss-on');
    if (ssBar) ssBar.hidden = false;
    ssPaint(); ssShowBar();
    try {   // as big as the screen can make it, and kept awake: both are only asked for, and a browser may say no
      const req = lightbox.requestFullscreen || lightbox.webkitRequestFullscreen;
      if (req && !document.fullscreenElement && !document.webkitFullscreenElement) { const r = req.call(lightbox); ss.fs = true; if (r && r.catch) r.catch(function () { ss.fs = false; }); }
    } catch (x) { ss.fs = false; }
    ssWake();
    ssArm();
  }
  function ssStop() {
    if (!ss.on) return;
    ss.on = false; ss.paused = false;
    ssDisarm(); clearTimeout(ss.idle); ss.pre = null; ss.order = null;
    if (ss.muted) { lightboxVideo.muted = false; ss.muted = false; }
    lightbox.classList.remove('ss-on');
    if (ssBar) { ssBar.hidden = true; ssBar.classList.remove('idle', 'paused'); }
    if (ss.wake) { try { ss.wake.release(); } catch (x) {} ss.wake = null; }
    if (ss.fs) {
      ss.fs = false;
      try { const out = document.exitFullscreen || document.webkitExitFullscreen; if (out && (document.fullscreenElement || document.webkitFullscreenElement)) { const r = out.call(document); if (r && r.catch) r.catch(function () {}); } } catch (x) {}
    }
    ssPaint();
  }

  // Wiring
  if (ssPlayBtn) ssPlayBtn.addEventListener('click', function () { if (ss.on) ssToggle(); else ssStart(); });
  if (ssPageBtn) ssPageBtn.addEventListener('click', function () {
    // The page's Play starts at the first photo of what the page shows (the folder's own, in the folder's order).
    let at = -1;
    (typeof slideScope !== 'undefined' ? slideScope : []).forEach(function (name) { const i = files.indexOf(name); if (i >= 0 && (at < 0 || i < at)) at = i; });
    if (at >= 0) ssStart(at);
  });
  if (ssToggleBtn) ssToggleBtn.addEventListener('click', function () { ssToggle(); });
  if (ssStopBtn) ssStopBtn.addEventListener('click', function () { ssStop(); });
  if (ssSpeedBtn) ssSpeedBtn.addEventListener('click', function () {
    ss.seconds = SS_SPEEDS[(SS_SPEEDS.indexOf(ss.seconds) + 1) % SS_SPEEDS.length];
    try { localStorage.setItem('gallerySlideSeconds', String(ss.seconds)); } catch (x) {}
    ssPaint(); ssShowBar();
    if (ss.on && !ss.paused && !isVideoFile(files[current])) ssArm();   // (the new time counts from now)
  });
  if (ssShuffleBtn) ssShuffleBtn.addEventListener('click', function () { ss.shuffle = !ss.shuffle; ss.order = null; ssPaint(); ssShowBar(); if (ss.on && !ss.paused) ssPreload(); });
  // A tap on the photo pauses and carries on; a tap on one of the viewer's other buttons (share, edit, link...) pauses, so the photo does not change under it.
  lightbox.addEventListener('click', function (e) {
    if (!ss.on) return;
    if (e.target === lightboxImg) { e.stopPropagation(); e.preventDefault(); ssToggle(); }
  }, true);
  if (ssTools) ssTools.addEventListener('click', function (e) {
    if (!ss.on || ss.paused) return;
    const b = e.target.closest && e.target.closest('button, a');
    if (b && b !== ssPlayBtn && !(ssPlayBtn && ssPlayBtn.contains(b))) ssPause();
  }, true);
  ['mousemove', 'touchstart', 'pointerdown'].forEach(function (name) { lightbox.addEventListener(name, ssShowBar, { passive: true }); });
  document.addEventListener('keydown', function (e) {
    if (!ss.on || !lightbox.classList.contains('open') || e.ctrlKey || e.metaKey || e.altKey) return;
    if (e.target && e.target.closest && e.target.closest('.gs-modal, .gs-large, .fp-modal, .admin-modal, .map-modal, input, textarea, select, [contenteditable="true"]')) return;
    ssShowBar();
    if (e.key === 'Escape') {
      if (typeof miniExpanded !== 'undefined' && miniExpanded) return;   // (the big map closes first, as it always did)
      e.preventDefault(); e.stopImmediatePropagation(); ssStop(); return;   // the first Escape ends the show and leaves the photo up; the next closes the viewer
    }
    if (e.key === 'z' || e.key === 'Z') { e.stopImmediatePropagation(); return; }   // (no zooming while it plays)
    if (e.key === ' ' && !(e.target && e.target.closest && e.target.closest('button, a, video'))) { e.preventDefault(); e.stopImmediatePropagation(); ssToggle(); }
  }, true);
  document.addEventListener('fullscreenchange', function () { if (ss.on && ss.fs && !document.fullscreenElement) { ss.fs = false; ssStop(); } });   // (leaving full screen by the browser's own way ends the show)
  document.addEventListener('visibilitychange', function () { if (ss.on && document.visibilityState === 'visible') ssWake(); });

  // The viewer calls these: after every photo it shows, and when it closes.
  window.__ssShown = function () {
    if (ssPlayBtn) ssPlayBtn.hidden = siblingsOf(current).length < 2;
    if (ss.on) { if (lbEdit && lbEdit.isOpen()) { ssStop(); return; } ssArm(); }
  };
  window.__ssStop = ssStop;
  ssPaint();
