<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Unit\Concerns;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Vusys\Bitemporal\Exceptions\TemporalConfigurationException;
use Vusys\Bitemporal\Facades\TemporalLens;
use Vusys\Bitemporal\Tests\Docs\Models\Compensation;
use Vusys\Bitemporal\Tests\Docs\Models\Employee;
use Vusys\Bitemporal\Tests\Fixtures\Models\NonModelEntityPrice;
use Vusys\Bitemporal\Tests\Fixtures\Models\UserRoleAssignment;
use Vusys\Bitemporal\Tests\TestCase;

/**
 * Coverage for the trait default temporalEntityRelation(): building the BelongsTo
 * from the $temporalEntity class-string on the natural foreign key, and the two
 * rejection paths (no declaration; a non-Model class-string).
 */
final class HasTemporalEntityRelationTest extends TestCase
{
    public function test_property_builds_a_belongs_to_on_the_natural_foreign_key(): void
    {
        $relation = (new Compensation)->temporalEntityRelation();

        $this->assertInstanceOf(BelongsTo::class, $relation);
        // Derived from Employee's natural key — the column bitemporalForeignFor() emits.
        $this->assertSame('employee_id', $relation->getForeignKeyName());
        $this->assertInstanceOf(Employee::class, $relation->getRelated());
    }

    public function test_a_model_without_a_declaration_is_rejected(): void
    {
        // The pivot declares neither $temporalEntity nor an override.
        $this->expectException(TemporalConfigurationException::class);
        $this->expectExceptionMessage('temporalEntityRelation() method');

        (new UserRoleAssignment)->temporalEntityRelation();
    }

    public function test_a_non_model_class_string_is_rejected(): void
    {
        // Boot guards would reject this at construction; suppress them to reach
        // the resolver's class-string guard directly.
        $model = TemporalLens::withoutBootGuards(fn (): NonModelEntityPrice => new NonModelEntityPrice);
        $this->assertInstanceOf(NonModelEntityPrice::class, $model);

        $this->expectException(TemporalConfigurationException::class);

        $model->temporalEntityRelation();
    }
}
