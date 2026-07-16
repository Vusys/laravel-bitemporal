<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Docs\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Vusys\Bitemporal\Bitemporal;

/**
 * @property int $id
 * @property int $policy_id
 * @property string $limit
 * @property string $deductible
 * @property string $premium
 * @property CarbonImmutable $valid_from
 * @property CarbonImmutable|null $valid_to
 * @property CarbonImmutable $recorded_from
 * @property CarbonImmutable|null $recorded_to
 * @property bool $is_retraction
 */
class PolicyCoverage extends Model
{
    use Bitemporal;

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /**
     * @return BelongsTo<Policy, $this>
     */
    public function temporalEntity(): BelongsTo
    {
        // FK pinned to match the policy_id column bitemporalForeignFor() emits;
        // otherwise Eloquent guesses temporal_entity_id from the method name.
        return $this->belongsTo(Policy::class, 'policy_id');
    }
}
