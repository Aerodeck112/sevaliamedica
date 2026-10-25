@extends('layouts.panou')
@section('title', $template->name)
@section('content')
    @php
        // La fiecare afișare adăugăm o secțiune goală și trei rânduri goale pe secțiune, pentru completare fără JavaScript.
        $sections = $schema['sections'] ?? [];
        $sections[] = ['title' => '', 'fields' => []];
    @endphp
    <x-panou.page-head :title="$template->name" sub="Editați structura ca draft. Fișele noi folosesc doar versiunile publicate; fișele existente rămân pe versiunea lor."
        :crumbs="['Șabloane fișe' => route('panou.medical.templates.index'), $template->name => null]" />

    @if ($template->is_example)
        <div class="alert alert--info"><x-panou.icon name="warn" /><div>Acesta este un <strong>exemplu generic</strong> (doar secțiuni de text liber). Adaptați-l conform practicii medicilor clinicii înainte de utilizare.</div></div>
    @endif

    <div class="grid grid--aside">
        <form method="post" action="{{ route('panou.medical.templates.update', $template) }}" class="stack">
            @csrf @method('put')
            <x-panou.panel title="Date generale">
                <div class="form-grid">
                    <x-panou.field name="name" label="Denumire" :value="$template->name" required />
                    <x-panou.field name="specialty_id" type="select" label="Specialitate" :value="$template->specialty_id" :options="$specialties" placeholder="Orice specialitate" />
                    <x-panou.field name="description" label="Descriere internă" :value="$template->description" full />
                </div>
            </x-panou.panel>

            <x-panou.panel title="Structură">
                <p class="small muted">Rândurile fără etichetă și secțiunile fără titlu sunt ignorate. „Ordine” stabilește poziția. Pentru liste și tabele scrieți câte o opțiune / coloană pe rând.</p>
                @foreach ($sections as $si => $section)
                    @php $fields = array_merge($section['fields'] ?? [], array_fill(0, 3, [])); @endphp
                    <fieldset class="tpl-section">
                        <legend class="sr-only">Secțiunea {{ $si + 1 }}</legend>
                        <div class="cluster" style="align-items:flex-end">
                            <div class="field" style="width:80px"><label class="field__label" for="s{{ $si }}o">Ordine</label><input id="s{{ $si }}o" type="number" name="sections[{{ $si }}][order]" value="{{ ($si + 1) * 10 }}"></div>
                            <div class="field" style="flex:1"><label class="field__label" for="s{{ $si }}t">{{ $section['title'] === '' ? 'Secțiune nouă — titlu' : 'Titlul secțiunii' }}</label><input id="s{{ $si }}t" type="text" name="sections[{{ $si }}][title]" value="{{ $section['title'] }}"></div>
                            @if ($section['title'] !== '')<label class="check small"><input type="checkbox" name="sections[{{ $si }}][remove]" value="1"><span>Elimină secțiunea</span></label>@endif
                        </div>
                        @foreach ($fields as $fi => $f)
                            @php $p = "sections[$si][fields][$fi]"; $pid = "s{$si}f{$fi}"; $type = $f['type'] ?? 'textarea'; @endphp
                            <div class="tpl-field">
                                <div class="field"><label class="field__label" for="{{ $pid }}o">Ordine</label><input id="{{ $pid }}o" type="number" name="{{ $p }}[order]" value="{{ ($fi + 1) * 10 }}"></div>
                                <div class="field"><label class="field__label" for="{{ $pid }}l">Etichetă</label><input id="{{ $pid }}l" type="text" name="{{ $p }}[label]" value="{{ $f['label'] ?? '' }}"><input type="hidden" name="{{ $p }}[key]" value="{{ $f['key'] ?? '' }}"></div>
                                <div class="field"><label class="field__label" for="{{ $pid }}t">Tip</label>
                                    <select id="{{ $pid }}t" name="{{ $p }}[type]">@foreach ($types as $tk => $tl)<option value="{{ $tk }}" @selected($type === $tk)>{{ $tl }}</option>@endforeach</select></div>
                                <div class="field"><label class="field__label" for="{{ $pid }}h">Indicație</label><input id="{{ $pid }}h" type="text" name="{{ $p }}[help]" value="{{ $f['help'] ?? '' }}"></div>
                                <div class="field">
                                    <label class="check small"><input type="checkbox" name="{{ $p }}[required]" value="1" @checked($f['required'] ?? false)><span>Obligatoriu</span></label>
                                    @if (! empty($f['label']))<label class="check small"><input type="checkbox" name="{{ $p }}[remove]" value="1"><span>Elimină</span></label>@endif
                                </div>
                                <details class="tpl-field__extra" @if (in_array($type, ['number', 'select', 'scale', 'table'], true)) open @endif>
                                    <summary class="small">Opțiuni pentru număr, listă, scală, tabel</summary>
                                    <div class="tpl-field__extra">
                                        <div class="field"><label class="field__label" for="{{ $pid }}u">Unitate (număr)</label><input id="{{ $pid }}u" type="text" name="{{ $p }}[unit]" value="{{ $f['unit'] ?? '' }}"></div>
                                        <div class="field"><label class="field__label" for="{{ $pid }}op">Opțiuni (listă)</label><textarea id="{{ $pid }}op" name="{{ $p }}[options]">{{ is_array($f['options'] ?? null) ? implode("\n", $f['options']) : ($f['options'] ?? '') }}</textarea></div>
                                        <div class="field"><label class="field__label" for="{{ $pid }}mi">Minim (scală)</label><input id="{{ $pid }}mi" type="number" name="{{ $p }}[min]" value="{{ $f['min'] ?? 0 }}"></div>
                                        <div class="field"><label class="field__label" for="{{ $pid }}ma">Maxim (scală)</label><input id="{{ $pid }}ma" type="number" name="{{ $p }}[max]" value="{{ $f['max'] ?? 10 }}"></div>
                                        <div class="field"><label class="field__label" for="{{ $pid }}c">Coloane (tabel)</label><textarea id="{{ $pid }}c" name="{{ $p }}[columns]">{{ is_array($f['columns'] ?? null) ? implode("\n", $f['columns']) : ($f['columns'] ?? '') }}</textarea></div>
                                        <div class="field"><label class="field__label" for="{{ $pid }}r">Rânduri (tabel)</label><input id="{{ $pid }}r" type="number" name="{{ $p }}[rows]" value="{{ $f['rows'] ?? 3 }}"></div>
                                    </div>
                                </details>
                            </div>
                        @endforeach
                    </fieldset>
                @endforeach
                <div class="form-actions"><button class="btn btn--primary" type="submit">Salvează structura (draft)</button></div>
            </x-panou.panel>
        </form>

        <div class="stack">
            <x-panou.panel title="Publicare">
                @if ($template->hasUnpublishedChanges())
                    <p>Draftul are modificări nepublicate.</p>
                @else
                    <p class="small muted">Draftul este identic cu ultima versiune publicată.</p>
                @endif
                <form method="post" action="{{ route('panou.medical.templates.publish', $template) }}" class="stack">
                    @csrf
                    <x-panou.field name="notes" label="Ce s-a schimbat (opțional)" />
                    <button class="btn btn--primary" type="submit" @disabled(! $template->hasUnpublishedChanges())>Publică versiunea</button>
                </form>
            </x-panou.panel>
            <x-panou.panel title="Previzualizare (draft)">
                @if (! $preview)
                    <p class="small muted">Adăugați o secțiune și câmpuri (sau corectați erorile semnalate) pentru previzualizare.</p>
                @else
                    <div inert style="opacity:.9">@include('medical::partials.fields-form', ['schema' => $preview, 'values' => []])</div>
                @endif
            </x-panou.panel>
            <x-panou.panel title="Versiuni">
                @forelse ($template->versions as $v)
                    <p class="small"><strong>v{{ $v->version }}</strong> · {{ $v->published_at->format('d.m.Y H:i') }} · {{ $v->publisher?->name }}@if ($v->notes)<br>{{ $v->notes }}@endif</p>
                @empty
                    <p class="small muted">Nicio versiune publicată.</p>
                @endforelse
                <form method="post" action="{{ route('panou.medical.templates.toggle', $template) }}">@csrf<button class="btn btn--small" type="submit">{{ $template->is_active ? 'Dezactivează' : 'Activează' }} șablonul</button></form>
            </x-panou.panel>
        </div>
    </div>
@endsection
