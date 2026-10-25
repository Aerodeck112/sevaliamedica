<?php

namespace App\Modules\Scheduling\Models;

use App\Modules\Clinic\Models\Location;
use App\Modules\Clinic\Models\Service;
use App\Modules\Clinic\Models\StaffMember;
use App\Modules\Patients\Models\Patient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WaitlistEntry extends Model
{
    public const STATUSES = ['activ' => 'Activ', 'oferit' => 'Interval oferit', 'programat' => 'Programat', 'expirat' => 'Expirat', 'anulat' => 'Anulat'];

    public const PRIORITIES = [1 => 'Urgent', 2 => 'Normal', 3 => 'Flexibil'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['preferences' => 'array', 'valid_until' => 'date'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function staffMember(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(WaitlistOffer::class)->latest('id');
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', ['activ', 'oferit'])
            ->where(fn ($w) => $w->whereNull('valid_until')->orWhere('valid_until', '>=', now()->toDateString()));
    }

    public function preferenceSummary(): string
    {
        $p = $this->preferences ?? [];
        $days = collect($p['weekdays'] ?? [])->map(fn ($d) => StaffSchedule::WEEKDAYS[$d] ?? $d)->join(', ');
        $hours = ! empty($p['from']) || ! empty($p['to']) ? ($p['from'] ?? '…').'–'.($p['to'] ?? '…') : '';

        return trim(($days ?: 'orice zi').($hours ? ', '.$hours : ''));
    }
}
