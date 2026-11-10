    const arrRow = bar.querySelector('.select-arr');
    const loose = function () { return gal ? [].slice.call(gal.querySelectorAll(':scope > .card[data-file]')) : []; };
    const canArrange = !!arrRow && loose().length >= 2;   // photos shown one by one here, not only inside group boxes
    if (arrRow && !canArrange) arrRow.hidden = true;
    // Collections: the page may be one (its Remove), and the owner may add the picked photos to one (a short list over the bar, with a way to make a new one).
    const collBox = document.getElementById('select-coll'), collPage = collBox ? collBox.getAttribute('data-collection-page') : '';
    let collList = []; try { collList = JSON.parse((collBox && collBox.getAttribute('data-list')) || '[]') || []; } catch (e) { collList = []; }
    const stackOf = {};
    stacks.forEach(function (list, i) { list.forEach(function (f) { stackOf[f] = i; }); });
    let on = false, press = null, drag = null, swallow = false, pointer = { x: 0, y: 0 }, scrollTimer = 0, busy = false;
    const picked = [];   // the picked files, in the order they were picked
    const cards = function () { return [].slice.call(document.querySelectorAll('.card[data-file]')); };
    const fileOf = function (c) { return c.getAttribute('data-file'); };
    const bare = function (f) { return f.slice(f.lastIndexOf('/') + 1); };
    const say = function (text) {
      const t = document.getElementById('owner-toast'); if (!t) return;
      t.textContent = text; t.hidden = false; clearTimeout(say.timer); say.timer = setTimeout(function () { t.hidden = true; }, 3500);
    };
    const count = document.getElementById('select-count');
    const paint = function () {
      cards().forEach(function (c) {
        const f = fileOf(c), i = stackOf[f];
        c.classList.toggle('sel', picked.indexOf(f) >= 0);
        c.classList.toggle('is-stacked', canArrange && i !== undefined);
        if (canArrange && i !== undefined) c.style.setProperty('--stack-hue', String((i * 67 + 215) % 360)); else c.style.removeProperty('--stack-hue');
      });
      const n = picked.length;
      count.textContent = n ? n + ' selected' : count.getAttribute('data-empty');
      bar.querySelector('[data-select=clear]').disabled = !n;
      bar.querySelector('[data-select=private]').disabled = !n || busy;
      bar.querySelector('[data-select=public]').disabled = !n || busy;
      bar.querySelector('[data-select=inherit]').disabled = !n || busy;
      // Only what would change something is offered: Make private when a picked photo is not private on its own, Make public when one is
      // (or, in a private folder, is not already public on its own), Folder when one has a setting of its own to take back.
      // With nothing picked the buttons wait, all shown, as before.
      const ownOf = function (f) { return ownerState.own[f] || ''; };
      const canPrivate = !n || picked.some(function (f) { return ownOf(f) !== 'private'; });
      const canPublic = !n || picked.some(function (f) { return ownerState.inh[f] ? ownOf(f) !== 'public' : ownOf(f) === 'private'; });
      const canFolder = !n || picked.some(function (f) { return ownOf(f) !== ''; });
      bar.querySelector('[data-select=private]').hidden = !canPrivate || !!collPage;   // (on a collection's page the photos are from several folders, each with its own marks: only Share and Remove)
      bar.querySelector('[data-select=public]').hidden = !canPublic || !!collPage;
      const collBtn = bar.querySelector('[data-select=coll]'); if (collBtn) collBtn.disabled = !n || busy;
      const collRm = bar.querySelector('[data-select=collrm]'); if (collRm) collRm.disabled = !n || busy;
      const shareBtn = bar.querySelector('[data-select=share]'); if (shareBtn) shareBtn.disabled = !n || busy;
      // In a private folder (the photos picked, or else the ones on the page) the bar offers the three states of the lock.
      const first = cards()[0], ctx = picked.length ? picked[0] : (first ? fileOf(first) : null), inPriv = !!(ctx && ownerState.inh[ctx]);
      bar.classList.toggle('in-private', inPriv);
      bar.querySelector('[data-select=inherit]').hidden = !inPriv || !canFolder || !!collPage;
      // In a Not listed folder there is no public or private: the bar says Link off / Link on (on = back to following the folder).
      const inAddr = !!(ctx && ownerState.unl && ownerState.unl[ctx]);
      bar.classList.toggle('in-addr', inAddr);
      const sbn = bar.querySelector('.sb-private .sb-n'), sbp = bar.querySelector('.sb-private .sb-p'), sbf = bar.querySelector('.sb-folder .sb-lab');
      if (sbn) sbn.textContent = inAddr ? 'Link off' : 'Make private'; if (sbp) sbp.textContent = inAddr ? 'Off' : 'Private'; if (sbf) sbf.textContent = inAddr ? 'Link on' : 'Folder';
      if (inAddr) bar.querySelector('[data-select=public]').hidden = true;
      if (canArrange) {
        bar.querySelector('[data-select=release]').disabled = !picked.some(function (f) { return stackOf[f] !== undefined; });
        bar.querySelector('[data-select=reset]').disabled = !stacks.length;
      }
    };
    const setOn = function (v) {
      on = v; document.body.classList.toggle('selecting', v); document.body.classList.toggle('can-arrange', v && canArrange); bar.hidden = !v;
      btn.setAttribute('aria-pressed', v ? 'true' : 'false');
      if (!v) picked.length = 0;
      paint();
    };
    const toggle = function (f) {
      const i = picked.indexOf(f);
      if (i >= 0) picked.splice(i, 1);
      else {
        if (picked.length && folderOf(picked[0]) !== folderOf(f)) { picked.length = 0; say('A selection stays within one folder'); }
        picked.push(f);
      }
      paint();
    };
    btn.addEventListener('click', function () { setOn(!on); });
    // The admin's own save (a token is fetched first).
    const request = function (folder, fields) {
      return fetch('?admin&csrf=1', { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { if ((r.headers.get('content-type') || '').indexOf('json') < 0) throw new Error('signed out'); return r.json(); })
        .then(function (j) {
          const body = new URLSearchParams(); body.set('csrf_token', j.token);
          Object.keys(fields).forEach(function (k) { body.set(k, fields[k]); });
          return fetch('?admin' + (folder ? '&folder=' + encodeURIComponent(folder) : ''), { method: 'POST', credentials: 'same-origin', body: body.toString(), headers: { 'X-Requested-With': 'gallery-fetch', 'Content-Type': 'application/x-www-form-urlencoded' } });
        })
        .then(function (r) { if ((r.headers.get('content-type') || '').indexOf('json') < 0) throw new Error('signed out'); return r.json(); })
        .then(function (j) { if (!j.ok) throw new Error(j.text || 'not saved'); return j; });
    };
    const fail = function (err) { say(err && err.message === 'signed out' ? 'Your admin session ended: sign in again' : 'Not saved: ' + (err && err.message ? err.message : 'try again')); };
    // A placement is saved, then the page reloads in the same place with the same photos picked.
    const send = function (folder, fields, keep) {
      request(folder, Object.assign({ action: 'arrange_items' }, fields)).then(function () {
        try { sessionStorage.setItem('gallery_select', JSON.stringify({ y: window.scrollY, keep: keep || [] })); } catch (e) {}
        location.reload();
      }).catch(fail);
    };
    const bulk = function (state) {
      if (!picked.length || busy) return;
      const sel = picked.slice(), folder = folderOf(sel[0]);
      busy = true; paint();
      request(folder, { action: 'bulk_item_flag', flag: 'private', state: state, files: JSON.stringify(sel.map(bare)) }).then(function (j) {
        sel.forEach(function (f) { setOwn(f, state); });
        ownerPaint();
        say(j.text || (sel.length + (state === 'private' ? ' now private' : (state === 'public' ? ' now public' : ' follow their folder'))));
      }).catch(fail).then(function () { busy = false; paint(); });
    };
    const selectAll = function () {
      const all = cards();
      if (!all.length) return;
      const folder = picked.length ? folderOf(picked[0]) : (all.some(function (c) { return folderOf(fileOf(c)) === pageFolder; }) ? pageFolder : folderOf(fileOf(all[0])));
      all.forEach(function (c) { const f = fileOf(c); if (folderOf(f) === folder && picked.indexOf(f) < 0) picked.push(f); });
      paint();
    };
    bar.addEventListener('click', function (e) {
      const b = e.target.closest('[data-select]'); if (!b || b.disabled) return;
      const what = b.getAttribute('data-select');
      if (what === 'done') setOn(false);
      else if (what === 'all') selectAll();
      else if (what === 'clear') { picked.length = 0; paint(); }
      else if (what === 'private') bulk('private');
      else if (what === 'public') bulk('public');
      else if (what === 'inherit') bulk('inherit');
      else if (what === 'share') { if (picked.length && window.GalleryShare) { const pf = picked.slice(); window.GalleryShare.quick({ kind: 'photos', files: pf, allPrivate: !!ownerState && pf.every(function (f) { return !!ownerState.priv[f]; }), anyPrivate: !ownerState || pf.some(function (f) { return !!ownerState.priv[f]; }) }); } }
      else if (what === 'coll') openCollPicker();
      else if (what === 'collrm') {
        if (!picked.length || busy) return;
        const sel = picked.slice(); busy = true; paint();
        request(pageFolder, { action: 'collection_member_remove_files', id: collPage, files: JSON.stringify(sel) }).then(function (j) {
          say(j.text || 'Taken out of the collection.');
          if (j.removed) { try { sessionStorage.setItem('gallery_select', JSON.stringify({ y: window.scrollY, keep: [] })); } catch (er) {} location.reload(); }
        }).catch(fail).then(function () { busy = false; paint(); });
      } else if (what === 'release') {
        const sel = picked.filter(function (f) { return stackOf[f] !== undefined; });
        send(folderOf(sel[0]), { mode: 'release', files: JSON.stringify(sel.map(bare)) }, picked.slice());
      } else if (what === 'reset') {
        const folder = picked.length ? folderOf(picked[0]) : pageFolder;
        if (confirm('Put every photo of this folder back in its sorted place?')) send(folder, { mode: 'reset' }, []);
      }
    });
    function openCollPicker() {
      if (!picked.length || busy) return;
      const old = document.getElementById('coll-pop'); if (old) { old.remove(); return; }
      const sel = picked.slice(), folder = folderOf(sel[0]), pop = document.createElement('div');
      const esc = function (t) { return String(t).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
      pop.id = 'coll-pop'; pop.className = 'coll-pop'; pop.setAttribute('role', 'dialog');
      pop.innerHTML = '<h4>Add ' + sel.length + (sel.length === 1 ? ' photo' : ' photos') + ' to a collection</h4>'
        + collList.map(function (c) { return '<button type="button" class="coll-opt" data-id="' + esc(c.id) + '"><span class="coll">' + esc(c.title) + '</span>' + (c.private ? ' <span class="cm-pill priv">Private</span>' : '') + '<small>' + esc(c.where) + '</small></button>'; }).join('')
        + '<div class="coll-new"><button type="button" class="coll-opt coll-newbtn">New collection\u2026<small>Asks for a name, then adds these photos</small></button><form class="coll-newform" hidden><input type="text" maxlength="80" placeholder="Name of the new collection" aria-label="Name of the new collection"><button type="submit" class="primary">Make and add</button></form></div>'
        + '<button type="button" class="coll-cancel">Cancel</button>';
      document.body.appendChild(pop);
      const done = function (text) { pop.remove(); say(text); };
      const add = function (id) { busy = true; paint(); return request(folder, { action: 'collection_member_add_files', id: id, folder: folder, files: JSON.stringify(sel.map(bare)) }).then(function (j) { done(j.text || 'Added.'); }).catch(function (err) { pop.remove(); fail(err); }).then(function () { busy = false; paint(); }); };
      pop.addEventListener('click', function (e) {
        const b = e.target.closest('button'); if (!b) return;
        if (b.classList.contains('coll-cancel')) { pop.remove(); return; }
        if (b.classList.contains('coll-newbtn')) { pop.querySelector('.coll-newform').hidden = false; b.hidden = true; pop.querySelector('.coll-newform input').focus(); return; }
        if (b.hasAttribute('data-id')) add(b.getAttribute('data-id'));
      });
      pop.querySelector('.coll-newform').addEventListener('submit', function (e) {
        e.preventDefault();
        const name = pop.querySelector('.coll-newform input').value.trim(); if (!name) return;
        busy = true; paint();
        request('', { action: 'collection_create', parent: '', title: name }).then(function (j) {
          busy = false; if (!j.ok) { pop.remove(); say(j.text); return; }
          return add(j.id);
        }).catch(function (err) { busy = false; pop.remove(); fail(err); });
      });
    }
    // Tapping a tile picks it instead of opening it.
    document.addEventListener('click', function (e) {
      if (!on) return;
      const c = e.target.closest ? e.target.closest('.card[data-file]') : null;
      if (!c) return;
      e.preventDefault(); e.stopPropagation();
      if (swallow) return;
      toggle(fileOf(c));
    }, true);
    document.addEventListener('contextmenu', function (e) { if (on && e.target.closest && e.target.closest('.card[data-file]')) e.preventDefault(); });
    // Dragging (only where the photos are laid out one by one)
    const line = document.createElement('div'); line.className = 'arrange-line'; line.hidden = true; document.body.appendChild(line);
    const begin = function () {
      const c = press.card, f = fileOf(c);
      if (picked.indexOf(f) < 0) { picked.length = 0; picked.push(f); paint(); }
      const set = cards().filter(function (x) { return picked.indexOf(fileOf(x)) >= 0; });
      const ghost = document.createElement('div'); ghost.className = 'arrange-ghost';
      ghost.innerHTML = '<img alt="" src="' + c.querySelector('img').currentSrc.replace(/"/g, '') + '">' + (set.length > 1 ? '<b>' + set.length + '</b>' : '');
      document.body.appendChild(ghost);
      set.forEach(function (x) { x.classList.add('dragging'); });
      drag = { set: set, ghost: ghost, folder: folderOf(f), target: null, side: 'after' };
      swallow = true; clearTimeout(press.timer);
      place(); loop();
    };
    const place = function () {
      if (!drag) return;
      drag.ghost.style.transform = 'translate(' + (pointer.x + 12) + 'px,' + (pointer.y + 12) + 'px)';
      let best = null, bestD = 1e9;
      loose().forEach(function (c) {
        if (drag.set.indexOf(c) >= 0 || folderOf(fileOf(c)) !== drag.folder) return;
        const r = c.getBoundingClientRect();
        const dx = Math.max(r.left - pointer.x, 0, pointer.x - r.right), dy = Math.max(r.top - pointer.y, 0, pointer.y - r.bottom), d = Math.hypot(dx, dy);
        if (d < bestD) { bestD = d; best = c; }
      });
      if (!best || bestD > 140) { drag.target = null; line.hidden = true; return; }
      const r = best.getBoundingClientRect();
      drag.target = best; drag.side = pointer.x < r.left + r.width / 2 ? 'before' : 'after';
      line.style.left = (drag.side === 'before' ? r.left - 6 : r.right + 2) + 'px'; line.style.top = r.top + 'px'; line.style.height = r.height + 'px'; line.hidden = false;
    };
    const loop = function () {   // scroll while the pointer is near the top or bottom edge
      cancelAnimationFrame(scrollTimer);
      if (!drag) return;
      const h = window.innerHeight, top = 80, low = h - 190;   // (the bar sits at the bottom)
      const dy = pointer.y < top ? -(top - pointer.y) / 6 : (pointer.y > low ? (pointer.y - low) / 6 : 0);
      if (dy) { window.scrollBy(0, dy); place(); }
      scrollTimer = requestAnimationFrame(loop);
    };
    const finish = function (drop) {
      clearTimeout(press && press.timer);
      const d = drag; drag = null; press = null; cancelAnimationFrame(scrollTimer);
      if (!d) return;
      d.ghost.remove(); line.hidden = true;
      d.set.forEach(function (x) { x.classList.remove('dragging'); });
      setTimeout(function () { swallow = false; }, 0);
      if (drop && d.target) {
        const names = d.set.map(function (x) { return bare(fileOf(x)); });
        send(d.folder, { mode: 'place', files: JSON.stringify(names), target: bare(fileOf(d.target)), side: d.side }, d.set.map(fileOf));
      }
    };
    document.addEventListener('pointerdown', function (e) {
      if (!on || !canArrange || (e.pointerType === 'mouse' && e.button !== 0)) return;
      const c = e.target.closest('.card[data-file]'); if (!c || c.parentNode !== gal) return;
      pointer = { x: e.clientX, y: e.clientY };
      press = { card: c, x: e.clientX, y: e.clientY, id: e.pointerId, touch: e.pointerType !== 'mouse', timer: 0 };
      if (press.touch) press.timer = setTimeout(function () { if (press && !drag) begin(); }, 380);
    });
    document.addEventListener('pointermove', function (e) {
      pointer = { x: e.clientX, y: e.clientY };
      if (drag) { place(); return; }
      if (!press || e.pointerId !== press.id) return;
      if (Math.hypot(e.clientX - press.x, e.clientY - press.y) > (press.touch ? 10 : 6)) {
        if (press.touch) { clearTimeout(press.timer); press = null; } else begin();
      }
    });
    document.addEventListener('pointerup', function () { if (drag) finish(true); else { clearTimeout(press && press.timer); press = null; } });
    document.addEventListener('pointercancel', function () { finish(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && on) { if (drag) finish(false); else if (picked.length) { picked.length = 0; paint(); } } });
    // Holding a tile on a touch screen must not scroll the page once the drag has begun.
    document.addEventListener('touchmove', function (e) { if (drag) e.preventDefault(); }, { passive: false });
    let back = null;
    try { back = JSON.parse(sessionStorage.getItem('gallery_select') || 'null'); sessionStorage.removeItem('gallery_select'); } catch (e) {}
    if (back) {
      setOn(true);
      (back.keep || []).forEach(function (f) { if (cards().some(function (c) { return fileOf(c) === f; })) picked.push(f); });
      paint();
      window.scrollTo(0, back.y || 0);
    }
  })();
  // The owner's editor, in the viewer: caption, information link, place and the Private / Location / Cover switches of the photo
  // being looked at. Nothing needs a Save button: the switches save the moment they are pressed; the text saves when you leave a
  // field, a second after you stop typing, and when you move to another photo or close the editor. On a phone it is a sheet under
  // the photo (the photo stays in view above it, above the keyboard too); on a wide screen a panel beside the photo.
  lbEdit = (function () {
    const ed = document.getElementById('lb-editor');
    if (!ed || !ownerState) return null;
    const $ = function (id) { return document.getElementById(id); };
    const f = { caption: $('lbe-caption'), web: $('lbe-web'), lat: $('lbe-lat'), lng: $('lbe-lng'), label: $('lbe-label') };
    const pills = { priv: $('lbe-private'), loc: $('lbe-loc'), cover: $('lbe-cover') };
    const statusEl = $('lbe-status'), nameEl = $('lbe-name'), noteEl = $('lbe-note'), webErr = $('lbe-web-err'), locErr = $('lbe-loc-err');
    const autoHint = $('lbe-autohint'), locRow = $('lbe-locrow'), locSum = $('lbe-locsum'), hideKb = $('lbe-hidekb'), toolBtn = $('lightbox-edit');
    const sheetMq = window.matchMedia ? window.matchMedia('(max-width: 700px), (hover: none) and (pointer: coarse) and (orientation: portrait)') : { matches: false };
