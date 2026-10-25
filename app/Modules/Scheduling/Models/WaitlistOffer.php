<?php

namespace App\Modules\Scheduling\Models;

use App\Modules\Clinic\Models\StaffMember;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaitlistOffer extends Model
{
    public const OUTCOMES = ['in_asteptare' => 'În așteptarea răspunsului', 'acceptat' => 'Acceptat', 'refuzat' => 'Refuzat', 'expirat' => 'Expirat'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['slot_start' => 'immutable_datetime', 'slot_end' => 'immutable_datetime'];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(WaitlistEntry::class, 'waitlist_entry_id');
    }

    public function staffMember(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class);
    }
}
