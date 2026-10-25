<?php

namespace App\Modules\Scheduling\Events;

use App\Modules\Scheduling\Models\ServiceRecord;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un serviciu efectuat a fost înregistrat (action = recorded) sau anulat/corectat (action = cancelled).
 * Ascultat de Rehab (consumul ședințelor), Billing (de încasat) și Cas (servicii de raportat/corectat).
 */
class ServiceRecordChanged
{
    use Dispatchable;

    public function __construct(public readonly ServiceRecord $record, public readonly string $action) {}
}
