@extends('layouts.panou')
@section('title', 'Meniuri')
@section('content')
    <x-panou.page-head title="Meniul principal" sub="Ordinea de aici este ordinea din antetul siteului. Modificările sunt vizibile imediat.">
        <x-slot:actions><a class="btn btn--primary" href="{{ route('panou.cms.menus.create') }}"><x-panou.icon name="plus" />Element nou</a></x-slot:actions>
    </x-panou.page-head>
    <x-panou.panel :flush="true">
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Ordine</th><th>Etichetă</th><th>Tip</th><th>Adresă / conținut</th><th>Stare</th><th></th></tr></thead>
                <tbody>
                @foreach ($items as $item)
                    <tr>
                        <td>
                            <div class="cluster">
                                <form method="post" action="{{ route('panou.cms.menus.move', $item) }}">@csrf<input type="hidden" name="dir" value="up"><button class="btn btn--small" @disabled($loop->first) aria-label="Mută mai sus">↑</button></form>
                                <form method="post" action="{{ route('panou.cms.menus.move', $item) }}">@csrf<input type="hidden" name="dir" value="down"><button class="btn btn--small" @disabled($loop->last) aria-label="Mută mai jos">↓</button></form>
                            </div>
                        </td>
                        <td><a class="row-link" href="{{ route('panou.cms.menus.edit', $item) }}">{{ $item->label }}</a></td>
                        <td>{{ \App\Modules\Cms\Models\MenuItem::KINDS[$item->kind] ?? $item->kind }}</td>
                        <td class="small">
                            @if ($item->kind === 'mega') {{ count(\App\Modules\Cms\Services\Blocks\Slots::extract($item->mega_block['html'] ?? '')) }} câmpuri editabile
                            @elseif ($item->kind === 'dropdown') {{ $item->children->count() }} subelemente
                            @else <code>{{ $item->url }}</code> @endif
                            @if (! empty($broken[$item->id]))
                                <div><x-panou.badge tone="danger">link către pagină inexistentă</x-panou.badge> @foreach ($broken[$item->id] as $b)<code>{{ $b }}</code> <a href="{{ route('panou.cms.pages.create', ['cale' => $b]) }}">creează pagina</a> @endforeach</div>
                            @endif
                        </td>
                        <td>@if ($item->is_visible)<x-panou.badge tone="ok">vizibil</x-panou.badge>@else<x-panou.badge>ascuns</x-panou.badge>@endif</td>
                        <td class="text-right"><a class="btn btn--small" href="{{ route('panou.cms.menus.edit', $item) }}">Editează</a></td>
                    </tr>
                    @foreach ($item->children as $child)
                        <tr>
                            <td></td>
                            <td>↳ <a class="row-link" href="{{ route('panou.cms.menus.edit', $child) }}">{{ $child->label }}</a></td>
                            <td>Subelement</td>
                            <td class="small"><code>{{ $child->url }}</code></td>
                            <td>@if ($child->is_visible)<x-panou.badge tone="ok">vizibil</x-panou.badge>@else<x-panou.badge>ascuns</x-panou.badge>@endif</td>
                            <td class="text-right"><a class="btn btn--small" href="{{ route('panou.cms.menus.edit', $child) }}">Editează</a></td>
                        </tr>
                    @endforeach
                @endforeach
                </tbody>
            </table>
        </div>
    </x-panou.panel>
@endsection
