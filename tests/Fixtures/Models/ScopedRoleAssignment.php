<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Fixtures\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Vusys\Bitemporal\Bitemporal;

/**
 * A temporal pivot on the same table as UserRoleAssignment, but declaring the
 * pivot's `scope` column as an extra temporal dimension. Used to exercise the
 * pivot dimension-tuple folding on BitemporalBelongsToMany.
 *
 * @property int $id
 * @property int $user_id
 * @property int $role_id
 * @property string|null $scope
 * @property CarbonImmutable $valid_from
 * @property CarbonImmutable|null $valid_to
 * @property CarbonImmutable $recorded_from
 * @property CarbonImmutable|null $recorded_to
 * @property bool $is_retraction
 */
class ScopedRoleAssignment extends Pivot
{
    use Bitemporal;

    public $incrementing = true;

    protected $table = 'user_role_assignments';

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /**
     * @var array<int, string>
     */
    protected array $temporalDimensions = ['scope'];
}
