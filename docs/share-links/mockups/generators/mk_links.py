import json,os
S=os.environ['S']; P=S+'/mock/project/'
I=lambda d:'<svg class="i" viewBox="0 0 24 24" aria-hidden="true">%s</svg>'%d
DOWN=I('<path d="M5.5 8.75 12 15.25l6.5-6.5"/>'); UP=I('<path d="M5.5 15.25 12 8.75l6.5 6.5"/>')
COPY=I('<rect x="8.5" y="8.5" width="11" height="11" rx="2"/><path d="M15.5 8.5V6.5a2 2 0 0 0-2-2h-7a2 2 0 0 0-2 2v7a2 2 0 0 0 2 2h2"/>')
PLUS=I('<path d="M12 5v14M5 12h14"/>')
CHECK=I('<path d="M4.5 12.5 9.5 17.5 19.5 7"/>')
WARN=I('<path d="M12 4 3.5 19h17zM12 10v4M12 16.8v.2"/>')
LINKI=I('<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>')
TRASH=I('<path d="M5 7h14M10 7V5h4v2M7 7l1 12h8l1-12M10.5 11v5M13.5 11v5"/>')
CSS='''
body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
.i{width:1em;height:1em;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;flex:none}
.pg{background:#0f0f10;color:#eaeaea;box-sizing:border-box}
.pn{background:#1a1a1c;border:1px solid #26262a;border-radius:12px;padding:16px 18px;margin-bottom:14px}
.ok-b{border-radius:10px;padding:11px 14px;font-size:14px;display:flex;gap:10px;align-items:center;margin-bottom:14px}
.ok-b.good{background:rgba(59,165,92,0.12);border:1px solid rgba(59,165,92,0.45)}
.ok-b.good .i{color:#3ba55c}
.ok-b.amber{background:rgba(224,160,40,0.12);border:1px solid rgba(224,160,40,0.5)}
.ok-b.amber .i{color:#e0a028}
.ok-b .i{font-size:18px}
.lkx{color:#aab2ff;margin-left:auto;font-size:13.5px;white-space:nowrap}
h2{font-size:16px;margin:0}
.mut{color:#8a8a8f;font-size:13px}
.hd{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.sp{flex:1}
.btn{display:inline-flex;align-items:center;gap:6px;height:34px;padding:0 14px;border-radius:8px;border:1px solid #34343a;background:#26262a;color:#eaeaea;font-size:13.5px;font-weight:600;box-sizing:border-box;white-space:nowrap}
.btn.pri{background:#5865f2;border-color:#5865f2;color:#fff}
.btn.sm{height:30px;padding:0 11px;font-size:13px}
.btn.dng{background:#d83c3e;border-color:#d83c3e;color:#fff}
.tx{color:#aab2ff;font-size:13.5px;font-weight:600}
.tx.red{color:#ff8a8c}
.grp{font-size:11.5px;letter-spacing:.06em;text-transform:uppercase;color:#8a8a8f;margin:18px 0 4px}
.lr{border-top:1px solid #26262a;padding:12px 2px}
.lr.first{border-top:0}
.lh{display:flex;align-items:center;gap:10px}
.ln{font-size:15px;font-weight:600}
.lu{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12.5px;color:#8a8a8f}
.ls{font-size:13px;color:#b9b9c0;margin:5px 0 0 0}
.ls b{color:#fff;font-weight:600}
.cc{color:#8a8a8f;font-size:18px;display:flex}
.st{display:inline-flex;align-items:center;height:22px;padding:0 9px;border-radius:999px;font-size:12px;box-sizing:border-box;white-space:nowrap}
.st.on{background:rgba(59,165,92,0.14);color:#6fcf8d;border:1px solid rgba(59,165,92,0.4)}
.st.end{background:#26262a;color:#a0a0a8;border:1px solid #34343a}
.st.rev{border:1px solid rgba(255,120,122,0.5);color:#ff9a9c}
.bx{margin:12px 0 4px;padding:14px 16px;background:#141416;border:1px solid #26262a;border-radius:10px}
.kv{display:grid;grid-template-columns:130px 1fr;gap:6px 14px;font-size:13.5px;margin-bottom:4px}
.kv span:nth-child(odd){color:#8a8a8f}
.full{display:flex;gap:8px;align-items:center;margin-bottom:12px}
.fld{flex:1;min-width:0;height:34px;border:1px solid #34343a;background:#0f0f10;border-radius:8px;padding:0 11px;display:flex;align-items:center;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:13px;color:#d9d9de;overflow:hidden;white-space:nowrap}
.vh,.vr{display:grid;grid-template-columns:1.25fr .45fr 1.3fr 1.15fr 1.15fr .5fr .55fr;gap:10px;align-items:center;font-size:13px}
.vh{color:#8a8a8f;font-size:11.5px;letter-spacing:.05em;text-transform:uppercase;padding:0 0 6px;border-bottom:1px solid #26262a}
.vr{padding:9px 0;border-bottom:1px solid #1f1f22}
.vr:last-child{border-bottom:0}
.ip{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12.5px}
.sub{font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#8a8a8f;margin:14px 0 6px}
.sub.warn{color:#e0a028}
.vr.rj .why{color:#e0a028}
.acts{display:flex;gap:16px;align-items:center;margin-top:14px;flex-wrap:wrap}
.note{font-size:12.5px;color:#8a8a8f;margin-top:10px;line-height:1.45}
.vm{padding:10px 0;border-bottom:1px solid #1f1f22;font-size:13px}
.vm:last-child{border-bottom:0}
.vm .t{display:flex;gap:8px;align-items:center}
.vm .s{color:#8a8a8f;font-size:12.5px;margin-top:3px}
.vm.rj .s{color:#e0a028}
.seg{display:inline-flex;border:1px solid #34343a;border-radius:999px;overflow:hidden;flex-wrap:wrap}
.seg span{padding:7px 14px;font-size:13px;color:#b9b9c0}
.seg span+span{border-left:1px solid #34343a}
.seg .on{background:#5865f2;color:#fff}
.fl{margin:0 0 16px}
.fl>.k{font-size:13.5px;font-weight:600;margin-bottom:6px}
.fl>.h{font-size:12.5px;color:#8a8a8f;margin-top:6px;line-height:1.4}
.inp{height:36px;border:1px solid #34343a;background:#0f0f10;border-radius:8px;padding:0 12px;display:flex;align-items:center;font-size:14px;box-sizing:border-box;color:#eaeaea}
.rad{display:flex;gap:10px;align-items:flex-start;font-size:14px;margin:6px 0}
.rd{width:16px;height:16px;border-radius:50%;border:1.5px solid #6a6a72;box-sizing:border-box;margin-top:2px;flex:none}
.rd.on{border-color:#5865f2;background:radial-gradient(#5865f2 0 4px,transparent 4.5px)}
.chip{display:inline-flex;align-items:center;gap:6px;height:26px;padding:0 10px;border-radius:999px;background:#26262a;border:1px solid #34343a;font-size:12.5px;margin:4px 6px 0 0}
.tg{width:38px;height:22px;border-radius:999px;background:#5865f2;position:relative;flex:none}
.tg::after{content:"";position:absolute;right:3px;top:3px;width:16px;height:16px;border-radius:50%;background:#fff}
.tg.off{background:#3a3a40}
.tg.off::after{right:auto;left:3px}
.srow{display:flex;gap:14px;align-items:flex-start;padding:12px 0;border-top:1px solid #26262a}
.srow:first-of-type{border-top:0}
.srow .nm{font-size:14.5px;font-weight:600}
.srow .hp{font-size:12.5px;color:#8a8a8f;margin-top:3px;line-height:1.45}
.ov{background:rgba(0,0,0,0.62);display:flex;align-items:center;justify-content:center}
.dlg{background:#1f1f22;border:1px solid #34343a;border-radius:14px;padding:20px 22px;width:440px;box-sizing:border-box}
.dlg h3{margin:0 0 8px;font-size:17px}
.dlg p{font-size:14px;color:#c9c9ce;line-height:1.5;margin:0 0 10px}
.dlg .bt{display:flex;gap:10px;justify-content:flex-end;margin-top:16px}
.ph{background:#000;border-radius:28px;border:1px solid #26262a;box-sizing:border-box;padding:48px 24px 24px;display:flex;flex-direction:column;align-items:center;text-align:center}
.ph h1{font-size:22px;margin:18px 0 8px}
.ph p{font-size:15px;color:#b9b9c0;line-height:1.5;margin:0 0 20px}
.ph .big{width:100%;height:50px;border-radius:12px;background:#5865f2;color:#fff;font-size:16px;font-weight:600;display:flex;align-items:center;justify-content:center}
.ph .ico{width:64px;height:64px;border-radius:50%;background:#1a1a1c;display:flex;align-items:center;justify-content:center;font-size:30px;color:#aab2ff}
.ph .small{font-size:12.5px;color:#6f6f77;margin-top:16px}
.lab{font-size:12.5px;color:#8a8a8f;margin:0 0 6px}
'''
SEP='<span style="color:#55555c;margin:0 6px">&middot;</span>'
def head(title,sub,btn=True):
    return '<div class="hd"><div><h2>%s</h2><div class="mut" style="margin-top:3px">%s</div></div><span class="sp"></span>%s</div>'%(title,sub,('<a class="btn pri">%s New link</a>'%PLUS) if btn else '')
def netcheck():
    return '<div class="ok-b good">%s<div><b>Short links work.</b> <span style="font-family:ui-monospace,Menlo,monospace;font-size:12.5px">/trip/k7Qp&hellip;</span> reaches the gallery (checked just now).</div><span class="lkx">Show the rule</span></div>'%CHECK
def vtable(rows):
    h='<div class="vh"><span>IP address</span><span>Where</span><span>Browser</span><span>First seen</span><span>Last seen</span><span>Opens</span><span>Views</span></div>'
    return h+''.join('<div class="vr"><span class="ip">%s</span><span>%s</span><span>%s</span><span>%s</span><span>%s</span><span>%s</span><span>%s</span></div>'%r for r in rows)
def rtable(rows):
    h='<div class="vh" style="grid-template-columns:1.25fr .45fr 1.3fr 1.15fr 1.15fr 1.05fr"><span>IP address</span><span>Where</span><span>Browser</span><span>First tried</span><span>Last tried</span><span>Tries</span></div>'
    return h+''.join('<div class="vr rj" style="grid-template-columns:1.25fr .45fr 1.3fr 1.15fr 1.15fr 1.05fr"><span class="ip">%s</span><span>%s</span><span>%s</span><span>%s</span><span>%s</span><span class="why">%s</span></div>'%r for r in rows)
def vcards(rows):
    return ''.join('<div class="vm"><div class="t"><span class="ip">%s</span><span class="mut">%s</span></div><div class="s">%s<br>%s</div></div>'%r for r in rows)
FULL='<div class="full"><div class="fld">https://pcmike.net/trip/k7QpR2mXv9LwTb4e</div><a class="btn sm">%s Copy</a></div>'%COPY
# ---- Board 1: list, Aunt Carol expanded
vis1=[('203.0.113.42','US','Safari on iPhone','Oct 19, 9:14 am','Oct 19, 9:52 am','1','14'),
      ('198.51.100.7','US','Safari on Mac','Oct 20, 7:30 pm','Oct 21, 8:02 pm','1','6')]
vis1=[('203.0.113.42','US','Safari on iPhone','Oct 19, 9:14 am','Today, 9:52 am','1','14'),
      ('198.51.100.7','US','Safari on Mac','Oct 19, 7:30 am','Oct 19, 8:02 am','1','6')]
exp1='''<div class="bx">%s<div class="kv"><span>Who can see</span><span>Everything private</span><span>Downloads</span><span>Originals and the ZIP allowed</span><span>Created</span><span>Oct 18, 4:05 pm</span><span>Ends</span><span>Sun, Oct 25, 6:00 pm &middot; 6 days left</span></div>
<div class="sub">Visitors &middot; 2 IP addresses</div>%s
<div class="acts"><a class="tx">Edit&hellip;</a><a class="tx">Revoke</a><a class="tx red">Delete&hellip;</a><span class="sp"></span></div>
<div class="note">An IP address is a device on a network, not a person: a phone moving from wifi to cellular looks like two. Opens count the &ldquo;Open the gallery&rdquo; presses; views count pages looked at afterwards.</div></div>'''%(FULL,vtable(vis1))
def lr(st,stc,name,url,sub,body='',first=False,open_=False,copy=True):
    return '<div class="lr%s"><div class="lh"><span class="st %s">%s</span><span class="ln">%s</span><span class="lu">%s</span><span class="sp"></span>%s<span class="cc">%s</span></div><div class="ls">%s</div>%s</div>'%(' first' if first else '',stc,st,name,url,('<a class="btn sm">%s Copy link</a>'%COPY) if copy else '',UP if open_ else DOWN,sub,body)
list1=(lr('Active','on','Aunt Carol','/trip/k7Qp&hellip;Tb4e','<b>2 of 5</b> opens'+SEP+'<b>2</b> IPs'+SEP+'20 page views'+SEP+'ends Sun, Oct 25, 6:00 pm',exp1,True,True)
 +lr('Active','on','Mark &amp; Jen','/trip/Zx83&hellip;9aQc','<b>7</b> opens, no limit'+SEP+'<b>4</b> IPs'+SEP+'211 page views'+SEP+'no end date')
 )
ended=(lr('Used up','end','Photographer','/trip/Hn5d&hellip;Wy2k','<b>1 of 1</b> opens'+SEP+'<b>1</b> IP'+SEP+'<b style="color:#e0a028">2 blocked tries</b>'+SEP+'used up Oct 12',first=True)
 +lr('Expired','end','Dave (work friend)','/trip/Qe7m&hellip;Lp0s','<b>3 of 10</b> opens'+SEP+'<b>2</b> IPs'+SEP+'ended Sun, Oct 12, 6:00 pm')
 +lr('Revoked','rev','Old link','/trip/Bt1v&hellip;Rj6n','<b>4</b> opens'+SEP+'<b>2</b> IPs'+SEP+'revoked Oct 9'))
b1='<div class="pg" style="width:960px;padding:22px 24px">'+netcheck()+'<div class="pn">'+head('Share links','2 active &middot; 3 ended')+'<div class="grp" style="margin-top:14px">Active</div>'+list1+'<div class="grp">Ended <span style="text-transform:none;letter-spacing:0">&middot; the visitor log is kept until you delete the link</span></div>'+ended+'</div></div>'
# ---- Board 2: ended link expanded + delete dialog
vis2=[('192.0.2.15','US','Safari on iPhone','Oct 12, 10:03 am','Oct 12, 3:40 pm','1','38')]
rj2=[('198.51.100.77','GB','Chrome on Windows','Oct 12, 8:41 pm','Oct 13, 7:12 am','2 tries &middot; already used')]
exp2='''<div class="bx">%s<div class="kv"><span>Who can see</span><span>Everything private</span><span>Opens</span><span>1 of 1 &middot; used up Oct 12, 10:03 am</span></div>
<div class="sub">Visitors &middot; 1 IP address</div>%s
<div class="sub warn">Blocked tries &middot; 1 IP address</div>%s
<div class="acts"><a class="tx">Make a new link like this</a><a class="tx">Add 1 more open&hellip;</a><a class="tx red">Delete&hellip;</a></div>
<div class="note">This link no longer lets anyone in, but its log stays here. Deleting the link also deletes its log.</div></div>'''%(FULL.replace('k7QpR2mXv9LwTb4e','Hn5dKc3aPz8YrWy2k'),vtable(vis2),rtable(rj2))
list2=lr('Used up','end','Photographer','/trip/Hn5d&hellip;Wy2k','<b>1 of 1</b> opens'+SEP+'<b>1</b> IP'+SEP+'<b style="color:#e0a028">2 blocked tries</b>'+SEP+'used up Oct 12',exp2,True,True)+lr('Expired','end','Dave (work friend)','/trip/Qe7m&hellip;Lp0s','<b>3 of 10</b> opens'+SEP+'<b>2</b> IPs'+SEP+'ended Sun, Oct 12, 6:00 pm')+lr('Revoked','rev','Old link','/trip/Bt1v&hellip;Rj6n','<b>4</b> opens'+SEP+'<b>2</b> IPs'+SEP+'revoked Oct 9')
dlg='''<div class="dlg"><h3>Delete this link?</h3><p>&ldquo;Photographer&rdquo; stops working for good, and its log is deleted with it: <b>1 visitor</b> and <b>2 blocked tries</b>.</p><p style="color:#8a8a8f;font-size:13px">It already doesn&rsquo;t let anyone in. If you only want to keep it out of your way, leave it here: ended links keep their log until you delete them.</p><div class="bt"><a class="btn">Cancel</a><a class="btn dng">%s Delete link and log</a></div></div>'''%TRASH
b2='<div style="position:relative;width:960px"><div class="pg" style="width:960px;padding:22px 24px"><div class="pn">'+head('Share links','2 active &middot; 3 ended')+'<div class="grp" style="margin-top:14px">Ended</div>'+list2+'</div></div><div class="ov" style="position:absolute;left:0;top:0;right:0;bottom:0;border-radius:0;align-items:flex-end;padding-bottom:36px">'+dlg+'</div></div>'
# ---- Board 3: new link form
form='''<div class="pn" style="max-width:560px;margin:0 auto 14px"><h2 style="margin-bottom:14px">New share link</h2>
<div class="fl"><div class="k">Who is it for?</div><div class="inp">Aunt Carol</div><div class="h">Only you see this name. It helps you recognise the link later.</div></div>
<div class="fl"><div class="k">What can they see?</div>
<div class="rad"><span class="rd on"></span><span>Everything private<div class="h" style="margin:2px 0 0">The same as someone with the private-view password.</div></span></div>
<div class="rad"><span class="rd"></span><span>Only some folders<div class="h" style="margin:2px 0 0"><span class="chip">Eurotrip 2026 / Rome &times;</span><span class="chip">Eurotrip 2026 / Pisa &times;</span><span class="chip" style="color:#aab2ff">+ Add folder</span></div></span></div></div>
<div class="fl"><div class="k">How many times can it be opened?</div><div class="seg"><span>Once</span><span>5</span><span class="on">10</span><span>Unlimited</span><span>Other&hellip;</span></div><div class="h">Counted per browser, not per page. Once someone is in, they can keep looking until the link ends.</div></div>
<div class="fl"><div class="k">When does it stop working?</div><div class="seg"><span>1 day</span><span>1 week</span><span>30 days</span><span class="on">Pick a date&hellip;</span><span>Never</span></div>
<div class="inp" style="margin-top:8px;width:290px">Sun, Oct 25, 2026 &nbsp;at&nbsp; 6:00 pm</div><div class="h">In your browser&rsquo;s time zone. People already inside are signed out at that moment.</div></div>
<div class="fl"><div class="rad" style="margin:0"><span class="tg"></span><span>Allow downloading originals and the ZIP</span></div></div>
<div class="acts" style="margin-top:6px"><a class="btn pri">Create link</a><a class="btn">Cancel</a></div></div>
<div class="lab" style="max-width:560px;margin:0 auto 6px">After pressing Create link</div>
<div class="pn" style="max-width:560px;margin:0 auto"><div class="hd" style="margin-bottom:10px"><span class="st on">Active</span><b>Aunt Carol</b></div>'''+FULL+'''<div class="note" style="margin-top:0">Ends Sun, Oct 25, 6:00 pm &middot; up to 10 opens. You can copy it again any time from the list.</div></div>'''
b3='<div class="pg" style="width:960px;padding:22px 24px">'+form+'</div>'
# ---- Board 4: phone
vp=[('203.0.113.42','US','Safari on iPhone &middot; 1 open &middot; 14 views','First Oct 19, 9:14 am','Last Today, 9:52 am'),
    ('198.51.100.7','US','Safari on Mac &middot; 1 open &middot; 6 views','First Oct 19, 7:30 am','Last Oct 19, 8:02 am')]
vp=[(a,b,c,d+'<br>'+e) if False else (a,b,c,d,e) for a,b,c,d,e in vp]
def vcards2(rows):
    return ''.join('<div class="vm"><div class="t"><span class="ip">%s</span><span class="mut">%s</span></div><div class="s">%s<br>%s &middot; %s</div></div>'%r for r in rows)
expP='''<div class="bx" style="padding:12px 12px"><div class="full"><div class="fld" style="font-size:12px">pcmike.net/trip/k7QpR2mXv9LwTb4e</div><a class="btn sm">%s</a></div>
<div class="kv" style="grid-template-columns:96px 1fr"><span>Who can see</span><span>Everything private</span><span>Downloads</span><span>Allowed</span><span>Ends</span><span>Sun, Oct 25, 6:00 pm</span></div>
<div class="sub">Visitors &middot; 2 IPs</div>%s
<div class="acts" style="gap:14px"><a class="tx">Edit&hellip;</a><a class="tx">Revoke</a><a class="tx red">Delete&hellip;</a></div></div>'''%(COPY,vcards2(vp))
def lrP(st,stc,name,url,sub,body='',first=False,open_=False):
    return '<div class="lr%s"><div class="lh"><span class="st %s">%s</span><span class="ln">%s</span><span class="sp"></span><span class="cc">%s</span></div><div class="lu" style="margin-top:3px">%s</div><div class="ls">%s</div>%s</div>'%(' first' if first else '',stc,st,name,UP if open_ else DOWN,url,sub,body)
listP=(lrP('Active','on','Aunt Carol','/trip/k7Qp&hellip;Tb4e','<b>2 of 5</b> opens'+SEP+'<b>2</b> IPs<br>ends Sun, Oct 25, 6:00 pm',expP,True,True)
 +lrP('Active','on','Mark &amp; Jen','/trip/Zx83&hellip;9aQc','<b>7</b> opens'+SEP+'<b>4</b> IPs<br>no end date'))
endedP=(lrP('Used up','end','Photographer','/trip/Hn5d&hellip;Wy2k','<b>1 of 1</b> opens'+SEP+'<b>1</b> IP'+SEP+'<b style="color:#e0a028">2 blocked</b>',first=True)
 +lrP('Expired','end','Dave (work friend)','/trip/Qe7m&hellip;Lp0s','<b>3 of 10</b> opens'+SEP+'<b>2</b> IPs')
 +lrP('Revoked','rev','Old link','/trip/Bt1v&hellip;Rj6n','<b>4</b> opens'+SEP+'<b>2</b> IPs'))
b4='<div class="pg" style="width:390px;padding:16px 12px"><div class="pn" style="padding:14px 12px">'+head('Share links','2 active &middot; 3 ended')+'<div class="grp" style="margin-top:14px">Active</div>'+listP+'<div class="grp">Ended</div><div class="mut" style="font-size:12px;margin-bottom:2px">The log is kept until you delete the link.</div>'+endedP+'</div></div>'
# ---- Board 5: what a visitor sees
v1='<div><div class="lab">1 &middot; Opening the link</div><div class="ph" style="width:340px;height:360px"><div class="ico">%s</div><h1>Eurotrip 2026</h1><p>You&rsquo;ve been invited to view private photos. This link works until Sunday, Oct 25.</p><div class="big">Open the gallery</div></div></div>'%LINKI
v2='<div><div class="lab">2 &middot; Expired, used up, revoked, or mistyped (all look the same)</div><div class="ph" style="width:340px;height:360px"><div class="ico" style="color:#8a8a8f">%s</div><h1>This link isn&rsquo;t valid</h1><p>Please let the person who sent it to you know.</p></div></div>'%WARN
b5='<div class="pg" style="width:760px;padding:22px 24px;display:flex;gap:24px">'+v1+v2+'</div>'
# ---- Board 6: site settings
b6='''<div class="pg" style="width:960px;padding:22px 24px"><div class="pn"><h2>Share links</h2>
<div class="srow"><span class="tg"></span><div><div class="nm">Allow share links</div><div class="hp">Lets you make temporary links in the Share links tab. With this off, existing links stop working and nothing is deleted.</div></div></div>
<div class="srow"><div style="flex:1"><div class="nm">Short address</div><div class="hp">Links look like <span style="font-family:ui-monospace,Menlo,monospace">https://pcmike.net/trip/k7Qp&hellip;</span> when the server passes them to the gallery.</div>
<div class="ok-b good" style="margin:10px 0 0">%s<div><b>Works.</b> Checked just now from this browser.</div><span class="lkx">Show the rule</span></div>
<div class="ok-b amber" style="margin:10px 0 0">%s<div><b>Not set up yet.</b> Links will look like <span style="font-family:ui-monospace,Menlo,monospace;font-size:12.5px">/trip/?link=k7Qp&hellip;</span> instead, which works without any server change.</div><span class="lkx">Show the rule</span></div>
<div class="lab" style="margin-top:6px">(Two states drawn: you would see one of them.)</div></div></div>
<div class="srow"><div style="flex:1"><div class="nm">Where visitors&rsquo; IP addresses come from</div>
<div class="seg" style="margin:8px 0 0"><span>Directly</span><span class="on">Cloudflare</span><span>Another proxy&hellip;</span></div>
<div class="hp">The gallery reads the visitor&rsquo;s address from Cloudflare&rsquo;s header, and only when the request really comes from Cloudflare. Without this, every visitor shows as a Cloudflare address, in the link logs and in the sign-in throttle.</div>
<div class="hp" style="margin-top:8px">Your address as the gallery sees it: <b style="color:#eaeaea;font-family:ui-monospace,Menlo,monospace">203.0.113.42</b> (via Cloudflare, United States)</div></div></div>
<div class="srow"><div style="flex:1"><div class="nm">Link logs</div><div class="hp">Each link keeps its visitors&rsquo; IP addresses, browsers and times, and any blocked tries, for as long as the link exists. Only deleting a link deletes its log.</div></div></div>
</div></div>'''%(CHECK,WARN)
def board(fn,title,html,w,h):
    doc='<!doctype html>\n<html lang="en">\n<head>\n<meta charset="utf-8">\n<title>'+title+'</title>\n<script src="./support.js"></script>\n</head>\n<body>\n<x-dc>\n<helmet>\n<style>'+CSS+'</style>\n</helmet>\n'+html+'\n</x-dc>\n<script type="text/x-dc" data-dc-script data-props=\'{"$preview":{"width":%d,"height":%d}}\'>\nclass Component extends DCLogic {\n  renderVals() { return {}; }\n}\n</script>\n</body>\n</html>\n'%(w,h)
    open(P+fn,'w').write(doc)
boards=[('LinksList.dc.html','Share links list',b1,960,960,0,11500,'Share links 1 · The tab: active and ended links, one expanded (IP list)'),
 ('LinksEnded.dc.html','Share links ended',b2,960,745,1040,11500,'Share links 2 · An ended link keeps its log; Delete asks first'),
 ('LinksNew.dc.html','Share links new',b3,960,780,0,12700,'Share links 3 · New link form, and what you get after Create'),
 ('LinksPhone.dc.html','Share links phone',b4,390,970,1040,12700,'Share links 4 · Phone'),
 ('LinksVisitor.dc.html','Share links visitor',b5,760,420,0,14000,'Share links 5 · What the visitor sees'),
 ('LinksSettings.dc.html','Share links settings',b6,960,535,860,14000,'Share links 6 · Site settings: switch, short-address check, IP source')]
c=json.load(open(P+'canvas.json'))
for fn,t,h,w,ht,x,y,ct in boards:
    board(fn,t,h,w,ht)
    c['boards'][fn]={"x":x,"y":y,"w":w,"h":ht,"title":ct}
    if fn not in c['order']: c['order'].append(fn)
c['notes']['shareT']={"x":0,"y":11400-120,"text":"Share links","kind":"title1","maxW":2300}
json.dump(c,open(P+'canvas.json','w'),indent=2)
print('ok')
