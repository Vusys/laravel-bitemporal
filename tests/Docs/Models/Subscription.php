<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Docs\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Vusys\Bitemporal\Bitemporal;

/**
 * @property int $id
 * @property int $account_id
 * @property string $region
 * @property string $plan
 * @property int $seats
 * @property CarbonImmutable $valid_from
 * @property CarbonImmutable|null $valid_to
 * @property CarbonImmutable $recorded_from
 * @property CarbonImmutable|null $recorded_to
 * @property bool $is_retraction
 */
class Subscription extends Model
{
    use Bitemporal;

    /**
     * @var array<int, string>
     */
    protected array $temporalDimensions = ['region'];

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'seats' => 'integer',
    ];

    /**
     * @return BelongsTo<Account, $this>
     */
    public function temporalEntity(): BelongsTo
    {
        // FK pinned to match the column bitemporalForeignFor() emits — see PolicyCoverage.
        return $this->belongsTo(Account::class, 'account_id');
    }
}
