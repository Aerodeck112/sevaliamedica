"""Inventarul conținutului: pentru fiecare pagină — titlu, descriere, titluri, paragrafe, butoane/linkuri,
imagini (cu text alternativ și fișierul arhivat). Sursă: HTML randat din arhivă.

   python3 -I tools/audit/content-inventory.py <dir-arhiva> <iesire.json> <iesire.md>
"""
import html, json, os, re, sys
from html.parser import HTMLParser

arch, out_json, out_md = sys.argv[1:4]
index = json.load(open(os.path.join(arch, 'index.json')))
res = index['resources']
BLOCK = {'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'li', 'blockquote', 'td', 'th', 'figcaption', 'label', 'option'}


class P(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.in_main = 0; self.skip = 0; self.stack = []; self.items = []; self.buf = []; self.title = ''; self.in_title = False
        self.meta = {}

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == 'title': self.in_title = True
        if tag == 'meta' and (a.get('name') or a.get('property')): self.meta[a.get('name') or a.get('property')] = a.get('content', '')
        if tag == 'main': self.in_main += 1
        if tag in ('script', 'style', 'noscript', 'svg'): self.skip += 1
        if not self.in_main or self.skip: return
        if tag in BLOCK: self.flush(); self.stack.append(tag)
        if tag == 'img':
            self.items.append({'tip': 'imagine', 'src': a.get('src') or a.get('data-src'), 'alt': a.get('alt', '')})
        style = a.get('style') or ''
        for u in re.findall(r'url\((?:["\']|&quot;)?([^)"\']+)', style):
            self.items.append({'tip': 'imagine-fundal', 'src': html.unescape(u), 'alt': ''})
        if tag == 'a' and a.get('href'):
            self.items.append({'tip': 'link', 'href': a.get('href'), 'clase': a.get('class', '')})
        if tag == 'iframe':
            self.items.append({'tip': 'iframe', 'src': a.get('src')})

    def handle_endtag(self, tag):
        if tag == 'title': self.in_title = False
        if tag in ('script', 'style', 'noscript', 'svg') and self.skip: self.skip -= 1
        if tag == 'main' and self.in_main: self.flush(); self.in_main -= 1
        if tag in BLOCK and self.stack and self.stack[-1] == tag: self.flush(); self.stack.pop()

    def handle_data(self, d):
        if self.in_title: self.title += d
        if self.in_main and not self.skip: self.buf.append(d)

    def flush(self):
        t = ' '.join(''.join(self.buf).replace('​', '').split())
        self.buf = []
        if t:
            self.items.append({'tip': self.stack[-1] if self.stack else 'text', 'text': t})


from urllib.parse import unquote, urljoin
NORM = {unquote(k): v['file'] for k, v in res.items()}


def local(src):
    if not src: return None
    full = unquote(urljoin('https://www.sevalia.ro/', html.unescape(src)))
    return NORM.get(full) or NORM.get(full.replace('https://www.sevalia.ro/en/', 'https://www.sevalia.ro/'))


pages = {}
for url, info in sorted(index['pages'].items()):
    if not info.get('ok') or info.get('status') != 200 or not info.get('rendered'): continue
    if '/feed' in url or url.endswith(('.xml', '.txt')): continue
    final = info['final']
    if final in pages: continue
    p = P(); p.feed(open(os.path.join(arch, info['rendered']), errors='replace').read())
    for it in p.items:
        if it.get('tip', '').startswith('imagine'): it['fisier_arhivat'] = local(it['src'])
    pages[final] = {'titlu': ' '.join(p.title.split()), 'descriere': p.meta.get('description', ''),
                    'og_image': p.meta.get('og:image', ''), 'continut': p.items}

json.dump(pages, open(out_json, 'w'), ensure_ascii=False, indent=1)
lines = ['# Inventarul conținutului siteului www.sevalia.ro', '',
         'Generat de `tools/audit/content-inventory.py` din arhiva randată (`database/content/odoo-archive/`).',
         'Pentru fiecare pagină: textele în ordinea apariției, imaginile (cu textul alternativ și fișierul arhivat) și linkurile.',
         'Textele sunt reproduse exact, inclusiv greșelile (semnalate separat în `OBSERVATII-CONTINUT.md`).', '']
missing = 0
for url, pg in pages.items():
    lines += [f'## `{url.replace("https://www.sevalia.ro", "") or "/"}`', '', f'- Titlu: {pg["titlu"]}', f'- Descriere: {pg["descriere"] or "—"}', '']
    for it in pg['continut']:
        t = it['tip']
        if t in ('h1', 'h2', 'h3', 'h4', 'h5', 'h6'): lines.append(f'{"#" * min(int(t[1]) + 2, 6)} {it["text"]}')
        elif t.startswith('imagine'):
            f = it.get('fisier_arhivat'); missing += 0 if f else 1
            lines.append(f'- 🖼 {t}: `{it["src"]}` — alt: „{it["alt"]}” — {"arhivat: " + f if f else "**NEARHIVAT**"}')
        elif t == 'link': lines.append(f'- 🔗 `{it["href"]}`')
        elif t == 'iframe': lines.append(f'- ▣ iframe `{it["src"]}`')
        else: lines.append(it['text'])
        lines.append('')
open(out_md, 'w').write('\n'.join(lines))
print('pagini', len(pages), 'imagini nearhivate', missing)
