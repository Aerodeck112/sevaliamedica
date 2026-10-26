# Descarcă imaginile suplimentare (fundaluri CSS, coperți, og:image) referite de instantaneu și le adaugă în index.json.
# Utilizare: python3 -I tools/audit/download_more_images.py <lista-cai.txt> database/content/odoo-snapshot/images
import hashlib
import json
import os
import ssl
import sys
import time
import urllib.parse
import urllib.request

src, out = sys.argv[1], sys.argv[2]
idxf = os.path.join(out, 'index.json')
res = json.load(open(idxf))
ctx = ssl.create_default_context(cafile="/root/.ccr/ca-bundle.crt")
for p in [line.rstrip('\n') for line in open(src) if line.strip()]:
    if p in res:
        continue
    u = "https://www.sevalia.ro" + urllib.parse.quote(p, safe="/?=&%-_.,()")
    b = None
    ct = ''
    for _ in range(3):
        try:
            r = urllib.request.urlopen(urllib.request.Request(u, headers={"User-Agent": "Mozilla/5.0 audit", "Cookie": "frontend_lang=ro_RO"}), context=ctx, timeout=60)
            b = r.read()
            ct = r.headers.get('content-type', '')
            break
        except Exception as e:  # noqa: BLE001
            ct = str(e)
            time.sleep(2)
    if not b:
        res[p] = {"error": ct}
        print('ERR', p, ct)
        continue
    ext = {'image/jpeg': 'jpg', 'image/png': 'png', 'image/webp': 'webp', 'image/svg+xml': 'svg', 'image/gif': 'gif'}.get(ct.split(';')[0], 'bin')
    h = hashlib.sha256(b).hexdigest()
    fn = f"{h[:16]}.{ext}"
    with open(os.path.join(out, fn), 'wb') as f:
        f.write(b)
    res[p] = {"file": fn, "type": ct, "bytes": len(b), "sha256": h}
json.dump(res, open(idxf, 'w'), indent=1)
print(len(res))
