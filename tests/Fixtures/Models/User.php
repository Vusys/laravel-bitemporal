<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Vusys\Bitemporal\Concerns\HasBitemporalRelations;
use Vusys\Bitemporal\Relations\BitemporalBelongsToMany;

/**
 * @property int $id
 * @property string $name
 */
class User extends Model
{
    use HasBitemporalRelations;

    protected $guarded = [];

    /**
     * @return BitemporalBelongsToMany<$this>
     */
    public function roles(): BitemporalBelongsToMany
    {
        return $this->bitemporalBelongsToMany(Role::class)
            ->using(UserRoleAssignment::class);
    }
}
