// The owner's gallery page, left open while photos arrive (a sync, an upload): when the window comes back, a quiet bar says the photos here changed, with Refresh. Nothing is swapped on its own.
(function () {
  var box = document.getElementById('live-folder'); if (!box) return;   // (only the signed-in owner's page has this)
  var folder = box.getAttribute('data-folder') || '', base = null, last = 0, busy = false, bar = null;
  function ask() { return fetch('?admin&changed=1' + (folder ? '&folder=' + encodeURIComponent(folder) : ''), { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.ok ? r.json() : null; }).then(function (j) { return j && j.ok ? j : null; }); }
  function show() {
    if (bar) return;
    bar = document.createElement('div'); bar.className = 'live-note live-note-fixed'; bar.setAttribute('role', 'status');
    var t = document.createElement('span'); t.textContent = 'Photos in this folder changed. '; bar.appendChild(t);
    var go = document.createElement('button'); go.type = 'button'; go.textContent = 'Refresh'; go.addEventListener('click', function () { location.reload(); }); bar.appendChild(go);
    var x = document.createElement('button'); x.type = 'button'; x.className = 'live-x'; x.setAttribute('aria-label', 'Dismiss'); x.textContent = '×'; x.addEventListener('click', function () { bar.remove(); bar = null; base = null; last = 0; }); bar.appendChild(x);
    document.body.appendChild(bar);
  }
  function check() {
    if (document.hidden || busy || Date.now() - last < (window.__liveMin === undefined ? 15000 : window.__liveMin)) return;
    busy = true; last = Date.now();
    ask().then(function (j) { if (!j) return; if (base === null) base = j.media; else if (j.media !== base) show(); }).catch(function () {}).then(function () { busy = false; });
  }
  check();   // (the first answer is the baseline)
  document.addEventListener('visibilitychange', function () { if (!document.hidden) check(); });
  window.addEventListener('focus', check);
  window.addEventListener('pageshow', function (e) { if (e.persisted) check(); });
})();
