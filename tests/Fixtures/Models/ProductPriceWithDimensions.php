<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Fixtures\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Vusys\Bitemporal\Bitemporal;

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
class ProductPriceWithDimensions extends Model
{
    use Bitemporal;

    protected $table = 'dimensioned_prices';

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'amount' => 'integer',
    ];

    /**
     * @var array<int, string>
     */
    protected array $temporalDimensions = ['currency'];

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
