<?php

namespace App\Modules\Patients\Support;

use Illuminate\Support\Str;

/** Normalizări folosite la căutare și la detectarea duplicatelor. */
final class PatientNormalizer
{
    public static function name(?string $name): string
    {
        $name = Str::lower(Str::ascii((string) $name));
        $name = preg_replace('/[^a-z0-9 ]+/', ' ', $name);

        return trim(preg_replace('/\s+/', ' ', $name));
    }

    /** Telefon în format național fără separatori (ex. 0741558558); null dacă nu are cifre suficiente. */
    public static function phone(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);
        if (str_starts_with($digits, '0040')) {
            $digits = '0'.substr($digits, 4);
        } elseif (str_starts_with($digits, '40') && strlen($digits) === 11) {
            $digits = '0'.substr($digits, 2);
        }

        return strlen($digits) >= 6 ? $digits : null;
    }

    public static function cnp(?string $cnp): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $cnp);

        return $digits === '' ? null : $digits;
    }

    /**
     * Data nașterii și sexul deduse din CNP (pentru precompletare); null dacă nu se pot deduce.
     *
     * @return array{birth_date: string, sex: string}|null
     */
    public static function fromCnp(string $cnp): ?array
    {
        if (! self::cnpIsValid($cnp)) {
            return null;
        }
        $s = (int) $cnp[0];
        $century = match ($s) {
            1, 2 => 1900, 3, 4 => 1800, 5, 6 => 2000, default => null,
        };
        if ($century === null) {
            return null; // 7, 8, 9: rezidenți/străini — secolul nu se deduce sigur
        }
        $y = $century + (int) substr($cnp, 1, 2);
        $m = (int) substr($cnp, 3, 2);
        $d = (int) substr($cnp, 5, 2);
        if (! checkdate($m, $d, $y)) {
            return null;
        }

        return ['birth_date' => sprintf('%04d-%02d-%02d', $y, $m, $d), 'sex' => $s % 2 === 1 ? 'M' : 'F'];
    }

    /**
     * Verifică structura CNP (13 cifre, cifră de control). Nu dovedește că CNP-ul aparține persoanei.
     */
    public static function cnpIsValid(string $cnp): bool
    {
        if (! preg_match('/^[1-9]\d{12}$/', $cnp)) {
            return false;
        }
        $key = '279146358279';
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $cnp[$i] * (int) $key[$i];
        }
        $control = $sum % 11;
        $control = $control === 10 ? 1 : $control;

        return $control === (int) $cnp[12];
    }
}
