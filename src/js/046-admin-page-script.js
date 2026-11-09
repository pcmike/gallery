  // ---- When the window comes back, what is shown may be out of date (a visitor came, a lockout happened, another browser changed something). Parts of the page
  // register here. Each is refreshed when the window becomes visible or gets focus again, and when its tab is opened, at most once every 15 seconds (api.min), and never
  // while the person is in the middle of something (a menu or dialog open): it waits and goes when that ends. A part keeps its own state across a refresh.
  window.__live = (function () {
    var parts = {}, last = {}, wait = {}, retryT = null, api = { min: 15000 };   // (min: the least time between two refreshes of one part; a test sets it to 0)
    function ready(p) { return !p.tab || document.querySelector('.tab-panel[data-tab="' + p.tab + '"].active') !== null; }
    function run(name, force) {
      var p = parts[name]; if (!p || document.hidden || !ready(p)) return;
      if (!force && Date.now() - (last[name] || 0) < api.min) return;
      if (document.querySelector('.admin-modal:not([hidden])') || (p.busy && p.busy())) { wait[name] = true; return; }
      last[name] = Date.now(); wait[name] = false;
      try { var r = p.refresh(); if (r && r.catch) r.catch(function () {}); } catch (e) {}
    }
    function all() { Object.keys(parts).forEach(function (n) { run(n); }); }
    function retry() { clearTimeout(retryT); retryT = setTimeout(function () { Object.keys(wait).forEach(function (n) { if (wait[n]) run(n, true); }); }, 700); }
    document.addEventListener('visibilitychange', function () { if (!document.hidden) all(); });
    window.addEventListener('focus', all);
    window.addEventListener('pageshow', function (e) { if (e.persisted) all(); });
    ['click', 'keyup', 'change'].forEach(function (ev) { document.addEventListener(ev, function () { if (Object.keys(wait).some(function (n) { return wait[n]; })) retry(); }, true); });
    api.register = function (name, part) { parts[name] = part; last[name] = Date.now(); };   // (it has just been drawn: the first refresh is not before api.min)
    api.shown = function (tab) { Object.keys(parts).forEach(function (n) { if (parts[n].tab === tab) run(n); }); };
    return api;
  })();
  // Something changed somewhere else (another browser saved a setting, a folder's captions or marks were edited, photos were added by a sync): the page shows a quiet bar with
  // Refresh instead of swapping what the person may be editing. The page is drawn with three short fingerprints (#live-fp); they are asked for again when the window comes back,
  // and taken again after this page's own saves (a save made here is not "somewhere else").
  (function () {
    var holder = document.getElementById('live-fp'); if (!holder || !window.__live) return;
    var base = {}, bar = null, rebaseT = null, folder = (new URLSearchParams(location.search)).get('folder');
    try { base = JSON.parse(holder.getAttribute('data-fp')) || {}; } catch (e) {}
    function url() { return '?admin&changed=1' + (folder ? '&folder=' + encodeURIComponent(folder) : ''); }
    function ask() { return fetch(url(), { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.ok ? r.json() : null; }).then(function (j) { return j && j.ok ? j : null; }); }
    function hide() { if (bar) { bar.remove(); bar = null; } }
    function show(texts, now) {
      hide();
      bar = document.createElement('div'); bar.className = 'live-note'; bar.setAttribute('role', 'status');
      var t = document.createElement('span'); t.textContent = texts.join(' ') + ' '; bar.appendChild(t);
      var go = document.createElement('button'); go.type = 'button'; go.className = 'secondary'; go.textContent = 'Refresh'; go.addEventListener('click', function () { location.reload(); }); bar.appendChild(go);
      var x = document.createElement('button'); x.type = 'button'; x.className = 'linklike'; x.textContent = 'Dismiss'; x.addEventListener('click', function () { base = now; hide(); }); bar.appendChild(x);
      var main = document.querySelector('.admin-main'); if (main) main.insertBefore(bar, main.firstChild); else document.body.insertBefore(bar, document.body.firstChild);
    }
    window.__live.register('changed', { refresh: function () {
      return ask().then(function (j) {
        if (!j) return;
        var said = [];
        if (base.settings !== undefined && j.settings !== base.settings) said.push('The settings were changed somewhere else.');
        if (base.record !== undefined && j.record !== base.record) said.push("This folder's names, captions or marks were changed somewhere else.");
        if (base.media !== undefined && j.media !== base.media) said.push('Photos in this folder were added, removed or renamed.');
        if (said.length) show(said, j); else hide();
      });
    } });
    // a save made from this page: the fingerprints are taken again, so the page does not announce its own change
    window.__live.rebase = function () {
      clearTimeout(rebaseT);
      rebaseT = setTimeout(function () { ask().then(function (j) { if (j) { base = j; hide(); } }); }, 900);
    };
    var plainFetch = window.fetch;
    if (plainFetch) window.fetch = function (u, o) {
      var p = plainFetch.apply(this, arguments);
      try { if (o && String(o.method).toUpperCase() === 'POST' && typeof u === 'string' && u.indexOf('?admin') === 0) p.then(function () { window.__live.rebase(); }, function () {}); } catch (e) {}
      return p;
    };
  })();
  // The numbers on the sidebar entries (and the phone Menu's) in the page itself. In the desktop shell the sidebar is the shell's own, which asks again by itself.
  if (window.parent === window) window.__live.register('counts', { refresh: function () {
    return fetch('?admin&pbcounts=1', { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.ok ? r.json() : null; }).then(function (d) {
      if (!d) return;
      var badge = function (o) { var s = document.createElement('span'); s.className = 'count' + (o.kind ? ' ' + o.kind : ''); s.title = o.title || ''; s.textContent = o.text; return s; };
      ['features', 'links', 'site', 'visits'].forEach(function (k) {
        var list = d[k] || [];
        [].forEach.call(document.querySelectorAll('.side-link[data-tab="' + k + '"]'), function (a) {
          [].forEach.call(a.querySelectorAll(':scope > .count'), function (c) { c.remove(); });
          list.forEach(function (o) { a.appendChild(document.createTextNode(' ')); a.appendChild(badge(o)); });
        });
        [].forEach.call(document.querySelectorAll('.pb-b[data-b="' + k + '"]'), function (h) { h.textContent = ''; list.forEach(function (o) { h.appendChild(document.createTextNode(' ')); h.appendChild(badge(o)); }); });
      });
    });
  } });

  // Scrolls to (and briefly marks) a place on this page, opening what is folded around it.
  function adminReveal(id) {
    document.dispatchEvent(new Event('gallery-settings-find-clear'));   // (a search in Settings would hide the place we are going to)
    var e = document.getElementById(id);
    if (!e) return;
    if (e.hidden && e.closest) e = e.closest('details') || e;   // (a row hidden because the setting it needs is off: go to its group)
    if (e.tagName === 'DETAILS') e.open = true;
    for (var up = e.parentElement; up; up = up.parentElement) if (up.tagName === 'DETAILS') up.open = true;   // (a setting inside a folded group opens the group)
    e.scrollIntoView({ behavior: 'smooth', block: 'start' });
    e.classList.add('flash-target');
    setTimeout(function () { e.classList.remove('flash-target'); }, 2500);
  }
  // A link inside the admin that opens one of its tabs and, if given, scrolls to (and briefly marks) a place in it. Inside the admin shell the shell
  // opens it (so its header, its folder buttons and its sidebar say where you are); on its own page the tab is switched here.
  function adminGoTo(tab, id) {
    try { if (window.parent !== window && /[?&]inframe=1/.test(location.search) && window.parent.__shellGo && window.parent.__shellGo(tab, id || '')) return false; } catch (x) {}
    var t = document.querySelector('.side-link[data-tab="' + tab + '"], .side-hidden[data-tab="' + tab + '"], .admin-tabs a[data-tab="' + tab + '"]');
    if (!t) return true;
    t.click();
    if (id) setTimeout(function () { adminReveal(id); }, 60);
    return false;
  }
  // Are the web server rules in place (the ones on the Hidden tab and in Settings)? This browser asks, without any
  // sign-in: for a photo and a cached thumbnail by their plain addresses (a refusal means locked, a picture coming back
  // means not; HEAD, so nothing is downloaded), and for a folder by its tidy address (the gallery's own page for that folder
  // means the tidy links work). A warning only goes away on a clear answer; any doubt leaves it, and the rules, standing.
  (function () {
    const probe = document.getElementById('server-probe');
    if (!probe || typeof fetch === 'undefined') return;
    const photo = probe.getAttribute('data-photo'), cache = probe.getAttribute('data-cache');
    const folderUrl = probe.getAttribute('data-folder-url');
    const hasFiles = Number(probe.getAttribute('data-files')) > 0;
    const banner = document.getElementById('lock-banner');
    // The "nginx internal redirect" row in Settings says what was found too (not only the Health check), and the warning under it shows only while the setting is on and not known to work.
    const accelSay = function (cls, text, risk) {
      const box = document.querySelector('[data-accel-status]'), warn = document.querySelector('[data-accel-risk]');
      if (box) { box.textContent = ''; box.className = 'help accel-status ' + cls; box.appendChild(document.createTextNode(text)); }
      if (warn) warn.hidden = !risk;
    };
    const mirrorLater = function () {
      if (photo) { if (!folderUrl) mirror('tidy', '', 'No folder to test with (turn on "Show subfolders" and add a folder with photos).'); return; }
      if (hasFiles) {
        mirror('lock', 'ok-text', 'Not needed: every photo and video here comes from folders outside the gallery\'s own address, so none can be fetched by its address.');
        mirror('accel', 'ok-text', 'Doesn\'t apply here: files from outside the gallery\'s own address are always sent by the gallery itself, whatever this is set to.');
        accelSay('ok-text', 'Doesn\'t apply here: every photo and video comes from folders outside the gallery\'s own address, and those are always sent by the gallery itself, whatever this is set to.', false);
        if (!folderUrl) mirror('tidy', '', 'No folder to test with (turn on "Show subfolders" and add a folder with photos).');
      } else { ['lock', 'tidy', 'accel'].forEach(function (k) { mirror(k, '', 'Nothing to test with yet (no photos).'); }); accelSay('muted', 'Nothing to test with yet (no photos).', false); }
    };
    const hideAll = function (sel, on) { document.querySelectorAll(sel).forEach(function (el) { el.hidden = on; }); };
    const report = function (selector, ok, text, rulesSel) {
      document.querySelectorAll(selector).forEach(function (el) {
        el.className = 'lock-result ' + (ok ? 'ok-text' : 'warn-text');
        el.textContent = text;
        if (ok) {
          const more = document.createElement('button');
          more.type = 'button'; more.className = 'linklike'; more.textContent = 'Show the rules';
          more.addEventListener('click', function () { hideAll(rulesSel, false); more.remove(); });
          el.appendChild(document.createTextNode(' ')); el.appendChild(more);
        }
      });
    };
    const ask = function (url) {
      const target = url + (url.indexOf('?') < 0 ? '?' : '&') + 'lockcheck=' + Date.now();   // (a server that won't answer HEAD gets a one-byte GET)
      return fetch(target, { method: 'HEAD', cache: 'no-store', credentials: 'omit', redirect: 'manual' })
        .then(function (r) { return r.status === 405 || r.status === 501 ? fetch(target, { headers: { Range: 'bytes=0-0' }, cache: 'no-store', credentials: 'omit', redirect: 'manual' }) : r; })
        .then(function (r) {
          if (r.type === 'opaqueredirect') return 'unknown';
          if (r.status === 401 || r.status === 403 || r.status === 404 || r.status === 410) return 'blocked';
          if (r.status >= 200 && r.status < 300) return 'open';
          return 'unknown';
        })
        .catch(function () { return 'unknown'; });
    };
    const icon = function (name) { return '<svg class="ico" aria-hidden="true"><use href="#i-' + name + '"></use></svg>'; };
    const refreshHealth = function () {
      const panel = document.getElementById('health-panel');
      if (!panel) return;
      const pending = panel.querySelectorAll('.health-row.pending').length, loud = panel.querySelectorAll('.health-row.loud').length, red = panel.querySelectorAll('.health-row.loud.red').length + panel.querySelectorAll('.health-row.health-bad').length;
      panel.classList.toggle('is-checking', pending > 0 && loud === 0);
      panel.querySelectorAll('.health-group').forEach(function (g) { g.classList.toggle('all-quiet', !g.querySelector('.health-row.loud, .health-row.note')); });
      const text = panel.querySelector('.health-summary-text');
      if (!text) return;
      text.textContent = loud ? loud + ' thing' + (loud === 1 ? '' : 's') + ' to fix.' : pending ? 'Checking\u2026' : '\u2713 All good';
      text.className = 'health-summary-text ' + (loud ? (red ? 'warn-text' : 'note-text') : pending ? 'muted' : 'ok-text');
      const opt = panel.querySelectorAll('.health-row.optional').length, lab = panel.querySelector('.ht-label');
      if (lab) lab.textContent = opt ? opt + ' optional' : 'Details';   // (the fold also holds everything that passed)
    };
    window.__initMediaPanel = function (panel) {   // (called now for the panel the page came with, and again for one put in place when Thumbnails is switched on)
      if (!panel || !panel.dataset.csrf) return;
      const go = panel.querySelector('.media-go'), stop = panel.querySelector('.media-stop'), count = panel.querySelector('.media-count'), bar = panel.querySelector('.media-bar'), fill = bar.firstElementChild;
      const again = panel.querySelector('.media-again'), remake = panel.querySelector('.media-remake'), more = panel.querySelector('.media-more'), reportBox = panel.querySelector('.media-report');
      const backupsBox = panel.querySelector('.media-backups');
      const tidyHead = panel.querySelector('.media-tidy-head');   // ("Tidy up": there while Tidy up saved locations or the backups line is)
      const syncTidyHead = function () { if (!tidyHead) return; const box = panel.querySelector('.media-tidy-box'); tidyHead.hidden = (!box || box.hidden) && !(backupsBox && backupsBox.querySelector('.media-backups-line')); };
      // Three requests work side by side, each on every third file, and each asks again the moment it is answered; a request
      // lasts about a second and a half, so the percentage moves several times a second. The percentage is of the files that
      // needed something when the page opened (or when the button was pressed): every file for "Read everything again".
      const WORKERS = 3, fmt = function (n) { return n.toLocaleString(); };
      let running = false, stopping = false, gen = 0, active = 0, pending = Number(panel.dataset.left) || 0, worked = 0, failed = 0, unreadable = 0, total = Number(panel.dataset.total) || 0, started = 0, problem = '', all = false;
      const many = function (n, word) { return n + ' ' + word + (n === 1 ? '' : 's'); };
      const pct = function () { return pending > 0 ? Math.min(99, Math.floor(100 * worked / pending)) : 99; };
      const paint = function () {
        const p = pct(), secs = (Date.now() - started) / 1000;
        let eta = '';
        if (secs >= 3 && worked >= 3 && pending > worked) {
          const left = Math.round((pending - worked) * secs / worked);
          if (left < 60) eta = ', about ' + many(Math.max(5, Math.round(left / 5) * 5), 'second') + ' left';
          else if (left < 3600) eta = ', about ' + many(Math.round(left / 60), 'minute') + ' left';
          else { let h = Math.floor(left / 3600), m = Math.round((left % 3600) / 60); if (m === 60) { h++; m = 0; } eta = ', about ' + many(h, 'hour') + (m ? ' ' + many(m, 'minute') : '') + ' left'; }
        }
        fill.style.width = p + '%';
        count.textContent = (stopping ? 'Stopping… ' : 'Preparing… ') + p + '% (' + fmt(worked) + ' of ' + fmt(pending) + ' files done' + eta + ')';
      };
      const text = function (tag, cls, t) { const e = document.createElement(tag); if (cls) e.className = cls; e.textContent = t; return e; };
      const loadReport = function () {
        const body = new URLSearchParams();
        body.set('csrf_token', panel.dataset.csrf); body.set('action', 'media_report');
        fetch('?admin', { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Requested-With': 'gallery-fetch' } })
          .then(function (r) { return r.json(); })
          .then(function (j) {
            if (j.ok && backupsBox && typeof j.backupsHtml === 'string') { backupsBox.innerHTML = j.backupsHtml; syncTidyHead(); }
            reportBox.textContent = '';
            if (!j.ok || !j.rows.length) { reportBox.appendChild(text('p', 'muted', j.ok ? 'There are no photos or videos yet.' : (j.text || 'The list could not be loaded.'))); return; }
            const wrap = text('div', 'media-table-wrap', ''), table = document.createElement('table'), thead = document.createElement('thead'), head = document.createElement('tr'), tbody = document.createElement('tbody');
            const heads = ['Folder', 'Files', 'Thumbnails', 'Videos', 'In the files', 'Dates', 'Saved'];
            if (j.earlierAny) heads.push('Earlier views');
            table.className = 'media-table';
            heads.forEach(function (h) { head.appendChild(text('th', '', h)); });
            thead.appendChild(head); table.appendChild(thead); table.appendChild(tbody);
            let legacy = 0;
            j.rows.forEach(function (r) {
              const tr = document.createElement('tr'), loc = [];
              if (r.found) loc.push(r.found + ' found');
              if (r.none + r.na) loc.push((r.none + r.na) + ' without');
              if (r.unread) loc.push(r.unread + ' not read yet');
              const own = [];
              if (r.own) own.push(r.own + ' location' + (r.own === 1 ? '' : 's'));
              if (r.cleared) own.push(r.cleared + ' removed on purpose');
              if (r.legacy) own.push(r.legacy + ' old "nothing found" mark' + (r.legacy === 1 ? '' : 's'));
              legacy += r.legacy;
              tr.appendChild(text('td', 'media-folder', r.folder === '' ? '(top level)' : r.folder));
              tr.appendChild(text('td', '', fmt(r.files)));
              tr.appendChild(text('td', r.photos && r.tiles < r.photos ? 'note-text' : '', r.photos ? r.tiles + ' of ' + many(r.photos, 'photo') : '—'));
              tr.appendChild(text('td', r.videoFailed ? 'warn-text' : (r.videos && (r.posters < r.videos || r.clips < r.videos || r.strips < r.videos) ? 'note-text' : ''), r.videos ? many(r.videos, 'video') + ': ' + r.posters + ' posters, ' + r.clips + ' clips, ' + r.strips + ' strips' + (r.videoFailed ? ' (' + r.videoFailed + ' failed lately)' : '') : '—'));
              const lc = text('td', r.unreadable ? 'warn-text' : '', loc.join(', ') || (r.unread === 0 ? '—' : ''));
              if (r.unreadable) { lc.appendChild(document.createTextNode((loc.length ? ', ' : '') + r.unreadable + " couldn't be read: " + r.bad.slice(0, 8).join(', ') + (r.bad.length > 8 ? ' and ' + (r.bad.length - 8) + ' more' : ''))); }
              tr.appendChild(lc);
              tr.appendChild(text('td', '', r.dated + (r.undated ? ' of ' + (r.dated + r.undated) : '') + ' dated'));
              tr.appendChild(text('td', '', own.join(', ') || '—'));
              if (j.earlierAny) tr.appendChild(text('td', r.earlier && !r.earlier.added ? 'note-text' : '', r.earlier ? (r.earlier.added ? 'added' : fmt(r.earlier.found) + ' found') : '—'));
              [].forEach.call(tr.children, function (td, i) { td.setAttribute('data-label', heads[i]); });
              tbody.appendChild(tr);
            });
            wrap.appendChild(table); reportBox.appendChild(wrap);
            reportBox.appendChild(text('p', 'hint', 'Saved are locations kept next to the captions: copies an older version made from the files, or ones you set. "Tidy up saved locations" (below) compares them with the files.'));
            if (legacy) reportBox.appendChild(text('p', 'hint', 'An old mark means an earlier version of the gallery looked at that photo and found no location. They are left alone; "Tidy up" and "Read everything again" deal with them.'));
          })
          .catch(function () { reportBox.textContent = ''; reportBox.appendChild(text('p', 'warn-text', 'The list could not be loaded.')); });
      };
      // Moved in from another gallery: the views an older gallery counted for these photos (added to this one's, once), and the thumbnails it
      // left behind in a place from elsewhere on the server (removed once the owner says no other gallery still shows the folders).
      const moved = panel.querySelector('.media-moved'), outer = panel.querySelector('details.media-outer');
      const leftBox = panel.querySelector('.media-leftover-box'), leftOk = panel.querySelector('.media-leftover-ok'), leftGo = panel.querySelector('.media-leftover-go'), leftOut = panel.querySelector('.media-leftover-out');
      const mb = function (b) { return b >= 1048576 ? (b / 1048576).toFixed(b >= 10485760 ? 0 : 1).replace(/\.0$/, '') + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; };
      const movedPost = function (action, extra) {
        const body = new URLSearchParams(); body.set('csrf_token', panel.dataset.csrf); body.set('action', action);
        Object.keys(extra || {}).forEach(function (k) { body.set(k, extra[k]); });
        return fetch('?admin', { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Requested-With': 'gallery-fetch' } }).then(function (r) { return r.json(); });
      };
      const loadMoved = function (keepText) {
        if (!moved) return;
        movedPost('media_moved').then(function (j) {
          if (!j.ok) return;
          const left = j.leftovers || { count: 0, folders: [] };
          if (left.count > 0) {
            leftBox.querySelector('.media-leftover-sum').textContent = '\u00b7 ' + fmt(left.count) + ' found, ' + mb(left.bytes);
            leftBox.querySelector('.media-leftover-list').textContent = left.folders.map(function (f) { return f.folder + ': ' + fmt(f.count); }).join(' \u00b7 ') + (left.more ? ' \u00b7 and ' + many(left.more, 'more folder') : '') + ' (' + many(left.folders.length + (left.more || 0), 'folder') + ', ' + many(left.count, 'file') + ', ' + mb(left.bytes) + ')';
            leftBox.hidden = false; leftOut.textContent = '';
          } else if (!keepText) leftBox.hidden = true;
          moved.hidden = leftBox.hidden;
        }).catch(function () {});
      };
      if (outer) { outer.addEventListener('toggle', function () { if (outer.open) loadMoved(false); }); if (outer.open) loadMoved(false); }
      if (leftOk) leftOk.addEventListener('change', function () { leftGo.disabled = !leftOk.checked; });
      if (leftGo) leftGo.addEventListener('click', function () {
        if (!leftOk.checked) return;
        leftGo.disabled = true; leftOut.textContent = 'Removing\u2026';
        movedPost('media_moved_remove', { others_gone: '1' }).then(function (j) {
          if (!j.ok) { leftOut.textContent = j.text || 'Nothing was removed.'; leftGo.disabled = !leftOk.checked; return; }
          leftOut.textContent = 'Removed ' + many(j.count, 'file') + ' (' + mb(j.bytes) + ').';
          leftBox.querySelector('.media-leftover-sum').textContent = ''; leftBox.querySelector('.media-leftover-list').textContent = '';
          leftOk.checked = false; leftGo.disabled = true; if (more.open) loadReport();
        }).catch(function () { leftOut.textContent = 'The server did not answer.'; leftGo.disabled = !leftOk.checked; });
      });
      more.addEventListener('toggle', function () { if (more.open) loadReport(); });
      // Tidy up saved locations: a preview first, then the real thing (every folder that changes is backed up first).
      const tidyOut = panel.querySelector('.media-tidy-out'), tidyBtn = panel.querySelector('.media-tidy-preview');
      const tidyMode = function () { const c = panel.querySelector('input[name=media-tidy-mode]:checked'); return c ? c.value : 'careful'; };
      const tidyAsk = function (apply) {
        const body = new URLSearchParams();
        body.set('csrf_token', panel.dataset.csrf); body.set('action', 'media_tidy'); body.set('mode', tidyMode()); if (apply) body.set('apply', '1');
        return fetch('?admin', { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Requested-With': 'gallery-fetch' } }).then(function (r) { return r.json(); });
      };
      const coords = function (c) { return c ? c[0].toFixed(5) + ', ' + c[1].toFixed(5) : 'none'; };
      const tidyShow = function (j) {
        tidyOut.textContent = '';
        if (!j.ok) { tidyOut.appendChild(text('p', 'warn-text', j.text || 'That did not work.')); return; }
        const t = j.totals, gone = t.copies + t.refreshed;
        if (j.applied) {
          tidyOut.appendChild(text('p', gone || t.marks ? 'ok-text' : 'muted', gone || t.marks ? 'Done. Removed ' + many(gone, 'saved location') + ' that the files already say and ' + many(t.marks, 'old mark') + ', in ' + many(t.folders, 'folder') + '. ' + many(j.backups, 'backup') + ' made (.gallery-items.before-tidy-….php in each folder).' : 'Nothing needed tidying.'));
          j.errors.forEach(function (e) { tidyOut.appendChild(text('p', 'warn-text', e)); });
        } else if (!gone && !t.marks) {
          tidyOut.appendChild(text('p', 'ok-text', 'Nothing to tidy: no saved location is a copy of what a file says.'));
        } else {
          tidyOut.appendChild(text('p', '', 'Would remove ' + many(t.copies, 'saved location') + ' that match their file' + (t.refreshed ? ' and ' + many(t.refreshed, 'one') + ' that differ (the file\'s location is used instead)' : '') + ', and clear ' + many(t.marks, 'old mark') + ', in ' + many(t.folders, 'folder') + '. Nothing else changes.'));
        }
        const kept = [];
        if (t.edited) kept.push(many(t.edited, 'location') + ' differ from the file and are kept');
        if (t.labelled) kept.push(many(t.labelled, 'location') + ' with a label are kept');
        if (t.noFile) kept.push(many(t.noFile, 'location') + " can't be confirmed by the file and are kept");
        if (t.ambiguous) kept.push(many(t.ambiguous, 'old mark') + ' left (the file has a location but the mark may mean you removed it)');
        if (t.unread) kept.push(many(t.unread, 'photo') + " haven't been read yet (Prepare the rest first)");
        if (kept.length) tidyOut.appendChild(text('p', 'hint', (j.applied ? 'Left as they were: ' : 'Kept: ') + kept.join('; ') + '.'));
        const names = { edited: 'Differs from the file', labelled: 'Has a label', noFile: 'The file can\'t confirm', ambiguous: 'Old mark, the file has a location' };
        j.rows.forEach(function (r) {
          const parts = [], keptKinds = Object.keys(names).filter(function (k) { return r.lists[k].length; });
          if (r.n.copies + r.n.refreshed) parts.push((j.applied ? 'removed ' : 'would remove ') + many(r.n.copies + r.n.refreshed, 'location'));
          if (r.n.marks) parts.push(many(r.n.marks, 'mark') + (j.applied ? ' cleared' : ' to clear'));
          if (!parts.length && !keptKinds.length) return;
          const box = document.createElement('details'); box.className = 'media-tidy-folder';
          const sum = document.createElement('summary'); sum.textContent = (r.folder === '' ? '(top level)' : r.folder) + ': ' + (parts.join(', ') || 'nothing to change') + (keptKinds.length ? ' (' + keptKinds.length + ' list' + (keptKinds.length === 1 ? '' : 's') + ' of what is kept)' : '');
          box.appendChild(sum);
          Object.keys(names).forEach(function (k) {
            if (!r.lists[k].length) return;
            box.appendChild(text('p', 'hint', names[k] + ' (' + r.n[k] + '):'));
            const ul = document.createElement('ul');
            r.lists[k].forEach(function (x) { ul.appendChild(text('li', '', x.name + (x.saved ? ' (saved ' + coords(x.saved) + ')' : '') + (k === 'edited' || k === 'labelled' || k === 'ambiguous' ? ' (file ' + coords(x.file) + ')' : ''))); });
            box.appendChild(ul);
          });
          tidyOut.appendChild(box);
        });
        if (!j.applied && (gone || t.marks)) {
          const go2 = document.createElement('button'); go2.type = 'button'; go2.className = 'secondary'; go2.textContent = 'Tidy up now';
          go2.addEventListener('click', function () {
            if (!confirm('Tidy up ' + many(t.folders, 'folder') + '?\n\nEach folder is copied first (.gallery-items.before-tidy-….php). Captions, links, private flags and every other setting stay exactly as they are.')) return;
            go2.disabled = true; tidyBtn.disabled = true;
            tidyAsk(true).then(function (r) { tidyBtn.disabled = false; tidyShow(r); loadReport(); }).catch(function () { tidyBtn.disabled = false; go2.disabled = false; tidyOut.appendChild(text('p', 'warn-text', 'The server did not answer.')); });
          });
          tidyOut.appendChild(go2);
        }
      };
      tidyBtn.addEventListener('click', function () {
        tidyBtn.disabled = true; tidyOut.textContent = ''; tidyOut.appendChild(text('p', 'muted', 'Comparing…'));
        tidyAsk(false).then(function (j) { tidyBtn.disabled = false; tidyShow(j); }).catch(function () { tidyBtn.disabled = false; tidyOut.textContent = ''; tidyOut.appendChild(text('p', 'warn-text', 'The server did not answer.')); });
      });
      // The Tidy up backups: delete them all, after a yes.
      panel.addEventListener('click', function (e) {
        const del = e.target.closest ? e.target.closest('.media-backups-delete') : null;
        if (!del) return;
        if (!confirm('Delete the backups Tidy up made?\n\nThey are copies of the folders\' data (captions, links, private flags) from before the tidy. Do it once you have looked through the gallery and everything is as it should be.')) return;
        del.disabled = true;
        const body = new URLSearchParams();
        body.set('csrf_token', panel.dataset.csrf); body.set('action', 'media_backups_delete');
        fetch('?admin', { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Requested-With': 'gallery-fetch' } })
          .then(function (r) { return r.json(); })
          .then(function (j) { backupsBox.textContent = ''; backupsBox.appendChild(text('p', 'hint ' + (j.ok ? 'ok-text' : 'warn-text'), j.text || 'Done.')); syncTidyHead(); })
          .catch(function () { del.disabled = false; const o = backupsBox.querySelector('.media-backups-out'); if (o) o.textContent = 'The server did not answer.'; });
      });
      const finish = function () {
        if (active > 0) return;
        running = false; stop.hidden = true; stop.disabled = false; again.disabled = false;
        if (problem) { go.hidden = false; go.textContent = 'Try again'; count.textContent = 'Stopped: ' + problem + '.'; count.className = 'media-count warn-text'; return; }
        if (stopping) {
          stopping = false; pending = Math.max(1, pending - worked); worked = 0;
          go.hidden = false; go.textContent = 'Carry on';
          count.textContent = 'Stopped. Press the button to carry on; what is done stays done.';
          return;
        }
        bar.hidden = true; fill.style.width = '100%';
        const notes = [];
        if (failed) notes.push(many(failed, 'picture') + ' could not be made (a damaged file, or a folder the web server cannot write to)');
        if (unreadable) notes.push(many(unreadable, 'file') + " couldn't be read for a capture date or location (the list below names them)");
        count.textContent = notes.length ? 'Finished. ' + notes.join('; ') + '.' : (all ? 'Everything was read again: ' : 'Everything is prepared: ') + many(total, 'file') + '.';
        count.className = 'media-count ' + (notes.length ? 'note-text' : 'ok-text');
        go.hidden = true;
        loadReport();
      };
      const work = function (k, mine) {
        active++;
        const next = function (from) {
          if (!running || stopping || mine !== gen) { active--; finish(); return; }
          const body = new URLSearchParams();
          body.set('csrf_token', panel.dataset.csrf); body.set('action', 'prepare_media'); body.set('from', String(from)); body.set('step', String(WORKERS));
          if (all) { body.set('again', '1'); if (remake.checked) body.set('remake', '1'); }
          fetch('?admin', { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Requested-With': 'gallery-fetch' } })
            .then(function (r) { return r.json(); })
            .then(function (j) {
              if (!j.ok) throw new Error(j.text || 'failed');
              failed += j.failed; unreadable += j.unreadable || 0; worked += j.worked || 0; total = j.total;
              paint();
              if (j.done) { active--; finish(); } else next(j.next);
            })
            .catch(function (err) { problem = problem || (err && err.message ? err.message : 'the server did not answer'); running = false; active--; finish(); });
        };
        next(k);
      };
      const start = function (everything) {
        running = true; stopping = false; problem = ''; failed = 0; unreadable = 0; worked = 0; all = everything; gen++; started = Date.now();
        if (everything) pending = Math.max(1, total);
        go.hidden = true; stop.hidden = false; again.disabled = true; bar.hidden = false; fill.style.width = '0'; count.className = 'media-count';
        count.textContent = 'Starting…';
        for (let k = 0; k < WORKERS; k++) work(k, gen);
      };
      go.addEventListener('click', function () { start(false); });
      again.addEventListener('click', function () {
        if (running) return;
        if (confirm('Read every file again' + (remake.checked ? ' and make every thumbnail again' : '') + '?\n\nThis also checks photos that an earlier version marked as "nothing found", so a location in the file may start to show where there was none. Anything you typed in or removed yourself is kept. It can take a while on a big gallery; you can stop it and carry on later.')) start(true);
      });
      stop.addEventListener('click', function () { if (!running || stopping) return; stopping = true; stop.disabled = true; paint(); });
    };
    window.__initMediaPanel(document.getElementById('media-panel'));
    // A heads-up's button that flips a Settings switch on the spot (the page then reloads to show what that changed)
    [].forEach.call(document.querySelectorAll('[data-health-switch]'), function (b) {
      b.addEventListener('click', function () {
        var cb = document.querySelector('input[name="set_' + b.getAttribute('data-health-switch') + '"][type=checkbox]');
        if (!cb || cb.checked) return;
        b.disabled = true; b.textContent = 'Turning on…';
        cb.click();
        setTimeout(function () { location.reload(); }, 1400);
      });
    });
    // New versions: the daily look (asked after the page is up, so a slow or blocked GitHub never slows Settings), Check now, Update now and Go back.
    (function () {
      var panel = document.getElementById('health-panel');
      if (!panel) return;
      var csrf = panel.getAttribute('data-csrf');
      function post(fields, done, fail) {
        fetch('?admin', { method: 'POST', body: new URLSearchParams(Object.assign({ csrf_token: csrf }, fields)), credentials: 'same-origin', headers: { 'X-Requested-With': 'gallery-fetch' } })
          .then(function (r) { return r.json(); }).then(done).catch(fail || function () {});
      }
      function say(t) { var s = panel.querySelector('[data-update-text]'); if (s && t) s.textContent = t; }
      function showRows(rows) {   // (the new-version and just-updated heads-ups: replace what is there, add the group when there was none)
        [].forEach.call(panel.querySelectorAll('[data-update-row]'), function (r) { r.remove(); });
        if (!rows || !rows.length) return;
        var group = panel.querySelector('.health-notes');
        if (!group) {
          group = document.createElement('div'); group.className = 'health-group health-notes'; group.innerHTML = '<h3 class="subhead">Heads-up</h3>';
          var first = panel.querySelector('.health-group'); first ? first.parentNode.insertBefore(group, first) : panel.appendChild(group);
        }
        var h = group.querySelector('.subhead'); h.insertAdjacentHTML('afterend', rows.join(''));
      }
      panel.addEventListener('click', function (ev) {
        var b = ev.target.closest ? ev.target.closest('[data-update]') : null;
        if (!b || b.disabled) return;
        var what = b.getAttribute('data-update'), was = b.textContent;
        b.disabled = true;
        if (what === 'check') {
          say('Checking…'); b.textContent = 'Checking…';
          post({ action: 'update_check', force: '1' }, function (j) { b.disabled = false; b.textContent = was; say(j.text); showRows(j.rows); }, function () { b.disabled = false; b.textContent = was; say('Could not reach the gallery.'); });
          return;
        }
        b.textContent = what === 'apply' ? 'Updating…' : 'Going back…';
        var row = b.closest('.health-row'), detail = row && row.querySelector('.health-detail');
        post({ action: what === 'apply' ? 'update_apply' : 'update_undo' }, function (j) {
          if (j.ok) { if (detail) detail.textContent = j.text + ' Reloading…'; setTimeout(function () { location.reload(); }, 700); return; }
          b.disabled = false; b.textContent = was; if (detail) detail.textContent = j.text;
        }, function () { b.disabled = false; b.textContent = was; if (detail) detail.textContent = 'Could not reach the gallery.'; });
      });
      if (panel.getAttribute('data-update-due') === '1') setTimeout(function () {
        post({ action: 'update_check' }, function (j) { if (j && j.ok) { say(j.text); showRows(j.rows); } });
      }, 1500);
    })();
    const toggle = document.querySelector('.health-toggle');
    // The list starts closed. This button opens and closes it. The one time it starts open is straight after a button in the list reloaded the page
    // (Set aside, Remove them), which set a one-shot note for it.
    (function () {
      const panel = document.getElementById('health-panel');
      if (!panel || !toggle) return;
      const paint = function (on) { panel.classList.toggle('show-all', on); toggle.setAttribute('aria-expanded', on ? 'true' : 'false'); };
      let reopen = false; try { reopen = sessionStorage.getItem('healthOpen') === '1'; sessionStorage.removeItem('healthOpen'); } catch (e) {}
      if (reopen) paint(true);
      toggle.addEventListener('click', function () { paint(!panel.classList.contains('show-all')); });
    })();
    const mirror = function (key, cls, text) {
      document.querySelectorAll('[data-health="' + key + '"]').forEach(function (el) { el.textContent = text; el.className = 'health-detail ' + cls; });
      document.querySelectorAll('[data-health-row="' + key + '"]').forEach(function (row) {
        const optional = row.getAttribute('data-optional') === '1' && cls === 'note-text';   // (not set up, and that is fine: grey, never counted as something to fix)
        const problem = cls === 'warn-text' || (cls === 'note-text' && !optional);
        row.classList.remove('pending'); row.classList.toggle('loud', problem); row.classList.toggle('quiet', !problem); row.classList.toggle('red', cls === 'warn-text'); row.classList.toggle('optional', optional);
        const m = row.querySelector('.health-mark');
        m.className = 'health-mark ' + (cls === 'ok-text' ? 'ok-text' : cls === 'warn-text' ? 'warn-text' : (cls === 'note-text' && !optional) ? 'note-text' : 'muted');
        m.innerHTML = cls === 'ok-text' ? icon('check') : problem ? icon('warn') : optional ? '&#9675;' : '&bull;';
        if (optional) { const d = row.querySelector('.health-detail'); if (d) d.className = 'health-detail muted'; }
      });
      refreshHealth();
    };
    const anyPrivate = probe.getAttribute('data-any-private') === '1';
    const lockShow = function (state, detail) {
      const outside = state === 'outside';
      const locked = state === 'locked' || outside;
      if (banner) {
        banner.hidden = locked || !anyPrivate;   // (locked is good news that Health check already says; nothing private yet is nothing to warn about)
        banner.classList.toggle('ok', locked); banner.classList.toggle('error', !locked);
        banner.querySelector('.lock-open-text').hidden = locked;
        banner.querySelector('.lock-locked-text').hidden = !(locked && !outside);
        banner.querySelector('.lock-outside-text').hidden = !outside;
        banner.querySelector('.lock-recheck').hidden = false;
      }
      hideAll('.lock-help, [data-lock-rules], [data-lock-rules-panel]', locked);
      if (outside) mirror('lock', 'ok-text', 'Not needed: every photo and video here comes from folders outside the gallery\'s own address, so none can be fetched by its address.');
      else mirror('lock', locked ? 'ok-text' : state === 'open' ? (anyPrivate ? 'warn-text' : 'note-text') : '', locked ? 'Yes: a photo and a cached thumbnail asked for by their plain addresses were refused, so private items can only be reached through the gallery.'
        : state === 'open' ? (anyPrivate ? 'Private photos aren\'t locked. Hiding a photo only hides it from the gallery\'s pages; anyone who knows its file address can still open it (' + detail + ' was handed out to a plain request).' : 'Not locked yet. Nothing is private, so nothing is exposed; add the rule in Settings → Server setup before you make something private (' + detail + ' was handed out to a plain request).') : 'Couldn\'t tell (the request failed or was redirected).');
      if (state === 'open') document.querySelectorAll('[data-health="lock"]').forEach(function (d) {
        if (d.querySelector('.health-fix')) return;
        const a = document.createElement('button'); a.type = 'button'; a.className = 'secondary health-fix'; a.textContent = '\u2192 The rules';
        a.addEventListener('click', function (ev) { ev.preventDefault(); adminGoTo('site', 'lock-rules-row'); setTimeout(function () { const f = document.querySelector('#lock-rules-row details'); if (f) f.open = true; }, 120); });   // (Settings always has the rules; the Edited tab may not exist yet)
        d.appendChild(document.createTextNode(' ')); d.appendChild(a);
      });
      document.querySelectorAll('[data-lock-detail]').forEach(function (el) {
        el.className = 'lock-result warn-text';
        el.textContent = locked ? '' : state === 'open' ? 'The server handed out ' + detail + ' to a plain request.' : 'Couldn\'t check from this browser just now.';
      });
      report('[data-lock-status]', locked, outside ? 'Nothing to ask the server for: no photo or video has an address of its own here.' : locked ? 'Checked just now from this browser: direct requests for photos are refused.'
        : state === 'open' ? 'Checked just now from this browser: the server handed out ' + detail + ' to a plain request, so a private photo could be fetched by its address. Use the rules below.'
        : 'Couldn\'t check from this browser just now (the request failed or was redirected).', '.lock-help, [data-lock-rules], [data-lock-rules-panel]');
    };
    const runLock = function () {
      if (!photo) { if (hasFiles) lockShow('outside'); return; }
      Promise.all([ask(photo), cache ? ask(cache) : Promise.resolve('skipped')]).then(function (r) {
        if (r[0] === 'open') return lockShow('open', photo);
        if (r[1] === 'open') return lockShow('open', cache);
        if (r[0] === 'blocked' && (r[1] === 'blocked' || r[1] === 'skipped')) return lockShow('locked');
        lockShow('unknown');
      });
    };
    // Can the visitor log (a SQLite file with IP addresses in it) be downloaded by its plain address? The rule that refuses files starting with .gallery- keeps it private.
    const runVisitLog = function () {
      const url = probe.getAttribute('data-visits');
      if (!url) return;
      ask(url).then(function (state) {
        mirror('visitlog', state === 'blocked' ? 'ok-text' : state === 'open' ? 'warn-text' : '', state === 'blocked' ? 'Yes: asked for by its plain address, the server refuses it.'
          : state === 'open' ? 'Anyone can download the visitor log, which holds IP addresses: the server isn\'t refusing files that start with .gallery-. Add the server rules (Settings, Server setup) and check again.' : 'Couldn\'t check from this browser just now.');
      });
    };
    // Does the tidy share address (/trip/<code>) reach the gallery? The fixed probe address answers 200 with X-Gallery-Link: invalid when it does.
    const runShareTidy = function () {
      const url = probe.getAttribute('data-share-probe');
      if (!url) return;
      fetch(url, { cache: 'no-store', credentials: 'omit' })
        .then(function (r) { return r.headers.get('X-Gallery-Link') === 'invalid' ? 'works' : 'no'; })
        .catch(function () { return 'unknown'; })
        .then(function (state) {
          const shown = location.origin + url.replace(/0000-probe-0000$/, '') + 'k7QpR2mX';   // (the probe's own name taken off the end: a link is the address and its code)
          mirror('sharetidy', state === 'works' ? 'ok-text' : state === 'no' ? 'note-text' : '', state === 'works' ? 'A link looks like ' + shown + '.'
            : state === 'no' ? 'Not set up (optional): links use the long form (…/?k7QpR2mX). It works as it is; the rule only makes them shorter.' : 'Couldn\'t tell (the request failed).');
          document.querySelectorAll('[data-ft-sharetidy]').forEach(function (row) {   // (the Features list says the same: on, with a link as it looks now, and nothing to open)
            var chip = row.querySelector('.ft-chip'), text = row.querySelector('.ft-text'), btn = row.querySelector('.ft-act button');
            if (state === 'works') { chip.className = 'ft-chip ft-on'; chip.textContent = 'On'; text.textContent = 'The server rule is in place: a link looks like ' + shown + '.'; if (btn) btn.hidden = true; }
          });
          if (state === 'works') {
            hideAll('[data-sharetidy-rules]', true);
            report('[data-sharetidy-status]', true, 'Tidy share addresses work: a link looks like ' + shown + ' (checked just now from this browser).', '[data-sharetidy-rules]');
          } else {
            hideAll('[data-sharetidy-rules]', false);
            report('[data-sharetidy-status]', false, state === 'no' ? 'Tidy share addresses are not set up (optional): links use the long form (…/?k7QpR2mX), which works as it is. Rules to add are below.' : 'Couldn\'t check tidy share addresses from this browser just now.', '[data-sharetidy-rules]');
          }
        });
    };
    const runTidy = function () {
      if (!folderUrl) return;
      fetch(folderUrl, { cache: 'no-store', credentials: 'omit', redirect: 'manual' })
        .then(function (r) {
          if (r.type === 'opaqueredirect') return 'unknown';
          if (r.status === 200) return r.text().then(function (t) { return t.indexOf('name="gallery-folder"') >= 0 ? 'works' : 'no'; });
          // A closed gallery turns a visitor who isn't signed in away with the invalid-link page, which only the gallery sends (a server
          // without the rule answers with its own 404): so that answer says the address does reach the gallery.
          if (r.status === 404 && r.headers.get('X-Gallery-Link') === 'invalid') return 'closed';
          return r.status >= 400 && r.status < 500 ? 'no' : 'unknown';
        })
        .catch(function () { return 'unknown'; })
        .then(function (state) {
          if (state === 'closed') {
            mirror('tidy', 'ok-text', folderUrl + ' reaches the gallery. It is closed, so this check (which sends no sign-in) is shown the invalid-link page.');
            hideAll('[data-tidy-rules]', true);
            report('[data-tidy-status]', true, 'Tidy folder links work: ' + folderUrl + ' reaches the gallery (checked just now from this browser). The gallery is closed, so this check, which sends no sign-in, is shown the invalid-link page.', '[data-tidy-rules]');
            return;
          }
          mirror('tidy', state === 'works' ? 'ok-text' : state === 'no' ? 'note-text' : '', state === 'works' ? folderUrl + ' opens that folder.'
            : state === 'no' ? 'Not set up (optional): ' + folderUrl + ' doesn\'t open that folder; the ?folder= links work regardless.' : 'Couldn\'t tell (the request failed or was redirected).');
          if (state === 'works') {
            hideAll('[data-tidy-rules]', true);
            report('[data-tidy-status]', true, 'Tidy folder links work: ' + folderUrl + ' opens that folder (checked just now from this browser).', '[data-tidy-rules]');
          } else {
            hideAll('[data-tidy-rules]', false);
            report('[data-tidy-status]', false, state === 'no' ? 'Tidy folder links are not set up (optional): ' + folderUrl + ' does not open that folder yet. The gallery works without them, with ?folder= links. Rules to add are below.'
              : 'Couldn\'t check tidy folder links from this browser just now.', '[data-tidy-rules]');
          }
        });
    };
    // "nginx internal redirect": asks the gallery to answer a request the way it would with that setting, and sees whether
    // nginx turns the answer into the picture. With it set, that says whether it works; empty, whether setting it would.
    const accel = document.querySelector('[data-accel-status]');
    const runAccel = function () {
      if (!accel) return;
      const current = accel.getAttribute('data-accel-current'), candidate = accel.getAttribute('data-accel-candidate');
      const prefix = current || candidate;
      if (!photo) return;   // (mirrorLater has said why)
      if (!prefix) { mirror('accel', '', 'Not applicable: a gallery at the very top of a site has no folder to set it to.'); accelSay('muted', 'Not applicable: a gallery at the very top of a site has no folder to set it to.', false); return; }
      fetch('?admin&accelcheck=1&prefix=' + encodeURIComponent(prefix), { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { return r.ok && /^(image|video)\//.test(r.headers.get('content-type') || '') ? r.blob().then(function (b) { return b.size > 0; }) : false; })
        .catch(function () { return null; })
        .then(function (works) {
          const warn = document.querySelector('[data-accel-risk]'); if (warn) warn.hidden = !(current && works !== true);   // (the warning under the setting: only while it is on and not known to work)
          if (works === null) { accelSay('muted', 'Couldn\'t tell (the request failed).', !!current); return mirror('accel', '', 'Couldn\'t tell (the request failed).'); }
          mirror('accel', current ? (works ? 'ok-text' : 'warn-text') : (works ? 'note-text' : ''), current
            ? (works ? 'Set to ' + current + ' and working: nginx sends the photos and videos itself.' : 'Set to ' + current + ' but nginx did not serve a test photo through it; photos may fail to load.')
            : (works ? 'Not set, though nginx has the internal location for ' + candidate + ' in place: setting it makes photos and videos faster.' : 'Not set, and not needed unless you run nginx with the internal location (see the Edited tab).'));
          accel.textContent = '';
          const say = function (cls, text) { accel.className = 'help accel-status ' + cls; accel.appendChild(document.createTextNode(text)); };
          if (current) {
            if (works) say('ok-text', 'Working: nginx just served a test photo through ' + current + ', so photos and videos are sent by nginx itself (checked from this browser).');
            else say('warn-text', 'Not working: with this set, nginx did not serve ' + photo + ' (a photo in the gallery\'s own folder) through ' + current + ', so photos and videos may fail to load. The internal location must match that address: for the rules on the Private tab, a location starting ' + current + '/ whose photo extensions are marked internal, in the same server block that serves this page. Try: curl -I ' + location.origin + photo + ' (it should say 404 or 403), and look in nginx\'s error log for the line about the internal redirect. Or clear this setting.');
          } else if (works) {
            say('note-text', 'Not using it yet: nginx has the internal location for ' + candidate + ' in place (just tested), but this is empty, so the gallery reads every photo and video itself. Setting it to ' + candidate + ' lets nginx send them, which is faster and handles seeking in videos better. ');
            const use = document.createElement('button');
            use.type = 'button'; use.className = 'secondary'; use.textContent = 'Set it to ' + candidate;
            use.addEventListener('click', function () {
              const input = document.querySelector('input[name="set_NGINX_ACCEL_REDIRECT_PREFIX"]');
              if (!input) return;
              input.value = candidate; input.dispatchEvent(new Event('change', { bubbles: true }));
              use.remove();
              accel.className = 'help accel-status ok-text'; accel.textContent = 'Saved. Reload this page to check that it works.';
            });
            accel.appendChild(use);
          }
        });
    };
    if (banner) banner.querySelector('.lock-recheck').addEventListener('click', function () { runLock(); runTidy(); });
    document.querySelectorAll('.show-rules-btn').forEach(function (btn) { btn.addEventListener('click', function () { hideAll('.lock-help, [data-lock-rules], [data-lock-rules-panel]', false); btn.remove(); }); });
    mirrorLater();
    runLock();
    runVisitLog();
    runTidy();
    runShareTidy();
    runAccel();
  })();
  // The directive reference starts expanded on a desktop and collapsed
  // on a phone-width screen; it's a plain <details class="fold">, so either can toggle.
  // The folder list is a sidebar on a desktop and a collapsed "Folders" bar on a phone.
  if (window.matchMedia && window.matchMedia('(max-width: 800px)').matches) {
    document.querySelectorAll('.admin-folders').forEach(function (d) { d.removeAttribute('open'); });
  }
  // The notes reference's Insert buttons put a directive at the cursor of the note you last clicked in (or into
  // the only note there is); with several notes and none clicked, they say to click in one first.
  var lastNote = null;
  document.querySelectorAll('.adm-textarea').forEach(function (ta) {
    ta.addEventListener('focus', function () { lastNote = ta; });
  });
  // The notes reference shows one section at a time (without JavaScript every section is simply listed).
  [].forEach.call(document.querySelectorAll('.notes-reference'), function (ref) {
    var tabs = ref.querySelectorAll('.ref-tabs button'), panels = ref.querySelectorAll('.ref-panel');
    function show(name) {
      [].forEach.call(tabs, function (t) { var on = t.getAttribute('data-ref') === name; t.classList.toggle('active', on); t.setAttribute('aria-selected', on ? 'true' : 'false'); });
      [].forEach.call(panels, function (p) { p.classList.toggle('active', p.getAttribute('data-ref') === name); });
      ref.scrollTop = 0;
      try { sessionStorage.setItem('galleryRefTab', name); } catch (e) {}
    }
    ref.classList.add('js-ref');
    [].forEach.call(tabs, function (t) { t.addEventListener('click', function () { show(t.getAttribute('data-ref')); }); });
    [].forEach.call(ref.querySelectorAll('[data-goto]'), function (l) { l.addEventListener('click', function () { show(l.getAttribute('data-goto')); }); });
    var saved = null;
    try { saved = sessionStorage.getItem('galleryRefTab'); } catch (e) {}
    show(saved && ref.querySelector('.ref-panel[data-ref="' + saved + '"]') ? saved : 'start');
  });
  document.querySelectorAll('.admin-insert-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const notes = document.querySelectorAll('.adm-textarea');
      const textarea = lastNote || (notes.length === 1 ? notes[0] : null);
      const hint = document.querySelector('.insert-hint');
      if (!textarea) { if (hint) hint.hidden = false; return; }
      if (hint) hint.hidden = true;
      const snippet = btn.getAttribute('data-snippet');
      let start = textarea.selectionStart, end = textarea.selectionEnd;
      // Never write into the {gallery} line itself: a cursor still sitting in it (or at the very start) means the end of the text.
      const firstBreak = textarea.value.indexOf('\n'), firstLine = firstBreak < 0 ? textarea.value : textarea.value.slice(0, firstBreak);
      if (/^\s*\{gallery\}\s*$/i.test(firstLine) && start <= firstLine.length) {
        const sep = textarea.value === '' || textarea.value.slice(-1) === '\n' ? '' : ' ';
        start = end = textarea.value.length;
        textarea.value = textarea.value + sep;
        start = end = textarea.value.length;
      }
      textarea.value = textarea.value.slice(0, start) + snippet + textarea.value.slice(end);
      textarea.focus();
      textarea.selectionStart = textarea.selectionEnd = start + snippet.length;
    });
  });
  // Tabs: only the selected section is shown. #name in the address picks one
  // (so a link can point straight at a tab), the last
  // choice is remembered while moving between folders, and without JavaScript
  // every section simply stays visible.
  (function () {
    var main = document.querySelector('.admin-main');
    if (!main) return;
    var tabs = [].slice.call(document.querySelectorAll('.admin-tabs a[data-tab], .side-hidden[data-tab], .side-link[data-tab]'));
    var panels = [].slice.call(document.querySelectorAll('.tab-panel'));
    var names = tabs.map(function (t) { return t.getAttribute('data-tab'); });
    var KEY = 'galleryAdminTab';
    function show(name) {
      if (names.indexOf(name) < 0) name = names.indexOf('settings') >= 0 ? 'settings' : names[0];
      panels.forEach(function (p) { p.classList.toggle('active', p.getAttribute('data-tab') === name); });
      tabs.forEach(function (t) {
        var on = t.getAttribute('data-tab') === name;
        t.classList.toggle('active', on);
        if (t.getAttribute('role') === 'tab') t.setAttribute('aria-selected', on ? 'true' : 'false');   // (the sidebar's links are plain links: the page they are on is marked with aria-current)
        else if (on) t.setAttribute('aria-current', 'page'); else t.removeAttribute('aria-current');
      });
      // On Settings or Private nothing in the folder list is selected: the folder you came from stays marked, quietly.
      var lay = document.querySelector('.admin-layout');
      var whole = name === 'site' || name === 'hidden' || name === 'links' || name === 'features' || name === 'visits';
      if (lay) lay.classList.toggle('off-folder', whole);
      // The phone's Menu and bottom bar say the same thing as the sidebar: the page you are on is the one blue entry; the folder stays marked, quietly, on a whole-gallery page.
      [].forEach.call(document.querySelectorAll('.pb-w[data-pb-tab]'), function (a) { var on = a.getAttribute('data-pb-tab') === name; a.classList.toggle('cur', on); if (on) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current'); });
      [].forEach.call(document.querySelectorAll('#pb .pb-i[data-pb=settings], #pb .pb-i[data-pb=notes]'), function (a) { a.classList.toggle('on', a.getAttribute('data-pb') === name); });
      var pbSheet = document.getElementById('pb-sheet'); if (pbSheet) pbSheet.classList.toggle('off-folder', whole);
      var fh = document.getElementById('admin-folder-head'); if (fh) fh.hidden = whole;   // (the folder's own tabs belong to a folder, not to the whole-gallery pages)
      // "Hidden" is a look at the whole tree, not a section of a folder:
      // moving to another folder shouldn't land you back on it.
      if (name === 'links' && window.__slSeen) setTimeout(window.__slSeen, 1500);
      if (name === 'visits') setTimeout(function () { if (window.__vtSeen) window.__vtSeen(); }, 1500);
      if (window.__live) window.__live.shown(name);   // (looked up when the time is up: on a page that opens on this tab the Visitors script has not run yet at this moment)
      if (name !== 'hidden' && name !== 'site' && name !== 'links' && name !== 'features' && name !== 'visits') { try { sessionStorage.setItem(KEY, name); } catch (e) {} }
      return name;
    }
    function fromLocation() {
      var h = decodeURIComponent((location.hash || '').slice(1));
      if (names.indexOf(h) >= 0) { show(h); return; }
      var saved = null;
      try { saved = sessionStorage.getItem(KEY); } catch (e) {}
      var landed = null;
      try { landed = sessionStorage.getItem('galleryAdminLanded'); } catch (e) {}
      if (!saved && !landed && !/[?&]folder=/.test(location.search) && main.getAttribute('data-land') === 'features' && names.indexOf('features') >= 0) {   // a fresh install (nothing configured yet) lands on Features, once: opening a folder or pressing Root afterwards goes where it says
        try { sessionStorage.setItem('galleryAdminLanded', '1'); } catch (e) {}
        show('features'); return;
      }
      show(saved);
    }
    main.classList.add('js-tabs');
    tabs.forEach(function (t) {
      t.addEventListener('click', function (ev) {
        ev.preventDefault();
        var target = t.getAttribute('data-tab');
        if (window.__siteDirty && target !== 'site') {
          // Something in Settings changed what the gallery shows: reload so the sidebar, counts and photos are current.
          if (target !== 'hidden' && target !== 'links' && target !== 'features' && target !== 'visits') { try { sessionStorage.setItem(KEY, target); } catch (e) {} }
          if (history.replaceState) history.replaceState(null, '', '#' + target);
          location.reload();
          return;
        }
        var name = show(target);
        if (history.replaceState) history.replaceState(null, '', '#' + name);
        window.scrollTo(0, 0);
      });
    });
    window.addEventListener('hashchange', fromLocation);
    fromLocation();
  })();
  // Folder tree: branches not on the way to the folder being edited start folded; the chevron toggles one.
  // A function so it can be run again when Settings changes which folders exist.
  function initFolderTree() {
    var nav = document.querySelector('.admin-folder-nav');
    if (!nav) return;
    [].forEach.call(nav.querySelectorAll('li.has-children'), function (li) {
      var toggle = li.querySelector(':scope > .ftoggle');
      if (!li.querySelector('a.current')) { li.classList.add('collapsed'); toggle.setAttribute('aria-expanded', 'false'); }
      toggle.addEventListener('click', function () {
        var folded = li.classList.toggle('collapsed');
        toggle.setAttribute('aria-expanded', folded ? 'false' : 'true');
      });
    });
  }
  initFolderTree();
  // The "Copy" buttons on the web-server rules.
  [].forEach.call(document.querySelectorAll('.copy-rules'), function (btn) {
    btn.addEventListener('click', function () {
      var text = btn.parentNode.querySelector('pre').textContent;
      function done() { var old = btn.textContent; btn.textContent = 'Copied'; setTimeout(function () { btn.textContent = old; }, 1400); }
      if (window.GalleryShare && window.GalleryShare.copyText) { window.GalleryShare.copyText(text, done); return; }
      if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(done, function () {}); return; }
      var ta = document.createElement('textarea'); ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0'; document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); done(); } catch (e) {} document.body.removeChild(ta);
    });
  });
  // "Use these dates": copies the suggestion into the Dates box; nothing is saved until the form is.
  [].forEach.call(document.querySelectorAll('.date-suggest'), function (btn) {
    btn.addEventListener('click', function () {
      var input = btn.closest('.field').querySelector('input[name=dates]');
      if (!input) return;
      input.value = btn.getAttribute('data-dates');
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.focus();
      btn.remove();
    });
  });
  // Settings that are a single switch or number save the moment they change, in place: the control, the little status
  // beside it, and nothing else on the page moves. (The small Save button exists only without JavaScript.)
  [].forEach.call(document.querySelectorAll('.instant-form, .unlisted-form'), function (form) {
    form.addEventListener('submit', function (ev) { ev.preventDefault(); });
    var csrf = form.querySelector('input[name=csrf_token]').value, action = form.querySelector('input[name=action]').value;
    function say(node, text, kind) {
      if (!node) return;
      node.textContent = text; node.className = 'rowstate ' + (kind || '');
      clearTimeout(node._t);
      if (kind === 'ok') node._t = setTimeout(function () { node.textContent = ''; node.className = 'rowstate'; }, Math.max(3000, text.length * 60));
    }
    [].forEach.call(form.querySelectorAll('input[type=checkbox], input[type=number]'), function (el) {
      var isBox = el.type === 'checkbox';
      if (!isBox) el.setAttribute('data-prev', el.value);
      el.addEventListener('change', function () {
        var holder = el.closest('.instant-row, .field') || form, node = holder.querySelector('.rowstate');
        var sub = form.querySelector('.map-sub');
        if (el.name === 'map_enabled' && sub) sub.hidden = !el.checked;
        var body = new URLSearchParams();
        body.set('csrf_token', csrf); body.set('action', action); body.set(el.name, isBox ? (el.checked ? '1' : '0') : el.value);
        [].forEach.call(form.querySelectorAll('input[type=hidden][data-send]'), function (h) { body.set(h.name, h.value); });
        var revert = function () { if (isBox) el.checked = !el.checked; else el.value = el.getAttribute('data-prev'); if (el.name === 'map_enabled' && sub) sub.hidden = !el.checked; };
        say(node, 'Saving…', 'busy');
        fetch(location.pathname + location.search, { method: 'POST', credentials: 'same-origin', body: body.toString(),
            headers: { 'X-Requested-With': 'gallery-fetch', 'Content-Type': 'application/x-www-form-urlencoded' } })
          .then(function (r) { if ((r.headers.get('content-type') || '').indexOf('json') < 0) throw new Error('session'); return r.json(); })
          .then(function (j) {
            if (j.ok) { if (j.label && isBox) { var lab = el.closest('.instant-row').querySelector('.instant-label'); if (lab) lab.textContent = j.label; } say(node, j.text, 'ok'); window.__siteDirty = true; if (!isBox) el.setAttribute('data-prev', el.value); form.dispatchEvent(new CustomEvent('instant-saved', { bubbles: true, detail: j })); }
            else { say(node, j.text, 'err'); revert(); }
          })
          .catch(function (e) { say(node, e.message === 'session' ? 'Your session ended — reload the page and sign in again.' : 'Couldn\'t save — check your connection.', 'err'); revert(); });
      });
    });
  });
  // A folder's own settings save by themselves: a text field when you leave it or stop typing for a second, the order choices as they are
  // made. One request at a time, each checked against the record's fingerprint (so another tab's change is never overwritten), and each
  // field says "Saving…", "Saved" or what is wrong beside its own label. Nothing else in the folder's data is touched.
  // (the folder's settings are two forms, the fields above its switches and the order of the photos below them; they share one fingerprint
  // and one queue, so a save from either is checked against, and updates, the same record)
  var identityChain = Promise.resolve();
  [].forEach.call(document.querySelectorAll('.identity-form'), function (form) {
    form.addEventListener('submit', function (ev) { ev.preventDefault(); });
    var csrf = form.querySelector('input[name=csrf_token]').value, hashInput = form.querySelector('input[name=loaded_hash]');
    function say(node, text, kind) {
      if (!node) return;
      node.textContent = text; node.className = 'rowstate ' + (kind || '');
      clearTimeout(node._t);
      if (kind === 'ok') node._t = setTimeout(function () { node.textContent = ''; node.className = 'rowstate'; }, 3000);
    }
    function stateOf(el) { var h = el.closest('.flat-choice') || el.closest('.field') || el.closest('details'); return h ? h.querySelector('.rowstate') : null; }   // (each of the three Flat choices says Saved beside its own label)
    function post(fields, node, onOk, onErr) {
      identityChain = identityChain.then(function () {
        say(node, 'Saving…', 'busy');
        var body = new URLSearchParams();
        body.set('csrf_token', csrf); body.set('action', 'save_folder_identity'); body.set('loaded_hash', hashInput.value);
        fields.forEach(function (kv) { body.append(kv[0], kv[1]); });
        return fetch(location.pathname + location.search, { method: 'POST', credentials: 'same-origin', body: body.toString(),
            headers: { 'X-Requested-With': 'gallery-fetch', 'Content-Type': 'application/x-www-form-urlencoded' } })
          .then(function (r) { if ((r.headers.get('content-type') || '').indexOf('json') < 0) throw new Error('session'); return r.json(); })
          .then(function (j) {
            if (j.ok) {
              if (j.hash) [].forEach.call(document.querySelectorAll('.identity-form input[name=loaded_hash]'), function (h) { h.value = j.hash; }); say(node, 'Saved', 'ok'); window.__siteDirty = true; if (onOk) onOk();
              if (j.path) { var q = new URLSearchParams(location.search); if (q.get('folder') !== j.path) { q.set('folder', j.path); location.replace(location.pathname + '?' + q.toString() + location.hash); } }   // (a collection whose address was changed is now at another address)
            }
            else { say(node, j.text, 'err'); if (onErr) onErr(); }
          })
          .catch(function (e) { say(node, e.message === 'session' ? 'Your session ended: reload the page and sign in again.' : 'Couldn\'t save: check your connection.', 'err'); if (onErr) onErr(); });
      });
    }
    // How this folder shows the folders under it (and, in Flat, their names and notes): each press saves at once; the names and notes rows only show while they matter.
    var flatBox = form.querySelector('.flat-choices');
    function flatSync() {
      if (!flatBox) return;
      var val = function (n) { var c = flatBox.querySelector('input[name=' + n + ']:checked'); return c ? c.value : ''; };
      var flat = val('subfolder_style') === 'flat' || (val('subfolder_style') === '' && flatBox.getAttribute('data-site-flat') === '1');
      var names = val('flat_names') === '1' || (val('flat_names') === '' && flatBox.getAttribute('data-site-names') === '1');
      var rn = flatBox.querySelector('[data-flat-row=names]'), rt = flatBox.querySelector('[data-flat-row=notes]');
      if (rn) rn.hidden = !flat; if (rt) rt.hidden = !(flat && names);
    }
    [].forEach.call(form.querySelectorAll('.seg-site input[type=radio]'), function (r) {
      r.addEventListener('change', function () { flatSync(); post([[r.name, r.value]], stateOf(r)); });
    });
    [].forEach.call(form.querySelectorAll('input[type=text][name], input[type=number][name], textarea[name]'), function (el) {
      var saved = el.value, timer = 0;
      function go() {
        clearTimeout(timer);
        var node = stateOf(el), v = el.name === 'display_name' ? el.value.trim() : el.value;
        if (v === saved) { if (node && node.classList.contains('busy')) say(node, '', ''); return; }
        if (el.name === 'sort_order' && v.trim() !== '' && !/^-?\d{1,5}$/.test(v.trim())) {
          el.classList.add('bad'); say(node, 'Whole numbers only, from -99999 to 99999. Not saved.', 'err'); return;
        }
        el.classList.remove('bad');
        var sent = v;
        post([[el.name, v]], node, function () { saved = sent; }, function () { el.classList.add('bad'); });
      }
      el.addEventListener('input', function () {
        el.classList.remove('bad'); clearTimeout(timer);
        var node = stateOf(el); if (node && node.classList.contains('err')) say(node, '', '');
        timer = setTimeout(go, 1000);
      });
      el.addEventListener('blur', go);
      el.addEventListener('keydown', function (e) { if (e.key === 'Enter' && el.tagName !== 'TEXTAREA') { e.preventDefault(); go(); } });
      el.addEventListener('saved-externally', function () { saved = el.value; });
    });
    // "Use these dates" fills the field, which then saves like a typed one.
    [].forEach.call(form.querySelectorAll('.date-suggest'), function (b) {
      b.addEventListener('click', function () {
        var inp = form.querySelector('input[name=dates]'); if (!inp) return;
        inp.value = b.getAttribute('data-dates'); inp.dispatchEvent(new Event('input', { bubbles: true })); inp.blur();
        var hint = b.closest('.hint'); if (hint) hint.hidden = true;
      });
    });
    // The order of the photos: every choice sends the whole group (the server rebuilds the folder's own order from all of it).
    [].forEach.call(form.querySelectorAll('details.sort-details'), function (det) {
      var timer = 0, node = det.querySelector('summary .rowstate');
      function send() {
        var f = [];
        new FormData(form).forEach(function (v, k) { if (k.indexOf('photo_sort_') === 0) f.push([k, v]); });
        post(f, node);
      }
      function soon() { clearTimeout(timer); timer = setTimeout(send, 120); }
      det.addEventListener('change', soon);
      det.addEventListener('sf-changed', soon);
    });
  });
  // The Edited tab: filter chips, one card per folder that opens and closes, a header that says what is inside (as filtered); the locks on
  // a card's thumbnails change a photo on the spot (with an Undo), "+N" shows the rest, and "Make public…" on a private folder asks first,
  // with the counts, then opens it. After every change the list is fetched again, so its numbers are the server's, and the cards that were
  // open (and the chip that was chosen) stay as they were.
  (function () {
    var root = document.getElementById('ed-root'); if (!root) return;
    var csrf = root.getAttribute('data-csrf') || '', noun = root.getAttribute('data-noun') || 'photo';
    var state = 'all', expanded = {}, TH = 84, GAP = 10;
    var toast = document.getElementById('pv-toast'), modal = document.getElementById('pt-modal'), pending = null, toastTimer = null;
    function word(n) { return n + ' ' + noun + (n === 1 ? '' : 's'); }
    function plural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }
    function tag(cls, text) { var s = document.createElement('span'); s.className = 'tag ' + cls; s.textContent = text; return s; }
    function say(text, undo, bad) {
      if (!toast) return;
      clearTimeout(toastTimer); toast.className = 'pv-toast' + (bad ? ' err' : ''); toast.textContent = text + ' ';
      if (undo) {
        var b = document.createElement('button'); b.type = 'button'; b.className = 'linklike'; b.textContent = 'Undo';
        b.addEventListener('click', function () { toast.hidden = true; undo(); }); toast.appendChild(b);
      }
      toast.hidden = false; toastTimer = setTimeout(function () { toast.hidden = true; }, undo ? 8000 : 4000);
    }
    function post(folder, fields) {
      var body = new URLSearchParams(); body.set('csrf_token', csrf);
      Object.keys(fields).forEach(function (k) { body.set(k, fields[k]); });
      return fetch('?admin' + (folder ? '&folder=' + encodeURIComponent(folder) : ''), { method: 'POST', credentials: 'same-origin', body: body.toString(),
          headers: { 'X-Requested-With': 'gallery-fetch', 'Content-Type': 'application/x-www-form-urlencoded' } })
        .then(function (r) { if ((r.headers.get('content-type') || '').indexOf('json') < 0) throw new Error('session'); return r.json(); });
    }
    function failed(e) { say(e && e.message === 'session' ? 'Your session ended: reload the page and sign in again.' : 'Couldn\'t save: check your connection.', null, true); }
    function cards() { return [].slice.call(root.querySelectorAll('.ed-folder')); }
    function match(el) { var k = (el.getAttribute('data-kinds') || '').split(' '); return state === 'all' || k.indexOf(state) >= 0; }
    // How many thumbnails make two rows at the card's width, the "+N" tile counting as one.
    function limitFor(strip) {
      var w = strip.clientWidth || strip.parentNode.clientWidth || 600, per = Math.max(2, Math.floor((w + GAP) / (TH + GAP)));
      return Math.max(3, per * 2 - 1);
    }
    function syncEdTools() {   // only the one that can do something: all closed, Expand all; all open, Collapse all; some of each, both
      var all = cards(), open = all.filter(function (c) { return c.classList.contains('open'); }).length;
      var e = document.querySelector('[data-ed-expand=all]'), c = document.querySelector('[data-ed-expand=none]'), sep = document.querySelector('.ed-sep');
      if (!e || !c) return;
      e.hidden = all.length > 0 && open === all.length; c.hidden = open === 0; if (sep) sep.hidden = e.hidden || c.hidden;
    }
    function apply() {
      syncEdTools();
      cards().forEach(function (card) {
        var path = card.getAttribute('data-path'), counts = { private: 0, caption: 0, link: 0, location: 0 }, shown = 0, thumbs = [];
        [].forEach.call(card.querySelectorAll('.ed-it'), function (el) {
          var ok = match(el);
          if (el.classList.contains('ed-sm')) { if (ok) thumbs.push(el); else el.hidden = true; }
          else el.hidden = !ok;
          if (ok) { shown++; (el.getAttribute('data-kinds') || '').split(' ').forEach(function (k) { if (counts[k] !== undefined) counts[k]++; }); }
        });
        var strip = card.querySelector('.ed-strip'), lim = 0;
        if (strip) { strip.hidden = thumbs.length === 0; if (thumbs.length) lim = limitFor(card.querySelector('.ed-sth')); }
        var rest = Math.max(0, thumbs.length - lim);
        // everything fits when what is over would be one tile or fewer: the last photo beats a "+1"
        var showAll = expanded[path] || rest <= 1;
        thumbs.forEach(function (el, i) { el.hidden = !(showAll || i < lim); });
        var more = card.querySelector('.ed-more');
        if (more) { more.hidden = showAll || rest === 0; more.textContent = '+' + (thumbs.length - lim); }
        var fs = card.querySelector('.ed-fset'), fsOk = !!fs && (state === 'all' || state === 'folder');
        if (fs) fs.hidden = !fsOk || !fs.children.length;
        var sum = card.querySelector('.ed-fsum'); sum.textContent = '';
        if (counts.private) sum.appendChild(tag('ed-priv', counts.private + ' private'));
        if (counts.caption) sum.appendChild(tag('ed-cap', plural(counts.caption, 'caption', 'captions')));
        if (counts.link) sum.appendChild(tag('ed-cap', plural(counts.link, 'link', 'links')));
        if (counts.location) sum.appendChild(tag('ed-loc', plural(counts.location, 'location', 'locations')));
        if (fsOk) sum.appendChild(tag('', plural(+fs.getAttribute('data-n'), 'folder setting', 'folder settings')));
        card.hidden = shown === 0 && !fsOk;
      });
      [].forEach.call(root.querySelectorAll('.ed-chip'), function (c) { c.setAttribute('aria-pressed', c.getAttribute('data-k') === state ? 'true' : 'false'); });
    }
    function setOpen(card, open) {
      var head = card.querySelector('.ed-head'), body = card.querySelector('.ed-body');
      card.classList.toggle('open', open); head.setAttribute('aria-expanded', open ? 'true' : 'false'); body.hidden = !open;
    }
    function refresh() {
      var openPaths = cards().filter(function (c) { return c.classList.contains('open'); }).map(function (c) { return c.getAttribute('data-path'); });
      return fetch(location.pathname + location.search, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.text(); }).then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html'), fresh = doc.getElementById('ed-root');
        if (!fresh) { location.reload(); return; }
        root.innerHTML = fresh.innerHTML;
        cards().forEach(function (c) { if (openPaths.indexOf(c.getAttribute('data-path')) >= 0) setOpen(c, true); });
        apply();
        var c1 = document.querySelector('.side-hidden .count'), c2 = doc.querySelector('.side-hidden .count');
        if (c1 && c2) c1.textContent = c2.textContent;
      });
    }
    function setPhoto(folder, bare, fields, done) {
      var f = { action: 'set_item_flag', file: bare, flag: 'private' };
      Object.keys(fields).forEach(function (k) { f[k] = fields[k]; });
      return post(folder, f).then(function (j) { if (!j.ok) throw new Error(j.text || 'Not saved'); return refresh(); }).then(done);
    }
    root.addEventListener('click', function (e) {
      var t = e.target; if (!t.closest) return;
      var chip = t.closest('.ed-chip');
      if (chip) { state = chip.getAttribute('data-k'); apply(); return; }
      var lock = t.closest('.pv-lock');
      if (lock) {
        e.preventDefault();
        var own = lock.getAttribute('data-own'), bare = lock.getAttribute('data-bare'), folder = lock.getAttribute('data-folder');
        lock.disabled = true;
        // The lock switches a photo between private and public: private becomes public (inside a private folder that is an explicit
        // "public", so the photo stays in the list, with an open lock) and public becomes private again. It never drops to "follows the folder".
        setPhoto(folder, bare, own === 'private' ? { on: '0' } : { state: 'private' }, function () {
          say(own === 'private' ? 'Made public.' : 'Made private again.', function () {
            setPhoto(folder, bare, { state: own }, function () { say('Put back.'); }).catch(failed);
          });
        }).catch(function (err) { lock.disabled = false; if (err && err.message && err.message !== 'session' && err.message !== 'Failed to fetch') say(err.message, null, true); else failed(err); });
        return;
      }
      var mk = t.closest('.pv-makepub');
      if (mk && modal) {
        e.preventDefault();
        var folder2 = mk.getAttribute('data-folder');
        fetch('?admin&folder=' + encodeURIComponent(folder2) + '&privcounts=1', { credentials: 'same-origin', cache: 'no-store' })
          .then(function (r) { if ((r.headers.get('content-type') || '').indexOf('json') < 0) throw new Error('session'); return r.json(); })
          .then(function (j) {
            var c = j.counts || { stay: 0, open: 0 };
            document.getElementById('pt-folder').textContent = mk.getAttribute('data-label') || folder2;
            document.getElementById('pt-stay').textContent = word(c.stay); document.getElementById('pt-open').textContent = word(c.open);
            document.getElementById('pt-stay-v').textContent = c.stay === 1 ? 'stays' : 'stay'; document.getElementById('pt-open-v').textContent = c.open === 1 ? 'becomes' : 'become';
            pending = { folder: folder2, from: mk }; modal.hidden = false; document.getElementById('pt-cancel').focus();
          }).catch(failed);
        return;
      }
      var head = t.closest('.ed-head');
      if (head) { var card = head.parentNode; setOpen(card, !card.classList.contains('open')); apply(); return; }
      var all = t.closest('[data-ed-expand]');
      if (all) { var want = all.getAttribute('data-ed-expand') === 'all'; cards().forEach(function (c) { setOpen(c, want); }); apply(); return; }
      var more = t.closest('.ed-more');
      if (more) { expanded[more.closest('.ed-folder').getAttribute('data-path')] = true; apply(); }
    });
    function closeModal() { if (modal) modal.hidden = true; if (pending && pending.from && document.body.contains(pending.from)) pending.from.focus(); }
    if (modal) {
      document.getElementById('pt-cancel').addEventListener('click', closeModal);
      modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
      modal.addEventListener('keydown', function (e) { if (e.key === 'Escape') { e.preventDefault(); closeModal(); } });
      document.getElementById('pt-ok').addEventListener('click', function () {
        var target = pending; modal.hidden = true; if (!target) return;
        post(target.folder, { action: 'toggle_folder_restriction' }).then(function (j) {
          if (!j.ok) throw new Error(j.text || 'Not saved');
          return refresh();
        }).then(function () { say('The folder is public now. The photos you marked private stay private.'); }).catch(failed);
      });
    }
    window.addEventListener('resize', function () { apply(); });
    apply();
  })();
  // The Shares tab: make, change, pause, revoke, replace and delete links. Every change is a small POST; the tab is then fetched
  // again so what it shows is what is stored, and the rows that were open stay open. Plain events on the tab itself, so the
  // fresh copy keeps working.
  (function () {
    var box = document.getElementById('sl-root'); if (!box) return;
    var csrf = box.getAttribute('data-csrf') || '';
    var toastTimer = null, pretty = null;
    var st = { scope: [], pub: [], downloads: [], zip: [], photos: [] };   // the folders and photos the form is holding, as paths (pub: the chosen folders that show only their public items)
    function modalEl() { return document.getElementById('sl-modal'); }   // (looked up each time: the tab's own markup is replaced when it refreshes)
    function say(text, bad) {
      var toast = document.getElementById('sl-toast');
      clearTimeout(toastTimer); toast.className = 'pv-toast' + (bad ? ' err' : ''); toast.textContent = text; toast.hidden = false;
      var selBarNow = document.getElementById('sl-bulk'); toast.style.bottom = selBarNow && !selBarNow.hidden ? Math.round(innerHeight - selBarNow.getBoundingClientRect().top + 10) + 'px' : '';   // (above the selection bar, not on it)
      toastTimer = setTimeout(function () { toast.hidden = true; }, bad ? 6000 : 3500);
    }
    function post(fields) {
      var body = new URLSearchParams(); body.set('csrf_token', csrf);
      Object.keys(fields).forEach(function (k) { body.set(k, fields[k]); });
      return fetch('?admin', { method: 'POST', credentials: 'same-origin', body: body.toString(),
          headers: { 'X-Requested-With': 'gallery-fetch', 'Content-Type': 'application/x-www-form-urlencoded' } })
        .then(function (r) { if ((r.headers.get('content-type') || '').indexOf('json') < 0) throw new Error('session'); return r.json(); });
    }
    function failed(e) { say(e && e.message === 'session' ? 'Your session ended: reload the page and sign in again.' : 'Couldn\'t save: check your connection.', true); }
    function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function times() {
      [].forEach.call(box.querySelectorAll('time.sl-time[data-ts]'), function (t) {
        var d = new Date(+t.getAttribute('data-ts') * 1000); if (isNaN(d)) return;
        t.textContent = d.toLocaleString(undefined, { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
      });
    }
    function probe() {
      var url = box.getAttribute('data-probe'); if (!url) return;
      fetch(url, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { pretty = r.headers.get('X-Gallery-Link') === 'invalid'; }).catch(function () { pretty = false; }).then(function () {
        [].forEach.call(box.querySelectorAll('.sl-url'), function (i) { i.value = pretty ? i.getAttribute('data-short') : i.getAttribute('data-long'); });
      });
    }
    function refresh(light) {   // light: the window came back; only this panel is asked for (not the whole page), and the sidebar's numbers are the page's other part's business
      var openIds = [].map.call(box.querySelectorAll('details.sl-row[open]'), function (d) { return d.getAttribute('data-id'); });
      var formOpen = !box.querySelector('#sl-form').hidden;
      return fetch(light ? '?admin&linkspanel=1' : location.pathname + location.search, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.text(); }).then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html'), fresh = doc.getElementById('sl-root');
        if (!fresh) { location.reload(); return; }
        var keepForm = formOpen ? box.querySelector('#sl-form') : null;
        box.innerHTML = fresh.innerHTML;
        if (keepForm) box.querySelector('#sl-form').replaceWith(keepForm);
        [].forEach.call(box.querySelectorAll('details.sl-row'), function (d) { if (openIds.indexOf(d.getAttribute('data-id')) >= 0) d.open = true; });
        times(); pretty = null; probe();
        var sideNow = document.querySelector('.side-link[data-tab="links"]'), sideNew = doc.querySelector('.side-link[data-tab="links"]');
        if (sideNow && sideNew) sideNow.innerHTML = sideNew.innerHTML;
        mountPanels();
        if (window.__slSelApply) window.__slSelApply();
      });
    }
    // ---- Copy / Share… / QR code under a row's address, drawn when the row opens (the pieces come from the share kit)
    function mountPanels() {
      if (!window.GalleryShare) return;
      [].forEach.call(box.querySelectorAll('details.sl-row[open] .gs-mount'), function (m) {
        var row = rowOf(m);
        window.GalleryShare.mount(m, { copy: m.getAttribute('data-copy') === '1', name: m.getAttribute('data-name') || '', url: function () { var u = row.querySelector('.sl-url'); return u ? u.value : ''; } });
      });
    }
    function rowOf(el) { return el.closest ? el.closest('details.sl-row') : null; }
    function coverLines(root) {   // the Preview photo line of a link's settings: the picture it will use, or that nothing public is there yet
      [].forEach.call(root.querySelectorAll('.sl-cover[data-cover-id]:not([data-done])'), function (el) {
        el.setAttribute('data-done', '1');
        fetch('?admin&linkcover=1&id=' + encodeURIComponent(el.getAttribute('data-cover-id')), { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (j) {
          el.innerHTML = j.file ? '<img class="sl-covimg" src="' + esc(j.img) + '" alt=""> <span>' + (j.how === 'chosen' ? 'Your choice' : 'Picked automatically') + ' <span class="muted">' + esc(j.name) + '</span></span>' : 'On, but nothing public to use yet';
        }).catch(function () { el.removeAttribute('data-done'); });
      });
    }
    box.addEventListener('toggle', function (e) {
      var d = e.target;
      if (d && d.classList && d.classList.contains('sl-settings') && d.open) coverLines(d);
      if (d && d.classList && d.classList.contains('sl-row') && d.open) mountPanels();
    }, true);
    // ---- menus (the choices in the link form and its dialogs): a button that opens a list under it.
    // A menu of several ticks applies as each one is made (a moment later, so quick ticks make one request) and stays open;
    // a menu of one choice applies at once and closes. "All" is the first row: ticked while nothing else is.
    function popValues(pop) { return [].map.call(pop.querySelectorAll('input:checked:not([data-all])'), function (i) { return i.value; }); }
    function popClose(pop) {
      var menu = pop.querySelector('.sl-popmenu'); if (!menu || menu.hidden) return;
      menu.hidden = true; pop.querySelector('.sl-popbtn').setAttribute('aria-expanded', 'false');
    }
    function popCloseAll(except) { [].forEach.call(box.querySelectorAll('.sl-pop'), function (p) { if (p !== except) popClose(p); }); }
    function popApply(pop) {   // a menu in the form or a dialog keeps its choice in a hidden field and says it on its button
      var r = pop.querySelector('input[type=radio]:checked'), h = pop.querySelector('input[type=hidden]');
      if (r) pop.querySelector('.sl-poplab').textContent = r.getAttribute('data-short') || r.parentElement.textContent.trim();
      if (h) { h.value = r ? r.value : ''; h.dispatchEvent(new Event('change', { bubbles: true })); }
    }
    box.addEventListener('click', function (e) {
      var btn = e.target.closest ? e.target.closest('.sl-popbtn') : null, pop = btn ? btn.closest('.sl-pop') : null;
      if (!pop) { if (!(e.target.closest && e.target.closest('.sl-popmenu'))) popCloseAll(null); return; }
      if (btn.disabled) return;
      popCloseAll(pop);
      var menu = pop.querySelector('.sl-popmenu');
      if (menu.hidden) { menu.hidden = false; btn.setAttribute('aria-expanded', 'true'); } else popClose(pop);
    });
    box.addEventListener('change', function (e) {
      var inp = e.target, pop = inp.closest ? inp.closest('.sl-pop') : null;
      if (!pop || inp.type === 'hidden') return;
      if (pop.getAttribute('data-multi') === '1') {
        var all = pop.querySelector('input[data-all]');
        if (inp.hasAttribute('data-all')) { [].forEach.call(pop.querySelectorAll('input:not([data-all])'), function (i) { i.checked = false; }); inp.checked = true; }
        else if (all) all.checked = popValues(pop).length === 0;
        clearTimeout(pop._t); pop._t = setTimeout(function () { pop._t = null; popApply(pop); }, 220);
      } else { popClose(pop); popApply(pop); }
    });
    box.addEventListener('keydown', function (e) { if (e.key === 'Escape') popCloseAll(null); });
    function markSeen() { if (box.querySelector('.sl-newt')) post({ action: 'link_seen' }).catch(function () {}); }
    window.__slSeen = markSeen;
    // The window came back: the list (states, opens, what is new) is drawn again, unless the person is in the middle of something here (the form, selecting several, a menu, a field).
    if (window.__live) window.__live.register('shares', { tab: 'links', refresh: function () {
      return refresh(true).then(function () { if (window.__slSeen) setTimeout(window.__slSeen, 1500); });
    }, busy: function () {
      var bar = document.getElementById('sl-bulk'), f = box.querySelector('#sl-form'), el = document.activeElement;
      return (!!f && !f.hidden) || (!!bar && !bar.hidden) || !!box.querySelector('.sl-popmenu:not([hidden])') || (!!el && box.contains(el) && /^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName));
    } });
    function copy(text, done) {
      if (window.GalleryShare && window.GalleryShare.copyText) { window.GalleryShare.copyText(text, done); return; }   // (the share kit's copy: it never fails the first press)
      if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(done, function () {}); return; }
      var ta = document.createElement('textarea'); ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0'; document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); done(); } catch (e) {} document.body.removeChild(ta);
    }
    // ---- the question box
    var asking = null;
    function ask(title, bodyHtml, okText, danger, extraText) {   // resolves true (OK), false (Cancel), or 'extra' (the optional third button, last)
      return new Promise(function (resolve) {
        var modal = modalEl();
        document.getElementById('sl-modal-title').textContent = title;
        modal.querySelector('.sl-modal-body').innerHTML = bodyHtml;
        var ok = modal.querySelector('[data-sl-modal=ok]'); ok.textContent = okText; ok.className = danger ? 'sl-danger-btn' : ''; ok.disabled = false;
        var extra = modal.querySelector('[data-sl-modal=extra]'); extra.hidden = !extraText; extra.textContent = extraText || '';
        asking = function (yes) { asking = null; modal.hidden = true; resolve(yes); };
        modal.hidden = false; modal.querySelector('[data-sl-modal=cancel]').focus();
      });
    }
    box.addEventListener('click', function (e) {
      var b = e.target.closest ? e.target.closest('[data-sl-modal]') : null;
      var which = b ? b.getAttribute('data-sl-modal') : '';
      if (b && asking) asking(which === 'extra' ? 'extra' : which === 'ok'); else if (e.target === modalEl() && asking) asking(false);
    });
    box.addEventListener('keydown', function (e) { if (e.key === 'Escape' && asking) { e.preventDefault(); asking(false); } });
    // ---- the form
    // How many browsers a new link lets in (the setting; 0 is unlimited): where the limit row starts, what Reset goes back to, and what the "changed" marks compare with.
    var DEFAULT_USES = +(document.getElementById('sl-root').getAttribute('data-default-uses') || 0);
    var USES_PRESET = { 0: '0', 1: '1', 2: '2', 5: '5', 10: '10' };
    function setUses(form, n) { if (USES_PRESET[n] !== undefined) radio(form, 'opens_pick', USES_PRESET[n]); else { radio(form, 'opens_pick', 'n'); form.querySelector('[name=opens_other]').value = n; } }
    function usesOf(f) { return f.opens === 'n' ? (+f.opensN || 0) : +f.opens; }
    function usesClause(n) { return n === 0 ? 'in as many browsers as they like' : n === 1 ? 'in just one browser' : 'in up to ' + n + ' browsers'; }
    var DEFAULTS = { label: '', address: '', note: '', titleMode: 'gallery', titleText: '', picture: true, pictureSeed: '', pictureFile: '', maxOpens: DEFAULT_USES, startsAt: 0, endsAt: 0, locations: 'public', headsUp: 'default',
      scopeType: 'public', only: false, scopeFolders: [], scopePublicOnly: [], scopePhotos: [], openIn: '', downloads: 'off', downloadsFolders: [], zip: 'off', zipFolders: [] };
    function localValue(ts) {
      var d = new Date(ts * 1000), p = function (n) { return (n < 10 ? '0' : '') + n; };
      return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + 'T' + p(d.getHours()) + ':' + p(d.getMinutes());
    }
    function epoch(value) { var t = new Date(value).getTime(); return isNaN(t) ? 0 : Math.floor(t / 1000); }
    function radio(form, name, value) { var r = form.querySelector('input[name="' + name + '"][value="' + value + '"]'); if (r) r.checked = true; }
    function radioValue(form, name) { var r = form.querySelector('input[name="' + name + '"]:checked'); return r ? r.value : ''; }
    function folderList(form) { try { return JSON.parse(form.getAttribute('data-folders') || '[]'); } catch (x) { return []; } }
    function labelOf(form, path) { var f = folderList(form).filter(function (o) { return o.p === path; })[0]; return f ? f.l : path; }
    function under(path, roots) { return roots.some(function (r) { return path === r || path.indexOf(r + '/') === 0; }); }
    function chipsHtml(form, kind) {
      var list = st[kind] || [];
      return list.map(function (p) {
        var mode = '';
        if (kind === 'scope') {   // (each folder the link covers: everything inside it, or only its public items)
          var on = st.pub.indexOf(p) >= 0, priv = folderList(form).some(function (o) { return o.p === p && o.x; });
          mode = '<button type="button" class="sl-chipmode' + (on ? ' on' : '') + '" data-chip-mode data-p="' + esc(p) + '"' + (priv ? ' disabled title="This folder is private as a whole, so nothing in it is public"' : ' title="' + (on ? 'Only the public photos and videos in it are shown. Press for everything inside it.' : 'Everything inside it is shown, private photos and videos too. Press for only the public ones.') + '"') + ' aria-pressed="' + (on ? 'true' : 'false') + '">' + (on ? 'Public items only' : 'Everything') + '</button>';
        }
        return '<span class="sl-chip">' + esc(labelOf(form, p)) + mode + ' <button type="button" data-chip-x="' + kind + '" data-p="' + esc(p) + '" aria-label="Take it out">×</button></span>';
      }).join('')
        + '<button type="button" class="linklike sl-add" data-chip-add="' + kind + '">+ Add folder</button>';
    }
    // A one-choice menu for the form and its dialogs (the same look and behaviour as the filter menus): rows are { v: value, t: text, s: short text for the button, d: depth in the tree }; the choice is kept in a hidden field.
    function popHtml(key, name, rows, chosen, aria, disabled) {
      var cur = rows.filter(function (r) { return r.v === chosen; })[0] || rows[0];
      return '<div class="sl-pop sl-popform" data-pop="' + key + '" data-multi="0"><button type="button" class="sl-popbtn" aria-haspopup="true" aria-expanded="false" aria-label="' + esc(aria) + '"' + (disabled ? ' disabled' : '') + '><span class="sl-poplab">' + esc(cur.s || cur.t) + '</span><span class="sl-caret" aria-hidden="true">&#9662;</span></button><div class="sl-popmenu" hidden>'
        + rows.map(function (r) { return '<label class="sl-popopt' + (r.d ? ' sl-d' + Math.min(r.d, 6) : '') + '"><input type="radio" name="pop-' + key + '" value="' + esc(r.v) + '" data-short="' + esc(r.s || r.t) + '"' + (r === cur ? ' checked' : '') + '><span>' + esc(r.t) + '</span></label>'; }).join('')
        + '</div><input type="hidden" name="' + name + '" value="' + esc(cur.v) + '"></div>';
    }
    function folderRows(form, skip) {
      return folderList(form).filter(function (o) { return !(skip && skip(o)); }).map(function (o) { return { v: o.p, t: (o.x ? '🔒 ' : '') + (o.n || o.l), s: o.l, d: o.d || 0 }; });
    }
    // the preview photo the link will use, shown as soon as the box is ticked (the server works it out: the same rule the opening page uses)
    var coverTok = 0;
    // What the link covers, as the form holds it now, for the requests that choose or show the preview photo (it may only come from what the link covers).
    function scopeQuery(form) {
      var sc = radioValue(form, 'scope_type');
      return '&scope_type=' + encodeURIComponent(sc) + '&scope_folders=' + encodeURIComponent(JSON.stringify(sc === 'folders' || sc === 'mixed' ? st.scope : [])) + '&scope_photos=' + encodeURIComponent(JSON.stringify(sc === 'photos' || sc === 'mixed' ? st.photos : []));
    }
    function coverRefresh(form) {
      var row = form.querySelector('[data-coverrow]'), on = form.querySelector('[name=picture]').checked;
      row.hidden = !on; if (!on) return;
      var seed = form.querySelector('[name=picture_seed]').value, file = form.querySelector('[name=picture_file]').value, id = form.querySelector('[name=id]').value, mine = ++coverTok;
      row._loaded = true;
      fetch('?admin&linkcover=1&id=' + encodeURIComponent(id) + '&seed=' + encodeURIComponent(seed) + '&file=' + encodeURIComponent(file) + scopeQuery(form), { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (j) {
        if (mine !== coverTok) return;
        var img = row.querySelector('img'), how = row.querySelector('[data-cover-how]'), nm = row.querySelector('[data-cover-name]');
        if (j.file) { img.src = j.img; img.hidden = false; how.textContent = j.how === 'chosen' ? 'Your choice' : (file ? 'Picked automatically (your choice is not public, or not covered by the link, any more)' : 'Picked automatically'); nm.textContent = j.name; }
        else { img.hidden = true; how.textContent = 'Nothing public to use yet'; nm.textContent = 'The link shows the gallery\'s icon until a photo is public.'; }
        row.querySelector('[data-cover-auto]').hidden = !file;
      }).catch(function () {});
    }
    function syncForm(form) {
      var scope = radioValue(form, 'scope_type'), dl = radioValue(form, 'downloads'), zp = radioValue(form, 'zip');
      form.querySelector('[name=title_text]').hidden = radioValue(form, 'title_mode') !== 'custom';
      form.querySelector('[name=starts_at_local]').hidden = radioValue(form, 'starts_pick') !== 'pick';
      form.querySelector('[name=opens_other]').hidden = radioValue(form, 'opens_pick') !== 'n';
      form.querySelector('[name=ends_at_local]').hidden = radioValue(form, 'ends_pick') !== 'pick';
      var sc = form.querySelector('[data-chips=scope]');
      var photosHtml = function () {
        return '<div class="sl-pths">' + st.photos.map(function (p) {
          return '<span class="sl-pth" title="' + esc(p) + '"><img src="?img=' + encodeURIComponent(p) + '&size=thumb" alt=""><button type="button" data-chip-x="photos" data-p="' + esc(p) + '" aria-label="Take ' + esc(p.split('/').pop()) + ' out">×</button></span>';
        }).join('') + '<button type="button" class="sl-padd" data-chip-add="photos">+ Add photos</button></div>';
      };
      if (scope === 'folders') { sc.hidden = false; sc.innerHTML = chipsHtml(form, 'scope'); }
      else if (scope === 'photos') { sc.hidden = false; sc.innerHTML = photosHtml(); }
      else if (scope === 'mixed') { sc.hidden = false; sc.innerHTML = '<div class="sl-mixg"><div class="sl-mixl">Folders <small>(each with everything inside it, or only its public items)</small></div><div class="sl-mixc">' + chipsHtml(form, 'scope') + '</div></div><div class="sl-mixg"><div class="sl-mixl">Photos</div>' + photosHtml() + '</div>'; }
      else sc.hidden = true;
      form.querySelector('[data-only-row]').hidden = scope === 'all' || scope === 'public';
      [['downloads', dl], ['zip', zp]].forEach(function (pair) {
        var c = form.querySelector('[data-chips=' + pair[0] + ']'); c.hidden = pair[1] !== 'some'; if (pair[1] === 'some') c.innerHTML = chipsHtml(form, pair[0]);
        var pubOpt = form.querySelector('input[name=' + pair[0] + '][value=public]');
        if (pubOpt) { pubOpt.parentNode.style.display = scope === 'public' ? 'none' : ''; if (scope === 'public' && pubOpt.checked) radio(form, pair[0], 'all'); }
      });
      // where it opens: the front page, or a folder (among the folders it covers, when it is limited)
      var holder = form.querySelector('[data-openin-menu]'), curIn = holder.querySelector('[name=open_in]'), keep = holder.hasAttribute('data-want') ? holder.getAttribute('data-want') : (curIn ? curIn.value : '');
      holder.removeAttribute('data-want');
      var list = scope === 'folders' || scope === 'mixed' ? st.scope.map(function (p) { return { v: p, t: labelOf(form, p), d: 0 }; }) : (scope === 'photos' ? [] : folderRows(form, scope === 'public' ? function (o) { return o.x; } : null));
      var rows = [{ v: '', t: scope === 'photos' ? 'The first photo' : 'The front page', d: 0 }].concat(list);
      holder.innerHTML = popHtml('openin', 'open_in', rows, list.some(function (r) { return r.v === keep; }) ? keep : '', 'Opens in', scope === 'photos');
      form.querySelector('[data-openin] .sl-h').textContent = scope === 'photos' ? 'Straight into the viewer, on the first photo.' : 'The first page they see.';
      var crow = form.querySelector('[data-coverrow]'); crow.hidden = !form.querySelector('[name=picture]').checked;
      var coverKey = scope + '|' + st.scope.join(',') + '|' + st.photos.join(',');
      if (crow._key !== coverKey) { crow._key = coverKey; crow._loaded = false; }   // (what the link covers decides which photos the automatic picture may come from)
      if (!crow.hidden && !crow._loaded) coverRefresh(form);
      summarize(form);
    }
    // The address field: the name turned into an address in one press, and what to know about the one in the field.
    function slugName(t) {
      var s = String(t || ''); if (s.normalize) s = s.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
      return s.toLowerCase().replace(/[^a-z0-9]+/g, '').slice(0, 40);
    }
    // Three ways to have the address: the name (letters and digits only), a name typed in, or a random code. The field named "address" always holds
    // the final value ('' = random), so the rest of the form needs no change.
    // Does this link, as the form stands, open anything that is private? A folder counts if it is private, is inside a private folder, or has a
    // private photo or folder inside it; a photo if it is private itself or sits in a private folder without being made public.
    function opensPrivate(form) {
      var pv; try { pv = JSON.parse(form.getAttribute('data-private') || '{}'); } catch (x) { pv = {}; }
      var pf = pv.f || [], pi = pv.i || [], ps = pv.s || [];
      var inside = function (p, root) { return p === root || p.indexOf(root + '/') === 0; };
      var folderPriv = function (p) { if (p === '') return pf.length > 0 || pi.length > 0; return pf.some(function (r) { return inside(p, r) || inside(r, p); }) || pi.some(function (i) { return inside(i, p); }); };
      var photoPriv = function (p) { return pi.indexOf(p) >= 0 || (ps.indexOf(p) < 0 && pf.some(function (r) { return inside(p, r); })); };
      var sc = radioValue(form, 'scope_type');
      if (sc === 'public') return false;
      if (sc === 'all') return pf.length > 0 || pi.length > 0;
      return (sc !== 'photos' && st.scope.some(function (p) { return st.pub.indexOf(p) < 0 && folderPriv(p); })) || (sc !== 'folders' && st.photos.some(photoPriv));
    }
    function addrSync(form) {
      var mode = form._addrMode || 'name', ck = form.querySelector('[name=addr_name]'), inp = form.querySelector('[name=address]'), row = form.querySelector('[data-addrrow]'), prev = form.querySelector('[data-addrprev]');
      ck.checked = mode === 'name'; row.hidden = mode !== 'custom';
      form.querySelector('[data-addrnote]').hidden = !(mode === 'name' && opensPrivate(form));   // (a name can be guessed: said whenever a link that opens private photos has its address made from the name)
      if (mode === 'name') inp.value = slugName(form.querySelector('[name=label]').value); else if (mode === 'random') inp.value = '';
      prev.textContent = mode === 'name' ? '…/' + (inp.value || 'auntcarol') : '';
      addrHint(form);
    }
    function setAddrMode(form, mode, focus) {
      form._addrMode = mode; addrSync(form);
      if (focus && mode === 'custom') form.querySelector('[name=address]').focus();
    }
    function addrHint(form) {
      var inp = form.querySelector('[name=address]'), h = form.querySelector('[data-addrhint]'), v = inp.value, was = inp.getAttribute('data-was') || '', mode = form._addrMode || 'name', msg;
      // (the choice to keep the old address working is offered only when an address that exists is being changed)
      var row = form.querySelector('[data-keeprow]'), keepBox = form.querySelector('[name=keep_old]');
      var wasHidden = row.hidden;
      row.hidden = !(inp.getAttribute('data-editing') === '1' && v !== was);
      if (row.hidden) keepBox.checked = false; else if (wasHidden) keepBox.checked = true;   // (ticked whenever it appears: the address that was sent out is meant to keep working)
      var kept = keepBox.checked, ending = kept ? ' The old address keeps working and sends people on to the new one; anyone signed in through it is signed out.' : ' The old address is paused: it shows the “not valid” page (you can resume it from Settings), and anyone signed in through it is signed out.';
      if (mode === 'random') { h.innerHTML = 'A random code is made for you, one that can\'t be guessed.' + (was !== '' ? esc(ending) : '') + ' <button type="button" class="linklike" data-sl="addrcustom">Type my own instead</button>'; return; }
      if (mode === 'name') msg = v === '' ? '' : (v.length < 4 ? 'An address needs at least 4 letters or digits: add to the name, or untick the box.' : (v.length < 12 && !opensPrivate(form) ? 'A name can be guessed by someone who tries. A random address can\'t be.' : '')) + (was !== v && was !== '' ? ending : '');
      else if (v === '') msg = was ? 'Saving with this empty gives the link a new random address.' + ending : 'Type the address, or press Random.';
      else if (v.length < 4) msg = 'At least 4 letters, digits or hyphens.';
      else msg = (v.length < 12 ? 'A short name can be guessed by someone who tries. A random address can\'t be.' : 'Anyone who learns or guesses this name can open the link.') + (was !== v && was !== '' ? ending : '');
      h.textContent = msg;
    }
    // ---- the form's plain-words summary, and the four folded rows: what differs from the defaults is set off, in the sentence and on the row
    function formState(form) {
      var q = function (n) { var e = form.querySelector('[name=' + n + ']'); return e ? e.value : ''; };
      return { scope: radioValue(form, 'scope_type'), openIn: q('open_in'), opens: radioValue(form, 'opens_pick'), opensN: q('opens_other'), ends: radioValue(form, 'ends_pick'), endsAt: q('ends_at_local'),
        starts: radioValue(form, 'starts_pick'), startsAt: q('starts_at_local'), dl: radioValue(form, 'downloads'), zip: radioValue(form, 'zip'), loc: radioValue(form, 'locations'),
        title: radioValue(form, 'title_mode'), pic: form.querySelector('[name=picture]').checked, picFile: q('picture_file'), note: q('note').trim(), heads: radioValue(form, 'heads_up') };
    }
    function fdate(v) { var t = new Date(v); return isNaN(t.getTime()) || v === '' ? 'the date you pick' : t.toLocaleString(undefined, { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }); }
    var DL_SAY = { off: 'off', all: 'everything', 'public': 'everything but private', some: 'some folders' };
    function summarize(form) {
      var addrNote = form.querySelector('[data-addrnote]'); if (addrNote) addrNote.hidden = !((form._addrMode || 'name') === 'name' && opensPrivate(form));
      var f = formState(form), form_ = form, names = function (list) { return list.map(function (p) { return labelOf(form_, p); }).join(', '); };
      var scopeNames = function () { return st.scope.map(function (p) { return labelOf(form_, p) + (st.pub.indexOf(p) >= 0 ? ' (public items only)' : ''); }).join(', '); };
      var scopeSay = f.scope === 'public' ? 'the public gallery' : f.scope === 'all' ? 'the whole gallery, public and private' : f.scope === 'folders' ? (st.scope.length ? scopeNames() : 'the folders you pick') : f.scope === 'mixed' ? ((st.scope.length ? scopeNames() : 'no folders yet') + ' and ' + (st.photos.length ? st.photos.length + ' photo' + (st.photos.length === 1 ? '' : 's') : 'no photos yet')) : (st.photos.length ? st.photos.length + ' photo' + (st.photos.length === 1 ? '' : 's') + ' you picked' : 'the photos you pick');
      var usesNow = usesOf(f), usesIsDefault = usesNow === DEFAULT_USES && (f.opens !== 'n' || f.opensN !== '');
      var endsSay = f.ends === '0' ? 'never stops working' : f.ends === 'pick' ? 'stops working on ' + fdate(f.endsAt) : 'stops working after ' + ({ '86400': '1 day', '604800': '1 week', '2592000': '30 days' }[f.ends] || '');
      var titleSay = { gallery: 'the gallery\'s title', neutral: 'a neutral title', custom: 'a title of your own' }[f.title];
      var picSay = !f.pic ? 'no preview photo' : f.picFile ? 'a preview photo you chose' : 'an automatic preview photo';
      var cl = [
        ['Anyone with the link can see ' + scopeSay + '.', f.scope !== 'public'],
        ['It can be opened ' + (f.opens === 'n' && !f.opensN ? 'in up to ? browsers' : usesClause(usesNow)) + ',', !usesIsDefault],
        ['and ' + (f.ends === '0' ? 'never stops working.' : endsSay + '.') + (f.starts === 'pick' ? ' It starts on ' + fdate(f.startsAt) + '.' : ''), f.ends !== '0' || f.starts !== 'now'],
        [f.dl === 'off' && f.zip === 'off' ? 'Downloads and ZIP are off.' : 'Downloads: ' + DL_SAY[f.dl] + '; ZIP: ' + DL_SAY[f.zip] + '.', f.dl !== 'off' || f.zip !== 'off'],
        [f.loc === 'public' ? 'Photo locations are shown only where you set them to show.' : 'All saved locations are shown.', f.loc !== 'public'],
        ['The opening page uses ' + titleSay + ' and ' + picSay + ', ' + (f.note ? 'with a note.' : 'with no note.') + (f.heads === 'default' ? '' : ' The heads-up line is ' + f.heads + '.'), f.title !== 'gallery' || !f.pic || f.picFile !== '' || f.note !== '' || f.heads !== 'default']
      ];
      [].forEach.call(form.querySelectorAll('[data-cl]'), function (el) { var c = cl[+el.getAttribute('data-cl') - 1]; el.textContent = c[0]; el.className = c[1] ? 'sl-cl-ch' : 'sl-cl'; });
      var sums = {
        scope: [(f.scope === 'public' ? 'Just the public gallery' : f.scope === 'all' ? 'Everything' : f.scope === 'folders' ? 'Only some folders' + (st.scope.length ? ': ' + names(st.scope) : '') : f.scope === 'mixed' ? 'Some folders and some photos' + (st.scope.length || st.photos.length ? ' (' + st.scope.length + ' folder' + (st.scope.length === 1 ? '' : 's') + ', ' + st.photos.length + ' photo' + (st.photos.length === 1 ? '' : 's') + ')' : '') : 'Only some photos' + (st.photos.length ? ' (' + st.photos.length + ')' : '')), f.scope !== 'public' || f.openIn !== ''],
        limits: [[usesNow === 0 && f.opens !== 'n' ? 'Unlimited browsers' : usesNow === 1 ? 'Just one browser' : (f.opens === 'n' ? (f.opensN || '?') : f.opens) + ' browsers', f.ends === '0' ? 'never stops' : f.ends === 'pick' ? 'stops ' + fdate(f.endsAt) : 'stops after ' + ({ '86400': '1 day', '604800': '1 week', '2592000': '30 days' }[f.ends] || ''), f.starts === 'now' ? 'starts now' : 'starts ' + fdate(f.startsAt)].join(' · '), !usesIsDefault || f.ends !== '0' || f.starts !== 'now'],
        privacy: [['Downloads: ' + DL_SAY[f.dl], 'ZIP: ' + DL_SAY[f.zip], f.loc === 'public' ? 'locations shown only' : 'all locations'].join(' · '), f.dl !== 'off' || f.zip !== 'off' || f.loc !== 'public'],
        page: [[{ gallery: 'The gallery\'s title', neutral: 'A neutral title', custom: 'Your own title' }[f.title], f.pic ? (f.picFile ? 'preview photo chosen' : 'automatic preview photo') : 'no preview photo', f.note ? 'with a note' : 'no note', f.heads === 'default' ? 'heads-up as the site setting' : 'heads-up ' + f.heads].join(' · '), f.title !== 'gallery' || !f.pic || f.picFile !== '' || f.note !== '' || f.heads !== 'default']
      };
      var changed = 0;
      [].forEach.call(form.querySelectorAll('details.sl-grp'), function (d) {
        var x = sums[d.getAttribute('data-grp')]; if (!x) return;
        d.querySelector('[data-grp-sum]').textContent = x[0]; d.querySelector('[data-grp-tag]').hidden = !x[1]; d.querySelector('[data-grp-reset]').hidden = !x[1];
        if (x[1]) changed++;
      });
      var ra = form.querySelector('[data-sl=resetall]'); ra.hidden = changed === 0; ra.textContent = changed + (changed === 1 ? ' setting' : ' settings') + ' changed · Reset all';
    }
    function resetGroup(form, g) {
      if (g === 'scope') { radio(form, 'scope_type', 'public'); st.scope = []; st.pub = []; st.photos = []; form.querySelector('[name=only]').checked = false; form.querySelector('[data-openin-menu]').setAttribute('data-want', ''); }
      if (g === 'limits') { setUses(form, DEFAULT_USES); radio(form, 'ends_pick', '0'); radio(form, 'starts_pick', 'now'); }
      if (g === 'privacy') { radio(form, 'downloads', 'off'); radio(form, 'zip', 'off'); radio(form, 'locations', 'public'); st.downloads = []; st.zip = []; }
      if (g === 'page') { radio(form, 'title_mode', 'gallery'); form.querySelector('[name=title_text]').value = ''; form.querySelector('[name=picture]').checked = true; form.querySelector('[name=picture_file]').value = ''; form.querySelector('[name=note]').value = ''; radio(form, 'heads_up', 'default'); form.querySelector('[data-coverrow]')._loaded = false; }
      syncForm(form);
    }
    function openForm(link, focusField) {
      var form = box.querySelector('#sl-form'), v = Object.assign({}, DEFAULTS, link || {});
      form.reset();
      st = { scope: (v.scopeFolders || []).slice(), pub: (v.scopePublicOnly || []).slice(), downloads: (v.downloadsFolders || []).slice(), zip: (v.zipFolders || []).slice(), photos: (v.scopePhotos || []).slice() };
      var editing = !!(link && link.id);
      form.querySelector('[name=id]').value = editing ? link.id : '';
      form.querySelector('.sl-form-title').textContent = editing ? 'Edit link' : 'New share link';
      form.querySelector('.sl-save').textContent = editing ? 'Save' : 'Create link';
      form.querySelector('[name=label]').value = v.label; form.querySelector('[name=note]').value = v.note; form.querySelector('[name=title_text]').value = v.titleText;
      var addrIn = form.querySelector('[name=address]'); addrIn.value = v.address || ''; addrIn.setAttribute('data-was', v.address || ''); addrIn.setAttribute('data-editing', editing ? '1' : ''); form.querySelector('[name=keep_old]').checked = false;
      form._addrMode = editing ? (v.address ? 'custom' : 'random') : (v.addrMode || 'name');   // (a new link starts on the name; the quick form can hand over a random one)
      [].forEach.call(form.querySelectorAll('details.sl-grp'), function (d) { d.open = false; });
      form.querySelector('[name=picture]').checked = !!v.picture;
      form.querySelector('[name=picture_seed]').value = v.pictureSeed || (editing ? '' : Math.random().toString(16).slice(2, 10).padEnd(8, '0')); form.querySelector('[name=picture_file]').value = v.pictureFile || '';
      form.querySelector('[data-coverrow]')._loaded = false;
      radio(form, 'title_mode', v.titleMode); radio(form, 'downloads', v.downloads); radio(form, 'zip', v.zip); radio(form, 'locations', v.locations); radio(form, 'heads_up', v.headsUp);
      radio(form, 'scope_type', v.scopeType); form.querySelector('[name=only]').checked = !!v.only;
      radio(form, 'starts_pick', v.startsAt > 0 ? 'pick' : 'now'); if (v.startsAt > 0) form.querySelector('[name=starts_at_local]').value = localValue(v.startsAt);
      setUses(form, v.maxOpens);
      if (v.endsAt > 0) { radio(form, 'ends_pick', 'pick'); form.querySelector('[name=ends_at_local]').value = localValue(v.endsAt); } else radio(form, 'ends_pick', '0');
      form.querySelector('.sl-err').textContent = '';
      form.querySelector('[data-openin-menu]').setAttribute('data-want', v.openIn || '');
      if (focusField === 'address') form._addrMode = 'custom';
      addrSync(form); syncForm(form); form.hidden = false; form.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); form.querySelector(focusField === 'address' ? '[name=address]' : '[name=label]').focus();
    }
    function formFields(form) {
      var now = Math.floor(Date.now() / 1000), ends = radioValue(form, 'ends_pick'), opens = radioValue(form, 'opens_pick'), scope = radioValue(form, 'scope_type');
      return {
        action: form.querySelector('[name=id]').value ? 'link_save' : 'link_create', id: form.querySelector('[name=id]').value,
        label: form.querySelector('[name=label]').value, address: form.querySelector('[name=address]').value, address_auto: form._addrMode === 'name' ? '1' : '0', keep_old: form.querySelector('[name=keep_old]').checked && !form.querySelector('[data-keeprow]').hidden ? '1' : '0', note: form.querySelector('[name=note]').value,
        title_mode: radioValue(form, 'title_mode'), title_text: form.querySelector('[name=title_text]').value, picture: form.querySelector('[name=picture]').checked ? '1' : '0', picture_seed: form.querySelector('[name=picture_seed]').value, picture_file: form.querySelector('[name=picture_file]').value,
        scope_type: scope, scope_folders: JSON.stringify(scope === 'folders' || scope === 'mixed' ? st.scope : []), scope_public_only: JSON.stringify(scope === 'folders' || scope === 'mixed' ? st.pub.filter(function (p) { return st.scope.indexOf(p) >= 0; }) : []), scope_photos: JSON.stringify(scope === 'photos' || scope === 'mixed' ? st.photos : []),
        only: (scope === 'folders' || scope === 'photos' || scope === 'mixed') && form.querySelector('[name=only]').checked ? '1' : '0',
        open_in: form.querySelector('[name=open_in]').value,
        downloads: radioValue(form, 'downloads'), downloads_folders: JSON.stringify(st.downloads), zip: radioValue(form, 'zip'), zip_folders: JSON.stringify(st.zip),
        locations: radioValue(form, 'locations'), heads_up: radioValue(form, 'heads_up'),
        starts_at: radioValue(form, 'starts_pick') === 'pick' ? epoch(form.querySelector('[name=starts_at_local]').value) : 0,
        max_opens: opens === 'n' ? (+form.querySelector('[name=opens_other]').value || 0) : +opens,
        ends_at: ends === 'pick' ? epoch(form.querySelector('[name=ends_at_local]').value) : (ends === '0' ? 0 : now + (+ends))
      };
    }
    box.addEventListener('input', function (e) {
      var fm = e.target.closest ? e.target.closest('#sl-form') : null;
      if (fm && e.target.matches('[name=label]') && fm._addrMode === 'name') addrSync(fm);
      if (fm) summarize(fm);
      if (!e.target.matches || !e.target.matches('#sl-form [name=address]')) return;
      var at = e.target.selectionStart, clean = e.target.value.toLowerCase().replace(/[^a-z0-9-]+/g, '-');
      if (clean !== e.target.value) { e.target.value = clean; try { e.target.setSelectionRange(at, at); } catch (x) {} }
      addrHint(e.target.closest('#sl-form'));
    });
    box.addEventListener('change', function (e) {
      var form = e.target.closest ? e.target.closest('#sl-form') : null; if (!form) return;
      if (e.target.name === 'keep_old') { addrHint(form); return; }
      if (e.target.name === 'title_mode' && !form.querySelector('[name=id]').value) form.querySelector('[name=picture]').checked = e.target.value === 'gallery';
      if (e.target.name === 'picture' || e.target.name === 'title_mode') { form.querySelector('[data-coverrow]')._loaded = false; }   // (a new link: the photo follows the title until you decide)
      if (e.target.name === 'scope_type') form.querySelector('[name=only]').checked = e.target.value === 'folders' || e.target.value === 'photos' || e.target.value === 'mixed';   // (any link that limits what they see: on to start with, so it shows just what it covers; everything and the public gallery: off, and the box is hidden)
      if (e.target.name === 'addr_name') { setAddrMode(form, e.target.checked ? 'name' : (!opensPrivate(form) ? 'custom' : 'random'), true); return; }   // (unticking: a public link opens the box to type in; one that opens private photos gets a random address, which a name can't be)
      if (e.target.name === 'scope_type') addrSync(form);
      if (e.target.name === 'scope_type' && (e.target.value === 'folders' || e.target.value === 'mixed')) {
        // folders already chosen for downloads and ZIPs that the new scope doesn't cover are dropped
        ['downloads', 'zip'].forEach(function (k) { st[k] = st[k].filter(function (p) { return under(p, st.scope) || !st.scope.length; }); });
      }
      syncForm(form);
    });
    // The folder picker: a list of check boxes.
    function pickFolders(form, kind) {
      var all = folderList(form), roots = st.scope, scope = radioValue(form, 'scope_type');
      var list = (kind !== 'scope' && (scope === 'folders' || scope === 'mixed')) ? all.filter(function (o) { return under(o.p, roots); }) : all;
      if (kind !== 'scope' && (scope === 'folders' || scope === 'mixed') && !roots.length) { say('Choose the folders it covers first.', true); return Promise.resolve(false); }
      var rows = list.map(function (o, i) {
        return '<label class="sl-pick' + (o.d ? ' sl-d' + Math.min(o.d, 6) : '') + '"><input type="checkbox" value="' + esc(o.p) + '"' + (st[kind].indexOf(o.p) >= 0 ? ' checked' : '') + '> <span>' + (o.x ? '🔒 ' : '') + (o.c ? '<em class="coll">' + esc(o.n || o.l) + '</em>' : esc(o.n || o.l)) + (o.u ? ' <small class="muted">Not listed</small>' : '') + '</span></label>';
      }).join('') || '<p class="muted">There are no folders to choose from.</p>';
      return ask(kind === 'scope' ? 'Choose the folders it covers' : 'Choose the folders', '<div class="sl-picklist">' + rows + '</div><p class="hint">A folder includes everything inside it; once it is chosen you can limit it to its public items. A lock marks a private folder.</p>', 'Done', false).then(function (yes) {
        if (!yes) return false;
        var before = st[kind].slice();
        st[kind] = [].map.call(modalEl().querySelectorAll('.sl-picklist input:checked'), function (c) { return c.value; });
        // A Not listed folder is the owner's private drawer: picking one into a link is allowed, but asked about, because everyone with the link then sees all of it.
        var newlyUnlisted = kind === 'scope' ? all.filter(function (o) { return o.u && st[kind].indexOf(o.p) >= 0 && before.indexOf(o.p) < 0; }) : [];
        var finish = function () {
          if (kind === 'scope') { st.pub = st.pub.filter(function (p) { return st.scope.indexOf(p) >= 0; }); ['downloads', 'zip'].forEach(function (k) { st[k] = st[k].filter(function (p) { return under(p, st.scope); }); }); }
          syncForm(form);
          return true;
        };
        if (!newlyUnlisted.length) return finish();
        var names = newlyUnlisted.map(function (o) { return '<strong>' + esc(o.n || o.l) + '</strong>'; }).join(', ');
        return ask('Include a Not listed folder?', '<p>' + names + (newlyUnlisted.length === 1 ? ' is' : ' are') + ' Not listed: only you can open ' + (newlyUnlisted.length === 1 ? 'it' : 'them') + '. Everyone with this link will see all of ' + (newlyUnlisted.length === 1 ? 'it' : 'them') + '.</p>', 'Include ' + (newlyUnlisted.length === 1 ? 'it' : 'them'), true).then(function (answer) {
          if (answer !== true) { var drop = newlyUnlisted.map(function (o) { return o.p; }); st[kind] = st[kind].filter(function (p) { return drop.indexOf(p) < 0; }); }
          return finish();
        });
      });
    }
    // The photo picker: choose a folder, tick its photos; what is ticked stays ticked while another folder is looked at.
    function pickPhotos(form) {
      var chosen = st.photos.slice(), all = folderList(form), first = chosen.length ? chosen[0] : '';
      var startIn = first.indexOf('/') > 0 ? first.replace(/\/[^\/]*$/, '') : '';
      var rows = [{ v: '', t: 'The front page', d: 0 }].concat(folderRows(form));
      var done = ask('Add photos', '<div class="sl-f">' + popHtml('ppfolder', 'pp_folder', rows, startIn, 'Folder') + '</div><div id="sl-pp-grid" class="sl-pickgrid"><p class="muted">Loading…</p></div><p class="hint" id="sl-pp-count"></p>', 'Done', false);
      var m = modalEl(), grid = m.querySelector('#sl-pp-grid'), sel = m.querySelector('[name=pp_folder]'), count = m.querySelector('#sl-pp-count');
      function tally() { count.textContent = chosen.length ? chosen.length + ' photo' + (chosen.length === 1 ? '' : 's') + ' chosen' : 'Tick the photos to share.'; }
      function load() {
        grid.innerHTML = '<p class="muted">Loading…</p>';
        fetch('?admin&linkphotos=' + encodeURIComponent(sel.value), { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.text(); }).then(function (html) {
          grid.innerHTML = html;
          [].forEach.call(grid.querySelectorAll('input[type=checkbox]'), function (i) { i.checked = chosen.indexOf(i.value) >= 0; });
          tally();
        }).catch(function () { grid.innerHTML = '<p class="muted">Couldn\'t load: check your connection.</p>'; });
      }
      grid.addEventListener('change', function (e) {
        var i = e.target; if (!i || i.type !== 'checkbox') return;
        var k = chosen.indexOf(i.value);
        if (i.checked && k < 0) chosen.push(i.value); else if (!i.checked && k >= 0) chosen.splice(k, 1);
        tally();
      });
      sel.addEventListener('change', load); load();
      return done.then(function (yes) { if (!yes) return false; st.photos = chosen; syncForm(form); return true; });
    }
    // The preview photo picker: a folder, then one of its PUBLIC photos (the server lists nothing else and checks again when the link is saved and whenever the picture is used).
    function pickCover(form) {
      var chosen = form.querySelector('[name=picture_file]').value, startIn = chosen.indexOf('/') > 0 ? chosen.replace(/\/[^\/]*$/, '') : '';
      var done = ask('Choose the preview photo', '<div id="sl-cp-body"><p class="muted">Loading…</p></div>', 'Use this photo', false);
      var body = modalEl().querySelector('#sl-cp-body');
      fetch('?admin&linkcover=folders' + scopeQuery(form), { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (j) {
        if (!j.folders || !j.folders.length) { body.innerHTML = '<p class="muted">No folder the link covers has a public photo, so there is nothing to choose from (the link shows the gallery\'s icon). Make a photo public first, or change what the link covers.</p>'; return; }
        var rows = j.folders.map(function (f) { return { v: f.p, t: f.n, s: f.l, d: f.d }; });
        var start = chosen && rows.some(function (r) { return r.v === startIn; }) ? startIn : rows[0].v;   // (the chosen photo's folder, else the first folder that has public photos)
        body.innerHTML = '<div class="sl-f">' + popHtml('coverfolder', 'cover_folder', rows, start, 'Folder') + '</div><div id="sl-cp-grid" class="sl-pickgrid"><p class="muted">Loading…</p></div><p class="hint">Only public photos can be the preview photo.</p>';
        var grid = body.querySelector('#sl-cp-grid'), sel = body.querySelector('[name=cover_folder]');
        function load() {
          grid.innerHTML = '<p class="muted">Loading…</p>';
          fetch('?admin&linkphotos=' + encodeURIComponent(sel.value) + '&cover=1' + scopeQuery(form), { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.text(); }).then(function (html) {
            grid.innerHTML = html;
            [].forEach.call(grid.querySelectorAll('input[type=radio]'), function (i) { i.checked = i.value === chosen; });
          }).catch(function () { grid.innerHTML = '<p class="muted">Couldn\'t load: check your connection.</p>'; });
        }
        grid.addEventListener('change', function (e) { if (e.target && e.target.type === 'radio') chosen = e.target.value; });
        sel.addEventListener('change', load); load();
      }).catch(function () { body.innerHTML = '<p class="muted">Couldn\'t load: check your connection.</p>'; });
      return done.then(function (yes) { if (!yes || !chosen) return false; form.querySelector('[name=picture_file]').value = chosen; coverRefresh(form); return true; });
    }
    box.addEventListener('submit', function (e) {
      var form = e.target.closest ? e.target.closest('#sl-form') : null; if (!form) return;
      e.preventDefault();
      var f = formFields(form), err = form.querySelector('.sl-err'), btn = form.querySelector('.sl-save'), editing = !!f.id;
      if (radioValue(form, 'ends_pick') === 'pick' && !f.ends_at) { err.textContent = 'Pick the date and time it should stop working.'; return; }
      if (form._addrMode === 'name' && f.label.trim() !== '' && f.address.length < 4) { err.textContent = 'An address made from the name needs at least 4 letters or digits. Add to the name, or untick \u201cUse the name as the address\u201d.'; return; }
      btn.disabled = true; err.textContent = '';
      post(f).then(function (j) {
        btn.disabled = false;
        if (!j.ok) { err.textContent = j.text || 'Not saved'; return; }
        form.hidden = true;
        return refresh().then(function () {
          var row = box.querySelector('details.sl-row[data-id="' + j.id + '"]');
          if (row) { row.open = true; row.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
          say(editing ? 'Saved.' : 'Link made. Copy it from here.');
        });
      }).catch(function (x) { btn.disabled = false; failed(x); });
    });
    // ---- the buttons
    function act(action, row, extra) {
      var f = { action: action, id: row.getAttribute('data-id') };
      Object.keys(extra || {}).forEach(function (k) { f[k] = extra[k]; });
      return post(f).then(function (j) { if (!j.ok) { say(j.text || 'Not saved', true); return; } return refresh().then(function () { say(j.text || 'Saved.'); }); }).catch(failed);
    }
    box.addEventListener('click', function (e) {
      var cm = e.target.closest ? e.target.closest('[data-chip-mode]') : null;
      if (cm) { if (cm.disabled) return; var cp = cm.getAttribute('data-p'), at = st.pub.indexOf(cp); if (at >= 0) st.pub.splice(at, 1); else st.pub.push(cp); syncForm(box.querySelector('#sl-form')); return; }
      var x = e.target.closest ? e.target.closest('[data-chip-x]') : null;
      if (x) { var k = x.getAttribute('data-chip-x'), p = x.getAttribute('data-p'); st[k] = st[k].filter(function (q) { return q !== p; }); if (k === 'scope') st.pub = st.pub.filter(function (q) { return q !== p; }); if (k === 'scope') ['downloads', 'zip'].forEach(function (kk) { st[kk] = st[kk].filter(function (q) { return under(q, st.scope); }); }); syncForm(box.querySelector('#sl-form')); return; }
      if (e.target.closest && e.target.closest('[data-cover-choose]')) { pickCover(box.querySelector('#sl-form')); return; }
      if (e.target.closest && e.target.closest('[data-cover-auto]')) { var cf = box.querySelector('#sl-form'); cf.querySelector('[name=picture_file]').value = ''; coverRefresh(cf); return; }
      var add = e.target.closest ? e.target.closest('[data-chip-add]') : null;
      if (add) { var addKind = add.getAttribute('data-chip-add'); if (addKind === 'photos') pickPhotos(box.querySelector('#sl-form')); else pickFolders(box.querySelector('#sl-form'), addKind); return; }
      var b = e.target.closest ? e.target.closest('[data-sl]') : null; if (!b) return;
      var what = b.getAttribute('data-sl'), row = rowOf(b), link = null;
      if (row) { try { link = JSON.parse(row.getAttribute('data-link')); } catch (x2) {} }
      if (b.closest('summary')) e.preventDefault();   // a button in a row's title line doesn't also fold the row
      if (what === 'new') { openForm(null); return; }
      if (what === 'visitors') { var vrow = rowOf(b), vid = vrow ? vrow.getAttribute('data-id') : ''; if (window.__vtShow) window.__vtShow(vid); adminGoTo('visits'); return; }
      if (what === 'addrrandom') { setAddrMode(box.querySelector('#sl-form'), 'random', false); return; }
      if (what === 'addrcustom') { setAddrMode(box.querySelector('#sl-form'), 'custom', true); return; }
      if (what === 'grpreset') { var gd = b.closest('details.sl-grp'); resetGroup(box.querySelector('#sl-form'), gd.getAttribute('data-grp')); return; }
      if (what === 'resetall') { var rf = box.querySelector('#sl-form'); ['scope', 'limits', 'privacy', 'page'].forEach(function (g) { resetGroup(rf, g); }); return; }
      if (what === 'cancel') { box.querySelector('#sl-form').hidden = true; return; }
      if (!row) return;
      if (what === 'copy') {
        var input = row.querySelector('.sl-url'); if (!input) return;
        copy(input.value, function () { var old = b.textContent; b.textContent = 'Copied'; setTimeout(function () { b.textContent = old; }, 1400); });
        return;
      }
      if (what === 'edit') { openForm(link); return; }
      if (what === 'aliasresume') { act('link_alias_resume', row, { alias: b.getAttribute('data-alias') }); return; }
      if (what === 'aliaspause') { act('link_alias_pause', row, { alias: b.getAttribute('data-alias') }); return; }
      if (what === 'killedclear') {
        var kn = (link && link.killedCount) || 0;
        ask('Clear ' + (kn === 1 ? 'this record' : 'these ' + (kn || '') + ' records') + '?', '<p>This removes the one-line ' + (kn === 1 ? 'record' : 'records') + ' kept for ' + (kn === 1 ? 'the killed address' : 'each killed address') + ': when it was killed and how many visits it sent. <strong>It cannot be brought back.</strong></p><p class="hint">The addresses themselves are already gone; only this history is removed.</p>', 'Clear', true).then(function (yes) { if (yes) act('link_killed_clear', row); });
        return;
      }
      if (what === 'aliaskill') {
        var gone = b.getAttribute('data-alias'), named = b.getAttribute('data-custom') === '1', shown = named ? gone : gone.slice(0, 4) + '…' + gone.slice(-4);
        ask('Kill this old address?', '<p><strong>' + esc(shown) + '</strong> stops working for good and is forgotten: it cannot be brought back, and its visit totals are dropped (a one-line summary stays in Settings).</p>'
          + (named ? '<p class="hint"><strong>The name is then free.</strong> Any of your links (or a folder) can use it, and anyone still holding this address would land on whatever uses the name next.</p>' : '<p class="hint">A random code is never used again.</p>')
          + '<p class="hint">Not sure? Pause it instead: you can resume a paused address.</p>', 'Kill', true).then(function (yes) { if (yes) act('link_alias_kill', row, { alias: gone }); });
        return;
      }
      if (what === 'preview') {   // a form into a new tab: the security token travels in the body of the request, not in an address
        var pf = document.createElement('form');
        pf.method = 'post'; pf.action = '?admin'; pf.target = '_blank'; pf.rel = 'noopener'; pf.hidden = true;
        [['linkpreview', link.id], ['csrf_token', csrf]].forEach(function (kv) { var i = document.createElement('input'); i.type = 'hidden'; i.name = kv[0]; i.value = kv[1]; pf.appendChild(i); });
        document.body.appendChild(pf); pf.submit(); pf.remove();
        return;
      }
      var name = link ? link.label : 'this link';
      if (what === 'pause') { act('link_pause', row); return; }
      if (what === 'resume') { act('link_resume', row); return; }
      if (what === 'forget') { act('link_forget', row, { fid: b.getAttribute('data-fid') }); return; }
      if (what === 'revoke') {
        ask('Revoke this link?', '<p>“' + esc(name) + '” stops working for good, and anyone signed in through it is signed out. It stays in the list, and its log is kept.</p><p class="hint">Not sure? Pause it instead: you can resume a paused link.</p>', 'Revoke', true).then(function (yes) { if (yes) act('link_revoke', row); });
        return;
      }
      if (what === 'replace') {
        var custom = link && link.address ? link.address : '', keptNote = link && link.aliases ? '<p class="hint">The ' + (link.aliases === 1 ? 'old address' : link.aliases + ' old addresses') + ' you kept working ' + (link.aliases === 1 ? 'is' : 'are') + ' paused too.</p>' : '';
        ask('Give this link a new address?', (custom
          ? '<p>This link\'s address is <strong>' + esc(custom) + '</strong>. <em>Random</em> gives it a random code instead: the name is gone, the address you sent stops working right away, and anyone signed in through it is signed out.</p><p class="hint">Its name, settings and log stay as they are. To pick a different name of your own, press <em>Custom…</em>.</p>'
          : '<p>The address you sent stops working right away, and anyone signed in through it is signed out.</p><p class="hint">Its name, settings and log stay as they are. You will need to send the new address to whoever should still have it. To use a name of your own instead, press <em>Custom…</em>.</p>') + keptNote, 'Random', false, 'Custom…').then(function (yes) {
          if (yes === 'extra') openForm(link, 'address'); else if (yes) act('link_replace', row);
        });
        return;
      }
      if (what === 'delete') {
        if (row.classList.contains('sl-public')) { ask('Delete the public address\'s log?', '<p>Every visitor, folder and photo line in it is deleted. <strong>It starts again from nothing</strong> if logging is on.</p><p class="hint">To keep a copy, use <em>Export log</em> first.</p>', 'Delete the log', true).then(function (yes) { if (yes) act('link_delete', row); }); return; }
        ask('Delete this link?', '<p>“' + esc(name) + '” stops working for good and is removed from the list. <strong>Its log is deleted with it.</strong></p><p class="hint">To keep the log, don\'t delete: a revoked, paused or expired link keeps it.</p>', 'Delete link and log', true).then(function (yes) { if (yes) act('link_delete', row); });
        return;
      }
      if (what === 'extend') {
        // Used up asks how many more browsers; expired asks when it should stop; one that is both asks both. Everything else about the link stays.
        var needOpens = !!link && link.maxOpens > 0 && link.opens >= link.maxOpens, needEnd = !!link && link.state === 'expired';
        if (!needOpens && !needEnd) needEnd = true;
        var radios = function (name, list, def) { return '<div class="sl-extopts" role="radiogroup">' + list.map(function (o) { return '<label><input type="radio" name="' + name + '" value="' + o[0] + '"' + (o[0] === def ? ' checked' : '') + '> ' + o[1] + '</label>'; }).join('') + '</div>'; };
        var html = (needOpens ? '<div class="sl-f"><span class="sl-k">How many more browsers can open it?</span>' + radios('sl-ext-o', [['1', 'Just one'], ['2', '2'], ['5', '5'], ['10', '10'], ['0', 'Unlimited'], ['n', 'Other…']], '2')
            + '<input type="number" id="sl-ext-n" min="1" max="100000" value="5" aria-label="Number of browsers" hidden><span class="sl-h">Added to the ' + link.opens + ' already let in. Unlimited takes the limit off.</span></div>' : '')
          + (needEnd ? '<div class="sl-f"><span class="sl-k">When should it stop working?</span>' + radios('sl-ext-e', [['86400', '1 day'], ['604800', '1 week'], ['2592000', '30 days'], ['0', 'Never'], ['pick', 'Pick a date…']], '604800')
            + '<input type="datetime-local" id="sl-ext-date" aria-label="Stops working on" value="' + localValue(Math.floor(Date.now() / 1000) + 7 * 86400) + '" hidden><span class="sl-h">Counted from now, in your browser\'s time zone.</span></div>' : '')
          + '<p class="hint">Everything else about the link stays as it is: its address, its settings and its log.</p>';
        var mod = modalEl();
        var asked = ask('Extend this link', html, 'Extend', false);
        var picked = function (name) { var r = mod.querySelector('input[name=' + name + ']:checked'); return r ? r.value : ''; };
        mod.querySelector('.sl-modal-body').onchange = function () {
          var n = mod.querySelector('#sl-ext-n'), d = mod.querySelector('#sl-ext-date');
          if (n) n.hidden = picked('sl-ext-o') !== 'n';
          if (d) d.hidden = picked('sl-ext-e') !== 'pick';
        };
        asked.then(function (yes) {
          if (!yes) return;
          var extra = {};
          if (needOpens) {
            var o = picked('sl-ext-o');
            if (o === '0') extra.no_limit = '1'; else extra.add_opens = o === 'n' ? (mod.querySelector('#sl-ext-n').value || '1') : o;
          }
          if (needEnd) {
            var e = picked('sl-ext-e');
            if (e === '0') extra.no_end = '1'; else if (e === 'pick') extra.ends_at = epoch(mod.querySelector('#sl-ext-date').value); else extra.ends_at = Math.floor(Date.now() / 1000) + (+e);
          }
          act('link_extend', row, extra);
        });
      }
    });
    // ---- Select mode: the pill of each link ticks it, a bar holds the count, Select all and the actions (the same one-link actions, done for each)
    var sel = { on: false, ids: {} }, bar = null, working = false;
    function selRows() { return [].slice.call(box.querySelectorAll('details.sl-row:not(.sl-public)')); }
    function selLink(row) { var l = {}; try { l = JSON.parse(row.getAttribute('data-link')); } catch (x) {} return l; }
    function selChosen() { return selRows().filter(function (r) { return sel.ids[r.getAttribute('data-id')]; }); }
    function selBar() {
      if (bar) return bar;
      bar = document.createElement('div'); bar.className = 'sl-bulk'; bar.id = 'sl-bulk'; bar.hidden = true; bar.setAttribute('role', 'region'); bar.setAttribute('aria-label', 'Selected links');
      var ic = function (n) { return '<svg class="ico" aria-hidden="true"><use href="#i-' + n + '"></use></svg>'; };
      var act = function (k, icon, label, cls) { return '<button type="button" class="sl-bulk-a' + (cls ? ' ' + cls : '') + '" data-b="' + k + '">' + ic(icon) + '<span class="l">' + label + '</span><span class="c"></span></button>'; };
      bar.innerHTML = '<div class="sl-bulk-head"><strong class="sl-bulk-n" role="status"></strong><span class="sl-bulk-g"><button type="button" class="sl-bulk-q" data-b="all">Select all</button><button type="button" class="sl-bulk-q" data-b="clear">Clear</button></span><button type="button" class="sl-bulk-done" data-b="done">Done</button></div>'
        + '<div class="sl-bulk-acts">' + act('pause', 'pause', 'Pause') + act('resume', 'play', 'Resume') + act('extend', 'clock', 'Extend') + act('revoke', 'ban', 'Revoke', 'sl-bulk-danger') + act('delete', 'trash', 'Delete', 'sl-bulk-danger') + '</div>';
      document.body.appendChild(bar);
      bar.addEventListener('click', function (e) { var b = e.target.closest ? e.target.closest('[data-b]') : null; if (b && !b.disabled) selDo(b.getAttribute('data-b')); });
      return bar;
    }
    function selEligible(kind, rows) {
      return rows.filter(function (r) {
        var st = r.getAttribute('data-state');
        if (kind === 'pause') return st !== 'paused' && st !== 'revoked';
        if (kind === 'resume') return st === 'paused';
        if (kind === 'extend' || kind === 'revoke') return st !== 'revoked';
        return true;
      });
    }
    function selApply() {   // what the page shows follows the choice (also after the list was fetched again)
      var rows = selRows(), live = {};
      rows.forEach(function (r) {
        var id = r.getAttribute('data-id'), on = !!sel.ids[id], pill = r.querySelector('summary > .sl-pill');
        live[id] = true;
        r.classList.toggle('sel', sel.on && on);
        if (pill) { if (sel.on) { pill.setAttribute('role', 'checkbox'); pill.setAttribute('aria-checked', on ? 'true' : 'false'); pill.tabIndex = 0; } else { pill.removeAttribute('role'); pill.removeAttribute('aria-checked'); pill.removeAttribute('tabindex'); } }
      });
      Object.keys(sel.ids).forEach(function (id) { if (!live[id]) delete sel.ids[id]; });
      box.classList.toggle('sl-selecting', sel.on);
      var btn = box.querySelector('[data-sl=select]'); if (btn) btn.setAttribute('aria-pressed', sel.on ? 'true' : 'false');
      var b = selBar(), mine = selChosen(), tabOn = !!box.closest('.tab-panel.active');
      b.hidden = !(sel.on && tabOn);
      b.querySelector('.sl-bulk-n').textContent = mine.length === 0 ? 'Nothing selected' : mine.length + ' selected';
      b.querySelector('[data-b=all]').disabled = working || mine.length === rows.length;
      b.querySelector('[data-b=clear]').disabled = working || mine.length === 0;
      ['pause', 'resume', 'extend', 'revoke', 'delete'].forEach(function (k) {
        var n = selEligible(k, mine).length, el = b.querySelector('[data-b=' + k + ']');
        el.disabled = working || n === 0;
        el.querySelector('.c').textContent = n > 0 && mine.length ? n : '';
        el.setAttribute('aria-label', el.querySelector('.l').textContent + (n > 0 && mine.length ? ' (' + n + ')' : ''));
      });
    }
    function selMode(on) { sel.on = on; if (!on) sel.ids = {}; selApply(); }
    function selFlip(row) { var id = row.getAttribute('data-id'); if (sel.ids[id]) delete sel.ids[id]; else sel.ids[id] = true; selApply(); }
    function selNames(rows) { var n = rows.map(function (r) { return '“' + esc(selLink(r).label || '') + '”'; }); return n.length > 8 ? n.slice(0, 8).join(', ') + ' and ' + (n.length - 8) + ' more' : n.join(', '); }
    function selRun(kind, rows, extra, doneText) {   // one link at a time through the same actions as the buttons on a row
      working = true; selApply();
      var ok = 0, failed_ = [], i = 0, bar_ = selBar().querySelector('.sl-bulk-n');
      function next() {
        if (i >= rows.length) {
          working = false;
          if (kind === 'link_delete') rows.forEach(function (r) { delete sel.ids[r.getAttribute('data-id')]; });
          return refresh().then(function () {
            selApply();
            say(doneText(ok) + (failed_.length ? ' ' + failed_.length + ' could not be changed: ' + failed_.slice(0, 3).join(' ') : ''), failed_.length > 0 && ok === 0);
          });
        }
        var row = rows[i++]; bar_.textContent = 'Working… ' + i + ' of ' + rows.length;
        var f = { action: kind, id: row.getAttribute('data-id') }; Object.keys(extra || {}).forEach(function (k) { f[k] = extra[k]; });
        return post(f).then(function (j) { if (j.ok) ok++; else failed_.push(j.text || 'Not saved.'); }).then(next);
      }
      return next().catch(function (x) { working = false; selApply(); failed(x); });
    }
    function selDo(what) {
      var rows = selRows(), mine = selChosen();
      if (what === 'done') { selMode(false); return; }
      if (what === 'all') { rows.forEach(function (r) { sel.ids[r.getAttribute('data-id')] = true; }); selApply(); return; }
      if (what === 'clear') { sel.ids = {}; selApply(); return; }
      var rs = selEligible(what, mine), n = rs.length; if (!n) return;
      var plural = function (k) { return k + (k === 1 ? ' link' : ' links'); };
      if (what === 'pause') { selRun('link_pause', rs, null, function (k) { return 'Paused ' + plural(k) + '.'; }); return; }
      if (what === 'resume') { selRun('link_resume', rs, null, function (k) { return 'Resumed ' + plural(k) + '.'; }); return; }
      if (what === 'revoke') {
        ask('Revoke ' + plural(n) + '?', '<p>' + selNames(rs) + ' stop' + (n === 1 ? 's' : '') + ' working for good, and anyone signed in through ' + (n === 1 ? 'it is' : 'them are') + ' signed out. ' + (n === 1 ? 'It stays' : 'They stay') + ' in the list, with the log kept.</p><p class="hint">Not sure? Pause instead: you can resume a paused link.</p>', 'Revoke ' + plural(n), true).then(function (yes) {
          if (yes) selRun('link_revoke', rs, null, function (k) { return 'Revoked ' + plural(k) + '.'; });
        });
        return;
      }
      if (what === 'delete') {
        var asked = ask('Delete ' + plural(n) + '?', '<p>' + selNames(rs) + ' stop' + (n === 1 ? 's' : '') + ' working for good and ' + (n === 1 ? 'is' : 'are') + ' removed from the list. <strong>' + (n === 1 ? 'Its log is' : 'Their logs are') + ' deleted with ' + (n === 1 ? 'it' : 'them') + '.</strong> This cannot be undone.</p><p class="hint">To keep a log, don\'t delete: a revoked, paused or expired link keeps it.</p>'
          + '<label class="sl-only"><input type="checkbox" id="sl-bulk-sure"> Yes, delete ' + (n === 1 ? 'this link and its log' : 'these ' + n + ' links and their logs') + '</label>', 'Delete ' + plural(n), true);
        var m = modalEl(), okBtn = m.querySelector('[data-sl-modal=ok]'); okBtn.disabled = true;
        m.querySelector('#sl-bulk-sure').addEventListener('change', function (ev) { okBtn.disabled = !ev.target.checked; });
        asked.then(function (yes) { if (yes) selRun('link_delete', rs, null, function (k) { return 'Deleted ' + plural(k) + ' and their logs.'; }); });
        return;
      }
      if (what === 'extend') {
        var radios = function (name, list, def) { return '<div class="sl-extopts" role="radiogroup">' + list.map(function (o) { return '<label><input type="radio" name="' + name + '" value="' + o[0] + '"' + (o[0] === def ? ' checked' : '') + '> ' + o[1] + '</label>'; }).join(' ') + '</div>'; };
        var html = '<div class="sl-f"><span class="sl-k">When should ' + (n === 1 ? 'it' : 'they') + ' stop working?</span>' + radios('sl-bx-e', [['keep', 'Leave as it is'], ['86400', '1 day'], ['604800', '1 week'], ['2592000', '30 days'], ['0', 'Never'], ['pick', 'Pick a date…']], 'keep')
          + '<input type="datetime-local" id="sl-bx-date" aria-label="Stops working on" value="' + localValue(Math.floor(Date.now() / 1000) + 7 * 86400) + '" hidden><span class="sl-h">Counted from now, in your browser\'s time zone.</span></div>'
          + '<div class="sl-f"><span class="sl-k">Browsers</span>' + radios('sl-bx-o', [['keep', 'Leave as it is'], ['0', 'Unlimited']], 'keep') + '</div>'
          + '<p class="hint">Everything else about ' + (n === 1 ? 'the link' : 'each link') + ' stays as it is: its address, its settings and its log.</p>';
        var mod = modalEl(), askedE = ask('Extend ' + plural(n), html, 'Extend', false);
        var pickedB = function (nm) { var r = mod.querySelector('input[name=' + nm + ']:checked'); return r ? r.value : ''; };
        mod.querySelector('.sl-modal-body').onchange = function () { var d = mod.querySelector('#sl-bx-date'); if (d) d.hidden = pickedB('sl-bx-e') !== 'pick'; };
        askedE.then(function (yes) {
          if (!yes) return;
          var extra = {}, e = pickedB('sl-bx-e'), o = pickedB('sl-bx-o');
          if (e === '0') extra.no_end = '1'; else if (e === 'pick') extra.ends_at = epoch(mod.querySelector('#sl-bx-date').value); else if (e && e !== 'keep') extra.ends_at = Math.floor(Date.now() / 1000) + (+e);
          if (o === '0') extra.no_limit = '1';
          if (!Object.keys(extra).length) { say('Nothing to change: both were left as they are.'); return; }
          selRun('link_extend', rs, extra, function (k) { return 'Extended ' + plural(k) + '.'; });
        });
      }
    }
    box.addEventListener('click', function (e) {   // capture: the select button, and in select mode the pill, before the row's own buttons see the press
      var t = e.target; if (!t.closest) return;
      var sb = t.closest('[data-sl=select]');
      if (sb) { e.preventDefault(); e.stopPropagation(); selMode(!sel.on); return; }
      if (!sel.on) return;
      var pill = t.closest('details.sl-row:not(.sl-public) > summary > .sl-pill:first-child');
      if (pill) { e.preventDefault(); e.stopPropagation(); selFlip(pill.closest('details.sl-row')); return; }
    }, true);
    box.addEventListener('keydown', function (e) {
      if (!sel.on || (e.key !== ' ' && e.key !== 'Enter')) return;
      var pill = e.target.closest ? e.target.closest('details.sl-row:not(.sl-public) > summary > .sl-pill:first-child') : null;
      if (pill) { e.preventDefault(); selFlip(pill.closest('details.sl-row')); }
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && sel.on && document.getElementById('sl-modal').hidden) selMode(false); });
    var slPanel = box.closest('.tab-panel');
    if (slPanel && window.MutationObserver) new MutationObserver(function () { if (sel.on) selApply(); }).observe(slPanel, { attributes: true, attributeFilter: ['class'] });
    times(); probe();
    if (box.closest('.tab-panel.active')) setTimeout(markSeen, 1500);
    mountPanels();
    // the short "Share this folder / photo" form hands over to the full form ("More options…"), here or from another page
    window.__slOpenForm = openForm; window.__slRefresh = refresh; window.__slSelApply = selApply;
    try {
      var draft = sessionStorage.getItem('galleryShareDraft');
      if (draft) { sessionStorage.removeItem('galleryShareDraft'); var dv = JSON.parse(draft); dv.id = ''; setTimeout(function () { openForm(dv, dv.focusAddress ? 'address' : undefined); }, 300); }
    } catch (x) {}
  })();
  // Private folder: a switch like the others. Switching it on saves at once; switching it off first says what will happen (how many
  // photos stay private, how many become public) and waits for a yes.
  [].forEach.call(document.querySelectorAll('.priv-folder-form'), function (form) {
    form.addEventListener('submit', function (ev) { ev.preventDefault(); });
    var csrf = form.querySelector('input[name=csrf_token]').value, box = form.querySelector('input[type=checkbox]'), note = form.querySelector('.priv-folder-note');
    var node = form.querySelector('.rowstate'), modal = document.getElementById('priv-modal');
    var counts = { stay: +form.getAttribute('data-stay') || 0, open: +form.getAttribute('data-open') || 0 };
    var noun = form.getAttribute('data-noun') || 'photo';
    function word(n) { return n + ' ' + noun + (n === 1 ? '' : 's'); }
    function paint() {
      var dlBlock = document.querySelector('.dl-block'); if (dlBlock) dlBlock.hidden = box.checked;   // (a private folder offers visitors nothing: the download switch is for a public one)
      if (!note) return;
      note.innerHTML = box.checked ? note.getAttribute('data-on').replace('%stay%', word(counts.stay)).replace('%open%', word(counts.open)).replace('%becomes%', counts.open === 1 ? 'becomes' : 'become') : note.getAttribute('data-off');
    }
    function say(text, kind) {
      node.textContent = text; node.className = 'rowstate ' + (kind || ''); clearTimeout(node._t);
      if (kind === 'ok') node._t = setTimeout(function () { node.textContent = ''; node.className = 'rowstate'; }, 3000);
    }
    function send(hide, done) {
      var body = new URLSearchParams();
      body.set('csrf_token', csrf); body.set('action', 'toggle_folder_restriction'); if (hide) body.set('hidden', '1');
      say('Saving…', 'busy');
      fetch(location.pathname + location.search, { method: 'POST', credentials: 'same-origin', body: body.toString(),
          headers: { 'X-Requested-With': 'gallery-fetch', 'Content-Type': 'application/x-www-form-urlencoded' } })
        .then(function (r) { if ((r.headers.get('content-type') || '').indexOf('json') < 0) throw new Error('session'); return r.json(); })
        .then(function (j) {
          if (!j.ok) { say(j.text, 'err'); done(false); return; }
          if (j.counts) counts = { stay: j.counts.stay, open: j.counts.open };
          box.checked = hide; paint(); say('Saved', 'ok'); window.__siteDirty = true; done(true);
        })
        .catch(function (e) { say(e.message === 'session' ? 'Your session ended: reload the page and sign in again.' : 'Couldn\'t save: check your connection.', 'err'); done(false); });
    }
    function closeModal() { if (modal) modal.hidden = true; box.focus(); }
    function ask() {
      say('', '');
      fetch(location.pathname + location.search + '&privcounts=1', { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { if ((r.headers.get('content-type') || '').indexOf('json') < 0) throw new Error('session'); return r.json(); })
        .then(function (j) {
          if (j.counts) counts = { stay: j.counts.stay, open: j.counts.open };
          document.getElementById('pm-stay').textContent = word(counts.stay); document.getElementById('pm-open').textContent = word(counts.open);
          document.getElementById('pm-stay-v').textContent = counts.stay === 1 ? 'stays' : 'stay'; document.getElementById('pm-open-v').textContent = counts.open === 1 ? 'becomes' : 'become';
          modal.hidden = false; document.getElementById('pm-cancel').focus();
        })
        .catch(function () { say('Couldn\'t check: reload the page and try again.', 'err'); });
    }
    // The Not listed switch below this one makes the folder private as well, and making the folder public ends it: keep the two in step.
    document.addEventListener('instant-saved', function (ev) {
      var d = ev.detail || {};
      if (typeof d.private === 'boolean' && typeof d.unlisted === 'boolean') { box.checked = d.private; if (d.counts) counts = { stay: d.counts.stay, open: d.counts.open }; paint(); var sub = document.querySelector('.unlisted-sub'); if (sub) sub.hidden = !d.unlisted; }
    });
    box.addEventListener('change', function () {
      if (box.checked) { send(true, function (ok) { if (!ok) box.checked = false; }); return; }
      box.checked = true;   // stays on until it is confirmed
      if (modal) ask();
    });
    if (modal) {
      document.getElementById('pm-cancel').addEventListener('click', closeModal);
      modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
      modal.addEventListener('keydown', function (e) { if (e.key === 'Escape') { e.preventDefault(); closeModal(); } });
      document.getElementById('pm-ok').addEventListener('click', function () {
        modal.hidden = true;
        send(false, function () { var u = document.querySelector('.unlisted-form input[type=checkbox][name=unlisted]'); if (u) u.checked = false; var sub = document.querySelector('.unlisted-sub'); if (sub) sub.hidden = true; box.focus(); });
      });
    }
    paint();
  });
  // The map sits under the location switch and only shows photos whose location is public, so it is dimmed (with a note) while
  // locations are off. (The note and dimming follow the switch once it has saved; with locations off for the whole gallery they stay.)
  (function () {
    var keep = document.querySelector('.loc-keep'), box0 = document.querySelector('.instant-form input[type=checkbox][name=enabled]'), form0 = box0 && box0.closest('form');
    if (keep && form0) form0.addEventListener('instant-saved', function () { keep.hidden = !box0.checked; });   // (the keep-the-location switch only matters where the folder shows its locations)
    var under = document.getElementById('map-under'); if (!under || under.getAttribute('data-global-off')) return;
    var box = document.querySelector('.instant-form input[type=checkbox][name=enabled]'), form = box && box.closest('form');
    if (form) form.addEventListener('instant-saved', function () { under.classList.toggle('no-loc', !box.checked); });
  })();
  // Settings: every change saves the moment it is made (one request at a time, so the file's
  // fingerprint never races), and anything that publishes content, contacts a third party, or removes
  // something visitors see asks first. The other screens refresh when you leave this one.
  // "Put first": the three kinds are rows you drag (by the handle, with a finger or a mouse, or with the arrow keys on it) into the
  // order they stack in; each row's switch says whether it is used at all. What is switched on always stands above what is off. The
  // list is the same on the Settings page (every change saved as it is made) and in a folder's own settings (written into the
  // form, saved with it); persist(order, ok, fail) is how each one keeps a change.
  function initSortFirst(box, persist) {
    var list = box.querySelector('.sf-list'), summary = box.querySelector('.sf-summary');
    var NAMES = { private: 'private items', video: 'videos', located: 'photos with a location' };
    function order() { return [].map.call(list.children, function (li) { return li.getAttribute('data-kind'); }); }
    function sum() {
      var parts = [].filter.call(list.children, function (li) { return li.querySelector('input[type=checkbox]').checked; }).map(function (li) { return NAMES[li.getAttribute('data-kind')]; });
      var t = parts.length ? parts.join(', then ') + ', then the rest.' : 'Nothing is put first: photos follow the order above.';
      if (!parts.length && box.getAttribute('data-mode') === 'form') {   // a folder with everything off, while the site puts something first
        var sOn = {}, sOrd = (box.getAttribute('data-site-order') || '').split(',');
        try { sOn = JSON.parse(box.getAttribute('data-site-on')) || {}; } catch (e) {}
        var sParts = sOrd.filter(function (k) { return sOn[k]; }).map(function (k) { return NAMES[k]; });
        if (sParts.length) t = 'Nothing is put first in this folder, though the site puts ' + sParts.join(', then ') + ' first.';
      }
      summary.textContent = t.charAt(0).toUpperCase() + t.slice(1);
    }
    var saved = order();
    function put(arr) { arr.forEach(function (k) { var li = list.querySelector('[data-kind="' + k + '"]'); if (li) list.appendChild(li); }); sum(); }
    // For a caller that wants to set the whole list at once (the folder page's "use the site's again").
    box.sfSet = function (arr, on) {
      [].forEach.call(list.children, function (li) { li.querySelector('input[type=checkbox]').checked = !!on[li.getAttribute('data-kind')]; });
      put(arr); settle(); saved = order(); sum();
    };
    function save() {
      var now = order();
      sum();
      persist(now, function () { saved = now; }, function () { put(saved); });
    }
    // What is switched on always stands above what is off (the off ones do nothing, so the list and the sentence agree):
    // switching one on brings it up under the ones already on, switching one off sends it down below them.
    function isOn(li) { return li.querySelector('input[type=checkbox]').checked; }
    function settle() {
      var items = [].slice.call(list.children);
      items.filter(isOn).concat(items.filter(function (li) { return !isOn(li); })).forEach(function (li) { list.appendChild(li); });
    }
    [].forEach.call(list.querySelectorAll('input[type=checkbox]'), function (cb) {
      cb.addEventListener('change', function () { settle(); if (order().join(',') !== saved.join(',')) save(); else sum(); });
    });
    // Drag a row by its handle: pointer events, so a finger works as well as a mouse (the browser's own drag and drop does
    // not run on a touch screen). While it is held, the row only follows the pointer and the rows it passes slide out of its
    // way; the list itself is reordered once, when it is let go (moving the row in the page while it is held would make the
    // browser let go of the pointer after the first step).
    [].forEach.call(list.children, function (li) {
      var grip = li.querySelector('.sf-grip'), active = false, rows, rects, startY, gap, newIdx, idx0;
      function clear() { rows.forEach(function (r) { r.style.transform = ''; }); }
      grip.addEventListener('pointerdown', function (ev) {
        if (ev.button !== undefined && ev.button !== 0) return;
        rows = [].slice.call(list.children);
        rects = rows.map(function (r) { return r.getBoundingClientRect(); });
        idx0 = rows.indexOf(li); newIdx = idx0; startY = ev.clientY;
        gap = rows.length > 1 ? Math.max(0, rects[1].top - rects[0].bottom) : 0;
        active = true; li.classList.add('dragging'); list.classList.add('is-dragging');
        try { grip.setPointerCapture(ev.pointerId); } catch (e) {}
        ev.preventDefault();
      });
      grip.addEventListener('pointermove', function (ev) {
        if (!active) return;
        var me = rects[idx0], dy = ev.clientY - startY;
        var lo = rects[0].top - me.top, hi = rects[rows.length - 1].bottom - me.bottom;
        dy = Math.max(lo, Math.min(hi, dy));   // stays inside the list
        li.style.transform = 'translateY(' + dy + 'px)';
        var centre = me.top + me.height / 2 + dy;
        newIdx = 0;
        rows.forEach(function (r, k) { if (r !== li && rects[k].top + rects[k].height / 2 < centre) newIdx++; });
        if (dy >= hi - 1) newIdx = rows.length - 1; else if (dy <= lo + 1) newIdx = 0;   // all the way down / up is the last / first place
        var by = me.height + gap;
        rows.forEach(function (r, k) {
          if (r === li) return;
          var shift = 0;
          if (idx0 < newIdx && k > idx0 && k <= newIdx) shift = -by; else if (idx0 > newIdx && k < idx0 && k >= newIdx) shift = by;
          r.style.transform = shift ? 'translateY(' + shift + 'px)' : '';
        });
      });
      function end(ev, commit) {
        if (!active) return;
        active = false;
        try { grip.releasePointerCapture(ev.pointerId); } catch (e) {}
        li.classList.remove('dragging'); list.classList.remove('is-dragging');
        li.style.transform = ''; clear();
        grip.blur(); grip.classList.add('rest');   // no focus ring and no hover tint left behind (a touch screen keeps :hover after a tap)
        if (!commit) return;
        var rest = rows.filter(function (r) { return r !== li; });
        rest.splice(newIdx, 0, li);
        rest.forEach(function (r) { list.appendChild(r); });
        settle();
        if (order().join(',') !== saved.join(',')) save(); else sum();
      }
      grip.addEventListener('pointerleave', function () { grip.classList.remove('rest'); });
      grip.addEventListener('pointerup', function (ev) { end(ev, true); });
      grip.addEventListener('pointercancel', function (ev) { end(ev, false); });
      // The keyboard: up and down arrows on the handle move the row.
      grip.addEventListener('keydown', function (ev) {
        if (ev.key !== 'ArrowUp' && ev.key !== 'ArrowDown') return;
        ev.preventDefault();
        if (ev.key === 'ArrowUp' && li.previousElementSibling) list.insertBefore(li, li.previousElementSibling);
        else if (ev.key === 'ArrowDown' && li.nextElementSibling) list.insertBefore(li.nextElementSibling, li);
        else return;
        settle(); grip.focus(); if (order().join(',') !== saved.join(',')) save(); else sum();
      });
    });
    
  }
  // In a folder's own settings: the list shows what the folder uses. It follows the site's until a switch or a row is changed;
  // then it is this folder's own (kept in the form until it is saved), and one button goes back to the site's.
  [].forEach.call(document.querySelectorAll('.sortfirst[data-mode=form]'), function (box) {
    var hidden = box.querySelector('input.sf-order'), radios = box.querySelectorAll('input[name=photo_sort_first]');
    function orderNow() { return [].map.call(box.querySelectorAll('.sf-list li'), function (li) { return li.getAttribute('data-kind'); }).join(','); }
    function own(isOwn) { [].forEach.call(radios, function (r) { r.checked = (r.value === 'own') === isOwn; }); label(); }
    // The heading of the section says whether anything here is the folder's own (it follows what is on the page, not only what is saved).
    function label() {
      var det = box.closest('details'), tag = det && det.querySelector('summary .muted');
      if (!tag) return;
      var custom = false;
      [].forEach.call(det.querySelectorAll('input[type=radio]:checked'), function (r) { if (r.value !== '') custom = true; });
      tag.innerHTML = custom ? '&middot; custom' : '&middot; the site\'s order';
    }
    [].forEach.call(det_radios(), function (r) { r.addEventListener('change', label); });
    function det_radios() { var det = box.closest('details'); return det ? det.querySelectorAll('.sort-row input[type=radio]') : []; }
    var siteOrder = (box.getAttribute('data-site-order') || '').split(','), siteOn = {};
    try { siteOn = JSON.parse(box.getAttribute('data-site-on')) || {}; } catch (e) {}
    function effective(arr, on) { return arr.filter(function (k) { return on[k]; }).join(','); }
    var siteEff = effective(siteOrder, siteOn);
    // After any change: if the list would sort exactly as the site's does, it IS the site's (the pill says so and the list shows the
    // site's own); otherwise it is this folder's own. (All switches off is this folder's own while the site puts something first.)
    function evaluate() {
      var on = {}; [].forEach.call(box.querySelectorAll('.sf-list li'), function (li) { on[li.getAttribute('data-kind')] = li.querySelector('input[type=checkbox]').checked; });
      if (effective(orderNow().split(','), on) === siteEff) { box.sfSet(siteOrder, siteOn); hidden.value = orderNow(); own(false); }
      else { hidden.value = orderNow(); own(true); }
      box.dispatchEvent(new CustomEvent('sf-changed', { bubbles: true }));
    }
    initSortFirst(box, function (now, ok) { ok(); evaluate(); });
    [].forEach.call(box.querySelectorAll('input[type=checkbox]'), function (cb) { cb.addEventListener('change', evaluate); });
    [].forEach.call(radios, function (r) {
      r.addEventListener('change', function () {
        if (!r.checked) return;
        if (r.value === '') {   // back to the site's: its switches and its order
          var arr = (box.getAttribute('data-site-order') || '').split(','), on = {};
          try { on = JSON.parse(box.getAttribute('data-site-on')) || {}; } catch (e) {}
          box.sfSet(arr, on); hidden.value = orderNow();
        } else { hidden.value = orderNow(); }
        label();
      });
    });
  });
  [].forEach.call(document.querySelectorAll('.site-settings-form'), function (form) {
    var hashInput = form.querySelector('input[name=config_hash]');
    var csrf = form.querySelector('input[name=csrf_token]').value;
    var chain = Promise.resolve();
    // Whole-gallery settings are not about any folder, so a save is sent to this page's address without its folder: removing the very place
    // you are standing in (the Folders from elsewhere list) must not leave the next save pointing at a folder that is gone.
    function siteUrl() { var q = new URLSearchParams(location.search); q.delete('folder'); var t = q.toString(); return location.pathname + (t ? '?' + t : '?admin'); }
    var sync = document.querySelector('.sitesync');
    form.addEventListener('submit', function (ev) { ev.preventDefault(); });   // no submit button without JavaScript: Enter must not post the whole form
    function say(node, text, kind) {
      if (!node) return;
      node.textContent = text; node.className = 'rowstate ' + (kind || '');
      clearTimeout(node._t);
      if (kind === 'ok') node._t = setTimeout(function () { node.textContent = ''; node.className = 'rowstate'; }, 3000);
    }
    // The folder list and the tab counts are redrawn from a fresh copy of the page the moment a setting is saved
    // (subfolders on or off, videos on or off ...), so they never lag behind what you just changed.
    var refreshTimer;
    function refreshChrome() {
      clearTimeout(refreshTimer);
      refreshTimer = setTimeout(function () {
        fetch(location.pathname + location.search, { credentials: 'same-origin' })
          .then(function (r) { return r.text(); })
          .then(function (html) { return html.indexOf('admin-folders') >= 0 ? html : fetch(siteUrl(), { credentials: 'same-origin' }).then(function (r) { return r.text(); }); })   // (the folder you are in may be the place just removed)
          .then(function (html) {
            var doc = new DOMParser().parseFromString(html, 'text/html');
            var fresh = doc.querySelector('.admin-folders'), old = document.querySelector('.admin-folders');
            if (fresh && old) { old.innerHTML = fresh.innerHTML; initFolderTree(); }
            [].forEach.call(document.querySelectorAll('.admin-tabs a[data-tab]'), function (a) {
              var c = a.querySelector('.count'), f = doc.querySelector('.admin-tabs a[data-tab="' + a.getAttribute('data-tab') + '"] .count');
              if (c && f) c.textContent = f.textContent;
            });
          })
          .catch(function () {});
      }, 200);
    }
    function stateOf(el) { var holder = el.closest('.pwline') || el.closest('.ctl'); return holder ? holder.querySelector('.rowstate') : null; }
    function showManual(text) {
      var box = form.querySelector('.manual-box');
      if (!box) {
        box = document.createElement('div'); box.className = 'manual-box';
        box.innerHTML = '<p class="muted">The gallery\'s folder isn\'t writable, so nothing could be saved. Create a file named <code>.gallery-config.php</code> next to <code>index.php</code> containing exactly this:</p><textarea readonly rows="10" style="width:100%;font-family:ui-monospace,monospace;"></textarea>';
        form.insertBefore(box, form.firstChild.nextSibling);
      }
      box.querySelector('textarea').value = text;
    }
    function post(fields, node, onFail, onOk) {
      identityChain = identityChain.then(function () {
        say(node, 'Saving…', 'busy');
        var body = new URLSearchParams();
        body.set('csrf_token', csrf); body.set('action', 'save_site_settings'); body.set('config_hash', hashInput.value);
        Object.keys(fields).forEach(function (k) { body.set(k, fields[k]); });
        return fetch(siteUrl(), { method: 'POST', credentials: 'same-origin', body: body.toString(),
            headers: { 'X-Requested-With': 'gallery-fetch', 'Content-Type': 'application/x-www-form-urlencoded' } })
          .then(function (r) { if ((r.headers.get('content-type') || '').indexOf('json') < 0) throw new Error('session'); return r.json(); })
          .then(function (j) {
            if (j.ok && j.hash) hashInput.value = j.hash;
            if (j.ok) { say(node, j.text, 'ok'); window.__siteDirty = true; if (sync) sync.hidden = false; refreshChrome(); if (onOk) onOk(j); }
            else { say(node, j.text, 'err'); if (onFail) onFail(); if (j.manual) showManual(j.manual); }
            refreshMeta();
          })
          .catch(function (e) { say(node, e.message === 'session' ? 'Your session ended — reload the page and sign in again.' : 'Couldn\'t save — check your connection.', 'err'); if (onFail) onFail(); refreshMeta(); });
      });
    }
    // "default: …" under a row shows only while the row's value differs from the default.
    function rowValue(row) {
      var c = row.querySelector('input[type=checkbox][name^=set_]'); if (c) return c.checked ? '1' : '0';
      var r = row.querySelector('input[type=radio]:checked'); if (r) return r.value;
      var f = row.querySelector('input[type=number], select, input[type=text]'); return f ? f.value : null;
    }
    function refreshMeta() {
      [].forEach.call(form.querySelectorAll('.site-row .src[data-default]'), function (m) {
        var v = rowValue(m.closest('.site-row')), d = m.getAttribute('data-default');
        if (v === null) return;
        m.classList.toggle('is-default', v === d || (v !== '' && d !== '' && !isNaN(v) && !isNaN(d) && parseFloat(v) === parseFloat(d)));
      });
    }
    form.addEventListener('change', refreshMeta);
    // Prepare media lives under the Thumbnails switch: it appears the moment the switch is turned on (once that is saved) and goes when it is turned off.
    function mediaPanelFollow(on) {
      var slot = document.getElementById('media-slot'); if (!slot) return;
      if (!on) { slot.textContent = ''; return; }
      var body = new URLSearchParams(); body.set('csrf_token', csrf); body.set('action', 'media_panel');
      fetch(siteUrl(), { method: 'POST', credentials: 'same-origin', body: body.toString(), headers: { 'X-Requested-With': 'gallery-fetch', 'Content-Type': 'application/x-www-form-urlencoded' } })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j.ok || !j.html || !j.cache) return;
          slot.innerHTML = j.html;
          var panel = slot.querySelector('#media-panel'); if (panel && window.__initMediaPanel) window.__initMediaPanel(panel);
        }).catch(function () {});
    }
    // The window came back: Prepare media's numbers (how many files still need preparing, what the report says) are asked for again, in case another browser ran it or photos arrived. Not while a run is going here or one of its folds is open.
    if (window.__live) window.__live.register('media', { tab: 'site', refresh: function () {
      var slot = document.getElementById('media-slot'), panel = slot && slot.querySelector('#media-panel'); if (!panel) return;
      var body = new URLSearchParams(); body.set('csrf_token', csrf); body.set('action', 'media_panel');
      return fetch(siteUrl(), { method: 'POST', credentials: 'same-origin', body: body.toString(), headers: { 'X-Requested-With': 'gallery-fetch', 'Content-Type': 'application/x-www-form-urlencoded' } })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j.ok || !j.html || !j.cache) return;
          var fresh = new DOMParser().parseFromString(j.html, 'text/html').getElementById('media-panel');
          if (!fresh || (fresh.getAttribute('data-left') === panel.getAttribute('data-left') && fresh.getAttribute('data-total') === panel.getAttribute('data-total'))) return;
          slot.innerHTML = j.html;
          var np = slot.querySelector('#media-panel'); if (np && window.__initMediaPanel) window.__initMediaPanel(np);
        });
    }, busy: function () {
      var slot = document.getElementById('media-slot'), stop = slot && slot.querySelector('.media-stop');
      return !slot || slot.offsetParent === null || (!!stop && !stop.hidden) || !!slot.querySelector('details[open]');
    } });
    [].forEach.call(form.querySelectorAll('input[type=checkbox][data-label]'), function (cb) {
      cb.addEventListener('change', function () {
        var on = cb.checked, off = cb.getAttribute('data-offwarn');
        var row = cb.closest('.site-row'), note = row ? row.querySelector('.offnote') : null;
        var f = {}; f[cb.name] = on ? '1' : '0';
        // Settings that only matter while this one is on (the pin grouping, under the map) follow it.
        function follow(shown, name) {
          name = name || cb.name;
          [].forEach.call(form.querySelectorAll('.site-row[data-requires="' + name + '"]'), function (r) {
            r.hidden = !shown;
            var c = r.querySelector('input[type=checkbox][name^=set_]');   // what only matters while that one is on follows it too
            if (c) follow(shown && c.checked, c.name);
          });
          [].forEach.call(form.querySelectorAll('.site-row[data-hidden-when="' + name + '"]'), function (r) {
            r.hidden = shown;
            var g = r.closest('details'), tag = g && g.querySelector('[data-hid-group]');   // the group's "(1 hidden)" follows
            if (tag) { var n = g.querySelectorAll('.site-row[data-hidden-when][hidden]').length; tag.querySelector('[data-hid-n]').textContent = n; tag.hidden = n === 0; }
          });
        }
        follow(on);
        function effects(state) { [].forEach.call(row ? row.querySelectorAll('.effect') : [], function (e) { e.hidden = (e.getAttribute('data-effect') === 'on') !== state; }); }
        effects(on);
        // What switching it off does appears beside the switch once it is off (and goes when it is back on), instead of in a dialog.
        post(f, stateOf(cb), function () { cb.checked = !on; follow(!on); effects(!on); }, function () { if (note) note.hidden = on || !off; if (cb.name === 'set_ENABLE_THUMBNAIL_CACHE') mediaPanelFollow(on); });
      });
    });
    // On the Settings page: the switches are saved by the checkbox code above; the order is saved here.
    [].forEach.call(form.querySelectorAll('.sortfirst'), function (box) {
      var status = stateOf(box);
      initSortFirst(box, function (now, ok, fail) { var f = {}; f[box.getAttribute('data-name')] = now.join(','); post(f, status, fail, ok); });
    });
    [].forEach.call(form.querySelectorAll('select[name^=set_], input[type=number][name^=set_]'), function (el) {
      el.setAttribute('data-prev', el.value);
      el.addEventListener('change', function () {
        var f = {}; f[el.name] = el.value;
        post(f, stateOf(el), function () { el.value = el.getAttribute('data-prev'); }, function () { el.setAttribute('data-prev', el.value); });
      });
    });
    [].forEach.call(form.querySelectorAll('.seg, [data-menu]'), function (seg) {
      var radios = seg.querySelectorAll('input[type=radio][name^=set_]');
      if (!radios.length) return;
      var prev = (seg.querySelector('input:checked') || {}).value;
      [].forEach.call(radios, function (r) {
        r.addEventListener('change', function () {
          var f = {}; f[r.name] = r.value;
          post(f, stateOf(r), function () { [].forEach.call(radios, function (x) { x.checked = x.value === prev; }); }, function () { prev = r.value; });
        });
      });
    });
    // The JSON downloads under Files this gallery has made: a small form of its own, built and sent here (this sits inside the settings form, and forms cannot nest).
    [].forEach.call(form.querySelectorAll('[data-export]'), function (b) {
      b.addEventListener('click', function () {
        var f = document.createElement('form'); f.method = 'post'; f.style.display = 'none';
        [['csrf_token', csrf], ['action', b.getAttribute('data-export')]].forEach(function (kv) { var i = document.createElement('input'); i.type = 'hidden'; i.name = kv[0]; i.value = kv[1]; f.appendChild(i); });
        document.body.appendChild(f); f.submit(); setTimeout(function () { document.body.removeChild(f); }, 1000);
      });
    });
    // Expand all / Collapse all for the groups: only the one that can do something is shown (all closed: Expand all; all open: Collapse all; some of each: both).
    (function () {
      var tools = document.querySelector('[data-sg-tools]'); if (!tools) return;
      var groups = [].slice.call(form.querySelectorAll('details.site-group')), all = tools.querySelector('[data-sg=all]'), none = tools.querySelector('[data-sg=none]'), sep = tools.querySelector('.sg-sep');
      function sync() {
        var open = groups.filter(function (g) { return g.open; }).length;
        all.hidden = open === groups.length; none.hidden = open === 0; sep.hidden = all.hidden || none.hidden; tools.hidden = false;
      }
      groups.forEach(function (g) { g.addEventListener('toggle', sync); });
      tools.addEventListener('click', function (e) { var b = e.target.closest ? e.target.closest('[data-sg]') : null; if (!b) return; var want = b.getAttribute('data-sg') === 'all'; groups.forEach(function (g) { g.open = want; }); sync(); });
      sync();
    })();
    // Set aside / Bring back, on the Health check's rows about what was left behind: one request, then the page is read again so the row moves.
    document.addEventListener('click', function (e) {
      var b = e.target.closest ? e.target.closest('[data-aside], [data-aside-back]') : null; if (!b) return;
      var panel = b.closest('#health-panel'); if (!panel) return;
      var back = b.hasAttribute('data-aside-back'), body = new URLSearchParams();
      body.set('csrf_token', panel.getAttribute('data-csrf')); body.set('action', 'health_set_aside'); body.set('key', b.getAttribute(back ? 'data-aside-back' : 'data-aside')); if (back) body.set('back', '1');
      b.disabled = true;
      fetch('?admin', { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Requested-With': 'gallery-fetch' } })
        .then(function (r) { return r.json(); })
        .then(function (j) { if (j.ok) { window.__siteDirty = true; try { sessionStorage.setItem('healthOpen', '1'); } catch (e) {} location.reload(); } else { b.disabled = false; b.parentNode.appendChild(document.createTextNode(' ' + (j.text || 'Not saved.'))); } })
        .catch(function () { b.disabled = false; });
    });
    // A Health row that points at the Visitors tab with something chosen there (the lockouts): the tab opens already narrowed to it.
    document.addEventListener('click', function (e) {
      var a = e.target.closest ? e.target.closest('[data-vt-go]') : null; if (!a) return;
      e.preventDefault(); if (window.__vtShow) window.__vtShow(a.getAttribute('data-vt-go')); adminGoTo('visits');
    });
    // "Remove them" on the older visitor logs row: one request, then the page is read again so the row goes.
    document.addEventListener('click', function (e) {
      var b = e.target.closest ? e.target.closest('[data-oldlogs-remove]') : null; if (!b) return;
      var panel = b.closest('#health-panel'); if (!panel) return;
      var body = new URLSearchParams(); body.set('csrf_token', panel.getAttribute('data-csrf')); body.set('action', 'health_remove_old_logs');
      b.disabled = true;
      fetch('?admin', { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Requested-With': 'gallery-fetch' } })
        .then(function (r) { return r.json(); })
        .then(function (j) { if (j.ok) { window.__siteDirty = true; try { sessionStorage.setItem('healthOpen', '1'); } catch (e) {} location.reload(); } else { b.disabled = false; b.parentNode.appendChild(document.createTextNode(' ' + (j.text || 'Not removed.'))); } })
        .catch(function () { b.disabled = false; });
    });
    // Count views: the views another gallery counted for the photos now shown here (found in each folder's own stats file under an older path)
    // are offered under the setting they belong to, once something is found. Looked for when the group is opened (it reads each folder's file).
    (function () {
      var box = form.querySelector('[data-earlier]'); if (!box) return;
      var text = box.querySelector('.earlier-text'), add = box.querySelector('.earlier-add'), hint = box.querySelector('.earlier-hint'), act = box.querySelector('.earlier-act');
      var group = box.closest('details'), asked = false;
      function fmtN(n) { return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }
      function ask(extra) {
        var body = new URLSearchParams(); body.set('csrf_token', box.getAttribute('data-csrf')); body.set('action', extra || 'media_moved');
        return fetch('?admin', { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Requested-With': 'gallery-fetch' } }).then(function (r) { return r.json(); });
      }
      function look() {
        if (asked) return; asked = true;
        ask().then(function (j) {
          if (!j.ok || !j.track || !(j.views > 0)) return;
          text.textContent = ''; var st = document.createElement('strong'); st.textContent = fmtN(j.views) + ' earlier views'; text.appendChild(st);
          text.appendChild(document.createTextNode(' found for ' + j.viewFolders + (j.viewFolders === 1 ? ' folder' : ' folders') + ', counted by another gallery under their old addresses.'));
          act.hidden = false; hint.hidden = false; add.disabled = false; box.hidden = false;
        }).catch(function () { asked = false; });
      }
      if (group) { group.addEventListener('toggle', function () { if (group.open) look(); }); if (group.open) look(); }
      add.addEventListener('click', function () {
        add.disabled = true; text.textContent = 'Adding…';
        ask('media_moved_views').then(function (j) {
          if (!j.ok) { text.textContent = j.text || 'Not added.'; add.disabled = false; return; }
          text.textContent = 'Added ' + fmtN(j.added) + ' views to ' + j.folders + (j.folders === 1 ? ' folder' : ' folders') + '.';
          act.hidden = true; hint.hidden = true; window.__siteDirty = true;
        }).catch(function () { text.textContent = 'The server did not answer.'; add.disabled = false; });
      });
    })();
    // Search the settings: type a few words and only the settings that have all of them (in the name, the help, the choices, the setting's
    // own word such as TRACK_VIEWS, or the group's title) stay; their groups open by themselves, and clearing the box puts every group back as it was.
    (function () {
      var box = document.querySelector('[data-site-find]'), wrap = document.querySelector('[data-site-find-wrap]'), note = document.querySelector('[data-site-find-count]');
      if (!box || !wrap || !note) return;
      var index = null, saved = null;
      function norm(t) { return String(t || '').toLowerCase().replace(/\s+/g, ' ').trim(); }
      function build() {
        index = [].slice.call(form.querySelectorAll('details.site-group')).map(function (g) {
          var title = norm((g.querySelector('summary') || { textContent: '' }).textContent);
          return {
            el: g, title: title,
            rows: [].slice.call(g.children).filter(function (r) { return r.tagName !== 'SUMMARY'; }).map(function (r) {   // (every block in the group: the settings, and also the server rules and the Prepare media panel)
              var c = r.cloneNode(true), key = (r.id || '').replace(/^setting-/, '');
              [].forEach.call(c.querySelectorAll('.src, script, style, .rowstate'), function (n) { n.parentNode.removeChild(n); });
              return { el: r, text: norm(title + ' ' + c.textContent + ' ' + key + ' ' + key.replace(/_/g, ' ')) };
            })
          };
        });
      }
      function clear() {
        if (!saved) return;
        index.forEach(function (g, i) { g.el.classList.remove('find-miss'); g.rows.forEach(function (r) { r.el.classList.remove('find-miss'); }); g.el.open = saved[i]; });
        saved = null; note.hidden = true;
      }
      function apply() {
        var terms = norm(box.value).split(' ').filter(Boolean);
        if (!terms.length) { clear(); return; }
        if (!index) build();
        if (!saved) saved = index.map(function (g) { return g.el.open; });
        var shown = 0, later = 0;
        function has(t) { return terms.every(function (w) { return t.indexOf(w) >= 0; }); }
        index.forEach(function (g) {
          var any = 0;
          g.rows.forEach(function (r) {
            var ok = has(r.text);
            if (ok && r.el.hidden) { later++; ok = false; }   // (a setting that only shows while another is on: counted, not shown)
            r.el.classList.toggle('find-miss', !ok);
            if (ok) any++;
          });
          var own = !g.rows.length && has(g.title);   // (a group with nothing in it yet matches by its title)
          g.el.classList.toggle('find-miss', !(any || own));
          if (any) g.el.open = true;
          shown += any + (own ? 1 : 0);
        });
        note.hidden = false;
        note.textContent = (shown ? shown + ' found' : 'Nothing matches \u201c' + box.value.trim() + '\u201d.')
          + (later ? (shown ? '. ' : ' ') + later + (later === 1 ? ' more matches' : ' more match') + ' but only show while another setting is on.' : '');
      }
      box.addEventListener('focus', function () { if (!saved) index = null; });   // (rows built later, such as the folder lists, are read again)
      box.addEventListener('input', apply);
      box.addEventListener('keydown', function (e) { if (e.key === 'Escape' && box.value) { e.preventDefault(); box.value = ''; apply(); } else if (e.key === 'Enter') e.preventDefault(); });
      form.addEventListener('change', function () { if (saved) setTimeout(apply, 0); });
      document.addEventListener('gallery-settings-find-clear', function () { if (box.value) { box.value = ''; apply(); } });
      wrap.hidden = false;
    })();
    // The menu buttons: open and close the list, show the chosen label; the radios inside do the saving (the handlers above and below).
    (function () {
      var open = null;
      function close(m, focus) { if (!m) return; m.querySelector('.menu-pop').hidden = true; var b = m.querySelector('.menu-btn'); b.setAttribute('aria-expanded', 'false'); if (open === m) open = null; if (focus) b.focus(); }
      function show(m) {
        if (open && open !== m) close(open);
        m.querySelector('.menu-pop').hidden = false; m.querySelector('.menu-btn').setAttribute('aria-expanded', 'true'); open = m;
        var c = m.querySelector('input:checked') || m.querySelector('input'); if (c) c.focus();
      }
      [].forEach.call(form.querySelectorAll('[data-menu]'), function (m) {
        var btn = m.querySelector('.menu-btn'), pop = m.querySelector('.menu-pop');
        btn.addEventListener('click', function () { if (pop.hidden) show(m); else close(m); });
        m.addEventListener('change', function (e) {
          var r = e.target; if (!r || r.type !== 'radio') return;
          var t = r.closest('.menu-opt').querySelector('.menu-text').textContent;
          m.querySelector('.menu-label').textContent = t; btn.setAttribute('aria-label', (pop.getAttribute('aria-label') || '') + ': ' + t);
          close(m, true);
        });
        m.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !pop.hidden) { e.stopPropagation(); close(m, true); } });
      });
      document.addEventListener('click', function (e) { if (open && !open.contains(e.target)) close(open); });
    })();
    // Rows that only matter for one choice of another setting (the Flat switches under Folder style) follow that choice.
    function syncShown() {
      [].forEach.call(form.querySelectorAll('.site-row[data-shown-when]'), function (r) {
        var spec = r.getAttribute('data-shown-when').split('='), c = form.querySelector('input[name="set_' + spec[0] + '"]:checked'), ok = !!c && c.value === spec[1], req = r.getAttribute('data-requires');
        if (ok && req) { var rc = form.querySelector('input[type=checkbox][name="' + req + '"]'); ok = !!(rc && rc.checked); }
        r.hidden = !ok;
      });
    }
    form.addEventListener('change', function () { setTimeout(syncShown, 0); });
    // Photo order: one control for the order and its direction; each choice saves both settings together.
    [].forEach.call(form.querySelectorAll('.seg, [data-menu]'), function (seg) {
      var radios = seg.querySelectorAll('input[type=radio][name=pair_PHOTO_ORDER]');
      if (!radios.length) return;
      var prev = (seg.querySelector('input:checked') || {}).value;
      [].forEach.call(radios, function (r) {
        r.addEventListener('change', function () {
          var parts = r.value.split('_'), f = { set_PHOTO_SORT_ORDER: parts[0], set_PHOTO_SORT_DESC: parts[1] === 'desc' ? '1' : '0' };
          post(f, stateOf(r), function () { [].forEach.call(radios, function (x) { x.checked = x.value === prev; }); }, function () { prev = r.value; });
        });
      });
    });
    [].forEach.call(form.querySelectorAll('input[type=text][name^=set_]'), function (el) {
      el.setAttribute('data-prev', el.value);
      el.addEventListener('change', function () {
        el.value = el.value.trim();
        var f = {}; f[el.name] = el.value;
        post(f, stateOf(el), function () { el.value = el.getAttribute('data-prev'); }, function () { el.setAttribute('data-prev', el.value); });
      });
    });
    [].forEach.call(form.querySelectorAll('.extra-folders'), function (box) {
      var state = { named: {}, roots: [] };
      try { var parsed = JSON.parse(box.getAttribute('data-map')) || {}; state.named = parsed.named || {}; state.roots = parsed.roots || []; } catch (e) {}
      var list = box.querySelector('.extra-list'), none = box.querySelector('.extra-none'), status = stateOf(box);
      function item(parts, kind, key, note) {
        var li = document.createElement('li'), what = document.createElement('span'), btn = document.createElement('button');
        what.className = 'extra-what';
        parts.forEach(function (p) { what.appendChild(p); what.appendChild(document.createTextNode(' ')); });
        btn.type = 'button'; btn.className = 'extra-remove secondary'; btn.textContent = 'Remove'; btn.setAttribute('data-kind', kind); btn.setAttribute('data-name', key);
        var edit = document.createElement('button'), acts = document.createElement('span');
        edit.type = 'button'; edit.className = 'extra-edit secondary'; edit.textContent = 'Edit'; edit.setAttribute('data-kind', kind); edit.setAttribute('data-name', key);
        acts.className = 'extra-acts'; acts.appendChild(edit); acts.appendChild(btn);
        li.appendChild(what); li.appendChild(acts);
        if (note) { var n = document.createElement('span'); n.className = 'muted extra-note'; n.textContent = note; li.appendChild(n); }
        list.appendChild(li);
      }
      function el(tag, text, cls) { var e = document.createElement(tag); e.textContent = text; if (cls) e.className = cls; return e; }
      function draw() {
        list.textContent = '';
        state.roots.forEach(function (p) { item([el('code', p)], 'root', p, '\u2192 its folders are at the top of the gallery'); });
        Object.keys(state.named).forEach(function (name) { item([el('strong', name), el('span', '\u2192', 'muted'), el('code', state.named[name])], 'named', name); });
        none.hidden = state.roots.length + Object.keys(state.named).length > 0;
      }
      function copy() { var n = {}; Object.keys(state.named).forEach(function (k) { n[k] = state.named[k]; }); return { named: n, roots: state.roots.slice() }; }
      function send(next, onOk, edit) {
        var f = {}, payload = Object.assign({}, next); if (edit) payload.edit = edit;
        f[box.getAttribute('data-name')] = JSON.stringify(payload);
        post(f, status, null, function (j) { state = (j.extra && typeof j.extra === 'object') ? { named: j.extra.named || {}, roots: j.extra.roots || [] } : next; draw(); if (onOk) onOk(); });
      }
      box.querySelector('.extra-add-btn').addEventListener('click', function () {
        var n = box.querySelector('.extra-name').value.trim(), p = box.querySelector('.extra-path').value.trim();
        if (!p) { say(status, 'Give the full path of the place to add.', 'err'); return; }
        var next = copy();
        if (n) next.named[n] = p; else next.roots.push(p);
        send(next, function () { box.querySelector('.extra-name').value = ''; box.querySelector('.extra-path').value = ''; });
      });
      // Edit: the entry's name and path in two boxes in its own row; Save changes it in one step. The place keeps its identity, so its view counts
      // follow it (the server remembers the names it has had); only addresses with the old name stop working.
      function openEdit(b) {
        var kind = b.getAttribute('data-kind'), key = b.getAttribute('data-name'), path = kind === 'root' ? key : state.named[key], li = b.closest('li');
        var oldName = kind === 'root' ? '' : key;
        li.textContent = ''; li.classList.add('extra-editing');
        var row = document.createElement('div'); row.className = 'extra-add extra-editrow';
        var nm = document.createElement('input'), pa = document.createElement('input'), hint = el('p', '', 'muted extra-ehint'), bar = document.createElement('div'), save = el('button', 'Save', 'extra-save'), cancel = el('button', 'Cancel', 'extra-cancel secondary');
        nm.type = 'text'; nm.className = 'extra-ename'; nm.value = oldName; nm.placeholder = 'Name (optional)'; nm.maxLength = 60; nm.autocomplete = 'off'; nm.spellcheck = false; nm.setAttribute('aria-label', 'Name (optional)');
        pa.type = 'text'; pa.className = 'extra-epath'; pa.value = path; pa.placeholder = '/full/path/to/the/place'; pa.autocomplete = 'off'; pa.spellcheck = false; pa.setAttribute('aria-label', 'Path to the place');
        save.type = 'button'; cancel.type = 'button'; bar.className = 'extra-ebar'; bar.appendChild(save); bar.appendChild(cancel);
        function words() {
          var n = nm.value.trim(), t = [];
          if (n !== oldName) t.push(oldName === '' ? 'Giving it a name shows the whole place as one folder with that name.' : (n === '' ? 'With no name, its folders appear at the top of the gallery.' : 'Addresses with the old name (\u201c?folder=' + oldName + '/\u2026\u201d) stop working.'));
          if (n !== oldName && oldName !== '' && n !== '') t.push('Its view counts follow it. Share links and Not listed addresses are not affected.');
          hint.textContent = t.join(' '); hint.hidden = !t.length;
        }
        nm.addEventListener('input', words); words();
        row.appendChild(nm); row.appendChild(pa); row.appendChild(hint); row.appendChild(bar); li.appendChild(row); nm.focus();
        cancel.addEventListener('click', draw);
        save.addEventListener('click', function () {
          var n = nm.value.trim(), p = pa.value.trim(), next = copy();
          if (!p) { say(status, 'Give the full path of the place.', 'err'); return; }
          if (kind === 'root') next.roots = next.roots.filter(function (r) { return r !== key; }); else delete next.named[key];
          if (n) next.named[n] = p; else next.roots.push(p);
          if (n === oldName && p === path) { draw(); return; }
          send(next, function () { say(status, n !== oldName && oldName !== '' && n !== '' ? 'Saved. Its view counts follow the place; addresses with the old name no longer work.' : 'Saved.', 'ok'); }, { path: path, label: n });
        });
      }
      list.addEventListener('click', function (ev) {
        var eb = ev.target.closest ? ev.target.closest('.extra-edit') : null;
        if (eb) { openEdit(eb); return; }
        var b = ev.target.closest ? ev.target.closest('.extra-remove') : null;
        if (!b) return;
        var n = b.getAttribute('data-name'), next = copy();
        if (b.getAttribute('data-kind') === 'root') next.roots = next.roots.filter(function (r) { return r !== n; }); else delete next.named[n];
        send(next, function () { say(status, 'Removed. Nothing was deleted: its photos and their captions stay where they are.', 'ok'); });
      });
      draw();
    });
    [].forEach.call(form.querySelectorAll('.pw-btn'), function (btn) {
      function go() {
        var kind = btn.getAttribute('data-pw'), node = stateOf(btn), f = {}, els = form.elements;
        if (kind === 'admin') { f.pw_admin = els['pw_admin'].value; f.pw_admin2 = els['pw_admin2'].value; }
        else if (kind === 'private') { f.pw_private = els['pw_private'].value; f.pw_private2 = els['pw_private2'].value; }
        else { f.pw_private_remove = '1'; }
        if ((kind === 'admin' && !f.pw_admin) || (kind === 'private' && !f.pw_private)) { say(node, 'Type the new password first.', 'err'); return; }
        post(f, node, null, function () {
          ['pw_admin', 'pw_admin2', 'pw_private', 'pw_private2'].forEach(function (n) { if (els[n]) els[n].value = ''; });
        });
      }
      btn.addEventListener('click', go);
      if (btn.getAttribute('data-pw') === 'private-remove') return;
      // Enter in a password box does what the button next to it does.
      [].forEach.call(btn.closest('.ctl').querySelectorAll('input[type=password]'), function (input) {
        input.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); btn.click(); } });
      });
    });
  });
  // Signed-in browsers (in Settings, under Signing in): end one, or every other.
  (function () {
    var row = document.getElementById('browsers-row');
    if (!row) return;
    var csrf = row.getAttribute('data-csrf'), armed = null;
    function say(t, cls) { var n = row.querySelector('.rowstate'); if (n) { n.textContent = t; n.className = 'rowstate ' + (cls || ''); } }   // (looked up each time: the row can be drawn again)
    function send(fields, done) {
      var body = new URLSearchParams(Object.assign({ csrf_token: csrf }, fields));
      fetch('?admin', { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Requested-With': 'gallery-fetch' } })
        .then(function (r) { return r.json(); })
        .then(function (j) { if (j && j.ok) done(j); else say((j && j.text) || 'Could not do that.', 'err'); })
        .catch(function () { say('Could not reach the gallery.', 'err'); });
    }
    row.addEventListener('click', function (ev) {
      var b = ev.target.closest ? ev.target.closest('[data-br]') : null;
      if (!b || b.disabled) return;
      var what = b.getAttribute('data-br');
      if (what === 'details') {   // a browser's addresses, fetched when first asked for
        var item = b.closest('tbody[data-id]'), drow = item.querySelector('.br-detailrow'), box = drow.querySelector('.br-detail'), open = drow.hidden;
        var tog = item.querySelector('.br-more'); drow.hidden = !open;
        if (tog) { tog.setAttribute('aria-expanded', open ? 'true' : 'false'); tog.textContent = open ? 'Hide history' : 'History'; }
        if (open && !box.getAttribute('data-loaded')) {
          box.innerHTML = '<p class="muted">Loading…</p>';
          fetch('?admin&browserhist=' + encodeURIComponent(item.getAttribute('data-id')), { credentials: 'same-origin', headers: { 'X-Requested-With': 'gallery-fetch' } })
            .then(function (r) { return r.json(); })
            .then(function (j) { box.innerHTML = j && j.ok ? j.html : '<p class="muted">Could not load it.</p>'; box.setAttribute('data-loaded', '1'); })
            .catch(function () { box.innerHTML = '<p class="muted">Could not load it.</p>'; });
        }
        return;
      }
      if (what === 'ack') {   // "Looks fine": clear that flag
        var fl = b.closest('.br-flag'), li0 = b.closest('tbody[data-id]');
        send({ action: 'browser_ack', id: li0.getAttribute('data-id'), flag: fl.getAttribute('data-flag') }, function (j) {
          var frow = fl.closest('tr'); frow.parentNode.removeChild(frow);
          if (!li0.querySelector('.br-flag')) li0.classList.remove('br-flagged');
          say(j.text, 'ok');
        });
        return;
      }
      if (b.getAttribute('data-br') === 'cancel') {
        var li = b.closest('tbody[data-id]');
        send({ action: 'browser_cancel', id: li.getAttribute('data-id') }, function (j) { li.parentNode.removeChild(li); say(j.text, 'ok'); });
        return;
      }
      if (armed !== b) {   // (one more press to be sure: this signs out every phone and computer but this one)
        armed = b; b.textContent = 'Press again to sign them all out';
        setTimeout(function () { if (armed === b) { armed = null; b.textContent = 'Sign out all other browsers'; } }, 5000);
        return;
      }
      armed = null; b.textContent = 'Sign out all other browsers';
      send({ action: 'browser_cancel_others' }, function (j) {
        [].forEach.call(row.querySelectorAll('.br-table:not(.br-table-ended) tbody.br-item:not(.br-me)'), function (li) { li.parentNode.removeChild(li); });
        b.disabled = true; say(j.text, 'ok');
      });
    });
    // The window came back: the list is drawn again (another browser signed in or out), unless a sign-out is waiting for its second press, a browser's history is open, or the row is folded away.
    if (window.__live) window.__live.register('browsers', { tab: 'site', refresh: function () {
      return fetch('?admin&browserspanel=1', { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.text(); }).then(function (html) {
        var fresh = new DOMParser().parseFromString(html, 'text/html').getElementById('browsers-row');
        if (fresh && fresh.innerHTML !== row.innerHTML) row.innerHTML = fresh.innerHTML;
      });
    }, busy: function () { return armed !== null || !!row.querySelector('.br-detailrow:not([hidden])') || row.offsetParent === null; } });
  })();
  // The country file (Settings, under Signing in): what it needs, turn it on (downloads it), refresh, turn off.
  (function () {
    var row = document.getElementById('geo-row');
    if (!row) return;
    var csrf = row.getAttribute('data-csrf'), note = row.querySelector('.rowstate'), need = row.querySelector('[data-geo-need]'), del = row.querySelector('[data-geo-del]'), ready = false, offReady = false;
    function say(t, cls) { if (note) { note.textContent = t; note.className = 'rowstate ' + (cls || ''); } }
    function post(fields, done) {
      var body = new URLSearchParams(Object.assign({ csrf_token: csrf }, fields));
      fetch('?admin', { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Requested-With': 'gallery-fetch' } })
        .then(function (r) { return r.json(); }).then(done).catch(function () { say('Could not reach the gallery.', 'err'); });
    }
    row.addEventListener('click', function (ev) {
      var b = ev.target.closest ? ev.target.closest('[data-geo]') : null;
      if (!b || b.disabled) return;
      var what = b.getAttribute('data-geo');
      if (what === 'on') {
        if (!ready) {   // first press: ask what it would need, say so, and wait for a second press
          say('Checking…', ''); b.disabled = true;
          post({ action: 'geo_probe' }, function (j) {
            b.disabled = false;
            if (!j.ok) { say(j.text, 'err'); return; }
            if (!j.canFetch || !j.zlib) { say(!j.canFetch ? 'This server does not let PHP fetch addresses (no curl, and allow_url_fopen is off), so it can not download the file. Download ' + j.url + ' yourself, unpack it and put it here as .gallery-geo.php.' : 'This PHP has no zlib, which unpacking the file needs.', 'err'); return; }
            need.hidden = false;
            need.textContent = 'It downloads ' + j.gz + ' and takes ' + j.raw + (j.exact ? '' : ' (about)') + ' on disk, and needs about ' + j.need + ' free while it unpacks' + (j.free ? ' (this server has ' + j.free + ' free)' : '') + '.' + (j.enough ? '' : ' That is more room than there is.');
            if (!j.enough) { say('Not enough room.', 'err'); return; }
            ready = true; b.textContent = 'Download and turn on'; say('', '');
          });
          return;
        }
        say('Downloading… this can take a few seconds.', ''); b.disabled = true;
        post({ action: 'geo_enable' }, function (j) { if (j.ok) { say(j.text, 'ok'); setTimeout(function () { location.reload(); }, 900); } else { b.disabled = false; say(j.text, 'err'); } });
        return;
      }
      if (what === 'refresh') {
        say('Looking for a newer file…', ''); b.disabled = true;
        post({ action: 'geo_refresh' }, function (j) { b.disabled = false; say(j.text, j.ok ? 'ok' : 'err'); if (j.ok) setTimeout(function () { location.reload(); }, 900); });
        return;
      }
      if (what === 'off') {
        if (!offReady) { offReady = true; del.hidden = false; b.textContent = 'Press again to turn off'; return; }
        post({ action: 'geo_disable', delete: row.querySelector('[data-geo-delete]').checked ? '1' : '' }, function (j) { say(j.text, j.ok ? 'ok' : 'err'); if (j.ok) setTimeout(function () { location.reload(); }, 900); });
      }
    });
  })();
  // The line under a folder's download switch ("The 5 folders inside it have downloads off. Allow downloads in all"): one press does it, once (nothing
  // links the folders afterwards), and the line is redrawn for where things now stand. It shows only while the folder's own switch is on.
  [].forEach.call(document.querySelectorAll('[data-dl-inside]'), function (box) {
    var csrf = box.getAttribute('data-csrf'), body = box.querySelector('.dl-inside-body'), state = box.querySelector('.rowstate');
    var sw = box.closest('.dl-block').querySelector('input[type=checkbox][name=dl]'), swForm = sw && sw.closest('form');
    if (swForm) swForm.addEventListener('instant-saved', function () { box.hidden = !sw.checked; });
    function go(mode) {
      var fd = new URLSearchParams(); fd.set('csrf_token', csrf); fd.set('action', 'downloads_inside'); fd.set('mode', mode); fd.set('apply', '1');
      state.textContent = 'Saving…';
      fetch(location.pathname + location.search, { method: 'POST', credentials: 'same-origin', body: fd.toString(), headers: { 'X-Requested-With': 'gallery-fetch', 'Content-Type': 'application/x-www-form-urlencoded' } })
        .then(function (r) { if ((r.headers.get('content-type') || '').indexOf('json') < 0) throw new Error('session'); return r.json(); })
        .then(function (j) {
          if (!j.ok) { state.textContent = j.text; return; }
          if (j.html) body.innerHTML = j.html;
          window.__siteDirty = true; state.textContent = j.short || ''; clearTimeout(state._t); state._t = setTimeout(function () { state.textContent = ''; }, 3000);
        })
        .catch(function (e) { state.textContent = e && e.message === 'session' ? 'Your session ended: reload the page and sign in again.' : 'Couldn\'t save: check your connection.'; });
    }
    box.addEventListener('click', function (e) {
      var b = e.target.closest ? e.target.closest('.dl-in-on, .dl-in-off') : null; if (!b) return;
      go(b.classList.contains('dl-in-on') ? 'on' : 'off');
    });
  });
  // The Cover photo row of a folder that holds folders: the cover in use, and a picker with a tile for each folder under it (the folder then
  // follows that folder's cover), or any photo inside it by "Pick any photo instead". Each choice is one request and shows at once.
  [].forEach.call(document.querySelectorAll('[data-cover]'), function (box) {
    var form = box.closest('form'), csrf = form ? form.querySelector('input[name=csrf_token]').value : '';
    var pick = box.querySelector('.cv-pick'), any = box.querySelector('.cv-anywhere'), state = box.querySelector('.rowstate');
    function say(text, kind) { state.textContent = text; state.className = 'rowstate ' + (kind || ''); clearTimeout(state._t); if (kind === 'ok') state._t = setTimeout(function () { state.textContent = ''; state.className = 'rowstate'; }, 3000); }
    function send(fields) {
      var body = new URLSearchParams(); body.set('csrf_token', csrf); Object.keys(fields).forEach(function (k) { body.set(k, fields[k]); });
      return fetch(location.pathname + location.search, { method: 'POST', credentials: 'same-origin', body: body.toString(), headers: { 'X-Requested-With': 'gallery-fetch', 'Content-Type': 'application/x-www-form-urlencoded' } })
        .then(function (r) { if ((r.headers.get('content-type') || '').indexOf('json') < 0) throw new Error('session'); return r.json(); });
    }
    function fail(e) { say(e && e.message === 'session' ? 'Your session ended: reload the page and sign in again.' : 'Couldn\'t save: check your connection.', 'err'); }
    function show(c) {
      var img = box.querySelector('.cv-img'); if (c.url) { img.src = c.url; img.hidden = false; } else img.hidden = true;
      box.querySelector('.cv-title').textContent = c.title; box.querySelector('.cv-small').textContent = c.small;
      box.querySelector('.cv-auto').hidden = c.mode === 'auto';
      [].forEach.call(box.querySelectorAll('.cv-tile'), function (t) { t.classList.toggle('sel', t.getAttribute('data-child') === c.from); });
    }
    function choose(fields) {
      say('Saving…', 'busy');
      return send(Object.assign({ action: 'set_folder_cover' }, fields)).then(function (j) {
        if (!j.ok) { say(j.text, 'err'); return; }
        if (j.cover) show(j.cover); pick.hidden = true; say('Saved', 'ok'); window.__siteDirty = true;
      }).catch(fail);
    }
    box.querySelector('.cv-choose').addEventListener('click', function () { pick.hidden = !pick.hidden; });
    box.querySelector('.cv-cancel').addEventListener('click', function () { pick.hidden = true; any.hidden = true; });
    box.querySelector('.cv-auto').addEventListener('click', function () { choose({ mode: 'auto' }); });
    box.querySelector('.cv-any').addEventListener('click', function () { any.hidden = !any.hidden; });
    box.addEventListener('click', function (e) {
      var tile = e.target.closest ? e.target.closest('.cv-tile') : null;
      if (tile) { choose({ mode: 'follow', child: tile.getAttribute('data-child') }); return; }
      var ph = e.target.closest ? e.target.closest('.cv-photo') : null;
      if (ph) { choose({ mode: 'pin', file: ph.getAttribute('data-path') }); return; }
      var tab = e.target.closest ? e.target.closest('.cv-tab') : null;
      if (!tab) return;
      [].forEach.call(box.querySelectorAll('.cv-tab'), function (t) { t.classList.toggle('on', t === tab); });
      var holder = box.querySelector('.cv-photos'); holder.textContent = 'Loading…';
      send({ action: 'cover_photos', child: tab.getAttribute('data-child') }).then(function (j) {
        holder.textContent = '';
        if (!j.ok) { holder.textContent = j.text; return; }
        var grid = document.createElement('div'); grid.className = 'cv-grid cv-photogrid';
        (j.photos || []).forEach(function (p) {
          var b = document.createElement('button'), i = document.createElement('img');
          b.type = 'button'; b.className = 'cv-photo'; b.setAttribute('data-path', p.path); b.setAttribute('aria-label', p.path);
          i.src = p.url; i.alt = ''; i.loading = 'lazy'; b.appendChild(i); grid.appendChild(b);
        });
        holder.appendChild(grid);
        if (!(j.photos || []).length) holder.textContent = 'No photos here.';
        if (j.more) { var n = document.createElement('p'); n.className = 'hint'; n.textContent = 'The first 300 are shown. For a photo further in, set the cover of the folder that holds it, then follow that folder.'; holder.appendChild(n); }
      }).catch(function () { holder.textContent = 'Couldn\'t load the photos.'; });
    });
  });
  // Collections, the owner's side: make one from a name, delete one, and look after a collection's members. Each press is one request to the page's own
  // address; nothing here touches a folder or a photo.
  (function () {
    function request(csrf, fields) {
      var body = new URLSearchParams(); body.set('csrf_token', csrf); Object.keys(fields).forEach(function (k) { body.set(k, fields[k]); });
      return fetch(location.pathname + location.search, { method: 'POST', credentials: 'same-origin', body: body.toString(), headers: { 'X-Requested-With': 'gallery-fetch', 'Content-Type': 'application/x-www-form-urlencoded' } })
        .then(function (r) { if ((r.headers.get('content-type') || '').indexOf('json') < 0) throw new Error('session'); return r.json(); });
    }
    function go(url) { location.href = url + (window.parent !== window && /[?&]inframe=1/.test(location.search) ? '&inframe=1' : ''); }
    function note(node, text, kind) { if (!node) return; node.textContent = text; node.className = 'rowstate ' + (kind || ''); }
    function failText(e) { return e && e.message === 'session' ? 'Your session ended: reload the page and sign in again.' : 'Couldn\'t save: check your connection.'; }
    function csrfOf(el) { var h = el.closest('[data-csrf]'); return h ? h.getAttribute('data-csrf') : ''; }
    // New collection: just a name, then its own settings page.
    document.addEventListener('submit', function (ev) {
      var f = ev.target.closest ? ev.target.closest('.cm-new') : null; if (!f) return;
      ev.preventDefault();
      var sec = f.closest('.cm-section'), input = f.querySelector('input[name=title]'), st = f.querySelector('.rowstate'), btn = f.querySelector('button[type=submit]');
      if (!input.value.trim()) { note(st, 'Give the collection a name.', 'err'); input.focus(); return; }
      btn.disabled = true; note(st, 'Making…', 'busy');
      request(csrfOf(f), { action: 'collection_create', parent: sec.getAttribute('data-parent') || '', title: input.value.trim() })
        .then(function (j) { if (j.ok) go(j.url); else { btn.disabled = false; note(st, j.text, 'err'); } })
        .catch(function (e) { btn.disabled = false; note(st, failText(e), 'err'); });
    });
    document.addEventListener('click', function (ev) {
      var del = ev.target.closest ? ev.target.closest('.cm-del') : null;
      if (del) {
        if (!confirm('Delete the collection "' + del.getAttribute('data-title') + '"?\n\nOnly the collection is deleted. Every folder and photo in it stays exactly where it is.')) return;
        del.disabled = true;
        request(csrfOf(del), { action: 'collection_delete', id: del.getAttribute('data-id') }).then(function (j) { if (j.ok) location.reload(); else { del.disabled = false; alert(j.text); } }).catch(function (e) { del.disabled = false; alert(failText(e)); });
        return;
      }
      var members = ev.target.closest ? ev.target.closest('.cm-members') : null; if (!members) return;
      var csrf = members.getAttribute('data-csrf'), id = members.getAttribute('data-id');
      var rm = ev.target.closest('.cm-rm');
      if (rm) {
        rm.disabled = true;
        request(csrf, { action: 'collection_member_remove', id: id, key: rm.closest('.cm-row').getAttribute('data-key') }).then(function (j) { if (j.ok) location.reload(); else { rm.disabled = false; alert(j.text); } }).catch(function (e) { rm.disabled = false; alert(failText(e)); });
        return;
      }
      if (ev.target.closest('.cm-add')) openPicker(members, csrf, id);
    });
    document.addEventListener('change', function (ev) {
      var r = ev.target.closest ? ev.target.closest('.cm-seg input[type=radio]') : null; if (!r) return;
      var members = r.closest('.cm-members'), row = r.closest('.cm-row');
      request(members.getAttribute('data-csrf'), { action: 'collection_member_mode', id: members.getAttribute('data-id'), fid: row.getAttribute('data-fid'), mode: r.value }).then(function (j) { if (j.ok) location.reload(); else alert(j.text); }).catch(function (e) { alert(failText(e)); });
    });
    // Show on front page follows Private collection: a private collection is not on the front page, so that switch waits (and keeps what it was).
    function syncFront(box, priv) {
      var front = box.querySelector('.coll-front'); if (!front) return;
      var cb = front.querySelector('input[type=checkbox]');
      front.classList.toggle('is-off', priv);
      if (cb) {
        if (priv) { front.setAttribute('data-saved', cb.checked ? '1' : (front.getAttribute('data-saved') || '0')); cb.checked = false; cb.disabled = true; cb.title = 'A private collection is not shown on the front page'; }
        else { cb.disabled = false; cb.title = ''; cb.checked = front.getAttribute('data-saved') === '1'; }
      }
    }
    [].forEach.call(document.querySelectorAll('.coll-switches'), function (box) {
      var front = box.querySelector('.coll-front'); if (front) front.setAttribute('data-saved', (front.querySelector('input[type=checkbox]') || {}).checked ? '1' : (box.getAttribute('data-saved-front') || '0'));
      if (box.getAttribute('data-private') === '1') syncFront(box, true);
    });
    document.addEventListener('instant-saved', function (ev) {
      var box = ev.target.closest ? ev.target.closest('.coll-switches') : null; if (!box) return;
      var d = ev.detail || {};
      if (ev.target.classList.contains('coll-front')) { var c = ev.target.querySelector('input[type=checkbox]'); ev.target.setAttribute('data-saved', c && c.checked ? '1' : '0'); }
      if (typeof d.private === 'boolean') syncFront(box, d.private);
    });
    // The picker: folders as a tree to tick, a folder opened to tick single photos; one Add at the end.
    function openPicker(members, csrf, id) {
      var box = document.getElementById('cm-picker'), folders = [], have = { folders: [], photos: [] };
      try { folders = JSON.parse(members.getAttribute('data-folders') || '[]'); have = JSON.parse(members.getAttribute('data-have') || '{}'); } catch (e) {}
      var haveF = {}, haveP = {}; (have.folders || []).forEach(function (p) { haveF[p] = true; }); (have.photos || []).forEach(function (p) { haveP[p] = true; });
      var chosenF = {}, chosenP = {};
      function esc(t) { return String(t).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
      var rows = folders.map(function (o) {
        return '<li style="padding-left:' + (Math.min(o.d, 6) * 14) + 'px"><label><input type="checkbox" data-f="' + esc(o.p) + '"' + (haveF[o.p] ? ' checked disabled' : '') + '> <span>' + (o.x ? '🔒 ' : '') + esc(o.n) + (o.u ? ' <small class="muted">Not listed</small>' : '') + (haveF[o.p] ? ' <small class="muted">already in</small>' : '') + '</span></label> <button type="button" class="linklike" data-open="' + esc(o.p) + '">photos</button><div class="cm-grid" data-grid="' + esc(o.p) + '" hidden></div></li>';
      }).join('');
      box.innerHTML = '<div class="cm-pick"><strong>Add to ' + esc(members.getAttribute('data-title')) + '</strong><p class="hint" style="margin:2px 0 8px">Tick a folder to add all of it, or open a folder’s photos to pick single ones.</p><ul>' + rows + '</ul><div class="cm-foot"><button type="button" class="primary" data-pick-add disabled>Add</button><button type="button" class="secondary" data-pick-cancel>Cancel</button><span class="rowstate" role="status" aria-live="polite"></span></div></div>';
      box.hidden = false;
      var addBtn = box.querySelector('[data-pick-add]'), st = box.querySelector('.rowstate');
      function count() { var n = Object.keys(chosenF).length + Object.keys(chosenP).length; addBtn.disabled = n === 0; addBtn.textContent = n === 0 ? 'Add' : 'Add ' + n + (n === 1 ? ' item' : ' items'); }
      box.addEventListener('change', function (e) {
        var c = e.target; if (c.tagName !== 'INPUT') return;
        if (c.hasAttribute('data-f')) { if (c.checked) chosenF[c.getAttribute('data-f')] = true; else delete chosenF[c.getAttribute('data-f')]; }
        else if (c.hasAttribute('data-p')) { if (c.checked) chosenP[c.getAttribute('data-p')] = true; else delete chosenP[c.getAttribute('data-p')]; }
        count();
      });
      box.addEventListener('click', function (e) {
        if (e.target.closest('[data-pick-cancel]')) { box.hidden = true; box.innerHTML = ''; return; }
        var open = e.target.closest('[data-open]');
        if (open) {
          var folder = open.getAttribute('data-open'), grid = box.querySelector('.cm-grid[data-grid="' + (window.CSS && CSS.escape ? CSS.escape(folder) : folder) + '"]');
          if (!grid) return;
          if (!grid.hidden) { grid.hidden = true; return; }
          if (grid.getAttribute('data-loaded')) { grid.hidden = false; return; }
          grid.hidden = false; grid.textContent = 'Loading…';
          request(csrf, { action: 'collection_photos', folder: folder }).then(function (j) {
            grid.setAttribute('data-loaded', '1');
            if (!j.ok || !j.photos.length) { grid.textContent = 'No photos directly in this folder.'; return; }
            grid.innerHTML = j.photos.map(function (p) { var path = (folder === '' ? '' : folder + '/') + p.file, in_ = haveP[path]; return '<label title="' + esc(p.file) + '"><img src="' + esc(p.thumb) + '" alt="" loading="lazy"><input type="checkbox" data-p="' + esc(path) + '"' + (in_ ? ' checked disabled' : '') + (chosenP[path] ? ' checked' : '') + '></label>'; }).join('');
          }).catch(function (er) { grid.textContent = failText(er); });
          return;
        }
        if (e.target.closest('[data-pick-add]')) {
          var items = Object.keys(chosenF).map(function (p) { return { path: p }; });
          Object.keys(chosenP).forEach(function (path) { var i = path.lastIndexOf('/'); items.push({ path: i < 0 ? '' : path.slice(0, i), file: path.slice(i + 1) }); });
          addBtn.disabled = true; note(st, 'Adding…', 'busy');
          var send = function () { return request(csrf, { action: 'collection_member_add', id: id, items: JSON.stringify(items) }).then(function (j) { if (j.ok) location.reload(); else { addBtn.disabled = false; note(st, j.text, 'err'); } }); };
          // A courtesy: if share links include this collection and what is being added is private, say what they would gain before it is done.
          request(csrf, { action: 'collection_member_add', id: id, items: JSON.stringify(items), check: '1' }).then(function (j) {
            if (j.ok && j.warn && !confirm(j.warn + '\n\nAdd it anyway?')) { addBtn.disabled = false; note(st, '', ''); return; }
            return send();
          }).catch(function (er) { addBtn.disabled = false; note(st, failText(er), 'err'); });
        }
      });
      box.scrollIntoView({ block: 'nearest' });
    }
  })();
  // ---- The Visitors tab: the pills (All = countries, Public, Share links), the search, the four views, the export menu and Trim the log.
  // The table under them is a fragment the server builds (?admin&visits=1) from the same choices, so nothing is filtered here.
  (function () {
    var root = document.getElementById('vt-root');
    if (!root || !root.getAttribute('data-info')) return;
    var S = { cc: [], src: [], q: '', view: 'visitors', folders: [], exact: false, what: 'viewed', all: false, pop: '' };
    var reqId = 0, searchTimer = null, toastTimer = null;
    function info() { try { return JSON.parse(root.getAttribute('data-info')); } catch (e) { return { total: 0, pub: 0, lock: 0, links: [], countries: [] }; } }
    function $(sel) { return root.querySelector(sel); }
    function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function say(text, bad) {
      var t = document.getElementById('vt-toast'); if (!t) return;
      clearTimeout(toastTimer); t.className = 'pv-toast' + (bad ? ' err' : ''); t.textContent = text; t.hidden = false;
      toastTimer = setTimeout(function () { t.hidden = true; }, bad ? 6000 : 3500);
    }
    function times() {
      [].forEach.call(root.querySelectorAll('time.sl-time[data-ts]'), function (t) {
        var d = new Date(+t.getAttribute('data-ts') * 1000); if (isNaN(d)) return;
        t.textContent = d.toLocaleString(undefined, { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
      });
    }
    function sources() { return S.src.slice(); }   // ('public', 'lockouts' and share link ids: the choices under Sources)
    function sourceList() { var d = info(); return [{ id: 'public', name: 'Public', n: d.pub }, { id: 'lockouts', name: 'Lockouts', n: d.lock }].concat(d.links); }
    function params(extra) {
      var q = '', add = function (k, v) { q += '&' + k + '=' + encodeURIComponent(v); };
      if (sources().length) add('src', sources().join(','));
      if (S.cc.length) add('cc', S.cc.join(','));
      if (S.q) add('q', S.q);
      if (S.view === 'media') {
        S.folders.forEach(function (f) { q += '&folders%5B%5D=' + encodeURIComponent(f); });
        if (S.what && S.what !== 'viewed') add('what', S.what);
        if (S.exact) q += '&exact=1';
        if (S.all) q += '&all=1';
      }
      return q + (extra || '');
    }
    function fragUrl() { return '?admin&visits=1&tab=' + S.view + params(); }
    function filtered() { return S.cc.length > 0 || S.src.length > 0 || S.q !== ''; }
    function load(quiet) {   // quiet: the window came back; nothing is dimmed, and what is on screen is left alone unless the answer is different
      var my = ++reqId, body = $('.vt-body'); if (!quiet) root.classList.add('loading');
      return fetch(fragUrl(), { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.text(); }).then(function (html) {
        if (my !== reqId) return;
        root.classList.remove('loading');
        if (!quiet || body.innerHTML !== html) { body.innerHTML = html; times(); }
        paintNote(); paintExport();
      }).catch(function () { if (my !== reqId) return; root.classList.remove('loading'); if (!quiet) body.innerHTML = '<p class="muted">Couldn\'t load: check your connection.</p>'; });
    }
    function paintNote() {
      var f = $('.vt-frag'), n = $('.vt-note'); if (!f || !n) return;
      var m = +f.getAttribute('data-match'), a = +f.getAttribute('data-all');
      n.innerHTML = filtered() && a > 0 ? 'Showing ' + m.toLocaleString() + ' of ' + a.toLocaleString() + ' <button type="button" class="linklike" data-vt="clear">Clear</button>' : '';
    }
    function paintExport() {
      [].forEach.call(root.querySelectorAll('[data-export]'), function (a) { a.href = '?admin&visitsexport=' + a.getAttribute('data-export') + params(); });
    }
    function paintSearch() { var x = $('.vt-sx'), i = $('.vt-search'); if (x && i) x.hidden = i.value === ''; }
    function paintPills() {
      var d = info(), cc = $('[data-pill=cc]'), sp = $('[data-pill=src]');
      var ccN = d.countries.filter(function (c) { return S.cc.indexOf(c.c) >= 0; }).reduce(function (n, c) { return n + c.n; }, 0);
      cc.querySelector('.vt-l').textContent = S.cc.length === 0 ? 'All' : (S.cc.length <= 3 ? S.cc.join(', ') : S.cc.length + ' countries');
      cc.querySelector('em').textContent = (S.cc.length ? ccN : d.total).toLocaleString(); cc.classList.toggle('on', S.cc.length > 0);
      if (sp) {
        var picked = sourceList().filter(function (s) { return S.src.indexOf(s.id) >= 0; });
        sp.querySelector('.vt-l').textContent = picked.length === 0 ? 'Sources' : (picked.length === 1 ? picked[0].name : picked.length + ' sources');
        sp.querySelector('em').textContent = (picked.length ? picked.reduce(function (n, s) { return n + s.n; }, 0) : d.total).toLocaleString(); sp.classList.toggle('on', picked.length > 0);
      }
      paintSearch();
      [].forEach.call(root.querySelectorAll('.vt-views [data-view]'), function (b) { b.classList.toggle('on', b.getAttribute('data-view') === S.view); });
      [].forEach.call(root.querySelectorAll('.vt-chip[aria-haspopup]'), function (b) { b.setAttribute('aria-expanded', S.pop === b.getAttribute('data-pill') ? 'true' : 'false'); });
    }
    // ---- the two menus (a list under its pill; a sheet at the bottom on a phone): they stay open while you tick several
    function optionRows() {
      var d = info();
      if (S.pop === 'cc') return '<div class="vt-opt' + (S.cc.length ? '' : ' on') + '" data-call="1">All countries' + (S.cc.length ? '' : '<span class="tk">&#10003;</span>') + '</div>' + d.countries.map(function (c) { var on = S.cc.indexOf(c.c) >= 0; return '<div class="vt-opt' + (on ? ' on' : '') + '" data-c="' + esc(c.c) + '"><b class="cc2">' + esc(c.c) + '</b> ' + esc(c.name) + ' <span class="muted vt-n">' + c.n + '</span>' + (on ? '<span class="tk">&#10003;</span>' : '') + '</div>'; }).join('');
      return sourceList().map(function (l) { var on = S.src.indexOf(l.id) >= 0; return '<div class="vt-opt' + (on ? ' on' : '') + '" data-l="' + esc(l.id) + '">' + esc(l.name) + ' <span class="muted vt-n">' + l.n + '</span>' + (on ? '<span class="tk">&#10003;</span>' : '') + '</div>'; }).join('');
    }
    function closePop() { S.pop = ''; var p = $('.vt-pop'); if (p) p.remove(); var bg = $('.vt-sheetbg'); if (bg) bg.remove(); paintPills(); }
    function openPop(kind) {
      closePop(); S.pop = kind;
      var wrap = $('[data-pill=' + kind + ']').parentNode, pop = document.createElement('div');
      pop.className = 'vt-pop'; pop.setAttribute('data-kind', kind);
      pop.innerHTML = '<div class="vt-pop-h">' + (kind === 'cc' ? 'Country' : 'Sources') + '</div><input type="search" class="vt-pop-find" placeholder="' + (kind === 'cc' ? 'Find a country' : 'Find a source') + '" aria-label="' + (kind === 'cc' ? 'Find a country' : 'Find a source') + '" autocomplete="off"><div class="vt-opts">' + optionRows() + '</div><button type="button" class="vt-done" data-vt="done">Done</button>';
      var bg = document.createElement('div'); bg.className = 'vt-sheetbg'; root.appendChild(bg);
      wrap.appendChild(pop); paintPills();
      if (!matchMedia('(max-width: 800px)').matches) pop.querySelector('.vt-pop-find').focus();
    }
    function repaintOptions() { var o = $('.vt-pop .vt-opts'), f = $('.vt-pop .vt-pop-find'); if (!o) return; var top = o.scrollTop; o.innerHTML = optionRows(); o.scrollTop = top; applyFind(f ? f.value : ''); }
    function applyFind(v) { v = (v || '').toLowerCase(); [].forEach.call(root.querySelectorAll('.vt-pop .vt-opt'), function (o) { o.style.display = !v || o.textContent.toLowerCase().indexOf(v) >= 0 ? '' : 'none'; }); }
    function toggle(arr, v) { var i = arr.indexOf(v); if (i >= 0) arr.splice(i, 1); else arr.push(v); }
    // ---- the Media view's own menus (folders, what): the server draws them in the fragment
    function popValues(pop) { return [].map.call(pop.querySelectorAll('input:checked:not([data-all])'), function (i) { return i.value; }); }
    function mpopClose(pop) { var m = pop.querySelector('.sl-popmenu'); if (m && !m.hidden) { m.hidden = true; pop.querySelector('.sl-popbtn').setAttribute('aria-expanded', 'false'); } }
    function mpopCloseAll(except) { [].forEach.call(root.querySelectorAll('.vt-body .sl-pop'), function (p) { if (p !== except) mpopClose(p); }); }
    function mpopApply(pop) {
      var k = pop.getAttribute('data-pop'), v = popValues(pop);
      if (k === 'what') S.what = v[0] || 'viewed'; else { S.folders = v; S.exact = false; }
      S.all = false;
      var menu = pop.querySelector('.sl-popmenu'), top = menu.scrollTop, multi = pop.getAttribute('data-multi') === '1', wasOpen = !menu.hidden;
      load().then(function () {
        if (!wasOpen || !multi) return;
        var np = root.querySelector('.vt-body [data-pop="' + k + '"]'); if (!np) return;
        var nm = np.querySelector('.sl-popmenu'); nm.hidden = false; np.querySelector('.sl-popbtn').setAttribute('aria-expanded', 'true'); nm.scrollTop = top;
      });
    }
    // ---- Trim the log
    function modal() { return document.getElementById('vt-modal'); }
    function epoch(s) { var t = new Date(s).getTime(); return isNaN(t) ? 0 : Math.floor(t / 1000); }
    function post(fields) {
      var body = new URLSearchParams(); body.set('csrf_token', root.getAttribute('data-csrf'));
      Object.keys(fields).forEach(function (k) { body.set(k, fields[k]); });
      return fetch('?admin', { method: 'POST', credentials: 'same-origin', body: body.toString(), headers: { 'X-Requested-With': 'gallery-fetch', 'Content-Type': 'application/x-www-form-urlencoded' } })
        .then(function (r) { if ((r.headers.get('content-type') || '').indexOf('json') < 0) throw new Error('session'); return r.json(); });
    }
    var trimStamp = 0;
    function trimParams(apply) {
      var m = modal(), f = { action: 'visits_trim', id: m.querySelector('#vt-trim-src').value, apply: apply ? '1' : '0' }, age = m.querySelector('#vt-trim-age'), date = m.querySelector('#vt-trim-date');
      if (age.value === 'pick') f.before = date.value ? epoch(date.value + 'T00:00') : 0; else f.days = age.value;
      return f;
    }
    function trimCheck() {
      var m = modal(), mine = ++trimStamp, out = m.querySelector('#vt-trim-out'), ok = m.querySelector('[data-vt-modal=ok]'), age = m.querySelector('#vt-trim-age'), date = m.querySelector('#vt-trim-date'), src = m.querySelector('#vt-trim-src').value;
      ['events', 'items', 'visitors'].forEach(function (k) { m.querySelector('#vt-trim-dl-' + k).href = '?admin&visitsexport=' + k + (src !== 'all' ? '&src=' + src : ''); });
      date.hidden = age.value !== 'pick'; ok.disabled = true;
      if (age.value === 'pick' && !date.value) { out.textContent = 'Pick a date: what is older than it goes.'; return; }
      out.textContent = 'Counting…';
      post(trimParams(false)).then(function (j) { if (mine !== trimStamp) return; out.textContent = j.text || ''; ok.disabled = !j.ok || !!j.nothing; })
        .catch(function (e) { if (mine === trimStamp) out.textContent = e && e.message === 'session' ? 'Your session ended: reload the page and sign in again.' : 'Couldn\'t count: check your connection.'; });
    }
    function trimOpen() { var m = modal(); m.hidden = false; trimCheck(); m.querySelector('[data-vt-modal=cancel]').focus(); }
    function trimClose() { var m = modal(); if (m) m.hidden = true; trimStamp++; }
    // The numbers behind the pills and the summary line are asked for again (a small answer, not the whole page), then the list, quietly. Used after a trim, and when
    // the window comes back; the lockouts on screen then count as seen.
    function refreshPanel() {
      return fetch('?admin&visits=1&tab=info', { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (j) {
        if (!j || !j.ok) return;
        root.setAttribute('data-info', JSON.stringify(j.info));
        var sum = $('.vt-sum'); if (sum) sum.innerHTML = j.sum;
        var opt = $('#vt-trim-src'); if (opt) { var keep = opt.value; opt.innerHTML = j.trim; if ([].some.call(opt.options, function (o) { return o.value === keep; })) opt.value = keep; }
        paintPills();
        return load(true).then(function () { if (j.info.unseen > 0 && window.__vtSeen) window.__vtSeen(); });
      });
    }
    if (window.__live) window.__live.register('visits', { tab: 'visits', refresh: refreshPanel, busy: function () {
      var mn = $('.vt-menu'), md = modal();   // (a list under a pill, the export menu, the Trim dialog or one of the Media view's menus is open)
      return S.pop !== '' || (!!mn && !mn.hidden) || (!!md && !md.hidden) || !!root.querySelector('.vt-body .sl-popmenu:not([hidden])');
    } });
    // ---- clicks
    function setView(v) { S.view = v; S.all = false; paintPills(); load(); }
    function resetAll() { S.cc = []; S.src = []; S.q = ''; S.all = false; var s = $('.vt-search'); if (s) s.value = ''; closePop(); paintPills(); load(); }
    root.addEventListener('click', function (e) {
      var t = e.target, el;
      if ((el = t.closest('[data-pill]'))) {
        var p = el.getAttribute('data-pill'); closeMenu();
        if (S.pop === p) closePop(); else openPop(p);
        return;
      }
      if (t.closest('[data-call]')) { S.cc = []; closePop(); paintPills(); load(); return; }
      if ((el = t.closest('.vt-opt[data-c]'))) { toggle(S.cc, el.getAttribute('data-c')); repaintOptions(); paintPills(); load(); return; }
      if ((el = t.closest('.vt-opt[data-l]'))) { toggle(S.src, el.getAttribute('data-l')); repaintOptions(); paintPills(); load(); return; }
      if (t.closest('[data-vt=done]') || t.classList.contains('vt-sheetbg')) { closePop(); return; }
      if ((el = t.closest('.vt-views [data-view]'))) { closeMenu(); closePop(); setView(el.getAttribute('data-view')); return; }
      if ((el = t.closest('.vt-cc'))) { var c = el.getAttribute('data-cc'); S.cc = S.cc.length === 1 && S.cc[0] === c ? [] : [c]; closePop(); paintPills(); load(); return; }
      if ((el = t.closest('.vt-src'))) { var id = el.getAttribute('data-src'); if (S.src.length === 1 && S.src[0] === id) S.src = []; else S.src = [id]; closePop(); paintPills(); load(); return; }
      if ((el = t.closest('.vt-viewed'))) { var ip = el.getAttribute('data-ip'); S.q = ip; $('.vt-search').value = ip; S.view = 'media'; S.folders = []; S.exact = false; S.what = 'viewed'; S.all = false; paintPills(); load(); return; }
      if ((el = t.closest('.vt-ip'))) { var ip2 = el.getAttribute('data-ip'); S.q = ip2; $('.vt-search').value = ip2; S.all = false; paintPills(); load(); return; }
      if ((el = t.closest('[data-media]'))) { S.view = 'media'; S.folders = [el.getAttribute('data-folder')]; S.exact = true; S.what = el.getAttribute('data-media'); S.all = false; paintPills(); load(); return; }
      if (t.closest('[data-more]')) { S.all = true; load(); return; }
      if (t.closest('[data-vt=clear]')) { resetAll(); return; }
      if (t.closest('[data-vt=clearq]')) { clearTimeout(searchTimer); var qi = $('.vt-search'); qi.value = ''; S.q = ''; S.all = false; paintPills(); load(); qi.focus(); return; }
      if (t.closest('[data-vt=menu]')) { closePop(); var mn = $('.vt-menu'); mn.hidden = !mn.hidden; $('[data-vt=menu]').setAttribute('aria-expanded', mn.hidden ? 'false' : 'true'); paintExport(); return; }
      if (t.closest('[data-vt=trim]')) { closeMenu(); trimOpen(); return; }
      if (t.closest('.vt-menu a')) { setTimeout(closeMenu, 0); return; }   // (the download starts from the link itself)
      var mb = t.closest('.vt-body .sl-popbtn'), mpop = mb ? mb.closest('.sl-pop') : null;
      if (mpop) { mpopCloseAll(mpop); var mm = mpop.querySelector('.sl-popmenu'); if (mm.hidden) { mm.hidden = false; mb.setAttribute('aria-expanded', 'true'); } else mpopClose(mpop); return; }
      if (!t.closest('.vt-body .sl-popmenu')) mpopCloseAll(null);
    });
    function closeMenu() { var mn = $('.vt-menu'); if (mn && !mn.hidden) { mn.hidden = true; var b = $('[data-vt=menu]'); if (b) b.setAttribute('aria-expanded', 'false'); } }
    document.addEventListener('click', function (e) {   // a click outside the panel's menus closes them
      if (!root.contains(e.target)) return;
      if (S.pop && !e.target.closest('.vt-pw') && !e.target.closest('.vt-pop')) closePop();
      if (!e.target.closest('.vt-menuwrap')) closeMenu();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') return;
      var m = modal(); if (m && !m.hidden) { e.preventDefault(); trimClose(); return; }
      if (S.pop) closePop(); closeMenu(); mpopCloseAll(null);
    });
    root.addEventListener('change', function (e) {
      var inp = e.target, pop = inp.closest ? inp.closest('.vt-body .sl-pop') : null;
      if (pop && inp.type !== 'hidden') {
        if (pop.getAttribute('data-multi') === '1') {
          var all = pop.querySelector('input[data-all]');
          if (inp.hasAttribute('data-all')) { [].forEach.call(pop.querySelectorAll('input:not([data-all])'), function (i) { i.checked = false; }); inp.checked = true; }
          else if (all) all.checked = popValues(pop).length === 0;
          clearTimeout(pop._t); pop._t = setTimeout(function () { pop._t = null; mpopApply(pop); }, 220);
        } else { mpopClose(pop); mpopApply(pop); }
        return;
      }
      if (inp.id === 'vt-trim-src' || inp.id === 'vt-trim-age' || inp.id === 'vt-trim-date') trimCheck();
    });
    root.addEventListener('input', function (e) {
      if (e.target.classList.contains('vt-search')) { paintSearch(); clearTimeout(searchTimer); var v = e.target.value; searchTimer = setTimeout(function () { S.q = v.trim(); S.all = false; paintPills(); load(); }, 260); }
      else if (e.target.classList.contains('vt-pop-find')) applyFind(e.target.value);
    });
    var m0 = modal();
    if (m0) {
      m0.addEventListener('click', function (e) {
        var b = e.target.closest('[data-vt-modal]'), w = b ? b.getAttribute('data-vt-modal') : '';
        if (e.target === m0 || w === 'cancel') { trimClose(); return; }
        if (w === 'ok') {
          var ok = b; ok.disabled = true;
          post(trimParams(true)).then(function (j) { trimClose(); if (!j.ok) { say(j.text || 'Not trimmed', true); return; } return refreshPanel().then(function () { say(j.text || 'Trimmed.'); }); })
            .catch(function (er) { trimClose(); say(er && er.message === 'session' ? 'Your session ended: reload the page and sign in again.' : 'Couldn\'t trim: check your connection.', true); });
        }
      });
    }
    // the Shares tab's "See visitors" asks for one link
    // The Visitors tab was opened: the lockouts so far count as seen, so the sidebar's red number goes back to grey (in this page's sidebar and in the desktop shell's own).
    window.__vtSeen = function () {
      post({ action: 'visits_seen' }).then(function () {
        [].forEach.call(document.querySelectorAll('.side-link[data-tab=visits] .count.bad, .pb-w[data-pb-tab=visits] .count.bad'), function (c) { c.classList.remove('bad'); c.title = c.title.replace(/, and .* you have not seen$/, ''); });
        if (window.parent !== window) { try { window.parent.postMessage({ galleryShell: 1, tick: 1 }, location.origin); } catch (e) {} }
      }).catch(function () {});
    };
    window.__vtShow = function (id) { S.cc = []; S.src = id ? [id] : []; S.q = ''; S.view = 'visitors'; S.all = false; var s = $('.vt-search'); if (s) s.value = ''; closePop(); paintPills(); load(); };
    paintPills(); paintNote(); paintExport(); times();
  })();
  function admConfirmSaveAs(form) {
    const newNameField = form.querySelector('.adm-newname');
    if (newNameField && newNameField.value.trim() !== '') {
      return confirm('Save as "' + newNameField.value.trim() + '"? If that file already exists, it will be overwritten.');
    }
    return true;
  }
