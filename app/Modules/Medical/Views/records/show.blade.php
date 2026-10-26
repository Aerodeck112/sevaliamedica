@extends('layouts.panou')
@section('title', $record->title)
@section('content')
    <x-panou.page-head :title="$record->title" :sub="$record->kindLabel().' · '.$record->patient->fullName()"
        :crumbs="['Pacienți' => route('panou.patients.index'), $record->patient->fullName() => route('panou.patients.show', [$record->patient, 'tab' => 'fise']), $record->title => null]">
        <x-slot:actions>
            @if ($canEdit)
                <a class="btn btn--primary" href="{{ route('panou.medical.records.edit', $record) }}">Continuă draftul</a>
            @endif
            @if ($canAmend)
                <a class="btn" href="{{ route('panou.medical.records.amend-form', $root) }}">Completare / rectificare</a>
            @endif
            <button class="btn btn--ghost" type="button" data-module="print">Tipărește</button>
        </x-slot:actions>
    </x-panou.page-head>

    @if ($emergency)
        <div class="alert alert--danger"><x-panou.icon name="shield" /><div>Consultați fișa prin <strong>acces de urgență</strong>. Accesul este înregistrat și revizuit.</div></div>
    @endif
    @unless ($record->integrityOk())
        <div class="alert alert--danger" role="alert"><x-panou.icon name="warn" /><div><strong>Suma de control a fișei nu corespunde.</strong> Conținutul ar fi putut fi modificat în afara aplicației. Anunțați administratorul tehnic.</div></div>
    @endunless
    @if ($record->id === $root->id && $current->id !== $root->id)
        <div class="alert alert--warn"><x-panou.icon name="warn" /><div>Această fișă a fost <strong>rectificată</strong> pe {{ $current->finalized_at->format('d.m.Y H:i') }}. <a href="{{ route('panou.medical.records.show', $current) }}">Vedeți versiunea valabilă</a>. Originalul de mai jos rămâne neschimbat.</div></div>
    @endif

    <div class="grid grid--aside">
        <x-panou.panel>
            @if ($record->isAmendment())
                <div class="alert alert--info"><x-panou.icon name="file" /><div><strong>{{ $record->kindLabel() }}</strong> la „<a href="{{ route('panou.medical.records.show', $root) }}">{{ $root->title }}</a>”. Motiv: {{ $record->amendment_reason }}</div></div>
            @endif
            @include('medical::partials.fields-display', ['schema' => $record->schema(), 'values' => $record->content ?? []])
            @if ($record->documents->isNotEmpty())
                <hr class="sep">
                <h3>Documente atașate</h3>
                <ul>
                    @foreach ($record->documents as $doc)
                        <li><a href="{{ route('panou.medical.documents.show', $doc) }}" target="_blank" rel="noopener">{{ $doc->title }}</a> <span class="small muted">({{ $doc->humanSize() }})</span></li>
                    @endforeach
                </ul>
            @endif
        </x-panou.panel>

        <div class="stack">
            <x-panou.panel title="Detalii">
                <dl class="facts">
                    <dt>Status</dt><dd><x-panou.badge :tone="$record->statusTone()">{{ $record->statusLabel() }}</x-panou.badge></dd>
                    <dt>Autor</dt><dd>{{ $record->staffMember?->display_name }}</dd>
                    <dt>Creată</dt><dd class="num">{{ $record->created_at->format('d.m.Y H:i') }}</dd>
                    @if ($record->isFinal())
                        <dt>Finalizată</dt><dd class="num">{{ $record->finalized_at->format('d.m.Y H:i') }}<br><span class="small muted">de {{ $record->finalizer?->name }}</span></dd>
                    @endif
                    @if ($record->templateVersion)
                        <dt>Șablon</dt><dd>{{ $record->templateVersion->template?->name }} v{{ $record->templateVersion->version }}</dd>
                    @endif
                    @if ($record->appointment)
                        <dt>Programare</dt><dd><a href="{{ route('panou.appointments.show', $record->appointment) }}">{{ $record->appointment->starts_at?->format('d.m.Y H:i') }}</a></dd>
                    @endif
                </dl>
                @if ($record->isFinal())
                    <p class="med-legal" style="margin-top:1rem">Finalizată în aplicație cu reconfirmarea identității autorului. Nu este o semnătură electronică calificată.</p>
                @endif
            </x-panou.panel>

            <x-panou.panel title="Istoric">
                <ol class="med-chain">
                    @foreach ($chain as $item)
                        <li class="{{ $item->id === $current->id ? 'is-current' : '' }}">
                            <a href="{{ route('panou.medical.records.show', $item) }}">{{ $item->kindLabel() }}</a>
                            @if ($item->id === $record->id)<x-panou.badge tone="accent">afișată</x-panou.badge>@endif
                            <div class="small muted">
                                {{ $item->staffMember?->display_name }} ·
                                @if ($item->isFinal()) finalizată {{ $item->finalized_at->format('d.m.Y H:i') }} @else {{ strtolower($item->statusLabel()) }} @endif
                            </div>
                            @if ($item->isAmendment())<div class="small">Motiv: {{ $item->amendment_reason }}</div>@endif
                        </li>
                    @endforeach
                </ol>
            </x-panou.panel>
        </div>
    </div>
@endsection
