import json,os,sys
S=os.environ['S']; P=S+'/mock/project/'
src=open(S+'/patch/mk_links4.py').read().split("\nH=json.loads(os.environ")[0]
exec(src)
LINKI2=LINKI
CLOSEI=I('<path d="M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0zM8.4 8.4l7.2 7.2M15.6 8.4l-7.2 7.2"/>')
ZOOMI=I('<path d="M17.5 11a6.5 6.5 0 1 1-13 0 6.5 6.5 0 0 1 13 0zM20 20l-4.2-4.2M11 8.5v5M8.5 11h5"/>')
DLI=I('<path d="M12 4v11M7.5 10.5 12 15l4.5-4.5M5 19.5h14"/>')
CSS3=CSS3+"""
.vw{background:#000;border:1px solid #26262a;border-radius:12px;overflow:hidden;position:relative}
.vbar{height:52px;display:flex;align-items:center;padding:0 10px 0 18px;gap:6px}
.vbar .cnt{font-size:13.6px;color:#8a8a8f;flex:1}
.vbar .b{width:40px;height:40px;display:flex;align-items:center;justify-content:center;color:#8a8a8f;font-size:18px;border-radius:8px;position:relative}
.vbar .b.lit{color:#aab2ff;background:rgba(170,178,255,0.12)}
.vph{height:300px;margin:0 18px 18px;border-radius:8px}
.pop{position:absolute;right:56px;top:50px;width:420px;background:#1f1f22;border:1px solid #34343a;border-radius:12px;padding:14px 14px 12px;box-shadow:0 12px 34px rgba(0,0,0,0.55);z-index:3}
.pop h4{margin:0 0 4px;font-size:15px;display:flex;gap:8px;align-items:center}
.pop h4 .i{color:#ff8a8c}
.pop p{margin:0 0 10px;font-size:13px;color:#b9b9c0;line-height:1.45}
.pop .btn{width:100%;justify-content:center;margin-top:6px}
.selbar{display:flex;align-items:center;gap:10px;padding:10px 14px;background:#1a1a1c;border:1px solid #34343a;border-radius:12px;flex-wrap:wrap}
.pht{width:78px;height:78px;border-radius:8px;position:relative;flex:none}
.pht .x{position:absolute;top:-6px;right:-6px;width:20px;height:20px;border-radius:50%;background:#34343a;border:1px solid #55555c;display:flex;align-items:center;justify-content:center;font-size:12px;color:#eaeaea}
"""
def board(fn,title,html,w,h):
    doc='<!doctype html>\n<html lang="en">\n<head>\n<meta charset="utf-8">\n<title>'+title+'</title>\n<script src="./support.js"></script>\n</head>\n<body>\n<x-dc>\n<helmet>\n<style>'+CSS3+'</style>\n</helmet>\n'+html+'\n</x-dc>\n<script type="text/x-dc" data-dc-script data-props=\'{"$preview":{"width":%d,"height":%d}}\'>\nclass Component extends DCLogic {\n  renderVals() { return {}; }\n}\n</script>\n</body>\n</html>\n'%(w,h)
    open(P+fn,'w').write(doc)
def viewer(cnt,linkbtn,pop='',extra_dl=True,w=None):
    icons='<span class="b">%s</span>'%ZOOMI+('<span class="b">%s</span>'%DLI if extra_dl else '')+(linkbtn)+'<span class="b">%s</span><span class="b">%s</span>'%(PIN,CLOSEI)
    return '<div class="vw"%s><div class="vbar"><span class="cnt">%s</span>%s</div><div class="vph" style="background:%s"></div>%s</div>'%((' style="width:%dpx"'%w) if w else '',cnt,icons,G[1],pop)
LINKB='<span class="b lit">%s</span>'%LINKI
popup=('<div class="pop"><h4><span class="i">%s</span>This photo is private</h4><p>A plain link only opens for people who already have access. To let someone see just this photo, make a share link for it.</p>'
 '<div class="btnrow" style="gap:8px;flex-wrap:nowrap"><a class="btn pri" style="flex:1;width:auto;margin:0">%s Share just this photo&hellip;</a><a class="btn" style="width:auto;margin:0">%s Copy link</a></div><div class="note" style="margin:10px 0 0">Share links are managed in the Share links tab.</div></div>')%(LOCK,SHARE,COPY)
# Board 17
panelA='<div><div class="lab">1 &middot; You, on a private photo: the link button asks what you mean</div>'+viewer('3 / 52',LINKB,popup)+'</div>'
panelB='<div><div class="lab">2 &middot; Anyone else on a private photo (a link holder, or private-view): no link button, because the link would be useless</div>'+viewer('3 / 8','')+'</div>'
panelC='<div><div class="lab">3 &middot; Any photo that is public, for everyone: the link button as it is today</div>'+viewer('3 / 52',LINKB)+'</div>'
cards=('<div class="pn" style="margin:0"><div class="lab" style="margin-bottom:10px">4 &middot; The same idea on folders: the link icon on a private folder&rsquo;s card</div><div style="display:flex;gap:20px;align-items:flex-start;flex-wrap:wrap">'
 '<div style="width:260px"><div class="card2" style="background:%s;height:130px"><div class="cap">Rome<span>42 photos</span></div><span class="ib" style="position:absolute;right:10px;top:10px;width:30px;height:30px;background:rgba(0,0,0,0.55);font-size:15px">%s</span></div><div class="note">For you: opens the same question, &ldquo;Share this folder&hellip;&rdquo;.</div></div>'
 '<div style="width:260px"><div class="card2" style="background:%s;height:130px"><div class="cap">Rome<span>42 photos</span></div></div><div class="note">For a link holder or private-view visitor: no link icon on a private folder.</div></div>'
 '<div style="width:260px"><div class="card2" style="background:%s;height:130px"><div class="cap">Florence<span>31 photos</span></div><span class="ib" style="position:absolute;right:10px;top:10px;width:30px;height:30px;background:rgba(0,0,0,0.55);font-size:15px">%s</span></div><div class="note">A public folder keeps the icon for everyone.</div></div></div></div>')%(G[0],LINKI,G[0],G[2],LINKI)
b17='<div class="pg" style="width:1160px;padding:22px 24px"><div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">'+panelA+panelB+'</div><div style="margin-top:20px;max-width:560px">'+panelC+'</div><div style="margin-top:22px">'+cards+'</div></div>'
# Board 18: photo scope
phs=''.join('<span class="pht" style="background:%s"><span class="x">&times;</span></span>'%G[i%4] for i in range(3))
quick2=('<div class="dlg" style="width:460px"><h3>Share just this photo</h3><div style="display:flex;gap:14px;margin:6px 0 14px;align-items:center"><span class="pht" style="background:%s;width:64px;height:64px"></span><div><div style="font-size:14px;font-weight:600">IMG_2041.jpg</div><div class="mut">Eurotrip 2026 / Rome &middot; private</div></div></div>'
 '<div class="fl"><div class="k">Who is it for</div><div class="inp">Aunt Carol</div></div>'
 '<div class="fl"><div class="k">Opens</div>'+segb(['Once','5','10','Unlimited'],'Once')+'</div>'
 '<div class="fl"><div class="k">Stops working</div>'+segb(['1 day','1 week','30 days','Never'],'1 week')+'</div>'
 '<div style="margin:0 0 6px;font-size:12.5px;color:#8a8a8f">They can look at this photo only. Download off, location as the public sees it. <a class="tx" style="font-size:12.5px">More options&hellip;</a></div>'
 '<div class="bt"><a class="btn">Cancel</a><a class="btn pri">Create link</a></div></div>')%G[1]
scopeform=('<div class="pn" style="width:560px;margin:0"><h2 style="margin-bottom:6px">New share link</h2><div class="sec first">What they can see</div>'
 '<div class="fl"><div class="rad"><span class="rd"></span><span>Everything private</span></div><div class="rad"><span class="rd"></span><span>Only some folders</span></div>'
 '<div class="rad"><span class="rd on"></span><span>Only some photos<div class="h" style="margin:2px 0 0">Only the photos you pick, wherever they are. They see nothing else of the folder.</div><div class="grid" style="margin-top:10px;gap:12px">'+phs+'<span class="pht" style="background:#26262a;border:1px dashed #55555c;display:flex;align-items:center;justify-content:center;color:#aab2ff;font-size:12.5px;text-align:center">+ Add photos</span></div></span></div></div>'
 '<div class="fl" style="margin-top:12px"><div class="k">Opens in</div><div class="sel" style="width:340px"><span>The first photo</span>@D@</div><div class="h">Straight into the viewer.</div></div></div>').replace("@D@",DOWN)
b18=('<div class="pg" style="width:1240px;padding:22px 24px"><div style="display:flex;gap:28px;align-items:flex-start"><div><div class="lab">1 &middot; &ldquo;Share just this photo&hellip;&rdquo; from the link button: a short form, already set to this photo</div><div style="background:rgba(0,0,0,0.5);border-radius:12px;padding:22px">'+quick2+'</div></div>'
 '<div><div class="lab">2 &middot; In the full New link form, a third choice of what they can see</div>'+scopeform+'</div></div></div>')
# Board 19: select mode + what holder sees
selbar='<div class="selbar"><span style="font-size:14px;font-weight:600">3 selected</span><span class="sp"></span><a class="btn sm">Make private</a><a class="btn sm">Make public</a><a class="btn sm" style="border-color:#5865f2;color:#c9ceff">%s Share&hellip;</a><a class="tx" style="margin-left:6px">Cancel</a></div>'%SHARE
tiles=''.join('<span style="width:104px;height:104px;border-radius:8px;display:block;background:%s;%s"></span>'%(G[i%4],'outline:3px solid #5865f2;outline-offset:-3px' if i in (0,2,3) else '') for i in range(8))
sel=('<div class="pn" style="margin:0"><div class="lab">1 &middot; Select mode, with a few photos picked: Share&hellip; sits beside Make private and Make public</div>'+selbar+'<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px">'+tiles+'</div><div class="note">Share&hellip; opens the same short form, set to &ldquo;Only some photos&rdquo; with these three.</div></div>')
phoneV=('<div style="width:340px;height:520px;box-sizing:border-box;background:#000;border-radius:28px;border:1px solid #26262a;overflow:hidden"><div style="height:44px"></div><div class="vbar" style="padding:0 6px 0 16px"><span class="cnt">1 / 1</span><span class="b">%s</span><span class="b">%s</span></div><div style="height:380px;margin:6px 0;background:%s"></div></div>')%(PIN if False else ZOOMI,CLOSEI,G[1])
phoneF=('<div style="width:340px;height:520px;box-sizing:border-box;background:#0f0f10;border-radius:28px;border:1px solid #26262a;overflow:hidden"><div style="height:44px"></div><div style="padding:8px 16px 0;font-size:20px;font-weight:700">Rome</div><div style="padding:4px 16px 0;font-size:13px;color:#8a8a8f">1 photo</div><div style="padding:14px 16px"><span style="width:100%%;height:200px;border-radius:8px;display:block;background:%s"></span></div><div style="padding:0 16px;font-size:12.5px;color:#8a8a8f">Nothing else from this folder is shown.</div></div>')%G[1]
holder=('<div style="display:flex;gap:24px;margin-top:22px"><div><div class="lab">2 &middot; What the link holder sees: the viewer, one photo, no arrows</div>'+phoneV+'</div><div><div class="lab">3 &middot; After closing it: the folder, with only the shared photo</div>'+phoneF+'</div></div>')
b19='<div class="pg" style="width:760px;padding:22px 24px">'+sel+holder+'</div>'
H=json.loads(os.environ.get('LH','{}'))
spec=[('LinksPhotoShare.dc.html','Share links photo share',b17,1160,H.get('A',1080),0,22400,'Share links 17 · The link button on private media: ask the owner, hide it from everyone else'),
 ('LinksPhotoScope.dc.html','Share links photo scope',b18,1240,H.get('B',545),1300,22400,'Share links 18 · Share just this photo: the short form, and a third scope'),
 ('LinksPhotoSelect.dc.html','Share links photo select',b19,760,H.get('C',975),0,23500,'Share links 19 · Share a few photos from Select mode; what the holder sees')]
c=json.load(open(P+'canvas.json'))
for fn,t,h,w,ht,x,y,ct in spec:
    board(fn,t,h,w,ht)
    c['boards'][fn]={"x":x,"y":y,"w":w,"h":ht,"title":ct}
    if fn not in c['order']: c['order'].append(fn)
json.dump(c,open(P+'canvas.json','w'),indent=2)
print('ok')
