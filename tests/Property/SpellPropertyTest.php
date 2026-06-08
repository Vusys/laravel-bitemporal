<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Property;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Group;
use Vusys\Bitemporal\Spell;
use Vusys\Bitemporal\Tests\TestCase;

#[Group('property')]
final class SpellPropertyTest extends TestCase
{
    private const int ITERATIONS = 300;

    private const string EPOCH = '2026-01-01 00:00:00';

    private function randomSpell(bool $allowOpen = true): Spell
    {
        $base = CarbonImmutable::parse(self::EPOCH);

        $from = $allowOpen && random_int(0, 5) === 0
            ? null
            : $base->addDays(random_int(0, 200));

        $to = $allowOpen && random_int(0, 5) === 0
            ? null
            : ($from === null
                ? $base->addDays(random_int(0, 200))
                : $from->addDays(random_int(1, 200)));

        return new Spell($from, $to);
    }

    public function test_intersects_is_symmetric(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $a = $this->randomSpell();
            $b = $this->randomSpell();

            $this->assertSame(
                $a->intersects($b),
                $b->intersects($a),
                "intersects asymmetric for {$a} and {$b}",
            );
        }
    }

    public function test_intersect_result_is_contained_by_both(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $a = $this->randomSpell();
            $b = $this->randomSpell();

            $overlap = $a->intersect($b);

            if (! $overlap instanceof Spell) {
                $this->assertFalse($a->intersects($b));

                continue;
            }

            $this->assertTrue($a->containsSpell($overlap), "overlap not in a: {$a} ∩ {$b} = {$overlap}");
            $this->assertTrue($b->containsSpell($overlap), "overlap not in b: {$a} ∩ {$b} = {$overlap}");
        }
    }

    public function test_subtract_pieces_are_disjoint_from_other(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $a = $this->randomSpell();
            $b = $this->randomSpell();

            foreach ($a->subtract($b) as $piece) {
                $this->assertTrue($a->containsSpell($piece), "piece {$piece} not within {$a}");
                $this->assertFalse($piece->intersects($b), "piece {$piece} intersects subtracted {$b}");
            }
        }
    }

    public function test_contains_instant_consistent_with_contains_zero_width_logic(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $spell = $this->randomSpell(allowOpen: false);
            $from = $spell->from;
            $this->assertNotNull($from);

            $this->assertTrue($spell->containsInstant($from));
            $this->assertTrue($spell->containsInstant($from->addMicrosecond()));

            $to = $spell->to;
            $this->assertNotNull($to);
            $this->assertFalse($spell->containsInstant($to));
        }
    }

    public function test_merge_of_adjacent_or_overlapping_contains_both(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $a = $this->randomSpell(allowOpen: false);
            $b = $this->randomSpell(allowOpen: false);

            if (! $a->intersects($b) && ! $a->isAdjacent($b)) {
                continue;
            }

            $merged = $a->merge($b);

            $this->assertTrue($merged->containsSpell($a), "merge {$merged} lost {$a}");
            $this->assertTrue($merged->containsSpell($b), "merge {$merged} lost {$b}");
        }
    }

    public function test_equals_is_reflexive(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $spell = $this->randomSpell();
            $this->assertTrue($spell->equals($spell));
            $this->assertTrue($spell->equals(Spell::fromArray($spell->toArray())));
        }
    }
}
