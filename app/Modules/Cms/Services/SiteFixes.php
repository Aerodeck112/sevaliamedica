<?php

namespace App\Modules\Cms\Services;

use App\Modules\Clinic\Models\Location;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\Page;
use App\Modules\Site\Services\PageCache;
use Illuminate\Support\Str;

/**
 * Auditul siteului cerut de clinică (2026-11-02): corecturi aplicate conținutului preluat de pe siteul vechi.
 *
 *  - un titlu H1 real pe fiecare pagină (paginile cu H1 gol primesc titlul vizibil al paginii; paginile de servicii
 *    primesc un H1 descriptiv, cu localitatea, afișat la fel ca înainte — cu majuscule);
 *  - greșelile de text notate la preluare (docs/audit/OBSERVATII-CONTINUT.md);
 *  - titluri pentru motoarele de căutare consecvente, cu diacritice (doar din informațiile existente pe pagini);
 *  - paginile care lipseau, dar spre care existau linkuri: /neurologie (meniu, pagina principală) și /dry-needling;
 *  - pagina /locatii, locația Band în Clinică → Locații și elementul de meniu „Locații”.
 *
 * Fiecare pagină modificată primește o versiune nouă publicată (istoricul păstrează versiunea anterioară). Aplicarea
 * este idempotentă; paginile noi și meniul se creează doar dacă lipsesc, deci modificările ulterioare făcute din
 * CMS nu sunt suprascrise.
 */
final class SiteFixes
{
    public const NOTE = 'Audit site 2026-11: H1, corecturi, titluri (cerute de clinică)';

    public const BAND = [
        'name' => 'Locație BAND – medicină de familie',
        'slug' => 'band',
        'address' => 'Strada Păcii 19',
        'city' => 'Band',
        'county' => 'Mureș',
        'phone' => '+40 742 281 438',
        'map_query' => 'Strada Păcii 19, 547065 Band',
    ];

    /** H1 pe pagină: text nou; „drop” = H1-urile goale devin spațiu; „promote” = titlul vizibil care devine H1. */
    private const H1 = [
        '/servicii' => ['text' => 'Servicii de recuperare medicală în Târgu Mureș', 'drop' => true, 'upper' => true],
        '/contactus' => ['text' => 'Contact și programări – cu ce te putem ajuta?', 'drop' => true, 'promote' => 'Cu ce te putem ajuta?'],
        '/politica-de-confidentialitate' => ['text' => 'Politica de confidențialitate', 'drop' => true, 'promote' => 'Politica de confidențialitate'],
        '/termeni-si-conditii' => ['text' => 'Termeni și condiții', 'drop' => true, 'promote' => 'Termeni și condiții'],
        '/tarife-asistenta-medicala-ambulatorie-clinica' => ['text' => 'Tarife asistență medicală ambulatorie'],
        '/cum-ajung-la-clinica-sevalia' => ['text' => 'Cum ajungeți la clinica Sevalia'],
        '/pediatrie' => ['text' => 'Pediatrie în Târgu Mureș'],
        '/psihiatrie' => ['text' => 'Psihiatrie în Târgu Mureș'],
        '/bai-galvanice' => ['text' => 'Băi galvanice în Târgu Mureș', 'upper' => true],
        '/baie-stanger-electrohidroterapie' => ['text' => 'Baie Stanger (electrohidroterapie) în Târgu Mureș', 'upper' => true],
        '/decompresie-vertebrala-robotizata' => ['text' => 'Decompresie vertebrală robotizată în Târgu Mureș', 'upper' => true],
        '/drenaj-limfatic' => ['text' => 'Drenaj limfatic în Târgu Mureș', 'upper' => true],
        '/dus-subacval' => ['text' => 'Duș subacval în Târgu Mureș', 'upper' => true],
        '/electroterapie' => ['text' => 'Electroterapie în Târgu Mureș', 'upper' => true],
        '/hydro-physio-banda-de-alergat-subacvala-recuperare-rapida' => ['text' => 'Hydro Physio – bandă de alergat subacvală', 'upper' => true],
        '/kinesio-taping' => ['text' => 'Kinesio taping în Târgu Mureș', 'upper' => true],
        '/kinetoterapie' => ['text' => 'Kinetoterapie în Târgu Mureș', 'upper' => true],
        '/kinetoterapie-atm-articulatia-temporo-mandibulara' => ['text' => 'Kinetoterapie ATM (articulația temporo-mandibulară)', 'upper' => true],
        '/laserterapie' => ['text' => 'Laserterapie în Târgu Mureș', 'upper' => true],
        '/magnetoterapie' => ['text' => 'Magnetoterapie în Târgu Mureș', 'upper' => true],
        '/masaj' => ['text' => 'Masaj terapeutic în Târgu Mureș', 'upper' => true],
        '/medicina-interna-consultatii-tirgu-mures' => ['text' => 'Medicină internă în Târgu Mureș', 'upper' => true],
        '/reabilitare-medicala-mures' => ['text' => 'Reabilitare medicală în Târgu Mureș', 'upper' => true],
        '/reumatologie' => ['text' => 'Reumatologie în Târgu Mureș', 'upper' => true],
        '/salinoterapie' => ['text' => 'Salinoterapie în Târgu Mureș', 'upper' => true],
        '/shockwave-focusat' => ['text' => 'Terapie ShockWave focusat în Târgu Mureș', 'upper' => true],
        '/super-inductive-system-sis' => ['text' => 'Super Inductive System (SIS) în Târgu Mureș', 'upper' => true],
        '/termoterapie' => ['text' => 'Termoterapie în Târgu Mureș', 'upper' => true],
        '/tr-therapy' => ['text' => 'TR-Therapy (TECAR) în Târgu Mureș', 'upper' => true],
        '/ultrasonoterapie' => ['text' => 'Ultrasonoterapie în Târgu Mureș', 'upper' => true],
    ];

    /** Titluri pentru motoarele de căutare (și pentru rețelele sociale). */
    private const TITLES = [
        '/alterg-via-banda-antigravitationala-recuperare-medicala' => 'AlterG VIA – bandă antigravitațională pentru recuperare | SEVALIA',
        '/am-dureri' => 'Ameliorarea durerilor – recuperare în Târgu Mureș | SEVALIA',
        '/am-leziuni' => 'Recuperare după leziuni pentru sportivi, Târgu Mureș | SEVALIA',
        '/bai-galvanice' => 'Băi galvanice Târgu Mureș – terapie cu apă și curent | SEVALIA',
        '/baie-stanger-electrohidroterapie' => 'Baia Stanger Târgu Mureș – electrohidroterapie | SEVALIA',
        '/contactus' => 'Contact și programări – Sevalia Târgu Mureș',
        '/cum-ajung-la-clinica-sevalia' => 'Cum ajungeți la clinica Sevalia, Târgu Mureș',
        '/decompresie-vertebrala-robotizata' => 'Decompresie vertebrală robotizată Târgu Mureș | SEVALIA',
        '/drenaj-limfatic' => 'Drenaj limfatic Târgu Mureș – circulația limfatică | SEVALIA',
        '/dus-subacval' => 'Duș subacval Târgu Mureș – terapie hidrokinetică | SEVALIA',
        '/echipa-noastra' => 'Echipa Sevalia – medici și terapeuți în Târgu Mureș',
        '/electroterapie' => 'Electroterapie Târgu Mureș | SEVALIA',
        '/hydro-physio-banda-de-alergat-subacvala-recuperare-rapida' => 'Hydro Physio – bandă de alergat subacvală, Târgu Mureș | SEVALIA',
        '/kinesio-taping' => 'Kinesio taping Târgu Mureș – susținere musculară | SEVALIA',
        '/laserterapie' => 'Laserterapie Târgu Mureș | SEVALIA',
        '/magnetoterapie' => 'Magnetoterapie Târgu Mureș | SEVALIA',
        '/medicina-interna-consultatii-tirgu-mures' => 'Medicină internă Târgu Mureș – consultații | SEVALIA',
        '/pediatrie' => 'Pediatrie Târgu Mureș – consultații pentru copii | SEVALIA',
        '/politica-de-confidentialitate' => 'Politica de confidențialitate | SEVALIA',
        '/psihiatrie' => 'Psihiatrie Târgu Mureș – consultații | SEVALIA',
        '/recuperare-postoperatorie-cpmotion' => 'Recuperare postoperatorie ortopedică – CPMotion | SEVALIA',
        '/reumatologie' => 'Reumatologie Târgu Mureș – consultații | SEVALIA',
        '/salinoterapie' => 'Salinoterapie Târgu Mureș – haloterapie | SEVALIA',
        '/servicii' => 'Servicii de recuperare și fizioterapie Târgu Mureș | SEVALIA',
        '/sevalia-cabinet-maurer-mures' => 'Cabinet Sevalia Maurer – pediatrie, neurologie, psihiatrie | Târgu Mureș',
        '/shockwave-focusat' => 'Terapie ShockWave focusat Târgu Mureș | SEVALIA',
        '/sunt-insarcinata' => 'Programe pentru gravide, Târgu Mureș | SEVALIA',
        '/super-inductive-system-sis' => 'Super Inductive System (SIS) Târgu Mureș | SEVALIA',
        '/tarife-asistenta-medicala-ambulatorie-clinica' => 'Tarife asistență medicală ambulatorie | SEVALIA',
        '/termeni-si-conditii' => 'Termeni și condiții | SEVALIA',
        '/termoterapie' => 'Termoterapie cu parafină Târgu Mureș | SEVALIA',
        '/tr-therapy' => 'TR-Therapy (TECAR) Târgu Mureș – reabilitare | SEVALIA',
        '/ultrasonoterapie' => 'Ultrasonoterapie Târgu Mureș – terapie cu ultrasunete | SEVALIA',
        '/vreau-sa-ma-simt-bine' => 'Programe de recuperare personalizate, Târgu Mureș | SEVALIA',
        '/blog/blog-sevalia-1/ce-este-enteza-2' => 'Ce este enteza? | Blog Sevalia',
        '/blog/blog-sevalia-1/ce-face-de-fapt-medicul-internist-5' => 'Ce face, de fapt, medicul internist? | Sevalia Târgu Mureș',
        '/blog/blog-sevalia-1/guta-si-manifestarile-ei-3' => 'Guta și manifestările ei | Blog Sevalia',
        '/blog/blog-sevalia-1/incontinenta-urinara-7' => 'Incontinența urinară – când recuperarea devine posibilă | Blog Sevalia',
    ];

    private const DESCRIPTIONS = [
        '/tarife-asistenta-medicala-ambulatorie-clinica' => 'Tarifele serviciilor de asistență medicală ambulatorie de specialitate la clinica Sevalia din Târgu Mureș.',
    ];

    /** Corecturi de text, pe toate paginile (inclusiv subsolul). */
    private const TEXT = [
        'Electroterapie este o componentă a fizioterapie ce' => 'Electroterapia este o componentă a fizioterapiei care',
        'fortifierea' => 'fortificarea',
        'unul dintre colegii noștri te vor contacta' => 'unul dintre colegii noștri te va contacta',
        'Data nasterii' => 'Data nașterii',
        'Tîrgu Mureș' => 'Târgu Mureș',
        'Bai galvanice' => 'Băi galvanice',
        'Dus subacval' => 'Duș subacval',
        "consultație\u{200B}" => 'consultație',
        // Resturi din documentele sursă: un câmp de șablon necompletat și marcaje de comentarii Word.
        'La [Clinică] consultațiile' => 'La Sevalia consultațiile',
        '[u1]' => '',
    ];

    // ------------------------------------------------------------------ aplicare

    /** @return array{pages: list<string>, created: list<string>, menu: bool, location: bool} */
    public static function run(PageService $pages): array
    {
        $changed = [];
        foreach (Page::whereNotNull('published_version_id')->with('publishedVersion')->get() as $page) {
            if (self::fixPage($pages, $page)) {
                $changed[] = $page->path;
            }
        }
        $created = [];
        foreach (self::newPages() as $path => $def) {
            if (! Page::where('path', $path)->exists()) {
                $page = Page::create(['path' => $path, 'title' => $def['title'], 'kind' => Page::KIND_PAGE]);
                $version = $pages->saveVersion($page, $def['blocks'], $def['seo'], [], null, null, self::NOTE);
                $pages->publish($page->fresh(), $version, null);
                $created[] = $path;
            }
        }
        $location = self::ensureBandLocation();
        $menu = self::ensureMenu();
        PageCache::flush();

        return ['pages' => $changed, 'created' => $created, 'menu' => $menu, 'location' => $location];
    }

    private static function fixPage(PageService $pages, Page $page): bool
    {
        $version = $page->publishedVersion;
        if (! $version) {
            return false;
        }
        $blocks = $version->blocks;
        $seo = (array) $version->seo;

        foreach ($blocks as $i => $block) {
            if (is_string($block['html'] ?? null)) {
                $blocks[$i]['html'] = strtr($block['html'], self::TEXT);
                if ($page->path === '/blog/blog-sevalia-1/deficitul-de-vitamina-d-1') {
                    // Imaginea goală (fără sursă) moștenită din editorul Odoo.
                    $blocks[$i]['html'] = (string) preg_replace('~<img\b(?![^>]*\bsrc=)[^>]*>~i', '', $blocks[$i]['html']);
                }
            }
        }
        if (isset(self::H1[$page->path])) {
            $blocks = self::fixH1($blocks, self::H1[$page->path]);
        }
        if (isset(self::TITLES[$page->path])) {
            $seo['title'] = self::TITLES[$page->path];
            $seo['og_title'] = self::TITLES[$page->path];
        }
        if (isset(self::DESCRIPTIONS[$page->path]) && trim((string) ($seo['description'] ?? '')) === '') {
            $seo['description'] = self::DESCRIPTIONS[$page->path];
        }
        foreach (['title', 'og_title', 'description'] as $k) {
            if (isset($seo[$k]) && is_string($seo[$k])) {
                $seo[$k] = strtr($seo[$k], self::TEXT);
            }
        }

        if ($blocks === $version->blocks && $seo == (array) $version->seo) {
            return false;
        }
        $new = $pages->saveVersion($page, $blocks, $seo, (array) $version->settings, null, null, self::NOTE);
        $pages->publish($page->fresh(), $new, null);

        return true;
    }

    /** @param array{text: string, drop?: bool, promote?: string, upper?: bool} $spec */
    public static function fixH1(array $blocks, array $spec): array
    {
        $text = e($spec['text']);
        if (! empty($spec['drop'])) {
            foreach ($blocks as $i => $b) {
                if (is_string($b['html'] ?? null)) {
                    $blocks[$i]['html'] = (string) preg_replace_callback('~<h1\b([^>]*)>(.*?)</h1>~su',
                        fn ($m) => self::isEmpty($m[2]) ? '<div class="h1" aria-hidden="true"><br></div>' : $m[0], $b['html']);
                }
            }
        }
        if (! empty($spec['promote'])) {
            $target = self::plain($spec['promote']);
            foreach ($blocks as $i => $b) {
                if (! is_string($b['html'] ?? null)) {
                    continue;
                }
                $done = false;
                $blocks[$i]['html'] = (string) preg_replace_callback('~<(h[2-6])\b([^>]*)>(.*?)</\1>~su', function ($m) use (&$done, $target, $text) {
                    if ($done || self::plain($m[3]) !== $target) {
                        return $m[0];
                    }
                    $done = true;

                    return '<h1'.$m[2].'>'.$text.'</h1>';
                }, $b['html']);
                if ($done) {
                    return $blocks;
                }
            }
        }
        // Primul H1 al paginii primește textul (atributele se păstrează).
        foreach ($blocks as $i => $b) {
            if (! is_string($b['html'] ?? null) || ! preg_match('~<h1\b~', $b['html'])) {
                continue;
            }
            $blocks[$i]['html'] = (string) preg_replace_callback('~<h1\b([^>]*)>(.*?)</h1>~su', function ($m) use ($text, $spec) {
                $attrs = $m[1];
                if (! empty($spec['upper']) && ! str_contains($attrs, 'text-transform')) {
                    $attrs = preg_match('~\sstyle="~', $attrs)
                        ? preg_replace('~\sstyle="~', ' style="text-transform: uppercase; ', $attrs, 1)
                        : $attrs.' style="text-transform: uppercase;"';
                }

                return '<h1'.$attrs.'>'.$text.'</h1>';
            }, $b['html'], 1);

            return $blocks;
        }

        return $blocks;
    }

    private static function plain(string $html): string
    {
        $t = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/[\s\x{200B}\x{00A0}]+/u', ' ', $t));
    }

    private static function isEmpty(string $html): bool
    {
        return self::plain($html) === '';
    }

    // ------------------------------------------------------------------ locații și meniu

    private static function ensureBandLocation(): bool
    {
        if (Location::where('slug', self::BAND['slug'])->exists()) {
            return false;
        }
        Location::create(self::BAND + ['is_active' => true, 'sort' => (int) Location::max('sort') + 1]);

        return true;
    }

    private static function ensureMenu(): bool
    {
        if (MenuItem::where('menu', 'header')->whereNull('parent_id')->where('label', 'Locații')->exists()) {
            return false;
        }
        $after = MenuItem::where('menu', 'header')->whereNull('parent_id')->where('url', '/echipa-noastra')->value('sort');
        $sort = $after !== null ? $after + 1 : (int) MenuItem::where('menu', 'header')->whereNull('parent_id')->max('sort') + 1;
        MenuItem::where('menu', 'header')->whereNull('parent_id')->where('sort', '>=', $sort)->increment('sort');
        $menu = 'header';
        MenuItem::create([
            'menu' => $menu, 'parent_id' => null, 'label' => 'Locații', 'url' => null, 'kind' => 'mega',
            'mega_block' => ['uid' => 'locatii', 'type' => 'html', 'label' => 'Locații', 'snippet' => null, 'hidden' => false, 'html' => self::megaHtml()],
            'new_tab' => false, 'is_visible' => true, 'sort' => $sort,
        ]);

        return true;
    }

    private static function megaHtml(): string
    {
        $col = fn (string $href, string $title, string $sub) => '<div class="col-12 col-sm py-2 d-flex align-items-center o_colored_level"><nav class="nav flex-column">'
            .'<a href="'.$href.'" class="nav-link px-2 rounded text-wrap"><i class="fa fa-map-marker s_mega_menu_little_icons_icon fa-fw me-2"></i> <b style="display: inline-block;">'.$title.'</b>'
            .'<br><span class="small text-muted ms-4">'.$sub.'</span></a></nav></div>';

        return '<section class="s_mega_menu_little_icons overflow-hidden o_colored_level o_cc o_cc1"><div class="container-fluid"><div class="row">'
            .$col('/locatii#unirii', 'Târgu Mureș – cartier Unirii', 'Reumatologie, reabilitare, medicină internă, terapii')
            .$col('/sevalia-cabinet-maurer-mures', 'Târgu Mureș – cartier Maurer', 'Pediatrie, cardiologie pediatrică, neurologie, psihiatrie')
            .$col('/medicina-de-familie-band-mures', 'Band, jud. Mureș', 'Medicină de familie, centru de permanență')
            .'<div class="p-4 s_mega_menu_gray_area o_colored_level col-lg-3"><p class="text-muted">'
            .'<a href="/locatii" class="btn btn-secondary mb-2">Toate locațiile, adrese și program</a><br><br>'
            .'<a href="/cum-ajung-la-clinica-sevalia" class="btn btn-secondary mb-2">Cum ajungi la noi</a></p></div>'
            .'</div></div></section>';
    }

    // ------------------------------------------------------------------ pagini noi

    /** @return array<string, array{title: string, seo: array, blocks: list<array>}> */
    public static function newPages(): array
    {
        return [
            '/neurologie' => [
                'title' => 'Neurologie',
                'seo' => [
                    'title' => 'Neurologie Târgu Mureș – consultații | SEVALIA',
                    'og_title' => 'Neurologie Târgu Mureș – consultații | SEVALIA',
                    'description' => 'Consultații de neurologie la cabinetul Sevalia Maurer din Târgu Mureș: dureri de cap frecvente, amețeli, tulburări de echilibru, amorțeli, probleme de memorie sau de somn.',
                    'noindex' => false,
                ],
                'blocks' => [
                    self::block('titlu', self::band('Neurologie în Târgu Mureș')),
                    self::block('text', self::section(
                        '<h2>Diagnostic și tratament pentru afecțiuni neurologice</h2>'
                        .'<p>La cabinetul Sevalia din cartierul Maurer oferim diagnostic și tratament pentru afecțiuni neurologice, evaluări specializate și planuri terapeutice personalizate.</p>'
                        .'<p><strong>Medicul nostru neurolog:</strong> dr. Orban Emese, medic specialist neurolog.</p>'
                        .'<h3>Când ar trebui să mă prezint la un neurolog?</h3>'
                        .'<p>Consultația este recomandată în cazul durerilor de cap frecvente, amețelilor, tulburărilor de echilibru, amorțelilor sau problemelor de memorie.</p>'
                        .'<h3>Sunt durerile de cap frecvente un motiv de îngrijorare?</h3>'
                        .'<p>Dacă durerile sunt recurente, intense sau afectează activitățile zilnice, este important un consult neurologic pentru stabilirea cauzei. Citește și articolul nostru <a href="/blog/blog-sevalia-1/de-ce-te-doare-capul-cand-e-normal-si-cand-ar-trebui-sa-mergi-la-neurolog-12">De ce te doare capul? Când e cazul să mergi la un neurolog?</a></p>'
                        .'<h3>Se pot trata tulburările de somn la neurolog?</h3>'
                        .'<p>Da, anumite tulburări de somn pot avea cauze neurologice și necesită evaluare și tratament specific.</p>'
                    )),
                    self::block('locatie', self::locationBox('Unde ne găsești', 'Cabinet Sevalia Maurer', 'str. Ion Heliade Rădulescu B26 (lângă grădiniță), Târgu Mureș', '0741 558 558', '/sevalia-cabinet-maurer-mures')),
                    self::block('cta', self::cta('Dureri de cap, amețeli sau amorțeli care nu trec? Un consult neurologic este primul pas spre a afla cauza.')),
                ],
            ],
            '/dry-needling' => [
                'title' => 'Dry needling',
                'seo' => [
                    'title' => 'Dry needling Târgu Mureș | SEVALIA',
                    'og_title' => 'Dry needling Târgu Mureș | SEVALIA',
                    'description' => 'Dry needling la Sevalia Târgu Mureș: inactivarea punctelor de declanșare miofasciale, zone ale mușchilor tensionate din cauza stresului, posturii incorecte sau suprasolicitării.',
                    'noindex' => false,
                ],
                'blocks' => [
                    self::block('titlu', self::band('Dry needling în Târgu Mureș')),
                    self::block('text', self::section(
                        '<h2>Ce este dry needling?</h2>'
                        .'<p>Dry needling funcționează prin inactivarea punctelor de declanșare miofasciale, adică zone ale mușchilor care au devenit tensionate din cauza stresului, posturii incorecte sau suprasolicitării.</p>'
                        .'<p>Terapia face parte din programele de recuperare de la Sevalia și poate fi combinată, la recomandarea specialistului, cu <a href="/kinetoterapie">kinetoterapia</a> sau <a href="/masaj">masajul terapeutic</a>. Vezi toate <a href="/servicii">serviciile noastre</a>.</p>'
                    )),
                    self::block('cta', self::cta('Vrei să afli dacă dry needling este potrivit pentru tine? Programează o evaluare.')),
                ],
            ],
            '/locatii' => [
                'title' => 'Locații',
                'seo' => [
                    'title' => 'Locațiile Sevalia – Târgu Mureș (Unirii, Maurer) și Band | SEVALIA',
                    'og_title' => 'Locațiile Sevalia – Târgu Mureș și Band',
                    'description' => 'Clinica Sevalia: două locații în Târgu Mureș (cartier Unirii și cartier Maurer) și cabinetul de medicină de familie din Band. Adrese, telefoane, program și specialități.',
                    'noindex' => false,
                ],
                'blocks' => [
                    self::block('titlu', self::band('Locațiile Sevalia')),
                    self::block('locatii', '<section class="s_text_block pt48 pb48 o_colored_level"><div class="container"><div class="row">'
                        .self::card('unirii', 'Târgu Mureș – cartier Unirii', 'str. Constandin Hagi Stoian, nr. 3C, Târgu Mureș', '0365 403 001',
                            'Reumatologie, reabilitare medicală, medicină internă, recuperare și fizioterapie', 'Luni – Joi: 07:00 – 22:00<br>Vineri: 07:00 – 19:00', '/servicii', 'Vezi serviciile')
                        .self::card('maurer', 'Târgu Mureș – cartier Maurer', 'str. Ion Heliade Rădulescu B26 (lângă grădiniță), Târgu Mureș', '0741 558 558',
                            'Pediatrie, cardiologie pediatrică, neurologie, psihiatrie', 'Luni – Joi: 07:00 – 21:00<br>Vineri: 07:00 – 19:00', '/sevalia-cabinet-maurer-mures', 'Despre cabinetul Maurer')
                        .self::card('band', 'Band, jud. Mureș', 'Strada Păcii 19, 547065 Band', '+40 742 281 438',
                            'Medicină de familie, centru de permanență', null, '/medicina-de-familie-band-mures', 'Despre Sevalia Band')
                        .'</div><p class="mt-4"><a href="/cum-ajung-la-clinica-sevalia">Cum ajungeți la noi cu transportul în comun</a></p></div></section>'),
                    self::block('cta', self::cta('Nu știi la ce locație să te programezi? Scrie-ne și te îndrumăm către specialistul potrivit.')),
                ],
            ],
        ];
    }

    private static function block(string $label, string $html): array
    {
        return ['uid' => Str::lower(Str::random(11)), 'type' => 'html', 'label' => $label, 'snippet' => null, 'hidden' => false, 'html' => $html];
    }

    /** Banda de titlu, ca pe pagina Servicii. */
    private static function band(string $title): string
    {
        return '<section class="s_title pt40 pb40 o_colored_level o_cc o_cc5" data-snippet="s_title"><div class="s_allow_columns container">'
            .'<h1 style="text-align: left; text-transform: uppercase;"><strong>'.e($title).'</strong></h1></div></section>';
    }

    private static function section(string $inner): string
    {
        return '<section class="s_text_block pt48 pb24 o_colored_level" data-snippet="s_text_block"><div class="s_allow_columns container">'.$inner.'</div></section>';
    }

    private static function cta(string $text): string
    {
        return '<section class="s_call_to_action pt64 pb48 o_colored_level o_cc o_cc5" data-snippet="s_call_to_action"><div class="container"><div class="row">'
            .'<div class="col-lg-9 pb16 o_colored_level"><h3>'.e($text).'</h3></div>'
            .'<div class="col-lg-3 pt8 o_colored_level"><p style="text-align: right;"><a class="mb-2 btn btn-secondary btn-lg" href="/contactus">Programează un consult</a></p></div>'
            .'</div></div></section>';
    }

    private static function locationBox(string $heading, string $name, string $address, string $phone, string $href): string
    {
        return '<section class="s_text_block pt24 pb48 o_colored_level"><div class="container"><h2>'.e($heading).'</h2>'
            .'<p><i class="fa fa-map-marker fa-fw me-2"></i><strong>'.e($name).'</strong>, '.e($address).'<br>'
            .'<i class="fa fa-phone fa-fw me-2"></i><a href="tel:'.preg_replace('/[^0-9+]/', '', $phone).'">'.e($phone).'</a></p>'
            .'<p><a href="'.$href.'">Mai multe despre această locație</a> · <a href="/locatii">Toate locațiile</a></p></div></section>';
    }

    private static function card(string $id, string $title, string $address, string $phone, string $specialties, ?string $hours, string $href, string $link): string
    {
        return '<div class="col-lg-4 pb24 o_colored_level" id="'.$id.'"><div class="h-100 p-4 rounded border">'
            .'<h2 class="h4"><i class="fa fa-map-marker me-2"></i>'.e($title).'</h2>'
            .'<p>'.e($address).'<br><i class="fa fa-phone fa-fw me-1"></i><a href="tel:'.preg_replace('/[^0-9+]/', '', $phone).'">'.e($phone).'</a></p>'
            .'<p><strong>Specialități:</strong> '.e($specialties).'</p>'
            .($hours ? '<p><strong>Program:</strong><br>'.$hours.'</p>' : '<p><strong>Program:</strong> sunați pentru programări și informații.</p>')
            .'<p><a href="'.$href.'">'.e($link).'</a> · <a href="https://maps.google.com/maps?q='.rawurlencode($address).'" target="_blank" rel="noopener">Deschide în Google Maps</a></p>'
            .'</div></div>';
    }
}
