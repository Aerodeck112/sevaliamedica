# Manual scurt — administrator tehnic

Rolul *administrator tehnic* gestionează conturile, actualizările și backupurile. **Nu are acces la datele medicale.**

## Conturi

- **Panou → Administrare → Utilizatori**: cont nou (persoana primește e-mail pentru setarea parolei), roluri,
  dezactivare, închiderea sesiunilor, resetarea MFA. Modificările cer reconfirmarea parolei și a codului.
- Fiecare persoană are cont propriu. Nu partajați conturi. Conturile plecaților se dezactivează imediat.

## Actualizări

1. Primiți de la responsabilul de release fișierul `sevalia-<versiune>-update.zip`.
2. **Panou → Administrare → Actualizări** → *Încarcă și verifică*. Dacă pachetul e respins, motivul este afișat —
   nu încercați să-l „reparați”; cereți un pachet nou.
3. Citiți **Modificări** și **Verificări preliminare**. Toate trebuie să fie „ok” (sau „atenție” înțeleasă).
4. Alegeți un moment cu activitate redusă (seara): instalarea pune siteul și panoul în mentenanță câteva minute.
5. Bifați confirmarea → *Instalează*. Lăsați pagina deschisă; dacă se oprește, apăsați *Continuă*.
6. La final: stare „Instalat”. Verificați autentificarea și o pagină publică.

### Dacă instalarea eșuează

- „Revenit la versiunea anterioară”: nimic de făcut, aplicația funcționează pe versiunea veche. Trimiteți eroarea
  responsabilului de release.
- „Eșuat — necesită decizie”: aplicația este în mentenanță. Opțiuni:
  - **Restaurează baza de date din backupul de dinainte** — recomandat dacă nu știți cauza; nu se pierd date
    (scrierile au fost oprite înainte de backup);
  - **Reia pasul** — doar dacă ați remediat cauza (ex. spațiu pe disc);
  - **Ieși din mentenanță** — doar după verificare tehnică.
- Dacă panoul nu se mai deschide deloc: `https://<domeniu>/recovery.php` cu **cheia de recuperare** (păstrată offline)
  → activați versiunea anterioară și opriți mentenanța; apoi contactați responsabilul tehnic pentru baza de date.

## Backupuri

- **Panou → Administrare → Backupuri**: backup zilnic automat (02:30) al bazei de date și săptămânal al documentelor.
- Starea trebuie să fie „Verificat”. Un backup „Eșuat” sau lipsa backupului de azi se investighează imediat
  (spațiu pe disc, cron).
- *Descarcă* copiază fișierul criptat (pentru o copie în afara serverului); operația este auditată.
- Lunar: test de restaurare într-o bază separată (`docs/BACKUP-RESTAURARE.md` §5).

## Ce păstrați offline (în seif, nu pe calculatorul de birou)

- cheia de recuperare (afișată o singură dată la instalare);
- copia fișierului `shared/.env` și a directorului `shared/keys/` (fără `backup.key` backupurile nu pot fi citite);
- datele de acces cPanel (cu autentificare în doi pași activată).

## Verificări periodice

- săptămânal: **Actualizări → Verificarea mediului** fără probleme; jurnalul de audit pentru autentificări eșuate
  repetate;
- lunar: test de restaurare; revizuirea conturilor active și a rolurilor.
