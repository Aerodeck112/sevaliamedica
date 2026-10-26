@extends('layouts.panou')
@section('title', 'Livrare notificare')
@section('content')
    @php use App\Modules\Notifications\Support\MessageType; $p = $delivery->payload ?? []; @endphp
    <x-panou.page-head :title="MessageType::label($delivery->type).' — '.(MessageType::CHANNELS[$delivery->channel] ?? 'fără canal')"
        :crumbs="['Notificări' => route('panou.notifications.deliveries'), 'Livrare #'.$delivery->id => null]">
        <x-slot:actions>
            @if ($delivery->canRetry())
                @can('notifications.retry')
                    <form method="post" action="{{ route('panou.notifications.deliveries.retry', $delivery) }}">@csrf<button class="btn btn--primary" type="submit"><x-panou.icon name="refresh" />Reîncearcă</button></form>
                @endcan
            @endif
        </x-slot:actions>
    </x-panou.page-head>
    <div class="grid grid--aside">
        <x-panou.panel title="Mesaj">
            @if ($p)
                @if (! empty($p['subject']))<p><strong>{{ $p['subject'] }}</strong></p>@endif
                <p style="white-space:pre-line">{{ $p['body'] ?? '' }}</p>
                @if (! empty($p['provider_template']))
                    <p class="small muted">Șablon WhatsApp aprobat: <code>{{ $p['provider_template'] }}</code> · parametri: {{ implode(' | ', $p['provider_params'] ?? []) }}</p>
                @endif
            @else
                <p class="muted">Nu a fost generat niciun mesaj: {{ $delivery->skip_reason }}</p>
            @endif
        </x-panou.panel>
        <x-panou.panel title="Stare">
            <dl class="facts">
                <dt>Stare</dt><dd>@include('notifications::partials.status', ['status' => $delivery->status])</dd>
                <dt>Destinatar</dt><dd class="num">{{ $delivery->recipient_masked }}</dd>
                <dt>Pacient</dt><dd>{{ $delivery->patient?->fullName() ?? '—' }}</dd>
                <dt>Programare</dt><dd>@if ($delivery->appointment)<a href="{{ route('panou.appointments.show', $delivery->appointment) }}">{{ $delivery->appointment->starts_at?->format('d.m.Y H:i') ?? 'solicitare' }}</a>@else—@endif</dd>
                <dt>Categorie</dt><dd>{{ $delivery->category === 'marketing' ? 'marketing' : 'operațional' }}</dd>
                <dt>Programat pentru</dt><dd class="num">{{ $delivery->scheduled_for?->format('d.m.Y H:i') ?? '—' }}</dd>
                <dt>Trimis</dt><dd class="num">{{ $delivery->sent_at?->format('d.m.Y H:i:s') ?? '—' }}</dd>
                <dt>Încercări</dt><dd class="num">{{ $delivery->attempts }}</dd>
                @if ($delivery->last_error)<dt>Ultima eroare</dt><dd>{{ $delivery->last_error }}</dd>@endif
                @if ($delivery->skip_reason)<dt>Observație</dt><dd>{{ $delivery->skip_reason }}</dd>@endif
                @if ($delivery->provider_message_id)<dt>ID furnizor</dt><dd><code>{{ $delivery->provider_message_id }}</code></dd>@endif
                <dt>Șablon</dt><dd>{{ $delivery->template ? 'versiunea '.$delivery->template->version : '—' }}</dd>
                @if ($delivery->creator)<dt>Inițiat de</dt><dd>{{ $delivery->creator->name }}</dd>@endif
            </dl>
        </x-panou.panel>
    </div>
@endsection
