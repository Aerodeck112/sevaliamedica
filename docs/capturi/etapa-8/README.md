# Capturi — etapa 8 (actualizări, backup, recuperare)

Generate la 2026-10-25 cu `tools/screenshots/capture.mjs` pe o **instalare simulată** creată din pachetul real
`sevalia-0.1.0-install.zip` (aceeași structură ca pe cPanel: `~/sevalia/releases`, `shared/`, `current.json`,
`public_html` cu front controllerul), servită de serverul PHP încorporat. Pachetele de actualizare 0.2.0 (reușit) și
0.2.1 (cu o migrare care eșuează intenționat) au fost construite cu `sevalia:release:build` și semnate cu o cheie
de test generată în afara repository-ului. Contul afișat este un cont demonstrativ fictiv.

| Fișier | Ce arată |
|--------|----------|
| `01-actualizari-pachet-incarcat.png` | Tabul Actualizări: versiunea curentă, cheile acceptate, încărcarea, pachetul 0.2.0 în așteptare |
| `02-pachet-verificat-preflight.png` | Pachet verificat: pași, verificări preliminare reale, changelog, detalii pachet |
| `03-instalare-in-curs-mentenanta.png` | Instalare în curs (copiere 3541/8556 fișiere), mentenanță activă, jurnal |
| `04-site-in-mentenanta.png` | Ce vede un vizitator în timpul actualizării (503) |
| `05-migrare-esuata-optiuni-recuperare.png` | Pachetul 0.2.1: migrare eșuată, aplicația rămâne în mentenanță, opțiunile reale de recuperare |
| `06-recovery-autentificare.png` | `recovery.php` — autentificare cu cheia de recuperare |
| `07-recovery-stare-jurnal*.png` | `recovery.php` după autentificare: versiuni, mentenanță, jurnalul ultimei actualizări (desktop și mobil) |
| `08-dupa-restaurarea-bazei.png` | După restaurarea explicită a bazei: stare „revenit”, aplicația pe 0.2.0, ieșită din mentenanță |
| `09-istoric-actualizari.png` | Istoricul: 0.2.0 instalat, 0.2.1 revenit |
| `10-verificarea-mediului.png` | Raportul mediului real (același cu `sevalia:install --check`) |
| `11-backupuri*.png` | Backupurile făcute automat înainte de actualizări, verificate (desktop și mobil) |
