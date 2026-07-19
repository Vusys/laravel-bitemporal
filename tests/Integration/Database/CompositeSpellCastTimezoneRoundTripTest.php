<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Database;

use Illuminate\Support\Facades\Schema;
use Vusys\Bitemporal\Spell;
use Vusys\Bitemporal\Tests\Fixtures\Models\SpellCastPrice;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

/**
 * End-to-end timezone round-trip for CompositeSpellCast (issue #69, residual of
 * #42). When the ambient PHP default timezone differs from
 * bitemporal.spells.timezone, a Spell built from a bare string and persisted to
 * an offset-less table must reload as the same instant. The write side anchors
 * both Spell::parse() and CompositeSpellCast::set() to the config timezone;
 * without that the stored offset-less wall-clock is re-read as a different
 * instant and boundary comparisons silently break.
 */
final class CompositeSpellCastTimezoneRoundTripTest extends IntegrationTestCase
{
    private string $originalTimezone;

    protected function setUp(): void
    {
        parent::setUp();

        // Spells are stored/compared in UTC; the app runs in a different zone.
        config()->set('bitemporal.spells.timezone', 'UTC');
        $this->originalTimezone = date_default_timezone_get();
        date_default_timezone_set('America/New_York');

        Schema::create('spell_cast_prices', function ($table): void {
            $table->id();
            $table->dateTime('valid_from', 6);
            $table->dateTime('valid_to', 6)->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('spell_cast_prices');
        date_default_timezone_set($this->originalTimezone);

        parent::tearDown();
    }

    public function test_bare_string_spell_round_trips_to_the_same_instant(): void
    {
        // A bare wall-clock string: Spell::parse anchors it to the config TZ
        // (UTC), not the ambient New York default, so this is midnight UTC.
        $original = Spell::between('2024-06-01 00:00:00', '2024-12-01 12:30:00');

        $model = new SpellCastPrice;
        $model->valid_spell = $original;
        $model->save();

        // Reload from storage: the offset-less columns come back as strings and
        // get() parses them in the config TZ.
        $reloaded = SpellCastPrice::query()->whereKey($model->getKey())->sole();
        $spell = $reloaded->valid_spell;

        $this->assertInstanceOf(Spell::class, $spell);
        $this->assertNotNull($spell->from);
        $this->assertNotNull($spell->to);
        $this->assertNotNull($original->from);
        $this->assertNotNull($original->to);
        $this->assertTrue($original->from->equalTo($spell->from), 'the reloaded lower bound must equal the original instant');
        $this->assertTrue($original->to->equalTo($spell->to), 'the reloaded upper bound must equal the original instant');
    }

    public function test_open_ended_bare_string_spell_round_trips(): void
    {
        $original = Spell::startingAt('2024-06-01 00:00:00');

        $model = new SpellCastPrice;
        $model->valid_spell = $original;
        $model->save();

        $spell = SpellCastPrice::query()->whereKey($model->getKey())->sole()->valid_spell;

        $this->assertInstanceOf(Spell::class, $spell);
        $this->assertNotNull($spell->from);
        $this->assertNull($spell->to);
        $this->assertNotNull($original->from);
        $this->assertTrue($original->from->equalTo($spell->from));
    }
}
