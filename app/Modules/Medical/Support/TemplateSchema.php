<?php

namespace App\Modules\Medical\Support;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Structura unui șablon de fișă: secțiuni cu câmpuri. Aplicația nu conține câmpuri sau protocoale medicale
 * predefinite: structura o definesc medicii clinicii din panou.
 *
 *   {"sections":[{"key":"anamneza","title":"Anamneză","fields":[
 *       {"key":"istoric","label":"Istoric","type":"textarea","required":false,"help":null},
 *       {"key":"greutate","label":"Greutate","type":"number","unit":"kg"},
 *       {"key":"lateralitate","label":"Partea","type":"select","options":["stânga","dreapta"]},
 *       {"key":"durere","label":"Intensitate","type":"scale","min":0,"max":10},
 *       {"key":"masuratori","label":"Măsurători","type":"table","columns":["Segment","Valoare"],"rows":3}
 *   ]}]}
 */
final class TemplateSchema
{
    public const TYPES = [
        'text' => 'Text scurt',
        'textarea' => 'Text lung (liber)',
        'number' => 'Număr (cu unitate)',
        'date' => 'Dată',
        'select' => 'Listă de opțiuni',
        'checkbox' => 'Bifă (da/nu)',
        'scale' => 'Scală numerică',
        'table' => 'Tabel simplu',
    ];

    private const MAX_SECTIONS = 30;

    private const MAX_FIELDS = 80;

    /**
     * Normalizează și validează o structură venită din editor. Aruncă ValidationException cu mesaje în română.
     */
    public static function normalize(array $input): array
    {
        $sections = [];
        $keys = [];
        $errors = [];
        $fieldCount = 0;

        foreach (array_values($input['sections'] ?? []) as $si => $section) {
            $title = trim((string) ($section['title'] ?? ''));
            if ($title === '') {
                $errors["sections.$si.title"] = 'Fiecare secțiune are nevoie de un titlu.';

                continue;
            }
            $fields = [];
            foreach (array_values($section['fields'] ?? []) as $fi => $field) {
                $label = trim((string) ($field['label'] ?? ''));
                if ($label === '') {
                    continue; // rânduri goale din editor
                }
                $type = (string) ($field['type'] ?? 'text');
                if (! array_key_exists($type, self::TYPES)) {
                    $errors["sections.$si.fields.$fi.type"] = "Tipul câmpului „{$label}” nu este valid.";

                    continue;
                }
                $key = Str::slug((string) ($field['key'] ?? '') ?: $label, '_') ?: 'camp';
                $base = $key;
                $n = 2;
                while (isset($keys[$key])) {
                    $key = $base.'_'.$n++;
                }
                $keys[$key] = true;

                $normalized = [
                    'key' => Str::limit($key, 60, ''),
                    'label' => Str::limit($label, 190, ''),
                    'type' => $type,
                    'required' => filter_var($field['required'] ?? false, FILTER_VALIDATE_BOOL),
                    'help' => self::nullableString($field['help'] ?? null, 300),
                ];
                if ($type === 'number') {
                    $normalized['unit'] = self::nullableString($field['unit'] ?? null, 20);
                }
                if ($type === 'select') {
                    $options = self::lines($field['options'] ?? []);
                    if (count($options) < 2) {
                        $errors["sections.$si.fields.$fi.options"] = "Lista „{$label}” are nevoie de cel puțin două opțiuni (câte una pe rând).";
                    }
                    $normalized['options'] = $options;
                }
                if ($type === 'scale') {
                    $min = (int) ($field['min'] ?? 0);
                    $max = (int) ($field['max'] ?? 10);
                    if ($max <= $min || $max - $min > 100) {
                        $errors["sections.$si.fields.$fi.max"] = "Scala „{$label}” trebuie să aibă maximul mai mare decât minimul (cel mult 100 de trepte).";
                    }
                    $normalized['min'] = $min;
                    $normalized['max'] = $max;
                }
                if ($type === 'table') {
                    $columns = self::lines($field['columns'] ?? []);
                    if (! $columns || count($columns) > 8) {
                        $errors["sections.$si.fields.$fi.columns"] = "Tabelul „{$label}” are nevoie de 1–8 coloane (câte una pe rând).";
                    }
                    $normalized['columns'] = $columns;
                    $normalized['rows'] = max(1, min(30, (int) ($field['rows'] ?? 3)));
                }
                $fields[] = $normalized;
                $fieldCount++;
            }
            $sections[] = [
                'key' => 's'.($si + 1),
                'title' => Str::limit($title, 190, ''),
                'fields' => $fields,
            ];
        }

        if (! $sections) {
            $errors['sections'] = 'Șablonul are nevoie de cel puțin o secțiune.';
        } elseif ($fieldCount === 0) {
            $errors['sections'] = 'Șablonul are nevoie de cel puțin un câmp.';
        }
        if (count($sections) > self::MAX_SECTIONS || $fieldCount > self::MAX_FIELDS) {
            $errors['sections'] = 'Șablonul este prea mare (maximum '.self::MAX_SECTIONS.' secțiuni și '.self::MAX_FIELDS.' câmpuri).';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return ['sections' => $sections];
    }

    /** @return list<array> toate câmpurile, în ordine */
    public static function fields(array $schema): array
    {
        $out = [];
        foreach ($schema['sections'] ?? [] as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                $out[] = $field;
            }
        }

        return $out;
    }

    /**
     * Curăță valorile primite din formular conform structurii. Cu $strict, câmpurile obligatorii goale
     * produc erori (la finalizare); pentru draft se acceptă valori incomplete.
     *
     * @return array<string, mixed>
     */
    public static function values(array $schema, array $input, bool $strict): array
    {
        $values = [];
        $errors = [];
        foreach (self::fields($schema) as $field) {
            $key = $field['key'];
            $raw = $input[$key] ?? null;
            $value = match ($field['type']) {
                'text' => self::nullableString($raw, 500),
                'textarea' => self::nullableString($raw, 20000, multiline: true),
                'number' => self::number($raw, $field, $errors),
                'date' => self::date($raw, $field, $errors),
                'select' => in_array($raw, $field['options'] ?? [], true) ? $raw : null,
                'checkbox' => filter_var($raw, FILTER_VALIDATE_BOOL),
                'scale' => self::scale($raw, $field, $errors),
                'table' => self::table($raw, $field),
                default => null,
            };
            if ($strict && ($field['required'] ?? false) && self::isEmpty($value, $field['type'])) {
                $errors["values.$key"] = "Câmpul „{$field['label']}” este obligatoriu pentru finalizare.";
            }
            $values[$key] = $value;
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $values;
    }

    /** Lista câmpurilor obligatorii necompletate (pentru afișarea înainte de finalizare). */
    public static function missingRequired(array $schema, array $values): array
    {
        $missing = [];
        foreach (self::fields($schema) as $field) {
            if (($field['required'] ?? false) && self::isEmpty($values[$field['key']] ?? null, $field['type'])) {
                $missing[] = $field['label'];
            }
        }

        return $missing;
    }

    private static function isEmpty(mixed $value, string $type): bool
    {
        return match ($type) {
            'checkbox' => false,
            'table' => ! collect($value ?? [])->flatten()->filter(fn ($v) => $v !== null && $v !== '')->count(),
            default => $value === null || $value === '',
        };
    }

    private static function nullableString(mixed $value, int $max, bool $multiline = false): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = (string) $value;
        // Fără caractere de control (în afară de rânduri noi la text lung).
        $value = $multiline ? preg_replace('/[^\P{C}\n\t]/u', '', str_replace("\r\n", "\n", $value)) : preg_replace('/\p{C}/u', '', $value);
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private static function lines(mixed $value): array
    {
        $items = is_array($value) ? $value : preg_split('/\r?\n/', (string) $value);

        return array_values(array_unique(array_filter(array_map(fn ($v) => Str::limit(trim((string) $v), 120, ''), $items), fn ($v) => $v !== '')));
    }

    private static function number(mixed $raw, array $field, array &$errors): ?float
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $normalized = str_replace(',', '.', (string) $raw);
        if (! is_numeric($normalized)) {
            $errors['values.'.$field['key']] = "„{$field['label']}” trebuie să fie un număr.";

            return null;
        }

        return (float) $normalized;
    }

    private static function date(mixed $raw, array $field, array &$errors): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $raw);
        if (! $d || $d->format('Y-m-d') !== (string) $raw) {
            $errors['values.'.$field['key']] = "„{$field['label']}” trebuie să fie o dată validă.";

            return null;
        }

        return (string) $raw;
    }

    private static function scale(mixed $raw, array $field, array &$errors): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (filter_var($raw, FILTER_VALIDATE_INT) === false || (int) $raw < $field['min'] || (int) $raw > $field['max']) {
            $errors['values.'.$field['key']] = "„{$field['label']}” trebuie să fie între {$field['min']} și {$field['max']}.";

            return null;
        }

        return (int) $raw;
    }

    private static function table(mixed $raw, array $field): array
    {
        $rows = [];
        foreach (array_slice(is_array($raw) ? array_values($raw) : [], 0, $field['rows']) as $row) {
            $cells = [];
            foreach (array_keys($field['columns']) as $ci) {
                $cells[] = self::nullableString(is_array($row) ? ($row[$ci] ?? null) : null, 300);
            }
            $rows[] = $cells;
        }

        return $rows;
    }
}
