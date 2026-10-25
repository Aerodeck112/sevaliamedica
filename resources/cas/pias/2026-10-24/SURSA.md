# Specificații PIAS (CNAS) — copie de lucru

- Sursă: https://portal.cnas.ro/cnas/pias/specificatii (pagină publică CNAS)
- Descărcat: 2026-10-25 10:18 UTC
- `Last-Modified` raportat de server: 24 Oct 2026 11:31 GMT (ZIP-uri XSD și anexe), 07 Apr 2026 (Anexa 001)
- Document principal: „Specificaţii de interfaţare cu SIUI+PE+CEAS pentru aplicaţiile de raportare ale
  furnizorilor de servicii medicale şi farmaceutice”, versiunea **3.7.34 din 20.10.2026**
- Istoric oficial al modificărilor: https://github.com/portal-pias/specificatii/commits

| Fișier | Conținut |
|--------|----------|
| `xsd-raportare/` | Conținutul `StructuriRaportare.zip` (XSD raportare, nomenclatoare, feedback) |
| `xsd-validare/` | Conținutul `StructuriValidare.zip` (XSD prevalidare online) |
| `documentatie/` | Specificația principală și anexele 001, 007 (ambulatoriu de specialitate clinic), 011 (recuperare ambulatorie) |
| `SHA256SUMS` | Sumele de control ale fișierelor, pentru a detecta modificări la o descărcare viitoare |

Fișierele sunt publicate de CNAS și sunt păstrate nemodificate. Înainte de fiecare raportare reală se verifică
dacă există o versiune mai nouă; aplicația înregistrează versiunea XSD folosită pentru fiecare lot.
