<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Programarea dumneavoastră — SEVALIA</title>
    <link rel="icon" href="/favicon.ico">
    @vite(['resources/css/panou.css'])
</head>
<body class="panou" style="background:var(--mist)">
<main class="content" style="max-width:560px;margin:0 auto;padding-top:2.5rem">
    <p><img src="/images/sevalia-logo.webp" alt="SEVALIA" width="140" height="34"></p>
    @include('components.panou.flash')
    <x-panou.panel>
        @if (! $t)
            <h1 style="font-size:1.4rem">Linkul nu este valid</h1>
            <p>Verificați că ați copiat linkul complet sau contactați clinica telefonic.</p>
        @else
            @php $a = $appointment; @endphp
            <h1 style="font-size:1.4rem">Programarea dumneavoastră</h1>
            <dl class="facts" style="margin:1rem 0">
                <dt>Data</dt><dd>{{ $a->starts_at?->locale('ro')->isoFormat('dddd, D MMMM YYYY') }}</dd>
                <dt>Ora</dt><dd>{{ $a->starts_at?->format('H:i') }}</dd>
                <dt>Locație</dt><dd>{{ $a->location?->name }}@if ($a->location?->address)<br><span class="small muted">{{ $a->location->address }}</span>@endif</dd>
                <dt>Serviciu</dt><dd>{{ $a->service?->kindLabel() ?? 'programare' }}</dd>
                <dt>Stare</dt><dd>{{ $a->status === 'anulata' ? 'anulată' : ($a->status === 'confirmata' ? 'confirmată' : \App\Modules\Scheduling\Support\AppointmentStatus::label($a->status)) }}</dd>
            </dl>

            @if ($canCancel)
                <form method="post" action="{{ route('patient-link.cancel', $token) }}" data-confirm="Sigur doriți să anulați programarea?">
                    @csrf <button class="btn btn--danger" type="submit">Anulez programarea</button>
                </form>
            @elseif ($a->status === 'confirmata' && $valid)
                <p class="small muted">Anularea online nu mai este posibilă atât de aproape de ora programării. Vă rugăm să sunați la {{ $a->location?->phone ?? 'clinică' }}.</p>
            @endif

            @if ($valid && $a->status === 'confirmata')
                <hr class="sep">
                <h2 style="font-size:1.1rem;margin-bottom:.5rem">Doriți altă zi sau oră?</h2>
                <form method="post" action="{{ route('patient-link.reschedule', $token) }}" class="stack">
                    @csrf
                    <x-panou.field name="message" type="textarea" label="Când v-ar conveni (opțional)" hint="Ex. „marți sau joi după ora 16”. Vă rugăm să nu scrieți informații medicale." />
                    <button class="btn" type="submit">Cer reprogramarea</button>
                </form>
            @elseif (! $valid && $a->status === 'confirmata')
                <p class="small muted">Linkul a expirat. Pentru modificări, contactați clinica telefonic.</p>
            @endif
        @endif
    </x-panou.panel>
</main>
<script nonce="{{ app('csp-nonce') }}">
    document.addEventListener('submit', (e) => { const m = e.target.dataset.confirm; if (m && !confirm(m)) e.preventDefault(); });
</script>
</body>
</html>
