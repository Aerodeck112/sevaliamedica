<x-panou.panel title="Servicii efectuate" :flush="true">
    <div class="panel__body"><p class="small muted">Servicii înregistrate explicit de personal. Sursa de plată „CAS” intră în pregătirea raportării; o greșeală se corectează prin anulare cu motiv, nu prin ștergere.</p></div>
    @if ($records->isEmpty())
        <x-panou.empty title="Niciun serviciu înregistrat">Serviciile se înregistrează din pagina programării, după sosirea pacientului.</x-panou.empty>
    @else
        @include('scheduling::partials.service-records', ['records' => $records])
    @endif
    @can('services.record')
        <x-slot:footer>
            <details>
                <summary class="link-btn">Înregistrează un serviciu fără programare</summary>
                <form method="post" action="{{ route('panou.service-records.manual', $patient) }}" style="margin-top:1rem">
                    @csrf
                    <div class="form-grid form-grid--3">
                        <x-panou.field name="service_id" type="select" label="Serviciu" :options="\App\Modules\Clinic\Models\Service::active()->pluck('name', 'id')->all()" required />
                        <x-panou.field name="staff_member_id" type="select" label="Efectuat de" :options="\App\Modules\Clinic\Models\StaffMember::active()->pluck('display_name', 'id')->all()" required />
                        <x-panou.field name="location_id" type="select" label="Locație" :options="\App\Modules\Clinic\Models\Location::active()->pluck('name', 'id')->all()" required :placeholder="false" />
                        <x-panou.field name="performed_at" type="datetime-local" label="Data și ora" :value="now()->format('Y-m-d\TH:i')" required />
                        <x-panou.field name="payer_type" type="select" label="Sursa de plată" :options="\App\Modules\Scheduling\Models\ServiceRecord::PAYER_TYPES" required :placeholder="false" />
                        <x-panou.field name="insurer_name" label="Asigurător (dacă e cazul)" />
                        <x-panou.field name="quantity" type="number" label="Cantitate" value="1" required />
                        <x-panou.field name="manual_reason" label="De ce fără programare?" required full />
                    </div>
                    <div class="form-actions"><button class="btn btn--primary" type="submit">Înregistrează</button></div>
                </form>
            </details>
        </x-slot:footer>
    @endcan
</x-panou.panel>
