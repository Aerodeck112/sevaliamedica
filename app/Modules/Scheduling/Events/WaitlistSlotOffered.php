<?php

namespace App\Modules\Scheduling\Events;

use App\Modules\Scheduling\Models\WaitlistOffer;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Recepția a oferit un interval eliberat unui pacient din lista de așteptare.
 * Modulul Notifications ascultă acest eveniment pentru a trimite efectiv mesajul (fără date medicale).
 */
class WaitlistSlotOffered
{
    use Dispatchable;

    public function __construct(public readonly WaitlistOffer $offer) {}
}
