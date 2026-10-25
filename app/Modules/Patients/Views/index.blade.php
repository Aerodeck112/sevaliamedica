@extends('layouts.panou')
@section('title', 'Pacienți')
@section('content')
    <x-panou.page-head title="Pacienți" sub="Căutați după nume, telefon, CNP complet sau numărul fișei. Verificați întotdeauna identitatea înainte de a deschide o fișă.">
        <x-slot:actions>
            @can('patients.create')
                <a class="btn btn--primary" href="{{ route('panou.patients.create') }}"><x-panou.icon name="plus" />Pacient nou</a>
            @endcan
        </x-slot:actions>
    </x-panou.page-head>
    <x-panou.panel :flush="true">
        <form class="filters" method="get" role="search">
            <x-panou.field name="q" type="search" label="Caută pacient" :value="$q" autofocus />
            <button class="btn" type="submit"><x-panou.icon name="search" />Caută</button>
        </form>
        @if ($patients->isEmpty())
            <x-panou.empty title="{{ $q !== '' ? 'Niciun pacient găsit' : 'Încă nu există pacienți' }}">
                @if ($q !== '')Verificați scrierea numelui sau căutați după telefon.@endif
                @can('patients.create') <a href="{{ route('panou.patients.create') }}">Creați o fișă nouă</a>.@endcan
            </x-panou.empty>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Nume</th><th>Data nașterii</th><th>Telefon</th><th>Localitate</th><th>Fișa</th></tr></thead>
                    <tbody>
                    @foreach ($patients as $p)
                        <tr>
                            <td><a class="row-link" href="{{ route('panou.patients.show', $p) }}">{{ $p->fullName() }}</a></td>
                            <td class="num">{{ $p->birth_date?->format('d.m.Y') ?? '—' }}</td>
                            <td class="num">{{ $p->phone ?? '—' }}</td>
                            <td>{{ $p->city ?? '—' }}</td>
                            <td class="num muted">#{{ $p->id }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            {{ $patients->links() }}
        @endif
    </x-panou.panel>
@endsection
