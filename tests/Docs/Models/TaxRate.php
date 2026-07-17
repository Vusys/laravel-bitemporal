<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Docs\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Vusys\Bitemporal\Bitemporal;

/**
 * @property int $id
 * @property int $tax_jurisdiction_id
 * @property string $category
 * @property string $rate
 * @property CarbonImmutable $valid_from
 * @property CarbonImmutable|null $valid_to
 * @property CarbonImmutable $recorded_from
 * @property CarbonImmutable|null $recorded_to
 * @property bool $is_retraction
 */
class TaxRate extends Model
{
    use Bitemporal;

    /**
     * @var array<int, string>
     */
    protected array $temporalDimensions = ['category'];

    protected string $temporalEntity = TaxJurisdiction::class;

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';
}
