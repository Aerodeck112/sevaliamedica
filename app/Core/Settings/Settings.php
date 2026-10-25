<?php

namespace App\Core\Settings;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Setări cheie/valoare (JSON), păstrate în tabela `settings`. Modificările se auditează de apelant.
 */
class Settings
{
    private const CACHE_KEY = 'sevalia.settings';

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => $key],
            ['value' => json_encode($value, JSON_UNESCAPED_UNICODE), 'updated_at' => now()],
        );
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return Cache::remember(self::CACHE_KEY, 300, fn () => DB::table('settings')
            ->pluck('value', 'key')
            ->map(fn ($v) => json_decode($v, true))
            ->all());
    }
}
