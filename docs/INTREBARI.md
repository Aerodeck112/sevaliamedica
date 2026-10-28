# Întrebări deschise pentru clinică

Grupate după ce blochează. Până la răspuns, lucrul continuă pe variantele marcate „implicit”, ușor de schimbat.

## A. Hosting cPanel (blochează instalarea, nu dezvoltarea)

**Răspuns clinică 2026-10-25:** hostingul suportă tot ce e necesar (PHP 8.3+, extensii, SSH, Composer, cron).
Detaliile exacte (versiuni, limite) se verifică automat la instalare cu comanda de verificare a mediului.

1. Versiunile PHP disponibile în MultiPHP (necesar ≥ 8.3) și extensiile: `pdo_mysql`, `mbstring`, `intl`, `sodium`,
   `zip`, `gd` (cu WebP), `fileinfo`, `openssl`, `dom`/`xml`, `curl`.
2. Versiunea MariaDB/MySQL, limita de conexiuni, dacă userul DB poate crea tabele/indici.
3. Există acces SSH/Terminal? Composer? Se poate rula `php` din cron?
4. `memory_limit`, `upload_max_filesize`, `max_execution_time`, spațiul total și cât e liber.
5. Se pot face conexiuni HTTPS ieșire (SMTP, SMS, WhatsApp API)? Este `exec()`/`proc_open()` dezactivat?
6. Siteul va fi pe domeniul principal (`public_html`) sau pe un addon/subdomeniu cu document root configurabil?
7. Există un subdomeniu pentru staging (ex. `staging.sevalia.ro`) cu bază de date separată?
8. Ce backup oferă hostingul (JetBackup, zilnic?) și unde putem stoca backupuri externe criptate?

**Implicit:** structura din `ARHITECTURA.md` §4, care funcționează fără SSH și fără symlinkuri.

## B. Conținut și site

1. Accesul la Odoo (export pagini/imagini în rezoluție originală, lista formularelor și a destinatarilor e-mail).
2. Confirmați lista medicilor afișați și specialitățile (formularul diferă de pagina echipei).
3. Pagina `/neurologie` lipsește (404) deși e în meniu: o creăm sau eliminăm linkul?
4. Versiunea `/en`: o păstrăm (traducere reală) sau o redirecționăm 301 către română?
5. Cookiebot și Google Ads: rămân? Pe paginile cu formular medical recomandăm să nu încărcăm tracking publicitar.
6. `/medicina-de-familie-band-mures`: este punct de lucru activ al clinicii?

**Implicit:** păstrăm toate URL-urile în română; `/en/*` → 301 către echivalentul în română (de confirmat);
marcajele de tracking nu se încarcă până la confirmare.

## C. Operațional (programări, recuperare)

**Răspuns clinică 2026-10-25:** sălile, aparatele, duratele și regulile se introduc de clinică din panou.
Aplicația nu conține valori operaționale fixe; punctele 1–3 de mai jos devin configurare, nu întrebări blocante.

1. Lista sălilor și aparatelor care trebuie rezervate împreună cu terapeutul (ex. AlterG, Hydro Physio, EMSELLA).
2. Duratele standard pe tip de consultație/procedură, pauzele și regulile pentru confirmare automată.
3. Pachetele de ședințe vândute (dacă există) și regulile lor.
4. Ce canale de comunicare folosiți azi (SMS — ce furnizor? WhatsApp Business?).

**Implicit:** toate solicitările de pe site rămân „solicitare” până la confirmarea recepției; confirmarea automată
e dezactivată.

## D. CAS (blochează raportarea reală)

**Răspuns clinică 2026-10-25:** raportarea CAS se tratează la final. Modulul CAS devine ultima etapă de
implementare; întrebările de mai jos rămân deschise până atunci.

1. Casa de asigurări (CAS Mureș?) și tipurile de contracte: ambulatoriu de specialitate clinic (Anexa 007)?
   recuperare ambulatorie (Anexa 011)? medicină de familie?
2. Specialitățile și punctele de lucru contractate, medicii cu parafă înregistrați, codurile furnizorului.
3. Aplicația de raportare folosită acum (aplicația CNAS sau un produs comercial) și cine raportează.
4. Exemple **anonimizate** de fișier raportat acceptat și de fișier de răspuns (feedback).
5. Certificatele digitale (eToken) — cine le deține, pe ce calculator.
6. Regulile contractuale specifice (plafoane, număr de servicii) — numai din documentele contractului.

**Implicit:** modulul CAS implementează validare internă + XML validat pe XSD-ul oficial + import răspuns, cu
flux manual de transmitere. Nicio regulă contractuală nu este activă până la configurarea ei din surse confirmate.

## E. Juridic și protecția datelor

1. Persoana responsabilă (DPO `dpo@sevalia.ro`?) care validează scopurile prelucrării și termenele de retenție.
2. Modelele de consimțământ informat folosite acum, pe proceduri.
3. Facturare: rămâne în sistemul actual (ce sistem?) sau se dorește integrare ulterioară?

## F. Asistentul de pe site (adăugat 2026-10-27)

1. Asistentul Sevalia răspunde acum local, numai din conținutul public al siteului, fără AI extern și fără a păstra
   întrebările. Doriți, opțional, un model AI extern (ex. Claude) pentru răspunsuri mai naturale? Ar necesita un
   contract de prelucrare a datelor cu furnizorul, informarea vizitatorilor și o cheie API; răspunsurile ar rămâne
   limitate la conținutul siteului.
2. La mesajele despre gânduri de autovătămare asistentul trimite la 112. Doriți să adăugăm și o linie de sprijin
   emoțional (număr confirmat de clinică)?

## G. Subsol și locații (adăugat 2026-10-28)

1. Adresa exactă a cabinetului de medicină de familie din Band (stradă, număr). Arhiva siteului vechi spune doar
   „sediul nostru din Band” și telefonul +40 742 281 438; harta din subsol arată deocamdată localitatea
   („Band, județul Mureș”). Adresa se poate schimba și din panou: Site → Pagini → Subsol.
2. Programul cabinetului din Band, ca să fie adăugat ca locație în Administrare → Locații (pentru programări).
