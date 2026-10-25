@extends('layouts.panou')
@section('title', $titles['plural'])
@section('content')
    <x-panou.page-head :title="$titles['plural']" :sub="$description">
        <x-slot:actions>
            <a class="btn btn--primary" href="{{ route($routeBase.'.create') }}"><x-panou.icon name="plus" />{{ $titles['new'] }}</a>
        </x-slot:actions>
    </x-panou.page-head>
    <x-panou.panel :flush="true">
        @if ($searchable)
            <form class="filters" method="get" role="search">
                <x-panou.field name="q" type="search" label="Caută" :value="$q" />
                <button class="btn" type="submit">Caută</button>
            </form>
        @endif
        @if ($items->isEmpty())
            <x-panou.empty :title="'Nicio înregistrare'">
                <a href="{{ route($routeBase.'.create') }}">{{ $titles['new'] }}</a>
            </x-panou.empty>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr>@foreach ($fields as $f)<th>{{ $f->label }}</th>@endforeach<th></th></tr></thead>
                    <tbody>
                    @foreach ($items as $item)
                        <tr>
                            @foreach ($fields as $i => $f)
                                @php
                                    $value = $f->display ? ($f->display)($item) : $item->{$f->name};
                                    if ($f->type === 'checkbox' && ! $f->display) { $value = $value ? 'da' : 'nu'; }
                                    if ($f->type === 'select' && ! $f->display) { $value = $f->resolvedOptions()[$value] ?? $value; }
                                @endphp
                                <td>
                                    @if ($i === 0)<a class="row-link" href="{{ route($routeBase.'.edit', $item->id) }}">{{ $value }}</a>@else{{ $value }}@endif
                                </td>
                            @endforeach
                            <td class="text-right"><a class="btn btn--small" href="{{ route($routeBase.'.edit', $item->id) }}">Editează</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            {{ $items->links() }}
        @endif
    </x-panou.panel>
@endsection
