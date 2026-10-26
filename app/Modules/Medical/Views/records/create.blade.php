@extends('layouts.panou')
@section('title', 'Fișă nouă — '.$patient->fullName())
@section('content')
    <x-panou.page-head title="Fișă nouă" :sub="$patient->fullName()"
        :crumbs="['Pacienți' => route('panou.patients.index'), $patient->fullName() => route('panou.patients.show', [$patient, 'tab' => 'fise']), 'Fișă nouă' => null]" />
    <x-panou.panel style="max-width:760px">
        @if ($templates->isEmpty())
            <div class="alert alert--info"><x-panou.icon name="warn" /><div>Nu există încă șabloane publicate. Puteți scrie o notă clinică în text liber sau un medic poate configura șabloane în Medical → Șabloane fișe.</div></div>
        @endif
        <form method="post" action="{{ route('panou.medical.records.store', $patient) }}" class="stack">
            @csrf
            <input type="hidden" name="rehab_plan_id" value="{{ $selected['rehab_plan_id'] }}">
            <div class="form-grid">
                <x-panou.field name="kind" type="select" label="Tip" :value="$selected['kind']" :placeholder="false"
                    :options="['consultatie' => 'Consultație', 'reevaluare' => 'Reevaluare', 'nota' => 'Notă clinică']" required />
                <x-panou.field name="template_id" type="select" label="Șablon" :value="$selected['template_id']" placeholder="Fără șablon (text liber)"
                    :options="$templates->mapWithKeys(fn ($t) => [$t->id => $t->name.($t->specialty ? ' · '.$t->specialty->name : '').' (v'.$t->latestVersion->version.')'])->all()" />
                <x-panou.field name="appointment_id" type="select" label="Programarea (opțional)" :value="$selected['appointment_id']" placeholder="Fără programare"
                    :options="$appointments->mapWithKeys(fn ($a) => [$a->id => $a->starts_at->format('d.m.Y H:i').' · '.($a->service?->name ?? 'programare')])->all()" full />
                <x-panou.field name="title" label="Titlu (opțional)" hint="Implicit: numele șablonului." full />
            </div>
            <div class="form-actions">
                <button class="btn btn--primary" type="submit">Creează draftul</button>
                <a class="btn btn--ghost" href="{{ route('panou.patients.show', [$patient, 'tab' => 'fise']) }}">Renunță</a>
            </div>
        </form>
    </x-panou.panel>
@endsection
