<?php

declare(strict_types=1);

namespace Bitemporal\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A one-to-many temporal relation. Behaves like HasMany but its underlying
 * query is a BitemporalBuilder, so temporal read predicates (validAt, knownAt,
 * currentKnowledge, …) chain directly off the relation.
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends HasMany<TRelatedModel, TDeclaringModel>
 */
class BitemporalMany extends HasMany
{
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
