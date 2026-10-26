@extends('layouts.panou')
@section('title', 'Ședințe de marcat')
@section('content')
    <x-panou.page-head title="Ședințele mele" sub="Ședințele din planurile de recuperare se marchează efectuate doar de dumneavoastră, după ce au avut loc. Ora trecută nu înseamnă ședință efectuată." />
    @if (! $staff)
        <x-panou.panel><x-panou.empty title="Contul nu este legat de o persoană din personal">Administratorul vă poate asocia contul în Administrare → Personal.</x-panou.empty></x-panou.panel>
    @else
        <div class="grid grid--2">
            <x-panou.panel title="Azi" :flush="true">
                @if ($today->isEmpty())
                    <x-panou.empty title="Nicio ședință azi">Ședințele programate din planuri apar aici.</x-panou.empty>
                @else
                    <table class="table">
                        <thead><tr><th>Ora</th><th>Pacient</th><th>Procedura</th><th></th></tr></thead>
                        <tbody>
                        @foreach ($today as $a)
                            <tr>
                                <td class="num">{{ $a->starts_at->format('H:i') }}</td>
                                <td>{{ $a->patient?->fullName() }}</td>
                                <td>{{ $a->service?->name }}</td>
                                <td class="text-right">
                                    @if (isset($done[$a->id]))
                                        <x-panou.badge tone="ok">efectuată</x-panou.badge>
                                    @elseif ($a->starts_at->lte(now()->addMinutes(15)))
                                        <a class="btn btn--small btn--primary" href="{{ route('panou.rehab.sessions.create', $a) }}">Marchează efectuată</a>
                                    @else
                                        <span class="small muted">după ora programării</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </x-panou.panel>
            <x-panou.panel title="De închis (zile trecute, nemarcate)" :flush="true">
                @if ($pending->isEmpty())
                    <x-panou.empty title="Nimic de închis">Toate ședințele trecute sunt marcate sau rezolvate.</x-panou.empty>
                @else
                    <table class="table">
                        <thead><tr><th>Data</th><th>Pacient</th><th>Procedura</th><th></th></tr></thead>
                        <tbody>
                        @foreach ($pending as $a)
                            <tr>
                                <td class="num">{{ $a->starts_at->format('d.m H:i') }}</td>
                                <td>{{ $a->patient?->fullName() }}</td>
                                <td>{{ $a->service?->name }}</td>
                                <td class="text-right">
                                    <a class="btn btn--small" href="{{ route('panou.rehab.sessions.create', $a) }}">A avut loc</a>
                                    <a class="btn btn--small btn--ghost" href="{{ route('panou.appointments.show', $a) }}">Nu a avut loc</a>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                    <p class="small muted" style="padding:0 1.25rem 1rem">„Nu a avut loc”: recepția marchează programarea „neprezentat” sau „anulată”, cu motiv.</p>
                @endif
            </x-panou.panel>
        </div>
    @endif
@endsection
