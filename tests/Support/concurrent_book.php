<?php

/**
 * Proces separat pentru testul de rezervări concurente (tests/Feature/Scheduling/ConcurrentBookingTest.php).
 * Argument: JSON {start_at, mode: locked|naive, patient_id, service_id, staff_id, location_id, starts_at}.
 * Așteaptă momentul start_at, apoi încearcă rezervarea; scrie rezultatul ca JSON pe stdout.
 */

use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AppointmentResource;
use App\Modules\Scheduling\Services\BookingService;
use App\Modules\Scheduling\Support\BookingConflictException;
use App\Modules\Scheduling\Support\BookingRequest;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
$request = new BookingRequest((int) $args['patient_id'], (int) $args['service_id'], (int) $args['staff_id'], (int) $args['location_id'], CarbonImmutable::parse($args['starts_at']));
$booking = app(BookingService::class);

while (microtime(true) < $args['start_at']) {
    usleep(200);
}

try {
    if ($args['mode'] === 'naive') {
        // Control negativ: aceeași verificare, dar FĂRĂ blocări — arată că fereastra de cursă există.
        $id = DB::transaction(function () use ($booking, $request) {
            $eval = $booking->evaluate($request, null, false);
            if ($eval['conflicts']) {
                throw new BookingConflictException($eval['conflicts']);
            }
            BookingService::testDelay();
            $a = Appointment::create(['patient_id' => $request->patientId, 'service_id' => $request->serviceId, 'staff_member_id' => $request->staffMemberId,
                'location_id' => $request->locationId, 'starts_at' => $request->startsAt, 'ends_at' => $eval['end'], 'blocked_until' => $eval['blockEnd'], 'status' => 'confirmata']);
            foreach ($eval['allocation'] as [$type, $rid]) {
                AppointmentResource::create(['appointment_id' => $a->id, 'resource_type' => $type, 'resource_id' => $rid, 'starts_at' => $a->starts_at, 'ends_at' => $a->blocked_until]);
            }

            return $a->id;
        });
    } else {
        $id = $booking->book($request, null)->id;
    }
    echo json_encode(['ok' => true, 'id' => $id]);
} catch (BookingConflictException $e) {
    echo json_encode(['ok' => false, 'conflicts' => $e->messages()]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => get_class($e).': '.$e->getMessage()]);
}
