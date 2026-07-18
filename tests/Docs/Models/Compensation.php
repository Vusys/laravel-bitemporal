<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Docs\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Vusys\Bitemporal\Bitemporal;

/**
 * @property int $id
 * @property int $employee_id
 * @property string $component
 * @property int $annual_amount
 * @property CarbonImmutable $valid_from
 * @property CarbonImmutable|null $valid_to
 * @property CarbonImmutable $recorded_from
 * @property CarbonImmutable|null $recorded_to
 * @property bool $is_retraction
 */
class Compensation extends Model
{
    use Bitemporal;

    /**
     * @var array<int, string>
     */
    protected array $temporalDimensions = ['component'];

    // The entity this table versions. The library builds the BelongsTo on the
    // natural `employee_id` key — the same column bitemporalForeignFor() emits —
    // so schema and relation cannot drift.
    protected string $temporalEntity = Employee::class;

    // The docs create a `compensations` table, but "compensation" is uncountable
    // in Laravel's inflector, so the model would otherwise resolve to the
    // singular `compensation`. Pin the table to match the migration.
    protected $table = 'compensations';

    protected $guarded = [];

    // annual_amount is a decimal column, so pgsql/mysql hand it back as a string
    // ("5000.00"). Cast it so plain integer values round-trip and optimistic
    // `expectedCurrentAttributes` checks compare like-for-like across drivers.
    protected $casts = [
        'annual_amount' => 'integer',
    ];
}
