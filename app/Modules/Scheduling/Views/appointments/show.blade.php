@extends('layouts.panou')
@section('title', 'Programare #'.$appointment->id)
@section('content')
    @php use App\Modules\Scheduling\Support\AppointmentStatus; $a = $appointment; @endphp
    <x-panou.page-head :title="$a->displayName()"
        :crumbs="['Calendar' => route('panou.calendar', ['date' => $a->starts_at?->toDateString(), 'location_id' => $a->location_id]), 'Programare #'.$a->id => null]"
        :sub="($a->starts_at ? $a->starts_at->locale('ro')->isoFormat('dddd, D MMMM YYYY, HH:mm').'–'.$a->ends_at->format('H:i') : 'fără interval').' · '.($a->service?->name ?? 'serviciu nespecificat')">
        <x-slot:actions>
            @if ($a->patient)
                <a class="btn" href="{{ route('panou.patients.show', $a->patient) }}"><x-panou.icon name="user" />Fișa pacientului</a>
            @endif
        </x-slot:actions>
    </x-panou.page-head>

    @if ($newLink)
        <div class="alert alert--info" role="status"><x-panou.icon name="link" />
            <div><strong>Link pentru pacient</strong> (afișat o singură dată; trimiteți-l prin canalul agreat de pacient):<br><code style="word-break:break-all">{{ $newLink }}</code></div>
        </div>
    @endif
    @if ($a->reschedule_requested_at && $a->status === AppointmentStatus::CONFIRMATA)
        <div class="alert alert--warn"><x-panou.icon name="warn" /><div>Pacientul a cerut reprogramarea pe {{ $a->reschedule_requested_at->format('d.m.Y H:i') }}. Vedeți mesajul în istoric și mutați programarea.</div></div>
    @endif
    @foreach ($annotations ?? [] as $note)
        <div class="alert alert--{{ ($note['tone'] ?? 'warn') === 'danger' ? 'danger' : 'warn' }}" role="note"><x-panou.icon name="warn" /><div><strong>{{ $note['label'] }}</strong>@if ($note['detail']) — {{ $note['detail'] }}@endif</div></div>
    @endforeach

    <div class="grid grid--aside">
        <div class="stack">
            <x-panou.panel title="Detalii">
                <dl class="facts">
                    <dt>Status</dt><dd>@include('scheduling::partials.status', ['status' => $a->status])</dd>
                    <dt>Pacient</dt>
                    <dd>
                        @if ($a->patient)
                            <a href="{{ route('panou.patients.show', $a->patient) }}">{{ $a->patient->fullName() }}</a>
                        @else
                            <x-panou.badge tone="warn">neidentificat</x-panou.badge>
                            @if ($canManage)
                                <span class="small"> · <a href="{{ route('panou.requests.index') }}">identificați pacientul din „Solicitări”</a></span>
                            @endif
                        @endif
                    </dd>
                    <dt>Serviciu</dt><dd>{{ $a->service?->name ?? '—' }}</dd>
                    <dt>Persoana</dt><dd>{{ $a->staffMember?->display_name ?? '—' }}</dd>
                    <dt>Locație</dt><dd>{{ $a->location?->name ?? '—' }}</dd>
                    <dt>Resurse rezervate</dt>
                    <dd>
                        @forelse ($a->activeResources as $r)
                            <div>{{ \App\Modules\Scheduling\Models\AppointmentResource::TYPES[$r->resource_type] }}: {{ $r->resourceName() }}</div>
                        @empty
                            <span class="muted">niciuna (eliberate sau neprogramată)</span>
                        @endforelse
                    </dd>
                    <dt>Sursa de plată estimată</dt><dd>{{ \App\Modules\Scheduling\Models\Appointment::PAYER_TYPES[$a->payer_type] ?? '—' }}</dd>
                    <dt>Canal</dt><dd>{{ \App\Modules\Scheduling\Models\Appointment::SOURCES[$a->source] ?? $a->source }}</dd>
                    @if ($a->series)
                        <dt>Serie</dt><dd><a href="{{ route('panou.series.show', $a->series) }}">Ședința {{ $a->series_index }} din {{ $a->series->planned_count }}</a></dd>
                    @endif
                    @if ($a->notes)<dt>Observații</dt><dd style="white-space:pre-line;font-weight:400">{{ $a->notes }}</dd>@endif
                    @if ($a->cancel_reason)<dt>Motiv</dt><dd>{{ $a->cancel_reason }}</dd>@endif
                </dl>
            </x-panou.panel>

            @if ($transitions->isNotEmpty())
                <x-panou.panel title="Acțiuni">
                    <div class="cluster" style="align-items:flex-start">
                        @foreach ($transitions as $to)
                            @php $needsReason = AppointmentStatus::requiresReason($a->status, $to); @endphp
                            @if ($needsReason)
                                <details>
                                    <summary class="btn {{ in_array($to, ['anulata', 'neprezentat']) ? 'btn--danger' : '' }}">{{ AppointmentStatus::actionLabel($a->status, $to) }}</summary>
                                    <form method="post" action="{{ route('panou.appointments.status', $a) }}" class="stack" style="margin-top:.6rem;min-width:280px">
                                        @csrf <input type="hidden" name="to" value="{{ $to }}">
                                        <x-panou.field name="reason" :id="'reason_'.$to" label="Motiv" required />
                                        <button class="btn {{ in_array($to, ['anulata', 'neprezentat']) ? 'btn--danger' : 'btn--primary' }}" type="submit">Confirmă: {{ AppointmentStatus::actionLabel($a->status, $to) }}</button>
                                    </form>
                                </details>
                            @else
                                <form method="post" action="{{ route('panou.appointments.status', $a) }}">
                                    @csrf <input type="hidden" name="to" value="{{ $to }}">
                                    <button class="btn btn--primary" type="submit">{{ AppointmentStatus::actionLabel($a->status, $to) }}</button>
                                </form>
                            @endif
                        @endforeach
                    </div>
                    <p class="small muted" style="margin-top:.75rem">Statusul nu se schimbă automat când trece ora programării.</p>
                </x-panou.panel>
            @endif

            @if ($canManage && in_array($a->status, ['confirmata', 'sosit'], true))
                <x-panou.panel title="Mută programarea" id="muta">
                    <form method="post" action="{{ route('panou.appointments.move', $a) }}" class="stack" data-module="slot-fill">
                        @csrf
                        <input type="hidden" name="version" value="{{ $a->version }}">
                        <div class="form-grid form-grid--3">
                            <x-panou.field name="date" type="date" label="Data" :value="$a->starts_at->toDateString()" required />
                            <x-panou.field name="time" type="time" label="Ora" :value="$a->starts_at->format('H:i')" required />
                            <x-panou.field name="duration" type="number" label="Durată (minute)" :value="$a->durationMinutes()" />
                            <x-panou.field name="staff_member_id" type="select" label="Persoana" :value="$a->staff_member_id" :options="$staffOptions" placeholder="— aceeași —" />
                            <x-panou.field name="reason" label="Motivul modificării" required full />
                        </div>
                        @if ($suggestions)
                            <div>
                                <p class="small muted" style="margin-bottom:.4rem">Intervale libere (clic pentru a completa):</p>
                                <ul class="slot-list">
                                    @foreach ($suggestions as $s)
                                        <li><button type="button" class="btn btn--small" data-fill-slot data-date="{{ $s['start']->format('Y-m-d') }}" data-time="{{ $s['start']->format('H:i') }}" data-staff="{{ $s['staff_member_id'] }}">{{ $s['start']->locale('ro')->isoFormat('ddd D MMM HH:mm') }} · {{ $s['staff'] }}</button></li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                        @can('scheduling.override')
                            <x-panou.field name="override" type="checkbox" label="Forțează în afara programului de lucru (motivul de mai sus se înregistrează)" />
                        @endcan
                        <div><button class="btn btn--primary" type="submit">Mută</button></div>
                    </form>
                </x-panou.panel>
            @endif

            @if ($waitlistMatches->isNotEmpty())
                <x-panou.panel title="Lista de așteptare — pacienți compatibili cu intervalul eliberat" :flush="true">
                    <div class="table-wrap">
                        <table class="table">
                            <thead><tr><th>Pacient</th><th>Prioritate</th><th>Preferințe</th><th>Telefon</th><th></th></tr></thead>
                            <tbody>
                            @foreach ($waitlistMatches as $e)
                                <tr>
                                    <td>{{ $e->patient->fullName() }}</td>
                                    <td>{{ \App\Modules\Scheduling\Models\WaitlistEntry::PRIORITIES[$e->priority] }}</td>
                                    <td class="small">{{ $e->preferenceSummary() }}</td>
                                    <td class="num">{{ $e->patient->phone }}</td>
                                    <td class="text-right">
                                        <form method="post" action="{{ route('panou.waitlist.offer', $e) }}">
                                            @csrf
                                            <input type="hidden" name="freed_appointment_id" value="{{ $a->id }}">
                                            <input type="hidden" name="slot_start" value="{{ $a->starts_at->format('Y-m-d H:i') }}">
                                            <input type="hidden" name="slot_end" value="{{ $a->ends_at->format('Y-m-d H:i') }}">
                                            <input type="hidden" name="staff_member_id" value="{{ $a->staff_member_id }}">
                                            <input type="hidden" name="location_id" value="{{ $a->location_id }}">
                                            <button class="btn btn--small" type="submit">Oferă intervalul</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-panou.panel>
            @endif

            <x-panou.panel title="Servicii efectuate" :flush="true">
                @if ($a->serviceRecords->isEmpty())
                    <div class="panel__body"><p class="muted">Niciun serviciu înregistrat. Serviciul efectuat se înregistrează explicit, nu automat din programare.</p></div>
                @else
                    @include('scheduling::partials.service-records', ['records' => $a->serviceRecords])
                @endif
                @if ($canRecord && $a->patient_id && in_array($a->status, \App\Modules\Scheduling\Services\ServiceRecordService::RECORDABLE_STATUSES, true))
                    <x-slot:footer>
                        <form method="post" action="{{ route('panou.service-records.from-appointment', $a) }}">
                            @csrf
                            <div class="form-grid form-grid--3">
                                <x-panou.field name="service_id" type="select" label="Serviciu efectuat" :value="$a->service_id" :options="$services" required :placeholder="false" />
                                <x-panou.field name="staff_member_id" :id="'sr_staff'" type="select" label="Efectuat de" :value="$a->staff_member_id" :options="$staffOptions" required :placeholder="false" />
                                <x-panou.field name="payer_type" :id="'sr_payer'" type="select" label="Sursa de plată" :value="$a->payer_type ?? 'privat'" :options="\App\Modules\Scheduling\Models\ServiceRecord::PAYER_TYPES" required :placeholder="false" />
                                <x-panou.field name="insurer_name" label="Asigurător (dacă e cazul)" />
                                <x-panou.field name="quantity" type="number" label="Cantitate" value="1" required />
                                <x-panou.field name="notes" :id="'sr_notes'" label="Observații administrative" />
                            </div>
                            <div class="form-actions"><button class="btn btn--primary" type="submit"><x-panou.icon name="check" />Înregistrează serviciul efectuat</button></div>
                        </form>
                    </x-slot:footer>
                @endif
            </x-panou.panel>
        </div>

        <div class="stack">
            @if ($canManage && $a->status === 'confirmata' && $a->starts_at?->isFuture())
                <x-panou.panel title="Link pentru pacient">
                    <p class="small muted">Linkul permite pacientului să anuleze sau să ceară reprogramarea. Nu conține date medicale.</p>
                    <form method="post" action="{{ route('panou.appointments.link', $a) }}">@csrf<button class="btn btn--small" type="submit"><x-panou.icon name="link" />Generează link</button></form>
                </x-panou.panel>
            @endif
            <x-panou.panel title="Istoric">
                <ol class="timeline small">
                    @foreach ($a->events as $ev)
                        <li>
                            <strong>{{ $ev->typeLabel() }}</strong>
                            @if ($ev->to_status && $ev->type === 'status') : {{ AppointmentStatus::label($ev->from_status ?? '') }} → {{ AppointmentStatus::label($ev->to_status) }}@endif
                            <div class="muted">{{ $ev->created_at->format('d.m.Y H:i') }} · {{ $ev->user?->name ?? ($ev->type === 'anulata_pacient' || $ev->type === 'reprogramare_ceruta' ? 'pacient (link)' : 'sistem') }}</div>
                            @if ($ev->reason)<div>Motiv: {{ $ev->reason }}</div>@endif
                            @if (! empty($ev->changes['inainte']))
                                <div class="muted">{{ $ev->changes['inainte']['inceput'] ?? '' }} → {{ $ev->changes['dupa']['inceput'] ?? '' }}</div>
                            @endif
                            @foreach ($ev->changes['avertizari'] ?? [] as $w)<div class="muted">⚠ {{ $w }}</div>@endforeach
                        </li>
                    @endforeach
                </ol>
            </x-panou.panel>
        </div>
    </div>
@endsection
