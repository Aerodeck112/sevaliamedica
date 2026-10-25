# Planul proiectului SEVALIA

Document persistent. Se actualizează la fiecare etapă. Starea sinceră a fiecărei funcționalități se ține în
[`STATUS.md`](STATUS.md); întrebările deschise în [`INTREBARI.md`](INTREBARI.md); deciziile tehnice în
[`ARHITECTURA.md`](ARHITECTURA.md).

Ultima actualizare: 2026-10-25.

## Obiectiv

O singură aplicație (monolit modular, PHP/Laravel + MariaDB/MySQL, randare pe server) care înlocuiește siteul
actual `www.sevalia.ro` (Odoo) păstrându-i fidel aspectul și conținutul și care adaugă CMS, CRM clinică,
programări, dosar medical, recuperare medicală, pregătirea raportărilor CAS, notificări, rapoarte și actualizări
semnate prin ZIP — rulabilă pe hosting cPanel fără Docker, root sau procese permanente.

## Principii de lucru

1. **Fidelitate față de siteul existent.** Conținutul, culorile, fonturile, meniurile și ordinea secțiunilor se
   importă din siteul public, nu se rescriu. Greșelile observate se semnalează în
   [`audit/OBSERVATII-CONTINUT.md`](audit/OBSERVATII-CONTINUT.md), nu se corectează în tăcere.
2. **Nu inventăm date.** Tarife, contracte CAS, coduri, reguli de eligibilitate, medici și certificări vin
   numai din surse confirmate (site public, documente CNAS oficiale, confirmarea clinicii). Ce lipsește apare ca
   „blocat de acces/date” în `STATUS.md`.
3. **Date fictive** în demonstrații, teste și capturi. Seederul demonstrativ e separat de migrări și nu rulează în
   producție.
4. **Securitate implicită:** autorizare pe server pentru fiecare acțiune, audit pentru date sensibile, secrete și
   documente în afara `public_html`.
5. **Capturi de ecran la fiecare etapă**, salvate în `docs/capturi/etapa-N/`.

## Etape, dependențe și criterii de acceptanță

| # | Etapă | Depinde de | Criterii de acceptanță (rezumat) |
|---|-------|-----------|----------------------------------|
| 1 | Audit site, mediu, întrebări | — | Inventar URL/meniuri/formulare/imagini; capturi desktop+mobil ale originalului; paletă și fonturi; specificații CNAS descărcate cu dată și sursă; listă de întrebări blocante. |
| 2 | Arhitectură, model de date, securitate (fundație) | 1 | Schelet Laravel modular; autentificare cu MFA, roluri și permisiuni, audit, setări; layout panou; teste care rulează pe MariaDB. |
| 3 | Site public fidel + CMS | 2 | Toate URL-urile publice existente răspund 200 (sau 301 documentat); comparație vizuală la 1440 px și 390 px; editor pe blocuri controlate cu draft/publicare/istoric/restaurare/blocare editare concurentă; media, meniuri, SEO, redirecturi. |
| 4 | Pacienți, programări, recepție | 2 | Formular public → solicitare în CRM; calendar zi/săptămână cu filtre; rezervare simultană personal+sală+aparat fără conflicte (inclusiv concurent); serii, listă de așteptare, istoric modificări; statusuri distincte. |
| 5 | Dosar medical și recuperare | 4 | Fișe configurabile pe specialitățile confirmate; draft/finalizat; corecții versionate; flux consultație → plan → serie → ședințe → reevaluare → închidere; evidența ședințelor rămase. |
| 6 | Raportare CAS | 5 | Nomenclatoare versionate; validare internă cu legătură la înregistrarea de corectat; XML generat și validat pe XSD-ul oficial; loturi cu checksum, fișier exact și răspuns păstrate; reconciliere; stări distincte validat intern/XSD/exportat/transmis/acceptat/respins. |
| 7 | Notificări, financiar, rapoarte, dashboard pe roluri | 4 | Șabloane editabile; jurnal livrări; retry fără dubluri; link de anulare/reprogramare cu token limitat; evidență încasări și solduri; rapoarte cu definiții de indicatori; exporturi auditate. |
| 8 | Actualizări ZIP, backup, recuperare | 2 (proiectat din start) | Pachet semnat Ed25519 verificat; extragere în staging; backup verificat înainte; blocare concurență; pași reluabili; recuperare fără aplicația pornită; teste pentru pachet invalid/semnătură greșită/spațiu insuficient/migrare eșuată/întrerupere. |
| 9 | Teste complete, staging, lansare | toate | Cele 12 scenarii din cerințe rulate și documentate; raport SEO/performanță; manuale; pachete ZIP de instalare și actualizare. |

Etapele 3, 4 și 8 pot avansa în paralel după fundație; 5, 6 și 7 se bazează pe modelul de programări din etapa 4.

## Organizarea lucrului

- Fundația (etapa 2) e implementată centralizat, cu contracte clare între module (vezi `ARHITECTURA.md`).
- Modulele independente sunt dezvoltate în paralel pe ramuri separate și integrate apoi pe ramura principală de
  lucru, cu rularea întregii suite de teste după fiecare integrare.

## Jurnal de etape

- **2026-10-25 — Etapa 1:** audit inițial al siteului public (146 URL-uri accesate, 62 pagini capturate desktop și
  mobil), extragerea paletei și fonturilor din CSS-ul publicat, descărcarea specificațiilor PIAS oficiale.
  Detalii în `docs/audit/`.
