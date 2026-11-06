  // For someone using a share link (or, when the public address's log is on, an ordinary visitor): tell the gallery's owner, in small batches, what came on screen (a picture that stayed more than a
  // moment), what the viewer showed, what played and which folder page this is. Nothing is sent that the page itself didn't show.
  (function () {
    var queue = [], timer = null, seenKeys = {}, entrySent = !galleryEntry, url = '?linklog' + passQ;
    function flush() {
      timer = null; if (!queue.length && entrySent) return;
      var payload = { e: queue.splice(0, 100) };
      if (!entrySent) { payload.a = galleryEntry; entrySent = true; }   // (how the visit began, with the first batch only)
      var body = JSON.stringify(payload);
      try {
        if (navigator.sendBeacon && navigator.sendBeacon(url, new Blob([body], { type: 'application/json' }))) { /* sent */ }
        else fetch(url, { method: 'POST', body: body, keepalive: true, headers: { 'Content-Type': 'application/json' } }).catch(function () {});
      } catch (e) {}
      if (queue.length) timer = setTimeout(flush, 2000);
    }
    function log(kind, file) {
      if (kind === 's') { if (seenKeys[file]) return; seenKeys[file] = 1; }
      queue.push([kind, file]);
      if (!timer) timer = setTimeout(flush, 4000);
    }
    window.__linkLog = log;
    document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') flush(); });
    window.addEventListener('pagehide', flush);
    var meta = document.querySelector('meta[name="gallery-folder"]');
    if (passQ) { if (galleryEntry) timer = setTimeout(flush, 1500); }   // (a photo's own address is not a page of the gallery: no front-page line for it)
    else log('f', meta ? meta.getAttribute('content') : '');
    function fileOf(img) { try { return new URL(img.currentSrc || img.src, location.href).searchParams.get('img'); } catch (e) { return null; } }
    if ('IntersectionObserver' in window) {
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) {
          var img = en.target;
          if (en.isIntersecting) {
            clearTimeout(img.__seenT);
            img.__seenT = setTimeout(function () { var f = img.complete && img.naturalWidth > 0 ? fileOf(img) : null; if (f) { log('s', f); io.unobserve(img); } }, 400);
          } else clearTimeout(img.__seenT);
        });
      }, { threshold: 0.5 });
      [].forEach.call(document.querySelectorAll('img[src*="?img="], img[srcset*="?img="]'), function (img) { io.observe(img); });
    }
    if (typeof lightboxVideo !== 'undefined' && lightboxVideo) lightboxVideo.addEventListener('play', function () { log('p', files[current]); });
  })();
