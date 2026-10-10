// A deliberately small fake DOM — just enough surface for the gallery's inline scripts.
export const esc = (s) => String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
export class El {
  constructor(tag, id) { this.tagName=(tag||'div').toUpperCase(); this.id=id||''; this.attrs={}; this.children=[]; this.parent=null; this._l={}; this._text=''; this._html=null; this.style={}; this.dataset={}; this._cls=new Set(); this.hidden=false; this.src=''; this.href=''; this.hash='';
    const s=this._cls; this.classList={add:(...c)=>c.forEach(x=>s.add(x)),remove:(...c)=>c.forEach(x=>s.delete(x)),contains:(c)=>s.has(c),toggle:(c)=>{s.has(c)?s.delete(c):s.add(c)}}; }
  get className(){return [...this._cls].join(' ')} set className(v){this._cls=new Set(String(v).split(/\s+/).filter(Boolean)); const s=this._cls; this.classList.add=(...c)=>c.forEach(x=>s.add(x)); this.classList.remove=(...c)=>c.forEach(x=>s.delete(x)); this.classList.contains=(c)=>s.has(c);}
  get textContent(){ return this.children.length ? this.children.map(c=>c.textContent).join('') : this._text } set textContent(v){this._text=String(v); this.children=[]; this._html=null}
  get innerHTML(){ return this._html!==null ? this._html : esc(this.textContent) } set innerHTML(v){this._html=String(v); this._text=''; this.children=[]}
  addEventListener(t,f){(this._l[t]=this._l[t]||[]).push(f)}
  fire(t,ev){ev=ev||{}; ev.target=ev.target||this; ev.stopPropagation=ev.stopPropagation||(()=>{}); ev.preventDefault=ev.preventDefault||(()=>{}); (this._l[t]||[]).forEach(f=>f(ev))}
  appendChild(c){c.parent=this; this.children.push(c); return c}
  remove(){ if(this.parent) this.parent.children=this.parent.children.filter(x=>x!==this); this.parent=null }
  _all(){ return this.children.flatMap(c=>[c,...c._all()]) }
  _m(sel){ if(sel[0]==='.') return this._cls.has(sel.slice(1)); return this.tagName===sel.toUpperCase() }
  querySelector(sel){ return this._all().find(c=>c._m(sel))||null } querySelectorAll(sel){ return this._all().filter(c=>c._m(sel)) }
  closest(sel){ let n=this; while(n){ if(n._m(sel)) return n; n=n.parent } return null }
  getAttribute(k){ return k==='title'?this.attrs.title??null : (this.attrs[k]??null) } setAttribute(k,v){this.attrs[k]=String(v)} removeAttribute(k){delete this.attrs[k]} hasAttribute(k){return k in this.attrs}
  focus(){} play(){} pause(){this.paused=true} load(){this.loaded=true} contains(x){return x===this||this._all().includes(x)}
}
export function makeDoc(html) {
  const byId = {}; for (const m of html.matchAll(/<(\w+)[^>]*\sid="([^"]+)"/g)) byId[m[2]] = new El(m[1], m[2]);
  const body = new El('body'); const l = {};
  return { byId, body, documentElement: new El('html'), activeElement: body, readyState:'complete',
    getElementById: (id) => byId[id] || null, createElement: (t) => new El(t),
    addEventListener: (t,f)=>{(l[t]=l[t]||[]).push(f)}, fire: (t,ev)=>{ev=ev||{}; ev.target=ev.target||body; ev.stopPropagation=ev.stopPropagation||(()=>{}); ev.preventDefault=ev.preventDefault||(()=>{}); (l[t]||[]).forEach(f=>f(ev))},
    querySelectorAll: (sel)=>[], querySelector:()=>null, _l:l };
}
