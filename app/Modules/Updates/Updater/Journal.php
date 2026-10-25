<?php

namespace App\Modules\Updates\Updater;

use App\Modules\Updates\Models\UpdateRun;
use App\Modules\Updates\Support\DeploymentLayout;

/**
 * Jurnalul JSON al unei actualizări (shared/updates/<uuid>/journal.json) + pointerul shared/updates/last.json.
 * Este independent de baza de date: rămâne corect după o restaurare a bazei și e citit de recovery.php.
 */
class Journal
{
    public function __construct(private readonly DeploymentLayout $layout) {}

    public function path(string $uuid): string
    {
        return $this->layout->updatesPath($uuid.'/journal.json');
    }

    public function read(string $uuid): array
    {
        $data = json_decode((string) @file_get_contents($this->path($uuid)), true);

        return is_array($data) ? $data : ['events' => []];
    }

    public function write(UpdateRun $run, ?string $event = null, array $context = []): void
    {
        $data = $this->read($run->uuid);
        $data['run'] = [
            'id' => $run->id,
            'uuid' => $run->uuid,
            'from_version' => $run->from_version,
            'to_version' => $run->to_version,
            'status' => $run->status,
            'current_step' => $run->current_step,
            'steps' => $run->steps,
            'error' => $run->error,
            'backup_id' => $run->backup_id,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ];
        if ($event !== null) {
            $data['events'][] = ['at' => gmdate('c'), 'event' => $event] + $context;
        }
        $this->atomicWrite($this->path($run->uuid), $data);
        $this->atomicWrite($this->layout->updatesPath('last.json'), ['uuid' => $run->uuid, 'updated_at' => gmdate('c')]);
    }

    /** @return list<array{at: string, event: string}> */
    public function events(string $uuid): array
    {
        return $this->read($uuid)['events'] ?? [];
    }

    private function atomicWrite(string $path, array $data): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0750, true);
        }
        $tmp = $path.'.tmp-'.bin2hex(random_bytes(3));
        file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        rename($tmp, $path);
    }
}
