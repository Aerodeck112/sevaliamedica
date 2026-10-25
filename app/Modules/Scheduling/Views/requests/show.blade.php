@extends('layouts.panou')
@section('title', 'Solicitare '.$req->request_reference)
@section('content')
    <x-panou.page-head :title="'Solicitare '.($req->request_reference ?? '#'.$req->id)" :crumbs="['Solicitări' => route('panou.requests.index'), ($req->request_reference ?? '#'.$req->id) => null]"
        :sub="'Primită '.$req->created_at->format('d.m.Y H:i').' · '.\App\Modules\Scheduling\Models\Appointment::SOURCES[$req->source]" />

    <div class="grid grid--aside">
        <div class="stack">
            <x-panou.panel title="1. Identificați pacientul">
                @if ($req->patient)
                    <div class="picked"><x-panou.icon name="check" /><strong>{{ $req->patient->fullName() }}</strong>
                        <span class="muted small">{{ $req->patient->birth_date?->format('d.m.Y') }} · {{ $req->patient->phone }}</span>
                        <a class="btn btn--small" style="margin-left:auto" href="{{ route('panou.patients.show', $req->patient) }}">Fișa</a></div>
                @else
                    @if ($candidates->isNotEmpty())
                        <p class="small muted">Fișe posibile (după telefon și nume). Verificați identitatea înainte de a alege:</p>
                        <ul class="stack" style="list-style:none;padding:0">
                            @foreach ($candidates as $c)
                                <li class="cluster">
                                    <form method="post" action="{{ route('panou.requests.attach', $req) }}">@csrf<input type="hidden" name="patient_id" value="{{ $c->id }}"><button class="btn btn--small" type="submit">Alege</button></form>
                                    <span><strong>{{ $c->fullName() }}</strong> <span class="muted small">#{{ $c->id }} · {{ $c->birth_date?->format('d.m.Y') ?? 'fără dată' }} · {{ $c->phone ?? 'fără telefon' }}</span></span>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="muted">Nicio fișă asemănătoare găsită automat.</p>
                    @endif
                    <hr class="sep">
                    <div class="cluster">
                        <form method="get" action="{{ route('panou.patients.index') }}" class="cluster" style="align-items:flex-end">
                            <x-panou.field name="q" type="search" label="Caută altă fișă" :value="$contact['name'] ?? ''" />
                            <button class="btn" type="submit">Caută</button>
                        </form>
                        @can('patients.create')
                            <a class="btn btn--primary" href="{{ route('panou.patients.create', ['return_to' => route('panou.requests.show', $req, false)]) }}">Creează fișă nouă</a>
                        @endcan
                    </div>
                    @if (request('patient_id'))
                        <form method="post" action="{{ route('panou.requests.attach', $req) }}" style="margin-top:1rem">
                            @csrf <input type="hidden" name="patient_id" value="{{ request('patient_id') }}">
                            <button class="btn btn--primary" type="submit">Asociază fișa nou creată (#{{ request('patient_id') }})</button>
                        </form>
                    @endif
                @endif
            </x-panou.panel>

            @if ($req->status === 'solicitare')
                <x-panou.panel title="2. Alegeți intervalul și confirmați">
                    <form method="post" action="{{ route('panou.requests.confirm', $req) }}" class="stack" data-module="slot-fill">
                        @csrf
                        <div class="form-grid form-grid--3">
                            <div class="field">
                                <label class="field__label" for="rq_service">Serviciu<span class="req">*</span></label>
                                <select id="rq_service" name="service_id" required>
                                    @foreach ($services as $s)<option value="{{ $s->id }}" @selected((int) old('service_id', $defaultServiceId) === $s->id)>{{ $s->name }}</option>@endforeach
                                </select>
                            </div>
                            <div class="field">
                                <label class="field__label" for="rq_staff">Persoana</label>
                                <select id="rq_staff" name="staff_member_id">
                                    <option value="">— alegeți —</option>
                                    @foreach ($staff as $s)<option value="{{ $s->id }}" @selected((int) old('staff_member_id', $req->staff_member_id) === $s->id)>{{ $s->display_name }}</option>@endforeach
                                </select>
                            </div>
                            <x-panou.field name="location_id" type="select" label="Locație" :value="$defaultLocationId" :options="$locations" required :placeholder="false" />
                            <x-panou.field name="date" type="date" label="Data" :value="$req->request_preferred_start?->toDateString()" required />
                            <x-panou.field name="time" type="time" label="Ora" :value="$req->request_preferred_start?->format('H:i')" required />
                            <x-panou.field name="payer_type" type="select" label="Sursa de plată estimată" :options="\App\Modules\Scheduling\Models\Appointment::PAYER_TYPES" placeholder="— necunoscută —" />
                        </div>
                        @if ($suggestions)
                            <div>
                                <p class="small muted" style="margin-bottom:.4rem">Intervale libere:</p>
                                <ul class="slot-list">
                                    @foreach ($suggestions as $s)
                                        <li><button type="button" class="btn btn--small" data-fill-slot data-date="{{ $s['start']->format('Y-m-d') }}" data-time="{{ $s['start']->format('H:i') }}" data-staff="{{ $s['staff_member_id'] }}">{{ $s['start']->locale('ro')->isoFormat('ddd D MMM HH:mm') }} · {{ $s['staff'] }}</button></li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                        <div>
                            <button class="btn btn--primary" type="submit" @disabled(! $req->patient)>Confirmă programarea</button>
                            @unless ($req->patient)<span class="small muted"> · identificați întâi pacientul</span>@endunless
                        </div>
                    </form>
                </x-panou.panel>
                <x-panou.panel title="Închide solicitarea fără programare">
                    <form method="post" action="{{ route('panou.appointments.status', $req) }}" class="cluster" style="align-items:flex-end">
                        @csrf <input type="hidden" name="to" value="anulata">
                        <x-panou.field name="reason" label="Motiv" required placeholder="ex. nu a răspuns la 3 apeluri" />
                        <button class="btn btn--danger" type="submit">Închide</button>
                    </form>
                </x-panou.panel>
            @endif
        </div>

        <x-panou.panel title="Date din formular">
            <dl class="facts">
                <dt>Nume</dt><dd>{{ $contact['name'] ?? '—' }}</dd>
                <dt>Telefon</dt><dd>{{ $contact['phone'] ?? '—' }}</dd>
                <dt>E-mail</dt><dd>{{ $contact['email'] ?? '—' }}</dd>
                <dt>Preferă</dt><dd>{{ $contact['preferred_contact'] ?? '—' }}</dd>
                @if (! empty($contact['birth_date']))<dt>Data nașterii</dt><dd>{{ $contact['birth_date'] }}</dd>@endif
                <dt>Specialitate</dt><dd>{{ $req->requestSpecialty?->name ?? '—' }}</dd>
                <dt>Medic dorit</dt><dd>{{ $req->staffMember?->display_name ?? '—' }}</dd>
                <dt>Acord</dt><dd>{{ isset($contact['consent_at']) ? \Carbon\Carbon::parse($contact['consent_at'])->format('d.m.Y H:i') : '—' }}</dd>
            </dl>
            @if (! empty($contact['message']))
                <hr class="sep"><p class="small muted">Mesaj</p><p style="white-space:pre-line">{{ $contact['message'] }}</p>
            @endif
            <p class="small muted" style="margin-top:1rem">Datele sunt păstrate criptat. Consultarea acestei pagini este înregistrată în audit.</p>
        </x-panou.panel>
    </div>
@endsection
