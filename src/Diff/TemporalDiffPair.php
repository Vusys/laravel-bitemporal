<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Diff;

use Illuminate\Database\Eloquent\Model;

/**
 * A single row that exists in both sides of a diff but whose comparable
 * attributes differ. Carries both representations so callers never lose the
 * "before" when inspecting the "after".
 */
final readonly class TemporalDiffPair
{
    /**
     * @param  array<int, string>  $changedAttributes  names of attributes whose value differs
     */
    public function __construct(
        public Model $from,
        public Model $to,
        public array $changedAttributes,
    ) {}
}
