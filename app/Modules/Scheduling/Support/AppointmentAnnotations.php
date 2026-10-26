<?php

namespace App\Modules\Scheduling\Support;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Punct de extensie: alte module adaugă semnale scurte pe cardurile din calendar și în detaliile programării
 * (ex. Medical — „Avertizare clinică”). Fiecare sursă decide singură ce poate vedea utilizatorul.
 */
class AppointmentAnnotations
{
    /** @var list<AppointmentAnnotator> */
    private array $annotators = [];

    public function add(AppointmentAnnotator $annotator): void
    {
        $this->annotators[] = $annotator;
    }

    /**
     * @param  Collection<int, \App\Modules\Scheduling\Models\Appointment>  $appointments
     * @return array<int, list<array{label: string, tone: ?string, detail: ?string}>>  pe id-ul programării
     */
    public function for(Collection $appointments, User $user): array
    {
        $out = [];
        foreach ($this->annotators as $annotator) {
            foreach ($annotator->annotate($appointments, $user) as $id => $items) {
                $out[$id] = array_merge($out[$id] ?? [], $items);
            }
        }

        return $out;
    }
}
