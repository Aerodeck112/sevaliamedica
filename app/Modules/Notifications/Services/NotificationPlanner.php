<?php

namespace App\Modules\Notifications\Services;

use App\Models\User;
use App\Modules\Clinic\Models\Location;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Models\NotificationTemplate;
use App\Modules\Notifications\Support\MessageType;
use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\WaitlistOffer;
use App\Modules\Scheduling\Services\PatientLinkService;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Transformă un eveniment (programare confirmată, mutată, anulată ...) în livrări. Nu trimite nimic:
 * livrările sunt preluate de DeliveryDispatcher din cron.
 *
 * Fără duplicate: fiecare livrare are o cheie de idempotență unică în baza de date, calculată din
 * (tip, entitate, canal, destinatar, variantă). Același eveniment primit de două ori, sau aceeași scanare de
 * remindere rulată de mai multe ori, produce aceeași cheie, deci a doua inserare este ignorată.
 */
class NotificationPlanner
{
    public function __construct(
        private readonly NotificationSettings $settings,
        private readonly ChannelManager $channels,
        private readonly TemplateRenderer $renderer,
        private readonly PatientLinkService $links,
    ) {}

    /**
     * @param  string  $variant  ce face mesajul unic în timp (versiunea programării, ora, id-ul ofertei)
     * @param  array<string, string>  $extraVars
     * @return list<NotificationDelivery> livrările create acum (cele existente deja nu se întorc)
     */
    public function forAppointment(string $type, Appointment $appointment, string $variant, ?CarbonImmutable $scheduledFor = null, array $extraVars = [], ?User $by = null, bool $withLink = false): array
    {
        if (! $this->settings->typeEnabled($type)) {
            return [];
        }
        $appointment->loadMissing(['patient', 'location', 'service']);
        $recipients = $this->recipientsFor($appointment->patient, $appointment);
        $vars = $this->appointmentVariables($appointment) + $extraVars;

        return $this->createDeliveries($type, $recipients, $vars, $variant, $scheduledFor, $by, $withLink ? $appointment : null, [
            'patient_id' => $appointment->patient_id,
            'appointment_id' => $appointment->id,
        ], 'appointment:'.$appointment->id);
    }

    /** Oferta din lista de așteptare: destinatarul e pacientul din intrare, intervalul e cel oferit. */
    public function forWaitlistOffer(WaitlistOffer $offer): array
    {
        if (! $this->settings->typeEnabled(MessageType::OFERTA)) {
            return [];
        }
        $offer->loadMissing(['entry.patient']);
        $patient = $offer->entry?->patient;
        $start = CarbonImmutable::parse($offer->slot_start);
        $location = $offer->location_id ? Location::find($offer->location_id) : null;
        $vars = $this->baseVariables($patient?->first_name, $location) + [
            'data' => $start->format('d.m.Y'), 'ora' => $start->format('H:i'), 'ziua' => $start->locale('ro')->isoFormat('dddd'),
        ];

        return $this->createDeliveries(MessageType::OFERTA, $this->recipientsFor($patient, null), $vars, 'offer:'.$offer->id, null, null, null, [
            'patient_id' => $patient?->id,
            'subject_type' => 'waitlist_offer',
            'subject_id' => $offer->id,
        ], 'offer:'.$offer->id);
    }

    /**
     * Livrare inițiată manual (follow-up administrativ). Cheia include un identificator generat la afișarea
     * formularului, deci o dublă apăsare pe „Trimite” nu produce două mesaje.
     */
    public function manual(string $type, Appointment $appointment, string $channel, string $nonce, User $by): ?NotificationDelivery
    {
        $appointment->loadMissing(['patient', 'location', 'service']);
        $recipient = collect($this->recipientsFor($appointment->patient, $appointment, ignoreStrategy: true))->firstWhere('channel', $channel);
        if (! $recipient) {
            return null;
        }

        return $this->createDeliveries($type, [$recipient], $this->appointmentVariables($appointment), 'manual:'.$nonce, null, $by, null, [
            'patient_id' => $appointment->patient_id,
            'appointment_id' => $appointment->id,
        ], 'appointment:'.$appointment->id, force: true)[0] ?? null;
    }

    /**
     * Destinatarii, în ordinea canalelor configurată, filtrați după preferințele pacientului și canalele utilizabile.
     * Pentru o solicitare de pe site fără pacient identificat se folosesc datele de contact din formular.
     *
     * @return list<array{channel: string, address: string}>
     */
    public function recipientsFor(?Patient $patient, ?Appointment $appointment, bool $ignoreStrategy = false): array
    {
        $available = [];
        if ($patient) {
            $allowed = $patient->operationalChannels();
            $addresses = ['sms' => $patient->phone, 'whatsapp' => $patient->phone, 'email' => $patient->email];
        } elseif ($appointment && $appointment->request_contact) {
            $c = $appointment->request_contact;
            $preferred = $c['preferred_contact'] ?? null;
            // Cine a cerut să fie sunat nu primește mesaje automate; cine a cerut SMS sau e-mail primește pe acel canal.
            $allowed = match ($preferred) {
                'email' => ['email'],
                'sms' => ['sms'],
                'telefon' => [],
                default => array_keys(array_filter(['email' => $c['email'] ?? null, 'sms' => $c['phone'] ?? null])),
            };
            $addresses = ['sms' => $c['phone'] ?? null, 'whatsapp' => $c['phone'] ?? null, 'email' => $c['email'] ?? null];
        } else {
            return [];
        }

        foreach ($this->settings->channelOrder() as $channel) {
            if (in_array($channel, $allowed, true) && ! empty($addresses[$channel]) && $this->channels->isUsable($channel)) {
                $available[] = ['channel' => $channel, 'address' => trim((string) $addresses[$channel])];
            }
        }
        if ($ignoreStrategy) {
            return $available;
        }

        return $this->settings->get('notifications.strategy') === 'toate' ? $available : array_slice($available, 0, 1);
    }

    public function appointmentVariables(Appointment $a): array
    {
        $start = $a->starts_at;

        return $this->baseVariables($a->patient?->first_name ?? ($a->request_contact['name'] ?? null), $a->location) + [
            'data' => $start?->format('d.m.Y') ?? '',
            'ora' => $start?->format('H:i') ?? '',
            'ziua' => $start?->locale('ro')->isoFormat('dddd') ?? '',
            // Doar tipul generic („consultație”, „ședință de recuperare”), nu denumirea procedurii.
            'serviciu' => $a->service ? mb_strtolower($a->service->kindLabel()) : '',
            'referinta' => (string) ($a->request_reference ?? ''),
        ];
    }

    private function baseVariables(?string $firstName, $location): array
    {
        return [
            'prenume' => trim((string) $firstName) ?: 'pacient',
            'locatia' => $location?->name ?? '',
            'adresa_locatiei' => trim(($location?->address ?? '').($location?->city ? ', '.$location->city : '')),
            'telefon_clinica' => $location?->phone ?? '',
            'clinica' => 'SEVALIA',
        ];
    }

    /**
     * @param  list<array{channel: string, address: string}>  $recipients
     * @return list<NotificationDelivery>
     */
    private function createDeliveries(string $type, array $recipients, array $vars, string $variant, ?CarbonImmutable $scheduledFor, ?User $by, ?Appointment $linkFor, array $columns, string $entity, bool $force = false): array
    {
        $category = MessageType::all()[$type]['category'] ?? 'operational';
        $when = $this->outsideQuietHours($scheduledFor ?? CarbonImmutable::now());

        if ($recipients === []) {
            // Înregistrăm motivul, ca recepția să vadă de ce pacientul nu a primit mesajul.
            $this->insert(hash('sha256', implode('|', [$type, $entity, 'niciunul', $variant])), [
                'type' => $type, 'channel' => 'niciunul', 'category' => $category, 'recipient' => '', 'recipient_masked' => '—',
                'variant' => $variant, 'status' => NotificationDelivery::CANCELLED,
                'skip_reason' => 'Niciun canal configurat și permis de pacient.', 'created_by' => $by?->id,
            ] + $columns);

            return [];
        }

        $keyed = [];
        foreach ($recipients as $r) {
            $key = hash('sha256', implode('|', [$type, $entity, $r['channel'], mb_strtolower($r['address']), $variant]));
            if (! NotificationDelivery::where('idempotency_key', $key)->exists()) {
                $keyed[$key] = $r;
            }
        }
        if ($keyed === []) {
            return []; // eveniment deja tratat
        }

        // Linkul cu token se emite o singură dată, numai dacă există o livrare nouă.
        if ($linkFor && ! array_key_exists('link_gestionare', $vars)) {
            $vars['link_gestionare'] = $this->links->issue($linkFor, $by)['url'];
        }

        $created = [];
        foreach ($keyed as $key => $r) {
            $template = NotificationTemplate::active($type, $r['channel']);
            if (! $template) {
                $this->insert($key, [
                    'type' => $type, 'channel' => $r['channel'], 'category' => $category, 'recipient' => $r['address'],
                    'recipient_masked' => self::mask($r['address']), 'variant' => $variant, 'status' => NotificationDelivery::CANCELLED,
                    'skip_reason' => 'Nu există un șablon activ pentru acest tip și canal.', 'created_by' => $by?->id,
                ] + $columns);

                continue;
            }
            $single = $r['channel'] !== 'email';
            $payload = [
                'subject' => $template->subject ? $this->renderer->render($template->subject, $vars, true) : null,
                'body' => $this->renderer->render($template->body, $vars, false),
                'provider_template' => $template->provider_template,
                'provider_params' => array_map(fn ($p) => (string) ($vars[$p] ?? ''), $template->provider_params ?? []),
            ];
            if ($single) {
                $payload['body'] = preg_replace('/[ \t]+/', ' ', $payload['body']);
            }
            $delivery = $this->insert($key, [
                'type' => $type, 'channel' => $r['channel'], 'category' => $category, 'template_id' => $template->id,
                'recipient' => $r['address'], 'recipient_masked' => self::mask($r['address']), 'payload' => $payload,
                'variant' => $variant, 'status' => NotificationDelivery::PENDING, 'scheduled_for' => $when,
                'next_attempt_at' => $when, 'created_by' => $by?->id,
            ] + $columns);
            if ($delivery) {
                $created[] = $delivery;
            }
        }

        return $created;
    }

    /** Inserează dacă cheia nu există; întoarce null dacă livrarea exista deja (eveniment duplicat). */
    private function insert(string $key, array $attributes): ?NotificationDelivery
    {
        if (NotificationDelivery::where('idempotency_key', $key)->exists()) {
            return null;
        }
        try {
            return DB::transaction(fn () => NotificationDelivery::create(['idempotency_key' => $key] + $attributes));
        } catch (UniqueConstraintViolationException) {
            return null; // creată între timp de un proces concurent
        }
    }

    /** Mută ora de trimitere la sfârșitul intervalului de liniște, dacă e cazul. */
    public function outsideQuietHours(CarbonImmutable $at): CarbonImmutable
    {
        $start = (string) $this->settings->get('notifications.quiet_start');
        $end = (string) $this->settings->get('notifications.quiet_end');
        if ($start === $end) {
            return $at;
        }
        $time = $at->format('H:i');
        $inQuiet = $start < $end ? ($time >= $start && $time < $end) : ($time >= $start || $time < $end);
        if (! $inQuiet) {
            return $at;
        }
        [$h, $m] = array_map('intval', explode(':', $end));
        $candidate = $at->setTime($h, $m);

        return $candidate->lessThanOrEqualTo($at) ? $candidate->addDay() : $candidate;
    }

    public static function mask(string $address): string
    {
        if (str_contains($address, '@')) {
            [$user, $domain] = explode('@', $address, 2);

            return mb_substr($user, 0, 1).'•••@'.$domain;
        }
        $digits = preg_replace('/\D/', '', $address);

        return strlen($digits) > 5 ? substr($digits, 0, 2).str_repeat('•', strlen($digits) - 5).substr($digits, -3) : '•••';
    }
}
