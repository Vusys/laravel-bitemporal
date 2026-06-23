<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Vusys\Bitemporal\Bitemporal;
use Vusys\Bitemporal\Boot\Lints\BootLintCompactionExcludesDomainColumn;

/**
 * Deliberately broken: its primary key is a temporal column. Used to exercise
 * BootGuardPrimaryKey (booted via TemporalLens::withoutBootGuards so the guard
 * can be invoked in isolation). Also suppresses one lint for the suppression test.
 */
class CollidingKeyModel extends Model
{
    use Bitemporal;

    protected $table = 'product_price_versions';

    protected $primaryKey = 'valid_from';

    protected $guarded = [];

    /**
     * @var array<int, class-string>
     */
    protected array $suppressedBootLints = [
        BootLintCompactionExcludesDomainColumn::class,
    ];

    /**
     * @return BelongsTo<Product, $this>
     */
    public function temporalEntity(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
