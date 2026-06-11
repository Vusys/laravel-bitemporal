<?php

declare(strict_types=1);

namespace Vusys\Bitemporal;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Vusys\Bitemporal\Boot\BootGuards;
use Vusys\Bitemporal\Boot\BootLints;
use Vusys\Bitemporal\Collections\BitemporalCollection;
use Vusys\Bitemporal\Concerns\HasTemporalCasts;
use Vusys\Bitemporal\Concerns\HasTemporalEntity;
use Vusys\Bitemporal\Lens\LensStack;

/**
 * Marks an Eloquent model as temporal. The model must define a
 * `temporalEntity()` relation (BelongsTo or MorphTo).
 *
 * @phpstan-require-extends Model
 */
trait Bitemporal
{
    use HasTemporalCasts;
    use HasTemporalEntity;

    /**
     * @var array<class-string, bool>
     */
    private static array $temporalGuardsRun = [];

    /**
     * Validate the model's temporal configuration once per class, the first
     * time an instance is initialised (boot has finished by this point, so the
     * guards may safely inspect the model).
     */
    public function initializeBitemporal(): void
    {
        if (isset(self::$temporalGuardsRun[static::class])) {
            return;
        }

        // Closure-scoped suppression (TemporalLens::withoutBootGuards) must not
        // cache — the guards still run on a later, un-suppressed boot.
        if (resolve(LensStack::class)->bootGuardsSuppressed()) {
            return;
        }

        if (config('bitemporal.guards.enabled', true) === false) {
            self::$temporalGuardsRun[static::class] = true;

            return;
        }

        BootGuards::default()->runAgainst($this);
        BootLints::default()->runAgainst($this);

        self::$temporalGuardsRun[static::class] = true;
    }

    /**
     * @param  Builder  $query
     * @return BitemporalBuilder<Model>
     */
    public function newEloquentBuilder($query)
    {
        return new BitemporalBuilder($query);
    }

    /**
     * @param  array<int, static>  $models
     * @return BitemporalCollection<int, static>
     */
    public function newCollection(array $models = [])
    {
        return new BitemporalCollection($models);
    }
}
