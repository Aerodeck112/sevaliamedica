@extends('layouts.panou')
@section('title', $patient->exists ? $patient->fullName() : 'Pacient nou')
@section('content')
    <x-panou.page-head :title="$patient->exists ? 'Editare: '.$patient->fullName() : 'Pacient nou'"
        :crumbs="['Pacienți' => route('panou.patients.index'), ($patient->exists ? $patient->fullName() : 'Pacient nou') => $patient->exists ? route('panou.patients.show', $patient) : null]"
        sub="Completați doar datele necesare. CNP-ul e criptat și apare mascat; data nașterii și sexul se deduc din CNP." />

    @if ($dupes = session('duplicates'))
        <div class="alert {{ $dupes['blocking'] ? 'alert--danger' : 'alert--warn' }}" role="alert">
            <x-panou.icon name="warn" />
            <div>
                <strong>{{ $dupes['blocking'] ? 'Fișa există deja' : 'Fișe asemănătoare' }}</strong>
                <ul>
                    @foreach ([...$dupes['blocking'], ...$dupes['warnings']] as $d)
                        <li><a href="{{ $d['url'] }}">{{ $d['name'] }}</a>{{ $d['birth_date'] ? ', născut(ă) '.$d['birth_date'] : '' }} — {{ $d['reason'] }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    <form method="post" action="{{ $patient->exists ? route('panou.patients.update', $patient) : route('panou.patients.store') }}" class="stack">
        @csrf
        @if ($patient->exists) @method('put') @endif
        @if ($returnTo)<input type="hidden" name="return_to" value="{{ $returnTo }}">@endif

        <x-panou.panel title="Identificare">
            <div class="form-grid form-grid--3">
                <x-panou.field name="last_name" label="Nume de familie" :value="$patient->last_name" required autocomplete="off" />
                <x-panou.field name="first_name" label="Prenume" :value="$patient->first_name" required autocomplete="off" />
                <x-panou.field name="cnp" label="CNP" :value="null" inputmode="numeric" autocomplete="off"
                    :hint="$patient->exists && $patient->cnp ? 'Actual: '.$patient->maskedCnp().'. Lăsați gol pentru a-l păstra.' : 'Opțional pentru programare; necesar pentru servicii decontate CAS.'" />
                <x-panou.field name="birth_date" type="date" label="Data nașterii" :value="$patient->birth_date?->format('Y-m-d')" />
                <x-panou.field name="sex" type="select" label="Sex" :value="$patient->sex" :options="\App\Modules\Patients\Models\Patient::SEXES" placeholder="— nespecificat —" />
                <x-panou.field name="citizenship" label="Cetățenie" :value="$patient->citizenship" />
                <x-panou.field name="foreign_id_type" label="Document (cetățeni străini)" :value="$patient->foreign_id_type" hint="ex. pașaport" />
                <x-panou.field name="foreign_id_number" label="Număr document" :value="null" autocomplete="off" :hint="$patient->exists && $patient->foreign_id_number ? 'Completat; lăsați gol pentru a-l păstra.' : null" />
            </div>
            @if ($patient->exists && $patient->cnp)
                <label class="check" style="margin-top:1rem"><input type="checkbox" name="remove_cnp" value="1"><span>Șterge CNP-ul din fișă</span></label>
            @endif
        </x-panou.panel>

        <x-panou.panel title="Contact">
            <div class="form-grid form-grid--3">
                <x-panou.field name="phone" type="tel" label="Telefon" :value="$patient->phone" autocomplete="off" />
                <x-panou.field name="email" type="email" label="E-mail" :value="$patient->email" autocomplete="off" />
                <x-panou.field name="address" label="Adresă" :value="$patient->address" />
                <x-panou.field name="city" label="Localitate" :value="$patient->city" />
                <x-panou.field name="county" label="Județ" :value="$patient->county" />
            </div>
        </x-panou.panel>

        <x-panou.panel title="Preferințe de comunicare">
            <fieldset>
                <legend class="field__label">Comunicări operaționale (confirmări, remindere, modificări de program)</legend>
                <div class="cluster" style="gap:.5rem 1.5rem">
                    <x-panou.field name="allow_phone" type="checkbox" label="Telefon" :value="$patient->allow_phone" />
                    <x-panou.field name="allow_sms" type="checkbox" label="SMS" :value="$patient->allow_sms" />
                    <x-panou.field name="allow_email" type="checkbox" label="E-mail" :value="$patient->allow_email" />
                    <x-panou.field name="allow_whatsapp" type="checkbox" label="WhatsApp" :value="$patient->allow_whatsapp" />
                </div>
            </fieldset>
            <hr class="sep">
            <x-panou.field name="marketing_consent" type="checkbox" label="Acord pentru comunicări de marketing (noutăți, campanii)" :value="$patient->marketing_consent" />
            <p class="field__hint">Separat de comunicările operaționale. Se înregistrează data și cine a consemnat acordul@if ($patient->marketing_consent_at) — ultima modificare {{ $patient->marketing_consent_at->format('d.m.Y H:i') }} ({{ $patient->marketing_consent_source }})@endif.</p>
        </x-panou.panel>

        <x-panou.panel title="Observații administrative">
            <x-panou.field name="admin_notes" type="textarea" label="Observații" :value="$patient->admin_notes" full hint="Doar informații administrative (ex. preferă dimineața). Nu introduceți aici date medicale." />
        </x-panou.panel>

        @if (session('duplicates') && ! session('duplicates')['blocking'])
            <label class="check"><input type="checkbox" name="confirm_not_duplicate" value="1"><span>Am verificat: este o persoană diferită de fișele de mai sus.</span></label>
        @endif
        <div class="form-actions">
            <button class="btn btn--primary" type="submit">{{ $patient->exists ? 'Salvează modificările' : 'Creează fișa' }}</button>
            <a class="btn btn--ghost" href="{{ $patient->exists ? route('panou.patients.show', $patient) : ($returnTo ?? route('panou.patients.index')) }}">Renunță</a>
        </div>
    </form>
@endsection
