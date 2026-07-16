<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Docs\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Vusys\Bitemporal\Bitemporal;

/**
 * @property int $id
 * @property int $employee_id
 * @property string $component
 * @property string $annual_amount
 * @property CarbonImmutable $valid_from
 * @property CarbonImmutable|null $valid_to
 * @property CarbonImmutable $recorded_from
 * @property CarbonImmutable|null $recorded_to
 * @property bool $is_retraction
 */
class Compensation extends Model
{
    use Bitemporal;

    /**
     * @var array<int, string>
     */
    protected array $temporalDimensions = ['component'];

    // The docs create a `compensations` table, but "compensation" is uncountable
    // in Laravel's inflector, so the model would otherwise resolve to the
    // singular `compensation`. Pin the table to match the migration.
    protected $table = 'compensations';

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function temporalEntity(): BelongsTo
    {
        // FK pinned to match the column bitemporalForeignFor() emits — see PolicyCoverage.
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
