@extends('layouts.panou')
@section('title', 'Solicitări')
@section('content')
    <x-panou.page-head title="Solicitări de programare" sub="Cereri venite de pe site sau înregistrate fără interval. O solicitare nu este o programare: identificați pacientul, alegeți intervalul, apoi confirmați." />

    <x-panou.panel title="Solicitări noi" :flush="true">
        @if ($requests->isEmpty())
            <x-panou.empty title="Nicio solicitare în așteptare">Solicitările de pe site apar aici imediat după trimitere.</x-panou.empty>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Primită</th><th>Cod</th><th>Nume (din formular)</th><th>Specialitate / serviciu</th><th>Medic dorit</th><th>Interval dorit</th><th>Pacient</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($requests as $r)
                        <tr>
                            <td class="num">{{ $r->created_at->format('d.m.Y H:i') }}</td>
                            <td class="num">{{ $r->request_reference ?? '—' }}</td>
                            <td><a class="row-link" href="{{ route('panou.requests.show', $r) }}">{{ $r->displayName() }}</a></td>
                            <td>{{ $r->service?->name ?? $r->requestSpecialty?->name ?? '—' }}</td>
                            <td>{{ $r->staffMember?->display_name ?? '—' }}</td>
                            <td class="num">{{ $r->request_preferred_start?->format('d.m.Y H:i') ?? '—' }}</td>
                            <td>@if ($r->patient)<x-panou.badge tone="ok">identificat</x-panou.badge>@else<x-panou.badge tone="warn">de identificat</x-panou.badge>@endif</td>
                            <td class="text-right"><a class="btn btn--small" href="{{ route('panou.requests.show', $r) }}">Preia</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            {{ $requests->links() }}
        @endif
    </x-panou.panel>

    @if ($unidentified->isNotEmpty())
        <x-panou.panel title="Programări confirmate automat, cu pacient neidentificat" :flush="true">
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Interval</th><th>Nume (din formular)</th><th>Serviciu</th><th>Persoana</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($unidentified as $a)
                        <tr>
                            <td class="num">{{ $a->starts_at->format('d.m.Y H:i') }}</td>
                            <td>{{ $a->displayName() }}</td>
                            <td>{{ $a->service?->name }}</td>
                            <td>{{ $a->staffMember?->display_name }}</td>
                            <td class="text-right"><a class="btn btn--small" href="{{ route('panou.requests.show', $a) }}">Identifică</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </x-panou.panel>
    @endif

    @if ($reschedule->isNotEmpty())
        <x-panou.panel title="Pacienți care au cerut reprogramarea (prin link)" :flush="true">
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Programare</th><th>Pacient</th><th>Serviciu</th><th>Cerere</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($reschedule as $a)
                        <tr>
                            <td class="num">{{ $a->starts_at->format('d.m.Y H:i') }}</td>
                            <td>{{ $a->displayName() }}</td>
                            <td>{{ $a->service?->name }}</td>
                            <td class="num">{{ $a->reschedule_requested_at->format('d.m.Y H:i') }}</td>
                            <td class="text-right"><a class="btn btn--small" href="{{ route('panou.appointments.show', $a) }}#muta">Reprogramează</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </x-panou.panel>
    @endif
@endsection
