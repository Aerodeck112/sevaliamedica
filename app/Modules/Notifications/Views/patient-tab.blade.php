@php use App\Modules\Notifications\Support\MessageType; @endphp
<x-panou.panel title="Mesaje trimise" :flush="true">
    <x-slot:actions>
        <a class="btn btn--small" href="{{ route('panou.notifications.deliveries', ['patient_id' => $patient->id]) }}">Jurnal complet</a>
    </x-slot:actions>
    <div class="panel__body" style="padding-bottom:0">
        <p class="small muted">Canale operaționale permise de pacient: {{ collect($patient->operationalChannels())->map(fn ($c) => $c === 'telefon' ? 'apel telefonic' : (MessageType::CHANNELS[$c] ?? $c))->join(', ') ?: 'niciunul' }}.
            Marketing: {{ $patient->marketing_consent ? 'consimțământ din '.$patient->marketing_consent_at?->format('d.m.Y') : 'fără consimțământ' }}.</p>
    </div>
    @if ($deliveries->isEmpty())
        <x-panou.empty title="Niciun mesaj">Mesajele de confirmare și remindere apar aici.</x-panou.empty>
    @else
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Creat</th><th>Tip</th><th>Canal</th><th>Stare</th><th>Detalii</th></tr></thead>
                <tbody>
                @foreach ($deliveries as $d)
                    <tr>
                        <td class="num"><a class="row-link" href="{{ route('panou.notifications.deliveries.show', $d) }}">{{ $d->created_at->format('d.m.Y H:i') }}</a></td>
                        <td>{{ MessageType::label($d->type) }}</td>
                        <td>{{ MessageType::CHANNELS[$d->channel] ?? '—' }}</td>
                        <td>@include('notifications::partials.status', ['status' => $d->status])</td>
                        <td class="small">{{ $d->last_error ?? $d->skip_reason }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-panou.panel>
