<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Docs\Models;

use Illuminate\Database\Eloquent\Model;
use Vusys\Bitemporal\Concerns\HasBitemporalRelations;
use Vusys\Bitemporal\Relations\BitemporalBelongsToMany;

/**
 * @property int $id
 * @property string $tier
 */
class Plan extends Model
{
    use HasBitemporalRelations;

    protected $guarded = [];

    /**
     * @return BitemporalBelongsToMany<$this>
     */
    public function features(): BitemporalBelongsToMany
    {
        return $this->bitemporalBelongsToMany(Feature::class)
            ->using(PlanFeature::class);
    }
}
