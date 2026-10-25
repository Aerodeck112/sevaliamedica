# Starea funcționalităților

Tabel sincer, actualizat la fiecare etapă. Coloane: **implementat** · **testat local** (teste automate și/sau
simulare locală) · **testat staging** (pe hostingul clinicii) · **verificat cu serviciul extern** · **blocat de
acces/date**. „—” = nu se aplică.

## Actualizări, backup, recuperare, instalare (etapa 8)

| Funcționalitate | Implementat | Testat local | Testat staging | Verificat cu serviciul extern | Blocat de acces/date |
|---|---|---|---|---|---|
| Pachet ZIP cu manifest, semnătură Ed25519, SHA-256 pe fișier | da | da (teste + pachete reale 0.1.0/0.2.0/0.2.1) | nu | — | cheia de producție negenerată |
| Respingere pachet: manifest lipsă/corupt, semnătură greșită/cheie neautorizată, checksum, zip-slip, symlink, zip bomb, fișier nedeclarat | da | da (15 teste) | nu | — | — |
| Unelte de release: `sevalia:release:keygen`, `sevalia:release:build` (install/update) | da | da (pachete reale construite) | — | — | — |
| Tab „Actualizări” (încărcare, verificări preliminare, progres, changelog, istoric, erori, recuperare) | da | da (teste HTTP + capturi) | nu | — | — |
| Reautentificare parolă + MFA pentru încărcare, pornire, recuperare | da | da | nu | — | — |
| Verificări preliminare (spațiu, PHP, extensii, drepturi, versiune, BD, concurență) | da | da (inclusiv spațiu insuficient simulat, versiune incompatibilă) | nu | — | valorile reale ale hostingului |
| Mașina de stări cu pași reluabili, jurnal JSON, blocare concurentă (fișier + index unic) | da | da (întrerupere și reluare, două actualizări, doi pași simultani) | nu | — | — |
| Mentenanță pe durata instalării (scrieri și cron oprite) | da | da | nu | — | — |
| Backup verificat obligatoriu înainte de instalare | da | da | nu | — | — |
| Revenire automată a codului (eșec înainte de migrări, health check eșuat) | da | da | nu | — | — |
| Migrare eșuată: BD neatinsă automat, opțiuni explicite, restaurare exactă din backup | da | da (test + simulare cu pachet real 0.2.1) | nu | — | — |
| Health check al noului cod în proces separat (`sevalia:health`) | da | da (simulare: proc_open real) | nu | — | proc_open pe hosting de verificat |
| Actualizare peste o versiune cu date existente (date CMS/tarife păstrate) | da | da (test + simulare 0.1.0 → 0.2.0) | nu | — | — |
| Front controller `public_html/index.php` fără symlinkuri, `.htaccess` | da | da (simulare cu serverul PHP încorporat) | nu | — | comportamentul Apache/LiteSpeed real |
| Lansator stabil `<deploy>/artisan` pentru cron | da | da (simulare) | nu | — | — |
| `recovery.php` independent (cheie, limitare, comutare versiune, mentenanță, jurnal) | da | da (teste unitare + HTTP fără aplicație) | nu | — | — |
| `sevalia:install` (chei, migrări, seedere de instalare, admin, activare, publicare) | da | da (simulare instalare din pachet real) | nu | — | acces la hosting |
| `sevalia:install --check` — raport al mediului real | da | da | nu | — | rulare pe hosting |
| Backup BD în PHP pur, criptat, pe bucăți, reluabil, retenție, cron | da | da (restaurare cu date identice, întrerupere, trunchiere, modificare) | nu | — | — |
| Backup documente private, criptat, cu SHA-256 pe fișier | da | da | nu | — | — |
| Restaurare explicită într-o bază aleasă (implicit nu peste producție) | da | da | nu | — | baza de verificare se creează din cPanel |
| UI Backupuri (listă, creare, verificare, descărcare auditată) | da | da | nu | — | — |
| Copie externă a backupurilor (în afara serverului) | doar documentat | — | — | — | alegerea spațiului de stocare de către clinică |

## Cerințe de hosting

Confirmate de clinică la 2026-10-25 (PHP 8.3+, SSH/Terminal, Composer, cron); **de verificat la instalare** cu
`php artisan sevalia:install --check`. Valorile reale se notează aici după prima rulare.
