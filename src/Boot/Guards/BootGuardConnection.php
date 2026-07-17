<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Boot\Guards;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ReflectionClass;
use Throwable;
use Vusys\Bitemporal\Boot\BootGuard;

/**
 * A temporal model and its temporalEntityRelation() must live on the same connection —
 * the writer locks and joins across the two in one transaction, which a
 * cross-connection relation cannot honour.
 *
 * Only the BelongsTo case is checked here (BootGuardRelationType already rejects
 * anything that is not BelongsTo/MorphTo); the polymorphic per-morph-type check
 * is deferred to the morph-map guard.
 */
final class BootGuardConnection implements BootGuard
{
    public function check(Model $model): ?string
    {
        if (! method_exists($model, 'temporalEntityRelation')) {
            return null;
        }

        try {
            $relation = $model->temporalEntityRelation();
        } catch (Throwable) {
            return null;
        }

        if (! $relation instanceof BelongsTo) {
            return null;
        }

        // A BelongsTo propagates the parent's connection onto its related
        // instance, so compare against the related class's *declared* default
        // connection to detect a table that actually lives elsewhere. Read it
        // via reflection rather than instantiating — constructing the related
        // model here would re-enter the boot guards and can recurse.
        $relatedClass = $relation->getRelated()::class;
        $modelConnection = $model->getConnectionName();
        $entityConnection = new ReflectionClass($relatedClass)->getDefaultProperties()['connection'] ?? null;
        $entityConnection = is_string($entityConnection) ? $entityConnection : null;

        if ($modelConnection === $entityConnection) {
            return null;
        }

        return "temporal model uses connection '".($modelConnection ?? '[default]')
            ."' but its temporalEntityRelation() uses '".($entityConnection ?? '[default]')
            ."'; both sides must share one connection";
    }
}
