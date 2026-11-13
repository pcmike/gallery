  })();

  // ---- A pin's thumbnail (on the little map or the full-screen one): that photo, or its group, is scrolled to the middle and ringed for a
  // moment, and a pill leads back to the map. The little map stays open; the full-screen one closes first (it covers the page).
  // The pill goes at the first of: pressed, its cross or Escape, the map on screen again, about 10 seconds untouched, a photo opened.
  (function () {
    const modal = document.getElementById('map-modal'), peek = document.getElementById('map-preview-body');
    const calm = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    let pill = null, fadeTimer = null, ringTimer = null, ringed = null, io = null, armed = false, settled = false;
    function dropPill() {
      if (fadeTimer) { clearTimeout(fadeTimer); fadeTimer = null; }
      if (io) { io.disconnect(); io = null; }
      if (pill && pill.parentNode) pill.parentNode.removeChild(pill);
      pill = null;
    }
    function startFade() {
      if (fadeTimer) clearTimeout(fadeTimer);
      fadeTimer = setTimeout(function () {
        if (!pill) return;
        if (calm) { dropPill(); return; }
        pill.classList.add('fading'); fadeTimer = setTimeout(dropPill, 300);
      }, 10000);
    }
    function showPill(fromModal, backHref) {   // backHref: the pin was pressed on another page (this one is where it led): the pill goes back to that page, its map open
      dropPill();
      pill = document.createElement('div'); pill.className = 'map-back'; pill.setAttribute('role', 'status');
      const go = document.createElement('button'); go.type = 'button'; go.className = 'map-back-go'; go.textContent = '\u2190 Back to the map';
      const x = document.createElement('button'); x.type = 'button'; x.className = 'map-back-x'; x.setAttribute('aria-label', 'Dismiss'); x.textContent = '\u00d7';
      pill.appendChild(go); pill.appendChild(x); document.body.appendChild(pill);
      x.addEventListener('click', dropPill);
      go.addEventListener('click', function () {
        dropPill();
        if (backHref) { location.href = backHref; return; }
        if (fromModal) { const o = document.getElementById('map-full'); if (o) o.click(); return; }
        const t = document.getElementById('map-toggle');
        if (peek && peek.hidden && t) t.click();
        (document.getElementById('map-preview') || peek).scrollIntoView({ block: 'center', behavior: calm ? 'auto' : 'smooth' });
      });
      pill.addEventListener('mouseenter', function () { if (fadeTimer) { clearTimeout(fadeTimer); fadeTimer = null; } });
      pill.addEventListener('mouseleave', startFade);
      startFade();
      armed = false; settled = false;
      setTimeout(function () { settled = true; }, 1200);
      // the little map back on screen (after it has been off screen) is what the pill was for
      if (!fromModal && !backHref && peek && window.IntersectionObserver) {
        io = new IntersectionObserver(function (entries) {
          entries.forEach(function (en) {
            if (!en.isIntersecting) armed = true;
            else if (armed && settled) dropPill();
          });
        }, { threshold: 0.2 });
        io.observe(peek);
      }
    }
    function ring(el) {
      if (ringed) ringed.classList.remove('find-ring');
      if (ringTimer) clearTimeout(ringTimer);
      el.classList.add('find-ring'); ringed = el;
      ringTimer = setTimeout(function () { el.classList.remove('find-ring'); if (ringed === el) ringed = null; }, 3500);
    }
    function settle(cb) {   // the full-screen map steps the browser back a page when it closes: let that finish first
      let done = false;
      const go = function () { if (done) return; done = true; window.removeEventListener('popstate', go); setTimeout(cb, 120); };   // (a beat after the step back: a scroll started inside it is dropped by the browser)
      window.addEventListener('popstate', go); setTimeout(go, 350);
    }
    document.addEventListener('click', function (e) {
      if (e.defaultPrevented || e.metaKey || e.ctrlKey || e.shiftKey || e.button) return;
      // a pin whose photo is in another folder opens that folder's page: remember where it was pressed, so that page can lead back
      const far = e.target.closest && e.target.closest('.leaflet-popup a[href^="?folder="]');
      if (far) { try { sessionStorage.setItem('galleryMapBack', JSON.stringify({ from: location.pathname + location.search, t: Date.now() })); } catch (err) {} return; }
      const a = e.target.closest && e.target.closest('.leaflet-popup a[href^="#group-"]'); if (!a) return;
      let id; try { id = decodeURIComponent(a.getAttribute('href').slice(1)); } catch (err) { return; }
      const target = document.getElementById(id); if (!target) return;   // not on this page: the link does what it always did
      e.preventDefault();
      const fromModal = !!(modal && !modal.hidden && modal.contains(a));
      const land = function () {
        target.scrollIntoView({ block: 'center', behavior: calm ? 'auto' : 'smooth' });
        ring(target); showPill(fromModal);
      };
      if (fromModal) settle(land); else land();
    }, true);   // (capture: the full-screen map's own handler hides it, and it is still showing when this one looks)
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && pill) dropPill(); });
    // landed on a photo from a pin pressed on another page (within the last two minutes): the pill leads back to that page with its map open
    (function () {
      let rec = null;
      try { rec = JSON.parse(sessionStorage.getItem('galleryMapBack') || 'null'); sessionStorage.removeItem('galleryMapBack'); } catch (err) { rec = null; }
      if (!rec || typeof rec.from !== 'string' || !(Date.now() - rec.t < 120000) || location.hash.indexOf('#group-') !== 0) return;
      setTimeout(function () { const back = rec.from.split('#')[0]; showPill(false, /[?&]map\b/.test(back) ? back : back + '#map'); }, 700);
    })();
    // the full map opened again, or a photo opened large: the pill has done its job
    if (modal && window.MutationObserver) new MutationObserver(function () { if (!modal.hidden) dropPill(); }).observe(modal, { attributes: true, attributeFilter: ['hidden'] });
    const lb = document.getElementById('lightbox');
    if (lb && window.MutationObserver) new MutationObserver(function () { if (lb.classList.contains('open')) dropPill(); }).observe(lb, { attributes: true, attributeFilter: ['class'] });
  })();

  // ---- The little map in the viewer: where the open photo was taken, top left of the photo, about half a mile
  // across. Click it to enlarge and move around; the 📍 button beside the caption turns it off (and on again).
  const mapBox = document.getElementById('lightbox-map');
  const mapToggle = document.getElementById('lightbox-map-toggle');
  const webLink = document.getElementById('lightbox-web');
  let miniMap = null, miniMarker = null, miniExpanded = false;
  const MINI_ZOOM = 15;
  // On a phone the little map would cover a good part of the photo, so it starts off there (and stays wherever the viewer
  // puts it after that); the pin button beside the caption turns it on, and says so with a short pulse the first time.
  const phoneish = function () { return !!(window.matchMedia && window.matchMedia('(max-width: 700px), (hover: none) and (pointer: coarse)').matches); };
  function miniWanted() { const r = remembered('galleryLightboxMap'); return r === null || r === undefined ? !phoneish() : r !== '0'; }
  // While the owner's editor is up the map starts off (it would cover a photo made small for the editor) and the pin button turns it on
  // for as long as the editor is open, without changing what the viewer chose to keep.
  let editMap = false;
  function mapEditing() { return !!(lbEdit && lbEdit.isOpen()); }
  function miniShown() { return mapEditing() ? editMap : miniWanted(); }
  function mapEditStart() { editMap = false; updateLightboxExtras(); }
  function mapEditEnd() { editMap = false; updateLightboxExtras(); }
  function placeMiniMap() {
    if (!mapBox || mapBox.hidden || miniExpanded) return;
    if (zoomMode !== 'fit') { mapBox.style.left = '12px'; mapBox.style.top = '12px'; return; }
    const shown = lightboxImg.style.display === 'none' ? lightboxVideo : lightboxImg;
    const r = shown.getBoundingClientRect();
    if (mapEditing()) {   // smaller, so the photo stays mostly in view
      const w = Math.max(84, Math.min(170, Math.round(r.width * 0.42)));
      mapBox.style.width = w + 'px'; mapBox.style.height = Math.round(w * 0.76) + 'px';
      if (miniMap) miniMap.invalidateSize();
    } else { mapBox.style.width = ''; mapBox.style.height = ''; }
    mapBox.style.left = Math.round(Math.max(8, r.left + 10)) + 'px';
    mapBox.style.top = Math.round(Math.max(8, r.top + 10)) + 'px';
  }
  function setMiniInteractive(on) {
    if (!miniMap) return;
    ['dragging', 'scrollWheelZoom', 'doubleClickZoom', 'touchZoom', 'boxZoom', 'keyboard'].forEach(function (h) {
      if (miniMap[h]) { if (on) miniMap[h].enable(); else miniMap[h].disable(); }
    });
    if (miniMap.zoomControl) { if (on) miniMap.zoomControl.addTo(miniMap); else miniMap.zoomControl.remove(); }
  }
  function expandMiniMap() {
    if (miniExpanded || !miniMap) return;
    miniExpanded = true;
    mapBox.classList.add('expanded');
    mapBox.style.left = ''; mapBox.style.top = ''; mapBox.style.width = ''; mapBox.style.height = '';
    setMiniInteractive(true);
    miniMap.invalidateSize();
    const loc = locations[current];
    if (loc) {
      const link = mapBox.querySelector('.lbmap-osm');
      if (link) link.href = 'https://www.openstreetmap.org/?mlat=' + loc[0] + '&mlon=' + loc[1] + '#map=16/' + loc[0] + '/' + loc[1];
    }
  }
  function collapseMiniMap() {
    if (!miniExpanded) return;
    miniExpanded = false;
    if (mapBox) mapBox.classList.remove('expanded');
    setMiniInteractive(false);
    const loc = locations[current];
    if (miniMap && loc) { miniMap.invalidateSize(); miniMap.setView([loc[0], loc[1]], MINI_ZOOM, { animate: false }); }
    placeMiniMap();
  }
  function showMiniAt(loc) {
    if (!mapBox || mapBox.hidden || locations[current] !== loc) return;
    if (!miniMap) {
      miniMap = L.map(mapBox, { zoomControl: true, attributionControl: true });
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a>',
        maxZoom: 19,
      }).addTo(miniMap);
      miniMap.attributionControl.setPrefix(false);
      miniMarker = L.circleMarker([loc[0], loc[1]], { radius: 7, color: '#fff', weight: 2, fillColor: '#5865f2', fillOpacity: 0.95 }).addTo(miniMap);
      setMiniInteractive(false);
    }
    miniMarker.setLatLng([loc[0], loc[1]]);
    miniMap.invalidateSize();
    miniMap.setView([loc[0], loc[1]], MINI_ZOOM, { animate: false });
  }
  function updateLightboxExtras() {
    const web = webLinks[current];
    if (webLink) {
      if (web) { webLink.href = web; webLink.title = 'Information link: ' + web; webLink.hidden = false; } else { webLink.hidden = true; webLink.removeAttribute('href'); webLink.title = ''; }
    }
    if (!mapBox || !mapToggle) return;
    const loc = locations[current];
    mapToggle.hidden = !loc;
    mapToggle.classList.toggle('off', !miniShown());
    mapToggle.title = miniShown() ? 'Hide the little map' : 'Show where this was taken';
    mapToggle.classList.toggle('on', miniShown());
    // A reminder that the map is there, on every photo that has a location, for as long as the viewer hasn't pressed the button
    // (either way): a short pulse, restarted for each photo.
    mapToggle.classList.remove('nudge');
    if (loc && !miniShown() && !mapEditing() && remembered('galleryLightboxMap') === null) { void mapToggle.offsetWidth; mapToggle.classList.add('nudge'); }
    if (!loc || !miniShown()) { collapseMiniMap(); mapBox.hidden = true; return; }
    mapBox.hidden = false;
    placeMiniMap();
    loadLeaflet(function () { showMiniAt(loc); });
  }
  if (mapBox && mapToggle) {
    mapToggle.addEventListener('click', function () {
      if (mapEditing()) editMap = !editMap; else remembered('galleryLightboxMap', miniWanted() ? '0' : '1');
      mapToggle.classList.remove('nudge');
      updateLightboxExtras();
    });
    mapBox.addEventListener('click', function (e) {
      if (e.target.closest && e.target.closest('.lbmap-close')) { e.stopPropagation(); collapseMiniMap(); return; }
      if (e.target.closest && e.target.closest('.lbmap-osm')) return;
      if (!miniExpanded) expandMiniMap();
    });
    ['touchstart', 'touchend'].forEach(function (name) {
      mapBox.addEventListener(name, function (e) { e.stopPropagation(); }, { passive: true });
    });
    lightboxImg.addEventListener('load', placeMiniMap);
    lightboxVideo.addEventListener('loadedmetadata', placeMiniMap);
    lightboxVideo.addEventListener('resize', placeMiniMap);   // (the video's own picture changed size)
    window.addEventListener('resize', placeMiniMap);
    // What is shown can change size after it was placed against (a video that grows when it starts playing, a photo that
    // settles to its size): the map follows the corner of whichever one is on screen, whenever its size changes.
    if (typeof ResizeObserver === 'function') {
      const sizeWatch = new ResizeObserver(function () { placeMiniMap(); });
      sizeWatch.observe(lightboxImg); sizeWatch.observe(lightboxVideo);
    }
  }
