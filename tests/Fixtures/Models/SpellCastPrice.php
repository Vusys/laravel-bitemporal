<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Fixtures\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Vusys\Bitemporal\Casts\CompositeSpellCast;
use Vusys\Bitemporal\Spell;

/**
 * A plain model exposing a synthetic Spell over two offset-less datetime
 * columns via CompositeSpellCast. Used to prove the config-timezone round-trip
 * (issue #69) end-to-end through a real save + reload.
 *
 * @property Spell $valid_spell
 * @property CarbonImmutable $valid_from
 * @property CarbonImmutable|null $valid_to
 */
class SpellCastPrice extends Model
{
    protected $table = 'spell_cast_prices';

    protected $guarded = [];

    public $timestamps = false;

    // Microsecond, offset-less storage — as MySQL DATETIME(6) / SQLite TEXT.
    protected $dateFormat = 'Y-m-d H:i:s.u';

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'valid_spell' => CompositeSpellCast::class.':valid_from,valid_to',
        'valid_from' => 'datetime',
        'valid_to' => 'datetime',
    ];
}
