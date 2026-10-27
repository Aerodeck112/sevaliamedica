<?php

namespace App\Modules\Assistant\Services;

use App\Core\Contracts\PublicBooking;
use App\Modules\Clinic\Models\Location;
use App\Modules\Clinic\Models\Specialty;
use App\Modules\Clinic\Models\StaffMember;
use App\Modules\Cms\Models\Page;

/**
 * Asistentul Sevalia de pe site. Răspunde NUMAI din informațiile publice ale siteului (pagini, întrebări frecvente,
 * locații și program) și trimite spre pagina-sursă. Nu inventează tarife, condiții de decontare sau disponibilitate,
 * nu dă sfaturi medicale și nu păstrează întrebările vizitatorilor.
 */
class Assistant
{
    private const DAYS = [1 => 'Luni', 'Marți', 'Miercuri', 'Joi', 'Vineri', 'Sâmbătă', 'Duminică'];

    public function __construct(private readonly KnowledgeBase $kb, private readonly PublicBooking $booking) {}

    /**
     * @return array{answer: string, links: list<array{label: string, url: string}>, source: ?string, kind: string}
     */
    public function answer(string $question): array
    {
        $q = Text::normalize($question);
        $links = [];
        $parts = [];

        // Urgențele au prioritate: asistentul nu poate evalua o situație medicală.
        if (preg_match('/\b(sinucid\w*|suicid\w*|sa ma omor|vreau sa mor|nu mai vreau sa traiesc|imi fac rau)\b/u', $q)) {
            return $this->reply("Dacă sunteți în pericol sau vă gândiți să vă faceți rău, sunați acum la **112**. Nu sunteți singur — cereți ajutor imediat de la cineva apropiat.", [], 'urgenta');
        }
        if (self::isEmergency($q)) {
            return $this->reply('Dacă este o urgență medicală, sunați imediat la **112**. Asistentul nu poate evalua situații medicale.', [], 'urgenta');
        }

        // Intenții practice, recunoscute după cuvinte (fără diacritice). „programul” = orar; „programare” = rezervare.
        $intents = [
            'program' => '/\b(program|programul|programului|orar|orarul|deschis|deschideti|inchis|inchideti|ore de lucru|lucrati|lucreaza|sambata|duminica|weekend)\b/u',
            'adresa' => '/\b(adresa|adresele|unde sunteti|unde va aflati|locatia|locatie|locatii|locatiile|cum ajung|parcare|harta|maurer|unirii)\b/u',
            'contact' => '/\b(telefon|telefonul|numar de telefon|sun|sunati|email|e mail|mail|contact)\b/u',
            'programare' => '/\b(programare|programari|programez|programa|programati|programeaza|rezerv\w*|consultatie|consult|interval liber|loc liber)\b/u',
            'tarife' => '/\b(pret|pretul|preturi|tarif|tarife|tarifele|cost|costa|costul|lei|plata|achit\w*)\b/u',
            'specialist' => '/\b(medic|medici|doctor|doctori|dr|specialist|specialisti|echipa|neurolog\w*|pediatr\w*|reumatolog\w*|psihiatr\w*|cardiolog\w*|internist\w*|medicina interna|reabilitare medicala|recuperare medicala|kinetoterapeut\w*|fizioterapeut\w*)\b/u',
            'decontare' => '/\b(cas|casa de asigurari|decont\w*|asigur\w*|gratuit|bilet de trimitere|trimitere|compensat)\b/u',
        ];
        $hits = array_keys(array_filter($intents, fn ($re) => preg_match($re, $q) === 1));

        $locations = Location::active()->get();
        foreach ($hits as $intent) {
            switch ($intent) {
                case 'program':
                    foreach ($locations as $loc) {
                        $parts[] = '**'.$loc->name.'**: '.$this->hours($loc).'.';
                    }
                    break;
                case 'adresa':
                    foreach ($locations as $loc) {
                        $parts[] = '**'.$loc->name.'**: '.implode(', ', array_filter([$loc->address, $loc->city])).'.';
                    }
                    $this->addPage($links, '/cum-ajung-la-clinica-sevalia', 'Cum ajung la clinică');
                    break;
                case 'contact':
                    foreach ($locations as $loc) {
                        $parts[] = '**'.$loc->name.'**: '.implode(' · ', array_filter([$loc->phone ? 'telefon '.$loc->phone : null, $loc->email])).'.';
                    }
                    $this->addPage($links, '/contactus', 'Formular de contact');
                    break;
                case 'programare':
                    if ($this->booking->available()) {
                        $parts[] = 'Vă puteți programa online, alegând ziua și ora libere din calendarul clinicii.';
                        $links[] = ['label' => 'Programare online', 'url' => '/programare-online'];
                    } else {
                        $parts[] = 'Programările se fac telefonic sau prin formularul de contact.';
                    }
                    $this->addPage($links, '/contactus', 'Formular de contact');
                    break;
                case 'tarife':
                    $parts[] = 'Tarifele publicate de clinică se găsesc pe pagina de tarife; pentru un serviciu anume, recepția vă confirmă prețul actual.';
                    $this->addPage($links, '/tarife-asistenta-medicala-ambulatorie-clinica', 'Tarife');
                    break;
                case 'specialist':
                    array_push($parts, ...$this->specialists($q, $links));
                    break;
                case 'decontare':
                    $parts[] = 'Condițiile de decontare (CAS sau asigurări private) depind de serviciu și de documentele pe care le aveți; recepția vă spune exact ce se aplică în cazul dumneavoastră.';
                    break;
            }
        }
        if (array_intersect($hits, ['tarife', 'decontare', 'programare']) && ($phone = $locations->first()?->phone)) {
            $parts[] = 'Telefon recepție: '.$phone.'.';
        }

        // Căutare în conținutul siteului pentru restul întrebării (fără cuvintele intențiilor deja tratate).
        $rest = $q;
        foreach ($hits as $intent) {
            $rest = preg_replace($intents[$intent], ' ', $rest);
        }
        $restTokens = Text::tokens($rest);
        $found = $restTokens ? $this->search($rest) : [];
        $source = null;
        if ($found && $hits) {
            // La o întrebare practică („cât costă kinetoterapia”) se adaugă doar legătura spre pagina serviciului numit.
            $page = collect($found)->first(fn ($f) => array_diff($restTokens, Text::tokens($f['page'])) === []);
            if ($page) {
                $links[] = ['label' => 'Despre '.$page['page'], 'url' => $page['path']];
            }
        } elseif ($found) {
            $best = $found[0];
            $parts[] = ($best['kind'] === 'faq' ? '**'.$best['title'].'** ' : '').self::excerpt($best['text']);
            $source = $best['path'];
            $links[] = ['label' => 'Citiți mai mult: '.$best['page'], 'url' => $best['path']];
            foreach (array_slice($found, 1) as $other) {
                if (count($links) >= 4 || $other['score'] < $best['score'] * 0.45) {
                    break;
                }
                if (! collect($links)->contains('url', $other['path'])) {
                    $links[] = ['label' => $other['page'], 'url' => $other['path']];
                }
            }
        }

        if (! $parts) {
            $phone = $locations->first()?->phone;
            $parts[] = 'Nu am găsit un răspuns sigur în informațiile de pe site. Colegii de la recepție vă pot ajuta'.($phone ? ' la '.$phone : '').'.';
            if ($this->booking->available()) {
                $links[] = ['label' => 'Programare online', 'url' => '/programare-online'];
            }
            $this->addPage($links, '/contactus', 'Formular de contact');

            return $this->reply(implode("\n\n", $parts), $links, 'necunoscut');
        }

        // Întrebări despre simptome sau tratament: informația de pe site nu ține locul consultației.
        if (Text::has($q, ['ce tratament', 'ce sa fac', 'ce sa iau', 'medicament', 'diagnostic', 'ma doare', 'am dureri', 'simptom', 'e grav', 'este grav'])) {
            $parts[] = '_Informațiile de pe site sunt generale. Tratamentul potrivit îl stabilește medicul, după consultație._';
        }

        return $this->reply(implode("\n\n", $parts), array_slice($links, 0, 4), $hits ? 'informatii' : 'continut', $source);
    }

    /** Semne de urgență, în orice ordine a cuvintelor (textul e deja fără diacritice). */
    public static function isEmergency(string $q): bool
    {
        $patterns = [
            '/\b(urgent|urgenta|urgente|ambulanta|accident\w*)\b/u',
            '/\b(durere|dureri|doare|apasare|junghi|strange)\b.*\b(piept|torace|inima)\b|\b(piept|torace|inima)\b.*\b(durere|dureri|doare|apasare|junghi|strange)\b/u',
            '/\b(nu pot respira|respir greu|ma sufoc|sufocare|nu am aer)\b/u',
            '/\b(lesin\w*|inconstient\w*|convulsi\w*|criza de epilepsie)\b/u',
            '/\b(avc|accident vascular|paraliz\w*|nu pot vorbi|vorbesc greu|gura stramba|fata stramba|amorteala brusca)\b/u',
            '/\b(sanger\w*|hemoragi\w*|intoxica\w*|otravi\w*|fractur\w* deschis\w*)\b/u',
        ];
        foreach ($patterns as $re) {
            if (preg_match($re, $q)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{kind: string, title: string, text: string, path: string, page: string, score: float}> */
    public function search(string $question, int $limit = 4): array
    {
        $tokens = array_values(array_unique(Text::tokens($question)));
        if (! $tokens) {
            return [];
        }
        $passages = $this->kb->passages();
        $n = max(1, count($passages));
        $df = [];
        foreach ($passages as $p) {
            foreach (array_unique($p['tokens']) as $t) {
                $df[$t] = ($df[$t] ?? 0) + 1;
            }
        }
        $scored = [];
        foreach ($passages as $p) {
            $set = array_flip($p['tokens']);
            $titles = array_flip($p['title_tokens']);
            $score = 0.0;
            $matched = 0;
            foreach ($tokens as $t) {
                if (isset($set[$t])) {
                    $idf = log(1 + $n / ($df[$t] ?? 1));
                    $score += $idf * (isset($titles[$t]) ? 2 : 1);
                    $matched++;
                }
            }
            if ($matched === 0) {
                continue;
            }
            // Mai multe cuvinte din întrebare în același pasaj, întrebările frecvente și descrierile contează mai mult.
            $score *= ($matched / count($tokens)) * ($p['kind'] === 'faq' ? 1.4 : ($p['kind'] === 'descriere' ? 1.15 : 1));
            $scored[] = $p + ['score' => round($score, 3)];
        }
        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        // Un singur pasaj pe pagină, ca legăturile să fie diferite.
        $out = [];
        foreach ($scored as $s) {
            if (! isset($out[$s['path']])) {
                $out[$s['path']] = $s;
            }
            if (count($out) >= $limit) {
                break;
            }
        }

        return array_values(array_filter($out, fn ($s) => $s['score'] >= 1.5));
    }

    /** Pagina siteului pentru fiecare specialitate publică (din meniul „Specialitate medicală”). */
    private const SPECIALTY_PAGES = [
        'Reumatologie' => '/reumatologie', 'Pediatrie' => '/pediatrie', 'Psihiatrie' => '/psihiatrie',
        'Medicină internă' => '/medicina-interna-consultatii-tirgu-mures', 'Reabilitare medicală' => '/reabilitare-medicala-mures',
        'Cardiologie pediatrică' => '/sevalia-cabinet-maurer-mures',
    ];

    /** Specialitățile clinicii și medicii afișați pe pagina echipei, pentru specialitatea întrebată. @return list<string> */
    private function specialists(string $q, array &$links): array
    {
        $specialties = Specialty::where('is_active', true)->orderBy('sort')->get();
        $asked = $specialties->filter(fn ($sp) => str_contains($q, mb_substr(Text::normalize($sp->name), 0, 7)));
        $parts = [];
        foreach ($asked as $sp) {
            $staff = StaffMember::onSite()->whereHas('specialties', fn ($w) => $w->whereKey($sp->id))->pluck('display_name');
            $parts[] = '**'.$sp->name.'**'.($staff->isNotEmpty() ? ': '.$staff->join(', ').'.' : ' — întrebați recepția despre medicul disponibil.');
            if (isset(self::SPECIALTY_PAGES[$sp->name])) {
                $this->addPage($links, self::SPECIALTY_PAGES[$sp->name], $sp->name);
            }
        }
        if ($asked->isEmpty()) {
            $parts[] = 'Specialitățile clinicii: '.$specialties->pluck('name')->join(', ').'.';
        }
        $this->addPage($links, '/echipa-noastra', 'Echipa noastră');

        return $parts;
    }

    /** Programul grupat pe zile consecutive cu aceleași ore: „Luni–Joi 07:00–22:00; Vineri …; Sâmbătă–Duminică închis”. */
    private function hours(Location $loc): string
    {
        $groups = [];
        foreach (self::DAYS as $n => $day) {
            $slots = implode(', ', array_map(fn ($s) => $s[0].'–'.$s[1], $loc->hoursFor($n))) ?: 'închis';
            $last = array_key_last($groups);
            if ($last !== null && $groups[$last]['hours'] === $slots) {
                $groups[$last]['to'] = $day;
            } else {
                $groups[] = ['from' => $day, 'to' => $day, 'hours' => $slots];
            }
        }

        return implode('; ', array_map(fn ($g) => ($g['from'] === $g['to'] ? $g['from'] : $g['from'].'–'.$g['to']).' '.$g['hours'], $groups));
    }

    private function addPage(array &$links, string $path, string $label): void
    {
        if (! collect($links)->contains('url', $path) && Page::published()->where('path', $path)->exists()) {
            $links[] = ['label' => $label, 'url' => $path];
        }
    }

    private function reply(string $answer, array $links, string $kind, ?string $source = null): array
    {
        return ['answer' => $answer, 'links' => $links, 'source' => $source, 'kind' => $kind];
    }

    public static function excerpt(string $text, int $max = 420): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max);
        $end = max(mb_strrpos($cut, '. ') ?: 0, mb_strrpos($cut, '! ') ?: 0, mb_strrpos($cut, '? ') ?: 0);

        return $end > $max * 0.5 ? mb_substr($cut, 0, $end + 1) : rtrim(mb_substr($cut, 0, mb_strrpos($cut, ' ') ?: $max)).'…';
    }
}
