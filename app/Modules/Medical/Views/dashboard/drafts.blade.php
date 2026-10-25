<x-panou.panel title="Fișe de finalizat">
    <x-slot:actions><a class="btn btn--small" href="{{ route('panou.medical.drafts') }}">Toate ({{ $total }})</a></x-slot:actions>
    @if (! $staff)
        <x-panou.empty title="Contul nu este legat de o persoană din personal">Administratorul vă poate asocia contul în Administrare → Personal.</x-panou.empty>
    @elseif ($drafts->isEmpty())
        <x-panou.empty title="Nicio fișă în draft">Fișele începute și nefinalizate apar aici.</x-panou.empty>
    @else
        <ul class="nav-list">
            @foreach ($drafts as $d)
                <li class="cluster" style="justify-content:space-between;padding:.35rem 0;border-bottom:1px solid var(--line)">
                    <span><a href="{{ route('panou.medical.records.edit', $d) }}">{{ $d->patient->fullName() }}</a> <span class="small muted">· {{ $d->title }}</span></span>
                    <span class="small muted num">{{ $d->created_at->diffForHumans() }}</span>
                </li>
            @endforeach
        </ul>
    @endif
</x-panou.panel>
