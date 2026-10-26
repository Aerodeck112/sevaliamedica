<?php

namespace App\Modules\Notifications\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Șablon versionat: o modificare creează o versiune nouă; cea activă e ultima cu is_active = true.
 * Livrările păstrează template_id, deci se vede exact ce versiune a fost folosită.
 */
class NotificationTemplate extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'provider_params' => 'array'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function active(string $type, string $channel): ?self
    {
        return self::where('type', $type)->where('channel', $channel)->where('is_active', true)->orderByDesc('version')->first();
    }
}
