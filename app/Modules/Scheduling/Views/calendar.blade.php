@extends('layouts.panou')
@section('title', 'Calendar')
@section('wide', '1')
@section('content')
    @php
        $q = fn (array $over) => route('panou.calendar', array_filter(array_merge($filters, ['date' => $date->toDateString(), 'view' => $view, 'group' => $group, 'location_id' => $location?->id], $over), fn ($v) => $v !== null && $v !== ''));
        $prev = $view === 'saptamana' ? $date->subWeek() : $date->subDay();
        $next = $view === 'saptamana' ? $date->addWeek() : $date->addDay();
        $title = $view === 'saptamana'
            ? $rangeStart->locale('ro')->isoFormat('D MMM').' – '.$rangeEnd->subDay()->locale('ro')->isoFormat('D MMM YYYY')
            : $date->locale('ro')->isoFormat('dddd, D MMMM YYYY');
        $height = ($endMin - $startMin) * $px;
    @endphp

    <x-panou.page-head title="Calendar" :sub="$title">
        <x-slot:actions>
            <a class="btn" href="{{ $q(['date' => $prev->toDateString()]) }}" aria-label="Înapoi">‹</a>
            <a class="btn" href="{{ $q(['date' => now()->toDateString()]) }}">Azi</a>
            <a class="btn" href="{{ $q(['date' => $next->toDateString()]) }}" aria-label="Înainte">›</a>
            @can('scheduling.manage')
                <a class="btn btn--primary" href="{{ route('panou.appointments.create', ['date' => $date->toDateString(), 'location_id' => $location?->id]) }}"><x-panou.icon name="plus" />Programare nouă</a>
            @endcan
        </x-slot:actions>
    </x-panou.page-head>

    @if (! $location)
        <div class="alert alert--warn"><x-panou.icon name="warn" /><div>Nu există nicio locație activă. Configurați locațiile în Administrare → Locații.</div></div>
    @elseif ($noStaff)
        <div class="alert alert--warn"><x-panou.icon name="warn" /><div>{{ $location->name }} nu are personal asociat. Asociați medicii/terapeuții locației în Administrare → Personal.</div></div>
    @endif
    @if ($noResources)
        <div class="alert alert--info"><x-panou.icon name="warn" /><div>Locația nu are încă săli sau aparate configurate. Serviciile care cer o sală sau un aparat nu pot fi programate până la configurarea resurselor în Administrare → Săli / Aparatură.</div></div>
    @endif

    <x-panou.panel :flush="true">
        <form class="filters cal-filters" method="get" data-autosubmit>
            <input type="hidden" name="date" value="{{ $date->toDateString() }}">
            <div class="field">
                <span class="field__label">Vizualizare</span>
                <div class="seg" role="group" aria-label="Vizualizare">
                    <a href="{{ $q(['view' => 'zi']) }}" @if ($view === 'zi') aria-current="page" @endif>Zi</a>
                    <a href="{{ $q(['view' => 'saptamana']) }}" @if ($view === 'saptamana') aria-current="page" @endif>Săptămână</a>
                </div>
            </div>
            @if ($view === 'zi')
                <div class="field">
                    <span class="field__label">Coloane</span>
                    <div class="seg" role="group" aria-label="Coloane">
                        <a href="{{ $q(['group' => 'personal']) }}" @if ($group === 'personal') aria-current="page" @endif>Personal</a>
                        <a href="{{ $q(['group' => 'sali']) }}" @if ($group === 'sali') aria-current="page" @endif>Săli</a>
                    </div>
                </div>
            @endif
            <input type="hidden" name="view" value="{{ $view }}"><input type="hidden" name="group" value="{{ $group }}">
            <x-panou.field name="location_id" type="select" label="Locație" :value="$location?->id" :options="$locations->pluck('name', 'id')->all()" :placeholder="false" />
            <details class="cal-more" open data-collapse-mobile>
                <summary class="btn btn--small">Filtre</summary>
                <div class="cal-more__body">
                    <x-panou.field name="specialty_id" type="select" label="Specialitate" :value="$filters['specialty_id'] ?? null" :options="$specialties->pluck('name', 'id')->all()" placeholder="Toate" />
                    <x-panou.field name="staff[]" id="f_staff" type="select" label="Persoană" :value="$selectedStaff[0] ?? null" :options="$allStaff->pluck('display_name', 'id')->all()" placeholder="Toți" />
                    <x-panou.field name="room_id" type="select" label="Sală" :value="$filters['room_id'] ?? null" :options="$rooms->pluck('name', 'id')->all()" placeholder="Toate" />
                    <x-panou.field name="equipment_id" type="select" label="Aparat" :value="$filters['equipment_id'] ?? null" :options="$equipment->pluck('name', 'id')->all()" placeholder="Toate" />
                    <x-panou.field name="cancelled" type="checkbox" label="Arată anulările" :value="$filters['cancelled'] ?? false" />
                    <noscript><button class="btn" type="submit">Aplică</button></noscript>
                </div>
            </details>
        </form>

        <div class="cal-legend small">
            @foreach (\App\Modules\Scheduling\Support\AppointmentStatus::LABELS as $s => $label)
                @if ($s !== 'solicitare')
                    <span class="cal-legend__item"><span class="cal-dot cal-dot--{{ $s }}" aria-hidden="true"></span>{{ $label }} <span class="muted">{{ $counts[$s] ?? 0 }}</span></span>
                @endif
            @endforeach
            @if ($hiddenStaff)<span class="muted">· {{ $hiddenStaff }} persoane fără program în această zi nu sunt afișate (alegeți-le din „Persoană”).</span>@endif
            <span class="muted">· Trageți o programare pentru a o muta sau marginea de jos pentru a schimba durata. Pe tastatură: deschideți programarea și folosiți „Mută programarea”.</span>
        </div>

        @if (! $columns)
            <x-panou.empty title="Nimic de afișat">Alegeți altă locație sau alt filtru.</x-panou.empty>
        @else
            <div class="cal" data-module="calendar"
                 data-start-min="{{ $startMin }}" data-px="{{ $px }}" data-slot="{{ $slot }}"
                 data-move-url="{{ url('/panou/programari/__ID__/muta') }}"
                 data-create-url="{{ route('panou.appointments.create') }}"
                 data-location="{{ $location?->id }}" data-view="{{ $view }}" data-group="{{ $group }}"
                 data-can-manage="{{ auth()->user()->can('scheduling.manage') ? '1' : '0' }}"
                 data-csrf="{{ csrf_token() }}">
                <div class="cal__scroll">
                    <div class="cal__grid" style="--cols: {{ count($columns) }}">
                        <div class="cal__corner"></div>
                        @foreach ($columns as $col)
                            <div class="cal__head {{ $col['isToday'] && $view === 'saptamana' ? 'is-today' : '' }}">
                                @if ($col['color'])<span class="cal-swatch" style="background: {{ $col['color'] }}" aria-hidden="true"></span>@endif
                                <span>{{ $col['label'] }}</span>
                                @if ($col['noSchedule'])<span class="cal__hint" title="Fără program configurat: se afișează programul locației">fără program</span>@endif
                            </div>
                        @endforeach

                        <div class="cal__times" style="height: {{ $height }}px" aria-hidden="true">
                            @for ($m = $startMin; $m < $endMin; $m += 60)
                                <span style="top: {{ ($m - $startMin) * $px }}px">{{ sprintf('%02d:00', intdiv($m, 60)) }}</span>
                            @endfor
                        </div>

                        @foreach ($columns as $col)
                            <div class="cal__col" style="height: {{ $height }}px; --lanes: {{ $col['lanes'] }}; --hour: {{ 60 * $px }}px"
                                 data-col-type="{{ $col['type'] }}" data-col-id="{{ $col['id'] }}" data-date="{{ $col['date']->toDateString() }}"
                                 aria-label="{{ $col['label'] }}">
                                @foreach ($col['windows'] as $w)
                                    <div class="cal__open" style="top: {{ $w['top'] }}px; height: {{ $w['height'] }}px"></div>
                                @endforeach
                                @if ($col['isToday'] && $nowMin >= $startMin && $nowMin <= $endMin)
                                    <div class="cal__now" style="top: {{ ($nowMin - $startMin) * $px }}px"><span class="sr-only">Ora curentă</span></div>
                                @endif
                                @foreach ($col['cards'] as $card)
                                    @php $a = $card['a']; $movable = in_array($a->status, ['confirmata', 'sosit'], true); @endphp
                                    <a class="appt appt--{{ $a->status }} {{ $movable ? 'is-movable' : '' }} {{ $card['height'] < 70 ? 'appt--compact' : '' }}"
                                       href="{{ route('panou.appointments.show', $a) }}"
                                       style="top: {{ $card['top'] }}px; height: {{ $card['height'] }}px; --lane: {{ $card['lane'] }}; --accent: {{ $a->staffMember?->calendar_color ?? '#00C4C4' }}"
                                       data-id="{{ $a->id }}" data-version="{{ $a->version }}"
                                       data-date="{{ $a->starts_at->toDateString() }}" data-start="{{ $a->starts_at->format('H:i') }}"
                                       data-duration="{{ $a->durationMinutes() }}" data-staff="{{ $a->staff_member_id }}">
                                        <span class="appt__time">{{ $a->starts_at->format('H:i') }}–{{ $a->ends_at->format('H:i') }}</span>
                                        <span class="appt__who">{{ $a->patient?->fullName() ?? 'Pacient neidentificat' }}</span>
                                        <span class="appt__what">{{ $a->service?->name }}@if ($view === 'saptamana' || $group === 'sali') · {{ $a->staffMember?->display_name }}@endif</span>
                                        <span class="appt__status">{{ $a->statusLabel() }}</span>
                                        @if ($movable)<span class="appt__resize" aria-hidden="true"></span>@endif
                                    </a>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </div>
                <div class="cal__live sr-only" role="status" aria-live="polite"></div>
            </div>
        @endif
    </x-panou.panel>

    <dialog class="dialog" id="move-reason">
        <form method="dialog" class="stack">
            <div class="dialog__head"><h2>Motivul modificării</h2></div>
            <div class="dialog__body stack">
                <p class="move-reason__summary"></p>
                <x-panou.field name="reason_choice" type="select" label="Motiv" :placeholder="false"
                    :options="['La cererea pacientului' => 'La cererea pacientului', 'Indisponibilitate personal' => 'Indisponibilitate personal', 'Corectarea programării' => 'Corectarea programării', 'Reorganizare program' => 'Reorganizare program', 'altul' => 'Alt motiv…']" />
                <x-panou.field name="reason_other" label="Alt motiv" />
            </div>
            <div class="dialog__foot">
                <button class="btn" value="cancel" type="submit">Renunță</button>
                <button class="btn btn--primary" value="ok" type="submit">Mută</button>
            </div>
        </form>
    </dialog>
@endsection
