@extends('layouts.panou')
@section('title', 'Actualizare '.$run->to_version)
@php
    use App\Modules\Updates\Models\UpdateRun;
    $stepTone = fn ($s) => match ($s) { 'done' => 'ok', 'running' => 'info', 'failed' => 'danger', 'reverted' => 'warn', default => '' };
    $stepLabel = fn ($s) => match ($s) { 'done' => 'încheiat', 'running' => 'în curs', 'failed' => 'eșuat', 'reverted' => 'anulat (revenire)', 'skipped' => 'omis', default => 'în așteptare' };
    $summary = $run->manifest_summary;
@endphp
@section('content')
    <x-panou.page-head :title="'Versiunea '.$run->to_version" :crumbs="['Actualizări' => route('panou.updates.index'), $run->to_version => null]"
        :sub="'De la '.$run->from_version.' · pachet '.($run->package_type === 'install' ? 'de instalare' : 'de actualizare').' · semnat cu cheia '.$run->key_id">
        <x-slot:actions>
            <x-panou.badge :tone="$run->statusTone()">{{ UpdateRun::STATUSES[$run->status] }}</x-panou.badge>
        </x-slot:actions>
    </x-panou.page-head>

    @if ($maintenance)
        <div class="alert alert--warn" role="status"><x-panou.icon name="warn" /><div>Aplicația este în <strong>mod mentenanță</strong>: vizitatorii și personalul văd o pagină de indisponibilitate, iar taskurile programate sunt oprite. Doar tabul Actualizări rămâne accesibil.</div></div>
    @endif

    @if ($run->status === 'failed' || $run->status === 'rolled_back')
        <div class="alert {{ $run->status === 'failed' ? 'alert--danger' : 'alert--warn' }}" role="alert">
            <x-panou.icon name="warn" /><div style="white-space:pre-line">{{ $run->error }}</div>
        </div>
    @endif

    <div class="grid grid--aside">
        <div class="stack">
            @if ($run->status === 'running' || $restoring)
                <x-panou.panel title="{{ $restoring ? 'Restaurarea bazei de date' : 'Instalare în curs' }}">
                    <p>Pasul curent: <strong>{{ $restoring ? 'restaurare din backup' : (UpdateRun::STEPS[$next] ?? '—') }}</strong>. Pagina continuă automat; dacă se oprește (ex. conexiune întreruptă), apăsați butonul — pașii se reiau de unde au rămas.</p>
                    <form method="post" action="{{ $restoring ? route('panou.updates.restore.continue', $run->uuid) : route('panou.updates.advance', $run->uuid) }}" data-module="autosubmit" data-delay="700">
                        @csrf
                        <button class="btn btn--primary" type="submit"><x-panou.icon name="refresh" />Continuă</button>
                    </form>
                </x-panou.panel>
            @endif

            <x-panou.panel title="Pași" :flush="true">
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>Pas</th><th>Stare</th><th>Detalii</th></tr></thead>
                        <tbody>
                        @foreach (UpdateRun::STEPS as $key => $label)
                            @php $s = $run->step($key); @endphp
                            <tr>
                                <td>{{ $label }}</td>
                                <td><x-panou.badge :tone="$stepTone($s['status'] ?? 'pending')">{{ $stepLabel($s['status'] ?? 'pending') }}</x-panou.badge></td>
                                <td class="small">
                                    @if (! empty($s['error']))<span class="field__error">{{ \Illuminate\Support\Str::limit($s['error'], 400) }}</span>@endif
                                    @if ($key === 'extract' && isset($s['data']['files'])) {{ $s['data']['files'] }} fișiere verificate @endif
                                    @if ($key === 'copy_release' && isset($s['data']['cursor'])) {{ $s['data']['cursor'] }} / {{ $summary['files'] }} fișiere @endif
                                    @if ($key === 'migrate' && ! empty($s['data']['applied'])) rulate: {{ implode(', ', $s['data']['applied']) }} @endif
                                    @if ($key === 'migrate' && ! empty($s['data']['current'])) <br>în lucru: {{ $s['data']['current'] }} @endif
                                    @if ($key === 'backup_db' && $run->backup) backup {{ \App\Modules\Backup\Models\Backup::STATUSES[$run->backup->status] ?? '' }} @endif
                                    @if ($key === 'health' && ! empty($s['data']['results']))
                                        @foreach ($s['data']['results'] as $r){{ $r['ok'] ? '✔' : '✘' }} {{ $r['check'] }}@if (! $loop->last); @endif @endforeach
                                    @endif
                                    @if (($s['attempts'] ?? 0) > 1) <span class="muted">(reluat de {{ $s['attempts'] - 1 }} ori)</span> @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </x-panou.panel>

            @if ($options)
                <x-panou.panel title="Opțiuni de recuperare">
                    <p class="muted small">Revenirea codului și a bazei de date sunt decizii separate. Baza de date nu se restaurează niciodată automat.</p>
                    <div class="stack">
                        @foreach ($options as $key => $option)
                            <form method="post" action="{{ route('panou.updates.recover', $run->uuid) }}" class="panel" style="padding:1rem">
                                @csrf
                                <input type="hidden" name="action" value="{{ $key }}">
                                <h3>{{ $option['label'] }}</h3>
                                <p class="small">{{ $option['detail'] }}</p>
                                <div class="cluster">
                                    @if ($option['danger'])
                                        <x-panou.field name="confirm_text" :id="'confirm_'.$key" label="Tastați CONFIRM" />
                                    @endif
                                    <button class="btn {{ $option['danger'] ? 'btn--danger' : '' }}" type="submit">{{ $option['label'] }}</button>
                                </div>
                            </form>
                        @endforeach
                    </div>
                </x-panou.panel>
            @endif

            <x-panou.panel title="Modificări (changelog)">
                @if (trim((string) $run->changelog) !== '')
                    <div style="white-space:pre-line">{{ $run->changelog }}</div>
                @else
                    <p class="muted">Pachetul nu conține note de modificare.</p>
                @endif
            </x-panou.panel>
        </div>

        <div class="stack">
            @if ($run->status === 'prepared')
                <x-panou.panel title="Instalare">
                    @php $ok = ! in_array('fail', array_column($run->preflight ?? [], 'status'), true); @endphp
                    @if ($ok)
                        <form method="post" action="{{ route('panou.updates.start', $run->uuid) }}" class="stack">
                            @csrf
                            <label class="check"><input type="checkbox" name="confirm" value="1" required><span>Am citit modificările. Știu că aplicația intră în mentenanță pe durata instalării.</span></label>
                            <button class="btn btn--primary" type="submit">Instalează versiunea {{ $run->to_version }}</button>
                        </form>
                    @else
                        <p>Instalarea nu poate porni până nu se rezolvă verificările marcate „eșuat”.</p>
                    @endif
                    <form method="post" action="{{ route('panou.updates.cancel', $run->uuid) }}" style="margin-top:.75rem" data-confirm="Anulați și ștergeți pachetul?">
                        @csrf <button class="btn btn--ghost btn--small" type="submit">Anulează</button>
                    </form>
                </x-panou.panel>
            @endif

            <x-panou.panel title="Verificări preliminare" :flush="true">
                <ul class="nav-list" style="padding:.5rem 1rem">
                    @foreach ($run->preflight ?? [] as $check)
                        <li style="padding:.45rem 0;border-bottom:1px solid var(--line)">
                            <x-panou.badge :tone="['ok' => 'ok', 'warn' => 'warn', 'fail' => 'danger'][$check['status']]">{{ ['ok' => 'ok', 'warn' => 'atenție', 'fail' => 'eșuat'][$check['status']] }}</x-panou.badge>
                            <strong class="small">{{ $check['label'] }}</strong>
                            @if ($check['detail'])<div class="small muted">{{ $check['detail'] }}</div>@endif
                        </li>
                    @endforeach
                </ul>
            </x-panou.panel>

            <x-panou.panel title="Pachet">
                <dl class="facts">
                    <dt>Fișiere</dt><dd class="num">{{ number_format($summary['files'], 0, ',', '.') }}</dd>
                    <dt>Dimensiune</dt><dd class="num">{{ \App\Modules\Backup\BackupManager::human($summary['bytes']) }}</dd>
                    <dt>Migrări incluse</dt><dd class="num">{{ count($summary['migrations']) }}</dd>
                    <dt>PHP minim</dt><dd class="num">{{ $summary['php'] }}</dd>
                    @if ($summary['upgrade_from'])<dt>Se instalează peste</dt><dd class="num">{{ $summary['upgrade_from'][0] }} – {{ $summary['upgrade_from'][1] }}</dd>@endif
                    <dt>Construit</dt><dd class="num">{{ $summary['created_at'] ? \Carbon\Carbon::parse($summary['created_at'])->timezone(config('app.timezone'))->format('d.m.Y H:i') : '—' }}</dd>
                    <dt>SHA-256</dt><dd class="small" style="word-break:break-all">{{ $run->package_sha256 }}</dd>
                </dl>
            </x-panou.panel>

            <x-panou.panel title="Jurnal" :flush="true">
                <ul class="nav-list small" style="padding:.5rem 1rem;max-height:340px;overflow:auto">
                    @foreach ($events as $e)
                        <li style="padding:.3rem 0"><span class="num muted">{{ \Carbon\Carbon::parse($e['at'])->timezone(config('app.timezone'))->format('H:i:s') }}</span> {{ str_replace('_', ' ', $e['event']) }}@if (! empty($e['eroare'])) — {{ \Illuminate\Support\Str::limit($e['eroare'], 140) }}@endif @if (! empty($e['versiune'])) {{ $e['versiune'] }}@endif</li>
                    @endforeach
                </ul>
            </x-panou.panel>
        </div>
    </div>
@endsection
