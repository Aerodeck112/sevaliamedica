<x-panou.panel title="Relații de îngrijire" :flush="true">
    <div class="panel__body">
        <p class="muted small">Datele medicale ale pacientului sunt accesibile personalului care are o programare cu pacientul în ultimele {{ \App\Modules\Patients\Services\CareAccess::setting('care_window_past_days') }} de zile (sau în următoarele {{ \App\Modules\Patients\Services\CareAccess::setting('care_window_future_days') }}), sau o relație explicită de mai jos. Orice alt acces cere procedura de urgență, cu motiv, și este auditat.</p>
    </div>
    @if ($relations->isEmpty())
        <x-panou.empty title="Nicio relație explicită">Relațiile rezultate din programări nu apar aici; ele sunt verificate automat.</x-panou.empty>
    @else
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Persoană</th><th>Sursă</th><th>Motiv</th><th>Perioadă</th><th></th></tr></thead>
                <tbody>
                @foreach ($relations as $r)
                    @php $active = $r->valid_until === null || $r->valid_until->isToday() || $r->valid_until->isFuture(); @endphp
                    <tr>
                        <td>{{ $r->staffMember?->display_name }}</td>
                        <td>{{ \App\Modules\Patients\Models\CareRelation::SOURCES[$r->source] ?? $r->source }}</td>
                        <td class="small">{{ $r->reason }}</td>
                        <td class="num small">{{ $r->valid_from->format('d.m.Y') }} – {{ $r->valid_until?->format('d.m.Y') ?? 'nedeterminat' }}</td>
                        <td class="text-right">
                            @if ($active)
                                @can('patients.care_relations')
                                    <form method="post" action="{{ route('panou.patients.care.end', [$patient, $r]) }}">@csrf<button class="btn btn--small" type="submit">Încheie</button></form>
                                @endcan
                            @else
                                <x-panou.badge>încheiată</x-panou.badge>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
    @can('patients.care_relations')
        <x-slot:footer>
            <form method="post" action="{{ route('panou.patients.care.store', $patient) }}">
                @csrf
                <div class="form-grid form-grid--3">
                    <x-panou.field name="staff_member_id" type="select" label="Persoană" required
                        :options="\App\Modules\Clinic\Models\StaffMember::active()->whereIn('kind', ['medic', 'terapeut', 'asistent'])->pluck('display_name', 'id')->all()" />
                    <x-panou.field name="source" type="select" label="Sursă" required :options="\App\Modules\Patients\Models\CareRelation::SOURCES" :placeholder="false" />
                    <x-panou.field name="reason" label="Motiv" required hint="Fără detalii medicale" />
                    <x-panou.field name="valid_from" type="date" label="De la" :value="now()->toDateString()" required />
                    <x-panou.field name="valid_until" type="date" label="Până la" />
                </div>
                <div class="form-actions"><button class="btn btn--primary" type="submit">Adaugă relația</button></div>
            </form>
        </x-slot:footer>
    @endcan
</x-panou.panel>
