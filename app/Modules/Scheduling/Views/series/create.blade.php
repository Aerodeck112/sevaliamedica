@extends('layouts.panou')
@section('title', 'Serie nouă')
@section('content')
    <x-panou.page-head title="Serie nouă de ședințe" :crumbs="['Serii de ședințe' => route('panou.series.index'), 'Serie nouă' => null]"
        sub="Pasul 1: regula. Pasul 2: verificați fiecare ședință (conflicte și alternative), apoi confirmați. Nimic nu se rezervă înainte de confirmare." />
    <form method="post" action="{{ route('panou.series.plan') }}" class="stack" data-module="appointment-form"
          data-search-url="{{ route('panou.patients.search') }}" data-suggest-url="{{ route('panou.appointments.suggestions') }}">
        @csrf
        <x-panou.panel title="Pacient">
            <div class="patient-picker" data-picker>
                <input type="hidden" name="patient_id" value="{{ old('patient_id', $patient?->id) }}" data-picker-value>
                <div class="picked" data-picker-picked @if (! $patient) hidden @endif>
                    <x-panou.icon name="user" /><strong data-picker-label>{{ $patient?->fullName() }}</strong>
                    <span class="muted small" data-picker-meta>{{ $patient?->birth_date?->format('d.m.Y') }}</span>
                    <button type="button" class="btn btn--small" data-picker-clear style="margin-left:auto">Schimbă</button>
                </div>
                <div data-picker-search @if ($patient) hidden @endif>
                    <label class="field__label" for="patient_q">Caută pacientul</label>
                    <input id="patient_q" type="search" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="patient_results">
                    <ul id="patient_results" class="patient-picker__results" role="listbox" hidden></ul>
                </div>
                @error('patient_id')<span class="field__error">{{ $message }}</span>@enderror
            </div>
        </x-panou.panel>
        <x-panou.panel title="Regula seriei">
            <div class="form-grid form-grid--3">
                <x-panou.field name="service_id" type="select" label="Serviciu" :options="$services->pluck('name', 'id')->all()" required />
                <x-panou.field name="staff_member_id" type="select" label="Terapeut / persoana" :options="$staff" placeholder="— fără persoană fixă —" />
                <x-panou.field name="location_id" type="select" label="Locație" :options="$locations" required :placeholder="false" />
                <x-panou.field name="start_date" type="date" label="Prima zi" :value="now()->addDay()->toDateString()" required />
                <x-panou.field name="time" type="time" label="Ora" value="09:00" required />
                <x-panou.field name="count" type="number" label="Număr de ședințe" value="10" required />
                <fieldset class="field field--full">
                    <legend class="field__label">Zilele săptămânii<span class="req">*</span></legend>
                    <div class="cluster" style="gap:.4rem 1rem">
                        @foreach (\App\Modules\Scheduling\Models\StaffSchedule::WEEKDAYS as $d => $label)
                            <label class="check"><input type="checkbox" name="weekdays[]" value="{{ $d }}" @checked(in_array($d, old('weekdays', [1, 3, 5])))><span>{{ $label }}</span></label>
                        @endforeach
                    </div>
                    @error('weekdays')<span class="field__error">{{ $message }}</span>@enderror
                </fieldset>
                <x-panou.field name="notes" type="textarea" label="Observații administrative" full />
            </div>
        </x-panou.panel>
        <div class="form-actions"><button class="btn btn--primary" type="submit">Verifică ședințele</button></div>
    </form>
@endsection
