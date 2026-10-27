<x-panou.panel title="Audit SEO automat">
    <x-slot:actions><a class="btn btn--small" href="{{ route('panou.marketing.seo') }}">Raportul complet</a></x-slot:actions>
    @if (! $run)
        <p class="muted">Primul audit rulează în noaptea aceasta (sau porniți-l din „Audit SEO”).</p>
    @else
        <div class="metrics" style="border-top:0">
            <div class="metric">
                <span class="metric__value num">{{ $run->score }}</span><span class="metric__label">Scor SEO</span>
                @php $d = $previous ? $run->score - $previous->score : null; @endphp
                <span class="metric__help">{{ $d === null ? 'din 100' : ($d === 0 ? 'neschimbat' : ($d > 0 ? '+'.$d : $d)).' față de auditul anterior' }}</span>
            </div>
            <div class="metric"><span class="metric__value num">{{ $run->errors_count }}</span><span class="metric__label">Erori</span><span class="metric__help">{{ $run->finished_at->diffForHumans() }}</span></div>
            <div class="metric"><span class="metric__value num">{{ $run->warnings_count }}</span><span class="metric__label">Avertismente</span><span class="metric__help">{{ $run->pages_total }} pagini</span></div>
        </div>
    @endif
</x-panou.panel>
