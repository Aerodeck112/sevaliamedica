<?php

namespace Database\Seeders\Demo;

use App\Models\User;
use App\Modules\Clinic\Models\Location;
use App\Modules\Kiosk\Models\KioskDevice;
use App\Modules\Kiosk\Models\PatientReview;
use App\Modules\Scheduling\Models\ServiceRecord;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Tablete și recenzii DEMONSTRATIVE, fictive. Tabletele nu sunt asociate (se asociază din panou cu un cod).
 * Comentariile recenziilor sunt evident fictive și nu provin de la pacienți reali.
 */
class KioskDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction() || config('sevalia.stage') === 'production') {
            throw new RuntimeException('Datele demonstrative nu pot fi încărcate în producție.');
        }

        $admin = User::where('email', 'manager@demo.sevalia.invalid')->first() ?? User::first();
        $location = Location::where('slug', 'cartier-unirii')->first();
        foreach ([['Tabletă recepție — consimțăminte (demo)', 'consimtamant'], ['Tabletă recepție — recenzii (demo)', 'recenzie']] as [$name, $mode]) {
            KioskDevice::firstOrCreate(['name' => $name], ['mode' => $mode, 'location_id' => $location?->id, 'created_by' => $admin?->id]);
        }

        $comments = [
            5 => 'Text demonstrativ: personal amabil, explicații clare.',
            4 => 'Text demonstrativ: totul a decurs bine, am așteptat puțin.',
            3 => null,
        ];
        $ratings = array_keys($comments);
        foreach (ServiceRecord::where('status', 'efectuat')->orderBy('id')->limit(3)->get() as $i => $record) {
            $rating = $ratings[$i % count($ratings)];
            PatientReview::firstOrCreate(['service_record_id' => $record->id], [
                'patient_id' => $record->patient_id,
                'service_id' => $record->service_id,
                'staff_member_id' => $record->staff_member_id,
                'rating' => $rating,
                'comment' => $comments[$rating],
                'publish_consent' => $rating >= 4,
                'source' => 'tableta',
            ]);
        }
    }
}
