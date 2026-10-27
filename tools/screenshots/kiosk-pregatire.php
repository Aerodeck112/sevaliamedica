<?php

// Pregătește date pentru capturile tabletelor, NUMAI pe baza demonstrativă (refuză în producție).
//   php tools/screenshots/kiosk-pregatire.php asociere <id-tabletă>   → cod de asociere
//   php tools/screenshots/kiosk-pregatire.php consimtamant            → cod de 6 cifre pentru un pacient demo
//   php tools/screenshots/kiosk-pregatire.php recenzie <id-tabletă>   → recenzie trimisă direct pe tabletă
// Afișează un JSON pe o linie.

use App\Models\User;
use App\Modules\Kiosk\Models\KioskDevice;
use App\Modules\Kiosk\Models\PatientReview;
use App\Modules\Kiosk\Services\KioskService;
use App\Modules\Medical\Models\ConsentTemplateVersion;
use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Models\ServiceRecord;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (app()->isProduction() || config('sevalia.stage') === 'production') {
    fwrite(STDERR, "Refuz: doar pe baza demonstrativă.\n");
    exit(1);
}

$kiosk = app(KioskService::class);
$by = User::where('email', 'receptie@demo.sevalia.invalid')->firstOrFail();
$out = match ($argv[1] ?? '') {
    'tablete' => KioskDevice::whereNull('revoked_at')->get(['id', 'name', 'mode'])->toArray(),
    'asociere' => (function () use ($argv) {
        $code = 'DEMO'.random_int(1000, 9999);
        KioskDevice::findOrFail($argv[2])->forceFill(['pairing_code_hash' => KioskService::hashCode($code), 'pairing_expires_at' => now()->addMinutes(30)])->save();

        return ['cod' => $code];
    })(),
    'consimtamant' => (function () use ($kiosk, $by) {
        $patient = Patient::where('email', 'like', '%@demo.sevalia.invalid')->orderBy('id')->firstOrFail();
        [$session, $code] = $kiosk->createConsentSession($patient, ConsentTemplateVersion::latest('id')->firstOrFail(), $by);

        return ['cod' => $code, 'sesiune' => $session->id];
    })(),
    'recenzie' => (function () use ($kiosk, $by, $argv) {
        $record = ServiceRecord::where('status', 'efectuat')->whereNotIn('id', PatientReview::whereNotNull('service_record_id')->pluck('service_record_id'))->orderBy('id')->firstOrFail();
        [$session] = $kiosk->createReviewSession($record, $by, KioskDevice::findOrFail($argv[2]), true);

        return ['sesiune' => $session->id];
    })(),
    default => throw new InvalidArgumentException('Comandă necunoscută.'),
};
echo json_encode($out, JSON_UNESCAPED_UNICODE), "\n";
