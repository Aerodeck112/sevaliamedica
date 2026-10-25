# Backup, restaurare și recuperare

Ultima actualizare: 2026-10-25. Cod: `app/Modules/Backup`, teste în `tests/Feature/Backup` și `tests/Feature/Updates`.

## 1. Ce se salvează

| Tip | Conținut | Program (cron) | Păstrare implicită |
|-----|----------|----------------|-------------------|
| Bază de date | toate tabelele (structură + date); `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs` doar structură | zilnic la 02:30 (`SEVALIA_BACKUP_AT`) | 14 (`SEVALIA_BACKUP_RETAIN_DB`) |
| Documente | fișierele private din `storage/app/private` (documente pacienți etc.) | săptămânal, duminică 03:30 | 8 (`SEVALIA_BACKUP_RETAIN_DOCS`) |
| Înainte de fiecare actualizare | baza de date, verificată obligatoriu | la instalare | nu se șterge cât timp actualizarea nu e încheiată |

Codul aplicației nu se salvează separat: fiecare versiune rămâne în `releases/` (ultimele 3), iar pachetele se pot
reconstrui din repository. **Fișierul `shared/.env` și directorul `shared/keys/` trebuie copiate offline la instalare
și la fiecare modificare** — fără ele, backupurile nu pot fi citite.

Programările rulează din cronul unic (`php <deploy>/artisan schedule:run`), cu `withoutOverlapping`. În modul
mentenanță programatorul nu rulează.

## 2. Format și criptare

- Dump SQL generat în PHP (fără `mysqldump`): `SHOW CREATE TABLE` + `INSERT` multi-rând, câte o instrucțiune pe
  rând; valorile binare ca hex; sesiune în UTC.
- Criptare în flux XChaCha20-Poly1305 (`sodium_crypto_secretstream`), mesaje comprimate deflate; ultimul mesaj are
  eticheta FINAL, deci un fișier trunchiat este detectat. Fiecare bloc este autentificat: orice modificare a
  fișierului face verificarea să eșueze.
- Cheia: `shared/keys/backup.key` (32 de octeți, base64, 0600), **separată de `APP_KEY`**; antetul fiecărui backup
  conține un identificator scurt al cheii (nu cheia).
- Lângă fiecare `*.sbk` există `*.sbk.json` necriptat cu SHA-256, dimensiune, versiunea aplicației și statistici
  (fără date personale).
- Backupul rulează pe bucăți cu stare salvată (`shared/backups/.state/`); un backup întrerupt se reia.
- Înainte de backup se verifică spațiul liber (dimensiunea bazei/documentelor + 20%).

## 3. Verificare

„Verificat” înseamnă: SHA-256 al fișierului identic cu cel înregistrat, decriptarea și autentificarea tuturor
blocurilor, decomprimarea, numărul de instrucțiuni identic cu cel declarat la final (sau, pentru documente,
SHA-256 al fiecărui fișier). Existența fișierului nu este considerată verificare.

```
php artisan sevalia:backup:verify            # ultimul backup
php artisan sevalia:backup:verify <uuid>
```

## 4. Restaurare

Restaurarea este întotdeauna explicită. Implicit **nu** se face peste baza de producție.

```
# test de restaurare într-o bază goală de verificare (creată din cPanel → MySQL Databases)
php artisan sevalia:backup:restore <uuid> --database=<cont>_sevalia_verificare

# peste baza curentă (distructiv): puneți întâi aplicația în mentenanță; se cere tastarea „RESTAUREAZĂ”
php artisan down
php artisan sevalia:backup:restore <uuid> --over-current
php artisan up

# documente, într-un director separat (nu peste fișierele existente)
php artisan sevalia:backup:restore <uuid> --target-dir=/home/<cont>/restaurare-documente
```

Restaurarea peste baza curentă este „exactă”: tabelele care nu existau în backup sunt eliminate. După o actualizare
eșuată, restaurarea se face din tabul Actualizări (opțiunea apare numai dacă mentenanța a fost activă continuu de
dinaintea backupului).

## 5. Test periodic de restaurare (obligatoriu)

Lunar (și după orice schimbare de hosting):

1. Creați din cPanel o bază goală `<cont>_sevalia_verificare` cu utilizatorul aplicației.
2. `php artisan sevalia:backup:restore <uuid-ultimul-backup> --database=<cont>_sevalia_verificare --force`
3. Verificați numărul de pacienți/programări între producție și baza de verificare (raport din panou sau SQL simplu).
4. Ștergeți baza de verificare. Notați data și rezultatul în registrul intern al clinicii.

## 6. Copii în afara serverului

Backupurile din `shared/backups/` sunt pe același server: nu protejează împotriva pierderii contului de hosting.
Recomandare: copie zilnică a directorului `shared/backups/` (fișierele sunt deja criptate) pe un spațiu separat
(ex. backupul de cont al hostingului, JetBackup, sau descărcare din panou → Backupuri, cu audit). Cheia
`backup.key` **nu** se stochează împreună cu backupurile.

## 7. Recuperare de urgență

Dacă aplicația nu mai pornește: `https://<domeniu>/recovery.php`, autentificare cu cheia de recuperare (afișată o
singură dată de `sevalia:install`; stocată doar ca hash în `shared/keys/recovery.hash`). Permite comutarea la o
versiune din `releases/` și pornirea/oprirea mentenanței; nu restaurează baza de date. Cheie nouă:
`php <deploy>/artisan sevalia:install --rotate-recovery-key` (cea veche nu mai funcționează). Dezactivare: fișierul
`shared/keys/recovery.disabled`. Fără acces web: același efect se obține din File Manager editând `current.json`
(`{"release":"0.1.0"}`) și ștergând `shared/storage/framework/down`.
