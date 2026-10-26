<?php

namespace App\Modules\Medical\Support;

use App\Models\User;
use App\Modules\Medical\Models\ClinicalAlert;
use App\Modules\Patients\Services\CareAccess;
use App\Modules\Scheduling\Support\AppointmentAnnotator;
use Illuminate\Support\Collection;

/**
 * Avertizările clinice active apar pe cardurile din calendar și în detaliile programării — DOAR pentru
 * utilizatorii cu medical.view și relație de îngrijire cu pacientul. Recepția nu vede nici existența lor
 * (este informație medicală).
 */
class AlertAnnotations implements AppointmentAnnotator
{
    public function annotate(Collection $appointments, User $user): array
    {
        if (! $user->can('medical.view')) {
            return [];
        }
        $patientIds = $appointments->pluck('patient_id')->filter()->unique()->values();
        if ($patientIds->isEmpty()) {
            return [];
        }
        $alerts = ClinicalAlert::active()->whereIn('patient_id', $patientIds)->get()->groupBy('patient_id');
        if ($alerts->isEmpty()) {
            return [];
        }

        $care = app(CareAccess::class);
        $allowed = [];
        $out = [];
        foreach ($appointments as $appointment) {
            $pid = $appointment->patient_id;
            if (! $pid || ! isset($alerts[$pid])) {
                continue;
            }
            $allowed[$pid] ??= $appointment->patient && $care->canAccessMedical($user, $appointment->patient);
            if (! $allowed[$pid]) {
                continue;
            }
            $list = $alerts[$pid];
            $worst = $list->contains('severity', 'critic') ? 'critic' : ($list->contains('severity', 'atentie') ? 'atentie' : 'info');
            $out[$appointment->id][] = [
                'label' => $list->count() === 1 ? 'Avertizare clinică' : $list->count().' avertizări clinice',
                'tone' => ClinicalAlert::TONES[$worst],
                'detail' => $list->map(fn (ClinicalAlert $a) => $a->severityLabel().': '.$a->text)->join(' · '),
            ];
        }

        return $out;
    }
}
