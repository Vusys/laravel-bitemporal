<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Vusys\Bitemporal\Bitemporal;
use Vusys\Bitemporal\Tests\Fixtures\Models\Product;

/**
 * Lives in the App\Models namespace on purpose: the migration command prepends
 * "App\Models\" to bare (un-namespaced) model names, so this fixture exercises
 * that resolution path. Loaded via require_once from the mutation test.
 *
 * @property int $id
 * @property int $product_id
 */
class MigrationProbe extends Model
{
    use Bitemporal;

    protected $table = 'app_probe_prices';

    protected $guarded = [];

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function temporalEntity(): BelongsTo
    {
        return $this->product();
    }
}
