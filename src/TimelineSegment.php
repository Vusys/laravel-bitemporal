<?php

declare(strict_types=1);

namespace Bitemporal;

use Bitemporal\Support\AttributeEquality;

/**
 * A Period plus a payload — a snapshot of one row's attributes for one
 * bitemporal segment.
 */
final readonly class TimelineSegment
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public Period $validPeriod,
        public ?Period $recordedPeriod,
        public array $attributes,
        public bool $isRetraction = false,
    ) {}

    public function isAntiRow(): bool
    {
        return $this->isRetraction;
    }

    public function hasSameAttributesAs(TimelineSegment $other): bool
    {
        if ($this->isRetraction !== $other->isRetraction) {
            return false;
        }

        return AttributeEquality::attributesMatch($this->attributes, $other->attributes);
    }

    /**
     * @param  array<int, string>  $dimensionColumns
     */
    public function hasSameDimensionsAs(TimelineSegment $other, array $dimensionColumns): bool
    {
        return array_all($dimensionColumns, fn ($column): bool => AttributeEquality::equals($this->attributes[$column] ?? null, $other->attributes[$column] ?? null));
    }

    public function withValidPeriod(Period $period): self
    {
        return new self($period, $this->recordedPeriod, $this->attributes, $this->isRetraction);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function withAttributes(array $attributes): self
    {
        return new self($this->validPeriod, $this->recordedPeriod, $attributes, $this->isRetraction);
    }

    public function equals(TimelineSegment $other): bool
    {
        if ($this->isRetraction !== $other->isRetraction) {
            return false;
        }

        if (! $this->validPeriod->equals($other->validPeriod)) {
            return false;
        }

        if (! $this->recordedPeriodsEqual($this->recordedPeriod, $other->recordedPeriod)) {
            return false;
        }

        return AttributeEquality::attributesMatch($this->attributes, $other->attributes);
    }

    /**
     * @param  array<string, string>  $columnMap
     * @return array<string, mixed>
     */
    public function toRow(array $columnMap): array
    {
        $row = $this->attributes;

        $row[$columnMap['valid_from']] = $this->validPeriod->from;
        $row[$columnMap['valid_to']] = $this->validPeriod->to;

        if ($this->recordedPeriod instanceof Period) {
            $row[$columnMap['recorded_from']] = $this->recordedPeriod->from;
            $row[$columnMap['recorded_to']] = $this->recordedPeriod->to;
        }

        $row[$columnMap['is_retraction']] = $this->isRetraction;

        return $row;
    }

    /**
     * @return array{valid_period: array{from: ?string, to: ?string}, recorded_period: array{from: ?string, to: ?string}|null, attributes: array<string, mixed>, is_retraction: bool}
     */
    public function toArray(): array
    {
        return [
            'valid_period' => $this->validPeriod->toArray(),
            'recorded_period' => $this->recordedPeriod?->toArray(),
            'attributes' => $this->attributes,
            'is_retraction' => $this->isRetraction,
        ];
    }

    private function recordedPeriodsEqual(?Period $a, ?Period $b): bool
    {
        if (! $a instanceof Period || ! $b instanceof Period) {
            return ! $a instanceof Period && ! $b instanceof Period;
        }

        return $a->equals($b);
    }
}
