# Matricea rolurilor și permisiunilor

Document generat de `php artisan sevalia:permissions-matrix` din declarațiile modulelor. Nu editați manual.

Acordările de mai jos sunt cele **implicite**. Administratorul poate ajusta rolurile din panou; verificarea
se face pe server la fiecare acțiune. Accesul la datele medicale ale unui pacient cere, în plus față de
permisiune, o relație de îngrijire (vezi `SECURITATE.md`). Administratorul tehnic nu are implicit acces medical.

| Modul | Permisiune | Descriere | Administrator tehnic | Manager clinică | Recepție | Medic | Terapeut / fizioterapeut | Asistent medical | Responsabil CAS | Financiar / casierie | Editor site |
|---|---|---|---|---|---|---|---|---|---|---|---|
| Backup | `backups.manage` | Creează, verifică și descarcă backupurile criptate | ✔ |  |  |  |  |  |  |  |  |
| Clinică | `clinic.manage` | Administrează locații, specialități, personal, săli, aparatură și catalogul de servicii | ✔ | ✔ |  |  |  |  |  |  |  |
| Site (CMS) | `cms.edit` | Editează paginile și articolele (ciorne) |  | ✔ |  |  |  |  |  |  | ✔ |
| Site (CMS) | `cms.publish` | Publică, retrage și restaurează versiuni ale paginilor |  | ✔ |  |  |  |  |  |  | ✔ |
| Site (CMS) | `cms.media` | Administrează biblioteca media |  | ✔ |  |  |  |  |  |  | ✔ |
| Site (CMS) | `cms.menus` | Administrează meniurile siteului |  | ✔ |  |  |  |  |  |  | ✔ |
| Site (CMS) | `cms.seo` | Modifică datele SEO ale paginilor |  | ✔ |  |  |  |  |  |  | ✔ |
| Site (CMS) | `cms.redirects` | Administrează redirecturile |  | ✔ |  |  |  |  |  |  | ✔ |
| Site (CMS) | `cms.settings` | Modifică setările siteului (contact, rețele sociale, formular, cookie) |  | ✔ |  |  |  |  |  |  | ✔ |
| Site (CMS) | `cms.tracking` | Configurează etichetele de măsurare și marketing (Google, Meta) |  | ✔ |  |  |  |  |  |  |  |
| Site (CMS) | `cms.submissions` | Consultă mesajele primite prin formularele siteului |  | ✔ | ✔ |  |  |  |  |  |  |
| Conturi și securitate | `users.manage` | Administrează conturile de utilizator și rolurile | ✔ |  |  |  |  |  |  |  |  |
| Conturi și securitate | `audit.view` | Consultă jurnalul de audit | ✔ | ✔ |  |  |  |  |  |  |  |
| Conturi și securitate | `settings.manage` | Modifică setările generale ale aplicației | ✔ |  |  |  |  |  |  |  |  |
| Dosar medical | `medical.view` | Consultă fișele, documentele, consimțămintele și avertizările pacienților în îngrijire |  |  |  | ✔ | ✔ | ✔ |  |  |  |
| Dosar medical | `medical.write` | Redactează fișe (draft), încarcă documente, introduce avertizări |  |  |  | ✔ |  |  |  |  |  |
| Dosar medical | `medical.finalize` | Finalizează propriile fișe (cu reconfirmarea identității) |  |  |  | ✔ |  |  |  |  |  |
| Dosar medical | `medical.amend` | Completează / rectifică fișe finalizate (versiune nouă, cu motiv) |  |  |  | ✔ |  |  |  |  |  |
| Dosar medical | `medical.consents` | Înregistrează și retrage consimțămintele pacienților |  |  |  | ✔ | ✔ | ✔ |  |  |  |
| Dosar medical | `medical.templates` | Configurează șabloanele de fișă pe specialități |  | ✔ |  | ✔ |  |  |  |  |  |
| Dosar medical | `medical.consent_templates` | Administrează modelele de consimțământ (text furnizat de clinică) |  | ✔ |  |  |  |  |  |  |  |
| Dosar medical | `medical.documents.retract` | Retrage documente încărcate greșit (cu motiv, fără ștergere) |  |  |  | ✔ |  |  |  |  |  |
| Notificări | `notifications.view` | Vede jurnalul livrărilor de notificări |  | ✔ | ✔ |  |  |  |  |  |  |
| Notificări | `notifications.retry` | Reîncearcă manual o notificare eșuată |  | ✔ | ✔ |  |  |  |  |  |  |
| Notificări | `notifications.send` | Trimite manual mesaje administrative (follow-up) din șabloane |  | ✔ | ✔ |  |  |  |  |  |  |
| Notificări | `notifications.templates` | Editează șabloanele de mesaje |  | ✔ |  |  |  |  |  |  |  |
| Notificări | `notifications.settings` | Configurează canalele (e-mail, SMS, WhatsApp) și regulile de trimitere | ✔ | ✔ |  |  |  |  |  |  |  |
| Notificări | `feedback.view` | Vede răspunsurile la solicitările de feedback |  | ✔ |  |  |  |  |  |  |  |
| Pacienți | `patients.view` | Consultă fișele administrative ale pacienților (identificare, contact, programări) |  | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |  |
| Pacienți | `patients.create` | Creează fișe de pacient |  | ✔ | ✔ | ✔ |  |  |  |  |  |
| Pacienți | `patients.edit` | Modifică datele administrative și reprezentanții |  | ✔ | ✔ | ✔ |  | ✔ |  |  |  |
| Pacienți | `patients.reveal_identifiers` | Afișează CNP-ul complet (acțiune auditată) |  | ✔ | ✔ | ✔ |  |  | ✔ |  |  |
| Pacienți | `patients.merge` | Unește fișe duplicate (cu reconfirmare și motiv) |  | ✔ |  |  |  |  |  |  |  |
| Pacienți | `patients.care_relations` | Desemnează relații explicite de îngrijire |  | ✔ |  |  |  |  |  |  |  |
| Pacienți | `patients.break_glass` | Acces de urgență la datele medicale fără relație de îngrijire (cu motiv, auditat) |  |  |  | ✔ |  |  |  |  |  |
| Recuperare medicală | `rehab.view` | Vede planurile de recuperare (proceduri, ședințe, programări); conținutul clinic cere și relație de îngrijire |  | ✔ | ✔ | ✔ | ✔ | ✔ |  |  |  |
| Recuperare medicală | `rehab.plan` | Întocmește planuri de recuperare (draft) pentru pacienții în îngrijire |  |  |  | ✔ |  |  |  |  |  |
| Recuperare medicală | `rehab.approve` | Aprobă, prelungește, suspendă și încheie planuri (medic) |  |  |  | ✔ |  |  |  |  |  |
| Recuperare medicală | `rehab.schedule` | Programează seriile de ședințe ale planurilor aprobate |  | ✔ | ✔ | ✔ |  |  |  |  |  |
| Recuperare medicală | `rehab.sessions` | Marchează ședințele efectuate (cu observații și evoluție) |  |  |  | ✔ | ✔ | ✔ |  |  |  |
| Recuperare medicală | `rehab.packages` | Configurează tipurile de pachete de ședințe |  | ✔ |  |  |  |  |  |  |  |
| Recuperare medicală | `rehab.packages.assign` | Atribuie pachete de ședințe pacienților |  | ✔ | ✔ |  |  |  |  |  |  |
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
| Actualizări | `updates.manage` | Încarcă și instalează pachete de actualizare semnate; decide recuperarea la eșec | ✔ |  |  |  |  |  |  |  |  |
