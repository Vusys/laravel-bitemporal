<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

/**
 * Generates a migration for a temporal table: bitemporalForeignFor() for the
 * entity, bitemporalPeriods(), and preventBitemporalOverlaps() scoped to the
 * entity + dimensions inferred from the model. The user fills in the domain
 * attribute columns.
 */
final class MakeBitemporalMigrationCommand extends Command
{
    protected $signature = 'make:bitemporal-migration {name : The migration name} {--model= : The temporal model to infer table/entity/dimensions from} {--temporal-only : Use temporalPeriod() instead of bitemporalPeriods()}';

    protected $description = 'Create a migration for a temporal table';

    public function handle(): int
    {
        $name = $this->argument('name');

        if (! is_string($name) || $name === '') {
            $this->error('A migration name is required.');

            return self::FAILURE;
        }

        [$table, $entity, $entityColumns, $dimensions] = $this->resolveModelShape();

        $stub = (string) file_get_contents($this->stubPath());
        $contents = str_replace(
            ['{{ table }}', '{{ entity }}', '{{ entityColumns }}', '{{ dimensions }}'],
            [$table, $entity, $entityColumns, $dimensions],
            $stub,
        );

        if ($this->option('temporal-only') === true) {
            $contents = str_replace('$table->bitemporalPeriods();', '$table->temporalPeriod();', $contents);
        }

        $path = $this->laravel->databasePath('migrations/'.Date::now()->format('Y_m_d_His').'_'.Str::snake($name).'.php');

        $this->files()->ensureDirectoryExists(\dirname($path));
        $this->files()->put($path, $contents);

        $this->info('Created migration: '.$path);

        return self::SUCCESS;
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private function resolveModelShape(): array
    {
        $model = $this->option('model');

        if (! is_string($model) || $model === '') {
            return ['temporal_table', 'Model', "'entity_id'", ''];
        }

        $class = str_contains($model, '\\') ? $model : 'App\\Models\\'.$model;

        if (! class_exists($class) || ! is_a($class, Model::class, true)) {
            return [Str::snake(Str::pluralStudly(class_basename($model))), class_basename($model), "'entity_id'", ''];
        }

        $instance = new $class;
        $table = $instance->getTable();

        $entityColumns = ['entity_id'];
        $entityClass = 'Model';

        if (method_exists($instance, 'temporalEntity')) {
            $relation = $instance->temporalEntity();

            if ($relation instanceof MorphTo) {
                $entityColumns = [$relation->getMorphType(), $relation->getForeignKeyName()];
            } elseif ($relation instanceof BelongsTo) {
                $entityColumns = [$relation->getForeignKeyName()];
                $entityClass = class_basename($relation->getRelated());
            }
        }

        $dimensions = method_exists($instance, 'temporalDimensions') ? $instance->temporalDimensions() : [];

        return [
            $table,
            $entityClass,
            $this->quoteList($entityColumns),
            $this->quoteList($dimensions),
        ];
    }

    /**
     * @param  array<int, string>  $values
     */
    private function quoteList(array $values): string
    {
        return implode(', ', array_map(static fn (string $value): string => "'{$value}'", $values));
    }

    private function stubPath(): string
    {
        $published = $this->laravel->basePath('stubs/vendor/bitemporal/bitemporal-migration.stub');

        return file_exists($published) ? $published : __DIR__.'/../../../stubs/bitemporal-migration.stub';
    }

    private function files(): Filesystem
    {
        return $this->laravel->make(Filesystem::class);
    }
}
