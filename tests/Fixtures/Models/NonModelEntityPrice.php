<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use stdClass;
use Vusys\Bitemporal\Bitemporal;

/**
 * Deliberately misconfigured: $temporalEntity points at a non-Model class, so
 * temporalEntityRelation() must reject it. Used to exercise the class-string
 * guard in isolation (boot guards suppressed).
 */
class NonModelEntityPrice extends Model
{
    use Bitemporal;

    /** @var class-string */
    protected string $temporalEntity = stdClass::class;

    protected $guarded = [];
}
