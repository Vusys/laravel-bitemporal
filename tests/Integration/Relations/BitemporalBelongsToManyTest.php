<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Integration\Relations;

use Vusys\Bitemporal\Exceptions\TemporalCardinalityException;
use Vusys\Bitemporal\Exceptions\TemporalConfigurationException;
use Vusys\Bitemporal\Tests\Fixtures\Models\Role;
use Vusys\Bitemporal\Tests\Fixtures\Models\User;
use Vusys\Bitemporal\Tests\Fixtures\Models\UserRoleAssignment;
use Vusys\Bitemporal\Tests\Integration\IntegrationTestCase;

final class BitemporalBelongsToManyTest extends IntegrationTestCase
{
    private function makeUser(string $name = 'Ada'): User
    {
        return User::query()->create(['name' => $name]);
    }

    private function makeRole(string $name = 'admin'): Role
    {
        return Role::query()->create(['name' => $name]);
    }

    public function test_attach_for_creates_an_open_ended_assignment(): void
    {
        $user = $this->makeUser();
        $role = $this->makeRole();

        $user->roles()->attachFor(related: $role, validFrom: '2026-06-01');

        $assignments = $user->roles()->currentKnowledge()->get();

        $this->assertCount(1, $assignments);
        $this->assertSame($role->getKey(), $assignments->first()?->getAttribute('role_id'));
        $this->assertNull($assignments->first()?->getAttribute('valid_to'));
    }

    public function test_attach_for_accepts_a_closed_window(): void
    {
        $user = $this->makeUser();
        $role = $this->makeRole();

        $user->roles()->attachFor(related: $role, validFrom: '2026-06-01', validTo: '2026-08-01');

        $this->assertCount(1, $user->roles()->validAt('2026-07-01')->currentKnowledge()->get());
        $this->assertCount(0, $user->roles()->validAt('2026-09-01')->currentKnowledge()->get());
    }

    public function test_assignments_are_scoped_per_related_dimension(): void
    {
        $user = $this->makeUser();
        $admin = $this->makeRole('admin');
        $editor = $this->makeRole('editor');

        $user->roles()->attachFor(related: $admin, validFrom: '2026-06-01');
        $user->roles()->attachFor(related: $editor, validFrom: '2026-06-01');

        $this->assertCount(2, $user->roles()->currentKnowledge()->get());
    }

    public function test_detach_at_ends_the_assignment(): void
    {
        $user = $this->makeUser();
        $role = $this->makeRole();

        $user->roles()->attachFor(related: $role, validFrom: '2026-06-01');
        $user->roles()->detachAt(related: $role, validTo: '2026-09-01');

        $this->assertCount(1, $user->roles()->validAt('2026-07-01')->currentKnowledge()->get());
        $this->assertCount(0, $user->roles()->validAt('2026-10-01')->currentKnowledge()->get());
    }

    public function test_detach_at_throws_when_no_open_ended_assignment(): void
    {
        $user = $this->makeUser();
        $role = $this->makeRole();

        $this->expectException(TemporalCardinalityException::class);

        $user->roles()->detachAt(related: $role, validTo: '2026-09-01');
    }

    public function test_correct_assignment_throws_without_existing_rows(): void
    {
        $user = $this->makeUser();
        $role = $this->makeRole();

        $this->expectException(TemporalCardinalityException::class);

        $user->roles()->correctAssignment(related: $role, validFrom: '2026-06-01', validTo: '2026-08-01');
    }

    public function test_correct_assignment_corrects_an_attribute_over_a_window(): void
    {
        $user = $this->makeUser();
        $role = $this->makeRole();

        $user->roles()->attachFor(related: $role, validFrom: '2026-01-01', attributes: ['scope' => 'global']);
        $user->roles()->correctAssignment(related: $role, validFrom: '2026-06-01', validTo: '2026-08-01', attributes: ['scope' => 'eu']);

        $this->assertSame('global', $user->roles()->validAt('2026-03-01')->currentKnowledge()->sole()->getAttribute('scope'));
        $this->assertSame('eu', $user->roles()->validAt('2026-07-01')->currentKnowledge()->sole()->getAttribute('scope'));
        $this->assertSame('global', $user->roles()->validAt('2026-09-01')->currentKnowledge()->sole()->getAttribute('scope'));
    }

    public function test_standard_pivot_helpers_are_disabled(): void
    {
        $user = $this->makeUser();
        $role = $this->makeRole();

        $this->expectException(TemporalConfigurationException::class);

        $user->roles()->attach($role->getKey());
    }

    public function test_pivot_model_boots_without_a_temporal_entity(): void
    {
        // Instantiating the pivot triggers the boot guards; the Pivot exemption
        // means it must not throw despite having no temporalEntityRelation().
        $assignment = new UserRoleAssignment;

        $this->assertInstanceOf(UserRoleAssignment::class, $assignment);
    }

    public function test_get_without_using_throws_the_same_guard_as_writes(): void
    {
        $user = $this->makeUser();

        // No ->using(): the query is still the far-model stand-in, so a read
        // must fail fast with the resolution error rather than hit the wrong
        // table — the same guard writes already enforce (issue #45).
        $this->expectException(TemporalConfigurationException::class);
        $this->expectExceptionMessage('requires ->using(PivotClass::class)');

        $user->bitemporalBelongsToMany(Role::class)->get();
    }

    public function test_get_results_without_using_throws(): void
    {
        $user = $this->makeUser();

        $this->expectException(TemporalConfigurationException::class);

        $user->bitemporalBelongsToMany(Role::class)->getResults();
    }

    public function test_eager_constraints_without_using_throws(): void
    {
        $user = $this->makeUser();

        $this->expectException(TemporalConfigurationException::class);

        $user->bitemporalBelongsToMany(Role::class)->addEagerConstraints([$user]);
    }

    public function test_reads_succeed_once_using_is_bound(): void
    {
        $user = $this->makeUser();
        $role = $this->makeRole();
        $user->roles()->attachFor(related: $role, validFrom: '2026-06-01');

        // The resolved relation (built with ->using()) reads normally.
        $this->assertCount(1, $user->bitemporalBelongsToMany(Role::class)->using(UserRoleAssignment::class)->currentKnowledge()->get());
    }
}
