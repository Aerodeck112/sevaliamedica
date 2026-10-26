# SEVALIA — instrucțiuni de proiect

Platforma clinicii SEVALIA: site public (păstrat fidel după www.sevalia.ro) + CMS + CRM clinică + dosar medical +
recuperare + pregătirea raportărilor CAS + actualizări ZIP semnate. Rulează pe hosting cPanel.

Citește întâi: `docs/PLAN.md`, `docs/ARHITECTURA.md`, `docs/STATUS.md`, `docs/INTREBARI.md`.

## Reguli de neîncălcat

- Limba interfeței, mesajelor, documentației și comentariilor: **română cu diacritice** (ș, ț cu virgulă).
- **Nu inventa** tarife, coduri CAS, reguli contractuale, medici, certificări, endpointuri sau scheme. Ce nu e
  confirmat rămâne configurabil și marcat „neconfirmat”/„blocat de date” în `docs/STATUS.md`.
- **Date fictive** în teste, demo și capturi (`@demo.sevalia.invalid`, nume evident fictive). Seederul demo e
  `database/seeders/Demo/` și refuză să ruleze în producție.
- Siteul vechi (Odoo) va fi **șters**: sursa conținutului este exclusiv arhiva din `database/content/` (nu siteul live).
- Siteul public păstrează textele, imaginile, meniurile și ordinea secțiunilor originale. Greșelile se notează în
  `docs/audit/OBSERVATII-CONTINUT.md`, nu se corectează în tăcere.
- Autorizare **pe server** pentru fiecare acțiune (`permission:` pe rute + politici pe obiect). Datele medicale
  cer și relație de îngrijire. Administratorul tehnic nu primește implicit permisiuni medicale.
- Audit (`App\Core\Audit\Audit::log`) pentru consultarea, modificarea, descărcarea și exportul datelor sensibile.
  Nu pune în context/loguri parole, tokenuri, CNP complet sau conținut medical.
- Nicio dependență de Docker, root, Redis, Node pe server sau worker permanent. Taskurile de fundal rulează din
  cron prin `ModuleServiceProvider::schedule()` cu `withoutOverlapping()` și loturi limitate.
- Nu introduce PHP/JS arbitrar prin editorul CMS; HTML-ul e sanitizat cu HTMLPurifier.
- Nu marca nimic „acceptat de CAS”, „transmis” sau „efectuat” fără o acțiune/dovadă explicită.

## Structură și convenții

- Monolit modular: `app/Modules/<Modul>/` cu `ModuleServiceProvider.php` (descoperit automat), `routes/public.php`,
  `routes/panel.php` (prefix `/panou`, nume `panou.`, middleware `auth` + `panou`), `Views/` (namespace = cheia
  modulului, ex. `view('scheduling::calendar')`), `Models/`, `Services/`, `Http/Controllers/`, `Policies/`, `Console/`.
- Modulul declară `permissions()`, `roleGrants()`, `navigation()`, `dashboardWidgets()`, `schedule()`.
  După adăugarea de permisiuni: `php artisan sevalia:sync-permissions` și `php artisan sevalia:permissions-matrix`.
- Migrările stau în `database/migrations/`, cu prefix de dată pe modul (ordinea contează):
  `2026_01_01` core · `2026_01_02` clinic · `2026_02_*` cms/site · `2026_03_*` patients/scheduling ·
  `2026_04_*` medical · `2026_05_*` rehab · `2026_06_*` cas · `2026_07_*` notifications · `2026_08_*` billing ·
  `2026_09_*` reports · `2026_10_*` updates/backup. Numele de indici ≤ 64 caractere (MariaDB).
- Pagini de panou: `@extends('layouts.panou')`, `@section('title')`, `@section('content')`; componente
  `x-panou.page-head`, `x-panou.panel`, `x-panou.field`, `x-panou.badge`, `x-panou.empty`, `x-panou.icon`.
  Nomenclatoarele simple folosesc `App\Core\Http\Crud\ResourceController`.
- CSS panou: `resources/css/panou.css` (tokeni pe `:root`, fără framework). JS: `resources/js/panou.js`; module
  per pagină în `resources/js/panou/<nume>.js`, încărcate prin `data-module="<nume>"`. CSP cu nonce: scripturile
  inline trebuie să aibă `nonce="{{ app('csp-nonce') }}"`; preferă fișiere JS.
- Teste: PHPUnit pe MariaDB (`sevalia_test`), `tests/Feature/<Modul>/`. `Tests\TestCase` oferă `actingAsRole()` și
  `withRecentReauth()`. Rulează `php artisan test` înainte de fiecare commit.
- Capturi pe etape: `tools/screenshots/capture.mjs` + un plan JSON în `tools/screenshots/`; ies în `docs/capturi/etapa-N/`.

## Mediu local

```
mysqld_safe --user=mysql &           # MariaDB 10.11 (sevalia / sevalia_dev; baze sevalia, sevalia_test)
composer install && npm ci && npm run build
php artisan migrate:fresh --seed
php artisan db:seed --class='Database\Seeders\Demo\DemoSeeder'
php artisan serve                    # /panou/autentificare — conturi demo în docs/DEMO.md
```
