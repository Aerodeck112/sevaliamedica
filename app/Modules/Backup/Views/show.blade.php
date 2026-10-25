@extends('layouts.panou')
@section('title', 'Backup')
@section('content')
    <x-panou.page-head :title="'Backup '.mb_strtolower(\App\Modules\Backup\Models\Backup::TYPES[$backup->type])" :crumbs="['Backupuri' => route('panou.backups.index'), $backup->started_at?->format('d.m.Y H:i') => null]">
        <x-slot:actions><x-panou.badge :tone="$backup->statusTone()">{{ \App\Modules\Backup\Models\Backup::STATUSES[$backup->status] }}</x-panou.badge></x-slot:actions>
    </x-panou.page-head>
    @if ($backup->status === 'running')
        <x-panou.panel title="Backup în curs">
            <p>Backupul se scrie pe bucăți. Pagina continuă automat; dacă se oprește, apăsați butonul.</p>
            <form method="post" action="{{ route('panou.backups.advance', $backup->uuid) }}" data-module="autosubmit" data-delay="500">
                @csrf <button class="btn btn--primary" type="submit"><x-panou.icon name="refresh" />Continuă</button>
            </form>
        </x-panou.panel>
    @endif
    @error('backup')<div class="alert alert--danger"><x-panou.icon name="warn" /><div>{{ $message }}</div></div>@enderror
    <x-panou.panel title="Detalii">
        <dl class="facts">
            <dt>Fișier</dt><dd class="small">{{ $backup->file }}</dd>
            <dt>Declanșat de</dt><dd>{{ $backup->trigger }}{{ $backup->creator ? ' · '.$backup->creator->name : '' }}</dd>
            <dt>Început</dt><dd class="num">{{ $backup->started_at?->format('d.m.Y H:i:s') }}</dd>
            <dt>Încheiat</dt><dd class="num">{{ $backup->finished_at?->format('d.m.Y H:i:s') ?? '—' }}</dd>
            <dt>Verificat</dt><dd class="num">{{ $backup->verified_at?->format('d.m.Y H:i:s') ?? '—' }}</dd>
            <dt>Dimensiune</dt><dd class="num">{{ $backup->size ? \App\Modules\Backup\BackupManager::human($backup->size) : '—' }}</dd>
            <dt>SHA-256</dt><dd class="small" style="word-break:break-all">{{ $backup->sha256 ?? '—' }}</dd>
            <dt>Amprenta cheii</dt><dd class="num">{{ $backup->key_fingerprint }}</dd>
            <dt>Versiune aplicație</dt><dd class="num">{{ $backup->app_version }}</dd>
            @if ($backup->error)<dt>Eroare</dt><dd class="field__error">{{ $backup->error }}</dd>@endif
        </dl>
    </x-panou.panel>
@endsection
