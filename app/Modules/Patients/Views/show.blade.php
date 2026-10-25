@extends('layouts.panou')
@section('title', $patient->fullName())
@section('content')
    <x-panou.page-head :title="$patient->fullName()" :crumbs="['Pacienți' => route('panou.patients.index'), $patient->fullName() => null]">
        <x-slot:actions>
            @can('scheduling.manage')
                <a class="btn btn--primary" href="{{ route('panou.appointments.create', ['patient_id' => $patient->id]) }}"><x-panou.icon name="calendar" />Programare nouă</a>
            @endcan
            @can('patients.edit')
                <a class="btn" href="{{ route('panou.patients.edit', $patient) }}">Editează datele</a>
            @endcan
            @can('patients.merge')
                <a class="btn btn--ghost" href="{{ route('panou.patients.merge.create', $patient) }}">Unește cu altă fișă</a>
            @endcan
        </x-slot:actions>
    </x-panou.page-head>

    <div class="cluster small" style="margin:-.75rem 0 1.25rem">
        <span class="muted">Fișa #{{ $patient->id }}</span>
        @if ($patient->birth_date)<span>· {{ $patient->birth_date->format('d.m.Y') }} ({{ $patient->age() }} ani)</span>@endif
        @if ($patient->phone)<span>· {{ $patient->phone }}</span>@endif
        @if ($patient->isMinor())<x-panou.badge tone="info">minor</x-panou.badge>@endif
        @if ($careReason === 'urgenta')
            <x-panou.badge tone="danger">acces de urgență până la {{ $breakGlass->expires_at->format('H:i') }}</x-panou.badge>
        @elseif ($careReason)
            <x-panou.badge tone="ok">relație de îngrijire</x-panou.badge>
        @else
            <x-panou.badge>fără relație de îngrijire — date medicale ascunse</x-panou.badge>
            @can('patients.break_glass')
                <a class="small" href="{{ route('panou.patients.break-glass.create', $patient) }}">Acces de urgență</a>
            @endcan
        @endif
    </div>

    <nav class="tabs" aria-label="Secțiunile fișei">
        @foreach ($tabs as $tab)
            @php $badge = $tab->badge ? ($tab->badge)($patient) : null; @endphp
            <a href="{{ route('panou.patients.show', [$patient, 'tab' => $tab->key]) }}" @if ($tab->key === $current->key) aria-current="page" @endif>
                {{ $tab->label }}@if ($badge !== null) <span class="badge badge--accent">{{ $badge }}</span>@endif
            </a>
        @endforeach
    </nav>

    {!! $content !!}
@endsection
