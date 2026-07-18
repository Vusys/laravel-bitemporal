<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Unit\Casts;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use Vusys\Bitemporal\Casts\CompositeSpellCast;
use Vusys\Bitemporal\Spell;
use Vusys\Bitemporal\Tests\TestCase;

/**
 * Pins CompositeSpellCast::get()/set() boundary handling: how each raw column
 * value type is converted to a CarbonImmutable bound, and the exact column
 * array produced when persisting a Spell.
 */
final class CompositeSpellCastTest extends TestCase
{
    private function model(): Model
    {
        return new class extends Model {};
    }

    private function cast(): CompositeSpellCast
    {
        return new CompositeSpellCast('valid_from', 'valid_to');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function getSpell(array $attributes): Spell
    {
        return $this->cast()->get($this->model(), 'spell', null, $attributes);
    }

    public function test_get_parses_string_endpoints(): void
    {
        $spell = $this->getSpell([
            'valid_from' => '2026-01-01 09:00:00',
            'valid_to' => '2026-06-01 17:30:00',
        ]);

        $this->assertInstanceOf(CarbonImmutable::class, $spell->from);
        $this->assertInstanceOf(CarbonImmutable::class, $spell->to);
        $this->assertSame('2026-01-01 09:00:00', $spell->from->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-01 17:30:00', $spell->to->format('Y-m-d H:i:s'));
    }

    public function test_get_parses_strings_in_the_configured_spell_timezone(): void
    {
        config()->set('bitemporal.spells.timezone', 'UTC');

        $ambient = date_default_timezone_get();
        date_default_timezone_set('America/New_York');

        try {
            $spell = $this->getSpell([
                'valid_from' => '2026-01-01 12:00:00',
                'valid_to' => null,
            ]);

            // Stored offset-less strings are UTC; the reconstructed instant must
            // be UTC noon regardless of the ambient/default timezone. Under the
            // bug it would be parsed as New York noon (17:00 UTC).
            $this->assertInstanceOf(CarbonImmutable::class, $spell->from);
            $this->assertSame('2026-01-01T12:00:00+00:00', $spell->from->toIso8601String());
        } finally {
            date_default_timezone_set($ambient);
        }
    }

    public function test_get_accepts_datetime_instances(): void
    {
        $spell = $this->getSpell([
            'valid_from' => new DateTimeImmutable('2026-02-02 12:00:00'),
            'valid_to' => null,
        ]);

        // Kills the `instanceof DateTimeInterface -> false` mutant (would yield null).
        $this->assertInstanceOf(CarbonImmutable::class, $spell->from);
        $this->assertSame('2026-02-02 12:00:00', $spell->from->format('Y-m-d H:i:s'));
        $this->assertNull($spell->to);
    }

    public function test_get_parses_integer_timestamp(): void
    {
        $spell = $this->getSpell([
            'valid_from' => 1735732800, // 2025-01-01 12:00:00 UTC
            'valid_to' => null,
        ]);

        // Kills `is_string($value) || !is_int($value)` — integers must still parse.
        $this->assertInstanceOf(CarbonImmutable::class, $spell->from);
        $this->assertSame(1735732800, $spell->from->getTimestamp());
    }

    public function test_get_treats_null_endpoints_as_unbounded(): void
    {
        $spell = $this->getSpell([
            'valid_from' => null,
            'valid_to' => null,
        ]);

        $this->assertNull($spell->from);
        $this->assertNull($spell->to);
    }

    public function test_get_treats_unsupported_scalar_as_null_bound(): void
    {
        // Floats are neither string nor int: original returns a null bound.
        // Kills `!is_string || !is_int` which would try to parse the float.
        $spell = $this->getSpell([
            'valid_from' => 1.5,
            'valid_to' => null,
        ]);

        $this->assertNull($spell->from);
        $this->assertNull($spell->to);
    }

    public function test_get_uses_null_for_missing_attributes(): void
    {
        $spell = $this->getSpell([]);

        $this->assertNull($spell->from);
        $this->assertNull($spell->to);
    }

    public function test_set_writes_both_columns_from_spell(): void
    {
        config()->set('bitemporal.spells.timezone', 'UTC');

        $cast = new CompositeSpellCast('vf', 'vt');
        $spell = new Spell(
            CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC'),
            CarbonImmutable::parse('2026-02-01 00:00:00', 'UTC'),
        );

        $result = $cast->set($this->model(), 'spell', $spell, []);

        // Exact keys/order pins ArrayItem* and ArrayItemRemoval mutants; the
        // bounds round-trip to the same instant in the config timezone.
        $this->assertSame(['vf', 'vt'], array_keys($result));
        $this->assertInstanceOf(CarbonImmutable::class, $result['vf']);
        $this->assertInstanceOf(CarbonImmutable::class, $result['vt']);
        $this->assertNotNull($spell->from);
        $this->assertNotNull($spell->to);
        $this->assertTrue($spell->from->equalTo($result['vf']));
        $this->assertTrue($spell->to->equalTo($result['vt']));
    }

    public function test_set_normalizes_bounds_to_the_config_timezone(): void
    {
        // Issue #69: a bound anchored to a non-config zone must be stored as the
        // configured-TZ wall-clock, so get() (which reads offset-less strings in
        // the config TZ) reconstructs the same instant. New York 09:00 is 14:00
        // UTC; the persisted wall-clock must be 14:00, not a verbatim 09:00 that
        // get() would misread as 09:00 UTC.
        config()->set('bitemporal.spells.timezone', 'UTC');

        $cast = new CompositeSpellCast('vf', 'vt');
        $spell = new Spell(
            CarbonImmutable::parse('2026-01-01 09:00:00', 'America/New_York'),
            null,
        );

        $result = $cast->set($this->model(), 'spell', $spell, []);

        $this->assertInstanceOf(CarbonImmutable::class, $result['vf']);
        $this->assertSame('2026-01-01 14:00:00', $result['vf']->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $result['vf']->getTimezone()->getName());
        $this->assertNotNull($spell->from);
        $this->assertTrue($spell->from->equalTo($result['vf']));
    }

    public function test_spell_round_trips_through_set_then_get(): void
    {
        // The full residual-of-#42 scenario: build a Spell whose bound is not in
        // the config zone, persist it via set(), read it back via get() (with the
        // offset dropped, as MySQL DATETIME / SQLite TEXT would), and assert the
        // instant survives.
        config()->set('bitemporal.spells.timezone', 'UTC');

        $cast = new CompositeSpellCast('valid_from', 'valid_to');
        $spell = new Spell(
            CarbonImmutable::parse('2024-06-01 00:00:00', 'America/New_York'),
            null,
        );

        $stored = $cast->set($this->model(), 'spell', $spell, []);
        $storedFrom = $stored['valid_from'];

        $reloaded = $this->getSpell([
            'valid_from' => $storedFrom instanceof CarbonImmutable ? $storedFrom->format('Y-m-d H:i:s') : null,
            'valid_to' => null,
        ]);

        $this->assertInstanceOf(CarbonImmutable::class, $reloaded->from);
        $this->assertNotNull($spell->from);
        $this->assertTrue($spell->from->equalTo($reloaded->from), 'the reloaded instant must equal the original');
    }

    public function test_set_returns_empty_array_for_non_spell(): void
    {
        $cast = $this->cast();

        // Kills the InstanceOf/LogicalNot mutants that would skip the early return.
        $this->assertSame([], $cast->set($this->model(), 'spell', null, []));

        // Eloquent calls set() with an arbitrary mixed value at runtime (the
        // native signature is `mixed $value`), so invoke it reflectively with a
        // non-Spell value to pin the instanceof guard without lying about types.
        $set = new \ReflectionMethod($cast, 'set');
        $this->assertSame([], $set->invoke($cast, $this->model(), 'spell', 'not-a-spell', []));
    }
}
