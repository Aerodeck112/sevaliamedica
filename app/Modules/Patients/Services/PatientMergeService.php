<?php

namespace App\Modules\Patients\Services;

use App\Core\Audit\Audit;
use App\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Models\PatientMerge;
use App\Modules\Patients\Support\PatientMergeRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Unirea a două fișe ale aceleiași persoane. Fișa sursă NU se șterge: primește merged_into_id, iar înregistrările
 * legate sunt mutate de fiecare modul participant. Operația e auditată și nu se face niciodată automat.
 */
class PatientMergeService
{
    public function __construct(private readonly PatientMergeRegistry $registry) {}

    /** @return list<array{label: string, count: int}> */
    public function preview(Patient $source): array
    {
        return array_map(fn ($p) => ['label' => $p->label(), 'count' => $p->count($source)], $this->registry->all());
    }

    public function merge(Patient $source, Patient $target, User $user, string $reason): PatientMerge
    {
        if ($source->is($target)) {
            throw ValidationException::withMessages(['target_id' => 'Alegeți o fișă diferită.']);
        }
        if ($source->merged_into_id || $target->merged_into_id) {
            throw ValidationException::withMessages(['target_id' => 'Una dintre fișe a fost deja unită în altă fișă.']);
        }
        if (mb_strlen(trim($reason)) < 10) {
            throw ValidationException::withMessages(['reason' => 'Motivul unirii este obligatoriu (minimum 10 caractere).']);
        }

        return DB::transaction(function () use ($source, $target, $user, $reason) {
            // Blocăm ambele fișe în ordinea ID-urilor, ca două uniri simultane să nu se încrucișeze.
            $ids = [$source->id, $target->id];
            sort($ids);
            Patient::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();

            $summary = [];
            foreach ($this->registry->all() as $participant) {
                $summary[$participant->label()] = $participant->move($source, $target);
            }

            // Identificatorii unici trec pe fișa țintă dacă aceasta nu îi are.
            $fresh = Patient::findOrFail($source->id);
            $targetFresh = Patient::findOrFail($target->id);
            if ($fresh->cnp && $targetFresh->cnp && $fresh->cnp !== $targetFresh->cnp) {
                throw ValidationException::withMessages(['target_id' => 'Fișele au CNP-uri diferite: nu pot aparține aceleiași persoane.']);
            }
            $moveCnp = $fresh->cnp && ! $targetFresh->cnp;
            $cnp = $fresh->cnp;
            $fresh->forceFill(['cnp' => null, 'cnp_index' => null, 'merged_into_id' => $target->id, 'merged_at' => now(), 'updated_by' => $user->id])->save();
            if ($moveCnp) {
                $targetFresh->forceFill(['cnp' => $cnp])->save();
                $summary['CNP preluat'] = 1;
            }

            $merge = PatientMerge::create([
                'source_patient_id' => $source->id,
                'target_patient_id' => $target->id,
                'user_id' => $user->id,
                'reason' => trim($reason),
                'summary' => $summary,
            ]);
            Audit::log('patients.merged', $merge, ['sursa' => $source->id, 'tinta' => $target->id, 'mutate' => $summary], $target->id);
            Audit::log('patients.merged_source', $fresh, ['tinta' => $target->id], $source->id);

            return $merge;
        });
    }
}
