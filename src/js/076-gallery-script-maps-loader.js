  // ---- Maps: Leaflet is fetched only when a visitor asks for one (the overview's "Show map" button, or the little
  // map in the viewer), so a page that nobody opens a map on contacts no third party.
  let leafletQueue = null;
  function loadLeaflet(done) {
    if (window.L) { done(); return; }
    if (leafletQueue) { leafletQueue.push(done); return; }
    leafletQueue = [done];
    const css = document.createElement('link');
    css.rel = 'stylesheet';
    css.href = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.css';
    css.integrity = 'sha384-sHL9NAb7lN7rfvG5lfHpm643Xkcjzp4jFvuavGOndn6pjVqS6ny56CAt3nsEVT4H';   // (the browser refuses the file if the CDN ever serves anything but this exact one)
    css.crossOrigin = 'anonymous';
    document.head.appendChild(css);
    const js = document.createElement('script');
    js.src = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.js';
    js.integrity = 'sha384-cxOPjt7s7Iz04uaHJceBmS+qpjv2JkIHNVcuOrM+YHwZOmJGBXI00mdUXEq65HTH';
    js.crossOrigin = 'anonymous';
    js.onload = function () { const queued = leafletQueue; leafletQueue = null; queued.forEach(function (f) { f(); }); };
    js.onerror = function () { leafletQueue = null; };
    document.head.appendChild(js);
  }
  function remembered(key, value) {
    try { if (value === undefined) return localStorage.getItem(key); localStorage.setItem(key, value); } catch (e) {}
    return null;
  }
  (function () {
    const btn = document.getElementById('map-toggle'), body = document.getElementById('map-preview-body');
    if (!btn || !body) return;
    const label = btn.querySelector('.map-toggle-label');
    if (!label) return;
    const noun = label.textContent.replace(/^Show /, '');
    let drawn = false;
    function set(open, fromLink) {
      body.hidden = !open;
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      label.textContent = (open ? 'Hide ' : 'Show ') + noun;
      btn.title = label.textContent;
      if (!fromLink) remembered('galleryMapOpen', open ? '1' : '0');   // (a link opening it is not the visitor's choice to keep it open)
      if (open && !drawn) {
        loadLeaflet(function () {
          drawn = true;
          const draw = window.__lazyMaps && window.__lazyMaps[body.getAttribute('data-map')];
          if (draw) draw();
        });
      }
    }
    btn.addEventListener('click', function () { set(body.hidden); });
    if (remembered('galleryMapOpen') === '1') set(true);
    // A link ending in #map opens the page with this small map showing (not the full-screen one, which is a lot to close
    // on a phone), and brings it into view.
    function peek() {
      body.classList.toggle('peek-hit', location.hash === '#map');
      if (location.hash !== '#map') return;
      if (body.hidden) set(true, true);
      const sec = document.getElementById('map-preview');
      if (sec && sec.scrollIntoView) sec.scrollIntoView({ block: 'start' });
    }
    peek();
    window.addEventListener('hashchange', peek);
    const peekShare = document.getElementById('map-peek-share');
    if (peekShare) peekShare.addEventListener('click', function () {
      copyToClipboard(location.origin + location.pathname + peekShare.getAttribute('data-href')).then(function () { flashCopied(peekShare); });
    });
  })();

  (function () {
    const modal = document.getElementById('map-modal'), opener = document.getElementById('map-full');
    if (!modal || !opener) return;
    const mapHref = opener.getAttribute('href');
    let drawn = false, pushed = false;
    function show() {
      modal.hidden = false;
      document.body.style.overflow = 'hidden';
      if (!drawn) {
        loadLeaflet(function () {
          drawn = true;
          const draw = window.__lazyMaps && window.__lazyMaps[modal.getAttribute('data-map')];
          if (draw) draw();
        });
      }
    }
    function hide() { modal.hidden = true; document.body.style.overflow = ''; }
    // The address bar follows the overlay (?map), so copying it shares the map; Back closes it.
    function open() {
      show();
      if (!/[?&]map\b/.test(location.search)) { history.pushState({ galleryMap: 1 }, '', mapHref); pushed = true; }
    }
    function close() {
      hide();
      if (pushed) { pushed = false; history.back(); }
      else if (/[?&]map\b/.test(location.search)) { history.replaceState(null, '', location.search.replace(/[?&]map\b(=[^&]*)?/, '').replace(/^&/, '?') || location.pathname); }
    }
    window.addEventListener('popstate', function () { if (!modal.hidden && !/[?&]map\b/.test(location.search)) { pushed = false; hide(); } });
    opener.addEventListener('click', function (e) {
      if (e.metaKey || e.ctrlKey || e.shiftKey || e.button === 1) return;   // "open in a new tab" still works
      e.preventDefault();
      open();
    });
    modal.addEventListener('click', function (e) {
      if (e.target === modal || (e.target.closest && e.target.closest('.map-modal-close'))) { close(); return; }
      const a = e.target.closest && e.target.closest('.leaflet-popup a[href^="#"]');
      if (a) close();   // a pin's link to something on this page: show it
    });
    const share = document.getElementById('map-share');
    if (share) share.addEventListener('click', function () {
      copyToClipboard(location.origin + location.pathname + share.getAttribute('data-href')).then(function () { flashCopied(share); });
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) close(); });
