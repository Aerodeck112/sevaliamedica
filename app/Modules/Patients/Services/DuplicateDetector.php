<?php

namespace App\Modules\Patients\Services;

use App\Core\Security\BlindIndex;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Support\PatientNormalizer;
use Illuminate\Support\Collection;

/**
 * Detectarea duplicatelor fără fuziuni automate:
 *  - CNP identic (sau document străin identic) → blocare, cu legătură către fișa existentă;
 *  - nume + data nașterii identice sau telefon identic → avertizare; utilizatorul confirmă explicit.
 */
class DuplicateDetector
{
    /**
     * @return array{blocking: Collection<int, Patient>, warnings: Collection<int, array{patient: Patient, reason: string}>}
     */
    public function check(array $data, ?int $ignoreId = null): array
    {
        $base = fn () => Patient::query()->active()->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId));

        $blocking = collect();
        $cnp = PatientNormalizer::cnp($data['cnp'] ?? null);
        if ($cnp) {
            $blocking = $blocking->merge($base()->where('cnp_index', BlindIndex::make($cnp, 'cnp'))->get());
        }
        if (! empty($data['foreign_id_number'])) {
            $blocking = $blocking->merge($base()->where('foreign_id_index', BlindIndex::make($data['foreign_id_number'], 'foreign_id'))->get());
        }

        $warnings = collect();
        $name = PatientNormalizer::name(($data['last_name'] ?? '').' '.($data['first_name'] ?? ''));
        if ($name !== '' && ! empty($data['birth_date'])) {
            foreach ($base()->where('search_name', $name)->whereDate('birth_date', $data['birth_date'])->limit(5)->get() as $p) {
                $warnings->push(['patient' => $p, 'reason' => 'același nume și aceeași dată a nașterii']);
            }
        }
        $phone = PatientNormalizer::phone($data['phone'] ?? null);
        if ($phone) {
            foreach ($base()->where('phone_normalized', $phone)->limit(5)->get() as $p) {
                $warnings->push(['patient' => $p, 'reason' => 'același număr de telefon']);
            }
        }

        $blockingIds = $blocking->pluck('id')->all();

        return [
            'blocking' => $blocking->unique('id')->values(),
            'warnings' => $warnings->reject(fn ($w) => in_array($w['patient']->id, $blockingIds, true))->unique(fn ($w) => $w['patient']->id)->values(),
        ];
    }
}
