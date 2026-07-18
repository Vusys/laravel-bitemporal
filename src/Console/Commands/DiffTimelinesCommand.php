<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Vusys\Bitemporal\BitemporalBuilder;

/**
 * CLI form of $relation->diffTimelines(): compares the believed valid-time
 * timeline for one entity at two recorded dates.
 */
final class DiffTimelinesCommand extends Command
{
    protected $signature = 'bitemporal:diff-timelines
        {--model= : FQCN of the temporal model}
        {--entity-id= : The entity key to scope to}
        {--from-known-at= : Earlier recorded date}
        {--to-known-at= : Later recorded date}';

    protected $description = 'Diff a temporal entity timeline between two recorded dates';

    public function handle(): int
    {
        $class = $this->option('model');
        $entityId = $this->option('entity-id');
        $fromKnownAt = $this->option('from-known-at');
        $toKnownAt = $this->option('to-known-at');

        if (! is_string($class) || ! class_exists($class) || ! is_a($class, Model::class, true)) {
            $this->error('Provide a valid --model FQCN.');

            return self::FAILURE;
        }

        if (! is_scalar($entityId) || ! is_string($fromKnownAt) || ! is_string($toKnownAt)) {
            $this->error('--entity-id, --from-known-at and --to-known-at are required.');

            return self::FAILURE;
        }

        $entityId = (string) $entityId;

        $model = new $class;
        $query = $model->newQuery();

        if (! $query instanceof BitemporalBuilder) {
            $this->error("{$class} is not a temporal model.");

            return self::FAILURE;
        }

        $query->where($this->foreignKey($model), '=', $entityId);

        $diff = $query->diffTimelines($fromKnownAt, $toKnownAt);

        $this->line("added: {$diff->added->count()}, removed: {$diff->removed->count()}, changed: {$diff->changed->count()}, retracted: {$diff->retracted->count()}, unchanged: {$diff->unchanged->count()}");

        foreach ($diff->changed as $pair) {
            $this->line('  changed ['.implode(', ', $pair->changedAttributes).']');
        }

        return self::SUCCESS;
    }

    private function foreignKey(Model $model): string
    {
        if (method_exists($model, 'temporalEntityRelation')) {
            $relation = $model->temporalEntityRelation();

            if ($relation instanceof MorphTo || $relation instanceof BelongsTo) {
                return $relation->getForeignKeyName();
            }
        }

        return 'id';
    }
}
