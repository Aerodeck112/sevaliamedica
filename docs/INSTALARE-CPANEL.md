# Instalare pe hosting cPanel

Ultima actualizare: 2026-10-25. Procedura a fost rulată integral pe o **simulare locală** (aceleași directoare, același
front controller, serverul PHP încorporat în locul Apache); nu a fost încă rulată pe hostingul clinicii.

## 1. Cerințe

Cerințele de mai jos sunt **confirmate de clinică la 2026-10-25, de verificat la instalare** cu
`php artisan sevalia:install --check` (raportează valorile reale ale serverului).

| Cerință | Valoare | Stare |
|---------|---------|-------|
| PHP (MultiPHP Manager) | ≥ 8.3, CLI și web aceeași versiune | confirmat de clinică, de verificat |
| Extensii | pdo_mysql, mbstring, openssl, sodium, zip, json, fileinfo, dom, xml, ctype, tokenizer; recomandate intl, gd (cu WebP), curl | de verificat |
| Bază de date | MariaDB ≥ 10.6 sau MySQL ≥ 8.0, utf8mb4 | de verificat |
| SSH / Terminal cPanel | necesar pentru instalare și actualizări din CLI (există și cale fără SSH, §6) | confirmat de clinică |
| Composer | nu este necesar pe server (pachetul conține `vendor/`) | — |
| Cron | o singură intrare, la minut | confirmat de clinică |
| Limite PHP recomandate | memory_limit ≥ 256M, upload_max_filesize și post_max_size ≥ 64M (pachetul de actualizare are ~25 MB), max_execution_time ≥ 30 | de verificat |
| Spațiu liber | ≥ 1 GB (2 versiuni + staging + backupuri) | de verificat |
| Funcții | proc_open recomandat (verificarea noului cod după actualizare); nu sunt necesare exec/shell_exec/symlink | de verificat |
| HTTPS | AutoSSL / Let's Encrypt activ pe domeniu | de verificat |

## 2. Structura directoarelor

```
/home/<cont>/
  sevalia/                      (în afara public_html)
    artisan                     lansator stabil: rulează versiunea activă (pentru cron și Terminal)
    current.json                versiunea activă
    releases/0.1.0/             codul versiunii (din pachet)
    shared/
      .env                      configurare și secrete (chmod 600)
      storage/                  loguri, sesiuni, cache, documente private
      backups/                  backupuri criptate
      updates/                  pachete, staging, jurnale de actualizare
      keys/                     backup.key, recovery.hash (chmod 700)
  public_html/                  document root
    index.php  .htaccess  recovery.php  build/  images/  favicon.ico  robots.txt
```

Nu sunt necesare legături simbolice. `public_html/index.php` caută implicit `../sevalia`; altă cale se setează cu
variabila `SEVALIA_DEPLOY_PATH` (ex. `SetEnv` în `.htaccess`, dacă hostingul permite).

## 3. Pași de instalare (cu SSH)

1. **Bază de date** — cPanel → *MySQL® Databases*: creați baza `<cont>_sevalia` (utf8mb4) și utilizatorul, cu
   ALL PRIVILEGES pe bază. Pentru staging: o bază separată `<cont>_sevalia_staging`.
2. **Încărcare pachet** — `sevalia-<v>-install.zip` și `.sha256` în `/home/<cont>/`; verificați suma:
   `sha256sum -c sevalia-<v>-install.zip.sha256`.
3. **Dezarhivare**:
   ```
   mkdir -p ~/sevalia/releases ~/sevalia/shared && cd ~ && unzip -q sevalia-<v>-install.zip -d sevalia-pkg
   mv sevalia-pkg/payload ~/sevalia/releases/<v> && rm -rf sevalia-pkg
   echo "$HOME/sevalia/shared" > ~/sevalia/releases/<v>/.shared-path
   cp ~/sevalia/releases/<v>/.env.example ~/sevalia/shared/.env && chmod 600 ~/sevalia/shared/.env
   ```
4. **Configurare** `~/sevalia/shared/.env`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://www.sevalia.ro`,
   `SEVALIA_STAGE=production`, `SEVALIA_DEPLOY_PATH=/home/<cont>/sevalia`, `SEVALIA_PUBLIC_PATH=/home/<cont>/public_html`,
   datele bazei, `SESSION_SECURE_COOKIE=true`, setările SMTP (§5). `APP_KEY` și `SEVALIA_BLIND_INDEX_KEY` rămân goale:
   le generează instalarea.
5. **Verificarea mediului**: `cd ~/sevalia/releases/<v> && php artisan sevalia:install --check`.
6. **Instalare**: `php artisan sevalia:install --admin-email=<adresa administratorului tehnic>`
   - generează cheile (aplicație, index orb, backup, recuperare), rulează migrările și datele publice preluate de pe
     site (fără date demo), creează administratorul (parola se cere interactiv), scrie `current.json`, publică
     fișierele în `public_html` și lansatorul `~/sevalia/artisan`;
   - **afișează o singură dată cheia de recuperare** — notați-o offline;
   - copiați offline `shared/.env` și `shared/keys/` (fără ele backupurile nu pot fi citite).
7. **Document root**: pentru domeniul principal, `public_html` (conținutul vechi Odoo/alt site se mută înainte).
   Pentru un subdomeniu de staging: cPanel → *Domains* → document root `/home/<cont>/staging_html` + o instalare
   separată `~/sevalia-staging` (bază, `.env`, chei proprii).
8. **Cron** — cPanel → *Cron Jobs*, „Once Per Minute”:
   ```
   * * * * * /usr/local/bin/php /home/<cont>/sevalia/artisan schedule:run >> /dev/null 2>&1
   ```
   (calea PHP exactă: `which php` în Terminal; trebuie să fie aceeași versiune ca pentru web).
9. **Verificare**: `php ~/sevalia/artisan sevalia:health`; autentificare la `https://<domeniu>/panou/autentificare`
   (prima autentificare configurează MFA); Panou → Actualizări → *Verificarea mediului*.

## 4. SSL și antete

- cPanel → *SSL/TLS Status* → AutoSSL activ pentru domeniu și `www`. `.htaccess` redirecționează la HTTPS și setează
  HSTS doar pe HTTPS.
- După activarea HTTPS: `SESSION_SECURE_COOKIE=true` în `.env`.

## 5. E-mail

- Creați o căsuță (ex. `notificari@sevalia.ro`) în cPanel → *Email Accounts*; în `.env`: `MAIL_MAILER=smtp`,
  `MAIL_HOST=<serverul cPanel>`, `MAIL_PORT=465`, `MAIL_SCHEME=smtps`, utilizator/parolă, `MAIL_FROM_ADDRESS`.
- cPanel → *Email Deliverability*: SPF și DKIM „Valid” pentru domeniu; adăugați DMARC (`p=quarantine` după o
  perioadă de monitorizare).
- Test: resetarea parolei unui cont de test.

## 6. Instalare fără SSH (plasă de siguranță)

Posibil, cu limite: File Manager poate dezarhiva pachetul și crea fișierele din §3; comenzile artisan se rulează
printr-un cron temporar (cPanel → *Cron Jobs*, o singură rulare), cu ieșirea trimisă pe e-mail:
`php /home/<cont>/sevalia/releases/<v>/artisan sevalia:install --force --no-interaction`. Contul de administrator
nu poate fi creat interactiv pe această cale (parola nu se scrie în cron); se creează ulterior cu SSH sau de
furnizor. Actualizările ulterioare se fac din panou (nu necesită SSH).

## 7. Permisiuni

- Fișiere 0644, directoare 0755; `shared/.env` 0600; `shared/keys/` 0700 cu fișiere 0600 (create automat).
- Utilizatorul PHP (de obicei același cu contul cPanel, prin PHP-FPM/suPHP) trebuie să poată scrie în `~/sevalia`
  (pentru `current.json` și `releases/`) și în `public_html`.

## 8. Ce trebuie verificat la hosting înainte de producție

- `sevalia:install --check` fără EROARE; valorile reale notate în `docs/STATUS.md`.
- O actualizare de test pe staging (pachet semnat cu cheia de producție) și un test de restaurare (`docs/BACKUP-RESTAURARE.md` §5).
- Funcționarea `recovery.php` prin HTTPS.
- Că cererile web pot dura cel puțin 30 s (pașii de actualizare sunt sub 15 s implicit: `SEVALIA_UPDATE_STEP_BUDGET`).
- Backupul contului oferit de hosting și politica lui de retenție.
