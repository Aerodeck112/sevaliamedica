# Jurnalul modificărilor

Formatul: o secțiune `## [versiune] — dată` pentru fiecare release. Textul secțiunii este inclus în pachetul de
actualizare și afișat administratorului înainte de instalare.

## [0.9.1] — 2026-10-27

- Tablete la recepție: consimțăminte semnate pe ecran cu cod de 6 cifre și recenzii 1–5 stele după servicii
  (serviciu, specialist, comentariu), statistici pe specialiști și servicii, cod QR opțional către recenzia Google.
- Marketing: audit SEO automat (în fiecare noapte și la cerere) cu scor, istoric și cuvinte cheie urmărite;
  verificare Google Search Console și Bing, profil Google Business în datele structurate, IndexNow, `/llms.txt`
  și controlul accesului asistenților AI din robots.txt.
- Programare online pe site (`/programare-online`) cu intervalele libere reale din calendar: serviciu, locație,
  specialist (opțional), zi și oră; intervalul se reverifică la trimitere, iar confirmarea automată urmează
  regulile din panou. Bară „Sună acum / Programare online” pe telefoane.
- Asistentul Sevalia pe site: răspunde la program, adrese, programare, specialități și la întrebările despre
  terapii din conținutul public al siteului, cu legătură la pagina-sursă; urgențele sunt trimise la 112; fără
  AI extern și fără păstrarea întrebărilor. Se poate opri din Setări site.
- Schițe anatomice animate în linie pe copertele paginilor de servicii (zona tratată evidențiată) și harta
  „Unde simțiți durere sau disconfort?” pe prima pagină, cu legături spre terapii; se pot opri din Setări site.
- Performanță pe mobil: imaginea principală corectă preîncărcată pe telefoane, fonturi de bază cerute devreme
  (fără reașezarea textului), banner de cookie afișat imediat, favicon de 6,5 KB în loc de 43 KB, pictograme
  pentru telefon; corecturi de accesibilitate fără schimbări vizibile.
- După actualizare, permisiunile modulelor noi (Marketing, Tablete, Recenzii) se creează automat din cron și se
  acordă rolurilor implicite, fără a reface acordările retrase manual.
- Paginile fără descriere SEO primesc automat începutul textului lor ca descriere; imaginea principală devine
  imaginea de distribuire.

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
