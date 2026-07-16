<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Unit\Casts;

use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;
use Vusys\Bitemporal\Casts\PostgresSpellCast;
use Vusys\Bitemporal\Spell;

/**
 * PostgresSpellCast maps a PostgreSQL tstzrange text form to/from a half-open
 * Spell, with open bounds mapping to null.
 */
final class PostgresSpellCastTest extends TestCase
{
    private function cast(): PostgresSpellCast
    {
        return new PostgresSpellCast;
    }

    private function model(): Model
    {
        return $this->createStub(Model::class);
    }

    public function test_parses_a_bounded_tstzrange(): void
    {
        $spell = $this->cast()->get($this->model(), 'valid_period', '["2024-01-01 00:00:00+00","2024-06-01 00:00:00+00")', []);

        $this->assertInstanceOf(Spell::class, $spell);
        $this->assertNotNull($spell->from);
        $this->assertNotNull($spell->to);
        $this->assertSame('2024-01-01 00:00:00', $spell->from->format('Y-m-d H:i:s'));
        $this->assertSame('2024-06-01 00:00:00', $spell->to->format('Y-m-d H:i:s'));
    }

    public function test_parses_an_open_ended_upper_bound_as_null(): void
    {
        $spell = $this->cast()->get($this->model(), 'valid_period', '["2024-01-01 00:00:00+00",)', []);

        $this->assertInstanceOf(Spell::class, $spell);
        $this->assertNotNull($spell->from);
        $this->assertNull($spell->to);
    }

    public function test_parses_an_open_lower_bound_as_null(): void
    {
        $spell = $this->cast()->get($this->model(), 'recorded_period', '(,"2024-06-01 00:00:00+00")', []);

        $this->assertInstanceOf(Spell::class, $spell);
        $this->assertNull($spell->from);
        $this->assertNotNull($spell->to);
    }

    public function test_empty_and_non_string_values_read_as_null(): void
    {
        $this->assertNull($this->cast()->get($this->model(), 'valid_period', null, []));
        $this->assertNull($this->cast()->get($this->model(), 'valid_period', 'empty', []));
        $this->assertNull($this->cast()->get($this->model(), 'valid_period', '', []));
    }

    public function test_passes_through_an_existing_spell(): void
    {
        $spell = Spell::between('2024-01-01', '2024-02-01');

        $this->assertSame($spell, $this->cast()->get($this->model(), 'valid_period', $spell, []));
    }

    public function test_formats_a_bounded_spell_to_a_half_open_range(): void
    {
        $out = $this->cast()->set($this->model(), 'valid_period', Spell::between('2024-01-01', '2024-06-01'), []);

        $this->assertArrayHasKey('valid_period', $out);
        $this->assertIsString($out['valid_period']);
        $this->assertMatchesRegularExpression('/^\["2024-01-01[^"]*","2024-06-01[^"]*"\)$/', $out['valid_period']);
    }

    public function test_formats_an_unbounded_upper_side_as_an_open_bound(): void
    {
        $out = $this->cast()->set($this->model(), 'valid_period', Spell::startingAt('2024-01-01'), []);

        $this->assertIsString($out['valid_period']);
        $this->assertMatchesRegularExpression('/^\["2024-01-01[^"]*",\)$/', $out['valid_period']);
    }

    public function test_round_trips_through_format_and_parse(): void
    {
        $original = Spell::between('2024-01-01 08:30:00', '2024-12-31 23:59:59');

        $stored = $this->cast()->set($this->model(), 'valid_period', $original, []);
        $reloaded = $this->cast()->get($this->model(), 'valid_period', $stored['valid_period'], []);

        $this->assertInstanceOf(Spell::class, $reloaded);
        $this->assertNotNull($reloaded->from);
        $this->assertNotNull($reloaded->to);
        $this->assertNotNull($original->from);
        $this->assertNotNull($original->to);
        $this->assertTrue($original->from->equalTo($reloaded->from));
        $this->assertTrue($original->to->equalTo($reloaded->to));
    }
}
