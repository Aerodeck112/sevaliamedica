@extends('layouts.panou')
@section('title', 'Verificare serie')
@section('content')
    @php $bad = collect($plan)->filter(fn ($i) => $i['conflicts'])->count(); @endphp
    <x-panou.page-head title="Verificați ședințele" :crumbs="['Serii de ședințe' => route('panou.series.index'), 'Verificare' => null]"
        :sub="$patient->fullName().' · '.$service->name.($staffMember ? ' · '.$staffMember->display_name : '').' · '.count($plan).' ședințe propuse'" />

    @if ($bad)
        <div class="alert alert--warn"><x-panou.icon name="warn" /><div>{{ $bad }} ședințe au conflicte. Alegeți o alternativă sau debifați ședința. Seria se creează parțial numai dacă bifați acordul de la final.</div></div>
    @else
        <div class="alert alert--ok"><x-panou.icon name="check" /><div>Toate ședințele sunt libere în acest moment.</div></div>
    @endif

    <form method="post" action="{{ route('panou.series.store') }}" class="stack">
        @csrf
        @foreach (['patient_id', 'service_id', 'staff_member_id', 'location_id', 'start_date', 'time', 'count', 'notes'] as $k)
            <input type="hidden" name="{{ $k }}" value="{{ $data[$k] ?? '' }}">
        @endforeach
        @foreach ($data['weekdays'] as $d)<input type="hidden" name="weekdays[]" value="{{ $d }}">@endforeach

        <x-panou.panel :flush="true">
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Include</th><th>#</th><th>Interval propus</th><th>Stare</th><th>Alegere</th></tr></thead>
                    <tbody>
                    @foreach ($plan as $item)
                        @php $i = $item['index']; @endphp
                        <tr>
                            <td><input type="checkbox" name="choice[{{ $i }}][include]" value="1" aria-label="Include ședința {{ $i }}" @checked(! $item['conflicts'] || $item['alternatives'])></td>
                            <td class="num">{{ $i }}</td>
                            <td class="num">{{ $item['start']->locale('ro')->isoFormat('ddd D MMM YYYY, HH:mm') }}</td>
                            <td>
                                @if ($item['conflicts'])
                                    <x-panou.badge tone="danger">conflict</x-panou.badge>
                                    <div class="small">{{ implode(' ', $item['conflicts']) }}</div>
                                @else
                                    <x-panou.badge tone="ok">liber</x-panou.badge>
                                @endif
                                @foreach ($item['warnings'] as $w)<div class="small muted">⚠ {{ $w }}</div>@endforeach
                            </td>
                            <td>
                                @if ($item['conflicts'])
                                    @forelse ($item['alternatives'] as $k => $alt)
                                        <label class="check small"><input type="radio" name="choice[{{ $i }}][start]" value="{{ $alt['start']->format('Y-m-d H:i') }}|{{ $alt['staff_member_id'] }}" @checked($k === 0)>
                                            <span>{{ $alt['start']->locale('ro')->isoFormat('ddd D MMM HH:mm') }}{{ $alt['staff'] ? ' · '.$alt['staff'] : '' }}</span></label>
                                    @empty
                                        <span class="small muted">nicio alternativă în 3 zile</span>
                                        <input type="hidden" name="choice[{{ $i }}][start]" value="{{ $item['start']->format('Y-m-d H:i') }}">
                                    @endforelse
                                @else
                                    <input type="hidden" name="choice[{{ $i }}][start]" value="{{ $item['start']->format('Y-m-d H:i') }}">
                                    <span class="small muted">intervalul propus</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </x-panou.panel>
        @error('series')<div class="alert alert--danger">{{ $message }}</div>@enderror
        <label class="check"><input type="checkbox" name="allow_partial" value="1"><span>Sunt de acord să se creeze seria chiar dacă unele ședințe nu pot fi rezervate (vor rămâne de programat).</span></label>
        <div class="form-actions">
            <button class="btn btn--primary" type="submit">Creează seria</button>
            <a class="btn btn--ghost" href="{{ url()->previous() }}">Înapoi la regulă</a>
        </div>
    </form>
@endsection
