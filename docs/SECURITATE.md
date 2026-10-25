# Securitate și protecția datelor

Document comun; fiecare modul își adaugă secțiunea. Nu promite „conformitate GDPR garantată”: procedurile
juridice se validează de responsabilii clinicii înainte de producție.

## Controlul accesului la pacienți și programări (module Patients, Scheduling)

### Două niveluri

1. **Date administrative** (identificare, contact, programări, servicii efectuate) — guvernate de permisiuni
   explicite pe server (`patients.*`, `scheduling.*`, `services.*`), verificate pe fiecare rută prin middleware-ul
   `permission:` și, pentru acțiuni pe un obiect, prin politici (`AppointmentPolicy`).
2. **Date medicale** (fișe, documente, recuperare — module viitoare) — cer, *în plus* față de permisiunea
   modulului, o **relație de îngrijire**, verificată de `App\Modules\Patients\Services\CareAccess::canAccessMedical()`.

### Relația de îngrijire

`CareAccess` permite accesul medical dacă utilizatorul este legat de o persoană activă din personal
(`staff_members.user_id`) și există cel puțin una dintre:

| Sursă | Regulă |
|-------|--------|
| Programare | o programare neanulată (nu „solicitare”) cu pacientul, în fereastra configurabilă (implicit 365 de zile în urmă, 60 înainte; Administrare → Reguli programări) |
| Relație explicită | un rând activ în `care_relations` (sursa: desemnare manuală, plan de tratament, trimitere internă), adăugat de cine are `patients.care_relations` |
| Dovadă a altui modul | orice `CareEvidence` înregistrat de un modul (ex. viitorul plan de recuperare) |
| Acces de urgență | „break-glass”: permisiunea `patients.break_glass`, motiv de minimum 15 caractere, durată limitată (implicit 60 de minute, configurabilă), audit `patients.break_glass` |

Fără relație, taburile marcate `requiresCare` nu apar în fișă, iar fișa arată „date medicale ascunse”.
Evaluarea se face la fiecare cerere (fără cache), deci o relație încheiată sau expirată se aplică imediat.

Rolurile implicite: recepția, financiarul și responsabilul CAS au acces administrativ, dar **niciun acces medical**
(nu sunt personal clinic). Administratorul tehnic nu are acces nici la pacienți, nici la calendar.

### Identificatori

- CNP-ul și numărul documentului străin sunt criptate în baza de date (`encrypted`, cheia `APP_KEY`).
- Căutarea exactă și detectarea duplicatelor folosesc un index orb HMAC-SHA256 (`SEVALIA_BLIND_INDEX_KEY`,
  diferită de `APP_KEY`). Valoarea nu poate fi recuperată din index.
- Afișarea implicită e mascată (`1••••••••123`); afișarea completă cere `patients.reveal_identifiers` și se
  auditează (`patients.cnp_revealed`) fără a pune CNP-ul în jurnal.

### Audit (fără conținut sensibil în context)

`patients.viewed`, `patients.searched`, `patients.created/updated`, `patients.cnp_revealed`, `patients.merged`,
`patients.break_glass`, `scheduling.appointment_viewed/created/moved/status`, `scheduling.request_viewed`,
`scheduling.request_received`, `scheduling.service_recorded/…_cancelled`, `scheduling.settings_updated` (cu
valorile vechi și noi), `scheduling.patient_link_issued`.

### Unirea fișelor

Necesită `patients.merge` (implicit doar managerul), reconfirmarea parolei și a codului MFA (middleware
`reauth`), un motiv și bifarea verificării identității. Fișele cu CNP-uri diferite nu pot fi unite. Fișa sursă nu
se șterge: primește `merged_into_id`, iar fiecare modul își mută înregistrările prin `PatientMergeParticipant`.

### Formularul public și linkurile pentru pacient

- Solicitările de pe site se salvează cu datele de contact **criptate** (`request_contact`), fără a crea fișe.
- Linkurile `/p/{token}`: 32 de octeți aleatori, stocați doar ca SHA-256, cu expirare (implicit 14 zile, dar
  niciodată după ora programării), limitare de rată, `Cache-Control: no-store`, `noindex`, `Referrer-Policy:
  no-referrer`. Pagina afișează doar data, ora, locația și tipul generic al serviciului — niciun diagnostic,
  nici numele pacientului. Anularea consumă tokenul.

### Concurență și integritate

Rezervările folosesc tranzacții și blocări `SELECT … FOR UPDATE` pe resursele implicate, în ordine fixă;
verificările de suprapunere sunt citiri cu blocare. Testul `ConcurrentBookingTest` rulează două procese reale și
include un control negativ fără blocări. Istoricul programărilor (`appointment_events`) și jurnalul de audit nu
pot fi modificate sau șterse din aplicație.

## Actualizări, backup și recuperare

### Integritatea codului instalat

- Pachetele de actualizare sunt semnate **Ed25519**; serverul conține doar cheile publice (`config/update-keys/`),
  cheia privată rămâne pe mașina de build. Checksumurile singure nu sunt acceptate: semnătura se verifică peste
  octeții exacți ai manifestului, iar manifestul conține SHA-256 pentru fiecare fișier.
- Cheile de încredere se schimbă numai printr-un release (cod revizuit), nu din panou.
- Arhivele sunt inspectate înainte de extragere: zip-slip (căi absolute, `..`, caractere de control), legături
  simbolice, fișiere speciale, intrări criptate, „zip bomb” (raport de compresie, dimensiune totală, număr de
  intrări), fișiere nedeclarate. Extragerea se face doar în staging, cu dimensiunea reală limitată și SHA-256
  verificat; copierea în `releases/` re-verifică fiecare fișier.
- Pachetele nu conțin `.env`, date, seedere demo, teste sau depozite `.git`.

### Acces

- Tabul **Actualizări** și **Backupuri**: permisiunile `updates.manage` și `backups.manage`, acordate implicit doar
  rolului *administrator tehnic* (care nu are acces la datele medicale).
- Încărcarea și pornirea unei instalări, acțiunile de recuperare și descărcarea backupurilor cer **reautentificare
  cu parolă + cod MFA** (valabilă 10 minute); acțiunile distructive cer în plus tastarea confirmării.
- Toate operațiile se înregistrează în jurnalul de audit (`updates.*`, `backup.*`), fără conținutul datelor.
- În modul mentenanță rămân accesibile doar autentificarea și tabul Actualizări.

### Backupuri

- Criptare XChaCha20-Poly1305 (libsodium), cu cheie dedicată (`shared/keys/backup.key`, 0600), separată de `APP_KEY`
  și de backupuri. Autentificarea fiecărui bloc detectează modificarea; marcajul final detectează trunchierea.
- Fișierele de backup și cheile sunt în afara `public_html`; `.htaccess` blochează oricum extensiile `.sbk`, `.key`,
  `.hash`, `.env`, `.sql`, `.zip` și fișierele ascunse.
- Descărcarea unui backup (criptat) este auditată.

### Recuperare de urgență (`recovery.php`)

- Independent de framework; funcționează și când aplicația nu pornește.
- Autentificare cu cheie de recuperare de 30 de caractere (stocată doar ca `password_hash`), limitare a încercărilor
  pe IP (5 / 15 min) și global (20 / 15 min) cu fișier blocat exclusiv, sesiune de 15 minute cu cookie `HttpOnly`,
  `SameSite=Strict`, `Secure` pe HTTPS, token CSRF, CSP `default-src 'none'`, doar HTTPS.
- Funcții limitate: comutare la o versiune existentă din `releases/`, pornire/oprire mentenanță, citirea jurnalului.
  Nu restaurează baza de date și nu execută cod încărcat.
- Se poate dezactiva cu `shared/keys/recovery.disabled`. Acțiunile se jurnalizează în `shared/updates/recovery.log`
  (cu hash de IP).

### Riscuri reziduale cunoscute

- Un atacator cu acces de scriere la contul de hosting poate modifica direct codul sau `current.json`; semnătura
  protejează canalul de actualizare, nu contul. Măsuri: parolă puternică și 2FA pentru contul cPanel, SFTP/SSH cu chei.
- Migrările MySQL nu sunt tranzacționale; recuperarea după o migrare eșuată se bazează pe backupul verificat făcut
  cu aplicația în mentenanță.
