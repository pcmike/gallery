import * as std from 'std';
import { El, makeDoc } from './dom.js';
const src = std.loadFile(scriptArgs[1]); let fails = 0; const ok = (c, m) => { print((c ? '  ok    ' : '  FAIL  ') + m); if (!c) fails++; };
function run(phone) {
  const doc = makeDoc('');
  const det = new El('details'); det._cls.add('admin-reference'); det.setAttribute('open', '');
  const wrap = new El('div'); wrap._cls.add('md-file'); const ta = new El('textarea'); ta._cls.add('adm-textarea'); ta.value = 'Hello world'; ta.selectionStart = 5; ta.selectionEnd = 5; ta.focus = () => { ta.focused = true; };
  const btn = new El('button'); btn._cls.add('admin-insert-btn'); btn.setAttribute('data-snippet', '{sold}'); wrap.appendChild(ta); wrap.appendChild(btn);
  doc.querySelectorAll = (s) => s === '.admin-reference' ? [det] : s === '.admin-insert-btn' ? [btn] : [];
  const f = new Function('document', 'window', 'confirm', src + '\n;return { admConfirmSaveAs };')(doc, { matchMedia: () => ({ matches: phone }) }, (m) => { f.asked = m; return true; });
  return { det, btn, ta, f: (r => r)(f) };
}
let r = run(true);
ok(!r.det.hasAttribute('open'), 'on a phone-width screen the directive reference starts collapsed');
r = run(false);
ok(r.det.hasAttribute('open'), 'on a wider screen it stays expanded');
r.btn.fire('click');
ok(r.ta.value === 'Hello{sold} world' && r.ta.selectionStart === 11 && r.ta.focused === true, 'an insert button puts its directive at the cursor and moves the cursor after it');
r.ta.selectionStart = 0; r.ta.selectionEnd = 5; r.btn.fire('click');
ok(r.ta.value === '{sold}{sold} world', 'with text selected, the insert replaces the selection');
const form = new El('form'); const nn = new El('input'); nn._cls.add('adm-newname'); nn.value = '  copy.md '; form.appendChild(nn);
const asked = []; const g = new Function('document', 'window', 'confirm', src + '\n;return admConfirmSaveAs;')(makeDoc(''), { matchMedia: () => ({ matches: false }) }, (m) => { asked.push(m); return false; });
ok(g(form) === false && asked[0] === 'Save as "copy.md"? If that file already exists, it will be overwritten.', 'Save As asks for confirmation naming the trimmed file, and a "no" cancels the submit');
nn.value = '   '; ok(g(form) === true && asked.length === 1, 'a blank new-name field (plain Save) never prompts');
print(fails ? `\n${fails} FAILED` : '\nadmin script: all checks passed'); std.exit(fails ? 1 : 0);
