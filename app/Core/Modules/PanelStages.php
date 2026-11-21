<?php

namespace App\Core\Modules;

use App\Core\Settings\Settings;

/**
 * Funcțiile panoului, pe etape: clinica pornește cu esențialul (echipă, servicii, programări, solicitări) și
 * activează celelalte etape când este pregătită (Administrare → Funcții panou).
 *
 * O etapă oprită ascunde doar elementele ei din meniu și cardurile ei de pe ecranul principal. Datele rămân, iar
 * paginile se pot deschide în continuare din linkurile altor pagini (ex. fișa pacientului dintr-o programare);
 * drepturile de acces se verifică la fel ca înainte.
 */
final class PanelStages
{
    public const SETTING = 'panel.stages';

    /** Mereu vizibile (dacă utilizatorul are dreptul): acasă și administrarea tehnică. */
    public const ALWAYS = ['panou.dashboard', 'panou.users.index', 'panou.updates.index', 'panou.backups.index', 'panou.features.edit', 'panou.mail-check'];

    /** @return array<int, array{label: string, detail: string, routes: list<string>, modules: list<string>}> */
    public static function stages(): array
    {
        return [
            1 => ['label' => 'Esențial', 'detail' => 'Echipa, serviciile, programările și solicitările de pe site.',
                'routes' => ['panou.calendar', 'panou.requests.index', 'panou.clinic.staff.index', 'panou.schedules.index', 'panou.clinic.services.index',
                    'panou.clinic.specialties.index', 'panou.clinic.locations.index', 'panou.scheduling-settings.edit'],
                'modules' => ['scheduling', 'clinic']],
            2 => ['label' => 'Pacienți și comunicare', 'detail' => 'Lista pacienților, lista de așteptare, serii de ședințe, notificările către pacienți, mesajele din site, săli și aparatură.',
                'routes' => ['panou.patients.index', 'panou.waitlist.index', 'panou.series.index', 'panou.notifications.deliveries', 'panou.notifications.templates',
                    'panou.notifications.channels', 'panou.cms.submissions.index', 'panou.clinic.rooms.index', 'panou.clinic.equipment.index'],
                'modules' => ['patients', 'notifications']],
            3 => ['label' => 'Site și marketing', 'detail' => 'Editarea paginilor și a blogului, media, meniuri, setările siteului, auditul SEO și Google.',
                'routes' => ['panou.cms.pages.index', 'panou.cms.articles.index', 'panou.cms.media.index', 'panou.cms.menus.index', 'panou.cms.redirects.index',
                    'panou.cms.settings.edit', 'panou.cms.tracking.edit', 'panou.marketing.seo', 'panou.marketing.keywords', 'panou.marketing.integrations'],
                'modules' => ['cms', 'marketing', 'site']],
            4 => ['label' => 'Medical și recuperare', 'detail' => 'Fișe medicale, șabloane, consimțăminte, planuri de recuperare, ședințe, pachete și tabletele de la recepție.',
                'routes' => ['panou.medical.drafts', 'panou.medical.templates.index', 'panou.medical.consent-templates.index', 'panou.rehab.plans.index',
                    'panou.rehab.sessions.index', 'panou.rehab.packages.index', 'panou.kiosk.devices'],
                'modules' => ['medical', 'rehab', 'kiosk']],
            5 => ['label' => 'Rapoarte și control', 'detail' => 'Recenzii, feedbackul pacienților, jurnalul de audit.',
                'routes' => ['panou.kiosk.reviews', 'panou.notifications.feedback', 'panou.audit.index'],
                'modules' => ['reports', 'billing', 'cas']],
        ];
    }

    public function __construct(private readonly Settings $settings) {}

    /** @return list<int> */
    public function enabled(): array
    {
        $stages = array_map('intval', (array) $this->settings->get(self::SETTING, [1]));

        return array_values(array_unique([1, ...array_intersect($stages, array_keys(self::stages()))]));
    }

    public function routeVisible(string $route): bool
    {
        if (in_array($route, self::ALWAYS, true)) {
            return true;
        }
        foreach (self::stages() as $number => $stage) {
            if (in_array($route, $stage['routes'], true)) {
                return in_array($number, $this->enabled(), true);
            }
        }

        return true;
    }

    public function moduleVisible(string $key): bool
    {
        foreach (self::stages() as $number => $stage) {
            if (in_array($key, $stage['modules'], true)) {
                return in_array($number, $this->enabled(), true);
            }
        }

        return true;
    }

    /** @param list<int> $stages */
    public function set(array $stages): void
    {
        $this->settings->set(self::SETTING, array_values(array_unique([1, ...array_map('intval', $stages)])));
    }
}
