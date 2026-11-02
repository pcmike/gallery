      function esc(t) { return String(t).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
      function size(n) { return n >= 1048576 ? (n / 1048576).toFixed(n >= 10485760 ? 0 : 1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB'; }
      function num(n) { return Number(n).toLocaleString(); }
      function post(fields) {
        var b = new URLSearchParams(); b.set('csrf_token', csrf); Object.keys(fields).forEach(function (k) { b.set(k, fields[k]); });
        return fetch(location.pathname + location.search, { method: 'POST', credentials: 'same-origin', body: b.toString(), headers: { 'X-Requested-With': 'gallery-fetch', 'Content-Type': 'application/x-www-form-urlencoded' } })
          .then(function (r) { if ((r.headers.get('content-type') || '').indexOf('json') < 0) throw new Error('session'); return r.json(); });
      }
      function load() { return post({ action: 'files_list' }).then(function (j) { if (!j.ok) throw new Error(j.text || 'Could not list'); sum = j; return j; }); }
      function table(j) {
        var groups = [['beside index.php', 'Beside index.php'], ['in each folder', 'In your folders (' + j.folders + ' folder' + (j.folders === 1 ? '' : 's') + ', mapped ones included)'], ['in each .gallery-cache', 'In the thumbnail folders']];
        var h = '<table><thead><tr><th>What</th><th>Files</th><th>Size</th><th>If deleted</th></tr></thead><tbody>';
        groups.forEach(function (g) {
          var rows = j.rows.filter(function (r) { return r.where === g[0]; }); if (!rows.length) return;
          h += '<tr><td class="fm-sec" colspan="4">' + esc(g[1]) + '</td></tr>';
          rows.forEach(function (r) { h += '<tr><td>' + esc(r.label) + '<span class="fm-sub">' + esc(r.what) + '</span></td><td>' + num(r.files) + '</td><td>' + size(r.bytes) + '</td><td>' + esc(r.ifgone) + '</td></tr>'; });
        });
        h += '</tbody></table><p class="muted" style="margin:8px 0 0">' + num(j.files) + ' file' + (j.files === 1 ? '' : 's') + (j.thumbs ? ' and ' + num(j.thumbs) + ' thumbnail' + (j.thumbs === 1 ? '' : 's') + ' (' + size(j.thumbBytes) + ')' : '') + '. Never touched: your photos, videos, .md files and index.php.</p>';
        if (j.unknown && j.unknown.length) h += '<p class="muted" style="margin:6px 0 0">' + num(j.unknown.length) + ' other file' + (j.unknown.length === 1 ? ' starts' : 's start') + ' with <code>.gallery-</code> but ' + (j.unknown.length === 1 ? 'is not one' : 'are not ones') + ' the gallery makes, so ' + (j.unknown.length === 1 ? 'it is' : 'they are') + ' left alone: ' + esc(j.unknown.slice(0, 5).join(', ')) + '.</p>';
        return h;
      }
      function overlay(html) {
        var o = document.createElement('div'); o.className = 'fm-ov'; o.setAttribute('role', 'dialog'); o.setAttribute('aria-modal', 'true');
        o.innerHTML = '<div class="fm-card">' + html + '</div>'; document.body.appendChild(o);
        function key(e) { if (e.key === 'Escape') { e.preventDefault(); close(); } }
        function close() { document.removeEventListener('keydown', key, true); if (o.parentNode) o.parentNode.removeChild(o); }
        document.addEventListener('keydown', key, true);
        o._close = close; return o;
      }
      // ---- the ZIP
      function zipDialog(after, cancelled) {
        load().then(function (j) {
          var o = overlay('<h3>Download a copy</h3>'
            + '<p>One ZIP of everything the gallery made, laid out like the files are on disk, from the folder index.php lives in. A README inside says what each file is and how to put them back: unzip it over the folder.</p>'
            + '<pre>README.txt\n' + esc(j.paths.slice(0, 8).join('\n')) + (j.paths.length > 8 ? '\n…' : '') + '</pre>'
            + (j.thumbs ? '<label class="fm-tick"><input type="checkbox" data-fm-thumbs> <span>Include the thumbnails <span class="muted">(' + size(j.thumbBytes) + ', can be made again)</span></span></label>' : '')
            + '<div class="fm-warn">It holds password hashes, the install secret, link addresses and visitor IP addresses. Keep it private. Your photos, videos and .md files are not in it.</div>'
            + '<input type="password" autocomplete="current-password" placeholder="Admin password" aria-label="Admin password" data-fm-pw><div class="fm-err" role="alert" data-fm-err></div>'
            + '<div class="fm-acts"><button type="button" class="secondary" data-fm-x="cancel">Cancel</button><button type="button" class="fm-primary" data-fm-x="go">Download</button></div>');
          var pw = o.querySelector('[data-fm-pw]'), err = o.querySelector('[data-fm-err]'); pw.focus();
          function go() {
            if (!pw.value) { err.textContent = 'Type your admin password.'; return; }
            var th = o.querySelector('[data-fm-thumbs]');
            post({ action: 'files_zip_prepare', password: pw.value, thumbs: th && th.checked ? '1' : '0' }).then(function (r) {
              if (!r.ok) { err.textContent = r.text || 'Not allowed.'; return; }
              copied = true; o._close(); location.href = r.url; if (after) after();
            }).catch(function () { err.textContent = 'Could not reach the gallery: check your connection.'; });
          }
          o.addEventListener('click', function (e) { var b = e.target.closest ? e.target.closest('[data-fm-x]') : null; if (!b) return; if (b.getAttribute('data-fm-x') === 'cancel') { o._close(); if (cancelled) cancelled(); } else go(); });
          pw.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); go(); } });
        }).catch(function (e) { alert(e.message === 'session' ? 'Your session expired: reload the page and sign in again.' : e.message); });
      }
      // ---- the delete: three steps, each refused again by the server unless it is right
      function deleteFlow() {
        copied = false;
        load().then(function (j) {
          step1(j);
        }).catch(function (e) { alert(e.message === 'session' ? 'Your session expired: reload the page and sign in again.' : e.message); });
      }
      function step1(j) {
        var o = overlay('<div class="fm-step">Step 1 of 3</div><h3>Keep a copy first</h3>'
          + '<p>This removes every file the gallery made: settings, share links and their logs, captions, locations, private marks, view counts and thumbnails. Your photos, videos and .md files and index.php are never touched. A ZIP of it lets you put it all back by unzipping it over this folder.</p>'
          + '<div><button type="button" class="fm-primary" data-fm-x="zip" style="min-height:42px;padding:0 16px">Download ZIP&hellip;</button> <span data-fm-got style="font-weight:600;color:#7bd88f" hidden>Copy downloaded</span></div>'
          + '<label class="fm-tick"><input type="checkbox" data-fm-nocopy> <span>I don\'t want a copy</span></label>'
          + '<div class="fm-acts"><button type="button" class="secondary" data-fm-x="cancel">Cancel</button><button type="button" class="fm-primary" data-fm-x="next" disabled>Continue</button></div>');
        var next = o.querySelector('[data-fm-x=next]'), nocopy = o.querySelector('[data-fm-nocopy]'), got = o.querySelector('[data-fm-got]');
        function sync() { got.hidden = !copied; next.disabled = !(copied || nocopy.checked); }
        nocopy.addEventListener('change', sync); sync();
        o.addEventListener('click', function (e) {
          var b = e.target.closest ? e.target.closest('[data-fm-x]') : null; if (!b) return; var w = b.getAttribute('data-fm-x');
          if (w === 'cancel') o._close();
          else if (w === 'zip') { var shown = o; shown.style.display = 'none'; zipDialog(function () { shown.style.display = ''; sync(); }, function () { shown.style.display = ''; }); }
          else if (w === 'next' && !next.disabled) { var nc = nocopy.checked && !copied; o._close(); step2(j, nc); }
        });
      }
      function step2(j, nocopy) {
        var o = overlay('<div class="fm-step">Step 2 of 3</div><h3>Confirm it\'s you</h3>'
          + '<input type="password" autocomplete="current-password" placeholder="Admin password" aria-label="Admin password" data-fm-pw>'
          + '<label><span style="display:block;font-size:0.8rem;font-weight:600;margin-bottom:6px">Type DELETE to go on</span><input type="text" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="DELETE" data-fm-typed></label>'
          + '<div class="fm-acts"><button type="button" class="secondary" data-fm-x="cancel">Cancel</button><button type="button" class="fm-primary" data-fm-x="next" disabled>Continue</button></div>');
        var pw = o.querySelector('[data-fm-pw]'), typed = o.querySelector('[data-fm-typed]'), next = o.querySelector('[data-fm-x=next]'); pw.focus();
        function sync() { next.disabled = !(pw.value.length > 0 && typed.value === 'DELETE'); }
        pw.addEventListener('input', sync); typed.addEventListener('input', sync);
        o.addEventListener('click', function (e) {
          var b = e.target.closest ? e.target.closest('[data-fm-x]') : null; if (!b) return;
          if (b.getAttribute('data-fm-x') === 'cancel') o._close(); else if (!next.disabled) { var p = pw.value; o._close(); step3(j, nocopy, p); }
        });
      }
      function step3(j, nocopy, password) {
        var n = j.files, t = j.thumbs;
        var o = overlay('<div class="fm-step">Step 3 of 3</div><h3 class="fm-bad">This can\'t be undone</h3>'
          + '<ul><li>' + num(n) + ' file' + (n === 1 ? '' : 's') + (t ? ' and ' + num(t) + ' thumbnail' + (t === 1 ? '' : 's') + ' (' + size(j.bytes) + ')' : '') + ' are deleted: settings, share links and their logs, captions, locations, private marks, view counts.</li>'
          + '<li class="fm-bad"><strong>Nothing stays private.</strong> The private marks are among the files deleted, so every photo, video and folder you had marked private can be seen by anyone who reaches the gallery, and a closed gallery opens. If that matters, move the private ones out of the gallery folder first.</li>'
          + '<li>Every share link stops working, and everyone is signed out. The admin is gone, and so are folders from elsewhere on the server that you had added (the folders themselves are not touched).</li><li>Your photos, videos, .md files and index.php are not touched.</li>'
          + '<li>The gallery is then plain and public, and the admin page offers to set it up again: open <code>?admin</code>, type the code from the file <code>gallery-setup-code.php</code> it makes beside index.php, and choose a password.</li></ul>'
          + '<details class="fold"><summary style="cursor:pointer;font-weight:600">Show the ' + num(n) + ' files</summary><pre>' + esc(j.paths.join('\n')) + (j.pathsMore ? '\n… and ' + num(j.pathsMore) + ' more' : '') + (t ? '\n+ ' + num(t) + ' thumbnails in the .gallery-cache folders' : '') + '</pre></details>'
          + '<p class="muted">The list is checked again when you press the red button. If anything has changed since, nothing is deleted.</p><div class="fm-err" role="alert" data-fm-err></div>'
          + '<div class="fm-acts"><button type="button" class="secondary" data-fm-x="cancel">Cancel</button><button type="button" class="fm-red" data-fm-x="go">Delete ' + num(n) + ' file' + (n === 1 ? '' : 's') + (t ? ' and ' + num(t) + ' thumbnails' : '') + ' for good</button></div>');
        var err = o.querySelector('[data-fm-err]');
        o.addEventListener('click', function (e) {
          var b = e.target.closest ? e.target.closest('[data-fm-x]') : null; if (!b) return;
          if (b.getAttribute('data-fm-x') === 'cancel') { o._close(); return; }
          b.disabled = true; err.textContent = '';
          post({ action: 'files_delete', password: password, typed: 'DELETE', fingerprint: j.fingerprint, nocopy: nocopy ? '1' : '0' }).then(function (r) {
            if (!r.ok && !r.left) { b.disabled = false; err.textContent = r.text || 'Not deleted.'; return; }
            o._close();
            var d = overlay('<h3>' + (r.ok ? 'Done' : 'Stopped') + '</h3><p>' + esc(r.text) + (r.ok ? ' You are signed out and the gallery is now plain and public, with its default settings. Your photos are as they were. To set it up again, open <code>?admin</code>, type the code from the file <code>gallery-setup-code.php</code> it makes beside index.php, and choose a password.' : '') + '</p>' + (r.left && r.left.length ? '<pre>' + esc(r.left.join('\n')) + '</pre>' : '') + '<div class="fm-acts"><button type="button" class="fm-primary" data-fm-x="done">Close</button></div>');
            d.addEventListener('click', function (e2) { if (e2.target.closest && e2.target.closest('[data-fm-x]')) { d._close(); location.href = location.pathname; } });
          }).catch(function () { b.disabled = false; err.textContent = 'Could not reach the gallery: check your connection.'; });
        });
      }
      root.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('[data-fm]') : null; if (!b) return; e.preventDefault();
        var w = b.getAttribute('data-fm');
        if (w === 'list') {
          if (!listBox.hidden) { listBox.hidden = true; b.textContent = 'Show list'; return; }
          b.disabled = true;
          load().then(function (j) { listBox.innerHTML = table(j); listBox.hidden = false; b.textContent = 'Hide list'; }).catch(function (x) { listBox.innerHTML = '<p class="muted">' + esc(x.message === 'session' ? 'Your session expired: reload the page and sign in again.' : x.message) + '</p>'; listBox.hidden = false; }).then(function () { b.disabled = false; });
        } else if (w === 'zip') zipDialog(null);
        else if (w === 'delete') deleteFlow();
      });
    })();
