<?php

namespace App\Modules\Medical\Models;

use App\Models\User;
use App\Modules\Patients\Models\Patient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Document încărcat (PDF / imagine). Fișierul e criptat pe discul privat; se servește doar prin controller
 * autorizat. Nu se șterge: se poate „retrage” cu motiv (rămâne în istoric pentru cei autorizați).
 */
class MedicalDocument extends Model
{
    public const CATEGORIES = [
        'rezultat' => 'Rezultat investigație',
        'scrisoare' => 'Scrisoare medicală',
        'bilet' => 'Bilet de trimitere',
        'consimtamant' => 'Consimțământ semnat',
        'imagistica' => 'Imagistică',
        'altul' => 'Alt document',
    ];

    protected $guarded = ['id'];

    protected $hidden = ['storage_path'];

    protected function casts(): array
    {
        return ['retracted_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Documentele medicale nu se șterg; se pot retrage cu motiv.'));
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class, 'medical_record_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', 'activ');
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }

    public function humanSize(): string
    {
        return $this->size >= 1048576 ? number_format($this->size / 1048576, 1, ',', '.').' MB' : max(1, (int) round($this->size / 1024)).' KB';
    }
}
