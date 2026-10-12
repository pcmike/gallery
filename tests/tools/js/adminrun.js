import * as std from 'std';
import { El, makeDoc } from './dom.js';
const src = std.loadFile(scriptArgs[1]); let fails = 0; const ok = (c, m) => { print((c ? '  ok    ' : '  FAIL  ') + m); if (!c) fails++; };
function run(notes) {
  const doc = makeDoc('');
  const tas = []; const wrap = new El('div'); wrap._cls.add('md-file');
  for (let i = 0; i < notes; i++) { const ta = new El('textarea'); ta._cls.add('adm-textarea'); ta.value = 'Hello world'; ta.selectionStart = 5; ta.selectionEnd = 5; ta.focus = () => { ta.focused = true; }; tas.push(ta); wrap.appendChild(ta); }
  const btn = new El('button'); btn._cls.add('admin-insert-btn'); btn.setAttribute('data-snippet', '{sold}');
  const hint = new El('p'); hint.hidden = true;
  doc.querySelectorAll = (s) => s === '.adm-textarea' ? tas : s === '.admin-insert-btn' ? [btn] : [];
  doc.querySelector = (s) => s === '.insert-hint' ? hint : null;
  new Function('document', 'window', 'confirm', 'navigator', src + '\n;return {};')(doc, { matchMedia: () => ({ matches: false }) }, () => true, { maxTouchPoints: 0 });
  return { btn, tas, hint };
}
let r = run(1);
r.btn.fire('click');
ok(r.tas[0].value === 'Hello{sold} world' && r.tas[0].selectionStart === 11 && r.tas[0].focused === true, 'with one note, an insert button puts its directive at the cursor and moves the cursor after it');
r.tas[0].selectionStart = 0; r.tas[0].selectionEnd = 5; r.btn.fire('click');
ok(r.tas[0].value === '{sold}{sold} world', 'with text selected, the insert replaces the selection');
r = run(1);
r.tas[0].value = '{gallery}\nHello'; r.tas[0].selectionStart = 0; r.tas[0].selectionEnd = 0; r.btn.fire('click');
ok(r.tas[0].value === '{gallery}\nHello {sold}', 'a cursor in (or at the start of) the {gallery} line never makes the insert land inside it: the directive goes at the end');
r = run(1);
r.tas[0].value = '{gallery}\nHello\n'; r.tas[0].selectionStart = 3; r.tas[0].selectionEnd = 3; r.btn.fire('click');
ok(r.tas[0].value === '{gallery}\nHello\n{sold}', '...with no stray space when the text already ends with a newline');
r = run(2);
r.btn.fire('click');
ok(r.tas[0].value === 'Hello world' && r.tas[1].value === 'Hello world' && r.hint.hidden === false, 'with several notes and none clicked in yet, nothing is inserted and it says to click in one first');
r.tas[1].fire('focus'); r.btn.fire('click');
ok(r.tas[1].value === 'Hello{sold} world' && r.tas[0].value === 'Hello world' && r.hint.hidden === true, 'after clicking in the second note, the directive goes into that note, not the other, and the hint goes away');
const form = new El('form'); const nn = new El('input'); nn._cls.add('adm-newname'); nn.value = '  copy.md '; form.appendChild(nn);
const asked = []; const g = new Function('document', 'window', 'confirm', 'navigator', src + '\n;return admConfirmSaveAs;')(makeDoc(''), { matchMedia: () => ({ matches: false }) }, (m) => { asked.push(m); return false; }, { maxTouchPoints: 0 });
ok(g(form) === false && asked[0] === 'Save as "copy.md"? If that file already exists, it will be overwritten.', 'Save As asks for confirmation naming the trimmed file, and a "no" cancels the submit');
nn.value = '   '; ok(g(form) === true && asked.length === 1, 'a blank new-name field (plain Save) never prompts');
print(fails ? `\n${fails} FAILED` : '\nadmin script: all checks passed'); std.exit(fails ? 1 : 0);
