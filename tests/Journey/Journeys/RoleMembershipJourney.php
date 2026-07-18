<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Journey\Journeys;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Assert;
use Vusys\Bitemporal\Exceptions\TemporalCardinalityException;
use Vusys\Bitemporal\Exceptions\TemporalInvalidSpellException;
use Vusys\Bitemporal\Tests\Fixtures\Models\Role;
use Vusys\Bitemporal\Tests\Fixtures\Models\User;
use Vusys\Bitemporal\Tests\Fixtures\Models\UserRoleAssignment;
use Vusys\Bitemporal\Tests\Journey\Concerns\ExploresTimelines;
use Vusys\Runabout\Context;
use Vusys\Runabout\Invariant;
use Vusys\Runabout\Journey;
use Vusys\Runabout\Step;

/**
 * A journey over a bitemporal many-to-many: a user's membership of several roles
 * across time, built with `attachFor` / `detachAt` / `correctAssignment`.
 *
 * Each (user, role) pair is its own timeline — the relation scopes writes and
 * reads by role_id. The shuffler interleaves attaches, detaches and retroactive
 * corrections across roles in orders we never hand-write, tolerating only the
 * legitimate domain rejections (you cannot hold a role you don't yet hold, and
 * you cannot ask for a nonsensical window).
 *
 * The cardinality law that must survive every ordering: a role is never held
 * twice at the same instant — within one role, no two live assignments overlap
 * in valid time.
 */
final class RoleMembershipJourney extends Journey
{
    use ExploresTimelines;

    private const string START = '2026-01-01 00:00:00';

    /** @var list<string> */
    private const array ROLES = ['admin', 'editor', 'viewer'];

    /** @var list<string> */
    private const array SCOPES = ['global', 'eu', 'us'];

    public function steps(): array
    {
        return [
            Step::make('create user and roles')
                ->act(function (Context $ctx): void {
                    $ctx->travelTo(self::START);
                    $ctx->remember('user', User::query()->create(['name' => 'Ada']));

                    foreach (self::ROLES as $role) {
                        $ctx->remember("role {$role}", Role::query()->create(['name' => $role]));
                    }
                })
                ->assert(fn (Context $ctx) => Assert::assertTrue(
                    $ctx->instance('user', User::class)->exists,
                )),

            Step::make('advance the clock')
                ->after('create user and roles')
                ->repeatable()
                ->act(fn (Context $ctx) => $ctx->travel('+'.$ctx->randomInt(1, 30).' days')),

            // Grant a role from now-or-later, sometimes for a bounded window. If
            // the role is already held over an overlapping window the grant is
            // legitimately refused.
            Step::make('grant a role')
                ->after('create user and roles')
                ->repeatable()
                ->act(function (Context $ctx): void {
                    $user = $ctx->instance('user', User::class);
                    $role = $this->pickRole($ctx);
                    $validFrom = CarbonImmutable::now()->addDays($ctx->randomInt(0, 60));
                    $validTo = $ctx->randomInt(0, 1) === 1 ? $validFrom->addDays($ctx->randomInt(30, 200)) : null;

                    $this->attemptMembership(fn () => $user->roles()->attachFor(
                        related: $role,
                        validFrom: $validFrom,
                        validTo: $validTo,
                        attributes: ['scope' => $ctx->pick(self::SCOPES)],
                    ));
                }),

            // Revoke a role from a future instant. Refused if the role is not
            // currently held open-ended.
            Step::make('revoke a role')
                ->after('create user and roles')
                ->repeatable()
                ->act(function (Context $ctx): void {
                    $user = $ctx->instance('user', User::class);
                    $role = $this->pickRole($ctx);
                    $validTo = CarbonImmutable::now()->addDays($ctx->randomInt(1, 120));

                    $this->attemptMembership(fn () => $user->roles()->detachAt(related: $role, validTo: $validTo));
                }),

            // Retroactively correct an assignment's scope over a window. Refused
            // if the role was never held.
            Step::make('correct an assignment')
                ->after('create user and roles')
                ->repeatable()
                ->act(function (Context $ctx): void {
                    $user = $ctx->instance('user', User::class);
                    $role = $this->pickRole($ctx);
                    $from = CarbonImmutable::parse(self::START)->addDays($ctx->randomInt(0, 300));
                    $to = $from->addDays($ctx->randomInt(1, 120));

                    $this->attemptMembership(fn () => $user->roles()->correctAssignment(
                        related: $role,
                        validFrom: $from,
                        validTo: $to,
                        attributes: ['scope' => $ctx->pick(self::SCOPES)],
                    ));
                }),
        ];
    }

    public function invariants(): array
    {
        return [
            // No role is ever held twice at the same instant: within a single
            // role, the live current-knowledge assignments never overlap in
            // valid time. This is the many-to-many cardinality law.
            Invariant::make('no role is held twice at once', function (Context $ctx): void {
                if (! $ctx->has('user')) {
                    return;
                }

                $assignments = $ctx->instance('user', User::class)->roles()
                    ->currentKnowledge()
                    ->excludeRetractions()
                    ->get();

                /** @var array<string, list<array{from: string, to: ?string}>> $byRole */
                $byRole = [];
                foreach ($assignments as $assignment) {
                    $roleId = (string) ($this->scalar($assignment->getAttribute('role_id')) ?? '');
                    $byRole[$roleId][] = [
                        'from' => (string) $this->instant($assignment->getAttribute('valid_from')),
                        'to' => $this->instant($assignment->getAttribute('valid_to')),
                    ];
                }

                foreach ($byRole as $roleId => $spans) {
                    usort($spans, fn (array $a, array $b): int => $a['from'] <=> $b['from']);

                    $previousTo = null;
                    $havePrevious = false;
                    foreach ($spans as $span) {
                        if ($havePrevious) {
                            Assert::assertNotNull($previousTo, "Role {$roleId} holds an open-ended assignment before a later one.");
                            Assert::assertTrue($span['from'] >= $previousTo, "Role {$roleId} is held twice at the same instant.");
                        }
                        $previousTo = $span['to'];
                        $havePrevious = true;
                    }
                }
            }),

            Invariant::make('assignment history only ever grows', function (Context $ctx): void {
                if (! $ctx->has('user')) {
                    return;
                }

                $user = $ctx->instance('user', User::class);
                $count = UserRoleAssignment::query()->where('user_id', $user->id)->count();
                $high = $ctx->has('row high water') ? $ctx->integer('row high water') : 0;

                Assert::assertGreaterThanOrEqual($high, $count, 'Assignment rows dropped — history was mutated in place.');
                $ctx->remember('row high water', $count);
            }),
        ];
    }

    private function pickRole(Context $ctx): Role
    {
        return $ctx->instance('role '.$ctx->pick(self::ROLES), Role::class);
    }

    /**
     * Run a membership write, tolerating only the legitimate domain rejections:
     * a cardinality refusal (revoking/correcting a role that isn't held, or
     * granting one already held over the window) and a nonsensical drawn window.
     * Anything else — an overlap-guard trip, a write conflict — propagates.
     */
    private function attemptMembership(callable $write): void
    {
        try {
            $write();
        } catch (TemporalCardinalityException|TemporalInvalidSpellException) {
            // Legitimately refused by the domain; the shuffler draws these.
        }
    }
}
