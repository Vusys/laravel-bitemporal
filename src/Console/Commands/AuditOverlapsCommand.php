<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Console\Commands;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Vusys\Bitemporal\BitemporalBuilder;
use Vusys\Bitemporal\Spell;

/**
 * Production overlap audit. Loads current-known rows for the model, groups them
 * by (entity, dimensions) tuple, and reports any pair whose valid periods
 * intersect. Exits non-zero when overlaps are found, for use as a CI gate.
 */
final class AuditOverlapsCommand extends Command
{
    protected $signature = 'bitemporal:audit-overlaps {--model= : FQCN of the temporal model}';

    protected $description = 'Detect overlapping current-known rows for a temporal model';

    public function handle(): int
    {
        $class = $this->option('model');

        if (! is_string($class) || ! class_exists($class) || ! is_a($class, Model::class, true)) {
            $this->error('Provide a valid --model FQCN.');

            return self::FAILURE;
        }

        $model = new $class;
        $query = $model->newQuery();

        if (! $query instanceof BitemporalBuilder || ! method_exists($model, 'temporalMetadata')) {
            $this->error("{$class} is not a temporal model.");

            return self::FAILURE;
        }

        $meta = $model->temporalMetadata();
        $entityColumns = $this->entityColumns($model);

        $byTuple = [];
        foreach ($query->currentKnowledge()->get() as $row) {
            $byTuple[$this->tupleKey($row, $entityColumns, $meta->dimensions)][] = $row;
        }

        $overlaps = 0;
        foreach ($byTuple as $key => $rows) {
            $count = count($rows);
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    if ($this->spell($rows[$i], $meta->validFrom, $meta->validTo)->intersects($this->spell($rows[$j], $meta->validFrom, $meta->validTo))) {
                        $overlaps++;
                        $this->line("  overlap in tuple [{$key}] between #{$this->keyLabel($rows[$i])} and #{$this->keyLabel($rows[$j])}");
                    }
                }
            }
        }

        if ($overlaps > 0) {
            $this->error("{$overlaps} overlap(s) detected for {$class}.");

            return self::FAILURE;
        }

        $this->info("No overlaps detected for {$class}.");

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function entityColumns(Model $model): array
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

    /**
     * @param  array<int, string>  $entityColumns
     * @param  array<int, string>  $dimensions
     */
    private function tupleKey(Model $row, array $entityColumns, array $dimensions): string
    {
        $parts = [];
        foreach ([...$entityColumns, ...$dimensions] as $column) {
            $parts[] = $column.'='.var_export($row->getAttribute($column), true);
        }

        return implode('|', $parts);
    }

    private function keyLabel(Model $row): string
    {
        $key = $row->getKey();

        return is_scalar($key) ? (string) $key : get_debug_type($key);
    }

    private function spell(Model $row, string $fromColumn, string $toColumn): Spell
    {
        return new Spell($this->instant($row->getAttribute($fromColumn)), $this->instant($row->getAttribute($toColumn)));
    }

    private function instant(mixed $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return CarbonImmutable::instance($value);
        }

        return is_string($value) ? CarbonImmutable::parse($value) : null;
    }
}
