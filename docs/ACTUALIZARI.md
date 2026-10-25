# Actualizări prin pachete ZIP semnate

Ultima actualizare: 2026-10-25. Cod: `app/Modules/Updates`, `deploy/`, teste în `tests/Feature/Updates`.

## 1. Pe scurt

1. Responsabilul de release construiește pe mașina de build un pachet `sevalia-<versiune>-update.zip`, semnat cu
   cheia privată Ed25519 a clinicii (cheia nu ajunge niciodată pe hosting).
2. Administratorul tehnic încarcă pachetul în **Panou → Administrare → Actualizări** (cere reconfirmarea parolei și a
   codului MFA). Aplicația verifică semnătura, structura, sumele de control și compatibilitatea **înainte de orice
   modificare**.
3. După citirea modificărilor și a verificărilor preliminare, administratorul pornește instalarea. Pașii rulează pe
   rând, fiecare scurt; pagina continuă automat, iar o întrerupere se reia de unde a rămas.
4. La eșec, codul revine automat dacă baza nu a fost atinsă; dacă baza poate fi modificată, aplicația rămâne în
   mentenanță și administratorul alege explicit o opțiune de recuperare.

Aceleași operații există și în linia de comandă: `sevalia:update:stage <zip>`, `sevalia:update:run <id> --start`,
`--retry`, `--restore-db`.

## 2. Formatul pachetului

```
sevalia-0.2.0-update.zip
├── manifest.json      produs, versiune, tip (install|update), intervalul de versiuni de la care se poate actualiza,
│                      PHP minim, extensii, motoare BD acceptate, lista TUTUROR fișierelor cu SHA-256 și dimensiune,
│                      lista migrărilor, changelog, data și commitul de build
├── manifest.sig       {"alg":"ed25519","key_id":"…","signature":"…"} — semnătură detașată peste octeții exacți ai manifestului
└── payload/           app, bootstrap, config, database (fără seedere demo), public (cu build/ compilat), resources,
                       routes, vendor (fără pachete dev), deploy, artisan, composer.json/lock, VERSION, CHANGELOG.md
```

Pachetul de instalare (`install`) conține în plus `database/content/` (instantaneul siteului vechi, necesar la importul
inițial). Pachetele nu conțin `.env`, `storage/`, teste, `node_modules`, `.git`, documentație sau date demo.

## 3. Verificări de securitate ale pachetului

| Verificare | Unde |
|-----------|------|
| Semnătură Ed25519 validă, cu o cheie din `config/update-keys/*.pub` (checksumul singur nu e suficient) | `SignatureVerifier` |
| Manifest strict (format, produs, versiune, tip, cerințe, intrări de fișiere) | `Manifest::parse` |
| Nume de fișiere: fără căi absolute, `..`, `.`, segmente goale, backslash, caractere de control, litere de unitate | `PackageInspector::assertSafeName` |
| Numai directoarele/fișierele din `updates.payload_roots` | idem |
| Respinse: legături simbolice, fișiere speciale, intrări criptate, duplicate | `PackageInspector` |
| Limite: dimensiune pachet, număr intrări, dimensiune totală, dimensiune pe fișier, raport de compresie (zip bomb) | `config/updates.php` → `limits` |
| Corespondență exactă arhivă ↔ manifest (niciun fișier nedeclarat sau lipsă, dimensiuni identice) | `PackageInspector` |
| Extragere doar în `shared/updates/<id>/staging`, cu dimensiunea reală limitată și SHA-256 verificat la fiecare fișier | `PackageExtractor` |
| Re-verificarea SHA-256 la copierea în `releases/<versiune>` | `UpdateRunner::copyVerified` |
| Pachetul nu se schimbă între încărcare și instalare (SHA-256 al arhivei comparat la fiecare pas) | `UpdateRunner::package` |

## 4. Pașii instalării

| Pas | Ce face | La eșec |
|-----|---------|---------|
| Extragere | dezarhivează pe bucăți și verifică fiecare fișier | nimic modificat |
| Verificări preliminare | structură releases/, versiune compatibilă, PHP + extensii, versiune BD, spațiu liber (estimat), drepturi de scriere, cheie de backup, nicio altă actualizare | nimic modificat |
| Mod mentenanță | site și panou indisponibile (cu excepția tabului Actualizări și a autentificării), taskurile cron oprite (Laravel nu rulează programatorul și cozile în mentenanță) | ieșire automată din mentenanță |
| Backup BD | dump criptat, pe bucăți | idem |
| Verificarea backupului | decriptare completă, autentificarea fiecărui bloc, numărarea instrucțiunilor | idem |
| Copiere | `releases/<versiune>.partial` → `releases/<versiune>` | versiunea parțială se șterge, mentenanța se oprește |
| Legare shared | `releases/<versiune>/.shared-path` → versiunea citește `.env` și `storage` din `shared/` | idem |
| Migrări | una câte una, din `releases/<versiune>/database/migrations` | **aplicația rămâne în mentenanță, BD nu se restaurează automat** |
| Publicare | `public/` (fără `index.php`) și `deploy/public_html/{index.php,recovery.php}` în `public_html`, `deploy/artisan` în `<deploy>/artisan` | ca la migrări (BD poate fi deja modificată) |
| Activare | `current.json` rescris atomic (fișier temporar + `rename`) | — |
| Verificări de funcționare | structura versiunii, `VERSION`, apoi `php <versiune>/artisan sevalia:health --json` într-un proces separat (noul cod chiar pornește, se conectează la BD, nu are migrări rămase) | **codul revine automat** la versiunea anterioară |
| Ieșire din mentenanță, curățare | șterge staging-ul și pachetul, păstrează ultimele 3 versiuni | se poate relua |

Ordinea diferă intenționat de „backup → mentenanță”: mentenanța pornește **înaintea** backupului, ca să nu existe
scrieri între backup și migrări. Astfel restaurarea backupului după o migrare eșuată nu pierde date.

### Reluare după întrerupere

Starea fiecărui pas (cu cursor) se scrie în tabela `update_runs` și în `shared/updates/<id>/journal.json` înainte și
după execuție. Un timeout PHP, o conexiune întreruptă sau un restart al serverului lasă pasul „în curs”; următoarea
cerere (sau `sevalia:update:run <id>`) îl reia. Pașii sunt idempotenți (fișierele se scriu prin temporar + rename;
migrările deja rulate sunt înregistrate de Laravel). Doi pași nu pot rula simultan: blocare exclusivă pe
`shared/updates/.lock`; două actualizări nu pot fi active simultan: index unic `update_runs.active_slot`.

## 5. Recuperare: cod și bază de date, separat

| Situație | Ce se întâmplă automat | Ce decide administratorul |
|----------|-----------------------|---------------------------|
| Eșec înainte de migrări | versiunea nouă se șterge, aplicația rămâne pe versiunea veche, iese din mentenanță | nimic |
| Migrare eșuată | nimic; aplicația rămâne în mentenanță pe codul vechi | **Restaurează baza din backupul de dinainte** (posibil numai dacă mentenanța a fost activă continuu de dinaintea backupului — verificat din jurnal; restaurarea este „exactă”: elimină și tabelele create de migrarea eșuată) · **Reia pasul** după remediere · **Ieși din mentenanță** (doar după verificare manuală) · **Închide incidentul** |
| Verificare de funcționare eșuată după activare | codul revine la versiunea anterioară; dacă au rulat migrări, aplicația rămâne în mentenanță | ca mai sus |
| Aplicația nu mai pornește deloc | — | `https://<domeniu>/recovery.php` cu cheia de recuperare: comutare la o versiune existentă, oprire/pornire mentenanță, jurnalul ultimei actualizări |

Nu există „rollback automat al oricărei migrări”: migrările MySQL/MariaDB cu DDL nu sunt tranzacționale. Revenirea
bazei se face numai din backupul verificat, explicit, cu tastarea confirmării.

Acțiunile periculoase din panou cer reautentificare (parolă + MFA) și tastarea `CONFIRM`; din CLI, `RESTAUREAZĂ`.

## 6. Construirea pachetelor (mașina de build / CI)

```
# o singură dată: perechea de chei, ÎN AFARA repository-ului
php artisan sevalia:release:keygen --out=~/.sevalia-release
# cheia publică (<key_id>.pub) se adaugă în config/update-keys/ printr-un commit revizuit

# la fiecare release
echo "0.2.0" > VERSION                     # și secțiunea „## [0.2.0]” în CHANGELOG.md
npm ci && npm run build                    # public/build
php artisan test
php artisan sevalia:release:build --type=update --from=0.1.0-0.1.99 --key=~/.sevalia-release/sevalia-release-<id>.key
php artisan sevalia:release:build --type=install --key=…   # pentru instalări noi
```

`--vendor=local` (implicit) copiază `vendor/` existent și rulează `composer install --no-dev` care doar elimină
pachetele de dezvoltare (fără descărcări). `--vendor=composer` face o instalare curată (necesită acces la Packagist/
GitHub). Din `vendor/` se elimină depozitele `.git`, testele/documentația pachetelor și orice legătură simbolică.
Lângă fiecare ZIP se scrie `<zip>.sha256`.

## 7. Cheile de semnare: păstrare și rotație

- Cheia privată: pe mașina de build, cu permisiuni 0600, plus o copie offline (ex. stick criptat în seif). Nu în
  repository, nu pe hosting, nu în e-mail/chat.
- **Rotație planificată**: generați o cheie nouă; publicați un release semnat cu cheia **veche** care adaugă noua
  cheie publică în `config/update-keys/`; release-urile următoare se semnează cu cheia nouă; după ce toate instalările
  au trecut de acel release, un release ulterior elimină cheia veche.
- **Cheie compromisă**: nu mai semnați nimic cu ea; release de urgență (semnat cu o cheie încă de încredere) care o
  elimină din `config/update-keys/`. Dacă nu mai există o cheie de încredere necompromisă, actualizarea se face manual
  (instalare nouă în `releases/` din pachet verificat prin canal separat), apoi `recovery.php`/`current.json`.

## 8. Ce nu este încă validat

- Rulare pe hostingul cPanel real (doar simulare locală: Apache înlocuit de serverul PHP încorporat, același front
  controller, aceleași căi). Vezi `docs/STATUS.md`.
- Cheia de producție nu există încă: `config/update-keys/` este gol, deci niciun pachet nu poate fi instalat până la
  generarea și adăugarea ei de către responsabilul de release.
