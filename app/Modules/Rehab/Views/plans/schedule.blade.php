@extends('layouts.panou')
@section('title', 'Programare ședințe')
@section('content')
    @php $plan = $item->plan; $days = [1 => 'Lu', 2 => 'Ma', 3 => 'Mi', 4 => 'Jo', 5 => 'Vi', 6 => 'Sâ', 7 => 'Du']; @endphp
    <x-panou.page-head :title="'Programare: '.$item->service->name" :sub="$plan->patient->fullName().' · '.$plan->location?->name"
        :crumbs="['Plan #'.$plan->id => route('panou.rehab.plans.show', $plan), 'Programare' => null]" />
    <div class="grid grid--aside">
        <x-panou.panel title="Regula seriei">
            <form method="post" action="{{ route('panou.rehab.plans.items.schedule-preview', $item) }}" class="stack">
                @csrf
                <div class="form-grid">
                    <x-panou.field name="start_date" type="date" label="De la data" :value="old('start_date', max(now()->addDay()->toDateString(), $plan->planned_start?->toDateString() ?? ''))" required />
                    <x-panou.field name="time" type="time" label="Ora" value="09:00" required />
                    <x-panou.field name="count" type="number" label="Număr de ședințe" :value="$progress['unscheduled']" required :hint="'Neprogramate: '.$progress['unscheduled']" />
                    <x-panou.field name="staff_member_id" type="select" label="Terapeut" :value="$item->preferred_therapist_id" :options="$staff" placeholder="— alocare automată —" />
                    <fieldset class="field field--full">
                        <legend class="field__label">Zilele săptămânii @if ($item->sessions_per_week)<span class="muted">(planul: {{ $item->sessions_per_week }}/săptămână)</span>@endif</legend>
                        <div class="cluster">
                            @foreach ($days as $d => $label)
                                <label class="check"><input type="checkbox" name="weekdays[]" value="{{ $d }}" @checked(in_array($d, old('weekdays', []))) ><span>{{ $label }}</span></label>
                            @endforeach
                        </div>
                        @error('weekdays')<span class="field__error">{{ $message }}</span>@enderror
                    </fieldset>
                </div>
                <div class="form-actions"><button class="btn btn--primary" type="submit">Verifică disponibilitatea</button></div>
            </form>
        </x-panou.panel>
        <x-panou.panel title="Procedura">
            <dl class="facts">
                <dt>Durată</dt><dd>{{ $item->service->duration_minutes }} min</dd>
                <dt>Prescrise</dt><dd>{{ $progress['prescribed'] }}</dd>
                <dt>Efectuate</dt><dd>{{ $progress['done'] }}</dd>
                <dt>Programate</dt><dd>{{ $progress['scheduled'] }}</dd>
                <dt>Neprogramate</dt><dd>{{ $progress['unscheduled'] }}</dd>
            </dl>
            <p class="small muted" style="margin-top:1rem">La rezervare se alocă împreună terapeutul, sala și aparatul cerute de serviciu. Conflictele apar în pasul următor, cu alternative.</p>
        </x-panou.panel>
    </div>
@endsection
