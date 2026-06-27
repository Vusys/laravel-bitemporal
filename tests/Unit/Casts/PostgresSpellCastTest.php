<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Unit\Casts;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Vusys\Bitemporal\Casts\PostgresSpellCast;
use Vusys\Bitemporal\Spell;
use Vusys\Bitemporal\Tests\TestCase;

/**
 * Pins PostgresSpellCast::get()/set(): get() yields the Spell unchanged and
 * coerces anything else to null; set() echoes the keyed value back.
 */
final class PostgresSpellCastTest extends TestCase
{
    private function model(): Model
    {
        return new class extends Model {};
    }

    public function test_get_returns_spell_unchanged(): void
    {
        $spell = new Spell(CarbonImmutable::parse('2026-01-01'), null);
        $cast = new PostgresSpellCast;

        // Kills `false ? ...` and the swapped-ternary mutants (would return null).
        $this->assertSame($spell, $cast->get($this->model(), 'spell', $spell, []));
    }

    public function test_get_returns_null_for_non_spell_value(): void
    {
        $cast = new PostgresSpellCast;

        // Kills `true ? ...` and the swapped-ternary mutants (would return the value).
        $this->assertNull($cast->get($this->model(), 'spell', 'tstzrange-string', []));
        $this->assertNull($cast->get($this->model(), 'spell', null, []));
    }

    public function test_set_echoes_keyed_value(): void
    {
        $cast = new PostgresSpellCast;
        $spell = new Spell(CarbonImmutable::parse('2026-01-01'), null);

        // Kills ArrayItemRemoval (would return []).
        $this->assertSame(['valid_spell' => $spell], $cast->set($this->model(), 'valid_spell', $spell, []));
    }
}
