<?php

namespace App\Modules\Cms\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Trimitere din formularele publice, păstrată criptat. Folosită când modulul de programări nu este activ
 * (implementarea implicită a AppointmentIntake) și pentru formularele care nu sunt solicitări de programare.
 */
class FormSubmission extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'handled_at' => 'datetime'];
    }
}
