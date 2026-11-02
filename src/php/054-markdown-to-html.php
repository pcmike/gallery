/**
 * Converts one .md file's content to HTML. Also, as a side effect:
 *  - attaches a thumbnail strip + view count after any paragraph that
 *    mentions a photo group's name (auto-link),
 *  - records a per-group color into $groupColors (by reference) when a
 *    {color: value} directive appears in that same paragraph, and
 *  - records a per-group status ('sold' or 'reserved') into $groupStatus
 *    (by reference) when a {sold} or {reserved}/{held} directive appears
 *    in that same paragraph.
 */
function markdown_to_html($text, $groups, $files, &$groupColors, &$groupStatus, &$groupLabels, &$renderClaims, $photoViews, $prefix = '') {
    $text = str_replace("\r\n", "\n", $text);
    $lines = explode("\n", $text);
    $html = '';
    $listType = null;
    $inCode = false;
    $codeBuf = '';
    $paraLines = [];

    $flushPara = function () use (&$html, &$paraLines, $groups, $files, &$groupColors, &$groupStatus, &$groupLabels, &$renderClaims, $photoViews, $prefix) {
        if (empty($paraLines)) return;

        // Pull out optional {color: value} / {sold} / {reserved} (or
        // {held}) / {group: Name} / {photos: a.jpg, b.jpg} directives,
        // and strip them (and any resulting empty lines) from what
        // actually shows. If both {sold} and {reserved}/{held} appear,
        // sold wins — it's the more final state.
        $colorValue = null;
        $status = '';
        $explicitGroupName = null;
        $explicitPhotosRaw = null;
        $align = null;   // {left} / {center} / {right}: how this paragraph's text is aligned
        $cleanLines = [];
        foreach ($paraLines as $l) {
            if ($colorValue === null && preg_match('/\{\s*color\s*:\s*([^}]+)\}/i', $l, $cm)) {
                $candidate = trim($cm[1]);
                if (is_safe_color($candidate)) {
                    $colorValue = $candidate;
                }
            }
            if (preg_match('/\{\s*sold\s*\}/i', $l)) {
                $status = 'sold';
            } elseif ($status !== 'sold' && preg_match('/\{\s*(reserved|held)\s*\}/i', $l)) {
                $status = 'reserved';
            }
            if ($explicitGroupName === null && preg_match('/\{\s*group\s*:\s*([^}]+)\}/i', $l, $ggm)) {
                $explicitGroupName = trim($ggm[1]);
            }
            if ($explicitPhotosRaw === null && preg_match('/\{\s*photos\s*:\s*([^}]+)\}/i', $l, $ppm)) {
                $explicitPhotosRaw = $ppm[1];
            }
            if ($align === null && preg_match('/\{\s*(left|center|centre|right)\s*\}/i', $l, $am)) {
                $align = strtolower($am[1]) === 'centre' ? 'center' : strtolower($am[1]);
            }
            $l = trim(preg_replace(
                ['/\{\s*color\s*:\s*[^}]+\}/i', '/\{\s*sold\s*\}/i', '/\{\s*(reserved|held)\s*\}/i',
                 '/\{\s*group\s*:\s*[^}]+\}/i', '/\{\s*photos\s*:\s*[^}]+\}/i', '/\{\s*(left|center|centre|right)\s*\}/i'],
                '', $l
            ));
            if ($l !== '') $cleanLines[] = $l;
        }

        // Register this paragraph's {photos:} claims in render order —
        // before the empty-paragraph check below, so a directive-only
        // paragraph (no other text) still consumes its claim. Otherwise
        // a *later* paragraph re-referencing the same file would never
        // see it as already taken, and would win the claim instead of
        // correctly losing it (extraction, further up, already decided
        // this paragraph's claim wins — rendering has to agree).
        $explicitPhotos = [];
        if ($explicitPhotosRaw !== null) {
            foreach (explode(',', $explicitPhotosRaw) as $p) {
                // Names are read relative to the folder the notes are in ($prefix), like extraction does.
                $p = basename(trim($p));
                $p = $p === '' ? '' : $prefix . $p;
                if ($p !== '' && in_array($p, $files, true) && !isset($renderClaims[$p])) {
                    $explicitPhotos[] = $p;
                    $renderClaims[$p] = true;
                }
            }
        }

        // An explicit {group:}+{photos:} pair identifies a real, already-
        // built group (see GROUPING above) regardless of whether this
        // paragraph has any other text — so its color/status/label side
        // effects need to apply here, before the empty-paragraph check
        // below, or a textless {group:}/{photos:} paragraph would return
        // early and never actually apply them.
        $matchedKeys = [];
        if ($explicitGroupName !== null && !empty($explicitPhotos)) {
            $slug = slugify($explicitGroupName);
            if ($slug !== '' && isset($groups[$slug])) {
                $matchedKeys[] = $slug;
                if ($colorValue !== null) $groupColors[$slug] = $colorValue;
                if ($status) $groupStatus[$slug] = $status;
                $groupLabels[$slug] = $explicitGroupName;
            }
        }

        // A paragraph left empty after stripping directives renders
        // nothing in the note box — including for {group:}/{photos:}
        // with no other text. That's fine: their structural effect (the
        // box existing, membership, positional reordering, and the
        // color/status/label side effects above) already happened; this
        // check only decides whether there's a note-box entry to show,
        // and with no text, there's genuinely nothing to show.
        if (empty($cleanLines)) {
            $paraLines = [];
            return;
        }

        $rawText = implode(' ', $cleanLines);

        // With neither an explicit group match nor bare {photos:}, fall
        // back to the existing behavior: match any group whose name is
        // literally mentioned in the text.
        if (empty($matchedKeys) && empty($explicitPhotos)) {
            foreach ($groups as $key => $groupFiles) {
                if (preg_match(group_mention_pattern($key), $rawText)) {
                    $matchedKeys[] = $key;
                }
            }
        }

        $itemId = null;
        if (!empty($matchedKeys)) {
            $itemId = 'item-' . slugify($matchedKeys[0]);
        } elseif (!empty($explicitPhotos)) {
            // No box, no name — the closest thing to a stable identity
            // here is the first listed file (full filename, extension
            // included, to stay unique — see group_key()), so that's
            // what the permalink anchors on.
            $itemId = 'item-' . slugify(substr($explicitPhotos[0], strlen($prefix)));
        }
        $idAttr = $itemId ? (' id="' . htmlspecialchars($itemId, ENT_QUOTES, 'UTF-8') . '"') : '';

        $inner = implode('<br>', array_map('inline_markdown', $cleanLines));
        $alignCss = $align !== null ? 'text-align: ' . $align . ';' : '';
        $alignAttr = $alignCss !== '' ? ' style="' . $alignCss . '"' : '';
        if ($status === 'sold') {
            $html .= '<div class="sold-item"' . $idAttr . $alignAttr . '><span class="sold-badge">SOLD</span><p>' . $inner . '</p></div>';
        } elseif ($status === 'reserved') {
            $html .= '<div class="reserved-item"' . $idAttr . $alignAttr . '><span class="reserved-badge">RESERVED</span><p>' . $inner . '</p></div>';
        } else {
            // A {color:} always accents its own paragraph with a bar, so the text and the photos or folder it colours
            // read as one item (and with nothing to put a box round it still does something visible).
            $accent = ($colorValue !== null)
                ? ' class="accent-note" style="--bar: ' . htmlspecialchars($colorValue, ENT_QUOTES, 'UTF-8') . ';' . $alignCss . '"'
                : $alignAttr;
            $html .= '<p' . $idAttr . $accent . '>' . $inner . '</p>';
        }

        if (!empty($matchedKeys)) {
            foreach ($matchedKeys as $i => $key) {
                $groupFiles = $groups[$key];
                $linkHtml = ($i === 0) ? render_permalink_button($itemId) : '';
                $html .= render_group_thumbs($key, $groupFiles, $files, $photoViews, $status, $linkHtml);
                if ($colorValue !== null) {
                    $groupColors[$key] = $colorValue;
                }
                if ($status) {
                    $groupStatus[$key] = $status;
                }
                if ($explicitGroupName !== null) {
                    $groupLabels[$key] = $explicitGroupName;
                }
            }
        } elseif (!empty($explicitPhotos)) {
            $html .= render_photo_thumbs_plain($explicitPhotos, $files, render_permalink_button($itemId));
            foreach ($explicitPhotos as $pf) {
                // Must match the key these standalone singles were
                // stored under in $groups (see GROUPING above): the
                // file's own full filename, not its extension-stripped
                // base.
                $pfKey = substr($pf, strlen($prefix));
                if ($colorValue !== null) $groupColors[$pfKey] = $colorValue;
                if ($status) $groupStatus[$pfKey] = $status;
            }
        }
        $paraLines = [];
    };
    $closeList = function () use (&$html, &$listType) {
        if ($listType) { $html .= "</$listType>"; $listType = null; }
    };

    foreach ($lines as $line) {
        $trimmed = trim($line);

        if (strpos($trimmed, '```') === 0) {
            $flushPara();
            if ($inCode) {
                $html .= '<pre><code>' . htmlspecialchars(rtrim($codeBuf, "\n"), ENT_QUOTES, 'UTF-8') . '</code></pre>';
                $codeBuf = '';
                $inCode = false;
            } else {
                $closeList();
                $inCode = true;
            }
            continue;
        }
        if ($inCode) {
            $codeBuf .= $line . "\n";
            continue;
        }

        if ($trimmed === '') {
            $flushPara();
            $closeList();
            continue;
        }

        if (preg_match('/^(#{1,3})\s+(.+)$/', $trimmed, $m)) {
            $flushPara();
            $closeList();
            $level = strlen($m[1]);
            $headingText = $m[2];
            $headingAlign = '';
            if (preg_match('/\{\s*(left|center|centre|right)\s*\}/i', $headingText, $hm)) {
                $headingAlign = ' style="text-align: ' . (strtolower($hm[1]) === 'centre' ? 'center' : strtolower($hm[1])) . ';"';
                $headingText = trim(preg_replace('/\{\s*(left|center|centre|right)\s*\}/i', '', $headingText));
            }
            $html .= "<h{$level}{$headingAlign}>" . inline_markdown($headingText) . "</h{$level}>";
            continue;
        }

        if (preg_match('/^>\s?(.*)$/', $trimmed, $m)) {
            $flushPara();
            $closeList();
            $html .= '<blockquote>' . inline_markdown($m[1]) . '</blockquote>';
            continue;
        }

        if (preg_match('/^[-*]\s+(.+)$/', $trimmed, $m)) {
            $flushPara();
            if ($listType !== 'ul') {
                $closeList();
                $html .= '<ul>';
                $listType = 'ul';
            }
            $html .= '<li>' . inline_markdown($m[1]) . '</li>';
            continue;
        }

        if (preg_match('/^\d+\.\s+(.+)$/', $trimmed, $m)) {
            $flushPara();
            if ($listType !== 'ol') {
                $closeList();
                $html .= '<ol>';
                $listType = 'ol';
            }
            $html .= '<li>' . inline_markdown($m[1]) . '</li>';
            continue;
        }

        // A line like "(1) Item description..." always starts a new
        // paragraph, even with no blank line before it — this is what
        // lets each numbered item in a listing get its own auto-linked
        // thumbnails / color / sold state, without requiring blank lines.
        if (preg_match('/^\(\d+\)\s+/', $trimmed)) {
            $flushPara();
        }

        $closeList();
        $paraLines[] = $trimmed;
    }
    $flushPara();
    $closeList();
    if ($inCode && $codeBuf !== '') {
        $html .= '<pre><code>' . htmlspecialchars(rtrim($codeBuf, "\n"), ENT_QUOTES, 'UTF-8') . '</code></pre>';
    }
    return unprotect_inline_code($html);
}

/** The look of a video's picture wherever it is shown (see video_tile_parts); used by the gallery and the admin. */
function video_tile_css() {
    return <<<'CSS'
  .vtile { position: relative; }
  /* A video's own first frame, over the placeholder, only once the browser has it. */
  .vtile .tile-video { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; opacity: 0; pointer-events: none; transition: opacity 0.2s ease, transform 0.25s ease; }
  .vtile .tile-video.ready { opacity: 1; }
  .vtile .tile-preview { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; opacity: 0; pointer-events: none; transition: opacity 0.2s ease; }
  .vtile .tile-preview.ready { opacity: 1; }
  /* Dragging a finger sideways across a video tile scrubs through it; up and down still scrolls the page. */
  .vtile[data-strip] { touch-action: pan-y; }
  .vtile .tile-strip { position: absolute; inset: 0; background-repeat: no-repeat; pointer-events: none; }
  /* A video's picture carries a big play mark in the middle so it is told apart at a glance; the clip and the scrub frames
     are laid over the tile after it, so they cover it while they show. */
  .vtile .video-mark {
    position: absolute; left: 50%; top: 50%;
    width: clamp(48px, 30%, 92px); aspect-ratio: 1;
    transform: translate(-50%, -50%);
    display: flex; align-items: center; justify-content: center;
    border-radius: 50%;
    background: rgba(0,0,0,0.55); border: 2px solid rgba(255,255,255,0.85); color: #fff;
    box-shadow: 0 2px 12px rgba(0,0,0,0.45);
    pointer-events: none;
  }
  .vtile .video-mark .ico { width: 46%; height: 46%; margin-left: 6%; fill: currentColor; stroke: currentColor; stroke-linejoin: round; }
  .linked-photos-thumbs .vthumb { display: block; width: 56px; height: 56px; border-radius: 6px; overflow: hidden; cursor: pointer; transition: transform 0.15s ease; }
  .linked-photos-thumbs .vthumb img { width: 100%; height: 100%; }
  .linked-photos-thumbs .vthumb:hover { transform: scale(1.08); }
  .linked-photos-thumbs .vthumb .video-mark { width: 26px; border-width: 1.5px; box-shadow: none; }
CSS;
}

/** The script behind video tiles' hover clips, finger and mouse scrubbing, and first-frame pictures; shared by the gallery
 *  and the admin (see video_tile_parts for the markup it finds). */
function video_tile_script() {
    return <<<'JS'
(function () {
  const viewerOpen = function () { const l = document.getElementById('lightbox'); return !!(l && l.classList.contains('open')); };
  // Video tile clips. A made clip (a few seconds, silent, looping) plays over the tile: on a desktop when the pointer rests on
  // it, on a touch screen for the tile nearest the middle of the screen once the page has stopped moving. Only one plays at a
  // time, and starting another (or scrolling, leaving, switching tab, opening the viewer) stops the previous completely,
  // download included. Where the server has no clip, a desktop hover plays the video itself where the browser can. Nothing
  // plays by itself for someone who asked their device for reduced motion or for less data.
  const hoverish = !!(window.matchMedia && window.matchMedia('(hover: hover)').matches);
  const calmDevice = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) || !!(navigator.connection && navigator.connection.saveData);
  let active = null, pending = null;
  const halt = function () {
    if (pending) { clearTimeout(pending); pending = null; }
    if (active) { const a = active; active = null; a.stop(); }
  };
  window.__previewHalt = halt;
  const makeClip = function (card) {
    const el = document.createElement('video');
    el.className = 'tile-preview'; el.muted = true; el.loop = true; el.playsInline = true; el.preload = 'auto'; el.setAttribute('aria-hidden', 'true'); el.tabIndex = -1;
    const handle = {
      card: card, el: el,
      stop: function () { el.pause(); el.removeAttribute('src'); try { el.load(); } catch (e) {} el.remove(); }
    };
    el.addEventListener('error', function () { if (active === handle) active = null; handle.stop(); });
    el.addEventListener('playing', function () { el.classList.add('ready'); });
    el.src = card.dataset.preview;
    card.appendChild(el);
    const p = el.play(); if (p && p.catch) p.catch(function () { if (active === handle) active = null; handle.stop(); });
    return handle;
  };
  if (hoverish) {
    const attach = function (card, start) {
      card.addEventListener('mouseenter', function () {
        halt();
        pending = setTimeout(function () { pending = null; active = start(card); }, 250);
      });
      card.addEventListener('mouseleave', halt);
    };
    document.querySelectorAll('.vtile[data-preview]').forEach(function (card) { attach(card, makeClip); });
    document.querySelectorAll('.vtile:not([data-preview]) .tile-video').forEach(function (v) {
      attach(v.closest('.vtile'), function () {
        if (!v.classList.contains('ready')) return null;
        v.loop = true; const p = v.play(); if (p && p.catch) p.catch(function () {});
        return { card: v.closest('.vtile'), el: v, stop: function () { v.pause(); try { v.currentTime = 0.1; } catch (e) {} } };
      });
    });
    window.addEventListener('blur', halt);
    window.addEventListener('scroll', halt, { passive: true });
    document.addEventListener('visibilitychange', halt);
  } else if (!calmDevice) {
    const cards = Array.prototype.slice.call(document.querySelectorAll('.card[data-preview]'));
    if (cards.length) {
      let timer = null;
      const choose = function () {
        timer = null;
        if (document.hidden || viewerOpen()) { halt(); return; }
        const mid = window.innerHeight / 2;
        let best = null, bestDist = Infinity;
        cards.forEach(function (card) {
          const r = card.getBoundingClientRect();
          if (Math.min(r.bottom, window.innerHeight) - Math.max(r.top, 0) < r.height * 0.65) return;   // mostly on screen
          const d = Math.abs((r.top + r.bottom) / 2 - mid);
          if (d < bestDist) { best = card; bestDist = d; }
        });
        if (best && active && active.card === best) return;
        halt();
        if (best) active = makeClip(best);
      };
      const schedule = function () { if (timer) clearTimeout(timer); timer = setTimeout(choose, 400); };
      window.addEventListener('scroll', function () { halt(); schedule(); }, { passive: true });   // stops while the page moves, picks again when it settles
      window.addEventListener('resize', schedule);
      document.addEventListener('visibilitychange', function () { halt(); schedule(); });
      window.addEventListener('pageshow', schedule);
      window.__previewResume = schedule;
      schedule();
    }
  }
  // Getting the clips ready before anyone needs them: once the page is up, the tiles in view whose clip isn't made yet are
  // asked to make it, one request at a time, a dozen at most, with nothing to download. Stops at the first refusal.
  function warmUp(attr) {
    const cards = Array.prototype.slice.call(document.querySelectorAll('.vtile[' + attr + ']:not([' + attr + '-ready])'));
    if (!cards.length || typeof IntersectionObserver === 'undefined' || typeof fetch === 'undefined') return;
    let queue = [], running = false, done = 0, dead = false;
    const next = function () {
      if (running || dead || done >= 12 || !queue.length || document.hidden) return;
      running = true;
      const card = queue.shift();
      fetch(card.getAttribute(attr) + '&warm=1', { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { if (r.status === 204) { card.setAttribute(attr + '-ready', '1'); done++; } else { dead = true; } })
        .catch(function () { dead = true; })
        .then(function () { running = false; setTimeout(next, 300); });
    };
    const io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) { if (e.isIntersecting) { io.unobserve(e.target); queue.push(e.target); } });
      setTimeout(next, 800);
    }, { rootMargin: '200px' });
    cards.forEach(function (c) { io.observe(c); });
    document.addEventListener('visibilitychange', function () { if (!document.hidden) next(); });
  }
  warmUp('data-preview');
  if ((navigator.maxTouchPoints || 0) > 0 || !hoverish) warmUp('data-strip');
  // Scrubbing a video tile with a finger: a horizontal drag across it shows the frame under the finger (frames from the
  // whole video, left edge = the start, right edge = the end), following the finger back and forth; a vertical drag is still
  // the page scrolling, a plain tap still opens the viewer, and a drag never opens it. A tile whose sprite isn't made yet
  // simply doesn't scrub (it is prepared in the background), and a playing clip gives way to the scrub.
  document.querySelectorAll('.vtile[data-strip]').forEach(function (card) {
    const cols = Number(card.getAttribute('data-strip-cols')) || 10, rows = Number(card.getAttribute('data-strip-rows')) || 1;
    let sx = 0, sy = 0, tracking = false, scrubbing = false, overlay = null, img = null;
    const show = function (x) {
      const r = card.getBoundingClientRect();
      const f = Math.min(cols * rows - 1, Math.max(0, Math.floor(Math.min(0.999, Math.max(0, (x - r.left) / r.width)) * cols * rows)));
      const col = f % cols, row = Math.floor(f / cols);
      const cell = r.height;   // each frame is shown as tall as the tile (the frames are square)
      overlay.style.backgroundSize = (cell * cols) + 'px ' + (cell * rows) + 'px';
      overlay.style.backgroundPosition = (-(col * cell) + (r.width - cell) / 2) + 'px ' + (-(row * cell)) + 'px';
    };
    card.addEventListener('touchstart', function (e) {
      if (e.touches.length !== 1) { tracking = false; return; }
      tracking = true; scrubbing = false; sx = e.touches[0].clientX; sy = e.touches[0].clientY;
      if (!img) { img = new Image(); img.src = card.getAttribute('data-strip'); }
    }, { passive: true });
    card.addEventListener('touchmove', function (e) {
      if (!tracking) return;
      const t = e.touches[0];
      if (!scrubbing) {
        const dx = Math.abs(t.clientX - sx), dy = Math.abs(t.clientY - sy);
        if (dx < 8 && dy < 8) return;
        if (dy > dx) { tracking = false; return; }
        if (!img.complete || !img.naturalWidth) return;
        if (window.__previewHalt) window.__previewHalt();
        overlay = document.createElement('div'); overlay.className = 'tile-strip';
        overlay.style.backgroundImage = 'url("' + img.src.replace(/"/g, '%22') + '")';
        card.appendChild(overlay);
        scrubbing = true;
      }
      e.preventDefault();
      show(t.clientX);
    }, { passive: false });
    const end = function () {
      tracking = false;
      if (scrubbing) { scrubbing = false; window.__scrubbedAt = Date.now(); if (window.__previewResume) window.__previewResume(); }
      if (overlay) { overlay.remove(); overlay = null; }
    };
    card.addEventListener('touchend', end);
    card.addEventListener('touchcancel', end);
    // With a mouse the same frames follow the pointer sideways across the tile. A pointer that rests starts the clip as
    // before; one that moves sideways (a few pixels, or more once a clip is playing) swaps it for the frame under it, and
    // the tile keeps scrubbing until the pointer leaves, so a pause to look at a frame doesn't start the clip. A click
    // still opens the viewer.
    if (hoverish) {
      let mx = 0, over = false, mouseScrubbing = false;
      const leave = function () {
        over = false; mouseScrubbing = false;
        if (overlay) { overlay.remove(); overlay = null; }
      };
      card.addEventListener('mouseenter', function (e) {
        over = true; mouseScrubbing = false; mx = e.clientX;
        if (!img) { img = new Image(); img.src = card.getAttribute('data-strip'); }
      });
      card.addEventListener('mousemove', function (e) {
        if (!over) {   // the pointer is on the tile (a move says so) though nothing announced it, e.g. after the window was left and returned to
          over = true; mx = e.clientX;
          if (!img) { img = new Image(); img.src = card.getAttribute('data-strip'); }
        }
        if (!mouseScrubbing) {
          if (Math.abs(e.clientX - mx) < (active && active.card === card ? 15 : 6)) return;
          if (!img || !img.complete || !img.naturalWidth) return;
          halt();
          overlay = document.createElement('div'); overlay.className = 'tile-strip';
          overlay.style.backgroundImage = 'url("' + img.src.replace(/"/g, '%22') + '")';
          card.appendChild(overlay);
          mouseScrubbing = true;
        }
        show(e.clientX);
      });
      card.addEventListener('mouseleave', leave);
      window.addEventListener('blur', leave);
      document.addEventListener('visibilitychange', leave);
    }
  });
  // A video's own first frame over its placeholder, once the browser has one; if it can't play the video, nothing changes.
  document.querySelectorAll('.tile-video').forEach(function (v) {
    const ready = function () { v.classList.add('ready'); };
    v.addEventListener('loadeddata', ready);
    v.addEventListener('error', function () { v.remove(); });
    if (v.readyState >= 2) ready();
  });
})();
JS;
}

