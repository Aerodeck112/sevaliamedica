<?php

namespace App\Modules\Marketing\Models;

use App\Modules\Cms\Models\Page;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoAuditPage extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['issues' => 'array', 'metrics' => 'array', 'checked_at' => 'datetime'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(SeoAuditRun::class, 'seo_audit_run_id');
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function countBy(string $severity): int
    {
        return count(array_filter((array) $this->issues, fn ($i) => ($i['severity'] ?? '') === $severity));
    }
}
