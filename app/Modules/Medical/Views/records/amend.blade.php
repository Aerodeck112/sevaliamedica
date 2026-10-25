@extends('layouts.panou')
@section('title', 'Corectură — '.$record->title)
@section('content')
    <x-panou.page-head title="Completare sau rectificare" :sub="$record->title.' · '.$record->patient->fullName()"
        :crumbs="[$record->patient->fullName() => route('panou.patients.show', [$record->patient, 'tab' => 'fise']), $record->title => route('panou.medical.records.show', $record), 'Corectură' => null]" />
    <x-panou.panel style="max-width:720px">
        <p>Fișa finalizată nu se modifică. Corectura devine o fișă nouă, legată de original, cu motivul de mai jos, autorul și data — și trece prin aceeași finalizare.</p>
        <form method="post" action="{{ route('panou.medical.records.amend', $record) }}" class="stack">
            @csrf
            <fieldset>
                <legend>Tip</legend>
                <label class="check"><input type="radio" name="kind" value="completare" @checked(old('kind', 'completare') === 'completare')><span><strong>Completare</strong> — adaugă informații noi, fără a înlocui conținutul original (text liber).</span></label>
                <label class="check" style="margin-top:.5rem"><input type="radio" name="kind" value="rectificare" @checked(old('kind') === 'rectificare')><span><strong>Rectificare</strong> — corectează conținutul; pornește de la valorile actuale, iar versiunea rectificată devine cea valabilă.</span></label>
            </fieldset>
            <x-panou.field name="reason" type="textarea" label="Motiv" required hint="Rămâne vizibil în istoricul fișei (minimum 10 caractere)." />
            <div class="form-actions">
                <button class="btn btn--primary" type="submit">Pornește corectura</button>
                <a class="btn btn--ghost" href="{{ route('panou.medical.records.show', $record) }}">Renunță</a>
            </div>
        </form>
    </x-panou.panel>
@endsection
