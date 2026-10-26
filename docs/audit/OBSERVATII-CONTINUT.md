# Observații de conținut — pentru revizuirea clinicii

Textele au fost importate **exact** cum apar pe site. Lista de mai jos semnalează formulări care ar merita
revizuite. Nimic de aici nu a fost modificat; decizia aparține clinicii. Lista se completează pe măsură ce
paginile sunt verificate în CMS.

## Greșeli de exprimare / ortografie

| Pagina | Text actual | Observație |
|--------|-------------|-----------|
| `/echipa-noastra`, `/` | „Electroterapie este o componentă a fizioterapie ce…” | acord: „a fizioterapiei, care…” |
| `/echipa-noastra` | „…acționând prin fortifierea musculaturii planșeului pelvin” | „fortificarea” |
| `/contactus` | „unul dintre colegii noștri te vor contacta” | acord: „te va contacta” |
| `/contactus` | etichete „Data nasterii”, „Custom Text” | diacritice lipsă; „Custom Text” e o etichetă implicită Odoo |
| mai multe | „Programează o consultație​” | conține caractere invizibile (U+200B) moștenite din editorul Odoo |
| titluri | „Tîrgu Mureș”, „Târgu Mureș”, „Targu Mures” | grafie inconsecventă |
| titluri | „Bai galvanice”, „Dus subacval”, „Baia Stanger – … profunda” | diacritice lipsă în `<title>` |
| subsol | „© Copyright Sevalia Medica 2023” | an neactualizat |

## Afirmații care merită verificate (medical / comercial)

| Pagina | Afirmație | De ce |
|--------|-----------|-------|
| `/bai-galvanice` (titlu) | „Efecte dovedite în afecțiunile reumatologice și neurologice” | afirmație de eficacitate; recomandăm sursă sau reformulare |
| `/` | „Servicii decontate integral prin OMNIASIG, Allianz, CNAS” | depinde de contractele în vigoare și de serviciu; de confirmat |
| `/echipa-noastra`, `/contactus` | „Peste 1.000 de pacienți și-au îmbunătățit sănătatea” | afirmație cantitativă; de confirmat |
| `/` (hero) | „CARDIOLOGIE PEDIATRICĂ \| PEDIATRIE \| NEUROLOGIE \| PSIHIATRIE” | „Neurologie” are link 404 în meniu |
| `/contactus` | lista de medici din formular nu îl include pe dr. Crauciuc Andrei, prezent pe pagina echipei | de sincronizat |

## Probleme tehnice vizibile pentru vizitatori

- Articolul „Deficitul de vitamina D” conține o imagine goală (`<img>` fără sursă) după paragraful „Ce spun studiile?”.

- Hărțile din subsol afișează „O adresă trebuie specificată pentru a putea fi încorporată o hartă”.
- Link telefonic invalid către 0365 403 001.
- Meniul „Neurologie” și linkul `/dry-needling` duc la „Page Not Found”.
