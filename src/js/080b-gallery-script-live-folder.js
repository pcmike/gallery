// The owner's gallery page, left open while things happen on disk (a sync, an upload, a rename by hand). Three things, all drawn from the log of disk activity (the Changes tab):
//  - when the window comes back, the log is asked what it took in since this page was drawn; if something changed in this folder or below it, a quiet pill at the bottom says where and what,
//    each folder a link (Refresh only where the view in front of you would change). Nothing is swapped on its own.
//  - a link with ?since= (from the pill, the card text or the Changes tab) opens a folder with what arrived since marked: a ring and a small New, scrolled to the first. The marks stay until the
//    page is left or reloaded; the address is cleaned afterwards so a reload does not mark again.
//  - the owner's amber note on a folder's card ("(+1 photo ›)") is brought up to date, without a reload.
(function () {
  var box = document.getElementById('live-folder'); if (!box) return;   // (only the signed-in owner's page has this)
  var folder = box.getAttribute('data-folder') || '', last = +box.getAttribute('data-last') || 0, noun = box.getAttribute('data-noun') || 'photo';
  var framed = /[?&]inframe=1/.test(location.search), tickAt = 0, busy = false, bar = null, marked = 0;
  function url(extra) { return '?admin&changed=1' + (folder ? '&folder=' + encodeURIComponent(folder) : '') + extra; }
  function ask(extra) { return fetch(url(extra), { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.ok ? r.json() : null; }).then(function (j) { return j && j.ok ? j : null; }); }
  function parentOf(p) { var i = p.lastIndexOf('/'); return i < 0 ? '' : p.slice(0, i); }
  function open(path, since) { var q = []; if (path) q.push('folder=' + encodeURIComponent(path)); if (since) q.push('since=' + Math.max(0, since - 2)); if (framed) q.push('inframe=1'); return '?' + q.join('&'); }
  function many(n, one, more) { return n + ' ' + (n === 1 ? one : more); }
  // Entries gathered per folder: counts of media added (add), removed (rem), renamed (ren), folders that came (nw) and went (gone), and the earliest arrival.
  function gather(entries) {
    var by = {}, order = [];
    (entries || []).forEach(function (e) {
      var c = by[e.folder]; if (!c) { c = by[e.folder] = { path: e.folder, label: e.label, add: 0, rem: 0, ren: 0, nw: 0, gone: 0, t0: e.t0 }; order.push(e.folder); }
      if (e.kind === 'add') c.add += e.n; else if (e.kind === 'rem') c.rem += e.n; else if (e.kind === 'ren') c.ren += e.n; else if (e.kind === 'new') c.nw++; else if (e.kind === 'gone') c.gone++;
      c.t0 = Math.min(c.t0, e.t0);
    });
    return order.map(function (k) { return by[k]; });
  }
  function says(c, here) {
    var p = [];
    if (c.add) p.push(c.add + ' new media');
    if (c.rem) p.push(c.rem + ' removed');
    if (c.ren) p.push(here && !c.add && !c.rem ? many(c.ren, 'item', 'items') + ' renamed' : c.ren + ' renamed');
    if (c.nw) p.push(many(c.nw, 'new folder', 'new folders'));
    if (c.gone) p.push(many(c.gone, 'folder', 'folders') + ' gone');
    return p.join(', ');
  }
  // refresh: null (no button), or the address Refresh goes to (the same page, with what is new marked)
  function pill(build, refresh, onClose) {
    if (bar) bar.remove();
    bar = document.createElement('div'); bar.className = 'live-note live-note-fixed'; bar.setAttribute('role', 'status');
    var t = document.createElement('span'); build(t); bar.appendChild(t);
    if (refresh) { var go = document.createElement('button'); go.type = 'button'; go.textContent = 'Refresh'; go.addEventListener('click', function () { location.href = refresh; }); bar.appendChild(go); }
    var x = document.createElement('button'); x.type = 'button'; x.className = 'live-x'; x.setAttribute('aria-label', 'Dismiss'); x.textContent = '×';
    x.addEventListener('click', function () { bar.remove(); bar = null; if (onClose) onClose(); });
    bar.appendChild(x); document.body.appendChild(bar);
  }
  // What is in front of the owner changes only for a change in this folder itself, or a folder coming or going at this level.
  // Returns the address Refresh should go to (this page with what is new marked, or just this page when only things went), or null when nothing in front of the owner changes.
  function viewChanges(entries) {
    var mine = (entries || []).filter(function (e) { return e.folder === folder || ((e.kind === 'new' || e.kind === 'gone') && parentOf(e.folder) === folder); });
    if (!mine.length) return null;
    var fresh = mine.filter(function (e) { return e.kind === 'add' || e.kind === 'ren' || e.kind === 'new'; });
    return open(folder, fresh.length ? Math.min.apply(null, fresh.map(function (e) { return e.t0; })) : 0);
  }
  function sentence(groups, into) {
    groups.forEach(function (c, i) {
      if (i) into.appendChild(document.createTextNode('; '));
      var here = c.path === folder;
      if (here) { into.appendChild(document.createTextNode(says(c, true) + ' here')); return; }
      var name = c.label || c.path || 'the front page';
      if (c.gone && !c.add && !c.rem && !c.ren && !c.nw) { into.appendChild(document.createTextNode(name + ': ' + says(c))); return; }
      var a = document.createElement('a'); a.href = open(c.path, c.t0); a.textContent = name; into.appendChild(a); into.appendChild(document.createTextNode(': ' + says(c)));
    });
    into.appendChild(document.createTextNode('.'));
  }
  // The amber text on a folder's card, the way the page draws it: what is new in it and below it (a rename changes no count, so it adds nothing here).
  function cardText(c) {
    var p = [], n = function (v, o, m) { return v + ' ' + (v === 1 ? o : m); };
    if (c.add > 0) p.push('+' + n(c.add, noun, noun + 's'));
    if (c.rem > 0) p.push('−' + c.rem);
    if (c.nw > 0) p.push('+' + n(c.nw, 'folder', 'folders'));
    if (c.gone > 0) p.push('−' + n(c.gone, 'folder', 'folders'));
    return !p.length ? '' : p.length > 2 ? 'changed' : p.join(', ');
  }
  function cardPath(card) { var a = card.querySelector('a[href*="folder="]'); if (!a) return null; var m = /[?&]folder=([^&#]*)/.exec(a.getAttribute('href')); return m ? decodeURIComponent(m[1]) : null; }
  function updateCards(entries) {
    var sum = {};   // path => counts from this page's lifetime, for the folder and every folder above it
    (entries || []).forEach(function (e) {
      for (var p = e.folder; p; p = parentOf(p)) {
        var c = sum[p] || (sum[p] = { add: 0, rem: 0, nw: 0, gone: 0, t0: e.t0 });
        var own = p === e.folder;   // (a folder that came adds its media to itself and, as one folder, to every folder above; one that went takes its media and itself from those above)
        if (e.kind === 'add') c.add += e.n; else if (e.kind === 'rem') c.rem += e.n;
        else if (e.kind === 'new') { c.add += e.n; if (!own) c.nw++; } else if (e.kind === 'gone' && !own) { c.rem += e.n; c.gone++; }
        c.t0 = Math.min(c.t0, e.t0);
      }
    });
    [].forEach.call(document.querySelectorAll('.folder-cover'), function (card) {
      var p = cardPath(card), c = p !== null && sum[p]; if (!c) return;
      var line = card.querySelector('.folder-cover-count'); if (!line) return;
      var link = line.querySelector('.fc-chg'), old = { add: 0, rem: 0, nw: 0, gone: 0 };
      if (link) { try { var d = JSON.parse(link.getAttribute('data-chg-base') || link.getAttribute('data-chg') || '{}'); old = { add: d.add || 0, rem: d.rem || 0, nw: d['new'] || 0, gone: d.gone || 0 }; if (!link.hasAttribute('data-chg-base')) link.setAttribute('data-chg-base', link.getAttribute('data-chg') || '{}'); } catch (e) {} }   // (what the page was drawn with: each check adds all that came since to it, not to the last total)
      var now = { add: old.add + c.add, rem: old.rem + c.rem, nw: old.nw + c.nw, gone: old.gone + c.gone };
      var text = cardText(now); if (!text) return;
      if (!link) {
        if (!line.classList.contains('has-priv-count')) {   // a plain count line sits inside the card's link; the amber note is a link of its own, so the line steps out of it first
          var outer = line.closest('a.folder-cover-link'); if (!outer) return;
          var main = document.createElement('a'); main.className = 'fc-main'; main.href = outer.getAttribute('href'); main.textContent = line.textContent.trim();
          line.textContent = ''; line.appendChild(main); line.classList.add('has-priv-count'); outer.parentNode.insertBefore(line, outer.nextSibling);
        }
        link = document.createElement('a'); link.className = 'fc-chg'; link.title = 'Open the folder with what is new marked';
        line.appendChild(document.createTextNode(' ')); line.appendChild(link);
      }
      var since = /[?&]since=(\d+)/.exec(link.getAttribute('href') || ''), t0 = since ? Math.min(+since[1] + 2, c.t0) : c.t0;
      link.setAttribute('href', open(p, t0));
      link.setAttribute('data-chg', JSON.stringify({ add: now.add, rem: now.rem, 'new': now.nw, gone: now.gone }));
      link.textContent = '(' + text; var chev = document.createElementNS('http://www.w3.org/2000/svg', 'svg'); chev.setAttribute('class', 'ico'); chev.setAttribute('aria-hidden', 'true');
      var use = document.createElementNS('http://www.w3.org/2000/svg', 'use'); use.setAttribute('href', '#i-chev-right'); chev.appendChild(use); link.appendChild(chev); link.appendChild(document.createTextNode(')'));
    });
  }
  function check() {
    if (document.hidden || busy || Date.now() - tickAt < (window.__liveMin === undefined ? 15000 : window.__liveMin)) return;
    busy = true; tickAt = Date.now();
    ask('&after=' + last).then(function (j) {
      if (!j || !j.entries || !j.entries.length) return;
      var groups = gather(j.entries), refresh = viewChanges(j.entries);
      updateCards(j.entries);
      pill(function (t) { sentence(groups, t); }, refresh, function () { last = j.last; });
    }).catch(function () {}).then(function () { busy = false; });
  }
  // Arrived by a link with ?since= : what came in since is marked, the page scrolls to the first, and the address is cleaned so a reload does not mark again.
  var m = /[?&]since=(\d+)/.exec(location.search);
  if (m) {
    ask('&since=' + m[1]).then(function (j) {
      if (!j) return;
      var wantFile = {}, wantFolder = {}; (j.fresh || []).forEach(function (f) { wantFile[f] = 1; }); (j.folders || []).forEach(function (f) { wantFolder[f] = 1; });
      var els = [];
      [].forEach.call(document.querySelectorAll('.card[data-file]'), function (c) { if (wantFile[c.getAttribute('data-file')]) els.push(c); });
      [].forEach.call(document.querySelectorAll('.folder-cover'), function (c) { var p = cardPath(c); if (p !== null && wantFolder[p]) els.push(c); });
      els.forEach(function (c) { c.classList.add('live-new'); });
      marked = els.length;
      if (marked) {
        els[0].scrollIntoView({ block: 'center', behavior: 'smooth' });
        pill(function (t) { t.textContent = (marked === 1 ? '1 new item is' : marked + ' new items are') + ' marked.'; }, false);
      }
    }).catch(function () {}).then(function () {
      try { var u = new URL(location.href); u.searchParams.delete('since'); history.replaceState(history.state, '', u.pathname + u.search + u.hash); } catch (e) {}
    });
  }
  document.addEventListener('visibilitychange', function () { if (!document.hidden) check(); });
  window.addEventListener('focus', check);
  window.addEventListener('pageshow', function (e) { if (e.persisted) check(); });
})();
