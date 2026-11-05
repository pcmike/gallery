(function () {
  var C = JSON.parse(document.getElementById('sh-cfg').textContent);
  var st = { folder: C.folder, tab: C.tab }, WHOLE = C.whole;
  var enc = encodeURIComponent;
  if (window.matchMedia && window.matchMedia('(max-width: 800px)').matches) {   // (the phone keeps the ordinary admin pages for now)
    location.replace('?admin' + (st.folder ? '&folder=' + enc(st.folder) : '') + (st.tab ? '#' + st.tab : ''));
    return;
  }
  var frame = document.getElementById('sh-frame'), pathBox = document.getElementById('sh-path'), toolsBox = document.getElementById('sh-tools');
  var alone = document.getElementById('sh-alone'), tree = document.querySelector('.sh-tree'), treeRaw = null;
  // The page in the frame has a scrollbar of its own (where scrollbars take room), which narrows it from the right: the bar above gives up the
  // same room, so its icons line up with the page's icons below them.
  var frameRO = null;
  function syncBarEdge() {
    var bar = document.querySelector('.sh-bar'); if (!bar) return;
    try { var d = frame.contentDocument, w = d ? frame.clientWidth - d.documentElement.getBoundingClientRect().width : 0; bar.style.paddingRight = (24 + Math.max(0, w)) + 'px'; } catch (e) {}
  }
  frame.addEventListener('load', function () {
    syncBarEdge();
    try { if (typeof ResizeObserver !== 'undefined') { if (frameRO) frameRO.disconnect(); frameRO = new ResizeObserver(syncBarEdge); frameRO.observe(frame.contentDocument.documentElement); } } catch (e) {}
  });
  window.addEventListener('resize', syncBarEdge);
  function esc(t) { var d = document.createElement('div'); d.textContent = t; return d.innerHTML.replace(/"/g, '&quot;'); }
  function label(f) { return f === '' ? C.title : (C.labels[f] || f.split('/').pop()); }
  function srcFor(folder, tab) {
    var f = folder ? 'folder=' + enc(folder) + '&' : '';
    return tab ? '?admin&' + f + 'inframe=1#' + tab : C.root + '?' + f + 'inframe=1';
  }
  function chrome() {
    var isWhole = !!WHOLE[st.tab], sep = '<span class="sep">/</span>';
    var h = '<a class="sh-title" href="#" data-go="">' + esc(C.title) + '</a>';
    if (isWhole) h += sep + '<span class="sh-here">' + esc(WHOLE[st.tab]) + '</span>';
    else if (st.folder) h += sep + '<a class="sh-crumb" href="#" data-go="' + esc(st.folder) + '" title="' + esc(st.folder) + '">' + esc(label(st.folder)) + '</a>';   // (this folder only: its parents are in the tree)
    pathBox.innerHTML = h;
    pathBox.style.fontSize = '';
    var fs = parseFloat(getComputedStyle(pathBox).fontSize);
    function cut() { return [].some.call(pathBox.children, function (c) { return c.scrollWidth > c.clientWidth + 1; }); }
    while (cut() && fs > 13) { fs -= 0.5; pathBox.style.fontSize = fs + 'px'; }   // (a long name makes the line a little smaller (never bigger than its usual size), to fit the room above the sidebar; past that it is cut with a dot dot dot)
    toolsBox.hidden = isWhole;
    [].forEach.call(toolsBox.querySelectorAll('.sh-tool'), function (b) {
      var t = b.getAttribute('data-tool'), on = st.tab === t;
      b.classList.toggle('on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false');
      var name = (t === 'settings' ? 'Folder settings' : 'Notes') + ' for ' + label(st.folder);
      b.title = on ? 'Close ' + name.toLowerCase() + ' and go back to the photos' : name; b.setAttribute('aria-label', b.title);
    });
    [].forEach.call(document.querySelectorAll('#sh-whole a'), function (a) { a.classList.toggle('active', a.getAttribute('data-tab') === st.tab); });
    tree.classList.toggle('quiet', isWhole);
    [].forEach.call(tree.querySelectorAll('a'), function (a) {
      var cur = a.getAttribute('data-path') === st.folder;
      a.classList.toggle('current', cur); if (cur) { a.setAttribute('aria-current', 'page'); for (var li = a.parentNode; li && li !== tree; li = li.parentNode) if (li.classList && li.classList.contains('collapsed')) li.classList.remove('collapsed'); } else a.removeAttribute('aria-current');
    });
    var curRow = tree.querySelector('a.current'); if (curRow && curRow.scrollIntoView) curRow.scrollIntoView({ block: 'nearest' });   // (a deep tree: the folder you are in is always in view)
    alone.href = C.root + (st.folder ? '?folder=' + enc(st.folder) : '');
    document.title = (st.folder ? label(st.folder) + ' · ' : '') + C.title + ' — Admin';
    if (history.replaceState) history.replaceState(null, '', '?admin&shell=1' + (st.folder ? '&folder=' + enc(st.folder) : '') + (st.tab ? '&tab=' + st.tab : ''));
  }
  function go(folder, tab) {
    st.folder = folder; st.tab = tab; chrome();
    var u = new URL(srcFor(folder, tab), location.href).href;
    try { frame.contentWindow.location.assign(u); } catch (e) { frame.src = u; }   // (a step in the history, so the browser's Back button walks back through what you opened)
  }
  // The lock beside a folder (shown while the gallery is Flat): one press makes the folder private or visible.
  function folderLock(b) {
    if (b.disabled) return;
    var on = b.classList.contains('on'); b.disabled = true;
    var body = new URLSearchParams({ csrf_token: b.getAttribute('data-csrf'), action: 'toggle_folder_restriction', hidden: on ? '' : '1' });
    fetch('?admin&folder=' + encodeURIComponent(b.getAttribute('data-lock-path')), { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Requested-With': 'gallery-fetch' } })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        b.disabled = false;
        if (!j || !j.ok) return;
        var now = !on;
        [].forEach.call(document.querySelectorAll('.sh-tree .flock'), function (x) {   // (the folders inside it follow: private because a folder above is)
          var p = x.getAttribute('data-lock-path'), me = b.getAttribute('data-lock-path');
          if (x === b) return;
          if (p.indexOf(me + '/') === 0 && !x.dataset.own) { x.classList.toggle('on', now); x.classList.toggle('inh', now); x.disabled = now; x.setAttribute('aria-pressed', now ? 'true' : 'false'); var u2 = x.querySelector('use'); if (u2) u2.setAttribute('href', now ? '#i-lock' : '#i-unlock'); }
        });
        b.classList.toggle('on', now); b.setAttribute('aria-pressed', now ? 'true' : 'false');
        b.title = now ? 'Private: press to make it visible' : 'Visible: press to make it private';
        var u = b.querySelector('use'); if (u) u.setAttribute('href', now ? '#i-lock' : '#i-unlock');
      })
      .catch(function () { b.disabled = false; });
  }
  document.addEventListener('click', function (e) {
    var lock = e.target.closest ? e.target.closest('.flock') : null;
    if (lock) { e.preventDefault(); folderLock(lock); return; }
    var t = e.target.closest ? e.target.closest('[data-go], .sh-tool, #sh-whole a, .sh-tree a, .ftoggle') : null;
    if (!t) return;
    if (t.classList.contains('ftoggle')) { var li = t.parentNode, f = li.classList.toggle('collapsed'); t.setAttribute('aria-expanded', f ? 'false' : 'true'); return; }
    e.preventDefault();
    if (t.hasAttribute('data-go')) return go(t.getAttribute('data-go'), '');
    if (t.classList.contains('sh-tool')) { var tool = t.getAttribute('data-tool'); return go(st.folder, st.tab === tool ? '' : tool); }
    if (t.closest('#sh-whole')) return go(st.folder, t.getAttribute('data-tab'));
    if (t.closest('.sh-tree')) return go(t.getAttribute('data-path'), WHOLE[st.tab] ? '' : st.tab);
  });
  var whole = document.getElementById('sh-whole'), cTimer = null;
  function refreshCounts() {   // the sidebar's numbers (Features, Shares, Settings) are asked for again after something was done in the frame
    fetch('?admin&shellcounts=1', { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.ok ? r.text() : null; }).then(function (html) {
      if (!html || html.indexOf('data-tab="features"') < 0) return;
      whole.innerHTML = html;
      [].forEach.call(whole.querySelectorAll('a'), function (a) { a.classList.toggle('active', a.getAttribute('data-tab') === st.tab); });
    }).catch(function () {});
  }
  function refreshTree() {   // the folder tree is asked for again too (a setting that shows or hides the locks, a folder made private in the frame, a new folder); what was folded stays folded
    fetch('?admin&shelltree=1', { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.ok ? r.text() : null; }).then(function (html) {
      if (!html || html.indexOf('class="sh-tree"') < 0 || html === treeRaw) return;
      treeRaw = html;
      var box = document.createElement('div'); box.innerHTML = html;
      var fresh = box.firstChild, folded = {};
      [].forEach.call(tree.querySelectorAll('li.has-children'), function (li) { var a = li.querySelector(':scope > a'); if (a) folded[a.getAttribute('data-path')] = li.classList.contains('collapsed'); });
      [].forEach.call(fresh.querySelectorAll('li.has-children'), function (li) {
        var a = li.querySelector(':scope > a'), p = a && a.getAttribute('data-path');
        if (p !== null && folded[p]) { li.classList.add('collapsed'); li.querySelector(':scope > .ftoggle').setAttribute('aria-expanded', 'false'); }
      });
      tree.parentNode.replaceChild(fresh, tree); tree = fresh;
      tree.classList.toggle('quiet', !!WHOLE[st.tab]);
      [].forEach.call(tree.querySelectorAll('a'), function (a) { var cur = a.getAttribute('data-path') === st.folder; a.classList.toggle('current', cur); if (cur) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current'); });
    }).catch(function () {});
  }
  function refreshMeta() {   // the gallery's title and the folders' display names, which the header and the path line show
    fetch('?admin&shellmeta=1', { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.ok ? r.json() : null; }).then(function (j) {
      if (!j || !j.ok || (j.title === C.title && JSON.stringify(j.labels) === JSON.stringify(C.labels))) return;
      C.title = j.title; C.labels = j.labels;
      frame.title = C.title; chrome();
    }).catch(function () {});
  }
  function refreshSoon() { clearTimeout(cTimer); cTimer = setTimeout(function () { refreshCounts(); refreshTree(); refreshMeta(); }, 500); }
  window.addEventListener('focus', refreshSoon);
  window.addEventListener('message', function (e) {
    var m = e.data;
    if (e.origin !== location.origin || e.source !== frame.contentWindow || !m || m.galleryShell !== 1) return;
    if (m.tick) { refreshSoon(); return; }
    var tab = '';
    if (m.kind === 'admin') { tab = m.tab || 'settings'; if (tab !== 'settings' && tab !== 'notes' && !WHOLE[tab]) tab = 'settings'; }
    st.folder = typeof m.folder === 'string' ? m.folder : ''; st.tab = tab; chrome(); refreshSoon();
  });
  [].forEach.call(tree.querySelectorAll('li.has-children'), function (li) { if (!li.classList.contains('root') && !li.querySelector('a.current')) { li.classList.add('collapsed'); li.querySelector(':scope > .ftoggle').setAttribute('aria-expanded', 'false'); } });
  chrome();
})();
