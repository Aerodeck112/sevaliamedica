<?php

namespace App\Modules\Assistant\Services;

/**
 * Normalizarea textului pentru căutare: fără diacritice și majuscule, fără cuvinte de legătură, cu o rădăcină
 * simplă (primele 6 litere ale cuvintelor lungi), ca „kinetoterapia” să găsească „kinetoterapie”.
 */
final class Text
{
    /** Cuvinte de legătură și formule de politețe, deja normalizate (fără diacritice). */
    private const STOPWORDS = ['si', 'sau', 'de', 'la', 'in', 'pe', 'cu', 'din', 'pentru', 'a', 'al', 'ale', 'ai', 'un', 'o', 'unei', 'unui',
        'este', 'e', 'sunt', 'ce', 'care', 'cum', 'cat', 'cand', 'unde', 'mai', 'nu', 'da', 'ma', 'mi', 'imi', 'va', 'eu', 'voi',
        'se', 'sa', 'as', 'aveti', 'puteti', 'pot', 'fi', 'fost', 'ca', 'cel', 'cea', 'cei', 'cele', 'acest', 'aceasta', 'buna', 'ziua',
        'rog', 'multumesc', 'salut', 'vreau', 'doresc', 'dori', 'avea', 'ati', 'face', 'faceti', 'exista', 'despre', 'ceva'];

    public static function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        $text = strtr($text, ['ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't', 'ă' => 'a', 'â' => 'a', 'î' => 'i']);
        $text = preg_replace('/\btg\.?\s*(?=mures)/u', 'targu ', $text);
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);

        return trim(preg_replace('/\s+/', ' ', $text));
    }

    /** @return list<string> */
    public static function tokens(string $text): array
    {
        $out = [];
        foreach (explode(' ', self::normalize($text)) as $word) {
            if (mb_strlen($word) < 2 || in_array($word, self::STOPWORDS, true)) {
                continue;
            }
            $out[] = mb_strlen($word) > 6 ? mb_substr($word, 0, 6) : $word;
        }

        return $out;
    }

    public static function has(string $normalizedText, array $words): bool
    {
        foreach ($words as $w) {
            if (preg_match('/\b'.preg_quote($w, '/').'/u', $normalizedText)) {
                return true;
            }
        }

        return false;
    }
}
