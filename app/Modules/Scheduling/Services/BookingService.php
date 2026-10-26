<?php

namespace App\Modules\Scheduling\Services;

use App\Core\Audit\Audit;
use App\Models\User;
use App\Modules\Clinic\Models\Equipment;
use App\Modules\Clinic\Models\Location;
use App\Modules\Clinic\Models\Room;
use App\Modules\Clinic\Models\Service;
use App\Modules\Clinic\Models\StaffMember;
use App\Modules\Scheduling\Events\AppointmentCancelled;
use App\Modules\Scheduling\Events\AppointmentStatusChanged;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AppointmentEvent;
use App\Modules\Scheduling\Models\AppointmentResource;
use App\Modules\Scheduling\Models\ScheduleException;
use App\Modules\Scheduling\Support\AppointmentStatus;
use App\Modules\Scheduling\Support\BookingConflictException;
use App\Modules\Scheduling\Support\BookingRequest;
use App\Modules\Scheduling\Support\Conflict;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Rezervarea simultană a persoanei, sălii și aparatului, fără conflicte — inclusiv la cereri concurente.
 *
 * Protecția la concurență:
 *  1. totul rulează într-o tranzacție;
 *  2. se blochează (SELECT … FOR UPDATE) rândurile resurselor candidate, mereu în aceeași ordine
 *     (persoană → săli după id → aparate după id), ca două rezervări să se serializeze fără deadlock;
 *  3. verificarea suprapunerilor folosește citiri cu blocare („current read”), deci vede ultimele date
 *     confirmate, chiar dacă tranzacția are deja un snapshot mai vechi;
 *  4. abia apoi se inserează programarea și resursele.
 * Două cereri pentru aceeași resursă și același interval: a doua așteaptă blocarea, vede rezervarea primei
 * și primește conflict.
 */
class BookingService
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly SchedulingSettings $settings,
    ) {}

    /**
     * Verifică o rezervare fără a o salva. Cu $lock = true trebuie apelată în tranzacție.
     *
     * @return array{conflicts: list<Conflict>, warnings: list<string>, allocation: list<array{0:string,1:int}>, end: ?CarbonImmutable, blockEnd: ?CarbonImmutable}
     */
    public function evaluate(BookingRequest $r, ?int $excludeAppointmentId = null, bool $lock = false): array
    {
        $conflicts = [];
        $warnings = [];
        $allocation = [];

        $service = Service::with('requirements')->find($r->serviceId);
        $location = Location::find($r->locationId);
        if (! $service || ! $service->is_active) {
            return $this->result([new Conflict('input', 'Serviciul ales nu există sau nu este activ.')]);
        }
        if (! $location || ! $location->is_active) {
            return $this->result([new Conflict('input', 'Locația aleasă nu există sau nu este activă.')]);
        }

        $start = $r->startsAt;
        $end = $r->endsAt ?? $start->addMinutes($service->duration_minutes);
        $blockEnd = $end->addMinutes((int) $service->buffer_minutes);
        if ($end <= $start) {
            return $this->result([new Conflict('input', 'Ora de sfârșit trebuie să fie după ora de început.')]);
        }

        // 1. Persoana
        $staff = null;
        if ($r->staffMemberId) {
            $staff = $lock
                ? StaffMember::whereKey($r->staffMemberId)->lockForUpdate()->first()
                : StaffMember::find($r->staffMemberId);
            if (! $staff || ! $staff->is_active) {
                return $this->result([new Conflict('staff', 'Persoana aleasă nu există sau nu este activă.')]);
            }
            if ($service->staff_kind && $staff->kind !== $service->staff_kind) {
                $warnings[] = 'Serviciul este efectuat de regulă de: '.(StaffMember::KINDS[$service->staff_kind] ?? $service->staff_kind).'.';
            }
        } elseif ($service->staff_kind) {
            $conflicts[] = new Conflict('staff', 'Alegeți persoana care efectuează serviciul.');
        }

        // 2. Închideri și program de lucru
        if ($closure = $this->availability->closure($location->id, $start, $blockEnd)) {
            $conflicts[] = new Conflict('closure', 'Locația este închisă în acest interval ('.($closure->note ?: 'închidere').').');
        }
        if ($staff) {
            $off = $this->availability->exceptions($staff->id, $location->id, $start, $blockEnd)
                ->filter(fn ($e) => $e->staff_member_id === $staff->id && $e->isUnavailability());
            if ($off->isNotEmpty()) {
                $e = $off->first();
                $conflicts[] = new Conflict('schedule', $staff->display_name.' este indisponibil(ă) ('.mb_strtolower(ScheduleException::KINDS[$e->kind] ?? $e->kind).') în acest interval.');
            } elseif ($this->settings->get('scheduling.enforce_working_hours')) {
                if (! $this->availability->hasAnySchedule($staff->id)) {
                    $warnings[] = $staff->display_name.' nu are încă program de lucru configurat (Programări → Program de lucru); intervalul nu a putut fi verificat față de program.';
                } elseif (! $this->availability->isWithinWorkingHours($staff->id, $location->id, $start, $end)) {
                    if ($r->overrideSchedule && trim((string) $r->overrideReason) !== '') {
                        $warnings[] = 'Programare în afara programului de lucru, forțată: '.$r->overrideReason;
                    } else {
                        $conflicts[] = new Conflict('schedule', 'Intervalul este în afara programului de lucru al persoanei '.$staff->display_name.' în '.$location->name.'.');
                    }
                }
            }

            if ($busy = $this->overlapping('staff', $staff->id, $start, $blockEnd, $excludeAppointmentId, $lock)->first()) {
                $conflicts[] = new Conflict('staff', $staff->display_name.' are deja o programare '.$busy->starts_at->format('H:i').'–'.$busy->ends_at->format('H:i').'.', $busy->appointment_id);
            } else {
                $allocation[] = ['staff', $staff->id];
            }
        }

        // 3. Săli
        $roomReqs = $service->requirements->where('resource_type', 'room');
        $manualRooms = array_values(array_unique(array_map('intval', $r->roomIds)));
        if ($roomReqs->isEmpty() && $manualRooms) {
            $roomReqs = collect([(object) ['resource_kind' => null, 'quantity' => count($manualRooms)]]);
        }
        foreach ($roomReqs as $req) {
            $query = Room::where('location_id', $location->id)->where('is_active', true)
                ->when($req->resource_kind, fn ($q, $k) => $q->where('kind', $k))
                ->when($manualRooms, fn ($q) => $q->whereIn('id', $manualRooms))
                ->orderBy('id');
            $candidates = $lock ? $query->lockForUpdate()->get() : $query->get();
            if ($candidates->isEmpty()) {
                $conflicts[] = new Conflict('room', $manualRooms
                    ? 'Sala aleasă nu este potrivită pentru acest serviciu sau nu este activă în '.$location->name.'.'
                    : 'Serviciul necesită o sală de tip „'.$req->resource_kind.'”, dar '.$location->name.' nu are încă săli de acest tip. Configurați resursele în Administrare → Săli.');

                continue;
            }
            $picked = 0;
            foreach ($candidates as $room) {
                if ($picked >= $req->quantity) {
                    break;
                }
                if ($this->peakOccupancy('room', $room->id, $start, $blockEnd, $excludeAppointmentId, $lock) < max(1, $room->capacity)) {
                    $allocation[] = ['room', $room->id];
                    $picked++;
                }
            }
            if ($picked < $req->quantity) {
                $conflicts[] = new Conflict('room', ($req->resource_kind ? 'Nicio sală de tip „'.$req->resource_kind.'” liberă' : 'Sala aleasă este ocupată').' în acest interval ('.$candidates->pluck('name')->join(', ').' ocupate la capacitate).');
            }
        }

        // 4. Aparate (fiecare aparat fizic, câte o programare odată)
        $manualEquipment = array_values(array_unique(array_map('intval', $r->equipmentIds)));
        foreach ($service->requirements->where('resource_type', 'equipment') as $req) {
            $query = Equipment::where('location_id', $location->id)->where('is_active', true)->where('kind', $req->resource_kind)
                ->when($manualEquipment, fn ($q) => $q->whereIn('id', $manualEquipment))
                ->orderBy('id');
            $candidates = $lock ? $query->lockForUpdate()->get() : $query->get();
            if ($candidates->isEmpty()) {
                $conflicts[] = new Conflict('equipment', $manualEquipment
                    ? 'Aparatul ales nu este de tipul cerut („'.$req->resource_kind.'”) sau nu este activ.'
                    : 'Serviciul necesită un aparat de tip „'.$req->resource_kind.'”, dar '.$location->name.' nu are încă aparate de acest tip. Configurați resursele în Administrare → Aparatură.');

                continue;
            }
            $picked = 0;
            foreach ($candidates as $eq) {
                if ($picked >= $req->quantity) {
                    break;
                }
                if (! $this->overlapping('equipment', $eq->id, $start, $blockEnd, $excludeAppointmentId, $lock)->exists()) {
                    $allocation[] = ['equipment', $eq->id];
                    $picked++;
                }
            }
            if ($picked < $req->quantity) {
                $conflicts[] = new Conflict('equipment', 'Niciun aparat de tip „'.$req->resource_kind.'” liber în acest interval ('.$candidates->pluck('name')->join(', ').' rezervat).');
            }
        }

        return ['conflicts' => $conflicts, 'warnings' => $warnings, 'allocation' => $allocation, 'end' => $end, 'blockEnd' => $blockEnd];
    }

    /** Creează o programare confirmată (sau o ține pentru o solicitare). Aruncă BookingConflictException. */
    public function book(BookingRequest $r, ?User $user, ?CarbonImmutable $holdUntil = null): Appointment
    {
        return DB::transaction(function () use ($r, $user, $holdUntil) {
            $eval = $this->evaluate($r, null, true);
            if ($eval['conflicts']) {
                throw new BookingConflictException($eval['conflicts']);
            }
            self::testDelay();

            $appointment = Appointment::create([
                'patient_id' => $r->patientId ?: null,
                'service_id' => $r->serviceId,
                'staff_member_id' => $r->staffMemberId,
                'location_id' => $r->locationId,
                'series_id' => $r->seriesId,
                'series_index' => $r->seriesIndex,
                'starts_at' => $r->startsAt,
                'ends_at' => $eval['end'],
                'blocked_until' => $eval['blockEnd'],
                'status' => $r->status,
                'source' => $r->source,
                'payer_type' => $r->payerType,
                'notes' => $r->notes,
                'created_by' => $user?->id,
                'updated_by' => $user?->id,
            ]);
            $this->insertResources($appointment, $eval['allocation'], $holdUntil);
            $this->event($appointment, $user, 'creata', null, $r->status, $r->overrideReason, [
                'resurse' => $this->describe($eval['allocation']),
                'avertizari' => $eval['warnings'],
            ]);
            Audit::log('scheduling.appointment_created', $appointment, ['status' => $r->status, 'sursa' => $r->source], $appointment->patient_id, $user?->id);

            return $appointment->setRelation('bookingWarnings', collect($eval['warnings']));
        }, 3);
    }

    /**
     * Mută / redimensionează / schimbă persoana. $expectedVersion previne suprascrierea unei modificări
     * făcute între timp de alt utilizator (ex. două ferestre de calendar deschise).
     */
    public function move(Appointment $appointment, CarbonImmutable $start, ?CarbonImmutable $end, ?int $staffId, ?int $expectedVersion, User $user, string $reason, ?array $roomIds = null, ?array $equipmentIds = null, bool $override = false): Appointment
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Indicați motivul modificării.']);
        }

        return DB::transaction(function () use ($appointment, $start, $end, $staffId, $expectedVersion, $user, $reason, $roomIds, $equipmentIds, $override) {
            /** @var Appointment $locked */
            $locked = Appointment::whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            if ($expectedVersion !== null && $locked->version !== $expectedVersion) {
                throw new BookingConflictException([new Conflict('input', 'Programarea a fost modificată între timp de altcineva. Reîncărcați calendarul.')]);
            }
            if (! in_array($locked->status, [AppointmentStatus::CONFIRMATA, AppointmentStatus::SOSIT], true)) {
                throw new BookingConflictException([new Conflict('input', 'O programare cu statusul „'.$locked->statusLabel().'” nu mai poate fi mutată.')]);
            }

            $current = $locked->activeResources()->get();
            $keepRooms = $roomIds ?? $current->where('resource_type', 'room')->pluck('resource_id')->all();
            $keepEquipment = $equipmentIds ?? $current->where('resource_type', 'equipment')->pluck('resource_id')->all();
            $duration = $end ? null : $locked->durationMinutes();
            $request = new BookingRequest(
                patientId: (int) $locked->patient_id,
                serviceId: (int) $locked->service_id,
                staffMemberId: $staffId ?? $locked->staff_member_id,
                locationId: (int) $locked->location_id,
                startsAt: $start,
                endsAt: $end ?? $start->addMinutes($duration),
                roomIds: $keepRooms,
                equipmentIds: $keepEquipment,
                overrideSchedule: $override,
                overrideReason: $override ? $reason : null,
            );
            $eval = $this->evaluate($request, $locked->id, true);
            if ($eval['conflicts'] && ($roomIds === null || $equipmentIds === null)) {
                // Sala/aparatul vechi poate fi ocupat la noua oră: încercăm alocarea automată a altora de același tip.
                $retry = $this->evaluate($request->with(['roomIds' => $roomIds ?? [], 'equipmentIds' => $equipmentIds ?? []]), $locked->id, true);
                if (! $retry['conflicts']) {
                    $eval = $retry;
                }
            }
            if ($eval['conflicts']) {
                throw new BookingConflictException($eval['conflicts']);
            }

            $before = [
                'inceput' => $locked->starts_at?->format('Y-m-d H:i'), 'sfarsit' => $locked->ends_at?->format('Y-m-d H:i'),
                'personal' => $locked->staff_member_id, 'resurse' => $this->describe($current->map(fn ($c) => [$c->resource_type, $c->resource_id])->all()),
            ];
            $locked->activeResources()->update(['released_at' => now()]);
            $locked->forceFill([
                'starts_at' => $start, 'ends_at' => $eval['end'], 'blocked_until' => $eval['blockEnd'],
                'staff_member_id' => $request->staffMemberId, 'version' => $locked->version + 1, 'updated_by' => $user->id,
            ])->save();
            $this->insertResources($locked, $eval['allocation']);
            $this->event($locked, $user, 'mutata', $locked->status, $locked->status, $reason, [
                'inainte' => $before,
                'dupa' => ['inceput' => $start->format('Y-m-d H:i'), 'sfarsit' => $eval['end']->format('Y-m-d H:i'), 'personal' => $request->staffMemberId, 'resurse' => $this->describe($eval['allocation'])],
                'avertizari' => $eval['warnings'],
            ]);
            Audit::log('scheduling.appointment_moved', $locked, [], $locked->patient_id);

            return $locked->setRelation('bookingWarnings', collect($eval['warnings']));
        }, 3);
    }

    /**
     * Schimbă statusul. $user = null doar pentru acțiunile pacientului prin link (anulare), înregistrate ca atare.
     */
    public function changeStatus(Appointment $appointment, string $to, ?User $user, ?string $reason = null, bool $byPatient = false): Appointment
    {
        return DB::transaction(function () use ($appointment, $to, $user, $reason, $byPatient) {
            $locked = Appointment::whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;
            if (! AppointmentStatus::canTransition($from, $to)) {
                throw ValidationException::withMessages(['status' => 'Tranziția „'.AppointmentStatus::label($from).'” → „'.AppointmentStatus::label($to).'” nu este permisă.']);
            }
            if (AppointmentStatus::requiresReason($from, $to) && trim((string) $reason) === '') {
                throw ValidationException::withMessages(['reason' => 'Indicați motivul.']);
            }
            if ($to === AppointmentStatus::CONFIRMATA && $from === AppointmentStatus::SOLICITARE) {
                throw ValidationException::withMessages(['status' => 'O solicitare se confirmă alegând pacientul și intervalul (ecranul „Solicitări”).']);
            }
            if ($to === AppointmentStatus::NEPREZENTAT && $locked->starts_at && $locked->starts_at->isFuture()) {
                throw ValidationException::withMessages(['status' => 'Neprezentarea se poate marca doar după ora programării.']);
            }
            if (in_array($to, [AppointmentStatus::SOSIT, AppointmentStatus::IN_DESFASURARE], true) && $locked->starts_at && ! $locked->starts_at->isToday()) {
                throw ValidationException::withMessages(['status' => 'Sosirea și începerea se pot marca doar în ziua programării.']);
            }

            $locked->status = $to;
            $locked->updated_by = $user?->id;
            $locked->version++;
            if (in_array($to, [AppointmentStatus::ANULATA, AppointmentStatus::NEPREZENTAT], true)) {
                $locked->cancel_reason = $reason;
                $locked->cancelled_at = now();
                $locked->activeResources()->update(['released_at' => now()]);
            }
            $locked->save();
            $this->event($locked, $user, $byPatient ? 'anulata_pacient' : 'status', $from, $to, $reason);
            Audit::log('scheduling.appointment_status', $locked, ['din' => $from, 'in' => $to, 'de_pacient' => $byPatient], $locked->patient_id, $user?->id);

            DB::afterCommit(function () use ($locked, $from, $to, $byPatient) {
                event(new AppointmentStatusChanged($locked, $from, $to));
                if ($to === AppointmentStatus::ANULATA) {
                    event(new AppointmentCancelled($locked, $byPatient));
                }
            });

            return $locked;
        });
    }

    /**
     * Confirmarea ULTERIOARĂ a prezenței, pentru o programare dintr-o zi trecută rămasă activă (ex. terapeutul a uitat
     * să marcheze sosirea). Acțiune explicită, cu motiv obligatoriu; trece programarea în „finalizată” și consemnează
     * în istoric că prezența a fost confirmată după data programării. Nu se aplică automat niciodată.
     */
    public function recordLateAttendance(Appointment $appointment, User $user, string $reason): Appointment
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages(['reason' => 'Indicați de ce prezența se confirmă ulterior.']);
        }

        return DB::transaction(function () use ($appointment, $user, $reason) {
            $locked = Appointment::whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;
            if (! in_array($from, [AppointmentStatus::CONFIRMATA, AppointmentStatus::SOSIT, AppointmentStatus::IN_DESFASURARE], true)) {
                throw ValidationException::withMessages(['status' => 'Prezența se poate confirma ulterior doar pentru o programare activă (status actual: '.AppointmentStatus::label($from).').']);
            }
            if (! $locked->starts_at || ! $locked->starts_at->lt(now()->startOfDay())) {
                throw ValidationException::withMessages(['status' => 'Pentru programările de azi folosiți pașii obișnuiți (sosit → în desfășurare → finalizată).']);
            }
            $locked->status = AppointmentStatus::FINALIZATA;
            $locked->updated_by = $user->id;
            $locked->version++;
            $locked->save();
            $text = mb_substr('Prezență confirmată ulterior: '.trim($reason), 0, 500);
            $this->event($locked, $user, 'status', $from, AppointmentStatus::FINALIZATA, $text);
            Audit::log('scheduling.appointment_late_attendance', $locked, ['din' => $from], $locked->patient_id, $user->id);
            DB::afterCommit(fn () => event(new AppointmentStatusChanged($locked, $from, AppointmentStatus::FINALIZATA)));

            return $locked;
        });
    }

    /**
     * Transformă o solicitare (cu pacient identificat) într-o programare confirmată, rezervând resursele.
     */
    public function confirmRequest(Appointment $request, BookingRequest $r, ?User $user, bool $requirePatient = true): Appointment
    {
        return DB::transaction(function () use ($request, $r, $user, $requirePatient) {
            $locked = Appointment::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== AppointmentStatus::SOLICITARE) {
                throw new BookingConflictException([new Conflict('input', 'Solicitarea a fost deja procesată (status: '.$locked->statusLabel().').')]);
            }
            if ($requirePatient && ! $locked->patient_id) {
                throw new BookingConflictException([new Conflict('input', 'Identificați întâi pacientul (fișă existentă sau fișă nouă).')]);
            }
            // O eventuală ținere a intervalului aparține chiar acestei solicitări: o excludem din verificare.
            $eval = $this->evaluate($r, $locked->id, true);
            if ($eval['conflicts']) {
                throw new BookingConflictException($eval['conflicts']);
            }
            $locked->resources()->whereNull('released_at')->update(['released_at' => now()]);
            $locked->forceFill([
                'service_id' => $r->serviceId, 'staff_member_id' => $r->staffMemberId, 'location_id' => $r->locationId,
                'starts_at' => $r->startsAt, 'ends_at' => $eval['end'], 'blocked_until' => $eval['blockEnd'],
                'status' => AppointmentStatus::CONFIRMATA, 'payer_type' => $r->payerType ?? $locked->payer_type,
                'version' => $locked->version + 1, 'updated_by' => $user?->id,
            ])->save();
            $this->insertResources($locked, $eval['allocation']);
            $this->event($locked, $user, 'status', AppointmentStatus::SOLICITARE, AppointmentStatus::CONFIRMATA, $user ? null : 'Confirmare automată (reguli online)', ['resurse' => $this->describe($eval['allocation']), 'avertizari' => $eval['warnings']]);
            Audit::log('scheduling.request_confirmed', $locked, ['automat' => $user === null], $locked->patient_id, $user?->id);
            DB::afterCommit(fn () => event(new AppointmentStatusChanged($locked, AppointmentStatus::SOLICITARE, AppointmentStatus::CONFIRMATA)));

            return $locked->setRelation('bookingWarnings', collect($eval['warnings']));
        });
    }

    /**
     * Ține temporar intervalul cerut de o solicitare online (dacă setarea „ținere” > 0 și intervalul e liber).
     * Nu aruncă excepție: dacă intervalul nu e liber, solicitarea rămâne fără ținere.
     */
    public function holdForRequest(Appointment $request, BookingRequest $r, CarbonImmutable $until): bool
    {
        return DB::transaction(function () use ($request, $r, $until) {
            $eval = $this->evaluate($r, $request->id, true);
            if ($eval['conflicts']) {
                return false;
            }
            $request->forceFill(['starts_at' => $r->startsAt, 'ends_at' => $eval['end'], 'blocked_until' => $eval['blockEnd']])->save();
            $this->insertResources($request, $eval['allocation'], $until);
            $this->event($request, null, 'resurse', null, null, null, ['tinere_pana_la' => $until->format('Y-m-d H:i'), 'resurse' => $this->describe($eval['allocation'])]);

            return true;
        });
    }

    /** Rezervările active care se suprapun cu intervalul (ignorând ținerile expirate și programarea exclusă). */
    public function overlapping(string $type, int $id, CarbonImmutable $from, CarbonImmutable $to, ?int $exclude, bool $lock)
    {
        $q = AppointmentResource::where('resource_type', $type)->where('resource_id', $id)
            ->whereNull('released_at')
            ->where(fn ($h) => $h->whereNull('held_until')->orWhere('held_until', '>', now()))
            ->where('starts_at', '<', $to)->where('ends_at', '>', $from)
            ->when($exclude, fn ($q) => $q->where('appointment_id', '!=', $exclude))
            ->orderBy('starts_at');

        return $lock ? $q->lockForUpdate() : $q;
    }

    /** Numărul maxim de rezervări simultane ale resursei în interval (pentru săli cu capacitate > 1). */
    public function peakOccupancy(string $type, int $id, CarbonImmutable $from, CarbonImmutable $to, ?int $exclude, bool $lock): int
    {
        $rows = $this->overlapping($type, $id, $from, $to, $exclude, $lock)->get(['starts_at', 'ends_at']);
        $points = [];
        foreach ($rows as $row) {
            $points[] = [max($row->starts_at, $from)->getTimestamp(), 1];
            $points[] = [min($row->ends_at, $to)->getTimestamp(), -1];
        }
        usort($points, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $current = $peak = 0;
        foreach ($points as [, $delta]) {
            $current += $delta;
            $peak = max($peak, $current);
        }

        return $peak;
    }

    public function event(Appointment $a, ?User $user, string $type, ?string $from, ?string $to, ?string $reason = null, array $changes = []): AppointmentEvent
    {
        return AppointmentEvent::create([
            'appointment_id' => $a->id, 'user_id' => $user?->id, 'type' => $type,
            'from_status' => $from, 'to_status' => $to, 'reason' => $reason,
            'changes' => array_filter($changes, fn ($v) => $v !== [] && $v !== null) ?: null,
            'created_at' => now(),
        ]);
    }

    private function insertResources(Appointment $a, array $allocation, ?CarbonImmutable $holdUntil = null): void
    {
        foreach ($allocation as [$type, $id]) {
            AppointmentResource::create([
                'appointment_id' => $a->id, 'resource_type' => $type, 'resource_id' => $id,
                'starts_at' => $a->starts_at, 'ends_at' => $a->blocked_until ?? $a->ends_at, 'held_until' => $holdUntil,
            ]);
        }
    }

    /** @return list<string> */
    public function describe(array $allocation): array
    {
        return array_map(function ($pair) {
            [$type, $id] = $pair;
            $name = match ($type) {
                'staff' => StaffMember::find($id)?->display_name,
                'room' => Room::find($id)?->name,
                'equipment' => Equipment::find($id)?->name,
                default => null,
            };

            return (AppointmentResource::TYPES[$type] ?? $type).': '.($name ?? '#'.$id);
        }, $allocation);
    }

    /**
     * Doar în teste: lărgește fereastra dintre verificare și inserare, ca testul de concurență să poată
     * demonstra că blocările serializează cererile simultane. Fără efect în afara mediului „testing”.
     */
    public static function testDelay(): void
    {
        if (app()->environment('testing') && ($ms = (int) env('SEVALIA_TEST_BOOKING_DELAY_MS', 0)) > 0) {
            usleep($ms * 1000);
        }
    }

    private function result(array $conflicts): array
    {
        return ['conflicts' => $conflicts, 'warnings' => [], 'allocation' => [], 'end' => null, 'blockEnd' => null];
    }
}
