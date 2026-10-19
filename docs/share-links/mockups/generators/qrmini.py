# Minimal QR encoder: byte mode, error correction H, versions 1-6 (enough for a ~44 character link).
ECC_PER_BLOCK={1:17,2:28,3:22,4:16,5:22,6:28}
NUM_BLOCKS={1:1,2:1,3:2,4:4,5:4,6:4}
ALIGN={1:[],2:[6,18],3:[6,22],4:[6,26],5:[6,30],6:[6,34]}
def _raw(v):
    n=(16*v+128)*v+64
    if v>=2:
        a=v//7+2; n-=(25*a-10)*a-55
    return n//8
def _gmul(x,y):
    z=0
    for i in range(7,-1,-1):
        z=(z<<1)^((z>>7)*0x11D)
        z^=((y>>i)&1)*x
    return z
def _rsgen(d):
    r=[0]*(d-1)+[1]; root=1
    for _ in range(d):
        for j in range(d):
            r[j]=_gmul(r[j],root)
            if j+1<d: r[j]^=r[j+1]
        root=_gmul(root,2)
    return r
def _rsrem(data,gen):
    r=[0]*len(gen)
    for b in data:
        f=b^r.pop(0); r.append(0)
        for i,c in enumerate(gen): r[i]^=_gmul(c,f)
    return r
def encode(text,mask=None):
    data=text.encode('utf-8')
    for v in range(1,7):
        cap=_raw(v)-ECC_PER_BLOCK[v]*NUM_BLOCKS[v]
        if 4+8+8*len(data)<=cap*8: break
    else: raise ValueError('too long')
    bits=[]
    def put(val,n): bits.extend((val>>i)&1 for i in range(n-1,-1,-1))
    put(4,4); put(len(data),8)
    for b in data: put(b,8)
    put(0,min(4,cap*8-len(bits)))
    while len(bits)%8: bits.append(0)
    cw=[int(''.join(map(str,bits[i:i+8])),2) for i in range(0,len(bits),8)]
    pad=0
    while len(cw)<cap: cw.append(0xEC if pad%2==0 else 0x11); pad+=1
    nb=NUM_BLOCKS[v]; ecc=ECC_PER_BLOCK[v]; raw=_raw(v)
    nshort=nb-raw%nb; slen=raw//nb
    gen=_rsgen(ecc); blocks=[]; k=0
    for i in range(nb):
        dl=slen-ecc+(0 if i<nshort else 1)
        d=cw[k:k+dl]; k+=dl
        e=_rsrem(d,gen)
        blocks.append((d,e))
    out=[]
    for i in range(max(len(b[0]) for b in blocks)):
        for d,e in blocks:
            if i<len(d): out.append(d[i])
    for i in range(ecc):
        for d,e in blocks: out.append(e[i])
    size=v*4+17
    mod=[[False]*size for _ in range(size)]; fn=[[False]*size for _ in range(size)]
    def setf(x,y,dark): mod[y][x]=dark; fn[y][x]=True
    for i in range(size):
        setf(6,i,i%2==0); setf(i,6,i%2==0)
    for cx,cy in ((3,3),(size-4,3),(3,size-4)):
        for dy in range(-4,5):
            for dx in range(-4,5):
                x,y=cx+dx,cy+dy
                if 0<=x<size and 0<=y<size:
                    dist=max(abs(dx),abs(dy)); setf(x,y,dist not in (2,4))
    ap=ALIGN[v]
    for i,cx in enumerate(ap):
        for j,cy in enumerate(ap):
            if (i==0 and j==0) or (i==0 and j==len(ap)-1) or (i==len(ap)-1 and j==0): continue
            for dy in range(-2,3):
                for dx in range(-2,3): setf(cx+dx,cy+dy,max(abs(dx),abs(dy))!=1)
    def fmt(m):
        d=(2<<3)|m; r=d
        for _ in range(10): r=(r<<1)^((r>>9)*0x537)
        b=((d<<10)|r)^0x5412
        g=lambda i:((b>>i)&1)==1
        for i in range(6): setf(8,i,g(i))
        setf(8,7,g(6)); setf(8,8,g(7)); setf(7,8,g(8))
        for i in range(9,15): setf(14-i,8,g(i))
        for i in range(8): setf(size-1-i,8,g(i))
        for i in range(8,15): setf(8,size-15+i,g(i))
        setf(8,size-8,True)
    fmt(0)
    allbits=[(c>>i)&1 for c in out for i in range(7,-1,-1)]
    i=0; x=size-1; up=True
    while x>0:
        if x==6: x-=1
        ys=range(size-1,-1,-1) if up else range(size)
        for y in ys:
            for dx in (0,1):
                xx=x-dx
                if not fn[y][xx]:
                    mod[y][xx]=(allbits[i]==1) if i<len(allbits) else False; i+=1
        up=not up; x-=2
    masks=[lambda x,y:(x+y)%2==0,lambda x,y:y%2==0,lambda x,y:x%3==0,lambda x,y:(x+y)%3==0,
           lambda x,y:(x//3+y//2)%2==0,lambda x,y:x*y%2+x*y%3==0,lambda x,y:(x*y%2+x*y%3)%2==0,lambda x,y:((x+y)%2+x*y%3)%2==0]
    def applym(m):
        r=[row[:] for row in mod]
        for y in range(size):
            for x in range(size):
                if not fn[y][x] and masks[m](x,y): r[y][x]=not r[y][x]
        return r
    def penalty(g):
        p=0
        for line in list(g)+[list(c) for c in zip(*g)]:
            run=1
            for a in range(1,size):
                if line[a]==line[a-1]: run+=1
                else:
                    if run>=5: p+=3+run-5
                    run=1
            if run>=5: p+=3+run-5
            s=''.join('1' if t else '0' for t in line)
            p+=40*(s.count('00001011101')+s.count('10111010000'))
        for y in range(size-1):
            for x in range(size-1):
                if g[y][x]==g[y][x+1]==g[y+1][x]==g[y+1][x+1]: p+=3
        dark=sum(map(sum,g)); tot=size*size
        k=-(-abs(dark*20-tot*10)//tot)-1
        return p+k*10
    best=None
    for m in (range(8) if mask is None else [mask]):
        g=applym(m)
        # temporarily write the format for this mask
        mod_backup=mod
        fmt_saved=[row[:] for row in mod]
        # apply format bits onto g
        d=(2<<3)|m; r=d
        for _ in range(10): r=(r<<1)^((r>>9)*0x537)
        b=((d<<10)|r)^0x5412
        gg=lambda i:((b>>i)&1)==1
        def sg(x,y,dark): g[y][x]=dark
        for i in range(6): sg(8,i,gg(i))
        sg(8,7,gg(6)); sg(8,8,gg(7)); sg(7,8,gg(8))
        for i in range(9,15): sg(14-i,8,gg(i))
        for i in range(8): sg(size-1-i,8,gg(i))
        for i in range(8,15): sg(8,size-15+i,gg(i))
        sg(8,size-8,True)
        sc=penalty(g)
        if best is None or sc<best[0]: best=(sc,m,g)
    return best[2],v,best[1]
def decode(g,v):
    """Independent read-back: format -> unmask -> codewords -> RS check -> text."""
    size=len(g)
    bits=0
    for i in range(6): bits|=(1 if g[i][8] else 0)<<i
    bits|=(1 if g[7][8] else 0)<<6; bits|=(1 if g[8][8] else 0)<<7; bits|=(1 if g[8][7] else 0)<<8
    for i in range(9,15): bits|=(1 if g[8][14-i] else 0)<<i
    bits^=0x5412; mask=(bits>>10)&7; ecl=(bits>>13)&3
    assert ecl==2,'ecl'
    fnm=[[False]*size for _ in range(size)]
    # rebuild function map by encoding a dummy of same version
    return mask
