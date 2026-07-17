<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Vusys\Bitemporal\Bitemporal;
use Vusys\Bitemporal\Boot\Lints\BootLintCompactionExcludesDomainColumn;

/**
 * Suppresses the (first) compaction lint while still tripping the (second)
 * mutable-datetime-cast lint. Used to prove that BootLints::runAgainst skips a
 * suppressed lint with `continue` (and keeps checking later lints) rather than
 * `break`ing out of the loop.
 */
class MutableCastSuppressingCompactionPrice extends Model
{
    use Bitemporal;

    protected $table = 'product_price_versions';

    protected $guarded = [];

    protected bool $autoApplyTemporalCasts = false;

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'valid_from' => 'datetime',
    ];

    /**
     * @var array<int, class-string>
     */
    protected array $suppressedBootLints = [
        BootLintCompactionExcludesDomainColumn::class,
    ];

    /**
     * @return BelongsTo<Product, $this>
     */
    public function temporalEntityRelation(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
