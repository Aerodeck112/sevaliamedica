<?php

namespace App\Core\Support;

/**
 * Fișierul public_html/.htaccess: fără regulile SEVALIA, serverul nu trimite adresele către aplicație (orice pagină
 * în afară de prima ar da 404) și nu blochează fișierele sensibile.
 *
 * cPanel scrie singur blocuri în același fișier (versiunea PHP din MultiPHP Manager, setările din MultiPHP INI
 * Editor); acestea, ca și regulile proprii ale clinicii puse între „# BEGIN reguli proprii” și „# END reguli proprii”,
 * se păstrează. Restul fișierului este înlocuit cu șablonul versiunii, iar fișierul anterior se salvează alături.
 */
final class Htaccess
{
    public const MARKER = 'SEVALIA-MANAGED';

    /** Blocurile scrise de cPanel, păstrate întocmai. */
    private const CPANEL = [
        '/^# php -- BEGIN cPanel-generated handler.*?^# php -- END cPanel-generated handler[^\n]*\n?/ms',
        '/^# BEGIN cPanel-generated php ini directives.*?^# END cPanel-generated php ini directives[^\n]*\n?/ms',
    ];

    /** Secțiunea pentru regulile proprii ale clinicii. */
    private const CUSTOM = '/^# BEGIN reguli proprii.*?^# END reguli proprii[^\n]*\n?/ms';

    /**
     * Conținutul nou: regulile proprii (primele, ca să ruleze înaintea regulii care trimite totul la aplicație),
     * apoi șablonul SEVALIA, apoi blocurile scrise de cPanel.
     */
    public static function merge(string $template, ?string $existing): string
    {
        $custom = self::first(self::CUSTOM, (string) $existing) ?? self::first(self::CUSTOM, $template);
        $cpanel = [];
        foreach (self::CPANEL as $re) {
            if ($existing !== null && preg_match_all($re, $existing, $m)) {
                foreach ($m[0] as $block) {
                    $cpanel[] = rtrim($block)."\n";
                }
            }
        }
        $body = rtrim(self::without([...self::CPANEL, self::CUSTOM], $template))."\n";
        // Antetul cu marcajul rămâne primul rând al fișierului.
        $header = '';
        if (preg_match('/\A(?:#[^\n]*\n)+/', $body, $m)) {
            $header = $m[0];
            $body = ltrim(substr($body, strlen($header)), "\n");
        }

        return $header.($custom !== null ? "\n".rtrim($custom)."\n" : '')."\n".$body.($cpanel ? "\n".implode("\n", $cpanel) : '');
    }

    /**
     * Scrie public_html/.htaccess dacă diferă de rezultatul îmbinării. Fișierul anterior, dacă nu era administrat de
     * SEVALIA, se păstrează ca .htaccess.inainte-de-sevalia-<dată> (fișierele .ht* nu sunt servite de Apache).
     *
     * @return bool true dacă fișierul a fost (re)scris
     */
    public static function write(string $publicPath, string $template): bool
    {
        $target = rtrim($publicPath, '/').'/.htaccess';
        $existing = is_file($target) ? (string) file_get_contents($target) : null;
        $merged = self::merge($template, $existing);
        if ($existing === $merged) {
            return false;
        }
        if ($existing !== null && ! str_contains($existing, self::MARKER)) {
            @copy($target, $target.'.inainte-de-sevalia-'.date('Ymd-His'));
        }
        $tmp = $target.'.tmp-'.bin2hex(random_bytes(3));
        if (file_put_contents($tmp, $merged) === false || ! rename($tmp, $target)) {
            @unlink($tmp);

            return false;
        }
        @chmod($target, 0644);

        return true;
    }

    /** Fișierul e cel administrat de SEVALIA (are marcajul). */
    public static function isManaged(string $publicPath): bool
    {
        $target = rtrim($publicPath, '/').'/.htaccess';

        return is_file($target) && str_contains((string) file_get_contents($target), self::MARKER);
    }

    private static function first(string $re, string $text): ?string
    {
        return preg_match($re, $text, $m) ? $m[0] : null;
    }

    private static function without(array $patterns, string $text): string
    {
        foreach ($patterns as $re) {
            $text = (string) preg_replace($re, '', $text);
        }

        return $text;
    }
}
