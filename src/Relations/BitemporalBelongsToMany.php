<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Relations;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Vusys\Bitemporal\BitemporalBuilder;
use Vusys\Bitemporal\Events\TemporalWriteCommitted;
use Vusys\Bitemporal\Exceptions\TemporalCardinalityException;
use Vusys\Bitemporal\Exceptions\TemporalConfigurationException;
use Vusys\Bitemporal\Locking\WriteLocker;
use Vusys\Bitemporal\Support\TemporalEntityMetadata;
use Vusys\Bitemporal\Writers\BitemporalWriter;
use Vusys\Bitemporal\Writers\TimelineSplitter;

/**
 * A many-to-many relation whose pivot row is itself temporal. The pivot is a
 * timeline scoped to the (parent, related) tuple: the parent key is the entity
 * scope and the related key behaves like a built-in dimension. Reads return
 * pivot (assignment) rows; the standard attach/detach/sync helpers are disabled
 * because their non-temporal semantics would destroy history.
 *
 * @template TDeclaringModel of Model
 *
 * @extends HasMany<Model, TDeclaringModel>
 *
 * @mixin BitemporalBuilder<Model>
 */
class BitemporalBelongsToMany extends HasMany
{
    private bool $pivotResolved = false;

    /**
     * @param  Builder<Model>  $query  a stand-in query; replaced when the pivot is resolved via using()
     * @param  class-string<Model>  $relatedClass  the far model (e.g. Role)
     */
    public function __construct(
        Builder $query,
        Model $parent,
        private readonly string $foreignPivotKey,
        private readonly string $relatedPivotKey,
        string $parentKey,
        private readonly string $relatedClass,
    ) {
        parent::__construct($query, $parent, $query->getModel()->getTable().'.'.$foreignPivotKey, $parentKey);
    }

    /**
     * Bind the pivot model that backs this relation and (re)build the underlying
     * query against it, scoped to the parent.
     *
     * @param  class-string<Model>  $pivotClass
     */
    public function using(string $pivotClass): static
    {
        $pivot = new $pivotClass;

        if ($pivot->getConnectionName() === null) {
            $pivot->setConnection($this->parent->getConnectionName());
        }

        $this->related = $pivot;
        $this->query = $pivot->newQuery();
        $this->foreignKey = $pivot->getTable().'.'.$this->foreignPivotKey;
        $this->pivotResolved = true;

        if (static::$constraints) {
            $this->addConstraints();
        }

        return $this;
    }

    /**
     * Guard reads the same way writes are guarded. Until ->using() rebinds the
     * query onto the pivot model, $this->query is the far-model stand-in and
     * $this->foreignKey points at a column that usually does not exist, so a
     * read would hit the wrong table (SQL error, or silently wrong rows). These
     * are the read-execution entry points — lazy (getResults), direct/sole/first
     * (get), and eager (addEagerConstraints); none run during construction, so
     * the valid `bitemporalBelongsToMany(Related::class)->using(Pivot::class)`
     * chaining order is preserved.
     *
     * @return Collection<int, Model>
     */
    #[\Override]
    public function getResults()
    {
        $this->assertResolved();

        return parent::getResults();
    }

    /**
     * @param  array<int, string>  $columns
     * @return Collection<int, Model>
     */
    #[\Override]
    public function get($columns = ['*'])
    {
        $this->assertResolved();

        return parent::get($columns);
    }

    /**
     * @param  array<int, TDeclaringModel>  $models
     */
    #[\Override]
    public function addEagerConstraints(array $models): void
    {
        $this->assertResolved();

        parent::addEagerConstraints($models);
    }

    /**
     * Create (or replace over a window) an assignment of $related to the parent.
     * Accepts a closed window in one call; pass validTo: null for open-ended.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function attachFor(Model $related, CarbonInterface|string $validFrom, CarbonInterface|string|null $validTo = null, array $attributes = []): TemporalWriteCommitted
    {
        return $this->assignmentWriter($related)->correct($attributes, $validFrom, $validTo);
    }

    /**
     * Retroactively correct an existing assignment over a window. Throws if the
     * tuple has no existing rows — use attachFor to create one.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function correctAssignment(Model $related, CarbonInterface|string|null $validFrom = null, CarbonInterface|string|null $validTo = null, array $attributes = []): TemporalWriteCommitted
    {
        if (! $this->currentAssignmentQuery($related)->exists()) {
            throw TemporalCardinalityException::noAssignmentToCorrect($this->tupleLabel($related));
        }

        return $this->assignmentWriter($related)->correct($attributes, $validFrom, $validTo);
    }

    /**
     * End the open-ended assignment of $related at $validTo. Throws if there is
     * no open-ended current assignment to end.
     */
    public function detachAt(Model $related, CarbonInterface|string $validTo): TemporalWriteCommitted
    {
        $meta = $this->pivotMeta();

        $hasOpenEnded = $this->currentAssignmentQuery($related)
            ->whereNull($meta->validTo)
            ->where($meta->isRetraction, '=', false)
            ->exists();

        if (! $hasOpenEnded) {
            throw TemporalCardinalityException::noAssignmentToDetach($this->tupleLabel($related));
        }

        return $this->assignmentWriter($related)->endAt($validTo);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function attach(mixed $id, array $attributes = [], bool $touch = true): never
    {
        throw TemporalConfigurationException::disabledPivotMethod('attach', 'attachFor');
    }

    public function detach(mixed $ids = null, bool $touch = true): never
    {
        throw TemporalConfigurationException::disabledPivotMethod('detach', 'detachAt');
    }

    public function sync(mixed $ids, bool $detaching = true): never
    {
        throw TemporalConfigurationException::disabledPivotMethod('sync', 'attachFor / detachAt');
    }

    private function assignmentWriter(Model $related): BitemporalWriter
    {
        $this->assertResolved();

        /** @var Application $app */
        $app = app();

        $dimensions = [$this->relatedPivotKey => $related->getKey(), ...$this->pivotDimensionTuple()];

        return new BitemporalWriter(
            $this->related,
            $this->parent,
            $dimensions,
            $app->make(WriteLocker::class),
            new TimelineSplitter,
            $app->make(Dispatcher::class),
            entityScope: [$this->foreignPivotKey => $this->parent->getKey()],
            extraDimensionColumns: [$this->relatedPivotKey],
        );
    }

    /**
     * @return BitemporalBuilder<Model>
     */
    private function currentAssignmentQuery(Model $related): BitemporalBuilder
    {
        $this->assertResolved();

        $query = $this->related->newQuery();

        if (! $query instanceof BitemporalBuilder) {
            throw new TemporalConfigurationException($this->relatedClass.' pivot must use the Bitemporal trait');
        }

        return $query
            ->withoutLens()
            ->where($this->foreignPivotKey, '=', $this->parent->getKey())
            ->where($this->relatedPivotKey, '=', $related->getKey())
            // Scope by the pivot dimension tuple exactly as assignmentWriter()
            // does; without it the existence guard answers "is there an open
            // assignment?" across ALL dimension values, so it can pass for a
            // dimension (e.g. a `scope` column) that has no open row and let the
            // writer no-op or write an unexpected timeline (issue #75).
            ->forDimensions($this->pivotDimensionTuple())
            ->currentKnowledge();
    }

    /**
     * @return array<string, mixed>
     */
    private function pivotDimensionTuple(): array
    {
        return $this->query instanceof BitemporalBuilder ? $this->query->temporalDimensionTuple() : [];
    }

    private function pivotMeta(): TemporalEntityMetadata
    {
        $pivot = $this->related;

        if (! method_exists($pivot, 'temporalMetadata')) {
            throw new TemporalConfigurationException($this->relatedClass.' pivot must use the Bitemporal trait');
        }

        return $pivot->temporalMetadata();
    }

    private function tupleLabel(Model $related): string
    {
        return $this->foreignPivotKey.'='.$this->stringifyKey($this->parent->getKey())
            .', '.$this->relatedPivotKey.'='.$this->stringifyKey($related->getKey());
    }

    private function stringifyKey(mixed $key): string
    {
        return is_scalar($key) ? (string) $key : get_debug_type($key);
    }

    private function assertResolved(): void
    {
        if (! $this->pivotResolved) {
            throw new TemporalConfigurationException(
                'bitemporalBelongsToMany() requires ->using(PivotClass::class) before reads or writes',
            );
        }
    }
}
