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
