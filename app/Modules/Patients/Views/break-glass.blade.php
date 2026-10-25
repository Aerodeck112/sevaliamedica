@extends('layouts.panou')
@section('title', 'Acces de urgență')
@section('content')
    <x-panou.page-head title="Acces de urgență la datele medicale"
        :crumbs="['Pacienți' => route('panou.patients.index'), $patient->fullName() => route('panou.patients.show', $patient), 'Acces de urgență' => null]"
        sub="Folosiți această procedură doar când îngrijirea pacientului o cere și nu aveți o relație de îngrijire înregistrată. Accesul durează {{ \App\Modules\Patients\Services\CareAccess::setting('break_glass_minutes') }} de minute, este înregistrat în jurnalul de audit și poate fi verificat de conducerea clinicii." />
    <x-panou.panel style="max-width:640px">
        <form method="post" action="{{ route('panou.patients.break-glass.store', $patient) }}" class="stack">
            @csrf
            <x-panou.field name="reason" type="textarea" label="Motivul accesului" required hint="Ex. pacient prezentat fără programare, medicul curant indisponibil. Minimum 15 caractere." />
            <div class="form-actions">
                <button class="btn btn--danger" type="submit">Activează accesul de urgență</button>
                <a class="btn btn--ghost" href="{{ route('panou.patients.show', $patient) }}">Renunță</a>
            </div>
        </form>
    </x-panou.panel>
@endsection
