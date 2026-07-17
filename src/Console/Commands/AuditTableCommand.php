<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Vusys\Bitemporal\BitemporalBuilder;

/**
 * Renders a human-readable timeline for one entity. Current knowledge by
 * default; the full physical history (including superseded rows) with --full.
 */
final class AuditTableCommand extends Command
{
    protected $signature = 'bitemporal:audit-table
        {--model= : FQCN of the temporal model}
        {--entity-id= : The entity key to scope to}
        {--full : Include superseded rows}';

    protected $description = 'Render a temporal entity timeline as a table';

    public function handle(): int
    {
        $class = $this->option('model');
        $entityId = $this->option('entity-id');

        if (! is_string($class) || ! class_exists($class) || ! is_a($class, Model::class, true)) {
            $this->error('Provide a valid --model FQCN.');

            return self::FAILURE;
        }

        if (! is_scalar($entityId)) {
            $this->error('--entity-id is required.');

            return self::FAILURE;
        }

        $entityId = (string) $entityId;

        $model = new $class;
        $query = $model->newQuery();

        if (! $query instanceof BitemporalBuilder || ! method_exists($model, 'temporalMetadata')) {
            $this->error("{$class} is not a temporal model.");

            return self::FAILURE;
        }

        $meta = $model->temporalMetadata();
        $query->where($this->foreignKey($model), '=', $entityId);

        $full = $this->option('full') === true;
        if (! $full) {
            $query->currentKnowledge();
        }

        $rows = $query->orderBy($meta->validFrom)->orderBy($meta->recordedFrom)->get();

        $headers = [$meta->validFrom, $meta->validTo, $meta->recordedFrom, $meta->recordedTo, $meta->isRetraction];

        $body = $rows->map(static function (Model $row) use ($headers): array {
            $cells = [];
            foreach ($headers as $column) {
                $value = $row->getAttribute($column);
                $cells[] = $value === null ? '—' : (is_scalar($value) ? (string) $value : (string) json_encode($value));
            }

            return $cells;
        })->all();

        $this->table($headers, $body);
        $this->info("{$class} #{$entityId}: {$rows->count()} row(s).");

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
