<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Unit\Support\Mutation;

use Vusys\Bitemporal\Exceptions\TemporalInvalidSpellException;
use Vusys\Bitemporal\Facades\TemporalLens;
use Vusys\Bitemporal\Support\EntityScope;
use Vusys\Bitemporal\Tests\Fixtures\Models\MisrelatedPrice;
use Vusys\Bitemporal\Tests\Fixtures\Models\Product;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Fixtures\Models\Role;
use Vusys\Bitemporal\Tests\TestCase;

/**
 * Mutation coverage for {@see EntityScope::resolve()}: the two throw paths and
 * the BelongsTo instanceof branch.
 */
final class EntityScopeMutationTest extends TestCase
{
    public function test_resolves_a_belongs_to_entity_to_its_foreign_key(): void
    {
        $scope = EntityScope::resolve(new ProductPrice, tap(new Product, fn (Product $p) => $p->setAttribute('id', 9)));

        $this->assertSame(['product_id' => 9], $scope);
    }

    // Kills the first throw's Concat / ConcatOperandRemoval / Throw_ mutants:
    // a related model without a temporalEntity() method must raise the
    // configuration error with the class name + the descriptive suffix.
    public function test_missing_relation_method_throws_with_class_and_message(): void
    {
        try {
            EntityScope::resolve(new Role, new Product);
            $this->fail('Expected a TemporalInvalidSpellException.');
        } catch (TemporalInvalidSpellException $exception) {
            $this->assertStringStartsWith(Role::class, $exception->getMessage());
            $this->assertStringContainsString('must define a temporalEntity() relation', $exception->getMessage());
        }
    }

    // Kills the BelongsTo InstanceOf_ -> true mutant (it would return a scope
    // array for a HasMany) and the second Throw_ mutant (removing the throw lets
    // the array-typed method fall through -> TypeError).
    public function test_non_belongs_to_relation_is_rejected(): void
    {
        // MisrelatedPrice is deliberately misconfigured, so its boot guards
        // throw on construction; suppress them to reach EntityScope itself.
        $related = TemporalLens::withoutBootGuards(fn (): MisrelatedPrice => new MisrelatedPrice);

        try {
            EntityScope::resolve($related, new Product);
            $this->fail('Expected a TemporalInvalidSpellException.');
        } catch (TemporalInvalidSpellException $exception) {
            $this->assertStringContainsString('BelongsTo or MorphTo relation', $exception->getMessage());
        }
    }
}
