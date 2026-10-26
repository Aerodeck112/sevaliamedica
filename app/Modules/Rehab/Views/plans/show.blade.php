@extends('layouts.panou')
@section('title', 'Plan de recuperare #'.$plan->id)
@section('content')
    @php
        use App\Modules\Rehab\Models\RehabPlan;
        $states = ['efectuata' => ['efectuată', 'ok'], 'programata' => ['programată', 'info'], 'de_clarificat' => ['de clarificat', 'warn'], 'anulata' => ['anulată', null], 'neprezentat' => ['neprezentat', 'danger']];
        $open = in_array($plan->status, [RehabPlan::APPROVED, RehabPlan::ACTIVE, RehabPlan::SUSPENDED], true);
    @endphp
    <x-panou.page-head :title="'Plan de recuperare #'.$plan->id" :sub="$plan->patient->fullName().' · '.$plan->location?->name"
        :crumbs="[$plan->patient->fullName() => route('panou.patients.show', [$plan->patient, 'tab' => 'recuperare']), 'Plan #'.$plan->id => null]">
        <x-slot:actions>
            @if ($plan->isEditable())
                @can('rehab.plan')<a class="btn" href="{{ route('panou.rehab.plans.edit', $plan) }}">Editează planul</a>@endcan
                @can('rehab.approve')
                    <form method="post" action="{{ route('panou.rehab.plans.approve', $plan) }}" data-confirm="Aprobați planul? Procedurile nu se vor mai putea modifica (doar numărul de ședințe, cu motiv).">@csrf<button class="btn btn--primary" type="submit">Aprobă planul</button></form>
                @endcan
            @endif
            @if ($clinical && $open)
                @can('medical.write')<a class="btn" href="{{ route('panou.medical.records.create', [$plan->patient, 'kind' => 'reevaluare', 'plan' => $plan->id]) }}">Reevaluare</a>@endcan
            @endif
        </x-slot:actions>
    </x-panou.page-head>

    @if ($plan->status === RehabPlan::SUSPENDED)
        <div class="alert alert--danger"><x-panou.icon name="warn" /><div><strong>Plan suspendat</strong> din {{ $plan->suspended_at->format('d.m.Y') }}: {{ $plan->suspend_reason }}. Ședințele nu se pot marca până la reluare.</div></div>
    @endif
    @if ($progress['totals']['pending'])
        <div class="alert alert--warn"><x-panou.icon name="clock" /><div>Ședințe cu ora trecută, nemarcate: {{ $progress['totals']['pending'] }}. Nu se consideră efectuate automat: terapeutul le marchează, iar recepția rezolvă neprezentările/anulările.</div></div>
    @endif

    <div class="grid grid--aside">
        <div class="stack">
            <x-panou.panel title="Evidența ședințelor">
                @include('rehab::partials.progress', ['p' => $progress['totals']])
            </x-panou.panel>

            @foreach ($plan->items as $item)
                @php $p = $progress['items'][$item->id]; @endphp
                <x-panou.panel :title="$item->service->name">
                    <x-slot:actions>
                        @if ($plan->canSchedule() && $p['unscheduled'] > 0)
                            @can('rehab.schedule')<a class="btn btn--primary btn--small" href="{{ route('panou.rehab.plans.items.schedule', $item) }}"><x-panou.icon name="calendar" />Programează {{ $p['unscheduled'] }}</a>@endcan
                        @endif
                        @if ($plan->isEditable())
                            @can('rehab.plan')
                                <form method="post" action="{{ route('panou.rehab.plans.items.destroy', $item) }}" data-confirm="Eliminați procedura din draft?">@csrf @method('delete')<button class="btn btn--small btn--danger" type="submit">Elimină</button></form>
                            @endcan
                        @endif
                    </x-slot:actions>
                    <dl class="facts">
                        <dt>Ședințe prescrise</dt><dd>{{ $item->sessions_count }}@if ($item->sessions_per_week) · {{ $item->sessions_per_week }}/săptămână@endif</dd>
                        <dt>Terapeut preferat</dt><dd>{{ $item->preferredTherapist?->display_name ?? '—' }}</dd>
                        <dt>Durată</dt><dd>{{ $item->service->duration_minutes }} min</dd>
                        <dt>Resurse</dt><dd>{{ $item->service->requirements->map(fn ($r) => ($r->resource_type === 'room' ? 'sală ' : 'aparat ').$r->resource_kind)->join(', ') ?: 'fără sală/aparat dedicat' }}</dd>
                        <dt>Sursa de plată</dt><dd>{{ $payers[$item->payer()] ?? $item->payer() }}</dd>
                        @if ($item->instructions)<dt>Instrucțiuni</dt><dd>{{ $item->instructions }}</dd>@endif
                    </dl>
                    <div style="margin-top:1rem">@include('rehab::partials.progress', ['p' => $p])</div>

                    @if ($p['appointments']->isNotEmpty())
                        <div class="table-wrap" style="margin-top:1rem">
                            <table class="table">
                                <thead><tr><th>#</th><th>Data</th><th>Terapeut</th><th>Ședință</th><th></th></tr></thead>
                                <tbody>
                                @foreach ($p['appointments'] as $a)
                                    @php [$label, $tone] = $states[$a->rehab_state]; @endphp
                                    <tr>
                                        <td class="num">{{ $loop->iteration }}</td>
                                        <td class="num">{{ $a->starts_at?->locale('ro')->isoFormat('ddd D MMM, HH:mm') }}</td>
                                        <td>{{ $a->staffMember?->display_name }}</td>
                                        <td><x-panou.badge :tone="$tone">{{ $label }}</x-panou.badge>
                                            @if ($clinical && $a->rehab_record?->observations)<div class="small muted">{{ \Illuminate\Support\Str::limit($a->rehab_record->observations, 80) }}</div>@endif</td>
                                        <td class="text-right">
                                            @if (in_array($a->rehab_state, ['de_clarificat', 'programata'], true) && ($a->starts_at?->lte(now()->addMinutes(15))))
                                                @can('rehab.sessions')<a class="btn btn--small btn--primary" href="{{ route('panou.rehab.sessions.create', $a) }}">Marchează efectuată</a>@endcan
                                            @endif
                                            <a class="btn btn--small" href="{{ route('panou.appointments.show', $a) }}">Programarea</a>
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                        <p class="small muted" style="margin-top:.5rem">Reprogramarea unei ședințe: din „Programarea” (Mută programarea). Reprogramarea tuturor ședințelor viitoare:
                            @foreach ($item->series as $s)<a href="{{ route('panou.series.show', $s) }}">seria #{{ $s->id }}</a>@if (! $loop->last), @endif @endforeach.</p>
                    @endif

                    @if (! $plan->isEditable() && $open)
                        @can('rehab.approve')
                            <details style="margin-top:.75rem">
                                <summary class="small">Modifică numărul de ședințe (prelungire / reducere)</summary>
                                <form method="post" action="{{ route('panou.rehab.plans.items.extend', $item) }}" class="cluster" style="margin-top:.5rem;align-items:flex-end">
                                    @csrf
                                    <x-panou.field name="sessions_count" type="number" label="Ședințe" :value="$item->sessions_count" required />
                                    <x-panou.field name="reason" label="Motiv" required />
                                    <button class="btn btn--small" type="submit">Salvează</button>
                                </form>
                            </details>
                        @endcan
                    @endif
                </x-panou.panel>
            @endforeach

            @if ($plan->items->isEmpty())
                <x-panou.panel><x-panou.empty title="Nicio procedură">Medicul adaugă procedurile din catalogul de servicii.</x-panou.empty></x-panou.panel>
            @endif

            @if ($plan->isEditable())
                @can('rehab.plan')
                    <x-panou.panel title="Adaugă procedură">
                        @if ($services->isEmpty())
                            <p>Catalogul nu are servicii de tip „ședință” sau „procedură” (Administrare → Catalog servicii).</p>
                        @else
                            <form method="post" action="{{ route('panou.rehab.plans.items.store', $plan) }}">
                                @csrf
                                <div class="form-grid form-grid--3">
                                    <x-panou.field name="service_id" type="select" label="Procedura (din catalog)" :options="$services->pluck('name', 'id')->all()" required />
                                    <x-panou.field name="sessions_count" type="number" label="Număr de ședințe" required />
                                    <x-panou.field name="sessions_per_week" type="number" label="Ședințe pe săptămână" />
                                    <x-panou.field name="preferred_therapist_id" type="select" label="Terapeut preferat" :options="$therapists" placeholder="— oricare —" />
                                    <x-panou.field name="payer_type" type="select" label="Sursa de plată" :options="$payers" placeholder="ca planul" />
                                    <x-panou.field name="instructions" label="Instrucțiuni organizatorice" />
                                </div>
                                <div class="form-actions"><button class="btn btn--primary" type="submit">Adaugă procedura</button></div>
                            </form>
                        @endif
                    </x-panou.panel>
                @endcan
            @endif
        </div>

        <div class="stack">
            <x-panou.panel title="Plan">
                <dl class="facts">
                    <dt>Stare</dt><dd><x-panou.badge :tone="$plan->statusTone()">{{ $plan->statusLabel() }}</x-panou.badge></dd>
                    <dt>Medic</dt><dd>{{ $plan->physician?->display_name }}</dd>
                    @if ($plan->approved_at)<dt>Aprobat</dt><dd class="num">{{ $plan->approved_at->format('d.m.Y') }}<br><span class="small muted">{{ $plan->approver?->name }}</span></dd>@endif
                    <dt>Sursa de plată</dt><dd>{{ $payers[$plan->payer_type] ?? $plan->payer_type }}</dd>
                    @if ($plan->package)<dt>Pachet</dt><dd>{{ $plan->package->label() }}</dd>@endif
                    @if ($plan->planned_start)<dt>Început estimat</dt><dd class="num">{{ $plan->planned_start->format('d.m.Y') }}</dd>@endif
                    @if ($plan->record && $clinical)<dt>Consultația</dt><dd><a href="{{ route('panou.medical.records.show', $plan->record) }}">{{ $plan->record->title }}</a></dd>@endif
                </dl>
            </x-panou.panel>

            <x-panou.panel title="Conținut clinic">
                @if ($clinical)
                    <h3 class="small muted">Obiective</h3>
                    <p style="white-space:pre-wrap">{{ $plan->objectives ?: '—' }}</p>
                    <h3 class="small muted">Observații pentru echipă</h3>
                    <p style="white-space:pre-wrap">{{ $plan->notes ?: '—' }}</p>
                    @if ($plan->closing_conclusion)
                        <h3 class="small muted">Concluzie la închidere</h3>
                        <p style="white-space:pre-wrap">{{ $plan->closing_conclusion }}</p>
                    @endif
                    @if ($plan->reevaluations->isNotEmpty())
                        <h3 class="small muted">Reevaluări</h3>
                        <ul class="small">@foreach ($plan->reevaluations as $r)<li><a href="{{ route('panou.medical.records.show', $r) }}">{{ $r->title }}</a> · {{ $r->created_at->format('d.m.Y') }} · {{ $r->statusLabel() }}</li>@endforeach</ul>
                    @endif
                @else
                    <p class="small muted">Obiectivele, observațiile și concluziile sunt vizibile doar personalului medical care îngrijește pacientul.</p>
                @endif
            </x-panou.panel>

            @if ($open)
                @can('rehab.approve')
                    <x-panou.panel title="Suspendare / încheiere">
                        @if ($plan->status === RehabPlan::SUSPENDED)
                            <form method="post" action="{{ route('panou.rehab.plans.resume', $plan) }}">@csrf<button class="btn" type="submit">Reia planul</button></form>
                        @else
                            <form method="post" action="{{ route('panou.rehab.plans.suspend', $plan) }}" class="stack">
                                @csrf
                                <x-panou.field name="reason" label="Motivul suspendării" />
                                <button class="btn btn--small" type="submit">Suspendă</button>
                            </form>
                        @endif
                        <hr class="sep">
                        <form method="post" action="{{ route('panou.rehab.plans.close', $plan) }}" class="stack" data-confirm="Încheiați planul?">
                            @csrf
                            <x-panou.field name="conclusion" type="textarea" label="Concluzie (obligatorie)" required />
                            @if ($progress['totals']['scheduled'])
                                <label class="check small"><input type="checkbox" name="cancel_future" value="1"><span>Anulează cele {{ $progress['totals']['scheduled'] }} ședințe programate în viitor.</span></label>
                            @endif
                            <button class="btn btn--danger btn--small" type="submit">Încheie planul</button>
                        </form>
                    </x-panou.panel>
                @endcan
            @endif
        </div>
    </div>
@endsection
