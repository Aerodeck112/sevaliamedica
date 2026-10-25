@extends('layouts.panou')
@section('title', 'Șabloane fișe')
@section('content')
    <x-panou.page-head title="Șabloane de fișă" sub="Structura fișelor, definită de medicii clinicii pe specialitățile confirmate. Aplicația nu propune câmpuri sau protocoale medicale.">
        <x-slot:actions><a class="btn btn--primary" href="{{ route('panou.medical.templates.create') }}"><x-panou.icon name="plus" />Șablon nou</a></x-slot:actions>
    </x-panou.page-head>
    <x-panou.panel :flush="true">
        @if ($templates->isEmpty())
            <x-panou.empty title="Niciun șablon">Creați primul șablon; până atunci, medicii pot scrie note clinice în text liber.</x-panou.empty>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Șablon</th><th>Specialitate</th><th>Versiune publicată</th><th>Fișe create</th><th>Stare</th></tr></thead>
                    <tbody>
                    @foreach ($templates as $t)
                        <tr>
                            <td><a class="row-link" href="{{ route('panou.medical.templates.edit', $t) }}">{{ $t->name }}</a>
                                @if ($t->is_example)<x-panou.badge tone="info">exemplu de adaptat</x-panou.badge>@endif
                                @if ($t->description)<div class="small muted">{{ $t->description }}</div>@endif</td>
                            <td>{{ $t->specialty?->name ?? 'Orice specialitate' }}</td>
                            <td>@if ($t->latestVersion) v{{ $t->latestVersion->version }} <span class="small muted">· {{ $t->latestVersion->published_at->format('d.m.Y') }}</span>@else<x-panou.badge tone="warn">nepublicat</x-panou.badge>@endif
                                @if ($t->latestVersion && $t->hasUnpublishedChanges())<div class="small muted">modificări nepublicate</div>@endif</td>
                            <td class="num">{{ $usage[$t->id] ?? 0 }}</td>
                            <td>@if ($t->is_active)<x-panou.badge tone="ok">activ</x-panou.badge>@else<x-panou.badge>inactiv</x-panou.badge>@endif</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-panou.panel>
@endsection
