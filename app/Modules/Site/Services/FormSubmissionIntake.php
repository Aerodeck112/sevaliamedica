<?php

namespace App\Modules\Site\Services;

use App\Core\Audit\Audit;
use App\Core\Contracts\AppointmentIntake;
use App\Core\Contracts\IntakeResult;
use App\Core\Contracts\PublicAppointmentRequest;
use App\Modules\Cms\Models\FormSubmission;
use Illuminate\Support\Str;

/**
 * Implementarea implicită a preluării solicitărilor: păstrează solicitarea criptat în „Mesaje din site”.
 * Nu rezervă niciun interval, deci nu confirmă niciodată o programare.
 */
class FormSubmissionIntake implements AppointmentIntake
{
    public function submit(PublicAppointmentRequest $request): IntakeResult
    {
        $reference = 'S'.now()->format('ymd').'-'.Str::upper(Str::random(5));
        $submission = FormSubmission::create([
            'form' => str_contains($request->sourceUrl, 'emsella') ? 'emsella' : 'contact',
            'payload' => [
                'nume' => $request->name,
                'telefon' => $request->phone,
                'email' => $request->email,
                'contact_preferat' => $request->preferredContact,
                'specialitate_id' => $request->specialtyId,
                'medic_id' => $request->staffMemberId,
                'mesaj' => $request->message,
                'data_nasterii' => $request->birthDate,
                'consimtamant_la' => $request->consentAt->toIso8601String(),
            ],
            'status' => 'noua',
            'reference' => $reference,
            'ip_hash' => $request->ipHash,
            'source_url' => mb_substr($request->sourceUrl, 0, 255),
        ]);
        Audit::log('site.form.received', $submission, ['formular' => $submission->form]);

        return new IntakeResult(true, false, 'Solicitarea a fost înregistrată. Vă vom contacta pentru stabilirea programării.', $reference);
    }
}
