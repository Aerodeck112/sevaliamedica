<?php

namespace App\Modules\Cms\Events;

use App\Modules\Cms\Models\Page;
use Illuminate\Foundation\Events\Dispatchable;

/** O pagină a fost publicată sau retrasă de pe site (ex. pentru notificarea motoarelor de căutare). */
final class PageVisibilityChanged
{
    use Dispatchable;

    public function __construct(public readonly Page $page, public readonly bool $published) {}
}
