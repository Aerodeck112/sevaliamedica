<?php

namespace App\Modules\Backup\Http\Controllers;

use App\Core\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Modules\Backup\BackupManager;
use App\Modules\Backup\Crypto\BackupKey;
use App\Modules\Backup\Models\Backup;
use App\Modules\Updates\Support\DeploymentLayout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class BackupController extends Controller
{
    public function __construct(private readonly BackupManager $manager, private readonly DeploymentLayout $layout) {}

    public function index(): View
    {
        $hasKey = BackupKey::exists($this->layout->keysPath());

        return view('backup::index', [
            'backups' => Backup::with('creator')->latest('id')->paginate(30),
            'hasKey' => $hasKey,
            'fingerprint' => $hasKey ? $this->manager->key()->fingerprint() : null,
            'retain' => config('updates.backup.retain'),
            'dailyAt' => config('updates.backup.daily_at'),
            'lastVerified' => Backup::where('status', 'verified')->where('type', 'database')->latest('verified_at')->first(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['type' => ['required', Rule::in(array_keys(Backup::TYPES))]]);
        try {
            $backup = $this->manager->start($data['type'], 'manual', $request->user()->id);
        } catch (Throwable $e) {
            return back()->withErrors(['type' => $e->getMessage()]);
        }

        return redirect()->route('panou.backups.show', $backup->uuid);
    }

    public function show(string $uuid): View
    {
        return view('backup::show', ['backup' => Backup::with('creator')->where('uuid', $uuid)->firstOrFail()]);
    }

    public function advance(string $uuid): RedirectResponse
    {
        $backup = Backup::where('uuid', $uuid)->firstOrFail();
        try {
            if ($this->manager->step($backup, microtime(true) + (int) config('updates.step_time_budget', 15))) {
                $this->manager->verify($backup->refresh());

                return redirect()->route('panou.backups.show', $uuid)->with('status', 'Backupul a fost creat și verificat.');
            }
        } catch (Throwable $e) {
            return redirect()->route('panou.backups.show', $uuid)->withErrors(['backup' => $e->getMessage()]);
        }

        return redirect()->route('panou.backups.show', $uuid);
    }

    public function verify(string $uuid): RedirectResponse
    {
        $backup = Backup::where('uuid', $uuid)->firstOrFail();
        try {
            $result = $this->manager->verify($backup);
        } catch (Throwable $e) {
            Audit::log('backup.verify_failed', $backup, ['eroare' => mb_substr($e->getMessage(), 0, 300)]);

            return back()->withErrors(['backup' => 'Verificare eșuată: '.$e->getMessage()]);
        }
        $detail = $backup->type === 'database' ? $result['statements'].' instrucțiuni SQL' : $result['files'].' fișiere';

        return back()->with('status', "Backup valid: decriptare și autentificare reușite ({$detail}).");
    }

    public function download(string $uuid): BinaryFileResponse
    {
        $backup = Backup::where('uuid', $uuid)->firstOrFail();
        abort_unless($backup->isUsable(), 404);
        $file = $this->layout->backupsPath($backup->file);
        abort_unless(is_file($file), 404);
        Audit::log('backup.downloaded', $backup);

        // Fișierul este criptat; fără cheia de backup (păstrată separat) nu poate fi citit.
        return response()->download($file, basename($backup->file), ['Content-Type' => 'application/octet-stream', 'Cache-Control' => 'no-store']);
    }
}
