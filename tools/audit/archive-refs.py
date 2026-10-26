"""Completează arhiva cu resursele referite în HTML (brut și randat) dar neîncărcate de browser.

   python3 -I tools/audit/archive-refs.py <dir-arhiva> <dir-pagini-brute>...

Caută referințe către /web/image, /web/content, /unsplash, /website/static, /theme_*, /base/static, fișiere
(.pdf, .doc, .mp4...) și meta og:image/twitter:image, le descarcă de pe www.sevalia.ro și le adaugă în index.json
cu sursa „referință HTML”. Raportează ce nu a putut fi descărcat.
"""
import hashlib, html, json, os, re, ssl, sys, time, urllib.parse, urllib.request

arch = sys.argv[1]
sources = sys.argv[2:] + [os.path.join(arch, 'rendered')]
idx_path = os.path.join(arch, 'index.json')
index = json.load(open(idx_path))
ctx = ssl.create_default_context(cafile='/root/.ccr/ca-bundle.crt')
BASE = 'https://www.sevalia.ro'
PAT = re.compile(r'''(?:src|href|content|data-[a-z-]+)=["']([^"']+)["']|url\((?:&quot;|["'])?([^)"'&]+)''')
INTERESTING = re.compile(r'^/(web/image|web/content|unsplash|website/static|theme_[a-z_]+/static|base/static|website/image)|\.(pdf|docx?|xlsx?|pptx?|mp4|webm|jpe?g|png|webp|gif|svg|ico)(\?|$)', re.I)

refs = {}
for src in sources:
    for name in os.listdir(src):
        if not name.endswith('.html'):
            continue
        text = open(os.path.join(src, name), errors='replace').read()
        for a, b in PAT.findall(text):
            raw = html.unescape(a or b).strip()
            for part in raw.split(','):  # srcset
                u = part.strip().split(' ')[0]
                if not u or u.startswith(('data:', 'mailto:', 'tel:', '#', 'javascript:')):
                    continue
                full = urllib.parse.urljoin(BASE + '/', u)
                p = urllib.parse.urlparse(full)
                if not p.netloc.endswith('sevalia.ro'):
                    continue
                if INTERESTING.search(p.path):
                    refs.setdefault(full, set()).add(name)

known = set(index['resources'])
missing = sorted(u for u in refs if u not in known)
print('referințe', len(refs), 'deja în arhivă', len(refs) - len(missing), 'de descărcat', len(missing))
failed = []
for u in missing:
    body = None
    for attempt in range(3):
        try:
            req = urllib.request.Request(u, headers={'User-Agent': 'Mozilla/5.0 (arhivare SEVALIA)'})
            r = urllib.request.urlopen(req, context=ctx, timeout=60)
            body, ct = r.read(), r.headers.get('content-type', '')
            break
        except urllib.error.HTTPError as e:
            failed.append((u, e.code)); break
        except Exception as e:
            time.sleep(2 ** attempt); err = str(e)
    else:
        failed.append((u, err))
    if body is None:
        continue
    if 'text/html' in ct:
        failed.append((u, 'html în loc de fișier')); continue
    sha = hashlib.sha256(body).hexdigest()
    ext = {'image/jpeg': 'jpg', 'image/png': 'png', 'image/webp': 'webp', 'image/svg+xml': 'svg', 'image/gif': 'gif',
           'application/pdf': 'pdf', 'video/mp4': 'mp4', 'image/x-icon': 'ico', 'image/vnd.microsoft.icon': 'ico'}.get(ct.split(';')[0].strip(), 'bin')
    fn = f'{sha[:20]}.{ext}'
    open(os.path.join(arch, 'files', fn), 'wb').write(body)
    index['resources'][u] = {'file': fn, 'type': ct, 'bytes': len(body), 'sha256': sha, 'resourceType': 'referinta-html', 'pages': sorted(refs[u])}
    time.sleep(0.2)
json.dump(index, open(idx_path, 'w'), indent=1)
json.dump(failed, open(os.path.join(arch, 'lipsa.json'), 'w'), indent=1)
print('eșuate', len(failed))
for f in failed[:30]:
    print('  ', f)
