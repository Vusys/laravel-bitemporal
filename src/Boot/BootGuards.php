<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Boot;

use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use Vusys\Bitemporal\Boot\Guards\BootGuardDimensions;
use Vusys\Bitemporal\Boot\Guards\BootGuardNewCollection;
use Vusys\Bitemporal\Boot\Guards\BootGuardNewEloquentBuilder;
use Vusys\Bitemporal\Boot\Guards\BootGuardRelationType;
use Vusys\Bitemporal\Boot\Guards\BootGuardSoftDeletes;
use Vusys\Bitemporal\Exceptions\TemporalConfigurationException;

/**
 * Runs the per-model boot guards and collects every failure into one
 * TemporalConfigurationException, each line prefixed by the guard that raised
 * it. Guards are strict: a failure blocks boot. Advisory checks are lints
 * (BootLints), which never block.
 */
final readonly class BootGuards
{
    /**
     * @param  array<int, BootGuard>  $guards
     */
    public function __construct(private array $guards) {}

    public static function default(): self
    {
        return new self([
            new BootGuardSoftDeletes,
            new BootGuardRelationType,
            new BootGuardNewEloquentBuilder,
            new BootGuardNewCollection,
            new BootGuardDimensions,
        ]);
    }

    public function runAgainst(Model $model): void
    {
        $failures = [];

        foreach ($this->guards as $guard) {
            $message = $guard->check($model);

            if ($message !== null) {
                $failures[new ReflectionClass($guard)->getShortName()] = $message;
            }
        }

        if ($failures !== []) {
            throw TemporalConfigurationException::guardFailures($model::class, $failures);
        }
    }
}
