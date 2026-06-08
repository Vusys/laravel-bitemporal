<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Vusys\Bitemporal\Concerns\HasTemporalWrites;

/**
 * A one-to-many temporal relation. Behaves like HasMany but its underlying
 * query is a BitemporalBuilder, so temporal read predicates (validAt, knownAt,
 * currentKnowledge, …) chain directly off the relation, and it carries the
 * temporal write API.
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends HasMany<TRelatedModel, TDeclaringModel>
 */
class BitemporalMany extends HasMany
{
    use HasTemporalWrites;

    /**
     * Delegate to the temporal builder so cardinality violations raise
     * TemporalCardinalityException rather than Laravel's relation exceptions.
     *
     * @param  array<int, string>|string  $columns
     * @return TRelatedModel
     */
    #[\Override]
    public function sole($columns = ['*']): Model
    {
        return $this->query->sole(is_string($columns) ? [$columns] : $columns);
    }
}
