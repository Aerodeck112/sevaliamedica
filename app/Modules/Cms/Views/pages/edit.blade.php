@extends('layouts.panou')
@php
    use App\Modules\Cms\Models\Page;
    $isArticle = $page->kind === Page::KIND_ARTICLE;
    $isGlobal = $page->kind === Page::KIND_GLOBAL;
    $listRoute = $isArticle ? 'panou.cms.articles.index' : 'panou.cms.pages.index';
    $typeLabels = ['html' => 'Secțiune', 'team' => 'Echipa (din fișele de personal)', 'blog_latest' => 'Ultimele articole (automat)',
        'blog_index' => 'Lista articolelor (automat)', 'form' => 'Formular', 'rich' => 'Text liber'];
@endphp
@section('title', 'Editare: '.$version->title)
@section('wide', '1')
@push('head') @vite('resources/css/cms.css') @endpush
@section('content')
    <x-panou.page-head :title="$version->title" :crumbs="[($isArticle ? 'Articole blog' : 'Pagini') => route($listRoute), 'Editare' => null]">
        <x-slot:actions>
            @unless ($isGlobal)<a class="btn" href="{{ $page->path }}" target="_blank" rel="noopener"><x-panou.icon name="globe" />Pagina publică</a>@endunless
            <a class="btn" href="{{ route('panou.cms.pages.versions', $page) }}"><x-panou.icon name="clock" />Istoric versiuni</a>
        </x-slot:actions>
    </x-panou.page-head>

    <div class="cluster" style="margin-bottom:1rem">
        <code>{{ $isGlobal ? 'zonă globală — apare pe toate paginile' : $page->path }}</code>
        @if ($page->isPublished())<x-panou.badge tone="ok">publicată (v{{ $page->publishedVersion?->number }})</x-panou.badge>@else<x-panou.badge>nepublicată</x-panou.badge>@endif
        @if ($page->hasUnpublishedChanges())<x-panou.badge tone="warn">ciorna v{{ $version->number }} are modificări nepublicate</x-panou.badge>@endif
    </div>

    @if ($otherEditor)
        <div class="alert alert--warn" role="alert" id="cms-lease-warning">
            <x-panou.icon name="warn" />
            <div><strong>{{ $otherEditor->name }} editează această pagină acum.</strong> Dacă salvați amândoi, a doua salvare va fi refuzată ca să nu se suprascrie modificările. Coordonați-vă sau așteptați.</div>
        </div>
    @else
        <div class="alert alert--warn" role="alert" id="cms-lease-warning" hidden><x-panou.icon name="warn" /><div></div></div>
    @endif
    @if ($broken)
        <div class="alert alert--warn" role="status"><x-panou.icon name="link" />
            <div>Linkuri către pagini inexistente în această pagină: @foreach ($broken as $b)<code>{{ $b }}</code>@if (! $loop->last), @endif @endforeach. Creați pagina sau schimbați linkul.</div>
        </div>
    @endif

    <div class="cms-editor" data-module="cms-editor"
         data-heartbeat="{{ route('panou.cms.pages.heartbeat', $page) }}" data-release="{{ route('panou.cms.pages.release', $page) }}"
         data-picker="{{ route('panou.cms.media.picker') }}">
        <form method="post" action="{{ route('panou.cms.pages.update', $page) }}" class="cms-editor__form" id="cms-form">
            @csrf @method('put')
            <input type="hidden" name="lock_version" value="{{ old('lock_version', $page->lock_version) }}">
            <input type="hidden" name="slots_json" value="">

            <div class="cms-toolbar">
                <button class="btn btn--primary" type="submit" name="action" value="save">Salvează ciorna</button>
                @if ($canPublish)<button class="btn" type="submit" name="action" value="publish" data-confirm-publish>Salvează și publică</button>@endif
                <span class="small muted" data-dirty-indicator hidden>Modificări nesalvate</span>
            </div>

            @unless ($isGlobal)
                <details class="panel cms-details" @if ($errors->has('seo.*') || $errors->has('title')) open @endif>
                    <summary class="panel__head"><h2>Titlu, SEO{{ $isArticle ? ' și detalii articol' : '' }}</h2></summary>
                    <div class="panel__body stack">
                        <x-panou.field name="title" label="Titlu (intern / titlul articolului)" :value="$version->title" required />
                        @if ($canSeo)
                            <x-panou.field name="seo[title]" label="Titlu în Google (title)" :value="$version->seo['title'] ?? ''" hint="Recomandat sub 60 de caractere." data-count="60" />
                            <x-panou.field name="seo[description]" type="textarea" label="Descriere în Google (meta description)" :value="$version->seo['description'] ?? ''"
                                :hint="empty($version->seo['description']) ? 'Lipsește — paginile fără descriere apar cu un fragment ales de Google. Completați un text scurt, verificat.' : 'Recomandat 120–160 de caractere.'" data-count="160" rows="3" />
                            <x-panou.field name="seo[canonical]" type="url" label="URL canonic (opțional)" :value="$version->seo['canonical'] ?? ''" hint="Lăsați gol pentru adresa paginii." />
                            <x-panou.field name="seo[og_image_media_id]" type="number" label="Imagine pentru distribuire (ID media, opțional)" :value="$version->seo['og_image_media_id'] ?? ''" />
                            <label class="check"><input type="hidden" name="seo[noindex]" value="0"><input type="checkbox" name="seo[noindex]" value="1" @checked($version->seo['noindex'] ?? false)> <span>Nu indexa în motoarele de căutare</span></label>
                        @else
                            <p class="small muted">Datele SEO pot fi modificate de utilizatorii cu permisiunea „SEO”.</p>
                        @endif
                        @if ($isArticle)
                            <hr class="sep">
                            <x-panou.field name="article[subtitle]" label="Subtitlu" :value="$version->settings['subtitle'] ?? ''" />
                            <div class="form-grid">
                                <x-panou.field name="article[published_at]" type="date" label="Data afișată" :value="$page->published_at?->format('Y-m-d')" />
                                <x-panou.field name="article[author_name]" label="Autor afișat" :value="$page->author_name" />
                            </div>
                            <x-panou.field name="article[cover_media_id]" type="number" label="Imagine de copertă (ID media)" :value="$page->cover_media_id" />
                        @endif
                    </div>
                </details>
            @endunless

            <ol class="cms-blocks">
                @foreach ($blocks as $i => $block)
                    @php $uid = $block['uid']; @endphp
                    <li class="panel cms-block @if ($block['hidden']) cms-block--hidden @endif" id="bloc-{{ $uid }}">
                        <details @if ($loop->first || old('op') && str_contains(old('op'), $uid)) open @endif>
                            <summary class="panel__head">
                                <span class="cms-block__num">{{ $i + 1 }}</span>
                                <span class="cms-block__title">{{ $block['label'] ?: ($typeLabels[$block['type']] ?? $block['type']) }}</span>
                                <span class="small muted">{{ $typeLabels[$block['type']] ?? $block['type'] }}</span>
                                @if ($block['hidden'])<x-panou.badge>ascunsă</x-panou.badge>@endif
                            </summary>
                            <div class="panel__body">
                                <div class="cms-block__ops" role="group" aria-label="Operații pentru secțiune">
                                    <button class="btn btn--small" type="submit" name="op" value="up:{{ $uid }}" @disabled($loop->first) aria-label="Mută secțiunea mai sus">↑ Sus</button>
                                    <button class="btn btn--small" type="submit" name="op" value="down:{{ $uid }}" @disabled($loop->last) aria-label="Mută secțiunea mai jos">↓ Jos</button>
                                    <button class="btn btn--small" type="submit" name="op" value="{{ $block['hidden'] ? 'show' : 'hide' }}:{{ $uid }}">{{ $block['hidden'] ? 'Afișează' : 'Ascunde' }}</button>
                                    <button class="btn btn--small" type="submit" name="op" value="duplicate:{{ $uid }}">Duplică</button>
                                    <button class="btn btn--small btn--danger" type="submit" name="op" value="delete:{{ $uid }}" data-confirm-op="Ștergeți această secțiune? O puteți recupera din istoricul versiunilor.">Șterge</button>
                                </div>

                                @if ($block['type'] === 'team')
                                    <p class="alert alert--info small">Numele, titulatura, fotografia și vizibilitatea medicilor se modifică din <a href="{{ route('panou.clinic.staff.index') }}">Administrare › Personal</a> (câmpurile „Afișat pe site”, „Nume afișat”, „Titulatură”).</p>
                                @elseif ($block['type'] === 'blog_latest')
                                    <p class="alert alert--info small">Afișează automat ultimele {{ $block['config']['count'] ?? 3 }} articole publicate.</p>
                                @elseif ($block['type'] === 'blog_index')
                                    <p class="alert alert--info small">Lista articolelor se generează automat din <a href="{{ route('panou.cms.articles.index') }}">Articole blog</a>.</p>
                                @elseif ($block['type'] === 'form')
                                    <p class="alert alert--info small">Câmpurile formularului și listele de specialități/medici vin din datele clinicii. Textele de mai jos sunt editabile. Câmpul „Data nașterii” se activează din <a href="{{ route('panou.cms.settings.edit') }}">Setări site</a>.</p>
                                @endif

                                @if ($block['type'] === 'rich')
                                    <label class="field__label" for="rich_{{ $uid }}">Conținut</label>
                                    <textarea id="rich_{{ $uid }}" name="rich[{{ $uid }}]" rows="12" data-rich="full">{{ old("rich.$uid", $block['html']) }}</textarea>
                                    <p class="field__hint">Titluri, paragrafe, liste, linkuri, imagini din bibliotecă. Codul și scripturile sunt eliminate automat.</p>
                                @endif

                                @if ($block['slots'])
                                    <div class="cms-slots">
                                        @foreach ($block['slots'] as $slot)
                                            @include('cms::partials.slot', ['uid' => $uid, 'slot' => $slot, 'media' => $media])
                                        @endforeach
                                    </div>
                                @elseif (! in_array($block['type'], ['rich', 'blog_index', 'blog_latest'], true))
                                    <p class="small muted">Secțiunea nu are texte editabile.</p>
                                @endif

                                @if ($block['repeats'])
                                    <div class="cms-repeats">
                                        <p class="field__label">Elemente repetabile</p>
                                        <ul>
                                            @foreach ($block['repeats'] as $rid => $label)
                                                <li>
                                                    <span class="small">{{ $label }}</span>
                                                    <button class="btn btn--small" type="submit" name="op" value="dup-item:{{ $uid }}:{{ $rid }}">Duplică</button>
                                                    <button class="btn btn--small btn--danger" type="submit" name="op" value="rm-item:{{ $uid }}:{{ $rid }}" @disabled(count($block['repeats']) < 2)>Elimină</button>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif
                            </div>
                        </details>
                        <div class="cms-add">
                            <label class="small" for="add_{{ $uid }}">Adaugă după această secțiune:</label>
                            <select id="add_{{ $uid }}" data-add-select="{{ $uid }}">
                                <option value="">— alegeți un șablon —</option>
                                @if ($isArticle)<option value="rich">Text liber (paragrafe, titluri, liste)</option>@endif
                                @foreach ($templates as $t)<option value="{{ $t->id }}">{{ $t->name }} ({{ $t->snippet }})</option>@endforeach
                            </select>
                            <button class="btn btn--small" type="submit" name="op" value="" data-add-button="{{ $uid }}">Adaugă</button>
                        </div>
                    </li>
                @endforeach
            </ol>
            @if (! $blocks)
                <x-panou.panel>
                    <div class="cms-add">
                        <label class="small" for="add_start">Prima secțiune:</label>
                        <select id="add_start" data-add-select="start">
                            <option value="">— alegeți un șablon —</option>
                            @if ($isArticle)<option value="rich">Text liber</option>@endif
                            @foreach ($templates as $t)<option value="{{ $t->id }}">{{ $t->name }} ({{ $t->snippet }})</option>@endforeach
                        </select>
                        <button class="btn btn--small" type="submit" name="op" value="" data-add-button="start">Adaugă</button>
                    </div>
                </x-panou.panel>
            @endif
        </form>

        <aside class="cms-preview" aria-label="Previzualizare">
            <div class="cms-preview__bar" role="group" aria-label="Lățimea previzualizării">
                <button type="button" class="btn btn--small" data-preview-width="1440" aria-pressed="true">Desktop</button>
                <button type="button" class="btn btn--small" data-preview-width="390" aria-pressed="false">Mobil</button>
                <a class="btn btn--small btn--ghost" href="{{ route('panou.cms.pages.preview', $page) }}" target="_blank" rel="noopener">Deschide separat</a>
            </div>
            <div class="cms-preview__frame" data-preview-frame>
                <iframe title="Previzualizarea ciornei" src="{{ route('panou.cms.pages.preview', $page) }}" loading="lazy"></iframe>
            </div>
            <p class="small muted">Previzualizarea arată ultima ciornă salvată.</p>
        </aside>
    </div>

    <dialog class="dialog cms-picker" id="cms-media-dialog">
        <div class="dialog__head"><h2>Alege o imagine</h2></div>
        <div class="dialog__body">
            <input type="search" placeholder="Caută după nume sau text alternativ" data-picker-search aria-label="Caută imagini">
            <div class="cms-picker__grid" data-picker-grid></div>
        </div>
        <div class="dialog__foot"><button type="button" class="btn" data-dialog-close>Închide</button></div>
    </dialog>
@endsection
