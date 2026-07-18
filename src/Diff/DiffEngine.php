<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Diff;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;
use Vusys\Bitemporal\Exceptions\TemporalDomainException;
use Vusys\Bitemporal\Support\AttributeEquality;
use Vusys\Bitemporal\Support\TemporalEntityMetadata;

/**
 * Compares two sets of believed rows and classifies each into added / removed /
 * changed / unchanged. Rows are matched by their (dimension tuple, valid_from)
 * key; "comparable" attributes are the value columns plus valid_to and the
 * retraction flag — recorded-time columns are ignored because they differ by
 * construction between two recorded dates.
 */
final class DiffEngine
{
    /**
     * @param  array<int, Model>  $from
     * @param  array<int, Model>  $to
     */
    public static function compare(array $from, array $to, TemporalEntityMetadata $meta): TemporalDiff
    {
        $fromByKey = self::keyByMatch($from, $meta);
        $toByKey = self::keyByMatch($to, $meta);

        /** @var Collection<int, Model> $added */
        $added = new Collection;
        /** @var Collection<int, Model> $removed */
        $removed = new Collection;
        /** @var Collection<int, TemporalDiffPair> $changed */
        $changed = new Collection;
        /** @var Collection<int, Model> $unchanged */
        $unchanged = new Collection;

        foreach ($toByKey as $key => $toRow) {
            if (! isset($fromByKey[$key])) {
                $added->push($toRow);

                continue;
            }

            $fromRow = $fromByKey[$key];
            $changedAttributes = self::changedAttributes($fromRow, $toRow, $meta);

            if ($changedAttributes === []) {
                $unchanged->push($toRow);

                continue;
            }

            $changed->push(new TemporalDiffPair($fromRow, $toRow, $changedAttributes));
        }

        foreach ($fromByKey as $key => $fromRow) {
            if (! isset($toByKey[$key])) {
                $removed->push($fromRow);
            }
        }

        return new TemporalDiff($added, $removed, $changed, $unchanged);
    }

    /**
     * @param  array<int, Model>  $rows
     * @return array<string, Model>
     */
    private static function keyByMatch(array $rows, TemporalEntityMetadata $meta): array
    {
        $keyed = [];
        foreach ($rows as $row) {
            $key = self::matchKey($row, $meta);

            if (isset($keyed[$key])) {
                // Two rows in one believed slice sharing (valid_from, dimensions)
                // can only arise from overlapping / re-segmented rows that
                // violate the non-overlap invariant. Overwriting would silently
                // drop the earlier row and could report two different timelines
                // as identical, so surface the bad state rather than hide it.
                throw TemporalDomainException::invariant(
                    "duplicate match key '{$key}' in a single believed slice (overlapping rows)",
                    'DiffEngine::keyByMatch',
                );
            }

            $keyed[$key] = $row;
        }

        return $keyed;
    }

    private static function matchKey(Model $row, TemporalEntityMetadata $meta): string
    {
        $parts = [self::instantKey($row->getAttribute($meta->validFrom))];

        foreach ($meta->dimensions as $dimension) {
            $parts[] = $dimension.'='.var_export($row->getAttribute($dimension), true);
        }

        return implode('|', $parts);
    }

    /**
     * @return array<int, string>
     */
    private static function changedAttributes(Model $from, Model $to, TemporalEntityMetadata $meta): array
    {
        $changed = [];

        foreach (self::comparableColumns($from, $to, $meta) as $column) {
            if (! AttributeEquality::equals($from->getAttribute($column), $to->getAttribute($column))) {
                $changed[] = $column;
            }
        }

        if (! AttributeEquality::equals($from->getAttribute($meta->isRetraction), $to->getAttribute($meta->isRetraction))) {
            $changed[] = $meta->isRetraction;
        }

        if (self::instantKey($from->getAttribute($meta->validTo)) !== self::instantKey($to->getAttribute($meta->validTo))) {
            $changed[] = $meta->validTo;
        }

        return $changed;
    }

    /**
     * Value columns common to either row: everything except the temporal,
     * entity-scope, and key columns.
     *
     * @return array<int, string>
     */
    private static function comparableColumns(Model $from, Model $to, TemporalEntityMetadata $meta): array
    {
        $reserved = array_filter([
            $from->getKeyName(),
            $meta->validFrom,
            $meta->validTo,
            $meta->recordedFrom,
            $meta->recordedTo,
            $meta->isRetraction,
            $from->getCreatedAtColumn(),
            $from->getUpdatedAtColumn(),
            ...$meta->dimensions,
            ...self::entityColumns($from),
        ], is_string(...));

        $columns = [...array_keys($from->getAttributes()), ...array_keys($to->getAttributes())];

        return array_values(array_unique(array_filter(
            $columns,
            static fn (string $column): bool => ! in_array($column, $reserved, true),
        )));
    }

    /**
     * @return array<int, string>
     */
    private static function entityColumns(Model $model): array
    {
        if (! method_exists($model, 'temporalEntityRelation')) {
            return [];
        }

        $relation = $model->temporalEntityRelation();

        if ($relation instanceof MorphTo) {
            return [$relation->getMorphType(), $relation->getForeignKeyName()];
        }

        if ($relation instanceof BelongsTo) {
            return [$relation->getForeignKeyName()];
        }

        return [];
    }

    private static function instantKey(mixed $value): string
    {
        if ($value === null) {
            return '∞';
        }

        if ($value instanceof CarbonInterface) {
            return $value->format('Y-m-d H:i:s.u');
        }

        return is_string($value) ? $value : var_export($value, true);
    }
}
