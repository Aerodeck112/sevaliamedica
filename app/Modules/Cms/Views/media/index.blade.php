@extends('layouts.panou')
@section('title', 'Bibliotecă media')
@push('head') @vite('resources/css/cms.css') @endpush
@section('content')
    <x-panou.page-head title="Bibliotecă media" sub="Imaginile publice ale siteului. Originalele se păstrează; pentru afișare se generează automat variante WebP pe mai multe lățimi." />
    <x-panou.panel title="Încarcă imagini">
        <form method="post" action="{{ route('panou.cms.media.store') }}" enctype="multipart/form-data" class="cluster" style="align-items:flex-end">
            @csrf
            <div class="field" style="flex:1 1 280px">
                <label class="field__label" for="f_files">Fișiere (JPG, PNG, WebP, GIF; max. 10 MB fiecare)</label>
                <input id="f_files" type="file" name="files[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple required>
                @error('files')<span class="field__error">{{ $message }}</span>@enderror
                @error('files.*')<span class="field__error">{{ $message }}</span>@enderror
                @error('file')<span class="field__error">{{ $message }}</span>@enderror
            </div>
            <div style="flex:1 1 240px"><x-panou.field name="alt" label="Text alternativ (opțional, comun)" /></div>
            <button class="btn btn--primary" type="submit"><x-panou.icon name="upload" />Încarcă</button>
        </form>
        <p class="small muted" style="margin-top:.75rem">Fișierele sunt verificate după conținut (nu doar după extensie) și reencodate, ceea ce elimină metadatele (ex. locația GPS). SVG-urile nu sunt acceptate la încărcare din motive de securitate.</p>
    </x-panou.panel>
    <x-panou.panel :flush="true">
        <form class="filters" method="get" role="search">
            <x-panou.field name="q" type="search" label="Caută după nume, text alternativ sau ID" :value="$q" />
            <x-panou.field name="filtru" type="select" label="Filtru" :value="$filter" :options="['fara-alt' => 'Fără text alternativ']" placeholder="Toate" />
            <button class="btn" type="submit">Filtrează</button>
        </form>
        <div class="panel__body">
            @if ($media->isEmpty())
                <x-panou.empty title="Nicio imagine">Încărcați imagini sau modificați căutarea.</x-panou.empty>
            @else
                <div class="media-grid">
                    @foreach ($media as $m)
                        <article class="media-card">
                            <img src="{{ $m->bestUrl(320) }}" alt="" loading="lazy" width="200" height="140">
                            <div class="media-card__body">
                                <div class="cluster"><strong>#{{ $m->id }}</strong> <span class="muted">{{ $m->width }}×{{ $m->height }}</span> @if ($m->source === 'import')<x-panou.badge>din siteul original</x-panou.badge>@endif</div>
                                <div class="small muted" style="word-break:break-all">{{ \Illuminate\Support\Str::limit($m->original_name, 60) }}</div>
                                <form method="post" action="{{ route('panou.cms.media.update', $m) }}" class="stack">
                                    @csrf @method('put')
                                    <label class="small" for="alt_{{ $m->id }}">Text alternativ</label>
                                    <input id="alt_{{ $m->id }}" type="text" name="alt" value="{{ $m->alt }}" maxlength="300">
                                    <button class="btn btn--small" type="submit">Salvează</button>
                                </form>
                                <form method="post" action="{{ route('panou.cms.media.destroy', $m) }}" data-confirm="Ștergeți definitiv imaginea #{{ $m->id }}?">
                                    @csrf @method('delete')
                                    <button class="btn btn--small btn--danger" type="submit">Șterge</button>
                                </form>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </div>
        {{ $media->links() }}
    </x-panou.panel>
@endsection
