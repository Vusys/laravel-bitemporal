<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Diff;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The result of comparing two believed valid-time states. Rows present only in
 * the "to" state are `added`; only in "from" are `removed`; present in both but
 * differing are `changed` (as TemporalDiffPair); identical in both are
 * `unchanged`. A window that newly became a retraction (an anti-row) between the
 * two knowledge dates — including one that appears only on the "to" side — is
 * `retracted` rather than changed/added, so consumers do not misread a
 * withdrawal (value → null, is_retraction → true) as a real value update.
 */
final readonly class TemporalDiff
{
    /**
     * @param  Collection<int, Model>  $added
     * @param  Collection<int, Model>  $removed
     * @param  Collection<int, TemporalDiffPair>  $changed
     * @param  Collection<int, TemporalRetraction>  $retracted  windows withdrawn between the two knowledge dates (both sides preserved)
     * @param  Collection<int, Model>  $unchanged
     */
    public function __construct(
        public Collection $added,
        public Collection $removed,
        public Collection $changed,
        public Collection $retracted,
        public Collection $unchanged,
    ) {}

    /**
     * True when the two states are identical (nothing added, removed, changed,
     * or retracted).
     */
    public function isEmpty(): bool
    {
        return $this->added->isEmpty()
            && $this->removed->isEmpty()
            && $this->changed->isEmpty()
            && $this->retracted->isEmpty();
    }
}
