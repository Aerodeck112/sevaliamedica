@extends('layouts.panou')
@section('title', 'Corector SEO')
@section('content')
    @php
        $limits = ['title' => [30, 65], 'description' => [70, 160]];
        $byPage = collect($proposals['fixes'])->groupBy('path');
        $taskLabels = ['thin_content' => 'Conținut', 'gsc_missing' => 'Google', 'gbp_missing' => 'Google', 'h1_missing' => 'Titlu H1', 'h1_multiple' => 'Titlu H1',
            'link_broken' => 'Link rupt', 'legacy_reference' => 'Site vechi', 'img_alt_missing' => 'Imagini', 'title_duplicate' => 'Duplicat', 'desc_duplicate' => 'Duplicat'];
    @endphp
    <x-panou.page-head title="Corector SEO" :crumbs="['Audit SEO' => route('panou.marketing.seo'), 'Corector' => null]"
        sub="Pentru problemele din ultimul audit care au o rezolvare sigură, corectorul propune valoarea nouă: titluri scurtate sau completate cu numele clinicii, descrieri compuse din frazele existente pe pagină. Verificați, modificați dacă vreți, apoi aplicați. Fiecare pagină primește o versiune nouă (istoricul o păstrează pe cea veche), iar scorul se recalculează imediat." />

    @if (! $run)
        <x-panou.empty title="Încă nu există un audit">Porniți întâi o verificare din <a href="{{ route('panou.marketing.seo') }}">Audit SEO</a>.</x-panou.empty>
    @else
        @if ($running)
            <div class="alert alert--info" role="status"><x-panou.icon name="clock" /><div>O verificare este în curs; propunerile de mai jos sunt din auditul din {{ $run->finished_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') }}.</div></div>
        @endif

        <x-panou.panel title="Corecturi automate ({{ count($proposals['fixes']) }})" :flush="true">
            @if ($proposals['fixes'] === [])
                <x-panou.empty title="Nimic de corectat automat">Toate titlurile și descrierile sunt în limitele recomandate. Rămân doar sarcinile de mai jos.</x-panou.empty>
            @else
                <form method="post" action="{{ route('panou.marketing.seo.fix.apply') }}" data-module="seo-fix">
                    @csrf
                    <div class="table-wrap">
                        <table class="table seo-fix">
                            <thead><tr><th scope="col" style="width:2.5rem"><span class="sr-only">Aplică</span></th><th scope="col">Pagina și problema</th><th scope="col">Acum</th><th scope="col">Propunere (o puteți modifica)</th></tr></thead>
                            <tbody>
                            @foreach ($byPage as $path => $items)
                                @foreach ($items as $fix)
                                    @php $id = 'fix-'.$fix['page_id'].'-'.$fix['field']; $lim = $limits[$fix['field']] ?? null; @endphp
                                    <tr>
                                        <td><input type="checkbox" id="{{ $id }}-use" name="use[{{ $fix['page_id'] }}][{{ $fix['field'] }}]" value="1" @checked(! $fix['blocked']) @disabled($fix['blocked']) aria-label="Aplică: {{ $fix['label'] }} pe {{ $path }}"></td>
                                        <td>
                                            <a href="{{ $path }}" target="_blank" rel="noopener" class="num small">{{ $path }}</a><br>
                                            <span class="small">{{ $fix['label'] }}</span>
                                            @if ($fix['blocked'])<br><span class="small field__error">{{ $fix['blocked'] }}</span>@endif
                                        </td>
                                        <td class="small muted" style="max-width:22rem">
                                            {{ $fix['current'] !== '' ? $fix['current'] : '—' }}
                                            @if ($lim && $fix['current'] !== '')<br><span class="num">{{ mb_strlen($fix['current']) }} caractere</span>@endif
                                        </td>
                                        <td style="min-width:18rem">
                                            @if ($lim)
                                                <label class="sr-only" for="{{ $id }}">{{ $fix['label'] }} – {{ $path }}</label>
                                                @if ($fix['field'] === 'description')
                                                    <textarea id="{{ $id }}" name="fix[{{ $fix['page_id'] }}][{{ $fix['field'] }}]" rows="3" class="input" data-min="{{ $lim[0] }}" data-max="{{ $lim[1] }}">{{ $fix['proposed'] }}</textarea>
                                                @else
                                                    <input type="text" id="{{ $id }}" name="fix[{{ $fix['page_id'] }}][{{ $fix['field'] }}]" value="{{ $fix['proposed'] }}" class="input" data-min="{{ $lim[0] }}" data-max="{{ $lim[1] }}">
                                                @endif
                                                <span class="small num seo-fix__count" data-count-for="{{ $id }}">{{ mb_strlen($fix['proposed']) }} caractere (recomandat {{ $lim[0] }}–{{ $lim[1] }})</span>
                                            @else
                                                <input type="hidden" name="fix[{{ $fix['page_id'] }}][{{ $fix['field'] }}]" value="1">
                                                <strong>{{ $fix['proposed'] }}</strong>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="panel__body cluster">
                        @error('use')<span class="field__error">{{ $message }}</span>@enderror
                        <button class="btn btn--primary" type="submit"><x-panou.icon name="check" /> Aplică corecturile bifate</button>
                        <span class="small muted">Se publică imediat; din Site → Pagini → Istoric se poate reveni oricând.</span>
                    </div>
                </form>
            @endif
        </x-panou.panel>

        <x-panou.panel title="De făcut manual ({{ count($proposals['tasks']) }})" :flush="true">
            @if ($proposals['tasks'] === [])
                <x-panou.empty title="Nicio sarcină">Nu rămâne nimic de făcut manual.</x-panou.empty>
            @else
                <div class="table-wrap">
                    <table class="table seo-fix seo-fix--tasks">
                        <thead><tr><th scope="col">Tip</th><th scope="col">Pagina</th><th scope="col">Ce trebuie făcut</th><th scope="col"><span class="sr-only">Acțiune</span></th></tr></thead>
                        <tbody>
                        @foreach ($proposals['tasks'] as $task)
                            <tr>
                                <td><x-panou.badge :tone="$task['code'] === 'thin_content' ? 'info' : 'warn'">{{ $taskLabels[$task['code']] ?? 'Altele' }}</x-panou.badge></td>
                                <td class="num small">{{ $task['path'] ?? 'tot siteul' }}</td>
                                <td class="small">{{ $task['message'] }}</td>
                                <td>@if ($task['url'])<a class="btn btn--small" href="{{ $task['url'] }}">{{ $task['action'] }}</a>@endif</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-panou.panel>
    @endif
@endsection
