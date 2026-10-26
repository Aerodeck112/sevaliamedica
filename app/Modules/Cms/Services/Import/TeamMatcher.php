<?php

namespace App\Modules\Cms\Services\Import;

use App\Modules\Clinic\Models\StaffMember;
use Illuminate\Support\Str;

/**
 * Potrivește un nume afișat pe site (ex. „dr. Szekely Varga Margit”) cu fișa din Clinică,
 * ignorând titlul, diacriticele, cratimele și ordinea numelor.
 */
final class TeamMatcher
{
    public static function key(string $name): string
    {
        $name = Str::lower(Str::ascii(str_replace("\u{00A0}", ' ', $name)));
        $name = preg_replace('/\bdr\.?\s*/', ' ', $name);
        $tokens = preg_split('/[\s\-]+/', trim(preg_replace('/[^a-z\s\-]/', '', $name))) ?: [];
        $tokens = array_values(array_filter($tokens));
        sort($tokens);

        return implode(' ', $tokens);
    }

    public static function find(string $name): ?StaffMember
    {
        $key = self::key($name);
        if ($key === '') {
            return null;
        }
        foreach (StaffMember::all() as $staff) {
            if (self::key($staff->display_name) === $key) {
                return $staff;
            }
        }

        return null;
    }
}
