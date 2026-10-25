@extends('layouts.panou')
@section('title', 'Listă de așteptare')
@section('content')
    @php use App\Modules\Scheduling\Models\WaitlistEntry; @endphp
    <x-panou.page-head title="Listă de așteptare" sub="Pacienți care așteaptă un interval mai devreme. La fiecare anulare, programarea anulată afișează pacienții compatibili cu intervalul eliberat." />

    <div class="grid grid--aside">
        <x-panou.panel :flush="true">
            <div class="tabs" style="padding:0 1rem;margin:0">
                @foreach (['deschise' => 'Deschise', 'programat' => 'Programate', 'expirat' => 'Expirate', 'anulat' => 'Anulate'] as $k => $label)
                    <a href="{{ route('panou.waitlist.index', ['status' => $k]) }}" @if ($status === $k) aria-current="page" @endif>{{ $label }}</a>
                @endforeach
            </div>
            @if ($entries->isEmpty())
                <x-panou.empty title="Lista este goală">Adăugați un pacient din formularul alăturat.</x-panou.empty>
            @else
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>Pacient</th><th>Serviciu / persoană</th><th>Preferințe</th><th>Prioritate</th><th>Stare</th><th></th></tr></thead>
                        <tbody>
                        @foreach ($entries as $e)
                            <tr>
                                <td><a class="row-link" href="{{ route('panou.patients.show', $e->patient) }}">{{ $e->patient->fullName() }}</a><div class="small muted">{{ $e->patient->phone }}</div></td>
                                <td>{{ $e->service?->name ?? 'orice serviciu' }}<div class="small muted">{{ $e->staffMember?->display_name ?? 'orice persoană' }}{{ $e->location ? ' · '.$e->location->name : '' }}</div></td>
                                <td class="small">{{ $e->preferenceSummary() }}@if ($e->valid_until)<div class="muted">până la {{ $e->valid_until->format('d.m.Y') }}</div>@endif</td>
                                <td>{{ WaitlistEntry::PRIORITIES[$e->priority] }}</td>
                                <td>
                                    <x-panou.badge :tone="$e->status === 'oferit' ? 'info' : ($e->status === 'programat' ? 'ok' : null)">{{ WaitlistEntry::STATUSES[$e->status] }}</x-panou.badge>
                                    @if ($o = $e->offers->first())<div class="small muted">ofertă {{ $o->slot_start->format('d.m H:i') }} · {{ \App\Modules\Scheduling\Models\WaitlistOffer::OUTCOMES[$o->outcome] }}</div>@endif
                                </td>
                                <td class="text-right">
                                    @if (in_array($e->status, ['activ', 'oferit'], true))
                                        <div class="cluster" style="justify-content:flex-end">
                                            @can('scheduling.manage')
                                                <a class="btn btn--small btn--primary" href="{{ route('panou.appointments.create', ['patient_id' => $e->patient_id, 'service_id' => $e->service_id, 'staff_member_id' => $e->staff_member_id, 'location_id' => $e->location_id, 'waitlist_entry_id' => $e->id] + ($o ? ['date' => $o->slot_start->toDateString(), 'time' => $o->slot_start->format('H:i')] : [])) }}">Programează</a>
                                            @endcan
                                            @if ($e->status === 'oferit')
                                                <form method="post" action="{{ route('panou.waitlist.status', $e) }}">@csrf<input type="hidden" name="status" value="activ"><input type="hidden" name="offer_outcome" value="refuzat"><button class="btn btn--small" type="submit">A refuzat</button></form>
                                            @endif
                                            <form method="post" action="{{ route('panou.waitlist.status', $e) }}" data-confirm="Scoateți pacientul din lista de așteptare?">@csrf<input type="hidden" name="status" value="anulat"><button class="btn btn--small btn--ghost" type="submit">Scoate</button></form>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                {{ $entries->links() }}
            @endif
        </x-panou.panel>

        <x-panou.panel title="Adaugă în listă">
            <form method="post" action="{{ route('panou.waitlist.store') }}" class="stack" data-module="appointment-form" data-search-url="{{ route('panou.patients.search') }}" data-suggest-url="">
                @csrf
                <div class="patient-picker" data-picker>
                    <input type="hidden" name="patient_id" value="{{ old('patient_id', $patient?->id) }}" data-picker-value>
                    <div class="picked" data-picker-picked @if (! $patient) hidden @endif>
                        <strong data-picker-label>{{ $patient?->fullName() }}</strong><span class="muted small" data-picker-meta></span>
                        <button type="button" class="btn btn--small" data-picker-clear style="margin-left:auto">Schimbă</button>
                    </div>
                    <div data-picker-search @if ($patient) hidden @endif>
                        <label class="field__label" for="patient_q">Pacient</label>
                        <input id="patient_q" type="search" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="patient_results">
                        <ul id="patient_results" class="patient-picker__results" role="listbox" hidden></ul>
                    </div>
                    @error('patient_id')<span class="field__error">{{ $message }}</span>@enderror
                </div>
                <x-panou.field name="service_id" type="select" label="Serviciu" :options="$services" placeholder="Orice" />
                <x-panou.field name="staff_member_id" type="select" label="Persoană preferată" :options="$staff" placeholder="Oricine" />
                <x-panou.field name="location_id" type="select" label="Locație" :options="$locations" placeholder="Oricare" />
                <fieldset>
                    <legend class="field__label">Zile potrivite</legend>
                    <div class="cluster" style="gap:.3rem .8rem">
                        @foreach (\App\Modules\Scheduling\Models\StaffSchedule::WEEKDAYS as $d => $label)
                            <label class="check small"><input type="checkbox" name="weekdays[]" value="{{ $d }}"><span>{{ $label }}</span></label>
                        @endforeach
                    </div>
                </fieldset>
                <div class="form-grid">
                    <x-panou.field name="from" type="time" label="De la ora" />
                    <x-panou.field name="to" type="time" label="Până la ora" />
                </div>
                <x-panou.field name="priority" type="select" label="Prioritate" :options="WaitlistEntry::PRIORITIES" value="2" :placeholder="false" />
                <x-panou.field name="valid_until" type="date" label="Valabil până la" />
                <x-panou.field name="notes" type="textarea" label="Observații" hint="Fără date medicale." />
                <button class="btn btn--primary" type="submit">Adaugă</button>
            </form>
        </x-panou.panel>
    </div>
@endsection
