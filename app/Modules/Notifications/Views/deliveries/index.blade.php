@extends('layouts.panou')
@section('title', 'Notificări')
@section('content')
    @php use App\Modules\Notifications\Models\NotificationDelivery as D; use App\Modules\Notifications\Support\MessageType; @endphp
    <x-panou.page-head title="Notificări" sub="Jurnalul mesajelor către pacienți: ce s-a trimis, ce a eșuat și de ce. Mesajele nu conțin date medicale." />

    <x-panou.panel :flush="true">
        <div class="metrics" style="border-top:0">
            @foreach ([D::SENT, D::SIMULATED, D::PENDING, D::FAILED, D::FAILED_FINAL, D::UNKNOWN] as $s)
                <a class="metric" style="text-decoration:none;color:inherit" href="{{ route('panou.notifications.deliveries', ['status' => $s]) }}">
                    <span class="metric__value">{{ $counts[$s] ?? 0 }}</span>
                    <span class="metric__label">{{ D::LABELS[$s] }}</span>
                    <span class="metric__help">ultimele 7 zile</span>
                </a>
            @endforeach
        </div>
        <form class="filters" method="get" data-autosubmit>
            <x-panou.field name="status" type="select" label="Stare" :value="$filters['status'] ?? null" :options="D::LABELS" placeholder="Toate" />
            <x-panou.field name="type" type="select" label="Tip" :value="$filters['type'] ?? null" :options="collect(MessageType::all())->map(fn ($t) => $t['label'])->all()" placeholder="Toate" />
            <x-panou.field name="channel" type="select" label="Canal" :value="$filters['channel'] ?? null" :options="MessageType::CHANNELS + ['niciunul' => 'Fără canal']" placeholder="Toate" />
            <x-panou.field name="from" type="date" label="De la" :value="$filters['from'] ?? null" />
            <x-panou.field name="to" type="date" label="Până la" :value="$filters['to'] ?? null" />
            <noscript><button class="btn" type="submit">Filtrează</button></noscript>
        </form>
        @if ($deliveries->isEmpty())
            <x-panou.empty title="Nicio livrare">Mesajele apar aici când se confirmă, se mută sau se anulează programări.</x-panou.empty>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Creat</th><th>Tip</th><th>Canal</th><th>Destinatar</th><th>Pacient</th><th>Stare</th><th>Încercări</th><th>Detalii</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($deliveries as $d)
                        <tr>
                            <td class="num"><a class="row-link" href="{{ route('panou.notifications.deliveries.show', $d) }}">{{ $d->created_at->format('d.m H:i') }}</a></td>
                            <td>{{ MessageType::label($d->type) }}</td>
                            <td>{{ MessageType::CHANNELS[$d->channel] ?? '—' }}</td>
                            <td class="num">{{ $d->recipient_masked }}</td>
                            <td>@if ($d->patient)<a href="{{ route('panou.patients.show', $d->patient) }}">{{ $d->patient->fullName() }}</a>@else<span class="muted">{{ $d->appointment ? 'solicitare' : '—' }}</span>@endif</td>
                            <td>@include('notifications::partials.status', ['status' => $d->status])</td>
                            <td class="num">{{ $d->attempts }}</td>
                            <td class="small">{{ $d->last_error ?? $d->skip_reason }}@if ($d->status === D::FAILED && $d->next_attempt_at)<br><span class="muted">reîncercare {{ $d->next_attempt_at->format('d.m H:i') }}</span>@endif</td>
                            <td class="text-right">
                                @if ($d->canRetry())
                                    @can('notifications.retry')
                                        <form method="post" action="{{ route('panou.notifications.deliveries.retry', $d) }}" @if ($d->status === D::UNKNOWN) data-confirm="Rezultatul trimiterii anterioare nu este cunoscut; pacientul ar putea primi mesajul de două ori. Reîncercați?" @endif>@csrf<button class="btn btn--small" type="submit"><x-panou.icon name="refresh" />Reîncearcă</button></form>
                                    @endcan
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            {{ $deliveries->links() }}
        @endif
    </x-panou.panel>
@endsection
