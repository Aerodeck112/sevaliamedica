<div class="grid grid--aside">
    <x-panou.panel title="Planuri de recuperare" :flush="true">
        @can('rehab.plan')
            <x-slot:actions><a class="btn btn--primary btn--small" href="{{ route('panou.rehab.plans.create', $patient) }}"><x-panou.icon name="plus" />Plan nou</a></x-slot:actions>
        @endcan
        @if ($plans->isEmpty())
            <x-panou.empty title="Niciun plan">Planurile se întocmesc de medic după consultație.</x-panou.empty>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Plan</th><th>Proceduri</th><th>Stare</th><th style="min-width:240px">Ședințe</th></tr></thead>
                    <tbody>
                    @foreach ($plans as $plan)
                        <tr>
                            <td><a class="row-link" href="{{ route('panou.rehab.plans.show', $plan) }}">Plan #{{ $plan->id }}</a><div class="small muted">{{ $plan->created_at->format('d.m.Y') }} · {{ $plan->physician?->display_name }}</div></td>
                            <td class="small">{{ $plan->items->map(fn ($i) => $i->service->name.' ×'.$i->sessions_count)->join(', ') ?: '—' }}</td>
                            <td><x-panou.badge :tone="$plan->statusTone()">{{ $plan->statusLabel() }}</x-panou.badge></td>
                            <td>@include('rehab::partials.progress', ['p' => $progress->forPlan($plan)['totals']])</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-panou.panel>

    <x-panou.panel title="Pachete de ședințe">
        @forelse ($packages as $pkg)
            <div style="margin-bottom:.75rem">
                <strong>{{ $pkg->definition->name }}</strong>
                @if ($pkg->status === 'activ')<x-panou.badge tone="ok">activ</x-panou.badge>@else<x-panou.badge>{{ $pkg->status }}</x-panou.badge>@endif
                <div class="small">{{ $pkg->used() }} din {{ $pkg->sessions_total }} ședințe consumate · {{ $pkg->valid_from->format('d.m.Y') }}@if ($pkg->valid_until) – {{ $pkg->valid_until->format('d.m.Y') }}@endif</div>
                @if ($pkg->status === 'activ')
                    @can('rehab.packages.assign')
                        <details><summary class="small">Anulează pachetul</summary>
                            <form method="post" action="{{ route('panou.rehab.packages.cancel', $pkg) }}" class="cluster" style="margin-top:.4rem">@csrf
                                <input type="text" name="reason" required minlength="5" placeholder="Motiv" aria-label="Motivul anulării"><button class="btn btn--small" type="submit">Anulează</button></form>
                        </details>
                    @endcan
                @endif
            </div>
        @empty
            <p class="small muted">Niciun pachet atribuit.</p>
        @endforelse
        @can('rehab.packages.assign')
            @php $defs = \App\Modules\Rehab\Models\PackageDefinition::where('is_active', true)->orderBy('name')->get(); @endphp
            @if ($defs->isNotEmpty())
                <form method="post" action="{{ route('panou.rehab.packages.assign', $patient) }}" class="stack" style="margin-top:1rem">
                    @csrf
                    <x-panou.field name="rehab_package_definition_id" type="select" label="Tip de pachet" :options="$defs->mapWithKeys(fn ($d) => [$d->id => $d->name.' · '.$d->sessions_count.' ședințe'])->all()" required />
                    <x-panou.field name="valid_from" type="date" label="Valabil de la" :value="now()->toDateString()" required />
                    <button class="btn" type="submit">Atribuie pachetul</button>
                </form>
            @else
                <p class="small muted">Clinica nu a configurat tipuri de pachete.</p>
            @endif
        @endcan
    </x-panou.panel>
</div>
