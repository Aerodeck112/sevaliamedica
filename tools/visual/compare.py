# Compară capturile reconstrucției cu referința siteului original.
#   python3 -I tools/visual/compare.py docs/audit/referinta storage/app/visual <dir-iesire> [slug,...]
# Pentru fiecare pagină și rezoluție: diferența de înălțime și procentul de pixeli diferiți pe zona comună
# (după o ușoară estompare, ca diferențele de antialiasing să nu conteze). Scrie rezultate.json și imagini
# alăturate (original | reconstrucție | diferențe) pentru paginile cerute.
import json
import os
import sys

from PIL import Image, ImageChops, ImageFilter

Image.MAX_IMAGE_PIXELS = None
ref_root, local_root, out_dir = sys.argv[1], sys.argv[2], sys.argv[3]
side_by_side = set(sys.argv[4].split(',')) if len(sys.argv) > 4 and sys.argv[4] else set()
widths = {'desktop': 1440, 'mobil': 390}
results = {}
os.makedirs(out_dir, exist_ok=True)

for vp, width in widths.items():
    local_dir = os.path.join(local_root, vp)
    if not os.path.isdir(local_dir):
        continue
    for f in sorted(os.listdir(local_dir)):
        slug = f[:-4]
        ref_path = os.path.join(ref_root, vp, slug + '.jpg')
        if not os.path.exists(ref_path):
            continue
        ref = Image.open(ref_path).convert('RGB')
        loc = Image.open(os.path.join(local_dir, f)).convert('RGB')
        if loc.width != width:
            loc = loc.resize((width, round(loc.height * width / loc.width)), Image.LANCZOS)
        h = min(ref.height, loc.height)
        a = ref.crop((0, 0, width, h)).filter(ImageFilter.BoxBlur(2))
        b = loc.crop((0, 0, width, h)).filter(ImageFilter.BoxBlur(2))
        diff = ImageChops.difference(a, b).convert('L').point(lambda v: 255 if v > 40 else 0)
        changed = sum(diff.histogram()[255:]) / (width * h) * 100
        results.setdefault(slug, {})[vp] = {
            'inaltime_original': ref.height,
            'inaltime_reconstructie': loc.height,
            'diferenta_inaltime_pct': round((loc.height - ref.height) / ref.height * 100, 1),
            'pixeli_diferiti_pct': round(changed, 1),
        }
        if slug in side_by_side:
            scale = 0.5 if vp == 'desktop' else 1.0
            w = int(width * scale)
            hh = max(ref.height, loc.height)
            canvas = Image.new('RGB', (w * 3 + 40, int(hh * scale)), 'white')
            canvas.paste(ref.resize((w, int(ref.height * scale))), (0, 0))
            canvas.paste(loc.resize((w, int(loc.height * scale))), (w + 20, 0))
            red = Image.new('RGB', (width, h), (230, 30, 30))
            overlay = Image.composite(red, ref.crop((0, 0, width, h)).point(lambda v: 255 - (255 - v) // 3), diff)
            canvas.paste(overlay.resize((w, int(h * scale))), (2 * w + 40, 0))
            canvas = canvas.crop((0, 0, canvas.width, min(canvas.height, 12000)))
            canvas.save(os.path.join(out_dir, f'{slug}-{vp}.jpg'), quality=70, optimize=True)

json.dump(results, open(os.path.join(out_dir, 'rezultate.json'), 'w'), indent=1, ensure_ascii=False)
for slug, r in sorted(results.items()):
    print(slug, {k: (v['pixeli_diferiti_pct'], v['diferenta_inaltime_pct']) for k, v in r.items()})
