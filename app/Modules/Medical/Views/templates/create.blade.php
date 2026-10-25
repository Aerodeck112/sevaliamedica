@extends('layouts.panou')
@section('title', 'Șablon nou')
@section('content')
    <x-panou.page-head title="Șablon nou" :crumbs="['Șabloane fișe' => route('panou.medical.templates.index'), 'Șablon nou' => null]" />
    <x-panou.panel style="max-width:720px">
        <form method="post" action="{{ route('panou.medical.templates.store') }}" class="stack">
            @csrf
            <x-panou.field name="name" label="Denumire" required />
            <x-panou.field name="specialty_id" type="select" label="Specialitate" :options="$specialties" placeholder="Orice specialitate" hint="Doar specialitățile confirmate (Administrare → Specialități)." />
            <x-panou.field name="description" type="textarea" label="Descriere internă" />
            <div class="form-actions"><button class="btn btn--primary" type="submit">Creează și editează structura</button></div>
        </form>
    </x-panou.panel>
@endsection
