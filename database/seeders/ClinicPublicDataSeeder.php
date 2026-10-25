<?php

namespace Database\Seeders;

use App\Modules\Clinic\Models\Location;
use App\Modules\Clinic\Models\Specialty;
use App\Modules\Clinic\Models\StaffMember;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Date publicate pe www.sevalia.ro (preluate 2026-10-25): locații, program, specialități, echipă.
 * Nu sunt date demonstrative și nu sunt confirmări CAS. Rularea e idempotentă și NU suprascrie
 * modificările făcute ulterior din panou (folosește firstOrCreate).
 */
class ClinicPublicDataSeeder extends Seeder
{
    public function run(): void
    {
        $weekdays = fn (string $close) => [
            '1' => [['07:00', $close]], '2' => [['07:00', $close]], '3' => [['07:00', $close]], '4' => [['07:00', $close]],
            '5' => [['07:00', '19:00']],
        ];

        $unirii = Location::firstOrCreate(['slug' => 'cartier-unirii'], [
            'name' => 'Locație cartier UNIRII',
            'address' => 'str. Constandin Hagi Stoian, nr. 3C',
            'city' => 'Târgu Mureș',
            'county' => 'Mureș',
            'phone' => '0365 403 001',
            'email' => 'office@sevalia.ro',
            'map_query' => 'Str. Constandin Hagi Stoian, nr. 3',
            'opening_hours' => $weekdays('22:00'),
            'sort' => 1,
        ]);
        $maurer = Location::firstOrCreate(['slug' => 'cartier-maurer'], [
            'name' => 'Locație cartier MAURER',
            'address' => 'str. Ion Heliade Rădulescu B26 (lângă grădiniță)',
            'city' => 'Târgu Mureș',
            'county' => 'Mureș',
            'phone' => '0741 558 558',
            'email' => 'office@sevalia.ro',
            'map_query' => 'str. Ion Heliade Radulescu, nr. 86',
            'opening_hours' => $weekdays('21:00'),
            'sort' => 2,
        ]);

        // Ordinea din meniul „Specialitate medicală” și din formularul de contact.
        $specialties = [];
        foreach (['Reumatologie', 'Reabilitare medicală', 'Medicină internă', 'Pediatrie', 'Psihiatrie', 'Neurologie', 'Cardiologie pediatrică'] as $i => $name) {
            $specialties[$name] = Specialty::firstOrCreate(['slug' => Str::slug($name)], ['name' => $name, 'sort' => $i + 1]);
        }

        // Ordinea și textele de pe /echipa-noastra.
        $team = [
            ['dr. Cristina Pop', 'Cristina', 'Pop', 'medic specialist reumatolog', 'Reumatologie'],
            ['dr. Kinga Fülöp', 'Kinga', 'Fülöp', 'medic specialist reabilitare medicală', 'Reabilitare medicală'],
            ['dr. Székely-Varga Margit', 'Margit', 'Székely-Varga', 'medic specialist recuperare, medicină fizică și balneologie', 'Reabilitare medicală'],
            ['dr. Moldovan Kitti', 'Kitti', 'Moldovan', 'medic specialist reumatolog', 'Reumatologie'],
            ['dr. Mureșan-Varro Emma-Sarolta', 'Emma-Sarolta', 'Mureșan-Varro', 'medic specialist medicină internă', 'Medicină internă'],
            ['dr. Voicu Maria', 'Maria', 'Voicu', 'medic specialist reabilitare medicală', 'Reabilitare medicală'],
            ['dr. Boilă Ioana', 'Ioana', 'Boilă', 'medic specialist pediatru', 'Pediatrie'],
            ['dr. Orban Emese', 'Emese', 'Orban', 'medic specialist neurolog', 'Neurologie'],
            ['dr. Bodo Cristina', 'Cristina', 'Bodo', 'medic specialist psihiatru', 'Psihiatrie'],
            ['dr. Crauciuc Andrei', 'Andrei', 'Crauciuc', 'medic specialist cardiolog pediatru', 'Cardiologie pediatrică'],
        ];
        foreach ($team as $i => [$display, $first, $last, $title, $specialty]) {
            $member = StaffMember::firstOrCreate(['display_name' => $display], [
                'kind' => 'medic',
                'honorific' => 'dr.',
                'first_name' => $first,
                'last_name' => $last,
                'professional_title' => $title,
                'show_on_site' => true,
                'site_sort' => $i + 1,
            ]);
            if ($member->wasRecentlyCreated) {
                $member->specialties()->sync([$specialties[$specialty]->id]);
                $member->locations()->sync([$unirii->id, $maurer->id]);
            }
        }
    }
}
