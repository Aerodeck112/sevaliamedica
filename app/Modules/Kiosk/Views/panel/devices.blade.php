@extends('layouts.panou')
@section('title', 'Tablete recepție')
@section('content')
    <x-panou.page-head title="Tablete recepție" sub="Tabletele pe care pacienții semnează consimțăminte și lasă recenzii. O tabletă se asociază o singură dată; tableta nu are acces la panou și nu afișează date medicale." />
    @if (session('pairing'))
        <div class="alert alert--info" role="status">
            <x-panou.icon name="link" />
            <div>
                <strong>Cod de asociere pentru „{{ session('pairing.device') }}”: <span style="font-size:1.4rem;letter-spacing:.1em">{{ session('pairing.code') }}</span></strong>
                <p style="margin:.3rem 0 0">Pe tabletă deschideți <code>{{ url('/tableta') }}</code> și introduceți codul. Este valabil 30 de minute și se afișează o singură dată.</p>
            </div>
        </div>
    @endif
    <div class="grid grid--aside">
        <x-panou.panel title="Tablete" :flush="true">
            @if ($devices->isEmpty())
                <x-panou.empty title="Nicio tabletă">Adăugați prima tabletă din formularul alăturat.</x-panou.empty>
            @else
                <div class="table-wrap"><table class="table">
                    <thead><tr><th>Nume</th><th>Folosită pentru</th><th>Locație</th><th>Stare</th><th>Ultima activitate</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($devices as $d)
                        <tr>
                            <td><strong>{{ $d->name }}</strong></td>
                            <td>{{ \App\Modules\Kiosk\Models\KioskDevice::MODES[$d->mode] }}</td>
                            <td>{{ $d->location?->name ?? '—' }}</td>
                            <td><x-panou.badge :tone="match($d->statusLabel()) { 'conectată' => 'ok', 'asociată' => 'accent', 'neasociată' => 'warn', default => null }">{{ $d->statusLabel() }}</x-panou.badge></td>
                            <td class="num small">{{ $d->last_seen_at?->format('d.m.Y H:i') ?? '—' }}</td>
                            <td class="text-right">
                                @unless ($d->revoked_at)
                                    <div class="cluster" style="justify-content:flex-end">
                                        <form method="post" action="{{ route('panou.kiosk.devices.pairing', $d) }}">@csrf<button class="btn btn--small" type="submit">{{ $d->paired_at ? 'Reasociază' : 'Cod de asociere' }}</button></form>
                                        <form method="post" action="{{ route('panou.kiosk.devices.revoke', $d) }}" data-confirm="Deconectați tableta „{{ $d->name }}”? Nu va mai putea fi folosită.">@csrf<button class="btn btn--small btn--danger" type="submit">Deconectează</button></form>
                                    </div>
                                @endunless
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
            @endif
        </x-panou.panel>
        <div class="stack">
            <x-panou.panel title="Tabletă nouă">
                <form method="post" action="{{ route('panou.kiosk.devices.store') }}" class="stack">
                    @csrf
                    <x-panou.field name="name" label="Nume" placeholder="ex. Tableta consimțăminte — Unirii" required />
                    <x-panou.field name="mode" type="select" label="Folosită pentru" :options="\App\Modules\Kiosk\Models\KioskDevice::MODES" :placeholder="false" value="ambele" />
                    <x-panou.field name="location_id" type="select" label="Locație" :options="$locations" />
                    <button class="btn btn--primary" type="submit">Adaugă și generează codul</button>
                </form>
            </x-panou.panel>
            <x-panou.panel title="Setări">
                <form method="post" action="{{ route('panou.kiosk.settings') }}" class="stack">
                    @csrf @method('put')
                    <x-panou.field name="code_minutes" type="number" label="Valabilitatea codului de 6 cifre (minute)" :value="$codeMinutes" required />
                    <x-panou.field name="google_review_url" type="url" label="Link pentru recenzie Google (opțional)" :value="$googleReviewUrl"
                        hint="Din Google Business Profile → „Cereți recenzii”. După orice recenzie (indiferent de nota acordată) tableta arată un cod QR către acest link — conform regulilor Google, nu se cer recenzii doar de la pacienții mulțumiți." />
                    <button class="btn" type="submit">Salvează</button>
                </form>
            </x-panou.panel>
        </div>
    </div>
@endsection
