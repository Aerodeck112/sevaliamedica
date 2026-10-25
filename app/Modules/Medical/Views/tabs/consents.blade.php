<div class="grid grid--aside">
    <x-panou.panel title="Consimțăminte" :flush="true">
        @if ($consents->isEmpty())
            <x-panou.empty title="Niciun consimțământ consemnat">Consimțămintele se consemnează pe versiunea exactă a textului clinicii.</x-panou.empty>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Data</th><th>Model</th><th>Mod</th><th>Consemnat de</th><th>Stare</th></tr></thead>
                    <tbody>
                    @foreach ($consents as $c)
                        <tr>
                            <td class="num">{{ $c->given_at->format('d.m.Y H:i') }}</td>
                            <td><a class="row-link" href="{{ route('panou.medical.consents.show', $c) }}">{{ $c->templateVersion->template->name }}</a> <span class="small muted">v{{ $c->templateVersion->version }}</span>
                                @if ($c->given_by === 'reprezentant')<div class="small">dat de reprezentant: {{ $c->representative_name }}</div>@endif</td>
                            <td class="small">{{ \App\Modules\Medical\Models\PatientConsent::METHODS[$c->method] ?? $c->method }}</td>
                            <td>{{ $c->collector?->name }}</td>
                            <td>
                                @if ($c->isActive())
                                    <x-panou.badge tone="ok">valabil</x-panou.badge>
                                    @can('medical.consents')
                                        <details>
                                            <summary class="small">Consemnează retragerea</summary>
                                            <form method="post" action="{{ route('panou.medical.consents.withdraw', $c) }}" class="stack" style="margin-top:.4rem">
                                                @csrf
                                                <input type="text" name="reason" required minlength="5" placeholder="Motiv / declarația pacientului" aria-label="Motivul retragerii">
                                                <button class="btn btn--small" type="submit">Retras</button>
                                            </form>
                                        </details>
                                    @endcan
                                @else
                                    <x-panou.badge>retras {{ $c->withdrawn_at->format('d.m.Y') }}</x-panou.badge>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-panou.panel>

    @can('medical.consents')
        <x-panou.panel title="Consemnează un consimțământ">
            @if ($templates->isEmpty())
                <p>Nu există modele de consimțământ cu text publicat. Textele le furnizează clinica (Medical → Modele consimțământ).</p>
            @else
                <form method="post" action="{{ route('panou.medical.consents.store', $patient) }}" class="stack">
                    @csrf
                    <x-panou.field name="consent_template_version_id" type="select" label="Model (versiunea curentă)" required
                        :options="$templates->mapWithKeys(fn ($t) => [$t->latestVersion->id => $t->name.' · v'.$t->latestVersion->version])->all()" />
                    <x-panou.field name="method" type="select" label="Mod" :options="\App\Modules\Medical\Models\PatientConsent::METHODS" :placeholder="false" />
                    <x-panou.field name="medical_document_id" type="select" label="Formularul semnat, scanat" placeholder="— necesar pentru „pe hârtie” —"
                        :options="$documents->mapWithKeys(fn ($d) => [$d->id => $d->title.' ('.$d->created_at->format('d.m.Y').')'])->all()"
                        hint="Încărcați scanarea în tabul Documente, categoria „Consimțământ semnat”." />
                    <x-panou.field name="given_by" type="select" label="Exprimat de" :options="\App\Modules\Medical\Models\PatientConsent::GIVEN_BY" :placeholder="false" />
                    <x-panou.field name="representative_name" label="Numele reprezentantului (dacă e cazul)" />
                    <x-panou.field name="given_at" type="datetime-local" label="Data și ora" :value="now()->format('Y-m-d\TH:i')" required />
                    <x-panou.field name="notes" type="textarea" label="Observații" />
                    <button class="btn btn--primary" type="submit">Consemnează</button>
                </form>
            @endif
        </x-panou.panel>
    @endcan
</div>
