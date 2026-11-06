<?php if (is_admin_browser() && ENABLE_SHARE_LINKS): ?>
  let linkPop = null;
  function closeLinkPopup() { if (linkPop) { linkPop.remove(); linkPop = null; document.removeEventListener('keydown', linkPopKey, true); document.removeEventListener('pointerdown', linkPopAway, true); } }
  function linkPopKey(e) { if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closeLinkPopup(); } else if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') closeLinkPopup(); }
  function linkPopAway(e) { if (linkPop && !linkPop.contains(e.target) && e.target !== lightboxCopyBtn && !lightboxCopyBtn.contains(e.target)) closeLinkPopup(); }
  function openPrivateLinkPopup() {
    if (linkPop) { closeLinkPopup(); return; }
    const file = files[current];
    linkPop = document.createElement('div'); linkPop.className = 'lb-sharepop'; linkPop.setAttribute('role', 'dialog'); linkPop.setAttribute('aria-label', 'This photo is private');
    linkPop.innerHTML = '<div class="lsp-h"><?= ui_icon('lock') ?> This photo is private</div><p>A plain link only opens for people who already have access. To let someone see just this photo, make a share link for it.</p>'
      + '<div class="lsp-btns"><button type="button" class="lsp-share"><?= ui_icon('share') ?> Share just this photo&hellip;</button><button type="button" class="lsp-copy"><?= ui_icon('copy') ?> Copy link</button></div>'
      + '<p class="lsp-fine">Share links are managed in the Shares tab.</p>';
    document.body.appendChild(linkPop);
    linkPop.querySelector('.lsp-share').addEventListener('click', () => { closeLinkPopup(); window.GalleryShare.quick({ kind: 'photos', files: [file], allPrivate: true, anyPrivate: true }); });
    linkPop.querySelector('.lsp-copy').addEventListener('click', () => { closeLinkPopup(); copyPhotoLink(); });
    document.addEventListener('keydown', linkPopKey, true); document.addEventListener('pointerdown', linkPopAway, true);
  }
  // (moving to another photo, or closing the viewer, closes the question)
  document.addEventListener('click', (e) => { if (linkPop && e.target.closest && e.target.closest('.lightbox .close, .lightbox .nav, .lightbox-close-row')) closeLinkPopup(); }, true);
<?php else: ?>
  function openPrivateLinkPopup() { copyPhotoLink(); }
<?php endif; ?>

  // The "this link expires on ..." line for someone using a share link: the time in their own zone, amber in the last day, and a dismissal kept for as long as the end time and that state stay the same.
  (function () {
    var el = document.getElementById('link-headsup'); if (!el) return;
    var ends = +el.getAttribute('data-ends'), t = el.querySelector('time'), d = new Date(ends * 1000);
    if (!isNaN(d)) t.textContent = d.toLocaleString(undefined, { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
    var soon = ends * 1000 - Date.now() < 86400000;
    el.classList.toggle('soon', soon);
    var key = 'galleryHeadsUp:' + el.getAttribute('data-key') + (soon ? ':soon' : '');
    try { if (localStorage.getItem(key)) el.hidden = true; } catch (e) {}
    el.querySelector('.lh-x').addEventListener('click', function () { el.hidden = true; try { localStorage.setItem(key, '1'); } catch (e) {} });
  })();

<?php if ((link_viewer() !== null && !is_revealed()) || public_log_wanted()): ?>
  const galleryEntry = <?= json_encode(public_log_arrival()) ?>;   // (how this page was reached, for the public address's log: null unless it was an arrival)
