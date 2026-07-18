<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Fixtures\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Vusys\Bitemporal\Bitemporal;

/**
 * A first-class effective-dated-only price: valid time only, no recorded spell.
 * Its table carries no recorded_from/recorded_to columns, so writes must
 * overwrite superseded rows physically rather than closing a recorded spell.
 *
 * @property int $id
 * @property int $product_id
 * @property int|null $amount
 * @property CarbonImmutable $valid_from
 * @property CarbonImmutable|null $valid_to
 */
class EffectivePrice extends Model
{
    use Bitemporal;

    protected $table = 'effective_prices';

    protected $guarded = [];

    protected string $temporalEntity = Product::class;

    protected bool $tracksRecordedTime = false;
}
