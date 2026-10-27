<?php

namespace App\Modules\Marketing\Services;

/**
 * Verifică dacă pagina țintă a unui cuvânt cheie îl conține în locurile care contează (titlu, H1, descriere, text).
 * Comparația ignoră diacriticele, majusculele și variantele „Tg. Mureș” / „Târgu Mureș”. Nu estimează poziția în
 * Google: aceasta depinde și de factori din afara siteului (recenzii, linkuri, profilul Google Business).
 */
class KeywordMatcher
{
    private const STOPWORDS = ['in', 'si', 'de', 'la', 'pentru', 'cu', 'din', 'pe', 'a', 'al', 'ale'];

    /** Puncte pe locul în care apare expresia: titlul contează cel mai mult. */
    public const WEIGHTS = ['title' => 40, 'h1' => 20, 'description' => 20, 'text' => 20];

    public static function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        $text = strtr($text, ['ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't', 'ă' => 'a', 'â' => 'a', 'î' => 'i']);
        $text = preg_replace('/\btg\.?\s*(?=mures)/u', 'targu ', $text);
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);

        return trim(preg_replace('/\s+/', ' ', $text));
    }

    /** @return list<string> */
    public static function tokens(string $phrase): array
    {
        return array_values(array_filter(explode(' ', self::normalize($phrase)), fn ($t) => $t !== '' && ! in_array($t, self::STOPWORDS, true)));
    }

    /**
     * „exact” = expresia apare întocmai; „partial” = toate cuvintele ei apar în același câmp; „lipsa” altfel.
     *
     * @param  array{title?: ?string, h1?: ?string, description?: ?string, text?: ?string}  $fields
     * @return array{fields: array<string, string>, score: int}
     */
    public static function match(string $phrase, array $fields): array
    {
        $needle = self::normalize($phrase);
        $tokens = self::tokens($phrase);
        $out = [];
        $score = 0;
        foreach (self::WEIGHTS as $field => $weight) {
            $hay = ' '.self::normalize((string) ($fields[$field] ?? '')).' ';
            if ($needle !== '' && str_contains($hay, ' '.$needle.' ')) {
                $out[$field] = 'exact';
                $score += $weight;
            } elseif ($tokens && array_reduce($tokens, fn ($ok, $t) => $ok && str_contains($hay, ' '.$t.' '), true)) {
                $out[$field] = 'partial';
                $score += intdiv($weight, 2);
            } else {
                $out[$field] = 'lipsa';
            }
        }

        return ['fields' => $out, 'score' => $score];
    }
}
