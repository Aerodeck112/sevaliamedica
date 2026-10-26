<?php

namespace App\Modules\Notifications\Support;

/**
 * Tipurile de mesaje. Toate sunt operaționale (legate de o programare sau solicitare existentă), cu excepția
 * categoriei „marketing”, pentru care există doar infrastructura (necesită consimțământ explicit, fără campanii).
 */
final class MessageType
{
    public const CONFIRMARE = 'confirmare';

    public const REMINDER = 'reminder';

    public const ANULARE = 'anulare';

    public const REPROGRAMARE = 'reprogramare';

    public const SOLICITARE = 'solicitare_inregistrata';

    public const OFERTA = 'oferta_lista_asteptare';

    public const FOLLOW_UP = 'follow_up';

    public const FEEDBACK = 'feedback';

    public const CHANNELS = ['email' => 'E-mail', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp'];

    /** @return array<string, array{label: string, category: string, help: string}> */
    public static function all(): array
    {
        return [
            self::CONFIRMARE => ['label' => 'Confirmare programare', 'category' => 'operational', 'help' => 'Trimis când o programare devine confirmată.'],
            self::REMINDER => ['label' => 'Reminder', 'category' => 'operational', 'help' => 'Trimis cu un număr configurabil de ore înainte de programare.'],
            self::ANULARE => ['label' => 'Anulare', 'category' => 'operational', 'help' => 'Trimis când programarea este anulată de clinică (nu când pacientul anulează singur prin link).'],
            self::REPROGRAMARE => ['label' => 'Reprogramare', 'category' => 'operational', 'help' => 'Trimis când ora sau ziua programării se schimbă.'],
            self::SOLICITARE => ['label' => 'Solicitare înregistrată', 'category' => 'operational', 'help' => 'Confirmă primirea solicitării de pe site. Nu promite un interval.'],
            self::OFERTA => ['label' => 'Ofertă din lista de așteptare', 'category' => 'operational', 'help' => 'Trimis când recepția oferă un interval eliberat.'],
            self::FOLLOW_UP => ['label' => 'Follow-up administrativ', 'category' => 'operational', 'help' => 'Trimis manual de recepție (ex. documente de adus). Fără conținut medical.'],
            self::FEEDBACK => ['label' => 'Solicitare de feedback', 'category' => 'operational', 'help' => 'Trimis după o programare finalizată, cu link către un formular scurt fără date medicale.'],
        ];
    }

    public static function label(string $type): string
    {
        return self::all()[$type]['label'] ?? $type;
    }

    /**
     * Variabilele permise în șabloane. Lista este închisă: nu există și nu se pot adăuga variabile cu date medicale
     * (diagnostic, observații, documente, denumirea exactă a procedurii).
     *
     * @return array<string, string>
     */
    public static function variables(): array
    {
        return [
            'prenume' => 'Prenumele pacientului (sau numele din solicitare)',
            'data' => 'Data programării (zz.ll.aaaa)',
            'ora' => 'Ora programării (hh:mm)',
            'ziua' => 'Ziua săptămânii',
            'locatia' => 'Denumirea locației',
            'adresa_locatiei' => 'Adresa locației',
            'telefon_clinica' => 'Telefonul locației',
            'serviciu' => 'Tipul generic al serviciului (ex. „consultație”), fără denumirea procedurii',
            'link_gestionare' => 'Link de anulare / cerere de reprogramare (token limitat)',
            'link_feedback' => 'Link către formularul de feedback',
            'referinta' => 'Numărul de referință al solicitării',
            'clinica' => 'Numele clinicii',
        ];
    }

    /** Texte implicite, neutre, fără conținut medical. Clinica le editează din panou. */
    public static function defaults(): array
    {
        $sms = [
            self::CONFIRMARE => 'SEVALIA: {prenume}, programarea din {data}, ora {ora}, la {locatia} este confirmată. Anulare sau reprogramare: {link_gestionare}',
            self::REMINDER => 'SEVALIA: Vă reamintim programarea din {data}, ora {ora}, la {locatia}. Anulare sau reprogramare: {link_gestionare}',
            self::ANULARE => 'SEVALIA: Programarea din {data}, ora {ora} a fost anulată. Pentru o nouă programare sunați la {telefon_clinica}.',
            self::REPROGRAMARE => 'SEVALIA: Programarea dvs. a fost mutată pe {data}, ora {ora}, la {locatia}. Anulare sau reprogramare: {link_gestionare}',
            self::SOLICITARE => 'SEVALIA: Am primit solicitarea dvs. (ref. {referinta}). Vă contactăm pentru stabilirea programării; intervalul nu este încă rezervat.',
            self::OFERTA => 'SEVALIA: S-a eliberat un loc pe {data}, ora {ora}, la {locatia}. Dacă îl doriți, sunați la {telefon_clinica}.',
            self::FOLLOW_UP => 'SEVALIA: {prenume}, vă rugăm să ne contactați la {telefon_clinica} pentru o informare administrativă privind programarea dvs.',
            self::FEEDBACK => 'SEVALIA: Vă mulțumim pentru vizită. Ne spuneți cum a fost? {link_feedback}',
        ];
        $subjects = [
            self::CONFIRMARE => 'Programare confirmată — {data}, ora {ora}',
            self::REMINDER => 'Reminder programare — {data}, ora {ora}',
            self::ANULARE => 'Programare anulată — {data}',
            self::REPROGRAMARE => 'Programare mutată — {data}, ora {ora}',
            self::SOLICITARE => 'Am primit solicitarea dumneavoastră',
            self::OFERTA => 'Un interval s-a eliberat',
            self::FOLLOW_UP => 'Informare administrativă',
            self::FEEDBACK => 'Cum a fost vizita la SEVALIA?',
        ];
        $out = [];
        foreach ($sms as $type => $text) {
            $email = "Bună ziua, {prenume},\n\n".preg_replace('/^SEVALIA: /', '', $text)."\n\nCu stimă,\nEchipa {clinica}\n\nMesaj operațional transmis în legătură cu programarea dumneavoastră. Nu conține date medicale.";
            $out[$type] = [
                'sms' => ['body' => $text],
                'whatsapp' => ['body' => $text],
                'email' => ['subject' => $subjects[$type], 'body' => $email],
            ];
        }

        return $out;
    }
}
