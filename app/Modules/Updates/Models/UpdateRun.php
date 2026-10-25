<?php

namespace App\Modules\Updates\Models;

use App\Models\User;
use App\Modules\Backup\Models\Backup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O instalare de pachet. Starea autoritară este și în jurnalul JSON din shared/updates/<uuid>/journal.json,
 * care supraviețuiește unei restaurări a bazei de date și poate fi citit de recovery.php.
 */
class UpdateRun extends Model
{
    public const STEPS = [
        'extract' => 'Extragere în staging și verificarea sumelor de control',
        'preflight' => 'Verificări preliminare',
        'maintenance_on' => 'Mod mentenanță (scrieri și taskuri cron oprite)',
        'backup_db' => 'Backup bază de date',
        'verify_backup' => 'Verificarea backupului',
        'copy_release' => 'Copierea versiunii noi în releases/',
        'link_shared' => 'Legarea configurației și a datelor partajate',
        'migrate' => 'Migrări bază de date',
        'publish_assets' => 'Publicarea fișierelor statice în public_html',
        'activate' => 'Activarea versiunii noi (comutare atomică)',
        'health' => 'Verificări de funcționare',
        'maintenance_off' => 'Ieșire din mentenanță',
        'cleanup' => 'Curățare (staging, versiuni vechi)',
    ];

    public const STATUSES = [
        'prepared' => 'Pregătit',
        'running' => 'În curs',
        'failed' => 'Eșuat — necesită decizie',
        'completed' => 'Instalat',
        'rolled_back' => 'Revenit la versiunea anterioară',
        'cancelled' => 'Anulat',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'manifest_summary' => 'array',
            'steps' => 'array',
            'preflight' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function backup(): BelongsTo
    {
        return $this->belongsTo(Backup::class);
    }

    public function step(string $key): array
    {
        return $this->steps[$key] ?? ['status' => 'pending'];
    }

    public function stepDone(string $key): bool
    {
        return in_array($this->step($key)['status'] ?? null, ['done', 'skipped'], true);
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['prepared', 'running', 'failed'], true);
    }

    public function statusTone(): string
    {
        return match ($this->status) {
            'completed' => 'ok',
            'running', 'prepared' => 'info',
            'failed' => 'danger',
            'rolled_back' => 'warn',
            default => '',
        };
    }
}
