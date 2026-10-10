import * as std from 'std';
import { El, makeDoc } from './dom.js';
const html = std.loadFile(scriptArgs[1]); const src = std.loadFile(scriptArgs[2]);
let fails = 0; const ok = (c, m) => { print((c ? '  ok    ' : '  FAIL  ') + m); if (!c) fails++; };
const doc = makeDoc(html);
const fetched = []; const timers = [];
const win = { location: null, CSS: undefined, addEventListener() {}, matchMedia: () => ({ matches: false }) };
const nav = { clipboard: { writeText: (t) => { nav.copied = t; return Promise.resolve(); } } };
const loc = { origin: 'http://example.test', pathname: '/kb/', hash: '', search: '' }; win.location = loc;
const fetchStub = (u) => { fetched.push(u); return Promise.resolve({ ok: true, json: () => Promise.resolve({ ok: true, photo: decodeURIComponent(String(u).split('=')[1]), photoViews: 5, groupSlug: 'x', groupViews: 9 }) }); };
const api = new Function('document', 'window', 'navigator', 'location', 'history', 'fetch', 'setTimeout',
  src + '\n;return { files, captions, fileVersions, locations, webLinks, openLightbox, closeLightbox, navigate, toggleCaptionPopover, isVideoFile, applyViewUpdate: typeof applyViewUpdate==="function"?applyViewUpdate:null };')(doc, win, nav, loc, { replaceState() {} }, fetchStub, (f) => timers.push(f));
const { files, captions, fileVersions, locations, webLinks } = api; const N = files.length;
const dirOf = (f) => f.includes('/') ? f.slice(0, f.lastIndexOf('/')) : '';
const sameDir = (i) => files.map((f, k) => k).filter(k => dirOf(files[k]) === dirOf(files[i]));
const tag = (i) => (fileVersions[i] ? '&v=' + fileVersions[i] : '');
const lb = doc.byId['lightbox'], img = doc.byId['lightbox-img'], vid = doc.byId['lightbox-video'], cap = doc.byId['lightbox-caption'];
print(`--- gallery script: ${N} files on the page`);
ok(!!lb && !!img && !!vid && !!cap, 'the lightbox elements the script needs all exist in the page HTML');
// lightbox: opens on a photo
api.openLightbox(0);
ok(lb.classList.contains('open'), 'openLightbox adds the "open" class');
ok(fileVersions.length === N && /^[0-9a-f]{10}$/.test(fileVersions[0]), 'the page gives the script a 10-character cache-busting tag for every file');
ok(img.src === '?img=' + encodeURIComponent(files[0]) + '&size=full' + tag(0), 'a photo loads its full-size image URL, with its cache-busting tag');
ok(vid.src === '' && vid.style.display === 'none', '...and the <video> is hidden and emptied');
const B0 = files.findIndex((f, i) => sameDir(i).length >= 2), M0 = sameDir(B0).length;
ok(M0 >= 2, '(setup) the first photo has company in its own folder, so the folder checks below mean something');
ok(cap.textContent === '1 / ' + sameDir(0).length, 'a caption-less photo shows only "1 / n" (n = the photos in its folder) — never its filename');
ok(!cap.textContent.includes('.jpg'), '...(no filename anywhere in the caption bar)');
ok(fetched.length === 1 && fetched[0] === '?track=' + encodeURIComponent(files[0]), 'opening it pings ?track= once');
// a photo that has a caption
const ci = captions.findIndex(c => c); 
if (ci >= 0) { api.openLightbox(ci); ok(cap.textContent === captions[ci] + ' — ' + (sameDir(ci).indexOf(ci) + 1) + ' / ' + sameDir(ci).length, 'a captioned photo shows "caption — n / N" (textContent, so the <b> in it is inert text)'); ok(!cap.textContent.includes(files[ci]), '...and still not its filename'); }
// video
const vi = files.findIndex(f => api.isVideoFile(f));
if (vi >= 0) { api.openLightbox(vi); ok(vid.src === '?video=' + encodeURIComponent(files[vi]) + tag(vi) && vid.style.display === '' && img.style.display === 'none' && vid.loaded === true, 'a video loads into the <video> element via ?video=, and the image is hidden'); api.navigate(1); ok(vid.paused === true || vid.src === '', 'navigating away from a video pauses it'); }
// navigation wraps both ways
const lastOfFirst = files[sameDir(B0).slice(-1)[0]];
api.openLightbox(sameDir(B0)[0]); api.navigate(-1); ok(img.src.includes(encodeURIComponent(lastOfFirst)) || vid.src.includes(encodeURIComponent(lastOfFirst)), 'navigate(-1) from the first item of a folder wraps to the last item of that folder');
api.navigate(1); ok(cap.textContent.startsWith('1 / ' + M0), 'navigate(+1) from the last wraps to the first of the same folder');
let crossed = 0; for (let i = 0; i < files.length; i++) { const d = dirOf(files[i]); api.openLightbox(i); for (let k = 0; k < files.length + 2; k++) { api.navigate(1); const shown = decodeURIComponent((img.src.split('img=')[1] || vid.src.split('video=')[1] || '').split('&')[0]); if (dirOf(shown) !== d) crossed++; } }
ok(crossed === 0, 'from every photo, going forward any number of times never leaves its folder');
api.openLightbox(sameDir(B0)[0]);
// the little map and the web link in the viewer
const li = locations.findIndex(l => l), nl = locations.findIndex(l => !l), wi = webLinks.findIndex(w => w);
const mapBox = doc.byId['lightbox-map'], mapBtn = doc.byId['lightbox-map-toggle'], webEl = doc.byId['lightbox-web'];
ok(!!mapBox && !!mapBtn && !!webEl, 'the viewer has its little-map box, its map button and its web-link icon');
if (li >= 0 && mapBox) {
  api.openLightbox(li);
  ok(mapBox.hidden === false && mapBtn.hidden === false, 'a photo with a public location shows the little map and its button');
  ok(doc.head.children.some(c => c.tagName === 'SCRIPT' && String(c.src).includes('leaflet')), '...and only now is the map library requested (nothing was fetched before)');
  api.openLightbox(nl); ok(mapBox.hidden === true && mapBtn.hidden === true, 'a photo without one shows neither');
}
if (wi >= 0) { api.openLightbox(wi); ok(webEl.hidden === false && webEl.href === webLinks[wi] && webEl.title === 'Information link: ' + webLinks[wi], 'a photo with a web link shows it beside the permalink icon'); api.openLightbox(webLinks.findIndex(w => !w)); ok(webEl.hidden === true, '...and one without shows no link'); }
api.openLightbox(sameDir(B0)[0]);
// keyboard
doc.fire('keydown', { key: 'ArrowRight' }); ok(cap.textContent.includes('2 / ' + M0), 'ArrowRight advances');
doc.fire('keydown', { key: 'ArrowLeft' }); ok(cap.textContent.includes('1 / ' + M0), 'ArrowLeft goes back');
doc.activeElement = vid; doc.fire('keydown', { key: 'ArrowRight' }); ok(cap.textContent.includes('1 / ' + M0), 'with the <video> focused, arrow keys are left to its own controls (no double action)');
doc.activeElement = doc.body;
doc.fire('keydown', { key: 'Escape' }); ok(!lb.classList.contains('open'), 'Escape closes the lightbox');
const afterClose = fetched.length; doc.fire('keydown', { key: 'ArrowRight' }); ok(fetched.length === afterClose, 'keys do nothing while the lightbox is closed');
// view-count update
const badge = new El('span'); badge._cls.add('view-badge'); badge.classList.add('hidden');
const seen = []; const popRoots = []; doc.querySelectorAll = (sel) => { seen.push(sel); if (sel.startsWith('.view-badge[data-photo=')) return [badge]; if (sel === '.caption-popover') return popRoots.flatMap(r => r._all().filter(c => c._cls.has('caption-popover'))); return []; };
api.openLightbox(0); // triggers trackView -> fetch -> applyViewUpdate (async)
for (let i = 0; i < 6; i++) await Promise.resolve();
ok(badge.textContent.includes('5') && !badge.classList.contains('hidden'), 'a ?track= response updates the on-page view badge and un-hides it');
ok(seen.some(s => s.includes('.view-badge[data-photo="')), '...found by an attribute selector built with the photo name');
// caption popover
const card = new El('div'); card._cls.add('card'); popRoots.push(card); const cbadge = new El('span'); cbadge._cls.add('caption-badge'); cbadge.setAttribute('title', 'He said "hi" & <b>left</b>'); card.appendChild(cbadge);
let stopped = 0, prevented = 0; const ev = { stopPropagation() { stopped++; }, preventDefault() { prevented++; } };
api.toggleCaptionPopover(cbadge, ev);
let pop = card.querySelector('.caption-popover');
ok(!!pop && pop.textContent === 'He said "hi" & <b>left</b>', 'tapping the caption badge shows a popover holding the caption verbatim');
ok(pop && pop.innerHTML === 'He said "hi" &amp; &lt;b&gt;left&lt;/b&gt;', '...as inert text (set via textContent, so the <b> is escaped, not parsed)');
ok(stopped === 1 && prevented === 1, '...and the tap is stopped from also opening the lightbox');
api.toggleCaptionPopover(cbadge, ev); ok(card.querySelector('.caption-popover') === null, 'tapping the badge again closes the popover');
api.toggleCaptionPopover(cbadge, ev); doc.fire('click', {}); ok(card.querySelector('.caption-popover') === null || card.querySelector('.caption-popover').parent === null, 'tapping anywhere else dismisses it');
// copy-link buttons
const link = new El('a'); link._cls.add('permalink-copy'); link.hash = '#group-x'; link.closest = (s) => s === '.permalink-copy' ? link : null;
doc.fire('click', { target: link }); await Promise.resolve(); ok(nav.copied === 'http://example.test/kb/#group-x', 'a copy-link button copies origin + path + #anchor (not the whole current URL with a query)');
const flink = new El('a'); flink._cls.add('permalink-copy'); flink.hash = ''; flink.setAttribute('href', '?folder=Europe%2FFrance'); flink.closest = (s) => s === '.permalink-copy' ? flink : null;
doc.fire('click', { target: flink }); await Promise.resolve(); ok(nav.copied === 'http://example.test/kb/?folder=Europe%2FFrance', 'a folder cover card\'s copy-link button copies its ?folder= URL');
print(fails ? `\n${fails} FAILED` : '\ngallery script: all checks passed'); std.exit(fails ? 1 : 0);
