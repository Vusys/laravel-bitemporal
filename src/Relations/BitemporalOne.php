<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Relations;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Vusys\Bitemporal\Concerns\HasTemporalWrites;
use Vusys\Bitemporal\Exceptions\TemporalCardinalityException;

/**
 * A one-to-one temporal relation. `sole()` returns null when no row matches
 * (or throws when the relation was created via bitemporalOneOrFail), and throws
 * TemporalCardinalityException when more than one row matches.
 *
 * A single-result relation is only meaningful once the timeline is narrowed to
 * one row — pin it with `validAt()`/`knownAt()`/`currentKnowledge()` or read it
 * inside an `asOf()` lens frame. When more than one row still matches (e.g. an
 * unpinned read of a whole timeline), `getResults()` would otherwise return an
 * arbitrary row; this relation forces a deterministic order (latest valid
 * period, then latest belief, then key) so the result is at least stable and
 * reproducible rather than storage-order dependent.
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

    private bool $deterministicOrderApplied = false;

    public function requirePresence(): static
    {
        $this->requirePresence = true;

        return $this;
    }

    /**
     * @return TRelatedModel|null
     */
    #[\Override]
    public function getResults()
    {
        $this->applyDeterministicOrder();

        return parent::getResults();
    }

    /**
     * @param  array<int, string>  $columns
     * @return Collection<int, TRelatedModel>
     */
    #[\Override]
    public function get($columns = ['*'])
    {
        $this->applyDeterministicOrder();

        return parent::get($columns);
    }

    /**
     * Pin a total order so the "one" of a multi-row match is reproducible
     * instead of storage-order dependent. Applied once; any user-supplied
     * ordering still wins because it was appended to the query first.
     */
    private function applyDeterministicOrder(): void
    {
        if ($this->deterministicOrderApplied) {
            return;
        }

        $this->deterministicOrderApplied = true;

        $model = $this->query->getModel();

        if (! method_exists($model, 'temporalMetadata')) {
            return;
        }

        $meta = $model->temporalMetadata();
        $table = $model->getTable();

        $this->query->orderBy($table.'.'.$meta->validFrom, 'desc');

        if ($meta->tracksRecordedTime) {
            $this->query->orderBy($table.'.'.$meta->recordedFrom, 'desc');
        }

        $this->query->orderBy($table.'.'.$model->getKeyName(), 'desc');
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
