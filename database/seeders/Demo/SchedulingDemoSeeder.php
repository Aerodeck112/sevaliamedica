<?php

namespace Database\Seeders\Demo;

use App\Core\Contracts\PublicAppointmentRequest;
use App\Models\User;
use App\Modules\Clinic\Models\Location;
use App\Modules\Clinic\Models\Service;
use App\Modules\Clinic\Models\StaffMember;
use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\StaffSchedule;
use App\Modules\Scheduling\Models\WaitlistEntry;
use App\Modules\Scheduling\Services\AppointmentIntakeService;
use App\Modules\Scheduling\Services\BookingService;
use App\Modules\Scheduling\Services\SeriesScheduler;
use App\Modules\Scheduling\Services\ServiceRecordService;
use App\Modules\Scheduling\Support\AppointmentStatus;
use App\Modules\Scheduling\Support\BookingConflictException;
use App\Modules\Scheduling\Support\BookingRequest;
use Carbon\CarbonImmutable;
use Database\Factories\PatientFactory;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Pacienți, programări, o serie, lista de așteptare și solicitări de pe site — toate FICTIVE.
 * Programul de lucru de aici (07:00–20:00, în fiecare zi) este doar pentru demonstrație; clinica își
 * configurează programul real din panou.
 */
class SchedulingDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction() || config('sevalia.stage') === 'production') {
            throw new RuntimeException('Datele demonstrative nu pot fi încărcate în producție.');
        }
        if (Patient::where('admin_notes', 'like', 'Pacient fictiv%')->exists()) {
            return; // deja încărcate
        }

        $booking = app(BookingService::class);
        $records = app(ServiceRecordService::class);
        $reception = User::where('email', 'receptie@demo.sevalia.invalid')->firstOrFail();
        $unirii = Location::where('slug', 'cartier-unirii')->firstOrFail();

        $medic = StaffMember::where('display_name', 'dr. Kinga Fülöp')->firstOrFail();
        $therapist = StaffMember::where('display_name', 'Andrei Demo (terapeut fictiv)')->firstOrFail();
        $therapist2 = StaffMember::where('display_name', 'Ioana Exemplu (terapeut fictiv)')->firstOrFail();
        foreach ([$medic, $therapist, $therapist2] as $s) {
            $s->locations()->syncWithoutDetaching([$unirii->id]);
            for ($d = 1; $d <= 7; $d++) {
                StaffSchedule::firstOrCreate(['staff_member_id' => $s->id, 'location_id' => $unirii->id, 'weekday' => $d], [
                    'start_time' => '07:00', 'end_time' => '20:00', 'breaks' => [['13:00', '13:30']], 'valid_from' => now()->subMonth()->toDateString(),
                ]);
            }
        }

        $names = [
            ['Exemplu', 'Ana'], ['Demonstrație', 'Mihai'], ['Fictiv', 'Elena'], ['Probă', 'Radu'], ['Testescu', 'Ioana'],
            ['Model', 'Paul'], ['Mostră', 'Daria'], ['Exersare', 'Victor'], ['Simulare', 'Irina'], ['Schiță', 'Andrei'],
        ];
        $patients = [];
        foreach ($names as $i => [$last, $first]) {
            $cnp = PatientFactory::fakeCnp();
            $patients[] = Patient::create([
                'last_name' => $last, 'first_name' => $first, 'cnp' => $cnp,
                'birth_date' => \App\Modules\Patients\Support\PatientNormalizer::fromCnp($cnp)['birth_date'] ?? null,
                'sex' => \App\Modules\Patients\Support\PatientNormalizer::fromCnp($cnp)['sex'] ?? null,
                'phone' => '0700 000 '.sprintf('%03d', 100 + $i), 'email' => 'pacient'.($i + 1).'@demo.sevalia.invalid',
                'city' => 'Târgu Mureș', 'allow_phone' => true, 'allow_sms' => $i % 2 === 0, 'allow_email' => $i % 3 === 0,
                'admin_notes' => 'Pacient fictiv (date demonstrative).', 'created_by' => $reception->id,
            ]);
        }
        $patients[4]->update(['birth_date' => now()->subYears(9)->toDateString(), 'cnp' => null]);
        $patients[4]->representatives()->create(['name' => 'Testescu Maria (fictiv)', 'relationship' => 'parinte', 'phone' => '0700 000 999', 'document_type' => 'certificat de naștere']);

        $consult = Service::where('code', 'DEMO-CONS-REAB')->firstOrFail();
        $kineto = Service::where('code', 'DEMO-KINETO')->firstOrFail();
        $alterg = Service::where('code', 'DEMO-ALTERG')->firstOrFail();
        $laser = Service::where('code', 'DEMO-LASER')->firstOrFail();
        $today = CarbonImmutable::today();

        $book = function (Patient $p, Service $s, StaffMember $st, CarbonImmutable $at, string $payer = 'privat') use ($booking, $reception, $unirii) {
            try {
                return $booking->book(new BookingRequest($p->id, $s->id, $st->id, $unirii->id, $at, payerType: $payer), $reception);
            } catch (BookingConflictException) {
                return null;
            }
        };
        $setStatus = fn (?Appointment $a, string $status) => $a?->forceFill(['status' => $status] + (in_array($status, ['anulata', 'neprezentat'], true)
            ? ['cancel_reason' => $status === 'anulata' ? 'Pacientul a anunțat telefonic (demo)' : 'Nu s-a prezentat (demo)', 'cancelled_at' => now()] : []))->save();

        // Azi: consultații la medic și ședințe la terapeuți, în diverse stadii.
        $a1 = $book($patients[0], $consult, $medic, $today->setTime(8, 30), 'cas');
        $a2 = $book($patients[1], $consult, $medic, $today->setTime(9, 0));
        $a3 = $book($patients[2], $consult, $medic, $today->setTime(10, 0), 'cas');
        $book($patients[3], $consult, $medic, $today->setTime(11, 0));
        $book($patients[5], $consult, $medic, $today->setTime(15, 30));
        $k1 = $book($patients[6], $kineto, $therapist, $today->setTime(8, 0));
        $book($patients[7], $kineto, $therapist, $today->setTime(9, 15), 'pachet');
        $book($patients[8], $alterg, $therapist2, $today->setTime(10, 0));
        $book($patients[9], $laser, $therapist2, $today->setTime(11, 0));
        $book($patients[4], $kineto, $therapist2, $today->setTime(16, 0));
        $setStatus($a1, AppointmentStatus::FINALIZATA);
        $setStatus($a2, AppointmentStatus::IN_DESFASURARE);
        $setStatus($a3, AppointmentStatus::SOSIT);
        $setStatus($k1, AppointmentStatus::FINALIZATA);
        foreach ([$a1, $k1] as $done) {
            if ($done) {
                $records->fromAppointment($done->fresh(), ['payer_type' => $done->payer_type ?? 'privat'], $reception);
            }
        }

        // Ieri și restul săptămânii.
        $setStatus($book($patients[3], $kineto, $therapist, $today->subDay()->setTime(10, 0)), AppointmentStatus::NEPREZENTAT);
        $setStatus($book($patients[5], $alterg, $therapist2, $today->subDay()->setTime(12, 0)), AppointmentStatus::FINALIZATA);
        for ($d = 1; $d <= 4; $d++) {
            $day = $today->addDays($d);
            $book($patients[$d], $consult, $medic, $day->setTime(9, 0));
            $book($patients[$d + 4], $kineto, $therapist, $day->setTime(10, 0));
            $book($patients[($d + 2) % 10], $laser, $therapist2, $day->setTime(14, 0));
        }
        $cancelled = $book($patients[9], $consult, $medic, $today->addDays(2)->setTime(11, 0));
        if ($cancelled) {
            $booking->changeStatus($cancelled, AppointmentStatus::ANULATA, $reception, 'Pacientul a anunțat telefonic (demo)');
        }

        // Serie de 10 ședințe (luni, miercuri, vineri).
        $scheduler = app(SeriesScheduler::class);
        $rule = ['start_date' => $today->toDateString(), 'weekdays' => [1, 3, 5], 'time' => '17:00', 'count' => 10];
        $plan = $scheduler->plan($patients[0]->id, $kineto->id, $therapist->id, $unirii->id, $rule);
        $scheduler->create($patients[0]->id, $kineto->id, $therapist->id, $unirii->id, $rule,
            array_map(fn ($i) => ['index' => $i['index'], 'start' => $i['start']->format('Y-m-d H:i'), 'skip' => (bool) $i['conflicts']], $plan),
            $reception, true, 'Serie demonstrativă');

        // Lista de așteptare.
        WaitlistEntry::create(['patient_id' => $patients[7]->id, 'service_id' => $consult->id, 'staff_member_id' => $medic->id, 'location_id' => $unirii->id,
            'preferences' => ['weekdays' => [1, 2, 3, 4, 5], 'from' => '08:00', 'to' => '13:00'], 'priority' => 1, 'notes' => 'Dorește cât mai devreme (demo)', 'created_by' => $reception->id]);
        WaitlistEntry::create(['patient_id' => $patients[2]->id, 'service_id' => $alterg->id, 'priority' => 2, 'preferences' => ['from' => '14:00', 'to' => '19:00'], 'created_by' => $reception->id]);
        WaitlistEntry::create(['patient_id' => $patients[6]->id, 'priority' => 3, 'valid_until' => $today->addWeeks(3)->toDateString(), 'created_by' => $reception->id]);

        // Solicitări de pe site.
        $intake = app(AppointmentIntakeService::class);
        foreach ([
            ['Popa Exemplu Daniel', '0700 111 222', 'Reabilitare medicală', $medic->id, 'Aș dori o consultație după o entorsă (mesaj demo).'],
            ['Demo Carmen', '0700 333 444', 'Reumatologie', null, 'Prefer după-amiaza (mesaj demo).'],
            ['Fictiv Ana', '0700 000 100', 'Reabilitare medicală', null, null],
        ] as [$name, $phone, $spec, $staffId, $msg]) {
            $intake->submit(new PublicAppointmentRequest(
                $name, $phone, null, 'telefon', \App\Modules\Clinic\Models\Specialty::where('name', $spec)->value('id'), $staffId, null, $unirii->id,
                null, $msg, null, CarbonImmutable::now()->subMinutes(rand(5, 300)), url('/contactus'), hash('sha256', 'demo'),
            ));
        }
    }
}
