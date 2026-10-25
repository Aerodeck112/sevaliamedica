import sys,os,ssl,urllib.request,urllib.parse,json,hashlib,re,time
src,out=sys.argv[1],sys.argv[2]; os.makedirs(out,exist_ok=True)
ctx=ssl.create_default_context(cafile="/root/.ccr/ca-bundle.crt")
res={}
for p in [l.strip() for l in open(src) if l.strip()]:
    p=p.replace('&amp;','&')
    u="https://www.sevalia.ro"+urllib.parse.quote(p,safe="/?=&%-_.")
    for a in range(3):
        try:
            r=urllib.request.urlopen(urllib.request.Request(u,headers={"User-Agent":"Mozilla/5.0 audit"}),context=ctx,timeout=60)
            b=r.read(); ct=r.headers.get('content-type',''); break
        except Exception as e: b=None; ct=str(e); time.sleep(2)
    if not b: res[p]={"error":ct}; continue
    ext={'image/jpeg':'jpg','image/png':'png','image/webp':'webp','image/svg+xml':'svg','image/gif':'gif'}.get(ct.split(';')[0],'bin')
    h=hashlib.sha256(b).hexdigest()
    fn=f"{h[:16]}.{ext}"; open(os.path.join(out,fn),'wb').write(b)
    res[p]={"file":fn,"type":ct,"bytes":len(b),"sha256":h}
json.dump(res,open(os.path.join(out,'index.json'),'w'),indent=1)
print(len(res))
