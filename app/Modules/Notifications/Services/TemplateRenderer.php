<?php

namespace App\Modules\Notifications\Services;

use App\Modules\Notifications\Support\MessageType;

/**
 * Randarea șabloanelor cu variabile dintr-o listă albă închisă ({prenume}, {data} ...).
 * Orice altă acoladă este refuzată la salvare, deci un șablon nu poate cere date medicale.
 */
class TemplateRenderer
{
    public const SMS_MAX = 480;

    /** @return list<string> mesaje de eroare, în română */
    public function validate(string $channel, ?string $subject, string $body, array $providerParams = []): array
    {
        $errors = [];
        $allowed = array_keys(MessageType::variables());
        foreach ([$subject ?? '', $body] as $text) {
            preg_match_all('/\{([^{}]*)\}/u', $text, $m);
            foreach (array_unique($m[1]) as $name) {
                if (! in_array($name, $allowed, true)) {
                    $errors[] = 'Variabila „{'.$name.'}” nu este permisă. Variabile permise: '.implode(', ', array_map(fn ($v) => '{'.$v.'}', $allowed)).'.';
                }
            }
        }
        if (substr_count($body, '{') !== substr_count($body, '}')) {
            $errors[] = 'Textul conține acolade neînchise.';
        }
        foreach ($providerParams as $param) {
            if (! in_array($param, $allowed, true)) {
                $errors[] = 'Parametrul WhatsApp „'.$param.'” nu este o variabilă permisă.';
            }
        }
        if (in_array($channel, ['sms', 'whatsapp'], true)) {
            if ($body !== strip_tags($body)) {
                $errors[] = 'Mesajele SMS și WhatsApp nu pot conține HTML.';
            }
            if (mb_strlen($body) > self::SMS_MAX) {
                $errors[] = 'Textul are '.mb_strlen($body).' caractere; maximum '.self::SMS_MAX.' pentru SMS/WhatsApp.';
            }
        }
        if ($channel === 'email' && trim((string) $subject) === '') {
            $errors[] = 'E-mailul are nevoie de un subiect.';
        }

        return array_values(array_unique($errors));
    }

    /** Înlocuiește variabilele; valorile lipsă devin text gol. */
    public function render(string $text, array $vars, bool $singleLine = false): string
    {
        $out = preg_replace_callback('/\{([a-z_]+)\}/', function ($m) use ($vars, $singleLine) {
            $value = (string) ($vars[$m[1]] ?? '');

            return $singleLine ? preg_replace('/\s+/', ' ', $value) : $value;
        }, $text);

        return trim((string) $out);
    }

    /** Valori fictive pentru previzualizare. */
    public static function sampleVariables(): array
    {
        return [
            'prenume' => 'Maria', 'data' => '13.11.2026', 'ora' => '09:30',
            'ziua' => \Carbon\Carbon::create(2026, 11, 13)->locale('ro')->isoFormat('dddd'),
            'locatia' => 'Locație cartier UNIRII', 'adresa_locatiei' => 'str. Exemplu nr. 1', 'telefon_clinica' => '0300 000 000',
            'serviciu' => 'consultație', 'link_gestionare' => 'https://exemplu.invalid/p/…', 'link_feedback' => 'https://exemplu.invalid/feedback/…',
            'referinta' => 'S-0000', 'clinica' => 'SEVALIA',
        ];
    }
}
