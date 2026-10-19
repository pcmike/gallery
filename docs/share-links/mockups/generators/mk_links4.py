import json,os,sys
S=os.environ['S']; P=S+'/mock/project/'
src=open(S+'/patch/mk_links3.py').read().split("H=json.loads")[0]
exec(src)
REFRESH=I('<path d="M20 11A8 8 0 0 0 5.6 7M4 4v4h4M4 13a8 8 0 0 0 14.4 4M20 20v-4h-4"/>')
CSS3=CSS3+"""
.pvb{background:#2a2350;border-bottom:1px solid #4a3f8f;padding:10px 16px;display:flex;align-items:center;gap:12px;font-size:13.5px;color:#e0dcff}
.pvb .i{font-size:18px;color:#b9aeff}
.pvb b{color:#fff}
.gal{background:#0f0f10;border:1px solid #26262a;border-radius:12px;overflow:hidden}
.gal .hd2{padding:18px 22px 6px}
.gal .hd2 h1{margin:0;font-size:26px}
.card2{border-radius:12px;height:170px;position:relative;overflow:hidden}
.card2 .cap{position:absolute;left:0;right:0;bottom:0;padding:34px 14px 12px;background:linear-gradient(transparent,rgba(0,0,0,0.7));font-weight:600}
.card2 .cap span{display:block;font-weight:400;font-size:12.5px;color:#c9c9ce;margin-top:2px}
.chk2{display:flex;gap:8px;align-items:flex-start;font-size:13.5px;margin:7px 0;line-height:1.4}
.chk2 .i{margin-top:2px;font-size:16px}
.ok{color:#6fcf8d}.no{color:#8a8a8f}.wn{color:#e0a028}
.bub{background:#26262a;border-radius:18px;overflow:hidden;width:300px}
.bub .im{height:150px;display:flex;align-items:center;justify-content:center;color:#8a8a8f;background:#1c1c1f}
.bub .tx2{padding:10px 14px 12px}
.bub .tx2 b{display:block;font-size:15px}
.bub .tx2 span{font-size:12.5px;color:#8a8a8f}
.menu{background:#1f1f22;border:1px solid #34343a;border-radius:10px;width:300px;padding:6px;box-shadow:0 10px 30px rgba(0,0,0,0.5)}
.menu div{padding:9px 12px;border-radius:7px;font-size:13.5px}
.menu div small{display:block;color:#8a8a8f;font-size:12px;margin-top:2px}
.menu div.hl{background:#2c2c31}
.code{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;line-height:1.7;background:#0f0f10;border:1px solid #26262a;border-radius:8px;padding:12px 14px;color:#cfcfd6;overflow:hidden;white-space:pre}
.ftool{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.ib{width:36px;height:36px;border-radius:8px;border:1px solid #34343a;background:#26262a;display:flex;align-items:center;justify-content:center;font-size:18px;color:#eaeaea;position:relative}
.ib.hl{border-color:#5865f2;background:rgba(88,101,242,0.25);color:#c9ceff}
.tip{position:absolute;top:42px;left:50%;transform:translateX(-50%);background:#eaeaea;color:#111;font-size:12px;padding:4px 8px;border-radius:6px;white-space:nowrap;font-weight:600}
.redb{display:flex;align-items:center;gap:10px;padding:10px 14px;background:rgba(237,66,69,0.14);border:1px solid rgba(237,66,69,0.5);border-radius:10px;font-size:13.5px;margin-top:12px}
.redb .i{color:#ff8a8c;font-size:18px}
.wnb{display:flex;gap:10px;align-items:center;padding:10px 14px;background:rgba(224,160,40,0.12);border:1px solid rgba(224,160,40,0.5);border-radius:10px;font-size:13.5px;margin-bottom:14px}
.wnb .i{color:#e0a028;font-size:18px}
"""
def board(fn,title,html,w,h):
    doc='<!doctype html>\n<html lang="en">\n<head>\n<meta charset="utf-8">\n<title>'+title+'</title>\n<script src="./support.js"></script>\n</head>\n<body>\n<x-dc>\n<helmet>\n<style>'+CSS3+'</style>\n</helmet>\n'+html+'\n</x-dc>\n<script type="text/x-dc" data-dc-script data-props=\'{"$preview":{"width":%d,"height":%d}}\'>\nclass Component extends DCLogic {\n  renderVals() { return {}; }\n}\n</script>\n</body>\n</html>\n'%(w,h)
    open(P+fn,'w').write(doc)
XICO=I('<path d="M6 6l12 12M18 6 6 18"/>'); CHK=I('<path d="M4.5 12.5 9.5 17.5 19.5 7"/>')
# ---------- A: Preview as
inc=('<div class="pn" style="width:330px;margin:0;flex:none"><h2 style="margin-bottom:10px">What this link includes</h2>'
 '<div class="chk2"><span class="i ok">%s</span><span>Rome, Pisa and everything inside them</span></div>'
 '<div class="chk2"><span class="i ok">%s</span><span>All public folders and photos, as any visitor sees them</span></div>'
 '<div class="chk2"><span class="i no">%s</span><span>Florence, Churches and your other private folders are <b>not shown and not hinted at</b></span></div>'
 '<div class="chk2"><span class="i ok">%s</span><span>Originals can be downloaded in Rome. ZIP is off.</span></div>'
 '<div class="chk2"><span class="i ok">%s</span><span>Locations: only the ones the public can see</span></div>'
 '<div class="note">This panel is part of the preview banner, under &ldquo;What&rsquo;s included&rdquo;.</div></div>')%(CHK,CHK,XICO,CHK,CHK)
cards=''.join('<div class="card2" style="background:%s"><div class="cap">%s<span>%s</span></div></div>'%(G[i],n,c) for i,(n,c) in enumerate([('Rome','42 photos and videos'),('Pisa','4 photos')]))
galP=('<div class="gal" style="flex:1;min-width:0"><div class="pvb">%s<span><b>Previewing as Aunt Carol.</b> You see exactly what this link shows. Nothing you do here is counted or logged.</span><span class="sp"></span><span style="white-space:nowrap;color:#c9ceff">What&rsquo;s included &#9662;</span><a class="btn sm">Exit preview</a></div>'
 '<div class="hd2"><h1>Eurotrip 2026</h1><div class="mut" style="margin-top:4px">2 folders</div></div><div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;padding:14px 22px 22px">%s</div></div>')%(EYE,cards)
bPA='<div class="pg" style="width:1160px;padding:22px 24px"><div class="lab">Opened by &ldquo;Preview as this link&rdquo; (it opens in a new tab and has its own sign-in, so you stay signed in as yourself in the first one)</div><div style="display:flex;gap:20px;align-items:flex-start">'+galP+inc+'</div><div class="note" style="max-width:820px">No Private pills, no pencils, no locks, no Private tab: the owner&rsquo;s tools are gone, exactly as for the person you send it to. Pressing Exit preview ends the preview and closes the tab.</div></div>'
# ---------- B: Replace address
actsrow='<div class="acts" style="margin-top:0"><a class="btn sm">'+EYE+' Preview as this link</a><a class="btn sm">'+EXPI+' Export log &#9662;</a><span class="sp"></span><a class="tx">Edit&hellip;</a><a class="tx">Pause</a><a class="tx" style="text-decoration:underline">Replace address&hellip;</a><a class="tx">Revoke</a><a class="tx red">Delete&hellip;</a></div>'
dl2='<div class="dlg" style="width:460px"><h3>Give this link a new address?</h3><p>The address you sent Aunt Carol stops working right away, and anyone signed in through it is signed out.</p><p style="color:#8a8a8f;font-size:13px">Its name, settings and log stay exactly as they are. You&rsquo;ll need to send the new address to whoever should still have it.</p><div class="bt"><a class="btn">Cancel</a><a class="btn pri">%s Replace address</a></div></div>'%REFRESH
after=('<div class="bx" style="margin-top:0"><div class="ok-b good" style="margin:0 0 12px">%s<div><b>New address ready.</b> The old one no longer works.</div></div><div class="full"><div class="fld">https://pcmike.net/trip/<b style="color:#aab2ff">Vw3nT8yHq5ZkRb6c</b></div></div>'%CHECK
 +'<div class="btnrow"><a class="btn sm">%s Copy</a><a class="btn sm">%s Share&hellip;</a><a class="btn sm">%s QR code</a></div><div class="note">Log, settings and note unchanged. The old address is logged as a blocked try if anyone presses it.</div></div>'%(COPY,SHARE,QRI))
bRP=('<div class="pg" style="width:960px;padding:22px 24px"><div class="pn">'
 '<div class="lab">1 &middot; In the link&rsquo;s actions</div><div class="bx" style="margin-top:0">'+actsrow+'</div>'
 '<div class="lab" style="margin-top:20px">2 &middot; The confirmation</div><div style="background:rgba(0,0,0,0.5);border-radius:12px;padding:26px;display:flex;justify-content:center">'+dl2+'</div>'
 '<div class="lab" style="margin-top:20px">3 &middot; Afterwards</div>'+after+'</div></div>')
# ---------- C: Share this folder
PIN=I('<path d="M12 21s-6.5-5.4-6.5-10.5a6.5 6.5 0 1 1 13 0C18.5 15.6 12 21 12 21zM14.3 10.5a2.3 2.3 0 1 1-4.6 0 2.3 2.3 0 0 1 4.6 0z"/>')
SORTI=I('<path d="M8 5v14M5 16l3 3 3-3M16 19V5M13 8l3-3 3 3"/>')
SELI=I('<path d="M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0zM8.4 12.2l2.6 2.6 4.6-5"/>')
ZIPI=I('<path d="M12 4v11M7.5 10.5 12 15l4.5-4.5M5 19.5h14"/>')
folderpage=('<div class="pn" style="margin:0"><div style="font-size:12.5px;color:#8a8a8f;margin-bottom:6px">Eurotrip 2026</div><div class="lh"><h2 style="font-size:24px">Rome</h2><span class="sp"></span>'
 '<div class="ftool"><span class="ib">%s</span><span class="ib">%s</span><span class="ib">%s</span><span class="ib">%s</span><span class="ib hl">%s<span class="tip">Share this folder&hellip;</span></span></div></div>'
 '<div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap"><span class="st on" style="height:28px;padding:0 12px;border-color:#5865f2;background:#5865f2;color:#fff">%s Rome</span><span class="st end" style="height:28px;padding:0 12px">Pisa</span><span class="st end" style="height:28px;padding:0 12px">%s Florence</span></div>'
 '<div class="redb">%s<span><b>This folder is private.</b> 3 photos stay private, 39 become public when you open it.</span><span class="sp"></span><a class="btn sm">Make public&hellip;</a></div></div>')%(PIN,SORTI,SELI,ZIPI,SHARE,LOCK,LOCK,LOCK)
privrow=('<div class="pn" style="margin:0"><div class="cr first" style="display:flex;align-items:center;gap:12px;padding:6px 4px"><span style="width:28px;height:28px;border-radius:50%%;background:rgba(237,66,69,0.92);display:flex;align-items:center;justify-content:center;color:#fff">%s</span><div style="flex:1"><div style="font-size:15px;font-weight:600">Eurotrip 2026 / Rome</div><div class="mut" style="margin-top:2px">3 stay private &middot; 39 become public when opened</div></div><span class="cc">%s</span></div>'
 '<div style="padding:8px 4px 4px 44px;display:flex;gap:10px;align-items:center;flex-wrap:wrap"><a class="tx">See them in the gallery &rsaquo;</a><span style="color:#55555c">&middot;</span><a class="tx">Make public&hellip;</a><span style="color:#55555c">&middot;</span><a class="tx">Folder settings</a><span style="color:#55555c">&middot;</span><a class="tx" style="text-decoration:underline">Share&hellip;</a></div></div>')%(LOCK,UP)
quick=('<div class="dlg" style="width:480px"><h3>Share Rome</h3>'
 +'<div class="fl"><div class="k">Who is it for</div><div class="inp">Aunt Carol</div></div>'
 +'<div class="fl"><div class="k">Folders</div><div>'+(CHIPQ%'Eurotrip 2026 / Rome')+'<span class="chip" style="color:#aab2ff">+ Add folder</span></div><div class="h">Everything inside them too.</div></div>'
 +'<div class="fl"><div class="k">Opens</div>'+segb(['Once','5','10','Unlimited'],'10')+'</div>'
 +'<div class="fl"><div class="k">Stops working</div>'+segb(['1 day','1 week','30 days','Never'],'30 days')+'</div>'
 +'<div style="margin:0 0 6px;font-size:12.5px;color:#8a8a8f">Downloads off, locations as the public sees them, no note. <a class="tx" style="font-size:12.5px">More options&hellip;</a></div>'
 +'<div class="bt"><a class="btn">Cancel</a><a class="btn pri">Create link</a></div></div>')
bSF=('<div class="pg" style="width:1100px;padding:22px 24px"><div style="display:flex;gap:28px;align-items:flex-start"><div style="flex:1;min-width:0">'
 '<div class="lab">1 &middot; A private folder&rsquo;s page: a share button beside the others</div>'+folderpage+
 '<div class="lab" style="margin-top:22px">2 &middot; The Private tab: one more link on each folder</div>'+privrow+
 '<div class="note">On a phone the button sits in the same row as the folder buttons. Signed-out visitors and link holders never see it.</div></div>'
 '<div style="flex:none"><div class="lab">3 &middot; Either one opens a short form, already set to this folder</div><div style="background:rgba(0,0,0,0.5);border-radius:12px;padding:22px">'+quick+'</div></div></div></div>')
# ---------- D: export
csv=('time,ip,link,what,folder,file\n'
 '2026-10-19 09:52:14,203.0.113.42,Aunt Carol,opened,Eurotrip 2026/Pisa,IMG_2310.jpg\n'
 '2026-10-19 09:41:08,203.0.113.42,Aunt Carol,opened,Eurotrip 2026/Rome,IMG_2041.jpg\n'
 '2026-10-19 09:40:31,203.0.113.42,Aunt Carol,played,Eurotrip 2026/Rome,MVI_0188.mov\n'
 '2026-10-19 09:25:02,203.0.113.42,Aunt Carol,saved,Eurotrip 2026/Rome,IMG_2057.jpg\n'
 '2026-10-19 09:16:20,203.0.113.42,Aunt Carol,seen,Eurotrip 2026/Rome,IMG_2041.jpg')
menu=('<div class="menu"><div>Visitors<small>One line per IP: opens, first and last seen</small></div><div class="hl">What they looked at<small>One line per item: seen, opened, played, saved</small></div><div>Everything, in order<small>The timeline, one line per event</small></div><div style="color:#8a8a8f;font-size:12.5px;border-top:1px solid #34343a;border-radius:0;margin-top:4px">Includes IP addresses. Opens in your downloads.</div></div>')
bEx=('<div class="pg" style="width:960px;padding:22px 24px"><div class="pn"><div class="lab">1 &middot; The Export log button in a link&rsquo;s actions</div><div class="bx" style="margin-top:0;display:flex;gap:20px;align-items:flex-start"><div><div class="acts" style="margin-top:0"><a class="btn sm">'+EYE+' Preview as this link</a><a class="btn sm" style="border-color:#5865f2;color:#c9ceff">'+EXPI+' Export log &#9652;</a></div><div style="margin-top:8px">'+menu+'</div></div></div>'
 '<div class="lab" style="margin-top:20px">2 &middot; &ldquo;What they looked at&rdquo;, opened in a spreadsheet or any text editor</div><div class="code">'+csv.replace('<','&lt;')+'</div>'
 '<div class="note">The same button sits on an IP address&rsquo;s page (it exports just that IP) and on the IP addresses list (everything, all links). Times are in your time zone.</div></div></div>')
# ---------- E: folder missing
mrow_html=('<div class="lr first"><div class="lh"><span class="st on">Active</span><span class="ln">Aunt Carol</span><span class="st pause" style="margin-left:2px">%s Folder missing</span><span class="lu">/trip/k7Qp&hellip;Tb4e</span><span class="sp"></span><a class="btn sm">%s Copy link</a><span class="cc">%s</span></div><div class="ls"><b>2 of 5</b> opens &middot; <b>2</b> IPs &middot; ends Sun, Oct 25, 6:00 pm</div>'
 '<div class="bx"><div class="sub" style="margin-top:0">What this link can see</div>'
 '<div class="chk2" style="margin:6px 0"><span class="i ok">%s</span><span>Eurotrip 2026 / Pisa <span class="mut">&middot; 4 photos</span></span></div>'
 '<div class="chk2" style="margin:6px 0"><span class="i wn">%s</span><span><b>Eurotrip 2026 / Rome</b> can&rsquo;t be found. It may have been renamed, moved or deleted.<br><span class="mut">Nothing from it is shown to anyone using this link. The rest of the link works.</span></span></div>'
 '<div class="btnrow" style="margin:10px 0 4px 24px"><a class="btn sm pri">Pick it again&hellip;</a><a class="btn sm">Remove it from this link</a></div>'
 '<div class="sel" style="width:360px;margin:8px 0 0 24px;border-color:#5865f2"><span>Did you rename it? Eurotrip 2026 / Rome (2026)</span>%s</div></div></div>')%(WARN,COPY,UP,CHK,WARN,DOWN)
bMi=('<div class="pg" style="width:960px;padding:22px 24px">'+tabs()+'<div class="wnb">%s<div><b>1 link refers to a folder that can&rsquo;t be found.</b> It fails safe: nothing from that folder is shown.</div><span class="lkx">Show it</span></div>'%WARN
 +'<div class="pn">'+head('Share links','2 not ended &middot; 3 ended',True)+'<div class="grp" style="margin-top:14px">Current</div>'+mrow_html+'</div></div>')
# ---------- F: unfurl
def bub(inner_img,title,dom,cap):
    return '<div><div class="lab" style="width:300px;min-height:34px">%s</div><div class="bub"><div class="im" style="%s">%s</div><div class="tx2"><b>%s</b><span>%s</span></div></div></div>'%(cap,'' ,inner_img,title,dom)
ICO='<div style="text-align:center"><div style="width:64px;height:64px;border-radius:14px;background:#0f0f10;border:1px solid #34343a;display:inline-flex;align-items:center;justify-content:center;font-size:30px;color:#eaeaea">'+IMG+'</div></div>'
PHOTO='<div style="width:100%;height:100%;background:'+G[1]+'"></div>'
bUn=('<div class="pg" style="width:1100px;padding:22px 24px"><div style="display:flex;gap:24px;align-items:flex-start;flex-wrap:wrap">'
 +bub(ICO,'Eurotrip 2026','pcmike.net','1 &middot; Someone pastes your share link into Messages')
 +bub(PHOTO,'Eurotrip 2026','pcmike.net','2 &middot; A public photo&rsquo;s link: shows the picture, as today')
 +bub(ICO,'Eurotrip 2026','pcmike.net','3 &middot; A private photo&rsquo;s link, pasted by someone who has access')+'</div>'
 '<div style="display:flex;gap:24px;margin-top:14px;font-size:13px;color:#b9b9c0;line-height:1.5"><div style="width:300px">The opening page gives the preview only the gallery&rsquo;s title and icon: no photo, no folder name, nothing about what&rsquo;s inside.</div><div style="width:300px">Unchanged. Public pictures keep previewing with their picture.</div><div style="width:300px">The preview app has no access, so it gets the plain gallery: no photo, no private name. And anyone else who opens that address sees the plain gallery too, with no photo, so the link is useless to them.</div></div></div>')
H=json.loads(os.environ.get('LH','{}'))
spec=[('LinksPreviewAs.dc.html','Share links preview as',bPA,1160,H.get('PA',450),0,19700,'Share links 11 · Preview as this link (counts nothing, logs nothing)'),
 ('LinksReplace.dc.html','Share links replace',bRP,960,H.get('RP',712),1240,19700,'Share links 12 · Replace the address of a link'),
 ('LinksShareFolder.dc.html','Share links share folder',bSF,1100,H.get('SF',545),0,20500,'Share links 13 · Share this folder: from a private folder, or the Private tab'),
 ('LinksExport.dc.html','Share links export',bEx,960,H.get('EX',595),1240,20700,'Share links 14 · Export the log as CSV'),
 ('LinksMissing.dc.html','Share links missing',bMi,960,H.get('MI',550),0,21500,'Share links 15 · A chosen folder was renamed, moved or deleted'),
 ('LinksUnfurl.dc.html','Share links unfurl',bUn,1100,H.get('UN',385),1040,21500,'Share links 16 · What a message preview shows')]
c=json.load(open(P+'canvas.json'))
for fn,t,h,w,ht,x,y,ct in spec:
    board(fn,t,h,w,ht)
    c['boards'][fn]={"x":x,"y":y,"w":w,"h":ht,"title":ct}
    if fn not in c['order']: c['order'].append(fn)
json.dump(c,open(P+'canvas.json','w'),indent=2)
print('ok')
