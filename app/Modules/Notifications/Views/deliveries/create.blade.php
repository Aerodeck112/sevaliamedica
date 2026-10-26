@extends('layouts.panou')
@section('title', 'Mesaj administrativ')
@section('content')
    <x-panou.page-head title="Mesaj administrativ (follow-up)"
        :crumbs="['Programare' => route('panou.appointments.show', $appointment), 'Mesaj nou' => null]"
        sub="Se trimite textul șablonului „Follow-up administrativ”. Nu se poate adăuga text liber, ca mesajul să nu conțină date medicale." />
    <x-panou.panel style="max-width:640px">
        <dl class="facts" style="margin-bottom:1rem">
            <dt>Pacient</dt><dd>{{ $appointment->patient->fullName() }}</dd>
            <dt>Programare</dt><dd>{{ $appointment->starts_at?->format('d.m.Y H:i') }} · {{ $appointment->location?->name }}</dd>
        </dl>
        @if ($channels === [])
            <x-panou.empty title="Niciun canal disponibil">Pacientul nu a permis niciun canal de mesaje sau canalele nu sunt configurate.</x-panou.empty>
        @else
            <form method="post" action="{{ route('panou.notifications.deliveries.store') }}">
                @csrf
                <input type="hidden" name="appointment_id" value="{{ $appointment->id }}">
                <input type="hidden" name="nonce" value="{{ $nonce }}">
                <x-panou.field name="channel" type="select" label="Canal" :options="$channels" :placeholder="false" required />
                <div class="form-actions"><button class="btn btn--primary" type="submit"><x-panou.icon name="send" />Pune în coadă</button></div>
            </form>
        @endif
    </x-panou.panel>
@endsection
