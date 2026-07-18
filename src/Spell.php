<?php

declare(strict_types=1);

namespace Vusys\Bitemporal;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonInterval;
use Illuminate\Container\Container;
use Vusys\Bitemporal\Exceptions\TemporalInvalidSpellException;

/**
 * A half-open `[from, to)` interval. Either bound may be null for "unbounded
 * on that side" — the package's representation of infinity.
 */
final readonly class Spell implements \Stringable
{
    public function __construct(
        public ?CarbonImmutable $from,
        public ?CarbonImmutable $to,
    ) {
        if ($from instanceof CarbonImmutable && $to instanceof CarbonImmutable) {
            if ($from->greaterThan($to)) {
                throw TemporalInvalidSpellException::fromAfterTo();
            }

            if ($from->equalTo($to) && ! $this->zeroLengthAllowed()) {
                throw TemporalInvalidSpellException::zeroLength();
            }
        }
    }

    public static function unbounded(): self
    {
        return new self(null, null);
    }

    public static function startingAt(CarbonInterface|string $from): self
    {
        return new self(self::parse($from), null);
    }

    public static function endingAt(CarbonInterface|string $to): self
    {
        return new self(null, self::parse($to));
    }

    /**
     * @param  array{from?: CarbonInterface|string|null, to?: CarbonInterface|string|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            self::parseNullable($data['from'] ?? null),
            self::parseNullable($data['to'] ?? null),
        );
    }

    /**
     * Construct a half-open spell. The optional $bounds parameter is an
     * inbound-conversion hint only; it is normalised to [) at construction.
     * The constructed Spell has no bounds field — every predicate operates
     * in [) unconditionally.
     */
    public static function between(
        CarbonInterface|string $from,
        CarbonInterface|string|null $to,
        SpellBounds $bounds = SpellBounds::ClosedOpen,
    ): self {
        $normalisedFrom = self::parse($from);
        $normalisedTo = self::parseNullable($to);

        if (! $bounds->includesLower()) {
            $normalisedFrom = $normalisedFrom->addMicrosecond();
        }

        if ($bounds->includesUpper() && $normalisedTo instanceof CarbonImmutable) {
            $normalisedTo = $normalisedTo->addMicrosecond();
        }

        return new self($normalisedFrom, $normalisedTo);
    }

    public function isOpenEnded(): bool
    {
        return ! $this->to instanceof CarbonImmutable;
    }

    public function isOpenStart(): bool
    {
        return ! $this->from instanceof CarbonImmutable;
    }

    public function isUnbounded(): bool
    {
        return ! $this->from instanceof CarbonImmutable && ! $this->to instanceof CarbonImmutable;
    }

    public function isEmpty(): bool
    {
        return $this->from instanceof CarbonImmutable && $this->to instanceof CarbonImmutable && $this->from->equalTo($this->to);
    }

    public function length(): ?CarbonInterval
    {
        if (! $this->from instanceof CarbonImmutable || ! $this->to instanceof CarbonImmutable) {
            return null;
        }

        return $this->from->diffAsCarbonInterval($this->to);
    }

    public function containsInstant(CarbonInterface $instant): bool
    {
        $lowerOk = ! $this->from instanceof CarbonImmutable || $this->from->lessThanOrEqualTo($instant);
        $upperOk = ! $this->to instanceof CarbonImmutable || $instant->lessThan($this->to);

        return $lowerOk && $upperOk;
    }

    public function containsSpell(Spell $other): bool
    {
        $lowerOk = ! $this->from instanceof CarbonImmutable
            || ($other->from instanceof CarbonImmutable && $this->from->lessThanOrEqualTo($other->from));
        $upperOk = ! $this->to instanceof CarbonImmutable
            || ($other->to instanceof CarbonImmutable && $other->to->lessThanOrEqualTo($this->to));

        return $lowerOk && $upperOk;
    }

    public function intersects(Spell $other): bool
    {
        $lower = $this->laterLowerBound($this->from, $other->from);
        $upper = $this->earlierUpperBound($this->to, $other->to);

        if (! $lower instanceof CarbonImmutable || ! $upper instanceof CarbonImmutable) {
            return true;
        }

        return $lower->lessThan($upper);
    }

    public function containedBy(Spell $other): bool
    {
        return $other->containsSpell($this);
    }

    public function intersect(Spell $other): ?Spell
    {
        if (! $this->intersects($other)) {
            return null;
        }

        return new self(
            $this->laterLowerBound($this->from, $other->from),
            $this->earlierUpperBound($this->to, $other->to),
        );
    }

    /**
     * @return array<int, Spell>
     */
    public function subtract(Spell $other): array
    {
        $overlap = $this->intersect($other);

        if (! $overlap instanceof Spell) {
            return [$this];
        }

        $pieces = [];

        if ($overlap->from instanceof CarbonImmutable && (! $this->from instanceof CarbonImmutable || $this->from->lessThan($overlap->from))) {
            $pieces[] = new self($this->from, $overlap->from);
        }

        if ($overlap->to instanceof CarbonImmutable && (! $this->to instanceof CarbonImmutable || $overlap->to->lessThan($this->to))) {
            $pieces[] = new self($overlap->to, $this->to);
        }

        return $pieces;
    }

    public function merge(Spell $other): Spell
    {
        if (! $this->intersects($other) && ! $this->isAdjacent($other)) {
            throw TemporalInvalidSpellException::mergeDisjoint();
        }

        return new self(
            $this->earlierLowerBound($this->from, $other->from),
            $this->laterUpperBound($this->to, $other->to),
        );
    }

    public function meets(Spell $other): bool
    {
        return $this->to instanceof CarbonImmutable && $other->from instanceof CarbonImmutable && $this->to->equalTo($other->from);
    }

    /**
     * True when this spell ends at or before $other begins — an **inclusive**
     * "before or immediately adjacent" contract (uses `<=`). Because the bound
     * is inclusive, `precedes()` and {@see meets()} are BOTH true for adjacent
     * half-open spells such as `[0,5)` and `[5,10)`: the first meets and precedes
     * the second. If you need strict "before with a gap", combine with
     * `! $a->meets($b)`; if you need "before, possibly touching", `precedes()`
     * alone is it.
     */
    public function precedes(Spell $other): bool
    {
        return $this->to instanceof CarbonImmutable && $other->from instanceof CarbonImmutable && $this->to->lessThanOrEqualTo($other->from);
    }

    public function follows(Spell $other): bool
    {
        return $other->precedes($this);
    }

    public function isAdjacent(Spell $other): bool
    {
        if ($this->meets($other)) {
            return true;
        }

        return $other->meets($this);
    }

    public function withFrom(?CarbonInterface $from): self
    {
        return new self(self::parseNullable($from), $this->to);
    }

    public function withTo(?CarbonInterface $to): self
    {
        return new self($this->from, self::parseNullable($to));
    }

    /**
     * @return array{from: ?string, to: ?string}
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from?->toIso8601String(),
            'to' => $this->to?->toIso8601String(),
        ];
    }

    public function equals(Spell $other): bool
    {
        return $this->boundsEqual($this->from, $other->from)
            && $this->boundsEqual($this->to, $other->to);
    }

    public function __toString(): string
    {
        return sprintf('[%s, %s)', $this->label($this->from, '-∞'), $this->label($this->to, '∞'));
    }

    private function label(?CarbonImmutable $bound, string $infinity): string
    {
        if (! $bound instanceof CarbonImmutable) {
            return $infinity;
        }

        if ($bound->equalTo($bound->startOfDay())) {
            return $bound->toDateString();
        }

        return $bound->format('Y-m-d H:i:s.u');
    }

    private function boundsEqual(?CarbonImmutable $a, ?CarbonImmutable $b): bool
    {
        if (! $a instanceof CarbonImmutable || ! $b instanceof CarbonImmutable) {
            return ! $a instanceof CarbonImmutable && ! $b instanceof CarbonImmutable;
        }

        return $a->equalTo($b);
    }

    private function laterLowerBound(?CarbonImmutable $a, ?CarbonImmutable $b): ?CarbonImmutable
    {
        if (! $a instanceof CarbonImmutable) {
            return $b;
        }

        if (! $b instanceof CarbonImmutable) {
            return $a;
        }

        return $a->greaterThan($b) ? $a : $b;
    }

    private function earlierLowerBound(?CarbonImmutable $a, ?CarbonImmutable $b): ?CarbonImmutable
    {
        if (! $a instanceof CarbonImmutable || ! $b instanceof CarbonImmutable) {
            return null;
        }

        return $a->lessThan($b) ? $a : $b;
    }

    private function earlierUpperBound(?CarbonImmutable $a, ?CarbonImmutable $b): ?CarbonImmutable
    {
        if (! $a instanceof CarbonImmutable) {
            return $b;
        }

        if (! $b instanceof CarbonImmutable) {
            return $a;
        }

        return $a->lessThan($b) ? $a : $b;
    }

    private function laterUpperBound(?CarbonImmutable $a, ?CarbonImmutable $b): ?CarbonImmutable
    {
        if (! $a instanceof CarbonImmutable || ! $b instanceof CarbonImmutable) {
            return null;
        }

        return $a->greaterThan($b) ? $a : $b;
    }

    private static function parse(CarbonInterface|string $value): CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        if ($value instanceof CarbonInterface) {
            return CarbonImmutable::instance($value);
        }

        // Anchor bare strings to the configured spell timezone rather than the
        // ambient PHP default, so a boundary parsed here reconstructs the same
        // instant the read/query path and the persisted value use when the app
        // default TZ differs from bitemporal.spells.timezone (issue #69). Parse
        // then convert (not reinterpret): a string carrying an offset keeps it,
        // matching BitemporalWriter::instant().
        return CarbonImmutable::parse($value)->setTimezone(self::timezone());
    }

    private static function timezone(): string
    {
        // Spell is a value object that may be built outside a booted app (pure
        // unit tests, CLI value construction), so degrade to UTC when the config
        // repository is not bound rather than throwing.
        if (! Container::getInstance()->bound('config')) {
            return 'UTC';
        }

        $timezone = config('bitemporal.spells.timezone', 'UTC');

        return is_string($timezone) ? $timezone : 'UTC';
    }

    private static function parseNullable(CarbonInterface|string|null $value): ?CarbonImmutable
    {
        return $value === null ? null : self::parse($value);
    }

    private function zeroLengthAllowed(): bool
    {
        return (bool) config('bitemporal.spells.allow_zero_length', false);
    }
}
