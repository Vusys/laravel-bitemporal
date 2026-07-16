<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Docs\Models;

use Illuminate\Database\Eloquent\Model;
use Vusys\Bitemporal\Concerns\HasBitemporalRelations;
use Vusys\Bitemporal\Relations\BitemporalMany;

/**
 * @property int $id
 * @property string $name
 */
class Account extends Model
{
    use HasBitemporalRelations;

    protected $guarded = [];

    /**
     * @return BitemporalMany<Subscription, $this>
     */
    public function subscription(): BitemporalMany
    {
        return $this->bitemporalMany(Subscription::class);
    }
}
