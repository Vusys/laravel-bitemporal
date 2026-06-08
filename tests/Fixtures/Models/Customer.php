<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Vusys\Bitemporal\Concerns\HasBitemporalRelations;
use Vusys\Bitemporal\Relations\BitemporalMany;

/**
 * @property int $id
 * @property string $name
 */
class Customer extends Model
{
    use HasBitemporalRelations;

    protected $guarded = [];

    /**
     * @return BitemporalMany<Address, $this>
     */
    public function addresses(): BitemporalMany
    {
        return $this->bitemporalMorphMany(Address::class);
    }
}
