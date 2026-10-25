@extends('layouts.panou')
@section('title', 'Programare nouă')
@section('content')
    <x-panou.page-head title="Programare nouă" :crumbs="['Calendar' => route('panou.calendar'), 'Programare nouă' => null]"
        sub="Persoana, sala și aparatul necesar se rezervă împreună. Dacă intervalul nu e liber, primiți motivul și intervale alternative." />

    @if (! empty($conflicts))
        <div class="alert alert--danger" role="alert">
            <x-panou.icon name="warn" />
            <div>
                <strong>Intervalul nu poate fi rezervat</strong>
                <ul>@foreach ($conflicts as $c)<li>{{ $c }}</li>@endforeach</ul>
                @if (! empty($suggestions))
                    <p style="margin:.6rem 0 .3rem">Intervale libere apropiate:</p>
                    <ul class="slot-list">
                        @foreach ($suggestions as $s)
                            <li><button type="button" class="btn btn--small" data-fill-slot data-date="{{ $s['start']->format('Y-m-d') }}" data-time="{{ $s['start']->format('H:i') }}" data-staff="{{ $s['staff_member_id'] }}">{{ $s['start']->locale('ro')->isoFormat('ddd D MMM HH:mm') }} · {{ $s['staff'] }}</button></li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    @endif

    <form method="post" action="{{ route('panou.appointments.store') }}" data-module="appointment-form" class="stack"
          data-search-url="{{ route('panou.patients.search') }}" data-suggest-url="{{ route('panou.appointments.suggestions') }}">
        @csrf
        @if (! empty($prefill['waitlist_entry_id']))<input type="hidden" name="waitlist_entry_id" value="{{ $prefill['waitlist_entry_id'] }}">@endif

        <x-panou.panel title="Pacient">
            <div class="patient-picker" data-picker>
                <input type="hidden" name="patient_id" value="{{ old('patient_id', $patient?->id) }}" data-picker-value>
                <div class="picked" data-picker-picked @if (! $patient) hidden @endif>
                    <x-panou.icon name="user" />
                    <strong data-picker-label>{{ $patient?->fullName() }}</strong>
                    <span class="muted small" data-picker-meta>{{ $patient?->birth_date?->format('d.m.Y') }} {{ $patient?->phone }}</span>
                    <button type="button" class="btn btn--small" data-picker-clear style="margin-left:auto">Schimbă</button>
                </div>
                <div data-picker-search @if ($patient) hidden @endif>
                    <label class="field__label" for="patient_q">Caută pacientul (nume, telefon, CNP)</label>
                    <input id="patient_q" type="search" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="patient_results" aria-autocomplete="list" placeholder="ex. Popescu Ana sau 0741…">
                    <ul id="patient_results" class="patient-picker__results" role="listbox" hidden></ul>
                    <p class="field__hint">
                        Nu găsiți pacientul? <button type="button" class="link-btn" data-dialog-open="new-patient">Creează fișă nouă</button>
                        <noscript> · fără JavaScript: <a href="{{ route('panou.patients.create', ['return_to' => request()->getRequestUri()]) }}">creați fișa</a> sau introduceți numărul fișei mai jos.</noscript>
                    </p>
                    <noscript><x-panou.field name="patient_id" type="number" label="Numărul fișei" :value="old('patient_id')" /></noscript>
                </div>
                @error('patient_id')<span class="field__error">{{ $message }}</span>@enderror
            </div>
        </x-panou.panel>

        <x-panou.panel title="Serviciu și interval">
            <div class="form-grid form-grid--3">
                <div class="field">
                    <label class="field__label" for="f_service_id">Serviciu<span class="req" aria-hidden="true">*</span></label>
                    <select id="f_service_id" name="service_id" required>
                        <option value="">— alegeți —</option>
                        @foreach ($services as $s)
                            <option value="{{ $s->id }}" data-duration="{{ $s->duration_minutes }}" data-staff-kind="{{ $s->staff_kind }}" @selected((string) old('service_id', $prefill['service_id'] ?? '') === (string) $s->id)>{{ $s->name }} ({{ $s->duration_minutes }} min)</option>
                        @endforeach
                    </select>
                    @if ($services->isEmpty())<span class="field__hint">Catalogul de servicii este gol. Adăugați servicii în Administrare → Catalog servicii.</span>@endif
                    @error('service_id')<span class="field__error">{{ $message }}</span>@enderror
                </div>
                <x-panou.field name="location_id" type="select" label="Locație" :value="$prefill['location_id'] ?? null" :options="$locations" required :placeholder="false" />
                <div class="field">
                    <label class="field__label" for="f_staff_member_id">Persoana care efectuează</label>
                    <select id="f_staff_member_id" name="staff_member_id">
                        <option value="">— oricine disponibil / nu e cazul —</option>
                        @foreach ($staff as $s)
                            <option value="{{ $s->id }}" data-kind="{{ $s->kind }}" @selected((string) old('staff_member_id', $prefill['staff_member_id'] ?? '') === (string) $s->id)>{{ $s->display_name }}</option>
                        @endforeach
                    </select>
                </div>
                <x-panou.field name="date" type="date" label="Data" :value="$prefill['date'] ?? null" required />
                <x-panou.field name="time" type="time" label="Ora" :value="$prefill['time'] ?? null" required step="300" />
                <x-panou.field name="duration" type="number" label="Durată (minute)" :value="$prefill['duration'] ?? null" hint="Gol = durata serviciului" />
            </div>
            <div class="stack" style="margin-top:1rem">
                <button type="button" class="btn btn--small" data-suggest>Arată intervale libere</button>
                <ul class="slot-list" data-suggest-list aria-live="polite"></ul>
            </div>
        </x-panou.panel>

        <x-panou.panel title="Resurse și detalii">
            <div class="form-grid form-grid--3">
                <x-panou.field name="room_id" type="select" label="Sală (opțional)" :value="$prefill['room_id'] ?? null" :options="$rooms" placeholder="Alocare automată" :hint="$rooms ? null : 'Locația nu are săli configurate.'" />
                <x-panou.field name="equipment_id" type="select" label="Aparat (opțional)" :value="$prefill['equipment_id'] ?? null" :options="$equipment" placeholder="Alocare automată" />
                <x-panou.field name="payer_type" type="select" label="Sursa de plată estimată" :value="$prefill['payer_type'] ?? null" :options="\App\Modules\Scheduling\Models\Appointment::PAYER_TYPES" placeholder="— necunoscută —" hint="Estimare la programare; serviciul efectuat se înregistrează separat." />
                <x-panou.field name="source" type="select" label="Canal" :value="$prefill['source'] ?? 'receptie'" :options="['receptie' => 'Recepție (prezență)', 'telefon' => 'Telefon']" :placeholder="false" />
                <x-panou.field name="notes" type="textarea" label="Observații administrative" :value="$prefill['notes'] ?? null" full hint="Fără date medicale." />
            </div>
            @can('scheduling.override')
                <details style="margin-top:1rem" @if (old('override')) open @endif>
                    <summary class="link-btn">Programare în afara programului de lucru</summary>
                    <div class="form-grid" style="margin-top:.75rem">
                        <x-panou.field name="override" type="checkbox" label="Forțează în afara programului (se înregistrează în istoric)" :value="old('override')" />
                        <x-panou.field name="override_reason" label="Motiv" :value="old('override_reason')" />
                    </div>
                </details>
            @endcan
        </x-panou.panel>

        <div class="form-actions">
            <button class="btn btn--primary" type="submit">Rezervă</button>
            <a class="btn btn--ghost" href="{{ route('panou.calendar') }}">Renunță</a>
        </div>
    </form>

    <dialog class="dialog" id="new-patient" data-new-patient data-store-url="{{ route('panou.patients.store') }}">
        <form method="dialog" novalidate>
            <div class="dialog__head"><h2>Fișă nouă de pacient</h2></div>
            <div class="dialog__body stack">
                <div class="form-grid">
                    <x-panou.field name="np_last_name" id="np_last_name" label="Nume de familie" required />
                    <x-panou.field name="np_first_name" id="np_first_name" label="Prenume" required />
                    <x-panou.field name="np_phone" id="np_phone" type="tel" label="Telefon" />
                    <x-panou.field name="np_birth_date" id="np_birth_date" type="date" label="Data nașterii" />
                    <x-panou.field name="np_cnp" id="np_cnp" label="CNP (opțional)" full />
                </div>
                <div data-np-result aria-live="polite"></div>
                <label class="check" data-np-confirm hidden><input type="checkbox" id="np_confirm"><span>Am verificat: este o persoană diferită de fișele de mai sus.</span></label>
            </div>
            <div class="dialog__foot">
                <button class="btn" type="button" data-dialog-close>Renunță</button>
                <button class="btn btn--primary" type="button" data-np-save>Creează fișa</button>
            </div>
        </form>
    </dialog>
@endsection
