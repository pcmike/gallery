  // Not listed folders (the owner only): a photo in one has an address of its own that opens that one photo for anyone who has it. These
  // copy it, and make a new one (the old stops working). The server makes the address; this puts it on the clipboard.
  function ownerIsUnlisted(file) { return !!(ownerState && ownerState.unl && ownerState.unl[file]); }
  function ownerAddress(file, fresh, btn) {
    const slash = file.lastIndexOf('/'), folder = file.slice(0, slash), name = file.slice(slash + 1);
    const json = (r) => { if ((r.headers.get('content-type') || '').indexOf('json') < 0) throw new Error('signed out'); return r.json(); };
    const headers = { 'X-Requested-With': 'gallery-fetch', 'Content-Type': 'application/x-www-form-urlencoded' };
    btn.classList.add('busy');
    return fetch('?admin&csrf=1', { credentials: 'same-origin', cache: 'no-store' }).then(json).then((j) => {
      const body = new URLSearchParams(); body.set('csrf_token', j.token); body.set('action', 'photo_address'); body.set('file', name); if (fresh) body.set('fresh', '1');
      return fetch('?admin&folder=' + encodeURIComponent(folder), { method: 'POST', credentials: 'same-origin', body: body.toString(), headers });
    }).then(json).then((j) => {
      if (!j.ok) throw new Error(j.text || 'No address');
      const root = new URL(galleryRootHref, window.location.href); root.search = ''; root.hash = '';
      return copyToClipboard(root.href + j.query).then(() => { btn.classList.remove('busy'); flashCopied(btn); });
    }).catch((err) => {
      btn.classList.remove('busy');
      window.alert(err && err.message === 'signed out' ? 'Your admin session ended: sign in again.' : 'No address: ' + (err && err.message ? err.message : 'try again'));
    });
  }
  (function () {
    const modal = document.getElementById('addr-modal');
    if (!modal) return;
    let pending = null;
    const close = () => { modal.hidden = true; pending = null; };
    const ask = (file, btn) => { pending = { file, btn }; modal.hidden = false; document.getElementById('addr-cancel').focus(); };
    document.getElementById('addr-cancel').addEventListener('click', close);
    modal.addEventListener('click', (e) => { if (e.target === modal) close(); });
    modal.addEventListener('keydown', (e) => { if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(); } });
    document.getElementById('addr-ok').addEventListener('click', () => { const p = pending; close(); if (p) ownerAddress(p.file, true, p.btn); });
    // The two buttons on a tile (the card itself opens the viewer, so they keep the press to themselves), and the viewer's.
    document.addEventListener('click', (e) => {
      const b = e.target.closest('.addr-toggle');
      if (!b) return;
      e.preventDefault(); e.stopPropagation();
      if (b.classList.contains('addr-new')) ask(b.getAttribute('data-file'), b); else ownerAddress(b.getAttribute('data-file'), false, b);
    }, true);
    const viewerNew = document.getElementById('lightbox-newaddr');
    if (viewerNew) viewerNew.addEventListener('click', () => ask(files[current], viewerNew));
  })();
