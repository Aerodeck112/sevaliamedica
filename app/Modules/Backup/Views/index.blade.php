@extends('layouts.panou')
@section('title', 'Backupuri')
@section('content')
    <x-panou.page-head title="Backupuri" sub="Copii criptate ale bazei de date și ale documentelor. Fiecare backup este verificat prin decriptare completă, nu doar prin existența fișierului." />

    @unless ($hasKey)
        <div class="alert alert--danger" role="alert"><x-panou.icon name="warn" /><div>Cheia de backup lipsește (<code>shared/keys/backup.key</code>). Rulați <code>php artisan sevalia:install</code> sau restaurați cheia din copia offline. Fără cheie nu se pot crea sau citi backupuri.</div></div>
    @endunless

    <div class="grid grid--2">
        <x-panou.panel title="Creează un backup acum">
            <form method="post" action="{{ route('panou.backups.store') }}" class="cluster">
                @csrf
                <button class="btn btn--primary" type="submit" name="type" value="database" @disabled(! $hasKey)>Bază de date</button>
                <button class="btn" type="submit" name="type" value="documents" @disabled(! $hasKey)>Documente private</button>
            </form>
            @error('type')<p class="field__error">{{ $message }}</p>@enderror
            <p class="small muted" style="margin-top:.75rem">Backupul se face pe bucăți; pagina continuă automat până la final.</p>
        </x-panou.panel>
        <x-panou.panel title="Politică">
            <dl class="facts">
                <dt>Bază de date</dt><dd>zilnic la {{ $dailyAt }}, se păstrează {{ $retain['database'] }}</dd>
                <dt>Documente</dt><dd>săptămânal, duminică 03:30, se păstrează {{ $retain['documents'] }}</dd>
                <dt>Criptare</dt><dd>XChaCha20-Poly1305, cheie separată de cheia aplicației</dd>
                <dt>Amprenta cheii</dt><dd class="num">{{ $fingerprint ?? '—' }}</dd>
                <dt>Ultima verificare</dt><dd class="num">{{ $lastVerified?->verified_at?->format('d.m.Y H:i') ?? 'niciuna' }}</dd>
            </dl>
            <p class="small muted">Testul periodic de restaurare (într-o bază separată) este descris în docs/BACKUP-RESTAURARE.md.</p>
        </x-panou.panel>
    </div>

    <x-panou.panel title="Backupuri existente" :flush="true" style="margin-top:1.25rem">
        @if ($backups->isEmpty())
            <x-panou.empty title="Niciun backup încă">Primul backup programat rulează la {{ $dailyAt }}; puteți crea unul acum.</x-panou.empty>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Creat</th><th>Tip</th><th>Declanșat de</th><th>Stare</th><th>Dimensiune</th><th>Conținut</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($backups as $b)
                        <tr>
                            <td class="num"><a class="row-link" href="{{ route('panou.backups.show', $b->uuid) }}">{{ $b->started_at?->format('d.m.Y H:i') }}</a></td>
                            <td>{{ \App\Modules\Backup\Models\Backup::TYPES[$b->type] }}</td>
                            <td>{{ ['manual' => 'manual', 'schedule' => 'program automat', 'update' => 'actualizare'][$b->trigger] ?? $b->trigger }}{{ $b->creator ? ' · '.$b->creator->name : '' }}</td>
                            <td><x-panou.badge :tone="$b->statusTone()">{{ \App\Modules\Backup\Models\Backup::STATUSES[$b->status] }}</x-panou.badge></td>
                            <td class="num">{{ $b->size ? \App\Modules\Backup\BackupManager::human($b->size) : '—' }}</td>
                            <td class="small">
                                @if ($b->type === 'database' && $b->summary){{ $b->summary['tables'] }} tabele, {{ number_format($b->summary['rows'], 0, ',', '.') }} rânduri
                                @elseif ($b->summary){{ $b->summary['files'] }} fișiere @endif
                            </td>
                            <td class="text-right">
                                @if ($b->isUsable())
                                    <form method="post" action="{{ route('panou.backups.verify', $b->uuid) }}" style="display:inline">@csrf<button class="btn btn--small" type="submit">Verifică</button></form>
                                    <a class="btn btn--small" href="{{ route('panou.backups.download', $b->uuid) }}">Descarcă</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            {{ $backups->links() }}
        @endif
    </x-panou.panel>
@endsection
