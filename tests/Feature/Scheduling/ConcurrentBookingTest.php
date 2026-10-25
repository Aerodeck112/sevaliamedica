<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Scheduling\Models\AppointmentResource;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;

/**
 * Scenariul 3: două rezervări concurente pentru aceeași resursă, din două procese PHP separate,
 * cu conexiuni proprii la baza de date. Datele trebuie confirmate (commit), de aceea testul nu
 * folosește tranzacția de test, ci golește tabelele la final.
 */
class ConcurrentBookingTest extends BaseTestCase
{
    use DatabaseTruncation, SchedulingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('sevalia:sync-permissions');
        $this->setUpClinic();
    }

    protected function tearDown(): void
    {
        // Datele au fost confirmate de procesele copil: golim tabelele, ca testele următoare (în tranzacție) să pornească curat.
        $db = $this->app['db']->connection();
        $db->statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($db->getSchemaBuilder()->getTableListing($db->getDatabaseName(), false) as $table) {
            if ($table !== 'migrations') {
                $db->table($table)->truncate();
            }
        }
        $db->statement('SET FOREIGN_KEY_CHECKS=1');
        parent::tearDown();
    }

    /** @return list<array> */
    private function race(string $mode, int $processes = 2): array
    {
        $patient = $this->patient();
        $start = microtime(true) + 1.5;
        $env = array_merge(getenv(), [
            'APP_ENV' => 'testing',
            'DB_DATABASE' => config('database.connections.mysql.database'),
            'SEVALIA_TEST_BOOKING_DELAY_MS' => '400',
            'CACHE_STORE' => 'array',
        ]);
        $pipes = [];
        $procs = [];
        for ($i = 0; $i < $processes; $i++) {
            $args = json_encode([
                'start_at' => $start, 'mode' => $mode, 'patient_id' => $patient->id, 'service_id' => $this->altergSession->id,
                'staff_id' => ($i === 0 ? $this->therapist : $this->staff('Terapeut paralel '.$mode.$i, 'terapeut'))->id,
                'location_id' => $this->location->id, 'starts_at' => $this->at('09:00')->format('Y-m-d H:i'),
            ]);
            $procs[$i] = proc_open([PHP_BINARY, base_path('tests/Support/concurrent_book.php'), $args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], base_path(), $env);
        }
        $results = [];
        foreach ($procs as $i => $p) {
            $out = stream_get_contents($pipes[$i][1]);
            $err = stream_get_contents($pipes[$i][2]);
            proc_close($p);
            $results[] = json_decode($out, true) ?? ['ok' => false, 'error' => trim($out.' '.$err)];
        }

        return $results;
    }

    public function test_only_one_of_two_simultaneous_bookings_for_the_same_equipment_succeeds(): void
    {
        // Doi terapeuți diferiți, același aparat (unic), același interval, în același moment.
        $results = $this->race('locked');
        $ok = array_values(array_filter($results, fn ($r) => $r['ok']));
        $this->assertCount(1, $ok, 'Exact o rezervare trebuie să reușească: '.json_encode($results, JSON_UNESCAPED_UNICODE));
        $failed = array_values(array_filter($results, fn ($r) => ! $r['ok']));
        $this->assertStringContainsString('aparat', implode(' ', $failed[0]['conflicts'] ?? [$failed[0]['error'] ?? '']));
        $this->assertSame(1, AppointmentResource::where('resource_type', 'equipment')->whereNull('released_at')->count());
    }

    public function test_negative_control_without_locks_shows_the_race_window_exists(): void
    {
        // Fără blocări, aceeași cursă produce o dublă rezervare — dovada că testul de mai sus verifică ceva real.
        $results = $this->race('naive');
        $this->assertCount(2, array_filter($results, fn ($r) => $r['ok']), json_encode($results, JSON_UNESCAPED_UNICODE));
    }
}
