import json,os,sys,base64
S=os.environ['S']; P=S+'/mock/project/'
sys.path.insert(0,S+'/patch')
import qrmini
base=open(S+'/patch/mk_links.py').read().split("b1='<div class=\"pg\"")[0]   # CSS + icon helpers + head/netcheck/vtable/rtable/vcards/FULL
exec(base)
G=['linear-gradient(160deg,#6d86a8,#b9a98b 55%,#4a3f36)','linear-gradient(160deg,#9a7b5b,#d9b98a 50%,#5b3f2e)','linear-gradient(160deg,#4d6b57,#a7b79a 55%,#2f3b33)','linear-gradient(160deg,#7a6a9a,#c3b0d9 55%,#3b2f4d)']
LOCK=I('<rect x="5" y="11" width="14" height="9.5" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>')
SHARE=I('<path d="M12 15V4M8 7.5 12 3.5l4 4M6 11H5.5A1.5 1.5 0 0 0 4 12.5v6A1.5 1.5 0 0 0 5.5 20h13a1.5 1.5 0 0 0 1.5-1.5v-6A1.5 1.5 0 0 0 18.5 11H18"/>')
EYE=I('<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="2.8"/>')
EXPI=I('<path d="M12 4v11M7.5 9.5 12 4.5l4.5 5M5 19.5h14"/>')
QRI=I('<rect x="4" y="4" width="6.5" height="6.5" rx="1"/><rect x="13.5" y="4" width="6.5" height="6.5" rx="1"/><rect x="4" y="13.5" width="6.5" height="6.5" rx="1"/><path d="M14 14h2.5v2.5H14zM18.5 14H20M14 19h.01M17 18.5h3V20"/>')
IMG=I('<rect x="4" y="5" width="16" height="14" rx="2.5"/><circle cx="9" cy="10" r="1.6"/><path d="m5 17 5-5 3.5 3.5L16 13l3 3"/>')
FOLDER=I('<path d="M3.5 7.5a2 2 0 0 1 2-2h4l2 2.2h7a2 2 0 0 1 2 2V17.5a2 2 0 0 1-2 2h-13a2 2 0 0 1-2-2z"/>')
ICON64=base64.b64encode(open(S+'/qr/icon.png','rb').read()).decode()
def qr_svg(text,px):
    g,v,m=qrmini.encode(text); n=len(g); q=4; T=n+2*q
    rects=[]
    for y,row in enumerate(g):
        x=0
        while x<n:
            if row[x]:
                s=x
                while x<n and row[x]: x+=1
                rects.append('M%d %dh%dv1h-%dz'%(s+q,y+q,x-s,x-s))
            else: x+=1
    c=T/2; plate=11; ic=8.6
    return ('<svg viewBox="0 0 %d %d" width="%d" height="%d" xmlns="http://www.w3.org/2000/svg" style="display:block;background:#fff;border-radius:10px"><rect width="%d" height="%d" fill="#fff"/><path d="%s" fill="#000"/>'
      '<rect x="%.2f" y="%.2f" width="%d" height="%d" rx="1.6" fill="#fff"/><image href="data:image/png;base64,%s" x="%.2f" y="%.2f" width="%.1f" height="%.1f" clip-path="inset(0 round 1.2px)"/></svg>')%(T,T,px,px,T,T,''.join(rects),c-plate/2,c-plate/2,plate,plate,ICON64,c-ic/2,c-ic/2,ic,ic)
QRURL='https://pcmike.net/trip/k7QpR2mXv9LwTb4e'
CSS2=CSS+'''
.tabs{display:flex;gap:4px;border-bottom:1px solid #26262a;margin:-4px 0 16px;overflow:hidden}
.tab{padding:9px 14px;font-size:14px;color:#a0a0a8;display:flex;gap:7px;align-items:center;white-space:nowrap}
.tab.on{color:#fff;box-shadow:inset 0 -2px 0 #5865f2}
.bdg{min-width:18px;height:18px;border-radius:999px;background:#5865f2;color:#fff;font-size:11.5px;font-weight:700;display:inline-flex;align-items:center;justify-content:center;padding:0 5px;box-sizing:border-box}
.newt{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;color:#aab2ff;margin-left:2px}
.newt::before{content:"";width:7px;height:7px;border-radius:50%;background:#5865f2}
.st.pause{background:rgba(224,160,40,0.13);color:#e0a028;border:1px solid rgba(224,160,40,0.45)}
.sec{font-size:11.5px;letter-spacing:.07em;text-transform:uppercase;color:#8a8a8f;margin:22px 0 10px;padding-top:16px;border-top:1px solid #26262a}
.sec.first{border-top:0;margin-top:4px;padding-top:0}
.ta{border:1px solid #34343a;background:#0f0f10;border-radius:8px;padding:10px 12px;font-size:14px;line-height:1.45;min-height:52px;box-sizing:border-box;color:#eaeaea}
.cnt2{font-size:12px;color:#6f6f77;text-align:right;margin-top:4px}
.sel{height:36px;border:1px solid #34343a;background:#0f0f10;border-radius:8px;padding:0 12px;display:flex;align-items:center;justify-content:space-between;font-size:14px;box-sizing:border-box;gap:10px}
.sel .i{color:#8a8a8f}
.seg2{display:flex;margin:0 0 4px}
.seg2 .seg span{padding:6px 13px}
.btnrow{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.th{position:relative;width:104px;height:104px;border-radius:8px;overflow:hidden;display:block}
.th .v{position:absolute;left:5px;bottom:5px;height:20px;padding:0 7px;border-radius:999px;background:rgba(0,0,0,0.66);font-size:11.5px;display:flex;align-items:center;color:#fff;box-sizing:border-box}
.th .lk{position:absolute;right:5px;top:5px;width:20px;height:20px;border-radius:50%;background:rgba(237,66,69,0.92);display:flex;align-items:center;justify-content:center;font-size:12px;color:#fff}
.grid{display:flex;flex-wrap:wrap;gap:10px;margin-top:10px}
.fb{display:grid;grid-template-columns:1.9fr .9fr .6fr .5fr 1fr;gap:10px;align-items:center;font-size:13px;padding:9px 0;border-bottom:1px solid #1f1f22}
.fb.h{color:#8a8a8f;font-size:11.5px;letter-spacing:.05em;text-transform:uppercase;padding:0 0 6px}
.fb:last-child{border-bottom:0}
.fb .nm{display:flex;gap:8px;align-items:center}
.fb .nm .i{color:#8a8a8f;font-size:16px}
.fb .nm .i.red{color:#ff8a8c}
.bar{height:6px;border-radius:3px;background:#26262a;margin-top:5px;overflow:hidden}
.bar i{display:block;height:100%;background:#5865f2}
.fchip{display:inline-flex;align-items:center;gap:6px;height:28px;padding:0 6px 0 11px;border-radius:999px;background:rgba(88,101,242,0.18);border:1px solid rgba(88,101,242,0.6);color:#c9ceff;font-size:12.5px}
.qrbox{display:flex;gap:18px;align-items:flex-start}
.qrbox .cap{font-size:12.5px;color:#8a8a8f;margin-top:8px;text-align:center}
.card{background:#fff;color:#111;border-radius:18px;padding:22px;display:flex;flex-direction:column;align-items:center;gap:12px}
.banner{display:flex;align-items:center;gap:10px;padding:10px 12px;background:#1f2233;border-bottom:1px solid #2f3350;font-size:13px;line-height:1.35;color:#d9dcff}
.banner .i{font-size:16px;color:#aab2ff}
.banner .x{margin-left:auto;color:#8a8a8f;font-size:18px;display:flex}
.note{font-size:12.5px;color:#8a8a8f;margin-top:10px;line-height:1.45}
.q{margin:6px 0 18px;padding:12px 14px;border-left:3px solid #5865f2;background:#141416;border-radius:0 8px 8px 0;font-size:15px;line-height:1.45;color:#e4e4e8;text-align:left;width:100%;box-sizing:border-box}
'''
def tabs(active='Share links',badge='1'):
    items=[('Folder settings',''),('Private',''),('Share links',badge),('Site settings','')]
    return '<div class="tabs">'+''.join('<span class="tab%s">%s%s</span>'%(' on' if n==active else '',n,('<span class="bdg">%s</span>'%b) if b else '') for n,b in items)+'</div>'
def segb(opts,on,extra=''):
    return '<div class="seg"%s>'%extra+''.join('<span%s>%s</span>'%(' class="on"' if o==on else '',o) for o in opts)+'</div>'
def th(i,views,priv=True):
    return '<span class="th" style="background:%s"><span class="v">%s</span>%s</span>'%(G[i%4],views,('<span class="lk">%s</span>'%LOCK) if priv else '')
CHIPQ='<span class="chip">%s &times;</span>'
# ---------------- Board A: list
facts='''<div class="kv" style="grid-template-columns:150px 1fr"><span>Who can see</span><span>Everything private</span><span>Original downloads</span><span>Everywhere they can see</span><span>ZIP of a folder</span><span>Off</span><span>Photo locations</span><span>As the public sees them</span><span>Starts</span><span>Immediately</span><span>Ends</span><span>Sun, Oct 25, 6:00 pm &middot; 6 days left</span><span>Heads-up header</span><span>On</span><span>Note shown</span><span>&ldquo;Hi Carol, here are the Rome photos&hellip;&rdquo;</span></div>'''
SHBTNS='<div class="btnrow" style="margin-bottom:12px"><a class="btn sm">%s Copy</a><a class="btn sm">%s Share&hellip;</a><a class="btn sm">%s QR code</a></div>'%(COPY,SHARE,QRI)
vis1=[('203.0.113.42','US','Safari on iPhone','Oct 19, 9:14 am','Today, 9:52 am','1','14'),
      ('198.51.100.7','US','Safari on Mac','Oct 19, 7:30 am','Oct 19, 8:02 am','1','6')]
def vt2(rows):
    h='<div class="vh"><span>IP address</span><span>Where</span><span>Browser</span><span>First seen</span><span>Last seen</span><span>Opens</span><span>Viewed</span></div>'
    return h+''.join('<div class="vr"><span class="ip">%s</span><span>%s</span><span>%s</span><span>%s</span><span>%s</span><span>%s</span><span class="tx" style="font-size:13px">%s views &rsaquo;</span></div>'%r for r in rows)
exp=('<div class="bx"><div class="full"><div class="fld">'+QRURL+'</div></div>'+SHBTNS+facts
 +'<div class="seg2" style="margin-top:14px">'+segb(['Visitors','Folders','Photos'],'Visitors')+'</div>'
 +vt2(vis1)
 +'<div class="acts"><a class="btn sm">'+EYE+' Preview as this link</a><a class="btn sm">'+EXPI+' Export log &#9662;</a><span class="sp"></span><a class="tx">Edit&hellip;</a><a class="tx">Pause</a><a class="tx">Replace address&hellip;</a><a class="tx">Revoke</a><a class="tx red">Delete&hellip;</a></div>'
 +'<div class="note">An IP address is a device on a network, not a person: a phone moving from wifi to cellular looks like two. Opens count the &ldquo;Open the gallery&rdquo; presses; views count photos and folder pages looked at afterwards.</div></div>')
def lr2(st,stc,name,url,sub,body='',first=False,open_=False,btn='Copy link',new=''):
    b=('<a class="btn sm">%s%s</a>'%((COPY+' ') if btn=='Copy link' else '',btn)) if btn else ''
    return '<div class="lr%s"><div class="lh"><span class="st %s">%s</span><span class="ln">%s</span>%s<span class="lu">%s</span><span class="sp"></span>%s<span class="cc">%s</span></div><div class="ls">%s</div>%s</div>'%(' first' if first else '',stc,st,name,new,url,b,UP if open_ else DOWN,sub,body)
active=(lr2('Active','on','Aunt Carol','/trip/k7Qp&hellip;Tb4e','<b>2 of 5</b> opens'+SEP+'<b>2</b> IPs'+SEP+'20 views'+SEP+'ends Sun, Oct 25, 6:00 pm',exp,True,True,new='<span class="newt">1 new since you last looked</span>')
 +lr2('Paused','pause','Mark &amp; Jen','/trip/Zx83&hellip;9aQc','<b>7</b> opens, no limit'+SEP+'<b>4</b> IPs'+SEP+'211 views'+SEP+'paused Oct 17',btn='Resume'))
ended=(lr2('Used up','end','Photographer','/trip/Hn5d&hellip;Wy2k','<b>1 of 1</b> opens'+SEP+'<b>1</b> IP'+SEP+'<b style="color:#e0a028">2 blocked tries</b>'+SEP+'used up Oct 12',first=True,btn='')
 +lr2('Expired','end','Dave (work friend)','/trip/Qe7m&hellip;Lp0s','<b>3 of 10</b> opens'+SEP+'<b>2</b> IPs'+SEP+'ended Sun, Oct 12, 6:00 pm',btn='Extend&hellip;')
 +lr2('Revoked','rev','Old link','/trip/Bt1v&hellip;Rj6n','<b>4</b> opens'+SEP+'<b>2</b> IPs'+SEP+'revoked Oct 9',btn=''))
bA='<div class="pg" style="width:960px;padding:22px 24px">'+tabs()+netcheck()+'<div class="pn">'+head('Share links','2 not ended &middot; 3 ended')+'<div class="grp" style="margin-top:14px">Current</div>'+active+'<div class="grp">Ended <span style="text-transform:none;letter-spacing:0">&middot; the log is kept until you delete the link</span></div>'+ended+'</div><div class="note" style="margin-top:0">Pause is a switch you can turn back on. Revoke is final: the link stays in the list with its log, but can&rsquo;t be reactivated (Extend is only for links that ran out on their own).</div></div>'
# ---------------- Board B: activity
frows=[('Eurotrip 2026 / Rome',True,'31 of 42','112','2','Today, 9:52 am',100),
       ('Eurotrip 2026 / Pisa',True,'4 of 4','9','1','Oct 19, 8:02 am',8),
       ('Eurotrip 2026 (front page)',False,'&mdash;','14','2','Oct 19, 9:14 am',13)]
def frow(r):
    nm,priv,seen,views,ips,last,pct=r
    return '<div class="fb"><div><div class="nm">%s%s</div><div class="bar"><i style="width:%d%%"></i></div></div><span>%s photos</span><span>%s</span><span>%s</span><span>%s</span></div>'%(('<span class="i red">%s</span>'%LOCK) if priv else FOLDER,nm,pct,seen,views,ips,last)
fh='<div class="fb h"><span>Folder</span><span>Photos seen</span><span>Views</span><span>IPs</span><span>Last viewed</span></div>'
panelFolders='<div class="bx" style="margin-top:0">'+'<div class="seg2">'+segb(['Visitors','Folders','Photos'],'Folders')+'</div><div style="margin-top:12px"></div>'+fh+''.join(frow(r) for r in frows)+'<div class="note">A view is a folder page opened or a photo opened in the viewer. A lock marks a private folder. Press an IP address anywhere in this log to see only what that IP looked at.</div></div>'
thumbs=''.join(th(i,v,p) for i,(v,p) in enumerate([('&times;12',True),('&times;9',True),('&times;9',True),('&times;7',True),('&times;6',True),('&times;5',True),('&times;5',True),('&times;4',True),('&times;3',True),('&times;3',False),('&times;2',True),('&times;1',True)]))
thumbs2=''.join(th(i,v,p) for i,(v,p) in enumerate([('&times;8',True),('&times;6',True),('&times;4',True),('&times;4',True),('&times;2',True),('&times;1',True)]))
panelPhotos='<div class="bx" style="margin-top:0"><div class="seg2">'+segb(['Visitors','Folders','Photos'],'Photos')+'</div><div class="btnrow" style="margin-top:12px"><div class="sel" style="width:170px">Most viewed %s</div><div class="sel" style="width:230px">All IPs (2) %s</div><div class="sel" style="width:210px">All folders %s</div></div><div class="grid">%s</div><div class="note">Showing 12 of 35 photos looked at. A lock marks a photo that&rsquo;s private; each badge is how many times it was opened.</div></div>'%(DOWN,DOWN,DOWN,thumbs)
panelPhotosIP='<div class="bx" style="margin-top:0"><div class="seg2">'+segb(['Visitors','Folders','Photos'],'Photos')+'</div><div class="btnrow" style="margin-top:12px"><span class="fchip"><span class="ip">203.0.113.42</span> &middot; Safari on iPhone <span style="font-size:17px;color:#aab2ff;display:flex;padding:0 4px">&times;</span></span><div class="sel" style="width:170px">Most viewed %s</div></div><div class="grid">%s</div><div class="note">Only what 203.0.113.42 opened: 24 photos in 2 folders, 112 views. Press &times; to go back to everyone.</div></div>'%(DOWN,thumbs2)
head2='<div class="lh" style="margin-bottom:10px"><span class="st on">Active</span><span class="ln">Aunt Carol</span><span class="lu">/trip/k7Qp&hellip;Tb4e</span><span class="newt">1 new since you last looked</span></div>'
bB='<div class="pg" style="width:960px;padding:22px 24px"><div class="pn">'+head2+'<div class="lab">What they looked at &middot; folders</div>'+panelFolders+'<div class="lab" style="margin-top:20px">Photos, everyone</div>'+panelPhotos+'<div class="lab" style="margin-top:20px">Photos, after pressing one IP address</div>'+panelPhotosIP+'</div></div>'
# ---------------- Board C: form, two states
def fl(k,body,h=''):
    return '<div class="fl"><div class="k">%s</div>%s%s</div>'%(k,body,('<div class="h">%s</div>'%h) if h else '')
def mkform(scope):
    some=(scope=='some')
    chips=(CHIPQ%'Eurotrip 2026 / Rome')+(CHIPQ%'Eurotrip 2026 / Pisa')+'<span class="chip" style="color:#aab2ff">+ Add folder</span>'
    sees=('<div class="fl"><div class="rad"><span class="rd%s"></span><span>Everything private<div class="h" style="margin:2px 0 0">Every private folder and photo in the gallery, including ones you add later.</div></span></div>'
          '<div class="rad"><span class="rd%s"></span><span>Only some folders<div class="h" style="margin:2px 0 0">Each folder includes everything inside it. They are never told other private folders exist.</div>%s</span></div></div>')%('' if some else ' on',' on' if some else '','<div style="margin-top:4px">'+chips+'</div>' if some else '')
    opens=fl('Opens in','<div class="sel" style="width:340px"><span>%s</span>%s</div>'%('Eurotrip 2026 / Rome' if some else 'The front page',DOWN),
             'The first page they see. Pick from the folders above.' if some else 'The first page they see. You can pick any folder instead.')
    if some:
        dl=fl('Download originals',segb(['Off','In all chosen folders','Only in some of them'],'Only in some of them')+'<div style="margin-top:6px">'+(CHIPQ%'Eurotrip 2026 / Rome')+'<span class="chip" style="color:#aab2ff">+ Choose from Rome, Pisa</span></div>','Public photos follow the gallery&rsquo;s own download setting.')
        zp=fl('ZIP of a folder',segb(['Off','In all chosen folders','Only in some of them'],'Off'))
    else:
        dl=fl('Download originals',segb(['Off','Everywhere','Only in chosen folders'],'Everywhere'))
        zp=fl('ZIP of a folder',segb(['Off','Everywhere','Only in chosen folders'],'Off'))
    return ('<div class="pn" style="width:600px;margin:0"><h2 style="margin-bottom:6px">New share link</h2>'
     '<div class="sec first">Who is it for</div>'
     +fl('Name','<div class="inp">%s</div>'%('Aunt Carol' if some else 'Mark &amp; Jen'),'Only you see this name.')
     +fl('Note on the opening page (optional)','<div class="ta">%s</div><div class="cnt2">%s / 200</div>'%(('Hi Carol, here are the Rome photos. Say hi to Uncle Joe!','56') if some else ('Everything from the trip, enjoy!','31')))
     +fl('Title on the opening page and in message previews',segb(["The gallery&rsquo;s title",'A neutral title','My own text&hellip;'],'My own text&hellip;' if not some else "The gallery&rsquo;s title")+('<div class="inp" style="margin-top:8px;width:340px">Photos for Mark &amp; Jen</div>' if not some else ''),'A neutral title shows &ldquo;A shared gallery&rdquo;. The icon is always the gallery&rsquo;s, and no photo or folder name is ever shown.')
     +'<div class="sec">What they can see</div>'+sees+opens
     +'<div class="sec">What they can do</div>'+dl+zp
     +fl('Photo locations','<div class="rad" style="margin:0 0 6px"><span class="rd on"></span><span>As the public sees them<div class="h" style="margin:2px 0 0">Only places this gallery already shows to visitors. Others stay hidden, and are removed from any download or ZIP.</div></span></div><div class="rad" style="margin:0"><span class="rd"></span><span>All saved locations<div class="h" style="margin:2px 0 0">Every saved location, including ones the public can&rsquo;t see. Original files keep their GPS.</div></span></div>')
     +'<div class="sec">Timing</div>'
     +fl('Starts',segb(['Immediately','Pick a date&hellip;'],'Immediately'))
     +fl('How many times can it be opened?',segb(['Once','5','10','Unlimited','Other&hellip;'],'10' if some else 'Unlimited'),'Counted per browser, not per page. Once someone is in, they can keep looking until the link ends.')
     +fl('When does it stop working?',segb(['1 day','1 week','30 days','Pick a date&hellip;','Never'],'Pick a date&hellip;' if some else '30 days')+('<div class="inp" style="margin-top:8px;width:290px">Sun, Oct 25, 2026 &nbsp;at&nbsp; 6:00 pm</div>' if some else ''),'In your browser&rsquo;s time zone. People already inside are signed out at that moment.')
     +'<div class="sec">Heads-up for the person using it</div>'
     +fl('Show &ldquo;the link being used to access this media expires on&hellip;&rdquo; at the top of the gallery',segb(['Use the site setting (off)','On','Off'],'On' if some else 'Use the site setting (off)'),'A thin line they can dismiss. It turns amber in the last day. Not shown when there is no end date.')
     +'<div class="acts" style="margin-top:22px"><a class="btn pri">Create link</a><a class="btn">Cancel</a></div></div>')
bC=('<div class="pg" style="width:1360px;padding:22px 24px"><div style="display:flex;gap:24px;align-items:flex-start">'
    '<div><div class="lab">Everything private</div>'+mkform('all')+'</div>'
    '<div><div class="lab">Only some folders</div>'+mkform('some')+'</div></div></div>')
# ---------------- Board D: share, QR
qr180=qr_svg(QRURL,176); qr360=qr_svg(QRURL,330); qrph=qr_svg(QRURL,300)
panelShare=('<div class="pn" style="width:560px;margin:0"><div class="lh" style="margin-bottom:10px"><span class="st on">Active</span><span class="ln">Aunt Carol</span></div><div class="full"><div class="fld">'+QRURL+'</div></div>'
 +'<div class="btnrow"><a class="btn sm">%s Copy</a><a class="btn sm">%s Share&hellip;</a><a class="btn sm" style="border-color:#5865f2;color:#c9ceff">%s QR code</a></div>'%(COPY,SHARE,QRI)
 +'<div class="qrbox" style="margin-top:16px"><div>'+qr180+'</div><div style="padding-top:2px"><div style="font-size:14px;font-weight:600;margin-bottom:10px">Scan to open</div><div class="btnrow" style="flex-direction:column;align-items:flex-start;gap:8px"><a class="btn sm">%s Show large</a><a class="btn sm">%s Copy image</a><a class="btn sm">Save image</a></div><div class="note" style="max-width:220px">Copy image puts the QR on the clipboard as a picture, to paste into a message. Share&hellip; opens the phone&rsquo;s own share sheet.</div></div></div>'%(IMG,COPY)
 +'<div class="note">Ends Sun, Oct 25, 6:00 pm &middot; up to 10 opens. You can copy it again any time from the list.</div></div>')
dlgL=('<div style="width:440px;height:520px;box-sizing:border-box;background:rgba(0,0,0,0.62);display:flex;align-items:center;justify-content:center;border-radius:12px"><div class="card" style="width:380px">'+qr360+'<div style="font-size:17px;font-weight:700">Aunt Carol</div><div style="font-size:13px;color:#666">Scan with the camera to open</div><div class="btnrow"><a class="btn sm" style="background:#eee;color:#111;border-color:#ddd">%s Copy image</a><a class="btn sm" style="background:#111;color:#fff;border-color:#111">Done</a></div></div></div>'%COPY)
phoneL=('<div style="width:340px;height:560px;box-sizing:border-box;background:#fff;color:#111;border-radius:28px;border:1px solid #26262a;padding:48px 20px 24px;display:flex;flex-direction:column;align-items:center;gap:14px">'+qrph+'<div style="font-size:20px;font-weight:700;margin-top:6px">Aunt Carol</div><div style="font-size:14px;color:#666;text-align:center">Hold this up to their camera.<br>Stays on screen until you press Done.</div><div style="margin-top:auto;width:100%;height:46px;border-radius:12px;background:#111;color:#fff;font-weight:600;display:flex;align-items:center;justify-content:center">Done</div></div>')
bD=('<div class="pg" style="width:1100px;padding:22px 24px"><div style="display:flex;gap:24px;align-items:flex-start"><div><div class="lab">1 &middot; Right after Create link (the QR button opens this part)</div>'+panelShare+'</div><div><div class="lab">2 &middot; Show large, on a desktop</div>'+dlgL+'</div></div>'
 +'<div style="display:flex;gap:24px;align-items:flex-start;margin-top:24px"><div><div class="lab">3 &middot; Show large, on a phone: fills the screen, white, easy to scan across a table</div>'+phoneL+'</div><div style="width:560px;font-size:13.5px;color:#b9b9c0;line-height:1.55;padding-top:26px">The gallery&rsquo;s own icon sits in the middle. A QR code can lose about a third of itself and still scan, and the icon covers well under a tenth, so it still reads fine.<br><br>The code in these boards is a real one for a made-up link, so a phone can scan it but it opens nothing.</div></div></div>')
# ---------------- Board E: visitor
v1='<div><div class="lab">1 &middot; Opening the link</div><div class="ph" style="width:340px;height:470px"><div class="ico">%s</div><h1>Eurotrip 2026</h1><div class="q">Hi Carol, here are the Rome photos. Say hi to Uncle Joe!</div><div class="big">Open the gallery</div><div class="small" style="margin-top:14px">This link works until Sunday, Oct 25.</div></div></div>'%LINKI
v2='<div><div class="lab">2 &middot; Expired, used up, revoked, paused, or mistyped (all look the same)</div><div class="ph" style="width:340px;height:470px"><div class="ico" style="color:#8a8a8f">%s</div><h1>This link isn&rsquo;t valid</h1><p>Please let the person who sent it to you know.</p></div></div>'%WARN
gal=''.join('<span style="flex:1;height:96px;border-radius:6px;background:%s"></span>'%G[i%4] for i in range(3))
v3=('<div><div class="lab">3 &middot; Inside the gallery: the heads-up (if on), until dismissed</div><div style="width:340px;height:470px;box-sizing:border-box;background:#0f0f10;border-radius:28px;border:1px solid #26262a;overflow:hidden"><div style="height:44px"></div>'
 '<div class="banner">%s<span>The link being used to access this media expires on Sun, Oct 25, 6:00 pm.</span><span class="x">&times;</span></div>'
 '<div style="padding:14px 14px 0;font-size:20px;font-weight:700">Rome</div><div style="padding:4px 14px 0;font-size:13px;color:#8a8a8f">42 photos</div>'
 '<div style="padding:12px 14px;display:flex;gap:8px">%s</div><div style="padding:0 14px;display:flex;gap:8px">%s</div><div style="padding:8px 14px;display:flex;gap:8px">%s</div></div></div>')%(CHECK.replace('<path d="M4.5 12.5 9.5 17.5 19.5 7"/>','<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>'),gal,gal,gal)
bE='<div class="pg" style="width:1180px;padding:22px 24px;display:flex;gap:24px">'+v1+v2+v3+'</div>'
# ---------------- Board F: settings
bF='''<div class="pg" style="width:960px;padding:22px 24px"><div class="pn"><h2>Share links</h2>
<div class="srow"><span class="tg"></span><div><div class="nm">Allow share links</div><div class="hp">Lets you make temporary links in the Share links tab. With this off, links stop working (they look invalid to visitors) and nothing is deleted.</div></div></div>
<div class="srow"><div style="flex:1"><div class="nm">Short address</div><div class="hp">Links look like <span style="font-family:ui-monospace,Menlo,monospace">https://pcmike.net/trip/k7Qp&hellip;</span> when the server passes them to the gallery.</div>
<div class="ok-b good" style="margin:10px 0 0">%s<div><b>Works.</b> Checked just now from this browser.</div><span class="lkx">Show the rule</span></div>
<div class="ok-b amber" style="margin:10px 0 0">%s<div><b>Not set up yet.</b> Links will look like <span style="font-family:ui-monospace,Menlo,monospace;font-size:12.5px">/trip/?link=k7Qp&hellip;</span> instead, which works without any server change.</div><span class="lkx">Show the rule</span></div>
<div class="lab" style="margin-top:6px">(Two states drawn: you would see one of them.)</div></div></div>
<div class="srow"><span class="tg off"></span><div><div class="nm">Heads-up for link holders, by default</div><div class="hp">A thin line at the top of the gallery for someone using a share link: &ldquo;The link being used to access this media expires on Sun, Oct 25, 6:00 pm.&rdquo; Each link can override this. Links with no end date never show it.</div></div></div>
<div class="srow"><div style="flex:1"><div class="nm">Where visitors&rsquo; IP addresses come from</div>
<div class="seg" style="margin:8px 0 0"><span>Directly</span><span class="on">Cloudflare</span><span>Another proxy&hellip;</span></div>
<div class="hp">The gallery reads the visitor&rsquo;s address from Cloudflare&rsquo;s header, and only when the request really comes from Cloudflare. Without this, every visitor shows as a Cloudflare address, in the link logs and in the sign-in throttle.</div>
<div class="hp" style="margin-top:8px">Your address as the gallery sees it: <b style="color:#eaeaea;font-family:ui-monospace,Menlo,monospace">203.0.113.42</b> (via Cloudflare, United States)</div></div></div>
<div class="srow"><div style="flex:1"><div class="nm">Cloudflare caching</div><div class="hp">If Cloudflare keeps copies of your public photos, those requests never reach this gallery. So what a link holder has <b style="color:#eaeaea">seen</b> is reported by their own browser as photos come on screen (this needs JavaScript), not counted from image requests. Pages for anyone using a link are never stored by Cloudflare.</div></div></div>
<div class="srow"><div style="flex:1"><div class="nm">Link logs</div><div class="hp">Each link keeps its visitors&rsquo; IP addresses, browsers and times, the folders and photos they opened, and any blocked tries, for as long as the link exists. Revoked, paused and expired links keep theirs. Only deleting a link deletes its log.</div></div></div>
</div></div>'''%(CHECK,WARN)
# ---------------- Board G: phone list
def vcards2(rows):
    return ''.join('<div class="vm"><div class="t"><span class="ip">%s</span><span class="mut">%s</span><span class="sp"></span><span class="tx" style="font-size:12.5px">%s views &rsaquo;</span></div><div class="s">%s &middot; %s opens<br>%s &middot; last %s</div></div>'%r for r in rows)
vp=[('203.0.113.42','US','14','Safari on iPhone','1','Oct 19, 9:14 am','today, 9:52 am'),('198.51.100.7','US','6','Safari on Mac','1','Oct 19, 7:30 am','Oct 19, 8:02 am')]
expP=('<div class="bx" style="padding:12px"><div class="full"><div class="fld" style="font-size:12px">pcmike.net/trip/k7QpR2mXv9LwTb4e</div></div><div class="btnrow" style="margin-bottom:10px"><a class="btn sm">%s Copy</a><a class="btn sm">%s Share</a><a class="btn sm">%s QR</a></div>'%(COPY,SHARE,QRI)
 +'<div class="kv" style="grid-template-columns:104px 1fr"><span>Who can see</span><span>Everything private</span><span>Originals</span><span>Everywhere</span><span>ZIP</span><span>Off</span><span>Locations</span><span>As the public</span><span>Ends</span><span>Sun, Oct 25, 6:00 pm</span></div>'
 +'<div class="seg2" style="margin-top:10px">'+segb(['Visitors','Folders','Photos'],'Visitors')+'</div>'+vcards2(vp)
 +'<div class="btnrow" style="margin-top:12px"><a class="btn sm">'+EYE+' Preview</a><a class="btn sm">'+EXPI+' Export &#9662;</a></div><div class="acts" style="gap:14px"><a class="tx">Edit&hellip;</a><a class="tx">Pause</a><a class="tx">Replace address&hellip;</a><a class="tx">Revoke</a><a class="tx red">Delete&hellip;</a></div></div>')
def lrP(st,stc,name,url,sub,body='',first=False,open_=False,new=''):
    return '<div class="lr%s"><div class="lh"><span class="st %s">%s</span><span class="ln">%s</span><span class="sp"></span><span class="cc">%s</span></div>%s<div class="lu" style="margin-top:3px">%s</div><div class="ls">%s</div>%s</div>'%(' first' if first else '',stc,st,name,UP if open_ else DOWN,new,url,sub,body)
listP=(lrP('Active','on','Aunt Carol','/trip/k7Qp&hellip;Tb4e','<b>2 of 5</b> opens'+SEP+'<b>2</b> IPs<br>ends Sun, Oct 25, 6:00 pm',expP,True,True,new='<div style="margin-top:4px"><span class="newt">1 new since you last looked</span></div>')
 +lrP('Paused','pause','Mark &amp; Jen','/trip/Zx83&hellip;9aQc','<b>7</b> opens'+SEP+'<b>4</b> IPs<br>paused Oct 17'))
endedP=(lrP('Used up','end','Photographer','/trip/Hn5d&hellip;Wy2k','<b>1 of 1</b> opens'+SEP+'<b>1</b> IP'+SEP+'<b style="color:#e0a028">2 blocked</b>',first=True)
 +lrP('Expired','end','Dave (work friend)','/trip/Qe7m&hellip;Lp0s','<b>3 of 10</b> opens'+SEP+'<b>2</b> IPs')
 +lrP('Revoked','rev','Old link','/trip/Bt1v&hellip;Rj6n','<b>4</b> opens'+SEP+'<b>2</b> IPs'))
tabsP='<div class="tabs" style="margin:-2px 0 12px"><span class="tab" style="padding:9px 8px">Private</span><span class="tab on" style="padding:9px 8px">Share links<span class="bdg">1</span></span><span class="tab" style="padding:9px 8px">Site settings</span></div>'
bG='<div class="pg" style="width:390px;padding:16px 12px">'+tabsP+'<div class="pn" style="padding:14px 12px">'+head('Share links','2 not ended &middot; 3 ended')+'<div class="grp" style="margin-top:14px">Current</div>'+listP+'<div class="grp">Ended</div><div class="mut" style="font-size:12px;margin-bottom:2px">The log is kept until you delete the link.</div>'+endedP+'</div></div>'
# ---------------- Board H: ended + delete (kept, with segment and Extend)
vis2=[('192.0.2.15','US','Safari on iPhone','Oct 12, 10:03 am','Oct 12, 3:40 pm','1','38 views &rsaquo;')]
rj2=[('198.51.100.77','GB','Chrome on Windows','Oct 12, 8:41 pm','Oct 13, 7:12 am','2 tries &middot; already used')]
def vt3(rows):
    h='<div class="vh"><span>IP address</span><span>Where</span><span>Browser</span><span>First seen</span><span>Last seen</span><span>Opens</span><span>Viewed</span></div>'
    return h+''.join('<div class="vr"><span class="ip">%s</span><span>%s</span><span>%s</span><span>%s</span><span>%s</span><span>%s</span><span class="tx" style="font-size:13px">%s</span></div>'%r for r in rows)
exp2=('<div class="bx"><div class="full"><div class="fld">https://pcmike.net/trip/Hn5dKc3aPz8YrWy2k</div></div><div class="kv"><span>Who can see</span><span>Everything private</span><span>Opens</span><span>1 of 1 &middot; used up Oct 12, 10:03 am</span></div>'
 '<div class="seg2" style="margin-top:12px">'+segb(['Visitors','Folders','Photos'],'Visitors')+'</div>'+vt3(vis2)
 +'<div class="sub warn">Blocked tries &middot; 1 IP address</div>'+rtable(rj2)
 +'<div class="acts"><a class="tx">Make a new link like this</a><a class="tx red">Delete&hellip;</a></div>'
 '<div class="note">This link no longer lets anyone in, but its log stays here. Deleting the link also deletes its log.</div></div>')
list2=lr2('Used up','end','Photographer','/trip/Hn5d&hellip;Wy2k','<b>1 of 1</b> opens'+SEP+'<b>1</b> IP'+SEP+'<b style="color:#e0a028">2 blocked tries</b>'+SEP+'used up Oct 12',exp2,True,True,btn='')+lr2('Expired','end','Dave (work friend)','/trip/Qe7m&hellip;Lp0s','<b>3 of 10</b> opens'+SEP+'<b>2</b> IPs'+SEP+'ended Sun, Oct 12, 6:00 pm',btn='Extend&hellip;')+lr2('Revoked','rev','Old link','/trip/Bt1v&hellip;Rj6n','<b>4</b> opens'+SEP+'<b>2</b> IPs'+SEP+'revoked Oct 9',btn='')
dlg='<div class="dlg"><h3>Delete this link?</h3><p>&ldquo;Photographer&rdquo; stops working for good, and its log is deleted with it: <b>1 visitor</b>, <b>2 blocked tries</b> and the record of <b>38 views</b>.</p><p style="color:#8a8a8f;font-size:13px">It already doesn&rsquo;t let anyone in. If you only want it out of your way, leave it here: ended links keep their log until you delete them.</p><div class="bt"><a class="btn">Cancel</a><a class="btn dng">%s Delete link and log</a></div></div>'%TRASH
bH='<div style="position:relative;width:960px"><div class="pg" style="width:960px;padding:22px 24px"><div class="pn">'+head('Share links','2 not ended &middot; 3 ended')+'<div class="grp" style="margin-top:14px">Ended</div>'+list2+'</div></div><div class="ov" style="position:absolute;left:0;top:0;right:0;bottom:0;border-radius:0;align-items:flex-end;padding-bottom:36px">'+dlg+'</div></div>'
def board(fn,title,html,w,h):
    doc='<!doctype html>\n<html lang="en">\n<head>\n<meta charset="utf-8">\n<title>'+title+'</title>\n<script src="./support.js"></script>\n</head>\n<body>\n<x-dc>\n<helmet>\n<style>'+CSS2+'</style>\n</helmet>\n'+html+'\n</x-dc>\n<script type="text/x-dc" data-dc-script data-props=\'{"$preview":{"width":%d,"height":%d}}\'>\nclass Component extends DCLogic {\n  renderVals() { return {}; }\n}\n</script>\n</body>\n</html>\n'%(w,h)
    open(P+fn,'w').write(doc)
H=json.loads(os.environ.get('LH','{}'))
spec=[('LinksList.dc.html','Share links list',bA,960,H.get('LinksList',1195),0,11500,'Share links 1 · The tab: badge for new activity, Paused, share buttons, one link open'),
 ('LinksActivity.dc.html','Share links activity',bB,960,H.get('LinksActivity',1115),1040,11500,'Share links 2 · What each link looked at: by folder, by photo, by IP'),
 ('LinksNew.dc.html','Share links new',bC,1360,H.get('LinksNew',1620),0,13000,'Share links 3 · New link form: how it changes with the scope (left: everything private, right: some folders)'),
 ('LinksShare.dc.html','Share links share',bD,1100,H.get('LinksShare',1170),1440,13000,'Share links 4 · Copy, Share and the QR code (small, large, phone)'),
 ('LinksVisitor.dc.html','Share links visitor',bE,1180,520,0,14900,'Share links 5 · What the link holder sees: opening page, invalid page, heads-up'),
 ('LinksEnded.dc.html','Share links ended',bH,960,H.get('LinksEnded',750),0,15600,'Share links 6 · An ended link keeps its log; Delete asks first'),
 ('LinksPhone.dc.html','Share links phone',bG,390,H.get('LinksPhone',1230),1040,15600,'Share links 7 · Phone'),
 ('LinksSettings.dc.html','Share links settings',bF,960,H.get('LinksSettings',740),1480,14900,'Share links 8 · Site settings: switch, short address, heads-up default, IP source')]
c=json.load(open(P+'canvas.json'))
for fn,t,h,w,ht,x,y,ct in spec:
    board(fn,t,h,w,ht)
    c['boards'][fn]={"x":x,"y":y,"w":w,"h":ht,"title":ct}
    if fn not in c['order']: c['order'].append(fn)
json.dump(c,open(P+'canvas.json','w'),indent=2)
print('ok')
