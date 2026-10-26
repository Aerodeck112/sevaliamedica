@extends('layouts.panou')
@section('title', $record->title)
@section('content')
    <x-panou.page-head :title="$record->title" :sub="$record->kindLabel().' · draft · '.$record->patient->fullName()"
        :crumbs="['Pacienți' => route('panou.patients.index'), $record->patient->fullName() => route('panou.patients.show', [$record->patient, 'tab' => 'fise']), 'Draft' => null]" />

    @if ($record->isAmendment())
        <div class="alert alert--info"><x-panou.icon name="file" />
            <div><strong>{{ $record->kindLabel() }} la fișa „{{ $record->amends->title }}”</strong> (finalizată {{ $record->amends->finalized_at->format('d.m.Y H:i') }}).
                Motiv: {{ $record->amendment_reason }}. Fișa originală rămâne neschimbată și vizibilă în istoric.</div>
        </div>
    @endif

    <div class="grid grid--aside">
        <x-panou.panel>
            <form method="post" action="{{ route('panou.medical.records.update', $record) }}" id="record-form">
                @csrf @method('put')
                <input type="hidden" name="version" value="{{ $record->version }}">
                <x-panou.field name="title" label="Titlu" :value="$record->title" full />
                @error('version')<div class="alert alert--danger" role="alert"><x-panou.icon name="warn" /><div>{{ $message }}</div></div>@enderror
                <div style="margin-top:1.25rem">
                    @include('medical::partials.fields-form', ['schema' => $schema, 'values' => $values])
                </div>
                <div class="form-actions">
                    <button class="btn btn--primary" type="submit">Salvează draftul</button>
                    <span class="small muted">Salvarea nu finalizează fișa. Draftul îl vedeți doar dumneavoastră și personalul cu acces la fișa pacientului.</span>
                </div>
            </form>
        </x-panou.panel>

        <div class="stack">
            <x-panou.panel title="Finalizare">
                <p class="med-legal">Finalizarea blochează conținutul: nu mai poate fi modificat, doar completat sau rectificat printr-o versiune nouă, cu motiv. Se cere reconfirmarea parolei și a codului de verificare.
                    <strong>Finalizarea în aplicație nu este o semnătură electronică calificată.</strong></p>
                @if ($missing)
                    <div class="alert alert--warn"><x-panou.icon name="warn" /><div>Câmpuri obligatorii necompletate (în ultima versiune salvată): {{ implode(', ', $missing) }}.</div></div>
                @endif
                @can('medical.finalize')
                    <form method="post" action="{{ route('panou.medical.records.finalize', $record) }}" class="stack" data-confirm="Finalizați fișa? Conținutul salvat devine definitiv.">
                        @csrf
                        <label class="check"><input type="checkbox" name="confirm" value="1" required><span>Am verificat conținutul salvat și îl finalizez.</span></label>
                        <button class="btn btn--primary" type="submit">Finalizează</button>
                        <span class="small muted">Salvați întâi modificările — se finalizează ultima versiune salvată.</span>
                    </form>
                @else
                    <p class="small">Contul dumneavoastră nu are dreptul de a finaliza fișe.</p>
                @endcan
            </x-panou.panel>
            <x-panou.panel title="Renunțare">
                <form method="post" action="{{ route('panou.medical.records.abandon', $record) }}" class="stack" data-confirm="Abandonați draftul? Rămâne în jurnal, dar nu mai poate fi editat.">
                    @csrf
                    <x-panou.field name="reason" label="Motiv" required />
                    <button class="btn btn--danger btn--small" type="submit">Abandonează draftul</button>
                </form>
            </x-panou.panel>
        </div>
    </div>
@endsection
