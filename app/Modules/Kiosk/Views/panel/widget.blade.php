<x-panou.panel title="Recenzii (ultimele 30 de zile)">
    <x-slot:actions><a class="btn btn--small" href="{{ route('panou.kiosk.reviews') }}">Toate recenziile</a></x-slot:actions>
    <div class="metrics" style="border-top:0">
        <div class="metric"><span class="metric__value num">{{ $average ? number_format($average, 2, ',', '') : '—' }}</span><span class="metric__label">Nota medie</span><span class="metric__help">din 5 stele</span></div>
        <div class="metric"><span class="metric__value num">{{ $count }}</span><span class="metric__label">Recenzii</span><span class="metric__help">de pe tabletă</span></div>
        <div class="metric"><span class="metric__value num">{{ $low }}</span><span class="metric__label">Note de 1–2 stele</span><span class="metric__help">merită un telefon</span></div>
    </div>
</x-panou.panel>
