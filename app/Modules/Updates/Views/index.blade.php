@extends('layouts.panou')
@section('title', 'Actualizări')
@section('content')
    <x-panou.page-head title="Actualizări" sub="Pachete ZIP semnate digital. Fiecare instalare face backup verificat al bazei de date înainte de orice modificare și păstrează versiunea anterioară pentru revenire.">
        <x-slot:actions>
            <a class="btn" href="{{ route('panou.updates.environment') }}"><x-panou.icon name="settings" />Verificarea mediului</a>
        </x-slot:actions>
    </x-panou.page-head>

    @if ($active)
        <div class="alert alert--warn" role="status">
            <x-panou.icon name="warn" />
            <div>Actualizarea la versiunea <strong>{{ $active->to_version }}</strong> este {{ mb_strtolower(\App\Modules\Updates\Models\UpdateRun::STATUSES[$active->status]) }}.
                <a href="{{ route('panou.updates.show', $active->uuid) }}">Deschide actualizarea</a></div>
        </div>
    @endif

    <div class="grid grid--2">
        <x-panou.panel title="Versiunea curentă">
            <dl class="facts">
                <dt>Versiune activă</dt><dd class="num">{{ $current }}</dd>
                <dt>Mod de rulare</dt><dd>{{ $deployed ? 'structură releases/ (producție sau staging)' : 'checkout de dezvoltare — pachetele pot fi verificate, nu instalate' }}</dd>
                @if ($pointer)
                    <dt>Activată la</dt><dd class="num">{{ \Carbon\Carbon::parse($pointer['activated_at'])->timezone(config('app.timezone'))->format('d.m.Y H:i') }}</dd>
                    <dt>Versiune anterioară</dt><dd class="num">{{ $pointer['previous'] ?? '—' }}</dd>
                @endif
                <dt>Versiuni păstrate</dt><dd class="num">{{ $releases ? implode(', ', $releases) : '—' }}</dd>
                <dt>Chei de semnare acceptate</dt><dd class="num">{{ $trustedKeys ? implode(', ', $trustedKeys) : 'niciuna configurată' }}</dd>
            </dl>
        </x-panou.panel>

        <x-panou.panel title="Încarcă un pachet">
            @if (! $trustedKeys)
                <div class="alert alert--warn"><x-panou.icon name="warn" /><div>Nu există nicio cheie publică de încredere în <code>config/update-keys/</code>. Niciun pachet nu poate fi acceptat până la adăugarea cheii de release a clinicii.</div></div>
            @endif
            <form method="post" action="{{ route('panou.updates.upload') }}" enctype="multipart/form-data" class="stack">
                @csrf
                <div class="field">
                    <label class="field__label" for="package">Pachet ZIP (sevalia-&lt;versiune&gt;-update.zip)</label>
                    <input id="package" type="file" name="package" accept=".zip,application/zip" required @error('package') aria-invalid="true" aria-describedby="package_e" @enderror>
                    <span class="field__hint">Dimensiune maximă acceptată de server: {{ $maxUpload }}. Pachetul este verificat (semnătură, structură, sume de control) înainte de orice altă acțiune.</span>
                    @error('package')<span class="field__error" id="package_e">{{ $message }}</span>@enderror
                </div>
                <div><button class="btn btn--primary" type="submit"><x-panou.icon name="upload" />Încarcă și verifică</button></div>
            </form>
        </x-panou.panel>

        <x-panou.panel title="Istoric" class="span-2" :flush="true">
            @if ($runs->isEmpty())
                <x-panou.empty title="Nicio actualizare încă">Pachetele încărcate și instalările apar aici, cu rezultatul fiecăruia.</x-panou.empty>
            @else
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>Versiune</th><th>De la</th><th>Stare</th><th>Încărcat de</th><th>Încărcat</th><th>Încheiat</th></tr></thead>
                        <tbody>
                        @foreach ($runs as $run)
                            <tr>
                                <td><a class="row-link" href="{{ route('panou.updates.show', $run->uuid) }}">{{ $run->to_version }}</a> <span class="small muted">{{ $run->package_type === 'install' ? 'instalare' : 'actualizare' }}</span></td>
                                <td class="num">{{ $run->from_version }}</td>
                                <td><x-panou.badge :tone="$run->statusTone()">{{ \App\Modules\Updates\Models\UpdateRun::STATUSES[$run->status] }}</x-panou.badge></td>
                                <td>{{ $run->creator?->name ?? 'consolă' }}</td>
                                <td class="num">{{ $run->created_at->format('d.m.Y H:i') }}</td>
                                <td class="num">{{ $run->finished_at?->format('d.m.Y H:i') ?? '—' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-panou.panel>
    </div>
@endsection
