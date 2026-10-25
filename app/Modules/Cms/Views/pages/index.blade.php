@extends('layouts.panou')
@php $isArticles = $kind === \App\Modules\Cms\Models\Page::KIND_ARTICLE; @endphp
@section('title', $isArticles ? 'Articole blog' : 'Pagini')
@push('head') @vite('resources/css/cms.css') @endpush
@section('content')
    <x-panou.page-head :title="$isArticles ? 'Articole blog' : 'Pagini site'"
        :sub="$isArticles ? 'Articolele blogului. Data și autorul apar pe pagina articolului și în listă.' : 'Paginile publice ale siteului. Modificările se salvează ca ciornă și devin vizibile doar după publicare.'">
        <x-slot:actions>
            @unless ($isArticles)
                @if ($footer)<a class="btn" href="{{ route('panou.cms.pages.edit', $footer) }}"><x-panou.icon name="layers" />Editează subsolul</a>@endif
            @endunless
            <a class="btn btn--primary" href="{{ route('panou.cms.pages.create', $isArticles ? ['tip' => 'articol'] : []) }}"><x-panou.icon name="plus" />{{ $isArticles ? 'Articol nou' : 'Pagină nouă' }}</a>
        </x-slot:actions>
    </x-panou.page-head>

    @php $allBroken = collect($issues)->pluck('broken')->flatten()->merge(collect($menuBroken)->flatten())->unique()->values(); @endphp
    @if ($allBroken->isNotEmpty())
        <div class="alert alert--warn" role="status">
            <x-panou.icon name="warn" />
            <div>
                <strong>Linkuri interne către pagini inexistente:</strong>
                @foreach ($allBroken as $link)
                    <code>{{ $link }}</code>@if (! $loop->last), @endif
                @endforeach
                <div class="small">Creați pagina lipsă (butonul „Creează” de lângă link) sau schimbați linkul în pagina/meniul care îl conține. Nu se creează conținut automat.</div>
                <div class="cluster" style="margin-top:.5rem">
                    @foreach ($allBroken as $link)
                        <a class="btn btn--small" href="{{ route('panou.cms.pages.create', ['cale' => $link]) }}">Creează {{ $link }}</a>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    <x-panou.panel :flush="true">
        <form class="filters" method="get" role="search">
            <x-panou.field name="q" type="search" label="Caută după titlu sau adresă" :value="$q" />
            <button class="btn" type="submit">Caută</button>
        </form>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Titlu</th><th>Adresă</th>@if ($isArticles)<th>Data</th>@endif<th>Stare</th><th>Atenționări</th><th>Actualizat</th></tr></thead>
                <tbody>
                @forelse ($pages as $p)
                    <tr>
                        <td><a class="row-link" href="{{ route('panou.cms.pages.edit', $p) }}">{{ \Illuminate\Support\Str::limit($p->title, 70) }}</a></td>
                        <td><code class="small">{{ $p->path }}</code></td>
                        @if ($isArticles)<td class="num">{{ $p->published_at?->format('d.m.Y') }}</td>@endif
                        <td>
                            @if ($p->status === 'published')<x-panou.badge tone="ok">publicată</x-panou.badge>@else<x-panou.badge>ciornă</x-panou.badge>@endif
                            @if ($p->hasUnpublishedChanges())<x-panou.badge tone="warn">modificări nepublicate</x-panou.badge>@endif
                            @if ($p->lockedByOther(auth()->user()))<x-panou.badge tone="info">editată de {{ $p->editingUser?->name }}</x-panou.badge>@endif
                        </td>
                        <td class="small">
                            @if ($issues[$p->id]['broken'])<x-panou.badge tone="danger">{{ count($issues[$p->id]['broken']) }} link rupt</x-panou.badge>@endif
                            @if ($issues[$p->id]['no_description'])<x-panou.badge tone="warn">fără descriere SEO</x-panou.badge>@endif
                        </td>
                        <td class="num small">{{ $p->updated_at?->format('d.m.Y H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6"><x-panou.empty title="Nicio pagină">Modificați căutarea.</x-panou.empty></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-panou.panel>
@endsection
