# Adaugă în instantaneu o pagină publică descoperită ulterior (ex. pagina de confirmare a formularului).
# Utilizare: python3 -I tools/audit/add_page.py <cale> <fisier-html-descarcat> "<nota>"
import json
import re
import shutil
import sys

path, src, note = sys.argv[1], sys.argv[2], sys.argv[3]
name = path.strip('/').replace('/', '__') or 'home'
dest = f'database/content/odoo-snapshot/pages/{name}.html'
shutil.copyfile(src, dest)
html = open(dest, encoding='utf-8').read()
title = re.search(r'<title>(.*?)</title>', html, re.S)
desc = re.search(r'<meta name="description" content="([^"]*)"', html)
p = 'database/content/odoo-snapshot/pages.json'
data = json.load(open(p, encoding='utf-8'))
url = 'https://www.sevalia.ro' + path
data[url] = {"code": 200, "final": url, "file": name, "title": title.group(1).strip() if title else None,
             "description": desc.group(1) if desc else None, "note": note}
json.dump(data, open(p, 'w', encoding='utf-8'), indent=1, ensure_ascii=False)
print(dest, data[url]['title'])
