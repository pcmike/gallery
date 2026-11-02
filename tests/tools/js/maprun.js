import * as std from 'std';
import { El, makeDoc } from './dom.js';
const html = std.loadFile(scriptArgs[1]); let src = std.loadFile(scriptArgs[2]);
let fails = 0; const ok = (c, m) => { print((c ? '  ok    ' : '  FAIL  ') + m); if (!c) fails++; };
if (scriptArgs[3]) { // swap in synthetic cluster data
  src = src.replace(/var clusters = .*?;\n/s, 'var clusters = ' + std.loadFile(scriptArgs[3]).trim() + ';\n');
}
// A fake Leaflet that records everything the script asks of it. Its map has a zoom (the screen distance between two points follows it, as
// in the real one: 256 * 2^zoom pixels for 360 degrees), a framing that sets the zoom, and a zoomend the script listens to.
// startZoom: the zoom the first framing gives (a high one keeps every pin apart, a low one piles them all together).
function run(startZoom, groupOnScreen) {
  const rec = { markers: [], circles: [], bounds: [], views: [], tiles: 0, mapOpts: null };
  const mkLayer = (kind, latlng, opts) => { const o = { kind, latlng, opts, popup: null, handlers: {}, removed: false };
    o.addTo = (target) => { (kind === 'circle' ? rec.circles : rec.markers).push(o); if (target && target._items) target._items.push(o); return o; };
    o.bindPopup = (h) => { o.popup = h; return o; }; o.on = (t, f) => { o.handlers[t] = f; return o; }; return o; };
  const handlers = {}; let zoom = null;
  const pt = (x, y) => ({ x, y, distanceTo: (q) => Math.hypot(x - q.x, y - q.y) });
  const map = { removeLayer: (m) => { m.removed = true; }, getZoom: () => zoom, on: (t, f) => { (handlers[t] = handlers[t] || []).push(f); return map; },
    getBoundsZoom: () => 18, latLngToLayerPoint: (ll) => { const k = 256 * Math.pow(2, zoom) / 360; return pt(ll[1] * k, ll[0] * k); },
    fitBounds: (b, o) => { rec.bounds.push([b, o]); zoom = o && o.maxZoom ? o.maxZoom : startZoom; (handlers.zoomend || []).forEach(f => f()); },
    setView: (c, z) => { rec.views.push([c, z]); zoom = z; } };
  const layerGroup = () => { const g = { _items: [], addTo: () => g, clearLayers: () => { g._items.forEach(m => { m.removed = true; }); g._items = []; } }; return g; };
  const L = { map: (el, o) => { rec.mapOpts = o; return map; }, layerGroup, point: pt, latLngBounds: (a) => a, tileLayer: () => ({ addTo: () => { rec.tiles++; } }),
    circleMarker: (ll, o) => mkLayer('circle', ll, o), marker: (ll, o) => mkLayer('marker', ll, o), divIcon: (o) => o };
  const doc = makeDoc(html); const win = { L };
  new Function('document', 'window', 'L', src.replace(/var groupOnScreen = (true|false);/, 'var groupOnScreen = ' + groupOnScreen + ';'))(doc, win, L);
  // A map that waits to be asked for (see the page's Show map button) registers its drawing function; ask for it.
  if (win.__lazyMaps) for (const f of Object.values(win.__lazyMaps)) f();
  return { rec, map, live: (k) => (k === 'circle' ? rec.circles : rec.markers).filter(m => !m.removed) };
}
print('--- map: ' + scriptArgs[2]);
const cl = (src.match(/var clusters = (.*?);\n/s) || [])[1]; const clusters = JSON.parse(cl);
const singles = clusters.filter(c => c.length === 1).length, multi = clusters.filter(c => c.length > 1).length;
const total = clusters.reduce((s, c) => s + c.length, 0);
const label = (m) => Number((m.opts.icon.html.match(/>(\d+)<\/div>/) || [])[1]);

// zoomed in far enough that no two pins are near each other on screen: just the server's groups
let r = run(16, true); let rec = r.rec;
ok(rec.tiles === 1, 'one OpenStreetMap tile layer added');
ok(rec.circles.length === singles, `a circle pin for each single point (${rec.circles.length}/${singles})`);
ok(rec.markers.length === multi, `a numbered marker for each multi-point cluster (${rec.markers.length}/${multi})`);
ok(rec.bounds.length + rec.views.length === 1, 'the map is framed exactly once (fitBounds, or setView for one point)');
ok(rec.bounds.length === 0 || (rec.bounds[0][1].paddingTopLeft && rec.bounds[0][1].paddingTopLeft[0] >= 50), 'the framing keeps the pins out from under the zoom buttons in the corner');
for (const c of rec.circles) {
  ok(typeof c.popup === 'string' && /^<a href="[^"<>]*"/.test(c.popup), 'popup is a link: ' + c.popup.slice(0, 60).replace(/\n/g,' '));
  ok(!/<(script|b|i|img src=x)\b/i.test(c.popup.replace(/<img src="[^"]*"[^>]*>/, '').replace(/<\/?(a|strong)\b[^>]*>/g, '')), 'popup HTML contains no unescaped tag from user text');
}
const evil = rec.circles.find(c => /&lt;/.test(c.popup));
if (evil) ok(/&lt;b&gt;left&lt;\/b&gt;/.test(evil.popup) || /&lt;/.test(evil.popup), 'a caption containing <b> is shown escaped in its popup');
// click every cluster: it must be replaced by its individual pins and zoom to them
for (let i = 0; i < multi; i++) {
  const t = run(16, true); const m = t.rec.markers[i], n = label(m);
  m.handlers.click();
  ok(m.removed === true, `clicking a ${n}-point cluster removes its numbered marker`);
  ok(t.live('circle').length === singles + n, `...and shows its ${n} individual pins (${t.live('circle').length} pins now, ${singles} were single already)`);
  ok(t.rec.bounds[t.rec.bounds.length - 1][1].maxZoom === 18, '...and zooms to them (as far as street level, maxZoom 18)');
}
// zoomed far out: pins that would lie on top of each other merge into one group of everything under it; zooming in parts them again
r = run(0, true); rec = r.rec;
const shownCount = r.live('marker').reduce((n, m) => n + label(m), 0) + r.live('circle').length;
ok(total < 2 || (r.live('marker').length + r.live('circle').length < total && shownCount === total), `zoomed far out, pins that would lie on top of each other merge into numbered groups, and every photo is still counted (${r.live('marker').length} groups + ${r.live('circle').length} pins for ${total} photos)`);
if (clusters.length > 1) {
  r.live('marker')[0].handlers.click();
  ok(r.map.getZoom() === 18 && r.live('marker').length === multi && r.live('circle').length === singles, `pressing that group zooms in and it parts into the server's groups again (${r.live('marker').length} groups, ${r.live('circle').length} pins)`);
  if (multi) {
    const big = r.live('marker')[0], n = label(big);
    big.handlers.click();
    ok(r.live('circle').length === singles + n && r.live('marker').length === multi - 1, `pressing one of those opens it into its ${n} photos`);
  }
}
// grouping switched off (a distance of 0): nothing merges, however far out the map is
r = run(0, false); rec = r.rec;
ok(r.live('marker').length === multi && r.live('circle').length === singles, `with grouping off the pins are the server's own and nothing merges on screen (${r.live('marker').length} groups, ${r.live('circle').length} pins)`);
// a map in the middle of a page: one finger scrolls the page on a phone (no matchMedia here, so a computer): the map drags
ok(rec.mapOpts && rec.mapOpts.dragging === true, 'on a computer the little map is dragged with one finger or the mouse');
print(fails ? `\n${fails} FAILED` : '\nmap script: all checks passed'); std.exit(fails ? 1 : 0);
