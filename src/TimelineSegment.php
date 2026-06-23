<?php

declare(strict_types=1);

namespace Vusys\Bitemporal;

use Vusys\Bitemporal\Support\AttributeEquality;

/**
 * A Spell plus a payload — a snapshot of one row's attributes for one
 * bitemporal segment.
 */
final readonly class TimelineSegment
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public Spell $validSpell,
        public ?Spell $recordedSpell,
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
        return array_all($dimensionColumns, fn (string $column): bool => AttributeEquality::equals($this->attributes[$column] ?? null, $other->attributes[$column] ?? null));
    }

    public function withValidSpell(Spell $spell): self
    {
        return new self($spell, $this->recordedSpell, $this->attributes, $this->isRetraction);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function withAttributes(array $attributes): self
    {
        return new self($this->validSpell, $this->recordedSpell, $attributes, $this->isRetraction);
    }

    public function equals(TimelineSegment $other): bool
    {
        if ($this->isRetraction !== $other->isRetraction) {
            return false;
        }

        if (! $this->validSpell->equals($other->validSpell)) {
            return false;
        }

        if (! $this->recordedSpellsEqual($this->recordedSpell, $other->recordedSpell)) {
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

        $row[$columnMap['valid_from']] = $this->validSpell->from;
        $row[$columnMap['valid_to']] = $this->validSpell->to;

        if ($this->recordedSpell instanceof Spell) {
            $row[$columnMap['recorded_from']] = $this->recordedSpell->from;
            $row[$columnMap['recorded_to']] = $this->recordedSpell->to;
        }

        $row[$columnMap['is_retraction']] = $this->isRetraction;

        return $row;
    }

    /**
     * @return array{valid_spell: array{from: ?string, to: ?string}, recorded_spell: array{from: ?string, to: ?string}|null, attributes: array<string, mixed>, is_retraction: bool}
     */
    public function toArray(): array
    {
        return [
            'valid_spell' => $this->validSpell->toArray(),
            'recorded_spell' => $this->recordedSpell?->toArray(),
            'attributes' => $this->attributes,
            'is_retraction' => $this->isRetraction,
        ];
    }

    private function recordedSpellsEqual(?Spell $a, ?Spell $b): bool
    {
        if (! $a instanceof Spell || ! $b instanceof Spell) {
            return ! $a instanceof Spell && ! $b instanceof Spell;
        }

        return $a->equals($b);
    }
}
