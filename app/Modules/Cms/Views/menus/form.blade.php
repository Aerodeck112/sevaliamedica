@extends('layouts.panou')
@section('title', $item->exists ? 'Meniu: '.$item->label : 'Element de meniu nou')
@push('head') @vite('resources/css/cms.css') @endpush
@section('content')
    <x-panou.page-head :title="$item->exists ? $item->label : 'Element de meniu nou'" :crumbs="['Meniuri' => route('panou.cms.menus.index'), ($item->exists ? 'Editare' : 'Adăugare') => null]" />
    <div class="cms-editor" data-module="cms-editor" data-picker="{{ route('panou.cms.media.picker') }}" style="grid-template-columns:minmax(0,760px)">
        <form method="post" action="{{ $item->exists ? route('panou.cms.menus.update', $item) : route('panou.cms.menus.store') }}" id="cms-form" class="stack">
            @csrf
            @if ($item->exists) @method('put') @endif
            <input type="hidden" name="slots_json" value="">
            <x-panou.panel>
                <div class="form-grid">
                    <x-panou.field name="label" label="Etichetă" :value="$item->label" required />
                    <x-panou.field name="kind" type="select" label="Tip" :value="$item->kind" :options="\App\Modules\Cms\Models\MenuItem::KINDS" :placeholder="false" />
                    <x-panou.field name="url" label="Adresă (pentru link simplu sau subelement)" :value="$item->url" hint="Ex. /contactus sau https://…" />
                    <x-panou.field name="parent_id" type="select" label="Subelement al" :value="$item->parent_id" :options="$parents" placeholder="— element principal —" />
                    <x-panou.field name="new_tab" type="checkbox" label="Se deschide în filă nouă" :value="$item->new_tab" />
                    <x-panou.field name="is_visible" type="checkbox" label="Vizibil pe site" :value="$item->is_visible" />
                </div>
            </x-panou.panel>
            @if ($slots)
                <x-panou.panel title="Conținutul meniului extins">
                    <p class="small muted">Textele și linkurile din meniul extins, cu aspectul original. Pentru o pagină inexistentă, schimbați linkul sau creați pagina.</p>
                    <div class="cms-slots">
                        @foreach ($slots as $slot)
                            @include('cms::partials.slot', ['uid' => 'menu', 'slot' => $slot, 'media' => $media])
                        @endforeach
                    </div>
                </x-panou.panel>
            @endif
            <div class="form-actions"><button class="btn btn--primary" type="submit">Salvează</button></div>
        </form>
    </div>
    @if ($item->exists)
        <form method="post" action="{{ route('panou.cms.menus.destroy', $item) }}" data-confirm="Eliminați „{{ $item->label }}” din meniu?" style="margin-top:1rem">
            @csrf @method('delete')
            <button class="btn btn--danger btn--small" type="submit">Elimină din meniu</button>
        </form>
    @endif
    <dialog class="dialog cms-picker" id="cms-media-dialog">
        <div class="dialog__head"><h2>Alege o imagine</h2></div>
        <div class="dialog__body"><input type="search" data-picker-search aria-label="Caută imagini"><div class="cms-picker__grid" data-picker-grid></div></div>
        <div class="dialog__foot"><button type="button" class="btn" data-dialog-close>Închide</button></div>
    </dialog>
@endsection
