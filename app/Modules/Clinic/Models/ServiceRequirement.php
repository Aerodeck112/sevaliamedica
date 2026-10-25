<?php

namespace App\Modules\Clinic\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** O resursă necesară unui serviciu: o sală de un anumit tip sau un aparat de un anumit tip. */
class ServiceRequirement extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
