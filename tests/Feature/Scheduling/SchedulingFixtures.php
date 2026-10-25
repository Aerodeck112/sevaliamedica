<?php

namespace Tests\Feature\Scheduling;

use App\Models\User;
use App\Modules\Clinic\Models\Equipment;
use App\Modules\Clinic\Models\Location;
use App\Modules\Clinic\Models\Room;
use App\Modules\Clinic\Models\Service;
use App\Modules\Clinic\Models\StaffMember;
use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Models\StaffSchedule;
use Carbon\CarbonImmutable;

/** Date de test fictive pentru programări. */
trait SchedulingFixtures
{
    protected Location $location;

    protected StaffMember $medic;

    protected StaffMember $therapist;

    protected Room $cabinet;

    protected Room $gym;

    protected Equipment $alterg;

    protected Service $consult;

    protected Service $altergSession;

    protected Service $gymSession;

    protected function setUpClinic(): void
    {
        $this->location = Location::create(['name' => 'Locație test', 'slug' => 'locatie-test', 'opening_hours' => []]);
        $this->medic = $this->staff('dr. Test Medic', 'medic');
        $this->therapist = $this->staff('Terapeut Test', 'terapeut');
        $this->cabinet = Room::create(['location_id' => $this->location->id, 'name' => 'Cabinet T', 'kind' => 'cabinet', 'capacity' => 1]);
        $this->gym = Room::create(['location_id' => $this->location->id, 'name' => 'Sală T', 'kind' => 'kineto', 'capacity' => 2]);
        $this->alterg = Equipment::create(['location_id' => $this->location->id, 'name' => 'AlterG T', 'kind' => 'alterg']);

        $this->consult = Service::create(['code' => 'T-CONS', 'name' => 'Consultație test', 'kind' => 'consultatie', 'staff_kind' => 'medic', 'duration_minutes' => 30, 'online_booking' => true]);
        $this->consult->requirements()->create(['resource_type' => 'room', 'resource_kind' => 'cabinet', 'quantity' => 1]);
        $this->altergSession = Service::create(['code' => 'T-ALTERG', 'name' => 'Ședință AlterG test', 'kind' => 'sedinta', 'staff_kind' => 'terapeut', 'duration_minutes' => 30, 'buffer_minutes' => 10]);
        $this->altergSession->requirements()->create(['resource_type' => 'equipment', 'resource_kind' => 'alterg', 'quantity' => 1]);
        $this->gymSession = Service::create(['code' => 'T-GYM', 'name' => 'Kinetoterapie test', 'kind' => 'sedinta', 'staff_kind' => 'terapeut', 'duration_minutes' => 45]);
        $this->gymSession->requirements()->create(['resource_type' => 'room', 'resource_kind' => 'kineto', 'quantity' => 1]);
    }

    protected function staff(string $name, string $kind, ?User $user = null): StaffMember
    {
        $s = StaffMember::create(['display_name' => $name, 'first_name' => 'T', 'last_name' => $name, 'kind' => $kind, 'user_id' => $user?->id]);
        $s->locations()->sync([$this->location->id]);
        for ($d = 1; $d <= 7; $d++) {
            StaffSchedule::create(['staff_member_id' => $s->id, 'location_id' => $this->location->id, 'weekday' => $d,
                'start_time' => '08:00', 'end_time' => '18:00', 'breaks' => [['12:00', '12:30']], 'valid_from' => '2020-01-01']);
        }

        return $s;
    }

    protected function patient(array $attrs = []): Patient
    {
        return Patient::factory()->create($attrs);
    }

    /** O dată viitoare la ora dată (mâine). */
    protected function at(string $time, int $daysAhead = 1): CarbonImmutable
    {
        return CarbonImmutable::today()->addDays($daysAhead)->setTimeFromTimeString($time);
    }
}
