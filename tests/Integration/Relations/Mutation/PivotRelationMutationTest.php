<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Relations\Mutation;

use Vusys\Bitemporal\Exceptions\TemporalCardinalityException;
use Vusys\Bitemporal\Exceptions\TemporalConfigurationException;
use Vusys\Bitemporal\Relations\BitemporalBelongsToMany;
use Vusys\Bitemporal\Relations\BitemporalMany;
use Vusys\Bitemporal\Relations\BitemporalOne;
use Vusys\Bitemporal\Tests\Fixtures\Models\Address;
use Vusys\Bitemporal\Tests\Fixtures\Models\Customer;
use Vusys\Bitemporal\Tests\Fixtures\Models\MisrelatedPrice;
use Vusys\Bitemporal\Tests\Fixtures\Models\ProductPrice;
use Vusys\Bitemporal\Tests\Fixtures\Models\Role;
use Vusys\Bitemporal\Tests\Fixtures\Models\ScopedRoleAssignment;
use Vusys\Bitemporal\Tests\Fixtures\Models\User;
use Vusys\Bitemporal\Tests\Fixtures\Models\UserRoleAssignment;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

/**
 * Mutation-killing coverage for BitemporalBelongsToMany, the HasBitemporalRelations
 * factory trait, and BitemporalMany / BitemporalOne sole().
 */
final class PivotRelationMutationTest extends IntegrationTestCase
{
    private function makeUser(string $name = 'Ada'): User
    {
        return User::query()->create(['name' => $name]);
    }

    private function makeRole(string $name = 'admin'): Role
    {
        return Role::query()->create(['name' => $name]);
    }

    // ---------------------------------------------------------------
    // HasBitemporalRelations: public visibility (called externally)
    // ---------------------------------------------------------------

    public function test_relation_factory_methods_are_publicly_callable(): void
    {
        $product = $this->makeProduct();
        $user = $this->makeUser();

        $this->assertInstanceOf(BitemporalMany::class, $product->bitemporalMany(ProductPrice::class));
        $this->assertInstanceOf(BitemporalMany::class, (new Customer)->bitemporalMorphMany(Address::class));
        $this->assertInstanceOf(BitemporalOne::class, $product->bitemporalOne(ProductPrice::class));
        $this->assertInstanceOf(BitemporalOne::class, $product->bitemporalOneOrFail(ProductPrice::class));
        $this->assertInstanceOf(BitemporalBelongsToMany::class, $user->bitemporalBelongsToMany(Role::class));
    }

    // ---------------------------------------------------------------
    // HasBitemporalRelations: ??= coalesce overrides for explicit keys
    // ---------------------------------------------------------------

    public function test_bitemporal_many_honours_explicit_keys(): void
    {
        $product = $this->makeProduct();

        $byForeign = $product->bitemporalMany(ProductPrice::class, 'weird_fk');
        $this->assertSame('weird_fk', $byForeign->getForeignKeyName());

        $byLocal = $product->bitemporalMany(ProductPrice::class, null, 'weird_local');
        $this->assertSame('weird_local', $byLocal->getLocalKeyName());
    }

    public function test_bitemporal_one_honours_explicit_keys(): void
    {
        $product = $this->makeProduct();

        $byForeign = $product->bitemporalOne(ProductPrice::class, 'weird_fk');
        $this->assertSame('weird_fk', $byForeign->getForeignKeyName());

        $byLocal = $product->bitemporalOne(ProductPrice::class, null, 'weird_local');
        $this->assertSame('weird_local', $byLocal->getLocalKeyName());
    }

    public function test_bitemporal_belongs_to_many_honours_explicit_foreign_and_parent_keys(): void
    {
        $user = $this->makeUser();

        $byForeign = $user->bitemporalBelongsToMany(Role::class, foreignPivotKey: 'weird_uid');
        $this->assertSame('weird_uid', $byForeign->getForeignKeyName());

        $byParent = $user->bitemporalBelongsToMany(Role::class, parentKey: 'weird_parent');
        $this->assertSame('weird_parent', $byParent->getLocalKeyName());
    }

    public function test_bitemporal_belongs_to_many_honours_explicit_related_pivot_key(): void
    {
        $user = $this->makeUser();
        $role = $this->makeRole();

        // A custom related pivot key (distinct from the computed default
        // "role_id") must be used verbatim: it surfaces in the tuple label of
        // the resulting cardinality exception.
        $relation = $user->bitemporalBelongsToMany(Role::class, relatedPivotKey: 'custom_rid')
            ->using(UserRoleAssignment::class);

        try {
            $relation->detachAt($role, '2026-09-01');
            $this->fail('Expected a cardinality exception.');
        } catch (TemporalCardinalityException $exception) {
            $this->assertStringContainsString("custom_rid={$role->getKey()}", $exception->getMessage());
        }
    }

    // ---------------------------------------------------------------
    // HasBitemporalRelations: relation-type guards
    // ---------------------------------------------------------------

    public function test_bitemporal_many_rejects_a_non_belongs_to_temporal_entity(): void
    {
        $product = $this->makeProduct();

        $this->expectException(TemporalConfigurationException::class);
        $this->expectExceptionMessageMatches('/BelongsTo/');

        $product->bitemporalMany(MisrelatedPrice::class);
    }

    public function test_bitemporal_many_rejects_a_related_without_temporal_entity(): void
    {
        $product = $this->makeProduct();

        $this->expectException(TemporalConfigurationException::class);

        $product->bitemporalMany(UserRoleAssignment::class);
    }

    public function test_bitemporal_morph_many_rejects_a_non_morph_to_temporal_entity(): void
    {
        $this->expectException(TemporalConfigurationException::class);
        $this->expectExceptionMessageMatches('/MorphTo/');

        (new Customer)->bitemporalMorphMany(ProductPrice::class);
    }

    public function test_bitemporal_morph_many_rejects_a_related_without_temporal_entity(): void
    {
        $this->expectException(TemporalConfigurationException::class);

        (new Customer)->bitemporalMorphMany(UserRoleAssignment::class);
    }

    public function test_bitemporal_belongs_to_many_binds_the_pivot_when_using_is_passed(): void
    {
        $user = $this->makeUser();
        $role = $this->makeRole();

        // When ->using() is wired up via the factory argument the relation is
        // resolved and writes succeed; otherwise assertResolved() would throw.
        $relation = $user->bitemporalBelongsToMany(Role::class, using: UserRoleAssignment::class);
        $relation->attachFor($role, '2026-06-01');

        $this->assertCount(1, $relation->currentKnowledge()->get());
    }

    // ---------------------------------------------------------------
    // BitemporalBelongsToMany: constructor foreign-key concatenation
    // ---------------------------------------------------------------

    public function test_pivot_relation_qualifies_the_foreign_key_before_binding(): void
    {
        $user = $this->makeUser();

        $relation = $user->bitemporalBelongsToMany(Role::class);

        $this->assertSame('roles.user_id', $relation->getQualifiedForeignKeyName());
    }

    // ---------------------------------------------------------------
    // BitemporalBelongsToMany: using() applies parent constraints
    // ---------------------------------------------------------------

    public function test_reads_are_scoped_to_the_parent(): void
    {
        $user = $this->makeUser('Ada');
        $other = $this->makeUser('Bea');
        $role = $this->makeRole();

        $user->roles()->attachFor($role, '2026-06-01');
        $other->roles()->attachFor($role, '2026-06-01');

        $this->assertCount(1, $user->roles()->currentKnowledge()->get());
    }

    // ---------------------------------------------------------------
    // BitemporalBelongsToMany: disabled mutating helpers
    // ---------------------------------------------------------------

    public function test_detach_helper_is_disabled(): void
    {
        $user = $this->makeUser();

        $this->expectException(TemporalConfigurationException::class);

        $user->roles()->detach($this->makeRole()->getKey());
    }

    public function test_sync_helper_is_disabled(): void
    {
        $user = $this->makeUser();

        $this->expectException(TemporalConfigurationException::class);

        $user->roles()->sync([$this->makeRole()->getKey()]);
    }

    // ---------------------------------------------------------------
    // BitemporalBelongsToMany: assertResolved guards
    // ---------------------------------------------------------------

    public function test_attach_for_requires_using_before_writes(): void
    {
        $user = $this->makeUser();
        $role = $this->makeRole();

        $relation = $user->bitemporalBelongsToMany(Role::class);

        $this->expectException(TemporalConfigurationException::class);
        $this->expectExceptionMessageMatches('/->using\(/');

        $relation->attachFor($role, '2026-06-01');
    }

    public function test_correct_assignment_requires_using_before_reads(): void
    {
        $user = $this->makeUser();
        $role = $this->makeRole();

        $relation = $user->bitemporalBelongsToMany(Role::class);

        $this->expectException(TemporalConfigurationException::class);
        $this->expectExceptionMessageMatches('/->using\(/');

        $relation->correctAssignment($role, '2026-06-01', '2026-08-01');
    }

    // ---------------------------------------------------------------
    // BitemporalBelongsToMany: pivot must be a Bitemporal model
    // ---------------------------------------------------------------

    public function test_correct_assignment_rejects_a_non_bitemporal_pivot(): void
    {
        $user = $this->makeUser();
        $role = $this->makeRole();

        // Role is not a Bitemporal model; resolving reads against it fails with
        // an exact, class-prefixed message (kills concat / throw mutants).
        $relation = $user->bitemporalBelongsToMany(Role::class, using: Role::class);

        try {
            $relation->correctAssignment($role, '2026-06-01');
            $this->fail('Expected a TemporalConfigurationException.');
        } catch (TemporalConfigurationException $exception) {
            $this->assertSame(Role::class.' pivot must use the Bitemporal trait', $exception->getMessage());
        }
    }

    public function test_detach_at_rejects_a_non_bitemporal_pivot(): void
    {
        $user = $this->makeUser();
        $role = $this->makeRole();

        $relation = $user->bitemporalBelongsToMany(Role::class, using: Role::class);

        try {
            $relation->detachAt($role, '2026-09-01');
            $this->fail('Expected a TemporalConfigurationException.');
        } catch (TemporalConfigurationException $exception) {
            $this->assertSame(Role::class.' pivot must use the Bitemporal trait', $exception->getMessage());
        }
    }

    // ---------------------------------------------------------------
    // BitemporalBelongsToMany: tuple label in cardinality messages
    // ---------------------------------------------------------------

    public function test_correct_assignment_message_includes_the_exact_tuple_label(): void
    {
        $user = $this->makeUser();
        $role = $this->makeRole();

        try {
            $user->roles()->correctAssignment($role, '2026-06-01', '2026-08-01');
            $this->fail('Expected a cardinality exception.');
        } catch (TemporalCardinalityException $exception) {
            $this->assertStringContainsString(
                "user_id={$user->getKey()}, role_id={$role->getKey()}",
                $exception->getMessage(),
            );
        }
    }

    public function test_detach_at_message_includes_the_exact_tuple_label(): void
    {
        $user = $this->makeUser();
        $role = $this->makeRole();

        try {
            $user->roles()->detachAt($role, '2026-09-01');
            $this->fail('Expected a cardinality exception.');
        } catch (TemporalCardinalityException $exception) {
            $this->assertStringContainsString(
                "user_id={$user->getKey()}, role_id={$role->getKey()}",
                $exception->getMessage(),
            );
        }
    }

    // ---------------------------------------------------------------
    // BitemporalBelongsToMany: pivot dimension tuple folding
    // ---------------------------------------------------------------

    public function test_pivot_dimension_tuple_scopes_writes_per_extra_dimension(): void
    {
        $user = $this->makeUser();
        $role = $this->makeRole();

        // Two assignments of the same role distinguished only by the pivot's
        // extra `scope` dimension must coexist as independent open timelines.
        // The dimension tuple is folded into the writer's scope; dropping it
        // would make the second write supersede the first.
        $user->bitemporalBelongsToMany(Role::class, using: ScopedRoleAssignment::class)
            ->forDimensions(['scope' => 'eu'])
            ->attachFor($role, '2026-06-01', attributes: ['scope' => 'eu']);
        $user->bitemporalBelongsToMany(Role::class, using: ScopedRoleAssignment::class)
            ->forDimensions(['scope' => 'us'])
            ->attachFor($role, '2026-06-01', attributes: ['scope' => 'us']);

        $this->assertCount(2, $user->roles()->currentKnowledge()->get());
    }

    // ---------------------------------------------------------------
    // BitemporalMany / BitemporalOne: sole(string $column)
    // ---------------------------------------------------------------

    public function test_bitemporal_many_sole_selects_the_named_string_column(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null]);

        $model = $product->prices()->sole('amount');

        $this->assertSame(['amount'], array_keys($model->getAttributes()));
    }

    public function test_bitemporal_one_sole_selects_the_named_string_column(): void
    {
        $product = $this->makeProduct();
        $this->insertPrice($product, ['amount' => 1000, 'valid_from' => '2026-01-01', 'valid_to' => null]);

        $model = $product->price()->sole('amount');

        $this->assertNotNull($model);
        $this->assertSame(['amount'], array_keys($model->getAttributes()));
    }
}
