@php $active = $alerts->whereNull('ended_at'); $ended = $alerts->whereNotNull('ended_at'); @endphp
<div class="grid grid--aside">
    <x-panou.panel title="Fișe medicale" :flush="true">
        @can('medical.write')
            <x-slot:actions>
                <a class="btn btn--primary btn--small" href="{{ route('panou.medical.records.create', $patient) }}"><x-panou.icon name="plus" />Fișă nouă</a>
            </x-slot:actions>
        @endcan
        @if ($records->isEmpty())
            <x-panou.empty title="Nicio fișă">Fișele redactate de medici pentru acest pacient apar aici.</x-panou.empty>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Data</th><th>Fișă</th><th>Autor</th><th>Status</th><th>Corecturi</th></tr></thead>
                    <tbody>
                    @foreach ($records as $r)
                        @php $amendments = $r->amendments->where('status', \App\Modules\Medical\Models\MedicalRecord::FINAL); @endphp
                        <tr>
                            <td class="num">{{ ($r->finalized_at ?? $r->created_at)->format('d.m.Y') }}</td>
                            <td><a class="row-link" href="{{ route('panou.medical.records.show', $r) }}">{{ $r->title }}</a><div class="small muted">{{ $r->kindLabel() }}@if ($r->specialty) · {{ $r->specialty->name }}@endif</div></td>
                            <td>{{ $r->staffMember?->display_name }}</td>
                            <td><x-panou.badge :tone="$r->statusTone()">{{ $r->statusLabel() }}</x-panou.badge></td>
                            <td class="small">
                                @if ($amendments->isEmpty())—@else
                                    {{ $amendments->where('kind', 'rectificare')->count() }} rectificări, {{ $amendments->where('kind', 'completare')->count() }} completări
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-panou.panel>

    <x-panou.panel title="Avertizări clinice">
        <p class="small muted">Introduse de clinicieni. Apar și în calendar, doar pentru personalul care îngrijește pacientul. Aplicația nu generează avertizări.</p>
        @forelse ($active as $alert)
            <div class="alert alert--{{ $alert->severity === 'critic' ? 'danger' : ($alert->severity === 'info' ? 'info' : 'warn') }}" style="margin-bottom:.6rem">
                <x-panou.icon name="warn" />
                <div style="flex:1">
                    <strong>{{ $alert->severityLabel() }}:</strong> {{ $alert->text }}
                    <div class="small muted">{{ $alert->staffMember?->display_name ?? $alert->creator?->name }} · {{ $alert->created_at->format('d.m.Y') }}</div>
                    @can('medical.write')
                        <details style="margin-top:.4rem">
                            <summary class="small">Încheie avertizarea</summary>
                            <form method="post" action="{{ route('panou.medical.alerts.end', $alert) }}" class="cluster" style="margin-top:.4rem">
                                @csrf
                                <input type="text" name="reason" required minlength="5" placeholder="Motiv" aria-label="Motivul încheierii" style="flex:1">
                                <button class="btn btn--small" type="submit">Încheie</button>
                            </form>
                        </details>
                    @endcan
                </div>
            </div>
        @empty
            <p>Nicio avertizare activă.</p>
        @endforelse
        @can('medical.write')
            <form method="post" action="{{ route('panou.medical.alerts.store', $patient) }}" class="stack" style="margin-top:1rem">
                @csrf
                <x-panou.field name="severity" type="select" label="Severitate" :options="\App\Modules\Medical\Models\ClinicalAlert::SEVERITIES" value="atentie" :placeholder="false" />
                <x-panou.field name="text" label="Avertizare" required placeholder="ex. alergie declarată la …" />
                <button class="btn" type="submit">Adaugă avertizarea</button>
            </form>
        @endcan
        @if ($ended->isNotEmpty())
            <details style="margin-top:1rem">
                <summary class="small">Avertizări încheiate ({{ $ended->count() }})</summary>
                <ul class="small">
                    @foreach ($ended as $alert)
                        <li>{{ $alert->severityLabel() }}: {{ $alert->text }} — încheiată {{ $alert->ended_at->format('d.m.Y') }} ({{ $alert->end_reason }})</li>
                    @endforeach
                </ul>
            </details>
        @endif
    </x-panou.panel>
</div>
