<?php

namespace App\Modules\Medical\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Versiune publicată a unui șablon. Imuabilă: fișele existente se bazează pe ea. */
class MedicalTemplateVersion extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['schema' => 'array', 'published_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('O versiune publicată de șablon nu se modifică; publicați o versiune nouă.'));
        static::deleting(fn () => throw new LogicException('O versiune publicată de șablon nu se șterge.'));
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(MedicalTemplate::class, 'medical_template_id');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
