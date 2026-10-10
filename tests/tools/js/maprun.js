import * as std from 'std';
import { El, makeDoc } from './dom.js';
const html = std.loadFile(scriptArgs[1]); let src = std.loadFile(scriptArgs[2]);
let fails = 0; const ok = (c, m) => { print((c ? '  ok    ' : '  FAIL  ') + m); if (!c) fails++; };
// A fake Leaflet that records everything the script asks of it.
const rec = { markers: [], circles: [], layersRemoved: 0, bounds: [], views: [], tiles: 0 };
const mkLayer = (kind, latlng, opts) => { const o = { kind, latlng, opts, popup: null, handlers: {} }; o.addTo = () => { (kind==='circle'?rec.circles:rec.markers).push(o); return o; }; o.bindPopup = (h) => { o.popup = h; return o; }; o.on = (t, f) => { o.handlers[t] = f; return o; }; return o; };
const L = { map: () => ({ removeLayer: (m) => { rec.layersRemoved++; m.removed = true; }, fitBounds: (b, o) => rec.bounds.push([b, o]), setView: (c, z) => rec.views.push([c, z]) }),
  tileLayer: () => ({ addTo: () => { rec.tiles++; } }), circleMarker: (ll, o) => mkLayer('circle', ll, o), marker: (ll, o) => mkLayer('marker', ll, o), divIcon: (o) => o };
const doc = makeDoc(html); const win = { L };
if (scriptArgs[3]) { // swap in synthetic cluster data
  src = src.replace(/var clusters = .*?;\n/s, 'var clusters = ' + std.loadFile(scriptArgs[3]).trim() + ';\n');
}
new Function('document', 'window', 'L', src)(doc, win, L);
// A map that waits to be asked for (see the page's Show map button) registers its drawing function; ask for it.
if (win.__lazyMaps) for (const f of Object.values(win.__lazyMaps)) f();
print('--- map: ' + scriptArgs[2]);
const cl = (src.match(/var clusters = (.*?);\n/s) || [])[1]; const clusters = JSON.parse(cl);
const singles = clusters.filter(c => c.length === 1).length, multi = clusters.filter(c => c.length > 1).length;
ok(rec.tiles === 1, 'one OpenStreetMap tile layer added');
ok(rec.circles.length === singles, `a circle pin for each single point (${rec.circles.length}/${singles})`);
ok(rec.markers.length === multi, `a numbered marker for each multi-point cluster (${rec.markers.length}/${multi})`);
ok(rec.bounds.length + rec.views.length === 1, 'the map is framed exactly once (fitBounds, or setView for one point)');
for (const c of rec.circles) {
  ok(typeof c.popup === 'string' && /^<a href="[^"<>]*"/.test(c.popup), 'popup is a link: ' + c.popup.slice(0, 60).replace(/\n/g,' '));
  ok(!/<(script|b|i|img src=x)\b/i.test(c.popup.replace(/<img src="[^"]*"[^>]*>/, '').replace(/<\/?(a|strong)\b[^>]*>/g, '')), 'popup HTML contains no unescaped tag from user text');
}
const evil = rec.circles.find(c => /&lt;/.test(c.popup));
if (evil) ok(/&lt;b&gt;left&lt;\/b&gt;/.test(evil.popup) || /&lt;/.test(evil.popup), 'a caption containing <b> is shown escaped in its popup');
// click every cluster: it must be replaced by its individual pins and zoom to them
for (const m of rec.markers) {
  const before = rec.circles.length, n = Number((m.opts.icon.html.match(/>(\d+)<\/div>/) || [])[1]);
  const members = clusters.find(c => c.length === n);
  m.handlers.click();
  ok(m.removed === true, `clicking a ${n}-point cluster removes its numbered marker`);
  ok(rec.circles.length - before === n, `...and adds its ${n} individual pins`);
  ok(rec.bounds[rec.bounds.length - 1][1].maxZoom === 15, '...and zooms to them (maxZoom 15)');
}
print(fails ? `\n${fails} FAILED` : '\nmap script: all checks passed'); std.exit(fails ? 1 : 0);
