<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Unit\Mutation;

use Carbon\CarbonImmutable;
use Vusys\Bitemporal\Exceptions\TemporalInvalidSpellException;
use Vusys\Bitemporal\Spell;
use Vusys\Bitemporal\Tests\TestCase;

/**
 * Pins the surviving mutants listed in build/mutants/src__Spell.txt.
 */
final class SpellMutationTest extends TestCase
{
    private function at(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value);
    }

    private function spell(?string $from, ?string $to): Spell
    {
        return new Spell(
            $from === null ? null : $this->at($from),
            $to === null ? null : $this->at($to),
        );
    }

    // --- subtract(): $overlap->from instanceof CarbonImmutable (InstanceOf_ -> true) ---

    public function test_subtract_with_open_overlap_lower_bound_adds_no_left_piece(): void
    {
        // (-inf, 2026-12) minus (-inf, 2026-06): overlap is (-inf, 2026-06), whose
        // ->from is null. The real guard `$overlap->from instanceof` is false, so the
        // left piece is skipped. The mutant (`true`) inserts a spurious (-inf, -inf).
        $result = $this->spell(null, '2026-12-01')->subtract($this->spell(null, '2026-06-01'));

        $this->assertCount(1, $result);
        $this->assertTrue($result[0]->equals($this->spell('2026-06-01', '2026-12-01')));
    }

    // --- subtract(): ! $this->from instanceof CarbonImmutable (InstanceOf_ -> true) ---

    public function test_subtract_open_start_emits_left_piece_to_overlap_from(): void
    {
        // (-inf, 2026-12) minus (2026-06, 2026-09): overlap is (2026-06, 2026-09).
        // The left piece must be (-inf, 2026-06). The mutant turns
        // `! $this->from instanceof` into `! true` (false) and then dereferences the
        // null $this->from, so it cannot produce this two-piece result.
        $result = $this->spell(null, '2026-12-01')->subtract($this->spell('2026-06-01', '2026-09-01'));

        $this->assertCount(2, $result);
        $this->assertTrue($result[0]->equals($this->spell(null, '2026-06-01')));
        $this->assertTrue($result[1]->equals($this->spell('2026-09-01', '2026-12-01')));
    }

    // --- toArray(): $this->from?->toIso8601String() (NullSafeMethodCall) ---

    public function test_to_array_keeps_null_from(): void
    {
        // The mutant drops the null-safe operator and calls ->toIso8601String() on
        // null, which fatals. The real code returns a null 'from'.
        $array = $this->spell(null, '2026-06-01')->toArray();

        $this->assertNull($array['from']);
        $this->assertNotNull($array['to']);
    }

    // --- toArray(): $this->to?->toIso8601String() (NullSafeMethodCall) ---

    public function test_to_array_keeps_null_to(): void
    {
        $array = $this->spell('2026-01-01', null)->toArray();

        $this->assertNull($array['to']);
        $this->assertNotNull($array['from']);
    }

    // --- zeroLengthAllowed(): config default false (FalseValue) ---

    public function test_zero_length_rejected_when_config_key_is_absent(): void
    {
        // Remove the key entirely so the default argument of config() is consulted.
        // Real default is false (reject); the mutant default true would allow it.
        $bitemporal = config('bitemporal');
        unset($bitemporal['spells']['allow_zero_length']);
        config(['bitemporal' => $bitemporal]);

        $this->expectException(TemporalInvalidSpellException::class);

        new Spell($this->at('2026-01-01'), $this->at('2026-01-01'));
    }

    // --- zeroLengthAllowed(): (bool) cast (CastBool) ---

    public function test_zero_length_config_is_cast_to_bool(): void
    {
        // A truthy non-bool config value must be cast to a real bool. The real code
        // returns (bool) 1 === true and allows the zero-length spell. Dropping the
        // cast returns int 1 from a `: bool` method under strict_types -> TypeError.
        config(['bitemporal.spells.allow_zero_length' => 1]);

        $spell = new Spell($this->at('2026-01-01'), $this->at('2026-01-01'));

        $this->assertTrue($spell->isEmpty());
    }
}
