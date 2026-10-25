# Date demonstrative

Încărcare (numai în local/staging): `php artisan db:seed --class='Database\Seeders\Demo\DemoSeeder'`.
Seederul refuză să ruleze când `APP_ENV=production` sau `SEVALIA_STAGE=production`.

| Cont | Rol |
|------|-----|
| `administrator-tehnic@demo.sevalia.invalid` | Administrator tehnic |
| `manager@demo.sevalia.invalid` | Manager clinică |
| `receptie@demo.sevalia.invalid` | Recepție |
| `medic@demo.sevalia.invalid` | Medic (legat demonstrativ de fișa „dr. Kinga Fülöp” de pe site) |
| `terapeut@demo.sevalia.invalid` | Terapeut (persoană fictivă „Andrei Demo”) |
| `asistent@demo.sevalia.invalid` | Asistent medical |
| `responsabil-cas@demo.sevalia.invalid` | Responsabil CAS |
| `financiar@demo.sevalia.invalid` | Financiar |
| `editor-site@demo.sevalia.invalid` | Editor site |

Parola tuturor conturilor demo: `Demo-Sevalia-2026`. Secret MFA comun (TOTP):
`DEMOSEVALIAMFAXXSECRETKEYABCDEFG` — se adaugă manual în aplicația de autentificare.

Sălile, aparatele, serviciile „(demo)”, tarifele și pacienții sunt **fictive**. Locațiile, programul și echipa
medicală provin de pe siteul public și nu sunt date demonstrative.
