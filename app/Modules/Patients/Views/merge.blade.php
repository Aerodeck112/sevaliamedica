@extends('layouts.panou')
@section('title', 'Unire fișe')
@section('content')
    <x-panou.page-head title="Unește fișe duplicate"
        :crumbs="['Pacienți' => route('panou.patients.index'), $source->fullName() => route('panou.patients.show', $source), 'Unire' => null]"
        sub="Fișa curentă (sursa) va fi unită în fișa aleasă (ținta). Programările și celelalte înregistrări se mută; fișa sursă rămâne în istoric, marcată ca unită. Operația nu se poate anula automat." />

    <div class="grid grid--2">
        <x-panou.panel title="Sursa (se închide)">
            <dl class="facts">
                <dt>Fișa</dt><dd>#{{ $source->id }} {{ $source->fullName() }}</dd>
                <dt>Data nașterii</dt><dd>{{ $source->birth_date?->format('d.m.Y') ?? '—' }}</dd>
                <dt>Telefon</dt><dd>{{ $source->phone ?? '—' }}</dd>
                <dt>CNP</dt><dd>{{ $source->cnp ? $source->maskedCnp() : '—' }}</dd>
            </dl>
            <hr class="sep">
            <p class="small muted">Se vor muta:</p>
            <ul class="small">
                @foreach ($preview as $row)<li>{{ $row['label'] }}: {{ $row['count'] }}</li>@endforeach
            </ul>
        </x-panou.panel>

        <x-panou.panel title="Ținta (rămâne)">
            @if ($target)
                <dl class="facts">
                    <dt>Fișa</dt><dd>#{{ $target->id }} {{ $target->fullName() }}</dd>
                    <dt>Data nașterii</dt><dd>{{ $target->birth_date?->format('d.m.Y') ?? '—' }}</dd>
                    <dt>Telefon</dt><dd>{{ $target->phone ?? '—' }}</dd>
                    <dt>CNP</dt><dd>{{ $target->cnp ? $target->maskedCnp() : '—' }}</dd>
                </dl>
                <form method="post" action="{{ route('panou.patients.merge.store', $source) }}" class="stack" style="margin-top:1rem">
                    @csrf
                    <input type="hidden" name="target_id" value="{{ $target->id }}">
                    <x-panou.field name="reason" type="textarea" label="Motivul unirii" required hint="Ex. fișă creată din greșeală la recepție; aceeași persoană (verificat act de identitate)." />
                    <label class="check"><input type="checkbox" name="confirm" value="1"><span>Am verificat că cele două fișe aparțin aceleiași persoane.</span></label>
                    <button class="btn btn--danger" type="submit">Unește fișele</button>
                </form>
            @else
                <form method="get" class="cluster" style="align-items:flex-end">
                    <x-panou.field name="q" type="search" label="Caută fișa țintă" :value="request('q')" />
                    <button class="btn" type="submit">Caută</button>
                </form>
                @if ($candidates->isNotEmpty())
                    <ul class="stack" style="list-style:none;padding:0;margin-top:1rem">
                        @foreach ($candidates as $c)
                            <li><a class="btn" href="{{ route('panou.patients.merge.create', [$source, 'target_id' => $c->id]) }}">#{{ $c->id }} {{ $c->fullName() }} · {{ $c->birth_date?->format('d.m.Y') ?? 'fără dată' }}</a></li>
                        @endforeach
                    </ul>
                @endif
            @endif
        </x-panou.panel>
    </div>
@endsection
