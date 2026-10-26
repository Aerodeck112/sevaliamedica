# Instalare pe cPanel fără Terminal (din browser)

Calea recomandată pentru hostingul clinicii: nu necesită SSH, Composer sau comenzi. Pachetul livrat se numește
`sevalia-<versiune>-instalare-cpanel.zip` (însoțit de `.sha256`).

## Ce conține arhiva

```
sevalia/releases/<versiune>/   aplicația completă (cod, dependențe, fișiere compilate, arhiva conținutului siteului vechi)
public_html/install.php        instalatorul din browser
CITESTE-MA-INSTALARE.txt       pașii pe scurt
```

Arhiva se dezarhivează în **directorul principal al contului** (`/home/<cont>`), astfel încât `sevalia/` ajunge
lângă `public_html/` (în afara zonei publice), iar `install.php` în `public_html/`.

## Pași

| # | Unde | Ce faceți |
|---|------|-----------|
| 1 | cPanel → **MySQL Database Wizard** | Bază nouă (ex. `cont_sevalia`), utilizator cu parolă puternică, **ALL PRIVILEGES**. |
| 2 | cPanel → **MultiPHP Manager** | Domeniul pe PHP ≥ 8.3. |
| 3 | cPanel → **SSL/TLS Status** | AutoSSL activ pentru domeniu și `www`. |
| 4 | cPanel → **File Manager** → `/home/<cont>` | *Upload* arhivă → clic dreapta → *Extract* în `/home/<cont>`. |
| 5 | Browser: `https://<domeniu>/install.php` | Introduceți codul din `sevalia/shared/install/cod-instalare.txt` (File Manager → clic dreapta → *View*). |
| 6 | Instalator — „Verificarea serverului” | Versiune PHP, extensii, spațiu, permisiuni. Erorile indică exact ce setare din cPanel trebuie schimbată. |
| 7 | Instalator — „Configurare” | Adresa siteului, datele bazei (testate imediat; baza trebuie să fie goală), administratorul tehnic, e-mail SMTP (opțional). |
| 8 | Instalator — „Instalare” | Chei de securitate, tabele, date de bază ale clinicii, contul administratorului. |
| 9 | Instalator — „Importul conținutului” | Paginile, textele, serviciile, articolele și fotografiile siteului vechi, din arhiva inclusă. Rulează pe bucăți de ~20 s, cu reîncărcare automată; se poate relua oricând fără pierderi. |
| 10 | Instalator — „Finalizare” | Notați **cheia de recuperare**; adăugați linia de **cron** în cPanel → *Cron Jobs* (*Once Per Minute*); descărcați offline `sevalia/shared/.env` și `sevalia/shared/keys/`. La confirmare instalatorul se dezactivează și se șterge. |
| 11 | `https://<domeniu>/panou/autentificare` | Prima autentificare: configurarea autentificării în doi pași pe telefon. |

## Securitatea instalatorului

- Fără codul din fișierul aflat în afara `public_html`, instalatorul nu face nimic (10 încercări, apoi blocare).
- Instalarea se face numai într-o bază de date **goală** — instalatorul refuză o bază cu tabele (protecția datelor).
- Parolele nu se afișează și nu se scriu în jurnale; `.env` primește permisiuni 0600.
- După finalizare: fișierul `sevalia/shared/.installed` blochează definitiv instalatorul, care se șterge singur.

## Actualizări ulterioare (fără Terminal)

Panou → Administrare → **Actualizări** → încărcați `sevalia-<versiune>-update.zip` (semnat digital) → verificare →
instalare. Detalii: [`ACTUALIZARI.md`](ACTUALIZARI.md). Dacă încărcarea e refuzată din cauza mărimii:
cPanel → **MultiPHP INI Editor** → `upload_max_filesize` și `post_max_size` = 256M.

## Dacă ceva nu merge

| Simptom | Ce faceți |
|---------|-----------|
| „Pachetul nu a fost găsit” | Arhiva a fost dezarhivată în alt director; `sevalia/` trebuie să fie lângă `public_html/`. |
| Eroare la conectarea bazei | Verificați că utilizatorul e **adăugat la bază** cu ALL PRIVILEGES (pasul 1). Numele au prefixul contului (`cont_`). |
| Importul se oprește | Apăsați „Reia importul”: continuă de unde a rămas. |
| Pagina albă / eroare 500 după instalare | `https://<domeniu>/recovery.php` cu cheia de recuperare; jurnalul: `sevalia/shared/storage/logs/`. |
