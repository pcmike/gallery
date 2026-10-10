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
  src + '\n;return { files, captions, openLightbox, closeLightbox, navigate, toggleCaptionPopover, isVideoFile, applyViewUpdate: typeof applyViewUpdate==="function"?applyViewUpdate:null };')(doc, win, nav, loc, { replaceState() {} }, fetchStub, (f) => timers.push(f));
const { files, captions } = api; const N = files.length;
const lb = doc.byId['lightbox'], img = doc.byId['lightbox-img'], vid = doc.byId['lightbox-video'], cap = doc.byId['lightbox-caption'];
print(`--- gallery script: ${N} files on the page`);
ok(!!lb && !!img && !!vid && !!cap, 'the lightbox elements the script needs all exist in the page HTML');
// lightbox: opens on a photo
api.openLightbox(0);
ok(lb.classList.contains('open'), 'openLightbox adds the "open" class');
ok(img.src === '?img=' + encodeURIComponent(files[0]) + '&size=full', 'a photo loads its full-size image URL');
ok(vid.src === '' && vid.style.display === 'none', '...and the <video> is hidden and emptied');
ok(cap.textContent === '1 / ' + N, 'a caption-less photo shows only "1 / N" — never its filename');
ok(!cap.textContent.includes('.jpg'), '...(no filename anywhere in the caption bar)');
ok(fetched.length === 1 && fetched[0] === '?track=' + encodeURIComponent(files[0]), 'opening it pings ?track= once');
// a photo that has a caption
const ci = captions.findIndex(c => c); 
if (ci >= 0) { api.openLightbox(ci); ok(cap.textContent === captions[ci] + ' — ' + (ci + 1) + ' / ' + N, 'a captioned photo shows "caption — n / N" (textContent, so the <b> in it is inert text)'); ok(!cap.textContent.includes(files[ci]), '...and still not its filename'); }
// video
const vi = files.findIndex(f => api.isVideoFile(f));
if (vi >= 0) { api.openLightbox(vi); ok(vid.src === '?video=' + encodeURIComponent(files[vi]) && vid.style.display === '' && img.style.display === 'none' && vid.loaded === true, 'a video loads into the <video> element via ?video=, and the image is hidden'); api.navigate(1); ok(vid.paused === true || vid.src === '', 'navigating away from a video pauses it'); }
// navigation wraps both ways
api.openLightbox(0); api.navigate(-1); ok(img.src.includes(encodeURIComponent(files[N - 1])) || vid.src.includes(encodeURIComponent(files[N - 1])), 'navigate(-1) from the first item wraps to the last');
api.navigate(1); ok(cap.textContent.startsWith('1 / ') || cap.textContent.includes('1 / ' + N), 'navigate(+1) from the last wraps to the first');
// keyboard
doc.fire('keydown', { key: 'ArrowRight' }); ok(cap.textContent.includes('2 / ' + N), 'ArrowRight advances');
doc.fire('keydown', { key: 'ArrowLeft' }); ok(cap.textContent.includes('1 / ' + N), 'ArrowLeft goes back');
doc.activeElement = vid; doc.fire('keydown', { key: 'ArrowRight' }); ok(cap.textContent.includes('1 / ' + N), 'with the <video> focused, arrow keys are left to its own controls (no double action)');
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
