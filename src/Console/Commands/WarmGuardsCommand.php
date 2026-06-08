<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Throwable;
use Vusys\Bitemporal\Boot\BootGuards;

/**
 * Runs the temporal boot guards against the given model classes and exits
 * non-zero if any fail — useful as a deploy/CI gate so misconfiguration is
 * caught before traffic hits the models.
 */
final class WarmGuardsCommand extends Command
{
    protected $signature = 'bitemporal:warm-guards {models* : Fully-qualified temporal model class names}';

    protected $description = 'Validate the temporal configuration of the given models';

    public function handle(): int
    {
        $guards = BootGuards::default();
        $failed = false;

        /** @var array<int, string> $models */
        $models = $this->argument('models');

        foreach ($models as $class) {
            try {
                if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                    throw new \RuntimeException('not an Eloquent model class');
                }

                $guards->runAgainst(new $class);
                $this->components->info($class.' passed');
            } catch (Throwable $exception) {
                $this->components->error($class.': '.$exception->getMessage());
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
