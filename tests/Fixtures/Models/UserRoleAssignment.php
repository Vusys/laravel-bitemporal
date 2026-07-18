<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Fixtures\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Vusys\Bitemporal\Bitemporal;

/**
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
class UserRoleAssignment extends Pivot
{
    use Bitemporal;

    public $incrementing = true;

    protected $table = 'user_role_assignments';

    protected $guarded = [];
}
