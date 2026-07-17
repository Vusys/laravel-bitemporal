<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Docs\Models;

use Illuminate\Database\Eloquent\Model;
use Vusys\Bitemporal\Concerns\HasBitemporalRelations;
use Vusys\Bitemporal\Relations\BitemporalMany;

/**
 * @property int $id
 * @property string|null $reference
 */
class Policy extends Model
{
    use HasBitemporalRelations;

    protected $guarded = [];

    /**
     * @return BitemporalMany<PolicyCoverage, $this>
     */
    public function coverages(): BitemporalMany
    {
        return $this->bitemporalMany(PolicyCoverage::class);
    }
}
