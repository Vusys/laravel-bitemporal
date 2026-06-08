<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Lens;

use Carbon\CarbonImmutable;

/**
 * One point-in-time lens frame: the ambient validAt / knownAt defaults applied
 * to temporal reads that do not specify their own. A null axis means "no
 * ambient default for this axis".
 */
final readonly class LensFrame
{
    public function __construct(
        public ?CarbonImmutable $validAt,
        public ?CarbonImmutable $knownAt,
    ) {}
}
