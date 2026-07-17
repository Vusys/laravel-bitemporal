<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Docs\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
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

    // The library derives the BelongsTo and its policy_id foreign key from this
    // class — the same column bitemporalForeignFor() emits in the migration.
    protected string $temporalEntity = Policy::class;

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';
}
