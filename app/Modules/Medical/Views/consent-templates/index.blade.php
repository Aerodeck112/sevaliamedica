@extends('layouts.panou')
@section('title', 'Modele consimțământ')
@section('content')
    <x-panou.page-head title="Modele de consimțământ" sub="Textele le furnizează și le validează clinica (juridic și medical). Fiecare modificare publică o versiune nouă; consimțămintele consemnate rămân legate de versiunea lor." />
    <div class="grid grid--aside">
        <x-panou.panel :flush="true">
            @if ($templates->isEmpty())
                <x-panou.empty title="Niciun model">Adăugați un model și introduceți textul primit de la clinică.</x-panou.empty>
            @else
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>Model</th><th>Se aplică la</th><th>Versiune</th><th>Stare</th></tr></thead>
                        <tbody>
                        @foreach ($templates as $t)
                            <tr>
                                <td><a class="row-link" href="{{ route('panou.medical.consent-templates.edit', $t) }}">{{ $t->name }}</a></td>
                                <td>{{ $t->scope ?? '—' }}</td>
                                <td>@if ($t->isReady()) v{{ $t->latestVersion->version }} @else<x-panou.badge tone="warn">de completat de clinică</x-panou.badge>@endif</td>
                                <td>@if ($t->is_active)<x-panou.badge tone="ok">activ</x-panou.badge>@else<x-panou.badge>inactiv</x-panou.badge>@endif</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-panou.panel>
        <x-panou.panel title="Model nou">
            <form method="post" action="{{ route('panou.medical.consent-templates.store') }}" class="stack">
                @csrf
                <x-panou.field name="name" label="Denumire" required />
                <x-panou.field name="scope" label="Procedura / situația" />
                <button class="btn btn--primary" type="submit">Adaugă</button>
            </form>
        </x-panou.panel>
    </div>
@endsection
