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
class TaxJurisdiction extends Model
{
    use HasBitemporalRelations;

    protected $guarded = [];

    /**
     * @return BitemporalMany<TaxRate, $this>
     */
    public function rates(): BitemporalMany
    {
        return $this->bitemporalMany(TaxRate::class);
    }
}
