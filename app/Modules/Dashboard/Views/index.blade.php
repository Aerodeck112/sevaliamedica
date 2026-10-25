@extends('layouts.panou')
@section('title', 'Acasă')
@section('content')
    <x-panou.page-head :title="'Bună ziua, '.\Illuminate\Support\Str::before($user->name, ' ')" :sub="now()->locale('ro')->isoFormat('dddd, D MMMM YYYY')" />
    @if ($widgets)
        <div class="grid grid--2">
            @foreach ($widgets as $widget)
                <div class="{{ $widget->span() === 2 ? 'span-2' : '' }}">{!! $widget->render($user) !!}</div>
            @endforeach
        </div>
    @elseif ($shortcuts->isNotEmpty())
        <x-panou.panel title="Secțiunile la care aveți acces">
            <ul class="nav-list" style="grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:.5rem">
                @foreach ($shortcuts as $item)
                    <li><a class="btn" style="width:100%;justify-content:flex-start" href="{{ route($item->route) }}"><x-panou.icon :name="$item->icon" />{{ $item->label }}</a></li>
                @endforeach
            </ul>
        </x-panou.panel>
    @else
        <x-panou.panel>
            <x-panou.empty title="Contul nu are încă un rol">Administratorul tehnic vă poate atribui rolul potrivit activității dumneavoastră.</x-panou.empty>
        </x-panou.panel>
    @endif
@endsection
