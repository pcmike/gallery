  var map = L.map(mapEl, { dragging: !twoFingers });
  if (twoFingers) {
    var hint = document.createElement('div'); hint.className = 'map-hint'; hint.hidden = true; hint.textContent = 'Use two fingers to move the map'; mapEl.appendChild(hint);
    var hintTimer = null, hintFrom = null;
    mapEl.addEventListener('touchstart', function (e) { hintFrom = e.touches.length === 1 ? [e.touches[0].clientX, e.touches[0].clientY] : null; if (e.touches.length > 1) hint.hidden = true; }, { passive: true });
    mapEl.addEventListener('touchmove', function (e) {
      if (e.touches.length !== 1 || !hintFrom) return;
      if (Math.abs(e.touches[0].clientX - hintFrom[0]) + Math.abs(e.touches[0].clientY - hintFrom[1]) < 10) return;
      hint.hidden = false; clearTimeout(hintTimer); hintTimer = setTimeout(function () { hint.hidden = true; }, 1400);
    }, { passive: true });
  }
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a> contributors',
    maxZoom: 19,
  }).addTo(map);

  function escapeHtml(s) {
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  function popupHtml(pt) {
    var cap = pt.caption ? pt.caption : pt.label;
    return '<a href="' + escapeHtml(pt.link) + '" style="display:block;text-decoration:none;color:inherit;">' +
      '<img src="' + escapeHtml(pt.thumbUrl) + '" style="width:120px;height:120px;object-fit:cover;border-radius:6px;display:block;margin-bottom:6px;" loading="lazy">' +
      '<strong>' + escapeHtml(cap) + '</strong></a>';
  }

  // Pins: photos within the grouping distance (server side, in miles) always share a pin. On top of that, pins that would
  // overlap on screen at the current zoom are merged (and split again as the map is zoomed in), so every pin on screen can be
  // pressed with a finger. Pressing a group zooms in until its photos part; where they can't (the zoom is at its limit) it
  // opens into one pin per photo. A distance of 0 means no grouping at all, so nothing is merged then either.
  var SEP = 40;
  var layer = L.layerGroup().addTo(map);
  var shown = null, n = 0;
  clusters.forEach(function (ms) { ms.forEach(function (m) { m._i = n++; }); });
  var base = clusters.map(function (ms) {
    return { members: ms, lat: ms.reduce(function (s, m) { return s + m.lat; }, 0) / ms.length, lng: ms.reduce(function (s, m) { return s + m.lng; }, 0) / ms.length, solo: false };
  });
  function keyOf(ms) { return ms.map(function (m) { return m._i; }).join(','); }
  function partsOf(it) { return it.parts || [it]; }
  function combine(a, b) {
    var na = a.members.length, nb = b.members.length;
    return { members: a.members.concat(b.members), parts: partsOf(a).concat(partsOf(b)), solo: false, lat: (a.lat * na + b.lat * nb) / (na + nb), lng: (a.lng * na + b.lng * nb) / (na + nb) };
  }
  function merge(items) {
    var out = [], used = [], pts = items.map(function (it) { return map.latLngToLayerPoint([it.lat, it.lng]); }), i, j;
    for (i = 0; i < items.length; i++) {
      if (used[i]) continue;
      var it = items[i]; used[i] = true;
      for (j = i + 1; !it.solo && j < items.length; j++) {
        if (!used[j] && !items[j].solo && pts[i].distanceTo(pts[j]) < SEP) { it = combine(it, items[j]); used[j] = true; }
      }
      out.push(it);
    }
    return out;
  }
  function arrange() {
    var items = base.slice();
    if (groupOnScreen) {
      items = merge(items);
      // (a merged group sits at its middle, which can be near another one: go again until none are, for a page of a sensible size)
      for (var pass = 0; pass < 6 && items.length <= 300; pass++) {
        var next = merge(items); if (next.length === items.length) break; items = next;
      }
    }
    var sig = items.map(function (it) { return keyOf(it.members); }).join('|');
    if (sig === shown) return;
    shown = sig;
    layer.clearLayers();
    items.forEach(function (it) { if (it.members.length === 1) addSingle(it.members[0]); else addCluster(it); });
  }
  function addSingle(pt) {
    L.circleMarker([pt.lat, pt.lng], { radius: coarse ? 12 : 9, color: '#fff', weight: 2, fillColor: pt.color, fillOpacity: 0.9 })
      .addTo(layer)
      .bindPopup(popupHtml(pt));
  }
  function addCluster(it) {
    var members = it.members;
    var marker = L.marker([it.lat, it.lng], {
      icon: L.divIcon({
        html: '<div style="background:' + members[0].color + ';color:#fff;border-radius:50%;width:32px;height:32px;display:flex;align-items:center;justify-content:center;font-size:0.85rem;font-weight:600;box-shadow:0 0 0 2px #fff;">' + members.length + '</div>',
        className: '', iconSize: [32, 32], iconAnchor: [16, 16],
      }),
    }).addTo(layer);
    marker.on('click', function () {
      var parts = partsOf(it);
      var bounds = L.latLngBounds(members.map(function (m) { return [m.lat, m.lng]; }));
      var z = Math.min(18, map.getBoundsZoom(bounds, false, L.point(86, 86)));
      if (parts.length === 1) {   // one pin of photos within the grouping distance: it opens into its photos (which then group by screen distance like any others)
        var at = base.indexOf(parts[0]);
        base.splice.apply(base, [at, 1].concat(members.map(function (m) { return { members: [m], lat: m.lat, lng: m.lng, solo: false }; })));
      } else if (z >= 18 || z <= map.getZoom()) {   // several pins that zooming can't part: they stay as they are, each pin on its own
        parts.forEach(function (p) { p.solo = true; });
      }
      if (z > map.getZoom()) map.fitBounds(bounds, { maxZoom: 18, paddingTopLeft: [56, 56], paddingBottomRight: [30, 30] });   // (the zoom's end groups again)
      else arrange();
    });
  }

  var allBounds = [];
  clusters.forEach(function (members) { members.forEach(function (m) { allBounds.push([m.lat, m.lng]); }); });
  if (allBounds.length === 1) {
    map.setView(allBounds[0], 13);
  } else {
    map.fitBounds(allBounds, { paddingTopLeft: [56, 56], paddingBottomRight: [30, 30] });   // (the zoom buttons sit in the top left corner: keep the pins out from under them)
  }
  arrange();
  map.on('zoomend', arrange);
  }
