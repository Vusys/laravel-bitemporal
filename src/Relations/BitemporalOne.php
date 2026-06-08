<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Vusys\Bitemporal\Concerns\HasTemporalWrites;
use Vusys\Bitemporal\Exceptions\TemporalCardinalityException;

/**
 * A one-to-one temporal relation. `sole()` returns null when no row matches
 * (or throws when the relation was created via bitemporalOneOrFail), and throws
 * TemporalCardinalityException when more than one row matches.
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends HasOne<TRelatedModel, TDeclaringModel>
 */
class BitemporalOne extends HasOne
{
    use HasTemporalWrites;

    private bool $requirePresence = false;

    public function requirePresence(): static
    {
        $this->requirePresence = true;

        return $this;
    }

    /**
     * @param  array<int, string>|string  $columns
     * @return TRelatedModel|null
     */
    #[\Override]
    public function sole($columns = ['*']): ?Model
    {
        try {
            return $this->query->sole(is_string($columns) ? [$columns] : $columns);
        } catch (TemporalCardinalityException $exception) {
            if ($exception->wasNoneFound() && ! $this->requirePresence) {
                return null;
            }

            throw $exception;
        }
    }
}
