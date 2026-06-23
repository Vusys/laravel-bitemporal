<?php

declare(strict_types=1);

namespace Vusys\Bitemporal;

use ArrayIterator;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Countable;
use DateTimeInterface;
use IteratorAggregate;
use Traversable;
use Vusys\Bitemporal\Exceptions\TemporalInvalidSpellException;
use Vusys\Bitemporal\Exceptions\TemporalOverlapException;

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
            $a->validSpell->from,
            $b->validSpell->from,
        ));

        $count = count($sorted);
        for ($i = 1; $i < $count; $i++) {
            if ($sorted[$i - 1]->validSpell->intersects($sorted[$i]->validSpell)) {
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
            $validSpell = Spell::fromArray([
                'from' => self::dateValue($row[$columnMap['valid_from']] ?? null),
                'to' => self::dateValue($row[$columnMap['valid_to']] ?? null),
            ]);

            $recordedSpell = null;
            if (isset($columnMap['recorded_from'], $columnMap['recorded_to'])
                && array_key_exists($columnMap['recorded_from'], $row)) {
                $recordedSpell = Spell::fromArray([
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

            $segments[] = new TimelineSegment($validSpell, $recordedSpell, $attributes, $isRetraction);
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
            if ($segment->validSpell->containsInstant($instant)) {
                return $segment;
            }
        }

        return null;
    }

    public function during(Spell $window): self
    {
        $clipped = [];

        foreach ($this->segments as $segment) {
            $intersection = $segment->validSpell->intersect($window);

            if ($intersection !== null) {
                $clipped[] = $segment->withValidSpell($intersection);
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
            if ($segment->validSpell->isOpenEnded()) {
                return $segment;
            }
        }

        return null;
    }

    public function spans(): Spell
    {
        if ($this->segments === []) {
            throw TemporalInvalidSpellException::emptyTimelineSpan();
        }

        $from = $this->segments[0]->validSpell->from;

        $to = $this->segments[0]->validSpell->to;
        foreach ($this->segments as $segment) {
            $segmentTo = $segment->validSpell->to;

            if ($segmentTo === null) {
                $to = null;

                break;
            }

            if ($to !== null && $segmentTo->greaterThan($to)) {
                $to = $segmentTo;
            }
        }

        return new Spell($from, $to);
    }

    public function merge(Timeline $other): self
    {
        return new self([...$this->segments, ...$other->segments]);
    }

    public function subtract(Spell $window): self
    {
        $remaining = [];

        foreach ($this->segments as $segment) {
            foreach ($segment->validSpell->subtract($window) as $piece) {
                $remaining[] = $segment->withValidSpell($piece);
            }
        }

        return new self($remaining);
    }

    public function applyCorrection(TimelineSegment $correction): self
    {
        if ($correction->isRetraction) {
            throw TemporalInvalidSpellException::antiRowCorrection();
        }

        return new self([...$this->subtract($correction->validSpell)->segments, $correction]);
    }

    public function applyRetraction(Spell $window): self
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
                && $last->validSpell->meets($segment->validSpell)
                && $last->hasSameAttributesAs($segment)
                && $last->hasSameDimensionsAs($segment, $dimensionColumns)
                && $this->recordedSpellsEqual($last->recordedSpell, $segment->recordedSpell)) {
                array_pop($result);
                $result[] = $last->withValidSpell(new Spell($last->validSpell->from, $segment->validSpell->to));

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

        return array_all($this->segments, fn (TimelineSegment $segment, $index): bool => $segment->equals($other->segments[$index]));
    }

    /**
     * @return array<int, array{valid_spell: array{from: ?string, to: ?string}, recorded_spell: array{from: ?string, to: ?string}|null, attributes: array<string, mixed>, is_retraction: bool}>
     */
    public function toArray(): array
    {
        return array_map(static fn (TimelineSegment $segment): array => $segment->toArray(), $this->segments);
    }

    private static function compareFrom(?CarbonImmutable $a, ?CarbonImmutable $b): int
    {
        return [$a instanceof CarbonImmutable, $a] <=> [$b instanceof CarbonImmutable, $b];
    }

    private function recordedSpellsEqual(?Spell $a, ?Spell $b): bool
    {
        if (! $a instanceof Spell || ! $b instanceof Spell) {
            return ! $a instanceof Spell && ! $b instanceof Spell;
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

        throw TemporalInvalidSpellException::unparseableDate();
    }
}
