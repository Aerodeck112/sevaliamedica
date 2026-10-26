<x-panou.panel title="Notificări de verificat">
    <x-slot:actions>
        <a class="btn btn--small" href="{{ route('panou.notifications.deliveries') }}"><x-panou.icon name="bell" />Jurnal</a>
    </x-slot:actions>
    <div class="metrics" style="border-top:0;margin:-1.25rem -1.25rem 1rem;border-bottom:1px solid var(--line)">
        <a class="metric" style="text-decoration:none;color:inherit" href="{{ route('panou.notifications.deliveries', ['status' => 'esuat_definitiv']) }}"><span class="metric__value" @if ($count) style="color:var(--danger)" @endif>{{ $count }}</span><span class="metric__label">De rezolvat</span><span class="metric__help">eșuate definitiv sau cu rezultat necunoscut, ultimele 7 zile</span></a>
        <div class="metric"><span class="metric__value">{{ $retrying }}</span><span class="metric__label">În reîncercare</span><span class="metric__help">pleacă automat</span></div>
        <div class="metric"><span class="metric__value">{{ $pending }}</span><span class="metric__label">În așteptare</span><span class="metric__help">programate (ex. după orele de liniște)</span></div>
    </div>
    @if ($latest->isEmpty())
        <p class="muted small" style="margin:0">Nicio problemă de livrare.</p>
    @else
        <ul class="small" style="margin:0;padding-left:1rem">
            @foreach ($latest as $d)
                <li><a href="{{ route('panou.notifications.deliveries.show', $d) }}">{{ $d->created_at->format('d.m H:i') }} · {{ \App\Modules\Notifications\Support\MessageType::label($d->type) }} · {{ $d->patient?->fullName() ?? 'solicitare' }}</a> — {{ $d->last_error }}</li>
            @endforeach
        </ul>
    @endif
</x-panou.panel>
