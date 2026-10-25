@extends('layouts.panou')
@section('title', 'Serii de ședințe')
@section('content')
    <x-panou.page-head title="Serii de ședințe" sub="Programări repetate pentru proceduri și recuperare. Evidența arată ședințele efectuate, anulate și rămase.">
        <x-slot:actions>
            @can('scheduling.manage')<a class="btn btn--primary" href="{{ route('panou.series.create') }}"><x-panou.icon name="plus" />Serie nouă</a>@endcan
        </x-slot:actions>
    </x-panou.page-head>
    <x-panou.panel :flush="true">
        <div class="tabs" style="padding:0 1rem;margin:0">
            <a href="{{ route('panou.series.index') }}" @if (request('status', 'activa') === 'activa') aria-current="page" @endif>Active</a>
            <a href="{{ route('panou.series.index', ['status' => 'inchisa']) }}" @if (request('status') === 'inchisa') aria-current="page" @endif>Închise</a>
            <a href="{{ route('panou.series.index', ['status' => 'toate']) }}" @if (request('status') === 'toate') aria-current="page" @endif>Toate</a>
        </div>
        @if ($series->isEmpty())
            <x-panou.empty title="Nicio serie">Creați o serie din fișa pacientului sau cu butonul „Serie nouă”.</x-panou.empty>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Pacient</th><th>Serviciu</th><th>Persoana</th><th>Efectuate / planificate</th><th>Rămase</th><th>Creată</th></tr></thead>
                    <tbody>
                    @foreach ($series as $s)
                        @php $c = $s->counts(); @endphp
                        <tr>
                            <td><a class="row-link" href="{{ route('panou.series.show', $s) }}">{{ $s->patient->fullName() }}</a></td>
                            <td>{{ $s->service->name }}</td>
                            <td>{{ $s->staffMember?->display_name ?? '—' }}</td>
                            <td class="num">{{ $c['done'] }} / {{ $c['planned'] }}</td>
                            <td class="num">{{ $c['remaining'] }}</td>
                            <td class="num">{{ $s->created_at->format('d.m.Y') }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            {{ $series->links() }}
        @endif
    </x-panou.panel>
@endsection
