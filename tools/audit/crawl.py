import sys, os, re, time, json, urllib.request, urllib.parse, hashlib, ssl
from html.parser import HTMLParser
base="https://www.sevalia.ro"
out=sys.argv[1]; os.makedirs(out+"/pages",exist_ok=True)
seeds=[l.strip() for l in open(sys.argv[2]) if l.strip()]
ctx=ssl.create_default_context(cafile="/root/.ccr/ca-bundle.crt")
class P(HTMLParser):
    def __init__(s): super().__init__(); s.links=[]; s.assets=[]
    def handle_starttag(s,t,a):
        a=dict(a)
        if t=="a" and a.get("href"): s.links.append(a["href"])
        for k in ("src","data-src","srcset","href"):
            if t in("img","link","script","source") and a.get(k): s.assets.append((t,k,a[k]))
def norm(u):
    u=urllib.parse.urljoin(base+"/",u); p=urllib.parse.urlparse(u)
    if p.netloc not in("www.sevalia.ro","sevalia.ro"): return None
    path=p.path
    if re.search(r'\.(jpg|jpeg|png|webp|gif|svg|pdf|css|js|ico|woff2?)$',path,re.I) or path.startswith(("/web/","/website/image","/web/image","/web/content")): return None
    return base+path+("?"+p.query if p.query and not path.startswith("/blog") else "")
seen={}; queue=list(seeds); meta={}
while queue and len(seen)<400:
    u=queue.pop(0)
    if u in seen: continue
    if any(x in u for x in ("/web/login","/logout","/shop/cart","?","/feed","/en/","/en?","/website/social")) : seen[u]="skip"; continue
    for attempt in range(4):
        try:
            req=urllib.request.Request(u,headers={"User-Agent":"Mozilla/5.0 (audit SEVALIA)","Accept-Language":"ro-RO,ro;q=0.9","Cookie":"frontend_lang=ro_RO"})
            r=urllib.request.urlopen(req,context=ctx,timeout=30)
            body=r.read().decode("utf-8","replace"); code=r.status; final=r.geturl(); break
        except urllib.error.HTTPError as e:
            body=e.read().decode("utf-8","replace"); code=e.code; final=u; break
        except Exception as e:
            code=str(e); body=""; final=u; time.sleep(2**attempt)
    name=hashlib.md5(u.encode()).hexdigest()[:10]
    open(f"{out}/pages/{name}.html","w").write(body)
    t=re.search(r"<title>(.*?)</title>",body,re.S)
    d=re.search(r'<meta name="description" content="([^"]*)"',body)
    meta[u]={"code":code,"final":final,"file":name,"title":t.group(1).strip() if t else None,"description":d.group(1) if d else None}
    seen[u]=code
    p=P(); 
    try: p.feed(body)
    except Exception: pass
    for l in p.links:
        n=norm(l)
        if n and n not in seen and n not in queue: queue.append(n)
    time.sleep(0.3)
json.dump(meta,open(out+"/pages.json","w"),indent=1,ensure_ascii=False)
print(len(meta))
