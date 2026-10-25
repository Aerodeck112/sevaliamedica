@extends('layouts.panou')
@section('title', 'Serie de ședințe')
@section('content')
    <x-panou.page-head :title="$series->service->name" :crumbs="['Serii de ședințe' => route('panou.series.index'), $series->patient->fullName() => null]"
        :sub="$series->patient->fullName().' · '.($series->staffMember?->display_name ?? 'fără persoană fixă').' · '.$series->location->name">
        <x-slot:actions><a class="btn" href="{{ route('panou.patients.show', $series->patient) }}">Fișa pacientului</a></x-slot:actions>
    </x-panou.page-head>

    <x-panou.panel :flush="true">
        <div class="metrics" style="border-top:0">
            <div class="metric"><span class="metric__value">{{ $counts['planned'] }}</span><span class="metric__label">Planificate</span><span class="metric__help">numărul din regula seriei</span></div>
            <div class="metric"><span class="metric__value">{{ $counts['done'] }}</span><span class="metric__label">Efectuate</span><span class="metric__help">programări finalizate</span></div>
            <div class="metric"><span class="metric__value">{{ $counts['active'] }}</span><span class="metric__label">Programate</span><span class="metric__help">confirmate, încă neefectuate</span></div>
            <div class="metric"><span class="metric__value">{{ $counts['cancelled'] + $counts['no_show'] }}</span><span class="metric__label">Anulate / neprezentări</span><span class="metric__help">{{ $counts['cancelled'] }} anulate, {{ $counts['no_show'] }} neprezentări</span></div>
            <div class="metric"><span class="metric__value">{{ $counts['remaining'] }}</span><span class="metric__label">Rămase</span><span class="metric__help">planificate − efectuate</span></div>
        </div>
    </x-panou.panel>

    <div class="grid grid--aside" style="margin-top:1.25rem">
        <x-panou.panel title="Ședințe" :flush="true">
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>#</th><th>Interval</th><th>Persoana</th><th>Status</th></tr></thead>
                    <tbody>
                    @foreach ($series->appointments as $a)
                        <tr>
                            <td class="num">{{ $a->series_index }}</td>
                            <td class="num"><a class="row-link" href="{{ route('panou.appointments.show', $a) }}">{{ $a->starts_at->locale('ro')->isoFormat('ddd D MMM YYYY, HH:mm') }}</a></td>
                            <td>{{ $a->staffMember?->display_name }}</td>
                            <td>@include('scheduling::partials.status', ['status' => $a->status])</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </x-panou.panel>
        @can('scheduling.manage')
            <x-panou.panel title="Reprogramează ședințele viitoare">
                <form method="post" action="{{ route('panou.series.plan-reschedule', $series) }}" class="stack">
                    @csrf
                    <x-panou.field name="start_date" type="date" label="De la data" :value="now()->toDateString()" required />
                    <x-panou.field name="time" type="time" label="Ora nouă" :value="$series->rule['time'] ?? '09:00'" required />
                    <fieldset>
                        <legend class="field__label">Zilele noi</legend>
                        <div class="cluster" style="gap:.3rem .8rem">
                            @foreach (\App\Modules\Scheduling\Models\StaffSchedule::WEEKDAYS as $d => $label)
                                <label class="check small"><input type="checkbox" name="weekdays[]" value="{{ $d }}" @checked(in_array($d, $series->rule['weekdays'] ?? []))><span>{{ $label }}</span></label>
                            @endforeach
                        </div>
                    </fieldset>
                    <x-panou.field name="staff_member_id" type="select" label="Persoana" :value="$series->staff_member_id" :options="$staff" placeholder="— aceeași —" />
                    <x-panou.field name="reason" label="Motiv" required />
                    <button class="btn" type="submit">Verifică noile intervale</button>
                </form>
                <p class="small muted" style="margin-top:.75rem">O singură ședință se mută din pagina ei sau din calendar.</p>
            </x-panou.panel>
        @endcan
    </div>
@endsection
