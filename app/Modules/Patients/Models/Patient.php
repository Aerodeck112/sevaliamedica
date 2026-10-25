<?php

namespace App\Modules\Patients\Models;

use App\Core\Security\BlindIndex;
use App\Modules\Patients\Support\PatientNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fișa administrativă a pacientului. Datele medicale stau în modulele Medical/Rehab și cer relație de îngrijire.
 *
 * CNP-ul și numărul documentului străin sunt criptate; căutarea și detectarea duplicatelor folosesc indexul orb.
 * O fișă unită în alta are merged_into_id și nu mai apare în liste.
 */
class Patient extends Model
{
    use HasFactory;

    public const SEXES = ['F' => 'Feminin', 'M' => 'Masculin'];

    protected $guarded = ['id', 'cnp_index', 'foreign_id_index', 'search_name', 'phone_normalized', 'merged_into_id', 'merged_at'];

    protected $hidden = ['cnp', 'cnp_index', 'foreign_id_number', 'foreign_id_index'];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'cnp' => 'encrypted',
            'foreign_id_number' => 'encrypted',
            'allow_phone' => 'boolean',
            'allow_sms' => 'boolean',
            'allow_email' => 'boolean',
            'allow_whatsapp' => 'boolean',
            'marketing_consent' => 'boolean',
            'marketing_consent_at' => 'datetime',
            'merged_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Patient $p) {
            $p->search_name = PatientNormalizer::name($p->last_name.' '.$p->first_name);
            $p->phone_normalized = PatientNormalizer::phone($p->phone);
            if ($p->isDirty('cnp')) {
                $p->cnp_index = BlindIndex::make(PatientNormalizer::cnp($p->cnp), 'cnp');
            }
            if ($p->isDirty('foreign_id_number')) {
                $p->foreign_id_index = BlindIndex::make($p->foreign_id_number, 'foreign_id');
            }
        });
    }

    protected static function newFactory()
    {
        return \Database\Factories\PatientFactory::new();
    }

    public function representatives(): HasMany
    {
        return $this->hasMany(PatientRepresentative::class);
    }

    public function careRelations(): HasMany
    {
        return $this->hasMany(CareRelation::class);
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('merged_into_id');
    }

    /** Căutare după nume (fără diacritice), telefon, CNP exact sau ID. */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);
        if ($term === '') {
            return $query;
        }
        $digits = preg_replace('/\D/', '', $term);

        return $query->where(function (Builder $w) use ($term, $digits) {
            if (strlen($digits) === 13 && $digits === preg_replace('/\s/', '', $term)) {
                $w->orWhere('cnp_index', BlindIndex::make($digits, 'cnp'));
            }
            if (strlen($digits) >= 6) {
                $w->orWhere('phone_normalized', 'like', '%'.substr(PatientNormalizer::phone($digits) ?? $digits, -9).'%');
            }
            if (ctype_digit($term) && strlen($term) < 9) {
                $w->orWhere('id', (int) $term);
            }
            foreach (preg_split('/\s+/', PatientNormalizer::name($term)) as $part) {
                if ($part !== '') {
                    $w->orWhere(fn (Builder $n) => $n->where('search_name', 'like', '%'.addcslashes($part, '%_').'%'));
                }
            }
        })->orderByRaw('search_name like ? desc', [PatientNormalizer::name($term).'%'])->orderBy('search_name');
    }

    public function fullName(): string
    {
        return trim($this->last_name.' '.$this->first_name);
    }

    public function maskedCnp(): string
    {
        return BlindIndex::mask($this->cnp, 1, 3);
    }

    public function age(): ?int
    {
        return $this->birth_date?->age;
    }

    public function isMinor(): bool
    {
        return $this->birth_date !== null && $this->birth_date->age < 18;
    }

    /** Canalele operaționale permise (confirmări, remindere), separat de marketing. @return list<string> */
    public function operationalChannels(): array
    {
        return array_keys(array_filter([
            'telefon' => $this->allow_phone,
            'sms' => $this->allow_sms && $this->phone,
            'email' => $this->allow_email && $this->email,
            'whatsapp' => $this->allow_whatsapp && $this->phone,
        ]));
    }
}
