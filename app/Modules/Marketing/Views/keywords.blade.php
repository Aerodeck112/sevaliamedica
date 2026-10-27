@extends('layouts.panou')
@section('title', 'Cuvinte cheie')
@section('content')
    <x-panou.page-head title="Cuvinte cheie urmărite"
        sub="Expresiile pentru care vreți să apară Sevalia în Google și pagina care trebuie să le răspundă. Auditul verifică dacă expresia apare în titlu, H1, descriere și text. Nu se adaugă text ascuns: optimizarea se face din titlul și descrierea SEO ale paginii (Site → Pagini → SEO)." />

    <div class="grid grid--aside">
        <x-panou.panel :flush="true">
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Expresie</th><th>Pagina țintă</th><th>Ultimul audit</th><th>Activ</th><th></th></tr></thead>
                <tbody>
                @forelse ($keywords as $k)
                    @php $r = $results->get($k->phrase); @endphp
                    <tr>
                        <td colspan="2">
                            <form method="post" action="{{ route('panou.marketing.keywords.update', $k) }}" class="cluster" id="kw{{ $k->id }}">
                                @csrf @method('put')
                                <label class="sr-only" for="kw{{ $k->id }}p">Expresie</label>
                                <input id="kw{{ $k->id }}p" name="phrase" value="{{ $k->phrase }}" maxlength="150" required style="min-width:14rem">
                                <label class="sr-only" for="kw{{ $k->id }}t">Pagina țintă</label>
                                <select id="kw{{ $k->id }}t" name="target_path">
                                    @unless ($pages->has($k->target_path))<option value="{{ $k->target_path }}" selected>{{ $k->target_path }} (nepublicată)</option>@endunless
                                    @foreach ($pages as $path => $label)<option value="{{ $path }}" @selected($path === $k->target_path)>{{ \Illuminate\Support\Str::limit($label, 60) }}</option>@endforeach
                                </select>
                            </form>
                        </td>
                        <td>
                            @if ($r)
                                <x-panou.badge :tone="$r['score'] >= 90 ? 'ok' : ($r['score'] >= 60 ? 'warn' : 'danger')">{{ $r['score'] }}%</x-panou.badge>
                            @else
                                <span class="muted small">după următorul audit</span>
                            @endif
                        </td>
                        <td><label class="check"><input type="checkbox" name="is_active" value="1" form="kw{{ $k->id }}" @checked($k->is_active)> <span class="sr-only">Activ</span></label></td>
                        <td style="white-space:nowrap;text-align:right">
                            <button class="btn btn--small" type="submit" form="kw{{ $k->id }}">Salvează</button>
                            <form method="post" action="{{ route('panou.marketing.keywords.destroy', $k) }}" style="display:inline" data-confirm="Ștergeți cuvântul cheie „{{ $k->phrase }}”?">
                                @csrf @method('delete')<button class="btn btn--small btn--danger" type="submit">Șterge</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5"><x-panou.empty title="Niciun cuvânt cheie">Adăugați expresiile pe care le caută pacienții.</x-panou.empty></td></tr>
                @endforelse
                </tbody>
            </table></div>
        </x-panou.panel>

        <div class="stack">
            <x-panou.panel title="Cuvânt cheie nou">
                <form method="post" action="{{ route('panou.marketing.keywords.store') }}" class="stack">
                    @csrf
                    <x-panou.field name="phrase" label="Expresie" required placeholder="ex. fizioterapie Târgu Mureș" />
                    <x-panou.field name="target_path" type="select" label="Pagina care răspunde" :options="$pages->all()" required />
                    <button class="btn btn--primary" type="submit">Adaugă</button>
                </form>
            </x-panou.panel>
            <x-panou.panel title="Cum se citește">
                <p class="small">O pagină bine țintită are expresia în <strong>titlul SEO</strong> (cel mai important), în <strong>H1</strong>, în <strong>descriere</strong> și în text. Pentru căutările locale contează la fel de mult <strong>profilul Google Business</strong> (recenzii, fotografii, program) și consecvența adresei și a telefonului pe toate siteurile.</p>
                <p class="small muted">Nu promitem poziții în Google: ordinea rezultatelor o decide Google, după sute de factori.</p>
            </x-panou.panel>
        </div>
    </div>
@endsection
