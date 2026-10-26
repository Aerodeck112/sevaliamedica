@extends('layouts.panou')
@section('title', $plan->exists ? 'Plan #'.$plan->id : 'Plan de recuperare nou')
@section('content')
    <x-panou.page-head :title="$plan->exists ? 'Editare plan #'.$plan->id : 'Plan de recuperare nou'" :sub="$patient->fullName()"
        :crumbs="[$patient->fullName() => route('panou.patients.show', [$patient, 'tab' => 'recuperare']), ($plan->exists ? 'Editare' : 'Plan nou') => null]" />
    <x-panou.panel style="max-width:900px">
        <p class="med-legal">Planul, procedurile și numărul de ședințe le stabilește medicul. Aplicația nu propune tratamente; ea organizează programarea și evidența ședințelor.</p>
        <form method="post" action="{{ $plan->exists ? route('panou.rehab.plans.update', $plan) : route('panou.rehab.plans.store', $patient) }}" class="stack" style="margin-top:1rem">
            @csrf
            @if ($plan->exists) @method('put') @endif
            <div class="form-grid">
                <x-panou.field name="medical_record_id" type="select" label="Consultația de bază" :value="$plan->medical_record_id" :options="$records" placeholder="— fără legătură —"
                    hint="Fișele finalizate ale pacientului." full />
                <x-panou.field name="location_id" type="select" label="Locația" :value="$plan->location_id" :options="$locations" required />
                <x-panou.field name="planned_start" type="date" label="Început estimat" :value="$plan->planned_start?->format('Y-m-d')" />
                <x-panou.field name="payer_type" type="select" label="Sursa de plată" :value="$plan->payer_type" :options="$payers" :placeholder="false"
                    hint="„CAS” este doar un marcaj; raportarea CAS se configurează separat." />
                <x-panou.field name="patient_package_id" type="select" label="Pachet (dacă sursa e „Pachet”)" :value="$plan->patient_package_id" :options="$packages" placeholder="— niciunul —" />
                <x-panou.field name="objectives" type="textarea" label="Obiective (text liber)" :value="$plan->objectives" full />
                <x-panou.field name="notes" type="textarea" label="Observații pentru echipă (text liber)" :value="$plan->notes" full />
            </div>
            <div class="form-actions">
                <button class="btn btn--primary" type="submit">{{ $plan->exists ? 'Salvează' : 'Creează planul (draft)' }}</button>
                <a class="btn btn--ghost" href="{{ $plan->exists ? route('panou.rehab.plans.show', $plan) : route('panou.patients.show', [$patient, 'tab' => 'recuperare']) }}">Renunță</a>
            </div>
        </form>
    </x-panou.panel>
@endsection
