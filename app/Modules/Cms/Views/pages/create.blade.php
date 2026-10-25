@extends('layouts.panou')
@php $isArticle = $kind === \App\Modules\Cms\Models\Page::KIND_ARTICLE; @endphp
@section('title', $isArticle ? 'Articol nou' : 'Pagină nouă')
@section('content')
    <x-panou.page-head :title="$isArticle ? 'Articol nou' : 'Pagină nouă'"
        :crumbs="[($isArticle ? 'Articole blog' : 'Pagini') => route($isArticle ? 'panou.cms.articles.index' : 'panou.cms.pages.index'), 'Adăugare' => null]"
        sub="Pagina se creează ca ciornă, nevizibilă public. Apoi adăugați secțiuni din șabloanele siteului și publicați." />
    <x-panou.panel style="max-width:720px">
        <form method="post" action="{{ route('panou.cms.pages.store') }}" class="stack">
            @csrf
            <input type="hidden" name="kind" value="{{ $kind }}">
            <x-panou.field name="title" label="Titlu" required />
            <x-panou.field name="path" label="Adresa (URL)" :value="$suggestedPath" :placeholder="$isArticle ? '/blog/blog-sevalia-1/titlu-articol-15' : '/titlu-pagina'"
                hint="Opțional; se generează din titlu. Doar litere mici fără diacritice, cifre și „-”." />
            <div class="form-actions"><button class="btn btn--primary" type="submit">Creează ciorna</button></div>
        </form>
    </x-panou.panel>
@endsection
