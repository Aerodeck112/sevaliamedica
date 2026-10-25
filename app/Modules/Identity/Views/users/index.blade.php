@extends('layouts.panou')
@section('title', 'Utilizatori')
@section('content')
    <x-panou.page-head title="Utilizatori" sub="Conturi individuale. Rolurile determină ce poate vedea și face fiecare persoană.">
        <x-slot:actions>
            <a class="btn btn--primary" href="{{ route('panou.users.create') }}"><x-panou.icon name="plus" />Cont nou</a>
        </x-slot:actions>
    </x-panou.page-head>
    <x-panou.panel :flush="true">
        <form class="filters" method="get" role="search">
            <x-panou.field name="q" type="search" label="Caută după nume sau e-mail" :value="$q" />
            <button class="btn" type="submit">Caută</button>
        </form>
        @if ($users->isEmpty())
            <x-panou.empty title="Niciun cont găsit">Modificați căutarea sau creați un cont nou.</x-panou.empty>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Nume</th><th>E-mail</th><th>Roluri</th><th>MFA</th><th>Stare</th><th>Ultima autentificare</th></tr></thead>
                    <tbody>
                    @foreach ($users as $u)
                        <tr>
                            <td><a class="row-link" href="{{ route('panou.users.edit', $u) }}">{{ $u->name }}</a></td>
                            <td>{{ $u->email }}</td>
                            <td>{{ $u->roles->map(fn ($r) => \App\Modules\Identity\Roles::label($r->name))->join(', ') ?: '—' }}</td>
                            <td>@if ($u->hasMfaEnabled())<x-panou.badge tone="ok">activ</x-panou.badge>@else<x-panou.badge tone="warn">neconfigurat</x-panou.badge>@endif</td>
                            <td>@if ($u->is_active)<x-panou.badge tone="ok">activ</x-panou.badge>@else<x-panou.badge>dezactivat</x-panou.badge>@endif</td>
                            <td class="num">{{ $u->last_login_at?->format('d.m.Y H:i') ?? '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            {{ $users->links() }}
        @endif
    </x-panou.panel>
@endsection
