<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Console\Commands;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputOption;

/**
 * Generates a model factory extending BitemporalFactory, which provides the
 * temporal states (validFrom, openEnded, retracted, …) out of the box.
 */
final class MakeBitemporalFactoryCommand extends GeneratorCommand
{
    protected $name = 'make:bitemporal-factory';

    protected $description = 'Create a new temporal model factory';

    protected $type = 'Factory';

    protected function getStub(): string
    {
        return __DIR__.'/../../../stubs/bitemporal-factory.stub';
    }

    #[\Override]
    protected function buildClass($name): string
    {
        $stub = parent::buildClass($name);

        $modelOption = $this->option('model');
        $namespacedModel = is_string($modelOption) && $modelOption !== ''
            ? $this->qualifyModel($modelOption)
            : $this->qualifyModel(Str::replaceLast('Factory', '', class_basename($name)));

        $namespace = $this->getNamespace(
            Str::replaceFirst($this->rootNamespace(), 'Database\\Factories\\', $this->qualifyClass($this->getNameInput())),
        );

        return str_replace(
            ['{{ factoryNamespace }}', '{{ namespacedModel }}', '{{ model }}'],
            [$namespace, $namespacedModel, class_basename($namespacedModel)],
            $stub,
        );
    }

    #[\Override]
    protected function getPath($name): string
    {
        $name = (string) Str::of($name)->replaceFirst($this->rootNamespace(), '')->finish('Factory');

        return $this->laravel->databasePath().'/factories/'.str_replace('\\', '/', $name).'.php';
    }

    /**
     * @return array<int, array{0: string, 1: string|null, 2: int, 3: string}>
     */
    #[\Override]
    protected function getOptions(): array
    {
        return [
            ['model', null, InputOption::VALUE_OPTIONAL, 'The temporal model this factory builds', ''],
        ];
    }
}
