@extends('layouts.panou')
@section('title', 'Pachete de ședințe')
@section('content')
    <x-panou.page-head title="Pachete de ședințe" sub="Tipurile de pachete oferite de clinică: număr de ședințe, valabilitate și serviciile incluse. Tarifele nu se gestionează aici." />
    <div class="grid grid--aside">
        <x-panou.panel :flush="true">
            @if ($definitions->isEmpty())
                <x-panou.empty title="Niciun tip de pachet">Dacă clinica nu vinde pachete, lăsați lista goală; funcția rămâne inactivă.</x-panou.empty>
            @else
                <table class="table">
                    <thead><tr><th>Pachet</th><th>Ședințe</th><th>Valabilitate</th><th>Servicii</th><th>Stare</th></tr></thead>
                    <tbody>
                    @foreach ($definitions as $d)
                        <tr>
                            <td><a class="row-link" href="{{ route('panou.rehab.packages.index', ['edit' => $d->id]) }}">{{ $d->name }}</a></td>
                            <td class="num">{{ $d->sessions_count }}</td>
                            <td>{{ $d->validity_days ? $d->validity_days.' zile' : 'fără termen' }}</td>
                            <td class="small">{{ collect($d->service_ids)->map(fn ($id) => $services[$id] ?? '#'.$id)->join(', ') }}</td>
                            <td>@if ($d->is_active)<x-panou.badge tone="ok">activ</x-panou.badge>@else<x-panou.badge>inactiv</x-panou.badge>@endif</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </x-panou.panel>
        <x-panou.panel :title="$editing ? 'Editează: '.$editing->name : 'Tip nou de pachet'">
            <form method="post" action="{{ $editing ? route('panou.rehab.packages.update', $editing) : route('panou.rehab.packages.store') }}" class="stack">
                @csrf @if ($editing) @method('put') @endif
                <x-panou.field name="name" label="Denumire" :value="$editing?->name" required />
                <x-panou.field name="sessions_count" type="number" label="Număr de ședințe" :value="$editing?->sessions_count" required />
                <x-panou.field name="validity_days" type="number" label="Valabilitate (zile)" :value="$editing?->validity_days" hint="Gol = fără termen." />
                <fieldset class="field">
                    <legend class="field__label">Servicii incluse</legend>
                    @foreach ($services as $id => $name)
                        <label class="check"><input type="checkbox" name="service_ids[]" value="{{ $id }}" @checked(in_array($id, old('service_ids', $editing?->service_ids ?? [])))><span>{{ $name }}</span></label>
                    @endforeach
                    @error('service_ids')<span class="field__error">{{ $message }}</span>@enderror
                </fieldset>
                <x-panou.field name="notes" label="Note" :value="$editing?->notes" />
                <x-panou.field name="is_active" type="checkbox" label="Activ" :value="$editing?->is_active ?? true" />
                <button class="btn btn--primary" type="submit">Salvează</button>
            </form>
        </x-panou.panel>
    </div>
@endsection
