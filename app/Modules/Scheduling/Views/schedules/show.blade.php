@extends('layouts.panou')
@section('title', 'Program: '.$member->display_name)
@section('content')
    @php use App\Modules\Scheduling\Models\StaffSchedule; use App\Modules\Scheduling\Models\ScheduleException; @endphp
    <x-panou.page-head :title="$member->display_name" :crumbs="['Program de lucru' => route('panou.schedules.index'), $member->display_name => null]" sub="Programul săptămânal se aplică pe perioada de valabilitate. Ștergerea unui rând nu modifică programările deja făcute." />
    <div class="grid grid--2">
        <x-panou.panel title="Program săptămânal" :flush="true">
            @if ($templates->isEmpty())
                <x-panou.empty title="Fără program configurat">Până la configurare, calendarul afișează programul locației și programările primesc doar o avertizare.</x-panou.empty>
            @else
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>Zi</th><th>Interval</th><th>Pauză</th><th>Locație</th><th>Valabil</th><th></th></tr></thead>
                        <tbody>
                        @foreach ($templates as $t)
                            <tr>
                                <td>{{ StaffSchedule::WEEKDAYS[$t->weekday] }}</td>
                                <td class="num">{{ substr($t->start_time, 0, 5) }}–{{ substr($t->end_time, 0, 5) }}</td>
                                <td class="num">{{ collect($t->breaks)->map(fn ($b) => $b[0].'–'.$b[1])->join(', ') ?: '—' }}</td>
                                <td>{{ $t->location?->name }}</td>
                                <td class="num small">{{ $t->valid_from->format('d.m.Y') }} – {{ $t->valid_until?->format('d.m.Y') ?? '…' }}</td>
                                <td class="text-right"><form method="post" action="{{ route('panou.schedules.templates.destroy', $t) }}" data-confirm="Ștergeți acest interval din program?">@csrf @method('delete')<button class="btn btn--small" type="submit">Șterge</button></form></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            <x-slot:footer>
                <form method="post" action="{{ route('panou.schedules.templates.store', $member) }}" class="stack">
                    @csrf
                    <fieldset>
                        <legend class="field__label">Zile<span class="req">*</span></legend>
                        <div class="cluster" style="gap:.3rem .8rem">
                            @foreach (StaffSchedule::WEEKDAYS as $d => $label)
                                <label class="check small"><input type="checkbox" name="weekdays[]" value="{{ $d }}" @checked(in_array($d, old('weekdays', [])))><span>{{ $label }}</span></label>
                            @endforeach
                        </div>
                        @error('weekdays')<span class="field__error">{{ $message }}</span>@enderror
                    </fieldset>
                    <div class="form-grid form-grid--3">
                        <x-panou.field name="location_id" type="select" label="Locație" :options="$locations" required :placeholder="false" />
                        <x-panou.field name="start_time" type="time" label="De la" required />
                        <x-panou.field name="end_time" type="time" label="Până la" required />
                        <x-panou.field name="break_from" type="time" label="Pauză de la" />
                        <x-panou.field name="break_to" type="time" label="Pauză până la" />
                        <x-panou.field name="valid_from" type="date" label="Valabil de la" :value="now()->toDateString()" required />
                        <x-panou.field name="valid_until" type="date" label="Valabil până la" />
                    </div>
                    <div><button class="btn btn--primary" type="submit">Adaugă în program</button></div>
                </form>
            </x-slot:footer>
        </x-panou.panel>

        <x-panou.panel title="Concedii, zile libere, ore suplimentare">
            @forelse ($exceptions as $e)
                <div class="cluster" style="justify-content:space-between;margin-bottom:.5rem">
                    <span><x-panou.badge :tone="$e->kind === 'ore_suplimentare' ? 'ok' : 'warn'">{{ ScheduleException::KINDS[$e->kind] }}</x-panou.badge>
                        <strong class="num">{{ $e->starts_at->format('d.m.Y H:i') }} – {{ $e->ends_at->format('d.m.Y H:i') }}</strong>
                        @if ($e->note)<span class="small muted"> · {{ $e->note }}</span>@endif</span>
                    <form method="post" action="{{ route('panou.schedules.exceptions.destroy', $e) }}">@csrf @method('delete')<button class="btn btn--small" type="submit">Șterge</button></form>
                </div>
            @empty
                <p class="muted">Nicio excepție.</p>
            @endforelse
            <hr class="sep">
            <form method="post" action="{{ route('panou.schedules.exceptions.store') }}" class="stack">
                @csrf <input type="hidden" name="staff_member_id" value="{{ $member->id }}">
                <x-panou.field name="kind" type="select" label="Tip" :options="collect(ScheduleException::KINDS)->except('inchidere')->all()" required :placeholder="false" />
                <x-panou.field name="location_id" type="select" label="Locație (pentru ore suplimentare)" :options="$locations" placeholder="—" />
                <div class="form-grid">
                    <x-panou.field name="starts_at" type="datetime-local" label="De la" required />
                    <x-panou.field name="ends_at" type="datetime-local" label="Până la" required />
                </div>
                <x-panou.field name="note" label="Notă" />
                <button class="btn" type="submit">Adaugă</button>
            </form>
        </x-panou.panel>
    </div>
@endsection
