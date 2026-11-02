  // The sort button: what comes first and in which order. A choice is kept in a cookie (so it follows the visitor from folder
  // to folder) and the page is loaded again, because the server puts the photos in order, the viewer's next/previous with them.
  (function () {
    const combo = document.getElementById('sort-combo');
    if (!combo) return;
    const pop = document.getElementById('sort-pop'), menu = document.getElementById('sort-menu-btn');
    const resetBtn = pop && pop.querySelector('.sort-reset');
    if (!pop || !menu || !resetBtn) return;
    const parse = function (text) {
      const parts = String(text).split('.');
      const s = { order: parts.shift() || 'name', desc: false, private: false, video: false, located: false };
      parts.forEach(function (p) { if (p in s && p !== 'order') s[p] = true; });
      return s;
    };
    const encode = function (s) { return [s.order].concat(['desc', 'private', 'video', 'located'].filter(function (k) { return s[k]; })).join('.'); };
    let spec = parse(combo.getAttribute('data-spec'));
    const paint = function () {
      pop.querySelectorAll('[data-order]').forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-order') === (spec.order === 'modified' ? 'taken' : spec.order) ? 'true' : 'false'); });
      const dates = spec.order !== 'name';
      pop.querySelector('[data-dir="asc"]').textContent = dates ? 'Oldest first' : 'A to Z';
      pop.querySelector('[data-dir="desc"]').textContent = dates ? 'Newest first' : 'Z to A';
      pop.querySelectorAll('[data-dir]').forEach(function (b) { b.setAttribute('aria-pressed', (b.getAttribute('data-dir') === 'desc') === spec.desc ? 'true' : 'false'); });
      combo.querySelectorAll('.sort-flag').forEach(function (b) { b.setAttribute('aria-pressed', spec[b.getAttribute('data-flag')] ? 'true' : 'false'); });
    };
    const go = function (text) {
      const u = new URL(location.href);
      u.searchParams.delete('sort');
      const cookie = 'gallery_sort=' + (text === null ? '' : text) + '; path=' + combo.getAttribute('data-path') + '; max-age=' + (text === null ? 0 : 31536000) + '; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
      try { document.cookie = cookie; } catch (e) {}
      if (u.toString() === location.href) location.reload(); else location.assign(u.toString());
    };
    const close = function () { pop.hidden = true; menu.setAttribute('aria-expanded', 'false'); };
    menu.addEventListener('click', function (e) { e.stopPropagation(); const open = pop.hidden; pop.hidden = !open; menu.setAttribute('aria-expanded', open ? 'true' : 'false'); });
    pop.addEventListener('click', function (e) { e.stopPropagation(); });
    document.addEventListener('click', close);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
    pop.querySelectorAll('[data-order]').forEach(function (b) { b.addEventListener('click', function () { spec.order = b.getAttribute('data-order'); go(encode(spec)); }); });
    pop.querySelectorAll('[data-dir]').forEach(function (b) { b.addEventListener('click', function () { spec.desc = b.getAttribute('data-dir') === 'desc'; go(encode(spec)); }); });
    combo.querySelectorAll('.sort-flag').forEach(function (b) { b.addEventListener('click', function () { const k = b.getAttribute('data-flag'); spec[k] = !spec[k]; go(encode(spec)); }); });
    resetBtn.addEventListener('click', function () { go(null); });
    paint();
  })();
  // The owner's lock and star, on every tile and in the viewer: flip a photo between private and public, or make it its folder's
  // cover, with the admin's own save (a token is fetched first). The state lives in ownerState; every button is drawn from it.
  // A photo's privacy has up to three states. Its own setting is "private", "public" or "" (none: it follows its folder). Where a folder
  // above it is private ("inh") the lock goes round Folder (dashed) -> Private (kept on its own) -> Public -> Folder; elsewhere it is
  // Public <-> Private. lockKind() names what is drawn: own (solid red), inh (dashed, "Folder"), pub (open lock, public inside a
  // private folder) or none (an ordinary public photo).
  function lockKind(f) {
    const o = ownerState.own[f] || '';
    if (o === 'private') return 'own';
    if (o === 'public' && ownerState.inh[f]) return 'pub';
    return o === '' && ownerState.inh[f] ? 'inh' : 'none';
  }
  function nextOwn(f) {
    const o = ownerState.own[f] || '';
    if (ownerState.inh[f]) return o === '' ? 'private' : (o === 'private' ? 'public' : 'inherit');
    return o === 'private' ? 'inherit' : 'private';
  }
  function setOwn(f, state) {
    ownerState.own[f] = state === 'inherit' ? '' : state;
    ownerState.priv[f] = state === 'private' || (state === 'inherit' && !!ownerState.inh[f]);
    privBannerRefresh();
  }
  // On a private folder's own page the banner says how many photos would stay private and how many would become public if it were
  // opened; it is asked for again after each change, so it always matches the locks.
  let bannerTimer = 0;
  function privBannerRefresh() {
    const el = document.getElementById('folder-priv-banner'); if (!el) return;
    clearTimeout(bannerTimer);
    bannerTimer = setTimeout(function () {
      fetch('?admin&folder=' + encodeURIComponent(el.getAttribute('data-folder')) + '&privcounts=1', { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { return r.ok && (r.headers.get('content-type') || '').indexOf('json') >= 0 ? r.json() : null; })
        .then(function (j) {
          const c = j && j.counts, n = el.querySelector('.fpb-counts');
          const noun = el.getAttribute('data-noun') || 'photo', many = function (k) { return k + ' ' + noun + (k === 1 ? '' : 's'); };
          if (c && n) n.textContent = many(c.stay) + ' will stay private \u00b7 ' + many(c.open) + (c.open === 1 ? ' becomes' : ' become') + ' public when you open it';
        }).catch(function () {});
    }, 250);
  }
  function lockTitle(f) {
    const k = lockKind(f);
    if (k === 'inh') return 'Private because its folder is: press to keep it private on its own';
    if (k === 'pub') return 'Public, though its folder is private: press to follow the folder again';
    if (k === 'own') return ownerState.inh[f] ? 'Kept private on its own: press to make it public' : 'Private: press to make it public';
    return 'Public: press to make it private';
  }
  function lockSaid(f, state) {
    if (state === 'private') return ownerState.inh[f] ? 'Kept private on its own' : 'Now private';
    if (state === 'public') return ownerState.inh[f] ? 'Public, though its folder is private' : 'Now public';
    return ownerState.inh[f] ? 'Follows its folder (private)' : 'Now public';
  }
  function ownerPaint() {
    if (!ownerState) return;
    document.querySelectorAll('.priv-toggle').forEach(function (b) {
      const f = b.getAttribute('data-file'), k = lockKind(f), on = !!ownerState.priv[f];
      b.classList.toggle('is-private', on); b.classList.toggle('is-folder', k === 'inh'); b.setAttribute('aria-pressed', on ? 'true' : 'false');
      b.title = lockTitle(f);
      b.innerHTML = '<svg class="ico" aria-hidden="true"><use href="#i-' + (on ? 'lock' : 'unlock') + '"></use></svg>' + (k === 'inh' ? '<span>Folder</span>' : (on ? '<span>Private</span>' : ''));
    });
    document.querySelectorAll('.cover-toggle').forEach(function (b) {
      const on = !!ownerState.cover[b.getAttribute('data-file')];
      b.classList.toggle('is-cover', on); b.setAttribute('aria-pressed', on ? 'true' : 'false');
      b.title = on ? 'This is the folder’s cover photo' : 'Make this the folder’s cover photo';
    });
    const file = files[current], lc = document.getElementById('lightbox-cover'), lp = document.getElementById('lightbox-private');
    if (lc && file !== undefined) {
      const on = !!ownerState.cover[file];
      lc.classList.toggle('on', on); lc.title = on ? 'This is the folder’s cover photo' : 'Make this the folder’s cover photo'; lc.setAttribute('aria-label', lc.title);
    }
    if (lp && file !== undefined) {
      const on = !!ownerState.priv[file];
      lp.classList.toggle('on', on); lp.classList.toggle('folder', lockKind(file) === 'inh'); lp.title = lockTitle(file); lp.setAttribute('aria-label', lp.title);
      lp.innerHTML = '<svg class="ico" aria-hidden="true"><use href="#i-' + (on ? 'lock' : 'unlock') + '"></use></svg>';
    }
  }
  document.addEventListener('click', function (e) {
    const btn = e.target.closest ? e.target.closest('.priv-toggle, .cover-toggle, [data-owner]') : null;
    if (!btn || !ownerState) return;
    e.preventDefault(); e.stopPropagation();
    if (btn.classList.contains('busy')) return;
    const act = btn.hasAttribute('data-owner') ? btn.getAttribute('data-owner') : (btn.classList.contains('cover-toggle') ? 'cover' : 'private');
    const file = btn.hasAttribute('data-owner') ? files[current] : btn.getAttribute('data-file');
    const slash = file.lastIndexOf('/'), folder = slash < 0 ? '' : file.slice(0, slash), bare = file.slice(slash + 1);
    const say = function (text) {
      const t = document.getElementById(lightbox.classList.contains('open') ? 'lightbox-toast' : 'owner-toast'); if (!t) return;
      t.textContent = text; t.hidden = false; clearTimeout(say.timer); say.timer = setTimeout(function () { t.hidden = true; }, 3500);
    };
    const nextState = act === 'private' ? nextOwn(file) : '';
    if (act === 'cover' && ownerState.cover[file]) { say('Already this folder’s cover photo'); return; }
    btn.classList.add('busy');
    const base = '?admin' + (folder ? '&folder=' + encodeURIComponent(folder) : '');
    fetch('?admin&csrf=1', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { if ((r.headers.get('content-type') || '').indexOf('json') < 0) throw new Error('signed out'); return r.json(); })
      .then(function (j) {
        const body = new URLSearchParams();
        body.set('csrf_token', j.token); body.set('file', bare);
        if (act === 'cover') body.set('action', 'set_cover');
        else { body.set('action', 'set_item_flag'); body.set('flag', 'private'); body.set('state', nextState); }
        return fetch(base, { method: 'POST', credentials: 'same-origin', body: body.toString(), headers: { 'X-Requested-With': 'gallery-fetch', 'Content-Type': 'application/x-www-form-urlencoded' } });
      })
      .then(function (r) { if ((r.headers.get('content-type') || '').indexOf('json') < 0) throw new Error('signed out'); return r.json(); })
      .then(function (j) {
        if (!j.ok) throw new Error(j.text || 'not saved');
        if (act === 'cover') {
          files.forEach(function (f) { if (folderOf(f) === folder) ownerState.cover[f] = false; });
          ownerState.cover[file] = true;
          say('Now the folder’s cover photo');
        } else {
          setOwn(file, nextState);
          say(lockSaid(file, nextState));
        }
        ownerPaint();
      })
      .catch(function (err) { say(err && err.message === 'signed out' ? 'Your admin session ended: sign in again' : 'Not saved: ' + (err && err.message ? err.message : 'try again')); })
      .then(function () { btn.classList.remove('busy'); });
  }, true);
