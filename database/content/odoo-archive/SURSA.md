# Arhiva completă a siteului www.sevalia.ro (înainte de dezafectare)

Clinica a decis ștergerea siteului vechi; această arhivă este **sursa unică** pentru conținutul public.

- Realizată: 2026-10-26, cu Chromium (Playwright), `Accept-Language: ro-RO`, cookie `frontend_lang=ro_RO`.
- Unelte: `tools/audit/archive.cjs` (browser: pagină randată după JavaScript, derulare completă, salvarea tuturor
  resurselor de pe domeniu încărcate efectiv) și `tools/audit/archive-refs.py` (resurse doar referite în HTML:
  og:image, imagini pentru mobil, `data-original-src`, `srcset`, fișiere legate).
- `rendered/` — HTML-ul randat al fiecărei pagini (română și versiunea `/en`).
- `files/` — resursele, nemodificate, cu numele = primele 20 de caractere din SHA-256.
- `index.json` — `resources`: URL original → fișier, tip MIME, octeți, SHA-256, paginile care îl folosesc;
  `pages`: URL → stare HTTP, URL final, fișier randat, resursele încărcate.
- `lipsa.json` — referințe care nu au putut fi descărcate (22 de nume de fișiere SVG care apar doar ca atribute
  interne ale editorului Odoo și răspund 404 — nu sunt imagini afișate).
- `continut.json` și `docs/audit/INVENTAR-CONTINUT.md` — inventarul textelor, imaginilor și linkurilor pe pagini.

Completează instantaneul din `../odoo-snapshot/` (HTML brut + imaginile din `<img>`).
Nu conține date de pacienți.
