<x-panou.panel title="Recuperare — ședințele mele">
    <x-slot:actions><a class="btn btn--small" href="{{ route('panou.rehab.sessions.index') }}">Toate ședințele</a></x-slot:actions>
    @if (! $staff)
        <x-panou.empty title="Contul nu este legat de o persoană din personal">Administratorul vă poate asocia contul în Administrare → Personal.</x-panou.empty>
    @else
        <div class="grid grid--2" style="gap:1rem">
            <div>
                <p class="small muted" style="margin-bottom:.4rem">Azi</p>
                @forelse ($today as $a)
                    <div class="cluster" style="justify-content:space-between;padding:.35rem 0;border-bottom:1px solid var(--line)">
                        <span class="num">{{ $a->starts_at->format('H:i') }} · {{ $a->patient?->fullName() }} <span class="small muted">{{ $a->service?->name }}</span></span>
                        @if (isset($done[$a->id]))<x-panou.badge tone="ok">efectuată</x-panou.badge>
                        @elseif ($a->starts_at->lte(now()->addMinutes(15)))<a class="btn btn--small btn--primary" href="{{ route('panou.rehab.sessions.create', $a) }}">Marchează</a>
                        @else<span class="small muted">programată</span>@endif
                    </div>
                @empty
                    <p class="small">Nicio ședință din planuri azi.</p>
                @endforelse
            </div>
            <div>
                <p class="small muted" style="margin-bottom:.4rem">De închis ({{ $pending->count() }})</p>
                @forelse ($pending->take(6) as $a)
                    <div class="cluster" style="justify-content:space-between;padding:.35rem 0;border-bottom:1px solid var(--line)">
                        <span class="num small">{{ $a->starts_at->format('d.m H:i') }} · {{ $a->patient?->fullName() }}</span>
                        <a class="btn btn--small" href="{{ route('panou.rehab.sessions.create', $a) }}">Clarifică</a>
                    </div>
                @empty
                    <p class="small">Nimic de închis.</p>
                @endforelse
                @if ($plans->isNotEmpty())
                    <p class="small muted" style="margin:1rem 0 .4rem">Planuri active în care sunteți terapeut</p>
                    <ul class="small" style="margin:0;padding-left:1rem">
                        @foreach ($plans as $plan)<li><a href="{{ route('panou.rehab.plans.show', $plan) }}">{{ $plan->patient->fullName() }}</a> · {{ $plan->items->pluck('service.name')->join(', ') }}</li>@endforeach
                    </ul>
                @endif
            </div>
        </div>
    @endif
</x-panou.panel>
