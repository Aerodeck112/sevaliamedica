# Securitate

Document comun; fiecare modul își descrie aici măsurile. Nu promitem „conformitate garantată”: măsurile de mai jos
sunt implementate și testate local, iar validarea finală o fac responsabilii clinicii înainte de producție.

## Actualizări, backup și recuperare

### Integritatea codului instalat

- Pachetele de actualizare sunt semnate **Ed25519**; serverul conține doar cheile publice (`config/update-keys/`),
  cheia privată rămâne pe mașina de build. Checksumurile singure nu sunt acceptate: semnătura se verifică peste
  octeții exacți ai manifestului, iar manifestul conține SHA-256 pentru fiecare fișier.
- Cheile de încredere se schimbă numai printr-un release (cod revizuit), nu din panou.
- Arhivele sunt inspectate înainte de extragere: zip-slip (căi absolute, `..`, caractere de control), legături
  simbolice, fișiere speciale, intrări criptate, „zip bomb” (raport de compresie, dimensiune totală, număr de
  intrări), fișiere nedeclarate. Extragerea se face doar în staging, cu dimensiunea reală limitată și SHA-256
  verificat; copierea în `releases/` re-verifică fiecare fișier.
- Pachetele nu conțin `.env`, date, seedere demo, teste sau depozite `.git`.

### Acces

- Tabul **Actualizări** și **Backupuri**: permisiunile `updates.manage` și `backups.manage`, acordate implicit doar
  rolului *administrator tehnic* (care nu are acces la datele medicale).
- Încărcarea și pornirea unei instalări, acțiunile de recuperare și descărcarea backupurilor cer **reautentificare
  cu parolă + cod MFA** (valabilă 10 minute); acțiunile distructive cer în plus tastarea confirmării.
- Toate operațiile se înregistrează în jurnalul de audit (`updates.*`, `backup.*`), fără conținutul datelor.
- În modul mentenanță rămân accesibile doar autentificarea și tabul Actualizări.

### Backupuri

- Criptare XChaCha20-Poly1305 (libsodium), cu cheie dedicată (`shared/keys/backup.key`, 0600), separată de `APP_KEY`
  și de backupuri. Autentificarea fiecărui bloc detectează modificarea; marcajul final detectează trunchierea.
- Fișierele de backup și cheile sunt în afara `public_html`; `.htaccess` blochează oricum extensiile `.sbk`, `.key`,
  `.hash`, `.env`, `.sql`, `.zip` și fișierele ascunse.
- Descărcarea unui backup (criptat) este auditată.

### Recuperare de urgență (`recovery.php`)

- Independent de framework; funcționează și când aplicația nu pornește.
- Autentificare cu cheie de recuperare de 30 de caractere (stocată doar ca `password_hash`), limitare a încercărilor
  pe IP (5 / 15 min) și global (20 / 15 min) cu fișier blocat exclusiv, sesiune de 15 minute cu cookie `HttpOnly`,
  `SameSite=Strict`, `Secure` pe HTTPS, token CSRF, CSP `default-src 'none'`, doar HTTPS.
- Funcții limitate: comutare la o versiune existentă din `releases/`, pornire/oprire mentenanță, citirea jurnalului.
  Nu restaurează baza de date și nu execută cod încărcat.
- Se poate dezactiva cu `shared/keys/recovery.disabled`. Acțiunile se jurnalizează în `shared/updates/recovery.log`
  (cu hash de IP).

### Riscuri reziduale cunoscute

- Un atacator cu acces de scriere la contul de hosting poate modifica direct codul sau `current.json`; semnătura
  protejează canalul de actualizare, nu contul. Măsuri: parolă puternică și 2FA pentru contul cPanel, SFTP/SSH cu chei.
- Migrările MySQL nu sunt tranzacționale; recuperarea după o migrare eșuată se bazează pe backupul verificat făcut
  cu aplicația în mentenanță.
