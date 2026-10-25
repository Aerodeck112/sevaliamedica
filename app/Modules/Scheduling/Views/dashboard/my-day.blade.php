<x-panou.panel title="Pacienții mei de azi">
    <x-slot:actions><a class="btn btn--small" href="{{ route('panou.calendar', $staff ? ['staff' => [$staff->id]] : []) }}"><x-panou.icon name="calendar" />Calendarul meu</a></x-slot:actions>
    @if (! $staff)
        <x-panou.empty title="Contul nu este legat de o persoană din personal">Administratorul vă poate asocia contul în Administrare → Personal, câmpul „Cont de utilizator”.</x-panou.empty>
    @elseif ($today->isEmpty())
        <x-panou.empty title="Nicio programare azi">Programările dumneavoastră confirmate apar aici.</x-panou.empty>
    @else
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Ora</th><th>Pacient</th><th>Serviciu</th><th>Status</th><th></th></tr></thead>
                <tbody>
                @foreach ($today as $a)
                    <tr>
                        <td class="num">{{ $a->starts_at->format('H:i') }}–{{ $a->ends_at->format('H:i') }}</td>
                        <td>@if ($a->patient)<a class="row-link" href="{{ route('panou.patients.show', $a->patient) }}">{{ $a->patient->fullName() }}</a>@else{{ $a->displayName() }}@endif</td>
                        <td>{{ $a->service?->name }}</td>
                        <td>@include('scheduling::partials.status', ['status' => $a->status])</td>
                        <td class="text-right"><a class="btn btn--small" href="{{ route('panou.appointments.show', $a) }}">Deschide</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
    @if ($series->isNotEmpty())
        <hr class="sep">
        <p class="small muted" style="margin-bottom:.4rem">Serii active</p>
        <ul class="small" style="margin:0;padding-left:1rem">
            @foreach ($series as $s)
                @php $c = $s->counts(); @endphp
                <li><a href="{{ route('panou.series.show', $s) }}">{{ $s->patient->fullName() }} · {{ $s->service->name }}</a> — {{ $c['done'] }}/{{ $c['planned'] }} efectuate, {{ $c['remaining'] }} rămase</li>
            @endforeach
        </ul>
    @endif
</x-panou.panel>
