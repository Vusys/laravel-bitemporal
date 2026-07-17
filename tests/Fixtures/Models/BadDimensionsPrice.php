<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Vusys\Bitemporal\Bitemporal;
use Vusys\Bitemporal\Support\TemporalEntityMetadata;

/**
 * Deliberately broken: temporalDimensions() yields a non-string element, which
 * BootGuardDimensions must reject. The non-string sits *after* a valid string so
 * the guard has to actually iterate the list (not just look at the first item).
 *
 * The list is returned from an override (rather than a typed $temporalDimensions
 * property) so the deliberately-invalid element stays out of the temporal
 * metadata pipeline, which legitimately requires a list of column-name strings.
 */
class BadDimensionsPrice extends Model
{
    use Bitemporal;

    protected $table = 'product_price_versions';

    protected $guarded = [];

    /**
     * @return array<int, mixed>
     */
    public function temporalDimensions(): array
    {
        return ['currency', 123];
    }

    public function temporalMetadata(): TemporalEntityMetadata
    {
        return new TemporalEntityMetadata(
            $this->validFromColumn(),
            $this->validToColumn(),
            $this->recordedFromColumn(),
            $this->recordedToColumn(),
            $this->isRetractionColumn(),
            $this->tracksRecordedTime(),
            [],
        );
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function temporalEntityRelation(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
