# Jurnalul modificărilor

Formatul: o secțiune `## [versiune] — dată` pentru fiecare release. Textul secțiunii este inclus în pachetul de
actualizare și afișat administratorului înainte de instalare.

## [0.9.6] — 2026-10-28

Se poate instala direct peste 0.9.1 – 0.9.5.

- Corectură importantă: siteul fără stiluri (doar text și linkuri) când era deschis când cu `sevalia.ro`, când cu
  `www.sevalia.ro`. Pagina păstrată în cache trimitea stilurile de pe celălalt domeniu, pe care browserul le
  blochează. Acum `sevalia.ro` trimite permanent (301) la `www.sevalia.ro` (adresa din configurare), iar cache-ul
  paginilor ține cont de domeniu.
- Meniul de pe desktop: subcategoriile rămân deschise cât timp mouse-ul coboară spre ele, chiar încet sau pe
  diagonală peste alt buton; meniul se schimbă doar când mouse-ul se oprește pe alt buton și se închide la scurt
  timp după ce mouse-ul pleacă.
- Subsol: copyright 2026 și a treia hartă, „Medicină de familie | Band, jud. Mureș” (cerute de clinică; versiune
  nouă a subsolului, vizibilă în istoricul paginii).
- Selectorul de limbă a fost eliminat complet (antet, meniul mobil, setarea din panou).

## [0.9.5] — 2026-10-28

Se poate instala direct peste 0.9.1 – 0.9.4.

- Meniul de pe desktop: listele extinse („Specialitate medicală”, „Servicii”, „Terapii premium”, „Ghid pacient”) se
  deschid pe toată lățimea, sub bara de meniu, și nu mai ies din ecran în dreapta (verificat la 992–1440 px).
- Meniul de pe telefon: după deschidere, elementele se pot atinge din nou (fundalul gri stătea peste meniu și primea
  toate atingerile); bara „Sună acum / Programare online” și butonul asistentului se ascund cât timp meniul e deschis.
- După o actualizare, paginile siteului păstrate în cache se generează din nou, cu stilurile versiunii noi (înainte
  rămâneau până la 12 ore cele vechi, așa că unele corecturi nu se vedeau imediat). Așa apar și corecturile din
  0.9.3 pentru telefon: de exemplu, fotografiile medicilor de pe „Echipa noastră” nu mai sunt întinse.

## [0.9.4] — 2026-10-28

Se poate instala direct peste 0.9.1, 0.9.2 sau 0.9.3 (conține toate corecturile din 0.9.2 și 0.9.3).

- Corectură importantă pentru actualizări pe cPanel: la pasul „Verificări de funcționare”, actualizatorul pornea un
  proces PHP separat; pe hostingurile LiteSpeed/PHP-FPM acela nu este PHP CLI și se putea bloca, iar siteul rămânea
  în mentenanță (eroare 503). Acum noul cod se verifică direct, în prima cerere servită de el (bază de date,
  migrări, stocare); procesul separat se folosește doar din linia de comandă, cu limită de timp.
- O eroare fatală în timpul unui pas (ex. timpul maxim de execuție al hostingului) nu mai lasă actualizarea „în curs”:
  pasul se marchează eșuat și se aplică regulile obișnuite (dacă baza de date nu a fost modificată, codul revine
  automat la versiunea anterioară și siteul iese din mentenanță).
- Actualizare blocată (fila închisă, proces oprit de hosting): după 5 minute fără progres pagina nu mai reîncearcă
  singură și oferă „Continuă” sau „Oprește actualizarea blocată”.
- Pentru instalările rămase blocate în 0.9.2: `repara-actualizare.php` (se încarcă în public_html, se deschide o dată
  cu cheia de recuperare, finalizează sau anulează actualizarea blocată și se șterge singur).

## [0.9.3] — 2026-10-28

Se poate instala direct peste 0.9.1 sau 0.9.2 (conține și corecturile din 0.9.2).

- Panou pe telefon: nicio pagină nu mai iese din lățimea ecranului (ecranul principal, lista de așteptare, programul
  de lucru, programările, planurile de recuperare, fișa pacientului, tabletele, profilul etc.): coloanele nu se mai
  lărgesc după conținut, tabelele se derulează în interiorul panoului, butoanele trec pe rândul următor. Verificat
  automat pe toate paginile panoului la 390, 768 și 1440 px.
- Site pe telefon și tabletă: titlurile cu cuvinte lungi („antigravitațională”, „salinoterapie”) și imaginile mari din
  grilele preluate de pe siteul vechi nu mai împing pagina în lateral (verificat pe toate cele 63 de pagini la 360,
  390, 768 și 1440 px). Pe desktop aspectul rămâne neschimbat.
- Cuvinte cheie: câmpurile din tabel au stilul panoului.
- Ghid nou: programul medicilor și programările, pas cu pas (`docs/manuale/GHID-PROGRAM-SI-PROGRAMARI.md`).

## [0.9.2] — 2026-10-28

- Corectură importantă pentru hostingurile cPanel: dacă `public_html/.htaccess` exista deja (cPanel îl creează pentru
  versiunea PHP), instalarea 0.9.1 îl păstra fără regulile SEVALIA și toate paginile, în afară de prima, dădeau 404.
  Acum fișierul se îmbină la instalare, la fiecare actualizare și printr-o verificare automată la 5 minute: regulile
  SEVALIA se scriu, blocurile cPanel (versiunea PHP, setări php.ini) și regulile proprii se păstrează, iar fișierul
  vechi se salvează alături.
- Pentru instalările 0.9.1 existente: `repara-htaccess.php` (se încarcă în public_html, se deschide o dată, se șterge
  singur).
- Fereastra de anunț a siteului apare abia după alegerea privind cookie-urile (nu se mai suprapun), iar în banner
  niciun buton nu mai pare preselectat.
- Instalatorul se oprește, fără să creeze fișiere, dacă arhiva a fost dezarhivată în public_html sau dacă lipsește
  directorul „sevalia”, și explică remedierea; instrucțiuni de instalare mai clare.

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
- Cheie nouă de semnare a actualizărilor: `config/update-keys/e9582d2db2648293.pub` (prima instalare se face cu 0.9.1).
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
- Cheia de semnare de producție pentru actualizări (înlocuită în 0.9.1, înainte de prima instalare).

## [0.1.0] — 2026-10-25

Prima versiune de lucru (nevalidată în producție).

- Fundația aplicației: autentificare cu MFA obligatoriu, roluri și permisiuni, jurnal de audit, antete de securitate.
- Date de referință ale clinicii: locații, specialități, personal, săli, aparatură, catalog de servicii.
- Actualizări prin pachete ZIP semnate Ed25519, cu verificări preliminare, backup verificat înainte de instalare,
  pași reluabili, revenire automată a codului și opțiuni explicite de recuperare a bazei de date.
- Backup criptat al bazei de date și al documentelor, verificare reală și restaurare într-o bază aleasă.
- Front controller pentru cPanel fără legături simbolice și `recovery.php` independent de aplicație.
