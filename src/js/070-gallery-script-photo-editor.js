    let isOpen = false, st = null, seq = 0, token = null;
    const bareOf = function (file) { return file.slice(file.lastIndexOf('/') + 1); };
    const jsonOf = function (r) { if ((r.headers.get('content-type') || '').indexOf('json') < 0) throw new Error('signed out'); return r.json(); };
    const setStatus = function (kind, text) {
      statusEl.className = 'lbe-status' + (kind ? ' ' + kind : '');
      statusEl.textContent = '';
      if (kind === 'ok') statusEl.innerHTML = '<svg class="ico" aria-hidden="true"><use href="#i-check"></use></svg> ';
      statusEl.appendChild(document.createTextNode(text || ''));
    };
    const problem = function (err) { setStatus('err', err && err.message === 'signed out' ? 'Your admin session ended: sign in again' : (err && err.message ? err.message : 'Not saved: try again')); };
    const csrf = function () {
      if (token) return Promise.resolve(token);
      return fetch('?admin&csrf=1', { credentials: 'same-origin', cache: 'no-store' }).then(jsonOf).then(function (j) { token = j.token; return token; });
    };
    const post = function (file, fields) {
      return csrf().then(function (tok) {
        const body = new URLSearchParams(); body.set('csrf_token', tok);
        Object.keys(fields).forEach(function (k) { body.set(k, fields[k]); });
        const folder = folderOf(file);
        return fetch('?admin' + (folder ? '&folder=' + encodeURIComponent(folder) : ''), { method: 'POST', credentials: 'same-origin', body: body.toString(), headers: { 'X-Requested-With': 'gallery-fetch', 'Content-Type': 'application/x-www-form-urlencoded' } });
      }).then(jsonOf);
    };
    const values = function () { return { caption: f.caption.value.trim(), web: f.web.value.trim(), lat: f.lat.value.trim(), lng: f.lng.value.trim(), label: f.label.value.trim() }; };
    const textDirty = function (s, v) { return v.caption !== s.base.caption || v.web !== s.base.web; };
    const locDirty = function (s, v) { return v.lat !== s.base.lat || v.lng !== s.base.lng || v.label !== s.base.label; };
    const isNum = function (x) { return x !== '' && isFinite(Number(x)); };
    // Marks what cannot be saved, and says so under the field; true when everything that changed is fine to save.
    const validate = function (s, v, quiet) {
      let ok = true;
      const bad = function (el, err, msg) { ok = false; if (!quiet) { el.classList.add('bad'); err.textContent = msg; } };
      if (!quiet) { f.web.classList.remove('bad'); webErr.textContent = ''; f.lat.classList.remove('bad'); f.lng.classList.remove('bad'); locErr.textContent = ''; }
      if (textDirty(s, v) && v.web !== '' && !/^https?:\/\/\S+$/i.test(v.web)) bad(f.web, webErr, 'The link needs to start with http:// or https://');
      if (locDirty(s, v) && (v.lat !== '' || v.lng !== '')) {
        if (!(isNum(v.lat) && isNum(v.lng) && Math.abs(Number(v.lat)) <= 90 && Math.abs(Number(v.lng)) <= 180)) { bad(f.lat, locErr, 'That is not a valid latitude and longitude.'); if (!quiet) f.lng.classList.add('bad'); }
      }
      return ok;
    };
    const paintPills = function () {
      if (!st) return;
      const set = function (el, on) { el.setAttribute('aria-pressed', on ? 'true' : 'false'); };
      const k = lockKind(st.file);
      const addr = k === 'aon' || k === 'aoff';
      set(pills.priv, k === 'own' || k === 'aoff'); set(pills.loc, st.cfg.locationShown); set(pills.cover, !!ownerState.cover[st.file]);
      pills.priv.classList.toggle('is-folder', k === 'inh');
      pills.priv.innerHTML = '<svg class="ico" aria-hidden="true"><use href="#i-' + (k === 'own' || k === 'inh' || k === 'aoff' ? 'lock' : 'unlock') + '"></use></svg> ' + (addr ? (k === 'aoff' ? 'Link off' : 'Link on') : (k === 'inh' ? 'Folder' : (k === 'pub' ? 'Public' : 'Private')));
      let note = '';
      if (k === 'aon') note = 'Anyone with this photo\u2019s link can open it. Tap to switch the link off.';
      else if (k === 'aoff') note = 'This photo\u2019s link is switched off. Tap to switch it on.';
      else if (k === 'inh') note = 'Private because its folder is. Tap to keep it private on its own.';
      else if (k === 'own' && ownerState.inh[st.file]) note = 'Kept private on its own. Tap to make it public.';
      else if (k === 'pub') note = 'Public, though its folder is private. Tap to follow the folder again.';
      else if (st.cfg.locationsOff) note = 'Locations are switched off for the whole gallery (Settings), so none show yet.';
      pills.priv.title = addr ? (k === 'aoff' ? 'Switch this photo\u2019s link on' : 'Switch this photo\u2019s link off') : 'Hidden from visitors unless they have the private-view password';
      noteEl.textContent = note; noteEl.hidden = note === '';
    };
    const paintLoc = function () {
      if (!st) return;
      const v = values();
      const place = v.lat !== '' && v.lng !== '';
      locSum.textContent = place ? v.lat + ', ' + v.lng + (v.label ? ' · ' + v.label : '') : 'No location';
      autoHint.hidden = !st.auto;
      if (st.auto) locSum.textContent = 'From the photo’s file';
    };
    const photoBadges = function (file, v) {
      const place = LOCON && v.lat !== '' && v.lng !== '';
      document.querySelectorAll('.card[data-file="' + cssEscape(file) + '"]').forEach(function (card) {
        const box = card.querySelector('.card-badges'); if (!box) return;
        box.querySelectorAll('.caption-badge, .location-badge, .web-badge').forEach(function (b) { b.remove(); });
        const made = [], svg = function (n) { return '<svg class="ico" aria-hidden="true"><use href="#i-' + n + '"></use></svg>'; };
        if (v.caption) {
          const s = document.createElement('span'); s.className = 'item-badge caption-badge'; s.title = v.caption; s.setAttribute('role', 'button'); s.tabIndex = 0; s.innerHTML = svg('caption');
          s.addEventListener('click', function (e) { toggleCaptionPopover(s, e); });
          s.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') toggleCaptionPopover(s, e); });
          made.push(s);
        }
        if (place) {
          const a = document.createElement('a'); a.className = 'item-badge location-badge'; a.target = '_blank'; a.rel = 'noopener noreferrer'; a.innerHTML = svg('pin');
          a.href = 'https://www.openstreetmap.org/?mlat=' + encodeURIComponent(v.lat) + '&mlon=' + encodeURIComponent(v.lng) + '#map=16/' + encodeURIComponent(v.lat) + '/' + encodeURIComponent(v.lng);
          a.title = v.label || (v.lat + ', ' + v.lng); a.addEventListener('click', function (e) { e.stopPropagation(); });
          made.push(a);
        }
        if (v.web) {
          const a = document.createElement('a'); a.className = 'item-badge web-badge'; a.target = '_blank'; a.rel = 'noopener noreferrer'; a.innerHTML = svg('globe');
          a.href = v.web; a.title = 'Information link: ' + v.web; a.addEventListener('click', function (e) { e.stopPropagation(); });
          made.push(a);
        }
        made.reverse().forEach(function (el) { box.insertBefore(el, box.firstChild); });
      });
    };
    // What the page itself shows for this photo (its tile badges, the viewer's caption line, link button and little map) follows a save.
    const applyLive = function (s, v) {
      const i = files.indexOf(s.file); if (i < 0) return;
      captions[i] = v.caption || null; webLinks[i] = v.web || null;
      if (MAPON) locations[i] = (LOCON && v.lat !== '' && v.lng !== '') ? [parseFloat(v.lat), parseFloat(v.lng)] : null;
      photoBadges(s.file, v);
      if (i === current) { paintCaption(); updateLightboxExtras(); }
    };
    // Saves what changed on the photo that is open. quiet: a pause in typing, which never nags about a half-typed link. Resolves true
    // when nothing is left unsaved (or nothing was wrong), false when something could not be saved.
    const flush = function (quiet) {
      const s = st; if (!s) return Promise.resolve(true);
      clearTimeout(s.timer);
      const v = values();
      const run = function () {
        const td = textDirty(s, v), ld = locDirty(s, v);
        if (!td && !ld) return true;
        if (s === st && !validate(s, v, !!quiet)) { if (!quiet) setStatus('err', 'Not saved yet'); return !!quiet; }
        if (s === st) setStatus('busy', 'Saving…');
        return csrf().then(function () {
          return post(s.file, { action: 'save_item', file: bareOf(s.file), loaded_hash: s.hash, caption: v.caption, web: v.web, lat: v.lat, lng: v.lng, loc_label: v.label, keep_loc: ld ? '0' : '1' });
        }).then(function (j) {
          if (!j.ok) { if (s === st) setStatus('err', j.stale ? 'Changed somewhere else: reload this page' : (j.text || 'Not saved')); return false; }
          s.hash = j.hash;
          s.base.caption = v.caption; s.base.web = v.web;
          if (ld) { s.base.lat = v.lat; s.base.lng = v.lng; s.base.label = v.label; s.auto = false; }
          applyLive(s, { caption: v.caption, web: v.web, lat: s.auto ? '' : s.base.lat, lng: s.auto ? '' : s.base.lng, label: s.base.label });
          if (s === st) { paintLoc(); setStatus('ok', 'Saved'); }
          return true;
        }).catch(function (err) { if (s === st) problem(err); return false; });
      };
      s.chain = s.chain.then(run, run);
      return s.chain;
    };
    const focusBad = function () { const b = ed.querySelector('input.bad'); if (b) { if (b === f.lat || b === f.lng) ed.classList.add('loc-open'); b.focus(); } };
    const beforeLeave = function () {
      if (!isOpen) return Promise.resolve(true);
      return flush(false).then(function (ok) { if (!ok) focusBad(); return ok; });
    };
    // Where the editor sits: a sheet under the photo on a phone, a panel beside it otherwise. The photo takes what is left, and
    // follows the part of the screen that is really visible (the keyboard takes the lower part of it on a phone).
    const layout = function () {
      if (!isOpen) return;
      const sheet = sheetMq.matches, vv = window.visualViewport, st2 = lightbox.style;
      lightbox.classList.toggle('lb-sheet', sheet); lightbox.classList.toggle('lb-side', !sheet);
      if (sheet && vv) { st2.setProperty('--lbh', vv.height + 'px'); st2.height = vv.height + 'px'; st2.top = vv.offsetTop + 'px'; st2.bottom = 'auto'; }
      else { st2.removeProperty('--lbh'); st2.removeProperty('height'); st2.removeProperty('top'); st2.removeProperty('bottom'); }
      st2.setProperty('--sheet-h', (sheet ? ed.offsetHeight : 0) + 'px');
      fitPhoto(sheet);
      if (typeof placeMiniMap === 'function') placeMiniMap();
    };
    // Under the sheet the photo is given its exact size (width and height together, from its own shape), rather than leaving the
    // browser to shrink it to a height limit: Safari keeps the width when it clamps the height, which squashes the picture.
    const photoEls = [lightboxImg, lightboxVideo];
    const clearPhoto = function () { photoEls.forEach(function (el) { ['width', 'height', 'maxWidth', 'maxHeight'].forEach(function (p) { el.style.removeProperty(p === 'maxWidth' ? 'max-width' : (p === 'maxHeight' ? 'max-height' : p)); }); }); };
    const fitPhoto = function (sheet) {
      clearPhoto();
      if (!sheet) return;
      const video = isVideoFile(files[current]), el = video ? lightboxVideo : lightboxImg;
      const nw = video ? el.videoWidth : el.naturalWidth, nh = video ? el.videoHeight : el.naturalHeight;
      if (!nw || !nh) return;   // (not loaded yet: this runs again when it is)
      const cs = getComputedStyle(lightbox), row = lightbox.querySelector('.caption-row'), rowFixed = row && getComputedStyle(row).position === 'fixed';
      const availW = lightbox.clientWidth * 0.9;
      const availH = lightbox.clientHeight - parseFloat(cs.paddingTop) - (parseFloat(lightbox.style.getPropertyValue('--sheet-h')) || 0) - (rowFixed || !row ? 8 : row.offsetHeight + 24);
      if (availW <= 0 || availH <= 0) return;
      let w = Math.min(nw, availW), h = w * nh / nw;
      if (h > availH) { h = availH; w = h * nw / nh; }
      el.style.width = Math.round(w) + 'px'; el.style.height = Math.round(h) + 'px'; el.style.maxWidth = 'none'; el.style.maxHeight = 'none';
    };
    const setControls = function (enabled) {
      [].forEach.call(ed.querySelectorAll('input, .lbe-pill'), function (el) { el.disabled = !enabled; });
    };
    const load = function () {
      const file = files[current], my = ++seq;
      st = null; setControls(false); clearPhoto();
      nameEl.textContent = bareOf(file);
      setStatus('busy', 'Loading…');
      [f.web, f.lat, f.lng].forEach(function (el) { el.classList.remove('bad'); }); webErr.textContent = ''; locErr.textContent = '';
      fetch('?admin&item=' + encodeURIComponent(file), { credentials: 'same-origin', cache: 'no-store' }).then(jsonOf).then(function (j) {
        if (my !== seq || !isOpen) return;
        if (!j.ok) throw new Error(j.text || 'Could not load this photo');
        const auto = !!(j.auto && j.lat === '' && j.lng === ''), shown = auto ? j.auto : j;
        f.caption.value = j.caption; f.web.value = j.web; f.lat.value = shown.lat; f.lng.value = shown.lng; f.label.value = j.label;
        st = { file: file, hash: j.hash, auto: auto, cfg: { private: !!j.private, own: j.own || '', locationShown: !!j.locationShown, folderPrivate: !!j.folderPrivate, locationsOff: !!j.locationsOff },
               base: { caption: j.caption, web: j.web, lat: shown.lat, lng: shown.lng, label: j.label }, timer: 0, chain: Promise.resolve(true) };
        ownerState.own[file] = st.cfg.own; ownerState.inh[file] = st.cfg.folderPrivate; ownerState.priv[file] = st.cfg.private; if (j.cover) ownerState.cover[file] = true;
        ownerPaint(); setControls(true); paintPills(); paintLoc(); setStatus('', '');
      }).catch(function (err) { if (my === seq) problem(err); });
    };
    const open = function () {
      if (isOpen) return;
      isOpen = true; ed.hidden = false;
      if (zoomMode !== 'fit') setZoom('fit');
      lightbox.classList.add('editing');
      ed.classList.toggle('loc-open', !sheetMq.matches);
      locRow.setAttribute('aria-expanded', ed.classList.contains('loc-open') ? 'true' : 'false');
      if (toolBtn) toolBtn.classList.add('on');
      layout(); load(); mapEditStart();
    };
    const close = function () {
      if (!isOpen) return;
      flush(false);
      isOpen = false; seq++; ed.hidden = true;
      if (ed.contains(document.activeElement)) document.activeElement.blur();
      ed.classList.remove('typing', 'kb');
      lightbox.classList.remove('editing', 'lb-sheet', 'lb-side');
      ['--lbh', '--sheet-h', 'height', 'top', 'bottom'].forEach(function (p) { lightbox.style.removeProperty(p); });
      clearPhoto();
      if (toolBtn) toolBtn.classList.remove('on');
      mapEditEnd();
      if (typeof placeMiniMap === 'function') placeMiniMap();
    };
    // The text fields
    ['caption', 'web', 'lat', 'lng', 'label'].forEach(function (k) {
      f[k].addEventListener('input', function () {
        if (!st) return;
        const s = st, v = values();
        paintLoc();
        if (k === 'web') { f.web.classList.remove('bad'); webErr.textContent = ''; }
        if (k === 'lat' || k === 'lng') { f.lat.classList.remove('bad'); f.lng.classList.remove('bad'); locErr.textContent = ''; }
        clearTimeout(s.timer);
        if (textDirty(s, v) || locDirty(s, v)) { setStatus('', 'Unsaved changes'); s.timer = setTimeout(function () { flush(true); }, 1000); }
        else setStatus('', '');
      });
      f[k].addEventListener('blur', function () { flush(false); });
      f[k].addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); f[k].blur(); } });
    });
    locRow.addEventListener('click', function () {
      const openNow = ed.classList.toggle('loc-open');
      locRow.setAttribute('aria-expanded', openNow ? 'true' : 'false');
      layout();
    });
    // The three switches save as they are pressed.
    const flag = function (which, flagName) {
      pills[which].addEventListener('click', function () {
        if (!st) return;
        const s = st, on = !(flagName === 'private' ? s.cfg.private : s.cfg.locationShown), next = flagName === 'private' ? nextOwn(s.file) : '';
        setStatus('busy', 'Saving…');
        const fields = { action: 'set_item_flag', file: bareOf(s.file), flag: flagName };
        if (flagName === 'private') fields.state = next; else fields.on = on ? '1' : '0';
        post(s.file, fields).then(function (j) {
          if (!j.ok) throw new Error(j.text || 'Not saved');
          if (flagName === 'private') { setOwn(s.file, next); s.cfg.private = ownerState.priv[s.file]; s.cfg.own = ownerState.own[s.file]; ownerPaint(); } else s.cfg.locationShown = on;
          if (s === st) { paintPills(); setStatus('ok', 'Saved'); }
        }).catch(function (err) { if (s === st) problem(err); });
      });
    };
    flag('priv', 'private'); flag('loc', 'location');
    pills.cover.addEventListener('click', function () {
      if (!st) return;
      const s = st;
      if (ownerState.cover[s.file]) { setStatus('ok', 'Already this folder’s cover'); return; }
      setStatus('busy', 'Saving…');
      post(s.file, { action: 'set_cover', file: bareOf(s.file) }).then(function (j) {
        if (!j.ok) throw new Error(j.text || 'Not saved');
        const folder = folderOf(s.file);
        files.forEach(function (x) { if (folderOf(x) === folder) ownerState.cover[x] = false; });
        ownerState.cover[s.file] = true; ownerPaint();
        if (s === st) { paintPills(); setStatus('ok', 'Now the folder’s cover'); }
      }).catch(function (err) { if (s === st) problem(err); });
    });
    // Moving on, finishing
    $('lbe-prev').addEventListener('click', function () { navigate(-1); });
    $('lbe-next').addEventListener('click', function () { navigate(1); });
    $('lbe-done').addEventListener('click', function () { beforeLeave().then(function (ok) { if (ok) close(); }); });
    // These buttons must not take the focus from a field (the sheet would change shape under the finger before the press lands).
    ['pointerdown', 'mousedown'].forEach(function (t) { ed.querySelector('.lbe-foot').addEventListener(t, function (e) { if (e.target.closest('button')) e.preventDefault(); }); });
    ['pointerdown', 'mousedown'].forEach(function (t) { hideKb.addEventListener(t, function (e) { e.preventDefault(); if (ed.contains(document.activeElement)) document.activeElement.blur(); }); });
    hideKb.addEventListener('click', function () { if (ed.contains(document.activeElement)) document.activeElement.blur(); });
    // A phone's keyboard: while a text field has it, the sheet shows just the fields (the photo keeps as much room as possible).
    // (Not at the instant of the tap: the click that follows it must still land on the field, and the sheet changing shape under it would
    // send that click to the photo behind, which puts the keyboard straight away again.)
    let focusAt = 0, kbTimer = 0;
    ed.addEventListener('focusin', function (e) {
      if (!sheetMq.matches || !e.target.matches || !e.target.matches('input')) return;
      focusAt = Date.now();
      const field = e.target;
      clearTimeout(kbTimer);
      kbTimer = setTimeout(function () {
        if (document.activeElement !== field) return;
        ed.classList.add('kb'); ed.classList.toggle('typing', field === f.caption || field === f.web); layout();
      }, 320);
    });
    ed.addEventListener('focusout', function () {
      setTimeout(function () { const a = document.activeElement; if (!(a && ed.contains(a) && a.matches && a.matches('input'))) { ed.classList.remove('kb', 'typing'); layout(); } }, 250);
    });
    // What happens on the touch screen inside the editor stays there: it must not swipe the viewer to another photo or close it.
    ['touchstart', 'touchend'].forEach(function (t) { ed.addEventListener(t, function (e) { e.stopPropagation(); }, { passive: true }); });
    lightboxImg.addEventListener('load', layout); lightboxVideo.addEventListener('loadedmetadata', layout);
    // Pulling the sheet down by its handle (or its title row) closes it, like Done: it follows the finger, and past a short distance (or
    // a quick flick) it saves and goes; otherwise it springs back.
    let pull = null;
    const settle = function () { ed.style.transition = ''; ed.style.transform = ''; };
    const pullStart = function (e) {
      if (!sheetMq.matches || !isOpen || (e.pointerType === 'mouse' && e.button !== 0) || (e.target.closest && e.target.closest('button'))) return;
      pull = { id: e.pointerId, y: e.clientY, t: Date.now(), dy: 0 };
      try { ed.setPointerCapture(e.pointerId); } catch (x) {}
      ed.style.transition = 'none';
    };
    [$('lbe-grip'), ed.querySelector('.lbe-head')].forEach(function (el) { el.addEventListener('pointerdown', pullStart); });
    ed.addEventListener('pointermove', function (e) {
      if (!pull || e.pointerId !== pull.id) return;
      pull.dy = Math.max(0, e.clientY - pull.y);
      ed.style.transform = 'translateY(' + pull.dy + 'px)';
    });
    const pullEnd = function (e) {
      if (!pull || e.pointerId !== pull.id) return;
      const p = pull; pull = null;
      const quick = p.dy / Math.max(1, Date.now() - p.t) > 0.6;
      ed.style.transition = 'transform .18s ease-out';
      if (e.type === 'pointerup' && (p.dy > 90 || (p.dy > 40 && quick))) {
        ed.style.transform = 'translateY(' + ed.offsetHeight + 'px)';
        beforeLeave().then(function (ok) { if (ok) { close(); settle(); } else settle(); });
      } else ed.style.transform = '';
      setTimeout(function () { if (!pull && isOpen) ed.style.transition = ''; }, 220);
    };
    ed.addEventListener('pointerup', pullEnd); ed.addEventListener('pointercancel', pullEnd);
    if (window.ResizeObserver) new ResizeObserver(layout).observe(ed);
    if (window.visualViewport) { window.visualViewport.addEventListener('resize', layout); window.visualViewport.addEventListener('scroll', layout); }
    window.addEventListener('resize', layout);
    if (toolBtn) toolBtn.addEventListener('click', function () { if (isOpen) beforeLeave().then(function (ok) { if (ok) close(); }); else open(); });
    return { open: open, close: close, load: load, beforeLeave: beforeLeave, isOpen: function () { return isOpen; }, justFocused: function () { return Date.now() - focusAt < 900; }, blur: function () { if (ed.contains(document.activeElement)) document.activeElement.blur(); } };
  })();
  // The pencil on a tile opens the viewer on that photo with the editor up.
  document.addEventListener('click', function (e) {
    const b = e.target.closest ? e.target.closest('.edit-toggle') : null;
    if (!b || !lbEdit) return;
    e.preventDefault(); e.stopPropagation();
    const i = files.indexOf(b.getAttribute('data-file'));
    if (i < 0) return;
    openLightbox(i);
    lbEdit.open();
  }, true);
  // The banner on a private folder's page, and the red Private pill on a private folder's card: pressing either asks (with the counts)
  // whether to make the folder public; only a yes does.
  (function () {
    const modal = document.getElementById('fp-modal');
    if (!modal) return;
    const $ = function (id) { return document.getElementById(id); }, noun = modal.getAttribute('data-noun') || 'photo';
    const triggers = [].slice.call(document.querySelectorAll('#folder-priv-banner, button.folder-priv.own'));
    if (!triggers.length) return;
    const many = function (k) { return k + ' ' + noun + (k === 1 ? '' : 's'); };
    const say = function (text) {
      const t = $('owner-toast'); if (!t) return;
      t.textContent = text; t.hidden = false; clearTimeout(say.timer); say.timer = setTimeout(function () { t.hidden = true; }, 3500);
    };
    const json = function (r) { if ((r.headers.get('content-type') || '').indexOf('json') < 0) throw new Error('signed out'); return r.json(); };
    let folder = '', from = null;
    const closeIt = function () { modal.hidden = true; if (from) from.focus(); };
    const ask = function (el) {
      from = el; folder = el.getAttribute('data-folder') || '';
      fetch('?admin&folder=' + encodeURIComponent(folder) + '&privcounts=1', { credentials: 'same-origin', cache: 'no-store' }).then(json).then(function (j) {
        const c = j.counts || { stay: 0, open: 0 };
        $('fp-stay').textContent = many(c.stay); $('fp-open').textContent = many(c.open);
        $('fp-stay-v').textContent = c.stay === 1 ? 'stays' : 'stay'; $('fp-open-v').textContent = c.open === 1 ? 'becomes' : 'become';
        const name = $('fp-folder'); if (name) { name.textContent = el.id === 'folder-priv-banner' ? '' : (el.closest('.folder-cover') && el.closest('.folder-cover').querySelector('.folder-cover-label') ? el.closest('.folder-cover').querySelector('.folder-cover-label').textContent : folder); name.hidden = !name.textContent; }
        modal.hidden = false; $('fp-cancel').focus();
      }).catch(function () { say('Your admin session ended: sign in again'); });
    };
    triggers.forEach(function (el) {
      el.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); ask(el); });
      el.addEventListener('keydown', function (e) { if (el.tagName !== 'BUTTON' && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); ask(el); } });
    });
    $('fp-cancel').addEventListener('click', closeIt);
    modal.addEventListener('click', function (e) { if (e.target === modal) closeIt(); });
    modal.addEventListener('keydown', function (e) { if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closeIt(); } });
    $('fp-ok').addEventListener('click', function () {
      modal.hidden = true;
      fetch('?admin&csrf=1', { credentials: 'same-origin', cache: 'no-store' }).then(json).then(function (j) {
        const body = new URLSearchParams(); body.set('csrf_token', j.token); body.set('action', 'toggle_folder_restriction');
        return fetch('?admin&folder=' + encodeURIComponent(folder), { method: 'POST', credentials: 'same-origin', body: body.toString(), headers: { 'X-Requested-With': 'gallery-fetch', 'Content-Type': 'application/x-www-form-urlencoded' } });
      }).then(json).then(function (j) {
        if (!j.ok) throw new Error(j.text || 'not saved');
        say('This folder is public now'); setTimeout(function () { location.reload(); }, 500);
      }).catch(function (err) { say(err && err.message === 'signed out' ? 'Your admin session ended: sign in again' : 'Not saved: ' + (err && err.message ? err.message : 'try again')); if (from) from.focus(); });
    });
  })();
