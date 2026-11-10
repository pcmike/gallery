// The owner's gallery page, left open while photos arrive (a sync, an upload): when the window comes back, a quiet pill at the bottom says where and what changed, with a link to each
// folder and a Refresh button. Nothing is swapped on its own. The link opens that folder with ?since=, and the page then marks what arrived since and scrolls to it.
(function () {
  var box = document.getElementById('live-folder'); if (!box) return;   // (only the signed-in owner's page has this)
  var folder = box.getAttribute('data-folder') || '', base = null, last = 0, busy = false, bar = null, marked = [];
  var framed = /[?&]inframe=1/.test(location.search);
  function url(since) { return '?admin&changed=1' + (folder ? '&folder=' + encodeURIComponent(folder) : '') + (since ? '&since=' + since : ''); }
  function ask(since) { return fetch(url(since), { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.ok ? r.json() : null; }).then(function (j) { return j && j.ok ? j : null; }); }
  // What changed among the photos, per folder: parts of a sentence, with the folder's name and path for the link.
  function describe(before, now) {
    var keys = {}, out = [], items = function (n) { return n + (n === 1 ? ' item' : ' items'); };
    Object.keys(before || {}).concat(Object.keys(now || {})).forEach(function (k) { keys[k] = 1; });
    Object.keys(keys).forEach(function (k) {
      var a = (before || {})[k], b = (now || {})[k], name = (b || a)[2] || 'the front page';
      if (!a) out.push({ pre: 'the new folder ', name: name, path: k, post: ' has ' + items(b[0]) });
      else if (!b) out.push({ pre: 'the folder ', name: name, path: null, post: ' has no items now' });
      else if (b[0] > a[0]) out.push({ pre: items(b[0] - a[0]) + ' added to ', name: name, path: k, post: '' });
      else if (b[0] < a[0]) out.push({ pre: items(a[0] - b[0]) + ' removed from ', name: name, path: k, post: '' });
      else if (b[1] !== a[1]) out.push({ pre: 'items in ', name: name, path: k, post: ' were renamed or replaced' });
    });
    return out;
  }
  function href(path, at) { return '?' + (path ? 'folder=' + encodeURIComponent(path) + '&' : '') + 'since=' + Math.max(0, (at || 0) - 2) + (framed ? '&inframe=1' : ''); }
  function sentence(parts, at, into) {
    if (!parts.length) { into.appendChild(document.createTextNode('Photos in this folder changed')); }
    parts.slice(0, 3).forEach(function (p, i) {
      if (i) into.appendChild(document.createTextNode('; '));
      var pre = i === 0 ? p.pre.charAt(0).toUpperCase() + p.pre.slice(1) : p.pre;
      into.appendChild(document.createTextNode(pre));
      if (p.path === null) into.appendChild(document.createTextNode(p.name));
      else { var a = document.createElement('a'); a.href = href(p.path, at); a.textContent = p.name; into.appendChild(a); }
      into.appendChild(document.createTextNode(p.post));
    });
    if (parts.length > 3) into.appendChild(document.createTextNode('; and ' + (parts.length - 3) + ' more places'));
    into.appendChild(document.createTextNode('. '));
  }
  function pill(build, refresh) {
    if (bar) bar.remove();
    bar = document.createElement('div'); bar.className = 'live-note live-note-fixed'; bar.setAttribute('role', 'status');
    var t = document.createElement('span'); build(t); bar.appendChild(t);
    if (refresh) { var go = document.createElement('button'); go.type = 'button'; go.textContent = 'Refresh'; go.addEventListener('click', function () { location.reload(); }); bar.appendChild(go); }
    var x = document.createElement('button'); x.type = 'button'; x.className = 'live-x'; x.setAttribute('aria-label', 'Dismiss'); x.textContent = '×';
    x.addEventListener('click', function () { bar.remove(); bar = null; if (refresh) { base = null; last = 0; } else unmark(); });
    bar.appendChild(x); document.body.appendChild(bar);
  }
  function unmark() { marked.forEach(function (c) { c.classList.remove('live-new'); }); marked = []; }
  function check() {
    if (document.hidden || busy || Date.now() - last < (window.__liveMin === undefined ? 15000 : window.__liveMin)) return;
    busy = true; last = Date.now();
    ask().then(function (j) {
      if (!j) return;
      if (base === null) { base = j; return; }
      if (j.media !== base.media) { var parts = describe(base.folders, j.folders), at = base.at; pill(function (t) { sentence(parts, at, t); }, true); }
    }).catch(function () {}).then(function () { busy = false; });
  }
  // Arrived by a link from the pill (or the admin's bar): what came in since is marked, and the page scrolls to the first.
  var m = /[?&]since=(\d+)/.exec(location.search);
  if (m) ask(m[1]).then(function (j) {
    if (!j || !j.fresh || !j.fresh.length) return;
    var want = {}; j.fresh.forEach(function (f) { want[f] = 1; });
    [].forEach.call(document.querySelectorAll('.card[data-file]'), function (c) { if (want[c.getAttribute('data-file')]) { c.classList.add('live-new'); marked.push(c); } });
    if (!marked.length) return;
    marked[0].scrollIntoView({ block: 'center', behavior: 'smooth' });
    pill(function (t) { t.textContent = (marked.length === 1 ? '1 new item is' : marked.length + ' new items are') + ' marked. '; }, false);
  }).catch(function () {});
  check();   // (the first answer is the baseline)
  document.addEventListener('visibilitychange', function () { if (!document.hidden) check(); });
  window.addEventListener('focus', check);
  window.addEventListener('pageshow', function (e) { if (e.persisted) check(); });
})();
