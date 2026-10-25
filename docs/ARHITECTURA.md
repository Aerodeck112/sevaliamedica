# Arhitectura platformei SEVALIA

Ultima actualizare: 2026-10-25.

## 1. Decizii principale

| Decizie | Alegere | Motiv |
|--------|---------|-------|
| Limbaj / framework | PHP ≥ 8.3, Laravel 13 | Framework matur, suport de securitate până în 2028; rulează pe cPanel fără procese permanente. |
| Bază de date | MariaDB ≥ 10.6 sau MySQL ≥ 8.0, `utf8mb4_unicode_ci` | Disponibil pe cPanel; tranzacții și blocări pe rânduri pentru prevenirea conflictelor. Dezvoltare/test: MariaDB 10.11. |
| Randare | Blade pe server; JavaScript nativ doar unde aduce valoare (calendar drag-and-drop, editor de blocuri, previzualizare) | Performanță, SEO, mentenanță; fără runtime Node pe server. |
| Asseturi | Vite în dezvoltare/CI; fișierele compilate (`public/build`) intră în pachetul de producție | cPanel nu rulează Node persistent. |
| CSS site public | CSS-ul publicat al siteului actual, redus la regulile folosite efectiv de paginile importate | Fidelitate vizuală maximă; dimensiune mică. |
| CSS panou | Sistem propriu, în culorile clinicii (#00C4C4, #007878, #006F75) | Design nou, aerisit, permis de cerințe. |
| Sesiuni, cache, cozi | Driver `database` | Fără Redis/worker permanent. Cozile rulează din cron în loturi limitate. |
| Roluri/permisiuni | `spatie/laravel-permission` + politici proprii (relație de îngrijire) | Bibliotecă matură, verificată; controlul pe obiect îl fac politicile. |
| MFA | TOTP (`pragmarx/google2fa`), cod QR local (`bacon/bacon-qr-code`), coduri de recuperare | Fără servicii externe; obligatoriu pentru rolurile privilegiate. |
| Sanitizare HTML | `ezyang/htmlpurifier` cu listă albă de etichete, clase și proprietăți CSS | Editorul nu permite PHP/JS arbitrar. |
| Semnare pachete | Ed25519 (`ext-sodium`, inclus în PHP) | Cheia privată stă doar pe mașina de build; serverul are numai cheia publică. |
| Criptare backup | `sodium_crypto_secretstream_xchacha20poly1305`, cheie separată de `APP_KEY` | Backup criptat în flux, verificabil, fără dependențe. |

Pachetele Composer au fost alese din ecosistemul consacrat (Laravel, Spatie, PragmaRX, Bacon, HTMLPurifier);
`composer audit` nu raportează vulnerabilități la data instalării. Nu am instalat pachetul `laravel/boost`
sugerat de scheletul Laravel, deoarece nu este necesar aplicației.

## 2. Structura monolitului modular

```
app/
  Core/                 Infrastructură comună: audit, setări, securitate, module, utilitare
  Modules/
    Identity/           Utilizatori, autentificare, MFA, sesiuni, roluri
    Clinic/             Locații, specialități, personal, săli, aparate, catalog servicii
    Site/               Randarea siteului public, formulare publice, sitemap, redirecturi
    Cms/                Pagini, blocuri, versiuni, media, meniuri, SEO, articole, FAQ
    Patients/           Fișa pacientului, reprezentanți, duplicate, unire fișe
    Scheduling/         Calendar, programări, resurse, disponibilitate, serii, listă de așteptare
    Medical/            Fișe medicale configurabile, documente, consimțăminte, versiuni
    Rehab/              Planuri de recuperare, serii, ședințe, reevaluări
    Cas/                Contracte, nomenclatoare, servicii raportabile, loturi XML, răspunsuri, reconciliere
    Notifications/      Șabloane, canale (e-mail/SMS/WhatsApp), jurnal livrări, preferințe
    Billing/            Tarife, pachete, plăți, solduri (fără pretenții fiscale)
    Reports/            Dashboard pe roluri, rapoarte, exporturi auditate
    Updates/            Actualizări ZIP semnate, backup, recuperare
```

Fiecare modul își declară într-un `ModuleServiceProvider`: rutele (`routes.php`), vizualizările (namespace
Blade), permisiunile și intrările de meniu din panou. Încărcarea se face automat (vezi `App\Core\Modules`).
Modulele comunică prin modele și servicii publice, nu prin acces direct la tabelele altor module, cu excepția
cheilor externe.

Contracte între module:

- `Scheduling` produce programări cu status; trecerea la „finalizată” **nu** creează automat servicii
  efectuate. Serviciul efectuat (`service_records`) e înregistrat explicit de personal.
- `service_records` este pivotul comun pentru `Rehab`, `Cas`, `Billing` și `Reports`: un serviciu efectuat
  are pacient, personal, locație, dată, cod de serviciu, sursa de plată (privat/CAS/pachet) și stare.
- `Cas` citește `service_records` cu sursa CAS și nu le modifică; corecțiile se fac la sursă și generează un
  lot nou de corecție.

## 3. Rute și zone

| Zonă | Prefix | Protecție |
|------|--------|-----------|
| Site public | `/` (URL-urile existente păstrate) | Public, cache HTTP pentru pagini fără date personale |
| Panou (CMS + CRM) | `/panou` | Autentificare, MFA pentru roluri privilegiate, permisiuni pe server, `noindex` ca măsură suplimentară |
| Link-uri pacient | `/p/{token}` | Token aleator, scop limitat, expirare, fără date medicale afișate |
| Recuperare | `recovery.php` separat | Cheie de recuperare stocată în afara `public_html`, funcționează fără framework |

## 4. Structura pe hosting cPanel

```
/home/<cont>/
  sevalia/
    releases/
      1.0.0/               cod aplicație + vendor + public/build (din pachet)
      1.1.0/
    shared/
      .env                 configurare și secrete (chmod 600)
      storage/             loguri, sesiuni, cache, documente pacienți, uploaduri
      backups/             backupuri criptate
      updates/             pachete primite, staging, jurnal de pași
      keys/                cheia de criptare backup, cheia de recuperare (hash)
    current.json           versiunea activă (comutare atomică prin rename)
  public_html/
    index.php              front controller mic: citește current.json și pornește versiunea activă
    .htaccess
    build/                 asseturi compilate (nume cu hash, versiunile coexistă)
    media/                 imagini publice optimizate (generate de CMS)
    recovery.php           recuperare de urgență, independentă de framework
```

Documentele pacienților, backupurile, logurile și `.env` nu se află niciodată sub `public_html`.

## 5. Taskuri de fundal

Un singur cron la minut: `php artisan schedule:run`. Programatorul rulează:

- `queue:work --stop-when-empty --max-time=50 --max-jobs=50` cu `withoutOverlapping()` (blocare în baza de date);
- trimiterea notificărilor programate (idempotentă: cheie unică pe `(eveniment, destinatar, canal)`);
- curățarea tokenurilor expirate, a sesiunilor și a fișierelor temporare;
- verificarea backupurilor programate.

Joburile sunt reîncercate cu backoff; o notificare are o cheie de idempotență, deci o reîncercare nu produce
duplicat.

## 6. Securitate

Detalii în [`SECURITATE.md`](SECURITATE.md) și [`ROLURI-PERMISIUNI.md`](ROLURI-PERMISIUNI.md).

## 7. CAS / PIAS — ce poate și ce nu poate face serverul

Sursa: „Specificaţii de interfaţare cu SIUI+PE+CEAS pentru aplicaţiile de raportare ale furnizorilor”,
versiunea 3.7.34 din 20.10.2026, `https://portal.cnas.ro/cnas/pias/specificatii` (descărcat 2026-10-25),
capitolele 2.3.1–2.3.2: autentificarea la SIUI folosește **certificat digital calificat** prin HTTPS cu
certificat client, iar **fișierele XML raportate se semnează electronic** cu certificatul utilizatorului,
adesea stocat pe eToken USB.

Consecințe:

- Serverul cPanel **nu** are acces la tokenul USB al utilizatorului și nu îl va pretinde.
- Modulul CAS acoperă A (pregătire și validare internă) și B (XML conform XSD-ului oficial, validat local).
- C (semnare, transmitere, preluarea răspunsului) se face, până la validare, prin **flux manual documentat**:
  lotul XML exportat e preluat în aplicația de raportare folosită de clinică, semnat și transmis acolo; fișierul
  de răspuns (feedback) e importat înapoi în CRM și reconciliat.
- Un conector local (aplicație pe calculatorul responsabilului CAS, cu acces la token) este proiectat separat
  în [`integrari/CAS-CONECTOR-LOCAL.md`](integrari/CAS-CONECTOR-LOCAL.md) și rămâne „nevalidat în producție”
  până la testarea cu credențiale reale.
