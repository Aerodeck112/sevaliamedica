<?php

namespace App\Modules\Cms\Services;

use App\Modules\Cms\Models\Page;
use RuntimeException;

/** Pagina a fost salvată de altcineva după ce editorul curent a deschis-o. */
class EditConflict extends RuntimeException
{
    public function __construct(public readonly Page $page)
    {
        parent::__construct('Pagina a fost modificată între timp.');
    }
}
