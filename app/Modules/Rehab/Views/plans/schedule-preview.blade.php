@extends('layouts.panou')
@section('title', 'Verificare ședințe')
@section('content')
    @php $bad = collect($proposal)->filter(fn ($i) => $i['conflicts'])->count(); $plan = $item->plan; @endphp
    <x-panou.page-head title="Verificați ședințele" :sub="$plan->patient->fullName().' · '.$item->service->name.($staffMember ? ' · '.$staffMember->display_name : '').' · '.count($proposal).' ședințe propuse'"
        :crumbs="['Plan #'.$plan->id => route('panou.rehab.plans.show', $plan), 'Programare' => route('panou.rehab.plans.items.schedule', $item), 'Verificare' => null]" />

    @if ($bad)
        <div class="alert alert--warn"><x-panou.icon name="warn" /><div>{{ $bad }} ședințe au conflicte. Alegeți o alternativă sau debifați ședința. Seria se creează parțial numai dacă bifați acordul de la final.</div></div>
    @else
        <div class="alert alert--ok"><x-panou.icon name="check" /><div>Toate ședințele sunt libere în acest moment (terapeut, sală și aparat).</div></div>
    @endif

    <form method="post" action="{{ route('panou.rehab.plans.items.schedule-store', $item) }}" class="stack">
        @csrf
        @foreach (['start_date', 'time', 'count', 'staff_member_id'] as $k)<input type="hidden" name="{{ $k }}" value="{{ $data[$k] ?? '' }}">@endforeach
        @foreach ($data['weekdays'] as $d)<input type="hidden" name="weekdays[]" value="{{ $d }}">@endforeach
        <x-panou.panel :flush="true">
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Include</th><th>#</th><th>Interval propus</th><th>Stare</th><th>Alegere</th></tr></thead>
                    <tbody>
                    @foreach ($proposal as $row)
                        @php $i = $row['index']; @endphp
                        <tr>
                            <td><input type="checkbox" name="choice[{{ $i }}][include]" value="1" aria-label="Include ședința {{ $i }}" @checked(! $row['conflicts'] || $row['alternatives'])></td>
                            <td class="num">{{ $i }}</td>
                            <td class="num">{{ $row['start']->locale('ro')->isoFormat('ddd D MMM YYYY, HH:mm') }}</td>
                            <td>
                                @if ($row['conflicts'])
                                    <x-panou.badge tone="danger">conflict</x-panou.badge><div class="small">{{ implode(' ', $row['conflicts']) }}</div>
                                @else
                                    <x-panou.badge tone="ok">liber</x-panou.badge>
                                @endif
                                @foreach ($row['warnings'] as $w)<div class="small muted">⚠ {{ $w }}</div>@endforeach
                            </td>
                            <td>
                                @if ($row['conflicts'])
                                    @forelse ($row['alternatives'] as $k => $alt)
                                        <label class="check small"><input type="radio" name="choice[{{ $i }}][start]" value="{{ $alt['start']->format('Y-m-d H:i') }}|{{ $alt['staff_member_id'] }}" @checked($k === 0)>
                                            <span>{{ $alt['start']->locale('ro')->isoFormat('ddd D MMM HH:mm') }}{{ $alt['staff'] ? ' · '.$alt['staff'] : '' }}</span></label>
                                    @empty
                                        <span class="small muted">nicio alternativă în 3 zile</span>
                                        <input type="hidden" name="choice[{{ $i }}][start]" value="{{ $row['start']->format('Y-m-d H:i') }}">
                                    @endforelse
                                @else
                                    <input type="hidden" name="choice[{{ $i }}][start]" value="{{ $row['start']->format('Y-m-d H:i') }}">
                                    <span class="small muted">intervalul propus</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </x-panou.panel>
        @error('series')<div class="alert alert--danger" role="alert">{{ $message }}</div>@enderror
        <label class="check"><input type="checkbox" name="allow_partial" value="1"><span>Sunt de acord să se programeze doar ședințele care se pot rezerva; celelalte rămân „neprogramate” în plan.</span></label>
        <div class="form-actions">
            <button class="btn btn--primary" type="submit">Programează ședințele</button>
            <a class="btn btn--ghost" href="{{ route('panou.rehab.plans.items.schedule', $item) }}">Înapoi la regulă</a>
        </div>
    </form>
@endsection
