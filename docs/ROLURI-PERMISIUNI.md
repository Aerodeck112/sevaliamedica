# Matricea rolurilor și permisiunilor

Document generat de `php artisan sevalia:permissions-matrix` din declarațiile modulelor. Nu editați manual.

Acordările de mai jos sunt cele **implicite**. Administratorul poate ajusta rolurile din panou; verificarea
se face pe server la fiecare acțiune. Accesul la datele medicale ale unui pacient cere, în plus față de
permisiune, o relație de îngrijire (vezi `SECURITATE.md`). Administratorul tehnic nu are implicit acces medical.

| Modul | Permisiune | Descriere | Administrator tehnic | Manager clinică | Recepție | Medic | Terapeut / fizioterapeut | Asistent medical | Responsabil CAS | Financiar / casierie | Editor site |
|---|---|---|---|---|---|---|---|---|---|---|---|
| Clinică | `clinic.manage` | Administrează locații, specialități, personal, săli, aparatură și catalogul de servicii | ✔ | ✔ |  |  |  |  |  |  |  |
| Conturi și securitate | `users.manage` | Administrează conturile de utilizator și rolurile | ✔ |  |  |  |  |  |  |  |  |
| Conturi și securitate | `audit.view` | Consultă jurnalul de audit | ✔ | ✔ |  |  |  |  |  |  |  |
| Conturi și securitate | `settings.manage` | Modifică setările generale ale aplicației | ✔ |  |  |  |  |  |  |  |  |
| Pacienți | `patients.view` | Consultă fișele administrative ale pacienților (identificare, contact, programări) |  | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |  |
| Pacienți | `patients.create` | Creează fișe de pacient |  | ✔ | ✔ | ✔ |  |  |  |  |  |
| Pacienți | `patients.edit` | Modifică datele administrative și reprezentanții |  | ✔ | ✔ | ✔ |  | ✔ |  |  |  |
| Pacienți | `patients.reveal_identifiers` | Afișează CNP-ul complet (acțiune auditată) |  | ✔ | ✔ | ✔ |  |  | ✔ |  |  |
| Pacienți | `patients.merge` | Unește fișe duplicate (cu reconfirmare și motiv) |  | ✔ |  |  |  |  |  |  |  |
| Pacienți | `patients.care_relations` | Desemnează relații explicite de îngrijire |  | ✔ |  |  |  |  |  |  |  |
| Pacienți | `patients.break_glass` | Acces de urgență la datele medicale fără relație de îngrijire (cu motiv, auditat) |  |  |  | ✔ |  |  |  |  |  |
| Programări | `scheduling.view` | Vede calendarul și programările |  | ✔ | ✔ | ✔ | ✔ | ✔ |  |  |  |
| Programări | `scheduling.manage` | Creează, mută, confirmă și anulează programări |  | ✔ | ✔ |  |  |  |  |  |  |
| Programări | `scheduling.attend` | Marchează sosirea, începerea și finalizarea propriilor programări |  | ✔ |  | ✔ | ✔ | ✔ |  |  |  |
| Programări | `scheduling.override` | Programează în afara programului de lucru (cu motiv) |  | ✔ | ✔ |  |  |  |  |  |  |
| Programări | `scheduling.requests` | Preia solicitările de programare venite de pe site |  | ✔ | ✔ |  |  |  |  |  |  |
| Programări | `scheduling.settings` | Modifică regulile de programare și de acces (setări auditate) |  | ✔ |  |  |  |  |  |  |  |
| Programări | `schedules.manage` | Administrează programul de lucru, concediile și închiderile |  | ✔ | ✔ |  |  |  |  |  |  |
| Programări | `waitlist.manage` | Administrează lista de așteptare și ofertele de intervale |  | ✔ | ✔ |  |  |  |  |  |  |
| Programări | `services.view` | Vede serviciile efectuate ale pacienților |  | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |  |
| Programări | `services.record` | Înregistrează serviciile efectuate |  | ✔ | ✔ | ✔ | ✔ | ✔ |  |  |  |
| Programări | `services.correct` | Anulează / corectează servicii efectuate (cu motiv) |  | ✔ | ✔ | ✔ |  |  |  |  |  |
