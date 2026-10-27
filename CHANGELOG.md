# Jurnalul modificărilor

Formatul: o secțiune `## [versiune] — dată` pentru fiecare release. Textul secțiunii este inclus în pachetul de
actualizare și afișat administratorului înainte de instalare.

## [Nelansat]

- Tablete la recepție: consimțăminte semnate pe ecran cu cod de 6 cifre și recenzii 1–5 stele după servicii
  (serviciu, specialist, comentariu), statistici pe specialiști și servicii, cod QR opțional către recenzia Google.

## [0.9.0] — 2026-10-26

Prima versiune instalabilă pe hosting (pre-lansare; de verificat pe staging înainte de producție).

- Instalare din browser pe cPanel fără Terminal (`install.php`), cu cod de instalare, verificarea serverului,
  configurarea bazei de date și a administratorului, import reluabil al conținutului.
- Siteul public reconstruit fidel din arhiva completă a siteului vechi (pagini, texte, servicii, articole, fotografii),
  CMS cu editor pe blocuri controlate, versiuni, bibliotecă media, meniuri, redirecturi, SEO, consimțământ cookie propriu.
- Pacienți, programări (calendar, resurse fără conflicte, serii, listă de așteptare, solicitări de pe site),
  servicii efectuate.
- Dosar medical (fișe configurabile, finalizare și rectificare trasabilă, documente criptate, consimțăminte)
  și recuperare medicală (planuri, serii de ședințe, efectuare explicită, pachete).
- Notificări (e-mail, SMS, WhatsApp) cu șabloane, jurnal de livrări și fără duplicate.
- Cheia de semnare de producție pentru actualizări (`config/update-keys/fb3391f2ed370c28.pub`).

## [0.1.0] — 2026-10-25

Prima versiune de lucru (nevalidată în producție).

- Fundația aplicației: autentificare cu MFA obligatoriu, roluri și permisiuni, jurnal de audit, antete de securitate.
- Date de referință ale clinicii: locații, specialități, personal, săli, aparatură, catalog de servicii.
- Actualizări prin pachete ZIP semnate Ed25519, cu verificări preliminare, backup verificat înainte de instalare,
  pași reluabili, revenire automată a codului și opțiuni explicite de recuperare a bazei de date.
- Backup criptat al bazei de date și al documentelor, verificare reală și restaurare într-o bază aleasă.
- Front controller pentru cPanel fără legături simbolice și `recovery.php` independent de aplicație.
