<div class="grid grid--aside">
    <div class="stack">
        <x-panou.panel title="Identificare și contact">
            <dl class="facts">
                <dt>Nume</dt><dd>{{ $patient->fullName() }}</dd>
                <dt>Data nașterii</dt><dd>{{ $patient->birth_date?->format('d.m.Y') ?? '—' }}</dd>
                <dt>Sex</dt><dd>{{ \App\Modules\Patients\Models\Patient::SEXES[$patient->sex] ?? '—' }}</dd>
                <dt>CNP</dt>
                <dd>
                    @if ($patient->cnp)
                        @if (session('revealed_cnp'))
                            <span class="num">{{ session('revealed_cnp') }}</span> <span class="small muted">(afișare înregistrată în audit)</span>
                        @else
                            <span class="num">{{ $patient->maskedCnp() }}</span>
                            @can('patients.reveal_identifiers')
                                <form method="post" action="{{ route('panou.patients.reveal-cnp', $patient) }}" style="display:inline">
                                    @csrf <button class="link-btn small" type="submit">Afișează</button>
                                </form>
                            @endcan
                        @endif
                    @else
                        <span class="muted">necompletat</span>
                    @endif
                </dd>
                <dt>Cetățenie</dt><dd>{{ $patient->citizenship ?: '—' }}</dd>
                @if ($patient->foreign_id_type)
                    <dt>Document</dt><dd>{{ $patient->foreign_id_type }} (număr criptat)</dd>
                @endif
                <dt>Telefon</dt><dd>{{ $patient->phone ?? '—' }}</dd>
                <dt>E-mail</dt><dd>{{ $patient->email ?? '—' }}</dd>
                <dt>Adresă</dt><dd>{{ collect([$patient->address, $patient->city, $patient->county])->filter()->join(', ') ?: '—' }}</dd>
            </dl>
        </x-panou.panel>

        <x-panou.panel title="Reprezentanți legali" :flush="true">
            @if ($patient->representatives->isEmpty())
                <div class="panel__body"><p class="muted">Niciun reprezentant înregistrat.@if ($patient->isMinor()) Pacientul este minor: adăugați părintele sau tutorele.@endif</p></div>
            @else
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>Nume</th><th>Calitate</th><th>Contact</th><th>Document</th><th>Valabil</th><th></th></tr></thead>
                        <tbody>
                        @foreach ($patient->representatives as $rep)
                            <tr>
                                <td>{{ $rep->name }}</td>
                                <td>{{ $rep->relationshipLabel() }}</td>
                                <td class="small">{{ collect([$rep->phone, $rep->email])->filter()->join(' · ') ?: '—' }}</td>
                                <td class="small">{{ $rep->document_type ?? '—' }}</td>
                                <td>@if ($rep->isValid())<x-panou.badge tone="ok">da</x-panou.badge>@else<x-panou.badge>încheiat {{ $rep->valid_until->format('d.m.Y') }}</x-panou.badge>@endif</td>
                                <td class="text-right">
                                    @if ($rep->isValid())
                                        @can('patients.edit')
                                            <form method="post" action="{{ route('panou.patients.representatives.destroy', [$patient, $rep]) }}" data-confirm="Încheiați calitatea de reprezentant de azi?">
                                                @csrf @method('delete')<button class="btn btn--small" type="submit">Încheie</button>
                                            </form>
                                        @endcan
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            @can('patients.edit')
                <x-slot:footer>
                    <details>
                        <summary class="link-btn">Adaugă reprezentant</summary>
                        <form method="post" action="{{ route('panou.patients.representatives.store', $patient) }}" style="margin-top:1rem">
                            @csrf
                            <div class="form-grid form-grid--3">
                                <x-panou.field name="name" label="Nume complet" required />
                                <x-panou.field name="relationship" type="select" label="Calitate" :options="\App\Modules\Patients\Models\PatientRepresentative::RELATIONSHIPS" required />
                                <x-panou.field name="phone" type="tel" label="Telefon" />
                                <x-panou.field name="email" type="email" label="E-mail" />
                                <x-panou.field name="document_type" label="Act doveditor" hint="ex. certificat de naștere, hotărâre" />
                                <x-panou.field name="document_number" label="Număr act" />
                                <x-panou.field name="valid_from" type="date" label="Valabil de la" />
                                <x-panou.field name="valid_until" type="date" label="Valabil până la" />
                            </div>
                            <div class="form-actions"><button class="btn btn--primary" type="submit">Adaugă</button></div>
                        </form>
                    </details>
                </x-slot:footer>
            @endcan
        </x-panou.panel>
    </div>

    <div class="stack">
        <x-panou.panel title="Comunicare">
            <p class="small muted" style="margin-bottom:.4rem">Canale operaționale permise</p>
            <div class="cluster">
                @forelse ($patient->operationalChannels() as $ch)
                    <x-panou.badge tone="accent">{{ $ch }}</x-panou.badge>
                @empty
                    <span class="muted">niciunul</span>
                @endforelse
            </div>
            <hr class="sep">
            <p class="small muted" style="margin-bottom:.4rem">Marketing</p>
            @if ($patient->marketing_consent)
                <x-panou.badge tone="ok">acord</x-panou.badge>
            @else
                <x-panou.badge>fără acord</x-panou.badge>
            @endif
            @if ($patient->marketing_consent_at)
                <p class="small muted" style="margin-top:.4rem">{{ $patient->marketing_consent_at->format('d.m.Y H:i') }} · {{ $patient->marketing_consent_source }}</p>
            @endif
        </x-panou.panel>
        @if ($patient->admin_notes)
            <x-panou.panel title="Observații administrative"><p style="white-space:pre-line">{{ $patient->admin_notes }}</p></x-panou.panel>
        @endif
    </div>
</div>
