# Matricea rolurilor și permisiunilor

Document generat de `php artisan sevalia:permissions-matrix` din declarațiile modulelor. Nu editați manual.

Acordările de mai jos sunt cele **implicite**. Administratorul poate ajusta rolurile din panou; verificarea
se face pe server la fiecare acțiune. Accesul la datele medicale ale unui pacient cere, în plus față de
permisiune, o relație de îngrijire (vezi `SECURITATE.md`). Administratorul tehnic nu are implicit acces medical.

| Modul | Permisiune | Descriere | Administrator tehnic | Manager clinică | Recepție | Medic | Terapeut / fizioterapeut | Asistent medical | Responsabil CAS | Financiar / casierie | Editor site |
|---|---|---|---|---|---|---|---|---|---|---|---|
| Backup | `backups.manage` | Creează, verifică și descarcă backupurile criptate | ✔ |  |  |  |  |  |  |  |  |
| Clinică | `clinic.manage` | Administrează locații, specialități, personal, săli, aparatură și catalogul de servicii | ✔ | ✔ |  |  |  |  |  |  |  |
| Conturi și securitate | `users.manage` | Administrează conturile de utilizator și rolurile | ✔ |  |  |  |  |  |  |  |  |
| Conturi și securitate | `audit.view` | Consultă jurnalul de audit | ✔ | ✔ |  |  |  |  |  |  |  |
| Conturi și securitate | `settings.manage` | Modifică setările generale ale aplicației | ✔ |  |  |  |  |  |  |  |  |
| Actualizări | `updates.manage` | Încarcă și instalează pachete de actualizare semnate; decide recuperarea la eșec | ✔ |  |  |  |  |  |  |  |  |
