<?php

namespace App\Modules\Scheduling\Listeners;

use App\Core\Mail\RequestAlert;
use App\Modules\Scheduling\Events\AppointmentRequestReceived;
use Illuminate\Support\Carbon;

/** Solicitarea nouă de pe site pleacă prin e-mail și la clinică (adresele din Reguli programări). */
class SendRequestAlert
{
    public function __construct(private readonly RequestAlert $alert) {}

    public function handle(AppointmentRequestReceived $e): void
    {
        $a = $e->appointment->loadMissing(['service', 'staffMember', 'location', 'requestSpecialty']);
        $c = (array) $a->request_contact;
        $contact = ['telefon' => 'telefon', 'email' => 'e-mail', 'sms' => 'SMS'];
        $wanted = $a->starts_at ?? $a->request_preferred_start;

        $this->alert->send(
            (string) $a->request_reference,
            $e->autoConfirmed
                ? 'programare confirmată automat pentru '.$a->starts_at?->timezone(config('app.timezone'))->format('d.m.Y H:i')
                : 'în așteptarea recepției (pacientul trebuie contactat)',
            [
                'Nume' => $c['name'] ?? null,
                'Telefon' => $c['phone'] ?? null,
                'E-mail' => $c['email'] ?? null,
                'Contact preferat' => isset($c['preferred_contact']) ? ($contact[$c['preferred_contact']] ?? $c['preferred_contact']) : null,
                'Specialitate' => $a->requestSpecialty?->name,
                'Medic / terapeut' => $a->staffMember?->display_name,
                'Serviciu' => $a->service?->name,
                'Locație' => $a->location?->name,
                'Interval dorit' => $wanted ? Carbon::parse($wanted)->timezone(config('app.timezone'))->format('d.m.Y H:i') : null,
            ],
            ! empty($c['message']),
            route('panou.requests.show', $a),
        );
    }
}
