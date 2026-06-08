<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Vusys\Bitemporal\BitemporalBuilder;
use Vusys\Bitemporal\Lens\LensFrame;
use Vusys\Bitemporal\Lens\LensStack;

/**
 * Applies the ambient TemporalLens frame to a temporal read just before it
 * executes, unless the query already pins that axis explicitly or opts out with
 * withoutLens(). Writers always read withoutLens().
 *
 * @phpstan-require-extends BitemporalBuilder
 */
trait InteractsWithLens
{
    private bool $lensDisabled = false;

    private bool $lensApplied = false;

    private bool $explicitValidAt = false;

    private bool $explicitKnownAt = false;

    private ?LensFrame $capturedFrame = null;

    private bool $hasCapturedFrame = false;

    public function withoutLens(): static
    {
        $this->lensDisabled = true;

        return $this;
    }

    /**
     * Snapshot the current ambient frame so a later execution uses it even if
     * the lens stack has since changed.
     */
    public function captureLens(): static
    {
        $this->capturedFrame = $this->resolveLens()?->current();
        $this->hasCapturedFrame = true;

        return $this;
    }

    public function markValidAtPinned(): void
    {
        $this->explicitValidAt = true;
    }

    public function markKnownAtPinned(): void
    {
        $this->explicitKnownAt = true;
    }

    /**
     * @param  array<int, string>|string  $columns
     * @return array<int, Model>
     */
    #[\Override]
    public function getModels($columns = ['*'])
    {
        $this->applyAmbientLens();

        return parent::getModels($columns);
    }

    private function applyAmbientLens(): void
    {
        if ($this->lensApplied || $this->lensDisabled) {
            return;
        }

        $this->lensApplied = true;

        $frame = $this->hasCapturedFrame ? $this->capturedFrame : $this->resolveLens()?->current();

        if (! $frame instanceof LensFrame) {
            return;
        }

        if (! $this->explicitValidAt && $frame->validAt instanceof CarbonImmutable) {
            $this->validAt($frame->validAt);
        }

        if (! $this->explicitKnownAt && $frame->knownAt instanceof CarbonImmutable) {
            $this->knownAt($frame->knownAt);
        }
    }

    private function resolveLens(): ?LensStack
    {
        $container = Container::getInstance();

        if (! $container->bound(LensStack::class)) {
            return null;
        }

        return $container->make(LensStack::class);
    }
}
