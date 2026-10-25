@extends('layouts.panou')
@section('title', $template->name)
@section('content')
    @php $latest = $template->versions->first(); @endphp
    <x-panou.page-head :title="$template->name" :crumbs="['Modele consimțământ' => route('panou.medical.consent-templates.index'), $template->name => null]" />
    <div class="grid grid--aside">
        <x-panou.panel title="Textul consimțământului">
            <p class="med-legal">Introduceți textul exact furnizat și validat de clinică. Aplicația nu generează și nu propune texte juridice sau medicale.</p>
            <form method="post" action="{{ route('panou.medical.consent-templates.publish', $template) }}" class="stack">
                @csrf
                <x-panou.field name="name" label="Denumire" :value="$template->name" required />
                <x-panou.field name="scope" label="Procedura / situația" :value="$template->scope" />
                <x-panou.field name="body" type="textarea" label="Text" :value="$latest?->body" required rows="16" />
                <button class="btn btn--primary" type="submit">Publică versiunea</button>
            </form>
        </x-panou.panel>
        <x-panou.panel title="Versiuni">
            @forelse ($template->versions as $v)
                <p class="small"><strong>v{{ $v->version }}</strong> · {{ $v->published_at->format('d.m.Y H:i') }}</p>
            @empty
                <p><x-panou.badge tone="warn">de completat de clinică</x-panou.badge></p>
            @endforelse
            <form method="post" action="{{ route('panou.medical.consent-templates.toggle', $template) }}">@csrf<button class="btn btn--small" type="submit">{{ $template->is_active ? 'Dezactivează' : 'Activează' }}</button></form>
        </x-panou.panel>
    </div>
@endsection
