@extends('layouts.panou')
@section('title', 'Istoric: '.$page->title)
@section('content')
    <x-panou.page-head :title="'Istoricul versiunilor'" :sub="$page->title.' — fiecare salvare este o versiune nouă; versiunile vechi nu se modifică și nu se șterg.'"
        :crumbs="['Pagini' => route('panou.cms.pages.index'), $page->title => route('panou.cms.pages.edit', $page), 'Istoric' => null]" />
    <x-panou.panel :flush="true">
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Versiune</th><th>Salvată</th><th>Autor</th><th>Descriere</th><th>Stare</th><th></th></tr></thead>
                <tbody>
                @foreach ($versions as $v)
                    <tr>
                        <td class="num"><strong>v{{ $v->number }}</strong></td>
                        <td class="num">{{ $v->created_at?->format('d.m.Y H:i') }}</td>
                        <td>{{ $v->author?->name ?? 'import / sistem' }}</td>
                        <td>{{ $v->note }}</td>
                        <td>
                            @if ($page->published_version_id === $v->id)<x-panou.badge tone="ok">publicată acum</x-panou.badge>@elseif ($v->published_at)<x-panou.badge>publicată {{ $v->published_at->format('d.m.Y') }}</x-panou.badge>@endif
                            @if ($page->current_version_id === $v->id)<x-panou.badge tone="accent">ciorna curentă</x-panou.badge>@endif
                        </td>
                        <td class="text-right">
                            <div class="cluster" style="justify-content:flex-end">
                                <a class="btn btn--small" href="{{ route('panou.cms.pages.preview', [$page, 'versiune' => $v->number]) }}" target="_blank" rel="noopener">Previzualizează</a>
                                @can('cms.publish')
                                    @if ($page->current_version_id !== $v->id)
                                        <form method="post" action="{{ route('panou.cms.pages.restore', [$page, $v]) }}" data-confirm="Restaurați versiunea v{{ $v->number }} ca ciornă nouă?">@csrf<button class="btn btn--small" type="submit">Restaurează</button></form>
                                    @endif
                                    @if ($page->published_version_id !== $v->id)
                                        <form method="post" action="{{ route('panou.cms.pages.publish', $page) }}" data-confirm="Publicați direct versiunea v{{ $v->number }}?">@csrf<input type="hidden" name="version" value="{{ $v->id }}"><button class="btn btn--small" type="submit">Publică</button></form>
                                    @endif
                                @endcan
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        {{ $versions->links() }}
    </x-panou.panel>
    @can('cms.publish')
        @if ($page->isPublished() && $page->kind !== \App\Modules\Cms\Models\Page::KIND_GLOBAL)
            <x-panou.panel title="Retragere">
                <p class="small">Retragerea face pagina invizibilă public (404). Conținutul și istoricul rămân.</p>
                <form method="post" action="{{ route('panou.cms.pages.unpublish', $page) }}" data-confirm="Retrageți pagina de pe site?">@csrf<button class="btn btn--danger" type="submit">Retrage pagina</button></form>
            </x-panou.panel>
        @endif
    @endcan
@endsection
