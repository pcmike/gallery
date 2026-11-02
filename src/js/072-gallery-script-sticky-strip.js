  // The strip of buttons sticks to the top; once the page has scrolled under it, it gets a soft edge.
  (function () {
    const bar = document.getElementById('map-bar'); if (!bar) return;
    const crumbs = document.getElementById('crumb-bar'), root = document.documentElement;
    let topAt = 0;   // where the strip sticks: under the folder row (and under a preview banner, if one is showing)
    const edge = function () { bar.classList.toggle('stuck', window.scrollY > 0 && bar.getBoundingClientRect().top <= topAt + 0.5); };
    // The folder row sticks above the strip: the strip's top is the row's height (and below a preview banner, if one is showing).
    const sizes = function () {
      const pv = document.querySelector('.link-preview');
      const pvH = pv ? pv.offsetHeight || 0 : 0, crH = crumbs ? crumbs.offsetHeight || 0 : 0;
      root.style.setProperty('--stick-top', pvH + 'px');
      root.style.setProperty('--crumb-h', crH + 'px');
      topAt = pvH + crH;
      edge();
    };
    window.addEventListener('scroll', edge, { passive: true }); window.addEventListener('resize', sizes);
    if (crumbs && typeof ResizeObserver !== 'undefined') new ResizeObserver(sizes).observe(crumbs);
    sizes();
    const jumps = crumbs && crumbs.querySelector('.jump-btns');
    if (jumps) {
      // Down and up arrows (in that order) at the right of the row, once the page has scrolled: to the bottom, to the top.
      const calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      const show = function () { const on = window.scrollY > 120; if (jumps.hidden === on) jumps.hidden = !on; crumbs.classList.toggle('stuck', window.scrollY > 0); };
      window.addEventListener('scroll', show, { passive: true }); show();
      crumbs.addEventListener('click', function (e) {
        const b = e.target.closest('.jump-btn'); if (!b) return;
        window.scrollTo({ top: b.getAttribute('data-jump') === 'top' ? 0 : document.documentElement.scrollHeight, behavior: calm ? 'auto' : 'smooth' });
      });
    }
  })();
  // The row of sibling folders starts with the current one in view.
  (function () {
    const row = document.querySelector('.crumb-row'), cur = row && row.querySelector('.folder-pills .current'), back = row && row.querySelector('.breadcrumb');
    if (cur) row.scrollLeft = cur.offsetLeft - (back ? back.offsetWidth : 0) - (row.clientWidth - (back ? back.offsetWidth : 0) - cur.offsetWidth) / 2;
  })();
  // The archive always asks first, saying how many files and how big: it is a long, heavy download, and nothing is done on the server until the answer is yes.
  document.querySelectorAll('.page-archive').forEach(function (a) {
    a.addEventListener('click', function (e) {
      if (!confirm(a.dataset.ask + '?\n\nIt is sent as it is built, and a big one can take a while.')) e.preventDefault();
    });
  });
  const downloadLink = document.getElementById('lightbox-download');   // only on a page made for a signed-in visitor
  // The download button on a phone: the original goes to the share sheet, which offers "Save Image" / "Save Video" straight into
  // Photos (a plain download would land in Files). Where sharing a file isn't possible, or anything goes wrong, it is a plain
  // download as before. Safari only allows the share sheet right after a tap, so if the fetch took too long the button lights up
  // and the next tap opens it.
  if (downloadLink && navigator.share && navigator.canShare && (navigator.maxTouchPoints || 0) > 0) {
    let ready = null, toastTimer = null;
    const toast = document.getElementById('lightbox-toast');
    const say = function (text) {
      if (!toast) return;
      toast.textContent = text; toast.hidden = false;
      clearTimeout(toastTimer); toastTimer = setTimeout(function () { toast.hidden = true; }, 4000);
    };
    const plain = function (href) { const a = document.createElement('a'); a.href = href; a.download = ''; document.body.appendChild(a); a.click(); a.remove(); };
    downloadLink.addEventListener('click', function (e) {
      e.preventDefault();
      if (downloadLink.classList.contains('busy')) return;
      const href = downloadLink.getAttribute('href');
      if (ready && ready.href === href) {
        const file = ready.file; ready = null; downloadLink.classList.remove('ready');
        navigator.share({ files: [file] }).catch(function (err) { if (!err || err.name !== 'AbortError') plain(href); });
        return;
      }
      downloadLink.classList.add('busy'); say('Getting the original\u2026');
      // An ordinary photo on the screen IS the original file (the viewer shows it untouched), so the browser already holds
      // it: asking for that same address again is answered from its memory, at once, while the tap still counts. (A HEIC
      // photo is shown as a converted copy and a video isn't a picture, so those fetch the original.)
      const shown = files[current], fromMemory = !isVideoFile(shown) && !/\.hei[cf]$/i.test(shown) && lightboxImg.getAttribute('src');
      fetch(fromMemory || href, fromMemory ? { credentials: 'same-origin', cache: 'force-cache' } : { credentials: 'same-origin' })
        .then(function (r) {
          if (!r.ok) throw new Error('status');
          if ((Number(r.headers.get('content-length')) || 0) > 150 * 1024 * 1024) throw new Error('too big to hold in the browser');
          return r.blob();
        })
        .then(function (blob) {
          const name = decodeURIComponent((href.split('original=')[1] || 'photo').split('&')[0]).split('/').pop();
          const file = new File([blob], name, { type: blob.type || 'application/octet-stream' });
          if (!navigator.canShare({ files: [file] })) throw new Error('unsupported');
          toast && (toast.hidden = true);
          return navigator.share({ files: [file] }).catch(function (err) {
            if (err && err.name === 'AbortError') return;
            if (err && err.name === 'NotAllowedError') { ready = { href: href, file: file }; downloadLink.classList.add('ready'); say('Ready: tap the button again to save'); return; }
            throw err;
          });
        })
        .catch(function () { if (toast) toast.hidden = true; plain(href); })
        .then(function () { downloadLink.classList.remove('busy'); });
    });
  }
  function updateImage() {
    const file = files[current];
    if (isVideoFile(file)) {
      lightboxImg.style.display = 'none';
      lightboxImg.src = '';
      lightboxVideo.style.display = '';
