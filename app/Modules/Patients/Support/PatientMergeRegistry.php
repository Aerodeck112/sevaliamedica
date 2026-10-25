<?php

namespace App\Modules\Patients\Support;

use App\Modules\Patients\Models\Patient;
use Illuminate\Support\Facades\DB;

class PatientMergeRegistry
{
    /** @var list<PatientMergeParticipant> */
    private array $participants = [];

    public function add(PatientMergeParticipant $participant): void
    {
        $this->participants[] = $participant;
    }

    /** @return list<PatientMergeParticipant> */
    public function all(): array
    {
        return $this->participants;
    }

    /**
     * Participant simplu pentru o tabelă cu coloană patient_id.
     */
    public static function table(string $label, string $table, string $column = 'patient_id'): PatientMergeParticipant
    {
        return new class($label, $table, $column) implements PatientMergeParticipant
        {
            public function __construct(private string $label, private string $table, private string $column) {}

            public function label(): string
            {
                return $this->label;
            }

            public function count(Patient $source): int
            {
                return DB::table($this->table)->where($this->column, $source->id)->count();
            }

            public function move(Patient $source, Patient $target): int
            {
                return DB::table($this->table)->where($this->column, $source->id)->update([$this->column => $target->id]);
            }
        };
    }
}
