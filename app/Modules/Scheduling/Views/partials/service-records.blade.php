<div class="table-wrap">
    <table class="table">
        <thead><tr><th>Data</th><th>Serviciu</th><th>Efectuat de</th><th>Sursa de plată</th><th>Cant.</th><th>Stare</th><th></th></tr></thead>
        <tbody>
        @foreach ($records as $r)
            <tr>
                <td class="num">{{ $r->performed_at->format('d.m.Y H:i') }}</td>
                <td>{{ $r->service?->name }}@if ($r->manual_reason)<div class="small muted">fără programare: {{ $r->manual_reason }}</div>@endif @if ($r->corrects_id)<div class="small muted">corectează #{{ $r->corrects_id }}</div>@endif</td>
                <td>{{ $r->staffMember?->display_name }}</td>
                <td>{{ $r->payerLabel() }}{{ $r->insurer_name ? ' — '.$r->insurer_name : '' }}</td>
                <td class="num">{{ $r->quantity }}</td>
                <td>
                    @if ($r->status === 'efectuat')
                        <x-panou.badge tone="ok">efectuat</x-panou.badge>
                    @else
                        <x-panou.badge>anulat</x-panou.badge>
                        <div class="small muted">{{ $r->cancel_reason }}@if ($r->corrected_by_id) · înlocuit de #{{ $r->corrected_by_id }}@endif</div>
                    @endif
                </td>
                <td class="text-right">
                    @if ($r->status === 'efectuat')
                        @can('services.correct')
                            <details>
                                <summary class="btn btn--small">Anulează / corectează</summary>
                                <form method="post" action="{{ route('panou.service-records.cancel', $r) }}" class="stack" style="margin-top:.5rem;min-width:260px;text-align:left">
                                    @csrf
                                    <x-panou.field name="cancel_reason" :id="'cr_'.$r->id" label="Motiv" required />
                                    <x-panou.field name="replace" :id="'rp_'.$r->id" type="checkbox" label="Înlocuiește cu o înregistrare corectă" />
                                    <x-panou.field name="replacement_service_id" :id="'rs_'.$r->id" type="select" label="Serviciul corect" :value="$r->service_id"
                                        :options="\App\Modules\Clinic\Models\Service::active()->pluck('name', 'id')->all()" />
                                    <x-panou.field name="replacement_payer_type" :id="'rpt_'.$r->id" type="select" label="Sursa de plată corectă" :value="$r->payer_type"
                                        :options="\App\Modules\Scheduling\Models\ServiceRecord::PAYER_TYPES" :placeholder="false" />
                                    <button class="btn btn--danger btn--small" type="submit">Confirmă</button>
                                </form>
                            </details>
                        @endcan
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
