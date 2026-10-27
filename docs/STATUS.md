# Starea sinceră a funcționalităților

Tabel actualizat la fiecare etapă. Coloane: **implementat** · **testat local** (teste automate și/sau simulare
locală) · **testat staging** (pe hostingul clinicii) · **verificat cu serviciul extern** · **blocat de acces/date**.
„—” = nu se aplică. „Testat staging” rămâne „nu” până la existența unui staging pe hostingul clinicii.

## Pacienți și programări (etapa 4)

| Funcționalitate | Implementat | Testat local | Testat staging | Verificat cu serviciul extern | Blocat de acces/date |
|---|---|---|---|---|---|
| Fișa pacientului (identificare, contact, reprezentanți, preferințe comunicare, observații) | ✅ | ✅ | ❌ | — | — |
| CNP criptat + index orb, afișare mascată, dezvăluire auditată | ✅ | ✅ | ❌ | — | — |
| Duplicate: blocare la CNP/document, avertizare nume+dată/telefon, fără fuziuni automate | ✅ | ✅ | ❌ | — | — |
| Unirea fișelor (permisiune, reconfirmare, motiv, audit, mutare prin participanți) | ✅ | ✅ | ❌ | — | — |
| Relația de îngrijire + acces de urgență | ✅ | ✅ | ❌ | — | — |
| Program de lucru, pauze, concedii, ore suplimentare, închideri | ✅ | ✅ | ❌ | — | Programul real al personalului: îl introduce clinica |
| Rezervare simultană persoană + sală (capacitate) + aparat, alocare automată / manuală | ✅ | ✅ | ❌ | — | Sălile și aparatele reale: le introduce clinica (în demo sunt fictive) |
| Prevenirea conflictelor la cereri concurente (2 procese reale + control negativ) | ✅ | ✅ | ❌ | — | — |
| Statusuri și tranziții validate, istoric cu motive | ✅ | ✅ | ❌ | — | — |
| Serii de ședințe (plan cu conflicte și alternative, creare parțială cu acord, reprogramare) | ✅ | ✅ | ❌ | — | — |
| Listă de așteptare + potrivire la anulare + ofertă (eveniment pentru notificări) | ✅ | ✅ | ❌ | — | Trimiterea mesajului: modulul Notifications |
| Calendar zi/săptămână, coloane personal/săli, filtre, drag-and-drop, alternativă accesibilă | ✅ | ⚠️ randare testată automat; tragerea verificată manual în capturi, fără test automat de browser | ❌ | — | — |
| Solicitări de pe site (contract AppointmentIntake), confirmare automată după reguli | ✅ | ✅ | ❌ | — | Formularul public îl leagă modulul Site |
| Linkuri pentru pacient (anulare, cerere reprogramare) | ✅ | ✅ | ❌ | — | Trimiterea linkului: modulul Notifications |
| Servicii efectuate (service_records) cu corectare fără ștergere | ✅ | ✅ | ❌ | — | — |
| Reguli de programare și acces editabile din panou, auditate | ✅ | ✅ | ❌ | — | Valorile reale: le stabilește clinica |
| Dashboard recepție / medic-terapeut | ✅ | ✅ | ❌ | — | — |

## Limitări cunoscute (etapa 4)

- Tragerea în calendar nu are încă test automat de browser (Playwright); validarea conflictelor e pe server și
  testată prin endpointul JSON.
- Seriile acceptă o ședință pe zi; mai multe ședințe în aceeași zi se programează individual.
- Capacitatea sălii se calculează ca suprapunere maximă în interval (corect pentru programări cu durate diferite).
- Fereastra relației de îngrijire bazată pe programări include programările viitoare confirmate.

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

## Tablete recepție: consimțăminte și recenzii (modulul Kiosk)

| Funcționalitate | Implementat | Testat local | Testat staging | Verificat cu serviciul extern | Blocat de acces/date |
|---|---|---|---|---|---|
| Asocierea tabletei cu un cod de 8 caractere (o singură dată), token păstrat doar ca hash, revocare | ✅ | ✅ | ❌ | — | — |
| Cod de 6 cifre per pacient (HMAC, expirare configurabilă, blocare după 5 coduri greșite) | ✅ | ✅ | ❌ | — | — |
| Semnarea consimțământului pe ecran: text versionat, bifă de acord, reprezentant legal opțional, refuz | ✅ | ✅ (teste + capturi în browser) | ❌ | — | Textele reale ale consimțămintelor: le introduce clinica |
| Semnătura salvată criptat ca document al pacientului + amprentele SHA-256 ale textului și semnăturii | ✅ | ✅ | ❌ | — | — |
| Recenzie după un serviciu efectuat: serviciu, specialist, 1–5 stele, comentariu opțional criptat | ✅ | ✅ | ❌ | — | — |
| Recenzie trimisă direct pe tabletă (se deschide singură, fără cod) | ✅ | ✅ | ❌ | — | — |
| Statistici pe specialiști și servicii, publicare pe site doar cu acordul pacientului | ✅ | ✅ | ❌ | — | Afișarea recenziilor publicate pe site: neimplementată încă |
| Cod QR către recenzia Google după orice recenzie (fără filtrare după notă) | ✅ | ⚠️ randare testată, linkul real lipsește | ❌ | ❌ | Linkul Google Business Profile al clinicii |
| Ecranul tabletei revine singur la început după inactivitate | ✅ | ⚠️ verificat manual | ❌ | — | — |

Semnătura desenată pe tabletă este o **semnătură electronică simplă**, nu o semnătură electronică calificată.
Dacă un anumit document cere semnătură calificată, clinica trebuie să folosească un serviciu calificat separat.
Modul „kiosk” al tabletei (fixarea aplicației/browserului pe ecran) se configurează din sistemul tabletei.

## Cerințe de hosting

Confirmate de clinică la 2026-10-25 (PHP 8.3+, SSH/Terminal, Composer, cron); **de verificat la instalare** cu
`php artisan sevalia:install --check`. Valorile reale se notează aici după prima rulare.
