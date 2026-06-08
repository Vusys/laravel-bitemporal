<?php

declare(strict_types=1);

namespace Bitemporal;

use ArrayIterator;
use Bitemporal\Exceptions\TemporalInvalidPeriodException;
use Bitemporal\Exceptions\TemporalOverlapException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Countable;
use DateTimeInterface;
use IteratorAggregate;
use Traversable;

/**
 * An ordered, non-overlapping sequence of TimelineSegments, all belonging to
 * the same (entity, dimensions) tuple. A Timeline instance is proof its rows
 * are consistent: the constructor sorts by valid_from and rejects overlaps.
 *
 * @implements IteratorAggregate<int, TimelineSegment>
 */
final readonly class Timeline implements Countable, IteratorAggregate
{
    /**
     * @var array<int, TimelineSegment>
     */
    private array $segments;

    /**
     * @param  array<int, TimelineSegment>  $segments
     */
    public function __construct(array $segments)
    {
        $sorted = $segments;
        usort($sorted, static fn (TimelineSegment $a, TimelineSegment $b): int => self::compareFrom(
            $a->validPeriod->from,
            $b->validPeriod->from,
        ));

        $count = count($sorted);
        for ($i = 1; $i < $count; $i++) {
            if ($sorted[$i - 1]->validPeriod->intersects($sorted[$i]->validPeriod)) {
                throw TemporalOverlapException::betweenSegments($i - 1, $i);
            }
        }

        $this->segments = $sorted;
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $rows
     * @param  array<string, string>  $columnMap
     */
    public static function fromRows(iterable $rows, array $columnMap): self
    {
        $segments = [];

        foreach ($rows as $row) {
            $validPeriod = Period::fromArray([
                'from' => self::dateValue($row[$columnMap['valid_from']] ?? null),
                'to' => self::dateValue($row[$columnMap['valid_to']] ?? null),
            ]);

            $recordedPeriod = null;
            if (isset($columnMap['recorded_from'], $columnMap['recorded_to'])
                && array_key_exists($columnMap['recorded_from'], $row)) {
                $recordedPeriod = Period::fromArray([
                    'from' => self::dateValue($row[$columnMap['recorded_from']] ?? null),
                    'to' => self::dateValue($row[$columnMap['recorded_to']] ?? null),
                ]);
            }

            $isRetraction = (bool) ($row[$columnMap['is_retraction']] ?? false);

            $attributes = $row;
            foreach (['valid_from', 'valid_to', 'recorded_from', 'recorded_to', 'is_retraction'] as $key) {
                if (isset($columnMap[$key])) {
                    unset($attributes[$columnMap[$key]]);
                }
            }

            $segments[] = new TimelineSegment($validPeriod, $recordedPeriod, $attributes, $isRetraction);
        }

        return new self($segments);
    }

    /**
     * @return array<int, TimelineSegment>
     */
    public function segments(): array
    {
        return $this->segments;
    }

    public function isEmpty(): bool
    {
        return $this->segments === [];
    }

    public function count(): int
    {
        return count($this->segments);
    }

    /**
     * @return Traversable<int, TimelineSegment>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->segments);
    }

    public function at(CarbonInterface $instant): ?TimelineSegment
    {
        foreach ($this->segments as $segment) {
            if ($segment->validPeriod->containsInstant($instant)) {
                return $segment;
            }
        }

        return null;
    }

    public function during(Period $window): self
    {
        $clipped = [];

        foreach ($this->segments as $segment) {
            $intersection = $segment->validPeriod->intersect($window);

            if ($intersection !== null) {
                $clipped[] = $segment->withValidPeriod($intersection);
            }
        }

        return new self($clipped);
    }

    public function head(): ?TimelineSegment
    {
        return $this->segments[0] ?? null;
    }

    public function tail(): ?TimelineSegment
    {
        if ($this->segments === []) {
            return null;
        }

        return $this->segments[count($this->segments) - 1];
    }

    public function openEnded(): ?TimelineSegment
    {
        foreach ($this->segments as $segment) {
            if ($segment->validPeriod->isOpenEnded()) {
                return $segment;
            }
        }

        return null;
    }

    public function spans(): Period
    {
        if ($this->segments === []) {
            throw TemporalInvalidPeriodException::emptyTimelineSpan();
        }

        $from = $this->segments[0]->validPeriod->from;

        $to = $this->segments[0]->validPeriod->to;
        foreach ($this->segments as $segment) {
            $segmentTo = $segment->validPeriod->to;

            if ($segmentTo === null) {
                $to = null;

                break;
            }

            if ($to !== null && $segmentTo->greaterThan($to)) {
                $to = $segmentTo;
            }
        }

        return new Period($from, $to);
    }

    public function merge(Timeline $other): self
    {
        return new self([...$this->segments, ...$other->segments]);
    }

    public function subtract(Period $window): self
    {
        $remaining = [];

        foreach ($this->segments as $segment) {
            foreach ($segment->validPeriod->subtract($window) as $piece) {
                $remaining[] = $segment->withValidPeriod($piece);
            }
        }

        return new self($remaining);
    }

    public function applyCorrection(TimelineSegment $correction): self
    {
        if ($correction->isRetraction) {
            throw TemporalInvalidPeriodException::antiRowCorrection();
        }

        return new self([...$this->subtract($correction->validPeriod)->segments, $correction]);
    }

    public function applyRetraction(Period $window): self
    {
        $antiRow = new TimelineSegment($window, null, [], true);

        return new self([...$this->subtract($window)->segments, $antiRow]);
    }

    /**
     * @param  array<int, string>  $dimensionColumns
     */
    public function compact(array $dimensionColumns): self
    {
        $result = [];

        foreach ($this->segments as $segment) {
            $last = $result === [] ? null : $result[count($result) - 1];

            if ($last !== null
                && $last->validPeriod->meets($segment->validPeriod)
                && $last->hasSameAttributesAs($segment)
                && $last->hasSameDimensionsAs($segment, $dimensionColumns)
                && $this->recordedPeriodsEqual($last->recordedPeriod, $segment->recordedPeriod)) {
                array_pop($result);
                $result[] = $last->withValidPeriod(new Period($last->validPeriod->from, $segment->validPeriod->to));

                continue;
            }

            $result[] = $segment;
        }

        return new self($result);
    }

    public function equals(Timeline $other): bool
    {
        if (count($this->segments) !== count($other->segments)) {
            return false;
        }

        return array_all($this->segments, fn ($segment, $index) => $segment->equals($other->segments[$index]));
    }

    /**
     * @return array<int, array{valid_period: array{from: ?string, to: ?string}, recorded_period: array{from: ?string, to: ?string}|null, attributes: array<string, mixed>, is_retraction: bool}>
     */
    public function toArray(): array
    {
        return array_map(static fn (TimelineSegment $segment): array => $segment->toArray(), $this->segments);
    }

    private static function compareFrom(?CarbonImmutable $a, ?CarbonImmutable $b): int
    {
        return [$a instanceof CarbonImmutable, $a] <=> [$b instanceof CarbonImmutable, $b];
    }

    private function recordedPeriodsEqual(?Period $a, ?Period $b): bool
    {
        if (! $a instanceof Period || ! $b instanceof Period) {
            return ! $a instanceof Period && ! $b instanceof Period;
        }

        return $a->equals($b);
    }

    private static function dateValue(mixed $value): CarbonInterface|string|null
    {
        if ($value === null || is_string($value) || $value instanceof CarbonInterface) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        throw TemporalInvalidPeriodException::unparseableDate();
    }
}
