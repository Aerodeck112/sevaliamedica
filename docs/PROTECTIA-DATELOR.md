# Protecția datelor — propunere de lucru pentru validarea de către clinică

**Stare: propunere tehnică, nevalidată juridic.** Documentul descrie cum este construită aplicația și ce trebuie
decis de clinică (operatorul de date) împreună cu responsabilul cu protecția datelor (DPO, `dpo@sevalia.ro` conform
siteului). Nu reprezintă consultanță juridică și nu garantează conformitatea; conformitatea depinde de procedurile
clinicii, nu doar de software.

## 1. Scopuri, categorii de date și temeiuri propuse

Temeiurile sunt **propuse** pentru discuție; consimțământul nu este folosit ca temei unic.

| Scop | Date | Temei propus (de validat) | Unde în aplicație |
|------|------|---------------------------|-------------------|
| Răspuns la solicitări de programare de pe site | nume, telefon/e-mail, specialitate/medic dorit, mesaj | demersuri precontractuale la cererea persoanei (art. 6(1)(b) GDPR); pentru datele de sănătate eventual incluse în mesaj: art. 9(2)(h) | modulul Site → Scheduling |
| Programări și evidența prezenței | identificare, contact, programări, istoric | executarea contractului de servicii medicale (art. 6(1)(b)) | Scheduling |
| Asistență medicală și dosar medical | date de sănătate, documente, consimțăminte | art. 9(2)(h) + obligații legale privind documentația medicală (art. 6(1)(c)) | Medical, Rehab |
| Raportare către CAS | CNP/CID, servicii, diagnostice, parafă | obligație legală / contractuală față de CAS (art. 6(1)(c), art. 9(2)(h)) | Cas |
| Evidență financiară | servicii private, plăți, solduri | obligație legală (contabilitate) și contract | Billing |
| Comunicări operaționale (confirmări, remindere) | telefon, e-mail | executarea contractului / interes legitim, cu posibilitate de refuz pe canal | Notifications |
| Comunicări de marketing | contact | **consimțământ** separat, dovedit (dată, sursă), retractabil oricând | Notifications (preferințe) |
| Securitate și audit | conturi, jurnal de acces, IP | interes legitim + obligația de securitate (art. 32) | Identity, Audit |

## 2. Minimizare implementată

- Formularul public cere numai datele necesare contactării; câmpul „Data nașterii” din formularul actual este
  configurabil și propus ascuns (vezi `docs/audit/AUDIT-SITE.md` §5).
- Solicitările de pe site nu creează automat fișe de pacient; recepția identifică persoana.
- CNP-ul este stocat criptat; căutarea se face printr-un index HMAC (`BlindIndex`), iar afișarea este mascată.
- SMS/WhatsApp/notificări nu conțin diagnostice sau documente; linkurile către pacient au token aleator, scop
  limitat, expirare și nu afișează date medicale.
- Datele de sănătate nu intră în analytics, tracking publicitar, URL-uri publice sau servicii AI externe.
  Scripturile de tracking existente pe site (Google Ads, Cookiebot) nu sunt încărcate până la decizia clinicii.
- Logurile tehnice nu conțin parole, tokenuri, CNP complet sau conținut medical (curățare automată în `Audit`).

## 3. Retenție (de stabilit de clinică)

Aplicația permite configurarea termenelor; valorile de mai jos sunt **întrebări**, nu decizii:

| Categorie | Termen | Sursa obligației (de confirmat de clinică) |
|-----------|--------|--------------------------------------------|
| Documentație medicală | ? | legislația privind documentația medicală / normele CNAS |
| Raportări CAS și răspunsuri | ? | contractul cu CAS, normele de aplicare |
| Documente financiar-contabile | ? | legislația contabilă |
| Solicitări de pe site fără programare | propunere: 6 luni | interes legitim, minimizare |
| Jurnal de audit | propunere: 5 ani | securitate, investigarea incidentelor |
| Backupuri | propunere: rotație 30 de zile + lunare 12 luni | continuitate |

Ștergerea la expirare se va face prin taskuri programate, cu raport către responsabil; datele aflate sub obligație
legală de păstrare nu se șterg la cerere.

## 4. Drepturile persoanelor — proceduri susținute de aplicație

| Drept | Suport în aplicație |
|-------|---------------------|
| Acces (art. 15) | export al fișei pacientului (autorizat, auditat), inclusiv lista accesărilor din jurnalul de audit |
| Rectificare (art. 16) | editare cu istoric; documentele medicale finalizate se corectează prin versiune/completare trasabilă |
| Ștergere (art. 17) | anonimizare/ștergere pentru datele fără obligație de păstrare; refuz motivat pentru documentația medicală |
| Restricționare, opoziție | marcaje pe fișă; oprirea comunicărilor operaționale opționale și de marketing |
| Portabilitate | export structurat (JSON/PDF) al datelor furnizate de persoană |
| Retragerea consimțământului de marketing | din preferințe, cu efect imediat și înregistrare |

Fiecare cerere se înregistrează (dată, tip, termen de răspuns de o lună, rezultat). Procedurile operaționale
(verificarea identității solicitantului, cine aprobă) se stabilesc de clinică.

## 5. Securitate (rezumat)

Vezi `docs/SECURITATE.md`: conturi individuale, MFA, roluri și permisiuni pe server, acces medical limitat la relația
de îngrijire, audit, criptare, backupuri criptate cu chei separate, HTTPS și antete de securitate.

## 6. De validat înainte de producție

1. Temeiurile din §1 și informarea persoanelor (politica de confidențialitate a siteului).
2. Termenele de retenție din §3.
3. Lista împuterniciților (hosting, furnizor SMS/e-mail/WhatsApp) și contractele aferente.
4. Evaluarea de impact (DPIA) pentru prelucrarea pe scară largă a datelor de sănătate, dacă se aplică.
5. Procedura de notificare a incidentelor de securitate.
