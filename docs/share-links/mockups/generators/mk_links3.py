import json,os,sys
S=os.environ['S']; P=S+'/mock/project/'
src=open(S+'/patch/mk_links2.py').read().split("def board(fn,title")[0]
exec(src)
PLAY=I('<path d="M8 5.5v13l10.5-6.5z"/>')
CSS3=CSS2+'''
.mt-h,.mt{display:grid;grid-template-columns:54px 1.35fr 1.7fr .55fr 1.15fr 1.15fr;gap:12px;align-items:center;font-size:13px}
.mt-h{color:#8a8a8f;font-size:11.5px;letter-spacing:.05em;text-transform:uppercase;padding:0 0 6px;border-bottom:1px solid #26262a}
.mt{padding:8px 0;border-bottom:1px solid #1f1f22}
.mt:last-child{border-bottom:0}
.mt .nm{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12.5px;display:flex;gap:6px;align-items:center}
.mt .fo{color:#b9b9c0;display:flex;gap:6px;align-items:center}
.mt .fo .i{color:#ff8a8c;font-size:14px}
.tn{position:relative;width:48px;height:48px;border-radius:6px;overflow:hidden;display:block}
.tn .b{position:absolute;right:3px;bottom:3px;width:16px;height:16px;border-radius:50%;background:rgba(0,0,0,0.66);display:flex;align-items:center;justify-content:center;font-size:9px;color:#fff}
.tn .l{position:absolute;left:3px;top:3px;width:16px;height:16px;border-radius:50%;background:rgba(237,66,69,0.92);display:flex;align-items:center;justify-content:center;font-size:9px;color:#fff}
.tl{display:grid;grid-template-columns:96px 48px 1fr;gap:12px;align-items:center;padding:7px 0;border-bottom:1px solid #1f1f22;font-size:13px}
.tl:last-child{border-bottom:0}
.tl .t{color:#8a8a8f;font-variant-numeric:tabular-nums}
.tl .d{color:#8a8a8f;font-size:12.5px;margin-top:2px}
.dayh{font-size:11.5px;letter-spacing:.06em;text-transform:uppercase;color:#8a8a8f;margin:14px 0 2px}
.kpi{display:flex;gap:26px;flex-wrap:wrap;margin:12px 0 4px}
.kpi div b{display:block;font-size:20px}
.kpi div span{font-size:12.5px;color:#8a8a8f}
.ipH{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:20px;font-weight:700}
.gt{display:grid;grid-template-columns:1.2fr .4fr 1.2fr 1.5fr .5fr 1.1fr;gap:12px;align-items:center;font-size:13px;padding:9px 0;border-bottom:1px solid #1f1f22}
.gt.h{color:#8a8a8f;font-size:11.5px;letter-spacing:.05em;text-transform:uppercase;padding:0 0 6px;border-bottom:1px solid #26262a}
.gt:last-child{border-bottom:0}
.lnkchip{display:inline-flex;align-items:center;height:22px;padding:0 9px;border-radius:999px;background:#26262a;border:1px solid #34343a;font-size:12px;margin-right:6px}
.ipbtn{color:#aab2ff}
'''
def tn(i,video=False,priv=True):
    return '<span class="tn" style="background:%s">%s%s</span>'%(G[i%4],('<span class="l">%s</span>'%LOCK) if priv else '',('<span class="b">%s</span>'%PLAY) if video else '')
def mrow(i,name,folder,n,first,last,video=False,priv=True,via=None):
    return '<div class="mt">%s<span class="nm">%s</span><span class="fo">%s%s</span><span>&times;%s</span><span>%s</span><span>%s</span></div>'%(tn(i,video,priv),name,('<span class="i">%s</span>'%LOCK) if priv else FOLDER,folder+(' &middot; <span class="mut">via %s</span>'%via if via else ''),n,first,last)
media=[(0,'IMG_2041.jpg','Eurotrip 2026 / Rome',8,'Oct 19, 9:16 am','Today, 9:41 am',False,True),
 (1,'IMG_2042.jpg','Eurotrip 2026 / Rome',6,'Oct 19, 9:16 am','Today, 9:38 am',False,True),
 (2,'MVI_0188.mov','Eurotrip 2026 / Rome',4,'Oct 19, 9:21 am','Today, 9:30 am',True,True),
 (3,'IMG_2057.jpg','Eurotrip 2026 / Rome',4,'Oct 19, 9:24 am','Oct 19, 9:25 am',False,True),
 (0,'IMG_2310.jpg','Eurotrip 2026 / Pisa',2,'Oct 19, 9:47 am','Today, 9:52 am',False,True),
 (1,'IMG_0017.jpg','Eurotrip 2026 (front page)',3,'Oct 19, 9:14 am','Oct 19, 9:15 am',False,False)]
def mt_table(rows,hdr='Opened'):
    return '<div class="mt-h"><span></span><span>File</span><span>Folder</span><span>%s</span><span>First opened</span><span>Last opened</span></div>'%hdr+''.join(mrow(*r) for r in rows)
def ipHeader(active):
    return ('<div class="lh" style="margin-bottom:6px;flex-wrap:wrap"><span class="ipH">203.0.113.42</span><span class="mut">United States &middot; Safari on iPhone</span><span class="sp"></span><div class="sel" style="width:210px">Links: all (2) %s</div></div>'%DOWN
     +'<div class="lh" style="margin:2px 0 4px"><span class="lnkchip">Aunt Carol &middot; 1 open</span><span class="lnkchip">Dave (work friend) &middot; 1 open</span></div>'
     +'<div class="kpi"><div><b>3</b><span>folders</span></div><div><b>26</b><span>photos</span></div><div><b>3</b><span>videos</span></div><div><b>126</b><span>views</span></div><div><b>Oct 19, 9:14 am</b><span>first seen</span></div><div><b>Today, 9:52 am</b><span>last seen</span></div></div>'
     +'<div class="seg2" style="margin-top:12px">'+segb(['Folders','Media','Timeline'],active)+'</div>')
back='<div class="tx" style="margin-bottom:10px">&lsaquo; Aunt Carol</div>'
# Panel 1: folders
fr=[('Eurotrip 2026 / Rome',True,'28 of 42','112','Oct 19, 9:16 am','Today, 9:41 am',100),('Eurotrip 2026 / Pisa',True,'1 of 4','2','Oct 19, 9:47 am','Today, 9:52 am',2),('Eurotrip 2026 (front page)',False,'&mdash;','12','Oct 19, 9:14 am','Oct 19, 9:15 am',11)]
def f2(r):
    nm,priv,seen,views,first,last,pct=r
    return '<div class="mt" style="grid-template-columns:1.9fr .9fr .6fr 1.1fr 1.1fr"><div><div class="fo" style="color:#eaeaea">%s%s</div><div class="bar"><i style="width:%d%%"></i></div></div><span>%s items</span><span>%s</span><span>%s</span><span>%s</span></div>'%(('<span class="i" style="color:#ff8a8c;font-size:16px">%s</span>'%LOCK) if priv else '<span class="i" style="color:#8a8a8f;font-size:16px">%s</span>'%FOLDER,nm,pct,seen,views,first,last)
fold='<div class="mt-h" style="grid-template-columns:1.9fr .9fr .6fr 1.1fr 1.1fr"><span>Folder</span><span>Opened</span><span>Views</span><span>First opened</span><span>Last opened</span></div>'+''.join(f2(r) for r in fr)
p1='<div class="bx" style="margin-top:0">'+back+ipHeader('Folders')+'<div style="margin-top:10px"></div>'+fold+'<div class="note">&ldquo;28 of 42&rdquo; means this IP opened 28 of that folder&rsquo;s 42 photos and videos. Press a folder to see exactly which ones.</div></div>'
# Panel 2: media
p2='<div class="bx" style="margin-top:0">'+back+ipHeader('Media')+'<div class="btnrow" style="margin:12px 0 8px"><div class="sel" style="width:190px">All folders %s</div><div class="sel" style="width:170px">Most opened %s</div><div class="sel" style="width:190px">Photos and videos %s</div></div>'%(DOWN,DOWN,DOWN)+mt_table(media)+'<div class="note">Showing 6 of 29 items. Filenames are shown here because only you see this page. Press a row to open it in the gallery.</div></div>'
# Panel 3: timeline
ev=[('9:52:14 am',3,False,True,'Opened <b>IMG_2310.jpg</b>','Eurotrip 2026 / Pisa'),
    ('9:51:40 am',None,False,True,'Opened the folder <b>Eurotrip 2026 / Pisa</b>',''),
    ('9:41:08 am',0,False,True,'Opened <b>IMG_2041.jpg</b>','Eurotrip 2026 / Rome'),
    ('9:40:31 am',2,True,True,'Opened <b>MVI_0188.mov</b>','Eurotrip 2026 / Rome'),
    ('9:38:02 am',1,False,True,'Opened <b>IMG_2042.jpg</b>','Eurotrip 2026 / Rome')]
def evrow(e):
    t,i,v,p,txt,fo=e
    th=tn(i,v,p) if i is not None else '<span class="tn" style="background:#26262a;display:flex;align-items:center;justify-content:center;color:#8a8a8f;font-size:20px">%s</span>'%FOLDER
    return '<div class="tl"><span class="t">%s</span>%s<div>%s%s</div></div>'%(t,th,txt,('<div class="d">%s</div>'%fo) if fo else '')
p3='<div class="bx" style="margin-top:0">'+back+ipHeader('Timeline')+'<div class="dayh">Today</div>'+''.join(evrow(e) for e in ev)+'<div class="dayh">Oct 19</div>'+evrow(('9:16:20 am',0,False,True,'Opened <b>IMG_2041.jpg</b> for the first time','Eurotrip 2026 / Rome'))+evrow(('9:14:05 am',None,False,False,'Opened the link, then the <b>front page</b>',''))+'<div class="note">Every folder page and every photo or video this IP opened, in order, for the latest 300 events per link. Older ones are folded into the counts in the Folders and Media tabs, so nothing is lost.</div></div>'
# Panel 4: all IPs
g=[('203.0.113.42','US','Safari on iPhone','<span class="lnkchip">Aunt Carol</span><span class="lnkchip">Dave</span>','126','Today, 9:52 am'),
   ('198.51.100.7','US','Safari on Mac','<span class="lnkchip">Aunt Carol</span>','31','Oct 19, 8:02 am'),
   ('198.51.100.77','GB','Chrome on Windows','<span class="lnkchip">Photographer</span> <span style="color:#e0a028;font-size:12px">blocked &times;2</span>','0','Oct 13, 7:12 am'),
   ('192.0.2.15','US','Safari on iPhone','<span class="lnkchip">Photographer</span>','38','Oct 12, 3:40 pm')]
p4=('<div class="bx" style="margin-top:0"><div class="seg2">'+segb(['Links','IP addresses'],'IP addresses')+'</div><div style="margin-top:12px"></div><div class="gt h"><span>IP address</span><span>Where</span><span>Browser</span><span>Links used</span><span>Views</span><span>Last seen</span></div>'
  +''.join('<div class="gt"><span class="ip ipbtn">%s</span><span>%s</span><span>%s</span><span>%s</span><span>%s</span><span>%s</span></div>'%r for r in g)
  +'<div class="note">Every address that has opened any link, across all your links. An address on two links, like the first, is worth a look. Press one to see everything it opened.</div></div>')
bI=('<div class="pg" style="width:960px;padding:22px 24px"><div class="pn">'
 '<div class="lab">1 &middot; Press an IP address anywhere in the log: what that IP looked at, by folder</div>'+p1+
 '<div class="lab" style="margin-top:22px">2 &middot; Media: exactly which photos and videos, with times</div>'+p2+
 '<div class="lab" style="margin-top:22px">3 &middot; Timeline: in order</div>'+p3+
 '<div class="lab" style="margin-top:22px">4 &middot; And a list of every IP across all links (a second switch at the top of the Share links tab)</div>'+p4+'</div></div>')
# phone
def mrowP(i,name,folder,n,first,last,video=False,priv=True):
    return '<div class="vm" style="display:flex;gap:10px"><span style="flex:none">%s</span><div style="min-width:0"><div class="nm" style="font-family:ui-monospace,Menlo,monospace;font-size:12.5px">%s &nbsp;<span class="mut">&times;%s</span></div><div class="s">%s</div><div class="s">%s &middot; last %s</div></div></div>'%(tn(i,video,priv),name,n,folder,first,last)
bIP=('<div class="pg" style="width:390px;padding:16px 12px"><div class="pn" style="padding:14px 12px"><div class="tx" style="margin-bottom:8px">&lsaquo; Aunt Carol</div><div class="ipH" style="font-size:18px">203.0.113.42</div><div class="mut" style="margin-top:3px">United States &middot; Safari on iPhone</div>'
 '<div style="margin-top:8px"><span class="lnkchip">Aunt Carol</span><span class="lnkchip">Dave</span></div><div class="kpi" style="gap:18px"><div><b>3</b><span>folders</span></div><div><b>29</b><span>items</span></div><div><b>126</b><span>views</span></div></div>'
 '<div class="seg2" style="margin-top:12px">'+segb(['Folders','Media','Timeline'],'Media')+'</div><div class="btnrow" style="margin:10px 0 4px"><div class="sel" style="width:150px">All folders %s</div><div class="sel" style="width:160px">Most opened %s</div></div>'%(DOWN,DOWN)
 +''.join(mrowP(*[m[0],m[1],m[2],m[3],m[4],m[5],m[6],m[7]]) for m in media[:5])+'<div class="note">Showing 5 of 29. Press a row to open it in the gallery.</div></div></div>')
# ---- v2: seen / opened / played / saved
CSS3=CSS3+"""
.m2h,.m2{display:grid;grid-template-columns:54px 1.25fr 1.5fr 1.05fr .75fr .6fr 1fr;gap:12px;align-items:center;font-size:13px}
.m2h{color:#8a8a8f;font-size:11.5px;letter-spacing:.05em;text-transform:uppercase;padding:0 0 6px;border-bottom:1px solid #26262a}
.m2{padding:8px 0;border-bottom:1px solid #1f1f22}
.m2:last-child{border-bottom:0}
.dim{color:#55555c}
.strip6{display:flex;gap:4px;margin-top:6px;align-items:center}
.strip6 span.s{width:34px;height:34px;border-radius:5px;display:block}
.strip6 .more{font-size:12px;color:#8a8a8f;margin-left:4px}
.legend{display:flex;gap:18px;flex-wrap:wrap;font-size:12.5px;color:#b9b9c0;margin:10px 0 2px}
.legend b{color:#fff}
.chk{color:#6fcf8d}
"""
def kpi2(items):
    return '<div class="kpi">'+''.join('<div><b>%s</b><span>%s</span></div>'%(a,b) for a,b in items)+'</div>'
def ipHeader2(active):
    return ('<div class="lh" style="margin-bottom:6px;flex-wrap:wrap"><span class="ipH">203.0.113.42</span><span class="mut">United States &middot; Safari on iPhone</span><span class="sp"></span><div class="sel" style="width:210px">Links: all (2) %s</div></div>'%DOWN
     +'<div class="lh" style="margin:2px 0 4px"><span class="lnkchip">Aunt Carol &middot; 1 open</span><span class="lnkchip">Dave (work friend) &middot; 1 open</span></div>'
     +kpi2([('3','folders'),('56','seen'),('13','opened'),('1','saved'),('Oct 19, 9:14 am','first seen'),('Today, 9:52 am','last seen')])
     +'<div class="legend"><span><b>Seen</b> it was on their screen, even as a thumbnail</span><span><b>Opened</b> shown large in the viewer (<b>Played</b> for a video)</span><span><b>Saved</b> original downloaded</span></div>'
     +'<div class="seg2" style="margin-top:12px">'+segb(['Folders','Media','Timeline'],active)+'</div>')
# folders
fr2=[('Eurotrip 2026 / Rome',True,'40 of 42','11','1','Oct 19, 9:16 am','Today, 9:41 am',95),('Eurotrip 2026 / Pisa',True,'4 of 4','2','&mdash;','Oct 19, 9:47 am','Today, 9:52 am',100),('Eurotrip 2026 (front page)',False,'12 of 260','&mdash;','&mdash;','Oct 19, 9:14 am','Oct 19, 9:15 am',5)]
def f3(r):
    nm,priv,seen,op,sv,first,last,pct=r
    ic=('<span class="i" style="color:#ff8a8c;font-size:16px">%s</span>'%LOCK) if priv else '<span class="i" style="color:#8a8a8f;font-size:16px">%s</span>'%FOLDER
    return '<div class="mt" style="grid-template-columns:1.9fr .9fr .7fr .6fr 1.1fr 1.1fr"><div><div class="fo" style="color:#eaeaea">%s%s</div><div class="bar"><i style="width:%d%%"></i></div></div><span>%s</span><span>%s</span><span>%s</span><span>%s</span><span>%s</span></div>'%(ic,nm,pct,seen,op,sv,first,last)
fold2='<div class="mt-h" style="grid-template-columns:1.9fr .9fr .7fr .6fr 1.1fr 1.1fr"><span>Folder</span><span>Seen</span><span>Opened</span><span>Saved</span><span>First seen</span><span>Last activity</span></div>'+''.join(f3(r) for r in fr2)
q1='<div class="bx" style="margin-top:0">'+back+ipHeader2('Folders')+'<div style="margin-top:10px"></div>'+fold2+'<div class="note">&ldquo;40 of 42&rdquo; means 40 of that folder&rsquo;s photos and videos were on this IP&rsquo;s screen, even if only as thumbnails. Press a folder to see exactly which ones, and which of them were opened.</div></div>'
# media
def m2row(i,name,folder,seen,opened,saved,last,video=False,priv=True):
    op=('<span>%s</span>'%opened) if opened else '<span class="dim">&mdash;</span>'
    sv='<span class="chk">&check; saved</span>' if saved else '<span class="dim">&mdash;</span>'
    return '<div class="m2">%s<span class="nm">%s</span><span class="fo">%s%s</span><span>%s</span>%s%s<span>%s</span></div>'%(tn(i,video,priv),name,('<span class="i">%s</span>'%LOCK) if priv else FOLDER,folder,seen,op,sv,last)
rows2=[(0,'IMG_2041.jpg','Eurotrip 2026 / Rome','Oct 19, 9:16 am','Opened &times;8',False,'Today, 9:41 am',False,True),
 (1,'IMG_2042.jpg','Eurotrip 2026 / Rome','Oct 19, 9:16 am','Opened &times;6',False,'Today, 9:38 am',False,True),
 (2,'MVI_0188.mov','Eurotrip 2026 / Rome','Oct 19, 9:21 am','Played &times;4',False,'Today, 9:40 am',True,True),
 (3,'IMG_2057.jpg','Eurotrip 2026 / Rome','Oct 19, 9:24 am','Opened &times;4',True,'Oct 19, 9:25 am',False,True),
 (0,'IMG_2063.jpg','Eurotrip 2026 / Rome','Oct 19, 9:26 am',None,False,'Oct 19, 9:26 am',False,True),
 (1,'IMG_2064.jpg','Eurotrip 2026 / Rome','Oct 19, 9:26 am',None,False,'Oct 19, 9:26 am',False,True),
 (2,'IMG_2310.jpg','Eurotrip 2026 / Pisa','Oct 19, 9:47 am','Opened &times;2',False,'Today, 9:52 am',False,True),
 (3,'IMG_0017.jpg','Eurotrip 2026 (front page)','Oct 19, 9:14 am','Opened &times;1',False,'Oct 19, 9:15 am',False,False)]
m2tab='<div class="m2h"><span></span><span>File</span><span>Folder</span><span>First seen</span><span>Opened</span><span>Saved</span><span>Last activity</span></div>'+''.join(m2row(*r) for r in rows2)
q2='<div class="bx" style="margin-top:0">'+back+ipHeader2('Media')+'<div class="btnrow" style="margin:12px 0 8px"><div class="sel" style="width:190px">All folders %s</div><div class="sel" style="width:210px">Everything seen %s</div><div class="sel" style="width:170px">Most recent %s</div></div>'%(DOWN,DOWN,DOWN)+m2tab+'<div class="note">Showing 8 of 56. The filter has Everything seen, Opened or played only, and Saved only. The two seen-only rows are photos this IP scrolled past as thumbnails without opening. Press a row to open it in the gallery.</div></div>'
# timeline
def strip(idx,more=''):
    return '<div class="strip6">'+''.join('<span class="s" style="background:%s"></span>'%G[i%4] for i in idx)+('<span class="more">%s</span>'%more if more else '')+'</div>'
def ev2(t,body,extra=''):
    return '<div class="tl" style="grid-template-columns:96px 1fr"><span class="t">%s</span><div>%s%s</div></div>'%(t,body,extra)
q3=('<div class="bx" style="margin-top:0">'+back+ipHeader2('Timeline')+'<div class="dayh">Today</div>'
 +ev2('9:52:14 am','Opened <b>IMG_2310.jpg</b><div class="d">Eurotrip 2026 / Pisa</div>')
 +ev2('9:47&ndash;9:52 am','Scrolled through <b>Eurotrip 2026 / Pisa</b> &middot; saw all 4',strip([0,1,2,3]))
 +ev2('9:41:08 am','Opened <b>IMG_2041.jpg</b><div class="d">Eurotrip 2026 / Rome</div>')
 +ev2('9:40:31 am','Played <b>MVI_0188.mov</b><div class="d">Eurotrip 2026 / Rome</div>')
 +ev2('9:26&ndash;9:38 am','Scrolled through <b>Eurotrip 2026 / Rome</b> &middot; saw 28 more',strip([0,1,2,3,0,1],'+22'))
 +'<div class="dayh">Oct 19</div>'
 +ev2('9:25:02 am','Saved the original of <b>IMG_2057.jpg</b><div class="d">Eurotrip 2026 / Rome</div>')
 +ev2('9:16&ndash;9:25 am','Scrolled through <b>Eurotrip 2026 / Rome</b> &middot; saw 12, opened 4',strip([0,1,2,3,0,1],'+6'))
 +ev2('9:14:05 am','Opened the link, then the <b>front page</b> &middot; saw 12',strip([2,3,0,1]))
 +'<div class="note">Scrolling is shown as one line per stretch, with a strip of what came into view. Opened, played and saved items get their own line with the exact time. The latest 300 lines are kept per link; older ones are folded into the counts in the Folders and Media tabs.</div></div>')
bI=('<div class="pg" style="width:960px;padding:22px 24px"><div class="pn">'
 '<div class="lab">1 &middot; Press an IP address anywhere in the log: what that IP saw, opened and saved, by folder</div>'+q1+
 '<div class="lab" style="margin-top:22px">2 &middot; Media: every photo and video that reached their screen, and what they did with it</div>'+q2+
 '<div class="lab" style="margin-top:22px">3 &middot; Timeline: in order</div>'+q3+
 '<div class="lab" style="margin-top:22px">4 &middot; And a list of every IP across all links (a second switch at the top of the Share links tab)</div>'+p4+'</div></div>')
def mrowP2(r):
    i,name,folder,seen,opened,saved,last,video,priv=r
    tag=(opened or 'Seen only')+(' &middot; <span class="chk">&check; saved</span>' if saved else '')
    return '<div class="vm" style="display:flex;gap:10px"><span style="flex:none">%s</span><div style="min-width:0"><div class="nm" style="font-family:ui-monospace,Menlo,monospace;font-size:12.5px">%s</div><div class="s">%s</div><div class="s">First seen %s</div><div class="s" style="color:%s">%s</div></div></div>'%(tn(i,video,priv),name,folder,seen,'#c9c9ce' if opened else '#8a8a8f',tag)
bIP=('<div class="pg" style="width:390px;padding:16px 12px"><div class="pn" style="padding:14px 12px"><div class="tx" style="margin-bottom:8px">&lsaquo; Aunt Carol</div><div class="ipH" style="font-size:18px">203.0.113.42</div><div class="mut" style="margin-top:3px">United States &middot; Safari on iPhone</div>'
 '<div style="margin-top:8px"><span class="lnkchip">Aunt Carol</span><span class="lnkchip">Dave</span></div>'+kpi2([('56','seen'),('13','opened'),('1','saved')]).replace('class="kpi"','class="kpi" style="gap:20px"')
 +'<div class="seg2" style="margin-top:12px">'+segb(['Folders','Media','Timeline'],'Media')+'</div><div class="btnrow" style="margin:10px 0 4px"><div class="sel" style="width:170px">Everything seen %s</div><div class="sel" style="width:150px">Most recent %s</div></div>'%(DOWN,DOWN)
 +''.join(mrowP2(r) for r in rows2[2:7])+'<div class="note">Showing 5 of 56. Press a row to open it in the gallery.</div></div></div>')

def board(fn,title,html,w,h):
    doc='<!doctype html>\n<html lang="en">\n<head>\n<meta charset="utf-8">\n<title>'+title+'</title>\n<script src="./support.js"></script>\n</head>\n<body>\n<x-dc>\n<helmet>\n<style>'+CSS3+'</style>\n</helmet>\n'+html+'\n</x-dc>\n<script type="text/x-dc" data-dc-script data-props=\'{"$preview":{"width":%d,"height":%d}}\'>\nclass Component extends DCLogic {\n  renderVals() { return {}; }\n}\n</script>\n</body>\n</html>\n'%(w,h)
    open(P+fn,'w').write(doc)
H=json.loads(os.environ.get('LH','{}'))
spec=[('LinksIP.dc.html','Share links IP',bI,960,H.get("LinksIP",2790),0,16600,'Share links 9 · One IP address: what it saw, opened and saved; a timeline; and all IPs'),
      ('LinksIPPhone.dc.html','Share links IP phone',bIP,390,H.get("LinksIPPhone",900),1040,16600,'Share links 10 · One IP address, phone')]
c=json.load(open(P+'canvas.json'))
for fn,t,h,w,ht,x,y,ct in spec:
    board(fn,t,h,w,ht)
    c['boards'][fn]={"x":x,"y":y,"w":w,"h":ht,"title":ct}
    if fn not in c['order']: c['order'].append(fn)
json.dump(c,open(P+'canvas.json','w'),indent=2)
print('ok')
