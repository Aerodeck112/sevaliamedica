<div class="stack">
    <x-panou.panel title="Programări" :flush="true">
        <x-slot:actions>
            @can('scheduling.manage')
                <a class="btn btn--small btn--primary" href="{{ route('panou.appointments.create', ['patient_id' => $patient->id]) }}"><x-panou.icon name="plus" />Programare</a>
                <a class="btn btn--small" href="{{ route('panou.series.create', ['patient_id' => $patient->id]) }}">Serie de ședințe</a>
            @endcan
            @can('waitlist.manage')
                <a class="btn btn--small" href="{{ route('panou.waitlist.index', ['patient_id' => $patient->id]) }}">Listă de așteptare</a>
            @endcan
        </x-slot:actions>
        @if ($appointments->isEmpty())
            <x-panou.empty title="Nicio programare">Programările pacientului apar aici.</x-panou.empty>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Data</th><th>Serviciu</th><th>Persoana</th><th>Locație</th><th>Status</th></tr></thead>
                    <tbody>
                    @foreach ($appointments as $a)
                        <tr>
                            <td class="num"><a class="row-link" href="{{ route('panou.appointments.show', $a) }}">{{ $a->starts_at?->format('d.m.Y H:i') ?? 'fără interval' }}</a></td>
                            <td>{{ $a->service?->name ?? '—' }}@if ($a->series_id)<div class="small muted">ședința {{ $a->series_index }}</div>@endif</td>
                            <td>{{ $a->staffMember?->display_name ?? '—' }}</td>
                            <td>{{ $a->location?->name ?? '—' }}</td>
                            <td>@include('scheduling::partials.status', ['status' => $a->status])</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-panou.panel>

    @if ($series->isNotEmpty())
        <x-panou.panel title="Serii de ședințe" :flush="true">
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Serviciu</th><th>Efectuate / planificate</th><th>Rămase</th><th>Stare</th></tr></thead>
                    <tbody>
                    @foreach ($series as $s)
                        @php $c = $s->counts(); @endphp
                        <tr>
                            <td><a class="row-link" href="{{ route('panou.series.show', $s) }}">{{ $s->service->name }}</a></td>
                            <td class="num">{{ $c['done'] }} / {{ $c['planned'] }}</td>
                            <td class="num">{{ $c['remaining'] }}</td>
                            <td>{{ $s->status === 'activa' ? 'activă' : 'închisă' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </x-panou.panel>
    @endif

    @if ($waitlist->isNotEmpty())
        <x-panou.panel title="În lista de așteptare">
            <ul style="margin:0;padding-left:1rem">
                @foreach ($waitlist as $w)
                    <li>{{ $w->service?->name ?? 'orice serviciu' }} · {{ $w->preferenceSummary() }} · {{ \App\Modules\Scheduling\Models\WaitlistEntry::STATUSES[$w->status] }}</li>
                @endforeach
            </ul>
        </x-panou.panel>
    @endif
</div>
