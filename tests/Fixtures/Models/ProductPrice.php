<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Fixtures\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Vusys\Bitemporal\Bitemporal;
use Vusys\Bitemporal\Tests\Fixtures\Factories\ProductPriceFactory;

/**
 * @property int $id
 * @property int $product_id
 * @property int|null $amount
 * @property string|null $currency
 * @property CarbonImmutable $valid_from
 * @property CarbonImmutable|null $valid_to
 * @property CarbonImmutable $recorded_from
 * @property CarbonImmutable|null $recorded_to
 * @property bool $is_retraction
 */
class ProductPrice extends Model
{
    use Bitemporal;

    /** @use HasFactory<ProductPriceFactory> */
    use HasFactory;

    protected $table = 'product_price_versions';

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'amount' => 'integer',
    ];

    protected static function newFactory(): ProductPriceFactory
    {
        return ProductPriceFactory::new();
    }

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
