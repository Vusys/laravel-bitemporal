<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Testing;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use PHPUnit\Framework\Assert;
use Throwable;
use Vusys\Bitemporal\BitemporalBuilder;
use Vusys\Bitemporal\Exceptions\TemporalConfigurationException;
use Vusys\Bitemporal\Exceptions\TemporalException;
use Vusys\Bitemporal\Spell;
use Vusys\Bitemporal\Support\AttributeEquality;
use Vusys\Bitemporal\Support\TemporalEntityMetadata;

/**
 * PHPUnit assertions for temporal models. Mix into any test class. Pest
 * projects use the auto-registered expectations instead.
 *
 * @see PestExpectations
 */
trait InteractsWithTimelines
{
    /**
     * Assert the attributes of the single row valid at $validAt and (optionally)
     * known at $knownAt.
     *
     * @param  BitemporalBuilder<*>|Relation<*, *, *>  $relation
     * @param  array<string, mixed>  $attributes
     */
    protected function assertTemporalAttributes(BitemporalBuilder|Relation $relation, CarbonInterface|string $validAt, CarbonInterface|string|null $knownAt = null, array $attributes = []): void
    {
        $query = $this->temporalQuery($relation)->validAt($validAt);
        $query = $knownAt === null ? $query->currentKnowledge() : $query->knownAt($knownAt);

        $row = $query->first();

        Assert::assertNotNull($row, 'Expected a temporal row valid at the given instant, found none.');

        foreach ($attributes as $key => $expected) {
            Assert::assertTrue(
                AttributeEquality::equals($row->getAttribute($key), $expected),
                "Temporal attribute [{$key}] did not match. Expected ".var_export($expected, true).', got '.var_export($row->getAttribute($key), true).'.',
            );
        }
    }

    /**
     * Assert the current-known timeline matches the expected rows positionally,
     * ordered by valid_from ASC. With includeSuperseded the full physical
     * history is asserted, ordered valid_from ASC, recorded_from ASC.
     *
     * @param  BitemporalBuilder<*>|Relation<*, *, *>  $relation
     * @param  array<int, array<string, mixed>>  $expected
     */
    protected function assertTemporalTimeline(BitemporalBuilder|Relation $relation, array $expected, bool $includeSuperseded = false): void
    {
        $rows = $this->orderedTimelineRows($relation, $includeSuperseded);

        Assert::assertCount(count($expected), $rows, 'Timeline row count did not match the expected timeline.');

        foreach ($expected as $index => $expectedRow) {
            $this->assertRowMatches($rows[$index], $expectedRow, "timeline position {$index}");
        }
    }

    /**
     * Set-equality variant of assertTemporalTimeline (order-independent).
     *
     * @param  BitemporalBuilder<*>|Relation<*, *, *>  $relation
     * @param  array<int, array<string, mixed>>  $expected
     */
    protected function assertTemporalTimelineUnordered(BitemporalBuilder|Relation $relation, array $expected, bool $includeSuperseded = false): void
    {
        $rows = $this->orderedTimelineRows($relation, $includeSuperseded);

        Assert::assertCount(count($expected), $rows, 'Timeline row count did not match the expected timeline.');

        $remaining = $rows;
        foreach ($expected as $expectedRow) {
            $matchIndex = null;
            foreach ($remaining as $index => $row) {
                if ($this->rowMatches($row, $expectedRow)) {
                    $matchIndex = $index;

                    break;
                }
            }

            Assert::assertNotNull($matchIndex, 'No current-known row matched expected '.var_export($expectedRow, true).'.');
            unset($remaining[$matchIndex]);
        }
    }

    /**
     * Assert no two current-known rows in the same (entity, dimensions) tuple
     * overlap on the valid axis.
     *
     * @param  class-string<Model>  $modelClass
     */
    protected function assertNoTemporalOverlaps(string $modelClass): void
    {
        $this->assertNoOverlaps($modelClass, currentKnownOnly: true, bitemporal: false);
    }

    /**
     * Stronger: assert no two physical rows (current or superseded) in the same
     * tuple overlap on BOTH the valid and recorded axes.
     *
     * @param  class-string<Model>  $modelClass
     */
    protected function assertNoBitemporalOverlaps(string $modelClass): void
    {
        $this->assertNoOverlaps($modelClass, currentKnownOnly: false, bitemporal: true);
    }

    /**
     * Assert each (entity, dimensions) tuple under $entity has at most one
     * open-ended current-known row.
     */
    protected function assertExactlyOneOpenEndedCurrentKnownPerDimensionTuple(Model $entity): void
    {
        $relations = [];
        foreach ($this->temporalRelationsOf($entity) as $relation) {
            $relations[] = $relation;
        }

        Assert::assertNotEmpty($relations, $entity::class.' declares no temporal relations.');

        foreach ($relations as $relation) {
            $meta = $this->metaFor($relation->getModel());
            $openEndedByTuple = [];

            foreach ($relation->currentKnowledge()->get() as $row) {
                if ($row->getAttribute($meta->validTo) !== null) {
                    continue;
                }

                $key = $this->tupleKey($row, $meta);
                $openEndedByTuple[$key] = ($openEndedByTuple[$key] ?? 0) + 1;
            }

            foreach ($openEndedByTuple as $key => $count) {
                Assert::assertLessThanOrEqual(1, $count, "Tuple [{$key}] has {$count} open-ended current-known rows; expected at most one.");
            }
        }
    }

    /**
     * @param  class-string<TemporalException>  $exception
     */
    protected function expectTemporalException(string $exception, ?string $messageSubstring = null): void
    {
        $this->expectException($exception);

        if ($messageSubstring !== null) {
            $this->expectExceptionMessageMatches('/'.preg_quote($messageSubstring, '/').'/');
        }
    }

    /**
     * Run $callback and assert it raised a TemporalConfigurationException whose
     * collected guard failures include the named guard class.
     *
     * @param  class-string  $guard
     * @param  callable():mixed  $callback
     */
    protected function expectGuardFailure(string $guard, callable $callback): void
    {
        $shortName = new \ReflectionClass($guard)->getShortName();

        try {
            $callback();
        } catch (TemporalConfigurationException $exception) {
            Assert::assertStringContainsString(
                "[{$shortName}]",
                $exception->getMessage(),
                "TemporalConfigurationException was thrown but did not include the [{$shortName}] guard failure.",
            );

            return;
        }

        Assert::fail("Expected a TemporalConfigurationException including the [{$shortName}] guard failure; none was thrown.");
    }

    /**
     * @param  BitemporalBuilder<*>|Relation<*, *, *>  $relation
     * @return array<int, Model>
     */
    private function orderedTimelineRows(BitemporalBuilder|Relation $relation, bool $includeSuperseded): array
    {
        $query = $this->temporalQuery($relation);
        $meta = $this->metaFor($query->getModel());

        if ($includeSuperseded) {
            return $query
                ->orderBy($meta->validFrom)
                ->orderBy($meta->recordedFrom)
                ->get()
                ->all();
        }

        return $query
            ->currentKnowledge()
            ->orderBy($meta->validFrom)
            ->get()
            ->all();
    }

    /**
     * Normalise a temporal relation or builder to a BitemporalBuilder carrying
     * the relation's entity-scoping constraints.
     *
     * @param  BitemporalBuilder<*>|Relation<*, *, *>  $relation
     * @return BitemporalBuilder<Model>
     */
    private function temporalQuery(BitemporalBuilder|Relation $relation): BitemporalBuilder
    {
        $query = $relation instanceof Relation ? $relation->getQuery() : $relation;

        if (! $query instanceof BitemporalBuilder) {
            throw new \LogicException('The given relation is not backed by a BitemporalBuilder.');
        }

        /** @var BitemporalBuilder<Model> $query */
        return $query;
    }

    /**
     * @param  array<string, mixed>  $expected
     */
    private function assertRowMatches(Model $row, array $expected, string $where): void
    {
        Assert::assertTrue(
            $this->rowMatches($row, $expected),
            "Temporal row at {$where} did not match expected ".var_export($expected, true).'.',
        );
    }

    /**
     * @param  array<string, mixed>  $expected
     */
    private function rowMatches(Model $row, array $expected): bool
    {
        $meta = $this->metaFor($row);
        $isRetraction = (bool) ($expected[$meta->isRetraction] ?? $expected['is_retraction'] ?? false);

        $valueKeys = array_filter(
            array_keys($expected),
            fn (string $key): bool => ! in_array($key, [$meta->validFrom, $meta->validTo, $meta->recordedFrom, $meta->recordedTo, $meta->isRetraction, 'valid_from', 'valid_to', 'recorded_from', 'recorded_to', 'is_retraction'], true),
        );

        if ($isRetraction && $valueKeys !== []) {
            throw new \LogicException('A retracted expectation row may not also assert attribute values.');
        }

        if ($isRetraction !== (bool) $row->getAttribute($meta->isRetraction)) {
            return false;
        }

        foreach ([$meta->validFrom => 'valid_from', $meta->validTo => 'valid_to', $meta->recordedFrom => 'recorded_from', $meta->recordedTo => 'recorded_to'] as $column => $alias) {
            if (! array_key_exists($column, $expected) && ! array_key_exists($alias, $expected)) {
                continue;
            }

            $expectedValue = $expected[$column] ?? $expected[$alias] ?? null;
            if (! $this->instantsEqual($row->getAttribute($column), $expectedValue)) {
                return false;
            }
        }

        return array_all($valueKeys, fn (string $key): bool => AttributeEquality::equals($row->getAttribute($key), $expected[$key]));
    }

    private function instantsEqual(mixed $actual, mixed $expected): bool
    {
        $actualInstant = $this->instant($actual);
        $expectedInstant = $this->instant($expected);

        if ($actualInstant === null || $expectedInstant === null) {
            return $actualInstant === $expectedInstant;
        }

        return $actualInstant->equalTo($expectedInstant);
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function assertNoOverlaps(string $modelClass, bool $currentKnownOnly, bool $bitemporal): void
    {
        $model = new $modelClass;
        $meta = $this->metaFor($model);

        $query = $model->newQuery();
        if ($currentKnownOnly && $query instanceof BitemporalBuilder) {
            $query->currentKnowledge();
        }

        $byTuple = [];
        foreach ($query->get() as $row) {
            $byTuple[$this->tupleKey($row, $meta)][] = $row;
        }

        $overlaps = [];
        foreach ($byTuple as $key => $rows) {
            $count = count($rows);
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    if ($this->rowsOverlap($rows[$i], $rows[$j], $meta, $bitemporal)) {
                        $overlaps[] = $key;
                    }
                }
            }
        }

        Assert::assertSame(
            [],
            $overlaps,
            $overlaps === [] ? '' : 'Temporal overlap detected in '.$modelClass.' tuple(s): '.implode(', ', array_unique($overlaps)).'.',
        );
    }

    private function rowsOverlap(Model $a, Model $b, TemporalEntityMetadata $meta, bool $bitemporal): bool
    {
        $validA = $this->spellFrom($a, $meta->validFrom, $meta->validTo);
        $validB = $this->spellFrom($b, $meta->validFrom, $meta->validTo);

        if (! $validA->intersects($validB)) {
            return false;
        }

        if (! $bitemporal || ! $meta->tracksRecordedTime) {
            return true;
        }

        $recordedA = $this->spellFrom($a, $meta->recordedFrom, $meta->recordedTo);
        $recordedB = $this->spellFrom($b, $meta->recordedFrom, $meta->recordedTo);

        return $recordedA->intersects($recordedB);
    }

    private function spellFrom(Model $model, string $fromColumn, string $toColumn): Spell
    {
        return new Spell(
            $this->instant($model->getAttribute($fromColumn)),
            $this->instant($model->getAttribute($toColumn)),
        );
    }

    private function instant(mixed $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return CarbonImmutable::instance($value);
        }

        if (is_string($value)) {
            return CarbonImmutable::parse($value);
        }

        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance(CarbonImmutable::createFromInterface($value));
        }

        throw new \LogicException('Expected a date/datetime value; got '.get_debug_type($value).'.');
    }

    private function tupleKey(Model $row, TemporalEntityMetadata $meta): string
    {
        $parts = [];
        foreach ($this->entityColumns($row) as $column) {
            $parts[] = $column.'='.var_export($row->getAttribute($column), true);
        }

        foreach ($meta->dimensions as $dimension) {
            $parts[] = $dimension.'='.var_export($row->getAttribute($dimension), true);
        }

        return implode('|', $parts);
    }

    /**
     * @return array<int, string>
     */
    private function entityColumns(Model $model): array
    {
        if (! method_exists($model, 'temporalEntity')) {
            return [];
        }

        $relation = $model->temporalEntity();

        if ($relation instanceof MorphTo) {
            return [$relation->getMorphType(), $relation->getForeignKeyName()];
        }

        if ($relation instanceof BelongsTo) {
            return [$relation->getForeignKeyName()];
        }

        return [];
    }

    /**
     * Discover the entity's temporal relations by reflection. Only zero-argument
     * methods whose declared return type is an Eloquent Relation are invoked, so
     * unrelated model methods (delete(), save(), …) are never called.
     *
     * @return iterable<int, BitemporalBuilder<Model>>
     */
    private function temporalRelationsOf(Model $entity): iterable
    {
        $reflection = new \ReflectionClass($entity);

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic()) {
                continue;
            }
            if ($method->getNumberOfRequiredParameters() > 0) {
                continue;
            }
            $returnType = $method->getReturnType();
            if (! $returnType instanceof \ReflectionNamedType) {
                continue;
            }
            if ($returnType->isBuiltin()) {
                continue;
            }

            if (! is_a($returnType->getName(), Relation::class, true)) {
                continue;
            }

            try {
                $relation = $entity->{$method->getName()}();
            } catch (Throwable) {
                continue;
            }

            if (! $relation instanceof Relation) {
                continue;
            }

            $query = $relation->getQuery();
            if ($query instanceof BitemporalBuilder) {
                yield $query;
            }
        }
    }

    private function metaFor(Model $model): TemporalEntityMetadata
    {
        if (! method_exists($model, 'temporalMetadata')) {
            throw new \LogicException($model::class.' is not a temporal model.');
        }

        return $model->temporalMetadata();
    }
}
