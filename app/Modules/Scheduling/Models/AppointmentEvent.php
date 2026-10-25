<?php

namespace App\Modules\Scheduling\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Istoricul unei programări. Doar adăugare. */
class AppointmentEvent extends Model
{
    public const UPDATED_AT = null;

    public const TYPES = [
        'creata' => 'Creată',
        'status' => 'Status schimbat',
        'mutata' => 'Mutată',
        'resurse' => 'Resurse modificate',
        'nota' => 'Notă',
        'reprogramare_ceruta' => 'Pacientul a cerut reprogramare',
        'anulata_pacient' => 'Anulată de pacient (link)',
        'pacient_identificat' => 'Pacient identificat',
        'link_emis' => 'Link pentru pacient emis',
        'oferta_lista_asteptare' => 'Interval oferit din lista de așteptare',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['changes' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Istoricul programării nu poate fi modificat.'));
        static::deleting(fn () => throw new LogicException('Istoricul programării nu poate fi șters.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
