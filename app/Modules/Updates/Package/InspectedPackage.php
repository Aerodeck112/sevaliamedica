<?php

namespace App\Modules\Updates\Package;

/** Un pachet cu semnătura și structura deja verificate. */
final class InspectedPackage
{
    public function __construct(
        public readonly string $path,
        public readonly Manifest $manifest,
        public readonly string $keyId,
        public readonly string $sha256,
    ) {}
}
