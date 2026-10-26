@extends('layouts.panou')
@section('title', 'Planuri de recuperare')
@section('content')
    <x-panou.page-head title="Planuri de recuperare" sub="Planurile întocmite și aprobate de medici, cu evidența ședințelor. Planurile noi se creează din fișa pacientului, tabul Recuperare." />
    <x-panou.panel :flush="true">
        <form class="filters" method="get" data-autosubmit>
            <x-panou.field name="status" type="select" label="Stare" :value="$filters['status'] ?? ''" :options="\App\Modules\Rehab\Models\RehabPlan::STATUSES" placeholder="Deschise (fără încheiate)" />
            @if ($hasStaff)<x-panou.field name="mine" type="checkbox" label="Doar planurile mele" :value="$filters['mine'] ?? false" />@endif
            <noscript><button class="btn" type="submit">Filtrează</button></noscript>
        </form>
        @if ($plans->isEmpty())
            <x-panou.empty title="Niciun plan">Nu există planuri pentru filtrele alese.</x-panou.empty>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Pacient</th><th>Proceduri</th><th>Medic</th><th>Stare</th><th style="min-width:260px">Ședințe</th></tr></thead>
                    <tbody>
                    @foreach ($plans as $plan)
                        @php $p = $progress->forPlan($plan)['totals']; @endphp
                        <tr>
                            <td><a class="row-link" href="{{ route('panou.rehab.plans.show', $plan) }}">{{ $plan->patient->fullName() }}</a><div class="small muted">Plan #{{ $plan->id }} · {{ $plan->location?->name }}</div></td>
                            <td class="small">{{ $plan->items->map(fn ($i) => $i->service->name.' ×'.$i->sessions_count)->join(', ') ?: '—' }}</td>
                            <td>{{ $plan->physician?->display_name }}</td>
                            <td><x-panou.badge :tone="$plan->statusTone()">{{ $plan->statusLabel() }}</x-panou.badge></td>
                            <td>@include('rehab::partials.progress', ['p' => $p])</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            {{ $plans->links() }}
        @endif
    </x-panou.panel>
@endsection
