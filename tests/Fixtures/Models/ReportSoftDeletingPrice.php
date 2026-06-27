<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Vusys\Bitemporal\Bitemporal;

/**
 * Intentionally misconfigured (SoftDeletes on a temporal model) so it fails a
 * boot guard. Dedicated to BootDiagnosticsReport tests, which instantiate it
 * with guards disabled; using a private fixture keeps the trait's per-class
 * "guards already run" cache from leaking into other suites.
 */
class ReportSoftDeletingPrice extends Model
{
    use Bitemporal;
    use SoftDeletes;

    protected $table = 'product_price_versions';

    protected $guarded = [];

    /**
     * @return BelongsTo<Product, $this>
     */
    public function temporalEntity(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
