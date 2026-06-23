<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Events;

use Illuminate\Database\Eloquent\Model;

/**
 * Dispatched when an advisory boot lint is raised for a model. Non-blocking;
 * the model still boots.
 */
final readonly class TemporalBootLintRaised
{
    /**
     * @param  class-string<Model>  $model
     * @param  class-string  $lint
     */
    public function __construct(
        public string $model,
        public string $lint,
        public string $message,
    ) {}
}
