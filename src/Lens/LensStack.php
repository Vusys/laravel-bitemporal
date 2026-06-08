<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Lens;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Vusys\Bitemporal\Exceptions\TemporalConfigurationException;

/**
 * Request-scoped stack of point-in-time lens frames. asOf() pushes a frame for
 * the duration of a callback; nested frames inherit the parent's axes unless
 * overridden. Resolved as a singleton (the TemporalLens facade accessor).
 */
final class LensStack
{
    /**
     * @var array<int, LensFrame>
     */
    private array $frames = [];

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function asOf(CarbonInterface|string|null $validAt, CarbonInterface|string|null $knownAt, Closure $callback): mixed
    {
        $this->push($validAt, $knownAt);

        try {
            return $callback();
        } finally {
            array_pop($this->frames);
        }
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function validAt(CarbonInterface|string $validAt, Closure $callback): mixed
    {
        return $this->asOf($validAt, null, $callback);
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function knownAt(CarbonInterface|string $knownAt, Closure $callback): mixed
    {
        return $this->asOf(null, $knownAt, $callback);
    }

    public function current(): ?LensFrame
    {
        return $this->frames === [] ? null : $this->frames[count($this->frames) - 1];
    }

    public function depth(): int
    {
        return count($this->frames);
    }

    public function reset(): void
    {
        $this->frames = [];
    }

    public function assertEmpty(): void
    {
        if ($this->frames !== []) {
            throw new TemporalConfigurationException(
                'a TemporalLens::asOf() frame was left open at the end of the request or job; '.
                'asOf() must always pop its frame (it does so automatically unless the worker was killed mid-callback)',
            );
        }
    }

    private function push(CarbonInterface|string|null $validAt, CarbonInterface|string|null $knownAt): void
    {
        $parent = $this->current();

        $this->frames[] = new LensFrame(
            $validAt !== null ? $this->parse($validAt) : $parent?->validAt,
            $knownAt !== null ? $this->parse($knownAt) : $parent?->knownAt,
        );
    }

    private function parse(CarbonInterface|string $value): CarbonImmutable
    {
        $timezone = config('bitemporal.spells.timezone', 'UTC');

        return CarbonImmutable::parse($value)->setTimezone(is_string($timezone) ? $timezone : 'UTC');
    }
}
