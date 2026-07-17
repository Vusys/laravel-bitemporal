<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Console\Commands;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputOption;

/**
 * Generates a temporal Eloquent model wired with the Bitemporal trait and a
 * $temporalEntity class-string declaring the entity it versions.
 */
final class MakeBitemporalModelCommand extends GeneratorCommand
{
    protected $name = 'make:bitemporal-model';

    protected $description = 'Create a new temporal Eloquent model';

    protected $type = 'Model';

    protected function getStub(): string
    {
        return __DIR__.'/../../../stubs/bitemporal-model.stub';
    }

    #[\Override]
    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\Models';
    }

    #[\Override]
    protected function buildClass($name): string
    {
        $stub = parent::buildClass($name);

        $entity = $this->option('entity');
        $entity = is_string($entity) && $entity !== '' ? $entity : 'Model';
        $entity = class_basename($entity);

        // The natural key the library derives from the entity class and that
        // bitemporalForeignFor() emits (<entity>_id) — referenced in the stub's
        // doc comment so the generated model documents its own foreign key.
        $foreignKey = Str::snake($entity).'_id';

        return str_replace(
            ['{{ entity }}', '{{ foreignKey }}'],
            [$entity, $foreignKey],
            $stub,
        );
    }

    /**
     * @return array<int, array{0: string, 1: string|null, 2: int, 3: string}>
     */
    #[\Override]
    protected function getOptions(): array
    {
        return [
            ['entity', null, InputOption::VALUE_OPTIONAL, 'The entity class this model belongs to', 'Model'],
        ];
    }
}
