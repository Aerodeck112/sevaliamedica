@extends('layouts.panou')
@section('title', 'Redirecturi')
@section('content')
    <x-panou.page-head title="Redirecturi" sub="Fiecare adresă veche duce direct la destinația finală (301 permanent sau 302 temporar). Lanțurile și buclele sunt împiedicate automat." />
    <x-panou.panel title="Redirect nou">
        <form method="post" action="{{ route('panou.cms.redirects.store') }}">
            @csrf
            <div class="form-grid form-grid--3">
                <x-panou.field name="source_path" label="Adresa veche" placeholder="/pagina-veche" required />
                <x-panou.field name="target" label="Destinație" placeholder="/pagina-noua sau https://…" required />
                <x-panou.field name="status_code" type="select" label="Tip" :options="[301 => '301 — permanent', 302 => '302 — temporar']" :placeholder="false" value="301" />
                <x-panou.field name="note" label="Notă (opțional)" full />
            </div>
            <input type="hidden" name="is_active" value="1">
            <div class="form-actions"><button class="btn btn--primary" type="submit">Adaugă redirectul</button></div>
        </form>
    </x-panou.panel>
    <x-panou.panel :flush="true">
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Adresa veche</th><th>Destinație</th><th>Tip</th><th>Accesări</th><th>Notă</th><th></th></tr></thead>
                <tbody>
                @forelse ($redirects as $r)
                    <tr>
                        <td><code>{{ $r->source_path }}</code> @unless ($r->is_active)<x-panou.badge>inactiv</x-panou.badge>@endunless</td>
                        <td><code>{{ $r->target }}</code></td>
                        <td>{{ $r->status_code }}</td>
                        <td class="num">{{ $r->hits }}@if ($r->last_hit_at) <span class="muted small">(ultima {{ $r->last_hit_at->format('d.m.Y') }})</span>@endif</td>
                        <td class="small">{{ $r->note }}</td>
                        <td class="text-right">
                            <form method="post" action="{{ route('panou.cms.redirects.destroy', $r) }}" data-confirm="Ștergeți redirectul {{ $r->source_path }}?">@csrf @method('delete')<button class="btn btn--small btn--danger" type="submit">Șterge</button></form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6"><x-panou.empty title="Niciun redirect">Adăugați primul redirect mai sus.</x-panou.empty></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $redirects->links() }}
    </x-panou.panel>
    <p class="small muted">Regula pentru versiunea în engleză (<code>/en/…</code> → pagina în română) se configurează în <a href="{{ route('panou.cms.settings.edit') }}">Setări site</a>.</p>
@endsection
