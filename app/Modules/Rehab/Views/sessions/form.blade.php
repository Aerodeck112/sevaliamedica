@extends('layouts.panou')
@section('title', 'Marchează ședința')
@section('content')
    @php $plan = $item->plan; @endphp
    <x-panou.page-head :title="$item->service->name.' — ședința '.($appointment->series_index ?? '')" :sub="$appointment->patient->fullName().' · '.$appointment->starts_at?->locale('ro')->isoFormat('dddd D MMMM, HH:mm')"
        :crumbs="['Plan #'.$plan->id => route('panou.rehab.plans.show', $plan), 'Ședință' => null]" />

    @foreach ($alerts as $alert)
        <div class="alert alert--{{ $alert->severity === 'critic' ? 'danger' : 'warn' }}" role="note"><x-panou.icon name="warn" /><div><strong>{{ $alert->severityLabel() }}:</strong> {{ $alert->text }}</div></div>
    @endforeach

    <div class="grid grid--aside">
        <x-panou.panel title="Înregistrare ședință">
            @if ($existing && $existing->counts())
                <div class="alert alert--ok"><x-panou.icon name="check" /><div>Ședința este deja înregistrată ca efectuată ({{ $existing->performed_at->format('d.m.Y H:i') }}).</div></div>
            @else
                <form method="post" action="{{ route('panou.rehab.sessions.store', $appointment) }}" class="stack">
                    @csrf
                    @if ($isPastDay && $appointment->status !== 'finalizata')
                        <div class="alert alert--warn"><x-panou.icon name="clock" /><div>Ședința este dintr-o zi trecută și nu a fost marcată atunci. Confirmarea ulterioară se consemnează în istoricul programării, cu motivul de mai jos.</div></div>
                        <x-panou.field name="late_reason" label="De ce se marchează acum" required />
                    @endif
                    <x-panou.field name="observations" type="textarea" label="Observații (text liber)" />
                    <x-panou.field name="evolution" type="textarea" label="Evoluție (text liber)" />
                    <label class="check"><input type="checkbox" name="confirm" value="1" required><span>Confirm că ședința a avut loc.</span></label>
                    <p class="small muted">Se înregistrează serviciul efectuat „{{ $item->service->name }}”, cu sursa de plată {{ \App\Modules\Scheduling\Models\ServiceRecord::PAYER_TYPES[$item->payer()] ?? $item->payer() }}@if ($item->payer() === 'pachet' && $plan->package) (pachet {{ $plan->package->label() }})@endif. O greșeală se corectează din „Servicii efectuate” în fișa pacientului.</p>
                    <div class="form-actions"><button class="btn btn--primary" type="submit">Ședință efectuată</button></div>
                </form>
            @endif
        </x-panou.panel>
        <div class="stack">
            <x-panou.panel title="Evidența procedurii">@include('rehab::partials.progress', ['p' => $progress])</x-panou.panel>
            <x-panou.panel title="Ședințe anterioare">
                @forelse ($previous as $s)
                    <div style="margin-bottom:.75rem">
                        <div class="small"><strong>{{ $s->performed_at->format('d.m.Y') }}</strong> · {{ $s->therapist?->display_name }}</div>
                        @if ($s->observations)<div class="small" style="white-space:pre-wrap">{{ $s->observations }}</div>@endif
                        @if ($s->evolution)<div class="small muted" style="white-space:pre-wrap">Evoluție: {{ $s->evolution }}</div>@endif
                    </div>
                @empty
                    <p class="small muted">Aceasta este prima ședință înregistrată.</p>
                @endforelse
            </x-panou.panel>
        </div>
    </div>
@endsection
