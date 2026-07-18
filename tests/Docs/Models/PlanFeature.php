<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Docs\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Vusys\Bitemporal\Bitemporal;

/**
 * @property int $id
 * @property int $plan_id
 * @property int $feature_id
 * @property CarbonImmutable $valid_from
 * @property CarbonImmutable|null $valid_to
 * @property CarbonImmutable $recorded_from
 * @property CarbonImmutable|null $recorded_to
 * @property bool $is_retraction
 */
class PlanFeature extends Pivot
{
    use Bitemporal;

    public $incrementing = true;         // each version is its own row

    protected $table = 'plan_feature';

    protected $guarded = [];
}
