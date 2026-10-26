# Descarcă formele decorative SVG (fundaluri „shape” Odoo) referite de CSS-ul redus al siteului, ca să nu depindă de siteul vechi.
# Utilizare: python3 -I tools/audit/download_shapes.py resources/css/site/odoo.css database/content/odoo-snapshot/assets/shapes
import hashlib
import json
import os
import re
import ssl
import sys
import time
import urllib.request

css_path, out = sys.argv[1], sys.argv[2]
os.makedirs(out, exist_ok=True)
idx_path = os.path.join(out, 'index.json')
index = json.load(open(idx_path)) if os.path.exists(idx_path) else {}
ctx = ssl.create_default_context(cafile="/root/.ccr/ca-bundle.crt")
css = open(css_path, encoding='utf-8').read()
for url in sorted(set(re.findall(r'url\("(/html_editor/shape/[^"]+)"\)', css))):
    if url in index:
        continue
    body = None
    for attempt in range(5):
        try:
            body = urllib.request.urlopen(urllib.request.Request('https://www.sevalia.ro' + url, headers={'User-Agent': 'Mozilla/5.0 audit'}), context=ctx, timeout=60).read()
            break
        except Exception as e:  # noqa: BLE001
            print('reîncerc', url, e)
            time.sleep(2 ** attempt)
    if body is None:
        print('LIPSĂ', url)
        continue
    if b'<script' in body.lower():
        raise SystemExit('SVG cu script: ' + url)
    name = re.sub(r'[^a-zA-Z0-9]+', '-', url.split('/shape/')[1].split('?')[0]).strip('-') + '-' + hashlib.sha256(url.encode()).hexdigest()[:8] + '.svg'
    with open(os.path.join(out, name), 'wb') as f:
        f.write(body)
    index[url] = {'file': name, 'sha256': hashlib.sha256(body).hexdigest(), 'bytes': len(body)}
    print(url, '->', name)
json.dump(index, open(idx_path, 'w'), indent=1)
