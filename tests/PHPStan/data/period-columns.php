<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\PHPStan\Data;

use Illuminate\Database\Eloquent\Model;

use function PHPStan\Testing\assertType;

use Vusys\Bitemporal\Bitemporal;
use Vusys\Bitemporal\Tests\Fixtures\Models\Product;

/** A temporal model with no @property docblock: the extension alone says what these are. */
class BarePrice extends Model
{
    use Bitemporal;

    protected string $temporalEntity = Product::class;
}

class BareChildPrice extends BarePrice {}

function period(BarePrice $price, BareChildPrice $child): void
{
    assertType('Carbon\CarbonImmutable', $price->valid_from);
    assertType('Carbon\CarbonImmutable|null', $price->valid_to);
    assertType('Carbon\CarbonImmutable', $price->recorded_from);
    assertType('Carbon\CarbonImmutable|null', $price->recorded_to);
    assertType('bool', $price->is_retraction);
    assertType('Carbon\CarbonImmutable|null', $child->valid_to);
}
