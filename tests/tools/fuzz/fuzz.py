#!/usr/bin/env python3
"""Fuzz the gallery with hostile requests and watch PHP for warnings and fatals.

Builds a "kitchen sink" fixture (every feature on, awkward folder/file names),
runs PHP's built-in server with every error reported and logged, then sends a
few thousand requests -- GETs against every endpoint as an anonymous visitor, a
revealed viewer and the admin, plus randomized admin POSTs -- and fails if any
response or the PHP error log contains a Warning/Notice/Fatal/Deprecated or a
5xx. Needs: python3, php (with gd + imagick for the fixture). Run from anywhere:

    python3 tests/tools/fuzz/fuzz.py

Exit status is 0 only if nothing was found. The fixture lives in a temp dir that
is removed afterwards (it is mutated by the POST storm, so never reuse one).
"""
import subprocess, time, urllib.request, urllib.parse, urllib.error, base64, re, os, sys, random, tempfile, shutil, socket
HERE = os.path.dirname(os.path.abspath(__file__))
WORK = tempfile.mkdtemp(prefix="gallery-fuzz-"); SITE = WORK + "/site"
def free_port():
    s = socket.socket(); s.bind(("127.0.0.1", 0)); p = s.getsockname()[1]; s.close(); return p
PORT = free_port(); B = f"http://127.0.0.1:{PORT}"
log = WORK + "/php-errors.log"; open(log, "w").close()
subprocess.run(["php", HERE + "/build_fixture.php", SITE], check=True, stdout=subprocess.DEVNULL)
srv = subprocess.Popen([os.environ.get("PHP_SERVER", "php"), "-d", "display_errors=1", "-d", "error_reporting=-1", "-d", "html_errors=0", "-d", "log_errors=1", "-d", f"error_log={log}", "-S", f"127.0.0.1:{PORT}", "-t", SITE], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
time.sleep(1.2)
BAD=re.compile(rb"(Warning|Notice|Fatal error|Deprecated|Parse error|Uncaught|Stack trace)\b")
problems=[]; n=0
class NoRedir(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*a,**k): return None
op=urllib.request.build_opener(NoRedir)
def req(path,method="GET",data=None,headers=None,label=None):
    global n; n+=1
    h=dict(headers or {})
    r=urllib.request.Request(B+path,data=(urllib.parse.urlencode(data,doseq=True).encode() if data is not None else None),headers=h,method=method)
    try:
        resp=op.open(r,timeout=10); status=resp.status; body=resp.read(); hd=resp.headers
    except urllib.error.HTTPError as e:
        status=e.code; body=e.read(); hd=e.headers
    except Exception as e:
        problems.append((path[:90],"EXC "+str(e))); return 0,b"",{}
    why=[]
    if status>=500: why.append(f"HTTP {status}")
    m=BAD.search(body)
    if m: why.append("PHP message in body: "+body[max(0,m.start()-60):m.start()+160].decode('latin1').replace("\n"," "))
    if why: problems.append(((label or path)[:110],"; ".join(why)))
    return status,body,hd
q=urllib.parse.quote
folders=['Europe','Europe/France','Europe/France/Paris','Europe/France/Lyon','Europe/Germany','Asia','Day 1','Weird & Name','Üñí',"quo'te",'a<b>c','semi;colon','dot.dot','100%']
files=['loose1.jpg','loose2.jpg','dp104_01.jpg','dp104_02.jpg','secret.jpg','geo1.jpg','clip.mp4','phone.heic','Asia/geo2.jpg','Europe/intro.jpg','Europe/France/Paris/eiffel.jpg','Day 1/p.jpg','Weird & Name/p.jpg','Üñí/p.jpg',"quo'te/p.jpg",'a<b>c/p.jpg','100%/p.jpg']
junk=['','.','..','../','/','//','\\','%00','%2e%2e%2f','a'*5000,'<script>alert(1)</script>',"'\"",'0','-1','null','Europe//France','Europe/./France','EUROPE','europe','Europe%2FFrance','nope/nope','index.php','.gallery-items.php','/etc/passwd','Europe/France/Paris/eiffel.jpg/','😀']
# --- cookies for revealed + admin viewers
def cookie_from(path,auth):
    r=urllib.request.Request(B+path,headers={"Authorization":"Basic "+base64.b64encode(auth.encode()).decode()})
    try: resp=op.open(r,timeout=10)
    except urllib.error.HTTPError as e: resp=e
    return "; ".join(h.split(';')[0] for h in resp.headers.get_all('Set-Cookie') or []), resp
revealC,_=cookie_from("/?reveal","viewer:viewer-pw")
adminC,adminResp=cookie_from("/?admin","admin:adm-pw")
adminAuth={"Authorization":"Basic "+base64.b64encode(b"admin:adm-pw").decode(),"Cookie":adminC}
# --- share links: a link to a folder, a link to photos and a link to everything, each as a signed-in link holder
def _csrf():
    s,b,_=req("/?admin",headers=adminAuth); m=re.search(rb'csrf_token"\s+value="([a-f0-9]+)"',b) or re.search(rb'csrf_token" value="([a-f0-9]+)"',b); return m.group(1).decode()
import json
def make_link(label,**fields):
    base={"action":"link_create","label":label,"title_mode":"gallery","max_opens":"0","ends_at":str(int(time.time())+86400),"locations":"public","heads_up":"on","scope_type":"all","downloads":"off","zip":"off","csrf_token":_csrf()}
    base.update(fields)
    s,b,_=req("/?admin",method="POST",data=base,headers=dict(adminAuth,**{"X-Requested-With":"gallery-fetch"}),label="make link "+label)
    d=json.loads(b.decode() or "{}")
    if not d.get("ok"): problems.append(("make link "+label,"not made: "+str(d))); return None,""
    raw=open(SITE+"/.gallery-links.php").read(); links=json.loads(raw[raw.index("\n")+1:])["links"]; tok=links[d["id"]]["token"]
    r=urllib.request.Request(B+"/?link="+tok,data=b"go=1",method="POST")
    try: resp=op.open(r,timeout=10)
    except urllib.error.HTTPError as e: resp=e
    return tok,"; ".join(h.split(';')[0] for h in resp.headers.get_all('Set-Cookie') or [])
linkTokF,linkF=make_link("folder",scope_type="folders",scope_folders=json.dumps(["Europe/Germany","Europe/France"]),open_in="Europe/France",downloads="all",zip="all",locations="public")
linkTokP,linkP=make_link("photos",scope_type="photos",scope_photos=json.dumps(["loose1.jpg","secret.jpg","Europe/France/Paris/eiffel.jpg"]),downloads="all")
linkTokA,linkA=make_link("all",downloads="all",zip="all",locations="all")
sess=[("anon",{}),("revealed",{"Cookie":revealC}),("admin",adminAuth),("link-folders",{"Cookie":linkF}),("link-photos",{"Cookie":linkP}),("link-all",{"Cookie":linkA})]
# --- the log: reports from link holders, and the owner's views of it
raw=open(SITE+"/.gallery-links.php").read(); LINK_IDS=list(json.loads(raw[raw.index("\n")+1:])["links"].keys())
beacons=[{"e":[["s","loose1.jpg"],["o","loose1.jpg"],["p","clip.mp4"],["f","Europe"],["f",""]]},{"e":[["s","secret.jpg"],["o","Asia/geo2.jpg"],["d","loose1.jpg"]]},{"e":"x"},{"e":[["s"]]},{"e":[["s",["a"]]]},{"e":[[1,2]]},{"e":[["s","a"*5000]]},{"e":[["f","../.."],["f","Europe/../x"],["s","../index.php"]]},{"e":[["s","loose1.jpg"]]*200},{},[],"str",None,{"e":[["s","\u0000"]]}]
for name,h in [(nm,hh) for nm,hh in sess if nm.startswith("link") or nm in ("anon","admin","revealed")]:
    for b in beacons:
        r=urllib.request.Request(B+"/?linklog",data=json.dumps(b).encode(),method="POST",headers=dict(h,**{"Content-Type":"application/json"}))
        n+=1
        try: resp=op.open(r,timeout=10); st=resp.status; body=resp.read()
        except urllib.error.HTTPError as e: st=e.code; body=e.read()
        if st>=500 or BAD.search(body): problems.append((f"[{name}] linklog {str(b)[:40]}",f"HTTP {st} {body[:80]!r}"))
    for raw_body in (b"", b"{", b"\xff\xfe", b"[[[[[", b"{\"e\":" + b"["*500):
        r=urllib.request.Request(B+"/?linklog",data=raw_body,method="POST",headers=dict(h))
        n+=1
        try: resp=op.open(r,timeout=10); st=resp.status; body=resp.read()
        except urllib.error.HTTPError as e: st=e.code; body=e.read()
        if st>=500 or BAD.search(body): problems.append((f"[{name}] linklog raw {raw_body[:10]!r}",f"HTTP {st}"))
    req("/?linklog",headers=h,label=f"[{name}] linklog GET")
for lid in LINK_IDS+['nope','','../','a'*40,'%00']:
    for tab in ('visitors','folders','photos','bogus',''):
        req(f"/?admin&linkview={q(lid,safe='')}&tab={tab}",headers=adminAuth,label=f"linkview {lid[:12]} {tab}")
    req(f"/?admin&linkview={q(lid,safe='')}&tab=photos&vid={q(lid,safe='')}&folder={q('Europe',safe='')}&sort=recent&all=1",headers=adminAuth,label=f"linkview filters {lid[:12]}")
    for what in ('visitors','items','events','bogus',''): req(f"/?admin&linkexport={q(lid,safe='')}&what={what}",headers=adminAuth,label=f"linkexport {lid[:12]} {what}")
req("/?admin&linkexport=all&what=items",headers=adminAuth,label="linkexport all"); req("/?admin&linkips=1",headers=adminAuth,label="linkips")
for ip in ('127.0.0.1','nope','','::1','a'*300,'<script>','%00'):
    for tab in ('folders','media','timeline','bogus'): req(f"/?admin&linkip={q(ip,safe='')}&tab={tab}",headers=adminAuth,label=f"linkip {ip[:12]} {tab}")
for name,h in sess:
    if name!='admin':
        for p in ("/?admin&linkview="+LINK_IDS[0]+"&tab=visitors","/?admin&linkips=1","/?admin&linkexport=all&what=items"):
            s,b,_=req(p,headers=h,label=f"[{name}] {p[:40]}")
            if s==200 and b'data-ip=' in b: problems.append((f"[{name}] {p[:40]}","the owner's log was served to a non-owner"))
# --- GET sweeps
for name,h in sess:
    for p in ['/','/?about','/?map','/?mapicon','/?download','/?hidden','/?reveal','/?admin','/?admin&hidden','/?archive','/?archive&folder=Europe','/?archive&folder=Europe%2FGermany','/?archive&folder=nope']: req(p,headers=h,label=f"[{name}] {p}")
    for f in folders+junk:
        req("/?folder="+q(f,safe=''),headers=h,label=f"[{name}] ?folder={f[:30]}")
        req("/?admin&folder="+q(f,safe=''),headers=h,label=f"[{name}] ?admin&folder={f[:30]}")
    for f in files+junk:
        for size in ['thumb','share','full','bogus','']:
            req(f"/?img={q(f,safe='')}&size={size}",headers=h,label=f"[{name}] ?img={f[:30]}&size={size}")
        req("/?video="+q(f,safe=''),headers=h,label=f"[{name}] ?video={f[:30]}")
        req("/?track="+q(f,safe=''),headers=h,label=f"[{name}] ?track={f[:30]}")
        req("/?photo="+q(f,safe=''),headers=h,label=f"[{name}] ?photo={f[:30]}")
        for what in ('original','strip','preview'): req(f"/?{what}="+q(f,safe=''),headers=h,label=f"[{name}] ?{what}={f[:30]}")
        req("/?link="+q(f,safe=''),headers=h,label=f"[{name}] ?link={f[:30]}")
        req("/?link="+q(f,safe=''),method="POST",data={"go":"1"},headers=h,label=f"[{name}] POST ?link={f[:30]}")
    for arr in ['folder[]=x','img[]=x','video[]=x','track[]=x','photo[]=x','folder[a]=b','img[]=a&img[]=b','size[]=x&img=loose1.jpg','map[]=1','admin[]=1','reveal[]=1','folder=Europe&photo[]=x','folder[]=Europe&map']:
        req("/?"+arr,headers=h,label=f"[{name}] ?{arr}")
    for combo in ['folder=Europe&photo=Europe/intro.jpg','map&folder=Asia','about&folder=Europe','folder=Asia&track=loose1.jpg','map&photo=loose1.jpg']:
        req("/?"+combo,headers=h,label=f"[{name}] ?{combo}")
for rng in ['bytes=0-10','bytes=-5','bytes=10-','bytes=99999999-','bytes=5-2','bytes=a-b','bytes=0-1,5-6','','bytes=','garbage','bytes=-0','bytes=0-0','bytes=9999999999999999999-']:
    req("/?video=clip.mp4",headers={"Range":rng},label=f"?video Range {rng}")
for m in ['POST','PUT','DELETE','OPTIONS','HEAD','PATCH']:
    for p in ['/','/?admin','/?reveal','/?img=loose1.jpg','/?track=loose1.jpg']:
        req(p,method=m,data={"x":"y"} if m in('POST','PUT','PATCH') else None,headers=adminAuth if 'admin' in p else {},label=f"{m} {p}")
# --- admin POST fuzz
def csrf():
    s,b,_=req("/?admin",headers=adminAuth); m=re.search(rb'csrf_token" value="([a-f0-9]+)"',b); return m.group(1).decode()
tok=csrf()
weird=['["Europe"]', '["Europe/France","Asia"]', '["nope"]', '[1,2,3]', '{"a":1}', 'folders', 'photos', 'some', 'all', 'on', 'public', '1700000000', '99999999999', '["loose1.jpg"]', '', 'x', 'a'*3000, '<?php echo 1; ?>', "'; DROP TABLE x;--", '../../etc/passwd', '0','-1','1e999','NaN','99999999999999999999', '😀', "\x00", "line1\nline2\r\n", ' ', '{"a":1}']
raw=open(SITE+"/.gallery-links.php").read(); weird += list(json.loads(raw[raw.index("\n")+1:])["links"].keys())   # real link ids, so the link actions get past the first check
arrs=[['a','b'],{'k':'v'}]
actions=['save_item','save_group_label','save_folder_identity','toggle_location_enabled','toggle_folder_restriction','set_cover','save_notes','delete_notes','add_gallery_marker','export_items','export_tree','save_site_settings','save_site_settings','link_create','link_create','link_save','link_pause','link_resume','link_revoke','link_extend','link_replace','link_forget','link_delete','nonexistent','']
fields=['file','caption','web','lat','lng','loc_label','loc_visibility','restriction','group_key','display_name','description','dates','sort_order','enabled','hidden','cover','md_file','new_name','content','confirm','loaded_hash','set_ENABLE_VIDEO','set_AUTO_GROUP_BY_FOLDER','set_TRACK_VIEWS','set_PHOTO_SORT_ORDER','set_FOLDER_DISPLAY_MODE','set_MAP_CLUSTER_RADIUS_MILES','pw_admin','pw_admin2','pw_private','pw_private2','pw_private_remove','config_hash','id','label','note','title_mode','title_text','max_opens','ends_at','starts_at','locations','heads_up','scope_type','scope_folders','scope_photos','open_in','downloads','downloads_folders','zip','zip_folders','add_opens','no_end','fid']
random.seed(7)
import atexit; atexit.register(lambda: (srv.poll() is None) and srv.terminate())
scopes=['','&folder=Europe','&folder=Asia','&folder='+q('Europe/France/Paris',safe=''),'&folder=nope']
for i in range(900):
    act=random.choice(actions); data={'csrf_token':tok if random.random()>0.1 else 'bad','action':act}
    for f in random.sample(fields,random.randint(0,8)):
        data[f]=random.choice(weird) if random.random()>0.2 else random.choice(arrs)[0:2] if False else random.choice(weird)
    if random.random()<0.15:
        data[random.choice(fields)+'[]']=['x','y']
    if act=='save_site_settings' and random.random()<0.6:
        # a correct fingerprint of the settings file some of the time, so the real save path (not just "stale") is exercised
        import hashlib
        cp=SITE+'/.gallery-config.php'; data['config_hash']=hashlib.md5(open(cp,'rb').read() if os.path.exists(cp) else b'').hexdigest()
        for k,v in (('set_ENABLE_VIDEO',random.choice(['0','1','x',''])),('set_PHOTO_SORT_ORDER',random.choice(['filename','mtime','random'])),('set_MAP_CLUSTER_RADIUS_MILES',random.choice(['50','0','1e9','abc','-3']))):
            if random.random()<0.7: data[k]=v
    if random.random()<0.5 and act in('save_item','set_cover'): data['file']=random.choice(['loose1.jpg','loose2.jpg','eiffel.jpg','p.jpg','geo1.jpg'])
    r=random.choice(scopes)
    # mutations to the data files must not poison later requests:
    req("/?admin"+r,method="POST",data=data,headers=adminAuth,label=f"ADMIN POST {act} {r} {sorted(data)[:6]}")
    if i%100==99: tok=csrf()
# after the storm, every public page must still render
for name,h in sess:
    for p in ['/','/?map','/?folder=Europe','/?folder=Asia','/?admin','/?admin&hidden']:
        s,b,_=req(p,headers=h,label=f"AFTER STORM [{name}] {p}")
        if s not in (200,302,401): problems.append((f"AFTER STORM [{name}] {p}",f"unexpected status {s}"))
srv.terminate(); srv.wait()
errs = open(log).read().strip()
print(f"requests: {n}   problems in responses: {len(problems)}   lines in PHP error log: {len(errs.splitlines()) if errs else 0}")
seen = set()
for lab, why in problems:
    k = why[:90]
    if k in seen: continue
    seen.add(k); print("PROBLEM:", lab, "->", why)
if errs:
    uniq = {}
    for line in errs.splitlines():
        key = re.sub(r'^\[[^\]]*\]\s*', '', line); key = re.sub(r'\d+', 'N', key)[:170]; uniq[key] = uniq.get(key, 0) + 1
    print("--- distinct PHP log messages:")
    for k, c in sorted(uniq.items(), key=lambda x: -x[1])[:25]: print(f"{c:5d}x {k}")
shutil.rmtree(WORK, ignore_errors=True)
sys.exit(1 if (problems or errs) else 0)
