@extends('layouts.panou')
@section('title', 'Program de lucru')
@section('content')
    <x-panou.page-head title="Program de lucru" sub="Programul săptămânal al fiecărei persoane, concediile, orele suplimentare și închiderile locațiilor. Calendarul și verificarea programărilor folosesc aceste date." />
    <div class="grid grid--aside">
        <x-panou.panel title="Personal" :flush="true">
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Persoană</th><th>Categorie</th><th>Program</th><th></th></tr></thead>
                    <tbody>
                    @forelse ($staff as $row)
                        <tr>
                            <td><a class="row-link" href="{{ route('panou.schedules.show', $row->member) }}">{{ $row->member->display_name }}</a></td>
                            <td>{{ $row->member->kindLabel() }}</td>
                            <td>@if ($row->templates)<x-panou.badge tone="ok">{{ $row->templates }} intervale/săpt.</x-panou.badge>@else<x-panou.badge tone="warn">neconfigurat</x-panou.badge>@endif</td>
                            <td class="text-right"><a class="btn btn--small" href="{{ route('panou.schedules.show', $row->member) }}">Configurează</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="4"><x-panou.empty title="Niciun medic sau terapeut activ">Adăugați personalul în Administrare → Personal.</x-panou.empty></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </x-panou.panel>
        <x-panou.panel title="Închideri (sărbători, întreaga locație)">
            @forelse ($closures as $c)
                <div class="cluster" style="justify-content:space-between;margin-bottom:.5rem">
                    <span><strong>{{ $c->starts_at->format('d.m.Y H:i') }} – {{ $c->ends_at->format('d.m.Y H:i') }}</strong><br><span class="small muted">{{ $c->location?->name ?? 'toate locațiile' }}{{ $c->note ? ' · '.$c->note : '' }}</span></span>
                    <form method="post" action="{{ route('panou.schedules.exceptions.destroy', $c) }}">@csrf @method('delete')<button class="btn btn--small" type="submit">Șterge</button></form>
                </div>
            @empty
                <p class="muted">Nicio închidere programată.</p>
            @endforelse
            <hr class="sep">
            <form method="post" action="{{ route('panou.schedules.exceptions.store') }}" class="stack">
                @csrf <input type="hidden" name="kind" value="inchidere">
                <x-panou.field name="location_id" type="select" label="Locație" :options="$locations" placeholder="Toate locațiile" />
                <x-panou.field name="starts_at" type="datetime-local" label="De la" required />
                <x-panou.field name="ends_at" type="datetime-local" label="Până la" required />
                <x-panou.field name="note" label="Notă" placeholder="ex. Sărbătoare legală" />
                <button class="btn" type="submit">Adaugă închidere</button>
            </form>
        </x-panou.panel>
    </div>
@endsection
