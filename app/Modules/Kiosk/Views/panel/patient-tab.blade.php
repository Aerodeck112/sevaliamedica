@php $kinds = ['consimtamant' => 'Consimțământ', 'recenzie' => 'Recenzie']; @endphp
@if (session('kiosk_code'))
    <div class="alert alert--info" role="status">
        <x-panou.icon name="send" />
        <div>
            @if (session('kiosk_code.auto'))
                <strong>Cererea de recenzie a fost trimisă pe tabletă și se deschide automat.</strong>
                <p style="margin:.3rem 0 0">Dacă tableta nu o afișează, pacientul poate introduce codul <strong style="font-size:1.3rem;letter-spacing:.15em">{{ session('kiosk_code.code') }}</strong>.</p>
            @else
                <strong>Codul pentru pacient: <span style="font-size:1.6rem;letter-spacing:.2em">{{ session('kiosk_code.code') }}</span></strong>
                <p style="margin:.3rem 0 0">Spuneți-l pacientului sau scrieți-l pe un bilet; îl introduce pe tabletă. Valabil până la {{ session('kiosk_code.expires') }}. Codul nu va mai fi afișat.</p>
            @endif
        </div>
    </div>
@endif
<div class="grid grid--2">
    <x-panou.panel title="Semnare consimțământ pe tabletă">
        @if ($templates->isEmpty())
            <p class="muted">Nu există modele de consimțământ completate. Medicul le adaugă din Medical → Modele de consimțământ.</p>
        @else
            <form method="post" action="{{ route('panou.kiosk.sessions.consent', $patient) }}" class="stack">
                @csrf
                <x-panou.field name="consent_template_version_id" type="select" label="Consimțământ" :options="$templates->mapWithKeys(fn ($t) => [$t->latestVersion->id => $t->name.' (v'.$t->latestVersion->version.')'])->all()" required />
                <x-panou.field name="kiosk_device_id" type="select" label="Tabletă" :options="$devices->filter->accepts('consimtamant')->pluck('name', 'id')->all()" placeholder="Oricare tabletă de consimțăminte" />
                <button class="btn btn--primary" type="submit">Generează codul de 6 cifre</button>
            </form>
        @endif
    </x-panou.panel>
    <x-panou.panel title="Recenzie după serviciu" :flush="true">
        @if ($records->isEmpty())
            <x-panou.empty title="Niciun serviciu efectuat">Recenzia se cere după înregistrarea unui serviciu efectuat.</x-panou.empty>
        @else
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Data</th><th>Serviciu</th><th>Specialist</th><th></th></tr></thead>
                <tbody>
                @foreach ($records as $r)
                    <tr>
                        <td class="num">{{ $r->performed_at->format('d.m.Y') }}</td>
                        <td>{{ $r->service?->name }}</td>
                        <td>{{ $r->staffMember?->display_name }}</td>
                        <td class="text-right">
                            @if (in_array($r->id, $reviewed))
                                <x-panou.badge tone="ok">recenzie primită</x-panou.badge>
                            @else
                                <form method="post" action="{{ route('panou.kiosk.sessions.review', $r) }}" class="cluster" style="justify-content:flex-end">
                                    @csrf
                                    @php $reviewDevices = $devices->filter->accepts('recenzie'); @endphp
                                    @if ($reviewDevices->isNotEmpty())
                                        <select name="kiosk_device_id" aria-label="Tabletă" style="width:auto;min-height:32px;font-size:.8rem">
                                            <option value="">cod pentru orice tabletă</option>
                                            @foreach ($reviewDevices as $d)<option value="{{ $d->id }}">trimite direct pe „{{ $d->name }}”</option>@endforeach
                                        </select>
                                        <input type="hidden" name="auto_open" value="1">
                                    @endif
                                    <button class="btn btn--small" type="submit">Cere recenzie</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
    </x-panou.panel>
    <x-panou.panel title="Cereri trimise pe tabletă" class="span-2" :flush="true">
        @if ($sessions->isEmpty())
            <x-panou.empty title="Nicio cerere">Cererile trimise pe tabletă apar aici, cu starea lor.</x-panou.empty>
        @else
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Trimisă</th><th>Tip</th><th>Detalii</th><th>De</th><th>Stare</th><th></th></tr></thead>
                <tbody>
                @foreach ($sessions as $s)
                    <tr>
                        <td class="num">{{ $s->created_at->format('d.m.Y H:i') }}</td>
                        <td>{{ $kinds[$s->kind] }}</td>
                        <td>{{ $s->kind === 'consimtamant' ? $s->consentVersion?->template?->name : trim(($s->service?->name ?? '').' · '.($s->staffMember?->display_name ?? ''), ' ·') }}</td>
                        <td>{{ $s->creator?->name }}</td>
                        <td><x-panou.badge :tone="match($s->status) { 'finalizat' => 'ok', 'refuzat', 'blocat' => 'danger', 'deschis' => 'accent', 'in_asteptare' => $s->expires_at->isPast() ? null : 'warn', default => null }">{{ $s->statusLabel() }}</x-panou.badge></td>
                        <td class="text-right">
                            @if (in_array($s->status, ['in_asteptare', 'deschis']) && $s->expires_at->isFuture())
                                <form method="post" action="{{ route('panou.kiosk.sessions.cancel', $s) }}">@csrf<button class="btn btn--small" type="submit">Anulează</button></form>
                            @elseif ($s->status === 'finalizat' && $s->patient_consent_id && auth()->user()->can('medical.view'))
                                <a class="btn btn--small" href="{{ route('panou.medical.consents.show', $s->patient_consent_id) }}">Vezi consimțământul</a>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
    </x-panou.panel>
</div>
