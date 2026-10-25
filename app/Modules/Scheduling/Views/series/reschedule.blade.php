@extends('layouts.panou')
@section('title', 'Reprogramare serie')
@section('content')
    <x-panou.page-head title="Reprogramarea ședințelor viitoare" :crumbs="['Serii de ședințe' => route('panou.series.index'), $series->patient->fullName() => route('panou.series.show', $series), 'Reprogramare' => null]"
        :sub="$series->service->name.' · motiv: '.$data['reason']" />
    @if (! $plan)
        <x-panou.panel><x-panou.empty title="Nicio ședință viitoare confirmată">Nu există ședințe de mutat de la data aleasă.</x-panou.empty></x-panou.panel>
    @else
        <form method="post" action="{{ route('panou.series.reschedule', $series) }}" class="stack">
            @csrf
            <input type="hidden" name="reason" value="{{ $data['reason'] }}">
            <input type="hidden" name="staff_member_id" value="{{ $data['staff_member_id'] ?? '' }}">
            <x-panou.panel :flush="true">
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>Mută</th><th>Acum</th><th>Nou</th><th>Stare</th></tr></thead>
                        <tbody>
                        @foreach ($plan as $item)
                            @php $id = $item['appointment_id']; @endphp
                            <tr>
                                <td><input type="checkbox" name="choice[{{ $id }}][include]" value="1" @checked(! $item['conflicts'] || $item['alternatives']) aria-label="Mută ședința"></td>
                                <td class="num">{{ $item['current_start']->format('d.m.Y H:i') }}</td>
                                <td class="num">
                                    @if ($item['conflicts'] && $item['alternatives'])
                                        @foreach ($item['alternatives'] as $k => $alt)
                                            <label class="check small"><input type="radio" name="choice[{{ $id }}][start]" value="{{ $alt['start']->format('Y-m-d H:i') }}" @checked($k === 0)><span>{{ $alt['start']->format('d.m.Y H:i') }}</span></label>
                                        @endforeach
                                    @else
                                        {{ $item['start']->format('d.m.Y H:i') }}
                                        <input type="hidden" name="choice[{{ $id }}][start]" value="{{ $item['start']->format('Y-m-d H:i') }}">
                                    @endif
                                </td>
                                <td>@if ($item['conflicts'])<x-panou.badge tone="danger">conflict</x-panou.badge> <span class="small">{{ implode(' ', $item['conflicts']) }}</span>@else<x-panou.badge tone="ok">liber</x-panou.badge>@endif</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </x-panou.panel>
            <div class="form-actions"><button class="btn btn--primary" type="submit">Aplică reprogramarea</button></div>
        </form>
    @endif
@endsection
