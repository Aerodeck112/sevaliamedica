<?php

namespace App\Modules\Updates\Http\Controllers;

use App\Core\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Modules\Updates\Models\UpdateRun;
use App\Modules\Updates\Package\PackageException;
use App\Modules\Updates\Support\DeploymentLayout;
use App\Modules\Updates\Support\EnvironmentReport;
use App\Modules\Updates\Updater\Journal;
use App\Modules\Updates\Updater\UpdateRunner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class UpdateController extends Controller
{
    public function __construct(private readonly UpdateRunner $runner) {}

    public function index(DeploymentLayout $layout): View
    {
        return view('updates::index', [
            'current' => $this->runner->currentVersion(),
            'pointer' => $layout->current(),
            'deployed' => $layout->isDeployed(),
            'releases' => $layout->releases(),
            'runs' => UpdateRun::with('creator')->latest('id')->limit(30)->get(),
            'active' => UpdateRun::whereNotNull('active_slot')->first(),
            'maxUpload' => ini_get('upload_max_filesize'),
            'trustedKeys' => array_keys(\App\Modules\Updates\Package\SignatureVerifier::fromConfig()->trustedKeys()),
        ]);
    }

    public function environment(EnvironmentReport $report): View
    {
        return view('updates::environment', ['rows' => $report->collect()]);
    }

    public function upload(Request $request): RedirectResponse
    {
        $request->validate(['package' => ['required', 'file', 'max:'.(int) (config('updates.limits.max_package_bytes') / 1024)]]);
        $file = $request->file('package');
        $tmp = $file->getRealPath().'.sevalia';
        $file->move(dirname($tmp), basename($tmp));
        try {
            $run = $this->runner->prepare($tmp, $request->user()->id);
        } catch (PackageException $e) {
            @unlink($tmp);

            return back()->withErrors(['package' => 'Pachet respins: '.$e->getMessage()]);
        }

        return redirect()->route('panou.updates.show', $run->uuid)->with('status', 'Pachetul a fost verificat: semnătură validă, structură corectă.');
    }

    public function show(string $uuid, Journal $journal): View
    {
        $run = UpdateRun::with(['backup', 'creator'])->where('uuid', $uuid)->firstOrFail();

        return view('updates::show', [
            'run' => $run,
            'options' => $this->runner->recoveryOptions($run),
            'events' => array_reverse($journal->events($run->uuid)),
            'next' => $this->runner->nextStep($run),
            'restoring' => ($run->steps['restore_db']['status'] ?? null) === 'running',
            'maintenance' => app()->isDownForMaintenance(),
        ]);
    }

    public function start(Request $request, string $uuid): RedirectResponse
    {
        $run = UpdateRun::where('uuid', $uuid)->firstOrFail();
        $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => 'Confirmați că ați citit modificările și că aplicația va intra în mentenanță.']);
        try {
            $this->runner->start($run, $request->user()->id);
        } catch (Throwable $e) {
            return back()->withErrors(['start' => $e->getMessage()]);
        }

        return redirect()->route('panou.updates.show', $uuid);
    }

    public function advance(string $uuid): RedirectResponse
    {
        $run = UpdateRun::where('uuid', $uuid)->firstOrFail();
        try {
            $this->runner->advance($run);
        } catch (Throwable $e) {
            return redirect()->route('panou.updates.show', $uuid)->with('warning', $e->getMessage());
        }

        return redirect()->route('panou.updates.show', $uuid);
    }

    public function cancel(string $uuid): RedirectResponse
    {
        $run = UpdateRun::where('uuid', $uuid)->firstOrFail();
        try {
            $this->runner->cancel($run);
        } catch (Throwable $e) {
            return back()->withErrors(['cancel' => $e->getMessage()]);
        }

        return redirect()->route('panou.updates.index')->with('status', 'Actualizarea a fost anulată; pachetul a fost șters.');
    }

    public function recover(Request $request, string $uuid): RedirectResponse
    {
        $run = UpdateRun::where('uuid', $uuid)->firstOrFail();
        $options = $this->runner->recoveryOptions($run);
        $data = $request->validate(['action' => ['required', Rule::in(array_keys($options))], 'confirm_text' => ['nullable', 'string']]);
        if (($options[$data['action']]['danger'] ?? false) && trim((string) ($data['confirm_text'] ?? '')) !== 'CONFIRM') {
            return back()->withErrors(['confirm_text' => 'Pentru această acțiune tastați CONFIRM.']);
        }
        Audit::log('updates.recovery_action', $run, ['actiune' => $data['action']]);
        try {
            match ($data['action']) {
                'retry' => $this->runner->retry($run),
                'restore_db' => $this->runner->restoreDatabase($run),
                'rollback_code' => $this->runner->rollbackCode($run),
                'maintenance_off' => $this->runner->maintenanceOff($run),
                'close' => $this->runner->close($run),
            };
        } catch (Throwable $e) {
            return back()->withErrors(['action' => $e->getMessage()]);
        }

        return redirect()->route('panou.updates.show', $uuid)->with('status', 'Acțiunea de recuperare a fost executată.');
    }

    public function continueRestore(string $uuid): RedirectResponse
    {
        $run = UpdateRun::with('backup')->where('uuid', $uuid)->firstOrFail();
        if (($run->steps['restore_db']['status'] ?? null) !== 'running') {
            return redirect()->route('panou.updates.show', $uuid);
        }
        try {
            $this->runner->restoreDatabase($run);
        } catch (Throwable $e) {
            return redirect()->route('panou.updates.show', $uuid)->withErrors(['action' => $e->getMessage()]);
        }

        return redirect()->route('panou.updates.show', $uuid);
    }
}
