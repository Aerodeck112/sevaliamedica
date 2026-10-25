<?php

namespace Database\Seeders\Demo;

use App\Models\User;
use App\Modules\Clinic\Models\Equipment;
use App\Modules\Clinic\Models\Location;
use App\Modules\Clinic\Models\Room;
use App\Modules\Clinic\Models\Service;
use App\Modules\Clinic\Models\Specialty;
use App\Modules\Clinic\Models\StaffMember;
use App\Modules\Identity\Roles;
use Database\Seeders\ClinicPublicDataSeeder;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Date DEMONSTRATIVE, fictive. Nu rulează în producție.
 * Conturi: <rol>@demo.sevalia.invalid, parola „Demo-Sevalia-2026”, MFA cu secret fix (vezi docs/DEMO.md).
 * Sălile, aparatele, duratele și tarifele de aici sunt exemple, NU date confirmate ale clinicii.
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'Demo-Sevalia-2026';

    public const MFA_SECRET = 'DEMOSEVALIAMFAXXSECRETKEYABCDEFG';

    public function run(): void
    {
        if (app()->isProduction() || config('sevalia.stage') === 'production') {
            throw new RuntimeException('Datele demonstrative nu pot fi încărcate în producție.');
        }

        $this->call(ClinicPublicDataSeeder::class);

        foreach (Roles::all() as $role => $label) {
            $user = User::firstOrCreate(['email' => str_replace('_', '-', $role).'@demo.sevalia.invalid'], [
                'name' => 'Demo '.$label,
                'password' => self::PASSWORD,
                'is_active' => true,
            ]);
            $user->forceFill([
                'mfa_secret' => self::MFA_SECRET,
                'mfa_confirmed_at' => now(),
                'mfa_recovery_codes' => ['DEMO-0000-0001', 'DEMO-0000-0002'],
                'password_changed_at' => now(),
            ])->save();
            $user->syncRoles([$role]);
        }

        // Legăm medicii publici de conturi demo, pentru a putea demonstra „pacienții mei”.
        $medic = User::where('email', 'medic@demo.sevalia.invalid')->first();
        StaffMember::where('display_name', 'dr. Kinga Fülöp')->whereNull('user_id')->update(['user_id' => $medic->id]);

        $terapeutUser = User::where('email', 'terapeut@demo.sevalia.invalid')->first();
        $terapeut = StaffMember::firstOrCreate(['display_name' => 'Andrei Demo (terapeut fictiv)'], [
            'kind' => 'terapeut', 'first_name' => 'Andrei', 'last_name' => 'Demo',
            'professional_title' => 'fizioterapeut (date fictive)', 'user_id' => $terapeutUser->id, 'calendar_color' => '#7A5AF8',
        ]);
        $terapeut2 = StaffMember::firstOrCreate(['display_name' => 'Ioana Exemplu (terapeut fictiv)'], [
            'kind' => 'terapeut', 'first_name' => 'Ioana', 'last_name' => 'Exemplu',
            'professional_title' => 'kinetoterapeut (date fictive)', 'calendar_color' => '#F79009',
        ]);

        $unirii = Location::where('slug', 'cartier-unirii')->first();
        $maurer = Location::where('slug', 'cartier-maurer')->first();
        $terapeut->locations()->syncWithoutDetaching([$unirii->id]);
        $terapeut2->locations()->syncWithoutDetaching([$unirii->id, $maurer->id]);

        $rooms = [
            [$unirii, 'Cabinet 1 (demo)', 'cabinet', 1],
            [$unirii, 'Cabinet 2 (demo)', 'cabinet', 1],
            [$unirii, 'Sala kinetoterapie (demo)', 'kinetoterapie', 4],
            [$unirii, 'Sala fizioterapie (demo)', 'fizioterapie', 3],
            [$maurer, 'Cabinet Maurer (demo)', 'cabinet', 1],
            [$maurer, 'Sala recuperare Maurer (demo)', 'kinetoterapie', 3],
        ];
        foreach ($rooms as [$loc, $name, $kind, $cap]) {
            Room::firstOrCreate(['name' => $name], ['location_id' => $loc->id, 'kind' => $kind, 'capacity' => $cap]);
        }
        $fizio = Room::where('name', 'Sala fizioterapie (demo)')->first();
        foreach ([['AlterG VIA (demo)', 'alterg'], ['Shockwave (demo)', 'shockwave'], ['TECAR (demo)', 'tecar'], ['Laser (demo) #1', 'laser'], ['Laser (demo) #2', 'laser']] as [$name, $kind]) {
            Equipment::firstOrCreate(['name' => $name], ['location_id' => $unirii->id, 'room_id' => $fizio->id, 'kind' => $kind]);
        }

        $reab = Specialty::where('slug', 'reabilitare-medicala')->first();
        $reum = Specialty::where('slug', 'reumatologie')->first();
        $services = [
            ['DEMO-CONS-REUM', 'Consultație reumatologie (demo)', 'consultatie', $reum, 'medic', 30, 0, []],
            ['DEMO-CONS-REAB', 'Consultație reabilitare medicală (demo)', 'consultatie', $reab, 'medic', 30, 0, [['room', 'cabinet']]],
            ['DEMO-KINETO', 'Ședință kinetoterapie (demo)', 'sedinta', $reab, 'terapeut', 45, 15, [['room', 'kinetoterapie']]],
            ['DEMO-ALTERG', 'Ședință AlterG (demo)', 'sedinta', $reab, 'terapeut', 30, 10, [['room', 'fizioterapie'], ['equipment', 'alterg']]],
            ['DEMO-LASER', 'Laserterapie (demo)', 'sedinta', $reab, 'terapeut', 15, 5, [['room', 'fizioterapie'], ['equipment', 'laser']]],
        ];
        foreach ($services as [$code, $name, $kind, $spec, $staffKind, $dur, $buf, $reqs]) {
            $service = Service::firstOrCreate(['code' => $code], [
                'name' => $name, 'kind' => $kind, 'specialty_id' => $spec?->id, 'staff_kind' => $staffKind,
                'duration_minutes' => $dur, 'buffer_minutes' => $buf, 'online_booking' => $kind === 'consultatie',
                'price_note' => 'tarif fictiv, doar pentru demonstrație', 'price' => 100,
            ]);
            foreach ($reqs as [$type, $rk]) {
                $service->requirements()->firstOrCreate(['resource_type' => $type, 'resource_kind' => $rk], ['quantity' => 1]);
            }
        }

        $this->call(SchedulingDemoSeeder::class);
    }
}
