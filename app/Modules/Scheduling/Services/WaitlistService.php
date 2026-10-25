<?php

namespace App\Modules\Scheduling\Services;

use App\Core\Audit\Audit;
use App\Models\User;
use App\Modules\Scheduling\Events\WaitlistSlotOffered;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\WaitlistEntry;
use App\Modules\Scheduling\Models\WaitlistOffer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class WaitlistService
{
    /**
     * Intrările deschise compatibile cu un interval eliberat (serviciu, persoană, locație, zi, oră),
     * ordonate după prioritate și vechime.
     *
     * @return Collection<int, WaitlistEntry>
     */
    public function matchesFor(Appointment $freed): Collection
    {
        if (! $freed->starts_at) {
            return collect();
        }

        return WaitlistEntry::open()->with(['patient', 'service', 'staffMember'])
            ->where('patient_id', '!=', $freed->patient_id ?? 0)
            ->where(fn ($q) => $q->whereNull('service_id')->orWhere('service_id', $freed->service_id))
            ->where(fn ($q) => $q->whereNull('staff_member_id')->orWhere('staff_member_id', $freed->staff_member_id))
            ->where(fn ($q) => $q->whereNull('location_id')->orWhere('location_id', $freed->location_id))
            ->orderBy('priority')->orderBy('created_at')
            ->get()
            ->filter(fn (WaitlistEntry $e) => $this->fitsPreferences($e, $freed->starts_at, $freed->ends_at))
            ->values();
    }

    public function fitsPreferences(WaitlistEntry $entry, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        $p = $entry->preferences ?? [];
        if (! empty($p['weekdays']) && ! in_array($start->isoWeekday(), array_map('intval', $p['weekdays']), true)) {
            return false;
        }
        if (! empty($p['from']) && $start->format('H:i') < $p['from']) {
            return false;
        }
        if (! empty($p['to']) && $end->format('H:i') > $p['to']) {
            return false;
        }

        return true;
    }

    public function offer(WaitlistEntry $entry, CarbonImmutable $start, CarbonImmutable $end, ?int $staffId, ?int $locationId, ?int $freedAppointmentId, User $user): WaitlistOffer
    {
        $offer = $entry->offers()->create([
            'slot_start' => $start, 'slot_end' => $end, 'staff_member_id' => $staffId, 'location_id' => $locationId,
            'freed_appointment_id' => $freedAppointmentId, 'offered_by' => $user->id,
        ]);
        $entry->update(['status' => 'oferit']);
        Audit::log('scheduling.waitlist_offered', $offer, ['interval' => $start->format('Y-m-d H:i')], $entry->patient_id);
        event(new WaitlistSlotOffered($offer));

        return $offer;
    }

    public function markBooked(WaitlistEntry $entry, Appointment $appointment): void
    {
        $entry->update(['status' => 'programat', 'appointment_id' => $appointment->id]);
        $entry->offers()->where('outcome', 'in_asteptare')->update(['outcome' => 'acceptat']);
        Audit::log('scheduling.waitlist_booked', $entry, ['programare' => $appointment->id], $entry->patient_id);
    }
}
