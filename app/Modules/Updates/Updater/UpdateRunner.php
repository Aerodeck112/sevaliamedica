<?php

namespace App\Modules\Updates\Updater;

use App\Core\Audit\Audit;
use App\Modules\Backup\BackupManager;
use App\Modules\Backup\Models\Backup;
use App\Modules\Updates\Models\UpdateRun;
use App\Modules\Updates\Package\InspectedPackage;
use App\Modules\Updates\Package\PackageException;
use App\Modules\Updates\Package\PackageExtractor;
use App\Modules\Updates\Package\PackageInspector;
use App\Modules\Updates\Support\DeploymentLayout;
use App\Modules\Updates\Support\HealthChecker;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Mașina de stări a unei instalări de pachet.
 *
 * Fiecare apel advance() execută cel mult un pas, în limita bugetului de timp (pașii mari continuă la apelul
 * următor, cu cursor salvat). Starea se scrie în baza de date și în jurnalul JSON înainte și după fiecare pas,
 * deci un proces întrerupt (timeout, restart) se reia cu advance(): pașii sunt idempotenți.
 *
 * Revenirea codului și revenirea bazei de date sunt tratate separat:
 *  - eșec înainte de migrări: versiunea nouă se șterge, aplicația rămâne pe versiunea veche, mentenanța se oprește;
 *  - eșec la/după migrări: baza NU se restaurează automat; aplicația rămâne în mentenanță, iar administratorul
 *    alege explicit: restaurarea backupului de dinainte, reluarea pasului sau ieșirea din mentenanță.
 */
class UpdateRunner
{
    /** Pași după care baza de date poate fi deja modificată. */
    private const DB_CHANGING = ['migrate', 'publish_assets', 'activate', 'health', 'maintenance_off', 'cleanup'];

    public function __construct(
        private readonly DeploymentLayout $layout,
        private readonly PackageInspector $inspector,
        private readonly PackageExtractor $extractor,
        private readonly Preflight $preflight,
        private readonly BackupManager $backups,
        private readonly HealthChecker $health,
        private readonly Journal $journal,
    ) {}

    public function currentVersion(): string
    {
        return $this->layout->current()['release'] ?? (string) config('sevalia.version');
    }

    // ------------------------------------------------------------------ pregătire și pornire

    /** Primește pachetul, îl verifică (semnătură, structură) și creează actualizarea în starea „pregătit”. */
    public function prepare(string $packagePath, ?int $userId = null): UpdateRun
    {
        $uuid = (string) Str::uuid();
        $dir = $this->layout->updatesPath($uuid);
        if (! is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $target = $dir.'/package.zip';
        if (! @rename($packagePath, $target) && ! @copy($packagePath, $target)) {
            throw new RuntimeException('Nu pot salva pachetul încărcat.');
        }

        try {
            $package = $this->inspector->inspect($target);
        } catch (PackageException $e) {
            $this->removeDirectory($dir);
            Audit::log('updates.package_rejected', context: ['motiv' => $e->getMessage()], userId: $userId);
            throw $e;
        }

        $m = $package->manifest;
        file_put_contents($dir.'/manifest.json', $m->raw);
        $current = $this->currentVersion();
        $run = UpdateRun::create([
            'uuid' => $uuid,
            'from_version' => $current,
            'to_version' => $m->version,
            'package_type' => $m->type,
            'package_sha256' => $package->sha256,
            'key_id' => $package->keyId,
            'manifest_summary' => [
                'files' => count($m->files), 'bytes' => $m->totalBytes(), 'migrations' => $m->migrations,
                'php' => $m->phpMin, 'extensions' => $m->extensions, 'database' => $m->databases,
                'upgrade_from' => $m->type === 'update' ? [$m->upgradeFromMin, $m->upgradeFromMax] : null,
                'created_at' => $m->createdAt, 'builder' => $m->builder,
            ],
            'changelog' => $m->changelog,
            'status' => 'prepared',
            'steps' => array_map(fn () => ['status' => 'pending'], UpdateRun::STEPS),
            'preflight' => $this->preflight->run($m, $current),
            'created_by' => $userId,
        ]);
        $this->journal->write($run, 'pachet_incarcat', ['versiune' => $m->version, 'cheie' => $package->keyId]);
        Audit::log('updates.package_uploaded', $run, ['versiune' => $m->version, 'cheie' => $package->keyId], userId: $userId);

        return $run;
    }

    public function start(UpdateRun $run, ?int $userId = null): UpdateRun
    {
        if ($run->status !== 'prepared') {
            throw new RuntimeException('Actualizarea nu este în starea „pregătit”.');
        }
        $results = $this->preflight->run($this->package($run)->manifest, $this->currentVersion(), $run->id);
        $run->preflight = $results;
        if (! Preflight::passed($results)) {
            $run->save();
            throw new RuntimeException('Verificările preliminare nu au trecut. Rezolvați problemele marcate și reîncercați.');
        }
        try {
            $run->forceFill(['active_slot' => 1, 'status' => 'running', 'started_at' => now()])->save();
        } catch (UniqueConstraintViolationException) {
            throw new RuntimeException('O altă actualizare este deja în curs.');
        }
        $this->journal->write($run, 'pornit');
        Audit::log('updates.started', $run, ['de_la' => $run->from_version, 'la' => $run->to_version], userId: $userId);

        return $run;
    }

    public function cancel(UpdateRun $run): void
    {
        if ($run->status !== 'prepared') {
            throw new RuntimeException('Doar o actualizare încă nepornită poate fi anulată.');
        }
        $run->forceFill(['status' => 'cancelled', 'active_slot' => null, 'finished_at' => now()])->save();
        $this->removeDirectory($this->layout->updatesPath($run->uuid.'/staging'));
        @unlink($this->layout->updatesPath($run->uuid.'/package.zip'));
        $this->journal->write($run, 'anulat');
        Audit::log('updates.cancelled', $run);
    }

    // ------------------------------------------------------------------ avansare

    /** Execută (o parte din) pasul curent. Întoarce actualizarea reîmprospătată. */
    public function advance(UpdateRun $run, ?float $deadline = null): UpdateRun
    {
        $lock = $this->acquireLock();
        try {
            $run->refresh();
            if ($run->status !== 'running') {
                return $run;
            }
            $step = $this->nextStep($run);
            if ($step === null) {
                return $this->complete($run);
            }
            $deadline ??= microtime(true) + (int) config('updates.step_time_budget', 15);

            $state = $run->step($step);
            if (($state['status'] ?? 'pending') === 'pending') {
                $state = ['status' => 'running', 'started_at' => gmdate('c'), 'attempts' => 0, 'data' => []];
            }
            $state['attempts'] = ($state['attempts'] ?? 0) + 1;
            $state['status'] = 'running';
            $this->saveStep($run, $step, $state);

            try {
                $data = $state['data'] ?? [];
                $done = $this->{'step'.Str::studly($step)}($run, $data, $deadline);
                $state['data'] = $data;
                if ($done) {
                    $state['status'] = 'done';
                    $state['finished_at'] = gmdate('c');
                    unset($state['error']);
                }
                $this->saveStep($run, $step, $state, $done ? 'pas_'.$step : null);
            } catch (Throwable $e) {
                $state['data'] = $data ?? ($state['data'] ?? []);
                $state['status'] = 'failed';
                $state['error'] = mb_substr($e->getMessage(), 0, 1500);
                $state['finished_at'] = gmdate('c');
                $this->saveStep($run, $step, $state);
                $this->handleFailure($run, $step, $e);
            }

            if ($run->status === 'running' && $this->nextStep($run) === null) {
                return $this->complete($run);
            }

            return $run;
        } finally {
            $this->releaseLock($lock);
        }
    }

    /** Rulează până la final sau eșec (CLI). */
    public function runToEnd(UpdateRun $run, ?callable $progress = null): UpdateRun
    {
        while ($run->status === 'running') {
            $run = $this->advance($run);
            if ($progress) {
                $progress($run);
            }
        }

        return $run;
    }

    public function nextStep(UpdateRun $run): ?string
    {
        foreach (array_keys(UpdateRun::STEPS) as $key) {
            if (! $run->stepDone($key)) {
                return $key;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------ pașii

    private function stepExtract(UpdateRun $run, array &$data, float $deadline): bool
    {
        $result = $this->extractor->extract($this->package($run), $this->stagingPath($run), (int) ($data['cursor'] ?? 0), $deadline);
        $data['cursor'] = $result['cursor'];
        $data['files'] = ($data['files'] ?? 0) + $result['files'];

        return $result['done'];
    }

    private function stepPreflight(UpdateRun $run, array &$data, float $deadline): bool
    {
        $results = $this->preflight->run($this->package($run)->manifest, $run->from_version, $run->id);
        // În timpul rulării, mentenanța/staging-ul propriu nu sunt motive de oprire.
        $run->forceFill(['preflight' => $results])->save();
        if (! Preflight::passed($results)) {
            $failed = array_filter($results, fn ($r) => $r['status'] === 'fail');
            throw new RuntimeException('Verificări preliminare eșuate: '.implode('; ', array_map(fn ($r) => $r['label'].' — '.$r['detail'], $failed)));
        }

        return true;
    }

    private function stepMaintenanceOn(UpdateRun $run, array &$data, float $deadline): bool
    {
        if (! app()->isDownForMaintenance()) {
            app()->maintenanceMode()->activate([
                'time' => time(), 'retry' => 60, 'refresh' => 60, 'status' => 503,
                'redirect' => null, 'secret' => null, 'template' => null, 'except' => [],
                'reason' => 'actualizare '.$run->to_version,
            ]);
        }
        $data['since'] = $data['since'] ?? gmdate('c');
        $this->journal->write($run, 'mentenanta_pornita');

        return true;
    }

    private function stepBackupDb(UpdateRun $run, array &$data, float $deadline): bool
    {
        if (empty($data['backup_uuid'])) {
            $backup = $this->backups->start('database', 'update', $run->created_by);
            $data['backup_uuid'] = $backup->uuid;
            $data['started_at'] = gmdate('c');
            $run->forceFill(['backup_id' => $backup->id])->save();
            // Salvăm imediat legătura, ca o întrerupere să nu lase un backup orfan.
            $steps = $run->steps;
            $steps['backup_db']['data'] = $data;
            $run->forceFill(['steps' => $steps])->save();
        }
        $backup = Backup::where('uuid', $data['backup_uuid'])->firstOrFail();

        return $this->backups->step($backup, $deadline);
    }

    private function stepVerifyBackup(UpdateRun $run, array &$data, float $deadline): bool
    {
        $result = $this->backups->verify($run->backup()->firstOrFail());
        $data['statements'] = $result['statements'];

        return true;
    }

    private function stepCopyRelease(UpdateRun $run, array &$data, float $deadline): bool
    {
        $version = $run->to_version;
        $final = $this->layout->releasesPath($version);
        $partial = $final.'.partial';
        if (! isset($data['cursor'])) {
            if (is_dir($final)) {
                if ($version === ($this->layout->current()['release'] ?? null)) {
                    throw new RuntimeException("Versiunea {$version} este deja activă.");
                }
                // Rămășiță dintr-o încercare anterioară, neactivă: se înlocuiește.
                $this->removeDirectory($final);
            }
            $this->removeDirectory($partial);
            mkdir($partial, 0755, true);
            $data['cursor'] = 0;
        }

        $files = $this->package($run)->manifest->files;
        $staging = $this->stagingPath($run);
        $i = (int) $data['cursor'];
        $first = true;
        for (; $i < count($files); $i++) {
            if (! $first && microtime(true) > $deadline) {
                break;
            }
            $first = false;
            $file = $files[$i];
            $rel = substr($file['path'], strlen('payload/'));
            $this->copyVerified($staging.'/'.$file['path'], $partial.'/'.$rel, $file['sha256']);
        }
        $data['cursor'] = $i;
        if ($i < count($files)) {
            return false;
        }
        if (! @rename($partial, $final)) {
            throw new RuntimeException('Nu pot finaliza directorul versiunii noi.');
        }

        return true;
    }

    private function stepLinkShared(UpdateRun $run, array &$data, float $deadline): bool
    {
        $release = $this->layout->releasesPath($run->to_version);
        $this->layout->ensureSharedDirectories();
        // Versiunea nouă își citește .env și storage din shared/ (vezi bootstrap/app.php).
        file_put_contents($release.'/.shared-path', $this->layout->sharedPath()."\n");
        if (is_dir($release.'/storage') && ! is_link($release.'/storage')) {
            $this->removeDirectory($release.'/storage');
        }
        foreach (['bootstrap/cache'] as $dir) {
            if (! is_dir($release.'/'.$dir)) {
                mkdir($release.'/'.$dir, 0755, true);
            }
        }

        return true;
    }

    private function stepMigrate(UpdateRun $run, array &$data, float $deadline): bool
    {
        $path = $this->layout->releasesPath($run->to_version).'/database/migrations';
        if (! is_dir($path)) {
            return true;
        }
        /** @var \Illuminate\Database\Migrations\Migrator $migrator */
        $migrator = app('migrator');
        // Migrator::setConnection() schimbă și conexiunea implicită a aplicației: o refacem la final.
        $previousDefault = DB::getDefaultConnection();
        $previous = $migrator->getConnection();
        $migrator->setConnection(config('updates.connection') ?? $previousDefault);
        try {
            if (! $migrator->repositoryExists()) {
                $migrator->getRepository()->createRepository();
            }
            $first = true;
            while (true) {
                $ran = $migrator->getRepository()->getRan();
                $pending = array_values(array_diff_key($migrator->getMigrationFiles([$path]), array_flip($ran)));
                if ($pending === []) {
                    return true;
                }
                if (! $first && microtime(true) > $deadline) {
                    return false;
                }
                $first = false;
                $name = basename($pending[0], '.php');
                $data['current'] = $name;
                $migrator->runPending([$pending[0]], ['pretend' => false, 'step' => false]);
                $data['applied'][] = $name;
                unset($data['current']);
            }
        } finally {
            $migrator->setConnection($previous ?? $previousDefault);
            DB::setDefaultConnection($previousDefault);
        }
    }

    private function stepPublishAssets(UpdateRun $run, array &$data, float $deadline): bool
    {
        $public = $this->layout->publicPath();
        if ($public === null) {
            $data['skipped'] = 'SEVALIA_PUBLIC_PATH nu este configurat';

            return true;
        }
        $release = $this->layout->releasesPath($run->to_version);
        if (! isset($data['list'])) {
            $list = [];
            foreach ($this->filesUnder($release.'/public') as $rel) {
                if (! in_array($rel, ['index.php', '.htaccess'], true)) {
                    $list[] = ['public/'.$rel, $rel];
                }
            }
            foreach (['index.php', 'recovery.php'] as $f) {
                if (is_file($release.'/deploy/public_html/'.$f)) {
                    $list[] = ['deploy/public_html/'.$f, $f];
                }
            }
            $data['list'] = $list;
            $data['cursor'] = 0;
            $data['copied'] = 0;
        }
        $first = true;
        for ($i = (int) $data['cursor']; $i < count($data['list']); $i++) {
            if (! $first && microtime(true) > $deadline) {
                $data['cursor'] = $i;

                return false;
            }
            $first = false;
            [$src, $dest] = $data['list'][$i];
            $from = $release.'/'.$src;
            $to = $public.'/'.$dest;
            if (is_file($to) && filesize($to) === filesize($from) && hash_file('sha256', $to) === hash_file('sha256', $from)) {
                continue;
            }
            if (! is_dir(dirname($to))) {
                mkdir(dirname($to), 0755, true);
            }
            $tmp = $to.'.tmp-'.bin2hex(random_bytes(3));
            copy($from, $tmp);
            rename($tmp, $to);
            $data['copied']++;
        }
        $data['cursor'] = count($data['list']);
        unset($data['list']);

        return true;
    }

    private function stepActivate(UpdateRun $run, array &$data, float $deadline): bool
    {
        $current = $this->layout->current()['release'] ?? null;
        if ($current !== $run->to_version) {
            $data['previous'] = $current;
            $this->layout->activate($run->to_version, $current);
        }
        $this->journal->write($run, 'versiune_activata', ['versiune' => $run->to_version, 'anterioara' => $data['previous'] ?? null]);

        return true;
    }

    private function stepHealth(UpdateRun $run, array &$data, float $deadline): bool
    {
        $results = $this->health->check($this->layout->releasesPath($run->to_version), $run->to_version);
        $data['results'] = $results;
        $failed = array_filter($results, fn ($r) => ! $r['ok']);
        if ($failed) {
            throw new RuntimeException('Verificările de funcționare au eșuat: '.implode('; ', array_map(fn ($r) => $r['check'].' ('.$r['detail'].')', $failed)));
        }

        return true;
    }

    private function stepMaintenanceOff(UpdateRun $run, array &$data, float $deadline): bool
    {
        app()->maintenanceMode()->deactivate();
        $this->journal->write($run, 'mentenanta_oprita');

        return true;
    }

    private function stepCleanup(UpdateRun $run, array &$data, float $deadline): bool
    {
        $this->removeDirectory($this->stagingPath($run));
        @unlink($this->layout->updatesPath($run->uuid.'/package.zip'));
        $current = $this->layout->current();
        $keep = array_filter([$current['release'] ?? null, $current['previous'] ?? null]);
        $releases = $this->layout->releases();
        $removable = array_values(array_diff($releases, $keep));
        $excess = count($releases) - (int) config('updates.keep_releases', 3);
        foreach (array_slice($removable, 0, max(0, $excess)) as $old) {
            $this->removeDirectory($this->layout->releasesPath($old));
            $data['removed'][] = $old;
        }

        return true;
    }

    // ------------------------------------------------------------------ eșec și recuperare

    private function handleFailure(UpdateRun $run, string $step, Throwable $e): void
    {
        $dbChanged = in_array($step, self::DB_CHANGING, true) && ! empty($run->step('migrate')['data']['applied'] ?? [])
            || $step === 'migrate';
        $activated = $run->stepDone('activate');
        $notes = [];

        if ($step === 'health' && $activated) {
            $previous = $run->step('activate')['data']['previous'] ?? null;
            if ($previous && is_dir($this->layout->releasesPath($previous))) {
                $this->layout->activate($previous, $run->to_version);
                $steps = $run->steps;
                $steps['activate']['status'] = 'reverted';
                $run->steps = $steps;
                $notes[] = "Codul a revenit automat la versiunea {$previous}.";
                $this->journal->write($run, 'cod_revenit', ['versiune' => $previous]);
                $activated = false;
            }
        }

        if (in_array($step, ['extract', 'preflight'], true)) {
            // Nimic nu s-a modificat.
            $run->forceFill(['status' => 'failed', 'active_slot' => null]);
            $notes[] = 'Nicio modificare nu a fost făcută aplicației.';
        } elseif (! $dbChanged && ! $activated && ! in_array($step, ['maintenance_off', 'cleanup'], true)) {
            // Baza de date neatinsă: ștergem versiunea parțială și ieșim din mentenanță.
            $this->removeDirectory($this->layout->releasesPath($run->to_version).'.partial');
            if ($run->to_version !== ($this->layout->current()['release'] ?? null)) {
                $this->removeDirectory($this->layout->releasesPath($run->to_version));
            }
            if (app()->isDownForMaintenance()) {
                app()->maintenanceMode()->deactivate();
                $this->journal->write($run, 'mentenanta_oprita');
            }
            $run->forceFill(['status' => 'rolled_back', 'active_slot' => null, 'finished_at' => now()]);
            $notes[] = 'Baza de date nu a fost modificată; aplicația a rămas pe versiunea '.($this->layout->current()['release'] ?? $run->from_version).' și a ieșit din mentenanță.';
        } else {
            // Baza poate fi modificată: decizia rămâne a administratorului. Aplicația rămâne în mentenanță.
            $run->forceFill(['status' => 'failed']);
            $notes[] = 'Baza de date poate fi modificată parțial. Aplicația rămâne în mentenanță până alegeți o opțiune de recuperare.';
        }

        $run->forceFill(['error' => mb_substr($e->getMessage(), 0, 1500)."\n".implode(' ', $notes)])->save();
        $this->journal->write($run, 'esec', ['pas' => $step, 'eroare' => mb_substr($e->getMessage(), 0, 500)]);
        Audit::log('updates.failed', $run, ['pas' => $step, 'eroare' => mb_substr($e->getMessage(), 0, 300)]);
    }

    /**
     * Opțiunile reale de recuperare pentru o actualizare eșuată.
     *
     * @return array<string, array{label: string, detail: string, danger: bool}>
     */
    public function recoveryOptions(UpdateRun $run): array
    {
        if ($run->status !== 'failed') {
            return [];
        }
        $failedStep = collect($run->steps)->search(fn ($s) => ($s['status'] ?? null) === 'failed');
        $options = [];
        $current = $this->layout->current()['release'] ?? null;

        if ($failedStep && ! in_array($failedStep, ['extract', 'preflight'], true)) {
            $options['retry'] = ['label' => 'Reia pasul „'.UpdateRun::STEPS[$failedStep].'”', 'detail' => 'După ce ați remediat cauza (ex. spațiu, permisiuni). Pașii deja încheiați nu se repetă.', 'danger' => false];
        }
        $restore = $this->restoreCheck($run);
        if ($restore['possible']) {
            $options['restore_db'] = ['label' => 'Restaurează baza de date din backupul de dinainte', 'detail' => $restore['detail'], 'danger' => true];
        }
        if ($current === $run->to_version && ($previous = $run->step('activate')['data']['previous'] ?? null)) {
            $options['rollback_code'] = ['label' => "Revino la codul versiunii {$previous}", 'detail' => 'Schimbă doar codul. Dacă migrările au modificat baza, codul vechi poate să nu funcționeze corect fără restaurarea bazei.', 'danger' => true];
        }
        if (app()->isDownForMaintenance()) {
            $options['maintenance_off'] = ['label' => 'Ieși din mentenanță fără alte modificări', 'detail' => 'Doar dacă ați verificat că aplicația funcționează (ex. ați remediat manual).', 'danger' => true];
        }
        $options['close'] = ['label' => 'Închide incidentul (rezolvat manual)', 'detail' => 'Eliberează blocarea pentru o actualizare nouă. Nu modifică aplicația.', 'danger' => false];

        return $options;
    }

    /** Condițiile în care restaurarea backupului este sigură. */
    public function restoreCheck(UpdateRun $run): array
    {
        $backup = $run->backup;
        if (! $backup || $backup->status !== 'verified') {
            return ['possible' => false, 'detail' => 'Nu există un backup verificat pentru această actualizare.'];
        }
        $maintenanceSince = $run->step('maintenance_on')['data']['since'] ?? null;
        $backupStarted = $run->step('backup_db')['data']['started_at'] ?? null;
        $offAfter = collect($this->journal->events($run->uuid))
            ->contains(fn ($e) => $e['event'] === 'mentenanta_oprita' && $maintenanceSince && $e['at'] >= $maintenanceSince);
        if (! $maintenanceSince || ! $backupStarted || $backupStarted < $maintenanceSince || $offAfter || ! app()->isDownForMaintenance()) {
            return ['possible' => false, 'detail' => 'Nu se poate garanta că nu există scrieri după backup (mentenanța a fost întreruptă). Restaurarea se face doar manual, cu analiză.'];
        }

        return ['possible' => true, 'detail' => 'Backup din '.$backup->started_at->timezone(config('app.timezone'))->format('d.m.Y H:i:s')
            .'. Aplicația este în mentenanță continuu de la '.\Carbon\Carbon::parse($maintenanceSince)->timezone(config('app.timezone'))->format('d.m.Y H:i:s')
            .' (înainte de backup), deci nu s-au pierdut scrieri. Codul revine la versiunea '.$run->from_version.'.'];
    }

    public function retry(UpdateRun $run): UpdateRun
    {
        if ($run->status !== 'failed') {
            throw new RuntimeException('Doar o actualizare eșuată poate fi reluată.');
        }
        $steps = $run->steps;
        foreach ($steps as $key => $s) {
            if (($s['status'] ?? null) === 'failed') {
                $steps[$key]['status'] = 'running';
                unset($steps[$key]['error']);
            }
        }
        $run->forceFill(['steps' => $steps, 'status' => 'running', 'active_slot' => 1, 'error' => null])->save();
        $this->journal->write($run, 'reluare');
        Audit::log('updates.retried', $run);

        return $run;
    }

    /** Restaurează backupul de dinainte, pe bucăți. Întoarce true la final. */
    public function restoreDatabase(UpdateRun $run, ?float $deadline = null): bool
    {
        $lock = $this->acquireLock();
        try {
            $check = $this->restoreCheck($run);
            $steps = $run->steps;
            $state = $steps['restore_db']['data'] ?? null;
            if ($state === null && ! $check['possible']) {
                throw new RuntimeException($check['detail']);
            }
            $backup = $run->backup;
            $backupAttributes = $backup->getAttributes();
            $deadline ??= microtime(true) + (int) config('updates.step_time_budget', 15);
            if ($state === null) {
                $this->journal->write($run, 'restaurare_bd_pornita', ['backup' => $backup->uuid]);
                Audit::log('updates.db_restore_started', $run, ['backup' => $backup->uuid]);
            }
            $state = $this->backups->restoreStep($backup, $this->backups->connection(), $state, $deadline, exact: true);

            // Baza poate conține acum rândurile vechi ale acestor tabele: le rescriem din memorie/jurnal.
            $steps['restore_db'] = ['status' => $state['done'] ? 'done' : 'running', 'data' => $state['done'] ? ['executed' => $state['executed']] : $state];
            $run->steps = $steps;
            if ($state['done']) {
                if (($this->layout->current()['release'] ?? null) === $run->to_version && $run->from_version && is_dir($this->layout->releasesPath($run->from_version))) {
                    $this->layout->activate($run->from_version, $run->to_version);
                    $this->journal->write($run, 'cod_revenit', ['versiune' => $run->from_version]);
                }
                if (app()->isDownForMaintenance()) {
                    app()->maintenanceMode()->deactivate();
                }
                $run->forceFill(['status' => 'rolled_back', 'active_slot' => null, 'finished_at' => now(),
                    'error' => trim($run->error."\nBaza de date a fost restaurată din backupul {$backup->uuid}; aplicația rulează versiunea {$run->from_version}.")]);
            }
            $this->persistAfterRestore($run, $backupAttributes);
            if ($state['done']) {
                $this->journal->write($run, 'restaurare_bd_finalizata');
                Audit::log('updates.db_restored', $run, ['backup' => $backup->uuid, 'instructiuni' => $state['executed']]);
            }

            return $state['done'];
        } finally {
            $this->releaseLock($lock);
        }
    }

    public function rollbackCode(UpdateRun $run): void
    {
        $previous = $run->step('activate')['data']['previous'] ?? null;
        if (! $previous || ! is_dir($this->layout->releasesPath($previous))) {
            throw new RuntimeException('Versiunea anterioară nu mai este disponibilă.');
        }
        $this->layout->activate($previous, $run->to_version);
        $steps = $run->steps;
        $steps['activate']['status'] = 'reverted';
        $run->forceFill(['steps' => $steps])->save();
        $this->journal->write($run, 'cod_revenit', ['versiune' => $previous]);
        Audit::log('updates.code_rolled_back', $run, ['versiune' => $previous]);
    }

    public function maintenanceOff(UpdateRun $run): void
    {
        app()->maintenanceMode()->deactivate();
        $this->journal->write($run, 'mentenanta_oprita', ['manual' => true]);
        Audit::log('updates.maintenance_off_manual', $run);
    }

    public function close(UpdateRun $run): void
    {
        $run->forceFill(['active_slot' => null, 'finished_at' => $run->finished_at ?? now(),
            'error' => trim($run->error."\nIncident închis manual de administrator.")])->save();
        $this->journal->write($run, 'inchis_manual');
        Audit::log('updates.closed_manually', $run);
    }

    // ------------------------------------------------------------------ utilitare

    public function package(UpdateRun $run): InspectedPackage
    {
        $path = $this->layout->updatesPath($run->uuid.'/package.zip');
        $package = $this->inspector->inspect($path);
        if (! hash_equals($run->package_sha256, $package->sha256)) {
            throw new PackageException('Pachetul din staging nu mai corespunde celui verificat la încărcare.');
        }

        return $package;
    }

    private function complete(UpdateRun $run): UpdateRun
    {
        $run->forceFill(['status' => 'completed', 'active_slot' => null, 'finished_at' => now(), 'current_step' => null])->save();
        $this->journal->write($run, 'finalizat');
        Audit::log('updates.completed', $run, ['de_la' => $run->from_version, 'la' => $run->to_version]);

        return $run;
    }

    private function saveStep(UpdateRun $run, string $step, array $state, ?string $event = null): void
    {
        $steps = $run->steps;
        $steps[$step] = $state;
        $run->forceFill(['steps' => $steps, 'current_step' => $step])->save();
        $this->journal->write($run, $event);
    }

    /** Rescrie rândurile update_runs/backups după o restaurare (care le-a readus la starea din backup). */
    private function persistAfterRestore(UpdateRun $run, array $backupAttributes): void
    {
        $attributes = $run->getAttributes();
        DB::table('update_runs')->updateOrInsert(['uuid' => $run->uuid], $attributes);
        DB::table('backups')->updateOrInsert(['uuid' => $backupAttributes['uuid']], $backupAttributes);
        $run->exists = true;
        $run->syncOriginal();
    }

    private function stagingPath(UpdateRun $run): string
    {
        return $this->layout->updatesPath($run->uuid.'/staging');
    }

    private function copyVerified(string $from, string $to, string $sha256): void
    {
        if (! is_file($from) || is_link($from)) {
            throw new RuntimeException('Fișier lipsă în staging: '.$from);
        }
        if (! is_dir(dirname($to))) {
            mkdir(dirname($to), 0755, true);
        }
        $tmp = $to.'.tmp';
        $in = fopen($from, 'rb');
        $out = fopen($tmp, 'wb');
        $hash = hash_init('sha256');
        while (! feof($in)) {
            $chunk = fread($in, 1 << 16);
            hash_update($hash, $chunk);
            if (fwrite($out, $chunk) !== strlen($chunk)) {
                fclose($in);
                fclose($out);
                throw new RuntimeException('Scriere incompletă (spațiu insuficient?): '.$to);
            }
        }
        fclose($in);
        fclose($out);
        if (! hash_equals($sha256, hash_final($hash))) {
            @unlink($tmp);
            throw new RuntimeException('Suma de control nu corespunde după copiere: '.$to);
        }
        @chmod($tmp, str_ends_with($to, '/artisan') ? 0755 : 0644);
        rename($tmp, $to);
    }

    /** @return list<string> */
    private function filesUnder(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && ! $f->isLink()) {
                $out[] = substr($f->getPathname(), strlen($dir) + 1);
            }
        }
        sort($out);

        return $out;
    }

    /** @return resource */
    private function acquireLock()
    {
        $dir = $this->layout->updatesPath();
        if (! is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $fh = fopen($dir.'/.lock', 'c');
        if (! $fh || ! flock($fh, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Un alt pas al actualizării rulează chiar acum. Așteptați câteva secunde.');
        }

        return $fh;
    }

    private function releaseLock($fh): void
    {
        if (is_resource($fh)) {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (is_link($dir) || is_file($dir)) {
            @unlink($dir);

            return;
        }
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeDirectory($dir.'/'.$entry);
            }
        }
        @rmdir($dir);
    }
}
