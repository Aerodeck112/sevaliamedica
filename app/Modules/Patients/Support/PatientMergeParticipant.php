<?php

namespace App\Modules\Patients\Support;

use App\Modules\Patients\Models\Patient;

/**
 * Un modul care deține înregistrări legate de pacient și le mută la unirea fișelor.
 * Se înregistrează în PatientMergeRegistry din boot(). move() rulează în tranzacția unirii.
 */
interface PatientMergeParticipant
{
    /** Ce mută, pe scurt (ex. „Programări”). */
    public function label(): string;

    /** Câte înregistrări ale fișei sursă ar fi mutate (pentru previzualizare). */
    public function count(Patient $source): int;

    /** Mută înregistrările de la sursă la țintă; întoarce numărul mutat. */
    public function move(Patient $source, Patient $target): int;
}
