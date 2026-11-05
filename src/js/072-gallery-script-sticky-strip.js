  // Once the page has scrolled, the two round buttons at the bottom right (to the top, to the bottom) show.
  (function () {
    const fab = document.getElementById('jump-fab'); if (!fab) return;
    const calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const show = function () { const on = window.scrollY > 120; if (fab.hidden === on) fab.hidden = !on; };
    window.addEventListener('scroll', show, { passive: true }); show();
    // Their right edge is the gallery's own (where the strip's last button ends), not the window's: on a wide screen they stay by the photos.
    const strip = document.getElementById('map-bar');
    const edge = function () {
      if (!strip) return;
      const r = strip.getBoundingClientRect(), side = document.documentElement.clientWidth - (r.right - 24);
      if (r.width > 0) fab.style.right = 'max(' + Math.max(14, Math.round(side)) + 'px, calc(14px + env(safe-area-inset-right, 0px)))';
    };
    window.addEventListener('resize', edge); window.addEventListener('load', edge); edge();
    if (strip && typeof ResizeObserver !== 'undefined') new ResizeObserver(edge).observe(strip);
    fab.addEventListener('click', function (e) {
      const b = e.target.closest('.jump-btn'); if (!b) return;
      window.scrollTo({ top: b.getAttribute('data-jump') === 'top' ? 0 : document.documentElement.scrollHeight, behavior: calm ? 'auto' : 'smooth' });
    });
  })();
  // A row with more pills than fit gets a small button at each end (where there is a pointer; a touch screen swipes), and the wheel moves it sideways.
  (function () {
    const bar = document.getElementById('crumb-bar'), row = bar && bar.querySelector('.crumb-row'); if (!row) return;
    if (window.matchMedia && window.matchMedia('(hover: none)').matches) return;   // (a touch screen swipes)
    const mk = function (name, icon) { const b = document.createElement('button'); b.type = 'button'; b.className = 'row-chev'; b.setAttribute('aria-label', name); b.innerHTML = '<svg class="ico" aria-hidden="true"><use href="#i-' + icon + '"></use></svg>'; return b; };
    const left = mk('Scroll the folders left', 'chev-left'), right = mk('Scroll the folders right', 'chev-right');
    bar.insertBefore(left, row); bar.insertBefore(right, row.nextSibling);
    const calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const sync = function () {
      const room = (left.classList.contains('on') ? 36 : 0) + (right.classList.contains('on') ? 36 : 0);   // (what the buttons take: without it a row that just fits would flip them on and off)
      const over = row.scrollWidth > row.clientWidth + room + 2;
      left.classList.toggle('on', over && row.scrollLeft > 2); right.classList.toggle('on', over && row.scrollLeft + row.clientWidth < row.scrollWidth - 2);
    };
    const by = function (d) { row.scrollBy({ left: d * Math.max(160, row.clientWidth * 0.6), behavior: calm ? 'auto' : 'smooth' }); };
    left.addEventListener('click', function () { by(-1); }); right.addEventListener('click', function () { by(1); });
    row.addEventListener('scroll', sync, { passive: true });
    row.addEventListener('wheel', function (e) {
      if (row.scrollWidth <= row.clientWidth + 2 || Math.abs(e.deltaX) >= Math.abs(e.deltaY)) return;
      e.preventDefault(); row.scrollLeft += e.deltaY;
    }, { passive: false });
    window.addEventListener('resize', sync);
    if (typeof ResizeObserver !== 'undefined') new ResizeObserver(sync).observe(row);
    setTimeout(sync, 0); window.addEventListener('load', sync);
  })();
  // The way back stays pinned at the left of the row while the pills swipe, unless it is nearly all of the width (a phone with a deep path):
  // then it swipes away too, so the pills have the room.
  (function () {
    const row = document.querySelector('.crumb-row'), back = row && row.querySelector('.breadcrumb'); if (!back) return;
    const fit = function () { row.classList.toggle('crumb-free', back.offsetWidth > window.innerWidth * 0.7); };
    fit(); window.addEventListener('resize', fit);
  })();
  // Flat with folder names: the top row gets a pill for each folder's section on this page (after the folders beside this one, past a dot).
  // A press scrolls to the section; the one you are in is outlined, and the row swipes to keep it in view.
  (function () {
    const bar = document.getElementById('crumb-bar'), row = bar && bar.querySelector('.crumb-row');
    if (!row) return;
    const secs = Array.prototype.slice.call(document.querySelectorAll('[data-sec]'));
    if (secs.length < 2) { if (!row.querySelector('.breadcrumb')) bar.remove(); return; }
    const calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (row.children.length) { const dot = document.createElement('span'); dot.className = 'crumb-dot'; dot.setAttribute('aria-hidden', 'true'); dot.textContent = '•'; row.appendChild(dot); }
    const nav = document.createElement('nav'); nav.className = 'folder-pills sec-pills'; nav.setAttribute('aria-label', 'Folders on this page');
    const pills = secs.map(function (sec) {
      const a = document.createElement('a'); a.href = '#' + sec.id;
      const priv = sec.getAttribute('data-sec-priv');
      if (priv) {
        a.className = 'priv'; a.title = priv === 'own' ? 'Private folder' : 'Hidden because a folder above it is private';
        a.innerHTML = '<svg class="ico" aria-hidden="true"><use href="#i-lock"></use></svg>';
      }
      a.appendChild(document.createTextNode(sec.getAttribute('data-sec')));
      nav.appendChild(a); return a;
    });
    row.appendChild(nav); bar.classList.add('has-sec');
    nav.addEventListener('click', function (e) {
      const a = e.target.closest('a'), at = a ? pills.indexOf(a) : -1; if (at < 0) return;
      e.preventDefault(); pinned = at; secs[at].scrollIntoView({ behavior: calm ? 'auto' : 'smooth', block: 'start' });
    });
    // (a section near the end of the page may never reach the top: the pill you pressed stays the lit one until you scroll yourself)
    let lit = -1, queued = false, pinned = -1;
    ['pointerdown', 'wheel', 'touchstart', 'keydown'].forEach(function (t) { window.addEventListener(t, function () { pinned = -1; }, { passive: true }); });
    const spy = function () {
      queued = false;
      const strip = document.getElementById('map-bar'), line = (strip ? strip.getBoundingClientRect().bottom : bar.getBoundingClientRect().bottom) + 12;
      let now = -1;
      secs.forEach(function (sec, i) { if (sec.getBoundingClientRect().top <= line) now = i; });
      if (pinned >= 0) now = pinned;
      else if (now >= 0 && window.scrollY + window.innerHeight >= document.documentElement.scrollHeight - 2) now = secs.length - 1;
      if (now === lit) return;
      if (lit >= 0) { pills[lit].classList.remove('here'); pills[lit].removeAttribute('aria-current'); }
      lit = now;
      if (lit < 0) return;
      pills[lit].classList.add('here'); pills[lit].setAttribute('aria-current', 'location'); reveal(calm ? 'auto' : 'smooth');
    };
    // (the row swipes to keep the lit pill in view; it also does when the row's width changes, as it does when the arrows appear at its right)
    const reveal = function (how) {
      const a = pills[lit]; if (!a) return;
      const back = row.querySelector('.breadcrumb'), backW = back && back.offsetWidth && !row.classList.contains('crumb-free') ? back.offsetWidth + 10 : 0;
      if (a.offsetLeft < row.scrollLeft + backW || a.offsetLeft + a.offsetWidth > row.scrollLeft + row.clientWidth) {
        row.scrollTo({ left: a.offsetLeft - backW - (row.clientWidth - backW - a.offsetWidth) / 2, behavior: how });
      }
    };
    if (typeof ResizeObserver !== 'undefined') new ResizeObserver(function () { reveal('auto'); }).observe(row);
    window.addEventListener('scroll', function () { if (!queued) { queued = true; requestAnimationFrame(spy); } }, { passive: true });
    window.addEventListener('resize', spy);
    spy();
  })();
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
      // A page of just a few folder cards has a narrower column than the usual: the row above takes the strip's own width.
      if (crumbs) {
        const r = bar.getBoundingClientRect(), narrow = /covers-\d/.test(bar.className) && r.left > 0;
        crumbs.style.paddingLeft = narrow ? r.left + 24 + 'px' : ''; crumbs.style.paddingRight = narrow ? root.clientWidth - r.right + 24 + 'px' : '';
      }
      edge();
    };
    window.addEventListener('scroll', edge, { passive: true }); window.addEventListener('resize', sizes);
    if (crumbs && typeof ResizeObserver !== 'undefined') new ResizeObserver(sizes).observe(crumbs);
    sizes();
    window.addEventListener('scroll', function () { if (crumbs) crumbs.classList.toggle('stuck', window.scrollY > 0); }, { passive: true });
  })();
  // The row of sibling folders starts with the current one in view.
  (function () {
    const row = document.querySelector('.crumb-row'), cur = row && row.querySelector('.folder-pills .current'), back = row && row.querySelector('.breadcrumb');
    const bw = back && !row.classList.contains('crumb-free') ? back.offsetWidth : 0;
    if (cur) row.scrollLeft = cur.offsetLeft - bw - (row.clientWidth - bw - cur.offsetWidth) / 2;
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
