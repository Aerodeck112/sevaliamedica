@extends('layouts.panou')
@section('title', 'Consimțământ')
@section('content')
    @php $v = $consent->templateVersion; @endphp
    <x-panou.page-head :title="$v->template->name" :sub="'Versiunea '.$v->version.' · '.$consent->patient->fullName()"
        :crumbs="[$consent->patient->fullName() => route('panou.patients.show', [$consent->patient, 'tab' => 'consimtaminte']), 'Consimțământ' => null]" />
    <div class="grid grid--aside">
        <x-panou.panel title="Textul acceptat">
            <div style="white-space:pre-wrap;max-width:75ch">{{ $v->body }}</div>
        </x-panou.panel>
        <x-panou.panel title="Consemnare">
            <dl class="facts">
                <dt>Data</dt><dd class="num">{{ $consent->given_at->format('d.m.Y H:i') }}</dd>
                <dt>Mod</dt><dd>{{ \App\Modules\Medical\Models\PatientConsent::METHODS[$consent->method] ?? $consent->method }}</dd>
                <dt>Exprimat de</dt><dd>{{ \App\Modules\Medical\Models\PatientConsent::GIVEN_BY[$consent->given_by] ?? $consent->given_by }}@if ($consent->representative_name) — {{ $consent->representative_name }}@endif</dd>
                <dt>Consemnat de</dt><dd>{{ $consent->collector?->name }}</dd>
                @if ($consent->document)<dt>Scanare</dt><dd><a href="{{ route('panou.medical.documents.show', $consent->document) }}" target="_blank" rel="noopener">{{ $consent->document->title }}</a></dd>@endif
                @if ($consent->notes)<dt>Observații</dt><dd>{{ $consent->notes }}</dd>@endif
                <dt>Stare</dt><dd>@if ($consent->isActive())<x-panou.badge tone="ok">valabil</x-panou.badge>@else<x-panou.badge>retras {{ $consent->withdrawn_at->format('d.m.Y H:i') }}</x-panou.badge><div class="small">{{ $consent->withdraw_reason }}</div>@endif</dd>
            </dl>
        </x-panou.panel>
    </div>
@endsection
